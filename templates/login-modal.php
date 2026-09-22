<?php

defined( 'ABSPATH' ) || exit;

$site_name = get_bloginfo( 'name' );
$home_url  = home_url( '/' );

?>

<section
	pinova-data="pinovaLoginModal"
	class="pinova-container pinova-auth-modal pinova-account-page"
	id="pinovaLoginModal"
>
	<div
		pinova-cloak
		pinova-show="modalIsOpen"
		pinova-on:click.self="closeModal()"
		pinova-on:keydown="handleDialogKeydown($event)"
		class="pinova-auth-modal__viewport"
	>
		<div
			class="pinova-auth-modal__dialog"
			role="dialog"
			aria-modal="true"
			aria-labelledby="pinova-checkout-dialog-title"
			tabindex="-1"
		>
			<section
				class="pinova-auth-layout"
				pinova-bind:aria-busy="pageLoaderIsActive"
			>
				<div class="pinova-auth-card">
					<button
						pinova-on:click="closeModal()"
						class="pinova-auth-close-button"
						type="button"
						aria-label="بستن پنجرهٔ ورود"
					>
						<img src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/close.svg' ); ?>" alt="" aria-hidden="true">
					</button>

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

					<div class="pinova-auth-content">
						<h2 id="pinova-checkout-dialog-title" class="pinova-visually-hidden">ورود یا ثبت‌نام در <?php echo esc_html( $site_name ); ?></h2>

						<header class="pinova-auth-header">
							<button
								pinova-cloak
								pinova-show="stepName === 'loginByPassword' || stepName === 'changePassword'"
								pinova-on:click="backStep()"
								pinova-bind:inert="pageLoaderIsActive"
								class="pinova-auth-icon-button"
								type="button"
								aria-label="بازگشت به مرحله قبل"
							>
								<img src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/arrow-right.svg' ); ?>" alt="" aria-hidden="true">
							</button>

							<a class="pinova-auth-logo" href="<?php echo esc_url( $home_url ); ?>" pinova-bind:inert="pageLoaderIsActive" aria-label="بازگشت به <?php echo esc_attr( $site_name ); ?>">
								<img pinova-bind:src="logo" alt="لوگوی <?php echo esc_attr( $site_name ); ?>">
							</a>
						</header>

						<div class="pinova-auth-main" pinova-bind:inert="pageLoaderIsActive">
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
								<form id="pinova-modal-authenticate" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">ورود / ثبت‌نام</h2>
									<div class="pinova-auth-field pinova-auth-field--lead">
										<label for="pinova-modal-identifier">شماره موبایل، نام کاربری یا ایمیل خود را وارد کنید</label>
										<input id="pinova-modal-identifier" pinova-model="forms.authenticate.inputs.identifier.value" pinova-bind:aria-invalid="Boolean(forms.authenticate.inputs.identifier.errorMsg)" aria-describedby="pinova-modal-identifier-error" name="username" type="text" autocomplete="username" autocapitalize="none" dir="auto" required>
										<p id="pinova-modal-identifier-error" class="pinova-auth-error" pinova-show="forms.authenticate.inputs.identifier.errorMsg" pinova-text="forms.authenticate.inputs.identifier.errorMsg" role="alert" aria-atomic="true"></p>
									</div>
									<button class="pinova-auth-primary" type="submit">ادامه</button>
								</form>
							</template>

							<template pinova-if="stepName === 'signIn'">
								<form id="pinova-modal-signIn" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
									<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.signIn.msg"></p>
									<div class="pinova-auth-edit">
										<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
									</div>
									<div class="pinova-auth-field pinova-auth-code-field">
										<label for="pinova-modal-signin-code">کد تأیید</label>
										<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.signIn.inputs.code.errorMsg)}">
											<div class="pinova-auth-code-slots" aria-hidden="true">
												<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
													<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.signIn.inputs.code.value.length >= digitIndex, 'is-active': forms.signIn.inputs.code.value.length === digitIndex - 1 || (forms.signIn.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.signIn.inputs.code.value.charAt(digitIndex - 1)"></span>
												</template>
											</div>
											<input id="pinova-modal-signin-code" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('signIn')" pinova-model="forms.signIn.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.signIn.inputs.code.errorMsg)" aria-describedby="pinova-modal-signin-code-hint pinova-modal-signin-code-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
										</div>
										<p id="pinova-modal-signin-code-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
										<p id="pinova-modal-signin-code-error" class="pinova-auth-error" pinova-show="forms.signIn.inputs.code.errorMsg" pinova-text="forms.signIn.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
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
								<form id="pinova-modal-loginByPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">ورود با رمز عبور</h2>
									<div class="pinova-auth-field pinova-auth-field--lead">
										<label for="pinova-modal-password">رمز عبور خود را وارد کنید</label>
										<div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
											<input id="pinova-modal-password" pinova-model="forms.loginByPassword.inputs.password.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.loginByPassword.inputs.password.errorMsg)" aria-describedby="pinova-modal-password-error" autocomplete="current-password" dir="ltr" required>
											<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور' : 'پنهان کردن رمز عبور'" type="button">
												<img pinova-cloak pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
												<img pinova-cloak pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
											</button>
										</div>
										<p id="pinova-modal-password-error" class="pinova-auth-error" pinova-show="forms.loginByPassword.inputs.password.errorMsg" pinova-text="forms.loginByPassword.inputs.password.errorMsg" role="alert" aria-atomic="true"></p>
									</div>
									<div class="pinova-auth-actions pinova-auth-actions--split">
										<button pinova-on:click="authenticate({force_otp: '1'}, 'loginByOtp')" type="button">ورود با رمز یک‌بارمصرف</button>
										<button pinova-on:click="authenticate({forget: '1'}, 'forgotPassword')" type="button">رمز عبور را فراموش کرده‌ام</button>
									</div>
									<button class="pinova-auth-primary" type="submit">ورود</button>
								</form>
							</template>

							<template pinova-if="stepName === 'loginByOtp'">
								<form id="pinova-modal-loginByOtp" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
									<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.loginByOtp.msg"></p>
									<div class="pinova-auth-edit">
										<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
									</div>
									<div class="pinova-auth-field pinova-auth-code-field">
										<label for="pinova-modal-login-otp">کد تأیید</label>
										<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.loginByOtp.inputs.code.errorMsg)}">
											<div class="pinova-auth-code-slots" aria-hidden="true">
												<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
													<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.loginByOtp.inputs.code.value.length >= digitIndex, 'is-active': forms.loginByOtp.inputs.code.value.length === digitIndex - 1 || (forms.loginByOtp.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.loginByOtp.inputs.code.value.charAt(digitIndex - 1)"></span>
												</template>
											</div>
											<input id="pinova-modal-login-otp" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('loginByOtp')" pinova-model="forms.loginByOtp.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.loginByOtp.inputs.code.errorMsg)" aria-describedby="pinova-modal-login-otp-hint pinova-modal-login-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
										</div>
										<p id="pinova-modal-login-otp-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
										<p id="pinova-modal-login-otp-error" class="pinova-auth-error" pinova-show="forms.loginByOtp.inputs.code.errorMsg" pinova-text="forms.loginByOtp.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
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
								<form id="pinova-modal-forgotPassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">کد تأیید</h2>
									<p class="pinova-auth-description pinova-auth-otp-copy" pinova-text="forms.forgotPassword.msg"></p>
									<div class="pinova-auth-edit">
										<button pinova-on:click="editIdentifier()" pinova-text="identifierEditLabel()" type="button"></button>
									</div>
									<div class="pinova-auth-field pinova-auth-code-field">
										<label for="pinova-modal-forgot-otp">کد تأیید</label>
										<div class="pinova-auth-code-input" pinova-bind:style="`--pinova-code-length: ${codeLength}`" pinova-bind:class="{'has-error': Boolean(forms.forgotPassword.inputs.code.errorMsg)}">
											<div class="pinova-auth-code-slots" aria-hidden="true">
												<template pinova-for="digitIndex in codeLength" pinova-bind:key="digitIndex">
													<span class="pinova-auth-code-slot" pinova-bind:class="{'is-filled': forms.forgotPassword.inputs.code.value.length >= digitIndex, 'is-active': forms.forgotPassword.inputs.code.value.length === digitIndex - 1 || (forms.forgotPassword.inputs.code.value.length === codeLength && digitIndex === codeLength)}" pinova-text="forms.forgotPassword.inputs.code.value.charAt(digitIndex - 1)"></span>
												</template>
											</div>
											<input id="pinova-modal-forgot-otp" pinova-on:focus="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:click="$el.setSelectionRange($el.value.length, $el.value.length)" pinova-on:input="handleOtpInput('forgotPassword')" pinova-model="forms.forgotPassword.inputs.code.value" pinova-bind:maxlength="codeLength" pinova-bind:aria-invalid="Boolean(forms.forgotPassword.inputs.code.errorMsg)" aria-describedby="pinova-modal-forgot-otp-hint pinova-modal-forgot-otp-error" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>
										</div>
										<p id="pinova-modal-forgot-otp-hint" class="pinova-auth-hint" pinova-text="`کد ${codeLength} رقمی را وارد کنید.`"></p>
										<p id="pinova-modal-forgot-otp-error" class="pinova-auth-error" pinova-show="forms.forgotPassword.inputs.code.errorMsg" pinova-text="forms.forgotPassword.inputs.code.errorMsg" role="alert" aria-atomic="true"></p>
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
								<form id="pinova-modal-changePassword" class="pinova-auth-form" pinova-on:submit.prevent="submit()" novalidate>
									<h2 data-pinova-step-heading tabindex="-1">تغییر رمز عبور</h2>
									<p class="pinova-auth-description">رمز جدید باید حداقل ۸ نویسه داشته باشد.</p>
									<div class="pinova-auth-field">
										<label for="pinova-modal-password-new">رمز عبور جدید</label>
										<div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
											<input id="pinova-modal-password-new" pinova-model="forms.changePassword.inputs.password_1.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_1.errorMsg)" aria-describedby="pinova-modal-password-new-error pinova-modal-password-guidance" autocomplete="new-password" dir="ltr" required>
											<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش رمز عبور جدید' : 'پنهان کردن رمز عبور جدید'" type="button">
												<img pinova-cloak pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
												<img pinova-cloak pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
											</button>
										</div>
										<p id="pinova-modal-password-new-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_1.errorMsg" pinova-text="forms.changePassword.inputs.password_1.errorMsg" role="alert" aria-atomic="true"></p>
									</div>
									<div id="pinova-modal-password-guidance" class="pinova-password-guidance">
										<strong pinova-show="forms.changePassword.inputs.password_1.value.length" pinova-text="passwordStrengthLabel()"></strong>
										<ul>
											<li pinova-bind:class="{'is-valid': checkStringLength(forms.changePassword.inputs.password_1.value)}">حداقل ۸ نویسه</li>
											<li pinova-bind:class="{'is-valid': checkStringIncludeNumber(forms.changePassword.inputs.password_1.value)}">دارای عدد</li>
											<li pinova-bind:class="{'is-valid': checkStringIncludeSymbols(forms.changePassword.inputs.password_1.value)}">دارای علامت</li>
											<li pinova-bind:class="{'is-valid': checkStringIncludeUppercaseAndLowercase(forms.changePassword.inputs.password_1.value)}">دارای حروف کوچک و بزرگ انگلیسی</li>
										</ul>
									</div>
									<div class="pinova-auth-field">
										<label for="pinova-modal-password-confirm">تکرار رمز عبور</label>
										<div pinova-data="{typeIsPassword: true}" class="pinova-password-field">
											<input id="pinova-modal-password-confirm" pinova-model="forms.changePassword.inputs.password_2.value" pinova-bind:type="typeIsPassword ? 'password' : 'text'" pinova-bind:aria-invalid="Boolean(forms.changePassword.inputs.password_2.errorMsg)" aria-describedby="pinova-modal-password-confirm-error" autocomplete="new-password" dir="ltr" required>
											<button class="pinova-password-toggle" pinova-on:click="typeIsPassword = !typeIsPassword" pinova-bind:aria-label="typeIsPassword ? 'نمایش تکرار رمز عبور' : 'پنهان کردن تکرار رمز عبور'" type="button">
												<img pinova-cloak pinova-show="typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye.svg' ); ?>" alt="" aria-hidden="true">
												<img pinova-cloak pinova-show="!typeIsPassword" src="<?php echo esc_url( PINOVA_URL . 'assets/images/icons/eye-off.svg' ); ?>" alt="" aria-hidden="true">
											</button>
										</div>
										<p id="pinova-modal-password-confirm-error" class="pinova-auth-error" pinova-show="forms.changePassword.inputs.password_2.errorMsg" pinova-text="forms.changePassword.inputs.password_2.errorMsg" role="alert" aria-atomic="true"></p>
									</div>
									<button class="pinova-auth-primary" type="submit">ثبت رمز عبور جدید</button>
								</form>
							</template>
						</div>
					</div>
				</div>
			</section>
		</div>
	</div>
</section>
