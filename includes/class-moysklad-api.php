<?php
/**
 * API-клиент МойСклад JSON API 1.2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_MS_API {

	const BASE = 'https://api.moysklad.ru/api/remap/1.2/';

	private $auth_header;
	private $debug;

	/**
	 * @param string $login    Логин МС (email) или пустая строка при использовании токена.
	 * @param string $password Пароль МС или Bearer-токен (если $login пуст).
	 * @param bool   $debug
	 */
	public function __construct( $login, $password, $debug = false ) {
		if ( $login !== '' ) {
			$this->auth_header = 'Basic ' . base64_encode( $login . ':' . $password );
		} else {
			// Bearer token: $password содержит токен.
			$this->auth_header = 'Bearer ' . $password;
		}
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
		return $this->request( 'POST', self::BASE . $endpoint, $body, array(), 0 );
	}

	/**
	 * PUT-запрос. $disable_webhooks = true добавляет заголовок X-Lognex-WebHook-Disable
	 * чтобы МС не стрелял вебхуком обратно (защита от цикла WC→MS→webhook→WC).
	 */
	public function put( $endpoint, $body, $disable_webhooks = false ) {
		$extra_headers = array();
		if ( $disable_webhooks ) {
			$extra_headers['X-Lognex-WebHook-Disable'] = 'true';
		}
		return $this->request( 'PUT', self::BASE . $endpoint, $body, $extra_headers, 0 );
	}

	/**
	 * @param array $extra_headers
	 * @param int   $retry_429_attempt Номер повтора после 429 (внутренний счётчик).
	 */
	private function request( $method, $url, $body = null, $extra_headers = array(), $retry_429_attempt = 0 ) {
		// Content-Type только при теле: GET/DELETE с application/json дают 400 у JSON API МойСклад.
		$headers = array(
			'Authorization' => $this->auth_header,
			'Accept'        => 'application/json;charset=utf-8',
			// gzip через стек WP часто ломает Accept (1062) — для cURL см. CURLOPT_ENCODING ниже.
			'Accept-Encoding' => 'gzip',
		);
		if ( $body !== null ) {
			$headers['Content-Type'] = 'application/json;charset=utf-8';
		}
		if ( $extra_headers ) {
			$headers = array_merge( $headers, $extra_headers );
		}

		if ( $this->debug ) {
			$this->log( sprintf( 'API %s %s | body=%s', $method, $url, $body ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) : '-' ) );
		}

		// Обход WP HTTP API: иначе ядро/плагины подмешивают Accept и МойСклад отвечает 1062.
		if ( function_exists( 'curl_init' ) ) {
			return $this->request_via_curl( $method, $url, $body, $headers, $extra_headers, $retry_429_attempt );
		}

		return $this->request_via_wp_http( $method, $url, $body, $headers, $extra_headers, $retry_429_attempt );
	}

	/**
	 * HTTP через cURL — ровно те заголовки, что в массиве (без неявного Accept от WordPress).
	 *
	 * @param string $method HTTP-метод.
	 * @param string $url    Полный URL.
	 * @param array|null $body Тело (для POST/PUT).
	 * @param array $headers_assoc Заголовки имя => значение.
	 * @param array $extra_headers Исходные доп. заголовки (для рекурсии при 429).
	 * @param int   $retry_429_attempt
	 * @return array|WP_Error
	 */
	private function request_via_curl( $method, $url, $body, array $headers_assoc, array $extra_headers, $retry_429_attempt = 0 ) {
		$method = strtoupper( $method );
		$header_lines = array();
		foreach ( $headers_assoc as $name => $value ) {
			if ( strtolower( (string) $name ) === 'accept-encoding' ) {
				continue;
			}
			$header_lines[] = $name . ': ' . $value;
		}

		$payload = null;
		if ( $body !== null ) {
			$payload = wp_json_encode( $body );
		}

		$retry_after_429 = null;
		$ch              = curl_init( $url );
		if ( ! $ch ) {
			return new WP_Error( 'ms_curl_init', 'Не удалось инициализировать cURL.' );
		}

		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $header_lines,
			CURLOPT_ENCODING       => '',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$retry_after_429 ) {
				if ( preg_match( '/^X-Lognex-Retry-After:\s*(\d+)/i', $line, $m ) ) {
					$retry_after_429 = (int) $m[1];
				}
				return strlen( $line );
			},
		);
		if ( $payload !== null ) {
			$opts[ CURLOPT_POSTFIELDS ] = $payload;
		}
		curl_setopt_array( $ch, $opts );

		$raw_body = curl_exec( $ch );
		$curl_err = curl_error( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( $raw_body === false ) {
			if ( $this->debug ) {
				$this->log( 'API ERROR (cURL): ' . $curl_err );
			}
			self::append_debug_file( sprintf( 'TRANSPORT cURL | %s %s | %s', $method, $url, $curl_err ) );
			return new WP_Error( 'ms_curl', $curl_err ? $curl_err : 'Ошибка cURL' );
		}

		return $this->handle_http_response( $method, $url, $body, $extra_headers, $code, (string) $raw_body, $retry_after_429, $retry_429_attempt );
	}

	/**
	 * Запасной путь без расширения cURL.
	 *
	 * @param array $extra_headers Для рекурсии при 429.
	 * @param int $retry_429_attempt
	 * @return array|WP_Error
	 */
	private function request_via_wp_http( $method, $url, $body, array $headers, array $extra_headers, $retry_429_attempt = 0 ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$em = $response->get_error_message();
			if ( $this->debug ) {
				$this->log( 'API ERROR: ' . $em );
			}
			self::append_debug_file( sprintf( 'TRANSPORT | %s %s | %s', $method, $url, $em ) );
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$retry    = null;
		if ( $code === 429 ) {
			$h = wp_remote_retrieve_header( $response, 'X-Lognex-Retry-After' );
			$retry = is_array( $h ) ? reset( $h ) : $h;
		}

		return $this->handle_http_response( $method, $url, $body, $extra_headers, $code, $raw_body, $retry, $retry_429_attempt );
	}

	/**
	 * Разбор ответа, 429, ошибки API.
	 *
	 * @param string     $method
	 * @param string     $url
	 * @param array|null $body
	 * @param array      $extra_headers
	 * @param int        $code
	 * @param string     $raw_body
	 * @param string|int|null $retry_after_header
	 * @param int               $retry_429_attempt Уже сделано повторов после 429.
	 * @return array|WP_Error
	 */
	private function handle_http_response( $method, $url, $body, array $extra_headers, $code, $raw_body, $retry_after_header = null, $retry_429_attempt = 0 ) {
		if ( $this->debug ) {
			$this->log( sprintf( 'API RESPONSE %s | status=%d', $url, $code ) );
		}

		if ( $code === 429 ) {
			if ( $retry_429_attempt >= 2 ) {
				self::append_debug_file( sprintf( 'HTTP 429 limit | %s %s', $method, $url ) );
				return new WP_Error( 'ms_api_429', 'МойСклад: превышен лимит запросов (429). Повторите позже.' );
			}
			// X-Lognex-Retry-After возвращается в миллисекундах — конвертируем в секунды.
			$retry_ms = $retry_after_header !== null && $retry_after_header !== '' ? (int) $retry_after_header : 0;
			if ( $retry_ms > 0 ) {
				$retry_sec = (int) ceil( $retry_ms / 1000 );
			} else {
				$retry_sec = 3;
			}
			$retry_sec = max( 1, min( $retry_sec, 10 ) );
			if ( $this->debug ) {
				$this->log( sprintf( 'Rate limited (header=%s ms), retry %d in %d sec', (string) $retry_after_header, $retry_429_attempt + 1, $retry_sec ) );
			}
			sleep( $retry_sec );
			return $this->request( $method, $url, $body, $extra_headers, $retry_429_attempt + 1 );
		}

		// 200/201 с телом — JSON; 204 No Content (DELETE) — пустое тело.
		if ( $code === 204 || $raw_body === '' ) {
			if ( $code >= 400 ) {
				self::append_debug_file( sprintf( 'HTTP %d | %s %s | (empty body)', $code, $method, $url ) );
				return new WP_Error( 'ms_api_' . $code, 'HTTP ' . $code );
			}
			return array( 'ok' => true );
		}

		$data = json_decode( $raw_body, true );

		if ( $code >= 400 ) {
			$msg = self::format_ms_api_error( $code, $data, $raw_body );
			self::append_debug_file( sprintf( 'HTTP %d | %s %s | %s', $code, $method, $url, $msg ) );
			return new WP_Error( 'ms_api_' . $code, $msg );
		}

		return $data;
	}

	/**
	 * Текст ошибки из тела ответа МойСклад (errors[] / сырой JSON).
	 *
	 * @param int         $code HTTP-код.
	 * @param mixed       $data Результат json_decode.
	 * @param string      $raw_body Сырое тело ответа.
	 */
	private static function format_ms_api_error( $code, $data, $raw_body ) {
		if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$parts = array();
			foreach ( $data['errors'] as $err ) {
				if ( ! is_array( $err ) ) {
					continue;
				}
				$line = '';
				if ( isset( $err['error'] ) ) {
					$line .= is_string( $err['error'] ) ? $err['error'] : wp_json_encode( $err['error'], JSON_UNESCAPED_UNICODE );
				}
				if ( isset( $err['code'] ) && $err['code'] !== '' ) {
					$line .= $line !== '' ? ' (код ' . $err['code'] . ')' : (string) $err['code'];
				}
				if ( ! empty( $err['moreInfo'] ) && is_string( $err['moreInfo'] ) ) {
					$line .= $line !== '' ? ' — ' . $err['moreInfo'] : $err['moreInfo'];
				}
				if ( $line !== '' ) {
					$parts[] = $line;
				}
			}
			if ( $parts ) {
				return implode( '; ', $parts );
			}
		}
		$raw = is_string( $raw_body ) ? trim( $raw_body ) : '';
		if ( $raw !== '' ) {
			$utf8 = wp_check_invalid_utf8( $raw, true );
			if ( strlen( $utf8 ) > 2000 ) {
				return substr( $utf8, 0, 2000 ) . '…';
			}
			return $utf8;
		}
		return 'HTTP ' . (int) $code;
	}

	/**
	 * Отдельный лог в wp-content/wc-moysklad-sync-debug.log (права на запись у веб-сервера).
	 *
	 * @param string $message Строка без секретов (без Authorization).
	 */
	private static function append_debug_file( $message ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) || WP_CONTENT_DIR === '' ) {
			return;
		}
		$path = WP_CONTENT_DIR . '/wc-moysklad-sync-debug.log';
		$line = gmdate( 'Y-m-d H:i:s' ) . ' [WC-MS] ' . $message . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( wp_is_writable( dirname( $path ) ) ) {
			file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		}
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
			'filter' => 'email=' . (string) $email,
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
			'filter' => 'phone=' . (string) $phone,
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
			'filter' => 'article=' . (string) $sku,
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
	public function update_customer_order( $id, array $body, $disable_webhooks = true ) {
		return $this->put( 'entity/customerorder/' . $id, $body, $disable_webhooks );
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
		return $this->request( 'DELETE', self::BASE . 'entity/webhook/' . $id, null, array(), 0 );
	}

	/**
	 * Универсальный DELETE с возможностью отключить вебхук.
	 *
	 * @param string $endpoint
	 * @param bool   $disable_webhooks
	 * @return array|WP_Error
	 */
	public function delete( $endpoint, $disable_webhooks = false ) {
		$extra = array();
		if ( $disable_webhooks ) {
			$extra['X-Lognex-WebHook-Disable'] = 'true';
		}
		return $this->request( 'DELETE', self::BASE . $endpoint, null, $extra, 0 );
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

	/**
	 * Фильтр http_request_args: для api.moysklad.ru принудительно выставляет Accept (код 1062).
	 * Удаляет дубликаты Accept с другим регистром ключа — плагины могли подставить Accept: * / * или application/json без charset.
	 *
	 * @param array  $args Аргументы wp_remote_*.
	 * @param string $url  URL запроса.
	 * @return array
	 */
	public static function enforce_moysklad_headers( $args, $url ) {
		if ( strpos( (string) $url, 'api.moysklad.ru' ) === false ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		foreach ( array_keys( $args['headers'] ) as $hk ) {
			if ( strtolower( (string) $hk ) === 'accept' ) {
				unset( $args['headers'][ $hk ] );
			}
		}
		$args['headers']['Accept'] = 'application/json;charset=utf-8';

		if ( ! empty( $args['body'] ) ) {
			foreach ( array_keys( $args['headers'] ) as $hk ) {
				if ( strtolower( (string) $hk ) === 'content-type' ) {
					unset( $args['headers'][ $hk ] );
				}
			}
			$args['headers']['Content-Type'] = 'application/json;charset=utf-8';
		}

		return $args;
	}
}
