<?php
/**
 * Customer renewal invoice (manual renewals).
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent when a renewal is due and the subscription has no card for automatic payment.
 */
class LWS_Email_Renewal_Invoice extends LWS_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'lws_renewal_invoice';
		$this->customer_email = true;
		$this->title          = __( 'Subscription renewal invoice', 'lighter-woo-subscriptions' );
		$this->description    = __( 'Sent to customers when a subscription renewal is due and must be paid manually.', 'lighter-woo-subscriptions' );
		$this->show_pay_link  = true;

		add_action( 'lws_renewal_invoice_notification', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} subscription renewal is due', 'lighter-woo-subscriptions' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Renewal invoice for order #{order_number}', 'lighter-woo-subscriptions' );
	}

	/**
	 * Intro text.
	 *
	 * @return string
	 */
	public function get_intro() {
		return __( 'Your subscription renewal is ready. Please use the link below to pay and keep your subscription active.', 'lighter-woo-subscriptions' );
	}

	/**
	 * Trigger.
	 *
	 * @param WC_Order         $order        Renewal order.
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public function trigger( $order, $subscription ) {
		if ( $order instanceof WC_Order && $subscription instanceof LWS_Subscription ) {
			$this->send_for( $order, $subscription );
		}
	}
}
