<?php
/**
 * Looking at the shop as one of the tiers, from the toolbar.
 *
 * WHY IT EXISTS. The only way to be sure a tier is configured correctly is to
 * see the shop the way that tier's customers see it, and the alternative —
 * keeping a second account and a second browser — is slow enough that nobody
 * does it, which is how a mispriced tier stays mispriced for a month.
 *
 * WHAT CHANGED IN THE PORT, AND WHY IT MATTERS. The version this came from had
 * to work around a commercial plugin that read `$current_user->roles` directly,
 * so it ADDED the previewed role to that array for the duration of the request.
 * That worked, and it meant a preview handed the previewing account a role's
 * capabilities as a side effect. Here the plugin owns its own price
 * resolution, so a preview overrides one thing — the tier — and touches
 * neither roles nor capabilities. Nothing about who you are changes.
 *
 * A PREVIEW IS FAITHFUL, WHICH MAKES IT DANGEROUS. Prices while previewing are
 * really the previewed tier's prices, in the cart as well as the catalogue —
 * that is the point, a preview that stopped at the catalogue would not tell you
 * what you need to know. So an order cannot be placed while one is active: a
 * banner says so, the cart says so, and checkout refuses with a message naming
 * the way out. A warning alone is what the earlier version had, and a warning
 * does not stop a tired person at nine in the evening.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tier preview for managers.
 */
class Dahu_User_Pricing_Preview {

	const COOKIE_NAME  = 'dahu_user_pricing_preview';
	const QUERY_VAR    = 'dahu_user_pricing_preview_tier';
	const NONCE_ACTION = 'dahu_user_pricing_preview';

	/**
	 * Hook the preview.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'init', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_toolbar_items' ), 100 );
		add_action( 'wp_body_open', array( __CLASS__, 'render_banner' ) );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_banner' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_banner' ) );
		// Two checkouts, two hooks, and one of them is now the default.
		// woocommerce_after_checkout_validation is fired in exactly one place,
		// WC_Checkout::process_checkout(), which is the classic shortcode
		// checkout. The Checkout block goes through the Store API instead and
		// never reaches it — so a preview would have been stopped on the old
		// checkout and waved through on the new one.
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'block_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'block_store_api' ) );
	}

	/**
	 * The tier currently being previewed, or null.
	 *
	 * Revalidated on every call rather than remembered: eligibility can be
	 * revoked, and a tier can be deleted, between two requests carrying the
	 * same cookie. Both cases end the preview silently, which is the right
	 * outcome — the alternative is a stale cookie quietly repricing a shop.
	 *
	 * Front end only. In wp-admin the figures on screen are the shop's own,
	 * and a preview that reached them would make the product editor lie.
	 *
	 * @return string|null
	 */
	public static function get_preview_tier() {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return null;
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		if ( ! Dahu_User_Pricing_Context::is_manager() ) {
			return null;
		}

		$requested = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		return Dahu_User_Pricing_Tiers::exists( $requested ) ? $requested : null;
	}

	/**
	 * Whether a preview is running.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return null !== self::get_preview_tier();
	}

	/**
	 * Start or stop a preview from a toolbar link.
	 *
	 * @return void
	 */
	public static function handle_request() {

		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! Dahu_User_Pricing_Context::is_manager() ) {
			return;
		}

		$requested = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$path      = COOKIEPATH ? COOKIEPATH : '/';

		if ( '' === $requested || '0' === $requested || ! Dahu_User_Pricing_Tiers::exists( $requested ) ) {

			setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
			unset( $_COOKIE[ self::COOKIE_NAME ] );

		} else {

			// A session cookie: a preview still running tomorrow morning is
			// the failure mode this guards against.
			setcookie( self::COOKIE_NAME, $requested, 0, $path, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE_NAME ] = $requested;
		}

		Dahu_User_Pricing_Context::flush();

		wp_safe_redirect( remove_query_arg( array( self::QUERY_VAR, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * The toolbar menu.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The toolbar.
	 * @return void
	 */
	public static function add_toolbar_items( $wp_admin_bar ) {

		if ( ! Dahu_User_Pricing_Context::is_manager() ) {
			return;
		}

		$tiers = Dahu_User_Pricing_Tiers::all();

		if ( ! $tiers ) {
			return;
		}

		$current = self::get_preview_tier();

		$wp_admin_bar->add_node(
			array(
				'id'    => 'dahu-user-pricing-preview',
				'title' => null === $current
					? esc_html__( 'Preview prices', 'dahu-user-pricing' )
					: sprintf(
						/* translators: %s: name of the tier being previewed. */
						esc_html__( 'Previewing: %s', 'dahu-user-pricing' ),
						Dahu_User_Pricing_Tiers::label( $current )
					),
			)
		);

		foreach ( $tiers as $key => $tier ) {

			$wp_admin_bar->add_node(
				array(
					'id'     => 'dahu-user-pricing-preview-' . sanitize_key( $key ),
					'parent' => 'dahu-user-pricing-preview',
					'title'  => ( $key === $current ? '✓ ' : '' ) . esc_html( $tier['label'] ),
					'href'   => esc_url( self::switch_url( $key ) ),
				)
			);
		}

		if ( null !== $current ) {

			$wp_admin_bar->add_node(
				array(
					'id'     => 'dahu-user-pricing-preview-stop',
					'parent' => 'dahu-user-pricing-preview',
					'title'  => esc_html__( 'Stop previewing', 'dahu-user-pricing' ),
					'href'   => esc_url( self::switch_url( '0' ) ),
				)
			);
		}
	}

	/**
	 * URL that starts or stops a preview.
	 *
	 * Built against the front page rather than the current URL when the
	 * toolbar is being rendered in wp-admin: a preview link pointing at an
	 * admin screen would set the cookie and land somewhere the preview does
	 * not apply, which reads as the feature being broken.
	 *
	 * @param string $tier Tier key, or '0' to stop.
	 * @return string
	 */
	private static function switch_url( $tier ) {

		$base = is_admin() ? home_url( '/' ) : '';

		return wp_nonce_url(
			add_query_arg( self::QUERY_VAR, $tier, $base ),
			self::NONCE_ACTION
		);
	}

	/**
	 * The banner saying a preview is running.
	 *
	 * Rendered at the top of the page, in the cart and at checkout. Attached
	 * to three hooks rather than one because a theme may not fire
	 * wp_body_open, and the two places where the warning matters most are the
	 * two where it must not be missed.
	 *
	 * @return void
	 */
	public static function render_banner() {

		static $rendered_on = array();

		$tier = self::get_preview_tier();

		if ( null === $tier ) {
			return;
		}

		// One banner per hook at most, so the cart does not get two.
		$hook = (string) current_action();

		if ( isset( $rendered_on[ $hook ] ) ) {
			return;
		}

		$rendered_on[ $hook ] = true;

		printf(
			'<div class="dahu-user-pricing-preview-banner" role="status"><span>%1$s</span> <a href="%2$s" rel="nofollow">%3$s</a></div>',
			esc_html(
				sprintf(
					/* translators: %s: name of the tier being previewed. */
					__( 'Previewing prices as %s. Prices shown are really this tier\'s prices, and an order cannot be placed until you stop.', 'dahu-user-pricing' ),
					Dahu_User_Pricing_Tiers::label( $tier )
				)
			),
			esc_url( self::switch_url( '0' ) ),
			esc_html__( 'Stop previewing', 'dahu-user-pricing' )
		);
	}

	/**
	 * Refuse checkout while a preview is running.
	 *
	 * @param array    $data   Posted checkout data, unused.
	 * @param WP_Error $errors Error collection to add to.
	 * @return void
	 */
	public static function block_checkout( $data, $errors ) {

		unset( $data );

		if ( ! self::is_active() ) {
			return;
		}

		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		$errors->add( 'dahu_user_pricing_preview_active', self::block_message() );
	}

	/**
	 * Refuse the block checkout, and the block cart, while a preview is
	 * running.
	 *
	 * The Store API collects cart errors here and refuses to proceed when any
	 * are present, which is how an extension stops a block checkout.
	 *
	 * @param WP_Error $errors Error collection to add to.
	 * @return void
	 */
	public static function block_store_api( $errors ) {

		if ( ! self::is_active() ) {
			return;
		}

		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		$errors->add( 'dahu_user_pricing_preview_active', self::block_message() );
	}

	/**
	 * Why the order is refused, and how to get past it.
	 *
	 * @return string
	 */
	private static function block_message() {

		return esc_html__( 'A price preview is active, so this order would be placed at the previewed tier\'s prices. Stop the preview from the toolbar, then order again.', 'dahu-user-pricing' );
	}
}
