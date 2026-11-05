<?php
/**
 * Uninstaller.
 *
 * WHAT GOES WITHOUT ASKING: the plugin's own option. It is configuration, it
 * is small, and nothing outside this plugin can read it.
 *
 * WHAT DOES NOT: the per-tier prices on products. They are typed in by hand,
 * one figure at a time, and a shop with four tiers and a thousand products has
 * four thousand of them. Deleting those because somebody deactivated a plugin
 * to test something would be irreversible and would look, from the outside,
 * exactly like data loss — because it is. They are therefore kept unless the
 * shop asked for them to go, in the setting on the pricing screen, before
 * uninstalling.
 *
 * The prices are also harmless when left: they are meta on a product under a
 * name nothing else uses, and reinstalling the plugin finds them again.
 *
 * @package UbacPricing
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this plugin's data from the current site.
 *
 * @return void
 */
function ubac_pricing_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-pricing' );
	delete_option( 'ubac_welcome_pending_ubac-pricing' );
	delete_option( 'ubac_welcome_tour_ubac-pricing' );

	global $wpdb;

	$settings = get_option( 'ubac_pricing_settings', array() );
	$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	delete_option( 'ubac_pricing_settings' );

	if ( ! $purge ) {
		return;
	}

	// The prefix is the one in Ubac_Pricing_Tiers::PRICE_META_PREFIX, and
	// it is written out here because the uninstaller runs with the plugin's
	// classes unloaded. If one of the two ever changes, the other has to.
	$like = $wpdb->esc_like( '_ubac_pricing_price_' ) . '%';

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not data.
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$like
	);

	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
}

if ( is_multisite() ) {

	$ubac_pricing_sites = get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'public'   => null,
			'archived' => null,
			'mature'   => null,
			'spam'     => null,
			'deleted'  => null,
		)
	);

	foreach ( $ubac_pricing_sites as $ubac_pricing_site_id ) {

		switch_to_blog( (int) $ubac_pricing_site_id );
		ubac_pricing_uninstall_site();
		restore_current_blog();
	}

	unset( $ubac_pricing_sites, $ubac_pricing_site_id );

} else {
	ubac_pricing_uninstall_site();
}
