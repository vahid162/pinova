<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Services\UserService;
use Pinova\Services\RateLimitService;
use WP_Error;
use WP_User;

final class Privacy {

	private const BATCH_SIZE = 100;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['pinova'] = [
			'exporter_friendly_name' => __( 'پینوا', 'pinova' ),
			'callback'               => [ self::class, 'export_personal_data' ],
		];

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['pinova'] = [
			'eraser_friendly_name' => __( 'پینوا', 'pinova' ),
			'callback'             => [ self::class, 'erase_personal_data' ],
		];

		return $erasers;
	}

	/**
	 * Export only user-owned profile information and non-sensitive audit facts.
	 * OTP records, credentials, tokens, fingerprints, and log context are excluded.
	 *
	 * @return array{data:array<int, array<string, mixed>>,done:bool}|WP_Error
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ) {
		$user_lookup = self::find_user_by_email( sanitize_email( $email_address ) );
		if ( ! $user_lookup['success'] ) {
			return self::export_error();
		}

		$user = $user_lookup['user'];
		if ( null === $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$page   = max( 1, $page );
		$data   = [];
		$mobile = null;

		if ( 1 === $page ) {
			$mobile_lookup = UserService::get_persisted_mobile_result( $user->ID );
			if ( ! $mobile_lookup['success'] ) {
				return self::export_error();
			}
			$mobile = $mobile_lookup['value'];
		}

		$log_result = LogRepository::export_for_user( $user->ID, $page, self::BATCH_SIZE );
		if ( ! $log_result['success'] ) {
			return self::export_error();
		}
		$logs = $log_result['rows'];

		if ( is_string( $mobile ) && '' !== $mobile ) {
			$data[] = [
				'group_id'    => 'pinova-profile',
				'group_label' => __( 'پروفایل پینوا', 'pinova' ),
				'item_id'     => 'pinova-profile-' . $user->ID,
				'data'        => [
					[
						'name'  => __( 'شماره موبایل', 'pinova' ),
						'value' => $mobile,
					],
				],
			];
		}

		foreach ( $logs as $log ) {
			$data[] = [
				'group_id'    => 'pinova-audit',
				'group_label' => __( 'رخدادهای ممیزی پینوا', 'pinova' ),
				'item_id'     => 'pinova-audit-' . (int) $log['id'],
				'data'        => [
					[
						'name'  => __( 'رخداد', 'pinova' ),
						'value' => (string) $log['event'],
					],
					[
						'name'  => __( 'زمان UTC', 'pinova' ),
						'value' => (string) $log['created_at'],
					],
					[
						'name'  => __( 'شناسه هم‌بستگی', 'pinova' ),
						'value' => (string) $log['correlation_id'],
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => count( $logs ) < self::BATCH_SIZE,
		];
	}

	private static function export_error(): WP_Error {
		return new WP_Error(
			'pinova_privacy_export_failed',
			__( 'خروجی داده‌های پینوا کامل نشد. لطفاً عملیات خروجی را دوباره آغاز کنید.', 'pinova' )
		);
	}

	/**
	 * Erase Pinova-owned profile and live authentication data, then anonymize
	 * operational audit rows without deleting their non-identifying event facts.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		unset( $page );
		$email       = sanitize_email( $email_address );
		$user_lookup = self::find_user_by_email( $email );
		if ( ! $user_lookup['success'] ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [ __( 'بخشی از داده‌های پینوا حذف نشد. لطفاً عملیات پاک‌سازی را دوباره اجرا کنید.', 'pinova' ) ],
				'done'           => false,
			];
		}

		$user = $user_lookup['user'];
		if ( null === $user ) {
			return self::erase_unowned_email_data( $email );
		}

		$mobile_lookup = UserService::get_persisted_mobile_result( $user->ID );
		if ( ! $mobile_lookup['success'] ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [ __( 'بخشی از داده‌های پینوا حذف نشد. لطفاً عملیات پاک‌سازی را دوباره اجرا کنید.', 'pinova' ) ],
				'done'           => false,
			];
		}

		$mobile          = $mobile_lookup['value'];
		$identity_result = self::erasure_identifiers( $user, $email_address, $mobile );
		if ( ! $identity_result['success'] ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [ __( 'بخشی از داده‌های پینوا حذف نشد. لطفاً عملیات پاک‌سازی را دوباره اجرا کنید.', 'pinova' ) ],
				'done'           => false,
			];
		}
		$identifiers      = $identity_result['identifiers'];
		$queue_cleared    = RateLimitService::delete_queued_for_identifiers( $identifiers );
		$otp_result       = self::delete_otp_records( $user->ID, $identifiers );
		$fingerprints     = self::identifier_fingerprints( $identifiers );
		$identifier_types = self::identifier_types( $identifiers );
		$logs             = LogRepository::anonymize_user( $user->ID, self::BATCH_SIZE, $fingerprints, $identifier_types );
		$mobile_result    = [
			'removed' => 0,
			'success' => true,
		];

		if ( $otp_result['success'] && $queue_cleared && $logs['success'] && $logs['done'] ) {
			$mobile_result = self::delete_physical_mobile( $user->ID );
		}

		$success = $otp_result['success'] && $queue_cleared && $mobile_result['success'] && $logs['success'];

		return [
			'items_removed'  => $mobile_result['removed'] > 0 || $otp_result['removed'] > 0 || $logs['processed'] > 0,
			'items_retained' => ! $success,
			'messages'       => $success ? [] : [ __( 'بخشی از داده‌های پینوا حذف نشد. لطفاً عملیات پاک‌سازی را دوباره اجرا کنید.', 'pinova' ) ],
			'done'           => $success && $logs['done'],
		];
	}

	/**
	 * Remove pre-account records after WordPress has approved an erasure request
	 * for an email address that does not currently belong to a WordPress user.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	private static function erase_unowned_email_data( string $email_address ): array {
		$identifiers   = self::email_variants( $email_address );
		$queue_cleared = RateLimitService::delete_queued_for_identifiers( $identifiers );
		$otp_result    = self::delete_otp_records( 0, $identifiers );
		$fingerprints  = self::identifier_fingerprints( $identifiers );
		$logs          = LogRepository::anonymize_user( 0, self::BATCH_SIZE, $fingerprints, self::identifier_types( $identifiers ) );
		$success       = $otp_result['success'] && $queue_cleared && $logs['success'];

		return [
			'items_removed'  => $otp_result['removed'] > 0 || $logs['processed'] > 0,
			'items_retained' => ! $success,
			'messages'       => $success ? [] : [ __( 'بخشی از داده‌های پینوا حذف نشد. لطفاً عملیات پاک‌سازی را دوباره اجرا کنید.', 'pinova' ) ],
			'done'           => $success && $logs['done'],
		];
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>'
			. esc_html__( 'پینوا برای احراز هویت می‌تواند شماره موبایل و رکوردهای موقت OTP را پردازش کند. رکوردهای OTP شامل کد موقت هستند و هرگز در خروجی حریم خصوصی نمایش داده نمی‌شوند.', 'pinova' )
			. '</p><p>'
			. esc_html__( 'برای ارسال کد، شناسه و نشانی IP درخواست تا پایان مهلت کوتاه جریان در صف رمزنگاری‌شده نگهداری می‌شوند و پس از پردازش حذف می‌گردند. درخواست پاک‌سازی تأییدشده این صف را نیز حذف می‌کند.', 'pinova' )
			. '</p><p>'
			. esc_html__( 'رخدادهای عملیاتی پینوا برای مدت محدود با کد رخداد، شناسه هم‌بستگی و اطلاعات زمینه‌ای محدود نگهداری می‌شوند. رمز عبور، OTP، کلید API، توکن، متن پیام و شناسه خام نباید در این گزارش‌ها ذخیره شوند.', 'pinova' )
			. '</p><p>'
			. esc_html__( 'اگر مدیر سایت یک ارائه‌دهنده پیامک، تماس صوتی یا پیام‌رسان را فعال کند، شماره مقصد، محتوای پیام یا OTP و اطلاعات احراز هویت حساب سرویس فقط هنگام درخواست ارسال به همان ارائه‌دهنده منتقل می‌شود. مدیر سایت باید ارائه‌دهندگان فعال و سیاست‌های آنان را در سیاست حریم خصوصی خود اعلام کند.', 'pinova' )
			. '</p>';

		wp_add_privacy_policy_content( 'Pinova', wp_kses_post( $content ) );
	}

	/**
	 * Include only mobile aliases which resolve uniquely to this account under
	 * the same rules used by authentication. They remain excluded from profile
	 * export unless a physical Pinova mobile row exists.
	 *
	 * @return array{success:bool,identifiers:string[]}
	 */
	private static function erasure_identifiers( WP_User $user, string $email_address, ?string $physical_mobile ): array {
		global $wpdb;

		$identifiers   = array_merge( self::email_variants( $user->user_email ), self::email_variants( $email_address ) );
		$mobile_values = [];

		// A native/imported mobile-shaped username is not proof that Pinova owns
		// pre-account records for that number. Only Pinova-created accounts may
		// use their legacy username as an erasure-only mobile alias.
		if ( ( new Mobile( $user->user_login ) )->is_valid() ) {
			$pinova_created = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM %i WHERE `user_id` = %d AND `meta_key` = %s AND `meta_value` = %s LIMIT 1',
					$wpdb->usermeta,
					$user->ID,
					'created_by',
					'pinova'
				)
			);
			if ( self::database_error_present() ) {
				return [
					'success'     => false,
					'identifiers' => [],
				];
			}
			if ( '1' === (string) $pinova_created ) {
				$mobile_values[] = $user->user_login;
			}
		}

		if ( null !== $physical_mobile ) {
			$mobile_values[] = $physical_mobile;
		}

		$meta_keys = array_values( array_filter( array_diff( UserService::mobile_possible_meta_keys(), [ 'pinova_mobile' ] ), 'is_string' ) );
		if ( $meta_keys ) {
			$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
			$values       = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The unpacked alias keys supply the generated placeholders.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only fixed placeholders are generated.
					"SELECT `meta_value` FROM %i WHERE `user_id` = %d AND `meta_key` IN ({$placeholders})",
					...array_merge( [ $wpdb->usermeta, $user->ID ], $meta_keys )
				)
			);
			if ( $wpdb->last_error || ! is_array( $values ) ) {
				return [
					'success'     => false,
					'identifiers' => [],
				];
			}
			$mobile_values = array_merge( $mobile_values, $values );
		}

		$mobile_candidates = [];
		foreach ( $mobile_values as $value ) {
			$mobile = new Mobile( is_scalar( $value ) ? (string) $value : '' );
			if ( ! $mobile->is_valid() ) {
				continue;
			}
			$mobile_candidates[] = $mobile->get_formatted();
		}
		foreach ( array_unique( $mobile_candidates ) as $formatted ) {
			$owner       = UserService::mobile_candidate_ids_result( $formatted );
			$after_erase = null === $physical_mobile ? $owner : UserService::mobile_candidate_ids_result( $formatted, $user->ID );
			if ( ! $owner['success'] || ! $after_erase['success'] ||
				( in_array( $user->ID, $owner['ids'], true ) && 1 !== count( $owner['ids'] ) ) ||
				( in_array( $user->ID, $after_erase['ids'], true ) && 1 !== count( $after_erase['ids'] ) ) ) {
				return [
					'success'     => false,
					'identifiers' => [],
				];
			}
			if ( [ $user->ID ] === $owner['ids'] || [ $user->ID ] === $after_erase['ids'] ) {
				$identifiers[] = $formatted;
			}
		}

		return [
			'success'     => true,
			'identifiers' => array_values( array_unique( array_filter( $identifiers ) ) ),
		];
	}

	/** @return string[] */
	private static function email_variants( string $email_address ): array {
		$email = sanitize_email( $email_address );
		if ( '' === $email || ! is_email( $email ) ) {
			return [];
		}

		return array_values( array_unique( [ $email, strtolower( $email ) ] ) );
	}

	/**
	 * @param string[] $identifiers
	 * @return string[]
	 */
	private static function identifier_fingerprints( array $identifiers ): array {
		$fingerprints = [];
		$logger       = Logger::instance();

		foreach ( $identifiers as $value ) {
			$identifier = new Identifier( $value );
			if ( ! $identifier->is_valid() ) {
				continue;
			}

			$fingerprint = $logger->fingerprint( $identifier->get_value(), $identifier->get_type() );
			if ( '' !== $fingerprint ) {
				$fingerprints[] = $fingerprint;
			}

			if ( $identifier->is_email() ) {
				$legacy_fingerprint = $logger->legacy_fingerprint( $identifier->get_value(), $identifier->get_type() );
				if ( '' !== $legacy_fingerprint ) {
					$fingerprints[] = $legacy_fingerprint;
				}
			}
		}

		return array_values( array_unique( $fingerprints ) );
	}

	/**
	 * @param string[] $identifiers
	 * @return string[]
	 */
	private static function identifier_types( array $identifiers ): array {
		$types = [];

		foreach ( $identifiers as $value ) {
			$identifier = new Identifier( $value );
			if ( $identifier->is_valid() ) {
				$types[] = $identifier->get_type();
			}
		}

		return array_values( array_unique( $types ) );
	}

	/** @return array{user:?WP_User,success:bool} */
	private static function find_user_by_email( string $email_address ): array {
		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `ID` FROM %i WHERE `user_email` = %s LIMIT 1',
				$wpdb->users,
				$email_address
			)
		);
		if ( self::database_error_present() ) {
			return [
				'user'    => null,
				'success' => false,
			];
		}

		if ( null === $user_id ) {
			return [
				'user'    => null,
				'success' => true,
			];
		}

		$user = get_userdata( (int) $user_id );
		if ( self::database_error_present() || ! $user instanceof WP_User ) {
			return [
				'user'    => null,
				'success' => false,
			];
		}

		return [
			'user'    => $user,
			'success' => true,
		];
	}

	/**
	 * @return array{removed:int,success:bool}
	 */
	private static function delete_physical_mobile( int $user_id ): array {
		global $wpdb;

		$meta_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `umeta_id` FROM %i WHERE `user_id` = %d AND `meta_key` = %s LIMIT 1',
				$wpdb->usermeta,
				$user_id,
				'pinova_mobile'
			)
		);

		if ( self::database_error_present() ) {
			return [
				'removed' => 0,
				'success' => false,
			];
		}

		if ( null === $meta_id ) {
			return [
				'removed' => 0,
				'success' => true,
			];
		}

		$removed = delete_user_meta( $user_id, 'pinova_mobile' );

		return [
			'removed' => $removed ? 1 : 0,
			'success' => $removed,
		];
	}

	/**
	 * @param string[] $identifiers
	 * @return array{removed:int,success:bool}
	 */
	private static function delete_otp_records( int $user_id, array $identifiers ): array {
		global $wpdb;

		$table       = $wpdb->prefix . 'pinova_otp';
		$table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( self::database_error_present() ) {
			return [
				'removed' => 0,
				'success' => false,
			];
		}

		if ( $table_found !== $table ) {
			return [
				'removed' => 0,
				'success' => true,
			];
		}

		$identifiers = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $identifiers ),
					static fn( string $identifier ): bool => '' !== $identifier
				)
			)
		);
		$where       = [];
		$values      = [ $table ];
		if ( $user_id > 0 ) {
			$where[]  = '`user_id` = %d';
			$values[] = $user_id;
		}

		if ( $identifiers ) {
			$where[] = '(`user_id` IS NULL AND `identifier` IN (' . implode( ',', array_fill( 0, count( $identifiers ), '%s' ) ) . '))';
			$values  = array_merge( $values, $identifiers );
		}

		if ( ! $where ) {
			return [
				'removed' => 0,
				'success' => true,
			];
		}
		$query = 'DELETE FROM %i WHERE ' . implode( ' OR ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only fixed placeholders assembled above.
		$removed = $wpdb->query( $wpdb->prepare( $query, $values ) );

		return [
			'removed' => false === $removed ? 0 : max( 0, (int) $removed ),
			'success' => false !== $removed,
		];
	}

	/** @phpstan-impure */
	private static function database_error_present(): bool {
		global $wpdb;

		$properties = get_object_vars( $wpdb );
		$error      = $properties['last_error'] ?? '';

		return is_string( $error ) && '' !== $error;
	}
}
