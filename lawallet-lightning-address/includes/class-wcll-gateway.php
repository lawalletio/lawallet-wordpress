<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'wcll_gateway';
		$this->method_title       = __( 'Accept Bitcoin with your Lightning Address', 'lawallet-lightning-address' );
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
			'nwc_proxy_enabled'     => array(
				'title'       => __( 'NWC proxy wallet', 'lawallet-lightning-address' ),
				'type'        => 'checkbox',
				'label'       => __( 'Settle through an NWC proxy wallet when the Lightning Address cannot confirm payments', 'lawallet-lightning-address' ),
				'description' => __( 'Required only for Lightning Addresses that support neither LUD-21 verification nor NIP-57 zap receipts. The plugin invoices an NWC (NIP-47) wallet, then forwards the funds to your Lightning Address minus a small routing reserve.', 'lawallet-lightning-address' ),
				'default'     => 'no',
			),
			'nwc_mode'              => array(
				'title'       => __( 'NWC wallet mode', 'lawallet-lightning-address' ),
				'type'        => 'select',
				'description' => __( 'Disposable: the plugin creates a throwaway wallet on demand and replaces it when it dies. Permanent: you provide a fixed connection string that is never replaced.', 'lawallet-lightning-address' ),
				'default'     => 'disposable',
				'options'     => array(
					'disposable' => __( 'Disposable (auto-created)', 'lawallet-lightning-address' ),
					'permanent'  => __( 'Permanent (your own connection)', 'lawallet-lightning-address' ),
				),
				'desc_tip'    => true,
			),
			'nwc_lncurl_url'        => array(
				'title'       => __( 'Disposable wallet service (lncurl)', 'lawallet-lightning-address' ),
				'type'        => 'text',
				'description' => __( 'Used in Disposable mode. An lncurl service that mints a wallet from a single request. Defaults to https://lncurl.lol.', 'lawallet-lightning-address' ),
				'placeholder' => 'https://lncurl.lol',
				'default'     => 'https://lncurl.lol',
				'desc_tip'    => true,
			),
			'nwc_auto_replace'      => array(
				'title'       => __( 'Auto-replace dead wallet', 'lawallet-lightning-address' ),
				'type'        => 'checkbox',
				'label'       => __( 'Provision a new wallet when the current disposable wallet becomes unavailable', 'lawallet-lightning-address' ),
				'description' => __( 'Disposable mode only. A permanent connection is never replaced.', 'lawallet-lightning-address' ),
				'default'     => 'yes',
			),
			'nwc_connection'        => array(
				'title'       => __( 'Permanent NWC connection string', 'lawallet-lightning-address' ),
				'type'        => 'password',
				'description' => __( 'Used in Permanent mode: a nostr+walletconnect:// string for a wallet you control. It is stored securely on the server (not in the settings form) and is not shown again after saving; leave blank to keep the current one. Never exposed to customers. Leave blank in Disposable mode.', 'lawallet-lightning-address' ),
				'placeholder' => 'nostr+walletconnect://...',
				'default'     => '',
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

	/**
	 * Grouping of the gateway settings into tabs for a clearer admin UI. Each
	 * field is still part of the single settings form, so saving and validation
	 * are unchanged — only the presentation is grouped.
	 */
	public static function settings_tabs() {
		return array(
			'checkout' => array(
				'label'  => __( 'Checkout', 'lawallet-lightning-address' ),
				'intro'  => __( 'Enable the gateway and control how it appears to customers at checkout.', 'lawallet-lightning-address' ),
				'fields' => array( 'enabled', 'title', 'description', 'invoice_expiry_minutes' ),
			),
			'address'  => array(
				'label'  => __( 'Lightning Address', 'lawallet-lightning-address' ),
				'intro'  => __( 'Your merchant Lightning Address. The badges show whether it can confirm payments via LUD-21 or NIP-57.', 'lawallet-lightning-address' ),
				'fields' => array( 'lightning_address' ),
			),
			'nwc'      => array(
				'label'  => __( 'NWC proxy wallet', 'lawallet-lightning-address' ),
				'intro'  => __( 'Optional fallback for Lightning Addresses that support neither LUD-21 nor NIP-57. Payments settle on an NWC wallet and are forwarded to your Lightning Address.', 'lawallet-lightning-address' ),
				'fields' => array( 'nwc_proxy_enabled', 'nwc_mode', 'nwc_lncurl_url', 'nwc_auto_replace', 'nwc_connection' ),
			),
			'pricing'  => array(
				'label'  => __( 'Pricing & rates', 'lawallet-lightning-address' ),
				'intro'  => __( 'How fiat order totals are converted to satoshis and shown on the payment page.', 'lawallet-lightning-address' ),
				'fields' => array( 'manual_sats_per_unit', 'rate_display_unit', 'price_buffer_percent' ),
			),
			'advanced' => array(
				'label'  => __( 'Advanced', 'lawallet-lightning-address' ),
				'intro'  => __( 'NIP-57 relay URLs, plus developer and local-environment options.', 'lawallet-lightning-address' ),
				'fields' => array( 'nostr_relays', 'allow_insecure_http' ),
			),
		);
	}

	/**
	 * Popular wallets that give customers a Lightning Address, shown in the
	 * "compatible wallets" picker. Mirrors the project landing page; logos are
	 * bundled under assets/wallets/.
	 */
	public static function compatible_wallets() {
		return array(
			array(
				'name'    => 'Wallet of Satoshi',
				'tagline' => __( 'Easiest', 'lawallet-lightning-address' ),
				'desc'    => __( 'The simplest way to start: download, get a Lightning Address, and receive in seconds.', 'lawallet-lightning-address' ),
				'logo'    => 'wallet-of-satoshi.png',
				'accent'  => '#14B8A6',
				'url'     => 'https://www.walletofsatoshi.com/',
			),
			array(
				'name'    => 'Primal',
				'tagline' => __( 'Nostr native', 'lawallet-lightning-address' ),
				'desc'    => __( 'A Nostr social client with a built-in Lightning wallet and address.', 'lawallet-lightning-address' ),
				'logo'    => 'primal.svg',
				'accent'  => '#8B2FF7',
				'url'     => 'https://primal.net/',
			),
			array(
				'name'    => 'Strike',
				'tagline' => __( 'Most popular', 'lawallet-lightning-address' ),
				'desc'    => __( 'One of the most widely used Bitcoin apps, with Lightning Addresses across many regions.', 'lawallet-lightning-address' ),
				'logo'    => 'strike.png',
				'accent'  => '#CFD3DA',
				'url'     => 'https://strike.me/',
			),
			array(
				'name'    => 'Alby',
				'tagline' => __( 'Self custody', 'lawallet-lightning-address' ),
				'desc'    => __( 'A self-custodial Lightning wallet and browser extension for the open web.', 'lawallet-lightning-address' ),
				'logo'    => 'alby.svg',
				'accent'  => '#FFC83A',
				'url'     => 'https://getalby.com/',
			),
			array(
				'name'    => 'Blink',
				'tagline' => __( 'Good for merchants', 'lawallet-lightning-address' ),
				'desc'    => __( 'A popular Bitcoin wallet, great for merchants and everyday payments.', 'lawallet-lightning-address' ),
				'logo'    => 'blink.png',
				'accent'  => '#FF6B00',
				'url'     => 'https://www.blink.sv/',
			),
			array(
				'name'    => 'LNbits',
				'tagline' => __( 'Own infrastructure', 'lawallet-lightning-address' ),
				'desc'    => __( 'Run your own Lightning accounts and addresses on your infrastructure.', 'lawallet-lightning-address' ),
				'logo'    => 'lnbits.svg',
				'accent'  => '#EC4899',
				'url'     => 'https://lnbits.com/',
			),
		);
	}

	public function admin_options() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag that only toggles an onboarding notice for admins.
		if ( isset( $_GET['wcll_setup'] ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Set your Lightning Address to start accepting Lightning payments.', 'lawallet-lightning-address' ) . '</strong> ';
			echo esc_html__( 'Saving this page creates a test invoice and confirms the address supports LUD-21 verification or NIP-57 zap receipts.', 'lawallet-lightning-address' ) . '</p></div>';
		}

		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo wp_kses_post( wpautop( $this->get_method_description() ) );

		$tabs     = self::settings_tabs();
		$assigned = array();
		foreach ( $tabs as $tab ) {
			$assigned = array_merge( $assigned, $tab['fields'] );
		}
		// Defensive: never drop a field — anything unassigned joins the first tab.
		$leftover = array_diff( array_keys( $this->form_fields ), $assigned );
		if ( ! empty( $leftover ) ) {
			$first_id                      = array_key_first( $tabs );
			$tabs[ $first_id ]['fields']   = array_merge( $tabs[ $first_id ]['fields'], $leftover );
		}

		echo '<div class="wcll-settings">';
		echo '<nav class="nav-tab-wrapper wcll-tabs">';
		$is_first = true;
		foreach ( $tabs as $id => $tab ) {
			printf(
				'<a href="#wcll-%1$s" class="nav-tab%2$s" data-wcll-tab="%1$s">%3$s</a>',
				esc_attr( $id ),
				$is_first ? ' nav-tab-active' : '',
				esc_html( $tab['label'] )
			);
			$is_first = false;
		}
		echo '</nav>';

		$is_first = true;
		foreach ( $tabs as $id => $tab ) {
			printf( '<div class="wcll-tab-panel%2$s" data-wcll-panel="%1$s">', esc_attr( $id ), $is_first ? ' is-active' : '' );
			if ( ! empty( $tab['intro'] ) ) {
				echo '<p class="description wcll-tab-intro">' . esc_html( $tab['intro'] ) . '</p>';
			}

			$group = array();
			foreach ( $tab['fields'] as $key ) {
				if ( isset( $this->form_fields[ $key ] ) ) {
					$group[ $key ] = $this->form_fields[ $key ];
				}
			}

			echo '<table class="form-table">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce-generated settings field markup.
			echo $this->generate_settings_html( $group, false );
			echo '</table>';
			if ( 'address' === $id ) {
				$proxy_on = 'yes' === $this->get_option( 'nwc_proxy_enabled', 'no' );
				echo '<p class="wcll-nwc-proxy-toggle"><label><input type="checkbox" id="wcll-use-nwc-proxy" data-wcll-nwc-mirror' . checked( $proxy_on, true, false ) . ' /> '
					. esc_html__( 'Use NWC Proxy', 'lawallet-lightning-address' ) . '</label> <span class="description">'
					. esc_html__( 'Settle through an NWC proxy wallet when the Lightning Address cannot confirm payments. Configure it on the NWC proxy wallet tab.', 'lawallet-lightning-address' )
					. '</span></p>';
				$this->render_compatible_wallets();
			}
			if ( 'nwc' === $id ) {
				$this->render_nwc_wallet_panel();
			}
			echo '</div>';
			$is_first = false;
		}
		echo '</div>';

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

	public function validate_nwc_connection_field( $key, $value ) {
		unset( $key );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			// Write-only field: an empty submit keeps the stored connection.
			return '';
		}

		$parsed = WCLL_NWC_Client::parse_connection( $value );
		if ( is_wp_error( $parsed ) ) {
			WC_Admin_Settings::add_error( $parsed->get_error_message() );
			return '';
		}

		// Persist the secret OUTSIDE the WooCommerce settings array (autoload=no),
		// so it is never stored in or rendered back from the autoloaded settings
		// option / admin form. Always return '' for the settings field itself.
		WCLL_NWC_Manager::set_permanent_connection( $value );
		return '';
	}

	public function validate_nwc_mode_field( $key, $value ) {
		unset( $key );
		$value = strtolower( sanitize_key( $value ) );
		return in_array( $value, array( 'permanent', 'disposable' ), true ) ? $value : 'disposable';
	}

	public function validate_nwc_lncurl_url_field( $key, $value ) {
		unset( $key );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return WCLL_NWC_Manager::DEFAULT_LNCURL;
		}

		$clean = esc_url_raw( $value );
		if ( '' === $clean || ! preg_match( '#^https?://#i', $clean ) ) {
			WC_Admin_Settings::add_error( __( 'The lncurl service must be a valid http(s) URL.', 'lawallet-lightning-address' ) );
			return WCLL_NWC_Manager::DEFAULT_LNCURL;
		}

		return $clean;
	}

	/**
	 * The settlement method recorded for the configured address: lud21, nip57,
	 * nwc, or none.
	 */
	public static function effective_settlement( array $settings ) {
		if ( ! empty( $settings['settlement'] ) ) {
			return (string) $settings['settlement'];
		}
		if ( isset( $settings['lud21_verified'] ) && 'yes' === $settings['lud21_verified'] ) {
			return 'lud21';
		}
		return 'none';
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		$address = $this->get_option( 'lightning_address', '' );
		if ( empty( $address ) ) {
			return false;
		}

		// Hide the gateway unless a settlement method (LUD-21, NIP-57, or the NWC
		// proxy) has been confirmed for the address.
		$settlement = self::effective_settlement( self::get_gateway_settings() );
		return in_array( $settlement, array( 'lud21', 'nip57', 'nwc' ), true );
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

		// Settlement must be confirmable later, either through a LUD-21 verify URL
		// or NIP-57 zap receipts on the relays advertised by the wallet.
		$has_verify        = ! empty( $invoice['verify'] );
		$has_zap           = ! empty( $invoice['nostr']['event'] );
		$settlement_method = $has_verify ? 'lud21' : ( $has_zap ? 'nip57' : '' );
		$expiry_minutes    = isset( $settings['invoice_expiry_minutes'] ) ? max( 1, absint( $settings['invoice_expiry_minutes'] ) ) : 30;

		$nwc_wallet_pubkey = '';
		$nwc_client_pubkey = '';
		$nwc_relays        = array();
		$payment_hash      = '';

		if ( ! $has_verify && ! $has_zap ) {
			// Last resort: the address can confirm nothing on its own, so invoice an
			// NWC proxy wallet (which we can verify and forward) instead. In
			// disposable mode this lazily mints a wallet and self-heals a dead one.
			$result = WCLL_NWC_Manager::make_order_invoice(
				$settings,
				$amount_msat,
				sprintf( 'WooCommerce order #%s', $order->get_order_number() ),
				$expiry_minutes * MINUTE_IN_SECONDS
			);
			if ( is_wp_error( $result ) ) {
				wc_add_notice( __( 'Payment error: this Lightning Address cannot confirm payments. It returned no LUD-21 verify URL and does not support NIP-57 zap receipts.', 'lawallet-lightning-address' ) . ' ' . $result->get_error_message(), 'error' );
				return array( 'result' => 'failure' );
			}

			$nwc   = $result['client'];
			$proxy = $result['invoice'];
			if ( empty( $proxy['invoice'] ) || empty( $proxy['payment_hash'] ) ) {
				wc_add_notice( __( 'Payment error: the NWC proxy wallet returned an incomplete invoice.', 'lawallet-lightning-address' ), 'error' );
				return array( 'result' => 'failure' );
			}

			// The customer now pays the proxy invoice; settlement is forwarded later.
			$invoice['pr']     = $proxy['invoice'];
			$invoice['verify'] = '';
			$settlement_method = 'nwc';
			$payment_hash      = $proxy['payment_hash'];
			$nwc_wallet_pubkey = $nwc->wallet_pubkey();
			$nwc_client_pubkey = $nwc->client_pubkey();
			$nwc_relays        = $nwc->relays();
		}

		$expires_at = time() + ( $expiry_minutes * MINUTE_IN_SECONDS );
		$nostr      = isset( $invoice['nostr'] ) && is_array( $invoice['nostr'] ) ? $invoice['nostr'] : array();

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

		$order->update_meta_data( '_wcll_settlement_method', $settlement_method );
		if ( 'nwc' === $settlement_method ) {
			$order->update_meta_data( '_wcll_payment_hash', sanitize_text_field( $payment_hash ) );
			$order->update_meta_data( '_wcll_nwc_wallet_pubkey', sanitize_text_field( $nwc_wallet_pubkey ) );
			$order->update_meta_data( '_wcll_nwc_client_pubkey', sanitize_text_field( $nwc_client_pubkey ) );
			$order->update_meta_data( '_wcll_nwc_relays', array_values( array_map( 'sanitize_text_field', $nwc_relays ) ) );
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
			// No LUD-21 verify URL: the address can still take payments if it
			// supports NIP-57 zap receipts and relays are configured.
			$nip57 = $this->check_nip57_capability( $address, $settings );
			if ( ! is_wp_error( $nip57 ) ) {
				$this->save_connection_status(
					array(
						'lud21_verified' => 'no',
						'settlement'     => 'nip57',
						'verified_at'    => gmdate( 'c' ),
						'allows_nostr'   => 'yes',
						'nostr_pubkey'   => $nip57['pubkey'],
						'status_message' => __( 'No LUD-21 verify URL; payments will be confirmed via NIP-57 zap receipts.', 'lawallet-lightning-address' ),
					)
				);
				WC_Admin_Settings::add_message( __( 'Lightning Address will be confirmed via NIP-57 zap receipts.', 'lawallet-lightning-address' ) );
				return;
			}

			// Neither LUD-21 nor NIP-57. The NWC proxy wallet is the last resort.
			if ( WCLL_NWC_Manager::is_enabled( $settings ) ) {
				$probe = WCLL_NWC_Manager::probe( $settings );
				if ( $probe['ok'] ) {
					$this->save_connection_status(
						array(
							'lud21_verified'   => 'no',
							'settlement'       => 'nwc',
							'verified_at'      => gmdate( 'c' ),
							'allows_nostr'     => 'no',
							'nostr_pubkey'     => '',
							'nwc_mode'         => $probe['mode'],
							'nwc_balance_sats' => null === $probe['balance_sats'] ? '' : (int) $probe['balance_sats'],
							'status_message'   => $probe['message'],
						)
					);
					WC_Admin_Settings::add_message( $probe['message'] );
					return;
				}
				$nwc_error = $probe['message'];
			} else {
				$nwc_error = __( 'Enable and configure the NWC proxy wallet to accept this address.', 'lawallet-lightning-address' );
			}

			$message = trim( $result->get_error_message() . ' ' . $nwc_error );
			$this->save_connection_status(
				array(
					'lud21_verified' => 'no',
					'settlement'     => 'none',
					'status_message' => $message,
				)
			);
			WC_Admin_Settings::add_error( $message );
			return;
		}

		$pay_request = $result['pay_request'];
		$this->save_connection_status(
			array(
				'lud21_verified' => 'yes',
				'settlement'     => 'lud21',
				'verified_at'    => gmdate( 'c' ),
				'allows_nostr'   => ! empty( $pay_request['allowsNostr'] ) ? 'yes' : 'no',
				'nostr_pubkey'   => ! empty( $pay_request['nostrPubkey'] ) ? sanitize_text_field( $pay_request['nostrPubkey'] ) : '',
				'status_message' => __( 'Lightning Address supports LUD-21 verification.', 'lawallet-lightning-address' ),
			)
		);

		WC_Admin_Settings::add_message( __( 'Lightning Address verified with LUD-21.', 'lawallet-lightning-address' ) );
	}

	private function check_nip57_capability( $address, array $settings ) {
		$client      = new WCLL_LNURL_Client( $settings );
		$pay_request = $client->resolve_lightning_address( $address );
		if ( is_wp_error( $pay_request ) ) {
			return $pay_request;
		}

		$pubkey = isset( $pay_request['nostrPubkey'] ) ? strtolower( (string) $pay_request['nostrPubkey'] ) : '';
		if ( empty( $pay_request['allowsNostr'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $pubkey ) ) {
			return new WP_Error( 'wcll_no_nip57', __( 'This Lightning Address does not advertise NIP-57 zap receipts.', 'lawallet-lightning-address' ) );
		}

		$relays = WCLL_LNURL_Client::parse_relays( isset( $settings['nostr_relays'] ) ? $settings['nostr_relays'] : '' );
		if ( empty( $relays ) ) {
			return new WP_Error( 'wcll_no_relays', __( 'Add at least one NIP-57 relay URL to confirm payments when LUD-21 is unavailable.', 'lawallet-lightning-address' ) );
		}

		return array(
			'pubkey' => $pubkey,
			'relays' => $relays,
		);
	}

	private function save_connection_status( array $values ) {
		$settings = self::get_gateway_settings();
		foreach ( $values as $key => $value ) {
			$settings[ $key ] = $value;
		}
		update_option( 'woocommerce_wcll_gateway_settings', $settings );
	}

	private function render_connection_status() {
		$settings   = self::get_gateway_settings();
		$settlement = self::effective_settlement( $settings );
		$message    = ! empty( $settings['status_message'] ) ? $settings['status_message'] : __( 'No Lightning Address has been checked yet.', 'lawallet-lightning-address' );

		echo '<h2>' . esc_html__( 'Connection status', 'lawallet-lightning-address' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Settlement verification', 'lawallet-lightning-address' ) . '</th><td>';
		if ( 'lud21' === $settlement ) {
			echo '<mark class="yes">' . esc_html__( 'LUD-21 verified', 'lawallet-lightning-address' ) . '</mark>';
		} elseif ( 'nip57' === $settlement ) {
			echo '<mark class="yes">' . esc_html__( 'NIP-57 zap receipts', 'lawallet-lightning-address' ) . '</mark>';
		} elseif ( 'nwc' === $settlement ) {
			echo '<mark class="yes">' . esc_html__( 'NWC proxy wallet', 'lawallet-lightning-address' ) . '</mark>';
		} else {
			echo '<mark class="error">' . esc_html__( 'Not verified', 'lawallet-lightning-address' ) . '</mark>';
		}
		echo '<p class="description">' . esc_html( $message ) . '</p>';
		if ( ! empty( $settings['verified_at'] ) ) {
			/* translators: %s: date and time of the last Lightning Address verification. */
			$last_checked = sprintf( __( 'Last checked: %s', 'lawallet-lightning-address' ), $settings['verified_at'] );
			echo '<p class="description">' . esc_html( $last_checked ) . '</p>';
		}
		if ( ! empty( $settings['allows_nostr'] ) && 'yes' === $settings['allows_nostr'] ) {
			echo '<p class="description">' . esc_html__( 'NIP-57 receipts are available for fast checkout detection.', 'lawallet-lightning-address' ) . '</p>';
		}
		if ( 'nwc' === $settlement ) {
			$mode      = WCLL_NWC_Manager::mode( $settings );
			$mode_text = 'disposable' === $mode
				? __( 'Mode: disposable (auto-created wallet).', 'lawallet-lightning-address' )
				: __( 'Mode: permanent (your own connection).', 'lawallet-lightning-address' );
			echo '<p class="description">' . esc_html( $mode_text ) . '</p>';

			$balance = WCLL_NWC_Manager::get_cached_balance( $settings );
			if ( ! empty( $balance['ok'] ) ) {
				/* translators: %s: NWC proxy wallet balance in sats. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Wallet balance: %s sats.', 'lawallet-lightning-address' ), number_format_i18n( (int) $balance['sats'] ) ) ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Wallet balance: unavailable.', 'lawallet-lightning-address' ) . '</p>';
			}
		}
		echo '</td></tr></tbody></table>';
	}

	/**
	 * Live wallet panel shown in the NWC tab: real-time balance plus receive
	 * (make_invoice) and withdraw (pay) controls. All signed NWC operations run
	 * server-side over AJAX; the browser only subscribes to notifications.
	 */
	private function render_nwc_wallet_panel() {
		$info = WCLL_NWC_Manager::admin_wallet_info( self::get_gateway_settings() );

		echo '<div class="wcll-nwc-wallet" data-wcll-nwc-wallet>';
		echo '<h4 class="wcll-nwc-wallet-title">' . esc_html__( 'Proxy wallet', 'lawallet-lightning-address' ) . '</h4>';

		if ( empty( $info['configured'] ) ) {
			echo '<p class="description">' . esc_html__( 'Save the settings with the NWC proxy enabled and configured to manage the wallet here.', 'lawallet-lightning-address' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wcll-nwc-balance-row">';
		echo '<span class="wcll-nwc-balance-label">' . esc_html__( 'Balance', 'lawallet-lightning-address' ) . '</span> ';
		echo '<span class="wcll-nwc-balance" data-wcll-nwc-balance>' . esc_html__( 'Loading balance…', 'lawallet-lightning-address' ) . '</span> ';
		echo '<button type="button" class="button-link wcll-nwc-refresh" data-wcll-nwc-refresh aria-label="' . esc_attr__( 'Refresh balance', 'lawallet-lightning-address' ) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>';
		echo '</div>';

		echo '<div class="wcll-nwc-actions">';
		echo '<button type="button" class="button" data-wcll-nwc-tab="receive">' . esc_html__( 'Receive', 'lawallet-lightning-address' ) . '</button> ';
		echo '<button type="button" class="button" data-wcll-nwc-tab="withdraw">' . esc_html__( 'Withdraw', 'lawallet-lightning-address' ) . '</button>';
		echo '</div>';

		echo '<div class="wcll-nwc-form" data-wcll-nwc-form="receive" hidden>';
		echo '<p><label>' . esc_html__( 'Amount (sats)', 'lawallet-lightning-address' ) . ' <input type="number" min="1" step="1" class="small-text" data-wcll-nwc-amount="receive" /></label> ';
		echo '<button type="button" class="button button-primary" data-wcll-nwc-generate>' . esc_html__( 'Generate invoice', 'lawallet-lightning-address' ) . '</button></p>';
		echo '<div class="wcll-nwc-invoice" data-wcll-nwc-invoice hidden>';
		echo '<textarea readonly rows="3" class="large-text code" data-wcll-nwc-invoice-text></textarea>';
		echo '<button type="button" class="button" data-wcll-nwc-copy>' . esc_html__( 'Copy invoice', 'lawallet-lightning-address' ) . '</button>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wcll-nwc-form" data-wcll-nwc-form="withdraw" hidden>';
		echo '<p><label class="wcll-nwc-field"><span>' . esc_html__( 'To (Lightning Address or BOLT11 invoice)', 'lawallet-lightning-address' ) . '</span>';
		echo '<input type="text" class="regular-text" data-wcll-nwc-destination placeholder="name@example.com" /></label></p>';
		echo '<p><label class="wcll-nwc-field"><span>' . esc_html__( 'Amount (sats — required for a Lightning Address)', 'lawallet-lightning-address' ) . '</span>';
		echo '<input type="number" min="1" step="1" class="small-text" data-wcll-nwc-amount="withdraw" /></label></p>';
		echo '<button type="button" class="button button-primary" data-wcll-nwc-send>' . esc_html__( 'Send payment', 'lawallet-lightning-address' ) . '</button>';
		echo '</div>';

		echo '<p class="wcll-nwc-feedback" data-wcll-nwc-feedback role="status" aria-live="polite"></p>';
		echo '</div>';
	}

	private function render_compatible_wallets() {
		$wallets = self::compatible_wallets();

		echo '<div class="wcll-wallets">';
		echo '<h3 class="wcll-wallets-title">' . esc_html__( 'Compatible wallets', 'lawallet-lightning-address' ) . '</h3>';
		echo '<p class="wcll-wallets-lead">' . esc_html__( 'This plugin works with every Lightning Address. Customers can pay from any of these popular wallets, or any other wallet that gives them a Lightning Address.', 'lawallet-lightning-address' ) . '</p>';
		echo '<ul class="wcll-wallets-grid">';
		foreach ( $wallets as $wallet ) {
			$logo = WCLL_PLUGIN_URL . 'assets/wallets/' . $wallet['logo'];
			/* translators: %s: wallet name. */
			$aria = sprintf( __( 'Open the %s website (opens in a new tab)', 'lawallet-lightning-address' ), $wallet['name'] );
			echo '<li class="wcll-wallet-card" style="--wcll-accent:' . esc_attr( $wallet['accent'] ) . '">';
			echo '<a class="wcll-wallet-link" href="' . esc_url( $wallet['url'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $aria ) . '">';
			echo '<span class="wcll-wallet-logo"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $wallet['name'] ) . '" loading="lazy" /></span>';
			echo '<span class="wcll-wallet-info">';
			echo '<span class="wcll-wallet-name">' . esc_html( $wallet['name'] ) . ' <span class="wcll-wallet-ext dashicons dashicons-external" aria-hidden="true"></span></span>';
			echo '<span class="wcll-wallet-tag">' . esc_html( $wallet['tagline'] ) . '</span>';
			echo '<span class="wcll-wallet-desc">' . esc_html( $wallet['desc'] ) . '</span>';
			echo '</span>';
			echo '</a>';
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="wcll-wallets-foot">' . esc_html__( 'These are popular examples. See the full list of compatible wallets and services at', 'lawallet-lightning-address' )
			. ' <a href="' . esc_url( 'https://lightningaddress.com/' ) . '" target="_blank" rel="noopener noreferrer">lightningaddress.com</a>.</p>';
		echo '</div>';
	}
}
