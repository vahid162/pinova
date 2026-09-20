<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Models\OTP;
use Pinova\Services\ChannelService;
use WP_UnitTestCase;

final class EmailPurposeIntegrationTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		OTP::query()->delete();
	}

	public function tear_down(): void {
		OTP::query()->delete();
		parent::tear_down();
	}

	/**
	 * @dataProvider purpose_copy_provider
	 */
	public function test_email_subject_and_body_identify_the_exact_otp_purpose(
		string $purpose,
		string $expected_title,
		string $expected_instruction,
		string $unexpected_copy
	): void {
		$captured = null;
		$filter   = static function ( $return, array $mail ) use ( &$captured ) {
			unset( $return );
			$captured = $mail;

			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		try {
			$otp = OTP::query()->create(
				[
					'user_id'    => null,
					'identifier' => 'purpose-email@example.test',
					'code'       => '1234',
					'type'       => $purpose,
					'channels'   => [ 'email' => false ],
				]
			);

			$channels = ChannelService::send( $otp, 1234 );
		} finally {
			remove_filter( 'pre_wp_mail', $filter, 10 );
		}

		self::assertSame( [ 'email' ], $channels );
		self::assertIsArray( $captured );
		self::assertSame( 'purpose-email@example.test', $captured['to'] ?? null );
		self::assertStringContainsString( $expected_title, $captured['subject'] ?? '' );
		self::assertStringContainsString( $expected_instruction, $captured['message'] ?? '' );
		self::assertStringNotContainsString( $unexpected_copy, $captured['message'] ?? '' );
		self::assertStringNotContainsString( '{{purpose_', $captured['message'] ?? '' );
	}

	/**
	 * @return array<string, array{string,string,string,string}>
	 */
	public function purpose_copy_provider(): array {
		return [
			'login'    => [ OTP::TYPE_LOGIN, 'کد ورود', 'فقط در صفحهٔ ورود', 'بازیابی رمز عبور حساب' ],
			'register' => [ OTP::TYPE_REGISTER, 'کد ثبت‌نام', 'فقط در مرحلهٔ ثبت‌نام', 'برای ورود به حساب کاربری' ],
			'forget'   => [ OTP::TYPE_FORGET, 'کد بازیابی رمز عبور', 'فقط در صفحهٔ بازیابی رمز عبور', 'برای ورود به حساب کاربری' ],
		];
	}
}
