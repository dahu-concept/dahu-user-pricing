<?php
/**
 * The price source, and how it reaches WooCommerce.
 *
 * WHY THIS PLUGIN OWNS ITS PRICES. The version this was ported from was a
 * display layer: it formatted and labelled prices that a commercial plugin
 * computed, and did nothing at all on its own. That is fine for one shop that
 * owns a licence for the other plugin, and useless to anybody else — a pricing
 * plugin that cannot name a price is not installable. So a tier price is a
 * value stored here, on the product, and `dahu_user_pricing_price` is the way
 * for another plugin to supply one instead.
 *
 * HOW IT REACHES WOOCOMMERCE. Only `price` is filtered; `regular_price` is
 * left alone. That keeps one thing true that is otherwise easy to lose: the
 * public price of a product remains readable while a tier price is in force,
 * which is what lets the display show both, and the margin between them.
 *
 * THE VARIATION PRICE CACHE. WooCommerce caches the price range of a variable
 * product in a transient keyed by a hash. A plugin that changes variation
 * prices per user and forgets to add the user's tier to that hash will serve
 * one account's prices to another from cache — the single most common defect
 * in this category of plugin, and invisible in testing because it needs two
 * accounts and a warm cache to show up. The tier goes into the hash below.
 *
 * WHAT IS NOT APPLIED. A shop sale price does not stack on top of a tier
 * price: a tier price is a negotiated figure, not a discount off the public
 * one, and compounding them would make a reseller's price depend on a
 * promotion they never agreed to. A shop that wants the lower of the two can
 * say so in three lines on `dahu_user_pricing_price`.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-tier prices and their WooCommerce integration.
 */
class Dahu_User_Pricing_Prices {

	/**
	 * Whether this request is the order screen editing an order, memoised.
	 *
	 * Three states: unset, yes, no. Resolving it costs a nonce verification,
	 * and a shop page prices hundreds of products.
	 *
	 * @var bool|null
	 */
	private static $is_order_screen_request = null;

	/**
	 * Depth of nested suspensions.
	 *
	 * A counter rather than a boolean: resume() must not re-enable pricing
	 * that an outer caller had suspended, and nesting happens as soon as one
	 * suspended routine calls another.
	 *
	 * @var int
	 */
	private static $suspended = 0;

	/**
	 * Hook the filters.
	 *
	 * @return void
	 */
	public static function init() {

		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_product_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_product_price' ), 10, 2 );

		// Variable products: the cached price range, and the key it is cached
		// under. Both or neither — see the file header.
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_prices_price' ), 10, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_variation_prices_hash' ), 10, 3 );
	}

	/**
	 * Stop applying tier prices until resume() is called.
	 *
	 * Used by code that prices a product for somebody other than the current
	 * user — an order's customer — where the current user's own tier would be
	 * the wrong answer.
	 *
	 * @return void
	 */
	public static function suspend() {
		self::$suspended++;
	}

	/**
	 * Resume applying tier prices.
	 *
	 * @return void
	 */
	public static function resume() {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	/**
	 * Whether tier pricing currently applies to the request at all.
	 *
	 * Admin SCREENS are excluded: prices shown in wp-admin are the shop's own
	 * prices, and a shop manager who also happens to hold a tier — test
	 * accounts usually do — must not see their own tier's price while editing
	 * a catalogue.
	 *
	 * admin-ajax needs a finer answer, because two unrelated things run
	 * through it. The classic front-end add-to-cart and cart-fragment requests
	 * are part of a visitor's session and must be priced for them. The order
	 * screen's own requests are not: there, WooCommerce prices a line from the
	 * catalogue — `$order->add_product()` calls `$product->get_price()` — and
	 * writes the amount ONTO the line before this plugin's repricing hook has
	 * run. Priced for the current user, that amount is the shop manager's own
	 * tier, or the tier they happen to be previewing, written onto another
	 * customer's order. Repricing afterwards usually corrects it, but not for
	 * a line the repricing is right to skip, and "usually corrected" is not a
	 * property to rely on for an invoice.
	 *
	 * The discriminator is WooCommerce's own 'order-item' nonce. Every
	 * order-editing AJAX endpoint in class-wc-ajax.php verifies it, so its
	 * presence identifies the request exactly — and it keeps identifying it
	 * when a WooCommerce release adds another such endpoint, which a list of
	 * action names would not.
	 *
	 * @return bool
	 */
	public static function is_active() {

		if ( self::$suspended > 0 ) {
			return false;
		}

		if ( ! is_admin() ) {
			return true;
		}

		if ( ! wp_doing_ajax() ) {
			return false;
		}

		return ! self::is_order_screen_request();
	}

	/**
	 * Whether this request is an order-editing request from the order screen.
	 *
	 * @return bool
	 */
	public static function is_order_screen_request() {

		if ( null !== self::$is_order_screen_request ) {
			return self::$is_order_screen_request;
		}

		$nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : '';

		self::$is_order_screen_request = ( '' !== $nonce && false !== wp_verify_nonce( $nonce, 'order-item' ) );

		return self::$is_order_screen_request;
	}

	/**
	 * Forget the memoised request kind.
	 *
	 * Only a test has any reason to call this: within one real request the
	 * answer cannot change.
	 *
	 * @return void
	 */
	public static function flush_request_kind() {
		self::$is_order_screen_request = null;
	}

	/**
	 * Price of a product for the current user.
	 *
	 * @param string|float $price   Price as WooCommerce has it.
	 * @param WC_Product   $product Product.
	 * @return string|float
	 */
	public static function filter_product_price( $price, $product ) {

		if ( ! self::is_active() ) {
			return $price;
		}

		$tier = Dahu_User_Pricing_Context::get_tier();

		if ( null === $tier ) {
			return $price;
		}

		$tier_price = self::get_tier_price( $product, $tier );

		return null === $tier_price ? $price : $tier_price;
	}

	/**
	 * Price of one variation inside the cached price range of its parent.
	 *
	 * @param string|float $price     Price as WooCommerce has it.
	 * @param WC_Product   $variation The variation.
	 * @param WC_Product   $product   The parent variable product.
	 * @return string|float
	 */
	public static function filter_variation_prices_price( $price, $variation, $product ) {

		unset( $product );

		return self::filter_product_price( $price, $variation );
	}

	/**
	 * Add the tier to the variation price cache key.
	 *
	 * Without this, the first account to warm the cache decides what every
	 * other account sees. The tier string is enough: nothing else in this
	 * plugin changes a variation's price.
	 *
	 * @param array      $hash    Hash parts.
	 * @param WC_Product $product Product.
	 * @param bool       $display Whether the prices are for display.
	 * @return array
	 */
	public static function filter_variation_prices_hash( $hash, $product, $display ) {

		unset( $product, $display );

		if ( ! self::is_active() ) {
			return $hash;
		}

		$tier = Dahu_User_Pricing_Context::get_tier();

		if ( null !== $tier ) {
			$hash['dahu_user_pricing_tier'] = $tier;
		}

		return $hash;
	}

	/**
	 * The tier price of a product, or null if it has none.
	 *
	 * A variation with no price of its own inherits its parent's tier price,
	 * which matches how WooCommerce treats most variation fields and saves
	 * filling in the same figure on forty variations.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param string     $tier     Tier key.
	 * @param float      $quantity Quantity being priced, for a source that
	 *                             prices by quantity. One when unknown — it
	 *                             is unknown on a catalogue page, and a
	 *                             source wanting quantity breaks has to treat
	 *                             that as the single-unit price.
	 * @return float|null
	 */
	public static function get_tier_price( $product, $tier, $quantity = 1 ) {

		$price = null;

		if ( $product instanceof WC_Product && Dahu_User_Pricing_Tiers::exists( $tier ) ) {

			$price = self::read_stored_price( $product, $tier );

			if ( null === $price && $product->is_type( 'variation' ) ) {

				$parent = wc_get_product( $product->get_parent_id() );

				if ( $parent instanceof WC_Product ) {
					$price = self::read_stored_price( $parent, $tier );
				}
			}
		}

		/**
		 * Filter the tier price of a product.
		 *
		 * This is the way to put another source of prices behind this plugin
		 * — a commercial pricing plugin, a quantity-break table, a figure
		 * coming from an ERP. Return null for "no tier price", in which case
		 * the product's public price applies.
		 *
		 * @param float|null $price    Price net of nothing: in whatever base
		 *                             the shop stores prices in, exactly like
		 *                             a product's own `_price`.
		 * @param WC_Product $product  Product or variation.
		 * @param string     $tier     Tier key.
		 * @param float      $quantity Quantity being priced, 1 when unknown.
		 */
		$price = apply_filters( 'dahu_user_pricing_price', $price, $product, $tier, max( 0.0, (float) $quantity ) );

		return null === $price ? null : (float) $price;
	}

	/**
	 * Read a stored tier price from a product's meta.
	 *
	 * An empty string means "no price for this tier"; zero does NOT. A tier
	 * whose price is 0 gets the product for nothing, and that is a legitimate
	 * thing to configure — so the test is against the empty string, never
	 * empty(), which would quietly turn free into full price.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $tier    Tier key.
	 * @return float|null
	 */
	private static function read_stored_price( $product, $tier ) {

		$raw = $product->get_meta( Dahu_User_Pricing_Tiers::price_meta_key( $tier ), true );

		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$raw = trim( (string) $raw );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		$price = (float) wc_format_decimal( $raw );

		// A negative price is not a price. Treated as unset rather than
		// clamped to zero: zero means free, and guessing that somebody meant
		// free would be a worse answer than ignoring a value that cannot be
		// right.
		return $price < 0 ? null : $price;
	}

	/**
	 * The public price of a product — what it costs with no tier applied.
	 *
	 * Needed by the display layer, which shows both figures, and it cannot
	 * simply read get_regular_price(): a product on sale has a public price
	 * that is its sale price, not its regular one.
	 *
	 * The 'edit' context is what makes this reliable rather than circular.
	 * WooCommerce applies `woocommerce_product_get_price` — this plugin's own
	 * filter included — only in the 'view' context, so asking in 'edit' reads
	 * the stored price and cannot be answered by our own override. It is also
	 * empty for a variable product, which has no single price; the display
	 * layer handles that case by range.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return float|null
	 */
	public static function get_public_price( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$price = $product->get_price( 'edit' );

		return ( '' === $price || null === $price ) ? null : (float) $price;
	}
}
