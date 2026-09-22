<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Logging\LogRepository;
use Pinova\Services\UserService;
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
	 * @return array{data:array<int, array<string, mixed>>,done:bool}
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user instanceof WP_User ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$page = max( 1, $page );
		$data = [];

		if ( 1 === $page ) {
			$mobile = UserService::get_persisted_mobile( $user->ID );
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
		}

		$logs = LogRepository::export_for_user( $user->ID, $page, self::BATCH_SIZE );
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

	/**
	 * Erase Pinova-owned profile and live authentication data, then anonymize
	 * operational audit rows without deleting their non-identifying event facts.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', sanitize_email( $email_address ) );

		if ( ! $user instanceof WP_User ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}

		$mobile           = UserService::get_persisted_mobile( $user->ID );
		$otp_result       = self::delete_otp_records( $user->ID, [ $email_address, $mobile ?? '' ] );
		$mobile_result    = self::delete_physical_mobile( $user->ID );

		$logs    = LogRepository::anonymize_user( $user->ID, self::BATCH_SIZE );
		$success = $otp_result['success'] && $mobile_result['success'] && $logs['success'];

		return [
			'items_removed'  => $mobile_result['removed'] > 0 || $otp_result['removed'] > 0 || $logs['processed'] > 0,
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
			. esc_html__( 'رخدادهای عملیاتی پینوا برای مدت محدود با کد رخداد، شناسه هم‌بستگی و اطلاعات زمینه‌ای محدود نگهداری می‌شوند. رمز عبور، OTP، کلید API، توکن، متن پیام و شناسه خام نباید در این گزارش‌ها ذخیره شوند.', 'pinova' )
			. '</p><p>'
			. esc_html__( 'اگر مدیر سایت یک ارائه‌دهنده پیامک، تماس صوتی یا پیام‌رسان را فعال کند، شماره مقصد، محتوای پیام یا OTP و اطلاعات احراز هویت حساب سرویس فقط هنگام درخواست ارسال به همان ارائه‌دهنده منتقل می‌شود. مدیر سایت باید ارائه‌دهندگان فعال و سیاست‌های آنان را در سیاست حریم خصوصی خود اعلام کند.', 'pinova' )
			. '</p>';

		wp_add_privacy_policy_content( 'Pinova', wp_kses_post( $content ) );
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

		if ( '' !== $wpdb->last_error ) {
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
		if ( '' !== $wpdb->last_error ) {
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
		$query       = 'DELETE FROM %i WHERE `user_id` = %d';
		$values      = [ $table, $user_id ];

		if ( $identifiers ) {
			$query   .= ' OR (`user_id` IS NULL AND `identifier` IN (' . implode( ',', array_fill( 0, count( $identifiers ), '%s' ) ) . '))';
			$values   = array_merge( $values, $identifiers );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only fixed placeholders assembled above.
		$removed = $wpdb->query( $wpdb->prepare( $query, $values ) );

		return [
			'removed' => false === $removed ? 0 : max( 0, (int) $removed ),
			'success' => false !== $removed,
		];
	}
}
