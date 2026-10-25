<?php
/**
 * Cart and Checkout block support.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * Adds schedule data to the Store API and a small script that shows it in the blocks.
 */
class LWS_Blocks {

	const NAMESPACE = 'lws';

	/**
	 * Hooks.
	 */
	public static function init() {
		// This plugin loads on plugins_loaded:20, after WooCommerce has usually fired woocommerce_blocks_loaded.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_data' ) );
		}
		add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
	}

	/**
	 * Registers the script with the blocks integration registry.
	 *
	 * @param object $registry Integration registry.
	 */
	public static function register_integration( $registry ) {
		require_once LWS_DIR . 'includes/class-lws-blocks-integration.php';
		if ( ! $registry->is_registered( 'lighter-woo-subscriptions' ) ) {
			$registry->register( new LWS_Blocks_Integration() );
		}
	}

	/**
	 * Extends cart and cart item responses.
	 */
	public static function register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartItemSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( __CLASS__, 'cart_item_data' ),
				'schema_callback' => array( __CLASS__, 'cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( __CLASS__, 'cart_data' ),
				'schema_callback' => array( __CLASS__, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Per-item data.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $cart_item ) {
		$schedule = isset( $cart_item['data'] ) ? lws_get_product_schedule( $cart_item['data'] ) : null;
		return array(
			'schedule' => $schedule ? lws_get_schedule_string( $schedule['interval'], $schedule['period'], $schedule['length'] ) : '',
		);
	}

	/**
	 * Per-item schema.
	 *
	 * @return array
	 */
	public static function cart_item_schema() {
		return array(
			'schedule' => array(
				'description' => __( 'Billing schedule text.', 'lighter-woo-subscriptions' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Cart-level data.
	 *
	 * @return array
	 */
	public static function cart_data() {
		$schedule = LWS_Cart::get_cart_schedule();
		if ( ! $schedule ) {
			return array(
				'has_subscription' => false,
				'recurring_total'  => '',
				'schedule'         => '',
				'first_renewal'    => '',
			);
		}

		$first = lws_add_periods( time(), $schedule['interval'], $schedule['period'] );
		return array(
			'has_subscription' => true,
			'recurring_total'  => html_entity_decode( wp_strip_all_tags( wc_price( LWS_Cart::get_recurring_total() ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'schedule'         => lws_get_schedule_string( $schedule['interval'], $schedule['period'], $schedule['length'] ),
			/* translators: %s: date. */
			'first_renewal'    => sprintf( __( 'First renewal: %s', 'lighter-woo-subscriptions' ), wp_date( get_option( 'date_format' ), $first ) ),
		);
	}

	/**
	 * Cart-level schema.
	 *
	 * @return array
	 */
	public static function cart_schema() {
		$string = array(
			'type'     => 'string',
			'readonly' => true,
		);
		return array(
			'has_subscription' => array(
				'type'     => 'boolean',
				'readonly' => true,
			),
			'recurring_total'  => $string,
			'schedule'         => $string,
			'first_renewal'    => $string,
		);
	}
}
