<?php
/**
 * The plugin's single option: defaults, reading, sanitising.
 *
 * No user interface here — the settings screen lives in
 * class-dahu-user-pricing-admin-screens.php. This file is what every other
 * module reads, and keeping it free of markup is what lets the screen change
 * without the price resolution noticing.
 *
 * WHY ONE OPTION AND NOT SEVERAL. Every field here is read on nearly every
 * request that prices a product. One option is one row of autoloaded data; a
 * dozen options would be a dozen lookups on a cold cache for no gain.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option access and validation.
 */
class Dahu_User_Pricing_Settings {

	const OPTION_KEY = 'dahu_user_pricing_settings';

	/**
	 * Register the setting so options.php accepts the screen's form.
	 *
	 * @return void
	 */
	public static function init() {
		add_action(
			'admin_init',
			static function () {
				register_setting(
					self::OPTION_KEY,
					self::OPTION_KEY,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
	}

	/**
	 * Shipped defaults.
	 *
	 * A fresh install has NO tiers and NO role mapping, so it changes nothing
	 * about the shop until somebody configures it. Inventing a tier, or
	 * guessing that a role named something like "wholesale" should get trade
	 * prices, would quietly change displayed prices on activation — the one
	 * thing a pricing plugin must never do.
	 *
	 * `margin_display` defaults to 'none' for the same reason: a margin is the
	 * gap between what a reseller pays and what they charge, and that is
	 * commercial information. It gets displayed because somebody asked for it,
	 * not because a plugin was installed.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'tiers'          => array(),
			'role_tier_map'  => array(),
			'manager_roles'  => array( 'administrator' ),
			'margin_display' => 'none',
			'view_switch'    => false,

			// Off, so that uninstalling never destroys a shop's typed-in
			// prices. See uninstall.php for the whole argument.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * The option, merged over the defaults.
	 *
	 * wp_parse_args() is shallow by design here: a saved 'tiers' array
	 * replaces the default empty one whole, which is what we want. Nothing in
	 * this option has a nested default worth merging into.
	 *
	 * @return array
	 */
	public static function all() {

		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {

		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Write the option back, sanitised.
	 *
	 * @param array $settings Full settings array.
	 * @return void
	 */
	public static function update( array $settings ) {
		update_option( self::OPTION_KEY, self::sanitize( $settings ) );
	}

	/**
	 * Clean one submitted tier row.
	 *
	 * @param array  $tier  Submitted row.
	 * @param string $label Label already sanitised and defaulted.
	 * @return array
	 */
	private static function sanitize_tier( array $tier, $label ) {

		return array(
			'label'        => $label,
			'label_public' => isset( $tier['label_public'] ) ? sanitize_text_field( (string) $tier['label_public'] ) : '',
			'label_tier'   => isset( $tier['label_tier'] ) ? sanitize_text_field( (string) $tier['label_tier'] ) : '',
		);
	}

	/**
	 * Validate submitted settings.
	 *
	 * Everything is rebuilt from scratch into a fresh array: a key this method
	 * does not know about does not survive, so a stale field left behind by an
	 * older version of the screen cannot keep influencing anything.
	 *
	 * THE ONE RULE THAT MATTERS HERE: a tier key, once created, is never
	 * changed or removed by this method. Product prices are stored under meta
	 * keys derived from it, so renaming a key would orphan every price filed
	 * under the old one, silently. The screen therefore shows a key as
	 * read-only text once it exists, and deleting a tier is a separate,
	 * explicit action — see Dahu_User_Pricing_Tiers::delete().
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	/*
	 * NO wp_unslash() ANYWHERE BELOW, AND THAT IS THE FIX.
	 *
	 * `wp-admin/options.php` unslashes what it hands to a sanitize_callback,
	 * so what arrives here already holds real backslashes. Unslashing again
	 * ate one the shop owner had typed — and this sanitiser runs more than
	 * once per save: WordPress sanitises twice the first time an option is
	 * written (`update_option()` then `add_option()`), and `update()` below
	 * sanitises explicitly before calling `update_option()` on a REGISTERED
	 * setting, which sanitises once more. Deleting a tier goes through
	 * `update()`, so every deletion used to eat another layer off every
	 * label, quietly, and re-typing the text appeared to fix it.
	 *
	 * Everything else here — sanitize_text_field(), sanitize_key() — is
	 * idempotent, so leaving the slashes alone makes the whole sanitiser
	 * idempotent.
	 */
	public static function sanitize( $input ) {

		$output = self::defaults();

		if ( ! is_array( $input ) ) {
			return $output;
		}

		/* --- Tiers. ------------------------------------------------------ */

		$existing  = self::all();
		$submitted = ( isset( $input['tiers'] ) && is_array( $input['tiers'] ) ) ? $input['tiers'] : array();
		$tiers     = array();

		/*
		 * TWO PASSES, and the order is the whole point. An existing tier's key
		 * is passed through untouched; only a NEW row has a key derived from
		 * its label, and it is made unique against the existing keys rather
		 * than the other way round.
		 *
		 * One pass could not promise that. make_key() uniquifies against the
		 * keys collected so far, so a submitted array whose new row came
		 * BEFORE an existing one — a scripted post, or a future change to the
		 * row order on the screen — had the new row claim the existing key and
		 * the existing tier renamed to `<key>-2`, silently inheriting zero
		 * prices while the new row inherited all of them. The invariant this
		 * file states has to hold whatever order the fields arrive in, not
		 * only in the order the screen happens to print them.
		 */
		foreach ( $submitted as $submitted_key => $tier ) {

			if ( ! is_array( $tier ) || ! isset( $existing['tiers'][ $submitted_key ] ) ) {
				continue;
			}

			$key = sanitize_key( (string) $submitted_key );

			if ( '' === $key ) {
				continue;
			}

			$label = isset( $tier['label'] ) ? sanitize_text_field( (string) $tier['label'] ) : '';

			// An EXISTING tier whose label was cleared keeps existing, with
			// its key standing in as a name. Dropping it here would make an
			// emptied text field delete a tier and unmap its roles — a
			// destructive act triggered by a blank field, which is exactly
			// what the explicit delete button is there to be instead.
			$tiers[ $key ] = self::sanitize_tier( $tier, '' === $label ? $key : $label );
		}

		foreach ( $submitted as $submitted_key => $tier ) {

			if ( ! is_array( $tier ) || isset( $existing['tiers'][ $submitted_key ] ) ) {
				continue;
			}

			$label = isset( $tier['label'] ) ? sanitize_text_field( (string) $tier['label'] ) : '';

			if ( '' === $label ) {
				// The empty "add a tier" row, left as it was printed. Not an
				// error, just nothing.
				continue;
			}

			$key = Dahu_User_Pricing_Tiers::make_key( $label, array_keys( $tiers ) );

			if ( '' === $key ) {
				continue;
			}

			$tiers[ $key ] = self::sanitize_tier( $tier, $label );
		}

		$output['tiers'] = $tiers;

		/* --- Roles. ------------------------------------------------------ */

		$valid_roles = array_keys( wp_roles()->get_names() );

		if ( isset( $input['role_tier_map'] ) && is_array( $input['role_tier_map'] ) ) {

			foreach ( $input['role_tier_map'] as $role => $tier_key ) {

				$role     = (string) $role;
				$tier_key = (string) $tier_key;

				if ( ! in_array( $role, $valid_roles, true ) ) {
					continue;
				}

				if ( ! isset( $tiers[ $tier_key ] ) ) {
					// Covers both "no tier" (empty select) and a tier deleted
					// in the same save.
					continue;
				}

				$output['role_tier_map'][ $role ] = $tier_key;
			}
		}

		$output['manager_roles'] = array();

		if ( isset( $input['manager_roles'] ) && is_array( $input['manager_roles'] ) ) {

			foreach ( $input['manager_roles'] as $role ) {

				if ( in_array( (string) $role, $valid_roles, true ) ) {
					$output['manager_roles'][] = (string) $role;
				}
			}
		}

		/* --- Display. ---------------------------------------------------- */

		$margin_modes = array( 'none', 'percent', 'coefficient' );

		$output['margin_display'] = ( isset( $input['margin_display'] ) && in_array( $input['margin_display'], $margin_modes, true ) )
			? $input['margin_display']
			: 'none';

		$output['view_switch'] = ! empty( $input['view_switch'] );

		$output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		return $output;
	}
}
