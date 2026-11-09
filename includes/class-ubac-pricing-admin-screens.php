<?php
/**
 * The settings screen, and the admin assets.
 *
 * Separate from class-ubac-pricing-settings.php on purpose: that file is
 * read on every request that prices a product, this one only when somebody is
 * looking at a screen. Keeping the markup out of the hot path is also what
 * lets the screen be rewritten without reading the price resolution.
 *
 * @package UbacPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class Ubac_Pricing_Admin_Screens {

	/**
	 * Action name of the tier deletion handler.
	 */
	const DELETE_ACTION = 'ubac_pricing_delete_tier';

	/**
	 * Hook the screens.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

		// options.php, where this screen's form posts, requires
		// `manage_options` unless told otherwise — and this screen is reached
		// with `manage_woocommerce`. Without this line a shop manager can open
		// it, fill it in, press Save and be told they lack permission, losing
		// everything they typed. The screen and its form must agree on who may
		// use them.
		add_filter(
			'option_page_capability_' . Ubac_Pricing_Settings::OPTION_KEY,
			static function () {
				return 'manage_woocommerce';
			}
		);
		add_action( 'admin_post_' . self::DELETE_ACTION, array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the screen under WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu() {

		add_submenu_page(
			'woocommerce',
			__( 'User pricing', 'dahuconcept-customer-pricing' ),
			__( 'User pricing', 'dahuconcept-customer-pricing' ),
			'manage_woocommerce',
			UBAC_PRICING_SETTINGS_PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load admin assets, each on its own screen only.
	 *
	 * @return void
	 */
	public static function enqueue() {

		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		if ( 'woocommerce_page_' . UBAC_PRICING_SETTINGS_PAGE === $screen->id ) {

			// The family sheet first, and its own second: the plugin's own rules
			// are written to sit on top of the shared components, so the order is
			// a dependency and not a hope. One handle across the family -- the
			// file is byte-identical everywhere, so whichever plugin loads it
			// first serves all of them, and a shop with six of these installed
			// downloads one copy.
			wp_enqueue_style(
				'ubac-ui',
				UBAC_PRICING_URL . 'assets/css/ubac-ui.css',
				array(),
				UBAC_PRICING_VERSION
			);

			wp_enqueue_style(
				'ubac-pricing-admin-settings',
				UBAC_PRICING_URL . 'assets/css/admin-settings.css',
				array( 'ubac-ui' ),
				UBAC_PRICING_VERSION
			);

			return;
		}

		if ( 'product' === $screen->id ) {

			// The family sheet first, and its own second: the plugin's own rules
			// are written to sit on top of the shared components, so the order is
			// a dependency and not a hope. One handle across the family -- the
			// file is byte-identical everywhere, so whichever plugin loads it
			// first serves all of them, and a shop with six of these installed
			// downloads one copy.
			wp_enqueue_style(
				'ubac-ui',
				UBAC_PRICING_URL . 'assets/css/ubac-ui.css',
				array(),
				UBAC_PRICING_VERSION
			);

			wp_enqueue_style(
				'ubac-pricing-admin-product',
				UBAC_PRICING_URL . 'assets/css/admin-product.css',
				array( 'ubac-ui' ),
				UBAC_PRICING_VERSION
			);

			return;
		}

		// The order screen, under both storages: the legacy post type and the
		// HPOS page.
		$is_order_screen = ( false !== strpos( $screen->id, 'shop_order' ) )
			|| ( 'woocommerce_page_wc-orders' === $screen->id );

		if ( $is_order_screen ) {

			wp_enqueue_script(
				'ubac-pricing-admin-order',
				UBAC_PRICING_URL . 'assets/js/admin-order.js',
				array( 'jquery' ),
				UBAC_PRICING_VERSION,
				true
			);

			wp_localize_script(
				'ubac-pricing-admin-order',
				'ubacUserPricingOrder',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( Ubac_Pricing_Order::RECALC_NONCE ),
					'repriceLabel' => __( 'Reprice lines for this customer', 'dahuconcept-customer-pricing' ),
					'failed'       => __( 'The lines could not be repriced. Reload the order and try again.', 'dahuconcept-customer-pricing' ),
				)
			);
		}
	}

	/**
	 * Delete one tier.
	 *
	 * Its own request rather than a checkbox in the settings form, because a
	 * tier deletion is not the same kind of act as changing a label: it is the
	 * one thing on this screen that cannot be undone by retyping what was
	 * there.
	 *
	 * @return void
	 */
	public static function handle_delete() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change pricing settings.', 'dahuconcept-customer-pricing' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DELETE_ACTION );

		$tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';

		$deleted = '' !== $tier && Ubac_Pricing_Tiers::delete( $tier );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => UBAC_PRICING_SETTINGS_PAGE,
					'ubac-tier-deleted' => $deleted ? '1' : '0',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = Ubac_Pricing_Settings::all();
		$tiers    = Ubac_Pricing_Tiers::all();
		$roles    = wp_roles()->get_names();
		$option   = Ubac_Pricing_Settings::OPTION_KEY;

		echo '<div class="wrap ubac-ui ubac-pricing-settings">';

		echo '<div class="ubac-ui-head">';
		printf( '<h1 class="ubac-ui-head__title">%s</h1>', esc_html__( 'User pricing', 'dahuconcept-customer-pricing' ) );
		printf( '<p class="ubac-ui-head__sub">%s</p>', esc_html__( 'Prices that follow the customer rather than the catalogue.', 'dahuconcept-customer-pricing' ) );
		echo '</div>';

		self::render_delete_notice();

		echo '<hr class="wp-header-end">';

		if ( ! $tiers ) {
			printf(
				'<div class="ubac-pricing-settings__empty"><p>%s</p></div>',
				esc_html__( 'No tier yet. Name one below and save — nothing about your prices changes until a tier exists and a role is mapped to it.', 'dahuconcept-customer-pricing' )
			);
		}

		echo '<form method="post" action="options.php">';

		settings_fields( $option );

		self::render_tiers( $option, $tiers );
		self::render_role_map( $option, $settings, $tiers, $roles );
		self::render_managers( $option, $settings, $roles );
		self::render_display( $option, $settings );
		self::render_data( $option, $settings );

		submit_button( null, 'ubac-ui-btn ubac-ui-btn--go' );

		echo '</form>';

		self::render_delete_forms( $tiers );

		echo '</div>';
	}

	/**
	 * Notice shown after a deletion.
	 *
	 * @return void
	 */
	private static function render_delete_notice() {

		if ( ! isset( $_GET['ubac-tier-deleted'] ) ) {
			return;
		}

		$deleted = '1' === sanitize_key( wp_unslash( $_GET['ubac-tier-deleted'] ) );

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			$deleted ? 'success' : 'warning',
			esc_html(
				$deleted
					? __( 'Tier deleted. The prices filed under it were kept: recreating the tier with the same name brings them back.', 'dahuconcept-customer-pricing' )
					: __( 'That tier no longer exists.', 'dahuconcept-customer-pricing' )
			)
		);
	}

	/**
	 * The tiers table.
	 *
	 * @param string $option Option key.
	 * @param array  $tiers  Existing tiers.
	 * @return void
	 */
	private static function render_tiers( $option, $tiers ) {

		echo '<div class="ubac-ui-card">';

		printf( '<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%s</h2></div>', esc_html__( 'Tiers', 'dahuconcept-customer-pricing' ) );

		printf(
			'<p class="ubac-pricing-settings__hint">%s</p>',
			esc_html__( 'The labels are shown in front of a price on the shop. Leave one empty to show that price with no prefix. No need for a trailing space — the spacing is handled for you.', 'dahuconcept-customer-pricing' )
		);

		echo '<table class="widefat striped ubac-pricing-settings__table">';

		printf(
			'<thead><tr><th>%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th><th>%5$s</th></tr></thead>',
			esc_html__( 'Name', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Label for the tier price', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Label for the public price', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Key', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Prices set', 'dahuconcept-customer-pricing' )
		);

		echo '<tbody>';

		foreach ( $tiers as $key => $tier ) {
			self::render_tier_row( $option, $key, $tier );
		}

		// One empty row, which is how a tier gets added. A row left blank is
		// discarded on save, so this costs nothing when unused.
		// The placeholder key contains a dot, which sanitize_key() strips, so
		// no real tier can ever be keyed the same and collide with this row.
		self::render_tier_row(
			$option,
			'ubac.new',
			array(
				'label'        => '',
				'label_public' => '',
				'label_tier'   => '',
			),
			true
		);

		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * One row of the tiers table.
	 *
	 * @param string $option Option key.
	 * @param string $key    Tier key, or the placeholder key of the empty row.
	 * @param array  $tier   Tier data.
	 * @param bool   $is_new Whether this is the empty row.
	 * @return void
	 */
	private static function render_tier_row( $option, $key, $tier, $is_new = false ) {

		$name = sprintf( '%1$s[tiers][%2$s]', $option, $key );

		echo '<tr>';

		printf(
			'<td><input type="text" class="regular-text" name="%1$s[label]" value="%2$s" placeholder="%3$s"></td>',
			esc_attr( $name ),
			esc_attr( $tier['label'] ),
			$is_new ? esc_attr__( 'e.g. Trade', 'dahuconcept-customer-pricing' ) : ''
		);

		printf(
			'<td><input type="text" class="regular-text" name="%1$s[label_tier]" value="%2$s" placeholder="%3$s"></td>',
			esc_attr( $name ),
			esc_attr( $tier['label_tier'] ),
			$is_new ? esc_attr__( 'e.g. Your price:', 'dahuconcept-customer-pricing' ) : ''
		);

		printf(
			'<td><input type="text" class="regular-text" name="%1$s[label_public]" value="%2$s" placeholder="%3$s"></td>',
			esc_attr( $name ),
			esc_attr( $tier['label_public'] ),
			$is_new ? esc_attr__( 'e.g. Suggested retail:', 'dahuconcept-customer-pricing' ) : ''
		);

		if ( $is_new ) {
			printf( '<td class="ubac-pricing-settings__muted">%s</td><td></td>', esc_html__( 'set on save', 'dahuconcept-customer-pricing' ) );
			echo '</tr>';
			return;
		}

		printf( '<td><code>%s</code></td>', esc_html( $key ) );

		$count = Ubac_Pricing_Tiers::price_count( $key );

		printf(
			'<td><span class="ubac-pricing-settings__count">%1$s</span><button type="submit" form="ubac-delete-%2$s" class="button-link ubac-pricing-settings__delete">%3$s</button></td>',
			esc_html(
				sprintf(
					/* translators: %s: how many products carry a price for this tier. */
					_n( '%s product', '%s products', $count, 'dahuconcept-customer-pricing' ),
					number_format_i18n( $count )
				)
			),
			esc_attr( $key ),
			esc_html__( 'Delete tier', 'dahuconcept-customer-pricing' )
		);

		echo '</tr>';
	}

	/**
	 * The deletion forms.
	 *
	 * Outside the settings form, because a form cannot be nested in another
	 * one; the buttons above reach them by their `form` attribute.
	 *
	 * @param array $tiers Existing tiers.
	 * @return void
	 */
	private static function render_delete_forms( $tiers ) {

		foreach ( $tiers as $key => $tier ) {

			unset( $tier );

			printf(
				'<form id="ubac-delete-%1$s" method="post" action="%2$s" class="ubac-pricing-settings__delete-form">',
				esc_attr( $key ),
				esc_url( admin_url( 'admin-post.php' ) )
			);

			wp_nonce_field( self::DELETE_ACTION );

			printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::DELETE_ACTION ) );
			printf( '<input type="hidden" name="tier" value="%s">', esc_attr( $key ) );

			echo '</form>';
		}
	}

	/**
	 * The role-to-tier mapping.
	 *
	 * @param string $option   Option key.
	 * @param array  $settings Settings.
	 * @param array  $tiers    Existing tiers.
	 * @param array  $roles    Role slug => name.
	 * @return void
	 */
	private static function render_role_map( $option, $settings, $tiers, $roles ) {

		echo '<div class="ubac-ui-card">';

		printf( '<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%s</h2></div>', esc_html__( 'Which roles get which tier', 'dahuconcept-customer-pricing' ) );

		if ( ! $tiers ) {
			printf(
				'<p class="ubac-pricing-settings__hint">%s</p>',
				esc_html__( 'Nothing to map yet: save a tier first.', 'dahuconcept-customer-pricing' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p class="ubac-pricing-settings__hint">%s</p>',
			esc_html__( 'An account holding two mapped roles gets one of the two tiers, and which one is not defined — give an account one mapped role.', 'dahuconcept-customer-pricing' )
		);

		echo '<table class="widefat striped ubac-pricing-settings__table">';

		printf(
			'<thead><tr><th>%1$s</th><th>%2$s</th></tr></thead>',
			esc_html__( 'Role', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Tier', 'dahuconcept-customer-pricing' )
		);

		echo '<tbody>';

		foreach ( $roles as $slug => $label ) {

			$current = isset( $settings['role_tier_map'][ $slug ] ) ? $settings['role_tier_map'][ $slug ] : '';

			printf(
				'<tr><td>%1$s <code>%2$s</code></td><td><select name="%3$s[role_tier_map][%2$s]">',
				esc_html( $label ),
				esc_attr( $slug ),
				esc_attr( $option )
			);

			printf(
				'<option value="" %1$s>%2$s</option>',
				selected( '', $current, false ),
				esc_html__( 'No tier — public prices', 'dahuconcept-customer-pricing' )
			);

			foreach ( $tiers as $key => $tier ) {

				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $key ),
					selected( $key, $current, false ),
					esc_html( $tier['label'] )
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * The manager roles.
	 *
	 * @param string $option   Option key.
	 * @param array  $settings Settings.
	 * @param array  $roles    Role slug => name.
	 * @return void
	 */
	private static function render_managers( $option, $settings, $roles ) {

		echo '<div class="ubac-ui-card">';

		printf( '<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%s</h2></div>', esc_html__( 'Who can preview a tier', 'dahuconcept-customer-pricing' ) );

		printf(
			'<p class="ubac-pricing-settings__hint">%s</p>',
			esc_html__( 'These roles get a toolbar menu for looking at the shop as any tier. Prices while previewing are really that tier\'s prices, so an order cannot be placed until the preview is stopped.', 'dahuconcept-customer-pricing' )
		);

		printf(
			'<p class="ubac-pricing-settings__hint">%s</p>',
			esc_html__( 'Only roles that can manage WooCommerce are listed: previewing shows every tier\'s prices, which is not something a shop should be able to hand to its customers by ticking a box.', 'dahuconcept-customer-pricing' )
		);

		echo '<fieldset class="ubac-pricing-settings__roles">';

		foreach ( $roles as $slug => $label ) {

			$ticked = in_array( $slug, (array) $settings['manager_roles'], true );

			// A role that cannot manage WooCommerce would be ignored at run
			// time — see Ubac_Pricing_Context::is_manager() — so offering
			// it here would be offering a checkbox that does nothing. One
			// already saved is still shown, so that it can be unticked.
			if ( ! $ticked && ! self::role_can_manage( $slug ) ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%1$s[manager_roles][]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
				esc_attr( $option ),
				esc_attr( $slug ),
				checked( $ticked, true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		echo '</div>';
	}

	/**
	 * Whether a role carries the capability the preview requires.
	 *
	 * @param string $slug Role slug.
	 * @return bool
	 */
	private static function role_can_manage( $slug ) {

		$role = get_role( $slug );

		return $role instanceof WP_Role && $role->has_cap( 'manage_woocommerce' );
	}

	/**
	 * The display options.
	 *
	 * @param string $option   Option key.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private static function render_display( $option, $settings ) {

		echo '<div class="ubac-ui-card">';

		printf( '<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%s</h2></div>', esc_html__( 'Display', 'dahuconcept-customer-pricing' ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><fieldset class="ubac-pricing-settings__margin">',
			esc_html__( 'Margin between the two prices', 'dahuconcept-customer-pricing' )
		);

		$modes = array(
			'none'        => __( 'Do not show it', 'dahuconcept-customer-pricing' ),
			'percent'     => __( 'As a percentage of the public price', 'dahuconcept-customer-pricing' ),
			'coefficient' => __( 'As a multiplier', 'dahuconcept-customer-pricing' ),
		);

		foreach ( $modes as $value => $label ) {

			printf(
				'<label><input type="radio" name="%1$s[margin_display]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( $option ),
				esc_attr( $value ),
				checked( $value, $settings['margin_display'], false ),
				esc_html( $label )
			);
		}

		printf(
			'</fieldset><p class="description">%s</p></td></tr>',
			esc_html__( 'A margin tells a reseller what they make on a resale. It is off by default because it is commercial information, not decoration.', 'dahuconcept-customer-pricing' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[view_switch]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html__( 'Public-price view switch', 'dahuconcept-customer-pricing' ),
			esc_attr( $option ),
			checked( ! empty( $settings['view_switch'] ), true, false ),
			esc_html__( 'Let an account switch between its own prices and the public ones', 'dahuconcept-customer-pricing' ),
			sprintf(
				/* translators: %s: the shortcode that renders the switch. */
				esc_html__( 'Place the control with the %s shortcode. The switch changes what is displayed only: the cart always charges the account\'s own price.', 'dahuconcept-customer-pricing' ),
				'<code>[ubac_pricing_view_switch]</code>'
			)
		);

		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * What happens to the data.
	 *
	 * Its own section rather than a line under Display, because it is the one
	 * setting on this screen that decides whether something is destroyed.
	 *
	 * @param string $option   Option key.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private static function render_data( $option, $settings ) {

		echo '<div class="ubac-ui-card">';

		printf( '<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%s</h2></div>', esc_html__( 'Data', 'dahuconcept-customer-pricing' ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[delete_data_on_uninstall]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html__( 'On uninstall', 'dahuconcept-customer-pricing' ),
			esc_attr( $option ),
			checked( ! empty( $settings['delete_data_on_uninstall'] ), true, false ),
			esc_html__( 'Also delete every tier price set on a product', 'dahuconcept-customer-pricing' ),
			esc_html__( 'Off by default. Tier prices are typed in by hand and deleting them cannot be undone, so uninstalling keeps them and a reinstall finds them again.', 'dahuconcept-customer-pricing' )
		);

		echo '</tbody></table>';

		echo '</div>';
	}
}
