<?php

use Guzzle\Plugin\Backoff\TruncatedBackoffStrategy;

/**
 * Class GF_Checkout_Com_Brevo_Data
 *
 * Handles integration between Gravity Forms and Brevo for tracking form submissions
 * and payment status updates. Updates user attributes in Brevo based on form
 * submissions and successful payments.
 */
class GF_Checkout_Com_Brevo_Data {

	/**
	 * Constructor.
	 * Sets up action hooks for handling form submissions and payment processing.
	 */
	public function __construct() {
		add_action( 'gform_after_submission', array( $this, 'update_brevo_submitted_attribute' ), 10, 2 );
		// Automatically run this on payment completion.
		add_action( 'gform_post_payment_completed', array( $this, 'update_purchased_status_after_payment' ), 10, 2 );
		// action for handle ajax request for send data to brevo.
		add_action( 'wp_ajax_send_data_to_brevo', array( $this, 'send_data_to_brevo_handler' ) );
		add_action( 'wp_ajax_nopriv_send_data_to_brevo', array( $this, 'send_data_to_brevo_handler' ) );
		add_action( 'template_redirect', array( $this, 'maybe_trigger_pdf_download' ) );
	}

	/**
	 * Summary of update_brevo_submitted_attribute
	 *
	 * @param mixed $entry The Gravity Forms entry object.
	 * @param mixed $form  The Gravity Forms form object.
	 * @return void
	 */
	public function update_brevo_submitted_attribute( $entry, $form ) {
		$data       = array();
		$attributes = array();
		// Retrieve the email from the form entry; adjust the field ID as necessary.
		$form_id    = $entry['form_id'];
		$list_id    = (int) $this->get_brevo_listid_from_form_feeds( $form_id );
		$email      = rgar( $entry, '2' ); // Get Email from ID.
		$first_name = rgar( $entry, '1.3' );
		$last_name  = rgar( $entry, '1.6' );

		if ( ! empty( $email ) ) {

			$data['email']           = $email;
			$data['first_name']      = $first_name;
			$data['last_name']       = $last_name;
			$attributes['SUBMITTED'] = true;
			$attributes['PURCHASED'] = false;
			$this->send_data_to_brevo( $list_id, $data, $attributes );
		}
	}

	/**
	 * Hooked after Gravity Forms payment is completed.
	 * Updates Brevo attributes and sends a confirmation email.
	 *
	 * @param array $entry The Gravity Forms entry object.
	 * @param array $action The payment action details.
	 */
	public function update_purchased_status_after_payment( $entry, $action ) {
		$email = $entry[2];

		if ( $email && $entry ) {
			// 1. Update Brevo 'PURCHASED' attribute.
			$this->update_attribute_in_brevo( $email, array( 'PURCHASED' => true ) );

			// 2. Send payment confirmation email via Brevo
			$this->send_payment_confirmation_email( $entry );
		} else {
			GFCommon::log_debug( 'Missing required data to update Brevo/entry PURCHASED attribute.' );
		}
	}

	/**
	 * Sends a payment confirmation email via Brevo.
	 * This function now dynamically finds the correct template ID from the form's "Brevo Mail Extra" feed.
	 *
	 * @param array $entry The Gravity Forms entry object.
	 */
	public function send_payment_confirmation_email( $entry ) {
		error_log( 'Sending payment confirmation email via Brevo...' );

		$entry_id    = rgar( $entry, 'id' );
		$form_id     = rgar( $entry, 'form_id' );
		$form        = GFAPI::get_form( $form_id );
		$all_feeds   = GFAPI::get_feeds( null, $form_id );
		$brevo_addon = GF_Brevo_Mail_Extra_AddOn::get_instance();

		$email_field_id      = '2';
		$name_field_id       = '1';
		$first_name_input_id = $name_field_id . '.3';
		$last_name_input_id  = $name_field_id . '.6';

		$email      = rgar( $entry, $email_field_id );
		$first_name = rgar( $entry, $first_name_input_id );
		$last_name  = rgar( $entry, $last_name_input_id );

		$app_reff_code = '';
		$fields        = $form['fields'];
		foreach ( $fields as $field ) {
			if ( 'uid' === $field->type && 'Application Unique ID' === $field->label ) {
				$app_reff_code = rgar( $entry, $field->id );
			} elseif ( 'Mailing Address State/Territory' === $field->label ) {
				$state = rgar( $entry, $field->id );
			} elseif ( 'Mailing Address' === $field->label ) {
				$address_parts = array();
				$inputs        = $field->inputs;

				foreach ( $inputs as $input ) {
					$temp = rgar( $entry, $input['id'] );
					if ( ! empty( $temp ) ) {
						$address_parts[] = $temp;
					}
				}
			}
		}
		// Only add $state if it's not empty.
		if ( ! empty( $state ) ) {
			$address_parts[] = $state;
		}
		$address = implode( ', ', $address_parts );

		$raw_date   = rgar( $entry, 'payment_date' );
		$order_date = '';
		if ( ! empty( $raw_date ) ) {
			// Try parsing as year-month-day FIRST, as this is the intended format.
			$date_obj = DateTime::createFromFormat( 'y-m-d H:i:s', $raw_date );

			if ( ! $date_obj ) {
				// If the above fails, try parsing as day-month-year as a FALLBACK.
				$date_obj = DateTime::createFromFormat( 'd-m-y H:i:s', $raw_date );
			}

			if ( $date_obj ) {
				$order_date = $date_obj->format( 'd F Y' );
			}
		}

		if ( empty( $email ) ) {
			GFCommon::log_debug( __METHOD__ . '(): No email address found in entry ' . $entry['id'] );
			return;
		}

		$to = array(
			array(
				'email' => $email,
				'name'  => trim( $first_name . ' ' . $last_name ),
			),
		);

		$params_base = array(
			'PRENOM'        => $first_name,
			'NOM'           => $last_name,
			'ORDER_DATE'    => $order_date,
			'APP_REFF_CODE' => $app_reff_code,
			'BILLING_ADDR'  => $address,
			'ORDER_TOTAL'   => GFCommon::to_money( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) ),
		);

		// Generate edit link using GP Easy Passthrough token
		if ( function_exists( 'gp_easy_passthrough' ) ) {
			// Check if refresh_token is enabled (allows multiple edits)
			$refresh_token_enabled = true; // Set to true to match your gpep-edit-entry.php config

			$token_used = gform_get_meta( $entry_id, 'fg_easypassthrough_token_used' );

			if ( $token_used && ! $refresh_token_enabled ) {
				// Entry was already edited and refresh_token is disabled
				error_log( 'Brevo Email: Entry ' . $entry_id . ' was already edited on ' . $token_used . ', not including edit link' );
				$params_base['EDIT_APP_LNK'] = ''; // Empty edit link
			} else {
				// Generate edit link (either first time or refresh_token enabled)
				if ( $token_used && $refresh_token_enabled ) {
					// Clear the used flag for multiple edits
					gform_delete_meta( $entry_id, 'fg_easypassthrough_token_used' );
					gform_delete_meta( $entry_id, 'fg_easypassthrough_token' ); // Force new token generation
					error_log( 'Brevo Email: Cleared token_used flag for entry ' . $entry_id . ' (refresh_token enabled)' );
				}

				$token = gp_easy_passthrough()->get_entry_token( $entry_id );

				// Define edit page URLs for different ORIGINAL forms
				$edit_pages = array(
					'1' => '/edit-new-passport/',        // Original Form 1 → Edit page with Form 11
					'4' => '/edit-lost-stolen-passport/', // Original Form 4 → Edit page with Form 13
					'5' => '/edit-passport-renewal/',     // Original Form 5 → Edit page with Form 12
					'6' => '/edit-passport-corrections/', // Original Form 6 → Edit page with Form 14
				);

				$edit_page                   = isset( $edit_pages[ $form_id ] ) ? $edit_pages[ $form_id ] : '/edit-new-passport/';
				$edit_url                    = home_url( $edit_page . '?gpep_token=' . $token );
				$params_base['EDIT_APP_LNK'] = $edit_url;
				error_log( 'Edit_URL: ' . print_r( $edit_url, true ) );
			}
		}

		foreach ( $all_feeds as $feed ) {
			if ( 'gf-brevo-mail-extra' !== $feed['addon_slug'] || ! rgar( $feed, 'is_active' ) ) {
				continue;
			}

			if ( $brevo_addon->is_feed_condition_met( $feed, $form, $entry ) ) {
				$template_id = rgars( $feed, 'meta/brevoTemplateId' );
				$delay_flag  = rgars( $feed, 'meta/delay30Minutes' );
				$attachments = array();

				if ( '1' === $delay_flag || 1 === $delay_flag ) {
					$delay = true;  // Delay enabled.
				} else {
					$delay = false; // Send immediately.
				}
				if ( rgars( $feed, 'meta/fillablePdfFeedId' ) && ! empty( rgars( $feed, 'meta/fillablePdfFeedId' ) ) ) {
					$pdf_feed_id                  = rgars( $feed, 'meta/fillablePdfFeedId' );
					$attachments                  = $this->get_entry_pdf_links( $entry['id'], $pdf_feed_id );
					$params_base['DOWNLOAD_LINK'] = $attachments[0]['url'];
					if ( rgars( $feed, 'meta/onlyattachmentlink' ) && (int) rgars( $feed, 'meta/onlyattachmentlink' ) === 1 ) {
						$attachments = array(); // make it empty if we need to send only link.
					}
				}
				$params = $params_base;
				$mailer = new GF_Checkout_Com_Brevo_Mailer();
				$result = $mailer->send_transactional_email( $template_id, $to, $params, $attachments, $entry_id, $delay );

				if ( is_wp_error( $result ) ) {
					$this->add_note( $entry['id'], 'Failed to send Brevo email (Template ID ' . $template_id . '). Error: ' . $result->get_error_message(), 'error' );
				} elseif ( 'scheduled' === $result ) {
					$this->add_note( $entry['id'], 'Brevo email (Template ID ' . $template_id . ') scheduled to be sent after 30 minutes.' );
				} else {
					$this->add_note( $entry['id'], 'Brevo email (Template ID ' . $template_id . ') sent immediately.' );
				}
			}
		}
	}

	public function maybe_trigger_pdf_download() {
		// Check if we are on one of the specific thank you pages.
		$current_slug = basename( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

		// Define all target thank you page slugs.
		$thank_you_slugs = array(
			'thank-you-page-passport-renewal-ds-82',
			'pagina-de-agradecimiento-renovacion-de-pasaporte-ds-82',
			'thank-you-page-passport-changes-and-corrections-ds-5504',
			'pagina-gracias-cambios-y-correcciones-en-el-pasaporte-ds-5504',
			'thank-you-page-new-passport-ds-11',
			'pagina-de-agradecimiento-nuevo-pasaporte-ds-11',
			'thank-you-page-lost-or-stolen-passport-ds-64',
			'pagina-de-agradecimiento-pasaporte-perdido-o-robado-ds-64'
		);

		// Use in_array to check if the current slug exists in the array of target slugs
		if ( ! in_array( $current_slug, $thank_you_slugs, true ) ) {
			return;
		}

		// Parse and decode URL to handle HTML entities
		if ( empty( $_SERVER['QUERY_STRING'] ) ) {
			return;
		}
		
		$query_string = html_entity_decode( $_SERVER['QUERY_STRING'] );
		parse_str( $query_string, $params );

		if ( ! is_array( $params ) || ! isset( $params['entry'] ) || ! isset( $params['gform_id'] ) ) {
			return;
		}

		$entry_id = absint( $params['entry'] );

		if ( $entry_id === 0 ) {
			return;
		}

		// Get entry and check if paid
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) || empty( $entry['payment_status'] ) || $entry['payment_status'] !== 'Paid' ) {
			return;
		}

		$form_id = isset( $entry['form_id'] ) ? absint( $entry['form_id'] ) : 0;
		
		if ( $form_id === 0 ) {
			return;
		}
		
		$all_feeds = GFAPI::get_feeds( null, $form_id );
		if ( ! is_array( $all_feeds ) || empty( $all_feeds ) ) {
			return;
		}
		
		
		$pdf_download_url = '';

		// Get PDF from delayed feed
		foreach ( $all_feeds as $feed ) {
			if ( 'gf-brevo-mail-extra' !== $feed['addon_slug'] || ! rgar( $feed, 'is_active' ) ) {
				continue;
			}
			
			$delay_flag = rgars( $feed, 'meta/delay30Minutes' );
			if ( ( '1' === $delay_flag || 1 === $delay_flag ) && rgars( $feed, 'meta/fillablePdfFeedId' ) ) {
				$pdf_feed_id = rgars( $feed, 'meta/fillablePdfFeedId' );
				$attachments = $this->get_entry_pdf_links( $entry_id, $pdf_feed_id );
				if ( ! empty( $attachments ) ) {
					$pdf_download_url = $attachments[0]['url'];
					break;
				}
			}
		}

		if ( empty( $pdf_download_url ) || ! filter_var( $pdf_download_url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		add_action( 'wp_footer', function() use ( $pdf_download_url, $entry_id ) {
			?>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					// Auto download function
					function downloadPDF() {
						console.log('PDF Download: Attempting download');
						var link = document.createElement('a');
						link.href = '<?php echo esc_url( $pdf_download_url ); ?>';
						link.download = 'application-form-entry-<?php echo esc_attr( $entry_id ); ?>.pdf';
						link.target = '_blank';
						document.body.appendChild(link);
						link.click();
						document.body.removeChild(link);
						console.log('PDF Download: Download triggered');
					}
					
					// Auto download on page load
					downloadPDF();
					
					// Add click handler for download button
					var downloadBtn = document.getElementById('form-pdf-download-btn');
					if (downloadBtn) {
						downloadBtn.addEventListener('click', function(e) {
							e.preventDefault();
							console.log('PDF Download: Button clicked');
							downloadPDF();
						});
					} else {
						console.log('PDF Download: Button not found');
					}
				});
			</script>
			<?php
		}, 999 );
		error_log( 'maybe_trigger_pdf_download: PDF download JavaScript enqueued for entry ID: ' . $entry_id );
	}


	/**
	 * Adds a note to a Gravity Forms entry for auditing.
	 *
	 * @param int    $entry_id The entry ID.
	 * @param string $note     The note content.
	 * @param string $note_type The note type.
	 */
	public function add_note( $entry_id, $note, $note_type = 'success' ) {
		if ( class_exists( 'GFAPI' ) ) {
			GFAPI::add_note( $entry_id, 'gravityforms-checkout-com-extra', 'Brevo Mailer', $note, 'brevo-mailer', $note_type );
		}
	}


	/**
	 * Reusable function: Update PURCHASED = true in both Brevo + entry field.
	 *
	 * @param string $email The email address to update.
	 * @param array  $attributes The attributes to update.
	 */
	public function update_attribute_in_brevo( $email, $attributes = array() ) {
		if ( empty( $email ) ) {
			GFCommon::log_debug( 'Email is required to update Brevo contact.' );
			return;
		}

		if ( empty( $attributes ) ) {
			GFCommon::log_debug( 'Attributes are required to update Brevo contact.' );
			return;
		}

		// Step 1: Update Brevo.
		$brevo_settings = get_option( 'gravityformsaddon_gravityformsbrevo_settings' );
		$api_key        = rgar( $brevo_settings, 'brevo_api_key' );

		if ( ! $api_key ) {
			GFCommon::log_debug( ' Brevo API Key not found.' );
			return;
		}

		$url  = 'https://api.brevo.com/v3/contacts/' . urlencode( $email );
		$body = json_encode( array( 'attributes' => $attributes ) );

		$args     = array(
			'headers' => array(
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
			),
			'body'    => $body,
			'method'  => 'PUT',
		);
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			GFCommon::log_debug( 'Brevo update failed: ' . $response->get_error_message() );
		} else {
			GFCommon::log_debug( "Brevo contact updated successfully of email {$email}." );
		}
	}

	/**
	 * Update PURCHASED field in the Gravity Forms entry (based on field label).
	 *
	 * @param int    $entry_id The entry ID to update.
	 * @param int    $form_id The form ID associated with the entry.
	 * @param string $target_label The label of the field to update.
	 * @param string $target_value The value to update the field with.
	 */
	public function update_entry_purchased_field( $entry_id, $form_id, $target_label = 'PURCHASED', $target_value = 'true' ) {

		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			GFCommon::log_debug( "Form {$form_id} not found for updating field." );
			return;
		}

		$field_id_to_update = null;

		foreach ( $form['fields'] as $field ) {
			if ( strtolower( $field->label ) === strtolower( $target_label ) ) {
				$field_id_to_update = $field->id;
				break;
			}
		}

		if ( $field_id_to_update ) {
			$result = GFAPI::update_entry_field( $entry_id, $field_id_to_update, $target_value );
			if ( is_wp_error( $result ) ) {
				GFCommon::log_debug( 'Failed to update entry field: ' . $result->get_error_message() );
			} else {
				GFCommon::log_debug( "Entry {$entry_id} field '{$field_id_to_update}' updated to {$target_value}." );
			}
		} else {
			GFCommon::log_debug( "Field with label 'PURCHASED' not found in form {$form_id}." );
		}
	}


	/**
	 *  Send data to brevo custom
	 */
	public function send_data_to_brevo_handler() {

		// Check if nonce is valid.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'send_data_to_brevo_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce' );
			wp_die();
		}

		if ( ! isset( $_POST['data'] ) || empty( $_POST['data'] ) ) {
			wp_send_json_error( 'Missing required data.' );
		}

		$data       = $_POST['data'];
		$email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		$first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
		$last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
		$form_id    = isset( $data['formId'] ) ? sanitize_text_field( $data['formId'] ) : '';

		$data['email']      = $email;
		$data['first_name'] = $first_name;
		$data['last_name']  = $last_name;
		if ( ! $email ) {
			wp_send_json_error( 'Email is required.' );
		}
		$list_id    = (int) $this->get_brevo_listid_from_form_feeds( $form_id );
		$attributes = array(
			'PURCHASED' => false,
			'SUBMITTED' => false,
		);
		$this->send_data_to_brevo( $list_id, $data, $attributes, true );
	}

	/**
	 * Function For send data to brevo manually.
	 *
	 * @param int     $list_id        pass list id.
	 * @param array   $data           pass data in array formate with key name like email , first_name , last_name , phone.
	 * @param array   $attributes     pass in array formate to send direclty.
	 * @param boolean $need_response  pass true if you want to get response from brevo.
	 */
	public function send_data_to_brevo( $list_id, $data, $attributes = array(), $need_response = false ) {
		if ( empty( $data ) || empty( $data['email'] ) ) {
			wp_send_json_error( 'Required data missing.' );
			return;
		}
		$brevo_settings = get_option( 'gravityformsaddon_gravityformsbrevo_settings' );
		$brevo_api_key  = rgar( $brevo_settings, 'brevo_api_key' );
		// $list_id        = isset( $brevo_settings['list_id'] ) ? intval( $brevo_settings['list_id'] ) : 14;

		if ( empty( $brevo_api_key ) ) {
			wp_send_json_error( 'API key not configured.' );
			return;
		}

		// Add name fields if present.
		if ( ! empty( $data['first_name'] ) ) {
			$attributes['PRENOM']    = $data['first_name'];
			$attributes['FIRSTNAME'] = $data['first_name'];
		}

		if ( ! empty( $data['last_name'] ) ) {
			$attributes['NOM']      = $data['last_name'];
			$attributes['LASTNAME'] = $data['last_name'];
		}

		$payload = array(
			'email'         => $data['email'],
			'listIds'       => array( $list_id ),
			'updateEnabled' => true,
			'attributes'    => $attributes,
		);

		$args = array(
			'headers' => array(
				'api-key'      => $brevo_api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'method'  => 'POST',
			'timeout' => 60,
		);

		$response = wp_remote_request( 'https://api.brevo.com/v3/contacts', $args );
		if ( $need_response ) {
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( 'Request failed: ' . $response->get_error_message() );
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			if ( $code >= 200 && $code < 300 ) {
				wp_send_json_success( 'Contact added successfully.' );
			} else {
				wp_send_json_error( 'Error adding contact: ' . $body );
			}
		}
	}

	/**
	 * Helper Function to fetch brevo ListId from form feeds.
	 *
	 * @param int $form_id The ID of the form.
	 */
	public function get_brevo_listid_from_form_feeds( $form_id ) {
		if ( empty( $form_id ) ) {
			return '';
		}

		$feeds = GFAPI::get_feeds( null, $form_id );

		foreach ( $feeds as $feed ) {
			if ( isset( $feed['addon_slug'] ) && $feed['addon_slug'] === 'gravityformsbrevo' ) {
				return $feed['meta']['brevoList'] ?? '';
			}
		}

		return '';
	}

	/**
	 * Safely retrieves Fillable PDFs for a Gravity Forms entry.
	 *
	 * @param int   $entry_id     The Gravity Forms entry ID.
	 * @param array $pdf_feed_id  PDF feed IDs to filter by.
	 *
	 * @return array An array of ['name' => string, 'url' => string] pairs.
	 */
	public function get_entry_pdf_links( $entry_id, $pdf_feed_id = null ) {

		$results = array();

		if ( empty( $pdf_feed_id ) ) {
			return $results;
		}
		$pdf_feed_id = intval( $pdf_feed_id );

		// Check if plugin and required methods exist.
		if ( ! class_exists( 'Fillable_PDFs_API' ) || ! function_exists( 'fg_fillablepdfs' ) ) {
			return $results;
		}

		if ( ! method_exists( fg_fillablepdfs(), 'get_entry_pdfs' ) ) {
			return $results;
		}

			$entry_id = intval( $entry_id );
		if ( $entry_id <= 0 ) {
			return $results;
		}

		$pdfs = fg_fillablepdfs()->get_entry_pdfs( $entry_id );
		if ( is_wp_error( $pdfs ) ) {
			return $results;
		}

		if ( ! is_array( $pdfs ) || empty( $pdfs ) ) {
			return $results;
		}
		foreach ( $pdfs as $pdf ) {
			if ( ! is_array( $pdf ) ) {
				continue;
			}

			$name    = isset( $pdf['file_name'] ) ? sanitize_text_field( $pdf['file_name'] ) : '(Unnamed PDF)';
			$feed_id = isset( $pdf['feed_id'] ) ? intval( $pdf['feed_id'] ) : 0;
			if ( $feed_id !== $pdf_feed_id ) {
				continue;
			}

			// Build URL.
			$url = '';
			if ( method_exists( fg_fillablepdfs(), 'build_pdf_url' ) ) {
				$url = fg_fillablepdfs()->build_pdf_url( $pdf, true );
			}

			if ( $url ) {
				$results[] = array(
					'name' => $name,
					'url'  => esc_url_raw( $url ),
				);
			}
		}

		return $results;
	}
}

// Initialize class.
new GF_Checkout_Com_Brevo_Data();
