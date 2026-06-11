<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_LNURL_Client {
	private $settings;

	public function __construct( array $settings = array() ) {
		$this->settings = $settings;
	}

	public function resolve_lightning_address( $address ) {
		$address = trim( strtolower( (string) $address ) );
		if ( ! preg_match( '/^([^@\s]+)@([^@\s]+)$/', $address, $matches ) ) {
			return new WP_Error( 'wcll_invalid_lightning_address', __( 'Enter a Lightning Address like name@example.com.', 'lawallet-wordpress' ) );
		}

		$name   = rawurlencode( $matches[1] );
		$domain = $matches[2];
		$scheme = $this->should_use_http( $domain ) ? 'http' : 'https';
		$url    = $scheme . '://' . $domain . '/.well-known/lnurlp/' . $name;

		$payload = $this->get_json( $url );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$valid = $this->validate_pay_request( $payload );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$payload['lnurl_pay_url']     = $url;
		$payload['lightning_address'] = $address;
		$payload['domain']            = $domain;

		return $payload;
	}

	public function test_lud21( $address ) {
		$pay_request = $this->resolve_lightning_address( $address );
		if ( is_wp_error( $pay_request ) ) {
			return $pay_request;
		}

		$amount_msat = max( 1000, (int) $pay_request['minSendable'] );
		if ( $amount_msat > (int) $pay_request['maxSendable'] ) {
			return new WP_Error( 'wcll_amount_out_of_range', __( 'The Lightning Address minimum amount is higher than its maximum amount.', 'lawallet-wordpress' ) );
		}

		$invoice = $this->request_invoice(
			$pay_request,
			$amount_msat,
			array(
				'description' => 'WooCommerce Lightning setup check',
				'use_nostr'   => false,
			)
		);

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( empty( $invoice['verify'] ) ) {
			return new WP_Error( 'wcll_lud21_missing', __( 'This Lightning Address can create invoices, but the callback did not return a LUD-21 verify URL.', 'lawallet-wordpress' ) );
		}

		return array(
			'pay_request' => $pay_request,
			'invoice'     => $invoice,
		);
	}

	public function request_invoice( array $pay_request, $amount_msat, array $context = array() ) {
		$amount_msat = (int) $amount_msat;
		if ( $amount_msat < (int) $pay_request['minSendable'] || $amount_msat > (int) $pay_request['maxSendable'] ) {
			return new WP_Error( 'wcll_amount_out_of_range', __( 'Order amount is outside the Lightning Address sendable range.', 'lawallet-wordpress' ) );
		}

		$params = array(
			'amount' => $amount_msat,
		);

		$nostr = array();
		if ( ! isset( $context['use_nostr'] ) || $context['use_nostr'] ) {
			$nostr = $this->maybe_build_zap_request( $pay_request, $amount_msat, $context );
			if ( ! is_wp_error( $nostr ) && ! empty( $nostr['event'] ) ) {
				$params['nostr'] = wp_json_encode( $nostr['event'] );
				$params['lnurl'] = $nostr['lnurl'];
			}
		}

		$url      = $this->build_callback_url( $pay_request['callback'], $params );
		$response = $this->get_json( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['status'] ) && 'ERROR' === strtoupper( (string) $response['status'] ) ) {
			$reason = ! empty( $response['reason'] ) ? $response['reason'] : __( 'LNURL callback returned an error.', 'lawallet-wordpress' );
			return new WP_Error( 'wcll_callback_error', sanitize_text_field( $reason ) );
		}

		if ( empty( $response['pr'] ) || ! is_string( $response['pr'] ) ) {
			return new WP_Error( 'wcll_invoice_missing', __( 'LNURL callback did not return a Lightning invoice.', 'lawallet-wordpress' ) );
		}

		if ( empty( $response['verify'] ) || ! is_string( $response['verify'] ) ) {
			return new WP_Error( 'wcll_verify_missing', __( 'LNURL callback did not return the required LUD-21 verify URL.', 'lawallet-wordpress' ) );
		}

		$response['nostr'] = is_wp_error( $nostr ) ? array( 'error' => $nostr->get_error_message() ) : $nostr;
		return $response;
	}

	public function verify_invoice( $verify_url, $invoice ) {
		$payload = $this->get_json( $verify_url );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( ! empty( $payload['status'] ) && 'ERROR' === strtoupper( (string) $payload['status'] ) ) {
			$reason = ! empty( $payload['reason'] ) ? $payload['reason'] : __( 'LUD-21 verification failed.', 'lawallet-wordpress' );
			return new WP_Error( 'wcll_verify_error', sanitize_text_field( $reason ) );
		}

		if ( isset( $payload['pr'] ) && is_string( $payload['pr'] ) && ! hash_equals( $invoice, $payload['pr'] ) ) {
			return new WP_Error( 'wcll_verify_invoice_mismatch', __( 'LUD-21 verification returned a different invoice.', 'lawallet-wordpress' ) );
		}

		return array(
			'settled'  => ! empty( $payload['settled'] ),
			'preimage' => isset( $payload['preimage'] ) && is_string( $payload['preimage'] ) ? $payload['preimage'] : '',
			'payload'  => $payload,
		);
	}

	public static function parse_relays( $value ) {
		if ( is_array( $value ) ) {
			$candidates = $value;
		} else {
			$candidates = preg_split( '/[\r\n,\s]+/', (string) $value );
		}

		$relays = array();
		foreach ( $candidates as $relay ) {
			$relay = trim( (string) $relay );
			if ( '' === $relay || ! preg_match( '#^wss?://#i', $relay ) ) {
				continue;
			}
			$relays[] = esc_url_raw( $relay, array( 'ws', 'wss' ) );
		}

		return array_values( array_unique( $relays ) );
	}

	public static function bech32_lnurl( $url ) {
		$data = self::convert_bits( array_values( unpack( 'C*', $url ) ), 8, 5, true );
		if ( false === $data ) {
			return '';
		}

		return self::bech32_encode( 'lnurl', $data );
	}

	private function validate_pay_request( array $payload ) {
		if ( ! empty( $payload['status'] ) && 'ERROR' === strtoupper( (string) $payload['status'] ) ) {
			$reason = ! empty( $payload['reason'] ) ? $payload['reason'] : __( 'Lightning Address returned an error.', 'lawallet-wordpress' );
			return new WP_Error( 'wcll_pay_request_error', sanitize_text_field( $reason ) );
		}

		foreach ( array( 'callback', 'minSendable', 'maxSendable', 'metadata', 'tag' ) as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				return new WP_Error( 'wcll_pay_request_invalid', sprintf( 'LNURL-pay response is missing %s.', $field ) );
			}
		}

		if ( 'payRequest' !== $payload['tag'] ) {
			return new WP_Error( 'wcll_not_pay_request', __( 'Lightning Address did not return an LNURL-pay request.', 'lawallet-wordpress' ) );
		}

		if ( (int) $payload['minSendable'] < 1 || (int) $payload['maxSendable'] < (int) $payload['minSendable'] ) {
			return new WP_Error( 'wcll_range_invalid', __( 'Lightning Address returned an invalid sendable range.', 'lawallet-wordpress' ) );
		}

		if ( empty( $payload['callback'] ) || ! $this->is_valid_service_url( $payload['callback'] ) ) {
			return new WP_Error( 'wcll_callback_invalid', __( 'Lightning Address returned an invalid callback URL.', 'lawallet-wordpress' ) );
		}

		return true;
	}

	private function maybe_build_zap_request( array $pay_request, $amount_msat, array $context ) {
		if ( empty( $pay_request['allowsNostr'] ) || empty( $pay_request['nostrPubkey'] ) ) {
			return array();
		}

		if ( ! WCLL_Nostr::can_sign() ) {
			return new WP_Error( 'wcll_nostr_unavailable', __( 'NIP-57 signing needs the PHP GMP extension.', 'lawallet-wordpress' ) );
		}

		$relays = self::parse_relays( isset( $this->settings['nostr_relays'] ) ? $this->settings['nostr_relays'] : '' );
		if ( empty( $relays ) ) {
			return array();
		}

		$lnurl = self::bech32_lnurl( $pay_request['lnurl_pay_url'] );
		if ( empty( $lnurl ) ) {
			return array();
		}

		return WCLL_Nostr::create_zap_request(
			(string) $pay_request['nostrPubkey'],
			$amount_msat,
			$lnurl,
			$relays,
			isset( $context['description'] ) ? (string) $context['description'] : ''
		);
	}

	private function get_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => ! $this->is_insecure_allowed_for_url( $url ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wcll_http_error', sprintf( 'Request to %s failed with HTTP %d.', esc_url_raw( $url ), $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wcll_bad_json', __( 'Lightning service returned invalid JSON.', 'lawallet-wordpress' ) );
		}

		return $data;
	}

	private function build_callback_url( $callback, array $params ) {
		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		return $callback . ( false === strpos( $callback, '?' ) ? '?' : '&' ) . $query;
	}

	private function is_valid_service_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $scheme || ! $host || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( 'http' === strtolower( $scheme ) ) {
			return $this->should_use_http( $host );
		}

		return (bool) wp_http_validate_url( $url );
	}

	private function should_use_http( $domain ) {
		if ( ! empty( $this->settings['allow_insecure_http'] ) && 'yes' === $this->settings['allow_insecure_http'] ) {
			return true;
		}

		return preg_match( '/(^localhost(:\d+)?$|^127\.0\.0\.1(:\d+)?$|^mock-lnurl(:\d+)?$)/', $domain );
	}

	private function is_insecure_allowed_for_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && $this->should_use_http( $host );
	}

	private static function bech32_encode( $hrp, array $data ) {
		$charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
		$values  = array_merge( $data, self::bech32_create_checksum( $hrp, $data ) );
		$result  = strtolower( $hrp ) . '1';

		foreach ( $values as $value ) {
			if ( $value < 0 || $value > 31 ) {
				return '';
			}
			$result .= $charset[ $value ];
		}

		return strtoupper( $result );
	}

	private static function bech32_create_checksum( $hrp, array $data ) {
		$values  = array_merge( self::bech32_hrp_expand( $hrp ), $data, array( 0, 0, 0, 0, 0, 0 ) );
		$polymod = self::bech32_polymod( $values ) ^ 1;
		$checksum = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$checksum[] = ( $polymod >> ( 5 * ( 5 - $i ) ) ) & 31;
		}

		return $checksum;
	}

	private static function bech32_hrp_expand( $hrp ) {
		$hrp    = strtolower( $hrp );
		$values = array();
		$length = strlen( $hrp );

		for ( $i = 0; $i < $length; $i++ ) {
			$values[] = ord( $hrp[ $i ] ) >> 5;
		}

		$values[] = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$values[] = ord( $hrp[ $i ] ) & 31;
		}

		return $values;
	}

	private static function bech32_polymod( array $values ) {
		$generators = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
		$chk        = 1;

		foreach ( $values as $value ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= $generators[ $i ];
				}
			}
		}

		return $chk;
	}

	private static function convert_bits( array $data, $from_bits, $to_bits, $pad = true ) {
		$acc     = 0;
		$bits    = 0;
		$ret     = array();
		$maxv    = ( 1 << $to_bits ) - 1;
		$max_acc = ( 1 << ( $from_bits + $to_bits - 1 ) ) - 1;

		foreach ( $data as $value ) {
			if ( $value < 0 || ( $value >> $from_bits ) ) {
				return false;
			}
			$acc  = ( ( $acc << $from_bits ) | $value ) & $max_acc;
			$bits += $from_bits;
			while ( $bits >= $to_bits ) {
				$bits  -= $to_bits;
				$ret[] = ( $acc >> $bits ) & $maxv;
			}
		}

		if ( $pad ) {
			if ( $bits ) {
				$ret[] = ( $acc << ( $to_bits - $bits ) ) & $maxv;
			}
		} elseif ( $bits >= $from_bits || ( ( $acc << ( $to_bits - $bits ) ) & $maxv ) ) {
			return false;
		}

		return $ret;
	}
}
