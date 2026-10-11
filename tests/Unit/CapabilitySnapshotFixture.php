<?php

/** Isolated-process contract fixture; never load it into WordPress or real-plugin tests. */
namespace WeDevs\Dokan\Frontend\MyAccount {
	class BecomeAVendor {
		public function __construct() {
			throw new \RuntimeException( 'A health read must not construct this service.' );
		}
		public function become_a_seller_form_handler(): void {}
	}
}

namespace {
	final class PinovaCapabilityMember {
		public int $writes = 0;
		public array $queries = [];
		public bool $complete_each_write = false;
		public function get_status( $id ): string { return 'inactive'; }
		public function synchronize_user( $id ): void { ++$this->writes; }
		public function update_profile_fields( $id, $fields, $permissions = true ): int {
			++$this->writes;
			apply_filters( 'wpforo_before_update_profile_fields', $fields, $id );
			$result = 1;
			foreach ( $this->queries as $query ) {
				$result = $GLOBALS['wpdb']->query( $query );
				if ( $this->complete_each_write ) { do_action( 'wpforo_update_profile_fields', $id, $fields, $result ); }
			}
			return $result;
		}
		public function init_current_user(): void {}
	}
	final class PinovaCapabilityForum {
		public $member;
		public $tables;
		public $usergroup;
		public $perm;
		public $ram_cache;
		public int $initializations = 0;
		public function __construct() {
			$this->member = new PinovaCapabilityMember();
			$this->tables = (object) [ 'profiles' => 'wp_wpforo_profiles' ];
			$this->usergroup = new class() { public function can( $permission ): bool { return false; } };
			$this->perm = new class() { public function user_can_manage_user( $actor, $user ): bool { return false; } };
			$this->ram_cache = new class() { public function reset( $key ): void {} };
		}
		public function init(): void { ++$this->initializations; }
	}
	function get_option( $name, $default = false ) {
		return $GLOBALS['pinova_capability_options'][ $name ] ?? $default;
	}
	function get_user_meta( $id, $key, $single = false ) {
		$rows = $GLOBALS['pinova_capability_meta'][ $id ][ $key ] ?? [];
		return $single ? ( $rows[0] ?? '' ) : $rows;
	}
	function add_user_meta( $id, $key, $value, $unique = false ): bool {
		if ( $unique && get_user_meta( $id, $key ) ) { return false; }
		$GLOBALS['pinova_capability_meta'][ $id ][ $key ][] = $value;
		return true;
	}
	function get_current_user_id(): int { return 0; }
	function get_userdata( $id ) { return $GLOBALS['pinova_capability_users'][ $id ] ?? false; }
	function clean_user_cache( $id ): void {}
	function doing_action( $hook ): bool { return 'after_password_reset' === $hook; }
	function wp_is_serving_rest_request(): bool { return true; }
	function is_wp_error( $value ): bool { return false; }
	function wp_salt( $scheme = 'auth' ): string { return 'pinova-capability-test-salt'; }
	function pinova_capability_callback_key( $callback ): string {
		return is_object( $callback ) ? 'object-' . spl_object_id( $callback ) : ( is_array( $callback ) ? ( is_object( $callback[0] ) ? spl_object_id( $callback[0] ) : $callback[0] ) . '::' . $callback[1] : $callback );
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ): void {
		$GLOBALS['wp_filter'][ $hook ] = $GLOBALS['wp_filter'][ $hook ] ?? (object) [ 'callbacks' => [] ];
		$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ pinova_capability_callback_key( $callback ) ] = [ 'function' => $callback, 'accepted_args' => $accepted ];
	}
	function remove_filter( $hook, $callback, $priority = 10 ): void {
		unset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ pinova_capability_callback_key( $callback ) ] );
	}
	function add_action( $hook, $callback, $priority = 10, $accepted = 1 ): void { add_filter( $hook, $callback, $priority, $accepted ); }
	function remove_action( $hook, $callback, $priority = 10 ): void { remove_filter( $hook, $callback, $priority ); }
	function apply_filters( $hook, $value, ...$args ) {
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$priorities = $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [];
			ksort( $priorities );
			foreach ( $priorities as $entries ) {
				foreach ( $entries as $entry ) { $value = ( $entry['function'] )( ...array_slice( [ $value, ...$args ], 0, $entry['accepted_args'] ) ); }
			}
			return $value;
		} finally { array_pop( $GLOBALS['wp_current_filter'] ); }
	}
	function do_action( $hook, ...$args ): void {
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$priorities = $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [];
			ksort( $priorities );
			foreach ( $priorities as $entries ) {
				foreach ( $entries as $entry ) { ( $entry['function'] )( ...array_slice( $args, 0, $entry['accepted_args'] ) ); }
			}
		} finally { array_pop( $GLOBALS['wp_current_filter'] ); }
	}
	function WPF() {
		if ( ! empty( $GLOBALS['pinova_capability_accessor_failure'] ) ) {
			throw new RuntimeException( 'Private diagnostic payload must not escape.' );
		}
		return $GLOBALS['pinova_capability_forum'];
	}
	function wpforo_url( $path = '', $route = null ): string { return 'https://example.test/community/'; }
	function wpforo_home_url(): string { return 'https://example.test/community/'; }
	function wpforo_setting( $group, $name ): bool { return false; }
	function wpforo_update_usergroup_on_role_change( $id, $role, $old = [] ): void {}
	function dokan(): void { throw new RuntimeException( 'A health read must not resolve this service.' ); }
	function dokan_is_user_seller( $id ): bool { return false; }
	function dokan_get_navigation_url(): string { return 'https://example.test/vendor/'; }
	function wc_get_account_endpoint_url( $endpoint ): string { return 'https://example.test/account/' . $endpoint . '/'; }
	function dokan_user_update_to_seller( $user, $fields ): void {}
	$GLOBALS['pinova_capability_forum'] = new PinovaCapabilityForum();
	defined( 'DB_NAME' ) || define( 'DB_NAME', 'pinova_capability_fixture' );
	$GLOBALS['wpdb'] = new class() {
		public string $usermeta = 'wp_usermeta';
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $parameters = [];
		public array $queries = [];
		public string $profile_status = 'inactive-admin-held';
		public function prepare( $query, ...$args ): string {
			$this->parameters = $args;
			return preg_replace_callback( '/%[ids]/', static function ( $match ) use ( &$args ): string {
				$value = array_shift( $args );
				return '%i' === $match[0] ? '`' . $value . '`' : ( '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", $value ) . "'" );
			}, $query );
		}
		public function get_col( $query ): array { return get_user_meta( $this->parameters[1], $this->parameters[2] ); }
		public function get_var( $query ): string { return '1'; }
		public function suppress_errors( $value = true ): bool { return false; }
		public function query( $query ): int {
			$query = apply_filters( 'query', $query );
			$this->queries[] = $query;
			if ( 'SELECT 0' === $query ) { return 0; }
			if ( preg_match( '/\A\s*UPDATE\b/i', $query ) && preg_match( '/\bwp_wpforo_profiles\b/', $query ) ) {
				if ( str_contains( $query, 'INNER JOIN' ) && 'inactive' !== $this->profile_status ) { return 0; }
				$this->profile_status = 'active';
			}
			return 1;
		}
	};
	$GLOBALS['pinova_capability_options'] = [ 'pinova_integrations' => [ 'wpforo_enabled' => '1', 'dokan_enabled' => '1' ] ];
	$GLOBALS['wp_filter'] = [];
	foreach ( [ 'add_user_role' => 2, 'set_user_role' => 3 ] as $hook => $accepted ) {
		$GLOBALS['wp_filter'][ $hook ] = (object) [ 'callbacks' => [ 99 => [ 'known' => [ 'function' => 'wpforo_update_usergroup_on_role_change', 'accepted_args' => $accepted ] ] ] ];
	}
}
