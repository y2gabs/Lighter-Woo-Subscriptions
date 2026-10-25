<?php
/**
 * End-to-end smoke test. Run on a disposable site with WooCommerce and this plugin active:
 *
 *   wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/smoke-test.php
 *
 * It creates products, customers and orders, so never run it on a live store.
 *
 * @package LighterWooSubscriptions
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'LWS_Plugin' ) ) {
	echo "Run with wp eval-file on a site with the plugin active.\n";
	return;
}

/**
 * Fake gateway adapter whose next results are queued by the test.
 */
class LWS_Test_Adapter implements LWS_Gateway_Adapter {
	public $queue = array();
	public $calls = 0;
	public function get_id() { return 'test'; }
	public function init() {}
	public function supports_gateway( $id ) { return 'lws_test' === $id; }
	public function get_payment_meta_keys() { return array( '_lws_test_token' ); }
	public function charge( WC_Order $order, LWS_Subscription $subscription ) {
		++$this->calls;
		$next = array_shift( $this->queue );
		return $next ? $next : array( 'result' => 'success', 'transaction_id' => 'txn_' . $order->get_id() );
	}
	public function get_customer_tokens( LWS_Subscription $s ) { return array(); }
	public function use_token( LWS_Subscription $s, WC_Payment_Token $t ) {}
	public function get_current_token( LWS_Subscription $s ) { return (string) $s->get_meta( '_lws_test_token' ); }
}

$GLOBALS['lws_failures'] = 0;
function lws_check( $label, $condition ) {
	echo ( $condition ? '  PASS ' : '  FAIL ' ) . $label . "\n";
	if ( ! $condition ) {
		++$GLOBALS['lws_failures'];
	}
}

// Same validation the add-to-cart form, AJAX handler and Store API run.
function lws_can_add( $product_id, $variation_id = 0 ) {
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, $variation_id, array() );
	wc_clear_notices();
	return $valid;
}

// Register the fake adapter in front of the real ones.
$lws_adapter = new LWS_Test_Adapter();
$ref         = new ReflectionProperty( 'LWS_Gateways', 'adapters' );
$ref->setAccessible( true );
$ref->setValue( null, array_merge( array( 'test' => $lws_adapter ), $ref->getValue() ) );

// Capture emails.
$lws_mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$lws_mails ) {
		$lws_mails[] = $atts['subject'];
		return true;
	},
	10,
	2
);

function lws_reload( $subscription ) {
	return lws_get_subscription( $subscription->get_id() );
}

function lws_has_action( $hook, $subscription ) {
	return (bool) as_next_scheduled_action( $hook, array( 'subscription_id' => $subscription->get_id() ), LWS_Scheduler::GROUP );
}

echo 'Storage: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts' ) . "\n";

echo "Date math\n";
$jan31 = gmmktime( 12, 0, 0, 1, 31, 2027 );
lws_check( 'Jan 31 + 1 month = Feb 28', '2027-02-28' === gmdate( 'Y-m-d', lws_add_periods( $jan31, 1, 'month' ) ) );
lws_check( 'Jan 31 + 13 months = Feb 29 2028', '2028-02-29' === gmdate( 'Y-m-d', lws_add_periods( $jan31, 13, 'month' ) ) );
lws_check( 'Jan 31 + 2 weeks', '2027-02-14' === gmdate( 'Y-m-d', lws_add_periods( $jan31, 2, 'week' ) ) );
lws_check( 'Schedule string', '/ month' === lws_get_schedule_string( 1, 'month' ) && 'every 3 months for 12 months' === lws_get_schedule_string( 3, 'month', 4 ) );

echo "Products\n";
$simple = new WC_Product_Simple();
$simple->set_name( 'LWS Monthly Box' );
$simple->set_regular_price( '10' );
$simple->update_meta_data( '_lws_subscription', 'yes' );
$simple->update_meta_data( '_lws_period', 'month' );
$simple->update_meta_data( '_lws_interval', 1 );
$simple->update_meta_data( '_lws_length', 0 );
$simple->save();

$variable = new WC_Product_Variable();
$variable->set_name( 'LWS Coffee' );
$variable->update_meta_data( '_lws_subscription', 'yes' );
$variable->update_meta_data( '_lws_period', 'month' );
$variable->update_meta_data( '_lws_interval', 1 );
$variable->update_meta_data( '_lws_length', 3 );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_attributes( array( 'size' => 'Large' ) );
$variation->set_regular_price( '25' );
$variation->update_meta_data( '_lws_period', 'week' );
$variation->update_meta_data( '_lws_interval', 2 );
$variation->update_meta_data( '_lws_length', '' );
$variation->save();

$plain = new WC_Product_Simple();
$plain->set_name( 'LWS Mug' );
$plain->set_regular_price( '5' );
$plain->save();

$vs = lws_get_product_schedule( $variation );
lws_check( 'Variation overrides period/interval', $vs && 'week' === $vs['period'] && 2 === $vs['interval'] );
lws_check( 'Variation inherits blank length', $vs && 3 === $vs['length'] );
lws_check( 'Plain product is not a subscription', ! lws_is_subscription_product( $plain ) );
lws_check( 'Price HTML shows schedule', false !== strpos( $simple->get_price_html(), '/ month' ) );

echo "Checkout and activation\n";
$user_id = wc_create_new_customer( 'lws-' . wp_generate_password( 6, false ) . '@example.com', '', wp_generate_password() );
$order   = wc_create_order( array( 'customer_id' => $user_id ) );
$order->add_product( $simple, 2 );
$order->add_product( $variation, 1 );
$order->set_address(
	array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'email'      => get_userdata( $user_id )->user_email,
		'country'    => 'US',
	),
	'billing'
);
$shipping = new WC_Order_Item_Shipping();
$shipping->set_props( array( 'method_title' => 'Flat rate', 'method_id' => 'flat_rate', 'total' => '4' ) );
$order->add_item( $shipping );
$order->set_payment_method( 'lws_test' );
$order->update_meta_data( '_lws_test_token', 'tok_123' );
$order->calculate_totals();
$order->save();

$subs = LWS_Checkout::create_subscriptions_for_order( $order );
lws_check( 'One subscription per schedule (2 created)', 2 === count( $subs ) );
$monthly = null;
$weekly  = null;
foreach ( $subs as $s ) {
	if ( 'month' === $s->get_billing_period() ) {
		$monthly = $s;
	} else {
		$weekly = $s;
	}
}
lws_check( 'Monthly sub total = 2 x 10 + shipping 4', $monthly && 24.0 === (float) $monthly->get_total() );
lws_check( 'Subscription is pending before payment', $monthly && $monthly->has_status( 'pending' ) );
lws_check( 'wc_get_order returns LWS_Subscription', wc_get_order( $monthly->get_id() ) instanceof LWS_Subscription );

// A retried checkout replaces pending subscriptions instead of duplicating them.
$subs = LWS_Checkout::create_subscriptions_for_order( $order );
lws_check( 'Re-processing checkout does not duplicate', 2 === count( lws_get_subscriptions_for_order( $order ) ) );
foreach ( $subs as $s ) {
	if ( 'month' === $s->get_billing_period() ) {
		$monthly = $s;
	} else {
		$weekly = $s;
	}
}

$order->payment_complete( 'txn_initial' );
$monthly = lws_reload( $monthly );
$weekly  = lws_reload( $weekly );
lws_check( 'Active after payment', $monthly->has_status( 'active' ) && $weekly->has_status( 'active' ) );
lws_check( 'Payment details copied', 'tok_123' === $monthly->get_meta( '_lws_test_token' ) );
lws_check( 'Next payment about one month out', abs( $monthly->get_date_timestamp( 'next_payment' ) - lws_add_periods( time(), 1, 'month' ) ) < 120 );
lws_check( 'Weekly next payment two weeks out', abs( $weekly->get_date_timestamp( 'next_payment' ) - ( time() + 14 * DAY_IN_SECONDS ) ) < 120 );
lws_check( 'Renewal action scheduled', lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) );
lws_check( 'Customer sees both subscriptions', 2 === count( lws_get_subscriptions_for_user( $user_id ) ) );
lws_check( 'No order email for subscription status change', ! in_array( true, array_map( function ( $s ) { return false !== stripos( $s, 'cancelled' ); }, $lws_mails ), true ) );

echo "Automatic renewal\n";
$monthly->set_date_timestamp( 'next_payment', time() - 10 );
$monthly->save();
$previous_due = $monthly->get_date_timestamp( 'next_payment' );
LWS_Scheduler::run_renewal( $monthly->get_id() );
$monthly = lws_reload( $monthly );
$renewal = wc_get_order( current( array_reverse( $monthly->get_renewal_order_ids() ) ) );
lws_check( 'Renewal order created and paid', $renewal && $renewal->is_paid() );
lws_check( 'Renewal total matches subscription', $renewal && (float) $renewal->get_total() === (float) $monthly->get_total() );
lws_check( 'Renewal linked to subscription', $renewal && lws_get_subscription_for_renewal( $renewal )->get_id() === $monthly->get_id() );
lws_check( 'Payment count = 2', 2 === $monthly->get_payment_count() );
lws_check( 'Next payment moved forward', $monthly->get_date_timestamp( 'next_payment' ) > time() );
lws_check( 'Still active', $monthly->has_status( 'active' ) );

echo "Duplicate run is ignored\n";
$calls = $lws_adapter->calls;
LWS_Scheduler::run_renewal( $monthly->get_id() ); // next payment is in the future now.
lws_check( 'No second charge', $calls === $lws_adapter->calls );

echo "Failure, retries and recovery\n";
$lws_adapter->queue = array(
	array( 'result' => 'failure', 'message' => 'Card declined' ),
	array( 'result' => 'failure', 'message' => 'Card declined' ),
);
$monthly->set_date_timestamp( 'next_payment', time() - 10 );
$monthly->save();
$mails_before = count( $lws_mails );
LWS_Scheduler::run_renewal( $monthly->get_id() );
$monthly = lws_reload( $monthly );
$failed  = $monthly->get_unpaid_renewal_order();
lws_check( 'On hold after failure', $monthly->has_status( 'on-hold' ) );
lws_check( 'Renewal order failed', $failed && $failed->has_status( 'failed' ) );
lws_check( 'Retry scheduled ~1 day out', abs( (int) LWS_Scheduler::get_next_retry( $monthly ) - ( time() + DAY_IN_SECONDS ) ) < 120 );
lws_check( 'Payment-failed email sent', count( $lws_mails ) > $mails_before );
lws_check( 'No renewal scheduled while on hold', ! lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) );

LWS_Scheduler::run_retry( $monthly->get_id() );
$monthly = lws_reload( $monthly );
lws_check( 'Second failure schedules retry 2 (~3 days)', abs( (int) LWS_Scheduler::get_next_retry( $monthly ) - ( time() + 3 * DAY_IN_SECONDS ) ) < 120 );

LWS_Scheduler::run_retry( $monthly->get_id() ); // queue empty -> success
$monthly = lws_reload( $monthly );
lws_check( 'Retry success reactivates', $monthly->has_status( 'active' ) );
lws_check( 'Retry cleared', false === LWS_Scheduler::get_next_retry( $monthly ) );
lws_check( 'Same renewal order reused (no extra order)', 2 === count( $monthly->get_renewal_order_ids() ) );
lws_check( 'Next payment in the future', $monthly->get_date_timestamp( 'next_payment' ) > time() );
lws_check( 'Renewal action rescheduled', lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) );

echo "Manual renewal (no saved card)\n";
$monthly->delete_meta_data( '_lws_test_token' );
$monthly->set_date_timestamp( 'next_payment', time() - 10 );
$monthly->save();
$mails_before = count( $lws_mails );
LWS_Scheduler::run_renewal( $monthly->get_id() );
$monthly = lws_reload( $monthly );
$invoice = $monthly->get_unpaid_renewal_order();
lws_check( 'Invoice order pending', $invoice && $invoice->has_status( 'pending' ) );
lws_check( 'Subscription on hold awaiting payment', $monthly->has_status( 'on-hold' ) );
lws_check( 'Invoice email sent', count( $lws_mails ) > $mails_before );
$invoice->set_payment_method( 'lws_test' );
$invoice->update_meta_data( '_lws_test_token', 'tok_new' );
$invoice->save();
$invoice->payment_complete( 'txn_manual' ); // customer paid via the pay link with a new card
$monthly = lws_reload( $monthly );
lws_check( 'Paying invoice reactivates', $monthly->has_status( 'active' ) );
lws_check( 'New card stored for future renewals', 'tok_new' === $monthly->get_meta( '_lws_test_token' ) );

echo "Pause / resume\n";
$monthly->update_meta_data( '_lws_paused_by_customer', 'yes' );
$monthly->update_status( 'on-hold' );
$monthly = lws_reload( $monthly );
lws_check( 'Paused: no renewal action', ! lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) );
lws_check( 'Resume offered to customer', array_key_exists( 'resume', LWS_My_Account::get_actions( $monthly ) ) );
$monthly->set_date_timestamp( 'next_payment', time() - DAY_IN_SECONDS ); // paused past the due date
$monthly->save();
$monthly->delete_meta_data( '_lws_paused_by_customer' );
$monthly->update_status( 'active' );
$monthly = lws_reload( $monthly );
lws_check( 'Resume moves next payment to the future', $monthly->get_date_timestamp( 'next_payment' ) > time() );
lws_check( 'Resume reschedules renewal', lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) );

echo "Cancel at period end\n";
$next = $monthly->get_date_timestamp( 'next_payment' );
$monthly->update_status( 'pending-cancel' );
$monthly = lws_reload( $monthly );
lws_check( 'End date = paid-through date', $next === $monthly->get_date_timestamp( 'end' ) );
lws_check( 'No further renewals', ! lws_has_action( LWS_Scheduler::HOOK_RENEWAL, $monthly ) && 0 === $monthly->get_date_timestamp( 'next_payment' ) );
lws_check( 'End action scheduled', lws_has_action( LWS_Scheduler::HOOK_END, $monthly ) );
lws_check( 'Admin cancellation email', in_array( true, array_map( function ( $s ) { return false !== stripos( $s, 'cancelled' ); }, $lws_mails ), true ) );
$monthly->update_status( 'active' ); // customer changes their mind
$monthly = lws_reload( $monthly );
lws_check( 'Reactivation restores billing date', $next === $monthly->get_date_timestamp( 'next_payment' ) && 0 === $monthly->get_date_timestamp( 'end' ) );
$monthly->update_status( 'pending-cancel' );
$monthly = lws_reload( $monthly );
$monthly->set_date_timestamp( 'end', time() - 10 );
$monthly->save();
LWS_Scheduler::run_end( $monthly->get_id() );
lws_check( 'Cancelled when period ends', lws_reload( $monthly )->has_status( 'cancelled' ) );

echo "Fixed length (variable product: 3 payments, every 2 weeks)\n";
for ( $i = 0; $i < 2; $i++ ) {
	$weekly->set_date_timestamp( 'next_payment', time() - 10 );
	$weekly->save();
	LWS_Scheduler::run_renewal( $weekly->get_id() );
	$weekly = lws_reload( $weekly );
}
lws_check( 'Three payments recorded', 3 === $weekly->get_payment_count() );
lws_check( 'No next payment after last one', 0 === $weekly->get_date_timestamp( 'next_payment' ) );
lws_check( 'End date set to end of paid period', $weekly->get_date_timestamp( 'end' ) > time() );
$weekly->set_date_timestamp( 'end', time() - 10 );
$weekly->save();
LWS_Scheduler::run_end( $weekly->get_id() );
lws_check( 'Expired', lws_reload( $weekly )->has_status( 'expired' ) );

echo "Cart rules\n";
WC()->frontend_includes();
if ( null === WC()->session ) {
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
}
if ( null === WC()->customer ) {
	WC()->customer = new WC_Customer( $user_id, true );
}
if ( null === WC()->cart ) {
	WC()->cart = new WC_Cart();
}
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $simple->get_id(), 1 );
lws_check( 'Subscription added to empty cart', 1 === count( WC()->cart->get_cart() ) );
lws_check( 'Same subscription can be added again', true === lws_can_add( $simple->get_id() ) );
lws_check( 'Cannot add a plain product to a subscription cart', false === lws_can_add( $plain->get_id() ) );
lws_check( 'Cannot mix schedules', false === lws_can_add( $variable->get_id(), $variation->get_id() ) );
lws_check( 'Recurring total = subtotal', 10.0 === LWS_Cart::get_recurring_total() );
lws_check( 'Account required at checkout', true === WC()->checkout()->is_registration_required() );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $plain->get_id(), 1 );
lws_check( 'Cannot add a subscription to a cart with other products', false === lws_can_add( $simple->get_id() ) );
WC()->cart->empty_cart();
wc_clear_notices();

echo "\n" . ( $GLOBALS['lws_failures'] ? $GLOBALS['lws_failures'] . " check(s) FAILED\n" : "All checks passed\n" );
