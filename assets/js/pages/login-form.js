//define Alpine
import pinovaAlpine from './../alpine.min.js';
import { createAuthState } from './auth-state.js?ver=9';
pinovaAlpine.prefix("pinova-");
pinovaAlpine.data("pinovaLoginForm", ()=>({

        ...createAuthState(),
        requestSequence: 0,
        activeRequestId: 0,
        activeRequestAbort: null,

        init(){
            this.changeStep(this.stepName, {replace: true, fromHistory: true, focus: false});

            window.addEventListener('popstate', (event) => {
                const targetStep = event.state && event.state.pinovaStep;
                this.changeStep(
                    this.allowedSteps.includes(targetStep) ? targetStep : 'authenticate',
                    {fromHistory: true}
                );
            });

            window.addEventListener('beforeunload', () => { this.cancelRequest(); this.stopTimer(); });

            this.initWebOtpLifecycle();

        },

        submit(){

            if(this.pageLoaderIsActive){
                return
            }

            this.clearStatus();

            this.clearFormErrors();

            //validation
            let hasError = false;
            for (const key in this.forms[this.stepName].inputs) {
                const validate = pinovaValidateField(this.forms[this.stepName].inputs[key]);
                if(validate.errorMsg){
                    hasError = true
                }
                this.forms[this.stepName].inputs[key] = validate
            }

            if(hasError){
                this.focusFirstInvalidField();
                return;
            }

            switch (this.stepName){

                case 'authenticate': {
                    this.authenticate();
                    break;
                }

                case 'signIn': {
                    this.loginByOtp();
                    break;
                }

                case 'loginByPassword': {
                    this.loginByPassword();
                    break;
                }

                case 'loginByOtp': {
                    this.loginByOtp();
                    break;
                }

                case 'forgotPassword': {
                    this.forgotPassword();
                    break;
                }

                case 'changePassword': {
                    this.changePassword();
                    break;
                }

            }
        },

        //requests
        async authenticate(otherData = {}, nextStepName = null){
            const request = this.beginRequest();
            if(!request){
                return;
            }

            try{
                const data = {...otherData};
                for (const key in this.forms.authenticate.inputs) {
                    if(this.forms.authenticate.inputs[key].value !== null){
                        data[key] = this.forms.authenticate.inputs[key].value
                    }
                }

                const result = await pinovaApiRequest('pinova/user/authenticate', {
                    method: 'POST',
                    notifyOnError: false,
                    headers:{
                        nonce: null
                    },
                    signal: request.signal,
                    form: true,
                    data
                })

                if(!this.requestIsCurrent(request)){
                    return;
                }

                if(result.success){
                    const responseData = result.data;
                    this.forms.loginByPassword.inputs.identifier.value = this.forms.authenticate.inputs.identifier.value;

                    if(nextStepName === "forgotPassword"){
                        this.changeStep("forgotPassword", {fromRequest: true});
                        this.forms.forgotPassword.inputs.jwt.value = responseData.jwt;
                        this.forms.forgotPassword.inputs.code.value = '';
                        this.resetOtpAutoSubmit('forgotPassword');
                        this.forms.forgotPassword.msg = result.message;
                        this.time.duration = responseData.ttl * 1000;
                        this.startTime();
                        this.startWebOtp(this.stepName);
                    }else if(nextStepName === "loginByOtp" || responseData.login_method === 'otp'){
                        this.changeStep("loginByOtp", {fromRequest: true});
                        this.forms.loginByOtp.inputs.jwt.value = responseData.jwt;
                        this.forms.loginByOtp.inputs.code.value = '';
                        this.resetOtpAutoSubmit('loginByOtp');
                        this.forms.loginByOtp.msg = result.message;
                        this.time.duration = responseData.ttl * 1000;
                        this.startTime();
                        this.startWebOtp(this.stepName);
                    }else if(responseData.login_method === 'password'){
                        this.changeStep('loginByPassword', {fromRequest: true});
                    }

                }else{
                    if (result.data && result.data.native_login_url) {
                        window.location.href = result.data.native_login_url;
                        return;
                    }
                    this.handleRequestFailure(result);
                }

            }catch (error){
                if(!this.requestIsCurrent(request)){
                    return;
                }
                this.handleRequestException(error);
            }finally{
                this.endRequest(request);
            }
        },

        async loginByOtp(){
            const request = this.beginRequest();
            if(!request){
                return;
            }

            const formName = this.stepName;

            try{
                const data = {};

                for (const key in this.forms[formName].inputs) {
                    if(this.forms[formName].inputs[key].value !== null) {
                        data[key] = this.forms[formName].inputs[key].value
                    }
                }

                const result = await pinovaApiRequest('pinova/user/login/otp', {
                    method: 'POST',
                    notifyOnError: false,
                    headers:{
                        nonce: null
                    },
                    signal: request.signal,
                    form: true,
                    data
                })

                if(!this.requestIsCurrent(request)){
                    return;
                }

                if(result.success){
                    const responseData = result.data;
                    pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');

                    if (this.handleInterimLoginSuccess()) {
                        return;
                    }

                    window.location.href = responseData.back_url;
                }else{
                    if (result.data && result.data.native_login_url) {
                        window.location.href = result.data.native_login_url;
                        return;
                    }
                    this.handleRequestFailure(result);
                    this.forms[formName].inputs.code.value = '';
                    this.resetOtpAutoSubmit(formName);
                }

            }catch (error){
                if(!this.requestIsCurrent(request)){
                    return;
                }
                this.handleRequestException(error);
            }finally{
                this.endRequest(request);
            }
        },

        async loginByPassword(){
            const request = this.beginRequest();
            if(!request){
                return;
            }

            try{
                const data = {};
                for (const key in this.forms.loginByPassword.inputs) {
                    if(this.forms.loginByPassword.inputs[key].value !== null) {
                        data[key] = this.forms.loginByPassword.inputs[key].value
                    }
                }

                const result = await pinovaApiRequest('pinova/user/login/password', {
                    method: 'POST',
                    notifyOnError: false,
                    headers:{
                        nonce: null
                    },
                    signal: request.signal,
                    form: true,
                    data
                })

                if(!this.requestIsCurrent(request)){
                    return;
                }

                if(result.success){
                    const responseData = result.data;
                    pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');

                    if (this.handleInterimLoginSuccess()) {
                        return;
                    }

                    window.location.href = responseData.back_url;
                }else{
                    if (result.data && result.data.native_login_url) {
                        window.location.href = result.data.native_login_url;
                        return;
                    }
                    this.handleRequestFailure(result);
                }

            }catch (error){
                if(!this.requestIsCurrent(request)){
                    return;
                }
                this.handleRequestException(error);
            }finally{
                this.endRequest(request);
            }
        },

        async forgotPassword(){
            const request = this.beginRequest();
            if(!request){
                return;
            }

            try{
                const data = {};
                for (const key in this.forms.forgotPassword.inputs) {
                    if(this.forms.forgotPassword.inputs[key].value !== null) {
                        data[key] = this.forms.forgotPassword.inputs[key].value
                    }
                }

                const result = await pinovaApiRequest('pinova/auth/forgot/verify', {
                    method: 'POST',
                    notifyOnError: false,
                    headers:{
                        nonce: null
                    },
                    signal: request.signal,
                    form: true,
                    data
                })

                if(!this.requestIsCurrent(request)){
                    return;
                }

                if(result.success){
                    const responseData = result.data;

                    this.changeStep('changePassword', {fromRequest: true});
                    this.forms.changePassword.inputs.jwt.value = responseData.jwt;
                    this.forms.changePassword.inputs.reset_key.value = responseData.reset_key;
                }else{
                    this.handleRequestFailure(result);
                    this.forms.forgotPassword.inputs.code.value = '';
                    this.resetOtpAutoSubmit('forgotPassword');
                }

            }catch (error){
                if(!this.requestIsCurrent(request)){
                    return;
                }
                this.handleRequestException(error);
            }finally{
                this.endRequest(request);
            }
        },

        async changePassword(){
            const request = this.beginRequest();
            if(!request){
                return;
            }

            try{
                const data = {};
                for (const key in this.forms.changePassword.inputs) {
                    if(this.forms.changePassword.inputs[key].value !== null) {
                        data[key] = this.forms.changePassword.inputs[key].value
                    }
                }

                const result = await pinovaApiRequest('pinova/auth/forgot/change', {
                    method: 'POST',
                    notifyOnError: false,
                    headers:{
                        nonce: null
                    },
                    signal: request.signal,
                    form: true,
                    data
                })

                if(!this.requestIsCurrent(request)){
                    return;
                }

                if(result.success){
                    const responseData = result.data;
                    pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                    window.location.href = responseData.back_url;
                }else{
                    this.handleRequestFailure(result);
                }

            }catch (error){
                if(!this.requestIsCurrent(request)){
                    return;
                }
                this.handleRequestException(error);
            }finally{
                this.endRequest(request);
            }
        },

        //other function
        beginRequest(){
            if(this.pageLoaderIsActive){
                return null;
            }
            this.stopWebOtp();
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const request = {id: ++this.requestSequence, signal: controller ? controller.signal : null};
            this.pageLoaderIsActive = true;
            this.activeRequestId = request.id;
            this.activeRequestAbort = controller ? () => controller.abort() : null;
            return request;
        },

        requestIsCurrent(request){
            return Boolean(request) && this.activeRequestId === request.id;
        },

        endRequest(request){
            if(!this.requestIsCurrent(request)){
                return;
            }
            this.activeRequestId = 0;
            this.activeRequestAbort = null;
            this.pageLoaderIsActive = false;
            this.resumeWebOtp();
        },

        cancelRequest(){
            this.stopWebOtp();
            const abort = this.activeRequestAbort;
            this.activeRequestId = 0;
            this.activeRequestAbort = null;
            this.pageLoaderIsActive = false;
            if(typeof abort === 'function'){
                abort();
            }
        },

        focusFirstInvalidField(){
            setTimeout(()=>{
                const step = this.$el?.querySelector(`[data-pinova-step="${this.stepName}"]`) || document.getElementById(this.stepName);
                const firstInvalidField = step && step.querySelector('[aria-invalid="true"]');
                if(firstInvalidField){
                    firstInvalidField.focus();
                }
            }, 0);
        },

        focusStepHeading(stepName){
            setTimeout(()=>{
                const step = this.$el?.querySelector(`[data-pinova-step="${stepName}"]`) || document.getElementById(stepName);
                const heading = step && step.querySelector('[data-pinova-step-heading]');
                if(heading){
                    heading.focus({preventScroll: true});
                }
            }, 100);
        },

        changeStep(newStep, options = {}){
            if(!this.allowedSteps.includes(newStep)){
                return;
            }

            if(!options.fromRequest){
                this.cancelRequest();
            }
            this.stopWebOtp();

            if(this.stepName !== newStep){
                this.clearStepSecrets(this.stepName);
            }

            if(newStep === 'authenticate'){
                this.resetSensitiveState();
            }

            this.previousStepName = this.stepName;
            this.stepName = newStep;
            this.clearStatus();

            if(!options.fromHistory){
                const method = options.replace ? 'replaceState' : 'pushState';
                window.history[method]({pinovaStep: newStep}, '', window.location.href);
            }else if(options.replace){
                window.history.replaceState({pinovaStep: newStep}, '', window.location.href);
            }

            if(options.focus !== false){
                this.focusStepHeading(newStep);
            }
        },

        backStep(){
            this.cancelRequest();
            if(window.history.state && window.history.state.pinovaStep === this.stepName){
                window.history.back();
                return;
            }

            switch (this.stepName){

                case 'authenticate': {
                    break;
                }

                case 'signIn': {
                    this.changeStep('authenticate');
                    break;
                }

                case 'loginByPassword': {
                    this.changeStep('authenticate');
                    break;
                }

                case 'loginByOtp': {
                    this.changeStep('authenticate');
                    break;
                }

                case 'forgotPassword': {
                    this.changeStep('loginByPassword');
                    break;
                }

                case 'changePassword': {
                    this.changeStep('loginByPassword');
                    break;
                }

            }
        },

        // Admin Iframe
        isInterimLogin() {
            try {
                const iframe = window.parent.document.querySelector('#wp-auth-check-frame');

                if (!iframe) {
                    return false;
                }

                const url = new URL(iframe.getAttribute('src'), window.location.origin);

                return url.searchParams.get('interim-login') === '1';

            } catch (e) {
                return false;
            }
        },
        handleInterimLoginSuccess() {
            if (!this.isInterimLogin()) {
                return false;
            }

            document.body.classList.add('interim-login-success');

            if (window.parent.jQuery && window.frameElement) {
                window.parent.jQuery(window.frameElement).trigger('load');
            }

            return true;
        }

    }))
pinovaAlpine.start();
