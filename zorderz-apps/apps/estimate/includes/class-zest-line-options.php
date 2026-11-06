<?php
/**
 * ZEST_Line_Options - the press-and-hold option map: which words in a line may be swapped for which others.
 *
 * The map says, per product family, which "slots" a line has (a colour, a material, a model, a size ...) and the words
 * each slot's options are written and said as, most-used first. The billing view and the line editor read it so a
 * person changes "white" to "black" by picking it from a menu instead of retyping the line, and the server checks the
 * swap against the same map (check_option()). No model is ever asked: the same map gives the same menus every time.
 *
 * The map is the business's own catalog, never Core's. Where it comes from, in order:
 *   1. the copy last fetched from the charts hub (op `line_options`, through ZCH_Hub_Client when the charts app is
 *      connected; daily), when it passes validate();
 *   2. the copy an Identity Pack wrote (catalog.yml `estimate.line_options`, option zest_line_options_pack);
 *   3. none: no menus, and an option change is refused ("the list of choices is not available").
 *
 * Shape (map_version 1):
 *   { map_version: 1, built: "YYYY-MM-DD",
 *     colors:        [ { id, label, say: [] } ],
 *     color_context: { slot_id: [ words that say a colour belongs to this slot ] },
 *     slots:         { slot_id: { label, kind?: "color", options?: [ { id, label, write, say: [] } ] } },
 *     families:      [ { id, label, match: [ words ], slots: [ slot_id ], color_default?, ranked? } ] }
 * Every string is short plain text (no markup, no control characters); ids are [a-z0-9_].
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Line_Options {

	const OPTION       = 'zest_line_options_map';   // the hub's copy, with when and why (autoload off)
	const PACK_OPTION  = 'zest_line_options_pack';  // the Identity Pack's copy
	const CRON_HOOK    = 'zest_line_options_refresh';
	const MAX_BYTES    = 200000;
	const MAX_FAMILIES = 30;
	const MAX_SLOTS    = 40;
	const MAX_OPTIONS  = 60;
	const MAX_SAY      = 40;
	const MAX_STR      = 80;

	/** @var array|null per-request memo ([] = none) */
	private static $memo = null;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_from_hub' ) );
		if ( class_exists( 'ZCH_Hub_Client' ) ) {
			self::schedule();
		}
	}

	public static function flush(): void {
		self::$memo = null;
	}

	/* ------------------------------------------------------------ read */

	/** The map in use (the hub's copy when valid, else the pack's), or null. */
	public static function get(): ?array {
		if ( null !== self::$memo ) {
			return self::$memo ? self::$memo : null;
		}
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( is_array( $stored ) && isset( $stored['map'] ) && self::validate( $stored['map'] ) ) {
			self::$memo = $stored['map'];
			return self::$memo;
		}
		$pack = function_exists( 'get_option' ) ? get_option( self::PACK_OPTION, null ) : null;
		self::$memo = self::validate( $pack ) ? $pack : array();
		return self::$memo ? self::$memo : null;
	}

	/** Where the map in use came from: hub | pack | none, with when it was built and the last fetch error. */
	public static function status(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$hub    = is_array( $stored ) && isset( $stored['map'] ) && self::validate( $stored['map'] );
		$map    = self::get();
		return array(
			'from'       => $hub ? 'hub' : ( $map ? 'pack' : 'none' ),
			'built'      => is_array( $map ) ? (string) ( $map['built'] ?? '' ) : '',
			'fetched_at' => is_array( $stored ) ? (int) ( $stored['fetched_at'] ?? 0 ) : 0,
			'last_error' => is_array( $stored ) ? (string) ( $stored['last_error'] ?? '' ) : '',
		);
	}

	/** A short fingerprint of the map (the editor sends it back, so a stale menu is noticed). */
	public static function fingerprint( $map = null ): string {
		$map = null === $map ? self::get() : $map;
		return is_array( $map ) && $map ? substr( hash( 'sha256', (string) json_encode( $map ) ), 0, 12 ) : '';
	}

	/* ------------------------------------------------------------ refresh */

	/** Fetch the map from the charts hub. Keeps the previous copy on any failure and records why (no data in it). */
	public static function refresh_from_hub(): bool {
		if ( ! class_exists( 'ZCH_Hub_Client' ) ) {
			self::note_error( 'the charts app is not active' );
			return false;
		}
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			self::note_error( 'the charts hub is not connected on this site' );
			return false;
		}
		try {
			$reply = $client->call(
				'line_options',
				array( 'access' => array( 'caller' => 'app:zest', 'ops' => array( 'health', 'line_options' ), 'scope' => null, 'kiosk' => false ) ),
				'wp_zest',
				function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' )
			);
		} catch ( \Throwable $e ) {
			$reply = null;
		}
		if ( ! is_array( $reply ) || empty( $reply['ok'] ) ) {
			$code = is_array( $reply ) ? (string) ( $reply['error']['code'] ?? 'error' ) : 'error';
			self::note_error( 'the charts hub answered ' . preg_replace( '/[^a-z_]/', '', $code ) );
			return false;
		}
		$map = $reply['data']['map'] ?? null;
		if ( ! self::validate( $map ) ) {
			self::note_error( 'the hub sent a map that did not pass the checks' );
			return false;
		}
		update_option( self::OPTION, array( 'map' => $map, 'fetched_at' => time(), 'last_error' => '' ), false );
		self::$memo = null;
		return true;
	}

	private static function note_error( string $why ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['last_error']    = substr( $why, 0, 120 );
		$stored['last_error_at'] = time();
		update_option( self::OPTION, $stored, false );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------ checks */

	private static function ok_str( $s ): bool {
		return is_string( $s ) && '' !== $s && strlen( $s ) <= self::MAX_STR && ! preg_match( '/[<>\x00-\x1f]/', $s );
	}

	private static function ok_option( $o, bool $need_write ): bool {
		if ( ! is_array( $o ) || ! self::ok_str( $o['id'] ?? null ) || ! self::ok_str( $o['label'] ?? null ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-z0-9_]{1,30}$/', $o['id'] ) || ( $need_write && ! self::ok_str( $o['write'] ?? null ) ) ) {
			return false;
		}
		$say = $o['say'] ?? null;
		if ( ! is_array( $say ) || ! $say || count( $say ) > self::MAX_SAY ) {
			return false;
		}
		foreach ( $say as $s ) {
			if ( ! self::ok_str( $s ) ) {
				return false;
			}
		}
		return true;
	}

	/** Structural checks on a map from anywhere (hub or pack). Fails closed. */
	public static function validate( $map ): bool {
		if ( ! is_array( $map ) || 1 !== (int) ( $map['map_version'] ?? 0 ) ) {
			return false;
		}
		$fams  = $map['families'] ?? null;
		$slots = $map['slots'] ?? null;
		$cols  = $map['colors'] ?? array();
		if ( ! is_array( $fams ) || ! is_array( $slots ) || ! is_array( $cols ) ) {
			return false;
		}
		if ( count( $fams ) < 1 || count( $fams ) > self::MAX_FAMILIES || count( $slots ) > self::MAX_SLOTS || count( $cols ) > self::MAX_OPTIONS ) {
			return false;
		}
		foreach ( $cols as $c ) {
			if ( ! self::ok_option( $c, false ) ) {
				return false;
			}
		}
		foreach ( $slots as $sid => $s ) {
			if ( ! preg_match( '/^[a-z0-9_]{1,30}$/', (string) $sid ) || ! is_array( $s ) || ! self::ok_str( $s['label'] ?? null ) ) {
				return false;
			}
			if ( 'color' === ( $s['kind'] ?? '' ) ) {
				continue;
			}
			$opts = $s['options'] ?? null;
			if ( ! is_array( $opts ) || ! $opts || count( $opts ) > self::MAX_OPTIONS ) {
				return false;
			}
			foreach ( $opts as $o ) {
				if ( ! self::ok_option( $o, true ) ) {
					return false;
				}
			}
		}
		foreach ( $fams as $f ) {
			if ( ! is_array( $f ) || ! self::ok_str( $f['id'] ?? null ) || ! self::ok_str( $f['label'] ?? null ) ) {
				return false;
			}
			if ( ! is_array( $f['match'] ?? null ) || ! is_array( $f['slots'] ?? null ) ) {
				return false;
			}
			foreach ( $f['match'] as $m ) {
				if ( ! self::ok_str( $m ) ) {
					return false;
				}
			}
			foreach ( $f['slots'] as $sid ) {
				if ( ! is_string( $sid ) || ! isset( $slots[ $sid ] ) ) {
					return false;
				}
			}
		}
		$cc = $map['color_context'] ?? array();
		if ( ! is_array( $cc ) ) {
			return false;
		}
		foreach ( $cc as $words ) {
			if ( ! is_array( $words ) ) {
				return false;
			}
			foreach ( $words as $w ) {
				if ( ! self::ok_str( $w ) ) {
					return false;
				}
			}
		}
		return strlen( (string) json_encode( $map ) ) <= self::MAX_BYTES;
	}

	/* ------------------------------------------------------------ lookups */

	/** One slot's options: [ id => { label, write, say[] } ]; the colours for a colour slot. */
	public static function slot_options( array $map, string $slot ): array {
		$s = $map['slots'][ $slot ] ?? null;
		if ( ! is_array( $s ) ) {
			return array();
		}
		$out = array();
		if ( 'color' === ( $s['kind'] ?? '' ) ) {
			foreach ( (array) ( $map['colors'] ?? array() ) as $c ) {
				$out[ $c['id'] ] = array( 'label' => $c['label'], 'write' => strtolower( $c['label'] ), 'say' => (array) $c['say'] );
			}
			return $out;
		}
		foreach ( (array) ( $s['options'] ?? array() ) as $o ) {
			$out[ $o['id'] ] = array( 'label' => $o['label'], 'write' => $o['write'], 'say' => (array) $o['say'] );
		}
		return $out;
	}

	/** Lower case, with curly quotes and long dashes folded (the browser's tokenizer does the same). */
	public static function norm( string $s ): string {
		$s = strtolower( $s );
		$s = str_replace( array( "\u{201C}", "\u{201D}", "\u{2033}" ), '"', $s );
		$s = str_replace( "\u{2019}", "'", $s );
		return str_replace( array( "\u{2013}", "\u{2014}" ), '-', $s );
	}

	private static function word_char( string $ch ): bool {
		return '' !== $ch && (bool) preg_match( '/[a-z0-9]/i', $ch );
	}

	/** The line's family: the first family (in map order) with a match word on word boundaries, or null. */
	public static function family_of( string $text, array $map ): ?array {
		$t = ' ' . self::norm( $text ) . ' ';
		foreach ( (array) ( $map['families'] ?? array() ) as $f ) {
			foreach ( (array) ( $f['match'] ?? array() ) as $w ) {
				$w = self::norm( (string) $w );
				if ( '' === $w ) {
					continue;
				}
				$i = strpos( $t, $w );
				while ( false !== $i ) {
					$b = $i > 0 ? $t[ $i - 1 ] : '';
					$e = $t[ $i + strlen( $w ) ] ?? '';
					if ( ! self::word_char( $b ) && ! ( self::word_char( substr( $w, -1 ) ) && self::word_char( $e ) ) ) {
						return $f;
					}
					$i = strpos( $t, $w, $i + 1 );
				}
			}
		}
		return null;
	}

	/** Which option of $slot the words $said name, or ''. */
	public static function option_named( array $map, string $slot, string $said ): string {
		$n = self::norm( trim( $said ) );
		foreach ( self::slot_options( $map, $slot ) as $id => $o ) {
			foreach ( $o['say'] as $s ) {
				if ( self::norm( (string) $s ) === $n ) {
					return (string) $id;
				}
			}
		}
		return '';
	}

	/** $write in the case style of $original: ALL CAPS stays all caps, a capital first letter stays capital. */
	public static function apply_case( string $original, string $write ): string {
		if ( strlen( $original ) > 1 && strtoupper( $original ) === $original && strtolower( $original ) !== $original ) {
			return strtoupper( $write );
		}
		$first = substr( $original, 0, 1 );
		if ( '' !== $first && strtolower( $first ) !== $first ) {
			return strtoupper( substr( $write, 0, 1 ) ) . substr( $write, 1 );
		}
		return $write;
	}

	/* ------------------------------------------------------------ one swap */

	/**
	 * Check one swap: {start, end, from, to, slot, option} on $text (character offsets). $family_slots: the slots the
	 * line's family has (null: any). Returns the checked change { start, end, from, to, slot, option, was }, or the
	 * reason it was refused.
	 *
	 * @return array|string
	 */
	public static function check_option( string $text, array $c, array $map, ?array $family_slots = null ) {
		$start = $c['start'] ?? null;
		$end   = $c['end'] ?? null;
		$len   = mb_strlen( $text, 'UTF-8' );
		if ( ! is_int( $start ) || ! is_int( $end ) || $start < 0 || $end <= $start || $end > $len ) {
			return __( 'the word to change could not be found. Open the document again and try again.', 'zorderz' );
		}
		$from = (string) ( $c['from'] ?? '' );
		if ( mb_substr( $text, $start, $end - $start, 'UTF-8' ) !== $from || trim( $from ) !== $from ) {
			return __( 'the wording changed since you opened it. Open the document again and try again.', 'zorderz' );
		}
		$before = $start > 0 ? mb_substr( $text, $start - 1, 1, 'UTF-8' ) : '';
		$after  = mb_substr( $text, $end, 1, 'UTF-8' );
		if ( self::word_char( $before ) || ( self::word_char( mb_substr( $from, -1, 1, 'UTF-8' ) ) && self::word_char( $after ) ) ) {
			return __( 'only a whole word can be swapped. Open the document again and try again.', 'zorderz' );
		}
		$slot = (string) ( $c['slot'] ?? '' );
		if ( null !== $family_slots && ! in_array( $slot, $family_slots, true ) ) {
			return __( 'that kind of choice does not belong on this line.', 'zorderz' );
		}
		$opts = self::slot_options( $map, $slot );
		if ( ! $opts ) {
			return __( 'that kind of choice is not in the list.', 'zorderz' );
		}
		$was = self::option_named( $map, $slot, $from );
		if ( '' === $was ) {
			/* translators: 1: the word, 2: the kind of choice */
			return sprintf( __( '"%1$s" is not one of the choices for %2$s.', 'zorderz' ), $from, (string) ( $map['slots'][ $slot ]['label'] ?? $slot ) );
		}
		$oid = (string) ( $c['option'] ?? '' );
		if ( ! isset( $opts[ $oid ] ) ) {
			return __( 'that choice is not in the list.', 'zorderz' );
		}
		if ( $oid === $was ) {
			return __( 'that is already the choice.', 'zorderz' );
		}
		$want = self::apply_case( $from, (string) $opts[ $oid ]['write'] );
		if ( (string) ( $c['to'] ?? '' ) !== $want ) {
			return __( 'the new wording does not match the choice.', 'zorderz' );
		}
		return array( 'start' => $start, 'end' => $end, 'from' => $from, 'to' => $want, 'slot' => $slot, 'option' => $oid, 'was' => $was );
	}

	/**
	 * Apply several swaps to one text, last first, so earlier offsets stay true. Returns [ text, checked[] ] or the
	 * reason the whole set was refused (two swaps overlapping included).
	 *
	 * @return array|string
	 */
	public static function apply_swaps( string $text, array $changes, array $map, ?array $family_slots = null ) {
		usort( $changes, static function ( $a, $b ) {
			return (int) ( $b['start'] ?? 0 ) <=> (int) ( $a['start'] ?? 0 );
		} );
		$out      = $text;
		$done     = array();
		$last_end = PHP_INT_MAX;
		foreach ( $changes as $c ) {
			$v = self::check_option( $text, (array) $c, $map, $family_slots );
			if ( is_string( $v ) ) {
				return $v;
			}
			if ( $v['end'] > $last_end ) {
				return __( 'two changes overlap. Undo one and save again.', 'zorderz' );
			}
			$last_end = $v['start'];
			$out      = mb_substr( $out, 0, $v['start'], 'UTF-8' ) . $v['to'] . mb_substr( $out, $v['end'], null, 'UTF-8' );
			$done[]   = $v;
		}
		return array( $out, array_reverse( $done ) );
	}

	/** What the browser needs to draw the menus: the map (when the person's copy is stale) and its fingerprint. */
	public static function for_browser( string $have ): array {
		$map = self::get();
		$fp  = self::fingerprint( $map );
		$out = array( 'map_fp' => $fp, 'map_from' => self::status()['from'] );
		if ( $map && $have !== $fp ) {
			$out['map'] = $map;
		}
		return $out;
	}
}
