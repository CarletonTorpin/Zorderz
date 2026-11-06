<?php
/**
 * ZRO_App — the Team Roles dashboard app (implements the theme's Widget_App_Interface).
 *
 * A thin shell: the card's root and a loading state. Everything is drawn by assets/app.js from
 * the team-roles REST routes, which decide what each viewer may see. Never on the shared device.
 * Required only from inside after_setup_theme.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZRO_App' ) ) {

	class ZRO_App implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => ZRO_APP_ID,
				'nm'          => __( 'Team Roles', 'zorderz' ),
				'icon'        => 'users',
				'cat'         => 'team',
				'cc'          => '#56B4E9', // the Team group's colour; the group decides what is drawn
				'desc'        => __( 'What each person can and can\'t do: what they sell, install and repair, site conditions and tasks.', 'zorderz' ),
				'roles'       => zro_team_roles(), // never the shared device
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=zro-settings' ),
				'classes'     => array( 'ZRO_App', 'ZRO_REST', 'ZRO_Roles', 'ZRO_Abilities', 'ZRO_DB' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! zro_user_can_use( $user_id ) ) {
				return null;
			}
			zro_enqueue_app( $user_id );
			return '<div class="zro-app" id="zro-app">'
				. '<div class="zdz-loading" role="status" aria-live="polite"><span class="zdz-loading-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="zdz-loading-label">' . esc_html__( 'Loading Team Roles…', 'zorderz' ) . '</span></div>'
				. '<noscript>' . esc_html__( 'Team Roles needs JavaScript.', 'zorderz' ) . '</noscript></div>';
		}
	}
}
