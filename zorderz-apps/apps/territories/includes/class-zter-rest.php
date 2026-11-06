<?php
/**
 * ZTER_REST — {ZDZ_REST_NS}/territories/*, the app's own API. Every permission decision is here; app.js draws
 * what it is sent.
 *
 *   who                        sees                                                     changes
 *   admin tier                 every territory, rule, zone, word, decision, the history   everything
 *   reviewer (named on page)   every territory, rule and decision, and why                 a note on a decision
 *   salesperson / team member  their own ZIPs (others' outlines only if allowed)           nothing
 *   the shared device          refused (403)                                               refused
 *
 * Team Roles' `why` (it can carry an admin's note) is fetched live, per person, and only when Team Roles' own
 * rule says the viewer may see that person's row (zro_can_view). It is never stored here.
 *
 * Two lists of rules, one per stage. `stage=stub` (the default) is who gets a new estimate stub; `stage=install`
 * is who does a won job, suggested to its salesperson. Every rules, checklist, decisions, try and tests call
 * takes it; an unknown stage is the stub stage.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_REST {

	const BASE = '/territories';

	/** The platform's REST namespace. */
	public static function ns() {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function me() {
		return (int) get_current_user_id();
	}

	public static function perm_team() {
		$u = self::me();
		return $u > 0 && ! zter_is_kiosk( $u ) && zter_is_team_member( $u ) && zter_user_can_use( $u );
	}

	public static function perm_admin() {
		$u = self::me();
		return $u > 0 && zter_is_admin( $u );
	}

	public static function perm_review() {
		$u = self::me();
		return $u > 0 && zter_can_review( $u ) && ( zter_is_admin( $u ) || zter_user_can_use( $u ) ); // app access taken away = no access
	}

	public static function routes() {
		$r = function ( $path, $method, $cb, $perm ) {
			register_rest_route( self::ns(), self::BASE . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => array( __CLASS__, $perm ) ) );
		};
		$r( '/state', 'GET', 'state', 'perm_team' );
		$r( '/zips', 'POST', 'save_zips', 'perm_admin' );
		$r( '/rules', 'POST', 'save_rules', 'perm_admin' );
		$r( '/rules/(?P<id>[a-z0-9_]{1,24})/live', 'POST', 'set_live', 'perm_admin' ); // one rule at a time
		$r( '/checklist', 'GET', 'checklist', 'perm_review' );                           // the go-live checklist
		$r( '/words', 'POST', 'save_words', 'perm_admin' );
		$r( '/settings', 'POST', 'save_settings', 'perm_admin' );
		$r( '/zones', 'POST', 'save_zones', 'perm_admin' );
		$r( '/rules/revert', 'POST', 'revert_rules', 'perm_admin' );                      // put an earlier version back
		$r( '/decisions', 'GET', 'decisions', 'perm_review' );
		$r( '/why', 'GET', 'why', 'perm_review' );
		$r( '/decisions/(?P<id>\d+)/note', 'POST', 'note', 'perm_review' );
		$r( '/try', 'POST', 'try_stub', 'perm_review' );
		$r( '/tests', 'POST', 'run_tests', 'perm_admin' );
		$r( '/tests', 'DELETE', 'clear_tests', 'perm_admin' );
		$r( '/history', 'GET', 'history', 'perm_admin' );
		$r( '/asks', 'GET', 'asks', 'perm_admin' );                                         // Shadow -> Last asked
	}

	/** The stage a request asks about: 'install' or (anything else) 'stub'. */
	private static function stage( WP_REST_Request $req ) {
		return ZTER_Rules::stage( $req->get_param( 'stage' ) );
	}

	private static function err( $msg, $status = 400 ) {
		return new WP_Error( 'zter', $msg, array( 'status' => $status ) );
	}

	/* ── state ────────────────────────────────────────────────────────────────────────────────────────── */

	public static function state( WP_REST_Request $req ) {
		$me     = self::me();
		$admin  = zter_is_admin( $me );
		$review = zter_can_review( $me );
		$set    = ZTER_DB::settings();
		$all    = ZTER_DB::all_zips();
		if ( null === $all ) {
			return self::err( __( 'The territories could not be read just now.', 'zorderz' ), 500 );
		}
		$people = array();
		foreach ( zter_team() as $p ) {
			$z    = $all[ $p['id'] ] ?? array();
			$mine = $p['id'] === $me;
			if ( $review || $mine ) {
				$people[] = array( 'id' => $p['id'], 'name' => $p['name'], 'initials' => $p['initials'], 'color' => zter_color( $p['id'] ), 'ink' => zter_ink( $p['id'] ), 'zips' => $z, 'me' => $mine );
			} elseif ( $set['peers_visible'] && $z ) {
				$people[] = array( 'id' => 0, 'name' => '', 'initials' => $p['initials'], 'color' => zter_color( $p['id'] ), 'ink' => zter_ink( $p['id'] ), 'zips' => $z, 'me' => false );
			}
		}
		$out = array(
			'canEdit' => $admin, 'canReview' => $review, 'me' => $me, 'people' => $people,
			'generation' => ZTER_DB::generation(), 'version' => ZTER_VERSION,
			// the zones this person may see (admin-only ones for the admin tier alone)
			// staff as the registry ranks it (sales, the operator, the shop); a tech sees no zones, as in the verbs
			// no-go zones too, for the admin tier only (they edit them here; the map verbs never give them out)
			'zones' => ZTER_Zones::visible_to( $admin, zter_is_staff( $me ), true ),
			'zonesVersion' => ZTER_Zones::all()['version'],
		);
		$known = ZTER_Kinds::abilities();
		foreach ( $out['zones'] as &$z ) {   // the kinds of work in words, for those who don't get the abilities list
			$z['kinds'] = array_map( function ( $k ) use ( $known ) { return (string) ( $known[ $k ]['label'] ?? $k ); }, $z['abilities'] );
		}
		unset( $z );
		if ( ! $review ) {
			return $out;
		}
		$abs   = ZTER_Kinds::abilities();
		$lists = array();
		foreach ( ZTER_Rules::STAGES as $st ) {
			$lists[ $st ] = ZTER_Rules::get( $st );
			foreach ( $lists[ $st ]['rows'] as $i => &$r ) {
				$r['no']       = $i + 1;
				$r['sentence'] = ZTER_Rules::sentence( $r, $abs, $st );
			}
			unset( $r );
		}
		$work = array();
		foreach ( (array) $abs as $k => $a ) {
			if ( 'work' === $a['group'] && empty( $a['retired'] ) ) {
				$work[] = array( 'key' => $k, 'label' => $a['label'], 'type' => $a['type'] );
			}
		}
		$items = ZTER_Kinds::item_list();
		$out  += array(
			'rules'        => $lists['stub'],
			'installRules' => $lists['install'],                // who installs a won job
			'items'        => null === $abs ? null : $items,    // what the rules and the words table name
			'words'        => ZTER_Kinds::rows(),
			'abilities'    => null === $abs ? null : $work,      // the kinds of work, for the zones that still name them
			'types'        => self::types(),
			'teamRoles'    => self::team_roles_state( $items, null !== $abs ),
			'jobs'         => class_exists( 'ZJOB_Project_Types' ) && method_exists( 'ZJOB_Project_Types', 'types_for_line' ),
			'catalog'      => class_exists( 'ZDZ_Item_Engine' ),
		);
		// Initials two accounts share. Names (a former employee's included) for the admin tier.
		$dups = zter_duplicate_initials();
		$out['duplicates'] = $admin ? $dups : array_map( function ( $d ) { return array( 'initials' => $d['initials'], 'people' => array(), 'blocks' => $d['blocks'], 'count' => count( $d['people'] ) ); }, $dups );
		if ( $admin ) {
			$out['settings']     = $set;
			$out['team']         = zter_team();
			$out['userAdminUrl'] = zter_user_admin_url();
		}
		return $out;
	}

	/** The kinds of project a words-table row may name (the Jobs app's list). */
	private static function types() {
		$out = array();
		foreach ( ZTER_Kinds::types() as $k => $label ) {
			$out[] = array( 'key' => (string) $k, 'label' => (string) $label );
		}
		return $out;
	}

	/**
	 * Is Team Roles here, does it have items, and has anyone been marked Can to sell
	 * and to install anything yet?
	 */
	private static function team_roles_state( array $items, $active ) {
		if ( ! $active ) {
			return array( 'active' => false, 'items' => false, 'anyCan' => false, 'anySell' => false, 'anyInstall' => false );
		}
		$any = array( 'sell' => false, 'install' => false );
		foreach ( $items as $it ) {
			foreach ( array( 'sell', 'install' ) as $role ) {
				if ( $any[ $role ] || ! in_array( $role, $it['roles'], true ) ) {
					continue;
				}
				$who = apply_filters( 'zdz_people_who_can', null, $it['key'] . '__' . $role, array() );
				foreach ( is_array( $who ) ? $who : array() as $w ) {
					$any[ $role ] = $any[ $role ] || 'can' === ( $w['level'] ?? '' );
				}
			}
		}
		return array( 'active' => true, 'items' => (bool) $items, 'anyCan' => $any['sell'] || $any['install'], 'anySell' => $any['sell'], 'anyInstall' => $any['install'] );
	}

	/* ── edits (admin tier) ───────────────────────────────────────────────────────────────────────────── */

	public static function save_zips( WP_REST_Request $req ) {
		$uid = (int) $req->get_param( 'user_id' );
		if ( ! zter_is_team_member( $uid ) ) {
			return self::err( __( 'That person is not on the team.', 'zorderz' ) );
		}
		list( $ok, $bad ) = ZTER_DB::parse_zips( $req->get_param( 'zips' ) );
		if ( $bad ) {
			return self::err( sprintf( __( 'These are not five-digit ZIP codes: %s. Nothing was saved.', 'zorderz' ), implode( ', ', $bad ) ) );
		}
		if ( count( $ok ) > ZTER_DB::MAX_ZIPS_PER_PERSON ) {
			return self::err( sprintf( __( 'At most %d ZIP codes per person.', 'zorderz' ), ZTER_DB::MAX_ZIPS_PER_PERSON ) );
		}
		list( $add, $del ) = ZTER_DB::set_zips( $uid, $ok, self::me() );
		return array( 'ok' => true, 'user_id' => $uid, 'zips' => ZTER_DB::zips_of( $uid ), 'added' => $add, 'removed' => $del, 'generation' => ZTER_DB::generation() );
	}

	/** The whole list of zones (the admin tier). */
	public static function save_zones( WP_REST_Request $req ) {
		$v = $req->get_param( 'version' );
		$r = ZTER_Zones::save( $req->get_param( 'rows' ), self::me(), is_numeric( $v ) ? (int) $v : null );
		if ( is_wp_error( $r ) ) {
			return self::err( $r->get_error_message(), 'zter_zone_stale' === $r->get_error_code() ? 409 : 400 );
		}
		return array( 'ok' => true, 'version' => $r['version'], 'zones' => $r['rows'], 'generation' => ZTER_DB::generation() );
	}

	public static function save_rules( WP_REST_Request $req ) {
		$rows = $req->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			return self::err( __( 'No rules were sent.', 'zorderz' ) );
		}
		// the editor never changes a rule's mode; each keeps the one it has (a new rule starts in shadow).
		// Going live is set_live(), one rule at a time from the checklist.
		$st = self::stage( $req );
		// the whole list is replaced, so the page must name the version it loaded. A page
		// opened before an upgrade or before someone else's
		// change is refused, never allowed to undo it.
		$v = $req->get_param( 'version' );
		if ( ! is_numeric( $v ) || (int) $v !== (int) ZTER_Rules::get( $st )['version'] ) {
			return self::err( __( 'The rules changed since this page opened (or the app was updated). Reload, then make your change again.', 'zorderz' ), 409 );
		}
		$r  = ZTER_Rules::save( ZTER_Rules::keep_modes( $rows, $st ), self::me(), $st );
		if ( is_wp_error( $r ) ) {
			return self::err( $r->get_error_message() );
		}
		return array( 'ok' => true, 'version' => $r['version'], 'stage' => $st );
	}

	/** Turn one rule live or back to shadow, after the app's own confirmation sheet. */
	public static function set_live( WP_REST_Request $req ) {
		$live = rest_sanitize_boolean( $req->get_param( 'live' ) ); // "false" as text is false
		$r    = ZTER_Rules::set_live( (string) $req['id'], $live, (int) $req->get_param( 'version' ), self::me(), self::stage( $req ) );
		if ( is_wp_error( $r ) ) {
			$d = $r->get_error_data();
			return self::err( $r->get_error_message(), (int) ( $d['status'] ?? 400 ) );
		}
		return array( 'ok' => true, 'version' => $r['version'], 'no' => $r['no'], 'live' => $live, 'at' => $r['updated_at'], 'by' => zter_initials( self::me() ), 'stage' => self::stage( $req ) );
	}

	/**
	 * Put an earlier version of a stage's rules back, as a new version (the admin tier). Each rule keeps the mode
	 * it has now: a revert never turns a rule live. `version` is the version the page was looking at.
	 */
	public static function revert_rules( WP_REST_Request $req ) {
		$st = self::stage( $req );
		$r  = ZTER_Rules::revert( $st, (int) $req->get_param( 'to' ), (int) $req->get_param( 'version' ), self::me() );
		if ( is_wp_error( $r ) ) {
			$d = $r->get_error_data();
			return self::err( $r->get_error_message(), (int) ( $d['status'] ?? 400 ) );
		}
		return array( 'ok' => true, 'version' => (int) $r['version'], 'stage' => $st );
	}

	/**
	 * the go-live checklist, per rule: how many real (not test) stubs it decided in shadow in the last 7 days,
	 * how many of those differ from what happened (what the estimate app wrote, or the person a reviewer's note says it went
	 * to), how many it answered live, and the people it can choose with the kinds of work Team Roles marks them Can
	 * for. Plus the initials two accounts share (the estimate app then keeps its own pick for that person), and when each
	 * rule last went live or back to shadow, and by whom.
	 */
	public static function checklist( WP_REST_Request $req ) {
		$admin = zter_is_admin( self::me() );
		$st    = self::stage( $req );
		$rules = ZTER_Rules::get( $st );
		$abs   = ZTER_Kinds::abilities();
		$all   = ZTER_DB::all_zips();
		if ( null === $all ) {
			return self::err( __( 'The territories could not be read just now.', 'zorderz' ), 500 );
		}
		// who Team Roles marks Can, per item, for the role this stage asks about (Sell for a new stub; Install,
		// or Repair for an item with no Install, for a won job). One ask per item.
		$work  = array();
		$can   = array();
		$items = ZTER_Kinds::item_list();
		foreach ( $items as $it ) {
			$role = ZTER_Kinds::role_for( $it['key'], 'new', $st );
			if ( '' === $role ) {
				continue;
			}
			$work[ $it['key'] ] = $it['label'];
			$who = apply_filters( 'zdz_people_who_can', null, $it['key'] . '__' . $role, array() );
			foreach ( is_array( $who ) ? $who : array() as $w ) {
				if ( 'can' === ( $w['level'] ?? '' ) ) {
					$can[ (int) $w['user_id'] ][] = $it['key'];
				}
			}
		}
		$tr_on = null !== $abs;
		$dups  = zter_duplicate_initials();
		$dupi  = array();
		foreach ( $dups as $d ) {
			$dupi[ $d['initials'] ] = true;
		}
		// The last 7 days, real stubs (or won jobs) only.
		$week  = array();
		$bump  = function ( $id, $what ) use ( &$week ) {
			if ( ! isset( $week[ $id ] ) ) {
				$week[ $id ] = array( 'decided' => 0, 'differ' => 0, 'live' => 0 );
			}
			$week[ $id ][ $what ]++;
		};
		foreach ( ZTER_DB::decisions( 7, false, 1000, $st ) as $d ) {
			$id = (string) $d['rule_id'];
			$bump( $id, 'decided' );
			// what happened: a reviewer's note, else who the salesperson confirmed (a won job), else what the estimate app wrote
			$happened = $d['note_to'] > 0 ? zter_initials( $d['note_to'] ) : ( $d['confirmed_user_id'] > 0 ? zter_initials( $d['confirmed_user_id'] ) : ZTER_Notify::norm( $d['answered_initials'] ) );
			$would    = ZTER_Notify::norm( $d['chosen_initials'] );
			if ( '' !== $would && '' !== $happened && $would !== $happened ) {
				$week[ $id ]['differ']++;
			}
			foreach ( array_filter( explode( '+', (string) $d['live_rule_id'] ) ) as $lid ) { // a pair answered by two rules
				$bump( $lid, 'live' );
			}
		}
		// When each rule last changed mode, and by whom.
		$when = array();
		$hist = ZTER_DB::history( 400, 0, 'live' )['rows'];
		foreach ( $hist as $h ) {
			$dd  = $h['detail'];
			$rid = is_array( $dd ) ? (string) ( $dd['rule'] ?? '' ) : '';
			if ( '' !== $rid && ! isset( $when[ $rid ] ) && ZTER_Rules::stage( $dd['stage'] ?? 'stub' ) === $st ) {
				$when[ $rid ] = array( 'at' => (string) $h['changed_at'], 'by' => zter_initials( (int) $h['changed_by'] ) ?: '#' . (int) $h['changed_by'], 'live' => ! empty( $dd['live'] ) );
			}
		}
		$team = array();
		foreach ( zter_team() as $p ) {
			$team[] = (int) $p['id'];
		}
		$out = array();
		$has_service = false;
		foreach ( ZTER_Zones::all()['rows'] as $z ) {
			$has_service = $has_service || 'service' === $z['kind'];
		}
		foreach ( $rules['rows'] as $i => $r ) {
			$pool = array();
			if ( 'territory' === $r['who'] ) {
				foreach ( $all as $uid => $zips ) {
					if ( zter_is_team_member( $uid ) && ( ! $r['zips'] || array_intersect( $r['zips'], $zips ) ) ) {
						$pool[] = (int) $uid;
					}
				}
			} elseif ( 'person' === $r['who'] ) {
				$pool[] = (int) $r['person'];
			} elseif ( 'anyone' === $r['who'] ) {
				$pool = $team; // anyone Team Roles marks Can
			}
			$people = array();
			$able   = 0;
			$hidden = 0;
			$kinds  = $r['kinds'] ? $r['kinds'] : array_keys( $work );
			foreach ( $pool as $uid ) {
				$ini   = zter_initials( $uid );
				$mine  = array_values( array_intersect( $kinds, $can[ $uid ] ?? array() ) );
				$ok    = zter_assignable( $uid ) && ( ! $r['need_can'] || ( $tr_on && $mine ) );
				if ( 'anyone' === $r['who'] && ! $mine ) {
					continue; // "anyone" lists only the people it could really choose
				}
				$able += $ok ? 1 : 0;
				$u     = get_userdata( $uid );
				// Team Roles' levels only where Team Roles would show that row: a reviewer outside the
				// admin tier sees who the rule can choose, not what each person is marked for.
				$show  = $admin || zter_can_see_roles_row( self::me(), $uid );
				if ( 'anyone' === $r['who'] && ! $show ) {
					$hidden++; // "anyone" lists only people marked Can: naming them would tell what Team Roles hides
					continue;
				}
				$people[] = array(
					'id' => $uid, 'name' => $u ? (string) $u->display_name : '', 'initials' => $ini, 'color' => zter_color( $uid ), 'ink' => zter_ink( $uid ),
					'can' => $show ? array_map( function ( $k ) use ( $work ) { return $work[ $k ] ?? $k; }, $mine ) : array(), 'hidden' => ! $show,
					'assignable' => zter_assignable( $uid ), 'able' => $show ? $ok : null, 'dup' => '' !== $ini && isset( $dupi[ $ini ] ),
				);
			}
			usort( $people, function ( $a, $b ) { return strcmp( $a['initials'], $b['initials'] ); } );
			$warn = array();
			if ( $r['need_can'] && ! $tr_on ) {
				$warn[] = 'Team Roles is not active, so this rule cannot check "can do" and never matches.';
			} elseif ( $r['need_can'] && ! $items ) {
				$warn[] = 'Team Roles has no items to sell, install and repair yet (the catalog is empty, or older marks are still to be moved), so this rule never matches.';
			}
			if ( $admin && in_array( $r['who'], array( 'territory', 'person', 'anyone' ), true ) && ! $able ) { // follows from Team Roles' marks: admin tier only
				$what = 'install' === $st ? 'install' : 'sell';
				$warn[] = 'Nobody this rule could choose can be given work yet' . ( $r['need_can'] ? ' (marked Can to ' . $what . ' in Team Roles, with initials)' : ' (with initials)' ) . ', so it would never decide anything.';
			}
			foreach ( $people as $p ) {
				if ( $p['dup'] ) {
					$warn[] = 'The initials ' . $p['initials'] . ' are held by more than one account, so the estimate app keeps its own pick whenever this rule picks ' . $p['initials'] . '. Fix them in User Management.';
				}
				if ( ! $p['assignable'] ) {
					$warn[] = $p['name'] . ' has no initials in User Management, so this rule skips them.';
				}
			}
			// A rule above it still in shadow is skipped for live answers: a stub it would catch goes to this
			// rule's pick once this one is live. Say so before anyone turns it on.
			if ( empty( $r['live'] ) && empty( $r['fallback'] ) && 'default' !== $r['who'] ) {
				foreach ( array_slice( $rules['rows'], 0, $i ) as $j => $above ) {
					if ( empty( $above['live'] ) ) {
						$warn[] = 'Rule ' . ( $j + 1 ) . ' above it is still in shadow, so live answers skip it: ' . ( 'install' === $st ? 'a won job' : 'a stub' ) . ' it would catch ("' . ZTER_Rules::sentence( $above, $abs, $st ) . '") gets this rule\'s pick instead. Turn rule ' . ( $j + 1 ) . ' live first if it should still decide those.';
					}
				}
			}
			if ( 'refer' === $r['who'] && ! $has_service ) {
				$warn[] = 'There are no service zones yet, so nothing is outside every service area and this rule never decides.';
			}
			if ( ! empty( $r['with_rest'] ) ) {
				$warn[] = 'install' === $st
					? 'With other work on the job, this rule suggests two installers ("' . ( zter_initials( (int) $r['person'] ) ?: '?' ) . ' & …", the second from the rules below it). An asking app that shows one installer gets no suggestion for those jobs.'
					: 'With other work on the stub, this rule writes two people ("' . ( zter_initials( (int) $r['person'] ) ?: '?' ) . ' & …"). An asking app that writes one person keeps its own pick for those stubs.';
			}
			$w = $week[ $r['id'] ] ?? array( 'decided' => 0, 'differ' => 0, 'live' => 0 );
			$out[] = array(
				'id' => $r['id'], 'no' => $i + 1, 'sentence' => ZTER_Rules::sentence( $r, $abs, $st ), 'live' => ! empty( $r['live'] ), 'fallback' => ! empty( $r['fallback'] ),
				'who' => $r['who'], 'decided' => $w['decided'], 'differ' => $w['differ'], 'answeredLive' => $w['live'],
				'people' => $people, 'hiddenPeople' => $hidden, 'able' => $admin ? $able : count( $people ), 'warnings' => array_values( array_unique( $warn ) ),
				'changed' => $when[ $r['id'] ] ?? null,
			);
		}
		return array(
			'stage' => $st, 'version' => (int) $rules['version'], 'days' => 7, 'rules' => $out,
			// Names of every account sharing initials (former employees included): the admin tier only.
			'duplicates' => $admin ? $dups : array_map( function ( $d ) { return array( 'initials' => $d['initials'], 'people' => array(), 'blocks' => $d['blocks'], 'count' => count( $d['people'] ) ); }, $dups ),
			'teamRoles' => array( 'active' => $tr_on, 'items' => (bool) $items ),
			'userAdminUrl' => $admin ? zter_user_admin_url() : '',
		);
	}

	public static function save_words( WP_REST_Request $req ) {
		$rows = $req->get_param( 'rows' );
		if ( ! is_array( $rows ) || ! $rows ) {
			return self::err( __( 'The words table needs at least one row.', 'zorderz' ) );
		}
		$r = ZTER_Kinds::save( $rows, self::me() );
		if ( is_wp_error( $r ) ) {
			return self::err( $r->get_error_message() );
		}
		return array( 'ok' => true, 'rows' => $r );
	}

	public static function save_settings( WP_REST_Request $req ) {
		$s = ZTER_DB::save_settings( array( 'peers_visible' => (bool) $req->get_param( 'peers_visible' ), 'reviewers' => (array) $req->get_param( 'reviewers' ) ), self::me() );
		return array( 'ok' => true, 'settings' => $s );
	}

	/* ── decisions (admin tier and reviewers) ─────────────────────────────────────────────────────────── */

	/** A stage's rules list as it was at a version: the current one, or the history row that saved it ([] when neither). */
	public static function rules_at( $version, array &$memo, $stage = 'stub' ) {
		$stage = ZTER_Rules::stage( $stage );
		$mk    = $stage . ':' . (int) $version;
		if ( ! isset( $memo[ $mk ] ) ) {
			$rows        = ZTER_Rules::rows_at( $stage, (int) $version );
			$memo[ $mk ] = null === $rows ? array() : $rows;
		}
		return $memo[ $mk ];
	}

	public static function shape_row( array $d, array &$memo, $abs ) {
		$st       = ZTER_Rules::stage( $d['stage'] ?? 'stub' );
		$rules    = self::rules_at( $d['rule_version'], $memo, $st );
		$sentence = '';
		$no       = 0;
		foreach ( $rules as $i => $r ) {
			if ( ( $r['id'] ?? '' ) === $d['rule_id'] ) {
				$sentence = ZTER_Rules::sentence( ZTER_Rules::clean_rule( (array) $r, false, $st ), $abs, $st ); // an old saved rule in today's shape
				$no       = $i + 1;
				break;
			}
		}
		$cands  = array();
		$viewer = self::me();
		$whos   = array();
		foreach ( $rules as $r ) {
			$whos[ (string) ( $r['id'] ?? '' ) ] = (string) ( $r['who'] ?? '' );
		}
		$hidden = 0;
		foreach ( $d['candidates'] as $c ) {
			$lv   = array();
			$cu   = (int) ( $c['u'] ?? 0 );
			// A person's Team Roles levels only where Team Roles would show that row.
			$show = zter_is_admin( $viewer ) || zter_can_see_roles_row( $viewer, $cu );
			if ( ! $show && 'anyone' === ( $whos[ (string) ( $c['rule'] ?? '' ) ] ?? '' ) ) {
				$hidden++; // an "anyone" rule looks only at people marked Can: the name alone would say it
				continue;
			}
			foreach ( $show ? (array) ( $c['levels'] ?? array() ) : array() as $k => $l ) {
				$lv[] = array( 'key' => (string) $k, 'label' => ZTER_Kinds::label( $k, $abs ), 'level' => (string) $l );
			}
			$cands[] = array( 'rule' => (string) ( $c['rule'] ?? '' ), 'u' => $cu, 'ini' => (string) ( $c['ini'] ?? '' ), 'levels' => $lv, 'hidden' => ! $show && ! empty( $c['levels'] ) );
		}
		$notes = array();
		foreach ( $d['kind_notes'] as $n ) {
			$item    = (string) ( $n['item'] ?? $n['ability'] ?? '' );
			$notes[] = array( 'n' => (int) ( $n['n'] ?? 0 ), 'types' => array_values( (array) ( $n['types'] ?? array() ) ), 'repair' => ! empty( $n['repair'] ), 'ability' => $item, 'item' => $item, 'work' => (string) ( $n['work'] ?? '' ), 'label' => '' !== $item ? ZTER_Kinds::label( $item, $abs ) : '', 'by' => (string) ( $n['by'] ?? '' ) );
		}
		$people = function ( $ids, $ini ) {
			$ini = explode( ' & ', (string) $ini );
			$out = array();
			foreach ( array_values( (array) $ids ) as $n => $p ) {
				$out[] = array( 'id' => (int) $p, 'initials' => (string) ( $ini[ $n ] ?? ( (int) $p > 0 ? zter_initials( (int) $p ) : '' ) ) );
			}
			return $out;
		};
		return array(
			'id' => $d['id'], 'at' => $d['created_at'], 'test' => (bool) $d['is_test'], 'label' => $d['label'], 'stage' => $st,
			'seller' => ! empty( $d['seller_user_id'] ) ? zter_initials( (int) $d['seller_user_id'] ) : '',
			'asker' => zter_initials( $d['asker_id'] ), 'zip' => $d['zip'], 'zipFrom' => $d['zip_from'],
			'kinds' => array_map( function ( $k ) use ( $abs ) { return array( 'key' => $k, 'label' => ZTER_Kinds::stored_label( $k, $abs ) ); }, $d['kinds'] ),
			'kindState' => $d['kind_state'], 'lines' => $notes,
			'ruleId' => $d['rule_id'], 'ruleNo' => $no, 'ruleVersion' => $d['rule_version'], 'sentence' => $sentence,
			'default' => $d['default_initials'], 'would' => $d['chosen_initials'], 'wouldId' => $d['chosen_user_id'],
			'liveRuleNo' => '' !== (string) ( $d['live_rule_id'] ?? '' ) ? ZTER_Rules::number_of( ZTER_Rules::get( $st ), $d['live_rule_id'] ) : 0,
			'wouldPeople' => $people( $d['chosen_people'] ?? array(), $d['chosen_initials'] ), 'livePeople' => $people( $d['live_people'] ?? array(), $d['answered_initials'] ), // a pair
			'answered' => $d['answered_initials'], 'mode' => $d['mode'], 'teamRoles' => $d['team_roles'],
			'candidates' => $cands, 'hiddenCandidates' => $hidden, 'trail' => self::trail_for( $d['trail'], zter_is_admin( $viewer ) ),
			'refer' => (int) ( $d['refer'] ?? 0 ), // 1 shadow, 2 live: outside every service area
			'applied' => $d['applied_at'], 'doc' => $d['applied_doc'], 'notice' => $d['notice'],
			'confirmed' => self::confirmed_words( $d ), // the installer the salesperson said Yes (or Another) to; each part ("AB & CD", "AB & …")
			'confirmedDone' => count( array_filter( (array) ( $d['confirmed_people'] ?? array() ) ) ) >= max( 1, count( (array) ( $d['live_people'] ?? array() ) ) ) || ( empty( $d['confirmed_people'] ) && ! empty( $d['confirmed_user_id'] ) && count( (array) ( $d['live_people'] ?? array() ) ) < 2 ),
			'note' => '' !== $d['note_reason'] ? array( 'by' => zter_initials( $d['note_by'] ), 'to' => $d['note_to'] ? zter_initials( $d['note_to'] ) : '', 'reason' => $d['note_reason'], 'at' => $d['note_at'] ) : null,
		);
	}

	/**
	 * A no-go zone is the admin tier's alone. A trail line that says who it left out is left out of the trail for
	 * anyone else: even reworded, a line under a rule that names one person would tell a reviewer, ZIP by ZIP,
	 * where that person is never sent.
	 */
	public static function trail_for( array $trail, $admin ) {
		if ( $admin ) {
			return array_values( $trail );
		}
		$out = array();
		foreach ( $trail as $t ) {
			if ( false === strpos( (string) $t, ZTER_Rules::NOGO_MARK ) ) {
				$out[] = (string) $t;
			}
		}
		return $out;
	}

	/**
	 * The last 20 territories.route_for calls that reached Territories, and who may ask (ZTER_Asks).
	 * The admin tier only: it names staff by their initials. No customer data (the verb never sees any).
	 */
	public static function asks( WP_REST_Request $req ) {
		$rows = array();
		foreach ( ZTER_Asks::recent() as $a ) {
			$words = 'decision' === ( $a['outcome'] ?? '' ) ? 'decision ' . (int) ( $a['id'] ?? 0 ) . ' (' . ( 'live' === ( $a['mode'] ?? '' ) ? 'live' : 'shadow' ) . ')'
				: ( 'refusal' === ( $a['outcome'] ?? '' ) ? ZTER_Asks::reason_words( (string) ( $a['reason'] ?? '' ) ) : 'error: ' . (string) ( $a['message'] ?? '' ) );
			$rows[] = array( 'at' => (string) ( $a['at'] ?? '' ), 'app' => (string) ( $a['app'] ?? '' ), 'who' => (string) ( $a['who'] ?? '' ), 'tier' => (string) ( $a['tier'] ?? '' ),
				'test' => ! empty( $a['test'] ), 'outcome' => (string) ( $a['outcome'] ?? '' ), 'words' => $words );
		}
		return array( 'rows' => $rows, 'who' => ZTER_Asks::who_may_ask(), 'since' => (string) get_option( 'zter_installed', '' ) );
	}

	public static function decisions( WP_REST_Request $req ) {
		$days  = max( 1, min( 90, (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
		$tests = (bool) $req->get_param( 'tests' );
		$st    = self::stage( $req );
		$rows  = ZTER_DB::decisions( $days, $tests, 300, $st );
		$abs   = ZTER_Kinds::abilities();
		$memo  = array();
		$out   = array();
		$sum   = array( 'total' => 0, 'same' => 0, 'differ' => 0, 'noDefault' => 0, 'fallback' => 0, 'unknownKind' => 0, 'teamRolesOff' => 0, 'live' => 0, 'tests' => 0, 'byRule' => array() );
		$fb    = '';
		foreach ( ZTER_Rules::get( $st )['rows'] as $r ) {
			if ( ! empty( $r['fallback'] ) ) {
				$fb = $r['id'];
			}
		}
		foreach ( $rows as $d ) {
			$row   = self::shape_row( $d, $memo, $abs );
			$out[] = $row;
			$sum['total']++;
			$sum['tests'] += $row['test'] ? 1 : 0;
			$sum['live']  += 'live' === $row['mode'] ? 1 : 0;
			if ( '' === $row['default'] ) {
				$sum['noDefault']++;
			} elseif ( ZTER_Notify::norm( $row['would'] ) === ZTER_Notify::norm( $row['default'] ) ) {
				$sum['same']++;
			} else {
				$sum['differ']++;
			}
			$sum['fallback']     += $fb === $row['ruleId'] ? 1 : 0;
			$sum['unknownKind']  += 'known' !== $row['kindState'] ? 1 : 0;
			$sum['teamRolesOff'] += 'off' === $row['teamRoles'] ? 1 : 0;
			$k = '' !== $row['ruleId'] ? $row['ruleId'] : '(none)';
			$sum['byRule'][ $k ] = ( $sum['byRule'][ $k ] ?? 0 ) + 1;
		}
		return array( 'stage' => $st, 'days' => $days, 'tests' => $tests, 'summary' => $sum, 'rows' => $out );
	}

	/**
	 * Team Roles' own words for one person on one decision's kinds of work, fetched live. Only when Team Roles says the
	 * viewer may see that person's row (the person, their crew lead, the admin tier). Never stored.
	 */
	public static function why( WP_REST_Request $req ) {
		$d   = ZTER_DB::decision( (int) $req->get_param( 'decision' ) );
		$uid = (int) $req->get_param( 'user' );
		if ( ! $d || $uid <= 0 ) {
			return self::err( __( 'No such decision or person.', 'zorderz' ), 404 );
		}
		$seen = false;
		foreach ( $d['candidates'] as $c ) {
			$seen = $seen || (int) ( $c['u'] ?? 0 ) === $uid;
		}
		if ( ! $seen ) {
			return self::err( __( 'That person was not looked at for this decision.', 'zorderz' ), 404 );
		}
		if ( ! zter_can_see_roles_row( self::me(), $uid ) ) {
			return self::err( __( 'Team Roles shows that person\'s notes only to them, their crew lead and the admin tier.', 'zorderz' ), 403 );
		}
		$abs = ZTER_Kinds::abilities();
		$out = array();
		foreach ( $d['kinds'] as $k ) {
			// a stored kind is "item" or "item:repair"; Team Roles is asked about the cell the decision's stage
			// asks (sell for a stub; install or repair for a won job). A decision that stored a kind of work: asked as is.
			list( $item, $work ) = array_pad( explode( ':', (string) $k, 2 ), 2, 'new' );
			$cell = $item;
			if ( isset( ZTER_Kinds::items()[ $item ] ) ) {
				$cells = array();
				foreach ( 'both' === $work ? array( 'new', 'repair' ) : array( $work ) as $w ) {
					$role = ZTER_Kinds::role_for( $item, $w, $d['stage'] );
					if ( '' !== $role ) {
						$cells[] = $item . '__' . $role;
					}
				}
			} else {
				$cells = array( $cell );
			}
			foreach ( array_unique( $cells ) as $cell ) {
				$a = apply_filters( 'zdz_person_can', null, $uid, $cell );
				if ( ! is_array( $a ) ) {
					return self::err( __( 'Team Roles is not active.', 'zorderz' ), 409 );
				}
				$out[] = array( 'key' => $cell, 'label' => ZTER_Kinds::label( $cell, $abs ), 'level' => (string) ( $a['level'] ?? '' ), 'why' => (string) ( $a['why'] ?? '' ) );
			}
		}
		return array( 'user' => $uid, 'initials' => zter_initials( $uid ), 'now' => $out );
	}

	/**
	 * A note on a decision: who it really went to and why (the reassignment itself happens in the estimate app, through its
	 * own For: / line-edit path). Shown in the comparison so the rules can be fixed; it trains nothing.
	 */
	public static function note( WP_REST_Request $req ) {
		$d = ZTER_DB::decision( (int) $req['id'] );
		if ( ! $d ) {
			return self::err( __( 'No such decision.', 'zorderz' ), 404 );
		}
		$reason = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $req->get_param( 'reason' ) ) );
		$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 300 ) : substr( $reason, 0, 300 );
		if ( '' === $reason ) {
			return self::err( __( 'Say why, in a few words.', 'zorderz' ) );
		}
		$to = (int) $req->get_param( 'to' );
		if ( $to && ! zter_assignable( $to ) ) {
			return self::err( __( 'Pick someone on the team who has initials.', 'zorderz' ) );
		}
		ZTER_DB::update_decision( $d['id'], array( 'note_by' => self::me(), 'note_to' => $to, 'note_reason' => $reason, 'note_at' => ZTER_DB::now() ) );
		ZTER_DB::log( self::me(), $to, 'note', array( 'decision' => $d['id'], 'to' => $to, 'reason' => $reason ) );
		return array( 'ok' => true );
	}

	/** Who was confirmed, each part of a two-installer job ("AB & CD"; "AB & …" while the second is open). */
	private static function confirmed_words( array $d ) {
		$cp = array_values( (array) ( $d['confirmed_people'] ?? array() ) );
		if ( ! $cp ) {
			return ! empty( $d['confirmed_user_id'] ) ? zter_initials( (int) $d['confirmed_user_id'] ) : '';
		}
		$cp = array_pad( $cp, max( count( $cp ), count( (array) ( $d['live_people'] ?? array() ) ) ), 0 );
		return implode( ' & ', array_map( function ( $u ) { return (int) $u > 0 ? zter_initials( (int) $u ) : '…'; }, $cp ) );
	}

	/** Try a made-up stub without recording it or taking a turn: what would happen, and why. */
	public static function try_stub( WP_REST_Request $req ) {
		$lines = array();
		foreach ( preg_split( '/\r?\n/', (string) $req->get_param( 'text' ) ) as $l ) {
			$l = trim( $l );
			if ( '' === $l ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( '|', $l, 2 ) );
			$lines[] = array( 'name' => $parts[0], 'desc' => $parts[1] ?? '' );
		}
		if ( ! $lines ) {
			return self::err( __( 'Write at least one line, for example "<item name> repair".', 'zorderz' ) );
		}
		$st     = self::stage( $req );
		$seller = (int) $req->get_param( 'seller' ); // stage install: the salesperson of the won job
		if ( 'install' === $st && $seller && ! zter_is_team_member( $seller ) ) {
			return self::err( __( 'Pick a salesperson on the team.', 'zorderz' ) );
		}
		list( , $rec ) = ZTER_Rules::route( array(
			'zip' => (string) $req->get_param( 'zip' ), 'reference' => (string) $req->get_param( 'reference' ), 'lines' => array_slice( $lines, 0, 60 ),
			'default_initials' => (string) $req->get_param( 'default' ), 'asker_id' => self::me(), 'dry' => true,
			'stage' => $st, 'seller_user_id' => $seller, 'pairs' => true,
		) );
		$memo = array();
		$rec  = ZTER_DB::shape_decision( array_merge( $rec, array(
			'id' => 0, 'created_at' => ZTER_DB::now(), 'kinds' => implode( ',', $rec['kinds'] ), 'kind_notes' => wp_json_encode( $rec['kind_notes'] ),
			'candidates' => wp_json_encode( $rec['candidates'] ), 'trail' => wp_json_encode( $rec['trail'] ), 'applied_at' => null, 'applied_doc' => '', 'notice' => '',
			'live_rule_id' => (string) $rec['live_rule_id'], 'live_user_id' => (int) $rec['live_user_id'],
			'chosen_people' => implode( ',', (array) $rec['chosen_people'] ), 'live_people' => implode( ',', (array) $rec['live_people'] ), // as the table keeps them
			'note_by' => 0, 'note_to' => 0, 'note_reason' => '', 'note_at' => null,
		) ) );
		return self::shape_row( $rec, $memo, ZTER_Kinds::abilities() );
	}

	public static function run_tests( WP_REST_Request $req ) {
		$res  = ZTER_Testdata::run( self::me(), self::stage( $req ) );
		$abs  = ZTER_Kinds::abilities();
		$memo = array();
		$out  = array();
		foreach ( $res as $r ) {
			$d     = ZTER_DB::decision( $r['decision_id'] );
			$out[] = $d ? self::shape_row( $d, $memo, $abs ) : array( 'label' => $r['label'], 'error' => 'not recorded' );
		}
		return array( 'ok' => true, 'rows' => $out, 'stage' => self::stage( $req ) );
	}

	public static function clear_tests( WP_REST_Request $req ) {
		$st = $req->get_param( 'stage' ); // none: both stages' test stubs
		return array( 'ok' => true, 'cleared' => ZTER_DB::clear_tests( self::me(), null === $st || '' === $st ? null : ZTER_Rules::stage( $st ) ) );
	}

	public static function history( WP_REST_Request $req ) {
		$h = ZTER_DB::history( (int) ( $req->get_param( 'limit' ) ?: 50 ), (int) $req->get_param( 'before' ) );
		foreach ( $h['rows'] as &$r ) {
			$r['by']     = zter_initials( $r['changed_by'] ) ?: ( $r['changed_by'] ? '#' . $r['changed_by'] : 'setup' );
			$r['person'] = $r['user_id'] ? ( zter_initials( $r['user_id'] ) ?: '#' . $r['user_id'] ) : '';
		}
		unset( $r );
		return $h;
	}
}
