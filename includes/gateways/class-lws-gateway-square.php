<?php
/**
 * Square adapter for the official "WooCommerce Square" plugin.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Square saves the card on file as a token and stores it, with the Square customer
 * ID, in `_wc_square_credit_card_payment_token` / `_wc_square_credit_card_customer_id` order meta.
 * Renewals run the plugin's own process_payment() with that token injected through its
 * `wc_payment_gateway_square_credit_card_get_order` filter, the same way its built-in subscriptions
 * integration does.
 */
class LWS_Gateway_Square implements LWS_Gateway_Adapter {

	const GATEWAY_ID = 'square_credit_card';

	/**
	 * Token to inject for the charge in progress.
	 *
	 * @var array|null
	 */
	private $pending_charge = null;

	/**
	 * Implements LWS_Gateway_Adapter::get_id().
	 */
	public function get_id() {
		return 'square';
	}

	/**
	 * Implements LWS_Gateway_Adapter::init().
	 */
	public function init() {
		add_filter( 'wc_payment_gateway_' . self::GATEWAY_ID . '_tokenization_forced', array( $this, 'force_tokenization' ) );
		add_filter( 'wc_' . self::GATEWAY_ID . '_payment_form_tokenization_forced', array( $this, 'force_tokenization' ) );
	}

	/**
	 * Forces the card to be saved when buying or paying for a subscription.
	 *
	 * @param bool $forced Current value.
	 * @return bool
	 */
	public function force_tokenization( $forced ) {
		return LWS_Gateways::is_subscription_payment_context() ? true : $forced;
	}

	/**
	 * Implements LWS_Gateway_Adapter::supports_gateway().
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public function supports_gateway( $gateway_id ) {
		return self::GATEWAY_ID === $gateway_id;
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_payment_meta_keys().
	 */
	public function get_payment_meta_keys() {
		return array( '_wc_' . self::GATEWAY_ID . '_payment_token', '_wc_' . self::GATEWAY_ID . '_customer_id' );
	}

	/**
	 * The Square gateway instance, if the plugin is active.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ self::GATEWAY_ID ] ) ? $gateways[ self::GATEWAY_ID ] : null;
	}

	/**
	 * Implements LWS_Gateway_Adapter::charge().
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function charge( WC_Order $order, LWS_Subscription $subscription ) {
		$gateway = $this->get_gateway();
		if ( ! $gateway || ! method_exists( $gateway, 'get_payment_tokens_handler' ) ) {
			return $this->failure( __( 'The WooCommerce Square plugin is not active.', 'lighter-woo-subscriptions' ) );
		}

		$token = $this->get_current_token( $subscription );
		if ( ! $token || ! $gateway->get_payment_tokens_handler()->user_has_token( $subscription->get_customer_id(), $token ) ) {
			return $this->failure( __( 'No saved Square card on this subscription.', 'lighter-woo-subscriptions' ) );
		}

		if ( $order->get_payment_method() !== self::GATEWAY_ID ) {
			$order->set_payment_method( $gateway );
			$order->save();
		}

		$this->pending_charge = array(
			'order_id'    => $order->get_id(),
			'token'       => $token,
			'customer_id' => (string) $subscription->get_meta( '_wc_' . self::GATEWAY_ID . '_customer_id' ),
			'user_id'     => $subscription->get_customer_id(),
		);
		$filter               = 'wc_payment_gateway_' . self::GATEWAY_ID . '_get_order';
		add_filter( $filter, array( $this, 'inject_token' ), 10, 2 );

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} catch ( Exception $e ) {
			$result = array(
				'result'  => 'failure',
				'message' => $e->getMessage(),
			);
		} finally {
			remove_filter( $filter, array( $this, 'inject_token' ), 10 );
			$this->pending_charge = null;
			if ( function_exists( 'wc_clear_notices' ) && WC()->session ) {
				wc_clear_notices(); // Square adds checkout notices; there is no customer to show them to.
			}
		}

		$succeeded = is_array( $result ) && isset( $result['result'] ) && 'success' === $result['result'];
		$order     = wc_get_order( $order->get_id() );
		if ( $order && ( $order->is_paid() || ( $succeeded && $order->has_status( 'on-hold' ) ) ) ) {
			return array( 'result' => 'success' ); // on-hold = authorized, when Square is set to authorize only.
		}

		return $this->failure( is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : __( 'Square declined the payment.', 'lighter-woo-subscriptions' ) );
	}

	/**
	 * Adds the saved card to the order object Square builds for the transaction.
	 *
	 * @param WC_Order $order   Order with Square's `payment` data.
	 * @param object   $gateway Square gateway.
	 * @return WC_Order
	 */
	public function inject_token( $order, $gateway ) {
		if ( ! $this->pending_charge || (int) $order->get_id() !== (int) $this->pending_charge['order_id'] ) {
			return $order;
		}

		/* translators: 1: site name, 2: order number. */
		$order->description    = sprintf( __( '%1$s - Subscription renewal order %2$s', 'lighter-woo-subscriptions' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
		$order->payment->token = $this->pending_charge['token'];
		if ( $this->pending_charge['customer_id'] ) {
			$order->customer_id = $this->pending_charge['customer_id'];
		}

		$token = $gateway->get_payment_tokens_handler()->get_token( $this->pending_charge['user_id'], $this->pending_charge['token'] );
		if ( $token ) {
			$order->payment->account_number = $token->get_last4();
			$order->payment->last_four      = $token->get_last4();
			$order->payment->card_type      = $token->get_card_type();
			$order->payment->exp_month      = $token->get_expiry_month();
			$order->payment->exp_year       = $token->get_expiry_year();
		}

		return $order;
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_customer_tokens().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_customer_tokens( LWS_Subscription $subscription ) {
		return WC_Payment_Tokens::get_customer_tokens( $subscription->get_customer_id(), self::GATEWAY_ID );
	}

	/**
	 * Implements LWS_Gateway_Adapter::use_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Payment_Token $token        Token owned by the customer.
	 */
	public function use_token( LWS_Subscription $subscription, WC_Payment_Token $token ) {
		$subscription->update_meta_data( '_wc_' . self::GATEWAY_ID . '_payment_token', $token->get_token() );
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_current_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_current_token( LWS_Subscription $subscription ) {
		return (string) $subscription->get_meta( '_wc_' . self::GATEWAY_ID . '_payment_token' );
	}

	/**
	 * Failure result.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private function failure( $message ) {
		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}
}
