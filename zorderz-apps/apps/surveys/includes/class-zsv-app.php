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
			'cc'          => '#0072B2',
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

	/**
	 * Is "Check reviews" drawn beside the sync button for this person? Only for the people the
	 * site lets run a check (ZSV_Review_Checker::ajax_check asks for manage_options); everyone
	 * else keeps it under "More tools". Asked of the person the widget is drawn for, not of
	 * whoever is signed in. Display only: it adds, loosens and removes no check.
	 */
	public static function check_beside( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	private function body_html( int $user_id ): string {
		wp_enqueue_style( 'zsv-widget', ZSV_URL . 'assets/css/widget.css', array(), zsv_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_script( 'zsv-widget-logic', ZSV_URL . 'assets/js/widget-logic.js', array(), zsv_asset_ver( 'assets/js/widget-logic.js' ), true );
		wp_enqueue_script( 'zsv-widget', ZSV_URL . 'assets/js/widget.js', array( 'zsv-widget-logic' ), zsv_asset_ver( 'assets/js/widget.js' ), true );
		wp_localize_script(
			'zsv-widget',
			'zsvWidget',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ZSV_NONCE ),
				'version' => ZSV_VERSION,
			)
		);
		return self::markup( self::check_beside( $user_id ) );
	}

	/**
	 * The widget's markup. With $check_beside, "Check reviews" is the third button of the top row:
	 * it shares a wrapper with the sync button so the two stay side by side at every width, and
	 * the last check's result sits under the buttons that started it. Without it, the top row is
	 * the two buttons and "Check reviews" stays under "More tools". Either way there is exactly one
	 * "Check reviews" and one result region in the page.
	 */
	public static function markup( bool $check_beside ): string {
		ob_start();
		?>
		<div class="zsv-w" id="zsv-widget">
			<div class="zsv-w-stats" id="zsv-stats" aria-live="polite">
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-batches">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Batches', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-leads">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Follow-ups', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-invited">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Invited', 'zorderz' ); ?></span></div>
				<div class="zsv-stat"><span class="zsv-stat-val" id="zsv-st-reviews">--</span><span class="zsv-stat-label"><?php esc_html_e( 'Reviews', 'zorderz' ); ?></span></div>
			</div>
			<div class="zsv-w-actions<?php echo $check_beside ? ' zsv-w-actions-trio' : ''; ?>">
				<button type="button" class="zsv-btn zsv-btn-primary" id="zsv-run-batch"><?php esc_html_e( 'Run batch', 'zorderz' ); ?></button>
				<?php if ( $check_beside ) : ?><div class="zsv-w-actions-pair"><?php endif; ?>
				<button type="button" class="zsv-btn zsv-btn-secondary" id="zsv-sync"><?php esc_html_e( 'Sync operator notes', 'zorderz' ); ?></button>
				<?php if ( $check_beside ) : ?>
				<button type="button" class="zsv-btn zsv-btn-secondary" id="zsv-check-reviews"><?php esc_html_e( 'Check reviews', 'zorderz' ); ?></button>
				</div>
				<?php endif; ?>
			</div>
			<div class="zsv-progress" id="zsv-progress" role="status" aria-live="polite"></div>
			<?php if ( $check_beside ) : ?>
			<div class="zsv-review-status" id="zsv-review-status" role="status" aria-live="polite" hidden></div>
			<?php endif; ?>
			<div class="zsv-greviews" id="zsv-greviews" hidden></div>
			<button type="button" class="zsv-btn zsv-btn-primary zsv-send" id="zsv-send" hidden></button>
			<?php if ( ! $check_beside ) : ?>
			<details class="zsv-more">
				<summary><?php esc_html_e( 'More tools', 'zorderz' ); ?></summary>
				<button type="button" class="zsv-btn zsv-btn-secondary" id="zsv-check-reviews"><?php esc_html_e( 'Check reviews', 'zorderz' ); ?></button>
				<div class="zsv-review-status" id="zsv-review-status" role="status" aria-live="polite" hidden></div>
			</details>
			<?php endif; ?>
			<div class="zsv-w-list" id="zsv-list" aria-live="polite">
				<div class="zsv-empty zsv-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
			</div>
			<a class="zsv-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . ZSV_Admin::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'zorderz' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
