<?php
/**
 * Square adapter test. Needs the "WooCommerce Square" plugin active on a disposable site. The
 * plugin's API client is replaced with a mock returning real Square SDK objects, so no Square
 * account is needed:
 *
 *   wp eval-file wp-content/plugins/lighter-woo-subscriptions/tests/gateway-square-test.php
 *
 * WARNING: it creates products, users, saved cards and orders.
 *
 * @package LighterWooSubscriptions
 */

// phpcs:disable
if ( ! class_exists( 'LWS_Plugin' ) || ! class_exists( '\\WooCommerce\\Square\\Gateway' ) ) {
	echo "Activate WooCommerce, Lighter Woo Subscriptions and WooCommerce Square first.\n";
	return;
}
$GLOBALS['fails'] = 0;
function ok( $label, $c ) { echo ( $c ? '  PASS ' : '  FAIL ' ) . $label . "\n"; if ( ! $c ) $GLOBALS['fails']++; }

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways['square_credit_card'] ?? null;
echo 'Square gateway loaded: ' . ( $gateway ? get_class( $gateway ) : 'NO' ) . "\n";
if ( ! $gateway ) return;

class LWS_Mock_Square_API extends \WooCommerce\Square\Gateway\API {
	public $queue = array();
	public $seen  = array();
	public function credit_card_charge( \WC_Order $order ) { return $this->respond( $order ); }
	public function credit_card_authorization( \WC_Order $order ) { return $this->respond( $order ); }
	private function respond( $order ) {
		$this->seen[] = array( 'token' => $order->payment->token ?? null, 'customer' => $order->customer_id ?? null, 'total' => $order->payment_total ?? null, 'description' => $order->description ?? '' );
		$next = array_shift( $this->queue );
		$data = new \Square\Models\CreatePaymentResponse();
		if ( 'decline' === $next ) {
			$error = new \Square\Models\Error( 'PAYMENT_METHOD_ERROR', 'CARD_DECLINED' );
			$error->setDetail( 'Card declined.' );
			return new \WooCommerce\Square\Gateway\API\Responses\Create_Payment( array( $error ) );
		} else {
			$money = new \Square\Models\Money(); $money->setAmount( (int) round( $order->get_total() * 100 ) ); $money->setCurrency( 'USD' );
			$payment = new \Square\Models\Payment(); $payment->setId( 'sqpay_' . $order->get_id() ); $payment->setStatus( 'COMPLETED' ); $payment->setTotalMoney( $money ); $payment->setLocationId( 'LOC1' );
			$data->setPayment( $payment );
		}
		return new \WooCommerce\Square\Gateway\API\Responses\Create_Payment( $data );
	}
}
$mock = new LWS_Mock_Square_API( 'token', 'LOC1', true );
$prop = new ReflectionProperty( $gateway, 'api' );
$prop->setAccessible( true );
$prop->setValue( $gateway, $mock );

$product = new WC_Product_Simple();
$product->set_name( 'Square Sub' ); $product->set_regular_price( '12.50' );
$product->update_meta_data( '_lws_subscription', 'yes' ); $product->update_meta_data( '_lws_period', 'week' ); $product->update_meta_data( '_lws_interval', 1 );
$product->save();

$user_id = wc_create_new_customer( 'square' . wp_generate_password( 5, false ) . '@example.com', '', 'pw12345678' );
wp_set_current_user( $user_id );

// Tokenization is forced while paying for a subscription.
WC()->frontend_includes();
if ( ! WC()->session ) { WC()->session = new WC_Session_Handler(); WC()->session->init(); }
if ( ! WC()->customer ) { WC()->customer = new WC_Customer( $user_id, true ); }
if ( ! WC()->cart ) { WC()->cart = new WC_Cart(); }
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id() );
ok( 'Square tokenization forced with subscription in cart', true === $gateway->get_payment_tokens_handler()->tokenization_forced() );
ok( 'Square payment form tokenization forced', true === $gateway->get_payment_form_instance()->tokenization_forced() );
WC()->cart->empty_cart();

// Saved card, as the Square plugin stores it after checkout.
$handler = $gateway->get_payment_tokens_handler();
$token   = $handler->build_token( 'ccof:CARD123', array( 'type' => 'credit_card', 'card_type' => 'visa', 'last_four' => '4242', 'exp_month' => '12', 'exp_year' => '2030' ) );
$handler->add_token( $user_id, $token );
ok( 'Customer token stored', $handler->user_has_token( $user_id, 'ccof:CARD123' ) );

$order = wc_create_order( array( 'customer_id' => $user_id ) );
$order->add_product( $product, 1 );
$order->set_billing_email( get_userdata( $user_id )->user_email );
$order->set_payment_method( $gateway );
$order->calculate_totals();
$order->update_meta_data( '_wc_square_credit_card_payment_token', 'ccof:CARD123' );
$order->update_meta_data( '_wc_square_credit_card_customer_id', 'SQCUST1' );
$order->save();
LWS_Checkout::create_subscriptions_for_order( $order );
$order->payment_complete( 'sq_initial' );
$sub = current( lws_get_subscriptions_for_order( $order ) );
ok( 'Square card copied to subscription', 'ccof:CARD123' === $sub->get_meta( '_wc_square_credit_card_payment_token' ) && 'SQCUST1' === $sub->get_meta( '_wc_square_credit_card_customer_id' ) );
ok( 'Adapter is Square (automatic)', 'square' === LWS_Gateways::get_adapter_for( $sub )->get_id() );

echo "Successful renewal\n";
$sub->set_date_timestamp( 'next_payment', time() - 5 ); $sub->save();
LWS_Scheduler::run_renewal( $sub->get_id() );
$sub     = lws_get_subscription( $sub->get_id() );
$renewal = wc_get_order( current( $sub->get_renewal_order_ids() ) );
$seen    = end( $mock->seen );
ok( 'Charged saved card on file', $seen && 'ccof:CARD123' === $seen['token'] );
ok( 'Charged Square customer', $seen && 'SQCUST1' === $seen['customer'] );
ok( 'Renewal description set', $seen && false !== strpos( $seen['description'], 'Subscription renewal order' ) );
ok( 'Renewal paid', $renewal && $renewal->is_paid() );
ok( 'Square payment ID recorded', $renewal && 'sqpay_' . $renewal->get_id() === $renewal->get_transaction_id() );
ok( 'Subscription active, next date ahead', $sub->has_status( 'active' ) && $sub->get_date_timestamp( 'next_payment' ) > time() );

echo "Declined renewal\n";
$mock->queue[] = 'decline';
$sub->set_date_timestamp( 'next_payment', time() - 5 ); $sub->save();
LWS_Scheduler::run_renewal( $sub->get_id() );
$sub    = lws_get_subscription( $sub->get_id() );
$failed = $sub->get_unpaid_renewal_order();
ok( 'Renewal failed, subscription on hold, retry scheduled', $failed && $failed->has_status( 'failed' ) && $sub->has_status( 'on-hold' ) && LWS_Scheduler::get_next_retry( $sub ) );

echo "Retry succeeds\n";
LWS_Scheduler::run_retry( $sub->get_id() );
$sub = lws_get_subscription( $sub->get_id() );
ok( 'Reactivated after retry', $sub->has_status( 'active' ) && wc_get_order( $failed->get_id() )->is_paid() );

echo "Change card from My Account\n";
$token2 = $handler->build_token( 'ccof:CARD999', array( 'type' => 'credit_card', 'card_type' => 'mastercard', 'last_four' => '4444', 'exp_month' => '01', 'exp_year' => '2031' ) );
$handler->add_token( $user_id, $token2 );
// The card would be added in an earlier request; drop Square's per-request token cache to match.
$cache = new ReflectionProperty( $handler, 'tokens' ); $cache->setAccessible( true ); $cache->setValue( $handler, null ); $handler->clear_transient( $user_id );
$adapter = LWS_Gateways::get_adapter( 'square_credit_card' );
$tokens  = $adapter->get_customer_tokens( $sub );
ok( 'Both saved cards offered', 2 === count( $tokens ) );
foreach ( $tokens as $t ) { if ( 'ccof:CARD999' === $t->get_token() ) { $adapter->use_token( $sub, $t ); } }
$sub->save();
$sub->set_date_timestamp( 'next_payment', time() - 5 ); $sub->save();
LWS_Scheduler::run_renewal( $sub->get_id() );
$sub = lws_get_subscription( $sub->get_id() );
$seen = end( $mock->seen );
ok( 'Next renewal uses the new card', 'ccof:CARD999' === $seen['token'] );

echo "\n" . ( $GLOBALS['fails'] ? $GLOBALS['fails'] . " FAILED\n" : "All Square checks passed\n" );
