<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GF_Checkout_Com_Pay_Later
 *
 * Handles all "Retrieve & Pay" functionality for the Site A proxy gateway.
 */
class GF_Checkout_Com_Pay_Later {

	/**
	 * The single instance of the class.
	 *
	 * @var GF_Checkout_Com_Pay_Later
	 */
	private static $_instance = null;

	/**
	 * Gets the single instance of this class.
	 *
	 * @return GF_Checkout_Com_Pay_Later
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'gf_checkout_pay_later', array( $this, 'render_shortcode_container' ) );
		add_action( 'wp_ajax_gf_proxy_find_entries', array( $this, 'ajax_find_entries_handler' ) );
		add_action( 'wp_ajax_nopriv_gf_proxy_find_entries', array( $this, 'ajax_find_entries_handler' ) );
	}

	/**
	 * Renders the shortcode's initial HTML container and enqueues necessary scripts.
	 *
	 * @return string The HTML for the initial lookup form container.
	 */
	public function render_shortcode_container() {
		wp_enqueue_script(
			'gf-checkout-pay-later-js',
			plugin_dir_url( __FILE__ ) . 'public/js/pay-later.js',
			array( 'jquery' ),
			GF_CHECKOUT_COM_VERSION,
			true
		);

		wp_enqueue_style( 'gf-checkout-pay-later-css', plugin_dir_url( __FILE__ ) . 'public/css/pay-later.css', array(), GF_CHECKOUT_COM_VERSION );

		wp_localize_script(
			'gf-checkout-pay-later-js',
			'payLaterAjax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gf_pay_later_lookup_nonce' ),
			)
		);

		ob_start();
		?>
		<div id="gf-pay-later-app">
			<div class="gf-pay-later-lookup-container">
				<h2 class="gf-pay-later-heading">Retrieve Your Application</h2>
				<p>Enter the email address you used during submission to find your application and complete your payment.</p>
				<form id="gf-pay-later-lookup-form" action="" method="post">
					<input type="email" id="gf_lookup_identifier" name="gf_lookup_identifier" required aria-label="Your Email Address" placeholder="Enter your email address here" />
					<button type="submit" id="gf-lookup-submit-button" class="button">Find My Application</button>
				</form>
			</div>
			
			<div id="gf-pay-later-loading" style="display:none; text-align:center; padding: 20px;">
				<p><em>Searching...</em></p>
			</div>

			<div id="gf-pay-later-results" style="display:none; margin-top: 30px;">
				<!-- AJAX results will be injected here -->
			</div>
			<div id="unsubmitted-forms-link">
				<span>Retrieve your unsubmitted forms by reopen it: <a href="https://mypassportcenter.com/#passport-sec">Click Here</a></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

		/**
		 * Handles the AJAX request to find unpaid entries with pagination,
		 * correctly searching across multiple forms.
		 */
	public function ajax_find_entries_handler() {
		if ( ! check_ajax_referer( 'gf_pay_later_lookup_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ), 403 );
			return;
		}

		// Input sanitization.
		$identifier = isset( $_POST['identifier'] ) ? sanitize_email( wp_unslash( $_POST['identifier'] ) ) : '';
		$page       = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page   = 10; // Set how many items per page.

		if ( empty( $identifier ) ) {
			wp_send_json_error( array( 'message' => 'A valid email address is required.' ), 400 );
			return;
		}

		try {
			$gateway_instance = GF_Checkout_Com::get_instance();
			$settings         = $gateway_instance->get_plugin_settings();
			$payment_page_url = rgar( $settings, 'payment_page_url' );

			if ( empty( $payment_page_url ) ) {
				wp_send_json_error( array( 'message' => 'Payment gateway is not configured. Please contact support.' ), 500 );
				return;
			}

			// Find all form IDs that have a proxy gateway feed.
			$all_forms          = GFAPI::get_forms( true, false );
			$form_ids_with_feed = array_reduce(
				$all_forms,
				function ( $carry, $form ) use ( $gateway_instance ) {
					if ( $gateway_instance->has_feed( $form['id'] ) ) {
						$carry[] = $form['id'];
					}
					return $carry;
				},
				array()
			);

			if ( empty( $form_ids_with_feed ) ) {
				wp_send_json_success(
					array(
						'entries'         => array(),
						'pagination_html' => null,
					)
				);
				return;
			}

			// --- CORRECTED MULTI-FORM SEARCH LOGIC ---
			$all_matching_entries = array();
			$base_search_criteria = array(
				'status'        => 'active',
				'field_filters' => array(
					'mode' => 'all',
					array(
						'key'      => '2',
						'value'    => $identifier,
						'operator' => 'is',
					),
					array(
						'key'      => 'payment_status',
						'operator' => 'IN',
						'value'    => array( 'Processing', 'Failed' ),
					),
				),
			);

			// Loop through each form and get its matching entries.
			foreach ( $form_ids_with_feed as $form_id ) {
				$entries_for_form = GFAPI::get_entries( $form_id, $base_search_criteria );
				if ( ! empty( $entries_for_form ) ) {
					$all_matching_entries = array_merge( $all_matching_entries, $entries_for_form );
				}
			}

			$total_entries = count( $all_matching_entries );

			if ( 0 === $total_entries ) {
				wp_send_json_success(
					array(
						'entries'         => array(),
						'pagination_html' => null,
					)
				);
				return;
			}

			// Manually sort all found entries by date, descending.
			usort(
				$all_matching_entries,
				function ( $a, $b ) {
					return strtotime( $b['date_created'] ) - strtotime( $a['date_created'] );
				}
			);

			// Manually apply pagination to the final merged array.
			$offset            = ( $page - 1 ) * $per_page;
			$paginated_entries = array_slice( $all_matching_entries, $offset, $per_page );

			// --- End of corrected logic ---

			// Step 3: Format results for the frontend table.
			$results       = array();
			$counter_start = $offset + 1;

			if ( ! empty( $paginated_entries ) ) {
				foreach ( $paginated_entries as $index => $entry ) {
					$form = GFAPI::get_form( $entry['form_id'] );
					if ( ! $form ) {
						continue;
					}

					$pay_now_url   = add_query_arg( 'entry_id', $entry['id'], $payment_page_url );
					$fullname      = trim( rgar( $entry, '1.3' ) . ' ' . rgar( $entry, '1.6' ) );
					$date_of_birth = rgar( $entry, '5' );

					$results[] = array(
						'number'         => $counter_start + $index,
						'entry_id'       => esc_html( rgar( $entry, 'id' ) ),
						'form_title'     => esc_html( $form['title'] ),
						'name'           => esc_html( $fullname ),
						'dob'            => esc_html( $date_of_birth ),
						'date_created'   => esc_html( GFCommon::format_date( $entry['date_created'], true ) ),
						'payment_amount' => esc_html( GFCommon::to_money( $entry['payment_amount'], $entry['currency'] ) ),
						'payment_url'    => esc_url( $pay_now_url ),
					);
				}
			}

			// Step 4: Prepare pagination data.
			$total_pages     = ceil( $total_entries / $per_page );
			$pagination_html = $this->generate_pagination_html( $page, $total_pages );

			wp_send_json_success(
				array(
					'entries'         => $results,
					'pagination_html' => $pagination_html,
				)
			);

		} catch ( Exception $e ) {
			error_log( 'GF Proxy Pay Later Error: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => 'An unexpected server error occurred.' ), 500 );
		}
	}

		/**
		 * Generates intelligent pagination HTML with ellipses for large page sets.
		 *
		 * @param int $current_page The current active page.
		 * @param int $total_pages  The total number of pages.
		 * @return string The generated HTML for the pagination controls.
		 */
	private function generate_pagination_html( $current_page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return ''; // No pagination needed.
		}

		$range = 1; // How many pages to show around the current page.
		$html  = '<div class="gform-pagination">';

		// "Previous" link
		if ( $current_page > 1 ) {
			$prev_page = $current_page - 1;
			$html     .= "<a href='#' class='pagination-link prev' data-page='{$prev_page}'>«</a>";
		}

		$ellipsis_shown = false;
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			// Conditions to show a page link:
			// 1. It's the first page.
			// 2. It's the last page.
			// 3. It's within the range of the current page.
			if ( $i == 1 || $i == $total_pages || ( $i >= $current_page - $range && $i <= $current_page + $range ) ) {
				$class          = ( $i == $current_page ) ? 'active' : '';
				$html          .= "<a href='#' class='pagination-link {$class}' data-page='{$i}'>{$i}</a>";
				$ellipsis_shown = false; // Reset ellipsis since we just showed a number.
			} elseif ( ! $ellipsis_shown ) {
				// If we are in a gap, show an ellipsis, but only once per gap.
				$html          .= "<span class='pagination-ellipsis'>…</span>";
				$ellipsis_shown = true;
			}
		}

		// "Next" link
		if ( $current_page < $total_pages ) {
			$next_page = $current_page + 1;
			$html     .= "<a href='#' class='pagination-link next' data-page='{$next_page}'>»</a>";
		}

		$html .= '</div>';
		return $html;
	}
}

// Initialize the class.
GF_Checkout_Com_Pay_Later::get_instance();