<?php
/**
 * Template Name: Zorderz Login
 *
 * Handles three modes:
 *   1. Bridge interstitial (Safari → tells user to open PWA)
 *   2. Normal login with PWA polling (generates request_id, polls for bridge token)
 *   3. Normal login without polling (non-PWA context, desktop, etc.)
 *
 * @package Zorderz
 * @since   2.18.0
 * @updated 2.19.0 — Short-code fallback for cold-start PWA logins
 */

// ── Bridge interstitial mode ────────────────────────────────────────────
// This fires when Safari redirects here after Magic Login validates the token.
// The user is already authenticated in Safari. We show a confirmation with
// a login code for the PWA (v2.19.0), and optionally write a bridge token
// to CacheStorage as a supplemental mechanism.
$is_bridge = ! empty( $_GET['magic-login-bridge'] ) && $_GET['magic-login-bridge'] === '1';

if ( $is_bridge ) {
	$bridge_token = isset( $_GET['bridge_token'] ) ? sanitize_text_field( $_GET['bridge_token'] ) : '';
	$request_id   = isset( $_GET['request_id'] ) ? sanitize_text_field( $_GET['request_id'] ) : '';
	$bridge_code  = isset( $_GET['bridge_code'] ) ? sanitize_text_field( $_GET['bridge_code'] ) : '';
	$is_cold_start = empty( $request_id );

	// The login/bridge card is white, so it wants the DARK-ink wide lockup. The
	// Business Profile is authoritative; the theme mods below are the pre-profile
	// way of setting a logo and stay as a fallback so an existing install does
	// not lose its artwork the day it upgrades.
	$login_logo_url = '';
	if ( class_exists( 'ZDZ_Business_Profile' ) ) {
		$login_logo_url = ZDZ_Business_Profile::logo( 'wide', 'light' )['url'];
	}
	if ( '' === $login_logo_url ) {
		$logo_light     = get_theme_mod( 'zdz_logo_light', '' );
		$logo_dark      = get_theme_mod( 'zdz_logo_dark', '' );
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		$login_logo_url = $logo_dark
			?: ( $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'full' ) : ( $logo_light ?: '' ) );
	}

	// Minimal header — no full get_header() to avoid skeleton/SPA shell overhead.
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="theme-color" content="#2563eb">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Login Successful — Zorderz', 'zorderz' ); ?></title>
	<style>
		*{box-sizing:border-box;margin:0;padding:0}
		body{
			font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
			background:linear-gradient(145deg,#2563eb 0%,#1e3a8a 50%,#172554 100%);
			min-height:100vh;display:flex;align-items:center;justify-content:center;
			padding:24px 16px;color:#111827;
		}
		.bridge-card{
			background:#fff;border-radius:20px;padding:40px 28px 32px;
			width:100%;max-width:360px;box-shadow:0 25px 60px rgba(0,0,0,.35);
			text-align:center;
		}
		.bridge-logo{margin-bottom:24px}
		.bridge-logo img{display:block;margin:0 auto;max-width:180px;max-height:60px;
			width:auto;height:auto;object-fit:contain}
		.bridge-icon{
			width:64px;height:64px;margin:0 auto 20px;
			background:#ECFDF5;border-radius:50%;display:flex;
			align-items:center;justify-content:center;
		}
		.bridge-icon svg{color:#059669;width:32px;height:32px}
		.bridge-title{font-size:20px;font-weight:700;margin-bottom:8px;color:#111827}
		.bridge-sub{font-size:15px;color:#6b7280;line-height:1.5;margin-bottom:24px}
		.bridge-home-hint{
			display:inline-flex;align-items:center;gap:8px;
			background:#F0F9FF;border:1px solid #BAE6FD;border-radius:12px;
			padding:14px 20px;font-size:14px;font-weight:500;color:#0369A1;
		}
		.bridge-home-hint svg{width:20px;height:20px;flex-shrink:0}
		.bridge-note{font-size:12px;color:#9ca3af;margin-top:20px;line-height:1.4}
		.bridge-version{font-size:11px;color:#9ca3af;margin-top:16px}
		.bridge-code-wrap{margin:20px 0 4px;text-align:center}
		.bridge-code-label{font-size:13px;font-weight:500;color:#6b7280;margin-bottom:10px;text-transform:uppercase;letter-spacing:0.5px}
		.bridge-code{
			display:inline-block;font-size:36px;font-weight:800;letter-spacing:8px;
			color:#111827;background:#F9FAFB;border:2px solid #E5E7EB;
			border-radius:14px;padding:14px 24px;font-family:'SF Mono',ui-monospace,monospace;
			-webkit-user-select:all;user-select:all;
		}
		.bridge-code-expire{font-size:12px;color:#9ca3af;margin-top:8px}
		.bridge-or{font-size:12px;color:#d1d5db;margin:16px 0;text-transform:uppercase;letter-spacing:1px}
		/* v2.20.0: Copy button */
		.bridge-copy-btn{
			display:inline-flex;align-items:center;gap:6px;
			margin-top:10px;padding:10px 20px;
			background:#EEF2FF;border:1px solid #C7D2FE;border-radius:10px;
			font-size:14px;font-weight:600;color:#4338CA;
			cursor:pointer;font-family:inherit;
			transition:background 150ms;
		}
		.bridge-copy-btn:active{background:#C7D2FE}
		.bridge-copy-btn svg{width:16px;height:16px}
		/* v2.20.0: Step-by-step instructions */
		.bridge-steps{
			text-align:left;margin:20px 0 8px;
		}
		.bridge-step{
			display:flex;align-items:flex-start;gap:12px;
			margin-bottom:14px;font-size:14px;color:#374151;line-height:1.5;
		}
		.bridge-step-num{
			flex:0 0 28px;height:28px;border-radius:50%;
			background:#EEF2FF;color:#4338CA;font-weight:700;font-size:13px;
			display:flex;align-items:center;justify-content:center;
		}
		/* v2.20.0: Swipe animation */
		.bridge-swipe-hint{
			display:flex;align-items:center;justify-content:center;
			margin:16px 0 8px;opacity:0.7;
		}
		.bridge-swipe-hand{
			animation:bridge-swipe 2s ease-in-out infinite;
			font-size:28px;
		}
		@keyframes bridge-swipe{
			0%,100%{transform:translateY(0)}
			50%{transform:translateY(-12px)}
		}
		@media(max-width:420px){
			body{padding:16px 12px}
			.bridge-card{padding:32px 20px 24px;border-radius:16px}
		}
	</style>
</head>
<body>
<div class="bridge-card">
	<div class="bridge-logo">
		<?php if ( $login_logo_url ) : ?>
			<img src="<?php echo esc_url( $login_logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<?php else : ?>
			<strong style="font-size:22px;">Zorderz</strong>
		<?php endif; ?>
	</div>

	<div class="bridge-icon">
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
			<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
		</svg>
	</div>

	<div class="bridge-title">You're logged in!</div>

	<?php if ( $bridge_code ) : ?>
		<?php
		$code_display = substr( $bridge_code, 0, 3 ) . ' ' . substr( $bridge_code, 3 );
		?>

		<div class="bridge-code-wrap">
			<div class="bridge-code-label">Your login code</div>
			<div class="bridge-code" id="bridge-code-value"><?php echo esc_html( $code_display ); ?></div>
			<div class="bridge-code-expire">Expires in 5 minutes</div>
			<button type="button" class="bridge-copy-btn" id="bridge-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js( $bridge_code ); ?>').then(function(){document.getElementById('bridge-copy-btn').innerHTML='<svg viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2.5&quot;><path d=&quot;M20 6L9 17l-5-5&quot;/></svg> Copied!';})">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
				Copy Code
			</button>
		</div>

		<div class="bridge-steps">
			<div class="bridge-step">
				<span class="bridge-step-num">1</span>
				<span><strong>Swipe up</strong> from the bottom of your screen to go to your Home Screen</span>
			</div>
			<div class="bridge-step">
				<span class="bridge-step-num">2</span>
				<span>Tap the <strong>Zorderz</strong> app icon</span>
			</div>
			<div class="bridge-step">
				<span class="bridge-step-num">3</span>
				<span>Tap <strong>"Have a login code?"</strong> and enter the code above</span>
			</div>
		</div>

		<div class="bridge-swipe-hint">
			<span class="bridge-swipe-hand">👆</span>
		</div>

	<?php else : ?>
		<div class="bridge-sub">Open Zorderz from your Home Screen to continue. Your app will log in automatically.</div>

		<div class="bridge-home-hint">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
				<path stroke-linecap="round" stroke-linejoin="round" d="m3 12 9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1"/>
			</svg>
			Tap your Home Screen icon
		</div>
	<?php endif; ?>

	<p class="bridge-note">You can close this Safari tab after switching.</p>

	<div class="bridge-version">
		<?php echo esc_html( 'Zorderz v' . ( wp_get_theme()->get( 'Version' ) ?: ( defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '' ) ) ); ?>
	</div>
</div>

<?php if ( $bridge_token ) : ?>
<script>
/**
 * Write the bridge token to CacheStorage as a best-effort supplemental mechanism.
 * Note: On modern iOS (16+), CacheStorage is isolated between Safari and the
 * standalone PWA, so this may not be picked up. The primary mechanisms are:
 *   - REST API polling (warm start, v2.18.0)
 *   - Short login code (cold start, v2.19.0)
 * Kept for backward compatibility with any iOS versions that DO share CacheStorage.
 */
(function(){
	try {
		if ('caches' in window) {
			caches.open('zdz-bridge-v1').then(function(cache) {
				var body = JSON.stringify({
					bridge_token: <?php echo wp_json_encode( $bridge_token ); ?>,
					request_id: <?php echo wp_json_encode( $request_id ); ?>,
					timestamp: Date.now()
				});
				var response = new Response(body, {
					headers: { 'Content-Type': 'application/json' }
				});
				cache.put('/_ts-bridge-token', response);
			}).catch(function(){});
		}
	} catch(e) {}
})();
</script>
<?php endif; ?>

</body>
</html>
	<?php
	exit; // Stop WordPress from rendering anything else
}

// ── Normal login mode ───────────────────────────────────────────────────
// Redirect if already logged in.
if ( is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// Same resolution as the bridge card above: white surface, so dark ink.
// Business Profile first, pre-profile theme mods as the fallback.
$login_logo_url = '';
if ( class_exists( 'ZDZ_Business_Profile' ) ) {
	$login_logo_url = ZDZ_Business_Profile::logo( 'wide', 'light' )['url'];
}
if ( '' === $login_logo_url ) {
	$logo_light     = get_theme_mod( 'zdz_logo_light', '' );
	$logo_dark      = get_theme_mod( 'zdz_logo_dark', '' );
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	$login_logo_url = $logo_dark
		?: ( $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'full' ) : ( $logo_light ?: '' ) );
}

// Build the redirect_to URL with bridge request_id placeholder.
// The JS will replace __REQUEST_ID__ with the actual UUID before the form submits.
$bridge_redirect = add_query_arg( 'zdz_bridge_request_id', '__TS_REQUEST_ID__', home_url( '/' ) );

// We include the header so WP enqueues styles/scripts, then overlay everything.
get_header(); ?>

<style>
	/* Full-screen takeover — hardcoded dark-blue gradient, never uses CSS vars */
	.zdz-login-takeover {
		position: fixed;
		inset: 0;
		z-index: 999999;
		background: linear-gradient(145deg, #2563eb 0%, #1e3a8a 50%, #172554 100%);
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 24px 16px;
		font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
		font-size: 16px;
		line-height: 1.5;
		color: #111827;
		/* Prevent iOS overscroll bounce */
		overflow: auto;
		-webkit-overflow-scrolling: touch;
	}

	/* ---- Card ---- */
	.login-card {
		background: #ffffff;
		border-radius: 24px;
		padding: 44px 32px 36px;
		width: 100%;
		max-width: 440px;
		box-shadow: 0 25px 60px rgba(0,0,0,0.35);
		text-align: center;
		overflow: hidden;
	}
	/* Guarantee containment of every child element */
	.login-card *,
	.login-card *::before,
	.login-card *::after {
		box-sizing: border-box !important;
		max-width: 100% !important;
	}

	/* ---- Logo ---- */
	.login-logo {
		margin-bottom: 28px;
	}
	.login-logo img {
		display: block;
		margin: 0 auto;
		max-width: 180px;
		max-height: 60px;
		width: auto;
		height: auto;
		object-fit: contain;
	}
	/* SVG fallback icon */
	.login-logo .logo-mark {
		width: 52px;
		height: 52px;
		color: #2563eb;
		margin: 0 auto 10px;
	}
	.login-logo h1 {
		font-size: 22px;
		font-weight: 700;
		margin: 8px 0 2px;
		color: #111827;
	}
	.login-logo p {
		font-size: 13px;
		color: #6b7280;
		margin: 0;
	}

	/* ---- Magic Login overrides — containment & contrast ---- */
	.magic-login-section {
		text-align: left;
		width: 100%;
		overflow: hidden;
	}
	/* Force ALL text inside Magic Login plugin to high-contrast dark */
	.magic-login-section,
	.magic-login-section p,
	.magic-login-section span,
	.magic-login-section div,
	.magic-login-section label,
	.magic-login-section .description,
	.magic-login-section .magic-login-form p,
	.magic-login-section .magic-login-form span,
	.magic-login-section .magic-login-form label,
	.magic-login-section .magic-login-form .description {
		color: #374151 !important;
		font-size: 16px !important;
		line-height: 1.5 !important;
	}
	.magic-login-section label {
		display: block !important;
		font-size: 17px !important;
		font-weight: 600 !important;
		margin-bottom: 8px !important;
		color: #111827 !important;
	}
	.magic-login-section a {
		color: #2563eb !important;
		text-decoration: underline !important;
		font-size: 14px !important;
	}
	/* Inputs */
	.magic-login-section input[type="email"],
	.magic-login-section input[type="text"],
	.magic-login-section .magic-login-form input[type="email"],
	.magic-login-section .magic-login-form input[type="text"] {
		display: block !important;
		width: 100% !important;
		padding: 16px 18px !important;
		border: 2px solid #d1d5db !important;
		border-radius: 14px !important;
		font-size: 18px !important;
		min-height: 58px !important;
		box-sizing: border-box !important;
		margin: 0 0 14px 0 !important;
		background: #f9fafb !important;
		color: #111827 !important;
	}
	.magic-login-section input[type="email"]:focus,
	.magic-login-section input[type="text"]:focus,
	.magic-login-section .magic-login-form input[type="email"]:focus,
	.magic-login-section .magic-login-form input[type="text"]:focus {
		border-color: #2563eb !important;
		outline: none !important;
		background: #ffffff !important;
		box-shadow: 0 0 0 3px rgba(37,99,235,0.15) !important;
	}
	/* Submit button */
	.magic-login-section input[type="submit"],
	.magic-login-section button[type="submit"],
	.magic-login-section button,
	.magic-login-section .magic-login-form input[type="submit"],
	.magic-login-section .magic-login-form button {
		display: block !important;
		width: 100% !important;
		padding: 18px !important;
		border: none !important;
		border-radius: 14px !important;
		font-size: 18px !important;
		font-weight: 700 !important;
		min-height: 58px !important;
		cursor: pointer !important;
		text-align: center !important;
		background: #2563eb !important;
		color: #ffffff !important;
		margin: 4px 0 0 0 !important;
		box-sizing: border-box !important;
	}
	.magic-login-section input[type="submit"]:hover,
	.magic-login-section button[type="submit"]:hover,
	.magic-login-section .magic-login-form input[type="submit"]:hover,
	.magic-login-section .magic-login-form button:hover {
		background: #1d4ed8 !important;
	}
	/* Plugin form wrapper containment */
	.magic-login-section form,
	.magic-login-section .magic-login-form {
		width: 100% !important;
		max-width: 100% !important;
		margin: 0 !important;
		padding: 0 !important;
		overflow: hidden !important;
	}
	/* Kill any plugin-injected left/right borders (blue stripe bug) */
	.magic-login-section *,
	.magic-login-section .magic-login-form * {
		border-left-style: none !important;
		border-right-style: none !important;
	}
	/* Re-apply borders only on inputs and buttons */
	.magic-login-section input[type="email"],
	.magic-login-section input[type="text"],
	.magic-login-section .magic-login-form input[type="email"],
	.magic-login-section .magic-login-form input[type="text"] {
		border-style: solid !important;
	}

	/* ---- PWA bridge: "Check your email" polling state ---- */
	.zdz-bridge-polling {
		display: none;
		text-align: center;
		padding: 8px 0;
	}
	.zdz-bridge-polling.active {
		display: block;
	}
	.zdz-bridge-polling .polling-icon {
		width: 48px; height: 48px; margin: 0 auto 16px;
		color: #2563eb;
	}
	.zdz-bridge-polling h3 {
		font-size: 18px; font-weight: 600; color: #111827; margin: 0 0 8px;
	}
	.zdz-bridge-polling p {
		font-size: 14px !important; color: #6b7280 !important; margin: 0 0 4px !important;
		line-height: 1.5 !important;
	}
	.zdz-bridge-spinner {
		display: inline-block; width: 20px; height: 20px; margin-top: 12px;
		border: 2.5px solid #E5E7EB; border-top-color: #2563eb;
		border-radius: 50%; animation: zdz-spin 0.8s linear infinite;
	}
	@keyframes zdz-spin { to { transform: rotate(360deg); } }
	.zdz-bridge-status {
		font-size: 12px !important; color: #9ca3af !important; margin-top: 8px !important;
	}

	/* ---- Footer ---- */
	.login-version {
		margin-top: 20px;
		font-size: 11px;
		color: #9ca3af;
	}
	.login-fallback {
		color: #6b7280;
		font-size: 14px;
		margin: 0;
	}

	/* ---- Mobile polish ---- */
	@media (max-width: 420px) {
		.zdz-login-takeover {
			padding: 16px 12px;
		}
		.login-card {
			padding: 28px 20px 24px;
			border-radius: 16px;
		}
	}

	/* ---- v2.19.0: Login code entry ---- */
	.zdz-code-toggle {
		display: block;
		width: 100%;
		text-align: center;
		margin-top: 16px;
		font-size: 13px;
		color: #6b7280;
		cursor: pointer;
		background: none;
		border: none;
		padding: 8px;
		font-family: inherit;
	}
	.zdz-code-toggle:hover {
		color: #2563eb;
		text-decoration: underline;
	}
	/* v2.21.0: demoted "Already have a code?" affordance — quiet, secondary,
	   sits beneath the email→code hero. Hidden once the code block is shown. */
	.zdz-have-code-link {
		display: block;
		width: 100%;
		text-align: center;
		margin-top: 14px;
		font-size: 14px;
		color: #6b7280;
		cursor: pointer;
		background: none;
		border: none;
		padding: 8px;
		font-family: inherit;
	}
	.zdz-have-code-link:hover {
		color: #2563eb;
		text-decoration: underline;
	}
	.zdz-have-code-block.active {
		display: block !important;
	}
	.zdz-code-entry {
		display: none;
		text-align: center;
		padding: 4px 0;
	}
	.zdz-code-entry.active {
		display: block;
	}
	.zdz-code-entry-label {
		font-size: 17px;
		font-weight: 600;
		color: #374151;
		margin-bottom: 14px;
	}
	.zdz-code-input {
		display: block;
		width: 100%;
		padding: 18px;
		border: 2px solid #d1d5db;
		border-radius: 14px;
		font-size: 32px;
		font-weight: 800;
		letter-spacing: 8px;
		text-align: center;
		min-height: 64px;
		box-sizing: border-box;
		margin: 0 0 14px;
		background: #f9fafb;
		color: #111827;
		font-family: 'SF Mono', ui-monospace, monospace;
	}
	.zdz-code-input:focus {
		border-color: #2563eb;
		outline: none;
		background: #fff;
		box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
	}
	.zdz-code-submit {
		display: block;
		width: 100%;
		padding: 18px;
		border: none;
		border-radius: 14px;
		font-size: 18px;
		font-weight: 700;
		min-height: 58px;
		cursor: pointer;
		text-align: center;
		background: #6366F1;
		color: #fff;
		font-family: inherit;
	}
	.zdz-code-submit:hover { background: #1d4ed8; }
	.zdz-code-submit:disabled { background: #93c5fd; cursor: not-allowed; }
	.zdz-code-error {
		font-size: 13px;
		color: #dc2626;
		margin-top: 8px;
		display: none;
	}
	.zdz-code-back {
		display: inline-block;
		margin-top: 12px;
		font-size: 13px;
		color: #6b7280;
		cursor: pointer;
		background: none;
		border: none;
		padding: 4px 8px;
		font-family: inherit;
	}
	.zdz-code-back:hover { color: #2563eb; text-decoration: underline; }
</style>

<div class="zdz-login-takeover">
	<div class="login-card">
		<div class="login-logo">
			<?php if ( $login_logo_url ) : ?>
				<img src="<?php echo esc_url( $login_logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php else : ?>
				<!-- Shield Check Icon (fallback when no logo uploaded) -->
				<svg class="logo-mark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
					<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
				</svg>
				<h1><?php esc_html_e( 'Zorderz', 'zorderz' ); ?></h1>
				<p><?php echo esc_html( class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::get( 'identity.tagline', get_bloginfo( 'description' ) ) : get_bloginfo( 'description' ) ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Email/link login form — demoted to a secondary option (hidden by
		     default). The email is now code-only, so code entry is primary; this
		     remains available via "Use email login instead" for anyone who wants
		     the link form. The shortcode still renders so the OTP "Send me a code"
		     path has its email source and Magic Login stays wired. -->
		<div id="zdz-login-form-wrap" style="display:none;">
		<?php if ( function_exists( 'magic_login' ) || shortcode_exists( 'magic_login_form' ) ) : ?>
			<div class="magic-login-section">
				<?php echo do_shortcode( '[magic_login_form redirect_to="' . esc_url( $bridge_redirect ) . '"]' ); ?>
			</div>
		<?php else : ?>
			<p class="login-fallback"><?php esc_html_e( 'Login is currently unavailable. Please contact your administrator.', 'zorderz' ); ?></p>
		<?php endif; ?>
		</div>

		<!-- Toggle kept in the DOM for the JS handlers but hidden — code entry is
		     now the default view, so there's nothing to toggle TO on load. -->
		<button type="button" class="zdz-code-toggle" id="zdz-code-toggle" style="display:none;">Have a login code? Or send one instead →</button>

		<!-- PRIMARY view: code login. Shown by default (`active`). Ordered the way
		     a user actually does it: request a code (email) first, then enter it.
		     v2.21.0 login streamline: ONE input on screen at a time. The email →
		     "Send me a code" request is the always-visible hero; the 6-digit code
		     field is collapsed behind a demoted "Already have a code?" link so the
		     two inputs no longer compete. (Auth plumbing unchanged — same IDs,
		     same handlers, same endpoints.) -->
		<div class="zdz-code-entry active" id="zdz-code-entry">

			<!-- Step 1 — request a code: enter email → code-only email is sent.
			     This is the hero and is always visible on load. -->
			<div id="zdz-otp-section" style="text-align:center;">
				<div class="zdz-code-entry-label">Enter your email to get a login code</div>
				<input type="email" class="zdz-otp-email" id="zdz-otp-email" placeholder="Enter your email"
					autocomplete="email" autocapitalize="none" inputmode="email"
					style="display:block;width:100%;padding:16px 18px;border:2px solid #d1d5db;border-radius:14px;font-size:18px;min-height:58px;box-sizing:border-box;margin:0 0 10px;background:#f9fafb;color:#111827;">
				<button type="button" class="zdz-otp-send" id="zdz-otp-send"
					style="display:block;width:100%;padding:18px;border:none;border-radius:14px;font-size:18px;font-weight:700;min-height:58px;cursor:pointer;text-align:center;background:#6366F1;color:#fff;font-family:inherit;">
					Send me a code
				</button>
				<div class="zdz-otp-error" id="zdz-otp-error" style="font-size:14px;color:#dc2626;margin-top:8px;display:none;"></div>
			</div>

			<!-- Demoted secondary affordance: reveals the code field below. Hidden
			     once the code block is showing (and after a code is sent, since the
			     send handler reveals the block directly). -->
			<button type="button" class="zdz-have-code-link" id="zdz-have-code-link">Already have a code?</button>

			<!-- Step 2 — enter the 6-digit code. Collapsed by default so only ONE
			     input shows on load; revealed by the link above or by sending a code. -->
			<div class="zdz-have-code-block" id="zdz-have-code-block" style="display:none;margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb;">
				<div class="zdz-code-entry-label" id="zdz-have-code-label" style="font-size:18px;font-weight:600;margin-bottom:12px;">Enter your 6-digit code</div>
				<input type="text" class="zdz-code-input" id="zdz-code-input"
					inputmode="numeric" pattern="[0-9]*" maxlength="7"
					placeholder="000 000" autocomplete="one-time-code">
				<button type="button" class="zdz-code-submit" id="zdz-code-submit" disabled>Log In</button>
				<div class="zdz-code-error" id="zdz-code-error"></div>
			</div>

			<!-- Secondary escape hatch: reveal the email/link login form. -->
			<button type="button" class="zdz-code-back" id="zdz-code-back">Use email login instead</button>
		</div>

		<!-- Polling state (shown only after the email/link form is used in PWA) -->
		<div id="zdz-bridge-polling" class="zdz-bridge-polling">
			<div class="polling-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
					<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
				</svg>
			</div>
			<h3>Check your email</h3>
			<p>We sent a 6-digit login code to your email.<br>Enter it above and you'll be logged in here.</p>
			<div class="zdz-bridge-spinner"></div>
			<div class="zdz-bridge-status" id="zdz-bridge-status">Waiting for your code…</div>
		</div>

		<div class="login-version">
			<?php echo esc_html( 'Zorderz v' . ( wp_get_theme()->get( 'Version' ) ?: ( defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '' ) ) ); ?>
		</div>
	</div>
</div>

<script>
/**
 * v2.18.0: PWA Magic Login Bridge — Client-side polling.
 *
 * When running in standalone PWA mode on iOS, this script:
 * 1. Generates a request_id (UUID) and injects it into the form's redirect_to
 * 2. Calls /zorderz/v1/magic-link-init with the request_id + email
 * 3. After form submission, shows a polling UI
 * 4. Polls /zorderz/v1/magic-link-status every 2.5s for the bridge token
 * 5. On token received, calls /zorderz/v1/magic-link-claim to set auth cookie
 * 6. Reloads the page → user is now authenticated in the PWA
 *
 * Also checks CacheStorage on load for a bridge token written by Safari.
 */
(function() {
	'use strict';

	var isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
		|| (window.navigator && window.navigator.standalone === true);

	var apiBase = <?php echo wp_json_encode( esc_url_raw( rest_url( ZDZ_REST_NS . '/' ) ) ); ?>;

	// ── v2.20.3: Unified login flow ──
	// The OTP code is now injected into the Magic Login email via wp_mail filter.
	// After the magic link form submits, we auto-transition to the code entry
	// view since the user will receive ONE email with both the link and the code.
	// The "Send me a code" button is kept as a fallback for direct code requests.
	function initOTPMode() {
		var otpSection = document.getElementById('zdz-otp-section');
		var codeToggle = document.getElementById('zdz-code-toggle');
		var otpEmail   = document.getElementById('zdz-otp-email');
		var otpSend    = document.getElementById('zdz-otp-send');
		var otpError   = document.getElementById('zdz-otp-error');

		if (!otpSend) return;

		// Pre-fill the OTP email from the main magic login form
		function syncEmail() {
			var mainInput = document.querySelector('.magic-login-section input[type="email"], .magic-login-section input[name="log"]');
			if (mainInput && mainInput.value && otpEmail) {
				otpEmail.value = mainInput.value;
			}
		}

		// v2.20.3: After the magic link form submits, auto-show code entry
		// since the email now contains both the magic link AND the OTP code
		var magicForms = document.querySelectorAll('.magic-login-section form, .magic-login-form');
		magicForms.forEach(function(form) {
			form.addEventListener('submit', function() {
				syncEmail();
				setTimeout(function() {
					var entry     = document.getElementById('zdz-code-entry');
					var formWrap  = document.getElementById('zdz-login-form-wrap');
					var toggle    = document.getElementById('zdz-code-toggle');
					var codeInput = document.getElementById('zdz-code-input');
					var haveBlock = document.getElementById('zdz-have-code-block');
					var haveLink  = document.getElementById('zdz-have-code-link');
					var haveLabel = document.getElementById('zdz-have-code-label');

					if (entry && formWrap) {
						formWrap.style.display = 'none';
						if (toggle) toggle.style.display = 'none';
						entry.classList.add('active');
						// A code is on the way — hide the request form and reveal the
						// code field directly so the user just enters what they receive.
						if (otpSection) otpSection.style.display = 'none';
						if (haveLink) haveLink.style.display = 'none';
						if (haveBlock) { haveBlock.style.display = 'block'; haveBlock.classList.add('active'); }
						if (haveLabel) haveLabel.textContent = 'Check your email — enter the 6-digit code';
						if (codeInput) codeInput.focus();
					}
				}, 1200);
			});
		});

		// Sync email when toggle is clicked too
		if (codeToggle) {
			codeToggle.addEventListener('click', syncEmail);
		}

		// OTP send button (fallback for direct code requests)
		otpSend.addEventListener('click', function() {
			var email = otpEmail ? otpEmail.value.trim() : '';
			if (!email) {
				if (otpEmail) otpEmail.focus();
				return;
			}

			otpSend.disabled = true;
			otpSend.textContent = 'Sending code…';
			if (otpError) otpError.style.display = 'none';

			fetch(apiBase + 'magic-link-send-code', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ email: email })
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data && data.success) {
					otpSection.style.display = 'none';
					var entry = document.getElementById('zdz-code-entry');
					if (entry) entry.classList.add('active');
					// Code is on the way: hide the demoted link and reveal the code
					// field, and tell the user prominently to check their email.
					var haveLink  = document.getElementById('zdz-have-code-link');
					var haveBlock = document.getElementById('zdz-have-code-block');
					var haveLabel = document.getElementById('zdz-have-code-label');
					if (haveLink) haveLink.style.display = 'none';
					if (haveBlock) { haveBlock.style.display = 'block'; haveBlock.classList.add('active'); }
					if (haveLabel) { haveLabel.textContent = 'Check your email — enter the 6-digit code'; }
					var codeInput = document.getElementById('zdz-code-input');
					if (codeInput) codeInput.focus();
				} else {
					var msg = (data && data.message) ? data.message : 'Could not send code. Please try again.';
					if (otpError) { otpError.textContent = msg; otpError.style.display = 'block'; }
					otpSend.textContent = 'Send me a code';
					otpSend.disabled = false;
				}
			})
			.catch(function() {
				if (otpError) { otpError.textContent = 'Connection error. Please try again.'; otpError.style.display = 'block'; }
				otpSend.textContent = 'Send me a code';
				otpSend.disabled = false;
			});
		});

		if (otpEmail) {
			otpEmail.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') { e.preventDefault(); otpSend.click(); }
			});
		}
	}

	// ── CacheStorage bridge check (runs on every load) ──────────────
	// If Safari wrote a bridge token to CacheStorage, try to claim it.
	function checkCacheStorageBridge() {
		if (!('caches' in window)) return;
		try {
			caches.open('zdz-bridge-v1').then(function(cache) {
				return cache.match('/_ts-bridge-token');
			}).then(function(response) {
				if (!response) return;
				return response.json();
			}).then(function(data) {
				if (!data || !data.bridge_token) return;
				// Clean up the cache entry
				caches.open('zdz-bridge-v1').then(function(cache) {
					cache.delete('/_ts-bridge-token');
				});
				// Attempt to claim
				claimBridgeToken(data.bridge_token);
			}).catch(function() {});
		} catch(e) {}
	}

	// Run CacheStorage check immediately
	checkCacheStorageBridge();

	// ── UUID v4 generator ──────────────────────────────────────────
	function uuid4() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	// ── Claim a bridge token ───────────────────────────────────────
	function claimBridgeToken(token) {
		fetch(apiBase + 'magic-link-claim', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ bridge_token: token })
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data && data.success) {
				var statusEl = document.getElementById('zdz-bridge-status');
				if (statusEl) statusEl.textContent = 'Logging you in…';
				setTimeout(function() {
					window.location.href = data.redirect || '/';
				}, 300);
			}
		})
		.catch(function() {});
	}

	// ── v2.19.0: Login code entry (works in both standalone and browser) ──
	function initCodeEntry() {
		var toggle    = document.getElementById('zdz-code-toggle');
		var entry     = document.getElementById('zdz-code-entry');
		var formWrap  = document.getElementById('zdz-login-form-wrap');
		var codeInput = document.getElementById('zdz-code-input');
		var submitBtn = document.getElementById('zdz-code-submit');
		var errorEl   = document.getElementById('zdz-code-error');
		var backBtn   = document.getElementById('zdz-code-back');
		var haveLink  = document.getElementById('zdz-have-code-link');
		var haveBlock = document.getElementById('zdz-have-code-block');

		if (!toggle || !entry || !codeInput || !submitBtn) return;

		// v2.21.0: demoted "Already have a code?" — reveal the collapsed code
		// field so only one input is ever visible at a time on load.
		if (haveLink && haveBlock) {
			haveLink.addEventListener('click', function() {
				haveBlock.style.display = 'block';
				haveBlock.classList.add('active');
				haveLink.style.display = 'none';
				codeInput.focus();
			});
		}

		// Toggle: show code entry, hide email form
		toggle.addEventListener('click', function() {
			formWrap.style.display = 'none';
			toggle.style.display = 'none';
			entry.classList.add('active');
			codeInput.focus();
		});

		// "Use email login instead": reveal the demoted email/link form and hide
		// code entry. (Code entry is the default; this is the secondary path.)
		if (backBtn) {
			backBtn.addEventListener('click', function() {
				entry.classList.remove('active');
				if (formWrap) formWrap.style.display = '';
				// Offer the way back to code entry.
				if (toggle) toggle.style.display = '';
				errorEl.style.display = 'none';
				codeInput.value = '';
				submitBtn.disabled = true;
				// Re-collapse the code field and restore the demoted link so the
				// next return to this view starts clean (one input again).
				if (haveBlock) { haveBlock.style.display = 'none'; haveBlock.classList.remove('active'); }
				if (haveLink) haveLink.style.display = '';
			});
		}

		// Format code input: auto-insert space after 3 digits, strip non-digits
		codeInput.addEventListener('input', function() {
			var raw = this.value.replace(/\D/g, '').substring(0, 6);
			if (raw.length > 3) {
				this.value = raw.substring(0, 3) + ' ' + raw.substring(3);
			} else {
				this.value = raw;
			}
			submitBtn.disabled = raw.length !== 6;
			errorEl.style.display = 'none';
		});

		// Paste handler: clean up pasted codes
		codeInput.addEventListener('paste', function(e) {
			e.preventDefault();
			var pasted = (e.clipboardData || window.clipboardData).getData('text');
			var raw = pasted.replace(/\D/g, '').substring(0, 6);
			if (raw.length > 3) {
				codeInput.value = raw.substring(0, 3) + ' ' + raw.substring(3);
			} else {
				codeInput.value = raw;
			}
			submitBtn.disabled = raw.length !== 6;
		});

		// Submit: call the code-claim endpoint
		submitBtn.addEventListener('click', function() {
			var raw = codeInput.value.replace(/\D/g, '');
			if (raw.length !== 6) return;

			submitBtn.disabled = true;
			submitBtn.textContent = 'Verifying…';
			errorEl.style.display = 'none';

			fetch(apiBase + 'magic-link-code-claim', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ code: raw })
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data && data.success) {
					submitBtn.textContent = 'Success!';
					setTimeout(function() {
						window.location.href = data.redirect || '/';
					}, 300);
				} else {
					var msg = (data && data.message) ? data.message : 'Invalid or expired code. Try again.';
					errorEl.textContent = msg;
					errorEl.style.display = 'block';
					submitBtn.textContent = 'Log In';
					submitBtn.disabled = false;
					codeInput.value = '';
					codeInput.focus();
				}
			})
			.catch(function() {
				errorEl.textContent = 'Something went wrong. Please try again.';
				errorEl.style.display = 'block';
				submitBtn.textContent = 'Log In';
				submitBtn.disabled = false;
			});
		});

		// Enter key submits the code
		codeInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' && !submitBtn.disabled) {
				e.preventDefault();
				submitBtn.click();
			}
		});
	}

	// ── Suppress iOS/Safari password autofill on the Magic Login email field ──
	// The email field is rendered by the [magic_login_form] shortcode (a 3rd-party
	// plugin), so we can't set attributes on it in markup — we adjust the rendered
	// element here instead. WordPress login fields use name="log", the canonical
	// "username" signal that makes Safari aggressively offer saved-password / Strong
	// Password autofill. We can't rename it (the form submits on name="log"), but we
	// CAN de-signal it: set a benign autocomplete, mark it not-a-password, and add
	// the common password-manager ignore hints.
	//
	// NOTE: iOS Safari deliberately resists suppressing password autofill on
	// login-looking fields (Apple ignores autocomplete="off" there by design), so
	// this REDUCES the prompt but is not guaranteed to eliminate it on every iOS
	// build. It does NOT touch the code field (#zdz-code-input), which intentionally
	// keeps autocomplete="one-time-code" so the OTP can still be offered.
	function suppressMagicLoginAutofill() {
		var fields = document.querySelectorAll(
			'.magic-login-section input[type="email"], .magic-login-section input[name="log"], .magic-login-section input[type="text"]'
		);
		fields.forEach(function(el) {
			// Never alter the OTP code input.
			if (el.id === 'zdz-code-input') return;
			el.setAttribute('autocomplete', 'off');
			el.setAttribute('autocorrect', 'off');
			el.setAttribute('autocapitalize', 'none');
			el.setAttribute('spellcheck', 'false');
			// Explicit "this is not a credential field" hints for password managers.
			el.setAttribute('data-1p-ignore', '');   // 1Password
			el.setAttribute('data-lpignore', 'true'); // LastPass
			el.setAttribute('data-bwignore', '');     // Bitwarden
		});
	}

	// Init code entry on DOM ready (runs for all contexts)
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			suppressMagicLoginAutofill();
			initCodeEntry();
			initOTPMode();
		});
	} else {
		suppressMagicLoginAutofill();
		initCodeEntry();
		initOTPMode();
	}


	// ── Only set up polling if running as standalone PWA ───────────
	if (!isStandalone) return;

	var requestId = uuid4();
	var pollTimer = null;
	var pollCount = 0;
	var MAX_POLLS = 120; // 120 × 2.5s = 5 minutes

	// Inject request_id into the Magic Login form's redirect_to hidden field
	function injectRequestId() {
		var forms = document.querySelectorAll('.magic-login-section form, .magic-login-form');
		forms.forEach(function(form) {
			// Find existing redirect_to field or the form action
			var redirectInput = form.querySelector('input[name="redirect_to"]');
			if (redirectInput) {
				var val = redirectInput.value;
				val = val.replace('__TS_REQUEST_ID__', requestId);
				redirectInput.value = val;
			}
			// Also try the action URL
			if (form.action && form.action.indexOf('__TS_REQUEST_ID__') !== -1) {
				form.action = form.action.replace('__TS_REQUEST_ID__', requestId);
			}
		});
	}

	// Get the email from the form
	function getEmailFromForm() {
		var input = document.querySelector('.magic-login-section input[type="email"], .magic-login-section input[name="log"]');
		return input ? input.value.trim() : '';
	}

	// Start polling
	function startPolling() {
		var pollingDiv = document.getElementById('zdz-bridge-polling');
		var formWrap = document.getElementById('zdz-login-form-wrap');
		if (pollingDiv) pollingDiv.classList.add('active');
		if (formWrap) formWrap.style.display = 'none';

		function doPoll() {
			pollCount++;
			if (pollCount > MAX_POLLS) {
				clearInterval(pollTimer);
				var statusEl = document.getElementById('zdz-bridge-status');
				if (statusEl) statusEl.textContent = 'Link expired. Please try again.';
				if (pollingDiv) pollingDiv.classList.remove('active');
				if (formWrap) formWrap.style.display = '';
				return;
			}

			fetch(apiBase + 'magic-link-status?request_id=' + encodeURIComponent(requestId), {
				credentials: 'same-origin'
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data && data.status === 'ready' && data.bridge_token) {
					clearInterval(pollTimer);
					var statusEl = document.getElementById('zdz-bridge-status');
					if (statusEl) statusEl.textContent = 'Link verified! Logging you in…';
					claimBridgeToken(data.bridge_token);
				}
			})
			.catch(function() {});
		}

		pollTimer = setInterval(doPoll, 2500);

		// v2.20.0: Resume polling when the PWA becomes visible again.
		// iOS suspends JS timers when the app is backgrounded (user switches
		// to email to tap the link). When they come back, the intervals may
		// have been paused. This listener fires an immediate poll on return.
		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'visible' && pollCount <= MAX_POLLS && pollCount > 0) {
				doPoll(); // Immediate check on return
			}
		});
	}

	// Intercept Magic Login form submission
	function interceptFormSubmit() {
		var forms = document.querySelectorAll('.magic-login-section form, .magic-login-form');
		forms.forEach(function(form) {
			form.addEventListener('submit', function() {
				var email = getEmailFromForm();
				if (!email) return; // Let normal validation handle it

				// Call init endpoint
				fetch(apiBase + 'magic-link-init', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ request_id: requestId, email: email })
				}).catch(function() {});

				// Start polling after a brief delay (let the form submit complete)
				setTimeout(startPolling, 1500);
			});
		});
	}

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			injectRequestId();
			interceptFormSubmit();
		});
	} else {
		injectRequestId();
		interceptFormSubmit();
	}
})();
</script>

<?php get_footer(); ?>
