<?php

namespace Pinova\Exceptions;

use RuntimeException;

final class BlockedException extends RuntimeException {
	private string $identifier_type;

	public function __construct( string $identifier_type ) {
		$this->identifier_type = $identifier_type;
		parent::__construct( self::message_for( $identifier_type ) );
	}

	public function get_identifier_type(): string {
		return $this->identifier_type;
	}

	public static function message_for( string $identifier_type ): string {
		switch ( $identifier_type ) {
			case 'mobile':
				return __( 'تلفن همراه مسدود شده است.', 'pinova' );
			case 'email':
				return __( 'ایمیل مسدود شده است.', 'pinova' );
			case 'username':
				return __( 'نام کاربری مسدود شده است.', 'pinova' );
			case 'ip':
				return __( 'آدرس آی.پی شما مسدود شده است.', 'pinova' );
			default:
				return __( 'این شناسه مسدود شده است.', 'pinova' );
		}
	}
}
