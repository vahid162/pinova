<?php


namespace Pinova\API;

use Carbon\Carbon;
use Exception;
use Pinova\Exceptions\SendOTPException;
use Pinova\Helper;
use Pinova\Helpers\JWT;
use Pinova\Models\OTP;
use Pinova\Objects\Identifier;
use Pinova\Pinova;
use Pinova\Services\ChannelService;
use Pinova\Services\FirewallService;
use Pinova\Services\OTPService;
use Pinova\Services\UserService;
use Pinova\Services\ValidationService;
use WP_REST_Request;

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
	 * @return void
	 */
	public function authenticate( WP_REST_Request $request ): void {

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );
		$force_otp  = boolval( $request->get_param( 'force_otp' ) );
		$forget     = boolval( $request->get_param( 'forget' ) );

		$data = [
			'has_password' => false,
			'has_account'  => false,
			'login_method' => 'otp',
			'ttl'          => JWT::DEFAULT_TTL,
		];

		$user_id = UserService::match( $identifier );
		// @todo set attempt, prevent user enumeration

		if ( $user_id ) {

			$data['has_account'] = true;

			$user = get_user_by( 'id', $user_id );

			$data['has_password'] = ! str_starts_with( $user->user_pass, 'NO_PASSWORD_' );

			if ( $identifier->is_email() || $identifier->is_username() ) {
				$data['login_method'] = 'password';
			}

			if ( $user->get( 'pinova_login_method' ) == 'password' ) {
				$data['login_method'] = 'password';
			}

			if ( $data['has_password'] && Pinova::get_option( 'advanced.default_login_method' ) == 'password' ) {
				$data['login_method'] = 'password';
			}

		} elseif ( $forget ) {
			self::response( false, __( 'حساب کاربری یافت نشد.', 'pinova' ) );
		} elseif ( ! Pinova::users_can_register() ) {
			self::response( false, __( 'عضویت در سایت غیرفعال است.', 'pinova' ) );
		} elseif ( $identifier->is_email() ) {
			self::response( false, __( 'حساب کاربری با این ایمیل وجود ندارد. برای عضویت از تلفن همراه استفاده کنید.', 'pinova' ) );
		}

		$force_otp = $force_otp || $forget;

		if ( $force_otp ) {
			$data['login_method'] = 'otp';
		}

		$message = null;

		if ( $data['login_method'] == 'otp' ) {

			if ( $identifier->is_username() ) {
				self::response( false, __( 'برای دریافت کد تایید، از تلفن همراه یا ایمیل استفاده کنید.', 'pinova' ) );
			}

			/** @var OTP $current_otp */
			$current_otp = OTP::query()
			                  ->where( 'identifier', $identifier->get_value() )
			                  ->where( 'expires_at', '>', Carbon::now() )
			                  ->first();

			if ( $current_otp ) {

				$ttl = $current_otp->expires_at->diffInSeconds();

				if ( $current_otp->isVerified() ) {

					if ( $force_otp ) {
						self::response( false, sprintf( 'برای دریافت کد تایید %d ثانیه دیگر تلاش کنید.', $ttl ) );
					} elseif ( $data['has_password'] ) {
						$data['login_method'] = 'password';
					}

				} else {

					$data['ttl'] = $ttl;
					$data['jwt'] = JWT::encode( [
						'otp_id' => $current_otp->id,
					], $ttl );

				}

				$message = ChannelService::get_message( $current_otp->channels, $identifier, $user_id );

			} else {

				try {

					$type = OTP::TYPE_REGISTER;

					if ( $user_id ) {
						$type = OTP::TYPE_LOGIN;

						if ( $forget ) {
							$type = OTP::TYPE_FORGET;
						}
					}

					$channels = ChannelService::get_channels( $identifier );

					[
						$data['jwt'],
						$successful_channels,
					] = OTPService::create( $identifier, $channels, $type, $user_id );

					$message = ChannelService::get_message( $successful_channels, $identifier, $user_id );

				} catch ( SendOTPException $e ) {

					if ( $force_otp ) {
						self::response( false, $e->getMessage() );
					} elseif ( $data['has_password'] ) {
						$data['login_method'] = 'password';
					} else {
						self::response( false, $e->getMessage() );
					}

				} catch ( Exception $e ) {
					self::response( false, $e->getMessage() );
				}

			}

		}

		self::response( true, $message, $data );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return void
	 */
	public function login_password( WP_REST_Request $request ): void {

		/** @var Identifier $identifier */
		$identifier = $request->get_param( 'identifier' );
		$password   = $request->get_param( 'password' );

		$user_id = UserService::match( $identifier );
		// @todo set attempt, prevent user enumeration

		if ( ! $user_id ) {
			self::response( false, __( 'اطلاعات ورود معتبر نمی‌باشد.', 'pinova' ) );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			self::response( false, __( 'اطلاعات ورود معتبر نمی‌باشد.', 'pinova' ) );
		}

		UserService::login( $user_id, 'password' );

		self::response( true, __( 'ورود با موفقیت انجام شد.', 'pinova' ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return void
	 */
	public function login_otp( WP_REST_Request $request ): void {

		$jwt  = $request->get_param( 'jwt' );
		$code = $request->get_param( 'code' );

		try {
			$user = OTPService::verify( $jwt, $code );
		} catch ( Exception $e ) {
			self::response( false, $e->getMessage() );
		}

		UserService::login( $user->ID, 'otp' );

		self::response( true, __( 'ورود با موفقیت انجام شد.', 'pinova' ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return void
	 */
	public function forgot_verify( WP_REST_Request $request ): void {

		$jwt  = $request->get_param( 'jwt' );
		$code = $request->get_param( 'code' );

		try {
			$user = OTPService::verify( $jwt, $code );
		} catch ( Exception $e ) {
			self::response( false, $e->getMessage() );
		}

		clean_user_cache( $user->ID );
		$reset_key = get_password_reset_key( $user );

		self::response( true, null, [
			'jwt'       => UserService::generate_jwt( $user->ID ),
			'reset_key' => $reset_key,
		] );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return void
	 */
	public function forgot_change( WP_REST_Request $request ): void {

		$jwt              = $request->get_param( 'jwt' );
		$reset_key        = $request->get_param( 'reset_key' );
		$password         = $request->get_param( 'password_1' );
		$password_confirm = $request->get_param( 'password_2' );

		if ( $password !== $password_confirm ) {
			self::response( false, __( 'رمز عبور و تکرار رمز عبور یکسان نیستند.', 'pinova' ) );
		}

		try {
			$user_id = UserService::parse_jwt( $jwt );
		} catch ( Exception $e ) {
			self::response( false, $e->getMessage() );
		}

		$user = get_user_by( 'id', $user_id );

		$user = check_password_reset_key( $reset_key, $user->user_login );

		if ( is_wp_error( $user ) ) {
			self::response( false, $user->get_error_message() );
		}

		reset_password( $user, $password );

		UserService::login( $user_id, 'password' );

		self::response( true, __( 'رمزعبور با موفقیت بازنشانی شد و به سیستم وارد شدید.', 'pinova' ) );
	}

	/**
	 * The middleware to check if user can log out
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback_logout( WP_REST_Request $request ): bool {

		if ( ! is_user_logged_in() ) {
			self::response( false, __( 'در ابتدا وارد شوید.', 'pinova' ) );
		}

		return true;
	}


	/**
	 * The middleware to check if user can access the routes and set initial important values in 'request' param
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function permission_callback( WP_REST_Request $request ): bool {

		if ( is_user_logged_in() ) {
			self::response( false, __( 'شما وارد شده اید.', 'pinova' ) );
		}

		if ( FirewallService::is_ip_blocked() ) {
			self::response( false, __( 'آدرس آی.پی شما مسدود شده است.', 'pinova' ) );
		}

		return true;
	}

	/**
	 * @param bool        $success
	 * @param string|null $message
	 * @param array       $data
	 *
	 * @return no-return
	 */
	public static function response( bool $success, ?string $message = null, array $data = [] ): void {

		if ( $success ) {
			$data['back_url'] = Helper::get_login_back_url();
		}

		parent::response( $success, $message, $data );
	}

}
