<?php

namespace Pinova\Exceptions;

use RuntimeException;

class RateLimitException extends RuntimeException {
	private int $retry_after;

	public function __construct( int $retry_after ) {
		parent::__construct( __( 'تعداد تلاش‌های شما بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'pinova' ) );
		$this->retry_after = max( 1, $retry_after );
	}

	public function get_retry_after(): int {
		return $this->retry_after;
	}
}
