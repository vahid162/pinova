<?php

use Pinova\Helper;
use Pinova\Pinova;

defined( 'ABSPATH' ) || exit;

?>

<section
        pinova-data="pinovaLoginModal"
        class="pinova-container"
        id="pinovaLoginModal"
>

    <div
            pinova-show="modalIsOpen"
            pinova-cloak
            class="h-screen w-screen fixed top-0 left-0 flex items-center justify-center z-[99999] overflow-auto p-4"
    >
        <!-- overlay -->
        <div
                pinova-on:click="modalIsOpen = false"
                class="fixed top-0 left-0 h-screen w-screen bg-black bg-opacity-50 cursor-pointer -z-[1]"
        >
        </div>

        <div class="bg-white text-[#333333] text-sm border border-[#66666640] w-[390px] max-w-full relative rounded-2xl overflow-hidden py-8 px-6 my-auto">


            <!-- close icon -->
            <button
                    pinova-on:click="modalIsOpen = false"
                    class="absolute top-4 left-5"
                    type="button"
            >
                <img class="h-full" src="<?php echo PINOVA_URL ?>assets/images/icons/close.svg">
            </button>

            <!-- loader -->
            <div
                    pinova-cloak
                    pinova-show="pageLoaderIsActive"
                    class="absolute top-0 left-0 h-full w-full z-10 bg-gray-300 bg-opacity-90 flex items-center justify-center p-4"
            >
                <span class="loader"></span>
            </div>

            <!-- static -->
            <div class="relative mb-8">
                <div class="text-center">
                    <a href="<?php echo get_site_url(); ?>">
                        <img pinova-bind:src="logo" class="block max-h-20 mx-auto">
                    </a>
                </div>
                <div class="h-full absolute top-0 right-0 flex items-center">
                    <div
                            pinova-cloak
                            pinova-show="stepName !== 'authenticate'"
                            pinova-on:click="backStep()"
                            class="cursor-pointer h-6"
                    >
                        <img class="h-full" src="<?php echo PINOVA_URL ?>assets/images/icons/arrow-right.svg">
                    </div>
                </div>
            </div>

            <template pinova-if="stepName === 'authenticate'">
                <div id="authenticate">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        ورود | ثبت‌نام
                    </div>
                    <div class="font-normal mb-5">
                        سلام!
                        <br>
                        لطفا شماره موبایل یا نام کاربری خود را وارد کنید.
                    </div>
                    <div class="mb-5">
                        <input
                                pinova-on:keyup.enter="submit()"
                                pinova-model="forms.authenticate.inputs.identifier.value"
                                placeholder="شماره موبایل، نام کاربری یا ایمیل"
                                class="block w-full text-sm border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3"
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
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5"
                            type="button"
                    >
                        تایید
                    </button>
                </div>
            </template>

            <template pinova-if="stepName === 'signIn'">
                <div id="signIn">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        خوش آمدید
                    </div>
                    <div pinova-html="forms.signIn.msg" class="font-normal text-xs md:mb-5 mb-4"></div>
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
                                class="block w-full text-sm text-center tracking-[20px] border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3"
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
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5 mb-4"
                            type="button"
                    >
                        تایید
                    </button>
                    <div>
                        عضویت شما به معنای پذیرش
                        <a href="#" class="text-primary-500 hover:text-primary-600 font-semibold mx-0.5">
                            شرایط
                        </a>
                        و
                        <a href="#" class="text-primary-500 hover:text-primary-600 font-semibold mx-0.5">
                            قوانین
                        </a>
                        است.
                    </div>
                </div>
            </template>

            <template pinova-if="stepName === 'loginByPassword'">
                <div id="loginByPassword">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        رمز عبور را وارد کنید
                    </div>
                    <div class="mb-5">
                        <div
                                pinova-data="{typeIsPassword: true}"
                                class="relative"
                        >
                            <input
                                    pinova-on:keyup.enter="submit()"
                                    pinova-model="forms.loginByPassword.inputs.password.value"
                                    pinova-bind:type="`${typeIsPassword ? 'password' : 'text'}`"
                                    class="block w-full text-sm border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3 pl-10"
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
                                class="flex items-center gap-1.5 text-primary-500 hover:text-primary-600 font-semibold mb-3"
                                type="button"
                        >
                            <span>ورود با رمز یک‌بار‌مصرف</span>
                            <img class="md:h-5 h-4"
                                 src="<?php echo PINOVA_URL ?>assets/images/icons/nav-arrow-left.svg">
                        </button>
                        <button
                                pinova-on:click="authenticate({forget: '1'}, 'forgotPassword')"
                                class="flex items-center gap-1.5 text-primary-500 hover:text-primary-600 font-semibold"
                                type="button"
                        >
                            <span>فراموشی رمز عبور</span>
                            <img class="md:h-5 h-4"
                                 src="<?php echo PINOVA_URL ?>assets/images/icons/nav-arrow-left.svg">
                        </button>
                    </div>
                    <button
                            pinova-on:click="submit()"
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5"
                            type="button"
                    >
                        ورود
                    </button>
                </div>
            </template>

            <template pinova-if="stepName === 'loginByOtp'">
                <div id="loginByOtp">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        کد تایید را وارد کنید
                    </div>
                    <div pinova-html="forms.loginByOtp.msg" class="font-normal text-xs md:mb-5 mb-4"></div>
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
                                class="block w-full text-sm text-center tracking-[20px] border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3"
                                pinova-bind:class="{'!border-error-300' : forms.loginByOtp.inputs.code.errorMsg}"
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
                                class="flex items-center gap-1.5 text-primary-500 hover:text-primary-600 font-semibold mb-3"
                                type="button"
                        >
                            <span>ورود با رمز عبور</span>
                            <img class="md:h-5 h-4"
                                 src="<?php echo PINOVA_URL ?>assets/images/icons/nav-arrow-left.svg">
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
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5"
                            type="button"
                    >
                        تایید
                    </button>
                </div>
            </template>

            <template pinova-if="stepName === 'forgotPassword'">
                <div id="forgotPassword">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        کد تایید را وارد کنید
                    </div>
                    <div class="font-normal md:mb-5 mb-4">
                        <div pinova-html="forms.forgotPassword.msg" class="font-normal text-xs md:mb-5 mb-4"></div>
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
                                class="block w-full text-sm text-center tracking-[20px] border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3"
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
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5"
                            type="button"
                    >
                        تایید
                    </button>
                </div>
            </template>

            <template pinova-if="stepName === 'changePassword'">
                <div id="changePassword">
                    <div class="font-semibold text-base md:mb-5 mb-4">
                        تغییر رمز عبور
                    </div>
                    <div class="mb-5">
                        رمز عبور باید حداقل ۸ حرفی باشد.
                    </div>
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
                                        class="block w-full text-sm border border-gray-300 focus:border-primary-500 rounded-[8px] shadow-[0_1px_2px_0_#1018280D] py-2.5 px-3 pl-10"
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
                            class="block w-full bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-semibold p-2.5 mt-8"
                            type="button"
                    >
                        تایید
                    </button>
                </div>
            </template>

        </div>
    </div>
</section>
