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
	/** UUID услуги «Доставка» в МС (строка позиции при включённой доставке). */
	const OPT_SHIPPING_SERVICE_ID = 'wc_ms_shipping_service_id';
	const OPT_RESERVE_ON_CREATE = 'wc_ms_reserve_on_create';
	/** Проводить заказ покупателя в МС (без резерва — если нужен только «проведённый» документ). */
	const OPT_ORDER_APPLICABLE = 'wc_ms_order_applicable';
	const OPT_NOTE_LABEL     = 'wc_ms_note_label';
	/** Шаблон поля «Название» заказа покупателя в МС (плейсхолдеры ниже на странице настроек). */
	const OPT_ORDER_NAME_TPL = 'wc_ms_order_name_tpl';
	/** Как собирать имя нового контрагента: first_last | last_first | company_or_fio */
	const OPT_AGENT_NAME_STYLE = 'wc_ms_agent_name_style';
	/** Многострочный шаблон комментария к заказу в МС; пусто — старый формат по умолчанию. */
	const OPT_DESCRIPTION_TPL = 'wc_ms_description_tpl';
	/** Контрагент: per_customer (поиск/создание по email) | fixed_uuid (один контрагент, как маркетплейс). */
	const OPT_AGENT_MODE = 'wc_ms_agent_mode';
	/** UUID контрагента в МС для режима fixed_uuid (карточка «Сайт Rodina-Kniga.ru» и т.п.). */
	const OPT_FIXED_COUNTERPARTY_ID = 'wc_ms_fixed_counterparty_id';
	/** Когда выгружать: on_status | checkout_and_status | cron | status_and_cron | manual */
	const OPT_SYNC_TRIGGER = 'wc_ms_sync_trigger';
	/** Интервал WP-Cron: wc_ms_15min | hourly | twicedaily | daily */
	const OPT_SYNC_CRON_SCHED = 'wc_ms_sync_cron_sched';

	const CRON_HOOK = 'wc_ms_cron_sync_orders';

	const ORDER_META_ID    = 'wc_ms_order_id';
	const ORDER_META_ERROR = 'wc_ms_error';
	const ORDER_META_NAME  = 'wc_ms_order_name';

	/* ── Инициализация ─────────────────────────────────────── */

	public static function init() {
		require_once __DIR__ . '/class-moysklad-api.php';

		// Ошибка 1062: Accept должен быть ровно application/json;charset=utf-8 (другие плагины часто затирают Accept).
		add_filter( 'http_request_args', array( 'WC_MS_API', 'enforce_moysklad_headers' ), 99999, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_wc_ms_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wc_ms_load_entities', array( __CLASS__, 'ajax_load_entities' ) );
		add_action( 'wp_ajax_wc_ms_preview_order', array( __CLASS__, 'ajax_preview_order' ) );
		add_action( 'wp_ajax_wc_ms_export_order', array( __CLASS__, 'ajax_export_order' ) );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 30, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_sync' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_intervals' ) );

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

	/**
	 * После активации плагина: перепланировать cron при необходимости.
	 */
	public static function on_activate() {
		self::reschedule_cron_sync();
	}

	/* ── Ссылка "Настройки" ────────────────────────────────── */

	public static function plugin_action_links( $links ) {
		$url  = admin_url( 'admin.php?page=wc-moysklad-sync' );
		$url2 = admin_url( 'admin.php?page=wc-moysklad-preview' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">Настройки</a>',
			'<a href="' . esc_url( $url2 ) . '">Предпросмотр</a>'
		);
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
				if ( ! is_string( $ms_state_id ) || $ms_state_id === '' || ! is_string( $wc_status ) || $wc_status === '' ) {
					continue;
				}
				// В хуке WC статус без префикса wc-; в селекте настроек ключи вида wc-processing — нормализуем оба.
				$wc_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
				$wc_to_ms[ $wc_clean ]  = $ms_state_id;
				$wc_to_ms[ $wc_status ] = $ms_state_id;
			}

			if ( isset( $wc_to_ms[ $new_status ] ) && $wc_to_ms[ $new_status ] !== '' ) {
				self::update_ms_order_state( $order, (string) $ms_id, (string) $wc_to_ms[ $new_status ] );
			}
			return;
		}

		if ( ! self::sync_trigger_allows_status() ) {
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

	/**
	 * Сразу после оформления: если статус заказа уже совпадает с «отправлять при», выгружаем раньше смены статуса.
	 *
	 * @param int $order_id ID заказа.
	 */
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
		self::sync_order( $order );
	}

	/**
	 * Периодическая выгрузка заказов без meta МС.
	 */
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
			self::sync_order( $order );
		}
	}

	/**
	 * @param array $schedules
	 * @return array
	 */
	public static function register_cron_intervals( $schedules ) {
		$schedules['wc_ms_15min'] = array(
			'interval' => 900,
			'display'  => 'WooCommerce МойСклад: каждые 15 минут',
		);
		return $schedules;
	}

	/* ── Синхронизация заказа ──────────────────────────────── */

	/**
	 * Заказ уже есть в МС (или старый ключ плагина).
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function order_already_synced( $order ) {
		return (bool) ( $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' ) );
	}

	/**
	 * @param string $status Статус без префикса wc-.
	 * @return bool
	 */
	private static function order_status_matches_send_on( $status ) {
		$send_on       = get_option( self::OPT_SEND_ON_STATUS, 'wc-processing' );
		$send_on_clean = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		return ( $status === $send_on || $status === $send_on_clean || 'wc-' . $status === $send_on );
	}

	/**
	 * @return string
	 */
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

	/**
	 * Собрать тело заказа покупателя для API МС.
	 *
	 * @param WC_Order $order
	 * @param WC_MS_API $api
	 * @return array|WP_Error
	 */
	private static function assemble_customerorder_payload( $order, $api ) {
		$org_id = get_option( self::OPT_ORG_ID, '' );
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

		$reserve_on = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		if ( $reserve_on && trim( (string) get_option( self::OPT_STORE_ID, '' ) ) === '' ) {
			return new WP_Error( 'ms_reserve', 'Резерв остатков: укажите склад в настройках плагина.' );
		}

		$positions = self::build_positions( $order, $api );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}

		if ( get_option( self::OPT_ADD_SHIPPING, '0' ) === '1' ) {
			$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			if ( $shipping_total > 0 ) {
				$ship_svc = trim( (string) get_option( self::OPT_SHIPPING_SERVICE_ID, '' ) );
				if ( $ship_svc === '' ) {
					return new WP_Error( 'ms_shipping_service', 'Включена строка доставки в заказ МС: укажите UUID услуги доставки в настройках плагина.' );
				}
				$positions[] = array(
					'quantity'   => 1,
					'price'      => self::price_to_cents( $shipping_total ),
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

		$store_id = get_option( self::OPT_STORE_ID, '' );
		if ( $store_id ) {
			$body['store'] = array( 'meta' => self::make_meta( 'entity/store/' . $store_id, 'store' ) );
		}

		$state_id = get_option( self::OPT_MS_STATE_ID, '' );
		if ( $state_id ) {
			$body['state'] = array( 'meta' => self::make_meta( 'entity/customerorder/metadata/states/' . $state_id, 'state' ) );
		}

		return $body;
	}

	public static function sync_order( $order ) {
		$login    = get_option( self::OPT_LOGIN, '' );
		$password = get_option( self::OPT_PASSWORD, '' );
		if ( empty( $login ) || empty( $password ) ) {
			self::set_error( $order, 'Не заданы логин или пароль МойСклад.' );
			return;
		}

		if ( self::order_already_synced( $order ) ) {
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
			if ( ! $order || self::order_already_synced( $order ) ) {
				return;
			}

			$api = self::api();

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
		} finally {
			delete_transient( $lock_key );
		}
	}

	/* ── Тесты (для отладки шагов синхронизации) ───────────────── */

	private static function run_test_action( $action ) {
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

		$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		if ( $reserve_on_create && trim( (string) get_option( self::OPT_STORE_ID, '' ) ) === '' ) {
			return new WP_Error( 'wc_ms_test_reserve_store', 'Для теста с резервом укажите склад в настройках (Организация и склад).' );
		}

		// Позиции: 1 товар по заглушке
		$price_kop = self::price_to_cents( 100.0 ); // 100.00 RUB -> 10000 коп.

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
			'applicable'   => self::ms_customerorder_applicable(),
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
	 * Сумма для предпросмотра: разделители как в WooCommerce + символ валюты (близко к колонкам МС).
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return string
	 */
	private static function format_money_preview_list( $amount, $currency ) {
		$dec  = wc_get_price_decimal_separator();
		$thou = wc_get_price_thousand_separator();
		$abs  = abs( (float) $amount );
		$n    = number_format( $abs, wc_get_price_decimals(), $dec, $thou );
		if ( $amount < 0 ) {
			$n = '-' . $n;
		}
		return $n . ' ' . get_woocommerce_currency_symbol( $currency );
	}

	/**
	 * Домен магазина для комментария в МС и шапки предпросмотра (вместо слова «WooCommerce»).
	 *
	 * @return string
	 */
	private static function site_host_for_ms_label() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return ( is_string( $host ) && $host !== '' ) ? $host : 'site';
	}

	/**
	 * Цвет статуса МС (число API) → #RRGGBB.
	 *
	 * @param mixed $color
	 * @return string
	 */
	private static function ms_ui_color_to_hex( $color ) {
		if ( is_numeric( $color ) ) {
			$n = max( 0, min( 0xffffff, (int) $color ) );
			return '#' . str_pad( dechex( $n ), 6, '0', STR_PAD_LEFT );
		}
		return '#2271b1';
	}

	/**
	 * Контрастный цвет текста для «таблетки» статуса.
	 *
	 * @param string $hex #RRGGBB
	 * @return string
	 */
	private static function contrast_text_for_hex( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
			return '#fff';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$yiq = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;
		return $yiq >= 145 ? '#1d2327' : '#fff';
	}

	/**
	 * Начальный статус заказа в МС для предпросмотра (имя + цвет из metadata).
	 *
	 * @param WC_MS_API $api
	 * @return array name (string), optional color (#hex) for UI.
	 */
	private static function preview_initial_ms_state( $api ) {
		$state_id = trim( (string) get_option( self::OPT_MS_STATE_ID, '' ) );
		$states   = $api->get_order_states();
		if ( is_wp_error( $states ) || ! is_array( $states ) ) {
			return array(
				'name'  => $state_id !== '' ? $state_id : '—',
				'color' => null,
			);
		}
		if ( $state_id !== '' ) {
			foreach ( $states as $st ) {
				if ( ! is_array( $st ) ) {
					continue;
				}
				if ( isset( $st['id'] ) && (string) $st['id'] === $state_id ) {
					$name = isset( $st['name'] ) ? (string) $st['name'] : $state_id;
					$hex  = null;
					if ( isset( $st['color'] ) ) {
						$hex = self::ms_ui_color_to_hex( $st['color'] );
					}
					return array( 'name' => $name, 'color' => $hex );
				}
			}
			return array( 'name' => $state_id, 'color' => null );
		}
		if ( ! empty( $states[0] ) && is_array( $states[0] ) ) {
			$st = $states[0];
			$name = isset( $st['name'] ) ? (string) $st['name'] : '—';
			$hex  = isset( $st['color'] ) ? self::ms_ui_color_to_hex( $st['color'] ) : null;
			return array( 'name' => $name . ' (первый в МС)', 'color' => $hex );
		}
		return array( 'name' => '—', 'color' => null );
	}

	/**
	 * Проводить ли заказ покупателя в МС. Резерв остатков по позициям обычно срабатывает только у проведённого заказа со складом.
	 *
	 * @return bool
	 */
	private static function ms_customerorder_applicable() {
		$reserve = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		$manual  = get_option( self::OPT_ORDER_APPLICABLE, '0' ) === '1';
		return $reserve || $manual;
	}

	/**
	 * Поле «Название» заказа покупателя в МС (до ~200 символов).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function format_ms_order_name( $order ) {
		$tpl = get_option( self::OPT_ORDER_NAME_TPL, '' );
		$tpl = is_string( $tpl ) ? trim( $tpl ) : '';
		if ( $tpl === '' ) {
			$tpl = 'WC-{order_number}';
		}
		$name = self::replace_order_tokens( $order, $tpl );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		if ( $name === '' ) {
			$name = 'WC-' . $order->get_order_number();
		}
		$name = self::truncate_ms_order_name( $name, 200 );
		return $name;
	}

	/**
	 * Обрезка названия для МС по символам UTF-8 (не по байтам).
	 *
	 * @param string $name
	 * @param int    $max_chars
	 * @return string
	 */
	/**
	 * Подпись заказа в списке предпросмотра: номер, статус на языке магазина, сумма, дата.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function format_preview_order_option_label( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$status_label = '';
		if ( is_callable( array( $order, 'get_status_label' ) ) ) {
			$status_label = (string) $order->get_status_label();
		}
		if ( $status_label === '' ) {
			$slug = $order->get_status();
			$key  = ( strpos( $slug, 'wc-' ) === 0 ) ? $slug : 'wc-' . $slug;
			$status_label = wc_get_order_status_name( $key );
		}
		if ( ! is_string( $status_label ) || $status_label === '' ) {
			$status_label = $order->get_status();
		}
		$date_str = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '—';
		$sym      = get_woocommerce_currency_symbol( $order->get_currency() );
		$total    = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
		return sprintf(
			'#%1$s — %2$s — %3$s %4$s — %5$s',
			$order->get_order_number(),
			$status_label,
			$total,
			$sym,
			$date_str
		);
	}

	private static function truncate_ms_order_name( $name, $max_chars ) {
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
	 * Комментарий к заказу в МС: свой шаблон или строка по умолчанию.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function build_order_description( $order ) {
		$tpl = get_option( self::OPT_DESCRIPTION_TPL, '' );
		$tpl = is_string( $tpl ) ? trim( $tpl ) : '';
		if ( $tpl !== '' ) {
			return self::replace_order_tokens( $order, $tpl );
		}
		$order_number = $order->get_order_number();
		return sprintf(
			'%s #%s | %s | %s %s | %s',
			self::site_host_for_ms_label(),
			$order_number,
			$order->get_billing_email(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_phone()
		);
	}

	/**
	 * Подстановка плейсхолдеров из заказа WC.
	 *
	 * @param WC_Order $order
	 * @param string   $tpl
	 * @return string
	 */
	private static function replace_order_tokens( $order, $tpl ) {
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
		$vars      = array(
			'{site_host}'            => self::site_host_for_ms_label(),
			'{order_number}'         => (string) $order->get_order_number(),
			'{order_id}'             => (string) $order->get_id(),
			'{first_name}'           => trim( (string) $order->get_billing_first_name() ),
			'{last_name}'            => trim( (string) $order->get_billing_last_name() ),
			'{company}'              => trim( (string) $order->get_billing_company() ),
			'{email}'                => $email,
			'{email_local}'          => $local,
			'{phone}'                => trim( (string) $order->get_billing_phone() ),
			'{shipping_city}'        => trim( (string) $order->get_shipping_city() ),
			'{shipping_address_1}'   => trim( (string) $order->get_shipping_address_1() ),
			'{payment_method_title}' => trim( (string) $order->get_payment_method_title() ),
			'{customer_note}'        => trim( (string) $order->get_customer_note() ),
			'{shipping_method}'      => self::order_shipping_methods_label( $order ),
			'{billing_address}'      => self::formatted_address_to_plain( $bill_html ),
			'{shipping_address}'     => self::formatted_address_to_plain( $ship_html ),
			'{order_total}'          => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) . ' ' . $order->get_currency(),
			'{line_items}'           => self::order_line_items_summary( $order ),
		);
		return str_replace( array_keys( $vars ), array_values( $vars ), $tpl );
	}

	/**
	 * Адрес из WC (HTML) в текст с переносами строк.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function formatted_address_to_plain( $html ) {
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
	private static function order_shipping_methods_label( $order ) {
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
		$labels = array_unique( $labels );
		return implode( ', ', $labels );
	}

	/**
	 * Краткий список позиций: «Товар ×2, …».
	 *
	 * @param WC_Order $order
	 * @param int      $max_len Макс. длина строки для МС.
	 * @return string
	 */
	private static function order_line_items_summary( $order, $max_len = 1500 ) {
		$parts = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) $item->get_name() ) );
			if ( $name === '' ) {
				continue;
			}
			$q      = (int) $item->get_quantity();
			$parts[] = $name . ' ×' . $q;
		}
		$s = implode( ', ', $parts );
		if ( strlen( $s ) > $max_len ) {
			return substr( $s, 0, $max_len - 3 ) . '...';
		}
		return $s;
	}

	/**
	 * Имя для создаваемого контрагента (уже существующий в МС подбирается по email/телефону — имя там не меняется).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function format_new_counterparty_name( $order ) {
		$style   = get_option( self::OPT_AGENT_NAME_STYLE, 'first_last' );
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
			$note_label = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
			$order->add_order_note( sprintf(
				'%s: Не удалось обновить state в МойСклад (заказ в МС не затронут как ошибка синхронизации): %s',
				$note_label,
				$result->get_error_message()
			) );
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
		$mode = get_option( self::OPT_AGENT_MODE, 'per_customer' );
		if ( $mode === 'fixed_uuid' ) {
			$uuid = trim( (string) get_option( self::OPT_FIXED_COUNTERPARTY_ID, '' ) );
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
				$positions[ count( $positions ) - 1 ]['reserve'] = (int) $quantity;
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
		$secret = get_option( self::OPT_WEBHOOK_SECRET, '' );
		if ( $secret === '' ) {
			return new WP_REST_Response( array( 'error' => 'Webhook secret not configured' ), 403 );
		}
		$provided = $request->get_param( 'secret' );
		if ( $provided !== $secret ) {
			return new WP_REST_Response( array( 'error' => 'Invalid secret' ), 403 );
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
					$wc_title   = wc_get_order_status_name( ( strpos( $wc_status, 'wc-' ) === 0 ) ? $wc_status : 'wc-' . $wc_status );
					$wc_order->update_status(
						$wc_status_clean,
						sprintf( '%s: Статус МС "%s" → WooCommerce "%s"', $note_label, $state_name, $wc_title ? $wc_title : $wc_status_clean )
					);
				}
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ── Предпросмотр выгрузки (как в МС) ──────────────────── */

	/**
	 * Подпись организации для предпросмотра.
	 *
	 * @param WC_MS_API $api
	 * @return string|WP_Error
	 */
	private static function preview_organization_label( $api ) {
		$org_id = get_option( self::OPT_ORG_ID, '' );
		if ( $org_id !== '' ) {
			$orgs = $api->get_organizations();
			if ( is_wp_error( $orgs ) ) {
				return $orgs;
			}
			foreach ( $orgs as $o ) {
				if ( isset( $o['id'] ) && (string) $o['id'] === (string) $org_id && ! empty( $o['name'] ) ) {
					return (string) $o['name'];
				}
			}
			return 'Организация UUID: ' . $org_id;
		}
		$orgs = $api->get_organizations();
		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}
		if ( ! empty( $orgs[0]['name'] ) ) {
			return (string) $orgs[0]['name'] . ' (первая в аккаунте)';
		}
		return '—';
	}

	/**
	 * Подпись контрагента без создания в МС.
	 *
	 * @param WC_Order  $order
	 * @param WC_MS_API $api
	 * @return string
	 */
	private static function preview_agent_label( $order, $api ) {
		if ( get_option( self::OPT_AGENT_MODE, 'per_customer' ) === 'fixed_uuid' ) {
			$uuid = trim( (string) get_option( self::OPT_FIXED_COUNTERPARTY_ID, '' ) );
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
	 * Данные для таблицы предпросмотра (проверка позиций через тот же API, что и при выгрузке).
	 *
	 * @param WC_Order $order
	 * @return array|WP_Error
	 */
	private static function build_export_preview_data( $order ) {
		$warnings = array();
		if ( get_option( self::OPT_ENABLED, '0' ) !== '1' ) {
			$warnings[] = 'Синхронизация в настройках выключена — автоотправка не пойдёт, ручная выгрузка с этой страницы всё равно возможна.';
		}

		$login    = get_option( self::OPT_LOGIN, '' );
		$password = get_option( self::OPT_PASSWORD, '' );
		if ( $login === '' || $password === '' ) {
			return new WP_Error( 'wc_ms_preview_auth', 'Задайте логин и пароль МойСклад в настройках.' );
		}

		$api = self::api();

		$org_label = self::preview_organization_label( $api );
		if ( is_wp_error( $org_label ) ) {
			return $org_label;
		}

		$chk = self::build_positions( $order, $api );
		if ( is_wp_error( $chk ) ) {
			return $chk;
		}

		$agent_label = self::preview_agent_label( $order, $api );
		$store_id    = trim( (string) get_option( self::OPT_STORE_ID, '' ) );
		$store_label = $store_id !== '' ? 'Склад UUID: ' . $store_id : '—';
		$reserve_on  = get_option( self::OPT_RESERVE_ON_CREATE, '0' ) === '1';
		if ( $reserve_on && $store_id === '' ) {
			$warnings[] = 'Включён резерв, но склад не выбран — выгрузка завершится ошибкой, пока не укажете склад.';
		}

		$positions_rows = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			$product  = $item->get_product();
			$sku      = $product ? $product->get_sku() : '';
			$qty      = (int) $item->get_quantity();
			$line_tot = (float) $item->get_total() + (float) $item->get_total_tax();
			$unit     = $qty > 0 ? $line_tot / $qty : 0.0;
			$positions_rows[] = array(
				'title'   => $item->get_name(),
				'sku'     => $sku !== '' ? $sku : '—',
				'qty'     => $qty,
				'price'   => wc_format_decimal( $unit, wc_get_price_decimals() ),
				'reserve' => $reserve_on ? (string) $qty : '—',
			);
		}

		if ( get_option( self::OPT_ADD_SHIPPING, '0' ) === '1' ) {
			$ship = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			if ( $ship > 0 ) {
				$positions_rows[] = array(
					'title'   => 'Доставка (услуга МС)',
					'sku'     => '—',
					'qty'     => 1,
					'price'   => wc_format_decimal( $ship, wc_get_price_decimals() ),
					'reserve' => '—',
				);
				if ( trim( (string) get_option( self::OPT_SHIPPING_SERVICE_ID, '' ) ) === '' ) {
					$warnings[] = 'Включена строка доставки, но не задан UUID услуги доставки в настройках — выгрузка завершится ошибкой.';
				}
			}
		}

		$cur         = $order->get_currency();
		$total       = (float) $order->get_total();
		$state_info  = self::preview_initial_ms_state( $api );
		$desc        = self::build_order_description( $order );
		$desc_clip   = $desc;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc_clip, 'UTF-8' ) > 180 ) {
			$desc_clip = mb_substr( $desc_clip, 0, 177, 'UTF-8' ) . '…';
		} elseif ( strlen( $desc_clip ) > 180 ) {
			$desc_clip = substr( $desc_clip, 0, 177 ) . '…';
		}

		$na = 'не указано';

		return array(
			'order_num'      => self::format_ms_order_name( $order ),
			'datetime'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '',
			'counterparty'   => $agent_label,
			'organization'   => $org_label,
			'store'          => $store_label,
			'sum_ms'         => self::format_money_preview_list( $total, $cur ),
			'shipped_ms'     => $na,
			'reserved_ms'    => $reserve_on ? self::format_money_preview_list( $total, $cur ) : $na,
			'ms_state'       => $state_info,
			'description'    => $desc,
			'description_short' => $desc_clip,
			'applicable'     => self::ms_customerorder_applicable(),
			'reserve_on'     => $reserve_on,
			'positions'      => $positions_rows,
			'warnings'       => $warnings,
			'wc_id'          => $order->get_id(),
			'wc_status'      => wc_get_order_status_name( 'wc-' . $order->get_status() ),
			'already_ms'     => self::order_already_synced( $order ),
		);
	}

	/**
	 * HTML таблиц «как в списке МС» + позиции.
	 *
	 * @param array    $d
	 * @param WC_Order $order
	 * @return string
	 */
	private static function render_ms_preview_table( array $d, $order ) {
		ob_start();
		?>
		<div class="wc-ms-preview-inner">
			<p><strong><?php echo esc_html( self::site_host_for_ms_label() ); ?></strong> #<?php echo esc_html( (string) $order->get_order_number() ); ?> — <?php echo esc_html( (string) $d['wc_status'] ); ?></p>
			<?php
			foreach ( $d['warnings'] as $w ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
			}
			if ( ! empty( $d['already_ms'] ) ) {
				echo '<div class="notice notice-info inline"><p>Этот заказ уже привязан к МойСклад — повторная выгрузка с этой страницы заблокирована (используйте «Пересоздать» в карточке заказа).</p></div>';
			}
			?>
			<p class="description wc-ms-preview-list-caption">Строка списка «Заказы покупателей» в МойСклад (те же смыслы колонок; суммы — в формате магазина).</p>
			<div class="wc-ms-table-scroll">
			<table class="widefat striped wc-ms-ms-list" style="min-width:920px;">
				<thead>
					<tr>
						<th>№</th>
						<th>Время</th>
						<th>Контрагент</th>
						<th>Организация</th>
						<th>Сумма / валюта</th>
						<th>Отгружено</th>
						<th>Зарезервировано</th>
						<th>Статус</th>
						<th>Комментарий</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php echo esc_html( $d['order_num'] ); ?></strong></td>
						<td><?php echo esc_html( $d['datetime'] ); ?></td>
						<td><?php echo esc_html( $d['counterparty'] ); ?></td>
						<td><?php echo esc_html( $d['organization'] ); ?></td>
						<td><?php echo esc_html( $d['sum_ms'] ); ?></td>
						<td><?php echo esc_html( $d['shipped_ms'] ); ?></td>
						<td><?php echo esc_html( $d['reserved_ms'] ); ?></td>
						<td>
							<?php
							$sn = isset( $d['ms_state']['name'] ) ? (string) $d['ms_state']['name'] : '—';
							$sc = isset( $d['ms_state']['color'] ) ? (string) $d['ms_state']['color'] : '';
							$bg = $sc !== '' ? $sc : '#2271b1';
							$fg = self::contrast_text_for_hex( $bg );
							?>
							<span class="wc-ms-pill-status" style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;"><?php echo esc_html( $sn ); ?></span>
							<?php if ( ! empty( $d['applicable'] ) ) : ?>
								<span class="description" title="Документ будет проведён в МС (как в настройках плагина)"> · проведён</span>
							<?php endif; ?>
						</td>
						<td class="wc-ms-td-comment" title="<?php echo esc_attr( $d['description'] ); ?>"><?php echo esc_html( $d['description_short'] !== '' ? $d['description_short'] : '—' ); ?></td>
					</tr>
				</tbody>
			</table>
			</div>
			<p class="description" style="margin-top:10px;margin-bottom:0;"><strong>Склад:</strong> <?php echo esc_html( $d['store'] ); ?>.
			В предпросмотре «Отгружено» не считается — после выгрузки и отгрузок в МойСклад там появятся фактические суммы.
			<?php if ( ! empty( $d['reserve_on'] ) ) : ?>
				При включённом резерве в колонке «Зарезервировано» показана ожидаемая сумма; в МС она обычно совпадает с заказом, пока нет отгрузки.
			<?php else : ?>
				Резерв в МС выключен — в предпросмотре «Зарезервировано» помечено как «не указано»; в интерфейсе МС суммы появятся после резервов и отгрузок.
			<?php endif; ?>
			</p>
			<?php if ( $d['description'] !== $d['description_short'] && $d['description'] !== '' ) : ?>
				<details class="wc-ms-preview-desc-full" style="margin-top:12px;">
					<summary>Полный комментарий к заказу в МС</summary>
					<div style="margin-top:8px;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;border-radius:4px;font-size:13px;"><?php echo esc_html( $d['description'] ); ?></div>
				</details>
			<?php endif; ?>

			<h3 style="margin-top:20px;">Позиции</h3>
			<table class="widefat striped" style="max-width:100%;">
				<thead>
					<tr>
						<th>Товар</th>
						<th>Артикул (SKU)</th>
						<th>Кол-во</th>
						<th>Цена за ед.</th>
						<th>Резерв (шт.)</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $d['positions'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['title'] ); ?></td>
							<td><code><?php echo esc_html( $row['sku'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['qty'] ); ?></td>
							<td><?php echo esc_html( $row['price'] ); ?></td>
							<td><?php echo esc_html( (string) $row['reserve'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 style="margin-top:20px;">Комментарий в МС</h3>
			<div style="background:#f6f7f7;border:1px solid #c3c4c7;padding:12px;white-space:pre-wrap;max-height:240px;overflow:auto;"><?php echo esc_html( $d['description'] ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function ajax_preview_order() {
		check_ajax_referer( 'wc_ms_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Нет прав.' );
		}
		$oid = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $oid < 1 ) {
			wp_send_json_error( 'Укажите номер заказа (ID).' );
		}
		$order = wc_get_order( $oid );
		if ( ! $order ) {
			wp_send_json_error( 'Заказ не найден.' );
		}
		$data = self::build_export_preview_data( $order );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}
		wp_send_json_success(
			array(
				'html' => self::render_ms_preview_table( $data, $order ),
			)
		);
	}

	public static function ajax_export_order() {
		check_ajax_referer( 'wc_ms_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Нет прав.' );
		}
		$oid = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $oid < 1 ) {
			wp_send_json_error( 'Укажите номер заказа (ID).' );
		}
		$order = wc_get_order( $oid );
		if ( ! $order ) {
			wp_send_json_error( 'Заказ не найден.' );
		}
		if ( self::order_already_synced( $order ) ) {
			wp_send_json_error( 'Заказ уже выгружен в МойСклад. Сбросьте связь в карточке заказа (Пересоздать), чтобы выгрузить снова.' );
		}
		self::sync_order( $order );
		$order = wc_get_order( $oid );
		if ( self::order_already_synced( $order ) ) {
			$ms_id = $order->get_meta( self::ORDER_META_ID );
			wp_send_json_success(
				array(
					'message' => 'Заказ выгружен в МойСклад.',
					'ms_url'  => 'https://online.moysklad.ru/app/#customerorder/edit?id=' . rawurlencode( (string) $ms_id ),
				)
			);
		}
		$err = $order->get_meta( self::ORDER_META_ERROR );
		wp_send_json_error( $err ? $err : 'Выгрузка не удалась (см. примечания к заказу).' );
	}

	/**
	 * Перепланировать WP-Cron при изменении настроек.
	 */
	private static function reschedule_cron_sync() {
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
		if ( strpos( $hook, 'wc-moysklad' ) === false ) {
			return;
		}
		wp_enqueue_style( 'wc-ms-admin', plugin_dir_url( WC_MS_SYNC_FILE ) . 'assets/admin.css', array(), WC_MS_SYNC_VERSION );
		wp_enqueue_script( 'wc-ms-admin', plugin_dir_url( WC_MS_SYNC_FILE ) . 'assets/admin.js', array( 'jquery' ), WC_MS_SYNC_VERSION, true );
		wp_localize_script(
			'wc-ms-admin',
			'wc_ms',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wc_ms_admin' ),
				'status_map' => get_option( self::OPT_STATUS_MAP, array() ),
			)
		);
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
		add_submenu_page(
			'woocommerce',
			'МойСклад — Предпросмотр',
			'Предпросмотр',
			'manage_woocommerce',
			'wc-moysklad-preview',
			array( __CLASS__, 'render_preview_page' )
		);
	}

	/**
	 * Предпросмотр выгрузки и тестовая отправка.
	 */
	public static function render_preview_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$recent = wc_get_orders(
			array(
				'limit'   => 50,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);
		$sync_on = get_option( self::OPT_ENABLED, '0' ) === '1';
		?>
		<div class="wrap wc-ms-app" id="wc_ms_preview_wrap">
			<h1 class="screen-reader-text">Предпросмотр МойСклад</h1>
			<div class="wc-ms-preview-hero">
				<p class="wc-ms-preview-kicker">Проверка выгрузки</p>
				<h2>Как заказ попадёт в МойСклад</h2>
				<p>Сначала «Просмотр» — увидите таблицу, как в списке заказов МС. Затем при необходимости «Отправить» — одна тестовая выгрузка. Автоматические правила — в <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-moysklad-sync' ) ); ?>">настройках</a>.</p>
			</div>

			<p style="margin:0 0 12px;">
				<?php if ( $sync_on ) : ?>
					<span class="wc-ms-badge wc-ms-badge--ok">Автовыгрузка включена</span>
				<?php else : ?>
					<span class="wc-ms-badge wc-ms-badge--off">Автовыгрузка выключена</span>
					<span class="description" style="margin-left:8px;">Ручной просмотр и отправка всё равно работают.</span>
				<?php endif; ?>
			</p>

			<div class="wc-ms-preview-toolbar">
				<div class="wc-ms-field">
					<label for="wc_ms_preview_order_id">Заказ</label>
					<select id="wc_ms_preview_order_id">
						<option value="">Выберите из последних 50 заказов…</option>
						<?php foreach ( $recent as $rid ) : ?>
							<?php
							$o = wc_get_order( $rid );
							if ( ! $o ) {
								continue;
							}
							$label = self::format_preview_order_option_label( $o );
							?>
							<option value="<?php echo esc_attr( (string) $rid ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="wc-ms-field" style="flex: 0 0 140px;">
					<label for="wc_ms_preview_order_id_manual">Или ID заказа</label>
					<input type="number" id="wc_ms_preview_order_id_manual" min="1" step="1" placeholder="Напр. 12345" />
				</div>
			</div>
			<p class="description" style="margin:-6px 0 0;">В строке списка: номер, статус (как в магазине), сумма, дата. ID — число из URL карточки заказа.</p>

			<div class="wc-ms-preview-actions">
				<button type="button" class="button button-large" id="wc_ms_preview_btn">Просмотр</button>
				<button type="button" class="button button-primary button-large" id="wc_ms_export_btn">Отправить в МойСклад</button>
				<p class="wc-ms-preview-msg" id="wc_ms_preview_ajax_msg" aria-live="polite"></p>
			</div>

			<div class="wc-ms-preview-result" id="wc_ms_preview_result"></div>
		</div>
		<?php
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
		$add_shipping         = get_option( self::OPT_ADD_SHIPPING, '0' );
		$shipping_service_id  = get_option( self::OPT_SHIPPING_SERVICE_ID, '' );
		$reserve_on_create = get_option( self::OPT_RESERVE_ON_CREATE, '0' );
		$order_applicable  = get_option( self::OPT_ORDER_APPLICABLE, '0' );
		$note_label   = get_option( self::OPT_NOTE_LABEL, 'МойСклад' );
		$order_name_tpl = get_option( self::OPT_ORDER_NAME_TPL, '' );
		$agent_mode   = get_option( self::OPT_AGENT_MODE, 'per_customer' );
		$fixed_cp_id  = get_option( self::OPT_FIXED_COUNTERPARTY_ID, '' );
		$agent_style  = get_option( self::OPT_AGENT_NAME_STYLE, 'first_last' );
		$desc_tpl     = get_option( self::OPT_DESCRIPTION_TPL, '' );
		$status_map   = get_option( self::OPT_STATUS_MAP, array() );
		$wh_active    = get_option( self::OPT_WEBHOOK_ACTIVE, '0' );
		$wh_id        = get_option( self::OPT_WEBHOOK_ID, '' );
		$sync_trigger = get_option( self::OPT_SYNC_TRIGGER, 'on_status' );
		$sync_cron_sched = get_option( self::OPT_SYNC_CRON_SCHED, 'hourly' );
		$next_cron_ts = wp_next_scheduled( self::CRON_HOOK );

		$wc_statuses = wc_get_order_statuses();
		$webhook_url = rest_url( 'moysklad/v1/webhook' );
		$preview_url = admin_url( 'admin.php?page=wc-moysklad-preview' );
		?>
		<div class="wrap wc-ms-app wc-ms-settings">
			<h1 class="screen-reader-text">МойСклад</h1>
			<div class="wc-ms-title">
				<span>МойСклад × WooCommerce</span>
				<span class="wc-ms-title-actions">
					<a href="<?php echo esc_url( $preview_url ); ?>" class="button">Предпросмотр заказа</a>
					<?php if ( get_option( self::OPT_ENABLED, '0' ) === '1' ) : ?>
						<span class="wc-ms-badge wc-ms-badge--ok">Вкл</span>
					<?php else : ?>
						<span class="wc-ms-badge wc-ms-badge--off">Выкл</span>
					<?php endif; ?>
				</span>
			</div>
			<p class="wc-ms-lead">Вкладки ниже — по шагам настройки. Сохранение одно на все поля (внизу любой вкладки). Тест одного заказа — в «Предпросмотр».</p>
			<?php echo $test_notice_html ? $test_notice_html : ''; ?>

			<form method="post" id="wc_ms_settings_form">
				<?php wp_nonce_field( 'wc_ms_save', 'wc_ms_nonce' ); ?>

				<ul class="wc-ms-tabs" role="tablist">
					<li><button type="button" class="wc-ms-tab is-active" data-wc-ms-tab="connect" role="tab" aria-selected="true">Подключение</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="company" role="tab" aria-selected="false">Компания</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="sync" role="tab" aria-selected="false">Выгрузка</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="catalog" role="tab" aria-selected="false">Товары</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="look" role="tab" aria-selected="false">Вид в МС</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="reverse" role="tab" aria-selected="false">МС → магазин</button></li>
					<li><button type="button" class="wc-ms-tab" data-wc-ms-tab="accounting" role="tab" aria-selected="false">Учёт</button></li>
				</ul>

				<div class="wc-ms-panels">

				<div class="wc-ms-panel is-active" id="wc-ms-panel-connect" data-wc-ms-panel="connect">
				<div class="wc-ms-card">
				<h3>Подключение к API МойСклад</h3>
				<p class="wc-ms-card-desc">Логин и пароль от учётной записи МойСклад. Кнопка «Проверить» только проверяет связь, настройки не пишет.</p>
				<table class="form-table">
					<tr>
						<th scope="row">Включить синхронизацию</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> /> Передавать заказы из WooCommerce в МойСклад</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_login">Логин</label></th>
						<td>
							<input type="text" id="ms_login" name="<?php echo esc_attr( self::OPT_LOGIN ); ?>" value="<?php echo esc_attr( $login ); ?>" class="regular-text" autocomplete="username" />
							<p class="description">Обычно e-mail учётной записи МойСклад.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_password">Пароль</label></th>
						<td>
							<input type="password" id="ms_password" name="<?php echo esc_attr( self::OPT_PASSWORD ); ?>" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••' : ''; ?>" autocomplete="new-password" />
							<p class="description">Оставьте поле пустым при сохранении, если пароль менять не нужно.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Проверка</th>
						<td>
							<button type="button" id="wc_ms_test_btn" class="button">Проверить подключение</button>
							<span id="wc_ms_test_result" style="margin-left:10px;"></span>
						</td>
					</tr>
				</table>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-company" data-wc-ms-panel="company">
				<div class="wc-ms-card">
				<h3>Организация и склад</h3>
				<p class="wc-ms-card-desc">Загрузите списки из МойСклад, затем выберите значения. Склад обязателен, если позже включите резерв (вкладка «Учёт»).</p>
				<p class="wc-ms-toolbar"><button type="button" id="wc_ms_load_btn" class="button button-primary">Загрузить из МойСклад</button> <span id="wc_ms_load_result" class="description"></span></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ms_org">Организация</label></th>
						<td>
							<select id="ms_org" name="<?php echo esc_attr( self::OPT_ORG_ID ); ?>" class="regular-text">
								<option value="">— Как в МойСклад по умолчанию —</option>
								<?php if ( $org_id ) : ?>
									<option value="<?php echo esc_attr( $org_id ); ?>" selected><?php echo esc_html( $org_id ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_store">Склад</label></th>
						<td>
							<select id="ms_store" name="<?php echo esc_attr( self::OPT_STORE_ID ); ?>" class="regular-text">
								<option value="">— Не указывать в заказе —</option>
								<?php if ( $store_id ) : ?>
									<option value="<?php echo esc_attr( $store_id ); ?>" selected><?php echo esc_html( $store_id ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description">Обязателен при резерве (вкладка «Учёт»).</p>
						</td>
					</tr>
				</table>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-sync" data-wc-ms-panel="sync">
				<div class="wc-ms-card">
				<h3>Когда создавать заказ в МойСклад</h3>
				<p class="wc-ms-card-desc">Момент первой отправки заказа в МС. Статусы WooCommerce — с теми подписями, что в списке заказов магазина.</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wc_ms_sync_trigger">Как запускать выгрузку</label></th>
						<td>
							<select id="wc_ms_sync_trigger" name="<?php echo esc_attr( self::OPT_SYNC_TRIGGER ); ?>">
								<option value="on_status" <?php selected( $sync_trigger, 'on_status' ); ?>>Когда заказ в WooCommerce переходит в выбранный ниже статус</option>
								<option value="checkout_and_status" <?php selected( $sync_trigger, 'checkout_and_status' ); ?>>Сразу после оформления (если статус уже подходит) и при дальнейшей смене статуса</option>
								<option value="cron" <?php selected( $sync_trigger, 'cron' ); ?>>Только по расписанию (фоновые задачи WordPress)</option>
								<option value="status_and_cron" <?php selected( $sync_trigger, 'status_and_cron' ); ?>>И по статусу, и по расписанию (на случай пропусков)</option>
								<option value="manual" <?php selected( $sync_trigger, 'manual' ); ?>>Только вручную: кнопка в карточке заказа или раздел «МС предпросмотр»</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_send_on">Статус WooCommerce для автовыгрузки</label></th>
						<td>
							<select id="ms_send_on" name="<?php echo esc_attr( self::OPT_SEND_ON_STATUS ); ?>">
								<?php foreach ( $wc_statuses as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $send_on, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Используется для режимов со статусом и для расписания: в МойСклад попадают заказы, у которых в WooCommerce сейчас <strong>этот</strong> статус (ещё не выгруженные ранее).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_state">С каким статусом создать заказ в МС</label></th>
						<td>
							<select id="ms_state" name="<?php echo esc_attr( self::OPT_MS_STATE_ID ); ?>" class="regular-text">
								<option value="">— Первый статус в вашем МойСклад —</option>
								<?php if ( $state_id ) : ?>
									<option value="<?php echo esc_attr( $state_id ); ?>" selected><?php echo esc_html( $state_id ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description">Список статусов заказа покупателя подгружается той же кнопкой, что организации и склады.</p>
						</td>
					</tr>
					<tr class="wc-ms-cron-row">
						<th scope="row"><label for="wc_ms_sync_cron_sched">Интервал при «по расписанию»</label></th>
						<td>
							<select id="wc_ms_sync_cron_sched" name="<?php echo esc_attr( self::OPT_SYNC_CRON_SCHED ); ?>">
								<option value="wc_ms_15min" <?php selected( $sync_cron_sched, 'wc_ms_15min' ); ?>>Каждые 15 минут</option>
								<option value="hourly" <?php selected( $sync_cron_sched, 'hourly' ); ?>>Каждый час</option>
								<option value="twicedaily" <?php selected( $sync_cron_sched, 'twicedaily' ); ?>>Дважды в сутки</option>
								<option value="daily" <?php selected( $sync_cron_sched, 'daily' ); ?>>Раз в сутки</option>
							</select>
							<p class="description">
								<?php
								if ( $next_cron_ts ) {
									echo esc_html( sprintf( 'Следующий запуск по времени сервера: %s', wp_date( 'd.m.Y H:i', $next_cron_ts ) ) );
								} else {
									echo esc_html( 'Расписание не активно: выберите режим с «расписанием», сохраните настройки. На хостинге должен работать системный cron для WordPress, иначе задержки возможны.' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-catalog" data-wc-ms-panel="catalog">
				<div class="wc-ms-card">
				<h3>Товары и доставка</h3>
				<p class="wc-ms-card-desc">Строки заказа в МС строятся по SKU. Если в МС нет позиции с таким артикулом — подставляется заглушка или строка пропускается.</p>
				<table class="form-table">
					<tr>
						<th><label for="ms_product">Товар-заглушка (UUID)</label></th>
						<td>
							<input type="text" id="ms_product" name="<?php echo esc_attr( self::OPT_DEFAULT_PROD ); ?>" value="<?php echo esc_attr( $default_prod ); ?>" class="regular-text" />
							<p class="description">UUID товара в МС для позиций без совпадения по артикулу (SKU). Оставьте пустым — позиции без совпадения будут пропущены.</p>
						</td>
					</tr>
					<tr>
						<th>Доставка в заказе МС</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ADD_SHIPPING ); ?>" value="1" <?php checked( $add_shipping, '1' ); ?> /> Добавлять отдельной позицией стоимость доставки WooCommerce как <strong>услугу</strong> в МойСклад</label>
							<p><label for="wc_ms_shipping_service">UUID услуги доставки в МС</label><br />
							<input type="text" id="wc_ms_shipping_service" name="<?php echo esc_attr( self::OPT_SHIPPING_SERVICE_ID ); ?>" value="<?php echo esc_attr( $shipping_service_id ); ?>" class="large-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></p>
							<p class="description">В МойСклад создайте услугу «Доставка», откройте карточку и скопируйте UUID из URL. Без UUID при включённой галочке и сумме доставки отправка не выполнится.</p>
						</td>
					</tr>
				</table>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-look" data-wc-ms-panel="look">
				<div class="wc-ms-card">
				<h3>Внешний вид заказа в МойСклад</h3>
				<p class="wc-ms-card-desc">Название в колонке «№», контрагент, многострочный комментарий (шаблон с плейсхолдерами).</p>
				<table class="form-table">
					<tr>
						<th><label for="wc_ms_agent_mode">Контрагент в заказе</label></th>
						<td>
							<select id="wc_ms_agent_mode" name="<?php echo esc_attr( self::OPT_AGENT_MODE ); ?>">
								<option value="per_customer" <?php selected( $agent_mode, 'per_customer' ); ?>>По покупателю: поиск в МС по email, затем по телефону; иначе новый контрагент</option>
								<option value="fixed_uuid" <?php selected( $agent_mode, 'fixed_uuid' ); ?>>Один контрагент для всех заказов с сайта (как Ozon/WB)</option>
							</select>
							<p class="description">Для варианта «один контрагент» создайте в МС карточку (например «Сайт Rodina-Kniga.ru»), скопируйте UUID из URL карточки.</p>
							<p><label for="wc_ms_fixed_cp">UUID контрагента</label><br />
							<input type="text" id="wc_ms_fixed_cp" name="<?php echo esc_attr( self::OPT_FIXED_COUNTERPARTY_ID ); ?>" value="<?php echo esc_attr( $fixed_cp_id ); ?>" class="large-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></p>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_order_name_tpl">Название заказа в МС (колонка «№»)</label></th>
						<td>
							<input type="text" id="wc_ms_order_name_tpl" name="<?php echo esc_attr( self::OPT_ORDER_NAME_TPL ); ?>" value="<?php echo esc_attr( $order_name_tpl ); ?>" class="large-text" placeholder="WC-{order_number}" />
							<p class="description">Плейсхолдеры: <code>{order_number}</code>, <code>{order_id}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{company}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{shipping_method}</code>, <code>{shipping_city}</code>. Пример: <code>WEB-{order_number}</code>. Пустое = <code>WC-{order_number}</code>.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_agent_name_style">Имя нового контрагента</label></th>
						<td>
							<select id="wc_ms_agent_name_style" name="<?php echo esc_attr( self::OPT_AGENT_NAME_STYLE ); ?>">
								<option value="first_last" <?php selected( $agent_style, 'first_last' ); ?>>Имя Фамилия</option>
								<option value="last_first" <?php selected( $agent_style, 'last_first' ); ?>>Фамилия Имя</option>
								<option value="company_or_fio" <?php selected( $agent_style, 'company_or_fio' ); ?>>Компания из заказа, если указана, иначе ФИО</option>
							</select>
							<p class="description">Только при режиме «по покупателю» и только при создании новой карточки в МС.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_description_tpl">Комментарий к заказу</label></th>
						<td>
							<textarea id="wc_ms_description_tpl" name="<?php echo esc_attr( self::OPT_DESCRIPTION_TPL ); ?>" class="large-text" rows="8" placeholder="Оставьте пустым — формат по умолчанию"><?php echo esc_textarea( $desc_tpl ); ?></textarea>
							<p class="description">Пустое = одна строка «<code>{site_host}</code> #… | email | ФИО | телефон» (домен магазина вместо слова WooCommerce). Пример для длинного комментария (скопируйте в поле):<br />
							<code style="display:block;white-space:pre-wrap;margin-top:6px;">Заказ сайта {site_host} #{order_number} (ID {order_id})<br />Покупатель: {first_name} {last_name} | {phone} | {email}<br />Доставка: {shipping_method}<br />Адрес: {shipping_address}<br />Оплата: {payment_method_title} | Сумма: {order_total}<br />Примечание: {customer_note}<br />Состав: {line_items}</code><br />
							Ещё плейсхолдеры: <code>{billing_address}</code>, <code>{shipping_address_1}</code>, <code>{shipping_city}</code>, <code>{email_local}</code>.</p>
						</td>
					</tr>
				</table>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-reverse" data-wc-ms-panel="reverse">
				<div class="wc-ms-card">
				<h3>Из МойСклад обратно в магазин</h3>
				<p class="wc-ms-card-desc">Когда статус заказа меняется <strong>в МС</strong>, можно автоматически сменить статус <strong>в WooCommerce</strong>. Сначала загрузите статусы МС (вкладка «Компания»), заполните таблицу, сохраните настройки. Ниже — создание вебхука (отдельная кнопка).</p>
				<h4 style="margin:12px 0 8px;font-size:13px;">Сопоставление статусов WP ↔ МС</h4>
				<div id="wc_ms_status_map_container">
					<p class="description" style="margin:0 0 10px;">Добавляйте пары кнопкой «+». Можно сразу ввести UUID статуса МС вручную или загрузить статусы на вкладке «Компания» и выбрать из списка.</p>
					<input type="hidden" name="wc_ms_map_pairs_present" value="1" />
					<table class="widefat wc-ms-map-table" style="max-width:760px;">
						<thead><tr><th>Статус WooCommerce</th><th>Статус МойСклад (UUID)</th><th style="width:44px;"></th></tr></thead>
						<tbody id="wc_ms_status_pairs_body">
							<?php if ( ! empty( $status_map ) && is_array( $status_map ) ) : ?>
								<?php foreach ( $status_map as $ms_state => $wc_status ) : ?>
									<tr>
										<td>
											<select name="wc_ms_map_pairs[wc][]" style="width:100%;">
												<option value="">— Не менять —</option>
												<?php foreach ( $wc_statuses as $st_key => $st_label ) : ?>
													<option value="<?php echo esc_attr( $st_key ); ?>" <?php selected( $wc_status, $st_key ); ?>><?php echo esc_html( $st_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td>
											<select name="wc_ms_map_pairs[ms][]" class="wc-ms-ms-state-select" style="width:100%;">
												<option value="">— Выберите статус МойСклад —</option>
												<option value="<?php echo esc_attr( (string) $ms_state ); ?>" selected><?php echo esc_html( (string) $ms_state ); ?></option>
											</select>
										</td>
										<td>
											<button type="button" class="button-link-delete wc-ms-map-remove" aria-label="Удалить сопоставление">×</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr class="wc-ms-map-empty"><td colspan="3"><em>Добавьте первую пару кнопкой «+ Добавить сопоставление».</em></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
					<p style="margin:10px 0 0;">
						<button type="button" class="button" id="wc_ms_map_add_btn">+ Добавить сопоставление</button>
					</p>
				</div>
				</div></div>

				<div class="wc-ms-panel" id="wc-ms-panel-accounting" data-wc-ms-panel="accounting">
				<div class="wc-ms-card">
				<h3>Учёт в МойСклад</h3>
				<p class="wc-ms-card-desc">Резерв, проведение документа, подпись к заметкам. Отладка — для диагностики.</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ms_note">Подпись в примечаниях к заказу</label></th>
						<td>
							<input type="text" id="ms_note" name="<?php echo esc_attr( self::OPT_NOTE_LABEL ); ?>" value="<?php echo esc_attr( $note_label ); ?>" class="regular-text" placeholder="МойСклад" />
							<p class="description">Короткое слово в начале системных записей в истории заказа WooCommerce.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Резерв на складе</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_RESERVE_ON_CREATE ); ?>" value="1" <?php checked( $reserve_on_create, '1' ); ?> /> Резервировать количество по строкам заказа на выбранном складе</label>
							<p class="description">Нужен выбранный <strong>склад</strong> на вкладке «Компания». Заказ в МС будет проведён, чтобы резерв сработал. Товары в МС должны вести учёт по этому складу.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Проведённый заказ без резерва</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ORDER_APPLICABLE ); ?>" value="1" <?php checked( $order_applicable, '1' ); ?> /> Сразу проводить заказ покупателя, если резерв не используется</label>
							<p class="description">Включайте, если нужен сразу «живой» документ в учёте без резервирования строк. Если включён резерв, проведение всё равно будет включено автоматически.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Отладка для разработчика</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_DEBUG ); ?>" value="1" <?php checked( $debug, '1' ); ?> /> Писать обращения к API в лог WordPress (<code>WP_DEBUG_LOG</code>)</label></td>
					</tr>
				</table>
				</div>

				<div class="wc-ms-card">
				<h3>Диагностика</h3>
				<p class="wc-ms-card-desc">Тесты обращаются к МойСклад сразу. Результат появится над вкладками после отправки формы.</p>
				<p class="wc-ms-toolbar" style="margin:0;">
					<button type="submit" class="button" name="wc_ms_test_action" value="connection">Связь с МС</button>
					<button type="submit" class="button" name="wc_ms_test_action" value="entities">Загрузить сущности</button>
					<button type="submit" class="button button-primary" name="wc_ms_test_action" value="create_draft_roundtrip">Тестовый заказ в МС</button>
				</p>
				</div>
				</div>

				</div><?php // .wc-ms-panels ?>

				<div class="wc-ms-sticky-footer">
					<p class="wc-ms-footer-hint">Сохраняются все поля на всех вкладках.</p>
					<input type="submit" class="button-primary button-large" name="wc_ms_save_main" value="Сохранить настройки" />
				</div>
			</form>

			<div class="wc-ms-panel-extra" id="wc-ms-webhook-block" data-wc-ms-for-tab="reverse" style="display:none;">
			<div class="wc-ms-panels" style="border-top:none;margin-top:-1px;">
			<div class="wc-ms-card" style="margin:0;">
			<h3>Вебхук</h3>
			<p class="wc-ms-card-desc">Нужен для строк таблицы выше: МС сообщает сайту о смене статуса. Создавайте кнопкой — секрет добавится сам.</p>
			<p style="margin:0 0 8px;"><strong>URL для ручной вставки в МС:</strong> <code style="word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code></p>
			<p class="description">Без секрета в URL сайт ответит отказом.</p>
			<?php
			$wh_secret = get_option( self::OPT_WEBHOOK_SECRET, '' );
			if ( $wh_active === '1' && $wh_secret === '' ) :
				?>
				<div class="notice notice-warning inline"><p>Вебхук отмечен активным, но секрет в базе пуст — входящие уведомления МС не будут приняты. Удалите вебхук и создайте снова.</p></div>
			<?php endif; ?>
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
			</div></div></div>
		</div>
		<?php
	}

	/* ── Сохранение настроек ───────────────────────────────── */

	private static function save_settings() {
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		$fields = array(
			self::OPT_ENABLED      => 'checkbox',
			self::OPT_LOGIN        => 'text',
			self::OPT_ORG_ID       => 'text',
			self::OPT_STORE_ID     => 'text',
			self::OPT_SEND_ON_STATUS => 'text',
			self::OPT_MS_STATE_ID  => 'text',
			self::OPT_DEFAULT_PROD => 'text',
			self::OPT_ORDER_NAME_TPL => 'text',
			self::OPT_DEBUG        => 'checkbox',
			self::OPT_ADD_SHIPPING         => 'checkbox',
			self::OPT_SHIPPING_SERVICE_ID  => 'text',
			self::OPT_RESERVE_ON_CREATE => 'checkbox',
			self::OPT_ORDER_APPLICABLE => 'checkbox',
			self::OPT_NOTE_LABEL   => 'text',
		);

		foreach ( $fields as $key => $type ) {
			if ( $type === 'checkbox' ) {
				update_option( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
			} else {
				update_option( $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' );
			}
		}

		$agent_style = isset( $_POST[ self::OPT_AGENT_NAME_STYLE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_AGENT_NAME_STYLE ] ) ) : 'first_last';
		$allowed_ag  = array( 'first_last', 'last_first', 'company_or_fio' );
		update_option( self::OPT_AGENT_NAME_STYLE, in_array( $agent_style, $allowed_ag, true ) ? $agent_style : 'first_last' );

		$desc_tpl = isset( $_POST[ self::OPT_DESCRIPTION_TPL ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::OPT_DESCRIPTION_TPL ] ) ) : '';
		update_option( self::OPT_DESCRIPTION_TPL, $desc_tpl );

		$agent_mode = isset( $_POST[ self::OPT_AGENT_MODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_AGENT_MODE ] ) ) : 'per_customer';
		if ( ! in_array( $agent_mode, array( 'per_customer', 'fixed_uuid' ), true ) ) {
			$agent_mode = 'per_customer';
		}
		update_option( self::OPT_AGENT_MODE, $agent_mode );

		if ( isset( $_POST[ self::OPT_FIXED_COUNTERPARTY_ID ] ) ) {
			update_option( self::OPT_FIXED_COUNTERPARTY_ID, sanitize_text_field( wp_unslash( $_POST[ self::OPT_FIXED_COUNTERPARTY_ID ] ) ) );
		}

		// Пароль — только если не пустой
		if ( isset( $_POST[ self::OPT_PASSWORD ] ) && $_POST[ self::OPT_PASSWORD ] !== '' ) {
			update_option( self::OPT_PASSWORD, sanitize_text_field( wp_unslash( $_POST[ self::OPT_PASSWORD ] ) ) );
		}

		// Маппинг статусов (новый формат пар + fallback со старого формата).
		$map = null;
		if ( isset( $_POST['wc_ms_map_pairs_present'] ) ) {
			$map       = array();
			$pair_data = isset( $_POST['wc_ms_map_pairs'] ) && is_array( $_POST['wc_ms_map_pairs'] ) ? $_POST['wc_ms_map_pairs'] : array();
			$wc_list   = isset( $pair_data['wc'] ) && is_array( $pair_data['wc'] ) ? $pair_data['wc'] : array();
			$ms_list   = isset( $pair_data['ms'] ) && is_array( $pair_data['ms'] ) ? $pair_data['ms'] : array();
			$limit     = max( count( $wc_list ), count( $ms_list ) );

			for ( $i = 0; $i < $limit; $i++ ) {
				$ms_state  = isset( $ms_list[ $i ] ) ? sanitize_text_field( wp_unslash( $ms_list[ $i ] ) ) : '';
				$wc_status = isset( $wc_list[ $i ] ) ? sanitize_text_field( wp_unslash( $wc_list[ $i ] ) ) : '';
				if ( $ms_state !== '' && $wc_status !== '' ) {
					$wc_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? $wc_status : 'wc-' . $wc_status;
					if ( ! isset( $wc_statuses[ $wc_clean ] ) ) {
						continue;
					}
					$map[ $ms_state ] = $wc_status;
				}
			}
		} elseif ( isset( $_POST['wc_ms_map'] ) && is_array( $_POST['wc_ms_map'] ) ) {
			$map = array();
			foreach ( $_POST['wc_ms_map'] as $ms_state => $wc_status ) {
				$ms_state  = sanitize_text_field( $ms_state );
				$wc_status = sanitize_text_field( $wc_status );
				if ( $ms_state !== '' && $wc_status !== '' ) {
					$wc_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? $wc_status : 'wc-' . $wc_status;
					if ( ! isset( $wc_statuses[ $wc_clean ] ) ) {
						continue;
					}
					$map[ $ms_state ] = $wc_status;
				}
			}
		}
		if ( is_array( $map ) ) {
			update_option( self::OPT_STATUS_MAP, $map );
		}

		$trigger = isset( $_POST[ self::OPT_SYNC_TRIGGER ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_SYNC_TRIGGER ] ) ) : 'on_status';
		$allowed_triggers = array( 'on_status', 'checkout_and_status', 'cron', 'status_and_cron', 'manual' );
		if ( ! in_array( $trigger, $allowed_triggers, true ) ) {
			$trigger = 'on_status';
		}
		update_option( self::OPT_SYNC_TRIGGER, $trigger );

		$cron_sched = isset( $_POST[ self::OPT_SYNC_CRON_SCHED ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_SYNC_CRON_SCHED ] ) ) : 'hourly';
		$allowed_sched = array( 'wc_ms_15min', 'hourly', 'twicedaily', 'daily' );
		if ( ! in_array( $cron_sched, $allowed_sched, true ) ) {
			$cron_sched = 'hourly';
		}
		update_option( self::OPT_SYNC_CRON_SCHED, $cron_sched );

		self::reschedule_cron_sync();
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
