<?php
/**
 * ZAW_App -- the Ai Workspace in the Zorderz app shell (implements the theme's Widget_App_Interface).
 *
 * A thin shell: the workspace's root element; assets/workspace.js draws the thread from the ai-workspace REST routes.
 * Whether the tile shows is the platform's app grant; what is inside is ZAW_Grant's: the person who holds the workspace
 * gets the thread, an owner-level account without it gets the switch, everyone else gets no card at all (null). Never
 * the shared device, never an account in Privacy View. Required only from inside after_setup_theme.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZAW_App' ) ) {

	class ZAW_App implements \Zorderz\Widget_App_Interface {

		public static function init() {
			$widget = new self();
			add_filter(
				'zdz_register_apps',
				function ( $apps ) use ( $widget ) {
					$apps[ ZAW_APP_ID ] = $widget;
					return $apps;
				}
			);
		}

		/** Does this account get a card at all? The holder, or an owner-level account that may turn it on. */
		public static function shows_for( int $user_id ): bool {
			if ( '' !== ZAW_Grant::refused( $user_id ) ) {
				return false;
			}
			return ZAW_Grant::may_use( $user_id ) || ( ZAW_Grant::owner_level( $user_id ) && user_can( $user_id, 'manage_options' ) );
		}

		public function get_config(): array {
			return array(
				'id'          => ZAW_APP_ID,
				'nm'          => __( 'Ai Workspace', 'zorderz' ),
				'short_name'  => __( 'Workspace', 'zorderz' ),
				'icon'        => 'sparkles',
				'cat'         => 'tools',
				'cc'          => '#CC79A7', // the Tools group's colour; the group decides what is drawn
				'desc'        => __( 'Talk with the business\'s assistant; customer details stay coded.', 'zorderz' ),
				'roles'       => array(),   // visibility: the platform's app grant, then ZAW_Grant
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=' . ZAW_Admin::PAGE ),
				'classes'     => array( 'ZAW_App', 'ZAW_REST', 'ZAW_Grant', 'ZAW_Turns' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! self::shows_for( $user_id ) ) {
				return null;
			}
			ZAW_Admin::enqueue( $user_id );
			return ZAW_Admin::root();
		}
	}
}
