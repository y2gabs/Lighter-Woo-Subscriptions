/**
 * Lighter Woo Subscriptions: billing schedule and recurring total in the Cart and Checkout blocks.
 * Plain script (no build step); data comes from the Store API "lws" extension namespace.
 */
( function ( wc, wp ) {
	if ( ! wc || ! wc.blocksCheckout || ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var checkout = wc.blocksCheckout;

	function withSchedule( value, extensions ) {
		var data = extensions && extensions.lws;
		return data && data.schedule ? value + ' ' + data.schedule : value;
	}

	if ( checkout.registerCheckoutFilters ) {
		checkout.registerCheckoutFilters( 'lighter-woo-subscriptions', {
			cartItemPrice: withSchedule,
			subtotalPriceFormat: withSchedule,
		} );
	}

	function RecurringTotal( props ) {
		var data = props.extensions && props.extensions.lws;
		if ( ! data || ! data.has_subscription ) {
			return null;
		}
		return el(
			'div',
			{ className: 'wc-block-components-totals-wrapper' },
			el(
				'div',
				{ className: 'wc-block-components-totals-item lws-recurring-total' },
				el( 'span', { className: 'wc-block-components-totals-item__label' }, __( 'Recurring total', 'lighter-woo-subscriptions' ) ),
				el( 'span', { className: 'wc-block-components-totals-item__value' }, data.recurring_total + ' ' + data.schedule ),
				el( 'div', { className: 'wc-block-components-totals-item__description' }, data.first_renewal )
			)
		);
	}

	if ( checkout.ExperimentalOrderMeta ) {
		wp.plugins.registerPlugin( 'lighter-woo-subscriptions', {
			scope: 'woocommerce-checkout',
			render: function () {
				return el( checkout.ExperimentalOrderMeta, null, el( RecurringTotal, null ) );
			},
		} );
	}
} )( window.wc, window.wp );
