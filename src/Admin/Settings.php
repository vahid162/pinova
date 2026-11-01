<?php

namespace Pinova\Admin;

use Pinova\Integrations\IntegrationSettings;
use Pinova\Integrations\Woocommerce\VirtualCheckout;
use Pinova\Integrations\Wordpress\NativeLoginGate;
use Pinova\Logging\DiagnosticContext;
use Pinova\Logging\EventThrottle;
use Pinova\Services\SMSService;
use Pinova\Services\UserService;
use Psr\Log\LogLevel;

class Settings extends \Nabik\Utils\V1\Settings {

	protected static ?\Nabik\Utils\V1\Settings $_instance = null;

	/**
	 * Last authorized normalization, only for the Settings API's same-request second pass.
	 *
	 * @var array{previous:array<array-key,mixed>,value:array<string,mixed>,actor:int}|null
	 */
	private static ?array $logging_normalization = null;

	private static bool $logging_read_failed = false;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * @return void
	 */
	public static function render(): void {

		$settings = self::instance();

		echo '<div class="wrap">';

		$settings->init();
		$settings->show_navigation();
		$settings->show_forms();

		echo '</div>';
	}


	/**
	 * @return array
	 */
	public function get_sections(): array {
		$sections = [
			[
				'id'    => 'pinova_general',
				'title' => 'همگانی',
			],
			[
				'id'    => 'pinova_sms',
				'title' => 'پیامک',
			],
			[
				'id'    => 'pinova_messengers',
				'title' => 'پیام‌رسان‌ها',
			],
			[
				'id'    => 'pinova_zohal',
				'title' => 'زحل',
			],
			[
				'id'    => 'pinova_design',
				'title' => 'ظاهر',
			],
			[
				'id'    => 'pinova_logging',
				'title' => 'گزارش‌ها',
			],
			[
				'id'    => IntegrationSettings::OPTION,
				'title' => 'یکپارچه‌سازی‌ها',
			],
			[
				'id'    => 'pinova_advanced',
				'title' => 'پیشرفته',
			],
		];

		return apply_filters( 'pinova/settings_sections', $sections );
	}

	/**
	 * @return array
	 */
	public function get_fields(): array {

		$roles            = array_map(
			static fn( string $name ): string => $name . ' - ' . translate_user_role( $name ),
			UserService::allowed_registration_roles()
		);
		$all_roles        = array_map(
			static fn( array $role ): string => $role['name'] . ' - ' . translate_user_role( $role['name'] ),
			get_editable_roles()
		);
		$native_login_url = NativeLoginGate::url();

		$settings_fields = [
			'pinova_general'            => [
				[
					'id'    => 'bale_group',
					'label' => '',
					'type'  => 'html',
					'desc'  => 'پرسش و پاسخ درباره افزونه پینوا در گروه بله: <a href="https://ble.ir/webmastertalk" target="_blank">https://ble.ir/webmastertalk</a>',
				],
				[
					'id'    => 'wordpress',
					'label' => 'وردپرس',
					'type'  => 'html',
				],
				[
					'id'      => 'wordpress_users_can_register',
					'label'   => 'عضویت',
					'desc'    => 'هر کسی می‌تواند نام‌نویسی کند',
					'type'    => 'checkbox',
					'default' => 1 === (int) get_option( 'users_can_register' ),
				],
				[
					'id'                => 'wordpress_default_role',
					'label'             => 'نقش پیشفرض کاربر تازه',
					'type'              => 'select',
					'options'           => $roles,
					'default'           => get_option( 'default_role' ),
					'sanitize_callback' => [ self::class, 'sanitize_registration_role' ],
				],
				[
					'id'    => 'woocommerce',
					'label' => 'ووکامرس',
					'type'  => 'html',
				],
				[
					'id'      => 'woocommerce_checkout_registration_required',
					'label'   => 'ورود/عضویت اجباری',
					'desc'    => 'در تسویه حساب ووکامرس، ورود/عضویت اجباری باشد؟',
					'type'    => 'select',
					'options' => [
						'no'            => 'غیرفعال، ثبت سفارش مهمان',
						'yes_redirect'  => 'فعال، ورود/عضویت قبل از مشاهده تسویه حساب',
						'yes_automatic' => 'فعال، ایجاد خودکار حساب کاربری بدون احراز هویت',
					],
					'default' => function_exists( 'WC' ) && WC()->checkout()->is_registration_required() ? 'yes_redirect' : 'no',
				],
				[
					'id'                => VirtualCheckout::SETTING,
					'label'             => 'تسویه‌حساب سادهٔ محصولات مجازی',
					'type'              => 'checkbox',
					'default'           => false,
					'desc'              => VirtualCheckout::description(),
					'sanitize_callback' => [ VirtualCheckout::class, 'sanitize_enabled' ],
				],
			],
			'pinova_sms'                => [
				function_exists( 'PWSMS' ) ? [] : [
					'id'    => 'persian_woocommerce_sms',
					'label' => '',
					'type'  => 'html',
					'desc'  => 'برای استفاده از وب سرویس‌های پیامکی بیشتر می‌توانید افزونه جامع پیامکی persian woocommerce sms را نصب و فعال نمایید: <a href="' . admin_url( 'plugin-install.php?tab=plugin-information&plugin=persian-woocommerce-sms' ) . '" target="_blank">نصب و فعالسازی رایگان</a>',
				],
				[
					'id'      => 'gateway',
					'label'   => 'وبسرویس',
					'type'    => 'select',
					'default' => 'none',
					'options' => SMSService::list(),
				],
				[
					'id'          => 'message_code',
					'label'       => 'پیامک کد تایید',
					'type'        => 'textarea',
					'default'     => "کد تایید شما: {{otp}}\nلطفاً آن را با دیگران به اشتراک نگذارید.",
					'desc'        =>
						'از کد کوتاه {{otp}} برای جایگزینی رمز عبور ارسالی استفاده نمایید.<br>' .
						'برای ثبت الگوی پیامک به روش زیر عمل کنید:<br>' .
						'<code>pattern:PatternCode<br>ParamName:{{otp}}</code>',
					'field_class' => 'ltr',
				],
				[
					'id'      => 'test_mobile',
					'label'   => 'تست ارسال پیامک',
					'type'    => 'number',
					'default' => '',
					'desc'    => 'پس از ذخیره تغییرات، برای تست ارسال پیامک کد تایید، شماره تلفن همراه خود را وارد کنید.',
				],
			],
			'pinova_messengers'         => [
				[
					'id'    => 'bale',
					'label' => 'بله',
					'type'  => 'html',
					'desc'  => 'برای دریافت توکن بله، <a href="https://business.bale.ai/dashboard/safir/otp" target="_blank"> ثبت نام کرده و وارد شوید</a>، سپس از منو ارسال پیام » وب‌سرویس » رمز یکبار مصرف، توکن را دریافت کنید | <a href="https://www.aparat.com/v/ljv62af" target="_blank">آموزش اتصال پینوا به بله</a>',
				],
				[
					'id'      => 'bale_api_token',
					'label'   => 'توکن وب سرویس (API)',
					'type'    => 'text',
					'desc'    => 'از مسیر ارسال پیام > وب‌سرویس > رمز یکبار مصرف دریافت کنید.',
					'default' => '',
				],
				[
					'id'      => 'bale_bot_id',
					'label'   => 'شناسه بازو (Bot_id)',
					'type'    => 'text',
					'default' => '',
				],
			],
			'pinova_zohal'              => [
				[
					'id'   => 'introduce',
					'type' => 'html',
					'desc' => 'زحل ارائه دهنده سرویس‌های استعلام و احراز هویت است. با فعالسازی زحل می‌توانید از امکانات زیر بهره ببرید:
<ul>
<li>ارسال رمز یکبار مصرف از طریق تماس</li>
<li>استعلام و احراز هویت کاربران (بزودی)</li>
</ul>',
				],
				[
					'id'      => 'api_key',
					'label'   => 'توکن',
					'type'    => 'text',
					'default' => '',
					'desc'    => 'برای دریافت توکن زحل، <a href="https://l.nabik.net/zohal" target="_blank"> ثبت نام کرده و وارد شوید</a>، سپس از منو توسعه‌دهنگان یک توکن ایجاد کنید | <a href="https://www.aparat.com/v/dfnpr73" target="_blank">آموزش اتصال پینوا به زحل</a>',
				],
			],
			'pinova_design'             => [
				[
					'id'    => 'logo',
					'type'  => 'photo',
					'label' => 'لوگو',
				],
			],
			'pinova_logging'            => [
				[
					'id'                => 'minimum_level',
					'label'             => 'حداقل سطح ثبت',
					'type'              => 'select',
					'options'           => [
						LogLevel::INFO    => 'Info و بالاتر',
						LogLevel::NOTICE  => 'Notice و بالاتر',
						LogLevel::WARNING => 'Warning و بالاتر (پیشنهادی)',
						LogLevel::ERROR   => 'Error و بالاتر',
					],
					'default'           => LogLevel::WARNING,
					'desc'              => 'این حداقل سطح برای رخدادهای عادی حفظ می‌شود؛ جزئیات تشخیصی فقط در دامنه و پنجرهٔ انتخاب‌شده ثبت می‌شوند.',
					'sanitize_callback' => [ self::class, 'sanitize_log_level' ],
				],
				[
					'id'                => 'retention_days',
					'label'             => 'مدت نگهداری',
					'type'              => 'number',
					'default'           => 14,
					'desc'              => 'تعداد روز نگهداری گزارش‌ها، بین ۱ تا ۹۰ روز. پاک‌سازی به‌صورت روزانه و batch انجام می‌شود.',
					'sanitize_callback' => [ self::class, 'sanitize_log_retention' ],
				],
				[
					'id'                => 'diagnostic_until',
					'label'             => 'پنجرهٔ جزئیات تشخیصی',
					'type'              => 'diagnostic_window',
					'default'           => 0,
					'desc'              => 'پنجره به‌طور خودکار منقضی می‌شود و سطح عادی ثبت را تغییر نمی‌دهد. دریافت گوشی از پذیرش سرویس‌دهنده جداست؛ هیچ کد یا شناسهٔ خامی ثبت نمی‌شود.',
					'sanitize_callback' => [ self::class, 'sanitize_diagnostic_window' ],
				],
				[
					'id'      => 'diagnostic_scope',
					'label'   => 'دامنهٔ جزئیات تشخیصی',
					'type'    => 'diagnostic_scope',
					'default' => [],
				],
			],
			IntegrationSettings::OPTION => $this->integration_fields(),
			'pinova_advanced'           => [
				[
					'id'    => 'pinova_mobile',
					'type'  => 'html',
					'label' => 'کلید متا پینوا',
					'desc'  => 'کلید استاندارد تلفن همراه پینوا pinova_mobile است. شماره‌ای که از پروفایل تغییر کند به‌صورت فیزیکی در این متا ذخیره می‌شود و بر نام کاربری موبایلی و متاهای قدیمی اولویت دارد. برای کاربران قدیمیِ بدون این مقدار، get_user_meta همچنان شمارهٔ سازگارشده را برمی‌گرداند.',
				],
				[
					'id'    => 'mobile_possible_meta_keys',
					'type'  => 'textarea',
					'label' => 'کلیدهای متا تلفن همراه کاربر',
					'desc'  => 'کلیدهای متا جدول usermeta که حاوی تلفن همراه هست را در هر خط وارد کنید. کلیدهای digits_phone و digits_phone_no (افزونه دیجیتس) به صورت پیشفرض تعریف شده اند.',
				],
				[
					'id'      => 'code_length',
					'label'   => 'تعداد ارقام کد تایید',
					'type'    => 'select',
					'options' => [
						4 => '۴ رقم - پیشفرض',
						5 => '۵ رقم',
						6 => '۶ رقم',
					],
					'default' => 4,
				],
				[
					'id'      => 'default_login_method',
					'label'   => 'روش ورود پیشفرض',
					'type'    => 'select',
					'options' => [
						'password' => 'رمز عبور',
						'otp'      => 'کد یکبار مصرف (OTP)',
					],
					'default' => 'otp',
				],
				[
					'id'                => 'native_only_roles',
					'label'             => 'نقش‌های ورود بومی',
					'type'              => 'select2',
					'options'           => $all_roles,
					'default'           => [ 'administrator' ],
					'attributes'        => [ 'multiple' => true ],
					'desc'              => 'این نقش‌ها فقط از مسیر خصوصی ورود مدیریتی و سازوکار رمز/2FA وردپرس وارد می‌شوند. پیش‌فرض: مدیرکل.',
					'sanitize_callback' => [ self::class, 'sanitize_native_only_roles' ],
				],
				[
					'id'                => 'native_login_slug',
					'label'             => 'مسیر خصوصی ورود مدیر',
					'type'              => 'text',
					'default'           => NativeLoginGate::default_slug(),
					'field_class'       => 'ltr',
					'desc'              => sprintf(
						'ابتدا این آدرس را در یک پنجرهٔ خصوصی مرورگر آزمایش و ذخیره کنید: <span class="ltr">%s</span>. این مسیر محرمانه جای رمز عبور و 2FA را نمی‌گیرد.',
						esc_html( $native_login_url )
					),
					'sanitize_callback' => [ NativeLoginGate::class, 'sanitize_slug' ],
				],
				[
					'id'      => 'block_native_login',
					'label'   => 'مسدودسازی مسیر اصلی وردپرس',
					'type'    => 'checkbox',
					'default' => false,
					'desc'    => 'پس از آزمایش موفق مسیر خصوصی، این گزینه را فعال کنید تا ورود، ثبت‌نام و بازیابی حساب از wp-login.php پاسخ 404 بگیرد. خروج امن، تأیید درخواست حریم خصوصی و رمز نوشته‌های محافظت‌شده به‌عنوان عملیات غیرورودی وردپرس حفظ می‌شوند. برای بازیابی اضطراری می‌توان PINOVA_BLOCK_NATIVE_LOGIN را در wp-config.php برابر false قرار داد.',
				],
				[
					'id'                => 'trusted_proxy_header',
					'label'             => 'هدر پروکسی مورد اعتماد',
					'type'              => 'select',
					'options'           => [
						''                      => 'غیرفعال (فقط REMOTE_ADDR)',
						'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
						'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
						'HTTP_X_REAL_IP'        => 'X-Real-IP',
					],
					'default'           => '',
					'sanitize_callback' => [ self::class, 'sanitize_proxy_header' ],
				],
				[
					'id'                => 'trusted_proxy_cidrs',
					'label'             => 'CIDR پروکسی‌های مورد اعتماد',
					'type'              => 'textarea',
					'desc'              => 'در هر خط یک IPv4/IPv6 یا CIDR وارد کنید. تا وقتی هدر و CIDR هر دو تنظیم نشده‌اند، فقط REMOTE_ADDR استفاده می‌شود.',
					'default'           => '',
					'sanitize_callback' => [ self::class, 'sanitize_proxy_cidrs' ],
				],
				[
					'id'                => 'delete_data_on_uninstall',
					'label'             => 'پاک‌سازی هنگام حذف افزونه',
					'type'              => 'checkbox',
					'default'           => get_option( \Pinova\Install::PURGE_OPTION, false ) ? '1' : '0',
					'desc'              => 'در حالت پیش‌فرض داده‌های عملیاتی پس از حذف افزونه حفظ می‌شوند. با فعال‌کردن این گزینه، جدول‌ها، تنظیمات، داده‌های موقت و زمان‌بندی‌های پینوا هنگام Uninstall حذف می‌شوند؛ متای هویتی pinova_mobile حفظ خواهد شد.',
					'sanitize_callback' => [ self::class, 'sanitize_delete_data_on_uninstall' ],
				],
				// @todo add firewall options here
			],
		];

		return apply_filters( 'pinova/settings_fields', $settings_fields );
	}

	/**
	 * Keep native-login activation in the same atomic option update as its
	 * setting while leaving every other settings section on the base sanitizer.
	 *
	 * @param mixed $options
	 * @return mixed
	 */
	public function sanitize_options( $options ) {
		if ( 'sanitize_option_pinova_logging' === current_filter() ) {
			return self::sanitize_logging_options( $options );
		}

		if ( 'sanitize_option_pinova_integrations' === current_filter() ) {
			// options.php enforces the Settings API nonce; also reject unauthorized programmatic saves.
			return IntegrationSettings::normalize(
				current_user_can( 'manage_options' ) ? $options : get_option( IntegrationSettings::OPTION, [] )
			);
		}

		if (
			NativeLoginGate::is_runtime_invalidation_update()
			&& function_exists( 'current_filter' )
			&& 'sanitize_option_pinova_advanced' === current_filter()
		) {
			return $options;
		}

		$sanitized = parent::sanitize_options( $options );

		if (
			! is_array( $sanitized )
			|| ! function_exists( 'current_filter' )
			|| 'sanitize_option_pinova_advanced' !== current_filter()
		) {
			return $sanitized;
		}

		$previous  = get_option( 'pinova_advanced', [] );
		$previous  = is_array( $previous ) ? $previous : [];
		$requested = ! empty( $sanitized['block_native_login'] );
		$sanitized = NativeLoginGate::reconcile_advanced_options( $sanitized, $previous );

		if ( $requested && empty( $sanitized['block_native_login'] ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'pinova_advanced',
				'pinova_native_login_arm_required',
				__( 'مسدودسازی فعال نشد. مسیر خصوصی را ذخیره کنید، سپس با یک مدیر مجاز از همان مسیر وارد شوید و حداکثر تا ۳۰ دقیقه دوباره تلاش کنید.', 'pinova' ),
				'error'
			);
		}

		return $sanitized;
	}

	/** @return array<int, array<string, mixed>> */
	private function integration_fields(): array {
		$theme = wp_get_theme();
		return [
			[
				'id'   => 'identity_policy',
				'type' => 'html',
				'desc' => 'احراز هویت موبایلی پینوا مبنای هماهنگی است. تأیید ایمیل شرط ورود موبایلی نمی‌شود. مجوزهای انجمن و تأیید فروشندگان در اختیار افزونهٔ مربوط باقی می‌مانند. خاموش‌کردن هماهنگی، محدودیت حساب‌های در انتظار تأیید را حذف نمی‌کند.',
			],
			[
				'id'      => 'wpforo_enabled',
				'label'   => 'انجمن wpForo',
				'type'    => 'checkbox',
				'default' => false,
				'desc'    => 'هماهنگی انجمن با ورود موبایلی پینوا فعال باشد.',
			],
			[
				'id'    => 'wpforo_status',
				'label' => 'وضعیت انجمن',
				'type'  => 'html',
				'desc'  => esc_html( IntegrationSettings::description( 'wpforo' ) ),
			],
			[
				'id'      => 'dokan_enabled',
				'label'   => 'فروشندگان Dokan',
				'type'    => 'checkbox',
				'default' => false,
				'desc'    => 'هماهنگی دکان با ورود موبایلی پینوا فعال باشد.',
			],
			[
				'id'    => 'dokan_status',
				'label' => 'وضعیت دکان',
				'type'  => 'html',
				'desc'  => esc_html( IntegrationSettings::description( 'dokan' ) ),
			],
			[
				'id'    => 'woocommerce_status',
				'label' => 'ووکامرس',
				'type'  => 'html',
				'desc'  => function_exists( 'WC' ) ? 'اتصال حساب و تسویه‌حساب به‌صورت خودکار برقرار است.' : 'ووکامرس فعال نیست.',
			],
			[
				'id'    => 'woodmart_status',
				'label' => 'وودمارت',
				'type'  => 'html',
				'desc'  => 'woodmart' === $theme->get_template() ? 'قالب وودمارت فعال است؛ هماهنگی رابط ورود به‌صورت خودکار انجام می‌شود.' : 'قالب وودمارت فعال نیست.',
			],
		];
	}

	public static function sanitize_registration_role( $role ): string {
		$role    = sanitize_key( (string) $role );
		$allowed = UserService::allowed_registration_roles();

		if ( isset( $allowed[ $role ] ) ) {
			return $role;
		}

		return isset( $allowed['subscriber'] ) ? 'subscriber' : (string) array_key_first( $allowed );
	}

	public static function sanitize_native_only_roles( $roles ): array {
		$registered = array_keys( wp_roles()->roles );
		$roles      = is_array( $roles ) ? array_map( 'sanitize_key', $roles ) : [];
		$roles      = array_values( array_intersect( $roles, $registered ) );

		return $roles ?: [ 'administrator' ];
	}

	public static function sanitize_proxy_header( $header ): string {
		$header = strtoupper( sanitize_key( (string) $header ) );
		$header = str_replace( '-', '_', $header );

		return in_array( $header, [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ], true )
			? $header
			: '';
	}

	public static function sanitize_proxy_cidrs( $value ): string {
		$valid = [];
		$rows  = preg_split( '/[\r\n,]+/', (string) $value ) ?: [];

		foreach ( $rows as $row ) {
			[ $network, $prefix ] = array_pad( explode( '/', trim( $row ), 2 ), 2, null );

			if ( false === filter_var( $network, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			$max_bits = false !== filter_var( $network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
			$prefix   = null === $prefix ? $max_bits : (int) $prefix;

			if ( $prefix >= 0 && $prefix <= $max_bits ) {
				$valid[] = $network . '/' . $prefix;
			}
		}

		return implode( "\n", array_values( array_unique( $valid ) ) );
	}

	public static function sanitize_log_level( $level ): string {
		$level = is_string( $level ) ? strtolower( sanitize_key( $level ) ) : '';

		return in_array( $level, [ LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR ], true )
			? $level
			: LogLevel::WARNING;
	}

	public static function sanitize_log_retention( $days ): int {
		return is_int( $days ) || is_string( $days ) ? max( 1, min( 90, absint( $days ) ) ) : 14;
	}

	public static function sanitize_diagnostic_window( $seconds ): int {
		$seconds = is_int( $seconds ) || is_string( $seconds ) ? (string) $seconds : '';

		return in_array( $seconds, [ '900', '3600', '86400' ], true ) ? time() + (int) $seconds : 0;
	}

	/**
	 * Whole-option ownership keeps duration actions distinct from stored epochs.
	 *
	 * @param mixed $options
	 * @return array<string,mixed>
	 */
	private static function sanitize_logging_options( $options ): array {
		global $wpdb;
		$before = is_object( $wpdb ) ? (int) ( $wpdb->num_queries ?? 0 ) : 0;
		try {
			$previous = get_option( 'pinova_logging', [] );
			$read_ok  = ! is_object( $wpdb ) || $before >= (int) ( $wpdb->num_queries ?? 0 ) || '' === (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$previous = [];
			$read_ok  = false;
		}
		$previous = is_array( $previous ) ? $previous : [];
		$options  = is_array( $options ) ? $options : [];
		$result   = [
			'minimum_level'  => self::sanitize_log_level( $options['minimum_level'] ?? $previous['minimum_level'] ?? LogLevel::WARNING ),
			'retention_days' => self::sanitize_log_retention( $options['retention_days'] ?? $previous['retention_days'] ?? 14 ),
		];
		foreach ( [ 'diagnostic_until', 'diagnostic_scope', 'diagnostic_budget_initialized' ] as $key ) {
			if ( array_key_exists( $key, $previous ) ) {
				$result[ $key ] = $previous[ $key ];
			}
		}
		self::$logging_read_failed = self::$logging_read_failed || ! $read_ok;
		if ( self::$logging_read_failed ) {
			// This latch forbids initialization; it does not claim that a budget row exists.
			$result['diagnostic_until']              = 0;
			$result['diagnostic_scope']              = [];
			$result['diagnostic_budget_initialized'] = true;
			return $result;
		}
		$actor = get_current_user_id();
		if ( 0 >= $actor || ! current_user_can( 'manage_options' ) ) {
			return $result;
		}
		if ( null !== self::$logging_normalization && $actor === self::$logging_normalization['actor']
			&& $previous === self::$logging_normalization['previous'] && $options === self::$logging_normalization['value'] ) {
			return self::$logging_normalization['value'];
		}
		$now             = time();
		$previous_until  = $previous['diagnostic_until'] ?? 0;
		$previous_until  = is_int( $previous_until ) && 0 <= $previous_until && $now + DAY_IN_SECONDS >= $previous_until ? $previous_until : 0;
		$action          = $options['diagnostic_until'] ?? 'keep';
		$keep            = 'keep' === $action || ( is_int( $action ) && $action === $previous_until );
		$until           = $keep ? $previous_until : self::sanitize_diagnostic_window( $action );
		$scope_submitted = array_key_exists( 'diagnostic_scope', $options ) || array_key_exists( 'diagnostic_scope_type', $options );
		$scope           = $scope_submitted ? self::requested_diagnostic_scope( $options ) : self::diagnostic_scope( $previous['diagnostic_scope'] ?? [] );
		if ( ! $keep && [] === $scope ) {
			$until = 0;
		}
		$result['diagnostic_until'] = $until;
		$result['diagnostic_scope'] = $scope;
		if ( $now < $until && [] !== $scope && ! array_key_exists( 'diagnostic_budget_initialized', $previous ) ) {
			if ( EventThrottle::initialize_diagnostics() ) {
				$result['diagnostic_budget_initialized'] = true;
			} else {
				$until = 0;
			}
		}
		$result['diagnostic_until'] = $until;

		self::$logging_normalization = [
			'previous' => $previous,
			'value'    => $result,
			'actor'    => $actor,
		];
		return $result;
	}

	/**
	 * @param array<array-key,mixed> $options
	 * @return array{type:string,value:string}|array{}
	 */
	private static function requested_diagnostic_scope( array $options ): array {
		if ( array_key_exists( 'diagnostic_scope_type', $options ) ) {
			if ( array_key_exists( 'diagnostic_scope', $options ) ) {
				return [];
			}
			$type = $options['diagnostic_scope_type'];
			return self::diagnostic_scope(
				[
					'type'  => $type,
					'value' => 'operation' === $type ? ( $options['diagnostic_operation'] ?? '' ) : ( $options['diagnostic_flow_id'] ?? '' ),
				]
			);
		}
		return self::diagnostic_scope( $options['diagnostic_scope'] ?? [] );
	}

	/**
	 * @param mixed $scope
	 * @return array{type:string,value:string}|array{}
	 */
	private static function diagnostic_scope( $scope ): array {
		if ( ! is_array( $scope ) || 2 !== count( $scope ) || ! is_string( $scope['value'] ?? null ) ) {
			return [];
		}
		if ( 'operation' === ( $scope['type'] ?? '' ) && in_array( $scope['value'], DiagnosticContext::OPERATIONS, true ) ) {
			return [
				'type'  => 'operation',
				'value' => $scope['value'],
			];
		}
		if ( 'flow' === ( $scope['type'] ?? '' ) && preg_match( '/\A[a-f0-9]{32}\z/D', $scope['value'] ) ) {
			return [
				'type'  => 'flow',
				'value' => $scope['value'],
			];
		}
		return [];
	}

	public static function sanitize_delete_data_on_uninstall( $enabled ): int {
		$enabled = empty( $enabled ) ? 0 : 1;
		update_option( \Pinova\Install::PURGE_OPTION, $enabled, false );

		return $enabled;
	}

	public function callback_diagnostic_window( array $args ): void {
		$options = get_option( $args['section'], [] );
		$options = is_array( $options ) ? $options : [];
		$until   = isset( $options[ $args['id'] ] ) ? (int) $options[ $args['id'] ] : 0;
		$name    = sprintf( '%s[%s]', $args['section'], $args['id'] );
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<option value="keep"><?php esc_html_e( 'حفظ مهلت فعلی', 'pinova' ); ?></option>
			<option value="0"><?php esc_html_e( 'خاموش', 'pinova' ); ?></option>
			<option value="900"><?php esc_html_e( 'فعال برای ۱۵ دقیقه', 'pinova' ); ?></option>
			<option value="3600"><?php esc_html_e( 'فعال برای یک ساعت', 'pinova' ); ?></option>
			<option value="86400"><?php esc_html_e( 'فعال برای ۲۴ ساعت', 'pinova' ); ?></option>
		</select>
		<?php if ( $until > time() ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: debug expiration date and time. */
					esc_html__( 'مهلت ذخیره‌شده: %s. حفظ مهلت، زمان را تمدید نمی‌کند؛ «خاموش» جزئیات تازه را متوقف می‌کند.', 'pinova' ),
					esc_html( wp_date( 'Y-m-d H:i:s', $until ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $args['desc'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['desc'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function callback_diagnostic_scope( array $args ): void {
		$options = get_option( $args['section'], [] );
		$scope   = self::diagnostic_scope( is_array( $options ) ? ( $options['diagnostic_scope'] ?? [] ) : [] );
		$type    = $scope['type'] ?? '';
		$prefix  = (string) $args['section'];
		?>
		<label for="pinova-diagnostic-scope-type"><?php esc_html_e( 'انتخاب دامنه', 'pinova' ); ?></label>
		<select id="pinova-diagnostic-scope-type" name="<?php echo esc_attr( $prefix ); ?>[diagnostic_scope_type]">
			<option value=""><?php esc_html_e( 'دامنه‌ای انتخاب نشده', 'pinova' ); ?></option>
			<option value="operation" <?php selected( $type, 'operation' ); ?>><?php esc_html_e( 'یک عملیات', 'pinova' ); ?></option>
			<option value="flow" <?php selected( $type, 'flow' ); ?>><?php esc_html_e( 'یک جریان مشخص', 'pinova' ); ?></option>
		</select>
		<p>
			<label for="pinova-diagnostic-operation"><?php esc_html_e( 'عملیات', 'pinova' ); ?></label>
			<select id="pinova-diagnostic-operation" name="<?php echo esc_attr( $prefix ); ?>[diagnostic_operation]">
				<?php
				foreach ( [
					'queued_otp'    => __( 'آماده‌سازی و ارسال کد', 'pinova' ),
					'verify_otp'    => __( 'بررسی کد ورود یا بازیابی', 'pinova' ),
					'verify_mobile' => __( 'بررسی مالکیت موبایل', 'pinova' ),
				] as $operation => $label ) :
					?>
					<option value="<?php echo esc_attr( $operation ); ?>" <?php selected( 'operation' === $type ? $scope['value'] : '', $operation ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="pinova-diagnostic-flow"><?php esc_html_e( 'شناسهٔ جریان ثبت‌شده', 'pinova' ); ?></label>
			<input id="pinova-diagnostic-flow" type="text" dir="ltr" class="regular-text" maxlength="32" name="<?php echo esc_attr( $prefix ); ?>[diagnostic_flow_id]" value="<?php echo esc_attr( 'flow' === $type ? $scope['value'] : '' ); ?>" autocomplete="off">
		</p>
		<p class="description"><?php esc_html_e( 'فقط دامنهٔ انتخاب‌شده بررسی می‌شود. شناسهٔ جریان باید از گزارش معتبر گرفته شود؛ کد تأیید، توکن، شماره یا ایمیل را وارد نکنید. پنجرهٔ قدیمیِ بدون دامنه، جزئیات تازه ثبت نمی‌کند.', 'pinova' ); ?></p>
		<?php
	}

	public static function render_field( array $field, string $section ): string {

		$type  = $field['type'] ?? 'text';
		$id    = $field['id'] ?? $field['key'] ?? '';
		$label = $field['label'] ?? '';

		if ( empty( $id ) ) {
			return '';
		}

		$args = [
			'id'          => $id,
			'type'        => $type,
			'label'       => $label,
			'section'     => $section,
			'desc'        => $field['desc'] ?? $field['description'] ?? '',
			'options'     => $field['options'] ?? [],
			'std'         => $field['default'] ?? '',
			'placeholder' => $field['placeholder'] ?? '',
			'class'       => $field['class'] ?? '',
			'field_class' => $field['field_class'] ?? '',
			'attributes'  => $field['attributes'] ?? [],
		];

		if ( array_key_exists( 'value', $field ) ) {
			$args['value'] = $field['value'];
		}

		$method = 'callback_' . $type;

		$instance = self::instance();

		if ( ! method_exists( $instance, $method ) ) {
			return '';
		}

		ob_start();

		$instance->{$method}( $args );

		return ob_get_clean();
	}
}
