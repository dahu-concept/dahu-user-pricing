<?php
/**
 * Orders created or edited in wp-admin.
 *
 * THE PROBLEM. A line added from the order screen's "Add product" modal is
 * priced from the catalogue, by a request whose current user is the shop
 * manager. Nothing in that path knows the order's customer, so a trade
 * customer's manually built order comes out at public prices — which is how a
 * reseller gets invoiced the wrong amount by somebody who was only trying to
 * help them over the phone.
 *
 * THE FIX, AND WHY IT IS NOT A PRICE FILTER. `set_subtotal()` and
 * `set_total()` write amounts onto the line itself. No price filter, this
 * plugin's own included, can reach a value written that way — which is exactly
 * why this module exists rather than being handled by the filters in
 * class-dahu-user-pricing-prices.php. The line is repriced explicitly, from
 * the ORDER's customer, after WooCommerce has finished adding it.
 *
 * WHAT THE PORT CHANGED. The version this came from decomposed tax by hand:
 * it read the display setting `woocommerce_tax_display_shop`, called
 * WC_Tax::calc_inclusive_tax() and assigned the pieces itself. That was
 * necessary there, because the price it was handed had already been converted
 * for display by another plugin. Here the tier price is stored in the shop's
 * own price base, so the question is `wc_prices_include_tax()`, not a display
 * setting — and `wc_get_price_excluding_tax()` answers it with the same code
 * WooCommerce uses to build a cart line. Taxes are then left to
 * `$order->calculate_totals()`, which knows the customer's address, their tax
 * class and compound rates. A second tax implementation living in a plugin is
 * a second tax implementation to keep correct.
 *
 * SUBTOTAL AND TOTAL BOTH. Subtotal is the line before order-level discounts,
 * total is after. Repricing sets both to the tier amount, because a tier price
 * is the price, not a discount off another one — and a line whose subtotal and
 * total differ renders as "discounted" in every order view and every invoice.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-side order pricing.
 */
class Dahu_User_Pricing_Order {

	/**
	 * Nonce action of the reprice endpoint.
	 */
	const RECALC_NONCE = 'dahu-user-pricing-recalc';

	/**
	 * Hook the module.
	 *
	 * @return void
	 */
	public static function init() {

		// After WooCommerce has added the items (and after any other pricing
		// plugin, which conventionally works at 100).
		add_action( 'woocommerce_ajax_order_items_added', array( __CLASS__, 'reprice_added_items' ), 200, 2 );

		add_action( 'wp_ajax_dahu_user_pricing_recalc_order', array( __CLASS__, 'ajax_recalc' ) );
	}

	/**
	 * Reprice the lines that were just added from the modal.
	 *
	 * ONLY those lines. The version this was ported from repriced the whole
	 * order on every item add, so that a customer corrected after the first
	 * line would reprice the first line too. That is a silent rewrite of every
	 * amount on the order, with no undo: a line somebody had typed by hand
	 * came back at the catalogue's figure, and WooCommerce saves hand edits in
	 * the same request, just before this hook. Whole-order repricing is now
	 * the explicit button's job, and nothing else's.
	 *
	 * @param mixed    $added_items Items just added, keyed by line id.
	 * @param WC_Order $order       The order being edited.
	 * @return void
	 */
	public static function reprice_added_items( $added_items, $order ) {

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		// On "Add order", the customer chosen in #customer_user is not written
		// to the draft until "Create" is clicked, so get_user_id() is 0 and
		// every line would be priced publicly. The browser sends the field's
		// current value with the request; persisting it here just gives the
		// draft, a few seconds early, the value the Create button would have
		// given it anyway.
		//
		// The request is WooCommerce's own order-item AJAX call, whose
		// 'order-item' nonce it verified before this hook could run.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before this hook.
		if ( ! $order->get_user_id() && isset( $_POST['dahu_user_pricing_customer'] ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
			$posted = absint( wp_unslash( $_POST['dahu_user_pricing_customer'] ) );

			if ( $posted && get_user_by( 'id', $posted ) ) {
				$order->set_customer_id( $posted );
				$order->save();
			}
		}

		$ids = is_array( $added_items ) ? array_map( 'absint', array_keys( $added_items ) ) : array();

		self::reprice( $order, $ids );
	}

	/**
	 * Reprice lines of an order from its customer's tier.
	 *
	 * @param WC_Order   $order    The order.
	 * @param int[]|null $item_ids Lines to reprice, or null for all of them.
	 * @return bool True if anything changed.
	 */
	public static function reprice( WC_Order $order, $item_ids = null ) {

		if ( ! self::may_reprice( $order ) ) {
			return false;
		}

		$user = $order->get_user();
		$user = $user instanceof WP_User ? $user : null;

		$only    = is_array( $item_ids ) ? array_map( 'absint', $item_ids ) : null;
		$changed = false;

		foreach ( $order->get_items() as $item_id => $item ) {

			if ( null !== $only && ! in_array( (int) $item_id, $only, true ) ) {
				continue;
			}

			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( self::is_child_line( $item ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// A float, not an int. Plugins that allow decimal quantities are
			// common in trade shops — metres of cable, kilos of something —
			// and truncating 2.5 to 2 under-invoices by a full half unit while
			// leaving 2.5 on the line for anybody to read.
			$quantity = (float) $item->get_quantity();

			if ( $quantity <= 0 ) {
				continue;
			}

			$pricing = self::resolve_line_pricing( $product, $user, $quantity );

			if ( null === $pricing['price_excl_tax'] ) {
				continue;
			}

			$line_total = (float) $pricing['price_excl_tax'] * $quantity;

			if ( self::same_money( $item->get_subtotal(), $line_total )
				&& self::same_money( $item->get_total(), $line_total ) ) {
				continue;
			}

			// Subtotal is the line before order-level discounts, total is
			// after. Both are set to the tier amount here, and any discount is
			// then recomputed below from the coupons the order actually
			// carries — writing the same figure into both and stopping there
			// is what silently voids a coupon while leaving it on display.
			$item->set_subtotal( $line_total );
			$item->set_total( $line_total );
			$item->save();

			$changed = true;
		}

		if ( ! $changed ) {
			return false;
		}

		if ( $order->get_coupon_codes() && is_callable( array( $order, 'recalculate_coupons' ) ) ) {
			// Recomputes each coupon's discount against the new line amounts
			// and writes the line totals back. It calls calculate_totals()
			// itself, so the call below is for the order that has no coupon.
			$order->recalculate_coupons();
		} else {
			// Recomputes every line's tax from the order's own tax location,
			// then the order totals. Nothing in this plugin calculates tax.
			$order->calculate_totals();
		}

		$order->save();

		return true;
	}

	/**
	 * Whether an order may be repriced at all.
	 *
	 * Repricing rewrites amounts on lines. On an order that has been paid, or
	 * refunded, or is otherwise past editing, those amounts are a record of a
	 * transaction that already happened: a captured payment, an invoice sent, a
	 * refund calculated against a total. Rewriting them does not change any of
	 * that, it just makes the order disagree with it — an order that reads as
	 * partly unpaid when it was paid in full, with no note to say why.
	 *
	 * WooCommerce answers the first question itself with is_editable(), which
	 * is true for pending, on-hold and auto-draft and false once an order is
	 * processing or completed. Refunds are checked separately because an
	 * order can be refunded while still editable.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function may_reprice( WC_Order $order ) {

		if ( ! $order->is_editable() ) {
			return false;
		}

		if ( (float) $order->get_total_refunded() > 0 ) {
			return false;
		}

		/**
		 * Filter whether an order may have its lines repriced.
		 *
		 * @param bool     $allowed Whether repricing is allowed.
		 * @param WC_Order $order   The order.
		 */
		return (bool) apply_filters( 'dahu_user_pricing_may_reprice_order', true, $order );
	}

	/**
	 * Reprice on demand, when the customer field changes.
	 *
	 * @return void
	 */
	public static function ajax_recalc() {

		check_ajax_referer( self::RECALC_NONCE, 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit orders.', 'dahu-user-pricing' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'dahu-user-pricing' ) ), 400 );
		}

		$customer_id = isset( $_POST['customer_user'] ) ? absint( wp_unslash( $_POST['customer_user'] ) ) : 0;

		// An emptied field is a legitimate instruction: it means "no customer",
		// and the lines must go back to public prices.
		if ( $customer_id && ! get_user_by( 'id', $customer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Customer not found.', 'dahu-user-pricing' ) ), 400 );
		}

		if ( ! self::may_reprice( $order ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This order can no longer be repriced: it has been paid, refunded, or is past editing.', 'dahu-user-pricing' ),
				),
				409
			);
		}

		if ( (int) $order->get_customer_id() !== $customer_id ) {
			$order->set_customer_id( $customer_id );
			$order->save();
		}

		wp_send_json_success( array( 'changed' => self::reprice( $order ) ) );
	}

	/**
	 * Unit price net of tax for a product, as a given customer pays it.
	 *
	 * Tier pricing is suspended for the duration: the price wanted here is the
	 * ORDER's customer's, and the current user is a shop manager who may hold
	 * a tier of their own. Reading the product's price through the filters
	 * would quietly use theirs.
	 *
	 * @param WC_Product   $product  Product or variation.
	 * @param WP_User|null $user     Customer, or null for the public price.
	 * @param float        $quantity Quantity, passed through to the price
	 *                               filter so a source can price by quantity.
	 *                               The amount returned is still per unit.
	 * @return array{price_excl_tax: float|null, tier: string|null}
	 */
	public static function resolve_line_pricing( $product, $user, $quantity = 1 ) {

		$none = array(
			'price_excl_tax' => null,
			'tier'           => null,
		);

		if ( ! $product instanceof WC_Product ) {
			return $none;
		}

		$tier = ( $user instanceof WP_User )
			? Dahu_User_Pricing_Context::tier_from_roles( (array) $user->roles )
			: null;

		Dahu_User_Pricing_Prices::suspend();

		// try/finally, and not for tidiness: a price source hooked onto
		// dahu_user_pricing_price is third-party code, and anything thrown in
		// here would leave the counter above zero for the rest of the request.
		// Tier pricing would then be off everywhere, with no error on screen —
		// a visitor's catalogue and cart quietly reverting to public prices.
		try {

			$price = null;

			if ( null !== $tier ) {
				$price = Dahu_User_Pricing_Prices::get_tier_price( $product, $tier, $quantity );
			}

			if ( null === $price ) {
				$price = Dahu_User_Pricing_Prices::get_public_price( $product );
				$tier  = null;
			}

			$excl = ( null === $price )
				? null
				: (float) wc_get_price_excluding_tax(
					$product,
					array(
						'qty'   => 1,
						'price' => $price,
					)
				);

		} finally {
			Dahu_User_Pricing_Prices::resume();
		}

		return array(
			'price_excl_tax' => $excl,
			'tier'           => $tier,
		);
	}

	/**
	 * Whether a line's price belongs to another line.
	 *
	 * Bundling plugins add a component as its own line and carry the price on
	 * the parent, leaving the component at zero — which is what an order placed
	 * on the front end looks like. Repricing such a line from its own product
	 * price charges the customer for it twice, and does so quietly, because
	 * nothing about the line says its price is somebody else's.
	 *
	 * This plugin does not depend on any of those plugins; it recognises the
	 * metas they set so as not to damage them. The list is filterable because
	 * there are more bundling plugins than anybody can enumerate.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @return bool
	 */
	private static function is_child_line( $item ) {

		/**
		 * Metas marking a line whose price is carried by a parent line.
		 *
		 * @param string[] $metas Meta keys.
		 */
		$metas = apply_filters(
			'dahu_user_pricing_child_line_metas',
			array( '_bundled_by', '_composite_parent', '_composite_item' )
		);

		foreach ( (array) $metas as $meta ) {

			if ( $item->get_meta( (string) $meta, true ) ) {

				// One exception, and it is the bundling plugins' own: a
				// component configured as priced individually behaves like an
				// ordinary line, on the front end as here.
				if ( '_bundled_by' === $meta
					&& 'yes' === $item->get_meta( '_bundled_item_priced_individually', true ) ) {
					continue;
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two amounts are the same money.
	 *
	 * Compared at the shop's precision rather than with ===, so that a line
	 * already carrying the right amount is left untouched instead of being
	 * rewritten — and the order not re-totalled — because of a difference in
	 * the twelfth decimal of a float.
	 *
	 * @param mixed $a First amount.
	 * @param mixed $b Second amount.
	 * @return bool
	 */
	private static function same_money( $a, $b ) {

		$decimals = wc_get_price_decimals();

		return round( (float) $a, $decimals ) === round( (float) $b, $decimals );
	}
}
