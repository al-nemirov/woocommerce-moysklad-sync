<?php
/**
 * Plugin Name: WooCommerce МойСклад Sync
 * Plugin URI: https://github.com/al-nemirov/woocommerce-moysklad-sync
 * Description: Синхронизация заказов WooCommerce с МойСклад (заказы покупателя, контрагенты, позиции по артикулу).
 * Version: 3.2.0
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

define( 'WC_MS_SYNC_VERSION', '3.2.0' );
define( 'WC_MS_SYNC_FILE', __FILE__ );

register_activation_hook( __FILE__, 'wc_ms_sync_activate' );
register_deactivation_hook( __FILE__, 'wc_ms_sync_deactivate' );

/**
 * Флаг: после следующей загрузки WooCommerce перепланировать cron (WC при активации может быть ещё не подключён).
 */
function wc_ms_sync_activate() {
	update_option( 'wc_ms_pending_activation_reschedule', '1' );
}

/**
 * Снять периодическую выгрузку при отключении плагина.
 */
function wc_ms_sync_deactivate() {
	wp_clear_scheduled_hook( 'wc_ms_cron_sync_orders' );
}

add_action( 'plugins_loaded', 'wc_ms_sync_init' );

function wc_ms_sync_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-moysklad-sync.php';
	WC_MoySklad_Sync::init();
	if ( get_option( 'wc_ms_pending_activation_reschedule', '' ) === '1' ) {
		delete_option( 'wc_ms_pending_activation_reschedule' );
		WC_MoySklad_Sync::on_activate();
	}
}
