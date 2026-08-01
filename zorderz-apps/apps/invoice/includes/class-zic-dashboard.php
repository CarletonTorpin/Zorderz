<?php
/**
 * Optional front-end shortcode [zic_invoice_creator] — a small pointer to the
 * admin Invoices screen. The real UI lives in WP-Admin.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Dashboard {

	public static function register_shortcode() {
		add_shortcode( 'zic_invoice_creator', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in.', 'zorderz' ) . '</p>';
		}
		wp_enqueue_style( 'zic-widget-css', ZIC_PLUGIN_URL . 'assets/css/widget.css', array(), ZIC_VERSION );
		$url = esc_url( admin_url( 'admin.php?page=zic_dashboard' ) );
		return '<div class="zic-widget"><h3>' . esc_html__( 'Invoices', 'zorderz' ) . '</h3>'
			. '<p>' . sprintf(
				/* translators: %s: link to the admin Invoices screen. */
				esc_html__( 'Manage invoices from the %s screen in the admin menu.', 'zorderz' ),
				'<a href="' . $url . '">' . esc_html__( 'Invoices', 'zorderz' ) . '</a>'
			) . '</p></div>';
	}
}
