<?php

namespace Pinova\Logging;

/** One historical-data redactor for the viewer, exports and issue reports. */
final class EventEvidence {

	/** Finite reasons are safe to expose; arbitrary code-shaped strings are not. */
	public const REASONS = [
		'auth.request_failed'        => [ 'initiation_error', 'delivery_worker_error', 'queue_expired' ],
		'auth.password_failed'       => [ 'authentication_rejected', 'native_only_policy' ],
		'auth.password_reset_failed' => [ 'native_only_policy', 'reset_key_generation_failed', 'reset_key_rejected', 'password_mismatch', 'invalid_token', 'user_not_found', 'invalid_reset_key', 'reset_pipeline_failed' ],
		'auth.logout_rejected'       => [ 'invalid_nonce' ],
		'auth.redirect_failed'       => [ 'safe_redirect_rejected', 'headers_sent' ],
		'otp.verify_failed'          => [ 'token_expired', 'invalid_token', 'record_not_found', 'flow_mismatch', 'purpose_mismatch', 'expired', 'already_verified', 'attempt_limit', 'ip_mismatch', 'invalid_code', 'policy_rejected', 'claim_rejected' ],
		'otp.channel_send_failed'    => [ 'provider_returned_false', 'provider_exception' ],
		'otp.delivery_skipped'       => [ 'policy_rejected', 'already_completed', 'provider_outcome_unknown', 'invalid_payload', 'duplicate_records', 'claim_unavailable', 'expired' ],
	];

	/** @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	private static function diagnostic_context( string $event, array $stored ): array {
		$allowed = [ 'reason' => self::REASONS[ $event ] ?? [] ];
		if ( str_starts_with( $event, 'otp.' ) || str_starts_with( $event, 'admin.sms_test_' ) ) {
			$allowed['channel'] = [ 'sms', 'email', 'bale', 'call' ];
		}
		if ( in_array( $event, [ 'auth.request_failed', 'otp.delivery_skipped' ], true ) ) {
			$allowed['operation'] = [ 'authenticate', 'queued_otp' ];
		} elseif ( str_starts_with( $event, 'auth.password_reset_' ) ) {
			$allowed['operation'] = [ 'forgot_verify', 'forgot_change' ];
		} elseif ( str_starts_with( $event, 'user.export_' ) ) {
			$allowed['operation'] = [ 'excel', 'vcf' ];
		} elseif ( 'auth.redirect_failed' === $event ) {
			$allowed['operation'] = [ 'logout', 'login', 'native_login', 'force_reauthentication', 'issue_review' ];
		}
		if ( in_array( $event, [ 'auth.session_created', 'user.registered' ], true ) ) {
			$allowed['auth_method'] = [ 'otp', 'password' ];
		}
		if ( 'logging.issue_reviewed' === $event ) {
			$allowed['result'] = [ 'acknowledged', 'resolved_unverified' ];
		}
		$safe = [];
		foreach ( $allowed as $key => $values ) {
			if ( isset( $stored[ $key ] ) && in_array( $stored[ $key ], $values, true ) ) {
				$safe[ $key ] = $stored[ $key ];
			}
		}
		if ( in_array( $event, [ 'otp.created', 'otp.delivery_failed' ], true ) && isset( $stored['channels'] ) && is_array( $stored['channels'] ) ) {
			$safe['channels'] = array_values( array_intersect( [ 'sms', 'email', 'bale', 'call' ], array_filter( $stored['channels'], 'is_string' ) ) );
		}
		return $safe;
	}

	/** @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function redact( array $row ): array {
		$stored = json_decode( (string) ( $row['context'] ?? '{}' ), true );
		$stored = is_array( $stored ) ? $stored : [];
		$safe   = [];
		foreach ( [
			'attempt'             => 1000,
			'attempts'            => 1000,
			'candidate_count'     => 1000,
			'count'               => 1000,
			'duration_ms'         => 600000,
			'http_status'         => 599,
			'retry_after'         => 86400,
			'queue_delay_seconds' => 86400,
			'remaining_seconds'   => 86400,
		] as $key => $maximum ) {
			if ( isset( $stored[ $key ] ) && is_int( $stored[ $key ] ) && 0 <= $stored[ $key ] && $maximum >= $stored[ $key ] ) {
				$safe[ $key ] = $stored[ $key ];
			}
		}
		if ( isset( $stored['identifier_type'] ) && in_array( $stored['identifier_type'], [ 'email', 'mobile', 'username', 'ip' ], true ) ) {
			$safe['identifier_type'] = $stored['identifier_type'];
		}
		if ( isset( $stored['otp_type'] ) && in_array( $stored['otp_type'], [ 'login', 'register', 'forget', 'verify_mobile' ], true ) ) {
			$safe['otp_type'] = $stored['otp_type'];
		}
		if ( 'settings.updated' === ( $row['event'] ?? '' ) ) {
			$keys = isset( $stored['changed_keys'] ) && is_array( $stored['changed_keys'] ) ? SettingsAudit::safe_keys( $stored['changed_keys'] ) : [];
			if ( $keys ) {
				$safe['changed_keys'] = $keys;
			}
			if ( isset( $stored['operation'] ) && in_array(
				$stored['operation'],
				[
					'pinova_general',
					'pinova_sms',
					'pinova_gateway_maxsms',
					'pinova_gateway_melipayamak',
					'pinova_gateway_panelchi',
					'pinova_messengers',
					'pinova_zohal',
					'pinova_design',
					'pinova_logging',
					'pinova_advanced',
				],
				true
			) ) {
				$safe['operation'] = $stored['operation'];
			}
			if ( 'success' === ( $stored['result'] ?? '' ) ) {
				$safe['result'] = 'success';
			}
		}
		if ( isset( $stored['build_commit'] ) && is_string( $stored['build_commit'] ) && preg_match( '/\A[a-f0-9]{40}\z/', $stored['build_commit'] ) ) {
			$safe['build_commit'] = $stored['build_commit'];
		}
		if ( isset( $stored['package_identity'] ) && in_array( $stored['package_identity'], [ 'source', 'pinova-release-zip' ], true ) ) {
			$safe['package_identity'] = $stored['package_identity'];
		}
		if ( isset( $stored['release_tag'] ) && is_string( $stored['release_tag'] ) && preg_match( '/\Av[0-9]+\.[0-9]+\.[0-9]+-rc[1-9][0-9]*\z/', $stored['release_tag'] ) ) {
			$safe['release_tag'] = $stored['release_tag'];
		}

		$safe = array_merge( $safe, self::diagnostic_context( LogRepository::redact_event( $row['event'] ?? null ), $stored ) );

		return [
			'created_at'     => preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) ( $row['created_at'] ?? '' ) ) ? $row['created_at'] : '',
			'level'          => in_array( $row['level'] ?? '', [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ], true ) ? $row['level'] : 'unknown',
			'event'          => LogRepository::redact_event( $row['event'] ?? null ),
			'correlation_id' => LogRepository::redact_correlation_id( $row['correlation_id'] ?? null ),
			'flow_id'        => preg_match( '/\A[a-f0-9]{32}\z/', (string) ( $row['flow_id'] ?? '' ) ) ? $row['flow_id'] : '',
			'user_id'        => max( 0, (int) ( $row['user_id'] ?? 0 ) ),
			'context'        => $safe,
		];
	}
}
