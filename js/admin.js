/**
 * Invalid Traffic Blocker Admin JavaScript
 */
jQuery(document).ready(function ($) {

    // Test API connectivity
    $('#invatrbl-test-api').on('click', function (e) {
        e.preventDefault();

        var $button = $(this);
        var $result = $('#invatrbl-test-result');

        // Show loading state
        $button.prop('disabled', true).addClass('loading');
        $button.html('<span class="dashicons dashicons-update"></span> Testing...');

        $result.removeClass('success error').addClass('loading').show()
            .html('<p>Testing API connectivity...</p>');

        // AJAX request
        $.ajax({
            url: invatrblVars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'invatrbl_test_api',
                _ajax_nonce: invatrblVars.nonce
            },
            success: function (response) {
                $result.removeClass('loading').addClass('success').html(response);
            },
            error: function (xhr, status, error) {
                $result.removeClass('loading').addClass('error')
                    .html('<p><strong>Error:</strong> Failed to connect to API. Please check your settings.</p>');
            },
            complete: function () {
                $button.prop('disabled', false).removeClass('loading');
                $button.html('<span class="dashicons dashicons-networking"></span> Test API Connection');
            }
        });
    });

    // Whitelist current IP
    $('#invatrbl-whitelist-my-ip').on('click', function (e) {
        e.preventDefault();

        var $button = $(this);
        var $result = $('#invatrbl-test-result');
        var adminIP = invatrblVars.adminIP;

        if (!adminIP || adminIP === '0.0.0.0') {
            $result.removeClass('loading success').addClass('error').show()
                .html('<p><strong>Error:</strong> Could not determine your IP address.</p>');
            return;
        }

        // Get current whitelist value
        var $whitelistField = $('textarea[name="' + invatrblVars.optionName + '[whitelisted_ips]"]');
        var currentValue = $whitelistField.val().trim();

        // Check if IP is already whitelisted
        var ipList = currentValue ? currentValue.split('\n') : [];
        var ipExists = ipList.some(function (ip) {
            return ip.trim() === adminIP;
        });

        if (ipExists) {
            $result.removeClass('loading error').addClass('success').show()
                .html('<p><strong>Info:</strong> Your IP (' + adminIP + ') is already whitelisted.</p>');
            return;
        }

        // Add IP to whitelist
        ipList.push(adminIP);
        $whitelistField.val(ipList.join('\n'));

        // Show success message
        $result.removeClass('loading error').addClass('success').show()
            .html('<p><strong>Success:</strong> Your IP (' + adminIP + ') has been added to the whitelist. Don\'t forget to save your settings!</p>');

        // Highlight the whitelist field briefly
        $whitelistField.css('border-color', '#46b450').animate({
            'border-color': '#ddd'
        }, 2000);
    });

    // License key validation
    var $licenseField = $('input[name="' + invatrblVars.optionName + '[license_key]"]');
    var licenseTimeout;

    $licenseField.on('input', function () {
        var $field = $(this);
        var $status = $('.license-status');
        var licenseKey = $field.val().trim();

        // Clear previous timeout
        clearTimeout(licenseTimeout);

        // Remove existing status
        $status.removeClass('valid invalid').text('');

        if (licenseKey.length < 5) {
            return;
        }

        // Debounce validation
        licenseTimeout = setTimeout(function () {
            validateLicense(licenseKey, $status);
        }, 500);
    });

    function validateLicense(licenseKey, $status) {
        // Simple client-side validation (server-side validation is still required)
        if (licenseKey.indexOf('PRO-') === 0 && licenseKey.length > 10) {
            $status.addClass('valid').text('✓ Valid Format');
        } else {
            $status.addClass('invalid').text('✗ Invalid Format');
        }
    }

    // Blocking mode radio-like behavior for checkboxes
    var $blockingModes = $('input[name$="[safe_mode]"], input[name$="[strict_mode]"], input[name$="[custom_mode]"]');

    $blockingModes.on('change', function () {
        if ($(this).is(':checked')) {
            // Uncheck other modes
            $blockingModes.not(this).prop('checked', false);

            // Show/hide custom options
            toggleCustomOptions();
        }
    });

    function toggleCustomOptions() {
        var $customMode = $('input[name$="[custom_mode]"]');
        var $customOptions = $customMode.closest('tr').next('tr');

        if ($customMode.is(':checked')) {
            $customOptions.show();
        } else {
            $customOptions.hide();
        }
    }

    // Initialize custom options visibility
    toggleCustomOptions();

    // Provider field enhancement
    var $providerField = $('select[name$="[provider]"]');

    $providerField.on('change', function () {
        var selectedProvider = $(this).val();
        updateAPIKeyLabel(selectedProvider);
        updateAPIKeyPlaceholder(selectedProvider);
        updateAPIKeyDescription(selectedProvider);
        updateBlockingModes(selectedProvider);
    });

    function updateAPIKeyLabel(provider) {
        var $apiKeyRow = $('input[name$="[api_key]"]').closest('tr');
        var $label = $apiKeyRow.find('th label');

        var labels = {
            'iphub': 'IPHub API Key',
            'ipqualityscore': 'IPQualityScore API Key',
            'ipapi': 'IP-API Key (Optional)',
            'proxycheck': 'ProxyCheck.io API Key'
        };

        if (labels[provider]) {
            $label.text(labels[provider]);
        }
    }

    function updateAPIKeyPlaceholder(provider) {
        var $apiKeyField = $('input[name$="[api_key]"]');

        var placeholders = {
            'iphub': 'Enter your IPHub API key',
            'ipqualityscore': 'Enter your IPQualityScore API key',
            'ipapi': 'Leave empty for free tier (1000 requests/month)',
            'proxycheck': 'Enter your ProxyCheck.io API key'
        };

        if (placeholders[provider]) {
            $apiKeyField.attr('placeholder', placeholders[provider]);
        }
    }

    function updateAPIKeyDescription(provider) {
        var $apiKeyRow = $('input[name$="[api_key]"]').closest('tr');
        var $description = $apiKeyRow.find('.description');

        var descriptions = {
            'iphub': 'Get your API key from <a href="https://iphub.info/register" target="_blank">IPHub.info</a>',
            'ipqualityscore': 'Get your API key from <a href="https://www.ipqualityscore.com/create-account" target="_blank">IPQualityScore</a>',
            'ipapi': 'IP-API offers 1000 free requests per month. <a href="http://ip-api.com/docs/api:json" target="_blank">Learn more</a>',
            'proxycheck': 'Get your API key from <a href="https://proxycheck.io/register" target="_blank">ProxyCheck.io</a>'
        };

        if (descriptions[provider]) {
            $description.html(descriptions[provider]);
        }
    }

    function updateBlockingModes(provider) {
        var $blockingRow = $('input[name$="[safe_mode]"]').closest('tr');
        var $label = $blockingRow.find('th label');

        if (provider === 'iphub') {
            $label.text('Blocking Options (Select one)');
        } else {
            $label.text('Blocking Options');
        }

        // Trigger a refresh of the blocking modes section if it exists
        if (typeof window.refreshBlockingModes === 'function') {
            window.refreshBlockingModes(provider);
        }
    }

    // Initialize provider-specific content
    updateAPIKeyLabel($providerField.val());
    updateAPIKeyPlaceholder($providerField.val());
    updateAPIKeyDescription($providerField.val());
    updateBlockingModes($providerField.val());

    // Form validation
    $('form.invatrbl-settings-form').on('submit', function (e) {
        var $form = $(this);
        var isValid = true;
        var errors = [];
        var selectedProvider = $('select[name$="[provider]"]').val();

        // Validate API key (only required for certain providers)
        var $apiKey = $('input[name$="[api_key]"]');
        var apiKeyRequired = ['iphub', 'ipqualityscore', 'proxycheck'].includes(selectedProvider);

        if (apiKeyRequired && $apiKey.val().trim() === '') {
            errors.push('API Key is required for ' + selectedProvider);
            $apiKey.css('border-color', '#dc3232');
            isValid = false;
        } else {
            $apiKey.css('border-color', '#ddd');
        }

        // Validate blocking mode selection
        var $modes = $('input[name$="[safe_mode]"], input[name$="[strict_mode]"], input[name$="[custom_mode]"]');
        var modeSelected = $modes.is(':checked');

        if (!modeSelected) {
            errors.push('Please select a blocking mode');
            isValid = false;
        }

        // Show errors if any
        if (!isValid) {
            e.preventDefault();

            var errorHtml = '<div class="notice notice-error"><p><strong>Please fix the following errors:</strong></p><ul>';
            errors.forEach(function (error) {
                errorHtml += '<li>' + error + '</li>';
            });
            errorHtml += '</ul></div>';

            $('.invatrbl-settings-form').prepend(errorHtml);

            // Scroll to top
            $('html, body').animate({
                scrollTop: $('.invatrbl-settings-form').offset().top - 50
            }, 500);
        }
    });

    // Remove validation errors on input
    $('input, select, textarea').on('input change', function () {
        $(this).css('border-color', '#ddd');
        $('.notice-error').fadeOut();
    });

    // Premium feature hints
    $('.premium-notice').on('click', function () {
        if (confirm('This is a premium feature. Would you like to learn more about upgrading?')) {
            window.open('https://yourdomain.com/premium', '_blank');
        }
    });

    // Smooth scrolling for internal links
    $('a[href^="#"]').on('click', function (e) {
        e.preventDefault();

        var target = $(this.getAttribute('href'));
        if (target.length) {
            $('html, body').animate({
                scrollTop: target.offset().top - 50
            }, 500);
        }
    });

    // Simple toast notification function
    function showToast(message, type = 'success') {
        var $toast = $('<div class="invatrbl-toast ' + type + '">' + message + '</div>');

        $toast.css({
            position: 'fixed',
            top: '20px',
            right: '20px',
            background: type === 'success' ? '#46b450' : '#dc3232',
            color: '#fff',
            padding: '12px 20px',
            borderRadius: '4px',
            zIndex: 9999,
            opacity: 0
        });

        $('body').append($toast);

        $toast.animate({ opacity: 1 }, 300);

        setTimeout(function () {
            $toast.animate({ opacity: 0 }, 300, function () {
                $toast.remove();
            });
        }, 3000);
    }

    // Keyboard shortcuts
    $(document).on('keydown', function (e) {
        // Ctrl/Cmd + S to save settings
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && $('.invatrbl-settings-form').length > 0) {
            e.preventDefault();
            $('.invatrbl-settings-form').submit();
        }

        // Escape to close any open modals or notifications
        if (e.key === 'Escape') {
            $('.notice-error').fadeOut();
            $('.invatrbl-toast').remove();
        }
    });

    // Print current configuration (for debugging)
    if (window.console && console.log) {
        console.log('Invalid Traffic Blocker Admin loaded');
        console.log('Current IP:', invatrblVars.adminIP);
    }
});

// Utility functions available globally
window.InvatrblAdmin = {
    showNotice: function (message, type = 'info') {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.invatrbl-tab-content').prepend($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function () {
            $notice.fadeOut();
        }, 5000);
    },

    getCurrentTab: function () {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('tab') || 'settings';
    },

    refreshStats: function () {
        if ($('.invatrbl-analytics-container').length > 0) {
            location.reload();
        }
    }
};
