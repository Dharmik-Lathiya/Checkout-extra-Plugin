//local storage
jQuery(document).ready(function ($) {

    const localStorageFormIds = [1, 4, 5,6];

	function getStorageKey(formId) {
		return `gravity_form_data_${formId}`;
	}

	function saveFormData($form, formId) {
		const formData = {};
		const formFields = $form.find('input:not([type=submit]):not([type=button]):not([type=hidden]), select, textarea');

		formFields.each(function () {
			const $field = $(this);
			const name = $field.attr('name');
			const type = $field.attr('type');

			if (!name || name.toLowerCase().includes('nonce')) return;

			if ($field.is('select[multiple]')) {
				formData[name] = $field.val() || [];
				return;
			}
			
			if (type === 'checkbox') {
				if (name.endsWith('[]')) {
					if (!Array.isArray(formData[name])) {
						formData[name] = [];
					}
					if ($field.is(':checked')) {
						formData[name].push($field.val());
					}
				} else {
					if ($field.is(':checked')) {
						formData[name] = $field.val();
					} else if(formData[name] === $field.val()){
						delete formData[name];
					}
				}
			} 
			else if (type === 'radio') {
				if ($field.is(':checked')) {
					formData[name] = $field.val();
				}
			} 
			else if (type !== 'file') {
				formData[name] = $field.val();
			}
		});

		localStorage.setItem(getStorageKey(formId), JSON.stringify(formData));
	}

	function loadFormData($form, formId) {
		const key = getStorageKey(formId);
		const savedData = JSON.parse(localStorage.getItem(key) || '{}');
		
		if (Object.keys(savedData).length === 0) {
			return;
		}

		const formFields = $form.find('input:not([type=submit]):not([type=button]):not([type=hidden]), select, textarea');

		formFields.each(function () {
			const $field = $(this);
			const name = $field.attr('name');
			const type = $field.attr('type');

			if (!name || !(name in savedData)) {
				return;
			}
			
			const value = savedData[name];

			if ($field.is('select[multiple]')) {
				$field.val(value);
			}
			else if (type === 'checkbox') {
				if (Array.isArray(value)) {
					if (value.includes($field.val())) {
						$field.prop('checked', true);
					}
				} else {
					if ($field.val() === value) {
						$field.prop('checked', true);
					}
				}
			}
			else if (type === 'radio') {
				if ($field.val() === value) {
					$field.prop('checked', true);
				}
			}
			else if (type !== 'file') {
				$field.val(value);
			}
			
			$field.trigger('change');
		});
	}

	function debounce(func, wait) {
		let timeout;
		return function (...args) {
			clearTimeout(timeout);
			timeout = setTimeout(() => func.apply(this, args), wait);
		};
	}

	$(document).on('gform_post_render', function (event, formId, currentPage) {
		if (!localStorageFormIds.includes(formId)) return;

		// Skip local storage auto-fill for edit application forms
		if (window.location.search.includes('token=') ) {
			return;
		}

		const $form = $('#gform_' + formId);
		
		loadFormData($form, formId);
		
		const debouncedSave = debounce(() => saveFormData($form, formId), 300);

		$form.off('input.gfls change.gfls');
		$form.on('input.gfls change.gfls', 'input, select, textarea', debouncedSave);

		$form.on('submit', function () {
			setTimeout(() => localStorage.removeItem(getStorageKey(formId)), 500);
		});
	});

	$(document).on('gform_confirmation_loaded', function (event, formId) {
		if (localStorageFormIds.includes(formId)) {
			localStorage.removeItem(getStorageKey(formId));
		}
	});
});