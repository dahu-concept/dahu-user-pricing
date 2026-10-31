<?php
/**
 * Customer tiers.
 *
 * A tier is a named price level — "Trade", "Wholesale", "Gold" — with a key,
 * a label, and the two strings shown in front of a price. Everything else in
 * the plugin is expressed in terms of tiers: a product carries one price per
 * tier, a role maps to a tier, a preview previews a tier.
 *
 * WHY ARBITRARY TIERS AND NOT TWO FIXED ONES. The shop this plugin grew out
 * of had exactly two, "Or" and "Argent", and they were written into the code
 * as constants. That made every other shop's first question — "where do I add
 * the third one?" — unanswerable. Tiers are therefore data, created in the
 * settings screen, with no built-in list and no limit.
 *
 * WHY A KEY IS PERMANENT. Prices live in product meta named after the key
 * (`_dahu_user_pricing_price_<key>`). A key that changed would leave every
 * price filed under the old name unreachable, with nothing on screen to say
 * so. So the key is derived from the label once, at creation, and never again
 * — renaming the label is free, and the key just stops matching it. That is
 * the lesser evil: a slightly stale key is readable, lost prices are not.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tier model.
 */
class Dahu_User_Pricing_Tiers {

	/**
	 * Prefix of the per-tier product meta key.
	 *
	 * Public because the product fields, the price resolution and the
	 * uninstaller all have to agree on it, and three copies of a string is
	 * how they stop agreeing.
	 */
	const PRICE_META_PREFIX = '_dahu_user_pricing_price_';

	/**
	 * Longest a tier key may be.
	 *
	 * A product meta key is limited to 255 characters by the column it lives
	 * in. The prefix above is 25, and a variation adds nothing, so 60 leaves
	 * room to spare and keeps keys readable in a database client.
	 */
	const KEY_MAX_LENGTH = 60;

	/**
	 * All tiers, keyed by tier key.
	 *
	 * @return array<string, array{label: string, label_public: string, label_tier: string}>
	 */
	public static function all() {

		$tiers = Dahu_User_Pricing_Settings::get( 'tiers' );

		return is_array( $tiers ) ? $tiers : array();
	}

	/**
	 * Tier keys only.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * Whether a tier exists.
	 *
	 * @param string $key Tier key.
	 * @return bool
	 */
	public static function exists( $key ) {

		$tiers = self::all();

		return isset( $tiers[ (string) $key ] );
	}

	/**
	 * One tier, or null.
	 *
	 * @param string $key Tier key.
	 * @return array|null
	 */
	public static function get( $key ) {

		$tiers = self::all();
		$key   = (string) $key;

		return isset( $tiers[ $key ] ) ? $tiers[ $key ] : null;
	}

	/**
	 * A tier's display label, falling back to its key.
	 *
	 * The fallback matters: a role mapped to a tier that has since been
	 * deleted should read as "trade" rather than as an empty string, so that
	 * whoever is looking at the screen can see what is wrong.
	 *
	 * @param string $key Tier key.
	 * @return string
	 */
	public static function label( $key ) {

		$tier = self::get( $key );

		return ( $tier && '' !== $tier['label'] ) ? $tier['label'] : (string) $key;
	}

	/**
	 * The two price labels of a tier.
	 *
	 * Trimmed, because the sanitiser trims. The space between a label and an
	 * amount is set in the stylesheet rather than typed into a settings field,
	 * where it would be invisible and lost on the first save.
	 *
	 * @param string $key Tier key.
	 * @return array{public: string, tier: string}|null Null if the tier is unknown.
	 */
	public static function get_labels( $key ) {

		$tier = self::get( $key );

		if ( ! $tier ) {
			return null;
		}

		return array(
			'public' => (string) $tier['label_public'],
			'tier'   => (string) $tier['label_tier'],
		);
	}

	/**
	 * Meta key holding a product's price for a tier.
	 *
	 * @param string $key Tier key.
	 * @return string
	 */
	public static function price_meta_key( $key ) {
		return self::PRICE_META_PREFIX . (string) $key;
	}

	/**
	 * Turn a label — or an existing key — into a usable tier key.
	 *
	 * Given something that is already a key (the caller knows, because the
	 * tier exists), it is returned unchanged apart from sanitising, so that
	 * saving a settings form never renames a tier.
	 *
	 * Given a label, it is slugified, then made unique against $taken by
	 * appending -2, -3 and so on. sanitize_key() is deliberate rather than
	 * sanitize_title(): a key ends up inside a meta key and a form field name,
	 * where a percent-encoded accent would be a nuisance. It also strips
	 * spaces rather than converting them, hence the substitution first.
	 *
	 * @param string   $source Existing key, or a label to derive one from.
	 * @param string[] $taken  Keys already in use.
	 * @return string Empty string if nothing usable could be derived.
	 */
	public static function make_key( $source, array $taken = array() ) {

		// Whitespace becomes a hyphen BEFORE sanitize_key(), which would
		// otherwise delete it: "Gold Partner" has to come out as
		// "gold-partner", not "goldpartner".
		$key = remove_accents( trim( (string) $source ) );
		$key = (string) preg_replace( '/\s+/', '-', $key );
		$key = sanitize_key( $key );
		$key = substr( $key, 0, self::KEY_MAX_LENGTH );

		if ( '' === $key ) {
			return '';
		}

		if ( ! in_array( $key, $taken, true ) ) {
			return $key;
		}

		// Reserve room for the suffix rather than truncating it off.
		$base = substr( $key, 0, self::KEY_MAX_LENGTH - 4 );

		for ( $n = 2; $n <= 99; $n++ ) {

			$candidate = $base . '-' . $n;

			if ( ! in_array( $candidate, $taken, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Remove a tier from the settings.
	 *
	 * WHAT THIS DELIBERATELY DOES NOT DO: delete the prices. Every
	 * `_dahu_user_pricing_price_<key>` meta stays exactly where it is. A tier
	 * is usually deleted because somebody is reorganising, and recreating it
	 * under the same key brings every price back; deleting a thousand prices
	 * on a settings save would be irreversible and could not be undone from
	 * the screen that caused it.
	 *
	 * Roles mapped to the tier lose their mapping, because a mapping to a
	 * tier that does not exist would resolve to no tier anyway, and leaving
	 * it would make the settings screen show a row it cannot render.
	 *
	 * @param string $key Tier key.
	 * @return bool True if a tier was removed.
	 */
	public static function delete( $key ) {

		$key      = (string) $key;
		$settings = Dahu_User_Pricing_Settings::all();

		if ( ! isset( $settings['tiers'][ $key ] ) ) {
			return false;
		}

		unset( $settings['tiers'][ $key ] );

		foreach ( $settings['role_tier_map'] as $role => $mapped ) {

			if ( $mapped === $key ) {
				unset( $settings['role_tier_map'][ $role ] );
			}
		}

		Dahu_User_Pricing_Settings::update( $settings );

		return true;
	}

	/**
	 * How many products carry a price for a tier.
	 *
	 * Shown next to the delete button, because "delete Trade" and "delete
	 * Trade, which 418 products have a price for" are different decisions.
	 *
	 * @param string $key Tier key.
	 * @return int
	 */
	public static function price_count( $key ) {

		global $wpdb;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not data.
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
			self::price_meta_key( $key )
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}
}
