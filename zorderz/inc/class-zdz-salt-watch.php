<?php
/**
 * ZDZ_Salt_Watch -- the site notices if its secret keys (the WordPress salts) ever change.
 *
 * The salt lines in wp-config.php are the master key for much of what the apps keep: stored credentials,
 * share links, private documents, signed agreements, gate codes, and the mailbox and calendar sign-ins.
 * Changing them breaks each of those, quietly.
 *
 * On the first run a fingerprint of each salt is kept (option zdz_salt_fingerprints: per scheme the first 12 hex of
 * sha256( 'zdz-salt-fp-v1|' . scheme . '|' . wp_salt( scheme ) ), never a salt). On a mismatch: a notice on every admin
 * page for administrators and a "critical" Site Health item, each naming what breaks and what to do. Nothing here
 * offers to regenerate anything; the one button, "I've dealt with it: record the new fingerprints", only records the
 * fingerprints again.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Salt_Watch {

	const OPT     = 'zdz_salt_fingerprints';   // ['v' => 1, 'at' => 'Y-m-d H:i:s', 'by' => uid, 'fp' => [scheme => 12 hex]]
	const SCHEMES = array( 'auth', 'secure_auth', 'logged_in', 'nonce' );
	const ACTION  = 'zdz_salt_rerecord';

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'changed' ) );   // records on the first run
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_rerecord' ) );
	}

	/** Today's fingerprints: [scheme => 12 hex]. Never a salt. */
	public static function now() {
		$out = array();
		foreach ( self::SCHEMES as $s ) {
			$out[ $s ] = substr( hash( 'sha256', 'zdz-salt-fp-v1|' . $s . '|' . wp_salt( $s ) ), 0, 12 );
		}
		return $out;
	}

	public static function record( $uid = 0 ) {
		update_option( self::OPT, array( 'v' => 1, 'at' => gmdate( 'Y-m-d H:i:s' ), 'by' => (int) $uid, 'fp' => self::now() ), false );
	}

	/** The schemes whose salt changed since the fingerprints were kept ([] when none; the first run records and says []). */
	public static function changed() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return array();
		}
		$have = get_option( self::OPT, null );
		if ( ! is_array( $have ) || empty( $have['fp'] ) || ! is_array( $have['fp'] ) ) {
			self::record( 0 );
			return array();
		}
		$out = array();
		foreach ( self::now() as $s => $fp ) {
			if ( isset( $have['fp'][ $s ] ) && ! hash_equals( (string) $have['fp'][ $s ], $fp ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Plain sentences: what breaks when these salts changed, and what to do about each. */
	public static function breaks( array $changed ) {
		$auth   = in_array( 'auth', $changed, true );
		$secure = in_array( 'secure_auth', $changed, true );
		$out    = array();
		if ( $auth ) {
			$out[] = 'Stored credentials can no longer be read: re-enter each key on Zorderz → Settings.';
			$out[] = 'Receipt and other share links already sent stop working.';
			$out[] = 'Email and calendar sign-ins stop: people connect their accounts again.';
		}
		/**
		 * Apps that keep something under these salts add their own line here (what breaks, what to do):
		 * filter zdz_salt_watch_breaks( $lines, $changed ). Only features that exist on this site are named.
		 */
		$extra = apply_filters( 'zdz_salt_watch_breaks', array(), $changed );
		foreach ( (array) $extra as $line ) {
			if ( is_string( $line ) && '' !== $line ) {
				$out[] = $line;
			}
		}
		if ( $secure && class_exists( 'ZDZ_Sealed' ) ) {
			// "still open" only when codes really can be opened now (can_keep()); a lock, missing salts or a bad
			// ZDZ_SEALED_KEY are all mended by the printed recovery code in ZDZ_SEALED_KEY
			if ( method_exists( 'ZDZ_Sealed', 'can_keep' ) ? ZDZ_Sealed::can_keep() : ( method_exists( 'ZDZ_Sealed', 'locked' ) && ! ZDZ_Sealed::locked() ) ) {
				$out[] = 'Gate codes still open: their key is held apart from these salts (ZDZ_SEALED_KEY).';
			} else {
				$out[] = 'Gate codes are locked: add ZDZ_SEALED_KEY to wp-config.php with the printed recovery code (Zorderz → Gate code key).';
			}
		}
		$out[] = ( $auth || $secure )
			? 'Everyone is signed out and signs in again.'
			: 'Everyone is signed out and signs in again. Nothing else is affected.';
		return $out;
	}

	private static function names( array $changed ) {
		$map = array( 'auth' => 'AUTH', 'secure_auth' => 'SECURE_AUTH', 'logged_in' => 'LOGGED_IN', 'nonce' => 'NONCE' );
		$n   = array_map( function ( $s ) use ( $map ) { return $map[ $s ] ?? strtoupper( $s ); }, $changed );
		if ( count( $n ) > 2 ) {
			$last = array_pop( $n );
			return implode( ', ', $n ) . ', and ' . $last;
		}
		return implode( ' and ', $n );
	}

	/** The words both the notice and Site Health use. */
	public static function message( array $changed ) {
		return array(
			'head'  => 'The site\'s secret keys changed (' . self::names( $changed ) . ' in wp-config.php).',
			'first' => 'If that was a mistake, put the saved lines back in wp-config.php from your password manager: that undoes all of the below. Do not make new keys.',
			'items' => self::breaks( $changed ),
		);
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$changed = self::changed();
		if ( ! $changed ) {
			return;
		}
		$m = self::message( $changed );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $m['head'] ) . '</strong> ' . esc_html( $m['first'] ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $m['items'] as $i ) {
			echo '<li>' . esc_html( $i ) . '</li>';
		}
		echo '</ul>' . self::button() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() is built from esc_url(), esc_attr(), wp_nonce_field() and fixed text
	}

	private static function button() {
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 10px">';
		$out .= wp_nonce_field( self::ACTION, '_wpnonce', true, false );
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$out .= '<button class="button" onclick="return confirm(\'Only once everything above is dealt with: record the new fingerprints, so this notice stops? Nothing else changes.\')">I\'ve dealt with it: record the new fingerprints</button></form>';
		return $out;
	}

	public static function handle_rerecord() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::ACTION );
		self::record( (int) get_current_user_id() );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function site_health( $tests ) {
		$tests['direct']['zdz_salt_watch'] = array(
			'label' => 'Zorderz: the site\'s secret keys',
			'test'  => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	public static function site_health_result() {
		$changed = self::changed();
		$base    = array(
			'badge' => array( 'label' => 'Security', 'color' => $changed ? 'red' : 'blue' ),
			'test'  => 'zdz_salt_watch',
		);
		if ( ! $changed ) {
			return $base + array(
				'label'       => 'The site\'s secret keys are the ones its stored keys were made with',
				'status'      => 'good',
				'description' => '<p>Stored credentials, share links and sign-ins rely on the secret keys in wp-config.php. They have not changed.</p>',
				'actions'     => '',
			);
		}
		$m    = self::message( $changed );
		$desc = '<p>' . esc_html( $m['first'] ) . '</p><ul>';
		foreach ( $m['items'] as $i ) {
			$desc .= '<li>' . esc_html( $i ) . '</li>';
		}
		return $base + array(
			'label'       => $m['head'],
			'status'      => 'critical',
			'description' => $desc . '</ul>',
			'actions'     => self::button(),
		);
	}
}
