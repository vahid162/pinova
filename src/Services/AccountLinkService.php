<?php

namespace Pinova\Services;

use Pinova\Logging\EventThrottle;
use Pinova\Logging\Logger;
use Pinova\Objects\Identifier;
use RuntimeException;
use Throwable;
use WP_Session_Tokens;

/** Reconcile an empty OTP account into a proven legacy account; never transfer commerce data. */
final class AccountLinkService {
	public const RETIRED_META = '_pinova_mobile_alias_retired';
	public const JOURNAL_META = '_pinova_account_link_journal';

	private const REASONS = [
		1 => 'not_authorized', 2 => 'unsupported_accounts', 3 => 'identity_conflict',
		4 => 'stale_state', 5 => 'ownership_not_confirmed', 6 => 'active_delivery',
		7 => 'write_failed', 8 => 'unsupported_storage', 9 => 'data_changed',
	];

	/** Null is an unreadable/malformed security record, never an available alias. */
	public static function retired_state( int $user_id ): ?bool {
		try {
			$value = self::meta( $user_id, self::RETIRED_META );
			return null === $value ? false : ( '1' === $value ? true : null );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
	}

	/** A retired account cannot keep an old cookie racing session invalidation. */
	public static function current_user( $user_id ): int {
		return $user_id && false !== self::retired_state( (int) $user_id ) ? 0 : (int) $user_id;
	}

	/** Read-only, privileged, bounded preview; contains no raw identifiers or credentials. */
	public static function plan( int $canonical, int $source ): array {
		self::authorize( $canonical, $source );
		$journal = self::journal( $canonical );
		if ( null !== $journal ) {
			self::require_state( ( $journal['source'] ?? null ) === $source, 2 );
			$state = self::snapshot( $canonical, $source );
			return self::preview( $canonical, $source, $state, (string) $journal['state'], $journal );
		}
		$state = self::inspect( $canonical, $source );
		return self::preview( $canonical, $source, $state, 'ready', null );
	}

	public static function apply( int $canonical, int $source, string $expected, bool $ownership_confirmed ): void {
		try {
			self::authorize( $canonical, $source );
			self::require_state( $ownership_confirmed, 5 );
			$journal = self::journal( $canonical );
			if ( null !== $journal ) {
				self::require_state( 'applied' === $journal['state'] && $source === $journal['source'] && hash_equals( $journal['plan'], $expected ), 4 );
				self::check_applied( $canonical, $source, $journal );
				return;
			}
			$mobile = (string) UserService::get_mobile( $source );
			self::locked( $canonical, $source, $mobile, static function () use ( $canonical, $source, $expected, $mobile ): void {
				$state = self::inspect( $canonical, $source );
				self::require_state( hash_equals( self::preview( $canonical, $source, $state, 'ready', null )['fingerprint'], $expected ), 4 );
				self::quiesce( $canonical, $source, $mobile );
				self::transaction( $canonical, $source, static function () use ( $canonical, $source, $expected, $mobile, $state ): void {
					self::require_state( hash_equals( $state['digest'], self::inspect( $canonical, $source )['digest'] ), 4 );
					self::require_state( MobileVerificationService::revoke( $canonical ) && MobileVerificationService::revoke( $source ), 7 );
					$retired_id = add_user_meta( $source, self::RETIRED_META, '1', true );
					$mobile_id  = add_user_meta( $canonical, 'pinova_mobile', $mobile, true );
					self::require_state( is_int( $retired_id ) && is_int( $mobile_id ), 7 );
					self::require_state( UserService::get_by_mobile( $mobile ) === $canonical, 7 );
					$journal = [
						'state' => 'applied', 'source' => $source, 'actor' => get_current_user_id(), 'at' => time(),
						'ownership_confirmed' => true, 'plan' => $expected, 'safety' => $state['safety'], 'mobile_id' => $mobile_id, 'retired_id' => $retired_id,
						'mobile_digest' => MobileVerificationService::identity_digest( $canonical, new Identifier( $mobile ) ),
					];
					self::require_state( false !== add_user_meta( $canonical, self::JOURNAL_META, wp_json_encode( $journal ), true ), 7 );
				} );
			} );
			self::finish( $canonical, $source, 'identity.account_linked' );
		} catch ( Throwable $throwable ) {
			self::observe_failure( $canonical, $source, $throwable );
			throw $throwable;
		}
	}

	public static function rollback( int $canonical, int $source, string $expected ): void {
		try {
			self::authorize( $canonical, $source );
			$journal = self::journal( $canonical );
			self::require_state( null !== $journal && $source === $journal['source'], 4 );
			if ( 'rolled_back' === $journal['state'] ) {
				return;
			}
			$mobile = (string) UserService::get_mobile( $canonical );
			self::locked( $canonical, $source, $mobile, static function () use ( $canonical, $source, $expected, $mobile, $journal ): void {
				self::require_state( hash_equals( self::plan( $canonical, $source )['fingerprint'], $expected ), 4 );
				self::check_applied( $canonical, $source, $journal );
				self::require_state( hash_equals( $journal['safety'], self::snapshot( $canonical, $source )['safety'] ), 9 );
				self::quiesce( $canonical, $source, $mobile );
				self::transaction( $canonical, $source, static function () use ( $canonical, $source, $journal ): void {
					self::check_applied( $canonical, $source, $journal );
					self::require_state( hash_equals( $journal['safety'], self::snapshot( $canonical, $source )['safety'] ), 9 );
					self::require_state( MobileVerificationService::revoke( $canonical ) && MobileVerificationService::revoke( $source ), 7 );
					self::require_state( delete_metadata_by_mid( 'user', $journal['mobile_id'] ) && delete_metadata_by_mid( 'user', $journal['retired_id'] ), 7 );
					$updated = $journal;
					$updated['state'] = 'rolled_back';
					$updated['rollback_actor'] = get_current_user_id();
					$updated['rollback_at'] = time();
					self::require_state( false !== update_user_meta( $canonical, self::JOURNAL_META, wp_json_encode( $updated ), wp_json_encode( $journal ) ), 7 );
				} );
			} );
			self::finish( $canonical, $source, 'identity.account_link_rolled_back' );
		} catch ( Throwable $throwable ) {
			self::observe_failure( $canonical, $source, $throwable );
			throw $throwable;
		}
	}

	public static function erase_journal( int $user_id ): bool {
		try {
			$raw = self::meta( $user_id, self::JOURNAL_META );
			return null === $raw || ( delete_user_meta( $user_id, self::JOURNAL_META, $raw ) && null === self::meta( $user_id, self::JOURNAL_META ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	private static function authorize( int $canonical, int $source ): void {
		self::require_state( ! is_multisite() && $canonical > 0 && $source > 0 && $canonical !== $source
			&& current_user_can( 'manage_options' ) && current_user_can( 'edit_users' )
			&& current_user_can( 'edit_user', $canonical ) && current_user_can( 'edit_user', $source ), 1 );
		self::require_state( function_exists( 'wc_get_orders' ), 2 );
	}

	private static function inspect( int $canonical, int $source ): array {
		$state = self::snapshot( $canonical, $source );
		$old   = get_userdata( $source );
		$main  = get_userdata( $canonical );
		self::require_state( $old && $main && ! UserService::is_native_only( $old ) && ! UserService::is_native_only( $main )
			&& '' === $old->user_email && str_starts_with( $old->user_pass, 'NO_PASSWORD_' )
			&& ! array_diff( $old->roles, [ 'subscriber', 'customer' ] ) && 'pinova' === self::meta( $source, 'created_by' )
			&& 0 === $state['source_orders'] && 0 === $state['source_permissions'], 2 );
		self::require_state( null === self::meta( $canonical, self::JOURNAL_META )
			&& false === self::retired_state( $canonical ) && false === self::retired_state( $source )
			&& null === self::meta( $canonical, 'pinova_mobile' ) && null === UserService::get_mobile( $canonical ), 3 );
		self::require_state( '' !== $state['mobile'] && UserService::get_by_mobile( $state['mobile'] ) === $source, 3 );
		return $state;
	}

	/** Snapshot hashes remain private; no billing data, tokens or identifiers leave the service. */
	private static function snapshot( int $canonical, int $source ): array {
		global $wpdb;
		$orders = [];
		$users  = [];
		$counts = [];
		foreach ( [ $canonical, $source ] as $id ) {
			clean_user_cache( $id );
			$user = get_userdata( $id );
			self::require_state( false !== $user, 2 );
			$users[] = [ $id, $user->user_login, $user->user_email, $user->user_pass, $user->roles ];
			$ids = wc_get_orders( [ 'customer_id' => $id, 'type' => wc_get_order_types(), 'limit' => 101, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );
			self::require_state( is_array( $ids ) && count( $ids ) <= 100 && ! $wpdb->last_error, 2 );
			$counts[ $id ] = count( $ids );
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				self::require_state( false !== $order, 2 );
				$orders[] = [ $order->get_data(), array_map( static fn( $meta ) => $meta->get_data(), $order->get_meta_data() ) ];
			}
		}
		$permissions = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id IN (%d,%d) ORDER BY permission_id LIMIT 1001', $wpdb->prefix . 'woocommerce_downloadable_product_permissions', $canonical, $source ), ARRAY_A );
		self::require_state( is_array( $permissions ) && ! $wpdb->last_error && count( $permissions ) <= 1000, 2 );
		$mobile = UserService::get_mobile( $source ) ?? '';
		$safety = self::digest( [ $users, $orders, $permissions ] );
		return [
			'mobile' => $mobile, 'safety' => $safety, 'source_orders' => $counts[ $source ], 'canonical_orders' => $counts[ $canonical ],
			'source_permissions' => count( array_filter( $permissions, static fn( $row ) => (int) $row['user_id'] === $source ) ),
			'canonical_permissions' => count( array_filter( $permissions, static fn( $row ) => (int) $row['user_id'] === $canonical ) ),
			'digest' => self::digest( [ $safety, $mobile, self::meta( $canonical, 'pinova_mobile' ), self::meta( $source, self::RETIRED_META ) ] ),
		];
	}

	private static function preview( int $canonical, int $source, array $state, string $phase, ?array $journal ): array {
		return [
			'canonical_id' => $canonical, 'source_id' => $source, 'state' => $phase,
			'orders_preserved' => $state['canonical_orders'], 'permissions_preserved' => $state['canonical_permissions'],
			'fingerprint' => self::digest( [ $canonical, $source, $state['digest'], $journal ] ),
		];
	}

	private static function check_applied( int $canonical, int $source, array $journal ): void {
		global $wpdb;
		$mobile = self::meta( $canonical, 'pinova_mobile' );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE (user_id = %d AND meta_key = %s) OR (user_id = %d AND meta_key = %s) ORDER BY umeta_id', $wpdb->usermeta, $canonical, 'pinova_mobile', $source, self::RETIRED_META ) );
		self::require_state( ! $wpdb->last_error && is_string( $mobile ) && true === self::retired_state( $source )
			&& hash_equals( $journal['mobile_digest'], MobileVerificationService::identity_digest( $canonical, new Identifier( $mobile ) ) )
			&& count( $ids ) === 2 && in_array( $journal['mobile_id'], array_map( 'intval', $ids ), true ) && in_array( $journal['retired_id'], array_map( 'intval', $ids ), true )
			&& UserService::get_by_mobile( $mobile ) === $canonical, 4 );
	}

	private static function quiesce( int $canonical, int $source, string $mobile ): void {
		self::require_state( RateLimitService::delete_queued_for_identifiers( [ $mobile ] )
			&& MobileVerificationService::erase_pending( $canonical ) && MobileVerificationService::erase_pending( $source ), 6 );
	}

	private static function locked( int $canonical, int $source, string $mobile, callable $callback ): void {
		$ids = [ $canonical, $source ];
		sort( $ids, SORT_NUMERIC );
		MobileVerificationService::with_identity_lock( $mobile, static function () use ( $ids, $callback ): void {
			MobileVerificationService::with_lock( $ids[0], static function () use ( $ids, $callback ): void {
				MobileVerificationService::with_lock( $ids[1], $callback );
			} );
		} );
	}

	private static function transaction( int $canonical, int $source, callable $callback ): void {
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->usermeta ) );
		self::require_state( is_string( $engine ) && 'innodb' === strtolower( $engine ), 8 );
		// MySQL protocol bit zero is IN_TRANS; never commit an enclosing transaction.
		$connection = $wpdb->dbh;
		self::require_state( $connection instanceof \mysqli && 0 === ( $connection->server_status & 1 ), 8 );
		self::require_state( false !== $wpdb->query( 'START TRANSACTION' ), 7 );
		try {
			self::require_state( false !== $wpdb->query( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE user_id IN (%d,%d) ORDER BY umeta_id FOR UPDATE', $wpdb->usermeta, $canonical, $source ) ), 7 );
			$callback();
			self::require_state( false !== $wpdb->query( 'COMMIT' ), 7 );
		} catch ( Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' );
			throw $throwable;
		} finally {
			clean_user_cache( $canonical );
			clean_user_cache( $source );
		}
	}

	private static function finish( int $canonical, int $source, string $event ): void {
		WP_Session_Tokens::get_instance( $canonical )->destroy_all();
		WP_Session_Tokens::get_instance( $source )->destroy_all();
		Logger::instance()->audit( 'warning', $event, [ 'user_id' => $canonical, 'operation' => 'account_link', 'result' => 'success' ] );
	}

	private static function observe_failure( int $canonical, int $source, Throwable $throwable ): void {
		$reason = $throwable instanceof RuntimeException ? ( self::REASONS[ $throwable->getCode() ] ?? 'operation_failed' ) : 'operation_failed';
		EventThrottle::log( 'identity.account_link_failed', [ 'user_id' => $canonical, 'operation' => 'account_link', 'reason' => $reason ] );
	}

	private static function meta( int $id, string $key ): ?string {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 2', $wpdb->usermeta, $id, $key ) );
		self::require_state( ! $wpdb->last_error && is_array( $rows ) && count( $rows ) <= 1, 3 );
		return $rows ? (string) $rows[0] : null;
	}

	private static function journal( int $canonical ): ?array {
		$raw = self::meta( $canonical, self::JOURNAL_META );
		if ( null === $raw ) {
			return null;
		}
		$journal = json_decode( $raw, true );
		self::require_state( is_array( $journal ) && in_array( $journal['state'] ?? null, [ 'applied', 'rolled_back' ], true )
			&& is_int( $journal['source'] ?? null ) && is_string( $journal['plan'] ?? null )
			&& is_string( $journal['safety'] ?? null ) && is_string( $journal['mobile_digest'] ?? null )
			&& is_int( $journal['mobile_id'] ?? null ) && is_int( $journal['retired_id'] ?? null ), 3 );
		return $journal;
	}

	private static function digest( array $data ): string {
		$json = wp_json_encode( $data );
		self::require_state( is_string( $json ), 7 );
		return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	private static function require_state( bool $condition, int $reason ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'Account reconciliation requires administrator review.', $reason );
		}
	}
}
