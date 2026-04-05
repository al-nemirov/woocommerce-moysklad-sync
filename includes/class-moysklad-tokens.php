<?php
/**
 * Реестр переменных (плейсхолдеров) для шаблонов МойСклад.
 *
 * Используется в шаблонах названия заказа и комментария к заказу.
 * Предоставляет полный справочник с описаниями для UI предпросмотра.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_Tokens {

	/**
	 * Реестр всех доступных переменных с описаниями.
	 *
	 * @return array Массив [ '{token}' => 'Описание' ].
	 */
	public static function registry() {
		return array(
			// Заказ
			'{site_host}'            => 'Домен сайта',
			'{order_number}'         => 'Номер заказа WC',
			'{order_id}'             => 'ID заказа WC (число)',
			'{order_date}'           => 'Дата заказа (d.m.Y)',
			'{order_time}'           => 'Время заказа (H:i)',
			'{order_total}'          => 'Общая сумма + валюта',
			'{order_subtotal}'       => 'Сумма товаров (без доставки)',
			'{order_discount}'       => 'Скидка',
			'{order_shipping_total}' => 'Стоимость доставки',
			'{order_tax_total}'      => 'Сумма налогов',
			'{order_currency}'       => 'Валюта заказа (код)',
			'{items_count}'          => 'Количество позиций',

			// Покупатель
			'{first_name}'           => 'Имя (billing)',
			'{last_name}'            => 'Фамилия (billing)',
			'{company}'              => 'Компания (billing)',
			'{email}'                => 'Email покупателя',
			'{email_local}'          => 'Часть email до @',
			'{phone}'                => 'Телефон (billing)',
			'{customer_id}'          => 'ID пользователя WP',

			// Billing-адрес
			'{billing_address}'      => 'Полный адрес оплаты',
			'{billing_city}'         => 'Город (billing)',
			'{billing_state}'        => 'Регион (billing)',
			'{billing_postcode}'     => 'Индекс (billing)',
			'{billing_country}'      => 'Страна (billing, код)',

			// Shipping-адрес
			'{shipping_address}'     => 'Полный адрес доставки',
			'{shipping_address_1}'   => 'Улица (shipping)',
			'{shipping_city}'        => 'Город (shipping)',
			'{shipping_state}'       => 'Регион (shipping)',
			'{shipping_postcode}'    => 'Индекс (shipping)',
			'{shipping_country}'     => 'Страна (shipping, код)',

			// Доставка и оплата
			'{shipping_method}'      => 'Способ доставки',
			'{payment_method}'       => 'Код метода оплаты',
			'{payment_method_title}' => 'Название метода оплаты',

			// Прочее
			'{customer_note}'        => 'Примечание покупателя',
			'{line_items}'           => 'Список позиций (Товар ×2, …)',
		);
	}

	/**
	 * Подставить переменные из заказа WC в шаблон.
	 *
	 * @param WC_Order $order
	 * @param string   $tpl
	 * @return string
	 */
	public static function replace( $order, $tpl ) {
		$vars = self::resolve_all( $order );
		return str_replace( array_keys( $vars ), array_values( $vars ), $tpl );
	}

	/**
	 * Все переменные с подставленными значениями для конкретного заказа.
	 *
	 * @param WC_Order $order
	 * @return array [ '{token}' => 'значение' ]
	 */
	public static function resolve_all( $order ) {
		$email = trim( (string) $order->get_billing_email() );
		$local = '';
		if ( $email !== '' && strpos( $email, '@' ) !== false ) {
			$local = strstr( $email, '@', true );
			if ( ! is_string( $local ) ) {
				$local = '';
			}
		}

		$bill_html = (string) $order->get_formatted_billing_address();
		$ship_html = (string) $order->get_formatted_shipping_address();

		$date_created = $order->get_date_created();

		return array(
			// Заказ
			'{site_host}'            => self::site_host(),
			'{order_number}'         => (string) $order->get_order_number(),
			'{order_id}'             => (string) $order->get_id(),
			'{order_date}'           => $date_created ? $date_created->date_i18n( 'd.m.Y' ) : '',
			'{order_time}'           => $date_created ? $date_created->date_i18n( 'H:i' ) : '',
			'{order_total}'          => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) . ' ' . $order->get_currency(),
			'{order_subtotal}'       => wc_format_decimal( $order->get_subtotal(), wc_get_price_decimals() ),
			'{order_discount}'       => wc_format_decimal( $order->get_discount_total(), wc_get_price_decimals() ),
			'{order_shipping_total}' => wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
			'{order_tax_total}'      => wc_format_decimal( $order->get_total_tax(), wc_get_price_decimals() ),
			'{order_currency}'       => $order->get_currency(),
			'{items_count}'          => (string) $order->get_item_count(),

			// Покупатель
			'{first_name}'           => trim( (string) $order->get_billing_first_name() ),
			'{last_name}'            => trim( (string) $order->get_billing_last_name() ),
			'{company}'              => trim( (string) $order->get_billing_company() ),
			'{email}'                => $email,
			'{email_local}'          => $local,
			'{phone}'                => trim( (string) $order->get_billing_phone() ),
			'{customer_id}'          => (string) $order->get_customer_id(),

			// Billing
			'{billing_address}'      => self::html_address_to_plain( $bill_html ),
			'{billing_city}'         => trim( (string) $order->get_billing_city() ),
			'{billing_state}'        => trim( (string) $order->get_billing_state() ),
			'{billing_postcode}'     => trim( (string) $order->get_billing_postcode() ),
			'{billing_country}'      => trim( (string) $order->get_billing_country() ),

			// Shipping
			'{shipping_address}'     => self::html_address_to_plain( $ship_html ),
			'{shipping_address_1}'   => trim( (string) $order->get_shipping_address_1() ),
			'{shipping_city}'        => trim( (string) $order->get_shipping_city() ),
			'{shipping_state}'       => trim( (string) $order->get_shipping_state() ),
			'{shipping_postcode}'    => trim( (string) $order->get_shipping_postcode() ),
			'{shipping_country}'     => trim( (string) $order->get_shipping_country() ),

			// Доставка и оплата
			'{shipping_method}'      => self::shipping_methods_label( $order ),
			'{payment_method}'       => trim( (string) $order->get_payment_method() ),
			'{payment_method_title}' => trim( (string) $order->get_payment_method_title() ),

			// Прочее
			'{customer_note}'        => trim( (string) $order->get_customer_note() ),
			'{line_items}'           => self::line_items_summary( $order ),
		);
	}

	/**
	 * Справочник: массив [ [ 'token' => '{...}', 'desc' => '...', 'value' => '...' ], ... ]
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function reference_table( $order ) {
		$registry = self::registry();
		$values   = self::resolve_all( $order );
		$rows     = array();
		foreach ( $registry as $token => $desc ) {
			$rows[] = array(
				'token' => $token,
				'desc'  => $desc,
				'value' => isset( $values[ $token ] ) ? $values[ $token ] : '',
			);
		}
		return $rows;
	}

	/* ── Хелперы ───────────────────────────────────────────── */

	/**
	 * Домен магазина.
	 *
	 * @return string
	 */
	public static function site_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return ( is_string( $host ) && $host !== '' ) ? $host : 'site';
	}

	/**
	 * HTML-адрес WC → текст с переносами строк.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function html_address_to_plain( $html ) {
		if ( ! is_string( $html ) || $html === '' ) {
			return '';
		}
		$t = str_ireplace( array( '<br/>', '<br />', '<br>' ), "\n", $html );
		return trim( wp_strip_all_tags( $t ) );
	}

	/**
	 * Подписи способов доставки в заказе.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function shipping_methods_label( $order ) {
		$labels = array();
		foreach ( $order->get_shipping_methods() as $ship_item ) {
			if ( ! $ship_item instanceof WC_Order_Item_Shipping ) {
				continue;
			}
			$t = trim( (string) $ship_item->get_name() );
			if ( $t === '' ) {
				$t = trim( (string) $ship_item->get_method_title() );
			}
			if ( $t !== '' ) {
				$labels[] = $t;
			}
		}
		return implode( ', ', array_unique( $labels ) );
	}

	/**
	 * Краткий список позиций: «Товар ×2, …».
	 *
	 * @param WC_Order $order
	 * @param int      $max_len
	 * @return string
	 */
	public static function line_items_summary( $order, $max_len = 1500 ) {
		$parts = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) $item->get_name() ) );
			if ( $name === '' ) {
				continue;
			}
			$parts[] = $name . ' ×' . (int) $item->get_quantity();
		}
		$s = implode( ', ', $parts );
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $s, 'UTF-8' ) > $max_len ) {
				return mb_substr( $s, 0, $max_len - 3, 'UTF-8' ) . '...';
			}
		} elseif ( strlen( $s ) > $max_len ) {
			return substr( $s, 0, $max_len - 3 ) . '...';
		}
		return $s;
	}
}
