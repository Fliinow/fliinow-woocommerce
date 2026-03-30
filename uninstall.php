<?php
/**
 * Fliinow — Uninstall.
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package Fliinow_Checkout_Financing
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove gateway settings.
delete_option( 'woocommerce_fliinow_settings' );

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'fliinow_check_pending_orders' );
wp_clear_scheduled_hook( 'fliinow_health_monitor' );

// Remove health monitor transient.
delete_transient( 'fliinow_health_failure' );

// Clean order meta (HPOS-compatible via $wpdb).
global $wpdb;

// Legacy post meta (pre-HPOS).
$meta_keys = array( '_fliinow_operation_id', '_fliinow_status', '_fliinow_financing_url' );
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
}

// HPOS order meta (wc_orders_meta table, may not exist).
$hpos_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
	foreach ( $meta_keys as $key ) {
		$wpdb->delete( $hpos_table, array( 'meta_key' => $key ) );
	}
}
