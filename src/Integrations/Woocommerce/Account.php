<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use Pinova\Services\FirewallService;
use Pinova\Services\UserService;
use stdClass;
use WC_Customer;
use WP_Error;

class Account {

	public function __construct() {
		add_filter( 'woocommerce_save_account_details_required_fields', [ $this, 'make_email_optional' ] );
		add_action( 'woocommerce_edit_account_form_start', [ $this, 'render_mobile_field' ] );
		add_action( 'woocommerce_save_account_details_errors', [ $this, 'validate_mobile_field' ], 10, 2 );
		add_action( 'woocommerce_save_account_details', [ $this, 'save_mobile' ] );
	}

	public function make_email_optional( $required_fields ) {

		unset( $required_fields['account_email'] );

		return $required_fields;
	}

	public function render_mobile_field() {
		$user_id = get_current_user_id();
		$mobile  = get_user_meta( $user_id, 'pinova_mobile', true );
		?>

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="pinova_mobile">
				<?php _e( 'تلفن همراه', 'pinova' ); ?>
				<span class="required" aria-hidden="true">*</span>
			</label>
			<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text"
			       name="pinova_mobile" id="pinova_mobile" required
			       value="<?php echo esc_attr( $mobile ); ?>"
			>
			<span id="pinova_mobile_description">
				با تغییر تلفن همراه،
				<strong>نام کاربری شما تغییر خواهد کرد.</strong>
				لطفاً پس از تغییر، با تلفن همراه جدید وارد شوید.
			</span>
		</p>
		<br>

		<?php
	}

	public function validate_mobile_field( WP_Error $errors, stdClass $user ) {

		$mobile = sanitize_text_field( $_POST['pinova_mobile'] ?? '' );

		if ( empty( $mobile ) ) {
			$errors->add( 'mobile_required', __( 'لطفاً تلفن همراه خود را وارد نمایید.', 'pinova' ) );

			return;
		}

		$identifier = new Identifier( $mobile );

		if ( ! $identifier->is_mobile() ) {
			$errors->add( 'mobile_invalid', __( 'لطفا یک تلفن همراه معتبر را ثبت کنید.', 'pinova' ) );

			return;
		}

		if ( FirewallService::is_blocked( $identifier->get_value() ) ) {
			$errors->add( 'mobile_blocked', __( 'تلفن همراه وارد شده، مسدود می‌باشد.', 'pinova' ) );

			return;
		}

		$existing_user_id = UserService::get_by_mobile( $identifier->get_value() );

		if ( is_int( $existing_user_id ) && $existing_user_id !== $user->ID ) {
			$errors->add( 'mobile_duplicate', sprintf(
				__( 'با تلفن همراه %s یک حساب کاربری وجود دارد، لطفاً تلفن همراه دیگری وارد نمایید.', 'pinova' ),
				$identifier->get_value()
			) );
		}
	}

	public function save_mobile( int $user_id ) {

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$mobile = sanitize_user( $_POST['pinova_mobile'] ?? '' );

		$identifier = new Identifier( $mobile );

		if ( get_user_meta( $user_id, 'pinova_mobile', true ) === $identifier->get_value() ) {
			return;
		}

		UserService::update_username( $user_id, $identifier->get_value() );
	}
}
