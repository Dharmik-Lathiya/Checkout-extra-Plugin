jQuery(document).ready(function ($){
		// Get all classes from the <body> tag
	let bodyClasses = document.body.classList;

	// Define the page IDs for all thank-you pages
	let thankYouPageIds = [
	"page-id-23093", // DS-82 English
	"page-id-23502", // DS-82 Chinese
	"page-id-23506", // DS-82 Spanish

	"page-id-23098", // DS-64 English
	"page-id-23493", // DS-64 Chinese
	"page-id-23497", // DS-64 Spanish

	"page-id-22927", // DS-11 English
	"page-id-23397", // DS-11 Chinese
	"page-id-23382", // DS-11 Spanish

	"page-id-23104", // DS-5504 English
	"page-id-23484", // DS-5504 Chinese
	"page-id-23488"  // DS-5504 Spanish
	];

	// Check if the current body has one of the thank-you page classes
	let isThankYouPage = thankYouPageIds.some(function(pageId) {
	return bodyClasses.contains(pageId);
	});
	if (isThankYouPage) {
		const reffNo = $('.application-RefCode');
		const paymentTotal = $('.application-paymentTotal');
		const paymentDate = $('.application-paymentDate');
		const applicantsName = $('.applicant-firstname');

		// Fix HTML-encoded ampersands and get URL parameters
		let searchString = window.location.search;
		// Replace HTML-encoded ampersands with regular ampersands
		searchString = searchString.replace(/&amp;/g, '&');
		
		const urlParams = new URLSearchParams(searchString);
		const refCode = urlParams.get('refcode');
		const paymentTotalValue = urlParams.get('paymenttotal');
		const paymentDateValue = urlParams.get('paymentdate');
		const applicantsNameValue = urlParams.get('firstname');

		//check for each's value and element if found then change it 
		if( reffNo && refCode && refCode.length ){
			reffNo.text(refCode);
		}
		if( paymentTotal && paymentTotalValue && paymentTotalValue.length > 0 ){
			//add $ sign after payment total
			paymentTotal.text(paymentTotalValue);
		}
		if( paymentDate && paymentDateValue && paymentDateValue.length > 0 ){
			paymentDate.text(paymentDateValue);
		}
		if( applicantsName && applicantsNameValue && applicantsNameValue.length > 0 ){
			applicantsName.text(applicantsNameValue);
		}
	}

});