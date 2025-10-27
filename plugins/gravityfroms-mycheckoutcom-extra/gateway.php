<?php
/*
Plugin Name: Gravity Forms Checkout.com Gateway Extra
Plugin URI: https://wpgateways.com/products/checkout-com-gateway-gravity-forms/
Description: Extends Gravity Forms to process payments with Checkout.com payment gateway
Version: 1.1.7.0
Author: Your Name
Author URI: https://youtsite.com
Text Domain: gf-checkout-com
Domain Path: /languages
*/

define( 'GF_CHECKOUT_COM_VERSION', '1.1.7.0' );
define( 'GF_CHECKOUT_COM_PLUGIN_DIR', __DIR__ );
define( 'GF_CHECKOUT_COM_PLUGIN_URL', plugins_url( plugin_basename( GF_CHECKOUT_COM_PLUGIN_DIR ) ) );
define( 'GF_CHECKOUT_COM_PLUGIN_PATH', plugin_basename( __FILE__ ) );

/**
 * Bootstrap class for the Gravity Forms Checkout.com Gateway plugin.
 *
 * This class handles the initialization and loading of the plugin dependencies,
 * including registering the add-on with Gravity Forms.
 */
class GF_Checkout_Com_Bootstrap {
	/**
	 * Load the plugin dependencies and register the add-on with Gravity Forms.
	 *
	 * @return void
	 */
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		load_plugin_textdomain( 'gf-checkout-com', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		require_once 'updates/updates.php';
		require_once 'class-gateway.php';
		require_once 'class-brevo-data.php';
		require_once 'class-brevo-mailer.php';
		require_once 'class-customize-pdf-data.php';
		require_once 'class-brevo-mail-extra-addon.php';
		require_once 'class-pay-later.php';
		require_once 'class-resend-email.php';

		require_once 'gpep-edit-entry.php';

		GFAddOn::register( 'GF_Checkout_Com' );
		GFAddOn::register( 'GF_Brevo_Mail_Extra_AddOn' );
		GF_Checkout_Com_Resend_Email::get_instance();
	}
}
add_action( 'gform_loaded', array( 'GF_Checkout_Com_Bootstrap', 'load' ), 5 );

/**
 * Returns an instance of the GF_Checkout_Com class.
 *
 * @return GF_Checkout_Com Instance of GF_Checkout_Com.
 */
function gf_checkout_com() {
	return GF_Checkout_Com::get_instance();
}

// load js file.
add_action( 'wp_enqueue_scripts', 'gf_checkout_com_extra_scripts_and_styles' );
/**
 * Function for loading custom scripts
 *
 * @return void
 */
function gf_checkout_com_extra_scripts_and_styles() {
	// For Payment form styles.
	wp_enqueue_style( 'gf-checkout-com-extra-payment-page', plugins_url( 'public/css/payment_form_style.css', __FILE__ ), array(), GF_CHECKOUT_COM_VERSION );

	// For Thank you page dynamic feild.
	wp_enqueue_script( 'gf-checkout-com-extra-thankyou-page', plugins_url( 'public/js/thankyou_page_fields.js', __FILE__ ), array( 'jquery' ), GF_CHECKOUT_COM_VERSION, true );

	// For adding data brevo on exit intent.
	wp_enqueue_script( 'gf-checkout-com-extra-add-data-in-brevo', plugins_url( 'public/js/add_data_in_brevo.js', __FILE__ ), array( 'jquery' ), GF_CHECKOUT_COM_VERSION, true );
	wp_localize_script(
		'gf-checkout-com-extra-add-data-in-brevo',
		'send_data_to_brevo_obj',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'send_data_to_brevo_nonce' ),
		)
	);

	// For adding data into local storage.
	wp_enqueue_script( 'gf-checkout-com-extra-local-storage', plugins_url( 'public/js/local-storage.js', __FILE__ ), array( 'jquery' ), GF_CHECKOUT_COM_VERSION, true );

	// For custom popup style.
	wp_enqueue_style( 'custom-popup-style', plugins_url( 'public/css/custom-popup-style.css', __FILE__ ), array(), GF_CHECKOUT_COM_VERSION );
}


/**
 * Fixes a bug in Gravity Forms where the notification field name is malformed.
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		// Only load on Gravity Forms admin pages
		if ( ! class_exists( 'GFForms' ) || ! GFForms::is_gravity_page() ) {
			return;
		}

		wp_add_inline_script(
			'jquery',
			<<<JS
jQuery(document).ready(function($) {
	var \$select = $('[name="_gform_setting_notifications[][]"]');
	if (\$select.length) {
		console.log('Fixing malformed notifications[] field name');
		\$select.attr('name', '_gform_setting_notifications[]');
	}
});
JS
		);
	}
);


add_action( 'gf_brevo_send_email_scheduled', 'gf_brevo_send_email_scheduled' );
/**
 * Sends a scheduled email through the Brevo API with optional attachments.
 *
 * @param array $args The arguments for the API request including the email body and attachments.
 * @return bool|WP_Error True if successful, WP_Error on failure.
 */
function gf_brevo_send_email_scheduled( $args ) {

	// Decode JSON body.
	$body = json_decode( $args['body'], true );

	if ( isset( $body['entry_id'] ) && ! empty( $body['entry_id'] ) ) {
		$entry_id = $body['entry_id'];
		unset( $body['entry_id'] );
	}
	if ( isset( $body['to'][0]['email'] ) && ! empty( $body['to'][0]['email'] ) ) {
		$email = $body['to'][0]['email'];
	}

	if ( ! empty( $body['raw_attachments'] ) && is_array( $body['raw_attachments'] ) ) {
		$attachment_payloads = array();

		foreach ( $body['raw_attachments'] as $attachment ) {
			if ( isset( $attachment['url'], $attachment['name'] ) ) {
				$file_path = download_url( $attachment['url'] );
				if ( ! is_wp_error( $file_path ) && file_exists( $file_path ) ) {
					$file_content = file_get_contents( $file_path );
					if ( $file_content ) {
						$attachment_payloads[] = array(
							'content' => base64_encode( $file_content ),
							'name'    => basename( $attachment['name'] ),
						);
					}
					@unlink( $file_path );
				}
			}
		}

		if ( ! empty( $attachment_payloads ) ) {
			$body['attachment'] = $attachment_payloads;
		}

		unset( $body['raw_attachments'] );
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_post( 'https://api.brevo.com/v3/smtp/email', $args );

	if ( is_wp_error( $response ) ) {
		GFCommon::log_debug( __METHOD__ . '(): Brevo API request failed: ' . $response->get_error_message() );
		return $response;
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );

	if ( $response_code >= 200 && $response_code < 300 ) {
		GFCommon::log_debug( __METHOD__ . '(): Brevo email sent successfully. Response: ' . $response_body );
		if ( class_exists( 'GFAPI' ) && $entry_id ) {
			GFAPI::add_note( $entry_id, 'gravityforms-checkout-com-extra', 'Brevo Mailer', 'Delayed mail sended successfully on email: ' . $email, 'brevo-mailer', 'success' );
		}
		return true;
	} else {
		GFCommon::log_debug( __METHOD__ . '(): Failed to send Brevo email. Code: ' . $response_code . '. Body: ' . $response_body );
		if ( class_exists( 'GFAPI' ) && $entry_id ) {
			GFAPI::add_note( $entry_id, 'gravityforms-checkout-com-extra', 'Brevo Mailer', 'Failed to send mail on : ' . $email, 'brevo-mailer', 'error' );
		}
		return new WP_Error(
			'api_error',
			'Failed to send Brevo email.',
			array(
				'status' => $response_code,
				'body'   => $response_body,
			)
		);
	}
}

add_filter( 'gform_notification_events', 'your_plugin_add_custom_event', 10, 2 );
function your_plugin_add_custom_event( $notification_events, $form ) {
	$notification_events['payment_completed_custom'] = __( 'Custom Payment Completed', 'gf-checkout-com' );
	return $notification_events;
}
