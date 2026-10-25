<?php
/**
 * Uninstall cleanup.
 *
 * Subscriptions are stored as WooCommerce orders and are deliberately kept: deleting them would
 * remove customer and payment history. Only the plugin's own options and scheduled actions go.
 *
 * @package LighterWooSubscriptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lws_flush_rewrite_rules' );

global $wpdb;
// Renewal locks are short-lived options named lws_lock_{subscription_id}.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lws\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	// An empty hook with a group cancels every pending action in that group.
	as_unschedule_all_actions( '', array(), 'lighter-woo-subscriptions' );
}
