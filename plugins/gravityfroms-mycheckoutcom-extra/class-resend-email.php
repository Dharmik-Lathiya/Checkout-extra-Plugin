<?php
/**
 * Handles the manual resending of notifications from the entry detail page.
 *
 * @package    GravityForms
 * @subpackage CheckoutCom
 * @author     Your Name <you@example.com>
 * @copyright  2025 Your Name
 * @license    GPL-2.0+
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GF_Checkout_Com_Resend_Email
 *
 * Manages the UI and AJAX handling for resending emails from the entry detail screen.
 */
class GF_Checkout_Com_Resend_Email {

	/**
	 * The single instance of the class.
	 *
	 * @var GF_Checkout_Com_Resend_Email
	 */
	private static $_instance = null;

	/**
	 * Gets the single instance of this class.
	 *
	 * @return GF_Checkout_Com_Resend_Email
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * GF_Checkout_Com_Resend_Email constructor.
	 */
	public function __construct() {
		add_action( 'gform_entry_detail_meta_boxes', array( $this, 'add_resend_email_meta_box' ), 10, 3 );
		add_action( 'wp_ajax_gf_resend_notifications', array( $this, 'ajax_resend_notifications_handler' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueues scripts for the entry detail page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( ! class_exists( 'GFForms' ) || ! GFForms::is_gravity_page() || 'entry' !== rgget( 'view' ) ) {
			return;
		}

		wp_enqueue_style(
			'gf-checkout-com-admin-style',
			GF_CHECKOUT_COM_PLUGIN_URL . '/public/css/admin-style.css',
			array(),
			GF_CHECKOUT_COM_VERSION
		);

		wp_enqueue_script(
			'gf-checkout-com-resend-email',
			GF_CHECKOUT_COM_PLUGIN_URL . '/public/js/resend-email.js',
			array( 'jquery' ),
			GF_CHECKOUT_COM_VERSION,
			true
		);

		wp_localize_script(
			'gf-checkout-com-resend-email',
			'gf_resend_email_obj',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gf_resend_notifications_nonce' ),
				'entry_id' => absint( rgget( 'lid' ) ),
			)
		);
	}

	/**
	 * Adds the "Resend Notifications" meta box.
	 *
	 * @param array $meta_boxes The current array of meta boxes.
	 * @param array $entry      The current entry object.
	 * @param array $form       The current form object.
	 * @return array The modified array of meta boxes.
	 */
	public function add_resend_email_meta_box( $meta_boxes, $entry, $form ) {
		if ( 'Paid' !== rgar( $entry, 'payment_status' ) || 'checkout-com-proxy' !== rgar( $entry, 'payment_method' ) ) {
			return $meta_boxes;
		}

		$meta_boxes['gf_resend_notifications'] = array(
			'title'    => __( 'Resend Notifications', 'gf-checkout-com' ),
			'callback' => array( $this, 'render_meta_box_content' ),
			'context'  => 'side',
			'priority' => 'high',
		);
		return $meta_boxes;
	}

	/**
	 * Renders the HTML content for the meta box.
	 *
	 * @param array $args An array containing the form and entry objects.
	 */
	public function render_meta_box_content( $args ) {
		$form  = $args['form'];
		$entry = $args['entry'];

		$feeds = GFAPI::get_feeds( null, $form['id'], 'gf-brevo-mail-extra' );

		if ( empty( $feeds ) ) {
			echo '<p>' . esc_html__( 'No active Brevo email feeds are configured for this form.', 'gf-checkout-com' ) . '</p>';
			return;
		}
		?>
		<div id="gf-resend-notifications-container">
			<p><strong><?php esc_html_e( 'Select Brevo notifications to resend:', 'gf-checkout-com' ); ?></strong></p>
			
			<div class="gf-resend-notifications-list">
				<ul class="gfield_checkbox" id="gf_resend_notification_choices">
					<li class="gchoice">
						<input type="checkbox" id="gf_resend_select_all">
						<label for="gf_resend_select_all" style="font-weight: bold;"><?php esc_html_e( 'Select All', 'gf-checkout-com' ); ?></label>
					</li>
					<?php foreach ( $feeds as $feed ) : ?>
						<?php if ( ! empty( $feed['is_active'] ) ) : ?>
							<li class="gchoice">
								<input type="checkbox" name="notifications_to_send[]" value="<?php echo esc_attr( $feed['id'] ); ?>" id="gf_resend_choice_<?php echo esc_attr( $feed['id'] ); ?>">
								<label for="gf_resend_choice_<?php echo esc_attr( $feed['id'] ); ?>"><?php echo esc_html( rgars( $feed, 'meta/feedName' ) ); ?></label>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="gf-resend-email-field" style="margin-top: 15px;">
				<label for="gf-resend-email-address" style="font-weight:bold; display:block; margin-bottom: 5px;"><?php esc_html_e( 'Optional: Send to different email', 'gf-checkout-com' ); ?></label>
				<input type="email" id="gf-resend-email-address" class="widefat" placeholder="<?php echo esc_attr( rgar( $entry, '2' ) ); ?>">
			</div>

			<div class="gf-resend-email-actions" style="margin-top: 15px;">
				<button type="button" id="gf-resend-email-button" class="button button-primary" disabled><?php esc_html_e( 'Send Selected', 'gf-checkout-com' ); ?></button>
			</div>

			<div id="gf-resend-email-spinner" class="spinner" style="display:none; float:left; margin-top: 5px;"></div>
			<div id="gf-resend-email-feedback" style="margin-top:10px; padding: 10px; display: none;"></div>
		</div>
		<?php
	}

	/**
	 * Handles the AJAX request to resend notifications.
	 */
	public function ajax_resend_notifications_handler() {
		check_ajax_referer( 'gf_resend_notifications_nonce', 'nonce' );

		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission.', 'gf-checkout-com' ) ), 403 );
		}

		$entry_id       = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$feed_ids       = isset( $_POST['feed_ids'] ) && is_array( $_POST['feed_ids'] ) ? array_map( 'absint', $_POST['feed_ids'] ) : array();
		$override_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $entry_id ) || empty( $feed_ids ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing required data.', 'gf-checkout-com' ) ), 400 );
		}

		$entry = GFAPI::get_entry( $entry_id );

		// Final safeguard: Ensure the entry is valid before proceeding.
		if ( is_wp_error( $entry ) || ! isset( $entry['form_id'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The specified entry could not be found.', 'gf-checkout-com' ) ), 404 );
		}

		$form = GFAPI::get_form( $entry['form_id'] );

		foreach ( $feed_ids as $feed_id ) {
			$this->resend_brevo_feed( $entry, $form, $feed_id, $override_email );
		}

		wp_send_json_success( array( 'message' => __( 'Process complete. See entry notes for details. The page will now reload.', 'gf-checkout-com' ) ) );
	}

	/**
	 * Resends a specific Brevo feed email.
	 *
	 * @param array       $entry          The entry object.
	 * @param array       $form           The form object.
	 * @param int         $feed_id        The ID of the feed to resend.
	 * @param string|null $override_email An optional email to send to instead of the entry's email.
	 */
	private function resend_brevo_feed( $entry, $form, $feed_id, $override_email = null ) {
		$brevo_data_handler = new GF_Checkout_Com_Brevo_Data();
		$feed               = GFAPI::get_feed( $feed_id );
		$brevo_addon        = GF_Brevo_Mail_Extra_AddOn::get_instance();
		$recipient_email    = ( ! empty( $override_email ) && is_email( $override_email ) ) ? $override_email : rgar( $entry, '2' );

		if ( empty( $recipient_email ) || ! $feed || ! $feed['is_active'] || 'gf-brevo-mail-extra' !== $feed['addon_slug'] ) {
			return;
		}

		if ( ! $brevo_addon->is_feed_condition_met( $feed, $form, $entry ) ) {
			$brevo_data_handler->add_note( $entry['id'], sprintf( `Did not resend "%s" because the feed's conditional logic was not met.`, rgars( $feed, 'meta/feedName' ) ), 'info' );
			return;
		}

		$first_name = rgar( $entry, '1.3' );
		$last_name  = rgar( $entry, '1.6' );
		$to         = array(
			array(
				'email' => $recipient_email,
				'name'  => trim( "$first_name $last_name" ),
			),
		);

		$template_id = rgars( $feed, 'meta/brevoTemplateId' );
		$mailer      = new GF_Checkout_Com_Brevo_Mailer();
		$params      = $this->get_brevo_email_params( $entry, $form );
		$attachments = $this->get_brevo_email_attachments( $feed, $entry['id'], $params );

		$result = $mailer->send_transactional_email( $template_id, $to, $params, $attachments, $entry['id'], false ); // Always send immediately.
		if ( is_wp_error( $result ) ) {
			$brevo_data_handler->add_note( $entry['id'], sprintf( 'Failed to manually resend "%s" to %s. Error: %s', rgars( $feed, 'meta/feedName' ), $recipient_email, $result->get_error_message() ), 'error' );
		} else {
			$brevo_data_handler->add_note( $entry['id'], sprintf( 'Manually resent "%s" to %s.', rgars( $feed, 'meta/feedName' ), $recipient_email ), 'success' );
		}
	}

	/**
	 * Gathers parameters for the Brevo email.
	 *
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 * @return array An array of parameters for the Brevo template.
	 */
	private function get_brevo_email_params( $entry, $form ) {
		$params = array(
			'PRENOM'        => rgar( $entry, '1.3' ),
			'NOM'           => rgar( $entry, '1.6' ),
			'ORDER_DATE'    => gmdate( 'd F Y', strtotime( rgar( $entry, 'payment_date' ) ) ),
			'APP_REFF_CODE' => rgar( $entry, $this->find_field_id_by_label( $form, 'Application Unique ID' ) ),
			'ORDER_TOTAL'   => GFCommon::to_money( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) ),
		);

		// Add edit link using GP Easy Passthrough token
		if ( function_exists( 'gp_easy_passthrough' ) ) {
			// Check if refresh_token is enabled (allows multiple edits)
			$refresh_token_enabled = true; // Set to true to match your gpep-edit-entry.php config

			$token_used = gform_get_meta( $entry['id'], 'fg_easypassthrough_token_used' );
			error_log( '$token_used: ' . print_r( $token_used, true ) );

			if ( $token_used && ! $refresh_token_enabled ) {
				// Entry was already edited and refresh_token is disabled
				error_log( 'Resend Email: Entry ' . $entry['id'] . ' was already edited on ' . $token_used . ', not including edit link' );
				$params['EDIT_APP_LNK'] = ''; // Empty edit link
			} else {
				// Generate edit link (either first time or refresh_token enabled)
				if ( $token_used && $refresh_token_enabled ) {
					// Clear the used flag for multiple edits.
					gform_delete_meta( $entry['id'], 'fg_easypassthrough_token_used' );
					gform_delete_meta( $entry['id'], 'fg_easypassthrough_token' ); // Force new token generation
					error_log( 'Resend Email: Cleared token_used flag for entry ' . $entry['id'] . ' (refresh_token enabled)' );
				}

				$token = gp_easy_passthrough()->get_entry_token( $entry['id'] );

				// Define edit page URLs for different forms (point to EDIT pages with duplicate forms)
				$edit_pages = array(
					'1' => '/edit-new-passport/',        // Original Form 1 → Edit page with Form 11
					'4' => '/edit-lost-stolen-passport/', // Original Form 4 → Edit page with Form 13
					'5' => '/edit-passport-renewal/',     // Original Form 5 → Edit page with Form 12
					'6' => '/edit-passport-corrections/', // Original Form 6 → Edit page with Form 14
				);

				$form_id   = $entry['form_id'];
				$edit_page = isset( $edit_pages[ $form_id ] ) ? $edit_pages[ $form_id ] : '/edit-application/';
				$edit_url  = home_url( $edit_page . '?gpep_token=' . $token );
				error_log( '$edit_url: ' . print_r( $edit_url, true ) );
				$params['EDIT_APP_LNK'] = $edit_url;
			}
		}

		return $params;
	}

	/**
	 * Gathers attachments for the Brevo email.
	 *
	 * @param array $feed     The Brevo feed object.
	 * @param int   $entry_id The ID of the current entry.
	 * @param array $params   A reference to the parameters array, to which the DOWNLOAD_LINK may be added.
	 * @return array An array of attachment objects for the Brevo API.
	 */
	private function get_brevo_email_attachments( $feed, $entry_id, &$params ) {
		$attachments = array();
		if ( rgars( $feed, 'meta/fillablePdfFeedId' ) ) {
			$brevo_data_handler      = new GF_Checkout_Com_Brevo_Data();
			$pdf_feed_id             = rgars( $feed, 'meta/fillablePdfFeedId' );
			$attachments             = $brevo_data_handler->get_entry_pdf_links( $entry_id, $pdf_feed_id );
			$params['DOWNLOAD_LINK'] = $attachments[0]['url'] ?? '';
			if ( rgars( $feed, 'meta/onlyattachmentlink' ) ) {
				$attachments = array();
			}
		}
		return $attachments;
	}

	/**
	 * Helper to find a field ID by its admin label or regular label.
	 *
	 * @param array  $form  The form object.
	 * @param string $label The admin label or label of the field to find.
	 * @return int|null The field ID or null if not found.
	 */
	private function find_field_id_by_label( $form, $label ) {
		foreach ( $form['fields'] as $field ) {
			// Use rgar for safe access and Yoda conditions for strict comparison.
			if ( rgar( $field, 'adminLabel' ) === $label || rgar( $field, 'label' ) === $label ) {
				return rgar( $field, 'id' );
			}
		}
		return null;
	}
}

GF_Checkout_Com_Resend_Email::get_instance();
