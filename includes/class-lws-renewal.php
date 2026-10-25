<?php
/**
 * Renewal orders, charging and retries.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renewal processing.
 */
class LWS_Renewal {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'renewal_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'renewal_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'renewal_paid' ) );
	}

	/**
	 * Retry delays in days after the first, second and third failed attempt.
	 *
	 * @return int[]
	 */
	public static function get_retry_intervals() {
		return array_map( 'absint', (array) apply_filters( 'lws_retry_intervals', array( 1, 3, 5 ) ) );
	}

	/**
	 * Called by the scheduler when a renewal is due.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function process_scheduled_renewal( LWS_Subscription $subscription ) {
		$due = $subscription->get_date_timestamp( 'next_payment' );
		if ( ! $due ) {
			return;
		}
		if ( $due > time() + 5 * MINUTE_IN_SECONDS ) {
			LWS_Scheduler::schedule( $subscription ); // Date moved later since this action was queued.
			return;
		}
		if ( ! self::lock( $subscription ) ) {
			return;
		}

		try {
			// The subscription may have changed while waiting for the lock.
			$subscription = lws_get_subscription( $subscription->get_id() );
			if ( ! $subscription || ! $subscription->has_status( 'active' ) || $subscription->get_date_timestamp( 'next_payment' ) !== $due ) {
				return;
			}

			$order = self::create_renewal_order( $subscription );
			if ( is_wp_error( $order ) ) {
				$subscription->add_order_note( $order->get_error_message() );
				$subscription->update_status( 'on-hold', __( 'Could not create renewal order.', 'lighter-woo-subscriptions' ) );
				return;
			}

			$subscription->update_meta_data( '_lws_retry_count', 0 );
			$subscription->save();

			self::charge( $subscription, $order );
		} finally {
			self::unlock( $subscription );
		}
	}

	/**
	 * Called by the scheduler to retry the unpaid renewal order.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function retry( LWS_Subscription $subscription ) {
		$order = $subscription->get_unpaid_renewal_order();
		if ( ! $order || ! self::lock( $subscription ) ) {
			return;
		}

		try {
			$order->add_order_note( __( 'Retrying renewal payment.', 'lighter-woo-subscriptions' ) );
			if ( ! $order->has_status( 'pending' ) ) {
				$order->set_status( 'pending' );
				$order->save();
			}
			self::charge( $subscription, $order );
		} finally {
			self::unlock( $subscription );
		}
	}

	/**
	 * Creates a pending renewal order copying the subscription's items, shipping and addresses.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return WC_Order|WP_Error
	 */
	public static function create_renewal_order( LWS_Subscription $subscription ) {
		try {
			$order = new WC_Order();
			$order->set_customer_id( $subscription->get_customer_id() );
			$order->set_currency( $subscription->get_currency() );
			$order->set_prices_include_tax( $subscription->get_prices_include_tax() );
			$order->set_address( $subscription->get_address( 'billing' ), 'billing' );
			$order->set_address( $subscription->get_address( 'shipping' ), 'shipping' );
			$order->set_payment_method( $subscription->get_payment_method() );
			$order->set_payment_method_title( $subscription->get_payment_method_title() );
			$order->set_created_via( 'lws_renewal' );
			$order->update_meta_data( '_lws_renewal_of', $subscription->get_id() );

			foreach ( $subscription->get_items() as $item ) {
				$copy = LWS_Checkout::copy_product_item( $item );
				$copy->set_total( $item->get_total() );
				$copy->set_taxes( $item->get_taxes() );
				$order->add_item( $copy );
			}
			foreach ( $subscription->get_items( 'shipping' ) as $shipping ) {
				$order->add_item( self::copy_shipping_item( $shipping ) );
			}

			$order->update_taxes();
			$order->calculate_totals( false );
			$order->set_status( 'pending' );

			$order = apply_filters( 'lws_renewal_order_before_save', $order, $subscription );
			$order->save();

			LWS_Gateways::copy_payment_details( $subscription, $order );
			$order->add_order_note(
				sprintf(
					/* translators: %d: subscription ID. */
					__( 'Renewal order for subscription #%d.', 'lighter-woo-subscriptions' ),
					$subscription->get_id()
				)
			);
			$order->save();

			$subscription->add_renewal_order_id( $order->get_id() );
			$subscription->save();

			do_action( 'lws_renewal_order_created', $order, $subscription );

			return $order;
		} catch ( Exception $e ) {
			return new WP_Error( 'lws_renewal_order', $e->getMessage() );
		}
	}

	/**
	 * Copies a shipping line.
	 *
	 * @param WC_Order_Item_Shipping $shipping Source.
	 * @return WC_Order_Item_Shipping
	 */
	public static function copy_shipping_item( WC_Order_Item_Shipping $shipping ) {
		$copy = new WC_Order_Item_Shipping();
		$copy->set_props(
			array(
				'method_title' => $shipping->get_method_title(),
				'method_id'    => $shipping->get_method_id(),
				'instance_id'  => $shipping->get_instance_id(),
				'total'        => $shipping->get_total(),
				'taxes'        => $shipping->get_taxes(),
			)
		);
		foreach ( $shipping->get_meta_data() as $meta ) {
			$copy->add_meta_data( $meta->key, $meta->value );
		}
		return $copy;
	}

	/**
	 * Charges a renewal order through the subscription's gateway adapter.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Order         $order        Renewal order.
	 */
	public static function charge( LWS_Subscription $subscription, WC_Order $order ) {
		// Always charge the card currently on the subscription (the customer may have changed it).
		LWS_Gateways::copy_payment_details( $subscription, $order );
		$order->save();

		if ( $order->get_total() <= 0 ) {
			$order->payment_complete();
			return;
		}

		$adapter = LWS_Gateways::get_adapter_for( $subscription );
		$result  = $adapter->charge( $order, $subscription );

		$order = wc_get_order( $order->get_id() ); // Gateways update their own copy.
		if ( ! $order ) {
			return;
		}

		switch ( $result['result'] ) {
			case 'success':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( isset( $result['transaction_id'] ) ? $result['transaction_id'] : '' );
				}
				break;

			case 'pending':
				// Payment is processing (e.g. bank debit) or awaits the customer; the gateway's webhook completes it.
				if ( ! empty( $result['message'] ) ) {
					$order->add_order_note( $result['message'] );
				}
				if ( 'manual' === $adapter->get_id() ) {
					$subscription->update_status( 'on-hold', __( 'Awaiting manual renewal payment.', 'lighter-woo-subscriptions' ) );
					do_action( 'lws_renewal_invoice', $order, $subscription );
				}
				break;

			default:
				self::payment_failed( $subscription, $order, isset( $result['message'] ) ? $result['message'] : '' );
		}
	}

	/**
	 * Handles a failed charge: put the subscription on hold and schedule the next retry.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param WC_Order         $order        Renewal order.
	 * @param string           $message      Gateway message.
	 */
	public static function payment_failed( LWS_Subscription $subscription, WC_Order $order, $message ) {
		$message = $message ? $message : __( 'The payment was declined.', 'lighter-woo-subscriptions' );

		if ( ! $order->has_status( 'failed' ) ) {
			/* translators: %s: gateway message. */
			$order->update_status( 'failed', sprintf( __( 'Renewal payment failed: %s', 'lighter-woo-subscriptions' ), $message ) );
		}

		$subscription = lws_get_subscription( $subscription->get_id() );
		$attempt      = absint( $subscription->get_meta( '_lws_retry_count' ) );
		$intervals    = self::get_retry_intervals();
		$final        = $attempt >= count( $intervals );

		$subscription->update_meta_data( '_lws_retry_count', $attempt + 1 );
		$subscription->save();

		if ( $final ) {
			$subscription->add_order_note( __( 'All payment retries failed. Waiting for the customer to pay the renewal order.', 'lighter-woo-subscriptions' ) );
		} else {
			$when = time() + $intervals[ $attempt ] * DAY_IN_SECONDS;
			LWS_Scheduler::schedule_retry( $subscription, $when );
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: date, 2: attempt number. */
					__( 'Renewal payment failed. Retry %2$d scheduled for %1$s.', 'lighter-woo-subscriptions' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ),
					$attempt + 1
				)
			);
		}

		if ( ! $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold', __( 'Renewal payment failed.', 'lighter-woo-subscriptions' ) );
		}

		if ( 0 === $attempt || $final ) {
			do_action( 'lws_renewal_payment_failed', $order, $subscription, $final );
		}
	}

	/**
	 * When a renewal order is paid (automatically, by retry, or by the customer via the pay link),
	 * move the subscription forward and reactivate it if it was on hold.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function renewal_paid( $order_id ) {
		$order        = wc_get_order( $order_id );
		$subscription = $order ? lws_get_subscription_for_renewal( $order ) : false;
		if ( ! $subscription || 'yes' === $order->get_meta( '_lws_renewal_applied' ) ) {
			return;
		}

		$order->update_meta_data( '_lws_renewal_applied', 'yes' );
		$order->save();

		LWS_Scheduler::unschedule_retry( $subscription );
		if ( $order->get_payment_method() === $subscription->get_payment_method() || ! $subscription->get_payment_method() ) {
			LWS_Gateways::copy_payment_details( $order, $subscription );
		} elseif ( $order->get_payment_method() ) {
			// Paid with a different gateway via the pay link: future renewals use it.
			$subscription->set_payment_method( $order->get_payment_method() );
			$subscription->set_payment_method_title( $order->get_payment_method_title() );
			LWS_Gateways::copy_payment_details( $order, $subscription );
		}

		$subscription->increment_payment_count();
		$subscription->set_date_timestamp( 'last_payment', time() );
		$subscription->update_meta_data( '_lws_retry_count', 0 );

		if ( $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
			LWS_Scheduler::set_next_dates( $subscription );
		}
		$subscription->save();

		/* translators: %d: order ID. */
		$subscription->add_order_note( sprintf( __( 'Renewal order #%d paid.', 'lighter-woo-subscriptions' ), $order->get_id() ) );

		if ( $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'active', __( 'Renewal payment received.', 'lighter-woo-subscriptions' ) );
		} else {
			LWS_Scheduler::schedule( $subscription );
		}

		do_action( 'lws_renewal_paid', $order, $subscription );
	}

	/**
	 * Takes a short-lived lock so a subscription is never charged twice in parallel.
	 * add_option() is atomic because option names are unique in the database.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return bool
	 */
	private static function lock( LWS_Subscription $subscription ) {
		$name = 'lws_lock_' . $subscription->get_id();
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $name );
		if ( $since && $since < time() - 10 * MINUTE_IN_SECONDS ) {
			delete_option( $name ); // Stale lock from a crashed request.
			return add_option( $name, time(), '', false );
		}
		return false;
	}

	/**
	 * Releases the lock.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	private static function unlock( LWS_Subscription $subscription ) {
		delete_option( 'lws_lock_' . $subscription->get_id() );
	}
}
