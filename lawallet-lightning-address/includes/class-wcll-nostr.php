<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Nostr {
	private static $p;
	private static $n;
	private static $gx;
	private static $gy;

	public static function can_sign() {
		return extension_loaded( 'gmp' );
	}

	public static function create_zap_request( $recipient_pubkey, $amount_msat, $lnurl, array $relays, $content = '' ) {
		if ( ! self::can_sign() ) {
			return new WP_Error( 'wcll_gmp_missing', __( 'PHP GMP extension is required to sign NIP-57 zap requests.', 'lawallet-lightning-address' ) );
		}

		$recipient_pubkey = strtolower( trim( $recipient_pubkey ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $recipient_pubkey ) ) {
			return new WP_Error( 'wcll_bad_nostr_pubkey', __( 'Lightning Address returned an invalid Nostr public key.', 'lawallet-lightning-address' ) );
		}

		$private_key = self::random_private_key();
		$pubkey      = self::public_key_from_private_key( $private_key );
		if ( is_wp_error( $pubkey ) ) {
			return $pubkey;
		}

		$tags = array(
			array_merge( array( 'relays' ), array_values( $relays ) ),
			array( 'amount', (string) (int) $amount_msat ),
			array( 'lnurl', $lnurl ),
			array( 'p', $recipient_pubkey ),
		);

		$event = array(
			'kind'       => 9734,
			'content'    => $content,
			'tags'       => $tags,
			'pubkey'     => $pubkey,
			'created_at' => time(),
		);

		$signed = self::sign_event( $event, $private_key );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}

		return array(
			'event'       => $signed,
			'lnurl'       => $lnurl,
			'relays'      => $relays,
			'sender'      => $pubkey,
			'recipient'   => $recipient_pubkey,
			'amount_msat' => (int) $amount_msat,
		);
	}

	private static function sign_event( array $event, $private_key_hex ) {
		$serialized = self::event_serialization( $event );
		if ( false === $serialized ) {
			return new WP_Error( 'wcll_event_json_error', __( 'Could not serialize Nostr zap request.', 'lawallet-lightning-address' ) );
		}

		$id  = hash( 'sha256', $serialized );
		$sig = self::schnorr_sign( hex2bin( $id ), $private_key_hex );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}

		$event['id']  = $id;
		$event['sig'] = $sig;
		return $event;
	}

	private static function event_serialization( array $event ) {
		return json_encode(
			array(
				0,
				$event['pubkey'],
				(int) $event['created_at'],
				(int) $event['kind'],
				$event['tags'],
				(string) $event['content'],
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	private static function random_private_key() {
		self::init_curve();

		do {
			$hex = bin2hex( random_bytes( 32 ) );
			$d   = gmp_init( $hex, 16 );
		} while ( gmp_cmp( $d, 0 ) <= 0 || gmp_cmp( $d, self::$n ) >= 0 );

		return self::int_to_hex( $d );
	}

	private static function public_key_from_private_key( $private_key_hex ) {
		self::init_curve();

		$d = gmp_init( $private_key_hex, 16 );
		if ( gmp_cmp( $d, 0 ) <= 0 || gmp_cmp( $d, self::$n ) >= 0 ) {
			return new WP_Error( 'wcll_bad_private_key', __( 'Generated an invalid Nostr private key.', 'lawallet-lightning-address' ) );
		}

		$point = self::point_mul( $d, array( self::$gx, self::$gy ) );
		if ( null === $point ) {
			return new WP_Error( 'wcll_pubkey_failed', __( 'Could not derive Nostr public key.', 'lawallet-lightning-address' ) );
		}

		return self::int_to_hex( $point[0] );
	}

	private static function schnorr_sign( $message, $private_key_hex ) {
		self::init_curve();

		if ( 32 !== strlen( $message ) ) {
			return new WP_Error( 'wcll_bad_schnorr_message', __( 'Nostr event hash must be 32 bytes.', 'lawallet-lightning-address' ) );
		}

		$d0 = gmp_init( $private_key_hex, 16 );
		if ( gmp_cmp( $d0, 0 ) <= 0 || gmp_cmp( $d0, self::$n ) >= 0 ) {
			return new WP_Error( 'wcll_bad_private_key', __( 'Invalid private key for Nostr signing.', 'lawallet-lightning-address' ) );
		}

		$p = self::point_mul( $d0, array( self::$gx, self::$gy ) );
		if ( null === $p ) {
			return new WP_Error( 'wcll_pubkey_failed', __( 'Could not derive public key for Nostr signing.', 'lawallet-lightning-address' ) );
		}

		$d = self::has_even_y( $p ) ? $d0 : gmp_sub( self::$n, $d0 );
		$px = hex2bin( self::int_to_hex( $p[0] ) );
		$aux = random_bytes( 32 );
		$t   = self::xor_bytes( hex2bin( self::int_to_hex( $d ) ), self::tagged_hash( 'BIP0340/aux', $aux ) );
		$k0  = self::mod( gmp_init( bin2hex( self::tagged_hash( 'BIP0340/nonce', $t . $px . $message ) ), 16 ), self::$n );

		if ( gmp_cmp( $k0, 0 ) === 0 ) {
			return new WP_Error( 'wcll_nonce_failed', __( 'Generated an invalid nonce for Nostr signing.', 'lawallet-lightning-address' ) );
		}

		$r = self::point_mul( $k0, array( self::$gx, self::$gy ) );
		if ( null === $r ) {
			return new WP_Error( 'wcll_nonce_point_failed', __( 'Could not derive nonce point for Nostr signing.', 'lawallet-lightning-address' ) );
		}

		$k = self::has_even_y( $r ) ? $k0 : gmp_sub( self::$n, $k0 );
		$rx = hex2bin( self::int_to_hex( $r[0] ) );
		$e  = self::mod( gmp_init( bin2hex( self::tagged_hash( 'BIP0340/challenge', $rx . $px . $message ) ), 16 ), self::$n );
		$s  = self::mod( gmp_add( $k, gmp_mul( $e, $d ) ), self::$n );

		return self::int_to_hex( $r[0] ) . self::int_to_hex( $s );
	}

	private static function init_curve() {
		if ( null !== self::$p ) {
			return;
		}

		self::$p  = gmp_init( 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16 );
		self::$n  = gmp_init( 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16 );
		self::$gx = gmp_init( '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16 );
		self::$gy = gmp_init( '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16 );
	}

	private static function point_add( $p, $q ) {
		if ( null === $p ) {
			return $q;
		}
		if ( null === $q ) {
			return $p;
		}

		if ( gmp_cmp( $p[0], $q[0] ) === 0 ) {
			if ( gmp_cmp( self::mod( gmp_add( $p[1], $q[1] ), self::$p ), 0 ) === 0 ) {
				return null;
			}
			return self::point_double( $p );
		}

		$lambda = self::mod(
			gmp_mul(
				gmp_sub( $q[1], $p[1] ),
				gmp_invert( self::mod( gmp_sub( $q[0], $p[0] ), self::$p ), self::$p )
			),
			self::$p
		);

		$x = self::mod( gmp_sub( gmp_sub( gmp_powm( $lambda, 2, self::$p ), $p[0] ), $q[0] ), self::$p );
		$y = self::mod( gmp_sub( gmp_mul( $lambda, gmp_sub( $p[0], $x ) ), $p[1] ), self::$p );

		return array( $x, $y );
	}

	private static function point_double( $p ) {
		if ( null === $p || gmp_cmp( $p[1], 0 ) === 0 ) {
			return null;
		}

		$lambda = self::mod(
			gmp_mul(
				gmp_mul( 3, gmp_powm( $p[0], 2, self::$p ) ),
				gmp_invert( self::mod( gmp_mul( 2, $p[1] ), self::$p ), self::$p )
			),
			self::$p
		);

		$x = self::mod( gmp_sub( gmp_powm( $lambda, 2, self::$p ), gmp_mul( 2, $p[0] ) ), self::$p );
		$y = self::mod( gmp_sub( gmp_mul( $lambda, gmp_sub( $p[0], $x ) ), $p[1] ), self::$p );

		return array( $x, $y );
	}

	private static function point_mul( $scalar, $point ) {
		$result = null;
		$addend = $point;
		$k      = $scalar;

		while ( gmp_cmp( $k, 0 ) > 0 ) {
			if ( gmp_testbit( $k, 0 ) ) {
				$result = self::point_add( $result, $addend );
			}
			$addend = self::point_add( $addend, $addend );
			$k      = gmp_div_q( $k, 2 );
		}

		return $result;
	}

	private static function has_even_y( array $point ) {
		return 0 === gmp_intval( gmp_mod( $point[1], 2 ) );
	}

	private static function tagged_hash( $tag, $message ) {
		$tag_hash = hash( 'sha256', $tag, true );
		return hash( 'sha256', $tag_hash . $tag_hash . $message, true );
	}

	private static function xor_bytes( $a, $b ) {
		return $a ^ $b;
	}

	private static function mod( $num, $mod ) {
		$result = gmp_mod( $num, $mod );
		if ( gmp_cmp( $result, 0 ) < 0 ) {
			$result = gmp_add( $result, $mod );
		}
		return $result;
	}

	private static function int_to_hex( $num ) {
		return str_pad( gmp_strval( $num, 16 ), 64, '0', STR_PAD_LEFT );
	}
}
