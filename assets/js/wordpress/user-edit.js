jQuery(function ($) {

    $(document).ready(function () {
        // Move Mobile field
        let user_mobile_wrap_el = $('.user-mobile-wrap');
        let user_role_wrap_el = $('.user-role-wrap');
        let user_first_name_wrap_el = $('.user-first-name-wrap');

        if (user_mobile_wrap_el.length && user_first_name_wrap_el.length) {
            user_mobile_wrap_el.insertBefore(user_first_name_wrap_el);
        }

        if (user_mobile_wrap_el.length && user_role_wrap_el.length) {
            user_mobile_wrap_el.insertBefore(user_role_wrap_el);

            return;
        }

        // Remove email requirement
        $('.user-email-wrap span.description').hide();
    });

});