<?php
/**
 * Zorderz Prep — dashboard app/widget (implements the theme's Widget_App_Interface).
 *
 * bridge_type 'inline_widget': the theme wraps this in the unified dashboard shell, so
 * render_dashboard_widget() returns ONLY the body. Hidden on the shared kiosk. The roll
 * options, piece-kind labels, colours, letterhead and the versioned cut-complete contract
 * header are all localised from configuration / the Item Engine — nothing is hardcoded to
 * a trade.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_App implements \Zorderz\Widget_App_Interface {

	const APP_ID = 'prep';

	public function get_config(): array {
		return array(
			'id'          => self::APP_ID,
			'nm'          => __( 'Prep', 'zorderz' ),
			'icon'        => 'scissors',
			'cat'         => 'Field',
			'cc'          => '#D4881C',
			'desc'        => __( 'Plan cuts from a job with colour-accurate nesting layouts.', 'zorderz' ),
			'roles'       => (array) apply_filters( 'zprep_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_mfg', 'zdz_sales' ) ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => admin_url( 'admin.php?page=' . ZPREP_Admin::PAGE ),
		);
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zprep-fullscreen" data-app-id="prep">' . $this->body_html( $user_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return null; // never on the shared device.
		}
		if ( ! ZPREP_Dashboard::user_can_access( $user_id ) ) {
			return null; // don't localize a nonce for a user without access.
		}
		return $this->body_html( $user_id );
	}

	/** The localized data payload for the widget JS. */
	private function localized_data(): array {
		// Piece-kind labels from the Item Engine (id => display name), scoped to the queue.
		$kind_labels = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			$filter  = array();
			$subtype = ZPREP_Settings::queue_subtype();
			if ( '' !== $subtype ) {
				$filter['subtype'] = $subtype;
			}
			foreach ( ZDZ_Item_Engine::all( $filter ) as $id => $item ) {
				$kind_labels[ (string) $id ] = (string) ( $item['display_name'] ?? $id );
			}
		}

		return array(
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( ZPREP_NONCE ),
			'version'    => ZPREP_VERSION,
			'settings'   => array(
				'grungyMarginIn' => ZPREP_Settings::grungy_margin_in(),
				'minSaveableIn'  => ZPREP_Settings::min_saveable_in(),
			),
			'letterhead' => ZPREP_Settings::letterhead(),
			'colors'     => array_values( ZPREP_Settings::roll_colors() ),
			'kindLabels' => $kind_labels,
			'contract'   => array(
				'cutComplete' => ZPREP_CONTRACT_CUT_COMPLETE,
				'signature'   => ZPREP_CONTRACT_SIGNATURE,
			),
			'user'       => array(
				'name'     => wp_get_current_user()->display_name ?? '',
				'is_admin' => current_user_can( 'manage_options' ),
			),
		);
	}

	/** Build the roll <option> list from configuration (Auto + each available roll). */
	private function roll_options_html(): string {
		$html = '<option value="0">' . esc_html__( 'Auto', 'zorderz' ) . '</option>';
		foreach ( ZPREP_Settings::rolls() as $r ) {
			if ( empty( $r['available'] ) ) {
				continue;
			}
			$val   = (int) $r['width_in'] . strtolower( substr( (string) $r['color'], 0, 1 ) );
			$label = sprintf( '%d" %s', (int) $r['width_in'], ucfirst( (string) $r['color'] ) );
			$html .= '<option value="' . esc_attr( $val ) . '" data-width="' . esc_attr( (int) $r['width_in'] ) . '" data-color="' . esc_attr( (string) $r['color'] ) . '">' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	private function body_html( int $user_id ): string {
		wp_enqueue_style( 'zprep-widget', ZPREP_URL . 'assets/css/widget.css', array(), zprep_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_style( 'zprep-print', ZPREP_URL . 'assets/css/print.css', array( 'zprep-widget' ), zprep_asset_ver( 'assets/css/print.css' ) );
		wp_enqueue_script( 'zprep-widget', ZPREP_URL . 'assets/js/widget.js', array(), zprep_asset_ver( 'assets/js/widget.js' ), true );
		wp_localize_script( 'zprep-widget', 'zprepWidgetData', $this->localized_data() );

		ob_start();
		?>
		<div class="zprep-w" id="zprep-widget">

			<!-- ── LOOKUP VIEW ───────────────────────────────────── -->
			<div id="zprep-w-lookup" class="zprep-w-panel">

				<div id="zprep-w-atc" class="zprep-w-atc">
					<div class="zprep-w-section-head">
						<h3><?php esc_html_e( 'Ready to Cut', 'zorderz' ); ?></h3>
						<button id="zprep-w-atc-reload" class="zprep-w-btn zprep-w-btn-ghost" type="button"><?php esc_html_e( 'Reload', 'zorderz' ); ?></button>
					</div>
					<p class="zprep-w-hint"><?php esc_html_e( 'Jobs in the configured cut stage. Tap one to plan its cuts.', 'zorderz' ); ?></p>
					<div id="zprep-w-atc-status" class="zprep-w-status" style="display:none;">
						<span class="zprep-w-spin"></span>
						<span id="zprep-w-atc-msg"><?php esc_html_e( 'Loading jobs…', 'zorderz' ); ?></span>
					</div>
					<div id="zprep-w-atc-list" class="zprep-w-atc-list"></div>
					<button id="zprep-w-atc-more" class="zprep-w-atc-more" type="button" style="display:none;"></button>
					<div id="zprep-w-atc-empty" class="zprep-w-atc-empty" style="display:none;"></div>
					<div id="zprep-w-atc-notice" class="zprep-w-atc-notice" style="display:none;"></div>
				</div>

				<div class="zprep-w-atc-divider"><span><?php esc_html_e( 'or look one up', 'zorderz' ); ?></span></div>

				<p class="zprep-w-lead"><?php esc_html_e( 'Plan cuts from a CRM lead or billing estimate.', 'zorderz' ); ?></p>
				<label class="zprep-w-label" for="zprep-w-search"><?php esc_html_e( 'Lead #, estimate #, customer name, or phone', 'zorderz' ); ?></label>
				<div class="zprep-w-search-row">
					<input type="text" id="zprep-w-search" class="zprep-w-input" placeholder="<?php esc_attr_e( 'e.g. 2585 or a customer name', 'zorderz' ); ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" />
					<button id="zprep-w-lookup-btn" class="zprep-w-btn zprep-w-btn-primary"><?php esc_html_e( 'Find', 'zorderz' ); ?></button>
				</div>
				<div id="zprep-w-lookup-status" class="zprep-w-status" style="display:none;">
					<span class="zprep-w-spin"></span>
					<span id="zprep-w-lookup-msg"><?php esc_html_e( 'Searching…', 'zorderz' ); ?></span>
				</div>
				<div id="zprep-w-lookup-error" class="zprep-w-error" style="display:none;"></div>
			</div>

			<!-- ── MATCH + MEASUREMENTS VIEW ─────────────────────── -->
			<div id="zprep-w-match" class="zprep-w-panel" style="display:none;">
				<div id="zprep-w-customer" class="zprep-w-customer"></div>

				<div class="zprep-w-section-head">
					<h3><?php esc_html_e( 'Measurements from the CRM', 'zorderz' ); ?></h3>
					<button id="zprep-w-refresh-notes" class="zprep-w-btn zprep-w-btn-ghost"><?php esc_html_e( 'Reload', 'zorderz' ); ?></button>
				</div>
				<p class="zprep-w-hint"><?php esc_html_e( 'Field-noted measurements are the source of truth. Review and edit before computing cuts.', 'zorderz' ); ?></p>

				<div id="zprep-w-measurements" class="zprep-w-measurements"></div>
				<div id="zprep-w-parse-error" class="zprep-w-error" style="display:none;"></div>

				<label class="zprep-w-lo-toggle" style="display:none;">
					<input type="checkbox" id="zprep-w-use-leftovers">
					<span><?php esc_html_e( 'Use available leftovers?', 'zorderz' ); ?></span>
					<small><?php esc_html_e( 'Draws from save-for-future inventory before pulling fresh roll.', 'zorderz' ); ?></small>
				</label>

				<div class="zprep-w-workspace-bar">
					<div class="zprep-w-ws-group">
						<label class="zprep-w-ws-label"><?php esc_html_e( 'Workspace', 'zorderz' ); ?></label>
						<div class="zprep-w-toggle-row" id="zprep-w-workspace-toggle">
							<button type="button" class="zprep-w-toggle-btn active" data-val="flat"><?php esc_html_e( 'Flat Table', 'zorderz' ); ?></button>
							<button type="button" class="zprep-w-toggle-btn" data-val="roller"><?php esc_html_e( 'Roller Table', 'zorderz' ); ?></button>
						</div>
					</div>
					<div class="zprep-w-ws-group">
						<label class="zprep-w-ws-label"><?php esc_html_e( 'Roll', 'zorderz' ); ?></label>
						<select id="zprep-w-roll-select" class="zprep-w-select"><?php echo $this->roll_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					</div>
				</div>

				<div class="zprep-w-actions">
					<button id="zprep-w-compute" class="zprep-w-btn zprep-w-btn-primary zprep-w-btn-full"><?php esc_html_e( 'Compute Cut Plan', 'zorderz' ); ?></button>
					<button id="zprep-w-reset" class="zprep-w-btn zprep-w-btn-secondary zprep-w-btn-full"><?php esc_html_e( 'Start Over', 'zorderz' ); ?></button>
					<label class="zprep-w-debug-toggle" for="zprep-w-debug-check">
						<input type="checkbox" id="zprep-w-debug-check"> <?php esc_html_e( 'Debug mode (show packing report)', 'zorderz' ); ?>
					</label>
				</div>
			</div>

			<!-- ── CUT PLAN VIEW ─────────────────────────────────── -->
			<div id="zprep-w-plan" class="zprep-w-panel" style="display:none;">
				<div id="zprep-w-plan-summary" class="zprep-w-summary"></div>

				<h3 class="zprep-w-h3"><?php esc_html_e( 'Cut Sheets', 'zorderz' ); ?></h3>
				<div id="zprep-w-pages" class="zprep-w-pages"></div>

				<h3 class="zprep-w-h3"><?php esc_html_e( 'Deliverables', 'zorderz' ); ?></h3>
				<div id="zprep-w-deliverables" class="zprep-w-deliverables"></div>

				<h3 class="zprep-w-h3"><?php esc_html_e( 'Material Used', 'zorderz' ); ?></h3>
				<div id="zprep-w-cost" class="zprep-w-cost"></div>

				<div class="zprep-w-actions">
					<button id="zprep-w-sync" class="zprep-w-btn zprep-w-btn-primary zprep-w-btn-full"><?php esc_html_e( '✓ Finished — Send to CRM', 'zorderz' ); ?></button>
					<button id="zprep-w-print" class="zprep-w-btn zprep-w-btn-ghost zprep-w-btn-full"><?php esc_html_e( '🖨 Print Cut Sheets', 'zorderz' ); ?></button>
					<button id="zprep-w-back-to-match" class="zprep-w-btn zprep-w-btn-secondary zprep-w-btn-full"><?php esc_html_e( 'Back to Measurements', 'zorderz' ); ?></button>
				</div>

				<button type="button" class="zprep-print-exit" style="display:none;"><?php esc_html_e( 'Exit print mode', 'zorderz' ); ?></button>
				<div id="zprep-w-sync-result" class="zprep-w-sync-result" style="display:none;"></div>
			</div>

			<!-- ── FULLSCREEN DIAGRAM MODAL ──────────────────────── -->
			<div id="zprep-w-modal" class="zprep-w-modal" style="display:none;">
				<div class="zprep-w-modal-bg"></div>
				<div class="zprep-w-modal-body">
					<button id="zprep-w-modal-close" class="zprep-w-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zorderz' ); ?>">&times;</button>
					<div id="zprep-w-modal-content"></div>
				</div>
			</div>

		</div>
		<?php
		return (string) ob_get_clean();
	}
}
