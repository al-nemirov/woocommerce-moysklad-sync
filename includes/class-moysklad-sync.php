<?php
/**
 * Синхронизация заказов WooCommerce с МойСклад.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MoySklad_Sync {

	/* ── Опции ─────────────────────────────────────────────── */

	const OPT_ENABLED        = 'wc_ms_enabled';
	const OPT_LOGIN          = 'wc_ms_login';
	const OPT_PASSWORD       = 'wc_ms_password';
	const OPT_ORG_ID         = 'wc_ms_organization_id';
	const OPT_STORE_ID       = 'wc_ms_store_id';
	const OPT_DEFAULT_PROD   = 'wc_ms_default_product_id';
	const OPT_SEND_ON_STATUS = 'wc_ms_send_on_status';
	const OPT_MS_STATE_ID    = 'wc_ms_initial_state_id';
	const OPT_STATUS_MAP     = 'wc_ms_status_map';
	const OPT_WEBHOOK_ACTIVE = 'wc_ms_webhook_active';
	const OPT_WEBHOOK_ID     = 'wc_ms_webhook_id';
	const OPT_WEBHOOK_SECRET = 'wc_ms_webhook_secret';
	const OPT_DEBUG          = 'wc_ms_debug';
	const OPT_ADD_SHIPPING   = 'wc_ms_add_shipping';
	const OPT_RESERVE_ON_CREATE = 'wc_ms_reserve_on_create';
	const OPT_NOTE_LABEL     = 'wc_ms_note_label';

	const ORDER_META_ID    = 'wc_ms_order_id';
	const ORDER_META_ERROR = 'wc_ms_error';
	const ORDER_META_NAME  = 'wc_ms_order_name';

	/* ── Инициализация ─────────────────────────────────────── */

	public static function init() {
		require_once __DIR__ . '/class-moysklad-api.php';

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_wc_ms_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wc_ms_load_entities', array( __CLASS__, 'ajax_load_entities' ) );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 20, 2 );

		// HPOS + CPT сохранение
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'on_save_order' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_save_order' ), 10, 2 );

		// Webhook endpoint
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_endpoint' ) );

		// Колонка в списке заказов
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column_hpos' ), 10, 2 );

		// Миграция со старого плагина
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_from_yd' ) );

		// Ссылка "Настройки" в списке плагинов
		add_filter( 'plugin_action_links_' . plugin_basename( WC_MS_SYNC_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	/* ── Ссылка "Настройки" ────────────────────────────────── */

	public static function plugin_action_links( $links ) {
		$url  = admin_url( 'admin.php?page=wc-moysklad-sync' );
		$link = '<a href="' . esc_url( $url ) . '">Настройки</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/* ── API-инстанс ───────────────────────────────────────── */

	private static function api() {
		$login    = get_option( self::OPT_LOGIN, '' );
		$password = get_option( self::OPT_PASSWORD, '' );
		$debug    = get_option( self::OPT_DEBUG, '0' ) === '1';
		return new WC_MS_API( $login, $password, $debug );
	}

	/* ── Миграция ──────────────────────────────────────────── */

	public static function maybe_migrate_from_yd() {
		if ( get_option( 'wc_ms_migrated_from_yd', false ) ) {
			return;
		}
		$old_keys = array(
			'yd_moysklad_enabled'            => self::OPT_ENABLED,
			'yd_moysklad_login'              => self::OPT_LOGIN,
			'yd_moysklad_password'           => self::OPT_PASSWORD,
			'yd_moysklad_send_on_status'     => self::OPT_SEND_ON_STATUS,
			'yd_moysklad_organization_id'    => self::OPT_ORG_ID,
			'yd_moysklad_default_product_id' => self::OPT_DEFAULT_PROD,
		);
		$migrated = false;
		foreach ( $old_keys as $old => $new ) {
			$val = get_option( $old, '' );
			if ( $val !== '' && get_option( $new, '' ) === '' ) {
				update_option( $new, $val );
				$migrated = true;
			}
		}
		if ( $migrated ) {
			update_option( 'wc_ms_migrated_from_yd', '1' );
		}
	}

	/* ── Колонка "МС" в списке заказов ─────────────────────── */

	public static function add_order_column( $columns ) {
		if ( get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			return $columns;
		}
		$new = array();
		foreach ( $columns as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'order_status' ) {
				$new['wc_ms_status'] = 'МС';
			}
		}
		return $new;
	}

	public static function render_order_column( $column, $post_id ) {
		if ( $column !== 'wc_ms_status' ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}
		self::render_column_content( $order );
	}

	public static function render_order_column_hpos( $column, $order ) {
		if ( $column !== 'wc_ms_status' ) {
			return;
		}
		self::render_column_content( $order );
	}

	private static function render_column_content( $order ) {
		$ms_id    = $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );
		$ms_error = $order->get_meta( self::ORDER_META_ERROR ) ?: $order->get_meta( 'yd_moysklad_error' );
		if ( $ms_id ) {
			echo '<span style="color:green;" title="Передан в МС">&#10003;</span>';
		} elseif ( $ms_error ) {
			echo '<span style="color:red;" title="' . esc_attr( $ms_error ) . '">&#10007;</span>';
		} else {
			echo '<span style="color:#999;">—</span>';
		}
	}

	/* ── Мета-бокс в заказе ────────────────────────────────── */

	public static function add_order_meta_box( $post_type, $post ) {
		if ( ( $post_type !== 'shop_order' && $post_type !== 'wc-order' ) || get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			return;
		}
		add_meta_box( 'wc_moysklad_sync', 'МойСклад', array( __CLASS__, 'render_order_meta_box' ), $post_type, 'side' );
	}

	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$ms_id    = $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );
		$ms_error = $order->get_meta( self::ORDER_META_ERROR ) ?: $order->get_meta( 'yd_moysklad_error' );
		$ms_name  = $order->get_meta( self::ORDER_META_NAME );

		if ( $ms_id ) {
			$href = 'https://online.moysklad.ru/app/#customerorder/edit?id=' . $ms_id;
			echo '<p style="color:green;font-weight:bold;">&#10003; Передан в МойСклад</p>';
			if ( $ms_name ) {
				echo '<p>Заказ МС: <strong>' . esc_html( $ms_name ) . '</strong></p>';
			}
			echo '<p><a href="' . esc_url( $href ) . '" target="_blank" rel="noopener" class="button">Открыть в МойСклад</a></p>';
			echo '<hr>';
			echo '<p><button type="submit" name="wc_ms_resync" class="button" style="color:#999;font-size:11px;" onclick="return confirm(\'Пересоздать заказ в МойСклад?\');">Пересоздать заказ</button></p>';
		} elseif ( $ms_error ) {
			echo '<p style="color:red;">&#10007; <strong>Ошибка:</strong></p>';
			echo '<p style="color:red;font-size:12px;">' . esc_html( $ms_error ) . '</p>';
			echo '<p><button type="submit" name="wc_ms_sync" class="button button-primary">Отправить в МойСклад</button></p>';
		} else {
			echo '<p>Заказ не отправлен в МойСклад.</p>';
			echo '<p><button type="submit" name="wc_ms_sync" class="button button-primary">Отправить в МойСклад</button></p>';
		}
	}

	/* ── Сохранение мета-бокса ─────────────────────────────── */

	public static function on_save_order( $order_id, $order = null ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		if ( ! isset( $_POST['wc_ms_sync'] ) && ! isset( $_POST['wc_ms_resync'] ) ) {
			return;
		}
		$ord = wc_get_order( $order_id );
		if ( ! $ord ) {
			return;
		}
		// Nonce
		$nonce_ok = false;
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce_ok = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $order_id )
			         || wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-order_' . $order_id );
		}
		if ( isset( $_POST['woocommerce_meta_nonce'] ) ) {
			$nonce_ok = $nonce_ok || wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		}
		if ( ! $nonce_ok ) {
			return;
		}
		if ( isset( $_POST['wc_ms_resync'] ) ) {
			$ord->delete_meta_data( self::ORDER_META_ID );
			$ord->delete_meta_data( self::ORDER_META_ERROR );
			$ord->delete_meta_data( self::ORDER_META_NAME );
			$ord->delete_meta_data( 'yd_moysklad_id' );
			$ord->delete_meta_data( 'yd_moysklad_error' );
			$ord->save();
		}
		self::sync_order( $ord );
	}

	/* ── Автоотправка по статусу ──────────────────────────── */

	public static function on_order_status_changed( $order_id, $old_status, $new_status ) {
		if ( get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$ms_id = $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );

		// Если заказ уже есть в МойСклад — обновляем его state при каждом изменении статуса WC.
		if ( $ms_id ) {
			$status_map = get_option( self::OPT_STATUS_MAP, array() );
			if ( empty( $status_map ) || ! is_array( $status_map ) ) {
				return;
			}

			// Маппинг хранится как: ms_state_id => wc_status.
			// Инвертируем: wc_status => ms_state_id.
			$wc_to_ms = array();
			foreach ( $status_map as $ms_state_id => $wc_status ) {
				if ( is_string( $ms_state_id ) && $ms_state_id !== '' && is_string( $wc_status ) && $wc_status !== '' ) {
					$wc_to_ms[ $wc_status ] = $ms_state_id;
				}
			}

			if ( isset( $wc_to_ms[ $new_status ] ) && $wc_to_ms[ $new_status ] !== '' ) {
				self::update_ms_order_state( $order, (string) $ms_id, (string) $wc_to_ms[ $new_status ] );
			}
			return;
		}

		// Если заказа в МойСклад ещё нет — отправляем только при совпадении с настройкой.
		$send_on       = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		$send_on_clean = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		if ( $new_status !== $send_on && $new_status !== $send_on_clean ) {
			return;
		}

		self::sync_order( $order );
	}

	/* ── Синхронизация заказа ──────────────────────────────── */

	public static function sync_order( $order ) {
		$login    = get_option( self::OPT_LOGIN, '' );
		$password = get_option( self::OPT_PASSWORD, '' );
		if ( empty( $login ) || empty( $password ) ) {
			self::set_error( $order, 'Не заданы логин или пароль МойСклад.' );
			return;
		}

		$api = self::api();

		// 1. Организация
		$org_id = get_option( self::OPT_ORG_ID, '' );
		if ( $org_id ) {
			$org_meta = self::make_meta( 'entity/organization/' . $org_id, 'organization' );
		} else {
			$orgs = $api->get_organizations();
			if ( is_wp_error( $orgs ) ) {
				self::set_error( $order, 'Ошибка получения организации: ' . $orgs->get_error_message() );
				return;
			}
			if ( empty( $orgs[0]['meta'] ) ) {
				self::set_error( $order, 'В МойСклад не найдена ни одна организация.' );
				return;
			}
			$org_meta = $orgs[0]['meta'];
		}

		// 2. Контрагент
		$agent_meta = self::get_or_create_counterparty( $order, $api );
		if ( is_wp_error( $agent_meta ) ) {
			self::set_error( $order, 'Контрагент: ' . $agent_meta->get_error_message() );
			return;
		}

		// 3. Позиции
		$positions = self::build_positions( $order, $api );
		if ( is_wp_error( $positions ) ) {
			self::set_error( $order, $positions->get_error_message() );
			return;
		}

		// 4. Доставка как позиция-услуга
		if ( get_option( self::OPT_ADD_SHIPPING, '0' ) === '1' ) {
			$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			if ( $shipping_total > 0 ) {
				$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
				$positions[] = array(
					'quantity'   => 1,
					'price'      => self::price_to_cents( $shipping_total ),
					'assortment' => array( 'meta' => self::make_meta( 'entity/service/' . get_option( 'wc_ms_shipping_service_id', '' ), 'service' ) ),
					'reserve'    => $reserve_on_create ? 1 : null,
				);
				if ( $reserve_on_create === false ) {
					unset( $positions[ count( $positions ) - 1 ]['reserve'] );
				}
			}
		}

		// 5. Собрать заказ
		$order_number = $order->get_order_number();
		$body = array(
			'name'         => 'WC-' . $order_number,
			'applicable'   => false,
			'vatEnabled'   => false,
			'agent'        => array( 'meta' => $agent_meta ),
			'organization' => array( 'meta' => $org_meta ),
			'description'  => sprintf(
				'WooCommerce #%s | %s | %s %s | %s',
				$order_number,
				$order->get_billing_email(),
				$order->get_billing_first_name(),
				$order->get_billing_last_name(),
				$order->get_billing_phone()
			),
			'positions'    => $positions,
		);

		// Склад
		$store_id = get_option( self::OPT_STORE_ID, '' );
		if ( $store_id ) {
			$body['store'] = array( 'meta' => self::make_meta( 'entity/store/' . $store_id, 'store' ) );
		}

		// Начальный статус МС
		$state_id = get_option( self::OPT_MS_STATE_ID, '' );
		if ( $state_id ) {
			$body['state'] = array( 'meta' => self::make_meta( 'entity/customerorder/metadata/states/' . $state_id, 'state' ) );
		}

		// 6. Отправить
		$result = $api->create_customer_order( $body );
		if ( is_wp_error( $result ) ) {
			self::set_error( $order, $result->get_error_message() );
			return;
		}

		$ms_id   = isset( $result['id'] ) ? $result['id'] : '';
		$ms_name = isset( $result['name'] ) ? $result['name'] : '';

		if ( $ms_id ) {
			$order->update_meta_data( self::ORDER_META_ID, $ms_id );
			$order->update_meta_data( self::ORDER_META_NAME, $ms_name );
			$order->delete_meta_data( self::ORDER_META_ERROR );
			$order->delete_meta_data( 'yd_moysklad_error' );

			$note_label = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
			$order->add_order_note( sprintf(
				'%s: Заказ передан (%s). <a href="https://online.moysklad.ru/app/#customerorder/edit?id=%s" target="_blank">Открыть</a>',
				$note_label,
				$ms_name,
				$ms_id
			) );
		}
		$order->save();
	}

	/* ── Тесты (для отладки шагов синхронизации) ───────────────── */

	private static function run_test_action( $action ) {
		switch ( $action ) {
			case 'connection':
				return (string) self::test_step_connection();
			case 'entities':
				return (string) self::test_step_entities();
			case 'create_draft_roundtrip':
				return (string) self::test_step_create_draft_roundtrip();
			default:
				return new WP_Error( 'wc_ms_test_unknown', 'Неизвестный тест.' );
		}
	}

	private static function test_step_connection() {
		$api = self::api();
		if ( ! $api ) {
			return new WP_Error( 'wc_ms_test_api', 'API не инициализировано.' );
		}
		$res = $api->test_connection();
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return 'Подключение к МойСклад: OK';
	}

	private static function test_step_entities() {
		$api = self::api();
		if ( ! $api ) {
			return new WP_Error( 'wc_ms_test_api', 'API не инициализировано.' );
		}

		$orgs   = $api->get_organizations();
		$stores = $api->get_stores();
		$states = $api->get_order_states();

		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}
		if ( is_wp_error( $stores ) ) {
			return $stores;
		}
		if ( is_wp_error( $states ) ) {
			return $states;
		}

		return sprintf(
			'Данные загружены: orgs=%d, stores=%d, states=%d',
			count( $orgs ),
			count( $stores ),
			count( $states )
		);
	}

	private static function test_step_create_draft_roundtrip() {
		$api = self::api();
		if ( ! $api ) {
			return new WP_Error( 'wc_ms_test_api', 'API не инициализировано.' );
		}

		$default_prod = get_option( self::OPT_DEFAULT_PROD, '' );
		if ( empty( $default_prod ) ) {
			return new WP_Error( 'wc_ms_test_no_product', 'Не задан Товар-заглушка (UUID) в настройках.' );
		}

		// Организация
		$org_id = get_option( self::OPT_ORG_ID, '' );
		if ( $org_id ) {
			$org_meta = self::make_meta( 'entity/organization/' . $org_id, 'organization' );
		} else {
			$orgs = $api->get_organizations();
			if ( is_wp_error( $orgs ) || empty( $orgs[0]['meta'] ) ) {
				return new WP_Error( 'wc_ms_test_org', 'Не удалось получить организацию.' );
			}
			$org_meta = $orgs[0]['meta'];
		}

		// Статусы: возьмём initial + следующий
		$states = $api->get_order_states();
		if ( is_wp_error( $states ) || empty( $states ) ) {
			return new WP_Error( 'wc_ms_test_states', 'Не удалось получить статусы customerorder.' );
		}

		$initial_state_id = get_option( self::OPT_MS_STATE_ID, '' );
		if ( empty( $initial_state_id ) ) {
			$initial_state_id = (string) $states[0]['id'];
		}
		$next_state_id = '';
		foreach ( $states as $st ) {
			if ( isset( $st['id'] ) && (string) $st['id'] !== '' && (string) $st['id'] !== (string) $initial_state_id ) {
				$next_state_id = (string) $st['id'];
				break;
			}
		}
		if ( empty( $next_state_id ) ) {
			return new WP_Error( 'wc_ms_test_states_next', 'Не найден следующий state для roundtrip.' );
		}

		// Контрагент: создаём уникального тестового
		$ts    = time();
		$email = 'wc-ms-test-' . $ts . '@example.com';
		$phone = '';
		$name  = 'WC-MS TEST ' . $ts;

		$agent = $api->create_counterparty( $name, $email, $phone );
		if ( is_wp_error( $agent ) || empty( $agent['meta'] ) ) {
			return new WP_Error( 'wc_ms_test_agent', 'Не удалось создать тестового контрагента.' );
		}
		$agent_meta = $agent['meta'];

		// Позиции: 1 товар по заглушке
		$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		$price_kop         = self::price_to_cents( 100.0 ); // 100.00 RUB -> 10000 коп.

		$positions = array(
			array(
				'quantity'   => 1,
				'price'      => $price_kop,
				'assortment' => array( 'meta' => self::make_meta( 'entity/product/' . $default_prod, 'product' ) ),
			),
		);
		if ( $reserve_on_create ) {
			$positions[0]['reserve'] = 1;
		}

		$body = array(
			'name'         => 'WC-MS-TEST-' . $ts,
			'applicable'   => false,
			'agent'        => array( 'meta' => $agent_meta ),
			'organization' => array( 'meta' => $org_meta ),
			'description'  => 'Тест: создание черновика customerorder и смена state.',
			'positions'    => $positions,
		);

		$store_id = get_option( self::OPT_STORE_ID, '' );
		if ( $store_id ) {
			$body['store'] = array( 'meta' => self::make_meta( 'entity/store/' . $store_id, 'store' ) );
		}

		$body['state'] = array(
			'meta' => self::make_meta(
				'entity/customerorder/metadata/states/' . $initial_state_id,
				'state'
			),
		);

		$created = $api->create_customer_order( $body );
		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			return is_wp_error( $created ) ? $created : new WP_Error( 'wc_ms_test_create', 'Не удалось создать customerorder.' );
		}
		$ms_id = (string) $created['id'];

		$updated = $api->update_customer_order( $ms_id, array(
			'state' => array(
				'meta' => self::make_meta( 'entity/customerorder/metadata/states/' . $next_state_id, 'state' ),
			),
		) );
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'wc_ms_test_update', 'Создал, но не смог обновить state: ' . $updated->get_error_message() );
		}

		return 'Тест OK: создан customerorder=' . $ms_id . ' и updated state.';
	}

	/* ── Хелперы ───────────────────────────────────────────── */

	private static function set_error( $order, $msg ) {
		$order->update_meta_data( self::ORDER_META_ERROR, $msg );
		$order->save();

		$note_label = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
		$order->add_order_note( sprintf( '%s: Ошибка — %s', $note_label, $msg ) );
	}

	private static function make_meta( $endpoint, $type ) {
		return array(
			'href'      => WC_MS_API::BASE . $endpoint,
			'type'      => $type,
			'mediaType' => 'application/json',
		);
	}

	private static function price_to_cents( $price ) {
		// МойСклад positions.price: Integer (Long), цена в копейках.
		return (int) round( (float) $price * 100 );
	}

	/**
	 * Обновить state в МойСклад для уже созданного customerorder.
	 *
	 * @param WC_Order $order
	 * @param string   $ms_id
	 * @param string   $ms_state_id
	 */
	private static function update_ms_order_state( $order, $ms_id, $ms_state_id ) {
		$api = self::api();
		if ( ! $api ) {
			return;
		}

		// Защита от лишних PUT (и потенциального “WC→MS→webhook→WC” цикла).
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
			self::set_error( $order, 'Не удалось обновить state в МойСклад: ' . $result->get_error_message() );
			return;
		}

		$order->add_order_note( sprintf(
			'%s: Обновлён state в МойСклад (customerorder %s) → %s',
			get_option( self::OPT_NOTE_LABEL, 'МойСклад' ),
			$ms_id,
			$ms_state_id
		) );
	}

	private static function get_or_create_counterparty( $order, $api ) {
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

		$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		if ( $name === '' ) {
			$name = $order->get_billing_company() ?: 'Покупатель';
		}

		$result = $api->create_counterparty( $name, $email, $phone );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['meta'] ) ) {
			return new WP_Error( 'ms_agent', 'Не удалось создать контрагента.' );
		}
		return $result['meta'];
	}

	private static function build_positions( $order, $api ) {
		$positions          = array();
		$default_product_id = get_option( self::OPT_DEFAULT_PROD, '' );
		$reserve_on_create  = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';

		foreach ( $order->get_items() as $item ) {
			if ( ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();
			if ( $quantity < 1 ) {
				continue;
			}
			$price = self::price_to_cents( ( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() ) / $quantity );

			$meta = null;
			if ( $product ) {
				$sku = $product->get_sku();
				if ( $sku !== '' ) {
					$found = $api->find_product_by_article( $sku );
					if ( $found && ! empty( $found['meta'] ) ) {
						$meta = $found['meta'];
					}
				}
			}
			if ( ! $meta && $default_product_id !== '' ) {
				$meta = self::make_meta( 'entity/product/' . $default_product_id, 'product' );
			}
			if ( ! $meta ) {
				continue;
			}
			$positions[] = array(
				'quantity'   => $quantity,
				'price'      => $price,
				'assortment' => array( 'meta' => $meta ),
			);
			if ( $reserve_on_create ) {
				$positions[ count( $positions ) - 1 ]['reserve'] = $quantity;
			}
		}

		if ( empty( $positions ) ) {
			return new WP_Error( 'ms_positions', 'Не удалось сопоставить позиции с товарами МойСклад (проверьте артикулы SKU).' );
		}
		return $positions;
	}

	/* ── Webhook endpoint ──────────────────────────────────── */

	public static function register_webhook_endpoint() {
		register_rest_route( 'moysklad/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle_webhook( $request ) {
		// Проверка секрета (query param ?secret=...)
		$secret = get_option( self::OPT_WEBHOOK_SECRET, '' );
		if ( $secret !== '' ) {
			$provided = $request->get_param( 'secret' );
			if ( $provided !== $secret ) {
				return new WP_REST_Response( array( 'error' => 'Invalid secret' ), 403 );
			}
		}

		$body = $request->get_json_params();
		if ( empty( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$status_map = get_option( self::OPT_STATUS_MAP, array() );
		if ( empty( $status_map ) || ! is_array( $status_map ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$api = self::api();

		foreach ( $body['events'] as $event ) {
			if ( ! isset( $event['meta']['type'] ) || $event['meta']['type'] !== 'customerorder' ) {
				continue;
			}
			if ( ! isset( $event['action'] ) || $event['action'] !== 'UPDATE' ) {
				continue;
			}
			$href = isset( $event['meta']['href'] ) ? $event['meta']['href'] : '';
			if ( ! $href ) {
				continue;
			}

			// Извлекаем ID заказа МС из href
			$ms_id = basename( $href );

			// Найти заказ WC по meta
			$orders = wc_get_orders( array(
				'meta_key'   => self::ORDER_META_ID,
				'meta_value' => $ms_id,
				'limit'      => 1,
			) );
			if ( empty( $orders ) ) {
				// Попробуем старый ключ
				$orders = wc_get_orders( array(
					'meta_key'   => 'yd_moysklad_id',
					'meta_value' => $ms_id,
					'limit'      => 1,
				) );
			}
			if ( empty( $orders ) ) {
				continue;
			}

			$wc_order = $orders[0];

			// Получить заказ МС чтобы узнать текущий статус
			$ms_order = $api->get_customer_order( $ms_id );
			if ( is_wp_error( $ms_order ) || ! isset( $ms_order['state']['meta']['href'] ) ) {
				continue;
			}

			$state_href = $ms_order['state']['meta']['href'];
			$state_id   = basename( $state_href );

			// Маппинг статуса МС → WC
			if ( isset( $status_map[ $state_id ] ) && $status_map[ $state_id ] !== '' ) {
				$wc_status = $status_map[ $state_id ];
				$wc_status_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
				if ( $wc_order->get_status() !== $wc_status_clean ) {
					$state_name = isset( $ms_order['state']['name'] ) ? $ms_order['state']['name'] : $state_id;
					$note_label = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
					$wc_order->update_status(
						$wc_status_clean,
						sprintf( '%s: Статус МС "%s" → ', $note_label, $state_name )
					);
				}
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ── AJAX: Проверка подключения ────────────────────────── */

	public static function ajax_test_connection() {
		check_ajax_referer( 'wc_ms_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Нет прав.' );
		}

		$login    = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : get_option( self::OPT_LOGIN, '' );
		$password = isset( $_POST['password'] ) && $_POST['password'] !== '' ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : get_option( self::OPT_PASSWORD, '' );

		if ( empty( $login ) || empty( $password ) ) {
			wp_send_json_error( 'Введите логин и пароль.' );
		}

		$api    = new WC_MS_API( $login, $password );
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'Подключение успешно!' );
	}

	/* ── AJAX: Загрузка сущностей ──────────────────────────── */

	public static function ajax_load_entities() {
		check_ajax_referer( 'wc_ms_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Нет прав.' );
		}

		$api  = self::api();
		$data = array();

		// Организации
		$orgs = $api->get_organizations();
		if ( ! is_wp_error( $orgs ) ) {
			$data['organizations'] = array();
			foreach ( $orgs as $org ) {
				$data['organizations'][] = array(
					'id'   => $org['id'],
					'name' => $org['name'],
				);
			}
		}

		// Склады
		$stores = $api->get_stores();
		if ( ! is_wp_error( $stores ) ) {
			$data['stores'] = array();
			foreach ( $stores as $store ) {
				$data['stores'][] = array(
					'id'   => $store['id'],
					'name' => $store['name'],
				);
			}
		}

		// Статусы заказа
		$states = $api->get_order_states();
		if ( ! is_wp_error( $states ) ) {
			$data['states'] = array();
			foreach ( $states as $state ) {
				$data['states'][] = array(
					'id'    => $state['id'],
					'name'  => $state['name'],
					'color' => isset( $state['color'] ) ? $state['color'] : 0,
				);
			}
		}

		wp_send_json_success( $data );
	}

	/* ── Скрипты ───────────────────────────────────────────── */

	public static function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'wc-moysklad-sync' ) === false ) {
			return;
		}
		wp_enqueue_script( 'wc-ms-admin', plugin_dir_url( WC_MS_SYNC_FILE ) . 'assets/admin.js', array( 'jquery' ), WC_MS_SYNC_VERSION, true );
		wp_localize_script( 'wc-ms-admin', 'wc_ms', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wc_ms_admin' ),
		) );
	}

	/* ── Страница настроек ────────────────────────────────── */

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			'МойСклад — Настройки',
			'МойСклад',
			'manage_woocommerce',
			'wc-moysklad-sync',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return;
		}

		$test_notice_html = '';

		// Сохранение
		if ( isset( $_POST['wc_ms_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce'] ) ), 'wc_ms_save' ) ) {
			self::save_settings();
			echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
		}

		// Тесты
		if ( isset( $_POST['wc_ms_test_action'] ) && isset( $_POST['wc_ms_nonce'] ) ) {
			$nonce_ok = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce'] ) ), 'wc_ms_save' );
			if ( $nonce_ok ) {
				$action = sanitize_text_field( wp_unslash( $_POST['wc_ms_test_action'] ) );
				$res    = self::run_test_action( $action );
				if ( is_wp_error( $res ) ) {
					$test_notice_html = '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
				} else {
					$test_notice_html = '<div class="notice notice-success"><p>' . esc_html( (string) $res ) . '</p></div>';
				}
			}
		}

		// Управление вебхуком
		if ( isset( $_POST['wc_ms_webhook_action'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce_wh'] ?? '' ) ), 'wc_ms_webhook' ) ) {
			self::handle_webhook_action( sanitize_text_field( wp_unslash( $_POST['wc_ms_webhook_action'] ) ) );
		}

		$enabled      = get_option( self::OPT_ENABLED, '0' );
		$login        = get_option( self::OPT_LOGIN, '' );
		$password     = get_option( self::OPT_PASSWORD, '' );
		$org_id       = get_option( self::OPT_ORG_ID, '' );
		$store_id     = get_option( self::OPT_STORE_ID, '' );
		$send_on      = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		$state_id     = get_option( self::OPT_MS_STATE_ID, '' );
		$default_prod = get_option( self::OPT_DEFAULT_PROD, '' );
		$debug        = get_option( self::OPT_DEBUG, '0' );
		$add_shipping = get_option( self::OPT_ADD_SHIPPING, '0' );
		$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' );
		$note_label   = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
		$status_map   = get_option( self::OPT_STATUS_MAP, array() );
		$wh_active    = get_option( self::OPT_WEBHOOK_ACTIVE, '0' );
		$wh_id        = get_option( self::OPT_WEBHOOK_ID, '' );

		$wc_statuses = wc_get_order_statuses();
		$webhook_url = rest_url( 'moysklad/v1/webhook' );
		?>
		<div class="wrap">
			<h1>МойСклад — Синхронизация заказов</h1>
			<p>Заказы WooCommerce автоматически передаются в МойСклад как заказы покупателя (черновики).</p>
			<?php echo $test_notice_html ? $test_notice_html : ''; ?>

			<form method="post" style="max-width:800px;">
				<?php wp_nonce_field( 'wc_ms_save', 'wc_ms_nonce' ); ?>

				<!-- Подключение -->
				<h2>Подключение</h2>
				<table class="form-table">
					<tr>
						<th>Включить</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> /> Синхронизировать заказы</label></td>
					</tr>
					<tr>
						<th><label for="ms_login">Логин (email)</label></th>
						<td><input type="email" id="ms_login" name="<?php echo esc_attr( self::OPT_LOGIN ); ?>" value="<?php echo esc_attr( $login ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ms_password">Пароль</label></th>
						<td>
							<input type="password" id="ms_password" name="<?php echo esc_attr( self::OPT_PASSWORD ); ?>" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••' : ''; ?>" autocomplete="new-password" />
							<br><small>Оставьте пустым, чтобы не менять.</small>
						</td>
					</tr>
					<tr>
						<th>Проверка</th>
						<td>
							<button type="button" id="wc_ms_test_btn" class="button">Проверить подключение</button>
							<span id="wc_ms_test_result" style="margin-left:10px;"></span>
						</td>
					</tr>
				</table>

				<!-- Организация и склад -->
				<h2>Организация и склад</h2>
				<p><button type="button" id="wc_ms_load_btn" class="button">Загрузить из МойСклад</button> <span id="wc_ms_load_result" style="margin-left:10px;"></span></p>
				<table class="form-table">
					<tr>
						<th><label for="ms_org">Организация</label></th>
						<td>
							<select id="ms_org" name="<?php echo esc_attr( self::OPT_ORG_ID ); ?>" class="regular-text">
								<option value="">— Первая по умолчанию —</option>
								<?php if ( $org_id ) : ?>
									<option value="<?php echo esc_attr( $org_id ); ?>" selected><?php echo esc_html( $org_id ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description">Нажмите «Загрузить из МойСклад» для списка.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ms_store">Склад</label></th>
						<td>
							<select id="ms_store" name="<?php echo esc_attr( self::OPT_STORE_ID ); ?>" class="regular-text">
								<option value="">— Не указывать —</option>
								<?php if ( $store_id ) : ?>
									<option value="<?php echo esc_attr( $store_id ); ?>" selected><?php echo esc_html( $store_id ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				</table>

				<!-- Автоотправка -->
				<h2>Автоотправка заказов</h2>
				<table class="form-table">
					<tr>
						<th><label for="ms_send_on">Отправлять при статусе WC</label></th>
						<td>
							<select id="ms_send_on" name="<?php echo esc_attr( self::OPT_SEND_ON_STATUS ); ?>">
								<?php foreach ( $wc_statuses as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $send_on, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Заказ автоматически отправится в МС при переходе в этот статус.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ms_state">Начальный статус в МС</label></th>
						<td>
							<select id="ms_state" name="<?php echo esc_attr( self::OPT_MS_STATE_ID ); ?>" class="regular-text">
								<option value="">— По умолчанию (первый) —</option>
								<?php if ( $state_id ) : ?>
									<option value="<?php echo esc_attr( $state_id ); ?>" selected><?php echo esc_html( $state_id ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description">Нажмите «Загрузить из МойСклад» для списка.</p>
						</td>
					</tr>
				</table>

				<!-- Товары -->
				<h2>Сопоставление товаров</h2>
				<table class="form-table">
					<tr>
						<th><label for="ms_product">Товар-заглушка (UUID)</label></th>
						<td>
							<input type="text" id="ms_product" name="<?php echo esc_attr( self::OPT_DEFAULT_PROD ); ?>" value="<?php echo esc_attr( $default_prod ); ?>" class="regular-text" />
							<p class="description">UUID товара в МС для позиций без совпадения по артикулу (SKU). Оставьте пустым — позиции без совпадения будут пропущены.</p>
						</td>
					</tr>
				</table>

				<!-- Маппинг статусов МС → WC -->
				<h2>Маппинг статусов МС → WC</h2>
				<p class="description">При изменении статуса заказа в МойСклад — автоматически менять статус в WooCommerce. Требует активного вебхука (см. ниже).</p>
				<div id="wc_ms_status_map_container">
					<table class="widefat" style="max-width:600px;">
						<thead><tr><th>Статус МС</th><th>→ Статус WC</th></tr></thead>
						<tbody id="wc_ms_status_map_body">
							<tr><td colspan="2"><em>Нажмите «Загрузить из МойСклад» для настройки.</em></td></tr>
						</tbody>
					</table>
					<?php if ( ! empty( $status_map ) && is_array( $status_map ) ) : ?>
						<?php foreach ( $status_map as $ms_state => $wc_status ) : ?>
							<input type="hidden" name="wc_ms_map[<?php echo esc_attr( $ms_state ); ?>]" value="<?php echo esc_attr( $wc_status ); ?>" />
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Дополнительно -->
				<h2>Дополнительно</h2>
				<table class="form-table">
					<tr>
						<th><label for="ms_note">Метка в примечаниях</label></th>
						<td><input type="text" id="ms_note" name="<?php echo esc_attr( self::OPT_NOTE_LABEL ); ?>" value="<?php echo esc_attr( $note_label ); ?>" class="regular-text" placeholder="МойСклад" /></td>
					</tr>
					<tr>
						<th>Резерв при создании</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_RESERVE_ON_CREATE ); ?>" value="1" <?php checked( $reserve_on_create, '1' ); ?> /> Резервировать количество в customerorder.positions.reserve (если поддерживается вашей схемой склад/заказы)</label></td>
					</tr>
					<tr>
						<th>Отладка</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_DEBUG ); ?>" value="1" <?php checked( $debug, '1' ); ?> /> Записывать API-запросы в debug.log</label></td>
					</tr>
				</table>

				<hr>
				<h2>Тесты (по шагам)</h2>
				<table class="form-table">
					<tr>
						<td><button type="submit" class="button" name="wc_ms_test_action" value="connection">Тест: подключение</button></td>
					</tr>
					<tr>
						<td><button type="submit" class="button" name="wc_ms_test_action" value="entities">Тест: загрузка организаций/складов/статусов</button></td>
					</tr>
					<tr>
						<td><button type="submit" class="button button-primary" name="wc_ms_test_action" value="create_draft_roundtrip">Тест: создать черновик + смена state</button></td>
					</tr>
				</table>

				<p class="submit"><input type="submit" class="button-primary" value="Сохранить настройки" /></p>
			</form>

			<!-- Вебхук -->
			<hr>
			<h2>Вебхук (МС → WC)</h2>
			<p>URL вебхука: <code><?php echo esc_html( $webhook_url ); ?></code></p>
			<?php if ( $wh_active === '1' && $wh_id ) : ?>
				<p style="color:green;">&#10003; Вебхук активен (ID: <?php echo esc_html( $wh_id ); ?>)</p>
				<form method="post">
					<?php wp_nonce_field( 'wc_ms_webhook', 'wc_ms_nonce_wh' ); ?>
					<input type="hidden" name="wc_ms_webhook_action" value="delete" />
					<button type="submit" class="button" onclick="return confirm('Удалить вебхук?');">Удалить вебхук</button>
				</form>
			<?php else : ?>
				<p style="color:#999;">Вебхук не активен.</p>
				<form method="post">
					<?php wp_nonce_field( 'wc_ms_webhook', 'wc_ms_nonce_wh' ); ?>
					<input type="hidden" name="wc_ms_webhook_action" value="create" />
					<button type="submit" class="button button-primary">Создать вебхук в МойСклад</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ── Сохранение настроек ───────────────────────────────── */

	private static function save_settings() {
		$fields = array(
			self::OPT_ENABLED      => 'checkbox',
			self::OPT_LOGIN        => 'text',
			self::OPT_ORG_ID       => 'text',
			self::OPT_STORE_ID     => 'text',
			self::OPT_SEND_ON_STATUS => 'text',
			self::OPT_MS_STATE_ID  => 'text',
			self::OPT_DEFAULT_PROD => 'text',
			self::OPT_DEBUG        => 'checkbox',
			self::OPT_ADD_SHIPPING => 'checkbox',
			self::OPT_RESERVE_ON_CREATE => 'checkbox',
			self::OPT_NOTE_LABEL   => 'text',
		);

		foreach ( $fields as $key => $type ) {
			if ( $type === 'checkbox' ) {
				update_option( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
			} else {
				update_option( $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' );
			}
		}

		// Пароль — только если не пустой
		if ( isset( $_POST[ self::OPT_PASSWORD ] ) && $_POST[ self::OPT_PASSWORD ] !== '' ) {
			update_option( self::OPT_PASSWORD, sanitize_text_field( wp_unslash( $_POST[ self::OPT_PASSWORD ] ) ) );
		}

		// Маппинг статусов
		if ( isset( $_POST['wc_ms_map'] ) && is_array( $_POST['wc_ms_map'] ) ) {
			$map = array();
			foreach ( $_POST['wc_ms_map'] as $ms_state => $wc_status ) {
				$ms_state  = sanitize_text_field( $ms_state );
				$wc_status = sanitize_text_field( $wc_status );
				if ( $ms_state && $wc_status ) {
					$map[ $ms_state ] = $wc_status;
				}
			}
			update_option( self::OPT_STATUS_MAP, $map );
		}
	}

	/* ── Управление вебхуком ───────────────────────────────── */

	private static function handle_webhook_action( $action ) {
		$api = self::api();

		if ( $action === 'create' ) {
			// Генерируем секрет для защиты endpoint
			$secret = wp_generate_password( 32, false );
			update_option( self::OPT_WEBHOOK_SECRET, $secret );

			$url    = rest_url( 'moysklad/v1/webhook' ) . '?secret=' . $secret;
			$result = $api->create_webhook( $url, 'customerorder', 'UPDATE' );
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>Ошибка: ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$wh_id = isset( $result['id'] ) ? $result['id'] : '';
				update_option( self::OPT_WEBHOOK_ACTIVE, '1' );
				update_option( self::OPT_WEBHOOK_ID, $wh_id );
				echo '<div class="notice notice-success"><p>Вебхук создан!</p></div>';
			}
		} elseif ( $action === 'delete' ) {
			$wh_id = get_option( self::OPT_WEBHOOK_ID, '' );
			if ( $wh_id ) {
				$api->delete_webhook( $wh_id );
			}
			update_option( self::OPT_WEBHOOK_ACTIVE, '0' );
			update_option( self::OPT_WEBHOOK_ID, '' );
			echo '<div class="notice notice-success"><p>Вебхук удалён.</p></div>';
		}
	}
}
