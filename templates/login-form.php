<?php

use Pinova\Pinova;
use Pinova\Services\OTPService;

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'nocache_headers' ) ) {
	nocache_headers();
}

$site_name        = get_bloginfo( 'name' );
$home_url         = home_url( '/' );
$privacy_url      = get_privacy_policy_url();
$logo_url         = Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) );

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?php echo esc_html( $site_name ); ?> | ورود</title>
    <link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/style.css?ver=' . PINOVA_VERSION ); ?>" media="all">
    <link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/account.css?ver=' . PINOVA_VERSION ); ?>" media="all">

    <script id="login-form-js-extra">
        var pinova = <?php echo wp_json_encode( [
			'root'        => esc_url_raw( rest_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'code_length' => OTPService::code_length(),
			'logo'        => esc_url_raw( $logo_url ),
		] ); ?>;
    </script>
    <script src="<?php echo esc_url( PINOVA_URL . 'assets/js/notyf.min.js?ver=' . PINOVA_VERSION ); ?>"></script>
    <link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/notyf.min.css?ver=' . PINOVA_VERSION ); ?>" media="all">
    <script src="<?php echo esc_url( PINOVA_URL . 'assets/js/pages/login-form.js?ver=' . PINOVA_VERSION ); ?>" type="module"></script>
</head>
<body class="pinova-container pinova-account-page">
<main class="pinova-auth-shell">
    <div
        pinova-data="pinovaLoginForm()"
        class="pinova-auth-layout"
        pinova-bind:aria-busy="pageLoaderIsActive"
    >
        <section class="pinova-auth-card" aria-label="فرم ورود و ثبت‌نام">
            <h1 class="pinova-visually-hidden">ورود به حساب کاربری <?php echo esc_html( $site_name ); ?></h1>
            <div
                pinova-cloak
                pinova-show="pageLoaderIsActive"
                class="pinova-auth-loader"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            >
                <span class="loader" aria-hidden="true"></span>
                <span>در حال پردازش درخواست…</span>
            </div>

            <header class="pinova-auth-header">
                <button
                    pinova-cloak
                    pinova-show="stepName !== 'authenticate'"
                    pinova-on:click="backStep()"
                    class="pinova-auth-icon-button"
                    type="button"
                    aria-label="بازگشت به مرحله قبل"
                >
                    <img src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/arrow-right.svg' ); ?>" alt="" aria-hidden="true">
                </button>
                <a
                    pinova-cloak
                    pinova-show="stepName === 'authenticate'"
                    class="pinova-auth-icon-button"
                    href="<?php echo esc_url( $home_url ); ?>"
                    aria-label="بازگشت به فروشگاه"
                >
                    <img src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/arrow-right.svg' ); ?>" alt="" aria-hidden="true">
                </a>
                <a class="pinova-auth-logo" href="<?php echo esc_url( $home_url ); ?>">
                    <img pinova-bind:src="logo" alt="لوگوی <?php echo esc_attr( $site_name ); ?>">
                </a>
            </header>

            <div
                pinova-cloak
                pinova-show="status.message"
                pinova-text="status.message"
                pinova-bind:class="`pinova-auth-status is-${status.tone}`"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            ></div>

            <template pinova-if="stepName === 'authenticate'">
                <form id="authenticate" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>ورود یا ثبت‌نام</h2>
                    <p class="pinova-auth-description">شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید.</p>
                    <div class="pinova-auth-field">
                        <label for="pinova-identifier">شماره موبایل، نام کاربری یا ایمیل</label>
                        <input id="pinova-identifier" pinova-model="forms.authenticate.inputs.identifier.value" pinova-bind:aria-invalid="Boolean(forms.authenticate.inputs.identifier.errorMsg)" aria-describedby="pinova-identifier-error" name="username" type="text" autocomplete="username" autocapitalize="none">
                        <p id="pinova-identifier-error" class="pinova-auth-error" pinova-show="forms.authenticate.inputs.identifier.errorMsg" pinova-text="forms.authenticate.inputs.identifier.errorMsg"></p>
                    </div>
                    <button class="pinova-auth-primary" type="submit">ادامه</button>
                </form>
            </template>

            <template pinova-if="stepName === 'signIn'">
                <form id="signIn" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>تأیید شماره موبایل</h2>
                    <p class="pinova-auth-description" pinova-text="forms.signIn.msg"></p>
                    <div class="pinova-auth-field">
                        <label for="pinova-signin-code">کد تأیید</label>
                        <input id="pinova-signin-code" pinova-on:input="forms.signIn.inputs.code.value = pinovaCleanNumericInput(forms.signIn.inputs.code.value); if (forms.signIn.inputs.code.value.length === codeLength) submit();" pinova-model="forms.signIn.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.signIn.inputs.code.errorMsg)" aria-describedby="pinova-signin-code-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr">
                        <p id="pinova-signin-code-error" class="pinova-auth-error" pinova-show="forms.signIn.inputs.code.errorMsg" pinova-text="forms.signIn.inputs.code.errorMsg"></p>
                    </div>
                    <button class="pinova-auth-secondary" pinova-on:click="authenticate({force_otp: '1'})" pinova-bind:disabled="!time.btnResendIsActive" type="button">
                        <span pinova-show="!time.btnResendIsActive" pinova-text="`${time.textTime} تا ارسال مجدد`"></span>
                        <span pinova-show="time.btnResendIsActive">ارسال مجدد کد</span>
                    </button>
                    <button class="pinova-auth-primary" type="submit">تأیید و ورود</button>
                </form>
            </template>

            <template pinova-if="stepName === 'loginByPassword'">
                <form id="loginByPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>ورود با رمز عبور</h2>
                    <p class="pinova-auth-description">رمز عبور حساب خود را وارد کنید.</p>
                    <div class="pinova-auth-field">
                        <label for="pinova-password">رمز عبور</label>
                        <div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
                            <input id="pinova-password" pinova-model="forms.loginByPassword.inputs.password.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.loginByPassword.inputs.password.errorMsg)" aria-describedby="pinova-password-error" autocomplete="current-password">
                            <button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور' : 'پنهان کردن رمز عبور'" type="button">
                                <img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
                                <img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
                            </button>
                        </div>
                        <p id="pinova-password-error" class="pinova-auth-error" pinova-show="forms.loginByPassword.inputs.password.errorMsg" pinova-text="forms.loginByPassword.inputs.password.errorMsg"></p>
                    </div>
                    <div class="pinova-auth-actions">
                        <button pinova-on:click="authenticate({force_otp: '1'}, 'loginByOtp')" type="button">ورود با رمز یک‌بارمصرف</button>
                        <button pinova-on:click="authenticate({forget: '1'}, 'forgotPassword')" type="button">رمز عبور را فراموش کرده‌ام</button>
                    </div>
                    <button class="pinova-auth-primary" type="submit">ورود</button>
                </form>
            </template>

            <template pinova-if="stepName === 'loginByOtp'">
                <form id="loginByOtp" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>ورود با رمز یک‌بارمصرف</h2>
                    <p class="pinova-auth-description" pinova-text="forms.loginByOtp.msg"></p>
                    <div class="pinova-auth-field">
                        <label for="pinova-login-otp">کد تأیید</label>
                        <input id="pinova-login-otp" pinova-on:input="forms.loginByOtp.inputs.code.value = pinovaCleanNumericInput(forms.loginByOtp.inputs.code.value); if (forms.loginByOtp.inputs.code.value.length === codeLength) submit();" pinova-model="forms.loginByOtp.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.loginByOtp.inputs.code.errorMsg)" aria-describedby="pinova-login-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr">
                        <p id="pinova-login-otp-error" class="pinova-auth-error" pinova-show="forms.loginByOtp.inputs.code.errorMsg" pinova-text="forms.loginByOtp.inputs.code.errorMsg"></p>
                    </div>
                    <div class="pinova-auth-actions"><button pinova-on:click="changeStep('loginByPassword')" type="button">ورود با رمز عبور</button></div>
                    <button class="pinova-auth-secondary" pinova-on:click="authenticate({force_otp: '1'})" pinova-bind:disabled="!time.btnResendIsActive" type="button">
                        <span pinova-show="!time.btnResendIsActive" pinova-text="`${time.textTime} تا ارسال مجدد`"></span>
                        <span pinova-show="time.btnResendIsActive">ارسال مجدد کد</span>
                    </button>
                    <button class="pinova-auth-primary" type="submit">تأیید و ورود</button>
                </form>
            </template>

            <template pinova-if="stepName === 'forgotPassword'">
                <form id="forgotPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>بازیابی رمز عبور</h2>
                    <p class="pinova-auth-description" pinova-text="forms.forgotPassword.msg"></p>
                    <div class="pinova-auth-field">
                        <label for="pinova-forgot-otp">کد تأیید</label>
                        <input id="pinova-forgot-otp" pinova-on:input="forms.forgotPassword.inputs.code.value = pinovaCleanNumericInput(forms.forgotPassword.inputs.code.value); if (forms.forgotPassword.inputs.code.value.length === codeLength) submit();" pinova-model="forms.forgotPassword.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.forgotPassword.inputs.code.errorMsg)" aria-describedby="pinova-forgot-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr">
                        <p id="pinova-forgot-otp-error" class="pinova-auth-error" pinova-show="forms.forgotPassword.inputs.code.errorMsg" pinova-text="forms.forgotPassword.inputs.code.errorMsg"></p>
                    </div>
                    <button class="pinova-auth-secondary" pinova-on:click="authenticate({forget: '1', force_otp: '1'})" pinova-bind:disabled="!time.btnResendIsActive" type="button">
                        <span pinova-show="!time.btnResendIsActive" pinova-text="`${time.textTime} تا ارسال مجدد`"></span>
                        <span pinova-show="time.btnResendIsActive">ارسال مجدد کد</span>
                    </button>
                    <button class="pinova-auth-primary" type="submit">تأیید کد</button>
                </form>
            </template>

            <template pinova-if="stepName === 'changePassword'">
                <form id="changePassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
                    <h2>تغییر رمز عبور</h2>
                    <p class="pinova-auth-description">رمز جدید باید حداقل ۸ نویسه داشته باشد.</p>
                    <div class="pinova-auth-field">
                        <label for="pinova-password-new">رمز عبور جدید</label>
                        <div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
                            <input id="pinova-password-new" pinova-model="forms.changePassword.inputs.password_1.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_1.errorMsg)" aria-describedby="pinova-password-new-error pinova-password-guidance" autocomplete="new-password">
                            <button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور جدید' : 'پنهان کردن رمز عبور جدید'" type="button">
                                <img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
                                <img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
                            </button>
                        </div>
                        <p id="pinova-password-new-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_1.errorMsg" pinova-text="forms.changePassword.inputs.password_1.errorMsg"></p>
                    </div>
                    <div id="pinova-password-guidance" class="pinova-password-guidance">
                        <strong pinova-show="forms.changePassword.inputs.password_1.value.length" pinova-text="passwordStrengthLabel()"></strong>
                        <ul>
                            <li pinova-bind:class="{'is-valid': checkStringLength(forms.changePassword.inputs.password_1.value)}">حداقل ۸ نویسه</li>
                            <li pinova-bind:class="{'is-valid': checkStringIncludeNumber(forms.changePassword.inputs.password_1.value)}">دارای عدد</li>
                            <li pinova-bind:class="{'is-valid': checkStringIncludeSymbols(forms.changePassword.inputs.password_1.value)}">دارای علامت</li>
                            <li pinova-bind:class="{'is-valid': checkStringIncludeUppercaseAndLowercase(forms.changePassword.inputs.password_1.value)}">دارای حروف کوچک و بزرگ انگلیسی</li>
                        </ul>
                    </div>
                    <div class="pinova-auth-field">
                        <label for="pinova-password-confirm">تکرار رمز عبور</label>
                        <div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
                            <input id="pinova-password-confirm" pinova-model="forms.changePassword.inputs.password_2.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_2.errorMsg)" aria-describedby="pinova-password-confirm-error" autocomplete="new-password">
                            <button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش تکرار رمز عبور' : 'پنهان کردن تکرار رمز عبور'" type="button">
                                <img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
                                <img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
                            </button>
                        </div>
                        <p id="pinova-password-confirm-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_2.errorMsg" pinova-text="forms.changePassword.inputs.password_2.errorMsg"></p>
                    </div>
                    <button class="pinova-auth-primary" type="submit">ثبت رمز عبور جدید</button>
                </form>
            </template>

            <?php if ( $privacy_url ) : ?>
                <footer class="pinova-auth-footer">
                    <a href="<?php echo esc_url( $privacy_url ); ?>">حریم خصوصی</a>
                </footer>
            <?php endif; ?>
        </section>

        <aside class="pinova-auth-intro" aria-label="معرفی <?php echo esc_attr( $site_name ); ?>">
            <p class="pinova-auth-eyebrow">حساب کاربری</p>
            <h2 class="pinova-auth-intro-title">ورود امن و سریع</h2>
            <p>برای ادامه خرید، پیگیری سفارش‌ها و مدیریت حساب خود وارد شوید.</p>
            <ul class="pinova-auth-benefits">
                <li>ورود با رمز عبور یا رمز یک‌بارمصرف</li>
                <li>حفاظت از اطلاعات حساب کاربری</li>
                <li>دسترسی سریع به سفارش‌ها</li>
            </ul>
            <a class="pinova-auth-store-link" href="<?php echo esc_url( $home_url ); ?>">بازگشت به فروشگاه</a>
        </aside>
    </div>
</main>

<noscript>
    <div class="pinova-auth-noscript" role="alert">
        برای ورود به حساب کاربری، JavaScript مرورگر را فعال کنید.
        <a href="<?php echo esc_url( $home_url ); ?>">بازگشت به فروشگاه</a>
    </div>
</noscript>

<script src="<?php echo esc_url( PINOVA_URL . 'assets/js/global.js?ver=' . PINOVA_VERSION ); ?>"></script>
</body>
</html>
