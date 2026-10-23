<?php

namespace Pinova\Services;

use Pinova\Logging\EventThrottle;
use Pinova\Logging\Logger;
use Pinova\Objects\Identifier;
use Pinova\Objects\Mobile;
use RuntimeException;
use PDO;
use PDOStatement;
use Pinova\Models\OTP;
use Throwable;
use WP_Session_Tokens;

/** Reconcile an empty OTP account into a proven legacy account; never transfer commerce data. */
final class AccountLinkService {
	public const RETIRED_META = '_pinova_mobile_alias_retired';
	public const JOURNAL_META = '_pinova_account_link_journal';

	private const REASONS = [
		1  => 'not_authorized',
		2  => 'unsupported_accounts',
		3  => 'identity_conflict',
		4  => 'stale_state',
		5  => 'ownership_not_confirmed',
		6  => 'active_delivery',
		7  => 'write_failed',
		8  => 'unsupported_storage',
		9  => 'data_changed',
		10 => 'source_has_content',
		11 => 'source_has_wallet_activity',
		12 => 'source_has_forum_activity',
		13 => 'unsupported_ownership_adapter',
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
				self::require_state( 2 === ( $journal['version'] ?? 1 ), 8 );
				self::require_state(
					'applied' === $journal['state'] && $source === $journal['source']
					&& ( hash_equals( $journal['plan'], $expected ) || hash_equals( self::plan( $canonical, $source )['fingerprint'], $expected ) ),
					4
				);
				self::source_activity( $source );
				self::check_applied( $canonical, $source, $journal );
				self::finish( $canonical, $source, 'identity.account_linked' );
				return;
			}
			$mobile = (string) UserService::get_mobile( $source );
			self::locked(
				$canonical,
				$source,
				$mobile,
				static function () use ( $canonical, $source, $expected, $mobile ): void {
					$state = self::inspect( $canonical, $source );
					self::require_state( hash_equals( self::preview( $canonical, $source, $state, 'ready', null )['fingerprint'], $expected ), 4 );
					self::quiesce( $canonical, $source, $mobile );
					self::transaction(
						$canonical,
						$source,
						static function ( PDO $pdo ) use ( $canonical, $source, $expected, $mobile, $state ): void {
							self::require_state( hash_equals( $state['guard'], self::guard( $state['guard_sql'], $pdo ) ), 4 );
							self::revoke( $pdo, $canonical );
							self::revoke( $pdo, $source );
							$retired_id = self::insert_meta( $pdo, $source, self::RETIRED_META, '1' );
							$mobile_id  = self::insert_meta( $pdo, $canonical, 'pinova_mobile', $mobile );
							self::require_state( 0 < $retired_id && 0 < $mobile_id, 7 );
							$journal = [
								'version'             => 2,
								'state'               => 'applied',
								'source'              => $source,
								'actor'               => get_current_user_id(),
								'at'                  => time(),
								'ownership_confirmed' => true,
								'plan'                => $expected,
								'safety'              => $state['safety'],
								'mobile_id'           => $mobile_id,
								'retired_id'          => $retired_id,
								'mobile_digest'       => $state['mobile_digest'],
							];
							self::insert_meta( $pdo, $canonical, self::JOURNAL_META, (string) wp_json_encode( $journal ) );
						}
					);
				}
			);
			$persisted = self::journal( $canonical );
			self::require_state( null !== $persisted, 7 );
			self::source_activity( $source );
			self::check_applied( $canonical, $source, $persisted );
			self::finish( $canonical, $source, 'identity.account_linked' );
		} catch ( Throwable $throwable ) {
			self::observe_failure( $canonical, $throwable );
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
			self::require_state( 2 === ( $journal['version'] ?? 1 ), 8 );
			$mobile = (string) UserService::get_mobile( $canonical );
			self::locked(
				$canonical,
				$source,
				$mobile,
				static function () use ( $canonical, $source, $expected, $mobile, $journal ): void {
					self::require_state( hash_equals( self::plan( $canonical, $source )['fingerprint'], $expected ), 4 );
					self::check_applied( $canonical, $source, $journal );
					self::require_state( hash_equals( $journal['safety'], self::snapshot( $canonical, $source )['safety'] ), 9 );
					$state = self::snapshot( $canonical, $source );
					self::quiesce( $canonical, $source, $mobile );
					self::transaction(
						$canonical,
						$source,
						static function ( PDO $pdo ) use ( $canonical, $source, $journal, $state ): void {
							self::require_state( hash_equals( $state['guard'], self::guard( $state['guard_sql'], $pdo ) ), 4 );
							self::revoke( $pdo, $canonical );
							self::revoke( $pdo, $source );
							self::delete_meta( $pdo, $canonical, $journal['mobile_id'], 'pinova_mobile' );
							self::delete_meta( $pdo, $source, $journal['retired_id'], self::RETIRED_META );
							$updated                   = $journal;
							$updated['state']          = 'rolled_back';
							$updated['rollback_actor'] = get_current_user_id();
							$updated['rollback_at']    = time();
							global $wpdb;
							$sql = $wpdb->prepare( 'UPDATE %i SET meta_value = ? WHERE user_id = ? AND meta_key = ? AND BINARY meta_value = ?', $wpdb->usermeta );
							self::require_state( 1 === self::write( $pdo, $sql, [ wp_json_encode( $updated ), $canonical, self::JOURNAL_META, wp_json_encode( $journal ) ] )->rowCount(), 7 );
						}
					);
				}
			);
			self::finish( $canonical, $source, 'identity.account_link_rolled_back' );
		} catch ( Throwable $throwable ) {
			self::observe_failure( $canonical, $throwable );
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
		self::require_state(
			! is_multisite() && $canonical > 0 && $source > 0 && $canonical !== $source
			&& current_user_can( 'manage_options' ) && current_user_can( 'edit_users' )
			&& current_user_can( 'edit_user', $canonical ) && current_user_can( 'edit_user', $source ),
			1
		);
		self::require_state( function_exists( 'wc_get_orders' ), 2 );
	}

	private static function inspect( int $canonical, int $source ): array {
		$state = self::snapshot( $canonical, $source );
		foreach ( [ $canonical, $source ] as $id ) {
			foreach ( [ MobileVerificationService::EPOCH_META, MobileVerificationService::PROOF_META, MobileVerificationService::PENDING_META ] as $key ) {
				self::meta( $id, $key );
			}
		}
		$old  = get_userdata( $source );
		$main = get_userdata( $canonical );
		self::require_state(
			$old && $main && ! UserService::is_native_only( $old ) && ! UserService::is_native_only( $main )
			&& '' === $old->user_email && str_starts_with( $old->user_pass, 'NO_PASSWORD_' )
			&& ! array_diff( $old->roles, [ 'subscriber', 'customer' ] ) && 'pinova' === self::meta( $source, 'created_by' )
			&& 0 === $state['source_orders'] && 0 === $state['source_permissions'],
			2
		);
		self::require_state(
			null === self::meta( $canonical, self::JOURNAL_META )
			&& false === self::retired_state( $canonical ) && false === self::retired_state( $source )
			&& null === self::meta( $canonical, 'pinova_mobile' ) && null === UserService::get_mobile( $canonical ),
			3
		);
		self::source_activity( $source );
		self::check_initial_aliases( $canonical, $source, $state, $old->user_login );
		self::require_state( '' !== $state['mobile'] && UserService::get_by_mobile( $state['mobile'] ) === $source, 3 );
		return $state;
	}

	/** A source with multiple effective numbers needs a separate migration, not alias retirement. */
	private static function check_initial_aliases( int $canonical, int $source, array $state, string $source_login ): void {
		$physical = UserService::get_persisted_mobile_result( $source );
		self::require_state( $physical['success'] && ( null === $physical['value'] || $physical['value'] === $state['mobile'] ), 3 );
		$values = $state['aliases'];
		if ( null === $physical['value'] ) {
			$values[] = [ 'user_id' => $source, 'meta_value' => $source_login ];
		}
		foreach ( $values as $row ) {
			if ( $source === (int) $row['user_id'] && null !== $physical['value'] ) {
				continue; // The unchanged physical override suppresses older aliases.
			}
			$mobile = new Mobile( (string) $row['meta_value'] );
			self::require_state( ! $mobile->is_valid() || ( $canonical !== (int) $row['user_id'] && $mobile->get_formatted() === $state['mobile'] ), 3 );
		}
	}

	/** Exact fallback rows are part of rollback safety; exclude only the operation-owned binding. */
	private static function aliases( int $canonical, int $source ): array {
		global $wpdb;
		$keys = UserService::mobile_possible_meta_keys();
		self::require_state( count( $keys ) <= 40, 2 );
		$tokens = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only generated placeholders are interpolated.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id,user_id,meta_key,CASE WHEN OCTET_LENGTH(meta_value)<=256 THEN meta_value ELSE NULL END AS meta_value,OCTET_LENGTH(meta_value) AS value_size FROM %i WHERE user_id IN (%d,%d) AND meta_key IN ({$tokens}) AND meta_key NOT IN ('billing_phone','shipping_phone') AND NOT (user_id=%d AND meta_key='pinova_mobile') ORDER BY umeta_id LIMIT 1001", array_merge( [ $wpdb->usermeta, $canonical, $source ], $keys, [ $canonical ] ) ), ARRAY_A );
		self::require_state( ! $wpdb->last_error && is_array( $rows ) && count( $rows ) <= 1000, 3 );
		foreach ( $rows as $row ) {
			self::require_state( (int) $row['value_size'] <= 256, 2 );
		}
		return $rows;
	}

	/** Do not strand source-owned content or finance/forum history behind retired login. */
	private static function source_activity( int $source ): void {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			// WooCommerce order ownership is checked through its data store, never post_author.
			$post = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_author = %d AND post_type NOT IN ('shop_order','shop_order_refund','shop_order_placehold') LIMIT 1", $wpdb->posts, $source ) );
			self::require_ownership_read();
			$comment = $wpdb->get_var( $wpdb->prepare( 'SELECT comment_ID FROM %i WHERE user_id = %d LIMIT 1', $wpdb->comments, $source ) );
			self::require_ownership_read();
			self::require_state( null === $post && null === $comment, 10 );
			$wallet_table = $wpdb->base_prefix . 'woo_wallet_transactions';
			$pattern      = $wpdb->esc_like( $wpdb->prefix . 'wpforo' ) . '%' . $wpdb->esc_like( '_posts' );
			$tables       = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME = %s OR TABLE_NAME LIKE %s) LIMIT 11', $wallet_table, $pattern ) );
			self::require_state( ! $wpdb->last_error && is_array( $tables ) && count( $tables ) <= 10, 13 );
			self::require_state( ! function_exists( 'woo_wallet' ) || in_array( $wallet_table, $tables, true ), 13 );
			self::require_state( ! function_exists( 'wpforo' ) || (bool) array_diff( $tables, [ $wallet_table ] ), 13 );
			foreach ( $tables as $table ) {
				$is_wallet = $wallet_table === $table;
				$column    = $is_wallet ? 'user_id' : 'userid';
				$exists    = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE %i = %d LIMIT 1', $table, $column, $source ) );
				self::require_ownership_read();
				self::require_state( null === $exists, $is_wallet ? 11 : 12 );
			}
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** wpdb mutates last_error on every read, including after an earlier successful read. */
	private static function require_ownership_read(): void {
		global $wpdb;
		self::require_state( '' === $wpdb->last_error, 13 );
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
			$ids     = wc_get_orders(
				[
					'customer_id' => $id,
					'status'      => array_values( array_unique( array_merge( array_keys( wc_get_order_statuses() ), array_values( get_post_stati() ) ) ) ),
					'type'        => wc_get_order_types(),
					'limit'       => 101,
					'return'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
				]
			);
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
		$mobile    = UserService::get_mobile( $source ) ?? '';
		$aliases   = self::aliases( $canonical, $source );
		$safety    = self::digest( [ $users, $orders, $permissions, $aliases ] );
		$guard_sql = self::guard_sql( $canonical, $source );
		$guard     = self::guard( $guard_sql );
		return [
			'aliases'               => $aliases,
			'mobile_digest'         => MobileVerificationService::identity_digest( $canonical, new Identifier( $mobile ) ),
			'guard_sql'             => $guard_sql,
			'guard'                 => $guard,
			'mobile'                => $mobile,
			'safety'                => $safety,
			'source_orders'         => $counts[ $source ],
			'canonical_orders'      => $counts[ $canonical ],
			'source_permissions'    => count( array_filter( $permissions, static fn( $row ) => (int) $row['user_id'] === $source ) ),
			'canonical_permissions' => count( array_filter( $permissions, static fn( $row ) => (int) $row['user_id'] === $canonical ) ),
			'digest'                => self::digest( [ $safety, $mobile, $guard, self::meta( $canonical, 'pinova_mobile' ), self::meta( $source, self::RETIRED_META ) ] ),
		];
	}

	/** @return string[] Fully prepared reads; pending queues are cancelled separately. */
	private static function guard_sql( int $canonical, int $source ): array {
		global $wpdb;
		$keys = array_values(
			array_unique(
				array_merge(
					UserService::mobile_possible_meta_keys(),
					[
						$wpdb->prefix . 'capabilities',
						'created_by',
						self::RETIRED_META,
						self::JOURNAL_META,
						MobileVerificationService::EPOCH_META,
						MobileVerificationService::PROOF_META,
					]
				)
			)
		);
		self::require_state( count( $keys ) <= 40, 2 );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return [
			$wpdb->prepare( 'SELECT ID,user_login,user_email,user_pass FROM %i WHERE ID IN (%d,%d) ORDER BY ID', $wpdb->users, $canonical, $source ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic fragment contains only placeholders.
			$wpdb->prepare( "SELECT umeta_id,user_id,meta_key,meta_value FROM %i WHERE user_id IN (%d,%d) AND meta_key IN ({$placeholders}) ORDER BY umeta_id LIMIT 1001", array_merge( [ $wpdb->usermeta, $canonical, $source ], $keys ) ),
		];
	}

	/** Native reads inside the write transaction cannot invoke WordPress hooks or reconnect. */
	private static function guard( array $queries, ?PDO $pdo = null ): string {
		global $wpdb;
		$data = [];
		foreach ( $queries as $sql ) {
			if ( null === $pdo ) {
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- guard_sql returns fully prepared reads.
				self::require_state( ! $wpdb->last_error && is_array( $rows ), 3 );
			} else {
				$statement = $pdo->query( $sql );
				self::require_state( $statement instanceof PDOStatement, 7 );
				$rows = $statement->fetchAll( PDO::FETCH_ASSOC );
			}
			self::require_state( count( $rows ) <= 1000, 2 );
			$data[] = array_map( static fn( $row ) => array_map( 'strval', $row ), $rows );
		}
		$json = wp_json_encode( $data );
		self::require_state( is_string( $json ), 7 );
		return hash( 'sha256', $json );
	}

	private static function preview( int $canonical, int $source, array $state, string $phase, ?array $journal ): array {
		return [
			'journal_version'       => $journal['version'] ?? ( null === $journal ? 0 : 1 ),
			'canonical_id'          => $canonical,
			'source_id'             => $source,
			'state'                 => $phase,
			'source_orders'         => $state['source_orders'],
			'source_permissions'    => $state['source_permissions'],
			'orders_preserved'      => $state['canonical_orders'],
			'permissions_preserved' => $state['canonical_permissions'],
			'fingerprint'           => self::digest( [ $canonical, $source, $state['digest'], $journal ] ),
		];
	}

	private static function check_applied( int $canonical, int $source, array $journal ): void {
		global $wpdb;
		$mobile = self::meta( $canonical, 'pinova_mobile' );
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE (user_id = %d AND meta_key = %s) OR (user_id = %d AND meta_key = %s) ORDER BY umeta_id', $wpdb->usermeta, $canonical, 'pinova_mobile', $source, self::RETIRED_META ) );
		self::require_state(
			! $wpdb->last_error && is_string( $mobile ) && true === self::retired_state( $source )
			&& hash_equals( $journal['mobile_digest'], MobileVerificationService::identity_digest( $canonical, new Identifier( $mobile ) ) )
			&& count( $ids ) === 2 && in_array( $journal['mobile_id'], array_map( 'intval', $ids ), true ) && in_array( $journal['retired_id'], array_map( 'intval', $ids ), true )
			&& UserService::get_by_mobile( $mobile ) === $canonical,
			4
		);
	}

	private static function quiesce( int $canonical, int $source, string $mobile ): void {
		self::require_state(
			RateLimitService::delete_queued_for_identifiers( [ $mobile ] )
			&& MobileVerificationService::erase_pending( $canonical ) && MobileVerificationService::erase_pending( $source ),
			6
		);
	}

	private static function locked( int $canonical, int $source, string $mobile, callable $callback ): void {
		$ids = [ $canonical, $source ];
		sort( $ids, SORT_NUMERIC );
		MobileVerificationService::with_identity_lock(
			$mobile,
			static function () use ( $ids, $callback ): void {
				MobileVerificationService::with_lock(
					$ids[0],
					static function () use ( $ids, $callback ): void {
						MobileVerificationService::with_lock( $ids[1], $callback );
					}
				);
			}
		);
	}

	/** Use native PDO writes so a lost connection cannot replay metadata in autocommit. */
	private static function transaction( int $canonical, int $source, callable $callback ): void {
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->usermeta ) );
		$pdo    = OTP::resolveConnection()->getPdo();
		self::require_state(
			is_string( $engine ) && 'innodb' === strtolower( $engine ) && $pdo instanceof PDO
			&& ! $pdo->inTransaction() && 'mysql' === $pdo->getAttribute( PDO::ATTR_DRIVER_NAME ),
			8
		);
		$database = $pdo->query( 'SELECT DATABASE()' );
		self::require_state( $database instanceof PDOStatement && DB_NAME === $database->fetchColumn(), 8 );
		self::require_state( '1' === (string) $wpdb->get_var( 'SELECT @@autocommit' ), 8 );
		$timeout = $pdo->query( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		self::require_state( $timeout instanceof PDOStatement, 8 );
		$previous_timeout = (int) $timeout->fetchColumn();
		self::require_state( false !== $pdo->exec( 'SET SESSION innodb_lock_wait_timeout = 5' ), 8 );
		try {
			self::require_state( $pdo->beginTransaction(), 7 );
			self::write( $pdo, $wpdb->prepare( 'SELECT ID FROM %i WHERE ID IN (?,?) ORDER BY ID FOR UPDATE', $wpdb->users ), [ $canonical, $source ] );
			self::write( $pdo, $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE user_id IN (?,?) ORDER BY umeta_id FOR UPDATE', $wpdb->usermeta ), [ $canonical, $source ] );
			$callback( $pdo );
			self::require_state( $pdo->commit(), 7 );
		} catch ( Throwable $throwable ) {
			if ( $pdo->inTransaction() ) {
				try {
					$pdo->rollBack();
				} catch ( Throwable $rollback_error ) {
					unset( $rollback_error );
				}
			}
			throw $throwable;
		} finally {
			try {
				$pdo->exec( 'SET SESSION innodb_lock_wait_timeout = ' . $previous_timeout );
			} catch ( Throwable $restore_error ) {
				unset( $restore_error );
			}
			foreach ( [ $canonical, $source ] as $id ) {
				wp_cache_delete( $id, 'user_meta' );
				clean_user_cache( $id );
			}
		}
	}

	private static function write( PDO $pdo, string $sql, array $params ): PDOStatement {
		$statement = $pdo->prepare( $sql );
		self::require_state( $statement instanceof PDOStatement && $statement->execute( $params ), 7 );
		return $statement;
	}

	private static function insert_meta( PDO $pdo, int $id, string $key, string $value ): int {
		global $wpdb;
		$sql = $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (?,?,?)', $wpdb->usermeta );
		self::require_state( 1 === self::write( $pdo, $sql, [ $id, $key, $value ] )->rowCount(), 7 );
		return (int) $pdo->lastInsertId();
	}

	private static function delete_meta( PDO $pdo, int $id, int $meta_id, string $key ): void {
		global $wpdb;
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE umeta_id = ? AND user_id = ? AND meta_key = ?', $wpdb->usermeta );
		self::require_state( 1 === self::write( $pdo, $sql, [ $meta_id, $id, $key ] )->rowCount(), 7 );
	}

	private static function revoke( PDO $pdo, int $id ): void {
		global $wpdb;
		$key    = MobileVerificationService::EPOCH_META;
		$epoch  = bin2hex( random_bytes( 16 ) );
		$values = self::write( $pdo, $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = ? AND meta_key = ? LIMIT 2', $wpdb->usermeta ), [ $id, $key ] )->fetchAll( PDO::FETCH_COLUMN );
		self::require_state( count( $values ) <= 1, 3 );
		if ( ! $values ) {
			self::insert_meta( $pdo, $id, $key, $epoch );
		} else {
			$sql = $wpdb->prepare( 'UPDATE %i SET meta_value = ? WHERE user_id = ? AND meta_key = ?', $wpdb->usermeta );
			self::require_state( 1 === self::write( $pdo, $sql, [ $epoch, $id, $key ] )->rowCount(), 7 );
		}
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = ? AND meta_key = ?', $wpdb->usermeta );
		self::write( $pdo, $sql, [ $id, MobileVerificationService::PROOF_META ] );
	}

	private static function finish( int $canonical, int $source, string $event ): void {
		foreach ( [ $canonical, $source ] as $id ) {
			$tokens = WP_Session_Tokens::get_instance( $id );
			$tokens->destroy_all();
			self::require_state( [] === $tokens->get_all(), 7 );
		}
		Logger::instance()->audit(
			'warning',
			$event,
			[
				'user_id'   => $canonical,
				'operation' => 'account_link',
				'result'    => 'success',
			]
		);
	}

	public static function failure_reason( Throwable $throwable ): string {
		return $throwable instanceof RuntimeException ? ( self::REASONS[ $throwable->getCode() ] ?? 'operation_failed' ) : 'operation_failed';
	}

	private static function observe_failure( int $canonical, Throwable $throwable ): void {
		$reason = self::failure_reason( $throwable );
		EventThrottle::log(
			'identity.account_link_failed',
			[
				'user_id'   => $canonical,
				'operation' => 'account_link',
				'reason'    => $reason,
			]
		);
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
		self::require_state(
			is_array( $journal ) && in_array( $journal['state'] ?? null, [ 'applied', 'rolled_back' ], true )
			&& ( ! array_key_exists( 'version', $journal ) || is_int( $journal['version'] ) )
			&& is_int( $journal['source'] ?? null ) && is_string( $journal['plan'] ?? null )
			&& is_string( $journal['safety'] ?? null ) && is_string( $journal['mobile_digest'] ?? null )
			&& is_int( $journal['mobile_id'] ?? null ) && is_int( $journal['retired_id'] ?? null ),
			3
		);
		return $journal;
	}

	private static function digest( array $data ): string {
		$json = wp_json_encode( $data );
		self::require_state( is_string( $json ), 7 );
		return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	/** @phpstan-assert true $condition */
	private static function require_state( bool $condition, int $reason ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'Account reconciliation requires administrator review.', $reason );
		}
	}
}
