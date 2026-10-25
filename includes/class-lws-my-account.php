<?php
/**
 * My Account: subscriptions list, detail view and customer actions.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer self-service.
 */
class LWS_My_Account {

	const ENDPOINT_LIST = 'subscriptions';
	const ENDPOINT_VIEW = 'view-subscription';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_LIST . '_title', array( __CLASS__, 'list_title' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_VIEW . '_title', array( __CLASS__, 'view_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_LIST . '_endpoint', array( __CLASS__, 'render_list' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_VIEW . '_endpoint', array( __CLASS__, 'render_view' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_action' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_subscription_links' ) );
	}

	/**
	 * Registers endpoints with WooCommerce (which adds the rewrite rules).
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[ self::ENDPOINT_LIST ] = self::ENDPOINT_LIST;
		$vars[ self::ENDPOINT_VIEW ] = self::ENDPOINT_VIEW;
		return $vars;
	}

	/**
	 * Adds "Subscriptions" after "Orders".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function add_menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT_LIST ] = __( 'Subscriptions', 'lighter-woo-subscriptions' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT_LIST ] ) ) {
			$new[ self::ENDPOINT_LIST ] = __( 'Subscriptions', 'lighter-woo-subscriptions' );
		}
		return $new;
	}

	/**
	 * List title.
	 *
	 * @return string
	 */
	public static function list_title() {
		return __( 'Subscriptions', 'lighter-woo-subscriptions' );
	}

	/**
	 * Detail title.
	 *
	 * @return string
	 */
	public static function view_title() {
		global $wp;
		$id = isset( $wp->query_vars[ self::ENDPOINT_VIEW ] ) ? absint( $wp->query_vars[ self::ENDPOINT_VIEW ] ) : 0;
		/* translators: %d: subscription ID. */
		return $id ? sprintf( __( 'Subscription #%d', 'lighter-woo-subscriptions' ), $id ) : __( 'Subscription', 'lighter-woo-subscriptions' );
	}

	/**
	 * Renders the list.
	 */
	public static function render_list() {
		wc_get_template(
			'myaccount/lws-subscriptions.php',
			array( 'subscriptions' => lws_get_subscriptions_for_user( get_current_user_id() ) ),
			'',
			LWS_DIR . 'templates/'
		);
	}

	/**
	 * Renders one subscription.
	 *
	 * @param string $subscription_id Endpoint value.
	 */
	public static function render_view( $subscription_id ) {
		$subscription = lws_get_subscription( absint( $subscription_id ) );
		if ( ! $subscription || ! self::user_owns( $subscription ) ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'Invalid subscription.', 'lighter-woo-subscriptions' ) . '</div>';
			return;
		}

		$adapter = LWS_Gateways::get_adapter( $subscription->get_payment_method() );
		$tokens  = $adapter ? $adapter->get_customer_tokens( $subscription ) : array();

		wc_get_template(
			'myaccount/lws-view-subscription.php',
			array(
				'subscription'  => $subscription,
				'actions'       => self::get_actions( $subscription ),
				'unpaid_order'  => $subscription->get_unpaid_renewal_order(),
				'tokens'        => $tokens,
				'current_token' => $adapter ? $adapter->get_current_token( $subscription ) : '',
				'is_automatic'  => LWS_Gateways::is_automatic( $subscription ),
			),
			'',
			LWS_DIR . 'templates/'
		);
	}

	/**
	 * Actions available to the customer now.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return array<string,string> action => label.
	 */
	public static function get_actions( LWS_Subscription $subscription ) {
		$actions = array();
		$paused  = 'yes' === $subscription->get_meta( '_lws_paused_by_customer' );

		if ( $subscription->has_status( 'active' ) && apply_filters( 'lws_customer_can_pause', true, $subscription ) ) {
			$actions['pause'] = __( 'Pause', 'lighter-woo-subscriptions' );
		}
		if ( $subscription->has_status( 'on-hold' ) && $paused && ! $subscription->get_unpaid_renewal_order() ) {
			$actions['resume'] = __( 'Resume', 'lighter-woo-subscriptions' );
		}
		if ( $subscription->has_status( 'pending-cancel' ) ) {
			$actions['reactivate'] = __( 'Keep my subscription', 'lighter-woo-subscriptions' );
		}
		if ( $subscription->has_status( array( 'active', 'on-hold' ) ) && apply_filters( 'lws_customer_can_cancel', true, $subscription ) ) {
			$actions['cancel'] = __( 'Cancel', 'lighter-woo-subscriptions' );
		}

		return apply_filters( 'lws_customer_actions', $actions, $subscription );
	}

	/**
	 * Processes customer actions posted from the detail view.
	 */
	public static function handle_action() {
		if ( empty( $_POST['lws_action'] ) || empty( $_POST['lws_subscription_id'] ) || ! is_user_logged_in() ) {
			return;
		}

		$subscription_id = absint( $_POST['lws_subscription_id'] );
		$nonce           = isset( $_POST['_lws_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_lws_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lws_subscription_' . $subscription_id ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'lighter-woo-subscriptions' ), 'error' );
			return;
		}

		$subscription = lws_get_subscription( $subscription_id );
		if ( ! $subscription || ! self::user_owns( $subscription ) ) {
			wc_add_notice( __( 'Invalid subscription.', 'lighter-woo-subscriptions' ), 'error' );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['lws_action'] ) );

		if ( 'change_card' === $action ) {
			$token_id = isset( $_POST['lws_token_id'] ) ? absint( $_POST['lws_token_id'] ) : 0;
			self::change_card( $subscription, $token_id );
		} elseif ( array_key_exists( $action, self::get_actions( $subscription ) ) ) {
			self::apply_action( $subscription, $action );
		} else {
			wc_add_notice( __( 'That change is not available for this subscription.', 'lighter-woo-subscriptions' ), 'error' );
		}

		wp_safe_redirect( $subscription->get_view_order_url() );
		exit;
	}

	/**
	 * Applies a status action.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param string           $action       pause|resume|reactivate|cancel.
	 */
	private static function apply_action( LWS_Subscription $subscription, $action ) {
		switch ( $action ) {
			case 'pause':
				$subscription->update_meta_data( '_lws_paused_by_customer', 'yes' );
				$subscription->update_status( 'on-hold', __( 'Paused by the customer.', 'lighter-woo-subscriptions' ), true );
				wc_add_notice( __( 'Your subscription is paused. You will not be charged until you resume it.', 'lighter-woo-subscriptions' ) );
				break;

			case 'resume':
				$subscription->delete_meta_data( '_lws_paused_by_customer' );
				$subscription->update_status( 'active', __( 'Resumed by the customer.', 'lighter-woo-subscriptions' ), true );
				wc_add_notice( __( 'Your subscription is active again.', 'lighter-woo-subscriptions' ) );
				break;

			case 'reactivate':
				$subscription->update_status( 'active', __( 'Cancellation withdrawn by the customer.', 'lighter-woo-subscriptions' ), true );
				wc_add_notice( __( 'Your subscription will continue.', 'lighter-woo-subscriptions' ) );
				break;

			case 'cancel':
				$subscription->delete_meta_data( '_lws_paused_by_customer' );
				// Customers keep what they paid for: an active subscription ends when its current period does.
				$target = $subscription->has_status( 'active' ) && $subscription->get_date_timestamp( 'next_payment' ) > time() ? 'pending-cancel' : 'cancelled';
				$subscription->update_status( $target, __( 'Cancelled by the customer.', 'lighter-woo-subscriptions' ), true );
				do_action( 'lws_subscription_cancelled_by_customer', $subscription );
				wc_add_notice(
					'pending-cancel' === $target
						/* translators: %s: date. */
						? sprintf( __( 'Your subscription is cancelled and will end on %s.', 'lighter-woo-subscriptions' ), $subscription->get_formatted_date( 'end' ) )
						: __( 'Your subscription is cancelled.', 'lighter-woo-subscriptions' )
				);
				break;
		}
	}

	/**
	 * Switches the subscription to another saved card.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @param int              $token_id     WC payment token ID.
	 */
	private static function change_card( LWS_Subscription $subscription, $token_id ) {
		$token   = $token_id ? WC_Payment_Tokens::get( $token_id ) : null;
		$adapter = LWS_Gateways::get_adapter( $subscription->get_payment_method() );

		if ( ! $token || ! $adapter || (int) $token->get_user_id() !== get_current_user_id() || ! $adapter->supports_gateway( $token->get_gateway_id() ) ) {
			wc_add_notice( __( 'Please choose one of your saved cards.', 'lighter-woo-subscriptions' ), 'error' );
			return;
		}

		$adapter->use_token( $subscription, $token );
		$subscription->delete_meta_data( '_lws_manual_renewal' );
		$subscription->save();
		/* translators: %s: card description. */
		$subscription->add_order_note( sprintf( __( 'Customer changed the payment card to %s.', 'lighter-woo-subscriptions' ), wp_strip_all_tags( $token->get_display_name() ) ) );
		wc_add_notice( __( 'Payment card updated. Future renewals will use it.', 'lighter-woo-subscriptions' ) );

		// A failed renewal is retried straight away with the new card.
		if ( $subscription->has_status( 'on-hold' ) && $subscription->get_unpaid_renewal_order() ) {
			LWS_Scheduler::schedule_retry( $subscription, time() );
		}
	}

	/**
	 * Lists subscriptions created by an order on the customer's order view.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_subscription_links( $order ) {
		if ( ! $order instanceof WC_Order || $order instanceof LWS_Subscription ) {
			return;
		}
		$subscriptions = lws_get_subscriptions_for_order( $order );
		$renewal_of    = lws_get_subscription_for_renewal( $order );
		if ( $renewal_of ) {
			$subscriptions[] = $renewal_of;
		}
		if ( ! $subscriptions || ! is_account_page() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Related subscriptions', 'lighter-woo-subscriptions' ) . '</h2><ul class="lws-related-subscriptions">';
		foreach ( $subscriptions as $subscription ) {
			printf(
				'<li><a href="%1$s">%2$s</a> &ndash; %3$s</li>',
				esc_url( $subscription->get_view_order_url() ),
				/* translators: %d: subscription ID. */
				esc_html( sprintf( __( 'Subscription #%d', 'lighter-woo-subscriptions' ), $subscription->get_id() ) ),
				esc_html( lws_get_status_label( $subscription->get_status() ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Whether the logged-in user owns the subscription.
	 *
	 * @param LWS_Subscription $subscription Subscription.
	 * @return bool
	 */
	public static function user_owns( LWS_Subscription $subscription ) {
		return get_current_user_id() && (int) $subscription->get_customer_id() === get_current_user_id();
	}
}
