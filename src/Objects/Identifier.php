<?php

namespace Pinova\Objects;

use Pinova\Helpers\Number;

class Identifier {

	private string $value;

	private string $type = '';

	public const TYPE_EMAIL    = 'email';
	public const TYPE_MOBILE   = 'mobile';
	public const TYPE_USERNAME = 'username';

	public function __construct( string $value ) {
		$this->set_value( $value );
	}

	public function is_valid(): bool {
		return ! empty( $this->type );
	}

	public function is_email(): bool {
		return self::TYPE_EMAIL === $this->type;
	}

	public function is_mobile(): bool {
		return self::TYPE_MOBILE === $this->type;
	}

	public function is_username(): bool {
		return self::TYPE_USERNAME === $this->type;
	}

	public function get_value(): string {
		return $this->value;
	}

	public function set_value( string $value ): void {
		$this->value = Number::en( $value );
		$this->value = sanitize_text_field( $this->value );

		if ( is_email( $this->value ) ) {
			$this->type = self::TYPE_EMAIL;

			return;
		}

		$mobile = new Mobile( $this->value );

		if ( $mobile->is_valid() ) {
			$this->type  = self::TYPE_MOBILE;
			$this->value = $mobile->get_formatted();

			return;
		}

		if ( sanitize_user( $value, true ) === $value ) {
			$this->type = self::TYPE_USERNAME;
		}
	}

	public function get_type(): string {
		return $this->type;
	}
}
