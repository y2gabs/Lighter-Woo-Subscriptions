<?php
/**
 * Plugin loader.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads files and wires every component.
 */
final class LWS_Plugin {

	/**
	 * Singleton.
	 *
	 * @var LWS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton.
	 *
	 * @return LWS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();

		LWS_Order_Type::init();
		LWS_Product::init();
		LWS_Cart::init();
		LWS_Checkout::init();
		LWS_Scheduler::init();
		LWS_Renewal::init();
		LWS_Gateways::init();
		LWS_My_Account::init();
		LWS_Emails::init();
		LWS_Blocks::init();

		if ( is_admin() ) {
			LWS_Admin::init();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_run_activation_tasks' ), 20 );
	}

	/**
	 * Requires all class files.
	 */
	private function includes() {
		$dir = LWS_DIR . 'includes/';

		require_once $dir . 'lws-functions.php';
		require_once $dir . 'class-lws-subscription.php';
		require_once $dir . 'class-lws-order-type.php';
		require_once $dir . 'class-lws-product.php';
		require_once $dir . 'class-lws-cart.php';
		require_once $dir . 'class-lws-checkout.php';
		require_once $dir . 'class-lws-scheduler.php';
		require_once $dir . 'class-lws-renewal.php';
		require_once $dir . 'gateways/interface-lws-gateway-adapter.php';
		require_once $dir . 'gateways/class-lws-gateway-stripe.php';
		require_once $dir . 'gateways/class-lws-gateway-square.php';
		require_once $dir . 'gateways/class-lws-gateway-manual.php';
		require_once $dir . 'class-lws-gateways.php';
		require_once $dir . 'class-lws-my-account.php';
		require_once $dir . 'class-lws-emails.php';
		require_once $dir . 'class-lws-blocks.php';
		require_once $dir . 'class-lws-admin.php';
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'lighter-woo-subscriptions', false, dirname( plugin_basename( LWS_FILE ) ) . '/languages' );
	}

	/**
	 * Runs one-off tasks queued by the activation hook, once WooCommerce and our endpoints are registered.
	 */
	public function maybe_run_activation_tasks() {
		if ( 'yes' !== get_option( 'lws_flush_rewrite_rules' ) ) {
			return;
		}
		delete_option( 'lws_flush_rewrite_rules' );
		flush_rewrite_rules();
		LWS_Scheduler::reschedule_missing();
	}
}
