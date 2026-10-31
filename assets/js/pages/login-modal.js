import pinovaAlpine from './../alpine.min.js';
import { createAuthState } from './auth-state.js?ver=9';

pinovaAlpine.prefix('pinova-');
pinovaAlpine.data('pinovaLoginModal', () => ({
    ...createAuthState(),
    modalIsOpen: false,
    returnFocusElement: null,
    focusSequence: 0,
    requestSequence: 0,
    activeRequestId: 0,
    activeRequestAbort: null,
    previousBodyOverflow: '',
    inertedElements: [],
    init() {
        window.addEventListener('beforeunload', () => {
            this.cancelRequest();
            this.stopTimer();
            this.unlockPageInteraction();
        });

        this.initWebOtpLifecycle();

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

        this.stopWebOtp();

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
                    this.changeStep('forgotPassword', {fromRequest: true});
                    this.forms.forgotPassword.inputs.jwt.value = responseData.jwt;
                    this.forms.forgotPassword.inputs.code.value = '';
                    this.resetOtpAutoSubmit('forgotPassword');
                    this.forms.forgotPassword.msg = result.message;
                    this.time.duration = responseData.ttl * 1000;
                    this.startTime();
                    this.startWebOtp(this.stepName);
                } else if (nextStepName === 'loginByOtp' || responseData.login_method === 'otp') {
                    this.changeStep('loginByOtp', {fromRequest: true});
                    this.forms.loginByOtp.inputs.jwt.value = responseData.jwt;
                    this.forms.loginByOtp.inputs.code.value = '';
                    this.resetOtpAutoSubmit('loginByOtp');
                    this.forms.loginByOtp.msg = result.message;
                    this.time.duration = responseData.ttl * 1000;
                    this.startTime();
                    this.startWebOtp(this.stepName);
                } else if (responseData.login_method === 'password') {
                    this.changeStep('loginByPassword', {fromRequest: true});
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

            this.otpSubmittedCodes[formName] = data.code;

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
                this.stopWebOtp();
                pinovaNotyf.success(result.message || 'درخواست با موفقیت انجام شد!');
                location.reload();
            } else {
                if (result.data && result.data.native_login_url) {
                    window.location.href = result.data.native_login_url;
                    return;
                }
                this.handleRequestFailure(result);
                this.forms[formName].inputs.code.value = '';

            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
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

            this.otpSubmittedCodes['forgotPassword'] = data.code;

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
                this.changeStep('changePassword', {fromRequest: true});
                this.forms.changePassword.inputs.jwt.value = responseData.jwt;
                this.forms.changePassword.inputs.reset_key.value = responseData.reset_key;
            } else {
                this.handleRequestFailure(result);
                this.forms.forgotPassword.inputs.code.value = '';

            }
        } catch (error) {
            if (!this.requestIsCurrent(request)) {
                return;
            }
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
            this.handleRequestException(error);
        } finally {
            this.endRequest(request);
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
        this.resumeWebOtp();
    },

    cancelRequest() {
        this.stopWebOtp();
        const abort = this.activeRequestAbort;
        this.activeRequestId = 0;
        this.activeRequestAbort = null;
        this.pageLoaderIsActive = false;

        if (typeof abort === 'function') {
            abort();
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

        if (!options.fromRequest) {
            this.cancelRequest();
        }
        this.stopWebOtp();

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
            const restoreFocus = () => {
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
            };

            // Wait for Alpine to hide the dialog before returning focus outside it.
            this.$nextTick(restoreFocus);
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
