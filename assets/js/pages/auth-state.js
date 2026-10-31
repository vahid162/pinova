export function createAuthState() {
    return {
        logo: pinova.logo,
        stepName: 'authenticate',
        previousStepName: null,
        authRoot: null,
        themeWebOtpCheck: null,
        themeWebOtpCleanup: null,
        webOtpSequence: 0,
        webOtpAbort: null,
        pendingWebOtp: null,
        webOtpPageActive: true,
        pageLoaderIsActive: false,
        codeLength: pinova.code_length,
        allowedSteps: [
            'authenticate',
            'signIn',
            'loginByPassword',
            'loginByOtp',
            'forgotPassword',
            'changePassword',
        ],
        status: {
            message: '',
            reference: '',
            tone: 'info',
        },
        otpSubmittedCodes: {
            signIn: '',
            loginByOtp: '',
            forgotPassword: '',
        },

        forms: {
            authenticate: {
                inputs: {
                    identifier: {
                        value: '',
                        rules: {
                            required: true,
                        },
                        errorMsg: '',
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
            },
            signIn: {
                inputs: {
                    jwt: {
                        value: '',
                        errorMsg: '',
                    },
                    code: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number'],
                        },
                        errorMsg: '',
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
                msg: null,
            },
            loginByPassword: {
                inputs: {
                    identifier: {
                        value: '',
                        errorMsg: '',
                    },
                    password: {
                        value: '',
                        rules: {
                            required: true,
                        },
                        errorMsg: '',
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
            },
            loginByOtp: {
                inputs: {
                    jwt: {
                        value: '',
                        errorMsg: '',
                    },
                    code: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number'],
                        },
                        errorMsg: '',
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
                msg: null,
            },
            forgotPassword: {
                inputs: {
                    jwt: {
                        value: '',
                        errorMsg: '',
                    },
                    code: {
                        value: '',
                        errorMsg: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number'],
                        },
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
                msg: null,
            },
            changePassword: {
                inputs: {
                    jwt: {
                        value: '',
                        errorMsg: '',
                    },
                    reset_key: {
                        value: '',
                        errorMsg: '',
                    },
                    password_1: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: 8,
                        },
                        errorMsg: '',
                    },
                    password_2: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: 8,
                        },
                        errorMsg: '',
                    },
                    back_url: {
                        value: pinovaGetQueryParam('back_url'),
                        errorMsg: '',
                    },
                },
            },
        },

        time: {
            duration: 120 * 1000,
            timerInterval: null,
            textTime: '03:00',
            textTimeMinutes: '',
            textTimeSeconds: '',
            btnResendIsActive: false,
        },

        initWebOtpLifecycle() {
            this.authRoot = this.$root || this.$el || null;
            window.addEventListener('pagehide', () => {
                this.webOtpPageActive = false;
                this.cancelRequest();
                this.stopWebOtp();
                this.stopTimer();
            });
            window.addEventListener('pageshow', () => {
                this.webOtpPageActive = true;
            });
        },

        stopWebOtp() {
            ++this.webOtpSequence;
            const abort = this.webOtpAbort;
            this.webOtpAbort = null;
            this.pendingWebOtp = null;
            if (abort) {
                abort();
            }
        },

        webOtpIsCurrent(context) {
            return context.sequence === this.webOtpSequence && this.webOtpPageActive
                && this.stepName === context.formName
                && this.forms[context.formName].inputs.jwt.value === context.jwt
                && this.forms.authenticate.inputs.identifier.value === context.identifier
                && this.modalIsOpen !== false && this.authRoot?.isConnected !== false
                && (!this.themeWebOtpCheck || this.themeWebOtpCheck());
        },

        startWebOtp(formName) {
            this.stopWebOtp();
            if (!Object.prototype.hasOwnProperty.call(this.otpSubmittedCodes, formName)
                || !this.forms[formName].inputs.jwt.value || !this.webOtpPageActive
                || this.modalIsOpen === false || this.authRoot?.isConnected === false
                || (this.themeWebOtpCheck && !this.themeWebOtpCheck())
                || !('OTPCredential' in window) || typeof AbortController !== 'function'
                || typeof navigator.credentials?.get !== 'function') {
                return;
            }
            const controller = new AbortController();
            const context = {
                sequence: this.webOtpSequence,
                formName,
                jwt: this.forms[formName].inputs.jwt.value,
                identifier: this.forms.authenticate.inputs.identifier.value,
            };
            this.webOtpAbort = () => controller.abort();
            try {
                Promise.resolve(navigator.credentials.get({ otp: { transport: ['sms'] }, signal: controller.signal }))
                    .then(otp => {
                        if (!this.webOtpIsCurrent(context) || !otp || typeof otp.code !== 'string') {
                            return;
                        }
                        const code = pinovaCleanNumericInput(otp.code);
                        if (this.pageLoaderIsActive) {
                            this.pendingWebOtp = {...context, code};
                        } else {
                            this.forms[formName].inputs.code.value = code;
                            this.handleOtpInput(formName);
                        }
                    }).catch(() => {});
            } catch {
                this.stopWebOtp();
            }
        },

        resumeWebOtp() {
            const context = this.pendingWebOtp;
            this.pendingWebOtp = null;
            if (context && this.webOtpIsCurrent(context)) {
                this.forms[context.formName].inputs.code.value = context.code;
                this.handleOtpInput(context.formName);
            }
        },

        setStatus(message, tone = 'error', reference = '') {
            this.status.message = message || 'درخواست انجام نشد. کمی بعد دوباره تلاش کنید.';
            this.status.tone = tone;
            this.status.reference = reference || '';
        },

        clearStatus() {
            this.status.message = '';
            this.status.tone = 'info';
            this.status.reference = '';
        },

        clearFormErrors() {
            for (const formName in this.forms) {
                for (const inputName in this.forms[formName].inputs) {
                    this.forms[formName].inputs[inputName].errorMsg = '';
                }
            }
        },

        handleOtpInput(formName) {
            if (!Object.prototype.hasOwnProperty.call(this.otpSubmittedCodes, formName)) {
                return;
            }

            const field = this.forms[formName].inputs.code;
            const normalizedCode = pinovaCleanNumericInput(field.value).slice(0, this.codeLength);
            field.value = normalizedCode;

            if (normalizedCode.length !== Number(this.codeLength)) {
                this.resetOtpAutoSubmit(formName);
                return;
            }

            if (this.otpSubmittedCodes[formName] === normalizedCode || this.pageLoaderIsActive) {
                return;
            }

            this.otpSubmittedCodes[formName] = normalizedCode;
            this.submit();
        },

        resetOtpAutoSubmit(formName) {
            if (Object.prototype.hasOwnProperty.call(this.otpSubmittedCodes, formName)) {
                this.otpSubmittedCodes[formName] = '';
            }
        },

        identifierEditLabel() {
            const identifier = String(this.forms.authenticate.inputs.identifier.value || '').trim();

            if (identifier.includes('@')) {
                return 'ویرایش ایمیل';
            }

            if (/^[+()\s0-9۰-۹٠-٩-]+$/.test(identifier)) {
                return 'ویرایش شماره';
            }

            return 'ویرایش شناسه';
        },

        editIdentifier() {
            this.changeStep('authenticate');
        },

        handleRequestFailure(result) {
            if (result?.http?.status === 429) {
                this.stopWebOtp();
            }
            this.setStatus(result?.userMessage || result?.message || 'درخواست انجام نشد. کمی بعد دوباره تلاش کنید.', 'error', result?.userMessage ? result.http?.correlationId : '');
        },

        handleRequestException(error) {
            this.setStatus(error?.pinovaUserMessage || error?.pinovaMessage || 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.', 'error', error?.pinovaCorrelationId);
        },

        clearStepSecrets(stepName) {
            this.stopWebOtp();
            switch (stepName) {
                case 'signIn':
                case 'loginByOtp':
                case 'forgotPassword':
                    this.forms[stepName].inputs.jwt.value = '';
                    this.forms[stepName].inputs.code.value = '';
                    this.resetOtpAutoSubmit(stepName);
                    this.stopTimer();
                    break;
                case 'changePassword':
                    this.forms.changePassword.inputs.jwt.value = '';
                    this.forms.changePassword.inputs.reset_key.value = '';
                    this.forms.changePassword.inputs.password_1.value = '';
                    this.forms.changePassword.inputs.password_2.value = '';
                    break;
                case 'loginByPassword':
                    this.forms.loginByPassword.inputs.password.value = '';
                    break;
            }
        },

        resetSensitiveState() {
            this.stopWebOtp();
            this.stopTimer();
            this.forms.signIn.inputs.jwt.value = '';
            this.forms.signIn.inputs.code.value = '';
            this.resetOtpAutoSubmit('signIn');
            this.forms.loginByOtp.inputs.jwt.value = '';
            this.forms.loginByOtp.inputs.code.value = '';
            this.resetOtpAutoSubmit('loginByOtp');
            this.forms.forgotPassword.inputs.jwt.value = '';
            this.forms.forgotPassword.inputs.code.value = '';
            this.resetOtpAutoSubmit('forgotPassword');
            this.forms.changePassword.inputs.jwt.value = '';
            this.forms.changePassword.inputs.reset_key.value = '';
            this.forms.changePassword.inputs.password_1.value = '';
            this.forms.changePassword.inputs.password_2.value = '';
            this.forms.loginByPassword.inputs.password.value = '';
        },

        stopTimer() {
            if (this.time.timerInterval !== null) {
                clearInterval(this.time.timerInterval);
            }
            this.time.timerInterval = null;
            this.time.btnResendIsActive = true;
        },

        startTime() {
            this.stopTimer();
            this.time.btnResendIsActive = false;
            const endTime = Date.now() + this.time.duration;

            this.updateTimer(this.time.duration);
            this.time.timerInterval = setInterval(() => {
                const remaining = endTime - Date.now();

                if (remaining <= 0) {
                    clearInterval(this.time.timerInterval);
                    this.time.timerInterval = null;
                    this.time.btnResendIsActive = true;
                    return;
                }

                this.updateTimer(remaining);
            }, 1000);
        },

        updateTimer(remaining) {
            const totalSeconds = Math.ceil(remaining / 1000);
            let minutes = Math.floor(totalSeconds / 60);
            let seconds = Math.floor(totalSeconds % 60);

            minutes = minutes < 10 ? `0${minutes}` : minutes;
            seconds = seconds < 10 ? `0${seconds}` : seconds;
            this.time.textTime = `${minutes}:${seconds}`;
            this.time.textTimeMinutes = minutes.toString();
            this.time.textTimeSeconds = seconds.toString();
        },

        checkStringLength(value) {
            return value.length >= 8;
        },

        checkStringIncludeNumber(value) {
            return /\d/.test(value);
        },

        checkStringIncludeSymbols(value) {
            return /[^\w\s]/.test(value);
        },

        checkStringIncludeUppercaseAndLowercase(value) {
            return /[a-z]/.test(value) && /[A-Z]/.test(value);
        },

        passwordStrengthAssessment() {
            let passwordStrength = 0;
            const password = this.forms.changePassword.inputs.password_1.value;

            if (this.checkStringLength(password)) {
                passwordStrength += 1;
            }
            if (this.checkStringIncludeNumber(password)) {
                passwordStrength += 1;
            }
            if (this.checkStringIncludeSymbols(password)) {
                passwordStrength += 1;
            }
            if (this.checkStringIncludeUppercaseAndLowercase(password)) {
                passwordStrength += 1;
            }

            return passwordStrength;
        },

        passwordStrengthLabel() {
            const strength = this.passwordStrengthAssessment();
            if (strength === 4) {
                return 'قدرت رمز: عالی';
            }
            if (strength === 3) {
                return 'قدرت رمز: متوسط';
            }
            return 'قدرت رمز: ضعیف';
        },
    };
}
