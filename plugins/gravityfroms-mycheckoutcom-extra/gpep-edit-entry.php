<?php

class GPEP_Edit_Entry {

	private $form_id;
	private $delete_partial;
	private $passed_through_entries;
	private $refresh_token;
	private $process_feeds;

	public function __construct( $options ) {

		if ( ! function_exists( 'rgar' ) ) {
			return;
		}

		$this->form_id        = rgar( $options, 'form_id' );
		$this->delete_partial = rgar( $options, 'delete_partial', true );
		$this->refresh_token  = rgar( $options, 'refresh_token', false );
		$this->process_feeds  = rgar( $options, 'process_feeds', false );

		add_filter( "gpep_form_{$this->form_id}", array( $this, 'capture_passed_through_entry_ids' ), 10, 3 );
		add_filter( "gform_entry_id_pre_save_lead_{$this->form_id}", array( $this, 'update_entry_id' ), 10, 2 );
		add_filter( "gform_entry_post_save_{$this->form_id}", array( $this, 'delete_values_for_conditionally_hidden_fields' ), 10, 2 );

		// Enable edit view in GP Inventory.
		add_filter( "gpi_is_edit_view_{$this->form_id}", '__return_true' );

		// Bypass limit submissions on validation
		add_filter( 'gform_validation', array( $this, 'bypass_limit_submission_validation' ) );

		add_filter( "gpi_query_{$this->form_id}", array( $this, 'exclude_edit_entry_from_inventory' ), 10, 2 );

		// If we need to reprocess any feeds on 'edit'.
		if ( $this->process_feeds ) {
			add_filter( "gform_entry_post_save_{$this->form_id}", array( $this, 'process_feeds' ), 10, 2 );
		}
	}

	public function capture_passed_through_entry_ids( $form, $values, $passed_through_entries ) {
		// MANUAL FIX - If GP Easy Passthrough fails, do it manually
		if ( empty( $passed_through_entries ) && isset( $_GET['gpep_token'] ) ) {
			if ( function_exists( 'gp_easy_passthrough' ) ) {
				$token = sanitize_text_field( $_GET['gpep_token'] );
				$entry = gp_easy_passthrough()->get_entry_for_token( $token );

				if ( $entry ) {
					// Create manual passed through entries.
					$passed_through_entries = array(
						array(
							'entry_id' => $entry['id'],
							'form_id'  => $entry['form_id'],
						),
					);
				}
			}
		}

		// Save a runtime cache for use when releasing inventory reserved by the entry being edited.
		$this->passed_through_entries = $passed_through_entries;

		if ( empty( $passed_through_entries ) ) {
			return $form;
		}

		// Add hidden input to capture entry IDs passed through via GPEP.

		add_filter(
			"gform_form_tag_{$form['id']}",
			function ( $form_tag, $form ) use ( $passed_through_entries ) {
				$entry_ids = implode( ',', wp_list_pluck( $passed_through_entries, 'entry_id' ) );
				$hash      = wp_hash( $entry_ids );
				$value     = sprintf( '%s|%s', $entry_ids, $hash );
				$input     = sprintf( '<input type="hidden" name="%s" value="%s">', $this->get_passed_through_entries_input_name( $form['id'] ), $value );
				$form_tag .= $input;
				return $form_tag;
			},
			10,
			2
		);

		add_filter(
			"gpls_rule_groups_{$this->form_id}",
			function ( $rule_groups, $form_id ) use ( $passed_through_entries ) {
				// Bypass GPLS if we're updating an entry.
				if ( ! empty( $passed_through_entries ) ) {
					$rule_groups = array();
				}

				return $rule_groups;
			},
			10,
			2
		);

		return $form;
	}

	public function bypass_limit_submission_validation( $validation_result ) {
		$edit_entry_id = $this->get_edit_entry_id( rgars( $validation_result, 'form/id' ) );

		if ( ! $edit_entry_id ) {
			return $validation_result;
		}

		add_filter(
			"gpls_rule_groups_{$this->form_id}",
			function ( $rule_groups, $form_id ) use ( $edit_entry_id ) {
				return array();
			},
			10,
			2
		);

		return $validation_result;
	}

	public function update_entry_id( $entry_id, $form ) {

		error_log( 'GPEP_Edit_Entry: update_entry_id called for form ' . $form['id'] . ' with entry_id ' . $entry_id );

		$update_entry_id = $this->get_edit_entry_id( $form['id'] );
		error_log( 'GPEP_Edit_Entry: get_edit_entry_id returned: ' . ( $update_entry_id ? $update_entry_id : 'false' ) );

		if ( $update_entry_id ) {
			error_log( 'GPEP_Edit_Entry: Updating existing entry ' . $update_entry_id . ' instead of creating new entry ' . $entry_id );

			// Preserve product selections before updating
			$this->preserve_product_selections( $entry_id, $update_entry_id, $form );

			// Purge product info cache
			$this->purge_product_cache( $form, GFAPI::get_entry( $update_entry_id ) );

			if ( $this->delete_partial
				&& is_callable( array( 'GF_Partial_Entries', 'get_instance' ) )
				&& $entry_id !== null
				&& ! empty( GF_Partial_Entries::get_instance()->get_active_feeds( $form['id'] ) )
			) {
				GFAPI::delete_entry( $entry_id );
			}
			if ( $this->refresh_token ) {
				gform_delete_meta( $update_entry_id, 'fg_easypassthrough_token' );
				gform_delete_meta( $update_entry_id, 'fg_easypassthrough_token_used' ); // Clear usage flag for multiple edits
				gp_easy_passthrough()->get_entry_token( $update_entry_id );
				// Remove entry from the session and prevent Easy Passthrough from resaving it.
				$session = gp_easy_passthrough()->session_manager();
				$session[ gp_easy_passthrough()->get_slug() . '_' . $form['id'] ] = null;
				remove_action( 'gform_after_submission', array( gp_easy_passthrough(), 'store_entry_id' ) );
			} else {
				// Invalidate token after use (one-time use) - DELETE the token completely
				gform_delete_meta( $update_entry_id, 'fg_easypassthrough_token' );
				gform_update_meta( $update_entry_id, 'fg_easypassthrough_token_used', current_time( 'mysql' ) );
				error_log( 'GPEP_Edit_Entry: Token invalidated for entry ' . $update_entry_id );
			}
			return $update_entry_id;
		}

		error_log( 'GPEP_Edit_Entry: No edit entry found, creating new entry ' . $entry_id );
		return $entry_id;
	}

	/**
	 * Delete values that exist for the entry in the database for fields that are now conditionally hidden.
	 *
	 * If we find any instance where a conditionally hidden field has a value, we'll update the DB with the passed entry,
	 * which was just submitted and will not contain conditionally hidden values.
	 *
	 * Note: There's a good case for us to simply call GFAPI::update_entry() with the passed entry without all the other
	 * fancy logic to that only makes the call if it identifies a conditionally hidden field with a DB value. A thought
	 * for future us.
	 *
	 * @param $entry
	 * @param $form
	 *
	 * @return mixed
	 */
	public function delete_values_for_conditionally_hidden_fields( $entry, $form ) {

		// We'll only update the entry if we identify a field value that needs to be deleted.
		$has_change = false;

		// The passed entry does not reflect what is actually in the database.
		$db_entry = null;

		/**
		 * @var \GF_Field $field
		 */
		foreach ( $form['fields'] as $field ) {

			if ( ! GFFormsModel::is_field_hidden( $form, $field, array(), $entry ) ) {
				continue;
			}

			if ( ! $db_entry ) {
				$db_entry = GFAPI::get_entry( $entry['id'] );
			}

			$inputs = $field->get_entry_inputs();
			if ( ! $inputs ) {
				$inputs = array(
					array(
						'id' => $field->id,
					),
				);
			}

			foreach ( $inputs as $input ) {
				if ( ! empty( $db_entry[ $input['id'] ] ) ) {
					$has_change = true;
					break 2;
				}
			}
		}

		if ( $has_change ) {
			GFAPI::update_entry( $entry );
		}

		return $entry;
	}

	public function get_passed_through_entries_input_name( $form_id ) {
		return "gpepee_passed_through_entries_{$form_id}";
	}

	public function get_passed_through_entry_ids( $form_id ) {

		$entry_ids = array();

		if ( ! empty( $_POST ) ) {

			$posted_value = rgpost( $this->get_passed_through_entries_input_name( $form_id ) );
			if ( empty( $posted_value ) ) {
				return $entry_ids;
			}

			list( $entry_ids, $hash ) = explode( '|', $posted_value );
			if ( $hash !== wp_hash( $entry_ids ) ) {
				return $entry_ids;
			}

			$entry_ids = explode( ',', $entry_ids );

		} elseif ( ! empty( $this->passed_through_entries ) ) {

			$entry_ids = wp_list_pluck( $this->passed_through_entries, 'entry_id' );

		}

		return $entry_ids;
	}

	public function get_edit_entry_id( $form_id ) {

		$entry_ids = $this->get_passed_through_entry_ids( $form_id );
		$entry_id  = array_shift( $entry_ids );

		/**
		 * Filter the ID that will be used to fetch assign the entry to be edited.
		 *
		 * @since 1.3
		 *
		 * @param int|bool $edit_entry_id The ID of the entry to be edited.
		 * @param int      $form_id       The ID of the form that was submitted.
		 */
		return gf_apply_filters( array( 'gpepee_edit_entry_id', $form_id ), $entry_id, $form_id );
	}

	/**
	 * Exclude the entry being edited in GravityView from inventory counts.
	 *
	 * Without this, you can't reselect choices that the current entry has consumed.
	 */
	public function exclude_edit_entry_from_inventory( $query, $field ) {
		global $wpdb;

		$entry_ids = $this->get_passed_through_entry_ids( $field->formId );

		// @todo Update to work with multiple passed through entries.
		$current_entry_id = array_pop( $entry_ids );
		if ( ! $current_entry_id ) {
			return $query;
		}

		$query['where'] .= $wpdb->prepare( "\nAND em.entry_id != %d", $current_entry_id );

		return $query;
	}

	public static function purge_product_cache( $form, $entry ) {

		$cache_options = array(
			array( false, false ),
			array( false, true ),
			array( true, false ),
			array( true, true ),
		);

		foreach ( $cache_options as $cache_option ) {
			list( $use_choice_text, $use_admin_label ) = $cache_option;
			if ( gform_get_meta( rgar( $entry, 'id' ), "gform_product_info_{$use_choice_text}_{$use_admin_label}" ) ) {
				gform_delete_meta( rgar( $entry, 'id' ), "gform_product_info_{$use_choice_text}_{$use_admin_label}" );
			}
		}
	}

	/**
	 * Preserve product selections from original entry during edit process
	 */
	public function preserve_product_selections( $edit_entry_id, $original_entry_id, $form ) {
		try {
			error_log( 'GPEP_Edit_Entry: Preserving product selections from original entry ' . $original_entry_id );
			
			// Get both entries
			$edit_entry = GFAPI::get_entry( $edit_entry_id );
			$original_entry = GFAPI::get_entry( $original_entry_id );
			
			if ( is_wp_error( $edit_entry ) || is_wp_error( $original_entry ) ) {
				error_log( 'GPEP_Edit_Entry: Failed to get entries for product preservation' );
				return;
			}

			// Get the original form to identify product fields
			$original_form_id = $this->get_original_form_id( $form['id'] );
			if ( ! $original_form_id ) {
				error_log( 'GPEP_Edit_Entry: Could not determine original form ID' );
				return;
			}

			$original_form = GFAPI::get_form( $original_form_id );
			if ( ! $original_form ) {
				error_log( 'GPEP_Edit_Entry: Could not get original form' );
				return;
			}

			// Find product and option fields in original form
			foreach ( $original_form['fields'] as $field ) {
				if ( in_array( $field->type, array( 'product', 'option' ) ) ) {
					$field_id = $field->id;
					
					// Preserve the original value
					if ( isset( $original_entry[ $field_id ] ) ) {
						$edit_entry[ $field_id ] = $original_entry[ $field_id ];
						error_log( 'GPEP_Edit_Entry: Preserved ' . $field->type . ' field ' . $field_id . ' = ' . $original_entry[ $field_id ] );
					}

					// Handle multi-input fields (like checkboxes)
					if ( ! empty( $field->inputs ) ) {
						foreach ( $field->inputs as $input ) {
							$input_id = $input['id'];
							if ( isset( $original_entry[ $input_id ] ) ) {
								$edit_entry[ $input_id ] = $original_entry[ $input_id ];
								error_log( 'GPEP_Edit_Entry: Preserved input ' . $input_id . ' = ' . $original_entry[ $input_id ] );
							}
						}
					}
				}
			}

			// Update the edit entry with preserved product data
			$result = GFAPI::update_entry( $edit_entry );
			if ( is_wp_error( $result ) ) {
				error_log( 'GPEP_Edit_Entry: Failed to update edit entry with preserved products: ' . $result->get_error_message() );
			} else {
				error_log( 'GPEP_Edit_Entry: Successfully preserved product selections in edit entry' );
			}

		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Exception in preserve_product_selections: ' . $e->getMessage() );
		}
	}

	/**
	 * Get original form ID from edit form ID
	 */
	private function get_original_form_id( $edit_form_id ) {
		$form_mapping = array(
			11 => 1,  // Edit New Passport -> New Passport
			12 => 5,  // Edit Passport Renewal -> Passport Renewal  
			13 => 4,  // Edit Lost/Stolen Passport -> Lost/Stolen Passport
			14 => 6,  // Edit Passport Corrections -> Passport Corrections
		);

		return isset( $form_mapping[ $edit_form_id ] ) ? $form_mapping[ $edit_form_id ] : null;
	}

	public function process_feeds( $entry, $form ) {
		if ( ! $this->process_feeds ) {
			return $entry;
		}

		// Prevent duplicate processing
		$processed_key = 'gpep_edit_processed_' . $entry['id'];
		if ( get_transient( $processed_key ) ) {
			error_log( 'GPEP_Edit_Entry: Already processed entry ' . $entry['id'] . ' - skipping' );
			return $entry;
		}
		set_transient( $processed_key, true, 300 ); // 5 minutes

		error_log( 'GPEP_Edit_Entry: Starting process_feeds for entry ' . $entry['id'] );

		// Add entry note for edit success
		$this->add_edit_note( $entry['id'], 'Entry successfully edited on ' . current_time( 'mysql' ), 'success' );

		// Transfer upload fields from edit form to original entry
		$this->transfer_upload_fields( $entry, $form );

		/**
		 * Disable asynchronous feed process on edit otherwise async feeds will not be re-ran due to a check in
		 * class-gf-feed-processor.php that checks `gform_get_meta( $entry_id, 'processed_feeds' )` and there isn't
		 * a way to bypass it.
		 */
		$filter_priority = rand( 100000, 999999 );
		add_filter( 'gform_is_feed_asynchronous', '__return_false', $filter_priority );

		foreach ( GFAddOn::get_registered_addons( true ) as $addon ) {
			if ( method_exists( $addon, 'maybe_process_feed' ) && ( $this->process_feeds === true || strpos( $this->process_feeds, $addon->get_slug() ) !== false ) ) {
				error_log( 'GPEP_Edit_Entry: Processing addon: ' . $addon->get_slug() );
				$addon->maybe_process_feed( $entry, $form );
			}
		}

		remove_filter( 'gform_is_feed_asynchronous', '__return_false', $filter_priority );

		// Regenerate Fillable PDFs after edit
		error_log( 'GPEP_Edit_Entry: Starting PDF regeneration for entry ' . $entry['id'] );
		$pdf_success = $this->regenerate_fillable_pdfs( $entry, $form );
		error_log( 'GPEP_Edit_Entry: PDF regeneration result: ' . ( $pdf_success ? 'SUCCESS' : 'FAILED' ) );

		// Send edit confirmation email with updated PDFs immediately
		if ( $pdf_success ) {
			error_log( 'GPEP_Edit_Entry: Sending edit confirmation email for entry ' . $entry['id'] );
			$this->send_edit_confirmation_email_now( $entry );
		} else {
			error_log( 'GPEP_Edit_Entry: Skipping email due to PDF failure for entry ' . $entry['id'] );
			$this->add_edit_note( $entry['id'], 'Email not sent due to PDF regeneration failure', 'error' );
		}

		error_log( 'GPEP_Edit_Entry: Completed process_feeds for entry ' . $entry['id'] );
		return $entry;
	}

	/**
	 * Regenerate Fillable PDFs for the entry
	 */
	public function regenerate_fillable_pdfs( $entry, $form ) {
		error_log( 'GPEP_Edit_Entry: regenerate_fillable_pdfs called for entry ' . $entry['id'] . ', edit form ' . $form['id'] );

		// Map edit forms to original forms for PDF regeneration
		$edit_to_original_form_map = array(
			11 => 1,  // Edit form 11 -> Original form 1
			13 => 4,  // Edit form 13 -> Original form 4
			12 => 5,  // Edit form 12 -> Original form 5
			14 => 6,  // Edit form 14 -> Original form 6
		);

		// Get the original form ID for PDF feeds
		$original_form_id = isset( $edit_to_original_form_map[ $form['id'] ] ) ? $edit_to_original_form_map[ $form['id'] ] : $form['id'];

		if ( $original_form_id !== $form['id'] ) {
			error_log( 'GPEP_Edit_Entry: Using original form ' . $original_form_id . ' PDF feeds for edit form ' . $form['id'] );

			// Get the original form for PDF feeds
			$original_form = GFAPI::get_form( $original_form_id );
			if ( ! $original_form ) {
				error_log( 'GPEP_Edit_Entry: Original form ' . $original_form_id . ' not found' );
				$this->add_edit_note( $entry['id'], 'PDF regeneration failed: Original form ' . $original_form_id . ' not found', 'error' );
				return false;
			}
		} else {
			$original_form = $form;
		}

		// Check if ForGravity Fillable PDFs plugin is active
		if ( ! class_exists( 'ForGravity\Fillable_PDFs\Fillable_PDFs' ) ) {
			error_log( 'GPEP_Edit_Entry: ForGravity\Fillable_PDFs\Fillable_PDFs class not found' );
			$this->add_edit_note( $entry['id'], 'PDF regeneration failed: Fillable PDFs plugin class not found', 'error' );
			return false;
		}
		error_log( 'GPEP_Edit_Entry: ForGravity Fillable PDFs class found' );

		// Get PDF feeds from the ORIGINAL form (not edit form)
		$feeds = GFAPI::get_feeds( null, $original_form_id, 'forgravity-fillablepdfs' );
		error_log( 'GPEP_Edit_Entry: Found ' . count( $feeds ) . ' PDF feeds for original form ' . $original_form_id );

		if ( empty( $feeds ) ) {
			error_log( 'GPEP_Edit_Entry: No Fillable PDF feeds found for original form ' . $original_form_id );
			$this->add_edit_note( $entry['id'], 'PDF regeneration failed: No PDF feeds found for original form ' . $original_form_id, 'error' );
			return false;
		}

		$pdf_success = false;
		foreach ( $feeds as $feed ) {
			error_log( 'GPEP_Edit_Entry: Processing feed ' . $feed['id'] . ', active: ' . ( $feed['is_active'] ? 'YES' : 'NO' ) );

			if ( ! $feed['is_active'] ) {
				error_log( 'GPEP_Edit_Entry: Skipping inactive feed ' . $feed['id'] );
				continue;
			}

			try {
				error_log( 'GPEP_Edit_Entry: Getting ForGravity addon instance' );

				// Get the ForGravity Fillable PDFs addon instance
				$addon = \ForGravity\Fillable_PDFs\Fillable_PDFs::get_instance();
				error_log( 'GPEP_Edit_Entry: Addon instance: ' . ( $addon ? 'SUCCESS' : 'FAILED' ) );

				if ( method_exists( $addon, 'process_feed' ) ) {
					error_log( 'GPEP_Edit_Entry: Calling process_feed for feed ' . $feed['id'] . ' with original form ' . $original_form_id );
					// Use original form for PDF generation but updated entry data
					$result = $addon->process_feed( $feed, $entry, $original_form );
					error_log( 'GPEP_Edit_Entry: process_feed result: ' . print_r( $result, true ) );
					error_log( 'GPEP_Edit_Entry: Regenerated PDF for feed ' . $feed['id'] . ' on entry ' . $entry['id'] );
					$pdf_success = true;
				} else {
					error_log( 'GPEP_Edit_Entry: process_feed method not found on addon' );
					$this->add_edit_note( $entry['id'], 'PDF regeneration failed: process_feed method not available', 'error' );
				}
			} catch ( Exception $e ) {
				error_log( 'GPEP_Edit_Entry: PDF regeneration exception for feed ' . $feed['id'] . ': ' . $e->getMessage() );
				error_log( 'GPEP_Edit_Entry: Exception trace: ' . $e->getTraceAsString() );
				$this->add_edit_note( $entry['id'], 'PDF regeneration failed for feed ' . $feed['id'] . ': ' . $e->getMessage(), 'error' );
			}
		}

		if ( $pdf_success ) {
			error_log( 'GPEP_Edit_Entry: PDF regeneration completed successfully for entry ' . $entry['id'] );
			$this->add_edit_note( $entry['id'], 'PDF regeneration completed successfully using original form ' . $original_form_id . ' feeds', 'success' );
		} else {
			error_log( 'GPEP_Edit_Entry: PDF regeneration failed for entry ' . $entry['id'] );
			$this->add_edit_note( $entry['id'], 'PDF regeneration failed - check debug logs for details', 'error' );
		}

		return $pdf_success;
	}

	/**
	 * Add entry note for edit activities
	 */
	public function add_edit_note( $entry_id, $note, $note_type = 'success' ) {
		if ( class_exists( 'GFAPI' ) ) {
			GFAPI::add_note( $entry_id, 'gpep-edit-entry', 'Edit System', $note, 'edit-entry', $note_type );
		}
	}

	/**
	 * Transfer upload fields from edit form entry to original entry
	 */
	public function transfer_upload_fields( $edit_entry, $edit_form ) {
		try {
			// Get the original entry ID from the token.
			if ( ! isset( $_GET['gpep_token'] ) || ! function_exists( 'gp_easy_passthrough' ) ) {
				return;
			}

			$token          = sanitize_text_field( $_GET['gpep_token'] );
			$original_entry = gp_easy_passthrough()->get_entry_for_token( $token );

			if ( ! $original_entry ) {
				error_log( 'GPEP_Edit_Entry: Could not find original entry for upload transfer' );
				return;
			}

			// Get field mapping.
			$feeds = GFAPI::get_feeds( null, $edit_form['id'], 'gp-easy-passthrough' );
			if ( empty( $feeds ) ) {
				error_log( 'GPEP_Edit_Entry: No GP Easy Passthrough feed found for upload transfer' );
				return;
			}

			$feed                = $feeds[0];
			$uploads_transferred = false;

			error_log( 'GPEP_Edit_Entry: Starting upload field transfer from edit entry ' . $edit_entry['id'] . ' to original entry ' . $original_entry['id'] );

			// Check each field in the edit form for uploads
			foreach ( $edit_form['fields'] as $field ) {
				if ( $field->type === 'fileupload' ) {
					$field_key = 'fieldMap_' . $field->id;

					if ( isset( $feed['meta'][ $field_key ] ) ) {
						$original_field_id = $feed['meta'][ $field_key ];

						// Check if there's a new upload in the edit entry
						if ( isset( $edit_entry[ $field->id ] ) && ! empty( $edit_entry[ $field->id ] ) ) {
							$new_upload = $edit_entry[ $field->id ];
							$old_upload = isset( $original_entry[ $original_field_id ] ) ? $original_entry[ $original_field_id ] : '';

							error_log( 'GPEP_Edit_Entry: Upload field ' . $field->id . ' -> ' . $original_field_id );
							error_log( 'GPEP_Edit_Entry: Old upload: ' . $old_upload );
							error_log( 'GPEP_Edit_Entry: New upload: ' . $new_upload );

							// Update the original entry with the new upload
							$original_entry[ $original_field_id ] = $new_upload;
							$uploads_transferred                  = true;

							error_log( 'GPEP_Edit_Entry: Transferred upload from field ' . $field->id . ' to original field ' . $original_field_id );
						}
					}
				}
			}

			// Save the updated original entry if uploads were transferred
			if ( $uploads_transferred ) {
				$result = GFAPI::update_entry( $original_entry );

				if ( is_wp_error( $result ) ) {
					error_log( 'GPEP_Edit_Entry: Failed to update original entry with uploads: ' . $result->get_error_message() );
					$this->add_edit_note( $edit_entry['id'], 'Upload transfer failed: ' . $result->get_error_message(), 'error' );
				} else {
					error_log( 'GPEP_Edit_Entry: Successfully transferred uploads to original entry ' . $original_entry['id'] );
					$this->add_edit_note( $edit_entry['id'], 'Upload fields transferred to original entry successfully', 'success' );
				}
			} else {
				error_log( 'GPEP_Edit_Entry: No upload fields to transfer' );
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Upload transfer error: ' . $e->getMessage() );
			$this->add_edit_note( $edit_entry['id'], 'Upload transfer error: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Send edit confirmation email immediately using existing Brevo code
	 */
	public function send_edit_confirmation_email_now( $entry ) {
		try {
			if ( class_exists( 'GF_Checkout_Com_Brevo_Data' ) ) {
				try {
					$brevo_data = new GF_Checkout_Com_Brevo_Data();

					// Use existing payment confirmation email method (it handles notes automatically)
					$brevo_data->send_payment_confirmation_email( $entry );

					error_log( 'GPEP_Edit_Entry: Edit confirmation email processed for entry ' . $entry['id'] );
				} catch ( Exception $e ) {
					error_log( 'GPEP_Edit_Entry: Brevo email error: ' . $e->getMessage() );
					$this->add_edit_note( $entry['id'], 'Edit confirmation email error: ' . $e->getMessage(), 'error' );
				}
			} else {
				error_log( 'GPEP_Edit_Entry: GF_Checkout_Com_Brevo_Data class not found' );
				$this->add_edit_note( $entry['id'], 'Edit confirmation email failed: Brevo class not found', 'error' );
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Email function fatal error: ' . $e->getMessage() );
			// Fail silently to prevent crash
		}
	}
}

// // Configurations
// new GPEP_Edit_Entry(
// array(
// 'form_id'        => 1,   // Set this to the form ID.
// 'delete_partial' => false, // Set this to false if you wish to preserve partial entries after an edit is submitted.
// 'refresh_token'  => false,  // Set this to true to generate a fresh Easy Passthrough token after updating an entry.
// 'process_feeds'  => false,  // Set this to true to process all feed addons on Edit Entry, or provide a comma separated list of addon slugs like 'gravityformsuserregistration', etc.
// )
// );


add_action(
	'init',
	function () {
		if ( class_exists( 'GPEP_Edit_Entry' ) ) {
			// Use DUPLICATE form IDs (edit forms), NOT original form IDs.
			$edit_forms = array(
				11, // Edit form for original form 1.
				13, // Edit form for original form 4.
				12, // Edit form for original form 5.
				14, // Edit form for original form 6.
			);

			foreach ( $edit_forms as $form_id ) {
				new GPEP_Edit_Entry(
					array(
						'form_id'        => $form_id,
						'refresh_token'  => true,  // Allow multiple edits.
						'process_feeds'  => true,  // Enable PDF regeneration and email sending.
						'delete_partial' => false,
					)
				);
			}
		} else {
			error_log( 'GPEP_Edit_Entry: Class not found! GP Easy Passthrough may not be active.' );
		}
	}
);

// Restrict fields in edit forms - make email and product fields read-only.
add_filter(
	'gform_pre_render',
	function ( $form ) {
		// Only apply to edit forms.
		if ( ! in_array( $form['id'], array( 11, 13, 12, 14 ) ) ) {
			return $form;
		}

		// Only apply when gpep_token is present (edit mode).
		if ( ! isset( $_GET['gpep_token'] ) ) {
			return $form;
		}

		foreach ( $form['fields'] as &$field ) {
			// Make email fields read-only.
			if ( $field->type === 'email' ) {
				$field->isRequired  = false; // Remove required to avoid validation issues.
				$field->cssClass    = ( $field->cssClass ? $field->cssClass . ' ' : '' ) . 'gf-readonly';
				$field->inputMask   = false;
				$field->placeholder = 'Email cannot be changed';
			}

			// Make product fields read-only (pre-selected from original entry)
			if ( in_array( $field->type, array( 'product', 'option', 'quantity', 'shipping', 'total' ) ) ) {
				$field->cssClass = ( $field->cssClass ? $field->cssClass . ' ' : '' ) . 'gf-readonly-product';
			}
		}

		return $form;
	}
);

// Add CSS to make product fields read-only.
add_action(
	'wp_head',
	function () {
		if ( isset( $_GET['gpep_token'] ) ) {
			echo '<style>
		.gf-readonly input[type="email"] {
			background-color: #f5f5f5 !important;
			color: #666 !important;
			cursor: not-allowed !important;
			pointer-events: none !important;
		}
		.gf-readonly-product input, .gf-readonly-product select {
			background-color: #f5f5f5 !important;
			color: #666 !important;
			cursor: not-allowed !important;
			pointer-events: none !important;
		}
		.gf-readonly-product .ginput_total {
			background-color: #f5f5f5 !important;
			color: #666 !important;
		}
		</style>';
		}
	}
);



// Check if edit token has been used (one-time use validation)
add_filter(
	'gform_pre_render',
	function ( $form ) {
		// Only apply to edit forms with gpep_token.
		if ( ! in_array( $form['id'], array( 11, 13, 12, 14 ) ) || ! isset( $_GET['gpep_token'] ) ) {
			return $form;
		}

		// Check if token is valid.
		$token = sanitize_text_field( $_GET['gpep_token'] );
		if ( function_exists( 'gp_easy_passthrough' ) ) {
			$entry = gp_easy_passthrough()->get_entry_for_token( $token );

			// If no entry found, token is invalid or has been deleted.
			if ( ! $entry ) {
				error_log( 'GPEP_Edit_Entry: Invalid or deleted token: ' . $token );
				add_filter(
					'the_content',
					function ( $content ) {
						if ( is_page() && in_the_loop() && is_main_query() ) {
							return '<div class="gform_confirmation_message">
						<h3>Edit Link No Longer Valid</h3>
						<p>This edit link is no longer valid. Edit links can only be used once for security reasons.</p>
						<p>If you need to make additional changes, please contact support or request a new edit link.</p>
                        </div>';
						}
						return $content;
					}
				);
				return array(); // Return empty form to prevent rendering.
			}

			// Additional check for token usage timestamp.
			$token_used = gform_get_meta( $entry['id'], 'fg_easypassthrough_token_used' );
			if ( $token_used ) {
				error_log( 'GPEP_Edit_Entry: Token already used at: ' . $token_used );
				add_filter(
					'the_content',
					function ( $content ) use ( $token_used ) {
						if ( is_page() && in_the_loop() && is_main_query() ) {
							return '<div class="gform_confirmation_message">
						<h3>Edit Link Already Used</h3>
                            <p>This edit link was already used on ' . date( 'F j, Y \a\t g:i A', strtotime( $token_used ) ) . ' and is no longer valid.</p>
						<p>Each edit link can only be used once for security reasons. If you need to make additional changes, please contact support.</p>
                        </div>';
						}
						return $content;
					}
				);
				return array(); // Return empty form to prevent rendering.
			}
		}

		return $form;
	}
);

// Token validation for all edit forms
add_action(
	'init',
	function () {
		try {
			// Only check on edit form pages.
			if ( ! is_page( array( 'edit-new-passport', 'edit-lost-stolen-passport', 'edit-passport-renewal', 'edit-passport-corrections' ) ) || is_admin() ) {
				return;
			}

			// Check if token is provided.
			if ( ! isset( $_GET['gpep_token'] ) || empty( $_GET['gpep_token'] ) ) {
				wp_die(
					'<h1>Invalid Access</h1><p>Missing Tokens, Please use the edit link provided in your email.</p><script>setTimeout(function(){ window.location.href = "' . home_url() . '"; }, 3000);</script>',
					'Invalid Access',
					array( 'response' => 403 )
				);
			}

			$token = sanitize_text_field( $_GET['gpep_token'] );

			// Validate token if GP Easy Passthrough is available.
			if ( function_exists( 'gp_easy_passthrough' ) ) {
				try {
					$entry = gp_easy_passthrough()->get_entry_for_token( $token );

					if ( ! $entry ) {
						wp_die(
							'<h1>Invalid or Expired Token</h1><p>This edit link has expired or been used. Please use the latest link from your email.</p><script>setTimeout(function(){ window.location.href = "' . home_url() . '"; }, 3000);</script>',
							'Invalid Token',
							array( 'response' => 403 )
						);
					}
				} catch ( Exception $e ) {
					error_log( 'GPEP_Edit_Entry: Token validation error: ' . $e->getMessage() );
					wp_die(
						'<h1>System Error</h1><p>Unable to validate edit link. Please try again later.</p><script>setTimeout(function(){ window.location.href = "' . home_url() . '"; }, 3000);</script>',
						'System Error',
						array( 'response' => 500 )
					);
				}
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Token validation fatal error: ' . $e->getMessage() );
			// Fail silently to prevent site crash
		}
	}
);

/**
 * Load edit form script for edit forms only
 */
function load_edit_form_script() {
	wp_enqueue_script( 'gf-checkout-com-edit-form-script', plugins_url( 'public/js/edit_form_script.js', __FILE__ ), array( 'jquery' ), GF_CHECKOUT_COM_VERSION, true );
}

// Manual pre-fill for all edit forms when GP Easy Passthrough fails
add_filter(
	'gform_pre_render',
	function ( $form ) {
		try {
			// Only apply to edit forms
			if ( ! in_array( $form['id'], array( 11, 13, 12, 14 ) ) ) {
				return $form;
			}

			// Load edit form script when edit form is rendered
			load_edit_form_script();

			if ( isset( $_GET['gpep_token'] ) && function_exists( 'gp_easy_passthrough' ) ) {
				$token = sanitize_text_field( $_GET['gpep_token'] );
				$entry = gp_easy_passthrough()->get_entry_for_token( $token );

				if ( $entry ) {
					// DEBUG: Print entry data for debugging
					// error_log( 'GPEP_Edit_Entry: Entry data for debugging: ' . print_r( $entry, true ) );

					// Get field mapping from GP Easy Passthrough feed for current form
					$feeds = GFAPI::get_feeds( null, $form['id'], 'gp-easy-passthrough' );
					if ( ! empty( $feeds ) ) {
						$feed = $feeds[0];

						// DEBUG: Print field mapping
						// error_log( 'GPEP_Edit_Entry: Field mapping for form ' . $form['id'] . ': ' . print_r( $feed['meta'], true ) );

						// Pre-fill form fields using field mapping
						foreach ( $form['fields'] as &$field ) {
							// Handle simple fields (no inputs)
							if ( empty( $field->inputs ) ) {
								$field_key = 'fieldMap_' . $field->id;
								if ( isset( $feed['meta'][ $field_key ] ) ) {
									$source_field_id = $feed['meta'][ $field_key ];
									if ( is_scalar( $source_field_id ) && isset( $entry[ $source_field_id ] ) ) {

										// Special handling for product option fields
										if ( $field->type === 'option' ) {
											error_log( 'GPEP_Edit_Entry: Processing option field ' . $field->id . ', source: ' . $source_field_id . ', value: ' . $entry[ $source_field_id ] );

											// Only set choices as selected if they have actual values
											if ( ! empty( $entry[ $source_field_id ] ) ) {
												$selected_values = is_array( $entry[ $source_field_id ] ) ? $entry[ $source_field_id ] : array( $entry[ $source_field_id ] );

												foreach ( $field->choices as &$choice ) {
													$choice['isSelected'] = in_array( $choice['value'], $selected_values );
													error_log( 'GPEP_Edit_Entry: Choice "' . $choice['text'] . '" (' . $choice['value'] . ') selected: ' . ( $choice['isSelected'] ? 'YES' : 'NO' ) );
												}
											} else {
												// No options were selected, ensure all are unselected
												foreach ( $field->choices as &$choice ) {
													$choice['isSelected'] = false;
													error_log( 'GPEP_Edit_Entry: Choice "' . $choice['text'] . '" set to unselected (no value in entry)' );
												}
											}
										} elseif ( $field->type === 'product' ) {
											// Regular product field
											if ( ! empty( $entry[ $source_field_id ] ) ) {
												$field->defaultValue = $entry[ $source_field_id ];
												error_log( 'GPEP_Edit_Entry: Set product field ' . $field->id . ' = ' . $entry[ $source_field_id ] );
											}
										} elseif ( $field->type === 'fileupload' || $field->type === 'upload' ) {
											// File upload field - show existing files
											if ( ! empty( $entry[ $source_field_id ] ) ) {
												$field->defaultValue = $entry[ $source_field_id ];
												error_log( 'GPEP_Edit_Entry: Set upload field ' . $field->id . ' = ' . $entry[ $source_field_id ] );

												// For multi-file uploads, ensure it's treated as an array
												if ( $field->multipleFiles && ! is_array( $field->defaultValue ) ) {
													$field->defaultValue = json_decode( $field->defaultValue, true );
													if ( ! is_array( $field->defaultValue ) ) {
														$field->defaultValue = array( $entry[ $source_field_id ] );
													}
												}
											}
										} else {
											// Regular field
											if ( ! empty( $entry[ $source_field_id ] ) ) {
												$field->defaultValue = $entry[ $source_field_id ];
											}
										}
									}
								}
							} else {
								// Handle complex fields with inputs (Name, Address, Product Options, etc.)
								if ( $field->type === 'option' ) {

									// First, ensure all choices are unselected by default
									foreach ( $field->choices as &$choice ) {
										$choice['isSelected'] = false;
									}

									// Handle checkbox-style product options
									foreach ( $field->inputs as &$input ) {
										$input_key = 'fieldMap_' . $input['id'];
										if ( isset( $feed['meta'][ $input_key ] ) ) {
											$source_field_id = $feed['meta'][ $input_key ];
											error_log( 'GPEP_Edit_Entry: Checking input ' . $input['id'] . ' mapped to source ' . $source_field_id );

											if ( isset( $entry[ $source_field_id ] ) && ! empty( $entry[ $source_field_id ] ) ) {
												// This option was selected in the original entry
												error_log( 'GPEP_Edit_Entry: Found selected option: ' . $entry[ $source_field_id ] );

												// Find and mark the corresponding choice as selected
												$option_value = $entry[ $source_field_id ];
												foreach ( $field->choices as &$choice ) {
													// Match by text or value in the stored option
													if ( strpos( $option_value, $choice['text'] ) !== false ||
													strpos( $option_value, $choice['value'] ) !== false ||
													$choice['value'] === $option_value ) {
														$choice['isSelected'] = true;
														error_log( 'GPEP_Edit_Entry: Marked choice "' . $choice['text'] . '" (' . $choice['value'] . ') as SELECTED' );
													}
												}

												// Set the input default value
												$input['defaultValue'] = $option_value;
												error_log( 'GPEP_Edit_Entry: Set option input ' . $input['id'] . ' = ' . $option_value );
											} else {
												// This option was NOT selected - ensure input is empty
												$input['defaultValue'] = '';
												error_log( 'GPEP_Edit_Entry: Option input ' . $input['id'] . ' was NOT selected (empty value)' );
											}
										}
									}
								} else {
									// Handle other multi-input fields (Name, Address, etc.)
									foreach ( $field->inputs as &$input ) {
										// Convert input ID format: 26.3 becomes fieldMap_26_3
										$input_key = 'fieldMap_' . str_replace( '.', '_', $input['id'] );
										if ( isset( $feed['meta'][ $input_key ] ) ) {
											$source_input_id = $feed['meta'][ $input_key ];
											if ( is_scalar( $source_input_id ) && isset( $entry[ $source_input_id ] ) && ! empty( $entry[ $source_input_id ] ) ) {
												$input['defaultValue'] = $entry[ $source_input_id ];

												// Special handling for confirm email - copy from main email.
												if ( $field->type === 'email' && strpos( $input['label'], 'Confirm' ) !== false ) {
													// Find the main email value and copy it.
													foreach ( $field->inputs as $main_input ) {
														if ( strpos( $main_input['label'], 'Confirm' ) === false ) {
															$main_email_key = 'fieldMap_' . str_replace( '.', '_', $main_input['id'] );
															if ( isset( $feed['meta'][ $main_email_key ] ) ) {
																$main_email_source = $feed['meta'][ $main_email_key ];
																if ( isset( $entry[ $main_email_source ] ) ) {
																	$input['defaultValue'] = $entry[ $main_email_source ];
																	error_log( 'Confirm email field ' . $input['id'] . ' = ' . $entry[ $main_email_source ] );
																}
															}
															break;
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Pre-fill error: ' . $e->getMessage() );
			// Return form unchanged to prevent crash
		}
		return $form;
	}
);

// Additional hook to ensure product options are correctly set (runs after other GF processing)
add_filter(
	'gform_pre_render',
	function ( $form ) {
		try {
			// Only apply to edit forms with token
			if ( ! in_array( $form['id'], array( 11, 13, 12, 14 ) ) || ! isset( $_GET['gpep_token'] ) ) {
				return $form;
			}

			if ( function_exists( 'gp_easy_passthrough' ) ) {
				$token = sanitize_text_field( $_GET['gpep_token'] );
				$entry = gp_easy_passthrough()->get_entry_for_token( $token );

				if ( $entry ) {
					$feeds = GFAPI::get_feeds( null, $form['id'], 'gp-easy-passthrough' );
					if ( ! empty( $feeds ) ) {
						$feed = $feeds[0];

						// Focus only on product option fields
						foreach ( $form['fields'] as &$field ) {
							if ( $field->type === 'option' && ! empty( $field->inputs ) ) {

								// Reset all choices to unselected first
								foreach ( $field->choices as &$choice ) {
									$choice['isSelected'] = false;
								}

								// Check which options were actually selected in original entry
								$selected_options = array();
								foreach ( $field->inputs as $input ) {
									$input_key = 'fieldMap_' . $input['id'];
									if ( isset( $feed['meta'][ $input_key ] ) ) {
										$source_field_id = $feed['meta'][ $input_key ];
										if ( isset( $entry[ $source_field_id ] ) && ! empty( $entry[ $source_field_id ] ) ) {
											$selected_options[] = $entry[ $source_field_id ];
										}
									}
								}

								// Mark only the selected choices
								foreach ( $field->choices as &$choice ) {
									foreach ( $selected_options as $selected_option ) {
										if ( strpos( $selected_option, $choice['text'] ) !== false ||
										strpos( $selected_option, $choice['value'] ) !== false ||
										$choice['value'] === $selected_option ) {
											$choice['isSelected'] = true;
											break;
										}
									}

									if ( ! $choice['isSelected'] ) {
									}
								}
							}
						}
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: Final check error: ' . $e->getMessage() );
		}
		return $form;
	},
	20
); // Higher priority to run after other hooks

// Special handling for file upload fields - Gravity Forms needs this hook
add_filter(
	'gform_field_value',
	function ( $value, $field, $name ) {
		try {
			// Only apply to edit forms with token
			if ( ! in_array( $field->formId, array( 11, 13, 12, 14 ) ) || ! isset( $_GET['gpep_token'] ) ) {
				return $value;
			}

			// Only handle file upload fields
			if ( $field->type !== 'fileupload' ) {
				return $value;
			}

			if ( function_exists( 'gp_easy_passthrough' ) ) {
				$token = sanitize_text_field( $_GET['gpep_token'] );
				$entry = gp_easy_passthrough()->get_entry_for_token( $token );

				if ( $entry ) {
					$feeds = GFAPI::get_feeds( null, $field->formId, 'gp-easy-passthrough' );
					if ( ! empty( $feeds ) ) {
						$feed      = $feeds[0];
						$field_key = 'fieldMap_' . $field->id;

						if ( isset( $feed['meta'][ $field_key ] ) ) {
							$source_field_id = $feed['meta'][ $field_key ];

							if ( isset( $entry[ $source_field_id ] ) && ! empty( $entry[ $source_field_id ] ) ) {
								$file_value = $entry[ $source_field_id ];

								// Handle multiple files
								if ( $field->multipleFiles ) {
									// If it's JSON, decode it
									if ( is_string( $file_value ) && ( strpos( $file_value, '[' ) === 0 || strpos( $file_value, '{' ) === 0 ) ) {
										$decoded = json_decode( $file_value, true );
										if ( is_array( $decoded ) ) {
											return $decoded;
										}
									}
									// If it's a single file, make it an array
									return is_array( $file_value ) ? $file_value : array( $file_value );
								} else {
									// Single file upload
									return is_array( $file_value ) ? $file_value[0] : $file_value;
								}
							}
						}
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'GPEP_Edit_Entry: File upload pre-fill error: ' . $e->getMessage() );
		}

		return $value;
	},
	10,
	3
);

// Restrict fields in edit forms - make email and product fields read-only
add_filter(
	'gform_pre_render',
	function ( $form ) {
		// Only apply to edit forms
		if ( ! in_array( $form['id'], array( 11, 13, 12, 14 ) ) ) {
			return $form;
		}

		// Only apply when gpep_token is present (edit mode)
		if ( ! isset( $_GET['gpep_token'] ) ) {
			return $form;
		}

		foreach ( $form['fields'] as &$field ) {
			// Make email fields read-only
			if ( $field->type === 'email' ) {
				$field->isRequired = false; // Remove required to avoid validation issues
				$field->cssClass   = ( $field->cssClass ? $field->cssClass . ' ' : '' ) . 'gf-readonly';
			}

			// Make product fields read-only (pre-selected from original entry)
			if ( in_array( $field->type, array( 'product', 'option', 'quantity', 'shipping', 'total' ) ) ) {
				$field->cssClass = ( $field->cssClass ? $field->cssClass . ' ' : '' ) . 'gf-readonly-product';
			}
		}

		return $form;
	}
);

// Add CSS to make fields read-only
add_action(
	'wp_head',
	function () {
		if ( isset( $_GET['gpep_token'] ) ) {
			echo '<style>
		.gf-readonly input[type="email"] {
			background-color: #f5f5f5 !important;
			color: #666 !important;
			cursor: not-allowed !important;
			pointer-events: none !important;
		}
		.gf-readonly-product input, .gf-readonly-product select, .gf-readonly-product label {
			background-color: #f5f5f5 !important;
			color: #666 !important;
			cursor: not-allowed !important;
			pointer-events: none !important;
		}
		.gf-readonly-product .ginput_total {
			background-color: #f5f5f5 !important;
			color: #666 !important;
		}
		</style>';

			echo '<script>
		jQuery(document).ready(function($) {
			// Copy main email to confirm email field
			setTimeout(function() {
				$("input[type=email]").each(function() {
					var mainEmail = $(this);
					var confirmEmail = mainEmail.closest(".ginput_container_email").find("input[type=email]").eq(1);
					
					if (confirmEmail.length && confirmEmail.val() === "" && mainEmail.val() !== "") {
						confirmEmail.val(mainEmail.val());
						console.log("Copied email: " + mainEmail.val() + " to confirm field");
					}
				});
			}, 500);
		});
		</script>';
		}
	}
);
