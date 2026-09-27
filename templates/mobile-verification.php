<?php
/** Authenticated proof-only form. Place outside other forms. */
defined( 'ABSPATH' ) || exit;
// Navigation only: the REST nonce separately authorizes proof mutations.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$return_target = wp_unslash( $_GET['back_url'] ?? '' );
$return_url    = \Pinova\Integrations\Continuation::validate( $return_target, function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
?>
<section class="pinova-mobile-proof" dir="rtl" data-endpoint="<?php echo esc_url( rest_url( 'pinova/mobile/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-length="<?php echo esc_attr( (string) \Pinova\Services\OTPService::code_length() ); ?>">
	<form>
		<div class="pinova-mobile-proof-content">
			<h2 tabindex="-1"><?php esc_html_e( 'تأیید تلفن همراه حساب', 'pinova' ); ?></h2>
			<p><?php esc_html_e( 'شمارهٔ فعلی یا شمارهٔ جدید را با کد یک‌بارمصرف تأیید کنید. حساب و نام کاربری شما حفظ می‌شود.', 'pinova' ); ?></p>
			<label><?php esc_html_e( 'تلفن همراه', 'pinova' ); ?><input name="mobile" type="tel" inputmode="tel" autocomplete="tel" dir="auto" required></label>
			<div class="pinova-mobile-code" hidden>
				<label><?php esc_html_e( 'کد تأیید', 'pinova' ); ?><input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="<?php echo esc_attr( (string) \Pinova\Services\OTPService::code_length() ); ?>" disabled></label>
			</div>
			<button type="submit"><?php esc_html_e( 'دریافت کد تأیید', 'pinova' ); ?></button>
			<p class="pinova-mobile-countdown" aria-live="off"></p>
			<button class="pinova-mobile-resend" type="button" hidden><?php esc_html_e( 'ارسال دوبارهٔ کد', 'pinova' ); ?></button>
			<button class="pinova-mobile-edit" type="button" hidden><?php esc_html_e( 'ویرایش شماره', 'pinova' ); ?></button>
		</div>
		<p class="pinova-mobile-status" role="status" aria-live="polite"></p>
		<p class="pinova-mobile-busy" role="status" hidden><?php esc_html_e( 'در حال پردازش…', 'pinova' ); ?></p>
	</form>
	<p><a href="<?php echo esc_url( $return_url ); ?>"><?php esc_html_e( 'ادامه', 'pinova' ); ?></a></p>
</section>
