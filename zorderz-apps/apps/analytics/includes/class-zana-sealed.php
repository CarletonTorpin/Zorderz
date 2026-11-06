<?php
/**
 * ZANA_Sealed — the chat never carries an access code.
 *
 * A gate, lockbox, alarm, door or keypad code is never printed in plain text and never reaches a model in any form
 * (the platform's one vault, ZDZ_Sealed). The chat only READS what other apps hold, so here every code is CUT, never
 * sealed: the words keep "[gate code sealed]", and whoever may open it does so in the app that owns it, with Show code.
 *
 *   The apps     every answer a verb gives the chat (ZANA_App_Reads, the hours and charts routes) is cut, whichever app
 *                answered (deep()).
 *   Its verbs    the chat's own registry verbs answer with codes cut (guard_verbs(), registered last, so it wraps what
 *                this app registered).
 *   The person   what a person types into the chat is cut before it is saved or read by a model (ZANA_Chat).
 *   The answer   what the chat shows and keeps is cut once more.
 *   The scan     the sealed-codes scan (filter zdz_sealed_scan_sources) cuts codes in the chat's history and in "About
 *                you" sentences.
 *
 * A door SIZE is not a code ("storm door #3080", "French door no. 6080"): a "door" span counts only when its words say
 * code, pin, combo or key. Without the theme's ZDZ_Sealed nothing here changes a word.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Sealed {

	/** Tests hand in the reader: callable( string $text ) => [ [start, len, kind], … ], and the label: callable( kind ) => string. */
	public static $finder = null;
	public static $labeler = null;

	public static function boot(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'guard_verbs' ), 1000 );
		add_filter( 'zdz_sealed_scan_sources', array( __CLASS__, 'scan_sources' ), 50 );
	}

	public static function on(): bool {
		if ( is_callable( self::$finder ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'find' ) && method_exists( 'ZDZ_Sealed', 'label' );
	}

	/** Could $text hold a code? The vault's own quick check (a digit or a spoken number); no false "no". */
	private static function might( string $text ): bool {
		if ( is_callable( self::$finder ) ) {
			return true;
		}
		return ! method_exists( 'ZDZ_Sealed', 'might_have_code' ) || (bool) ZDZ_Sealed::might_have_code( $text );
	}

	/** The codes in $text, less door sizes: [ [start, len, kind, words] ]. */
	public static function spans( $text ): array {
		if ( ! is_string( $text ) || '' === $text || ! self::on() || ! self::might( $text ) ) {
			return array();
		}
		$hits = is_callable( self::$finder ) ? (array) call_user_func( self::$finder, $text ) : (array) ZDZ_Sealed::find( $text );
		$out  = array();
		foreach ( $hits as $h ) {
			$w = substr( $text, (int) $h[0], (int) $h[1] );
			if ( 'door' === $h[2] && ! preg_match( '/code|pin|combo|key/i', $w ) ) {
				continue;
			}
			$out[] = array( (int) $h[0], (int) $h[1], (string) $h[2], $w );
		}
		return $out;
	}

	private static function label( string $kind ): string {
		if ( is_callable( self::$labeler ) ) {
			return (string) call_user_func( self::$labeler, $kind );
		}
		return (string) ZDZ_Sealed::label( $kind );
	}

	/** One string with every code cut (nothing is stored). */
	public static function cut( $s ) {
		$sp = self::spans( $s );
		if ( ! $sp ) {
			return $s;
		}
		foreach ( array_reverse( $sp ) as $p ) {
			$s = substr( $s, 0, $p[0] ) . self::label( $p[2] ) . substr( $s, $p[0] + $p[1] );
		}
		return $s;
	}

	/** Every string, any depth (arrays and plain objects), cut. Keys untouched. */
	public static function deep( $v ) {
		if ( is_string( $v ) ) {
			return self::cut( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( is_string( $x ) || is_array( $x ) || $x instanceof stdClass ) {
					$v[ $k ] = self::deep( $x );
				}
			}
			return $v;
		}
		if ( $v instanceof stdClass ) {
			foreach ( get_object_vars( $v ) as $k => $x ) {
				if ( is_string( $x ) || is_array( $x ) || $x instanceof stdClass ) {
					$v->$k = self::deep( $x );
				}
			}
		}
		return $v;
	}

	/** Every verb this app registered answers with codes cut. */
	public static function guard_verbs( $caps ) {
		if ( ! is_array( $caps ) ) {
			return $caps;
		}
		foreach ( $caps as $verb => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( array( 'callable', 'callback' ) as $k ) {
				$cb = $row[ $k ] ?? null;
				if ( is_array( $cb ) && isset( $cb[0] ) && is_string( $cb[0] ) && 0 === strpos( $cb[0], 'ZANA_' ) && is_callable( $cb ) ) {
					$caps[ $verb ][ $k ] = function ( $args = array() ) use ( $cb ) {
						return ZANA_Sealed::deep( call_user_func( $cb, $args ) );
					};
				}
			}
		}
		return $caps;
	}

	/** The sealed-codes scan: the chat's copies are cut only (no scope: nothing is kept). */
	public static function scan_sources( $s ) {
		global $wpdb;
		$s = is_array( $s ) ? $s : array();
		$s['zana_messages'] = array(
			'app'   => __( 'Chat', 'zorderz' ),
			'what'  => __( 'Chat history, questions and answers (cut only)', 'zorderz' ),
			'table' => $wpdb->prefix . 'zana_messages',
			'key'   => 'id',
			'cols'  => array( 'body' => 'text' ),
			'scope' => null,
		);
		$s['zana_memory'] = array(
			'app'   => __( 'Chat', 'zorderz' ),
			'what'  => __( '"About you" sentences (cut only)', 'zorderz' ),
			'table' => $wpdb->prefix . 'zana_memory',
			'key'   => 'id',
			'cols'  => array( 'fact_value' => 'text' ),
			'scope' => null,
		);
		return $s;
	}
}
