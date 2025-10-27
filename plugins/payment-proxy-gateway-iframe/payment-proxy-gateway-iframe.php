<?php
/**
 * Plugin Name:       Payment Proxy Gateway for Checkout.com (Iframe)
 * Description:       A secure proxy to handle Checkout.com payments for other sites using the iframe model.
 * Version:           2.1.1
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       payment-proxy-gateway-iframe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
// make plugin version constant.
define( 'PAYMENT_PROXY_GATEWAY_IFRAME_VERSION', '2.1.1' );

/**
 * Main class for handling payment proxy gateway functionality.
 *
 * This class manages the payment proxy gateway settings, API endpoints,
 * and payment processing functionality using the iframe model.
 *
 * @since 2.1.0
 */
final class SiteB_Payment_Proxy_Gateway_Iframe {

	/**
	 * Holds the single instance of this class.
	 *
	 * @var SiteB_Payment_Proxy_Gateway_Iframe
	 */
	private static $instance;
	const SETTINGS_KEY = 'siteb_proxy_settings_iframe';

	/**
	 * Gets the single instance of this class.
	 *
	 * @return SiteB_Payment_Proxy_Gateway_Iframe Instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Sets up hooks and filters.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'rest_api_init', array( $this, 'register_api_endpoints' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_siteb_process_payment', array( $this, 'ajax_process_payment' ) );
		add_action( 'wp_ajax_nopriv_siteb_process_payment', array( $this, 'ajax_process_payment' ) );
		add_shortcode( 'siteb_payment_frame', array( $this, 'render_payment_frame_shortcode' ) );
	}

	/**
	 * Adds the admin menu page for the payment proxy gateway settings.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Payment Proxy Gateway', 'payment-proxy-gateway-iframe' ),
			__( 'Payment Proxy Gateway', 'payment-proxy-gateway-iframe' ),
			'manage_options',
			'payment-proxy-gateway',
			array( $this, 'settings_page_html' )
		);
	}

	/**
	 * Initializes the plugin settings and registers the settings fields.
	 *
	 * @return void
	 */
	public function settings_init() {
		register_setting( 'payment_proxy_gateway_settings_group', self::SETTINGS_KEY );
		add_settings_section( 'proxy_settings_section', __( 'Gateway Configuration', 'payment-proxy-gateway-iframe' ), null, 'payment_proxy_gateway_settings_group' );

		add_settings_field(
			'environment_mode',
			__( 'Environment Mode', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_radio_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for' => 'environment_mode',
				'options'   => array(
					'sandbox' => 'Sandbox (for testing)',
					'live'    => 'Live (for real payments)',
				),
				'default'   => 'sandbox',
			)
		);

		// ++ ADDED: 3DS setting field
		add_settings_field(
			'enable_3ds',
			__( 'Enable 3D Secure (3DS)', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_checkbox_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'enable_3ds',
				'description' => 'Enable 3D Secure challenge for payments. The page on Site A must be publicly accessible for the return redirect to work.',
			)
		);

		add_settings_field(
			'site_a_home_url',
			__( 'Site A Home URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'site_a_home_url',
				'description' => 'The home URL of Site A (e.g., https://site-a.com). Used for secure cross-origin communication.',
			)
		);

		add_settings_field(
			'site_a_payment_page_url', // ++ ADDED: Site A payment page URL
			__( 'Site A Payment Page URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'site_a_payment_page_url',
				'description' => 'The full URL of the page on Site A where the [gf_checkout_payment_frame] shortcode is placed. Required for 3DS redirects.',
			)
		);

		add_settings_field(
			'checkout_com_public_key',
			__( 'Checkout.com Public Key', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'checkout_com_public_key',
				'description' => 'Your Sandbox or Live Public Key for Frames.',
			)
		);
		add_settings_field(
			'checkout_com_secret_key',
			__( 'Checkout.com Secret Key', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_password_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array( 'label_for' => 'checkout_com_secret_key' )
		);
		add_settings_field(
			'processing_channel_id',
			__( 'Processing Channel ID', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'processing_channel_id',
				'description' => 'Your specific Processing Channel ID from Checkout.com.',
			)
		);
		add_settings_field(
			'checkout_com_webhook_signature_key',
			__( 'Checkout.com Webhook Secret', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_password_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array( 'label_for' => 'checkout_com_webhook_signature_key' )
		);
		add_settings_field(
			'shared_secret',
			__( 'Shared Secret Key', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_password_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'shared_secret',
				'description' => 'It is Secrate Key to Communitcate With Your Desired Site(My Passport Center), Note: Add Same key in Both site',
			)
		);
		add_settings_field(
			'site_a_validation_url',
			__( 'Site A Validation URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'site_a_validation_url',
				'description' => 'The `/get-payment-details` endpoint URL from Site A.',
			)
		);
		add_settings_field(
			'site_a_callback_url',
			__( 'Site A Final Callback URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_text_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'site_a_callback_url',
				'description' => 'The final `/callback` endpoint URL from Site A for webhooks.',
			)
		);
		add_settings_field(
			'3ds_verification_url',
			__( 'This Site\'s 3DS Verification URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_readonly_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => '3ds_verification_url',
				'value'       => rest_url( 'payment-proxy-gateway-iframe/v1/verify-3ds-session' ),
				'description' => __( 'Copy this URL and paste it into the "Site B 3DS Verification URL" field in the Site A plugin settings.', 'payment-proxy-gateway-iframe' ),
			)
		);
		add_settings_field(
			'webhook_display_url',
			__( 'This Site\'s Webhook URL', 'payment-proxy-gateway-iframe' ),
			array( $this, 'render_readonly_field' ),
			'payment_proxy_gateway_settings_group',
			'proxy_settings_section',
			array(
				'label_for'   => 'webhook_display_url',
				'value'       => rest_url( 'payment-proxy-gateway-iframe/v1/webhook' ),
				'description' => __( 'Copy this URL and paste it into the "Endpoint URL" field when creating a webhook in your Checkout.com dashboard.', 'payment-proxy-gateway-iframe' ),
			)
		);
	}

	/**
	 * Renders a text input field for the settings page.
	 *
	 * @param array $args Arguments for the field including label_for and description.
	 */
	public function render_text_field( $args ) {
		$options = get_option( self::SETTINGS_KEY, array() );
		$value   = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
		echo '<input type="text" id="' . esc_attr( $args['label_for'] ) . '" name="' . esc_attr( self::SETTINGS_KEY . '[' . $args['label_for'] . ']' ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Renders a password input field for the settings page.
	 *
	 * @param array $args Arguments for the field including label_for.
	 */
	public function render_password_field( $args ) {
		$options = get_option( self::SETTINGS_KEY, array() );
		$value   = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
		echo '<input type="password" id="' . esc_attr( $args['label_for'] ) . '" name="' . esc_attr( self::SETTINGS_KEY . '[' . $args['label_for'] . ']' ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	/**
	 * Renders a readonly text input field for the settings page.
	 *
	 * @param array $args Arguments for the field including label_for, value and description.
	 */
	public function render_readonly_field( $args ) {
		echo '<input type="text" id="' . esc_attr( $args['label_for'] ) . '" value="' . esc_attr( $args['value'] ) . '" class="regular-text" readonly onfocus="this.select();">';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Renders a radio input field for the settings page.
	 *
	 * @param array $args Arguments for the field including label_for, default, and options.
	 */
	public function render_radio_field( $args ) {
		$options       = get_option( self::SETTINGS_KEY, array() );
		$current_value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : $args['default'];
		foreach ( $args['options'] as $value => $label ) {
			echo '<label style="margin-right: 20px;"><input type="radio" name="' . esc_attr( self::SETTINGS_KEY . '[' . $args['label_for'] . ']' ) . '" value="' . esc_attr( $value ) . '" ' . checked( $current_value, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
	}

	/**
	 * Renders a checkbox input field for the settings page.
	 *
	 * @param array $args Arguments for the field including label_for and description.
	 */
	public function render_checkbox_field( $args ) {
		$options = get_option( self::SETTINGS_KEY, array() );
		$checked = isset( $options[ $args['label_for'] ] ) && $options[ $args['label_for'] ];
		echo '<label><input type="checkbox" id="' . esc_attr( $args['label_for'] ) . '" name="' . esc_attr( self::SETTINGS_KEY . '[' . $args['label_for'] . ']' ) . '" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Enable', 'payment-proxy-gateway-iframe' ) . '</label>';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Renders the settings page HTML.
	 */
	public function settings_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'payment_proxy_gateway_settings_group' );
				do_settings_sections( 'payment_proxy_gateway_settings_group' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueues the required scripts for the payment frame.
	 */
	public function enqueue_scripts() {
		if ( is_a( get_post(), 'WP_Post' ) && has_shortcode( get_post()->post_content, 'siteb_payment_frame' ) ) {
			wp_enqueue_script( 'checkout-frames', 'https://cdn.checkout.com/js/framesv2.min.js', array(), null, true );
		}
	}

	/**
	 * Renders the payment frame shortcode content.
	 *
	 * Validates the entry ID, retrieves settings, validates the session with Site A,
	 * and generates the payment form HTML with necessary scripts and styles.
	 *
	 * @return string The HTML content for the payment frame.
	 */
	public function render_payment_frame_shortcode() {
		// error_log( 'render_payment_frame_shortcode is called' );
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( ! $entry_id ) {
			return '<p>Error: Invalid request parameters.</p>'; }

		$settings       = get_option( self::SETTINGS_KEY, array() );
		$shared_secret  = $settings['shared_secret'] ?? '';
		$validation_url = $settings['site_a_validation_url'] ?? '';
		$public_key     = $settings['checkout_com_public_key'] ?? '';
		$site_a_origin  = $settings['site_a_home_url'] ?? '';

		if ( empty( $shared_secret ) || empty( $validation_url ) || empty( $public_key ) || empty( $site_a_origin ) ) {
			return '<p>Error: Payment frame is not configured.</p>';
		}

		$payload_json = wp_json_encode(
			array(
				'entry_id' => $entry_id,
				'user_id'  => $user_id,
			)
		);
		$hmac         = hash_hmac( 'sha256', $payload_json, $shared_secret );
		$response     = wp_remote_post(
			$validation_url,
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-Proxy-Signature' => $hmac,
				),
				'body'    => $payload_json,
				'timeout' => 60, // Added timeout for resilience.
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			error_log( 'Site B: Could not validate session with Site A. Response: ' . print_r( $response, true ) );
			return '<p>Error: Could not validate payment session. Please Refresh the page and try again.</p>';
		}
		// ADDED: Robust check for JSON decoding and expected data format.
		$response_body = wp_remote_retrieve_body( $response );
		$details       = json_decode( $response_body, true );
		if ( ! is_array( $details ) || ! isset( $details['amount'], $details['currency'] ) ) {
			error_log( 'Site B: Invalid JSON or missing data from Site A validation endpoint. Body: ' . $response_body );
			return '<p>Error: Could not retrieve payment details. Please Refresh the page and try again.</p>';
		}
		$details = json_decode( wp_remote_retrieve_body( $response ), true );
		wp_enqueue_script( 'siteb-payment-js', plugin_dir_url( __FILE__ ) . 'public/assets/js/siteb-payment.js', array( 'jquery', 'checkout-frames' ), PAYMENT_PROXY_GATEWAY_IFRAME_VERSION, true );

		wp_localize_script(
			'siteb-payment-js',
			'payment_vars',
			array(
				'publicKey'   => $public_key,
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'amount'      => $details['amount'],
				'currency'    => $details['currency'],
				'entryId'     => $entry_id,
				'siteAOrigin' => $site_a_origin,
				'nonce'       => wp_create_nonce( 'siteb_payment_nonce' ),
			)
		);
		wp_enqueue_style( 'payment-form-style', plugin_dir_url( __FILE__ ) . 'public/assets/css/payment-form-style.css', array(), PAYMENT_PROXY_GATEWAY_IFRAME_VERSION );

		ob_start();
		?>
		<div class="payment-frame-container">
		<div class="iframe-card-custom-header-"> <span>Credit/Debit Card Information</span><img src="https://mypassportcenter.com/wp-content/uploads/2025/09/visa-mastercard-american-express.png" alt="credit card image" width="160" height="50" /></div>		
		<form id="payment-form" method="POST" >
			<div class="card-frame"></div>
			<div class="gform_footer top_label iframe-payment-form-footer">
				<div class="terms-checkbox-container">
					<input type="checkbox" id="terms-agreement" name="terms_agreement">
					<label for="terms-agreement" class="information-text">By authorizing the payment, I acknowledge that I am purchasing a third-party service not affiliated with any government agency, and I have reviewed and angree to the 
						<a href="https://mypassportcenter.com/terms-and-conditions/">term and conditions</a> and 
						<a href="https://mypassportcenter.com/privacy-policy">privacy policy</a></label>
				</div>
				<input id="pay-button" class="gform_button button" value="<?php _e( 'Complete Your Order', 'gf-checkout-com' ); ?>" type="submit" disabled/>
				<div class="payment-method-text"><p>We use secure payment methods to protect your information.</p></div>
			</div>
			<!-- <button id="pay-button" disabled>Pay Now</button> -->
			<p id="error-message" class="error-message"></p>
		</form>
		<div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Process payment via AJAX request.
	 *
	 * Handles payment processing through Checkout.com, including 3DS support.
	 * Validates request data, processes the payment, and sends callback to Site A.
	 *
	 * @return void
	 */
	public function ajax_process_payment() {
		// error_log( 'ajax_process_payment is called' );
		check_ajax_referer( 'siteb_payment_nonce', 'nonce' );

		$settings              = get_option( self::SETTINGS_KEY, array() );
		$checkout_secret       = $settings['checkout_com_secret_key'] ?? '';
		$processing_channel_id = $settings['processing_channel_id'] ?? '';
		$mode                  = $settings['environment_mode'] ?? 'sandbox';
		$enable_3ds            = ! empty( $settings['enable_3ds'] );
		$shared_secret         = $settings['shared_secret'] ?? '';
		$site_a_callback_url   = $settings['site_a_callback_url'] ?? '';
		$site_a_payment_url    = $settings['site_a_payment_page_url'] ?? '';

		$token    = $_POST['token'] ?? '';
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$amount   = isset( $_POST['amount'] ) ? absint( $_POST['amount'] ) : 0;
		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';

		if ( empty( $token ) || empty( $entry_id ) || empty( $amount ) || empty( $currency ) || empty( $checkout_secret ) || empty( $shared_secret ) || empty( $site_a_callback_url ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request data or missing configuration.' ) );
		}

		if ( $enable_3ds && empty( $site_a_payment_url ) ) {
			wp_send_json_error( array( 'message' => '3DS is enabled but the Site A Payment Page URL is not configured.' ) );
		}

		$checkout_api_url = ( 'live' === $mode ) ? 'https://api.checkout.com/payments' : 'https://api.sandbox.checkout.com/payments';

		$payload = array(
			'source'    => array(
				'type'  => 'token',
				'token' => $token,
			),
			'amount'    => $amount,
			'currency'  => $currency,
			'reference' => (string) $entry_id,
		);

		if ( $enable_3ds ) {
			// ++ The success and failure URLs must point back to Site A's payment page with the entry ID
			$payload['3ds']         = array( 'enabled' => true );
			$payload['success_url'] = add_query_arg( 'entry_id', $entry_id, $site_a_payment_url );
			$payload['failure_url'] = add_query_arg( 'entry_id', $entry_id, $site_a_payment_url );
		}

		if ( ! empty( $processing_channel_id ) ) {
			$payload['processing_channel_id'] = $processing_channel_id;
		}

		$response = wp_remote_post(
			$checkout_api_url,
			array(
				'headers' => array(
					'Authorization' => $checkout_secret,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true );
		// ++ MODIFIED: Handle 3DS Redirect
		if ( isset( $body['_links']['redirect']['href'] ) ) {
			error_log( '3D redirect happnes' );
			$payload = array( 'redirectUrl' => $body['_links']['redirect']['href'] );
			wp_send_json_success( $payload );
		}

		$is_approved       = ( ! is_wp_error( $response ) && $response_code < 300 && ! empty( $body['approved'] ) && true === $body['approved'] );
		$status_for_site_a = $is_approved ? 'Paid' : 'Failed';
		$amount_for_site_a = $body['amount'] ?? 0;

		// Format as webhook-style payload that Site A expects
		$callback_payload = array(
			'type' => $is_approved ? 'payment_approved' : 'payment_declined',
			'data' => array(
				'id'               => $body['id'] ?? 'N/A',
				'reference'        => (string) $entry_id,
				'amount'           => $amount_for_site_a,
				'currency'         => $currency,
				'approved'         => $is_approved,
				'status'           => $body['status'] ?? ($is_approved ? 'Authorized' : 'Declined'),
				'response_summary' => $body['response_summary'] ?? 'Payment processed',
			),
			'created_on' => gmdate('c'),
		);

		$payload_json   = wp_json_encode( $callback_payload );
		$hmac_to_site_a = hash_hmac( 'sha256', $payload_json, $shared_secret );

		wp_remote_post(
			$site_a_callback_url,
			array(
				'headers'  => array(
					'Content-Type'      => 'application/json',
					'X-Proxy-Signature' => $hmac_to_site_a,
				),
				'body'     => $payload_json,
				'blocking' => false,
			)
		);

		if ( $is_approved ) {
			wp_send_json_success();
		} else {
			$error_message = $body['response_summary'] ?? 'Payment was declined.';
			error_log( 'Checkout.com Non-3DS Payment Failed on Site B. Response: ' . print_r( $body, true ) );
			wp_send_json_error( array( 'message' => esc_html( $error_message ) ) );
		}
	}

	/**
	 * Registers REST API endpoints for the payment gateway.
	 *
	 * @return void
	 */
	public function register_api_endpoints() {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		register_rest_route(
			'payment-proxy-gateway-iframe/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_checkout_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
		// NEW: The endpoint Site A calls to verify a 3DS session.
		register_rest_route(
			'payment-proxy-gateway-iframe/v1',
			'/verify-3ds-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_verify_3ds_session' ),
				'permission_callback' => '__return_true', // Signature check handles auth.
			)
		);
	}
	/**
	 * Handles verification of 3DS sessions by communicating with Checkout.com.
	 *
	 * @param WP_REST_Request $request The incoming REST request object.
	 * @return WP_REST_Response The response containing payment verification status.
	 */
	public function handle_verify_3ds_session( WP_REST_Request $request ) {
		$settings        = get_option( self::SETTINGS_KEY, array() );
		$shared_secret   = $settings['shared_secret'] ?? '';
		$checkout_secret = $settings['checkout_com_secret_key'] ?? '';
		$mode            = $settings['environment_mode'] ?? 'sandbox';
		$site_a_url      = $settings['site_a_callback_url'] ?? '';

		// 1. Authenticate the request from Site A
		$received_hmac = $request->get_header( 'x-proxy-signature' );
		$payload_json  = $request->get_body();
		$expected_hmac = hash_hmac( 'sha256', $payload_json, $shared_secret );

		if ( ! hash_equals( $expected_hmac, $received_hmac ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 403 );
		}

		$data           = json_decode( $payload_json, true );
		$cko_session_id = $data['cko_session_id'] ?? null;
		if ( ! $cko_session_id ) {
			return new WP_REST_Response( array( 'message' => 'Missing session ID.' ), 400 );
		}

		// 2. Query Checkout.com for the final payment object
		$checkout_api_url = ( 'live' === $mode ) ? 'https://api.checkout.com/payments/' : 'https://api.sandbox.checkout.com/payments/';
		$verification_url = $checkout_api_url . $cko_session_id;

		$response         = wp_remote_get( $verification_url, array( 'headers' => array( 'Authorization' => $checkout_secret ) ) );
		$raw_payment_body = wp_remote_retrieve_body( $response );
		$payment_data     = json_decode( $raw_payment_body, true );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 || ! $payment_data ) {
			// Verification itself failed, so tell the user it failed. No entry update needed yet.
			return new WP_REST_Response(
				array(
					'success' => false,
					'data'    => array( 'status' => 'Failed' ),
				),
				200
			);
		}

		// 3. Determine the simple status for the user journey and return it to the browser.
		return new WP_REST_Response(
			array(
				'payment' => $payment_data,
			),
			200
		);
	}
	/**
	 * Handles incoming webhooks from Checkout.com for payment status updates.
	 *
	 * @param WP_REST_Request $request The incoming webhook request.
	 * @return WP_REST_Response Response to the webhook request.
	 */
	public function handle_checkout_webhook( WP_REST_Request $request ) {
		error_log( 'handle_checkout_webhook called' );
		$settings       = get_option( self::SETTINGS_KEY, array() );
		$webhook_secret = $settings['checkout_com_webhook_signature_key'] ?? '';
		$shared_secret  = $settings['shared_secret'] ?? '';
		$site_a_url     = $settings['site_a_callback_url'] ?? '';

		if ( empty( $webhook_secret ) || empty( $shared_secret ) || empty( $site_a_url ) ) {
			return new WP_REST_Response( array( 'message' => 'Webhook handler not configured.' ), 503 );
		}

		$auth_signature = $request->get_header( 'authorization' );
		$body           = $request->get_body();
		if ( empty( $auth_signature ) || empty( $body ) ) {
			return new WP_REST_Response( array( 'message' => 'Missing webhook data.' ), 400 );
		}
		$expected_signature = hash_hmac( 'sha256', $body, $webhook_secret );

		if ( $auth_signature !== $webhook_secret ) {
			error_log( 'Invalid webhook signature.' );
			return new WP_REST_Response( array( 'message' => 'Invalid webhook signature.' ), 403 );
		}

		$webhook_data = json_decode( $body, true );
		$event_type   = $webhook_data['type'] ?? '';
		error_log( '$webhook_data: ' . print_r( $webhook_data, true ) );
		if ( ! in_array( $event_type, array( 'payment_approved', 'payment_captured', 'payment_declined' ), true ) ) {
			error_log( 'Unhandled webhook event type: ' . $event_type );
			return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}

		$payment_data = $webhook_data['data'];
		$entry_id     = $payment_data['reference'] ?? null;
		if ( ! $entry_id ) {
			error_log( 'Missing reference (entry_id).' );
			return new WP_REST_Response( array( 'message' => 'Missing reference (entry_id).' ), 400 );
		}

		$status           = ( 'payment_declined' !== $event_type ) ? 'Paid' : 'Failed';
		$callback_payload = array(
			'entry_id'       => absint( $entry_id ),
			'status'         => $status,
			'transaction_id' => sanitize_text_field( $payment_data['id'] ?? 'N/A' ),
			'amount'         => ( $payment_data['amount'] ?? 0 ) / 100,
		);

		$payload_json   = wp_json_encode( $webhook_data );
		$hmac_to_site_a = hash_hmac( 'sha256', $payload_json, $shared_secret );

		wp_remote_post(
			$site_a_url,
			array(
				'headers'  => array(
					'Content-Type'      => 'application/json',
					'X-Proxy-Signature' => $hmac_to_site_a,
				),
				'body'     => $payload_json,
				'blocking' => false,
			)
		);

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
}
SiteB_Payment_Proxy_Gateway_Iframe::get_instance();