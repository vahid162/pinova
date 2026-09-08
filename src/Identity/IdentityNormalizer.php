<?php

namespace Pinova\Identity;

use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;

class IdentityNormalizer {
	public static function infer( string $value ): array {
		$identifier = new Identifier( $value );

		if ( ! $identifier->is_valid() ) {
			return [ null, null ];
		}

		return [
			$identifier->get_type(),
			self::normalize( $identifier->get_type(), $identifier->get_value() ),
		];
	}

	public static function normalize( string $type, string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		switch ( $type ) {
			case Identifier::TYPE_MOBILE:
				$mobile = new Mobile( $value );

				return $mobile->is_valid() ? $mobile->get_formatted() : null;
			case Identifier::TYPE_EMAIL:
				$email = sanitize_email( $value );

				return is_email( $email ) ? strtolower( $email ) : null;
			case Identifier::TYPE_USERNAME:
				$username = sanitize_user( $value, true );

				return '' !== $username ? strtolower( $username ) : null;
			default:
				return null;
		}
	}
}
