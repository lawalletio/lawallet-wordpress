<?php
/**
 * Lifecycle manager for the NWC proxy wallet.
 *
 * Two modes:
 *  - permanent: the merchant supplies a fixed nostr+walletconnect:// string that
 *    is used as-is and is NEVER auto-replaced.
 *  - disposable: the plugin mints throwaway wallets on demand from an lncurl
 *    service (a single POST returns a connection string), reuses the active one
 *    across orders, and replaces it when it dies (lncurl deletes idle/empty
 *    wallets that can no longer pay their upkeep).
 *
 * Connection secrets live ONLY in dedicated autoload=no options
 * (wcll_nwc_active_connection / wcll_nwc_connection_archive). They are never
 * written into the WooCommerce settings array (which is reflected back into the
 * admin form), never into order meta, and never sent to the browser. Orders
 * always settle/forward against the EXACT wallet they were invoiced on, looked
 * up by stored wallet pubkey — a rotated-in replacement never receives funds it
 * does not hold.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_NWC_Manager {
	const ACTIVE_OPTION          = 'wcll_nwc_active_connection';
	const ARCHIVE_OPTION         = 'wcll_nwc_connection_archive';
	const PERMANENT_OPTION       = 'wcll_nwc_permanent_connection';
	const RECEIVER_OPTION        = 'wcll_nwc_receiver_connection';
	const NOTICES_OPTION         = 'wcll_nwc_admin_notices';
	const MINT_WINDOW            = 'wcll_nwc_mint_window';
	const MINT_LOCK              = 'wcll_nwc_mint_lock';
	const BALANCE_CACHE          = 'wcll_nwc_balance_cache';
	const RECEIVER_BALANCE_CACHE = 'wcll_nwc_receiver_balance_cache';
	const ARCHIVE_MAX            = 20;
	const MINT_MAX_PER_HOUR      = 12;
	const BALANCE_TTL            = 120;
	const DEFAULT_LNCURL         = 'https://lncurl.lol';

	/**
	 * The selected settlement method: 'lightning_address', 'nwc_proxy' (receive on
	 * a managed proxy wallet, then forward to the Lightning Address) or 'nwc'
	 * (receive into the merchant's own NWC wallet, terminal).
	 */
	public static function settlement_method( array $settings ) {
		$method = isset( $settings['settlement_method'] ) ? (string) $settings['settlement_method'] : 'lightning_address';
		return in_array( $method, array( 'lightning_address', 'nwc_proxy', 'nwc' ), true ) ? $method : 'lightning_address';
	}

	/** Proxy mode: a managed wallet receives, then forwards to the Lightning Address. */
	public static function is_proxy( array $settings ) {
		return 'nwc_proxy' === self::settlement_method( $settings );
	}

	/** Terminal NWC mode: the merchant's own wallet receives and keeps the funds. */
	public static function is_terminal( array $settings ) {
		return 'nwc' === self::settlement_method( $settings );
	}

	/** Either NWC-based mode (proxy or terminal). */
	public static function uses_nwc( array $settings ) {
		return self::is_proxy( $settings ) || self::is_terminal( $settings );
	}

	/**
	 * "Enabled" historically meant "the managed proxy wallet is in use". It now maps
	 * to proxy mode, so all the managed-wallet machinery (disposable provisioning,
	 * active/archive connections, the wallet panel, liveness cron) gates on it.
	 */
	public static function is_enabled( array $settings ) {
		return self::is_proxy( $settings );
	}

	/** Settled NWC payments are forwarded to the Lightning Address only in proxy mode. */
	public static function forward_enabled( array $settings ) {
		return self::is_proxy( $settings );
	}

	public static function mode( array $settings ) {
		$mode = isset( $settings['nwc_mode'] ) ? (string) $settings['nwc_mode'] : '';
		if ( 'permanent' === $mode || 'disposable' === $mode ) {
			return $mode;
		}
		// Defensive default for un-migrated installs: a stored manual string means
		// permanent; otherwise disposable.
		$has_string = '' !== trim( self::permanent_connection() )
			|| ( isset( $settings['nwc_connection'] ) && '' !== trim( (string) $settings['nwc_connection'] ) );
		return $has_string ? 'permanent' : 'disposable';
	}

	/**
	 * The permanent NWC connection string. Kept in a dedicated autoload=no option
	 * so the secret never lands in the WooCommerce settings array (which is
	 * autoloaded and reflected back into the admin form).
	 */
	public static function permanent_connection() {
		return (string) get_option( self::PERMANENT_OPTION, '' );
	}

	public static function set_permanent_connection( $uri ) {
		update_option( self::PERMANENT_OPTION, (string) $uri, false );
	}

	public static function clear_permanent_connection() {
		delete_option( self::PERMANENT_OPTION );
	}

	/**
	 * The merchant's own NWC connection string for terminal "NWC" mode. Stored in
	 * its own dedicated autoload=no option, separate from the proxy wallet, so the
	 * two modes never share or overwrite each other's wallet. Same secret-handling
	 * rules as the permanent connection (never autoloaded, logged, or sent to the
	 * browser).
	 */
	public static function receiver_connection() {
		return (string) get_option( self::RECEIVER_OPTION, '' );
	}

	public static function set_receiver_connection( $uri ) {
		update_option( self::RECEIVER_OPTION, (string) $uri, false );
		delete_transient( self::RECEIVER_BALANCE_CACHE );
	}

	public static function clear_receiver_connection() {
		delete_option( self::RECEIVER_OPTION );
		delete_transient( self::RECEIVER_BALANCE_CACHE );
	}

	/**
	 * Client for the terminal NWC wallet (the merchant's own wallet). Null outside
	 * terminal mode; WP_Error if the connection string is missing/invalid.
	 *
	 * @return WCLL_NWC_Client|WP_Error|null
	 */
	public static function receiver_client( array $settings ) {
		if ( ! self::is_terminal( $settings ) ) {
			return null;
		}
		$uri = self::receiver_connection();
		if ( '' === trim( $uri ) ) {
			return new WP_Error( 'wcll_nwc_unconfigured', __( 'Set your NWC connection string.', 'lawallet-lightning-address' ) );
		}
		return WCLL_NWC_Client::from_uri( $uri );
	}

	/**
	 * make_invoice on the terminal wallet for a new order. No disposable self-heal:
	 * it is the merchant's own wallet, not a managed throwaway.
	 *
	 * @return array{client:WCLL_NWC_Client,invoice:array}|WP_Error
	 */
	public static function make_receiver_invoice( array $settings, $amount_msat, $description, $expiry_seconds ) {
		$client = self::receiver_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			return is_wp_error( $client ) ? $client : new WP_Error( 'wcll_nwc_unavailable', __( 'The NWC wallet is not available.', 'lawallet-lightning-address' ) );
		}
		$invoice = $client->make_invoice( $amount_msat, $description, $expiry_seconds );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		return array(
			'client'  => $client,
			'invoice' => $invoice,
		);
	}

	/**
	 * Cached balance of the terminal wallet for the Receiver panel.
	 *
	 * @return array{ok:bool,sats:int,checked_at:int}
	 */
	public static function receiver_balance( array $settings, $force = false ) {
		if ( ! self::is_terminal( $settings ) ) {
			return array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => 0,
			);
		}
		if ( ! $force ) {
			$cache = get_transient( self::RECEIVER_BALANCE_CACHE );
			if ( is_array( $cache ) ) {
				return $cache;
			}
		}
		$client = self::receiver_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			$result = array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => time(),
			);
			set_transient( self::RECEIVER_BALANCE_CACHE, $result, self::BALANCE_TTL );
			return $result;
		}
		$balance = $client->get_balance();
		if ( is_wp_error( $balance ) ) {
			$result = array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => time(),
			);
		} else {
			$msat   = isset( $balance['balance'] ) ? (int) $balance['balance'] : 0;
			$result = array(
				'ok'         => true,
				'sats'       => (int) floor( $msat / 1000 ),
				'checked_at' => time(),
			);
		}
		set_transient( self::RECEIVER_BALANCE_CACHE, $result, self::BALANCE_TTL );
		return $result;
	}

	/**
	 * Probe the terminal wallet on settings save.
	 *
	 * @return array{ok:bool,balance_sats:?int,message:string}
	 */
	public static function receiver_probe( array $settings ) {
		$client = self::receiver_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			return array(
				'ok'           => false,
				'balance_sats' => null,
				'message'      => is_wp_error( $client ) ? $client->get_error_message() : __( 'Set your NWC connection string.', 'lawallet-lightning-address' ),
			);
		}
		$balance = $client->get_balance();
		if ( is_wp_error( $balance ) ) {
			return array(
				'ok'           => false,
				'balance_sats' => null,
				'message'      => $balance->get_error_message(),
			);
		}
		$sats = (int) floor( ( isset( $balance['balance'] ) ? (int) $balance['balance'] : 0 ) / 1000 );
		set_transient(
			self::RECEIVER_BALANCE_CACHE,
			array(
				'ok'         => true,
				'sats'       => $sats,
				'checked_at' => time(),
			),
			self::BALANCE_TTL
		);
		return array(
			'ok'           => true,
			'balance_sats' => $sats,
			/* translators: %d: terminal wallet balance in sats. */
			'message'      => sprintf( __( 'NWC wallet ready (balance %d sats); payments are settled there and stay in the wallet.', 'lawallet-lightning-address' ), $sats ),
		);
	}

	/**
	 * Public descriptor for the terminal wallet (pubkeys + relays, NEVER the
	 * secret). Mirrors admin_wallet_info() for the Receiver panel.
	 *
	 * @return array{configured:bool,wallet_pubkey:string,client_pubkey:string,relays:string[]}
	 */
	public static function receiver_admin_info( array $settings ) {
		$info = array(
			'configured'    => false,
			'wallet_pubkey' => '',
			'client_pubkey' => '',
			'relays'        => array(),
		);
		if ( ! self::is_terminal( $settings ) ) {
			return $info;
		}
		$uri = self::receiver_connection();
		if ( '' === trim( $uri ) ) {
			return $info;
		}
		$parsed = WCLL_NWC_Client::parse_connection( $uri );
		if ( is_wp_error( $parsed ) ) {
			return $info;
		}
		$info['configured']    = true;
		$info['wallet_pubkey'] = $parsed['wallet_pubkey'];
		$info['client_pubkey'] = $parsed['client_pubkey'];
		$info['relays']        = $parsed['relays'];
		return $info;
	}

	/**
	 * The full nostr+walletconnect:// connection string for the wallet currently
	 * in use — the permanent connection in permanent mode, or the active
	 * disposable wallet otherwise. Returns '' when nothing is configured.
	 *
	 * SECURITY: the returned string INCLUDES THE SECRET and grants full spend
	 * access to the wallet. It must only ever be handed to an authenticated
	 * administrator on explicit request (capability + nonce). Never log it, store
	 * it in order data, or embed it in rendered page output.
	 */
	public static function current_connection_uri( array $settings ) {
		if ( ! self::is_enabled( $settings ) ) {
			return '';
		}
		if ( 'permanent' === self::mode( $settings ) ) {
			return (string) self::permanent_connection();
		}
		$active = self::get_active_connection();
		return ! empty( $active['uri'] ) ? (string) $active['uri'] : '';
	}

	public static function auto_replace( array $settings ) {
		// Disposable wallets are always replaced when they die — that is the whole
		// point of disposable mode. A permanent connection is never replaced.
		return 'disposable' === self::mode( $settings );
	}

	public static function lncurl_url( array $settings ) {
		$url = isset( $settings['nwc_lncurl_url'] ) ? trim( (string) $settings['nwc_lncurl_url'] ) : '';
		return '' !== $url ? $url : self::DEFAULT_LNCURL;
	}

	/**
	 * Client for invoicing a NEW order. In disposable mode the active wallet is
	 * reused, or a fresh one is minted lazily.
	 *
	 * @return WCLL_NWC_Client|WP_Error|null  null when the proxy is disabled.
	 */
	public static function get_active_client( array $settings ) {
		if ( ! self::is_enabled( $settings ) ) {
			return null;
		}

		if ( 'permanent' === self::mode( $settings ) ) {
			$uri = self::permanent_connection();
			if ( '' === trim( $uri ) ) {
				return new WP_Error( 'wcll_nwc_unconfigured', __( 'Set the permanent NWC connection string.', 'lawallet-lightning-address' ) );
			}
			return WCLL_NWC_Client::from_uri( $uri );
		}

		$active = self::get_active_connection();
		if ( ! empty( $active['uri'] ) ) {
			return WCLL_NWC_Client::from_uri( $active['uri'] );
		}
		return self::mint_and_store( $settings );
	}

	/**
	 * make_invoice for a new order, self-healing: if the active disposable wallet
	 * is dead and auto-replace is on, mint a replacement and retry once.
	 *
	 * @return array{client:WCLL_NWC_Client,invoice:array}|WP_Error
	 */
	public static function make_order_invoice( array $settings, $amount_msat, $description, $expiry_seconds ) {
		$client = self::get_active_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			return is_wp_error( $client ) ? $client : new WP_Error( 'wcll_nwc_unavailable', __( 'The NWC wallet is not available.', 'lawallet-lightning-address' ) );
		}

		$invoice = $client->make_invoice( $amount_msat, $description, $expiry_seconds );
		if ( ! is_wp_error( $invoice ) ) {
			return array(
				'client'  => $client,
				'invoice' => $invoice,
			);
		}

		if ( self::auto_replace( $settings ) ) {
			$replacement = self::handle_dead_wallet( $settings, $client->wallet_pubkey(), $invoice->get_error_message() );
			if ( $replacement instanceof WCLL_NWC_Client ) {
				$retry = $replacement->make_invoice( $amount_msat, $description, $expiry_seconds );
				if ( ! is_wp_error( $retry ) ) {
					return array(
						'client'  => $replacement,
						'invoice' => $retry,
					);
				}
				return $retry;
			}
		}

		return $invoice;
	}

	/**
	 * Resolve the EXACT wallet an order was invoiced on (by stored pubkey), so
	 * verification/forwarding always target the wallet that holds the funds.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	public static function client_for_order( WC_Order $order, array $settings ) {
		$pubkey = strtolower( (string) $order->get_meta( '_wcll_nwc_wallet_pubkey', true ) );
		if ( '' === $pubkey ) {
			return new WP_Error( 'wcll_nwc_no_wallet', __( 'This NWC order has no associated wallet.', 'lawallet-lightning-address' ) );
		}

		$uri = self::connection_uri_for_pubkey( $settings, $pubkey );
		if ( '' === $uri ) {
			return new WP_Error( 'wcll_nwc_wallet_gone', __( 'The NWC wallet for this order is no longer available; forward the funds to your Lightning Address manually.', 'lawallet-lightning-address' ) );
		}

		$client = WCLL_NWC_Client::from_uri( $uri );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Prefer the order's stored relays for transport (handles dev relay splits).
		$order_relays = $order->get_meta( '_wcll_nwc_relays', true );
		if ( is_array( $order_relays ) && ! empty( $order_relays ) ) {
			$client->with_relays( $order_relays );
		}
		return $client;
	}

	/**
	 * Build a client for a SPECIFIC wallet by pubkey (active, archived, permanent,
	 * or the terminal receiver). Used to look an invoice up on the exact wallet it
	 * was minted on, even after a disposable rotation.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	public static function client_for_pubkey( array $settings, $pubkey ) {
		$pubkey = strtolower( (string) $pubkey );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $pubkey ) ) {
			return new WP_Error( 'wcll_nwc_no_pubkey', __( 'Missing wallet.', 'lawallet-lightning-address' ) );
		}
		$uri = self::connection_uri_for_pubkey( $settings, $pubkey );
		if ( '' === $uri ) {
			return new WP_Error( 'wcll_nwc_wallet_gone', __( 'The NWC wallet is no longer available.', 'lawallet-lightning-address' ) );
		}
		return WCLL_NWC_Client::from_uri( $uri );
	}

	private static function connection_uri_for_pubkey( array $settings, $pubkey ) {
		$pubkey = strtolower( $pubkey );

		// Terminal mode's own wallet first, then the proxy's permanent/active/archive.
		$receiver = self::receiver_connection();
		if ( '' !== trim( $receiver ) ) {
			$parsed = WCLL_NWC_Client::parse_connection( $receiver );
			if ( ! is_wp_error( $parsed ) && strtolower( $parsed['wallet_pubkey'] ) === $pubkey ) {
				return $receiver;
			}
		}

		$perm = self::permanent_connection();
		if ( '' !== trim( $perm ) ) {
			$parsed = WCLL_NWC_Client::parse_connection( $perm );
			if ( ! is_wp_error( $parsed ) && strtolower( $parsed['wallet_pubkey'] ) === $pubkey ) {
				return $perm;
			}
		}

		$active = self::get_active_connection();
		if ( ! empty( $active['uri'] ) && strtolower( (string) $active['wallet_pubkey'] ) === $pubkey ) {
			return (string) $active['uri'];
		}

		$archive = self::get_archive();
		if ( isset( $archive[ $pubkey ]['uri'] ) ) {
			return (string) $archive[ $pubkey ]['uri'];
		}

		return '';
	}

	/**
	 * Mint a fresh disposable wallet from lncurl, archive the old active one, and
	 * store the new active connection. Concurrency-guarded and rate-limited.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	public static function mint_and_store( array $settings ) {
		if ( 'disposable' !== self::mode( $settings ) ) {
			return new WP_Error( 'wcll_nwc_not_disposable', __( 'Disposable wallet minting is only available in disposable mode.', 'lawallet-lightning-address' ) );
		}

		// Concurrency: if another request is already minting, reuse what it stored.
		if ( false !== get_transient( self::MINT_LOCK ) ) {
			$active = self::get_active_connection();
			if ( ! empty( $active['uri'] ) ) {
				return WCLL_NWC_Client::from_uri( $active['uri'] );
			}
			return new WP_Error( 'wcll_nwc_minting', __( 'A wallet is currently being provisioned; please retry in a moment.', 'lawallet-lightning-address' ) );
		}

		if ( ! self::within_mint_rate() ) {
			return new WP_Error( 'wcll_nwc_mint_rate', __( 'Too many NWC wallets were provisioned recently; aborting to avoid a loop.', 'lawallet-lightning-address' ) );
		}

		set_transient( self::MINT_LOCK, 1, MINUTE_IN_SECONDS );
		try {
			$uri = self::provision_lncurl( self::lncurl_url( $settings ) );
			if ( is_wp_error( $uri ) ) {
				return $uri;
			}

			$parsed = WCLL_NWC_Client::parse_connection( $uri );
			if ( is_wp_error( $parsed ) ) {
				return new WP_Error( 'wcll_nwc_bad_mint', __( 'The lncurl service returned an invalid connection string.', 'lawallet-lightning-address' ) );
			}

			self::archive_active();
			update_option(
				self::ACTIVE_OPTION,
				array(
					'uri'           => $uri,
					'wallet_pubkey' => $parsed['wallet_pubkey'],
					'relays'        => $parsed['relays'],
					'created_at'    => time(),
				),
				false
			);
			self::record_mint();
			delete_transient( self::BALANCE_CACHE );

			return WCLL_NWC_Client::from_uri( $uri );
		} finally {
			delete_transient( self::MINT_LOCK );
		}
	}

	/**
	 * Replace a dead disposable wallet for FUTURE invoicing. If the active wallet
	 * was already rotated away from $dead_pubkey, just return the current one.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	public static function handle_dead_wallet( array $settings, $dead_pubkey, $reason = '' ) {
		if ( ! self::auto_replace( $settings ) ) {
			return new WP_Error( 'wcll_nwc_no_replace', __( 'The NWC wallet is unavailable and auto-replace is off.', 'lawallet-lightning-address' ) );
		}

		$active = self::get_active_connection();
		if ( ! empty( $active['uri'] ) && strtolower( (string) $active['wallet_pubkey'] ) !== strtolower( (string) $dead_pubkey ) ) {
			return WCLL_NWC_Client::from_uri( $active['uri'] );
		}

		$client = self::mint_and_store( $settings );
		if ( ! is_wp_error( $client ) ) {
			self::add_admin_notice(
				sprintf(
					/* translators: %s: reason the previous wallet was replaced. */
					__( 'NWC proxy: the disposable wallet became unavailable (%s) and was replaced with a fresh one for future orders.', 'lawallet-lightning-address' ),
					$reason ? $reason : __( 'unreachable', 'lawallet-lightning-address' )
				)
			);
		}
		return $client;
	}

	/**
	 * Periodic liveness check (cron): replace the active disposable wallet if it
	 * has died, so a live wallet is always ready before the next order.
	 */
	public static function ensure_live_active( array $settings ) {
		if ( ! self::is_enabled( $settings ) || 'disposable' !== self::mode( $settings ) ) {
			return;
		}

		$active = self::get_active_connection();
		if ( empty( $active['uri'] ) ) {
			return; // Nothing minted yet; the first order will mint one.
		}

		$client = WCLL_NWC_Client::from_uri( $active['uri'] );
		if ( is_wp_error( $client ) ) {
			return;
		}

		$info = $client->get_info();
		if ( is_wp_error( $info ) ) {
			self::handle_dead_wallet( $settings, (string) $active['wallet_pubkey'], $info->get_error_message() );
		}
	}

	/**
	 * Probe the configured proxy (mints one in disposable mode if none exists).
	 * Used on settings save to confirm the wallet works and read its balance.
	 *
	 * @return array{ok:bool,mode:string,balance_sats:?int,message:string}
	 */
	public static function probe( array $settings ) {
		$mode   = self::mode( $settings );
		$client = self::get_active_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			return array(
				'ok'           => false,
				'mode'         => $mode,
				'balance_sats' => null,
				'message'      => is_wp_error( $client ) ? $client->get_error_message() : __( 'The NWC wallet is not configured.', 'lawallet-lightning-address' ),
			);
		}

		$balance = $client->get_balance();
		if ( is_wp_error( $balance ) && self::auto_replace( $settings ) ) {
			$client = self::handle_dead_wallet( $settings, $client->wallet_pubkey(), $balance->get_error_message() );
			if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
				return array(
					'ok'           => false,
					'mode'         => $mode,
					'balance_sats' => null,
					'message'      => $client->get_error_message(),
				);
			}
			$balance = $client->get_balance();
		}
		if ( is_wp_error( $balance ) ) {
			return array(
				'ok'           => false,
				'mode'         => $mode,
				'balance_sats' => null,
				'message'      => $balance->get_error_message(),
			);
		}

		$sats = (int) floor( ( isset( $balance['balance'] ) ? (int) $balance['balance'] : 0 ) / 1000 );
		set_transient(
			self::BALANCE_CACHE,
			array(
				'ok'         => true,
				'sats'       => $sats,
				'checked_at' => time(),
			),
			self::BALANCE_TTL
		);

		$kind = 'disposable' === $mode
			? __( 'Disposable NWC wallet', 'lawallet-lightning-address' )
			: __( 'NWC wallet', 'lawallet-lightning-address' );
		if ( self::forward_enabled( $settings ) ) {
			/* translators: 1: wallet kind (disposable/permanent), 2: balance in sats. */
			$message = sprintf( __( '%1$s ready (balance %2$d sats); payments are settled there and forwarded to your Lightning Address.', 'lawallet-lightning-address' ), $kind, $sats );
		} else {
			/* translators: 1: wallet kind (disposable/permanent), 2: balance in sats. */
			$message = sprintf( __( '%1$s ready (balance %2$d sats); payments are settled there and stay in the wallet.', 'lawallet-lightning-address' ), $kind, $sats );
		}

		return array(
			'ok'           => true,
			'mode'         => $mode,
			'balance_sats' => $sats,
			'message'      => $message,
		);
	}

	/**
	 * Cached wallet balance for display (does not mint).
	 *
	 * @return array{ok:bool,sats:int,checked_at:int}
	 */
	public static function get_cached_balance( array $settings, $force = false ) {
		if ( ! self::is_enabled( $settings ) ) {
			return array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => 0,
			);
		}

		if ( ! $force ) {
			$cache = get_transient( self::BALANCE_CACHE );
			if ( is_array( $cache ) ) {
				return $cache;
			}
		}

		$client = self::balance_client( $settings );
		if ( ! ( $client instanceof WCLL_NWC_Client ) ) {
			$result = array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => time(),
			);
			set_transient( self::BALANCE_CACHE, $result, self::BALANCE_TTL );
			return $result;
		}

		$balance = $client->get_balance();
		if ( is_wp_error( $balance ) ) {
			$result = array(
				'ok'         => false,
				'sats'       => 0,
				'checked_at' => time(),
			);
		} else {
			$msat   = isset( $balance['balance'] ) ? (int) $balance['balance'] : 0;
			$result = array(
				'ok'         => true,
				'sats'       => (int) floor( $msat / 1000 ),
				'checked_at' => time(),
			);
		}
		set_transient( self::BALANCE_CACHE, $result, self::BALANCE_TTL );
		return $result;
	}

	/**
	 * Public wallet descriptor for the admin wallet panel: pubkeys + relays only,
	 * NEVER the secret. The browser uses these to subscribe to NIP-47
	 * notifications (live balance trigger); all signed operations stay on the
	 * server.
	 *
	 * @return array{configured:bool,mode:string,wallet_pubkey:string,client_pubkey:string,relays:string[]}
	 */
	public static function admin_wallet_info( array $settings ) {
		$info = array(
			'configured'    => false,
			'mode'          => self::mode( $settings ),
			'wallet_pubkey' => '',
			'client_pubkey' => '',
			'relays'        => array(),
		);

		if ( ! self::is_enabled( $settings ) ) {
			return $info;
		}

		if ( 'permanent' === $info['mode'] ) {
			$uri = self::permanent_connection();
		} else {
			$active = self::get_active_connection();
			$uri    = ! empty( $active['uri'] ) ? (string) $active['uri'] : '';
		}

		if ( '' === trim( $uri ) ) {
			return $info;
		}

		$parsed = WCLL_NWC_Client::parse_connection( $uri );
		if ( is_wp_error( $parsed ) ) {
			return $info;
		}

		$info['configured']    = true;
		$info['wallet_pubkey'] = $parsed['wallet_pubkey'];
		$info['client_pubkey'] = $parsed['client_pubkey'];
		$info['relays']        = $parsed['relays'];
		return $info;
	}

	private static function balance_client( array $settings ) {
		if ( 'permanent' === self::mode( $settings ) ) {
			$uri = self::permanent_connection();
			return '' !== trim( $uri ) ? WCLL_NWC_Client::from_uri( $uri ) : null;
		}
		$active = self::get_active_connection();
		return ! empty( $active['uri'] ) ? WCLL_NWC_Client::from_uri( $active['uri'] ) : null;
	}

	private static function provision_lncurl( $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'wcll_nwc_bad_lncurl', __( 'Set a valid lncurl service URL.', 'lawallet-lightning-address' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'text/plain' ),
				'body'    => array(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wcll_nwc_lncurl_unreachable', __( 'Could not reach the lncurl service to provision a wallet.', 'lawallet-lightning-address' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( $code < 200 || $code >= 300 || '' === $body ) {
			return new WP_Error( 'wcll_nwc_lncurl_failed', __( 'The lncurl service did not return a wallet.', 'lawallet-lightning-address' ) );
		}

		// The body is the raw nostr+walletconnect:// string (it contains the
		// secret) — it must never be logged or surfaced.
		if ( ! preg_match( '#^nostr\+walletconnect://#i', $body ) ) {
			return new WP_Error( 'wcll_nwc_lncurl_format', __( 'The lncurl service returned an unexpected response.', 'lawallet-lightning-address' ) );
		}
		return $body;
	}

	private static function get_active_connection() {
		$active = get_option( self::ACTIVE_OPTION, array() );
		return is_array( $active ) ? $active : array();
	}

	private static function get_archive() {
		$archive = get_option( self::ARCHIVE_OPTION, array() );
		return is_array( $archive ) ? $archive : array();
	}

	private static function archive_active() {
		$active = self::get_active_connection();
		if ( empty( $active['uri'] ) || empty( $active['wallet_pubkey'] ) ) {
			return;
		}

		$archive = self::get_archive();
		$archive[ strtolower( (string) $active['wallet_pubkey'] ) ] = array(
			'uri'        => $active['uri'],
			'created_at' => isset( $active['created_at'] ) ? (int) $active['created_at'] : 0,
			'retired_at' => time(),
		);

		// Cap the ring, dropping the oldest retired wallets first.
		if ( count( $archive ) > self::ARCHIVE_MAX ) {
			uasort(
				$archive,
				static function ( $a, $b ) {
					$ra = isset( $a['retired_at'] ) ? (int) $a['retired_at'] : 0;
					$rb = isset( $b['retired_at'] ) ? (int) $b['retired_at'] : 0;
					return $ra <=> $rb;
				}
			);
			$archive = array_slice( $archive, count( $archive ) - self::ARCHIVE_MAX, null, true );
		}

		update_option( self::ARCHIVE_OPTION, $archive, false );
	}

	private static function within_mint_rate() {
		$window = get_option( self::MINT_WINDOW, array() );
		if ( ! is_array( $window ) || empty( $window['start'] ) || ( time() - (int) $window['start'] ) > HOUR_IN_SECONDS ) {
			return true;
		}
		return (int) $window['count'] < self::MINT_MAX_PER_HOUR;
	}

	private static function record_mint() {
		$window = get_option( self::MINT_WINDOW, array() );
		if ( ! is_array( $window ) || empty( $window['start'] ) || ( time() - (int) $window['start'] ) > HOUR_IN_SECONDS ) {
			$window = array(
				'start' => time(),
				'count' => 0,
			);
		}
		$window['count'] = (int) $window['count'] + 1;
		update_option( self::MINT_WINDOW, $window, false );
	}

	/**
	 * One-time migration for installs predating modes (v0.3.0): a populated single
	 * connection string becomes 'permanent' so behavior is unchanged.
	 */
	public static function maybe_migrate() {
		$settings = WCLL_Gateway::get_gateway_settings();
		if ( empty( $settings ) ) {
			return;
		}

		$changed = false;

		// Move any plaintext connection secret out of the autoloaded settings array
		// into the dedicated autoload=no option. This also remediates pre-0.4.0
		// installs, where the secret was stored in the settings option directly.
		if ( isset( $settings['nwc_connection'] ) && '' !== trim( (string) $settings['nwc_connection'] ) ) {
			if ( '' === self::permanent_connection() ) {
				self::set_permanent_connection( trim( (string) $settings['nwc_connection'] ) );
			}
			$settings['nwc_connection'] = '';
			$changed                    = true;
		}

		// First run on installs predating modes: default permanent vs disposable.
		if ( ! isset( $settings['nwc_mode'] ) ) {
			$settings['nwc_mode'] = '' !== self::permanent_connection() ? 'permanent' : 'disposable';
			$changed              = true;
		}

		// First run after the explicit settlement selector (v0.5.0): map the old
		// nwc_proxy_enabled checkbox onto the new method. The old proxy always
		// forwarded to the Lightning Address, so preserve that as forward-enabled.
		if ( ! isset( $settings['settlement_method'] ) ) {
			$was_proxy                       = isset( $settings['nwc_proxy_enabled'] ) && 'yes' === $settings['nwc_proxy_enabled'];
			$settings['settlement_method']   = $was_proxy ? 'nwc' : 'lightning_address';
			$settings['nwc_forward_enabled'] = $was_proxy ? 'yes' : 'no';
			$changed                         = true;
		}

		// Second migration (v0.6.0): split the 2-value method + forward toggle into
		// the 3-mode selector. The presence of nwc_forward_enabled marks an install
		// that predates the split; removing it makes this run exactly once.
		if ( isset( $settings['nwc_forward_enabled'] ) ) {
			if ( 'nwc' === ( isset( $settings['settlement_method'] ) ? $settings['settlement_method'] : '' ) ) {
				$settings['settlement_method'] = 'yes' === $settings['nwc_forward_enabled'] ? 'nwc_proxy' : 'nwc';
			}
			unset( $settings['nwc_forward_enabled'] );
			$changed = true;

			// A pre-split terminal wallet was the proxy's permanent connection; move
			// it to the dedicated terminal store so it keeps working. (No-op in
			// practice: the split shipped before any terminal install existed.)
			if ( 'nwc' === $settings['settlement_method'] && '' === self::receiver_connection() && '' !== self::permanent_connection() ) {
				self::set_receiver_connection( self::permanent_connection() );
			}
		}

		if ( $changed ) {
			update_option( 'woocommerce_wcll_gateway_settings', $settings );
		}
	}

	private static function add_admin_notice( $message ) {
		$notices = get_option( self::NOTICES_OPTION, array() );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'message' => $message,
			'time'    => time(),
		);
		update_option( self::NOTICES_OPTION, array_slice( $notices, -10 ), false );
	}

	/**
	 * Return and clear queued NWC admin notices (shown on admin pages).
	 *
	 * @return array
	 */
	public static function take_admin_notices() {
		$notices = get_option( self::NOTICES_OPTION, array() );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return array();
		}
		delete_option( self::NOTICES_OPTION );
		return $notices;
	}
}
