<?php
/**
 * ZIM_Widget
 *
 * Renders the inline dashboard widget skeleton + full-page view.
 *
 * TRAP 5 — CUSTOMER-FACING HARD-BLOCK:
 *   should_render() returns false whenever the analytics app's customer-facing mode is
 *   active for the current user. The check runs in PHP at render-time — not
 *   just in JS — so the widget HTML is never generated and never reaches
 *   the browser. Full-page route returns a generic "not available" template
 *   that looks identical to the dashboard's empty state (NOT a 403; that
 *   would leak the plugin's existence).
 *
 *   Result is cached per-session (transient keyed on user id + session
 *   nonce) to avoid hammering the transient on every AJAX poll. Cache
 *   is busted when mode toggles.
 *
 * ASSET LOADING:
 *   widget.css + widget.js + marked.js + DOMPurify (reuse the theme's
 *   marked + purify loadout from TSA v1.11.3 — same CDN pins for cache
 *   alignment).
 *
 * PUBLIC-KEY LOCALIZATION:
 *   We include the VAPID public key in zimData so the frontend can call
 *   pushManager.subscribe( { applicationServerKey } ) immediately on opt-in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Widget {

	/**
	 * Decide whether this widget should render for the current user.
	 * Hard-blocks under customer-facing mode (Trap 5 / acceptance #4).
	 *
	 * Returns true only when ALL of the following hold:
	 *  - there is a logged-in user with zdz_access_app
	 *  - customer-facing mode is NOT active for that user
	 *
	 * Cached per request.
	 */
	public static function should_render() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! zim_user_has_access() ) {
			$cached = false;
			return false;
		}

		// Check TSA customer-facing flag. Several possible code paths depending
		// on which TSA version is active:
		//   v1.11.4+  — TSA_Customer_Facing::is_active_for_user( $user_id )
		//               (the coordinated patch shipped alongside this plugin)
		//   v1.11.0+  — transient 'tsa_customer_mode_{user_id}'
		//   TSA absent — render.
		if ( class_exists( 'TSA_Customer_Facing' )
		     && is_callable( array( 'TSA_Customer_Facing', 'is_active_for_user' ) ) ) {
			if ( TSA_Customer_Facing::is_active_for_user( $user_id ) ) {
				$cached = false;
				return false;
			}
		} elseif ( class_exists( 'TSA_Analytics_Engine' ) ) {
			// Fall back to the transient TSA v1.11.3 writes.
			$active = (bool) get_transient( 'tsa_customer_mode_' . $user_id );
			if ( $active ) {
				$cached = false;
				return false;
			}
		}

		$cached = true;
		return true;
	}

	/**
	 * Render the widget skeleton.
	 *
	 * @param int    $user_id
	 * @param string $context  'inline' or 'fullpage'
	 * @return string|null     HTML, or null when hard-blocked
	 */
	public function render_dashboard_widget( $user_id, $context = 'inline' ) {
		if ( ! self::should_render() ) {
			return null;
		}

		// ── Enqueue assets ──
		wp_enqueue_style(
			'zim-widget-css',
			ZIM_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			ZIM_VERSION
		);

		// marked.js + DOMPurify — pin same versions TSA v1.11.3 uses so the
		// browser can reuse the CDN cache across plugins.
		wp_enqueue_script(
			'marked-js',
			'https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js',
			array(), '12.0.2', true
		);
		wp_enqueue_script(
			'dompurify',
			'https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js',
			array(), '3.1.6', true
		);
		wp_enqueue_script(
			'zim-widget-js',
			ZIM_PLUGIN_URL . 'assets/js/widget.js',
			array( 'marked-js', 'dompurify' ),
			ZIM_VERSION,
			true
		);

		$user = get_userdata( $user_id );
		$is_site_admin = current_user_can( 'manage_options' )
		                 || in_array( 'zdz_admin', (array) ( $user->roles ?? array() ), true )
		                 || in_array( 'zdz_owner', (array) ( $user->roles ?? array() ), true );

		$quiet = ZIM_Notifications::get_quiet_hours( $user_id );

		// v1.0.24 — Read-only kiosk (`zdz_general`). When true, the composer is
		// not rendered into the DOM at all, the New DM affordance is hidden, and
		// the JS suppresses every send/DM action. The server blocks are the
		// guarantee; this is the matching UX so the shared account never even
		// sees a way to type or send.
		$is_read_only = function_exists( 'zim_user_is_read_only' )
			? (bool) zim_user_is_read_only( $user_id )
			: false;

		wp_localize_script( 'zim-widget-js', 'zimData', array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( ZIM_NONCE ),
			'userId'            => (int) $user_id,
			'userName'          => $user ? $user->display_name : '',
			'userLogin'         => $user ? $user->user_login   : '',
			'isAdmin'           => (bool) $is_site_admin,
			'isReadOnly'        => $is_read_only,
			'readOnlyNotice'    => __( 'This is a shared device with read-only messaging. You can read announcements, but cannot send messages.', 'zdz-internal-messaging' ),
			'version'           => ZIM_VERSION,
			'swUrl'             => ZIM_PLUGIN_URL . 'assets/js/sw.js',
			'vapidPublicKey'    => ZIM_Push::get_public_key(),
			'quietHours'        => $quiet,
			// v1.1.1 — poll cadence raised 3s → 10s to cut the volume of
			// uncacheable admin-ajax hits (a top contributor to WP Engine PHP
			// worker saturation / 502s under load; the origin cache hit ratio was
			// ~12%). The client also backs off exponentially on failed polls, so a
			// struggling origin is given room to recover instead of being hammered.
			// Filterable so the cadence can be tuned without a redeploy.
			'pollIntervalMs'    => (int) apply_filters( 'zim_poll_interval_ms', 10000 ),
			'maxUploadBytes'    => ZIM_MAX_UPLOAD_BYTES,
			'allowedMimes'      => array_values( zim_allowed_mimes() ),
			'editWindowSec'     => ZIM_EDIT_WINDOW_SECONDS,
			'context'           => (string) $context,
			'tsaPreviewAvailable' => class_exists( 'TSA_Analytics_Engine' ),
			'deepLinkConvoId'   => isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0,
			// v1.0.7 — embed mode. When loaded inside the analytics app's iframe (?zdz_embed=tsa),
			// the widget runs in "conversation-focused" mode: sidebar hidden by default,
			// no redundant header chrome, signals parent frame with unreads.
			'embedMode'         => isset( $_GET['zdz_embed'] ) ? sanitize_key( $_GET['zdz_embed'] ) : '',
		) );

		ob_start();
		$embed_class = isset( $_GET['zdz_embed'] ) ? ' zim-w--embed' : '';
		?>
		<div class="zim-w zim-w--<?php echo esc_attr( $context ); ?><?php echo esc_attr( $embed_class ); ?>" id="zim-widget" data-user-id="<?php echo (int) $user_id; ?>">
			<aside class="zim-w-sidebar" id="zim-w-sidebar" aria-label="Conversations">
				<header class="zim-w-sidebar-hdr">
					<span class="zim-w-logo"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 1.9-5.7a8.5 8.5 0 1 1 3.8 3.8z"/></svg> Messages</span>
					<?php if ( $is_site_admin ) : ?>
						<button type="button" class="zim-w-icon-btn" id="zim-w-new-channel" title="New channel" aria-label="New channel"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
					<?php endif; ?>
					<button type="button" class="zim-w-icon-btn" id="zim-w-settings-btn" title="Settings" aria-label="Settings"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></button>
				</header>

				<section class="zim-w-section" aria-label="Channels">
					<div class="zim-w-section-hdr">Channels</div>
					<ul class="zim-w-list" id="zim-w-channels"></ul>
				</section>

				<section class="zim-w-section" aria-label="Direct Messages">
					<div class="zim-w-section-hdr">
						<span>Direct Messages</span>
						<?php if ( ! $is_read_only ) : ?>
						<button type="button" class="zim-w-icon-btn zim-w-icon-btn--sm" id="zim-w-new-dm" title="New DM" aria-label="New DM"><svg class="zim-w-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></button>
						<?php endif; ?>
					</div>
					<ul class="zim-w-list" id="zim-w-dms"></ul>
				</section>
			</aside>

			<main class="zim-w-main" id="zim-w-main">
				<header class="zim-w-main-hdr">
					<button type="button" class="zim-w-back-btn" id="zim-w-back-btn" aria-label="Back to conversation list"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg> <span>Messages</span></button>
					<button type="button" class="zim-w-icon-btn zim-w-mobile-only" id="zim-w-sidebar-toggle" aria-label="Toggle sidebar"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/></svg></button>
					<h3 class="zim-w-main-title" id="zim-w-main-title">Select a conversation</h3>
					<div class="zim-w-main-actions">
						<button type="button" class="zim-w-icon-btn" id="zim-w-search-btn" title="Search" aria-label="Search"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button>
						<button type="button" class="zim-w-icon-btn" id="zim-w-push-btn" title="Enable notifications" aria-label="Enable notifications"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></button>
					</div>
				</header>

				<div class="zim-w-search-bar" id="zim-w-search-bar" hidden style="display:none">
					<input type="search" id="zim-w-search-input" placeholder="Search this conversation…" autocomplete="off">
				</div>

				<div class="zim-w-messages" id="zim-w-messages" role="log" aria-live="polite">
					<div class="zim-w-empty">Choose a channel or DM to start messaging.</div>
				</div>

				<?php if ( $is_read_only ) : ?>
				<footer class="zim-w-composer zim-w-composer--readonly" id="zim-w-readonly-note" hidden style="display:none">
					<div class="zim-w-readonly-msg">
						<svg class="zim-w-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						<span><?php echo esc_html__( 'Read-only on this shared device. You can read announcements, but can’t send messages.', 'zdz-internal-messaging' ); ?></span>
					</div>
				</footer>
				<?php else : ?>
				<footer class="zim-w-composer" id="zim-w-composer" hidden style="display:none">
					<div class="zim-w-attach-chips" id="zim-w-attach-chips"></div>
					<div class="zim-w-composer-row">
						<button type="button" class="zim-w-icon-btn" id="zim-w-attach-btn" title="Attach" aria-label="Attach"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
						<input type="file" id="zim-w-file-input" hidden accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,application/pdf">
						<textarea
							id="zim-w-input"
							rows="1"
							placeholder="Type a message… use @ to mention, #NNNNN for estimates"
							inputmode="text"
							enterkeyhint="send"></textarea>
						<button type="button" class="zim-w-send-btn" id="zim-w-send-btn" aria-label="Send" disabled><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
					</div>
					<div class="zim-w-mention-pop" id="zim-w-mention-pop" hidden style="display:none"></div>
				</footer>
				<?php endif; ?>
			</main>

			<!-- Settings overlay -->
			<div class="zim-w-overlay" id="zim-w-settings" hidden style="display:none">
				<div class="zim-w-overlay-card" role="dialog" aria-label="Settings">
					<header class="zim-w-overlay-hdr">
						<h4>Settings</h4>
						<button type="button" class="zim-w-icon-btn" id="zim-w-settings-close" aria-label="Close"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
					</header>
					<div class="zim-w-overlay-body">
						<label class="zim-w-field">
							<span>Quiet hours start</span>
							<input type="time" id="zim-w-quiet-start" value="<?php echo esc_attr( $quiet['start'] ); ?>">
						</label>
						<label class="zim-w-field">
							<span>Quiet hours end</span>
							<input type="time" id="zim-w-quiet-end" value="<?php echo esc_attr( $quiet['end'] ); ?>">
						</label>
						<p class="zim-w-help">During quiet hours, pushes defer to a single digest at the end of the window.</p>
						<button type="button" class="zim-w-primary-btn" id="zim-w-quiet-save">Save</button>
					</div>
				</div>
			</div>

			<!-- New channel overlay (admins only) -->
			<?php if ( $is_site_admin ) : ?>
			<div class="zim-w-overlay" id="zim-w-channel-modal" hidden style="display:none">
				<div class="zim-w-overlay-card" role="dialog" aria-label="New channel">
					<header class="zim-w-overlay-hdr">
						<h4>New channel</h4>
						<button type="button" class="zim-w-icon-btn" id="zim-w-channel-close" aria-label="Close"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
					</header>
					<div class="zim-w-overlay-body">
						<label class="zim-w-field">
							<span>Slug</span>
							<input type="text" id="zim-w-channel-slug" placeholder="q4-planning" autocomplete="off">
						</label>
						<label class="zim-w-field">
							<span>Description</span>
							<input type="text" id="zim-w-channel-desc" placeholder="Q4 planning">
						</label>
						<label class="zim-w-field zim-w-field--row">
							<input type="checkbox" id="zim-w-channel-private">
							<span>Private (invite-only)</span>
						</label>
						<button type="button" class="zim-w-primary-btn" id="zim-w-channel-create">Create</button>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- New DM overlay -->
			<div class="zim-w-overlay" id="zim-w-dm-modal" hidden style="display:none">
				<div class="zim-w-overlay-card" role="dialog" aria-label="New DM">
					<header class="zim-w-overlay-hdr">
						<h4>New direct message</h4>
						<button type="button" class="zim-w-icon-btn" id="zim-w-dm-close" aria-label="Close"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
					</header>
					<div class="zim-w-overlay-body">
						<input type="text" id="zim-w-dm-search" placeholder="Search people…" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
						<ul class="zim-w-list zim-w-list--picker" id="zim-w-dm-candidates"></ul>
					</div>
				</div>
			</div>

			<!-- FreshBooks preview side-panel -->
			<div class="zim-w-overlay" id="zim-w-preview-panel" hidden style="display:none">
				<div class="zim-w-overlay-card zim-w-overlay-card--side" role="dialog" aria-label="Preview">
					<header class="zim-w-overlay-hdr">
						<h4 id="zim-w-preview-title">Preview</h4>
						<button type="button" class="zim-w-icon-btn" id="zim-w-preview-close" aria-label="Close"><svg class="zim-w-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
					</header>
					<div class="zim-w-overlay-body" id="zim-w-preview-body"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
