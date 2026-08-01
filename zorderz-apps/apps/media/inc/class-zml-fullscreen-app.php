<?php
/**
 * ZML_Fullscreen_App — the full-screen GALLERY surface (bridge_type ajax_html).
 *
 * This is the roomy "see everything" view, opened by the widget's "See All"
 * button (window.TSMedia → Bridge.loadApp('zdz-media-all')) and also available
 * as its own dock tile. The theme injects this HTML into #app-body via
 * innerHTML.
 *
 * CRITICAL — why there's no inline <script> here:
 *   innerHTML does NOT execute injected <script> (inline OR src), and Bridge
 *   v3.2 does not (yet) revive #app-body scripts. So instead of shipping a
 *   bootstrap that would silently never run, we ship ONLY markup. The globally-
 *   enqueued controller (window.TSMedia, loaded on the front page) detects this
 *   host container and mounts into it. This works TODAY, with no dependency on
 *   the pending theme revival. If/when the theme revives #app-body scripts, a
 *   bootstrap could be added, but it is not required.
 *
 * The controller discovers this surface two ways (belt & suspenders):
 *   1. It listens for the Bridge 'navigate'/load flow and, on the next frame,
 *      looks for #zml-fs inside #app-body.
 *   2. A lightweight MutationObserver on #app-body (installed once by the
 *      controller) catches the injection regardless of how it was triggered
 *      (dock tile, deep link, etc.).
 *
 * @package ZDZ_Media
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZML_Fullscreen_App implements \Zorderz\App_Interface {

	public function get_config(): array {
		return [
			'id'          => 'zdz-media-all',
			'nm'          => 'Media',
			'name'        => 'Media Library',
			'icon'        => 'images',
			'cat'         => 'Tools',
			'cc'          => '#2C5F8A',
			'desc'        => 'Browse all organization photos & sketches, and your own uploads',
			'description' => 'Browse all organization photos & sketches, and your own uploads',
			'roles'       => [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ],
			'bridge_type' => 'ajax_html',
			'admin_url'   => '',
			// v2.0.1: This is the SECONDARY surface of the Media app. The PRIMARY
			// user-facing entry is the 'zdz-media' dashboard widget (its tile +
			// card); this full-screen gallery is reached from that widget's "See
			// All" (Bridge.loadApp('zdz-media-all')). Registering it as a normal
			// app made it render as its OWN second "Media" dock tile (the
			// duplicate the owner saw), because the theme lists every app config
			// with nm/icon/cc. `springboard:false` (theme v2.21.0) keeps the app
			// fully loadable — it stays server-registered and in zdz_allowed_apps
			// so /load-app authorizes it and the viewport header reads "Media
			// Library" — but excludes it from the springboard grid, dock, recents,
			// and command palette. One app, one tile; the gallery opens from "See
			// All" exactly as before.
			'springboard' => false,
			'order'       => 61,
		];
	}

	/**
	 * Markup-only host. The global controller mounts the gallery into #zml-fs.
	 * We render the scope/type controls server-side so the surface looks correct
	 * the instant it appears (before the controller's first paint), and a
	 * loading state inside the grid.
	 */
	public function render_mobile_view( int $user_id ): void {
		// v2.3.0: the admin-only "All" scope (browse every photo org-wide,
		// incl. private) is rendered ONLY for admins. This mirrors the app's
		// existing admin definition (delete-any-photo). The zml_list endpoint
		// re-checks admin on every request, so hiding the tab is a convenience,
		// not the gate.
		$is_admin = function_exists( 'zml_current_user_is_admin' ) && zml_current_user_is_admin();
		?>
		<div class="zml-fs" id="zml-fs" data-surface="fullscreen">
			<div class="zml-fs-bar">
				<div class="zml-seg" role="tablist" aria-label="Library scope">
					<button class="zml-seg-btn zml-active" data-scope="public" role="tab" aria-selected="true">Organization</button>
					<button class="zml-seg-btn"            data-scope="mine"   role="tab" aria-selected="false">My&nbsp;Photos</button>
					<?php if ( $is_admin ) : ?>
					<button class="zml-seg-btn"            data-scope="all"    role="tab" aria-selected="false">All</button>
					<?php endif; ?>
				</div>
				<div class="zml-chips" role="group" aria-label="Filter by type">
					<button class="zml-chip zml-active" data-type="">All</button>
					<button class="zml-chip" data-type="photo">Photos</button>
					<button class="zml-chip" data-type="sketch">Sketches</button>
				</div>
				<button type="button" class="zml-add" data-action="add" aria-label="Add photos">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
					<span>Add&nbsp;Photos</span>
				</button>
				<?php /* v2.2.0 — toggles selection mode (tap photos to check them,
				        then bulk-delete via the action bar). The controller wires
				        this via [data-action="select"]; only photos the viewer may
				        delete are selectable (owner-or-admin, server-enforced). */ ?>
				<button type="button" class="zml-select-toggle" data-action="select" aria-pressed="false" aria-label="Select photos">Select</button>
			</div>
			<div class="zml-fs-scroll" id="zml-fs-scroll">
				<div class="zml-fs-grid-wrap" id="zml-fs-grid-wrap">
					<div class="zml-loading">Loading…</div>
				</div>
				<div class="zml-sentinel" id="zml-fs-sentinel" aria-hidden="true"></div>
			</div>
		</div>
		<?php
	}
}
