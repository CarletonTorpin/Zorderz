<?php
/**
 * Zorderz Surveys — dashboard app/widget (implements the theme's Widget_App_Interface).
 *
 * bridge_type 'inline_widget': the theme wraps this in the unified dashboard shell, so
 * render_dashboard_widget() returns ONLY the body. Hidden on the shared kiosk. Only
 * required from inside after_setup_theme, by which point the interface exists.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_App implements \Zorderz\Widget_App_Interface {

	const APP_ID = 'surveys';

	public function get_config(): array {
		return array(
			'id'          => self::APP_ID,
			'nm'          => 'Surveys',
			'icon'        => 'clipboard-check',
			'cat'         => 'Admin',
			'cc'          => '#2563EB',
			'desc'        => 'Satisfaction follow-up: screen settled invoices, track operator call outcomes, and send review invites.',
			'roles'       => (array) apply_filters( 'zdz_survey_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_operator' ) ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => admin_url( 'options-general.php?page=' . ZSV_Admin::PAGE ),
		);
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zsv-fullscreen" data-app-id="surveys">' . $this->body_html( $user_id ) . '</div>';
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return null; // never on the shared device.
		}
		return $this->body_html( $user_id );
	}

	private function body_html( int $user_id ): string {
		wp_enqueue_style( 'zsv-widget', ZSV_URL . 'assets/css/widget.css', array(), zsv_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_script( 'zsv-widget', ZSV_URL . 'assets/js/widget.js', array(), zsv_asset_ver( 'assets/js/widget.js' ), true );
		wp_localize_script(
			'zsv-widget',
			'zsvWidget',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ZSV_NONCE ),
				'version' => ZSV_VERSION,
			)
		);

		ob_start();
		?>
		<div class="zsv-w" id="zsv-widget">
			<div class="zsv-w-stats" id="zsv-stats" aria-live="polite">
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-batches">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Batches', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-leads">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Follow-ups', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-invited">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Invited', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-reviews">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Reviews', 'zorderz' ); ?></span></div>
			</div>
			<div class="zsv-w-actions">
				<button class="zsv-btn zsv-btn-primary" id="zsv-run-batch"><?php esc_html_e( 'Run batch', 'zorderz' ); ?></button>
				<button class="zsv-btn zsv-btn-secondary" id="zsv-sync"><?php esc_html_e( 'Sync operator notes', 'zorderz' ); ?></button>
				<button class="zsv-btn zsv-btn-secondary" id="zsv-check-reviews"><?php esc_html_e( 'Check reviews', 'zorderz' ); ?></button>
			</div>
			<div class="zsv-w-list" id="zsv-list" aria-live="polite">
				<div class="zsv-empty zsv-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
			</div>
			<a class="zsv-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . ZSV_Admin::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'zorderz' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
