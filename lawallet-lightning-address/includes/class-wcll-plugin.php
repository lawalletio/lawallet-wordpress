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
				$lud21 = is_wp_error( $invoice )
					? array( 'ok' => false, 'message' => $invoice->get_error_message() )
					: array( 'ok' => true, 'message' => __( 'LUD-21 settlement verification supported.', 'lawallet-lightning-address' ) );
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
	 * Rebuild the NWC client for an order: the secret/connection comes from the
	 * (never-persisted) gateway settings, while the order's stored relays are used
	 * for transport. Guards against the proxy wallet being swapped mid-order.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	private static function build_nwc_client_for_order( WC_Order $order ) {
		$nwc = WCLL_Gateway::resolve_nwc_client( WCLL_Gateway::get_gateway_settings() );
		if ( ! ( $nwc instanceof WCLL_NWC_Client ) ) {
			return is_wp_error( $nwc ) ? $nwc : new WP_Error( 'wcll_nwc_unconfigured', __( 'The NWC proxy wallet is no longer configured.', 'lawallet-lightning-address' ) );
		}

		$order_relays = $order->get_meta( '_wcll_nwc_relays', true );
		if ( is_array( $order_relays ) && ! empty( $order_relays ) ) {
			$nwc->with_relays( $order_relays );
		}

		$order_wallet = strtolower( (string) $order->get_meta( '_wcll_nwc_wallet_pubkey', true ) );
		if ( '' !== $order_wallet && $order_wallet !== strtolower( $nwc->wallet_pubkey() ) ) {
			return new WP_Error( 'wcll_nwc_wallet_mismatch', __( 'The configured NWC wallet no longer matches this order.', 'lawallet-lightning-address' ) );
		}

		return $nwc;
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
				return false;
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

			$settings = WCLL_Gateway::get_gateway_settings();
			$address  = (string) $order->get_meta( '_wcll_lightning_address', true );

			$nwc = self::build_nwc_client_for_order( $order );
			if ( is_wp_error( $nwc ) ) {
				return self::forward_failed( $order, $nwc->get_error_message(), $attempts, $max_attempts );
			}

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
					'description' => sprintf( 'WooCommerce order #%s (NWC forward)', $order->get_order_number() ),
					'use_nostr'   => false,
				)
			);
			if ( is_wp_error( $forward_invoice ) ) {
				return self::forward_failed( $order, $forward_invoice->get_error_message(), $attempts, $max_attempts );
			}
			if ( empty( $forward_invoice['pr'] ) ) {
				return self::forward_failed( $order, __( 'The Lightning Address did not return a forward invoice.', 'lawallet-lightning-address' ), $attempts, $max_attempts );
			}

			$payment = $nwc->pay_invoice( $forward_invoice['pr'] );
			if ( is_wp_error( $payment ) ) {
				return self::forward_failed( $order, $payment->get_error_message(), $attempts, $max_attempts );
			}

			$order->update_meta_data( '_wcll_nwc_forwarded', 'yes' );
			$order->update_meta_data( '_wcll_nwc_forward_preimage', sanitize_text_field( $payment['preimage'] ) );
			$order->update_meta_data( '_wcll_nwc_forward_fees', (int) $payment['fees_paid'] );
			$order->delete_meta_data( '_wcll_nwc_forward_pending' );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: forwarded amount in sats, 2: reserve kept in sats. */
					__( 'NWC proxy: forwarded %1$d sats to the merchant Lightning Address (kept %2$d sats reserve for routing fees).', 'lawallet-lightning-address' ),
					(int) round( $forward / 1000 ),
					(int) round( $reserve / 1000 )
				)
			);

			return true;
		} finally {
			delete_transient( $lock_key );
		}
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
