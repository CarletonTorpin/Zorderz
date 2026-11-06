<?php
/**
 * ZTER_App — the Territories dashboard app (implements the theme's Widget_App_Interface).
 *
 * A thin shell: the card's root and a loading state. Everything is drawn by assets/app.js from the
 * territories REST routes, which decide what each viewer may see. Never on the shared device. Required only
 * from inside after_setup_theme.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZTER_App' ) ) {

	class ZTER_App implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => ZTER_APP_ID,
				'nm'          => __( 'Territories', 'zorderz' ),
				'icon'        => 'map',
				'cat'         => 'sales',
				'cc'          => '#E69F00', // the Sales group's colour; the group decides what is drawn
				'desc'        => __( 'Who gets a new estimate stub and who does a won job: each salesperson\'s ZIP codes, the zones, the rules that pick a person, and what the rules would have chosen.', 'zorderz' ),
				'roles'       => array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ), // others by the per-person grant; never the shared device
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=zter-settings' ),
				'classes'     => array( 'ZTER_App', 'ZTER_REST', 'ZTER_Rules', 'ZTER_DB', 'ZTER_Zones', 'ZTER_Kinds' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! zter_user_can_use( $user_id ) ) {
				return null;
			}
			zter_enqueue_app( $user_id );
			return '<div class="zter-app" id="zter-app">'
				. '<div class="zdz-loading" role="status" aria-live="polite"><span class="zdz-loading-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="zdz-loading-label">' . esc_html__( 'Loading Territories…', 'zorderz' ) . '</span></div>'
				. '<noscript>' . esc_html__( 'Territories needs JavaScript.', 'zorderz' ) . '</noscript></div>';
		}
	}
}
