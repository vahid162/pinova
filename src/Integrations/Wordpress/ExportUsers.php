<?php

namespace Pinova\Integrations\Wordpress;

use Pinova\Integrations\Wordpress\Exports\Excel;
use Pinova\Integrations\Wordpress\Exports\VCF;
use WP_User;
use WP_User_Query;

class ExportUsers {

	public function __construct() {
		new Excel();
		new VCF();
	}

	public static function get_users( int $limit = 10000 ): array {
		$args = [
			'number' => 1,
			'fields' => 'all_with_meta'
		];

		if ( ! empty( $_GET['s'] ) ) {
			$args['search'] = '*' . trim( wp_unslash( $_GET['s'] ) ) . '*';
		}

		if ( isset( $_GET['role'] ) ) {
			$role = sanitize_key( wp_unslash( $_GET['role'] ) );

			if ( 'none' === $role ) {
				$args['include'] = array_map( 'intval', wp_get_users_with_no_role() );
			} elseif ( isset( wp_roles()->roles[ $role ] ) ) {
				$args['role'] = $role;
			}
		}

		if ( isset( $_GET['orderby'] ) ) {
			$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ) );

			if ( in_array( $orderby, [ 'ID', 'login', 'email', 'name', 'registered', 'display_name' ], true ) ) {
				$args['orderby'] = $orderby;
			}
		}

		if ( isset( $_GET['order'] ) ) {
			$order = strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) );

			if ( in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
				$args['order'] = $order;
			}
		}

		$query = new WP_User_Query( $args );

		if ( $query->get_total() > $limit ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: maximum number of users in one XLSX/VCF export. */
					__( 'تعداد کاربران بیشتر از سقف امن خروجی (%d) است. فیلتر دقیق‌تری انتخاب کنید.', 'pinova' ),
					$limit
				)
			);
		}

		$users      = [];
		$batch_size = 500;

		for ( $offset = 0; $offset < $query->get_total(); $offset += $batch_size ) {
			$args['number'] = min( $batch_size, $limit - $offset );
			$args['offset'] = $offset;
			$args['count_total'] = false;
			$batch = ( new WP_User_Query( $args ) )->get_results();

			if ( ! $batch ) {
				break;
			}

			$users = array_merge( $users, $batch );
		}

		return $users;
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

		$pws = function_exists( 'PWS' ) ? PWS() : null;

		if ( is_numeric( $id ) && is_object( $pws ) && method_exists( $pws, 'get_state' ) ) {

			$state_city = $pws->get_state( $id );

			if ( ! is_null( $state_city ) ) {
				return $state_city;
			}

			$state_city = $pws->get_city( $id );

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
