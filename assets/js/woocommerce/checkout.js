(function ($) {
    const explicitLoginSelector = '.pinova-open_login__modal, .showlogin';
    const accountConflictSelector = [
        '.woocommerce-error .pinova-open_login__modal',
        '.woocommerce-NoticeGroup-checkout .pinova-open_login__modal',
    ].join(', ');

    if ($('.showlogin').length) {
        $('form.woocommerce-form.woocommerce-form-login.login').remove();
    }

    function openLoginModal(trigger) {
        if (!trigger) {
            return;
        }

        pinovaOpenModal($(trigger).attr('data-identifier') || '', trigger);
    }

    function focusInvalidBillingPhone() {
        const phone = document.getElementById('billing_phone');
        const row = phone && phone.closest('.form-row');
        const isInvalid = row && (
            row.classList.contains('woocommerce-invalid')
            || row.classList.contains('woocommerce-invalid-required-field')
        );

        if (!phone || !isInvalid) {
            return;
        }

        phone.focus({ preventScroll: true });

        if (typeof phone.scrollIntoView === 'function') {
            phone.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function handleCheckoutError() {
        const accountConflict = document.querySelector(accountConflictSelector);

        if (accountConflict) {
            openLoginModal(accountConflict);
            return;
        }

        focusInvalidBillingPhone();
    }

    function handleExplicitLogin(event) {
        event.preventDefault();
        openLoginModal(event.currentTarget);
    }

    $(document.body).on('checkout_error', handleCheckoutError);
    $(document.body).on('click', explicitLoginSelector, handleExplicitLogin);
}(jQuery));
