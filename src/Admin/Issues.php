<?php

namespace Pinova\Admin;

use Pinova\Helper;
use Pinova\Integrations\IntegrationSettings;
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

	/** @param array<string,mixed> $input
	 * @return array<string,string>
	 */
	public static function filters( array $input ): array {
		$result = self::dates( $input );
		foreach ( [ 'flow_id' => 32, 'correlation_id' => 36 ] as $key => $length ) {
			$value = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
			$result[ $key ] = is_string( $value ) && $length >= strlen( $value ) ? $value : '!';
		}
		return $result;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, bounded report dates.
		$report   = IssueMonitor::report( self::filters( $_GET ) );
		$coverage = $report['coverage'];
		?>
		<section aria-labelledby="pinova-issues-heading">
			<h2 id="pinova-issues-heading"><?php esc_html_e( 'مشکلات و نتیجهٔ بررسی', 'pinova' ); ?></h2>
			<?php self::render_integrations( $report['integrations'] ); ?>
			<?php self::render_queue_health( $report['queue_health'] ); ?>
			<?php self::render_otp_cleanup( $report['otp_cleanup'] ); ?>
			<p><?php esc_html_e( 'این گزارش از رخدادهای ثبت‌شده ساخته می‌شود. خرابی مشاهده‌شده لزوماً باگ پینوا نیست؛ علت اصلی باید با شواهد بررسی شود. تأیید سرویس‌دهنده نیز به معنی دریافت پیامک روی گوشی نیست.', 'pinova' ); ?></p>
			<p><?php esc_html_e( 'بازهٔ گزارش حداکثر هفت روز تقویمی UTC است؛ پیش‌فرض، روز جاری است. با انتخاب فقط یک شناسهٔ جریان یا کد پیگیری معتبر، خط زمانی همان مورد نمایش داده می‌شود. برای خلاصهٔ کل بازه، هر دو را خالی کنید. فیلتر رخداد، سطح و کاربر فقط جدول لاگ‌ها را تغییر می‌دهد.', 'pinova' ); ?></p>
			<p>
				<?php /* translators: 1: start date; 2: end date; 3: observed row count. */ ?>
				<?php echo esc_html( sprintf( __( 'بازه: %1$s تا %2$s UTC؛ رخدادهای بررسی‌شده: %3$d', 'pinova' ), $report['selected_range'][0], $report['selected_range'][1], $coverage['rows_examined'] ) ); ?>
			</p>
			<?php if ( ! $coverage['complete'] || $coverage['truncated'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'شواهد کامل نیست: بازهٔ نامعتبر، محدودیت خواندن یا خطای ثبت/خواندن وجود دارد. نبودن مشکل در این خروجی، سلامت سیستم را تأیید نمی‌کند. برای بازهٔ شلوغ، تاریخ محدودتری انتخاب کنید.', 'pinova' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'تعدادها فقط مشاهدات ثبت‌شده‌اند؛ سطح ثبت، نمونه‌برداری و زمان نگهداری ممکن است برخی رخدادها را حذف کنند. لاگ‌های وردپرس، ووکامرس و سرور در این گزارش بررسی نمی‌شوند. ناپدیدشدن یک رخداد از بازه یا پایان نگهداری، به معنی رفع آن نیست.', 'pinova' ); ?></p>
			<?php if ( ! $coverage['valid_selector'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'فقط یک شناسهٔ جریان یا یک کد پیگیری معتبر انتخاب کنید؛ ترکیب این دو برای خط زمانی مجاز نیست. فیلترهای ترکیبی جدول لاگ و خروجی رخدادها همچنان مستقل‌اند.', 'pinova' ); ?></p></div>
			<?php elseif ( 'timeline' === $report['mode'] ) : ?>
				<?php self::render_timeline( $report ); ?>
			<?php elseif ( empty( $report['issues'] ) ) : ?>
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

	/** Current queue counts cannot be reviewed or resolved as historical evidence.
	 * @param array<string,bool|int> $snapshot
	 */
	private static function render_queue_health( array $snapshot ): void {
		?>
		<h3><?php esc_html_e( 'وضعیت فعلی صف کد تأیید', 'pinova' ); ?></h3>
		<p><?php esc_html_e( 'این شمارش فقط وضعیت فعلی رکوردهای صف است و به بازهٔ رخدادها وابسته نیست. رسیدگی یا اعلام رفع مشکل در جدول زیر، این رکوردها را تغییر نمی‌دهد؛ نبود رکورد، دریافت پیامک یا رفع خرابی قبلی را ثابت نمی‌کند.', 'pinova' ); ?></p>
		<?php if ( ! $snapshot['read_success'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'خواندن صف انجام نشد؛ تعداد درخواست‌های در انتظار و سلامت صف مشخص نیست.', 'pinova' ); ?></p></div>
		<?php else : ?>
			<?php if ( $snapshot['truncated'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'خواندن صف به سقف بررسی رسیده است؛ شمارش‌ها ممکن است کمتر از مقدار واقعی باشند.', 'pinova' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'وضعیت رکورد', 'pinova' ); ?></th><th><?php esc_html_e( 'تعداد مشاهده‌شدهٔ فعلی', 'pinova' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( [
					'pending'    => __( 'پذیرفته‌شده و در انتظار شروع پردازش', 'pinova' ),
					'expired'    => __( 'پذیرفته‌شده؛ مهلت پیش از شروع پردازش تمام شده', 'pinova' ),
					'reserved'   => __( 'رزروشده؛ هنوز برای ارسال پذیرفته نشده', 'pinova' ),
					'processing' => __( 'علامت‌گذاری‌شده برای پردازش؛ نتیجهٔ نهایی مشخص نیست', 'pinova' ),
					'legacy'     => __( 'رکورد قدیمی؛ پذیرش ارسال مشخص نیست', 'pinova' ),
				] as $key => $label ) :
					?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) $snapshot[ $key ] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h4><?php esc_html_e( 'سن و مالکیت پردازش فعلی', 'pinova' ); ?></h4>
			<table class="widefat striped"><tbody>
			<?php foreach ( [
				'timing_unknown' => __( 'رکوردهای با زمان نامشخص', 'pinova' ),
				'oldest_pending_age_seconds' => __( 'بیشترین سن انتظار از مبدأ معتبر (ثانیه)', 'pinova' ),
				'earliest_pending_remaining_seconds' => __( 'کمترین مهلت باقی‌ماندهٔ انتظار (ثانیه)', 'pinova' ),
				'processing_held' => __( 'پردازش با قفل نگه‌داشته‌شده', 'pinova' ),
				'processing_free_stale' => __( 'قفل آزاد و عمر claim دست‌کم ۶۰ ثانیه', 'pinova' ),
				'processing_free_recent' => __( 'قفل آزاد و claim تازه', 'pinova' ),
				'processing_lock_unknown' => __( 'مالکیت قفل نامشخص', 'pinova' ),
				'processing_age_unknown' => __( 'قفل آزاد با عمر claim نامشخص', 'pinova' ),
				'oldest_processing_age_seconds' => __( 'بیشترین عمر claim پردازش (ثانیه)', 'pinova' ),
			] as $key => $label ) : ?>
				<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( isset( $snapshot[ $key ] ) ? (string) $snapshot[ $key ] : __( 'نامشخص', 'pinova' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><?php esc_html_e( 'عمر claim از آغاز claim محاسبه می‌شود و زمان پذیرش صف نیست. این صفحه قفل را نمی‌گیرد و بازیابی یا ارسال انجام نمی‌دهد.', 'pinova' ); ?></p>
			<?php if ( 0 < $snapshot['expired'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'درخواست پذیرفته‌شده‌ای پیش از شروع پردازش منقضی شده است. این خرابی عملیاتی مشاهده‌شده است؛ علت دقیق را با زمان‌بندی، قفل‌ها و رخدادهای صف بررسی کنید. کد منقضی ارسال نمی‌شود.', 'pinova' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 0 < $snapshot['legacy'] ) : ?>
				<p><?php esc_html_e( 'پذیرش رکوردهای قدیمی قابل تأیید نیست؛ صرف وجود آن‌ها خرابی زمان‌بندی را ثابت نمی‌کند و باعث ارسال مجدد خودکار نمی‌شود.', 'pinova' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** @param array<string,mixed> $snapshot */
	private static function render_otp_cleanup( array $snapshot ): void {
		?>
		<h3><?php esc_html_e( 'سلامت فعلی نگهداری کدهای تأیید', 'pinova' ); ?></h3>
		<?php if ( empty( $snapshot['read_success'] ) ) : ?>
			<p><?php esc_html_e( 'خواندن وضعیت نگهداری انجام نشد؛ نتیجه و زمان‌بندی نامشخص است.', 'pinova' ); ?></p>
		<?php else : ?>
			<p><?php echo esc_html( match ( $snapshot['status'] ) {
				'success' => __( 'آخرین پاک‌سازی موفق گزارش شده است؛ این مشاهده تضمین اجرای بعدی نیست.', 'pinova' ),
				'failed' => __( 'آخرین پاک‌سازی ناموفق گزارش شده است.', 'pinova' ),
				default => __( 'نتیجهٔ پاک‌سازی هنوز مشخص نیست.', 'pinova' ),
			} ); ?></p>
			<?php if ( 0 < $snapshot['at'] ) : ?><p><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $snapshot['at'] ) ); ?> UTC</p><?php endif; ?>
			<?php /* translators: 1: observed deleted count or unknown; 2: finite backlog status; 3: finite continuation status. */ ?>
			<p><?php echo esc_html( sprintf( __( 'حذف مشاهده‌شده: %1$s؛ backlog: %2$s؛ ادامه: %3$s', 'pinova' ), null === $snapshot['deleted'] ? __( 'نامشخص', 'pinova' ) : (string) $snapshot['deleted'], $snapshot['backlog'], $snapshot['continuation'] ) ); ?></p>
			<?php if ( ! $snapshot['cleanup_scheduled'] || $snapshot['cleanup_overdue'] || $snapshot['continuation_missing'] || $snapshot['continuation_overdue'] || 'failed' === $snapshot['continuation'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'زمان‌بندی نگهداری روزانه یا ادامهٔ پاک‌سازی نیاز به بررسی دارد؛ این صفحه چیزی را زمان‌بندی یا پاک نمی‌کند.', 'pinova' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** @param array<string,mixed> $report */
	private static function render_timeline( array $report ): void {
		$timeline = $report['timeline'];
		?>
		<h3><?php esc_html_e( 'خط زمانی رخدادهای مشاهده‌شده', 'pinova' ); ?></h3>
		<p><?php esc_html_e( 'ترتیب جدول بر اساس شناسهٔ ذخیره‌سازی است. observed_at زمان رخداد با دقت ثانیه و created_at زمان ذخیره است؛ فاصلهٔ این دو، تأخیر صف یا پنل محسوب نمی‌شود. کاتالوگ مراحل، توالی اجباری همهٔ کانال‌ها نیست.', 'pinova' ); ?></p>
		<p><?php esc_html_e( 'اتمام ارتباط HTTP، پذیرش کانال، تأیید OTP و ایجاد نشست رخدادهای متفاوت‌اند. تأیید OTP به‌تنهایی ایجاد نشست، بازنشانی رمز، اثبات موبایل یا دریافت روی گوشی را ثابت نمی‌کند. مقدار false در مشاهدات یعنی شاهدی دیده نشد.', 'pinova' ); ?></p>
		<?php if ( empty( $timeline ) ) : ?>
			<p><?php esc_html_e( 'خط زمانی قابل خواندن نیست؛ شواهد کافی در دسترس نیست.', 'pinova' ); ?></p>
		<?php else : ?>
			<?php /* translators: 1: finite linkage status; 2: finite sampled coverage status. */ ?>
			<p><?php echo esc_html( sprintf( __( 'پیوند شواهد: %1$s؛ پوشش: %2$s؛ دریافت گوشی: تأیید نشده', 'pinova' ), $timeline['linkage'], $timeline['coverage'] ) ); ?></p>
			<?php if ( 'ambiguous' === $timeline['linkage'] ) : ?><p><?php esc_html_e( 'بیش از یک جریان برای این کد پیگیری دیده شد؛ گسترش خودکار انجام نشد و فقط رخدادهای همان درخواست نمایش داده می‌شوند.', 'pinova' ); ?></p><?php endif; ?>
			<?php if ( 'sole_flow' === $timeline['linkage'] ) : ?><p><code dir="ltr"><?php echo esc_html( $timeline['expanded_flow_id'] ); ?></code></p><?php endif; ?>
			<?php /* translators: 1: observed finite stage names; 2: unobserved possible stage names. */ ?>
			<p><?php echo esc_html( sprintf( __( 'مراحل مشاهده‌شده: %1$s؛ مراحل مشاهده‌نشده: %2$s', 'pinova' ), implode( ', ', $timeline['observed_stages'] ), implode( ', ', $timeline['unobserved_stages'] ) ) ); ?></p>
			<pre dir="ltr"><?php echo esc_html( (string) wp_json_encode( $timeline['observed_facts'], JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<?php if ( empty( $timeline['events'] ) ) : ?><p><?php esc_html_e( 'رخدادی برای انتخاب حاضر در شواهد نگهداری‌شده دیده نشد؛ نتیجهٔ عملیات نامشخص است.', 'pinova' ); ?></p><?php else : ?>
			<div style="overflow-x:auto"><table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'شناسه و رخداد', 'pinova' ); ?></th><th><?php esc_html_e( 'زمان ذخیره UTC', 'pinova' ); ?></th><th><?php esc_html_e( 'زمان رخداد UTC', 'pinova' ); ?></th><th><?php esc_html_e( 'شواهد پالایش‌شده و مرجع کد', 'pinova' ); ?></th></tr></thead>
				<tbody><?php foreach ( $timeline['events'] as $event ) : ?><tr>
					<td>#<?php echo esc_html( (string) $event['id'] ); ?><br><code dir="ltr"><?php echo esc_html( $event['event'] ); ?></code></td>
					<td><?php echo esc_html( $event['created_at'] ); ?></td>
					<td><?php echo esc_html( isset( $event['context']['observed_at'] ) ? gmdate( 'Y-m-d H:i:s', $event['context']['observed_at'] ) : __( 'نامشخص', 'pinova' ) ); ?></td>
					<td><code dir="ltr"><?php echo esc_html( (string) wp_json_encode( $event['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></code></td>
				</tr><?php endforeach; ?></tbody>
			</table></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** Current health has no historical review action or implied recovery.
	 * @param array<string,mixed> $snapshot
	 */
	private static function render_integrations( array $snapshot ): void {
		?>
		<h3><?php esc_html_e( 'سلامت فعلی هماهنگی‌ها', 'pinova' ); ?></h3>
		<p><?php esc_html_e( 'این وضعیت، قابلیت‌های فعلی را بررسی می‌کند و مستقل از بازهٔ رخدادهاست. فعال‌بودن هماهنگی، موفقیت همهٔ درخواست‌ها یا رفع مشکلات قبلی را اثبات نمی‌کند. شمارهٔ نسخه فقط برای تشخیص محیط نمایش داده می‌شود.', 'pinova' ); ?></p>
		<?php if ( empty( $snapshot ) ) : ?>
			<p><?php esc_html_e( 'وضعیت فعلی هماهنگی قابل بررسی نیست؛ سلامت آن تأیید نشده است.', 'pinova' ); ?></p>
		<?php else : ?>
			<p><time><?php echo esc_html( $snapshot['observed_at'] ); ?></time></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'هماهنگی', 'pinova' ); ?></th><th><?php esc_html_e( 'وضعیت و علت', 'pinova' ); ?></th><th><?php esc_html_e( 'قابلیت‌های در دسترس', 'pinova' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( [
					'wpforo' => 'wpForo',
					'dokan'  => 'Dokan',
				] as $slug => $label ) :
					?>
									<?php $entry = $snapshot[ $slug ]; ?>
					<tr>
						<td><?php echo esc_html( $label ); ?><br><?php echo esc_html( $entry['requested'] ? __( 'گزینه روشن است', 'pinova' ) : __( 'گزینه خاموش است', 'pinova' ) ); ?>
									<?php foreach ( $entry['versions'] as $plugin => $version ) : ?>
										<?php
										if ( '' !== $version ) :
											?>
											<br><code dir="ltr"><?php echo esc_html( $plugin . ' ' . $version ); ?></code><?php endif; ?>
							<?php endforeach; ?>
						</td>
						<td><?php echo esc_html( IntegrationSettings::description_for( $entry ) ); ?><p><code dir="ltr"><?php echo esc_html( $entry['reason'] ); ?></code></p></td>
						<td>
									<?php foreach ( $entry['features'] as $feature => $available ) : ?>
										<?php echo esc_html( self::integration_feature_label( $feature ) ); ?>: <?php echo esc_html( $available ? __( 'در دسترس', 'pinova' ) : __( 'در دسترس نیست', 'pinova' ) ); ?><br>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function integration_feature_label( string $feature ): string {
		return match ( $feature ) {
			'routing' => __( 'هدایت ورود و ثبت‌نام', 'pinova' ),
			'membership' => __( 'هماهنگی عضویت', 'pinova' ),
			'rest' => __( 'راه‌اندازی انجمن در REST', 'pinova' ),
			'conversion' => __( 'تبدیل حساب به فروشنده', 'pinova' ),
			'forum_groups' => __( 'حفظ گروه‌های انجمن', 'pinova' ),
			'reports' => __( 'هماهنگی گزارش سفارش‌ها', 'pinova' ),
			default => __( 'قابلیت ناشناخته', 'pinova' ),
		};
	}

	/** @param array<string,mixed> $report */
	private static function range_fields( array $report ): void {
		?>
		<input type="hidden" name="created_from" value="<?php echo esc_attr( $report['selected_range'][0] ); ?>">
		<input type="hidden" name="created_to" value="<?php echo esc_attr( $report['selected_range'][1] ); ?>">
		<?php if ( in_array( $report['selected_selector']['type'], [ 'flow', 'correlation' ], true ) ) : ?>
			<input type="hidden" name="<?php echo esc_attr( 'flow' === $report['selected_selector']['type'] ? 'flow_id' : 'correlation_id' ); ?>" value="<?php echo esc_attr( $report['selected_selector']['value'] ); ?>">
		<?php endif; ?>
		<?php
	}

	public static function category_label( string $category ): string {
		return match ( $category ) {
			'compatibility_observation' => __( 'محدودیت هماهنگی مشاهده شد؛ خرابی درخواست کاربر ثابت نشده', 'pinova' ),
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
		$report = IssueMonitor::report( self::filters( $_POST ) );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- review_response authorized and validated this range.
		$query = [ 'page' => 'pinova-logs' ] + self::dates( $_POST );
		Helper::redirect_to( add_query_arg( $query, admin_url( 'admin.php' ) ), 'issue_review' );
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
		$report = IssueMonitor::report( self::filters( $_POST ) );
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
