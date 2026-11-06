<?php
/**
 * ZTER_Zones — named sets of ZIP codes that are not a person's territory. The zones are the business's
 * data; Core ships none.
 *
 *   kind 'service'  where a kind of work is offered: Team Roles items (`abilities`) + the ZIPs, or everywhere
 *   kind 'area'     a named area for the business itself
 *   kind 'nogo'     the places a person is never given work: names `people` (user ids) and is the admin
 *                   tier's alone (visible is always 'admin'); it is left out of territories.zones and
 *                   territories.for_zip, so a staff asker learns nothing of it: asked for, a hidden zone and
 *                   a missing zone answer the same (an empty list). Only a no-go zone may also hold:
 *                     prefixes  three-digit ZIP prefixes: every ZIP that starts with one
 *                     outside   true: every ZIP EXCEPT the ones listed (and the prefixes)
 *                     nozip     true: a job with no five-digit ZIP at all is in the zone too
 *
 *   visible 'staff' every staff asker (sales, the operator, the shop) sees it; 'admin' only the admin tier
 *
 * Stored in one option (autoload off), `zter_zones`: { version, rows: [ zone, … ] }. Every save is a History
 * row and raises the generation. Colours are the platform's person tones (ZDZ_Person_Color), so a zone never
 * brings a colour of its own. No zone, ZIP or person is written in this file: zones are entered in the app
 * (or through the zones route by the admin tier).
 *
 * The install rules read the service zones (a rule's "only where we offer that work"); the stub rules don't:
 * a stub is sold wherever it comes from. contains() reads a zone; excluded() says whether a person is in a
 * no-go zone at a ZIP, and the rules drop that person from every pool, at both stages.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Zones {

	const OPTION    = 'zter_zones';
	const MAX_ZONES = 30;
	const MAX_ZIPS  = 800;
	const KINDS     = array( 'service', 'area', 'nogo' );   // nogo
	const MAX_PEOPLE = 10;
	const VISIBLE   = array( 'staff', 'admin' );

	/** Every zone, cleaned. */
	/** The cleaned list once per stored value per request; the rules ask for it on every person. */
	private static $memo = array( '', null );

	public static function all() {
		$o = get_option( self::OPTION, array() );
		$ab = ZTER_Kinds::abilities();   // a zone is cleaned against Team Roles and the theme's tones: both in the key
		$k  = md5( serialize( $o ) . '|' . count( self::tones() ) . '|' . ( null === $ab ? 'n' : md5( implode( ',', array_keys( (array) $ab ) ) ) ) );
		if ( $k === self::$memo[0] && is_array( self::$memo[1] ) ) {
			return self::$memo[1];
		}
		$res = self::read_all( $o );
		self::$memo = array( $k, $res );
		return $res;
	}

	private static function read_all( $o ) {
		$rows = array();
		$ids  = array();
		foreach ( array_values( (array) ( is_array( $o ) ? ( $o['rows'] ?? array() ) : array() ) ) as $i => $r ) {
			$c = self::clean( $r, $i );
			if ( ! is_wp_error( $c ) ) {
				$c['id'] = self::unique_id( $c['id'], $ids );   // a stored list never holds one id twice
				$rows[] = $c;
			}
		}
		return array( 'version' => (int) ( is_array( $o ) ? ( $o['version'] ?? 0 ) : 0 ), 'rows' => $rows );
	}

	/**
	 * The zones this person may see: the admin tier every zone, staff the 'staff' ones, anyone else none.
	 * No-go zones only when $nogo is asked for, and only ever for the admin tier (Territories' own page asks; the
	 * verbs other apps read do not, so no map ever draws one).
	 */
	public static function visible_to( $admin, $staff = true, $nogo = false ) {
		if ( ! $admin && ! $staff ) {
			return array();
		}
		$ink = class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'ink' );
		$out = array();
		foreach ( self::all()['rows'] as $z ) {
			if ( 'nogo' === $z['kind'] && ! ( $admin && $nogo ) ) {
				continue;
			}
			if ( $admin || 'staff' === $z['visible'] ) {
				$z['ink'] = $ink && '' !== $z['color'] ? (string) ZDZ_Person_Color::ink( $z['color'] ) : '';   // the text on its badge
				$out[] = $z;
			}
		}
		return $out;
	}

	/** The zones (that this person may see) a ZIP is in, as {id, label, short, kind, color, everywhere}. */
	public static function for_zip( $zip, $admin, $staff = true ) {
		$zip = ZTER_DB::clean_zip( $zip );
		if ( '' === $zip ) {
			return array();
		}
		$out = array();
		foreach ( self::visible_to( $admin, $staff ) as $z ) {
			if ( self::contains( $z, $zip ) ) {
				$out[] = array( 'id' => $z['id'], 'label' => $z['label'], 'short' => $z['short'], 'kind' => $z['kind'],
					'color' => $z['color'], 'everywhere' => $z['everywhere'] );
			}
		}
		return $out;
	}

	/**
	 * is this ZIP in the zone? '' (no US ZIP) is in a zone only when it says so (`nozip`); a service or area zone
	 * never holds it. Everywhere, the ZIP list, the prefixes, and `outside` (every ZIP except those).
	 */
	public static function contains( array $z, $zip ) {
		$zip = ZTER_DB::clean_zip( (string) $zip );
		if ( '' === $zip ) {
			return ! empty( $z['nozip'] );
		}
		if ( ! empty( $z['everywhere'] ) ) {
			return true;
		}
		$in = in_array( $zip, (array) ( $z['zips'] ?? array() ), true );
		foreach ( (array) ( $z['prefixes'] ?? array() ) as $p ) {
			$in = $in || 0 === strpos( $zip, (string) $p );
		}
		return ! empty( $z['outside'] ) ? ! $in : $in;
	}

	/**
	 * the no-go zones that leave this person out at this ZIP (their ids; empty = none). Read by the rules only;
	 * never shown outside the admin tier.
	 */
	public static function excluding( $uid, $zip ) {
		$uid = (int) $uid;
		$out = array();
		if ( $uid <= 0 ) {
			return $out;
		}
		foreach ( self::all()['rows'] as $z ) {
			if ( 'nogo' === $z['kind'] && in_array( $uid, $z['people'], true ) && self::contains( $z, $zip ) ) {
				$out[] = $z['id'];
			}
		}
		return $out;
	}

	/** Is there any no-go zone at all? (A rule's sentence mentions them only when there are.) */
	public static function any_nogo() {
		foreach ( self::all()['rows'] as $z ) {
			if ( 'nogo' === $z['kind'] ) {
				return true;
			}
		}
		return false;
	}

	public static function excluded( $uid, $zip ) {
		return (bool) self::excluding( $uid, $zip );
	}

	/** $id, or $id_2, _3 ... when the list already has it; records it in $ids. */
	private static function unique_id( $id, array &$ids ) {
		$base = $id;
		$n    = 2;
		while ( isset( $ids[ $id ] ) ) {
			$id = rtrim( substr( $base, 0, 21 ), '_' ) . '_' . $n++;
		}
		$ids[ $id ] = true;
		return $id;
	}

	/** ZIPs as text, or a list with only its strings and numbers kept. */
	private static function zip_items( $z ) {
		return is_array( $z ) ? array_values( array_filter( $z, 'is_scalar' ) ) : self::str( $z );
	}

	/** A text field as a string ('' for an array or object, so odd input never warns). */
	private static function str( $v ) {
		return is_scalar( $v ) ? (string) $v : '';
	}

	/** The theme's tones, in the order they are handed out (empty without the theme). */
	public static function tones() {
		if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'palette' ) ) {
			return array_values( (array) ZDZ_Person_Color::palette() );
		}
		return array();
	}

	/** One zone, cleaned: a WP_Error says what is wrong in words the page can show. $i: its place in the list. */
	public static function clean( $r, $i = 0 ) {
		if ( ! is_array( $r ) ) {
			return new WP_Error( 'zter_zone', __( 'A zone must be an object.', 'zorderz' ) );
		}
		$label = trim( mb_substr( sanitize_text_field( self::str( $r['label'] ?? '' ) ), 0, 40 ) );
		if ( '' === $label ) {
			return new WP_Error( 'zter_zone', __( 'Every zone needs a name.', 'zorderz' ) );
		}
		// trimmed after the cut, so a stored id never ends in "_" (read back, it would become another zone's id)
		$id = trim( substr( trim( strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', self::str( $r['id'] ?? '' ) ) ), '_' ), 0, 24 ), '_' );
		if ( '' === $id ) {
			$id = trim( substr( trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '_', $label ) ), '_' ), 0, 24 ), '_' );
		}
		$id    = '' !== $id ? $id : 'zone';
		$short = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', self::str( $r['short'] ?? '' ) ) );
		$short = substr( '' !== $short ? $short : strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $label ), 0, 2 ) ), 0, 4 );
		$short = '' !== $short ? $short : 'Z' . ( ( (int) $i ) + 1 );   // a name with no Latin letters
		$kind  = in_array( self::str( $r['kind'] ?? '' ), self::KINDS, true ) ? $r['kind'] : 'service';
		$nogo  = 'nogo' === $kind;
		// fail safe: only the word "staff" shows a zone to staff; anything else is the admin tier's alone.
		// a no-go zone is always the admin tier's alone.
		$vis   = ! $nogo && 'staff' === strtolower( trim( self::str( $r['visible'] ?? '' ) ) ) ? 'staff' : 'admin';
		$every = ! $nogo && self::bool( $r['everywhere'] ?? false );   // never "nowhere for this person"
		$out_  = $nogo && self::bool( $r['outside'] ?? false );
		$nozip = $nogo && self::bool( $r['nozip'] ?? false );
		// an everywhere zone keeps no ZIPs, so what is left in the (disabled) box is not read
		$pre = array();
		$raw = $every ? array() : self::zip_items( $r['zips'] ?? array() );
		if ( $nogo ) {
			// three digits are a prefix ("919": every ZIP starting 919), in the same box as the ZIPs
			$items = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
			$items = array_merge( (array) $items, is_array( $r['prefixes'] ?? null ) ? array_values( array_filter( $r['prefixes'], 'is_scalar' ) ) : array() );
			$raw   = array();
			foreach ( $items as $it ) {
				$it = trim( (string) $it );
				if ( preg_match( '/^\d{3}$/', $it ) ) {
					$pre[ $it ] = true;
				} elseif ( '' !== $it ) {
					$raw[] = $it;
				}
			}
			$pre = array_map( 'strval', array_keys( $pre ) );
			sort( $pre, SORT_STRING );
		}
		list( $ok, $bad ) = $every ? array( array(), array() ) : ZTER_DB::parse_zips( $raw );
		if ( $bad ) {
			return new WP_Error( 'zter_zone', sprintf( __( '%s: these are not %s: %s.', 'zorderz' ), $label, $nogo ? 'five-digit ZIP codes or three-digit ZIP prefixes' : 'five-digit ZIP codes', implode( ', ', array_slice( $bad, 0, 8 ) ) ) );
		}
		if ( count( $ok ) + count( $pre ) > self::MAX_ZIPS ) {
			return new WP_Error( 'zter_zone', sprintf( __( '%s: at most %d ZIP codes.', 'zorderz' ), $label, self::MAX_ZIPS ) );
		}
		if ( $nogo ) {
			if ( $out_ && ! $ok && ! $pre ) {
				return new WP_Error( 'zter_zone', sprintf( __( '%s: "every ZIP except these" needs the ZIP codes (or prefixes) that stay in.', 'zorderz' ), $label ) );
			}
			if ( ! $ok && ! $pre && ! $nozip ) {
				return new WP_Error( 'zter_zone', sprintf( __( '%s: give it ZIP codes or prefixes, or tick "a job with no ZIP".', 'zorderz' ), $label ) );
			}
		} elseif ( ! $every && ! $ok ) {
			return new WP_Error( 'zter_zone', sprintf( __( '%s: give it ZIP codes, or mark it "everywhere".', 'zorderz' ), $label ) );
		}
		$people = array();
		if ( $nogo ) {
			foreach ( (array) ( $r['people'] ?? array() ) as $p ) {
				$p = is_scalar( $p ) ? (int) $p : 0;
				if ( $p > 0 && ! in_array( $p, $people, true ) ) {
					$people[] = $p;
				}
			}
			$people = array_slice( $people, 0, self::MAX_PEOPLE );
			if ( ! $people ) {
				return new WP_Error( 'zter_zone', sprintf( __( '%s: a no-go zone needs the person (or people) it keeps out.', 'zorderz' ), $label ) );
			}
		}
		$abs = array();
		if ( 'service' === $kind ) {
			$known = ZTER_Kinds::abilities();
			foreach ( (array) ( $r['abilities'] ?? array() ) as $k ) {
				$k = sanitize_key( self::str( $k ) );
				if ( '' !== $k && ( null === $known || isset( $known[ $k ] ) ) && ! in_array( $k, $abs, true ) ) {
					$abs[] = $k;
				}
			}
		}
		$tones = self::tones();
		$color = strtoupper( self::str( $r['color'] ?? '' ) );
		if ( ! in_array( $color, $tones, true ) ) {
			$color = $tones ? $tones[ ( (int) $i ) % count( $tones ) ] : '';
		}
		$row = array(
			'id' => $id, 'label' => $label, 'short' => $short, 'kind' => $kind, 'abilities' => $abs,
			'everywhere' => $every, 'zips' => $every ? array() : $ok, 'visible' => $vis, 'color' => $color,
		);
		if ( $nogo ) {
			// only a no-go zone carries these, so a service or area zone keeps its shape for the map verbs
			$row += array( 'people' => $people, 'prefixes' => $pre, 'outside' => $out_, 'nozip' => $nozip );
		}
		return $row;
	}

	private static function bool( $v ) {
		return is_bool( $v ) ? $v : (bool) filter_var( self::str( $v ), FILTER_VALIDATE_BOOLEAN );
	}

	/** Save the whole list (the admin tier). Returns the new list, or a WP_Error that names the problem. */
	public static function save( $rows, $by, $expect = null ) {
		if ( null !== $expect && (int) $expect !== self::all()['version'] ) {
			// the whole list is replaced, so a page opened before someone else's change must not undo it
			return new WP_Error( 'zter_zone_stale', __( 'The zones changed since this page opened. Reload, then make your change again.', 'zorderz' ) );
		}
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'zter_zone', __( 'No zones were sent.', 'zorderz' ) );
		}
		if ( count( $rows ) > self::MAX_ZONES ) {
			return new WP_Error( 'zter_zone', sprintf( __( 'At most %d zones.', 'zorderz' ), self::MAX_ZONES ) );
		}
		$out    = array();
		$ids    = array();
		$shorts = array();
		foreach ( array_values( $rows ) as $i => $r ) {
			$c = self::clean( $r, $i );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			if ( 'nogo' === $c['kind'] ) {
				// only people on the team (never the shared kiosk); a person who has left keeps no zone
				foreach ( $c['people'] as $p ) {
					if ( ! zter_is_team_member( $p ) ) {
						return new WP_Error( 'zter_zone', sprintf( __( '%s: pick people on the team.', 'zorderz' ), $c['label'] ) );
					}
				}
			}
			$c['id'] = self::unique_id( $c['id'], $ids );
			// the map tells zones apart by short name, so two zones never share one (AB, AB2 ...)
			$base = $c['short'];
			$n    = 2;
			while ( isset( $shorts[ $c['short'] ] ) ) {
				$c['short'] = substr( $base, 0, 4 - strlen( (string) $n ) ) . $n++;
			}
			$shorts[ $c['short'] ] = true;
			$out[] = $c;
		}
		$old = self::all();
		if ( $old['rows'] === $out ) {
			return $old;
		}
		$new = array( 'version' => $old['version'] + 1, 'rows' => $out, 'updated_by' => (int) $by, 'updated_at' => ZTER_DB::now() );
		update_option( self::OPTION, $new, false );
		$was = array();
		foreach ( $old['rows'] as $z ) {
			$was[ $z['id'] ] = $z;
		}
		$summary = array();
		foreach ( $out as $z ) {
			$p = $was[ $z['id'] ] ?? null;
			unset( $was[ $z['id'] ] );
			if ( ! $p ) {
				$summary[] = array( 'zone' => $z['label'], 'did' => 'added', 'zips' => $z['everywhere'] ? 'everywhere' : count( $z['zips'] ) + count( $z['prefixes'] ?? array() ) )
					+ ( isset( $z['people'] ) ? array( 'people' => array_values( array_map( 'zter_initials', $z['people'] ) ) ) : array() );
			} elseif ( $p !== $z ) {
				$zl = array_merge( $z['zips'], (array) ( $z['prefixes'] ?? array() ) );
				$pl = array_merge( $p['zips'], (array) ( $p['prefixes'] ?? array() ) );
				$row = array( 'zone' => $z['label'], 'did' => 'changed',
					'added' => array_values( array_diff( $zl, $pl ) ), 'removed' => array_values( array_diff( $pl, $zl ) ) );
				// who a no-go zone keeps out, by initials (History is the admin tier's)
				$zp = (array) ( $z['people'] ?? array() );
				$pp = (array) ( $p['people'] ?? array() );
				if ( $zp !== $pp ) {
					$row['people_added']   = array_values( array_map( 'zter_initials', array_diff( $zp, $pp ) ) );
					$row['people_removed'] = array_values( array_map( 'zter_initials', array_diff( $pp, $zp ) ) );
				}
				$summary[] = $row;
			}
		}
		foreach ( $was as $z ) {
			$summary[] = array( 'zone' => $z['label'], 'did' => 'removed' );
		}
		ZTER_DB::log( $by, 0, 'zones', array( 'version' => $new['version'], 'changes' => $summary ) );
		ZTER_DB::bump();
		return array( 'version' => $new['version'], 'rows' => $out );
	}
}
