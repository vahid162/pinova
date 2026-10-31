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
			[ 'auth.request_failed', 'queue_schedule_failed', 'confirmed_failure' ],
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
			[ 'otp.queue_deferred', 'claim_unavailable', 'insufficient_evidence' ],
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

	public function test_recovery_and_deferred_claims_do_not_complete_a_queue_or_prove_delivery(): void {
		$queued = $this->row( 1, 'otp.queued' );
		$queued['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
		foreach ( [ 'otp.queue_recovered', 'otp.queue_deferred' ] as $event ) {
			$observation = $this->row( 2, $event, [ 'reason' => 'claim_unavailable', 'operation' => 'queued_otp', 'remaining_seconds' => 120 ] );
			$observation['flow_id'] = $queued['flow_id'];
			$issues = array_column( IssueMonitor::summarize( [ $observation, $queued ] ), null, 'code' );
			self::assertArrayHasKey( 'otp.outcome_unknown', $issues );
			self::assertSame( 'insufficient_evidence', $issues['otp.outcome_unknown']['category'] );
			if ( 'otp.queue_deferred' === $event ) {
				self::assertSame( 'insufficient_evidence', $issues['otp.queue_deferred:claim_unavailable']['category'] );
			} else {
				self::assertCount( 1, $issues );
			}
		}
	}

	public function test_queue_observations_keep_only_finite_evidence_and_never_recovery_claims(): void {
		foreach ( [ 'otp.queue_recovered', 'otp.queue_deferred', 'auth.request_failed' ] as $event ) {
			$context = [
				'operation' => 'queued_otp',
				'reason' => 'auth.request_failed' === $event ? 'queue_schedule_failed' : ( 'otp.queue_recovered' === $event ? 'missing_schedule' : 'claim_unavailable' ),
				'queue_delay_seconds' => 60,
				'remaining_seconds' => 120,
				'identifier' => 'private@example.test',
				'otp' => 'private-otp',
				'provider_response' => 'private-provider-response',
				'payload' => 'private-ciphertext',
				'recovery_verified' => true,
			];
			$redacted = EventEvidence::redact( $this->row( 1, $event, $context ) );
			self::assertSame( $event, $redacted['event'] );
			self::assertSame( 'queued_otp', $redacted['context']['operation'] );
			self::assertSame( 60, $redacted['context']['queue_delay_seconds'] );
			self::assertSame( 120, $redacted['context']['remaining_seconds'] );
			self::assertSame( $context['reason'], $redacted['context']['reason'] );
			self::assertStringNotContainsString( 'private', json_encode( $redacted ) );
			self::assertArrayNotHasKey( 'recovery_verified', $redacted['context'] );
			$context['reason'] = 'private_code_shaped_secret';
			$context['operation'] = 'private_operation';
			$negative = EventEvidence::redact( $this->row( 2, $event, $context ) );
			self::assertArrayNotHasKey( 'reason', $negative['context'] );
			self::assertArrayNotHasKey( 'operation', $negative['context'] );
		}
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

	public function test_timeline_selectors_reject_ambiguous_or_untrusted_linkage(): void {
		$flow = str_repeat( 'a', 32 );
		$correlation = '123e4567-e89b-42d3-a456-426614174000';
		self::assertSame( [ 'valid' => true, 'type' => 'flow', 'value' => $flow ], IssueMonitor::selector( [ 'flow_id' => $flow ] ) );
		self::assertSame( [ 'valid' => true, 'type' => 'correlation', 'value' => $correlation ], IssueMonitor::selector( [ 'correlation_id' => $correlation ] ) );
		foreach ( [
			[ 'flow_id' => $flow, 'correlation_id' => $correlation ],
			[ 'flow_id' => [ $flow ] ],
			[ 'flow_id' => strtoupper( $flow ) ],
			[ 'correlation_id' => 'private-reference' ],
			[ 'correlation_id' => '123e4567-e89b-12d3-a456-426614174000' ],
		] as $filters ) {
			self::assertSame( [ 'valid' => false, 'type' => 'invalid', 'value' => '' ], IssueMonitor::selector( $filters ) );
		}
	}

	public function test_timeline_separates_transport_acceptance_verification_and_session_observations(): void {
		$now = time();
		$transport = $this->row( 2, 'otp.diagnostic', [ 'stage' => 'provider_completed', 'observed_at' => $now - 5, 'result' => 'accepted', 'provider_duration_ms' => 53, 'password' => 'private-password' ] );
		$started = $this->row( 1, 'otp.diagnostic', [ 'stage' => 'provider_started', 'observed_at' => $now - 6 ] );
		$verified = $this->row( 3, 'otp.verified' );
		$timeline = IssueMonitor::timeline( [ $verified, $transport, $started ] );
		self::assertSame( [ 1, 2, 3 ], array_column( $timeline['events'], 'id' ) );
		self::assertTrue( $timeline['observed_facts']['transport_completed'] );
		self::assertFalse( $timeline['observed_facts']['channel_accepted'] );
		self::assertTrue( $timeline['observed_facts']['otp_verified'] );
		self::assertFalse( $timeline['observed_facts']['session_created'] );
		self::assertSame( 'not_verified', $timeline['physical_delivery'] );
		self::assertTrue( $timeline['catalogue_is_not_required_sequence'] );
		self::assertContains( 'channel_completed', $timeline['unobserved_stages'] );
		self::assertSame( $now - 5, $timeline['events'][1]['context']['observed_at'] );
		self::assertSame( $transport['created_at'], $timeline['events'][1]['created_at'] );
		self::assertStringNotContainsString( 'private', json_encode( $timeline ) );

		$accepted = $this->row( 4, 'otp.diagnostic', [ 'stage' => 'channel_completed', 'observed_at' => $now - 4, 'result' => 'accepted', 'channel' => 'email' ] );
		$unknown = $this->row( 5, 'otp.diagnostic', [ 'stage' => 'channel_completed', 'observed_at' => $now - 3, 'result' => 'unknown' ] );
		$session = $this->row( 6, 'auth.session_created' );
		$timeline = IssueMonitor::timeline( [ $accepted, $unknown, $session ] );
		self::assertTrue( $timeline['observed_facts']['channel_accepted'] );
		self::assertTrue( $timeline['observed_facts']['channel_outcome_unknown'] );
		self::assertTrue( $timeline['observed_facts']['session_created'] );
		self::assertFalse( $timeline['observed_facts']['otp_verified'] );
		self::assertSame( 'not_verified', $timeline['physical_delivery'] );
	}

	public function test_timeline_is_bounded_and_does_not_fabricate_legacy_stages(): void {
		$rows = [];
		for ( $id = 1; $id <= 1001; ++$id ) {
			$rows[] = $this->row( $id, 'otp.created', [ 'identifier' => str_repeat( 'private', 1000 ), 'remaining_seconds' => 100 ] );
		}
		$timeline = IssueMonitor::timeline( $rows );
		self::assertCount( 1000, $timeline['events'] );
		self::assertSame( [], $timeline['observed_stages'] );
		self::assertCount( 8, $timeline['unobserved_stages'] );
		self::assertFalse( $timeline['observed_facts']['channel_accepted'] );
		self::assertLessThanOrEqual( 2097152, strlen( json_encode( $timeline ) ) );
		self::assertStringNotContainsString( 'private', json_encode( $timeline ) );

		// Historical release tags are syntax-checked; use retained data to reach the byte cap.
		$release_tag = 'v' . str_repeat( '1', 6000 ) . '.0.0-rc1';
		$context = json_encode( [ 'release_tag' => $release_tag, 'identifier' => 'private-secret', 'remaining_seconds' => 100 ] );
		self::assertLessThan( 8192, strlen( $context ) );
		foreach ( $rows as &$row ) {
			$row['context'] = $context;
		}
		unset( $row );
		$timeline = IssueMonitor::timeline( $rows );
		self::assertTrue( $timeline['byte_truncated'] );
		self::assertNotEmpty( $timeline['events'] );
		self::assertLessThan( 1000, count( $timeline['events'] ) );
		self::assertSame( $release_tag, $timeline['events'][0]['context']['release_tag'] );
		self::assertLessThanOrEqual( 2097152, strlen( json_encode( $timeline ) ) );
		self::assertSame( [], $timeline['observed_stages'] );
		self::assertStringNotContainsString( 'private-secret', json_encode( $timeline ) );
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

	public function test_account_link_rejections_are_not_reported_as_code_failures(): void {
		$rows = [
			$this->row( 1, 'identity.account_link_failed', [ 'reason' => 'ownership_not_confirmed', 'operation' => 'account_link', 'identifier' => 'secret@example.test', 'jwt' => 'secret-token' ] ),
			$this->row( 2, 'identity.account_link_failed', [ 'reason' => 'write_failed', 'operation' => 'account_link' ] ),
		];
		$summary = IssueMonitor::summarize( $rows );
		$categories = array_column( $summary, 'category', 'code' );
		self::assertSame( 'expected_rejection', $categories['identity.account_link_failed:ownership_not_confirmed'] );
		self::assertSame( 'confirmed_failure', $categories['identity.account_link_failed:write_failed'] );
		self::assertStringNotContainsString( 'secret', json_encode( EventEvidence::redact( $rows[0] ) ) );
	}

	public function test_contact_overlap_is_insufficient_evidence_not_a_verified_duplicate(): void {
		$issues = IssueMonitor::summarize( [ $this->row( 1, 'identity.legacy_contact_overlap', [ 'reason' => 'unverified_contact', 'candidate_count' => 2 ] ) ] );
		self::assertSame( 'insufficient_evidence', $issues[0]['category'] );
	}
}
