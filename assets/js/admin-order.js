/*
 * Order screen: get manually built orders priced for the right customer.
 *
 * Two problems, and they are not the same problem.
 *
 * 1. On "Add order", the customer chosen in #customer_user is not written to
 *    the draft until Create is clicked. WooCommerce prices a line as it adds
 *    it, so every line added before that moment is priced publicly, with
 *    nothing on screen to say why. The field's current value is therefore sent
 *    along with the add-item request, and the server persists it before
 *    pricing.
 *
 * 2. Changing the customer on an order that already has lines leaves those
 *    lines at the previous customer's prices. The server can reprice them —
 *    but it is NOT done automatically, and that is deliberate: a line may have
 *    been adjusted by hand, and a silent reprice would throw that away with no
 *    undo. A button appears instead, and nothing happens until it is pressed.
 *
 * No modal dialog anywhere in here on purpose: a confirm() on an admin screen
 * blocks everything behind it, including anything driving the browser.
 */

( function ( $, config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var BUTTON_CLASS = 'js-dahu-user-pricing-reprice';

	/**
	 * The customer currently chosen in the order screen's field.
	 *
	 * @return {number} User id, or 0 for none.
	 */
	function chosenCustomer() {
		var field = $( '#customer_user' );

		if ( ! field.length ) {
			return 0;
		}

		return parseInt( field.val(), 10 ) || 0;
	}

	/**
	 * The order being edited.
	 *
	 * woocommerce_admin_meta_boxes.post_id is what WooCommerce's own order
	 * item scripts use, and it is set under both order storages. The hidden
	 * post field is a fallback for a screen that did not localise it.
	 *
	 * @return {number} Order id, or 0 if it cannot be determined.
	 */
	function orderId() {
		if ( window.woocommerce_admin_meta_boxes && window.woocommerce_admin_meta_boxes.post_id ) {
			return parseInt( window.woocommerce_admin_meta_boxes.post_id, 10 ) || 0;
		}

		return parseInt( $( '#post_ID' ).val(), 10 ) || 0;
	}

	/**
	 * Whether the order already has lines worth repricing.
	 *
	 * @return {boolean} True when at least one item row is present.
	 */
	function hasLines() {
		return $( '#order_line_items' ).find( 'tr.item' ).length > 0;
	}

	/* --- 1. Carry the chosen customer with every add-item request. -------- */

	$.ajaxPrefilter( function ( options ) {
		if ( ! options.data || typeof options.data !== 'string' ) {
			return;
		}

		if ( options.data.indexOf( 'action=woocommerce_add_order_item' ) === -1 ) {
			return;
		}

		options.data += '&dahu_user_pricing_customer=' + chosenCustomer();
	} );

	/* --- 2. Offer a reprice when the customer changes. ------------------- */

	/**
	 * Show the reprice button, once.
	 *
	 * @return {void}
	 */
	function offerReprice() {
		if ( $( '.' + BUTTON_CLASS ).length ) {
			return;
		}

		var button = $( '<button/>', {
			type: 'button',
			'class': 'button button-secondary ' + BUTTON_CLASS,
			text: config.repriceLabel
		} );

		var anchor = $( '#woocommerce-order-items' ).find( 'button.add-line-item' ).first();

		if ( anchor.length ) {
			anchor.parent().append( button );
			return;
		}

		$( '#woocommerce-order-items' ).find( '.inside' ).first().prepend( button );
	}

	/**
	 * Reload the order items panel, the way WooCommerce reloads it itself
	 * after recalculating taxes.
	 *
	 * @return {void}
	 */
	function reloadItems() {
		var boxes = window.woocommerce_admin_meta_boxes;

		if ( ! boxes || ! boxes.ajax_url || ! boxes.order_item_nonce ) {
			window.location.reload();
			return;
		}

		$.ajax( {
			url: boxes.ajax_url,
			data: {
				order_id: orderId(),
				action: 'woocommerce_load_order_items',
				security: boxes.order_item_nonce
			},
			type: 'POST',
			success: function ( response ) {
				$( '#woocommerce-order-items' ).find( '.inside' ).empty().append( response );
			},
			complete: function () {
				unblock();
			}
		} );
	}

	/**
	 * Block the items panel while a request is in flight, if blockUI is here.
	 *
	 * @return {void}
	 */
	function block() {
		var panel = $( '#woocommerce-order-items' );

		if ( panel.block ) {
			panel.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		}
	}

	/**
	 * Remove the block.
	 *
	 * @return {void}
	 */
	function unblock() {
		var panel = $( '#woocommerce-order-items' );

		if ( panel.unblock ) {
			panel.unblock();
		}
	}

	/**
	 * Say that the reprice failed, in the panel rather than in a dialog.
	 *
	 * @return {void}
	 */
	function showFailure() {
		var panel = $( '#woocommerce-order-items' ).find( '.inside' ).first();

		if ( ! panel.length || panel.find( '.js-dahu-user-pricing-failed' ).length ) {
			return;
		}

		panel.prepend(
			$( '<div/>', {
				'class': 'notice notice-error inline js-dahu-user-pricing-failed'
			} ).append( $( '<p/>', { text: config.failed } ) )
		);
	}

	$( document ).on( 'change', '#customer_user', function () {
		if ( hasLines() ) {
			offerReprice();
		}
	} );

	$( document ).on( 'click', '.' + BUTTON_CLASS, function ( event ) {
		event.preventDefault();

		var order = orderId();

		if ( ! order ) {
			return;
		}

		block();

		$.post( config.ajaxUrl, {
			action: 'dahu_user_pricing_recalc_order',
			security: config.nonce,
			order_id: order,
			customer_user: chosenCustomer()
		} )
			.done( function () {
				$( '.' + BUTTON_CLASS ).remove();
				reloadItems();
			} )
			.fail( function () {
				unblock();
				showFailure();
			} );
	} );
} )( jQuery, window.dahuUserPricingOrder );
