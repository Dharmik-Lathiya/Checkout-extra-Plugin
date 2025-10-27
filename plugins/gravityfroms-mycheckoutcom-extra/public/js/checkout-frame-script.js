//add this script on docuent ready
document.addEventListener("DOMContentLoaded", function (event) {
	// Use jQuery for convenience, especially with AJAX
	var $ = jQuery;

	// Check if required objects are available
	if (typeof Frames === 'undefined' || typeof checkout_vars === 'undefined') {
		console.error('Checkout Frames or checkout_vars is not defined.');
		return;
	}

	var card_errors = {},
		error_fields = {};
	card_errors["card-number"] = "Please enter a valid card number";
	card_errors["expiry-date"] = "Please enter a valid expiry date";
	card_errors["cvv"] = "Please enter a valid cvv code";
	var payButton = document.getElementById("pay-button");
	var form = document.getElementById("payment-form");
	var checkoutErrorDiv = $('#checkout-error');
	const currencySelector = $('#currency-selector');
	const loaderOverlay = $('#checkout-loader-overlay');
	const totalAmountDisplay = $('.order-total-amount');
	const productOrderAmount = $('.order-product-amount')
	const paymentForm = $('#payment-form');
	let entryId = paymentForm.attr('data-entry-id') || '';
	let formId = paymentForm.attr('data-form-id') || '';

	
	Frames.init({
		publicKey: checkout_vars.publicKey,
		style: {
			base: {
				color: "#111111",
				fontFamily: "'Open Sans', sans-serif"
			},
			invalid: "#790000"
		}
	});

	Frames.addEventHandler(
		Frames.Events.CARD_VALIDATION_CHANGED,
		function (event) {
			console.log(event);
			payButton.disabled = !Frames.isCardValid();
		}
	);

	Frames.addEventHandler(
		Frames.Events.FRAME_VALIDATION_CHANGED,
		function (event) {
			console.log(event);
			if (event.isValid || event.isEmpty) {
				error_fields[event.element] = '';
			} else {
				error_fields[event.element] = card_errors[event.element];
			}
			console.log(error_fields);
		}
	);

	Frames.addEventHandler(
		Frames.Events.CARD_TOKENIZED,
		function (event) {
			jQuery('#checkout_payment_token').val(event.token);
			form.submit();
		}
	);

	// --- Your existing form submission handler ---
	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault(); // Always prevent default to handle logic here

			if (!Frames.isCardValid()) {
				// Show the first available error
				var firstError = Object.values(error_fields)[0];
				if (firstError) {
					checkoutErrorDiv.text(firstError).slideDown();
				}
				return; // Stop submission
			}

			// If card is valid but token is not yet generated, submit to Frames
			if ($('#checkout_payment_token').val() === '') {
				checkoutErrorDiv.text('').slideUp();
				Frames.submitCard();
			}
		});
	}



	
	currencySelector.on('change', function () {
		const newCurrency = $(this).val();

		// Show loader and disable form elements to prevent interaction
		loaderOverlay.show();
		if (payButton) payButton.disabled = true;
		currencySelector.prop('disabled', true);

		// Make the AJAX call to the server
		$.ajax({
			url: checkout_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'gf_checkout_com_update_currency',
				nonce: checkout_vars.nonce,
				form_id: formId,
				entry_id: entryId,
				currency: newCurrency,
			},
			success: function (response) {
				if (response.success) {
					// Update the displayed total amount on the page
					totalAmountDisplay.html(
						'<strong>' + response.data.new_total_text + '</strong>'
					);
					productOrderAmount.text(response.data.new_amount_formatted);
				} else {
					// On error, show an alert and optionally revert the selector
					alert(
						'Error updating currency: ' +
						(response.data.message || 'Unknown error.')
					);
				}
			},
			error: function () {
				alert(
					'A server error occurred while updating the currency. Please refresh the page and try again.'
				);
			},
			complete: function () {
				// Hide loader and re-enable form elements
				loaderOverlay.hide();
				// Re-set button state based on card validity, not just 'enabled'
				if (payButton) payButton.disabled = !Frames.isCardValid();
				currencySelector.prop('disabled', false);
			},
		});
	});

	// --- 4. GOOGLE PAY LOGIC ---

	let paymentsClient;

	function getGPayPaymentDataRequest() {
		const totalText = totalAmountDisplay.text();
		const currencyCode = currencySelector.val() || 'USD';
		const amount =
			totalText.match(/[\d,.]+/)?.[0].replace(/,/g, '') || '0.00';
		return {
			apiVersion: 2,
			apiVersionMinor: 0,
			allowedPaymentMethods: [
				{
					type: 'CARD',
					parameters: {
						allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
						allowedCardNetworks: [
							'AMEX',
							'DISCOVER',
							'MASTERCARD',
							'VISA',
						],
					},
					tokenizationSpecification: {
						type: 'PAYMENT_GATEWAY',
						parameters: {
							gateway: 'checkoutltd',
							gatewayMerchantId: checkout_vars.publicKey,
						},
					},
				},
			],
			merchantInfo: {
				merchantId: checkout_vars.googlePayMerchantId,
			},
			transactionInfo: {
				totalPriceStatus: 'FINAL',
				totalPrice: amount,
				currencyCode: currencyCode,
			},
		};
	}

	function onGPayClicked() {
		const paymentDataRequest = getGPayPaymentDataRequest();
		paymentsClient
			.loadPaymentData(paymentDataRequest)
			.then(processGPay)
			.catch((err) => console.error('GPay Error:', err));
	}

	function processGPay(paymentData) {
		const gpayToken =
			paymentData.paymentMethodData.tokenizationData.token;
		$('#checkout_payment_source').val('gpay');
		$('#checkout_payment_token').val(gpayToken);
		paymentForm[0].submit(); // Also use the native submit here for consistency.
	}

	function initializeGPay() {
		if (
			!checkout_vars.googlePayMerchantId ||
			typeof google === 'undefined'
		) {
			return;
		}
		paymentsClient = new google.payments.api.PaymentsClient({
			environment: checkout_vars.googlePayEnvironment,
		});
		const isReadyToPayRequest = {
			apiVersion: 2,
			apiVersionMinor: 0,
			allowedPaymentMethods:
				getGPayPaymentDataRequest().allowedPaymentMethods,
		};
		paymentsClient
			.isReadyToPay(isReadyToPayRequest)
			.then(function (response) {
				if (response.result) {
					const button = paymentsClient.createButton({
						onClick: onGPayClicked,
					});
					gpayButtonContainer.appendChild(button);
					$('.cko-tabs li[data-tab="gpay"]').show();
				}
			})
			.catch((err) => console.error('isReadyToPay Error:', err));
	}

	$(document).on('gpay-sdk-loaded', initializeGPay);
	$('.cko-tabs li[data-tab="gpay"]').hide();
});
