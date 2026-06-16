<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Rates {
	public static function order_amount_to_msat( WC_Order $order, array $settings ) {
		$calculation = self::calculate_order_amount( $order, $settings );
		if ( is_wp_error( $calculation ) ) {
			return $calculation;
		}

		return $calculation['amount_msat'];
	}

	public static function calculate_order_amount( WC_Order $order, array $settings ) {
		$total    = (float) $order->get_total();
		$currency = strtoupper( $order->get_currency() );
		$sats     = null;
		$rate     = array(
			'source'              => '',
			'currency'            => $currency,
			'order_total'         => $total,
			'btc_price'           => null,
			'fiat_per_sat'        => null,
			'sats_per_unit'       => null,
			'display_unit'        => self::get_display_unit( $settings ),
			'price_buffer_percent' => isset( $settings['price_buffer_percent'] ) ? (float) $settings['price_buffer_percent'] : 0.0,
		);

		$manual = isset( $settings['manual_sats_per_unit'] ) ? (float) $settings['manual_sats_per_unit'] : 0.0;
		if ( $manual > 0 ) {
			$sats = $total * $manual;
			$rate['source']        = 'manual';
			$rate['fiat_per_sat']  = 1 / $manual;
			$rate['sats_per_unit'] = $manual;
		} elseif ( in_array( $currency, array( 'BTC', 'XBT' ), true ) ) {
			$sats = $total * 100000000;
			$rate['source']        = 'btc';
			$rate['btc_price']     = 1.0;
			$rate['fiat_per_sat']  = 0.00000001;
			$rate['sats_per_unit'] = 100000000;
		} elseif ( in_array( $currency, array( 'SAT', 'SATS' ), true ) ) {
			$sats = $total;
			$rate['source']        = 'sats';
			$rate['fiat_per_sat']  = 1;
			$rate['sats_per_unit'] = 1;
		} else {
			$price = self::get_btc_price( $currency, ! empty( $settings['allow_insecure_http'] ) && 'yes' === $settings['allow_insecure_http'] );
			if ( is_wp_error( $price ) ) {
				return $price;
			}

			$sats = $total * ( 100000000 / $price );
			$rate['source']        = 'yadio';
			$rate['btc_price']     = $price;
			$rate['fiat_per_sat']  = $price / 100000000;
			$rate['sats_per_unit'] = 100000000 / $price;
		}

		$buffer = $rate['price_buffer_percent'];
		if ( $buffer > 0 ) {
			$sats *= ( 1 + ( $buffer / 100 ) );
		}

		$sats = max( 1, (int) ceil( $sats ) );
		return array(
			'amount_msat' => $sats * 1000,
			'rate'        => $rate,
		);
	}

	private static function get_display_unit( array $settings ) {
		$display_unit = isset( $settings['rate_display_unit'] ) ? strtolower( sanitize_key( $settings['rate_display_unit'] ) ) : 'btc';
		return in_array( $display_unit, array( 'btc', 'sats' ), true ) ? $display_unit : 'btc';
	}

	private static function get_btc_price( $currency, $ssl_insecure = false ) {
		$currency = strtolower( sanitize_key( $currency ) );
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

		return $price;
	}
}
