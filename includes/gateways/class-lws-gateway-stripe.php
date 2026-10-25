<?php
/**
 * Stripe adapter for the official "WooCommerce Stripe Payment Gateway" plugin.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Stripe plugin stores the saved payment method (pm_...) in `_stripe_source_id` and the Stripe
 * customer (cus_...) in `_stripe_customer_id` on each order. Renewals create an off-session,
 * immediately confirmed PaymentIntent with those IDs through the plugin's own API client, so the
 * plugin's keys, logging and webhooks are reused.
 */
class LWS_Gateway_Stripe implements LWS_Gateway_Adapter {

	/**
	 * Implements LWS_Gateway_Adapter::get_id().
	 */
	public function get_id() {
		return 'stripe';
	}

	/**
	 * Implements LWS_Gateway_Adapter::init().
	 */
	public function init() {
		add_filter( 'wc_stripe_force_save_payment_method', array( $this, 'force_save' ), 10, 2 );
		if ( defined( 'WC_STRIPE_VERSION' ) && version_compare( WC_STRIPE_VERSION, '9.6.0', '<' ) ) {
			add_filter( 'wc_stripe_force_save_source', array( $this, 'force_save' ), 10, 2 );
		}
	}

	/**
	 * Forces the card to be saved for later off-session use when buying or paying for a subscription.
	 *
	 * @param bool       $force    Current value.
	 * @param int|string $order_id Order ID, when known.
	 * @return bool
	 */
	public function force_save( $force, $order_id = null ) {
		return LWS_Gateways::is_subscription_payment_context( $order_id ) ? true : $force;
	}

	/**
	 * Implements LWS_Gateway_Adapter::supports_gateway().
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public function supports_gateway( $gateway_id ) {
		// Cards use "stripe"; reusable methods such as SEPA use "stripe_sepa_debit" etc. and share the same meta.
		return 'stripe' === $gateway_id || 0 === strpos( (string) $gateway_id, 'stripe_' );
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_payment_meta_keys().
	 */
	public function get_payment_meta_keys() {
		return array( '_stripe_customer_id', '_stripe_source_id' );
	}

	/**
	 * Implements LWS_Gateway_Adapter::charge().
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function charge( WC_Order $order, LWS_Subscription $subscription ) {
		if ( ! class_exists( 'WC_Stripe_API' ) || ! class_exists( 'WC_Stripe_Helper' ) ) {
			return $this->failure( __( 'The WooCommerce Stripe Gateway plugin is not active.', 'lighter-woo-subscriptions' ) );
		}

		$payment_method = (string) $subscription->get_meta( '_stripe_source_id' );
		$customer       = (string) $subscription->get_meta( '_stripe_customer_id' );
		if ( ! $customer && $subscription->get_customer_id() ) {
			$customer = (string) get_user_option( '_stripe_customer_id', $subscription->get_customer_id() );
		}
		if ( ! $payment_method || ! $customer ) {
			return $this->failure( __( 'No saved Stripe payment method on this subscription.', 'lighter-woo-subscriptions' ) );
		}

		$currency = $order->get_currency();
		$request  = array(
			'amount'                    => WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $currency ),
			'currency'                  => strtolower( $currency ),
			'customer'                  => $customer,
			'payment_method'            => $payment_method,
			'off_session'               => 'true',
			'confirm'                   => 'true',
			'automatic_payment_methods' => array(
				'enabled'         => 'true',
				'allow_redirects' => 'never',
			),
			/* translators: 1: site name, 2: order number. */
			'description'               => sprintf( __( '%1$s - Subscription renewal order %2$s', 'lighter-woo-subscriptions' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() ),
			'metadata'                  => array(
				'order_id'        => $order->get_id(),
				'subscription_id' => $subscription->get_id(),
				'site_url'        => esc_url_raw( get_site_url() ),
			),
		);

		try {
			$intent = WC_Stripe_API::request( apply_filters( 'lws_stripe_renewal_request', $request, $order, $subscription ), 'payment_intents' );
		} catch ( Exception $e ) {
			return $this->failure( $e->getMessage() );
		}

		if ( ! empty( $intent->error ) ) {
			if ( ! empty( $intent->error->payment_intent->id ) ) {
				$order->update_meta_data( '_stripe_intent_id', $intent->error->payment_intent->id );
				$order->save();
			}
			$message = ! empty( $intent->error->message ) ? $intent->error->message : __( 'Stripe declined the payment.', 'lighter-woo-subscriptions' );
			if ( isset( $intent->error->code ) && 'authentication_required' === $intent->error->code ) {
				$message = __( 'The card issuer requires the customer to authenticate this payment.', 'lighter-woo-subscriptions' );
			}
			return $this->failure( $message );
		}

		if ( empty( $intent->id ) ) {
			return $this->failure( __( 'Unexpected response from Stripe.', 'lighter-woo-subscriptions' ) );
		}

		// Lets the Stripe plugin's webhooks and refund tools find this order.
		$order->update_meta_data( '_stripe_intent_id', $intent->id );
		$order->update_meta_data( '_stripe_currency', strtoupper( $currency ) );
		$order->save();

		switch ( $intent->status ) {
			case 'succeeded':
				$order->update_meta_data( '_stripe_charge_captured', 'yes' );
				$order->save();
				/* translators: %s: Stripe ID. */
				$order->add_order_note( sprintf( __( 'Stripe renewal charge complete (%s).', 'lighter-woo-subscriptions' ), $intent->id ) );
				return array(
					'result'         => 'success',
					'transaction_id' => ! empty( $intent->latest_charge ) ? ( is_object( $intent->latest_charge ) ? $intent->latest_charge->id : $intent->latest_charge ) : $intent->id,
				);

			case 'processing':
				$order->set_transaction_id( $intent->id );
				$order->update_status( 'on-hold', __( 'Stripe is processing the renewal payment.', 'lighter-woo-subscriptions' ) );
				return array( 'result' => 'pending' );

			case 'requires_action':
			case 'requires_payment_method':
			default:
				return $this->failure( __( 'The card issuer requires the customer to authenticate this payment.', 'lighter-woo-subscriptions' ) );
		}
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_customer_tokens().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_customer_tokens( LWS_Subscription $subscription ) {
		return WC_Payment_Tokens::get_customer_tokens( $subscription->get_customer_id(), $subscription->get_payment_method() );
	}

	/**
	 * Implements LWS_Gateway_Adapter::use_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Payment_Token $token        Token owned by the customer.
	 */
	public function use_token( LWS_Subscription $subscription, WC_Payment_Token $token ) {
		$subscription->update_meta_data( '_stripe_source_id', $token->get_token() );
		$customer = get_user_option( '_stripe_customer_id', $subscription->get_customer_id() );
		if ( $customer ) {
			$subscription->update_meta_data( '_stripe_customer_id', $customer );
		}
	}

	/**
	 * Implements LWS_Gateway_Adapter::get_current_token().
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function get_current_token( LWS_Subscription $subscription ) {
		return (string) $subscription->get_meta( '_stripe_source_id' );
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
