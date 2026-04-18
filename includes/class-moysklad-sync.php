<?php
/**
 * Ядро плагина: константы, инициализация модулей, хуки WC, cron, тесты.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MoySklad_Sync {

	/* ── Константы опций ──────────────────────────────────── */

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
	const OPT_SHIPPING_SERVICE_ID = 'wc_ms_shipping_service_id';
	const OPT_RESERVE_ON_CREATE = 'wc_ms_reserve_on_create';
	const OPT_ORDER_APPLICABLE = 'wc_ms_order_applicable';
	const OPT_NOTE_LABEL     = 'wc_ms_note_label';
	const OPT_ORDER_NAME_TPL = 'wc_ms_order_name_tpl';
	const OPT_AGENT_NAME_STYLE = 'wc_ms_agent_name_style';
	const OPT_DESCRIPTION_TPL = 'wc_ms_description_tpl';
	const OPT_AGENT_MODE     = 'wc_ms_agent_mode';
	const OPT_FIXED_COUNTERPARTY_ID = 'wc_ms_fixed_counterparty_id';
	const OPT_SYNC_TRIGGER   = 'wc_ms_sync_trigger';
	const OPT_SYNC_CRON_SCHED = 'wc_ms_sync_cron_sched';
	const OPT_SHIPPING_MARKUP = 'wc_ms_shipping_markup';
	const OPT_SALES_CHANNEL_ID = 'wc_ms_sales_channel_id';

	const CRON_HOOK = 'wc_ms_cron_sync_orders';

	const ORDER_META_ID    = 'wc_ms_order_id';
	const ORDER_META_ERROR = 'wc_ms_error';
	const ORDER_META_NAME  = 'wc_ms_order_name';

	/* ── Инициализация ────────────────────────────────────── */

	public static function init() {
		require_once __DIR__ . '/class-moysklad-api.php';
		require_once __DIR__ . '/class-moysklad-tokens.php';
		require_once __DIR__ . '/class-moysklad-order.php';
		require_once __DIR__ . '/class-moysklad-webhook.php';
		require_once __DIR__ . '/class-moysklad-admin.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/class-moysklad-cli.php';
		}

		add_filter( 'http_request_args', array( 'WC_MS_API', 'enforce_moysklad_headers' ), 99999, 2 );

		// WC хуки
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 30, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_sync' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_intervals' ) );

		// Webhook endpoint
		add_action( 'rest_api_init', array( 'WC_MS_Webhook', 'register_endpoint' ) );

		// Миграция
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_from_yd' ) );

		// Админка
		WC_MS_Admin::init();
	}

	public static function on_activate() {
		self::reschedule_cron_sync();
	}

	/* ── API-инстанс ──────────────────────────────────────── */

	public static function api() {
		$login    = get_option( self::OPT_LOGIN, '' );
		$password = get_option( self::OPT_PASSWORD, '' );
		$debug    = get_option( self::OPT_DEBUG, '0' ) === '1';
		return new WC_MS_API( $login, $password, $debug );
	}

	/* ── Проверка: заказ уже в МС ─────────────────────────── */

	public static function order_already_synced( $order ) {
		return (bool) ( $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' ) );
	}

	/* ── Миграция ─────────────────────────────────────────── */

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

		if ( $ms_id ) {
			$status_map = get_option( self::OPT_STATUS_MAP, array() );
			if ( empty( $status_map ) || ! is_array( $status_map ) ) {
				return;
			}
			$wc_to_ms = array();
			foreach ( $status_map as $ms_state_id => $wc_status ) {
				if ( ! is_string( $ms_state_id ) || $ms_state_id === '' || ! is_string( $wc_status ) || $wc_status === '' ) {
					continue;
				}
				$wc_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
				$wc_to_ms[ $wc_clean ]  = $ms_state_id;
				$wc_to_ms[ $wc_status ] = $ms_state_id;
			}
			if ( isset( $wc_to_ms[ $new_status ] ) && $wc_to_ms[ $new_status ] !== '' ) {
				WC_MS_Order::update_ms_order_state( $order, (string) $ms_id, (string) $wc_to_ms[ $new_status ] );
			}
			return;
		}

		if ( ! self::sync_trigger_allows_status() ) {
			return;
		}

		$send_on       = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		$send_on_clean = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		if ( $new_status !== $send_on && $new_status !== $send_on_clean ) {
			return;
		}

		WC_MS_Order::sync_order( $order );
	}

	public static function on_checkout_order_processed( $order_id ) {
		if ( get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			return;
		}
		if ( ! self::sync_trigger_allows_checkout() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( self::order_already_synced( $order ) ) {
			return;
		}
		if ( ! self::order_status_matches_send_on( $order->get_status() ) ) {
			return;
		}
		WC_MS_Order::sync_order( $order );
	}

	/* ── Cron ─────────────────────────────────────────────── */

	public static function run_cron_sync() {
		if ( get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			return;
		}
		if ( ! self::sync_trigger_allows_cron() ) {
			return;
		}
		$send_clean = self::send_on_status_clean();
		$orders     = wc_get_orders( array(
			'limit'    => 80,
			'status'   => array( $send_clean ),
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		) );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( self::order_already_synced( $order ) ) {
				continue;
			}
			if ( ! self::order_status_matches_send_on( $order->get_status() ) ) {
				continue;
			}
			WC_MS_Order::sync_order( $order );
		}
	}

	public static function register_cron_intervals( $schedules ) {
		$schedules['wc_ms_15min'] = array(
			'interval' => 900,
			'display'  => 'WooCommerce МойСклад: каждые 15 минут',
		);
		return $schedules;
	}

	public static function reschedule_cron_sync() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( ! self::sync_trigger_allows_cron() ) {
			return;
		}
		$sched = get_option( self::OPT_SYNC_CRON_SCHED, 'hourly' );
		$allow = array( 'wc_ms_15min', 'hourly', 'twicedaily', 'daily' );
		if ( ! in_array( $sched, $allow, true ) ) {
			$sched = 'hourly';
		}
		wp_schedule_event( time() + 120, $sched, self::CRON_HOOK );
	}

	/* ── Хелперы триггеров ────────────────────────────────── */

	private static function order_status_matches_send_on( $status ) {
		$send_on       = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		$send_on_clean = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		return ( $status === $send_on || $status === $send_on_clean || 'wc-' . $status === $send_on );
	}

	private static function send_on_status_clean() {
		$send_on = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		return ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
	}

	private static function sync_trigger_allows_status() {
		$m = get_option( self::OPT_SYNC_TRIGGER, 'on_status' );
		return in_array( $m, array( 'on_status', 'checkout_and_status', 'status_and_cron' ), true );
	}

	private static function sync_trigger_allows_checkout() {
		$m = get_option( self::OPT_SYNC_TRIGGER, 'on_status' );
		return $m === 'checkout_and_status';
	}

	private static function sync_trigger_allows_cron() {
		$m = get_option( self::OPT_SYNC_TRIGGER, 'on_status' );
		return in_array( $m, array( 'cron', 'status_and_cron' ), true );
	}

	/* ── Тесты диагностики ────────────────────────────────── */

	public static function run_test_action( $action ) {
		switch ( $action ) {
			case 'connection':
				$r = self::test_step_connection();
				break;
			case 'entities':
				$r = self::test_step_entities();
				break;
			case 'create_draft_roundtrip':
				$r = self::test_step_create_draft_roundtrip();
				break;
			default:
				return new WP_Error( 'wc_ms_test_unknown', 'Неизвестный тест.' );
		}
		return is_wp_error( $r ) ? $r : (string) $r;
	}

	private static function test_step_connection() {
		$api = self::api();
		$res = $api->test_connection();
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return 'Подключение к МойСклад: OK';
	}

	private static function test_step_entities() {
		$api    = self::api();
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

	/**
	 * Тест: создать черновой заказ в МС и обновить его state (roundtrip).
	 * Вместо жёсткой зависимости от товара-заглушки ищет первый товар в МС через API.
	 */
	private static function test_step_create_draft_roundtrip() {
		$api = self::api();

		// Организация
		$org_id = get_option( self::OPT_ORG_ID, '' );
		if ( $org_id ) {
			$org_meta = WC_MS_Order::make_meta( 'entity/organization/' . $org_id, 'organization' );
		} else {
			$orgs = $api->get_organizations();
			if ( is_wp_error( $orgs ) || empty( $orgs[0]['meta'] ) ) {
				return new WP_Error( 'wc_ms_test_org', 'Не удалось получить организацию.' );
			}
			$org_meta = $orgs[0]['meta'];
		}

		// Статусы
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

		// Товар: заглушка из настроек ИЛИ первый товар из МС
		$product_id = get_option( self::OPT_DEFAULT_PROD, '' );
		$product_type = 'product';
		if ( empty( $product_id ) ) {
			$assort = $api->get( 'entity/assortment', array( 'limit' => 1, 'filter' => 'type=product' ) );
			if ( ! is_wp_error( $assort ) && ! empty( $assort['rows'][0]['id'] ) ) {
				$product_id   = (string) $assort['rows'][0]['id'];
				$product_type = 'product';
			} else {
				// Попробуем услугу
				$assort = $api->get( 'entity/assortment', array( 'limit' => 1 ) );
				if ( ! is_wp_error( $assort ) && ! empty( $assort['rows'][0] ) ) {
					$row = $assort['rows'][0];
					$product_id = (string) $row['id'];
					if ( isset( $row['meta']['type'] ) ) {
						$product_type = $row['meta']['type'];
					}
				}
			}
		}
		if ( empty( $product_id ) ) {
			return new WP_Error( 'wc_ms_test_no_product', 'В МойСклад не найдено ни одного товара или услуги для тестового заказа.' );
		}

		// Контрагент
		$ts    = time();
		$email = 'wc-ms-test-' . $ts . '@example.com';
		$name  = 'WC-MS TEST ' . $ts;

		$agent = $api->create_counterparty( $name, $email, '' );
		if ( is_wp_error( $agent ) || empty( $agent['meta'] ) ) {
			return new WP_Error( 'wc_ms_test_agent', 'Не удалось создать тестового контрагента.' );
		}
		$agent_meta = $agent['meta'];

		$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		if ( $reserve_on_create && trim( (string) get_option( self::OPT_STORE_ID, '' ) ) === '' ) {
			return new WP_Error( 'wc_ms_test_reserve_store', 'Для теста с резервом укажите склад.' );
		}

		$price_kop = WC_MS_Order::price_to_cents( 100.0 );

		$positions = array(
			array(
				'quantity'   => 1,
				'price'      => $price_kop,
				'assortment' => array( 'meta' => WC_MS_Order::make_meta( 'entity/' . $product_type . '/' . $product_id, $product_type ) ),
			),
		);
		if ( $reserve_on_create ) {
			$positions[0]['reserve'] = 1;
		}

		$body = array(
			'name'         => 'WC-MS-TEST-' . $ts,
			'applicable'   => WC_MS_Order::ms_customerorder_applicable(),
			'agent'        => array( 'meta' => $agent_meta ),
			'organization' => array( 'meta' => $org_meta ),
			'description'  => 'Тест: создание черновика customerorder и смена state.',
			'positions'    => $positions,
		);

		$store_id = get_option( self::OPT_STORE_ID, '' );
		if ( $store_id ) {
			$body['store'] = array( 'meta' => WC_MS_Order::make_meta( 'entity/store/' . $store_id, 'store' ) );
		}

		$body['state'] = array(
			'meta' => WC_MS_Order::make_meta(
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
				'meta' => WC_MS_Order::make_meta( 'entity/customerorder/metadata/states/' . $next_state_id, 'state' ),
			),
		) );
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'wc_ms_test_update', 'Создал, но не смог обновить state: ' . $updated->get_error_message() );
		}

		return 'Тест OK: создан customerorder=' . $ms_id . ' и updated state.';
	}
}
