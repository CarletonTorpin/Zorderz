<?php
/**
 * Built-in on-site thank-you page, shown after payment when no return URL is
 * configured. Neutral copy; the business name (when known) comes from the
 * Business Profile. No production hostname.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$biz = class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' );
?>
<!doctype html><html><head><meta charset="utf-8"><title><?php echo esc_html__( 'Thank You', 'zorderz' ); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?php echo esc_url( ZIC_PLUGIN_URL . 'assets/css/checkout.css' ); ?>"></head>
<body>
<div class="zic-pay zic-thanks">
<h1><?php echo esc_html__( 'Thank you for your payment', 'zorderz' ); ?></h1>
<p><?php echo esc_html( sprintf(
	/* translators: %s: business name. */
	__( 'We appreciate your business. — %s', 'zorderz' ),
	$biz
) ); ?></p>
</div>
</body></html>
