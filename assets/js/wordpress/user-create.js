jQuery(function ($) {

    $(document).ready(function () {
        $('label[for="user_login"]').html('تلفن همراه <span class="description">(لازم)</span>');

        $('#user_login').attr({placeholder: 'مثلاً 09123456789'});

        $('#email').closest('tr.form-field')
            .removeClass('form-required')
            .find('label .description')
            .text('(اختیاری)');
    });

})