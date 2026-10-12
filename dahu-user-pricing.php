<?php
/**
 * Plugin Name:       Dahu User Pricing
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Per-account pricing for WooCommerce: named customer tiers, a price per tier on every product and variation, resale-margin display, a view switch between tier and public price, and admin-created order lines priced correctly net of tax.
 * Version:           1.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-user-pricing
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_USER_PRICING_VERSION', '1.0.0' );
define( 'DAHU_USER_PRICING_FILE', __FILE__ );
define( 'DAHU_USER_PRICING_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_USER_PRICING_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_USER_PRICING_SETTINGS_PAGE', 'dahu-user-pricing' );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-user-pricing',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * HPOS / WooCommerce feature compatibility
 *
 * Every ORDER access in this plugin goes through wc_get_order() and the
 * WC_Order / WC_Order_Item accessors — no order meta read by hand, no query
 * against an order table — which is what makes the declaration below true
 * rather than hopeful.
 *
 * Product prices are a different matter and are read as product meta, with
 * one counting query against wp_postmeta in the settings screen. Products are
 * posts under HPOS exactly as they were before it, so that is unaffected.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'dahu_user_pricing_bootstrap' );

/**
 * Load the plugin once WooCommerce is confirmed present.
 *
 * @return void
 */
function dahu_user_pricing_bootstrap() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'dahu_user_pricing_missing_wc_notice' );
		return;
	}

	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-settings.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-tiers.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-context.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-prices.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-product-fields.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-display.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-preview.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-order.php';
	require_once DAHU_USER_PRICING_PATH . 'includes/class-dahu-user-pricing-admin-screens.php';

	Dahu_User_Pricing_Settings::init();
	Dahu_User_Pricing_Context::init();
	Dahu_User_Pricing_Preview::init();
	Dahu_User_Pricing_Prices::init();
	Dahu_User_Pricing_Product_Fields::init();
	Dahu_User_Pricing_Display::init();
	Dahu_User_Pricing_Order::init();
	Dahu_User_Pricing_Admin_Screens::init();
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function dahu_user_pricing_missing_wc_notice() {

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'Dahu User Pricing requires WooCommerce to be installed and active.',
			'dahu-user-pricing'
		)
	);
}

/* -------------------------------------------------------------------------
 * Public API
 *
 * One entry point per thing another plugin might legitimately want. Reading
 * the option directly from elsewhere is what these exist to avoid: the option
 * shape is internal and has already changed once.
 * ---------------------------------------------------------------------- */

/**
 * Price labels for a tier, as typed in the settings screen.
 *
 * Returned as entered, except that leading and trailing whitespace is gone:
 * the sanitiser trims, and relying on a shop owner to type a trailing space
 * would make the gap between a label and an amount depend on something
 * invisible. The gap is the stylesheet's job — see assets/css/front.css.
 *
 * @param string|null $tier Tier key, or null for the current user's tier.
 * @return array{public: string, tier: string}|null Null if the tier is unknown.
 */
function dahu_user_pricing_get_price_labels( $tier = null ) {

	if ( null === $tier ) {
		return Dahu_User_Pricing_Context::get_price_labels();
	}

	return Dahu_User_Pricing_Tiers::get_labels( (string) $tier );
}

/**
 * Tier key that applies to a user, or null if none does.
 *
 * @param int|null $user_id User id, or null for the current user.
 * @return string|null
 */
function dahu_user_pricing_get_tier( $user_id = null ) {

	if ( null === $user_id ) {
		return Dahu_User_Pricing_Context::get_tier();
	}

	return Dahu_User_Pricing_Context::get_tier_for_user( (int) $user_id );
}

/**
 * Unit price net of tax for a product, as a given customer would be charged
 * it, and the tier that produced it.
 *
 * Exposed because an order built by code — a repair quote, a subscription
 * renewal, an import — writes amounts onto the line itself, and no price
 * filter can reach a value written that way. Such code needs to ask for the
 * price rather than hope a filter applies.
 *
 * Tax is deliberately not part of the answer: a caller holding a line should
 * get it from `WC_Order_Item::calculate_taxes()` or
 * `WC_Order::calculate_totals()`, which know the order's tax location. This
 * plugin does not implement tax.
 *
 * @param WC_Product   $product  Product to price.
 * @param WP_User|null $user     Customer, or null for the public price.
 * @param int          $quantity Quantity.
 * @return array{price_excl_tax: float|null, tier: string|null}
 */
function dahu_user_pricing_resolve_line_pricing( $product, $user, $quantity = 1 ) {

	return Dahu_User_Pricing_Order::resolve_line_pricing( $product, $user, $quantity );
}

/* -------------------------------------------------------------------------
 * Plugins screen — action links and meta row
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . DAHU_USER_PRICING_SETTINGS_PAGE ) ),
			esc_html__( 'Settings', 'dahu-user-pricing' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		return array(
			sprintf(
				/* translators: %s: plugin version number. */
				esc_html__( 'Version %s', 'dahu-user-pricing' ),
				esc_html( $header['Version'] )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-user-pricing' )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'Plugin website', 'dahu-user-pricing' )
			),
		);
	},
	10,
	2
);
