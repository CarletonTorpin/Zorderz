<?php
/**
 * ZDP_App — the dot-plot dashboard app/widget (implements the theme's Widget_App_Interface).
 *
 * Thin shell: renders the dictation box, the source/entity/window picker, and the dot grid. All
 * data comes from the zorderz/v1 /dotplot/* routes, which run the ZDZ_Report_Sources security
 * boundary. Hidden on the shared kiosk. Only required from inside after_setup_theme.
 *
 * @package Zorderz\DotPlot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDP_App' ) ) {

	class ZDP_App implements \Zorderz\Widget_App_Interface {

		const APP_ID = 'dotplot';

		public function get_config(): array {
			return array(
				'id'          => self::APP_ID,
				'nm'          => __( 'Dot Plot', 'zorderz' ),
				'icon'        => 'chart-scatter',
				'cat'         => 'Admin',
				'cc'          => '#0F766E',
				'desc'        => __( 'Plot any registered event by rep, customer or day over a window.', 'zorderz' ),
				'roles'       => (array) apply_filters( 'zdz_dotplot_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_operator' ) ),
				'bridge_type' => 'inline_widget',
				'admin_url'   => '',
				// AC3 boot fail-loud: the hot-path classes that MUST be defined once this app has
				// loaded. On a partial-zip install where one is missing, ZDZ_Plugin_API declines the
				// tile (logged disposition + admin notice) instead of fataling at wp_head.
				'classes'     => array( 'ZDP_App', 'ZDP_Rest', 'ZDP_Ai' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo '<div class="zdp-fullscreen" data-app-id="dotplot">' . $this->body_html( $user_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
				return null; // never on the shared device.
			}
			return $this->body_html( $user_id );
		}

		private function body_html( int $user_id ): string {
			wp_enqueue_style( 'zdp-widget', ZDP_URL . 'assets/css/widget.css', array(), zdp_asset_ver( 'assets/css/widget.css' ) );
			wp_enqueue_script( 'zdp-widget', ZDP_URL . 'assets/js/widget.js', array(), zdp_asset_ver( 'assets/js/widget.js' ), true );
			wp_localize_script(
				'zdp-widget',
				'zdpWidget',
				array(
					'rest'    => esc_url_raw( rest_url( ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) . '/dotplot' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'version' => ZDP_VERSION,
				)
			);

			ob_start();
			?>
			<div class="zdp-w" id="zdp-widget">
				<div class="zdp-w-bar">
					<input type="text" id="zdp-utterance" class="zdp-input" placeholder="<?php esc_attr_e( 'Describe a plot, e.g. accepted sales by rep this month', 'zorderz' ); ?>" />
					<button class="zdp-btn zdp-btn-secondary" id="zdp-dictate"><?php esc_html_e( 'Ask', 'zorderz' ); ?></button>
				</div>
				<div class="zdp-w-controls">
					<select id="zdp-source" class="zdp-select" aria-label="<?php esc_attr_e( 'Source', 'zorderz' ); ?>"></select>
					<select id="zdp-entity" class="zdp-select" aria-label="<?php esc_attr_e( 'Group by', 'zorderz' ); ?>"></select>
					<input type="date" id="zdp-from" class="zdp-date" aria-label="<?php esc_attr_e( 'From', 'zorderz' ); ?>" />
					<input type="date" id="zdp-to" class="zdp-date" aria-label="<?php esc_attr_e( 'To', 'zorderz' ); ?>" />
					<button class="zdp-btn zdp-btn-primary" id="zdp-plot"><?php esc_html_e( 'Plot', 'zorderz' ); ?></button>
				</div>
				<div class="zdp-msg" id="zdp-msg" aria-live="polite"></div>
				<div class="zdp-grid-wrap" id="zdp-grid" aria-live="polite">
					<div class="zdp-empty"><?php esc_html_e( 'Pick a source and press Plot.', 'zorderz' ); ?></div>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}
	}
}
