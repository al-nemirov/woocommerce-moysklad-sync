<?php
/**
 * Логика синхронизации заказов WC → МойСклад.
 *
 * Сборка payload, отправка, обновление статуса, контрагенты, позиции.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_Order {

	/* ── Синхронизация заказа ──────────────────────────────── */

	/**
	 * Главная точка входа: выгрузить один заказ WC в МС.
	 *
	 * @param WC_Order $order
	 */
	public static function sync_order( $order ) {
		$login    = get_option( WC_MoySklad_Sync::OPT_LOGIN, '' );
		$password = get_option( WC_MoySklad_Sync::OPT_PASSWORD, '' );
		// Bearer token: логин пуст, пароль содержит токен. Basic: оба обязательны.
		if ( $login === '' && $password === '' ) {
			self::set_error( $order, 'Не заданы логин/пароль или Bearer-токен МойСклад.' );
			return;
		}
		if ( $login !== '' && $password === '' ) {
			self::set_error( $order, 'Не задан пароль МойСклад.' );
			return;
		}

		if ( WC_MoySklad_Sync::order_already_synced( $order ) ) {
			return;
		}

		$oid      = $order->get_id();
		$lock_key = 'wc_ms_sync_lock_' . $oid;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 60 );

		try {
			$order = wc_get_order( $oid );
			if ( ! $order || WC_MoySklad_Sync::order_already_synced( $order ) ) {
				return;
			}

			$api = WC_MoySklad_Sync::api();

			$body = self::assemble_customerorder_payload( $order, $api );
			if ( is_wp_error( $body ) ) {
				self::set_error( $order, $body->get_error_message() );
				return;
			}

			$result = $api->create_customer_order( $body );
			if ( is_wp_error( $result ) ) {
				self::set_error( $order, $result->get_error_message() );
				return;
			}

			$ms_id   = isset( $result['id'] ) ? $result['id'] : '';
			$ms_name = isset( $result['name'] ) ? $result['name'] : '';

			if ( $ms_id ) {
				$order->update_meta_data( WC_MoySklad_Sync::ORDER_META_ID, $ms_id );
				$order->update_meta_data( WC_MoySklad_Sync::ORDER_META_NAME, $ms_name );
				$order->delete_meta_data( WC_MoySklad_Sync::ORDER_META_ERROR );
				$order->delete_meta_data( 'yd_moysklad_error' );

				$note_label = get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' );
				$order->add_order_note( sprintf(
					'%s: Заказ передан (%s). <a href="https://online.moysklad.ru/app/#customerorder/edit?id=%s" target="_blank">Открыть</a>',
					$note_label,
					$ms_name,
					$ms_id
				) );
			}
			$order->save();
		} finally {
			delete_transient( $lock_key );
		}
	}

	/* ── Сборка payload ───────────────────────────────────── */

	/**
	 * Собрать тело заказа покупателя для API МС.
	 *
	 * @param WC_Order  $order
	 * @param WC_MS_API $api
	 * @return array|WP_Error
	 */
	public static function assemble_customerorder_payload( $order, $api ) {
		$org_id = get_option( WC_MoySklad_Sync::OPT_ORG_ID, '' );
		if ( $org_id ) {
			$org_meta = self::make_meta( 'entity/organization/' . $org_id, 'organization' );
		} else {
			$orgs = $api->get_organizations();
			if ( is_wp_error( $orgs ) ) {
				return new WP_Error( 'ms_org', 'Ошибка получения организации: ' . $orgs->get_error_message() );
			}
			if ( empty( $orgs[0]['meta'] ) ) {
				return new WP_Error( 'ms_org', 'В МойСклад не найдена ни одна организация.' );
			}
			$org_meta = $orgs[0]['meta'];
		}

		$agent_meta = self::get_or_create_counterparty( $order, $api );
		if ( is_wp_error( $agent_meta ) ) {
			return new WP_Error( 'ms_agent', 'Контрагент: ' . $agent_meta->get_error_message() );
		}
		if ( ! $agent_meta || ! is_array( $agent_meta ) ) {
			return new WP_Error( 'ms_agent', 'Не удалось получить или создать контрагента в МойСклад.' );
		}

		$reserve_on = get_option( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		if ( $reserve_on && trim( (string) get_option( WC_MoySklad_Sync::OPT_STORE_ID, '' ) ) === '' ) {
			return new WP_Error( 'ms_reserve', 'Резерв остатков: укажите склад в настройках плагина.' );
		}

		$positions = self::build_positions( $order, $api );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}

		if ( get_option( WC_MoySklad_Sync::OPT_ADD_SHIPPING, '0' ) === '1' ) {
			$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			if ( $shipping_total > 0 ) {
				$ship_svc = trim( (string) get_option( WC_MoySklad_Sync::OPT_SHIPPING_SERVICE_ID, '' ) );
				if ( $ship_svc === '' ) {
					return new WP_Error( 'ms_shipping_service', 'Включена строка доставки в заказ МС: укажите UUID услуги доставки в настройках плагина.' );
				}
				$shipping_markup = (float) get_option( WC_MoySklad_Sync::OPT_SHIPPING_MARKUP, '22' );
				$shipping_price  = $shipping_total * ( 1 + $shipping_markup / 100 );
				$positions[] = array(
					'quantity'   => 1,
					'price'      => self::price_to_cents( $shipping_price ),
					'assortment' => array( 'meta' => self::make_meta( 'entity/service/' . $ship_svc, 'service' ) ),
				);
			}
		}

		$body = array(
			'name'         => self::format_ms_order_name( $order ),
			'applicable'   => self::ms_customerorder_applicable(),
			'vatEnabled'   => false,
			'agent'        => array( 'meta' => $agent_meta ),
			'organization' => array( 'meta' => $org_meta ),
			'description'  => self::build_order_description( $order ),
			'positions'    => $positions,
		);

		$store_id = get_option( WC_MoySklad_Sync::OPT_STORE_ID, '' );
		if ( $store_id ) {
			$body['store'] = array( 'meta' => self::make_meta( 'entity/store/' . $store_id, 'store' ) );
		}

		$state_id = get_option( WC_MoySklad_Sync::OPT_MS_STATE_ID, '' );
		if ( $state_id ) {
			$body['state'] = array( 'meta' => self::make_meta( 'entity/customerorder/metadata/states/' . $state_id, 'state' ) );
		}

		$channel_id = get_option( WC_MoySklad_Sync::OPT_SALES_CHANNEL_ID, '' );
		if ( $channel_id ) {
			$body['salesChannel'] = array( 'meta' => self::make_meta( 'entity/saleschannel/' . $channel_id, 'saleschannel' ) );
		}

		return $body;
	}

	/* ── Обновление статуса в МС ──────────────────────────── */

	/**
	 * Обновить state в МойСклад для уже созданного customerorder.
	 *
	 * @param WC_Order $order
	 * @param string   $ms_id
	 * @param string   $ms_state_id
	 */
	public static function update_ms_order_state( $order, $ms_id, $ms_state_id ) {
		$login    = get_option( WC_MoySklad_Sync::OPT_LOGIN, '' );
		$password = get_option( WC_MoySklad_Sync::OPT_PASSWORD, '' );
		if ( $login === '' && $password === '' ) {
			return;
		}
		$api = WC_MoySklad_Sync::api();

		$current = $api->get_customer_order( $ms_id );
		if ( ! is_wp_error( $current ) && isset( $current['state']['meta']['href'] ) ) {
			$current_href = (string) $current['state']['meta']['href'];
			$current_id   = basename( $current_href );
			if ( $current_id !== '' && $current_id === (string) $ms_state_id ) {
				return;
			}
		}

		$body = array(
			'state' => array(
				'meta' => self::make_meta(
					'entity/customerorder/metadata/states/' . $ms_state_id,
					'state'
				),
			),
		);

		$result = $api->update_customer_order( $ms_id, $body );
		if ( is_wp_error( $result ) ) {
			$note_label = get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' );
			$order->add_order_note( sprintf(
				'%s: Не удалось обновить state в МойСклад: %s',
				$note_label,
				$result->get_error_message()
			) );
			return;
		}

		$order->add_order_note( sprintf(
			'%s: Обновлён state в МойСклад (customerorder %s) → %s',
			get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' ),
			$ms_id,
			$ms_state_id
		) );
	}

	/* ── Контрагенты ──────────────────────────────────────── */

	/**
	 * @param WC_Order  $order
	 * @param WC_MS_API $api
	 * @return array|WP_Error meta-массив контрагента.
	 */
	public static function get_or_create_counterparty( $order, $api ) {
		$mode = get_option( WC_MoySklad_Sync::OPT_AGENT_MODE, 'per_customer' );
		if ( $mode === 'fixed_uuid' ) {
			$uuid = trim( (string) get_option( WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID, '' ) );
			if ( $uuid === '' ) {
				return new WP_Error( 'ms_agent_fixed', 'Режим «один контрагент»: укажите UUID контрагента в настройках плагина.' );
			}
			return self::make_meta( 'entity/counterparty/' . $uuid, 'counterparty' );
		}

		$email = $order->get_billing_email();
		if ( $email ) {
			$found = $api->find_counterparty_by_email( $email );
			if ( $found && ! empty( $found['meta'] ) ) {
				return $found['meta'];
			}
		}

		$phone = $order->get_billing_phone();
		if ( $phone ) {
			$found = $api->find_counterparty_by_phone( $phone );
			if ( $found && ! empty( $found['meta'] ) ) {
				return $found['meta'];
			}
		}

		$name = self::format_new_counterparty_name( $order );

		$result = $api->create_counterparty( $name, $email, $phone );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['meta'] ) ) {
			return new WP_Error( 'ms_agent', 'Не удалось создать контрагента.' );
		}
		return $result['meta'];
	}

	/**
	 * Имя для создаваемого контрагента.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function format_new_counterparty_name( $order ) {
		$style   = get_option( WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE, 'first_last' );
		$allowed = array( 'first_last', 'last_first', 'company_or_fio' );
		if ( ! in_array( $style, $allowed, true ) ) {
			$style = 'first_last';
		}

		$first   = trim( (string) $order->get_billing_first_name() );
		$last    = trim( (string) $order->get_billing_last_name() );
		$company = trim( (string) $order->get_billing_company() );

		if ( $style === 'company_or_fio' && $company !== '' ) {
			return $company;
		}

		if ( $style === 'last_first' ) {
			$name = trim( $last . ' ' . $first );
		} else {
			$name = trim( $first . ' ' . $last );
		}

		if ( $name === '' && $company !== '' ) {
			return $company;
		}
		if ( $name === '' ) {
			$email = $order->get_billing_email();
			if ( $email && strpos( $email, '@' ) !== false ) {
				$part = strstr( $email, '@', true );
				if ( is_string( $part ) && $part !== '' ) {
					return sprintf( 'Покупатель %s', $part );
				}
			}
			return 'Покупатель';
		}

		return $name;
	}

	/* ── Позиции заказа ───────────────────────────────────── */

	/**
	 * @param WC_Order  $order
	 * @param WC_MS_API $api
	 * @return array|WP_Error
	 */
	public static function build_positions( $order, $api ) {
		$positions          = array();
		$default_product_id = get_option( WC_MoySklad_Sync::OPT_DEFAULT_PROD, '' );
		$reserve_on_create  = get_option( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE, '0' ) === '1';

		foreach ( $order->get_items() as $item ) {
			if ( ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();
			if ( $quantity < 1 ) {
				continue;
			}

			if ( ! $product ) {
				error_log( sprintf(
					'[WC MoyS] Order #%s: товар #%d удалён из WC — позиция пропущена.',
					$order->get_order_number(),
					$item->get_product_id()
				) );
				continue;
			}

			// Берём цену из заказа — уже с учётом всех скидок/наценок (в т.ч. wc-dynamic-price-modifier)
			$price_rub = ( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() ) / $quantity;
			$price     = self::price_to_cents( $price_rub );

			$meta = null;
			$sku  = $product->get_sku();
			if ( $sku !== '' ) {
				$found = $api->find_product_by_article( $sku );
				if ( $found && ! empty( $found['meta'] ) ) {
					$meta = $found['meta'];
				}
			}
			if ( ! $meta && $default_product_id !== '' ) {
				$meta = self::make_meta( 'entity/product/' . $default_product_id, 'product' );
			}
			if ( ! $meta ) {
				error_log( sprintf(
					'[WC MoyS] Order #%s: товар «%s» (SKU: %s) не найден в МойСклад — позиция пропущена.',
					$order->get_order_number(),
					$product->get_name(),
					$sku ?: 'нет'
				) );
				continue;
			}
			$pos = array(
				'quantity'   => $quantity,
				'price'      => $price,
				'assortment' => array( 'meta' => $meta ),
			);
			if ( $reserve_on_create ) {
				$pos['reserve'] = $quantity;
			}
			$positions[] = $pos;
		}

		if ( empty( $positions ) ) {
			return new WP_Error( 'ms_positions', 'Не удалось сопоставить позиции с товарами МойСклад (проверьте артикулы SKU).' );
		}
		return $positions;
	}

	/* ── Шаблоны ──────────────────────────────────────────── */

	/**
	 * Поле «Название» заказа покупателя в МС.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function format_ms_order_name( $order ) {
		$tpl = get_option( WC_MoySklad_Sync::OPT_ORDER_NAME_TPL, '' );
		$tpl = is_string( $tpl ) ? trim( $tpl ) : '';
		if ( $tpl === '' ) {
			$tpl = 'WC-{order_number}';
		}
		$name = WC_MS_Tokens::replace( $order, $tpl );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		if ( $name === '' ) {
			$name = 'WC-' . $order->get_order_number();
		}
		return self::truncate_utf8( $name, 200 );
	}

	/**
	 * Комментарий к заказу в МС.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function build_order_description( $order ) {
		$tpl = get_option( WC_MoySklad_Sync::OPT_DESCRIPTION_TPL, '' );
		$tpl = is_string( $tpl ) ? trim( $tpl ) : '';
		if ( $tpl !== '' ) {
			return WC_MS_Tokens::replace( $order, $tpl );
		}
		return sprintf(
			'%s #%s | %s | %s %s | %s',
			WC_MS_Tokens::site_host(),
			$order->get_order_number(),
			$order->get_billing_email(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_phone()
		);
	}

	/* ── Хелперы ──────────────────────────────────────────── */

	public static function set_error( $order, $msg ) {
		$order->update_meta_data( WC_MoySklad_Sync::ORDER_META_ERROR, $msg );
		$order->save();

		$note_label = get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' );
		$order->add_order_note( sprintf( '%s: Ошибка — %s', $note_label, $msg ) );
	}

	public static function make_meta( $endpoint, $type ) {
		return array(
			'href'      => WC_MS_API::BASE . $endpoint,
			'type'      => $type,
			'mediaType' => 'application/json',
		);
	}

	public static function price_to_cents( $price ) {
		return (int) round( (float) $price * 100, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * Проводить ли заказ покупателя в МС.
	 *
	 * @return bool
	 */
	public static function ms_customerorder_applicable() {
		$reserve = get_option( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		$manual  = get_option( WC_MoySklad_Sync::OPT_ORDER_APPLICABLE, '0' ) === '1';
		return $reserve || $manual;
	}

	/**
	 * Обрезка названия для МС по символам UTF-8.
	 *
	 * @param string $name
	 * @param int    $max_chars
	 * @return string
	 */
	public static function truncate_utf8( $name, $max_chars ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $name, 'UTF-8' ) <= $max_chars ) {
				return $name;
			}
			return mb_substr( $name, 0, max( 0, $max_chars - 3 ), 'UTF-8' ) . '...';
		}
		if ( strlen( $name ) <= $max_chars ) {
			return $name;
		}
		return substr( $name, 0, max( 0, $max_chars - 3 ) ) . '...';
	}

	/**
	 * Подпись контрагента для предпросмотра (без создания в МС).
	 *
	 * @param WC_Order  $order
	 * @param WC_MS_API $api
	 * @return string
	 */
	public static function preview_agent_label( $order, $api ) {
		if ( get_option( WC_MoySklad_Sync::OPT_AGENT_MODE, 'per_customer' ) === 'fixed_uuid' ) {
			$uuid = trim( (string) get_option( WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID, '' ) );
			if ( $uuid === '' ) {
				return 'Не задан UUID контрагента в настройках';
			}
			$cp = $api->get( 'entity/counterparty/' . $uuid );
			if ( ! is_wp_error( $cp ) && ! empty( $cp['name'] ) ) {
				return (string) $cp['name'];
			}
			return 'Контрагент UUID ' . $uuid . ( is_wp_error( $cp ) ? ' (не удалось загрузить из МС)' : '' );
		}
		$email = $order->get_billing_email();
		if ( $email ) {
			$found = $api->find_counterparty_by_email( $email );
			if ( $found && ! empty( $found['name'] ) ) {
				return (string) $found['name'] . ' (уже в МС, поиск по email)';
			}
		}
		$phone = $order->get_billing_phone();
		if ( $phone ) {
			$found = $api->find_counterparty_by_phone( $phone );
			if ( $found && ! empty( $found['name'] ) ) {
				return (string) $found['name'] . ' (уже в МС, поиск по телефону)';
			}
		}
		return 'Будет создан: ' . self::format_new_counterparty_name( $order );
	}

	/**
	 * Сумма для предпросмотра с символом валюты.
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return string
	 */
	public static function format_money_preview( $amount, $currency ) {
		$dec  = wc_get_price_decimal_separator();
		$thou = wc_get_price_thousand_separator();
		$abs  = abs( (float) $amount );
		$n    = number_format( $abs, wc_get_price_decimals(), $dec, $thou );
		if ( $amount < 0 ) {
			$n = '-' . $n;
		}
		return $n . ' ' . get_woocommerce_currency_symbol( $currency );
	}
}
