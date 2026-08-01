<?php
/**
 * The header for our theme
 *
 * @package Zorderz
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> data-theme="system">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
	<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<meta name="apple-mobile-web-app-title" content="Zorderz">
	<link rel="apple-touch-icon" href="<?php echo esc_url( get_template_directory_uri() . '/assets/images/icon-192.png' ); ?>">
	<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( get_template_directory_uri() . '/assets/images/apple-touch-icon-180.png' ); ?>">
	<link rel="manifest" href="<?php echo esc_url( home_url( '/zdz-manifest.json' ) ); ?>">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php
	/**
	 * FOUC prevention — apply the saved theme BEFORE first paint.
	 *
	 * Without this, the page renders with data-theme="system" (the HTML default)
	 * and then visibly snaps to the user's saved theme when app.js loads. The
	 * script reads localStorage synchronously in <head> so the correct theme is
	 * already on <html> before the browser lays out the body. Because this runs
	 * before any stylesheet, it's zero-cost and prevents the flash.
	 *
	 * This is the inline script referenced in the README Section 4.
	 *
	 * @since 2.14.3
	 */
	?>
	<script>
	(function(){try{var t=localStorage.getItem('zdz_theme');if(t&&/^(light|dark|sunlight|system)$/.test(t)){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
	/* v2.31.0: larger-text pref must also apply before first paint */
	(function(){try{if(localStorage.getItem('zdz_textscale')==='lg'){document.documentElement.setAttribute('data-zdz-textscale','lg');}}catch(e){}})();
	</script>

	<?php
	/**
	 * v2.15.0: Inline critical CSS for app shell & skeleton screen.
	 *
	 * This block renders the background color, bottom nav bar shape, and a
	 * pulsing skeleton placeholder BEFORE any external CSS or JS downloads.
	 * The user sees the familiar app frame within ~100ms of navigation start
	 * instead of staring at a blank screen for 3-15 seconds on slow cellular.
	 *
	 * When app.js finishes booting and adds .zdz-ready to <body>, the skeleton
	 * hides and the real content appears. The CSS animation fallback (3s) in
	 * app.css is retained for the edge case where JS fails entirely.
	 *
	 * These styles intentionally duplicate a small subset of app.css / style.css
	 * tokens — that's the point. They must work before those files download.
	 */
	?>
	<style>
	/* ---- Skeleton: shown before JS boots ---- */
	body{margin:0;background:#F8FAFC;overflow:hidden}
	@media(prefers-color-scheme:dark){html:not([data-theme="light"]):not([data-theme="sunlight"]) body{background:#020617}}
	html[data-theme="dark"] body{background:#020617}
	html[data-theme="light"] body{background:#F8FAFC}
	html[data-theme="sunlight"] body{background:#fff}
	.zdz-skeleton{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;
	  font-family:'Inter',system-ui,-apple-system,sans-serif}
	.zdz-skel-main{flex:1;padding:60px 16px 16px}
	.zdz-skel-bar{height:14px;border-radius:7px;margin-bottom:12px;
	  background:rgba(148,163,184,.15);animation:zdz-pulse 1.8s ease-in-out infinite}
	.zdz-skel-bar.w60{width:60%}.zdz-skel-bar.w80{width:80%}.zdz-skel-bar.w45{width:45%}
	.zdz-skel-card{height:80px;border-radius:12px;margin-bottom:12px;
	  background:rgba(148,163,184,.08);animation:zdz-pulse 1.8s ease-in-out .3s infinite}
	.zdz-skel-dock{display:flex;gap:12px;margin:20px 0 24px;justify-content:center}
	.zdz-skel-dot{width:48px;height:48px;border-radius:14px;
	  background:rgba(148,163,184,.1);animation:zdz-pulse 1.8s ease-in-out .15s infinite}
	.zdz-skel-nav{position:fixed;bottom:0;left:0;right:0;height:calc(49px + env(safe-area-inset-bottom,0px));
	  padding-bottom:env(safe-area-inset-bottom,0);display:flex;align-items:center;justify-content:center;
	  background:rgba(255,255,255,.82);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
	  border-top:1px solid rgba(226,232,240,1)}
	@media(prefers-color-scheme:dark){
	  html:not([data-theme="light"]):not([data-theme="sunlight"]) .zdz-skel-bar{background:rgba(148,163,184,.08)}
	  html:not([data-theme="light"]):not([data-theme="sunlight"]) .zdz-skel-card{background:rgba(148,163,184,.05)}
	  html:not([data-theme="light"]):not([data-theme="sunlight"]) .zdz-skel-dot{background:rgba(148,163,184,.06)}
	  html:not([data-theme="light"]):not([data-theme="sunlight"]) .zdz-skel-nav{
	    background:rgba(30,41,59,.82);border-top-color:rgba(51,65,85,1)}
	}
	html[data-theme="dark"] .zdz-skel-bar{background:rgba(148,163,184,.08)}
	html[data-theme="dark"] .zdz-skel-card{background:rgba(148,163,184,.05)}
	html[data-theme="dark"] .zdz-skel-dot{background:rgba(148,163,184,.06)}
	html[data-theme="dark"] .zdz-skel-nav{background:rgba(30,41,59,.82);border-top-color:rgba(51,65,85,1)}
	@keyframes zdz-pulse{0%,100%{opacity:1}50%{opacity:.4}}
	/* Hide skeleton once app boots */
	body.zdz-ready .zdz-skeleton{display:none}
	/* Hide main content until app boots (prevents FOUC) */
	body:not(.zdz-ready) #view-main,
	body:not(.zdz-ready) #app-viewport,
	body:not(.zdz-ready) #cmd-overlay,
	body:not(.zdz-ready) #zdz-bug-overlay,
	body:not(.zdz-ready) #zdz-install-banner,
	body:not(.zdz-ready) #toast-container{visibility:hidden;height:0;overflow:hidden}
	/* 4s failsafe: if JS never boots, show content anyway */
	@keyframes zdz-skel-hide{to{display:none}}
	@keyframes zdz-content-show{to{visibility:visible;height:auto;overflow:visible}}
	</style>

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<!-- v2.15.0: App shell skeleton — visible instantly, hidden by zdz-ready -->
<div class="zdz-skeleton" aria-hidden="true" role="presentation">
	<div class="zdz-skel-main">
		<div class="zdz-skel-bar w60"></div>
		<div class="zdz-skel-bar w45"></div>
		<div class="zdz-skel-dock">
			<div class="zdz-skel-dot"></div>
			<div class="zdz-skel-dot"></div>
			<div class="zdz-skel-dot"></div>
			<div class="zdz-skel-dot"></div>
		</div>
		<div class="zdz-skel-card"></div>
		<div class="zdz-skel-card"></div>
		<div class="zdz-skel-bar w80"></div>
		<div class="zdz-skel-bar w60"></div>
		<div class="zdz-skel-card"></div>
	</div>
	<div class="zdz-skel-nav"></div>
</div>