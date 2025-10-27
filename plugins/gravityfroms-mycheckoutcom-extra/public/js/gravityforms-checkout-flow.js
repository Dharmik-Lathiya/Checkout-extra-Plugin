jQuery(document).ready(function ($) {
	const {
		publicKey,
		ajax_url,
		create_nonce,
		entry_id,
		form_id,
		error_message,
	} = checkout_flow_vars;
	const flowContainer = document.getElementById("cko-payment-flow-container");
	const errorContainer = document.getElementById("cko-flow-errors");
	const loaderOverlay = $("#checkout-loader-overlay");

	if (!flowContainer || !publicKey) {
		console.error("Checkout Flow container or Public Key is missing.");
		return;
	}

	async function initializeFlow() {
		loaderOverlay.show();
		errorContainer.style.display = "none";

		if (flowContainer) {
			flowContainer.innerHTML = '';
		}

		try {
			const sessionResponse = await $.ajax({
				type: "POST",
				url: ajax_url,
				data: {
					action: "gf_checkout_com_create_session",
					nonce: create_nonce,
					entry_id: entry_id,
					form_id: form_id,
				},
			});

			// --- FINAL FIX FOR JAVASCRIPT --- //
			// 1. The success condition from PHP is the presence of the `data` property and the session ID inside it.
			if (!sessionResponse.success || !sessionResponse.data.id) {
				throw new Error('Failed to create payment session. Invalid response from server.');
			}

			// 2. We now have the complete object from the server, which is what the library needs.
			const paymentSessionObject = sessionResponse.data;

			const ckoController = await CheckoutWebComponents({
				publicKey: publicKey,
				paymentSession: paymentSessionObject, // Pass the entire object here
				environment: checkout_flow_vars.environment
			});

			const flowComponent = ckoController.create('flow', {
				onPaymentCompleted: function (component, result) {
					$("#cko_session_id").val(result.id);
					$(`form#gform_${form_id}`).submit();
				},
				onError: function (component, error) {
					console.log(component)
					errorContainer.innerText = "Payment was declined. Please check your details and try again.";
					errorContainer.style.display = "block";
					console.error("Payment Declined:", error);
				},
				onReady: function () {
					loaderOverlay.hide();
				}
			});

			flowComponent.mount('#cko-payment-flow-container');

		} catch (err) {
			loaderOverlay.hide();
			errorContainer.innerText = error_message;
			errorContainer.style.display = "block";
			console.error("Initialization Error:", err);
		}
	}

	// Currency Switcher Logic (remains the same)
	const currencySelector = $("#currency-selector");
	if (currencySelector.length) {
		currencySelector.on("change", function () {
			loaderOverlay.show();
			const newCurrency = $(this).val();
			$.ajax({
				type: "POST",
				url: checkout_flow_vars.ajax_url,
				data: {
					action: "gf_checkout_com_update_currency",
					nonce: checkout_flow_vars.update_nonce,
					entry_id: entry_id,
					form_id: form_id,
					currency: newCurrency,
				},
				success: function (response) {
					if (response.success) {
						$(".order-total-amount").html(`<strong>${response.data.new_total_text}</strong>`);
						initializeFlow();
					} else {
						alert("Could not update currency.");
						loaderOverlay.hide();
					}
				},
				error: function () {
					alert("Error updating currency.");
					loaderOverlay.hide();
				},
			});
		});
	}

	// Initial load
	initializeFlow();
});