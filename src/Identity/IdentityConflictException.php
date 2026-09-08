<?php

namespace Pinova\Identity;

use RuntimeException;

class IdentityConflictException extends RuntimeException {
	private string $identity_type;
	private string $normalized_value;
	private array $user_ids;

	public function __construct( string $identity_type, string $normalized_value, array $user_ids ) {
		parent::__construct( __( 'این شناسه به بیش از یک حساب کاربری متصل است و نیاز به بررسی مدیر دارد.', 'pinova' ) );
		$this->identity_type    = $identity_type;
		$this->normalized_value = $normalized_value;
		$this->user_ids         = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
	}

	public function get_identity_type(): string {
		return $this->identity_type;
	}

	public function get_normalized_value(): string {
		return $this->normalized_value;
	}

	public function get_user_ids(): array {
		return $this->user_ids;
	}
}
