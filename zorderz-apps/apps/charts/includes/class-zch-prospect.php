<?php
/**
 * Prospecting: where to canvass (door hangers, flyers, knocking).
 *
 * Which neighborhoods are "good" depends on the ask, so it is not fixed in code. The hub ranks
 * the region's neighborhoods (patches of its address points) by a RECIPE: a product line,
 * ingredients and their weights, what to leave out, how many, where. This class is the
 * website's side of it:
 *
 *   clean_recipe()   the recipe as settings: bounded keys, plain numbers, bounded strings (the hub checks it again)
 *   hung_for()       the canvassing app's streets and days for the signed-in person (its read verb
 *                    `doorhangers.hung_streets`, or the `zdh_hung_streets` filter), so the hub can
 *                    leave out streets canvassed lately; null when there is no such log
 *   run()            the hub call (op `run_v2` with spec {prospect: recipe}), with this person's access block
 *   for_chat()       what the chat (charts.run) gets back: the ranked rows, reasons, streets; counts
 *                    and places, never a customer, an amount or an invoice
 *
 * Nothing here is stored but the audit line every hub call gets; a saved recipe is a saved
 * chart whose spec is {prospect: recipe} (ZCH_Store::clean_spec keeps it through clean_recipe).
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Prospect {
	/**
	 * The ingredients the reference hub knows. Census and own-data ingredients are always there;
	 * the place ingredients (fire_zone, burned_before, safety_area, inland, lot_size) exist when
	 * the hub's region pack carries the layer behind them. Any other hub may offer more, under the
	 * same key shape; the hub checks every key against its own closed list.
	 */
	const INGREDIENTS = array(
		'few_customers', 'product_nearby', 'single_family', 'owners', 'older_homes', 'not_hung', 'close',
		'fire_zone', 'burned_before', 'safety_area', 'inland', 'lot_size',
	);
	const KEY          = '/^[a-z][a-z_]{1,29}$/D';
	const MAX_INGREDIENTS = 24;
	const MAX_ROWS     = 20;
	const HUNG_DAYS    = 365;
	const HUNG_VERB    = 'doorhangers.hung_streets';

	/** Is this a key an ingredient may have? */
	public static function is_ingredient( $k ) {
		return is_string( $k ) && 1 === preg_match( self::KEY, $k );
	}

	/** A recipe from a page, a saved chart or the chat: only what the hub reads, plain and bounded. */
	public static function clean_recipe( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		$p   = isset( $raw['product'] ) && is_scalar( $raw['product'] ) ? strtolower( preg_replace( '/[^a-z_ -]/i', '', (string) $raw['product'] ) ) : '';
		if ( '' !== $p ) {
			$out['product'] = substr( str_replace( array( '-', ' ' ), '_', $p ), 0, 40 );
		}
		if ( isset( $raw['ingredients'] ) && is_array( $raw['ingredients'] ) ) {
			$ing = array();
			foreach ( $raw['ingredients'] as $k => $v ) {
				if ( ! self::is_ingredient( $k ) || ! is_numeric( $v ) || count( $ing ) >= self::MAX_INGREDIENTS ) {
					continue;
				}
				$ing[ $k ] = max( 0.0, min( 1.0, round( (float) $v, 3 ) ) );
			}
			if ( $ing ) {
				$out['ingredients'] = $ing;
			}
		}
		if ( in_array( $raw['replace_weights'] ?? null, array( true, 1, '1', 'true' ), true ) ) {
			$out['replace_weights'] = true;
		}
		if ( isset( $raw['exclude'] ) && is_array( $raw['exclude'] ) ) {
			$ex = array();
			foreach ( array( 'hung_within_days' => 3650, 'max_customers' => 10000, 'min_homes' => 2000 ) as $k => $hi ) {
				if ( ! array_key_exists( $k, $raw['exclude'] ) ) {
					continue;
				}
				$v = $raw['exclude'][ $k ];
				if ( null === $v || '' === $v || false === $v ) {
					$ex[ $k ] = null;
					continue;
				}
				if ( is_numeric( $v ) ) {
					$ex[ $k ] = max( 0, min( $hi, (int) $v ) );
				}
			}
			if ( $ex ) {
				$out['exclude'] = $ex;
			}
		}
		if ( isset( $raw['how_many'] ) && is_numeric( $raw['how_many'] ) ) {
			$out['how_many'] = max( 1, min( self::MAX_ROWS, (int) $raw['how_many'] ) );
		}
		if ( isset( $raw['near'] ) && ( is_string( $raw['near'] ) || is_int( $raw['near'] ) ) ) {
			$near = trim( mb_substr( sanitize_text_field( (string) $raw['near'] ), 0, 60 ) );
			if ( '' !== $near ) {
				$out['near'] = $near;
			}
		} elseif ( isset( $raw['near'] ) && is_array( $raw['near'] ) ) {       // "one town or the other": up to five places.
			$list = array();
			foreach ( array_slice( array_values( $raw['near'] ), 0, 10 ) as $n ) {
				if ( ! is_string( $n ) && ! is_int( $n ) ) {
					continue;
				}
				$n = trim( mb_substr( sanitize_text_field( (string) $n ), 0, 60 ) );
				if ( '' !== $n && ! in_array( mb_strtolower( $n ), array_map( 'mb_strtolower', $list ), true ) ) {
					$list[] = $n;
				}
			}
			$list = array_slice( $list, 0, 5 );
			if ( 1 === count( $list ) ) {
				$out['near'] = $list[0];
			} elseif ( $list ) {
				$out['near'] = $list;
			}
		}
		if ( isset( $raw['max_miles'] ) && is_numeric( $raw['max_miles'] ) ) {
			$out['max_miles'] = max( 0.5, min( 100.0, (float) $raw['max_miles'] ) );
		}
		if ( array_key_exists( 'spread', $raw ) ) {
			$out['spread'] = ! in_array( $raw['spread'], array( false, 0, '0', 'false' ), true );
		}
		if ( isset( $raw['name'] ) && is_scalar( $raw['name'] ) ) {
			$n = trim( mb_substr( sanitize_text_field( (string) $raw['name'] ), 0, 60 ) );
			if ( '' !== $n ) {
				$out['name'] = $n;
			}
		}
		return $out;
	}

	/** The canvassing app's streets and days for this person, or null when there is no log to read. */
	public static function hung_for( int $uid, $days = self::HUNG_DAYS ) {
		if ( $uid <= 0 ) {
			return null;
		}
		// A recipe that leaves out two years of routes needs two years of the log.
		$days = max( self::HUNG_DAYS, min( 3650, (int) $days ) );
		if ( class_exists( 'ZDZ_Capabilities' ) && ZDZ_Capabilities::get( self::HUNG_VERB ) ) {
			// The verb answers for the signed-in person only, so it is asked only when that is who this is for.
			if ( (int) get_current_user_id() !== $uid ) {
				return null;
			}
			$a = ZDZ_Capabilities::invoke( self::HUNG_VERB, array( 'days' => $days ) );
			// A refusal (not a holder of that app, the shared device) or a failed read: no log to read.
			if ( ! is_array( $a ) || ! empty( $a['denied'] ) || empty( $a['success'] ) || ! is_array( $a['rows'] ?? null ) ) {
				return null;
			}
			$rows = $a['rows'];
		} else {
			if ( ! has_filter( 'zdh_hung_streets' ) ) {
				return null;
			}
			$rows = apply_filters( 'zdh_hung_streets', null, $uid, $days );
		}
		return is_array( $rows ) ? self::clean_hung( $rows ) : null;
	}

	/** The canvassing log's rows, cut to street, ZIP, day and the house numbers covered. */
	public static function clean_hung( array $rows ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, 2000 ) as $r ) {
			if ( ! is_array( $r ) || empty( $r['street'] ) || ! is_scalar( $r['street'] ) || empty( $r['date'] ) || ! is_scalar( $r['date'] ) ) {
				continue;
			}
			$h = array(
				'street' => mb_substr( (string) $r['street'], 0, 80 ),
				'zip'    => substr( preg_replace( '/[^0-9]/', '', (string) ( $r['zip'] ?? '' ) ), 0, 5 ),
				'date'   => substr( (string) $r['date'], 0, 10 ),
			);
			// The house numbers the route covered there: the hub leaves out only that part.
			if ( isset( $r['low'], $r['high'] ) && is_numeric( $r['low'] ) && is_numeric( $r['high'] ) ) {
				$h['low']  = max( 0, (int) $r['low'] );
				$h['high'] = max( 0, (int) $r['high'] );
			}
			$out[] = $h;
		}
		return $out;
	}

	/** Does a question in words read like canvassing? */
	public static function is_canvassing_question( string $q ): bool {
		return 1 === preg_match( '/\b(?:hang|hanging|hangers?|door[\s-]?hangers?|flyers?|fliers?|canvass\w*|knock\w*|prospect\w*|leaflets?)\b/i', $q );
	}

	/** The canvassing log for a question in words, only when it reads like canvassing (every other ask goes without). */
	public static function hung_for_question( int $uid, string $q ) {
		return self::is_canvassing_question( $q ) ? self::hung_for( $uid, 3650 ) : null;   // the words may say "not in two years".
	}

	/**
	 * The canvassing app's street lookups from the region's address points on the hub (`run_v2`
	 * with {streets}), before that app asks a public website (filter `zdh_local_street_facts`).
	 * Public address data only (names, ZIPs, house numbers, counts). Null when no hub is
	 * connected, the person may not run Charts, or the hub did not answer in the last 5 minutes.
	 */
	public static function street_facts( $null, $streets, $near = null, $uid = 0 ) {
		$uid = (int) $uid ? (int) $uid : get_current_user_id();
		if ( ! is_array( $streets ) || ! $streets || $uid <= 0 ) {
			return $null;
		}
		$last = (int) get_option( 'zch_last_fail', 0 );
		if ( $last && time() - $last < 300 ) {        // down a moment ago: do not wait 20 s per street.
			return $null;
		}
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return $null;
		}
		$b = ZCH_Access::block( $uid, 'ui' );
		if ( ! in_array( 'run_v2', $b['ops'], true ) ) {
			return $null;
		}
		$q = array();
		foreach ( array_slice( $streets, 0, 12 ) as $st ) {
			if ( ! is_array( $st ) || ! isset( $st['name'] ) || ! is_scalar( $st['name'] ) ) {
				continue;
			}
			$one  = array( 'name' => mb_substr( sanitize_text_field( (string) $st['name'] ), 0, 120 ) );
			$path = array();
			foreach ( array_slice( (array) ( $st['path'] ?? array() ), 0, 600 ) as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
					$path[] = array( (float) $pt[0], (float) $pt[1] );
				}
			}
			if ( count( $path ) >= 2 ) {
				$one['path'] = $path;
			}
			$q[] = $one;
		}
		if ( ! $q ) {
			return $null;
		}
		$body = array( 'spec' => array( 'streets' => array( 'streets' => $q ) ), 'access' => $b );
		if ( is_array( $near ) && count( $near ) >= 2 && is_numeric( $near[0] ) && is_numeric( $near[1] ) ) {
			$body['spec']['streets']['near'] = array( (float) $near[0], (float) $near[1] );
		}
		$reply = $client->call( 'run_v2', $body, 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		ZCH_Store::audit( 'run_v2', ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, null, $client->last_ms, null, 'ui' );
		if ( 'hub_unavailable' === ( $reply['error']['code'] ?? '' ) ) {
			update_option( 'zch_last_fail', time(), false );
			return $null;
		}
		if ( empty( $reply['ok'] ) || 'streets' !== ( $reply['data']['status'] ?? '' ) ) {
			return $null;
		}
		$num = function ( $v ) {
			return is_numeric( $v ) ? (int) $v : null;
		};
		$out = array();
		foreach ( (array) ( $reply['data']['result']['streets'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'      => (string) $f['name'],
				'found'     => ! empty( $f['found'] ),
				'street'    => (string) ( $f['street'] ?? '' ),
				'zip'       => substr( preg_replace( '/[^0-9]/', '', (string) ( $f['zip'] ?? '' ) ), 0, 5 ),
				'city'      => (string) ( $f['city'] ?? '' ),
				'low'       => $num( $f['low'] ?? null ),
				'high'      => $num( $f['high'] ?? null ),
				'homes'     => (int) ( $f['homes'] ?? 0 ),
				'addresses' => (int) ( $f['addresses'] ?? 0 ),
				'on_path'   => ! empty( $f['on_path'] ),
			);
		}
		return $out;
	}

	/**
	 * The hub call. $caller 'ui' (the page), 'bot' (the chat) or 'app:<slug>'. $narrow: access
	 * keys a caller turns OFF for this call (charts.prospect sends money, by_person, compare and
	 * accounts as false: ranking neighborhoods needs none of them). It can only take away: a key
	 * is set to false, never to true, and the ops list is never touched. Returns the hub's reply.
	 */
	public static function run( array $recipe, int $uid, string $caller = 'ui', array $narrow = array() ) {
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			ZCH_Store::audit( 'run_v2', false, 'not_configured', null, null, null, $caller );
			return ZCH_Hub_Client::unavailable();
		}
		$b = ZCH_Access::block( $uid, $caller );
		if ( ! in_array( 'run_v2', $b['ops'], true ) ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => __( "Your account doesn't have access to that part of Charts.", 'zorderz' ) ) );
		}
		$b     = self::narrowed( $b, $narrow );
		$clean = self::clean_recipe( $recipe );
		$body  = array( 'spec' => array( 'prospect' => $clean ), 'access' => $b );
		$hung  = self::hung_for( $uid, (int) ( $clean['exclude']['hung_within_days'] ?? self::HUNG_DAYS ) );
		if ( null !== $hung ) {
			$body['hung'] = $hung;
		}
		$reply = $client->call( 'run_v2', $body, 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		$rows  = isset( $reply['data']['result']['rows'] ) ? count( (array) $reply['data']['result']['rows'] ) : null;
		ZCH_Store::audit( 'run_v2', ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, $rows, $client->last_ms, null, $caller );
		if ( 'hub_unavailable' === ( $reply['error']['code'] ?? '' ) ) {
			update_option( 'zch_last_fail', time(), false );
		}
		return $reply;
	}

	/** An access block with the named keys turned off: only ever narrowed. */
	public static function narrowed( array $b, array $narrow ): array {
		foreach ( array( 'money', 'by_person', 'compare', 'accounts' ) as $k ) {
			if ( array_key_exists( $k, $narrow ) && false === $narrow[ $k ] ) {
				$b[ $k ] = false;
			}
		}
		return $b;
	}

	/** One neighborhood for the page or the chat: only these keys, plain values. */
	public static function row( array $r ): array {
		$ing = array();
		foreach ( (array) ( $r['ingredients'] ?? array() ) as $k => $v ) {
			if ( ! self::is_ingredient( $k ) || ! is_array( $v ) ) {
				continue;
			}
			$ing[ $k ] = array(
				'value' => is_numeric( $v['value'] ?? null ) ? $v['value'] + 0 : null,
				'score' => is_numeric( $v['score'] ?? null ) ? (int) $v['score'] : null,
			);
		}
		$b = array_map( 'floatval', array_slice( array_values( (array) ( $r['bounds'] ?? array() ) ), 0, 4 ) );
		return array(
			'rank'           => (int) ( $r['rank'] ?? 0 ),
			'key'            => (int) ( $r['key'] ?? 0 ),
			'name'           => (string) ( $r['name'] ?? '' ),
			'town'           => (string) ( $r['town'] ?? '' ),
			'zip'            => substr( (string) ( $r['zip'] ?? '' ), 0, 5 ),
			'region'         => (string) ( $r['region'] ?? '' ),
			'homes'          => (int) ( $r['homes'] ?? 0 ),
			'walks'          => (int) ( $r['walks'] ?? 1 ),
			'streets'        => array_values( array_map( 'strval', array_slice( (array) ( $r['streets'] ?? array() ), 0, 8 ) ) ),
			'street_homes'   => array_values( array_map( 'intval', array_slice( (array) ( $r['street_homes'] ?? array() ), 0, 8 ) ) ),
			'customers'      => (int) ( $r['customers'] ?? 0 ),
			'mix'            => (int) ( $r['mix'] ?? 0 ),
			'reason'         => (string) ( $r['reason'] ?? '' ),
			'lat'            => (float) ( $r['lat'] ?? 0 ),
			'lon'            => (float) ( $r['lon'] ?? 0 ),
			'bounds'         => 4 === count( $b ) ? $b : array(),
			'miles'          => is_numeric( $r['miles'] ?? null ) ? (float) $r['miles'] : null,
			'last_hung_days' => is_numeric( $r['last_hung_days'] ?? null ) ? (int) $r['last_hung_days'] : null,
			'note'           => (string) ( $r['note'] ?? '' ),
			'ingredients'    => $ing,
		);
	}

	/** What the page gets: the hub's answer, rows re-shaped (only known keys). */
	public static function for_page( array $d ): array {
		$in  = (array) ( $d['result'] ?? array() );
		$str = function ( $a ) {
			return array_values( array_map( 'strval', array_filter( (array) $a, 'is_scalar' ) ) );
		};
		$ing = array();
		foreach ( (array) ( $in['ingredients'] ?? array() ) as $k => $m ) {
			if ( ! self::is_ingredient( $k ) || ! is_array( $m ) ) {
				continue;
			}
			$ing[ $k ] = array(
				'label'    => (string) ( $m['label'] ?? $k ),
				'unit'     => (string) ( $m['unit'] ?? '' ),
				'better'   => 'less' === ( $m['better'] ?? '' ) ? 'less' : 'more',
				'zip_wide' => ! empty( $m['zip_wide'] ),
			);
		}
		// Only these keys reach the page: anything else a hub answer carried stays behind.
		$res  = array(
			'title'       => (string) ( $in['title'] ?? '' ),
			'notes'       => $str( $in['notes'] ?? array() ),
			'read_as'     => $str( $in['read_as'] ?? array() ),
			'left_out'    => array_map( 'intval', array_filter( (array) ( $in['left_out'] ?? array() ), 'is_numeric' ) ),
			'candidates'  => (int) ( $in['candidates'] ?? 0 ),
			'ingredients' => $ing,
			'rows'        => array_map( array( __CLASS__, 'row' ), array_values( array_filter( (array) ( $in['rows'] ?? array() ), 'is_array' ) ) ),
		);
		$spec = isset( $d['spec']['prospect'] ) && is_array( $d['spec']['prospect'] )
			? array( 'prospect' => self::clean_recipe( $d['spec']['prospect'] ), 'title' => (string) ( $d['spec']['title'] ?? '' ) ) : null;
		return array(
			'spec'             => $spec,
			'status'           => (string) ( $d['status'] ?? 'prospect' ),
			'result'           => $res,
			'caption'          => (string) ( $d['caption'] ?? '' ),
			'clarify_question' => $d['clarify_question'] ?? null,
			'meta'             => array(
				'data_as_of'          => $d['meta']['data_as_of'] ?? null,
				'neighborhoods_built' => $d['meta']['neighborhoods_built'] ?? null,
			),
		);
	}

	/** What the chat (charts.run) gets back for a recipe. */
	public static function for_chat( array $d, array $recipe_in ): array {
		$res  = (array) ( $d['result'] ?? array() );
		$rows = array_map(
			function ( $r ) {
				$x = self::row( $r );
				unset( $x['ingredients'], $x['bounds'], $x['street_homes'] );
				return $x;
			},
			array_values( array_filter( (array) ( $res['rows'] ?? array() ), 'is_array' ) )
		);
		$recipe = self::clean_recipe( $d['spec']['prospect'] ?? $recipe_in );
		return array(
			'success'     => true,
			'verb'        => ZCH_Chat_Bridge::VERB,
			'kind'        => 'prospect',
			'title'       => (string) ( $res['title'] ?? '' ),
			'rows'        => $rows,
			'read_as'     => array_values( array_map( 'strval', (array) ( $res['read_as'] ?? array() ) ) ),
			'notes'       => array_values( array_map( 'strval', (array) ( $res['notes'] ?? array() ) ) ),
			'left_out'    => array_map( 'intval', (array) ( $res['left_out'] ?? array() ) ),
			'caption'     => (string) ( $d['caption'] ?? '' ),
			'recipe'      => $recipe,
			'spec_public' => array( 'prospect' => $recipe, 'title' => (string) ( $res['title'] ?? '' ) ),
			'data_as_of'  => (string) ( $d['meta']['data_as_of'] ?? '' ),
		);
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'zdh_local_street_facts', array( 'ZCH_Prospect', 'street_facts' ), 10, 4 );
}
