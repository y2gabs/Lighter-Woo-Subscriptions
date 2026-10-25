<?php
/**
 * Manual renewal fallback.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Used for gateways without automatic renewal support, or when no saved card is available.
 * The renewal order stays pending and the customer is emailed a link to pay it.
 */
class LWS_Gateway_Manual implements LWS_Gateway_Adapter {

	/**
	 * Implements LWS_Gateway_Adapter::get_id().
	 */
	public function get_id() {
		return 'manual';
	}

	/**
	 * Implements LWS_Gateway_Adapter::init().
	 */
	public function init() {}

	/**
	 * Implements LWS_Gateway_Adapter::supports_gateway().
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public function supports_gateway( $gateway_id ) {
		return true;
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_payment_meta_keys().
	 */
	public function get_payment_meta_keys() {
		return array();
	}

	/**
	 * Implements LWS_Gateway_Adapter::charge().
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function charge( WC_Order $order, LWS_Subscription $subscription ) {
		return array(
			'result'  => 'pending',
			'message' => __( 'Manual renewal: invoice sent to the customer.', 'lighter-woo-subscriptions' ),
		);
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_customer_tokens().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_customer_tokens( LWS_Subscription $subscription ) {
		return array();
	}

	/**
	 * Implements LWS_Gateway_Adapter::use_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Payment_Token $token        Token owned by the customer.
	 */
	public function use_token( LWS_Subscription $subscription, WC_Payment_Token $token ) {}

	/**
	 * Implements LWS_Gateway_Adapter::get_current_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_current_token( LWS_Subscription $subscription ) {
		return '';
	}
}
