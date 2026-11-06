<?php
/**
 * ZDZ_Cred_Expiry -- an "Expires" date beside each provider credential.
 *
 * Some providers' secrets stop working on a date (client secrets with a lifetime, service tokens). This keeps that
 * DATE only, never a key: option zdz_core_cred_expires = [row => 'Y-m-d'], one row per credential service. "A year
 * from today" fills a row with today + 365 days in one press. From 30 days before a date, and once it has passed, a
 * notice on every admin page tells administrators which one and what to do.
 *
 * The rows are every service on Zorderz → Credentials (ZDZ_Core_Credentials::services()) plus the Review Bridge key,
 * which is kept on Settings; without the Credentials screen they are the platform's own connectors. An app or a
 * connector adds its own with the `zdz_cred_expiry_rows` filter ([row => label]).
 *
 * The dates form is shown in one place: on Zorderz → Credentials, under the services it dates, when that screen
 * exists; on Zorderz → Settings otherwise.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Cred_Expiry {

	const OPT       = 'zdz_core_cred_expires';
	const ACTION    = 'zdz_cred_expires';
	const WARN_DAYS = 30;
	const YEAR_DAYS = 365;
	const PAGE      = 'zdz-core-settings';

	/** The platform's own connectors with a secret (row => label). */
	const CORE_ROWS = array(
		'poe'          => 'Ai gateway API key',
		'freshbooks'   => 'Billing app (client ID and secret)',
		'nutshell'     => 'CRM login and API key',
		'review_bridge' => 'Review bridge API key',
	);

	public static function boot() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		if ( ! self::on_credentials() ) {
			add_action( 'zdz_core_settings_after', array( __CLASS__, 'render_form' ) );
		}
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/** Does the Credentials screen exist (and so hold the dates form)? */
	public static function on_credentials() {
		return class_exists( 'ZDZ_Core_Credentials' );
	}

	/** The admin page the dates form is on. */
	public static function page() {
		return self::on_credentials() ? ZDZ_Core_Credentials::PAGE : self::PAGE;
	}

	/** [row => label]: every Credentials service (or the platform's connectors), then any an app or connector adds. */
	public static function rows() {
		$out = self::CORE_ROWS;
		if ( self::on_credentials() ) {
			$out = array();
			foreach ( ZDZ_Core_Credentials::services() as $svc => $def ) {
				$out[ $svc ] = (string) ( $def['label'] ?? $svc );
			}
			$out['review_bridge'] = self::CORE_ROWS['review_bridge'];
		}
		$extra = apply_filters( 'zdz_cred_expiry_rows', array() );
		foreach ( (array) $extra as $row => $label ) {
			$row = sanitize_key( (string) $row );
			if ( '' !== $row && is_scalar( $label ) ) {
				$out[ $row ] = (string) $label;
			}
		}
		return $out;
	}

	public static function all() {
		$v = get_option( self::OPT, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function get( $row ) {
		$all = self::all();
		return isset( $all[ $row ] ) && self::valid( $all[ $row ] ) ? (string) $all[ $row ] : '';
	}

	public static function valid( $d ) {
		$d = (string) $d;
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function today() {
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	public static function year_from_today() {
		return gmdate( 'Y-m-d', strtotime( self::today() . ' +' . self::YEAR_DAYS . ' days' ) );
	}

	/** Whole days from today to $date (negative once it has passed). */
	public static function days_left( $date ) {
		return (int) round( ( strtotime( $date . ' 00:00:00 UTC' ) - strtotime( self::today() . ' 00:00:00 UTC' ) ) / 86400 );
	}

	public static function pretty( $date ) {
		$ts = strtotime( $date . ' 12:00:00 UTC' );
		return function_exists( 'wp_date' ) ? wp_date( 'j M Y', $ts ) : gmdate( 'j M Y', $ts );
	}

	/** The short line under a service's heading. */
	public static function line( $row ) {
		$d = self::get( $row );
		if ( '' === $d ) {
			return '<p class="description">Expires: not recorded (set it under <a href="#zdz-cred-expiry">Expiry dates</a>).</p>';
		}
		$left = self::days_left( $d );
		$when = $left < 0 ? 'expired ' . abs( $left ) . ' days ago' : ( 0 === $left ? 'today' : 'in ' . $left . ' days' );
		$warn = $left <= self::WARN_DAYS;
		return '<p class="description"' . ( $warn ? ' style="color:#b32d2e;font-weight:600"' : '' ) . '>Expires: ' . esc_html( self::pretty( $d ) ) . ' (' . esc_html( $when ) . ').</p>';
	}

	/** The "Expiry dates" form, after the settings form on Zorderz → Settings. */
	public static function render_form() {
		echo '<h2 id="zdz-cred-expiry" style="margin-top:34px">Expiry dates</h2>';
		echo '<p style="max-width:900px">When a provider\'s secret stops working on a date, write the date here: a date only, never a key. From ' . (int) self::WARN_DAYS . ' days before it, every admin page says which one is due. When a provider makes you create a new secret, enter it above as usual, then set its new date here ("A year from today" is the usual one).</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		// Enter in a date box submits with the FIRST submit button: make it Save, not a row's "A year from today"
		echo '<button type="submit" name="save" value="1" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">Save the dates</button>';
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Service</th><th>Expires</th><th></th></tr></thead><tbody>';
		foreach ( self::rows() as $row => $label ) {
			$d = self::get( $row );
			echo '<tr><td>' . esc_html( $label ) . '</td><td><input type="date" name="exp[' . esc_attr( $row ) . ']" value="' . esc_attr( $d ) . '"></td>'
				. '<td><button class="button" name="year" value="' . esc_attr( $row ) . '">A year from today</button></td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary" name="save" value="1">Save the dates</button> <span class="description">An empty box means no date (no reminder).</span></p></form>';
	}

	/** Save every row's date (empty clears it); "A year from today" sets that one row to today + 365 days. */
	public static function save( array $posted, $year_row = '' ) {
		$rows = self::rows();
		$all  = self::all();
		foreach ( $rows as $row => $label ) {
			if ( ! array_key_exists( $row, $posted ) ) {
				continue;
			}
			$d = trim( (string) $posted[ $row ] );
			if ( '' === $d ) {
				unset( $all[ $row ] );
			} elseif ( self::valid( $d ) ) {
				$all[ $row ] = $d;
			}
		}
		if ( '' !== $year_row && isset( $rows[ $year_row ] ) ) {
			$all[ $year_row ] = self::year_from_today();
		}
		$all = array_intersect_key( $all, $rows );
		update_option( self::OPT, $all, false );
		return $all;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::ACTION );
		$posted = isset( $_POST['exp'] ) && is_array( $_POST['exp'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['exp'] ) ) : array();
		$year   = sanitize_key( wp_unslash( (string) ( $_POST['year'] ?? '' ) ) );
		$all    = self::save( $posted, $year );
		$rows   = self::rows();
		$msg    = '' !== $year && isset( $rows[ $year ] )
			? $rows[ $year ] . ' expires on ' . self::pretty( $all[ $year ] ) . ' (a year from today).'
			: 'Expiry dates saved.';
		set_transient( 'zdz_core_cred_msg_' . (int) get_current_user_id(), array( $msg, true ), 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::page(), 'zdz_cred_done' => '1' ), admin_url( 'admin.php' ) ) . '#zdz-cred-expiry' );
		exit;
	}

	/** [ [row, label, date, days_left], … ] due within WARN_DAYS or past, soonest first. */
	public static function due() {
		$out = array();
		foreach ( self::rows() as $row => $label ) {
			$d = self::get( $row );
			if ( '' === $d ) {
				continue;
			}
			$left = self::days_left( $d );
			if ( $left <= self::WARN_DAYS ) {
				$out[] = array( $row, $label, $d, $left );
			}
		}
		usort( $out, function ( $a, $b ) { return $a[3] <=> $b[3]; } );
		return $out;
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$due = self::due();
		if ( ! $due ) {
			return;
		}
		$url = add_query_arg( array( 'page' => self::page() ), admin_url( 'admin.php' ) ) . '#zdz-cred-expiry';
		echo '<div class="notice notice-warning"><p><strong>A provider\'s secret is due to expire.</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $due as $r ) {
			$when = $r[3] < 0 ? 'expired on ' . self::pretty( $r[2] ) : ( 0 === $r[3] ? 'expires today' : 'expires on ' . self::pretty( $r[2] ) . ' (in ' . $r[3] . ' days)' );
			$what = self::on_credentials() && 'review_bridge' !== $r[0]
				? 'Make a new one with the provider, then enter it on Zorderz → Credentials.'
				: 'Make a new one with the provider, then enter it on Zorderz → Settings.';
			echo '<li>' . esc_html( $r[1] ) . ' ' . esc_html( $when ) . '. ' . esc_html( $what ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( $url ) . '">Expiry dates</a> (set the new date there once it\'s replaced).</p></div>';
	}
}
