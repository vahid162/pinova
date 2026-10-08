<?php

declare(strict_types=1);

namespace Pinova\Logging;

/** Keep fallback failure injection outside production code and host logs. */
function error_log( string $message ): bool {
	if ( isset( $GLOBALS['pinova_test_fallback_messages'] ) ) {
		$GLOBALS['pinova_test_fallback_messages'][] = $message;
		if ( ! empty( $GLOBALS['pinova_test_fallback_throws'] ) ) {
			throw new \RuntimeException( 'private host logger failure' );
		}
		return false;
	}
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Test seam delegates to the actual host logger outside its scoped fixture.
	return \error_log( $message );
}
