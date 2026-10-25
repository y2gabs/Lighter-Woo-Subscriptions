<?php
/**
 * Action Scheduler integration.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one renewal action and one end action per subscription in sync with its status and dates.
 */
class LWS_Scheduler {

	const HOOK_RENEWAL = 'lws_process_renewal';
	const HOOK_RETRY   = 'lws_retry_renewal';
	const HOOK_END     = 'lws_end_subscription';
	const GROUP        = 'lighter-woo-subscriptions';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_RENEWAL, array( __CLASS__, 'run_renewal' ) );
		add_action( self::HOOK_RETRY, array( __CLASS__, 'run_retry' ) );
		add_action( self::HOOK_END, array( __CLASS__, 'run_end' ) );
		add_action( 'lws_subscription_status_changed', array( __CLASS__, 'status_changed' ), 10, 3 );
	}

	/**
	 * Action arguments for a subscription.
	 *
	 * @param LWS_Subscription|int $subscription Subscription.
	 * @return array
	 */
	private static function args( $subscription ) {
		return array( 'subscription_id' => $subscription instanceof LWS_Subscription ? $subscription->get_id() : absint( $subscription ) );
	}

	/**
	 * Sets next payment and end dates after a successful payment. Does not save.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function set_next_dates( LWS_Subscription $subscription ) {
		$next = $subscription->calculate_next_payment_date();

		if ( $subscription->is_length_complete() ) {
			// Fully billed: the customer keeps access until the end of the last paid period.
			$subscription->set_date_timestamp( 'next_payment', 0 );
			$subscription->set_date_timestamp( 'end', $next );
		} else {
			$subscription->set_date_timestamp( 'next_payment', $next );
			$subscription->set_date_timestamp( 'end', 0 );
		}
	}

	/**
	 * Replaces the renewal/end actions to match the subscription's current state.
	 * Pending retries are managed by LWS_Renewal and left alone here.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function schedule( LWS_Subscription $subscription ) {
		$args = self::args( $subscription );
		as_unschedule_all_actions( self::HOOK_RENEWAL, $args, self::GROUP );
		as_unschedule_all_actions( self::HOOK_END, $args, self::GROUP );

		$next = $subscription->get_date_timestamp( 'next_payment' );
		$end  = $subscription->get_date_timestamp( 'end' );

		if ( $subscription->has_status( 'active' ) && $next ) {
			as_schedule_single_action( max( $next, time() ), self::HOOK_RENEWAL, $args, self::GROUP );
		}
		if ( $subscription->has_status( array( 'active', 'pending-cancel' ) ) && $end ) {
			as_schedule_single_action( max( $end, time() ), self::HOOK_END, $args, self::GROUP );
		}
	}

	/**
	 * Removes every scheduled action for a subscription.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function unschedule_all( LWS_Subscription $subscription ) {
		$args = self::args( $subscription );
		foreach ( array( self::HOOK_RENEWAL, self::HOOK_RETRY, self::HOOK_END ) as $hook ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		}
	}

	/**
	 * Schedules a payment retry.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param int              $timestamp    When to retry.
	 */
	public static function schedule_retry( LWS_Subscription $subscription, $timestamp ) {
		$args = self::args( $subscription );
		as_unschedule_all_actions( self::HOOK_RETRY, $args, self::GROUP );
		as_schedule_single_action( $timestamp, self::HOOK_RETRY, $args, self::GROUP );
	}

	/**
	 * Cancels a pending retry.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 */
	public static function unschedule_retry( LWS_Subscription $subscription ) {
		as_unschedule_all_actions( self::HOOK_RETRY, self::args( $subscription ), self::GROUP );
	}

	/**
	 * Next scheduled retry, if any.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return int|false Timestamp.
	 */
	public static function get_next_retry( LWS_Subscription $subscription ) {
		$next = as_next_scheduled_action( self::HOOK_RETRY, self::args( $subscription ), self::GROUP );
		return is_int( $next ) ? $next : false;
	}

	/**
	 * Adjusts dates and actions after any status change (customer, admin or automatic).
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param string           $from         Old status.
	 * @param string           $to           New status.
	 */
	public static function status_changed( $subscription, $from, $to ) {
		switch ( $to ) {
			case 'active':
				// Resumed or reactivated: make sure the next charge is in the future.
				if ( ! $subscription->get_date_timestamp( 'end' ) && $subscription->get_date_timestamp( 'next_payment' ) <= time() ) {
					$subscription->set_date_timestamp( 'next_payment', $subscription->calculate_next_payment_date() );
				}
				if ( 'pending-cancel' === $from && ! $subscription->is_length_complete() ) {
					// Cancellation withdrawn: billing continues from the end of the paid period.
					$end = $subscription->get_date_timestamp( 'end' );
					$subscription->set_date_timestamp( 'next_payment', $end > time() ? $end : $subscription->calculate_next_payment_date() );
					$subscription->set_date_timestamp( 'end', 0 );
				}
				break;

			case 'pending-cancel':
				// Access continues until the period the customer paid for ends.
				$next = $subscription->get_date_timestamp( 'next_payment' );
				if ( ! $subscription->get_date_timestamp( 'end' ) ) {
					$subscription->set_date_timestamp( 'end', $next > time() ? $next : time() );
				}
				$subscription->set_date_timestamp( 'next_payment', 0 );
				self::unschedule_retry( $subscription );
				break;

			case 'cancelled':
			case 'expired':
				$subscription->set_date_timestamp( 'next_payment', 0 );
				if ( ! $subscription->get_date_timestamp( 'end' ) || $subscription->get_date_timestamp( 'end' ) > time() ) {
					$subscription->set_date_timestamp( 'end', time() );
				}
				$subscription->save();
				self::unschedule_all( $subscription );

				$unpaid = $subscription->get_unpaid_renewal_order();
				if ( $unpaid ) {
					$unpaid->update_status( 'cancelled', __( 'Subscription ended before this renewal was paid.', 'lighter-woo-subscriptions' ) );
				}
				return;
		}

		$subscription->save();
		self::schedule( $subscription );
	}

	/**
	 * Action callback: charge a renewal.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function run_renewal( $subscription_id ) {
		$subscription = lws_get_subscription( $subscription_id );
		if ( $subscription && $subscription->has_status( 'active' ) ) {
			LWS_Renewal::process_scheduled_renewal( $subscription );
		}
	}

	/**
	 * Action callback: retry a failed renewal.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function run_retry( $subscription_id ) {
		$subscription = lws_get_subscription( $subscription_id );
		if ( $subscription && $subscription->has_status( 'on-hold' ) ) {
			LWS_Renewal::retry( $subscription );
		}
	}

	/**
	 * Action callback: end a subscription at the end of its paid period.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function run_end( $subscription_id ) {
		$subscription = lws_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		if ( $subscription->get_date_timestamp( 'end' ) > time() + MINUTE_IN_SECONDS ) {
			self::schedule( $subscription ); // End date was moved later; follow it.
			return;
		}
		if ( $subscription->has_status( 'pending-cancel' ) ) {
			$subscription->update_status( 'cancelled', __( 'Paid period ended.', 'lighter-woo-subscriptions' ) );
		} elseif ( $subscription->has_status( 'active' ) ) {
			$subscription->update_status( 'expired', __( 'All payments completed.', 'lighter-woo-subscriptions' ) );
		}
	}

	/**
	 * Re-creates any missing actions, e.g. after the plugin was inactive while an action was due.
	 */
	public static function reschedule_missing() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		foreach ( lws_get_subscriptions( array( 'status' => array( 'wc-active', 'wc-pending-cancel' ) ) ) as $subscription ) {
			$args    = self::args( $subscription );
			$pending = as_next_scheduled_action( self::HOOK_RENEWAL, $args, self::GROUP ) || as_next_scheduled_action( self::HOOK_END, $args, self::GROUP );
			if ( ! $pending ) {
				self::schedule( $subscription );
			}
		}
	}
}
