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
 * @package DahuUserPricing
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this plugin's data from the current site.
 *
 * @return void
 */
function dahu_user_pricing_uninstall_site() {

	global $wpdb;

	$settings = get_option( 'dahu_user_pricing_settings', array() );
	$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	delete_option( 'dahu_user_pricing_settings' );

	if ( ! $purge ) {
		return;
	}

	// The prefix is the one in Dahu_User_Pricing_Tiers::PRICE_META_PREFIX, and
	// it is written out here because the uninstaller runs with the plugin's
	// classes unloaded. If one of the two ever changes, the other has to.
	$like = $wpdb->esc_like( '_dahu_user_pricing_price_' ) . '%';

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not data.
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$like
	);

	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
}

if ( is_multisite() ) {

	$dahu_user_pricing_sites = get_sites(
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

	foreach ( $dahu_user_pricing_sites as $dahu_user_pricing_site_id ) {

		switch_to_blog( (int) $dahu_user_pricing_site_id );
		dahu_user_pricing_uninstall_site();
		restore_current_blog();
	}

	unset( $dahu_user_pricing_sites, $dahu_user_pricing_site_id );

} else {
	dahu_user_pricing_uninstall_site();
}
