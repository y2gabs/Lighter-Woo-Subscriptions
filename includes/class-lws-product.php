<?php
/**
 * Subscription settings on simple and variable products.
 *
 * Any simple or variable product becomes a subscription by ticking "Subscription" next to
 * "Virtual" and "Downloadable". No custom product types are needed, so themes, add-to-cart
 * forms and other plugins keep working unchanged.
 *
 * @package LighterWooSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product admin fields and price display.
 */
class LWS_Product {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'product_type_options', array( __CLASS__, 'add_type_option' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'output_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'output_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'admin_script' ) );

		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 10, 2 );
	}

	/**
	 * Adds the "Subscription" checkbox.
	 *
	 * @param array $options Product type options.
	 * @return array
	 */
	public static function add_type_option( $options ) {
		$options['lws_subscription'] = array(
			'id'            => '_lws_subscription',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => __( 'Subscription', 'lighter-woo-subscriptions' ),
			'description'   => __( 'Bill customers for this product on a recurring schedule.', 'lighter-woo-subscriptions' ),
			'default'       => 'no',
		);
		return $options;
	}

	/**
	 * Schedule fields in the General tab.
	 */
	public static function output_fields() {
		global $product_object;

		$product = $product_object instanceof WC_Product ? $product_object : null;

		echo '<div class="options_group lws-subscription-fields show_if_simple show_if_variable">';

		woocommerce_wp_select(
			array(
				'id'          => '_lws_interval',
				'label'       => __( 'Bill every', 'lighter-woo-subscriptions' ),
				'options'     => self::interval_options(),
				'value'       => $product ? max( 1, absint( $product->get_meta( '_lws_interval' ) ) ) : 1,
				'desc_tip'    => true,
				'description' => __( 'With the period below, e.g. every 3 months. The product price is charged each time.', 'lighter-woo-subscriptions' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_lws_period',
				'label'   => __( 'Billing period', 'lighter-woo-subscriptions' ),
				'options' => lws_get_billing_periods(),
				'value'   => $product && $product->get_meta( '_lws_period' ) ? $product->get_meta( '_lws_period' ) : 'month',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_lws_length',
				'label'             => __( 'Number of payments', 'lighter-woo-subscriptions' ),
				'type'              => 'number',
				'value'             => $product ? absint( $product->get_meta( '_lws_length' ) ) : 0,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Total payments including the first one. 0 bills until the customer cancels.', 'lighter-woo-subscriptions' ),
			)
		);

		echo '<p class="form-field show_if_variable"><em>' . esc_html__( 'Variations use these values unless they set their own.', 'lighter-woo-subscriptions' ) . '</em></p>';
		echo '</div>';
	}

	/**
	 * Saves product-level settings.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies the product nonce before this hook.
		$enabled = isset( $_POST['_lws_subscription'] ) && $product->is_type( array( 'simple', 'variable' ) );
		$product->update_meta_data( '_lws_subscription', $enabled ? 'yes' : 'no' );

		if ( isset( $_POST['_lws_interval'] ) ) {
			$product->update_meta_data( '_lws_interval', max( 1, absint( wp_unslash( $_POST['_lws_interval'] ) ) ) );
		}
		if ( isset( $_POST['_lws_period'] ) ) {
			$period = sanitize_key( wp_unslash( $_POST['_lws_period'] ) );
			$product->update_meta_data( '_lws_period', array_key_exists( $period, lws_get_billing_periods() ) ? $period : 'month' );
		}
		if ( isset( $_POST['_lws_length'] ) ) {
			$product->update_meta_data( '_lws_length', absint( wp_unslash( $_POST['_lws_length'] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Per-variation overrides.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Unused.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function output_variation_fields( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}

		$inherit = array( '' => __( 'Same as parent', 'lighter-woo-subscriptions' ) );

		echo '<div class="lws-subscription-fields lws-variation-fields">';
		woocommerce_wp_select(
			array(
				'id'            => "_lws_interval_{$loop}",
				'name'          => "_lws_interval[{$loop}]",
				'label'         => __( 'Bill every', 'lighter-woo-subscriptions' ),
				'options'       => $inherit + self::interval_options(),
				'value'         => $product->get_meta( '_lws_interval' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => "_lws_period_{$loop}",
				'name'          => "_lws_period[{$loop}]",
				'label'         => __( 'Billing period', 'lighter-woo-subscriptions' ),
				'options'       => $inherit + lws_get_billing_periods(),
				'value'         => $product->get_meta( '_lws_period' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => "_lws_length_{$loop}",
				'name'              => "_lws_length[{$loop}]",
				'label'             => __( 'Number of payments (blank = same as parent, 0 = until cancelled)', 'lighter-woo-subscriptions' ),
				'type'              => 'number',
				'value'             => $product->get_meta( '_lws_length' ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
		echo '</div>';
	}

	/**
	 * Saves per-variation overrides. Blank values inherit from the parent.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index.
	 */
	public static function save_variation( $variation_id, $loop ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies the save-variations nonce.
		$interval = isset( $_POST['_lws_interval'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_lws_interval'][ $loop ] ) ) : '';
		$period   = isset( $_POST['_lws_period'][ $loop ] ) ? sanitize_key( wp_unslash( $_POST['_lws_period'][ $loop ] ) ) : '';
		$length   = isset( $_POST['_lws_length'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_lws_length'][ $loop ] ) ) : '';
		// phpcs:enable

		$variation->update_meta_data( '_lws_interval', '' === $interval ? '' : max( 1, absint( $interval ) ) );
		$variation->update_meta_data( '_lws_period', array_key_exists( $period, lws_get_billing_periods() ) ? $period : '' );
		$variation->update_meta_data( '_lws_length', '' === $length ? '' : absint( $length ) );
		$variation->save();
	}

	/**
	 * Shows the schedule fields only while the "Subscription" box is ticked.
	 */
	public static function admin_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery( function ( $ ) {
			function lwsToggle() {
				$( '.lws-subscription-fields' ).toggle( $( '#_lws_subscription' ).is( ':checked' ) );
			}
			$( '#_lws_subscription' ).on( 'change', lwsToggle );
			$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded woocommerce_variations_added', lwsToggle );
			$( 'select#product-type' ).on( 'change', function () { setTimeout( lwsToggle, 0 ); } );
			lwsToggle();
		} );
		</script>
		<?php
	}

	/**
	 * Appends the schedule to prices, e.g. "$10.00 / month".
	 *
	 * @param string     $price_html Price HTML.
	 * @param WC_Product $product    Product.
	 * @return string
	 */
	public static function price_html( $price_html, $product ) {
		if ( '' === $price_html ) {
			return $price_html;
		}

		$schedule = lws_get_product_schedule( $product );
		if ( ! $schedule ) {
			return $price_html;
		}

		// A variable product whose variations bill on different schedules shows no single schedule.
		if ( $product->is_type( 'variable' ) ) {
			$keys = array();
			foreach ( $product->get_visible_children() as $child_id ) {
				$child_schedule = lws_get_product_schedule( $child_id );
				if ( $child_schedule ) {
					$keys[ lws_schedule_key( $child_schedule ) ] = $child_schedule;
				}
			}
			if ( count( $keys ) > 1 ) {
				return $price_html . ' <span class="lws-schedule">' . esc_html__( '(subscription)', 'lighter-woo-subscriptions' ) . '</span>';
			}
			if ( 1 === count( $keys ) ) {
				$schedule = reset( $keys );
			}
		}

		return $price_html . ' <span class="lws-schedule">' . esc_html( lws_get_schedule_string( $schedule['interval'], $schedule['period'], $schedule['length'] ) ) . '</span>';
	}

	/**
	 * "Subscribe" button text for simple subscription products.
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function add_to_cart_text( $text, $product = null ) {
		if ( $product instanceof WC_Product && $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && lws_is_subscription_product( $product ) ) {
			return __( 'Subscribe', 'lighter-woo-subscriptions' );
		}
		return $text;
	}

	/**
	 * Interval choices 1-6.
	 *
	 * @return array<int,string>
	 */
	private static function interval_options() {
		$options = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$options[ $i ] = (string) $i;
		}
		return $options;
	}
}
