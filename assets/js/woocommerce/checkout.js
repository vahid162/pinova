(function ($) {

    const openLoginModalClasses = '.pinova-open_login__modal, .showlogin';

    if ($('.showlogin').length) {
        $('form.woocommerce-form.woocommerce-form-login.login').remove();
    }

    function showLoginModal(e) {
        if (!$(openLoginModalClasses).length) {
            return
        }

        pinovaOpenModal($(openLoginModalClasses).attr('data-identifier'));
    }

    $(document.body).on('checkout_error', showLoginModal);
    $(document.body).on('click', openLoginModalClasses, showLoginModal);
}(jQuery));