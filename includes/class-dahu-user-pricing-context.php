<?php
/**
 * Who the current visitor is, as far as pricing is concerned.
 *
 * One place answers "which tier applies?", and every other module asks here
 * rather than looking at roles itself. That is the whole point: the tier of a
 * request is decided once, so the price shown, the price charged, the label in
 * front of it and the class on the body can never disagree about it.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tier resolution for the current request.
 */
class Dahu_User_Pricing_Context {

	/**
	 * Cookie holding the view preference of the Pro/public switch.
	 */
	const VIEW_COOKIE = 'dahu_user_pricing_view';

	const VIEW_TIER   = 'tier';
	const VIEW_PUBLIC = 'public';

	/**
	 * Memoised tier for the current request, so that a page pricing two
	 * hundred products does not walk the role map two hundred times.
	 *
	 * Three states, and the third is why this is not simply null-checked:
	 * unset (not yet resolved), null (resolved to no tier), a string (a tier).
	 *
	 * @var array{set: bool, tier: string|null}
	 */
	private static $current = array(
		'set'  => false,
		'tier' => null,
	);

	/**
	 * Hook the one thing that invalidates the memoised tier.
	 *
	 * WITHOUT THIS, the memo is a bug rather than an optimisation. Anything
	 * that changes the current user mid-request — a user-switching plugin,
	 * code looping over customers to build price lists or quotes, an
	 * impersonation feature — would keep pricing for whoever was current the
	 * first time a price was asked for. WordPress fires `set_current_user`
	 * every time, so that is where the memo is dropped.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'set_current_user', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Forget the memoised tier.
	 *
	 * Called when the thing it depends on changes: the current user, a preview
	 * starting or stopping, or settings being saved.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$current = array(
			'set'  => false,
			'tier' => null,
		);
	}

	/**
	 * Tier of the current user, or null.
	 *
	 * An active preview wins over the real roles: somebody previewing "Trade"
	 * is treated here exactly as a real Trade account would be — same price,
	 * same label, same margin — while staying themselves everywhere else.
	 * is_manager() is deliberately NOT affected by a preview, so previewing
	 * cannot cost you the toolbar you need to stop previewing.
	 *
	 * @return string|null
	 */
	public static function get_tier() {

		if ( self::$current['set'] ) {
			return self::$current['tier'];
		}

		$tier = self::resolve_tier();

		self::$current = array(
			'set'  => true,
			'tier' => $tier,
		);

		return $tier;
	}

	/**
	 * The actual resolution, without memoisation.
	 *
	 * @return string|null
	 */
	private static function resolve_tier() {

		$preview = Dahu_User_Pricing_Preview::get_preview_tier();

		if ( null !== $preview ) {
			return $preview;
		}

		if ( ! is_user_logged_in() ) {
			return null;
		}

		return self::tier_from_roles( (array) wp_get_current_user()->roles );
	}

	/**
	 * Tier of a given user, ignoring any preview.
	 *
	 * This is the one to use when pricing something for somebody else — an
	 * order's customer, say. A preview is a property of whoever is looking at
	 * the screen, and must never leak into what another account is charged.
	 *
	 * @param int $user_id User id.
	 * @return string|null
	 */
	public static function get_tier_for_user( $user_id ) {

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return null;
		}

		return self::tier_from_roles( (array) $user->roles );
	}

	/**
	 * First tier any of these roles maps to.
	 *
	 * The map is walked in the order the ROLES come in, not the order the map
	 * was saved in, so that an account holding two mapped roles resolves the
	 * same way whatever the settings screen last wrote. Which of the two wins
	 * is still arbitrary — there is no non-arbitrary answer — so the settings
	 * screen says so rather than pretending to a rule.
	 *
	 * @param array $roles Role slugs.
	 * @return string|null
	 */
	public static function tier_from_roles( array $roles ) {

		$map = Dahu_User_Pricing_Settings::get( 'role_tier_map' );

		if ( ! is_array( $map ) || ! $map ) {
			return null;
		}

		foreach ( $roles as $role ) {

			if ( isset( $map[ $role ] ) && Dahu_User_Pricing_Tiers::exists( $map[ $role ] ) ) {
				return $map[ $role ];
			}
		}

		return null;
	}

	/**
	 * Whether the current user has a tier at all.
	 *
	 * @return bool
	 */
	public static function has_tier() {
		return null !== self::get_tier();
	}

	/**
	 * Whether the current user holds one of the roles listed as managers.
	 *
	 * Independent of has_tier(), and not exclusive with it: an account can be
	 * both a manager and hold a tier — test accounts usually are — and both
	 * calls then return true.
	 *
	 * TWO conditions, and the capability is the one that is not configurable.
	 * The setting says which of the shop's roles get the preview; it cannot
	 * hand it to a role that has no business running the shop. Without that
	 * floor, adding `customer` to the list — an easy thing to do while trying
	 * to work out what the setting does — would let every customer look at
	 * every tier's prices.
	 *
	 * @return bool
	 */
	public static function is_manager() {

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$manager_roles = Dahu_User_Pricing_Settings::get( 'manager_roles' );

		if ( ! is_array( $manager_roles ) || ! $manager_roles ) {
			return false;
		}

		return (bool) array_intersect( (array) wp_get_current_user()->roles, $manager_roles );
	}

	/**
	 * Whether tier prices should be shown to the current user.
	 *
	 * @return bool
	 */
	public static function can_see_tier_pricing() {
		return self::has_tier() || self::is_manager();
	}

	/**
	 * Price labels applying to the current user.
	 *
	 * A manager with no tier of their own borrows the first configured tier's
	 * labels, so the screen they are checking reads like the screen a customer
	 * gets. With no tiers configured at all there is nothing to borrow and
	 * both labels are empty, which renders as no prefix — the correct output
	 * for a shop that has not set this up.
	 *
	 * @return array{public: string, tier: string}
	 */
	public static function get_price_labels() {

		$tier = self::get_tier();

		if ( null === $tier ) {

			$keys = Dahu_User_Pricing_Tiers::keys();
			$tier = $keys ? $keys[0] : null;
		}

		$labels = null === $tier ? null : Dahu_User_Pricing_Tiers::get_labels( $tier );

		return $labels ? $labels : array(
			'public' => '',
			'tier'   => '',
		);
	}

	/**
	 * Which of the two prices the current user is LOOKING at.
	 *
	 * Display only. Switching to the public view shows a reseller what their
	 * own customer pays; it never changes what the cart charges, and the two
	 * modules that could confuse the two — Display and Order — are the reason
	 * this is stated here rather than inferred at each call site.
	 *
	 * @return string VIEW_TIER or VIEW_PUBLIC.
	 */
	public static function view_mode() {

		if ( ! Dahu_User_Pricing_Settings::get( 'view_switch' ) ) {
			return self::VIEW_TIER;
		}

		if ( ! self::can_see_tier_pricing() ) {
			return self::VIEW_TIER;
		}

		if ( isset( $_COOKIE[ self::VIEW_COOKIE ] ) ) {

			$value = sanitize_key( wp_unslash( $_COOKIE[ self::VIEW_COOKIE ] ) );

			if ( self::VIEW_PUBLIC === $value ) {
				return self::VIEW_PUBLIC;
			}
		}

		// Somebody with a tier is shown their own price first; a manager with
		// no tier is shown the public price first, because that is the one
		// their shop's visitors see.
		return self::has_tier() ? self::VIEW_TIER : self::VIEW_PUBLIC;
	}

	/**
	 * Body classes describing the pricing context.
	 *
	 * Emitted for the theme to hang styles on. They are NOT styled by this
	 * plugin: giving them an appearance here would impose it on every shop,
	 * which is the opposite of why they are in the markup.
	 *
	 * @return string[]
	 */
	public static function body_classes() {

		$classes = array();

		$tier = self::get_tier();

		if ( null !== $tier ) {
			$classes[] = 'dahu-user-pricing-tier';
			$classes[] = 'dahu-user-pricing-tier-' . sanitize_html_class( $tier );
		} else {
			$classes[] = 'dahu-user-pricing-public';
		}

		if ( self::is_manager() ) {
			$classes[] = 'dahu-user-pricing-manager';
		}

		$classes[] = 'dahu-user-pricing-view-' . sanitize_html_class( self::view_mode() );

		return $classes;
	}
}
