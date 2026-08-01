<?php
/**
 * ZSCH_Widget — the calendar UI (dashboard widget + full-page).
 *
 * Renders a lightweight HTML skeleton and enqueues the calendar JS/CSS. All
 * data loads asynchronously over the REST API (zorderz/v1/scheduler) after paint, matching
 * the Widget_App_Interface contract ("return lightweight HTML; load heavy data
 * via AJAX").
 *
 * should_render() mirrors TSIM: hide entirely under TSA customer-facing mode.
 *
 * v1.6.0 adds the CONNECTED CALENDARS card (flag-gated): a ⚙ header button +
 * modal where a user connects their own Google / Microsoft calendars as
 * conflict calendars. Rendered only for write-capable users (the kiosk never
 * sees it) and only when the feature flag + provider config are live. The
 * card's logic ships as its own small assets (connections.js / .css) so the
 * core calendar bundle is untouched.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Widget {

	/**
	 * Whether the scheduler should render at all for the current request.
	 * Hidden when TSA's customer-facing mode is active (never a visible
	 * refusal). Degrades to "true" when TSA isn't installed.
	 *
	 * @return bool
	 */
	public static function should_render() {
		$user_id = get_current_user_id();
		// If the analytics app exposes a customer-facing flag, honour it. Support
		// both method spellings the ecosystem has shipped (is_active_for_user is
		// the current one, matching the messaging module's gate). Guarded, so a
		// missing analytics app is not a hard dependency.
		if ( class_exists( 'TSA_Customer_Facing' ) ) {
			if ( is_callable( array( 'TSA_Customer_Facing', 'is_active_for_user' ) ) ) {
				return ! TSA_Customer_Facing::is_active_for_user( $user_id );
			}
			if ( is_callable( array( 'TSA_Customer_Facing', 'is_active' ) ) ) {
				return ! TSA_Customer_Facing::is_active( $user_id );
			}
		}
		// Filter hook so the platform can force-hide without a hard dependency.
		return (bool) apply_filters( 'zsch_should_render', true, $user_id );
	}

	/**
	 * Enqueue assets + render the skeleton.
	 *
	 * @param int    $user_id
	 * @param string $context 'inline' | 'fullpage'
	 * @return string|null
	 */
	public function render_dashboard_widget( $user_id, $context = 'inline' ) {
		if ( ! self::should_render() ) {
			return null;
		}

		// Cache-busting version = plugin version + the asset's file-modification
		// time. The ?ver= changes the instant a file's CONTENT changes, which
		// defeats NitroPack / CDN / PWA service-worker staleness without relying
		// on a manual version bump or the user clearing caches. (Same technique
		// the theme uses on its own app.js/app.css — see theme changelog v2.21.7.)
		$css_path = ZSCH_PLUGIN_DIR . 'assets/css/widget.css';
		$js_path  = ZSCH_PLUGIN_DIR . 'assets/js/widget.js';
		$css_ver  = ZSCH_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
		$js_ver   = ZSCH_VERSION . ( file_exists( $js_path ) ? '.' . filemtime( $js_path ) : '' );

		wp_enqueue_style(
			'zsch-widget-css',
			ZSCH_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			$css_ver
		);
		wp_enqueue_script(
			'zsch-widget-js',
			ZSCH_PLUGIN_URL . 'assets/js/widget.js',
			array(),
			$js_ver,
			true
		);
		// Ask page optimizers (NitroPack, Autoptimize, WP Rocket, Cloudflare
		// Rocket Loader) NOT to defer, combine, or lazy-load our script — those
		// transformations are a common cause of the widget JS silently not
		// running on a cached shell. Honored via the script_loader_tag filter
		// registered in this plugin's bootstrap.
		// (The flag is read off the handle; see zsch_mark_no_optimize().)

		$can_write = zsch_user_can_write( $user_id );
		$is_admin  = ZSCH_Appointments::viewer_is_admin( $user_id );
		$sync_on   = class_exists( 'ZSCH_Graph' ) && ZSCH_Graph::is_available();

		// v1.6.0 — Connected Calendars card: flag + config + write-capable user
		// (the read-only kiosk never sees the button, the modal, or the URLs).
		$conncal_on = $can_write
			&& class_exists( 'ZSCH_OAuth' )
			&& ZSCH_OAuth::feature_enabled();

		if ( $conncal_on ) {
			$cjs_path  = ZSCH_PLUGIN_DIR . 'assets/js/connections.js';
			$ccss_path = ZSCH_PLUGIN_DIR . 'assets/css/connections.css';
			wp_enqueue_style(
				'zsch-connections-css',
				ZSCH_PLUGIN_URL . 'assets/css/connections.css',
				array( 'zsch-widget-css' ),
				ZSCH_VERSION . ( file_exists( $ccss_path ) ? '.' . filemtime( $ccss_path ) : '' )
			);
			wp_enqueue_script(
				'zsch-connections-js',
				ZSCH_PLUGIN_URL . 'assets/js/connections.js',
				array( 'zsch-widget-js' ),
				ZSCH_VERSION . ( file_exists( $cjs_path ) ? '.' . filemtime( $cjs_path ) : '' ),
				true
			);
		}

		wp_localize_script( 'zsch-widget-js', 'zschData', array(
			'restUrl'   => ZSCH_REST::base_url(),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => (int) $user_id,
			'canWrite'  => (bool) $can_write,
			'isAdmin'   => (bool) $is_admin,
			'isReadOnly'=> zsch_user_is_read_only( $user_id ),
			'syncOn'    => (bool) $sync_on,
			'tz'        => ZSCH_Settings::default_tz(),
			'context'   => $context,
			// D2 cascade calendar (v1.5.0). Flag: update_option('zsch_views_v2','no') restores the classic month grid.
			'viewsV2'   => ( get_option( 'zsch_views_v2', 'yes' ) !== 'no' ),
			// v1.6.0 — Connected Calendars (absent/false when flag is down; the
			// start URLs are nonce-armed per session and carry NO secrets).
			'conncal'   => $conncal_on ? array(
				'enabled'        => true,
				'providers'      => ZSCH_OAuth::providers_available(),
				'startGoogle'    => ZSCH_OAuth::start_url( 'google' ),
				'startMicrosoft' => ZSCH_OAuth::start_url( 'microsoft' ),
			) : array( 'enabled' => false ),
		) );

		$embed_class = ! empty( $_GET['zdz_embed'] ) ? ' zsch-w--embed' : '';

		ob_start();
		?>
		<div class="zsch-w zsch-w--<?php echo esc_attr( $context ); ?><?php echo esc_attr( $embed_class ); ?>" id="zsch-widget" data-user-id="<?php echo (int) $user_id; ?>">

			<header class="zsch-w-hdr">
				<div class="zsch-w-hdr-left">
					<span class="zsch-w-logo">
						<svg class="zsch-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
						Schedule
					</span>
					<div class="zsch-w-nav">
						<button type="button" class="zsch-w-navbtn" id="zsch-prev" aria-label="Previous"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
						<button type="button" class="zsch-w-today" id="zsch-today">Today</button>
						<button type="button" class="zsch-w-navbtn" id="zsch-next" aria-label="Next"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
						<span class="zsch-w-period" id="zsch-period">—</span>
					</div>
				</div>
				<div class="zsch-w-hdr-right">
					<div class="zsch-w-tabs" role="tablist">
						<button type="button" class="zsch-w-tab is-active" data-view="month" role="tab">Calendar</button>
						<button type="button" class="zsch-w-tab" data-view="availability" role="tab">Availability</button>
						<button type="button" class="zsch-w-tab" data-view="team" role="tab">Team</button>
					</div>
					<?php if ( $conncal_on ) : ?>
						<button type="button" class="zsch-w-icon-btn" id="zsch-conncal-btn" title="Connected calendars" aria-label="Connected calendars">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>
						</button>
					<?php endif; ?>
					<?php if ( $sync_on ) : ?>
						<button type="button" class="zsch-w-icon-btn" id="zsch-sync" title="Sync with Outlook now" aria-label="Sync with Outlook">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
						</button>
					<?php endif; ?>
					<?php if ( $can_write ) : ?>
						<button type="button" class="zsch-w-add" id="zsch-add">+ New</button>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( zsch_user_is_read_only( $user_id ) ) : ?>
				<div class="zsch-w-readonly-banner">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					View only — the shared device can see the team calendar but can't make changes.
				</div>
			<?php endif; ?>

			<!-- Filter chips (scope) -->
			<div class="zsch-w-filters" id="zsch-filters">
				<button type="button" class="zsch-w-chip is-active" data-scope="all">All</button>
				<button type="button" class="zsch-w-chip" data-scope="personal">My appointments</button>
				<button type="button" class="zsch-w-chip" data-scope="shared">Team calendar</button>
				<span class="zsch-w-legend" id="zsch-legend"></span>
			</div>

			<!-- Main panes -->
			<main class="zsch-w-body">
				<section class="zsch-w-pane is-active" id="zsch-pane-month" role="tabpanel" aria-label="Calendar">
					<!-- D2 cascade (v1.5.0): month strip → week row → day drill-down.
					     Hidden until JS confirms viewsV2; the classic grid below stays
					     as the flag-off fallback. All navigation is TAP-driven with
					     visible chevrons (WCAG 2.5.1/F105); swipe is enhancement-only. -->
					<div class="zsch-casc" id="zsch-casc" hidden>
						<div class="zsch-casc-line zsch-casc-line--strip">
							<button type="button" class="zsch-casc-chev" id="zsch-casc-mprev" aria-label="Previous month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
							<div class="zsch-casc-strip" id="zsch-casc-strip" role="group" aria-label="Month overview — tap a week to load it below"></div>
							<button type="button" class="zsch-casc-chev" id="zsch-casc-mnext" aria-label="Next month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>
						</div>
						<div class="zsch-casc-line zsch-casc-line--wklabel">
							<button type="button" class="zsch-casc-chev zsch-casc-chev--wk" id="zsch-casc-wprev" aria-label="Previous week"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
							<span class="zsch-casc-wklabel" id="zsch-casc-wklabel" aria-live="polite">&mdash;</span>
							<button type="button" class="zsch-casc-chev zsch-casc-chev--wk" id="zsch-casc-wnext" aria-label="Next week"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>
						</div>
						<div class="zsch-casc-week" id="zsch-casc-week" role="group" aria-label="Days of the selected week"></div>
						<div class="zsch-casc-day" id="zsch-casc-day" role="region" hidden></div>
					</div>
					<div class="zsch-w-grid-head" id="zsch-grid-head"></div>
					<div class="zsch-w-grid" id="zsch-grid"></div>
				</section>

				<section class="zsch-w-pane" id="zsch-pane-availability" role="tabpanel" aria-label="My availability" hidden>
					<div class="zsch-w-avail-toolbar">
						<p class="zsch-w-hint">Pick <strong>Free</strong>, <strong>Busy</strong>, or <strong>Clear</strong>, then <strong>drag down a day</strong> to paint your hours (8am&ndash;8pm). Drag across days to paint several at once. Or tap <strong>Dictate</strong> to say it &mdash; e.g. &ldquo;free Monday to Wednesday.&rdquo;</p>
						<div class="zsch-w-avail-actions" role="group" aria-label="Paint mode">
							<button type="button" class="zsch-w-pill is-active" id="zsch-paint-open" data-kind="open" aria-pressed="true">Free</button>
							<button type="button" class="zsch-w-pill" id="zsch-paint-busy" data-kind="busy" aria-pressed="false">Busy</button>
							<button type="button" class="zsch-w-pill" id="zsch-paint-clear" data-kind="clear" aria-pressed="false">Clear</button>
							<button type="button" class="zsch-w-pill zsch-w-pill--ghost" id="zsch-dictate">&#127908; Dictate</button>
						</div>
					</div>
					<div class="zsch-w-avail-statusrow">
						<span class="zsch-w-avail-mode" id="zsch-avail-mode">Painting: Available</span>
						<span class="zsch-w-avail-legend">
							<span class="zsch-w-legend-item"><span class="zsch-w-legend-sw zsch-w-legend-sw--free"></span>Available</span>
							<span class="zsch-w-legend-item"><span class="zsch-w-legend-sw zsch-w-legend-sw--busy"></span>Busy</span>
							<span class="zsch-w-legend-item"><span class="zsch-w-legend-sw zsch-w-legend-sw--none"></span>Not set</span>
						</span>
					</div>
					<div class="zsch-w-grid-head" id="zsch-avail-head"></div>
					<div class="zsch-w-grid zsch-w-grid--avail" id="zsch-avail-grid"></div>
				</section>

				<section class="zsch-w-pane" id="zsch-pane-team" role="tabpanel" aria-label="Team availability" hidden>
					<div class="zsch-w-team" id="zsch-team"></div>
				</section>
			</main>

			<!-- Loading / empty overlays -->
			<div class="zsch-w-loading" id="zsch-loading" hidden><span class="zsch-w-spin"></span> Loading…</div>

			<!-- Event editor modal -->
			<div class="zsch-w-modal" id="zsch-modal" hidden>
				<div class="zsch-w-modal-card" role="dialog" aria-modal="true" aria-labelledby="zsch-modal-title">
					<header class="zsch-w-modal-hdr">
						<h3 id="zsch-modal-title">New appointment</h3>
						<button type="button" class="zsch-w-icon-btn" id="zsch-modal-close" aria-label="Close">✕</button>
					</header>
					<div class="zsch-w-modal-body">
						<label class="zsch-w-field">
							<span>Title</span>
							<input type="text" id="zsch-f-title" autocomplete="off" placeholder="e.g. Install — Job #4821">
						</label>
						<div class="zsch-w-field-row">
							<label class="zsch-w-field">
								<span>Starts</span>
								<input type="datetime-local" id="zsch-f-start">
							</label>
							<label class="zsch-w-field">
								<span>Ends</span>
								<input type="datetime-local" id="zsch-f-end">
							</label>
						</div>
						<label class="zsch-w-check"><input type="checkbox" id="zsch-f-allday"> All day</label>
						<label class="zsch-w-field">
							<span>Location</span>
							<input type="text" id="zsch-f-location" autocomplete="off" placeholder="Address or place">
						</label>
						<div class="zsch-w-field-row">
							<label class="zsch-w-field">
								<span>Calendar</span>
								<select id="zsch-f-scope">
									<option value="personal">My calendar (private)</option>
									<option value="shared">Team calendar (shared)</option>
								</select>
							</label>
							<label class="zsch-w-field">
								<span>Show as</span>
								<select id="zsch-f-busy">
									<option value="busy">Busy</option>
									<option value="free">Free</option>
									<option value="tentative">Tentative</option>
									<option value="oof">Out of office</option>
								</select>
							</label>
						</div>
						<label class="zsch-w-field">
							<span>Notes</span>
							<textarea id="zsch-f-body" rows="2" placeholder="Optional details"></textarea>
						</label>
						<label class="zsch-w-field">
							<span>Invite (emails, comma-separated)</span>
							<input type="text" id="zsch-f-attendees" autocomplete="off" placeholder="name@company.com, …">
						</label>
						<p class="zsch-w-sync-note" id="zsch-sync-note"></p>
					</div>
					<footer class="zsch-w-modal-ftr">
						<button type="button" class="zsch-w-btn zsch-w-btn--danger" id="zsch-delete" hidden>Delete</button>
						<span style="flex:1"></span>
						<button type="button" class="zsch-w-btn zsch-w-btn--ghost" id="zsch-cancel">Cancel</button>
						<button type="button" class="zsch-w-btn zsch-w-btn--primary" id="zsch-save">Save</button>
					</footer>
				</div>
			</div>

			<!-- Dictation modal — uses the DEVICE's own dictation, not an in-app recorder -->
			<div class="zsch-w-modal" id="zsch-dictate-modal" hidden>
				<div class="zsch-w-modal-card zsch-w-modal-card--sm" role="dialog" aria-modal="true">
					<header class="zsch-w-modal-hdr">
						<h3>Say your availability</h3>
						<button type="button" class="zsch-w-icon-btn" id="zsch-dictate-close" aria-label="Close">✕</button>
					</header>
					<div class="zsch-w-modal-body">
						<p class="zsch-w-hint">Tap the box, then use your device's dictation to speak it — or just type. For example: <em>"open June 16 to June 18"</em> or <em>"busy next Monday."</em></p>
						<div class="zsch-w-dictate-row">
							<input type="text" id="zsch-dictate-input" placeholder="open Monday to Wednesday" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="done">
						</div>
						<p class="zsch-w-dictate-tip" id="zsch-dictate-tip"></p>
						<div class="zsch-w-dictate-preview" id="zsch-dictate-preview"></div>
					</div>
					<footer class="zsch-w-modal-ftr">
						<span style="flex:1"></span>
						<button type="button" class="zsch-w-btn zsch-w-btn--ghost" id="zsch-dictate-cancel">Cancel</button>
						<button type="button" class="zsch-w-btn zsch-w-btn--primary" id="zsch-dictate-apply">Apply</button>
					</footer>
				</div>
			</div>

			<!-- Day-detail popover: all events for one day, per person -->
			<div class="zsch-w-modal" id="zsch-day-modal" hidden>
				<div class="zsch-w-modal-card zsch-w-modal-card--sm" role="dialog" aria-modal="true" aria-labelledby="zsch-day-title">
					<header class="zsch-w-modal-hdr">
						<h3 id="zsch-day-title">Day</h3>
						<button type="button" class="zsch-w-icon-btn" id="zsch-day-close" aria-label="Close">✕</button>
					</header>
					<div class="zsch-w-modal-body zsch-w-day-body" id="zsch-day-body"></div>
					<footer class="zsch-w-modal-ftr">
						<button type="button" class="zsch-w-btn zsch-w-btn--primary" id="zsch-day-add">+ Add on this day</button>
						<span style="flex:1"></span>
						<button type="button" class="zsch-w-btn zsch-w-btn--ghost" id="zsch-day-done">Done</button>
					</footer>
				</div>
			</div>

			<?php if ( $conncal_on ) : ?>
			<!-- v1.6.0 — Connected Calendars card -->
			<div class="zsch-w-modal" id="zsch-conncal-modal" hidden>
				<div class="zsch-w-modal-card" role="dialog" aria-modal="true" aria-labelledby="zsch-conncal-title">
					<header class="zsch-w-modal-hdr">
						<h3 id="zsch-conncal-title">Connected calendars</h3>
						<button type="button" class="zsch-w-icon-btn" id="zsch-conncal-close" aria-label="Close">✕</button>
					</header>
					<div class="zsch-w-modal-body">
						<p class="zsch-w-hint">Link your own calendars (personal Google, another Microsoft&nbsp;365 account) so the team scheduler knows when you're busy outside this app. Only you see event details — teammates just see busy time<?php echo '.'; ?></p>
						<div class="zsch-conncal-list" id="zsch-conncal-list" aria-live="polite">
							<p class="zsch-conncal-empty">Loading…</p>
						</div>
					</div>
					<footer class="zsch-w-modal-ftr zsch-conncal-ftr">
						<button type="button" class="zsch-w-btn zsch-w-btn--primary" id="zsch-conncal-google" hidden>Connect Google Calendar</button>
						<button type="button" class="zsch-w-btn zsch-w-btn--primary" id="zsch-conncal-ms" hidden>Connect Microsoft Calendar</button>
						<span style="flex:1"></span>
						<button type="button" class="zsch-w-btn zsch-w-btn--ghost" id="zsch-conncal-done">Done</button>
					</footer>
				</div>
			</div>
			<?php endif; ?>

			<div class="zsch-w-toast" id="zsch-toast" role="status" aria-live="polite" hidden></div>
		</div>
		<?php
		// ── Inline boot guarantee (v1.0.5) ──────────────────────────────────
		// This inline script ships INSIDE the widget HTML, so the theme's
		// renderWidgets() re-executes it even when the page shell is cached. Its
		// job: make sure widget.js actually loaded and initialized. If, a moment
		// after render, the calendar grid is still empty (because a stale cache /
		// optimizer prevented the enqueued widget.js from running), it
		// dynamically (re)injects widget.js with a fresh cache-busting query so
		// the calendar always comes up — with no need for the user to clear any
		// cache. Idempotent and self-limiting.
		$boot_js_src = esc_url( ZSCH_PLUGIN_URL . 'assets/js/widget.js' );
		$boot_ver    = esc_js( $js_ver );
		?>
		<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-nitro-exclude>
		(function () {
			var SRC = <?php echo wp_json_encode( $boot_js_src ); ?>;
			var VER = <?php echo wp_json_encode( $boot_ver ); ?>;
			function gridReady() {
				var g = document.getElementById('zsch-grid');
				return g && g.children && g.children.length > 0;
			}
			function alreadyLoading() {
				return !!document.querySelector('script[data-zsch-fallback]');
			}
			function injectFresh() {
				if (alreadyLoading()) { return; }
				var s = document.createElement('script');
				// Cache-bust hard: file-version + a load-time stamp so neither the
				// browser cache, a CDN, nor a service worker can serve a stale copy.
				s.src = SRC + (SRC.indexOf('?') > -1 ? '&' : '?') + 'ver=' + encodeURIComponent(VER) + '&cb=' + Date.now();
				s.async = true;
				s.setAttribute('data-zsch-fallback', '1');
				s.setAttribute('data-no-optimize', '1');
				s.setAttribute('data-cfasync', 'false');
				document.body.appendChild(s);
			}
			// Give the normally-enqueued widget.js a chance first; if the grid is
			// still empty shortly after, force a fresh copy. Re-check a couple of
			// times to cover slow networks, then stop.
			var tries = 0;
			function check() {
				if (gridReady()) { return; }
				injectFresh();
				if (++tries < 4) { setTimeout(check, 700); }
			}
			setTimeout(check, 600);
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
