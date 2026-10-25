<?php
/**
 * Cart rules and recurring totals.
 *
 * Rule: subscription products are checked out on their own, and all subscription items in a
 * cart bill on one schedule. That keeps every renewal an exact copy of the initial order
 * (items, shipping, tax) without having to split shipping or fees between products.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart integration.
 */
class LWS_Cart {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 5 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );

		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'cart_item_price' ), 20, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'recurring_total_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'recurring_total_row' ) );

		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_account' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'enable_registration' ) );
	}

	/**
	 * Schedule of the subscription items in the cart.
	 *
	 * @return array|null Null when the cart holds no subscription.
	 */
	public static function get_cart_schedule() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$schedule = lws_get_product_schedule( $item['data'] );
			if ( $schedule ) {
				return $schedule;
			}
		}
		return null;
	}

	/**
	 * Whether the cart contains a subscription.
	 *
	 * @return bool
	 */
	public static function cart_has_subscription() {
		return null !== self::get_cart_schedule();
	}

	/**
	 * Blocks carts that mix subscriptions with other products or with other schedules.
	 *
	 * @param bool $valid        Current validity.
	 * @param int  $product_id   Product ID.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation ID.
	 * @return bool
	 */
	public static function validate_add_to_cart( $valid, $product_id, $quantity = 1, $variation_id = 0 ) {
		if ( ! $valid || ! WC()->cart || WC()->cart->is_empty() ) {
			return $valid;
		}

		$new_schedule  = lws_get_product_schedule( $variation_id ? $variation_id : $product_id );
		$cart_schedule = self::get_cart_schedule();

		if ( null === $new_schedule && null === $cart_schedule ) {
			return $valid;
		}

		$conflict = null === $new_schedule || null === $cart_schedule || self::cart_has_non_subscription_items()
			|| lws_schedule_key( $new_schedule ) !== lws_schedule_key( $cart_schedule );

		if ( $conflict ) {
			wc_add_notice( self::mixed_cart_message(), 'error' );
			return false;
		}

		return $valid;
	}

	/**
	 * Re-checks the rule at checkout in case the cart was built some other way.
	 */
	public static function check_cart_items() {
		$keys      = array();
		$has_other = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			$schedule = lws_get_product_schedule( $item['data'] );
			if ( $schedule ) {
				$keys[ lws_schedule_key( $schedule ) ] = true;
			} else {
				$has_other = true;
			}
		}

		if ( $keys && ( $has_other || count( $keys ) > 1 ) ) {
			wc_add_notice( self::mixed_cart_message(), 'error' );
		}
	}

	/**
	 * Whether the cart holds any product that is not a subscription.
	 *
	 * @return bool
	 */
	private static function cart_has_non_subscription_items() {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! lws_is_subscription_product( $item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shared error text.
	 *
	 * @return string
	 */
	private static function mixed_cart_message() {
		return apply_filters(
			'lws_mixed_cart_message',
			__( 'Subscriptions must be checked out on their own, and all subscriptions in one order must share the same billing schedule. Please complete or empty your current cart first.', 'lighter-woo-subscriptions' )
		);
	}

	/**
	 * Appends the schedule to cart item prices and subtotals.
	 *
	 * @param string $html      Price HTML.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public static function cart_item_price( $html, $cart_item ) {
		$schedule = isset( $cart_item['data'] ) ? lws_get_product_schedule( $cart_item['data'] ) : null;
		if ( ! $schedule || false !== strpos( $html, 'lws-schedule' ) ) {
			return $html;
		}
		return $html . ' <span class="lws-schedule">' . esc_html( lws_get_schedule_string( $schedule['interval'], $schedule['period'], $schedule['length'] ) ) . '</span>';
	}

	/**
	 * Recurring amount the customer will be charged on each renewal.
	 * Coupons only apply to the first payment, so the recurring total is the pre-discount subtotal
	 * plus shipping and their taxes.
	 *
	 * @return float
	 */
	public static function get_recurring_total() {
		$cart  = WC()->cart;
		$total = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		return (float) apply_filters( 'lws_cart_recurring_total', $total, $cart );
	}

	/**
	 * Recurring total row for classic cart and checkout.
	 */
	public static function recurring_total_row() {
		$schedule = self::get_cart_schedule();
		if ( ! $schedule ) {
			return;
		}

		$first_renewal = lws_add_periods( time(), $schedule['interval'], $schedule['period'] );
		?>
		<tr class="lws-recurring-total">
			<th><?php esc_html_e( 'Recurring total', 'lighter-woo-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Recurring total', 'lighter-woo-subscriptions' ); ?>">
				<strong><?php echo wp_kses_post( wc_price( self::get_recurring_total() ) ); ?></strong>
				<?php echo esc_html( lws_get_schedule_string( $schedule['interval'], $schedule['period'], $schedule['length'] ) ); ?>
				<br /><small>
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'First renewal: %s', 'lighter-woo-subscriptions' ), wp_date( get_option( 'date_format' ), $first_renewal ) ) );
				?>
				</small>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renewals need a customer account to hold the saved card.
	 *
	 * @param bool $required Whether registration is required.
	 * @return bool
	 */
	public static function require_account( $required ) {
		return self::cart_has_subscription() ? true : $required;
	}

	/**
	 * Allows registration at checkout when the cart contains a subscription.
	 *
	 * @param bool $enabled Whether registration is enabled.
	 * @return bool
	 */
	public static function enable_registration( $enabled ) {
		return self::cart_has_subscription() ? true : $enabled;
	}
}
