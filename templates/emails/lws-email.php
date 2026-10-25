<?php
/**
 * Subscription email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/lws-email.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo esc_html( $intro ); ?></p>

<?php if ( $pay_url ) : ?>
	<p><a class="button" href="<?php echo esc_url( $pay_url ); ?>"><?php esc_html_e( 'Pay now', 'lighter-woo-subscriptions' ); ?></a></p>
<?php endif; ?>

<p>
	<?php
	printf(
		/* translators: 1: subscription ID, 2: schedule. */
		esc_html__( 'Subscription #%1$s, billed %2$s.', 'lighter-woo-subscriptions' ),
		esc_html( $subscription->get_id() ),
		esc_html( $subscription->get_schedule_string() )
	);
	?>
	<a href="<?php echo esc_url( $sent_to_admin ? $subscription->get_edit_order_url() : $subscription->get_view_order_url() ); ?>"><?php esc_html_e( 'View subscription', 'lighter-woo-subscriptions' ); ?></a>
</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
