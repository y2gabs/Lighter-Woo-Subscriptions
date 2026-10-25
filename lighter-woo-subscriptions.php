<?php
/**
 * Plugin Name:       Lighter Woo Subscriptions
 * Plugin URI:        https://github.com/y2gabs/Lighter-Woo-Subscriptions
 * Description:       Lightweight recurring subscriptions for WooCommerce with automatic Stripe and Square renewals.
 * Version:           1.0.0
 * Author:            y2gabs
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.5
 * WC tested up to:   11.2
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       lighter-woo-subscriptions
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

define( 'LWS_VERSION', '1.0.0' );
define( 'LWS_FILE', __FILE__ );
define( 'LWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWS_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Whether the official WooCommerce Subscriptions plugin is running. Both plugins
 * use the same gateway features and statuses, so they must not run together.
 *
 * @return bool
 */
function lws_official_subscriptions_active() {
	return class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscriptions_Core_Plugin' );
}

register_activation_hook(
	__FILE__,
	function () {
		if ( lws_official_subscriptions_active() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Lighter Woo Subscriptions cannot run alongside WooCommerce Subscriptions. Deactivate WooCommerce Subscriptions first.', 'lighter-woo-subscriptions' ) );
		}
		update_option( 'lws_flush_rewrite_rules', 'yes' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Scheduled renewals are kept: if the plugin is reactivated they resume, and while it is inactive
		// Action Scheduler simply has no callback for them.
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( lws_official_subscriptions_active() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Lighter Woo Subscriptions is inactive because WooCommerce Subscriptions is active. Run only one subscriptions plugin.', 'lighter-woo-subscriptions' ) . '</p></div>';
				}
			);
			return;
		}

		require_once LWS_DIR . 'includes/class-lws-plugin.php';
		LWS_Plugin::instance();
	},
	20
);
