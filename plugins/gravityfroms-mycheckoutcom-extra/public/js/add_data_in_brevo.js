let hasSend = false;
let isGenuineSubmission = false // flag for stop exit intent logic after genuine submission
let lastSentData = null; // Store the last sent data for comparison
let currentFormId = null;

jQuery(document).ready(function ($) {
	const notGovTooltip = document.getElementById('not-gov-site-tooltip');

	if (notGovTooltip && window.location.href.includes('/?gf_checkout_com_return=')) {
		notGovTooltip.style.setProperty('display', 'block', 'important');
	}

	// Run after Gravity Form is fully loaded/rendered
	$(document).on('gform_post_render', function (event, formId, currentPage) {
		currentPage = parseInt(currentPage);
		formId = parseInt(formId)
		$('#order-summary-main-container').hide();
		wrapFieldsInContainer('form-passport-card-fields', 'form-passport-card-container', 'U.S. Passport Card');
		wrapFieldsInContainer('form-passport-book-fields', 'form-passport-book-container', 'U.S. Passport Book');

		observeContainer('.form-passport-card-container', '.form-passport-card-fields');
		observeContainer('.form-passport-book-container', '.form-passport-book-fields');

		/* *Logic for making total changble after hidding product field */
		let productField = $('.product-base-price-custom').find('.ginput_product_price');
		gform.addFilter('gform_is_hidden', function (isHidden, element) {
			if (productField.is(element)) {
				return false; // keep visible
			}
			return isHidden;
		});

		// Only run for specific forms, e.g., form IDs 1, 4, 5, 6
		const validFormIds = [1, 4, 5, 6, 10];
		if (!validFormIds.includes(formId)) return;
		currentFormId = formId;
		manageSpinner(formId, currentPage);
		if (currentPage === 3) {
			let data = checkAndCollectData();
			// check if data anbd also data have email not empty
			if (data.email && data.email !== '') {
				// Send data to Brevo
				sendDataToBrevo(data);
				// Mark as genuine submission
				isGenuineSubmission = true;
				hasSend = true;
			}
		}

		// For Adding You are Child popup.
		if (formId === 5 && currentPage === 1) {
			let birthdateInput = $("#input_5_5");

			birthdateInput.on("input change", function () {
				let birthdateValue = $(this).val();

				// Check if date is in valid format (DD/MM/YYYY)
				if (birthdateValue && birthdateValue.length === 10) {
					// Parse the date (assuming DD/MM/YYYY format from your logs)
					let parts = birthdateValue.split("/");
					let birthMonth = parseInt(parts[0], 10) - 1; // Months are 0-based in JS
					let birthDay = parseInt(parts[1], 10);
					let birthYear = parseInt(parts[2], 10);

					let birthDate = new Date(birthYear, birthMonth, birthDay);
					let today = new Date();

					// Calculate age
					let age = today.getFullYear() - birthDate.getFullYear();
					// Adjust age if birthday hasn't occurred yet this year
					if (
						today.getMonth() < birthMonth ||
						(today.getMonth() === birthMonth && today.getDate() < birthDay)
					) {
						age--;
					}

					// Check if age is 16 or younger
					if (age < 16) {
						if ($('.age-alert-popup').length === 0) {
							const popupContainer = document.createElement("div");
							popupContainer.className = "popup-display-container";

							const simplePopup = createAgeAlertPopup();
							popupContainer.appendChild(simplePopup);

							document.body.appendChild(popupContainer);
							$popupContainer = $(popupContainer);
							// Add event listener to close the popup when clicking outside
							document.addEventListener("click", function (event) {
								// Check if the click target is outside the popup container
								if (!event.target.closest(".popup-display-container")) {
									popupContainer.remove();
								}
							});
						}
					}
				}
			});
		}

		//logic for run code in Lastpage
		let isLastPage = $('#gform_page_' + formId + '_' + (currentPage + 1));
		if (!isLastPage.length) {
			let container = $('.order-summary-container')
			getCheckedLabelsWithHtml(formId, container);
			let addons = $('#input_' + formId + '_98');
			if (addons.length) {
				addContentToAddons(formId);
			}

			let total = $('.ginput_total_' + formId)
			if (total.length) {
				addOrderContainer(formId);
			}
			managePreviewTable(); //Function for remove extra outer table from preview

		}
	});
	//For Exit Intent logic
	let mouseY;
	$('body').on('mouseleave', function (event) {
		mouseY = event.clientY;
		if (mouseY < 0) {
			// function for collecting avialble data
			handleExitIntent()
		}
	});


	function checkAndCollectData() {
		// Get input values and fallback to empty string if undefined/null
		let firstName = $('input[name="input_1.3"]').val() || '';
		let lastName = $('input[name="input_1.6"]').val() || '';
		let email = $('input[name="input_2"]').val() || '';

		// Return the data object
		return {
			email: email,
			first_name: firstName,
			last_name: lastName,
			formId: currentFormId,
		};
	}

	// function for sending data to brevo
	function sendDataToBrevo(data) {
		if (isDataSameAsLastSent(data)) {
			return;
		}

		lastSentData = JSON.parse(JSON.stringify(data));
		// Send data to backend using AJAX
		$.ajax({
			type: "POST",
			url: send_data_to_brevo_obj.ajax_url,
			data: {
				action: "send_data_to_brevo",
				nonce: send_data_to_brevo_obj.nonce,
				data: data,
			},
			success: function (response) {
				// console.log("Data sent successfully:", response);
			},
			error: function (xhr, status, error) {
				//console.error("Error sending data:", error);
			}
		});
	}

	// Helper function to compare current data with last sent data
	function isDataSameAsLastSent(newData) {
		if (!lastSentData) return false;

		// Compare only the relevant fields
		const fieldsToCompare = ['email', 'phone', 'first_name', 'last_name'];
		return fieldsToCompare.every(field =>
			newData[field] === lastSentData[field]
		);
	}

	// Helper function to handle Exit  Intent logic
	function handleExitIntent() {
		if (hasSend || isGenuineSubmission) {
			return
		}
		let data = checkAndCollectData();
		if (data.email && data.email !== '') {
			sendDataToBrevo(data);
		}
	}

	function createAgeAlertPopup() {
		popupTitle =
			"We noticed you’re under 16 years old and are applying for a passport renewal.";
		popupContent =
			"<b>Remember:</b> When you’re under 16 you must fill out a DS-11 new application even if this isn’t your first passport.";
		alertText =
			"If however, you’ve entered the wrong date of birth by mistake simply correct the error and continue filling in the form as normal.";
		buttonText = "Go to DS-11 >";
		buttonHref = "http://passportexpress.co/new-passport";

		// Create main container
		const popup = document.createElement("div");
		popup.className = "age-alert-popup";

		// Header
		const header = document.createElement("div");
		header.className = "age-alert-popup-header";

		const closeButton = document.createElement("button");
		closeButton.className = "age-alert-popup-close";
		closeButton.innerHTML = `X`;
		closeButton.addEventListener("click", () => popup.parentElement.remove());
		header.appendChild(closeButton);

		// Body
		const body = document.createElement("div");
		body.className = "age-alert-popup-body";

		const titleEl = document.createElement("h3");
		titleEl.className = "age-alert-popup-title";
		titleEl.textContent = popupTitle;
		body.appendChild(titleEl);

		const contentEl = document.createElement("div");
		contentEl.className = "age-alert-popup-content";
		contentEl.innerHTML = popupContent;
		body.appendChild(contentEl);

		const actions = document.createElement("div");
		actions.className = "age-alert-popup-actions";

		const continueBtn = document.createElement("button");
		continueBtn.className = "age-alert-popup-btn age-alert-popup-btn-primary";
		continueBtn.textContent = buttonText;
		continueBtn.addEventListener("click", () => {
			window.location.href = buttonHref; //Comment for Now only
			popup.parentElement.remove();
		});

		actions.appendChild(continueBtn);
		body.appendChild(actions);

		const alert = document.createElement("div");
		alert.className = "age-alert-popup-alert";
		alert.textContent = alertText;
		body.appendChild(alert);

		// Combine all parts
		popup.appendChild(header);
		popup.appendChild(body);

		return popup;
	}

	function manageSpinner(formId, pageId) {
		const $ = jQuery;

		// Build current page wrapper ID
		const pageSelector = '#gform_page_' + formId + '_' + pageId;
		const $footer = $(pageSelector).find('.gform-page-footer');

		if (!$footer.length) return;

		const $nextBtn = $footer.find('.gform_next_button');
		const $prevBtn = $footer.find('.gform_previous_button');
		const $submitBtn = $footer.find(`#gform_submit_button_${formId}`);


		if (!$nextBtn.length && !$prevBtn.length && !$submitBtn.length) return;

		// Store original texts
		const originalNextText = $nextBtn.length ? $nextBtn.val() : '';
		const originalPrevText = $prevBtn.length ? $prevBtn.val() : '';
		const originalSubmitText = $submitBtn.length ? $submitBtn.val() : '';


		// Add click/mousedown listener for both buttons
		if ($nextBtn.length) {
			$nextBtn.on('click', function () {
				$(this).val('Loading...');
			});
		}
		if ($prevBtn.length) {
			$prevBtn.on('click', function () {
				$(this).val('Loading...');
			});
		}
		if ($submitBtn.length) {
			$submitBtn.on('click', function (e) {
				const $btn = $(this);
				setTimeout(() => {
					$btn.val('Submitting...');
				}, 0);
			});
		}

		const updateButtonState = () => {
			const $spinner = $footer.find('.gform-loader');

			if ($spinner.length) {
				$spinner.hide();
			} else {
				if ($nextBtn.length && $nextBtn.val() !== originalNextText) {
					$nextBtn.val(originalNextText);
				}
				if ($prevBtn.length && $prevBtn.val() !== originalPrevText) {
					$prevBtn.val(originalPrevText);
				}
				if ($submitBtn.length && $submitBtn.val() !== originalSubmitText) {
					$submitBtn.val(originalPrevText);
				}
			}
		};

		// Watch DOM changes in footer
		const observerConfig = { childList: true, subtree: true };
		const observer = new MutationObserver(updateButtonState);
		observer.observe($footer[0], observerConfig);

		updateButtonState();
	}


	function managePreviewTable() {
		const $wrapper = $('.applicants-details-preview');

		if (!$wrapper.length) return;

		const $outerTable = $wrapper.find('> table').first();

		if (!$outerTable.length) return;

		const $innerTable = $outerTable.find('table').first();

		if (!$innerTable.length) return;


		// Add custom class to inner table
		$innerTable.addClass('preview-submission-table');
		// $newInnerTable = convertTableToDivs($innerTable);
		addDataClassesToTable($innerTable);
		$newTable = transformPassportForm($innerTable);
		// Move inner table to wrapper before removing outer table
		// $innerTable.appendTo($wrapper);
		$newTable.appendTo($wrapper);
		// Remove the outer table
		$outerTable.remove();
		
		// Initialize accordion functionality
		initAccordion();
	}

	function addDataClassesToTable($table) {
		$table.find('tr').each(function () {
			const $row = $(this);
			const $cells = $row.find('td');

			// Check for section header row
			if ($cells.length === 1 && $cells.attr('colspan') === '2' &&
				$cells.css('backgroundColor') === 'rgb(238, 238, 238)') {
				$row.addClass('data-section-header');
				return;
			}

			// Check for question row (bgcolor #EAF2FA)
			if ($row.attr('bgcolor') === '#EAF2FA' && $cells.length === 1) {
				$row.addClass('data-question-label');
				return;
			}

			// Check for answer row (bgcolor #FFFFFF)
			if ($row.attr('bgcolor') === '#FFFFFF' && $cells.length === 2) {
				$row.addClass('data-answer');
				return;
			}

			// Check for order table row
			const styleAttr = ($row.attr('style') || '').replace(/\s+/g, ''); // normalize spacing
			if (
				(styleAttr.includes('background-color:#FFFFFF') || styleAttr.includes('background-color:#EAF2FA'))
			) {
				if ($cells.length === 2) {
					$row.addClass('data-answer');
				} else if ($cells.length === 1) {
					$row.addClass('data-section-header');
				}

				const $orderTable = $row.find('>td').find('table');
				if ($orderTable.length) {
					$row.addClass('has-order-table');
					$orderTable.addClass('order-table');

					$orderTable.find('thead tr').addClass('order-table-header');
					$orderTable.find('tbody tr').addClass('order-table-row');
					$orderTable.find('tfoot tr').addClass('order-table-footer');
				}
				return;
			}
		});
	}

	function transformPassportForm($innerTable) {
		console.log("Transforming Passport Form...");
		// Create container for the transformed content
		const $container = $('<div class="review-form-container">');

		let currentSection = null;
		let currentSectionContent = null;
		let sectionIndex = 0;

		// Iterate through all rows
		$innerTable.find('tr').each(function () {
			const $row = $(this);

			// Section header
			if ($row.hasClass('data-section-header')) {
				// Append previous section
				if (currentSection) {
					// Add Edit button before closing previous section
					const $editBtn = $(`<div class="section-edit-btn-container"><button class="section-edit-button" data-page="${sectionIndex}">Edit Section</button></div>`);
					currentSectionContent.append($editBtn);

					currentSection.append(currentSectionContent);
					$container.append(currentSection);

					sectionIndex++; // increase for next section
				}

				const sectionTitle = $row.find('td').text().trim();
				currentSection = $('<div class="section">');
				currentSection.append(`<div class="section-header"><h3>${sectionTitle}</h3><div class="section-header-completed"><span>Completed</span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="injected-svg" data-src="/svg/check-with-circle.svg" xmlns:xlink="http://www.w3.org/1999/xlink">
    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.88-11.71L10 14.17l-1.88-1.88a.996.996 0 1 0-1.41 1.41l2.59 2.59c.39.39 1.02.39 1.41 0L17.3 9.7a.996.996 0 0 0 0-1.41c-.39-.39-1.03-.39-1.42 0z" fill="currentColor" fill-rule="nonzero"></path>
</svg></div><span class="arrow-icon">▼</span></div>`);
				currentSectionContent = $('<div class="section-content">');
			}
			// Question label
			else if ($row.hasClass('data-question-label')) {
				const questionText = $row.find('strong').text().trim() || $row.text().trim();
				const $nextRow = $row.next();

				if ($nextRow.hasClass('data-answer')) {
					const answerHTML = $nextRow.find('td:last').html();
					const $formRow = $('<div class="review-form-row">');

					$formRow.append(`<div class="question">${questionText}</div>`);

					// Special handling for complex answers
					if (answerHTML.includes('<ul>') || answerHTML.includes('<ol>') ||
						answerHTML.includes('<br>') || answerHTML.includes('<table')) {
						$formRow.append(`<div class="answer">${answerHTML}</div>`);
					} else {
						$formRow.append(`<div class="answer">${answerHTML}</div>`);
					}

					currentSectionContent.append($formRow);
				}
			}
			// Order table
			else if ($row.hasClass('has-order-table')) {
				const $orderTable = $row.find('table.order-table');
				if ($orderTable.length) {
					const $orderContainer = $('<div class="order-container">');
					$orderContainer.append($orderTable.clone().addClass('order-table'));
					currentSectionContent.append($orderContainer);
				}
				currentSection.hide();
			}
		});

		// Append last section (with Edit button)
		if (currentSection) {
			const $editBtn = $(`<button class="section-edit-button" data-page="${sectionIndex}">Edit</button>`);
			currentSectionContent.append($editBtn);

			currentSection.append(currentSectionContent);
			$container.append(currentSection);
		}

		return $container;
	}

	//Function to add content after addons.
	function addContentToAddons(formId) {
		let data = $('.addons-custom-notice')
		if (data.length) {
			let passportInsuranceNotice = data.find('.passport-insurance-notice');
			let priorityServiceNotice = data.find('.priority-service-notice');
			let passportCardNotice = data.find('.passport-card-notice');

			$(`.gchoice_${formId}_98_1`).after(passportInsuranceNotice);
			$(`.gchoice_${formId}_98_2`).after(priorityServiceNotice);
			$(`.gchoice_${formId}_98_3`).after(passportCardNotice);
		}
	}

	function addOrderContainer(formId) {

		let mainContainer = $('#order-summary-main-container')
		let container = mainContainer.find('.order-summary-container')

		mainContainer.show();

		$('[name^="input_98"]').on('change', function () {
			const isChecked = $(this).is(':checked');
			const inputId = this.id;

			getCheckedLabelsWithHtml(formId, container);
		});
	}

	/**
	 * Helper function to get checked labels with HTML for order summary
	 */
	function getCheckedLabelsWithHtml(formId, container) {

		let product = $('.ginput_product_price');
		// let productName = product.attr('aria-label');
		let productName = $(`label[for="input_${formId}_97_1"]`).text();
		let productPrice = product.val();

		let cleanedProductPrice = productPrice.replace(/[^0-9.]/g, '');
		let total = parseFloat(cleanedProductPrice) || 0;

		// Start assembling the full HTML
		let finalHTML = '';

		// 1. Product Details
		finalHTML += `
		<div class="order-summary-grid order-summary-product-details">
			<div class="item-name">${productName}</div>
			<div class="item-price">$${parseFloat(cleanedProductPrice).toFixed(2)}</div>
		</div>`;

		// 2. Add-on Details
		let addonsHTML = '';
		let addonsAdded = false;

		$(`[id^="choice_${formId}_98_"]`).each(function () {
			if ($(this).is(':checked')) {
				addonsAdded = true;

				const inputId = $(this).attr('id');
				const label = $(`label[for="${inputId}"]`);

				let labelText = label.clone().children('.ginput_price').remove().end().text().trim();
				let price = label.attr('price');

				if (!price || price.trim() === '') {
					price = $('#' + inputId).val();
					if (price.includes('|')) {
						price = price.split('|')[1].trim();
					}
				} else {
					price = price.trim();
				}

				let numericPrice = parseFloat(price.replace(/[^0-9.]/g, '')) || 0;

				if (labelText.endsWith(price)) {
					labelText = labelText.slice(0, labelText.length - price.length).trim();
				}

				addonsHTML += `
				<div class="item-name">${labelText}</div>
				<div class="item-price">$${numericPrice.toFixed(2)}</div>
			`;

				total += numericPrice;
			}
		});

		finalHTML += `
		<div class="order-summary-grid order-summary-addons">
			${addonsAdded ? `<div class="addons-custom-title" style="grid-column: 1 / -1;">selected add-ons: </div>` + addonsHTML : `<div class="no-addons" style="grid-column: 1 / -1;">No add-ons selected</div>`}
		</div>`;

		// 3. Total
		finalHTML += `
		<div class="order-summary-grid order-summary-total-row">
			<div class="item-name"><strong>Total</strong></div>
			<div class="item-price"><strong>$${total.toFixed(2)}</strong></div>
		</div>`;

		// Set final HTML into container
		container.html(finalHTML);
	}

	/* Helper Function to wrap fields in a container */
	function wrapFieldsInContainer(fieldClass, containerClass, sectionTitle = null) {
		const fields = document.querySelectorAll(`.${fieldClass}`);

		if (fields.length === 0) return; // Nothing to do

		// Check if fields are already inside the container
		const alreadyWrapped = Array.from(fields).every(field => {
			return field.closest(`.${containerClass}`);
		});

		if (alreadyWrapped) return; // Already wrapped, do nothing

		// Create the wrapper container
		const wrapper = document.createElement('div');
		wrapper.classList.add(containerClass);

		// If a section title is provided, add it as an <h3>
		if (sectionTitle) {
			const title = document.createElement('h3');
			title.textContent = sectionTitle;
			title.classList.add('section-title'); // Optional: for styling
			wrapper.appendChild(title);
		}

		// Insert wrapper before the first field
		const firstField = fields[0];
		firstField.parentNode.insertBefore(wrapper, firstField);

		// Move all matching fields into the wrapper
		fields.forEach(field => {
			wrapper.appendChild(field);
		});
	}

	/**
	 * Observe a wrapper container and hide/show it based on child field visibility.
	 * @param {string} wrapperSelector - CSS selector for the container div.
	 * @param {string} childSelector - CSS selector for the fields inside.
	 */
	function observeContainer(wrapperSelector, childSelector) {
		const wrapper = document.querySelector(wrapperSelector);
		if (!wrapper) return;

		const updateVisibility = () => {
			const directChildren = Array.from(wrapper.children).filter(child =>
				child.matches(childSelector)
			);

			const anyVisible = directChildren.some(child =>
				child.getAttribute('data-conditional-logic') === 'visible'
			);

			if (anyVisible) {
				wrapper.style.removeProperty('display');
			} else {
				wrapper.style.setProperty('display', 'none', 'important');
			}
		};

		const observer = new MutationObserver(mutations => {
			let shouldUpdate = false;

			mutations.forEach(mutation => {
				// Handle child attribute changes
				if (mutation.type === 'attributes' &&
					mutation.attributeName === 'data-conditional-logic') {
					shouldUpdate = true;
				}
				// Handle added/removed children
				else if (mutation.type === 'childList') {
					mutation.addedNodes.forEach(node => {
						if (node.nodeType === 1 && node.matches(childSelector)) {
							childObserver.observe(node, {
								attributes: true,
								attributeFilter: ['data-conditional-logic']
							});
						}
					});
					shouldUpdate = true;
				}
			});

			if (shouldUpdate) updateVisibility();
		});

		// Special observer for child attributes
		const childObserver = new MutationObserver(mutations => {
			for (const mutation of mutations) {
				if (mutation.attributeName === 'data-conditional-logic') {
					updateVisibility();
					break;
				}
			}
		});

		// Observe wrapper for structural changes
		observer.observe(wrapper, { childList: true });

		// Observe existing children
		Array.from(wrapper.children).forEach(child => {
			if (child.matches(childSelector)) {
				childObserver.observe(child, {
					attributes: true,
					attributeFilter: ['data-conditional-logic']
				});
			}
		});

		// Initial visibility check
		updateVisibility();
	}

	function initAccordion() {
		const $sections = $('.section');

		// Hide all content except first section
		$sections.not(':first').find('.section-content').hide();
		
		// Set initial arrow states
		$sections.first().find('.arrow-icon').text('▲');
		$sections.not(':first').find('.arrow-icon').text('▼');

		// Add click handler to headers
		$('.section-header').on('click', function () {
			const $header = $(this);
			const $section = $header.closest('.section');
			const $content = $section.find('.section-content');
			const $arrow = $header.find('.arrow-icon');

			if ($content.is(':visible')) {
				$content.slideUp();
				$arrow.text('▼');
			} else {
				$sections.find('.section-content').slideUp();
				$sections.find('.arrow-icon').text('▼');
				$content.slideDown();
				$arrow.text('▲');
			}
		});

	}

	$(document).on('click', '.section-edit-button', function (e) {
		e.preventDefault();

		const targetPage = $(this).data('page') + 1;

		const $form = $(this).closest('form');

		if (!$form.length) {
			console.error("Gravity Form element not found.");
			return;
		}

		const formId = $form.attr('id').replace('gform_', '');

		$('#gform_target_page_number_' + formId).val(targetPage);

		$form.trigger('submit', [true]);
	});

});