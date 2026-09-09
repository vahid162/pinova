<?php

namespace Pinova\Admin;

use Pinova\Services\SMSService;
use Pinova\Services\UserService;

class Settings extends \Nabik\Utils\V1\Settings {

	protected static ?\Nabik\Utils\V1\Settings $_instance = null;

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

		$roles = array_map(
			static fn( string $name ): string => $name . ' - ' . translate_user_role( $name ),
			UserService::allowed_registration_roles()
		);
		$all_roles = array_map(
			static fn( array $role ): string => $role['name'] . ' - ' . translate_user_role( $role['name'] ),
			get_editable_roles()
		);

		$settings_fields = [
			'pinova_general'    => [
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
					'default' => get_option( 'users_can_register' ) == 1,
				],
				[
					'id'      => 'wordpress_default_role',
					'label'   => 'نقش پیشفرض کاربر تازه',
					'type'    => 'select',
					'options' => $roles,
					'default' => get_option( 'default_role' ),
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
			],
			'pinova_sms'        => [
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
			'pinova_messengers' => [
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
			'pinova_zohal'      => [
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
			'pinova_design'     => [
				[
					'id'    => 'logo',
					'type'  => 'photo',
					'label' => 'لوگو',
				],
			],
			'pinova_advanced'   => [
				[
					'id'    => 'pinova_mobile',
					'type'  => 'html',
					'label' => 'کلید متا پینوا',
					'desc'  => 'پینوا برای افزایش سرعت، تلفن همراه را در متاها ذخیره نمی‌کند، ولی اگر افزونه‌ای نیاز به کلید متا داشت می‌توانید از کلید متا مجازی pinova_mobile استفاده کنید. دقت کنید که این کلید در دیتابیس وجود خارجی ندارد ولی افزونه ها و قالب‌هایی که با تابع get_user_meta و به صورت استاندارد آن را فراخوانی می کنند، به درستی عمل خواهند کرد.',
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
					'id'          => 'native_only_roles',
					'label'       => 'نقش‌های ورود بومی',
					'type'        => 'select2',
					'options'     => $all_roles,
					'default'     => [ 'administrator' ],
					'attributes'  => [ 'multiple' => true ],
					'desc'        => 'این نقش‌ها فقط از wp-login.php و سازوکار رمز/2FA وردپرس وارد می‌شوند. پیش‌فرض: مدیرکل.',
					'sanitize_callback' => [ self::class, 'sanitize_native_only_roles' ],
				],
				[
					'id'      => 'trusted_proxy_header',
					'label'   => 'هدر پروکسی مورد اعتماد',
					'type'    => 'select',
					'options' => [
						''                          => 'غیرفعال (فقط REMOTE_ADDR)',
						'HTTP_X_FORWARDED_FOR'      => 'X-Forwarded-For',
						'HTTP_CF_CONNECTING_IP'     => 'CF-Connecting-IP',
						'HTTP_X_REAL_IP'            => 'X-Real-IP',
					],
					'default' => '',
					'sanitize_callback' => [ self::class, 'sanitize_proxy_header' ],
				],
				[
					'id'      => 'trusted_proxy_cidrs',
					'label'   => 'CIDR پروکسی‌های مورد اعتماد',
					'type'    => 'textarea',
					'desc'    => 'در هر خط یک IPv4/IPv6 یا CIDR وارد کنید. تا وقتی هدر و CIDR هر دو تنظیم نشده‌اند، فقط REMOTE_ADDR استفاده می‌شود.',
					'default' => '',
					'sanitize_callback' => [ self::class, 'sanitize_proxy_cidrs' ],
				],
				// @todo add firewall options here
			],
		];

		return apply_filters( 'pinova/settings_fields', $settings_fields );
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
