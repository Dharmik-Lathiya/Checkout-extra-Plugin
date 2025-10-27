/**
 * Handles the AJAX functionality for the "Retrieve & Pay" feature on Site A.
 * Renders results in a paginated table.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		const $form = $('#gf-pay-later-lookup-form');
		const $submitButton = $('#gf-lookup-submit-button');
		const $resultsContainer = $('#gf-pay-later-results');
		const $loadingIndicator = $('#gf-pay-later-loading');
		const $identifierInput = $('#gf_lookup_identifier');

		// --- Main Form Submission Handler ---
		$form.on('submit', function (e) {
			e.preventDefault();
			fetchEntries(1); // Always fetch the first page on a new search
		});

		// --- Pagination Click Handler ---
		$resultsContainer.on('click', '.pagination-link', function (e) {
			e.preventDefault();
			const page = $(this).data('page');
			fetchEntries(page);
		});

		/**
		 * Fetches and renders entries for a given page number.
		 * @param {number} page The page number to fetch.
		 */
		function fetchEntries(page) {
			const identifier = $identifierInput.val().trim();
			if (identifier === '') {
				$identifierInput.focus();
				return;
			}

			$submitButton.prop('disabled', true).text('Searching...');
			$resultsContainer.hide().empty();
			$loadingIndicator.show();

			$.ajax({
				url: window.payLaterAjax.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'gf_proxy_find_entries',
					nonce: window.payLaterAjax.nonce,
					identifier: identifier,
					page: page
				},
				success: function (response) {
					if (response.success) {
						renderTable(response.data.entries, response.data.pagination_html);
					} else {
						renderError(response.data.message || 'An unknown error occurred.');
					}
				},
				error: function () {
					renderError('A network error occurred. Please try again.');
				},
				complete: function () {
					$loadingIndicator.hide();
					$resultsContainer.show();
					$submitButton.prop('disabled', false).text('Find My Application');
				}
			});
		}

		/**
		 * Renders the results table and pagination.
		 * @param {Array} entries Array of entry objects.
		 * @param {string} paginationHtml Pre-rendered pagination HTML.
		 */
		function renderTable(entries, paginationHtml) {
			if (!entries || entries.length === 0) {
				renderError('No unpaid applications were found for this email address. Please check for typos or contact support if you believe this is an error.');
				return;
			}

			let tableHtml = `
                <h3>Your Unpaid Applications</h3>
				<div class="gf-pay-later-table-wrapper">
                <table class="gf-pay-later-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Form Name</th>
                            <th>Applicant's Name</th>
                            <th>Date of Birth</th>
                            <th>Submission Time</th>
                            <th>Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>`;

			$.each(entries, function (index, entry) {
				tableHtml += `
                    <tr>
                        <td>${entry.number}</td>
                        <td>${entry.form_title}</td>
                        <td>${entry.name}</td>
                        <td>${entry.dob}</td>
                        <td>${entry.date_created}</td>
                        <td><strong>${entry.payment_amount}</strong></td>
                        <td><a href="${entry.payment_url}" class="button pay-now-button">Pay Now</a></td>
                    </tr>`;
			});

			tableHtml += `
                    </tbody>
                </table></div>
                ${paginationHtml || ''}
            `;

			$resultsContainer.html(tableHtml);
		}

		/**
		 * Renders an error message.
		 * @param {string} message The error message to display.
		 */
		function renderError(message) {
			const escapedMessage = $('<div />').text(message).html();
			$resultsContainer.html(`<div class="gf-pay-later-error"><p>${escapedMessage}</p></div>`);
		}
	});

})(jQuery);