<?php
/**
 * ZTER_Testdata — made-up stubs to try the rules on. Each one goes through the same routing as a real stub,
 * marked as a test, so it never counts in the weekly comparison and can be cleared. Nothing is sent to the
 * estimate app or a billing system: these are inputs to the rules only.
 *
 * The stubs are built from what this site has, never from a list in code: one new and one repair line per
 * Team Roles item (at most twelve items), two items together, a repair that names no item, a location line
 * only, and — for ZIPs — covered ZIPs in turn, one ZIP nobody covers, one stub with no ZIP, one with its ZIP
 * only in the reference, one outside every service zone and one at the first ZIP a no-go zone lists.
 *
 * As won jobs (stage install) each stub's salesperson is who the stub rules would pick for it (worked out
 * without recording anything), and the install rules then suggest its installer.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Testdata {

	const MAX_ITEMS = 12;

	/** [ label, where ('in' | 'out' | 'none' | 'ref' | 'refer' | 'nogo'), lines[ [name, desc] ] ] */
	public static function stubs() {
		$out   = array();
		$items = array_slice( ZTER_Kinds::item_list(), 0, self::MAX_ITEMS );
		foreach ( $items as $it ) {
			$out[] = array( $it['label'] . ', new', 'in', array( array( $it['label'], 'New install' ) ) );
			$out[] = array( $it['label'] . ', repair', 'in', array( array( $it['label'] . ' repair', 'Repair' ) ) );
		}
		if ( count( $items ) >= 2 ) {
			$out[] = array( 'two items together', 'in', array( array( $items[0]['label'], 'New install' ), array( $items[1]['label'], 'New install' ) ) );
		}
		$first = $items ? $items[0]['label'] : 'Work';
		$out[] = array( 'a repair that names no item', 'in', array( array( 'Repair', 'Fix it' ) ) );
		$out[] = array( 'a location line only', 'in', array( array( 'Location', '1 Example Way (XX)' ) ) );
		$out[] = array( 'ZIP only in the reference', 'ref', array( array( $first, '' ) ) );
		$out[] = array( 'no ZIP', 'none', array( array( $first, '' ) ) );
		$out[] = array( 'a ZIP nobody covers', 'out', array( array( $first, 'New install' ) ) );
		$out[] = array( 'outside every service area', 'refer', array( array( self::served_item_label(), 'New install' ) ) );
		$out[] = array( 'in a no-go ZIP', 'nogo', array( array( $first, 'New install' ) ) );
		return $out;
	}

	/** The label of the first item a service zone names (so "outside every service area" can happen), else the first item's. */
	private static function served_item_label() {
		$items = ZTER_Kinds::items();
		foreach ( ZTER_Zones::all()['rows'] as $z ) {
			if ( 'service' === $z['kind'] ) {
				foreach ( $z['abilities'] as $k ) {
					if ( isset( $items[ $k ] ) && empty( $items[ $k ]['retired'] ) ) {
						return $items[ $k ]['label'];
					}
				}
			}
		}
		$list = ZTER_Kinds::item_list();
		return $list ? $list[0]['label'] : 'Work';
	}

	/** A ZIP outside every service zone (and every territory), and the first five-digit ZIP a no-go zone lists. */
	public static function zone_zips( array $covered ) {
		$taken = $covered;
		$nogo  = '';
		foreach ( ZTER_Zones::all()['rows'] as $z ) {
			foreach ( $z['zips'] as $q ) {
				$taken[ $q ] = true;
				if ( '' === $nogo && 'nogo' === $z['kind'] && empty( $z['outside'] ) ) {
					$nogo = $q;
				}
			}
		}
		return array( self::uncovered_zip( $taken ), $nogo );
	}

	/** A five-digit ZIP nobody covers: the next one up from a covered ZIP (no place is named in code), or '99999'. */
	public static function uncovered_zip( array $covered ) {
		$keys = array_map( 'strval', array_keys( $covered ) );
		sort( $keys, SORT_STRING );
		foreach ( $keys as $z ) {
			for ( $n = (int) $z + 1; $n <= 99999 && $n <= (int) $z + 200; $n++ ) {
				$c = str_pad( (string) $n, 5, '0', STR_PAD_LEFT );
				if ( ! isset( $covered[ $c ] ) ) {
					return $c;
				}
			}
		}
		return '99999';
	}

	/** Run every stub through the routing as $by, as new stubs or (stage install) as won jobs. One row per stub. */
	public static function run( $by, $stage = 'stub' ) {
		$stage   = ZTER_Rules::stage( $stage );
		$covered = array();
		foreach ( (array) ZTER_DB::all_zips() as $zips ) {
			foreach ( $zips as $z ) {
				$covered[ $z ] = true;
			}
		}
		$in = array_map( 'strval', array_keys( $covered ) );
		sort( $in, SORT_STRING );
		$out = self::uncovered_zip( $covered );
		list( $far, $nogo ) = self::zone_zips( $covered );
		$i   = 0;
		$res = array();
		foreach ( self::stubs() as $n => $s ) {
			list( $label, $where, $lines ) = $s;
			$zip = '';
			$ref = '';
			if ( 'in' === $where ) {
				$zip = $in ? $in[ $i++ % count( $in ) ] : $out;
			} elseif ( 'out' === $where ) {
				$zip = $out;
			} elseif ( 'ref' === $where ) {
				$ref = ( $in ? $in[ $i++ % count( $in ) ] : $out ) . '-TEST';
			} elseif ( 'refer' === $where ) {
				$zip = $far;
			} elseif ( 'nogo' === $where ) {
				$zip = '' !== $nogo ? $nogo : $out;
			}
			$lab = sprintf( 'Test stub %02d: %s', $n + 1, $label );
			$ln  = array_map( function ( $l ) { return array( 'name' => $l[0], 'desc' => $l[1] ); }, $lines );
			$ask = array(
				'zip' => $zip, 'reference' => $ref, 'lines' => $ln, 'default_initials' => '', 'test' => true,
				'label' => $lab, 'asker_id' => (int) $by, 'pairs' => true,
			);
			if ( 'install' === $stage ) {
				// the salesperson: who the stub rules would pick, worked out without recording it or taking a turn
				list( , $sold ) = ZTER_Rules::route( $ask + array( 'dry' => true ) );
				$ask = array( 'stage' => 'install', 'seller_user_id' => (int) $sold['chosen_user_id'], 'pairs' => true ) + $ask;
			}
			list( , $rec ) = ZTER_Rules::route( $ask );
			$res[] = array( 'label' => $lab, 'decision_id' => (int) ( $rec['id'] ?? 0 ) );
		}
		return $res;
	}
}
