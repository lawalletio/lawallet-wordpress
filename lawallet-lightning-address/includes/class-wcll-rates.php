<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Rates {
	public static function order_amount_to_msat( WC_Order $order, array $settings ) {
		$total    = (float) $order->get_total();
		$currency = strtoupper( $order->get_currency() );
		$sats     = null;

		$manual = isset( $settings['manual_sats_per_unit'] ) ? (float) $settings['manual_sats_per_unit'] : 0.0;
		if ( $manual > 0 ) {
			$sats = $total * $manual;
		} elseif ( in_array( $currency, array( 'BTC', 'XBT' ), true ) ) {
			$sats = $total * 100000000;
		} elseif ( in_array( $currency, array( 'SAT', 'SATS' ), true ) ) {
			$sats = $total;
		} else {
			$price = self::get_btc_price( $currency, ! empty( $settings['allow_insecure_http'] ) && 'yes' === $settings['allow_insecure_http'] );
			if ( is_wp_error( $price ) ) {
				return $price;
			}

			$sats = $total * ( 100000000 / $price );
		}

		$buffer = isset( $settings['price_buffer_percent'] ) ? (float) $settings['price_buffer_percent'] : 0.0;
		if ( $buffer > 0 ) {
			$sats *= ( 1 + ( $buffer / 100 ) );
		}

		$sats = max( 1, (int) ceil( $sats ) );
		return $sats * 1000;
	}

	private static function get_btc_price( $currency, $ssl_insecure = false ) {
		$currency = strtolower( sanitize_key( $currency ) );
		$key      = 'wcll_yadio_btc_price_' . $currency;
		$cached   = get_transient( $key );
		if ( $cached ) {
			return (float) $cached;
		}

		$url      = 'https://api.yadio.io/rate/' . rawurlencode( strtoupper( $currency ) ) . '/BTC';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 12,
				'sslverify' => ! $ssl_insecure,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'wcll_rate_http_error', sprintf( 'BTC price lookup failed with HTTP %d.', $code ) );
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$price = isset( $data['rate'] ) ? (float) $data['rate'] : 0.0;
		if ( $price <= 0 ) {
			return new WP_Error( 'wcll_rate_missing', sprintf( 'Yadio BTC price for %s was not available.', strtoupper( $currency ) ) );
		}

		set_transient( $key, $price, 5 * MINUTE_IN_SECONDS );
		return $price;
	}
}
