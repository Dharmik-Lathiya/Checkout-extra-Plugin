<?php
if (! class_exists('GFForms')) {
	die();
}

GFForms::include_payment_addon_framework();

/**
 * Checkout.com payment gateway integration for Gravity Forms.
 *
 * @since 3.3.0
 */
class GF_Checkout_Com extends GFPaymentAddOn
{

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
	public function plugin_settings_fields()
	{
		return array(
			array(
				'title'       => esc_html__('Checkout.com Iframe Proxy Settings', 'gf-checkout-com'),
				'description' => '<p>' . esc_html__('To complete the setup, create a new page on this site and add the following shortcode to its content area:', 'gf-checkout-com') . '</p>' . '<pre><code>[gf_checkout_payment_frame]</code></pre>',
				'fields'      => array(
					array(
						'name'     => 'payment_page_url',
						'label'    => esc_html__('This Payment Page URL', 'gf-checkout-com'),
						'type'     => 'text',
						'class'    => 'large',
						'required' => true,
						'tooltip'  => '<h6>' . esc_html__('Payment Page URL', 'gf-checkout-com') . '</h6>' . esc_html__('Enter the full URL of the page where you placed the shortcode. Copy this value into the "Site A Payment Page URL" field on Site B.', 'gf-checkout-com'),
					),
					array(
						'name'     => 'site_b_proxy_url',
						'label'    => esc_html__('Site B Proxy Checkout Page URL', 'gf-checkout-com'),
						'type'     => 'text',
						'class'    => 'large',
						'required' => true,
						'tooltip'  => '<h6>' . esc_html__('Proxy URL', 'gf-checkout-com') . '</h6>' . esc_html__('The URL of the page on Site B where you placed the [siteb_payment_frame] shortcode.', 'gf-checkout-com'),
					),
					array(
						'name'       => 'shared_secret',
						'label'      => esc_html__('Shared Secret Key', 'gf-checkout-com'),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'required'   => true,
					),
					// NEW: URL for Site B to call to verify a 3DS session.
					array(
						'name'        => 'site_b_3ds_verification_url',
						'label'       => esc_html__('Site B 3DS Verification URL', 'gf-checkout-com'),
						'type'        => 'text',
						'class'       => 'large',
						'required'    => true,
						'tooltip'     => '<h6>' . esc_html__('3DS Verification URL', 'gf-checkout-com') . '</h6>' . esc_html__('The URL of the `/verify-3ds-session` endpoint on Site B.', 'gf-checkout-com'),
						'description' => '<small>' . esc_html__('Copy the "This Site\'s 3DS Verification URL" value from the Site B plugin settings and paste it here.', 'gf-checkout-com') . '</small>',
					),
					array(
						'name'          => 'validation_url_display',
						'label'         => esc_html__('Validation URL for Site B', 'gf-checkout-com'),
						'type'          => 'text',
						'readonly'      => true,
						'class'         => 'large code',
						'default_value' => rest_url('gf-checkout-proxy/v1/get-payment-details'),
						'description'   => '<small>' . esc_html__('Copy this URL into the "Site A Validation URL" field on Site B.', 'gf-checkout-com') . '</small>',
					),
					array(
						'name'          => 'callback_url_display',
						'label'         => esc_html__('Final Callback URL for Site B', 'gf-checkout-com'),
						'type'          => 'text',
						'readonly'      => true,
						'class'         => 'large code',
						'default_value' => rest_url('gf-checkout-proxy/v1/callback'),
						'description'   => '<small>' . esc_html__('Copy this URL into the "Site A Final Callback URL" field on Site B.', 'gf-checkout-com') . '</small>',
					),
				),
			),
		);
	}

	/**
	 * Generates the redirect URL for the apyment page.
	 *
	 * @param array $feed            The feed object currently being processed.
	 * @param array $submission_data The customer and transaction data.
	 * @param array $form           The form object currently being processed.
	 * @param array $entry          The entry object currently being processed.
	 *
	 * @return string|false The redirect URL or false if payment page URL is not configured.
	 */
	public function redirect_url($feed, $submission_data, $form, $entry)
	{
		$settings         = $this->get_plugin_settings();
		$payment_page_url = rgar($settings, 'payment_page_url');

		if (empty($payment_page_url)) {
			$this->log_error(__METHOD__ . '(): Payment Page URL is not configured on Site A.');
			return false;
		}

		// Update core entry properties.
		GFAPI::update_entry_property($entry['id'], 'payment_status', 'Processing');

		$payment_amount = rgar($submission_data, 'payment_amount');

		// MODIFICATION OF AMOUNT FOR TESTER.
		if (function_exists('get_current_user_id') && function_exists('current_user_can')) {
			if (get_current_user_id() === 6 && current_user_can('manage_options')) {
				$payment_amount = 1;
				error_log('Modify Payment amount from ' . $payment_amount . 'to 1 for admin with id #6');
			}
		}
		// END MODIFICATION.
		GFAPI::update_entry_property($entry['id'], 'payment_amount', $payment_amount);

		// Prepare redirect URL.
		$redirect_url = add_query_arg(array('entry_id' => $entry['id']), $payment_page_url);
		$this->log_debug(__METHOD__ . '(): Redirecting to iframe shortcode page: ' . $redirect_url);

		// Log the submission data and other custom meta if needed for debugging.
		$url = gf_apply_filters('gform_checkout_com_request', $form['id'], $redirect_url, $form, $entry, $feed, $submission_data);
		gform_update_meta($entry['id'], 'checkout_com_payment_url', $url);
		gform_update_meta($entry['id'], 'submission_data', $submission_data);

		return $redirect_url;
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
	/**
	 * Get error message for a specific reason code.
	 *
	 * @param string $reason_code     The error reason code.
	 * @param string $default_message The default error message.
	 *
	 * @return string The error message.
	 */
	public function get_error_message($reason_code, $default_message)
	{

		switch ($reason_code) {
			case '20087':
				$message = esc_html__('Invalid CVV and/or expiry date.', 'gf-checkout-com');
				break;

			case '20012':
				$message = esc_html__('The issuer has declined the transaction because it is invalid. The cardholder should contact their issuing bank.', 'gf-checkout-com');
				break;

			case '20013':
				$message = esc_html__('Invalid amount or amount exceeds maximum for card program.', 'gf-checkout-com');
				break;

			case '20003':
				$message = esc_html__('There was an error processing your credit card. Please verify the information and try again.', 'gf-checkout-com');
				break;

			case '20150':
				$message = esc_html__('Card not 3D Secure (3DS) enabled.', 'gf-checkout-com');
				break;

			case '20151':
				$message = esc_html__('Cardholder failed 3DS authentication.', 'gf-checkout-com');
				break;

			case '20155':
				$message = esc_html__('3DS authentication service provided invalid authentication result.', 'gf-checkout-com');
				break;

			default:
				$message = $default_message;
		}

		$message = '<-- Error: ' . $reason_code . ' --> ' . $message;

		return $message;
	}

	/**
	 * AJAX handler to create a Checkout.com Payment Session for Flow.
	 */
	public function ajax_create_checkout_session()
	{
		check_ajax_referer('gf_checkout_com_create_session', 'nonce');

		$entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
		$form_id  = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;

		if (! $entry_id || ! $form_id) {
			wp_send_json_error(array('message' => 'Missing form or entry ID.'), 400);
		}

		$entry = GFAPI::get_entry($entry_id);
		$feed  = $this->get_payment_feed($entry);
		if (! $entry || ! $feed) {
			wp_send_json_error(array('message' => 'Could not retrieve entry or feed.'), 404);
		}

		$api_settings    = $this->get_api_settings($feed);
		$submission_data = $this->get_submission_data($feed, GFAPI::get_form($form_id), $entry);
		$amount_in_cents = $this->get_amount_export(rgar($entry, 'payment_amount'), rgar($entry, 'currency'));
		// --- THIS IS THE CORRECTED PAYLOAD --- //
		$payload = array(
			'amount'                => $amount_in_cents,
			'currency'              => rgar($entry, 'currency'),
			'reference'             => rgar($submission_data, 'reference') ?: "GF-{$form_id}-{$entry_id}",
			'processing_channel_id' => $api_settings['processing_channel_id'],

			// FIX #1: Added the complete billing object with address and phone.
			'billing'               => array(
				'address' => array(
					'address_line1' => rgar($submission_data, 'address'),
					'address_line2' => rgar($submission_data, 'address2'),
					'city'          => rgar($submission_data, 'city'),
					'state'         => rgar($submission_data, 'state'),
					'zip'           => rgar($submission_data, 'zip'),
					'country'       => $this->get_country_code(rgar($submission_data, 'country')),
				),
				'phone'   => array(
					'number' => rgar($submission_data, 'phone'),
				),
			),

			'customer'              => array(
				'email' => rgar($submission_data, 'email'),
				'name'  => trim(rgar($submission_data, 'firstName') . ' ' . rgar($submission_data, 'lastName')),
			),

			// FIX #2: The URL generation is correct, but this will only work on a public server, not localhost.
			'success_url'           => esc_url_raw(add_query_arg('cko-session-id', '{cko-session-id}', $this->return_url($form_id, $entry_id))),
			'failure_url'           => esc_url_raw($this->return_url($form_id, $entry_id, 'cancel')),
			'metadata'              => array(
				'form_id'  => $form_id,
				'entry_id' => $entry_id,
			),
		);

		$api_url = ('test' === $api_settings['mode']) ? 'https://api.sandbox.checkout.com/payment-sessions' : 'https://api.checkout.com/payment-sessions';

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_settings['secret_key'],
				'Content-Type'  => 'application/json;charset=UTF-8',
			),
			'body'    => wp_json_encode($payload),
			'timeout' => 60,
		);
		$this->log_debug(__METHOD__ . '(): Creating Payment Session. Payload: ' . print_r($payload, true));
		$response = wp_remote_post($api_url, $args);

		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => $response->get_error_message()), 500);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		// --- FINAL FIX FOR PHP --- //
		if (! empty($body['payment_session_token'])) {
			// Send the ENTIRE successful body object back, not just the token.
			wp_send_json_success($body);
		} else {
			$this->log_error(__METHOD__ . '(): Failed to create payment session. Response: ' . print_r($body, true));
			wp_send_json_error(
				array(
					'message' => 'Failed to create payment session.',
					'details' => $body,
				),
				500
			);
		}
	}


	public function get_currency_specific_prices($form_title)
	{
		$page_type = false;
		if (stripos($form_title, 'esta') !== false) {
			$page_type = 'esta';
		} elseif (stripos($form_title, 'visa') !== false) {
			$page_type = 'visa';
		}

		if (! $page_type) {
			return array();
		}
		$this->log_debug(__METHOD__ . "(): Determined page type as '{$page_type}'. Fetching prices.");

		$prices = array();
		if ('esta' === $page_type) {
			$prices = get_option('checkout_esta_prices', array());
		} elseif ('visa' === $page_type) {
			$prices = get_option('checkout_visa_prices', array());
		}

		if (empty($prices)) {
			return array();
		}

		// Format prices: ['USD' => ['price' => 175, 'sign' => '$', 'code' => 'USD'], ...].
		$formatted_prices = array();
		foreach ($prices as $price_entry) {
			if (! empty($price_entry['currency'])) {
				$code                      = strtoupper($price_entry['currency']);
				$formatted_prices[$code] = array(
					'price' => floatval($price_entry['price']),
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
	public function ajax_update_entry_currency()
	{
		// 1. Security Check
		check_ajax_referer('gf_checkout_com_update_currency', 'nonce');

		// 2. Get and sanitize input
		$entry_id          = absint($_POST['entry_id']);
		$form_id           = absint($_POST['form_id']);
		$selected_currency = sanitize_text_field(strtoupper($_POST['currency']));

		if (! $entry_id || ! $form_id || ! $selected_currency) {
			wp_send_json_error(array('message' => 'Invalid data provided.'));
		}

		// 3. Load necessary objects
		$entry = GFAPI::get_entry($entry_id);
		$form  = GFAPI::get_form($form_id);

		if (is_wp_error($entry) || ! $form) {
			wp_send_json_error(array('message' => 'Could not load form or entry.'));
		}

		// Ensure the request is for this gateway.
		if (! $this->is_payment_gateway($entry['id'])) {
			wp_send_json_error(array('message' => 'Payment gateway mismatch.'));
		}

		// 4. Get available prices and find the new price
		$available_prices = $this->get_currency_specific_prices($form['title']);

		if (! isset($available_prices[$selected_currency])) {
			wp_send_json_error(array('message' => 'Selected currency is not available.'));
		}

		$new_price_info = $available_prices[$selected_currency];
		$new_amount     = $new_price_info['price'];
		$new_currency   = $new_price_info['code'];

		// // --- NEW: ADMIN PRICE OVERRIDE (DURING AJAX) ---
		if (function_exists('get_current_user_id') && function_exists('current_user_can') && get_current_user_id() === 8 && current_user_can('manage_options')) {

			$this->log_debug(__METHOD__ . '(): Maintaining payment amount of 1 for admin user ID 8 during currency switch.');
			$new_amount = 1;
		}
		// // --- END ADMIN PRICE OVERRIDE ---

		// 5. Update the entry in the database
		$entry['payment_amount'] = $new_amount;
		$entry['currency']       = $new_currency;
		$result                  = GFAPI::update_entry($entry);

		if (is_wp_error($result)) {
			$this->log_error(__METHOD__ . '(): Failed to update entry ' . $entry_id . ' - ' . $result->get_error_message());
			wp_send_json_error(array('message' => 'Failed to update payment details.'));
		}

		$this->log_debug(__METHOD__ . "(): Successfully updated entry {$entry_id} to {$new_amount} {$new_currency}.");

		// 6. Send success response with new details for display
		wp_send_json_success(
			array(
				'new_amount_formatted' => $new_price_info['sign'] . number_format($new_amount, 2),
				'new_total_text'       => 'Total Amount: ' . $new_price_info['sign'] . number_format($new_amount, 2),
			)
		);
	}
}
