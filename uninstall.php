<?php
/**
 * Fliinow WooCommerce — Uninstall.
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package Fliinow_WooCommerce
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove gateway settings.
delete_option( 'woocommerce_fliinow_settings' );

// Clear scheduled cron event.
wp_clear_scheduled_hook( 'fliinow_check_pending_orders' );
