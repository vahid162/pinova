<?php

namespace Pinova\Integrations;

use Pinova\Pinova;

/** Navigation only: callers supply a target after exactly one request-query decode. */
final class Continuation {
	/** @param string[] $excluded Additional canonical authentication URLs. */
	public static function validate( $target, ?string $fallback = null, array $excluded = [] ): string {
		$home     = home_url( '/' );
		$excluded = array_merge( [ Pinova::get_login_url(), home_url( '/logout/' ), site_url( '/wp-login.php' ) ], $excluded );
		$fallback = self::safe( $fallback, $excluded ) ?? $home;
		return self::safe( $target, $excluded ) ?? $fallback;
	}

	/** @param string[] $excluded Canonical authentication URLs. */
	private static function safe( $target, array $excluded ): ?string {
		if ( ! is_string( $target ) || '' === trim( $target ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $target ) ) {
			return null;
		}
		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			$origin = wp_parse_url( home_url() );
			$target = $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $target;
		}
		$url  = wp_parse_url( $target );
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $url ) || isset( $url['user'] ) || isset( $url['pass'] )
			|| ! in_array( $url['scheme'] ?? '', [ 'http', 'https' ], true )
			|| strtolower( $url['host'] ?? '' ) !== strtolower( $home['host'] )
			|| $url['scheme'] !== $home['scheme']
			|| ( $url['port'] ?? ( 'https' === $url['scheme'] ? 443 : 80 ) ) !== ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) ) ) {
			return null;
		}
		// Reject encoded path separators/dot segments and controls without decoding the destination again.
		$path = $url['path'] ?? '/';
		if ( preg_match( '/%(?:2e|2f|5c|25)|(?:^|\/)\.{1,2}(?:\/|$)/i', $path ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $target ) ) {
			return null;
		}
		$path = strtolower( rawurldecode( $path ) );
		parse_str( $url['query'] ?? '', $query );
		if ( isset( $query['pinova_verify_mobile'] ) ) {
			return null;
		}
		foreach ( (array) ( $query['wpfaction'] ?? [] ) as $action ) {
			if ( ! is_string( $action ) || in_array( sanitize_title( $action ), [ 'login', 'registration', 'register', 'lostpassword', 'resetpassword' ], true ) ) {
				return null;
			}
		}
		foreach ( $excluded as $blocked ) {
			$parts = wp_parse_url( $blocked );
			if ( ! is_array( $parts ) || untrailingslashit( $path ) !== strtolower( untrailingslashit( rawurldecode( $parts['path'] ?? '/' ) ) ) ) {
				continue;
			}
			parse_str( $parts['query'] ?? '', $blocked_query );
			if ( array_intersect_key( $query, $blocked_query ) === $blocked_query ) {
				return null;
			}
		}
		return $target === wp_validate_redirect( $target, '' ) ? $target : null;
	}
}
