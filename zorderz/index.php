<?php
/**
 * Zorderz Theme - Fallback Template
 *
 * The SPA lives in front-page.php. A logged-in user should never see
 * index.php — if they land here (post-OAuth bounce, stale bookmark,
 * WordPress Reading Settings edge case), serve front-page.php directly
 * instead of showing a dead end.
 *
 * @package Zorderz
 * @version 2.14.3.1
 */

if ( is_user_logged_in() && file_exists( get_theme_file_path( 'front-page.php' ) ) ) {
	include get_theme_file_path( 'front-page.php' );
	exit;
}

// Not logged in — the template_redirect auth check in functions.php
// normally handles this, but as a safety net show a minimal message.
get_header();
?>
<div style="padding: 2rem; text-align: center;">
    <h1><?php esc_html_e( 'Zorderz', 'zorderz' ); ?></h1>
    <p><?php esc_html_e( 'Please log in to continue.', 'zorderz' ); ?></p>
</div>
<?php
get_footer();
