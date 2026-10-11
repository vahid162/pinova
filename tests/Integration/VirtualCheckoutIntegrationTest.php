<?php

declare(strict_types=1);

namespace {
	/** Characterized legacy callback; intentionally shows the old empty-cart defect. */
	function pante_custom_override_checkout_fields( array $fields ): array {
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( $product && ! $product->is_virtual() ) {
				return $fields;
			}
		}
		foreach ( [ 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode' ] as $key ) {
			unset( $fields['billing'][ 'billing_' . $key ] );
		}
		unset( $fields['order']['order_comments'] );
		return $fields;
	}
}

namespace Pinova\Tests\Integration {

	use Pinova\Admin\Settings;
	use Pinova\Install;
	use Pinova\Integrations\Woocommerce\VirtualCheckout;
	use Pinova\Logging\EventEvidence;
	use Pinova\Logging\LogRepository;
	use Pinova\Logging\SettingsAudit;
	use WC_AJAX;
	use WC_Cart;
	use WC_Checkout;
	use WC_Customer;
	use WC_Coupon;
	use WC_Gateway_BACS;
	use WC_Payment_Gateway;
	use WC_Product_Simple;
	use WC_Product_Variable;
	use WC_Product_Variation;
	use WC_Session_Handler;
	use WP_Error;
	use WP_UnitTestCase;

	final class VirtualCheckoutIntegrationTest extends WP_UnitTestCase {

		private VirtualCheckout $policy;
		private array $original_gateways;
		private $original_cart;
		private $original_customer;
		private $original_session;
		private array $original_post;
		private $original_checkout;
		private array $orders = [];

		public static function set_up_before_class(): void {
			parent::set_up_before_class();
			Install::create_tables();
		}

		public function set_up(): void {
			parent::set_up();
			global $wp_filter;
			foreach ( $wp_filter['woocommerce_checkout_fields']->callbacks[10] as $entry ) {
				if ( is_array( $entry['function'] ) && $entry['function'][0] instanceof VirtualCheckout ) {
					$this->policy = $entry['function'][0];
					break;
				}
			}
			self::assertTrue( isset( $this->policy ), 'WooCommerce bootstrap must register the virtual policy.' );
			$this->original_cart     = WC()->cart;
			$this->original_customer = WC()->customer;
			$this->original_session  = WC()->session;
			$this->original_post     = $_POST;
			$this->original_checkout = ( new \ReflectionProperty( WC_Checkout::class, 'instance' ) )->getValue();
			$this->original_gateways = WC()->payment_gateways()->payment_gateways;
			WC()->session            = new WC_Session_Handler();
			WC()->customer           = new WC_Customer();
			WC()->cart               = new WC_Cart();
			// Materialize the native session once before manipulating a test-owned cart.
			WC()->cart->get_cart();
			$gateway                       = new WC_Gateway_BACS();
			$gateway->enabled              = 'yes';
			WC()->payment_gateways()->payment_gateways = [ 'bacs' => $gateway ];
			wp_set_current_user( 0 );
			update_option( 'woocommerce_calc_taxes', 'no' );
			update_option( 'woocommerce_tax_based_on', 'base' );
			update_option( 'woocommerce_ship_to_destination', 'shipping' );
			update_option( 'woocommerce_enable_guest_checkout', 'yes' );
			update_option( 'woocommerce_default_country', 'US:CA' );
			update_option( 'woocommerce_checkout_page_id', 0 );
			delete_option( 'pinova_general' );
			$this->policy->checkout_fields( $this->fields() );
		}

		public function tear_down(): void {
			foreach ( $this->orders as $id ) {
				$order = wc_get_order( $id );
				if ( $order ) {
					$order->delete( true );
				}
			}
			remove_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields', 10 );
			WC()->cart     = $this->original_cart;
			WC()->customer = $this->original_customer;
			WC()->session  = $this->original_session;
			WC()->payment_gateways()->payment_gateways = $this->original_gateways;
			$_POST = $this->original_post;
			( new \ReflectionProperty( WC_Checkout::class, 'instance' ) )->setValue( null, $this->original_checkout );
			wc_clear_notices();
			wp_set_current_user( 0 );
			parent::tear_down();
		}

		public function test_setting_is_default_off_strict_and_uses_existing_settings_ui(): void {
			self::assertFalse( VirtualCheckout::enabled() );
			$fields = ( new Settings() )->get_fields()['pinova_general'];
			$field  = array_values( array_filter( $fields, static fn( array $entry ): bool => VirtualCheckout::SETTING === ( $entry['id'] ?? '' ) ) )[0];
			self::assertFalse( $field['default'] );
			self::assertSame( 'checkbox', $field['type'] );
			self::assertSame( [ VirtualCheckout::class, 'sanitize_enabled' ], $field['sanitize_callback'] );
			foreach ( [ true, 1, '1' ] as $valid ) {
				self::assertTrue( VirtualCheckout::sanitize_enabled( $valid ) );
			}
			foreach ( [ false, 0, '0', 'yes', 'true', [], [ '1' ], new \stdClass(), null ] as $invalid ) {
				self::assertFalse( VirtualCheckout::sanitize_enabled( $invalid ) );
			}
			self::assertSame( [ VirtualCheckout::SETTING ], SettingsAudit::changed_keys( 'pinova_general', [], [ VirtualCheckout::SETTING => '1', 'unknown_secret' => 'PRIVATE_VALUE' ] ) );
		}

		public function test_disabled_virtual_cart_keeps_every_normal_field(): void {
			$this->add_product( true );
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
		}

		public function test_enabled_virtual_cart_keeps_names_contact_and_extension_fields(): void {
			$this->enable();
			$this->add_product( true );
			$fields = $this->policy->checkout_fields( $this->fields() );
			self::assertArrayNotHasKey( 'billing_address_1', $fields['billing'] );
			self::assertArrayNotHasKey( 'billing_country', $fields['billing'] );
			self::assertArrayNotHasKey( 'order_comments', $fields['order'] );
			foreach ( [ 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_extension' ] as $key ) {
				self::assertSame( $this->fields()['billing'][ $key ], $fields['billing'][ $key ] );
			}
			self::assertSame( $this->fields()['order']['extension_note'], $fields['order']['extension_note'] );
		}

		public function test_physical_mixed_and_empty_carts_keep_normal_fields(): void {
			$this->enable();
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			$this->add_product( false );
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			$this->add_product( true );
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
		}

		public function test_variation_uses_its_own_virtual_state_and_verified_parent(): void {
			$this->enable();
			$parent = new WC_Product_Variable();
			$parent->set_name( 'Synthetic variable product' );
			$parent->save();
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( '10' );
			$variation->set_virtual( true );
			$variation->set_status( 'publish' );
			$variation->save();
			WC()->cart->cart_contents = [ 'variation' => [ 'product_id' => $parent->get_id(), 'variation_id' => $variation->get_id(), 'quantity' => 1, 'data' => $variation ] ];
			self::assertArrayNotHasKey( 'billing_address_1', $this->policy->checkout_fields( $this->fields() )['billing'] );
			$variation->set_virtual( false );
			$variation->save();
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			$variation->set_virtual( true );
			$variation->save();
			WC()->cart->cart_contents['variation']['product_id'] = $parent->get_id() + 999;
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
		}

		public function test_uncertain_products_identifiers_and_quantities_fail_closed(): void {
			$this->enable();
			$key      = $this->add_product( true );
			$original = WC()->cart->cart_contents[ $key ];
			foreach ( [
				[ 'data' => null ], [ 'data' => new \stdClass() ],
				[ 'product_id' => 0 ], [ 'product_id' => 'invalid' ],
				[ 'variation_id' => 'invalid' ], [ 'product_id' => 999999999 ],
				[ 'quantity' => 0 ], [ 'quantity' => -1 ], [ 'quantity' => 'invalid' ],
				[ 'quantity' => INF ], [ 'quantity' => NAN ],
			] as $override ) {
				WC()->cart->cart_contents[ $key ] = array_replace( $original, $override );
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			}
		}

		public function test_customer_location_taxes_keep_full_fields_but_base_taxes_allow_reduction(): void {
			$this->enable();
			$this->add_product( true );
			update_option( 'woocommerce_calc_taxes', 'yes' );
			foreach ( [ 'billing', 'shipping', 'unknown' ] as $location ) {
				update_option( 'woocommerce_tax_based_on', $location );
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			}
			update_option( 'woocommerce_tax_based_on', 'base' );
			self::assertArrayNotHasKey( 'billing_country', $this->policy->checkout_fields( $this->fields() )['billing'] );
		}

		public function test_unknown_gateway_preserves_fields_and_contract_retains_required_definitions(): void {
			$this->enable();
			$this->add_product( true );
			$gateway = $this->gateway();
			WC()->payment_gateways()->payment_gateways['synthetic'] = $gateway;
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			$contract = static fn( $required, WC_Payment_Gateway $candidate ) => $candidate === $gateway ? [ 'billing_country', 'billing_postcode', 'order_comments' ] : $required;
			add_filter( 'pinova/virtual_checkout/gateway_required_fields', $contract, 10, 2 );
			try {
				$fields = $this->policy->checkout_fields( $this->fields() );
				foreach ( [ 'billing_country', 'billing_postcode' ] as $key ) {
					self::assertSame( $this->fields()['billing'][ $key ], $fields['billing'][ $key ] );
				}
				self::assertArrayNotHasKey( 'billing_address_1', $fields['billing'] );
				self::assertSame( $this->fields()['order']['order_comments'], $fields['order']['order_comments'] );
			} finally {
				remove_filter( 'pinova/virtual_checkout/gateway_required_fields', $contract, 10 );
			}
			foreach ( [ 'yes', [ 'unknown_field' ], [ 123 ], [ [] ] ] as $malformed ) {
				$bad_contract = static fn() => $malformed;
				add_filter( 'pinova/virtual_checkout/gateway_required_fields', $bad_contract );
				try {
					self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
				} finally {
					remove_filter( 'pinova/virtual_checkout/gateway_required_fields', $bad_contract );
				}
			}
		}

		public function test_no_enabled_gateway_is_uncertain_but_calculated_free_cart_needs_none(): void {
			$this->enable();
			$key = $this->add_product( true );
			WC()->payment_gateways()->payment_gateways = [];
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			$product = WC()->cart->cart_contents[ $key ]['data'];
			$product->set_regular_price( '0' );
			$product->set_price( '0' );
			$product->save();
			WC()->cart->calculate_totals();
			self::assertFalse( WC()->cart->needs_payment() );
			self::assertArrayNotHasKey( 'billing_address_1', $this->policy->checkout_fields( $this->fields() )['billing'] );
			WC()->payment_gateways()->payment_gateways = [ 'synthetic' => $this->gateway() ];
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
		}

		public function test_coupon_removal_keeps_unknown_gateway_address_inputs_available_and_editable(): void {
			$this->enable();
			$this->add_product( true );
			WC()->customer = new WC_Customer( 0, true );
			WC()->payment_gateways()->payment_gateways = [ 'synthetic' => $this->gateway() ];
			update_option( 'woocommerce_enable_coupons', 'yes' );
			$coupon = new WC_Coupon();
			$coupon->set_code( 'pinova-virtual-free-transition' );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( 100 );
			$coupon->save();
			try {
				self::assertTrue( WC()->cart->apply_coupon( $coupon->get_code() ) );
				WC()->cart->calculate_totals();
				self::assertFalse( WC()->cart->needs_payment() );
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
				$checkout = $this->checkout();
				self::assertArrayHasKey( 'billing_address_1', $checkout->get_checkout_fields( 'billing' ) );
				self::assertTrue( WC()->cart->remove_coupon( $coupon->get_code() ) );
				WC()->cart->calculate_totals();
				self::assertTrue( WC()->cart->needs_payment() );
				$response = $this->update_order_review_ajax( [ 'payment_method' => 'synthetic', 'country' => 'GB', 'address' => 'Editable after coupon removal' ] );
				self::assertSame( 'success', $response['result'] );
				self::assertArrayNotHasKey( '.woocommerce-billing-fields', $response['fragments'], 'Native AJAX does not rebuild the original address form.' );
				self::assertSame( 'Editable after coupon removal', WC()->customer->get_billing_address_1( 'edit' ) );
				$_POST = [ 'billing_country' => 'GB', 'billing_address_1' => 'Submitted after coupon removal', 'payment_method' => 'synthetic' ];
				$data = $checkout->get_posted_data();
				self::assertSame( 'Submitted after coupon removal', $data['billing_address_1'] );
				self::assertSame( 'GB', $data['billing_country'] );
			} finally {
				$coupon->delete( true );
			}
		}

		public function test_later_filter_restored_fields_keep_native_posted_values_and_order_values(): void {
			$this->enable();
			$this->add_product( true );
			$restore = static function ( array $fields ): array {
				$fields['billing']['billing_company'] = [ 'type' => 'text', 'label' => 'Restored company', 'required' => true ];
				return $fields;
			};
			add_filter( 'woocommerce_checkout_fields', $restore, 20 );
			try {
				$_POST = [ 'billing_company' => 'Extension restored company', 'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Customer', 'billing_phone' => '09351234567', 'billing_email' => 'restored@example.test', 'payment_method' => 'bacs' ];
				$checkout = $this->checkout();
				$data = $checkout->get_posted_data();
				self::assertSame( 'Extension restored company', $data['billing_company'] );
				$order_id = $checkout->create_order( $data );
				self::assertFalse( is_wp_error( $order_id ) );
				$this->orders[] = $order_id;
				self::assertSame( 'Extension restored company', wc_get_order( $order_id )->get_billing_company() );
			} finally {
				remove_filter( 'woocommerce_checkout_fields', $restore, 20 );
			}
		}

		public function test_native_phone_email_and_gateway_validation_remain_effective(): void {
			$this->enable();
			$this->add_product( true );
			$gateway = $this->gateway();
			WC()->payment_gateways()->payment_gateways = [ 'synthetic' => $gateway ];
			$contract = static fn() => [];
			add_filter( 'pinova/virtual_checkout/gateway_required_fields', $contract );
			try {
				$_POST = [ 'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Customer', 'billing_phone' => '', 'billing_email' => 'invalid', 'payment_method' => 'synthetic' ];
				$checkout = $this->checkout();
				$data     = $checkout->get_posted_data();
				$errors   = new WP_Error();
				$checkout->validate_data( $data, $errors );
				self::assertContains( 'billing_phone_required', $errors->get_error_codes() );
				self::assertContains( 'billing_email_validation', $errors->get_error_codes() );
				self::assertSame( 1, $gateway->validation_calls );
				self::assertSame( 1, wc_notice_count( 'error' ), 'Native gateway validation must retain its rejection.' );
			} finally {
				remove_filter( 'pinova/virtual_checkout/gateway_required_fields', $contract );
			}
		}

		public function test_legacy_callback_is_replaced_only_at_verified_priority_and_other_filters_keep_order(): void {
			$calls  = [];
			$before = static function ( array $fields ) use ( &$calls ): array { $calls[] = 'before'; return $fields; };
			$after  = static function ( array $fields ) use ( &$calls ): array { $calls[] = 'after'; return $fields; };
			add_filter( 'woocommerce_checkout_fields', $before, 10 );
			add_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields', 10 );
			add_filter( 'woocommerce_checkout_fields', $after, 10 );
			try {
				$this->policy->replace_legacy_callback();
				self::assertSame( 10, has_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields' ) );
				self::assertArrayNotHasKey( 'billing_country', apply_filters( 'woocommerce_checkout_fields', $this->fields() )['billing'], 'Characterize legacy empty-cart removal while disabled.' );
				$this->enable();
				$this->policy->replace_legacy_callback();
				self::assertFalse( has_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields' ) );
				$calls = [];
				self::assertArrayHasKey( 'billing_country', apply_filters( 'woocommerce_checkout_fields', $this->fields() )['billing'] );
				self::assertSame( [ 'before', 'after' ], $calls );
				self::assertSame( 10, has_filter( 'woocommerce_checkout_fields', $before ) );
				self::assertSame( 10, has_filter( 'woocommerce_checkout_fields', $after ) );
				// A new request registers the theme callback again; disabled leaves its native order intact.
				update_option( 'pinova_general', [ VirtualCheckout::SETTING => '0' ] );
				add_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields', 10 );
				$this->policy->replace_legacy_callback();
				self::assertSame( 10, has_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields' ) );
			} finally {
				remove_filter( 'woocommerce_checkout_fields', $before, 10 );
				remove_filter( 'woocommerce_checkout_fields', $after, 10 );
			}
		}

		public function test_blocks_are_explicitly_unsupported_and_theme_absence_is_supported(): void {
			$this->enable();
			$this->add_product( true );
			self::assertFalse( has_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields' ) );
			self::assertArrayNotHasKey( 'billing_country', $this->policy->checkout_fields( $this->fields() )['billing'] );
			$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ] );
			update_option( 'woocommerce_checkout_page_id', $page );
			self::assertTrue( VirtualCheckout::is_block_checkout() );
			self::assertStringContainsString( 'Checkout Blocks', VirtualCheckout::description() );
			self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
		}

		public function test_native_ajax_and_checkout_preserve_stored_addresses_and_create_order_with_omitted_fields(): void {
			$this->enable();
			$this->add_product( true );
			$user = self::factory()->user->create( [ 'role' => 'customer', 'user_login' => 'pinova_virtual_customer', 'user_email' => 'virtual@example.test' ] );
			wp_set_current_user( $user );
			$customer = new WC_Customer( $user );
			$customer->set_billing_address_1( 'Stored billing street' );
			$customer->set_billing_city( 'Billing city' );
			$customer->set_billing_country( 'US' );
			$customer->set_billing_state( 'CA' );
			$customer->set_billing_postcode( '90210' );
			$customer->set_shipping_address_1( 'Different stored shipping street' );
			$customer->set_shipping_city( 'Shipping city' );
			$customer->set_shipping_country( 'US' );
			$customer->set_shipping_state( 'NY' );
			$customer->set_shipping_postcode( '10001' );
			$customer->save();
			WC()->customer = new WC_Customer( $user, true );
			WC()->customer->save();
			update_option( 'woocommerce_ship_to_destination', 'billing' );
			$checkout = $this->checkout();
			$response = $this->update_order_review_ajax();
			self::assertSame( 'success', $response['result'] );
			self::assertArrayHasKey( '.woocommerce-checkout-review-order-table', $response['fragments'] );
			self::assertSame( 'Stored billing street', WC()->customer->get_billing_address_1( 'edit' ) );
			self::assertSame( 'Different stored shipping street', WC()->customer->get_shipping_address_1( 'edit' ) );
			self::assertSame( 'US', WC()->customer->get_billing_country( 'edit' ) );
			self::assertSame( 'NY', WC()->customer->get_shipping_state( 'edit' ) );
			$session_customer = WC()->session->get( 'customer' );
			self::assertSame( 'Stored billing street', $session_customer['address_1'] );
			self::assertSame( 'Different stored shipping street', $session_customer['shipping_address_1'] );
			$_POST = [
				'woocommerce-process-checkout-nonce' => 'synthetic-form-presence',
				'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Customer',
				'billing_phone' => '09351234567', 'billing_email' => 'virtual@example.test',
				'payment_method' => 'bacs', 'billing_address_1' => 'Malicious omitted billing value',
				'shipping_address_1' => 'Malicious omitted shipping value',
			];
			$data = $checkout->get_posted_data();
			self::assertArrayNotHasKey( 'billing_address_1', $data );
			self::assertArrayNotHasKey( 'shipping_address_1', $data );
			$checkout->sync_customer( $data );
			$errors = new WP_Error();
			$checkout->validate_data( $data, $errors );
			self::assertFalse( $errors->has_errors(), implode( ', ', $errors->get_error_codes() ) );
			$checkout->process_native_customer( $data );
			$reloaded = new WC_Customer( $user );
			self::assertSame( 'Stored billing street', $reloaded->get_billing_address_1( 'edit' ) );
			self::assertSame( 'Different stored shipping street', $reloaded->get_shipping_address_1( 'edit' ) );
			self::assertSame( '10001', $reloaded->get_shipping_postcode( 'edit' ) );
			self::assertSame( 'pinova_virtual_customer', get_userdata( $user )->user_login );
			$order_id = $checkout->create_order( $data );
			self::assertFalse( is_wp_error( $order_id ) );
			self::assertGreaterThan( 0, $order_id );
			$this->orders[] = $order_id;
			$order = wc_get_order( $order_id );
			self::assertSame( $user, $order->get_customer_id() );
			self::assertSame( 'Stored billing street', $order->get_billing_address_1() );
			self::assertSame( 'Different stored shipping street', $order->get_shipping_address_1() );
			self::assertSame( '09351234567', $order->get_billing_phone() );
			self::assertSame( 'virtual@example.test', $order->get_billing_email() );
			self::assertCount( 1, $order->get_items() );
		}

		public function test_extension_required_shipping_address_keeps_native_fields_posted_data_and_ajax_editable(): void {
			$this->enable();
			$this->add_product( true );
			WC()->customer = new WC_Customer( 0, true );
			add_filter( 'woocommerce_cart_needs_shipping_address', '__return_true' );
			try {
				self::assertTrue( WC()->cart->needs_shipping_address() );
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
				$_POST = [
					'ship_to_different_address' => '1', 'payment_method' => 'bacs',
					'billing_country' => 'US', 'billing_address_1' => 'Editable billing street',
					'shipping_country' => 'GB', 'shipping_address_1' => 'Editable shipping street',
					'shipping_city' => 'London', 'shipping_postcode' => 'SW1A 1AA',
				];
				$checkout = $this->checkout();
				$fields   = $checkout->get_checkout_fields();
				self::assertArrayHasKey( 'billing_address_1', $fields['billing'] );
				self::assertArrayHasKey( 'shipping_address_1', $fields['shipping'] );
				$data = $checkout->get_posted_data();
				self::assertSame( 'Editable billing street', $data['billing_address_1'] );
				self::assertSame( 'Editable shipping street', $data['shipping_address_1'] );
				self::assertSame( 'GB', $data['shipping_country'] );
				$response = $this->update_order_review_ajax( [
					'country' => 'US', 'address' => 'AJAX edited billing street',
					's_country' => 'GB', 's_address' => 'AJAX edited shipping street',
					's_city' => 'London', 's_postcode' => 'SW1A 1AA',
				] );
				self::assertSame( 'success', $response['result'] );
				self::assertSame( 'AJAX edited billing street', WC()->customer->get_billing_address_1( 'edit' ) );
				self::assertSame( 'AJAX edited shipping street', WC()->customer->get_shipping_address_1( 'edit' ) );
				self::assertSame( 'GB', WC()->customer->get_shipping_country( 'edit' ) );
			} finally {
				remove_filter( 'woocommerce_cart_needs_shipping_address', '__return_true' );
			}
		}

		public function test_ajax_keeps_a_country_restored_by_a_later_field_filter_editable(): void {
			$this->enable();
			$this->add_product( true );
			WC()->customer = new WC_Customer( 0, true );
			WC()->customer->set_billing_country( 'US' );
			WC()->customer->set_shipping_country( 'US' );
			WC()->customer->set_billing_address_1( 'Existing billing street' );
			WC()->customer->save();
			$restore = static function ( array $fields ): array {
				$fields['billing']['billing_country'] = [ 'type' => 'country', 'label' => 'Restored country', 'required' => true ];
				return $fields;
			};
			add_filter( 'woocommerce_checkout_fields', $restore, 20 );
			try {
				$this->checkout();
				$response = $this->update_order_review_ajax( [ 'country' => 'GB', 's_country' => 'FR' ] );
				self::assertSame( 'success', $response['result'] );
				self::assertSame( 'GB', WC()->customer->get_billing_country( 'edit' ) );
				self::assertSame( 'FR', WC()->customer->get_shipping_country( 'edit' ) );
				self::assertSame( 'Existing billing street', WC()->customer->get_billing_address_1( 'edit' ) );
			} finally {
				remove_filter( 'woocommerce_checkout_fields', $restore, 20 );
			}
		}

		public function test_thrown_gateway_contract_fails_closed_and_historical_failure_is_redacted(): void {
			global $wpdb;
			$this->enable();
			$this->add_product( true );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scope = %s', $wpdb->prefix . 'pinova_rate_limits', 'log_integration_failed' ) );
			LogRepository::delete_all();
			$broken = static function (): void { throw new \RuntimeException( 'PRIVATE_GATEWAY_SECRET' ); };
			add_filter( 'pinova/virtual_checkout/gateway_required_fields', $broken );
			try {
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
				self::assertSame( $this->fields(), $this->policy->checkout_fields( $this->fields() ) );
			} finally {
				remove_filter( 'pinova/virtual_checkout/gateway_required_fields', $broken );
			}
			$recorded = LogRepository::paginate();
			self::assertSame( 1, $recorded['total'], 'Repeated contract failures share the finite integration event budget.' );
			self::assertStringNotContainsString( 'PRIVATE_GATEWAY_SECRET', $recorded['rows'][0]['context'] );
			self::assertSame( 'woocommerce', EventEvidence::redact( $recorded['rows'][0] )['context']['operation'] );
			$row = EventEvidence::redact( [ 'event' => 'integration.operation_failed', 'context' => wp_json_encode( [ 'operation' => 'woocommerce', 'reason' => 'virtual_checkout_contract_failed', 'message' => 'PRIVATE_GATEWAY_SECRET', 'billing_email' => 'private@example.test' ] ) ] );
			self::assertSame( [ 'reason' => 'virtual_checkout_contract_failed', 'operation' => 'woocommerce' ], $row['context'] );
			self::assertStringNotContainsString( 'PRIVATE', wp_json_encode( $row ) );
			self::assertStringNotContainsString( 'private@example.test', wp_json_encode( $row ) );
		}

		/** Execute the native AJAX path and capture its normal WordPress termination. */
		private function update_order_review_ajax( array $overrides = [] ): array {
			$previous_request = $_REQUEST;
			$_POST = array_replace( [
				'security' => wp_create_nonce( 'update-order-review' ), 'payment_method' => 'bacs',
				'post_data' => 'billing_first_name=Synthetic&billing_last_name=Customer&billing_phone=09351234567&billing_email=virtual%40example.test',
				'country' => '', 'state' => '', 'postcode' => '', 'city' => '', 'address' => '', 'address_2' => '',
				's_country' => '', 's_state' => '', 's_postcode' => '', 's_city' => '', 's_address' => '', 's_address_2' => '',
				'has_full_address' => 'false',
			], $overrides );
			$_REQUEST = $_POST;
			$handler = static fn(): callable => static function ( $message ): void {
				if ( '' !== $message ) {
					throw new \WPAjaxDieStopException( (string) $message );
				}
				throw new \WPAjaxDieContinueException( '' );
			};
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', $handler );
			ob_start();
			try {
				WC_AJAX::update_order_review();
				self::fail( 'The native AJAX handler must terminate through wp_send_json().' );
			} catch ( \WPAjaxDieContinueException $exception ) {
				$response = json_decode( (string) ob_get_contents(), true );
				self::assertIsArray( $response );
				return $response;
			} finally {
				ob_end_clean();
				remove_filter( 'wp_die_ajax_handler', $handler );
				remove_filter( 'wp_doing_ajax', '__return_true' );
				$_REQUEST = $previous_request;
			}
		}

		private function enable(): void {
			update_option( 'pinova_general', [ VirtualCheckout::SETTING => '1', 'woocommerce_checkout_registration_required' => 'no' ] );
		}

		private function add_product( bool $virtual ): string {
			$product = new WC_Product_Simple();
			$product->set_name( 'Synthetic virtual checkout product' );
			$product->set_status( 'publish' );
			$product->set_regular_price( '10' );
			$product->set_virtual( $virtual );
			$product->save();
			$key = WC()->cart->add_to_cart( $product->get_id(), 1 );
			self::assertIsString( $key );
			return $key;
		}

		private function gateway(): WC_Payment_Gateway {
			return new class() extends WC_Payment_Gateway {
				public int $validation_calls = 0;
				public function __construct() { $this->id = 'synthetic'; $this->enabled = 'yes'; }
				public function validate_fields(): bool {
					++$this->validation_calls;
					wc_add_notice( 'Synthetic gateway rejection', 'error' );
					return false;
				}
			};
		}

		private function checkout(): WC_Checkout {
			$checkout = new class() extends WC_Checkout {
				public function validate_data( array &$data, WP_Error &$errors ): void { $this->validate_checkout( $data, $errors ); }
				public function sync_customer( array $data ): void { $this->update_session( $data ); }
				public function process_native_customer( array $data ): void { $this->process_customer( $data ); }
			};
			( new \ReflectionProperty( WC_Checkout::class, 'instance' ) )->setValue( null, $checkout );
			return $checkout;
		}

		private function fields(): array {
			$billing = [];
			foreach ( [ 'first_name', 'last_name', 'phone', 'email', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'extension' ] as $key ) {
				$billing[ 'billing_' . $key ] = [ 'label' => $key, 'required' => true, 'validate' => [ 'extension_preserved' ] ];
			}
			return [ 'billing' => $billing, 'shipping' => [ 'shipping_address_1' => [ 'required' => true ] ], 'order' => [ 'order_comments' => [ 'required' => false ], 'extension_note' => [ 'required' => true ] ] ];
		}
	}
}
