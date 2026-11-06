<?php
/**
 * ZRO_Pack — Team Roles' part of an Identity Pack: people's marks in parties.yml.
 *
 *   people:
 *     - initials: AB                 # how the pack names a person: their initials on this site
 *       name: "Alex"                 # for the reader of the file; not applied
 *       team_roles:
 *         items:                     # catalog items, by id: sell / install / repair
 *           example-item: { sell: can, install: learning }
 *         abilities:                 # the business's own abilities (site conditions, tasks …), by key
 *           example-task: can
 *
 * A level is can, learning or cannot. Each mark is checked as the grid checks it: the person must be on the team
 * (never the shared device) and named by initials exactly one of them holds; the item must be in the catalog and
 * in use, with that role; an ability must exist, be in use and not have moved into items. A mark that fails is
 * skipped and listed with the reason. A mark keeps the cell's note and "since" date. Marks the pack does not name
 * are left as they are.
 *
 * Revert puts each cell the pack changed back to what it was (or back to not marked), unless someone changed that
 * cell since; History keeps every step.
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_Pack {

	/** What the most recent apply wrote, per person and cell, so revert can tell whether a cell changed since. */
	const APPLIED_OPTION = 'zro_pack_applied';

	const PERSON_KEYS = array( 'initials', 'name', 'label', 'team_roles' );

	public static function register( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		$consumers['team-roles'] = array(
			'label'    => __( 'Team Roles', 'zorderz' ),
			'files'    => array( 'parties' ),
			'paths'    => array( 'parties' => array( 'people' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	/**
	 * The marks the pack's people carry, checked: { marks: [ uid => [ cell key => level ] ], names: [ uid => initials ],
	 * refused: [ {path, why} ], unconsumed: [ path ] }. Null when parties.yml lists no people.
	 */
	public static function plan( array $files ) {
		$people = $files['parties']['people'] ?? null;
		if ( ! is_array( $people ) || ! $people ) {
			return null;
		}
		$out  = array( 'marks' => array(), 'names' => array(), 'refused' => array(), 'unconsumed' => array() );
		$team = array_map( function ( $p ) { return (int) $p['id']; }, zro_team() );
		foreach ( array_values( $people ) as $i => $e ) {
			$ini  = is_array( $e ) && class_exists( 'ZDZ_Identity_Pack' ) ? ZDZ_Identity_Pack::person_ref( $e['initials'] ?? '' ) : '';
			$path = 'people.' . ( '' !== $ini ? $ini : ( $i + 1 ) );
			if ( ! is_array( $e ) ) {
				$out['refused'][] = array( 'path' => $path, 'why' => __( 'A person is a set of named values.', 'zorderz' ) );
				continue;
			}
			foreach ( array_diff( array_map( 'strval', array_keys( $e ) ), self::PERSON_KEYS ) as $k ) {
				$out['unconsumed'][] = "$path.$k";
			}
			$tr = $e['team_roles'] ?? null;
			if ( null === $tr || '' === $tr || array() === $tr ) {
				continue;   // a roster entry with nothing for Team Roles
			}
			$p = class_exists( 'ZDZ_Identity_Pack' ) ? ZDZ_Identity_Pack::person_id( $e['initials'] ?? '', $team ) : array( 'id' => 0, 'why' => '' );
			if ( ! $p['id'] ) {
				$out['refused'][] = array( 'path' => $path, 'why' => $p['why'] . ' ' . __( 'Their marks are left out.', 'zorderz' ) );
				continue;
			}
			$uid                  = $p['id'];
			$out['names'][ $uid ] = $ini;
			if ( ! is_array( $tr ) ) {
				$out['refused'][] = array( 'path' => "$path.team_roles", 'why' => __( 'Write items and abilities under team_roles.', 'zorderz' ) );
				continue;
			}
			foreach ( array_diff( array_map( 'strval', array_keys( $tr ) ), array( 'items', 'abilities' ) ) as $k ) {
				$out['unconsumed'][] = "$path.team_roles.$k";
			}
			foreach ( is_array( $tr['items'] ?? null ) ? $tr['items'] : array() as $item => $roles ) {
				$ip = "$path.team_roles.items.$item";
				$a  = ZRO_Abilities::get( (string) $item );
				if ( ! $a || 'item' !== $a['group'] ) {
					$out['refused'][] = array( 'path' => $ip, 'why' => __( 'Not an item in the catalog.', 'zorderz' ) );
					continue;
				}
				if ( ! empty( $a['retired'] ) ) {
					$out['refused'][] = array( 'path' => $ip, 'why' => __( 'That item is not in use.', 'zorderz' ) );
					continue;
				}
				if ( ! is_array( $roles ) ) {
					$out['refused'][] = array( 'path' => $ip, 'why' => __( 'Write sell, install or repair with a level for each.', 'zorderz' ) );
					continue;
				}
				foreach ( $roles as $role => $level ) {
					if ( ! in_array( (string) $role, ZRO_Abilities::roles_of( $a ), true ) ) {
						/* translators: %s: roles */
						$out['refused'][] = array( 'path' => "$ip.$role", 'why' => sprintf( __( 'This item has no such role here (it has: %s).', 'zorderz' ), implode( ', ', ZRO_Abilities::roles_of( $a ) ) ) );
						continue;
					}
					$lv = self::level( $level );
					if ( '' === $lv ) {
						$out['refused'][] = array( 'path' => "$ip.$role", 'why' => __( 'A level is can, learning or cannot.', 'zorderz' ) );
						continue;
					}
					$out['marks'][ $uid ][ ZRO_Abilities::cell_key( $a['key'], (string) $role ) ] = $lv;
				}
			}
			foreach ( is_array( $tr['abilities'] ?? null ) ? $tr['abilities'] : array() as $key => $level ) {
				$ap = "$path.team_roles.abilities.$key";
				$a  = ZRO_Abilities::get( (string) $key );
				if ( ! $a ) {
					$out['refused'][] = array( 'path' => $ap, 'why' => __( 'Team Roles has no such ability.', 'zorderz' ) );
					continue;
				}
				if ( 'item' === $a['group'] ) {
					$out['refused'][] = array( 'path' => $ap, 'why' => __( 'An item is marked under items, with sell, install or repair.', 'zorderz' ) );
					continue;
				}
				if ( ! empty( $a['retired'] ) ) {
					$out['refused'][] = array( 'path' => $ap, 'why' => __( 'That ability is not in use.', 'zorderz' ) );
					continue;
				}
				if ( ZRO_Abilities::merged_into( $a ) ) {
					$out['refused'][] = array( 'path' => $ap, 'why' => __( 'That kind of work moved into items; mark the items instead.', 'zorderz' ) );
					continue;
				}
				$lv = self::level( $level );
				if ( '' === $lv ) {
					$out['refused'][] = array( 'path' => $ap, 'why' => __( 'A level is can, learning or cannot.', 'zorderz' ) );
					continue;
				}
				$out['marks'][ $uid ][ $a['key'] ] = $lv;
			}
		}
		return $out;
	}

	/** A cell as revert compares it: level, note and date, or null when not marked. */
	private static function cell_state( $answer ) {
		return is_array( $answer ) ? array( (string) $answer['level'], (string) $answer['note'], (string) $answer['since'] ) : null;
	}

	/** can | learning | cannot, or ''. A YAML "no" reads as false: that is not a level. */
	private static function level( $v ) {
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
		return in_array( $v, ZRO_DB::LEVELS, true ) ? $v : '';
	}

	public static function preview( array $files ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return array();
		}
		$rows = array();
		foreach ( $plan['marks'] as $uid => $cells ) {
			foreach ( $cells as $key => $level ) {
				$now = ZRO_DB::answer( $uid, $key );
				if ( ( $now['level'] ?? '' ) !== $level && ! ( ! $now && 'cannot' === $level ) ) {
					$rows[] = array( 'file' => 'parties', 'path' => 'people.' . $plan['names'][ $uid ] . '.' . $key, 'from' => $now ? $now['level'] : __( '(not marked)', 'zorderz' ), 'to' => $level );
				}
			}
		}
		foreach ( $plan['refused'] as $r ) {
			$rows[] = array( 'file' => 'parties', 'kind' => 'refused', 'path' => $r['path'], 'why' => $r['why'] );
		}
		foreach ( array_unique( $plan['unconsumed'] ) as $p ) {
			$rows[] = array( 'file' => 'parties', 'kind' => 'unconsumed', 'path' => $p );
		}
		return $rows;
	}

	/** Before anything is written: each cell the pack names, as it is now (null: not marked). */
	public static function snapshot( array $files = array(), $slug = '' ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return null;
		}
		$keep = array();
		foreach ( $plan['marks'] as $uid => $cells ) {
			foreach ( array_keys( $cells ) as $key ) {
				$keep[] = array( 'user' => (int) $uid, 'key' => (string) $key, 'before' => ZRO_DB::answer( $uid, $key ) );
			}
		}
		return array( 'cells' => $keep );
	}

	public static function apply( array $files, $slug = '' ) {
		$plan = self::plan( $files );
		if ( null === $plan ) {
			return array();
		}
		$by     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$did    = array();
		$set    = 0;
		$msgs   = array();
		$detail = substr( 'Identity Pack ' . (string) $slug, 0, 255 );
		foreach ( $plan['marks'] as $uid => $cells ) {
			foreach ( $cells as $key => $level ) {
				$old = ZRO_DB::answer( $uid, $key );
				$r   = ZRO_DB::set( $uid, $key, $level, $old ? $old['note'] : '', $old ? $old['since'] : '', $by, 'pack', $detail );
				if ( is_wp_error( $r ) ) {
					/* translators: 1: cell, 2: reason */
					$msgs[] = sprintf( __( 'Team Roles: %1$s not set — %2$s', 'zorderz' ), 'people.' . $plan['names'][ $uid ] . '.' . $key, $r->get_error_message() );
					continue;
				}
				$set                += $r['changed'] ? 1 : 0;
				$did[ $uid ][ $key ] = self::cell_state( $r['answer'] );
			}
		}
		update_option( self::APPLIED_OPTION, $did, false );
		/* translators: 1: marks, 2: people */
		$msgs[] = sprintf( __( 'Team Roles: %1$d mark(s) set for %2$d people.', 'zorderz' ), $set, count( $plan['marks'] ) );
		foreach ( $plan['refused'] as $r ) {
			/* translators: 1: path, 2: reason */
			$msgs[] = sprintf( __( 'Team Roles: %1$s refused — %2$s', 'zorderz' ), $r['path'], $r['why'] );
		}
		return $msgs;
	}

	/** Each cell the pack changed goes back, unless it changed since. */
	public static function restore( $kept ) {
		$did = get_option( self::APPLIED_OPTION, null );
		delete_option( self::APPLIED_OPTION );
		if ( ! is_array( $kept ) || ! is_array( $did ) ) {
			return array( __( 'nothing to put back.', 'zorderz' ) );
		}
		$by    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$back  = 0;
		$moved = array();
		foreach ( (array) ( $kept['cells'] ?? array() ) as $c ) {
			$uid = (int) ( $c['user'] ?? 0 );
			$key = (string) ( $c['key'] ?? '' );
			if ( ! isset( $did[ $uid ] ) || ! array_key_exists( $key, $did[ $uid ] ) ) {
				continue;
			}
			$now = ZRO_DB::answer( $uid, $key );
			if ( self::cell_state( $now ) !== $did[ $uid ][ $key ] ) {
				$moved[] = $key;
				continue;
			}
			$before = is_array( $c['before'] ?? null ) ? $c['before'] : null;
			if ( null === $before ) {
				$r = $now ? ZRO_DB::clear( $uid, $key, $by ) : false;
			} else {
				$r = ZRO_DB::set( $uid, $key, $before['level'], $before['note'], $before['since'], $by, 'pack', 'Identity Pack revert' );
			}
			if ( ! is_wp_error( $r ) && $r ) {
				$back++;
			}
		}
		/* translators: %d: marks */
		$msgs = array( sprintf( __( '%d mark(s) put back.', 'zorderz' ), $back ) );
		if ( $moved ) {
			/* translators: %s: cells */
			$msgs[] = sprintf( __( 'changed since the pack, so left as they are: %s.', 'zorderz' ), implode( ', ', array_unique( $moved ) ) );
		}
		return $msgs;
	}
}
