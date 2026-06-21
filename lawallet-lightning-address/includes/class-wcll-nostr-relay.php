<?php
/**
 * Minimal server-side Nostr relay client used to confirm Lightning settlement
 * via NIP-57 zap receipts when a LUD-21 verify URL is not available.
 *
 * Nostr relays speak WebSocket only, so this opens a raw socket, performs the
 * WebSocket handshake, sends a single REQ for kind 9735 events authored by the
 * Lightning service, and looks for a receipt whose `bolt11` tag matches the
 * order's invoice. A candidate is only trusted after its event id is recomputed
 * and its schnorr signature is verified against the wallet's pubkey, so a forged
 * event on an open relay can never settle an order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Nostr_Relay {
	const MAX_FRAME_BYTES = 262144; // 256 KB — far larger than any zap receipt.
	const TOTAL_BUDGET    = 12;     // Seconds across all relays.
	const PER_RELAY       = 5;      // Seconds per relay.
	const CLOCK_SKEW      = 900;    // Allowed future drift for created_at.

	/**
	 * Look for a signed zap receipt that settles $invoice across the given relays.
	 *
	 * @return array{settled:bool,preimage:string,payload:array}|WP_Error
	 */
	public static function fetch_zap_receipt( array $relays, $invoice, $author_pubkey, $since = 0 ) {
		$invoice = trim( (string) $invoice );
		$author  = strtolower( trim( (string) $author_pubkey ) );

		if ( '' === $invoice || ! preg_match( '/^[0-9a-f]{64}$/', $author ) ) {
			return new WP_Error( 'wcll_zap_bad_args', __( 'Missing invoice or Nostr public key for zap receipt verification.', 'lawallet-lightning-address' ) );
		}

		$since  = (int) $since;
		$filter = array(
			'kinds'   => array( 9735 ),
			'authors' => array( $author ),
			'limit'   => 50,
		);
		if ( $since > 0 ) {
			$filter['since'] = $since;
		}

		$deadline = time() + self::TOTAL_BUDGET;
		$reached  = false;
		$count    = 0;

		foreach ( $relays as $relay ) {
			$relay = trim( (string) $relay );
			if ( '' === $relay || ! preg_match( '#^wss?://#i', $relay ) || $count >= 5 ) {
				continue;
			}

			$remaining = $deadline - time();
			if ( $remaining <= 0 ) {
				break;
			}
			++$count;

			$result = self::query_relay( $relay, $filter, $invoice, $author, $since, min( self::PER_RELAY, $remaining ) );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$reached = true;
			if ( ! empty( $result['settled'] ) ) {
				return $result;
			}
		}

		if ( ! $reached ) {
			return new WP_Error( 'wcll_zap_no_relay', __( 'Could not reach any Nostr relay to verify the zap receipt.', 'lawallet-lightning-address' ) );
		}

		return array(
			'settled'  => false,
			'preimage' => '',
			'payload'  => array(),
		);
	}

	/**
	 * Open a TCP/TLS socket to a relay and perform the WebSocket handshake.
	 *
	 * @return array{0:resource,1:int}|WP_Error  [ $socket, $deadline ] on success.
	 */
	private static function connect_and_handshake( $relay, $timeout ) {
		$timeout = max( 1, (int) $timeout );
		$parts   = wp_parse_url( $relay );
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'wcll_relay_bad', 'Invalid relay URL.' );
		}

		$secure = isset( $parts['scheme'] ) && 'wss' === strtolower( $parts['scheme'] );
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( $secure ? 443 : 80 );
		$path   = ! empty( $parts['path'] ) ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}

		$transport = $secure ? 'ssl' : 'tcp';
		$context   = stream_context_create();
		$errno     = 0;
		$errstr    = '';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.stream_socket_client_stream_socket_client -- Nostr relays are WebSocket-only; the WP HTTP API cannot open a relay subscription.
		$socket = @stream_socket_client( $transport . '://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context );
		if ( ! $socket ) {
			return new WP_Error( 'wcll_relay_connect', sprintf( 'Could not connect to %s: %s', $relay, $errstr ) );
		}
		stream_set_timeout( $socket, $timeout );

		$deadline  = time() + $timeout;
		$key       = base64_encode( random_bytes( 16 ) );
		$handshake = 'GET ' . $path . " HTTP/1.1\r\n"
			. 'Host: ' . $host . ':' . $port . "\r\n"
			. "User-Agent: WCLL-Nostr/1.0\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. 'Sec-WebSocket-Key: ' . $key . "\r\n"
			. "Sec-WebSocket-Version: 13\r\n\r\n";
		self::socket_write( $socket, $handshake );

		$response = '';
		while ( ! feof( $socket ) && time() < $deadline ) {
			$line = fgets( $socket, 1024 );
			if ( false === $line ) {
				break;
			}
			$response .= $line;
			if ( "\r\n" === $line || "\n" === $line ) {
				break;
			}
		}
		if ( false === strpos( $response, ' 101 ' ) ) {
			self::socket_close( $socket );
			return new WP_Error( 'wcll_relay_handshake', 'WebSocket handshake failed.' );
		}

		return array( $socket, $deadline );
	}

	private static function socket_write( $socket, $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to a network socket, not a file; WP_Filesystem cannot operate on a stream_socket_client handle.
		return @fwrite( $socket, $data );
	}

	private static function socket_close( $socket ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a network socket, not a file; WP_Filesystem cannot operate on a stream_socket_client handle.
		fclose( $socket );
	}

	private static function query_relay( $relay, array $filter, $invoice, $author, $since, $timeout ) {
		$conn = self::connect_and_handshake( $relay, $timeout );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		list( $socket, $deadline ) = $conn;

		$sub_id = 'wcll' . bin2hex( random_bytes( 4 ) );
		self::socket_write( $socket, self::encode_frame( (string) wp_json_encode( array( 'REQ', $sub_id, $filter ) ) ) );

		$settled  = false;
		$preimage = '';
		$payload  = array();

		while ( time() < $deadline ) {
			$frame = self::read_frame( $socket, $deadline );
			if ( null === $frame ) {
				break;
			}
			if ( '' === $frame ) {
				continue;
			}

			$message = json_decode( $frame, true );
			if ( ! is_array( $message ) || ! isset( $message[0] ) ) {
				continue;
			}

			if ( 'EVENT' === $message[0] && isset( $message[2] ) && is_array( $message[2] ) ) {
				$event = $message[2];
				if ( self::receipt_matches( $event, $invoice, $author, $since ) ) {
					$settled  = true;
					$preimage = self::tag_value( $event, 'preimage' );
					$payload  = $event;
					break;
				}
			} elseif ( 'EOSE' === $message[0] ) {
				break;
			}
		}

		// Best-effort close; ignore failures on an already-closing socket.
		self::socket_write( $socket, self::encode_frame( (string) wp_json_encode( array( 'CLOSE', $sub_id ) ) ) );
		self::socket_close( $socket );

		return array(
			'settled'  => $settled,
			'preimage' => $preimage,
			'payload'  => $payload,
		);
	}

	/**
	 * Publish a signed NWC (NIP-47) request event and await the correlated
	 * kind-23195 response from the wallet over the same connection.
	 *
	 * @return array The raw, signature-verified response event, or WP_Error.
	 */
	public static function nwc_request( array $relays, array $signed_event, $wallet_pubkey, $client_pubkey, $request_id, $timeout = self::PER_RELAY ) {
		$wallet_pubkey = strtolower( (string) $wallet_pubkey );
		$client_pubkey = strtolower( (string) $client_pubkey );
		$request_id    = strtolower( (string) $request_id );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $wallet_pubkey ) || ! preg_match( '/^[0-9a-f]{64}$/', $request_id ) ) {
			return new WP_Error( 'wcll_nwc_bad_args', 'Invalid NWC request parameters.' );
		}

		$filter = array(
			'kinds'   => array( 23195 ),
			'authors' => array( $wallet_pubkey ),
			'#e'      => array( $request_id ),
			'limit'   => 1,
		);
		if ( '' !== $client_pubkey ) {
			$filter['#p'] = array( $client_pubkey );
		}

		$reached = false;
		$count   = 0;
		foreach ( $relays as $relay ) {
			$relay = trim( (string) $relay );
			if ( '' === $relay || ! preg_match( '#^wss?://#i', $relay ) || $count >= 5 ) {
				continue;
			}
			++$count;

			$conn = self::connect_and_handshake( $relay, $timeout );
			if ( is_wp_error( $conn ) ) {
				continue;
			}
			list( $socket, $deadline ) = $conn;
			$reached = true;

			$sub_id = 'wcll' . bin2hex( random_bytes( 4 ) );
			// Subscribe for the response first, then publish the request so the
			// reply cannot race ahead of our subscription.
			self::socket_write( $socket, self::encode_frame( (string) wp_json_encode( array( 'REQ', $sub_id, $filter ) ) ) );
			self::socket_write( $socket, self::encode_frame( (string) wp_json_encode( array( 'EVENT', $signed_event ) ) ) );

			$response = null;
			while ( time() < $deadline ) {
				$frame = self::read_frame( $socket, $deadline );
				if ( null === $frame ) {
					break;
				}
				if ( '' === $frame ) {
					continue;
				}

				$message = json_decode( $frame, true );
				if ( ! is_array( $message ) || ! isset( $message[0] ) ) {
					continue;
				}

				// The reply usually arrives live after EOSE, so do not stop on EOSE.
				if ( 'EVENT' === $message[0] && isset( $message[2] ) && is_array( $message[2] ) ) {
					$event = $message[2];
					if ( self::nwc_response_matches( $event, $wallet_pubkey, $request_id ) ) {
						$response = $event;
						break;
					}
				}
			}

			self::socket_write( $socket, self::encode_frame( (string) wp_json_encode( array( 'CLOSE', $sub_id ) ) ) );
			self::socket_close( $socket );

			if ( null !== $response ) {
				return $response;
			}
		}

		if ( ! $reached ) {
			return new WP_Error( 'wcll_nwc_no_relay', __( 'Could not reach the NWC wallet relay.', 'lawallet-lightning-address' ) );
		}

		return new WP_Error( 'wcll_nwc_no_response', __( 'The NWC wallet did not respond in time.', 'lawallet-lightning-address' ) );
	}

	private static function nwc_response_matches( array $event, $wallet_pubkey, $request_id ) {
		if ( ! isset( $event['kind'] ) || 23195 !== (int) $event['kind'] ) {
			return false;
		}
		if ( ! isset( $event['pubkey'] ) || strtolower( (string) $event['pubkey'] ) !== $wallet_pubkey ) {
			return false;
		}
		if ( ! hash_equals( $request_id, self::tag_value( $event, 'e' ) ) ) {
			return false;
		}

		// Only a genuinely-signed response from the wallet pubkey is trusted.
		return WCLL_Nostr::verify_event( $event );
	}

	private static function receipt_matches( array $event, $invoice, $author, $since ) {
		if ( ! isset( $event['kind'] ) || 9735 !== (int) $event['kind'] ) {
			return false;
		}
		if ( ! isset( $event['pubkey'] ) || strtolower( (string) $event['pubkey'] ) !== $author ) {
			return false;
		}
		if ( ! hash_equals( $invoice, self::tag_value( $event, 'bolt11' ) ) ) {
			return false;
		}

		// Bind the receipt to the order's time window (defense in depth).
		$created = isset( $event['created_at'] ) ? (int) $event['created_at'] : 0;
		if ( $created <= 0 || ( $since > 0 && $created < $since ) || $created > ( time() + self::CLOCK_SKEW ) ) {
			return false;
		}

		// Critical: only a genuinely-signed event from the wallet's pubkey can
		// settle an order. Without this, a forged event published to an open
		// relay would mark the order paid.
		return WCLL_Nostr::verify_event( $event );
	}

	private static function tag_value( array $event, $name ) {
		if ( empty( $event['tags'] ) || ! is_array( $event['tags'] ) ) {
			return '';
		}
		foreach ( $event['tags'] as $tag ) {
			if ( is_array( $tag ) && isset( $tag[0], $tag[1] ) && $name === $tag[0] ) {
				return (string) $tag[1];
			}
		}
		return '';
	}

	private static function encode_frame( $payload ) {
		$length = strlen( $payload );
		$mask   = random_bytes( 4 );
		$frame  = chr( 0x81 ); // FIN + text opcode.

		if ( $length < 126 ) {
			$frame .= chr( 0x80 | $length );
		} elseif ( $length < 65536 ) {
			$frame .= chr( 0x80 | 126 ) . pack( 'n', $length );
		} else {
			$frame .= chr( 0x80 | 127 ) . pack( 'J', $length );
		}

		$frame .= $mask;
		for ( $i = 0; $i < $length; $i++ ) {
			$frame .= $payload[ $i ] ^ $mask[ $i % 4 ];
		}

		return $frame;
	}

	private static function read_frame( $socket, $deadline ) {
		$header = self::read_bytes( $socket, 2, $deadline );
		if ( null === $header || strlen( $header ) < 2 ) {
			return null;
		}

		$opcode = ord( $header[0] ) & 0x0f;
		$b1     = ord( $header[1] );
		$masked = ( $b1 & 0x80 ) !== 0;
		$length = $b1 & 0x7f;

		if ( 126 === $length ) {
			$ext = self::read_bytes( $socket, 2, $deadline );
			if ( null === $ext ) {
				return null;
			}
			$length = unpack( 'n', $ext )[1];
		} elseif ( 127 === $length ) {
			$ext = self::read_bytes( $socket, 8, $deadline );
			if ( null === $ext ) {
				return null;
			}
			$length = unpack( 'J', $ext )[1];
		}

		// Reject oversized or overflowed (negative) frame lengths from a hostile relay.
		if ( $length < 0 || $length > self::MAX_FRAME_BYTES ) {
			return null;
		}

		$mask = '';
		if ( $masked ) {
			$mask = self::read_bytes( $socket, 4, $deadline );
			if ( null === $mask ) {
				return null;
			}
		}

		$data = ( $length > 0 ) ? self::read_bytes( $socket, $length, $deadline ) : '';
		if ( null === $data ) {
			return null;
		}

		if ( 0x8 === $opcode ) { // Close frame.
			return null;
		}
		if ( 0x9 === $opcode || 0xA === $opcode ) { // Ping / pong: ignore.
			return '';
		}

		if ( $masked && '' !== $data ) {
			for ( $i = 0; $i < $length; $i++ ) {
				$data[ $i ] = $data[ $i ] ^ $mask[ $i % 4 ];
			}
		}

		return $data;
	}

	private static function read_bytes( $socket, $length, $deadline ) {
		$data      = '';
		$remaining = (int) $length;

		while ( $remaining > 0 ) {
			if ( time() >= $deadline ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from a network socket, not a file; WP_Filesystem cannot operate on a stream_socket_client handle.
			$chunk = fread( $socket, $remaining );
			if ( false === $chunk || '' === $chunk ) {
				$meta = stream_get_meta_data( $socket );
				if ( ! empty( $meta['timed_out'] ) || feof( $socket ) ) {
					return null;
				}
				continue;
			}
			$data      .= $chunk;
			$remaining -= strlen( $chunk );
		}

		return $data;
	}
}
