<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wcll_expires_at = (int) $order->get_meta( '_wcll_expires_at', true );
$wcll_amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
$wcll_amount_sats = $wcll_amount_msat ? number_format_i18n( $wcll_amount_msat / 1000 ) : '';
$wcll_rate = $order->get_meta( '_wcll_rate', true );
$wcll_rate_label = '';
$wcll_rate_value = '';

if ( ! function_exists( 'wcll_format_rate_number' ) ) {
	function wcll_format_rate_number( $number, $decimals = 8 ) {
		$formatted = number_format_i18n( (float) $number, $decimals );
		$decimal_separators = array( '.', ',' );
		if ( function_exists( 'wc_get_price_decimal_separator' ) ) {
			$decimal_separators[] = wc_get_price_decimal_separator();
		}

		foreach ( array_unique( $decimal_separators ) as $decimal_separator ) {
			if ( false !== strpos( $formatted, $decimal_separator ) ) {
				$formatted = rtrim( rtrim( $formatted, '0' ), $decimal_separator );
				break;
			}
		}

		return $formatted;
	}
}

if ( is_array( $wcll_rate ) && ! empty( $wcll_rate['sats_per_unit'] ) && ! empty( $wcll_rate['currency'] ) ) {
	$wcll_rate_source = isset( $wcll_rate['source'] ) ? (string) $wcll_rate['source'] : '';
	$wcll_rate_display_unit = isset( $wcll_rate['display_unit'] ) ? (string) $wcll_rate['display_unit'] : 'btc';
	$wcll_rate_suffix = '';

	if ( 'yadio' === $wcll_rate_source ) {
		$wcll_rate_label = __( 'Rate used (Yadio)', 'lawallet-lightning-address' );
	} else {
		$wcll_rate_label = __( 'Rate used', 'lawallet-lightning-address' );
	}

	if ( 'sats' === $wcll_rate_display_unit && ! empty( $wcll_rate['fiat_per_sat'] ) ) {
		$wcll_rate_value = sprintf(
			/* translators: 1: fiat price, 2: currency code. */
			__( '1 SAT = %1$s %2$s', 'lawallet-lightning-address' ),
			wcll_format_rate_number( (float) $wcll_rate['fiat_per_sat'], 8 ),
			$wcll_rate['currency']
		);
	} elseif ( ! empty( $wcll_rate['btc_price'] ) ) {
		$wcll_rate_value = sprintf(
			/* translators: 1: BTC price, 2: currency code. */
			__( '1 BTC = %1$s %2$s', 'lawallet-lightning-address' ),
			wcll_format_rate_number( (float) $wcll_rate['btc_price'], 2 ),
			$wcll_rate['currency']
		);
	} else {
		$wcll_rate_value = sprintf(
			/* translators: 1: sats amount, 2: currency code. */
			__( '1 %2$s = %1$s sats', 'lawallet-lightning-address' ),
			wcll_format_rate_number( (float) $wcll_rate['sats_per_unit'], 8 ),
			$wcll_rate['currency']
		);
	}

	if ( ! empty( $wcll_rate['price_buffer_percent'] ) ) {
		$wcll_rate_suffix = sprintf(
			/* translators: %s: buffer percentage. */
			__( ' + %s%% buffer', 'lawallet-lightning-address' ),
			wcll_format_rate_number( (float) $wcll_rate['price_buffer_percent'], 2 )
		);
	}

	$wcll_rate_value .= $wcll_rate_suffix;
}
?>

<section
	class="wcll-payment"
	data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	data-invoice="<?php echo esc_attr( $invoice ); ?>"
>
	<div class="wcll-payment__header">
		<div>
			<p class="wcll-payment__eyebrow"><?php esc_html_e( 'LaWallet Lightning', 'lawallet-lightning-address' ); ?></p>
			<h2><?php esc_html_e( 'Complete your payment', 'lawallet-lightning-address' ); ?></h2>
		</div>
		<div class="wcll-payment__status" data-wcll-status>
			<span class="wcll-payment__dot"></span>
			<span data-wcll-status-text><?php esc_html_e( 'Waiting for payment', 'lawallet-lightning-address' ); ?></span>
		</div>
	</div>

	<div class="wcll-payment__body">
		<div class="wcll-payment__qr-shell">
			<div class="wcll-payment__qr" data-wcll-qr aria-label="<?php esc_attr_e( 'Lightning invoice QR code', 'lawallet-lightning-address' ); ?>"></div>
			<div class="wcll-payment__paid-overlay" data-wcll-paid-overlay hidden aria-hidden="true">
				<span class="wcll-payment__paid-mark"></span>
			</div>
		</div>

		<div class="wcll-payment__details">
			<?php if ( $wcll_amount_sats ) : ?>
				<div class="wcll-payment__amount">
					<span><?php esc_html_e( 'Amount', 'lawallet-lightning-address' ); ?></span>
					<strong><?php echo esc_html( $wcll_amount_sats ); ?> sats</strong>
				</div>
			<?php endif; ?>

			<?php if ( $wcll_rate_value ) : ?>
				<div class="wcll-payment__rate">
					<span><?php echo esc_html( $wcll_rate_label ); ?></span>
					<strong><?php echo esc_html( $wcll_rate_value ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( $wcll_expires_at ) : ?>
				<div class="wcll-payment__timer">
					<span><?php esc_html_e( 'Expires', 'lawallet-lightning-address' ); ?></span>
					<strong data-wcll-countdown><?php echo esc_html( human_time_diff( time(), $wcll_expires_at ) ); ?></strong>
				</div>
			<?php endif; ?>

			<div class="wcll-payment__actions">
				<button class="button wcll-payment__action wcll-payment__webln" type="button" data-wcll-webln hidden>
					<?php esc_html_e( 'Pay with WebLN', 'lawallet-lightning-address' ); ?>
				</button>
				<a class="button wcll-payment__action wcll-payment__open" href="<?php echo esc_url( 'lightning:' . $invoice ); ?>">
					<?php esc_html_e( 'Open wallet', 'lawallet-lightning-address' ); ?>
				</a>
				<button class="button wcll-payment__action wcll-payment__copy" type="button" data-wcll-copy>
					<?php esc_html_e( 'Copy invoice', 'lawallet-lightning-address' ); ?>
				</button>
			</div>

			<textarea class="wcll-payment__invoice" readonly rows="4" data-wcll-invoice><?php echo esc_textarea( $invoice ); ?></textarea>
		</div>
	</div>
</section>
