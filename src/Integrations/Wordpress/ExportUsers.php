<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use WP_User;
use WP_User_Query;

class ExportUsers {

	public function __construct() {
		global $pagenow;

		if ( $pagenow !== 'users.php' ) {
			return;
		}

		new Excel();
		new VCF();
	}

	public static function get_users(): array {
		$args = [
			'number' => - 1,
			'fields' => 'all_with_meta'
		];

		if ( ! empty( $_GET['s'] ) ) {
			$args['search'] = '*' . trim( wp_unslash( $_GET['s'] ) ) . '*';
		}

		if ( isset( $_GET['role'] ) ) {
			$role         = sanitize_text_field( $_GET['role'] );
			$args['role'] = ( $role === 'none' ) ? wp_get_users_with_no_role() : $role;
		}

		if ( isset( $_GET['orderby'] ) ) {
			$args['orderby'] = sanitize_text_field( $_GET['orderby'] );
		}

		if ( isset( $_GET['order'] ) ) {
			$args['order'] = sanitize_text_field( $_GET['order'] );
		}

		$query = new WP_User_Query( $args );

		return $query->get_results();
	}

	public static function contains_latin( string $value ): bool {
		return strpbrk( $value, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ' ) !== false;
	}

	public static function translate_roles( array $roles ): array {
		$wp_roles = wp_roles();

		return array_map( function ( $role ) use ( $wp_roles ) {
			if ( isset( $wp_roles->roles[ $role ]['name'] ) ) {
				return translate_user_role( $wp_roles->roles[ $role ]['name'] );
			}

			return $role;
		}, $roles );
	}

	public static function state_city_name( string $id ): string {

		$country_state = explode( ":", $id );

		if ( count( $country_state ) == 2 ) {
			$id = $country_state[1];
		}

		if ( function_exists( 'PWS' ) && is_numeric( $id ) && method_exists( PWS(), 'get_state' ) ) {

			$state_city = PWS()::get_state( $id );

			if ( ! is_null( $state_city ) ) {
				return $state_city;
			}

			$state_city = PWS()::get_city( $id );

			if ( ! is_null( $state_city ) ) {
				return $state_city;
			}

		}

		if ( function_exists( 'PW' ) && isset( PW()->address::$states[ $id ] ) ) {
			return PW()->address::$states[ $id ];
		}

		return $id;
	}
}