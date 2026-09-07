<?php


namespace Pinova\API;

use Illuminate\Database\Eloquent\Builder;
use Pinova\Helper;
use Pinova\Helpers\IP;
use Pinova\Models\Block;
use Pinova\Objects\Identifier;
use WP_REST_Request;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

class BlocksAPI extends RestAPI {

	public function register_routes() {

		register_rest_route( 'pinova/admin/blocks', 'filters', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'filters' ],
			'permission_callback' => [ $this, 'permission_callback' ],
		] );

		register_rest_route( 'pinova/admin/blocks', 'index', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'identifier' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'from_date'  => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
				'to_date'    => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
				'blocked_by' => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
				'page'       => [
					'required'          => false,
					'sanitize_callback' => 'absint',
					'default'           => 1,
				],
				'per_page'   => [
					'required'          => false,
					'sanitize_callback' => 'absint',
					'default'           => 20,
				],
			],
		] );

		register_rest_route( 'pinova/admin/blocks', 'add', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'identifier'    => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'blocked_until' => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( 'pinova/admin/blocks', 'delete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'delete' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'block_id' => [
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

	}

	public function filters( WP_REST_Request $request ) {

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/user.php' );
		}

		// Users
		$roles = [];

		foreach ( array_reverse( wp_roles()->roles ) as $id => $role ) {

			if ( $role['capabilities']['manage_options'] ?? false ) {
				$roles[] = $id;
			}

		}

		$users = new WP_User_Query( [
			'number'   => - 1,
			'role__in' => $roles,
		] );
		$users = collect( $users->get_results() );

		self::response( true, null, [
			'users' => $users->pluck( 'display_name', 'ID' )->toArray(),
		] );
	}

	public function index( WP_REST_Request $request ) {

		$raw_identifier = $request->get_param( 'identifier' );
		$blocked_by     = $request->get_param( 'blocked_by' ); // @todo check it return null
		$blocked_by     = $_POST['blocked_by'] ?? null;
		$from_date      = $request->get_param( 'from_date' );
		$to_date        = $request->get_param( 'to_date' );

		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = max( 1, $request->get_param( 'per_page' ) );

		$timezone = wp_timezone_string();

		if ( ! empty( $from_date ) ) {
			$from_date = verta()
				->timestamp( intval( $from_date ) )
				->timezone( $timezone )
				->startDay()
				->toCarbon()
				->utc();
		}

		if ( ! empty( $to_date ) ) {
			$to_date = verta()
				->timestamp( intval( $to_date ) )
				->timezone( $timezone )
				->endDay()
				->toCarbon()
				->utc();
		}

		/** @var Block[]|Builder $query */
		$query = Block::query()
		              ->orderByDesc( 'id' )
		              ->when( $raw_identifier, function ( Builder $query ) use ( $raw_identifier ) {

			              $query->where( function ( Builder $query ) use ( $raw_identifier ) {

				              $query->where( 'identifier', 'LIKE', "%{$raw_identifier}%" );

				              $identifier = new Identifier( $raw_identifier );

				              if ( $identifier->is_valid() ) {
					              $query->orWhere( 'identifier', '=', $identifier->get_value() );
				              }

			              } );

		              } )
		              ->when( $blocked_by, function ( Builder $query ) use ( $blocked_by ) {

			              $users = new WP_User_Query( [
				              'search' => "*{$blocked_by}*",
				              'fields' => 'ID',
			              ] );

			              $query->where( 'blocked_by', '=', $users->get_results() );
		              } )
		              ->when( $from_date, function ( Builder $query ) use ( $from_date ) {
			              $query->where( 'blocked_until', '>=', $from_date );
		              } )
		              ->when( $to_date, function ( Builder $query ) use ( $to_date ) {
			              $query->where( 'blocked_until', '<=', $to_date );
		              } );

		$total_items = $query->count();

		$blocks = $query
			->forPage( $page, $per_page )
			->get()
			->map( [ $this, 'resource' ] )
			->toArray();

		self::response( true, null, [
			'blocks'       => $blocks,
			'current_page' => intval( $page ),
			'total_items'  => intval( $total_items ),
		] );
	}

	public function add( WP_REST_Request $request ) {

		$raw_identifier = $request->get_param( 'identifier' );
		$blocked_until  = $request->get_param( 'blocked_until' );

		if ( empty( $blocked_until ) ) {
			$blocked_until = null;
		} else {

			$timezone = wp_timezone_string();

			$blocked_until = verta()
				->timestamp( intval( $blocked_until ) )
				->timezone( $timezone )
				->endDay()
				->toCarbon()
				->utc();

			if ( $blocked_until->isPast() ) {
				self::response( false, __( 'تاریخ مسدودیت باید در آینده باشد.', 'pinova' ) );
			}
		}

		$identifier = $this->expand_identifier( $raw_identifier );

		if ( empty( $identifier ) ) {
			self::response( false, __( 'شناسه وارد شده معتبر نمی‌باشد.', 'pinova' ) );
		}

		/**@var Block $block */
		$block = Block::query()->updateOrCreate( [
			'identifier' => $identifier,
		], [
			'blocked_by'    => get_current_user_id(),
			'blocked_until' => $blocked_until,
		] );

		self::response( true, sprintf( 'شناسه %s با موفقیت مسدود شد.', $identifier ), [
			'block_id' => $block->id,
			'blocks'   => $this->blocks(),
		] );
	}

	public function delete( WP_REST_Request $request ) {

		$block_id = $request->get_param( 'block_id' );

		try {

			/** @var Block $block */
			$block = Block::query()->findOrFail( $block_id );

		} catch ( \Exception $e ) {
			self::response( false, $e->getMessage() );
		}

		if ( empty( $block->blocked_by ) ) {
			self::response( false, 'مسدودی‌های سیستمی قابل حذف نیستند.', [
				'blocks' => $this->blocks(),
			] );
		}

		$block->delete();

		self::response( true, 'شناسه با موفقیت رفع مسدودی شد.', [
			'blocks' => $this->blocks(),
		] );
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		return current_user_can( 'manage_options' );
	}

	public function blocks(): array {
		return Block::query()
		            ->orderByDesc( 'id' )
		            ->get()
		            ->map( [ $this, 'resource' ] )
		            ->toArray();
	}

	public function resource( Block $block ): array {
		return [
			'id'            => $block->id,
			'identifier'    => $block->identifier,
			'blocked_by'    => $block->blocked_by_label,
			'blocked_until' => $block->blocked_until ? Helper::date( $block->blocked_until, 'Y/m/d H:i' ) : null,
		];
	}

	private function expand_identifier( string $raw_identifier = '' ): ?string {

		if ( empty( $raw_identifier ) ) {
			return null;
		}

		$identifier = new Identifier( $raw_identifier );

		if ( IP::is_valid( $raw_identifier ) ) {
			return $raw_identifier;
		}

		if ( $identifier->get_type() === 'username' ) {

			$user = get_user_by( 'login', $identifier->get_value() );

			if ( $user instanceof WP_User ) {
				return $user->user_login;
			}

			return null;
		}

		// Phone/Email
		return $identifier->get_value();
	}
}