<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wcll_expires_at = (int) $order->get_meta( '_wcll_expires_at', true );
$wcll_amount_msat = (int) $order->get_meta( '_wcll_amount_msat', true );
$wcll_amount_sats = $wcll_amount_msat ? number_format_i18n( $wcll_amount_msat / 1000 ) : '';
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

			<?php if ( $wcll_expires_at ) : ?>
				<div class="wcll-payment__timer">
					<span><?php esc_html_e( 'Expires', 'lawallet-lightning-address' ); ?></span>
					<strong data-wcll-countdown><?php echo esc_html( human_time_diff( time(), $wcll_expires_at ) ); ?></strong>
				</div>
			<?php endif; ?>

			<div class="wcll-payment__actions">
				<button class="button alt wcll-payment__webln" type="button" data-wcll-webln hidden>
					<?php esc_html_e( 'Pay with WebLN', 'lawallet-lightning-address' ); ?>
				</button>
				<a class="button alt wcll-payment__open" href="<?php echo esc_url( 'lightning:' . $invoice ); ?>">
					<?php esc_html_e( 'Open wallet', 'lawallet-lightning-address' ); ?>
				</a>
				<button class="button wcll-payment__copy" type="button" data-wcll-copy>
					<?php esc_html_e( 'Copy invoice', 'lawallet-lightning-address' ); ?>
				</button>
			</div>

			<textarea class="wcll-payment__invoice" readonly rows="4" data-wcll-invoice><?php echo esc_textarea( $invoice ); ?></textarea>
		</div>
	</div>
</section>
