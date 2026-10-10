<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pinova\Logging\EventEvidence;
use Pinova\Logging\IssueMonitor;

final class IssueMonitorTest extends TestCase {

	/** @dataProvider classifications */
	public function test_operational_facts_are_not_confused_with_expected_rejections( string $event, string $reason, string $category ): void {
		$issues = IssueMonitor::summarize( [ $this->row( 1, $event, [ 'reason' => $reason ] ) ] );
		self::assertCount( 1, $issues );
		self::assertSame( $category, $issues[0]['category'] );
		self::assertSame( 'root_cause_unconfirmed', $issues[0]['diagnosis'] );
		self::assertSame( $event . ':' . $reason, $issues[0]['code'] );
	}

	public function classifications(): array {
		return [
			[ 'otp.verify_failed', 'token_expired', 'expected_rejection' ],
			[ 'otp.verify_failed', 'invalid_code', 'expected_rejection' ],
			[ 'auth.password_failed', 'authentication_rejected', 'expected_rejection' ],
			[ 'otp.channel_send_failed', 'provider_returned_false', 'confirmed_failure' ],
			[ 'auth.request_failed', 'delivery_worker_error', 'confirmed_failure' ],
			[ 'auth.request_failed', 'otp_completion_failed', 'confirmed_failure' ],
			[ 'auth.request_failed', 'mobile_evidence_unavailable', 'confirmed_failure' ],
			[ 'auth.session_failed', 'session_exception', 'confirmed_failure' ],
			[ 'auth.session_failed', 'policy_rejected', 'expected_rejection' ],
			[ 'auth.password_reset_failed', 'reset_pipeline_failed', 'confirmed_failure' ],
			[ 'otp.verify_failed', 'record_not_found', 'insufficient_evidence' ],
			[ 'otp.verify_failed', 'claim_rejected', 'insufficient_evidence' ],
			[ 'otp.delivery_skipped', 'policy_rejected', 'expected_rejection' ],
			[ 'otp.delivery_skipped', 'claim_unavailable', 'insufficient_evidence' ],
			[ 'otp.delivery_skipped', 'provider_outcome_unknown', 'insufficient_evidence' ],
			[ 'otp.delivery_skipped', 'duplicate_records', 'confirmed_failure' ],
		];
	}

	public function test_safe_reason_and_timing_survive_without_legacy_secrets(): void {
		$row = $this->row(
			1,
			'otp.channel_send_failed',
			[
				'reason' => 'provider_returned_false',
				'channel' => 'sms',
				'duration_ms' => 1200,
				'queue_delay_seconds' => 63,
				'remaining_seconds' => 117,
				'otp_type' => 'verify_mobile',
				'password' => 'private-password',
				'identifier' => 'private@example.test',
				'identifier_fingerprint' => str_repeat( 'a', 32 ),
				'exception_class' => 'PrivateClassToken',
				'exception_message' => 'private-provider-response',
				'provider' => 'private_api_key',
			]
		);
		$event = EventEvidence::redact( $row );
		self::assertSame( 'provider_returned_false', $event['context']['reason'] );
		self::assertSame( 'sms', $event['context']['channel'] );
		self::assertSame( 1200, $event['context']['duration_ms'] );
		self::assertSame( 63, $event['context']['queue_delay_seconds'] );
		self::assertSame( 117, $event['context']['remaining_seconds'] );
		self::assertSame( 'verify_mobile', $event['context']['otp_type'] );
		foreach ( [ 'password', 'identifier', 'identifier_fingerprint', 'exception_class', 'exception_message', 'provider' ] as $key ) {
			self::assertArrayNotHasKey( $key, $event['context'] );
		}
		self::assertStringNotContainsString( 'private', json_encode( $event ) );
		$skipped = EventEvidence::redact( $this->row( 2, 'otp.delivery_skipped', [ 'reason' => 'claim_unavailable', 'operation' => 'queued_otp' ] ) );
		self::assertSame( 'queued_otp', $skipped['context']['operation'] );
		self::assertSame( 'claim_unavailable', $skipped['context']['reason'] );
	}

	public function test_unknown_reason_and_malformed_correlations_remain_inconclusive(): void {
		$row = $this->row( 1, 'otp.verify_failed', [ 'reason' => 'private_token_shaped_like_a_code', 'duration_ms' => 600001, 'remaining_seconds' => '180' ] );
		$row['correlation_id'] = 'private-correlator';
		$row['flow_id'] = 'private-flow';
		$event = EventEvidence::redact( $row );
		self::assertSame( [], $event['context'] );
		self::assertSame( '', $event['correlation_id'] );
		self::assertSame( '', $event['flow_id'] );
		$issues = IssueMonitor::summarize( [ $row ] );
		self::assertSame( 'insufficient_evidence', $issues[0]['category'] );
		self::assertSame( 'otp.verify_failed', $issues[0]['code'] );
		$row['event'] = 'private-secret-as-event';
		self::assertSame( 'logging.unknown_event', IssueMonitor::summarize( [ $row ] )[0]['code'] );
		$row['context'] = '{broken';
		self::assertSame( [], EventEvidence::redact( $row )['context'] );
	}

	public function test_grouping_keeps_observed_counts_timestamps_and_three_safe_examples(): void {
		$rows = [];
		for ( $id = 1; $id <= 5; ++$id ) {
			$rows[] = $this->row( $id, 'otp.channel_send_failed', [ 'reason' => 'provider_exception', 'channel' => 'sms', 'build_commit' => str_repeat( 'b', 40 ) ] );
		}
		$issues = IssueMonitor::summarize( array_reverse( $rows ) );
		self::assertCount( 1, $issues );
		$issue = $issues[0];
		self::assertSame( 'otp.channel_send_failed:provider_exception', $issue['code'] );
		self::assertSame( 5, $issue['observed_count'] );
		self::assertSame( 5, $issue['last_event_id'] );
		self::assertSame( $rows[0]['created_at'], $issue['first_seen'] );
		self::assertSame( $rows[4]['created_at'], $issue['last_seen'] );
		self::assertCount( 3, $issue['evidence'] );
		foreach ( $issue['evidence'] as $event ) {
			self::assertArrayNotHasKey( 'user_id', $event );
			self::assertSame( str_repeat( 'b', 40 ), $event['context']['build_commit'] );
		}
	}

	public function test_unmatched_queue_is_inconclusive_after_grace_and_never_a_delivery_claim(): void {
		$queued = $this->row( 1, 'otp.queued' );
		$queued['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$issues = IssueMonitor::summarize( [ $queued ] );
		self::assertSame( 'otp.outcome_unknown', $issues[0]['code'] );
		self::assertSame( 'insufficient_evidence', $issues[0]['category'] );
		$recent = $queued;
		$recent['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 );
		self::assertSame( [], IssueMonitor::summarize( [ $recent ] ) );
		foreach ( [ 'otp.created', 'otp.delivery_failed', 'otp.delivery_skipped', 'auth.request_failed' ] as $event ) {
			$outcome = $this->row( 2, $event, [ 'reason' => 'already_completed', 'operation' => 'queued_otp' ] );
			$outcome['flow_id'] = $queued['flow_id'];
			self::assertNotContains( 'otp.outcome_unknown', array_column( IssueMonitor::summarize( [ $outcome, $queued ] ), 'code' ) );
		}
	}

	public function test_unrelated_flow_does_not_complete_a_queue(): void {
		$queued = $this->row( 1, 'otp.queued' );
		$queued['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$created = $this->row( 2, 'otp.created' );
		self::assertSame( 'otp.outcome_unknown', IssueMonitor::summarize( [ $created, $queued ] )[0]['code'] );
	}

	public function test_review_state_reopens_for_newer_evidence_without_claiming_verified_repair(): void {
		foreach ( [ 'acknowledged', 'resolved_unverified' ] as $state ) {
			$review = [ 'state' => $state, 'through_id' => 42, 'at' => 100 ];
			self::assertSame( $state, IssueMonitor::state( 42, $review ) );
			self::assertSame( 'open', IssueMonitor::state( 43, $review ) );
			$changed = $review;
			$changed['through_id'] = 43;
			self::assertNotSame( IssueMonitor::review_token( $review ), IssueMonitor::review_token( $changed ) );
		}
	}

	private function row( int $id, string $event, array $context = [] ): array {
		return [
			'id' => $id,
			'created_at' => '2026-01-01 12:00:' . str_pad( (string) $id, 2, '0', STR_PAD_LEFT ),
			'event' => $event,
			'level' => 'warning',
			'correlation_id' => '123e4567-e89b-42d3-a456-426614174000',
			'flow_id' => str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ),
			'user_id' => 27,
			'context' => json_encode( $context ),
		];
	}
}
