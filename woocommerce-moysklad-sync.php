<?php
/**
 * Plugin Name: WooCommerce МойСклад Sync
 * Plugin URI: https://github.com/al-nemirov/woocommerce-moysklad-sync
 * Description: Синхронизация заказов WooCommerce с МойСклад (заказы покупателя, контрагенты, позиции по артикулу).
 * Version: 2.0.0
 * Author: Al Nemirov
 * Author URI: https://github.com/al-nemirov
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License: GPLv2 or later
 * Text Domain: wc-moysklad-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_MS_SYNC_VERSION', '2.0.0' );
define( 'WC_MS_SYNC_FILE', __FILE__ );

add_action( 'plugins_loaded', 'wc_ms_sync_init' );

function wc_ms_sync_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-moysklad-sync.php';
	WC_MoySklad_Sync::init();
}
