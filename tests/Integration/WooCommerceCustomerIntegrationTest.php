<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

final class WooCommerceCustomerIntegrationTest extends \WP_UnitTestCase {
	public function test_registration_uses_the_current_mobile_override_and_preserves_username(): void {
		$id = self::factory()->user->create( [ 'user_login' => '989121234567' ] );
		update_user_meta( $id, 'pinova_mobile', '09129876543' );
		do_action( 'pinova/user_registered', $id );
		self::assertSame( '09129876543', get_user_meta( $id, 'billing_phone', true ) );
		self::assertSame( '989121234567', get_userdata( $id )->user_login );
	}

	public function test_email_account_without_a_mobile_preserves_its_existing_billing_phone(): void {
		$id = self::factory()->user->create( [ 'user_login' => 'pinova_email_customer' ] );
		update_user_meta( $id, 'billing_phone', 'existing contact' );
		do_action( 'pinova/user_registered', $id );
		self::assertSame( 'existing contact', get_user_meta( $id, 'billing_phone', true ) );
	}
}
