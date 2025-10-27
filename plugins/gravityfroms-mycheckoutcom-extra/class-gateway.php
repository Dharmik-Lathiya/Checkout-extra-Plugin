<?php
if (! class_exists('GFForms')) {
	die();
}


use function WPML\PHP\Logger\error;

add_action( 'wp', array( 'GF_Checkout_Com', 'maybe_process_checkout_com_page' ), 5 );

GFForms::include_payment_addon_framework();

/**
 * Checkout.com payment gateway integration for Gravity Forms.
 *
 * @since 3.3.0
 */
class GF_Checkout_Com extends GFPaymentAddOn{

	protected $_version                  = GF_CHECKOUT_COM_VERSION;
	protected $_min_gravityforms_version = '2.3.0';

	protected $_slug                     = 'checkout-com';
	protected $_path                     = GF_CHECKOUT_COM_PLUGIN_PATH;
	protected $_full_path                = __FILE__;
	protected $_url       = 'https://wpgateways.com/products/checkout-com-gateway-gravity-forms/';

	protected $_title       = 'Checkout.com Add-On Extra';
	protected $_short_title = 'Checkout.com Extra';

	protected $_requires_credit_card   = false;
	protected $_supports_callbacks     = true;
	protected $_requires_smallest_unit = true;

	const CHECKOUT_COM_URL_LIVE = 'https://api.checkout.com/payments/';
	const CHECKOUT_COM_URL_TEST = 'https://api.sandbox.checkout.com/payments/';

	// Members plugin integration.
	protected $_capabilities = array('gravityforms_checkout_com', 'gravityforms_checkout_com_uninstall', 'gravityforms_checkout_com_plugin_page');

	// Permissions.
	protected $_capabilities_settings_page = 'gravityforms_checkout_com';
	protected $_capabilities_form_settings = 'gravityforms_checkout_com';
	protected $_capabilities_uninstall     = 'gravityforms_checkout_com_uninstall';
	protected $_capabilities_plugin_page   = 'gravityforms_checkout_com_plugin_page';

	private static $_instance = null;

	public static function get_instance()
	{
		if (null === self::$_instance) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function init_frontend()
	{
		parent::init_frontend();
		add_filter('gform_disable_post_creation', array($this, 'delay_post'), 10, 3);
	}

	public function delay_post($is_disabled, $form, $entry)
	{
		$feed            = $this->get_payment_feed($entry);
		$submission_data = $this->get_submission_data($feed, $form, $entry);

		if (! $feed || empty($submission_data['payment_amount'])) {
			return $is_disabled;
		}
		return ! rgempty('delayPost', $feed['meta']);
	}

	/**
	 * Initialize the gateway by setting up hooks and shortcodes.
	 *
	 * @return void
	 */
	public function init()
	{
		parent::init();

		// Register REST API endpoint for webhooks.
		add_action('rest_api_init', array($this, 'register_api_endpoints'));
		add_action('rest_api_init', array($this, 'register_webhook_endpoint_for_chekcout_com_extra'));

		add_shortcode('gf_checkout_payment_frame', array($this, 'render_payment_frame_shortcode'));

		// NEW: Register AJAX action for creating a Checkout.com session.
		add_action('wp_ajax_gf_checkout_com_create_session', array($this, 'ajax_create_checkout_session'));
		add_action('wp_ajax_nopriv_gf_checkout_com_create_session', array($this, 'ajax_create_checkout_session'));

		// NEW: Register AJAX action for updating currency.
		add_action('wp_ajax_gf_checkout_com_update_currency', array($this, 'ajax_update_entry_currency'));
		add_action('wp_ajax_nopriv_gf_checkout_com_update_currency', array($this, 'ajax_update_entry_currency'));
	}


	// Register REST API endpoint for webhooks.
	public function register_webhook_endpoint_for_chekcout_com_extra()
	{
		register_rest_route(
			'gf-checkout-com/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'process_webhook'),
				'permission_callback' => array($this, 'verify_webhook_signature'),
			)
		);
	}

	// Process webhook request.
	public function verify_webhook_signature( $request ) {
		$this->log_debug( __METHOD__ . '(): Verifying webhook signature' );

		$headers = $request->get_headers();

		$signature = isset( $headers['authorization'] ) ? $headers['authorization'][0] : '';

		if ( empty( $signature ) ) {
			$this->log_error( __METHOD__ . '(): Missing signature in webhook request' );
			return false;
		}

		$settings       = $this->get_plugin_settings();
		$webhook_secret = rgar( $settings, 'webhookSecretKey' );

		if ( empty( $webhook_secret ) ) {
			$this->log_error( __METHOD__ . '(): Webhook secret key not configured' );
			return false;
		}

		if ( $signature !== $webhook_secret ) {
			$this->log_error( __METHOD__ . '(): Invalid webhook signature' );
			return false;
		}

		$this->log_debug( __METHOD__ . '(): Webhook signature verified successfully' );
		return true;
	}

	public function process_webhook( $request ) {
		$this->log_debug( __METHOD__ . '(): Processing webhook request' );

		$payload = $request->get_json_params();
		// $this->log_debug( __METHOD__ . '(): Webhook payload: ' . print_r( $payload, true ) );

		// Extract payment data.
		$payment_id = rgar( $payload, 'id' );
		$type       = rgar( $payload, 'type' );

		if ( empty( $payment_id ) ) {
			$this->log_error( __METHOD__ . '(): Missing payment ID in webhook' );
			return new WP_Error( 'missing_payment_id', 'Missing payment ID in webhook', array( 'status' => 400 ) );
		}

		// Get metadata from the payment
		$metadata = $payload['data']['metadata'];
		$form_id  = $metadata['form_id'];
		$entry_id = $metadata['entry_id'];

		if ( empty( $form_id ) || empty( $entry_id ) ) {
			$this->log_error( __METHOD__ . '(): Missing form_id or entry_id in payment metadata' );
			return new WP_Error( 'missing_metadata', 'Missing form_id or entry_id in payment metadata', array( 'status' => 400 ) );
		}

		// Get entry
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			$this->log_error( __METHOD__ . '(): Unable to find entry: ' . $entry_id );
			return new WP_Error( 'entry_not_found', 'Entry not found', array( 'status' => 404 ) );
		}

		// Get feed
		$feed = $this->get_payment_feed( $entry );
		if ( ! $feed ) {
			$this->log_error( __METHOD__ . '(): Unable to find feed for entry: ' . $entry_id );
			return new WP_Error( 'feed_not_found', 'Feed not found', array( 'status' => 404 ) );
		}

		// Process payment status update
		$action = $this->process_webhook_action( $payload, $feed, $entry );

		if ( is_wp_error( $action ) ) {
			return $action;
		}

		// Process the action
		$result = $this->checkout_com_process_callback_action( $action );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => 'Webhook processed successfully',
			),
			200,
		);
	}

	public function process_webhook_action( $payload, $feed, $entry ) {
		$payment_id = $payload['data']['id'];
		$type       = $payload['type'];
		// $status           = $payload['data']['status'];
		$amount           = $payload['data']['amount'];
		$currency         = $payload['data']['currency'];
		$response_code    = $payload['data']['response_code'];
		$response_summary = $payload['data']['response_summary'];

		// Convert amount from smallest unit if needed
		if ( $amount ) {
			$amount = $this->get_amount_import( $amount, $currency );
		} else {
			$amount = rgar( $entry, 'payment_amount' );
		}

		$action                   = array();
		$action['entry_id']       = $entry['id'];
		$action['transaction_id'] = $payment_id;

		switch ( $type ) {
			case 'payment_approved':
			case 'payment_captured':
				$action['id']               = $payment_id . '_' . time();
				$action['type']             = 'complete_payment';
				$action['amount']           = $amount;
				$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
				$action['payment_method']   = 'checkout-com';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;
				break;

			case 'payment_declined':
			case 'payment_canceled':
			case 'payment_failed':
				$error_message           = $this->get_error_message( $response_code, $response_summary );
				$action['id']            = $payment_id;
				$action['type']          = 'fail_payment';
				$action['amount']        = $amount;
				$amount_formatted        = GFCommon::to_money( $amount, $entry['currency'] );
				$action['note']          = sprintf( __( 'Payment failed. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s', 'gf-checkout-com' ), $amount_formatted, $payment_id, $error_message );
				$action['error_message'] = sprintf( __( 'Payment failed. Reason: %s Please try again.', 'gf-checkout-com' ), $error_message );
				break;

			case 'payment_pending':
				$action['id']            = $payment_id . '_' . time();
				$action['type']          = 'add_pending_payment';
				$action['amount']        = $amount;
				$amount_formatted        = GFCommon::to_money( $amount, $entry['currency'] );
				$action['note']          = sprintf( __( 'Payment is pending. Amount: %1$s. Transaction ID: %2$s.', 'gf-checkout-com' ), $amount_formatted, $payment_id );
				$action['error_message'] = __( 'Your payment is currently pending, it will be updated in our system when we received a confirmation from our processor.', 'gf-checkout-com' );
				break;

			default:
				$this->log_error( __METHOD__ . '(): Unhandled webhook event type: ' . $type );
				return new WP_Error( 'unhandled_event', 'Unhandled webhook event type', array( 'status' => 400 ) );
		}

		return $action;
	}

	/**
	 * Register REST API endpoints for payment processing.
	 *
	 * @return void
	 */
	public function register_api_endpoints()
	{
		register_rest_route(
			'gf-checkout-proxy/v1',
			'/get-payment-details', // For Site B to get amount/currency.
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_get_payment_details'),
				'permission_callback' => '__return_true', // Security is handled by HMAC signature.
			)
		);
		register_rest_route(
			'gf-checkout-proxy/v1',
			'/callback', // Final callback from Site B (from webhook or 3DS verification).
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'process_sitea_callback'),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Define the plugin settings fields.
	 *
	 * @return array Array of settings fields for the plugin configuration.
	 */
	// ----- SETTINGS PAGES ----------//
	public function plugin_settings_fields() {
		$description = wpautop( sprintf( esc_html__( 'Live merchant accounts cannot be used in a sandbox environment, so to test the plugin, please make sure you are using a separate sandbox account. If you do not have a sandbox account, you can sign up for one from %1$shere%2$s.', 'gf-checkout-com' ), '<a href="https://www.checkout.com/get-test-account" target="_blank">', '</a>' ) );

		return array(
			array(
				'title'       => esc_html__( 'Checkout.com Account Information', 'gf-checkout-com' ),
				'description' => $description,
				'fields'      => array(
					array(
						'name'          => 'mode',
						'label'         => esc_html__( 'Mode', 'gf-checkout-com' ),
						'type'          => 'radio',
						'description'   => '<small>' . sprintf( esc_html__( 'Check the Checkout.com testing guide %1$shere%2$s.', 'gf-checkout-com' ), '<a href="https://docs.checkout.com/testing/test-card-numbers" target="_blank">', '</a>' ) . '</small>',
						'default_value' => 'test',
						'tooltip'       => esc_html( __( 'Select either Production or Sandbox mode for live accounts or Sandbox if you have a sandbox account.', 'gf-checkout-com' ) ),
						'choices'       => array(
							array(
								'label' => esc_html__( 'Live', 'gf-checkout-com' ),
								'value' => 'production',
							),
							array(
								'label' => esc_html__( 'Sandbox', 'gf-checkout-com' ),
								'value' => 'test',
							),
						),
						'horizontal'    => true,
					),
					array(
						'name'              => 'secretKey',
						'label'             => esc_html__( 'Secret Key', 'gf-checkout-com' ),
						'description'       => '<small>' . esc_html__( 'Get it from Settings → Channels → API Keys section.', 'gf-checkout-com' ) . '</small>',
						'type'              => 'text',
						'input_type'        => 'password',
						'required'          => true,
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'is_setting_valid' ),
					),
					array(
						'name'              => 'publicKey',
						'label'             => esc_html__( 'Public Key', 'gf-checkout-com' ),
						'description'       => '<small>' . esc_html__( 'Get it from Settings → Channels → API Keys section.', 'gf-checkout-com' ) . '</small>',
						'type'              => 'text',
						'required'          => true,
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'is_setting_valid' ),
					), // Add new field for Processing Channel ID.
					array(
						'name'              => 'processingChannelId',
						'label'             => esc_html__( 'Processing Channel ID', 'gf-checkout-com' ),
						'description'       => '<small>' . esc_html__( 'Get it from Settings → Channels → Processing Channels section.', 'gf-checkout-com' ) . '</small>',
						'type'              => 'text',
						'required'          => true,
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'is_setting_valid' ),
					),
					// Add webhook secret key field.
					array(
						'name'        => 'webhookSecretKey',
						'label'       => esc_html__( 'Webhook Secret Key', 'gf-checkout-com' ),
						'description' => '<small>' . esc_html__( 'Secret key for validating webhook requests. Set this same value in your Checkout.com dashboard.', 'gf-checkout-com' ) . '</small>',
						'type'        => 'text',
						'required'    => true,
						'input_type'  => 'password',
						'class'       => 'medium',
					),
					// Add webhook endpoint field.
					array(
						'name'          => 'webhookEndpoint',
						'label'         => esc_html__( 'Webhook Endpoint', 'gf-checkout-com' ),
						'description'   => '<small>' . esc_html__( 'Copy this URL to your Checkout.com dashboard webhook settings.', 'gf-checkout-com' ) . '</small>',
						'type'          => 'text',
						'readonly'      => true,
						'default_value' => rest_url( 'gf-checkout-com/v1/webhook' ),
						'class'         => 'medium',
					),
				),
			),
		);
	}






	public function is_valid_plugin_settings() {
		$settings = $this->get_plugin_settings();
		return $this->is_setting_valid( rgar( $settings, 'secretKey' ) ) && $this->is_setting_valid( rgar( $settings, 'publicKey' ) );
	}

	public function feed_list_message() {
		return parent::feed_list_message();
	}

	public function is_setting_valid( $value ) {
		return ! empty( $value );
	}

	public function is_valid_override_settings() {
		if ( $this->get_setting( 'apiSettingsEnabled' ) ) {
			return $this->is_setting_valid( $this->get_setting( 'overrideSecretKey' ) ) && $this->is_setting_valid( $this->get_setting( 'overridePublicKey' ) );
		}
		return false;
	}

	private function get_api_settings( $feed = false ) {

		$feed = ! $feed ? $this->current_feed : $feed;

		// for Checkout.com, each feed can have its own Secret Key and Public Key specified which overrides the master plugin one
		// use the custom settings if found, otherwise use the master plugin settings
		if ( rgars( $feed, 'meta/apiSettingsEnabled' ) ) {
			return array(
				'secret_key'            => rgars( $feed, 'meta/overrideSecretKey' ),
				'public_key'            => rgars( $feed, 'meta/overridePublicKey' ),
				'processing_channel_id' => rgars( $feed, 'meta/overrideProcessingChannelId' ),
				'webhook_secret_key'    => rgars( $feed, 'meta/overrideWebhookSecretKey' ),
				'mode'                  => rgars( $feed, 'meta/overrideMode' ),
			);
		} else {
			$settings = $this->get_plugin_settings();
			return array(
				'secret_key'            => rgar( $settings, 'secretKey' ),
				'public_key'            => rgar( $settings, 'publicKey' ),
				'processing_channel_id' => rgars( $settings, 'processingChannelId' ),
				'webhook_secret_key'    => rgar( $settings, 'webhookSecretKey' ),
				'mode'                  => rgar( $settings, 'mode' ),
			);
		}
	}

	/**
	 * Prevent feeds being listed or created if the api keys aren't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return $this->is_valid_plugin_settings();
	}

	/**
	 * Configure the feed settings fields.
	 *
	 * @return array The feed settings fields.
	 */
	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		// remove default options before adding custom.
		$default_settings = $this->remove_field( 'transactionType', $default_settings );
		$default_settings = $this->remove_field( 'options', $default_settings );

		$transaction_type = array(
			array(
				'name'     => 'transactionType',
				'label'    => esc_html__( 'Transaction Type', 'gravityforms' ),
				'type'     => 'select',
				'onchange' => "jQuery(this).parents('form').submit();",
				'choices'  => array(
					array(
						'label' => esc_html__( 'Select a transaction type', 'gravityforms' ),
						'value' => '',
					),
					array(
						'label' => esc_html__( 'Products and Services', 'gravityforms' ),
						'value' => 'product',
					),
				),
				'tooltip'  => '<h6>' . esc_html__( 'Transaction Type', 'gravityforms' ) . '</h6>' . esc_html__( 'Select a transaction type.', 'gravityforms' ),
			),
		);
		$default_settings = $this->add_field_after( 'feedName', $transaction_type, $default_settings );

		$auth_only        = array(
			'name'    => 'auth_only',
			'label'   => esc_html__( 'Auth Only', 'gf-checkout-com' ),
			'type'    => 'checkbox',
			'hidden'  => $this->get_setting( 'transactionType' ) != 'product',
			'tooltip' => '<h6>' . esc_html__( 'Auth Only', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Process credit card transactions as Authorize Only instead of Authorize and Capture.', 'gf-checkout-com' ),
			'choices' => array(
				array(
					'label' => esc_html__( 'Enable', 'gf-checkout-com' ),
					'name'  => 'auth_only',
				),
			),
		);
		$default_settings = $this->add_field_after( 'transactionType', $auth_only, $default_settings );

		$enable_3ds       = array(
			'name'    => '3ds_option',
			'label'   => esc_html__( '3D Secure', 'gf-checkout-com' ),
			'type'    => 'checkbox',
			'hidden'  => $this->get_setting( 'transactionType' ) != 'product',
			'tooltip' => '<h6>' . esc_html__( '3D Secure', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Process credit card transactions through 3D secure mechanism.', 'gf-checkout-com' ),
			'choices' => array(
				array(
					'label' => esc_html__( 'Enable', 'gf-checkout-com' ),
					'name'  => '3ds_option',
				),
			),
		);
		$default_settings = $this->add_field_after( 'auth_only', $enable_3ds, $default_settings );

		$form = $this->get_current_form();

		// Add post fields if form has a post.
		if ( GFCommon::has_post_field( $form['fields'] ) ) {
			$post_settings    = array(
				'name'    => 'post_payment_actions',
				'label'   => esc_html__( 'Post Payment Actions', 'gravityforms' ),
				'type'    => 'checkbox',
				'tooltip' => '<h6>' . esc_html__( 'Post Payment Actions', 'gravityforms' ) . '</h6>' . esc_html__( 'Select which actions should only occur after payment has been received.', 'gravityforms' ),
				'choices' => array(
					array(
						'label' => esc_html__( 'Create post only when payment is received.', 'gf-checkout-com' ),
						'name'  => 'delayPost',
					),
				),
			);
			$default_settings = $this->add_field_before( 'conditionalLogic', $post_settings, $default_settings );
		}

		if ( GFCommon::$version < 2.5 ) {
			$override_mode       = '#gaddon-setting-row-overrideMode';
			$override_secret_key = '#gaddon-setting-row-overrideSecretKey';
			$override_public_key = '#gaddon-setting-row-overridePublicKey';
		} else {
			$override_mode       = '#gform_setting_overrideMode';
			$override_secret_key = '#gform_setting_overrideSecretKey';
			$override_public_key = '#gform_setting_overridePublicKey';
		}

		$override_settings = array(
			array(
				'name'     => 'apiSettingsEnabled',
				'label'    => esc_html__( 'API Settings', 'gf-checkout-com' ),
				'type'     => 'checkbox',
				'tooltip'  => '<h6>' . esc_html__( 'API Settings', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Override the settings provided on the Checkout.com Settings page and use these instead for this feed.', 'gf-checkout-com' ),
				'onchange' => "if(jQuery(this).prop('checked')){
									jQuery('{$override_mode}').show();
									jQuery('{$override_secret_key}').show();
									jQuery('{$override_public_key}').show();
								} else {
									jQuery('{$override_mode}').hide();
									jQuery('{$override_secret_key}').hide();
									jQuery('{$override_public_key}').hide();
									jQuery('#overrideSecretKey').val('');
									jQuery('#overridePublicKey').val('');
									jQuery('i').removeClass('icon-check fa-check gf_valid');
								}",
				'choices'  => array(
					array(
						'label' => 'Override Default Settings',
						'name'  => 'apiSettingsEnabled',
					),
				),
			),
			array(
				'name'          => 'overrideMode',
				'label'         => esc_html__( 'Mode', 'gf-checkout-com' ),
				'type'          => 'radio',
				'default_value' => 'test',
				'hidden'        => ! $this->get_setting( 'apiSettingsEnabled' ),
				'tooltip'       => '<h6>' . esc_html__( 'Mode', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Select either Live or Sandbox mode to override the chosen mode on the Checkout.com Settings page.', 'gf-checkout-com' ),
				'choices'       => array(
					array(
						'label' => esc_html__( 'Live', 'gf-checkout-com' ),
						'value' => 'production',
					),
					array(
						'label' => esc_html__( 'Sandbox', 'gf-checkout-com' ),
						'value' => 'test',
					),
				),
				'horizontal'    => true,
			),
			array(
				'name'              => 'overrideSecretKey',
				'label'             => esc_html__( 'Secret Key', 'gf-checkout-com' ),
				'type'              => 'text',
				'class'             => 'medium',
				'hidden'            => ! $this->get_setting( 'apiSettingsEnabled' ),
				'tooltip'           => '<h6>' . esc_html__( 'Secret Key', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Enter a new value to override the Secret Key on the Checkout.com Settings page.', 'gf-checkout-com' ),
				'feedback_callback' => array( $this, 'is_valid_override_settings' ),
			),
			array(
				'name'              => 'overridePublicKey',
				'label'             => esc_html__( 'Public Key', 'gf-checkout-com' ),
				'type'              => 'text',
				'input_type'        => 'password',
				'class'             => 'medium',
				'hidden'            => ! $this->get_setting( 'apiSettingsEnabled' ),
				'tooltip'           => '<h6>' . esc_html__( 'Public Key', 'gf-checkout-com' ) . '</h6>' . esc_html__( 'Enter a new value to override the Public Key on the Checkout.com Settings page.', 'gf-checkout-com' ),
				'feedback_callback' => array( $this, 'is_valid_override_settings' ),
			),
		);
		$default_settings  = $this->add_field_after( 'conditionalLogic', $override_settings, $default_settings );

		return apply_filters( 'gform_checkout_com_addon_feed_settings_fields', $default_settings, $form );
	}

	/**
	 * Append the phone field to the default billing_info_fields added by the framework.
	 *
	 * @return array
	 */
	public function billing_info_fields() {

		$fields = parent::billing_info_fields();

		array_unshift(
			$fields,
			array(
				'name'     => 'lastName',
				'label'    => esc_html__( 'Last Name', 'gf-checkout-com' ),
				'required' => false,
			)
		);
		array_unshift(
			$fields,
			array(
				'name'     => 'firstName',
				'label'    => esc_html__( 'First Name', 'gf-checkout-com' ),
				'required' => false,
			)
		);

		$fields[] = array(
			'name'  => 'phone',
			'label' => esc_html__( 'Phone', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'reference',
			'label' => esc_html__( 'Order Reference', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'order_summary',
			'label' => esc_html__( 'Order Summary', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'udf1',
			'label' => esc_html__( 'User defined field 1', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'udf2',
			'label' => esc_html__( 'User defined field 2', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'udf3',
			'label' => esc_html__( 'User defined field 3', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'udf4',
			'label' => esc_html__( 'User defined field 4', 'gf-checkout-com' ),
		);
		$fields[] = array(
			'name'  => 'udf5',
			'label' => esc_html__( 'User defined field 5', 'gf-checkout-com' ),
		);

		return $fields;
	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}
		return array(
			'complete_payment'    => esc_html__( 'Payment Completed', 'gf-checkout-com' ),
			'fail_payment'        => esc_html__( 'Payment Failed', 'gf-checkout-com' ),
			'add_pending_payment' => esc_html__( 'Pending Payment Added', 'gf-checkout-com' ),
		);
	}

	// ------ SENDING TO Checkout.com -----------//

	public function redirect_url( $feed, $submission_data, $form, $entry ) {

		// Prepare payment amount.
		$payment_amount = rgar( $submission_data, 'payment_amount' );

		// if ( function_exists( 'get_current_user_id' ) && function_exists( 'current_user_can' ) ) {
		// 	if ( get_current_user_id() === 8 && current_user_can( 'manage_options' ) ) {
		// 		error_log( 'Original Payment Amount: ' . print_r( $payment_amount, true ) );
		// 		$this->log_debug( __METHOD__ . '(): Overriding payment amount to 1 for admin user ID 8.' );
		// 		$payment_amount = 1; // Set the amount to 1.
		// 		error_log( 'payment amount changed for Admin, Updated amount: ' . print_r( $payment_amount, ) );
		// 	}
		// }
		// // --- END ADMIN PRICE OVERRIDE ---

		// Updating lead's payment_status to Processing
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
		GFAPI::update_entry_property( $entry['id'], 'payment_amount', $payment_amount );

		$return_url = $this->return_url( $form['id'], $entry['id'] );

		$url = gf_apply_filters( 'gform_checkout_com_request', $form['id'], $return_url, $form, $entry, $feed, $submission_data );

		gform_update_meta( $entry['id'], 'checkout_com_payment_url', $url );
		gform_update_meta( $entry['id'], 'payment_amount', $payment_amount );
		gform_update_meta( $entry['id'], 'submission_data', $submission_data );

		$this->log_debug( __METHOD__ . "(): Sending to Checkout.com paymentbox: {$url}" );

		return $url;
	}

	public function get_submission_data( $feed, $form, $entry ) {
		$submission_data          = parent::get_submission_data( $feed, $form, $entry );
		$submission_data['entry'] = $entry;

		// --- BEGIN TEMPORARY MODIFICATION ---
		// // If the current user is an admin with ID 6, set the payment amount to $1 for testing.
		if ( function_exists( 'get_current_user_id' ) && function_exists( 'current_user_can' ) ) {
			if ( get_current_user_id() === 8 && current_user_can( 'manage_options' ) ) {
				$this->log_debug( __METHOD__ . '(): Overriding payment amount to $1 for admin user ID 8. Original amount: ' . rgar( $submission_data, 'payment_amount' ) );
				$submission_data['payment_amount'] = 1;
			}
			error_log( 'get_submission_data(): Overriding payment amount to $1 for admin user ID 8. Original amount: ' . rgar( $submission_data, 'payment_amount' ) );
		}
		// // --- END TEMPORARY MODIFICATION --

		return $submission_data;
	}

	public function return_url( $form_id, $lead_id, $type = false ) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_checkout_com_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( strpos( $server_port, '80' ) === false ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		if ( $type == 'cancel' ) {
			$url = remove_query_arg( array( 'gf_checkout_com_return', 'cko-session-id' ), $pageURL );
			return apply_filters( 'gform_checkout_com_cancel_url', $url, $form_id, $lead_id );
		}

		$ids_query  = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_checkout_com_return', $this->base64_encode( $ids_query ), $pageURL );

		$query = 'gf_checkout_com_return=' . $this->base64_encode( $ids_query );
		/**
		 * Filters Checkout.com's return URL, which is the URL that users will be sent to after completing the payment on Checkout.com's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string  $url  The URL to be filtered.
		 * @param int $form_id  The ID of the form being submitted.
		 * @param int $entry_id The ID of the entry that was just created.
		 * @param string $query The query string portion of the URL.
		 */
		return apply_filters( 'gform_checkout_com_return_url', $url, $form_id, $lead_id, $query );
	}

	public static function maybe_process_checkout_com_page() {
		$instance = self::get_instance();

		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}

		if ( $str = rgget( 'gf_checkout_com_return' ) ) {

			$str = $instance->base64_decode( $str );
			$instance->log_debug( __METHOD__ . '(): Payment page request received. Starting to process.' );

			parse_str( $str, $query );
			$result = $error = false;

			if ( wp_hash( 'ids=' . $query['ids'] ) != $query['hash'] ) {
				$instance->log_error( __METHOD__ . '(): Payment request invalid (hash mismatch). Aborting.' );
				return false;
			} else {
				list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

				$form  = GFAPI::get_form( $form_id );
				$entry = GFAPI::get_entry( $lead_id );
				$feed  = $instance->get_payment_feed( $entry );

				$payment_status = ! empty( $entry ) ? rgar( $entry, 'payment_status' ) : false;

				if ( $payment_status == 'Paid' ) {
					$instance->log_debug( __METHOD__ . '(): Entry is already marked as Paid. Skipping to confirmation message.' );
				}

				// --- CHANGE #1: We now ONLY look for 'cko_session_id' from our new Flow integration.
				// It can be in the $_GET array (from the success_url) or in $_POST (from our JS form submission).
				if ( ( rgget( 'cko-session-id' ) || rgpost( 'cko_session_id' ) ) && $payment_status != 'Paid' ) {
					$callback_action = $instance->checkout_com_callback( $form, $entry );
					$instance->log_debug( __METHOD__ . '(): Result from gateway callback => ' . print_r( $callback_action, true ) );
				}
			}

			if ( isset( $callback_action ) && is_wp_error( $callback_action ) ) {

				$error = $instance->authorization_error( $callback_action->get_error_message() );

			} elseif ( isset( $callback_action ) && is_array( $callback_action ) && rgar( $callback_action, 'type' ) && ! rgar( $callback_action, 'abort_callback' ) ) {

				$result = $instance->checkout_com_process_callback_action( $callback_action );

				$instance->log_debug( __METHOD__ . '(): Result of callback action => ' . print_r( $result, true ) );

				if ( is_wp_error( $result ) ) {
					$error = $instance->authorization_error( $result->get_error_message() );
				} elseif ( ! $result ) {
					$error = $instance->authorization_error( __( 'Unable to validate your payment, please try again.', 'gf-checkout-com' ) );
				} elseif ( rgar( $callback_action, 'type' ) != 'complete_payment' ) {
					$error = $instance->authorization_error( rgar( $callback_action, 'error_message' ) );
				} else {
					$instance->checkout_com_post_callback( $callback_action, $result );
				}
				// --- CHANGE #2: REMOVED the old 'elseif' that checked for 'payment_token'. It is no longer needed. ---
			}

			if ( ! class_exists( 'GFFormDisplay' ) ) {
				require_once GFCommon::get_base_path() . '/form_display.php';
			}

			// --- CHANGE #3: This condition decides whether to show the payment form again or proceed to confirmation.
			// It's updated to check for 'cko_session_id' instead of the old 'payment_token'.
			if ( $error || ! ( rgpost( 'cko_session_id' ) || rgget( 'cko_session_id' ) ) ) {
				$submission_data = gform_get_meta( $lead_id, 'submission_data' );
				ob_start();
				?>
				<div class="gform_wrapper">
					
					<?php if ( rgar( $error, 'error_message' ) ) { ?>
						<div class="validation_error"><?php echo rgar( $error, 'error_message' ); ?></div>
					<?php } ?>
					<?php echo $instance->checkout_com_paymentbox( $form, $entry ); ?><br />  
				<?php
				$confirmation                          = ob_get_clean();
				GFFormDisplay::$submission[ $form_id ] = array(
					'is_confirmation'      => true,
					'confirmation_message' => $confirmation,
					'form'                 => $form,
					'lead'                 => $entry,
				);
				return;
			}

			$confirmation = GFFormDisplay::handle_confirmation( $form, $entry, false );

			if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
				header( "Location: {$confirmation['redirect']}" );
				exit;
			}

			GFFormDisplay::$submission[ $form_id ] = array(
				'is_confirmation'      => true,
				'confirmation_message' => $confirmation,
				'form'                 => $form,
				'lead'                 => $entry,
			);

		}
	}

	private function checkout_com_process_callback_action( $action ) {
		$this->log_debug( __METHOD__ . '(): Processing callback action.' );
		$action = wp_parse_args(
			$action,
			array(
				'type'             => false,
				'amount'           => false,
				'transaction_type' => false,
				'transaction_id'   => false,
				'entry_id'         => false,
				'payment_status'   => false,
				'note'             => false,
			)
		);

		$result = false;

		if ( rgar( $action, 'id' ) && $this->is_duplicate_callback( $action['id'] ) ) {
			return new WP_Error( 'duplicate', sprintf( esc_html__( 'This callback has already been processed (Event Id: %s)', 'gravityforms' ), $action['id'] ) );
		}

		$entry = GFAPI::get_entry( $action['entry_id'] );
		if ( ! $entry || is_wp_error( $entry ) ) {
			return $result;
		}

		/**
		 * Performs actions before the the payment action callback is processed.
		 *
		 * @since Unknown
		 *
		 * @param array $action The action array.
		 * @param array $entry  The Entry Object.
		 */
		do_action( 'gform_action_pre_payment_callback', $action, $entry );
		if ( has_filter( 'gform_action_pre_payment_callback' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_action_pre_payment_callback.' );
		}

		switch ( $action['type'] ) {
			case 'complete_payment':
				// check already completed or not.
				if ( rgar( $entry, 'payment_status' ) === 'Paid' ) {
					$this->log_debug( __METHOD__ . '(): Payment already completed. Skipping.' );
					break;
				}
				$result = $this->complete_payment( $entry, $action );
				break;
			case 'fail_payment':
				// Prevent duplicate failure processing.
				if ( rgar( $entry, 'payment_status' ) === 'Failed' ) {
					$this->log_debug( __METHOD__ . '(): Payment already marked as failed. Skipping.' );
					break;
				}
				$result = $this->fail_payment( $entry, $action );
				break;
			case 'add_pending_payment':
				// Prevent duplicate pending payment processing.
				if ( rgar( $entry, 'payment_status' ) === 'Processing' || rgar( $entry, 'payment_status' ) === 'Pending' ) {
					$this->log_debug( __METHOD__ . '(): Payment already pending. Skipping.' );
					break;
				}
				$result = $this->add_pending_payment( $entry, $action );
				break;
			default:
				// Handle custom events.
				if ( is_callable( array( $this, rgar( $action, 'callback' ) ) ) ) {
					$result = call_user_func_array( array( $this, $action['callback'] ), array( $entry, $action ) );
				}
				break;
		}

		if ( rgar( $action, 'id' ) && $result ) {
			$this->register_callback( $action['id'], $action['entry_id'] );
		}

		/**
		 * Fires right after the payment callback.
		 *
		 * @since Unknown
		 *
		 * @param array $entry The Entry Object
		 * @param array $action {
		 *     The action performed.
		 *
		 *     @type string $type             The callback action type. Required.
		 *     @type string $transaction_id   The transaction ID to perform the action on. Required if the action is a payment.
		 *     @type string $amount           The transaction amount. Typically required.
		 *     @type int    $entry_id         The ID of the entry associated with the action. Typically required.
		 *     @type string $transaction_type The transaction type to process this action as. Optional.
		 *     @type string $payment_status   The payment status to set the payment to. Optional.
		 *     @type string $note             The note to associate with this payment action. Optional.
		 * }
		 * @param mixed $result The Result Object.
		 */
		do_action( 'gform_post_payment_callback', $entry, $action, $result );

		if ( has_filter( 'gform_post_payment_callback' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_post_payment_callback.' );
		}

		return $result;
	}

	public function get_payment_feed( $entry, $form = false ) {

		$feed = parent::get_payment_feed( $entry, $form );

		if ( empty( $feed ) && ! empty( $entry['id'] ) ) {
			// looking for feed created by legacy versions.
			$feed = $this->get_checkout_com_feed_by_entry( $entry['id'] );
		}

		$feed = apply_filters( 'gform_checkout_com_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form( $entry['form_id'] ) );

		return $feed;
	}

	private function get_checkout_com_feed_by_entry( $entry_id ) {
		$feed_id = gform_get_meta( $entry_id, 'checkout_com_feed_id' );
		$feed    = $this->get_feed( $feed_id );

		return ! empty( $feed ) ? $feed : false;
	}

	public function checkout_com_post_callback( $callback_action, $callback_result ) {
		if ( is_wp_error( $callback_action ) || ! $callback_action ) {
			return false;
		}

		// run the necessary hooks
		$entry          = GFAPI::get_entry( $callback_action['entry_id'] );
		$feed           = $this->get_payment_feed( $entry );
		$transaction_id = rgar( $callback_action, 'transaction_id' );
		$amount         = rgar( $callback_action, 'amount' );
		$error_message  = rgar( $callback_action, 'error_message' );

		// run gform_checkout_com_fulfillment only in certain conditions
		if ( rgar( $callback_action, 'ready_to_fulfill' ) ) {
			$this->fulfill_order( $entry, $transaction_id, $amount, $feed );
		} else {
			$this->log_debug( __METHOD__ . '(): Entry is already fulfilled or not ready to be fulfilled, not running gform_checkout_com_fulfillment hook.' );
		}

		do_action( 'gform_checkout_com_post_callback', $_GET, $entry, $feed, false );
		if ( has_filter( 'gform_checkout_com_post_callback' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_checkout_com_post_callback.' );
		}
	}

	/**
	 * Summary of checkout_com_paymentbox
	 *
	 * @param mixed $form
	 * @param mixed $entry
	 * @return array|string|null
	 */
	public function checkout_com_paymentbox( $form, $entry ) {
		$api_settings = $this->get_api_settings( $this->get_payment_feed( $entry ) );
		$public_key   = rgar( $api_settings, 'public_key' );
		$cancel_url   = $this->return_url( $form['id'], $entry['id'], 'cancel' );

		// Custom logic to make order summary.
		$order_summary = array();
		if ( $form['fields'] ) {
			$product_details = array();

			foreach ( $form['fields'] as $field ) {
				if ( 'product' === $field['type'] ) {
					$product_details['Product Name']  = $field['label'];
					$product_details['Product Price'] = $field['basePrice'];
				}
			}
			$order_summary['product_details'] = $product_details;
		}
		// --- NEW: Get available currencies for the dropdown ---
		$available_prices = $this->get_currency_specific_prices( $form['title'] );

		// Prepare the HTML output
		ob_start();
		?>
	<div class="Checkout-page-parent-container">
	<div id="checkout-loader-overlay" style="display: flex;">
					<div class="checkout-loader-spinner"></div>
				</div>	
	<!-- Keep your currency selector as is -->
		<?php if ( ! empty( $available_prices ) && count( $available_prices ) > 1 ) : ?>
		<div class="currency-selector-wrapper">
			<label for="currency-selector">Choose Currency:</label>
			<select id="currency-selector" name="currency_selector">
				<?php
				foreach ( $available_prices as $code => $details ) :
					$selected = ( strtoupper( $entry['currency'] ) === $code ) ? 'selected' : '';
					echo '<option value="' . esc_attr( $code ) . '" ' . $selected . '>' . esc_html( $code . ' (' . $details['sign'] . ')' ) . '</option>';
				endforeach;
				?>
			</select>
		</div>
		<?php endif; ?>

		<div class="checkout-payment-wrapper">
			<!-- Left: Payment Form -->
			<div class="checkout-payment-box">
				<!-- <div class="card-custom-header"> <span>Credit/Debit Card Information</span><img src="https://passportexpress.co/wp-content/uploads/2025/05/visa-mastercard-american-express.png"  alt="credit card image" width="160" height="50" /></div> -->
			<!-- This form is now just a shell to post the session ID back to GF -->
			<form method="post" id="gform_<?php echo $form['id']; ?>" data-entry-id="<?php echo $entry['id']; ?>" data-form-id="<?php echo $form['id']; ?>">
				
				
				<!-- Main container for Checkout Flow -->
				<div id="cko-payment-flow-container"></div>
				
				<div id="cko-flow-errors" role="alert" style="color: red; margin-top: 10px;"></div>

				<!-- Hidden field to hold the session ID -->
				<input type="hidden" id="cko_session_id" name="cko_session_id" value="" />
			</form></div>
			<div class="order-details-container">
				<!-- here i want to shoe $order_summary in this following formate -->
				<h3 class="order-details-container-heading">Order Summary</h3>
				<div class="order-details-container-details">
				
					<?php

					if ( $order_summary ) {
						// check if application unique id then show it.
						if ( ! empty( $order_summary['Application Unique ID'] ) ) {
							echo '<p class="order-application-no"><strong>Application Reference Code:</strong> ' . esc_html( $order_summary['Application Unique ID'] ) . '</p>';
						}

						// check if product details then show.
						if ( ! empty( $order_summary['product_details'] ) ) {
							echo '<p class="order-sub-detail"><strong>Product Details:</strong></p>';
							$name       = $order_summary['product_details']['Product Name'];
							$base_price = $order_summary['product_details']['Product Price'];
								echo '<p class="order-product-details">' . esc_html( $name ) . ':<strong class="order-product-amount"> ' . esc_html( GFCommon::to_money( $entry['payment_amount'], $entry['currency'] ) ) . '</strong></p>';

						}

						// Display the total. This part will now be updated dynamically by JavaScript.
						if ( ! empty( $entry['payment_amount'] ) ) {
							echo '<p class="order-total-amount"><strong>Total Amount: ' . GFCommon::to_money( $entry['payment_amount'], $entry['currency'] ) . '</strong></p>';
						}
					}

					?>
					<div class="notice-payment"><p><strong>Important:</strong>This is a one-time payment for a private service, not affilianted with any government agency. Government filing fees are not included.</p></div>
				</div>
			</div>
		</div></div>

		<?php
		// --- FINAL, CORRECT SCRIPT ENQUEUEING ---
		// 1. Load the correct library for Flow (Web Components).
		wp_enqueue_script( 'cko-web-components', 'https://checkout-web-components.checkout.com/index.js', array(), null, true );

		// 2. Make our custom script depend on the correct library.
		wp_enqueue_script(
			'gf-checkout-flow-script',
			plugin_dir_url( __FILE__ ) . 'public/js/gravityforms-checkout-flow.js',
			array( 'jquery', 'cko-web-components' ),
			GF_CHECKOUT_COM_VERSION,
			true
		);

		wp_localize_script(
			'gf-checkout-flow-script',
			'checkout_flow_vars',
			array(
				'publicKey'     => $public_key,
				'environment'   => rgar( $api_settings, 'mode', 'test' ) === 'production' ? 'production' : 'sandbox',
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'create_nonce'  => wp_create_nonce( 'gf_checkout_com_create_session' ),
				'update_nonce'  => wp_create_nonce( 'gf_checkout_com_update_currency' ),
				'entry_id'      => $entry['id'],
				'form_id'       => $form['id'],
				'error_message' => __( 'Could not initialize payment. Please refresh and try again.', 'gf-checkout-com' ),
			)
		);

		wp_enqueue_style( 'gf-checkout-com-extra-payment-page', plugins_url( 'public/css/payment_form_style.css', __FILE__ ), array(), GF_CHECKOUT_COM_VERSION );
		$output = ob_get_clean();
		$output = preg_replace( '/>\s+</', '><', $output );
		return $output;
	}

	public function checkout_com_callback( $form, $entry ) {

		if ( ! $entry ) {
			$this->log_error( __METHOD__ . '(): Entry could not be found. Aborting.' );
			return false;
		}
		$this->log_debug( __METHOD__ . '(): Entry has been found => ' . print_r( $entry, true ) );

		if ( 'spam' == $entry['status'] ) {
			$this->log_error( __METHOD__ . '(): Entry is marked as spam. Aborting.' );
			return false;
		}

		$feed = $this->get_payment_feed( $entry );

		if ( ! $feed || ! rgar( $feed, 'is_active' ) ) {
			$this->log_error( __METHOD__ . "(): Form no longer is configured with Checkout.com Addon. Form ID: {$entry['form_id']}. Aborting." );
			return false;
		}
		$this->log_debug( __METHOD__ . "(): Form {$entry['form_id']} is properly configured." );

		// The payment response now comes from verifying the session ID.
		$session_id = rgpost( 'cko_session_id' ) ? rgpost( 'cko_session_id' ) : rgget( 'cko-session-id' );

		if ( ! $session_id ) {
			$this->log_error( __METHOD__ . '(): No cko-session-id found in request. Aborting.' );
			return new WP_Error( 'missing_session_id', 'Payment session ID not found.' );
		}

		// Get payment details by verifying the session ID with the API.
		$payment_response = $this->get_payment_details_by_session( $session_id, $feed, $entry );

		if ( ! $payment_response || is_wp_error( $payment_response ) ) {
			$this->log_debug( __METHOD__ . '(): Could not verify payment session.' );
			return $payment_response;
		}

		// Save the session ID to the entry for reference/auditing.
		gform_update_meta( $entry['id'], 'checkout_com_session_id', $session_id );

		// The rest of the processing remains the same, using the verified response.
		$this->log_debug( __METHOD__ . '(): Processing Callback with verified data...' );
		$action = $this->process_callback( $feed, $entry, $payment_response );
		$this->log_debug( __METHOD__ . '(): Callback processing complete.' );

		if ( rgempty( 'entry_id', $action ) ) {
			return false;
		}

		return $action;
	}


		/**
		 * NEW FUNCTION: Verifies a payment session with the Checkout.com API.
		 *
		 * @param string $session_id The CKO session ID to verify.
		 * @param array  $feed       The Gravity Forms feed.
		 * @param array  $entry      The Gravity Forms entry.
		 * @return array|WP_Error    The verified payment response or an error.
		 */
	public function get_payment_details_by_session( $session_id, $feed, $entry ) {
		$this->log_debug( __METHOD__ . "(): Verifying session: {$session_id}" );
		$api_settings = $this->get_api_settings( $feed );

		$api_url = ( 'test' === $api_settings['mode'] ) ? self::CHECKOUT_COM_URL_TEST : self::CHECKOUT_COM_URL_LIVE;
		// The endpoint for GETTING payment details uses the payment ID (which is the session ID in this context)
		$api_url .= $session_id;

		$args = array(
			'method'  => 'GET',
			'headers' => array( 'Authorization' => 'Bearer ' . $api_settings['secret_key'] ),
			'timeout' => 30,
		);

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Security Check: Ensure the amount and currency from the verified payment
		// match what's stored in the Gravity Forms entry. This prevents tampering.
		$entry_amount_cents = $this->get_amount_export( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) );

		if ( ! isset( $body['amount'] ) || $body['amount'] != $entry_amount_cents || $body['currency'] != rgar( $entry, 'currency' ) ) {
			$this->log_error( __METHOD__ . '(): Session verification failed. Amount/currency mismatch.' );
			return new WP_Error( 'validation_error', 'Payment validation failed due to amount mismatch.' );
		}

		$this->log_debug( __METHOD__ . '(): Session verified successfully. Response: ' . print_r( $body, true ) );

		return $body;
	}

	public function process_callback( $feed, $entry, $payment_response ) {
		$amount = rgar( $entry, 'payment_amount' );

		$status         = rgar( $payment_response, 'status' );
		$transaction_id = rgar( $payment_response, 'id' );
		$response_code  = rgar( $payment_response, 'response_code' );
		$reference      = rgar( $payment_response, 'reference' );

		$action = array();

		switch ( strtolower( $status ) ) {

			case 'authorized':
			case 'captured':
			case 'paid':
			case 'card verified':
				$action['id']               = $transaction_id . '_' . $reference;
				$action['type']             = 'complete_payment';
				$action['transaction_id']   = $transaction_id;
				$action['amount']           = $amount;
				$action['entry_id']         = $entry['id'];
				$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
				$action['payment_method']   = 'checkout-com';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;

				$this->log_debug( __METHOD__ . "(): Payment status: Success - Transaction ID: {$transaction_id} - Reference: {$reference}" );
				return $action;
				break;

			case 'declined':
			case 'canceled':
				$response_summary = rgar( $payment_response, 'response_summary' );
				$response_summary = $this->get_error_message( $response_code, $response_summary );

				$action['id']             = $transaction_id;
				$action['type']           = 'fail_payment';
				$action['transaction_id'] = $transaction_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $amount;
				$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
				$action['note']           = sprintf( __( 'Payment failed. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s', 'gf-checkout-com' ), $amount_formatted, $transaction_id, $response_summary );
				$action['error_message']  = sprintf( __( 'Payment failed. Reason: %s Please try again.', 'gf-checkout-com' ), $response_summary );
				$this->log_debug( __METHOD__ . sprintf( __( 'Payment failed. Amount: %1$s. Transaction ID: %2$s. Reason: %3$s', 'gf-checkout-com' ), $amount_formatted, $transaction_id, $response_summary ) );
				return $action;
				break;

			case 'pending':
				$action['id']             = $transaction_id . '_' . $reference;
				$action['type']           = 'add_pending_payment';
				$action['transaction_id'] = $transaction_id;
				$action['amount']         = $amount;
				$action['entry_id']       = $entry['id'];
				$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
				$action['note']           = sprintf( __( 'Payment is pending. Amount: %1$s. Transaction ID: %2$s.', 'gf-checkout-com' ), $amount_formatted, $action['transaction_id'] );
				$action['error_message']  = __( 'Your payment is currently pending, it will be updated in our system when we received a confirmation from our processor.', 'gf-checkout-com' );

				$this->log_debug( __METHOD__ . "(): Payment status: Pending - Transaction ID: {$transaction_id} - Reference: {$reference}" );
				return $action;
				break;

		}
	}

	public function get_error_message( $reason_code, $default_message ) {

		switch ( $reason_code ) {
			case '20087':
				$message = esc_html__( 'Invalid CVV and/or expiry date.', 'gf-checkout-com' );
				break;

			case '20012':
				$message = esc_html__( 'The issuer has declined the transaction because it is invalid. The cardholder should contact their issuing bank.', 'gf-checkout-com' );
				break;

			case '20013':
				$message = esc_html__( 'Invalid amount or amount exceeds maximum for card program.', 'gf-checkout-com' );
				break;

			case '20003':
				$message = esc_html__( 'There was an error processing your credit card. Please verify the information and try again.', 'gf-checkout-com' );
				break;

			default:
				$message = $default_message;
		}

		$message = '<!-- Error: ' . $reason_code . ' --> ' . $message;

		return $message;
	}

	public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null ) {

		if ( ! $feed ) {
			$feed = $this->get_payment_feed( $entry );
		}

		$form = GFFormsModel::get_form_meta( $entry['form_id'] );

		if ( rgars( $feed, 'meta/delayPost' ) ) {
			$this->log_debug( __METHOD__ . '(): Creating post.' );
			$entry['post_id'] = GFFormsModel::create_post( $form, $entry );
			$this->log_debug( __METHOD__ . '(): Post created.' );
		}

		if ( method_exists( $this, 'trigger_payment_delayed_feeds' ) ) {
			$this->trigger_payment_delayed_feeds( $transaction_id, $feed, $entry, $form );
		}

		do_action( 'gform_checkout_com_fulfillment', $entry, $feed, $transaction_id, $amount );

		if ( has_filter( 'gform_checkout_com_fulfillment' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_checkout_com_fulfillment.' );
		}
	}

	public function get_post_payment_actions_config( $feed_slug ) {
		// We specify Checkout.com here for backwards capability, in case the Checkout.com add-on < 3.3
		// hasn't implemented get_post_payment_actions_config().
		$config = array(
			'position' => 'before',
			'setting'  => 'conditionalLogic',
		);
		return $config;
	}

	/**
	 * Check if the current entry was processed by this add-on.
	 *
	 * @param int $entry_id The ID of the current Entry.
	 *
	 * @return bool
	 */
	public function is_payment_gateway( $entry_id ) {
		if ( $this->is_payment_gateway ) {
			return true;
		}
		$gateway = gform_get_meta( $entry_id, 'payment_gateway' );
		return in_array( $gateway, array( $this->_short_title, $this->_slug ) );
	}

	public function get_country_code( $country ) {
		$country_list = GF_Fields::get( 'address' )->get_country_codes();
		$country      = GFCommon::safe_strtoupper( $country );

		if ( array_key_exists( $country, $country_list ) ) {
			return $country_list[ $country ];
		}
		return $country;
	}

	public function base64_encode( $string ) {
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $string ) );
	}

	public function base64_decode( $string ) {
		return base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $string ) );
	}

		/**
		 * Fetches currency-specific prices from WordPress options.
		 *
		 * @param string $form_title The title of the Gravity Form.
		 * @return array An array of prices keyed by currency code.
		 */
	public function get_currency_specific_prices( $form_title ) {
		$page_type = false;
		if ( stripos( $form_title, 'esta' ) !== false ) {
			$page_type = 'esta';
		} elseif ( stripos( $form_title, 'visa' ) !== false ) {
			$page_type = 'visa';
		}

		if ( ! $page_type ) {
			return array();
		}
		$this->log_debug( __METHOD__ . "(): Determined page type as '{$page_type}'. Fetching prices." );

		$prices = array();
		if ( 'esta' === $page_type ) {
			$prices = get_option( 'checkout_esta_prices', array() );
		} elseif ( 'visa' === $page_type ) {
			$prices = get_option( 'checkout_visa_prices', array() );
		}

		if ( empty( $prices ) ) {
			return array();
		}

		// Format prices: ['USD' => ['price' => 175, 'sign' => '$', 'code' => 'USD'], ...].
		$formatted_prices = array();
		foreach ( $prices as $price_entry ) {
			if ( ! empty( $price_entry['currency'] ) ) {
				$code                      = strtoupper( $price_entry['currency'] );
				$formatted_prices[ $code ] = array(
					'price' => floatval( $price_entry['price'] ),
					'sign'  => $price_entry['sign'],
					'code'  => $code,
				);
			}
		}
		return $formatted_prices;
	}
		/**
		 * AJAX handler to update the entry's currency and payment amount.
		 */
	public function ajax_update_entry_currency() {
		// 1. Security Check
		check_ajax_referer( 'gf_checkout_com_update_currency', 'nonce' );

		// 2. Get and sanitize input
		$entry_id          = absint( $_POST['entry_id'] );
		$form_id           = absint( $_POST['form_id'] );
		$selected_currency = sanitize_text_field( strtoupper( $_POST['currency'] ) );

		if ( ! $entry_id || ! $form_id || ! $selected_currency ) {
			wp_send_json_error( array( 'message' => 'Invalid data provided.' ) );
		}

		// 3. Load necessary objects
		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $entry ) || ! $form ) {
			wp_send_json_error( array( 'message' => 'Could not load form or entry.' ) );
		}

		// Ensure the request is for this gateway.
		if ( ! $this->is_payment_gateway( $entry['id'] ) ) {
			wp_send_json_error( array( 'message' => 'Payment gateway mismatch.' ) );
		}

		// 4. Get available prices and find the new price
		$available_prices = $this->get_currency_specific_prices( $form['title'] );

		if ( ! isset( $available_prices[ $selected_currency ] ) ) {
			wp_send_json_error( array( 'message' => 'Selected currency is not available.' ) );
		}

		$new_price_info = $available_prices[ $selected_currency ];
		$new_amount     = $new_price_info['price'];
		$new_currency   = $new_price_info['code'];

		// // --- NEW: ADMIN PRICE OVERRIDE (DURING AJAX) ---
		if ( function_exists( 'get_current_user_id' ) && function_exists( 'current_user_can' ) && get_current_user_id() === 8 && current_user_can( 'manage_options' ) ) {

			$this->log_debug( __METHOD__ . '(): Maintaining payment amount of 1 for admin user ID 8 during currency switch.' );
			$new_amount = 1;
		}
		// // --- END ADMIN PRICE OVERRIDE ---

		// 5. Update the entry in the database
		$entry['payment_amount'] = $new_amount;
		$entry['currency']       = $new_currency;
		$result                  = GFAPI::update_entry( $entry );

		if ( is_wp_error( $result ) ) {
			$this->log_error( __METHOD__ . '(): Failed to update entry ' . $entry_id . ' - ' . $result->get_error_message() );
			wp_send_json_error( array( 'message' => 'Failed to update payment details.' ) );
		}

		$this->log_debug( __METHOD__ . "(): Successfully updated entry {$entry_id} to {$new_amount} {$new_currency}." );

		// 6. Send success response with new details for display
		wp_send_json_success(
			array(
				'new_amount_formatted' => $new_price_info['sign'] . number_format( $new_amount, 2 ),
				'new_total_text'       => 'Total Amount: ' . $new_price_info['sign'] . number_format( $new_amount, 2 ),
			)
		);
	}

	/**
	 * AJAX handler to create a Checkout.com Payment Session for Flow.
	 */
	public function ajax_create_checkout_session() {
		check_ajax_referer( 'gf_checkout_com_create_session', 'nonce' );

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$form_id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $entry_id || ! $form_id ) {
			wp_send_json_error( array( 'message' => 'Missing form or entry ID.' ), 400 );
		}

		$entry = GFAPI::get_entry( $entry_id );
		$feed  = $this->get_payment_feed( $entry );
		if ( ! $entry || ! $feed ) {
			wp_send_json_error( array( 'message' => 'Could not retrieve entry or feed.' ), 404 );
		}

		$api_settings    = $this->get_api_settings( $feed );
		$submission_data = $this->get_submission_data( $feed, GFAPI::get_form( $form_id ), $entry );
		$amount_in_cents = $this->get_amount_export( rgar( $entry, 'payment_amount' ), rgar( $entry, 'currency' ) );
		// --- THIS IS THE CORRECTED PAYLOAD --- //
		$payload = array(
			'amount'                => $amount_in_cents,
			'currency'              => rgar( $entry, 'currency' ),
			'reference'             => rgar( $submission_data, 'reference' ) ?: "GF-{$form_id}-{$entry_id}",
			'processing_channel_id' => $api_settings['processing_channel_id'],

			// FIX #1: Added the complete billing object with address and phone.
			'billing'               => array(
				'address' => array(
					'address_line1' => rgar( $submission_data, 'address' ),
					'address_line2' => rgar( $submission_data, 'address2' ),
					'city'          => rgar( $submission_data, 'city' ),
					'state'         => rgar( $submission_data, 'state' ),
					'zip'           => rgar( $submission_data, 'zip' ),
					'country'       => $this->get_country_code( rgar( $submission_data, 'country' ) ),
				),
				'phone'   => array(
					'number' => rgar( $submission_data, 'phone' ),
				),
			),

			'customer'              => array(
				'email' => rgar( $submission_data, 'email' ),
				'name'  => trim( rgar( $submission_data, 'firstName' ) . ' ' . rgar( $submission_data, 'lastName' ) ),
			),

			// FIX #2: The URL generation is correct, but this will only work on a public server, not localhost.
			'success_url'           => esc_url_raw( add_query_arg( 'cko-session-id', '{cko-session-id}', $this->return_url( $form_id, $entry_id ) ) ),
			'failure_url'           => esc_url_raw( $this->return_url( $form_id, $entry_id, 'cancel' ) ),
			'metadata'              => array(
				'form_id'  => $form_id,
				'entry_id' => $entry_id,
			),
		);

		$api_url = ( 'test' === $api_settings['mode'] ) ? 'https://api.sandbox.checkout.com/payment-sessions' : 'https://api.checkout.com/payment-sessions';

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_settings['secret_key'],
				'Content-Type'  => 'application/json;charset=UTF-8',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 60,
		);
		$this->log_debug( __METHOD__ . '(): Creating Payment Session. Payload: ' . print_r( $payload, true ) );
		$response = wp_remote_post( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// --- FINAL FIX FOR PHP --- //
		if ( ! empty( $body['payment_session_token'] ) ) {
			// Send the ENTIRE successful body object back, not just the token.
			wp_send_json_success( $body );
		} else {
			$this->log_error( __METHOD__ . '(): Failed to create payment session. Response: ' . print_r( $body, true ) );
			wp_send_json_error(
				array(
					'message' => 'Failed to create payment session.',
					'details' => $body,
				),
				500
			);
		}
	}

















	/**
	 * Renders the payment frame shortcode based on the current payment state.
	 *
	 * @return string The rendered payment frame HTML content.
	 */
	public function render_payment_frame_shortcode()
	{
		$entry_id = isset($_GET['entry_id']) ? absint($_GET['entry_id']) : 0;
		$message  = isset($_GET['error_message']) ? sanitize_text_field(wp_unslash($_GET['error_message'])) : '';
		if (! $entry_id) {
			return '<p>Error: No payment session specified.</p>';
		}

		$entry = GFAPI::get_entry($entry_id);
		if (! $entry || is_wp_error($entry)) {
			return '<p>Error: Payment session not found.</p>';
		}

		$form                    = GFAPI::get_form($entry['form_id']);
		$payment_status_from_url = sanitize_text_field(wp_unslash($_GET['payment_status'] ?? ''));
		$entry_payment_status    = rgar($entry, 'payment_status'); // Get the actual status from the entry.

		ob_start();

		// --- TOP-PRIORITY CHECK ---
		// STATE 0: ALREADY PAID - Check the entry's status first.
		if (! empty($entry_payment_status) && 'Paid' === $entry_payment_status) {
			$this->log_debug(__METHOD__ . "(): Entry {$entry_id} is already marked as Paid. Showing confirmation directly.");
			// This is the same logic as your success state, ensuring consistency.
			$confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);
			if (is_array($confirmation) && isset($confirmation['redirect'])) {
				echo "<p><h3>This payment has been completed. Redirecting you...<h3></p><script>window.location.href = '" . $confirmation['redirect'] . "';</script>";
			} else {
				echo wp_kses_post($confirmation);
			}
		}
		// --- END OF NEW CHECK ---

		// STATE 1: SUCCESS -.
		if ('success' === $payment_status_from_url) {
			$this->log_debug(__METHOD__ . "(): Payment for entry {$entry_id} succeeded or is pending. Handling confirmation.");

			$confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);

			if (is_array($confirmation) && isset($confirmation['redirect'])) {
				echo "<script>window.location.href = '" . esc_js($confirmation['redirect']) . "';</script>";
			} else {
				echo wp_kses_post($confirmation);
			}
		} elseif ('failed' === $payment_status_from_url) { // STATE 2: FAILED - The page was reloaded by our JS with &payment_status=failed.
			$this->render_payment_iframe($entry);
			if (empty($message)) {
				$this->log_debug(__METHOD__ . "(): Payment for entry {$entry_id} failed. Displaying error and form.");
				echo wp_kses_post('<h2 class="gform_payment_error_custom hide_summary"><span class="gform-icon gform-icon--close"></span>There was an issue with your payment. Please try again.</h2>');
			} else {
				$this->log_debug(__METHOD__ . "(): Payment for entry {$entry_id} failed. Reason: {$message}.");
				echo wp_kses_post('<h2 class="gform_payment_error_custom hide_summary"><span class="gform-icon gform-icon--close"></span> !! Payment Failed please Try again.</br> (Reason: ' . esc_html($message) . ')</h2>');
			}
		} elseif (isset($_GET['cko-session-id'])) { // STATE 3: VERIFYING - The user just returned from 3DS, cko-session-id is in the URL.
			$this->log_debug(__METHOD__ . "(): 3DS return for entry {$entry_id}. Rendering verification script.");
			$this->render_3ds_verification_handler($entry);
		} else { // STATE 4: INITIAL LOAD - No special parameters exist.
			$this->log_debug(__METHOD__ . "(): Initial load for entry {$entry_id}. Displaying payment iframe.");
			$this->render_payment_iframe($entry);
		}

		return ob_get_clean();
	}

	/**
	 * Renders the payment iframe for processing payments.
	 *
	 * @param array $entry The entry data for the current form submission.
	 * @return void
	 */
	private function render_payment_iframe($entry)
	{
		$settings         = $this->get_plugin_settings();
		$site_b_proxy_url = rgar($settings, 'site_b_proxy_url');
		if (empty($site_b_proxy_url)) {
			$this->log_error(__METHOD__ . '(): Proxy URL for Site B is not configured.');
			echo '<p>Error: Proxy gateway is not configured.</p>';
			return;
		}

		$form = GFAPI::get_form($entry['form_id']);
		// Custom logic to make order summary.
		$order_summary = array();
		if ($form['fields']) {
			$product_details = array();
			$product_addons  = array();

			foreach ($form['fields'] as $field) {
				if ('product' === $field['type']) {
					$product_details['Product Name']  = $field['label'];
					$product_details['Product Price'] = $field['basePrice'];
				} elseif ('option' === $field['type'] && ('Select Add-ons' === $field['label'] || 98 === $field['id'])) {

					foreach ($field['choices'] as $index => $choice) {
						// Each checkbox input has a unique input ID like "98.1", "98.2", etc.
						$input_id = isset($field['inputs'][$index]['id']) ? $field['inputs'][$index]['id'] : null;
						if (! $input_id) {
							continue;
						}

						$entry_value = rgar($entry, (string) $input_id);

						// Check if this particular choice was selected in the entry.
						if (! empty($entry_value) && strpos($entry_value, $choice['value']) !== false) {
							if ($choice['text']) {
								$product_addons[$choice['text']] = $choice['price'];
							}
						}
					}
				} elseif ('total' === $field['type']) {
					// need to store total value.
					$order_summary[$field['label']] = $entry['payment_amount'] ?? '';
				} elseif ('uid' === $field['type'] && 'Application Unique ID' === $field['label']) {
					$order_summary['Application Unique ID'] = $entry[$field['id']];
				}
			}
			$order_summary['product_details'] = $product_details;
			$order_summary['product_addons']  = $product_addons;
		}

		$confirmation_data = GFFormDisplay::handle_confirmation($form, $entry, false);
		$thank_you_url     = is_array($confirmation_data) && isset($confirmation_data['redirect']) ? $confirmation_data['redirect'] : home_url('/');
		$iframe_src        = add_query_arg('entry_id', $entry['id'], $site_b_proxy_url);

?>
		<div class="checkout-wrapper-main">
			<div class="payment-container-main checkout-payment-wrapper">
				<div class="custom-checkout-item-wrapper">

					<div id="checkout-payment-box" class="checkout-payment-box">
						<div class="payment-container-header">
							<h2>Download Your Document After One More Step</h2>
						</div>
						<div id="payment-form-countdown-timer">
							<span id="timer-display">5:00</span> Left to Download Your Document
						</div>
						<div id="iframe-wrapper">
							<iframe id="payment-frame" src="<?php echo esc_url($iframe_src); ?>" title="Secure Payment Form"></iframe>
							<div class="payment-errors-custom"></div>
						</div>
					</div>
					<div class="paymentCertificates__wrapper">
						<div class="paymentCertificates__header">Our security, your safety</div>
						<div class="paymentCertificates__item">
							<img
								class="paymentCertificates__item-logo"
								src="https://mypassportcenter.com/wp-content/uploads/2025/10/download-1.png"
								alt="payment certificates" />
							<p class="paymentCertificates__item-text">
								Your information is defended via secure data protection to keep it private
								and confidential.
							</p>
						</div>
						<div class="paymentCertificates__item">
							<img
								class="paymentCertificates__item-logo"
								src="https://mypassportcenter.com/wp-content/uploads/2025/10/download-2.png"
								alt="payment certificates" />
							<p class="paymentCertificates__item-text">
								This site is secured by SSL. Your credit card information is fully
								protected by an encryption system.
							</p>
						</div>
					</div>

				</div>
				<div class="sidesummary-container">
					<div class="order-details-container">
						<!-- here i want to shoe $order_summary in this following formate -->
						<h3 class="order-details-container-heading">Order Summary</h3>
						<div class="order-details-container-details">
							<?php
							if ($order_summary) {
								// check if application unique id then show it.
								if (! empty($order_summary['Application Unique ID'])) {
									echo '<p class="order-application-no"><strong>Application Reference Code:</strong> ' . esc_html($order_summary['Application Unique ID']) . '</p>';
								}

								// check if product details then show.
								if (! empty($order_summary['product_details'])) {
									echo '<p class="order-sub-detail"><strong>Product Details:</strong></p>';
									$name       = $order_summary['product_details']['Product Name'];
									$base_price = $order_summary['product_details']['Product Price'];
									echo '<p>' . esc_html($name) . ':<strong> ' . esc_html($base_price) . '</strong></p>';
								}

								// check for addons.
								if (! empty($order_summary['product_addons'])) {
									echo '<p class="order-sub-detail"><strong>Product Addons:</strong></p>';
									foreach ($order_summary['product_addons'] as $key => $value) {
										echo '<p>' . esc_html($key) . ':<strong> ' . esc_html($value) . '</strong></p>';
									}
								} else {
									echo '<p><strong>Product Addons:</strong> No Addons</p>';
								}

								// check total.
								if (! empty($order_summary['Total'])) {
									echo '<p class="order-total-amount"><strong>Total Amount: $' . esc_html($order_summary['Total']) . '</strong></p>';
								}
							}

							?>
							<div class="notice-payment">
								<p><strong>Important:</strong>This is a one-time payment for a private service, not affilianted with any government agency. Government filing fees are not included.</p>
							</div>
						</div>
					</div>

					<div class="contact-summary-container">
						<div class="contactSummary__item">
							<div class="contactSummary__icon"><img src="https://mypassportcenter.com/wp-content/uploads/2025/10/operation.svg" width="65" height="65"></div>
							<p class="contactSummary__copy">Our support team is here to help you <b>24/7 via email or form</b></p>
						</div>

					</div>
					<div class="instruction-summary-container">
						<h3 class="instruction-summary-header">Complete your order now to: </h3>
						<div class="instruction-summary-list-container">
							<li>Export your ready-to-submit passport application file to PDF.</li>
							<li>Avoid costly mistakes with 24/7 customer support and expert guidance on submitting an error-free application.</li>
							<li>Get detailed instructions on how to complete your application.</li>
						</div>
						<div class="contactSummary-gaurantee">
							<div class="contactSummary-gaurantee-image">
								<img src="https://mypassportcenter.com/wp-content/uploads/2025/10/money-3ba2a61dbc3c72196b01b17486e20971.png">
							</div>
							<div class="contactSummary-gaurantee-text">
								<span>Money back guarantee</span>
								<p>You have up to 120 days after payment to request a refund.</p>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				// 5-minute countdown timer
				let timeLeft = 300; // 5 minutes in seconds
				const timerDisplay = document.getElementById('timer-display');

				function updateTimer() {
					const minutes = Math.floor(timeLeft / 60);
					const seconds = timeLeft % 60;
					timerDisplay.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

					if (timeLeft <= 0) {
						return; // Just stop at 0:00
					}
					timeLeft--;
				}

				updateTimer(); // Initial call
				const timerInterval = setInterval(updateTimer, 1000);

				let siteBOrigin = new URL('<?php echo esc_js($site_b_proxy_url); ?>').origin;

				window.addEventListener('message', function(event) {
					if (event.origin !== siteBOrigin) {
						return;
					}

					let data = event.data;
					if (data && data.status === 'success') {
						console.log('Site A: Success message received. Redirecting to confirmation.');
						window.location.href = '<?php echo esc_js($thank_you_url); ?>';
					} else if (data && data.status === 'redirect' && data.url) {
						console.log('Site A: 3DS redirect message received. Redirecting to bank page.');
						window.location.href = data.url;
					}
				});
				let err_msg = jQuery('.gform_payment_error_custom');
				let err_container = jQuery('.payment-errors-custom');

				if (err_msg.length && err_container.length) {
					console.log('from ewrror condition');
					err_container.empty(); // Clear the container
					err_container.append(err_msg); // Move err_msg into err_container
				}

			});
		</script>
<?php
	}

	/**
	 * Handles the 3DS verification process after receiving a response from the Site B.
	 *
	 * @param array $entry The entry data containing payment information.
	 * @return void
	 */
	private function render_3ds_verification_handler($entry)
	{

		$settings         = $this->get_plugin_settings();
		$shared_secret    = rgar($settings, 'shared_secret');
		$verification_url = rgar($settings, 'site_b_3ds_verification_url');

		if (empty($verification_url) || empty($shared_secret)) {
			$this->log_error(__METHOD__ . '(): 3DS Verification URL for Site B is not configured.');
			echo '<p>Error: Gateway is not configured to handle this response.</p>';
			return;
		}

		$entry_id       = $entry['id'];
		$cko_session_id = sanitize_text_field($_GET['cko-session-id']);

		$payload_array = array(
			'entry_id'       => $entry_id,
			'cko_session_id' => $cko_session_id,
		);

		$payload_json = wp_json_encode($payload_array);
		$signature    = hash_hmac('sha256', $payload_json, $shared_secret);

		// 1. Send server-side request to Site B to get the full payment object.
		$response = wp_remote_post(
			$verification_url,
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-Proxy-Signature' => $signature,
				),
				'body'    => $payload_json,
				'timeout' => 15,
			)
		);

		if (is_wp_error($response)) {
			// error_log( 'Site A: Error sending verification request: ' . $response->get_error_message() );
			wp_redirect(
				add_query_arg(
					array(
						'entry_id'       => $entry_id,
						'payment_failed' => 1,
					),
					$settings['payment_page_url']
				)
			);
			exit;
		}

		$body           = json_decode(wp_remote_retrieve_body($response), true);
		$payment_status = 'failed'; // Default to failure This for handle Confirmation.

		if (isset($body['payment']) && ! empty($body['payment'])) {
			$entry_id     = $body['payment']['reference'];
			$status       = $body['payment']['status'];
			$payment_data = $body['payment'];

			if (! empty($status)) {
				$status_from_api = strtolower($status);
				if (in_array($status_from_api, array('authorized', 'captured', 'paid', 'pending', 'partially captured', 'deferred capture'))) {
					$payment_status = 'success'; // Treat 'pending' as a success for the user's redirect.
				}
			}
			// 3. PREPARE the standardized $action array in parallel.
			// This happens regardless of the user's redirect path.
			if (! empty($payment_data) && isset($payment_data['id'])) {
				$action                   = array();
				$action['id']             = $payment_data['id'] . '_' . time();
				$action['entry_id']       = $entry['id'];
				$action['transaction_id'] = $payment_data['id'];
				$action['amount']         = isset($payment_data['amount']) ? ($payment_data['amount'] / 100) : 0;
				$action['currency']       = $payment_data['currency'];
				$action['payment_method'] = $this->_slug;
				$response_code            = rgar($payment_data, 'response_code');
				$reference                = rgar($payment_data, 'reference');
				$api_status_lc            = strtolower($payment_data['status'] ?? 'failed');

				$transaction_id = $payment_data['id'];

				switch ($api_status_lc) {
					case 'authorized':
					case 'captured':
					case 'paid':
					case 'card verified':
						$action['type']         = 'complete_payment';
						$action['payment_date'] = gmdate('Y-m-d H:i:s', strtotime($payment_data['requested_on']));
						$amount_formatted       = GFCommon::to_money($action['amount'], $action['currency']);
						$action['note']         = "Payment completed via 3DS Verification. Amount: {$amount_formatted}.";
						break;
					case 'pending':
						$action['type'] = 'add_pending_payment';
						$action['note'] = 'Payment is pending after 3DS Verification.';
						break;
					// case 'declined': .
					// case 'canceled': .
					default: // Declined, Canceled, etc.
						$first_action = rgar($payment_data, 'actions');
						if (is_array($first_action) && isset($first_action[0])) {
							$response_code    = rgar($first_action[0], 'response_code');
							$response_summary = rgar($first_action[0], 'response_summary');
							if (! empty($response_code) && ! empty($response_summary)) {
								$response_summary = $this->get_error_message($response_code, $response_summary);
							}
						}

						$action['type'] = 'fail_payment';
						$action['note'] = "Payment failed after 3DS Verification. Status: {$api_status_lc}. Transaction Id: {$transaction_id} Reason: " . ($response_summary ?? 'Unknown');
						break;
				}

				// 4. PROCESS the backend update. This is now "fire and forget" from the user's perspective.
				// It does not block the redirect.
				$this->process_payment_action($action);
			} else {
				// Handle cases where the verification API call itself failed.
				$this->log_error(__METHOD__ . '(): Invalid or empty payment data from Site B verification for entry #' . $entry['id']);
				$fail_action = array(
					'type'     => 'fail_payment',
					'entry_id' => $entry['id'],
					'note'     => '3DS Verification failed. Could not retrieve valid payment details from Site B.',
				);
				$this->process_payment_action($fail_action);
			}

			// 5. REDIRECT the user immediately based on the determined outcome.
			if ('success' === $payment_status) {
				// Redirect to the GF confirmation page.
				$this->log_debug(__METHOD__ . "(): Redirecting user for entry #{$entry['id']} to confirmation.");
				$form         = GFAPI::get_form($entry['form_id']);
				$confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);
				if (is_array($confirmation) && isset($confirmation['redirect'])) {
					wp_safe_redirect($confirmation['redirect']);
				} else {
					// Fallback to a simple success redirect to avoid showing a blank page.
					wp_safe_redirect(add_query_arg('payment_status', 'success', home_url('/')));
				}
			} else {
				$response_code    = '';
				$response_summary = '';
				$first_action     = rgar($payment_data, 'actions');
				if (is_array($first_action) && isset($first_action[0])) {
					$response_code    = rgar($first_action[0], 'response_code');
					$response_summary = rgar($first_action[0], 'response_summary');
					if (! empty($response_code) && ! empty($response_summary)) {
						$response_summary = $this->get_error_message($response_code, $response_summary);
					}
				}

				// Redirect back to the payment page with a failure flag.
				$this->log_debug(__METHOD__ . "(): Redirecting user for entry #{$entry['id']} back to payment page.");
				wp_safe_redirect(
					add_query_arg(
						array(
							'entry_id'       => $entry_id,
							'payment_status' => 'failed',
							'error_message'  => ! empty($response_summary) ? $response_summary : 'Unknow Error',
						),
						$settings['payment_page_url']
					)
				);
			}
			exit;
		} else {
			$fail_action = array(
				'type'     => 'fail_payment',
				'entry_id' => $entry['id'],
				'note'     => '3DS Verification failed. Could not retrieve payment details from Site B.',
			);
			$this->process_payment_action($fail_action);
			wp_safe_redirect(
				add_query_arg(
					array(
						'entry_id'       => $entry_id,
						'payment_failed' => 1,
					),
					$settings['payment_page_url']
				)
			);
			exit;
		}
	}

	/**
	 * Handle the payment details request from Site B.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function handle_get_payment_details(WP_REST_Request $request)
	{
		// error_log( 'handle_get_payment_details called' );
		$settings      = $this->get_plugin_settings();
		$shared_secret = rgar($settings, 'shared_secret');
		$received_hmac = $request->get_header('x-proxy-signature');
		$payload_json  = $request->get_body();
		$expected_hmac = hash_hmac('sha256', $payload_json, $shared_secret);

		if (empty($shared_secret) || ! hash_equals($expected_hmac, $received_hmac)) {
			$this->log_error(__METHOD__ . '(): Invalid signature from Site B.');
			return new WP_REST_Response(array('message' => 'Invalid signature.'), 403);
		}

		$data     = json_decode($payload_json, true);
		$user_id  = absint($data['user_id']);
		$entry_id = absint($data['entry_id']);
		$entry    = GFAPI::get_entry($entry_id);

		if (is_wp_error($entry) || ! $entry) {
			$this->log_error(__METHOD__ . "(): Site B requested details for non-existent entry ID: $entry_id");
			return new WP_REST_Response(array('message' => 'Entry not found.'), 404);
		}

		// --- CHECK PAYMENT STATUS ---
		$payment_status = rgar($entry, 'payment_status');
		if ('Paid' === $payment_status) {
			$this->log_error(__METHOD__ . "(): Site B requested payment details for an already PAID entry ID: $entry_id");
			// Return a specific error code that Site B can understand.
			return new WP_REST_Response(array('message' => 'This application has already been paid.'), 409); // 409 Conflict is a good HTTP status for this.
		}
		// --- END OF FIX ---

		$total_amount = rgar($entry, 'payment_amount'); // Convert Amount in Smaller Unit According to checkout.com needed.
		if (empty($total_amount)) {
			return new WP_REST_Response(array('message' => 'Payment Amount is not found.'), 404);
		}
		$response = array(
			'amount'   => $this->get_amount_export(rgar($entry, 'payment_amount'), $entry['currency']) * 100,
			'currency' => rgar($entry, 'currency'),
		);

		return new WP_REST_Response(
			$response,
			200
		);
	}

	/**
	 * Process the callback from Site B for payment notifications which get from Webhook.
	 *
	 * @param WP_REST_Request $request The request object containing payment data.
	 * @return WP_REST_Response Response object with status and message.
	 */
	public function process_sitea_callback(WP_REST_Request $request)
	{
		// error_log( 'process_sitea_callback called' );
		$settings      = $this->get_plugin_settings();
		$shared_secret = rgar($settings, 'shared_secret');
		if (empty($shared_secret)) {
			$this->log_error(__METHOD__ . '(): Callback received but shared secret is not configured.');
			return new WP_REST_Response(array('message' => 'Forbidden: Not configured.'), 403);
		}

		// 1. Authenticate the request from Site B
		$received_hmac = $request->get_header('x-proxy-signature');
		$payload_json  = $request->get_body();
		$expected_hmac = hash_hmac('sha256', $payload_json, $shared_secret);

		if (! hash_equals($expected_hmac, $received_hmac)) {
			$this->log_error(__METHOD__ . '(): Forbidden: Invalid signature on callback from Site B.');
			return new WP_REST_Response(array('message' => 'Forbidden: Invalid signature.'), 403);
		}

		// 2. Decode the FULL webhook payload from Checkout.com
		$webhook_data = json_decode($payload_json, true);
		$this->log_debug(__METHOD__ . '(): Processing full webhook payload: ' . print_r($webhook_data, true));

		// This logic is now similar to your ORIGINAL plugin's webhook handler.
		$event_type = $webhook_data['type'] ?? '';
		if (empty($event_type)) {
			$this->log_error(__METHOD__ . '(): Received callback without an event type.');
			return new WP_REST_Response(array('message' => 'Event type missing.'), 400);
		}

		// Extract the payment data object.
		$payment_data = $webhook_data['data'] ?? null;
		if (! $payment_data) {
			$this->log_error(__METHOD__ . '(): Received callback without a data object.');
			return new WP_REST_Response(array('message' => 'Payload data missing.'), 400);
		}

		// Extract the entry_id from the 'reference' field.
		$entry_id = $payment_data['reference'] ?? null;
		if (! $entry_id) {
			$this->log_error(__METHOD__ . '(): Webhook payload is missing the entry ID in `data.reference`.');
			return new WP_REST_Response(array('message' => 'Missing reference (entry_id).'), 400);
		}

		$entry = GFAPI::get_entry($entry_id);
		if (is_wp_error($entry) || ! $entry) {
			$this->log_error(__METHOD__ . "(): Callback received for non-existent entry ID: $entry_id");
			return new WP_REST_Response(array('message' => 'Entry not found.'), 404);
		}

		// Prevent duplicate processing.
		if (in_array(rgar($entry, 'payment_status'), array('Paid', 'Failed'), true)) {
			$this->log_debug(__METHOD__ . "(): Entry $entry_id already processed. Ignoring duplicate callback.");
			return new WP_REST_Response(array('status' => 'already_processed'), 200);
		}

		// 3. PREPARE the standardized $action array.
		$action                   = array();
		$action['id']             = $webhook_data['id'] ?? ($payment_data['id'] . '_' . time()); // Create a unique ID for the event.
		$action['entry_id']       = $entry_id;
		$action['transaction_id'] = $payment_data['id'] ?? 'N/A';
		$action['amount']         = isset($payment_data['amount']) ? ($payment_data['amount'] / 100) : 0;
		$action['currency']       = $payment_data['currency'] ?? '';
		$action['payment_method'] = $this->_slug;

		switch ($event_type) {
			case 'payment_approved':
			case 'payment_captured':
				$amount_formatted       = GFCommon::to_money($action['amount'], $action['currency']);
				$action['type']         = 'complete_payment';
				$action['payment_date'] = gmdate('Y-m-d H:i:s', strtotime($webhook_data['created_on']));
				$action['note']         = "Payment completed via Webhook of : {$amount_formatted}.";
				$form = GFAPI::get_form($entry['form_id']);
				GFAPI::send_notifications($form, $entry, 'payment_completed_custom');  // Triger Notification Manually.
				break;

			case 'payment_pending':
				$action['type'] = 'add_pending_payment';
				$action['note'] = 'Payment is pending confirmation via Webhook.';
				break;

			case 'payment_declined':
			case 'payment_canceled':
			case 'payment_capture_declined':
				$action['type'] = 'fail_payment';
				$action['note'] = 'Payment failed via Webhook. Reason: ' . ($payment_data['response_summary'] ?? 'Unknown');
				break;

			default:
				$this->log_debug(__METHOD__ . '(): Ignored webhook event type: ' . $event_type);
				return new WP_REST_Response(array('message' => 'Event type ignored.'), 200);
		}

		// 4. PASS the action to the central processor.
		$this->process_payment_action($action);

		return new WP_REST_Response(array('message' => 'Callback processed by Site A.'), 200);
	}

	/**
	 * Processes a standardized payment action array to update the entry.
	 * This is the central point for all payment status updates.
	 *
	 * @param array $action The standardized action array.
	 * @return bool True on success (Paid/Pending), false on failure or error.
	 */
	private function process_payment_action($action)
	{
		$action = wp_parse_args(
			$action,
			array(
				'id'             => null,
				'type'           => false,
				'amount'         => false,
				'transaction_id' => false,
				'entry_id'       => false,
				'note'           => '',
			)
		);

		if (! $action['entry_id'] || ! $action['type']) {
			$this->log_error(__METHOD__ . '(): Missing entry_id or type in action.');
			return false;
		}

		// Prevent duplicate processing using the transaction ID + event type.
		if ($action['id'] && $this->is_duplicate_callback($action['id'])) {
			$this->log_debug(__METHOD__ . '(): Duplicate callback action detected. Aborting. ID: ' . $action['id']);
			return true; // Return true to prevent error states for legitimate duplicates.
		}

		$entry = GFAPI::get_entry($action['entry_id']);
		if (! $entry || is_wp_error($entry)) {
			$this->log_error(__METHOD__ . '(): Could not retrieve entry ' . $action['entry_id']);
			return false;
		}

		$result = false;
		switch ($action['type']) {
			case 'complete_payment':
				if (rgar($entry, 'payment_status') === 'Paid') {
					$this->log_debug(__METHOD__ . '(): Entry already marked as Paid. Skipping.');
					$result = true;
					break;
				}
				$result = $this->complete_payment($entry, $action);
				$form   = GFAPI::get_form($entry['form_id']);
				GFAPI::send_notifications($form, $entry, 'payment_completed_custom');  // Triger Notification Manually.
				break;

			case 'add_pending_payment':
				if (in_array(rgar($entry, 'payment_status'), array('Processing', 'Pending'), true)) {
					$this->log_debug(__METHOD__ . '(): Entry already in a pending state. Skipping.');
					$result = true;
					break;
				}
				$result = $this->add_pending_payment($entry, $action);
				break;

			case 'fail_payment':
				if (rgar($entry, 'payment_status') === 'Failed') {
					$this->log_debug(__METHOD__ . '(): Entry already marked as Failed. Skipping.');
					$result = true;
					break;
				}
				$result = $this->fail_payment($entry, $action);
				break;
		}

		if ($result && $action['id']) {
			$this->register_callback($action['id'], $action['entry_id']);
		}

		// Return true for success (Paid/Pending), false for failure.
		return in_array($action['type'], array('complete_payment', 'add_pending_payment'));
	}


}
