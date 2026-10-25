<?php
/**
 * Creates subscriptions from checkout orders and activates them once paid.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout integration (classic and block checkout).
 */
class LWS_Checkout {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_order_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'create_subscriptions_for_order' ) );

		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'order_cancelled' ) );
	}

	/**
	 * Classic checkout hook adapter.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public static function classic_order_processed( $order_id, $posted = array(), $order = null ) {
		self::create_subscriptions_for_order( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/**
	 * Creates one subscription per billing schedule in the order. If the customer retries a failed
	 * checkout, WooCommerce reuses the order, so pending subscriptions from the earlier attempt are
	 * replaced to match the current items.
	 *
	 * @param WC_Order $order Order.
	 * @return LWS_Subscription[]
	 */
	public static function create_subscriptions_for_order( $order ) {
		if ( ! $order instanceof WC_Order || $order instanceof LWS_Subscription || lws_is_renewal_order( $order ) ) {
			return array();
		}

		foreach ( lws_get_subscriptions_for_order( $order ) as $existing ) {
			if ( ! $existing->has_status( 'pending' ) ) {
				return array(); // Already activated; never duplicate.
			}
			$existing->delete( true );
		}

		$groups = array();
		foreach ( $order->get_items() as $item ) {
			$schedule = $item instanceof WC_Order_Item_Product ? lws_get_product_schedule( $item->get_product() ) : null;
			if ( $schedule ) {
				$key                        = lws_schedule_key( $schedule );
				$groups[ $key ]['schedule'] = $schedule;
				$groups[ $key ]['items'][]  = $item;
			}
		}

		$subscriptions = array();
		foreach ( $groups as $group ) {
			$subscriptions[] = self::create_subscription( $order, $group['schedule'], $group['items'] );
		}

		return $subscriptions;
	}

	/**
	 * Builds and saves one subscription.
	 *
	 * @param WC_Order                $order    Parent order.
	 * @param array                   $schedule Schedule.
	 * @param WC_Order_Item_Product[] $items    Items on that schedule.
	 * @return LWS_Subscription
	 */
	private static function create_subscription( WC_Order $order, array $schedule, array $items ) {
		$subscription = new LWS_Subscription();
		$subscription->set_parent_id( $order->get_id() );
		$subscription->set_customer_id( $order->get_customer_id() );
		$subscription->set_currency( $order->get_currency() );
		$subscription->set_prices_include_tax( $order->get_prices_include_tax() );
		$subscription->set_address( $order->get_address( 'billing' ), 'billing' );
		$subscription->set_address( $order->get_address( 'shipping' ), 'shipping' );
		$subscription->set_payment_method( $order->get_payment_method() );
		$subscription->set_payment_method_title( $order->get_payment_method_title() );
		$subscription->set_created_via( 'checkout' );
		$subscription->set_schedule( $schedule['period'], $schedule['interval'], $schedule['length'] );
		$subscription->set_status( 'pending' );

		foreach ( $items as $item ) {
			$subscription->add_item( self::copy_product_item( $item ) );
		}

		// Renewals ship like the first order. Mixed carts are not allowed, so all shipping belongs to the subscription.
		if ( apply_filters( 'lws_subscription_include_shipping', true, $order ) ) {
			foreach ( $order->get_items( 'shipping' ) as $shipping ) {
				$subscription->add_item( LWS_Renewal::copy_shipping_item( $shipping ) );
			}
		}

		$subscription->update_taxes();
		$subscription->calculate_totals( false );
		$subscription->save();

		$order->add_order_note(
			sprintf(
				/* translators: %d: subscription ID. */
				__( 'Subscription #%d created.', 'lighter-woo-subscriptions' ),
				$subscription->get_id()
			)
		);

		do_action( 'lws_subscription_created', $subscription, $order );

		return $subscription;
	}

	/**
	 * Copies a product line at its pre-discount price, so first-order coupons do not repeat.
	 *
	 * @param WC_Order_Item_Product $item Source item.
	 * @return WC_Order_Item_Product
	 */
	public static function copy_product_item( WC_Order_Item_Product $item ) {
		$taxes = $item->get_taxes();
		$copy  = new WC_Order_Item_Product();
		$copy->set_props(
			array(
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'tax_class'    => $item->get_tax_class(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_subtotal(),
				'taxes'        => array(
					'subtotal' => $taxes['subtotal'],
					'total'    => $taxes['subtotal'],
				),
			)
		);
		foreach ( $item->get_meta_data() as $meta ) {
			$copy->add_meta_data( $meta->key, $meta->value );
		}
		return $copy;
	}

	/**
	 * Activates pending subscriptions when their checkout order is paid.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order instanceof LWS_Subscription || lws_is_renewal_order( $order ) ) {
			return;
		}

		foreach ( lws_get_subscriptions_for_order( $order ) as $subscription ) {
			// Status hooks can run before the gateway has stored its card reference, so copy again later if needed.
			if ( $subscription->has_status( array( 'active', 'on-hold' ) ) && ! LWS_Gateways::has_payment_details( $subscription ) ) {
				LWS_Gateways::copy_payment_details( $order, $subscription );
				$subscription->save();
			}

			if ( ! $subscription->has_status( 'pending' ) ) {
				continue;
			}

			$now = time();
			LWS_Gateways::copy_payment_details( $order, $subscription );
			$subscription->set_date_timestamp( 'start', $now );
			$subscription->set_date_timestamp( 'last_payment', $now );
			$subscription->update_meta_data( '_lws_payment_count', 1 );
			LWS_Scheduler::set_next_dates( $subscription );
			$subscription->save();
			$subscription->update_status( 'active', __( 'Initial payment received.', 'lighter-woo-subscriptions' ) );
		}
	}

	/**
	 * Cancels never-activated subscriptions when the checkout order is cancelled.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function order_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || lws_is_renewal_order( $order ) ) {
			return;
		}
		foreach ( lws_get_subscriptions_for_order( $order ) as $subscription ) {
			if ( $subscription->has_status( 'pending' ) ) {
				$subscription->update_status( 'cancelled', __( 'Checkout order was cancelled.', 'lighter-woo-subscriptions' ) );
			}
		}
	}
}
