<?php
/**
 * Admin screens. The subscription list and edit screen are WooCommerce's own order screens
 * for our order type; this class adds the schedule meta box, list columns and actions.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Admin integration.
 */
class LWS_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 45 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_lws_process_renewal', array( __CLASS__, 'action_process_renewal' ) );

		$type = LWS_Order_Type::TYPE;
		add_filter( "manage_woocommerce_page_wc-orders--{$type}_columns", array( __CLASS__, 'list_columns' ) );
		add_action( "manage_woocommerce_page_wc-orders--{$type}_custom_column", array( __CLASS__, 'list_column_content' ), 10, 2 );
		add_filter( "manage_edit-{$type}_columns", array( __CLASS__, 'list_columns' ) );
		add_filter( 'request', array( __CLASS__, 'posts_list_statuses' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'list_column_content' ), 10, 2 );
	}

	/**
	 * Screen ID of an order type's edit screen (HPOS or posts).
	 *
	 * @param string $type Order type.
	 * @return string
	 */
	private static function screen_id( $type ) {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return 'shop_order' === $type ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders--' . $type;
		}
		return $type;
	}

	/**
	 * Registers meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'lws-subscription-schedule', __( 'Subscription schedule', 'lighter-woo-subscriptions' ), array( __CLASS__, 'render_schedule_box' ), self::screen_id( LWS_Order_Type::TYPE ), 'side', 'high' );
		add_meta_box( 'lws-subscription-orders', __( 'Related orders', 'lighter-woo-subscriptions' ), array( __CLASS__, 'render_related_box' ), self::screen_id( LWS_Order_Type::TYPE ), 'normal', 'low' );
		add_meta_box( 'lws-order-subscriptions', __( 'Subscriptions', 'lighter-woo-subscriptions' ), array( __CLASS__, 'render_order_box' ), self::screen_id( 'shop_order' ), 'side', 'default' );
	}

	/**
	 * Normalises the meta box argument (WP_Post on posts storage, WC_Order on HPOS).
	 *
	 * @param WP_Post|WC_Order $post_or_order Object.
	 * @return WC_Order|false
	 */
	private static function order_from( $post_or_order ) {
		return wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order );
	}

	/**
	 * Schedule meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Object.
	 */
	public static function render_schedule_box( $post_or_order ) {
		$subscription = lws_get_subscription( self::order_from( $post_or_order ) );
		if ( ! $subscription ) {
			$subscription = new LWS_Subscription();
		}

		$next  = $subscription->get_date_timestamp( 'next_payment' );
		$end   = $subscription->get_date_timestamp( 'end' );
		$retry = $subscription->get_id() ? LWS_Scheduler::get_next_retry( $subscription ) : false;

		wp_nonce_field( 'lws_save_schedule', '_lws_schedule_nonce' );
		?>
		<p>
			<label for="lws_interval"><?php esc_html_e( 'Bill every', 'lighter-woo-subscriptions' ); ?></label><br />
			<input type="number" min="1" step="1" style="width:60px" id="lws_interval" name="lws_interval" value="<?php echo esc_attr( $subscription->get_billing_interval() ); ?>" />
			<select name="lws_period">
				<?php foreach ( lws_get_billing_periods() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $subscription->get_billing_period(), $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="lws_length"><?php esc_html_e( 'Number of payments (0 = until cancelled)', 'lighter-woo-subscriptions' ); ?></label><br />
			<input type="number" min="0" step="1" id="lws_length" name="lws_length" value="<?php echo esc_attr( $subscription->get_length() ); ?>" />
			<?php if ( $subscription->get_id() ) : ?>
				<br /><small>
				<?php
				/* translators: %d: count. */
				echo esc_html( sprintf( __( 'Payments so far: %d', 'lighter-woo-subscriptions' ), $subscription->get_payment_count() ) );
				?>
				</small>
			<?php endif; ?>
		</p>
		<p>
			<label for="lws_next_payment"><?php esc_html_e( 'Next payment', 'lighter-woo-subscriptions' ); ?></label><br />
			<input type="datetime-local" id="lws_next_payment" name="lws_next_payment" value="<?php echo esc_attr( $next ? wp_date( 'Y-m-d\TH:i', $next ) : '' ); ?>" />
			<input type="hidden" name="lws_next_payment_original" value="<?php echo esc_attr( $next ? wp_date( 'Y-m-d\TH:i', $next ) : '' ); ?>" />
		</p>
		<p>
			<label for="lws_end"><?php esc_html_e( 'End date', 'lighter-woo-subscriptions' ); ?></label><br />
			<input type="datetime-local" id="lws_end" name="lws_end" value="<?php echo esc_attr( $end ? wp_date( 'Y-m-d\TH:i', $end ) : '' ); ?>" />
			<input type="hidden" name="lws_end_original" value="<?php echo esc_attr( $end ? wp_date( 'Y-m-d\TH:i', $end ) : '' ); ?>" />
		</p>
		<p>
			<label>
				<input type="checkbox" name="lws_manual_renewal" value="1" <?php checked( (bool) $subscription->get_meta( '_lws_manual_renewal' ) ); ?> />
				<?php esc_html_e( 'Manual renewals (email an invoice instead of charging the card)', 'lighter-woo-subscriptions' ); ?>
			</label>
		</p>
		<?php if ( $subscription->get_id() ) : ?>
			<p>
				<strong><?php esc_html_e( 'Renewals:', 'lighter-woo-subscriptions' ); ?></strong>
				<?php echo esc_html( LWS_Gateways::is_automatic( $subscription ) ? __( 'automatic (saved card)', 'lighter-woo-subscriptions' ) : __( 'manual (invoice)', 'lighter-woo-subscriptions' ) ); ?>
				<?php if ( $retry ) : ?>
					<br />
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Payment retry scheduled: %s', 'lighter-woo-subscriptions' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $retry ) ) );
					?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves the schedule box.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_meta_box( $order_id ) {
		$subscription = lws_get_subscription( $order_id );
		if ( ! $subscription || ! isset( $_POST['_lws_schedule_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lws_schedule_nonce'] ) ), 'lws_save_schedule' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$period   = isset( $_POST['lws_period'] ) ? sanitize_key( wp_unslash( $_POST['lws_period'] ) ) : 'month';
		$interval = isset( $_POST['lws_interval'] ) ? absint( $_POST['lws_interval'] ) : 1;
		$length   = isset( $_POST['lws_length'] ) ? absint( $_POST['lws_length'] ) : 0;
		$subscription->set_schedule( $period, $interval, $length );

		// Only apply dates the admin edited; a status change in the same save may already have moved them.
		$date_fields = array(
			'next_payment' => 'lws_next_payment',
			'end'          => 'lws_end',
		);
		foreach ( $date_fields as $type => $field ) {
			$value    = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			$original = isset( $_POST[ $field . '_original' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field . '_original' ] ) ) : '';
			if ( $value !== $original ) {
				$subscription->set_date_timestamp( $type, self::parse_date( $value ) );
			}
		}

		if ( ! empty( $_POST['lws_manual_renewal'] ) ) {
			$subscription->update_meta_data( '_lws_manual_renewal', 'yes' );
		} else {
			$subscription->delete_meta_data( '_lws_manual_renewal' );
		}

		if ( ! $subscription->get_date_timestamp( 'start' ) && $subscription->has_status( 'active' ) ) {
			$subscription->set_date_timestamp( 'start', time() );
		}

		$subscription->save();
		LWS_Scheduler::schedule( $subscription );
	}

	/**
	 * Converts a datetime-local value in the site timezone to a UTC timestamp.
	 *
	 * @param string $value Y-m-d\TH:i.
	 * @return int 0 when empty or invalid.
	 */
	private static function parse_date( $value ) {
		if ( '' === $value ) {
			return 0;
		}
		$date = DateTime::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Related orders meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Object.
	 */
	public static function render_related_box( $post_or_order ) {
		$subscription = lws_get_subscription( self::order_from( $post_or_order ) );
		$orders       = $subscription ? $subscription->get_related_orders() : array();
		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'No orders yet.', 'lighter-woo-subscriptions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'lighter-woo-subscriptions' ) . '</th><th>' . esc_html__( 'Type', 'lighter-woo-subscriptions' ) . '</th><th>' . esc_html__( 'Date', 'lighter-woo-subscriptions' ) . '</th><th>' . esc_html__( 'Status', 'lighter-woo-subscriptions' ) . '</th><th>' . esc_html__( 'Total', 'lighter-woo-subscriptions' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			printf(
				'<tr><td><a href="%1$s">#%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( lws_is_renewal_order( $order ) ? __( 'Renewal', 'lighter-woo-subscriptions' ) : __( 'Initial order', 'lighter-woo-subscriptions' ) ),
				esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ),
				esc_html( wc_get_order_status_name( $order->get_status() ) ),
				wp_kses_post( $order->get_formatted_order_total() )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Subscriptions box on regular orders.
	 *
	 * @param WP_Post|WC_Order $post_or_order Object.
	 */
	public static function render_order_box( $post_or_order ) {
		$order = self::order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$subscriptions = lws_get_subscriptions_for_order( $order );
		$renewal_of    = lws_get_subscription_for_renewal( $order );
		if ( $renewal_of ) {
			$subscriptions[] = $renewal_of;
		}
		if ( ! $subscriptions ) {
			echo '<p>' . esc_html__( 'This order is not linked to a subscription.', 'lighter-woo-subscriptions' ) . '</p>';
			return;
		}
		if ( $renewal_of ) {
			echo '<p>' . esc_html__( 'Renewal order for:', 'lighter-woo-subscriptions' ) . '</p>';
		}
		echo '<ul>';
		foreach ( $subscriptions as $subscription ) {
			printf(
				'<li><a href="%1$s">#%2$d</a> &ndash; %3$s</li>',
				esc_url( $subscription->get_edit_order_url() ),
				(int) $subscription->get_id(),
				esc_html( lws_get_status_label( $subscription->get_status() ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * "Process renewal now" in the order actions dropdown.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order   Order (may be absent on older WooCommerce).
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( ! $order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( $order instanceof LWS_Subscription ) {
			// Order-only actions do not apply to subscriptions.
			unset( $actions['send_order_details'], $actions['send_order_details_admin'], $actions['regenerate_download_permissions'] );
			if ( $order->has_status( 'active' ) ) {
				$actions['lws_process_renewal'] = __( 'Process renewal now', 'lighter-woo-subscriptions' );
			}
		}
		return $actions;
	}

	/**
	 * Runs a renewal immediately.
	 *
	 * @param WC_Order $order Subscription.
	 */
	public static function action_process_renewal( $order ) {
		$subscription = lws_get_subscription( $order->get_id() );
		if ( ! $subscription || ! $subscription->has_status( 'active' ) ) {
			return;
		}
		$subscription->set_date_timestamp( 'next_payment', time() );
		$subscription->add_order_note( __( 'Renewal processed manually by an admin.', 'lighter-woo-subscriptions' ), 0, true );
		$subscription->save();
		LWS_Renewal::process_scheduled_renewal( $subscription );
	}

	/**
	 * Posts storage: WordPress hides non-public statuses from the "All" list, and WooCommerce only
	 * fixes that for shop orders, so request subscription statuses explicitly.
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public static function posts_list_statuses( $query_vars ) {
		global $typenow;
		if ( LWS_Order_Type::TYPE === $typenow && empty( $query_vars['post_status'] ) ) {
			$query_vars['post_status'] = array_keys( lws_get_subscription_statuses() );
		}
		return $query_vars;
	}

	/**
	 * Adds list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function list_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				// Posts storage: the post title is an internal "Order – date" string.
				$key   = 'lws_title';
				$label = __( 'Subscription', 'lighter-woo-subscriptions' );
			}
			$new[ $key ] = $label;
			if ( 'order_number' === $key || 'lws_title' === $key ) {
				$new['lws_customer'] = __( 'Customer', 'lighter-woo-subscriptions' );
			}
		}
		unset( $new['comments'] );
		$new['lws_status']   = __( 'Status', 'lighter-woo-subscriptions' );
		$new['lws_schedule'] = __( 'Recurring total', 'lighter-woo-subscriptions' );
		$new['lws_next']     = __( 'Next payment', 'lighter-woo-subscriptions' );
		unset( $new['order_status'], $new['order_total'], $new['shipping_address'], $new['billing_address'], $new['wc_actions'] );
		return $new;
	}

	/**
	 * Renders list columns.
	 *
	 * @param string       $column Column.
	 * @param int|WC_Order $post_or_order Order or post ID.
	 */
	public static function list_column_content( $column, $post_or_order ) {
		$subscription = lws_get_subscription( $post_or_order instanceof WC_Order ? $post_or_order : absint( $post_or_order ) );
		if ( ! $subscription ) {
			return;
		}
		switch ( $column ) {
			case 'lws_title':
				printf(
					'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
					esc_url( $subscription->get_edit_order_url() ),
					/* translators: %d: subscription ID. */
					esc_html( sprintf( __( 'Subscription #%d', 'lighter-woo-subscriptions' ), $subscription->get_id() ) )
				);
				break;
			case 'lws_customer':
				$name = trim( $subscription->get_formatted_billing_full_name() );
				echo esc_html( $name ? $name : $subscription->get_billing_email() );
				break;
			case 'lws_status':
				printf( '<mark class="order-status status-%1$s"><span>%2$s</span></mark>', esc_attr( $subscription->get_status() ), esc_html( lws_get_status_label( $subscription->get_status() ) ) );
				break;
			case 'lws_schedule':
				echo wp_kses_post( wc_price( $subscription->get_total(), array( 'currency' => $subscription->get_currency() ) ) ) . ' ' . esc_html( $subscription->get_schedule_string() );
				break;
			case 'lws_next':
				echo esc_html( $subscription->has_status( 'active' ) && $subscription->get_formatted_date( 'next_payment' ) ? $subscription->get_formatted_date( 'next_payment' ) : '—' );
				break;
		}
	}
}
