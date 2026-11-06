<?php
/**
 * ZRP_Verb -- routeplanner.street_tags: what the survey found on these streets, for other apps.
 *
 * Only tags a PERSON confirmed are counted; a suggestion nobody confirmed is never an answer. Other apps (a ranker,
 * later the planner's own planning, the chat) read the survey only through this verb -- never the planner's tables.
 *
 *   Input (one of):  streets   [{street | street_key, zip}] (at most 50; zip may be '' for every ZIP)
 *                    envelope  {xmin, ymin, xmax, ymax} (lng/lat, at most ~11 x 11 km)
 *   Output, by who asks:
 *     | who asks                              | gets                                                                  |
 *     |---------------------------------------|-----------------------------------------------------------------------|
 *     | anyone signed in the verb allows      | per street (street, key, ZIP, town, centre): notes counted, each tag's|
 *     |                                       | count by scope, its level (seen / block / street / neighborhood) and  |
 *     |                                       | product, counts per road segment, the last day noted, tags `likely`   |
 *     |                                       | there by association; `areas`: corroborated statements and   |
 *     |                                       | hard-to-see features' likely surroundings -- streets and counts only      |
 *     | + someone who holds the Route Planner | the notes as said (up to 20 a street, newest first): words, scope,    |
 *     |                                       | side, tags, day -- never who said them                                 |
 *     | the shared kiosk                      | nothing                                                                |
 *     | anyone                                | never a person, a plate, a phone; a house number never (the said text |
 *     |                                       | keeps one only for "this house" notes; only holders get the text, and |
 *     |                                       | its digits only if Doorhangers lets them see house numbers)           |
 *   Tier viewer, never the kiosk, the Route Planner not needed (needs_app false: a ranker's users need the counts).
 *   A failed read is an error, never an empty success.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Verb {

	const VERB        = 'routeplanner.street_tags';
	const MAX_STREETS = 50;
	const SAID_EACH   = 20;

	/** How widely a note speaks, by its scope: seen (a home) < block < street < neighborhood. */
	const LEVEL = array( 'seen' => 1, 'block' => 2, 'street' => 3, 'neighborhood' => 4 );
	const CORROBORATE_M = 800;   // a broad statement on a second street this near corroborates the first
	const LIKELY_M      = 500;   // one hard-to-see feature seen: likely more within this distance

	public static function level_of( $scope ) {
		$m = array( 'here' => 'seen', 'house' => 'seen', 'side' => 'seen', 'segment' => 'block', 'block' => 'block', 'street' => 'street', 'neighborhood' => 'neighborhood' );
		return $m[ $scope ] ?? 'seen';
	}

	private static function mean( array $pts ) {
		if ( ! $pts ) { return null; }
		$la = 0.0; $ln = 0.0;
		foreach ( $pts as $p ) { $la += $p[0]; $ln += $p[1]; }
		return array( round( $la / count( $pts ), 6 ), round( $ln / count( $pts ), 6 ) );
	}

	/** Confirmed notes within ~1 km of the asked streets' notes (for areas only): streets, ZIPs, scopes, tags, points. */
	private static function near_notes( array $out ) {
		$pts = array();
		foreach ( $out as $st ) { foreach ( $st['_pts'] as $p ) { $pts[] = $p; } }
		if ( ! $pts ) { return array(); }
		$la = array_column( $pts, 0 ); $ln = array_column( $pts, 1 );
		$box = array( min( $ln ) - 0.012, min( $la ) - 0.01, max( $ln ) + 0.012, max( $la ) + 0.01 );
		$got = ZRP_DB::confirmed_on( array(), $box, 2000 );
		return is_array( $got ) ? $got['notes'] : array();
	}

	/**
	 * Areas from streets (those answered, plus nearby notes). Per tag:
	 *   stated  streets where it was said for the whole street or the neighborhood; streets within CORROBORATE_M of
	 *           each other join (single link). Two or more streets = corroborated. One street said "the whole
	 *           neighborhood" = a claim waiting for a second street (corroborated false).
	 *   likely  a hard-to-see tag (flag hard_to_see) seen on any street: likely around it.
	 * Each: {tag, label, product, kind, corroborated, streets [{street, zip}], center, radius_m, reason}.
	 */
	public static function areas( array $out, array $near_rows, array $vocab ) {
		$by = array(); // key => {street, zip, pts, tags: {tag: level}}
		$add = function ( $key, $street, $zip, $pt, $tag, $level ) use ( &$by ) {
			if ( ! isset( $by[ $key ] ) ) { $by[ $key ] = array( 'street' => $street, 'zip' => $zip, 'pts' => array(), 'tags' => array() ); }
			if ( $pt ) { $by[ $key ]['pts'][] = $pt; }
			$cur = $by[ $key ]['tags'][ $tag ] ?? 'seen';
			$by[ $key ]['tags'][ $tag ] = ZRP_Verb::LEVEL[ $level ] > ZRP_Verb::LEVEL[ $cur ] ? $level : $cur;
		};
		foreach ( $out as $k => $st ) {
			foreach ( $st['tags'] as $t => $info ) {
				foreach ( $st['_pts'] ?: array( null ) as $pt ) { $add( $k, $st['street'], $st['zip'], $pt, $t, $info['level'] ); }
			}
		}
		foreach ( $near_rows as $r ) {
			if ( '' === (string) $r['street_key'] ) { continue; }
			$pt = ( null !== $r['lat'] && null !== $r['lng'] ) ? array( (float) $r['lat'], (float) $r['lng'] ) : null;
			foreach ( $r['tags'] as $t ) { $add( $r['street_key'] . '|' . $r['zip'], (string) $r['street'], (string) $r['zip'], $pt, $t, self::level_of( $r['scope'] ) ); }
		}
		foreach ( $by as &$b ) { $b['center'] = self::mean( $b['pts'] ); }
		unset( $b );
		$areas = array();
		$tags  = array();
		foreach ( $by as $b ) { foreach ( $b['tags'] as $t => $lv ) { $tags[ $t ] = true; } }
		foreach ( array_keys( $tags ) as $t ) {
			$label   = (string) ( $vocab[ $t ]['label'] ?? $t );
			$product = (string) ( $vocab[ $t ]['product'] ?? '' );
			// stated: whole-street / neighborhood statements, linked by distance
			$seeds = array();
			foreach ( $by as $key => $b ) {
				if ( isset( $b['tags'][ $t ] ) && self::LEVEL[ $b['tags'][ $t ] ] >= self::LEVEL['street'] && $b['center'] ) { $seeds[ $key ] = $b; }
			}
			$left = array_keys( $seeds );
			while ( $left ) {
				$group = array( array_shift( $left ) );
				for ( $i = 0; $i < count( $group ); $i++ ) {
					foreach ( $left as $j => $other ) {
						$a = $seeds[ $group[ $i ] ]['center']; $c = $seeds[ $other ]['center'];
						if ( ZRP_DB::dist_m( $a[0], $a[1], $c[0], $c[1] ) <= self::CORROBORATE_M ) { $group[] = $other; unset( $left[ $j ] ); }
					}
					$left = array_values( $left );
				}
				$members = array_map( function ( $k ) use ( $seeds ) { return $seeds[ $k ]; }, $group );
				$corr    = count( $members ) >= 2;
				$neigh   = (bool) array_filter( $members, function ( $m ) use ( $t ) { return 'neighborhood' === $m['tags'][ $t ]; } );
				if ( ! $corr && ! $neigh ) { continue; } // one street said for itself: the street's own level says it
				$center = self::mean( array_map( function ( $m ) { return $m['center']; }, $members ) );
				$r = 0.0;
				foreach ( $members as $m ) { $r = max( $r, ZRP_DB::dist_m( $center[0], $center[1], $m['center'][0], $m['center'][1] ) ); }
				$areas[] = array( 'tag' => $t, 'label' => $label, 'product' => $product, 'kind' => 'stated', 'corroborated' => $corr,
					'streets' => array_map( function ( $m ) { return array( 'street' => $m['street'], 'zip' => $m['zip'] ); }, $members ),
					'center' => $center, 'radius_m' => (int) round( $r + 300 ),
					'reason' => $corr ? 'said for the whole street on ' . count( $members ) . ' nearby streets' : 'said for the whole neighborhood on one street; a second nearby street would confirm it' );
			}
			// likely: hard to see from the street, so one home seen speaks for the homes around it
			if ( in_array( 'hard_to_see', (array) ( $vocab[ $t ]['flags'] ?? array() ), true ) ) {
				foreach ( $by as $b ) {
					if ( ! isset( $b['tags'][ $t ] ) || ! $b['center'] ) { continue; }
					$areas[] = array( 'tag' => $t, 'label' => $label, 'product' => $product, 'kind' => 'likely', 'corroborated' => false,
						'streets' => array( array( 'street' => $b['street'], 'zip' => $b['zip'] ) ), 'center' => $b['center'], 'radius_m' => self::LIKELY_M,
						'reason' => 'seen on ' . $b['street'] . '; hard to see from the street, so likely on nearby homes too' );
				}
			}
		}
		return $areas;
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'routeplanner',
			'app'           => ZRP_APP_ID,
			'tier'          => 'viewer',
			'needs_app'     => false,
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'What drive-by surveys noted on these streets: confirmed tags counted per street and '
				. 'road segment, with their product lines; the notes as said only for holders of the Route Planner. Never a person; '
				. 'never the shared device.',
		);
	}

	private static function no( $why, $msg, $denied = false ) {
		$o = array( 'success' => false, 'verb' => self::VERB, 'message' => $msg );
		if ( $denied ) { $o['denied'] = true; $o['reason'] = $why; } else { $o['error'] = $why; }
		return $o;
	}

	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || zrp_is_kiosk( $uid ) ) {
			return self::no( 'kiosk', 'The Route Planner is not available on the shared device.', true );
		}
		if ( $uid <= 0 ) {
			return self::no( 'signed_out', 'Sign in first.', true );
		}
		$holder  = zrp_user_can_use( $uid );
		$streets = array();
		foreach ( array_slice( (array) ( $args['streets'] ?? array() ), 0, self::MAX_STREETS + 1 ) as $s ) {
			if ( is_string( $s ) ) {
				$s = array( 'street' => $s );
			}
			if ( ! is_array( $s ) ) {
				continue;
			}
			$key = isset( $s['street_key'] ) && is_scalar( $s['street_key'] ) ? strtolower( trim( (string) $s['street_key'] ) )
				: ( isset( $s['street'] ) && is_scalar( $s['street'] ) ? zrp_street_key( (string) $s['street'] ) : '' );
			$key = mb_substr( $key, 0, 120 );
			$zip = substr( preg_replace( '/[^0-9]/', '', (string) ( $s['zip'] ?? '' ) ), 0, 5 );
			if ( '' !== $key ) {
				$streets[ $key . '|' . $zip ] = array( $key, $zip );
			}
		}
		if ( count( $streets ) > self::MAX_STREETS ) {
			return self::no( 'too_many_streets', 'At most ' . self::MAX_STREETS . ' streets at a time.' );
		}
		$box = null;
		$e   = $args['envelope'] ?? null;
		if ( is_array( $e ) && isset( $e['xmin'], $e['ymin'], $e['xmax'], $e['ymax'] ) && is_numeric( $e['xmin'] ) && is_numeric( $e['ymin'] ) && is_numeric( $e['xmax'] ) && is_numeric( $e['ymax'] ) ) {
			$box = array( (float) $e['xmin'], (float) $e['ymin'], (float) $e['xmax'], (float) $e['ymax'] );
			if ( $box[2] <= $box[0] || $box[3] <= $box[1] ) {
				return self::no( 'bad_area', 'That area could not be read.' );
			}
			if ( $box[2] - $box[0] > 0.12 || $box[3] - $box[1] > 0.1 ) {
				return self::no( 'too_big', 'That area is too large. Ask for a smaller one.' );
			}
		}
		if ( ! $streets && ! $box ) {
			return self::no( 'no_area', 'Give streets or an area.' );
		}
		$got = ZRP_DB::confirmed_on( array_values( $streets ), $box );
		if ( null === $got ) {
			return self::no( 'db', 'The survey notes could not be read just now.' );
		}
		$rows = $got['notes'];
		$vocab = ZRP_Vocab::vocabulary();
		$out   = array();
		foreach ( $rows as $r ) {
			$k = (string) $r['street_key'] . '|' . (string) $r['zip'];
			if ( '' === (string) $r['street_key'] ) {
				continue; // a note not snapped to a street is the person's to fix on the Review list, not a street's
			}
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'street' => (string) $r['street'], 'street_key' => (string) $r['street_key'], 'zip' => (string) $r['zip'],
					'city' => (string) $r['city'], 'notes' => 0, 'tags' => array(), 'segments' => array(), 'last_noted' => '', 'likely' => array(), '_pts' => array() );
				if ( $holder ) {
					$out[ $k ]['said'] = array();
				}
			}
			$st = &$out[ $k ];
			$st['notes']++;
			if ( null !== $r['lat'] && null !== $r['lng'] ) { $st['_pts'][] = array( (float) $r['lat'], (float) $r['lng'] ); }
			$day = substr( (string) $r['created_at'], 0, 10 );
			if ( $day > $st['last_noted'] ) {
				$st['last_noted'] = $day;
			}
			$scope = in_array( $r['scope'], ZRP_Vocab::SCOPES, true ) ? $r['scope'] : 'here';
			foreach ( $r['tags'] as $t ) {
				if ( ! isset( $st['tags'][ $t ] ) ) {
					$st['tags'][ $t ] = array( 'label' => (string) ( $vocab[ $t ]['label'] ?? $t ), 'product' => (string) ( $vocab[ $t ]['product'] ?? '' ),
						'notes' => 0, 'by_scope' => array_fill_keys( ZRP_Vocab::SCOPES, 0 ), 'level' => 'seen',
						'hard_to_see' => in_array( 'hard_to_see', (array) ( $vocab[ $t ]['flags'] ?? array() ), true ) );
				}
				$st['tags'][ $t ]['notes']++;
				$st['tags'][ $t ]['by_scope'][ $scope ]++;
				if ( self::LEVEL[ self::level_of( $scope ) ] > self::LEVEL[ $st['tags'][ $t ]['level'] ] ) { $st['tags'][ $t ]['level'] = self::level_of( $scope ); }
				if ( null !== $r['seg_id'] && '' !== (string) $r['seg_id'] ) {
					$sid = (int) $r['seg_id'];
					$st['segments'][ $sid ] = $st['segments'][ $sid ] ?? array( 'seg_id' => $sid, 'tags' => array() );
					$st['segments'][ $sid ]['tags'][ $t ] = ( $st['segments'][ $sid ]['tags'][ $t ] ?? 0 ) + 1;
				}
			}
			if ( $holder && count( $st['said'] ) < self::SAID_EACH ) {
				$st['said'][] = array( 'said' => zrp_said_for( $r['said'], $uid ), 'scope' => $scope, 'side' => (string) $r['side'], 'tags' => $r['tags'], 'day' => $day );
			}
			unset( $st );
		}
		foreach ( $out as &$st ) {
			$st['segments'] = array_values( $st['segments'] );
			$st['center']   = self::mean( $st['_pts'] );
		}
		unset( $st );
		// areas -- a broad statement corroborated on a nearby street, and a hard-to-see feature
		// seen once (likely more around it). Read from the notes near the asked streets too, never their words.
		$areas = self::areas( $out, $streets ? self::near_notes( $out ) : array(), $vocab );
		foreach ( $out as &$st ) {
			foreach ( $areas as $a ) {
				if ( null === $st['center'] || isset( $st['tags'][ $a['tag'] ] ) || isset( $st['likely'][ $a['tag'] ] ) ) { continue; }
				if ( ZRP_DB::dist_m( $st['center'][0], $st['center'][1], $a['center'][0], $a['center'][1] ) <= $a['radius_m'] ) {
					$st['likely'][ $a['tag'] ] = array( 'label' => $a['label'], 'product' => $a['product'], 'why' => $a['reason'] );
				}
			}
			unset( $st['_pts'] );
		}
		unset( $st );
		return array( 'success' => true, 'verb' => self::VERB, 'holder' => (bool) $holder, 'streets' => array_values( $out ), 'count' => count( $out ),
			'areas' => $areas, 'truncated' => (bool) $got['truncated'] );
	}
}
