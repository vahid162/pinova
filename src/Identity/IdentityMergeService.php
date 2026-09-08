<?php

namespace Pinova\Identity;

use RuntimeException;
use WP_Session_Tokens;
use WP_User;

class IdentityMergeService {
	private const PROFILE_META_KEYS = [
		'first_name',
		'last_name',
		'nickname',
		'description',
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_country',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_phone',
		'billing_email',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_country',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_phone',
	];

	public static function dry_run( int $source_user_id, int $target_user_id ): array {
		$preflight = self::preflight( $source_user_id, $target_user_id, false );
		global $wpdb;

		return [
			'source_user_id'   => $source_user_id,
			'target_user_id'   => $target_user_id,
			'posts'            => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `post_author` = %d', $wpdb->posts, $source_user_id ) ),
			'comments'         => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `user_id` = %d', $wpdb->comments, $source_user_id ) ),
			'orders'           => self::order_count( $source_user_id ),
			'downloads'        => self::download_count( $source_user_id ),
			'identities'       => count( IdentityRepository::for_user( $source_user_id ) ),
			'profile_meta'     => count( self::copyable_meta( $source_user_id, $target_user_id ) ),
			'non_reversible'   => [ 'source_user_sessions' ],
			'preflight_issues' => $preflight['issues'],
		];
	}

	public static function apply( int $source_user_id, int $target_user_id, ?int $run_id = null ): array {
		if ( $run_id ) {
			self::validate_resume_run( $run_id, $source_user_id, $target_user_id );
		}

		$preflight = self::preflight( $source_user_id, $target_user_id, true, (bool) $run_id );

		$run_id = $run_id ?: self::create_run( $source_user_id, $target_user_id, $preflight );
		self::set_run_status( $run_id, 'running' );
		update_option( 'pinova_identity_maintenance', 1, false );

		try {
			self::reconcile_planned_items( $run_id );
			self::move_posts( $run_id, $source_user_id, $target_user_id );
			self::move_comments( $run_id, $source_user_id, $target_user_id );
			self::move_orders( $run_id, $source_user_id, $target_user_id );
			self::move_downloads( $run_id, $source_user_id, $target_user_id );
			self::copy_profile_meta( $run_id, $source_user_id, $target_user_id );
			self::move_identities( $run_id, $source_user_id, $target_user_id );
			self::disable_source( $run_id, $source_user_id, $target_user_id );
			self::destroy_sessions( $run_id, $source_user_id );
			self::require_no_planned_items( $run_id );
			self::set_run_status( $run_id, 'completed', 'completed_at' );
		} catch ( \Throwable $throwable ) {
			self::set_run_status( $run_id, 'failed' );
			throw $throwable;
		} finally {
			delete_option( 'pinova_identity_maintenance' );
		}

		return [
			'run_id'         => $run_id,
			'source_user_id' => $source_user_id,
			'target_user_id' => $target_user_id,
			'status'         => 'completed',
		];
	}

	public static function rollback( int $run_id, bool $apply = false ): array {
		global $wpdb;

		if ( is_multisite() ) {
			throw new RuntimeException( 'Identity rollback apply is not supported on Multisite.' );
		}

		$run_table  = $wpdb->prefix . 'pinova_identity_merge_runs';
		$item_table = $wpdb->prefix . 'pinova_identity_merge_items';
		$run        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', $run_table, $run_id ) );

		if ( ! $run ) {
			throw new RuntimeException( 'Merge run not found.' );
		}

		self::reconcile_planned_items( $run_id );
		$items = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE `run_id` = %d AND `status` = 'applied' ORDER BY `id` DESC", $item_table, $run_id )
		);

		$planned = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `run_id` = %d AND `status` = 'planned'", $item_table, $run_id )
		);

		if ( ! $apply ) {
			return [
				'run_id'                   => $run_id,
				'reversible_items'         => count( array_filter( $items, static fn( $item ): bool => 'session' !== $item->object_type ) ),
				'non_reversible_sessions'  => count( array_filter( $items, static fn( $item ): bool => 'session' === $item->object_type ) ),
				'unresolved_planned_items' => $planned,
			];
		}

		$restored = 0;
		$skipped  = $planned;
		update_option( 'pinova_identity_maintenance', 1, false );

		try {
			foreach ( $items as $item ) {
				if ( self::rollback_item( $item ) ) {
					++$restored;
					$status = 'rolled_back';
				} else {
					++$skipped;
					$status = 'rollback_skipped';
				}

				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET `status` = %s, `rolled_back_at` = UTC_TIMESTAMP() WHERE `id` = %d',
						$item_table,
						$status,
						(int) $item->id
					)
				);
			}
		} finally {
			delete_option( 'pinova_identity_maintenance' );
		}

		$final_status = $skipped ? 'rollback_partial' : 'rolled_back';
		self::set_run_status( $run_id, $final_status, 'rolled_back_at' );

		return [
			'run_id'   => $run_id,
			'restored' => $restored,
			'skipped'  => $skipped,
			'status'   => $final_status,
		];
	}

	public static function preflight(
		int $source_user_id,
		int $target_user_id,
		bool $enforce = true,
		bool $allow_already_merged = false
	): array {
		if ( is_multisite() ) {
			throw new RuntimeException( 'Identity merge is not supported on Multisite.' );
		}

		if ( $source_user_id < 1 || $target_user_id < 1 || $source_user_id === $target_user_id ) {
			throw new RuntimeException( 'Source and target users must be different valid user IDs.' );
		}

		$source = get_userdata( $source_user_id );
		$target = get_userdata( $target_user_id );

		if ( ! $source instanceof WP_User || ! $target instanceof WP_User ) {
			throw new RuntimeException( 'Source or target user does not exist.' );
		}

		$merged_into     = (int) get_user_meta( $source_user_id, 'pinova_merged_into', true );
		$source_disabled = (bool) get_user_meta( $source_user_id, 'pinova_account_disabled', true );
		$target_merged   = (int) get_user_meta( $target_user_id, 'pinova_merged_into', true );
		$target_disabled = (bool) get_user_meta( $target_user_id, 'pinova_account_disabled', true );

		if ( $merged_into && ( ! $allow_already_merged || $merged_into !== $target_user_id ) ) {
			throw new RuntimeException( 'Source user has already been merged.' );
		}

		if ( $source_disabled && ( ! $allow_already_merged || $merged_into !== $target_user_id ) ) {
			throw new RuntimeException( 'Source user is disabled and cannot start a new merge.' );
		}

		if ( $target_merged || $target_disabled ) {
			throw new RuntimeException( 'Canonical target user must be active and must not already be merged.' );
		}

		$issues = [];

		if ( function_exists( 'WPF' ) ) {
			$issues[] = 'wpforo_adapter_missing';
		}

		if ( ! function_exists( 'wc_get_orders' ) && self::stored_woocommerce_order_count( $source_user_id ) > 0 ) {
			$issues[] = 'woocommerce_inactive';
		}

		$issues = (array) apply_filters( 'pinova/identity_merge_preflight_issues', $issues, $source_user_id, $target_user_id );

		if ( $enforce && $issues ) {
			throw new RuntimeException( 'Unsupported identity dependencies: ' . implode( ', ', array_map( 'sanitize_key', $issues ) ) );
		}

		return [
			'source_roles' => array_values( $source->roles ),
			'target_roles' => array_values( $target->roles ),
			'issues'       => array_values( array_unique( array_map( 'sanitize_key', $issues ) ) ),
		];
	}

	private static function create_run( int $source, int $target, array $context ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_merge_runs';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (`source_user_id`, `target_user_id`, `started_by`, `status`, `context`, `started_at`) VALUES (%d, %d, %d, 'pending', %s, UTC_TIMESTAMP())",
				$table,
				$source,
				$target,
				get_current_user_id() ?: null,
				wp_json_encode( $context )
			)
		);

		if ( ! $wpdb->insert_id ) {
			throw new RuntimeException( 'Unable to create merge run.' );
		}

		return (int) $wpdb->insert_id;
	}

	private static function validate_resume_run( int $run_id, int $source, int $target ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_merge_runs';
		$run   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `source_user_id`, `target_user_id`, `status` FROM %i WHERE `id` = %d',
				$table,
				$run_id
			)
		);

		if ( ! $run ) {
			throw new RuntimeException( 'Merge run to resume was not found.' );
		}

		if ( (int) $run->source_user_id !== $source || (int) $run->target_user_id !== $target ) {
			throw new RuntimeException( 'Resume run does not match the requested source and target.' );
		}

		if ( in_array( $run->status, [ 'completed', 'rolled_back', 'rollback_partial' ], true ) ) {
			throw new RuntimeException( 'This merge run cannot be resumed from its current status.' );
		}
	}

	private static function set_run_status( int $run_id, string $status, ?string $timestamp_column = null ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_merge_runs';

		if ( 'completed_at' === $timestamp_column ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `status` = %s, `completed_at` = UTC_TIMESTAMP() WHERE `id` = %d', $table, $status, $run_id ) );

			return;
		} elseif ( 'rolled_back_at' === $timestamp_column ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `status` = %s, `rolled_back_at` = UTC_TIMESTAMP() WHERE `id` = %d', $table, $status, $run_id ) );

			return;
		}

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `status` = %s WHERE `id` = %d', $table, $status, $run_id ) );
	}

	private static function move_posts( int $run_id, int $source, int $target ): void {
		global $wpdb;

		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT `ID` FROM %i WHERE `post_author` = %d LIMIT 500', $wpdb->posts, $source ) ) );

			foreach ( $ids as $id ) {
				self::apply_item(
					$run_id,
					'post',
					$id,
					$source,
					$target,
					null,
					static function () use ( $wpdb, $id, $source, $target ): void {
						self::require_updated( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `post_author` = %d WHERE `ID` = %d AND `post_author` = %d', $wpdb->posts, $target, $id, $source ) ), 'post', $id );
						clean_post_cache( $id );
					}
				);
			}
			$batch_count = count( $ids );
		} while ( 500 === $batch_count );
	}

	private static function move_comments( int $run_id, int $source, int $target ): void {
		global $wpdb;

		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT `comment_ID` FROM %i WHERE `user_id` = %d LIMIT 500', $wpdb->comments, $source ) ) );

			foreach ( $ids as $id ) {
				self::apply_item(
					$run_id,
					'comment',
					$id,
					$source,
					$target,
					null,
					static function () use ( $wpdb, $id, $source, $target ): void {
						self::require_updated( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d WHERE `comment_ID` = %d AND `user_id` = %d', $wpdb->comments, $target, $id, $source ) ), 'comment', $id );
						clean_comment_cache( $id );
					}
				);
			}
			$batch_count = count( $ids );
		} while ( 500 === $batch_count );
	}

	private static function move_orders( int $run_id, int $source, int $target ): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		do {
			$ids = array_map(
				'intval',
				(array) wc_get_orders(
					[
						'customer_id' => $source,
						'limit'       => 500,
						'return'      => 'ids',
					]
				)
			);

			foreach ( $ids as $id ) {
				self::apply_item(
					$run_id,
					'order',
					$id,
					$source,
					$target,
					null,
					static function () use ( $id, $source, $target ): void {
						$order = wc_get_order( $id );

						if ( ! $order || (int) $order->get_customer_id() !== $source ) {
							throw new RuntimeException( sprintf( 'Unable to load order #%d for merge.', $id ) );
						}

						$order->set_customer_id( $target );

						if ( ! $order->save() ) {
							throw new RuntimeException( sprintf( 'Unable to move order #%d; merge remains resumable.', $id ) );
						}
					}
				);
			}
			$batch_count = count( $ids );
		} while ( 500 === $batch_count );
	}

	private static function move_downloads( int $run_id, int $source, int $target ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT `permission_id` FROM %i WHERE `user_id` = %d LIMIT 500', $table, $source ) ) );

			foreach ( $ids as $id ) {
				self::apply_item(
					$run_id,
					'download',
					$id,
					$source,
					$target,
					null,
					static function () use ( $wpdb, $table, $id, $source, $target ): void {
						self::require_updated( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d WHERE `permission_id` = %d AND `user_id` = %d', $table, $target, $id, $source ) ), 'download', $id );
					}
				);
			}
			$batch_count = count( $ids );
		} while ( 500 === $batch_count );
	}

	private static function copy_profile_meta( int $run_id, int $source, int $target ): void {
		foreach ( self::copyable_meta( $source, $target ) as $key => $value ) {
			$payload = wp_json_encode(
				[
					'key'        => $key,
					'value_hash' => self::value_hash( $value ),
				]
			);
			self::apply_item(
				$run_id,
				'user_meta:' . $key,
				$target,
				$source,
				$target,
				$payload,
				static function () use ( $target, $key, $value ): void {
					if ( '' === get_user_meta( $target, $key, true ) ) {
						update_user_meta( $target, $key, $value );
					}
				}
			);
		}
	}

	private static function move_identities( int $run_id, int $source, int $target ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identities';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `is_primary` FROM %i WHERE `user_id` = %d', $table, $source ) );

		foreach ( $rows as $row ) {
			$id      = (int) $row->id;
			$payload = wp_json_encode( [ 'is_primary' => (int) $row->is_primary ] );
			self::apply_item(
				$run_id,
				'identity',
				$id,
				$source,
				$target,
				$payload,
				static function () use ( $wpdb, $table, $id, $source, $target ): void {
					self::require_updated( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d, `is_primary` = 0, `updated_at` = UTC_TIMESTAMP() WHERE `id` = %d AND `user_id` = %d', $table, $target, $id, $source ) ), 'identity', $id );
				}
			);
		}
	}

	private static function disable_source( int $run_id, int $source, int $target ): void {
		$payload = wp_json_encode(
			[
				'merged_into' => get_user_meta( $source, 'pinova_merged_into', true ),
				'disabled'    => get_user_meta( $source, 'pinova_account_disabled', true ),
			]
		);
		self::apply_item(
			$run_id,
			'account_state',
			$source,
			$source,
			$target,
			$payload,
			static function () use ( $source, $target ): void {
				update_user_meta( $source, 'pinova_merged_into', $target );
				update_user_meta( $source, 'pinova_account_disabled', 1 );
			}
		);
	}

	private static function destroy_sessions( int $run_id, int $source ): void {
		self::apply_item(
			$run_id,
			'session',
			$source,
			$source,
			null,
			null,
			static function () use ( $source ): void {
				WP_Session_Tokens::get_instance( $source )->destroy_all();
			}
		);
	}

	private static function apply_item(
		int $run_id,
		string $object_type,
		int $object_id,
		?int $old_owner,
		?int $new_owner,
		?string $payload,
		callable $callback
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_merge_items';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (`run_id`, `object_type`, `object_id`, `old_owner`, `new_owner`, `payload`, `status`, `created_at`)
				 VALUES (%d, %s, %d, %d, %d, %s, 'planned', UTC_TIMESTAMP())",
				$table,
				$run_id,
				$object_type,
				$object_id,
				$old_owner,
				$new_owner,
				$payload
			)
		);

		$item = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `id`, `status` FROM %i WHERE `run_id` = %d AND `object_type` = %s AND `object_id` = %d',
				$table,
				$run_id,
				$object_type,
				$object_id
			)
		);

		if ( ! $item ) {
			throw new RuntimeException( 'Unable to journal merge item before applying it.' );
		}

		if ( 'applied' === $item->status ) {
			return;
		}

		$callback();
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET `status` = 'applied', `applied_at` = UTC_TIMESTAMP() WHERE `id` = %d", $table, (int) $item->id ) );
	}

	private static function reconcile_planned_items( int $run_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pinova_identity_merge_items';
		$items = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE `run_id` = %d AND `status` = 'planned' ORDER BY `id` ASC", $table, $run_id )
		);

		foreach ( $items as $item ) {
			if ( ! self::is_item_in_applied_state( $item ) ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET `status` = 'applied', `applied_at` = COALESCE(`applied_at`, UTC_TIMESTAMP()) WHERE `id` = %d AND `status` = 'planned'",
					$table,
					(int) $item->id
				)
			);
		}
	}

	private static function is_item_in_applied_state( object $item ): bool {
		global $wpdb;

		switch ( $item->object_type ) {
			case 'post':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `post_author` FROM %i WHERE `ID` = %d', $wpdb->posts, $item->object_id ) ) === (int) $item->new_owner;
			case 'comment':
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `user_id` FROM %i WHERE `comment_ID` = %d', $wpdb->comments, $item->object_id ) ) === (int) $item->new_owner;
			case 'order':
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $item->object_id ) : false;

				return $order && (int) $order->get_customer_id() === (int) $item->new_owner;
			case 'download':
				$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

				return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
					&& (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `user_id` FROM %i WHERE `permission_id` = %d', $table, $item->object_id ) ) === (int) $item->new_owner;
			case 'identity':
				return (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT `user_id` FROM %i WHERE `id` = %d', $wpdb->prefix . 'pinova_identities', $item->object_id )
				) === (int) $item->new_owner;
			case 'account_state':
				return (int) get_user_meta( (int) $item->object_id, 'pinova_merged_into', true ) === (int) $item->new_owner
					&& 1 === (int) get_user_meta( (int) $item->object_id, 'pinova_account_disabled', true );
			case 'session':
				return false;
			default:
				if ( str_starts_with( $item->object_type, 'user_meta:' ) ) {
					$payload = json_decode( (string) $item->payload, true ) ?: [];
					$key     = sanitize_key( (string) ( $payload['key'] ?? '' ) );

					return $key && hash_equals(
						(string) ( $payload['value_hash'] ?? '' ),
						self::value_hash( get_user_meta( (int) $item->new_owner, $key, true ) )
					);
				}
		}

		return false;
	}

	private static function require_no_planned_items( int $run_id ): void {
		global $wpdb;

		$planned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE `run_id` = %d AND `status` = 'planned'",
				$wpdb->prefix . 'pinova_identity_merge_items',
				$run_id
			)
		);

		if ( $planned > 0 ) {
			throw new RuntimeException( 'Merge has unresolved journal items and remains resumable.' );
		}
	}

	private static function rollback_item( object $item ): bool {
		global $wpdb;

		switch ( $item->object_type ) {
			case 'post':
				$restored = 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `post_author` = %d WHERE `ID` = %d AND `post_author` = %d', $wpdb->posts, $item->old_owner, $item->object_id, $item->new_owner ) );

				if ( $restored ) {
					clean_post_cache( (int) $item->object_id );
				}

				return $restored;
			case 'comment':
				$restored = 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d WHERE `comment_ID` = %d AND `user_id` = %d', $wpdb->comments, $item->old_owner, $item->object_id, $item->new_owner ) );

				if ( $restored ) {
					clean_comment_cache( (int) $item->object_id );
				}

				return $restored;
			case 'order':
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $item->object_id ) : false;

				if ( ! $order || (int) $order->get_customer_id() !== (int) $item->new_owner ) {
					return false;
				}

				$order->set_customer_id( (int) $item->old_owner );
				$order->save();

				return true;
			case 'download':
				$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

				return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d WHERE `permission_id` = %d AND `user_id` = %d', $table, $item->old_owner, $item->object_id, $item->new_owner ) );
			case 'identity':
				$table      = $wpdb->prefix . 'pinova_identities';
				$payload    = json_decode( (string) $item->payload, true ) ?: [];
				$is_primary = empty( $payload['is_primary'] ) ? 0 : 1;

				return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `user_id` = %d, `is_primary` = %d, `updated_at` = UTC_TIMESTAMP() WHERE `id` = %d AND `user_id` = %d', $table, $item->old_owner, $is_primary, $item->object_id, $item->new_owner ) );
			case 'account_state':
				if (
					(int) get_user_meta( (int) $item->object_id, 'pinova_merged_into', true ) !== (int) $item->new_owner
					|| 1 !== (int) get_user_meta( (int) $item->object_id, 'pinova_account_disabled', true )
				) {
					return false;
				}

				$payload = json_decode( (string) $item->payload, true ) ?: [];
				self::restore_meta( (int) $item->object_id, 'pinova_merged_into', $payload['merged_into'] ?? '' );
				self::restore_meta( (int) $item->object_id, 'pinova_account_disabled', $payload['disabled'] ?? '' );

				return true;
			case 'session':
				return false;
			default:
				if ( str_starts_with( $item->object_type, 'user_meta:' ) ) {
					$payload       = json_decode( (string) $item->payload, true ) ?: [];
					$key           = sanitize_key( (string) ( $payload['key'] ?? '' ) );
					$current_value = get_user_meta( (int) $item->new_owner, $key, true );

					if ( $key && hash_equals( (string) ( $payload['value_hash'] ?? '' ), self::value_hash( $current_value ) ) ) {
						delete_user_meta( (int) $item->new_owner, $key, $current_value );

						return true;
					}
				}
		}

		return false;
	}

	private static function restore_meta( int $user_id, string $key, $value ): void {
		if ( '' === $value || null === $value ) {
			delete_user_meta( $user_id, $key );
		} else {
			update_user_meta( $user_id, $key, $value );
		}
	}

	private static function require_updated( $result, string $object_type, int $object_id ): void {
		if ( 1 !== $result ) {
			throw new RuntimeException( sprintf( 'Unable to move %s #%d; merge remains resumable.', $object_type, $object_id ) );
		}
	}

	private static function value_hash( $value ): string {
		return hash_hmac( 'sha256', maybe_serialize( $value ), wp_salt( 'auth' ) );
	}

	private static function copyable_meta( int $source, int $target ): array {
		$copy = [];

		foreach ( self::PROFILE_META_KEYS as $key ) {
			$source_value = get_user_meta( $source, $key, true );

			if ( '' !== $source_value && '' === get_user_meta( $target, $key, true ) ) {
				$copy[ $key ] = $source_value;
			}
		}

		return $copy;
	}

	private static function order_count( int $user_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$result = wc_get_orders(
			[
				'customer_id' => $user_id,
				'limit'       => 1,
				'paginate'    => true,
				'return'      => 'ids',
			]
		);

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( (array) $result );
	}

	private static function stored_woocommerce_order_count( int $user_id ): int {
		global $wpdb;

		$count      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE `meta_key` = '_customer_user' AND `meta_value` = %s",
				$wpdb->postmeta,
				(string) $user_id
			)
		);
		$hpos_table = $wpdb->prefix . 'wc_orders';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$count += (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `customer_id` = %d', $hpos_table, $user_id )
			);
		}

		return $count;
	}

	private static function download_count( int $user_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `user_id` = %d', $table, $user_id ) )
			: 0;
	}
}
