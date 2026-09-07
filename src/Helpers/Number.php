<?php

namespace Pinova\Helpers;

class Number {

	/**
	 * @param string $string
	 *
	 * @return string
	 */
	public static function fa( string $string ): string {
		return str_replace( array_map( 'strval', range( 0, 9 ) ), [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ], $string );
	}

	/**
	 * @param string $string
	 *
	 * @return string
	 */
	public static function en( string $string ): string {

		// Farsi
		$string = str_ireplace( [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ], array_map( 'strval', range( 0, 9 ) ), $string );

		// Arabic
		$string = str_ireplace( [ '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ], array_map( 'strval', range( 0, 9 ) ), $string );

		return $string;
	}

}
