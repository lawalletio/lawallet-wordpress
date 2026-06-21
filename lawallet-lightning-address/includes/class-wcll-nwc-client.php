<?php
/**
 * Minimal NWC (NIP-47, Nostr Wallet Connect) client.
 *
 * Used as a last-resort proxy settlement method when a merchant's Lightning
 * Address supports neither LUD-21 verification nor NIP-57 zap receipts. The
 * plugin invoices a disposable NWC wallet (make_invoice), verifies payment over
 * the wallet connection (lookup_invoice), then forwards the funds to the
 * merchant Lightning Address (pay_invoice).
 *
 * The connection secret never leaves the server. Only NIP-04 encryption is
 * implemented (the universal NWC baseline); a NIP-44-only wallet surfaces a
 * clear "not supported yet" error from the decrypt step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_NWC_Client {
	const KIND_REQUEST  = 23194;
	const TIMEOUT_QUERY = 10; // get_info / make_invoice / lookup_invoice.
	const TIMEOUT_PAY   = 30; // pay_invoice (routing can be slow).

	private $wallet_pubkey;
	private $client_pubkey;
	private $secret;
	private $relays;

	private function __construct( $wallet_pubkey, $secret, $client_pubkey, array $relays ) {
		$this->wallet_pubkey = $wallet_pubkey;
		$this->secret        = $secret;
		$this->client_pubkey = $client_pubkey;
		$this->relays        = $relays;
	}

	/**
	 * Build a client from a nostr+walletconnect:// connection URI.
	 *
	 * @return WCLL_NWC_Client|WP_Error
	 */
	public static function from_uri( $uri ) {
		$parsed = self::parse_connection( $uri );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return new self( $parsed['wallet_pubkey'], $parsed['secret'], $parsed['client_pubkey'], $parsed['relays'] );
	}

	/**
	 * Parse + validate a connection URI without instantiating.
	 *
	 * @return array{wallet_pubkey:string,secret:string,client_pubkey:string,relays:string[]}|WP_Error
	 */
	public static function parse_connection( $uri ) {
		$uri = trim( (string) $uri );
		if ( '' === $uri ) {
			return new WP_Error( 'wcll_nwc_empty', __( 'Enter an NWC connection string.', 'lawallet-lightning-address' ) );
		}
		if ( ! preg_match( '#^nostr\+walletconnect://#i', $uri ) ) {
			return new WP_Error( 'wcll_nwc_scheme', __( 'The NWC connection must start with nostr+walletconnect://', 'lawallet-lightning-address' ) );
		}

		// The wallet pubkey is the URI "host": the part between :// and ?.
		$rest          = preg_replace( '#^nostr\+walletconnect://#i', '', $uri );
		$query_pos     = strpos( $rest, '?' );
		$wallet_pubkey = strtolower( trim( false === $query_pos ? $rest : substr( $rest, 0, $query_pos ) ) );
		$query         = false === $query_pos ? '' : substr( $rest, $query_pos + 1 );

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $wallet_pubkey ) ) {
			return new WP_Error( 'wcll_nwc_pubkey', __( 'The NWC connection has an invalid wallet public key.', 'lawallet-lightning-address' ) );
		}

		// Walk the raw pairs so repeated relay= params are all kept (parse_str
		// would collapse them).
		$relays = array();
		$secret = '';
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$kv    = explode( '=', $pair, 2 );
			$key   = strtolower( $kv[0] );
			$value = isset( $kv[1] ) ? urldecode( $kv[1] ) : '';
			if ( 'relay' === $key && '' !== $value ) {
				$relays[] = $value;
			} elseif ( 'secret' === $key ) {
				$secret = strtolower( trim( $value ) );
			}
		}

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $secret ) ) {
			return new WP_Error( 'wcll_nwc_secret', __( 'The NWC connection has an invalid secret.', 'lawallet-lightning-address' ) );
		}
		if ( empty( $relays ) ) {
			return new WP_Error( 'wcll_nwc_relay', __( 'The NWC connection is missing a relay.', 'lawallet-lightning-address' ) );
		}

		$client_pubkey = WCLL_Nostr::pubkey_from_secret( $secret );
		if ( is_wp_error( $client_pubkey ) ) {
			return $client_pubkey;
		}

		return array(
			'wallet_pubkey' => $wallet_pubkey,
			'secret'        => $secret,
			'client_pubkey' => $client_pubkey,
			'relays'        => array_values( array_unique( $relays ) ),
		);
	}

	public function wallet_pubkey() {
		return $this->wallet_pubkey;
	}

	public function client_pubkey() {
		return $this->client_pubkey;
	}

	public function relays() {
		return $this->relays;
	}

	/**
	 * Replace the transport relays (e.g. a container-reachable dev address)
	 * without changing the connection's pubkeys or secret.
	 */
	public function with_relays( array $relays ) {
		$relays = array_values( array_filter( array_map( 'trim', $relays ) ) );
		if ( ! empty( $relays ) ) {
			$this->relays = $relays;
		}
		return $this;
	}

	/**
	 * Send an NWC request and return the decrypted `result` array, or WP_Error.
	 */
	public function request( $method, array $params = array(), $timeout = self::TIMEOUT_QUERY ) {
		if ( ! WCLL_Nostr::can_sign() ) {
			return new WP_Error( 'wcll_nwc_no_crypto', __( 'NWC needs the PHP GMP extension to sign requests.', 'lawallet-lightning-address' ) );
		}

		$payload = wp_json_encode(
			array(
				'method' => $method,
				'params' => (object) $params,
			)
		);
		$content = WCLL_Nostr::nip04_encrypt( $payload, $this->secret, $this->wallet_pubkey );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$event  = array(
			'pubkey'     => $this->client_pubkey,
			'created_at' => time(),
			'kind'       => self::KIND_REQUEST,
			'tags'       => array( array( 'p', $this->wallet_pubkey ) ),
			'content'    => $content,
		);
		$signed = WCLL_Nostr::sign_event_with_key( $event, $this->secret );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}

		$response = WCLL_Nostr_Relay::nwc_request(
			$this->relays,
			$signed,
			$this->wallet_pubkey,
			$this->client_pubkey,
			$signed['id'],
			$timeout
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$plain = WCLL_Nostr::nip04_decrypt( $response['content'], $this->secret, $this->wallet_pubkey );
		if ( is_wp_error( $plain ) ) {
			return $plain;
		}

		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wcll_nwc_bad_response', __( 'The NWC wallet returned an unreadable response.', 'lawallet-lightning-address' ) );
		}

		if ( ! empty( $data['error'] ) && is_array( $data['error'] ) ) {
			$code = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : 'UNKNOWN';
			$msg  = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			/* translators: 1: NWC error code, 2: wallet error message. */
			return new WP_Error( 'wcll_nwc_wallet_error', trim( sprintf( __( 'NWC wallet error %1$s: %2$s', 'lawallet-lightning-address' ), $code, $msg ) ), $data['error'] );
		}

		return isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();
	}

	public function get_info() {
		return $this->request( 'get_info', array(), self::TIMEOUT_QUERY );
	}

	public function get_balance() {
		return $this->request( 'get_balance', array(), self::TIMEOUT_QUERY );
	}

	/**
	 * Create an invoice on the proxy wallet for $amount_msat millisatoshis.
	 *
	 * @return array|WP_Error Normalized invoice (see normalize_invoice()).
	 */
	public function make_invoice( $amount_msat, $description = '', $expiry = 3600 ) {
		$params = array( 'amount' => (int) $amount_msat );
		if ( '' !== (string) $description ) {
			$params['description'] = (string) $description;
		}
		if ( (int) $expiry > 0 ) {
			$params['expiry'] = (int) $expiry;
		}

		$result = $this->request( 'make_invoice', $params, self::TIMEOUT_QUERY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::normalize_invoice( $result );
	}

	/**
	 * @return array|WP_Error Normalized invoice; `settled` reflects payment state.
	 */
	public function lookup_invoice( $payment_hash, $invoice = '' ) {
		$params = array();
		if ( '' !== (string) $payment_hash ) {
			$params['payment_hash'] = (string) $payment_hash;
		}
		if ( '' !== (string) $invoice ) {
			$params['invoice'] = (string) $invoice;
		}
		if ( empty( $params ) ) {
			return new WP_Error( 'wcll_nwc_lookup_args', __( 'lookup_invoice needs a payment hash or invoice.', 'lawallet-lightning-address' ) );
		}

		$result = $this->request( 'lookup_invoice', $params, self::TIMEOUT_QUERY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::normalize_invoice( $result );
	}

	/**
	 * Pay a BOLT11 invoice from the proxy wallet.
	 *
	 * @return array{preimage:string,fees_paid:int}|WP_Error
	 */
	public function pay_invoice( $bolt11, $amount_msat = null ) {
		$params = array( 'invoice' => (string) $bolt11 );
		if ( null !== $amount_msat ) {
			$params['amount'] = (int) $amount_msat;
		}

		$result = $this->request( 'pay_invoice', $params, self::TIMEOUT_PAY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'preimage'  => isset( $result['preimage'] ) ? (string) $result['preimage'] : '',
			'fees_paid' => isset( $result['fees_paid'] ) ? (int) $result['fees_paid'] : 0,
		);
	}

	/**
	 * Normalize a make_invoice/lookup_invoice result into a stable shape.
	 */
	private static function normalize_invoice( array $result ) {
		$bolt11 = '';
		foreach ( array( 'invoice', 'bolt11', 'pr' ) as $key ) {
			if ( ! empty( $result[ $key ] ) ) {
				$bolt11 = (string) $result[ $key ];
				break;
			}
		}

		$settled = false;
		if ( ! empty( $result['settled_at'] ) ) {
			$settled = true;
		}
		if ( isset( $result['state'] ) && in_array( strtolower( (string) $result['state'] ), array( 'settled', 'paid', 'complete' ), true ) ) {
			$settled = true;
		}
		if ( ! empty( $result['paid'] ) ) {
			$settled = true;
		}

		return array(
			'invoice'      => $bolt11,
			'payment_hash' => isset( $result['payment_hash'] ) ? (string) $result['payment_hash'] : '',
			'preimage'     => isset( $result['preimage'] ) ? (string) $result['preimage'] : '',
			'amount'       => isset( $result['amount'] ) ? (int) $result['amount'] : 0,
			'settled'      => $settled,
			'state'        => isset( $result['state'] ) ? (string) $result['state'] : '',
			'raw'          => $result,
		);
	}
}
