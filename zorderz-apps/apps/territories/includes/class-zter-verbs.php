<?php
/**
 * ZTER_Verbs — the read verbs other apps ask, on the capability registry: an app that owns some data
 * publishes a read verb; another app asks through ZDZ_Capabilities::invoke; the answer is shaped by who asks.
 *
 * Identity always comes from the registry: user_id, tier and is_kiosk in $args are stamped over anything the
 * caller passed. "Admin" below means the registry's tier 'admin'. "Staff" means sales, the operator and the
 * shop (tier staff, which the registry passes as 'staff' or 'operator'). Techs (viewer) and the shared device
 * are refused by the registry before these run; each callback refuses them again.
 *
 * Every answer carries `generation`: a number every territory, rule, zone or setting edit raises. A view (a
 * map) may cache an answer briefly, keyed by the verb, its arguments, the asker and the generation. The
 * assigner never caches. A failed read is success:false with an error, never an empty success.
 *
 *   territories.of_person   person: 'me' | id. Staff: their own {user_id, initials, name, color, zips[]};
 *                           another person only when "salespeople see each other" is on, then {initials,
 *                           first_name, color, zips[]}. Admin: anyone's.
 *   territories.for_zip     zip. Staff: themselves if they cover it; everyone {initials, first_name, color}
 *                           only when "see each other" is on. Admin: people[] also {user_id, name}; overlap;
 *                           rules[] that can decide that ZIP {no, id, sentence, live}; tie_break. Both: the
 *                           zones the asker may see that the ZIP is in.
 *   territories.map         Staff: their own territory; others' {initials, color, zips[]} only when allowed.
 *                           Admin: every person {user_id, initials, name, color, zips[]}; overlaps[].
 *   territories.route_for   zip, reference, lines, default_initials, test, key, pairs. Only the admin tier and
 *                           people who make stubs (zest_create_zero_estimates): {initials, name, user_id,
 *                           decision_id, mode, people[], review}. With pairs: true a live answer may name two
 *                           people ("AB & CD").
 *   territories.zones       kind: service | area. Staff: the zones marked visible to staff; admin: every zone,
 *                           with `visible`. Never a no-go zone.
 *   territories.installer_for  zip, reference, lines, seller_user_id, key, pairs. Staff and admin: who does a
 *                           won job, from the install rules: {user_id, initials, name, why, others[],
 *                           decision_id, mode}; with pairs, people[ {part, user_id, items[], labels[], why,
 *                           others[]} ] for a job split between two installers.
 *   territories.preview_route  stubs[] (at most 200). Admin only: who the LIVE stub rules would give each
 *                           existing stub. A dry run: nothing recorded, no turn stored.
 *   territories.uncovered   the referral cases (a stub or won job whose ZIP is outside every service area of
 *                           its work). Admin: every case; a salesperson: their own; a tech: nothing. No
 *                           customer's name, phone or email: Territories never has them.
 *
 * Never, to anyone: rules' internals through of_person/map, Team Roles' notes or `why`, workload, customers,
 * money.
 *
 * route_for and installer_for record their decision in this app's own log and take a SHADOW turn. That is this
 * app's bookkeeping, not a write to any system of record or another app's tables, so they are registered as
 * reads (side_effect false). A verb that can move whose turn is next is not open to every salesperson: only the
 * admin tier and people who make stubs may call route_for, and the LIVE turn moves only when the estimate app
 * writes an assignment (zest_operator_routed), never on a question.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Verbs {

	const MAX_LINES = 60;

	public static function init() {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$base = array(
			'source' => 'territories', 'app' => ZTER_APP_ID, 'min_tier' => 'staff', 'kiosk' => false, 'kiosk_bounded' => false,
			'side_effect' => false, 'confirm' => false, 'needs_app' => false,
		);
		$verbs = array(
			'territories.of_person' => array( 'of_person', 'A person\'s ZIP codes, initials and color: your own; anyone\'s for the admin tier (others\' only when the admin allows it).' ),
			'territories.for_zip'   => array( 'for_zip', 'Who covers a ZIP: initials, first name and color; the admin tier also gets the overlap and the rules that decide it.' ),
			'territories.map'       => array( 'map', 'Every territory for a map: the admin tier gets everyone; others get their own (and others\' outlines only when allowed).' ),
			'territories.route_for' => array( 'route_for', 'Who gets a new estimate stub: initials, name, a decision id and whether the rule is live or in shadow. The reasons stay in Territories.' ),
			'territories.zones'     => array( 'zones', 'The zones: where each kind of work is offered, and named areas, with their ZIP codes (or everywhere). Staff see the staff zones; the admin tier sees every zone.' ),
			'territories.uncovered' => array( 'uncovered', 'The jobs outside every service area that need a referral to another company: ZIP, place, the work, the date and the estimate number. The admin tier sees every case; a salesperson their own. No customer details.' ),
		);
		foreach ( $verbs as $verb => $v ) {
			$caps[ $verb ] = array(
				'callable'   => array( __CLASS__, $v[0] ),
				'descriptor' => $base + array( 'verb' => $verb, 'summary' => $v[1] ),
			);
		}
		// The estimate app's re-assign batch asks who would get each EXISTING stub. Admin tier only, never the
		// shared device; a dry run (nothing recorded, no turn stored), so it is a read like the others.
		$caps['territories.preview_route'] = array(
			'callable'   => array( __CLASS__, 'preview_route' ),
			'descriptor' => array( 'min_tier' => 'admin' ) + $base + array(
				'verb'    => 'territories.preview_route',
				'summary' => 'Who the live rules would give each of these existing stubs (at most 200), with the rule and why. A dry run for the admin tier: nothing is recorded and no turn is taken.',
			),
		);
		// Who does a WON job (the installer stage). The Jobs app asks for its installer suggestion; the job's
		// salesperson confirms it there (Yes, or Another). It records its decision and takes a shadow turn, as
		// route_for does: this app's own bookkeeping, so a read like the others. Staff and the admin tier; never
		// a tech, never the shared device.
		$caps['territories.installer_for'] = array(
			'callable'   => array( __CLASS__, 'installer_for' ),
			'descriptor' => $base + array(
				'verb'    => 'territories.installer_for',
				'summary' => 'Who should do a won job (install or repair): one suggestion from the install rules when a rule is live, with one line of why and the others who could, for a person to confirm. In shadow it suggests no one and records what the rules would pick.',
			),
		);
		return $caps;
	}

	/* ── shared ───────────────────────────────────────────────────────────────────────────────────────── */

	/** [ uid, is_admin ] or an error answer when the asker may not have any answer (kiosk, signed out, a tech). */
	private static function asker( array $args, $verb ) {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$tier  = (string) ( $args['tier'] ?? '' );
		if ( ! empty( $args['is_kiosk'] ) || $uid <= 0 || ! in_array( $tier, array( 'admin', 'staff', 'operator' ), true ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'tier', 'error' => __( 'That is not available on your account.', 'zorderz' ), 'verb' => $verb );
		}
		return array( $uid, 'admin' === $tier );
	}

	private static function fail( $verb, $msg ) {
		return array( 'success' => false, 'error' => $msg, 'verb' => $verb );
	}

	private static function person_row( $uid, array $zips, $full ) {
		$u    = get_userdata( (int) $uid );
		$name = $u ? (string) $u->display_name : '';
		$row  = array( 'initials' => zter_initials( $uid ), 'color' => zter_color( $uid ), 'zips' => array_values( $zips ) );
		if ( $full ) {
			return array( 'user_id' => (int) $uid, 'name' => $name ) + $row;
		}
		return array( 'first_name' => zter_first_name( $name ) ) + $row;
	}

	/* ── territories.of_person ────────────────────────────────────────────────────────────────────────── */

	public static function of_person( array $args ) {
		$v = 'territories.of_person';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		$p = $args['person'] ?? 'me';
		$target = ( 'me' === $p || '' === $p || null === $p ) ? $uid : ( is_numeric( $p ) ? (int) $p : -1 );
		if ( $target <= 0 || ! zter_is_team_member( $target ) ) {
			return self::fail( $v, __( 'No such person on the team.', 'zorderz' ) );
		}
		$self = $target === $uid;
		if ( ! $self && ! $admin && ! ZTER_DB::settings()['peers_visible'] ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'not_yours', 'error' => __( 'Only your own territory is shown.', 'zorderz' ), 'verb' => $v );
		}
		$all = ZTER_DB::all_zips();
		if ( null === $all ) {
			return self::fail( $v, __( 'The territories could not be read just now.', 'zorderz' ) );
		}
		return array(
			'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(),
			'shaped_for' => $admin ? 'admin' : ( $self ? 'self' : 'peer' ),
			'person' => self::person_row( $target, $all[ $target ] ?? array(), $admin || $self ),
		);
	}

	/* ── territories.for_zip ──────────────────────────────────────────────────────────────────────────── */

	public static function for_zip( array $args ) {
		$v = 'territories.for_zip';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		$zip = ZTER_DB::clean_zip( $args['zip'] ?? '' );
		if ( '' === $zip ) {
			return self::fail( $v, __( 'A five-digit ZIP code is required.', 'zorderz' ) );
		}
		$ids = ZTER_DB::people_for_zip( $zip );
		if ( null === $ids ) {
			return self::fail( $v, __( 'The territories could not be read just now.', 'zorderz' ) );
		}
		$people = array();
		foreach ( $ids as $id ) {
			if ( ! zter_is_team_member( $id ) ) {
				continue;
			}
			$row = self::person_row( $id, array(), $admin );
			unset( $row['zips'] );
			$people[] = $row;
		}
		usort( $people, function ( $x, $y ) { return strcmp( $x['initials'], $y['initials'] ); } );
		if ( ! $admin && ! ZTER_DB::settings()['peers_visible'] ) {
			// Others' territories are not shown by default: asking ZIP by ZIP would rebuild them.
			$self   = in_array( $uid, $ids, true ) && zter_is_team_member( $uid ) ? array( $uid ) : array();
			$people = array_values( array_filter( $people, function ( $p ) use ( $self ) { return (bool) $self && $p['initials'] === zter_initials( $self[0] ); } ) );
		}
		$out = array( 'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(), 'shaped_for' => $admin ? 'admin' : ( ZTER_DB::settings()['peers_visible'] ? 'staff' : 'own' ), 'zip' => $zip, 'people' => $people );
		$out['zones'] = ZTER_Zones::for_zip( $zip, $admin );   // the zones this asker may see that the ZIP is in
		if ( $admin ) {
			$out['overlap']   = count( $people ) > 1;
			$out['tie_break'] = 'round_robin';
			$out['rules']     = array();
			$rules = ZTER_Rules::get();
			$abs   = ZTER_Kinds::abilities();
			foreach ( $rules['rows'] as $i => $r ) {
				if ( 'territory' === $r['who'] || in_array( $zip, $r['zips'], true ) || ! empty( $r['fallback'] ) ) {
					$out['rules'][] = array( 'no' => $i + 1, 'id' => $r['id'], 'sentence' => ZTER_Rules::sentence( $r, $abs ), 'live' => ! empty( $r['live'] ) );
				}
			}
			$out['rules_version'] = (int) $rules['version'];
		}
		return $out;
	}

	/* ── territories.map ──────────────────────────────────────────────────────────────────────────────── */

	public static function map( array $args ) {
		$v = 'territories.map';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		$all = ZTER_DB::all_zips();
		if ( null === $all ) {
			return self::fail( $v, __( 'The territories could not be read just now.', 'zorderz' ) );
		}
		$peers  = ZTER_DB::settings()['peers_visible'];
		$people = array();
		$by_zip = array();
		foreach ( $all as $id => $zips ) {
			if ( ! zter_is_team_member( $id ) ) {
				continue; // someone who left the team holds no territory on the map
			}
			$ini = zter_initials( $id );
			foreach ( $zips as $z ) {
				$by_zip[ $z ][] = $ini ?: '#' . $id;
			}
			if ( $admin || $id === $uid ) {
				$people[] = self::person_row( $id, $zips, true );
			} elseif ( $peers ) {
				$row = self::person_row( $id, $zips, false );
				unset( $row['first_name'] );
				$people[] = $row; // an outline: initials, color, ZIPs
			}
		}
		usort( $people, function ( $x, $y ) { return strcmp( $x['initials'], $y['initials'] ); } );
		$out = array(
			'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(),
			'shaped_for' => $admin ? 'admin' : ( $peers ? 'peers' : 'own' ), 'people' => $people,
		);
		if ( $admin ) {
			$over = array();
			foreach ( $by_zip as $z => $inis ) {
				if ( count( $inis ) > 1 ) {
					$over[] = array( 'zip' => (string) $z, 'initials' => $inis );
				}
			}
			usort( $over, function ( $x, $y ) { return strcmp( $x['zip'], $y['zip'] ); } );
			$out['overlaps']   = $over;
			$out['zips_total'] = count( $by_zip );
		}
		return $out;
	}

	/* ── territories.route_for ────────────────────────────────────────────────────────────────────────── */

	/**
	 * $args: zip (5 digits, may be empty), reference (its first five digits are the ZIP when zip is empty), lines
	 * (at most 60; {name, desc} or an estimate's {description, sub_description, notes}), default_initials (what the
	 * asking app would write without Territories), test (a test stub; kept out of the weekly comparison), key
	 * (optional: the asking app's own name for this stub, e.g. a hash it makes of the customer and the reference;
	 * the same key within 10 minutes gets the same decision. Territories never learns who the customer is).
	 *
	 * Use `initials` only when success is true AND mode is 'live'. In shadow, `initials` is the default you sent.
	 */
	public static function route_for( array $args ) {
		// Every call that reaches Territories is kept for Shadow -> Last asked (ZTER_Asks), the outcome
		// only. A refusal the registry makes before this runs (the shared device, a tier, app access) is shown there by
		// Who may ask instead.
		$app = class_exists( 'ZTER_Asks' ) ? ZTER_Asks::asking_app() : '';
		try {
			$out = self::route_for_inner( $args );
		} catch ( \Throwable $e ) {
			// kept as an error, then thrown on: the registry answers it as it would without the record
			self::record_ask( $args, self::fail( 'territories.route_for', __( 'The answer failed.', 'zorderz' ) ), $app );
			throw $e;
		}
		self::record_ask( $args, $out, $app );
		return $out;
	}

	/** The window onto the verb never changes its answer or throws. */
	private static function record_ask( array $args, array $out, $app ) {
		if ( class_exists( 'ZTER_Asks' ) ) {
			try {
				ZTER_Asks::record( $args, $out, $app );
			} catch ( \Throwable $e ) {
				// nothing: the answer stands
			}
		}
	}

	private static function route_for_inner( array $args ) {
		$v = 'territories.route_for';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		if ( ! $admin && ! user_can( $uid, 'zest_create_zero_estimates' ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'not_allowed', 'error' => __( 'Only the people who make estimate stubs can ask who gets one.', 'zorderz' ), 'verb' => $v );
		}
		$lines = $args['lines'] ?? array();
		if ( ! is_array( $lines ) ) {
			return self::fail( $v, __( 'lines must be a list.', 'zorderz' ) );
		}
		try {
			list( $answer, $rec ) = ZTER_Rules::route( array(
				'zip' => $args['zip'] ?? '', 'reference' => $args['reference'] ?? '', 'lines' => array_slice( $lines, 0, self::MAX_LINES ),
				'default_initials' => $args['default_initials'] ?? '', 'test' => ! empty( $args['test'] ), 'label' => '',
				'key' => is_scalar( $args['key'] ?? null ) ? (string) $args['key'] : '',
				'asker_id' => $uid, 'stage' => 'stub',
				// the asker writes two people ("AB & CD"); without it a two-person answer keeps the default
				'pairs' => ! empty( $args['pairs'] ),
			) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Territories: route_for failed - ' . $e->getMessage() );
			return self::fail( $v, __( 'Who gets this could not be worked out just now.', 'zorderz' ) );
		}
		if ( empty( $rec['id'] ) ) {
			// Not recorded = no trail: never an assignment nobody can explain.
			return self::fail( $v, __( 'The decision could not be recorded, so none was made.', 'zorderz' ) );
		}
		return $answer + array( 'verb' => $v, 'generation' => ZTER_DB::generation() );
	}

	/* ── territories.zones ────────────────────────────────────────────────────────────────────── */

	/**
	 * args: { kind: 'service' | 'area' }   (optional)
	 * staff → shaped_for:'staff': the zones marked visible to staff
	 * admin → shaped_for:'admin': every zone, with `visible`
	 * Each zone: { id, label, short, kind, abilities[], everywhere, zips[], color } (+ visible for the admin tier).
	 */
	public static function zones( array $args ) {
		$v = 'territories.zones';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( , $admin ) = $a;
		$kind = is_scalar( $args['kind'] ?? '' ) ? (string) ( $args['kind'] ?? '' ) : '';
		$rows = array();
		foreach ( ZTER_Zones::visible_to( $admin, true ) as $z ) {
			if ( '' !== $kind && $kind !== $z['kind'] ) {
				continue;
			}
			if ( ! $admin ) {
				unset( $z['visible'] );
			}
			$rows[] = $z;
		}
		return array( 'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(), 'shaped_for' => $admin ? 'admin' : 'staff', 'zones' => $rows );
	}

	/* ── territories.uncovered ────────────────────────────────────────────────────────────────── */

	/** The person a case belongs to: the stub's salesperson (as written, else the asking app's pick), or a won job's. 0 if none. */
	public static function referral_salesperson( array $c ) {
		if ( 'install' === $c['stage'] ) {
			return (int) $c['seller_user_id'];
		}
		$ini = '' !== (string) $c['written_initials'] ? (string) $c['written_initials'] : (string) $c['default_initials'];
		$ini = explode( ' & ', $ini )[0];   // a pair: the first person
		return (int) zter_user_by_initials( $ini );
	}

	/** May this person see (and work) this case? The admin tier, or the case's own salesperson. Never the kiosk. */
	public static function may_see_referral( $uid, array $c ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
			return false;
		}
		return zter_is_admin( $uid ) || ( zter_is_staff( $uid ) && self::referral_salesperson( $c ) === $uid );
	}

	/** One case as the verb answers it. */
	public static function referral_row( array $c, $abs = null ) {
		$abs = null === $abs ? ZTER_Kinds::abilities() : $abs;
		$sp  = self::referral_salesperson( $c );
		$zip = (string) $c['zip'];
		$place = '' !== $zip ? (string) apply_filters( 'zdz_zip_place', '', $zip ) : '';
		$items = array();
		foreach ( (array) $c['kinds'] as $k ) {
			$item    = explode( ':', (string) $k, 2 )[0];
			$items[] = array( 'key' => $item, 'label' => ZTER_Kinds::stored_label( $k, $abs ) );
		}
		$project = '';
		if ( 'install' === $c['stage'] && preg_match( '/^project-([A-Za-z0-9_\-]{1,40})$/', (string) $c['stub_key'], $m ) ) {
			$project = $m[1];
		}
		return array(
			'id' => (int) $c['id'], 'stage' => $c['stage'], 'mode' => 2 === (int) $c['refer'] ? 'live' : 'shadow', 'test' => (bool) $c['is_test'],
			'zip' => $zip, 'place' => substr( sanitize_text_field( $place ), 0, 60 ), 'items' => $items, 'created' => (string) $c['created_at'],
			'reference' => (string) $c['reference'], 'estimate_number' => (string) $c['estimate_number'], 'project' => $project,
			'salesperson' => array( 'user_id' => $sp, 'initials' => $sp > 0 ? zter_initials( $sp ) : '' ),
			'saved' => ! empty( $c['saved'] ),   // false: a stub the estimate app has not named yet (perhaps never saved)
			'closed' => '' !== $c['closed_at'] ? array( 'at' => $c['closed_at'], 'by' => zter_initials( (int) $c['closed_by'] ), 'how' => $c['closed_how'] ) : null,
		);
	}

	/**
	 * args: { closed: bool (also the closed ones, newest first), id: int (one case) }
	 * → { success, verb, generation, shaped_for: admin|staff, cases[] }
	 */
	public static function uncovered( array $args ) {
		$v = 'territories.uncovered';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		if ( ! $admin && ! zter_is_staff( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'not_allowed', 'error' => __( 'That is not available on your account.', 'zorderz' ), 'verb' => $v );
		}
		$cases = ZTER_DB::referral_cases( empty( $args['closed'] ) );
		if ( null === $cases ) {
			return self::fail( $v, __( 'The cases could not be read just now.', 'zorderz' ) );
		}
		$one = is_scalar( $args['id'] ?? null ) ? (int) $args['id'] : 0;
		$abs = ZTER_Kinds::abilities();
		$out = array();
		foreach ( $cases as $c ) {
			if ( ( $one > 0 && $one !== $c['id'] ) || ! ( $admin || self::may_see_referral( $uid, $c ) ) ) {
				continue;
			}
			$out[] = self::referral_row( $c, $abs );
		}
		return array( 'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(), 'shaped_for' => $admin ? 'admin' : 'staff', 'cases' => $out );
	}

	/* ── territories.preview_route ────────────────────────────────────────────────────────────── */

	/**
	 * The estimate app's re-assign batch: who would the LIVE rules give each of these existing stubs? Never
	 * recorded, never a turn stored (route_for records and takes a shadow turn; this does neither). While every rule
	 * is in shadow each row keeps its default and says so.
	 *
	 * $args: stubs [ { ref, zip, reference, lines[], default_initials, test }, … ] (at most 200). An empty list is
	 * allowed: it answers the generation, which the asking app compares before it writes anything.
	 * → { success, verb, generation, shaped_for: 'admin', rules_version, live_rules, rows[] } (see ZTER_Rules::preview).
	 * The admin tier may already read every decision and trail in this app, so the rule's sentence and one line of
	 * why travel with each row; candidates' Team Roles levels and notes do not.
	 */
	public static function preview_route( array $args ) {
		$v = 'territories.preview_route';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		if ( ! $admin || ! zter_is_admin( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'not_allowed', 'error' => __( 'Only the admin tier can preview re-assigning stubs.', 'zorderz' ), 'verb' => $v );
		}
		$stubs = $args['stubs'] ?? array();
		if ( ! is_array( $stubs ) ) {
			return self::fail( $v, __( 'stubs must be a list.', 'zorderz' ) );
		}
		if ( count( $stubs ) > ZTER_Rules::MAX_PREVIEW ) {
			return self::fail( $v, sprintf( 'At most %d stubs at a time.', ZTER_Rules::MAX_PREVIEW ) );
		}
		try {
			$p = ZTER_Rules::preview( $stubs, ! empty( $args['pairs'] ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Territories: preview_route failed - ' . $e->getMessage() );
			return self::fail( $v, __( 'Who gets these could not be worked out just now.', 'zorderz' ) );
		}
		return array( 'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(), 'shaped_for' => 'admin' ) + $p;
	}

	/* ── territories.installer_for ────────────────────────────────────────────────────────────── */

	/**
	 * $args: zip, reference, lines[] (the job's lines: never the customer), seller_user_id (the job's salesperson, for
	 * "the salesperson first"), key (the asker's own name for the job, e.g. a Project id: the same job asked again gets
	 * the same decision and takes no second turn), test.
	 * → { success, verb, generation, mode, user_id, initials, name, why, others[ {user_id, initials, name} ], decision_id }
	 * A suggestion only from a LIVE install rule (mode 'live'); in shadow user_id is 0 and nothing is suggested.
	 * `others` are the people the deciding live rule also found able, for "Another"; `why` is one plain sentence.
	 *
	 * `pairs: true` - a live rule that hands the rest of the work on ("<item> -> one person, and a second installer
	 * for the rest") answers two installers in `people`, each with the items they cover, why, and their own others.
	 * The top-level fields stay the first person's. Without `pairs` such a job gets no suggestion (the asker shows
	 * one installer). Confirm each with do_action( 'zjob_installer_confirmed', decision, user, job, part ).
	 */
	public static function installer_for( array $args ) {
		$v = 'territories.installer_for';
		$a = self::asker( $args, $v );
		if ( isset( $a['success'] ) ) {
			return $a;
		}
		list( $uid, $admin ) = $a;
		if ( ! $admin && ! zter_is_staff( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'not_allowed', 'error' => __( 'That is not available on your account.', 'zorderz' ), 'verb' => $v );
		}
		$lines = $args['lines'] ?? array();
		if ( ! is_array( $lines ) ) {
			return self::fail( $v, __( 'lines must be a list.', 'zorderz' ) );
		}
		try {
			list( $answer, $rec ) = ZTER_Rules::route( array(
				'stage' => 'install', 'zip' => $args['zip'] ?? '', 'reference' => $args['reference'] ?? '', 'lines' => array_slice( $lines, 0, self::MAX_LINES ),
				'seller_user_id' => $args['seller_user_id'] ?? ( $args['seller'] ?? 0 ), 'test' => ! empty( $args['test'] ), 'label' => '',
				'key' => is_scalar( $args['key'] ?? null ) ? (string) $args['key'] : '', 'asker_id' => $uid,
				'pairs' => ! empty( $args['pairs'] ),
			) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Territories: installer_for failed - ' . $e->getMessage() );
			return self::fail( $v, __( 'Who should do this could not be worked out just now.', 'zorderz' ) );
		}
		if ( empty( $rec['id'] ) ) {
			return self::fail( $v, __( 'The decision could not be recorded, so none was made.', 'zorderz' ) );
		}
		$others = array();
		foreach ( (array) ( $rec['live_able'] ?? array() ) as $o ) {
			$ou = get_userdata( (int) $o );
			if ( $ou && zter_assignable( $o ) ) {
				$others[] = array( 'user_id' => (int) $o, 'initials' => zter_initials( $o ), 'name' => (string) $ou->display_name );
			}
		}
		$why = '';
		if ( 'live' === $answer['mode'] && $answer['user_id'] > 0 ) {
			$rules = ZTER_Rules::get( 'install' );
			$no    = ZTER_Rules::number_of( $rules, (string) $rec['live_rule_id'] );
			$why   = $no > 0 ? 'Territories, install rule ' . $no . ': ' . ZTER_Rules::sentence( $rules['rows'][ $no - 1 ], null, 'install' ) : '';
		}
		$out = array(
			'success' => true, 'verb' => $v, 'generation' => ZTER_DB::generation(), 'mode' => $answer['mode'],
			'user_id' => (int) $answer['user_id'], 'initials' => 'live' === $answer['mode'] ? (string) $answer['initials'] : '',
			'name' => (string) $answer['name'], 'why' => $why, 'others' => $others, 'decision_id' => (int) $answer['decision_id'],
		);
		// two installers. Only for an asker that said `pairs` (route() answers no one otherwise) and a live answer.
		$ppl = array_values( (array) ( $rec['live_people'] ?? array() ) );
		if ( ! empty( $args['pairs'] ) && 'live' === $answer['mode'] && (int) ( $ppl[0] ?? 0 ) > 0 ) {
			$parts = self::install_parts( $rec, $lines, $others );
			// two installers; or the deciding rule hands the rest on and nobody below can take it: the rest is shown as
			// its own part with no one suggested (the Jobs app lists its usual people there), never folded into the
			// first person's. "Nobody for the rest" only when the rules really found nobody (rest_open), never because
			// a rule below picked the first person again
			if ( ( 2 === count( $ppl ) && (int) $ppl[1] > 0 ) || ( $parts[1]['items'] && ! empty( $rec['rest_open'] ) ) ) {
				$out['initials'] = zter_initials( (int) $ppl[0] );   // the first person's; the pair is in people[]
				$out['people']   = $parts;
			}
		}
		return $out;
	}


	/**
	 * the two parts of a won job handed on: part 0 the deciding rule's items and its person, part 1 the rest of
	 * the named work and the second installer (picked by the rules below it). Items are Team Roles item keys, with labels.
	 */
	private static function install_parts( array $rec, array $lines, array $others0 ) {
		$rules = ZTER_Rules::get( 'install' );
		$abs   = ZTER_Kinds::abilities();
		$rids  = explode( '+', (string) $rec['live_rule_id'] );
		$first = null;
		foreach ( $rules['rows'] as $r ) {
			if ( $r['id'] === ( $rids[0] ?? '' ) ) {
				$first = $r;
			}
		}
		$kinds = (array) ( ZTER_Kinds::classify( array_slice( $lines, 0, self::MAX_LINES ) )['kinds'] ?? array() );
		$own   = $first ? array_values( array_intersect( $kinds, (array) $first['kinds'] ) ) : array();
		$rest  = ! empty( $rec['rest_open'] ) ? array_values( (array) $rec['rest_open'] ) : array_values( array_diff( $kinds, $own ) );
		$ppl   = array_values( (array) $rec['live_people'] );
		$out   = array();
		foreach ( array( 0, 1 ) as $n ) {
			$uid = (int) ( $ppl[ $n ] ?? 0 );
			$u   = get_userdata( $uid );
			$no  = ZTER_Rules::number_of( $rules, (string) ( $rids[ $n ] ?? '' ) );
			$its = 0 === $n ? $own : $rest;
			$oth = $others0;
			if ( 1 === $n ) {
				$oth = array();
				foreach ( (array) ( $rec['live_able2'] ?? array() ) as $o ) {
					$ou = get_userdata( (int) $o );
					if ( $ou && zter_assignable( $o ) && (int) $o !== $uid ) {
						$oth[] = array( 'user_id' => (int) $o, 'initials' => zter_initials( $o ), 'name' => (string) $ou->display_name );
					}
				}
			}
			$out[] = array(
				'part' => $n, 'user_id' => ( $uid > 0 && $u ) ? $uid : 0, 'initials' => $uid > 0 ? zter_initials( $uid ) : '', 'name' => ( $uid > 0 && $u ) ? (string) $u->display_name : '',
				'items' => $its, 'labels' => array_map( function ( $k ) use ( $abs ) { return ZTER_Kinds::label( $k, $abs ); }, $its ),
				'why' => $no > 0 && $uid > 0 ? 'Territories, install rule ' . $no . ': ' . ZTER_Rules::sentence( $rules['rows'][ $no - 1 ], null, 'install' )
					: ( $uid > 0 ? '' : 'Territories: no won-jobs rule below names someone who can install this here.' ),
				'others' => $oth,
			);
		}
		return $out;
	}
}
