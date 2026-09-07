<?php

namespace Pinova\Channels;

use Exception;
use Pinova\Helpers\JWT;
use Pinova\Pinova;

class Email implements ChannelInterface {

	public static function send_code( string $identifier, int $code ): bool {
		global $phpmailer;

		$tags = [
			'{{logo_url}}'           => Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) ),
			'{{site_url}}'           => site_url(),
			'{{site_name}}'          => get_bloginfo( 'name' ),
			'{{expires_in_minutes}}' => JWT::DEFAULT_TTL / 60,
			'{{code}}'               => $code,
			'{{web_icon}}'           => PINOVA_URL . 'assets/images/icons/web.png',
		];

		$content = file_get_contents( PINOVA_DIR . '/templates/emails/otp.php' );

		if ( $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			$phpmailer->Timeout = 8;
		}

		add_action( 'phpmailer_init', function () {
			global $phpmailer;

			$phpmailer->Timeout = 8;
		} );

		return wp_mail(
			$identifier,
			get_bloginfo( 'name' ) . ' - کد تایید',
			str_replace( array_keys( $tags ), array_values( $tags ), $content ),
			[
				'content-type: text/html',
			]
		);
	}

	public static function send_message( string $identifier, string $message ): bool {
		// todo send email with pinova template
		return false;
	}
}