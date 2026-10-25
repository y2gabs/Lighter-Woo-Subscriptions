<?php
/**
 * Shared email behaviour.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class: one template showing an intro paragraph, an optional pay button and the order table.
 */
abstract class LWS_Email extends WC_Email {

	/**
	 * Subscription the email is about.
	 *
	 * @var LWS_Subscription|null
	 */
	public $subscription = null;

	/**
	 * Show the "Pay now" button for $this->object.
	 *
	 * @var bool
	 */
	protected $show_pay_link = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->template_base  = LWS_DIR . 'templates/';
		$this->template_html  = 'emails/lws-email.php';
		$this->template_plain = 'emails/plain/lws-email.php';
		$this->placeholders   = array_merge(
			array(
				'{order_number}'    => '',
				'{subscription_id}' => '',
			),
			$this->placeholders
		);
		parent::__construct();
	}

	/**
	 * Intro paragraph.
	 *
	 * @return string
	 */
	abstract public function get_intro();

	/**
	 * Sets the objects and sends.
	 *
	 * @param WC_Order         $order        Order shown in the email (renewal order or subscription).
	 * @param LWS_Subscription $subscription Subscription.
	 * @param string           $recipient    Recipient; customer email when empty.
	 */
	protected function send_for( $order, $subscription, $recipient = '' ) {
		$this->setup_locale();

		$this->object       = $order;
		$this->subscription = $subscription;
		$this->recipient    = $recipient ? $recipient : $order->get_billing_email();

		$this->placeholders['{order_number}']    = $order->get_order_number();
		$this->placeholders['{subscription_id}'] = $subscription->get_id();
		$this->placeholders['{order_date}']      = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Template arguments.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array
	 */
	protected function template_args( $plain_text ) {
		return array(
			'order'              => $this->object,
			'subscription'       => $this->subscription,
			'email_heading'      => $this->get_heading(),
			'intro'              => $this->get_intro(),
			'pay_url'            => $this->show_pay_link && $this->object && $this->object->needs_payment() ? $this->object->get_checkout_payment_url() : '',
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->is_customer_email(),
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
