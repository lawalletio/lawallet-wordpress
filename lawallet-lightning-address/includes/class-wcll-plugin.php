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
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_paid_order_pay_to_order_received' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );
		add_action( 'wp_ajax_wcll_claim_payment', array( __CLASS__, 'ajax_claim_payment' ) );
		add_action( 'wp_ajax_nopriv_wcll_claim_payment', array( __CLASS__, 'ajax_claim_payment' ) );
		add_action( 'woocommerce_receipt_wcll_gateway', array( __CLASS__, 'render_payment_page' ) );
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

		$relays = $order->get_meta( '_wcll_nostr_relays', true );
		if ( ! is_array( $relays ) ) {
			$relays = array();
		}

		$params = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'orderId'       => $order->get_id(),
			'orderKey'      => $order->get_order_key(),
			'nonce'         => wp_create_nonce( self::nonce_action( $order ) ),
			'invoice'       => $invoice,
			'paymentStatus' => $order->get_meta( '_wcll_status', true ),
			'isPaid'        => $order->is_paid(),
			'expiresAt'     => (int) $order->get_meta( '_wcll_expires_at', true ),
			'returnUrl'     => $order->get_checkout_order_received_url(),
			'nostrPubkey'   => (string) $order->get_meta( '_wcll_nostr_pubkey', true ),
			'nostrRelays'   => array_values(
				array_filter(
					array_map(
						function ( $relay ) {
							return esc_url_raw( $relay, array( 'ws', 'wss' ) );
						},
						$relays
					)
				)
			),
			'i18n'          => array(
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
	}

	public static function claim_order_payment( WC_Order $order, $from_cron = false ) {
		if ( $order->is_paid() ) {
			return self::payment_response( $order, 'paid' );
		}

		$verify_url = $order->get_meta( '_wcll_verify_url', true );
		$invoice    = $order->get_meta( '_wcll_invoice', true );
		$expires_at = (int) $order->get_meta( '_wcll_expires_at', true );

		if ( empty( $verify_url ) || empty( $invoice ) ) {
			return self::payment_response( $order, 'missing' );
		}

		$client = new WCLL_LNURL_Client( WCLL_Gateway::get_gateway_settings() );
		$check  = $client->verify_invoice( $verify_url, $invoice );

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
			$order->update_meta_data( '_wcll_status', 'paid' );
			if ( ! empty( $check['preimage'] ) ) {
				$order->update_meta_data( '_wcll_preimage', sanitize_text_field( $check['preimage'] ) );
			}
			$order->save();

			$order->payment_complete();
			$order->add_order_note( __( 'Lightning payment verified with LUD-21.', 'lawallet-lightning-address' ) );

			return self::payment_response( $order, 'paid' );
		}

		if ( $expires_at && time() > $expires_at ) {
			self::cancel_expired_order( $order );
			return self::payment_response( $order, 'expired' );
		}

		return self::payment_response( $order, 'pending' );
	}

	private static function cancel_expired_order( WC_Order $order, $reason = '' ) {
		if ( $order->is_paid() || 'cancelled' === $order->get_status() ) {
			return;
		}

		$order->update_meta_data( '_wcll_status', 'expired' );
		$order->save();

		$note = __( 'Lightning invoice expired before LUD-21 settlement was verified.', 'lawallet-lightning-address' );
		if ( $reason ) {
			/* translators: %s: error message returned by the last LUD-21 verification attempt. */
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
}
