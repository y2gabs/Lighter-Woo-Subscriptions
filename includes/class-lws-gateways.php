<?php
/**
 * Gateway adapter registry.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Picks the adapter for a subscription and moves saved-card references between orders.
 */
class LWS_Gateways {

	/**
	 * Automatic adapters.
	 *
	 * @var LWS_Gateway_Adapter[]
	 */
	private static $adapters = array();

	/**
	 * Fallback adapter.
	 *
	 * @var LWS_Gateway_Manual|null
	 */
	private static $manual = null;

	/**
	 * Registers adapters.
	 */
	public static function init() {
		self::$manual   = new LWS_Gateway_Manual();
		self::$adapters = apply_filters(
			'lws_gateway_adapters',
			array(
				'stripe' => new LWS_Gateway_Stripe(),
				'square' => new LWS_Gateway_Square(),
			)
		);
		foreach ( self::$adapters as $adapter ) {
			$adapter->init();
		}
	}

	/**
	 * Automatic adapter for a gateway ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return LWS_Gateway_Adapter|null
	 */
	public static function get_adapter( $gateway_id ) {
		foreach ( self::$adapters as $adapter ) {
			if ( $adapter->supports_gateway( $gateway_id ) ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Adapter that will charge this subscription: its gateway's adapter when a saved card is on
	 * file, otherwise the manual (invoice) fallback.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return LWS_Gateway_Adapter
	 */
	public static function get_adapter_for( LWS_Subscription $subscription ) {
		$adapter = self::get_adapter( $subscription->get_payment_method() );
		if ( $adapter && self::has_payment_details( $subscription ) && ! $subscription->get_meta( '_lws_manual_renewal' ) ) {
			return $adapter;
		}
		return self::$manual;
	}

	/**
	 * Whether automatic renewal is possible for the subscription.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return bool
	 */
	public static function is_automatic( LWS_Subscription $subscription ) {
		return 'manual' !== self::get_adapter_for( $subscription )->get_id();
	}

	/**
	 * Whether the subscription holds a saved-card reference for its gateway.
	 *
	 * @param WC_Order $order Subscription or order.
	 * @return bool
	 */
	public static function has_payment_details( WC_Order $order ) {
		$adapter = self::get_adapter( $order->get_payment_method() );
		if ( ! $adapter ) {
			return false;
		}
		foreach ( $adapter->get_payment_meta_keys() as $key ) {
			if ( '' === (string) $order->get_meta( $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Copies the saved-card references for $from's gateway onto $to. Does not save.
	 *
	 * @param WC_Order $from Source order or subscription.
	 * @param WC_Order $to   Target order or subscription.
	 */
	public static function copy_payment_details( WC_Order $from, WC_Order $to ) {
		$adapter = self::get_adapter( $from->get_payment_method() );
		if ( ! $adapter ) {
			return;
		}
		foreach ( $adapter->get_payment_meta_keys() as $key ) {
			$value = $from->get_meta( $key );
			if ( '' !== (string) $value ) {
				$to->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Whether the current payment is for a subscription, so the gateway must save the card:
	 * checkout with a subscription in the cart, or paying a subscription / renewal order.
	 *
	 * @param int|string|null $order_id Order ID, if the gateway passes one.
	 * @return bool
	 */
	public static function is_subscription_payment_context( $order_id = null ) {
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && lws_order_has_subscription( $order ) ) {
				return true;
			}
		}

		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			$pay_order_id = absint( get_query_var( 'order-pay' ) );
			$order        = $pay_order_id ? wc_get_order( $pay_order_id ) : false;
			if ( $order && lws_order_has_subscription( $order ) ) {
				return true;
			}
		}

		if ( did_action( 'wp_loaded' ) && LWS_Cart::cart_has_subscription() ) {
			return true;
		}

		return false;
	}
}
