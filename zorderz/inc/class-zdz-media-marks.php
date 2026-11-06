<?php
/**
 * Drawings on a photo, kept apart from the photo.
 *
 * ANYONE who may see a photo may draw on it, and each person's drawing is their own LAYER - seen by
 * them alone until they share it, like a new photo of theirs (only me / everyone who can see the
 * photo / chosen people), and drawn from a Project it is shared with that Project's people. A line
 * can be an arrow that ends in a Post-it holding a note written by the drawer. Administrators can
 * choose to see everyone's (off by default). The photo file itself never changes.
 *
 * What is stored (zdz_user_media.marks_json, one document per photo, so a rotate still turns every
 * drawing in the SAME guarded UPDATE as the photo):
 *   { "v": 2, "layers": [ { "uid": 31, "rev": 3, "at": "2026-01-15 16:00:00",
 *        "share": "private|team|people|project", "people": [ 44, 52 ], "context": "project:79" | "",
 *        "strokes": [ { "id": "a1b2c3d4", "c": "r", "s": 2, "p": [ x0, y0, ... ], "k": "l|a", "n": "note (arrows only)" } ],
 *        "history": [ { "at": "...", "add": 2, "rm": 0 } ] } ] }                       (the last 10 saves)
 * Points are whole-number fractions (0..10000) of the photo's width and height. c is a pen from PENS
 * (never a free color), s a width 1..3, k a line or an Arrow; an arrow's first point is its head (the
 * part it points at), its last point is where the Post-it sits. An older document (v 1: one set, a
 * `by` on each line) is read as one layer per drawer, shared with everyone who can see the photo, so
 * nobody loses what they saw; nothing is rewritten until someone saves.
 *
 * Who sees a layer (view()): only someone who may see the PHOTO (ZDZ_User_Media decides that, before
 * this class is asked), and then: its drawer; everyone, when shared with the team; the people it
 * names; the Project's people when the filter `zdz_media_layer_audience` says so (the jobs app answers
 * it - this theme knows nothing about Projects); an administrator who turned on "Show everyone's
 * drawings". The shared device draws nothing and sees only team layers.
 *
 * This class is the rules and the REST routes; ZDZ_User_Media keeps the column, the guarded saves and
 * the rotation. The kit draws (ZDZ.ui.marks); its limits are these.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Media_Marks {

	const GONE_KEEP = 200;  // cleared drawings whose last revision is remembered
	/** The pens a person may draw with (the kit maps each to its color). */
	const PENS   = array( 'r', 'y', 'b', 'w', 'k' );
	const KINDS  = array( 'l', 'a' );                         // a line, an arrow (which may carry a note)
	const SHARES = array( 'private', 'team', 'people', 'project' );

	// Per layer: these bound one person's drawing.
	const MAX_STROKES      = 100;
	const MAX_POINTS       = 1000;   // per line
	const MAX_TOTAL_POINTS = 6000;   // per layer
	const MAX_NOTES        = 20;     // per layer
	const NOTE_MAX         = 500;    // characters in one note
	// Per photo.
	const MAX_LAYERS       = 20;
	const MAX_PHOTO_POINTS = 30000;
	const MAX_PEOPLE       = 30;     // people one layer is shared with
	const SCALE            = 10000;  // a coordinate is a fraction of the side times this
	const HISTORY_KEEP     = 10;
	const PREF             = 'zdz_marks_show_all'; // user meta: an administrator's "Show everyone's drawings"

	/** @var array<string,bool> one request's answers from the audience filter */
	private static $aud = array();

	/** @var array<int,bool> one request's answers: does this person use the app (or is it the shared device)? */
	private static $readers = array();

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	// ------------------------------------------------------------------------------------------------ the shape

	/** A note's text, made safe: plain text, no markup, no control characters, at most NOTE_MAX characters. */
	public static function note_text( $v ) {
		if ( null === $v || '' === $v ) {
			return '';
		}
		if ( ! is_string( $v ) ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'A note could not be read.', 'zorderz' ) );
		}
		// Refused, never altered. Markup is not removed (it is shown as text everywhere - text nodes,
		// esc() - so "gap <2mm" stays what the person wrote); text that is not UTF-8 is refused rather than emptied.
		if ( ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $v, 'UTF-8' ) ) || false === @preg_match( '//u', $v ) ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'A note could not be read.', 'zorderz' ) );
		}
		$t = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v );
		$t = null === $t ? null : trim( preg_replace( "/\r\n?/", "\n", $t ) );
		if ( null === $t || ( function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t ) ) > self::NOTE_MAX ) {
			return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'A note can be at most %d characters.', 'zorderz' ), self::NOTE_MAX ) );
		}
		return $t;
	}

	/**
	 * The lines a browser sent for ONE layer, made safe, or a WP_Error naming what is wrong. Nothing is guessed: a line
	 * that does not follow the shape is refused, not trimmed, so what a person saw is what is saved.
	 *
	 * @param mixed $in  the decoded `strokes` array
	 * @return array|WP_Error list of [ id, c, s, p, k, (n) ]
	 */
	public static function clean( $in ) {
		if ( null === $in || '' === $in ) {
			return array();
		}
		if ( ! is_array( $in ) || ( $in && array_keys( $in ) !== range( 0, count( $in ) - 1 ) ) ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
		}
		if ( count( $in ) > self::MAX_STROKES ) {
			return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'That is more lines than one drawing can hold (%d).', 'zorderz' ), self::MAX_STROKES ) );
		}
		$out   = array();
		$seen  = array();
		$total = 0;
		$notes = 0;
		foreach ( $in as $s ) {
			if ( ! is_array( $s ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$id = isset( $s['id'] ) && is_string( $s['id'] ) ? $s['id'] : '';
			if ( ! preg_match( '/^[a-z0-9]{6,16}$/', $id ) || isset( $seen[ $id ] ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$seen[ $id ] = true;
			$c = isset( $s['c'] ) && is_string( $s['c'] ) ? $s['c'] : '';
			if ( ! in_array( $c, self::PENS, true ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$w = $s['s'] ?? null;
			if ( ! is_int( $w ) && ! ( is_string( $w ) && ctype_digit( $w ) ) && ! ( is_float( $w ) && floor( $w ) === $w ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$w = (int) $w;
			if ( $w < 1 || $w > 3 ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$k = isset( $s['k'] ) ? $s['k'] : 'l';
			if ( ! is_string( $k ) || ! in_array( $k, self::KINDS, true ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$p = $s['p'] ?? null;
			if ( is_array( $p ) && count( $p ) > 2 * self::MAX_POINTS ) {
				return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'One line is longer than a line can be (%d points). Undo it and draw it in parts.', 'zorderz' ), self::MAX_POINTS ) );
			}
			if ( ! is_array( $p ) || count( $p ) < 2 || count( $p ) % 2 || ( 'a' === $k && 4 !== count( $p ) ) ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
			}
			$pts = array();
			foreach ( $p as $v ) {
				if ( is_int( $v ) ) {
					$n = $v;
				} elseif ( is_float( $v ) && is_finite( $v ) && floor( $v ) === $v ) {
					$n = (int) $v;
				} else {
					return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
				}
				if ( $n < 0 || $n > self::SCALE ) {
					return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
				}
				$pts[] = $n;
			}
			$total += count( $pts ) / 2;
			if ( $total > self::MAX_TOTAL_POINTS ) {
				return new WP_Error( 'zdz_media_marks_bad', __( 'That is more drawing than one person can put on a photo.', 'zorderz' ) );
			}
			$one = array( 'id' => $id, 'c' => $c, 's' => $w, 'p' => $pts, 'k' => $k );
			if ( array_key_exists( 'n', $s ) && null !== $s['n'] && '' !== $s['n'] ) {
				if ( 'a' !== $k ) { // a note hangs only on an arrow's Post-it
					return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ) );
				}
				$n = self::note_text( $s['n'] );
				if ( is_wp_error( $n ) ) {
					return $n;
				}
				if ( '' !== $n ) {
					if ( ++$notes > self::MAX_NOTES ) {
						return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'That is more notes than one drawing can hold (%d).', 'zorderz' ), self::MAX_NOTES ) );
					}
					$one['n'] = $n;
				}
			}
			$out[] = $one;
		}
		return $out;
	}

	/** The stored document as v2 (a v1 one is read as one layer per drawer); an empty one when there is none. */
	public static function decode( $raw ): array {
		$d = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $d ) ) {
			return array( 'v' => 2, 'layers' => array() );
		}
		if ( 2 === (int) ( $d['v'] ?? 0 ) ) {
			$layers = array();
			foreach ( (array) ( $d['layers'] ?? array() ) as $l ) {
				if ( ! is_array( $l ) || (int) ( $l['uid'] ?? 0 ) <= 0 ) {
					continue;
				}
				$layers[] = self::norm_layer( $l );
			}
			// The last revision of a drawing its drawer cleared, so a device still holding that revision
			// cannot pass the stale check against a new drawing that would otherwise start again at 1.
			$gone = array();
			foreach ( (array) ( $d['gone'] ?? array() ) as $u => $r ) {
				if ( (int) $u > 0 && (int) $r > 0 ) {
					$gone[ (int) $u ] = (int) $r;
				}
			}
			return array( 'v' => 2, 'layers' => $layers, 'gone' => array_slice( $gone, -self::GONE_KEEP, null, true ) );
		}
		// v1: one set of lines, each with the person who drew it. One layer per drawer, shared with everyone who can
		// see the photo (it was shown to all of them).
		$by = array();
		foreach ( (array) ( $d['strokes'] ?? array() ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$u = (int) ( $s['by'] ?? ( $d['by'] ?? 0 ) );
			if ( $u <= 0 ) {
				continue;
			}
			$at = (string) ( $s['at'] ?? '' );
			if ( ! isset( $by[ $u ] ) ) {
				$by[ $u ] = array( 'uid' => $u, 'rev' => max( 1, (int) ( $d['rev'] ?? 1 ) ), 'at' => $at, 'share' => 'team', 'people' => array(), 'context' => '', 'strokes' => array(), 'history' => array() );
			}
			if ( $at > $by[ $u ]['at'] ) {
				$by[ $u ]['at'] = $at;
			}
			$by[ $u ]['strokes'][] = array( 'id' => $s['id'] ?? '', 'c' => $s['c'] ?? '', 's' => $s['s'] ?? 0, 'p' => $s['p'] ?? array(), 'k' => 'l' );
		}
		return array( 'v' => 2, 'layers' => array_values( array_map( array( __CLASS__, 'norm_layer' ), $by ) ) );
	}

	private static function norm_layer( array $l ): array {
		$share = in_array( $l['share'] ?? '', self::SHARES, true ) ? (string) $l['share'] : 'private';
		$ctx   = isset( $l['context'] ) && is_string( $l['context'] ) && preg_match( '/^[a-z]{2,20}:\d{1,12}$/', $l['context'] ) ? $l['context'] : '';
		if ( 'project' === $share && '' === $ctx ) {
			$share = 'private';
		}
		return array(
			'uid'     => (int) $l['uid'],
			'rev'     => max( 0, (int) ( $l['rev'] ?? 0 ) ),
			'at'      => (string) ( $l['at'] ?? '' ),
			'share'   => $share,
			'people'  => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $l['people'] ?? array() ) ), function ( $i ) { return $i > 0; } ) ) ),
			'context' => $ctx,
			'strokes' => array_values( array_filter( (array) ( $l['strokes'] ?? array() ), 'is_array' ) ),
			'history' => array_values( array_filter( (array) ( $l['history'] ?? array() ), 'is_array' ) ),
		);
	}

	/**
	 * A layer's key for the browser (show / hide, the note card): stable for one person on one photo, but not their user
	 * id (the browser never learns who is who by number).
	 */
	public static function layer_key( int $uid, int $media_id ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'zdz-media-marks';
		return 'k' . substr( hash_hmac( 'sha256', $uid . '|' . $media_id, 'zdz-marks-key|' . $salt ), 0, 12 );
	}

	/** The revision this person's drawing is at: their layer's, or the one it had when they cleared it, or 0. */
	public static function last_rev( array $doc, int $uid ): int {
		$i = self::layer_index( $doc, $uid );
		return $i >= 0 ? (int) $doc['layers'][ $i ]['rev'] : (int) ( $doc['gone'][ $uid ] ?? 0 );
	}

	/**
	 * Did this person's OWN drawing move since $base_rev (the revision they started from: 0 for a first drawing)? Then a
	 * save is refused (409) with the drawings as they are now. Someone else's drawing moving never makes this true.
	 */
	public static function is_stale( array $doc, int $uid, int $base_rev ): bool {
		return $base_rev !== self::last_rev( $doc, $uid );
	}

	/** Index of this person's layer in the document, or -1. */
	public static function layer_index( array $doc, int $uid ): int {
		foreach ( $doc['layers'] as $i => $l ) {
			if ( (int) $l['uid'] === $uid ) {
				return (int) $i;
			}
		}
		return -1;
	}

	private static function points( array $strokes ): int {
		$n = 0;
		foreach ( $strokes as $s ) {
			$n += (int) ( count( (array) ( $s['p'] ?? array() ) ) / 2 );
		}
		return $n;
	}

	private static function same( array $a, array $b ): bool {
		return ( $a['c'] ?? '' ) === ( $b['c'] ?? '' ) && (int) ( $a['s'] ?? 0 ) === (int) ( $b['s'] ?? 0 )
			&& ( $a['k'] ?? 'l' ) === ( $b['k'] ?? 'l' ) && (string) ( $a['n'] ?? '' ) === (string) ( $b['n'] ?? '' )
			&& array_map( 'intval', (array) ( $a['p'] ?? array() ) ) === array_map( 'intval', (array) ( $b['p'] ?? array() ) );
	}

	/**
	 * The document after $uid saves their own layer as $clean (the whole layer). A first save made from a context (a
	 * Project) starts shared with that context's people; any other first save starts private. Clearing every line removes
	 * the layer. Returns [ doc, added, removed ] or a WP_Error when the photo would hold more than it can.
	 */
	public static function with_layer( array $doc, int $uid, array $clean, string $when, string $context = '' ) {
		$i   = self::layer_index( $doc, $uid );
		$old = $i >= 0 ? $doc['layers'][ $i ] : null;
		$was = array();
		foreach ( (array) ( $old['strokes'] ?? array() ) as $s ) {
			$was[ (string) ( $s['id'] ?? '' ) ] = $s;
		}
		$added = 0;
		$kept  = 0;
		foreach ( $clean as $s ) {
			if ( isset( $was[ $s['id'] ] ) && self::same( $was[ $s['id'] ], $s ) ) {
				$kept++;
			} else {
				$added++;
			}
		}
		$removed = count( $was ) - $kept;
		if ( ! $added && ! $removed ) {
			return array( $doc, 0, 0 );
		}
		if ( ! $clean ) {
			$doc['gone'] = (array) ( $doc['gone'] ?? array() );
			unset( $doc['gone'][ $uid ] );
			$doc['gone'][ $uid ] = (int) $old['rev'] + 1;   // the clear is a revision too
			$doc['gone'] = array_slice( $doc['gone'], -self::GONE_KEEP, null, true );
			array_splice( $doc['layers'], $i, 1 );
			return array( $doc, 0, $removed );
		}
		if ( ! $old ) {
			if ( count( $doc['layers'] ) >= self::MAX_LAYERS ) {
				return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'This photo already holds as many drawings as it can (%d).', 'zorderz' ), self::MAX_LAYERS ) );
			}
			$ctx = preg_match( '/^[a-z]{2,20}:\d{1,12}$/', $context ) ? $context : '';
			$old = array( 'uid' => $uid, 'rev' => (int) ( $doc['gone'][ $uid ] ?? 0 ), 'at' => '', 'share' => '' !== $ctx ? 'project' : 'private', 'people' => array(), 'context' => $ctx, 'strokes' => array(), 'history' => array() );
		}
		$others = 0;
		foreach ( $doc['layers'] as $j => $l ) {
			if ( $j !== $i ) {
				$others += self::points( $l['strokes'] );
			}
		}
		if ( $others + self::points( $clean ) > self::MAX_PHOTO_POINTS ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'This photo holds as much drawing as it can. Undo some of yours first.', 'zorderz' ) );
		}
		unset( $doc['gone'][ $uid ] );
		$old['strokes']   = $clean;
		$old['rev']       = (int) $old['rev'] + 1;
		$old['at']        = $when;
		$old['history'][] = array( 'at' => $when, 'add' => $added, 'rm' => $removed );
		if ( count( $old['history'] ) > self::HISTORY_KEEP ) {
			$old['history'] = array_slice( $old['history'], -self::HISTORY_KEEP );
		}
		if ( $i >= 0 ) {
			$doc['layers'][ $i ] = $old;
		} else {
			$doc['layers'][] = $old;
		}
		return array( $doc, $added, $removed );
	}

	/**
	 * Who sees this person's layer. $people must already be limited to people who may see the photo (ZDZ_User_Media does
	 * that); 'project' needs the layer's context. A WP_Error when there is no layer or the choice is not one of SHARES.
	 */
	public static function with_share( array $doc, int $uid, string $share, array $people ) {
		$i = self::layer_index( $doc, $uid );
		if ( $i < 0 ) {
			return new WP_Error( 'zdz_media_marks_none', __( 'Draw on the photo first; then you can share your drawing.', 'zorderz' ) );
		}
		if ( ! in_array( $share, self::SHARES, true ) ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'That is not a way to share a drawing.', 'zorderz' ) );
		}
		if ( 'project' === $share && '' === $doc['layers'][ $i ]['context'] ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'This drawing was not made in a Project.', 'zorderz' ) );
		}
		$people = array_values( array_unique( array_filter( array_map( 'intval', $people ), function ( $p ) use ( $uid ) { return $p > 0 && $p !== $uid; } ) ) );
		if ( 'people' === $share && ! $people ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'Choose at least one person.', 'zorderz' ) );
		}
		if ( count( $people ) > self::MAX_PEOPLE ) {
			return new WP_Error( 'zdz_media_marks_bad', sprintf( __( 'A drawing can be shared with at most %d people.', 'zorderz' ), self::MAX_PEOPLE ) );
		}
		$doc['layers'][ $i ]['share']  = $share;
		$doc['layers'][ $i ]['people'] = 'people' === $share ? $people : array();
		return $doc;
	}

	/**
	 * Turn every point of every layer with the photo when the photo itself is turned clockwise by $cw (90, 180, 270): a
	 * fraction (x, y) of a W x H photo lands at (1 - y, x) of the H x W photo after a quarter turn. The width is a fraction
	 * of the short side, which a turn keeps. Each turned layer's revision moves on.
	 */
	public static function rotate( array $doc, int $cw ): array {
		$cw = ( ( $cw % 360 ) + 360 ) % 360;
		if ( ! in_array( $cw, array( 90, 180, 270 ), true ) ) {
			return $doc;
		}
		$S = self::SCALE;
		foreach ( $doc['layers'] as &$l ) {
			if ( ! $l['strokes'] ) {
				continue;
			}
			foreach ( $l['strokes'] as &$s ) {
				$p = array_map( 'intval', (array) ( $s['p'] ?? array() ) );
				for ( $i = 0; $i + 1 < count( $p ); $i += 2 ) {
					$x = $p[ $i ];
					$y = $p[ $i + 1 ];
					if ( 90 === $cw ) {
						$p[ $i ] = $S - $y; $p[ $i + 1 ] = $x;
					} elseif ( 180 === $cw ) {
						$p[ $i ] = $S - $x; $p[ $i + 1 ] = $S - $y;
					} else {
						$p[ $i ] = $y; $p[ $i + 1 ] = $S - $x;
					}
				}
				$s['p'] = $p;
			}
			unset( $s );
			$l['rev'] = (int) $l['rev'] + 1;
		}
		unset( $l );
		return $doc;
	}

	// ------------------------------------------------------------------------------------------------ who sees what

	public static function is_kiosk( int $uid ): bool {
		if ( $uid <= 0 ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	/** An administrator's own choice to see everyone's drawings (off unless they turned it on). */
	public static function shows_all( int $uid ): bool {
		return $uid > 0 && class_exists( 'ZDZ_User_Media' ) && ZDZ_User_Media::viewer_can_view_others( $uid )
			&& function_exists( 'get_user_meta' ) && '1' === (string) get_user_meta( $uid, self::PREF, true );
	}

	/**
	 * Why $viewer sees this layer: 'mine', 'team', 'people', 'project', 'admin' - or '' when they do not. The caller has
	 * already decided the viewer may see the PHOTO. $all: the viewer is an administrator who chose to see everyone's.
	 */
	public static function audience( array $layer, int $viewer, array $row, bool $all ): string {
		if ( $viewer <= 0 || ! self::reader( $viewer ) ) {
			return '';
		}
		if ( (int) $layer['uid'] === $viewer ) {
			return 'mine';
		}
		if ( 'team' === $layer['share'] ) {
			return 'team';
		}
		if ( self::is_kiosk( $viewer ) ) {
			return ''; // the shared device sees what the whole team sees, nothing chosen for a person
		}
		if ( 'people' === $layer['share'] && in_array( $viewer, $layer['people'], true ) ) {
			return 'people';
		}
		if ( 'project' === $layer['share'] && '' !== $layer['context'] && function_exists( 'apply_filters' ) ) {
			$k = $viewer . '|' . $layer['context'] . '|' . (int) $layer['uid'] . '|' . (int) ( $row['id'] ?? 0 );
			if ( ! array_key_exists( $k, self::$aud ) ) {
				self::$aud[ $k ] = (bool) apply_filters( 'zdz_media_layer_audience', false, $viewer, $layer['context'], array( 'uid' => (int) $layer['uid'], 'context' => $layer['context'] ), $row );
			}
			if ( self::$aud[ $k ] ) {
				return 'project';
			}
		}
		return $all ? 'admin' : '';
	}

	/**
	 * Drawings, notes and who drew them are for the people who use the app (viewer tier and up) and the
	 * shared device - never a bare signed-in account (a subscriber, a customer), even on a team photo it may see.
	 */
	public static function reader( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( ! array_key_exists( $uid, self::$readers ) ) {
			self::$readers[ $uid ] = self::is_kiosk( $uid ) || ( class_exists( 'ZDZ_User_Media' ) && ZDZ_User_Media::uses_app( $uid ) );
		}
		return self::$readers[ $uid ];
	}

	/** Forget the filter's answers (tests; a long request that changed who is on a Project). */
	public static function forget(): void {
		self::$readers = array();
		self::$aud = array();
	}

	/** A colleague whose account has no display name: "Someone at <business>". */
	private static function someone(): string {
		$biz = class_exists( 'ZDZ_Business_Profile' ) ? trim( (string) ZDZ_Business_Profile::name() ) : '';
		/* translators: %s: the business's name */
		return '' !== $biz ? sprintf( __( 'Someone at %s', 'zorderz' ), $biz ) : __( 'Someone on the team', 'zorderz' );
	}

	/** A person as a viewer sees them: name and initials (never an e-mail). */
	public static function person( int $uid ): array {
		static $memo = array();
		if ( isset( $memo[ $uid ] ) ) {
			return $memo[ $uid ];
		}
		$u    = $uid > 0 && function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		$name = $u ? trim( (string) $u->display_name ) : '';
		if ( '' === $name ) {
			$name = $u ? self::someone() : __( 'Someone who has left', 'zorderz' );
		}
		$ini = '';
		foreach ( preg_split( '/\s+/', $name ) as $p ) {
			if ( '' !== $p ) {
				$ini .= strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 ) );
			}
			if ( strlen( $ini ) >= 2 ) {
				break;
			}
		}
		return $memo[ $uid ] = array( 'id' => $uid, 'name' => $name, 'initials' => '' !== $ini ? $ini : '?' );
	}

	/** One stored line as a browser gets it, re-checked (a hand-edited row never sends a shape the kit does not expect). */
	private static function stroke_out( array $s ): ?array {
		$one = self::clean( array( array_intersect_key( $s, array( 'id' => 1, 'c' => 1, 's' => 1, 'p' => 1, 'k' => 1, 'n' => 1 ) ) ) );
		return ( is_wp_error( $one ) || ! $one ) ? null : $one[0];
	}

	/**
	 * What $viewer sees of a photo's drawings: their own layer first, then the rest newest first. $row: the photo (the
	 * caller has decided the viewer may see it). Returns [ layers, hidden_n ] - hidden_n counts, for an administrator who
	 * has NOT chosen to see everyone's, the layers that choice would show.
	 */
	public static function view( $raw, int $viewer, array $row, ?bool $all = null ): array {
		$doc     = self::decode( $raw );
		$is_adm  = $viewer > 0 && class_exists( 'ZDZ_User_Media' ) && ZDZ_User_Media::viewer_can_view_others( $viewer );
		$all     = null === $all ? self::shows_all( $viewer ) : ( $all && $is_adm );
		$out     = array();
		$hidden  = 0;
		foreach ( $doc['layers'] as $l ) {
			if ( ! $l['strokes'] ) {
				continue;
			}
			$why = self::audience( $l, $viewer, $row, $all );
			if ( '' === $why ) {
				if ( $is_adm && ! self::is_kiosk( $viewer ) ) {
					$hidden++;
				}
				continue;
			}
			$strokes = array();
			$notes   = 0;
			foreach ( $l['strokes'] as $s ) {
				$o = self::stroke_out( $s );
				if ( $o ) {
					$strokes[] = $o;
					$notes += isset( $o['n'] ) ? 1 : 0;
				}
			}
			if ( ! $strokes ) {
				continue;
			}
			$who = self::person( (int) $l['uid'] );
			$one = array(
				'key'      => self::layer_key( (int) $l['uid'], (int) ( $row['id'] ?? 0 ) ),
				'mine'     => 'mine' === $why,
				'seen_as'  => $why,
				'name'     => $who['name'],
				'initials' => $who['initials'],
				'at'       => $l['at'],
				'share'    => $l['share'],
				'lines'    => count( $strokes ),
				'notes'    => $notes,
				'strokes'  => $strokes,
			);
			if ( 'mine' === $why ) { // only the drawer sees who else it went to, and their revision to save from
				$one['rev']     = (int) $l['rev'];
				$one['context'] = $l['context'];
				$one['people']  = array_map( array( __CLASS__, 'person' ), $l['people'] );
			}
			$out[] = $one;
		}
		usort( $out, function ( $a, $b ) {
			if ( $a['mine'] !== $b['mine'] ) {
				return $a['mine'] ? -1 : 1;
			}
			return strcmp( (string) $b['at'], (string) $a['at'] );
		} );
		return array( $out, $hidden );
	}

	/** How many drawings $viewer sees on a photo (lists send this, never the lines). */
	public static function count_for( $raw, int $viewer, array $row ): int {
		if ( null === $raw || '' === $raw ) {
			return 0;
		}
		list( $layers ) = self::view( $raw, $viewer, $row );
		return count( $layers );
	}

	/** [ drawings, notes ] this viewer sees on a photo, in one pass (a list's outline and badge). */
	public static function counts_for( $raw, int $viewer, array $row ): array {
		if ( null === $raw || '' === $raw ) {
			return array( 0, 0 );
		}
		// A list row only needs the counts - who sees each layer, and how many notes it holds - not every
		// point checked again (the lines were checked when they were saved; view() checks them before drawing).
		$doc   = self::decode( $raw );
		$all   = self::shows_all( $viewer );
		$n     = 0;
		$notes = 0;
		foreach ( $doc['layers'] as $l ) {
			if ( ! $l['strokes'] || '' === self::audience( $l, $viewer, $row, $all ) ) {
				continue;
			}
			// counted the way view() draws it: a layer with at least one line that has the shape (usually the first),
			// and the notes that have it (at most 20) - re-check, LOW
			$ok = false;
			foreach ( $l['strokes'] as $s ) {
				if ( self::stroke_out( $s ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}
			$n++;
			foreach ( $l['strokes'] as $s ) {
				if ( 'a' === ( $s['k'] ?? '' ) && isset( $s['n'] ) && is_string( $s['n'] ) && '' !== $s['n'] ) {
					$o = self::stroke_out( $s );
					$notes += ( $o && isset( $o['n'] ) ) ? 1 : 0;
				}
			}
		}
		return array( $n, $notes );
	}

	/**
	 * Every note $viewer may read on a photo, for a screen that gathers them (Projects, and chat through it): text, who
	 * wrote it (uid kept for the server to place them on a Project; the browser gets name and initials), when.
	 */
	public static function notes_for( $raw, int $viewer, array $row ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$doc = self::decode( $raw );
		$all = self::shows_all( $viewer );
		$out = array();
		foreach ( $doc['layers'] as $l ) {
			$why = self::audience( $l, $viewer, $row, $all );
			if ( '' === $why ) {
				continue;
			}
			foreach ( $l['strokes'] as $s ) {
				$o = self::stroke_out( $s );
				if ( ! $o || ! isset( $o['n'] ) ) {
					continue;
				}
				$who   = self::person( (int) $l['uid'] );
				$out[] = array(
					'media_id' => (int) ( $row['id'] ?? 0 ),
					'id'       => $o['id'],
					'text'     => $o['n'],
					'by'       => (int) $l['uid'],
					'name'     => $who['name'],
					'initials' => $who['initials'],
					'at'       => $l['at'],
					'mine'     => 'mine' === $why,
					'seen_as'  => $why,
				);
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------------------------------------ REST

	public static function register(): void {
		$id   = array(
			'id' => array(
				'validate_callback' => function ( $v ) {
					return is_numeric( $v ) && (int) $v > 0;
				},
			),
		);
		$auth = function () {
			return is_user_logged_in();
		};
		register_rest_route( ZDZ_REST_NS, '/media/(?P<id>\d+)/marks', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_get' ), 'permission_callback' => $auth, 'args' => $id ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle' ), 'permission_callback' => $auth, 'args' => $id ),
		) );
		register_rest_route( ZDZ_REST_NS, '/media/(?P<id>\d+)/marks/share', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_share' ), 'permission_callback' => $auth, 'args' => $id,
		) );
		register_rest_route( ZDZ_REST_NS, '/media/(?P<id>\d+)/marks/people', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_people' ), 'permission_callback' => $auth, 'args' => $id,
		) );
		register_rest_route( ZDZ_REST_NS, '/media/marks/prefs', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_prefs' ), 'permission_callback' => $auth,
		) );
	}

	private static function params( WP_REST_Request $request ): array {
		$p = $request->get_json_params();
		return is_array( $p ) ? $p : (array) $request->get_params();
	}

	/** The HTTP status for a refusal: 404 missing, 403 not yours, 409 someone saved first, 400 bad shape, 503 not ready. */
	public static function http_status( $code ): int {
		$map = array(
			'zdz_media_missing'       => 404,
			'zdz_media_marks_denied'  => 403,
			'zdz_media_marks_stale'   => 409,
			'zdz_media_marks_busy'    => 409,
			'zdz_media_marks_bad'     => 400,
			'zdz_media_marks_none'    => 400,
			'zdz_media_marks_unready' => 503,
		);
		return $map[ (string) $code ] ?? 500;
	}

	private static function reply( $res ) {
		if ( ! is_wp_error( $res ) ) {
			return rest_ensure_response( $res );
		}
		$status = self::http_status( $res->get_error_code() );
		$data = array( 'status' => $status );
		// A refused save carries the drawings as they are now, so the bar can show them and Save works on the next try
		// without the person reopening the photo.
		$now = $res->get_error_data();
		if ( 409 === $status && is_array( $now ) && isset( $now['view'] ) ) {
			$data['view'] = $now['view'];
		}
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), $data );
	}

	private static function ready() {
		return class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'marks_view' )
			? true
			: new WP_Error( 'zdz_media_unavailable', __( 'Drawing on photos is not available just now.', 'zorderz' ), array( 'status' => 500 ) );
	}

	/** GET /zorderz/v1/media/{id}/marks -> the drawings this person sees on the photo. */
	public static function handle_get( WP_REST_Request $request ) {
		$ok = self::ready();
		if ( true !== $ok ) {
			return $ok;
		}
		return self::reply( ZDZ_User_Media::marks_view( (int) $request['id'], get_current_user_id() ) );
	}

	/** POST /zorderz/v1/media/{id}/marks { rev, strokes, context?, share?, people? } -> save MY drawing. */
	public static function handle( WP_REST_Request $request ) {
		$ok = self::ready();
		if ( true !== $ok ) {
			return $ok;
		}
		$p   = self::params( $request );
		$rev = isset( $p['rev'] ) && is_numeric( $p['rev'] ) ? (int) $p['rev'] : -1;
		// The whole set must be sent; a request without it never means "erase everything".
		if ( ! array_key_exists( 'strokes', $p ) || ! is_array( $p['strokes'] ) ) {
			return new WP_Error( 'zdz_media_marks_bad', __( 'The marks could not be read.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$ctx   = isset( $p['context'] ) && is_string( $p['context'] ) ? $p['context'] : '';
		$share = isset( $p['share'] ) && is_string( $p['share'] ) ? $p['share'] : null;
		$ppl   = isset( $p['people'] ) && is_array( $p['people'] ) ? $p['people'] : array();
		return self::reply( ZDZ_User_Media::save_layer( (int) $request['id'], get_current_user_id(), $p['strokes'], $rev, $ctx, $share, $ppl ) );
	}

	/** POST /zorderz/v1/media/{id}/marks/share { share, people } -> who sees MY drawing. */
	public static function handle_share( WP_REST_Request $request ) {
		$ok = self::ready();
		if ( true !== $ok ) {
			return $ok;
		}
		$p = self::params( $request );
		return self::reply( ZDZ_User_Media::share_layer(
			(int) $request['id'], get_current_user_id(),
			isset( $p['share'] ) && is_string( $p['share'] ) ? $p['share'] : '',
			isset( $p['people'] ) && is_array( $p['people'] ) ? $p['people'] : array()
		) );
	}

	/** GET /zorderz/v1/media/{id}/marks/people -> who a drawing on this photo can be shared with. */
	public static function handle_people( WP_REST_Request $request ) {
		$ok = self::ready();
		if ( true !== $ok ) {
			return $ok;
		}
		return self::reply( ZDZ_User_Media::share_people( (int) $request['id'], get_current_user_id() ) );
	}

	/** POST /zorderz/v1/media/marks/prefs { show_all } - an administrator's own "Show everyone's drawings". */
	public static function handle_prefs( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		if ( ! class_exists( 'ZDZ_User_Media' ) || ! ZDZ_User_Media::viewer_can_view_others( $uid ) || self::is_kiosk( $uid ) ) {
			return new WP_Error( 'zdz_media_marks_denied', __( 'Only an administrator can see everyone\'s drawings.', 'zorderz' ), array( 'status' => 403 ) );
		}
		$p  = self::params( $request );
		$on = ! empty( $p['show_all'] ) && 'false' !== $p['show_all'] && '0' !== (string) $p['show_all'];
		update_user_meta( $uid, self::PREF, $on ? '1' : '0' );
		return rest_ensure_response( array( 'show_all' => $on ) );
	}
}

ZDZ_Media_Marks::init();
