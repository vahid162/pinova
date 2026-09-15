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
}
