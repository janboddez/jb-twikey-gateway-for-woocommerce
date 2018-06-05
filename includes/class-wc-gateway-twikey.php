<?php
/**
 * Part of the WooCommerce Twikey Payment Gateway plugin that contains the main
 * payment gateway class.
 *
 * @package WooCommerce_Gateway_Twikey
 */

if ( ! class_exists( 'WC_Gateway_Twikey' ) ) :
/**
 * Twikey WooCommerce Payment Gateway class.
 *
 * The Twikey Payment Gateway for WooCommerce adds Twikey compatibility to
 * WooCommerce and WooCommerce Subscriptions. This class holds the bulk of the
 * functionality.
 * 
 * @since 0.1.0
 */
class WC_Gateway_Twikey extends WC_Payment_Gateway {
	/**
	 * Class constructor.
	 *
	 * @since 0.1.0
	 */
	function __construct() {
		$this->id = 'twikey';
		$this->icon = null;
		$this->has_fields = true;
		$this->method_title = __( 'Twikey', 'jb-wc-twikey' );
		$this->method_description = __( 'Use your debit card or eID to sign a recurring payment mandate on checkout.', 'jb-wc-twikey' );
		$this->supports = array( 
			'products',
			'subscriptions',
			//'subscription_cancellation',
			//'subscription_suspension',
			//'subscription_reactivation',
			//'subscription_payment_method_change',
		);
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		/*
		 * To be used in combination with a Twikey webhook. The callback URL
		 * will look like: http://yoursite.com/wc-api/wc_gateway_twikey/.
		 */
		add_action( 'woocommerce_api_'. strtolower( get_class( $this ) ), array( $this, 'callback_handler' ) );
	}

	/**
	 * Defines admin options for the this payment gateway.
	 *
	 * @since 0.1.0
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title' => __( 'Enable/Disable', 'jb-wc-twikey' ),
				'label' => __( 'Enable this payment gateway', 'jb-wc-twikey' ),
				'type' => 'checkbox',
				'default' => 'no',
			),
			'title' => array(
				'title' => __( 'Title', 'jb-wc-twikey' ),
				'type' => 'text',
				'default' => __( 'Twikey', 'jb-wc-twikey' ),
				'desc_tip' => __( 'Payment method name shown on checkout.', 'jb-wc-twikey' ),
			),
			'description' => array(
				'title' => __( 'Customer Message', 'jb-wc-twikey' ),
				'type' => 'textarea',
				'default' => __( 'Use your debit card or eID to sign a recurring payment mandate on checkout.', 'jb-wc-twikey' ),
				'desc_tip' => __( 'Payment method description shown on checkout.', 'jb-wc-twikey' ),
			),
			'api_token' => array(
				'title' => __( 'API Token', 'jb-wc-twikey' ),
				'type' => 'text',
				'description' => sprintf( __( 'Your <a href="%s" target="_blank" rel="noopener">API token</a>.', 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/settings/ei' ),
			),
			'private_key' => array(
				'title' => __( 'Private Key', 'jb-wc-twikey' ),
				'type' => 'text',
				'description' => sprintf( __( 'Your <a href="%s" target="_blank" rel="noopener">private key</a>.', 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/settings/ip' ),
			),
			'contract_template' => array(
				'title' => __( 'Contract Template', 'jb-wc-twikey' ),
				'type' => 'text',
				'description' => sprintf( __( 'The unique ID of the <a href="%s" target="_blank" rel="noopener">contract template</a> to be used.', 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/contracttemplate' ),
			),
			'callback_type' => array(
				'title' => __( 'Callback Type', 'jb-wc-twikey' ),
				'type' => 'select',
				'default' => 'none',
				'options' => array(
					'none' => __( 'None', 'jb-wc-twikey' ),
					//'webhook' => __( 'Webhook', 'jb-wc-twikey' ),
					'exit_url' => __( 'Exit URL', 'jb-wc-twikey' ),
				 ),
				'description' => __( 'The type of callback (if any) used to communicate back to WooCommerce after a mandate is signed.', 'jb-wc-twikey' ) . '<br />'
					//. sprintf( __( "When set to 'Webhook', you <strong>must</strong> set the callback URL in the Twikey <a href='%s' target='_blank'>API settings</a> to %s.", 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/settings/ei', '<code>http(s)://mysite.com/wc-api/wc_gateway_twikey/</code>' ) . '<br />'
					. sprintf( __( "When set to 'Exit URL', you <strong>must</strong> configure the Exit URL(s) in the Twikey <a href='%s' target='_blank' rel='noopener'>contract template settings</a> to %s.", 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/contracttemplate', '<code>http(s)://mysite.com/wc-api/wc_gateway_twikey/?mandateNumber={0}&state={1}&sig={3}</code>' ),
			),
		);
	}

	/**
	 * Contains most of the payment processing. Additional checks may be run in
	 * the callback handler (@see WC_Gateway_Twikey::callback_handler()).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id The unique ID of the order being processed.
	 */
	function process_payment( $order_id ) {
		global $woocommerce;
		$order = new WC_Order( $order_id );

		/* Step 1. Get a fresh autorization token. */
		$auth_token = $this->get_auth();

		if ( false === $auth_token ) {
			wc_add_notice( __( 'Payment error: Could not connect to Twikey server. Authentication details may be incorrect.', 'jb-wc-twikey' ), 'error' );
			return;
		}

		/* Step 2. Invite the customer to sign the mandate. */
		$invite = $this->get_invite( $auth_token, $order );

		if ( false === $invite ) {
			wc_add_notice( __( 'Payment error: No Twikey mandate ID returned. Please verify the checkout form was filled out correctly and try again.', 'jb-wc-twikey' ), 'error' );
			return;
		}

		/* Stores the mandate ID for later use. */
		//$order->update_meta_data( '_twikey_mandate_id', $invite['mandate_id'] );
		update_post_meta( $order_id, '_twikey_mandate_id', $invite['mandate_id'] ); // Stores things straight into 'wp_postmeta'.

		WC()->cart->empty_cart();
		$url = $this->get_return_url( $order );

		if ( isset( $invite['url'] ) ) {
			$url = $invite['url'];
		} else {
			// We got a mandate ID above, but no URL: no need to sign?
			$order->payment_complete();
		}

		/*
		 * Redirects the customer to either the 'Thank you' page or the unique
		 * Twikey URL (in order to actually sign the mandate).
		 */
		return array(
			'result' => 'success',
			'redirect' => $url,
		);
	}

	/**
	 * Requests an authorization token from the Twikey service. Tokens are valid
	 * for 24 hours.
	 *
	 * @since 0.1.1
	 *
	 * @return string|false Auth token on success, false otherwise.
	 */
	function get_auth() {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.twikey.com/creditor' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, 'apiToken=' . $this->get_option( 'api_token' ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( 200 != $http_code ) {
			return false;
		}

		$result = json_decode( $server_output );

		if ( ! isset ( $result->{'Authorization'} ) ) {
			return false;
		}

		return $result->{'Authorization'};
	}

	/**
	 * Requests a mandate ID and invitation URL from the Twikey service.
	 *
	 * @since 0.1.1
	 *
	 * @param string $auth_token The temporary Twikey authentication token.
	 * @param object $order The WooCommerce order being processed.
	 *
	 * @return array|false Associated array containing the mandate ID and,
	 * optionally, the invitation URL; false on failure.
	 */
	function get_invite( $auth_token, $order ) {
		$invite = array();

		/*
		 * Note: Uses rawurlencode to encode possible spaces (and other unwanted
		 * characters) but does _not_ run it on the entire argument string, as
		 * that would also encode the much-needed ampersands below. This
		 * approach should be fairly Twikey-safe.
		 */
		$args = 'ct='         . $this->get_option( 'contract_template' )
			  . '&l='         . substr( get_bloginfo ( 'language' ), 0, 2 )
			  . '&email='     . $order->get_billing_email()
			  . '&lastname='  . rawurlencode( remove_accents( $order->get_billing_last_name() ) )
			  . '&firstname=' . rawurlencode( remove_accents( $order->get_billing_first_name() ) )
			  . '&address='   . rawurlencode( remove_accents( $this->format_address( $order->get_billing_address_1(), $order->get_billing_address_2() ) ) )
			  . '&zip='       . $order->get_billing_postcode()
			  . '&city='      . rawurlencode( remove_accents( $order->get_billing_city() ) )
			  . '&country='   . $order->get_billing_country();

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.twikey.com/creditor/invite' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Authorization: ' . $auth_token ) );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( 200 != $http_code ) {
			return false;
		}

		$result = json_decode( $server_output );

		if ( ! isset( $result->{'mndtId'} ) ) {
			return false;
		} else {
			$invite['mandate_id'] = $result->{'mndtId'};
		}

		if ( isset( $result->{'url'} ) ) {
			$invite['url'] = $result->{'url'};
		}

		return $invite;
	}

	/**
	 * Called from the Twikey website after a mandate is signed, either through
	 * a 'webhook' (in the background) or 'exit URL' (in which case the customer
	 * is actually sent back to the actual website).
	 *
	 * @since 0.1.0
	 */
	function callback_handler() {
		$order = null;
		$mandate_id = 0;
		$status = 'Not at all OK';
		$signature = 'Any random string';
		$checksum = 'Any other random string';
		$error_message = '';

		if ( 'exit_url' === $this->get_option( 'callback_type' ) ) {
			if ( isset( $_GET['mandateNumber'] ) && isset( $_GET['state'] ) && ctype_alnum( $_GET['mandateNumber'] ) && isset( $_GET['sig'] ) ) {
				$mandate_id = $_GET['mandateNumber'];
				$status = $_GET['state'];
				$signature = $_GET['sig'];
				$checksum = hash_hmac( 'sha256', $mandate_id . '/' . $status, $this->get_option( 'private_key' ) );
			}
		//} elseif ( 'webhook' === $this->get_option( 'callback_type' ) ) {
		//	if ( isset( $_GET['mandateNumber'] ) && isset( $_GET['state'] ) && ctype_alnum( $_GET['mandateNumber'] ) && isset( $_GET['type'] ) && 'contract' == $_GET['type'] ) {
		//		$mandate_id = $_GET['mandateNumber'];
		//		$status = $_GET['state'];
		//		$signature = $_SERVER['HTTP_APITOKEN'];
		//		$checksum = $this->get_option( 'api_token' );
		//	}
		} else {
			// Stop right there.
			exit;
		}

		if ( strtolower( $signature ) == strtolower( $checksum ) ) {
			// Finds the corresponding order(s).
			$query = new WC_Order_Query( array(
				'orderby' => 'date',
				'order' => 'DESC',
				'order_type' => 'any',
				'meta_key' => '_twikey_mandate_id',
				'meta_value' => $mandate_id,
			) );
			$orders = $query->get_orders();

			if ( ! empty( $orders ) && is_array( $orders ) ) {
				if ( 'ok' == strtolower( $status ) || 'signed' == strtolower( $status ) || 'alreadysigned' == strtolower( $status ) ) {
					// Assuming just one order will be returned may not be correct.
					foreach ( $orders as $order ) {
						if ( wcs_order_contains_subscription( $order->id ) ) {
							if ( ! $order->has_status( 'complete' ) ) {
								$order->payment_complete();
								$order->update_status( 'completed' );
							}
						}
					}
				} else {
					$error_message = 'The reported Twikey mandate (' . esc_attr( $mandate_id ) . ') status is something other than "OK" or "signed": ' .  esc_attr( $status ) . '.';

					foreach ( $orders as $order ) {
						if ( wcs_order_contains_subscription( $order->id ) ) {
							if ( ! $order->has_status( 'complete' ) ) {
								// Mark order failed if mandate is anything other than signed.
								$order->update_status( 'failed', $error_message );
							}
						}
					}
				}

				/*
				 * 'Resets' the order var, assuming the most recent order is the
				 * one just confirmed. Used _only_ for upcoming redirect.
				 */
				$order = $orders[0];
			} else {
				// Note: WooCommerce itself might still retrieve the order correctly from a cookie var or something.
				$error_message = 'Notice: No WooCommerce orders found for the returned Twikey mandate.';
			}
		} else {
			$error_message = 'No valid Twikey mandate returned.';
		}

		// For debugging.
		if ( '' !== $error_message ) {
			$this->error_log( $error_message );
		}

		/*
		 * If the callback type is set to 'Exit URL', forwards the user to the
		 * 'Thank You' page.
		 */
		if ( 'exit_url' === $this->get_option( 'callback_type' ) ) {
			wp_redirect( $this->get_return_url( $order ) ); // $order may be null, but that's okay.
			exit; // Not necessarily needed, as the callback function will always 'die()'.
		}
	}

	/**
	 * Turns two address lines (in case of a suite no. or similar) into one.
	 *
	 * @since 0.1.1
	 *
	 * @param string $address_1 First address line.
	 * @param string $address_2 Second address line.
	 *
	 * @return string The resulting single address line.
	 */
	function format_address( $address_1 = '', $address_2 = '' ) {
		return trim( trim( $address_1 ) . ' ' . trim( $address_2 ) );
	}

	/**
	 * Logs error messages to the debug log file in the plugin folder, if WordPress
	 * debugging is enabled.
	 *
	 * @since 0.1.2
	 */
	function error_log( $message ) {
		if ( true === WP_DEBUG ) {
			error_log( '[' . date_i18n( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL, 3, dirname( dirname( __FILE__ ) ) . '/debug.log' );
		}
	}
}
endif;
