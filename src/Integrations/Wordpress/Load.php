<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Helper;
use Pinova\Objects\Mobile;
use Pinova\Pinova;
use Pinova\Services\UserService;
use WP_Comment;

class Load {

	protected static ?Load $_instance = null;

	public static function instance(): ?Load {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		new ExportUsers();
		new UsersList();
		new UserProfile();

		add_action( 'login_form_logout', [ Pinova::class, 'logout' ] );
		add_action( 'login_form_login', [ $this, 'wp_login_to_pinova_login' ] );
		add_action( 'login_form_register', [ $this, 'wp_login_to_pinova_login' ] );
		add_action( 'login_form_lostpassword', [ $this, 'wp_login_to_pinova_login' ] );
		add_action( 'login_form_retrievepassword', [ $this, 'wp_login_to_pinova_login' ] );

		add_filter( 'comment_class', [ $this, 'comment_class_nicename_to_id' ], 10, 4 );

		add_filter( 'get_user_metadata', [ $this, 'get_pinova_mobile' ], 10, 3 );
		add_action( 'update_option_pinova_general', [ $this, 'sync_user_settings' ], 10, 2 );
	}

	public function wp_login_to_pinova_login() {
		Helper::redirect_to( Pinova::get_login_url( $_GET['redirect_to'] ?? null ) );
	}

	public function comment_class_nicename_to_id( array $classes, $css_class, int $comment_ID, WP_Comment $comment ): array {

		if ( $comment->user_id ) {

			foreach ( $classes as & $class ) {

				if ( str_contains( $class, 'comment-author-' ) ) {
					$class = 'comment-author-' . sanitize_html_class( $comment->user_id );
				}

			}

		}

		return $classes;
	}

	public function get_pinova_mobile( $value, int $object_id, string $meta_key ): ?string {

		if ( $meta_key != 'pinova_mobile' ) {
			return $value;
		}

		return UserService::get_mobile( $object_id );
	}

	public function sync_user_settings( $old_value, $value ) {

		if ( array_key_exists( 'wordpress_users_can_register', $value ) ) {
			update_option( 'users_can_register', $value['wordpress_users_can_register'] );
		}

		if ( array_key_exists( 'wordpress_default_role', $value ) ) {
			update_option( 'default_role', $value['wordpress_default_role'] );
		}

	}
}