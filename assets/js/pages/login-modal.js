import pinovaAlpine from './../alpine.min.js';

pinovaAlpine.prefix('pinova-');
pinovaAlpine.data('pinovaLoginModal', () => ({
    modalIsOpen: false,
    logo: pinova.logo,
    stepName: 'authenticate',
    previousStepName: null,
    pageLoaderIsActive: false,
    codeLength: pinova.code_length,
    returnFocusElement: null,
    focusSequence: 0,
    requestSequence: 0,
    activeRequestId: 0,
    activeRequestAbort: null,
    previousBodyOverflow: '',
    inertedElements: [],
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

    init() {
        window.addEventListener('beforeunload', () => {
            this.stopTimer();
            this.unlockPageInteraction();
        });

        if ('OTPCredential' in window) {
            window.addEventListener('DOMContentLoaded', () => {
                navigator.credentials.get({
                    otp: { transport: ['sms'] },
                }).then(otp => {
                    if (!this.modalIsOpen || !otp.code) {
                        return;
                    }

                    switch (this.stepName) {
                        case 'loginByOtp':
                            this.forms.loginByOtp.inputs.code.value = pinovaCleanNumericInput(otp.code);
                            this.handleOtpInput('loginByOtp');
                            break;
                        case 'signIn':
                            this.forms.signIn.inputs.code.value = pinovaCleanNumericInput(otp.code);
                            this.handleOtpInput('signIn');
                            break;
                        case 'forgotPassword':
                            this.forms.forgotPassword.inputs.code.value = pinovaCleanNumericInput(otp.code);
                            this.handleOtpInput('forgotPassword');
                            break;
                    }
                }).catch(error => {
                    console.log(error);
                });
            });
        }
    },

    submit() {
        if (this.pageLoaderIsActive) {
            return;
        }

        this.clearStatus();
        this.clearFormErrors();

        let hasError = false;
        for (const key in this.forms[this.stepName].inputs) {
            const validated = pinovaValidateField(this.forms[this.stepName].inputs[key]);
            if (validated.errorMsg) {
                hasError = true;
            }
            this.forms[this.stepName].inputs[key] = validated;
        }

        if (hasError) {
            this.focusFirstInvalidField();
            return;
        }

        switch (this.stepName) {
            case 'authenticate':
                this.authenticate();
                break;
            case 'signIn':
            case 'loginByOtp':
                this.loginByOtp();
                break;
            case 'loginByPassword':
                this.loginByPassword();
                break;
            case 'forgotPassword':
                this.forgotPassword();
                break;
            case 'changePassword':
                this.changePassword();
                break;
        }
    },

    async authenticate(otherData = {}, nextStepName = null) {
        const request = this.beginRequest();
        if (!request) {
            return;
        }

        try {
            const data = { ...otherData };
            for (const key in this.forms.authenticate.inputs) {
                if (this.forms.authenticate.inputs[key].value !== null) {
                    data[key] = this.forms.authenticate.inputs[key].value;
                }
            }

            const result = await pinovaApiRequest('pinova/user/authenticate', {
                method: 'POST',
                notifyOnError: false,
                headers: {
                    nonce: null,
                },
                signal: request.signal,
                form: true,
                data,
            });

            if (!this.requestIsCurrent(request)) {
                return;
            }

            if (result.success) {
                const responseData = result.data || {};
                this.forms.loginByPassword.inputs.identifier.value = this.forms.authenticate.inputs.identifier.value;

                if (nextStepName === 'forgotPassword') {
                    this.changeStep('forgotPassword');
                    this.forms.forgotPassword.inputs.jwt.value = responseData.jwt;
                    this.forms.forgotPassword.inputs.code.value = '';
                    this.resetOtpAutoSubmit('forgotPassword');
                    this.forms.forgotPassword.msg = result.message;
                    this.time.duration = responseData.ttl * 1000;
                    this.startTime();
                } else if (nextStepName === 'loginByOtp' || responseData.login_method === 'otp') {
                    this.changeStep('loginByOtp');
                    this.forms.loginByOtp.inputs.jwt.value = responseData.jwt;
                    this.forms.loginByOtp.inputs.code.value = '';
                    this.resetOtpAutoSubmit('loginByOtp');
                    this.forms.loginByOtp.msg = result.message;
                    this.time.duration = responseData.ttl * 1000;
                    this.startTime();
                } else if (responseData.login_method === 'password') {
                    this.changeStep('loginByPassword');
                }
            } else {
                if (result.data && result.data.native_login_url) {
                    window.location.href = result.data.native_login_url;
                    return;
                }
                this.handleRequestFailure(result);
            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
            console.error('Pinova authentication request failed:', error);
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
        }
    },

    async loginByOtp() {
        const request = this.beginRequest();
        if (!request) {
            return;
        }

        const formName = this.stepName;

        try {
            const data = {};
            for (const key in this.forms[formName].inputs) {
                if (this.forms[formName].inputs[key].value !== null) {
                    data[key] = this.forms[formName].inputs[key].value;
                }
            }

            const result = await pinovaApiRequest('pinova/user/login/otp', {
                method: 'POST',
                notifyOnError: false,
                headers: {
                    nonce: null,
                },
                signal: request.signal,
                form: true,
                data,
            });

            if (!this.requestIsCurrent(request)) {
                return;
            }

            if (result.success) {
                pinovaNotyf.success(result.message || 'درخواست با موفقیت انجام شد!');
                location.reload();
            } else {
                if (result.data && result.data.native_login_url) {
                    window.location.href = result.data.native_login_url;
                    return;
                }
                this.handleRequestFailure(result);
                this.forms[formName].inputs.code.value = '';
                this.resetOtpAutoSubmit(formName);
            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
            console.error('Pinova OTP login request failed:', error);
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
        }
    },

    async loginByPassword() {
        const request = this.beginRequest();
        if (!request) {
            return;
        }

        try {
            const data = {};
            for (const key in this.forms.loginByPassword.inputs) {
                if (this.forms.loginByPassword.inputs[key].value !== null) {
                    data[key] = this.forms.loginByPassword.inputs[key].value;
                }
            }

            const result = await pinovaApiRequest('pinova/user/login/password', {
                method: 'POST',
                notifyOnError: false,
                headers: {
                    nonce: null,
                },
                signal: request.signal,
                form: true,
                data,
            });

            if (!this.requestIsCurrent(request)) {
                return;
            }

            if (result.success) {
                pinovaNotyf.success(result.message || 'درخواست با موفقیت انجام شد!');
                location.reload();
            } else {
                if (result.data && result.data.native_login_url) {
                    window.location.href = result.data.native_login_url;
                    return;
                }
                this.handleRequestFailure(result);
            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
            console.error('Pinova password login request failed:', error);
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
        }
    },

    async forgotPassword() {
        const request = this.beginRequest();
        if (!request) {
            return;
        }

        try {
            const data = {};
            for (const key in this.forms.forgotPassword.inputs) {
                if (this.forms.forgotPassword.inputs[key].value !== null) {
                    data[key] = this.forms.forgotPassword.inputs[key].value;
                }
            }

            const result = await pinovaApiRequest('pinova/auth/forgot/verify', {
                method: 'POST',
                notifyOnError: false,
                headers: {
                    nonce: null,
                },
                signal: request.signal,
                form: true,
                data,
            });

            if (!this.requestIsCurrent(request)) {
                return;
            }

            if (result.success) {
                const responseData = result.data || {};
                this.changeStep('changePassword');
                this.forms.changePassword.inputs.jwt.value = responseData.jwt;
                this.forms.changePassword.inputs.reset_key.value = responseData.reset_key;
            } else {
                this.handleRequestFailure(result);
                this.forms.forgotPassword.inputs.code.value = '';
                this.resetOtpAutoSubmit('forgotPassword');
            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
            console.error('Pinova password recovery request failed:', error);
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
        }
    },

    async changePassword() {
        const request = this.beginRequest();
        if (!request) {
            return;
        }

        try {
            const data = {};
            for (const key in this.forms.changePassword.inputs) {
                if (this.forms.changePassword.inputs[key].value !== null) {
                    data[key] = this.forms.changePassword.inputs[key].value;
                }
            }

            const result = await pinovaApiRequest('pinova/auth/forgot/change', {
                method: 'POST',
                notifyOnError: false,
                headers: {
                    nonce: null,
                },
                signal: request.signal,
                form: true,
                data,
            });

            if (!this.requestIsCurrent(request)) {
                return;
            }

            if (result.success) {
                pinovaNotyf.success(result.message || 'درخواست با موفقیت انجام شد!');
                location.reload();
            } else {
                this.handleRequestFailure(result);
            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
            console.error('Pinova password change request failed:', error);
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
        }
    },

    setStatus(message, tone = 'error') {
        this.status.message = message || 'خطایی رخ داده است!';
        this.status.tone = tone;
    },

    clearStatus() {
        this.status.message = '';
        this.status.tone = 'info';
    },

    clearFormErrors() {
        for (const formName in this.forms) {
            for (const inputName in this.forms[formName].inputs) {
                this.forms[formName].inputs[inputName].errorMsg = '';
            }
        }
    },

    beginRequest() {
        if (this.pageLoaderIsActive) {
            return null;
        }

        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const request = {
            id: ++this.requestSequence,
            signal: controller ? controller.signal : null,
        };

        this.clearStatus();
        this.pageLoaderIsActive = true;
        this.activeRequestId = request.id;
        this.activeRequestAbort = controller ? () => controller.abort() : null;

        return request;
    },

    requestIsCurrent(request) {
        return Boolean(request) && this.activeRequestId === request.id;
    },

    endRequest(request) {
        if (!this.requestIsCurrent(request)) {
            return;
        }

        this.activeRequestId = 0;
        this.activeRequestAbort = null;
        this.pageLoaderIsActive = false;
    },

    cancelRequest() {
        const abort = this.activeRequestAbort;
        this.activeRequestId = 0;
        this.activeRequestAbort = null;
        this.pageLoaderIsActive = false;

        if (typeof abort === 'function') {
            abort();
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

    focusFirstInvalidField() {
        setTimeout(() => {
            const step = document.getElementById(`pinova-modal-${this.stepName}`);
            const firstInvalidField = step && step.querySelector('[aria-invalid="true"]');
            if (firstInvalidField) {
                firstInvalidField.focus();
            }
        }, 0);
    },

    focusStepHeading(stepName) {
        const focusRequest = ++this.focusSequence;
        setTimeout(() => {
            // A closed, reopened, or superseded step must not receive stale focus.
            if (focusRequest !== this.focusSequence || !this.modalIsOpen || this.stepName !== stepName) {
                return;
            }

            const step = document.getElementById(`pinova-modal-${stepName}`);
            const heading = step && step.querySelector('[data-pinova-step-heading]');
            if (heading) {
                heading.focus({ preventScroll: true });
            }
        }, 100);
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
        this.setStatus(result && result.message ? result.message : 'خطایی رخ داده است!');
    },

    handleRequestException(error) {
        this.setStatus(error?.pinovaMessage || 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.');
    },

    clearStepSecrets(stepName) {
        switch (stepName) {
            case 'signIn':
                this.forms.signIn.inputs.jwt.value = '';
                this.forms.signIn.inputs.code.value = '';
                this.resetOtpAutoSubmit('signIn');
                this.stopTimer();
                break;
            case 'loginByOtp':
                this.forms.loginByOtp.inputs.jwt.value = '';
                this.forms.loginByOtp.inputs.code.value = '';
                this.resetOtpAutoSubmit('loginByOtp');
                this.stopTimer();
                break;
            case 'forgotPassword':
                this.forms.forgotPassword.inputs.jwt.value = '';
                this.forms.forgotPassword.inputs.code.value = '';
                this.resetOtpAutoSubmit('forgotPassword');
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

    resetModalState() {
        this.resetSensitiveState();
        this.forms.authenticate.inputs.identifier.value = '';
        this.forms.loginByPassword.inputs.identifier.value = '';
        this.forms.signIn.msg = null;
        this.forms.loginByOtp.msg = null;
        this.forms.forgotPassword.msg = null;
        this.clearFormErrors();
        this.clearStatus();
        this.previousStepName = null;
        this.stepName = 'authenticate';
    },

    changeStep(newStep, options = {}) {
        if (!this.allowedSteps.includes(newStep)) {
            return;
        }

        if (this.stepName !== newStep) {
            this.clearStepSecrets(this.stepName);
        }

        if (newStep === 'authenticate') {
            this.resetSensitiveState();
        }

        this.previousStepName = this.stepName;
        this.stepName = newStep;
        this.clearStatus();

        if (this.modalIsOpen && options.focus !== false) {
            this.focusStepHeading(newStep);
        }
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

    backStep() {
        switch (this.stepName) {
            case 'signIn':
            case 'loginByPassword':
            case 'loginByOtp':
                this.changeStep('authenticate');
                break;
            case 'forgotPassword':
            case 'changePassword':
                this.changeStep('loginByPassword');
                break;
        }
    },

    lockPageInteraction() {
        const modalRoot = document.getElementById('pinovaLoginModal');
        if (!modalRoot || !document.body) {
            return;
        }

        this.previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        this.inertedElements = [];

        let branch = modalRoot;
        while (branch && branch.parentElement) {
            const parent = branch.parentElement;
            for (const sibling of Array.from(parent.children || [])) {
                if (sibling === branch) {
                    continue;
                }

                this.inertedElements.push({
                    element: sibling,
                    inert: Boolean(sibling.inert),
                    ariaHidden: sibling.getAttribute('aria-hidden'),
                });
                sibling.inert = true;
                sibling.setAttribute('aria-hidden', 'true');
            }

            if (parent === document.body) {
                break;
            }
            branch = parent;
        }
    },

    unlockPageInteraction() {
        for (const record of this.inertedElements) {
            record.element.inert = record.inert;
            if (record.ariaHidden === null) {
                record.element.removeAttribute('aria-hidden');
            } else {
                record.element.setAttribute('aria-hidden', record.ariaHidden);
            }
        }

        this.inertedElements = [];
        if (document.body) {
            document.body.style.overflow = this.previousBodyOverflow;
        }
    },

    openModal(identifier = '', opener = null) {
        if (this.modalIsOpen) {
            this.focusStepHeading(this.stepName);
            return;
        }

        this.returnFocusElement = opener || document.activeElement;
        this.resetModalState();
        this.forms.authenticate.inputs.identifier.value = String(identifier || '');
        this.modalIsOpen = true;
        this.lockPageInteraction();
        this.focusStepHeading('authenticate');

        if (identifier) {
            setTimeout(() => this.authenticate({ force_otp: '1' }), 0);
        }
    },

    closeModal() {
        if (!this.modalIsOpen) {
            return;
        }

        const returnFocusElement = this.returnFocusElement;
        const focusRequest = ++this.focusSequence;
        this.cancelRequest();
        this.modalIsOpen = false;
        this.resetModalState();
        this.unlockPageInteraction();
        this.returnFocusElement = null;

        if (returnFocusElement) {
            setTimeout(() => {
                if (focusRequest === this.focusSequence && !this.modalIsOpen) {
                    let focusTarget = returnFocusElement;

                    // WooCommerce can replace checkout fragments while the dialog is open.
                    // Resolve the equivalent live opener instead of focusing a detached node.
                    if (focusTarget.isConnected === false && typeof focusTarget.matches === 'function') {
                        let fallbackSelector = null;
                        if (focusTarget.matches('.pinova-open_login__modal')) {
                            fallbackSelector = '.pinova-open_login__modal';
                        } else if (focusTarget.matches('.showlogin')) {
                            fallbackSelector = '.showlogin';
                        }

                        focusTarget = fallbackSelector ? document.querySelector(fallbackSelector) : null;
                    }

                    if (focusTarget && typeof focusTarget.focus === 'function') {
                        focusTarget.focus({ preventScroll: true });
                    }
                }
            }, 0);
        }
    },

    handleDialogKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.closeModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const dialog = document.querySelector('#pinovaLoginModal [role="dialog"]');
        if (!dialog) {
            return;
        }

        const focusable = Array.from(dialog.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        )).filter(element => !element.hidden && !element.inert && element.offsetParent !== null);

        if (!focusable.length) {
            event.preventDefault();
            dialog.focus({ preventScroll: true });
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
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
}));

pinovaAlpine.start();

export function pinovaOpenModal(identifier, opener) {
    const element = document.querySelector('#pinovaLoginModal');
    if (!element) {
        return;
    }

    const component = pinovaAlpine.$data(element);
    component.openModal(identifier, opener);
}

window.pinovaOpenModal = pinovaOpenModal;
