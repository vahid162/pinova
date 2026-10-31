<?php

namespace Pinova\Logging;

use Pinova\Integrations\IntegrationSettings;
use Pinova\Services\RateLimitService;
use Throwable;

/** A bounded observation report, never an authentication or repair authority. */
final class IssueMonitor {

	public const MAX_ROWS            = 1000;
	private const TIMELINE_MAX_BYTES = 1900000;
	private const FAILURE_EVENTS     = [ 'admin.sms_test_failed', 'auth.request_failed', 'auth.redirect_failed', 'identity.mobile_conflict', 'integration.operation_failed', 'logging.write_failed', 'otp.channel_send_failed', 'otp.delivery_failed', 'user.export_failed' ];
	private const REJECTION_EVENTS   = [ 'auth.password_failed', 'auth.logout_rejected', 'auth.password_reset_failed', 'auth.session_failed', 'otp.verify_failed', 'security.rate_limited' ];
	private const OPTION_PREFIX      = 'pinova_issue_review_';

	/** @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	public static function report( array $filters = [] ): array {
		$selector = self::selector( $filters );
		$today    = gmdate( 'Y-m-d' );
		$from     = $filters['created_from'] ?? '';
		$to       = $filters['created_to'] ?? '';
		if ( '' === $from && '' === $to ) {
			$from = $today;
			$to   = $today;
		}
		$filters = [
			'created_from' => $from,
			'created_to'   => $to,
		];
		$valid   = $selector['valid'] && LogRepository::valid_filters( $filters ) && '' !== $from && '' !== $to
			&& strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) < 7 * DAY_IN_SECONDS && $to <= $today;
		$report  = [
			'schema'            => 'pinova.issues.v1',
			'mode'              => 'none' === $selector['type'] ? 'summary' : 'timeline',
			'selected_selector' => $selector,
			'generated_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'build'             => BuildMetadata::info(),
			'selected_range'    => [ $from, $to ],
			'coverage'          => [
				'valid_range'          => $valid,
				'valid_selector'       => $selector['valid'],
				'complete'             => false,
				'truncated'            => false,
				'context_truncated'    => false,
				'rows_examined'        => 0,
				'max_rows'             => self::MAX_ROWS,
				'snapshot_event_id'    => 0,
				'observed_counts_only' => true,
				'sampling'             => 'source_slot_and_site_limits',
				'external_logs'        => 'not_inspected',
				'physical_delivery'    => 'not_verified',
			],
			'health'            => [],
			'queue_health'      => [
				'read_success' => false,
				'truncated'    => false,
				'pending'      => 0,
				'expired'      => 0,
				'reserved'     => 0,
				'processing'   => 0,
				'legacy'       => 0,
			],
			'integrations'      => [],
			'otp_cleanup'       => [],
			'diagnostics'       => [],
			'diagnostic_budget' => [],
			'timeline'          => [],
			'issues'            => [],
		];
		try {
			// Current capability health is independent of selected historical evidence.
			$report['integrations'] = IntegrationSettings::snapshot();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
		try {
			// Queue state is a current, read-only snapshot, not historical issue evidence.
			$report['queue_health'] = RateLimitService::queue_health();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
		try {
			$report['otp_cleanup'] = OTPHealthState::snapshot();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
		if ( ! $valid ) {
			return $report;
		}
		try {
			$report['health']                    = LogRepository::health();
			$report['coverage']['minimum_level'] = 'unavailable';
			foreach ( [ 'debug', 'info', 'notice', 'warning', 'error' ] as $level ) {
				if ( Logger::instance()->is_enabled( $level ) ) {
					$report['coverage']['minimum_level'] = $level;
					break;
				}
			}
			$report['coverage']['retention_days']   = LogRepository::retention_days();
			$report['diagnostics']                  = Logger::diagnostics_status();
			$report['coverage']['diagnostic_until'] = $report['diagnostics']['until'];
			$report['diagnostic_budget']            = EventThrottle::diagnostics_snapshot();
			if ( 'summary' !== $report['mode'] ) {
				$filters[ 'flow' === $selector['type'] ? 'flow_id' : 'correlation_id' ] = $selector['value'];
				return self::timeline_report( $report, $filters );
			}
			$rows   = [];
			$cursor = 0;
			do {
				$batch = LogRepository::incident_batch( $filters, $cursor, 100 );
				if ( ! $batch['success'] ) {
					break;
				}
				foreach ( $batch['rows'] as $row ) {
					if ( count( $rows ) >= self::MAX_ROWS ) {
						$report['coverage']['truncated'] = true;
						break 2;
					}
					$cursor = (int) $row['id'];
					if ( 0 === $report['coverage']['snapshot_event_id'] ) {
						$report['coverage']['snapshot_event_id'] = $cursor;
					}
					$report['coverage']['context_truncated'] = $report['coverage']['context_truncated'] || ! empty( $row['context_truncated'] );
					$rows[]                                  = $row;
				}
				if ( count( $batch['rows'] ) < 100 ) {
					$report['coverage']['complete'] = ! $report['coverage']['context_truncated'];
					break;
				}
			} while ( true );
			$report['coverage']['rows_examined'] = count( $rows );
			$report['issues']                    = self::summarize( $rows );
			wp_prime_option_caches( array_map( static fn( array $issue ): string => self::OPTION_PREFIX . hash( 'sha256', $issue['code'] ), $report['issues'] ) );
			foreach ( $report['issues'] as &$issue ) {
				$issue['investigation_hint'] = self::explanation( $issue['code'] );
				$review                      = self::review_state( $issue['code'] );
				$issue['review']             = $review;
				$issue['state']              = self::state( $issue['last_event_id'], $review );
			}
			unset( $issue );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$report['coverage']['complete'] = false;
		}
		return $report;
	}

	/** Timeline selectors are deliberately stricter than legacy viewer filters.
	 * @param array<string,mixed> $filters
	 * @return array{valid:bool,type:string,value:string}
	 */
	public static function selector( array $filters ): array {
		$flow        = $filters['flow_id'] ?? '';
		$correlation = $filters['correlation_id'] ?? '';
		if ( ! is_string( $flow ) || ! is_string( $correlation ) || ( '' !== $flow && '' !== $correlation )
			|| ( '' !== $flow && ! preg_match( '/\A[a-f0-9]{32}\z/', $flow ) )
			|| ( '' !== $correlation && LogRepository::redact_correlation_id( $correlation ) !== $correlation ) ) {
			return [
				'valid' => false,
				'type'  => 'invalid',
				'value' => '',
			];
		}
		return [
			'valid' => true,
			'type'  => '' !== $flow ? 'flow' : ( '' !== $correlation ? 'correlation' : 'none' ),
			'value' => '' !== $flow ? $flow : $correlation,
		];
	}

	/** @param array<string,mixed> $report
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	private static function timeline_report( array $report, array $filters ): array {
		$lookup                              = LogRepository::timeline_lookup( $filters );
		$report['coverage']['lookup_rows']   = $lookup['lookup_rows'];
		$report['coverage']['rows_examined'] = $lookup['lookup_rows'];
		if ( ! $lookup['success'] ) {
			return $report;
		}
		$linkage = 'flow' === $report['selected_selector']['type'] ? 'exact_flow' : 'request_only';
		if ( 'correlation' === $report['selected_selector']['type'] ) {
			if ( 1 === count( $lookup['flows'] ) ) {
				$filters['flow_id'] = $lookup['flows'][0];
				$linkage            = 'sole_flow';
			} elseif ( 1 < count( $lookup['flows'] ) ) {
				$linkage = 'ambiguous';
			}
		}
		$report['coverage']['snapshot_event_id'] = $lookup['snapshot_event_id'];
		$rows                                    = [];
		$cursor                                  = $lookup['snapshot_event_id'] + 1;
		$remaining                               = self::MAX_ROWS - $lookup['lookup_rows'];
		do {
			$limit = min( 100, $remaining );
			$batch = LogRepository::timeline_batch( $filters, $cursor, $limit );
			if ( ! $batch['success'] ) {
				break;
			}
			foreach ( $batch['rows'] as $row ) {
				$cursor                                  = (int) $row['id'];
				$report['coverage']['context_truncated'] = $report['coverage']['context_truncated'] || ! empty( $row['context_truncated'] );
				$rows[]                                  = $row;
			}
			$remaining                          -= count( $batch['rows'] );
			$report['coverage']['rows_examined'] = count( $rows ) + $lookup['lookup_rows'];
			if ( count( $batch['rows'] ) < $limit ) {
				$report['coverage']['complete'] = ! $report['coverage']['context_truncated'] && 'ambiguous' !== $linkage;
				break;
			}
			if ( 0 >= $remaining ) {
				// No extra sentinel row: lookup witnesses and events share the cap.
				$report['coverage']['truncated'] = true;
				break;
			}
		} while ( true );
		$report['timeline']                     = self::timeline( $rows );
		$report['timeline']['linkage']          = $linkage;
		$report['timeline']['expanded_flow_id'] = 'sole_flow' === $linkage ? $filters['flow_id'] : '';
		if ( $report['timeline']['byte_truncated'] ) {
			$report['coverage']['truncated'] = true;
			$report['coverage']['complete']  = false;
		}
		$report['timeline']['coverage'] = $report['coverage']['complete'] ? 'sampled' : 'incomplete';
		return $report;
	}

	/** Project only observed facts, ordered by storage ID rather than flush time.
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	public static function timeline( array $rows ): array {
		$rows = array_slice( $rows, 0, self::MAX_ROWS );
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		$result = [
			'events'                             => [],
			'observed_stages'                    => [],
			'unobserved_stages'                  => [],
			'catalogue_is_not_required_sequence' => true,
			'physical_delivery'                  => 'not_verified',
			'byte_truncated'                     => false,
			'observed_facts'                     => [
				'transport_completed'     => false,
				'channel_accepted'        => false,
				'channel_rejected'        => false,
				'channel_outcome_unknown' => false,
				'otp_verified'            => false,
				'session_created'         => false,
			],
		];
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$event       = EventEvidence::redact( $row );
			$event['id'] = max( 0, (int) ( $row['id'] ?? 0 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This bounded projection also runs in pure unit tests.
			$encoded = json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded ) || self::TIMELINE_MAX_BYTES < $bytes + strlen( $encoded ) + 1 ) {
				$result['byte_truncated'] = true;
				break;
			}
			$bytes             += strlen( $encoded ) + 1;
			$result['events'][] = $event;
			$stage              = $event['context']['stage'] ?? '';
			if ( DiagnosticContext::EVENT === $event['event'] && '' !== $stage ) {
				$result['observed_stages'][] = $stage;
				if ( 'provider_completed' === $stage ) {
					$result['observed_facts']['transport_completed'] = true;
				} elseif ( 'channel_completed' === $stage ) {
					$key = match ( $event['context']['result'] ?? '' ) {
						'accepted' => 'channel_accepted', 'rejected' => 'channel_rejected', 'unknown' => 'channel_outcome_unknown', default => '',
					};
					if ( '' !== $key ) {
						$result['observed_facts'][ $key ] = true;
					}
				}
			}
			if ( 'otp.verified' === $event['event'] ) {
				$result['observed_facts']['otp_verified'] = true;
			} elseif ( 'auth.session_created' === $event['event'] ) {
				$result['observed_facts']['session_created'] = true;
			}
		}
		$result['observed_stages']   = array_values( array_unique( $result['observed_stages'] ) );
		$result['unobserved_stages'] = array_values( array_diff( DiagnosticContext::stage_names(), $result['observed_stages'] ) );
		return $result;
	}

	/** @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function summarize( array $rows ): array {
		$groups   = [];
		$queued   = [];
		$outcomes = [];
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			$event       = EventEvidence::redact( $row );
			$event['id'] = max( 0, (int) ( $row['id'] ?? 0 ) );
			$flow        = $event['flow_id'];
			if ( '' !== $flow ) {
				if ( 'otp.queued' === $event['event'] ) {
					$queued[ $flow ] = $event;
				} elseif ( in_array( $event['event'], [ 'otp.created', 'otp.delivery_failed', 'otp.delivery_skipped' ], true ) || ( 'auth.request_failed' === $event['event'] && 'queued_otp' === ( $event['context']['operation'] ?? '' ) ) ) {
					$outcomes[ $flow ] = true;
				}
			}
			$classification = self::classify( $event['event'], $event['context']['reason'] ?? '', $event['context']['status'] ?? '' );
			if ( null !== $classification ) {
				self::add_observation( $groups, $event, $classification );
			}
		}
		foreach ( $queued as $flow => $event ) {
			if ( ! isset( $outcomes[ $flow ] ) && '' !== $event['created_at'] && strtotime( $event['created_at'] . ' UTC' ) <= time() - 300 ) {
				self::add_observation(
					$groups,
					$event,
					[
						'code'     => 'otp.outcome_unknown',
						'category' => 'insufficient_evidence',
					]
				);
			}
		}
		return array_values( $groups );
	}

	/** @return array{code:string,category:string}|null */
	private static function classify( string $event, string $reason, string $status = '' ): ?array {
		$code = $event . ( '' !== $reason ? ':' . $reason : '' );
		if ( in_array( $event, [ 'integration.wpforo_status_changed', 'integration.dokan_status_changed' ], true ) ) {
			return in_array( $status, [ 'partial', 'unavailable' ], true ) ? [
				'code'     => $code,
				'category' => 'compatibility_observation',
			] : null;
		}
		if ( 'identity.legacy_contact_overlap' === $event ) {
			return [
				'code'     => $code,
				'category' => 'insufficient_evidence',
			];
		}
		if ( 'identity.account_link_failed' === $event ) {
			return [
				'code'     => $code,
				'category' => in_array( $reason, [ 'write_failed', 'operation_failed' ], true ) ? 'confirmed_failure' : ( '' === $reason ? 'insufficient_evidence' : 'expected_rejection' ),
			];
		}
		if ( in_array( $event, self::FAILURE_EVENTS, true ) ) {
			return [
				'code'     => $code,
				'category' => 'confirmed_failure',
			];
		}
		if ( 'otp.delivery_skipped' === $event ) {
			if ( 'already_completed' === $reason ) {
				return null;
			}
			$category = in_array( $reason, [ 'duplicate_records', 'invalid_payload', 'expired' ], true ) ? 'confirmed_failure' : ( in_array( $reason, [ 'policy_rejected' ], true ) ? 'expected_rejection' : 'insufficient_evidence' );
			return [
				'code'     => $code,
				'category' => $category,
			];
		}
		if ( 'otp.queue_deferred' === $event ) {
			return [
				'code'     => $code,
				'category' => 'insufficient_evidence',
			];
		}
		if ( in_array( $event, self::REJECTION_EVENTS, true ) ) {
			$category = '' !== $reason || 'security.rate_limited' === $event ? 'expected_rejection' : 'insufficient_evidence';
			if ( in_array( $reason, [ 'reset_key_generation_failed', 'reset_pipeline_failed', 'session_exception' ], true ) ) {
				$category = 'confirmed_failure';
			} elseif ( in_array( $reason, [ 'record_not_found', 'claim_rejected' ], true ) ) {
				$category = 'insufficient_evidence';
			}
			return [
				'code'     => $code,
				'category' => $category,
			];
		}
		return 'logging.unknown_event' === $event ? [
			'code'     => $event,
			'category' => 'insufficient_evidence',
		] : null;
	}

	/** @param array<string,array<string,mixed>> $groups
	 * @param array<string,mixed> $event
	 * @param array{code:string,category:string} $classification
	 */
	private static function add_observation( array &$groups, array $event, array $classification ): void {
		$code = $classification['code'];
		if ( ! isset( $groups[ $code ] ) ) {
			$groups[ $code ] = $classification + [
				'component'      => explode( '.', $event['event'] )[0],
				'observed_count' => 0,
				'first_seen'     => $event['created_at'],
				'last_seen'      => $event['created_at'],
				'last_event_id'  => 0,
				'evidence'       => [],
				'diagnosis'      => 'root_cause_unconfirmed',
			];
		}
		$group = &$groups[ $code ];
		++$group['observed_count'];
		$group['first_seen']    = min( $group['first_seen'], $event['created_at'] );
		$group['last_seen']     = max( $group['last_seen'], $event['created_at'] );
		$group['last_event_id'] = max( $group['last_event_id'], $event['id'] );
		if ( count( $group['evidence'] ) < 3 ) {
			// User IDs are unnecessary for grouped reports; follow the exact event link if authorized.
			unset( $event['user_id'] );
			$group['evidence'][] = $event;
		}
	}

	public static function explanation( string $code ): string {
		return match ( true ) {
			str_starts_with( $code, 'identity.legacy_contact_overlap' ) => __( 'شمارهٔ حساب تازه در اطلاعات تماس حساب قدیمی دیده شده است. این تطابق اثبات مالکیت نیست؛ مشتری باید از مسیر حساب قبلی یا بررسی مستقل پشتیبانی ادامه دهد. خریدی منتقل نشده است.', 'pinova' ),
			str_starts_with( $code, 'integration.operation_failed' ) => __( 'یکی از عملیات هماهنگی تکمیل نشد. علت مجاز و وضعیت قابلیت‌های فعلی را بررسی کنید؛ این رخداد به‌تنهایی باگ پینوا یا تغییر مجوز حساب را ثابت نمی‌کند.', 'pinova' ),
			str_starts_with( $code, 'integration.wpforo_status_changed' ), str_starts_with( $code, 'integration.dokan_status_changed' ) => __( 'در زمان مشاهده، بخشی از هماهنگی در دسترس نبود. قابلیت و علت ثبت‌شده را بررسی کنید؛ وضعیت فعلی جداگانه نمایش داده می‌شود و به معنی تأیید رفع این مشاهده نیست.', 'pinova' ),
			'auth.session_failed:session_exception' === $code => __( 'مرحلهٔ ورود یا یکی از callbackهای آن خطا داد. رخدادهای همان درخواست را بررسی کنید؛ این مشاهده به‌تنهایی وضعیت نهایی Cookie مرورگر را ثابت نمی‌کند.', 'pinova' ),
			'auth.session_failed:policy_rejected' === $code => __( 'پس از تأیید کد، سیاست حساب اجازهٔ ورود نداد. تغییر نقش، مسدودیت یا محدودیت افزونهٔ دیگر را بررسی کنید؛ این رد به‌تنهایی باگ نیست.', 'pinova' ),
			'auth.request_failed:otp_completion_failed' === $code => __( 'کد پذیرفته شد، اما عملیات حساب تکمیل نشد. رکوردهای موبایل و مرحلهٔ ساخت حساب یا ثبت تأیید را بررسی کنید؛ این رخداد به معنی کد اشتباه نیست.', 'pinova' ),
			'auth.request_failed:mobile_evidence_unavailable' === $code => __( 'خواندن وضعیت تأیید موبایل انجام نشد. رکوردهای امنیتی تکراری یا خطای پایگاه داده را بررسی کنید؛ مصرف کد در این مرحله انجام نشده است.', 'pinova' ),
			'auth.request_failed:queue_schedule_failed' === $code => __( 'درخواست در صف برای ارسال پذیرفته نشد. ذخیرهٔ صف و زمان‌بندی وردپرس را بررسی کنید؛ این مشاهده علت دقیق خرابی را مشخص نمی‌کند.', 'pinova' ),
			str_starts_with( $code, 'otp.queue_deferred' ) => __( 'کارگر نتوانست این نوبت را در اختیار بگیرد. قفل، لغو یا خطای پایگاه داده ممکن است علت باشد؛ درخواست پایان‌یافته یا ارسال ناموفق تأیید نشده است.', 'pinova' ),
			'otp.outcome_unknown' === $code => __( 'نتیجهٔ این صف در بازه دیده نشد. محدودیت ثبت، رد سیاست یا نتیجهٔ خارج از بازه ممکن است علت باشد؛ ارسال ناموفق ثابت نشده است.', 'pinova' ),
			str_contains( $code, 'token_expired' ), str_contains( $code, ':expired' ) && str_starts_with( $code, 'otp.verify_failed' ) => __( 'اعتبار کد هنگام تأیید پایان یافته بود. این رخداد به‌تنهایی نشانهٔ باگ نیست؛ زمان درخواست و ارسال را مقایسه کنید.', 'pinova' ),
			str_contains( $code, 'queue_expired' ), 'otp.delivery_skipped:expired' === $code => __( 'مهلت صف پیش از ارسال تمام شد. زمان اجرای cron و مدت انتظار صف را بررسی کنید؛ کد منقضی نباید ارسال شود.', 'pinova' ),
			str_starts_with( $code, 'otp.channel_send_failed' ), str_starts_with( $code, 'otp.delivery_failed' ), 'admin.sms_test_failed' === $code => __( 'ارسال در یک یا چند کانال موفق نبود. تنظیمات درگاه و پاسخ سرویس‌دهنده را بررسی کنید؛ کانال دیگری ممکن است موفق شده باشد.', 'pinova' ),
			str_contains( $code, 'provider_outcome_unknown' ) => __( 'نتیجهٔ ارسال قبلی قطعی نیست؛ برای جلوگیری از ارسال تکراری، همان کد دوباره ارسال نشد.', 'pinova' ),
			str_starts_with( $code, 'auth.password_failed' ) => __( 'ورود با رمز یا سیاست حساب پذیرفته نشد. رد درخواست به‌تنهایی خرابی افزونه را نشان نمی‌دهد.', 'pinova' ),
			str_starts_with( $code, 'otp.verify_failed' ) => __( 'تأیید OTP انجام نشد. علت مجاز ثبت‌شده و رخدادهای همان جریان را بررسی کنید؛ نبود رکورد، علت قطعی را مشخص نمی‌کند.', 'pinova' ),
			'identity.mobile_conflict' === $code => __( 'شناسهٔ موبایل به بیش از یک حساب مرتبط است. مالکیت حساب‌ها باید بررسی شود؛ ادغام خودکار انجام نمی‌شود.', 'pinova' ),
			default => __( 'رخداد و علت ثبت‌شده را همراه شواهد همان درخواست بررسی کنید. این دسته‌بندی، تشخیص قطعی علت یا تأیید رفع مشکل نیست.', 'pinova' ),
		};
	}

	/** @return array{state:string,through_id:int,at:int} */
	public static function review_state( string $code ): array {
		$value = get_option( self::OPTION_PREFIX . hash( 'sha256', $code ), [] );
		$value = is_array( $value ) ? $value : [];
		return [
			'state'      => in_array( $value['state'] ?? '', [ 'acknowledged', 'resolved_unverified' ], true ) ? $value['state'] : 'open',
			'through_id' => max( 0, (int) ( $value['through_id'] ?? 0 ) ),
			'at'         => max( 0, (int) ( $value['at'] ?? 0 ) ),
		];
	}

	/** @param array{state:string,through_id:int,at:int} $review */
	public static function state( int $last_id, array $review ): string {
		return $last_id > $review['through_id'] ? 'open' : $review['state'];
	}

	/** A nonce/capability-protected caller passes a complete, unfiltered fresh report.
	 * @param array<string,mixed> $report
	 */
	public static function review( array $report, string $code, string $state, int $through_id, string $expected ): bool {
		global $wpdb;
		if ( 'summary' !== ( $report['mode'] ?? 'summary' ) || ! current_user_can( 'manage_options' ) || ! in_array( $state, [ 'acknowledged', 'resolved_unverified' ], true ) || empty( $report['coverage']['complete'] ) || ! empty( $report['coverage']['truncated'] ) ) {
			return false;
		}
		$found = false;
		foreach ( $report['issues'] as $issue ) {
			if ( $issue['code'] === $code && $through_id === $issue['last_event_id'] ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}
		$name     = self::OPTION_PREFIX . hash( 'sha256', $code );
		$previous = get_option( $name, false );
		$current  = self::review_state( $code );
		if ( $through_id < $current['through_id'] || ! hash_equals( self::review_token( $current ), $expected ) ) {
			return false;
		}
		$next = [
			'state'      => $state,
			'through_id' => $through_id,
			'at'         => time(),
		];
		if ( false === $previous ) {
			return add_option( $name, $next, '', false );
		}
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, maybe_serialize( $next ), $name, maybe_serialize( $previous ) ) );
		if ( 1 === $changed ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		return false;
	}

	/** @param array{state:string,through_id:int,at:int} $review */
	public static function review_token( array $review ): string {
		return hash( 'sha256', $review['state'] . ':' . $review['through_id'] . ':' . $review['at'] );
	}
}
