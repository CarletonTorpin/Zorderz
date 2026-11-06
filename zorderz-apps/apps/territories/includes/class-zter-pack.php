<?php
/**
 * ZTER_Pack — Territories' part of an Identity Pack (territories.yml).
 *
 *   zones:        the zones list, as the zones editor writes it. A no-go zone names its people by their
 *                 initials (people: [AB]); service zones name Team Roles items in `abilities`.
 *   rules:        { stub: [ … ], install: [ … ] }, each rule as the rules editor writes it; a named person
 *                 is `person: AB`. EVERY RULE A PACK WRITES IS IN SHADOW: the asking apps keep their own
 *                 picks and the rules' choices are recorded beside them until a person turns each rule live
 *                 from the go-live checklist. A list without "everything else" gets the one that names nobody.
 *   territories:  [ { person: AB, zips: [ "10001", … ] } ]: each person named gets exactly these ZIPs;
 *                 people the pack does not name keep theirs.
 *
 * Everything is checked as the page checks it (ZTER_Zones::clean, ZTER_Rules::check_rows, ZTER_DB::parse_zips).
 * A person the pack names who is not on the team, has no initials or shares them, an unknown item, a bad ZIP:
 * that zone, rule or territory is skipped and listed with the reason, never guessed. A ZIP is five digits; write
 * it in quotes so a leading zero survives.
 *
 * Revert puts back what the pack replaced, as new versions (the History keeps both), and only where nothing
 * changed since the pack: a zone list, a rule list or a person's ZIPs edited after the pack is left as it is and
 * the revert says so. Rules put back come back in shadow too, like every revert in Territories: the ones that were
 * live before the pack are named, so a person can turn them live again.
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Pack {

	/** What the most recent apply wrote, so revert can tell whether anything changed since. */
	const APPLIED_OPTION = 'zter_pack_applied';

	const ZONE_KEYS = array( 'id', 'label', 'short', 'kind', 'visible', 'everywhere', 'zips', 'prefixes', 'outside', 'nozip', 'people', 'abilities', 'color' );
	const RULE_KEYS = array( 'id', 'words', 'kinds', 'zips', 'who', 'person', 'need_can', 'fallback', 'all_kinds', 'unknown_only', 'in_area', 'with_rest', 'seller_first', 'live' );

	public static function register( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		$consumers['territories'] = array(
			'label'    => __( 'Territories', 'zorderz' ),
			'files'    => array( 'territories' ),
			'paths'    => array( 'territories' => array( 'zones', 'rules', 'territories' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	private static function rules_option( $stage ) {
		return 'install' === ZTER_Rules::stage( $stage ) ? ZTER_Rules::OPTION_INSTALL : ZTER_Rules::OPTION;
	}

	/** The team's ids: the only people a pack may name. */
	private static function team_ids() {
		return array_map( function ( $p ) { return (int) $p['id']; }, zter_team() );
	}

	private static function person( $ref ) {
		if ( ! class_exists( 'ZDZ_Identity_Pack' ) ) {
			return array( 'id' => 0, 'why' => 'The Identity Pack is not loaded.' );
		}
		return ZDZ_Identity_Pack::person_id( $ref, self::team_ids() );
	}

	private static function shown( $v ) {
		return is_scalar( $v ) ? (string) $v : ( function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $v ) : (string) json_encode( $v ) );
	}

	/**
	 * What the pack's territories file would write, checked:
	 * { zones: rows|null, rules: [ stage => rows ], zips: [ uid => zips ], names: [ uid => initials ],
	 *   refused: [ {path, why} ], unconsumed: [ path ] }. Null when the file says nothing Territories reads.
	 */
	public static function plan( array $files ) {
		$t = $files['territories'] ?? null;
		if ( ! is_array( $t ) || ( ! isset( $t['zones'] ) && ! isset( $t['rules'] ) && ! isset( $t['territories'] ) ) ) {
			return null;
		}
		$out = array( 'zones' => null, 'rules' => array(), 'zips' => array(), 'names' => array(), 'refused' => array(), 'unconsumed' => array() );

		if ( isset( $t['zones'] ) ) {
			$rows = array();
			foreach ( is_array( $t['zones'] ) ? array_values( $t['zones'] ) : array() as $i => $z ) {
				$name = is_array( $z ) ? (string) ( $z['label'] ?? $z['id'] ?? ( $i + 1 ) ) : (string) ( $i + 1 );
				$path = 'zones.' . $name;
				if ( ! is_array( $z ) ) {
					$out['refused'][] = array( 'path' => $path, 'why' => __( 'A zone is a set of named values.', 'zorderz' ) );
					continue;
				}
				foreach ( array_diff( array_map( 'strval', array_keys( $z ) ), self::ZONE_KEYS ) as $k ) {
					$out['unconsumed'][] = "$path.$k";
				}
				if ( 'nogo' === ( $z['kind'] ?? '' ) ) {
					$ids = array();
					foreach ( (array) ( $z['people'] ?? array() ) as $ref ) {
						$p = self::person( $ref );
						if ( $p['id'] ) {
							$ids[] = $p['id'];
						} else {
							$out['refused'][] = array( 'path' => "$path.people", 'why' => $p['why'] );
						}
					}
					if ( ! $ids ) {
						$out['refused'][] = array( 'path' => $path, 'why' => __( 'A no-go zone needs the people it keeps out; none of them is on the team here.', 'zorderz' ) );
						continue;
					}
					$z['people'] = $ids;
				}
				if ( 'service' === ( $z['kind'] ?? 'service' ) && ! empty( $z['abilities'] ) ) {
					$known = ZTER_Kinds::abilities();
					if ( is_array( $known ) ) {
						foreach ( (array) $z['abilities'] as $k ) {
							if ( ! isset( $known[ sanitize_key( (string) $k ) ] ) ) {
								$out['refused'][] = array( 'path' => "$path.abilities", 'why' => sprintf( __( 'Team Roles has no item or ability "%s"; the zone is kept without it.', 'zorderz' ), (string) $k ) );
							}
						}
					}
				}
				$c = ZTER_Zones::clean( $z, $i );
				if ( is_wp_error( $c ) ) {
					$out['refused'][] = array( 'path' => $path, 'why' => $c->get_error_message() );
					continue;
				}
				$rows[] = $z;
			}
			if ( count( $rows ) > ZTER_Zones::MAX_ZONES ) {
				$out['refused'][] = array( 'path' => 'zones', 'why' => sprintf( __( 'At most %d zones.', 'zorderz' ), ZTER_Zones::MAX_ZONES ) );
			} elseif ( is_array( $t['zones'] ) ) {
				$out['zones'] = $rows;
			}
		}

		if ( isset( $t['rules'] ) ) {
			$given = is_array( $t['rules'] ) ? $t['rules'] : array();
			foreach ( array_diff( array_map( 'strval', array_keys( $given ) ), ZTER_Rules::STAGES ) as $k ) {
				$out['unconsumed'][] = "rules.$k";
			}
			foreach ( ZTER_Rules::STAGES as $stage ) {
				if ( ! isset( $given[ $stage ] ) ) {
					continue;
				}
				$rows = array();
				$last = null;
				foreach ( is_array( $given[ $stage ] ) ? array_values( $given[ $stage ] ) : array() as $i => $r ) {
					$path = "rules.$stage." . ( is_array( $r ) && ! empty( $r['id'] ) ? (string) $r['id'] : ( $i + 1 ) );
					if ( ! is_array( $r ) ) {
						$out['refused'][] = array( 'path' => $path, 'why' => __( 'A rule is a set of named values.', 'zorderz' ) );
						continue;
					}
					foreach ( array_diff( array_map( 'strval', array_keys( $r ) ), self::RULE_KEYS ) as $k ) {
						$out['unconsumed'][] = "$path.$k";
					}
					if ( 'person' === ( $r['who'] ?? '' ) ) {
						$p = self::person( $r['person'] ?? '' );
						if ( ! $p['id'] ) {
							$out['refused'][] = array( 'path' => "$path.person", 'why' => $p['why'] . ' ' . __( 'The rule is left out.', 'zorderz' ) );
							continue;
						}
						$r['person'] = $p['id'];
					} else {
						unset( $r['person'] );
					}
					$r['live'] = false; // a pack never turns a rule live
					$c         = ZTER_Rules::clean_rule( $r, true, $stage );
					if ( is_wp_error( $c ) ) {
						$out['refused'][] = array( 'path' => $path, 'why' => $c->get_error_message() . ' ' . __( 'The rule is left out.', 'zorderz' ) );
						continue;
					}
					if ( $c['fallback'] ) {
						$last = $r;       // "everything else" goes last, wherever the pack wrote it
						continue;
					}
					$rows[] = $r;
				}
				$rows[]  = null !== $last ? $last + array( 'id' => 'fallback' ) : array( 'id' => 'fallback', 'fallback' => true, 'who' => 'default', 'live' => false );
				$checked = ZTER_Rules::check_rows( $rows, $stage );
				if ( is_wp_error( $checked ) ) {
					$out['refused'][] = array( 'path' => "rules.$stage", 'why' => $checked->get_error_message() . ' ' . __( 'This list is left as it is.', 'zorderz' ) );
					continue;
				}
				foreach ( $checked as &$c ) {
					$c['live'] = false;
				}
				unset( $c );
				$out['rules'][ $stage ] = $checked;
			}
		}

		if ( isset( $t['territories'] ) ) {
			foreach ( is_array( $t['territories'] ) ? array_values( $t['territories'] ) : array() as $i => $e ) {
				$ref  = is_array( $e ) ? ( $e['person'] ?? '' ) : '';
				$path = 'territories.' . ( is_scalar( $ref ) && '' !== (string) $ref ? (string) $ref : ( $i + 1 ) );
				if ( ! is_array( $e ) ) {
					$out['refused'][] = array( 'path' => $path, 'why' => __( 'Write each territory as a person and their ZIP codes.', 'zorderz' ) );
					continue;
				}
				foreach ( array_diff( array_map( 'strval', array_keys( $e ) ), array( 'person', 'zips' ) ) as $k ) {
					$out['unconsumed'][] = "$path.$k";
				}
				$p = self::person( $ref );
				if ( ! $p['id'] ) {
					$out['refused'][] = array( 'path' => $path, 'why' => $p['why'] );
					continue;
				}
				list( $ok, $bad ) = ZTER_DB::parse_zips( is_array( $e['zips'] ?? null ) ? array_filter( $e['zips'], 'is_scalar' ) : (string) ( $e['zips'] ?? '' ) );
				if ( $bad ) {
					$out['refused'][] = array( 'path' => $path, 'why' => sprintf( __( 'These are not five-digit ZIP codes: %s. This territory is left as it is.', 'zorderz' ), implode( ', ', array_slice( $bad, 0, 8 ) ) ) );
					continue;
				}
				if ( count( $ok ) > ZTER_DB::MAX_ZIPS_PER_PERSON ) {
					$out['refused'][] = array( 'path' => $path, 'why' => sprintf( __( 'At most %d ZIP codes per person.', 'zorderz' ), ZTER_DB::MAX_ZIPS_PER_PERSON ) );
					continue;
				}
				$out['zips'][ $p['id'] ]  = $ok;
				$out['names'][ $p['id'] ] = ZDZ_Identity_Pack::person_ref( $ref );
			}
		}
		return $out;
	}

	private static function zone_words( array $z ) {
		$n = ! empty( $z['everywhere'] ) ? __( 'everywhere', 'zorderz' ) : sprintf( _n( '%d ZIP', '%d ZIPs', count( (array) ( $z['zips'] ?? array() ) ) + count( (array) ( $z['prefixes'] ?? array() ) ), 'zorderz' ), count( (array) ( $z['zips'] ?? array() ) ) + count( (array) ( $z['prefixes'] ?? array() ) ) );
		return sprintf( '%s (%s, %s)', (string) $z['label'], (string) $z['kind'], $n );
	}

	/** Rows for the Identity Pack preview: what changes, what is refused, what nothing reads, and notes. */
	public static function preview( array $files ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return array();
		}
		$rows = array();
		if ( null !== $plan['zones'] ) {
			$now = array();
			foreach ( ZTER_Zones::all()['rows'] as $z ) {
				$now[ $z['id'] ] = $z;
			}
			$seen = array();
			foreach ( $plan['zones'] as $i => $z ) {
				$c = ZTER_Zones::clean( $z, $i );
				$seen[ $c['id'] ] = true;
				$was = $now[ $c['id'] ] ?? null;
				if ( $was !== $c ) {
					$rows[] = array( 'file' => 'territories', 'path' => 'zones.' . $c['id'], 'from' => $was ? self::zone_words( $was ) : __( '(none)', 'zorderz' ), 'to' => self::zone_words( $c ) );
				}
			}
			foreach ( $now as $id => $z ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$rows[] = array( 'file' => 'territories', 'path' => 'zones.' . $id, 'from' => self::zone_words( $z ), 'to' => __( '(removed)', 'zorderz' ) );
				}
			}
		}
		foreach ( $plan['rules'] as $stage => $list ) {
			$cur  = ZTER_Rules::get( $stage );
			$live = count( array_filter( $cur['rows'], function ( $r ) { return ! empty( $r['live'] ); } ) );
			$rows[] = array(
				'file' => 'territories',
				'path' => "rules.$stage",
				/* translators: 1: version, 2: rules, 3: live rules */
				'from' => sprintf( __( 'version %1$d: %2$d rules, %3$d live', 'zorderz' ), (int) $cur['version'], count( $cur['rows'] ), $live ),
				/* translators: %d: rules */
				'to'   => sprintf( __( '%d rules, every one in shadow', 'zorderz' ), count( $list ) ),
			);
			if ( $live ) {
				/* translators: 1: number of live rules, 2: stage */
				$rows[] = array( 'file' => 'territories', 'kind' => 'note', 'note' => sprintf( __( '%1$d live %2$s rule(s) go back to shadow: the asking apps keep their own picks until a person turns rules live again from the go-live checklist.', 'zorderz' ), $live, $stage ) );
			}
		}
		$changed = false;
		foreach ( $plan['zips'] as $uid => $zips ) {
			$have = ZTER_DB::zips_of( $uid );
			if ( $have !== $zips ) {
				$changed = true;
				$rows[]  = array( 'file' => 'territories', 'path' => 'territories.' . $plan['names'][ $uid ], 'from' => $have ? implode( ', ', $have ) : __( '(none)', 'zorderz' ), 'to' => implode( ', ', $zips ) );
			}
		}
		if ( $changed ) {
			$live_territory = 0;
			foreach ( ZTER_Rules::STAGES as $st ) {
				foreach ( ZTER_Rules::get( $st )['rows'] as $r ) {
					$live_territory += ( ! empty( $r['live'] ) && 'territory' === $r['who'] && ! isset( $plan['rules'][ $st ] ) ) ? 1 : 0;
				}
			}
			if ( $live_territory ) {
				/* translators: %d: number of live rules */
				$rows[] = array( 'file' => 'territories', 'kind' => 'note', 'note' => sprintf( __( '%d live rule(s) pick by territory: the new ZIP codes count for them as soon as the pack is applied.', 'zorderz' ), $live_territory ) );
			}
		}
		foreach ( $plan['refused'] as $r ) {
			$rows[] = array( 'file' => 'territories', 'kind' => 'refused', 'path' => $r['path'], 'why' => $r['why'] );
		}
		foreach ( array_unique( $plan['unconsumed'] ) as $p ) {
			$rows[] = array( 'file' => 'territories', 'kind' => 'unconsumed', 'path' => $p );
		}
		return $rows;
	}

	/** Before anything is written: the zone list, both rule lists and the ZIPs of the people the pack names. */
	public static function snapshot( array $files = array(), $slug = '' ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return null;
		}
		$keep = array( 'zones' => null, 'rules' => array(), 'zips' => array() );
		if ( null !== $plan['zones'] ) {
			$o             = get_option( ZTER_Zones::OPTION, null );
			$keep['zones'] = array( 'rows' => is_array( $o ) ? array_values( (array) ( $o['rows'] ?? array() ) ) : array() );
		}
		foreach ( array_keys( $plan['rules'] ) as $stage ) {
			// the list as stored (a list never saved is the one "everything else" rule)
			$o                       = get_option( self::rules_option( $stage ), null );
			$keep['rules'][ $stage ] = is_array( $o ) && ! empty( $o['rows'] ) ? array_values( (array) $o['rows'] ) : ZTER_Rules::seed_rows( $stage );
		}
		foreach ( array_keys( $plan['zips'] ) as $uid ) {
			$keep['zips'][ $uid ] = ZTER_DB::zips_of( $uid );
		}
		return $keep;
	}

	/** Write the pack's part, checked again now; record what was written for revert. */
	public static function apply( array $files, $slug = '' ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return array();
		}
		$by   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$msgs = array();
		$did  = array( 'zones' => null, 'rules' => array(), 'zips' => array() );
		if ( null !== $plan['zones'] ) {
			$r = ZTER_Zones::save( $plan['zones'], $by );
			if ( is_wp_error( $r ) ) {
				$msgs[] = __( 'Territories: zones not applied — ', 'zorderz' ) . $r->get_error_message();
			} else {
				$did['zones'] = (int) $r['version'];
				/* translators: %d: zones */
				$msgs[] = sprintf( __( 'Territories: %d zone(s) applied.', 'zorderz' ), count( $r['rows'] ) );
			}
		}
		foreach ( $plan['rules'] as $stage => $rows ) {
			if ( ! ZTER_DB::lock( 'zter_rules' ) ) {
				$msgs[] = sprintf( __( 'Territories: %s rules not applied — someone is saving the rules right now.', 'zorderz' ), $stage );
				continue;
			}
			try {
				$r = ZTER_Rules::save( $rows, $by, $stage );
			} finally {
				ZTER_DB::unlock( 'zter_rules' );
			}
			if ( is_wp_error( $r ) ) {
				$msgs[] = sprintf( __( 'Territories: %s rules not applied — ', 'zorderz' ), $stage ) . $r->get_error_message();
			} else {
				$did['rules'][ $stage ] = (int) $r['version'];
				/* translators: 1: rules, 2: stage */
				$msgs[] = sprintf( __( 'Territories: %1$d %2$s rule(s) applied, every one in shadow.', 'zorderz' ), count( $r['rows'] ), $stage );
			}
		}
		foreach ( $plan['zips'] as $uid => $zips ) {
			ZTER_DB::set_zips( $uid, $zips, $by, 'pack' );
			$did['zips'][ $uid ] = ZTER_DB::zips_of( $uid );
		}
		if ( $plan['zips'] ) {
			/* translators: %d: people */
			$msgs[] = sprintf( __( 'Territories: ZIP codes applied for %d people.', 'zorderz' ), count( $plan['zips'] ) );
		}
		foreach ( $plan['refused'] as $r ) {
			$msgs[] = sprintf( __( 'Territories: %1$s refused — %2$s', 'zorderz' ), $r['path'], $r['why'] );
		}
		update_option( self::APPLIED_OPTION, $did, false );
		return $msgs;
	}

	/**
	 * Put back what the pack replaced, where nothing changed since. Zones and rules come back as new versions;
	 * rules come back in shadow (the ones that were live before are named).
	 *
	 * @return string[] what was put back and what was not
	 */
	public static function restore( $kept ) {
		$did = get_option( self::APPLIED_OPTION, null );
		delete_option( self::APPLIED_OPTION );
		if ( ! is_array( $kept ) || ! is_array( $did ) ) {
			return array( __( 'nothing to put back.', 'zorderz' ) );
		}
		$by   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$msgs = array();

		if ( is_array( $kept['zones'] ?? null ) && null !== $did['zones'] ) {
			$cur = ZTER_Zones::all();
			if ( (int) $cur['version'] !== (int) $did['zones'] ) {
				$msgs[] = __( 'the zones changed since the pack, so they were left as they are.', 'zorderz' );
			} else {
				$new = array( 'version' => $cur['version'] + 1, 'rows' => array_values( (array) $kept['zones']['rows'] ), 'updated_by' => $by, 'updated_at' => ZTER_DB::now() );
				update_option( ZTER_Zones::OPTION, $new, false );
				ZTER_DB::log( $by, 0, 'zones', array( 'version' => $new['version'], 'changes' => array(), 'source' => 'pack' ) );
				ZTER_DB::bump();
				$msgs[] = __( 'the zones are back as they were.', 'zorderz' );
			}
		}

		foreach ( (array) ( $kept['rules'] ?? array() ) as $stage => $rows ) {
			if ( ! isset( $did['rules'][ $stage ] ) ) {
				continue;
			}
			$cur = ZTER_Rules::get( $stage );
			if ( (int) $cur['version'] !== (int) $did['rules'][ $stage ] ) {
				/* translators: %s: stage */
				$msgs[] = sprintf( __( 'the %s rules changed since the pack, so they were left as they are; an earlier version can be put back from History.', 'zorderz' ), $stage );
				continue;
			}
			$were_live = array();
			$back      = array();
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r['live'] ) ) {
					$were_live[] = (string) $r['id'];
				}
				$r['live'] = false;   // a revert never turns a rule live
				$back[]    = $r;
			}
			$new = array( 'version' => $cur['version'] + 1, 'rows' => $back, 'updated_by' => $by, 'updated_at' => ZTER_DB::now() );
			update_option( self::rules_option( $stage ), $new, false );
			ZTER_DB::log( $by, 0, 'rules', array( 'stage' => $stage, 'version' => $new['version'], 'from' => $cur['rows'], 'to' => $back ) );
			ZTER_DB::bump();
			/* translators: %s: stage */
			$msg = sprintf( __( 'the %s rules are back as they were, in shadow.', 'zorderz' ), $stage );
			if ( $were_live ) {
				/* translators: %s: rule ids */
				$msg .= ' ' . sprintf( __( 'Live before the pack: %s. Turn them live again from the go-live checklist.', 'zorderz' ), implode( ', ', $were_live ) );
			}
			$msgs[] = $msg;
		}

		$back = 0;
		foreach ( (array) ( $kept['zips'] ?? array() ) as $uid => $zips ) {
			if ( ! array_key_exists( $uid, (array) $did['zips'] ) ) {
				continue;
			}
			if ( ZTER_DB::zips_of( (int) $uid ) !== $did['zips'][ $uid ] ) {
				/* translators: %s: initials */
				$msgs[] = sprintf( __( '%s\'s ZIP codes changed since the pack, so they were left as they are.', 'zorderz' ), zter_initials( (int) $uid ) );
				continue;
			}
			ZTER_DB::set_zips( (int) $uid, (array) $zips, $by, 'pack' );
			$back++;
		}
		if ( $back ) {
			/* translators: %d: people */
			$msgs[] = sprintf( __( 'ZIP codes put back for %d people.', 'zorderz' ), $back );
		}
		return $msgs;
	}
}
