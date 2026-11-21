<?php
/**
 * Plugin Name: Zorderz Privacy View gate (must-use)
 * Description: Runs the Zorderz theme's Privacy View gate before any plugin loads, for a request whose sign-in cookie names an account in Privacy View. Does nothing for anyone else.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 *
 * This file ships inside the Zorderz theme (mu-plugin/), where WordPress never loads it. It works only from
 * wp-content/mu-plugins/. An administrator puts it there, updates it and removes it from Zorderz -> Privacy View, or
 * copies this one file there by hand. Removing it (or renaming it so that it no longer ends in .php) turns it off; the
 * theme's own gate keeps working without it.
 *
 * Why a must-use plugin: the theme's gate runs when the theme loads (after_setup_theme). A regular plugin's file, and
 * its plugins_loaded and setup_theme handlers, run before that, so a theme cannot be in front of them. WordPress loads
 * must-use plugins first of all, in file-name order; the "0-" puts this one before the others.
 *
 * What it does, in order:
 *  1. Reads WordPress's own three sign-in cookies (the names WordPress itself reads them under) for one of WordPress's
 *     shape ("login|expiry|token|check") that has not expired and whose login is an account with Privacy View on (one
 *     small query, only when such a cookie is there). WordPress cannot yet check the cookie's signature (its sign-in
 *     functions are loaded after the plugins); that is done at plugins_loaded. A cookie under any other name is not
 *     read: one planted under another name cannot hold a team member to the account's rules.
 *  2. If one does, loads the theme's ZDZ_Privacy_View (the one place the lists live) and hands the request to its
 *     early_gate(), which refuses anything the account may not do and judges it again, in full, as soon as WordPress
 *     says who is signed in (plugins_loaded, first of all).
 *  3. If the active theme has no early_gate() (an older Zorderz, or another theme), it fails closed: such a request is
 *     refused, except wp-login.php, the sign-in routes and the sign-in page, so that browser can still sign out or in.
 *
 * Nothing here reads, stores or logs a cookie's value. Nothing changes for anyone whose cookies name no account in
 * Privacy View: no hook that acts, no output.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'ZDZ_PV_GATE_VERSION' ) ) {
	return;   // loaded already
}
define( 'ZDZ_PV_GATE_VERSION', '1.0.0' );

/** The names WordPress reads its sign-in cookies under: wp-config.php's, else as WordPress makes them from the site address. */
function zdz_pv_gate_cookie_names() {
	if ( defined( 'COOKIEHASH' ) ) {
		$hash = (string) COOKIEHASH;
	} else {
		$site = function_exists( 'get_site_option' ) ? get_site_option( 'siteurl' ) : ( function_exists( 'get_option' ) ? get_option( 'siteurl' ) : '' );
		$hash = $site ? md5( (string) $site ) : '';
	}
	return array_values( array_unique( array(
		defined( 'LOGGED_IN_COOKIE' ) ? (string) LOGGED_IN_COOKIE : 'wordpress_logged_in_' . $hash,
		defined( 'AUTH_COOKIE' ) ? (string) AUTH_COOKIE : 'wordpress_' . $hash,
		defined( 'SECURE_AUTH_COOKIE' ) ? (string) SECURE_AUTH_COOKIE : 'wordpress_sec_' . $hash,
	) ) );
}

/**
 * The login of an account in Privacy View that a sign-in cookie on this request names, or '' (none, or the database
 * could not say). Only a cookie of WordPress's own shape counts: four pieces split by "|".
 */
function zdz_pv_gate_login( array $names = array() ) {
	if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
		return '';
	}
	$named = array();
	foreach ( $names ? $names : zdz_pv_gate_cookie_names() as $name ) {
		$value = $_COOKIE[ $name ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its shape, its expiry time and the login it names are read; the login is compared with the database's own, never used in a query
		if ( ! is_string( $value ) || 3 !== substr_count( $value, '|' ) ) {
			continue;
		}
		$piece = explode( '|', $value );
		if ( (int) $piece[1] + 3600 < time() ) {
			continue;   // expired beyond the hour WordPress still allows a form: it signs nobody in
		}
		$login = strtolower( trim( (string) $piece[0] ) );
		if ( '' !== $login ) {
			$named[ $login ] = true;
		}
	}
	if ( ! $named ) {
		return '';
	}
	global $wpdb;
	if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return '';
	}
	$on = (array) $wpdb->get_col( $wpdb->prepare( "SELECT u.user_login FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value = %s", 'zdz_privacy_view', '1' ) );
	foreach ( $on as $login ) {
		if ( isset( $named[ strtolower( trim( (string) $login ) ) ] ) ) {
			return (string) $login;
		}
	}
	return '';
}

/** The theme's ZDZ_Privacy_View, loaded from the theme WordPress will run (the same file the theme loads later). */
function zdz_pv_gate_theme_class() {
	if ( class_exists( 'ZDZ_Privacy_View', false ) ) {
		return true;
	}
	$tpl = function_exists( 'get_option' ) ? (string) get_option( 'template' ) : '';
	if ( ! preg_match( '/\A[A-Za-z0-9_.\-]+\z/', $tpl ) || false !== strpos( $tpl, '..' ) ) {
		return false;
	}
	$root = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $tpl ) : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/themes' : '' );
	$file = rtrim( $root, '/\\' ) . '/' . $tpl . '/inc/class-zdz-privacy-view.php';
	if ( '' === $root || ! is_readable( $file ) ) {
		return false;
	}
	require_once $file;
	return class_exists( 'ZDZ_Privacy_View', false );
}

/** The site's own path ("/" unless WordPress lives in a folder), with a slash at each end. */
function zdz_pv_gate_home_path() {
	$home = function_exists( 'get_option' ) ? (string) get_option( 'home' ) : '';
	$path = '' !== $home ? (string) parse_url( $home, PHP_URL_PATH ) : '';
	return '/' . ltrim( trim( $path, '/' ) . '/', '/' );
}

/**
 * Fail closed: a request naming the account, and no theme gate to judge it. Everything is refused but signing out or
 * in: wp-login.php, the five sign-in routes, and GET of the home page and the sign-in page with their own keys. (A
 * browser can keep the account's cookie after its session ends, and must still be able to sign in.)
 */
function zdz_pv_gate_refuse_all() {
	$self = (string) ( $_SERVER['PHP_SELF'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against a fixed pattern only
	$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against fixed patterns only
	$path = (string) substr( $uri, 0, strcspn( $uri, '?#' ) );
	$get  = in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with two fixed words
	$keys = array_map( 'strval', array_keys( (array) $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names only, compared with fixed lists; nothing is acted on
	$home = zdz_pv_gate_home_path();
	$page = ( $path === $home && ! array_diff( $keys, array( 'cb' ) ) )
		|| ( in_array( $path, array( $home . 'login', $home . 'login/' ), true ) && ! array_diff( $keys, array( 'magic-login-bridge', 'request_id', 'bridge_token', 'bridge_code', 'zdz_next', 'redirect_to' ) ) );
	$ns   = trim( defined( 'ZDZ_REST_NS' ) ? (string) ZDZ_REST_NS : 'zorderz/v1', '/' );
	$rest = trim( function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json', '/' );
	if ( preg_match( '#/wp-login\.php\z#i', $self )
		|| preg_match( '#\A' . preg_quote( $home, '#' ) . '(?:index\.php/)?' . preg_quote( $rest, '#' ) . '/' . preg_quote( $ns, '#' ) . '/magic-link-(?:init|status|claim|code-claim|send-code)/?\z#', $path )
		|| ( $get && $page ) ) {
		return;
	}
	error_log( 'Zorderz Privacy View: refused (the must-use gate found no early gate in the active theme)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one fixed line for the site's log
	if ( ! headers_sent() ) {
		http_response_code( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
	}
	echo 'This is not available in Privacy View: the active theme cannot check this request. Activate a Zorderz theme that has the early gate, or remove wp-content/mu-plugins/0-zdz-privacy-view-gate.php.';
	exit;
}

/** The account signs in only with its browser cookie: no application passwords (the theme says so too, but only once it loads). */
add_filter( 'wp_is_application_passwords_available_for_user', static function ( $available, $user = null ) {
	if ( is_object( $user ) && isset( $user->ID ) && function_exists( 'get_user_meta' ) && '1' === (string) get_user_meta( (int) $user->ID, 'zdz_privacy_view', true ) ) {
		return false;
	}
	return $available;
}, 99, 2 );

/**
 * The cookie names above are worked out before WordPress settles its own (after the must-use plugins). Should
 * something change the site address in between, they would differ and the account's cookie would be missed. So at
 * plugins_loaded, first of all, the names WordPress settled on are compared; only if they differ is the request looked
 * at again, with them, and judged there.
 */
function zdz_pv_gate_late() {
	$real = array();
	foreach ( array( 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ) as $c ) {
		if ( defined( $c ) ) {
			$real[] = (string) constant( $c );
		}
	}
	$first = isset( $GLOBALS['zdz_pv_gate_names'] ) ? (array) $GLOBALS['zdz_pv_gate_names'] : array();
	if ( ! $real || ! array_diff( $real, $first ) ) {
		return;   // the same names: the first look was the right one
	}
	$login = zdz_pv_gate_login( $real );
	if ( '' === $login ) {
		return;
	}
	if ( zdz_pv_gate_theme_class() && method_exists( 'ZDZ_Privacy_View', 'early_gate' ) ) {
		ZDZ_Privacy_View::early_gate( $login );
		ZDZ_Privacy_View::early_confirm();
		return;
	}
	zdz_pv_gate_refuse_all();
}

( static function () {
	$GLOBALS['zdz_pv_gate_names'] = zdz_pv_gate_cookie_names();   // the names the first look used (compared at plugins_loaded)
	$login                        = zdz_pv_gate_login( $GLOBALS['zdz_pv_gate_names'] );
	if ( '' === $login ) {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'plugins_loaded', 'zdz_pv_gate_late', PHP_INT_MIN );
		}
		return;
	}
	if ( zdz_pv_gate_theme_class() && method_exists( 'ZDZ_Privacy_View', 'early_gate' ) ) {
		ZDZ_Privacy_View::early_gate( $login );
		return;
	}
	zdz_pv_gate_refuse_all();
} )();
