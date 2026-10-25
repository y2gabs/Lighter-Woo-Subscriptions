<?php
/**
 * Blocks integration (script registration).
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Loads assets/js/blocks.js in the Cart and Checkout blocks.
 */
class LWS_Blocks_Integration implements IntegrationInterface {

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'lighter-woo-subscriptions';
	}

	/**
	 * Registers the script.
	 */
	public function initialize() {
		wp_register_script(
			'lws-blocks',
			LWS_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-i18n' ),
			LWS_VERSION,
			true
		);
		wp_set_script_translations( 'lws-blocks', 'lighter-woo-subscriptions', LWS_DIR . 'languages' );
	}

	/**
	 * Frontend scripts.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'lws-blocks' );
	}

	/**
	 * Editor scripts.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Script data.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array();
	}
}
