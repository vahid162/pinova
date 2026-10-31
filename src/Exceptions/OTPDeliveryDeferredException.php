<?php

namespace Pinova\Exceptions;

/** Internal loss of queued delivery permission; never a provider outcome. */
class OTPDeliveryDeferredException extends \RuntimeException {
	private string $diagnostic_reason;

	public function __construct( string $message = '', int $code = 0, ?\Throwable $previous = null, string $reason = 'claim_unavailable' ) {
		parent::__construct( $message, $code, $previous );
		$this->diagnostic_reason = in_array( $reason, [ 'claim_unavailable', 'budget_expired', 'channel_unsupported', 'deadline_expired' ], true ) ? $reason : 'claim_unavailable';
	}

	public function diagnostic_reason(): string {
		return $this->diagnostic_reason;
	}
}
