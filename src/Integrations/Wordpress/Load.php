<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Objects\Mobile;
use Pinova\Objects\Identifier;
use Pinova\Identity\IdentityConflictException;
use Pinova\Pinova;
use Pinova\Services\UserService;
use WP_Comment;
use WP_Error;
use WP_User;

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

		add_filter( 'comment_class', [ $this, 'comment_class_nicename_to_id' ], 10, 4 );

		add_filter( 'get_user_metadata', [ $this, 'get_pinova_mobile' ], 10, 3 );
		add_filter( 'authenticate', [ $this, 'authenticate_mobile' ], 19, 3 );
		add_action( 'update_option_pinova_general', [ $this, 'sync_user_settings' ], 10, 2 );
	}

	/**
	 * Resolve a mobile number to the immutable WordPress login before the
	 * standard username/password and security-plugin authentication filters run.
	 *
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public function authenticate_mobile( $user, string $username, string $password ) {
		if ( $user instanceof WP_User || $user instanceof WP_Error || '' === $username || '' === $password ) {
			return $user;
		}

		$identifier = new Identifier( $username );

		if ( ! $identifier->is_mobile() ) {
			return $user;
		}

		try {
			$user_id = UserService::match( $identifier, true, true );
		} catch ( IdentityConflictException $conflict ) {
			return new WP_Error( 'pinova_identity_conflict', __( 'اطلاعات ورود معتبر نمی‌باشد.', 'pinova' ) );
		}
		$matched = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $matched instanceof WP_User ) {
			return $user;
		}

		return wp_authenticate_username_password( null, $matched->user_login, $password );
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

	public function get_pinova_mobile( $value, int $object_id, string $meta_key ) {

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
