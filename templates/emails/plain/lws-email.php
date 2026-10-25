<?php
/**
 * Subscription email (plain text).
 *
 * @package LighterWooSubscriptions
 * @var WC_Order         $order
 * @var LWS_Subscription $subscription
 * @var string           $email_heading
 * @var string           $intro
 * @var string           $pay_url
 * @var string           $additional_content
 * @var bool             $sent_to_admin
 * @var bool             $plain_text
 * @var WC_Email         $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WooCommerce email hooks.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( $intro ) . "\n\n";

if ( $pay_url ) {
	/* translators: %s: URL. */
	echo esc_html( sprintf( __( 'Pay now: %s', 'lighter-woo-subscriptions' ), $pay_url ) ) . "\n\n";
}

/* translators: 1: subscription ID, 2: schedule. */
echo esc_html( sprintf( __( 'Subscription #%1$s, billed %2$s.', 'lighter-woo-subscriptions' ), $subscription->get_id(), $subscription->get_schedule_string() ) ) . "\n";
echo esc_url( $sent_to_admin ? $subscription->get_edit_order_url() : $subscription->get_view_order_url() ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n" . wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
