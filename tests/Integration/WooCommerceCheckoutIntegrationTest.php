<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use ReflectionClass;
use WC_Checkout;
use WP_Error;
use WP_UnitTestCase;

final class WooCommerceCheckoutIntegrationTest extends WP_UnitTestCase {
	public function test_checkout_process_does_not_parse_the_complete_payload_early(): void {
		$checkout   = new class() extends WC_Checkout {
			public int $posted_data_calls = 0;

			public function get_posted_data(): array {
				++$this->posted_data_calls;

				return [ 'billing_phone' => '09351234567' ];
			}
		};
		$reflection = new ReflectionClass( WC_Checkout::class );
		$instance   = $reflection->getProperty( 'instance' );
		$original   = $instance->getValue();
		$instance->setValue( null, $checkout );

		try {
			do_action( 'woocommerce_checkout_process' );

			self::assertSame( 0, $checkout->posted_data_calls );
		} finally {
			$instance->setValue( null, $original );
		}
	}

	public function test_phone_validation_uses_woocommerce_validated_data(): void {
		$errors = new WP_Error();

		do_action(
			'woocommerce_after_checkout_validation',
			[ 'billing_phone' => 'invalid-mobile' ],
			$errors
		);

		self::assertSame(
			[ 'pinova_billing_phone_invalid' ],
			$errors->get_error_codes()
		);
	}

	public function test_phone_validation_accepts_a_normalized_mobile(): void {
		$errors = new WP_Error();

		do_action(
			'woocommerce_after_checkout_validation',
			[ 'billing_phone' => '۰۹۳۵۱۲۳۴۵۶۷' ],
			$errors
		);

		self::assertFalse( $errors->has_errors() );
	}

	public function test_phone_validation_rejects_non_string_input_without_throwing(): void {
		$errors = new WP_Error();

		do_action(
			'woocommerce_after_checkout_validation',
			[ 'billing_phone' => [ '09351234567' ] ],
			$errors
		);

		self::assertSame(
			[ 'pinova_billing_phone_required' ],
			$errors->get_error_codes()
		);
	}

	public function test_legacy_email_callbacks_are_removed_without_changing_guest_intent(): void {
		add_action( 'woocommerce_checkout_create_order', 'mihanwp_set_customer_email', 10, 2 );
		add_filter( 'woocommerce_checkout_posted_data', 'mihanwp_remove_register_alert', 10 );
		$adapter = new \Pinova\Integrations\Woocommerce\LegacyCheckoutCompatibility();
		$adapter->remove_legacy_callbacks();
		self::assertFalse( has_action( 'woocommerce_checkout_create_order', 'mihanwp_set_customer_email' ) );
		self::assertFalse( has_filter( 'woocommerce_checkout_posted_data', 'mihanwp_remove_register_alert' ) );
		$data = [ 'createaccount' => false, 'billing_email' => '' ];
		self::assertSame( $data, apply_filters( 'woocommerce_checkout_posted_data', $data ) );
		remove_filter( 'woocommerce_billing_fields', [ $adapter, 'optional_email' ] );
		remove_action( 'after_setup_theme', [ $adapter, 'remove_legacy_callbacks' ], PHP_INT_MAX );
		remove_action( 'woocommerce_checkout_create_order', [ $adapter, 'check_owner' ], PHP_INT_MAX );
	}

	public function test_checkout_guard_accepts_owner_and_guest_without_email(): void {
		$adapter = new \Pinova\Integrations\Woocommerce\LegacyCheckoutCompatibility();
		$id = self::factory()->user->create( [ 'role' => 'customer' ] );
		wp_set_current_user( $id );
		$order = new \WC_Order();
		$order->set_customer_id( $id );
		$order->set_billing_email( 'different@example.test' );
		$adapter->check_owner( $order );
		wp_set_current_user( 0 );
		$order->set_customer_id( 0 );
		$order->set_billing_email( '' );
		$adapter->check_owner( $order );
		self::assertSame( '', $order->get_billing_email() );
		remove_filter( 'woocommerce_billing_fields', [ $adapter, 'optional_email' ] );
		remove_action( 'after_setup_theme', [ $adapter, 'remove_legacy_callbacks' ], PHP_INT_MAX );
		remove_action( 'woocommerce_checkout_create_order', [ $adapter, 'check_owner' ], PHP_INT_MAX );
	}

	public function test_checkout_guard_rejects_order_reassigned_by_contact_email(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$order = new \WC_Order();
		$order->set_customer_id( self::factory()->user->create() );
		$adapter = new \Pinova\Integrations\Woocommerce\LegacyCheckoutCompatibility();
		try {
			$this->expectException( \Exception::class );
			$adapter->check_owner( $order );
		} finally {
			wp_set_current_user( 0 );
			remove_filter( 'woocommerce_billing_fields', [ $adapter, 'optional_email' ] );
		remove_action( 'after_setup_theme', [ $adapter, 'remove_legacy_callbacks' ], PHP_INT_MAX );
			remove_action( 'woocommerce_checkout_create_order', [ $adapter, 'check_owner' ], PHP_INT_MAX );
		}
	}
}
