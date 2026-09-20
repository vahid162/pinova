<?php

namespace Pinova\Channels;

use Exception;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Pinova;

class Email implements ChannelInterface {

	public static function send_code( string $identifier, int $code ): bool {
		return self::send_code_for_purpose( $identifier, $code, OTP::TYPE_LOGIN );
	}

	public static function send_code_for_purpose( string $identifier, int $code, string $purpose ): bool {
		global $phpmailer;

		$site_name = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$copy      = self::get_purpose_copy( $purpose, $site_name );
		$tags      = [
			'{{logo_url}}'            => esc_url(
				(string) Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) )
			),
			'{{site_url}}'            => esc_url( site_url() ),
			'{{site_name}}'           => esc_html( $site_name ),
			'{{expires_in_minutes}}'  => JWT::DEFAULT_TTL / 60,
			'{{code}}'                => $code,
			'{{web_icon}}'            => esc_url( PINOVA_URL . 'assets/images/icons/web.png' ),
			'{{email_title}}'         => esc_html( $copy['title'] ),
			'{{purpose_intro}}'       => esc_html( $copy['intro'] ),
			'{{purpose_instruction}}' => esc_html( $copy['instruction'] ),
			'{{purpose_footer}}'      => esc_html( $copy['footer'] ),
		];

		$content = file_get_contents( PINOVA_DIR . '/templates/emails/otp.php' );
		if ( ! is_string( $content ) ) {
			return false;
		}

		if ( $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			$phpmailer->Timeout = 8;
		}

		add_action( 'phpmailer_init', function () {
			global $phpmailer;

			$phpmailer->Timeout = 8;
		} );

		return wp_mail(
			$identifier,
			$site_name . ' - ' . $copy['title'],
			str_replace( array_map( 'strval', array_keys( $tags ) ), array_map( 'strval', array_values( $tags ) ), $content ),
			[
				'content-type: text/html',
			]
		);
	}

	/**
	 * @return array{title:string,intro:string,instruction:string,footer:string}
	 */
	private static function get_purpose_copy( string $purpose, string $site_name ): array {
		switch ( $purpose ) {
			case OTP::TYPE_REGISTER:
				return [
					'title'       => __( 'کد ثبت‌نام', 'pinova' ),
					'intro'       => sprintf(
						/* translators: %s: Site name. */
						__( 'برای تکمیل ثبت‌نام خود در %s، کد یک‌بارمصرف زیر را وارد کنید.', 'pinova' ),
						$site_name
					),
					'instruction' => __( 'لطفاً این کد را فقط در مرحلهٔ ثبت‌نام وارد کنید.', 'pinova' ),
					'footer'      => sprintf(
						/* translators: %s: Site name. */
						__( 'این ایمیل برای ثبت‌نام شما در سایت %s ارسال شده است. اگر این درخواست توسط شما انجام نشده، ایمیل را نادیده بگیرید.', 'pinova' ),
						$site_name
					),
				];
			case OTP::TYPE_FORGET:
				return [
					'title'       => __( 'کد بازیابی رمز عبور', 'pinova' ),
					'intro'       => sprintf(
						/* translators: %s: Site name. */
						__( 'برای بازیابی رمز عبور حساب خود در %s، کد یک‌بارمصرف زیر را وارد کنید.', 'pinova' ),
						$site_name
					),
					'instruction' => __( 'لطفاً این کد را فقط در صفحهٔ بازیابی رمز عبور وارد کنید.', 'pinova' ),
					'footer'      => sprintf(
						/* translators: %s: Site name. */
						__( 'این ایمیل برای بازیابی رمز عبور حساب شما در سایت %s ارسال شده است. اگر این درخواست توسط شما انجام نشده، ایمیل را نادیده بگیرید.', 'pinova' ),
						$site_name
					),
				];
			case OTP::TYPE_LOGIN:
			default:
				return [
					'title'       => __( 'کد ورود', 'pinova' ),
					'intro'       => sprintf(
						/* translators: %s: Site name. */
						__( 'برای ورود به حساب کاربری خود در %s، کد یک‌بارمصرف زیر را وارد کنید.', 'pinova' ),
						$site_name
					),
					'instruction' => __( 'لطفاً این کد را فقط در صفحهٔ ورود وارد کنید.', 'pinova' ),
					'footer'      => sprintf(
						/* translators: %s: Site name. */
						__( 'این ایمیل برای ورود به حساب کاربری شما در سایت %s ارسال شده است. اگر این درخواست توسط شما انجام نشده، ایمیل را نادیده بگیرید.', 'pinova' ),
						$site_name
					),
				];
		}
	}

	public static function send_message( string $identifier, string $message ): bool {
		// todo send email with pinova template
		return false;
	}
}
