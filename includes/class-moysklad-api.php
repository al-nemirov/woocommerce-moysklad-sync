<?php
/**
 * API-клиент МойСклад JSON API 1.2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_API {

	const BASE = 'https://api.moysklad.ru/api/remap/1.2/';

	private $auth;
	private $debug;

	public function __construct( $login, $password, $debug = false ) {
		$this->auth  = base64_encode( $login . ':' . $password );
		$this->debug = $debug;
	}

	/* ── HTTP ───────────────────────────────────────────────── */

	public function get( $endpoint, $query = array() ) {
		$url = self::BASE . $endpoint;
		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}
		return $this->request( 'GET', $url );
	}

	public function post( $endpoint, $body ) {
		return $this->request( 'POST', self::BASE . $endpoint, $body );
	}

	public function put( $endpoint, $body ) {
		return $this->request( 'PUT', self::BASE . $endpoint, $body );
	}

	private function request( $method, $url, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'   => 'Basic ' . $this->auth,
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'Accept-Encoding' => 'gzip',
			),
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		if ( $this->debug ) {
			$this->log( sprintf( 'API %s %s | body=%s', $method, $url, $body ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) : '-' ) );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $this->debug ) {
				$this->log( 'API ERROR: ' . $response->get_error_message() );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $this->debug ) {
			$this->log( sprintf( 'API RESPONSE %s | status=%d', $url, $code ) );
		}

		if ( $code >= 400 ) {
			$msg = isset( $data['errors'][0]['error'] ) ? $data['errors'][0]['error'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'ms_api_' . $code, $msg );
		}

		return $data;
	}

	/* ── Организации ────────────────────────────────────────── */

	public function get_organizations() {
		$data = $this->get( 'entity/organization', array( 'limit' => 100 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['rows'] ) ? $data['rows'] : array();
	}

	/* ── Склады ─────────────────────────────────────────────── */

	public function get_stores() {
		$data = $this->get( 'entity/store', array( 'limit' => 100 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['rows'] ) ? $data['rows'] : array();
	}

	/* ── Статусы заказа покупателя ───────────────────────────── */

	public function get_order_states() {
		$data = $this->get( 'entity/customerorder/metadata' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['states'] ) ? $data['states'] : array();
	}

	/* ── Контрагенты ────────────────────────────────────────── */

	public function find_counterparty_by_email( $email ) {
		$data = $this->get( 'entity/counterparty', array(
			'filter' => 'email=' . $email,
			'limit'  => 1,
		) );
		if ( is_wp_error( $data ) || empty( $data['rows'][0] ) ) {
			return null;
		}
		return $data['rows'][0];
	}

	public function find_counterparty_by_phone( $phone ) {
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		$data  = $this->get( 'entity/counterparty', array(
			'filter' => 'phone=' . $phone,
			'limit'  => 1,
		) );
		if ( is_wp_error( $data ) || empty( $data['rows'][0] ) ) {
			return null;
		}
		return $data['rows'][0];
	}

	public function create_counterparty( $name, $email = '', $phone = '' ) {
		$body = array(
			'name'        => $name,
			'companyType' => 'individual',
		);
		if ( $email ) {
			$body['email'] = $email;
		}
		if ( $phone ) {
			$body['phone'] = $phone;
		}
		return $this->post( 'entity/counterparty', $body );
	}

	/* ── Товары ─────────────────────────────────────────────── */

	public function find_product_by_article( $sku ) {
		$data = $this->get( 'entity/assortment', array(
			'filter' => 'article=' . $sku,
			'limit'  => 1,
		) );
		if ( is_wp_error( $data ) || empty( $data['rows'][0] ) ) {
			return null;
		}
		return $data['rows'][0];
	}

	/* ── Заказы покупателя ──────────────────────────────────── */

	public function create_customer_order( $body ) {
		return $this->post( 'entity/customerorder', $body );
	}

	public function get_customer_order( $id ) {
		return $this->get( 'entity/customerorder/' . $id );
	}

	/**
	 * Обновить Заказ покупателя (customerorder) в МойСклад.
	 *
	 * Используется, чтобы синхронизировать state при изменении статусов в WooCommerce.
	 *
	 * @param string $id
	 * @param array  $body
	 * @return array|WP_Error
	 */
	public function update_customer_order( $id, array $body ) {
		return $this->put( 'entity/customerorder/' . $id, $body );
	}

	/* ── Вебхуки ────────────────────────────────────────────── */

	public function get_webhooks() {
		$data = $this->get( 'entity/webhook', array( 'limit' => 100 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['rows'] ) ? $data['rows'] : array();
	}

	public function create_webhook( $url, $entity_type, $action ) {
		return $this->post( 'entity/webhook', array(
			'url'        => $url,
			'entityType' => $entity_type,
			'action'     => $action,
		) );
	}

	public function delete_webhook( $id ) {
		return $this->request( 'DELETE', self::BASE . 'entity/webhook/' . $id );
	}

	/* ── Проверка подключения ───────────────────────────────── */

	public function test_connection() {
		$data = $this->get( 'entity/organization', array( 'limit' => 1 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return true;
	}

	/* ── Логирование ────────────────────────────────────────── */

	private function log( $message ) {
		error_log( '[WC-MS] ' . $message );
	}
}
