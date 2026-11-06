<?php
/**
 * ZRO_Abilities — the list of abilities: what a person might be able to do.
 *
 * Each ability has a KEY (never changes once made: other apps and the history refer to it), a
 * LABEL, a GROUP and, for a kind of work, the kind of project it belongs to.
 *
 *   item  Items and add-ons   the business's own catalog (ZDZ_Item_Engine): every active item,
 *                             each with up to three ROLES — Sell, Install (new work) and Repair
 *                             (work on an existing unit). A person's answer is kept per item and
 *                             role under the CELL KEY "<item-id>__<role>", in the same answers
 *                             table. Team Roles never creates an item: the catalog owns names and
 *                             order. Which roles an item has, whether it is an add-on, and whether
 *                             Team Roles tracks it at all are kept here (zro_item_roles), with the
 *                             item's own attributes (`work_roles`, `addon`, `project_type`) as the
 *                             starting point.
 *   work  Kinds of work       added by the business, each optionally tied to a kind of project
 *                             (ZJOB_Project_Types). Once older marks have been moved into items
 *                             (ZRO_Migrate) a kind of work is MERGED: it leaves the grid, keeps its
 *                             key, answers and history, and is answered from the items it covered.
 *   site  Site conditions     added by the business (for example working at height, or in a confined space).
 *   task  Tasks               added by the business; a task may name the Jobs step it matches
 *                             (`job_role`, one of ZJOB_Project_People::ROLES).
 *
 * Core ships no abilities and no items. An ability is retired, never deleted: the history names it.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_Abilities {

	const OPTION      = 'zro_abilities';
	const ITEM_OPTION = 'zro_item_roles';

	/** What a person may do with an item. Every item has some of these, in this order. */
	const ROLES = array(
		'sell'    => 'Sell',
		'install' => 'Install',
		'repair'  => 'Repair',
	);

	/** How a merged kind of work covered an item: new work, repairs, or both. */
	const WORKS = array( 'new', 'repair', 'any' );

	/** Between an item's id and a role in a cell key. Never made by key_from_label (it collapses runs of _). */
	const SEP = '__';

	const MAX_LABEL = 60;
	const MAX_KEY   = 40;
	const MAX_COUNT = 120;

	/** The Jobs steps a task may stand for when the Jobs app is absent. */
	const JOB_ROLES_FALLBACK = array( 'seller', 'installer', 'orderer', 'preparer' );

	private static $memo = null;

	public static function flush() {
		self::$memo = null;
	}

	/** The groups, in grid order, with their labels. */
	public static function groups() {
		return array(
			'item' => __( 'Items and add-ons', 'zorderz' ),
			'work' => __( 'Kinds of work', 'zorderz' ),
			'site' => __( 'Site conditions', 'zorderz' ),
			'task' => __( 'Tasks', 'zorderz' ),
		);
	}

	/** The role labels people read. */
	public static function role_labels() {
		return array(
			'sell'    => __( 'Sell', 'zorderz' ),
			'install' => __( 'Install', 'zorderz' ),
			'repair'  => __( 'Repair', 'zorderz' ),
		);
	}

	/** The Jobs steps a task may name (the Jobs app's list when it is installed). */
	public static function job_roles() {
		if ( class_exists( 'ZJOB_Project_People' ) && defined( 'ZJOB_Project_People::ROLES' ) ) {
			return array_values( array_diff( (array) ZJOB_Project_People::ROLES, array( 'installer2' ) ) );
		}
		return self::JOB_ROLES_FALLBACK;
	}

	/** The kinds of project the Jobs app is configured with (slug => label). Empty when there are none. */
	public static function project_types() {
		if ( class_exists( 'ZJOB_Project_Types' ) && method_exists( 'ZJOB_Project_Types', 'types' ) ) {
			return (array) ZJOB_Project_Types::types();
		}
		return array();
	}

	/* ── Items, from the catalog ──────────────────────────────────────────────────────────────── */

	/** @var callable|null Where items are read from instead of the Item Engine (unit tests). */
	private static $catalog_source = null;

	/** Read items from $source (a callable returning id => Item Engine row) instead of the Item Engine; null to undo. */
	public static function use_catalog( $source ) {
		self::$catalog_source = is_callable( $source ) ? $source : null;
		self::flush();
	}

	/** The catalog's items, active and inactive: id => Item Engine row. Empty without the Item Engine. */
	public static function catalog() {
		if ( null !== self::$catalog_source ) {
			$rows = call_user_func( self::$catalog_source );
			return is_array( $rows ) ? $rows : array();
		}
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ! method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			return array();
		}
		$on  = (array) ZDZ_Item_Engine::all( array( 'active' => true ) );
		$off = (array) ZDZ_Item_Engine::all( array( 'active' => false ) );
		return $on + $off;
	}

	/** Team Roles' own settings per item: id => [ roles[], addon (bool|null), hidden (bool) ]. */
	public static function item_settings() {
		$s   = get_option( self::ITEM_OPTION, array() );
		$out = array();
		foreach ( is_array( $s ) ? $s : array() as $id => $r ) {
			if ( is_array( $r ) && is_string( $id ) && '' !== $id ) {
				$out[ $id ] = $r;
			}
		}
		// An item row carried in the abilities list from an earlier install sets the same things.
		foreach ( self::stored_rows() as $r ) {
			if ( 'item' === ( $r['group'] ?? '' ) && ! empty( $r['key'] ) && ! isset( $out[ $r['key'] ] ) ) {
				$out[ (string) $r['key'] ] = array( 'roles' => (array) ( $r['roles'] ?? array() ), 'addon' => ! empty( $r['addon'] ), 'hidden' => ! empty( $r['retired'] ) );
			}
		}
		return $out;
	}

	private static function save_item_settings( array $s ) {
		update_option( self::ITEM_OPTION, $s, false );
		self::flush();
	}

	/** One catalog item as a grid row. */
	private static function item_row( array $it, array $set ) {
		$attrs = is_array( $it['attributes'] ?? null ) ? $it['attributes'] : array();
		$roles = isset( $set['roles'] ) ? (array) $set['roles'] : ( isset( $attrs['work_roles'] ) ? (array) $attrs['work_roles'] : array() );
		$roles = array_values( array_intersect( array_keys( self::ROLES ), array_map( 'strval', $roles ) ) );
		$addon = array_key_exists( 'addon', $set ) && null !== $set['addon'] ? (bool) $set['addon']
			: ( ! empty( $attrs['addon'] ) || 'component' === ( $it['sellable'] ?? '' ) || '' !== (string) ( $it['parent_item_id'] ?? '' ) );
		$type  = sanitize_key( (string) ( $attrs['project_type'] ?? '' ) );
		$types = self::project_types();
		if ( $types && ! isset( $types[ $type ] ) ) {
			$type = '';
		}
		return array(
			'key'      => (string) $it['id'],
			'label'    => self::clean_label( (string) ( '' !== (string) ( $it['display_name'] ?? '' ) ? $it['display_name'] : $it['id'] ) ),
			'group'    => 'item',
			'type'     => $type,
			'job_role' => '',
			'order'    => (int) ( $it['sort_order'] ?? 0 ),
			'retired'  => empty( $it['active'] ) || ! empty( $set['hidden'] ),
			'added_by' => 0,
			'added_at' => '',
			'roles'    => $roles ? $roles : array_keys( self::ROLES ),
			'addon'    => $addon,
			'aliases'  => array_values( array_filter( array_map( function ( $al ) { return is_array( $al ) ? (string) ( $al['value'] ?? '' ) : ( is_scalar( $al ) ? (string) $al : '' ); }, (array) ( $it['aliases'] ?? array() ) ) ) ),
			'catalog'  => true,
		);
	}

	/** Items (in use, or with retired too), in catalog order. key => row. */
	public static function items( $with_retired = false ) {
		return array_filter( self::all( $with_retired ), function ( $a ) { return 'item' === $a['group']; } );
	}

	/** The roles an item has, in the fixed order. Only for items; anything else has none. */
	public static function roles_of( $a ) {
		if ( ! is_array( $a ) || 'item' !== ( $a['group'] ?? '' ) ) {
			return array();
		}
		$have = (array) ( $a['roles'] ?? array() );
		return array_values( array_filter( array_keys( self::ROLES ), function ( $r ) use ( $have ) { return in_array( $r, $have, true ); } ) );
	}

	public static function cell_key( $item, $role ) {
		return (string) $item . self::SEP . (string) $role;
	}

	/**
	 * A cell key, read back: array( item row, role ) when it names an item (retired too) and a role
	 * that item has; else null. A key with no separator is never a cell.
	 */
	public static function parse_cell( $key ) {
		$key = (string) $key;
		$at  = strrpos( $key, self::SEP );
		if ( false === $at || $at < 1 ) {
			return null;
		}
		$item = substr( $key, 0, $at );
		$role = substr( $key, $at + strlen( self::SEP ) );
		$a    = self::get( $item );
		if ( ! $a || 'item' !== $a['group'] || ! isset( self::ROLES[ $role ] ) || ! in_array( $role, self::roles_of( $a ), true ) ) {
			return null;
		}
		return array( $a, $role );
	}

	/** The label people read for a cell: "<item> · Sell". */
	public static function cell_label( $a, $role ) {
		$l = self::role_labels();
		return $a['label'] . ' · ' . ( $l[ $role ] ?? $role );
	}

	/** Every cell key in use: items in use × their roles, in grid order. */
	public static function cell_keys() {
		$out = array();
		foreach ( self::items() as $k => $a ) {
			foreach ( self::roles_of( $a ) as $r ) {
				$out[] = self::cell_key( $k, $r );
			}
		}
		return $out;
	}

	/* ── Plain abilities, stored here ─────────────────────────────────────────────────────────── */

	private static function stored_rows() {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
	}

	/** A kind of work that the move has merged into items: its targets, or array(). */
	public static function merged_into( $a ) {
		if ( ! is_array( $a ) || 'work' !== ( $a['group'] ?? '' ) ) {
			return array();
		}
		return (array) ( $a['merged'] ?? array() );
	}

	/** Mark a kind of work merged into these targets (ZRO_Migrate). The key, its answers and history are kept. */
	public static function set_merged( $key, array $targets ) {
		$all = self::plain_all();
		if ( ! isset( $all[ $key ] ) || 'work' !== $all[ $key ]['group'] ) {
			return false;
		}
		$all[ $key ]['merged'] = $targets;
		$all[ $key ]           = self::clean_row( $all[ $key ] );
		self::save( $all );
		return true;
	}

	/** One stored plain row, cleaned. Never an item: items come from the catalog. */
	public static function clean_row( array $r ) {
		$groups = self::groups();
		$group  = isset( $groups[ $r['group'] ?? '' ] ) && 'item' !== ( $r['group'] ?? '' ) ? (string) $r['group'] : 'task';
		$type   = sanitize_key( (string) ( $r['type'] ?? '' ) );
		$types  = self::project_types();
		if ( 'work' !== $group || ( $types && ! isset( $types[ $type ] ) ) ) {
			$type = ''; // only a kind of work names a kind of project, and only one the Jobs app knows
		}
		$role = (string) ( $r['job_role'] ?? '' );
		$row  = array(
			'key'      => (string) $r['key'],
			'label'    => self::clean_label( (string) ( $r['label'] ?? '' ) ),
			'group'    => $group,
			'type'     => $type,
			'job_role' => 'task' === $group && in_array( $role, self::job_roles(), true ) ? $role : '',
			'order'    => (int) ( $r['order'] ?? 0 ),
			'retired'  => ! empty( $r['retired'] ),
			'added_by' => (int) ( $r['added_by'] ?? 0 ),
			'added_at' => (string) ( $r['added_at'] ?? '' ),
		);
		if ( 'work' === $group && ! empty( $r['merged'] ) && is_array( $r['merged'] ) ) {
			$m = array();
			foreach ( $r['merged'] as $t ) {
				if ( is_array( $t ) && is_string( $t['item'] ?? null ) && '' !== $t['item'] && in_array( $t['work'] ?? '', self::WORKS, true ) ) {
					$m[] = array( 'item' => $t['item'], 'work' => $t['work'] );
				}
			}
			if ( $m ) {
				$row['merged'] = $m;
			}
		}
		return $row;
	}

	/** Plain text, like a note: "Height <3m" stays whole. */
	public static function clean_label( $s ) {
		return ZRO_DB::plain( $s, self::MAX_LABEL );
	}

	/** The stored plain abilities, cleaned: key => row (retired too). */
	private static function plain_all() {
		$list = array();
		foreach ( self::stored_rows() as $r ) {
			if ( ! empty( $r['key'] ) && is_string( $r['key'] ) && 'item' !== ( $r['group'] ?? '' ) ) {
				$c                 = self::clean_row( $r );
				$list[ $c['key'] ] = $c;
			}
		}
		return $list;
	}

	/** Every ability in display order (group, then order). $with_retired: include retired ones. key => row. */
	public static function all( $with_retired = false ) {
		if ( null === self::$memo ) {
			$list = array();
			$set  = self::item_settings();
			foreach ( self::catalog() as $id => $it ) {
				if ( is_array( $it ) && '' !== (string) ( $it['id'] ?? '' ) ) {
					$list[ (string) $it['id'] ] = self::item_row( $it, $set[ (string) $it['id'] ] ?? array() );
				}
			}
			foreach ( self::plain_all() as $k => $r ) {
				if ( ! isset( $list[ $k ] ) ) { // a catalog item with the same key wins: one key, one meaning
					$list[ $k ] = $r;
				}
			}
			$gorder = array_flip( array_keys( self::groups() ) );
			uasort( $list, function ( $a, $b ) use ( $gorder ) {
				return array( $gorder[ $a['group'] ], $a['order'], $a['label'] ) <=> array( $gorder[ $b['group'] ], $b['order'], $b['label'] );
			} );
			self::$memo = $list;
		}
		if ( $with_retired ) {
			return self::$memo;
		}
		return array_filter( self::$memo, function ( $a ) { return empty( $a['retired'] ); } );
	}

	/** One ability (retired ones too), or null. */
	public static function get( $key ) {
		$all = self::all( true );
		return $all[ (string) $key ] ?? null;
	}

	/** The kinds of work under one kind of project (keys). */
	public static function kinds_for_type( $type ) {
		$out = array();
		foreach ( self::all() as $k => $a ) {
			if ( 'work' === $a['group'] && '' !== $a['type'] && $a['type'] === (string) $type ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** A new key from a label: lower-case words joined by _, unique among all (retired included). */
	public static function key_from_label( $label ) {
		$base = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $label ) : (string) $label );
		$base = str_replace( '&', ' and ', $base );
		$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $base ), '_' );
		$base = rtrim( substr( $base, 0, self::MAX_KEY - 3 ), '_' );
		if ( '' === $base || ! preg_match( '/^[a-z]/', $base ) ) {
			$base = 'ability_' . $base;
		}
		$all = self::all( true );
		$key = $base;
		$n   = 2;
		while ( isset( $all[ $key ] ) ) {
			$key = $base . '_' . $n++;
		}
		return $key;
	}

	private static function save( array $plain ) {
		// Item rows carried from an earlier install stay where they are (they set item settings).
		$keep = array_values( array_filter( self::stored_rows(), function ( $r ) { return 'item' === ( $r['group'] ?? '' ); } ) );
		update_option( self::OPTION, array_merge( array_values( $plain ), $keep ), false );
		self::flush();
	}

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/**
	 * Add an ability. Returns the new row, or WP_Error. A label already used by an ability in use is
	 * refused (two columns with one name could not be told apart); a retired one with that label is
	 * brought back instead. Items are added in the catalog, not here.
	 */
	public static function add( $label, $group, $type, $by, $opts = array() ) {
		$label = self::clean_label( $label );
		if ( 'item' === $group ) {
			return self::err( 'item_in_catalog', __( 'Items come from the catalog. Add the item there and it appears here with Sell, Install and Repair.', 'zorderz' ) );
		}
		if ( '' === $label ) {
			return self::err( 'no_label', __( 'Give the ability a name.', 'zorderz' ) );
		}
		$groups = self::groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return self::err( 'bad_group', __( 'Choose a group: kinds of work, site conditions or tasks.', 'zorderz' ) );
		}
		$all = self::all( true );
		foreach ( $all as $a ) {
			if ( 0 === strcasecmp( $a['label'], $label ) ) {
				if ( $a['retired'] && 'item' !== $a['group'] && empty( $a['merged'] ) ) {
					$back = self::retire( $a['key'], false, $by ); // same key, every old answer
					return is_wp_error( $back ) ? $back : self::edit( $a['key'], $label, $group, $type, $by, $opts );
				}
				/* translators: %s: ability name */
				return self::err( 'exists', sprintf( __( 'There is already an ability called "%s".', 'zorderz' ), $a['label'] ), 409 );
			}
		}
		// Abilities in use count toward the cap; retired ones don't, so retiring frees a place.
		if ( count( array_filter( self::plain_all(), function ( $a ) { return empty( $a['retired'] ); } ) ) >= self::MAX_COUNT ) {
			return self::err( 'too_many', __( 'That is as many abilities as the grid can hold. Retire one you no longer use.', 'zorderz' ) );
		}
		$plain = self::plain_all();
		$max   = 0;
		foreach ( $plain as $a ) {
			if ( $a['group'] === $group ) {
				$max = max( $max, (int) $a['order'] );
			}
		}
		$row = self::clean_row( array( 'key' => self::key_from_label( $label ), 'label' => $label, 'group' => $group, 'type' => $type, 'order' => $max + 10,
			'job_role' => (string) ( $opts['job_role'] ?? '' ), 'added_by' => (int) $by, 'added_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) );
		$plain[ $row['key'] ] = $row;
		self::save( $plain );
		ZRO_DB::log( array( 'user_id' => 0, 'ability' => $row['key'], 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => 'Added "' . $row['label'] . '" (' . $groups[ $group ] . ')' ) );
		return $row;
	}

	/**
	 * Change an ability. A plain ability: rename, regroup or re-type it (its key stays). An item:
	 * which roles it has and whether it is an add-on (its name, order and kind of project are the
	 * catalog's). Returns the row or WP_Error.
	 */
	public static function edit( $key, $label, $group, $type, $by, $opts = array() ) {
		$cur = self::get( $key );
		if ( ! $cur ) {
			return self::err( 'no_ability', __( 'That ability was not found.', 'zorderz' ), 404 );
		}
		if ( 'item' === $cur['group'] ) {
			return self::edit_item( $cur, $by, $opts );
		}
		if ( 'item' === $group ) {
			return self::err( 'bad_group', __( 'An ability can\'t become an item. Items come from the catalog.', 'zorderz' ) );
		}
		$plain  = self::plain_all();
		$old    = $plain[ $key ];
		$label  = null === $label ? $old['label'] : self::clean_label( $label );
		$group  = null === $group ? $old['group'] : (string) $group;
		$type   = null === $type ? $old['type'] : (string) $type;
		$groups = self::groups();
		if ( '' === $label ) {
			return self::err( 'no_label', __( 'Give the ability a name.', 'zorderz' ) );
		}
		if ( ! isset( $groups[ $group ] ) ) {
			return self::err( 'bad_group', __( 'Choose a group: kinds of work, site conditions or tasks.', 'zorderz' ) );
		}
		if ( ! empty( $old['merged'] ) && $group !== $old['group'] ) {
			return self::err( 'bad_group', __( 'This kind of work has moved into items; it stays where it is.', 'zorderz' ) );
		}
		foreach ( self::all( true ) as $k => $a ) {
			if ( $k !== $key && empty( $a['retired'] ) && 0 === strcasecmp( $a['label'], $label ) ) {
				/* translators: %s: ability name */
				return self::err( 'exists', sprintf( __( 'There is already an ability called "%s".', 'zorderz' ), $a['label'] ), 409 );
			}
		}
		$new = self::clean_row( array_merge( $old, array( 'label' => $label, 'group' => $group, 'type' => $type ), array_key_exists( 'job_role', $opts ) && null !== $opts['job_role'] ? array( 'job_role' => (string) $opts['job_role'] ) : array() ) );
		if ( $group !== $old['group'] ) {
			$max = 0;
			foreach ( $plain as $a ) {
				if ( $a['group'] === $group ) {
					$max = max( $max, (int) $a['order'] );
				}
			}
			$new['order'] = $max + 10;
		}
		$bits = array();
		if ( $new['label'] !== $old['label'] ) {
			$bits[] = 'renamed from "' . $old['label'] . '" to "' . $new['label'] . '"';
		}
		if ( $new['group'] !== $old['group'] ) {
			$bits[] = 'moved from ' . $groups[ $old['group'] ] . ' to ' . $groups[ $new['group'] ];
		}
		if ( $new['type'] !== $old['type'] ) {
			$types  = self::project_types();
			$bits[] = 'kind of project ' . ( '' === $new['type'] ? 'cleared' : 'set to ' . ( $types[ $new['type'] ] ?? $new['type'] ) );
		}
		if ( $new['job_role'] !== $old['job_role'] ) {
			$bits[] = 'Jobs step ' . ( '' === $new['job_role'] ? 'cleared' : 'set to ' . $new['job_role'] );
		}
		if ( ! $bits ) {
			return $new;
		}
		$plain[ $key ] = $new;
		self::save( $plain );
		ZRO_DB::log( array( 'user_id' => 0, 'ability' => $key, 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => ucfirst( implode( '; ', $bits ) ) ) );
		return $new;
	}

	/** An item's roles and add-on flag. Answers stay stored when a role is dropped, and come back with it. */
	private static function edit_item( array $cur, $by, array $opts ) {
		$set  = self::item_settings();
		$mine = $set[ $cur['key'] ] ?? array();
		$bits = array();
		if ( array_key_exists( 'roles', $opts ) && null !== $opts['roles'] ) {
			$roles = array_values( array_intersect( array_keys( self::ROLES ), array_map( 'strval', (array) $opts['roles'] ) ) );
			if ( ! $roles ) {
				return self::err( 'no_roles', __( 'Tick at least one of Sell, Install and Repair.', 'zorderz' ) );
			}
			$labels = self::role_labels();
			$won    = array_diff( $roles, self::roles_of( $cur ) );
			$lost   = array_diff( self::roles_of( $cur ), $roles );
			if ( $won ) {
				$bits[] = 'now ' . strtolower( implode( ', ', array_map( function ( $r ) use ( $labels ) { return $labels[ $r ]; }, $won ) ) );
			}
			if ( $lost ) {
				$bits[] = 'no longer ' . strtolower( implode( ', ', array_map( function ( $r ) use ( $labels ) { return $labels[ $r ]; }, $lost ) ) );
			}
			$mine['roles'] = $roles;
		}
		if ( array_key_exists( 'addon', $opts ) && null !== $opts['addon'] && (bool) $opts['addon'] !== (bool) $cur['addon'] ) {
			$mine['addon'] = (bool) $opts['addon'];
			$bits[]        = $mine['addon'] ? 'now an add-on' : 'no longer an add-on';
		}
		if ( ! $bits ) {
			return $cur;
		}
		$set[ $cur['key'] ] = $mine;
		self::save_item_settings( $set );
		ZRO_DB::log( array( 'user_id' => 0, 'ability' => $cur['key'], 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => ucfirst( implode( '; ', $bits ) ) ) );
		return self::get( $cur['key'] );
	}

	/** Retire (hide from the grid; every answer and the history are kept) or bring back. */
	public static function retire( $key, $retired, $by ) {
		$cur = self::get( $key );
		if ( ! $cur ) {
			return self::err( 'no_ability', __( 'That ability was not found.', 'zorderz' ), 404 );
		}
		$retired = (bool) $retired;
		if ( 'item' === $cur['group'] ) {
			$set                       = self::item_settings();
			$set[ $key ]['hidden']     = $retired;
			self::save_item_settings( $set );
			ZRO_DB::log( array( 'user_id' => 0, 'ability' => $key, 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => ( $retired ? 'Stopped tracking "' : 'Tracking again "' ) . $cur['label'] . '"' ) );
			return self::get( $key );
		}
		if ( $cur['retired'] === $retired ) {
			return $cur;
		}
		if ( ! $retired ) {
			foreach ( self::all( true ) as $k => $a ) {
				if ( $k !== $key && empty( $a['retired'] ) && 0 === strcasecmp( $a['label'], $cur['label'] ) ) {
					/* translators: %s: ability name */
					return self::err( 'exists', sprintf( __( 'An ability in use is already called "%s". Rename one first.', 'zorderz' ), $a['label'] ), 409 );
				}
			}
		}
		$plain                    = self::plain_all();
		$plain[ $key ]['retired'] = $retired;
		self::save( $plain );
		ZRO_DB::log( array( 'user_id' => 0, 'ability' => $key, 'changed_by' => (int) $by, 'source' => 'ability', 'detail' => ( $retired ? 'Retired "' : 'Brought back "' ) . $cur['label'] . '"' ) );
		return self::get( $key );
	}

	/** Move a plain ability one place up or down within its group. Items keep the catalog's order. */
	public static function move( $key, $dir, $by ) {
		$plain = self::plain_all();
		if ( ! isset( $plain[ $key ] ) ) {
			return self::get( $key ) ? self::err( 'item_order', __( 'Items keep the catalog\'s order.', 'zorderz' ) ) : self::err( 'no_ability', __( 'That ability was not found.', 'zorderz' ), 404 );
		}
		$group = $plain[ $key ]['group'];
		$keys  = array();
		foreach ( self::all( true ) as $k => $a ) {
			if ( $a['group'] === $group && isset( $plain[ $k ] ) ) {
				$keys[] = $k;
			}
		}
		$i = array_search( $key, $keys, true );
		$j = $i + ( $dir < 0 ? -1 : 1 );
		if ( $j < 0 || $j >= count( $keys ) ) {
			return $plain[ $key ];
		}
		$tmp        = $keys[ $i ];
		$keys[ $i ] = $keys[ $j ];
		$keys[ $j ] = $tmp;
		foreach ( $keys as $n => $k ) {
			$plain[ $k ]['order'] = ( $n + 1 ) * 10;
		}
		self::save( $plain );
		return self::get( $key );
	}

	/** For the page and for other apps: plain rows, in order. */
	public static function export( $with_retired = false ) {
		$types  = self::project_types();
		$groups = self::groups();
		$labels = self::role_labels();
		$out    = array();
		foreach ( self::all( $with_retired ) as $a ) {
			$row = array(
				'key'         => $a['key'],
				'label'       => $a['label'],
				'group'       => $a['group'],
				'group_label' => $groups[ $a['group'] ],
				'type'        => $a['type'],
				'type_label'  => '' !== $a['type'] ? (string) ( $types[ $a['type'] ] ?? $a['type'] ) : '',
				'job_role'    => $a['job_role'],
				'retired'     => (bool) $a['retired'],
			);
			if ( 'item' === $a['group'] ) {
				$row['addon'] = (bool) $a['addon'];
				$row['roles'] = array_map( function ( $r ) use ( $a, $labels ) { return array( 'role' => $r, 'label' => $labels[ $r ], 'key' => self::cell_key( $a['key'], $r ) ); }, self::roles_of( $a ) );
			}
			if ( self::merged_into( $a ) ) {
				// Still asked by its key (answered from the items), but no longer a column of its own.
				$row['merged'] = array_map( function ( $t ) {
					$i = self::get( $t['item'] );
					return array( 'item' => $t['item'], 'item_label' => $i ? $i['label'] : $t['item'], 'work' => $t['work'] );
				}, self::merged_into( $a ) );
			}
			$out[] = $row;
		}
		return $out;
	}
}
