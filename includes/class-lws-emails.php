<?php
/**
 * Registers subscription emails.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emails. Renewal orders also get WooCommerce's normal order emails (receipt, admin new/failed order).
 */
class LWS_Emails {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );
	}

	/**
	 * Adds our email classes. Loaded here because WC_Email only exists once the mailer loads.
	 *
	 * @param array $emails Email classes.
	 * @return array
	 */
	public static function register( $emails ) {
		require_once LWS_DIR . 'includes/emails/class-lws-email.php';
		require_once LWS_DIR . 'includes/emails/class-lws-email-renewal-invoice.php';
		require_once LWS_DIR . 'includes/emails/class-lws-email-payment-failed.php';
		require_once LWS_DIR . 'includes/emails/class-lws-email-cancelled.php';

		$emails['LWS_Email_Renewal_Invoice'] = new LWS_Email_Renewal_Invoice();
		$emails['LWS_Email_Payment_Failed']  = new LWS_Email_Payment_Failed();
		$emails['LWS_Email_Cancelled']       = new LWS_Email_Cancelled();
		return $emails;
	}

	/**
	 * Lets WooCommerce load the mailer when our actions fire; emails listen to "{action}_notification".
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function email_actions( $actions ) {
		$actions[] = 'lws_renewal_invoice';
		$actions[] = 'lws_renewal_payment_failed';
		$actions[] = 'lws_subscription_status_cancelled';
		$actions[] = 'lws_subscription_status_pending-cancel';
		return $actions;
	}
}
