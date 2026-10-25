<?php
/**
 * Customer renewal payment failed.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent on the first failed attempt and again when all retries are exhausted.
 */
class LWS_Email_Payment_Failed extends LWS_Email {

	/**
	 * Whether retries are exhausted for the email being sent.
	 *
	 * @var bool
	 */
	private $final = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'lws_payment_failed';
		$this->customer_email = true;
		$this->title          = __( 'Subscription payment failed', 'lighter-woo-subscriptions' );
		$this->description    = __( 'Sent to customers when an automatic renewal payment fails.', 'lighter-woo-subscriptions' );
		$this->show_pay_link  = true;

		add_action( 'lws_renewal_payment_failed_notification', array( $this, 'trigger' ), 10, 3 );
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} subscription payment failed', 'lighter-woo-subscriptions' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'We could not process your renewal payment', 'lighter-woo-subscriptions' );
	}

	/**
	 * Intro text.
	 *
	 * @return string
	 */
	public function get_intro() {
		return $this->final
			? __( 'We tried several times but could not charge your card. Your subscription is on hold. Pay the renewal below, or choose a different card in your account, to reactivate it.', 'lighter-woo-subscriptions' )
			: __( 'We could not charge your card for your subscription renewal. We will try again automatically. You can also pay now using the link below, or choose a different card in your account.', 'lighter-woo-subscriptions' );
	}

	/**
	 * Trigger.
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 * @param bool             $is_final     Retries exhausted.
	 */
	public function trigger( $order, $subscription, $is_final = false ) {
		if ( $order instanceof WC_Order && $subscription instanceof LWS_Subscription ) {
			$this->final = (bool) $is_final;
			$this->send_for( $order, $subscription );
		}
	}
}
