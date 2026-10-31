<?php
/**
 * Plugin Name:       Dahu User Pricing
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Per-account pricing for WooCommerce: named customer tiers, a price per tier on every product and variation, resale-margin display, a view switch between tier and public price, and admin-created order lines priced correctly net of tax.
 * Version:           1.1.0
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

define( 'DAHU_USER_PRICING_VERSION', '1.1.0' );
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

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_USER_PRICING_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-user-pricing' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-user-pricing',
				'name'       => __( 'User pricing', 'dahu-user-pricing' ),
				'tagline'    => __( 'Prices that follow the customer rather than the catalogue.', 'dahu-user-pricing' ),
				'file'       => DAHU_USER_PRICING_FILE,
				'version'    => DAHU_USER_PRICING_VERSION,
				'textdomain' => 'dahu-user-pricing',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M58 8 L104 8 Q110 8 110 14 L110 58 L56 104 Q52 108 48 104 L8 64 Q4 60 8 56 Z',
						'M30 74 a13 13 0 1 1 26 0',
						'M81.0 28.0 a9.0 9.0 0 1 0 18.0 0 a9.0 9.0 0 1 0 -18.0 0',
						'M35.0 56.0 a8.0 8.0 0 1 0 16.0 0 a8.0 8.0 0 1 0 -16.0 0',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahu-user-pricing' ),
					'done'   => __( 'Done', 'dahu-user-pricing' ),
					'skip'   => __( 'Skip', 'dahu-user-pricing' ),
					'of'     => __( 'of', 'dahu-user-pricing' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahu-user-pricing' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Per customer', 'dahu-user-pricing' ),
					'text'  => __( 'A price for one account, or for a whole role.', 'dahu-user-pricing' ),
				),
				array(
					'title' => __( 'Shown everywhere', 'dahu-user-pricing' ),
					'text'  => __( 'Catalogue, product page, cart and order agree.', 'dahu-user-pricing' ),
				),
				array(
					'title' => __( 'No surprises', 'dahu-user-pricing' ),
					'text'  => __( 'The customer sees the price they will pay.', 'dahu-user-pricing' ),
				),
				),

				/*
				 * The one-time tour. Every selector points at something the
				 * settings screen always renders, including on a fresh install
				 * with no tier yet. The role map is left out on purpose: it is
				 * only rendered once a tier exists.
				 */
				'tour_screen' => 'woocommerce_page_dahu-user-pricing',
				'tour'        => array(
					array(
						'selector' => 'input[name="dahu_user_pricing_settings[tiers][dahu.new][label]"]',
						'title'    => __( 'Name a tier', 'dahu-user-pricing' ),
						'text'     => __( 'Type a name here and save to create your first price tier.', 'dahu-user-pricing' ),
					),
					array(
						'selector' => '.dahu-user-pricing-settings__roles',
						'title'    => __( 'Who can preview', 'dahu-user-pricing' ),
						'text'     => __( 'Tick the roles that may browse the shop as any tier.', 'dahu-user-pricing' ),
					),
					array(
						'selector' => '.dahu-user-pricing-settings__margin',
						'title'    => __( 'Show the margin', 'dahu-user-pricing' ),
						'text'     => __( 'Optionally show resellers their margin over the public price.', 'dahu-user-pricing' ),
					),
				),
			)
		);
	}
);
