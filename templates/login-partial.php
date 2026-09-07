<?php

use Pinova\Pinova;
use Pinova\Services\OTPService;

defined( 'ABSPATH' ) || exit;

/**@var string $form_wrapper_classes */
$form_wrapper_classes = $form_wrapper_classes ?? '';

/**@var string $submit_button_classes */
$submit_button_classes = $submit_button_classes ?? '';

/**@var string $custom_style */
$custom_styles = $custom_styles ?? '';
/*Custom nav arrow based on elementor accent color*/
$nav_arrow_left = '<svg class="md:h-5 h-4 nav-arrow" width="20" height="21" viewBox="0 0 20 21" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 15.7693L7.5 10.7693L12.5 5.76929" stroke="var(--e-global-color-accent)" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>'

?>
<link rel='stylesheet' href='<?php echo PINOVA_URL ?>assets/css/style.css?ver=<?php echo PINOVA_VERSION; ?>' media='all'/>

<script id="login-form-js-extra">
    var pinova = <?php echo json_encode( [
		'root'        => esc_url_raw( rest_url() ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'code_length' => OTPService::code_length(),
		'logo'        => Pinova::get_option( 'design.logo', admin_url( 'images/wordpress-logo.svg' ) ),
	] ); ?>
</script>

<!-- Notyf -->
<script src="<?php echo PINOVA_URL ?>assets/js/notyf.min.js?ver=<?php echo PINOVA_VERSION; ?>"></script>
<link rel='stylesheet' href='<?php echo PINOVA_URL ?>assets/css/notyf.min.css?ver=<?php echo PINOVA_VERSION; ?>' media='all'/>

<!-- Page script -->
<script src="<?php echo PINOVA_URL ?>assets/js/pages/login-form.js?ver=<?php echo PINOVA_VERSION; ?>" type="module"></script>


<!-- Custom partial styles (override and new styles) -->
<?php echo $custom_styles ?>


<div class="pinova-container">
	<section>
		<div class="container">
			<div class="flex items-start justify-center py-4">
				<div
					pinova-data="pinovaLoginForm()"
					class="w-[390px] sm:relative  overflow-hidden max-w-full"
				>

					<!-- loader -->
					<div
						pinova-cloak
						pinova-show="pageLoaderIsActive"
						class="fixed top-0 left-0 h-full w-full z-10 flex items-center justify-center p-4"
					>
						<span class="loader"></span>
					</div>

					<!-- static -->
					<div class="relative mb-8">
						<div class="h-full absolute top-0 right-0 flex items-center py-4">
							<div
                                pinova-cloak
								pinova-show="stepName !== 'authenticate'"
								pinova-on:click="backStep()"
								class="cursor-pointer h-6"
							>
								<img class="h-full" src="<?php echo PINOVA_URL ?>assets/images/icons/arrow-right.svg">
							</div>
                            <button
                                pinova-cloak
                                pinova-show="(stepName === 'authenticate')"
                                pinova-on:click="pinovaGoBack()"
                                class="inline-block h-6"
                            >
                                <img class="h-full" src="<?php echo PINOVA_URL ?>assets/images/icons/arrow-right.svg">
                            </button>
						</div>
					</div>
					<template pinova-if="stepName === 'authenticate'">
						<div id="authenticate" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<label class="mb-5">
								شماره موبایل یا نام کاربری
								&nbsp;
								<span class="required" aria-hidden="true">*</span>
								<span class="screen-reader-text">الزامی</span>
							</label>
							<div class="mb-5">
								<input
									type="text"
									pinova-on:keyup.enter="submit()"
									pinova-model="forms.authenticate.inputs.identifier.value"
									placeholder="شماره موبایل، نام کاربری یا ایمیل"
									class="block w-full py-2.5 px-3"
									pinova-bind:class="{'!border-error-300' : forms.authenticate.inputs.identifier.errorMsg}"
                                    name="username"
								>
								<div
									pinova-show="forms.authenticate.inputs.identifier.errorMsg"
									pinova-text="forms.authenticate.inputs.identifier.errorMsg"
									class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
								>
								</div>
							</div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								تایید
							</button>
						</div>
					</template>

					<template pinova-if="stepName === 'signIn'">
						<div id="signIn" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<label pinova-html="forms.signIn.msg" class="md:mb-5 mb-4"></label>
							<div class="mb-5">
								<input
									pinova-on:keyup.enter="submit()"
									pinova-on:input="
                                      forms.signIn.inputs.code.value = pinovaCleanNumericInput(forms.signIn.inputs.code.value);
                                      if (forms.signIn.inputs.code.value.length === codeLength) submit();
                                    "
									pinova-model="forms.signIn.inputs.code.value"
									type="text"
									inputmode="numeric"
									pinova-bind:maxlength="codeLength"
									autocomplete="one-time-code"
									dir="ltr"
									class="block text-center w-full py-2.5 px-3"
									placeholder="کد تایید را وارد کنید"
									pinova-bind:class="{'!border-error-300' : forms.signIn.inputs.code.errorMsg}"
								>
								<div
									pinova-show="forms.signIn.inputs.code.errorMsg"
									pinova-text="forms.signIn.inputs.code.errorMsg"
									class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
								>
								</div>
							</div>
                            <div class="mb-5">
                                <button
                                        pinova-on:click="authenticate({force_otp : '1'})"
                                        pinova-bind:disabled="!time.btnResendIsActive"
                                        class="flex w-full items-center justify-center border border-gray-300 rounded-[8px] text-gray-700 font-semibold hover:bg-gray-100 disabled:hover:bg-transparent py-2.5 px-4"
                                >
                                    <img
                                            pinova-show="time.btnResendIsActive"
                                            class="w-5 ml-2"
                                            src="<?php echo PINOVA_URL ?>assets/images/icons/refresh.svg"
                                    >
                                    <span class="flex text-center" pinova-show="!time.btnResendIsActive">
                                    <template pinova-for="(char) in time.textTimeSeconds.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                    :
                                    <template pinova-for="char in time.textTimeMinutes.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                </span>
                                    <span class="mr-1">
                                    <span pinova-show="!time.btnResendIsActive">تا</span>
                                     ارسال مجدد
                                </span>
                                </button>
                            </div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 mb-4 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								تایید
							</button>
							<div>
								عضویت شما به معنای پذیرش
								<a href="#" class="mx-0.5">
									شرایط
								</a>
								و
								<a href="#" class="mx-0.5">
									قوانین
								</a>
								است.
							</div>
						</div>
					</template>

					<template pinova-if="stepName === 'loginByPassword'">
						<div id="loginByPassword" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<label class="md:mb-5 mb-4">
								رمز عبور را وارد کنید
							</label>
							<div class="mb-5">
								<div
									pinova-data="{typeIsPassword: true}"
									class="relative"
								>
									<input
										pinova-on:keyup.enter="submit()"
										pinova-model="forms.loginByPassword.inputs.password.value"
										pinova-bind:type="`${typeIsPassword ? 'password' : 'text'}`"
										class="block w-full py-2.5 px-3 pl-10"
										pinova-bind:class="{'!border-error-300' : forms.loginByPassword.inputs.password.errorMsg}"
									>
									<div
										pinova-show="typeIsPassword"
										pinova-on:click="typeIsPassword = false"
										class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
									>
										<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye.svg">
									</div>
									<div
										pinova-show="!typeIsPassword"
										pinova-on:click="typeIsPassword = true"
										class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
									>
										<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye-off.svg">
									</div>
								</div>
								<div
									pinova-show="forms.loginByPassword.inputs.password.errorMsg"
									pinova-text="forms.loginByPassword.inputs.password.errorMsg"
									class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
								>
								</div>
							</div>
							<div class="mb-5">
								<button
									pinova-on:click="authenticate({force_otp: '1'}, 'loginByOtp')"
									class="flex items-center gap-1.5 font-semibold mb-3 text-primary-500"
                                    type="button"
								>
									<span>ورود با رمز یک‌بار‌مصرف</span>
									<?php echo $nav_arrow_left; ?>
								</button>
								<button
									pinova-on:click="authenticate({forget: '1'}, 'forgotPassword')"
									class="flex items-center gap-1.5 font-semibold text-primary-500"
                                    type="button"
								>
									<span>فراموشی رمز عبور</span>
									<?php echo $nav_arrow_left; ?>
								</button>
							</div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								ورود
							</button>
						</div>
					</template>

					<template pinova-if="stepName === 'loginByOtp'">
						<div id="loginByOtp" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<label pinova-html="forms.loginByOtp.msg" class="md:mb-5 mb-4"></label>
							<div class="mb-5">
								<input
									pinova-on:keyup.enter="submit()"
									pinova-on:input="
                                      forms.loginByOtp.inputs.code.value = pinovaCleanNumericInput(forms.loginByOtp.inputs.code.value);
                                      if (forms.loginByOtp.inputs.code.value.length === codeLength) submit();
                                    "
									pinova-model="forms.loginByOtp.inputs.code.value"
									type="text"
									inputmode="numeric"
									pinova-bind:maxlength="codeLength"
									autocomplete="one-time-code"
									dir="ltr"
									class="block w-full text-center py-2.5 px-3"
									pinova-bind:class="{'!border-error-300' : forms.loginByOtp.inputs.code.errorMsg}"
									placeholder="کد تایید را وارد کنید"
								>
								<div
									pinova-show="forms.loginByOtp.inputs.code.errorMsg"
									pinova-text="forms.loginByOtp.inputs.code.errorMsg"
									class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
								>
								</div>
							</div>
							<div class="mb-5">
								<button
									pinova-on:click="changeStep('loginByPassword'); forms.loginByPassword.inputs.password.value = ''"
									class="flex items-center gap-1.5 font-semibold mb-3 text-primary-500"
                                    type="button"
								>
									<span>ورود با رمز عبور</span>
									<?php echo $nav_arrow_left; ?>
								</button>
							</div>
                            <div class="mb-5">
                                <button
                                        pinova-on:click="authenticate({force_otp : '1'})"
                                        pinova-bind:disabled="!time.btnResendIsActive"
                                        class="flex w-full items-center justify-center border border-gray-300 rounded-[8px] text-gray-700 font-semibold hover:bg-gray-100 disabled:hover:bg-transparent py-2.5 px-4"
                                >
                                    <img
                                            pinova-show="time.btnResendIsActive"
                                            class="w-5 ml-2"
                                            src="<?php echo PINOVA_URL ?>assets/images/icons/refresh.svg"
                                    >
                                    <span class="flex text-center" pinova-show="!time.btnResendIsActive">
                                    <template pinova-for="(char) in time.textTimeSeconds.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                    :
                                    <template pinova-for="char in time.textTimeMinutes.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                </span>
                                    <span class="mr-1">
                                    <span pinova-show="!time.btnResendIsActive">تا</span>
                                     ارسال مجدد
                                </span>
                                </button>
                            </div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								تایید
							</button>
						</div>
					</template>

					<template pinova-if="stepName === 'forgotPassword'">
						<div id="forgotPassword" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<div class="md:mb-5 mb-4">
								<label pinova-html="forms.forgotPassword.msg" class="md:mb-5 mb-4"></label>
							</div>
							<div class="mb-5">
								<input
									pinova-on:keyup.enter="submit()"
									pinova-on:input="
                                      forms.forgotPassword.inputs.code.value = pinovaCleanNumericInput(forms.forgotPassword.inputs.code.value);
                                      if (forms.forgotPassword.inputs.code.value.length === codeLength) submit();
                                    "
									pinova-model="forms.forgotPassword.inputs.code.value"
									type="text"
									inputmode="numeric"
									pinova-bind:maxlength="codeLength"
									autocomplete="one-time-code"
									dir="ltr"
									class="block w-full text-center py-2.5 px-3"
									placeholder="کد تایید را وارد کنید"
									pinova-bind:class="{'!border-error-300' : forms.forgotPassword.inputs.code.errorMsg}"
								>
								<div
									pinova-show="forms.forgotPassword.inputs.code.errorMsg"
									pinova-text="forms.forgotPassword.inputs.code.errorMsg"
									class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
								>
								</div>
							</div>
                            <div class="mb-5">
                                <button
                                        pinova-on:click="authenticate({forget : '1', force_otp : '1'})"
                                        pinova-bind:disabled="!time.btnResendIsActive"
                                        class="flex w-full items-center justify-center border border-gray-300 rounded-[8px] text-gray-700 font-semibold hover:bg-gray-100 disabled:hover:bg-transparent py-2.5 px-4"
                                >
                                    <img
                                            pinova-show="time.btnResendIsActive"
                                            class="w-5 ml-2"
                                            src="<?php echo PINOVA_URL ?>assets/images/icons/refresh.svg"
                                    >
                                    <span class="flex text-center" pinova-show="!time.btnResendIsActive">
                                    <template pinova-for="(char) in time.textTimeSeconds.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                    :
                                    <template pinova-for="char in time.textTimeMinutes.split('').map(Number).reverse()">
                                        <span pinova-text="char" class="w-3"></span>
                                    </template>
                                </span>
                                    <span class="mr-1">
                                    <span pinova-show="!time.btnResendIsActive">تا</span>
                                     ارسال مجدد
                                </span>
                                </button>
                            </div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								تایید
							</button>
						</div>
					</template>

					<template pinova-if="stepName === 'changePassword'">
						<div id="changePassword" class="<?php echo esc_attr( $form_wrapper_classes ); ?>">
							<div class="mb-5">
								<div class="mb-5">
									<label class="block mb-2">
										رمز عبور جدید
									</label>
									<div
										pinova-data="{typeIsPassword: true}"
										class="relative"
									>
										<input
											pinova-model="forms.changePassword.inputs.password_1.value"
											pinova-bind:type="`${typeIsPassword ? 'password' : 'text'}`"
											class="block w-full text-sm border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3 pl-10"
											pinova-bind:class="{'!border-error-300' : forms.changePassword.inputs.password_1.errorMsg}"
										>
										<div
											pinova-show="typeIsPassword"
											pinova-on:click="typeIsPassword = false"
											class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
										>
											<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye.svg">
										</div>
										<div
											pinova-show="!typeIsPassword"
											pinova-on:click="typeIsPassword = true"
											class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
										>
											<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye-off.svg">
										</div>
									</div>
									<div
										pinova-show="forms.changePassword.inputs.password_1.errorMsg"
										pinova-text="forms.changePassword.inputs.password_1.errorMsg"
										class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
									>
									</div>
								</div>
								<div class="text-gray-300 mb-5">
									<div class="mb-2">
										<div pinova-show="passwordStrengthAssessment() <= 2 && forms.changePassword.inputs.password_1.value.length > 0"
										     class="text-error-400">ضعیف
										</div>
										<div pinova-show="passwordStrengthAssessment() === 3" class="text-yellow-600">معمولی
										</div>
										<div pinova-show="passwordStrengthAssessment() === 4" class="text-success-400">عالی</div>
									</div>

									<div
										class="mb-4"
										pinova-bind:class="{
                                        'text-error-400': passwordStrengthAssessment() <= 2,
                                        'text-yellow-600': passwordStrengthAssessment() === 3,
                                        'text-success-400': passwordStrengthAssessment() === 4,
                                    }"
									>
										<div class="flex items-center gap-2">
											<div
												class="h-1 w-1/3 rounded bg-current"
												pinova-bind:class="{'!bg-gray-300': passwordStrengthAssessment() === 0 &&  forms.changePassword.inputs.password_1.value < 1}"
											>
											</div>
											<div
												class="h-1 w-1/3 rounded bg-current"
												pinova-bind:class="{'!bg-gray-300': passwordStrengthAssessment() < 3}"
											>
											</div>
											<div
												class="h-1 w-1/3 rounded bg-current"
												pinova-bind:class="{'!bg-gray-300': passwordStrengthAssessment() < 4}"
											>
											</div>
										</div>
									</div>
									<div class="text-gray-400">
										<ul class="list-disc text-gray-400 pr-5">
											<li pinova-bind:class="{'text-primary-500' : checkStringIncludeNumber(forms.changePassword.inputs.password_1.value)}">
												شامل عدد
											</li>
											<li pinova-bind:class="{'text-primary-500' : checkStringLength(forms.changePassword.inputs.password_1.value)}">
												حداقل ۸ حرف
											</li>
											<li pinova-bind:class="{'text-primary-500' : checkStringIncludeSymbols(forms.changePassword.inputs.password_1.value)}">
												شامل علامت (!@#$%&*^)
											</li>
											<li pinova-bind:class="{'text-primary-500' : checkStringIncludeUppercaseAndLowercase(forms.changePassword.inputs.password_1.value)}">
												شامل یک حرف بزرگ و کوچک
											</li>
										</ul>
									</div>
								</div>
								<div class="mb-5">
									<label class="block mb-2">
										تکرار رمز عبور
									</label>
									<div
										pinova-data="{typeIsPassword: true}"
										class="relative"
									>
										<input
											pinova-on:keyup.enter="submit()"
											pinova-model="forms.changePassword.inputs.password_2.value"
											pinova-bind:type="`${typeIsPassword ? 'password' : 'text'}`"
											class="block w-full py-2.5 px-3 pl-10"
											pinova-bind:class="{'!border-error-300' : forms.changePassword.inputs.password_2.errorMsg}"
										>
										<div
											pinova-show="typeIsPassword"
											pinova-on:click="typeIsPassword = false"
											class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
										>
											<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye.svg">
										</div>
										<div
											pinova-show="!typeIsPassword"
											pinova-on:click="typeIsPassword = true"
											class="absolute left-0 top-0 h-full flex items-center cursor-pointer pl-3"
										>
											<img class="w-5" src="<?php echo PINOVA_URL ?>assets/images/icons/eye-off.svg">
										</div>
									</div>
									<div
										pinova-show="forms.changePassword.inputs.password_2.errorMsg"
										pinova-text="forms.changePassword.inputs.password_2.errorMsg"
										class="text-error-500 md:text-sm text-xs pt-1.5 empty:!pt-0"
									>
									</div>
								</div>
							</div>
							<button
								pinova-on:click="submit()"
								class="block w-full p-2.5 mt-8 <?php echo esc_attr( $submit_button_classes ) ?>"
                                type="button"
							>
								تایید
							</button>
						</div>
					</template>

				</div>
			</div>
		</div>
	</section>
</div>
<script src="<?php echo PINOVA_URL ?>assets/js/global.js?ver=<?php echo PINOVA_VERSION; ?>"></script>
