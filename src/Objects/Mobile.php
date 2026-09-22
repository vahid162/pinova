<?php

namespace Pinova\Objects;

use Exception;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Pinova\Helpers\Number;

class Mobile {

	private ?PhoneNumber $value = null;

	public function __construct( string $value ) {
		$this->set_value( $value );
	}

	public function is_valid(): bool {
		return $this->value instanceof PhoneNumber;
	}

	/**
	 * @throws Exception
	 */
	public function parse( string $mobile, ?string $region = 'IR' ): PhoneNumber {

		$phone_util = PhoneNumberUtil::getInstance();

		try {

			$phone_number = $phone_util->parse( $mobile, $region );

			if ( ! $phone_util->isValidNumber( $phone_number ) ) {

				if ( ! str_contains( $mobile, '+' ) ) {
					return self::parse( '+' . $mobile, null );
				}

				throw new Exception( 'تلفن همراه وارد شده معتبر نمی‌باشد.' );
			}

			if ( ! in_array(
				$phone_util->getNumberType( $phone_number ),
				[
					PhoneNumberType::MOBILE,
					PhoneNumberType::FIXED_LINE_OR_MOBILE,
				],
				true
			) ) {
				throw new Exception( 'شماره وارد شده تلفن همراه نیست.' );
			}

			return $phone_number;

		} catch ( Exception $e ) {
			throw new Exception( 'تلفن همراه وارد شده صحیح نمی‌باشد.' );
		}
	}

	public function get_country(): ?int {
		return $this->value->getCountryCode();
	}

	public function get_formatted(): string {
		return sprintf( '+%s%s', $this->value->getCountryCode(), $this->value->getNationalNumber() );
	}

	public function get_sanitized_username(): string {
		return sanitize_user( $this->get_formatted(), true );
	}

	public function possible_formats(): array {

		$phone_util = PhoneNumberUtil::getInstance();

		return apply_filters(
			'pinova/mobile_possible_formats',
			[
				$this->value->getNationalNumber(),
				$this->value->getCountryCode() . $this->value->getNationalNumber(),
				$phone_util->format( $this->value, PhoneNumberFormat::E164 ),
				str_replace( ' ', '', $phone_util->format( $this->value, PhoneNumberFormat::NATIONAL ) ),
			],
			$this
		);
	}

	public function get_value(): string {
		return $this->value;
	}

	public function set_value( string $value ): void {
		$value = Number::en( $value );

		try {
			$this->value = self::parse( $value );
		} catch ( Exception $e ) {
			return;
		}
	}
}
