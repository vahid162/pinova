<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Pinova;
use Pinova\Services\FirewallService;
use Pinova\Services\UserService;
use stdClass;
use WP_Error;
use WP_User;
use WP_User_Query;

class UserProfile {

	public function __construct() {
		global $pagenow;

		if ( ! in_array( $pagenow, [ 'user-new.php', 'profile.php', 'user-edit.php' ] ) ) {
			return;
		}

		add_action( 'show_user_profile', [ $this, 'render_mobile_field' ] );
		add_action( 'edit_user_profile', [ $this, 'render_mobile_field' ] );

		add_action( 'user_profile_update_errors', [ $this, 'validate_mobile_field' ], 10, 3 );

		add_action( 'personal_options_update', [ $this, 'save_mobile_field' ], 20, 1 );
		add_action( 'edit_user_profile_update', [ $this, 'save_mobile_field' ], 20, 1 );

		add_filter( 'user_profile_update_errors', [ $this, 'remove_empty_email_validation' ], 20, 1 );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function new_user_username( string $user_login ): string {
		return ( new Mobile( $user_login ) )->get_sanitized_username();
	}

	/**
	 * @param WP_User $profile_user The current WP_User object.
	 *
	 * @return void
	 */
	public function render_mobile_field( WP_User $profile_user ): void {
		$phone = UserService::get_mobile( $profile_user->ID ) ?: '';

		?>
		<table class="form-table">
			<tr class="user-mobile-wrap">
				<th>
					<label for="user_mobile">
						<?php _e( 'تلفن همراه', 'pinova' ); ?>
						<span class="description">
							<?php _e( '(لازم)', 'pinova' ); ?>
						</span>
					</label>
				</th>
				<td>
					<input class="regular-text ltr" type="tel" name="user_mobile" id="user_mobile" required
					       value="<?php echo esc_attr( $phone ); ?>"
					>
					<p class="description" id="mobile-description">
						<?php esc_html_e( 'تغییر تلفن همراه، نام کاربری وردپرس را تغییر نمی‌دهد. پس از ذخیره، شمارهٔ جدید برای ورود پینوا استفاده می‌شود.', 'pinova' ); ?>
					</p>
				</td>
			</tr>

		</table>
		<?php
	}

	public function validate_mobile( string $mobile, int $user_id, WP_Error $errors ): WP_Error {

		if ( empty( $mobile ) ) {
			$errors->add( 'user_mobile_required', __( 'لطفاً تلفن همراه خود را وارد نمایید.', 'pinova' ) );

			return $errors;
		}

		$mobile = new Identifier( $mobile );

		if ( ! $mobile->is_mobile() ) {
			$errors->add( 'user_mobile_invalid', __( 'لطفا یک تلفن همراه معتبر وارد نمایید.', 'pinova' ) );

			return $errors;
		}

		$is_blocked = FirewallService::is_blocked( $mobile->get_value() );

		if ( $is_blocked ) {
			$errors->add( 'user_mobile_blocked', __( 'تلفن همراه ثبت شده، مسدود می‌باشد.', 'pinova' ) );

			return $errors;
		}

		if ( ! UserService::mobile_is_available_for_user( $mobile->get_value(), $user_id ) ) {

			$errors->add( 'user_mobile_duplicate', sprintf(
				__( 'با تلفن همراه %s یک حساب کاربری وجود دارد، لفطا تلفن همراه دیگری وارد نمایید.', 'pinova' ),
				$mobile->get_value()
			) );

		}

		return $errors;
	}

	/**
	 * @param WP_Error $errors WP_Error object (passed by reference).
	 * @param bool $update Whether this is a user update.
	 * @param stdClass $user User object (passed by reference).
	 *
	 * @return void
	 */
	public function validate_mobile_field( WP_Error $errors, bool $update, stdClass $user ): void {

		if ( ! $update ) {

			if ( ! ( new Mobile( $user->user_login ) )->is_valid() ) {
				$errors->add( 'user_login', __( '<strong>خطا:</strong> شماره تلفن همراه نامعتبر است.', 'pinova' ) );
			}

			return;
		}

		$mobile = sanitize_user( $_POST['user_mobile'] ?? '' );

		$this->validate_mobile( $mobile, $user->ID, $errors );
	}

	/**
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 */
	public function save_mobile_field( int $user_id ): void {

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$mobile = sanitize_user( $_POST['user_mobile'] ?? '' );

		$validation = $this->validate_mobile( $mobile, $user_id, new WP_Error() );

		if ( $validation->has_errors() ) {
			return;
		}

		$identifier = new Identifier( $mobile );

		if ( get_user_meta( $user_id, 'pinova_mobile', true ) === $identifier->get_value() ) {
			return;
		}

		update_user_meta( $user_id, 'pinova_mobile', $identifier->get_value() );
	}

	/**
	 * @param WP_Error $errors WP_Error object (passed by reference).
	 *
	 * @return WP_Error
	 */
	public function remove_empty_email_validation( WP_Error $errors ): WP_Error {

		if ( empty( $_POST['email'] ) ) {
			$errors->remove( 'empty_email' );
			$errors->remove( 'invalid_email' );
		}

		return $errors;

	}

	public function enqueue_scripts( string $hook ): void {

		if ( $hook == 'user-new.php' ) {
			wp_enqueue_script( 'pinova-wp-user-create', PINOVA_URL . 'assets/js/wordpress/user-create.js', [ 'jquery' ], PINOVA_VERSION, [] );
		}

		if ( in_array( $hook, [ 'user-edit.php', 'profile.php' ] ) ) {
			wp_enqueue_script( 'pinova-wp-user-edit', PINOVA_URL . 'assets/js/wordpress/user-edit.js', [ 'jquery' ], PINOVA_VERSION, [] );
		}
	}
}
