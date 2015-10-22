<?php
/**
 * Subscriptions Cart Class
 *
 * Mirrors a few functions in the WC_Cart class to work for subscriptions.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Cart
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.0
 */
class WC_Subscriptions_Cart {

	/**
	 * A flag to control how to modify the calculation of totals by WC_Cart::calculate_totals()
	 *
	 * Can take any one of these values:
	 * - 'none' used to calculate the initial total.
	 * - 'combined_total' used to calculate the total of sign-up fee + recurring amount.
	 * - 'sign_up_fee_total' used to calculate the initial amount when there is a free trial period and a sign-up fee. Different to 'combined_total' because shipping is not charged on a sign-up fee.
	 * - 'recurring_total' used to calculate the totals for the recurring amount when the recurring amount differs to to 'combined_total' because of coupons or sign-up fees.
	 * - 'free_trial_total' used to calculate the initial total when there is a free trial period and no sign-up fee. Different to 'combined_total' because shipping is not charged up-front when there is a free trial.
	 *
	 * @since 1.2
	 */
	private static $calculation_type = 'none';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Make sure WC calculates total on sign up fee + price per period, and keep a record of the price per period
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::add_calculation_price_filter', 10 );
		add_action( 'woocommerce_calculate_totals', __CLASS__ . '::remove_calculation_price_filter', 10 );
		add_action( 'woocommerce_after_calculate_totals', __CLASS__ . '::remove_calculation_price_filter', 10 );

		add_filter( 'woocommerce_calculated_total', __CLASS__ . '::calculate_subscription_totals', 1000, 2 );

		// Remove any subscriptions with a free trial from the initial shipping packages
		add_filter( 'woocommerce_cart_shipping_packages', __CLASS__ . '::set_cart_shipping_packages', -10, 1 );

		// Don't display shipping prices if the initial order won't require shipping (i.e. all the products in the cart are subscriptions with a free trial or synchronised to a date in the future)
		add_action( 'woocommerce_cart_shipping_method_full_label', __CLASS__ . '::get_cart_shipping_method_full_label', 10, 2 );

		// Display Formatted Totals
		add_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

		// Sometimes, even if the order total is $0, the cart still needs payment
		add_filter( 'woocommerce_cart_needs_payment', __CLASS__ . '::cart_needs_payment' , 10, 2 );

		// Make sure cart product prices correctly include/exclude taxes
		add_filter( 'woocommerce_cart_product_price', __CLASS__ . '::cart_product_price' , 10, 2 );

		// Make sure cart totals are calculated when setting up the cart widget
		add_action( 'wc_ajax_get_refreshed_fragments', __CLASS__ . '::pre_get_refreshed_fragments' , 1 );
		add_action( 'wp_ajax_woocommerce_get_refreshed_fragments', __CLASS__ . '::pre_get_refreshed_fragments', 1 );
		add_action( 'wp_ajax_nopriv_woocommerce_get_refreshed_fragments', __CLASS__ . '::pre_get_refreshed_fragments', 1, 1 );

		add_action( 'woocommerce_ajax_added_to_cart', __CLASS__ . '::pre_get_refreshed_fragments', 1, 1 );

		// Display grouped recurring amounts after order totals on the cart/checkout pages
		add_action( 'woocommerce_cart_totals_after_order_total', __CLASS__ . '::display_recurring_totals' );
		add_action( 'woocommerce_review_order_after_order_total', __CLASS__ . '::display_recurring_totals' );

		add_action( 'woocommerce_add_to_cart_validation', __CLASS__ . '::check_valid_add_to_cart', 10, 3 );

		add_filter( 'woocommerce_cart_needs_shipping', __CLASS__ . '::cart_needs_shipping', 11, 1 );
	}

	/**
	 * Attaches the "set_subscription_prices_for_calculation" filter to the WC Product's woocommerce_get_price hook.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.2
	 */
	public static function add_calculation_price_filter() {

		WC()->cart->recurring_carts = array();

		// Only hook when cart contains a subscription
		if ( ! self::cart_contains_subscription() ) {
			return;
		}

		// Set which price should be used for calculation
		add_filter( 'woocommerce_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
	}

	/**
	 * Removes the "set_subscription_prices_for_calculation" filter from the WC Product's woocommerce_get_price hook once
	 * calculations are complete.
	 *
	 * @since 1.2
	 */
	public static function remove_calculation_price_filter() {
		remove_filter( 'woocommerce_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
	}

	/**
	 * If we are running a custom calculation, we need to set the price returned by a product
	 * to be the appropriate value. This may include just the sign-up fee, a combination of the
	 * sign-up fee and recurring amount or just the recurring amount (default).
	 *
	 * If there are subscriptions in the cart and the product is not a subscription, then
	 * set the recurring total to 0.
	 *
	 * @since 1.2
	 */
	public static function set_subscription_prices_for_calculation( $price, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {

			// For original calculations, we need the items price to account for sign-up fees and/or free trial
			if ( 'none' == self::$calculation_type ) {

				// Use the cart value first, then fall back to the product value, as plugins may override the cart value (and even Subscriptions itself does with WC_Subscriptions_Synchroniser setting a free trial)
				$sign_up_fee  = ( isset( $product->subscription_sign_up_fee ) ) ? $product->subscription_sign_up_fee : WC_Subscriptions_Product::get_sign_up_fee( $product );
				$trial_length = ( isset( $product->subscription_trial_length ) ) ? $product->subscription_trial_length : WC_Subscriptions_Product::get_trial_length( $product );

				if ( $trial_length > 0 ) {
					$price = $sign_up_fee;
				} else {
					$price += $sign_up_fee;
				}
			}  // else $price = recurring amount already as WC_Product->get_price() returns subscription price

			$price = apply_filters( 'woocommerce_subscriptions_cart_get_price', $price, $product );

		// Make sure the recurring amount for any non-subscription products in the cart with a subscription is $0
		} elseif ( 'recurring_total' == self::$calculation_type ) {

			$price = 0;

		}

		return $price;
	}

	/**
	 * Calculate the initial and recurring totals for all subscription products in the cart.
	 *
	 * We need to group subscriptions by billing schedule to make the display and creation of recurring totals sane,
	 * when there are multiple subscriptions in the cart. To do that, we use an array with keys of the form:
	 * '{billing_interval}_{billing_period}_{trial_interval}_{trial_period}_{length}_{billing_period}'. This key
	 * is used to reference WC_Cart objects for each recurring billing schedule and these are stored in the master
	 * cart with the billing schedule key.
	 *
	 * After we have calculated and grouped all recurring totals, we need to checks the structure of the subscription
	 * product prices to see whether they include sign-up fees and/or free trial periods and then recalculates the
	 * appropriate totals by using the @see self::$calculation_type flag and cloning the cart to run @see WC_Cart::calculate_totals()
	 *
	 * @since 1.3.5
	 * @version 2.0
	 */
	public static function calculate_subscription_totals( $total, $cart ) {

		if ( ! self::cart_contains_subscription() && ! wcs_cart_contains_resubscribe() ) { // cart doesn't contain subscription
			return $total;
		} elseif ( 'none' != self::$calculation_type ) { // We're in the middle of a recalculation, let it run
			return $total;
		}

		// Save the original cart values/totals, as we'll use this when there is no sign-up fee
		WC()->cart->total = ( $total < 0 ) ? 0 : $total;

		do_action( 'woocommerce_subscription_cart_before_grouping' );

		$subscription_groups = array();

		// Group the subscription items by their cart item key based on billing schedule
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$subscription_groups[ self::get_recurring_cart_key( $cart_item ) ][] = $cart_item_key;
			}
		}

		do_action( 'woocommerce_subscription_cart_after_grouping' );

		$recurring_carts = array();

		// Now let's calculate the totals for each group of subscriptions
		self::$calculation_type = 'recurring_total';

		foreach ( $subscription_groups as $recurring_cart_key => $subscription_group ) {

			// Create a clone cart to calculate and store totals for this group of subscriptions
			$recurring_cart   = clone WC()->cart;
			$product          = null;

			// Remove any items not in this subscription group
			foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! in_array( $cart_item_key, $subscription_group ) ) {
					unset( $recurring_cart->cart_contents[ $cart_item_key ] );
					continue;
				}

				if ( null === $product ) {
					$product = $cart_item['data'];
				}
			}

			$recurring_cart->start_date         = apply_filters( 'wcs_recurring_cart_start_date', gmdate( 'Y-m-d H:i:s' ), $recurring_cart );
			$recurring_cart->trial_end_date     = apply_filters( 'wcs_recurring_cart_trial_end_date', WC_Subscriptions_Product::get_trial_expiration_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );
			$recurring_cart->next_payment_date  = apply_filters( 'wcs_recurring_cart_next_payment_date', WC_Subscriptions_Product::get_first_renewal_payment_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );
			$recurring_cart->end_date           = apply_filters( 'wcs_recurring_cart_end_date', WC_Subscriptions_Product::get_expiration_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );

			// No fees recur (yet)
			$recurring_cart->fees = array();
			$recurring_cart->fee_total = 0;
			WC()->shipping->reset_shipping();
			self::maybe_recalculate_shipping();
			$recurring_cart->calculate_totals();

			// Store this groups cart details
			$recurring_carts[ $recurring_cart_key ] = clone $recurring_cart;

			// And remove some other floatsam
			$recurring_carts[ $recurring_cart_key ]->removed_cart_contents = array();
			$recurring_carts[ $recurring_cart_key ]->cart_session_data = array();
		}

		self::$calculation_type = 'none';

		// We need to reset the packages and totals stored in WC()->shipping too
		self::maybe_recalculate_shipping();
		WC()->cart->calculate_shipping();

		// If there is no sign-up fee and a free trial, and no products being purchased with the subscription, we need to zero the fees for the first billing period
		if ( 0 == self::get_cart_subscription_sign_up_fee() && self::all_cart_items_have_free_trial() ) {
			foreach ( WC()->cart->get_fees() as $fee_index => $fee ) {
				WC()->cart->fees[ $fee_index ]->amount = 0;
				WC()->cart->fees[ $fee_index ]->tax = 0;
			}
			WC()->cart->fee_total = 0;
		}

		WC()->cart->recurring_carts = $recurring_carts;

		$total = max( 0, round( WC()->cart->cart_contents_total + WC()->cart->tax_total + WC()->cart->shipping_tax_total + WC()->cart->shipping_total + WC()->cart->fee_total, WC()->cart->dp ) );

		if ( isset( WC()->cart->discount_total ) && 0 !== WC()->cart->discount_total ) { // WC < 2.3, deduct deprecated after tax discount total
			$total = max( 0, round( $total - WC()->cart->discount_total, WC()->cart->dp ) );
		}

		if ( ! self::charge_shipping_up_front() ) {
			$total = max( 0, $total - WC()->cart->shipping_tax_total - WC()->cart->shipping_total );
			WC()->cart->shipping_taxes = array();
			WC()->cart->shipping_tax_total = 0;
			WC()->cart->shipping_total     = 0;
		}

		return apply_filters( 'woocommerce_subscriptions_calculated_total', $total );
	}

	/**
	 * Check whether shipping should be charged on the initial order.
	 *
	 * When the cart contains a physical subscription with a free trial and no other physical items, shipping
	 * should not be charged up-front.
	 *
	 * @since 1.5.4
	 */
	public static function charge_shipping_up_front() {

		$charge_shipping_up_front = true;

		if ( self::all_cart_items_have_free_trial() ) {

			$charge_shipping_up_front  = false;
			$other_items_need_shipping = false;

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) && $cart_item['data']->needs_shipping() ) {
					$other_items_need_shipping = true;
				}
			}

			if ( false === $other_items_need_shipping ) {
				$charge_shipping_up_front = false;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_shipping_up_front', $charge_shipping_up_front );
	}

	/**
	 * The cart needs shipping only if it needs shipping up front and/or for recurring items.
	 *
	 * @since 2.0
	 */
	public static function cart_needs_shipping( $needs_shipping ) {

		if ( self::cart_contains_subscription() ) {
			// Back up the shipping method. Chances are WC is going to wipe the chosen_shipping_methods data
			WC()->session->set( 'ost_shipping_methods', WC()->session->get( 'chosen_shipping_methods' ) );
			if ( 'none' == self::$calculation_type ) {
				if ( true == $needs_shipping && ! self::charge_shipping_up_front() && ! self::cart_contains_subscriptions_needing_shipping() ) {
					$needs_shipping = false;
				} elseif ( false == $needs_shipping && ( self::charge_shipping_up_front() || self::cart_contains_subscriptions_needing_shipping() ) ) {
					$needs_shipping = false;
				}
			} elseif ( 'recurring_total' == self::$calculation_type ) {
				if ( true == $needs_shipping && ! self::cart_contains_subscriptions_needing_shipping() ) {
					$needs_shipping = false;
				} elseif ( false == $needs_shipping && self::cart_contains_subscriptions_needing_shipping() ) {
					$needs_shipping = true;
				}
			}
		}

		return $needs_shipping;
	}

	/**
	 * Check whether all the subscription product items in the cart have a free trial.
	 *
	 * Useful for determining if certain up-front amounts should be charged.
	 *
	 * @since 2.0
	 */
	public static function all_cart_items_have_free_trial() {

		$all_items_have_free_trial = true;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$all_items_have_free_trial = false;
				break;
			} else {
				$trial_length = ( isset( $cart_item['data']->subscription_trial_length ) ) ? $cart_item['data']->subscription_trial_length : WC_Subscriptions_Product::get_trial_length( $cart_item['data'] );
				if ( 0 == $trial_length ) {
					$all_items_have_free_trial = false;
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_all_cart_items_have_free_trial', $all_items_have_free_trial );
	}

	/**
	 * Check if the cart contains a subscription which requires shipping.
	 *
	 * @since 1.5.4
	 */
	public static function cart_contains_subscriptions_needing_shipping() {

		if ( 'no' === get_option( 'woocommerce_calc_shipping' ) ) {
			return false;
		}

		$cart_contains_subscriptions_needing_shipping = false;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
				$_product = $values['data'];
				if ( WC_Subscriptions_Product::is_subscription( $_product ) && $_product->needs_shipping() && 'yes' !== $_product->subscription_one_time_shipping ) {
					$cart_contains_subscriptions_needing_shipping = true;
				}
			}
		}

		return apply_filters( 'woocommerce_cart_contains_subscriptions_needing_shipping', $cart_contains_subscriptions_needing_shipping );
	}

	/**
	 * Filters the cart contents to remove any subscriptions with free trials (or synchronised to a date in the future)
	 * to make sure no shipping amount is calculated for them.
	 *
	 * @since 2.0
	 */
	public static function set_cart_shipping_packages( $packages ) {

		if ( 'none' == self::$calculation_type ) {
			foreach ( $packages as $index => $package ) {
				foreach ( $package['contents'] as $cart_item_key => $cart_item ) {
					$trial_length = ( isset( $cart_item['data']->subscription_trial_length ) ) ? $cart_item['data']->subscription_trial_length : WC_Subscriptions_Product::get_trial_length( $cart_item['data'] );
					if ( $trial_length > 0 ) {
						unset( $packages[ $index ]['contents'][ $cart_item_key ] );
					}
				}
			}
		} elseif ( 'recurring_total' == self::$calculation_type ) {
			foreach ( $packages as $index => $package ) {
				foreach ( $package['contents'] as $cart_item_key => $cart_item ) {
					if ( isset( $cart_item['data']->subscription_one_time_shipping ) && 'yes' == $cart_item['data']->subscription_one_time_shipping ) {
						$packages[ $index ]['contents_cost'] -= $cart_item['line_total'];
						unset( $packages[ $index ]['contents'][ $cart_item_key ] );
					}
				}

				if ( empty( $packages[ $index ]['contents'] ) ) {
					unset( $packages[ $index ] );
				}
			}
		}

		return $packages;
	}

	/* Formatted Totals Functions */

	/**
	 * Returns the subtotal for a cart item including the subscription period and duration details
	 *
	 * @since 1.0
	 */
	public static function get_formatted_product_subtotal( $product_subtotal, $product, $quantity, $cart ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) && ! wcs_cart_contains_renewal() ) {

			// Avoid infinite loop
			remove_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

			add_filter( 'woocommerce_get_price', 'WC_Subscriptions_Product::get_sign_up_fee_filter', 100, 2 );

			// And get the appropriate sign up fee string
			$sign_up_fee_string = $cart->get_product_subtotal( $product, $quantity );

			remove_filter( 'woocommerce_get_price',  'WC_Subscriptions_Product::get_sign_up_fee_filter', 100, 2 );

			add_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

			$product_subtotal = WC_Subscriptions_Product::get_price_string( $product, array(
				'price'           => $product_subtotal,
				'sign_up_fee'     => $sign_up_fee_string,
				'tax_calculation' => WC()->cart->tax_display_cart,
				)
			);

			if ( false !== strpos( $product_subtotal, WC()->countries->inc_tax_or_vat() ) ) {
				$product_subtotal = str_replace( WC()->countries->inc_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
			}
			if ( false !== strpos( $product_subtotal, WC()->countries->ex_tax_or_vat() ) ) {
				$product_subtotal = str_replace( WC()->countries->ex_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' .  WC()->countries->ex_tax_or_vat() . '</small>';
			}

			$product_subtotal = '<span class="subscription-price">' . $product_subtotal . '</span>';
		}

		return $product_subtotal;
	}

	/*
	 * Helper functions for extracting the details of subscriptions in the cart
	 */

	/**
	 * Don't display shipping prices if the initial order won't require shipping (i.e. all the products in the cart are subscriptions with a free trial or synchronised to a date in the future)
	 *
	 * @return string Label for a shipping method
	 * @since 1.3
	 */
	public static function get_cart_shipping_method_full_label( $label, $method ) {

		if ( ! self::charge_shipping_up_front() ) {
			$label = $method->label;
		}

		return $label;
	}

	/**
	 * Checks the cart to see if it contains a subscription product.
	 *
	 * @since 1.0
	 */
	public static function cart_contains_subscription() {

		$contains_subscription = false;

		if ( ! empty( WC()->cart->cart_contents ) && ! wcs_cart_contains_renewal() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$contains_subscription = true;
					break;
				}
			}
		}

		return $contains_subscription;
	}

	/**
	 * Checks the cart to see if it contains a subscription product with a free trial
	 *
	 * @since 1.2
	 */
	public static function cart_contains_free_trial() {

		$cart_contains_free_trial = false;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['data']->subscription_trial_length ) && $cart_item['data']->subscription_trial_length > 0 ) {
					$cart_contains_free_trial = true;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
					$cart_contains_free_trial = true;
					break;
				}
			}
		}

		return $cart_contains_free_trial;
	}

	/**
	 * Gets the recalculate flag
	 *
	 * @since 1.2
	 */
	public static function get_calculation_type() {
		return self::$calculation_type;
	}

	/**
	 * Gets the recalculate flag
	 *
	 * @since 2.0
	 */
	public static function set_calculation_type( $calculation_type ) {

		self::$calculation_type = $calculation_type;

		return $calculation_type;
	}

	/**
	 * Gets the subscription sign up fee for the cart and returns it
	 *
	 * Currently short-circuits to return just the sign-up fee of the first subscription, because only
	 * one subscription can be purchased at a time.
	 *
	 * @since 1.0
	 */
	public static function get_cart_subscription_sign_up_fee() {

		$sign_up_fee = 0;

		if ( self::cart_contains_subscription() || wcs_cart_contains_renewal() ) {

			$renewal_item = wcs_cart_contains_renewal();

			foreach ( WC()->cart->cart_contents as $cart_item ) {

				// Renewal items do not have sign-up fees
				if ( $renewal_item == $cart_item ) {
					continue;
				}

				if ( isset( $cart_item['data']->subscription_sign_up_fee ) ) {
					$sign_up_fee += $cart_item['data']->subscription_sign_up_fee;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$sign_up_fee += WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_sign_up_fee', $sign_up_fee );
	}

	/**
	 * Check whether the cart needs payment even if the order total is $0
	 *
	 * @param bool $needs_payment The existing flag for whether the cart needs payment or not.
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		if ( false === $needs_payment && self::cart_contains_subscription() && $cart->total == 0 && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {

			$recurring_total = 0;
			$is_one_period   = true;
			$is_synced = false;

			foreach ( WC()->cart->recurring_carts as $cart ) {

				$recurring_total += $cart->total;

				$cart_length = wcs_cart_pluck( $cart, 'subscription_length' );

				if ( 0 == $cart_length || wcs_cart_pluck( $cart, 'subscription_period_interval' ) != $cart_length ) {
					$is_one_period = false;
				}

				$is_synced = ( $is_synced || false != WC_Subscriptions_Synchroniser::cart_contains_synced_subscription( $cart ) ) ? true : false;
			}

			$has_trial = self::cart_contains_free_trial();

			if ( $recurring_total > 0 && ( false === $is_one_period || true === $has_trial || ( false !== $is_synced && false == WC_Subscriptions_Synchroniser::is_today( WC_Subscriptions_Synchroniser::calculate_first_payment_date( $is_synced['data'], 'timestamp' ) ) ) ) ) {
				$needs_payment = true;
			}
		}

		return $needs_payment;
	}

	/**
	 * Re-calculate a shipping and tax estimate when on the cart page.
	 *
	 * The WC_Shortcode_Cart actually calculates shipping when the "Calculate Shipping" form is submitted on the
	 * cart page. Because of that, our own @see self::calculate_totals() method calculates incorrect values on
	 * the cart page because it triggers the method multiple times for multiple different pricing structures.
	 * This uses the same logic found in WC_Shortcode_Cart::output() to determine the correct estimate.
	 *
	 * @since 1.4.10
	 */
	private static function maybe_recalculate_shipping() {
		if ( ! empty( $_POST['calc_shipping'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce-cart' ) && function_exists( 'WC' ) ) {

			try {
				WC()->shipping->reset_shipping();

				$country  = wc_clean( $_POST['calc_shipping_country'] );
				$state    = isset( $_POST['calc_shipping_state'] ) ? wc_clean( $_POST['calc_shipping_state'] ) : '';
				$postcode = apply_filters( 'woocommerce_shipping_calculator_enable_postcode', true ) ? wc_clean( $_POST['calc_shipping_postcode'] ) : '';
				$city     = apply_filters( 'woocommerce_shipping_calculator_enable_city', false ) ? wc_clean( $_POST['calc_shipping_city'] ) : '';

				if ( $postcode && ! WC_Validation::is_postcode( $postcode, $country ) ) {
					throw new Exception( __( 'Please enter a valid postcode/ZIP.', 'woocommerce-subscriptions' ) );
				} elseif ( $postcode ) {
					$postcode = wc_format_postcode( $postcode, $country );
				}

				if ( $country ) {
					WC()->customer->set_location( $country, $state, $postcode, $city );
					WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
				} else {
					WC()->customer->set_to_base();
					WC()->customer->set_shipping_to_base();
				}

				WC()->customer->calculated_shipping( true );

				do_action( 'woocommerce_calculated_shipping' );

			} catch ( Exception $e ) {
				if ( ! empty( $e ) ) {
					wc_add_notice( $e->getMessage(), 'error' );
				}
			}
		}

		// If we had one time shipping in the carts, we may have wiped the WC chosen shippings. Restore them.
		self::maybe_restore_chosen_shipping_method();

		// Now make sure the correct shipping method is set
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			foreach ( $_POST['shipping_method'] as $i => $value ) {
				$chosen_shipping_methods[ $i ] = wc_clean( $value );
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	/**
	 * Make sure cart product prices correctly include/exclude taxes.
	 *
	 * @since 1.5.8
	 */
	public static function cart_product_price( $price, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
			$price = WC_Subscriptions_Product::get_price_string( $product, array( 'price' => $price, 'tax_calculation' => WC()->cart->tax_display_cart ) );
		}

		return $price;
	}

	/**
	 * Make sure cart totals are calculated when the cart widget is populated via the get_refreshed_fragments() method
	 * so that @see self::get_formatted_cart_subtotal() returns the correct subtotal price string.
	 *
	 * @since 1.5.11
	 */
	public static function pre_get_refreshed_fragments() {
		if ( defined( 'DOING_AJAX' ) && true === DOING_AJAX && ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Display the recurring totals for items in the cart
	 *
	 * @since 2.0
	 */
	public static function display_recurring_totals() {

		if ( self::cart_contains_subscription() ) {

			// We only want shipping for recurring amounts, and they need to be calculated again here
			self::$calculation_type = 'recurring_total';

			$shipping_methods = array();

			$carts_with_multiple_payments = 0;

			// Create new subscriptions for each subscription product in the cart (that is not a renewal)
			foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

				// Cart contains more than one payment
				if ( 0 != $recurring_cart->next_payment_date ) {
					$carts_with_multiple_payments++;

					// Create shipping packages for each subscription item
					if ( self::cart_contains_subscriptions_needing_shipping() ) {

						$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

						// Don't remove any subscriptions with a free trial from the shipping packages
						foreach ( $recurring_cart->get_shipping_packages() as $base_package ) {

							$package = WC()->shipping->calculate_shipping_for_package( $base_package );

							// Only display the costs for the chosen shipping method
							foreach ( $chosen_shipping_methods as $package_key ) {
								if ( isset( $package['rates'][ $package_key ] ) ) {
									$shipping_methods[ $recurring_cart_key ] = $package['rates'][ $package_key ];
								}
							}
						}
					}
				}
			}

			if ( $carts_with_multiple_payments >= 1 ) {
				wc_get_template( 'checkout/recurring-totals.php', array( 'shipping_methods' => $shipping_methods, 'recurring_carts' => WC()->cart->recurring_carts, 'carts_with_multiple_payments' => $carts_with_multiple_payments ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/' );
			}

			self::$calculation_type = 'none';
		}
	}

	/**
	 * Construct a cart key based on the billing schedule of a subscription product.
	 *
	 * Subscriptions groups products by billing schedule when calculating cart totals, so that shipping and other "per order" amounts
	 * can be calculated for each group of items for each renewal. This method constructs a cart key based on the billing schedule
	 * to allow products on the same billing schedule to be grouped together - free trials and synchronisation is accounted for by
	 * using the first renewal date (if any) for the susbcription.
	 *
	 * @since 2.0
	 */
	public static function get_recurring_cart_key( $cart_item, $renewal_time = '' ) {

		$cart_key = '';

		$product      = $cart_item['data'];
		$product_id   = ! empty( $product->variation_id ) ? $product->variation_id : $product->id;
		$renewal_time = ! empty( $renewal_time ) ? $renewal_time : WC_Subscriptions_Product::get_first_renewal_payment_time( $product_id );
		$interval     = WC_Subscriptions_Product::get_interval( $product );
		$period       = WC_Subscriptions_Product::get_period( $product );
		$length       = WC_Subscriptions_Product::get_length( $product );
		$trial_period = WC_Subscriptions_Product::get_trial_period( $product );
		$trial_length = WC_Subscriptions_Product::get_trial_length( $product );

		if ( $renewal_time > 0 ) {
			$cart_key .= date( 'Y_m_d_', $renewal_time );
		}

		// First start with the billing interval and period
		switch ( $interval ) {
			case 1 :
				if ( 'day' == $period ) {
					$cart_key .= 'daily'; // always gotta be one exception
				} else {
					$cart_key .= sprintf( '%sly', $period );
				}
				break;
			case 2 :
				$cart_key .= sprintf( 'every_2nd_%s', $period );
				break;
			case 3 :
				$cart_key .= sprintf( 'every_3rd_%s', $period ); // or sometimes two exceptions it would seem
				break;
			default:
				$cart_key .= sprintf( 'every_%dth_%s', $interval, $period );
				break;
		}

		if ( $length > 0 ) {
			$cart_key .= '_for_';
			$cart_key .= sprintf( '%d_%s', $length, $period );
			if ( $length > 1 ) {
				$cart_key .= 's';
			}
		}

		if ( $trial_length > 0 ) {
			$cart_key .= sprintf( '_after_a_%d_%s_trial', $trial_length, $trial_period );
		}

		return apply_filters( 'woocommerce_subscriptions_recurring_cart_key', $cart_key, $cart_item );
	}

	/**
	 * Don't allow other subscriptions to be added to the cart while it contains a renewal
	 *
	 * @since 2.0
	 */
	public static function check_valid_add_to_cart( $is_valid, $product, $quantity ) {

		if ( $is_valid && wcs_cart_contains_renewal() && WC_Subscriptions_Product::is_subscription( $product ) ) {

			wc_add_notice( __( 'That subscription product can not be added to your cart as it already contains a subscription renewal.', 'woocommerce-subscriptions' ), 'error' );
			$is_valid = false;
		}

		return $is_valid;
	}

	/* Deprecated */

	/**
	 * Returns the formatted subscription price string for an item
	 *
	 * @since 1.0
	 */
	public static function get_cart_item_price_html( $price_string, $cart_item ) {

		_deprecated_function( __METHOD__, '1.2' );

		return $price_string;
	}

	/**
	 * Returns either the total if prices include tax because this doesn't include tax, or the
	 * subtotal if prices don't includes tax, because this doesn't include tax.
	 *
	 * @return string formatted price
	 *
	 * @since 1.0
	 */
	public static function get_cart_contents_total( $cart_contents_total ) {

		_deprecated_function( __METHOD__, '1.2' );

		return $cart_contents_total;
	}

	/**
	 * Calculate totals for the sign-up fees in the cart, based on @see WC_Cart::calculate_totals()
	 *
	 * @since 1.0
	 */
	public static function calculate_sign_up_fee_totals() {
		_deprecated_function( __METHOD__, '1.2' );
	}

	/**
	 * Function to apply discounts to a product and get the discounted price (before tax is applied)
	 *
	 * @param mixed $values
	 * @param mixed $price
	 * @param bool $add_totals (default: false)
	 * @return float price
	 * @since 1.0
	 */
	public static function get_discounted_price( $values, $price, $add_totals = false ) {

		_deprecated_function( __METHOD__, '1.2' );

		return $price;
	}

	/**
	 * Function to apply product discounts after tax
	 *
	 * @param mixed $values
	 * @param mixed $price
	 * @since 1.0
	 */
	public static function apply_product_discounts_after_tax( $values, $price ) {
		_deprecated_function( __METHOD__, '1.2' );
	}

	/**
	 * Function to apply cart discounts after tax
	 *
	 * @since 1.0
	 */
	public static function apply_cart_discounts_after_tax() {
		_deprecated_function( __METHOD__, '1.2' );
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * @return float price
	 */
	public static function get_sign_up_taxes_total( $compound = true ) {
		_deprecated_function( __METHOD__, '1.2' );
		return 0;
	}

	public static function get_sign_up_fee_fields() {
		_deprecated_function( __METHOD__, '1.2' );

		return array(
			'cart_contents_sign_up_fee_total',
			'cart_contents_sign_up_fee_count',
			'sign_up_fee_total',
			'sign_up_fee_subtotal',
			'sign_up_fee_subtotal_ex_tax',
			'sign_up_fee_tax_total',
			'sign_up_fee_taxes',
			'sign_up_fee_discount_cart',
			'sign_up_fee_discount_total',
		);
	}

	/**
	 * Returns the subtotal for a cart item including the subscription period and duration details
	 *
	 * @since 1.0
	 */
	public static function get_product_subtotal( $product_subtotal, $product ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_product_subtotal( $product_subtotal, $product )' );
		return self::get_formatted_product_subtotal( $product_subtotal, $product );
	}

	/**
	 * Returns a string with the cart discount and subscription period.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_discounts_before_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_discounts_before_tax( $discount )' );
		return self::get_formatted_discounts_before_tax( $discount );
	}

	/**
	 * Gets the order discount amount - these are applied after tax
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_discounts_after_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_discounts_after_tax( $discount )' );
		return self::get_formatted_discounts_after_tax( $discount );
	}

	/**
	 * Includes the sign-up fee subtotal in the subtotal displayed in the cart.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_cart_subtotal( $cart_subtotal, $compound, $cart ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart )' );
		return self::get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart );
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_total( $total ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_total( $total )' );
		return self::get_formatted_total( $total );
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_total_ex_tax( $total_ex_tax ) {
		_deprecated_function( __METHOD__, '1.2', __CLASS__ .'::get_formatted_total_ex_tax( $total_ex_tax )' );
		return self::get_formatted_total_ex_tax( $total_ex_tax );
	}

	/**
	 * Displays each cart tax in a subscription string and calculates the sign-up fee taxes (if any)
	 * to display in the string.
	 *
	 * @since 1.2
	 */
	public static function get_formatted_taxes( $formatted_taxes, $cart ) {
		_deprecated_function( __METHOD__, '1.4.9', __CLASS__ .'::get_recurring_tax_totals( $total_ex_tax )' );

		if ( self::cart_contains_subscription() ) {

			$recurring_taxes = self::get_recurring_taxes();

			foreach ( $formatted_taxes as $tax_id => $tax_amount ) {
				$formatted_taxes[ $tax_id ] = self::get_cart_subscription_string( $tax_amount, $recurring_taxes[ $tax_id ] );
			}

			// Add any recurring tax not already handled - when a subscription has a free trial and a sign-up fee, we get a recurring shipping tax with no initial shipping tax
			foreach ( $recurring_taxes as $tax_id => $tax_amount ) {
				if ( ! array_key_exists( $tax_id, $formatted_taxes ) ) {
					$formatted_taxes[ $tax_id ] = self::get_cart_subscription_string( '', $tax_amount );
				}
			}
		}

		return $formatted_taxes;
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal.
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @deprecated 2.0
	 * @since 1.3
	 */
	public static function cart_contains_subscription_renewal( $role = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_cart_contains_renewal( $role )' );
		return wcs_cart_contains_renewal( $role );
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal.
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @deprecated 2.0
	 * @since 1.4
	 */
	public static function cart_contains_failed_renewal_order_payment() {
		_deprecated_function( __METHOD__, '2.0', 'wcs_cart_contains_failed_renewal_order_payment()' );
		return wcs_cart_contains_failed_renewal_order_payment();
	}


	/**
	 * Restore renewal flag when cart is reset and modify Product object with
	 * renewal order related info
	 *
	 * @since 1.3
	 */
	public static function get_cart_item_from_session( $session_data, $values, $key ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_cart_item_from_session( $session_data, $values, $key )' );
	}

	/**
	 * For subscription renewal via cart, use original order discount
	 *
	 * @since 1.3
	 */
	public static function before_calculate_totals( $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::set_renewal_discounts( $cart )' );
	}

	/**
	 * For subscription renewal via cart, previously adjust item price by original order discount
	 *
	 * No longer required as of 1.3.5 as totals are calculated correctly internally.
	 *
	 * @since 1.3
	 */
	public static function get_discounted_price_for_renewal( $price, $values, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_discounted_price_for_renewal( $price, $values, $cart )' );
	}

	/**
	 * Returns a string with the cart discount and subscription period.
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_formatted_discounts_before_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount;
	}

	/**
	 * Gets the order discount amount - these are applied after tax
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_formatted_discounts_after_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount;
	}

	/**
	 * Returns an individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $discount_html String of the coupon's discount amount
	 * @param string $coupon WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.4.6
	 * @deprecated 2.0
	 */
	public static function cart_coupon_discount_amount_html( $discount_html, $coupon ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount_html;
	}

	/**
	 * Returns individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $discount_html String of the coupon's discount amount
	 * @param string $coupon WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.4.6
	 * @deprecated 2.0
	 */
	public static function cart_totals_fee_html( $cart_totals_fee_html, $fee ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_totals_fee_html;
	}

	/**
	 * Includes the sign-up fee total in the cart total (after calculation).
	 *
	 * @since 1.5.10
	 * @return string formatted price
	 * @deprecated 2.0
	 */
	public static function get_formatted_cart_total( $cart_contents_total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_contents_total;
	}

	/**
	 * Includes the sign-up fee subtotal in the subtotal displayed in the cart.
	 *
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_subtotal;
	}

	/**
	 * Returns an array of taxes merged by code, formatted with recurring amount ready for output.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.3.5
	 * @deprecated 2.0
	 */
	public static function get_recurring_tax_totals( $tax_totals, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return apply_filters( 'woocommerce_cart_recurring_tax_totals', $tax_totals, $cart );
	}

	/**
	 * Returns a string of the sum of all taxes in the cart for initial payment and
	 * recurring amount.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.4.10
	 * @deprecated 2.0
	 */
	public static function get_taxes_total_html( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_formatted_total( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_formatted_total_ex_tax( $total_ex_tax ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total_ex_tax;
	}

	/**
	 * Returns an array of the recurring total fields
	 *
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_recurring_totals_fields() {
		_deprecated_function( __METHOD__, '2.0', 'recurring total values stored in WC()->cart->recurring_carts' );
		return array();
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single period for the entire cart.
	 *
	 * @since 1.0
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_period() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['data']->subscription_period ) ) {
					$period = $cart_item['data']->subscription_period;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$period = WC_Subscriptions_Product::get_period( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_period', $period );
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single interval for the entire cart.
	 *
	 * @since 1.0
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_interval() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		foreach ( WC()->cart->cart_contents as $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$interval = WC_Subscriptions_Product::get_interval( $cart_item['data'] );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_interval', $interval );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single length for the entire cart.
	 *
	 * @since 1.1
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_length() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$length = 0;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['data']->subscription_length ) ) {
					$length = $cart_item['data']->subscription_length;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$length = WC_Subscriptions_Product::get_length( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_length', $length );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single trial length for the entire cart.
	 *
	 * @since 1.1
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_trial_length() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$trial_length = 0;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['data']->subscription_trial_length ) ) {
					$trial_length = $cart_item['data']->subscription_trial_length;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$trial_length = WC_Subscriptions_Product::get_trial_length( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_length', $trial_length );
	}

	/**
	 * Gets the subscription trial period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single trial period for the entire cart.
	 *
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_trial_period() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$trial_period = '';

		// Get the original trial period
		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['data']->subscription_trial_period ) ) {
					$trial_period = $cart_item['data']->subscription_trial_period;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$trial_period = WC_Subscriptions_Product::get_trial_period( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_period', $trial_period );
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return float price
	 * @deprecated 2.0
	 */
	public static function get_recurring_cart_contents_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			if ( ! $cart->prices_include_tax ) {
				$recurring_total += $cart->cart_contents_total;
			} else {
				$recurring_total += $cart->cart_contents_total + $cart->tax_total;
			}
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring item subtotal amount less tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_subtotal_ex_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->subtotal_ex_tax;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring item subtotal amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_subtotal() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->subtotal;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring cart discount amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_discount_cart() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_cart;
		}

		return $recurring_total;
	}

	/**
	 * Returns the cart discount tax amount for WC 2.3 and newer
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double
	 * @since 2.0
	 */
	public static function get_recurring_discount_cart_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_cart_tax;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring discount amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_discount_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the amount of shipping tax that is recurring. As shipping only applies
	 * to recurring payments, and only 1 subscription can be purchased at a time,
	 * this is equal to @see WC_Cart::$shipping_tax_total
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring shipping tax amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_tax_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->shipping_tax_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the recurring shipping price . As shipping only applies to recurring
	 * payments, and only 1 subscription can be purchased at a time, this is
	 * equal to @see WC_Cart::shipping_total
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring shipping amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->shipping_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns an array of taxes on an order with their recurring totals.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.2
	 */
	public static function get_recurring_taxes() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$taxes = array();

		$recurring_fees = array();

		foreach ( WC()->cart->recurring_carts as $cart ) {
			foreach ( array_keys( $cart->taxes + $cart->shipping_taxes ) as $key ) {
				$taxes[ $key ] = ( isset( $cart->shipping_taxes[ $key ] ) ? $cart->shipping_taxes[ $key ] : 0 ) + ( isset( $cart->taxes[ $key ] ) ? $cart->taxes[ $key ] : 0 );
			}
		}

		return $taxes;
	}

	/**
	 * Returns an array of recurring fees.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return array Array of fee_id => fee_details for items in the cart
	 * @since 1.4.9
	 */
	public static function get_recurring_fees() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_fees = array();

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_fees = array_merge( $recurring_fees, $cart->get_fees() );
		}

		return $recurring_fees;
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring tax amount tax for items in the cart (maybe not including compound taxes)
	 * @since 1.2
	 */
	public static function get_recurring_taxes_total( $compound = true ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			foreach ( $cart->taxes as $key => $tax ) {
				if ( ! $compound && WC_Tax::is_compound( $key ) ) { continue; }
				$recurring_total += $tax;
			}
			foreach ( $cart->shipping_taxes as $key => $tax ) {
				if ( ! $compound && WC_Tax::is_compound( $key ) ) { continue; }
				$recurring_total += $tax;
			}
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total tax on an order that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring tax amount tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->tax_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total before tax on an order that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring amount less tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total_ex_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return self::get_recurring_total() - self::get_recurring_total_tax() - self::get_recurring_shipping_tax_total();
	}

	/**
	 * Returns the price per period for a subscription in an order.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->get_total();
		}

		return $recurring_total;
	}

	/**
	 * Calculate the total amount of recurring shipping needed.  Removes any item from the calculation that
	 * is not a subscription and calculates the totals.
	 *
	 * @since 1.5
	 * @deprecated 2.0
	 */
	public static function calculate_recurring_shipping() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total = $cart->shipping_total;
		}

		return $recurring_total;
	}

	/**
	 * Creates a string representation of the subscription period/term for each item in the cart
	 *
	 * @param string $initial_amount The initial amount to be displayed for the subscription as passed through the @see woocommerce_price() function.
	 * @param float $recurring_amount The price to display in the subscription.
	 * @param array $args (optional) Flags to customise  to display the trial and length of the subscription. Default to false - don't display.
	 * @since 1.0
	 * @deprecated 2.0
	 */
	public static function get_cart_subscription_string( $initial_amount, $recurring_amount, $args = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		if ( ! is_array( $args ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.4', 'Third parameter is now an array of name => value pairs. Use array( "include_lengths" => true ) instead.' );
			$args = array(
				'include_lengths' => $args,
			);
		}

		$args = wp_parse_args( $args, array(
				'include_lengths' => false,
				'include_trial'   => true,
			)
		);

		$subscription_details = array(
			'initial_amount'        => $initial_amount,
			'initial_description'   => __( 'now', 'woocommerce-subscriptions' ),
			'recurring_amount'      => $recurring_amount,
			'subscription_interval' => self::get_cart_subscription_interval(),
			'subscription_period'   => self::get_cart_subscription_period(),
			'trial_length'          => self::get_cart_subscription_trial_length(),
			'trial_period'          => self::get_cart_subscription_trial_period(),
		);

		$is_one_payment = ( self::get_cart_subscription_length() > 0 && self::get_cart_subscription_length() == self::get_cart_subscription_interval() ) ? true : false;

		// Override defaults when subscription is for one billing period
		if ( $is_one_payment ) {

			$subscription_details['subscription_length'] = self::get_cart_subscription_length();

		} else {

			if ( true === $args['include_lengths'] ) {
				$subscription_details['subscription_length'] = self::get_cart_subscription_length();
			}

			if ( false === $args['include_trial'] ) {
				$subscription_details['trial_length'] = 0;
			}
		}

		$initial_amount_string   = ( is_numeric( $subscription_details['initial_amount'] ) ) ? woocommerce_price( $subscription_details['initial_amount'] ) : $subscription_details['initial_amount'];
		$recurring_amount_string = ( is_numeric( $subscription_details['recurring_amount'] ) ) ? woocommerce_price( $subscription_details['recurring_amount'] ) : $subscription_details['recurring_amount'];

		// Don't show up front fees when there is no trial period and no sign up fee and they are the same as the recurring amount
		if ( self::get_cart_subscription_trial_length() == 0 && self::get_cart_subscription_sign_up_fee() == 0 && $initial_amount_string == $recurring_amount_string ) {
			$subscription_details['initial_amount'] = '';
		} elseif ( wc_price( 0 ) == $initial_amount_string && false === $is_one_payment && self::get_cart_subscription_trial_length() > 0 ) { // don't show $0.00 initial amount (i.e. a free trial with no non-subscription products in the cart) unless the recurring period is the same as the billing period
			$subscription_details['initial_amount'] = '';
		}

		// Include details of a synced subscription in the cart
		if ( $synchronised_cart_item = WC_Subscriptions_Synchroniser::cart_contains_synced_subscription() ) {
			$subscription_details += array(
				'is_synced'                => true,
				'synchronised_payment_day' => WC_Subscriptions_Synchroniser::get_products_payment_day( $synchronised_cart_item['data'] ),
			);
		}

		$subscription_details = apply_filters( 'woocommerce_cart_subscription_string_details', $subscription_details, $args );

		$subscription_string = wcs_price_string( $subscription_details );

		return $subscription_string;
	}

	/**
	 * Uses the a subscription's combined price total calculated by WooCommerce to determine the
	 * total price that should be charged per period.
	 *
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function set_calculated_total( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.0
	 */
	public static function get_cart_from_session() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Store the sign-up fee cart values in the session
	 *
	 * @since 1.0
	 */
	public static function set_session() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Reset the sign-up fee fields in the current session
	 *
	 * @since 1.0
	 */
	public static function reset() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Returns a cart item's product ID. For a variation, this will be a variation ID, for a simple product,
	 * it will be the product's ID.
	 *
	 * @since 1.5
	 */
	public static function get_items_product_id( $cart_item ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_canonical_product_id( $cart_item )' );
		return wcs_get_canonical_product_id( $cart_item );
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @param mixed $code
	 * @param mixed $amount
	 * @return void
	 */
	public static function increase_coupon_discount_amount( $code, $amount ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions_Coupon::increase_coupon_discount_amount( WC()->cart, $code, $amount )' );

		if ( empty( WC()->cart->coupon_discount_amounts[ $code ] ) ) {
			WC()->cart->coupon_discount_amounts[ $code ] = 0;
		}

		if ( 'recurring_total' != self::$calculation_type ) {
			WC()->cart->coupon_discount_amounts[ $code ] += $amount;
		}
	}

	/**
	 * One time shipping can null the need for shipping needs. WooCommerce treats that as no need to ship, therefore it will call
	 * WC()->shipping->reset() on it, which will wipe the preferences saved. That can cause the chosen shipping method for the one
	 * time shipping feature to be lost, and the first default to be applied instead. To counter that, we save the chosen shipping
	 * method to a key that's not going to get wiped by WC's method, and then later restore it.
	 */
	public static function maybe_restore_chosen_shipping_method() {
		$onetime_shipping = WC()->session->get( 'ost_shipping_methods', false );
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		if ( $onetime_shipping && empty( $chosen_shipping_methods ) ) {
			WC()->session->set( 'chosen_shipping_methods', $onetime_shipping );
			unset( WC()->session->ost_shipping_methods );
		}
	}
}
WC_Subscriptions_Cart::init();

