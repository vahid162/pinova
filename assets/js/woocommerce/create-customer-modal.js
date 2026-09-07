// define Alpine
import pinovaAlpine from './../alpine.min.js';

pinovaAlpine.prefix("pinova-");
pinovaAlpine.data("pinovaCreateCustomer", () => ({

    modalIsOpen: false,
    pageLoaderIsActive: false,
    orderId: 0,

    forms: {
        createCustomer: {
            inputs: {
                first_name: {
                    value: '',
                    rules: {required: true},
                    errorMsg: ''
                },
                last_name: {
                    value: '',
                    rules: {required: true},
                    errorMsg: ''
                },
                mobile: {
                    value: '',
                    rules: {required: true, type: ['number']},
                    errorMsg: ''
                },
                email: {
                    value: '',
                    rules: {required: false},
                    errorMsg: ''
                }
            }
        }
    },

    openModal(identifier) {
        this.resetForm();

        const postInput = document.querySelector('#post_ID');
        if (postInput) {
            this.orderId = postInput.value;
        }

        // Pre-fill modal inputs using current WooCommerce order billing inputs
        const getFieldValue = (selector) => {
            const el = document.querySelector(selector);
            return el ? el.value.trim() : '';
        };

        const billingFirstName = getFieldValue('#_billing_first_name');
        const billingLastName = getFieldValue('#_billing_last_name');
        const billingPhone = getFieldValue('#_billing_phone');
        const billingEmail = getFieldValue('#_billing_email');

        if (billingFirstName) this.forms.createCustomer.inputs.first_name.value = billingFirstName;
        if (billingLastName) this.forms.createCustomer.inputs.last_name.value = billingLastName;
        if (billingPhone) this.forms.createCustomer.inputs.mobile.value = billingPhone;
        if (billingEmail) this.forms.createCustomer.inputs.email.value = billingEmail;

        this.modalIsOpen = true;
    },

    resetForm() {
        for (const key in this.forms.createCustomer.inputs) {
            this.forms.createCustomer.inputs[key].value = '';
            this.forms.createCustomer.inputs[key].errorMsg = '';
        }
    },

    async submit() {

        if (this.pageLoaderIsActive) {
            return;
        }

        for (const key in this.forms.createCustomer.inputs) {
            this.forms.createCustomer.inputs[key].errorMsg = '';
        }

        let hasError = false;

        for (const key in this.forms.createCustomer.inputs) {

            const validate = pinovaValidateField(this.forms.createCustomer.inputs[key]);

            if (validate.errorMsg) {
                hasError = true;
            }

            this.forms.createCustomer.inputs[key] = validate;
        }

        if (hasError) {
            return;
        }

        try {

            this.pageLoaderIsActive = true;

            // Capture current guest information in order data
            const orderData = {};
            document.querySelector('#order_data')?.querySelectorAll('[name^="_billing_"], [name^="_shipping_"]').forEach(input => {
                if (input.value) {
                    orderData[input.name] = input.value;
                }
            });

            const data = {
                first_name: this.forms.createCustomer.inputs.first_name.value,
                last_name: this.forms.createCustomer.inputs.last_name.value,
                mobile: this.forms.createCustomer.inputs.mobile.value,
                email: this.forms.createCustomer.inputs.email.value,
                order_id: this.orderId,
                order_data: orderData
            };

            const result = await pinovaApiRequest('pinova/woocommerce/customer/create', {
                method: 'POST',
                data
            });

            if (result.success) {

                pinovaNotyf.success(result.message ? result.message : 'مشتری با موفقیت ایجاد شد.')

                this.modalIsOpen = false;

                const wcCustomerSelect = jQuery("#customer_user");

                if (wcCustomerSelect.length) {

                    const fullName = `${data.first_name} ${data.last_name} (${data.mobile})`;
                    let option = new Option(fullName, result.data.user_id, true, true);

                    wcCustomerSelect.append(option);
                    wcCustomerSelect.val(result.data.user_id);
                    wcCustomerSelect.trigger('change');

                } else {
                    location.reload();
                }

            } else {

                pinovaNotyf.error(result.message ? result.message : 'در ایجاد حساب کاربری مشتری، خطایی رخ داده است.');

                if (result.message) {
                    if (result.message.includes('تلفن همراه') || result.message.includes('mobile')) {
                        this.forms.createCustomer.inputs.mobile.errorMsg = result.message;
                    } else if (result.message.includes('ایمیل') || result.message.includes('email')) {
                        this.forms.createCustomer.inputs.email.errorMsg = result.message;
                    }
                }
            }

        } catch (error) {

            pinovaNotyf.error('در ایجاد حساب کاربری مشتری، خطایی رخ داده است.');
            console.error('PIONVA: Error creating customer : ', error);

            if (error.message) {
                if (error.message.includes('تلفن همراه')) {
                    this.forms.createCustomer.inputs.mobile.errorMsg = error.message;
                } else if (error.message.includes('ایمیل')) {
                    this.forms.createCustomer.inputs.email.errorMsg = error.message;
                }
            }

        }

        this.pageLoaderIsActive = false;

    }

}));

pinovaAlpine.start();

// global function for open create customer modal
export function pinovaOpenCreateCustomerModal(identifier) {
    const el = document.querySelector('#pinovaCreateCustomerModal');
    const pageComponent = pinovaAlpine.$data(el);
    pageComponent.openModal(identifier)
}

window.pinovaOpenCreateCustomerModal = pinovaOpenCreateCustomerModal;