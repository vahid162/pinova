//define Alpine
import pinovaAlpine from './../alpine.min.js';
pinovaAlpine.prefix("pinova-");
pinovaAlpine.data("pinovaLoginForm", ()=>({

        logo: pinova.logo,

        stepName: 'authenticate',
        previousStepName: null,
        pageLoaderIsActive: false,
        codeLength: pinova.code_length,
        allowedSteps: [
            'authenticate',
            'signIn',
            'loginByPassword',
            'loginByOtp',
            'forgotPassword',
            'changePassword'
        ],
        status: {
            message: '',
            tone: 'info'
        },
        otpSubmittedCodes: {
            signIn: '',
            loginByOtp: '',
            forgotPassword: ''
        },

        forms: {
            authenticate:{
                inputs:{
                    identifier: {
                        value: '',
                        rules: {
                            required: true
                        },
                        errorMsg: ''
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                }
            },
            signIn: {
                inputs:{
                    jwt:{
                        value: '',
                        errorMsg: ''
                    },
                    code:{
                        value: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number']
                        },
                        errorMsg: ''
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                },
                msg: null,
            },
            loginByPassword: {
                inputs:{
                    identifier:{
                        value: '',
                        errorMsg: ''
                    },
                    password:{
                        value: '',
                        rules: {
                            required: true
                        },
                        errorMsg: ''
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                }
            },
            loginByOtp: {
                inputs:{
                    jwt:{
                        value: '',
                        errorMsg: ''
                    },
                    code:{
                        value: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number']
                        },
                        errorMsg: ''
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                },
                msg: null,
            },
            forgotPassword: {
                inputs:{
                    jwt:{
                        value: '',
                        errorMsg: ''
                    },
                    code:{
                        value: '',
                        errorMsg: '',
                        rules: {
                            required: true,
                            minLength: pinova.code_length,
                            type: ['number']
                        },
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                },
                msg: null,
            },
            changePassword: {
                inputs:{
                    jwt:{
                        value: '',
                        errorMsg: ''
                    },
                    reset_key:{
                        value: '',
                        errorMsg: ''
                    },
                    password_1: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: 8
                        },
                        errorMsg: ''
                    },
                    password_2: {
                        value: '',
                        rules: {
                            required: true,
                            minLength: 8
                        },
                        errorMsg: ''
                    },
                    back_url: {
                        value: pinovaGetQueryParam("back_url"),
                        errorMsg: ''
                    }
                }
            },
        },

        time:{
            duration: 120 * 1000, //2 minutes in seconds
            timerInterval : null,
            textTime: "03:00",
            textTimeMinutes: '',
            textTimeSeconds: '',
            btnResendIsActive: false
        },

        init(){
            this.changeStep(this.stepName, {replace: true, fromHistory: true, focus: false});

            window.addEventListener('popstate', (event) => {
                const targetStep = event.state && event.state.pinovaStep;
                this.changeStep(
                    this.allowedSteps.includes(targetStep) ? targetStep : 'authenticate',
                    {fromHistory: true}
                );
            });

            window.addEventListener('beforeunload', () => this.stopTimer());

            if ('OTPCredential' in window) {
                window.addEventListener('DOMContentLoaded', e => {
                    navigator.credentials.get({
                        otp: { transport:['sms'] },
                    }).then(otp => {
                        if (otp.code) {

                            switch (this.stepName) {
                                case 'loginByOtp': {
                                    this.forms.loginByOtp.inputs.code.value = pinovaCleanNumericInput(otp.code);
                                    this.handleOtpInput('loginByOtp');
                                    break;
                                }

                                case 'signIn': {
                                    this.forms.signIn.inputs.code.value = pinovaCleanNumericInput(otp.code);
                                    this.handleOtpInput('signIn');
                                    break;
                                }

                                case 'forgotPassword': {
                                    this.forms.forgotPassword.inputs.code.value = pinovaCleanNumericInput(otp.code);
                                    this.handleOtpInput('forgotPassword');
                                    break;
                                }
                            }
                        }
                    }).catch(err => {
                        console.log(err);
                    });
                });
            }

        },

        submit(){

            if(this.pageLoaderIsActive){
                return
            }

            this.clearStatus();

            for (const key in this.forms){
                for (const inputKey in this.forms[key].inputs){
                    this.forms[key].inputs[inputKey].errorMsg = "";
                }
            }

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
            if(!this.beginRequest()){
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
                    form: true,
                    data
                })

                if(result.success){
                    const responseData = result.data;
                    this.forms.loginByPassword.inputs.identifier.value = this.forms.authenticate.inputs.identifier.value;

                    if(nextStepName === "forgotPassword"){
                        this.changeStep("forgotPassword");
                        this.forms.forgotPassword.inputs.jwt.value = responseData.jwt;
                        this.forms.forgotPassword.inputs.code.value = '';
                        this.resetOtpAutoSubmit('forgotPassword');
                        this.forms.forgotPassword.msg = result.message;
                        this.time.duration = responseData.ttl * 1000;
                        this.startTime();
                    }else if(nextStepName === "loginByOtp" || responseData.login_method === 'otp'){
                        this.changeStep("loginByOtp");
                        this.forms.loginByOtp.inputs.jwt.value = responseData.jwt;
                        this.forms.loginByOtp.inputs.code.value = '';
                        this.resetOtpAutoSubmit('loginByOtp');
                        this.forms.loginByOtp.msg = result.message;
                        this.time.duration = responseData.ttl * 1000;
                        this.startTime();
                    }else if(responseData.login_method === 'password'){
                        this.changeStep('loginByPassword');
                    }

                }else{
                    if (result.data && result.data.native_login_url) {
                        window.location.href = result.data.native_login_url;
                        return;
                    }
                    this.handleRequestFailure(result);
                }

            }catch (error){
                console.error('Error fetching posts:', error);
                this.handleRequestException(error);
            }finally{
                this.endRequest();
            }
        },

        async loginByOtp(){
            if(!this.beginRequest()){
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
                    form: true,
                    data
                })

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
                console.error('Error fetching posts:', error);
                this.handleRequestException(error);
            }finally{
                this.endRequest();
            }
        },

        async loginByPassword(){
            if(!this.beginRequest()){
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
                    form: true,
                    data
                })

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
                console.error('Error fetching posts:', error);
                this.handleRequestException(error);
            }finally{
                this.endRequest();
            }
        },

        async forgotPassword(){
            if(!this.beginRequest()){
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
                    form: true,
                    data
                })

                if(result.success){
                    const responseData = result.data;

                    this.changeStep('changePassword');
                    this.forms.changePassword.inputs.jwt.value = responseData.jwt;
                    this.forms.changePassword.inputs.reset_key.value = responseData.reset_key;
                }else{
                    this.handleRequestFailure(result);
                    this.forms.forgotPassword.inputs.code.value = '';
                    this.resetOtpAutoSubmit('forgotPassword');
                }

            }catch (error){
                console.error('Error fetching posts:', error);
                this.handleRequestException(error);
            }finally{
                this.endRequest();
            }
        },

        async changePassword(){
            if(!this.beginRequest()){
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
                    form: true,
                    data
                })

                if(result.success){
                    const responseData = result.data;
                    pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                    window.location.href = responseData.back_url;
                }else{
                    this.handleRequestFailure(result);
                }

            }catch (error){
                console.error('Error fetching posts:', error);
                this.handleRequestException(error);
            }finally{
                this.endRequest();
            }
        },

        //other function
        setStatus(message, tone = 'error'){
            this.status.message = message || 'خطایی رخ داده است!';
            this.status.tone = tone;
        },

        clearStatus(){
            this.status.message = '';
            this.status.tone = 'info';
        },

        beginRequest(){
            if(this.pageLoaderIsActive){
                return false;
            }

            this.pageLoaderIsActive = true;
            return true;
        },

        endRequest(){
            this.pageLoaderIsActive = false;
        },

        handleOtpInput(formName){
            if(!Object.prototype.hasOwnProperty.call(this.otpSubmittedCodes, formName)){
                return;
            }

            const field = this.forms[formName].inputs.code;
            const normalizedCode = pinovaCleanNumericInput(field.value).slice(0, this.codeLength);
            field.value = normalizedCode;

            if(normalizedCode.length !== Number(this.codeLength)){
                this.resetOtpAutoSubmit(formName);
                return;
            }

            if(this.otpSubmittedCodes[formName] === normalizedCode || this.pageLoaderIsActive){
                return;
            }

            this.otpSubmittedCodes[formName] = normalizedCode;
            this.submit();
        },

        resetOtpAutoSubmit(formName){
            if(Object.prototype.hasOwnProperty.call(this.otpSubmittedCodes, formName)){
                this.otpSubmittedCodes[formName] = '';
            }
        },

        focusFirstInvalidField(){
            setTimeout(()=>{
                const step = document.getElementById(this.stepName);
                const firstInvalidField = step && step.querySelector('[aria-invalid="true"]');
                if(firstInvalidField){
                    firstInvalidField.focus();
                }
            }, 0);
        },

        focusStepHeading(stepName){
            setTimeout(()=>{
                const step = document.getElementById(stepName);
                const heading = step && step.querySelector('[data-pinova-step-heading]');
                if(heading){
                    heading.focus({preventScroll: true});
                }
            }, 100);
        },

        identifierEditLabel(){
            const identifier = String(this.forms.authenticate.inputs.identifier.value || '').trim();

            if(identifier.includes('@')){
                return 'ویرایش ایمیل';
            }

            if(/^[+()\s0-9۰-۹٠-٩-]+$/.test(identifier)){
                return 'ویرایش شماره';
            }

            return 'ویرایش شناسه';
        },

        editIdentifier(){
            this.changeStep('authenticate');
        },

        handleRequestFailure(result){
            this.setStatus(result && result.message ? result.message : 'خطایی رخ داده است!');
        },

        handleRequestException(){
            this.setStatus('ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.');
        },

        clearStepSecrets(stepName){
            switch(stepName){
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

        resetSensitiveState(){
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

        changeStep(newStep, options = {}){
            if(!this.allowedSteps.includes(newStep)){
                return;
            }

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

        stopTimer(){
            if(this.time.timerInterval !== null){
                clearInterval(this.time.timerInterval);
            }
            this.time.timerInterval = null;
            this.time.btnResendIsActive = true;
        },

        startTime(){
            this.stopTimer();
            this.time.btnResendIsActive = false;
            const endTime = Date.now() + this.time.duration;

            this.updateTimer(this.time.duration);

            this.time.timerInterval = setInterval(()=>{

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

        updateTimer(remaining){
            const totalSeconds = Math.ceil(remaining / 1000);
            let minutes = Math.floor(totalSeconds / 60);
            let seconds = Math.floor(totalSeconds % 60);

            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            this.time.textTime = `${minutes}:${seconds}`;

            this.time.textTimeMinutes = minutes.toString();
            this.time.textTimeSeconds = seconds.toString();
        },

        backStep(){
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

        //password strength
        checkStringLength(str){

            if(str.length >= 8){
                return true
            }

            return false;
        },

        checkStringIncludeNumber(str){

            if(/\d/.test(str)){ //check has number
                return true;
            }

            return false;
        },

        checkStringIncludeSymbols(str){

            if(/[^\w\s]/.test(str)){ //check has symbol
                return true;
            }

            return false;
        },

        checkStringIncludeUppercaseAndLowercase(str){

            if(/[a-z]/.test(str) && /[A-Z]/.test(str)){ //check has letter uppercase and lowercase
                return true;
            }

            return false;
        },

        passwordStrengthAssessment(){

            let passwordStrength = 0;

            if(this.checkStringLength(this.forms.changePassword.inputs.password_1.value)){ //check length
                passwordStrength+=1;
            }

            if(this.checkStringIncludeNumber(this.forms.changePassword.inputs.password_1.value)){ //check has number
                passwordStrength+=1;
            }

            if(this.checkStringIncludeSymbols(this.forms.changePassword.inputs.password_1.value)){ //check has symbol
                passwordStrength+=1;
            }

            if(this.checkStringIncludeUppercaseAndLowercase(this.forms.changePassword.inputs.password_1.value)){ //check has letter uppercase and lowercase
                passwordStrength+=1;
            }

            return passwordStrength;

        },

        passwordStrengthLabel(){
            const strength = this.passwordStrengthAssessment();
            if(strength === 4){
                return 'قدرت رمز: عالی';
            }
            if(strength === 3){
                return 'قدرت رمز: متوسط';
            }
            return 'قدرت رمز: ضعیف';
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
