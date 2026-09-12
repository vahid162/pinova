<?php


namespace Pinova\API;

use Carbon\Carbon;
use Exception;
use Pinova\Exceptions\RateLimitException;
use Pinova\Exceptions\SendOTPException;
use Pinova\Helper;
use Pinova\Helpers\IP;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Pinova\Services\ChannelService;
use Pinova\Services\FirewallService;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class UserAPI extends RestAPI {

	public function register_routes() {

		register_rest_route( 'pinova/user', '/authenticate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'authenticate' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'identifier' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'identifier' ],
				],
			],
		] );

		register_rest_route( 'pinova/user', '/login/password', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login_password' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'identifier' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'identifier' ],
				],
				'password'   => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'password' ],
				],
			],
		] );

		register_rest_route( 'pinova/user', '/login/otp', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login_otp' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'jwt'  => [
					'required' => true,
				],
				'code' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'code' ],
				],
			],
		] );

		register_rest_route( 'pinova/auth', '/forgot/verify', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'forgot_verify' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'jwt'  => [
					'required' => true,
				],
				'code' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'code' ],
				],
			],
		] );

		register_rest_route( 'pinova/auth', '/forgot/change', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'forgot_change' ],
			'permission_callback' => [ $this, 'permission_callback' ],
			'args'                => [
				'jwt'        => [
					'required' => true,
				],
				'reset_key'  => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'reset_key' ],
				],
				'password_1' => [
					'required'          => true,
					'validate_callback' => [ ValidationService::class, 'new_password' ],
				],
			],
		] );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function authenticate( WP_REST_Request $request ): WP_REST_Response {
		$started = microtime( true );

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );
		$force_otp  = boolval( $request->get_param( 'force_otp' ) );
		$forget     = boolval( $request->get_param( 'forget' ) );

		$user_id      = UserService::match( $identifier );
		$user         = $user_id ? get_userdata( $user_id ) : false;
		$native_only  = $user instanceof WP_User && UserService::is_native_only( $user );
		$login_method = ( $identifier->is_mobile() || $force_otp || $forget ) ? 'otp' : 'password';
		$data         = [
			'login_method' => $login_method,
			'ttl'          => JWT::DEFAULT_TTL,
		];

		if ( 'password' === $login_method ) {
			return self::response( true, null, $data );
		}

		if ( $identifier->is_username() ) {
			return self::uniform_failure( $started );
		}

		if ( $native_only ) {
			$data['jwt'] = self::decoy_otp_jwt();
			self::minimum_response_time( $started );

			return self::response(
				true,
				__( 'اگر حسابی با این شناسه وجود داشته باشد، کد تأیید ارسال می‌شود.', 'pinova' ),
				$data
			);
		}

		if ( ! $user_id && ( $forget || ! Pinova::users_can_register() || $identifier->is_email() ) ) {
			$data['jwt'] = self::decoy_otp_jwt();
			self::minimum_response_time( $started );

			return self::response(
				true,
				__( 'اگر حسابی با این شناسه وجود داشته باشد، کد تأیید ارسال می‌شود.', 'pinova' ),
				$data
			);
		}

		/** @var OTP|null $current_otp */
		$current_otp = OTP::query()
			->where( 'identifier', $identifier->get_value() )
			->whereNull( 'verified_at' )
			->where( 'expires_at', '>', Carbon::now() )
			->first();

		if ( $current_otp ) {
			$ttl         = max( 1, $current_otp->expires_at->diffInSeconds() );
			$data['ttl'] = $ttl;
			$data['jwt'] = JWT::encode( [ 'otp_id' => $current_otp->id ], $ttl );

			return self::response( true, ChannelService::get_message( $current_otp->channels, $identifier ), $data );
		}

		try {
			$type = $user_id ? OTP::TYPE_LOGIN : OTP::TYPE_REGISTER;

			if ( $forget ) {
				$type = OTP::TYPE_FORGET;
			}

			[ $data['jwt'], $successful_channels ] = OTPService::create(
				$identifier,
				ChannelService::get_channels( $identifier ),
				$type,
				$user_id
			);
			$message = ChannelService::get_message( $successful_channels, $identifier );
		} catch ( RateLimitException $e ) {
			return self::response(
				false,
				__( 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'pinova' ),
				[],
				429,
				[ 'Retry-After' => $e->get_retry_after() ]
			);
		} catch ( SendOTPException $e ) {
			return self::response( false, $e->getMessage(), [], 503 );
		} catch ( Exception $e ) {
			return self::response( false, __( 'امکان پردازش درخواست وجود ندارد.', 'pinova' ), [], 500 );
		}

		return self::response( true, $message, $data );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function login_password( WP_REST_Request $request ): WP_REST_Response {
		$started = microtime( true );

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );
		$password   = (string) $request->get_param( 'password' );

		try {
			RateLimitService::password( IP::get(), $identifier->get_value() );
		} catch ( RateLimitException $e ) {
			return self::response(
				false,
				__( 'تعداد تلاش‌های ورود بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'pinova' ),
				[],
				429,
				[ 'Retry-After' => $e->get_retry_after() ]
			);
		}

		$user_id = UserService::match( $identifier );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( $user instanceof WP_User && UserService::is_native_only( $user ) ) {
			return self::password_failure( $started );
		}

		$authenticated = UserService::authenticate_password( $user_id, $password, true );

		if ( is_wp_error( $authenticated ) ) {
			return self::password_failure( $started );
		}

		update_user_meta( $authenticated->ID, 'pinova_login_method', 'password' );
		do_action( 'pinova/user_logged_in', $authenticated->ID );

		return self::response( true, __( 'ورود با موفقیت انجام شد.', 'pinova' ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function login_otp( WP_REST_Request $request ): WP_REST_Response {

		$jwt  = $request->get_param( 'jwt' );
		$code = $request->get_param( 'code' );

		try {
			$user = OTPService::verify( $jwt, $code );
		} catch ( Exception $e ) {
			return self::response( false, __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		if ( UserService::is_native_only( $user ) ) {
			return self::response( false, __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		UserService::login( $user->ID, 'otp' );

		return self::response( true, __( 'ورود با موفقیت انجام شد.', 'pinova' ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function forgot_verify( WP_REST_Request $request ): WP_REST_Response {

		$jwt  = $request->get_param( 'jwt' );
		$code = $request->get_param( 'code' );

		try {
			$user = OTPService::verify( $jwt, $code );
		} catch ( Exception $e ) {
			return self::response( false, __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		if ( UserService::is_native_only( $user ) ) {
			return self::response( false, __( 'کد تأیید معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		clean_user_cache( $user->ID );
		$reset_key = get_password_reset_key( $user );

		if ( is_wp_error( $reset_key ) ) {
			return self::response( false, __( 'امکان بازنشانی رمز عبور وجود ندارد.', 'pinova' ), [], 500 );
		}

		return self::response( true, null, [
			'jwt'       => UserService::generate_jwt( $user->ID ),
			'reset_key' => $reset_key,
		] );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function forgot_change( WP_REST_Request $request ): WP_REST_Response {

		$jwt              = $request->get_param( 'jwt' );
		$reset_key        = $request->get_param( 'reset_key' );
		$password         = $request->get_param( 'password_1' );
		$password_confirm = $request->get_param( 'password_2' );

		if ( ! hash_equals( (string) $password, (string) $password_confirm ) ) {
			return self::response( false, __( 'رمز عبور و تکرار رمز عبور یکسان نیستند.', 'pinova' ), [], 400 );
		}

		try {
			$user_id = UserService::parse_jwt( $jwt );
		} catch ( Exception $e ) {
			return self::response( false, __( 'درخواست بازنشانی معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User || UserService::is_native_only( $user ) ) {
			return self::response( false, __( 'درخواست بازنشانی معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		$user = check_password_reset_key( $reset_key, $user->user_login );

		if ( is_wp_error( $user ) ) {
			return self::response( false, __( 'درخواست بازنشانی معتبر نمی‌باشد.', 'pinova' ), [], 401 );
		}

		reset_password( $user, $password );

		UserService::login( $user_id, 'password' );

		return self::response( true, __( 'رمزعبور با موفقیت بازنشانی شد و به سیستم وارد شدید.', 'pinova' ) );
	}

	/**
	 * The middleware to check if user can log out
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool|WP_Error
	 */
	public function permission_callback_logout( WP_REST_Request $request ) {

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'pinova_login_required', __( 'در ابتدا وارد شوید.', 'pinova' ), [ 'status' => 401 ] );
		}

		return true;
	}


	/**
	 * The middleware to check if user can access the routes and set initial important values in 'request' param
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool|WP_Error
	 */
	public function permission_callback( WP_REST_Request $request ) {

		if ( is_user_logged_in() ) {
			return new WP_Error( 'pinova_already_logged_in', __( 'شما وارد شده‌اید.', 'pinova' ), [ 'status' => 403 ] );
		}

		if ( FirewallService::is_ip_blocked() ) {
			return new WP_Error( 'pinova_ip_blocked', __( 'آدرس آی.پی شما مسدود شده است.', 'pinova' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * @param bool        $success
	 * @param string|null $message
	 * @param array       $data
	 *
	 * @param int         $status
	 * @param array       $headers
	 *
	 * @return WP_REST_Response
	 */
	public static function response(
		bool $success,
		?string $message = null,
		array $data = [],
		int $status = 200,
		array $headers = []
	): WP_REST_Response {

		if ( $success ) {
			$data['back_url'] = Helper::get_login_back_url();
		}

		return parent::response( $success, $message, $data, $status, $headers );
	}

	private static function decoy_otp_jwt(): string {
		return JWT::encode( [
			'otp_id' => 0,
			'nonce'  => wp_generate_password( 20, false ),
		] );
	}

	private static function uniform_failure( float $started ): WP_REST_Response {
		self::minimum_response_time( $started );

		return self::response( false, __( 'امکان پردازش درخواست ورود وجود ندارد.', 'pinova' ), [], 400 );
	}

	private static function password_failure( float $started ): WP_REST_Response {
		self::minimum_response_time( $started );

		return self::response( false, __( 'اطلاعات ورود معتبر نمی‌باشد.', 'pinova' ), [], 401 );
	}

	private static function minimum_response_time( float $started ): void {
		$remaining = 0.35 - ( microtime( true ) - $started );

		if ( $remaining > 0 ) {
			usleep( (int) ( $remaining * 1000000 ) );
		}
	}

}
