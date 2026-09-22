<?php

use Pinova\Pinova;
use Pinova\Services\OTPService;

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'nocache_headers' ) ) {
	nocache_headers();
}

$site_name             = get_bloginfo( 'name' );
$home_url              = home_url( '/' );
$privacy_url           = get_privacy_policy_url();
$logo_url              = Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) );
$account_asset_version = PINOVA_VERSION . '.5';

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php echo esc_html( $site_name ); ?> | ورود</title>
	<link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/style.css?ver=' . $account_asset_version ); ?>" media="all">
	<link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/account.css?ver=' . $account_asset_version ); ?>" media="all">

	<script id="login-form-js-extra">
		var pinova =
		<?php
		echo wp_json_encode(
			[
				'root'        => esc_url_raw( rest_url() ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'code_length' => OTPService::code_length(),
				'logo'        => esc_url_raw( $logo_url ),
			]
		);
		?>
		;
	</script>
	<script src="<?php echo esc_url( PINOVA_URL . 'assets/js/notyf.min.js?ver=' . PINOVA_VERSION ); ?>"></script>
	<link rel="stylesheet" href="<?php echo esc_url( PINOVA_URL . 'assets/css/notyf.min.css?ver=' . PINOVA_VERSION ); ?>" media="all">
	<script src="<?php echo esc_url( PINOVA_URL . 'assets/js/pages/login-form.js?ver=' . $account_asset_version ); ?>" type="module"></script>
</head>
<body class="pinova-container pinova-account-page">
<main class="pinova-auth-shell">
	<div
		pinova-data="pinovaLoginForm()"
		class="pinova-auth-layout"
		pinova-bind:aria-busy="pageLoaderIsActive"
	>
		<section class="pinova-auth-card" aria-label="فرم ورود و ثبت‌نام">
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

			<div class="pinova-auth-content" pinova-bind:inert="pageLoaderIsActive">
			<h1 class="pinova-visually-hidden">ورود به حساب کاربری <?php echo esc_html( $site_name ); ?></h1>

			<header class="pinova-auth-header">
				<button
					pinova-cloak
					pinova-show="stepName === 'loginByPassword' || stepName === 'changePassword'"
					pinova-on:click="backStep()"
					class="pinova-auth-icon-button"
					type="button"
					aria-label="بازگشت به مرحله قبل"
				>
					<img src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/arrow-right.svg' ); ?>" alt="" aria-hidden="true">
				</button>
				<a class="pinova-auth-logo" href="<?php echo esc_url( $home_url ); ?>">
					<img pinova-bind:src="logo" alt="لوگوی <?php echo esc_attr( $site_name ); ?>">
				</a>
			</header>

			<div class="pinova-auth-main">

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
					<h2 data-pinova-step-heading tabindex="-1">ورود / ثبت‌نام</h2>
					<div class="pinova-auth-field pinova-auth-field--lead">
						<label for="pinova-identifier">شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید</label>
						<input id="pinova-identifier" pinova-model="forms.authenticate.inputs.identifier.value" pinova-bind:aria-invalid="Boolean(forms.authenticate.inputs.identifier.errorMsg)" aria-describedby="pinova-identifier-error" name="username" type="text" autocomplete="username" autocapitalize="none" dir="auto" required>
						<p id="pinova-identifier-error" class="pinova-auth-error" pinova-show="forms.authenticate.inputs.identifier.errorMsg" pinova-text="forms.authenticate.inputs.identifier.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<button class="pinova-auth-primary" type="submit">ادامه</button>
				</form>
			</template>

			<template pinova-if="stepName === 'signIn'">
				<form id="signIn" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
					<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
					<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.signIn.msg"></p>
					<div class="pinova-auth-edit">
						<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
					</div>
					<div class="pinova-auth-field pinova-auth-code-field">
						<label for="pinova-signin-code">کد تأیید</label>
						<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.signIn.inputs.code.errorMsg)}">
							<div class="pinova-auth-code-slots" aria-hidden="true">
								<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
									<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.signIn.inputs.code.value.length >= digitIndex, 'is-active': forms.signIn.inputs.code.value.length === digitIndex - 1 || (forms.signIn.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.signIn.inputs.code.value.charAt(digitIndex - 1)"></span>
								</template>
							</div>
							<input id="pinova-signin-code" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('signIn')" pinova-model="forms.signIn.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.signIn.inputs.code.errorMsg)" aria-describedby="pinova-signin-code-hint pinova-signin-code-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
						</div>
						<p id="pinova-signin-code-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
						<p id="pinova-signin-code-error" class="pinova-auth-error" pinova-show="forms.signIn.inputs.code.errorMsg" pinova-text="forms.signIn.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<button class="pinova-auth-primary" type="submit">تأیید و ورود</button>
					<div class="pinova-auth-resend">
						<p class="pinova-auth-resend-status" pinova-show="!time.btnResendIsActive">
							ارسال دوبارهٔ کد تا <bdi pinova-text="time.textTime"></bdi> دیگر
						</p>
						<button class="pinova-auth-resend-button" pinova-cloak pinova-show="time.btnResendIsActive" pinova-on:click="authenticate({force_otp: '1'})" type="button">ارسال دوبارهٔ کد</button>
					</div>
				</form>
			</template>

			<template pinova-if="stepName === 'loginByPassword'">
				<form id="loginByPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
					<h2 data-pinova-step-heading tabindex="-1">ورود با رمز عبور</h2>
					<div class="pinova-auth-field pinova-auth-field--lead">
						<label for="pinova-password">رمز عبور خود را وارد کنید</label>
						<div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
							<input id="pinova-password" pinova-model="forms.loginByPassword.inputs.password.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.loginByPassword.inputs.password.errorMsg)" aria-describedby="pinova-password-error" autocomplete="current-password" dir="ltr" required>
							<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور' : 'پنهان کردن رمز عبور'" type="button">
								<img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
								<img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
							</button>
						</div>
						<p id="pinova-password-error" class="pinova-auth-error" pinova-show="forms.loginByPassword.inputs.password.errorMsg" pinova-text="forms.loginByPassword.inputs.password.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<div class="pinova-auth-actions pinova-auth-actions--split">
						<button pinova-on:click="authenticate({force_otp: '1'}, 'loginByOtp')" type="button">ورود با رمز یک‌بارمصرف</button>
						<button pinova-on:click="authenticate({forget: '1'}, 'forgotPassword')" type="button">رمز عبور را فراموش کرده‌ام</button>
					</div>
					<button class="pinova-auth-primary" type="submit">ورود</button>
				</form>
			</template>

			<template pinova-if="stepName === 'loginByOtp'">
				<form id="loginByOtp" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
					<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
					<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.loginByOtp.msg"></p>
					<div class="pinova-auth-edit">
						<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
					</div>
					<div class="pinova-auth-field pinova-auth-code-field">
						<label for="pinova-login-otp">کد تأیید</label>
						<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.loginByOtp.inputs.code.errorMsg)}">
							<div class="pinova-auth-code-slots" aria-hidden="true">
								<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
									<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.loginByOtp.inputs.code.value.length >= digitIndex, 'is-active': forms.loginByOtp.inputs.code.value.length === digitIndex - 1 || (forms.loginByOtp.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.loginByOtp.inputs.code.value.charAt(digitIndex - 1)"></span>
								</template>
							</div>
							<input id="pinova-login-otp" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('loginByOtp')" pinova-model="forms.loginByOtp.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.loginByOtp.inputs.code.errorMsg)" aria-describedby="pinova-login-otp-hint pinova-login-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
						</div>
						<p id="pinova-login-otp-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
						<p id="pinova-login-otp-error" class="pinova-auth-error" pinova-show="forms.loginByOtp.inputs.code.errorMsg" pinova-text="forms.loginByOtp.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<button class="pinova-auth-primary" type="submit">تأیید و ورود</button>
					<div class="pinova-auth-resend">
						<p class="pinova-auth-resend-status" pinova-show="!time.btnResendIsActive">
							ارسال دوبارهٔ کد تا <bdi pinova-text="time.textTime"></bdi> دیگر
						</p>
						<button class="pinova-auth-resend-button" pinova-cloak pinova-show="time.btnResendIsActive" pinova-on:click="authenticate({force_otp: '1'})" type="button">ارسال دوبارهٔ کد</button>
					</div>
					<div class="pinova-auth-actions"><button pinova-on:click="changeStep('loginByPassword')" type="button">ورود با رمز عبور</button></div>
				</form>
			</template>

			<template pinova-if="stepName === 'forgotPassword'">
				<form id="forgotPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
					<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
					<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.forgotPassword.msg"></p>
					<div class="pinova-auth-edit">
						<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
					</div>
					<div class="pinova-auth-field pinova-auth-code-field">
						<label for="pinova-forgot-otp">کد تأیید</label>
						<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.forgotPassword.inputs.code.errorMsg)}">
							<div class="pinova-auth-code-slots" aria-hidden="true">
								<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
									<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.forgotPassword.inputs.code.value.length >= digitIndex, 'is-active': forms.forgotPassword.inputs.code.value.length === digitIndex - 1 || (forms.forgotPassword.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.forgotPassword.inputs.code.value.charAt(digitIndex - 1)"></span>
								</template>
							</div>
							<input id="pinova-forgot-otp" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('forgotPassword')" pinova-model="forms.forgotPassword.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.forgotPassword.inputs.code.errorMsg)" aria-describedby="pinova-forgot-otp-hint pinova-forgot-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
						</div>
						<p id="pinova-forgot-otp-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
						<p id="pinova-forgot-otp-error" class="pinova-auth-error" pinova-show="forms.forgotPassword.inputs.code.errorMsg" pinova-text="forms.forgotPassword.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<button class="pinova-auth-primary" type="submit">تأیید کد</button>
					<div class="pinova-auth-resend">
						<p class="pinova-auth-resend-status" pinova-show="!time.btnResendIsActive">
							ارسال دوبارهٔ کد تا <bdi pinova-text="time.textTime"></bdi> دیگر
						</p>
						<button class="pinova-auth-resend-button" pinova-cloak pinova-show="time.btnResendIsActive" pinova-on:click="authenticate({forget: '1', force_otp: '1'}, 'forgotPassword')" type="button">ارسال دوبارهٔ کد</button>
					</div>
					<div class="pinova-auth-actions"><button pinova-on:click="changeStep('loginByPassword')" type="button">ورود با رمز عبور</button></div>
				</form>
			</template>

			<template pinova-if="stepName === 'changePassword'">
				<form id="changePassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
					<h2 data-pinova-step-heading tabindex="-1">تغییر رمز عبور</h2>
					<p class="pinova-auth-description">رمز جدید باید حداقل ۸ نویسه داشته باشد.</p>
					<div class="pinova-auth-field">
						<label for="pinova-password-new">رمز عبور جدید</label>
						<div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
							<input id="pinova-password-new" pinova-model="forms.changePassword.inputs.password_1.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_1.errorMsg)" aria-describedby="pinova-password-new-error pinova-password-guidance" autocomplete="new-password" dir="ltr" required>
							<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور جدید' : 'پنهان کردن رمز عبور جدید'" type="button">
								<img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
								<img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
							</button>
						</div>
						<p id="pinova-password-new-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_1.errorMsg" pinova-text="forms.changePassword.inputs.password_1.errorMsg" role="alert" aria-atomic="true"></p>
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
							<input id="pinova-password-confirm" pinova-model="forms.changePassword.inputs.password_2.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_2.errorMsg)" aria-describedby="pinova-password-confirm-error" autocomplete="new-password" dir="ltr" required>
							<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش تکرار رمز عبور' : 'پنهان کردن تکرار رمز عبور'" type="button">
								<img pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
								<img pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
							</button>
						</div>
						<p id="pinova-password-confirm-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_2.errorMsg" pinova-text="forms.changePassword.inputs.password_2.errorMsg" role="alert" aria-atomic="true"></p>
					</div>
					<button class="pinova-auth-primary" type="submit">ثبت رمز عبور جدید</button>
				</form>
			</template>

			<nav class="pinova-auth-navigation" aria-label="پیوندهای حساب کاربری">
				<a class="pinova-auth-store-link" href="<?php echo esc_url( $home_url ); ?>">بازگشت به فروشگاه</a>
			</nav>

			<?php if ( $privacy_url ) : ?>
				<footer class="pinova-auth-footer">
					<a href="<?php echo esc_url( $privacy_url ); ?>">حریم خصوصی</a>
				</footer>
			<?php endif; ?>
			</div>
			</div>
		</section>
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
