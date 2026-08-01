<?php
/**
 * The front page template file (SPA Shell)
 *
 * @package Zorderz
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Auth check is handled by the template_redirect hook in functions.php,
// which runs BEFORE this template loads. If we reach this point, the user
// is authenticated. This is a safety net only.
if ( ! is_user_logged_in() ) {
	status_header( 200 );
	include get_theme_file_path( 'page-login.php' );
	exit;
}

get_header(); ?>

<!-- ============================================================
     VIEW: MAIN APP (SPA SHELL)
     ============================================================ -->
<div id="view-main" class="view active">

	<!-- === SUB-VIEW: DASHBOARD (unified — apps + widgets) === -->
	<div id="sv-dash" class="sub-view active" role="main">
		<!-- v2.16.0 T15: Sticky dash-top wrapper (greeting + search) -->
		<div id="dash-top" class="dash-top">
			<!-- Greeting + Refresh -->
			<div class="greeting-row" id="greeting-row"></div>

			<!-- v2.23.0: Inline ask field — type directly here; results drop down
			     beneath it (no pop-up overlay). -->
			<div class="dash-ask" id="dash-ask">
				<div class="dash-ask-field">
					<i data-lucide="search" style="width:18px;height:18px"></i>
					<input type="text" id="cmd-input"
						autocorrect="off"
						autocapitalize="off"
						spellcheck="false"
						autocomplete="off"
						placeholder="<?php esc_attr_e( 'Search apps or ask a question', 'zorderz' ); ?>"
						aria-label="<?php esc_attr_e( 'Search apps or ask a question', 'zorderz' ); ?>"
						role="combobox" aria-expanded="false" aria-controls="cmd-results" aria-autocomplete="list">
				</div>
				<!-- Results dropdown (hidden until the user types) -->
				<div class="cmd-results" id="cmd-results" role="listbox"></div>
			</div>
		</div>

		<!-- v2.16.0 T4: Sticky compact app bar (visible when dock scrolls out) -->
		<div id="dash-sticky" class="dash-sticky"></div>

		<!-- v2.14.4 A4: App Dock — icon buttons for all permitted apps -->
		<div id="app-dock" class="app-dock" aria-label="<?php esc_attr_e( 'Your apps', 'zorderz' ); ?>"></div>

		<!-- v2.21.3: Leads action tile — "you have N new leads today" for the
		     signed-in salesperson. Populated by renderLeadsTile() from the
		     unified /zorderz/v1/dashboard-items feed; hidden when there's nothing
		     pending or for roles that don't carry assigned leads. -->
		<div id="leads-tile" class="leads-tile" aria-live="polite"></div>

		<!-- Quick Stats -->
		<div class="quick-stats" id="quick-stats" aria-label="<?php esc_attr_e( 'Quick statistics', 'zorderz' ); ?>"></div>

		<!-- Widget Zone (v2.0): Inline plugin widgets render here -->
		<div id="dash-widget-zone" class="dash-widget-zone"></div>

		<!-- Recently Used -->
		<div id="recent-section" style="margin-bottom:var(--ref-space-3)">
			<div class="section-label"><?php esc_html_e( 'Recently Used', 'zorderz' ); ?></div>
			<div class="recent-row" id="recent-row"></div>
		</div>

		<!-- App Grid (role-adaptive) -->
		<div id="app-grid-container"></div>

		<!-- Hidden App Bridge Container -->
		<div id="app-container" style="display:none;"></div>

		<!-- v2.24.2: tiny build stamp so the running theme build is verifiable at a
		     glance (stale-PWA-shell diagnosis). Populated by JS from zdzData.themeVersion. -->
		<div class="zdz-build-stamp" id="zdz-build-stamp" aria-hidden="true"></div>
	</div>

	<!-- === SUB-VIEW: CHAT (injected by Analytics plugin) === -->

	<!-- === SUB-VIEW: SETTINGS === -->
	<div id="sv-settings" class="sub-view" role="main">
		<div id="profile-card-area"></div>
		<div class="settings-section">
			<h4><?php esc_html_e( 'Appearance', 'zorderz' ); ?></h4>
			<div class="theme-grid" id="theme-grid"></div>
		</div>
		<div class="settings-section" id="settings-info"></div>
	</div>

	<!-- Bottom Nav -->
	<?php
	// Logo for the nav. The Business Profile is authoritative; the pre-profile
	// theme mods stay as a fallback so an existing install does not lose its
	// artwork the day it upgrades.
	//
	// The nav is a DARK surface, so it asks for a dark background and gets the
	// light-ink artwork back. The slot is small and roughly square, hence 'square'.
	$bnav_logo_dark     = get_theme_mod( 'zdz_logo_dark', '' );
	$bnav_logo_light    = get_theme_mod( 'zdz_logo_light', '' );
	$bnav_logo_vertical = get_theme_mod( 'zdz_logo_vertical', '' ); // v2.14.4 A5
	$bnav_logo_src      = '';
	$bnav_name          = get_bloginfo( 'name' );
	$bnav_monogram      = '';
	if ( class_exists( 'ZDZ_Business_Profile' ) ) {
		$bnav_logo_src = ZDZ_Business_Profile::logo( 'square', 'dark' )['url'];
		$bnav_name     = ZDZ_Business_Profile::name();
		$bnav_monogram = ZDZ_Business_Profile::initials();
	}
	if ( '' === $bnav_logo_src ) {
		$bnav_logo_src = $bnav_logo_light ?: $bnav_logo_dark;
	}
	?>
	<nav class="bnav" role="navigation" aria-label="<?php esc_attr_e( 'Main navigation', 'zorderz' ); ?>">
		<button class="ni active" data-view="sv-dash" aria-label="<?php esc_attr_e( 'Apps', 'zorderz' ); ?>">
			<i data-lucide="layout-grid"></i>
			<span class="ni-label"><?php esc_html_e( 'Apps', 'zorderz' ); ?></span>
		</button>
		<button class="bnav-logo" id="bnav-logo" aria-label="<?php esc_attr_e( 'Settings', 'zorderz' ); ?>">
			<?php if ( $bnav_logo_src ) : ?>
				<img id="bnav-logo-img"
					src="<?php echo esc_url( $bnav_logo_src ); ?>"
					alt="<?php echo esc_attr( $bnav_name ); ?>"
					data-logo-dark="<?php echo esc_attr( $bnav_logo_dark ); ?>"
					data-logo-light="<?php echo esc_attr( $bnav_logo_light ); ?>"
					data-logo-vertical="<?php echo esc_attr( $bnav_logo_vertical ); ?>">
			<?php elseif ( has_custom_logo() ) : ?>
				<?php
				$custom_logo_id  = get_theme_mod( 'custom_logo' );
				$custom_logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
				?>
				<img id="bnav-logo-img"
					src="<?php echo esc_url( $custom_logo_url ); ?>"
					alt="<?php echo esc_attr( $bnav_name ); ?>"
					data-logo-dark=""
					data-logo-light=""
					data-logo-vertical="<?php echo esc_attr( $bnav_logo_vertical ); ?>">
			<?php else : ?>
				<span class="bnav-logo-text"><?php echo esc_html( $bnav_monogram ); ?></span>
			<?php endif; ?>
		</button>
		<!-- Bug Report trigger — ladybug icon (v2.8.0), positioned via CSS order -->
		<button class="bnav-bug" id="bug-report-trigger" aria-label="<?php esc_attr_e( 'Report a bug', 'zorderz' ); ?>">
			<svg class="zdz-ladybug-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.5L7.5 2"/><path d="M14 5.5L16.5 2"/><circle cx="12" cy="6.5" r="2.5"/><ellipse cx="12" cy="15" rx="6" ry="7"/><line x1="12" y1="9" x2="12" y2="22"/><line x1="6" y1="14" x2="18" y2="14"/><circle cx="9.5" cy="11.5" r=".9" fill="currentColor" stroke="none"/><circle cx="14.5" cy="11.5" r=".9" fill="currentColor" stroke="none"/><circle cx="9" cy="17.5" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="17.5" r=".9" fill="currentColor" stroke="none"/></svg>
		</button>
		<!-- Chat nav item is injected dynamically by the Analytics plugin -->
	</nav>

</div>

<!-- ============================================================
     FULL-SCREEN APP VIEWPORT
     ============================================================ -->
<div class="app-viewport" id="app-viewport" aria-hidden="true">
	<header class="app-header" id="app-header">
		<button class="app-back" id="app-back" aria-label="<?php esc_attr_e( 'Back', 'zorderz' ); ?>">
			<i data-lucide="arrow-left" style="width:22px;height:22px"></i>
		</button>
		<div class="app-header-info">
			<div class="app-header-icon" id="app-header-icon"></div>
			<span class="app-header-title" id="app-header-title"></span>
		</div>
		<div class="app-header-actions" id="app-header-actions"></div>
	</header>
	<div class="app-body" id="app-body"></div>
</div>

<!-- v2.23.0: command-palette overlay removed — the ask field is now inline in
     #dash-top (#dash-ask) and its results drop down beneath it. -->

<!-- ============================================================
     BUG REPORT OVERLAY (v2.0)
     ============================================================ -->
<div id="zdz-bug-overlay" class="zdz-bug-overlay" aria-hidden="true">
	<div class="zdz-bug-modal" role="dialog" aria-modal="true" aria-labelledby="zdz-bug-title">
		<!-- Header -->
		<div class="zdz-bug-header">
			<div class="zdz-bug-title-row">
				<div class="zdz-bug-title-icon">
					<svg class="zdz-ladybug-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.5L7.5 2"/><path d="M14 5.5L16.5 2"/><circle cx="12" cy="6.5" r="2.5"/><ellipse cx="12" cy="15" rx="6" ry="7"/><line x1="12" y1="9" x2="12" y2="22"/><line x1="6" y1="14" x2="18" y2="14"/><circle cx="9.5" cy="11.5" r=".9" fill="currentColor" stroke="none"/><circle cx="14.5" cy="11.5" r=".9" fill="currentColor" stroke="none"/><circle cx="9" cy="17.5" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="17.5" r=".9" fill="currentColor" stroke="none"/></svg>
				</div>
				<h3 id="zdz-bug-title"><?php esc_html_e( 'Report an Issue', 'zorderz' ); ?></h3>
			</div>
			<button class="btn-icon zdz-bug-close-btn" id="zdz-bug-close" aria-label="<?php esc_attr_e( 'Close', 'zorderz' ); ?>">
				<i data-lucide="x" style="width:20px;height:20px"></i>
			</button>
		</div>

		<!-- Body -->
		<div class="zdz-bug-body">
			<!-- Context badge (auto-populated by JS) -->
			<div class="zdz-bug-context-row" id="zdz-bug-context-row">
				<i data-lucide="monitor" style="width:14px;height:14px;flex-shrink:0"></i>
				<span class="zdz-bug-context-label"><?php esc_html_e( 'Currently on:', 'zorderz' ); ?></span>
				<span class="zdz-bug-context-badge" id="zdz-bug-context">Dashboard</span>
			</div>

			<!-- Category quick-select -->
			<div class="zdz-bug-cats">
				<button class="zdz-bug-cat-btn active" data-cat="bug" type="button">
					<i data-lucide="alert-circle" style="width:14px;height:14px"></i>
					<?php esc_html_e( 'Bug', 'zorderz' ); ?>
				</button>
				<button class="zdz-bug-cat-btn" data-cat="feature" type="button">
					<i data-lucide="lightbulb" style="width:14px;height:14px"></i>
					<?php esc_html_e( 'Suggestion', 'zorderz' ); ?>
				</button>
				<button class="zdz-bug-cat-btn" data-cat="other" type="button">
					<i data-lucide="message-circle" style="width:14px;height:14px"></i>
					<?php esc_html_e( 'Other', 'zorderz' ); ?>
				</button>
			</div>

			<!-- Description textarea -->
			<div class="zdz-bug-fg">
				<textarea id="zdz-bug-description"
					rows="4"
					placeholder="<?php esc_attr_e( 'Describe what happened and what you expected...', 'zorderz' ); ?>"
					aria-label="<?php esc_attr_e( 'Issue description', 'zorderz' ); ?>"></textarea>
			</div>

			<!-- Auto-capture notice -->
			<div class="zdz-bug-auto-notice">
				<i data-lucide="shield-check" style="width:12px;height:12px;flex-shrink:0"></i>
				<span><?php esc_html_e( 'Device info and screen context are captured automatically.', 'zorderz' ); ?></span>
			</div>
		</div>

		<!-- Footer -->
		<div class="zdz-bug-footer">
			<button class="btn btn-outline btn-sm" id="zdz-bug-cancel" type="button">
				<?php esc_html_e( 'Cancel', 'zorderz' ); ?>
			</button>
			<button class="btn btn-brand btn-sm" id="zdz-bug-submit" type="button">
				<i data-lucide="send" style="width:16px;height:16px"></i>
				<?php esc_html_e( 'Submit', 'zorderz' ); ?>
			</button>
		</div>
	</div>
</div>

<!-- v2.14.4 D1: Add-to-Home-Screen prompt (hidden in standalone mode) -->
<div id="zdz-install-banner" class="zdz-install-banner" style="display:none;" aria-label="<?php esc_attr_e( 'Install app', 'zorderz' ); ?>">
	<span class="zdz-install-text"><?php esc_html_e( 'For the best experience, add to your home screen', 'zorderz' ); ?></span>
	<button id="zdz-install-how" class="zdz-install-how-btn" type="button"><?php esc_html_e( 'How', 'zorderz' ); ?></button>
	<button id="zdz-install-dismiss" class="zdz-install-dismiss-btn" type="button" aria-label="<?php esc_attr_e( 'Dismiss', 'zorderz' ); ?>">
		<i data-lucide="x" style="width:16px;height:16px"></i>
	</button>
</div>

<!-- v2.18.0: iOS Add-to-Home-Screen step-by-step guide overlay
     Positioned at TOP of screen so it doesn't cover the Safari toolbar at the bottom.
     Steps match iOS 26 Compact Safari layout (⋯ → Share → Add to Home Screen → Add).
     v2.20.0: JS detects iPad vs iPhone and shows the correct steps. -->
<div id="zdz-ios-install-guide" class="zdz-ios-guide" style="display:none;" role="dialog" aria-label="Add to Home Screen Guide">
	<div class="zdz-ios-guide-backdrop"></div>
	<div class="zdz-ios-guide-card">
		<button class="zdz-ios-guide-close" id="zdz-ios-guide-close" aria-label="Close">✕</button>
		<h3 class="zdz-ios-guide-title">Install Zorderz</h3>
		<!-- iPhone steps (default) -->
		<div id="zdz-guide-iphone">
			<p class="zdz-ios-guide-sub">Get the full-screen app in 4 taps:</p>
			<div class="zdz-ios-guide-steps">
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">1</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
						</span>
						<span>Tap <strong>⋯</strong> at the bottom right of your screen ↘</span>
					</div>
				</div>
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">2</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>
						</span>
						<span>Tap <strong>Share</strong> (the first option)</span>
					</div>
				</div>
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">3</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
						</span>
						<span>Tap <strong>View More</strong>, scroll down, tap <strong>Add to Home Screen</strong></span>
					</div>
				</div>
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">4</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
						</span>
						<span>Make sure <strong>Open as Web App</strong> is on, then tap <strong>Add</strong></span>
					</div>
				</div>
			</div>
		</div>
		<!-- iPad steps (v2.20.0) -->
		<div id="zdz-guide-ipad" style="display:none;">
			<p class="zdz-ios-guide-sub">Get the full-screen app in 3 taps:</p>
			<div class="zdz-ios-guide-steps">
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">1</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>
						</span>
						<span>Tap the <strong>Share</strong> button (□↑) in the toolbar at the top</span>
					</div>
				</div>
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">2</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
						</span>
						<span>Scroll down and tap <strong>Add to Home Screen</strong></span>
					</div>
				</div>
				<div class="zdz-ios-guide-step">
					<span class="zdz-ios-guide-num">3</span>
					<div class="zdz-ios-guide-content">
						<span class="zdz-ios-guide-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
						</span>
						<span>Make sure <strong>Open as Web App</strong> is on, then tap <strong>Add</strong></span>
					</div>
				</div>
			</div>
		</div>
		<div class="zdz-ios-guide-arrow" id="zdz-ios-guide-arrow">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5 0 14"/><path d="m19 12-7 7-7-7"/></svg>
		</div>
	</div>
</div>
<script>
// v2.20.0: Detect iPad and show correct install steps
(function(){
	var isIPad = /iPad/.test(navigator.userAgent) ||
		(navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	if (isIPad) {
		var phone = document.getElementById('zdz-guide-iphone');
		var pad   = document.getElementById('zdz-guide-ipad');
		if (phone) phone.style.display = 'none';
		if (pad) pad.style.display = '';
		// Hide the bottom-right arrow on iPad (Share is at the top)
		var arrow = document.getElementById('zdz-ios-guide-arrow');
		if (arrow) arrow.style.display = 'none';
	}
})();
</script>

<!-- v2.18.0: Non-Safari iOS browser guide (Chrome, Firefox, Edge, etc.) -->
<div id="zdz-nonsafari-guide" class="zdz-ios-guide" style="display:none;" role="dialog" aria-label="Open in Safari Guide">
	<div class="zdz-ios-guide-backdrop" id="zdz-nonsafari-backdrop"></div>
	<div class="zdz-ios-guide-card">
		<button class="zdz-ios-guide-close" id="zdz-nonsafari-close" aria-label="Close">✕</button>
		<h3 class="zdz-ios-guide-title">Open in Safari to install</h3>
		<p class="zdz-ios-guide-sub">On iPhone, only Safari can install Zorderz as a full-screen app. It takes 30 seconds:</p>
		<div class="zdz-ios-guide-steps">
			<div class="zdz-ios-guide-step">
				<span class="zdz-ios-guide-num">1</span>
				<div class="zdz-ios-guide-content">
					<span class="zdz-ios-guide-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
					</span>
					<span>Tap <strong>Copy Link</strong> below</span>
				</div>
			</div>
			<div class="zdz-ios-guide-step">
				<span class="zdz-ios-guide-num">2</span>
				<div class="zdz-ios-guide-content">
					<span class="zdz-ios-guide-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2 6.3-6.4 2.1 2-6.3z"/></svg>
					</span>
					<span>Open <strong>Safari</strong> and paste the link</span>
				</div>
			</div>
			<div class="zdz-ios-guide-step">
				<span class="zdz-ios-guide-num">3</span>
				<div class="zdz-ios-guide-content">
					<span class="zdz-ios-guide-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
					</span>
					<span>Then follow the steps to <strong>Add to Home Screen</strong></span>
				</div>
			</div>
		</div>
		<button type="button" id="zdz-nonsafari-copy" style="display:block;width:100%;margin-top:16px;padding:14px;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;background:var(--sys-brand,#2C5F8A);color:#fff;">Copy Link</button>
	</div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toast-container" aria-live="polite"></div>

<?php get_footer(); ?>
