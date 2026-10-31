<?php
/**
 * What a price looks like once a tier applies.
 *
 * WHY THIS IS SERVER-SIDE. The version this was ported from did all of it in
 * JavaScript: five scripts walked the rendered page, found amounts by class
 * name, recomputed them and wrote them back. That works on exactly one theme,
 * breaks silently when the theme changes a wrapper, flashes the wrong price
 * before it runs, and is invisible to anything that reads the page without
 * running scripts. None of it survived the port. The price is assembled here,
 * once, from figures the server already has.
 *
 * THE VIEW SWITCH IS DISPLAY ONLY. Switching to the public view shows a
 * reseller what their own customer pays. It does not change what the cart
 * charges, and the cart deliberately keeps showing the tier price even while
 * the catalogue shows public ones — because the cart's job is to say what this
 * account will be billed. A switch that changed the charged price would be a
 * discount control disguised as a display preference.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end rendering of tier prices.
 */
class Dahu_User_Pricing_Display {

	/**
	 * Query argument carrying a view change.
	 */
	const SWITCH_VAR = 'dahu_user_pricing_view';

	/**
	 * Nonce action protecting a view change.
	 */
	const SWITCH_NONCE = 'dahu_user_pricing_switch_view';

	/**
	 * Hook the display.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'init', array( __CLASS__, 'handle_switch' ) );

		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 20, 2 );

		add_shortcode( 'dahu_user_pricing_view_switch', array( __CLASS__, 'render_switch' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
	}

	/**
	 * Load the front stylesheet, and only where it is used.
	 *
	 * @return void
	 */
	public static function enqueue_front() {

		if ( ! Dahu_User_Pricing_Context::can_see_tier_pricing() ) {
			return;
		}

		wp_enqueue_style(
			'dahu-user-pricing',
			DAHU_USER_PRICING_URL . 'assets/css/front.css',
			array(),
			DAHU_USER_PRICING_VERSION
		);
	}

	/**
	 * Add the pricing context to the body classes.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function filter_body_class( $classes ) {

		return array_merge( (array) $classes, Dahu_User_Pricing_Context::body_classes() );
	}

	/**
	 * Act on a view switch link.
	 *
	 * A link and a cookie rather than a script: the switch has to survive a
	 * page that was served from a cache, and a cookie set by a redirected
	 * request is the one mechanism that does. The nonce is what stops a third
	 * party changing what a visitor sees by linking at them.
	 *
	 * @return void
	 */
	public static function handle_switch() {

		if ( ! isset( $_GET[ self::SWITCH_VAR ] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::SWITCH_NONCE ) ) {
			return;
		}

		if ( ! Dahu_User_Pricing_Settings::get( 'view_switch' ) ) {
			return;
		}

		if ( ! Dahu_User_Pricing_Context::can_see_tier_pricing() ) {
			return;
		}

		$requested = sanitize_key( wp_unslash( $_GET[ self::SWITCH_VAR ] ) );

		$value = ( Dahu_User_Pricing_Context::VIEW_PUBLIC === $requested )
			? Dahu_User_Pricing_Context::VIEW_PUBLIC
			: Dahu_User_Pricing_Context::VIEW_TIER;

		// A session cookie: a view preference that outlived the browser being
		// closed would have somebody quoting public prices to themselves for
		// days without remembering why.
		setcookie( Dahu_User_Pricing_Context::VIEW_COOKIE, $value, 0, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ Dahu_User_Pricing_Context::VIEW_COOKIE ] = $value;

		wp_safe_redirect( remove_query_arg( array( self::SWITCH_VAR, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * The view switch, as a shortcode.
	 *
	 * Nothing places this automatically. A plugin that injected a control into
	 * a theme's header would be guessing at a layout it cannot see; a
	 * shortcode goes where the shop decides, and a block theme can put it in a
	 * template part.
	 *
	 * @return string
	 */
	public static function render_switch() {

		if ( ! Dahu_User_Pricing_Settings::get( 'view_switch' ) ) {
			return '';
		}

		if ( ! Dahu_User_Pricing_Context::can_see_tier_pricing() ) {
			return '';
		}

		$mode = Dahu_User_Pricing_Context::view_mode();

		$target = ( Dahu_User_Pricing_Context::VIEW_TIER === $mode )
			? Dahu_User_Pricing_Context::VIEW_PUBLIC
			: Dahu_User_Pricing_Context::VIEW_TIER;

		$text = ( Dahu_User_Pricing_Context::VIEW_TIER === $mode )
			? esc_html__( 'Show public prices', 'dahu-user-pricing' )
			: esc_html__( 'Show my prices', 'dahu-user-pricing' );

		$url = wp_nonce_url(
			add_query_arg( self::SWITCH_VAR, $target ),
			self::SWITCH_NONCE
		);

		return sprintf(
			'<a class="dahu-user-pricing-switch" href="%1$s" rel="nofollow">%2$s</a>',
			esc_url( $url ),
			$text
		);
	}

	/**
	 * Replace a product's price HTML when a tier price applies.
	 *
	 * @param string     $price_html Price HTML as WooCommerce built it.
	 * @param WC_Product $product    Product.
	 * @return string
	 */
	public static function filter_price_html( $price_html, $product ) {

		if ( ! Dahu_User_Pricing_Prices::is_active() ) {
			return $price_html;
		}

		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}

		if ( ! Dahu_User_Pricing_Context::can_see_tier_pricing() ) {
			return $price_html;
		}

		$tier = Dahu_User_Pricing_Context::get_tier();

		if ( null === $tier ) {
			return $price_html;
		}

		$labels = Dahu_User_Pricing_Tiers::get_labels( $tier );

		if ( ! $labels ) {
			return $price_html;
		}

		if ( ! self::has_single_price( $product ) ) {
			return self::render_range( $product, $price_html, $labels );
		}

		$tier_price   = Dahu_User_Pricing_Prices::get_tier_price( $product, $tier );
		$public_price = Dahu_User_Pricing_Prices::get_public_price( $product );

		if ( null === $tier_price || null === $public_price ) {
			return $price_html;
		}

		return self::render_price( $product, $labels, $tier_price, $public_price );
	}

	/**
	 * Whether a product has one price rather than a range.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function has_single_price( $product ) {

		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return false;
		}

		return '' !== $product->get_price( 'edit' );
	}

	/**
	 * A price range — a variable or grouped product.
	 *
	 * In TIER view the range WooCommerce built is already made of tier prices,
	 * because this plugin filtered the variation prices it was built from, so
	 * there is nothing to recompute and only the label to add.
	 *
	 * In PUBLIC view it is not. That range is still the tier range, and
	 * prefixing it with the public label would show a reseller their own
	 * buying price under the words "suggested retail" — the one figure they
	 * are in the public view to read. So the range is rebuilt from the public
	 * prices, with tier pricing suspended, which also makes WooCommerce's own
	 * variation-price cache answer under its public key.
	 *
	 * @param WC_Product $product    Product.
	 * @param string     $price_html Price HTML as WooCommerce built it.
	 * @param array      $labels     Tier labels.
	 * @return string
	 */
	private static function render_range( $product, $price_html, $labels ) {

		if ( Dahu_User_Pricing_Context::VIEW_PUBLIC !== Dahu_User_Pricing_Context::view_mode() ) {
			return self::label_only( $price_html, $labels['tier'] );
		}

		$public_html = self::public_range_html( $product );

		if ( '' === $public_html ) {
			// Nothing reliable to show. The tier range is NOT shown under the
			// public label as a fallback: a wrong figure with a confident
			// label is worse than an unlabelled one.
			return $price_html;
		}

		return self::label_only( $public_html, $labels['public'] );
	}

	/**
	 * The public price range of a product, formatted.
	 *
	 * @param WC_Product $product Product.
	 * @return string Empty if the product has no usable range.
	 */
	private static function public_range_html( $product ) {

		if ( ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_variation_prices' ) ) {
			// Grouped products take their range from other products' prices,
			// each of which goes through these filters on its own. Rebuilding
			// that here would mean duplicating WooCommerce's grouped
			// formatting; saying nothing is the honest answer.
			return '';
		}

		Dahu_User_Pricing_Prices::suspend();

		try {
			$prices = $product->get_variation_prices( true );
		} finally {
			Dahu_User_Pricing_Prices::resume();
		}

		if ( empty( $prices['price'] ) ) {
			return '';
		}

		$min = (float) current( $prices['price'] );
		$max = (float) end( $prices['price'] );

		return self::differs( $min, $max )
			? wc_format_price_range( $min, $max )
			: wc_price( $min );
	}

	/**
	 * Prefix a price range with a label.
	 *
	 * @param string $price_html Price HTML.
	 * @param string $label      Label to put in front of it.
	 * @return string
	 */
	private static function label_only( $price_html, $label ) {

		if ( '' === (string) $label || '' === $price_html ) {
			return $price_html;
		}

		return sprintf(
			'<span class="dahu-user-pricing-price"><span class="dahu-user-pricing-price__label">%1$s</span>%2$s</span>',
			esc_html( $label ),
			$price_html
		);
	}

	/**
	 * Build the price HTML for a product with a tier price.
	 *
	 * @param WC_Product $product      Product.
	 * @param array      $labels       Tier labels.
	 * @param float      $tier_price   Tier price, in the shop's storage base.
	 * @param float      $public_price Public price, in the shop's storage base.
	 * @return string
	 */
	private static function render_price( $product, $labels, $tier_price, $public_price ) {

		$tier_display   = (float) wc_get_price_to_display( $product, array( 'price' => $tier_price ) );
		$public_display = (float) wc_get_price_to_display( $product, array( 'price' => $public_price ) );

		$suffix = $product->get_price_suffix( $tier_price, 1 );

		if ( Dahu_User_Pricing_Context::VIEW_PUBLIC === Dahu_User_Pricing_Context::view_mode() ) {

			return sprintf(
				'<span class="dahu-user-pricing-price dahu-user-pricing-price--public">%1$s<span class="dahu-user-pricing-price__amount">%2$s</span>%3$s</span>',
				self::label_markup( $labels['public'] ),
				wp_kses_post( wc_price( $public_display ) ),
				wp_kses_post( $product->get_price_suffix( $public_price, 1 ) )
			);
		}

		$parts = sprintf(
			'<span class="dahu-user-pricing-price__tier">%1$s<ins class="dahu-user-pricing-price__amount">%2$s</ins>%3$s</span>',
			self::label_markup( $labels['tier'] ),
			wp_kses_post( wc_price( $tier_display ) ),
			wp_kses_post( $suffix )
		);

		// The public figure is shown beside the tier one only when it differs.
		// Identical prices struck through next to themselves would read as a
		// discount that is not there.
		if ( self::differs( $tier_display, $public_display ) ) {

			$parts .= sprintf(
				' <span class="dahu-user-pricing-price__public">%1$s<del class="dahu-user-pricing-price__amount">%2$s</del></span>',
				self::label_markup( $labels['public'] ),
				wp_kses_post( wc_price( $public_display ) )
			);

			$parts .= self::margin_markup( $tier_display, $public_display );
		}

		return '<span class="dahu-user-pricing-price">' . $parts . '</span>';
	}

	/**
	 * A label, or nothing at all when it is empty.
	 *
	 * @param string $label Label text.
	 * @return string
	 */
	private static function label_markup( $label ) {

		if ( '' === (string) $label ) {
			return '';
		}

		return sprintf(
			'<span class="dahu-user-pricing-price__label">%s</span>',
			esc_html( $label )
		);
	}

	/**
	 * Whether two displayed amounts are different to the shop's precision.
	 *
	 * Compared after rounding to the shop's decimals, because two amounts that
	 * render identically are the same amount as far as a reader is concerned,
	 * however they differ in the fourteenth decimal.
	 *
	 * @param float $a First amount.
	 * @param float $b Second amount.
	 * @return bool
	 */
	private static function differs( $a, $b ) {

		$decimals = wc_get_price_decimals();

		return round( $a, $decimals ) !== round( $b, $decimals );
	}

	/**
	 * The margin between a tier price and the public price.
	 *
	 * Two formats, because resellers think in both: a percentage of the
	 * selling price, and the multiplier that gets from one to the other.
	 * Default is neither — see the settings defaults for why.
	 *
	 * @param float $tier_display   Tier amount as displayed.
	 * @param float $public_display Public amount as displayed.
	 * @return string
	 */
	private static function margin_markup( $tier_display, $public_display ) {

		$mode = Dahu_User_Pricing_Settings::get( 'margin_display' );

		if ( 'percent' === $mode ) {

			if ( $public_display <= 0 ) {
				return '';
			}

			$value = ( ( $public_display - $tier_display ) / $public_display ) * 100;

			$text = sprintf(
				/* translators: %s: margin as a percentage, already formatted. */
				esc_html__( 'Margin: %s%%', 'dahu-user-pricing' ),
				number_format_i18n( $value, 1 )
			);

		} elseif ( 'coefficient' === $mode ) {

			if ( $tier_display <= 0 ) {
				return '';
			}

			$value = $public_display / $tier_display;

			$text = sprintf(
				/* translators: %s: multiplier between the tier price and the public price. */
				esc_html__( 'Margin: ×%s', 'dahu-user-pricing' ),
				number_format_i18n( $value, 2 )
			);

		} else {
			return '';
		}

		return sprintf(
			' <span class="dahu-user-pricing-price__margin">%s</span>',
			$text
		);
	}
}
