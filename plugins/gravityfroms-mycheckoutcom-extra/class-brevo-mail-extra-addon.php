<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

GFForms::include_feed_addon_framework();

class GF_Brevo_Mail_Extra_AddOn extends GFFeedAddOn {

	protected $_version                  = GF_CHECKOUT_COM_VERSION;
	protected $_min_gravityforms_version = '2.3';
	protected $_slug                     = 'gf-brevo-mail-extra';
	protected $_path                     = 'gravityfroms-mycheckoutcom-extra/class-brevo-mail-extra-addon.php';
	protected $_full_path                = __FILE__;
	protected $_title                    = 'Gravity Forms - Brevo Mail Extra';
	protected $_short_title              = 'Brevo Mail Extra';

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GF_Brevo_Mail_Extra_AddOn
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GF_Brevo_Mail_Extra_AddOn();
		}
		return self::$_instance;
	}

	/**
	 * Hook into the payment completion action and the scheduled cron action.
	 */
	public function init() {
		parent::init();
	}

	/**
	 * Define the settings for a feed.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {

		$form_id = rgget( 'id' ); // Gravity Forms admin query: form ID.

		return array(
			array(
				'title'  => esc_html__( 'Brevo Email Feed Settings', 'gf-checkout-com' ),
				'fields' => array(
					array(
						'label'    => esc_html__( 'Feed Name', 'gf-checkout-com' ),
						'type'     => 'text',
						'name'     => 'feedName',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => '<h6>' . esc_html__( 'Feed Name', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Enter a name for this feed, for example, "Post-Payment Confirmation".', 'gf-checkout-com' ),
					),
					array(
						'label'    => esc_html__( 'Brevo Template ID', 'gf-checkout-com' ),
						'type'     => 'text',
						'name'     => 'brevoTemplateId',
						'required' => true,
						'class'    => 'small',
						'tooltip'  => '<h6>' . esc_html__( 'Template ID', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Enter the numeric ID of the transactional template you want to send from your Brevo account.', 'gf-checkout-com' ),
					),
					// --- MODIFIED: Added simple checkbox and removed conditional logic ---
					array(
						'label'   => esc_html__( 'Email Timing', 'gf-checkout-com' ),
						'type'    => 'checkbox',
						'name'    => 'delay30Minutes',
						'tooltip' => '<h6>' . esc_html__( 'Delay Email', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'If checked, the email will be sent 30 minutes after the payment is completed.', 'gf-checkout-com' ),
						'choices' => array(
							array(
								'label' => esc_html__( 'Send this email 30 minutes after payment completion.', 'gf-checkout-com' ),
								'name'  => 'delay30Minutes',
							),
						),
					),
					// NEW: Fillable PDFs dropdown.
					array(
						'label'       => esc_html__( 'Fillable PDF Feed', 'gf-checkout-com' ),
						'type'        => 'select',
						// 'multiple'    => true,
						'enhanced_ui' => true,
						'name'        => 'fillablePdfFeedId',
						'class'       => 'fillables-pdf-select',
						'tooltip'     => esc_html__( 'Choose which Fillable PDF template to attach to this feed.', 'gf-checkout-com' ),
						'choices'     => $this->populate_fillablepdf_dropdown( $form_id ),
					),
					array(
						'label'   => esc_html__( 'Need to send attachment link only', 'gf-checkout-com' ),
						'type'    => 'checkbox',
						'name'    => 'onlyattachmentlink',
						'tooltip' => '<h6>' . esc_html__( 'Only attachment link', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Select it if you need to send attachment link only intead of attachment', 'gf-checkout-com' ),
						'choices' => array(
							array(
								'label' => esc_html__( 'Need to send attachment link only', 'gf-checkout-com' ),
								'name'  => 'onlyattachmentlink',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Defines which columns appear in the feed list.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'        => esc_html__( 'Name', 'gf-checkout-com' ),
			'brevoTemplateId' => esc_html__( 'Brevo Template ID', 'gf-checkout-com' ),
		);
	}

	/**
	 * Returns Fillable PDF feed choices for a given form ID.
	 *
	 * @param int $formid Gravity Form ID.
	 * @return array Choices array for select dropdown.
	 */
	public function populate_fillablepdf_dropdown( $formid ) {
		$choices   = array();
		// add placeholder choice.
		$choices[] = array(
			'label' => '-- ' . esc_html__( 'Select a Fillable PDF', 'gf-checkout-com' ) . ' --',
			'value' => '',
		);
		$pdf_feeds = $this->get_fillablepdfs_feeds_for_form( $formid, true );

		if ( is_wp_error( $pdf_feeds ) || empty( $pdf_feeds ) ) {
			return array(
				array(
					'label' => '-- ' . esc_html__( 'No Fillable PDFs available', 'gf-checkout-com' ) . ' --',
					'value' => '',
				),
			);
		}
		foreach ( $pdf_feeds as $feed ) {
			$choices[] = array(
				'label' => $feed['feed_name'],
				'value' => $feed['id'],
			);
		}
		return $choices;
	}


	
	/**
	 * Retrieves all Fillable PDFs feeds for a given form.
	 *
	 * @param int       $form_id       Gravity Form ID.
	 * @param bool|null $is_active     True = active feeds, false = inactive, null = both.
	 * @return array|WP_Error          Array of feed objects (stdClass) or WP_Error.
	 */
	private function get_fillablepdfs_feeds_for_form( $form_id, $is_active = true ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'no_gfapi', 'GFAPI is not available.' );
		}

		if ( ! is_numeric( $form_id ) || $form_id <= 0 ) {
			return new WP_Error( 'invalid_form', 'Invalid form ID provided.' );
		}

		// The addon slug for Fillable PDFs.
		$addon_slug = 'forgravity-fillablepdfs';

		// Fetch feeds using GFAPI.
		$feeds = GFAPI::get_feeds(
			null,
			$form_id,
			$addon_slug,
			$is_active
		);
		if ( is_wp_error( $feeds ) || empty( $feeds ) ) {
			return array();
		}

		$output = array();

		foreach ( $feeds as $feed ) {
			$output[] = array(
				'id'         => intval( $feed['id'] ),
				'form_id'    => intval( $feed['form_id'] ),
				'is_active'  => (bool) $feed['is_active'],

				'feed_name'  => $feed['meta']['feedName'] ?? '',
				'addon_slug' => $feed['addon_slug'],
			);
		}

		return $output;
	}
}
