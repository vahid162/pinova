<?php

namespace Pinova\Integrations\Wordpress;

use WP_User_Query;

class UsersList {

	public function __construct() {
		global $pagenow;

		if ( $pagenow !== 'users.php' ) {
			return;
		}

		add_filter( 'manage_users_columns', [ $this, 'add_registered_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'render_registered_column' ], 10, 3 );
		add_filter( 'manage_users_sortable_columns', [ $this, 'sortable_registered_column' ] );
		add_action( 'pre_get_users', [ $this, 'handle_registered_sorting' ] );
	}

	public function add_registered_column( array $columns ): array {

		$columns['registered'] = __( 'زمان ثبت نام', 'pinova' );

		return $columns;
	}

	public function render_registered_column( $value, string $column_name, int $user_id ) {

		if ( $column_name === 'registered' ) {

			$user = get_user( $user_id );

			return wp_date( 'Y/m/d H:i', strtotime( $user->get( 'user_registered' ) ) );
		}

		return $value;
	}

	public function sortable_registered_column( array $columns ): array {

		$columns['registered'] = 'registered';

		return $columns;
	}

	public function handle_registered_sorting( WP_User_Query $query ): void {

		if ( empty( $_GET['orderby'] ?? '' ) ) {

			$_GET['orderby'] = 'registered';
			$_GET['order']   = 'desc';
			$query->set( 'orderby', 'registered' );
			$query->set( 'order', 'DESC' );

		}

	}
}
