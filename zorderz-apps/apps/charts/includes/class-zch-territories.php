<?php
/**
 * The territory map: "what are my territories?", "who covers this ZIP?", "show all territories".
 *
 * Who covers which ZIP lives in the Territories app. Charts reads it ONLY through Territories'
 * read verbs on the capability registry, as the person asking:
 *
 *   'me'             territories.of_person {person: 'me'}   "what are my territories?", "my zip codes"
 *   'zip:NNNNN'      territories.for_zip   {zip}            "who covers NNNNN?", a tapped ZIP
 *   'all'            territories.map       {}               "show all territories", the layer on other ZIP maps
 *   'person:<word>'  territories.map, then the rows whose initials or first name are that word
 *   'zones'          territories.zones     {}               where each kind of work is offered, and named areas
 *   'zone:<id>'      territories.zones, then that one zone
 *
 * Territories shapes every answer by who asks (a salesperson sees their own unless an admin lets
 * them see each other; the shared device and field roles get nothing). Charts never re-derives
 * who may see what: it draws what the verb returned and says so when that is less than was asked.
 * It never reads Territories' tables or options, nor any other app's territory rules, and never
 * sends a territory to the Charts hub (the hub only reads the question's words). It works with no
 * hub at all.
 *
 * A missing verb, a refusal or a failed read is a sentence, never an empty map. An answer is
 * cached for a minute, keyed by the asker, their tier, the verb, its arguments and the last
 * `generation` Territories reported to this asker (every territory, rule or setting edit raises it).
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Territories {
	const VERB_PERSON = 'territories.of_person';
	const VERB_ZIP    = 'territories.for_zip';
	const VERB_MAP    = 'territories.map';
	const VERB_ZONES  = 'territories.zones';
	const CACHE_TTL   = 60;
	const GEN_TTL     = 600;
	const MAX_PEOPLE  = 60;
	const MAX_ZIPS    = 400;          // Territories' own cap per person.

	/** 'me' | 'all' | 'zones' | 'zone:<id>' | 'zip:NNNNN' | 'person:<word>', or '' when it is none of them. */
	public static function clean_who( $who ): string {
		if ( ! is_string( $who ) ) {
			return '';
		}
		$w = strtolower( trim( $who ) );
		if ( 'me' === $w || 'all' === $w || 'zones' === $w ) {
			return $w;
		}
		if ( preg_match( '/^zone:([a-z0-9_]{1,24})$/D', $w, $m ) ) {
			return 'zone:' . $m[1];
		}
		if ( preg_match( '/^zip:(\d{5})(?:-\d{4})?$/D', $w, $m ) ) {
			return 'zip:' . $m[1];
		}
		if ( preg_match( '/^person:([a-z][a-z.\-]{1,19})$/D', $w, $m ) ) {
			return 'person:' . trim( $m[1], '.-' );
		}
		return '';
	}

	/** May this person ask Territories at all? (the verb is registered and the registry lets them use it) */
	public static function available( $uid = null ): bool {
		$uid = null === $uid ? get_current_user_id() : (int) $uid;
		if ( $uid <= 0 || ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'get' ) ) {
			return false;
		}
		if ( ! ZDZ_Capabilities::get( self::VERB_MAP ) ) {
			return false;
		}
		return method_exists( 'ZDZ_Capabilities', 'can' ) ? ZDZ_Capabilities::can( self::VERB_MAP, $uid ) : true;
	}

	/**
	 * One verb, as the signed-in person. Returns the verb's own answer on success, else
	 * ['success' => false, 'why' => missing|denied|not_yours|error, 'message' => …].
	 */
	private static function ask_verb( string $verb, array $args ): array {
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'invoke' ) || ! ZDZ_Capabilities::get( $verb ) ) {
			return array(
				'success' => false,
				'why'     => 'missing',
				'message' => self::VERB_ZONES === $verb ? __( 'Zones need a newer Territories app.', 'zorderz' ) : __( "Territories isn't set up yet.", 'zorderz' ),
			);
		}
		$uid = (int) get_current_user_id();
		$gen = (string) get_transient( 'zch_terr_gen_' . $uid );
		// The tier is in the key (a demotion never gets the admin tier's cached answer), and the
		// answers that can hold an admin-only zone are never cached (a zone made admin-only is
		// hidden at once, not a minute later).
		$tier  = method_exists( 'ZDZ_Capabilities', 'bridge_tier' ) ? ZDZ_Capabilities::bridge_tier( $uid ) : '';
		$fresh = self::VERB_ZONES === $verb || self::VERB_ZIP === $verb;
		$key   = 'zch_terr_' . md5( wp_json_encode( array( $uid, $tier, $verb, $args, $gen ) ) );
		$hit   = $fresh ? false : get_transient( $key );
		// A cached answer is used only while the registry would still let this person ask (a
		// demotion or the shared device never gets the last minute's answer).
		if ( is_array( $hit ) && ! empty( $hit['success'] ) && ( ! method_exists( 'ZDZ_Capabilities', 'can' ) || ZDZ_Capabilities::can( $verb, $uid ) ) ) {
			return $hit;
		}
		$a = ZDZ_Capabilities::invoke( $verb, $args );       // no user id: the registry stamps the signed-in person.
		if ( ! is_array( $a ) ) {
			$a = array( 'success' => false, 'error' => 'No answer.' );
		}
		// The registry's refusal says success:true with denied:true, so a refusal is read before success.
		if ( ! empty( $a['denied'] ) ) {
			$why = 'not_yours' === ( $a['reason'] ?? '' ) ? 'not_yours' : 'denied';
			if ( 'unknown' === ( $a['reason'] ?? '' ) ) {
				$why = 'missing';
			}
			$msg = array(
				'not_yours' => __( 'Only your own territory is shown on your account.', 'zorderz' ),
				'missing'   => __( "Territories isn't set up yet.", 'zorderz' ),
				'denied'    => __( "That isn't available on your account.", 'zorderz' ),
			);
			return array( 'success' => false, 'why' => $why, 'message' => $msg[ $why ] );
		}
		if ( empty( $a['success'] ) ) {
			$err = is_string( $a['error'] ?? null ) ? $a['error'] : '';
			error_log( 'zorderz charts: ' . $verb . ' failed - ' . mb_substr( $err, 0, 200 ) );
			return array( 'success' => false, 'why' => 'error', 'message' => __( 'Territories could not be read just now.', 'zorderz' ), 'error' => mb_substr( $err, 0, 200 ) );
		}
		$g = isset( $a['generation'] ) && is_scalar( $a['generation'] ) ? (string) $a['generation'] : '';
		if ( $g !== $gen ) {                                // a newer generation: earlier answers are no longer used.
			set_transient( 'zch_terr_gen_' . $uid, $g, self::GEN_TTL );
			$key = 'zch_terr_' . md5( wp_json_encode( array( $uid, $tier, $verb, $args, $g ) ) );
		}
		if ( ! $fresh ) {
			set_transient( $key, $a, self::CACHE_TTL );
		}
		return $a;
	}

	/** A person row for the page: initials, a name when the verb gave one, colour, ZIPs. Never a user id. */
	public static function person( $p, int $me ): ?array {
		if ( ! is_array( $p ) ) {
			return null;
		}
		$ini = strtoupper( (string) ( $p['initials'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z]{1,3}$/D', $ini ) ) {
			$ini = '';
		}
		$color = (string) ( $p['color'] ?? '' );
		if ( ! preg_match( '/^#[0-9A-Fa-f]{6}$/D', $color ) ) {
			$color = '';
		}
		$zips = array();
		foreach ( array_slice( (array) ( $p['zips'] ?? array() ), 0, self::MAX_ZIPS ) as $z ) {
			if ( is_scalar( $z ) && preg_match( '/^(\d{5})/', (string) $z, $m ) ) {
				$zips[ $m[1] ] = true;
			}
		}
		$zips = array_keys( $zips );
		sort( $zips );
		$name = isset( $p['name'] ) && is_string( $p['name'] ) ? $p['name'] : ( isset( $p['first_name'] ) && is_string( $p['first_name'] ) ? $p['first_name'] : '' );
		return array(
			'initials' => $ini,
			'name'     => mb_substr( sanitize_text_field( $name ), 0, 60 ),
			'color'    => $color,
			'zips'     => array_map( 'strval', $zips ),
			'count'    => count( $zips ),
			'self'     => $me > 0 && (int) ( $p['user_id'] ?? 0 ) === $me,
		);
	}

	/** A zone as a map row: its short name in the initials' place, its label as the name. Never more. */
	public static function zone_row( $z ): ?array {
		if ( ! is_array( $z ) ) {
			return null;
		}
		$id = (string) ( $z['id'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9_]{1,24}$/D', $id ) ) {
			return null;
		}
		$short = strtoupper( (string) ( $z['short'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z0-9]{1,4}$/D', $short ) ) {
			$short = '';
		}
		$color = (string) ( $z['color'] ?? '' );
		if ( ! preg_match( '/^#[0-9A-Fa-f]{6}$/D', $color ) ) {
			$color = '';
		}
		$zips = array();
		foreach ( array_slice( (array) ( $z['zips'] ?? array() ), 0, 800 ) as $x ) {
			if ( is_scalar( $x ) && preg_match( '/^(\d{5})/', (string) $x, $m ) ) {
				$zips[ $m[1] ] = true;
			}
		}
		$zips = array_keys( $zips );
		sort( $zips );
		$zips = array_map( 'strval', $zips );
		return array(
			'id'         => $id,
			'initials'   => $short,
			'name'       => mb_substr( sanitize_text_field( (string) ( $z['label'] ?? '' ) ), 0, 40 ),
			'color'      => $color,
			'zips'       => $zips,
			'count'      => count( $zips ),
			'self'       => false,
			'kind'       => 'area' === ( $z['kind'] ?? '' ) ? 'area' : 'service',
			'everywhere' => ! empty( $z['everywhere'] ),
			'admin_only' => 'admin' === ( $z['visible'] ?? '' ),
		);
	}

	/** The zones a ZIP is in, as labels and short names. */
	private static function zone_names( array $zs ): array {
		$out = array();
		foreach ( array_slice( $zs, 0, 30 ) as $z ) {
			if ( ! is_array( $z ) || ! isset( $z['label'] ) ) {
				continue;
			}
			$c     = (string) ( $z['color'] ?? '' );
			$out[] = array(
				'label' => mb_substr( sanitize_text_field( (string) $z['label'] ), 0, 40 ),
				'short' => preg_match( '/^[A-Z0-9]{1,4}$/D', (string) ( $z['short'] ?? '' ) ) ? (string) $z['short'] : '',
				'color' => preg_match( '/^#[0-9A-Fa-f]{6}$/D', $c ) ? $c : '',
			);
		}
		return $out;
	}

	/** Overlaps among the people shown: ZIPs held by two or more, with their initials. */
	public static function overlaps( array $people ): array {
		$by = array();
		foreach ( $people as $p ) {
			foreach ( $p['zips'] as $z ) {
				$by[ $z ][] = '' !== $p['initials'] ? $p['initials'] : '?';
			}
		}
		$out = array();
		foreach ( $by as $z => $inis ) {
			if ( count( $inis ) > 1 ) {
				$out[] = array( 'zip' => (string) $z, 'initials' => $inis );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['zip'], $b['zip'] );
			}
		);
		return $out;
	}

	/** The planned title, when the hub gave none. */
	public static function title_for( string $who ): string {
		if ( 'me' === $who ) {
			return __( 'Your territory', 'zorderz' );
		}
		if ( 'all' === $who ) {
			return __( 'All territories', 'zorderz' );
		}
		if ( 0 === strpos( $who, 'zip:' ) ) {
			/* translators: %s: a ZIP code */
			return sprintf( __( 'Who covers %s', 'zorderz' ), substr( $who, 4 ) );
		}
		if ( 'zones' === $who ) {
			return __( 'Zones', 'zorderz' );
		}
		if ( 0 === strpos( $who, 'zone:' ) ) {
			return __( 'Zone', 'zorderz' );
		}
		if ( 0 === strpos( $who, 'person:' ) ) {
			$w = substr( $who, 7 );
			/* translators: %s: initials or a first name */
			return sprintf( __( "%s's territory", 'zorderz' ), strlen( $w ) <= 2 ? strtoupper( $w ) : ucfirst( $w ) );
		}
		return __( 'Territories', 'zorderz' );
	}

	/**
	 * The answer for one territory question, as the person asking:
	 * ['ok' => bool, 'who', 'title', 'shaped_for', 'people' => [...], 'overlaps' => [...], 'zip' => null|{...},
	 *  'message' => '' (why there is no map, or what the map leaves out), 'notes' => [], 'generation'].
	 */
	public static function answer( string $who, string $title = '' ): array {
		$who = self::clean_who( $who );
		// One zone's title is always its name as Territories gives it to this person, never what the
		// page sent (a card saved by the admin tier on a shared browser must not show a salesperson
		// an admin-only zone's name); and a refused answer never repeats the asked-for title.
		if ( 0 === strpos( $who, 'zone:' ) ) {
			$title = '';
		}
		$out = self::answer_as( $who, $title );
		if ( empty( $out['ok'] ) ) {
			$out['title'] = self::title_for( $who );
		}
		return $out;
	}

	private static function answer_as( string $who, string $title ): array {
		$title = trim( mb_substr( sanitize_text_field( $title ), 0, 90 ) );
		$out   = array(
			'ok'         => false,
			'who'        => $who,
			'title'      => '' !== $title ? $title : self::title_for( $who ),
			'shaped_for' => '',
			'people'     => array(),
			'overlaps'   => array(),
			'zip'        => null,
			'message'    => '',
			'notes'      => array(),
			'generation' => '',
			'rules'      => array(),
			'zones'      => false,
			'everywhere' => array(),
		);
		if ( '' === $who ) {
			$out['message'] = __( 'Ask "what are my territories?", "who covers" a ZIP code, or "show all territories".', 'zorderz' );
			return $out;
		}
		$me = (int) get_current_user_id();
		if ( 'me' === $who ) {
			$a = self::ask_verb( self::VERB_PERSON, array( 'person' => 'me' ) );
			if ( empty( $a['success'] ) ) {
				$out['message'] = 'error' === ( $a['why'] ?? '' ) && 'No such person on the team.' === ( $a['error'] ?? '' )
					? __( "You aren't on the team in Territories, so you have no territory.", 'zorderz' ) : (string) $a['message'];
				return $out;
			}
			$p = self::person( $a['person'] ?? null, $me );
			if ( $p ) {
				$p['self'] = true;
			}
			$out['people'] = $p && $p['count'] ? array( $p ) : array();
			if ( ! $out['people'] ) {
				$out['message'] = __( 'You have no ZIP codes in Territories yet. An admin adds them in Territories.', 'zorderz' );
			}
		} elseif ( 0 === strpos( $who, 'zip:' ) ) {
			$zip = substr( $who, 4 );
			$a   = self::ask_verb( self::VERB_ZIP, array( 'zip' => $zip ) );
			if ( empty( $a['success'] ) ) {
				$out['message'] = (string) $a['message'];
				return $out;
			}
			$cover = array();
			foreach ( array_slice( (array) ( $a['people'] ?? array() ), 0, self::MAX_PEOPLE ) as $row ) {
				$p = self::person( is_array( $row ) ? $row + array( 'zips' => array( $zip ) ) : null, $me );
				if ( $p ) {
					$cover[] = $p;
				}
			}
			$own = 'own' === ( $a['shaped_for'] ?? '' );
			// The verb sends a salesperson first names and initials, no ids: "own" holds only the
			// asker; otherwise the asker is the row with their own initials.
			$mine = ZCH_Access::initials( $me );
			foreach ( $cover as &$c ) {
				$c['self'] = $c['self'] || $own || ( '' !== $mine && $c['initials'] === $mine );
			}
			unset( $c );
			$out['zip']    = array(
				'zip'        => $zip,
				'covered_by' => array_map(
					function ( $p ) {
						return array( 'initials' => $p['initials'], 'name' => $p['name'], 'color' => $p['color'] );
					},
					$cover
				),
				'overlap'    => count( $cover ) > 1,
				'own_only'   => $own,
				'zones'      => self::zone_names( (array) ( $a['zones'] ?? array() ) ),
			);
			$out['people'] = $cover;
			if ( ! $cover ) {
				$out['message'] = $own
					/* translators: %s: a ZIP code */
					? sprintf( __( "%s isn't in your territory. Others' territories aren't shown on your account.", 'zorderz' ), $zip )
					/* translators: %s: a ZIP code */
					: sprintf( __( 'No one covers %s in Territories.', 'zorderz' ), $zip );
			}
			foreach ( array_slice( (array) ( $a['rules'] ?? array() ), 0, 12 ) as $r ) {      // the admin tier only.
				if ( is_array( $r ) && isset( $r['sentence'] ) && is_string( $r['sentence'] ) ) {
					$out['rules'][] = array( 'no' => (int) ( $r['no'] ?? 0 ), 'sentence' => mb_substr( $r['sentence'], 0, 300 ), 'live' => ! empty( $r['live'] ) );
				}
			}
		} elseif ( 'zones' === $who || 0 === strpos( $who, 'zone:' ) ) {
			$a = self::ask_verb( self::VERB_ZONES, array() );
			if ( empty( $a['success'] ) ) {
				$out['message'] = (string) $a['message'];
				return $out;
			}
			$one  = 0 === strpos( $who, 'zone:' ) ? substr( $who, 5 ) : '';
			$rows = array();
			foreach ( array_slice( (array) ( $a['zones'] ?? array() ), 0, self::MAX_PEOPLE ) as $z ) {
				$r = self::zone_row( $z );
				if ( $r && ( '' === $one || $r['id'] === $one ) ) {
					$rows[] = $r;
				}
			}
			if ( '' !== $one && ! $rows ) {
				$out['message'] = __( "That zone isn't one you may see.", 'zorderz' );
				return $out;
			}
			if ( '' !== $one && '' === $title ) {
				$out['title'] = $rows[0]['name'];
			}
			$out['everywhere'] = array_values(
				array_map(
					function ( $r ) {
						return array( 'initials' => $r['initials'], 'name' => $r['name'], 'color' => $r['color'], 'admin_only' => $r['admin_only'] );
					},
					array_filter(
						$rows,
						function ( $r ) {
							return $r['everywhere'];
						}
					)
				)
			);
			$out['people']     = array_values(
				array_filter(
					$rows,
					function ( $r ) {
						return ! $r['everywhere'];
					}
				)
			);
			$out['zones']      = true;
			if ( ! $rows ) {
				$out['message'] = __( 'No zones in Territories yet. The admin tier adds them in Territories.', 'zorderz' );
			}
		} else {                                           // 'all' and 'person:<word>'.
			$a = self::ask_verb( self::VERB_MAP, array() );
			if ( empty( $a['success'] ) ) {
				$out['message'] = (string) $a['message'];
				return $out;
			}
			$people = array();
			$rows   = (array) ( $a['people'] ?? array() );
			foreach ( array_slice( $rows, 0, self::MAX_PEOPLE ) as $row ) {
				$p = self::person( $row, $me );
				if ( $p && $p['count'] ) {
					$people[] = $p;
				}
			}
			if ( count( $rows ) > self::MAX_PEOPLE ) {
				/* translators: 1: how many are shown, 2: how many there are */
				$out['notes'][] = sprintf( __( 'The first %1$d of %2$d people are shown.', 'zorderz' ), self::MAX_PEOPLE, count( $rows ) );
			}
			$shaped = (string) ( $a['shaped_for'] ?? '' );
			if ( 0 === strpos( $who, 'person:' ) ) {
				$word = substr( $who, 7 );
				$hit  = array_values(
					array_filter(
						$people,
						function ( $p ) use ( $word ) {
							$first = strtolower( (string) strtok( $p['name'] . ' ', ' ' ) );
							return strtolower( $p['initials'] ) === $word || ( '' !== $first && $first === $word );
						}
					)
				);
				if ( ! $hit ) {
					$shown = strlen( $word ) <= 2 ? strtoupper( $word ) : ucfirst( $word );
					if ( 'own' === $shaped ) {
						$out['message'] = __( 'Only your own territory is shown on your account.', 'zorderz' );
					} elseif ( 'peers' === $shaped ) {
						// Territories shows a salesperson others' initials, not their names.
						/* translators: %s: initials */
						$out['message'] = sprintf( __( 'No one with the initials "%s" has a territory you may see. Others are shown by their initials.', 'zorderz' ), strtoupper( $word ) );
					} else {
						/* translators: %s: a first name or initials */
						$out['message'] = sprintf( __( 'No one called "%s" has a territory you may see.', 'zorderz' ), $shown );
					}
					return $out;
				}
				$people = $hit;
			} elseif ( 'own' === $shaped ) {
				$out['notes'][] = __( 'Only your own territory is shown on your account.', 'zorderz' );
			}
			$out['people'] = $people;
			if ( ! $people ) {
				$out['message'] = 'admin' === $shaped ? __( 'No one has ZIP codes in Territories yet.', 'zorderz' )
					: __( 'You have no ZIP codes in Territories yet. An admin adds them in Territories.', 'zorderz' );
			}
		}
		$out['ok']         = true;
		$out['shaped_for'] = (string) ( $a['shaped_for'] ?? '' );
		$out['generation'] = isset( $a['generation'] ) && is_scalar( $a['generation'] ) ? (string) $a['generation'] : '';
		$out['overlaps']   = self::overlaps( $out['people'] );
		return $out;
	}

	/** The page's payload: the shape every answer has, plus `territories`. */
	public static function for_page( string $who, string $title = '', array $read_as = array() ): array {
		$t    = self::answer( $who, $title );
		$read = array();
		foreach ( array_slice( $read_as, 0, 4 ) as $r ) {
			if ( is_string( $r ) && '' !== $r ) {
				$read[] = mb_substr( $r, 0, 200 );
			}
		}
		return array(
			'status'      => 'territories',
			'spec'        => array( 'territories' => array( 'who' => $t['who'] ), 'title' => $t['title'] ),
			'result'      => array( 'rows' => array(), 'read_as' => $read, 'notes' => $t['notes'], 'title_full' => $t['title'] ),
			'territories' => $t,
			'caption'     => self::caption( $t ),
			'meta'        => array( 'model' => 'rule', 'backup_model_used' => false, 'cached' => false, 'data_as_of' => null, 'caption_model' => null ),
		);
	}

	/** One plain sentence about the map, written from what the verb returned (never a model's words). */
	public static function caption( array $t ): string {
		if ( empty( $t['ok'] ) ) {
			return (string) $t['message'];
		}
		if ( $t['zip'] ) {
			$z = $t['zip']['zip'];
			$c = $t['zip']['covered_by'];
			if ( ! $c ) {
				return (string) $t['message'];
			}
			$names = array_map(
				function ( $p ) {
					return $p['initials'] . ( '' !== $p['name'] ? ' (' . $p['name'] . ')' : '' );
				},
				$c
			);
			/* translators: %s: a ZIP code */
			$s = 1 === count( $c ) && ! empty( $t['people'][0]['self'] ) ? sprintf( __( 'You cover %s.', 'zorderz' ), $z )
				/* translators: 1: a ZIP code, 2: who covers it */
				: sprintf( __( '%1$s is covered by %2$s.', 'zorderz' ), $z, implode( ' ' . __( 'and', 'zorderz' ) . ' ', $names ) );
			return $s . ( count( $c ) > 1 ? ' ' . __( 'It is shared; Territories takes turns.', 'zorderz' ) : '' );
		}
		if ( ! empty( $t['zones'] ) ) {
			$bits = array();
			foreach ( $t['people'] as $z ) {
				/* translators: 1: a zone, 2: how many ZIP codes */
				$bits[] = sprintf( _n( '%1$s (%2$d ZIP code)', '%1$s (%2$d ZIP codes)', $z['count'], 'zorderz' ), $z['name'], $z['count'] );
			}
			foreach ( $t['everywhere'] as $z ) {
				/* translators: %s: a zone */
				$bits[] = sprintf( __( '%s (everywhere)', 'zorderz' ), $z['name'] );
			}
			return $bits ? implode( ' · ', $bits ) . '.' : (string) $t['message'];
		}
		if ( ! $t['people'] ) {
			return (string) $t['message'];
		}
		if ( 1 === count( $t['people'] ) ) {
			$p   = $t['people'][0];
			/* translators: %s: initials */
			$who = $p['self'] ? __( 'Your territory', 'zorderz' ) : sprintf( __( "%s's territory", 'zorderz' ), $p['initials'] );
			/* translators: 1: whose territory, 2: how many ZIP codes */
			return sprintf( _n( '%1$s: %2$d ZIP code.', '%1$s: %2$d ZIP codes.', $p['count'], 'zorderz' ), $who, $p['count'] );
		}
		$zips = array();
		foreach ( $t['people'] as $p ) {
			foreach ( $p['zips'] as $z ) {
				$zips[ $z ] = 1;
			}
		}
		/* translators: 1: how many people, 2: how many ZIP codes */
		$s = sprintf( __( '%1$d people cover %2$d ZIP codes', 'zorderz' ), count( $t['people'] ), count( $zips ) );
		if ( $t['overlaps'] ) {
			/* translators: %d: how many ZIP codes are shared */
			$s .= '; ' . sprintf( _n( '%d is shared', '%d are shared', count( $t['overlaps'] ), 'zorderz' ), count( $t['overlaps'] ) );
		}
		return $s . '.';
	}

	/** What the chat gets from charts.run {territories: {who}}: the people and their ZIPs as words, and a chip spec. */
	public static function for_chat( string $who, string $verb_name ): array {
		$t = self::answer( $who );
		if ( empty( $t['ok'] ) ) {
			return array( 'success' => false, 'verb' => $verb_name, 'kind' => 'territories', 'error' => 'territories', 'message' => (string) $t['message'] );
		}
		return array(
			'success'     => true,
			'verb'        => $verb_name,
			'kind'        => 'territories',
			'who'         => $t['who'],
			'title'       => $t['title'],
			'shaped_for'  => $t['shaped_for'],
			'people'      => array_map(
				function ( $p ) {
					$row = array( 'initials' => $p['initials'], 'name' => $p['name'], 'zips' => $p['zips'], 'zip_count' => $p['count'], 'you' => $p['self'] );
					if ( isset( $p['admin_only'] ) ) {
						$row['admin_only'] = (bool) $p['admin_only'];   // the chat knows which zones are the admin tier's alone.
					}
					return $row;
				},
				$t['people']
			),
			'overlaps'    => $t['overlaps'],
			'zip'         => $t['zip'],
			'rules'       => $t['rules'],
			'zones'       => ! empty( $t['zones'] ),
			'everywhere'  => $t['everywhere'],
			'caption'     => self::caption( $t ),
			'message'     => $t['message'],
			'notes'       => $t['notes'],
			'spec_public' => 0 === strpos( $t['who'], 'zone:' ) ? array( 'territories' => array( 'who' => $t['who'] ) )
				: array( 'territories' => array( 'who' => $t['who'] ), 'title' => $t['title'] ),
			'spec_v2'     => array( 'territories' => array( 'who' => $t['who'] ) ),
		);
	}
}
