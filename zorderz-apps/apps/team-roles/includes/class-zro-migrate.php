<?php
/**
 * ZRO_Migrate — the one-time move of older kind-of-work marks into items (Sell, Install, Repair).
 *
 * A kind-of-work mark says "this person works with X" without saying whether they sell it or
 * install it. The move reads that from each person's own TASK marks — the task that stands for the
 * Jobs step `seller`, and the one for `installer` — and, where a task is not marked, from the
 * person's role (zro_move_role_defaults: by default the owner, admin and sales roles sell; the tech
 * and shop roles install; anyone else does neither). The admin sees and can change both, per
 * person, BEFORE anything is written:
 *
 *   preview( people, map )  what would be written, nothing stored
 *   apply( people, map )    writes it, once
 *
 * For each person and each older mark (Can, Learning, or Can't with a note), onto each item the
 * kind of work covered:
 *   Sell     when the person sells                                    (every item has Sell)
 *   Install  when the person installs and the kind covered new work
 *   Repair   when the person installs and the kind covered repairs
 * The level written is the lower of the mark and the person's Sell / Install answer (Learning to
 * install + Can for X = Learning to install X). Two older marks on one cell: the higher wins. A
 * cell already marked on the new grid is never overwritten. Every write is a history row.
 *
 * Where each kind of work goes starts from the business's own map (the `zro_move_defaults`
 * option, kind key => [ [item id, new|repair|any], … ]), else from the catalog: the item whose
 * name or aliases the kind's label names (ZDZ_Item_Engine::match), with "repair" / "install" in
 * the label deciding the work. The admin can change any of it in the preview.
 *
 * Then each moved kind of work is MERGED (ZRO_Abilities::set_merged): it leaves the grid, keeps its
 * key, its answers and its history, and answers from the items (ZRO_Roles::derived). A kind the
 * admin maps nowhere stays exactly as it is. Nothing is deleted.
 *
 * A kind of work no one has marked is merged at once by merge_unmarked(): there is nothing to move,
 * and an empty column beside the item it duplicates only confuses.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_Migrate {

	const OPTION          = 'zro_migration';
	const LOCK            = 'zro_migration_lock';
	const DEFAULTS_OPTION = 'zro_move_defaults';
	const RANK            = array( 'cannot' => 0, 'learning' => 1, 'can' => 2 );

	/** Which platform roles sell and which install, when a person's own task is not marked. */
	public static function role_defaults() {
		$d = array(
			'sells'    => array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' ),
			'installs' => array( 'zdz_tech', 'zdz_mfg' ),
		);
		$f = apply_filters( 'zro_move_role_defaults', $d );
		if ( is_array( $f ) ) {
			foreach ( array( 'sells', 'installs' ) as $k ) {
				if ( isset( $f[ $k ] ) && is_array( $f[ $k ] ) ) {
					$d[ $k ] = array_values( array_filter( array_map( 'strval', $f[ $k ] ), function ( $r ) { return 'zdz_general' !== $r; } ) );
				}
			}
		}
		return $d;
	}

	/** The record of the move once it has run, or null. */
	public static function done() {
		$r = get_option( self::OPTION, null );
		return is_array( $r ) ? $r : null;
	}

	/** Are the items the answer to word questions yet: the move ran, or there was nothing to move. */
	public static function items_live() {
		return null !== self::done() || ! self::needed();
	}

	/** Merge every unmerged kind of work that NO ONE has marked into its default items. */
	public static function merge_unmarked() {
		$out = array();
		foreach ( self::pending() as $k => $a ) {
			$t = self::default_targets( $a );
			if ( $t && 0 === ZRO_DB::count_for( array( $k ) ) && ZRO_Abilities::set_merged( $k, $t ) ) {
				$out[] = $k;
			}
		}
		if ( $out ) {
			ZRO_DB::log( array( 'user_id' => 0, 'ability' => '', 'changed_by' => 0, 'source' => 'ability', 'detail' => sprintf( '%d kinds of work no one had marked moved into items: %s', count( $out ), implode( ', ', $out ) ) ) );
		}
		return $out;
	}

	/** The kinds of work still to move: in use, not merged. key => row. */
	public static function pending() {
		return array_filter( ZRO_Abilities::all(), function ( $a ) {
			return 'work' === $a['group'] && ! ZRO_Abilities::merged_into( $a );
		} );
	}

	/** Is there anything to move: an unmerged kind of work with at least one mark, items to move into, and the move has not run. */
	public static function needed() {
		if ( self::done() ) {
			return false;
		}
		$keys = array_keys( self::pending() );
		if ( ! $keys || ! ZRO_Abilities::items() ) {
			return false;
		}
		return ZRO_DB::count_for( $keys ) > 0;
	}

	/** Where a kind of work goes by default: [ [item, work], … ] among items in use; [] when nothing fits. */
	public static function default_targets( $a ) {
		$items = ZRO_Abilities::items();
		$map   = get_option( self::DEFAULTS_OPTION, array() );
		$map   = apply_filters( 'zro_move_defaults', is_array( $map ) ? $map : array() );
		$out   = array();
		if ( is_array( $map ) && isset( $map[ $a['key'] ] ) && is_array( $map[ $a['key'] ] ) ) {
			foreach ( $map[ $a['key'] ] as $t ) {
				$item = is_array( $t ) ? (string) ( $t['item'] ?? ( $t[0] ?? '' ) ) : '';
				$work = is_array( $t ) ? (string) ( $t['work'] ?? ( $t[1] ?? 'any' ) ) : 'any';
				if ( isset( $items[ $item ] ) && in_array( $work, ZRO_Abilities::WORKS, true ) ) {
					$out[] = array( 'item' => $item, 'work' => $work );
				}
			}
			return $out;
		}
		$words = preg_split( '/[^a-z]+/', strtolower( (string) $a['label'] ), -1, PREG_SPLIT_NO_EMPTY );
		$work  = array_intersect( $words, array( 'repair', 'repairs', 'service' ) ) ? 'repair'
			: ( array_intersect( $words, array( 'install', 'installs', 'new' ) ) ? 'new' : 'any' );
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'match' ) ) {
			$hit = ZDZ_Item_Engine::match( (string) $a['label'] );
			if ( is_array( $hit ) && isset( $items[ (string) ( $hit['id'] ?? '' ) ] ) ) {
				return array( array( 'item' => (string) $hit['id'], 'work' => $work ) );
			}
		}
		return array();
	}

	/** The task that stands for a Jobs step ('seller' or 'installer'), or null. */
	private static function task_for( $job_role ) {
		foreach ( ZRO_Abilities::all() as $a ) {
			if ( 'task' === $a['group'] && $job_role === $a['job_role'] ) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Does this person sell / install, to start with: their own task mark when there is one, else
	 * their role. Returns array( 'sells' => level, 'sells_why' => …, 'installs' => level, 'installs_why' => … ).
	 */
	public static function person_defaults( $uid ) {
		$u     = get_userdata( (int) $uid );
		$roles = $u ? (array) $u->roles : array();
		$rd    = self::role_defaults();
		$words = array( 'can' => __( 'Can', 'zorderz' ), 'learning' => __( 'Learning', 'zorderz' ), 'cannot' => __( 'Can\'t', 'zorderz' ) );
		$out   = array();
		foreach ( array( 'sells' => 'seller', 'installs' => 'installer' ) as $k => $step ) {
			$task = self::task_for( $step );
			$row  = $task ? ZRO_DB::answer( (int) $uid, $task['key'] ) : null;
			if ( $row ) {
				$out[ $k ] = $row['level'];
				/* translators: 1: level, 2: task */
				$out[ $k . '_why' ] = sprintf( __( 'marked %1$s for %2$s', 'zorderz' ), $words[ $row['level'] ], $task['label'] );
			} else {
				$has                = (bool) array_intersect( $roles, $rd[ $k ] );
				$out[ $k ]          = $has ? 'can' : 'cannot';
				$out[ $k . '_why' ] = $has ? __( 'not marked; from their role', 'zorderz' ) : __( 'not marked; their role does neither', 'zorderz' );
			}
		}
		return $out;
	}

	/** People choices cleaned: uid => [sells, installs] levels. Anyone not named keeps their default. */
	private static function people_plan( array $people ) {
		$plan = array();
		foreach ( zro_team() as $p ) {
			$d                = self::person_defaults( $p['id'] );
			$plan[ $p['id'] ] = array( 'sells' => $d['sells'], 'installs' => $d['installs'], 'd' => $d );
		}
		foreach ( $people as $row ) {
			$uid = is_scalar( $row['id'] ?? 0 ) ? (int) ( $row['id'] ?? 0 ) : 0;
			if ( ! isset( $plan[ $uid ] ) ) {
				continue; // someone not on the team is never planned
			}
			foreach ( array( 'sells', 'installs' ) as $k ) {
				if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && isset( self::RANK[ (string) $row[ $k ] ] ) ) {
					$plan[ $uid ][ $k ] = (string) $row[ $k ];
				}
			}
		}
		return $plan;
	}

	/** Map choices cleaned: work key => [ [item, work], … ] ([] = leave it as it is). Unnamed kinds keep their default. */
	private static function map_plan( array $map ) {
		$items = ZRO_Abilities::items();
		$plan  = array();
		foreach ( self::pending() as $k => $a ) {
			$plan[ $k ] = self::default_targets( $a );
		}
		foreach ( $map as $row ) {
			$k = is_scalar( $row['key'] ?? '' ) ? (string) ( $row['key'] ?? '' ) : '';
			if ( ! isset( $plan[ $k ] ) || ! isset( $row['targets'] ) || ! is_array( $row['targets'] ) ) {
				continue;
			}
			$t = array();
			foreach ( $row['targets'] as $x ) {
				if ( ! is_array( $x ) || ! is_scalar( $x['item'] ?? '' ) || ! is_scalar( $x['work'] ?? '' ) ) {
					continue;
				}
				$item = (string) ( $x['item'] ?? '' );
				$work = (string) ( $x['work'] ?? '' );
				if ( isset( $items[ $item ] ) && in_array( $work, ZRO_Abilities::WORKS, true ) ) {
					$t[ $item ] = array( 'item' => $item, 'work' => $work );
				}
			}
			$plan[ $k ] = array_values( $t );
		}
		return $plan;
	}

	private static function lower( $a, $b ) {
		return self::RANK[ $a ] <= self::RANK[ $b ] ? $a : $b;
	}

	/** The cells the move would write: uid => cell key => row. Cells already marked are left out (counted in `kept`). */
	private static function plan_cells( array $people, array $map ) {
		$abs   = ZRO_Abilities::all();
		$uids  = array_keys( $people );
		$ans   = ZRO_DB::answers_for( $uids );
		$cells = array();
		$kept  = 0;
		foreach ( $map as $k => $targets ) {
			foreach ( $uids as $uid ) {
				$old = $ans[ $uid ][ $k ] ?? null;
				if ( ! $old ) {
					continue;
				}
				foreach ( $targets as $t ) {
					$item  = $abs[ $t['item'] ] ?? null;
					$roles = ZRO_Abilities::roles_of( $item );
					$want  = array();
					if ( 'cannot' !== $people[ $uid ]['sells'] ) {
						$want['sell'] = $people[ $uid ]['sells'];
					}
					if ( 'cannot' !== $people[ $uid ]['installs'] ) {
						if ( 'repair' !== $t['work'] ) {
							$want['install'] = $people[ $uid ]['installs'];
						}
						if ( 'new' !== $t['work'] ) {
							$want['repair'] = $people[ $uid ]['installs'];
						}
					}
					foreach ( $want as $role => $cap ) {
						if ( ! in_array( $role, $roles, true ) ) {
							continue;
						}
						$ck = ZRO_Abilities::cell_key( $t['item'], $role );
						if ( isset( $ans[ $uid ][ $ck ] ) ) {
							$kept++;
							continue;
						}
						$level = self::lower( $old['level'], $cap );
						if ( 'cannot' === $level && '' === $old['note'] && '' === $old['since'] ) {
							continue; // not marked already reads Can't: nothing would be stored
						}
						$prev = $cells[ $uid ][ $ck ] ?? null;
						if ( $prev && self::RANK[ $prev['level'] ] >= self::RANK[ $level ] ) {
							continue;
						}
						$cells[ $uid ][ $ck ] = array( 'level' => $level, 'note' => $old['note'], 'since' => $old['since'], 'from' => $abs[ $k ]['label'], 'item' => $item['label'], 'role' => $role );
					}
				}
			}
		}
		return array( $cells, $kept );
	}

	/** What the move would do, for the admin to read and change before anything is written. Nothing is stored. */
	public static function preview( array $people = array(), array $map = array() ) {
		$pp = self::people_plan( $people );
		$mp = self::map_plan( $map );
		list( $cells, $kept ) = self::plan_cells( $pp, $mp );
		$items      = ZRO_Abilities::items();
		$abs        = ZRO_Abilities::all();
		$ans        = ZRO_DB::answers_for( array_keys( $pp ) );
		$out_people = array();
		foreach ( zro_team() as $p ) {
			$lines = array();
			foreach ( $cells[ $p['id'] ] ?? array() as $ck => $x ) {
				$lines[] = array( 'key' => $ck, 'item' => $x['item'], 'role' => $x['role'], 'level' => $x['level'], 'from' => $x['from'] );
			}
			$out_people[] = array(
				'id'           => $p['id'],
				'name'         => $p['name'],
				'initials'     => $p['initials'],
				'sells'        => $pp[ $p['id'] ]['sells'],
				'sells_why'    => $pp[ $p['id'] ]['d']['sells_why'],
				'installs'     => $pp[ $p['id'] ]['installs'],
				'installs_why' => $pp[ $p['id'] ]['d']['installs_why'],
				'cells'        => $lines,
			);
		}
		$out_map = array();
		foreach ( $mp as $k => $targets ) {
			$n = 0;
			foreach ( $ans as $per ) {
				$n += isset( $per[ $k ] ) ? 1 : 0;
			}
			$out_map[] = array(
				'key'      => $k,
				'label'    => $abs[ $k ]['label'],
				'marks'    => $n,
				'targets'  => array_map( function ( $t ) use ( $items ) { return $t + array( 'item_label' => $items[ $t['item'] ]['label'] ); }, $targets ),
				'defaults' => self::default_targets( $abs[ $k ] ),
			);
		}
		$n = 0;
		foreach ( $cells as $per ) {
			$n += count( $per );
		}
		return array(
			'done'   => self::done(),
			'needed' => self::needed(),
			'people' => $out_people,
			'map'    => $out_map,
			'items'  => array_values( array_map( function ( $i ) { return array( 'key' => $i['key'], 'label' => $i['label'], 'addon' => (bool) $i['addon'], 'roles' => ZRO_Abilities::roles_of( $i ) ); }, $items ) ),
			'cells'  => $n,
			'kept'   => $kept,
		);
	}

	/** Do the move, once. Returns the record, or WP_Error. */
	public static function apply( array $people, array $map, $by ) {
		if ( self::done() ) {
			return new WP_Error( 'zro_moved', __( 'The marks were already moved into items. Change any answer on the grid.', 'zorderz' ), array( 'status' => 409 ) );
		}
		if ( ! ZRO_Abilities::items() ) {
			return new WP_Error( 'zro_no_items', __( 'There are no items to move the marks into. Add items to the catalog first.', 'zorderz' ), array( 'status' => 400 ) );
		}
		// One mover at a time, claimed atomically; a claim older than 5 minutes (a request that died) is taken over.
		if ( ! ZRO_DB::claim_option( self::LOCK, 300 ) ) {
			return new WP_Error( 'zro_busy', __( 'The marks are being moved right now. Reload Team Roles in a moment.', 'zorderz' ), array( 'status' => 409 ) );
		}
		try {
			// Looked at again, past the cache, now that this request holds the lock: the other one may have finished.
			if ( is_array( ZRO_DB::fresh_option( self::OPTION ) ) ) {
				return new WP_Error( 'zro_moved', __( 'The marks were already moved into items. Change any answer on the grid.', 'zorderz' ), array( 'status' => 409 ) );
			}
			$pp = self::people_plan( $people );
			$mp = self::map_plan( $map );
			list( $cells, $kept ) = self::plan_cells( $pp, $mp );
			$written = 0;
			foreach ( $cells as $uid => $per ) {
				foreach ( $per as $ck => $x ) {
					$r = ZRO_DB::set( $uid, $ck, $x['level'], $x['note'], $x['since'], $by, 'migrate', 'From "' . $x['from'] . '"' );
					if ( is_wp_error( $r ) ) {
						return $r; // nothing is merged and the move is not recorded: pressing again writes the rest
					}
					$written += $r['changed'] ? 1 : 0;
				}
			}
			$merged = array();
			$kept_t = array();
			foreach ( $mp as $k => $targets ) {
				if ( $targets && ZRO_Abilities::set_merged( $k, $targets ) ) {
					$merged[]     = $k;
					$kept_t[ $k ] = $targets;
					$labels       = array_map( function ( $t ) { $i = ZRO_Abilities::get( $t['item'] ); return ( $i ? $i['label'] : $t['item'] ) . ' (' . $t['work'] . ')'; }, $targets );
					ZRO_DB::log( array( 'user_id' => 0, 'ability' => $k, 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => 'Moved into ' . implode( ', ', $labels ) ) );
				}
			}
			$people_rec = array();
			foreach ( $pp as $uid => $x ) {
				$people_rec[ $uid ] = array( 'sells' => $x['sells'], 'installs' => $x['installs'] );
			}
			$rec = array( 'at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ), 'by' => (int) $by, 'cells' => $written, 'kept' => $kept, 'merged' => $merged, 'targets' => $kept_t, 'people' => $people_rec );
			update_option( self::OPTION, $rec, false );
			return $rec;
		} finally {
			ZRO_DB::release_option( self::LOCK );
		}
	}
}
