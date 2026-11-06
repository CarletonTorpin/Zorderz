<?php
/**
 * ZTER_Rules — the ordered rules that pick who gets the work, and the routing itself.
 *
 * Rules are evaluated in order; the first that matches decides. Each one reads as a sentence on the page.
 * The rules are the business's own: Core ships only the last one, "everything else", which names nobody.
 *
 * TWO STAGES, each with its own list:
 *   stub     a new estimate stub needs a SELLER (the estimate app asks, territories.route_for). "Can" means
 *            Team Roles' Sell for every item on it.
 *   install  a won job needs someone to DO it (the Jobs app asks, territories.installer_for). "Can" means
 *            Install for new work and Repair for a repair, on every item and add-on.
 *
 * Conditions: words a line mentions, items the work includes (or `all_kinds`: every item on the job is one of
 * the rule's), `unknown_only` (some line names no item, or there is no work line), ZIPs, `in_area` (the ZIP is
 * inside the service zone of every item a zone covers). Who: `territory` (whoever's territory holds the ZIP),
 * `person` (one named person), `anyone` (everyone who can, any ZIP), `default` (keep the asking app's own pick,
 * or no suggestion for a won job), `refer` (outside every service area: names nobody, marks a referral case).
 * `with_rest`: the rule's person takes its items and the rules BELOW it pick someone for the rest (two people,
 * "AB & CD"). `seller_first` (install stage): the job's salesperson, when they can do it.
 *
 * Several people matching are taken in turn (least recently picked, this app's own record). Team Roles is asked
 * one cell at a time (`<item>__sell`, `__install`, `__repair`); when it is not active a rule that needs "can do"
 * does not match and the trail says so: never assume Can.
 *
 * No-go zones (ZTER_Zones kind 'nogo'): no rule ever picks a person at a ZIP inside one of their no-go zones, at
 * either stage, live or shadow. The trail says a person was left out by a no-go zone; outside the admin tier
 * that line is left out (ZTER_REST::trail_for).
 *
 * SHADOW, THEN LIVE, ONE RULE AT A TIME. Every rule is in shadow until an admin turns it live from the go-live
 * checklist. In shadow, the answer is the asking app's own pick (or no suggestion) and the decision records what
 * the rules would have chosen. Rules are versioned per stage; every decision stores its stage, the rule id and
 * the version that matched; every saved list is in History with what it replaced, and an earlier version can be
 * put back (revert) as a new version, each rule keeping the mode it has now.
 *
 * EMPTY MEANS NOBODY. With no rules, or when no rule names anyone, nobody is picked: the decision is marked for
 * a person to review, and the asking app keeps doing what it does without Territories.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Rules {

	const OPTION         = 'zter_rules';
	const OPTION_INSTALL = 'zter_install_rules';
	const MAX_RULES      = 30;
	const WHO            = array( 'territory', 'person', 'anyone', 'default', 'refer' );
	const STAGES         = array( 'stub', 'install' );
	const NOGO_MARK      = '(a no-go zone)';   // how a trail line says it; the REST shape leaves such lines out below the admin tier

	public static function stage( $s ) {
		return 'install' === $s ? 'install' : 'stub';
	}

	/**
	 * Whose turn is next is kept per stage. The two lists can share rule ids (fallback, minted r1 …), so an install
	 * turn must never move a stub rotation. Stub keys are the bare rule id.
	 */
	public static function turn_key( $stage, $rule_id ) {
		return ( 'install' === self::stage( $stage ) ? 'install:' : '' ) . (string) $rule_id;
	}

	private static function option( $stage ) {
		return 'install' === self::stage( $stage ) ? self::OPTION_INSTALL : self::OPTION;
	}

	private static function blank( array $r ) {
		return $r + array( 'words' => array(), 'kinds' => array(), 'zips' => array(), 'who' => 'default', 'person' => 0, 'need_can' => false, 'live' => false,
			'fallback' => false, 'all_kinds' => false, 'unknown_only' => false, 'in_area' => false, 'with_rest' => false, 'seller_first' => false );
	}

	/** The first list for a fresh site: only "everything else", which names nobody. The business adds the rest. */
	public static function seed_rows( $stage = 'stub' ) {
		return array( self::blank( array( 'id' => 'fallback', 'fallback' => true ) ) );
	}

	public static function seed() {
		$did = false;
		foreach ( self::STAGES as $st ) {
			if ( false !== get_option( self::option( $st ), false ) ) {
				continue;
			}
			update_option( self::option( $st ), array( 'version' => 1, 'rows' => self::seed_rows( $st ), 'updated_by' => 0, 'updated_at' => ZTER_DB::now() ), false );
			ZTER_DB::log( 0, 0, 'rules', array( 'stage' => $st, 'version' => 1, 'seeded' => true, 'to' => self::seed_rows( $st ) ) );
			$did = true;
		}
		return $did;
	}

	public static function get( $stage = 'stub' ) {
		$stage = self::stage( $stage );
		$o     = get_option( self::option( $stage ), null );
		if ( ! is_array( $o ) || empty( $o['rows'] ) ) {
			return array( 'version' => 1, 'rows' => self::seed_rows( $stage ), 'updated_by' => 0, 'updated_at' => '', 'stage' => $stage );
		}
		$rows = array();
		foreach ( (array) $o['rows'] as $r ) {
			$c = self::clean_rule( $r, false, $stage );
			if ( ! is_wp_error( $c ) ) {
				$rows[] = $c;
			}
		}
		if ( ! $rows || empty( $rows[ count( $rows ) - 1 ]['fallback'] ) ) {
			$rows[] = self::blank( array( 'id' => 'fallback', 'fallback' => true ) ); // a stored list that lost its fallback still ends in one
		}
		return array( 'version' => (int) ( $o['version'] ?? 1 ), 'rows' => $rows, 'updated_by' => (int) ( $o['updated_by'] ?? 0 ), 'updated_at' => (string) ( $o['updated_at'] ?? '' ), 'stage' => $stage );
	}

	private static function flag( $v ) {
		return is_bool( $v ) ? $v : ( is_scalar( $v ) && (bool) filter_var( (string) $v, FILTER_VALIDATE_BOOLEAN ) );
	}

	/** One rule, cleaned. $strict checks people and items against the site (on save). */
	public static function clean_rule( $r, $strict = true, $stage = 'stub' ) {
		$stage = self::stage( $stage );
		if ( ! is_array( $r ) ) {
			return new WP_Error( 'zter_rule', __( 'A rule is not readable.', 'zorderz' ) );
		}
		$id = strtolower( is_scalar( $r['id'] ?? '' ) ? (string) ( $r['id'] ?? '' ) : '' );
		$id = preg_match( '/^[a-z0-9_]{1,24}$/', $id ) ? $id : '';
		$words = array();
		foreach ( ( is_array( $r['words'] ?? null ) ? $r['words'] : ( is_scalar( $r['words'] ?? '' ) ? preg_split( '/\s*,\s*/', (string) ( $r['words'] ?? '' ) ) : array() ) ) as $w ) {
			if ( ! is_scalar( $w ) ) {
				continue;
			}
			$w = strtolower( trim( preg_replace( '/[^a-z0-9 \-\']/i', '', (string) $w ) ) );
			if ( strlen( $w ) >= 2 && strlen( $w ) <= 40 ) {
				$words[ $w ] = true;
			}
		}
		$words = array_slice( array_keys( $words ), 0, 12 );
		$kinds = array();
		foreach ( (array) ( $r['kinds'] ?? array() ) as $k ) {
			$k = is_scalar( $k ) ? strtolower( trim( (string) $k ) ) : '';
			if ( preg_match( '/^[a-z0-9_]{2,40}$/', $k ) ) {
				$kinds[ $k ] = true;
			}
		}
		$kinds = array_keys( $kinds );
		list( $zips, $bad ) = ZTER_DB::parse_zips( is_array( $r['zips'] ?? null ) ? array_filter( $r['zips'], 'is_scalar' ) : ( is_scalar( $r['zips'] ?? '' ) ? (string) ( $r['zips'] ?? '' ) : '' ) );
		if ( $strict && $bad ) {
			return new WP_Error( 'zter_rule', sprintf( __( 'These are not ZIP codes: %s.', 'zorderz' ), implode( ', ', $bad ) ) );
		}
		$who_in       = is_scalar( $r['who'] ?? '' ) ? (string) ( $r['who'] ?? '' ) : '';
		$who          = in_array( $who_in, self::WHO, true ) ? $who_in : 'default';
		$person       = is_scalar( $r['person'] ?? 0 ) ? (int) ( $r['person'] ?? 0 ) : 0;
		$fallback     = self::flag( $r['fallback'] ?? false );
		$need_can     = self::flag( $r['need_can'] ?? false );
		$all_kinds    = self::flag( $r['all_kinds'] ?? false );
		$unknown_only = self::flag( $r['unknown_only'] ?? false );
		$in_area      = self::flag( $r['in_area'] ?? false );
		$with_rest    = self::flag( $r['with_rest'] ?? false );
		$seller_first = self::flag( $r['seller_first'] ?? false );
		if ( 'refer' === $who ) {
			// "outside every service area -> send for a referral" is a condition of its own; it takes no other,
			// names nobody, and never respects or skips anyone
			$words = array(); $kinds = array(); $zips = array(); $need_can = false;
			$all_kinds = $unknown_only = $in_area = $with_rest = $seller_first = false;
		}
		if ( 'anyone' === $who ) {
			$need_can = true; // "anyone who can" is the check itself
		}
		if ( 'install' === $stage ) {
			// a named installer may hand the rest of the work on; the rest goes down the won-jobs rules below it (who
			// can install it, in its service area)
		} else {
			$seller_first = false;
			$in_area      = false; // service areas decide where a job can be done, not who sells it
		}
		if ( 'person' !== $who ) {
			$with_rest = false; // only a named person hands the rest on
		}
		if ( $fallback ) {
			// The fallback always answers: no conditions, no "can do", never a pool of people.
			$words = array(); $kinds = array(); $zips = array(); $need_can = false;
			$all_kinds = $unknown_only = $in_area = $with_rest = $seller_first = false;
			if ( 'person' !== $who ) {
				$who = 'default';
			}
		}
		if ( ! $kinds ) {
			$all_kinds = false;
			$with_rest = false;
		}
		if ( 'person' !== $who ) {
			$person = 0;
		}
		if ( $strict ) {
			if ( 'person' === $who && ! zter_assignable( $person ) ) {
				return new WP_Error( 'zter_rule', __( 'Pick a person on the team who has initials (User Management).', 'zorderz' ) );
			}
			$items = ZTER_Kinds::items();
			if ( $items ) {
				foreach ( $kinds as $k ) {
					if ( ! isset( $items[ $k ] ) || ! empty( $items[ $k ]['retired'] ) ) {
						return new WP_Error( 'zter_rule', sprintf( __( 'Team Roles has no item "%s" in use.', 'zorderz' ), $k ) );
					}
				}
			}
		}
		return array(
			'id' => $id, 'words' => $words, 'kinds' => $kinds, 'zips' => $zips, 'who' => $who, 'person' => $person,
			'need_can' => $need_can, 'live' => self::flag( $r['live'] ?? false ), 'fallback' => $fallback,
			'all_kinds' => $all_kinds, 'unknown_only' => $unknown_only, 'in_area' => $in_area, 'with_rest' => $with_rest, 'seller_first' => $seller_first,
		);
	}

	/**
	 * The page's rule editor never turns a rule live or back to shadow. Each rule keeps the mode it has (matched by
	 * id); a new rule starts in shadow. Going live is one rule at a time, from the go-live checklist (set_live),
	 * confirmed in the app's sheet and recorded with who and when.
	 */
	public static function keep_modes( array $rows, $stage = 'stub' ) {
		$have = array();
		foreach ( self::get( $stage )['rows'] as $r ) {
			$have[ $r['id'] ] = ! empty( $r['live'] );
		}
		$seen = array();
		foreach ( $rows as &$r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$id        = strtolower( is_scalar( $r['id'] ?? '' ) ? (string) ( $r['id'] ?? '' ) : '' );
			// Only the first row carrying a live rule's id keeps it live: a copy with the same id is a new rule.
			$r['live'] = '' !== $id && ! empty( $have[ $id ] ) && ! isset( $seen[ $id ] );
			$seen[ $id ] = true;
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Turn one rule live, or back to shadow. $version is the rules version the person was looking at: a list
	 * changed since (another admin, another tab) is refused, so nobody turns on a rule they did not see. Saved through
	 * save() (its checks, a new version, the full list in History), then one 'live' History row says who and when.
	 *
	 * @return array|WP_Error the new rules record, plus 'no' and 'sentence' of the rule
	 */
	public static function set_live( $rule_id, $live, $version, $by, $stage = 'stub' ) {
		if ( ! ZTER_DB::lock( 'zter_rules' ) ) {
			return new WP_Error( 'zter_busy', __( 'Someone is saving the rules right now. Try again in a moment.', 'zorderz' ), array( 'status' => 409 ) );
		}
		try {
			return self::set_live_locked( $rule_id, $live, $version, $by, self::stage( $stage ) );
		} finally {
			ZTER_DB::unlock( 'zter_rules' );
		}
	}

	private static function set_live_locked( $rule_id, $live, $version, $by, $stage ) {
		$cur = self::get( $stage );
		if ( (int) $version !== (int) $cur['version'] ) {
			return new WP_Error( 'zter_stale', 'The rules changed since you opened them (now version ' . (int) $cur['version'] . '). Look at them again first.', array( 'status' => 409 ) );
		}
		$rows = $cur['rows'];
		$at   = -1;
		foreach ( $rows as $i => $r ) {
			if ( $r['id'] === (string) $rule_id ) {
				$at = $i;
			}
		}
		if ( $at < 0 ) {
			return new WP_Error( 'zter_rule', __( 'No such rule.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( (bool) $rows[ $at ]['live'] === (bool) $live ) {
			return new WP_Error( 'zter_rule', $live ? 'That rule is already live.' : 'That rule is already in shadow.', array( 'status' => 409 ) );
		}
		$rows[ $at ]['live'] = (bool) $live;
		if ( $live ) {
			$saved = self::save( $rows, $by, $stage ); // going live: every rule must pass the checks
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		} else {
			// Back to shadow is the off switch: it must work even when some other rule no longer passes the checks
			// (a person who lost their initials, a retired item). Only the flag moves.
			$saved = array( 'version' => $cur['version'] + 1, 'rows' => $rows, 'updated_by' => (int) $by, 'updated_at' => ZTER_DB::now() );
			update_option( self::option( $stage ), $saved, false );
			ZTER_DB::log( $by, 0, 'rules', array( 'stage' => $stage, 'version' => $saved['version'], 'from' => $cur['rows'], 'to' => $rows ) );
			ZTER_DB::bump();
		}
		$sentence = self::sentence( $saved['rows'][ $at ], null, $stage );
		ZTER_DB::log( $by, 0, 'live', array( 'stage' => $stage, 'rule' => (string) $rule_id, 'no' => $at + 1, 'live' => (bool) $live, 'version' => $saved['version'], 'sentence' => $sentence ) );
		return $saved + array( 'no' => $at + 1, 'sentence' => $sentence );
	}

	/** Save the whole list, in order. Exactly one fallback, last. The version goes up by one. */
	public static function save( array $rows, $by, $stage = 'stub' ) {
		$stage = self::stage( $stage );
		$out   = self::check_rows( $rows, $stage );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$old = self::get( $stage );
		$new = array( 'version' => $old['version'] + 1, 'rows' => $out, 'updated_by' => (int) $by, 'updated_at' => ZTER_DB::now() );
		update_option( self::option( $stage ), $new, false );
		ZTER_DB::log( $by, 0, 'rules', array( 'stage' => $stage, 'version' => $new['version'], 'from' => $old['rows'], 'to' => $out ) );
		ZTER_DB::bump();
		return $new + array( 'stage' => $stage );
	}

	/**
	 * The checks save() makes, without saving: each rule cleaned against the site, a condition on every rule but
	 * the last, exactly one "everything else", last, and an id for every rule (a rule new to the list starts in
	 * shadow). Returns the list as it would be stored, or a WP_Error that names the problem.
	 */
	public static function check_rows( array $rows, $stage = 'stub' ) {
		$stage = self::stage( $stage );
		$rows  = array_values( $rows );
		if ( count( $rows ) > self::MAX_RULES ) {
			return new WP_Error( 'zter_rule', sprintf( __( 'At most %d rules.', 'zorderz' ), self::MAX_RULES ) );
		}
		$out = array();
		$ids = array();
		$fb  = 0;
		foreach ( $rows as $i => $r ) {
			$c = self::clean_rule( $r, true, $stage );
			if ( is_wp_error( $c ) ) {
				return new WP_Error( 'zter_rule', sprintf( __( 'Rule %d: %s', 'zorderz' ), $i + 1, $c->get_error_message() ) );
			}
			if ( $c['fallback'] ) {
				$fb++;
				if ( $i !== count( $rows ) - 1 ) {
					return new WP_Error( 'zter_rule', __( 'The "everything else" rule must be the last one.', 'zorderz' ) );
				}
			}
			if ( ! $c['fallback'] && ! $c['words'] && ! $c['kinds'] && ! $c['zips'] && ! $c['unknown_only'] && ! $c['in_area'] && ! in_array( $c['who'], array( 'territory', 'anyone', 'refer' ), true ) ) {
				return new WP_Error( 'zter_rule', sprintf( __( 'Rule %d has no condition, so it would catch everything. Give it one, or change the "everything else" rule instead.', 'zorderz' ), $i + 1 ) );
			}
			if ( '' === $c['id'] || isset( $ids[ $c['id'] ] ) ) {
				$c['id']   = self::mint_id( $ids );
				$c['live'] = false; // a rule new to the list starts in shadow
			}
			$ids[ $c['id'] ] = true;
			$out[] = $c;
		}
		if ( 1 !== $fb ) {
			return new WP_Error( 'zter_rule', __( 'There must be exactly one "everything else" rule, last.', 'zorderz' ) );
		}
		return $out;
	}

	/**
	 * Put back the list a stage had at an earlier version, as a new version. Each rule keeps the mode it has
	 * now (matched by id): a revert never turns a rule live. $expect is the version the person was looking
	 * at; a list changed since is refused. Returns the new record, or WP_Error.
	 */
	public static function revert( $stage, $to_version, $expect, $by ) {
		$stage = self::stage( $stage );
		$cur   = self::get( $stage );
		if ( (int) $expect !== (int) $cur['version'] ) {
			/* translators: %d: rules version */
			return new WP_Error( 'zter_stale', sprintf( __( 'The rules changed since you opened them (now version %d). Look at them again first.', 'zorderz' ), (int) $cur['version'] ), array( 'status' => 409 ) );
		}
		$rows = self::rows_at( $stage, (int) $to_version );
		if ( null === $rows ) {
			return new WP_Error( 'zter_rule', __( 'That version of the rules is not in the history.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( (int) $to_version === (int) $cur['version'] ) {
			return new WP_Error( 'zter_rule', __( 'That is the version in use now.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$saved = self::save( self::keep_modes( $rows, $stage ), $by, $stage );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		ZTER_DB::log( $by, 0, 'revert', array( 'stage' => $stage, 'from' => (int) $cur['version'], 'to' => (int) $to_version, 'version' => (int) $saved['version'] ) );
		return $saved;
	}

	/**
	 * A stage's rules as they were at a version: the current list, or the history row that saved it.
	 * Null when that version is in neither.
	 */
	public static function rows_at( $stage, $version ) {
		$stage = self::stage( $stage );
		$cur   = self::get( $stage );
		if ( (int) $cur['version'] === (int) $version ) {
			return $cur['rows'];
		}
		$before = 0;
		for ( $page = 0; $page < 20; $page++ ) {
			$h = ZTER_DB::history( 200, $before, 'rules' );
			foreach ( $h['rows'] as $r ) {
				$d = $r['detail'];
				if ( (int) ( $d['version'] ?? 0 ) === (int) $version && self::stage( $d['stage'] ?? 'stub' ) === $stage && isset( $d['to'] ) && is_array( $d['to'] ) ) {
					return array_values( $d['to'] );
				}
				$before = (int) $r['id'];
			}
			if ( ! $h['more'] ) {
				break;
			}
		}
		return null;
	}

	private static function mint_id( array $taken ) {
		for ( $i = 1; $i < 1000; $i++ ) {
			if ( ! isset( $taken[ 'r' . $i ] ) ) {
				return 'r' . $i;
			}
		}
		return 'r' . wp_rand( 1000, 9999 );
	}

	/** A rule as a sentence. Plain text: the page escapes it. */
	public static function sentence( array $rule, $abs = null, $stage = 'stub' ) {
		$stage  = self::stage( $stage );
		$abs    = null === $abs ? ZTER_Kinds::abilities() : $abs;
		$rule   = self::blank( $rule );
		$target = self::target_words( $rule, $stage );
		if ( ! empty( $rule['fallback'] ) ) {
			return 'Everything else → ' . $target;
		}
		if ( 'refer' === $rule['who'] ) {
			return 'If the ZIP is outside every service area of the work (Zones) → ' . $target;
		}
		$verb = 'install' === $stage ? 'install or repair' : 'sell';
		$c    = array();
		if ( $rule['words'] ) {
			$c[] = 'a line mentions ' . self::join_or( array_map( function ( $w ) { return '"' . $w . '"'; }, $rule['words'] ) );
		}
		if ( $rule['kinds'] ) {
			$labels = array_map( function ( $k ) use ( $abs ) { return ZTER_Kinds::label( $k, $abs ); }, $rule['kinds'] );
			$c[]    = $rule['all_kinds'] ? 'all the work is ' . self::join_or( $labels ) : 'the work includes ' . self::join_or( $labels );
		}
		if ( $rule['unknown_only'] ) {
			$c[] = 'the work is unknown or only says "repair"';
		}
		if ( $rule['zips'] ) {
			$z   = $rule['zips'];
			$c[] = 'the ZIP is ' . ( count( $z ) <= 6 ? self::join_or( $z ) : implode( ', ', array_slice( $z, 0, 5 ) ) . ' or ' . ( count( $z ) - 5 ) . ' more' );
		}
		if ( $rule['in_area'] ) {
			$c[] = 'the ZIP is inside the service area of the work';
		}
		if ( 'territory' === $rule['who'] ) {
			$c[] = 'the ZIP is in someone\'s territory';
		}
		if ( 'anyone' === $rule['who'] ) {
			$c[] = 'anyone can ' . $verb . ' every item on it (Team Roles)';
		} elseif ( $rule['need_can'] ) {
			$c[] = ( 'territory' === $rule['who'] ? 'they' : self::person_words( $rule['person'] ) ) . ' can ' . $verb . ' every item on it (Team Roles)';
		}
		return ( $c ? 'If ' . implode( ' and ', $c ) : 'Always' ) . ' → ' . $target;
	}

	public static function target_words( array $rule, $stage = 'stub' ) {
		$rule = self::blank( $rule );
		$first = 'install' === self::stage( $stage ) && $rule['seller_first'] ? 'the salesperson if they can, else ' : '';
		if ( 'territory' === $rule['who'] ) {
			return $first . 'that person (taking turns when several match)';
		}
		if ( 'anyone' === $rule['who'] ) {
			return $first . 'one of them (taking turns)';
		}
		if ( 'refer' === $rule['who'] ) {
			return 'send for a referral (the referral list); ' . ( 'install' === self::stage( $stage ) ? 'no suggestion' : 'keep the asking app\'s own pick' );
		}
		if ( 'person' === $rule['who'] ) {
			return self::person_words( $rule['person'] ) . ( $rule['with_rest'] ? ( 'install' === self::stage( $stage ) ? ', and a second installer for the rest of the work' : ', and the best person for the rest of the work too' ) : '' );
		}
		return 'install' === self::stage( $stage ) ? 'no suggestion' : 'keep the asking app\'s own pick';
	}

	public static function person_words( $uid ) {
		$u = get_userdata( (int) $uid );
		if ( ! $u ) {
			return 'a person who is no longer here';
		}
		$ini = zter_initials( $uid );
		return (string) ( $u->display_name ?? '' ) . ( '' !== $ini ? ' (' . $ini . ')' : '' );
	}

	private static function join_or( array $items ) {
		$items = array_values( $items );
		if ( count( $items ) <= 1 ) {
			return (string) ( $items[0] ?? '' );
		}
		return implode( ', ', array_slice( $items, 0, -1 ) ) . ' or ' . $items[ count( $items ) - 1 ];
	}

	/* ── Evaluating ───────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * Is this person Can for every item on the job at this stage (Sell for a stub; Install for new work, Repair for a
	 * repair on a won job)? Returns array( ok bool|null, levels [cell => level] ): null when Team Roles cannot be asked
	 * (not active, or it has no items): "fall back, never assume Can".
	 */
	private static function can_do( $uid, array $job, $stage ) {
		$levels = array();
		$all    = true;
		foreach ( $job['kinds'] as $item ) {
			$works = 'both' === ( $job['work'][ $item ] ?? 'new' ) ? array( 'new', 'repair' ) : array( (string) ( $job['work'][ $item ] ?? 'new' ) );
			foreach ( $works as $w ) {
				$role = ZTER_Kinds::role_for( $item, $w, $stage );
				if ( '' === $role ) {
					$levels[ $item ] = 'cannot';
					$all             = false;
					continue;
				}
				$cell = $item . '__' . $role;
				$a    = apply_filters( 'zdz_person_can', null, (int) $uid, $cell );
				if ( ! is_array( $a ) || ! array_key_exists( 'can', $a ) ) {
					return array( null, array() );
				}
				$levels[ $cell ] = in_array( (string) ( $a['level'] ?? '' ), array( 'can', 'learning', 'cannot' ), true ) ? (string) $a['level'] : 'cannot';
				if ( empty( $a['can'] ) ) {
					$all = false;
				}
			}
		}
		return array( $all, $levels );
	}

	/**
	 * Is the ZIP inside the service zone of every item a service zone covers? An item no service zone covers (no zone
	 * names it) does not restrict. A zone names items, or kinds of work, which count for the item they moved to.
	 */
	public static function in_service_area( $zip, array $items ) {
		if ( '' === $zip ) {
			return false;
		}
		$map   = ZTER_Kinds::legacy_map();
		$zones = ZTER_Zones::all()['rows'];
		foreach ( $items as $item ) {
			$covering = array();
			foreach ( $zones as $z ) {
				if ( 'service' !== $z['kind'] ) {
					continue;
				}
				foreach ( $z['abilities'] as $k ) {
					if ( $k === $item || in_array( $item, (array) ( $map[ $k ] ?? array() ), true ) ) {
						$covering[] = $z;
						break;
					}
				}
			}
			if ( ! $covering ) {
				continue;
			}
			$in = false;
			foreach ( $covering as $z ) {
				if ( ZTER_Zones::contains( $z, $zip ) ) {
					$in = true;
				}
			}
			if ( ! $in ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is this job outside every service area? The ZIP is known, the work is known (every line names an item), every
	 * item is named by some service zone, and none of those zones holds the ZIP. An item no zone names is offered
	 * everywhere, so a job with one is never outside. A job we can do part of is not a referral.
	 */
	public static function uncovered( $zip, array $items, $state = 'known' ) {
		if ( '' === ZTER_DB::clean_zip( (string) $zip ) || 'known' !== $state || ! $items ) {
			return false;
		}
		$map   = ZTER_Kinds::legacy_map();
		$zones = ZTER_Zones::all()['rows'];
		foreach ( $items as $item ) {
			$named = false;
			foreach ( $zones as $z ) {
				if ( 'service' !== $z['kind'] ) {
					continue;
				}
				foreach ( $z['abilities'] as $k ) {
					if ( $k === $item || in_array( $item, (array) ( $map[ $k ] ?? array() ), true ) ) {
						$named = true;
						if ( ZTER_Zones::contains( $z, $zip ) ) {
							return false; // this item is offered here
						}
						break;
					}
				}
			}
			if ( ! $named ) {
				return false; // offered everywhere
			}
		}
		return true;
	}

	/**
	 * Which rule decides this job, and who it picks.
	 *
	 * Only looks: no turn is taken here (route() takes the shadow turn after recording; the live turn is taken when the
	 * asking app writes the assignment, ZTER_Notify).
	 *
	 * @param array      $job   { zip, kinds[] (items), work{item: new|repair|both}, kind_state, generic, text, seller }
	 * @param string     $book  whose turns decide a tie: 'shadow', 'live' or 'test'
	 * @param array|null $rules a rules record (default: the stored one for $stage)
	 * @param string     $stage 'stub' (who sells) or 'install' (who does the work)
	 * @return array { rule_id, rule_version, rule_no, live, chosen_user_id, use_default, candidates[], trail[], team_roles,
	 *                 second (with_rest: { rule_id, rule_no, chosen_user_id, use_default } or null), able[] (the others
	 *                 the deciding rule found able, for the Jobs app's "Another") }
	 *   candidates: each person a rule looked at: { rule, u, ini, levels: { cell: 'can'|'learning'|'cannot' } }.
	 *   No Team Roles `why` or note is ever kept here: its notes belong to the row's own viewers.
	 */
	public static function evaluate( array $job, $book = 'shadow', $rules = null, $stage = 'stub', $from = 0 ) {
		$stage = self::stage( $stage );
		$rules = is_array( $rules ) ? $rules : self::get( $stage );
		$abs   = ZTER_Kinds::abilities();
		$zip   = (string) ( $job['zip'] ?? '' );
		$kinds = array_values( (array) ( $job['kinds'] ?? array() ) );
		$state = (string) ( $job['kind_state'] ?? 'none' );
		$text  = (string) ( $job['text'] ?? '' );
		$job  += array( 'work' => array(), 'seller' => 0 );
		$verb  = 'install' === $stage ? 'install or repair' : 'sell';
		$trail = array();
		$cands = array();
		$tr    = 'n/a';
		$rows  = $rules['rows'];
		for ( $i = (int) $from; $i < count( $rows ); $i++ ) {
			$rule = self::blank( $rows[ $i ] );
			// A list of the live rules only (preview_route) keeps each rule's real number for the trail.
			$no = 'Rule ' . ( isset( $rule['_no'] ) ? (int) $rule['_no'] : $i + 1 );
			$labels = function ( $ks ) use ( $abs ) { return implode( ' or ', array_map( function ( $k ) use ( $abs ) { return ZTER_Kinds::label( $k, $abs ); }, $ks ) ); };
			if ( empty( $rule['fallback'] ) ) {
				if ( $rule['words'] && '' === ZTER_Kinds::has_word( $text, $rule['words'] ) ) {
					$trail[] = $no . ': no line mentions ' . implode( ' or ', $rule['words'] ) . '.';
					continue;
				}
				if ( $rule['kinds'] && ! array_intersect( $rule['kinds'], $kinds ) ) {
					$trail[] = $no . ': the work is not ' . $labels( $rule['kinds'] ) . '.';
					continue;
				}
				if ( $rule['all_kinds'] && ( 'known' !== $state || array_diff( $kinds, $rule['kinds'] ) ) ) {
					$trail[] = $no . ': not all the work is ' . $labels( $rule['kinds'] ) . '.';
					continue;
				}
				if ( $rule['unknown_only'] && 'known' === $state ) {
					$trail[] = $no . ': every line says what the work is.';
					continue;
				}
				if ( $rule['zips'] && ! in_array( $zip, $rule['zips'], true ) ) {
					$trail[] = $no . ': the ZIP ' . ( '' === $zip ? '(none)' : $zip ) . ' is not one of its ZIPs.';
					continue;
				}
				// A rule hands the rest on only at the top (the rest's own evaluation never hands on
				// again); at a won job its service area is that of its own items (the rest is the next installer's)
				$hands = $rule['with_rest'] && empty( $job['no_rest'] );
				$area  = ( $hands && 'install' === $stage && $rule['kinds'] ) ? array_values( array_intersect( $kinds, $rule['kinds'] ) ) : $kinds;
				if ( $rule['in_area'] && ! self::in_service_area( $zip, $area ) ) {
					$trail[] = $no . ': the ZIP ' . ( '' === $zip ? '(none)' : $zip ) . ' is outside the service area of the work (Zones).';
					continue;
				}
			}
			if ( 'refer' === $rule['who'] ) {
				// never for the rest of someone's work (the first person is doing part of the job)
				if ( ! empty( $job['no_rest'] ) || ! self::uncovered( $zip, $kinds, $state ) ) {
					$trail[] = $no . ': ' . ( '' === $zip ? 'the job has no ZIP.' : ( 'known' !== $state ? 'a line does not say what the work is.' : 'the ZIP ' . $zip . ' is inside a service area of the work (Zones).' ) );
					continue;
				}
				$trail[] = $no . ' decides: ' . $zip . ' is outside every service area of ' . $labels( $kinds ) . ', so it needs a referral; ' . ( 'install' === $stage ? 'no suggestion.' : 'keep the asking app\'s own pick.' );
				$res = self::result( $rules, $i, 0, true, $cands, $trail, $tr );
				$res['refer'] = true;
				return $res;
			}
			if ( 'default' === $rule['who'] ) {
				$trail[] = $no . ' decides: ' . ( 'install' === $stage ? 'no suggestion.' : 'keep the asking app\'s own pick.' );
				return self::result( $rules, $i, 0, true, $cands, $trail, $tr );
			}
			if ( 'territory' === $rule['who'] ) {
				if ( '' === $zip ) {
					$trail[] = $no . ': the stub has no ZIP.';
					continue;
				}
				$pool = ZTER_DB::people_for_zip( $zip );
				if ( null === $pool ) {
					$trail[] = $no . ': the territories could not be read just now.';
					continue;
				}
				if ( ! $pool ) {
					$trail[] = $no . ': nobody\'s territory has ' . $zip . '.';
					continue;
				}
			} elseif ( 'anyone' === $rule['who'] ) {
				$pool = array_map( function ( $p ) { return (int) $p['id']; }, zter_team() );
			} else {
				$pool = array( (int) $rule['person'] );
			}
			$ok = array();
			$skip = array_map( 'intval', (array) ( $job['exclude'] ?? array() ) );   // the rest is someone else's first
			$left = array();
			foreach ( $pool as $u ) {
				if ( in_array( (int) $u, $skip, true ) ) {
					continue;
				}
				// never a person at a ZIP inside one of their no-go zones (a job with no US ZIP too, when a zone says)
				if ( ZTER_Zones::excluded( (int) $u, $zip ) ) {
					$left[] = (int) $u;
					continue;
				}
				if ( zter_assignable( $u ) ) {
					$ok[] = (int) $u;
				} elseif ( 'anyone' !== $rule['who'] ) {
					$trail[] = $no . ': ' . self::person_words( $u ) . ' cannot be assigned work (not on the team, the shared device, or no initials).';
				}
			}
			if ( $left ) {
				// named for the admin tier; ZTER_REST::shape_row says only "an admin-only setting" to anyone else
				$trail[] = $no . ': ' . self::who_list( $left ) . ' ' . ( 1 === count( $left ) ? 'is' : 'are' ) . ' not given work ' . ( '' === $zip ? 'without a US ZIP' : 'in ' . $zip ) . ' ' . self::NOGO_MARK . '.';
			}
			if ( ! $ok ) {
				if ( 'anyone' === $rule['who'] && ! $left ) {
					$trail[] = $no . ': nobody on the team can be given work.';
				}
				continue;
			}
			$able = $ok;
			if ( $rule['need_can'] ) {
				if ( 'known' !== $state ) {
					$trail[] = $no . ': "can ' . $verb . '" could not be checked, because ' . ( 'none' === $state ? 'no line says what the work is' : 'a line does not say what the work is' ) . '.';
					if ( 'anyone' !== $rule['who'] ) {
						foreach ( $ok as $u ) {
							$cands[] = array( 'rule' => $rule['id'], 'u' => $u, 'ini' => zter_initials( $u ), 'levels' => array() );
						}
					}
					continue;
				}
				$able = array();
				$off  = false;
				$mine = array();
				// a rule that hands the rest on asks only about its own items (the rest is the next person's)
				$cjob = $job;
				if ( 'install' === $stage && $rule['with_rest'] && empty( $job['no_rest'] ) && $rule['kinds'] ) {   // never at the stub stage
					$own          = array_values( array_intersect( $kinds, $rule['kinds'] ) );
					$cjob['kinds'] = $own;
					$cjob['work']  = array_intersect_key( (array) $job['work'], array_flip( $own ) );
				}
				foreach ( $ok as $u ) {
					list( $can, $levels ) = self::can_do( $u, $cjob, $stage );
					if ( null === $can ) {
						$off = true;
						break;
					}
					if ( 'anyone' !== $rule['who'] || $can ) {
						$mine[] = array( 'rule' => $rule['id'], 'u' => $u, 'ini' => zter_initials( $u ), 'levels' => $levels );
					}
					if ( $can ) {
						$able[] = $u;
					}
				}
				if ( $off ) {
					$tr      = 'off';
					$trail[] = $no . ': Team Roles is not active (or has no items), so "can ' . $verb . '" could not be checked.';
					continue;
				}
				$tr    = 'on';
				$cands = array_merge( $cands, $mine );
				if ( ! $able ) {
					$trail[] = $no . ': ' . ( 'anyone' === $rule['who'] ? 'nobody' : self::who_list( $ok ) . ( 'territory' === $rule['who'] ? ' cover ' . $zip . ', but nobody there' : '' ) ) . ' is marked Can to ' . $verb . ' ' . $labels( $kinds ) . '.';
					continue;
				}
			} else {
				foreach ( $ok as $u ) {
					$cands[] = array( 'rule' => $rule['id'], 'u' => $u, 'ini' => zter_initials( $u ), 'levels' => array() );
				}
			}
			$seller = (int) $job['seller'];
			if ( $rule['seller_first'] && $seller > 0 && in_array( $seller, $able, true ) ) {
				$pick    = $seller;
				$trail[] = $no . ' decides: ' . zter_initials( $pick ) . ' (the salesperson, who can do it).';
			} else {
				$pick    = 1 === count( $able ) ? $able[0] : ZTER_DB::next_in_turn( $book, self::turn_key( $stage, $rule['id'] ), $able );
				$trail[] = $no . ' decides: ' . zter_initials( $pick ) . ( count( $able ) > 1 ? ' (taking turns among ' . self::who_list( $able ) . ')' : '' ) . '.';
			}
			$res = self::result( $rules, $i, $pick, false, $cands, $trail, $tr );
			$res['able'] = array_values( array_diff( $able, array( $pick ) ) );
			// The rest of the work: the rule's person takes its items, the rules below pick someone for the rest.
			if ( $rule['with_rest'] && empty( $job['no_rest'] ) ) {
				$rest = array_values( array_diff( $kinds, $rule['kinds'] ) );
				$res['own_kinds'] = array_values( array_intersect( $kinds, $rule['kinds'] ) );
				// a won job hands on only work that is named: an unnamed line is no installer's to guess
				if ( $rest || ( 'known' !== $state && 'install' !== $stage ) ) {
					$sub  = array( 'kinds' => $rest, 'work' => array_intersect_key( $job['work'], array_flip( $rest ) ), 'kind_state' => $rest ? $state : ( 'known' === $state ? 'none' : $state ) ) + $job;
					$sub['kinds'] = $rest;
					if ( 'install' === $stage ) {
						// the rest goes to someone else if anyone can take it (else the first person
						// does it all: one installer), and its evaluation never hands on a second time
						$sub['no_rest'] = true;
						$sub['exclude'] = array( $pick );
						$two = self::evaluate( $sub, $book, $rules, $stage, $i + 1 );
						if ( (int) $two['chosen_user_id'] <= 0 ) {
							$solo = self::evaluate( array( 'exclude' => array() ) + $sub, $book, $rules, $stage, $i + 1 );
							if ( (int) $solo['chosen_user_id'] === $pick ) {
								$two = $solo; // only the first person can do the rest too: one installer for the whole job
							}
						}
					} else {
						$two = self::evaluate( $sub, $book, $rules, $stage, $i + 1 );
					}
					$res['trail'] = array_merge( $res['trail'], array( 'The rest of the work (' . ( $rest ? $labels( $rest ) : 'the lines that do not say' ) . '):' ), $two['trail'] );
					$res['candidates'] = array_merge( $res['candidates'], $two['candidates'] );
					$sid = (int) $two['chosen_user_id'];
					// a won job's second installer is a person or nobody: "no suggestion" for the rest is not a second person;
					// named work nobody can take stays open (rest_open), never quietly folded into the first person's
					if ( 'install' === $stage && $rest && $sid <= 0 ) {
						$res['rest_open'] = $rest;
					}
					if ( $sid !== $pick && ( $sid > 0 || ( $two['use_default'] && 'install' !== $stage ) ) ) {
						$res['second'] = array( 'rule_id' => $two['rule_id'], 'rule_no' => $two['rule_no'], 'chosen_user_id' => $sid, 'use_default' => $two['use_default'] && $sid <= 0,
							'able' => array_values( (array) $two['able'] ), 'kinds' => $rest );
					}
				}
			}
			return $res;
		}
		$trail[] = 'install' === $stage ? 'No rule decided; no suggestion.' : 'No rule decided; keep the asking app\'s own pick.';
		return array( 'rule_id' => '', 'rule_version' => (int) $rules['version'], 'rule_no' => 0, 'live' => false, 'chosen_user_id' => 0, 'use_default' => true, 'candidates' => $cands, 'trail' => $trail, 'team_roles' => $tr, 'second' => null, 'able' => array(), 'refer' => false );
	}

	private static function result( $rules, $i, $uid, $use_default, $cands, $trail, $tr ) {
		$rule = $rules['rows'][ $i ];
		$no   = isset( $rule['_no'] ) ? (int) $rule['_no'] : $i + 1;
		return array(
			'rule_id' => $rule['id'], 'rule_version' => (int) $rules['version'], 'rule_no' => $no, 'live' => ! empty( $rule['live'] ),
			'chosen_user_id' => (int) $uid, 'use_default' => (bool) $use_default, 'candidates' => $cands, 'trail' => $trail, 'team_roles' => $tr,
			'second' => null, 'able' => array(), 'refer' => false,
		);
	}

	private static function who_list( array $uids ) {
		$ini = array();
		foreach ( $uids as $u ) {
			$ini[] = zter_initials( $u ) ?: '#' . (int) $u;
		}
		return implode( ', ', $ini );
	}

	/** "AB & CD" from an evaluation: the first pick and, with the rest, the second (the default's initials for "keep"). */
	private static function initials_of( array $ev, $default ) {
		$a = (int) $ev['chosen_user_id'] > 0 ? zter_initials( $ev['chosen_user_id'] ) : ( $ev['use_default'] ? $default : '' );
		if ( empty( $ev['second'] ) ) {
			return $a;
		}
		$b = (int) $ev['second']['chosen_user_id'] > 0 ? zter_initials( $ev['second']['chosen_user_id'] ) : ( ! empty( $ev['second']['use_default'] ) ? $default : '' );
		return ( '' === $b || $b === $a ) ? $a : $a . ' & ' . $b;
	}

	/** The people an evaluation names (user ids; 0 for "keep the default"). */
	private static function people_of( array $ev ) {
		$p = array( (int) $ev['chosen_user_id'] );
		if ( ! empty( $ev['second'] ) ) {
			$p[] = (int) $ev['second']['chosen_user_id'];
		}
		return $p;
	}

	/** The job as the rules read it, from what the asker sent. */
	private static function job_from( array $in ) {
		$zip  = ZTER_DB::clean_zip( is_scalar( $in['zip'] ?? '' ) ? (string) ( $in['zip'] ?? '' ) : '' );
		$from = '' !== $zip ? 'customer' : '';
		$ref  = substr( is_scalar( $in['reference'] ?? '' ) ? (string) ( $in['reference'] ?? '' ) : '', 0, 120 );
		if ( '' === $zip && preg_match( '/^\s*(\d{5})(?!\d)/', $ref, $m ) && '00000' !== $m[1] ) {
			$zip  = $m[1];
			$from = 'reference';
		}
		$lines = is_array( $in['lines'] ?? null ) ? array_slice( array_values( $in['lines'] ), 0, ZTER_Verbs::MAX_LINES ) : array();
		$k     = ZTER_Kinds::classify( $lines );
		$text  = ZTER_Kinds::all_text( $lines );
		$job   = array( 'zip' => $zip, 'kinds' => $k['kinds'], 'work' => $k['work'], 'kind_state' => $k['state'], 'generic' => $k['generic'], 'text' => $text,
			'seller' => is_scalar( $in['seller_user_id'] ?? 0 ) ? (int) ( $in['seller_user_id'] ?? 0 ) : 0 );
		return array( $job, $k, $ref, $from );
	}

	private static function kinds_stored( array $k ) {
		return array_map( function ( $it ) use ( $k ) { return $it . ( 'new' !== ( $k['work'][ $it ] ?? 'new' ) ? ':' . $k['work'][ $it ] : '' ); }, $k['kinds'] );
	}

	/* ── Routing (the work behind territories.route_for and territories.installer_for) ────────────────── */

	/**
	 * Who gets this stub (stage stub) or this won job (stage install). Records the decision (unless $in['dry']) and
	 * returns [ answer, record ].
	 *
	 * $in: zip, reference, lines[], default_initials, test, label, asker_id, dry, key (the caller's name for this job,
	 * so the same job asked again gets the same decision), pairs (the asker can write two people: "AB & CD"),
	 * seller_user_id (install: the job's salesperson, for "the salesperson first"), stage.
	 * The ZIP comes from `zip`, or else the first five digits of `reference` ("10001-Example"), so the answer does not
	 * depend on the order the asking app fills the customer's ZIP in.
	 *
	 * The answer: initials, name, user_id, decision_id, mode, people[]. In shadow, `initials` is the default that was
	 * passed in (or '' when none was): the caller keeps doing what it does today. A two-person answer goes only to an
	 * asker that says it can write one (`pairs`); anyone else keeps its default, and the trail says why.
	 */
	public static function route( array $in ) {
		$stage = self::stage( $in['stage'] ?? 'stub' );
		list( $job, $k, $ref, $from ) = self::job_from( $in );
		$zip     = $job['zip'];
		$default = strtoupper( trim( is_scalar( $in['default_initials'] ?? '' ) ? (string) ( $in['default_initials'] ?? '' ) : '' ) );
		$default = preg_match( '/^[A-Z]{2,3}$/', $default ) ? $default : '';
		$asker   = (int) ( $in['asker_id'] ?? 0 );
		$dry     = ! empty( $in['dry'] );
		$label   = substr( is_scalar( $in['label'] ?? '' ) ? (string) ( $in['label'] ?? '' ) : '', 0, 80 );
		$test    = ! empty( $in['test'] );
		$pairs   = ! empty( $in['pairs'] ); // the asker can show two people (a stub's two sellers, a won job's two installers)
		$stub    = substr( is_scalar( $in['key'] ?? '' ) ? (string) ( $in['key'] ?? '' ) : '', 0, 64 );
		$rules   = self::get( $stage );
		$text    = $job['text'];

		// The same job asked again gets the same answer and takes no second turn. Only when the caller names it (`key`):
		// two different customers' stubs can have the same ZIP and lines, and Territories never sees who
		// the customer is. The key also holds the stage, the rules version, the territories generation and the words
		// table, so an edit is never hidden behind an earlier answer.
		$key = '' === $stub ? '' : md5( $stage . '|' . $asker . '|' . $stub . '|' . $zip . '|' . $default . '|' . $job['seller'] . '|' . ( $pairs ? 'p' : '' ) . '|' . $rules['version'] . '|' . ZTER_DB::generation() . '|' . md5( wp_json_encode( ZTER_Kinds::rows() ) ) . '|' . md5( $ref . '|' . $text ) );
		if ( ! $dry && '' !== $key ) {
			// a won job is asked about each time the Jobs app builds its suggestions: the same job (same key, rules, words
			// and territories) is the same decision for good, never a new row and turn
			$prev = ZTER_DB::recent_by_key( $key, 'install' === $stage ? 0 : 10 );
			if ( $prev ) {
				// the others who could (the Jobs app's "Another") are not stored: work them out again, without a turn
				$prev['live_able'] = array();
				$prev['live_able2'] = array();
				$prev['rest_open']  = array();
				if ( 'install' === $stage && 'live' === $prev['mode'] ) {
					$lr = array_values( array_filter( $rules['rows'], function ( $r ) { return ! empty( $r['live'] ); } ) );
					$lv = self::evaluate( $job, $test ? 'test' : 'live', array( 'version' => $rules['version'], 'rows' => $lr ), $stage );
					$prev['live_able'] = array_values( array_diff( array_merge( (array) $lv['able'], array( (int) $lv['chosen_user_id'] ) ), array( (int) $prev['live_user_id'], 0 ) ) );
					// the others who could do the rest (the second installer's "Another")
					$prev['rest_open'] = (array) ( $lv['rest_open'] ?? array() );
					$p2 = (int) ( $prev['live_people'][1] ?? 0 );
					if ( $p2 > 0 && ! empty( $lv['second'] ) ) {
						$prev['live_able2'] = array_values( array_diff( array_merge( (array) $lv['second']['able'], array( (int) $lv['second']['chosen_user_id'] ) ), array( $p2, 0 ) ) );
					}
				}
				return array( self::answer_from( $prev ), $prev );
			}
		}

		// What the rules would do: every rule, in order, live or not. This is the shadow record.
		$ev         = self::evaluate( $job, $test ? 'test' : 'shadow', $rules, $stage );
		$uid        = (int) $ev['chosen_user_id'];
		$chosen_ini = self::initials_of( $ev, $default );

		// What is answered: the LIVE rules only. A rule still in shadow never changes a live answer, wherever it sits in
		// the list. No live rule matching = today's pick (stub) or no suggestion (install).
		$mode      = 'shadow';
		$answered  = $default;
		$live_id   = '';
		$live_uid  = 0;
		$live_ppl  = array();
		$live_able = array();
		$live_able2 = array();
		$rest_open  = array();
		$live_rows = array();
		foreach ( $rules['rows'] as $i => $r ) {
			if ( ! empty( $r['live'] ) ) {
				$live_rows[] = $r + array( '_no' => $i + 1 );
			}
		}
		if ( $live_rows ) {
			$lv = self::evaluate( $job, $test ? 'test' : 'live', array( 'version' => $rules['version'], 'rows' => $live_rows ), $stage );
			if ( '' !== $lv['rule_id'] ) {
				$two  = ! empty( $lv['second'] );
				$open = 'install' === $stage && ! empty( $lv['rest_open'] );
				if ( ( $two || $open ) && ! $pairs ) {
					$ev['trail'][] = 'install' === $stage
						? 'Live: rule ' . $lv['rule_no'] . ( $two ? ' names two installers (' . self::initials_of( $lv, '' ) . ')' : ' names an installer for part of the work, and nobody for the rest' ) . ', and the app asking shows one; no suggestion.'
						: 'Live: rule ' . $lv['rule_no'] . ' names two people (' . self::initials_of( $lv, $default ) . '), and the app asking writes one; it keeps ' . ( '' !== $default ? $default : 'its own pick' ) . '.';
				} else {
					$mode      = 'live';
					// a pair keeps both rules ("r1+territory"): each person's live turn is under their own rule
					$live_id   = $lv['rule_id'] . ( $two ? '+' . $lv['second']['rule_id'] : '' );
					$live_uid  = (int) $lv['chosen_user_id'];
					$live_ppl  = self::people_of( $lv );
					$live_able = $lv['able'];
					$live_able2 = $two ? (array) ( $lv['second']['able'] ?? array() ) : array();
					$rest_open  = $open ? (array) $lv['rest_open'] : array();
					$answered  = $two ? self::initials_of( $lv, $default ) : ( $live_uid > 0 ? zter_initials( $live_uid ) : $default );
					$ev['trail'][] = 'Live: rule ' . $lv['rule_no'] . ' answers ' . ( '' !== $answered ? $answered : ( 'install' === $stage ? 'no suggestion' : 'the asking app\'s own pick' ) ) . '.';
				}
			} else {
				$ev['trail'][] = 'install' === $stage ? 'No live rule matched, so there is no suggestion.' : 'No live rule matched, so today\'s pick was answered.';
			}
		}
		if ( 'install' === $stage && 'live' !== $mode ) {
			$answered = '';
		}

		// Outside every service area: 2 when a LIVE rule said so, 1 when only the shadow list did. The case keeps
		// what the referral list needs to find the estimate again (the caller's key and reference; the estimate number arrives
		// with zest_operator_routed), never the customer's name, phone or email.
		$refer = ( $live_rows && ! empty( $lv['refer'] ) && '' !== $lv['rule_id'] ) ? 2 : ( ! empty( $ev['refer'] ) ? 1 : 0 );
		if ( $refer ) {
			$ev['trail'][] = 2 === $refer ? 'Needs a referral (live): it is on the referral list.' : 'Needs a referral (shadow): it is on the referral list, marked Shadow.';
		}
		// Nobody named by any rule, and not a referral: a person decides. Core never names someone by default.
		$review = ! $refer && $uid <= 0 && ( empty( $ev['second'] ) || (int) $ev['second']['chosen_user_id'] <= 0 ) && ( 'live' !== $mode || $live_uid <= 0 );
		if ( $review ) {
			$ev['trail'][] = 'No rule names anyone for this ' . ( 'install' === $stage ? 'job' : 'stub' ) . ': a person decides (review).';
		}
		$rec = array(
			// the caller's key on every decision (a hash the estimate app makes; the Jobs app's "project-<id>"), so a later answer for
			// the same job (its ZIP corrected, the zones widened) closes an earlier referral
			'refer' => $refer, 'review' => $review, 'stub_key' => $stub, 'reference' => $refer ? $ref : '',
			'stage' => $stage, 'asker_id' => $asker, 'mode' => $mode, 'is_test' => $test, 'label' => $label,
			'zip' => $zip, 'zip_from' => '' === $zip ? 'none' : $from, 'kinds' => self::kinds_stored( $k ), 'kind_state' => $k['state'], 'kind_notes' => $k['notes'],
			'rule_id' => $ev['rule_id'], 'rule_version' => $ev['rule_version'], 'default_initials' => $default,
			'chosen_user_id' => $uid, 'chosen_initials' => $chosen_ini, 'answered_initials' => $answered,
			'chosen_people' => self::people_of( $ev ), 'live_people' => $live_ppl, 'seller_user_id' => $job['seller'],
			'live_rule_id' => $live_id, 'live_user_id' => $live_uid,
			'candidates' => $ev['candidates'], 'team_roles' => $ev['team_roles'], 'trail' => $ev['trail'], 'dedupe_key' => $key,
		);
		$rec['id'] = $dry ? 0 : ZTER_DB::add_decision( $rec );
		if ( $rec['id'] > 0 ) {
			// The shadow (or test) turns, only once the decision is on record.
			$book = $test ? 'test' : 'shadow';
			if ( $uid > 0 ) {
				ZTER_DB::take_turn( $book, self::turn_key( $stage, $ev['rule_id'] ), $uid );
			}
			if ( ! empty( $ev['second'] ) && (int) $ev['second']['chosen_user_id'] > 0 ) {
				ZTER_DB::take_turn( $book, self::turn_key( $stage, $ev['second']['rule_id'] ), (int) $ev['second']['chosen_user_id'] );
			}
		}
		$rec['rule_no']   = $ev['rule_no'];
		$rec['live_able'] = $live_able;
		$rec['live_able2'] = $live_able2;
		$rec['rest_open']  = $rest_open;
		return array( self::answer_from( $rec ), $rec );
	}

	/** A rule's place in the list, by id (1-based), or 0. */
	public static function number_of( array $rules, $id ) {
		$id = explode( '+', (string) $id )[0]; // a pair's decision keeps "first+second": the number is the first's
		foreach ( $rules['rows'] as $i => $r ) {
			if ( $r['id'] === $id ) {
				return $i + 1;
			}
		}
		return 0;
	}

	/* ── A batch preview (the work behind territories.preview_route) ───────────────────────────── */

	const MAX_PREVIEW = 200;

	/**
	 * Who would get each of these existing stubs, from the LIVE stub rules only, as route() answers a new stub. A dry
	 * run: no decision is recorded and no turn is stored. Turns are taken in memory across the batch, in order, so stubs
	 * that share a ZIP held by two people are proposed alternately, as they would really be given (ZTER_DB::sim_*).
	 *
	 * $stubs: [ { ref, zip, reference, lines[], default_initials, test }, … ] (at most 200; ref is the caller's own
	 * label for the stub, echoed back; Territories never learns who the customer is). $pairs: the asker can write two
	 * people; otherwise a two-person answer keeps the default and says so.
	 *
	 * Each row: ref, zip, zip_from, kinds[{key,label}], kind_state, mode ('live' when a live rule decided, else
	 * 'shadow'), change (a live rule names someone other than the default), initials / user_id / name (the proposal; the
	 * default when there is no change; "AB & CD" with people[] for a pair), rule_no / rule_id / sentence (the live rule
	 * that decided, else 0/''/''), why (one plain sentence), and shadow { initials, rule_no } (what every rule, live or
	 * not, would pick: shown for comparison, never proposed).
	 */
	public static function preview( array $stubs, $pairs = false ) {
		$rules = self::get( 'stub' );
		$abs   = ZTER_Kinds::abilities();
		$live  = array();
		foreach ( $rules['rows'] as $i => $r ) {
			if ( ! empty( $r['live'] ) ) {
				$live[] = $r + array( '_no' => $i + 1 );
			}
		}
		$out = array();
		ZTER_DB::sim_begin();
		try {
			foreach ( array_slice( array_values( $stubs ), 0, self::MAX_PREVIEW ) as $s ) {
				$s = is_array( $s ) ? $s : array();
				$out[] = self::preview_one( $s, $rules, $live, $abs, (bool) $pairs );
			}
		} finally {
			ZTER_DB::sim_end();
		}
		return array( 'rows' => $out, 'rules_version' => (int) $rules['version'], 'live_rules' => count( $live ) );
	}

	private static function preview_one( array $s, array $rules, array $live, $abs, $pairs ) {
		list( $job, $k, , $from ) = self::job_from( $s );
		$zip     = $job['zip'];
		$default = strtoupper( trim( is_scalar( $s['default_initials'] ?? '' ) ? (string) ( $s['default_initials'] ?? '' ) : '' ) );
		$default = preg_match( '/^[A-Z]{2,3}$/', $default ) ? $default : '';
		$test    = ! empty( $s['test'] );
		$def     = '' !== $default ? $default : 'the asking app\'s own pick';
		$row     = array(
			'ref' => substr( is_scalar( $s['ref'] ?? '' ) ? (string) ( $s['ref'] ?? '' ) : '', 0, 40 ), 'zip' => $zip, 'zip_from' => '' === $zip ? 'none' : $from,
			'kinds' => array_map( function ( $x ) use ( $abs ) { return array( 'key' => $x, 'label' => ZTER_Kinds::label( $x, $abs ) ); }, $k['kinds'] ),
			'kind_state' => $k['state'], 'mode' => 'shadow', 'change' => false,
			'initials' => $default, 'user_id' => 0, 'name' => '', 'people' => array(), 'rule_no' => 0, 'rule_id' => '', 'sentence' => '', 'why' => '',
		);

		// What every rule would pick (shadow): for comparison only. Both evaluations see the turns as they stand before
		// this stub, as route() does; the turns are taken after (a test stub's two books are one).
		$ev = self::evaluate( $job, $test ? 'test' : 'shadow', $rules, 'stub' );
		$lv = $live ? self::evaluate( $job, $test ? 'test' : 'live', array( 'version' => $rules['version'], 'rows' => $live ), 'stub' ) : null;
		$sb = $test ? 'test' : 'shadow';
		if ( $ev['chosen_user_id'] > 0 ) {
			ZTER_DB::take_turn( $sb, $ev['rule_id'], $ev['chosen_user_id'] ); // in memory
		}
		if ( ! empty( $ev['second'] ) && $ev['second']['chosen_user_id'] > 0 ) {
			ZTER_DB::take_turn( $sb, $ev['second']['rule_id'], $ev['second']['chosen_user_id'] );
		}
		$row['shadow'] = array( 'initials' => self::initials_of( $ev, $default ), 'rule_no' => (int) $ev['rule_no'] );

		if ( ! $live ) {
			$row['why'] = 'Every rule is in shadow, so Territories keeps ' . $def . '. Rules go live one at a time from Territories\' Rules view.';
			return $row;
		}
		if ( '' === $lv['rule_id'] ) {
			$last       = $lv['trail'] ? (string) $lv['trail'][ count( $lv['trail'] ) - 1 ] : '';
			$row['why'] = 'No live rule matched, so it keeps ' . $def . '.' . ( '' !== $last && 0 !== strpos( $last, 'No rule decided' ) ? ' ' . $last : '' );
			return $row;
		}
		$no              = (int) $lv['rule_no'];
		$rule            = $rules['rows'][ $no - 1 ];
		$row['mode']     = 'live';
		$row['rule_no']  = $no;
		$row['rule_id']  = $lv['rule_id'];
		$row['sentence'] = self::sentence( $rule, $abs, 'stub' );
		$uid             = (int) $lv['chosen_user_id'];
		$lb              = $test ? 'test' : 'live';
		if ( ! empty( $lv['second'] ) ) {
			$both = self::initials_of( $lv, $default );
			if ( ! $pairs ) {
				$row['why'] = 'Rule ' . $no . ' (live) names two people (' . $both . '); this batch writes one, so it keeps ' . $def . '.';
				return $row;
			}
			ZTER_DB::take_turn( $lb, $lv['rule_id'], $uid );
			if ( $lv['second']['chosen_user_id'] > 0 ) {
				ZTER_DB::take_turn( $lb, $lv['second']['rule_id'], $lv['second']['chosen_user_id'] );
			}
			$row['people'] = array_map( function ( $p ) use ( $default ) {
				$u = $p > 0 ? get_userdata( $p ) : false;
				return array( 'user_id' => (int) $p, 'initials' => $p > 0 ? zter_initials( $p ) : $default, 'name' => $u ? (string) ( $u->display_name ?? '' ) : '' );
			}, self::people_of( $lv ) );
			$row['change']   = $both !== $default;
			$row['initials'] = $both;
			$row['user_id']  = $uid;
			$row['name']     = implode( ' & ', array_filter( array_map( function ( $p ) { return $p['name']; }, $row['people'] ) ) );
			$row['why']      = 'Rule ' . $no . ' (live) picks ' . $both . ' (' . zter_initials( $uid ) . ' for ' . implode( ', ', array_map( function ( $x ) use ( $abs ) { return ZTER_Kinds::label( $x, $abs ); }, $rule['kinds'] ) ) . ', the rest by rule ' . (int) $lv['second']['rule_no'] . ').';
			return $row;
		}
		if ( $uid <= 0 ) {
			$row['why'] = 'Rule ' . $no . ' (live) keeps ' . $def . '.';
			return $row;
		}
		$ini = zter_initials( $uid );
		ZTER_DB::take_turn( $lb, $lv['rule_id'], $uid ); // in memory: the next stub in the batch takes the next turn
		$decides = '';
		foreach ( $lv['trail'] as $t ) {
			if ( preg_match( '/^Rule \d+ decides: /', (string) $t ) ) {
				$decides = (string) $t;
			}
		}
		if ( $ini === $default ) {
			$row['why'] = 'Rule ' . $no . ' (live) picks ' . $ini . ', who already has it.';
			return $row;
		}
		$u = get_userdata( $uid );
		$row['change']   = true;
		$row['initials'] = $ini;
		$row['user_id']  = $uid;
		$row['name']     = $u ? (string) ( $u->display_name ?? '' ) : '';
		$row['people']   = array( array( 'user_id' => $uid, 'initials' => $ini, 'name' => $row['name'] ) );
		$row['why']      = preg_replace( '/^Rule \d+ decides: /', 'Rule ' . $no . ' (live) picks ', $decides );
		return $row;
	}

	/** What the asker gets: never the trail (it stays in this app). */
	public static function answer_from( array $rec ) {
		$live   = 'live' === ( $rec['mode'] ?? '' );
		$uid    = $live ? (int) ( $rec['live_user_id'] ?? 0 ) : 0;
		$u      = $uid > 0 ? get_userdata( $uid ) : false;
		$people = array();
		if ( $live ) {
			$ini = explode( ' & ', (string) ( $rec['answered_initials'] ?? '' ) );
			foreach ( array_values( (array) ( $rec['live_people'] ?? array() ) ) as $n => $p ) {
				$pu       = (int) $p > 0 ? get_userdata( (int) $p ) : false;
				$people[] = array( 'user_id' => $pu ? (int) $p : 0, 'initials' => (string) ( $ini[ $n ] ?? '' ), 'name' => $pu ? (string) ( $pu->display_name ?? '' ) : '' );
			}
		}
		return array(
			'success'     => true,
			'initials'    => (string) ( $rec['answered_initials'] ?? '' ),
			'name'        => $u ? (string) ( $u->display_name ?? '' ) : '',
			'user_id'     => $u ? $uid : 0,
			'decision_id' => (int) ( $rec['id'] ?? 0 ),
			'mode'        => $live ? 'live' : 'shadow',
			'people'      => $people,
			'review'      => ! empty( $rec['review'] ),
		);
	}
}
