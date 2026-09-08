//define Alpine
import pinovaAlpine from './../alpine.min.js';
pinovaAlpine.prefix("pinova-");
pinovaAlpine.data("pinovaLoginModal", ()=>({

    modalIsOpen: false,
    logo: pinova.logo,

    stepName: 'authenticate',
    previousStepName: null,
    pageLoaderIsActive: false,
    codeLength: pinova.code_length,

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
        timeLeft: 120 * 1000, //2 minutes in seconds
        timerInterval : null,
        textTime: "03:00",
        textTimeMinutes: '',
        textTimeSeconds: '',
        btnResendIsActive: false
    },

    init(){
        this.changeStep(this.stepName);

        if ('OTPCredential' in window) {
            window.addEventListener('DOMContentLoaded', e => {
                navigator.credentials.get({
                    otp: { transport:['sms'] },
                }).then(otp => {
                    if (otp.code) {

                        switch (this.stepName) {
                            case 'loginByOtp': {
                                this.forms.loginByOtp.inputs.code.value = otp.code;
                                this.submit();
                                break;
                            }

                            case 'signIn': {
                                this.forms.signIn.inputs.code.value = otp.code;
                                this.submit();
                                break;
                            }

                            case 'forgotPassword': {
                                this.forms.forgotPassword.inputs.code.value = otp.code;
                                this.submit();
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
        try{
            this.pageLoaderIsActive = true;

            const data = otherData;
            for (const key in this.forms.authenticate.inputs) {
                if(this.forms.authenticate.inputs[key].value !== null){
                    data[key] = this.forms.authenticate.inputs[key].value
                }
            }

            const result = await pinovaApiRequest('pinova/user/authenticate', {
                method: 'POST',
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
                    this.forms.forgotPassword.msg = result.message;
                    if(!this.time.timerInterval){
                        this.time.timeLeft = responseData.ttl * 1000;
                        this.startTime();
                    }
                }else if(nextStepName === "loginByOtp" || responseData.login_method === 'otp'){
                    this.changeStep("loginByOtp");
                    this.forms.loginByOtp.inputs.jwt.value = responseData.jwt;
                    this.forms.loginByOtp.inputs.code.value = '';
                    this.forms.loginByOtp.msg = result.message;
                    if(!this.time.timerInterval){
                        this.time.timeLeft = responseData.ttl * 1000;
                        this.startTime();
                    }
                }else if(responseData.login_method === 'password'){
                    this.changeStep('loginByPassword');
                }

            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
            }

            this.pageLoaderIsActive = false;

        }catch (error){
            console.error('Error fetching posts:', error);
            this.pageLoaderIsActive = false;
        }
    },

    async loginByOtp(){
        try{
            this.pageLoaderIsActive = true;

            const data = {};

            for (const key in this.forms[this.stepName].inputs) {
                if(this.forms[this.stepName].inputs[key].value !== null) {
                    data[key] = this.forms[this.stepName].inputs[key].value
                }
            }

            const result = await pinovaApiRequest('pinova/user/login/otp', {
                method: 'POST',
                headers:{
                    nonce: null
                },
                form: true,
                data
            })

            if(result.success){
                const responseData = result.data;
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                location.reload();
            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.forms[this.stepName].inputs.code.value = null;
                this.pageLoaderIsActive = false;
            }

        }catch (error){
            console.error('Error fetching posts:', error);
            this.pageLoaderIsActive = false;
        }
    },

    async loginByPassword(){
        try{
            this.pageLoaderIsActive = true;

            const data = {};
            for (const key in this.forms.loginByPassword.inputs) {
                if(this.forms.loginByPassword.inputs[key].value !== null) {
                    data[key] = this.forms.loginByPassword.inputs[key].value
                }
            }

            const result = await pinovaApiRequest('pinova/user/login/password', {
                method: 'POST',
                headers:{
                    nonce: null
                },
                form: true,
                data
            })

            if(result.success){
                const responseData = result.data;
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                location.reload();
            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.pageLoaderIsActive = false;
            }

        }catch (error){
            console.error('Error fetching posts:', error);
            this.pageLoaderIsActive = false;
        }
    },

    async forgotPassword(){
        try{
            this.pageLoaderIsActive = true;

            const data = {};
            for (const key in this.forms.forgotPassword.inputs) {
                if(this.forms.forgotPassword.inputs[key].value !== null) {
                    data[key] = this.forms.forgotPassword.inputs[key].value
                }
            }

            const result = await pinovaApiRequest('pinova/auth/forgot/verify', {
                method: 'POST',
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
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.forms.forgotPassword.inputs.code.value = null;
            }

            this.pageLoaderIsActive = false;

        }catch (error){
            console.error('Error fetching posts:', error);
            this.pageLoaderIsActive = false;
        }
    },

    async changePassword(){
        try{
            this.pageLoaderIsActive = true;

            const data = {};
            for (const key in this.forms.changePassword.inputs) {
                if(this.forms.changePassword.inputs[key].value !== null) {
                    data[key] = this.forms.changePassword.inputs[key].value
                }
            }

            const result = await pinovaApiRequest('pinova/auth/forgot/change', {
                method: 'POST',
                headers:{
                    nonce: null
                },
                form: true,
                data
            })

            if(result.success){
                pinovaNotyf.success(result.message ? result.message : 'درخواست با موفقیت انجام شد!');
                location.reload();
            }else{
                pinovaNotyf.error(result.message ? result.message : 'خطایی رخ داده است!');
                this.pageLoaderIsActive = false;
            }

        }catch (error){
            console.error('Error fetching posts:', error);
            this.pageLoaderIsActive = false;
        }
    },

    //other function
    changeStep(newStep){
        this.stepName = newStep;
        setTimeout(()=>{
            document.getElementById(newStep).querySelector('input').focus();
        }, 100)
    },

    startTime(){
        this.time.timerInterval = null;
        clearInterval(this.time.timerInterval);
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
        switch (this.stepName){

            case 'authenticate': {
                this.stepName = null;
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

    openModal(identifier){
        this.modalIsOpen = true;
        if(identifier){
            this.forms.authenticate.inputs.identifier.value = identifier;
            this.authenticate({force_otp : '1'})
        }
    }

}))
pinovaAlpine.start();

//globally function for open login modal
export function pinovaOpenModal(identifier){
    const el = document.querySelector('#pinovaLoginModal');
    const pageComponent = pinovaAlpine.$data(el);
    pageComponent.openModal(identifier)
}
window.pinovaOpenModal = pinovaOpenModal;
