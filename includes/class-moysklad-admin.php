<?php
/**
 * Админка плагина МойСклад: настройки, предпросмотр, AJAX, мета-бокс, колонка.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_Admin {

	/* ── Инициализация ────────────────────────────────────── */

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wc_ms_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wc_ms_load_entities', array( __CLASS__, 'ajax_load_entities' ) );
		add_action( 'wp_ajax_wc_ms_preview_order', array( __CLASS__, 'ajax_preview_order' ) );
		add_action( 'wp_ajax_wc_ms_export_order', array( __CLASS__, 'ajax_export_order' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 20, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'on_save_order' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_save_order' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column_hpos' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( WC_MS_SYNC_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	/* ── Ссылки плагина ───────────────────────────────────── */

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

	/* ── Колонка «МС» ─────────────────────────────────────── */

	public static function add_order_column( $columns ) {
		if ( get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) !== '1' ) {
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
		$ms_id    = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );
		$ms_error = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ERROR ) ?: $order->get_meta( 'yd_moysklad_error' );
		if ( $ms_id ) {
			echo '<span style="color:green;" title="Передан в МС">&#10003;</span>';
		} elseif ( $ms_error ) {
			echo '<span style="color:red;" title="' . esc_attr( $ms_error ) . '">&#10007;</span>';
		} else {
			echo '<span style="color:#999;">—</span>';
		}
	}

	/* ── Мета-бокс ────────────────────────────────────────── */

	public static function add_order_meta_box( $post_type, $post ) {
		if ( ( $post_type !== 'shop_order' && $post_type !== 'wc-order' ) || get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) !== '1' ) {
			return;
		}
		add_meta_box( 'wc_moysklad_sync', 'МойСклад', array( __CLASS__, 'render_order_meta_box' ), $post_type, 'side' );
	}

	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$ms_id    = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );
		$ms_error = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ERROR ) ?: $order->get_meta( 'yd_moysklad_error' );
		$ms_name  = $order->get_meta( WC_MoySklad_Sync::ORDER_META_NAME );

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
			$ord->delete_meta_data( WC_MoySklad_Sync::ORDER_META_ID );
			$ord->delete_meta_data( WC_MoySklad_Sync::ORDER_META_ERROR );
			$ord->delete_meta_data( WC_MoySklad_Sync::ORDER_META_NAME );
			$ord->delete_meta_data( 'yd_moysklad_id' );
			$ord->delete_meta_data( 'yd_moysklad_error' );
			$ord->save();
		}
		WC_MS_Order::sync_order( $ord );
	}

	/* ── Скрипты ──────────────────────────────────────────── */

	public static function enqueue_scripts( $hook ) {
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
				'status_map' => get_option( WC_MoySklad_Sync::OPT_STATUS_MAP, array() ),
			)
		);
	}

	/* ── AJAX: Проверка подключения ────────────────────────── */

	public static function ajax_test_connection() {
		check_ajax_referer( 'wc_ms_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Нет прав.' );
		}

		$login    = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : get_option( WC_MoySklad_Sync::OPT_LOGIN, '' );
		$password = isset( $_POST['password'] ) && $_POST['password'] !== '' ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : get_option( WC_MoySklad_Sync::OPT_PASSWORD, '' );

		if ( $login === '' && $password === '' ) {
			wp_send_json_error( 'Введите логин/пароль или Bearer-токен.' );
		}
		if ( $login !== '' && $password === '' ) {
			wp_send_json_error( 'Введите пароль.' );
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

		$api  = WC_MoySklad_Sync::api();
		$data = array();

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

	/* ── AJAX: Предпросмотр ────────────────────────────────── */

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
		$data = self::build_preview_data( $order );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}
		wp_send_json_success(
			array(
				'html' => self::render_preview_table( $data, $order ),
			)
		);
	}

	/* ── AJAX: Экспорт ─────────────────────────────────────── */

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
		if ( WC_MoySklad_Sync::order_already_synced( $order ) ) {
			wp_send_json_error( 'Заказ уже выгружен в МойСклад. Сбросьте связь в карточке заказа (Пересоздать), чтобы выгрузить снова.' );
		}
		WC_MS_Order::sync_order( $order );
		$order = wc_get_order( $oid );
		if ( WC_MoySklad_Sync::order_already_synced( $order ) ) {
			$ms_id = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ID );
			wp_send_json_success(
				array(
					'message' => 'Заказ выгружен в МойСклад.',
					'ms_url'  => 'https://online.moysklad.ru/app/#customerorder/edit?id=' . rawurlencode( (string) $ms_id ),
				)
			);
		}
		$err = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ERROR );
		wp_send_json_error( $err ? $err : 'Выгрузка не удалась (см. примечания к заказу).' );
	}

	/* ── Меню ──────────────────────────────────────────────── */

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

	/* ── Предпросмотр: сбор данных ─────────────────────────── */

	private static function build_preview_data( $order ) {
		$warnings = array();
		if ( get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) !== '1' ) {
			$warnings[] = 'Синхронизация в настройках выключена — автоотправка не пойдёт, ручная выгрузка с этой страницы всё равно возможна.';
		}

		$login    = get_option( WC_MoySklad_Sync::OPT_LOGIN, '' );
		$password = get_option( WC_MoySklad_Sync::OPT_PASSWORD, '' );
		// Bearer: логин пуст, пароль = токен. Basic: оба нужны.
		if ( $login === '' && $password === '' ) {
			return new WP_Error( 'wc_ms_preview_auth', 'Задайте логин/пароль или Bearer-токен МойСклад в настройках.' );
		}
		if ( $login !== '' && $password === '' ) {
			return new WP_Error( 'wc_ms_preview_auth', 'Задайте пароль МойСклад в настройках.' );
		}

		$api = WC_MoySklad_Sync::api();

		$org_label = self::preview_organization_label( $api );
		if ( is_wp_error( $org_label ) ) {
			return $org_label;
		}

		$chk = WC_MS_Order::build_positions( $order, $api );
		if ( is_wp_error( $chk ) ) {
			return $chk;
		}

		$agent_label = WC_MS_Order::preview_agent_label( $order, $api );
		$store_id    = trim( (string) get_option( WC_MoySklad_Sync::OPT_STORE_ID, '' ) );
		$store_label = $store_id !== '' ? 'Склад UUID: ' . $store_id : '—';
		$reserve_on  = get_option( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE, '0' ) === '1';
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

		if ( get_option( WC_MoySklad_Sync::OPT_ADD_SHIPPING, '0' ) === '1' ) {
			$ship = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			if ( $ship > 0 ) {
				$positions_rows[] = array(
					'title'   => 'Доставка (услуга МС)',
					'sku'     => '—',
					'qty'     => 1,
					'price'   => wc_format_decimal( $ship, wc_get_price_decimals() ),
					'reserve' => '—',
				);
				if ( trim( (string) get_option( WC_MoySklad_Sync::OPT_SHIPPING_SERVICE_ID, '' ) ) === '' ) {
					$warnings[] = 'Включена строка доставки, но не задан UUID услуги доставки в настройках — выгрузка завершится ошибкой.';
				}
			}
		}

		$cur         = $order->get_currency();
		$total       = (float) $order->get_total();
		$state_info  = self::preview_initial_ms_state( $api );
		$desc        = WC_MS_Order::build_order_description( $order );
		$desc_clip   = $desc;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc_clip, 'UTF-8' ) > 180 ) {
			$desc_clip = mb_substr( $desc_clip, 0, 177, 'UTF-8' ) . '…';
		} elseif ( strlen( $desc_clip ) > 180 ) {
			$desc_clip = substr( $desc_clip, 0, 177 ) . '…';
		}

		$na = 'не указано';

		return array(
			'order_num'         => WC_MS_Order::format_ms_order_name( $order ),
			'datetime'          => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '',
			'counterparty'      => $agent_label,
			'organization'      => $org_label,
			'store'             => $store_label,
			'sum_ms'            => WC_MS_Order::format_money_preview( $total, $cur ),
			'shipped_ms'        => $na,
			'reserved_ms'       => $reserve_on ? WC_MS_Order::format_money_preview( $total, $cur ) : $na,
			'ms_state'          => $state_info,
			'description'       => $desc,
			'description_short' => $desc_clip,
			'applicable'        => WC_MS_Order::ms_customerorder_applicable(),
			'reserve_on'        => $reserve_on,
			'positions'         => $positions_rows,
			'warnings'          => $warnings,
			'wc_id'             => $order->get_id(),
			'wc_status'         => wc_get_order_status_name( 'wc-' . $order->get_status() ),
			'already_ms'        => WC_MoySklad_Sync::order_already_synced( $order ),
			'tokens_ref'        => WC_MS_Tokens::reference_table( $order ),
		);
	}

	/* ── Предпросмотр: хелперы ─────────────────────────────── */

	private static function preview_organization_label( $api ) {
		$org_id = get_option( WC_MoySklad_Sync::OPT_ORG_ID, '' );
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

	private static function preview_initial_ms_state( $api ) {
		$state_id = trim( (string) get_option( WC_MoySklad_Sync::OPT_MS_STATE_ID, '' ) );
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
					$hex  = isset( $st['color'] ) ? self::ms_color_to_hex( $st['color'] ) : null;
					return array( 'name' => $name, 'color' => $hex );
				}
			}
			return array( 'name' => $state_id, 'color' => null );
		}
		if ( ! empty( $states[0] ) && is_array( $states[0] ) ) {
			$st = $states[0];
			$name = isset( $st['name'] ) ? (string) $st['name'] : '—';
			$hex  = isset( $st['color'] ) ? self::ms_color_to_hex( $st['color'] ) : null;
			return array( 'name' => $name . ' (первый в МС)', 'color' => $hex );
		}
		return array( 'name' => '—', 'color' => null );
	}

	private static function ms_color_to_hex( $color ) {
		if ( is_numeric( $color ) ) {
			$n = max( 0, min( 0xffffff, (int) $color ) );
			return '#' . str_pad( dechex( $n ), 6, '0', STR_PAD_LEFT );
		}
		return '#2271b1';
	}

	private static function contrast_text( $hex ) {
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

	private static function format_preview_order_label( $order ) {
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

	/* ── Предпросмотр: рендер таблицы ──────────────────────── */

	private static function render_preview_table( array $d, $order ) {
		ob_start();
		?>
		<div class="wc-ms-preview-inner">
			<p><strong><?php echo esc_html( WC_MS_Tokens::site_host() ); ?></strong> #<?php echo esc_html( (string) $order->get_order_number() ); ?> — <?php echo esc_html( (string) $d['wc_status'] ); ?></p>
			<?php
			foreach ( $d['warnings'] as $w ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
			}
			if ( ! empty( $d['already_ms'] ) ) {
				echo '<div class="notice notice-info inline"><p>Этот заказ уже привязан к МойСклад — повторная выгрузка заблокирована (используйте «Пересоздать» в карточке заказа).</p></div>';
			}
			?>
			<p class="description wc-ms-preview-list-caption">Строка списка «Заказы покупателей» в МойСклад.</p>
			<div class="wc-ms-table-scroll">
			<table class="widefat striped wc-ms-ms-list" style="min-width:920px;">
				<thead>
					<tr>
						<th>&#8470;</th>
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
							$fg = self::contrast_text( $bg );
							?>
							<span class="wc-ms-pill-status" style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;"><?php echo esc_html( $sn ); ?></span>
							<?php if ( ! empty( $d['applicable'] ) ) : ?>
								<span class="description"> · проведён</span>
							<?php endif; ?>
						</td>
						<td class="wc-ms-td-comment" title="<?php echo esc_attr( $d['description'] ); ?>"><?php echo esc_html( $d['description_short'] !== '' ? $d['description_short'] : '—' ); ?></td>
					</tr>
				</tbody>
			</table>
			</div>
			<p class="description" style="margin-top:10px;margin-bottom:0;"><strong>Склад:</strong> <?php echo esc_html( $d['store'] ); ?>.</p>

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

			<?php // Справочник переменных ?>
			<h3 style="margin-top:20px;">Справочник переменных</h3>
			<p class="description">Используйте плейсхолдеры в шаблонах названия заказа и комментария. Нажимайте на переменную, чтобы скопировать. Значения — из выбранного заказа. 💡 = подсказка с примером.</p>

			<?php
			$category_labels = array(
				'order'   => '📦 Заказ',
				'customer' => '👤 Покупатель',
				'billing' => '💳 Адрес оплаты',
				'shipping' => '🚚 Адрес доставки',
				'payment' => '💰 Доставка и оплата',
				'other'   => '📝 Прочее',
			);
			?>

			<?php foreach ( $d['tokens_ref'] as $category => $rows ) : ?>
				<h4 style="margin-top:16px;margin-bottom:8px;color:#555;"><?php echo esc_html( isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ucfirst( $category ) ); ?></h4>
				<table class="widefat striped wc-ms-tokens-ref" style="max-width:100%;margin-bottom:16px;">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr style="vertical-align:top;">
								<td style="width:180px;padding:10px;">
									<code class="wc-ms-token-copy" style="cursor:pointer;background:#f6f7f7;padding:4px 6px;display:block;word-break:break-all;user-select:all;" title="Нажмите, чтобы скопировать"><?php echo esc_html( $row['token'] ); ?></code>
								</td>
								<td style="width:240px;padding:10px;">
									<strong style="display:block;margin-bottom:4px;"><?php echo esc_html( $row['short_desc'] ); ?></strong>
									<span class="dashicons dashicons-info" style="color:#0073aa;cursor:help;font-size:16px;width:auto;height:auto;" title="<?php echo esc_attr( $row['full_desc'] ); ?>" onclick="alert('<?php echo esc_attr( $row['full_desc'] ); ?>')"></span>
								</td>
								<td style="padding:10px;">
									<span style="font-size:13px;word-break:break-word;color:#666;display:block;max-height:80px;overflow:auto;border-left:3px solid #ddd;padding-left:8px;background:#fafafa;">
										<?php echo esc_html( $row['value'] !== '' ? $row['value'] : '—' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ── Страница предпросмотра ────────────────────────────── */

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
		$sync_on = get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) === '1';
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
							$label = self::format_preview_order_label( $o );
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

	/* ── Страница настроек ────────────────────────────────── */

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return;
		}

		$test_notice_html = '';

		if ( isset( $_POST['wc_ms_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce'] ) ), 'wc_ms_save' ) ) {
			self::save_settings();
			echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
		}

		if ( isset( $_POST['wc_ms_test_action'] ) && isset( $_POST['wc_ms_nonce'] ) ) {
			$nonce_ok = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce'] ) ), 'wc_ms_save' );
			if ( $nonce_ok ) {
				$action = sanitize_text_field( wp_unslash( $_POST['wc_ms_test_action'] ) );
				$res    = WC_MoySklad_Sync::run_test_action( $action );
				if ( is_wp_error( $res ) ) {
					$test_notice_html = '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
				} else {
					$test_notice_html = '<div class="notice notice-success"><p>' . esc_html( (string) $res ) . '</p></div>';
				}
			}
		}

		if ( isset( $_POST['wc_ms_webhook_action'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce_wh'] ?? '' ) ), 'wc_ms_webhook' ) ) {
			WC_MS_Webhook::handle_action( sanitize_text_field( wp_unslash( $_POST['wc_ms_webhook_action'] ) ) );
		}

		$enabled      = get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' );
		$login        = get_option( WC_MoySklad_Sync::OPT_LOGIN, '' );
		$password     = get_option( WC_MoySklad_Sync::OPT_PASSWORD, '' );
		$org_id       = get_option( WC_MoySklad_Sync::OPT_ORG_ID, '' );
		$store_id     = get_option( WC_MoySklad_Sync::OPT_STORE_ID, '' );
		$send_on      = get_option( WC_MoySklad_Sync::OPT_SEND_ON_STATUS, 'wc-processing' );
		$state_id     = get_option( WC_MoySklad_Sync::OPT_MS_STATE_ID, '' );
		$default_prod = get_option( WC_MoySklad_Sync::OPT_DEFAULT_PROD, '' );
		$debug        = get_option( WC_MoySklad_Sync::OPT_DEBUG, '0' );
		$add_shipping         = get_option( WC_MoySklad_Sync::OPT_ADD_SHIPPING, '0' );
		$shipping_service_id  = get_option( WC_MoySklad_Sync::OPT_SHIPPING_SERVICE_ID, '' );
		$shipping_markup      = get_option( WC_MoySklad_Sync::OPT_SHIPPING_MARKUP, '22' );
		$sales_channel_id     = get_option( WC_MoySklad_Sync::OPT_SALES_CHANNEL_ID, '' );
		$reserve_on_create = get_option( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE, '0' );
		$order_applicable  = get_option( WC_MoySklad_Sync::OPT_ORDER_APPLICABLE, '0' );
		$note_label   = get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' );
		$order_name_tpl = get_option( WC_MoySklad_Sync::OPT_ORDER_NAME_TPL, '' );
		$agent_mode   = get_option( WC_MoySklad_Sync::OPT_AGENT_MODE, 'per_customer' );
		$fixed_cp_id  = get_option( WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID, '' );
		$agent_style  = get_option( WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE, 'first_last' );
		$desc_tpl     = get_option( WC_MoySklad_Sync::OPT_DESCRIPTION_TPL, '' );
		$status_map   = get_option( WC_MoySklad_Sync::OPT_STATUS_MAP, array() );
		$wh_active    = get_option( WC_MoySklad_Sync::OPT_WEBHOOK_ACTIVE, '0' );
		$wh_id        = get_option( WC_MoySklad_Sync::OPT_WEBHOOK_ID, '' );
		$sync_trigger = get_option( WC_MoySklad_Sync::OPT_SYNC_TRIGGER, 'on_status' );
		$sync_cron_sched = get_option( WC_MoySklad_Sync::OPT_SYNC_CRON_SCHED, 'hourly' );
		$next_cron_ts = wp_next_scheduled( WC_MoySklad_Sync::CRON_HOOK );

		$wc_statuses = wc_get_order_statuses();
		$webhook_url = rest_url( 'moysklad/v1/webhook' );
		$preview_url = admin_url( 'admin.php?page=wc-moysklad-preview' );

		// Список всех плейсхолдеров для подсказки в UI
		$all_tokens = array_keys( WC_MS_Tokens::registry() );
		$tokens_hint = implode( ', ', array_map( function ( $t ) {
			return '<code>' . esc_html( $t ) . '</code>';
		}, $all_tokens ) );
		?>
		<div class="wrap wc-ms-app wc-ms-settings">
			<h1 class="screen-reader-text">МойСклад</h1>
			<div class="wc-ms-title">
				<span>МойСклад × WooCommerce</span>
				<span class="wc-ms-title-actions">
					<a href="<?php echo esc_url( $preview_url ); ?>" class="button">Предпросмотр заказа</a>
					<?php if ( get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) === '1' ) : ?>
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

				<!-- Подключение -->
				<div class="wc-ms-panel is-active" id="wc-ms-panel-connect" data-wc-ms-panel="connect">
				<div class="wc-ms-card">
				<h3>Подключение к API МойСклад</h3>
				<p class="wc-ms-card-desc">Логин и пароль от учётной записи МойСклад. Кнопка «Проверить» только проверяет связь, настройки не пишет.</p>
				<table class="form-table">
					<tr>
						<th scope="row">Включить синхронизацию</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> /> Передавать заказы из WooCommerce в МойСклад</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_login">Логин</label></th>
						<td>
							<input type="text" id="ms_login" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_LOGIN ); ?>" value="<?php echo esc_attr( $login ); ?>" class="regular-text" autocomplete="username" />
							<p class="description">Обычно e-mail учётной записи МойСклад.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_password">Пароль</label></th>
						<td>
							<input type="password" id="ms_password" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_PASSWORD ); ?>" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••' : ''; ?>" autocomplete="new-password" />
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

				<!-- Компания -->
				<div class="wc-ms-panel" id="wc-ms-panel-company" data-wc-ms-panel="company">
				<div class="wc-ms-card">
				<h3>Организация и склад</h3>
				<p class="wc-ms-card-desc">Загрузите списки из МойСклад, затем выберите значения. Склад обязателен, если позже включите резерв (вкладка «Учёт»).</p>
				<p class="wc-ms-toolbar"><button type="button" id="wc_ms_load_btn" class="button button-primary">Загрузить из МойСклад</button> <span id="wc_ms_load_result" class="description"></span></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ms_org">Организация</label></th>
						<td>
							<select id="ms_org" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_ORG_ID ); ?>" class="regular-text">
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
							<select id="ms_store" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_STORE_ID ); ?>" class="regular-text">
								<option value="">— Не указывать в заказе —</option>
								<?php if ( $store_id ) : ?>
									<option value="<?php echo esc_attr( $store_id ); ?>" selected><?php echo esc_html( $store_id ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description">Обязателен при резерве (вкладка «Учёт»).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_sales_channel">Канал продаж</label></th>
						<td>
							<input type="text" id="ms_sales_channel" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SALES_CHANNEL_ID ); ?>" value="<?php echo esc_attr( $sales_channel_id ); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
							<p class="description">UUID канала продаж в МойСклад (Настройки → Каналы продаж). Оставьте пустым, чтобы не указывать.</p>
						</td>
					</tr>
				</table>
				</div></div>

				<!-- Выгрузка -->
				<div class="wc-ms-panel" id="wc-ms-panel-sync" data-wc-ms-panel="sync">
				<div class="wc-ms-card">
				<h3>Когда создавать заказ в МойСклад</h3>
				<p class="wc-ms-card-desc">Момент первой отправки заказа в МС. Статусы WooCommerce — с теми подписями, что в списке заказов магазина.</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wc_ms_sync_trigger">Как запускать выгрузку</label></th>
						<td>
							<select id="wc_ms_sync_trigger" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SYNC_TRIGGER ); ?>">
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
							<select id="ms_send_on" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SEND_ON_STATUS ); ?>">
								<?php foreach ( $wc_statuses as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $send_on, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ms_state">С каким статусом создать заказ в МС</label></th>
						<td>
							<select id="ms_state" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_MS_STATE_ID ); ?>" class="regular-text">
								<option value="">— Первый статус в вашем МойСклад —</option>
								<?php if ( $state_id ) : ?>
									<option value="<?php echo esc_attr( $state_id ); ?>" selected><?php echo esc_html( $state_id ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr class="wc-ms-cron-row">
						<th scope="row"><label for="wc_ms_sync_cron_sched">Интервал при «по расписанию»</label></th>
						<td>
							<select id="wc_ms_sync_cron_sched" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SYNC_CRON_SCHED ); ?>">
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
									echo 'Расписание не активно.';
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				</div></div>

				<!-- Товары -->
				<div class="wc-ms-panel" id="wc-ms-panel-catalog" data-wc-ms-panel="catalog">
				<div class="wc-ms-card">
				<h3>Товары и доставка</h3>
				<p class="wc-ms-card-desc">Строки заказа в МС строятся по SKU. Если в МС нет позиции с таким артикулом — подставляется заглушка или строка пропускается.</p>
				<table class="form-table">
					<tr>
						<th><label for="ms_product">Товар-заглушка (UUID)</label></th>
						<td>
							<input type="text" id="ms_product" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_DEFAULT_PROD ); ?>" value="<?php echo esc_attr( $default_prod ); ?>" class="regular-text" />
							<p class="description">Опционально. UUID товара в МС для позиций без совпадения по артикулу (SKU). Пустое поле — позиции без совпадения просто пропускаются.</p>
						</td>
					</tr>
					<tr>
						<th>Доставка в заказе МС</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_ADD_SHIPPING ); ?>" value="1" <?php checked( $add_shipping, '1' ); ?> /> Добавлять отдельной позицией стоимость доставки WooCommerce как <strong>услугу</strong> в МойСклад</label>
							<p><label for="wc_ms_shipping_service">UUID услуги доставки в МС</label><br />
							<input type="text" id="wc_ms_shipping_service" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SHIPPING_SERVICE_ID ); ?>" value="<?php echo esc_attr( $shipping_service_id ); ?>" class="large-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></p>
							<p><label for="wc_ms_shipping_markup">Наценка на доставку, %</label><br />
							<input type="number" id="wc_ms_shipping_markup" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_SHIPPING_MARKUP ); ?>" value="<?php echo esc_attr( $shipping_markup ); ?>" class="small-text" min="0" max="999" step="1" />
							<span class="description">По умолчанию 22%. Стоимость доставки в МС = стоимость из заказа + наценка.</span></p>
						</td>
					</tr>
				</table>
				</div></div>

				<!-- Вид в МС -->
				<div class="wc-ms-panel" id="wc-ms-panel-look" data-wc-ms-panel="look">
				<div class="wc-ms-card">
				<h3>Внешний вид заказа в МойСклад</h3>
				<p class="wc-ms-card-desc">Название в колонке «&#8470;», контрагент, многострочный комментарий (шаблон с плейсхолдерами).</p>
				<table class="form-table">
					<tr>
						<th><label for="wc_ms_agent_mode">Контрагент в заказе</label></th>
						<td>
							<select id="wc_ms_agent_mode" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_AGENT_MODE ); ?>">
								<option value="per_customer" <?php selected( $agent_mode, 'per_customer' ); ?>>По покупателю: поиск в МС по email, затем по телефону; иначе новый контрагент</option>
								<option value="fixed_uuid" <?php selected( $agent_mode, 'fixed_uuid' ); ?>>Один контрагент для всех заказов с сайта (как Ozon/WB)</option>
							</select>
							<p><label for="wc_ms_fixed_cp">UUID контрагента</label><br />
							<input type="text" id="wc_ms_fixed_cp" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID ); ?>" value="<?php echo esc_attr( $fixed_cp_id ); ?>" class="large-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></p>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_order_name_tpl">Название заказа в МС (колонка «&#8470;»)</label></th>
						<td>
							<input type="text" id="wc_ms_order_name_tpl" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_ORDER_NAME_TPL ); ?>" value="<?php echo esc_attr( $order_name_tpl ); ?>" class="large-text" placeholder="WC-{order_number}" />
							<p class="description">Все плейсхолдеры: <?php echo $tokens_hint; ?>. Пустое = <code>WC-{order_number}</code>.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_agent_name_style">Имя нового контрагента</label></th>
						<td>
							<select id="wc_ms_agent_name_style" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE ); ?>">
								<option value="first_last" <?php selected( $agent_style, 'first_last' ); ?>>Имя Фамилия</option>
								<option value="last_first" <?php selected( $agent_style, 'last_first' ); ?>>Фамилия Имя</option>
								<option value="company_or_fio" <?php selected( $agent_style, 'company_or_fio' ); ?>>Компания из заказа, если указана, иначе ФИО</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wc_ms_description_tpl">Комментарий к заказу</label></th>
						<td>
							<textarea id="wc_ms_description_tpl" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_DESCRIPTION_TPL ); ?>" class="large-text" rows="8" placeholder="Оставьте пустым — формат по умолчанию"><?php echo esc_textarea( $desc_tpl ); ?></textarea>
							<p class="description">Пустое = одна строка «<code>{site_host}</code> #… | email | ФИО | телефон». Все плейсхолдеры: <?php echo $tokens_hint; ?></p>
						</td>
					</tr>
				</table>
				</div></div>

				<!-- МС → магазин -->
				<div class="wc-ms-panel" id="wc-ms-panel-reverse" data-wc-ms-panel="reverse">
				<div class="wc-ms-card">
				<h3>Из МойСклад обратно в магазин</h3>
				<p class="wc-ms-card-desc">Когда статус заказа меняется <strong>в МС</strong>, можно автоматически сменить статус <strong>в WooCommerce</strong>.</p>
				<h4 style="margin:12px 0 8px;font-size:13px;">Сопоставление статусов WP ↔ МС</h4>
				<div id="wc_ms_status_map_container">
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

				<!-- Учёт -->
				<div class="wc-ms-panel" id="wc-ms-panel-accounting" data-wc-ms-panel="accounting">
				<div class="wc-ms-card">
				<h3>Учёт в МойСклад</h3>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ms_note">Подпись в примечаниях к заказу</label></th>
						<td><input type="text" id="ms_note" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_NOTE_LABEL ); ?>" value="<?php echo esc_attr( $note_label ); ?>" class="regular-text" placeholder="МойСклад" /></td>
					</tr>
					<tr>
						<th scope="row">Резерв на складе</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE ); ?>" value="1" <?php checked( $reserve_on_create, '1' ); ?> /> Резервировать количество по строкам заказа на выбранном складе</label></td>
					</tr>
					<tr>
						<th scope="row">Проведённый заказ без резерва</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_ORDER_APPLICABLE ); ?>" value="1" <?php checked( $order_applicable, '1' ); ?> /> Сразу проводить заказ покупателя</label></td>
					</tr>
					<tr>
						<th scope="row">Отладка</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WC_MoySklad_Sync::OPT_DEBUG ); ?>" value="1" <?php checked( $debug, '1' ); ?> /> Писать обращения к API в лог WordPress</label></td>
					</tr>
				</table>
				</div>

				<div class="wc-ms-card">
				<h3>Диагностика</h3>
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
			<p style="margin:0 0 8px;"><strong>URL:</strong> <code style="word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code></p>
			<?php
			$wh_secret = get_option( WC_MoySklad_Sync::OPT_WEBHOOK_SECRET, '' );
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
			WC_MoySklad_Sync::OPT_ENABLED      => 'checkbox',
			WC_MoySklad_Sync::OPT_LOGIN        => 'text',
			WC_MoySklad_Sync::OPT_ORG_ID       => 'text',
			WC_MoySklad_Sync::OPT_STORE_ID     => 'text',
			WC_MoySklad_Sync::OPT_SEND_ON_STATUS => 'text',
			WC_MoySklad_Sync::OPT_MS_STATE_ID  => 'text',
			WC_MoySklad_Sync::OPT_DEFAULT_PROD => 'text',
			WC_MoySklad_Sync::OPT_ORDER_NAME_TPL => 'text',
			WC_MoySklad_Sync::OPT_DEBUG        => 'checkbox',
			WC_MoySklad_Sync::OPT_ADD_SHIPPING         => 'checkbox',
			WC_MoySklad_Sync::OPT_SHIPPING_SERVICE_ID  => 'text',
			// OPT_SHIPPING_MARKUP сохраняется отдельно с валидацией ниже
			WC_MoySklad_Sync::OPT_SALES_CHANNEL_ID     => 'text',
			WC_MoySklad_Sync::OPT_RESERVE_ON_CREATE => 'checkbox',
			WC_MoySklad_Sync::OPT_ORDER_APPLICABLE => 'checkbox',
			WC_MoySklad_Sync::OPT_NOTE_LABEL   => 'text',
		);

		foreach ( $fields as $key => $type ) {
			if ( $type === 'checkbox' ) {
				update_option( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
			} else {
				update_option( $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' );
			}
		}

		$shipping_markup_raw = isset( $_POST[ WC_MoySklad_Sync::OPT_SHIPPING_MARKUP ] )
			? (float) sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_SHIPPING_MARKUP ] ) )
			: 22.0;
		update_option( WC_MoySklad_Sync::OPT_SHIPPING_MARKUP, (string) max( 0, min( 999, $shipping_markup_raw ) ) );

		$agent_style = isset( $_POST[ WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE ] ) ? sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE ] ) ) : 'first_last';
		$allowed_ag  = array( 'first_last', 'last_first', 'company_or_fio' );
		update_option( WC_MoySklad_Sync::OPT_AGENT_NAME_STYLE, in_array( $agent_style, $allowed_ag, true ) ? $agent_style : 'first_last' );

		$desc_tpl = isset( $_POST[ WC_MoySklad_Sync::OPT_DESCRIPTION_TPL ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_DESCRIPTION_TPL ] ) ) : '';
		update_option( WC_MoySklad_Sync::OPT_DESCRIPTION_TPL, $desc_tpl );

		$agent_mode = isset( $_POST[ WC_MoySklad_Sync::OPT_AGENT_MODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_AGENT_MODE ] ) ) : 'per_customer';
		if ( ! in_array( $agent_mode, array( 'per_customer', 'fixed_uuid' ), true ) ) {
			$agent_mode = 'per_customer';
		}
		update_option( WC_MoySklad_Sync::OPT_AGENT_MODE, $agent_mode );

		if ( isset( $_POST[ WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID ] ) ) {
			update_option( WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID, sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_FIXED_COUNTERPARTY_ID ] ) ) );
		}

		if ( isset( $_POST[ WC_MoySklad_Sync::OPT_PASSWORD ] ) && $_POST[ WC_MoySklad_Sync::OPT_PASSWORD ] !== '' ) {
			update_option( WC_MoySklad_Sync::OPT_PASSWORD, sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_PASSWORD ] ) ) );
		}

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
		}
		if ( is_array( $map ) ) {
			update_option( WC_MoySklad_Sync::OPT_STATUS_MAP, $map );
		}

		$trigger = isset( $_POST[ WC_MoySklad_Sync::OPT_SYNC_TRIGGER ] ) ? sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_SYNC_TRIGGER ] ) ) : 'on_status';
		$allowed_triggers = array( 'on_status', 'checkout_and_status', 'cron', 'status_and_cron', 'manual' );
		if ( ! in_array( $trigger, $allowed_triggers, true ) ) {
			$trigger = 'on_status';
		}
		update_option( WC_MoySklad_Sync::OPT_SYNC_TRIGGER, $trigger );

		$cron_sched = isset( $_POST[ WC_MoySklad_Sync::OPT_SYNC_CRON_SCHED ] ) ? sanitize_text_field( wp_unslash( $_POST[ WC_MoySklad_Sync::OPT_SYNC_CRON_SCHED ] ) ) : 'hourly';
		$allowed_sched = array( 'wc_ms_15min', 'hourly', 'twicedaily', 'daily' );
		if ( ! in_array( $cron_sched, $allowed_sched, true ) ) {
			$cron_sched = 'hourly';
		}
		update_option( WC_MoySklad_Sync::OPT_SYNC_CRON_SCHED, $cron_sched );

		WC_MoySklad_Sync::reschedule_cron_sync();
	}
}
