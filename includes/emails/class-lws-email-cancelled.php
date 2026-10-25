<?php
/**
 * Admin: subscription cancelled.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells the store when a subscription is cancelled (immediately or at period end).
 */
class LWS_Email_Cancelled extends LWS_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'lws_cancelled';
		$this->title       = __( 'Subscription cancelled', 'lighter-woo-subscriptions' );
		$this->description = __( 'Sent to the store when a subscription is cancelled.', 'lighter-woo-subscriptions' );

		add_action( 'lws_subscription_status_cancelled_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'lws_subscription_status_pending-cancel_notification', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Subscription #{subscription_id} cancelled', 'lighter-woo-subscriptions' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Subscription cancelled', 'lighter-woo-subscriptions' );
	}

	/**
	 * Intro text.
	 *
	 * @return string
	 */
	public function get_intro() {
		$end = $this->subscription ? $this->subscription->get_formatted_date( 'end' ) : '';
		return $this->subscription && $this->subscription->has_status( 'pending-cancel' ) && $end
			/* translators: 1: subscription ID, 2: date. */
			? sprintf( __( 'Subscription #%1$d was cancelled and will end on %2$s.', 'lighter-woo-subscriptions' ), $this->subscription->get_id(), $end )
			/* translators: %d: subscription ID. */
			: sprintf( __( 'Subscription #%d has been cancelled.', 'lighter-woo-subscriptions' ), $this->subscription ? $this->subscription->get_id() : 0 );
	}

	/**
	 * Admin form fields: add a recipient field.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'lighter-woo-subscriptions' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Comma separated. Defaults to %s.', 'lighter-woo-subscriptions' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
				),
			),
			$this->form_fields
		);
	}

	/**
	 * Trigger.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param string           $from         Previous status.
	 */
	public function trigger( $subscription, $from = '' ) {
		// pending-cancel → cancelled is the period ending, already announced when the customer cancelled.
		if ( ! $subscription instanceof LWS_Subscription || 'pending-cancel' === $from ) {
			return;
		}
		$this->send_for( $subscription, $subscription, $this->get_recipient() ? $this->get_recipient() : get_option( 'admin_email' ) );
	}
}
