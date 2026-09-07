<?php

namespace Pinova\Helpers;

class Password {
	/**
	 * Validate input password to be secure
	 *
	 * @param string $password
	 *
	 * @return array ['valid'=>bool, 'errors'=>array]
	 */
	public static function validate( string $password ): array {
		$errors = [];
		// todo: avoid using mb_strlen as it depends on mbstring
		if ( mb_strlen( $password ) < 8 ) {
			$errors[] = __( 'حداقل ۸ کاراکتر لازم است.', 'pinova' );
		}
		// todo: avoid using preg_match because of slowness
		if ( ! preg_match( '/\d/u', $password ) ) {
			$errors[] = __( 'رمز عبور باید شامل حداقل یک عدد باشد.', 'pinova' );
		}

		if ( ! preg_match( '/[A-Z]/u', $password ) ) {
			$errors[] = __( 'رمز عبور باید شامل حداقل یک حرف بزرگ (A-Z) باشد.', 'pinova' );
		}

		if ( ! preg_match( '/[a-z]/u', $password ) ) {
			$errors[] = __( 'رمز عبور باید شامل حداقل یک حرف کوچک (a-z) باشد.', 'pinova' );
		}

		if ( ! preg_match( '/[^a-zA-Z0-9]/u', $password ) ) {
			$errors[] = __( 'رمز عبور باید شامل حداقل یک کاراکتر خاص باشد.', 'pinova' );
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}
}
