<?php
/**
 * Public helper functions.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription statuses, keyed with the `wc-` prefix used in storage.
 *
 * @return array<string,string>
 */
function lws_get_subscription_statuses() {
	return array(
		'wc-pending'        => _x( 'Pending', 'subscription status', 'lighter-woo-subscriptions' ),
		'wc-active'         => _x( 'Active', 'subscription status', 'lighter-woo-subscriptions' ),
		'wc-on-hold'        => _x( 'On hold', 'subscription status', 'lighter-woo-subscriptions' ),
		'wc-pending-cancel' => _x( 'Pending cancellation', 'subscription status', 'lighter-woo-subscriptions' ),
		'wc-cancelled'      => _x( 'Cancelled', 'subscription status', 'lighter-woo-subscriptions' ),
		'wc-expired'        => _x( 'Expired', 'subscription status', 'lighter-woo-subscriptions' ),
	);
}

/**
 * Human label for a subscription status.
 *
 * @param string $status Status with or without `wc-` prefix.
 * @return string
 */
function lws_get_status_label( $status ) {
	$statuses = lws_get_subscription_statuses();
	$key      = 'wc-' === substr( $status, 0, 3 ) ? $status : 'wc-' . $status;
	return isset( $statuses[ $key ] ) ? $statuses[ $key ] : $status;
}

/**
 * Billing periods.
 *
 * @return array<string,string>
 */
function lws_get_billing_periods() {
	return array(
		'day'   => __( 'Day', 'lighter-woo-subscriptions' ),
		'week'  => __( 'Week', 'lighter-woo-subscriptions' ),
		'month' => __( 'Month', 'lighter-woo-subscriptions' ),
		'year'  => __( 'Year', 'lighter-woo-subscriptions' ),
	);
}

/**
 * Gets a subscription object.
 *
 * @param int|LWS_Subscription|WP_Post $subscription Subscription or ID.
 * @return LWS_Subscription|false
 */
function lws_get_subscription( $subscription ) {
	if ( $subscription instanceof LWS_Subscription ) {
		return $subscription;
	}
	$object = wc_get_order( $subscription );
	return $object instanceof LWS_Subscription ? $object : false;
}

/**
 * Queries subscriptions. Accepts wc_get_orders() arguments.
 *
 * @param array $args Query arguments.
 * @return LWS_Subscription[]
 */
function lws_get_subscriptions( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'limit'   => -1,
			'status'  => array_keys( lws_get_subscription_statuses() ),
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);

	$args['type'] = LWS_Order_Type::TYPE;

	return array_values( array_filter( wc_get_orders( $args ), 'lws_get_subscription' ) );
}

/**
 * Subscriptions created from a checkout order.
 *
 * @param int|WC_Order $order Parent order.
 * @return LWS_Subscription[]
 */
function lws_get_subscriptions_for_order( $order ) {
	$order_id = $order instanceof WC_Order ? $order->get_id() : absint( $order );
	return $order_id ? lws_get_subscriptions( array( 'parent' => $order_id ) ) : array();
}

/**
 * Subscriptions owned by a customer.
 *
 * @param int $user_id User ID.
 * @return LWS_Subscription[]
 */
function lws_get_subscriptions_for_user( $user_id ) {
	return $user_id ? lws_get_subscriptions( array( 'customer_id' => absint( $user_id ) ) ) : array();
}

/**
 * The subscription a renewal order belongs to.
 *
 * @param WC_Order $order Order.
 * @return LWS_Subscription|false
 */
function lws_get_subscription_for_renewal( $order ) {
	if ( ! $order instanceof WC_Order || $order instanceof LWS_Subscription ) {
		return false;
	}
	$id = absint( $order->get_meta( '_lws_renewal_of' ) );
	return $id ? lws_get_subscription( $id ) : false;
}

/**
 * Whether an order is a subscription renewal.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function lws_is_renewal_order( $order ) {
	return $order instanceof WC_Order && ! $order instanceof LWS_Subscription && (bool) $order->get_meta( '_lws_renewal_of' );
}

/**
 * Whether an order created at least one subscription, or is a renewal.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function lws_order_has_subscription( $order ) {
	if ( ! $order instanceof WC_Order || $order instanceof LWS_Subscription ) {
		return false;
	}
	if ( lws_is_renewal_order( $order ) ) {
		return true;
	}
	foreach ( $order->get_items() as $item ) {
		if ( $item instanceof WC_Order_Item_Product && lws_is_subscription_product( $item->get_product() ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a product is sold as a subscription.
 *
 * @param WC_Product|int|false|null $product Product.
 * @return bool
 */
function lws_is_subscription_product( $product ) {
	return null !== lws_get_product_schedule( $product );
}

/**
 * Billing schedule of a subscription product. Variations inherit any value they leave blank from the parent.
 *
 * @param WC_Product|int|false|null $product Product.
 * @return array{period:string,interval:int,length:int}|null Null when the product is not a subscription.
 */
function lws_get_product_schedule( $product ) {
	if ( is_numeric( $product ) ) {
		$product = wc_get_product( $product );
	}
	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
	$source = $parent ? $parent : $product;

	if ( 'yes' !== $source->get_meta( '_lws_subscription' ) ) {
		return null;
	}

	$read = function ( $key, $fallback ) use ( $product, $parent ) {
		$value = $product->get_meta( $key );
		if ( '' === $value && $parent ) {
			$value = $parent->get_meta( $key );
		}
		return '' === $value ? $fallback : $value;
	};

	$period = (string) $read( '_lws_period', 'month' );
	if ( ! array_key_exists( $period, lws_get_billing_periods() ) ) {
		$period = 'month';
	}

	return array(
		'period'   => $period,
		'interval' => max( 1, absint( $read( '_lws_interval', 1 ) ) ),
		'length'   => absint( $read( '_lws_length', 0 ) ),
	);
}

/**
 * A stable key for grouping items that bill on the same schedule.
 *
 * @param array $schedule Schedule from lws_get_product_schedule().
 * @return string
 */
function lws_schedule_key( $schedule ) {
	return $schedule['interval'] . '_' . $schedule['period'] . '_' . $schedule['length'];
}

/**
 * Billing schedule text, e.g. "/ month", "every 3 months", "/ week for 12 weeks".
 *
 * @param int    $interval Interval.
 * @param string $period   Period.
 * @param int    $length   Number of periods, 0 for "until cancelled".
 * @return string
 */
function lws_get_schedule_string( $interval, $period, $length = 0 ) {
	$interval = max( 1, (int) $interval );
	$labels   = array(
		'day'   => array( __( 'day', 'lighter-woo-subscriptions' ), __( 'days', 'lighter-woo-subscriptions' ) ),
		'week'  => array( __( 'week', 'lighter-woo-subscriptions' ), __( 'weeks', 'lighter-woo-subscriptions' ) ),
		'month' => array( __( 'month', 'lighter-woo-subscriptions' ), __( 'months', 'lighter-woo-subscriptions' ) ),
		'year'  => array( __( 'year', 'lighter-woo-subscriptions' ), __( 'years', 'lighter-woo-subscriptions' ) ),
	);
	$label    = isset( $labels[ $period ] ) ? $labels[ $period ] : $labels['month'];

	if ( 1 === $interval ) {
		/* translators: %s: billing period, e.g. "month". */
		$string = sprintf( __( '/ %s', 'lighter-woo-subscriptions' ), $label[0] );
	} else {
		/* translators: 1: interval number, 2: plural billing period, e.g. "3 months". */
		$string = sprintf( __( 'every %1$d %2$s', 'lighter-woo-subscriptions' ), $interval, $label[1] );
	}

	if ( $length > 0 ) {
		$total = $length * $interval;
		/* translators: 1: number, 2: billing period (singular or plural). */
		$string .= ' ' . sprintf( __( 'for %1$d %2$s', 'lighter-woo-subscriptions' ), $total, 1 === $total ? $label[0] : $label[1] );
	}

	return apply_filters( 'lws_schedule_string', $string, $interval, $period, $length );
}

/**
 * Adds billing periods to a timestamp. Month and year steps keep the day of month,
 * clamped to the last day of shorter months (Jan 31 + 1 month = Feb 28/29).
 *
 * @param int    $timestamp UTC timestamp.
 * @param int    $interval  Number of periods.
 * @param string $period    day|week|month|year.
 * @return int
 */
function lws_add_periods( $timestamp, $interval, $period ) {
	$interval = max( 1, (int) $interval );
	$date     = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );

	switch ( $period ) {
		case 'day':
			return $date->modify( "+{$interval} days" )->getTimestamp();
		case 'week':
			return $date->modify( '+' . ( 7 * $interval ) . ' days' )->getTimestamp();
		case 'year':
			$months = 12 * $interval;
			break;
		default:
			$months = $interval;
	}

	$month_index = (int) $date->format( 'n' ) - 1 + $months; // 0-based months from January of the start year.
	$year        = (int) $date->format( 'Y' ) + intdiv( $month_index, 12 );
	$month       = $month_index % 12 + 1;
	$day         = min( (int) $date->format( 'j' ), lws_days_in_month( $month, $year ) );

	return $date->setDate( $year, $month, $day )->getTimestamp();
}

/**
 * Days in a month without requiring the calendar extension.
 *
 * @param int $month Month 1-12.
 * @param int $year  Year.
 * @return int
 */
function lws_days_in_month( $month, $year ) {
	return (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
}
