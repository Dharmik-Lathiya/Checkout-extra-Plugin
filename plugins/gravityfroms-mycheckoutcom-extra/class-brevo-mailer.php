<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GF_Checkout_Com_Brevo_Mailer
 *
 * Handles sending transactional emails through the Brevo API integration.
 */
class GF_Checkout_Com_Brevo_Mailer {
	/**
	 * Brevo API key for authentication.
	 *
	 * @var string
	 */
	private $api_key;
	const BREVO_API_URL = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Constructor. Retrieves the Brevo API key from settings.
	 */
	public function __construct() {
		$brevo_settings = get_option( 'gravityformsaddon_gravityformsbrevo_settings' );
		$this->api_key  = rgar( $brevo_settings, 'brevo_api_key' );
	}

	/**
	 * Sends a transactional email using a Brevo template.
	 *
	 * @param int         $template_id The ID of the Brevo template.
	 * @param array       $to          Recipient details. e.g., [['email' => 'john.doe@example.com', 'name' => 'John Doe']].
	 * @param array       $params      Associative array of parameters for the template. e.g., ['FIRST_NAME' => 'John'].
	 * @param string|null $attachments Attachments for the email.
	 * @param bool|null   $delay       Whether to delay sending the email by 30 minutes.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function send_transactional_email( $template_id, $to, $params = array(), $attachments = array(), $entry_id, $delay = null ) {
		if ( empty( $this->api_key ) ) {
			GFCommon::log_debug( __METHOD__ . '(): Brevo API Key is not configured.' );
			return new WP_Error( 'api_key_missing', 'Brevo API Key is not configured.' );
		}

		if ( empty( $template_id ) || empty( $to ) ) {
			GFCommon::log_debug( __METHOD__ . '(): Template ID and recipient are required.' );
			return new WP_Error( 'missing_params', 'Template ID and recipient are required.' );
		}

		// === Prepare Common Payload ===
		$payload = array(
			'to'         => $to,
			'templateId' => (int) $template_id,
			'params'     => (object) $params,
		);

		if ( $entry_id ) {
			$payload['entry_id'] = $entry_id; // For Saving note of delay mail status.
		}

		// === If delay is TRUE, skip base64 encoding, schedule it ===
		if ( true === $delay ) {
			$payload['raw_attachments'] = $attachments;  // For Adding attachments links.

			$args = array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'api-key'      => $this->api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			);

			wp_schedule_single_event( time() + 1800, 'gf_brevo_send_email_scheduled', array( $args ) );
			GFCommon::log_debug( __METHOD__ . '(): Email scheduled to send after 30 minutes.' );
			return 'scheduled';
		} else {
			// === Immediate send: process and attach base64-encoded files ===
			if ( is_array( $attachments ) && ! empty( $attachments ) ) {
				$attachment_payloads = array();

				foreach ( $attachments as $attachment ) {
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
						} else {
							GFCommon::log_debug( __METHOD__ . '(): Failed to download attachment: ' . $attachment['url'] );
						}
					}
				}

				if ( ! empty( $attachment_payloads ) ) {
					$payload['attachment'] = $attachment_payloads;
				}
			}

					$args = array(
						'method'  => 'POST',
						'timeout' => 30,
						'headers' => array(
							'api-key'      => $this->api_key,
							'Content-Type' => 'application/json',
							'Accept'       => 'application/json',
						),
						'body'    => wp_json_encode( $payload ),
					);

					$response = wp_remote_post( 'https://api.brevo.com/v3/smtp/email', $args );

					GFCommon::log_debug( __METHOD__ . '(): Payload: ' . print_r( $payload, true ) );
					GFCommon::log_debug( __METHOD__ . '(): Response: ' . print_r( $response, true ) );

					if ( is_wp_error( $response ) ) {
						GFCommon::log_debug( __METHOD__ . '(): Brevo API request failed: ' . $response->get_error_message() );
						return $response;
					}

					$response_code = wp_remote_retrieve_response_code( $response );
					$response_body = wp_remote_retrieve_body( $response );

					if ( $response_code >= 200 && $response_code < 300 ) {
						GFCommon::log_debug( __METHOD__ . '(): Brevo email sent successfully. Response: ' . $response_body );
						return true;
					} else {
						GFCommon::log_debug( __METHOD__ . '(): Failed to send Brevo email. Code: ' . $response_code . '. Body: ' . $response_body );
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
	}
}
