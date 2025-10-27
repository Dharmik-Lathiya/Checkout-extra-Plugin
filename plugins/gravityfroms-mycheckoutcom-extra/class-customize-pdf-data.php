<?php

class Class_customize_pdf_data {

	/**
	 * Constructor.
	 * Sets up action hooks for handling form submissions and payment processing.
	 */
	public function __construct() {
		add_filter( 'fg_fillablepdfs_pdf_args', array( $this, 'custimize_fields_before_pdf_making' ), 10, 4 );
	}

	/**
	 * Customizes field values before PDF generation.
	 *
	 * @param array $pdf_meta The PDF metadata.
	 * @param array $feed     The feed data.
	 * @param array $entry    The entry data.
	 * @param array $form     The form data.
	 *
	 * @return array Modified PDF metadata.
	 */
	public function custimize_fields_before_pdf_making( $pdf_meta, $feed, $entry, $form ) {

		$ssn_fields_key = array(
			'Social Security Number', // For DS-64.
			'Applicant SSN', // For DS-11, DS-82.
			"Applicant's SSN", // For Ds-5504.
		);

		foreach ( $pdf_meta['field_values'] as $key => $value ) {
			// If this PDF field corresponds to one of your form's SSN or date fields.
			if ( ! empty( $ssn_fields_key ) && in_array( $key, $ssn_fields_key ) && ! empty( $value ) ) {
				$pdf_meta['field_values'][ $key ] = preg_replace( '/\D/', '', $value );
			}
		}
		// error_log( ' $pdf_meta["field_values"]: ' . print_r( $pdf_meta, true ) );

		return $pdf_meta;
	}
}

new Class_customize_pdf_data();