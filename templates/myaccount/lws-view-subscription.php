<?php
/**
 * My Account > View subscription.
 *
 * Override by copying to yourtheme/woocommerce/myaccount/lws-view-subscription.php.
 *
 * @package LighterWooSubscriptions
 * @var LWS_Subscription      $subscription
 * @var array<string,string>  $actions
 * @var WC_Order|false        $unpaid_order
 * @var WC_Payment_Token[]    $tokens
 * @var string                $current_token
 * @var bool                  $is_automatic
 */

defined( 'ABSPATH' ) || exit;

$lws_rows = array(
	__( 'Status', 'lighter-woo-subscriptions' )       => lws_get_status_label( $subscription->get_status() ),
	__( 'Billing', 'lighter-woo-subscriptions' )      => wp_strip_all_tags( wc_price( $subscription->get_total(), array( 'currency' => $subscription->get_currency() ) ) ) . ' ' . $subscription->get_schedule_string(),
	__( 'Started', 'lighter-woo-subscriptions' )      => $subscription->get_formatted_date( 'start' ),
	__( 'Last payment', 'lighter-woo-subscriptions' ) => $subscription->get_formatted_date( 'last_payment' ),
);
if ( $subscription->has_status( 'active' ) && $subscription->get_date_timestamp( 'next_payment' ) ) {
	$lws_rows[ __( 'Next payment', 'lighter-woo-subscriptions' ) ] = $subscription->get_formatted_date( 'next_payment' );
}
if ( $subscription->get_date_timestamp( 'end' ) ) {
	$lws_rows[ $subscription->has_status( array( 'cancelled', 'expired' ) ) ? __( 'Ended', 'lighter-woo-subscriptions' ) : __( 'Ends', 'lighter-woo-subscriptions' ) ] = $subscription->get_formatted_date( 'end' );
}
$lws_rows[ __( 'Payment', 'lighter-woo-subscriptions' ) ] = $is_automatic
	? $subscription->get_payment_method_title()
	: __( 'Manual (we email you an invoice each renewal)', 'lighter-woo-subscriptions' );
?>

<?php if ( $unpaid_order ) : ?>
	<div class="woocommerce-error">
		<?php esc_html_e( 'Your latest renewal payment did not go through.', 'lighter-woo-subscriptions' ); ?>
		<a class="button" href="<?php echo esc_url( $unpaid_order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay now', 'lighter-woo-subscriptions' ); ?></a>
	</div>
<?php endif; ?>

<table class="shop_table lws-subscription-details">
	<tbody>
	<?php foreach ( $lws_rows as $lws_label => $lws_value ) : ?>
		<?php if ( '' !== $lws_value ) : ?>
		<tr><th><?php echo esc_html( $lws_label ); ?></th><td><?php echo esc_html( $lws_value ); ?></td></tr>
		<?php endif; ?>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $actions ) : ?>
<form method="post" class="lws-subscription-actions">
	<?php wp_nonce_field( 'lws_subscription_' . $subscription->get_id(), '_lws_nonce' ); ?>
	<input type="hidden" name="lws_subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>" />
	<?php foreach ( $actions as $lws_action => $lws_label ) : ?>
		<button type="submit" class="woocommerce-button button wp-element-button lws-action-<?php echo esc_attr( $lws_action ); ?>" name="lws_action" value="<?php echo esc_attr( $lws_action ); ?>"
			<?php if ( 'cancel' === $lws_action ) : ?>
			onclick="return window.confirm('<?php echo esc_js( __( 'Cancel this subscription?', 'lighter-woo-subscriptions' ) ); ?>');"
			<?php endif; ?>
		><?php echo esc_html( $lws_label ); ?></button>
	<?php endforeach; ?>
</form>
<?php endif; ?>

<?php if ( $tokens && $subscription->has_status( array( 'active', 'on-hold', 'pending-cancel' ) ) ) : ?>
<h2><?php esc_html_e( 'Payment card', 'lighter-woo-subscriptions' ); ?></h2>
<form method="post" class="lws-change-card">
	<?php wp_nonce_field( 'lws_subscription_' . $subscription->get_id(), '_lws_nonce' ); ?>
	<input type="hidden" name="lws_subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>" />
	<input type="hidden" name="lws_action" value="change_card" />
	<select name="lws_token_id">
		<?php foreach ( $tokens as $lws_token ) : ?>
			<option value="<?php echo esc_attr( $lws_token->get_id() ); ?>" <?php selected( $lws_token->get_token(), $current_token ); ?>><?php echo esc_html( wp_strip_all_tags( $lws_token->get_display_name() ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="woocommerce-button button wp-element-button"><?php esc_html_e( 'Use this card', 'lighter-woo-subscriptions' ); ?></button>
	<p><a href="<?php echo esc_url( wc_get_endpoint_url( 'add-payment-method', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Add a new card', 'lighter-woo-subscriptions' ); ?></a></p>
</form>
<?php endif; ?>

<h2><?php esc_html_e( 'Items', 'lighter-woo-subscriptions' ); ?></h2>
<table class="shop_table lws-subscription-items">
	<thead><tr><th><?php esc_html_e( 'Product', 'lighter-woo-subscriptions' ); ?></th><th><?php esc_html_e( 'Total', 'lighter-woo-subscriptions' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $subscription->get_items() as $lws_item ) : ?>
		<tr>
			<td><?php echo esc_html( $lws_item->get_name() ); ?> &times; <?php echo esc_html( $lws_item->get_quantity() ); ?></td>
			<td><?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $lws_item ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
	<tfoot>
	<?php foreach ( $subscription->get_order_item_totals() as $lws_total ) : ?>
		<tr><th><?php echo esc_html( $lws_total['label'] ); ?></th><td><?php echo wp_kses_post( $lws_total['value'] ); ?></td></tr>
	<?php endforeach; ?>
	</tfoot>
</table>

<h2><?php esc_html_e( 'Orders', 'lighter-woo-subscriptions' ); ?></h2>
<table class="shop_table lws-subscription-orders">
	<thead><tr><th><?php esc_html_e( 'Order', 'lighter-woo-subscriptions' ); ?></th><th><?php esc_html_e( 'Date', 'lighter-woo-subscriptions' ); ?></th><th><?php esc_html_e( 'Status', 'lighter-woo-subscriptions' ); ?></th><th><?php esc_html_e( 'Total', 'lighter-woo-subscriptions' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $subscription->get_related_orders() as $lws_order ) : ?>
		<tr>
			<td><a href="<?php echo esc_url( $lws_order->get_view_order_url() ); ?>">#<?php echo esc_html( $lws_order->get_order_number() ); ?></a></td>
			<td><?php echo esc_html( $lws_order->get_date_created() ? wc_format_datetime( $lws_order->get_date_created() ) : '' ); ?></td>
			<td><?php echo esc_html( wc_get_order_status_name( $lws_order->get_status() ) ); ?></td>
			<td><?php echo wp_kses_post( $lws_order->get_formatted_order_total() ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
