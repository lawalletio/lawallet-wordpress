<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Plugin {
	const CRON_HOOK = 'wcll_check_pending_payments';

	/**
	 * Floor (in millisatoshis) kept back from a forwarded NWC-proxy payment to
	 * cover the routing fee when paying the merchant Lightning Address.
	 */
	const FORWARD_RESERVE_FLOOR_MSAT = 10000;

	/** Per-wallet in-flight sweep invoice, keyed by wallet pubkey (idempotency). */
	const SWEEP_INFLIGHT_OPTION = 'wcll_nwc_sweep_inflight';

	/** Rolling log of completed proxy sweeps (payouts to the merchant address). */
	const SWEEP_LOG_OPTION = 'wcll_nwc_sweeps';

	/** Most sweep log entries to retain. */
	const SWEEP_LOG_MAX = 50;

	/** Most Lightning-Address payments a single sweep run will make per wallet. */
	const SWEEP_MAX_HOPS = 8;

	/**
	 * Millisatoshis withheld from an NWC-proxy forward as a routing-fee reserve:
	 * 1% of the amount, never below {@see self::FORWARD_RESERVE_FLOOR_MSAT}.
	 * Centralised so checkout, forwarding and the admin detail view stay in sync.
	 */
	public static function forward_reserve_msat( $amount_msat ) {
		return max( (int) round( (int) $amount_msat * 0.01 ), self::FORWARD_RESERVE_FLOOR_MSAT );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		WCLL_Discovery::init();
		if ( class_exists( 'WCLL_Updater' ) ) {
			WCLL_Updater::init();
		}

		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-gateway.php';
			WCLL_NWC_Manager::maybe_migrate();
		}

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCLL_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_pending_payments' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron_scheduled' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_paid_order_pay_to_order_received' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );
		add_action( 'wp_ajax_wcll_claim_payment', array( __CLASS__, 'ajax_claim_payment' ) );
		add_action( 'wp_ajax_nopriv_wcll_claim_payment', array( __CLASS__, 'ajax_claim_payment' ) );
		add_action( 'wp_ajax_wcll_recreate_invoice', array( __CLASS__, 'ajax_recreate_invoice' ) );
		add_action( 'wp_ajax_nopriv_wcll_recreate_invoice', array( __CLASS__, 'ajax_recreate_invoice' ) );
		add_action( 'woocommerce_receipt_wcll_gateway', array( __CLASS__, 'render_payment_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_gateway_admin_assets' ) );
		add_action( 'wp_ajax_wcll_check_lightning_address', array( __CLASS__, 'ajax_check_lightning_address' ) );
		add_action( 'wp_ajax_wcll_nwc_balance', array( __CLASS__, 'ajax_nwc_balance' ) );
		add_action( 'wp_ajax_wcll_nwc_receiver_balance', array( __CLASS__, 'ajax_nwc_receiver_balance' ) );
		add_action( 'wp_ajax_wcll_nwc_receive', array( __CLASS__, 'ajax_nwc_receive' ) );
		add_action( 'wp_ajax_wcll_nwc_invoice_status', array( __CLASS__, 'ajax_nwc_invoice_status' ) );
		add_action( 'wp_ajax_wcll_nwc_withdraw', array( __CLASS__, 'ajax_nwc_withdraw' ) );
		add_action( 'wp_ajax_wcll_nwc_regenerate', array( __CLASS__, 'ajax_nwc_regenerate' ) );
		add_action( 'wp_ajax_wcll_nwc_create', array( __CLASS__, 'ajax_nwc_create_disposable' ) );
		add_action( 'wp_ajax_wcll_nwc_connection', array( __CLASS__, 'ajax_nwc_connection' ) );
		add_action( 'wp_ajax_wcll_nwc_transactions', array( __CLASS__, 'ajax_nwc_transactions' ) );
		add_action( 'wp_ajax_wcll_nwc_transaction', array( __CLASS__, 'ajax_nwc_transaction' ) );
	}

	public static function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Loads the bundled es_* translations until translate.wordpress.org provides them.
		load_plugin_textdomain( 'lawallet-lightning-address', false, dirname( plugin_basename( WCLL_PLUGIN_FILE ) ) . '/languages' );
	}

	public static function enqueue_checkout_assets() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			wp_enqueue_style( 'wcll-checkout-method', WCLL_PLUGIN_URL . 'assets/css/checkout-method.css', array(), WCLL_VERSION );
		}
	}

	public static function enqueue_gateway_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'checkout' !== $tab || 'wcll_gateway' !== $section ) {
			return;
		}

		wp_enqueue_style( 'lawallet-gateway-admin', WCLL_PLUGIN_URL . 'assets/css/lawallet-gateway-admin.css', array( 'dashicons' ), WCLL_VERSION );
		wp_enqueue_script( 'lawallet-gateway-admin', WCLL_PLUGIN_URL . 'assets/js/lawallet-gateway-admin.js', array(), WCLL_VERSION, true );

		$settings    = WCLL_Gateway::get_gateway_settings();
		$wallet_info = WCLL_NWC_Manager::admin_wallet_info( $settings );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wcll_check_lightning_address' ),
			'fieldId' => 'woocommerce_wcll_gateway_lightning_address',
			'i18n'    => array(
				'lud16Label' => __( 'LUD-16', 'lawallet-lightning-address' ),
				'lud21Label' => __( 'LUD-21', 'lawallet-lightning-address' ),
				'nip57Label' => __( 'NIP-57', 'lawallet-lightning-address' ),
				'checking'   => __( 'Checking the Lightning Address', 'lawallet-lightning-address' ),
				'pending'    => __( 'Enter a Lightning Address to check', 'lawallet-lightning-address' ),
			),
			'nwc'     => array(
				'nonce'        => wp_create_nonce( 'wcll_nwc_admin' ),
				'configured'   => (bool) $wallet_info['configured'],
				'mode'         => $wallet_info['mode'],
				'walletPubkey' => $wallet_info['wallet_pubkey'],
				'clientPubkey' => $wallet_info['client_pubkey'],
				'relays'       => self::sanitize_ws_relays( $wallet_info['relays'] ),
				'i18n'         => array(
					'loading'        => __( 'Loading balance…', 'lawallet-lightning-address' ),
					'unavailable'    => __( 'Balance unavailable', 'lawallet-lightning-address' ),
					'sats'           => __( 'sats', 'lawallet-lightning-address' ),
					'sending'        => __( 'Sending…', 'lawallet-lightning-address' ),
					'generating'     => __( 'Generating…', 'lawallet-lightning-address' ),
					'copied'         => __( 'Copied', 'lawallet-lightning-address' ),
					'sent'           => __( 'Payment sent.', 'lawallet-lightning-address' ),
					'received'       => __( 'Payment received.', 'lawallet-lightning-address' ),
					'waitingPayment' => __( 'Waiting for payment…', 'lawallet-lightning-address' ),
					'amountRequired' => __( 'Enter an amount in sats.', 'lawallet-lightning-address' ),
					'destRequired'   => __( 'Enter a Lightning Address or BOLT11 invoice.', 'lawallet-lightning-address' ),
					'regenerating'   => __( 'Regenerating…', 'lawallet-lightning-address' ),
					'regenerated'    => __( 'Connection regenerated.', 'lawallet-lightning-address' ),
					'connShow'       => __( 'Show connection string', 'lawallet-lightning-address' ),
					'connHide'       => __( 'Hide connection string', 'lawallet-lightning-address' ),
					'connError'      => __( 'Could not load the connection string.', 'lawallet-lightning-address' ),
					'createConfirm'  => __( 'Create a new disposable wallet? The current one will be archived.', 'lawallet-lightning-address' ),
					'creating'       => __( 'Creating…', 'lawallet-lightning-address' ),
					'created'        => __( 'New disposable wallet created.', 'lawallet-lightning-address' ),
					'walletEmpty'    => __( 'now (empty)', 'lawallet-lightning-address' ),
					'txLoading'      => __( 'Loading…', 'lawallet-lightning-address' ),
					/* translators: 1: current page number, 2: total number of pages. */
					'txPage'         => __( 'Page %1$s of %2$s', 'lawallet-lightning-address' ),
				),
			),
		);
		wp_add_inline_script( 'lawallet-gateway-admin', 'window.WCLLGatewayAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function ajax_check_lightning_address() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to verify the Lightning Address.', 'lawallet-lightning-address' ) ), 403 );
		}

		check_ajax_referer( 'wcll_check_lightning_address', 'nonce' );

		$address = isset( $_POST['address'] ) ? trim( strtolower( sanitize_text_field( wp_unslash( $_POST['address'] ) ) ) ) : '';
		if ( '' === $address || ! preg_match( '/^[^@\s]+@[^@\s]+$/', $address ) ) {
			$invalid = __( 'Enter a Lightning Address like name@example.com.', 'lawallet-lightning-address' );
			wp_send_json_success(
				array(
					'address' => $address,
					'lud16'   => array( 'ok' => false, 'message' => $invalid ),
					'lud21'   => array( 'ok' => false, 'message' => $invalid ),
					'nip57'   => array( 'ok' => false, 'message' => $invalid ),
				)
			);
		}

		$settings = get_option( 'woocommerce_wcll_gateway_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$client   = new WCLL_LNURL_Client( $settings );

		$pay_request = $client->resolve_lightning_address( $address );
		if ( is_wp_error( $pay_request ) ) {
			$resolve_error = $pay_request->get_error_message();
			$lud16         = array( 'ok' => false, 'message' => $resolve_error );
			$lud21         = array( 'ok' => false, 'message' => $resolve_error );
			$nip57         = array( 'ok' => false, 'message' => $resolve_error );
		} else {
			$lud16 = array( 'ok' => true, 'message' => __( 'Lightning Address resolves (LUD-16).', 'lawallet-lightning-address' ) );

			$amount_msat = max( 1000, (int) $pay_request['minSendable'] );
			if ( $amount_msat > (int) $pay_request['maxSendable'] ) {
				$lud21 = array( 'ok' => false, 'message' => __( 'The Lightning Address minimum amount is higher than its maximum amount.', 'lawallet-lightning-address' ) );
			} else {
				$invoice = $client->request_invoice(
					$pay_request,
					$amount_msat,
					array(
						'description' => 'WooCommerce LUD-21 check',
						'use_nostr'   => false,
					)
				);
				// LUD-21 is only supported when the callback actually returns a
				// `verify` URL on the generated invoice — creating an invoice alone
				// is not enough.
				if ( is_wp_error( $invoice ) ) {
					$lud21 = array(
						'ok'      => false,
						'message' => $invoice->get_error_message(),
					);
				} elseif ( empty( $invoice['verify'] ) ) {
					$lud21 = array(
						'ok'      => false,
						'message' => __( 'This Lightning Address does not support LUD-21 verification (no verify URL).', 'lawallet-lightning-address' ),
					);
				} else {
					$lud21 = array(
						'ok'      => true,
						'message' => __( 'LUD-21 settlement verification supported.', 'lawallet-lightning-address' ),
					);
				}
			}

			$nostr_pubkey = isset( $pay_request['nostrPubkey'] ) ? (string) $pay_request['nostrPubkey'] : '';
			if ( ! empty( $pay_request['allowsNostr'] ) && preg_match( '/^[0-9a-f]{64}$/i', $nostr_pubkey ) ) {
				$nip57 = array( 'ok' => true, 'message' => __( 'NIP-57 zap receipts supported.', 'lawallet-lightning-address' ) );
			} else {
				$nip57 = array( 'ok' => false, 'message' => __( 'This Lightning Address does not advertise NIP-57 zap receipts.', 'lawallet-lightning-address' ) );
			}
		}

		wp_send_json_success(
			array(
				'address' => $address,
				'lud16'   => $lud16,
				'lud21'   => $lud21,
				'nip57'   => $nip57,
			)
		);
	}

	private static function verify_nwc_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage the NWC wallet.', 'lawallet-lightning-address' ) ), 403 );
		}
		check_ajax_referer( 'wcll_nwc_admin', 'nonce' );
	}

	public static function ajax_nwc_balance() {
		self::verify_nwc_admin();
		$balance = WCLL_NWC_Manager::get_cached_balance( WCLL_Gateway::get_gateway_settings(), true );
		wp_send_json_success(
			array(
				'ok'   => ! empty( $balance['ok'] ),
				'sats' => isset( $balance['sats'] ) ? (int) $balance['sats'] : 0,
			)
		);
	}

	public static function ajax_nwc_receiver_balance() {
		self::verify_nwc_admin();
		$balance = WCLL_NWC_Manager::receiver_balance( WCLL_Gateway::get_gateway_settings(), true );
		wp_send_json_success(
			array(
				'ok'   => ! empty( $balance['ok'] ),
				'sats' => isset( $balance['sats'] ) ? (int) $balance['sats'] : 0,
			)
		);
	}

	/**
	 * Return the wallet's current NWC connection string (with secret) to the
	 * authenticated administrator who requested it.
	 *
	 * SECURITY: the secret is returned ONLY here, only to a manage_woocommerce
	 * user with a valid nonce, only on an explicit click — it is never pre-rendered
	 * into the page, localized to the browser, logged, or stored in order data.
	 * Caching is suppressed so no proxy retains the response.
	 */
	public static function ajax_nwc_connection() {
		self::verify_nwc_admin();
		$settings = WCLL_Gateway::get_gateway_settings();
		$uri      = WCLL_NWC_Manager::current_connection_uri( $settings );
		if ( '' === trim( $uri ) ) {
			wp_send_json_error( array( 'message' => __( 'No NWC wallet is configured yet.', 'lawallet-lightning-address' ) ), 404 );
		}
		nocache_headers();
		wp_send_json_success(
			array(
				'uri'  => $uri,
				'mode' => WCLL_NWC_Manager::mode( $settings ),
			)
		);
	}

	public static function ajax_nwc_receive() {
		self::verify_nwc_admin();
		$amount_sats = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		if ( $amount_sats < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Enter an amount in sats.', 'lawallet-lightning-address' ) ) );
		}

		$client = WCLL_NWC_Manager::get_active_client( WCLL_Gateway::get_gateway_settings() );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			wp_send_json_error( array( 'message' => is_wp_error( $client ) ? $client->get_error_message() : __( 'The NWC wallet is not available.', 'lawallet-lightning-address' ) ) );
		}

		/* translators: %d: amount in sats. */
		$description = sprintf( __( 'Top up %d sats', 'lawallet-lightning-address' ), $amount_sats );
		$invoice     = $client->make_invoice( $amount_sats * 1000, $description, HOUR_IN_SECONDS );
		if ( is_wp_error( $invoice ) ) {
			wp_send_json_error( array( 'message' => $invoice->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'invoice'       => isset( $invoice['invoice'] ) ? $invoice['invoice'] : '',
				'payment_hash'  => isset( $invoice['payment_hash'] ) ? (string) $invoice['payment_hash'] : '',
				'wallet_pubkey' => $client->wallet_pubkey(),
				'amount'        => $amount_sats,
			)
		);
	}

	/**
	 * Poll whether a wallet invoice (by payment hash) has settled. Used by the
	 * Receive panel to detect an incoming top-up and close the invoice section.
	 */
	public static function ajax_nwc_invoice_status() {
		self::verify_nwc_admin();
		$hash = isset( $_POST['payment_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		if ( ! preg_match( '/^[0-9a-f]{64}$/i', $hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing payment hash.', 'lawallet-lightning-address' ) ) );
		}

		$settings = WCLL_Gateway::get_gateway_settings();
		// Look the invoice up on the EXACT wallet it was minted on, by pubkey, so a
		// disposable wallet that has since rotated (active -> archive) is still found.
		$pubkey = isset( $_POST['wallet_pubkey'] ) ? sanitize_text_field( wp_unslash( $_POST['wallet_pubkey'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		$client = preg_match( '/^[0-9a-f]{64}$/i', $pubkey )
			? WCLL_NWC_Manager::client_for_pubkey( $settings, $pubkey )
			: WCLL_NWC_Manager::get_active_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			wp_send_json_error( array( 'message' => is_wp_error( $client ) ? $client->get_error_message() : __( 'The NWC wallet is not available.', 'lawallet-lightning-address' ) ) );
		}

		$look = $client->lookup_invoice( $hash );
		// A pending/not-found invoice is a normal "not yet" answer for the poll, not
		// an error to surface.
		$settled = ! is_wp_error( $look ) && ! empty( $look['settled'] );
		wp_send_json_success( array( 'settled' => (bool) $settled ) );
	}

	public static function ajax_nwc_withdraw() {
		self::verify_nwc_admin();
		$destination = isset( $_POST['destination'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['destination'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		$amount_sats = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().

		if ( '' === $destination ) {
			wp_send_json_error( array( 'message' => __( 'Enter a Lightning Address or BOLT11 invoice.', 'lawallet-lightning-address' ) ) );
		}

		$settings = WCLL_Gateway::get_gateway_settings();
		$client   = WCLL_NWC_Manager::get_active_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			wp_send_json_error( array( 'message' => is_wp_error( $client ) ? $client->get_error_message() : __( 'The NWC wallet is not available.', 'lawallet-lightning-address' ) ) );
		}

		if ( preg_match( '/^ln[a-z0-9]{20,}$/i', $destination ) ) {
			$payment = $client->pay_invoice( $destination, $amount_sats > 0 ? $amount_sats * 1000 : null );
		} elseif ( preg_match( '/^[^@\s]+@[^@\s]+$/', $destination ) ) {
			if ( $amount_sats < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Enter an amount in sats to send to a Lightning Address.', 'lawallet-lightning-address' ) ) );
			}
			$lnurl       = new WCLL_LNURL_Client( $settings );
			$pay_request = $lnurl->resolve_lightning_address( strtolower( $destination ) );
			if ( is_wp_error( $pay_request ) ) {
				wp_send_json_error( array( 'message' => $pay_request->get_error_message() ) );
			}
			$invoice = $lnurl->request_invoice(
				$pay_request,
				$amount_sats * 1000,
				array(
					'description' => __( 'NWC wallet withdrawal', 'lawallet-lightning-address' ),
					'use_nostr'   => false,
				)
			);
			if ( is_wp_error( $invoice ) ) {
				wp_send_json_error( array( 'message' => $invoice->get_error_message() ) );
			}
			if ( empty( $invoice['pr'] ) ) {
				wp_send_json_error( array( 'message' => __( 'The Lightning Address did not return an invoice.', 'lawallet-lightning-address' ) ) );
			}
			$payment = $client->pay_invoice( $invoice['pr'] );
		} else {
			wp_send_json_error( array( 'message' => __( 'Enter a valid Lightning Address or BOLT11 invoice.', 'lawallet-lightning-address' ) ) );
		}

		if ( is_wp_error( $payment ) ) {
			wp_send_json_error( array( 'message' => $payment->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'preimage' => isset( $payment['preimage'] ) ? $payment['preimage'] : '',
				'fees'     => isset( $payment['fees_paid'] ) ? (int) $payment['fees_paid'] : 0,
			)
		);
	}

	public static function ajax_nwc_regenerate() {
		self::verify_nwc_admin();

		$url = isset( $_POST['lncurl_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['lncurl_url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid lncurl service URL.', 'lawallet-lightning-address' ) ) );
		}

		$settings = WCLL_Gateway::get_gateway_settings();
		if ( 'disposable' !== WCLL_NWC_Manager::mode( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Switch to Disposable mode to regenerate the wallet.', 'lawallet-lightning-address' ) ) );
		}

		// Adopt the new service URL (persist it) and mint a fresh wallet from it.
		$settings['nwc_lncurl_url'] = $url;
		update_option( 'woocommerce_wcll_gateway_settings', $settings );

		$client = WCLL_NWC_Manager::mint_and_store( $settings );
		if ( is_wp_error( $client ) ) {
			wp_send_json_error( array( 'message' => $client->get_error_message() ) );
		}

		$balance = WCLL_NWC_Manager::get_cached_balance( WCLL_Gateway::get_gateway_settings(), true );
		wp_send_json_success(
			array(
				'ok'   => ! empty( $balance['ok'] ),
				'sats' => isset( $balance['sats'] ) ? (int) $balance['sats'] : 0,
			)
		);
	}

	/**
	 * Mint a fresh disposable proxy wallet on demand (archiving the current one).
	 */
	public static function ajax_nwc_create_disposable() {
		self::verify_nwc_admin();
		$settings = WCLL_Gateway::get_gateway_settings();
		if ( ! WCLL_NWC_Manager::is_proxy( $settings ) || 'disposable' !== WCLL_NWC_Manager::mode( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Disposable wallets are only available in NWC Proxy mode with the disposable wallet mode.', 'lawallet-lightning-address' ) ), 400 );
		}

		$client = WCLL_NWC_Manager::mint_and_store( $settings );
		if ( is_wp_error( $client ) ) {
			wp_send_json_error( array( 'message' => $client->get_error_message() ) );
		}

		$balance = WCLL_NWC_Manager::get_cached_balance( $settings, true );
		wp_send_json_success(
			array(
				'ok'   => ! empty( $balance['ok'] ),
				'sats' => isset( $balance['sats'] ) ? (int) $balance['sats'] : 0,
			)
		);
	}

	/**
	 * Paginated ledger of NWC proxy activity, newest first: inbound orders
	 * (settled into the proxy wallet) merged with outbound sweeps (payouts to the
	 * merchant Lightning Address). Each item carries a 'type' ('order'|'sweep').
	 *
	 * Sweeps are capped at SWEEP_LOG_MAX; orders are fetched to the requested page
	 * depth, so the two sources merge correctly for any page.
	 *
	 * @return array{items:array,total:int,pages:int,page:int}
	 */
	public static function nwc_transactions( $page = 1, $per_page = 8 ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );
		$result   = array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
			'page'  => $page,
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $result;
		}

		// Inbound: proxy (forwarding) orders only. Terminal NWC orders
		// ('_wcll_nwc_forward' = 'no') keep funds in the merchant's own wallet and
		// belong on the normal Orders screen. Legacy proxy orders (pre-flag) have no
		// '_wcll_nwc_forward' meta, so NOT EXISTS keeps them visible. Fetch enough
		// recent orders to cover this page once merged with the sweeps.
		$query = wc_get_orders(
			array(
				'limit'      => $page * $per_page,
				'paged'      => 1,
				'paginate'   => true,
				// phpcs:disable WordPress.DB.SlowDBQuery -- Bounded admin list; wc_get_orders is HPOS-aware.
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_wcll_settlement_method',
						'value' => 'nwc',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => '_wcll_nwc_forward',
							'value' => 'yes',
						),
						array(
							'key'     => '_wcll_nwc_forward',
							'compare' => 'NOT EXISTS',
						),
					),
				),
				// phpcs:enable WordPress.DB.SlowDBQuery
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);

		$entries = array();
		foreach ( $query->orders as $order ) {
			$entries[] = self::nwc_tx_row( $order );
		}

		// Outbound: completed sweeps (payouts to the merchant Lightning Address).
		$sweeps = self::nwc_sweeps( self::SWEEP_LOG_MAX );
		foreach ( $sweeps as $sweep ) {
			$entries[] = self::nwc_sweep_row( $sweep );
		}

		// Merge into one chronological ledger, newest first.
		usort(
			$entries,
			static function ( $a, $b ) {
				return ( isset( $b['at'] ) ? (int) $b['at'] : 0 ) <=> ( isset( $a['at'] ) ? (int) $a['at'] : 0 );
			}
		);

		$total           = (int) $query->total + count( $sweeps );
		$result['total'] = $total;
		$result['pages'] = (int) ceil( $total / $per_page );
		$result['items'] = array_slice( $entries, ( $page - 1 ) * $per_page, $per_page );
		return $result;
	}

	private static function nwc_tx_row( WC_Order $order ) {
		$amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
		$forwarded   = (string) $order->get_meta( '_wcll_nwc_forwarded', true );
		$received    = $order->is_paid();

		// Forwarding is pooled now (a periodic wallet sweep), so a received order
		// shows "pooled". Legacy per-order results stay truthful for old orders.
		if ( 'yes' === $forwarded ) {
			$forward = 'forwarded';
		} elseif ( 'failed' === $forwarded ) {
			$forward = 'failed';
		} elseif ( $received ) {
			$forward = 'pooled';
		} else {
			$forward = 'none';
		}

		$date = $order->get_date_created();
		$at   = $date ? $date->getTimestamp() : 0;

		return array(
			'type'     => 'order',
			'at'       => $at,
			'order_id' => $order->get_id(),
			'order'    => $order->get_order_number(),
			'url'      => $order->get_edit_order_url(),
			'date'     => self::ledger_datetime( $at ),
			'amount'   => (int) round( $amount_msat / 1000 ),
			'received' => $received,
			'forward'  => $forward,
			'fees'     => (int) $order->get_meta( '_wcll_nwc_forward_fees', true ),
			'dest'     => (string) $order->get_meta( '_wcll_lightning_address', true ),
		);
	}

	/**
	 * Normalize a sweep-log entry into a ledger row (an outbound payout).
	 */
	private static function nwc_sweep_row( array $sweep ) {
		$at = isset( $sweep['at'] ) ? (int) $sweep['at'] : 0;
		return array(
			'type'   => 'sweep',
			'at'     => $at,
			'date'   => self::ledger_datetime( $at ),
			'amount' => (int) round( ( isset( $sweep['amount'] ) ? (int) $sweep['amount'] : 0 ) / 1000 ),
			'fees'   => isset( $sweep['fees'] ) ? (int) $sweep['fees'] : 0,
			'dest'   => isset( $sweep['address'] ) ? (string) $sweep['address'] : '',
		);
	}

	private static function ledger_datetime( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( ! $timestamp ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public static function ajax_nwc_transactions() {
		self::verify_nwc_admin();
		$page     = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		$per_page = isset( $_POST['per_page'] ) ? min( 50, max( 1, absint( wp_unslash( $_POST['per_page'] ) ) ) ) : 10; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		$data     = self::nwc_transactions( $page, $per_page );

		$rows = '';
		foreach ( $data['items'] as $row ) {
			$rows .= self::nwc_tx_row_html( $row );
		}

		wp_send_json_success(
			array(
				'rows'  => $rows,
				'total' => $data['total'],
				'pages' => $data['pages'],
				'page'  => $data['page'],
			)
		);
	}

	/**
	 * Render one ledger entry as a fully-escaped table row. Handles both inbound
	 * order rows and outbound sweep rows, so the markup is defined once and shared
	 * by the inline list and the (AJAX-fed) modal.
	 */
	public static function nwc_tx_row_html( array $row ) {
		$is_sweep = isset( $row['type'] ) && 'sweep' === $row['type'];
		$dash     = '<span aria-hidden="true">&mdash;</span>';

		// Origin: a linked #order for inbound payments, or a "Sweep" badge.
		if ( $is_sweep ) {
			$origin_cell = '<span class="wcll-tx-origin is-sweep">' . esc_html__( 'Sweep', 'lawallet-lightning-address' ) . '</span>';
		} else {
			$origin_cell = ! empty( $row['url'] )
				? '<a href="' . esc_url( $row['url'] ) . '">#' . esc_html( isset( $row['order'] ) ? $row['order'] : '' ) . '</a>'
				: '#' . esc_html( isset( $row['order'] ) ? $row['order'] : '' );
		}

		// Routing fee (sats) — per-order for legacy forwards, the sweep fee for
		// sweeps; pooled orders carry no per-order fee (it is on their sweep).
		$fees_msat = isset( $row['fees'] ) ? (int) $row['fees'] : 0;
		$fee_cell  = $fees_msat > 0
			? esc_html( number_format_i18n( (int) round( $fees_msat / 1000 ) ) . ' ' . __( 'sats', 'lawallet-lightning-address' ) )
			: $dash;

		if ( $is_sweep ) {
			// A sweep is an outbound payout: nothing "received", the forward IS it.
			$received_cell = $dash;
			$forward_cell  = '<span class="wcll-tx-status is-forwarded">' . esc_html__( 'Paid out', 'lawallet-lightning-address' ) . '</span>';
			if ( ! empty( $row['dest'] ) ) {
				/* translators: %s: merchant Lightning Address. */
				$forward_cell .= '<br /><span class="wcll-tx-dest">' . esc_html( sprintf( __( 'to %s', 'lawallet-lightning-address' ), $row['dest'] ) ) . '</span>';
			}
			$detail_cell = $dash;
		} else {
			$received_cell = ! empty( $row['received'] )
				? '<span class="wcll-tx-status is-received">' . esc_html__( 'Received', 'lawallet-lightning-address' ) . '</span>'
				: '<span class="wcll-tx-status is-unpaid">' . esc_html__( 'Awaiting payment', 'lawallet-lightning-address' ) . '</span>';

			$labels        = array(
				'forwarded' => __( 'Forwarded', 'lawallet-lightning-address' ),
				'pending'   => __( 'Pending', 'lawallet-lightning-address' ),
				'failed'    => __( 'Failed', 'lawallet-lightning-address' ),
				'pooled'    => __( 'Pooled', 'lawallet-lightning-address' ),
				'none'      => __( 'Not forwarded', 'lawallet-lightning-address' ),
			);
			$forward       = isset( $row['forward'] ) ? (string) $row['forward'] : 'none';
			$forward_label = isset( $labels[ $forward ] ) ? $labels[ $forward ] : $labels['none'];
			$forward_cell  = '<span class="wcll-tx-status is-' . esc_attr( sanitize_html_class( $forward ) ) . '">' . esc_html( $forward_label ) . '</span>';
			if ( ( 'forwarded' === $forward || 'pooled' === $forward ) && ! empty( $row['dest'] ) ) {
				/* translators: %s: merchant Lightning Address. */
				$forward_cell .= '<br /><span class="wcll-tx-dest">' . esc_html( sprintf( __( 'to %s', 'lawallet-lightning-address' ), $row['dest'] ) ) . '</span>';
			}
			$detail_cell = '<button type="button" class="button button-small wcll-tx-show" data-wcll-tx-show="' . esc_attr( (int) $row['order_id'] ) . '">' . esc_html__( 'Show', 'lawallet-lightning-address' ) . '</button>';
		}

		$html  = '<tr>';
		$html .= '<td>' . $origin_cell . '</td>';
		$html .= '<td>' . esc_html( $row['date'] ) . '</td>';
		$html .= '<td>' . esc_html( number_format_i18n( (int) ( isset( $row['amount'] ) ? $row['amount'] : 0 ) ) ) . ' ' . esc_html__( 'sats', 'lawallet-lightning-address' ) . '</td>';
		$html .= '<td>' . $received_cell . '</td>';
		$html .= '<td>' . $forward_cell . '</td>';
		$html .= '<td>' . $fee_cell . '</td>';
		$html .= '<td>' . $detail_cell . '</td>';
		$html .= '</tr>';
		return $html;
	}

	public static function ajax_nwc_transaction() {
		self::verify_nwc_admin();
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in verify_nwc_admin().
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		// Proxy panel: only forwarding orders are listed/viewable here.
		if ( ! $order
			|| 'nwc' !== (string) $order->get_meta( '_wcll_settlement_method', true )
			|| ! self::order_forwards( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'lawallet-lightning-address' ) ) );
		}
		wp_send_json_success( array( 'html' => self::nwc_transaction_detail_html( $order ) ) );
	}

	private static function txd_field( $label, $value_html ) {
		return '<dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd>';
	}

	private static function txd_sats( $msat ) {
		return esc_html( number_format_i18n( (int) round( (int) $msat / 1000 ) ) ) . ' ' . esc_html__( 'sats', 'lawallet-lightning-address' );
	}

	private static function txd_mono( $value ) {
		if ( '' === $value ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}
		$short = strlen( $value ) > 28 ? substr( $value, 0, 18 ) . '…' : $value;
		return '<code class="wcll-txd-mono" title="' . esc_attr( $value ) . '">' . esc_html( $short ) . '</code> '
			. '<button type="button" class="button-link wcll-tx-copy" data-wcll-tx-copy="' . esc_attr( $value ) . '">' . esc_html__( 'Copy', 'lawallet-lightning-address' ) . '</button>';
	}

	/**
	 * Fully-escaped detail view for one NWC transaction: the received (proxy)
	 * invoice and the sent (forward) invoice.
	 */
	public static function nwc_transaction_detail_html( WC_Order $order ) {
		$amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
		$received    = $order->is_paid();
		$date        = $order->get_date_created();

		$html  = '<div class="wcll-txd">';
		/* translators: 1: order number, 2: order date. */
		$html .= '<h3 class="wcll-txd-order">' . esc_html( sprintf( __( 'Order #%1$s — %2$s', 'lawallet-lightning-address' ), $order->get_order_number(), $date ? wc_format_datetime( $date ) : '' ) ) . '</h3>';

		$html .= '<div class="wcll-txd-section"><h4>' . esc_html__( 'Received (NWC wallet)', 'lawallet-lightning-address' ) . '</h4><dl class="wcll-txd-list">';
		$html .= self::txd_field(
			__( 'Status', 'lawallet-lightning-address' ),
			$received
				? '<span class="wcll-tx-status is-received">' . esc_html__( 'Received', 'lawallet-lightning-address' ) . '</span>'
				: '<span class="wcll-tx-status is-unpaid">' . esc_html__( 'Awaiting payment', 'lawallet-lightning-address' ) . '</span>'
		);
		$html .= self::txd_field( __( 'Amount', 'lawallet-lightning-address' ), self::txd_sats( $amount_msat ) );
		$html .= self::txd_field( __( 'Payment hash', 'lawallet-lightning-address' ), self::txd_mono( (string) $order->get_meta( '_wcll_payment_hash', true ) ) );
		$html .= self::txd_field( __( 'Preimage', 'lawallet-lightning-address' ), self::txd_mono( (string) $order->get_meta( '_wcll_preimage', true ) ) );
		$html .= self::txd_field( __( 'Invoice', 'lawallet-lightning-address' ), self::txd_mono( (string) $order->get_meta( '_wcll_invoice', true ) ) );
		$html .= '</dl></div>';

		// Forwarding is pooled, not per-order: received funds accumulate in the
		// managed wallet and are swept to the merchant Lightning Address in batches
		// (a routing-fee reserve is kept behind). Terminal NWC orders keep the funds.
		if ( self::order_forwards( $order ) ) {
			$html .= '<div class="wcll-txd-section"><h4>' . esc_html__( 'Forwarding', 'lawallet-lightning-address' ) . '</h4><dl class="wcll-txd-list">';
			$html .= self::txd_field( __( 'Payout', 'lawallet-lightning-address' ), esc_html__( 'Pooled — received funds are swept to the merchant Lightning Address in batches.', 'lawallet-lightning-address' ) );
			$html .= self::txd_field( __( 'To', 'lawallet-lightning-address' ), esc_html( (string) $order->get_meta( '_wcll_lightning_address', true ) ) );
			$html .= '</dl></div>';
		}

		$html .= '</div>';
		return $html;
	}

	public static function declare_woocommerce_features() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCLL_PLUGIN_FILE, true );
		}
	}

	public static function activate() {
		set_transient( 'wcll_activation_redirect', 1, MINUTE_IN_SECONDS );
		WCLL_Discovery::activate();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wcll_every_minute', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( 'wcll_activation_redirect' );
		WCLL_Discovery::deactivate();
	}

	public static function activation_redirect() {
		if ( ! get_transient( 'wcll_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'wcll_activation_redirect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check to skip the redirect during bulk activation.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( current_user_can( 'manage_woocommerce' ) && class_exists( 'WooCommerce' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wcll_gateway&wcll_setup=1' ) );
			exit;
		}
	}

	public static function add_gateway( $methods ) {
		$methods[] = 'WCLL_Gateway';
		return $methods;
	}

	public static function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=lawallet-lightning-address' ) ),
			esc_html__( 'Settings', 'lawallet-lightning-address' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules['wcll_every_minute'] ) ) {
			$schedules['wcll_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute', 'lawallet-lightning-address' ),
			);
		}

		return $schedules;
	}

	public static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wcll_every_minute', self::CRON_HOOK );
		}
	}

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		foreach ( WCLL_NWC_Manager::take_admin_notices() as $notice ) {
			if ( ! empty( $notice['message'] ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			}
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Accept Bitcoin with your Lightning Address needs WooCommerce active for checkout payments. Lightning Address discovery can still be configured from Settings -> LaWallet.', 'lawallet-lightning-address' ) . '</p></div>';
			return;
		}

		$settings = get_option( 'woocommerce_wcll_gateway_settings', array() );
		if ( empty( $settings['lightning_address'] ) ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wcll_gateway&wcll_setup=1' );
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Lightning payments need a merchant Lightning Address before checkout can accept payments.', 'lawallet-lightning-address' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Set Lightning Address', 'lawallet-lightning-address' ) . '</a>';
			echo '</p></div>';
		}
	}

	public static function render_payment_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'wcll_gateway' !== $order->get_payment_method() ) {
			return;
		}

		$invoice = $order->get_meta( '_wcll_invoice', true );
		if ( empty( $invoice ) ) {
			echo '<p>' . esc_html__( 'Lightning invoice is not available for this order.', 'lawallet-lightning-address' ) . '</p>';
			return;
		}

		wp_enqueue_style( 'wcll-checkout', WCLL_PLUGIN_URL . 'assets/css/checkout.css', array(), WCLL_VERSION );
		wp_enqueue_script( 'wcll-qrcode', WCLL_PLUGIN_URL . 'assets/js/qrcode.min.js', array(), WCLL_VERSION, true );
		wp_enqueue_script( 'wcll-checkout', WCLL_PLUGIN_URL . 'assets/js/checkout.js', array( 'wcll-qrcode' ), WCLL_VERSION, true );

		// The invoice/watch fields are identical to the re-issue endpoint's
		// payload, so reuse it and add only the page-session fields here.
		$params = array_merge(
			self::invoice_payload( $order ),
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'orderId'       => $order->get_id(),
				'orderKey'      => $order->get_order_key(),
				'nonce'         => wp_create_nonce( self::nonce_action( $order ) ),
				'paymentStatus' => $order->get_meta( '_wcll_status', true ),
				'isPaid'        => $order->is_paid(),
				'i18n'          => array(
					'waiting'       => __( 'Waiting for payment', 'lawallet-lightning-address' ),
					'checking'      => __( 'Checking settlement', 'lawallet-lightning-address' ),
					'paid'          => __( 'Payment received', 'lawallet-lightning-address' ),
					'expired'       => __( 'Invoice expired', 'lawallet-lightning-address' ),
					'copy'          => __( 'Copy invoice', 'lawallet-lightning-address' ),
					'copied'        => __( 'Copied', 'lawallet-lightning-address' ),
					'payWebln'      => __( 'Pay with WebLN', 'lawallet-lightning-address' ),
					'webLnPaying'   => __( 'Opening WebLN', 'lawallet-lightning-address' ),
					'webLnChecking' => __( 'Checking payment', 'lawallet-lightning-address' ),
				),
			)
		);

		wp_add_inline_script( 'wcll-checkout', 'window.WCLLPayment = ' . wp_json_encode( $params ) . ';', 'before' );

		include WCLL_PLUGIN_DIR . 'templates/payment-page.php';
	}

	public static function redirect_paid_order_pay_to_order_received() {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public order-pay page; the order key compared with hash_equals() is the access token.
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order     = wc_get_order( $order_id );
		if ( ! $order || 'wcll_gateway' !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), $order_key ) || ! $order->is_paid() ) {
			return;
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	public static function ajax_claim_payment() {
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$order     = wc_get_order( $order_id );

		if ( ! $order || 'wcll_gateway' !== $order->get_payment_method() || $order->get_order_key() !== $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'lawallet-lightning-address' ) ), 404 );
		}

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $order ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment session.', 'lawallet-lightning-address' ) ), 403 );
		}

		$result = self::claim_order_payment( $order );
		wp_send_json_success( $result );
	}

	/**
	 * Re-issue a Lightning invoice for an order whose previous one expired. The
	 * payment is ALWAYS verified first: if the expired invoice was in fact paid,
	 * the order is settled and no new invoice is minted. Otherwise a fresh invoice
	 * is created for the same amount and the order is revived to "pending".
	 */
	public static function ajax_recreate_invoice() {
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$order     = wc_get_order( $order_id );

		if ( ! $order || 'wcll_gateway' !== $order->get_payment_method() || $order->get_order_key() !== $order_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'lawallet-lightning-address' ) ), 404 );
		}

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $order ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment session.', 'lawallet-lightning-address' ) ), 403 );
		}

		// Verify the (expired) invoice was not actually paid BEFORE minting a new
		// one. cancel_on_expiry = false so a present customer's order is re-issued
		// rather than cancelled when it is past expiry.
		$check = self::claim_order_payment( $order, false, false );
		if ( ! empty( $check['paid'] ) ) {
			wp_send_json_success(
				array(
					'paid'      => true,
					'returnUrl' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// Re-issue for the same amount/rate the customer already agreed to.
		$amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
		$rate        = $order->get_meta( '_wcll_rate', true );
		if ( ! is_array( $rate ) ) {
			$rate = array();
		}
		if ( $amount_msat < 1 ) {
			$calc = WCLL_Rates::calculate_order_amount( $order, WCLL_Gateway::get_gateway_settings() );
			if ( is_wp_error( $calc ) ) {
				wp_send_json_error( array( 'message' => $calc->get_error_message() ) );
			}
			$amount_msat = (int) $calc['amount_msat'];
			$rate        = $calc['rate'];
		}

		$gateway = self::gateway_instance();
		if ( ! ( $gateway instanceof WCLL_Gateway ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway unavailable.', 'lawallet-lightning-address' ) ) );
		}

		$issued = $gateway->issue_invoice_for_order( $order, $amount_msat, $rate );
		if ( is_wp_error( $issued ) ) {
			wp_send_json_error( array( 'message' => $issued->get_error_message() ) );
		}

		// Reload to read the freshly stored invoice/watch metadata.
		$fresh = wc_get_order( $order->get_id() );
		wp_send_json_success( self::invoice_payload( $fresh instanceof WC_Order ? $fresh : $order ) );
	}

	/**
	 * The invoice-specific fields the checkout frontend needs to swap in a newly
	 * issued invoice (QR, countdown, and the relays/pubkeys it re-watches).
	 */
	private static function invoice_payload( WC_Order $order ) {
		return array(
			'paid'            => false,
			'invoice'         => (string) $order->get_meta( '_wcll_invoice', true ),
			'expiresAt'       => (int) $order->get_meta( '_wcll_expires_at', true ),
			'nostrPubkey'     => (string) $order->get_meta( '_wcll_nostr_pubkey', true ),
			'nostrRelays'     => self::sanitize_ws_relays( $order->get_meta( '_wcll_nostr_relays', true ) ),
			'nwcWalletPubkey' => (string) $order->get_meta( '_wcll_nwc_wallet_pubkey', true ),
			'nwcClientPubkey' => (string) $order->get_meta( '_wcll_nwc_client_pubkey', true ),
			'nwcRelays'       => self::sanitize_ws_relays( $order->get_meta( '_wcll_nwc_relays', true ) ),
			'returnUrl'       => $order->get_checkout_order_received_url(),
		);
	}

	private static function gateway_instance() {
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways['wcll_gateway'] ) && $gateways['wcll_gateway'] instanceof WCLL_Gateway ) {
				return $gateways['wcll_gateway'];
			}
		}
		return class_exists( 'WCLL_Gateway' ) ? new WCLL_Gateway() : null;
	}

	public static function check_pending_payments() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 50,
				'status'         => array( 'pending', 'on-hold' ),
				'payment_method' => 'wcll_gateway',
				'meta_key'       => '_wcll_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded lookup (limit 50) of pending Lightning orders; WooCommerce maps it to an HPOS-aware query.
				'meta_value'     => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		foreach ( $orders as $order ) {
			self::claim_order_payment( $order, true );
		}

		// Sweep the proxy wallet balance (minus the routing reserve) to the
		// merchant Lightning Address. Pooled across all received orders, so tiny
		// orders accumulate and ship out together.
		self::sweep_proxy_wallets();

		// Keep a live disposable wallet ready so the next order never lands on a
		// wallet that lncurl has reaped.
		WCLL_NWC_Manager::ensure_live_active( WCLL_Gateway::get_gateway_settings() );
	}

	public static function claim_order_payment( WC_Order $order, $from_cron = false, $cancel_on_expiry = true ) {
		if ( $order->is_paid() ) {
			return self::payment_response( $order, 'paid' );
		}

		$invoice    = $order->get_meta( '_wcll_invoice', true );
		$expires_at = (int) $order->get_meta( '_wcll_expires_at', true );

		if ( empty( $invoice ) ) {
			return self::payment_response( $order, 'missing' );
		}

		$check = self::verify_order_settlement( $order, $invoice );

		if ( is_wp_error( $check ) ) {
			if ( $cancel_on_expiry && $from_cron && $expires_at && time() > $expires_at ) {
				self::cancel_expired_order( $order, $check->get_error_message() );
				return self::payment_response( $order, 'expired' );
			}

			return array(
				'status'    => 'pending',
				'paid'      => false,
				'expired'   => false,
				'message'   => $check->get_error_message(),
				'returnUrl' => $order->get_checkout_order_received_url(),
			);
		}

		if ( ! empty( $check['settled'] ) ) {
			$method = isset( $check['method'] ) ? (string) $check['method'] : '';
			$order->update_meta_data( '_wcll_status', 'paid' );
			if ( ! empty( $check['preimage'] ) ) {
				$order->update_meta_data( '_wcll_preimage', sanitize_text_field( $check['preimage'] ) );
			}
			$order->save();

			$order->payment_complete();
			$order->add_order_note( self::settlement_note( $method ) );

			// Proxy forwarding is pooled, not per-order: the received funds now sit
			// in the managed wallet and are paid out to the merchant Lightning
			// Address by sweep_proxy_wallets() on the next cron run.

			return self::payment_response( $order, 'paid' );
		}

		if ( $expires_at && time() > $expires_at ) {
			if ( $cancel_on_expiry ) {
				self::cancel_expired_order( $order );
			}
			return self::payment_response( $order, 'expired' );
		}

		return self::payment_response( $order, 'pending' );
	}

	/**
	 * Whether a settled NWC order should be forwarded to a Lightning Address.
	 * Reads the per-order flag snapshotted at checkout; for orders created before
	 * the flag existed (pre-0.5.0) it falls back to "forward when an address was
	 * stored", preserving the old always-forward behaviour for in-flight orders.
	 */
	private static function order_forwards( WC_Order $order ) {
		$flag = (string) $order->get_meta( '_wcll_nwc_forward', true );
		if ( 'yes' === $flag ) {
			return true;
		}
		if ( 'no' === $flag ) {
			return false;
		}
		return '' !== trim( (string) $order->get_meta( '_wcll_lightning_address', true ) );
	}

	private static function verify_order_settlement( WC_Order $order, $invoice ) {
		// NWC proxy orders are confirmed by looking the proxy invoice up over the
		// wallet connection, not by LUD-21/NIP-57.
		if ( 'nwc' === (string) $order->get_meta( '_wcll_settlement_method', true ) ) {
			return self::verify_nwc_settlement( $order );
		}

		$client     = new WCLL_LNURL_Client( WCLL_Gateway::get_gateway_settings() );
		$verify_url = $order->get_meta( '_wcll_verify_url', true );

		// Prefer LUD-21 when the invoice exposes a verify URL.
		if ( ! empty( $verify_url ) ) {
			$result = $client->verify_invoice( $verify_url, $invoice );
			if ( ! is_wp_error( $result ) ) {
				$result['method'] = 'lud21';
			}
			return $result;
		}

		// Fallback: confirm settlement from a NIP-57 zap receipt on the relays
		// that were provided when the invoice was generated.
		$relays = $order->get_meta( '_wcll_nostr_relays', true );
		$author = (string) $order->get_meta( '_wcll_nostr_pubkey', true );
		if ( ! is_array( $relays ) || empty( $relays ) || '' === $author ) {
			return new WP_Error( 'wcll_no_verification', __( 'This invoice has no LUD-21 verify URL or NIP-57 relays to confirm settlement.', 'lawallet-lightning-address' ) );
		}

		$created = $order->get_date_created();
		$since   = $created ? ( $created->getTimestamp() - HOUR_IN_SECONDS ) : 0;

		$result = WCLL_Nostr_Relay::fetch_zap_receipt( $relays, $invoice, $author, $since );
		if ( ! is_wp_error( $result ) ) {
			$result['method'] = 'nip57';
		}
		return $result;
	}

	private static function verify_nwc_settlement( WC_Order $order ) {
		$payment_hash = (string) $order->get_meta( '_wcll_payment_hash', true );
		if ( '' === $payment_hash ) {
			return new WP_Error( 'wcll_nwc_no_hash', __( 'This NWC order has no payment hash to verify.', 'lawallet-lightning-address' ) );
		}

		$nwc = self::build_nwc_client_for_order( $order );
		if ( is_wp_error( $nwc ) ) {
			return $nwc;
		}

		$lookup = $nwc->lookup_invoice( $payment_hash );
		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		return array(
			'settled'  => ! empty( $lookup['settled'] ),
			'preimage' => isset( $lookup['preimage'] ) ? (string) $lookup['preimage'] : '',
			'payload'  => isset( $lookup['raw'] ) ? $lookup['raw'] : array(),
			'method'   => 'nwc',
		);
	}

	/**
	 * Rebuild the NWC client for an order against the EXACT wallet it was invoiced
	 * on (resolved by stored pubkey through the manager's active/archive store), so
	 * a rotated-in replacement never settles or receives another order's funds.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	private static function build_nwc_client_for_order( WC_Order $order ) {
		return WCLL_NWC_Manager::client_for_order( $order, WCLL_Gateway::get_gateway_settings() );
	}

	/**
	 * Sweep the proxy wallet balance to the merchant Lightning Address.
	 *
	 * Forwarding is pooled, not per-order: received payments accumulate in the
	 * managed proxy wallet, and this routine (run from cron) pays out everything
	 * above a routing-fee reserve. Only the wallet currently in use is swept —
	 * archived wallets are retired because they died and can no longer be reached.
	 */
	public static function sweep_proxy_wallets() {
		$settings = WCLL_Gateway::get_gateway_settings();
		if ( ! WCLL_NWC_Manager::is_proxy( $settings ) ) {
			return;
		}

		$address = isset( $settings['lightning_address'] ) ? trim( (string) $settings['lightning_address'] ) : '';
		if ( '' === $address ) {
			return;
		}

		$uri = WCLL_NWC_Manager::current_connection_uri( $settings );
		if ( '' === $uri ) {
			return;
		}
		$client = WCLL_NWC_Client::from_uri( $uri );
		if ( is_wp_error( $client ) ) {
			return;
		}

		// Resolve the destination once; its sendable bounds apply to every hop.
		$lnurl       = new WCLL_LNURL_Client( $settings );
		$pay_request = $lnurl->resolve_lightning_address( $address );
		if ( is_wp_error( $pay_request ) ) {
			return;
		}

		self::sweep_wallet_to_address( $client, $lnurl, $pay_request, $address );
	}

	/**
	 * Drain one wallet down to the reserve floor, one Lightning-Address payment at
	 * a time. Idempotent: the in-flight forward invoice is persisted before paying
	 * and reconciled with lookup_invoice on the next run, so a lost NWC response
	 * never pays twice. A remainder below the reserve floor (or below the
	 * address's minSendable) is left in the wallet for a later, larger sweep.
	 */
	private static function sweep_wallet_to_address( WCLL_NWC_Client $client, WCLL_LNURL_Client $lnurl, array $pay_request, $address ) {
		$pubkey   = $client->wallet_pubkey();
		$lock_key = 'wcll_nwc_sweep_' . $pubkey;
		if ( false !== get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

		$min = isset( $pay_request['minSendable'] ) ? (int) $pay_request['minSendable'] : 0;
		$max = isset( $pay_request['maxSendable'] ) ? (int) $pay_request['maxSendable'] : 0;

		try {
			for ( $hop = 0; $hop < self::SWEEP_MAX_HOPS; $hop++ ) {
				// 1. Reconcile a forward invoice left in flight by a previous run
				//    before minting a new one, so a lost response is never paid twice.
				$inflight = self::sweep_get_inflight( $pubkey );
				if ( null !== $inflight ) {
					$look = $client->lookup_invoice( '', $inflight['invoice'] );
					if ( is_wp_error( $look ) ) {
						return; // Cannot confirm; leave it and retry on the next run.
					}
					if ( ! empty( $look['settled'] ) ) {
						self::sweep_record( $pubkey, $address, (int) $inflight['amount'], isset( $look['preimage'] ) ? $look['preimage'] : '', self::lookup_fees( $look ) );
					}
					// Settled, or definitively unpaid (stale/expired): clear either way.
					self::sweep_clear_inflight( $pubkey );
				}

				// 2. Decide how much can ship right now.
				$balance = $client->get_balance();
				if ( is_wp_error( $balance ) ) {
					return;
				}
				$balance_msat = isset( $balance['balance'] ) ? (int) $balance['balance'] : 0;
				$forwardable  = $balance_msat - self::forward_reserve_msat( $balance_msat );
				if ( $forwardable <= 0 ) {
					return; // At or below the reserve floor: nothing left to forward.
				}

				$amount = ( $max > 0 && $forwardable > $max ) ? $max : $forwardable;
				if ( $amount < $min ) {
					return; // Remainder is below the address minimum; wait for more funds.
				}

				// 3. Mint the forward invoice and persist it BEFORE paying.
				$invoice = $lnurl->request_invoice(
					$pay_request,
					$amount,
					array(
						'comment'   => __( 'LaWallet WooCommerce proxy sweep', 'lawallet-lightning-address' ),
						'use_nostr' => false,
					)
				);
				if ( is_wp_error( $invoice ) || empty( $invoice['pr'] ) ) {
					return;
				}
				$bolt11 = (string) $invoice['pr'];
				self::sweep_set_inflight( $pubkey, $bolt11, $amount );

				// 4. Pay it from the proxy wallet.
				$payment = $client->pay_invoice( $bolt11 );
				if ( is_wp_error( $payment ) ) {
					// The pay may have settled with its response lost — confirm before
					// giving up so the next run never double-pays.
					$look = $client->lookup_invoice( '', $bolt11 );
					if ( ! is_wp_error( $look ) && ! empty( $look['settled'] ) ) {
						self::sweep_record( $pubkey, $address, $amount, isset( $look['preimage'] ) ? $look['preimage'] : '', self::lookup_fees( $look ) );
						self::sweep_clear_inflight( $pubkey );
						continue;
					}
					if ( ! is_wp_error( $look ) ) {
						self::sweep_clear_inflight( $pubkey ); // Definitively unpaid; drop the stale invoice.
					}
					return; // Retry on the next cron run.
				}

				self::sweep_record( $pubkey, $address, $amount, isset( $payment['preimage'] ) ? $payment['preimage'] : '', isset( $payment['fees_paid'] ) ? (int) $payment['fees_paid'] : 0 );
				self::sweep_clear_inflight( $pubkey );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	private static function sweep_get_inflight( $pubkey ) {
		$all = get_option( self::SWEEP_INFLIGHT_OPTION, array() );
		$key = strtolower( (string) $pubkey );
		return ( is_array( $all ) && isset( $all[ $key ]['invoice'] ) ) ? $all[ $key ] : null;
	}

	private static function sweep_set_inflight( $pubkey, $invoice, $amount_msat ) {
		$all = get_option( self::SWEEP_INFLIGHT_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ strtolower( (string) $pubkey ) ] = array(
			'invoice' => (string) $invoice,
			'amount'  => (int) $amount_msat,
			'created' => time(),
		);
		update_option( self::SWEEP_INFLIGHT_OPTION, $all, false );
	}

	private static function sweep_clear_inflight( $pubkey ) {
		$all = get_option( self::SWEEP_INFLIGHT_OPTION, array() );
		if ( is_array( $all ) && isset( $all[ strtolower( (string) $pubkey ) ] ) ) {
			unset( $all[ strtolower( (string) $pubkey ) ] );
			update_option( self::SWEEP_INFLIGHT_OPTION, $all, false );
		}
	}

	/**
	 * Append a completed sweep to the rolling payout log (newest first, capped).
	 */
	private static function sweep_record( $pubkey, $address, $amount_msat, $preimage, $fees_msat ) {
		$log = get_option( self::SWEEP_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'wallet'   => strtolower( (string) $pubkey ),
				'address'  => (string) $address,
				'amount'   => (int) $amount_msat,
				'fees'     => (int) $fees_msat,
				'preimage' => (string) $preimage,
				'at'       => time(),
			)
		);
		if ( count( $log ) > self::SWEEP_LOG_MAX ) {
			$log = array_slice( $log, 0, self::SWEEP_LOG_MAX );
		}
		update_option( self::SWEEP_LOG_OPTION, $log, false );
	}

	/**
	 * Most recent proxy sweeps (payouts to the merchant address), newest first.
	 */
	public static function nwc_sweeps( $limit = 5 ) {
		$log = get_option( self::SWEEP_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_slice( $log, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Extract the routing fee (msat) from a normalized lookup_invoice result.
	 */
	private static function lookup_fees( array $look ) {
		return isset( $look['raw']['fees_paid'] ) ? (int) $look['raw']['fees_paid'] : 0;
	}

	private static function settlement_note( $method ) {
		if ( 'nip57' === $method ) {
			return __( 'Lightning payment verified via NIP-57 zap receipt.', 'lawallet-lightning-address' );
		}
		if ( 'nwc' === $method ) {
			return __( 'Lightning payment verified in the NWC wallet.', 'lawallet-lightning-address' );
		}
		return __( 'Lightning payment verified with LUD-21.', 'lawallet-lightning-address' );
	}

	private static function cancel_expired_order( WC_Order $order, $reason = '' ) {
		if ( $order->is_paid() || 'cancelled' === $order->get_status() ) {
			return;
		}

		$order->update_meta_data( '_wcll_status', 'expired' );
		$order->save();

		$note = __( 'Lightning invoice expired before settlement was confirmed.', 'lawallet-lightning-address' );
		if ( $reason ) {
			/* translators: %s: error message returned by the last settlement verification attempt. */
			$note .= ' ' . sprintf( __( 'Last verification error: %s', 'lawallet-lightning-address' ), $reason );
		}
		$order->update_status( 'cancelled', $note );
	}

	private static function payment_response( WC_Order $order, $status ) {
		return array(
			'status'    => $status,
			'paid'      => $order->is_paid(),
			'expired'   => 'expired' === $status,
			'returnUrl' => $order->get_checkout_order_received_url(),
		);
	}

	private static function nonce_action( WC_Order $order ) {
		return 'wcll_claim_' . $order->get_id() . '_' . $order->get_order_key();
	}

	/**
	 * Normalize a stored relay list to browser-safe ws/wss URLs.
	 */
	private static function sanitize_ws_relays( $relays ) {
		if ( ! is_array( $relays ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $relay ) {
						return esc_url_raw( $relay, array( 'ws', 'wss' ) );
					},
					$relays
				)
			)
		);
	}
}
