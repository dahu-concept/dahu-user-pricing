<?php
/**
 * Per-tier price fields on the product edit screen.
 *
 * One field per tier, next to WooCommerce's own price fields rather than in a
 * tab of their own: a tier price is a price, and somebody setting the public
 * price is exactly the person who should see that there are three more to set.
 *
 * AN EMPTY FIELD IS NOT A ZERO. Leaving a field empty means "this tier pays
 * the public price"; typing 0 means "this tier pays nothing". The two are
 * different instructions and the save path keeps them apart — an empty field
 * deletes the meta, a zero stores a zero. This is the same distinction the
 * price resolution makes, and it only holds if both ends agree.
 *
 * @package DahuUserPricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin product fields for tier prices.
 */
class Dahu_User_Pricing_Product_Fields {

	/**
	 * Field name for the simple-product price of a tier.
	 */
	const FIELD_SIMPLE = 'dahu_user_pricing_price';

	/**
	 * Field name for the variation price of a tier.
	 */
	const FIELD_VARIATION = 'dahu_user_pricing_variation_price';

	/**
	 * Hook the fields and their saving.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple_fields' ) );

		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
	}

	/**
	 * Price fields for a simple product.
	 *
	 * @return void
	 */
	public static function render_simple_fields() {

		$tiers = Dahu_User_Pricing_Tiers::all();

		if ( ! $tiers ) {
			return;
		}

		$product = self::current_product();

		foreach ( $tiers as $key => $tier ) {

			$value = $product ? $product->get_meta( Dahu_User_Pricing_Tiers::price_meta_key( $key ), true ) : '';

			woocommerce_wp_text_input(
				array(
					// A clean id and a separate name. Left to itself,
					// woocommerce_wp_text_input() uses the id for both, which
					// would put square brackets inside an id attribute and
					// inside the wrapper class it generates from it.
					'id'          => self::FIELD_SIMPLE . '_' . $key,
					'name'        => self::FIELD_SIMPLE . '[' . $key . ']',
					'value'       => is_scalar( $value ) ? (string) $value : '',
					'label'       => sprintf(
						/* translators: 1: tier name, 2: shop currency symbol. */
						esc_html__( '%1$s price (%2$s)', 'dahu-user-pricing' ),
						$tier['label'],
						get_woocommerce_currency_symbol()
					),
					'desc_tip'    => true,
					'description' => esc_html__( 'Leave empty for this tier to pay the public price. Zero means free for this tier.', 'dahu-user-pricing' ),
					'data_type'   => 'price',
					'wrapper_class' => 'dahu-user-pricing-field',
				)
			);
		}
	}

	/**
	 * Price fields for one variation.
	 *
	 * @param int     $loop           Index of the variation in the form.
	 * @param array   $variation_data Legacy variation data, unused.
	 * @param WP_Post $variation      The variation post.
	 * @return void
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {

		unset( $variation_data );

		$tiers = Dahu_User_Pricing_Tiers::all();

		if ( ! $tiers ) {
			return;
		}

		$object = wc_get_product( $variation->ID );

		foreach ( $tiers as $key => $tier ) {

			$value = $object instanceof WC_Product
				? $object->get_meta( Dahu_User_Pricing_Tiers::price_meta_key( $key ), true )
				: '';

			woocommerce_wp_text_input(
				array(
					'id'            => self::FIELD_VARIATION . '_' . $key . '_' . $loop,
					'name'          => self::FIELD_VARIATION . '[' . $key . '][' . $loop . ']',
					'value'         => is_scalar( $value ) ? (string) $value : '',
					'label'         => sprintf(
						/* translators: 1: tier name, 2: shop currency symbol. */
						esc_html__( '%1$s price (%2$s)', 'dahu-user-pricing' ),
						$tier['label'],
						get_woocommerce_currency_symbol()
					),
					'desc_tip'      => true,
					'description'   => esc_html__( 'Leave empty to inherit the parent product, or the public price if it has none.', 'dahu-user-pricing' ),
					'data_type'     => 'price',
					'wrapper_class' => 'form-row form-row-full dahu-user-pricing-field',
				)
			);
		}
	}

	/**
	 * Save the simple-product fields onto the product object.
	 *
	 * WooCommerce saves the object itself after this hook, so nothing here
	 * calls save() — doing so would write the product twice and fire every
	 * save hook a second time.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save_simple_fields( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! self::may_save( $product->get_id() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- may_save() verified the nonce above.
		$submitted = isset( $_POST[ self::FIELD_SIMPLE ] ) ? wp_unslash( $_POST[ self::FIELD_SIMPLE ] ) : array();

		if ( ! is_array( $submitted ) ) {
			return;
		}

		foreach ( Dahu_User_Pricing_Tiers::keys() as $key ) {

			$raw = isset( $submitted[ $key ] ) ? $submitted[ $key ] : '';

			self::apply_price( $product, $key, $raw );
		}
	}

	/**
	 * Save the fields of one variation.
	 *
	 * @param int $variation_id Variation post id.
	 * @param int $loop         Index of the variation in the form.
	 * @return void
	 */
	public static function save_variation_fields( $variation_id, $loop ) {

		if ( ! self::may_save( $variation_id ) ) {
			return;
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- may_save() verified the nonce above.
		$submitted = isset( $_POST[ self::FIELD_VARIATION ] ) ? wp_unslash( $_POST[ self::FIELD_VARIATION ] ) : array();

		if ( ! is_array( $submitted ) ) {
			return;
		}

		$changed = false;

		foreach ( Dahu_User_Pricing_Tiers::keys() as $key ) {

			$raw = ( isset( $submitted[ $key ] ) && is_array( $submitted[ $key ] ) && isset( $submitted[ $key ][ $loop ] ) )
				? $submitted[ $key ][ $loop ]
				: '';

			$changed = self::apply_price( $variation, $key, $raw ) || $changed;
		}

		if ( $changed ) {
			$variation->save();
		}
	}

	/**
	 * Put one submitted value onto a product object.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $tier    Tier key.
	 * @param mixed      $raw     Submitted value.
	 * @return bool True if the object changed.
	 */
	private static function apply_price( $product, $tier, $raw ) {

		$meta_key = Dahu_User_Pricing_Tiers::price_meta_key( $tier );
		$existing = $product->get_meta( $meta_key, true );
		$existing = is_scalar( $existing ) ? (string) $existing : '';

		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		if ( '' === $raw ) {

			if ( '' === $existing ) {
				return false;
			}

			$product->delete_meta_data( $meta_key );

			return true;
		}

		// wc_format_decimal() is what WooCommerce uses on its own price
		// fields: it accepts the comma decimal separator the admin may be
		// typing in and leaves a canonical dot-decimal string behind.
		$value = wc_format_decimal( $raw );

		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			// Refused rather than clamped: a negative or unreadable price is a
			// mistake, and silently turning it into 0 would mean giving the
			// product away.
			return false;
		}

		if ( $existing === $value ) {
			return false;
		}

		$product->update_meta_data( $meta_key, $value );

		return true;
	}

	/**
	 * Whether the current request is allowed to write tier prices.
	 *
	 * WooCommerce checks its own nonces before the hooks this class uses, so
	 * this is a second check rather than the only one — but a second check
	 * that costs nothing and makes the file readable on its own, instead of
	 * relying on a guarantee made three files away in somebody else's plugin.
	 *
	 * The two nonces are the two ways a product gets saved: the whole edit
	 * screen posting to post.php, and the variations panel posting over AJAX.
	 *
	 * @param int $post_id Product or variation id.
	 * @return bool
	 */
	private static function may_save( $post_id ) {

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return false;
		}

		if ( isset( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return true;
		}

		if ( isset( $_POST['security'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * The product being edited, if there is one.
	 *
	 * @return WC_Product|null
	 */
	private static function current_product() {

		global $product_object;

		if ( $product_object instanceof WC_Product ) {
			return $product_object;
		}

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ? (int) $GLOBALS['post']->ID : 0;

		if ( ! $post_id ) {
			return null;
		}

		$product = wc_get_product( $post_id );

		return $product instanceof WC_Product ? $product : null;
	}
}
