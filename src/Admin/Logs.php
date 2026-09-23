<?php

namespace Pinova\Admin;

use Pinova\Logging\BuildMetadata;
use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;
use Pinova\Logging\SettingsAudit;
use Pinova\Pinova;
use Psr\Log\LogLevel;

final class Logs {

	private const CAPABILITY       = 'manage_options';
	private const EXPORT_MAX_ROWS  = 1000;
	private const EXPORT_MAX_BYTES = 2097152;
	private const LEVELS           = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];

	public function __construct() {
		add_action( 'admin_post_pinova_clear_logs', [ $this, 'clear' ] );
		add_action( 'admin_post_pinova_export_incident', [ $this, 'export_incident' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه مشاهده گزارش‌های پینوا را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters do not change state.
		$raw_page = wp_unslash( $_GET['paged'] ?? '1' );
		$page     = is_scalar( $raw_page ) ? max( 1, min( 1000, absint( $raw_page ) ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters do not change state.
		$level = self::request_level( $_GET );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters do not change state.
		$filters          = self::request_filters( $_GET );
		$data             = '' !== $level && ! in_array( $level, self::LEVELS, true )
			? [ 'rows' => [], 'total' => 0 ]
			: LogRepository::paginate( $page, 50, $level, $filters );
		$pages            = max( 1, (int) ceil( $data['total'] / 50 ) );
		$minimum_level    = (string) Pinova::get_option( 'logging.minimum_level', LogLevel::WARNING );
		$diagnostic_until = (int) Pinova::get_option( 'logging.diagnostic_until', 0 );
		$health           = LogRepository::health();
		$table_exists     = $health['table_exists'];
		$pagination       = paginate_links(
			[
				'base'    => add_query_arg(
					array_merge(
						$filters,
						[
							'page'  => 'pinova-logs',
							'level' => $level,
							'paged' => '%#%',
						]
					),
					admin_url( 'admin.php' )
				),
				'current' => $page,
				'total'   => $pages,
			]
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'گزارش‌های پینوا', 'pinova' ); ?></h1>
			<p>
				<?php
				/* translators: %d: number of days logs are retained. */
				echo esc_html( sprintf( __( 'فقط داده‌های ساختاریافته و بدون شناسهٔ خام ذخیره می‌شوند. نگهداری فعلی: %d روز.', 'pinova' ), LogRepository::retention_days() ) );
				?>
			</p>
			<h2><?php esc_html_e( 'سلامت ثبت گزارش', 'pinova' ); ?></h2>
			<p>
				<?php
				$states = [
					'unavailable'     => __( 'در دسترس نیست', 'pinova' ),
					'degraded'        => __( 'کاهش‌یافته', 'pinova' ),
					'database-backed' => __( 'پایگاه‌داده فعال', 'pinova' ),
					'fallback'        => __( 'مسیر جایگزین در این درخواست استفاده شد', 'pinova' ),
				];
				echo esc_html( $states[ $health['state'] ] ?? $states['unavailable'] );
				?>
				— <?php echo esc_html( $health['cleanup_scheduled'] ? __( 'پاک‌سازی زمان‌بندی شده است', 'pinova' ) : __( 'پاک‌سازی زمان‌بندی نشده است', 'pinova' ) ); ?>
				<?php if ( $health['last_event_id'] > 0 ) : ?>
					<?php /* translators: 1: last event ID; 2: UTC timestamp. */ ?>
					— <?php echo esc_html( sprintf( __( 'آخرین رخداد: #%1$d، %2$s UTC', 'pinova' ), $health['last_event_id'], $health['last_event_at'] ) ); ?>
				<?php endif; ?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: configured minimum PSR-3 log level. */
						__( 'حداقل سطح ثبت فعلی: %s', 'pinova' ),
						$diagnostic_until > time() ? LogLevel::DEBUG : $minimum_level
					)
				);
				?>
			</p>

			<?php if ( ! $table_exists ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'جدول گزارش‌های پینوا در دسترس نیست. نصب یا ارتقای افزونه باید بررسی شود.', 'pinova' ); ?></p></div>
			<?php elseif ( LogLevel::ERROR === $minimum_level && $diagnostic_until <= time() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'سطح ثبت روی Error است؛ رخدادهای Info، Notice و Warning ذخیره نمی‌شوند. تست مدیریتی پیامک با وجود این تنظیم همیشه ثبت می‌شود.', 'pinova' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational redirect flag only. ?>
			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'گزارش‌های قبلی پاک شدند و رخداد مدیریتی پاک‌سازی ثبت شد.', 'pinova' ); ?></p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="pinova-logs" />
				<label for="pinova-log-level"><?php esc_html_e( 'سطح:', 'pinova' ); ?></label>
				<select id="pinova-log-level" name="level">
					<option value=""><?php esc_html_e( 'همه', 'pinova' ); ?></option>
					<?php foreach ( self::LEVELS as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="pinova-log-event"><?php esc_html_e( 'رخداد:', 'pinova' ); ?></label>
				<input id="pinova-log-event" type="text" name="event" maxlength="100" value="<?php echo esc_attr( $filters['event'] ); ?>" />
				<label for="pinova-log-correlation"><?php esc_html_e( 'Correlation ID:', 'pinova' ); ?></label>
				<input id="pinova-log-correlation" type="text" name="correlation_id" maxlength="64" value="<?php echo esc_attr( $filters['correlation_id'] ); ?>" />
				<label for="pinova-log-user"><?php esc_html_e( 'User ID:', 'pinova' ); ?></label>
				<input id="pinova-log-user" type="number" min="1" name="user_id" value="<?php echo esc_attr( $filters['user_id'] ); ?>" />
				<label for="pinova-log-from"><?php esc_html_e( 'از تاریخ (UTC):', 'pinova' ); ?></label>
				<input id="pinova-log-from" type="date" name="created_from" value="<?php echo esc_attr( $filters['created_from'] ); ?>" />
				<label for="pinova-log-to"><?php esc_html_e( 'تا تاریخ (UTC):', 'pinova' ); ?></label>
				<input id="pinova-log-to" type="date" name="created_to" value="<?php echo esc_attr( $filters['created_to'] ); ?>" />
				<?php submit_button( __( 'فیلتر', 'pinova' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pinova_export_incident" />
				<input type="hidden" name="level" value="<?php echo esc_attr( $level ); ?>" />
				<?php foreach ( $filters as $key => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endforeach; ?>
				<?php wp_nonce_field( 'pinova_export_incident' ); ?>
				<p><?php esc_html_e( 'خروجی حداکثر ۷ روز، ۱۰۰۰ رخداد و ۲ مگابایت است. بدون تعیین تاریخ، روز جاری UTC انتخاب می‌شود.', 'pinova' ); ?></p>
				<?php submit_button( __( 'خروجی امن رخداد', 'pinova' ), 'secondary' ); ?>
			</form>

			<table class="widefat striped" style="margin-top: 1rem">
				<thead><tr>
					<th><?php esc_html_e( 'زمان', 'pinova' ); ?></th>
					<th><?php esc_html_e( 'سطح', 'pinova' ); ?></th>
					<th><?php esc_html_e( 'رخداد', 'pinova' ); ?></th>
					<th><?php esc_html_e( 'Correlation ID', 'pinova' ); ?></th>
					<th><?php esc_html_e( 'User ID', 'pinova' ); ?></th>
					<th><?php esc_html_e( 'Context امن', 'pinova' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'هنوز رخدادی مطابق فیلتر و سطح ثبت فعلی ذخیره نشده است.', 'pinova' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $row ) : ?>
						<?php
						$context = self::redact_incident_row( $row )['context'];
						$pretty  = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
						?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( (string) $row['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['level'] ); ?></code></td>
							<td><code><?php echo esc_html( (string) $row['event'] ); ?></code></td>
							<td><code><?php echo esc_html( (string) $row['correlation_id'] ); ?></code></td>
							<td><?php echo $row['user_id'] ? esc_html( (string) $row['user_id'] ) : '&mdash;'; ?></td>
							<td><code dir="ltr"><?php echo esc_html( is_string( $pretty ) ? $pretty : '{}' ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( is_string( $pagination ) && '' !== $pagination ) : ?>
				<?php echo wp_kses_post( $pagination ); ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'همه گزارش‌های فعلی پاک شوند؟', 'pinova' ) ); ?>');">
				<input type="hidden" name="action" value="pinova_clear_logs" />
				<?php wp_nonce_field( 'pinova_clear_logs' ); ?>
				<?php submit_button( __( 'پاک‌سازی گزارش‌ها', 'pinova' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	/** @param array<string,mixed> $input
	 * @return array{event:string,correlation_id:string,user_id:string,created_from:string,created_to:string}
	 */
	private static function request_filters( array $input ): array {
		$filters = [];
		foreach ( [ 'event' => 100, 'correlation_id' => 64, 'user_id' => 20, 'created_from' => 10, 'created_to' => 10 ] as $key => $max_length ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filters are read-only; export checks its nonce separately.
			$value           = wp_unslash( array_key_exists( $key, $input ) ? $input[ $key ] : '' );
			$filters[ $key ] = ( is_string( $value ) || is_int( $value ) ) && $max_length >= strlen( (string) $value )
				? (string) $value
				: '!';
		}

		return $filters;
	}

	/** @param array<string,mixed> $input */
	private static function request_level( array $input ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; export checks its nonce separately.
		$level = wp_unslash( array_key_exists( 'level', $input ) ? $input['level'] : '' );
		return is_string( $level ) && ( '' === $level || in_array( $level, self::LEVELS, true ) ) ? $level : '!';
	}

	/**
	 * Historical rows may predate the deny-by-default logger. Export only vetted facts.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function redact_incident_row( array $row ): array {
		$stored = json_decode( (string) ( $row['context'] ?? '{}' ), true );
		$stored = is_array( $stored ) ? $stored : [];
		$safe   = [];
		foreach ( [
			'attempt'         => 1000,
			'attempts'        => 1000,
			'candidate_count' => 1000,
			'count'           => 1000,
			'duration_ms'     => 600000,
			'http_status'     => 599,
			'retry_after'     => 86400,
		] as $key => $maximum ) {
			if ( isset( $stored[ $key ] ) && is_int( $stored[ $key ] ) && 0 <= $stored[ $key ] && $maximum >= $stored[ $key ] ) {
				$safe[ $key ] = $stored[ $key ];
			}
		}
		if ( isset( $stored['identifier_type'] ) && in_array( $stored['identifier_type'], [ 'email', 'mobile', 'username', 'ip' ], true ) ) {
			$safe['identifier_type'] = $stored['identifier_type'];
		}
		if ( isset( $stored['otp_type'] ) && in_array( $stored['otp_type'], [ 'login', 'register', 'forget' ], true ) ) {
			$safe['otp_type'] = $stored['otp_type'];
		}
		if ( 'settings.updated' === ( $row['event'] ?? '' ) ) {
			$keys = isset( $stored['changed_keys'] ) && is_array( $stored['changed_keys'] ) ? SettingsAudit::safe_keys( $stored['changed_keys'] ) : [];
			if ( $keys ) {
				$safe['changed_keys'] = $keys;
			}
			if ( isset( $stored['operation'] ) && in_array( $stored['operation'], [ 'pinova_general', 'pinova_sms', 'pinova_messengers', 'pinova_zohal', 'pinova_design', 'pinova_logging', 'pinova_advanced' ], true ) ) {
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

		return [
			'created_at'     => preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) ( $row['created_at'] ?? '' ) ) ? $row['created_at'] : '',
			'level'          => in_array( $row['level'] ?? '', [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ], true ) ? $row['level'] : 'unknown',
			'event'          => preg_match( '/\A[a-z0-9_.-]{1,100}\z/', (string) ( $row['event'] ?? '' ) ) ? $row['event'] : 'logging.unknown_event',
			'correlation_id' => preg_match( '/\A[A-Za-z0-9-]{1,64}\z/', (string) ( $row['correlation_id'] ?? '' ) ) ? $row['correlation_id'] : '',
			'user_id'        => max( 0, (int) ( $row['user_id'] ?? 0 ) ),
			'context'        => $safe,
		];
	}

	public function export_incident(): void {
		$this->export_incident_response();
		exit;
	}

	/** Emit the response separately so integration tests can inspect it without terminating PHPUnit. */
	private function export_incident_response(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه خروجی‌گرفتن از گزارش‌های پینوا را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'pinova_export_incident' );
		$filters = self::request_filters( $_POST );
		$today   = gmdate( 'Y-m-d' );
		if ( '' === $filters['created_from'] && '' === $filters['created_to'] ) {
			$filters['created_from'] = $today;
			$filters['created_to']   = $today;
		}
		if ( ! LogRepository::valid_filters( $filters ) ) {
			wp_die( esc_html__( 'فیلترهای خروجی نامعتبر هستند.', 'pinova' ), '', [ 'response' => 400 ] );
		}
		$from                    = strtotime( $filters['created_from'] . ' UTC' );
		$to                      = strtotime( $filters['created_to'] . ' UTC' );
		if ( false === $from || false === $to || gmdate( 'Y-m-d', $from ) !== $filters['created_from'] || gmdate( 'Y-m-d', $to ) !== $filters['created_to'] || $from > $to || $to - $from >= 7 * DAY_IN_SECONDS || $to > time() ) {
			wp_die( esc_html__( 'بازهٔ خروجی باید معتبر و حداکثر هفت روز باشد.', 'pinova' ), '', [ 'response' => 400 ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The admin export nonce was verified above.
		$level = self::request_level( $_POST );
		if ( '' !== $level && ! in_array( $level, self::LEVELS, true ) ) {
			wp_die( esc_html__( 'فیلتر سطح خروجی نامعتبر است.', 'pinova' ), '', [ 'response' => 400 ] );
		}
		$batch = LogRepository::incident_batch( $filters, 0, 100, $level );
		if ( ! $batch['success'] ) {
			wp_die( esc_html__( 'گزارش‌ها برای خروجی در دسترس نیستند.', 'pinova' ), '', [ 'response' => 503 ] );
		}

		$metadata = [
			'generated_at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version'      => PINOVA_VERSION,
			'build'               => BuildMetadata::info(),
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'php_version'         => PHP_VERSION,
			'minimum_level'       => (string) Pinova::get_option( 'logging.minimum_level', LogLevel::WARNING ),
			'level_filter'        => $level,
			'retention_days'      => LogRepository::retention_days(),
			'selected_range'      => [ $filters['created_from'], $filters['created_to'] ],
		];
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pinova-incident-' . $today . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		$prefix = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$prefix = is_string( $prefix ) ? substr( $prefix, 0, -1 ) . ',"events":[' : '{"events":[';
		echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded above.
		$bytes     = strlen( $prefix );
		$count     = 0;
		$cursor    = 0;
		$truncated = false;
		$complete  = true;
		do {
			foreach ( $batch['rows'] as $row ) {
				$cursor  = (int) $row['id'];
				$encoded = wp_json_encode( self::redact_incident_row( $row ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $encoded ) ) {
					$complete = false;
					continue;
				}
				$addition = ( $count > 0 ? ',' : '' ) . $encoded;
				if ( $count >= self::EXPORT_MAX_ROWS || $bytes + strlen( $addition ) + 48 > self::EXPORT_MAX_BYTES ) {
					$truncated = true;
					break 2;
				}
				echo $addition; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded and redacted above.
				$bytes += strlen( $addition );
				++$count;
			}
			if ( count( $batch['rows'] ) < 100 ) {
				break;
			}
			$batch = LogRepository::incident_batch( $filters, $cursor, 100, $level );
			if ( ! $batch['success'] ) {
				$complete = false;
				break;
			}
		} while ( $batch['rows'] );
		echo '],"truncated":' . ( $truncated ? 'true' : 'false' ) . ',"complete":' . ( $complete ? 'true' : 'false' ) . '}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed JSON suffix.
		Logger::instance()->audit(
			'notice',
			'logging.incident_exported',
			[
				'user_id' => get_current_user_id(),
				'count'   => $count,
				'scope'   => 'admin_incident',
				'result'  => $complete ? 'success' : 'failed',
			]
		);
	}

	public function clear(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه پاک‌سازی گزارش‌های پینوا را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'pinova_clear_logs' );
		LogRepository::delete_all();
		Logger::instance()->audit(
			'warning',
			'logging.cleared',
			[
				'user_id'   => get_current_user_id(),
				'operation' => 'manual_clear',
			]
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'pinova-logs',
					'cleared' => 1,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
