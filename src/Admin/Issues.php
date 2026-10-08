<?php

namespace Pinova\Admin;

use Pinova\Logging\IssueMonitor;
use Pinova\Logging\Logger;

/** Administrator projection of the same read-only report exposed to support tools. */
final class Issues {

	public function __construct() {
		add_action( 'admin_post_pinova_export_issues', [ $this, 'export' ] );
		add_action( 'admin_post_pinova_review_issue', [ $this, 'review' ] );
	}

	/** @param array<string,mixed> $input
	 * @return array{created_from:string,created_to:string}
	 */
	public static function dates( array $input ): array {
		$result = [];
		foreach ( [ 'created_from', 'created_to' ] as $key ) {
			$value          = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
			$result[ $key ] = is_string( $value ) && strlen( $value ) <= 10 ? $value : '!';
		}
		return $result;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, bounded report dates.
		$report   = IssueMonitor::report( self::dates( $_GET ) );
		$coverage = $report['coverage'];
		?>
		<section aria-labelledby="pinova-issues-heading">
			<h2 id="pinova-issues-heading"><?php esc_html_e( 'مشکلات و نتیجهٔ بررسی', 'pinova' ); ?></h2>
			<p><?php esc_html_e( 'این گزارش از رخدادهای ثبت‌شده ساخته می‌شود. خرابی مشاهده‌شده لزوماً باگ پینوا نیست؛ علت اصلی باید با شواهد بررسی شود. تأیید سرویس‌دهنده نیز به معنی دریافت پیامک روی گوشی نیست.', 'pinova' ); ?></p>
			<p><?php esc_html_e( 'بازهٔ گزارش حداکثر هفت روز تقویمی UTC است؛ پیش‌فرض، روز جاری است. فیلترهای رخداد، سطح، کاربر و کد پیگیری فقط جدول لاگ‌ها را تغییر می‌دهند و بر این خلاصه اثر ندارند.', 'pinova' ); ?></p>
			<p>
				<?php /* translators: 1: start date; 2: end date; 3: observed row count. */ ?>
				<?php echo esc_html( sprintf( __( 'بازه: %1$s تا %2$s UTC؛ رخدادهای بررسی‌شده: %3$d', 'pinova' ), $report['selected_range'][0], $report['selected_range'][1], $coverage['rows_examined'] ) ); ?>
			</p>
			<?php if ( ! $coverage['complete'] || $coverage['truncated'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'شواهد کامل نیست: بازهٔ نامعتبر، محدودیت خواندن یا خطای ثبت/خواندن وجود دارد. نبودن مشکل در این خروجی، سلامت سیستم را تأیید نمی‌کند. برای بازهٔ شلوغ، تاریخ محدودتری انتخاب کنید.', 'pinova' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'تعدادها فقط مشاهدات ثبت‌شده‌اند؛ سطح ثبت، نمونه‌برداری و زمان نگهداری ممکن است برخی رخدادها را حذف کنند. لاگ‌های وردپرس، ووکامرس و سرور در این گزارش بررسی نمی‌شوند. ناپدیدشدن یک رخداد از بازه یا پایان نگهداری، به معنی رفع آن نیست.', 'pinova' ); ?></p>
			<?php if ( empty( $report['issues'] ) ) : ?>
				<p><strong><?php echo esc_html( $coverage['complete'] ? __( 'در شواهد موجودِ این بازه، مشکل قابل دسته‌بندی پیدا نشد؛ عملکرد بدون خطا اثبات نشده است.', 'pinova' ) : __( 'برای نتیجه‌گیری، شواهد کافی در دسترس نیست.', 'pinova' ) ); ?></strong></p>
			<?php else : ?>
				<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'مشاهده و راه بررسی', 'pinova' ); ?></th><th><?php esc_html_e( 'دسته‌بندی', 'pinova' ); ?></th><th><?php esc_html_e( 'تعداد و زمان UTC', 'pinova' ); ?></th><th><?php esc_html_e( 'شواهد', 'pinova' ); ?></th><th><?php esc_html_e( 'وضعیت بررسی', 'pinova' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $report['issues'] as $issue ) : ?>
						<tr>
							<td><code dir="ltr"><?php echo esc_html( $issue['code'] ); ?></code><p><?php echo esc_html( $issue['investigation_hint'] ); ?></p></td>
							<td><?php echo esc_html( self::category_label( $issue['category'] ) ); ?></td>
							<td><?php echo esc_html( (string) $issue['observed_count'] ); ?><br><time><?php echo esc_html( $issue['first_seen'] ); ?></time><br><time><?php echo esc_html( $issue['last_seen'] ); ?></time></td>
							<td>
								<?php foreach ( $issue['evidence'] as $event ) : ?>
									<?php
									$query = [
										'page'         => 'pinova-logs',
										'event'        => $event['event'],
										'created_from' => $report['selected_range'][0],
										'created_to'   => $report['selected_range'][1],
									];
									if ( '' !== $event['flow_id'] ) {
										$query['flow_id'] = $event['flow_id'];
									} elseif ( '' !== $event['correlation_id'] ) {
										$query['correlation_id'] = $event['correlation_id'];
									}
									?>
									<a href="<?php echo esc_url( add_query_arg( $query, admin_url( 'admin.php' ) ) ); ?>">#<?php echo esc_html( (string) $event['id'] ); ?></a><br>
								<?php endforeach; ?>
							</td>
							<td>
								<p><?php echo esc_html( self::state_label( $issue['state'] ) ); ?></p>
								<?php if ( $coverage['complete'] && ! $coverage['truncated'] && $issue['last_event_id'] >= $issue['review']['through_id'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="pinova_review_issue">
										<input type="hidden" name="issue" value="<?php echo esc_attr( $issue['code'] ); ?>">
										<input type="hidden" name="through_id" value="<?php echo esc_attr( (string) $issue['last_event_id'] ); ?>">
										<input type="hidden" name="expected" value="<?php echo esc_attr( IssueMonitor::review_token( $issue['review'] ) ); ?>">
										<?php self::range_fields( $report ); ?>
										<?php wp_nonce_field( 'pinova_review_issue' ); ?>
										<button class="button" name="state" value="acknowledged"><?php esc_html_e( 'بررسی شد', 'pinova' ); ?></button>
										<button class="button" name="state" value="resolved_unverified"><?php esc_html_e( 'اعلام رفع توسط مدیر', 'pinova' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
			<?php if ( $coverage['valid_range'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pinova_export_issues">
					<?php self::range_fields( $report ); ?>
					<?php wp_nonce_field( 'pinova_export_issues' ); ?>
					<?php submit_button( __( 'دریافت گزارش مشکلات برای بررسی', 'pinova' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $report */
	private static function range_fields( array $report ): void {
		?>
		<input type="hidden" name="created_from" value="<?php echo esc_attr( $report['selected_range'][0] ); ?>">
		<input type="hidden" name="created_to" value="<?php echo esc_attr( $report['selected_range'][1] ); ?>">
		<?php
	}

	public static function category_label( string $category ): string {
		return match ( $category ) {
			'confirmed_failure' => __( 'خرابی مشاهده‌شده؛ علت هنوز تأیید نشده', 'pinova' ),
			'expected_rejection' => __( 'رد درخواست مطابق کنترل‌های سیستم', 'pinova' ),
			default => __( 'شواهد ناکافی برای تشخیص', 'pinova' ),
		};
	}

	public static function state_label( string $state ): string {
		return match ( $state ) {
			'acknowledged' => __( 'بررسی‌شده؛ رفع تأیید نشده', 'pinova' ),
			'resolved_unverified' => __( 'مدیر اعلام رفع کرده؛ آزمون رفع ثبت نشده', 'pinova' ),
			default => __( 'نیازمند بررسی', 'pinova' ),
		};
	}


	public function export(): void {
		$this->export_response();
		exit;
	}

	/** Separate response enables capability/nonce/JSON tests without exit. */
	private function export_response( bool $send_headers = true ): void {
		self::authorize( 'pinova_export_issues' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is checked by authorize above.
		$report = IssueMonitor::report( self::dates( $_POST ) );
		if ( ! $report['coverage']['valid_range'] ) {
			wp_die( esc_html__( 'بازهٔ گزارش نامعتبر است؛ حداکثر هفت روز انتخاب کنید.', 'pinova' ), '', [ 'response' => 400 ] );
		}
		$json = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > 2097152 ) {
			wp_die( esc_html__( 'گزارش قابل تهیه نیست؛ بازهٔ کوتاه‌تری انتخاب کنید.', 'pinova' ), '', [ 'response' => 503 ] );
		}
		if ( $send_headers ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="pinova-issues.json"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Redacted and JSON encoded above.
		Logger::instance()->audit(
			'notice',
			'logging.incident_exported',
			[
				'user_id' => get_current_user_id(),
				'count'   => count( $report['issues'] ),
				'scope'   => 'admin_issues',
				'result'  => $report['coverage']['complete'] ? 'success' : 'failed',
			]
		);
	}

	public function review(): void {
		$this->review_response();
		wp_safe_redirect( admin_url( 'admin.php?page=pinova-logs' ) );
		exit;
	}

	private function review_response(): void {
		self::authorize( 'pinova_review_issue' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize above.
		$input = wp_unslash( $_POST );
		foreach ( [ 'issue', 'state', 'through_id', 'expected' ] as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 160 ) {
				wp_die( esc_html__( 'درخواست بررسی نامعتبر است.', 'pinova' ), '', [ 'response' => 400 ] );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is checked by authorize above.
		$report = IssueMonitor::report( self::dates( $_POST ) );
		if ( ! ctype_digit( $input['through_id'] ) || ! IssueMonitor::review( $report, $input['issue'], $input['state'], (int) $input['through_id'], $input['expected'] ) ) {
			wp_die( esc_html__( 'وضعیت تغییر کرده یا شواهد کامل نیست. صفحه را تازه کنید و دوباره بررسی کنید.', 'pinova' ), '', [ 'response' => 409 ] );
		}
		Logger::instance()->audit(
			'notice',
			'logging.issue_reviewed',
			[
				'user_id' => get_current_user_id(),
				'result'  => $input['state'],
			]
		);
	}

	private static function authorize( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه بررسی گزارش‌های پینوا را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $nonce );
	}
}
