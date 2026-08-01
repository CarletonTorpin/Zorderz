<?php
/**
 * Zorderz Jobs — dashboard app/widget (implements the theme's Widget_App_Interface).
 *
 * bridge_type 'inline_widget': the theme wraps this in the unified dashboard shell,
 * so render_dashboard_widget() returns ONLY the body content. Styling + behaviour
 * live in external assets/css/widget.css + assets/js/widget.js, built on the theme's
 * design tokens. Returns null (hides the widget) for the kiosk — never on a shared
 * device. The AJAX list endpoint scopes what rows a caller actually gets.
 *
 * This class implements the theme's interface, so it is only ever required from
 * inside after_setup_theme (see app.php), by which point \Zorderz\Widget_App_Interface
 * is defined.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_App implements \Zorderz\Widget_App_Interface {

	const APP_ID = 'jobs';

	public function get_config(): array {
		return array(
			'id'          => self::APP_ID,
			'nm'          => 'Jobs',
			'icon'        => 'clipboard-list',
			'cat'         => 'Field',
			'cc'          => '#0E9F8E',
			'desc'        => 'Your jobs to do, and work assigned to specialists. Split a job component off to a teammate as a separate tracked job.',
			// Everyone with a real seat; NOT the shared kiosk.
			'roles'       => (array) apply_filters( 'zdz_job_roles', array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' ) ),
			'bridge_type' => 'inline_widget',
			'admin_url'   => '',
		);
	}

	/** Full-screen mobile view — the theme uses this for the springboard tile tap. */
	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zjob-fullscreen" data-app-id="jobs">' . $this->body_html( $user_id ) . '</div>';
	}

	/**
	 * Inline dashboard widget body. Returns null (hides) for the kiosk. Everyone else
	 * sees the widget; the AJAX list endpoint scopes the rows.
	 */
	public function render_dashboard_widget( int $user_id ): ?string {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return null; // never on the shared device
		}
		return $this->body_html( $user_id );
	}

	/** The widget BODY (no card frame — the theme provides it). */
	private function body_html( int $user_id ): string {
		$can_handoff = ZJOB_Jobs::user_can_hand_off( $user_id );
		$is_lead     = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_crew_lead( $user_id );

		// Job creation flows through the Estimates app ("Send as job(s)"). The legacy
		// in-widget manual form is RETIRED by default, kept behind a filter for a site
		// that wants a manual (non-estimate) path.
		$show_form = $can_handoff && (bool) apply_filters( 'zdz_job_show_manual_form', false );

		wp_enqueue_style(
			'zjob-widget',
			ZJOB_URL . 'assets/css/widget.css',
			array(),
			zjob_asset_ver( 'assets/css/widget.css' )
		);
		wp_enqueue_script(
			'zjob-widget',
			ZJOB_URL . 'assets/js/widget.js',
			array(),
			zjob_asset_ver( 'assets/js/widget.js' ),
			true
		);
		wp_localize_script( 'zjob-widget', 'zjobWidget', array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( ZJOB_NONCE ),
			'version'      => ZJOB_VERSION,
			'canHandoff'   => (bool) $can_handoff,
			'isLead'       => (bool) $is_lead,
			'minPhotos'    => (int) ZJOB_Jobs::min_finish_photos(),
			'accuracyMax'  => (int) ZJOB_Jobs::gps_accuracy_max_m(),
			'closeMaxDays' => (int) ZJOB_Jobs::close_max_days(),
			'closeSoonDays' => (int) ZJOB_Jobs::close_soon_days(),
			'components'   => function_exists( 'zjob_components' ) ? zjob_components() : array( 'other' => 'Other' ),
		) );

		$components = function_exists( 'zjob_components' ) ? zjob_components() : array( 'other' => __( 'Other', 'zorderz' ) );

		ob_start();
		?>
		<div class="zjob-w" id="zjob-widget"
			data-can-handoff="<?php echo $can_handoff ? '1' : '0'; ?>"
			data-is-lead="<?php echo $is_lead ? '1' : '0'; ?>">

			<?php if ( $show_form ) : ?>
			<div class="zjob-w-tabs" role="tablist">
				<button class="zjob-w-tab is-active" data-tab="list" role="tab"><?php echo $is_lead ? esc_html__( 'My crew\'s jobs', 'zorderz' ) : esc_html__( 'My jobs', 'zorderz' ); ?></button>
				<button class="zjob-w-tab" data-tab="new" role="tab"><?php esc_html_e( 'New job', 'zorderz' ); ?></button>
			</div>
			<?php endif; ?>

			<!-- LIST PANEL -->
			<div class="zjob-w-panel is-active" data-panel="list">
				<div class="zjob-w-filters" role="group" aria-label="<?php esc_attr_e( 'Past, present and future jobs', 'zorderz' ); ?>">
					<button class="zjob-chip is-active" data-bucket="present"><?php esc_html_e( 'Present', 'zorderz' ); ?></button>
					<button class="zjob-chip" data-bucket="future"><?php esc_html_e( 'Future', 'zorderz' ); ?></button>
					<button class="zjob-chip" data-bucket="past"><?php esc_html_e( 'Past', 'zorderz' ); ?></button>
				</div>
				<div class="zjob-w-list" id="zjob-list" aria-live="polite">
					<div class="zjob-empty zjob-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
				</div>
			</div>

			<?php if ( $show_form ) : ?>
			<!-- NEW JOB PANEL -->
			<div class="zjob-w-panel" data-panel="new">
				<p class="zjob-note"><?php esc_html_e( 'Split a sub-job to a specialist. Tracked here and in the CRM', 'zorderz' ); ?> &mdash; <strong><?php esc_html_e( 'not', 'zorderz' ); ?></strong> <?php esc_html_e( 'on the customer\'s billing document.', 'zorderz' ); ?></p>
				<div class="zjob-field-row">
					<div class="zjob-field">
						<label for="zjob-component"><?php esc_html_e( 'Component', 'zorderz' ); ?></label>
						<select id="zjob-component">
							<?php foreach ( $components as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="zjob-field">
						<label for="zjob-assignee"><?php esc_html_e( 'Assign to', 'zorderz' ); ?></label>
						<select id="zjob-assignee"><option value=""><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</option></select>
					</div>
				</div>
				<div class="zjob-field-row">
					<div class="zjob-field">
						<label for="zjob-customer"><?php esc_html_e( 'Customer', 'zorderz' ); ?></label>
						<input id="zjob-customer" type="text" placeholder="<?php esc_attr_e( 'Customer name', 'zorderz' ); ?>" autocapitalize="words" />
					</div>
					<div class="zjob-field">
						<label for="zjob-business"><?php esc_html_e( 'Business', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(optional)', 'zorderz' ); ?></span></label>
						<input id="zjob-business" type="text" placeholder="<?php esc_attr_e( 'Business name', 'zorderz' ); ?>" autocapitalize="words" />
					</div>
				</div>
				<div class="zjob-field">
					<label for="zjob-address"><?php esc_html_e( 'Site address', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(taps to open maps)', 'zorderz' ); ?></span></label>
					<input id="zjob-address" type="text" placeholder="<?php esc_attr_e( 'e.g. 12 Oak St, Springfield', 'zorderz' ); ?>" autocapitalize="words" />
				</div>
				<div class="zjob-field">
					<label for="zjob-phone"><?php esc_html_e( 'Phone', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(taps to call)', 'zorderz' ); ?></span></label>
					<input id="zjob-phone" type="tel" placeholder="<?php esc_attr_e( 'e.g. (555) 123-4567', 'zorderz' ); ?>" inputmode="tel" />
				</div>
				<div class="zjob-field-row">
					<div class="zjob-field">
						<label for="zjob-brand"><?php esc_html_e( 'Brand / model', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(optional)', 'zorderz' ); ?></span></label>
						<input id="zjob-brand" type="text" placeholder="<?php esc_attr_e( 'optional', 'zorderz' ); ?>" />
					</div>
					<div class="zjob-field zjob-field-qty">
						<label for="zjob-qty"><?php esc_html_e( 'Qty', 'zorderz' ); ?></label>
						<input id="zjob-qty" type="number" min="0" value="1" inputmode="numeric" />
					</div>
				</div>
				<div class="zjob-field-row">
					<div class="zjob-field">
						<label for="zjob-source"><?php esc_html_e( 'Source ref', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(optional)', 'zorderz' ); ?></span></label>
						<input id="zjob-source" type="text" placeholder="<?php esc_attr_e( 'e.g. estimate #1234', 'zorderz' ); ?>" />
					</div>
					<div class="zjob-field">
						<label for="zjob-parent"><?php esc_html_e( 'CRM lead #', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(optional)', 'zorderz' ); ?></span></label>
						<input id="zjob-parent" type="number" min="0" placeholder="<?php esc_attr_e( 'e.g. 7328', 'zorderz' ); ?>" inputmode="numeric" />
					</div>
				</div>
				<div class="zjob-field">
					<label for="zjob-access"><?php esc_html_e( 'Site / access notes', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(gate code, dog, parking)', 'zorderz' ); ?></span></label>
					<textarea id="zjob-access" rows="2" placeholder="<?php esc_attr_e( 'Anything the specialist needs to get on site.', 'zorderz' ); ?>"></textarea>
				</div>
				<div class="zjob-field">
					<label for="zjob-notes"><?php esc_html_e( 'Job notes', 'zorderz' ); ?> <span class="zjob-opt"><?php esc_html_e( '(internal only)', 'zorderz' ); ?></span></label>
					<textarea id="zjob-notes" rows="2" placeholder="<?php esc_attr_e( 'Sizes, details, anything the specialist needs.', 'zorderz' ); ?>"></textarea>
				</div>
				<div class="zjob-actions">
					<button class="zjob-btn zjob-btn-primary" id="zjob-create">
						<i data-lucide="split"></i><span><?php esc_html_e( 'Assign job', 'zorderz' ); ?></span>
					</button>
					<span class="zjob-inline-status" id="zjob-create-status" aria-live="polite"></span>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
