<?php
/**
 * ZHR_App -- the Hours dashboard app (implements the theme's Widget_App_Interface).
 *
 * A thin shell: the card's root and a loading state. Everything is drawn by assets/app.js from the hours REST routes,
 * which decide what each viewer may see. Never on the shared device. Required only from inside after_setup_theme.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZHR_App' ) ) {

	class ZHR_App implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => ZHR_APP_ID,
				'nm'          => __( 'Hours', 'zorderz' ),
				'icon'        => 'clock',
				'cat'         => 'team',
				'cc'          => '#56B4E9', // the Team group's colour; the group decides what is drawn
				'desc'        => __( 'Clock in and out, add hours by voice, text or photo, and see your week and month.', 'zorderz' ),
				'roles'       => zhr_staff_roles(), // never the shared device
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=zhr-settings' ),
				'classes'     => array( 'ZHR_App', 'ZHR_REST', 'ZHR_Time', 'ZHR_Rules', 'ZHR_DB' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! zhr_user_can_use( $user_id ) ) {
				return null;
			}
			zhr_enqueue_app( $user_id );
			return '<div class="zhr-app" id="zhr-app" data-zdz-ask="off">'
				. '<div class="zdz-loading" role="status" aria-live="polite"><span class="zdz-loading-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="zdz-loading-label">' . esc_html__( 'Loading Hours…', 'zorderz' ) . '</span></div>'
				. '<noscript>' . esc_html__( 'Hours needs JavaScript.', 'zorderz' ) . '</noscript></div>';
		}
	}
}
