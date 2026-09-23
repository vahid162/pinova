(function ($) {

    function control_sms_gateway_selection() {
        let dynamic_row_class = 'pinova-gateway-dynamic-row';
        let gateway_select = $('[id="pinova_sms[gateway]"]');
        let selected_gateway = gateway_select.val();

        if (!selected_gateway) {
            return
        }

        $('tr.' + dynamic_row_class).remove();
        let gateway_row = gateway_select.closest('tr');

        if (!gateway_row.length) {
            return
        }

        pinovaApiRequest('pinova/admin/gateway/get-options', {
            method: 'POST',
            data: {
                gateway: selected_gateway
            }
        })
            .then((response) => {

                if (!response || response.success === false) {
                    pinovaNotyf.error(response?.message || 'خطای اتصال به API رخ داده است.');
                    return;
                }

                if (!Array.isArray(response.data)) {
                    return;
                }

                let last_row = gateway_row;

                response.data.forEach((row_html) => {
                    let row = $(row_html).addClass(dynamic_row_class);
                    row.insertAfter(last_row);
                    last_row = row;
                });

            })
            .catch(() => {
                // The shared client already reports a safe error and server reference.
            });
    }

    function insert_test_sms_button() {
        const test_mobile_field = $('[id="pinova_sms[test_mobile]"]');

        if (!test_mobile_field.length || $('#pinova-test-sms-btn').length) {
            return;
        }

        const button = $('<button/>', {
            id: 'pinova-test-sms-btn',
            type: 'button',
            class: 'button button-secondary',
            text: 'ارسال'
        });

        const message_box = $('<div/>', {
            id: 'pinova-test-sms-result',
            css: {marginTop: '10px'}
        });

        test_mobile_field.after(button);
        const description = test_mobile_field.closest('td').find('p.description');
        description.after(message_box);

        button.on('click', async function () {
            const identifier = test_mobile_field.val();

            if (!identifier) {
                message_box.text('لطفا شماره تلفن را جهت تست پیامک کد تایید، وارد نمایید.').css('color', 'red');
                return;
            }

            button.prop('disabled', true).text('در حال ارسال...');
            message_box.text('').css('color', 'inherit');

            try {
                const result = await pinovaApiRequest('pinova/admin/test/sms', {
                    method: 'POST',
                    notifyOnError: false,
                    data: { identifier }
                });

                message_box
                    .css('color', result.success ? 'green' : 'red')
                    .text(result.message || 'خطایی رخ داده است.');
            } catch (error) {
                message_box
                    .css('color', 'red')
                    .text(error?.pinovaMessage || 'خطای اتصال به وبسرویس.');
            } finally {
                button.prop('disabled', false).text('ارسال');
            }
        });
    }

    $(document.body).ready(function () {
        control_sms_gateway_selection();
        $('[id="pinova_sms[gateway]"]').on('change', control_sms_gateway_selection);

        insert_test_sms_button();
    })

}(jQuery))
