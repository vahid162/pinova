<?php
/** Run-owned, network-isolated full-plugin fixture; never install on a real site. */

declare(strict_types=1);

namespace Pinova\Services {

	/** Instrument the genuine native completion without substituting success. */
	function fastcgi_finish_request(): bool {
		$result = \fastcgi_finish_request();
		if ( true === $result ) {
			\pinova_full_stack_marker( 'finished' );
		}
		return $result;
	}
}

namespace {
	$fixture_root = (string) getenv( 'PINOVA_FPM_FULL_STACK_ROOT' );
	$expected     = '/tmp/pinova-fpm-full-' . getenv( 'GITHUB_RUN_ID' ) . '-' . getenv( 'GITHUB_RUN_ATTEMPT' );
	if ( '1' !== getenv( 'PINOVA_FPM_FULL_STACK_FIXTURE' ) || $fixture_root !== $expected
		|| ! defined( 'PINOVA_FPM_FULL_STACK_SITE' ) || true !== PINOVA_FPM_FULL_STACK_SITE
		|| realpath( ABSPATH ) !== $fixture_root . '/wordpress' || is_link( $fixture_root ) ) {
		exit( 1 );
	}
	if ( 'fpm-fcgi' === PHP_SAPI ) {
		header( 'X-Pinova-Fixture-PHP: ' . PHP_VERSION );
	}

	/** Only finite test labels, PID and timestamps enter the shared evidence. */
	function pinova_full_stack_marker( string $event ): void {
		$case = (string) ( $_SERVER['HTTP_X_PINOVA_FIXTURE_CASE'] ?? getenv( 'PINOVA_FPM_CASE' ) ?: 'setup' );
		if ( ! preg_match( '/\A[a-z][a-z0-9_-]{0,47}\z/D', $case ) ) {
			$case = 'invalid';
		}
		$allowed = [ 'request_start', 'request_end', 'finished', 'account_lookup', 'provider_enter', 'provider_return', 'blocked_http', 'blocked_mail' ];
		if ( ! in_array( $event, $allowed, true ) ) {
			return;
		}
		$entry = [ 'case' => $case, 'event' => $event, 'pid' => getmypid(), 'at' => microtime( true ) ];
		file_put_contents( getenv( 'PINOVA_FPM_FULL_STACK_ROOT' ) . '/events.jsonl', json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
	}

	pinova_full_stack_marker( 'request_start' );
	register_shutdown_function( static function (): void { pinova_full_stack_marker( 'request_end' ); } );
	add_filter( 'query', static function ( string $sql ): string {
		global $wpdb;
		// Observe the existing synthetic-mobile account-resolution query without
		// changing it or retaining SQL/identifiers. Other WP bootstrap reads do
		// not establish the delivery-time account-resolution boundary.
		if ( str_starts_with( $sql, 'SELECT `ID` FROM `' . $wpdb->users . '` WHERE `user_login` IN (' )
			&& str_contains( $sql, '0910000' ) ) {
			pinova_full_stack_marker( 'account_lookup' );
		}
		return $sql;
	} );

	// Installed before *any* plugin activation. The Linux network namespace also
	// rejects direct sockets/cURL/SMTP that bypass WordPress's public HTTP API.
	add_filter( 'pre_wp_mail', static function (): bool {
		pinova_full_stack_marker( 'blocked_mail' );
		return true;
	}, PHP_INT_MAX );
	add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) {
		static $forwarding = false;
		$local = 'http://127.0.0.1:' . getenv( 'PINOVA_FPM_PROVIDER_PORT' ) . '/send';
		if ( $forwarding && $url === $local ) {
			return false;
		}
		$body = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		$keys = is_array( $body ) ? array_keys( $body ) : [];
		sort( $keys );
		$shape = [ 'code', 'from', 'password', 'to', 'username' ];
		$valid = 'https://rest.payamak-panel.com/api/SendSMS/SendOtp' === $url
			&& 'POST' === strtoupper( (string) ( $args['method'] ?? '' ) )
			&& $keys === $shape && 'fixture-user' === $body['username'] && 'fixture-secret' === $body['password']
			&& 'fixture-sender' === $body['from'] && is_string( $body['to'] )
			&& 1 === preg_match( '/\A\+98910000[0-9]{4}\z/D', $body['to'] )
			&& is_scalar( $body['code'] ) && 1 === preg_match( '/\A[0-9]{4,6}\z/D', (string) $body['code'] )
			&& is_numeric( $args['timeout'] ?? null ) && $args['timeout'] > 0 && $args['timeout'] <= 8;
		if ( ! $valid ) {
			pinova_full_stack_marker( 'blocked_http' );
			return new WP_Error( 'fixture_network_denied', 'Fixture outbound request denied.' );
		}
		pinova_full_stack_marker( 'provider_enter' );
		$forwarding = true;
		try {
			// Carry the real adapter's remaining timeout through unchanged. No OTP,
			// identifier, JWT, request body or provider payload is persisted/logged.
			$result = wp_remote_post( $local, [
				'timeout' => $args['timeout'], 'redirection' => 0,
				'headers' => [
					'Content-Type' => 'application/json',
					'X-Pinova-Fixture-Case' => (string) ( $_SERVER['HTTP_X_PINOVA_FIXTURE_CASE'] ?? getenv( 'PINOVA_FPM_CASE' ) ?: 'setup' ),
					'X-Pinova-Fixture-Pid' => (string) getmypid(),
				],
				'body' => $args['body'],
			] );
		} finally {
			$forwarding = false;
			pinova_full_stack_marker( 'provider_return' );
		}
		return $result;
	}, PHP_INT_MAX, 3 );
}
