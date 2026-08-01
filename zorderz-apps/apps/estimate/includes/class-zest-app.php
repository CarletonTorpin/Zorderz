<?php
/**
 * Zorderz Estimates — dashboard app/widget (implements the theme's Widget_App_Interface).
 *
 * bridge_type 'inline_widget': the theme wraps this in the dashboard shell, so
 * render_dashboard_widget() returns ONLY the body. Styling + behaviour live in
 * assets/css/widget.css + assets/js/widget.js. Returns null (hides) for the shared kiosk.
 * The app id is 'estimate-creator' to match the theme's role grants + plugin-api label map.
 *
 * Only required from inside after_setup_theme (see app.php), by which point
 * \Zorderz\Widget_App_Interface is defined.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_App implements \Zorderz\Widget_App_Interface {

	public function get_config(): array {
		return array(
			'id'          => ZEST_APP_ID,
			'nm'          => __( 'Estimates', 'zorderz' ),
			'icon'        => 'file-text',
			'cat'         => 'Sales',
			'cc'          => '#0E7C86',
			'desc'        => __( 'Dictate, photograph or type an estimate — the app parses, prices and drafts it.', 'zorderz' ),
			'roles'       => (array) apply_filters( 'zdz_estimate_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg' ) ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => '',
		);
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zest-fullscreen" data-app-id="' . esc_attr( ZEST_APP_ID ) . '">' . $this->body_html( $user_id ) . '</div>';
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return null; // never on the shared device
		}
		return $this->body_html( $user_id );
	}

	private function body_html( int $user_id ): string {
		$is_admin_tier = user_can( $user_id, 'manage_options' )
			|| (bool) array_intersect( array( 'zdz_owner', 'zdz_admin' ), (array) ( get_userdata( $user_id )->roles ?? array() ) );
		$is_operator = user_can( $user_id, 'zest_create_zero_estimates' );

		wp_enqueue_style( 'zest-widget', ZEST_URL . 'assets/css/widget.css', array(), zest_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_script( 'zest-widget', ZEST_URL . 'assets/js/widget.js', array(), zest_asset_ver( 'assets/js/widget.js' ), true );
		wp_localize_script( 'zest-widget', 'zestWidget', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'restBase'    => esc_url_raw( rest_url( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) ),
			'nonce'       => wp_create_nonce( ZEST_NONCE ),
			'version'     => ZEST_VERSION,
			'isAdminTier' => (bool) $is_admin_tier,
			'isOperator'  => (bool) $is_operator,
			'permissions' => ZEST_Dashboard::get_resolved_permissions( $user_id ),
		) );

		ob_start();
		?>
		<div class="zest-w" id="zest-widget" data-admin="<?php echo $is_admin_tier ? '1' : '0'; ?>">
			<div class="zest-w-tabs" role="tablist">
				<button class="zest-w-tab is-active" data-tab="open" role="tab"><?php esc_html_e( 'Open', 'zorderz' ); ?></button>
				<button class="zest-w-tab" data-tab="new" role="tab"><?php esc_html_e( 'New estimate', 'zorderz' ); ?></button>
				<?php if ( $is_admin_tier ) : ?>
				<button class="zest-w-tab" data-tab="leads" role="tab"><?php esc_html_e( 'Leads', 'zorderz' ); ?></button>
				<?php endif; ?>
				<button class="zest-w-tab" data-tab="history" role="tab"><?php esc_html_e( 'History', 'zorderz' ); ?></button>
			</div>

			<div class="zest-w-panel is-active" data-panel="open">
				<div class="zest-w-list" id="zest-open-list" aria-live="polite">
					<div class="zest-empty zest-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
				</div>
			</div>

			<div class="zest-w-panel" data-panel="new">
				<textarea id="zest-input" class="zest-textarea" rows="3"
					placeholder="<?php esc_attr_e( 'Describe the estimate items, dimensions and customer info — or upload a photo of a handwritten note.', 'zorderz' ); ?>"></textarea>
				<div class="zest-action-row">
					<label class="zest-upload" for="zest-file">
						<input type="file" id="zest-file" accept="image/*" multiple hidden />
						<span><?php esc_html_e( 'Upload photo(s)', 'zorderz' ); ?></span>
					</label>
					<button class="zest-btn zest-btn-primary" id="zest-parse"><?php esc_html_e( 'Parse estimate', 'zorderz' ); ?></button>
				</div>
				<div class="zest-status" id="zest-status" hidden><span id="zest-status-text"><?php esc_html_e( 'Working', 'zorderz' ); ?>&hellip;</span></div>
				<div class="zest-preview" id="zest-preview" hidden></div>
			</div>

			<?php if ( $is_admin_tier ) : ?>
			<div class="zest-w-panel" data-panel="leads">
				<div class="zest-w-list" id="zest-leads-list" aria-live="polite">
					<div class="zest-empty"><?php esc_html_e( 'Recent CRM leads not yet in billing appear here.', 'zorderz' ); ?></div>
				</div>
			</div>
			<?php endif; ?>

			<div class="zest-w-panel" data-panel="history">
				<div class="zest-w-list" id="zest-history-list" aria-live="polite">
					<div class="zest-empty zest-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
