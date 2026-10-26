jQuery(function ($) {
    'use strict';
    var $page = $('.invatrbl-admin');
    var $form = $('#invatrbl-settings-form');
    var $key = $('#invatrbl-api-key');
    $page.addClass('invatrbl-has-js');
    $page.find('fieldset').each(function (index) {
        var $fieldset = $(this);
        var $controls = $fieldset.find('input, select, textarea');
        var $description = $fieldset.find('.description').first();
        if ($description.length) {
            $description.attr('id', 'invatrbl-help-' + index);
            $controls.attr('aria-describedby', 'invatrbl-help-' + index);
        }
        if ($controls.length === 1 && !$controls.closest('label').length) {
            $controls.attr('aria-label', $fieldset.find('legend').text());
        }
    });
    function updateCustomOptions() {
        var custom = $form.find('input[name="' + invatrblVars.optionName + '[blocking_mode]"]:checked').val() === 'custom';
        $('#invatrbl-field-custom_block_options').prop('hidden', !custom);
    }
    updateCustomOptions();
    $form.on('input change', ':input', function () {
        $('#invatrbl-save-status').text(invatrblVars.unsaved);
        updateCustomOptions();
    });
    $('#invatrbl-show-key').on('click', function () {
        var reveal = $key.attr('type') === 'password';
        $key.attr('type', reveal ? 'text' : 'password');
        $(this).attr('aria-pressed', String(reveal)).text(reveal ? invatrblVars.hide : invatrblVars.show);
    });
    $('#invatrbl-test-api').on('click', function () {
        var $button = $(this);
        var $result = $('#invatrbl-test-result');
        var label = $button.text();
        $button.prop('disabled', true).text(invatrblVars.testing);
        $result.attr('aria-busy', 'true').text(invatrblVars.testing);
        $.ajax({
            url: invatrblVars.ajaxUrl, method: 'POST', timeout: 15000, dataType: 'html',
            data: { action: 'invatrbl_test_api', _ajax_nonce: invatrblVars.nonce }
        }).done(function (response) {
            if (!response || response.trim() === '0' || response.trim() === '-1') {
                $result.text(invatrblVars.testError);
                return;
            }
            // The authenticated endpoint returns escaped notice markup.
            $result.html(response);
        }).fail(function () {
            $result.text(invatrblVars.testError);
        }).always(function () {
            $result.attr('aria-busy', 'false');
            $button.prop('disabled', false).text(label);
        });
    });
    $('#invatrbl-whitelist-my-ip').on('click', function () {
        var $textarea = $form.find('textarea[name="' + invatrblVars.optionName + '[whitelisted_ips]"]');
        var adminIP = invatrblVars.adminIP;
        var ips = $textarea.val().split(/\r?\n/).map(function (ip) { return ip.trim(); }).filter(Boolean);
        var $result = $('#invatrbl-whitelist-result');
        if (!adminIP) {
            $result.text(invatrblVars.missingIP);
        } else if (ips.indexOf(adminIP) !== -1) {
            $result.text(invatrblVars.exists);
        } else {
            ips.push(adminIP);
            $textarea.val(ips.join('\n')).trigger('change');
            $result.text(invatrblVars.added);
        }
    });
});
