<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Gateway_PPEC
 */
abstract class WC_Gateway_PPEC extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields         = false;
		$this->icon               = 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/pp-acceptance-small.png';
		$this->supports[]         = 'refunds';
		$this->method_title       = __( 'PayPal Express Checkout', 'woocommerce-gateway-paypal-express-checkout' );
		$this->method_description = __( 'Allow customers to conveniently checkout directly with PayPal.', 'woocommerce-gateway-paypal-express-checkout' );

		if ( empty( $_GET['woo-paypal-return'] ) ) {
			$this->order_button_text  = __( 'Continue to payment', 'woocommerce-gateway-paypal-express-checkout' );
		}

		wc_gateway_ppec()->ips->maybe_received_credentials();

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->method_title;
		$this->description  = '';
		$this->enabled      = $this->get_option( 'enabled', 'yes' );
		$this->button_size  = $this->get_option( 'button_size', 'large' );
		$this->environment  = $this->get_option( 'environment', 'live' );
		$this->mark_enabled = 'yes' === $this->get_option( 'mark_enabled', 'no' );

		if ( 'live' === $this->environment ) {
			$this->api_username    = $this->get_option( 'api_username' );
			$this->api_password    = $this->get_option( 'api_password' );
			$this->api_signature   = $this->get_option( 'api_signature' );
			$this->api_certificate = $this->get_option( 'api_certificate' );
			$this->api_subject     = $this->get_option( 'api_subject' );
		} else {
			$this->api_username    = $this->get_option( 'sandbox_api_username' );
			$this->api_password    = $this->get_option( 'sandbox_api_password' );
			$this->api_signature   = $this->get_option( 'sandbox_api_signature' );
			$this->api_certificate = $this->get_option( 'sandbox_api_certificate' );
			$this->api_subject     = $this->get_option( 'sandbox_api_subject' );
		}

		$this->debug                      = 'yes' === $this->get_option( 'debug', 'no' );
		$this->invoice_prefix             = $this->get_option( 'invoice_prefix', 'WC-' );
		$this->instant_payments           = 'yes' === $this->get_option( 'instant_payments', 'no' );
		$this->require_billing            = 'yes' === $this->get_option( 'require_billing', 'no' );
		$this->paymentaction              = $this->get_option( 'paymentaction', 'sale' );
		$this->logo_image_url             = $this->get_option( 'logo_image_url' );
		$this->subtotal_mismatch_behavior = $this->get_option( 'subtotal_mismatch_behavior', 'add' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Change gateway name if session is active
		if ( ! is_admin() ) {
			$session  = WC()->session->get( 'paypal' );
			$checkout = wc_gateway_ppec()->checkout;

			if ( ! $checkout->has_active_session() || ! $session->checkout_completed ) {
				$this->title        = $this->get_option( 'title' );
				$this->description  = $this->get_option( 'description' );
			}
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = include( dirname( dirname( __FILE__ ) ) . '/settings/settings-ppec.php' );
	}

	/**
	 * Process payments
	 */
	public function process_payment( $order_id ) {
		$checkout = wc_gateway_ppec()->checkout;
		$order    = wc_get_order( $order_id );
		$session  = WC()->session->get( 'paypal' );

		// Redirect them over to PayPal if they have no current session (this is for PayPal Mark).
		if ( ! $checkout->has_active_session() || ! $session->checkout_completed ) {
			try {
				return array(
					'result'   => 'success',
					'redirect' => $checkout->start_checkout_from_checkout( $order_id ),
				);
			} catch( PayPal_API_Exception $e ) {
				wc_gateway_ppec_format_paypal_api_exception( $e->errors );
			}
		} else {
			try {
				// Get details
				$checkout_details = $checkout->getCheckoutDetails( $session->token );

				// Store addresses given by PayPal
				$order->set_address( $checkout->get_mapped_billing_address( $checkout_details ), 'billing' );
				$order->set_address( $checkout->get_mapped_shipping_address( $checkout_details ), 'shipping' );

				// Complete the payment now.
				$checkout->do_payment( $order, $session->token, $session->payerID );

				// Clear Cart
				WC()->cart->empty_cart();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);
			} catch( PayPal_Missing_Session_Exception $e ) {
				// For some reason, our session data is missing. Generally, if we've made it this far, this shouldn't happen.
				wc_add_notice( __( 'Sorry, an error occurred while trying to process your payment. Please try again.', 'woocommerce-gateway-paypal-express-checkout' ), 'error' );
			} catch( PayPal_API_Exception $e ) {
				// Did we get a 10486 or 10422 back from PayPal?  If so, this means we need to send the buyer back over to
				// PayPal to have them pick out a new funding method.
				$error_codes = wp_list_pluck( $e->errors, 'error_code' );

				if ( in_array( '10486', $error_codes ) || in_array( '10422', $error_codes ) ) {
					$session->checkout_completed = false;
					$session->source             = 'order';
					$session->order_id           = $order_id;
					WC()->session->set( 'paypal', $session );

					return array(
						'result'   => 'success',
						'redirect' => wc_gateway_ppec()->settings->get_paypal_redirect_url( $session->token, true )
					);
				} else {
					wc_gateway_ppec_format_paypal_api_exception( $e->errors );
				}
			}
		}
	}

	/**
	 * Get info about uploaded certificate.
	 * @param  string $cert_string
	 * @return string
	 */
	private function get_certificate_info( $cert_string ) {
		if ( ! strlen( $cert_string ) ) {
			return __( 'No API certificate on file.', 'woocommerce-gateway-paypal-express-checkout' );
		}

		$cert = @openssl_x509_read( $cert_string );
		$out  = '';

		if ( false !== $cert ) {
			$certinfo = openssl_x509_parse( $cert );
			if ( false !== $certinfo ) {
				$valid_until = $certinfo['validTo_time_t'];
				if ( $valid_until < time() ) {
					// Display in red if the cert is already expired
					$expires = '<span style="color: red;">' . __( 'expired on %s', 'woocommerce-gateway-paypal-express-checkout' ) . '</span>';
				} elseif ( $valid_until < ( time() - 2592000 ) ) {
					// Also display in red if the cert is going to expire in the next 30 days
					$expires = '<span style="color: red;">' . __( 'expires on %s', 'woocommerce-gateway-paypal-express-checkout' ) . '</span>';
				} else {
					// Otherwise just display a normal message
					$expires = __( 'expires on %s', 'woocommerce-gateway-paypal-express-checkout' );
				}

				$expires = sprintf( $expires, date_i18n( get_option( 'date_format' ), $valid_until ) );
				$out = sprintf( __( 'Certificate belongs to API username %1$s; %2$s', 'woocommerce-gateway-paypal-express-checkout' ), $certinfo['subject']['CN'], $expires );
			} else {
				$out = __( 'The certificate on file is not valid.', 'woocommerce-gateway-paypal-express-checkout' );
			}
		}

		return $out;
	}

	/**
	 * Do some additonal validation before saving options via the API.
	 */
	public function process_admin_options() {
		// Validate logo
		$logo_image_url = wc_clean( $_POST['woocommerce_ppec_paypal_logo_image_url'] );

		if ( ! empty( $logo_image_url ) && ! preg_match( '/https?:\/\/[a-zA-Z0-9][a-zA-Z0-9.-]+[a-zA-Z0-9](\/[a-zA-Z0-9.\/?&%#]*)?/', $logo_image_url ) ) {
			WC_Admin_Settings::add_error( __( 'Error: The logo image URL you provided is not valid and cannot be used.', 'woocommerce-gateway-paypal-express-checkout' ) );
			unset( $_POST['woocommerce_ppec_paypal_logo_image_url'] );
		}

		// If a certificate has been uploaded, read the contents and save that string instead.
		if ( array_key_exists( 'woocommerce_ppec_paypal_api_certificate', $_FILES )
			&& array_key_exists( 'tmp_name', $_FILES['woocommerce_ppec_paypal_api_certificate'] )
			&& array_key_exists( 'size', $_FILES['woocommerce_ppec_paypal_api_certificate'] )
			&& $_FILES['woocommerce_ppec_paypal_api_certificate']['size'] ) {

			$_POST['woocommerce_ppec_paypal_api_certificate'] = base64_encode( file_get_contents( $_FILES['woocommerce_ppec_paypal_api_certificate']['tmp_name'] ) );
			unlink( $_FILES['woocommerce_ppec_paypal_api_certificate']['tmp_name'] );
			unset( $_FILES['woocommerce_ppec_paypal_api_certificate'] );
		} else {
			$_POST['woocommerce_ppec_paypal_api_certificate'] = $this->get_option( 'api_certificate' );
		}

		if ( array_key_exists( 'woocommerce_ppec_paypal_sandbox_api_certificate', $_FILES )
			&& array_key_exists( 'tmp_name', $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate'] )
			&& array_key_exists( 'size', $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate'] )
			&& $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate']['size'] ) {

			$_POST['woocommerce_ppec_paypal_sandbox_api_certificate'] = base64_encode( file_get_contents( $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate']['tmp_name'] ) );
			unlink( $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate']['tmp_name'] );
			unset( $_FILES['woocommerce_ppec_paypal_sandbox_api_certificate'] );
		} else {
			$_POST['woocommerce_ppec_paypal_sandbox_api_certificate'] = $this->get_option( 'sandbox_api_certificate' );
		}

		parent::process_admin_options();

		// Validate credentials
		$this->validate_active_credentials();
	}

	/**
	 * Validate the provided credentials.
	 */
	protected function validate_active_credentials() {
		$settings = wc_gateway_ppec()->settings->load_settings( true );
		$creds    = $settings->get_active_api_credentials();

		$username = $creds->get_username();
		$password = $creds->get_password();

		if ( ! empty( $username ) ) {

			if ( empty( $password ) ) {
				WC_Admin_Settings::add_error( __( 'Error: You must enter API password.', 'woocommerce-gateway-paypal-express-checkout' ) );
				return false;
			}

			if ( is_a( $creds, 'WC_Gateway_PPEC_Client_Credential_Signature' ) && $creds->get_signature() ) {

				try {

					$payer_id = wc_gateway_ppec()->client->test_api_credentials( $creds, $settings->get_environment() );

					if ( ! $payer_id ) {
						WC_Admin_Settings::add_error( sprintf( __( 'Error: The %s credentials you provided are not valid.  Please double-check that you entered them correctly and try again.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
						return false;
					}

				} catch( PayPal_API_Exception $ex ) {
					WC_Admin_Settings::add_error( sprintf( __( 'An error occurred while trying to validate your %s API credentials.  Unable to verify that your API credentials are correct.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
				}

			} elseif ( is_a( $creds, 'WC_Gateway_PPEC_Client_Credential_Certificate' ) && $creds->get_certificate() ) {

				$cert = @openssl_x509_read( $creds->get_certificate() );

				if ( false === $cert ) {
					WC_Admin_Settings::add_error( sprintf( __( 'Error: The %s API certificate is not valid.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
					return false;
				}

				$cert_info   = openssl_x509_parse( $cert );
				$valid_until = $cert_info['validTo_time_t'];

				if ( $valid_until < time() ) {
					WC_Admin_Settings::add_error( sprintf( __( 'Error: The %s API certificate has expired.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
					return false;
				}

				if ( $cert_info['subject']['CN'] != $creds->get_username() ) {
					WC_Admin_Settings::add_error( __( 'Error: The API username does not match the name in the API certificate.  Make sure that you have the correct API certificate.', 'woocommerce-gateway-paypal-express-checkout' ) );
					return false;
				}

				try {

					$payer_id = wc_gateway_ppec()->client->test_api_credentials( $creds, $settings->get_environment() );

					if ( ! $payer_id ) {
						WC_Admin_Settings::add_error( sprintf( __( 'Error: The %s credentials you provided are not valid.  Please double-check that you entered them correctly and try again.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
						return false;
					}

				} catch( PayPal_API_Exception $ex ) {
					WC_Admin_Settings::add_error( sprintf( __( 'An error occurred while trying to validate your %s API credentials.  Unable to verify that your API credentials are correct.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
				}

			} else {

				WC_Admin_Settings::add_error( sprintf( __( 'Error: You must provide a %s API signature or certificate.', 'woocommerce-gateway-paypal-express-checkout' ), __( $settings->get_environment(), 'woocommerce-gateway-paypal-express-checkout' ) ) );
				return false;
			}

			$settings_array = (array) get_option( 'woocommerce_ppec_paypal_settings', array() );

			if ( 'yes' === $settings_array['require_billing'] ) {
				$is_account_enabled_for_billing_address = false;

				try {
					$is_account_enabled_for_billing_address = wc_gateway_ppec()->client->test_for_billing_address_enabled( $creds, $settings->get_environment() );
				} catch( PayPal_API_Exception $ex ) {
					$is_account_enabled_for_billing_address = false;
				}

				if ( ! $is_account_enabled_for_billing_address ) {
					$settings_array['require_billing'] = 'no';
					update_option( 'woocommerce_ppec_paypal_settings', $settings_array );
					WC_Admin_Settings::add_error( __( 'The "require billing address" option is not enabled by your account and has been disabled.', 'woocommerce-gateway-paypal-express-checkout' ) );
				}
			}
		}
	}

	/**
	 * Refunds.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( 0 == $amount || null == $amount ) {
			return new WP_Error( 'paypal_refund_error', __( 'Refund Error: You need to specify a refund amount.', 'woocommerce-gateway-paypal-express-checkout' ) );
		}

		// load up refundable_txns from Post Meta
		// loop through each transaction to compile list of txns that are able to be refunded
		// process refunds against each txn in the list until full amount of refund is reached
		// first loop through, try to find a transaction that equals the refund amount being requested
		$txnData = get_post_meta( $order_id, '_woo_pp_txnData', true );
		$didRefund = false;

		foreach ( $txnData['refundable_txns'] as $key => $value ) {
			$refundableAmount = $value['amount'] - $value['refunded_amount'];

			if ( $amount == $refundableAmount ) {
				if ( 0 == $value['refunded_amount'] ) {
					$refundType = 'Full';
				} else {
					$refundType = 'Partial';
				}

				try {
					$refundTxnID = WC_Gateway_PPEC_Refund::refund_order( $order, $amount, $refundType, $reason, $order->get_order_currency() );
					$txnData['refundable_txns'][ $key ]['refunded_amount'] += $amount;
					$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'woocommerce-gateway-paypal-express-checkout' ), $refundTxnID ) );
					update_post_meta( $order_id, '_woo_pp_txnData', $txnData );

					return true;

				} catch( PayPal_API_Exception $e ) {
					foreach ( $e->errors as $error ) {
						$final_output .= sprintf( __( 'Error: %1$s - %2$s', 'woocommerce-gateway-paypal-express-checkout' ), $error->error_code, $error->long_message );
					}

					return new WP_Error( 'paypal_refund_error', $final_output );
				}
			}
		}

		foreach ( $txnData['refundable_txns'] as $key => $value ) {
			$refundableAmount = $value['amount'] - $value['refunded_amount'];

			if ( $amount < $refundableAmount ) {

				try {
					$refundTxnID = WC_Gateway_PPEC_Refund::refund_order( $order, $amount, 'Partial', $reason, $order->get_order_currency() );
					$txnData['refundable_txns'][ $key ]['refunded_amount'] += $amount;
					$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'woocommerce-gateway-paypal-express-checkout' ), $refundTxnID ) );
					update_post_meta( $order_id, '_woo_pp_txnData', $txnData );

					return true;

				} catch( PayPal_API_Exception $e ) {
					foreach ( $e->errors as $error ) {
						$final_output .= sprintf( __( 'Error: %1$s - %2$s', 'woocommerce-gateway-paypal-express-checkout' ), $error->error_code, $error->long_message );
					}

					return new WP_Error( 'paypal_refund_error', $final_output );
				}

			}
		}

		$totalRefundableAmount = 0;
		foreach ( $txnData['refundable_txns'] as $key => $value ) {
			$refundableAmount = $value['amount'] - $value['refunded_amount'];
			$totalRefundableAmount += $refundableAmount;
		}

		if ( $totalRefundableAmount < $amount ) {
			if ( 0 == $totalRefundableAmount ) {
				return new WP_Error( 'paypal_refund_error', __( 'Refund Error: All transactions have been fully refunded. There is no amount left to refund', 'woocommerce-gateway-paypal-express-checkout' ) );
			} else {
				return new WP_Error( 'paypal_refund_error', sprintf( __( 'Refund Error: The requested refund amount is too large. The refund amount must be less than or equal to %s.', 'woocommerce-gateway-paypal-express-checkout' ), html_entity_decode( get_woocommerce_currency_symbol() ) . $totalRefundableAmount ) );
			}
		} else {
			$total_to_refund = $amount;

			foreach ( $txnData['refundable_txns'] as $key => $value ) {
				$refundableAmount = $value['amount'] - $value['refunded_amount'];

				if ( $refundableAmount > $total_to_refund ) {
					$amount_to_refund = $total_to_refund;
				} else {
					$amount_to_refund = $refundableAmount;
				}

				if ( 0 < $amount_to_refund ) {
					if ( 0 == $value['refunded_amount'] && $amount_to_refund == $value['amount'] ) {
						$refundType = 'Full';
					} else {
						$refundType = 'Partial';
					}

					try {
						$refundTxnID = WC_Gateway_PPEC_Refund::refund_order( $order, $amount_to_refund, $refundType, $reason, $order->get_order_currency() );
						$total_to_refund -= $amount_to_refund;
						$txnData['refundable_txns'][ $key ]['refunded_amount'] += $amount_to_refund;
						$order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'woocommerce-gateway-paypal-express-checkout' ), $refundTxnID ) );
						update_post_meta( $order_id, '_woo_pp_txnData', $txnData );

						return true;
					} catch( PayPal_API_Exception $e ) {
						foreach ( $e->errors as $error ) {
							$final_output .= sprintf( __( 'Error: %1$s - %2$s', 'woocommerce-gateway-paypal-express-checkout' ), $error->error_code, $error->long_message );
						}

						return new WP_Error( 'paypal_refund_error', $final_output );
					}
				}
			}
		}
	}

	/**
	 * Get the transaction URL.
	 *
	 * @param  WC_Order $order
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		if ( 'sandbox' === $this->environment ) {
			$this->view_transaction_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
		} else {
			$this->view_transaction_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
		}
		return parent::get_transaction_url( $order );
	}

	/**
	 * Check if this gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_available() {
		return 'yes' === $this->enabled;
	}
}