<?php
/**
 * Входящий webhook МойСклад → WooCommerce.
 *
 * Регистрация endpoint, обработка событий, управление вебхуком.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_Webhook {

	/* ── REST endpoint ────────────────────────────────────── */

	public static function register_endpoint() {
		register_rest_route( 'moysklad/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Обработка входящего события от МойСклад.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$secret = get_option( WC_MoySklad_Sync::OPT_WEBHOOK_SECRET, '' );
		if ( $secret === '' ) {
			return new WP_REST_Response( array( 'error' => 'Webhook secret not configured' ), 403 );
		}
		$provided = (string) $request->get_param( 'secret' );
		if ( $provided === '' || ! hash_equals( (string) $secret, $provided ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid secret' ), 403 );
		}

		$body = $request->get_json_params();
		if ( empty( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$status_map = get_option( WC_MoySklad_Sync::OPT_STATUS_MAP, array() );
		if ( empty( $status_map ) || ! is_array( $status_map ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$api = WC_MoySklad_Sync::api();

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

			$ms_id = basename( $href );

			$orders = wc_get_orders( array(
				'meta_key'   => WC_MoySklad_Sync::ORDER_META_ID,
				'meta_value' => $ms_id,
				'limit'      => 1,
			) );
			if ( empty( $orders ) ) {
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

			$ms_order = $api->get_customer_order( $ms_id );
			if ( is_wp_error( $ms_order ) || ! isset( $ms_order['state']['meta']['href'] ) ) {
				continue;
			}

			$state_href = $ms_order['state']['meta']['href'];
			$state_id   = basename( $state_href );

			if ( isset( $status_map[ $state_id ] ) && $status_map[ $state_id ] !== '' ) {
				$wc_status       = $status_map[ $state_id ];
				$wc_status_clean = ( strpos( $wc_status, 'wc-' ) === 0 ) ? substr( $wc_status, 3 ) : $wc_status;
				if ( $wc_order->get_status() !== $wc_status_clean ) {
					$state_name = isset( $ms_order['state']['name'] ) ? $ms_order['state']['name'] : $state_id;
					$note_label = get_option( WC_MoySklad_Sync::OPT_NOTE_LABEL, 'МойСклад' );
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

	/* ── Управление вебхуком ──────────────────────────────── */

	/**
	 * Создать или удалить вебхук в МойСклад.
	 *
	 * @param string $action 'create' или 'delete'.
	 */
	public static function handle_action( $action ) {
		$api = WC_MoySklad_Sync::api();

		if ( $action === 'create' ) {
			$secret = wp_generate_password( 32, false );
			update_option( WC_MoySklad_Sync::OPT_WEBHOOK_SECRET, $secret );

			$url    = rest_url( 'moysklad/v1/webhook' ) . '?secret=' . $secret;
			$result = $api->create_webhook( $url, 'customerorder', 'UPDATE' );
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>Ошибка: ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$wh_id = isset( $result['id'] ) ? $result['id'] : '';
				update_option( WC_MoySklad_Sync::OPT_WEBHOOK_ACTIVE, '1' );
				update_option( WC_MoySklad_Sync::OPT_WEBHOOK_ID, $wh_id );
				echo '<div class="notice notice-success"><p>Вебхук создан!</p></div>';
			}
		} elseif ( $action === 'delete' ) {
			$wh_id = get_option( WC_MoySklad_Sync::OPT_WEBHOOK_ID, '' );
			if ( $wh_id ) {
				$api->delete_webhook( $wh_id );
			}
			update_option( WC_MoySklad_Sync::OPT_WEBHOOK_ACTIVE, '0' );
			update_option( WC_MoySklad_Sync::OPT_WEBHOOK_ID, '' );
			echo '<div class="notice notice-success"><p>Вебхук удалён.</p></div>';
		}
	}
}
