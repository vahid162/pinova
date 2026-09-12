<?php

namespace Pinova\Admin;

use Pinova\Logging\Logger;
use Pinova\Logging\LogRepository;

final class Logs {

	private const CAPABILITY = 'manage_options';

	public function __construct() {
		add_action( 'admin_post_pinova_clear_logs', [ $this, 'clear' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه مشاهده گزارش‌های پینوا را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters do not change state.
		$page = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters do not change state.
		$level = sanitize_key( wp_unslash( $_GET['level'] ?? '' ) );
		$data  = LogRepository::paginate( $page, 50, $level );
		$pages = max( 1, (int) ceil( $data['total'] / 50 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'گزارش‌های پینوا', 'pinova' ); ?></h1>
			<p>
				<?php
				/* translators: %d: number of days logs are retained. */
				echo esc_html( sprintf( __( 'فقط داده‌های ساختاریافته و بدون شناسهٔ خام ذخیره می‌شوند. نگهداری فعلی: %d روز.', 'pinova' ), LogRepository::retention_days() ) );
				?>
			</p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational redirect flag only. ?>
			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'گزارش‌های قبلی پاک شدند و رخداد مدیریتی پاک‌سازی ثبت شد.', 'pinova' ); ?></p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="pinova-logs" />
				<label for="pinova-log-level"><?php esc_html_e( 'سطح:', 'pinova' ); ?></label>
				<select id="pinova-log-level" name="level">
					<option value=""><?php esc_html_e( 'همه', 'pinova' ); ?></option>
					<?php foreach ( [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ] as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'فیلتر', 'pinova' ), 'secondary', '', false ); ?>
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
					<tr><td colspan="6"><?php esc_html_e( 'گزارشی یافت نشد.', 'pinova' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $row ) : ?>
						<?php
						$context = json_decode( (string) $row['context'], true );
						$context = is_array( $context ) ? $context : [];
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

			<?php
			echo wp_kses_post(
				paginate_links(
					[
						'base'    => add_query_arg(
							[
								'page'  => 'pinova-logs',
								'level' => $level,
								'paged' => '%#%',
							],
							admin_url( 'admin.php' )
						),
						'current' => $page,
						'total'   => $pages,
					]
				)
			);
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'همه گزارش‌های فعلی پاک شوند؟', 'pinova' ) ); ?>');">
				<input type="hidden" name="action" value="pinova_clear_logs" />
				<?php wp_nonce_field( 'pinova_clear_logs' ); ?>
				<?php submit_button( __( 'پاک‌سازی گزارش‌ها', 'pinova' ), 'delete' ); ?>
			</form>
		</div>
		<?php
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
