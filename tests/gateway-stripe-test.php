<?php
/**
 * Stripe adapter test. Needs the "WooCommerce Stripe Payment Gateway" plugin active on a disposable
 * site. api.stripe.com is mocked, so no keys or network access are needed:
 *
 *   wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/gateway-stripe-test.php
 *
 * WARNING: it writes Stripe test settings and creates products, users and orders.
 *
 * @package LighterWooSubscriptions
 */

// phpcs:disable
if ( ! class_exists( 'LWS_Plugin' ) || ! class_exists( 'WC_Stripe_API' ) ) {
	echo "Activate WooCommerce, Lighter Woo Subscriptions and WooCommerce Stripe Gateway first.\n";
	return;
}
$GLOBALS['fails'] = 0;
function ok( $label, $c ) { echo ( $c ? '  PASS ' : '  FAIL ' ) . $label . "\n"; if ( ! $c ) $GLOBALS['fails']++; }

update_option( 'woocommerce_stripe_settings', array_merge( (array) get_option( 'woocommerce_stripe_settings', array() ), array(
	'enabled' => 'yes', 'testmode' => 'yes', 'test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_fake', 'saved_cards' => 'yes',
) ) );
if ( class_exists( 'WC_Stripe_API' ) ) { WC_Stripe_API::set_secret_key( 'sk_test_fake' ); }

$GLOBALS['stripe_requests'] = array();
$GLOBALS['stripe_next']     = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'api.stripe.com' ) ) return $pre;
	$GLOBALS['stripe_requests'][] = array( 'url' => $url, 'body' => $args['body'], 'headers' => $args['headers'] );
	$next = array_shift( $GLOBALS['stripe_next'] );
	return array( 'headers' => array(), 'body' => wp_json_encode( $next['body'] ), 'response' => array( 'code' => $next['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

echo "Stripe plugin " . WC_STRIPE_VERSION . "\n";

$product = new WC_Product_Simple();
$product->set_name( 'Stripe Sub' ); $product->set_regular_price( '19.99' );
$product->update_meta_data( '_lws_subscription', 'yes' ); $product->update_meta_data( '_lws_period', 'month' ); $product->update_meta_data( '_lws_interval', 1 );
$product->save();

$user_id = wc_create_new_customer( 'stripe' . wp_generate_password( 5, false ) . '@example.com', '', 'pw12345678' );
update_user_option( $user_id, '_stripe_customer_id', 'cus_TEST', false );
wp_set_current_user( $user_id );

$order = wc_create_order( array( 'customer_id' => $user_id ) );
$order->add_product( $product, 1 );
$order->set_billing_email( get_userdata( $user_id )->user_email );
$order->set_payment_method( 'stripe' );
$order->calculate_totals();
$order->save();
LWS_Checkout::create_subscriptions_for_order( $order );

ok( 'Stripe force-save filter returns true for subscription order', true === WC_Stripe_Helper::should_force_save_payment_method( false, $order->get_id() ) );

// The Stripe plugin stores these during checkout payment.
$order->update_meta_data( '_stripe_customer_id', 'cus_TEST' );
$order->update_meta_data( '_stripe_source_id', 'pm_TEST' );
$order->save();
$order->payment_complete( 'ch_initial' );

$sub = current( lws_get_subscriptions_for_order( $order ) );
ok( 'Subscription active', $sub->has_status( 'active' ) );
ok( 'Stripe IDs copied to subscription', 'pm_TEST' === $sub->get_meta( '_stripe_source_id' ) && 'cus_TEST' === $sub->get_meta( '_stripe_customer_id' ) );
ok( 'Adapter is Stripe (automatic)', 'stripe' === LWS_Gateways::get_adapter_for( $sub )->get_id() );

echo "Successful renewal\n";
$GLOBALS['stripe_next'][] = array( 'code' => 200, 'body' => array( 'id' => 'pi_OK', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => 'ch_OK', 'amount' => 1999, 'currency' => 'usd' ) );
$sub->set_date_timestamp( 'next_payment', time() - 5 ); $sub->save();
LWS_Scheduler::run_renewal( $sub->get_id() );
$sub     = lws_get_subscription( $sub->get_id() );
$renewal = wc_get_order( current( $sub->get_renewal_order_ids() ) );
$req     = end( $GLOBALS['stripe_requests'] );
ok( 'POST to /v1/payment_intents', false !== strpos( $req['url'], '/v1/payment_intents' ) );
ok( 'Amount in cents', 1999 === (int) $req['body']['amount'] && 'usd' === $req['body']['currency'] );
ok( 'Off-session confirm with saved card', 'true' === $req['body']['off_session'] && 'true' === $req['body']['confirm'] && 'pm_TEST' === $req['body']['payment_method'] && 'cus_TEST' === $req['body']['customer'] );
ok( 'Secret key used', 'Basic ' . base64_encode( 'sk_test_fake:' ) === $req['headers']['Authorization'] );
ok( 'Idempotency key sent', ! empty( $req['headers']['Idempotency-Key'] ) );
ok( 'Renewal paid with charge ID', $renewal->is_paid() && 'ch_OK' === $renewal->get_transaction_id() );
ok( 'Intent ID stored for Stripe webhooks/refunds', 'pi_OK' === $renewal->get_meta( '_stripe_intent_id' ) );
ok( 'Subscription still active, next date ahead', $sub->has_status( 'active' ) && $sub->get_date_timestamp( 'next_payment' ) > time() );

echo "Declined renewal\n";
$GLOBALS['stripe_next'][] = array( 'code' => 402, 'body' => array( 'error' => array( 'type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.', 'payment_intent' => array( 'id' => 'pi_DECLINED', 'status' => 'requires_payment_method' ) ) ) );
$sub->set_date_timestamp( 'next_payment', time() - 5 ); $sub->save();
LWS_Scheduler::run_renewal( $sub->get_id() );
$sub    = lws_get_subscription( $sub->get_id() );
$failed = $sub->get_unpaid_renewal_order();
ok( 'Renewal failed, subscription on hold', $failed && $failed->has_status( 'failed' ) && $sub->has_status( 'on-hold' ) );
ok( 'Decline message recorded', (bool) array_filter( wc_get_order_notes( array( 'order_id' => $failed->get_id() ) ), function ( $n ) { return false !== strpos( $n->content, 'Your card was declined.' ); } ) );
ok( 'Retry scheduled', (bool) LWS_Scheduler::get_next_retry( $sub ) );

echo "3-D Secure required on retry\n";
$GLOBALS['stripe_next'][] = array( 'code' => 402, 'body' => array( 'error' => array( 'type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Authentication required', 'payment_intent' => array( 'id' => 'pi_3DS', 'status' => 'requires_action' ) ) ) );
LWS_Scheduler::run_retry( $sub->get_id() );
$sub = lws_get_subscription( $sub->get_id() );
ok( 'Still on hold, second retry scheduled', $sub->has_status( 'on-hold' ) && 2 === (int) $sub->get_meta( '_lws_retry_count' ) );

echo "Customer pays via pay link (Stripe webhook-style completion)\n";
$failed = $sub->get_unpaid_renewal_order();
$failed->update_meta_data( '_stripe_source_id', 'pm_NEWCARD' );
$failed->save();
$failed->payment_complete( 'ch_PAYLINK' );
$sub = lws_get_subscription( $sub->get_id() );
ok( 'Reactivated', $sub->has_status( 'active' ) );
ok( 'New card used for future renewals', 'pm_NEWCARD' === $sub->get_meta( '_stripe_source_id' ) );
ok( 'Retry cancelled', false === LWS_Scheduler::get_next_retry( $sub ) );

echo "\n" . ( $GLOBALS['fails'] ? $GLOBALS['fails'] . " FAILED\n" : "All Stripe checks passed\n" );
