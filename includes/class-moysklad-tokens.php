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
			'{site_host}'            => 'Домен сайта (пример: shop.ru)',
			'{order_number}'         => 'Номер заказа в магазине (видит покупатель)',
			'{order_id}'             => 'ID заказа в БД (число)',
			'{order_date}'           => 'Дата заказа, формат ДД.ММ.ГГГГ',
			'{order_time}'           => 'Время заказа, формат ЧЧ:ММ',
			'{order_total}'          => 'Итоговая сумма с валютой (пример: 1500.00 RUB)',
			'{order_subtotal}'       => 'Сумма товаров без доставки и налогов',
			'{order_discount}'       => 'Сумма скидки',
			'{order_shipping_total}' => 'Стоимость доставки',
			'{order_tax_total}'      => 'Сумма всех налогов',
			'{order_currency}'       => 'Код валюты (пример: RUB, USD)',
			'{items_count}'          => 'Количество товаров в заказе',

			// Покупатель
			'{first_name}'           => 'Имя покупателя',
			'{last_name}'            => 'Фамилия покупателя',
			'{company}'              => 'Компания (если указана)',
			'{email}'                => 'Email покупателя (полный адрес)',
			'{email_local}'          => 'Часть email до знака @ (пример: из john@example.com → john)',
			'{phone}'                => 'Номер телефона',
			'{customer_id}'          => 'ID пользователя в WordPress',

			// Billing-адрес
			'{billing_address}'      => 'Полный адрес оплаты в одну строку',
			'{billing_city}'         => 'Город оплаты',
			'{billing_state}'        => 'Область/регион оплаты',
			'{billing_postcode}'     => 'Почтовый индекс оплаты',
			'{billing_country}'      => 'Страна оплаты (код, пример: RU)',

			// Shipping-адрес
			'{shipping_address}'     => 'Полный адрес доставки в одну строку',
			'{shipping_address_1}'   => 'Улица/номер дома (доставка)',
			'{shipping_city}'        => 'Город доставки',
			'{shipping_state}'       => 'Область/регион доставки',
			'{shipping_postcode}'    => 'Почтовый индекс доставки',
			'{shipping_country}'     => 'Страна доставки (код)',

			// Доставка и оплата
			'{shipping_method}'      => 'Название способа доставки',
			'{payment_method}'       => 'Код способа оплаты (пример: cod, bank_transfer)',
			'{payment_method_title}' => 'Название способа оплаты для отображения',

			// Прочее
			'{customer_note}'        => 'Примечание покупателя к заказу',
			'{line_items}'           => 'Список товаров (пример: Рубашка ×2, Джинсы ×1)',
		);
	}

	/**
	 * Расширенный реестр с категориями и подробными описаниями.
	 *
	 * @return array Массив [ 'category' => [ '{token}' => 'full_desc', ... ], ... ]
	 */
	public static function registry_detailed() {
		return array(
			'order' => array(
				'{site_host}' => 'Домен вашего магазина. Пример: shop.ru',
				'{order_number}' => 'Номер заказа, видимый покупателю. Пример: #2025-001',
				'{order_id}' => 'Уникальный ID заказа в базе. Пример: 12345',
				'{order_date}' => 'Дата в формате ДД.ММ.ГГГГ. Пример: 15.04.2026',
				'{order_time}' => 'Время в формате ЧЧ:ММ. Пример: 14:35',
				'{order_total}' => 'Финальная сумма с валютой. Пример: 1500.00 RUB',
				'{order_subtotal}' => 'Сумма без доставки и налогов. Пример: 1400.00',
				'{order_discount}' => 'Скидка (если была). Пример: 100.00',
				'{order_shipping_total}' => 'Стоимость доставки. Пример: 200.00',
				'{order_tax_total}' => 'Налог (если есть). Пример: 0.00',
				'{order_currency}' => 'Трёхбуквенный код. Пример: RUB',
				'{items_count}' => 'Общее количество товаров. Пример: 3',
			),
			'customer' => array(
				'{first_name}' => 'Имя из формы оплаты. Пример: Иван',
				'{last_name}' => 'Фамилия из формы оплаты. Пример: Петров',
				'{company}' => 'Организация (необязательное поле). Пример: ООО Рога',
				'{email}' => 'Электронная почта. Пример: ivan@example.com',
				'{email_local}' => 'Только часть до @. Пример: из ivan@example.com → ivan',
				'{phone}' => 'Номер телефона. Пример: +7 (499) 123-45-67',
				'{customer_id}' => 'ID пользователя в WordPress. Пример: 5',
			),
			'billing' => array(
				'{billing_address}' => 'Адрес оплаты одной строкой. Пример: ул. Ленина, 10, кв. 5, Москва, 123456',
				'{billing_city}' => 'Город. Пример: Москва',
				'{billing_state}' => 'Область/штат. Пример: Московская область',
				'{billing_postcode}' => 'Почтовый индекс. Пример: 123456',
				'{billing_country}' => 'Код страны. Пример: RU',
			),
			'shipping' => array(
				'{shipping_address}' => 'Адрес доставки одной строкой',
				'{shipping_address_1}' => 'Улица и номер дома. Пример: ул. Пушкина, 15',
				'{shipping_city}' => 'Город доставки. Пример: Санкт-Петербург',
				'{shipping_state}' => 'Область доставки. Пример: Ленинградская',
				'{shipping_postcode}' => 'Индекс доставки. Пример: 190000',
				'{shipping_country}' => 'Страна доставки. Пример: RU',
			),
			'payment' => array(
				'{shipping_method}' => 'Способ доставки. Пример: Курьер по городу',
				'{payment_method}' => 'ID способа оплаты. Пример: cod, bank_transfer',
				'{payment_method_title}' => 'Название для показа. Пример: Наличные при получении',
			),
			'other' => array(
				'{customer_note}' => 'Заметка от покупателя. Пример: Позвонить перед доставкой',
				'{line_items}' => 'Список товаров с количеством. Пример: Рубашка ×2, Джинсы ×1',
			),
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
	 * Справочник: массив [ [ 'token' => '{...}', 'short_desc' => '...', 'full_desc' => '...', 'value' => '...' ], ... ]
	 * Сгруппировано по категориям для более понятного отображения.
	 *
	 * @param WC_Order $order
	 * @return array Массив с категориями: [ 'category_name' => [ rows ], ... ]
	 */
	public static function reference_table( $order ) {
		$registry_short = self::registry();
		$registry_full  = self::registry_detailed();
		$values         = self::resolve_all( $order );
		$result         = array();

		foreach ( $registry_full as $category => $tokens ) {
			$result[ $category ] = array();
			foreach ( $tokens as $token => $full_desc ) {
				$short_desc = isset( $registry_short[ $token ] ) ? $registry_short[ $token ] : $full_desc;
				$result[ $category ][] = array(
					'token'      => $token,
					'short_desc' => $short_desc,
					'full_desc'  => $full_desc,
					'value'      => isset( $values[ $token ] ) ? $values[ $token ] : '',
				);
			}
		}
		return $result;
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
