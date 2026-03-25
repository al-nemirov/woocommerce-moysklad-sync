<?php
/**
 * Синхронизация заказов WooCommerce с МойСклад.
 *
 * @package WC_MoySklad_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MoySklad_Sync {

	const API_BASE = 'https://online.moysklad.ru/api/remap/1.2/';

	const OPTION_ENABLED            = 'wc_ms_enabled';
	const OPTION_LOGIN              = 'wc_ms_login';
	const OPTION_PASSWORD           = 'wc_ms_password';
	const OPTION_SEND_ON_STATUS     = 'wc_ms_send_on_status';
	const OPTION_ORGANIZATION_ID    = 'wc_ms_organization_id';
	const OPTION_DEFAULT_PRODUCT_ID = 'wc_ms_default_product_id';

	const ORDER_META_ID    = 'wc_ms_order_id';
	const ORDER_META_ERROR = 'wc_ms_error';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 20, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_meta_box' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'save_order_meta_box_hpos' ), 10, 2 );

		// Миграция со старого плагина (yd_moysklad_*)
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_from_yd' ) );
	}

	// ── Миграция ──────────────────────────────────────────────

	public static function maybe_migrate_from_yd() {
		if ( get_option( 'wc_ms_migrated_from_yd', false ) ) {
			return;
		}
		$old_keys = array(
			'yd_moysklad_enabled'            => self::OPTION_ENABLED,
			'yd_moysklad_login'              => self::OPTION_LOGIN,
			'yd_moysklad_password'           => self::OPTION_PASSWORD,
			'yd_moysklad_send_on_status'     => self::OPTION_SEND_ON_STATUS,
			'yd_moysklad_organization_id'    => self::OPTION_ORGANIZATION_ID,
			'yd_moysklad_default_product_id' => self::OPTION_DEFAULT_PRODUCT_ID,
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

	// ── Мета-бокс в заказе ────────────────────────────────────

	public static function add_order_meta_box( $post_type, $post ) {
		if ( ( $post_type !== 'shop_order' && $post_type !== 'wc-order' ) || get_option( self::OPTION_ENABLED, '0' ) !== '1' ) {
			return;
		}
		add_meta_box(
			'wc_moysklad_sync',
			'МойСклад',
			array( __CLASS__, 'render_order_meta_box' ),
			$post_type,
			'side'
		);
	}

	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		// Совместимость: проверяем и старые и новые мета-ключи
		$ms_id    = $order->get_meta( self::ORDER_META_ID ) ?: $order->get_meta( 'yd_moysklad_id' );
		$ms_error = $order->get_meta( self::ORDER_META_ERROR ) ?: $order->get_meta( 'yd_moysklad_error' );

		if ( $ms_id ) {
			$href = 'https://online.moysklad.ru/app/#customerorder/edit?id=' . $ms_id;
			echo '<p>Заказ передан в МойСклад (черновик).</p>';
			echo '<p><a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">Открыть в МойСклад</a></p>';
			echo '<p><button type="submit" name="wc_ms_resync" class="button" style="color:#999;border-color:#ccc;font-size:11px;" onclick="return confirm(\'Пересоздать заказ в МойСклад?\');">Пересоздать</button></p>';
		} elseif ( $ms_error ) {
			echo '<p><strong>Ошибка:</strong><br>' . esc_html( $ms_error ) . '</p>';
			echo '<p><button type="submit" name="wc_ms_sync" class="button">Отправить в МойСклад</button></p>';
		} else {
			echo '<p>Заказ не отправлен в МойСклад.</p>';
			echo '<p><button type="submit" name="wc_ms_sync" class="button">Отправить в МойСклад</button></p>';
		}
	}

	public static function save_order_meta_box( $order_id, $order = null ) {
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
		// Nonce — CPT или HPOS
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
		// Пересоздание — очищаем старую мету
		if ( isset( $_POST['wc_ms_resync'] ) ) {
			$ord->delete_meta_data( self::ORDER_META_ID );
			$ord->delete_meta_data( self::ORDER_META_ERROR );
			$ord->delete_meta_data( 'yd_moysklad_id' );
			$ord->delete_meta_data( 'yd_moysklad_error' );
			$ord->save();
		}
		self::sync_order( $ord );
	}

	public static function save_order_meta_box_hpos( $order_id, $order = null ) {
		self::save_order_meta_box( $order_id, $order );
	}

	// ── Настройки ─────────────────────────────────────────────

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			'МойСклад',
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

		// Сохранение
		if ( isset( $_POST['wc_ms_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_ms_nonce'] ) ), 'wc_ms_save' ) ) {
			update_option( self::OPTION_ENABLED, isset( $_POST[ self::OPTION_ENABLED ] ) ? '1' : '0' );
			update_option( self::OPTION_LOGIN, isset( $_POST[ self::OPTION_LOGIN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_LOGIN ] ) ) : '' );
			if ( isset( $_POST[ self::OPTION_PASSWORD ] ) && $_POST[ self::OPTION_PASSWORD ] !== '' ) {
				update_option( self::OPTION_PASSWORD, sanitize_text_field( wp_unslash( $_POST[ self::OPTION_PASSWORD ] ) ) );
			}
			update_option( self::OPTION_SEND_ON_STATUS, isset( $_POST[ self::OPTION_SEND_ON_STATUS ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_SEND_ON_STATUS ] ) ) : 'wc-processing' );
			update_option( self::OPTION_ORGANIZATION_ID, isset( $_POST[ self::OPTION_ORGANIZATION_ID ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_ORGANIZATION_ID ] ) ) : '' );
			update_option( self::OPTION_DEFAULT_PRODUCT_ID, isset( $_POST[ self::OPTION_DEFAULT_PRODUCT_ID ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_DEFAULT_PRODUCT_ID ] ) ) : '' );
			echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
		}

		$enabled         = get_option( self::OPTION_ENABLED, '0' );
		$login           = get_option( self::OPTION_LOGIN, '' );
		$password         = get_option( self::OPTION_PASSWORD, '' );
		$send_on         = get_option( self::OPTION_SEND_ON_STATUS, 'wc-processing' );
		$org_id          = get_option( self::OPTION_ORGANIZATION_ID, '' );
		$default_product = get_option( self::OPTION_DEFAULT_PRODUCT_ID, '' );
		?>
		<div class="wrap">
			<h1>МойСклад — Синхронизация заказов</h1>
			<p>Заказы WooCommerce автоматически передаются в МойСклад как черновики заказов покупателя.</p>
			<form method="post" style="max-width:700px;">
				<?php wp_nonce_field( 'wc_ms_save', 'wc_ms_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th>Включить</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> /> Синхронизировать заказы</label></td>
					</tr>
					<tr>
						<th><label for="ms_login">Логин (email)</label></th>
						<td><input type="email" id="ms_login" name="<?php echo esc_attr( self::OPTION_LOGIN ); ?>" value="<?php echo esc_attr( $login ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ms_password">Пароль</label></th>
						<td><input type="password" id="ms_password" name="<?php echo esc_attr( self::OPTION_PASSWORD ); ?>" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••' : ''; ?>" autocomplete="new-password" /><br><small>Оставьте пустым, чтобы не менять.</small></td>
					</tr>
					<tr>
						<th><label for="ms_send_on">Отправлять при статусе</label></th>
						<td>
							<select id="ms_send_on" name="<?php echo esc_attr( self::OPTION_SEND_ON_STATUS ); ?>">
								<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $send_on, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ms_org">ID организации (UUID)</label></th>
						<td><input type="text" id="ms_org" name="<?php echo esc_attr( self::OPTION_ORGANIZATION_ID ); ?>" value="<?php echo esc_attr( $org_id ); ?>" class="regular-text" placeholder="Необязательно — будет взята первая" /></td>
					</tr>
					<tr>
						<th><label for="ms_product">Товар по умолчанию (UUID)</label></th>
						<td><input type="text" id="ms_product" name="<?php echo esc_attr( self::OPTION_DEFAULT_PRODUCT_ID ); ?>" value="<?php echo esc_attr( $default_product ); ?>" class="regular-text" placeholder="Для позиций без совпадения по артикулу" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button-primary" value="Сохранить" /></p>
			</form>
		</div>
		<?php
	}

	// ── Автоотправка по статусу ───────────────────────────────

	public static function on_order_status_changed( $order_id, $old_status, $new_status ) {
		if ( get_option( self::OPTION_ENABLED, '0' ) !== '1' ) {
			return;
		}
		$send_on = get_option( self::OPTION_SEND_ON_STATUS, 'wc-processing' );
		$send_on_clean = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		if ( $new_status !== $send_on && $new_status !== $send_on_clean ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Уже отправлен (проверяем оба ключа)
		if ( $order->get_meta( self::ORDER_META_ID ) || $order->get_meta( 'yd_moysklad_id' ) ) {
			return;
		}
		self::sync_order( $order );
	}

	// ── Синхронизация ─────────────────────────────────────────

	public static function sync_order( $order ) {
		$login    = get_option( self::OPTION_LOGIN, '' );
		$password = get_option( self::OPTION_PASSWORD, '' );
		if ( empty( $login ) || empty( $password ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, 'Не заданы логин или пароль МойСклад.' );
			$order->save();
			return new WP_Error( 'ms_config', 'Не заданы логин или пароль МойСклад.' );
		}

		$auth    = base64_encode( $login . ':' . $password );
		$headers = array(
			'Authorization' => 'Basic ' . $auth,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		$org_meta = self::get_organization_meta( $headers );
		if ( is_wp_error( $org_meta ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $org_meta->get_error_message() );
			$order->save();
			return $org_meta;
		}

		$agent_meta = self::get_or_create_counterparty( $order, $headers );
		if ( is_wp_error( $agent_meta ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $agent_meta->get_error_message() );
			$order->save();
			return $agent_meta;
		}

		$positions = self::build_positions( $order, $headers );
		if ( is_wp_error( $positions ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $positions->get_error_message() );
			$order->save();
			return $positions;
		}

		$order_number = $order->get_order_number();
		$body = array(
			'name'         => (string) $order_number,
			'applicable'   => false,
			'agent'        => array( 'meta' => $agent_meta ),
			'organization' => array( 'meta' => $org_meta ),
			'description'  => sprintf( 'WooCommerce #%s | %s', $order_number, $order->get_edit_order_url() ),
			'positions'    => $positions,
		);

		$response = wp_remote_post(
			self::API_BASE . 'entity/customerorder',
			array( 'timeout' => 30, 'headers' => $headers, 'body' => wp_json_encode( $body ) )
		);

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $response->get_error_message() );
			$order->save();
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$body_json = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_json, true );

		if ( $code >= 400 ) {
			$msg = isset( $data['errors'][0]['error'] ) ? $data['errors'][0]['error'] : $body_json;
			$order->update_meta_data( self::ORDER_META_ERROR, $msg );
			$order->delete_meta_data( self::ORDER_META_ID );
			$order->save();
			return new WP_Error( 'ms_api', $msg );
		}

		$id = isset( $data['id'] ) ? $data['id'] : null;
		if ( $id ) {
			$order->update_meta_data( self::ORDER_META_ID, $id );
			$order->delete_meta_data( self::ORDER_META_ERROR );
		}
		$order->save();
		return $data;
	}

	// ── API-хелперы ───────────────────────────────────────────

	private static function get_organization_meta( $headers ) {
		$org_id = get_option( self::OPTION_ORGANIZATION_ID, '' );
		if ( $org_id !== '' ) {
			return array(
				'href'      => self::API_BASE . 'entity/organization/' . $org_id,
				'type'      => 'organization',
				'mediaType' => 'application/json',
			);
		}
		$response = wp_remote_get( self::API_BASE . 'entity/organization?limit=1', array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$rows = isset( $data['rows'] ) ? $data['rows'] : array();
		if ( empty( $rows[0]['meta'] ) ) {
			return new WP_Error( 'ms_org', 'В МойСклад не найдена ни одна организация.' );
		}
		return $rows[0]['meta'];
	}

	private static function get_or_create_counterparty( $order, $headers ) {
		$email = $order->get_billing_email();
		if ( $email ) {
			$url      = self::API_BASE . 'entity/counterparty?filter=email=' . rawurlencode( $email ) . '&limit=1';
			$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $data['rows'][0]['meta'] ) ) {
					return $data['rows'][0]['meta'];
				}
			}
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( $name === '' ) {
			$name = $order->get_billing_company() ?: 'Покупатель';
		}
		$response = wp_remote_post(
			self::API_BASE . 'entity/counterparty',
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( array(
					'name'  => $name,
					'email' => $order->get_billing_email() ?: '',
					'phone' => $order->get_billing_phone() ?: '',
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || empty( $data['meta'] ) ) {
			return new WP_Error( 'ms_counterparty', isset( $data['errors'][0]['error'] ) ? $data['errors'][0]['error'] : 'Не удалось создать контрагента.' );
		}
		return $data['meta'];
	}

	private static function price_to_cents( $price ) {
		return (int) round( (float) $price * 100 );
	}

	private static function build_positions( $order, $headers ) {
		$positions          = array();
		$default_product_id = get_option( self::OPTION_DEFAULT_PRODUCT_ID, '' );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();
			if ( $quantity < 1 ) {
				continue;
			}
			$price = self::price_to_cents( (float) $item->get_subtotal() / $quantity + (float) $item->get_subtotal_tax() / $quantity );

			$meta = null;
			if ( $product ) {
				$sku = $product->get_sku();
				if ( $sku !== '' ) {
					$meta = self::find_product_meta_by_sku( $sku, $headers );
				}
			}
			if ( ! $meta && $default_product_id !== '' ) {
				$meta = array(
					'href'      => self::API_BASE . 'entity/product/' . $default_product_id,
					'type'      => 'product',
					'mediaType' => 'application/json',
				);
			}
			if ( ! $meta ) {
				continue;
			}
			$positions[] = array(
				'quantity'   => $quantity,
				'price'      => $price,
				'assortment' => array( 'meta' => $meta ),
			);
		}

		if ( empty( $positions ) ) {
			return new WP_Error( 'ms_positions', 'Не удалось сопоставить позиции с товарами МойСклад.' );
		}
		return $positions;
	}

	private static function find_product_meta_by_sku( $sku, $headers ) {
		$url      = self::API_BASE . 'entity/product?filter=article=' . rawurlencode( $sku ) . '&limit=1';
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['rows'][0]['meta'] ) ) {
			return $data['rows'][0]['meta'];
		}
		return null;
	}
}
