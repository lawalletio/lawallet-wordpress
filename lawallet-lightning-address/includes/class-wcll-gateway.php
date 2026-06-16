<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'wcll_gateway';
		$this->method_title       = __( 'LaWallet - Lightning Address', 'lawallet-lightning-address' );
		$this->method_description = __( 'Accept Bitcoin Lightning payments through a Lightning Address with LUD-21 settlement verification, and configure LaWallet discovery from the main settings page.', 'lawallet-lightning-address' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = WCLL_PLUGIN_URL . 'assets/bitcoin.png';

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled', 'yes' );
		$this->title       = $this->get_option( 'title', __( 'LaWallet Lightning', 'lawallet-lightning-address' ) );
		$this->description = $this->get_option( 'description', __( 'Pay instantly with a Bitcoin Lightning wallet.', 'lawallet-lightning-address' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Enable/Disable', 'lawallet-lightning-address' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bitcoin Lightning payments', 'lawallet-lightning-address' ),
				'default' => 'yes',
			),
			'lightning_address'     => array(
				'title'       => __( 'Lightning Address', 'lawallet-lightning-address' ),
				'type'        => 'text',
				'description' => __( 'Merchant Lightning Address. It must support LNURL-pay and LUD-21 verify.', 'lawallet-lightning-address' ),
				'placeholder' => 'merchant@example.com',
				'default'     => '',
				'desc_tip'    => true,
			),
			'title'                 => array(
				'title'       => __( 'Checkout title', 'lawallet-lightning-address' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to customers.', 'lawallet-lightning-address' ),
				'default'     => __( 'LaWallet Lightning', 'lawallet-lightning-address' ),
				'desc_tip'    => true,
			),
			'description'           => array(
				'title'   => __( 'Checkout description', 'lawallet-lightning-address' ),
				'type'    => 'textarea',
				'default' => __( 'Scan a Lightning invoice QR code or open it in your Lightning wallet.', 'lawallet-lightning-address' ),
			),
			'invoice_expiry_minutes' => array(
				'title'             => __( 'Invoice expiry minutes', 'lawallet-lightning-address' ),
				'type'              => 'number',
				'default'           => 30,
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
			),
			'nostr_relays'          => array(
				'title'       => __( 'NIP-57 relay URLs', 'lawallet-lightning-address' ),
				'type'        => 'textarea',
				'description' => __( 'Optional relay URLs used to listen for zap receipts. One URL per line.', 'lawallet-lightning-address' ),
				'default'     => "wss://relay.damus.io\nwss://relay.primal.net",
				'desc_tip'    => true,
			),
			'manual_sats_per_unit'  => array(
				'title'       => __( 'Manual sats per currency unit', 'lawallet-lightning-address' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave blank to use Yadio BTC exchange rates for fiat currencies.', 'lawallet-lightning-address' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'rate_display_unit'     => array(
				'title'       => __( 'Rate display unit', 'lawallet-lightning-address' ),
				'type'        => 'select',
				'description' => __( 'Choose whether the locked Yadio rate is shown as a BTC price or a SAT price on the payment page.', 'lawallet-lightning-address' ),
				'default'     => 'btc',
				'options'     => array(
					'btc'  => __( 'BTC', 'lawallet-lightning-address' ),
					'sats' => __( 'SATS', 'lawallet-lightning-address' ),
				),
				'desc_tip'    => true,
			),
			'price_buffer_percent'  => array(
				'title'             => __( 'Price buffer percent', 'lawallet-lightning-address' ),
				'type'              => 'number',
				'default'           => 0,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => '0.1',
				),
			),
			'allow_insecure_http'   => array(
				'title'       => __( 'Local development HTTP', 'lawallet-lightning-address' ),
				'type'        => 'checkbox',
				'label'       => __( 'Allow HTTP Lightning Address endpoints for local development', 'lawallet-lightning-address' ),
				'description' => __( 'Keep this disabled in production.', 'lawallet-lightning-address' ),
				'default'     => 'no',
			),
		);
	}

	public function admin_options() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that only toggles an onboarding notice for admins.
		if ( isset( $_GET['wcll_setup'] ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Set your Lightning Address to start accepting Lightning payments.', 'lawallet-lightning-address' ) . '</strong> ';
			echo esc_html__( 'Saving this page will create a test invoice and require a LUD-21 verify URL.', 'lawallet-lightning-address' ) . '</p></div>';
		}

		parent::admin_options();
		$this->render_connection_status();
	}

	public function process_admin_options() {
		$result = parent::process_admin_options();
		$this->init_settings();
		$this->refresh_lightning_address_status();
		return $result;
	}

	public function validate_lightning_address_field( $key, $value ) {
		unset( $key );
		$value = trim( strtolower( sanitize_text_field( $value ) ) );

		if ( '' !== $value && ! preg_match( '/^[^@\s]+@[^@\s]+$/', $value ) ) {
			WC_Admin_Settings::add_error( __( 'Lightning Address must look like name@example.com.', 'lawallet-lightning-address' ) );
			return '';
		}

		return $value;
	}

	public function validate_manual_sats_per_unit_field( $key, $value ) {
		unset( $key );
		$value = trim( wc_clean( $value ) );
		if ( '' === $value ) {
			return '';
		}

		$number = (float) $value;
		if ( $number <= 0 ) {
			WC_Admin_Settings::add_error( __( 'Manual sats per currency unit must be greater than zero.', 'lawallet-lightning-address' ) );
			return '';
		}

		return (string) $number;
	}

	public function validate_rate_display_unit_field( $key, $value ) {
		unset( $key );
		$value = strtolower( sanitize_key( $value ) );
		return in_array( $value, array( 'btc', 'sats' ), true ) ? $value : 'btc';
	}

	public function validate_invoice_expiry_minutes_field( $key, $value ) {
		unset( $key );
		return max( 1, absint( $value ) );
	}

	public function validate_price_buffer_percent_field( $key, $value ) {
		unset( $key );
		return max( 0, (float) $value );
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		$address = $this->get_option( 'lightning_address', '' );
		return ! empty( $address );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Payment error: order could not be loaded.', 'lawallet-lightning-address' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$settings = self::get_gateway_settings();
		$address  = isset( $settings['lightning_address'] ) ? $settings['lightning_address'] : '';
		if ( empty( $address ) ) {
			wc_add_notice( __( 'Payment error: merchant Lightning Address is not configured.', 'lawallet-lightning-address' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount_calculation = WCLL_Rates::calculate_order_amount( $order, $settings );
		if ( is_wp_error( $amount_calculation ) ) {
			wc_add_notice( __( 'Payment error:', 'lawallet-lightning-address' ) . ' ' . $amount_calculation->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount_msat = (int) $amount_calculation['amount_msat'];

		$client      = new WCLL_LNURL_Client( $settings );
		$pay_request = $client->resolve_lightning_address( $address );
		if ( is_wp_error( $pay_request ) ) {
			wc_add_notice( __( 'Payment error:', 'lawallet-lightning-address' ) . ' ' . $pay_request->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$invoice = $client->request_invoice(
			$pay_request,
			$amount_msat,
			array(
				'description' => sprintf( 'WooCommerce order #%s', $order->get_order_number() ),
				'use_nostr'   => true,
			)
		);

		if ( is_wp_error( $invoice ) ) {
			wc_add_notice( __( 'Payment error:', 'lawallet-lightning-address' ) . ' ' . $invoice->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$expiry_minutes = isset( $settings['invoice_expiry_minutes'] ) ? max( 1, absint( $settings['invoice_expiry_minutes'] ) ) : 30;
		$expires_at     = time() + ( $expiry_minutes * MINUTE_IN_SECONDS );
		$nostr          = isset( $invoice['nostr'] ) && is_array( $invoice['nostr'] ) ? $invoice['nostr'] : array();

		$order->update_meta_data( '_wcll_status', 'pending' );
		$order->update_meta_data( '_wcll_invoice', sanitize_text_field( $invoice['pr'] ) );
		$order->update_meta_data( '_wcll_verify_url', esc_url_raw( $invoice['verify'] ) );
		$order->update_meta_data( '_wcll_amount_msat', (int) $amount_msat );
		$order->update_meta_data( '_wcll_rate', $amount_calculation['rate'] );
		$order->update_meta_data( '_wcll_expires_at', $expires_at );
		$order->update_meta_data( '_wcll_lightning_address', sanitize_text_field( $address ) );
		$order->update_meta_data( '_wcll_lnurl_pay_url', esc_url_raw( $pay_request['lnurl_pay_url'] ) );

		if ( ! empty( $nostr['event'] ) ) {
			$order->update_meta_data( '_wcll_zap_request', wp_json_encode( $nostr['event'] ) );
			$order->update_meta_data( '_wcll_nostr_pubkey', sanitize_text_field( $nostr['recipient'] ) );
			$order->update_meta_data( '_wcll_nostr_relays', array_values( $nostr['relays'] ) );
		}

		$order->save();
		$order->update_status( 'pending', __( 'Awaiting Bitcoin Lightning payment.', 'lawallet-lightning-address' ) );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	public static function get_gateway_settings() {
		$settings = get_option( 'woocommerce_wcll_gateway_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function refresh_lightning_address_status() {
		$settings = self::get_gateway_settings();
		$address  = isset( $settings['lightning_address'] ) ? trim( (string) $settings['lightning_address'] ) : '';

		if ( empty( $address ) ) {
			$this->save_connection_status(
				array(
					'lud21_verified' => 'no',
					'status_message' => __( 'Lightning Address is required.', 'lawallet-lightning-address' ),
				)
			);
			return;
		}

		$client = new WCLL_LNURL_Client( $settings );
		$result = $client->test_lud21( $address );

		if ( is_wp_error( $result ) ) {
			$this->save_connection_status(
				array(
					'lud21_verified' => 'no',
					'status_message' => $result->get_error_message(),
				)
			);
			WC_Admin_Settings::add_error( $result->get_error_message() );
			return;
		}

		$pay_request = $result['pay_request'];
		$this->save_connection_status(
			array(
				'lud21_verified' => 'yes',
				'verified_at'    => gmdate( 'c' ),
				'allows_nostr'   => ! empty( $pay_request['allowsNostr'] ) ? 'yes' : 'no',
				'nostr_pubkey'   => ! empty( $pay_request['nostrPubkey'] ) ? sanitize_text_field( $pay_request['nostrPubkey'] ) : '',
				'status_message' => __( 'Lightning Address supports LUD-21 verification.', 'lawallet-lightning-address' ),
			)
		);

		WC_Admin_Settings::add_message( __( 'Lightning Address verified with LUD-21.', 'lawallet-lightning-address' ) );
	}

	private function save_connection_status( array $values ) {
		$settings = self::get_gateway_settings();
		foreach ( $values as $key => $value ) {
			$settings[ $key ] = $value;
		}
		update_option( 'woocommerce_wcll_gateway_settings', $settings );
	}

	private function render_connection_status() {
		$settings = self::get_gateway_settings();
		$verified = isset( $settings['lud21_verified'] ) && 'yes' === $settings['lud21_verified'];
		$message  = ! empty( $settings['status_message'] ) ? $settings['status_message'] : __( 'No Lightning Address has been checked yet.', 'lawallet-lightning-address' );

		echo '<h2>' . esc_html__( 'Connection status', 'lawallet-lightning-address' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'LUD-21 verify', 'lawallet-lightning-address' ) . '</th><td>';
		echo $verified ? '<mark class="yes">' . esc_html__( 'Verified', 'lawallet-lightning-address' ) . '</mark>' : '<mark class="error">' . esc_html__( 'Not verified', 'lawallet-lightning-address' ) . '</mark>';
		echo '<p class="description">' . esc_html( $message ) . '</p>';
		if ( ! empty( $settings['verified_at'] ) ) {
			/* translators: %s: date and time of the last Lightning Address verification. */
			$last_checked = sprintf( __( 'Last checked: %s', 'lawallet-lightning-address' ), $settings['verified_at'] );
			echo '<p class="description">' . esc_html( $last_checked ) . '</p>';
		}
		if ( ! empty( $settings['allows_nostr'] ) && 'yes' === $settings['allows_nostr'] ) {
			echo '<p class="description">' . esc_html__( 'NIP-57 receipts are available for fast checkout detection.', 'lawallet-lightning-address' ) . '</p>';
		}
		echo '</td></tr></tbody></table>';
	}
}
