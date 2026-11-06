<?php
/**
 * ZRO_REST — {ZDZ_REST_NS}/team-roles/*. Who may see and change what is decided here and only here:
 *   - everyone who holds the app sees their OWN row and its history;
 *   - a crew lead also sees their crew's rows (ZDZ_Hierarchy::can_oversee), read only;
 *   - the admin tier sees everyone, changes any cell, copies one person's answers onto another,
 *     and edits the list of abilities;
 *   - the shared device never holds the app, so every route refuses it.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_REST {

	const BASE = '/team-roles';

	/** The platform's REST namespace. */
	public static function ns() {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$use   = array( __CLASS__, 'can_use' );
		$admin = array( __CLASS__, 'can_admin' );
		$r     = function ( $path, $method, $cb, $perm ) {
			register_rest_route( self::ns(), self::BASE . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/grid', 'GET', 'grid', $use );
		$r( '/history', 'GET', 'history', $use );
		$r( '/cell', 'POST', 'cell', $admin );
		$r( '/copy', 'POST', 'copy', $admin );
		$r( '/ability', 'POST', 'ability', $admin );
		$r( '/ability/retire', 'POST', 'ability_retire', $admin );
		$r( '/ability/move', 'POST', 'ability_move', $admin );
		// The one-time move of older marks into items. Preview writes nothing.
		$r( '/migrate/preview', 'POST', 'migrate_preview', $admin );
		$r( '/migrate', 'POST', 'migrate_apply', $admin );
	}

	public static function can_use() {
		return zro_user_can_use( get_current_user_id() );
	}

	public static function can_admin() {
		$me = get_current_user_id();
		return zro_user_can_use( $me ) && zro_user_is_admin( $me );
	}

	private static function name_of( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? (string) $u->display_name : ( (int) $uid > 0 ? __( 'A former user', 'zorderz' ) : __( 'Setup', 'zorderz' ) );
	}

	/** A team member by id, or WP_Error. */
	private static function member( $raw ) {
		$uid = is_scalar( $raw ) ? (int) $raw : 0;
		if ( $uid <= 0 || ! zro_is_team_member( $uid ) ) {
			return new WP_Error( 'no_person', __( 'That person is not on the team.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return $uid;
	}

	/* ── the grid ─────────────────────────────────────────────────────────────────────────────── */

	/**
	 * The abilities (retired ones too, for the admin's list), the people this viewer may see with
	 * their answers, and what the viewer may do. Notes and "since" dates travel only with rows the
	 * viewer may see.
	 */
	public static function grid( WP_REST_Request $req ) {
		$me    = get_current_user_id();
		$admin = zro_user_is_admin( $me );
		if ( $admin ) {
			ZRO_Migrate::merge_unmarked(); // a kind of work nobody marked never needs the move
		}
		$people = zro_visible_team( $me );
		$ans    = ZRO_DB::answers_for( array_map( function ( $p ) { return $p['id']; }, $people ) );
		$names  = array();
		$rows   = array();
		foreach ( $people as $p ) {
			$cells = array();
			foreach ( $ans[ $p['id'] ] ?? array() as $k => $a ) {
				if ( ! isset( $names[ $a['updated_by'] ] ) ) {
					$names[ $a['updated_by'] ] = self::name_of( $a['updated_by'] );
				}
				$cells[ $k ] = array( 'level' => $a['level'], 'note' => $a['note'], 'since' => $a['since'], 'by' => $names[ $a['updated_by'] ], 'at' => substr( $a['updated_at'], 0, 10 ) );
			}
			$rows[] = array( 'id' => $p['id'], 'name' => $p['name'], 'initials' => $p['initials'], 'color' => $p['color'], 'ink' => $p['ink'],
				'me' => (int) $p['id'] === (int) $me, 'cells' => (object) $cells );
		}
		// The viewer's own row first, then by name; both keys spelled out so the order is stable.
		usort( $rows, function ( $a, $b ) { return array( (int) ! $a['me'], strtolower( $a['name'] ), $a['id'] ) <=> array( (int) ! $b['me'], strtolower( $b['name'] ), $b['id'] ); } );
		$types = array();
		foreach ( ZRO_Abilities::project_types() as $slug => $label ) {
			$types[] = array( 'slug' => $slug, 'label' => $label );
		}
		$groups = array();
		foreach ( ZRO_Abilities::groups() as $k => $label ) {
			$groups[] = array( 'key' => $k, 'label' => $label );
		}
		$roles = array();
		foreach ( ZRO_Abilities::role_labels() as $k => $label ) {
			$roles[] = array( 'key' => $k, 'label' => $label );
		}
		$moved = ZRO_Migrate::done();
		return array(
			'abilities' => ZRO_Abilities::export( $admin ),
			'groups'    => $groups,
			'types'     => $types,
			'roles'     => $roles,
			'jobRoles'  => ZRO_Abilities::job_roles(),
			'catalog'   => class_exists( 'ZDZ_Item_Engine' ),
			'people'    => $rows,
			'canEdit'   => $admin,
			'scope'     => $admin ? 'everyone' : ( count( $rows ) > 1 ? 'crew' : 'self' ),
			'migration' => $admin ? array( 'needed' => ZRO_Migrate::needed(), 'done' => $moved ? array( 'at' => substr( (string) $moved['at'], 0, 10 ), 'by' => self::name_of( $moved['by'] ), 'cells' => (int) $moved['cells'] ) : null ) : null,
		);
	}

	/** Set one cell (admin tier). */
	public static function cell( WP_REST_Request $req ) {
		$uid = self::member( $req->get_param( 'user' ) );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$key  = (string) $req->get_param( 'ability' );
		$cell = ZRO_Abilities::parse_cell( $key );
		$a    = $cell ? $cell[0] : ZRO_Abilities::get( $key );
		if ( ! $a || ! empty( $a['retired'] ) ) {
			return new WP_Error( 'no_ability', __( 'That ability is not in use. Reload Team Roles.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( 'item' === $a['group'] && ! $cell ) {
			/* translators: %s: item */
			return new WP_Error( 'no_ability', sprintf( __( 'Choose Sell, Install or Repair for %s.', 'zorderz' ), $a['label'] ), array( 'status' => 400 ) );
		}
		if ( ZRO_Abilities::merged_into( $a ) ) {
			// Its answer comes from the items now; a mark here would be hidden and contradict them.
			/* translators: %s: kind of work */
			return new WP_Error( 'merged', sprintf( __( '"%s" moved into items. Change Sell, Install or Repair there.', 'zorderz' ), $a['label'] ), array( 'status' => 409 ) );
		}
		$store = $cell ? ZRO_Abilities::cell_key( $a['key'], $cell[1] ) : $a['key'];
		$since = (string) $req->get_param( 'since' );
		if ( '' !== trim( $since ) && null === ZRO_DB::clean_date( $since ) ) {
			return new WP_Error( 'bad_date', __( 'The "since" date is not a real date.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( '' !== trim( $since ) && $since > current_time( 'Y-m-d' ) ) {
			return new WP_Error( 'bad_date', __( 'The "since" date is in the future.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$level = (string) $req->get_param( 'level' );
		if ( 'unset' === $level ) {
			// Back to not marked (the Undo of a first mark): the row goes; the history keeps from -> ''.
			$r = ZRO_DB::clear( $uid, $store, get_current_user_id() );
			return is_wp_error( $r ) ? $r : array( 'changed' => $r, 'cell' => null, 'answer' => ZRO_Roles::person_can( $uid, $store ) );
		}
		$note = $req->get_param( 'note' );
		$r    = ZRO_DB::set( $uid, $store, $level, null === $note ? '' : (string) $note, $since, get_current_user_id(), 'grid' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$ans = $r['answer'];
		return array(
			'changed' => $r['changed'],
			'cell'    => $ans ? array( 'level' => $ans['level'], 'note' => $ans['note'], 'since' => $ans['since'], 'by' => self::name_of( $ans['updated_by'] ), 'at' => substr( $ans['updated_at'], 0, 10 ) ) : null,
			'answer'  => ZRO_Roles::person_can( $uid, $store ),
		);
	}

	/**
	 * Copy one person's answers onto another (admin tier): to start a new person from someone who
	 * does the same work. For every ability in use, the target's level becomes the source's (not
	 * marked counts as Can't). Notes and dates are the source's own and are not copied. Each
	 * changed cell is a history row.
	 */
	public static function copy( WP_REST_Request $req ) {
		$from = self::member( $req->get_param( 'from' ) );
		$to   = self::member( $req->get_param( 'to' ) );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( $from === $to ) {
			return new WP_Error( 'same_person', __( 'Choose two different people.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$me      = get_current_user_id();
		$all     = ZRO_DB::answers_for( array( $from, $to ) );
		$src     = $all[ $from ] ?? array();
		$dst     = $all[ $to ] ?? array();
		$detail  = 'Copied from ' . self::name_of( $from );
		$changed = 0;
		$keys    = ZRO_Abilities::cell_keys();
		foreach ( ZRO_Abilities::all() as $k => $a ) {
			if ( 'item' !== $a['group'] && ! ZRO_Abilities::merged_into( $a ) ) {
				$keys[] = $k;
			}
		}
		foreach ( $keys as $k ) {
			$want = isset( $src[ $k ] ) ? $src[ $k ]['level'] : 'cannot';
			$have = isset( $dst[ $k ] ) ? $dst[ $k ]['level'] : 'cannot';
			if ( $want === $have ) {
				continue;
			}
			$r = ZRO_DB::set( $to, $k, $want, '', '', $me, 'copy', $detail );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$changed += $r['changed'] ? 1 : 0;
		}
		return array(
			'changed' => $changed,
			'message' => 0 === $changed
				/* translators: 1: person, 2: person */
				? sprintf( __( '%1$s already matched %2$s.', 'zorderz' ), self::name_of( $to ), self::name_of( $from ) )
				/* translators: 1: number of answers, 2: person */
				: sprintf( _n( '%1$d answer changed for %2$s.', '%1$d answers changed for %2$s.', $changed, 'zorderz' ), $changed, self::name_of( $to ) ),
		);
	}

	/* ── the list of abilities (admin tier) ───────────────────────────────────────────────────── */

	/** Add (no key) or change (key) an ability. */
	public static function ability( WP_REST_Request $req ) {
		$me    = get_current_user_id();
		$key   = (string) $req->get_param( 'key' );
		$roles = $req->get_param( 'roles' );
		$roles = null === $roles ? null : ( is_array( $roles ) ? array_map( 'strval', array_filter( $roles, 'is_scalar' ) ) : ( is_scalar( $roles ) ? preg_split( '/\s*,\s*/', (string) $roles, -1, PREG_SPLIT_NO_EMPTY ) : array() ) );
		$addon = $req->get_param( 'addon' );
		$jr    = $req->get_param( 'job_role' );
		$opts  = array( 'roles' => $roles, 'addon' => null === $addon ? null : rest_sanitize_boolean( $addon ), 'job_role' => is_scalar( $jr ) ? (string) $jr : null );
		if ( null !== $roles && ! array_intersect( $roles, array_keys( ZRO_Abilities::ROLES ) ) ) {
			return new WP_Error( 'no_roles', __( 'Tick at least one of Sell, Install and Repair.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( '' === $key ) {
			$before = ZRO_Abilities::all( true );
			$r      = ZRO_Abilities::add( (string) $req->get_param( 'label' ), (string) $req->get_param( 'group' ), (string) $req->get_param( 'type' ), $me, $opts );
			/* translators: %s: ability */
			$msg = is_wp_error( $r ) ? '' : ( isset( $before[ $r['key'] ] ) ? sprintf( __( 'Brought back "%s" with its answers and history.', 'zorderz' ), $r['label'] ) : sprintf( __( 'Added "%s".', 'zorderz' ), $r['label'] ) );
		} else {
			$r   = ZRO_Abilities::edit( $key, $req->get_param( 'label' ), $req->get_param( 'group' ), $req->get_param( 'type' ), $me, $opts );
			$msg = __( 'Saved.', 'zorderz' );
		}
		return is_wp_error( $r ) ? $r : array( 'ability' => $r, 'abilities' => ZRO_Abilities::export( true ), 'message' => $msg );
	}

	public static function ability_retire( WP_REST_Request $req ) {
		$r = ZRO_Abilities::retire( (string) $req->get_param( 'key' ), (bool) (int) $req->get_param( 'retired' ), get_current_user_id() );
		return is_wp_error( $r ) ? $r : array( 'ability' => $r, 'abilities' => ZRO_Abilities::export( true ) );
	}

	public static function ability_move( WP_REST_Request $req ) {
		$r = ZRO_Abilities::move( (string) $req->get_param( 'key' ), (int) $req->get_param( 'dir' ), get_current_user_id() );
		return is_wp_error( $r ) ? $r : array( 'ability' => $r, 'abilities' => ZRO_Abilities::export( true ) );
	}

	/* ── history ──────────────────────────────────────────────────────────────────────────────── */

	/**
	 * History, newest first. user=<id>: that person's (yourself, your crew as a lead, anyone as
	 * admin). No user: the admin tier gets everything (with the changes to the list of abilities);
	 * anyone else gets the rows they may see.
	 */
	public static function history( WP_REST_Request $req ) {
		$me    = get_current_user_id();
		$admin = zro_user_is_admin( $me );
		$raw   = $req->get_param( 'user' );
		if ( null !== $raw && '' !== $raw ) {
			$uid = (int) $raw;
			if ( $uid <= 0 || ! get_userdata( $uid ) ) {
				return new WP_Error( 'no_person', __( 'That person was not found.', 'zorderz' ), array( 'status' => 404 ) );
			}
			if ( ! zro_can_view( $me, $uid ) ) {
				return new WP_Error( 'forbidden', __( 'You can only see your own row and your crew\'s.', 'zorderz' ), array( 'status' => 403 ) );
			}
			$uids = array( $uid );
		} else {
			$uids = $admin ? null : array_map( function ( $p ) { return $p['id']; }, zro_visible_team( $me ) );
		}
		$ability = (string) $req->get_param( 'ability' );
		$ability = preg_match( '/^[a-z0-9_\-]{1,128}$/', $ability ) ? $ability : '';
		$limit   = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
		$rows    = ZRO_DB::history( $uids, $ability, $limit + 1, (int) $req->get_param( 'before' ) );
		$more    = count( $rows ) > $limit;
		$rows    = array_slice( $rows, 0, $limit );
		$labels  = array();
		foreach ( ZRO_Abilities::all( true ) as $k => $a ) {
			$labels[ $k ] = $a['label'];
			if ( 'item' === $a['group'] ) {
				foreach ( array_keys( ZRO_Abilities::ROLES ) as $r ) { // every role, so a row stays readable after a role is dropped
					$labels[ ZRO_Abilities::cell_key( $k, $r ) ] = ZRO_Abilities::cell_label( $a, $r );
				}
			}
		}
		$names = array();
		$out   = array();
		foreach ( $rows as $r ) {
			foreach ( array( (int) $r['changed_by'], (int) $r['user_id'] ) as $id ) {
				if ( ! isset( $names[ $id ] ) ) {
					$names[ $id ] = self::name_of( $id );
				}
			}
			$out[] = array(
				'id'        => (int) $r['id'],
				'at'        => (string) $r['changed_at'],
				'by'        => $names[ (int) $r['changed_by'] ],
				'person'    => (int) $r['user_id'] > 0 ? $names[ (int) $r['user_id'] ] : '',
				'ability'   => $labels[ $r['ability'] ] ?? $r['ability'],
				'from'      => (string) $r['from_level'],
				'to'        => (string) $r['to_level'],
				'fromNote'  => (string) $r['from_note'],
				'toNote'    => (string) $r['to_note'],
				'fromSince' => ! empty( $r['from_since'] ) ? (string) $r['from_since'] : '',
				'toSince'   => ! empty( $r['to_since'] ) ? (string) $r['to_since'] : '',
				'source'    => (string) $r['source'],
				'detail'    => (string) $r['detail'],
			);
		}
		return array( 'rows' => $out, 'more' => $more );
	}

	/* ── the one-time move ────────────────────────────────────────────────────────────────────── */

	/** people: [ {id, sells, installs} ]; map: [ {key, targets: [ {item, work} ]} ]. Anything else is ignored. */
	private static function migrate_args( WP_REST_Request $req ) {
		$people = $req->get_param( 'people' );
		$map    = $req->get_param( 'map' );
		return array( is_array( $people ) ? array_values( array_filter( $people, 'is_array' ) ) : array(), is_array( $map ) ? array_values( array_filter( $map, 'is_array' ) ) : array() );
	}

	public static function migrate_preview( WP_REST_Request $req ) {
		list( $people, $map ) = self::migrate_args( $req );
		return ZRO_Migrate::preview( $people, $map );
	}

	public static function migrate_apply( WP_REST_Request $req ) {
		list( $people, $map ) = self::migrate_args( $req );
		$r = ZRO_Migrate::apply( $people, $map, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$n = (int) $r['cells'];
		/* translators: %d: number of marks */
		return array( 'record' => $r, 'message' => sprintf( _n( 'Moved %d mark into items.', 'Moved %d marks into items.', $n, 'zorderz' ), $n ) );
	}
}
