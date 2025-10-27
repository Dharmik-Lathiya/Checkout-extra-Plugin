jQuery(document).ready(function ($) {
    'use strict';

    const $container = $('#gf-resend-notifications-container');
    if (!$container.length) {
        return;
    }

    const $actionsDiv = $container.find('.gf-resend-email-actions');
    const $selectAll = $container.find('#gf_resend_select_all');
    const $choices = $container.find('input[name="notifications_to_send[]"]');
    const $feedback = $container.find('#gf-resend-email-feedback');
    const $spinner = $container.find('#gf-resend-email-spinner');
    const originalButtonHtml = $actionsDiv.html(); // Store the initial state of the button

    // Enable the main button now that JS is loaded
    $container.find('#gf-resend-email-button').prop('disabled', false);

    // --- UI State Management ---
    function resetToActionState() {
        $actionsDiv.html(originalButtonHtml);
        $container.find('#gf-resend-email-button').prop('disabled', false);
        $feedback.hide();
    }

    // --- Event Delegation for Button Clicks ---
    $actionsDiv.on('click', 'button', function (e) {
        e.preventDefault();
        const buttonId = $(this).attr('id');

        switch (buttonId) {
            case 'gf-resend-email-button':
                handleInitialSendClick();
                break;
            case 'gf-resend-cancel-button':
                resetToActionState();
                break;
            case 'gf-resend-confirm-button':
                handleConfirmSendClick();
                break;
        }
    });

    // --- Checkbox Logic ---
    $selectAll.on('change', function () {
        $choices.prop('checked', $(this).prop('checked'));
    });

    $choices.on('change', function () {
        if (!$(this).prop('checked')) {
            $selectAll.prop('checked', false);
        }
    });

    // --- Handler Functions ---

    function handleInitialSendClick() {
        const selectedFeedIds = $choices.filter(':checked').map(function () {
            return $(this).val();
        }).get();

        if (selectedFeedIds.length === 0) {
            $feedback.css({'background-color': '#f7e7e7', 'border': '1px solid #800000'}).text('Please select at least one notification to resend.').show();
            return;
        }
        
        // Step 1: Hide "Send" and show "Confirm/Cancel"
        const confirmationHtml = '<button type="button" id="gf-resend-confirm-button" class="button button-primary-caution">Are you sure?</button> <button type="button" id="gf-resend-cancel-button" class="button">Cancel</button>';
        $feedback.hide();
        $actionsDiv.html(confirmationHtml);
    }

    function handleConfirmSendClick() {
        // Step 2 (Confirm): Hide "Confirm/Cancel" and show original button
        $actionsDiv.html(originalButtonHtml);
        const $resendButton = $actionsDiv.find('#gf-resend-email-button');

        // Step 3: Change text to "Sending..." and start process
        $resendButton.text('Sending...').prop('disabled', true);
        $spinner.show();
        $feedback.hide();

        const selectedFeedIds = $choices.filter(':checked').map(function () { return $(this).val(); }).get();
        const newEmail = $container.find('#gf-resend-email-address').val().trim();

        $.ajax({
            url: gf_resend_email_obj.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'gf_resend_notifications',
                nonce: gf_resend_email_obj.nonce,
                entry_id: gf_resend_email_obj.entry_id,
                email: newEmail,
                feed_ids: selectedFeedIds
            },
            success: function (response) {
                if (response.success) {
                    $feedback.css({'background-color': '#e7f7e7', 'border': '1px solid #008000'}).text(response.data.message).show();
                    $actionsDiv.empty(); // Clear the buttons
                    setTimeout(function () {
                        location.reload();
                    }, 2500);
                } else {
                    $feedback.css({'background-color': '#f7e7e7', 'border': '1px solid #800000'}).text('Error: ' + response.data.message).show();
                    resetToActionState();
                }
            },
            error: function (jqXHR) {
                let errorMsg = 'An unknown AJAX error occurred.';
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMsg = jqXHR.responseJSON.data.message;
                }
                $feedback.css({'background-color': '#f7e7e7', 'border': '1px solid #800000'}).text('Error: ' + errorMsg).show();
                resetToActionState();
            },
            complete: function () {
                $spinner.hide();
            }
        });
    }
});