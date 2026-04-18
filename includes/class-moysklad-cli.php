<?php
/**
 * WP-CLI команды плагина.
 *
 * Использование:
 *   wp wc-ms sync-order <id>   — выгрузить один заказ WC в МойСклад
 *   wp wc-ms sync-pending      — запустить cron-выгрузку заказов с целевым статусом
 *   wp wc-ms test              — проверить подключение
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class WC_MS_CLI {

	/**
	 * Выгрузить один заказ WooCommerce в МойСклад.
	 *
	 * ## OPTIONS
	 *
	 * <order_id>
	 * : ID заказа WooCommerce.
	 *
	 * [--force]
	 * : Пересоздать, если уже выгружен (сбросит meta связки).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-ms sync-order 12345
	 *     wp wc-ms sync-order 12345 --force
	 */
	public function sync_order( $args, $assoc_args ) {
		$oid   = (int) $args[0];
		$force = ! empty( $assoc_args['force'] );

		$order = wc_get_order( $oid );
		if ( ! $order ) {
			WP_CLI::error( 'Заказ не найден: ' . $oid );
		}

		if ( $force ) {
			$order->delete_meta_data( WC_MoySklad_Sync::ORDER_META_ID );
			$order->delete_meta_data( WC_MoySklad_Sync::ORDER_META_ERROR );
			$order->delete_meta_data( WC_MoySklad_Sync::ORDER_META_NAME );
			$order->delete_meta_data( 'yd_moysklad_id' );
			$order->delete_meta_data( 'yd_moysklad_error' );
			$order->save();
		}

		if ( WC_MoySklad_Sync::order_already_synced( $order ) ) {
			WP_CLI::warning( 'Заказ уже в МойСклад. Используйте --force для пересоздания.' );
			return;
		}

		WC_MS_Order::sync_order( $order );

		$order = wc_get_order( $oid );
		$ms_id = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ID );
		if ( $ms_id ) {
			WP_CLI::success( 'Заказ выгружен. customerorder id=' . $ms_id );
			return;
		}
		$err = $order->get_meta( WC_MoySklad_Sync::ORDER_META_ERROR );
		WP_CLI::error( $err ? $err : 'Выгрузка не удалась.' );
	}

	/**
	 * Запустить cron-подобную выгрузку ожидающих заказов.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-ms sync-pending
	 */
	public function sync_pending( $args, $assoc_args ) {
		if ( get_option( WC_MoySklad_Sync::OPT_ENABLED, '0' ) !== '1' ) {
			WP_CLI::warning( 'Синхронизация выключена в настройках (OPT_ENABLED=0), но запускаем принудительно.' );
		}
		WP_CLI::log( 'Запуск выгрузки…' );
		WC_MoySklad_Sync::run_cron_sync();
		WP_CLI::success( 'Готово.' );
	}

	/**
	 * Проверить подключение к API МойСклад.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-ms test
	 */
	public function test( $args, $assoc_args ) {
		$res = WC_MoySklad_Sync::run_test_action( 'connection' );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( (string) $res );
	}
}

WP_CLI::add_command( 'wc-ms', 'WC_MS_CLI' );
