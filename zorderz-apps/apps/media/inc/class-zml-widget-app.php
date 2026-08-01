<?php
/**
 * ZML_Widget_App — the dashboard WIDGET surface (bridge_type inline_widget).
 *
 * Renders a compact "recent media" card body into the theme's
 * .dash-widget-body. The theme supplies the card chrome (icon, title, reorder
 * arrows) and revives the inline bootstrap <script> we emit here (see app.js
 * "Activate inline <script> tags inside widget HTML"). The bootstrap simply
 * hands off to the globally-enqueued controller window.TSMedia.mountWidget().
 *
 * Design: this is an at-a-glance surface, NOT the full gallery. It honours the
 * theme's WIDGET-OVERFLOW-CONTRACT (no nested scroll container — the body grows
 * to natural height inside the page scroll). Infinite scroll and the roomy grid
 * live in the full-screen surface, opened via the "See All" button.
 *
 * @package ZDZ_Media
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZML_Widget_App implements \Zorderz\Widget_App_Interface {

	public function get_config(): array {
		return [
			'id'          => 'zdz-media',
			'nm'          => 'Media',
			'name'        => 'Media',
			'icon'        => 'images',
			'cat'         => 'Tools',
			'cc'          => '#2C5F8A',  // theme brand (matches --sys-brand light)
			'desc'        => 'Recent photos & sketches — yours and the organization\'s',
			'description' => 'Recent photos & sketches — yours and the organization\'s',
			'roles'       => [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ],
			'bridge_type' => 'inline_widget',
			'admin_url'   => '',
			'order'       => 60,
		];
	}

	/**
	 * Full-screen path. An inline_widget app is opened on the dashboard, not via
	 * the full-screen Bridge, so this is only a graceful fallback if something
	 * loads the widget id through load-app. Point the user at the dashboard.
	 */
	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zml-fallback" style="padding:24px;text-align:center;color:var(--sys-text-sec,#475569)">'
			. 'Media lives on your dashboard. Close this and scroll to the Media card.'
			. '</div>';
	}

	/**
	 * The dashboard widget body. Lightweight skeleton + an inline bootstrap that
	 * defers to the globally-loaded controller. No data is fetched here; the
	 * controller loads the recent slice via AJAX after mount (per the theme's
	 * "heavy data loads async after render" guidance).
	 */
	public function render_dashboard_widget( int $user_id ): ?string {
		// v2.3.0: admins additionally get the "All" scope on the widget (browse
		// every photo incl. private). Non-admins see only Mine / Organization.
		// The server re-authorizes scope=all on every request regardless.
		$is_admin = function_exists( 'zml_current_user_is_admin' ) && zml_current_user_is_admin();
		ob_start();
		?>
		<div class="zml-w" id="zml-w" data-surface="widget">
			<div class="zml-w-bar">
				<div class="zml-seg" role="tablist" aria-label="Library scope">
					<button class="zml-seg-btn zml-active" data-scope="mine"   role="tab" aria-selected="true">My&nbsp;Photos</button>
					<button class="zml-seg-btn"            data-scope="public" role="tab" aria-selected="false">Organization</button>
					<?php if ( $is_admin ) : ?>
					<button class="zml-seg-btn"            data-scope="all"    role="tab" aria-selected="false">All</button>
					<?php endif; ?>
				</div>
				<div class="zml-w-actions">
					<button type="button" class="zml-add zml-add-sm" data-action="add" aria-label="Add photos">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						<span>Add</span>
					</button>
					<button type="button" class="zml-seeall" data-action="seeall">
						See&nbsp;All
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
					</button>
				</div>
			</div>
			<div class="zml-w-chips" role="group" aria-label="Filter by type">
				<button class="zml-chip zml-active" data-type="">All</button>
				<button class="zml-chip" data-type="photo">Photos</button>
				<button class="zml-chip" data-type="sketch">Sketches</button>
			</div>
			<div class="zml-w-grid" id="zml-w-grid" aria-live="polite">
				<div class="zml-loading">Loading…</div>
			</div>
		</div>
		<script>
		/* Bootstrap: defer to the globally-enqueued controller. This inline
		 * script DOES run on the dashboard because the theme revives
		 * .dash-widget-body scripts (app.js). It is intentionally tiny. */
		(function () {
			function boot() {
				if (window.TSMedia && typeof window.TSMedia.mountWidget === 'function') {
					window.TSMedia.mountWidget(document.getElementById('zml-w'));
				} else {
					// Controller not parsed yet — retry briefly, then on the
					// theme's widgets-rendered event as a backstop.
					setTimeout(boot, 60);
				}
			}
			boot();
			document.addEventListener('zdz_widgets_rendered', boot, { once: true });
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
