<?php
/**
 * Gateway adapter contract.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment gateways only charge saved cards automatically for the official Subscriptions plugin,
 * so each supported gateway gets a small adapter that charges its saved card directly.
 */
interface LWS_Gateway_Adapter {

	/**
	 * Adapter ID.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Registers hooks (e.g. forcing the gateway to save the card at checkout).
	 */
	public function init();

	/**
	 * Whether this adapter handles a WooCommerce gateway ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	public function supports_gateway( $gateway_id );

	/**
	 * Order meta keys holding the saved payment reference. Copied from the checkout order to the
	 * subscription, and from the subscription to each renewal order.
	 *
	 * @return string[]
	 */
	public function get_payment_meta_keys();

	/**
	 * Charges a renewal order off-session.
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 * @return array{result:string,message?:string,transaction_id?:string} result is success|pending|failure.
	 */
	public function charge( WC_Order $order, LWS_Subscription $subscription );

	/**
	 * Saved payment tokens the customer could switch this subscription to.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return WC_Payment_Token[]
	 */
	public function get_customer_tokens( LWS_Subscription $subscription );

	/**
	 * Points the subscription at another saved token. Does not save.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Payment_Token $token        Token owned by the customer.
	 */
	public function use_token( LWS_Subscription $subscription, WC_Payment_Token $token );

	/**
	 * The token value currently used by the subscription.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return string
	 */
	public function get_current_token( LWS_Subscription $subscription );
}
