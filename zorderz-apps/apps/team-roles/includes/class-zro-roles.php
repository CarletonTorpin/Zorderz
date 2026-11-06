<?php
/**
 * ZRO_Roles — the one question other apps ask, answered on the server.
 *
 *   zdz_person_can( $user_id, $key )   (or apply_filters( 'zdz_person_can', null, $user_id, $key ))
 *       -> array( 'can' => bool, 'level' => 'can'|'learning'|'cannot', 'why' => '...' )
 *       Exactly these three keys, always. `can` is true only for level 'can': someone Learning is
 *       not yet given the work on their own. Anything this app cannot vouch for answers can=false,
 *       level 'cannot', and says why: a person or ability it does not know, a retired ability, an
 *       item asked without a role, the shared device, or a cell nobody has marked (the default is
 *       Can't). The answer is read from the asked person's own row and nobody else's.
 *
 *   apply_filters( 'zdz_person_can_do', null, $user_id, $item_id, 'install' )   the same, in two parts
 *
 *   apply_filters( 'zdz_people_who_can', null, $key, array( 'learning' => bool ) )
 *       -> list of array( 'user_id', 'name', 'initials', 'level', 'since', 'note' ): the team members
 *       marked Can (and, with 'learning' => true, Learning after them), by name. An unknown or
 *       retired ability, or an item without a role, lists no one.
 *
 *   apply_filters( 'zdz_abilities', null, array( 'retired' => bool ) )
 *       -> the list of abilities (ZRO_Abilities::export).
 *
 *   apply_filters( 'zdz_team_roles_in_use', null )
 *       -> true when at least one mark exists (anyone, any ability, any level), else false. A
 *       caller that would refuse work for "nobody is marked Can" asks this first: on a site
 *       that has Team Roles but has not marked anyone, every answer above is Can't.
 *
 * A kind of work merged into items still answers by its key: Can when the person may Sell, Install
 * or Repair what it covered (what the older mark meant: "works with it"), so older callers keep
 * getting a true answer.
 *
 * These run inside the site for a caller that has already decided its viewer may see the answer.
 * Nothing here writes. The chat verb (roles.who_can) is gated by the capability registry.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_Roles {

	const RANK = array( 'cannot' => 0, 'learning' => 1, 'can' => 2 );

	public static function init() {
		add_filter( 'zdz_person_can', array( __CLASS__, 'person_can_filter' ), 10, 3 );
		add_filter( 'zdz_person_can_do', array( __CLASS__, 'person_can_do_filter' ), 10, 4 );
		add_filter( 'zdz_people_who_can', array( __CLASS__, 'who_can_filter' ), 10, 3 );
		add_filter( 'zdz_abilities', array( __CLASS__, 'abilities_filter' ), 10, 2 );
		add_filter( 'zdz_team_roles_in_use', array( __CLASS__, 'in_use_filter' ), 10, 1 );
	}

	/** True when at least one mark exists; this app is the authority, so a prior answer is replaced. */
	public static function in_use_filter( $prior = null ) {
		return (bool) ZRO_DB::has_marks();
	}

	public static function person_can_do_filter( $prior = null, $user_id = 0, $item = '', $role = '' ) {
		return self::person_can( $user_id, ZRO_Abilities::cell_key( is_string( $item ) ? trim( $item ) : '', is_string( $role ) ? trim( $role ) : '' ) );
	}

	/** This app is the authority on the answer: whatever an earlier filter returned is replaced. */
	public static function person_can_filter( $prior = null, $user_id = 0, $ability_key = '' ) {
		return self::person_can( $user_id, $ability_key );
	}

	public static function who_can_filter( $prior = null, $ability_key = '', $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		return self::who_can( $ability_key, ! empty( $args['learning'] ) );
	}

	public static function abilities_filter( $prior = null, $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		return ZRO_Abilities::export( ! empty( $args['retired'] ) );
	}

	/** True only when the person is marked Can. */
	public static function can( $user_id, $key ) {
		return ! empty( self::person_can( $user_id, $key )['can'] );
	}

	private static function no( $why ) {
		return array( 'can' => false, 'level' => 'cannot', 'why' => $why );
	}

	/** The answer, always in the three-key shape. */
	public static function person_can( $user_id, $ability_key ) {
		$uid    = is_numeric( $user_id ) ? (int) $user_id : 0;
		$key    = is_string( $ability_key ) ? trim( $ability_key ) : '';
		$cell   = '' !== $key ? ZRO_Abilities::parse_cell( $key ) : null;
		$a      = $cell ? $cell[0] : ( '' !== $key ? ZRO_Abilities::get( $key ) : null );
		$labels = ZRO_Abilities::role_labels();
		if ( ! $a && '' !== $key && false !== strpos( $key, ZRO_Abilities::SEP ) ) {
			// An item with a role it does not have, or with a word that is not a role.
			$at   = strrpos( $key, ZRO_Abilities::SEP );
			$item = ZRO_Abilities::get( substr( $key, 0, $at ) );
			$role = substr( $key, $at + strlen( ZRO_Abilities::SEP ) );
			if ( $item && 'item' === $item['group'] ) {
				return self::no( isset( ZRO_Abilities::ROLES[ $role ] )
					/* translators: 1: item, 2: role */
					? sprintf( __( '%1$s has no %2$s in Team Roles.', 'zorderz' ), $item['label'], strtolower( $labels[ $role ] ) )
					/* translators: %s: item */
					: sprintf( __( 'Ask about %s with sell, install or repair.', 'zorderz' ), $item['label'] ) );
			}
		}
		if ( ! $a ) {
			$kinds = '' !== $key ? ZRO_Abilities::kinds_for_type( $key ) : array();
			if ( $kinds ) {
				/* translators: 1: kind of project, 2: list of abilities */
				return self::no( sprintf( __( '"%1$s" is a kind of project with several abilities under it (%2$s). Ask about one of them.', 'zorderz' ), $key, implode( ', ', $kinds ) ) );
			}
			$types = '' !== $key ? ZRO_Abilities::project_types() : array();
			if ( isset( $types[ $key ] ) ) {
				/* translators: %s: kind of project */
				return self::no( sprintf( __( 'No ability in Team Roles is tied to the kind of project "%s" yet. Add one under Kinds of work with that kind of project, or ask about a specific ability.', 'zorderz' ), $types[ $key ] ) );
			}
			/* translators: %s: ability key */
			return self::no( '' === $key ? __( 'No ability was named.', 'zorderz' ) : sprintf( __( 'Team Roles has no ability called "%s".', 'zorderz' ), $key ) );
		}
		$u = $uid > 0 ? get_userdata( $uid ) : false;
		if ( ! $u ) {
			/* translators: %d: user id */
			return self::no( $uid > 0 ? sprintf( __( 'No person with id %d.', 'zorderz' ), $uid ) : __( 'No person was named.', 'zorderz' ) );
		}
		$name = (string) ( $u->display_name ?? ( 'user ' . $uid ) );
		if ( zro_is_kiosk( $uid ) ) {
			return self::no( __( 'The shared device is not a person and is never given work.', 'zorderz' ) );
		}
		if ( ! zro_is_team_member( $uid ) ) {
			/* translators: %s: person */
			return self::no( sprintf( __( '%s is not on the team in Team Roles.', 'zorderz' ), $name ) );
		}
		if ( ! empty( $a['retired'] ) ) {
			/* translators: %s: ability */
			return self::no( sprintf( __( '"%s" is retired in Team Roles.', 'zorderz' ), $a['label'] ) );
		}
		if ( 'item' === $a['group'] && ! $cell ) {
			// Selling an item and installing it are different answers; never guess which was meant.
			$roles = ZRO_Abilities::roles_of( $a );
			return self::no( sprintf(
				/* translators: 1: item, 2: person, 3: roles, 4: example key */
				__( '%1$s is an item: ask whether %2$s can %3$s it (for example "%4$s").', 'zorderz' ),
				$a['label'],
				$name,
				implode( ', ', array_map( 'strtolower', array_map( function ( $r ) use ( $labels ) { return $labels[ $r ]; }, $roles ) ) ),
				ZRO_Abilities::cell_key( $a['key'], $roles[0] ?? 'sell' )
			) );
		}
		if ( ZRO_Abilities::merged_into( $a ) ) {
			return self::derived( $uid, $name, $a );
		}
		$store = $cell ? ZRO_Abilities::cell_key( $a['key'], $cell[1] ) : $a['key'];
		$label = $cell ? ZRO_Abilities::cell_label( $a, $cell[1] ) : $a['label'];
		$row   = ZRO_DB::answer( $uid, $store );
		if ( ! $row ) {
			/* translators: 1: person, 2: ability */
			return self::no( sprintf( __( '%1$s is not marked for %2$s yet; the default is Can\'t.', 'zorderz' ), $name, $label ) );
		}
		return self::say( $name, $row, $cell ? $cell[1] : '', $a['label'] );
	}

	/** The answer for a stored row. $role '' = a plain ability ("can do X"); else "can sell X" and so on. */
	private static function say( $name, array $row, $role, $label ) {
		$since = '' !== $row['since'] ? ' since ' . $row['since'] : '';
		$note  = '' !== $row['note'] ? ' (' . $row['note'] . ')' : '';
		$verb  = '' === $role ? 'do' : $role;
		$ing   = '' === $role ? 'learning' : 'learning to ' . $role;
		switch ( $row['level'] ) {
			case 'can':
				return array( 'can' => true, 'level' => 'can', 'why' => sprintf( '%s can %s %s%s%s.', $name, $verb, $label, $since, $note ) );
			case 'learning':
				return array( 'can' => false, 'level' => 'learning', 'why' => sprintf( '%s is %s %s%s%s.', $name, $ing, $label, $since, $note ) );
			default:
				return array( 'can' => false, 'level' => 'cannot', 'why' => '' === $role
					? sprintf( '%s is marked Can\'t for %s%s.', $name, $label, $note )
					: sprintf( '%s is marked Can\'t to %s %s%s.', $name, $role, $label, $note ) );
		}
	}

	/** The cells a merged kind of work stands for: [ [item row, role], ... ] (items in use, roles they have). */
	public static function merged_cells( $a ) {
		$out = array();
		foreach ( ZRO_Abilities::merged_into( $a ) as $t ) {
			$i = ZRO_Abilities::get( $t['item'] );
			if ( ! $i || 'item' !== $i['group'] || ! empty( $i['retired'] ) ) {
				continue;
			}
			$roles = array( 'sell' );
			if ( 'repair' !== $t['work'] ) {
				$roles[] = 'install';
			}
			if ( 'new' !== $t['work'] ) {
				$roles[] = 'repair';
			}
			foreach ( array_intersect( ZRO_Abilities::roles_of( $i ), $roles ) as $r ) {
				$out[ ZRO_Abilities::cell_key( $i['key'], $r ) ] = array( $i, $r );
			}
		}
		return array_values( $out );
	}

	/**
	 * A merged kind of work, answered from the items it covers: the best of the person's Sell,
	 * Install and Repair marks there. Can > Learning > Can't.
	 */
	public static function derived( $uid, $name, $a ) {
		$best = null;
		$from = null;
		$mine = ZRO_DB::answers_for( array( $uid ) )[ $uid ] ?? array(); // one read, not one per cell
		$cells = self::merged_cells( $a );
		foreach ( $cells as $c ) {
			$row = $mine[ ZRO_Abilities::cell_key( $c[0]['key'], $c[1] ) ] ?? null;
			if ( $row && ( null === $best || self::RANK[ $row['level'] ] > self::RANK[ $best['level'] ] ) ) {
				$best = $row;
				$from = $c;
			}
		}
		$under = implode( ', ', array_unique( array_map( function ( $c ) { return $c[0]['label']; }, $cells ) ) );
		/* translators: 1: kind of work, 2: items */
		$moved = ' ' . sprintf( __( '("%1$s" is now Sell, Install and Repair under %2$s)', 'zorderz' ), $a['label'], '' !== $under ? $under : __( 'items', 'zorderz' ) );
		if ( ! $best ) {
			/* translators: 1: person, 2: kind of work */
			return self::no( sprintf( __( '%1$s is not marked to sell, install or repair what "%2$s" covered yet; the default is Can\'t', 'zorderz' ), $name, $a['label'] ) . $moved . '.' );
		}
		$ans        = self::say( $name, $best, $from[1], $from[0]['label'] );
		$ans['why'] = rtrim( $ans['why'], '.' ) . $moved . '.';
		return $ans;
	}

	/** Who can: the team members marked Can (then Learning, when asked), by name. */
	public static function who_can( $ability_key, $with_learning = false ) {
		$key  = is_string( $ability_key ) ? trim( $ability_key ) : '';
		$cell = '' !== $key ? ZRO_Abilities::parse_cell( $key ) : null;
		$a    = $cell ? $cell[0] : ( '' !== $key ? ZRO_Abilities::get( $key ) : null );
		if ( ! $a || ! empty( $a['retired'] ) || ( 'item' === $a['group'] && ! $cell ) ) {
			return array(); // an item without a role names no one: selling is not installing
		}
		$levels = $with_learning ? array( 'can', 'learning' ) : array( 'can' );
		$team   = zro_team();
		if ( ZRO_Abilities::merged_into( $a ) ) {
			$cells = array_map( function ( $c ) { return ZRO_Abilities::cell_key( $c[0]['key'], $c[1] ); }, self::merged_cells( $a ) );
			$ans   = ZRO_DB::answers_for( array_map( function ( $p ) { return $p['id']; }, $team ) ); // one read for the whole team
			$rows  = array();
			foreach ( $team as $p ) {
				$best = null;
				foreach ( $cells as $ck ) {
					$r = $ans[ $p['id'] ][ $ck ] ?? null;
					if ( $r && ( null === $best || self::RANK[ $r['level'] ] > self::RANK[ $best['level'] ] ) ) {
						$best = $r;
					}
				}
				if ( $best && in_array( $best['level'], $levels, true ) ) {
					$rows[ $p['id'] ] = $best;
				}
			}
		} else {
			$rows = ZRO_DB::by_ability( $cell ? ZRO_Abilities::cell_key( $a['key'], $cell[1] ) : $a['key'], $levels );
		}
		$out = array();
		foreach ( $team as $p ) {
			if ( isset( $rows[ $p['id'] ] ) ) {
				$r     = $rows[ $p['id'] ];
				$out[] = array( 'user_id' => $p['id'], 'name' => $p['name'], 'initials' => $p['initials'], 'level' => $r['level'], 'since' => $r['since'], 'note' => $r['note'] );
			}
		}
		usort( $out, function ( $x, $y ) {
			return array( 'can' === $x['level'] ? 0 : 1, strtolower( $x['name'] ) ) <=> array( 'can' === $y['level'] ? 0 : 1, strtolower( $y['name'] ) );
		} );
		return $out;
	}
}
