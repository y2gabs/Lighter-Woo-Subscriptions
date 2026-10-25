<?php
/**
 * Registers the subscription order type and statuses.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order type registration.
 */
class LWS_Order_Type {

	const TYPE = 'lws_subscription';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_order_type' ), 6 );
		add_action( 'init', array( __CLASS__, 'register_statuses' ), 9 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'filter_statuses_on_subscription_screens' ) );
	}

	/**
	 * Registers the order type (after WooCommerce registers its own on init:5).
	 */
	public static function register_order_type() {
		wc_register_order_type(
			self::TYPE,
			apply_filters(
				'lws_register_order_type_args',
				array(
					'labels'                           => array(
						'name'               => __( 'Subscriptions', 'lighter-woo-subscriptions' ),
						'singular_name'      => __( 'Subscription', 'lighter-woo-subscriptions' ),
						'add_new'            => __( 'Add subscription', 'lighter-woo-subscriptions' ),
						'add_new_item'       => __( 'Add new subscription', 'lighter-woo-subscriptions' ),
						'edit'               => __( 'Edit', 'lighter-woo-subscriptions' ),
						'edit_item'          => __( 'Edit subscription', 'lighter-woo-subscriptions' ),
						'new_item'           => __( 'New subscription', 'lighter-woo-subscriptions' ),
						'view_item'          => __( 'View subscription', 'lighter-woo-subscriptions' ),
						'search_items'       => __( 'Search subscriptions', 'lighter-woo-subscriptions' ),
						'not_found'          => __( 'No subscriptions found', 'lighter-woo-subscriptions' ),
						'not_found_in_trash' => __( 'No subscriptions found in trash', 'lighter-woo-subscriptions' ),
						'menu_name'          => __( 'Subscriptions', 'lighter-woo-subscriptions' ),
					),
					'description'                      => __( 'Customer subscriptions.', 'lighter-woo-subscriptions' ),
					'public'                           => false,
					'show_ui'                          => true,
					'capability_type'                  => 'shop_order',
					'map_meta_cap'                     => true,
					'publicly_queryable'               => false,
					'exclude_from_search'              => true,
					'show_in_menu'                     => current_user_can( 'edit_shop_orders' ) ? 'woocommerce' : false,
					'hierarchical'                     => false,
					'show_in_nav_menus'                => false,
					'rewrite'                          => false,
					'query_var'                        => false,
					'supports'                         => array( 'title', 'comments', 'custom-fields' ),
					'has_archive'                      => false,
					'exclude_from_orders_screen'       => true,
					'add_order_meta_boxes'             => true,
					'exclude_from_order_count'         => true,
					'exclude_from_order_views'         => true,
					'exclude_from_order_webhooks'      => true,
					'exclude_from_order_reports'       => true,
					'exclude_from_order_sales_reports' => true,
					'class_name'                       => 'LWS_Subscription',
				)
			)
		);
	}

	/**
	 * Registers post statuses that WooCommerce does not already provide.
	 */
	public static function register_statuses() {
		$statuses = array(
			/* translators: %s: count. */
			'wc-active'         => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'lighter-woo-subscriptions' ),
			/* translators: %s: count. */
			'wc-pending-cancel' => _n_noop( 'Pending cancellation <span class="count">(%s)</span>', 'Pending cancellation <span class="count">(%s)</span>', 'lighter-woo-subscriptions' ),
			/* translators: %s: count. */
			'wc-expired'        => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'lighter-woo-subscriptions' ),
		);

		foreach ( $statuses as $status => $label_count ) {
			register_post_status(
				$status,
				array(
					'label'                     => lws_get_status_label( $status ),
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $label_count,
				)
			);
		}
	}

	/**
	 * On subscription admin screens, the order status dropdown, list filters and status labels
	 * should offer subscription statuses rather than order statuses.
	 *
	 * @param array $statuses Order statuses.
	 * @return array
	 */
	public static function filter_statuses_on_subscription_screens( $statuses ) {
		return self::is_subscription_admin_request() ? lws_get_subscription_statuses() : $statuses;
	}

	/**
	 * Whether this admin request is for the subscriptions list or edit screen (HPOS or posts).
	 *
	 * @return bool
	 */
	public static function is_subscription_admin_request() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['page'] ) && 'wc-orders--' . self::TYPE === $_GET['page'] ) {
			return true;
		}
		if ( isset( $_REQUEST['post_type'] ) && self::TYPE === $_REQUEST['post_type'] ) {
			return true;
		}
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : ( isset( $_REQUEST['post_ID'] ) ? absint( $_REQUEST['post_ID'] ) : 0 );
		// phpcs:enable

		return $post_id && self::TYPE === get_post_type( $post_id );
	}
}
