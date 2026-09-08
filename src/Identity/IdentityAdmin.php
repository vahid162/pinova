<?php

namespace Pinova\Identity;

use Throwable;

class IdentityAdmin {
	private const RESULT_TRANSIENT_PREFIX = 'pinova_identity_admin_result_';

	public static function add_submenu( array $submenus ): array {
		$submenus[30] = [
			'title'      => __( 'شناسه‌ها', 'pinova' ),
			'capability' => 'manage_pinova_identities',
			'slug'       => 'pinova-identities',
			'callback'   => [ self::class, 'render' ],
		];

		ksort( $submenus );

		return $submenus;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_pinova_identities' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ مدیریت شناسه‌های پینوا را ندارید.', 'pinova' ) );
		}

		if ( ! IdentityRepository::is_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'شناسه‌های پینوا', 'pinova' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'جدول‌های شناسه نصب نشده‌اند.', 'pinova' ) . '</p></div></div>';

			return;
		}

		$audit     = IdentityAuditService::audit( false );
		$conflicts = $audit['conflicts'];
		$result    = get_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id() );
		delete_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'شناسه‌های پینوا', 'pinova' ); ?></h1>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice <?php echo ! empty( $result['success'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
					<p><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></p>
					<?php if ( isset( $result['data'] ) ) : ?>
						<pre style="white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'این صفحه فقط audit خواندنی و merge صریح مدیر را ارائه می‌کند. مقدار خام شناسه‌های متعارض نمایش داده یا log نمی‌شود.', 'pinova' ); ?></p>

			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th><?php esc_html_e( 'تعداد کاربران', 'pinova' ); ?></th><td><?php echo esc_html( (string) $audit['total_users'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'چند شناسه روی یک User ID', 'pinova' ); ?></th><td><?php echo esc_html( (string) count( $audit['multiple_identifiers'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'تعارض قطعی', 'pinova' ); ?></th><td><?php echo esc_html( (string) count( $conflicts ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'شباهت احتمالی', 'pinova' ); ?></th><td><?php echo esc_html( (string) count( $audit['possible_matches'] ) ); ?></td></tr>
			</tbody>
			</table>

			<h2><?php esc_html_e( 'تعارض‌های قطعی', 'pinova' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th><?php esc_html_e( 'نوع', 'pinova' ); ?></th><th><?php esc_html_e( 'شناسهٔ ماسک‌شده', 'pinova' ); ?></th><th>User IDs</th></tr></thead>
				<tbody>
				<?php if ( ! $conflicts ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'تعارض قطعی پیدا نشد.', 'pinova' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $conflicts as $conflict ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $conflict['type'] ); ?></td>
							<td><?php echo esc_html( ConflictRepository::mask( (string) $conflict['type'], (string) $conflict['normalized_value'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( 'intval', $conflict['user_ids'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'بررسی یا اجرای merge', 'pinova' ); ?></h2>
			<p><?php esc_html_e( 'حساب مقصد canonical است؛ رمز، نقش و capability آن تغییر نمی‌کند. حساب مبدأ حذف نمی‌شود و sessionهای آن در apply باطل می‌شوند.', 'pinova' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px">
				<input type="hidden" name="action" value="pinova_identity_merge">
				<?php wp_nonce_field( 'pinova_identity_merge' ); ?>
				<table class="form-table">
					<tr><th><label for="source_user_id"><?php esc_html_e( 'User ID مبدأ', 'pinova' ); ?></label></th><td><input required min="1" type="number" id="source_user_id" name="source_user_id"></td></tr>
					<tr><th><label for="target_user_id"><?php esc_html_e( 'User ID مقصد', 'pinova' ); ?></label></th><td><input required min="1" type="number" id="target_user_id" name="target_user_id"></td></tr>
					<tr><th><label for="confirmation"><?php esc_html_e( 'تأیید apply', 'pinova' ); ?></label></th><td><input type="text" id="confirmation" name="confirmation" placeholder="MERGE"><p class="description"><?php esc_html_e( 'برای اجرای واقعی دقیقاً MERGE وارد کنید؛ برای dry-run خالی بگذارید.', 'pinova' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'بررسی / اجرا', 'pinova' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_merge(): void {
		if ( ! current_user_can( 'manage_pinova_identities' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ مدیریت شناسه‌های پینوا را ندارید.', 'pinova' ) );
		}

		check_admin_referer( 'pinova_identity_merge' );

		$source       = absint( $_POST['source_user_id'] ?? 0 );
		$target       = absint( $_POST['target_user_id'] ?? 0 );
		$confirmation = sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) );
		$apply        = 'MERGE' === $confirmation;

		try {
			$data   = $apply
				? IdentityMergeService::apply( $source, $target )
				: IdentityMergeService::dry_run( $source, $target );
			$result = [
				'success' => true,
				'message' => $apply ? __( 'Merge تکمیل شد.', 'pinova' ) : __( 'Dry-run تکمیل شد؛ تغییری اعمال نشد.', 'pinova' ),
				'data'    => $data,
			];
		} catch ( Throwable $throwable ) {
			$result = [
				'success' => false,
				'message' => $throwable->getMessage(),
			];
		}

		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=pinova-identities' ) );
		exit;
	}
}
