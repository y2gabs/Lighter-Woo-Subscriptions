<?php
/**
 * Subscription object.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * A subscription is stored as its own order type, so it reuses WooCommerce's order storage
 * (posts or HPOS tables), line items, addresses, taxes and admin edit screen. Schedule data
 * lives in order meta.
 */
class LWS_Subscription extends WC_Order {

	/**
	 * Allowed status changes. Admins may still force any status from the edit screen.
	 *
	 * @var array<string,string[]>
	 */
	const TRANSITIONS = array(
		'pending'        => array( 'active', 'on-hold', 'cancelled' ),
		'active'         => array( 'on-hold', 'pending-cancel', 'cancelled', 'expired' ),
		'on-hold'        => array( 'active', 'pending-cancel', 'cancelled', 'expired' ),
		'pending-cancel' => array( 'active', 'cancelled' ),
		'cancelled'      => array(),
		'expired'        => array(),
	);

	/**
	 * Order type.
	 *
	 * @return string
	 */
	public function get_type() {
		return LWS_Order_Type::TYPE;
	}

	/**
	 * Statuses a subscription may have.
	 *
	 * @return string[]
	 */
	protected function get_valid_statuses() {
		return array_keys( lws_get_subscription_statuses() );
	}

	/**
	 * Fires subscription status hooks instead of the order ones, so order emails, stock
	 * changes and reports never react to a subscription changing status.
	 */
	protected function status_transition() {
		$transition              = $this->status_transition;
		$this->status_transition = false;

		if ( ! $transition || empty( $transition['from'] ) || $transition['from'] === $transition['to'] ) {
			return;
		}

		$from = $transition['from'];
		$to   = $transition['to'];

		$note = sprintf(
			/* translators: 1: old status, 2: new status. */
			__( 'Subscription status changed from %1$s to %2$s.', 'lighter-woo-subscriptions' ),
			lws_get_status_label( $from ),
			lws_get_status_label( $to )
		);
		if ( ! empty( $transition['note'] ) ) {
			$note = $transition['note'] . ' ' . $note;
		}
		$this->add_order_note( trim( $note ), 0, ! empty( $transition['manual'] ) );

		do_action( 'lws_subscription_status_' . $to, $this, $from );
		do_action( 'lws_subscription_status_' . $from . '_to_' . $to, $this );
		do_action( 'lws_subscription_status_changed', $this, $from, $to );
	}

	/**
	 * Whether the status may move to $status.
	 *
	 * @param string $status Target status without prefix.
	 * @return bool
	 */
	public function can_be_updated_to( $status ) {
		$current = $this->get_status();
		return isset( self::TRANSITIONS[ $current ] ) && in_array( $status, self::TRANSITIONS[ $current ], true );
	}

	/**
	 * Subscriptions stay editable by admins until they end.
	 *
	 * @return bool
	 */
	public function is_editable() {
		return ! $this->has_status( array( 'cancelled', 'expired' ) );
	}

	/**
	 * A subscription itself is never paid for; its renewal orders are.
	 *
	 * @return bool
	 */
	public function needs_payment() {
		return false;
	}

	/**
	 * Customer-facing URL.
	 *
	 * @return string
	 */
	public function get_view_order_url() {
		return wc_get_endpoint_url( LWS_My_Account::ENDPOINT_VIEW, $this->get_id(), wc_get_page_permalink( 'myaccount' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Schedule data
	|--------------------------------------------------------------------------
	*/

	/**
	 * Billing period.
	 *
	 * @return string day|week|month|year
	 */
	public function get_billing_period() {
		$period = (string) $this->get_meta( '_lws_billing_period' );
		return array_key_exists( $period, lws_get_billing_periods() ) ? $period : 'month';
	}

	/**
	 * Billing interval.
	 *
	 * @return int
	 */
	public function get_billing_interval() {
		return max( 1, absint( $this->get_meta( '_lws_billing_interval' ) ) );
	}

	/**
	 * Total number of payments, including the first. 0 = until cancelled.
	 *
	 * @return int
	 */
	public function get_length() {
		return absint( $this->get_meta( '_lws_length' ) );
	}

	/**
	 * Sets the billing schedule.
	 *
	 * @param string $period   Period.
	 * @param int    $interval Interval.
	 * @param int    $length   Number of payments, 0 for unlimited.
	 */
	public function set_schedule( $period, $interval, $length = 0 ) {
		$this->update_meta_data( '_lws_billing_period', array_key_exists( $period, lws_get_billing_periods() ) ? $period : 'month' );
		$this->update_meta_data( '_lws_billing_interval', max( 1, absint( $interval ) ) );
		$this->update_meta_data( '_lws_length', absint( $length ) );
	}

	/**
	 * Schedule text, e.g. "/ month".
	 *
	 * @return string
	 */
	public function get_schedule_string() {
		return lws_get_schedule_string( $this->get_billing_interval(), $this->get_billing_period(), $this->get_length() );
	}

	/**
	 * Gets a stored date as a UTC timestamp.
	 *
	 * @param string $type start|next_payment|end|last_payment.
	 * @return int 0 when unset.
	 */
	public function get_date_timestamp( $type ) {
		return absint( $this->get_meta( '_lws_' . $type . '_date' ) );
	}

	/**
	 * Sets a stored date.
	 *
	 * @param string $type      start|next_payment|end|last_payment.
	 * @param int    $timestamp UTC timestamp, 0 to clear.
	 */
	public function set_date_timestamp( $type, $timestamp ) {
		$this->update_meta_data( '_lws_' . $type . '_date', absint( $timestamp ) );
	}

	/**
	 * Formatted date for display in the site timezone.
	 *
	 * @param string $type Date type.
	 * @return string Empty when unset.
	 */
	public function get_formatted_date( $type ) {
		$timestamp = $this->get_date_timestamp( $type );
		return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
	}

	/**
	 * Number of successful payments so far, including the initial order.
	 *
	 * @return int
	 */
	public function get_payment_count() {
		return absint( $this->get_meta( '_lws_payment_count' ) );
	}

	/**
	 * Records one more successful payment.
	 */
	public function increment_payment_count() {
		$this->update_meta_data( '_lws_payment_count', $this->get_payment_count() + 1 );
	}

	/**
	 * Whether the fixed length (if any) has been fully billed.
	 *
	 * @return bool
	 */
	public function is_length_complete() {
		return $this->get_length() > 0 && $this->get_payment_count() >= $this->get_length();
	}

	/**
	 * Next payment date after the current one. Keeps the billing anchor (e.g. the 15th of each
	 * month) and skips forward past dates that are already in the past, so a long pause never
	 * results in several back-to-back charges.
	 *
	 * @return int UTC timestamp.
	 */
	public function calculate_next_payment_date() {
		$now  = time();
		$base = $this->get_date_timestamp( 'next_payment' );
		if ( ! $base ) {
			$base = $this->get_date_timestamp( 'last_payment' ) ? $this->get_date_timestamp( 'last_payment' ) : $now;
		}

		$next  = lws_add_periods( $base, $this->get_billing_interval(), $this->get_billing_period() );
		$guard = 0;
		while ( $next <= $now && $guard < 1000 ) {
			$next = lws_add_periods( $next, $this->get_billing_interval(), $this->get_billing_period() );
			++$guard;
		}

		return $next;
	}

	/*
	|--------------------------------------------------------------------------
	| Related orders
	|--------------------------------------------------------------------------
	*/

	/**
	 * IDs of renewal orders, oldest first.
	 *
	 * @return int[]
	 */
	public function get_renewal_order_ids() {
		$ids = $this->get_meta( '_lws_renewal_order_ids' );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Links a renewal order.
	 *
	 * @param int $order_id Renewal order ID.
	 */
	public function add_renewal_order_id( $order_id ) {
		$ids   = $this->get_renewal_order_ids();
		$ids[] = absint( $order_id );
		$this->update_meta_data( '_lws_renewal_order_ids', array_values( array_unique( $ids ) ) );
	}

	/**
	 * Parent order plus renewals, newest first.
	 *
	 * @return WC_Order[]
	 */
	public function get_related_orders() {
		$ids = array_reverse( $this->get_renewal_order_ids() );
		if ( $this->get_parent_id() ) {
			$ids[] = $this->get_parent_id();
		}
		return array_values( array_filter( array_map( 'wc_get_order', $ids ) ) );
	}

	/**
	 * Most recent renewal order that still awaits payment, if any.
	 *
	 * @return WC_Order|false
	 */
	public function get_unpaid_renewal_order() {
		foreach ( array_reverse( $this->get_renewal_order_ids() ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order && $order->has_status( array( 'pending', 'failed' ) ) ) {
				return $order;
			}
		}
		return false;
	}
}
