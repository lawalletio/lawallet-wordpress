<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Plugin {
	const CRON_HOOK = 'wcll_check_pending_payments';

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
		add_action( 'woocommerce_receipt_wcll_gateway', array( __CLASS__, 'render_payment_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_gateway_admin_assets' ) );
		add_action( 'wp_ajax_wcll_check_lightning_address', array( __CLASS__, 'ajax_check_lightning_address' ) );
		add_action( 'wp_ajax_wcll_nwc_balance', array( __CLASS__, 'ajax_nwc_balance' ) );
		add_action( 'wp_ajax_wcll_nwc_receive', array( __CLASS__, 'ajax_nwc_receive' ) );
		add_action( 'wp_ajax_wcll_nwc_withdraw', array( __CLASS__, 'ajax_nwc_withdraw' ) );
		add_action( 'wp_ajax_wcll_nwc_regenerate', array( __CLASS__, 'ajax_nwc_regenerate' ) );
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
					'amountRequired' => __( 'Enter an amount in sats.', 'lawallet-lightning-address' ),
					'destRequired'   => __( 'Enter a Lightning Address or BOLT11 invoice.', 'lawallet-lightning-address' ),
					'regenerating'   => __( 'Regenerating…', 'lawallet-lightning-address' ),
					'regenerated'    => __( 'Connection regenerated.', 'lawallet-lightning-address' ),
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

	public static function ajax_nwc_receive() {
		self::verify_nwc_admin();
		$amount_sats = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
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
				'invoice' => isset( $invoice['invoice'] ) ? $invoice['invoice'] : '',
				'amount'  => $amount_sats,
			)
		);
	}

	public static function ajax_nwc_withdraw() {
		self::verify_nwc_admin();
		$destination = isset( $_POST['destination'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['destination'] ) ) ) : '';
		$amount_sats = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;

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

		$url = isset( $_POST['lncurl_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['lncurl_url'] ) ) ) : '';
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
	 * Paginated list of orders settled through the NWC proxy, newest first.
	 *
	 * @return array{items:array,total:int,pages:int,page:int}
	 */
	public static function nwc_transactions( $page = 1, $per_page = 5 ) {
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

		// The _wcll_settlement_method='nwc' meta is set only by this gateway, so it
		// uniquely identifies NWC proxy orders on its own.
		$query = wc_get_orders(
			array(
				'limit'      => $per_page,
				'paged'      => $page,
				'paginate'   => true,
				'meta_key'   => '_wcll_settlement_method', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded, paginated admin list of NWC orders; wc_get_orders is HPOS-aware.
				'meta_value' => 'nwc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);

		foreach ( $query->orders as $order ) {
			$result['items'][] = self::nwc_tx_row( $order );
		}
		$result['total'] = (int) $query->total;
		$result['pages'] = (int) $query->max_num_pages;
		return $result;
	}

	private static function nwc_tx_row( WC_Order $order ) {
		$amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
		$forwarded   = (string) $order->get_meta( '_wcll_nwc_forwarded', true );
		$pending     = 'yes' === (string) $order->get_meta( '_wcll_nwc_forward_pending', true );
		$received    = $order->is_paid();

		if ( 'yes' === $forwarded ) {
			$forward = 'forwarded';
		} elseif ( 'failed' === $forwarded ) {
			$forward = 'failed';
		} elseif ( $received && $pending ) {
			$forward = 'pending';
		} else {
			$forward = 'none';
		}

		$date = $order->get_date_created();

		return array(
			'order_id' => $order->get_id(),
			'order'    => $order->get_order_number(),
			'url'      => $order->get_edit_order_url(),
			'date'     => $date ? wc_format_datetime( $date ) : '',
			'amount'   => (int) round( $amount_msat / 1000 ),
			'received' => $received,
			'forward'  => $forward,
			'dest'     => (string) $order->get_meta( '_wcll_lightning_address', true ),
		);
	}

	public static function ajax_nwc_transactions() {
		self::verify_nwc_admin();
		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$data = self::nwc_transactions( $page, 10 );

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
	 * Render one transaction as a fully-escaped table row. Used for both the
	 * inline list and the (AJAX-fed) modal, so the markup is defined once.
	 */
	public static function nwc_tx_row_html( array $row ) {
		$labels        = array(
			'forwarded' => __( 'Forwarded', 'lawallet-lightning-address' ),
			'pending'   => __( 'Pending', 'lawallet-lightning-address' ),
			'failed'    => __( 'Failed', 'lawallet-lightning-address' ),
			'none'      => __( 'Not forwarded', 'lawallet-lightning-address' ),
		);
		$forward       = isset( $row['forward'] ) ? (string) $row['forward'] : 'none';
		$forward_label = isset( $labels[ $forward ] ) ? $labels[ $forward ] : $labels['none'];

		$forward_cell = '<span class="wcll-tx-status is-' . esc_attr( sanitize_html_class( $forward ) ) . '">' . esc_html( $forward_label ) . '</span>';
		if ( 'forwarded' === $forward && ! empty( $row['dest'] ) ) {
			/* translators: %s: merchant Lightning Address. */
			$forward_cell .= '<br /><span class="wcll-tx-dest">' . esc_html( sprintf( __( 'to %s', 'lawallet-lightning-address' ), $row['dest'] ) ) . '</span>';
		}

		$received_cell = ! empty( $row['received'] )
			? '<span class="wcll-tx-status is-received">' . esc_html__( 'Received', 'lawallet-lightning-address' ) . '</span>'
			: '<span class="wcll-tx-status is-unpaid">' . esc_html__( 'Awaiting payment', 'lawallet-lightning-address' ) . '</span>';

		$show_cell = '<button type="button" class="button button-small wcll-tx-show" data-wcll-tx-show="' . esc_attr( (int) $row['order_id'] ) . '">' . esc_html__( 'Show', 'lawallet-lightning-address' ) . '</button>';

		$order_cell = ! empty( $row['url'] )
			? '<a href="' . esc_url( $row['url'] ) . '">#' . esc_html( $row['order'] ) . '</a>'
			: '#' . esc_html( $row['order'] );

		$html  = '<tr>';
		$html .= '<td>' . $order_cell . '</td>';
		$html .= '<td>' . esc_html( $row['date'] ) . '</td>';
		$html .= '<td>' . esc_html( number_format_i18n( (int) $row['amount'] ) ) . ' ' . esc_html__( 'sats', 'lawallet-lightning-address' ) . '</td>';
		$html .= '<td>' . $received_cell . '</td>';
		$html .= '<td>' . $forward_cell . '</td>';
		$html .= '<td>' . $show_cell . '</td>';
		$html .= '</tr>';
		return $html;
	}

	public static function ajax_nwc_transaction() {
		self::verify_nwc_admin();
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || 'nwc' !== (string) $order->get_meta( '_wcll_settlement_method', true ) ) {
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
		$reserve     = max( (int) round( $amount_msat * 0.01 ), 10000 );
		$forward     = max( 0, $amount_msat - $reserve );
		$received    = $order->is_paid();
		$forwarded   = (string) $order->get_meta( '_wcll_nwc_forwarded', true );
		$fwd_pending = 'yes' === (string) $order->get_meta( '_wcll_nwc_forward_pending', true );

		if ( 'yes' === $forwarded ) {
			$fwd_state = 'forwarded';
			$fwd_label = __( 'Forwarded', 'lawallet-lightning-address' );
		} elseif ( 'failed' === $forwarded ) {
			$fwd_state = 'failed';
			$fwd_label = __( 'Failed', 'lawallet-lightning-address' );
		} elseif ( $received && $fwd_pending ) {
			$fwd_state = 'pending';
			$fwd_label = __( 'Pending', 'lawallet-lightning-address' );
		} else {
			$fwd_state = 'none';
			$fwd_label = __( 'Not forwarded', 'lawallet-lightning-address' );
		}

		$date = $order->get_date_created();

		$html  = '<div class="wcll-txd">';
		/* translators: 1: order number, 2: order date. */
		$html .= '<h3 class="wcll-txd-order">' . esc_html( sprintf( __( 'Order #%1$s — %2$s', 'lawallet-lightning-address' ), $order->get_order_number(), $date ? wc_format_datetime( $date ) : '' ) ) . '</h3>';

		$html .= '<div class="wcll-txd-section"><h4>' . esc_html__( 'Received (proxy wallet)', 'lawallet-lightning-address' ) . '</h4><dl class="wcll-txd-list">';
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

		$html .= '<div class="wcll-txd-section"><h4>' . esc_html__( 'Sent (forward)', 'lawallet-lightning-address' ) . '</h4><dl class="wcll-txd-list">';
		$html .= self::txd_field( __( 'Status', 'lawallet-lightning-address' ), '<span class="wcll-tx-status is-' . esc_attr( $fwd_state ) . '">' . esc_html( $fwd_label ) . '</span>' );
		$html .= self::txd_field( __( 'To', 'lawallet-lightning-address' ), esc_html( (string) $order->get_meta( '_wcll_lightning_address', true ) ) );
		$html .= self::txd_field( __( 'Amount', 'lawallet-lightning-address' ), self::txd_sats( $forward ) );
		$html .= self::txd_field( __( 'Reserve kept', 'lawallet-lightning-address' ), self::txd_sats( $reserve ) );
		/* translators: %s: routing fee in millisatoshis. */
		$html .= self::txd_field( __( 'Routing fee', 'lawallet-lightning-address' ), esc_html( sprintf( __( '%s msat', 'lawallet-lightning-address' ), number_format_i18n( (int) $order->get_meta( '_wcll_nwc_forward_fees', true ) ) ) ) );
		$html .= self::txd_field( __( 'Preimage', 'lawallet-lightning-address' ), self::txd_mono( (string) $order->get_meta( '_wcll_nwc_forward_preimage', true ) ) );
		$html .= self::txd_field( __( 'Invoice', 'lawallet-lightning-address' ), self::txd_mono( (string) $order->get_meta( '_wcll_nwc_forward_invoice', true ) ) );
		$html .= '</dl></div>';

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

		$nwc_relays = $order->get_meta( '_wcll_nwc_relays', true );

		$params = array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'orderId'         => $order->get_id(),
			'orderKey'        => $order->get_order_key(),
			'nonce'           => wp_create_nonce( self::nonce_action( $order ) ),
			'invoice'         => $invoice,
			'paymentStatus'   => $order->get_meta( '_wcll_status', true ),
			'isPaid'          => $order->is_paid(),
			'expiresAt'       => (int) $order->get_meta( '_wcll_expires_at', true ),
			'returnUrl'       => $order->get_checkout_order_received_url(),
			'nostrPubkey'     => (string) $order->get_meta( '_wcll_nostr_pubkey', true ),
			'nostrRelays'     => self::sanitize_ws_relays( $order->get_meta( '_wcll_nostr_relays', true ) ),
			'nwcWalletPubkey' => (string) $order->get_meta( '_wcll_nwc_wallet_pubkey', true ),
			'nwcClientPubkey' => (string) $order->get_meta( '_wcll_nwc_client_pubkey', true ),
			'nwcRelays'       => self::sanitize_ws_relays( $nwc_relays ),
			'i18n'            => array(
				'waiting'  => __( 'Waiting for payment', 'lawallet-lightning-address' ),
				'checking' => __( 'Checking settlement', 'lawallet-lightning-address' ),
				'paid'     => __( 'Payment received', 'lawallet-lightning-address' ),
				'expired'  => __( 'Invoice expired', 'lawallet-lightning-address' ),
				'copy'     => __( 'Copy invoice', 'lawallet-lightning-address' ),
				'copied'   => __( 'Copied', 'lawallet-lightning-address' ),
				'payWebln' => __( 'Pay with WebLN', 'lawallet-lightning-address' ),
				'webLnPaying' => __( 'Opening WebLN', 'lawallet-lightning-address' ),
				'webLnChecking' => __( 'Checking payment', 'lawallet-lightning-address' ),
			),
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

		// Retry NWC forwards that settled but were not forwarded yet. These orders
		// are already paid, so the pending-settlement query above will not see them.
		$forward_orders = wc_get_orders(
			array(
				'limit'          => 50,
				'status'         => array( 'processing', 'completed', 'on-hold' ),
				'payment_method' => 'wcll_gateway',
				'meta_key'       => '_wcll_nwc_forward_pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded lookup (limit 50) of paid-but-unforwarded NWC orders; WooCommerce maps it to an HPOS-aware query.
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		foreach ( $forward_orders as $order ) {
			self::forward_nwc_payment( $order );
		}

		// Keep a live disposable wallet ready so the next order never lands on a
		// wallet that lncurl has reaped.
		WCLL_NWC_Manager::ensure_live_active( WCLL_Gateway::get_gateway_settings() );
	}

	public static function claim_order_payment( WC_Order $order, $from_cron = false ) {
		if ( $order->is_paid() ) {
			// A paid NWC order may still owe its forward to the merchant address.
			if ( 'nwc' === (string) $order->get_meta( '_wcll_settlement_method', true )
				&& 'yes' === (string) $order->get_meta( '_wcll_nwc_forward_pending', true ) ) {
				self::forward_nwc_payment( $order );
			}
			return self::payment_response( $order, 'paid' );
		}

		$invoice    = $order->get_meta( '_wcll_invoice', true );
		$expires_at = (int) $order->get_meta( '_wcll_expires_at', true );

		if ( empty( $invoice ) ) {
			return self::payment_response( $order, 'missing' );
		}

		$check = self::verify_order_settlement( $order, $invoice );

		if ( is_wp_error( $check ) ) {
			if ( $from_cron && $expires_at && time() > $expires_at ) {
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
			if ( 'nwc' === $method ) {
				// Mark the forward owed before completing payment, so a crash
				// between the two is recovered by the cron forward-retry query.
				$order->update_meta_data( '_wcll_nwc_forward_pending', 'yes' );
			}
			$order->save();

			$order->payment_complete();
			$order->add_order_note( self::settlement_note( $method ) );

			if ( 'nwc' === $method ) {
				self::forward_nwc_payment( $order );
			}

			return self::payment_response( $order, 'paid' );
		}

		if ( $expires_at && time() > $expires_at ) {
			self::cancel_expired_order( $order );
			return self::payment_response( $order, 'expired' );
		}

		return self::payment_response( $order, 'pending' );
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
	 * Forward a settled NWC proxy payment to the merchant Lightning Address.
	 *
	 * Idempotent and safe to call from both the AJAX claim and cron: a transient
	 * inflight lock plus the `_wcll_nwc_forwarded` flag and an attempt cap prevent
	 * a double payout, and a failure never un-pays the (already paid) order.
	 */
	public static function forward_nwc_payment( WC_Order $order ) {
		$forwarded = (string) $order->get_meta( '_wcll_nwc_forwarded', true );
		if ( 'yes' === $forwarded || 'failed' === $forwarded ) {
			return 'yes' === $forwarded;
		}

		// Inflight lock: stop cron and the AJAX claim from forwarding concurrently.
		$lock_key = 'wcll_nwc_fwd_' . $order->get_id();
		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

		$max_attempts = 10;

		try {
			$attempts = (int) $order->get_meta( '_wcll_nwc_forward_attempts', true );
			if ( $attempts >= $max_attempts ) {
				// Cap already reached (e.g. a crash between increment and resolution):
				// mark it failed with a manual-recovery note instead of silently
				// looping on every cron run.
				return self::forward_failed( $order, __( 'NWC proxy: reached the maximum number of forwarding attempts.', 'lawallet-lightning-address' ), $attempts, $max_attempts );
			}
			$attempts++;
			$order->update_meta_data( '_wcll_nwc_forward_attempts', $attempts );
			$order->save();

			$amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
			$reserve     = max( (int) round( $amount_msat * 0.01 ), 10000 );
			$forward     = $amount_msat - $reserve;
			if ( $forward <= 0 ) {
				return self::forward_abort( $order, __( 'NWC proxy: the order amount is too small to forward after the routing reserve. Forward the funds to your Lightning Address manually.', 'lawallet-lightning-address' ) );
			}

			$nwc = self::build_nwc_client_for_order( $order );
			if ( is_wp_error( $nwc ) ) {
				return self::forward_failed( $order, $nwc->get_error_message(), $attempts, $max_attempts );
			}

			// Reuse the forward invoice from a previous attempt when one exists.
			// pay_invoice() can settle on the wallet yet have its NWC response lost
			// in transit (a slow relay, a dropped connection) — which would leave
			// the order stuck "pending" and, worse, make the next retry pay a
			// second time. So forwarding is made idempotent against a single
			// invoice: we persist it BEFORE paying and, before ever paying again,
			// ask the wallet whether it is already settled.
			$forward_bolt11 = (string) $order->get_meta( '_wcll_nwc_forward_invoice', true );
			$reused         = ( '' !== $forward_bolt11 );

			if ( $reused ) {
				$look = $nwc->lookup_invoice( '', $forward_bolt11 );
				if ( ! is_wp_error( $look ) && ! empty( $look['settled'] ) ) {
					return self::forward_settled( $order, $forward, $reserve, $look['preimage'], self::lookup_fees( $look ) );
				}
			} else {
				$settings    = WCLL_Gateway::get_gateway_settings();
				$address     = (string) $order->get_meta( '_wcll_lightning_address', true );
				$lnurl       = new WCLL_LNURL_Client( $settings );
				$pay_request = $lnurl->resolve_lightning_address( $address );
				if ( is_wp_error( $pay_request ) ) {
					return self::forward_failed( $order, $pay_request->get_error_message(), $attempts, $max_attempts );
				}

				// Respect the address's sendable bounds; abort cleanly if the forward
				// (amount minus reserve) cannot fit within them.
				$min = isset( $pay_request['minSendable'] ) ? (int) $pay_request['minSendable'] : 0;
				$max = isset( $pay_request['maxSendable'] ) ? (int) $pay_request['maxSendable'] : 0;
				if ( $forward < $min || ( $max > 0 && $forward > $max ) ) {
					return self::forward_abort( $order, __( 'NWC proxy: the forward amount is outside the Lightning Address limits. Forward the funds to your Lightning Address manually.', 'lawallet-lightning-address' ) );
				}

				$forward_invoice = $lnurl->request_invoice(
					$pay_request,
					$forward,
					array(
						'comment'   => sprintf( 'Proxied from Woocommerce #%s', $order->get_order_number() ),
						'use_nostr' => false,
					)
				);
				if ( is_wp_error( $forward_invoice ) ) {
					return self::forward_failed( $order, $forward_invoice->get_error_message(), $attempts, $max_attempts );
				}
				if ( empty( $forward_invoice['pr'] ) ) {
					return self::forward_failed( $order, __( 'The Lightning Address did not return a forward invoice.', 'lawallet-lightning-address' ), $attempts, $max_attempts );
				}

				$forward_bolt11 = (string) $forward_invoice['pr'];
				// Persist the forward invoice BEFORE paying it, so a lost response is
				// reconciled by lookup_invoice on the next attempt rather than paid twice.
				$order->update_meta_data( '_wcll_nwc_forward_invoice', sanitize_text_field( $forward_bolt11 ) );
				$order->save();
			}

			$payment = $nwc->pay_invoice( $forward_bolt11 );
			if ( is_wp_error( $payment ) ) {
				// The payment may have settled even though the response was lost.
				// Confirm with the wallet before treating this as a failure, so we
				// never send the funds twice on the next retry.
				$look = $nwc->lookup_invoice( '', $forward_bolt11 );
				if ( ! is_wp_error( $look ) && ! empty( $look['settled'] ) ) {
					return self::forward_settled( $order, $forward, $reserve, $look['preimage'], self::lookup_fees( $look ) );
				}
				// Only when the lookup definitively reports the reused invoice as
				// unpaid do we discard it (it is stale/expired) and let the next
				// attempt mint a fresh one. A lookup that itself errored leaves the
				// invoice in place so the next run can re-confirm it.
				if ( $reused && ! is_wp_error( $look ) ) {
					$order->delete_meta_data( '_wcll_nwc_forward_invoice' );
					$order->save();
				}
				return self::forward_failed( $order, $payment->get_error_message(), $attempts, $max_attempts );
			}

			return self::forward_settled( $order, $forward, $reserve, $payment['preimage'], (int) $payment['fees_paid'] );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Extract the routing fee (msat) from a normalized lookup_invoice result.
	 */
	private static function lookup_fees( array $look ) {
		return isset( $look['raw']['fees_paid'] ) ? (int) $look['raw']['fees_paid'] : 0;
	}

	/**
	 * Record a successful (or recovered) forward: flag it, store the proof, clear
	 * the pending marker and add a merchant note. Shared by the direct-pay path
	 * and the lookup-based recovery so both produce identical, idempotent state.
	 */
	private static function forward_settled( WC_Order $order, $forward, $reserve, $preimage, $fees_paid ) {
		$order->update_meta_data( '_wcll_nwc_forwarded', 'yes' );
		$order->update_meta_data( '_wcll_nwc_forward_preimage', sanitize_text_field( (string) $preimage ) );
		$order->update_meta_data( '_wcll_nwc_forward_fees', (int) $fees_paid );
		$order->delete_meta_data( '_wcll_nwc_forward_pending' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: forwarded amount in sats, 2: reserve kept in sats, 3: routing fee paid in sats. */
				__( 'NWC proxy: forwarded %1$d sats to the merchant Lightning Address (kept %2$d sats reserve; %3$d sats routing fee).', 'lawallet-lightning-address' ),
				(int) round( $forward / 1000 ),
				(int) round( $reserve / 1000 ),
				(int) round( ( (int) $fees_paid ) / 1000 )
			)
		);

		return true;
	}

	/**
	 * Record a recoverable forward failure: leave the order pending for cron to
	 * retry, or give up with an admin note once the attempt cap is reached.
	 */
	private static function forward_failed( WC_Order $order, $message, $attempts, $max_attempts ) {
		if ( $attempts >= $max_attempts ) {
			$order->update_meta_data( '_wcll_nwc_forwarded', 'failed' );
			$order->delete_meta_data( '_wcll_nwc_forward_pending' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: last forwarding error message. */
					__( 'NWC proxy: could not forward the payment after several attempts (%s). The funds are held in the proxy wallet — forward them to your Lightning Address manually.', 'lawallet-lightning-address' ),
					$message
				)
			);
		} else {
			// Keep _wcll_nwc_forward_pending set so cron retries later.
			$order->save();
		}

		return false;
	}

	/**
	 * Permanently abort forwarding (non-retryable): the amount cannot be routed.
	 */
	private static function forward_abort( WC_Order $order, $note ) {
		$order->update_meta_data( '_wcll_nwc_forwarded', 'failed' );
		$order->delete_meta_data( '_wcll_nwc_forward_pending' );
		$order->save();
		$order->add_order_note( $note );
		return false;
	}

	private static function settlement_note( $method ) {
		if ( 'nip57' === $method ) {
			return __( 'Lightning payment verified via NIP-57 zap receipt.', 'lawallet-lightning-address' );
		}
		if ( 'nwc' === $method ) {
			return __( 'Lightning payment verified through the NWC proxy wallet.', 'lawallet-lightning-address' );
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
