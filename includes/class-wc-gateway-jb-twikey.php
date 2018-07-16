<?php
/**
 * Part of the Twikey Gateway for WooCommerce plugin that contains the actual
 * payment gateway class.
 *
 * @package JB_WC_Twikey_Gateway
 */

if ( ! class_exists( 'WC_Gateway_JB_Twikey' ) ) :
/**
 * Twikey Payment Gateway for WooCommerce class.
 *
 * JB Twikey Gateway for WooCommerce enables Twikey checkout for
 * WooCommerce (and WooCommerce Subscriptions). This class holds the bulk of the
 * functionality.
 * 
 * @since 0.1.0
 */
class WC_Gateway_JB_Twikey extends WC_Payment_Gateway {
	/**
	 * Class constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->id = 'jb_twikey'; // Changed after the 'official' Twikey plugin came into existence.
		$this->icon = null;
		$this->has_fields = true;
		$this->method_title = __( 'Pre-authorized payment via Twikey', 'jb-wc-twikey' );
		$this->method_description = __( "Sign a recurring payment mandate using a debit card, eID or text message, or use an existing mandate if you've signed one before.", 'jb-wc-twikey' );
		$this->supports = array( 
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension', // _Needs_ enabled in order to support automatic renewals!
			'subscription_reactivation', // _Needs_ enabled in order to support automatic renewals!
			'subscription_payment_method_change',
		);
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		/*
		 * To be used in combination with a Twikey webhook. The callback URL
		 * will look like http(s)://yoursite.com/wc-api/wc_gateway_jb_twikey/.
		 */
		add_action( 'woocommerce_api_'. strtolower( get_class( $this ) ), array( $this, 'callback_handler' ) );

		/* In order to handle renewal payments. */
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 ); // Number of params!
	}

	/**
	 * Defines admin options for the this payment gateway.
	 *
	 * @since 0.1.0
	 */
	public function init_form_fields() {
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
			'transaction_message' => array(
				'title' => __( 'Transaction Message', 'jb-wc-twikey' ),
				'type' => 'text',
				'default' => sprintf( __( 'Your payment for %s.', 'jb-wc-twikey' ), get_bloginfo( 'name' ) ),
				'desc_tip' => __( 'The message customers see on their bank statement after a payment was processed.', 'jb-wc-twikey' ),
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
				'default' => 'exit_url',
				'options' => array( 'exit_url' => __( 'Exit URL', 'jb-wc-twikey' ) ),
				'description' => sprintf( __( 'For this payment gateway to work correctly, you <strong>absolutely must</strong> configure all Exit URLs in the Twikey <a href="%1$s" target="_blank" rel="noopener">contract template settings</a> to %2$s.', 'jb-wc-twikey' ), 'https://www.twikey.com/r/admin#/c/contracttemplate', '<code>' . get_home_url( null, '/wc-api/wc_gateway_jb_twikey/?mandateNumber={0}&state={1}&sig={3}' ) . '</code>' ),
			),
			'test' => array(
				'title' => __( 'Test environment?', 'jb-wc-twikey' ),
				'label' => __( "Check when using Twikey's test environment", 'jb-wc-twikey' ),
				'type' => 'checkbox',
				'default' => 'no',
			),
		);
	}

	/**
	 * Contains most of the initial payment (i.e. during checkout) processing.
	 * Additional checks may be run in the callback handler (@see
	 * WC_Gateway_JB_Twikey::callback_handler()).
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id The unique ID of the order being processed.
	 *
	 * @return array|void Array containing result and redirect URL, or nothing on failure.
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;
		$order = wc_get_order( $order_id );
		$url = $this->get_return_url( $order ); // Default 'Thank you' URL.

		if ( $order->get_total() > 0 ) {
			$auth_token = $this->get_auth();

			if ( false === $auth_token ) {
				/*
				 * Note: these notices will be shown on the cart/checkout page,
				 * but not on the payment screen in case of payment at a later
				 * time (i.e. after an earlier error). Luckily, these errors
				 * are rare and really shouldn't occur once things have been
				 * correctly configured.
				 */
				wc_add_notice( __( 'Could not connect to Twikey server. If this keeps happening, please contact the store owner.', 'jb-wc-twikey' ), 'error' );
				return;
			}

			$invite = $this->get_invite( $auth_token, $order );

			if ( false === $invite ) {
				wc_add_notice( __( 'No Twikey mandate ID returned. Please verify the checkout form was filled out correctly and try again.', 'jb-wc-twikey' ), 'error' );
				return;
			}

			/* Stores the mandate ID for later use. */
			$order->update_meta_data( '_twikey_mandate_id', $invite['mandate_id'] );
			$order->save();

			if ( isset( $invite['url'] ) ) {
				/* The (new) mandate needs signed first! */
				$url = $invite['url']; // Unique URL provided by Twikey.
			} else {
				/*
				 * A signed mandate was returned. Let's see if it's also
				 * collectable.
				 */
				$status = $this->get_mandate_status( $auth_token, $invite['mandate_id'] );

				if ( false === $status ) {
					wc_add_notice( __( 'Payment error: invalid existing Twikey mandate status returned.', 'jb-wc-twikey' ), 'error' );
					return;
				}

				if ( false === $this->add_transaction( $auth_token, $invite['mandate_id'], $order ) ) {
					wc_add_notice( __( 'Payment error: could not get Twikey to confirm the payment request.', 'jb-wc-twikey' ), 'error' );
					return;
				}

				/*
				 * All went well and we've now asked for a payment. Let's
				 * check later (we're using a WP cron job for that) if it
				 * was processed as well.
				 */
				$order->update_status( 'on-hold', __( 'Twikey payment request successfully submitted. Awaiting final payment confirmation.', 'jb-wc-twikey' ) );
			}
		} else {
			$order->payment_complete();
		}

		WC()->cart->empty_cart();

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
	 * Runs when a (WooCommerce Subscriptions) renewal payment is due.
	 *
	 * @since 0.2.0
	 *
	 * @param float $renewal_total The total amount to charge for this renewal payment.
	 * @param WC_Order $renewal_order The WooCommerce order created to record the renewal.
	*/
	public function scheduled_subscription_payment( $renewal_total, $renewal_order ) {
		global $woocommerce;
		$auth_token = $this->get_auth();

		if ( false === $auth_token ) {
			$renewal_order->add_order_note( __( 'Order could not be completed: no Twikey authorization token returned.', 'jb-wc-twikey' ) );
			/* May as well stop here. Order remains pending, subscription stays on hold. */
			return;
		}

		/* Get the associated Twikey mandate ID (if any). */
		$mandate_id = $renewal_order->get_meta( '_twikey_mandate_id', true );

		if ( empty( $mandate_id ) && class_exists( 'WC_Subscriptions_Renewal_Order' ) ) {
			/*
			 * Note: this function should only ever be called when WC
			 * Subscriptions is active.
			 */
			$mandate_id = get_post_meta( WC_Subscriptions_Renewal_Order::get_parent_order_id( $renewal_order->get_id() ), '_twikey_mandate_id', true ); 
		}

		if ( empty( $mandate_id ) ) {
			/*
			 * No mandate found. Could be this subscriber was previously
			 * somehow signed up manually.
			 */
			$invite = $this->get_invite( $auth_token, $renewal_order );

			if ( false === $invite ) {
				$renewal_order->add_order_note( __( 'Invite error: no (new) Twikey mandate ID returned.', 'jb-wc-twikey' ) );
				/* Order remains 'pending', subscription on hold. */
				return;
			}

			if ( isset( $invite['url'] ) || false === $this->get_mandate_status( $auth_token, $invite['mandate_id'] ) ) {
				/*
				 * The returned mandate still needs signed or is otherwise
				 * uncollectable.
				 */
				$renewal_order->update_status( 'failed', __( 'Renewal order could not be completed: the status of the Twikey mandate associated with the order is invalid.', 'jb-wc-twikey' ) );
			} else {
				/* Stores the mandate ID for later use. */
				update_post_meta( $parent_order_id, '_twikey_mandate_id', $invite['mandate_id'] ); // Stores things straight into 'wp_postmeta'.
				$renewal_order->update_meta_data( '_twikey_mandate_id', $invite['mandate_id'] );
				$renewal_order->save();

				/* The mandate's collectable, so let's add a fresh transaction. */
				if ( false !== $this->add_transaction( $auth_token, $invite['mandate_id'], $renewal_order ) ) {
					/*
					 * All went well and we've now asked for a payment. Let's
					 * check later (we're using a WP cron job for that) if it
					 * was succesfully processed as well.
					 */
					$renewal_order->update_status( 'on-hold', __( 'Twikey payment request successfully submitted. Awaiting final payment confirmation.', 'jb-wc-twikey' ) );
				}
			}
		} else {
			/* Verify the status of the mandate associated with the order. */
			if ( false === $this->get_mandate_status( $auth_token, $mandate_id ) ) {
				$renewal_order->update_status( 'failed', __( 'Renewal order could not be completed: the status of the Twikey mandate associated with the order is invalid.', 'jb-wc-twikey' ) );
			} else {
				/* We're not done, yet: now submit a new transaction request. */
				if ( false !== $this->add_transaction( $auth_token, $mandate_id, $renewal_order ) ) {
					/*
					 * All went well and we've now asked for a payment. Let's
					 * check later (we're using a WP cron job for that) if it
					 * was succesfully processed as well.
					 */
					$renewal_order->update_status( 'on-hold', __( 'Twikey payment request successfully submitted. Awaiting final payment confirmation.', 'jb-wc-twikey' ) );
				}
			}
		}
	}

	/**
	 * Called from the Twikey website after a mandate is signed (or not signed).
	 *
	 * Checks if the refering website really is Twikey's, wether the mandate got
	 * signed, and tries to retrieve the associated order(s). Then, attempts to
	 * fire a new transaction (i.e. a payment request) for the order amount.
	 *
	 * @since 0.1.0
	 */
	public function callback_handler() {
		$order = null;
		$error_message = '';

		if ( isset( $_GET['mandateNumber'] ) && ctype_alnum( $_GET['mandateNumber'] ) && isset( $_GET['state'] ) && ctype_alnum( $_GET['state'] ) && isset( $_GET['sig'] ) && ctype_alnum( $_GET['sig'] ) ) {
			$mandate_id = $_GET['mandateNumber'];
			$status = $_GET['state'];
			$signature = $_GET['sig'];

			/* Calculates the signature based on the mandate ID and status. */
			$checksum = hash_hmac( 'sha256', $mandate_id . '/' . $status, $this->get_option( 'private_key' ) );

			if ( strtolower( $signature ) == strtolower( $checksum ) ) {
				/* We trust the request came from Twikey. */
				if ( 'ok' == strtolower( $status ) || 'signed' == strtolower( $status ) || 'alreadysigned' == strtolower( $status ) ) {
					/*
					 * Finds the most recent corresponding (with the mandate ID)
					 * order(s). While assuming just one order will be returned
					 * might not be correct, one may also prefer the customer is
					 * not charged more than once.
					 */
					$query = new WC_Order_Query( array(
						'orderby' => 'date',
						'order' => 'DESC',
						'order_type' => 'any',
						'status' => array( 'wc-pending' ), // Any order just submitted will have status 'pending'.
						'meta_key' => '_twikey_mandate_id',
						'meta_value' => $mandate_id,
						'limit' => 10, // Anything over zero. Not trusting the default no. of posts as set in the WordPress General Settings.
					) );
					$orders = $query->get_orders();

					if ( ! empty( $orders ) && is_array( $orders ) ) {
						$order = reset( $orders ); // This _is_ that most recent corresponding order.

						if ( false !== $this->add_transaction( $this->get_auth(), $mandate_id, $order ) ) {
							/*
							 * All went well and we've now asked for a payment.
							 * Let's check later (we're using a WP cron job for
							 * that) if it was processed as well.
							 */
							$order->update_status( 'on-hold', __( 'Twikey payment request successfully submitted. Awaiting final payment confirmation.', 'jb-wc-twikey' ) );
						}

						/*
						 * Done for now. We'll be processing payment requests on
						 * a regular basis.
						 */
					} else {
						$error_message = 'Notice: no WooCommerce orders found for the returned Twikey mandate.';
					}
				} else {
					$error_message = "The reported Twikey mandate (" . esc_attr( $mandate_id ) . ") status is something other than 'OK' or 'signed': " . esc_attr( $status ) . ".";
				}
			} else {
				$error_message = 'No valid Twikey mandate returned.';
			}
		} else {
			$error_message = 'Callback handler called with incomplete or wrong arguments.';
		}

		/* For debugging. */
		if ( '' !== $error_message ) {
			$this->error_log( $error_message );
		}

		/*
		 * Forwards the user to a 'Thank You' page, regardless if the order was
		 * processed or even found.
		 */
		wp_redirect( $this->get_return_url( $order ) ); // $order may be null, but that's okay.
		exit;
	}

	/**
	 * Calls the Twikey API (e.g. on a regular basis) and processes open orders
	 * (and memberships) accordingly.
	 *
	 * @since 0.2.0
	 */
	public function check_transactions() {
		$auth_token = $this->get_auth();

		if ( false === $auth_token ) {
			return;
		}

		$transactions = $this->get_transaction_feed( $auth_token ); // Will return all transactions that changed status since the last check.

		if ( ! empty( $transactions ) && is_array( $transactions ) ) {
			foreach ( $transactions as $order_id => $transaction ) {
				if ( 'paid' == strtolower( $transaction['status'] ) ) {
					/* Finds the corresponding order. */
					$order = wc_get_order( $order_id );

					if ( ! $order->has_status( 'processing' ) && ! $order->has_status( 'complete' ) ) {
						$order->add_order_note( 'Twikey payment confirmed.', 'jb-wc-twikey' );
						$order->set_date_paid( $transaction['date'] );
						$order->payment_complete();
					}
				}
			}
		}
	}

	/**
	 * Returns the image tag for this payment gateway's logo.
	 *
	 * @since 0.2.0
	 *
	 * @return string Image tag for this payment gateway's logo.
	 */
	public function get_icon() {
		return '<img src="' . plugin_dir_url( dirname( __FILE__ ) ) . 'assets/butterfly.svg" alt="Twikey" width="24" />';
	}

	/**
	 * Asks the Twikey API for an authorization token. (Tokens are valid for 24
	 * hours.)
	 *
	 * @since 0.1.1
	 *
	 * @return string|false Auth token on success, false otherwise.
	 */
	private function get_auth() {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->get_host() . '/creditor' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, 'apiToken=' . $this->get_option( 'api_token' ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$server_output = curl_exec( $ch );
		curl_close( $ch );
		$result = json_decode( $server_output );

		if ( ! isset ( $result->{'Authorization'} ) ) {
			return false;
		}

		return $result->{'Authorization'};
	}

	/**
	 * Asks the Twikey API for a (new) mandate ID and invitation URL.
	 *
	 * @since 0.1.1
	 *
	 * @param string $auth_token The temporary Twikey authentication token.
	 * @param WC_Order $order The WooCommerce order being processed.
	 *
	 * @return array|false Associated array containing the mandate ID and, optionally, the invitation URL; false on failure.
	 */
	private function get_invite( $auth_token, $order ) {
		$invite = false;
		$args = array(
			'ct' => $this->get_option( 'contract_template' ),
			'l' => substr( get_bloginfo( 'language' ), 0, 2 ),
			'email' => $order->get_billing_email(),
			'lastname' => $order->get_billing_last_name(),
			'firstname' => $order->get_billing_first_name(),
			'address' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'zip' => $order->get_billing_postcode(),
			'city' => $order->get_billing_city(),
			'country' => $order->get_billing_country(),
			'check' => true, // Do not create a new mandate if one already exists.
		);
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->get_host() . '/creditor/invite' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $args ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: ' . $auth_token,
		) );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		$result = json_decode( $server_output );

		if ( 200 != $http_code ) {
			/* Something went wrong. */
			if ( ! empty( $result->{'message'} ) ) { 
				$this->error_log( 'An error occurred trying to get a Twikey mandate: ' . $result->{'message'} . '.' );
			}

			return false;
		}

		if ( ! isset( $result->{'mndtId'} ) ) {
			/* No mandate returned. (Should never be the case at this point.) */
			return false;
		} else {
			$invite['mandate_id'] = $result->{'mndtId'};
		}

		if ( isset( $result->{'url'} ) ) {
			/*
			 * The mandate still needs signed and this URL can be used to do
			 * just that.
			 */
			$invite['url'] = $result->{'url'};
		}

		return $invite;
	}

	/**
	 * Asks the Twikey API for a mandate's status.
	 *
	 * @since 0.2.0
	 *
	 * @param string $auth_token The temporary Twikey authentication token.
	 * @param string $mandate_id The Twikey mandate ID inquired about.
	 *
	 * @return bool True if the mandate is signed and collectable, false otherwise.
	 */
	private function get_mandate_status( $auth_token, $mandate_id ) {
		$status = false;
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->get_host() . '/creditor/mandate/detail?mndtId=' . $mandate_id );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Authorization: ' . $auth_token,
		) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HEADER, 1 );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( 200 == $http_code ) {
			/*
			 * Given the params we provided, only signed mandates should be
			 * returned. But: even a signed mandate may be uncollectable.
			 */
			list( $headers, $response ) = explode( "\r\n\r\n", $server_output, 2 );
			$headers = explode( "\n", $headers );

			foreach ( $headers as $header ) {
				if ( false !== stripos( $header, 'x-collectable: true' ) ) {
					/* Mandate really is collectable. */
					$status = true;
				}
			}
		}

		return $status;
	}

	/**
	 * Tries adding a new transaction through the Twikey API.
	 *
	 * @since 0.2.0
	 *
	 * @param string The mandate ID.
	 * @return string|false The transaction ID or false on failure.
	 */
	private function add_transaction( $auth_token, $mandate_id, $order ) {
		$args = array(
			'mndtId' => $mandate_id,
			'ref' => $order->get_id(),
			'message' => esc_attr( remove_accents( wp_strip_all_tags( $this->get_option( 'transaction_message' ) ) ) ), // May be overkill, but making sure no invalid characters are present.
			'amount' => round( $order->get_total(), 2 ),
		);
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->get_host() . '/creditor/transaction' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $args ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: ' . $auth_token,
		) );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		$result = json_decode( $server_output );

		if ( 200 == $http_code && ! empty( $result->{'Entries'} ) ) {
			$transaction = reset( $result->{'Entries'} ); // Only one entry should be returned.

			if ( isset( $transaction->{'id'} ) ) {
				return $transaction->{'id'};
			}
		} elseif ( ! empty( $result->{'message'} ) ) {
			$order->add_order_note( sprintf( __( "Twikey transaction could not be added. The Twikey API responsed: '%s'.", 'jb-wc-twikey' ), $result->{'message'} ) );
		}

		return false;
	}

	/**
	 * Asks the Twikey API for the updated transactions feed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $auth_token Twikey authorization token.
	 * @return array|false An associated array of transaction statuses, or false on failure.
	 */
	private function get_transaction_feed( $auth_token ) {
		$transactions = false;
		$ch = curl_init();
		$url = $this->get_host() . '/creditor/transaction';
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Authorization: ' . $auth_token ) );
		$server_output = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( 200 != $http_code ) {
			$this->error_log( 'An error occurred trying to fetch Twikey transaction statuses.' );
			return false;
		}

		$result = json_decode( $server_output );

		foreach ( $result->{'Entries'} as $transaction ) {
			
			if ( ! empty( $transaction->{'ref'} ) && isset( $transaction->{'state'} ) && isset( $transaction->{'bkdate'} ) ) {
				$transactions[$transaction->{'ref'}] = array(
					'status' => $transaction->{'state'}, // OPEN, PAID, ERROR, etc.
					'date' => $transaction->{'bkdate'}, // ISO 8601-formatted date/time.
				);
			}
		}$this->error_log( print_r( $transactions, true ) );

		return $transactions;
	}

	/**
	 * Returns the Twikey API URL (without trailing slash).
	 *
	 * @since 0.2.0
	 *
	 * @return string The Twikey API URL.
	 */
	private function get_host() {
		if ( 'yes' == $this->get_option( 'test' ) ) {
			return 'https://api.beta.twikey.com';
		}

		return 'https://api.twikey.com';
	}

	/**
	 * Logs error messages to the debug log file in the plugin folder, if
	 * WordPress debugging is enabled.
	 *
	 * @since 0.1.2
	 *
	 * @param string The error message to be logged.
	 */
	private function error_log( $message ) {
		$dir = dirname( dirname( __FILE__ ) );

		if ( true === WP_DEBUG && is_writeable( $dir ) ) {
			/* Tries writing to a separate file in the plugin folder: 'wp-content/jb-twikey-gateway-for-woocommerce/debug.log'. */
			error_log( '[' . date_i18n( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL, 3, $dir . '/debug.log' );
		} else {
			/* Default behavior. */
			error_log( $message ); // Default location: 'wp-content/debug.log'.
		}
	}
}
endif;
