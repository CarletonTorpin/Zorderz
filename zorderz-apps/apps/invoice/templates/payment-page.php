<?php
/**
 * Hosted pay page for /pay/<token>.
 *
 * $inv is provided by ZIC_REST::maybe_render_payment_page(). Customer-facing and
 * standalone (outside the app shell). No production hostname: the Stripe JS/API
 * hosts are the provider's; the post-payment destination resolves through
 * zic_return_url() (admin option or the built-in on-site thank-you page).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

header( "Content-Security-Policy: default-src 'self' https://js.stripe.com; script-src 'self' https://js.stripe.com 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-src 'self' https://js.stripe.com; connect-src 'self' https://api.stripe.com;" );

if ( empty( $inv ) ) {
	status_header( 404 );
	echo 'Not found';
	return;
}

// Already settled — or Stripe has just redirected back reporting success while
// the confirming webhook is still in flight — send the customer on rather than
// re-showing the pay form (which would risk a second charge). An admin-configured
// URL wins; blank falls through to the built-in on-site thank-you page below.
$zic_stripe_ok = isset( $_GET['redirect_status'] ) && 'succeeded' === sanitize_text_field( wp_unslash( $_GET['redirect_status'] ) );
if ( in_array( $inv['status'], array( 'paid', 'refunded' ), true ) || $zic_stripe_ok ) {
	$configured = trim( (string) get_option( 'zic_return_url', '' ) );
	if ( '' !== $configured ) {
		wp_redirect( esc_url_raw( $configured ) ); // admin-entered, sanitized; may be off-site.
		exit;
	}
	include ZIC_PLUGIN_DIR . 'templates/thank-you.php';
	exit;
}

$biz         = class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' );
$pi_url      = class_exists( 'ZIC_REST' ) ? ZIC_REST::payment_intent_url() : '';
$return_url  = function_exists( 'zic_return_url' ) ? zic_return_url( $inv['token'] ) : home_url( '/' );
?><!doctype html><html><head><meta charset="utf-8"><title><?php echo esc_html( 'Pay Invoice — ' . $biz ); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?php echo esc_url( ZIC_PLUGIN_URL . 'assets/css/checkout.css' ); ?>">
<script src="https://js.stripe.com/v3/"></script></head><body>
<div class="zic-pay">
<h1>Invoice #<?php echo (int) $inv['id']; ?></h1>
<p><?php echo esc_html( $inv['description'] ); ?></p>
<p><strong>$<?php echo esc_html( number_format( $inv['amount_cents'] / 100, 2 ) ); ?></strong></p>
<div id="payment-element"></div>
<button id="submit">Pay</button>
<div id="error-message"></div>
</div>
<script>
window.ZIC_TOKEN     = <?php echo wp_json_encode( $inv['token'] ); ?>;
window.ZIC_STRIPE_PK = <?php echo wp_json_encode( get_option( 'zic_stripe_publishable', '' ) ); ?>;
window.ZIC_PI_URL    = <?php echo wp_json_encode( $pi_url ); ?>;
window.ZIC_RETURN    = <?php echo wp_json_encode( $return_url ); ?>;
</script>
<script src="<?php echo esc_url( ZIC_PLUGIN_URL . 'assets/js/checkout.js' ); ?>"></script>
</body></html>
