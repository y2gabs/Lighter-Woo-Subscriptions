<?php
/**
 * My Account > Subscriptions.
 *
 * Override by copying to yourtheme/woocommerce/myaccount/lws-subscriptions.php.
 *
 * @package LighterWooSubscriptions
 * @var LWS_Subscription[] $subscriptions
 */

defined( 'ABSPATH' ) || exit;

if ( ! $subscriptions ) : ?>
	<div class="woocommerce-info">
		<?php esc_html_e( 'You have no subscriptions yet.', 'lighter-woo-subscriptions' ); ?>
		<a class="button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Browse products', 'lighter-woo-subscriptions' ); ?></a>
	</div>
	<?php
	return;
endif;
?>
<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders lws-subscriptions-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Subscription', 'lighter-woo-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Status', 'lighter-woo-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Next payment', 'lighter-woo-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Total', 'lighter-woo-subscriptions' ); ?></th>
			<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lighter-woo-subscriptions' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $subscriptions as $lws_subscription ) : ?>
		<tr>
			<td data-title="<?php esc_attr_e( 'Subscription', 'lighter-woo-subscriptions' ); ?>">
				<a href="<?php echo esc_url( $lws_subscription->get_view_order_url() ); ?>">#<?php echo esc_html( $lws_subscription->get_id() ); ?></a>
			</td>
			<td data-title="<?php esc_attr_e( 'Status', 'lighter-woo-subscriptions' ); ?>"><?php echo esc_html( lws_get_status_label( $lws_subscription->get_status() ) ); ?></td>
			<td data-title="<?php esc_attr_e( 'Next payment', 'lighter-woo-subscriptions' ); ?>">
				<?php
				$lws_next = $lws_subscription->get_formatted_date( 'next_payment' );
				echo esc_html( $lws_next && $lws_subscription->has_status( 'active' ) ? $lws_next : '—' );
				?>
			</td>
			<td data-title="<?php esc_attr_e( 'Total', 'lighter-woo-subscriptions' ); ?>">
				<?php echo wp_kses_post( wc_price( $lws_subscription->get_total(), array( 'currency' => $lws_subscription->get_currency() ) ) ); ?>
				<?php echo esc_html( $lws_subscription->get_schedule_string() ); ?>
			</td>
			<td>
				<a class="woocommerce-button button view" href="<?php echo esc_url( $lws_subscription->get_view_order_url() ); ?>"><?php esc_html_e( 'View', 'lighter-woo-subscriptions' ); ?></a>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
