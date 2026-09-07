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

        fetch(pinova.root + 'pinova/admin/gateway/get-options', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': pinova.nonce
            },
            body: JSON.stringify({
                gateway: selected_gateway
            })
        })
            .then(async (response) => {
                const data = await response.json().catch(() => null);

                if (!response.ok) {
                    const message = data?.message || response.statusText || 'خطای نامشخصی رخ داده است.';
                    throw new Error(message);
                }

                return data;
            })
            .then((response) => {

                if (!response || response.success === false) {
                    console.error(response?.message || 'خطای اتصال به API رخ داده است.');
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
            .catch((error) => {
                console.error('خطای اتصال به API:', error.message);
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

        button.on('click', function () {
            const identifier = test_mobile_field.val();

            if (!identifier) {
                message_box.text('لطفا شماره تلفن را جهت تست پیامک کد تایید، وارد نمایید.').css('color', 'red');
                return;
            }

            button.prop('disabled', true).text('در حال ارسال...');
            message_box.text('').css('color', 'inherit');

            fetch(pinova.root + 'pinova/admin/test/sms', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': pinova.nonce
                },
                body: JSON.stringify({
                    identifier: identifier
                })
            })
                .then(async (response) => {
                    let data = null;

                    try {
                        data = await response.json();
                    } catch (e) {
                        data = null;
                    }

                    button.prop('disabled', false).text('ارسال');

                    if (!response.ok) {
                        const errorMessage =
                            data?.message ||
                            'خطای اتصال به وبسرویس.';

                        message_box
                            .css('color', 'red')
                            .text(errorMessage);

                        return;
                    }

                    if (data?.success) {
                        message_box
                            .css('color', 'green')
                            .text(data.message);
                    } else {
                        message_box
                            .css('color', 'red')
                            .text(data?.message || 'خطایی رخ داده است.');
                    }
                })
                .catch((error) => {
                    button.prop('disabled', false).text('ارسال');

                    message_box
                        .css('color', 'red')
                        .text('خطای اتصال به وبسرویس.');

                    console.error(error);
                });
        });
    }

    $(document.body).ready(function () {
        control_sms_gateway_selection();
        $('[id="pinova_sms[gateway]"]').on('change', control_sms_gateway_selection);

        insert_test_sms_button();
    })

}(jQuery))