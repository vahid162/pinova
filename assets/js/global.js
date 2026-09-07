//variables
let pinovaNotyf = null;
window.addEventListener('load', function () {
    pinovaNotyf = new Notyf({
        duration: 3000,
        position: {
            x: pinova.adminPage ? 'right' : 'center',
            y: pinova.adminPage ? 'bottom' : 'top',
        },
        dismissible: true,
        types: [
            {
                type: 'warning',
                background: '#ffc107',
            },
            {
                type: 'error',
                icon: false,
                background: pinova.adminPage ? '#ED3D3DFF' : '#0c0c0c',
            },
            {
                type: 'success',
                icon: false
            }
        ]
    });

    const style = document.createElement('style');
    style.innerHTML = `
        .notyf {
            z-index: 999999 !important;
        }
        
        @media (min-width: 640px) {
            .notyf__wrapper {
                min-width: 225px;
            }
        }
        .notyf__wrapper .notyf__dismiss {
            width: 30px !important;
        }
        .notyf__wrapper .notyf__message {
            font-size: 14px !important;
            font-weight: 500 !important;
        }
    `;
    document.head.appendChild(style);
})

//functions
function pinovaGetQueryParam(key) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(key);
}

async function pinovaApiRequest(url, options = {}) {

    const {
        method = 'GET',
        data = {},
        headers = {},
        form = false
    } = options;

    if(headers.nonce !== null){
        headers['X-WP-Nonce'] = pinova.nonce
    }

    try {
        const fetchOptions = {
            method,
            headers
        };

        if (data && method !== 'GET') {
            if (data instanceof FormData) {
                fetchOptions.body = data;
            } else if (form) {
                fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded';

                const params = new URLSearchParams();
                for (const key in data) {
                    const value = data[key];
                    if (Array.isArray(value)) {
                        value.forEach(item => {
                            params.append(`${key}[]`, item);
                        });
                    } else {
                        params.append(key, value);
                    }
                }
                fetchOptions.body = params.toString();
            } else {
                fetchOptions.headers['Content-Type'] = 'application/json';
                fetchOptions.body = JSON.stringify(data);
            }
        }

        const response = await fetch(pinova.root + url, fetchOptions);

        const contentType = response.headers.get('content-type');
        const responseData = contentType?.includes('application/json')
            ? await response.json()
            : await response.text();

        if (!response.ok) {
            throw new Error(responseData.message || 'خطا در پاسخ از سرور');
        }

        return responseData;

    } catch (err) {
        console.error('API error:', err.message);
        pinovaNotyf.error('در پردازش درخواست خطایی رخ داده است!');
        throw err;
    }
}

function pinovaGetVisiblePages(pagination) {

    let visiblePages = [];

    // Add first page if not already included in the range of current page
    if (pagination.currentPage > 3) {
        visiblePages.push(1);
        if (pagination.currentPage > 4) {
            visiblePages.push('...');
        }
    }

    // Add two pages before and after current page
    let start = Math.max(1, pagination.currentPage -2);
    let end = Math.min(pagination.totalPage,pagination.currentPage +2);


    for (let i=start; i<=end; i++) {
        visiblePages.push(i);

    }

    // Add last page if not already included in the range of current page
    if (pagination.totalPage > end ) {
        if (end < pagination.totalPage -2) {
            visiblePages.push('...');
        }
        visiblePages.push(pagination.totalPage);
    }


    return visiblePages;

}

function pinovaParseQueryString(url) {
    const obj = {};
    const queryString = url.includes('?') ? url.split('?')[1] : url;

    const params = new URLSearchParams(queryString);

    for (const [key, value] of params.entries()) {
        const arrayMatch = key.match(/^([^\[]+)\[\d*\]$/);

        if (arrayMatch) {
            const baseKey = arrayMatch[1];
            if (!obj[baseKey]) obj[baseKey] = [];
            obj[baseKey].push(value);
        } else {
            if (obj[key] !== undefined) {
                if (!Array.isArray(obj[key])) {
                    obj[key] = [obj[key]];
                }
                obj[key].push(value);
            } else {
                obj[key] = value;
            }
        }
    }

    return obj;
}

function pinovaBuildQueryString(obj) {
    const params = [];

    for (const key in obj) {
        const value = obj[key];

        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                params.push(`${encodeURIComponent(key)}[${index}]=${encodeURIComponent(item)}`);
            });
        } else if (value !== undefined && value !== null) {
            params.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
        }
    }

    return params.join('&');
}

function pinovaCleanNumericInput(value) {
    return value.replace(/[^0-9]/g, '');
}

function pinovaFormatDate(timestamp, format){
    const date = new persianDate(timestamp);
    return format ? date.format(format) : date.format();
}

function pinovaDateToTimestamp(newDate){
    const array = newDate.split('-').map(item => Number(item));
    const date = new persianDate(array);
    return date.unix() * 1000;
}

function pinovaCheckDateFormatIsValid(str) {
    const pattern = /^\d{4}-\d{2}-\d{2}$/;
    return pattern.test(str);
}

function pinovaConvertPersianNumberToEnglish(number) {
    const persianDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

    return number.replace(/[۰-۹]/g, d => persianDigits.indexOf(d));
}

function pinovaSetUrlQueryParams(namePage, filters){
    delete filters.per_page;

    if(namePage){
        filters.page = namePage;
    }

    if(filters.from_date){
        filters.from_date = pinovaConvertPersianNumberToEnglish(pinovaFormatDate(filters.from_date * 1000, 'YYYY-MM-DD'));
    }
    if(filters.to_date){
        filters.to_date =  pinovaConvertPersianNumberToEnglish(pinovaFormatDate(filters.to_date * 1000, 'YYYY-MM-DD'));
    }

    window.history.replaceState(null, null,  '?' + pinovaBuildQueryString(filters));
}

function pinovaGenerateFiltersObject(filters){
    const objFilters = {};

    for (const objKey in filters) {
        if(filters[objKey]){
            objFilters[objKey] = filters[objKey]
        }
    }

    if(objFilters.from_date){
        objFilters.from_date = objFilters.from_date / 1000;
    }
    if( objFilters.to_date){
        objFilters.to_date =  objFilters.to_date / 1000;
    }

    return objFilters;
}

function pinovaGoBack() {
    try {
        if (
            document.referrer &&
            new URL(document.referrer).origin === location.origin
        ) {
            history.back();
            return;
        }
    } catch (error) {
        console.log(error)
    }

    location.href = '/';
}

//validator
const persianTypeNames = {
    email: 'ایمیل',
    phone: 'شماره تلفن',
    number: 'عدد',
    text: 'متن'
};

function pinovaJoinTypesPersian(arr) {
    if (!arr || arr.length === 0) return '';
    if (arr.length === 1) return arr[0];
    if (arr.length === 2) return `${arr[0]} یا ${arr[1]}`;
    return `${arr.slice(0, -1).join('، ')} یا ${arr.slice(-1)}`;
}

function pinovaValidateField(field) {
    const {value = '', rules} = field;

    if (!rules || typeof rules.required === 'undefined') {
        return field;
    }

    const valTrim = String(value).trim();

    if (rules.required && valTrim === '') {
        return {...field, errorMsg: 'این فیلد نمی‌تواند خالی باشد'};
    }

    if (!rules.required && valTrim === '') {
        return {...field, errorMsg: ''};
    }

    if (rules.minLength && valTrim.length < rules.minLength) {
        return {...field, errorMsg: `حداقل طول باید ${rules.minLength} کاراکتر باشد`};
    }
    if (rules.maxLength && valTrim.length > rules.maxLength) {
        return {...field, errorMsg: `حداکثر طول باید ${rules.maxLength} کاراکتر باشد`};
    }

    const validators = {
        email: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
        phone: v => /^09\d{8,13}$/.test(v),
        number: v => /^-?\d+(\.\d+)?$/.test(v),
        text: v => true
    };

    if (rules.type) {
        const types = Array.isArray(rules.type) ? rules.type : [rules.type];
        const isValid = types.some(t => validators[t] ? validators[t](valTrim) : false);

        if (!isValid) {
            const customMap = rules.typeNames || {};
            const readable = types.map(t => customMap[t] || persianTypeNames[t] || t);
            const joined = pinovaJoinTypesPersian(readable);
            return {...field, errorMsg: `مقدار وارد شده باید ${joined} معتبر باشد`};
        }
    }

    return {...field, errorMsg: ''};
}

function pinovacCreateDatePicker(parentEl, minDate) {

    if(!window?.$){
        window.$ = window.jQuery;
    }

    let datePicker = null;

    datePicker = $($(parentEl).find(".date-picker")).persianDatepicker({
        initialValueType: 'persian',
        inline: true,
        altField: '.date-picker-alt',
        leapYearMode: 'astronomical',
        altFormat: 'L',
        initialValue: true,
        minDate,
        toolbox: {
            enabled: false
        },
        navigator: {
            scroll: {
                enabled: false
            }
        },
        calendar:{
            persian: {
                leapYearMode: 'astronomical'
            }
        }
    });

    return datePicker;
}

function pinovaCreateRangeDateFilter(parentEl, dateFromValue, dateToValue) {

    if(!window?.$){
        window.$ = window.jQuery;
    }

    let rangeDateFrom = null;
    let rangeDateTo = null;

    rangeDateFrom = $($(parentEl).find(".range-date-from")).persianDatepicker({
        initialValueType: 'persian',
        inline: true,
        altField: '.range-date-from-alt',
        leapYearMode: 'astronomical',
        altFormat: 'L',
        initialValue: true,
        maxDate: dateToValue,
        toolbox: {
            enabled: false
        },
        navigator: {
            scroll: {
                enabled: false
            }
        },
        calendar:{
            persian: {
                leapYearMode: 'astronomical'
            }
        },
        onSelect: (unix) => {
            if (rangeDateTo && rangeDateTo.options && rangeDateTo.options.minDate != unix) {
                let cachedValue = rangeDateTo.getState().selected.unixDate;
                rangeDateTo.options = {minDate: unix};
                rangeDateTo.setDate(cachedValue);
            }
        }
    });
    rangeDateFrom.setDate(dateFromValue)

    rangeDateTo = $($(parentEl).find(".range-date-to")).persianDatepicker({
        initialValueType: 'persian',
        inline: true,
        altField: '.range-date-to-alt',
        altFormat: 'L',
        minDate: dateFromValue,
        initialValue: true,
        toolbox: {
            enabled: false
        },
        navigator: {
            scroll: {
                enabled: false
            }
        },
        calendar:{
            persian: {
                leapYearMode: 'astronomical'
            }
        },
        onSelect: (unix) => {
            if (rangeDateFrom && rangeDateFrom.options && rangeDateFrom.options.maxDate != unix) {
                let cachedValue = rangeDateFrom.getState().selected.unixDate;
                rangeDateFrom.options = {maxDate: unix};
                rangeDateFrom.setDate(cachedValue);
            }
        }
    });
    rangeDateTo.setDate(dateToValue)

    return [rangeDateFrom, rangeDateTo];
}
