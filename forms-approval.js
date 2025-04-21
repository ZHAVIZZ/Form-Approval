jQuery(document).ready(function($) {
    $(document).on('wpformsAjaxSubmitCompleted', function(event, response, $form) {
        var formID = parseInt($form.data('formid'));
        console.log('Forms Approval: Submit completed for form ID ' + formID);
        if (!formsApprovalSettings.formIds.includes(formID)) {
            return;
        }

        setTimeout(function() {
            $form.find('.wpforms-submit').prop('disabled', false)
                .removeClass('wpforms-submit-ajax wpforms-ajax-submit wpforms-disabled');
            $form.find('.wpforms-submit-spinner').remove();
            $form.removeClass('wpforms-ajax-submitting wpforms-submitting');
            $form.find('.wpforms-field').val('').trigger('change');
            $form.find('.wpforms-error').remove();
            $form[0].reset();
            $form.data('submitting', false);

            if (response.data && response.data.message && !$form.find('.wpforms-confirmation').length) {
                $form.append('<div class="wpforms-confirmation">' + response.data.message + '</div>');
                setTimeout(function() {
                    $form.find('.wpforms-confirmation').fadeOut();
                }, 5000);
            }
        }, 500);

        if (response.data && response.data.redirect) {
            console.log('Forms Approval: Redirecting to ' + response.data.redirect);
            window.location.href = response.data.redirect;
        }
    });

    function sendHeartbeat() {
        $.ajax({
            url: formsApprovalSettings.ajaxUrl + '?nocache=' + Date.now(),
            type: 'POST',
            cache: false,
            data: {
                action: 'forms_approval_heartbeat',
                _ajax_nonce: formsApprovalSettings.nonce
            },
            success: function(response) {
                console.log('Forms Approval: Heartbeat response:', response);
            },
            error: function(xhr, status, error) {
                console.log('Forms Approval: Heartbeat error:', status, error, xhr.responseText);
            }
        });
    }

    sendHeartbeat();
    setInterval(sendHeartbeat, 10000);

    function checkRedirect() {
        $.ajax({
            url: formsApprovalSettings.ajaxUrl + '?nocache=' + Date.now(),
            type: 'POST',
            cache: false,
            data: {
                action: 'forms_approval_check_redirect',
                _ajax_nonce: formsApprovalSettings.nonce
            },
            success: function(response) {
                console.log('Forms Approval: Redirect check response:', response);
                if (response.success && response.data.redirect) {
                    console.log('Forms Approval: Redirecting to ' + response.data.redirect);
                    window.location.href = response.data.redirect;
                }
            },
            error: function(xhr, status, error) {
                console.log('Forms Approval: Redirect check error:', status, error, xhr.responseText);
            }
        });
    }

    checkRedirect();
    setInterval(checkRedirect, 1000); // Check every 1 second for faster redirects
});