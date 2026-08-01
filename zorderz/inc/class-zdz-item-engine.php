<?php
/**
 * Zorderz — Item Engine
 *
 * The shared, admin-defined catalog of everything a business sells, and the
 * single authority for the cross-app COUNTS CONTRACT that every module speaks.
 *
 * WHAT IT REPLACES
 * Before this service the product taxonomy lived hardcoded in eight different
 * places — a different copy of "what this business sells" in every module, each
 * drifting from the others. That taxonomy was also the *wire format* between
 * modules: one app counted units into a fixed enum of one trade's product words,
 * and every other app was taught that same fixed vocabulary. A business that did
 * not sell those specific things got empty numbers.
 *
 * THE MODEL (settled — see the Item Engine design doc)
 *   - TWO TYPES ONLY. Everything is an Item, and an Item is exactly one of
 *     'product' (tangible) or 'service' (intangible). Fixed forever.
 *   - USER-NAMED SUBTYPES underneath, each 'global' (offered on future items)
 *     or 'one_off'. Subtypes are WordPress taxonomy terms — we use the platform's
 *     own taxonomy engine rather than a parallel store.
 *   - PER-ITEM PRICING SCHEMES the owner defines: flat / per_unit / per_hour /
 *     per_area / per_visit / tiered / formula(cost x markup) / quote_only. Named
 *     schemes are reusable, cloneable, and editable globally or per item.
 *   - THE COUNTS CONTRACT is catalog-driven. Count categories are Items, not a
 *     fixed enum. count_categories() / classify() / kinds() / aliases() are the
 *     single authority; there are NO hardcoded product names anywhere here.
 *
 * SHIP EMPTY
 * Activation creates schema only. No company's products, subtypes, prices or
 * SKUs are ever seeded. An empty catalog makes every resolver return neutral
 * ('' / null / []), so consumers fall back to their own neutral defaults
 * ('other' / 'service') and nothing breaks. An OPTIONAL, clearly-marked sample
 * set exists (sample_pack()) but is applied only by an explicit, confirmed admin
 * action — never automatically.
 *
 * DESCRIBE, NEVER PRESCRIBE
 * The engine mirrors how a business already prices and classifies. Discovery
 * proposes a catalog from connected data; the owner approves item by item;
 * nothing is applied without a yes. The engine never editorialises about prices.
 *
 * USAGE (static, cross-module authority)
 *   ZDZ_Item_Engine::classify( $text )            // text -> item id (a "kind"), or null
 *   ZDZ_Item_Engine::match( $text )               // text -> full item, longest-alias-wins
 *   ZDZ_Item_Engine::aliases()                    // alias => item id (for prompts / JS)
 *   ZDZ_Item_Engine::count_categories()           // item id => count meta (the vocabulary)
 *   ZDZ_Item_Engine::kinds()                       // countable item ids
 *   ZDZ_Item_Engine::resolve_price( $id, $ctx )   // a Pricing Scheme -> money
 *
 * @package Zorderz
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Item_Engine {

	/** Custom tables (id-keyed, so ids can be meaningful slugs and old ids survive as aliases). */
	const ITEMS_TABLE   = 'zdz_items';
	const SCHEMES_TABLE = 'zdz_pricing_schemes';

	/** The subtype taxonomy. We register it against an object type that need not be a real CPT. */
	const SUBTYPE_TAX  = 'zdz_item_subtype';
	const SUBTYPE_OBJ  = 'zdz_item';

	/** Options. */
	const SCHEMA_OPTION  = 'zdz_item_engine_schema';   // schema version gate
	const VERSION_OPTION = 'zdz_item_engine_version';   // content version (cache-buster)
	const SCHEMA_VERSION = 1;

	/** The two types. Fixed forever. */
	const TYPES = [ 'product', 'service' ];

	/** Sellable modes. */
	const SELLABLE = [ 'standalone', 'component', 'both' ];

	/** Subtype scopes. */
	const SCOPES = [ 'global', 'one_off' ];

	/**
	 * The pricing methods. This set is CORE — it exists whether or not a tenant
	 * uses every member. 'per_hour' ships even though the demo trade has no hourly
	 * rate, which is exactly the proof the method belongs to the platform.
	 */
	const PRICING_METHODS = [
		'flat', 'per_unit', 'per_hour', 'per_area', 'per_visit', 'tiered', 'formula', 'quote_only',
	];

	/** The counts-contract shape discriminator. Consumers branch on this, never by probing for keys. */
	const COUNTS_SHAPE = 'item_keyed_v2';

	/** Request-level caches, keyed nowhere fancier than the content version. */
	private static $cache_version = null;
	private static $items_cache   = null;   // id => item (active + inactive)
	private static $alias_index   = null;   // built lazily from active items

	// ───────────────────────────────────────────────────────── wiring

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_subtype_taxonomy' ], 5 );

		// Schema install: version-gated quick-exit, same discipline as the theme's
		// other migrations. Creates tables only. NEVER seeds a row.
		add_action( 'after_switch_theme', [ __CLASS__, 'install' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 4 );

		// Consumer adapters — the shipped Jobs module already binds these filters
		// with neutral fallbacks. We make them resolve THROUGH the catalog, so an
		// empty catalog leaves Jobs' own 'other'/'service' defaults untouched.
		add_filter( 'zdz_default_job_component', [ __CLASS__, 'adapter_job_default_component' ], 10, 1 );
		add_filter( 'zdz_job_components', [ __CLASS__, 'adapter_job_components' ], 10, 1 );
		add_filter( 'zdz_job_classify_component', [ __CLASS__, 'adapter_job_classify_component' ], 10, 2 );
		add_filter( 'zdz_job_detect_brand', [ __CLASS__, 'adapter_job_detect_brand' ], 10, 2 );

		// Canonical consumer contract — future consumers (estimate, commission,
		// stock, the fabrication modules) bind to these filters when a hard dependency
		// is undesirable. They mirror the static API and all degrade to neutral.
		add_filter( 'zdz_item_classify', [ __CLASS__, 'filter_classify' ], 10, 2 );
		add_filter( 'zdz_item_match', [ __CLASS__, 'filter_match' ], 10, 3 );
		add_filter( 'zdz_item_aliases', [ __CLASS__, 'filter_aliases' ], 10, 1 );
		add_filter( 'zdz_item_kinds', [ __CLASS__, 'filter_kinds' ], 10, 1 );
		add_filter( 'zdz_item_count_categories', [ __CLASS__, 'filter_count_categories' ], 10, 1 );
		add_filter( 'zdz_item_get', [ __CLASS__, 'filter_get' ], 10, 2 );
		add_filter( 'zdz_pricing_resolve', [ __CLASS__, 'filter_pricing_resolve' ], 10, 3 );
		add_filter( 'zdz_item_engine_version', [ __CLASS__, 'filter_version' ], 10, 1 );

		add_action( 'rest_api_init', [ __CLASS__, 'register_rest' ] );
	}

	/**
	 * Register the subtype taxonomy.
	 *
	 * Registering a taxonomy is schema/runtime, not data seeding — it inserts no
	 * terms. Terms (subtypes) are created only when an owner defines one, or when
	 * the optional sample set is explicitly applied.
	 */
	public static function register_subtype_taxonomy() {
		if ( taxonomy_exists( self::SUBTYPE_TAX ) ) {
			return;
		}
		register_taxonomy(
			self::SUBTYPE_TAX,
			self::SUBTYPE_OBJ,
			[
				'labels'            => [
					'name'          => __( 'Item Subtypes', 'zorderz' ),
					'singular_name' => __( 'Item Subtype', 'zorderz' ),
				],
				'public'            => false,
				'show_ui'           => false,   // the Item Engine admin owns the UI
				'show_in_rest'      => false,
				'hierarchical'      => false,   // behaves like tags
				'rewrite'           => false,
			]
		);
	}

	// ───────────────────────────────────────────────────────── schema

	public static function maybe_install() {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the catalog tables. Schema only — no rows. Idempotent and safe to
	 * call repeatedly; the version gate makes the common path a single option read.
	 */
	public static function install() {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$items   = $wpdb->prefix . self::ITEMS_TABLE;
		$schemes = $wpdb->prefix . self::SCHEMES_TABLE;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items ) ) !== $items ) {
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS `{$items}` (
					`id` varchar(80) NOT NULL,
					`type` varchar(16) NOT NULL DEFAULT 'product',
					`subtype` varchar(80) NOT NULL DEFAULT '',
					`display_name` varchar(191) NOT NULL DEFAULT '',
					`aliases` longtext,
					`negative_aliases` longtext,
					`attributes` longtext,
					`sellable` varchar(16) NOT NULL DEFAULT 'standalone',
					`consumes` longtext,
					`pricing_scheme_id` varchar(80) NOT NULL DEFAULT '',
					`rules` longtext,
					`external_refs` longtext,
					`unit_noun_singular` varchar(64) NOT NULL DEFAULT '',
					`unit_noun_plural` varchar(64) NOT NULL DEFAULT '',
					`parent_item_id` varchar(80) NOT NULL DEFAULT '',
					`match_priority` int NOT NULL DEFAULT 50,
					`countable` tinyint(1) NOT NULL DEFAULT 0,
					`active` tinyint(1) NOT NULL DEFAULT 1,
					`sort_order` int NOT NULL DEFAULT 0,
					`created_at` datetime NOT NULL,
					`updated_at` datetime NOT NULL,
					PRIMARY KEY (`id`),
					KEY `type` (`type`),
					KEY `subtype` (`subtype`),
					KEY `active` (`active`),
					KEY `countable` (`countable`),
					KEY `parent_item_id` (`parent_item_id`)
				) {$charset};"
			);
			if ( $wpdb->last_error ) {
				error_log( 'Zorderz Item Engine: FAILED to create ' . $items . ': ' . $wpdb->last_error );
				return; // don't set the gate — retry next request
			}
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $schemes ) ) !== $schemes ) {
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS `{$schemes}` (
					`id` varchar(80) NOT NULL,
					`name` varchar(191) NOT NULL DEFAULT '',
					`method` varchar(24) NOT NULL DEFAULT 'flat',
					`params` longtext,
					`expression` text,
					`scope` varchar(16) NOT NULL DEFAULT 'global',
					`cloned_from` varchar(80) NOT NULL DEFAULT '',
					`currency` varchar(8) NOT NULL DEFAULT '',
					`created_at` datetime NOT NULL,
					`updated_at` datetime NOT NULL,
					PRIMARY KEY (`id`),
					KEY `scope` (`scope`),
					KEY `method` (`method`)
				) {$charset};"
			);
			if ( $wpdb->last_error ) {
				error_log( 'Zorderz Item Engine: FAILED to create ' . $schemes . ': ' . $wpdb->last_error );
				return;
			}
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	private static function items_table() {
		global $wpdb;
		return $wpdb->prefix . self::ITEMS_TABLE;
	}

	private static function schemes_table() {
		global $wpdb;
		return $wpdb->prefix . self::SCHEMES_TABLE;
	}

	// ───────────────────────────────────────────────────────── version / cache

	/**
	 * The content version. A cache-buster that consumers fold into their own
	 * classification-cache keys, so editing the catalog self-invalidates their
	 * caches with zero backfill (the pattern the incumbent COGS catalog used).
	 */
	public static function version() {
		if ( null === self::$cache_version ) {
			self::$cache_version = (int) get_option( self::VERSION_OPTION, 1 );
		}
		return self::$cache_version;
	}

	/** Bump the content version and drop request-level caches. Called on every write. */
	public static function bump_version() {
		$v = self::version() + 1;
		update_option( self::VERSION_OPTION, $v, true );
		self::flush();
	}

	/** Drop request-level caches. */
	public static function flush() {
		self::$cache_version = null;
		self::$items_cache   = null;
		self::$alias_index   = null;
	}

	// ───────────────────────────────────────────────────────── read: items

	/**
	 * Load one item by id, or null.
	 *
	 * @return array|null
	 */
	public static function get( $item_id ) {
		$item_id = self::slug( $item_id );
		if ( '' === $item_id ) {
			return null;
		}
		$all = self::load_all();
		return $all[ $item_id ] ?? null;
	}

	/**
	 * All items matching a filter.
	 *
	 * @param array $filter Supported keys: type, subtype, sellable, countable
	 *                      (bool), active (bool, default true), parent_item_id,
	 *                      and 'attributes' => [ key => value ] for attribute
	 *                      equality (e.g. ['attributes' => ['bench_payable' => true]]).
	 * @return array<string,array> id => item
	 */
	public static function all( array $filter = [] ) {
		$active = array_key_exists( 'active', $filter ) ? (bool) $filter['active'] : true;
		$out    = [];
		foreach ( self::load_all() as $id => $item ) {
			if ( array_key_exists( 'active', $filter ) && $item['active'] !== $active ) {
				continue;
			}
			if ( ! array_key_exists( 'active', $filter ) && ! $item['active'] ) {
				continue;
			}
			if ( isset( $filter['type'] ) && $item['type'] !== $filter['type'] ) {
				continue;
			}
			if ( isset( $filter['subtype'] ) && $item['subtype'] !== $filter['subtype'] ) {
				continue;
			}
			if ( isset( $filter['sellable'] ) && $item['sellable'] !== $filter['sellable'] ) {
				continue;
			}
			if ( array_key_exists( 'countable', $filter ) && $item['countable'] !== (bool) $filter['countable'] ) {
				continue;
			}
			if ( isset( $filter['parent_item_id'] ) && $item['parent_item_id'] !== $filter['parent_item_id'] ) {
				continue;
			}
			if ( ! empty( $filter['attributes'] ) && is_array( $filter['attributes'] ) ) {
				$ok = true;
				foreach ( $filter['attributes'] as $k => $v ) {
					if ( ! array_key_exists( $k, $item['attributes'] ) || $item['attributes'][ $k ] != $v ) { // loose compare on purpose
						$ok = false;
						break;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}
			$out[ $id ] = $item;
		}
		return $out;
	}

	/** Count of items — the cheapest "is the catalog empty?" probe. */
	public static function item_count() {
		global $wpdb;
		$table = self::items_table();
		// The table may not exist yet on a very fresh install; degrade to 0 (empty)
		// rather than emitting a DB error, so adapters resolve to neutral safely.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	public static function is_empty() {
		return 0 === self::item_count();
	}

	/** Load every row (active and inactive), hydrated, keyed by id. Cached per request. */
	private static function load_all() {
		if ( null !== self::$items_cache ) {
			return self::$items_cache;
		}
		self::$items_cache = [];
		global $wpdb;
		$table = self::items_table();
		// Table may not exist yet on a very fresh install; guard.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return self::$items_cache;
		}
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `match_priority` DESC, `sort_order` ASC, `id` ASC", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$item = self::hydrate( $r );
			self::$items_cache[ $item['id'] ] = $item;
		}
		return self::$items_cache;
	}

	/** Turn a DB row into the canonical item array. */
	private static function hydrate( array $r ) {
		return [
			'id'                 => (string) $r['id'],
			'type'               => in_array( $r['type'], self::TYPES, true ) ? $r['type'] : 'product',
			'subtype'            => (string) $r['subtype'],
			'subtype_scope'      => self::subtype_scope( (string) $r['subtype'] ),
			'display_name'       => (string) $r['display_name'],
			'aliases'            => self::json_list( $r['aliases'] ),
			'negative_aliases'   => self::json_list( $r['negative_aliases'] ),
			'attributes'         => self::json_map( $r['attributes'] ),
			'sellable'           => in_array( $r['sellable'], self::SELLABLE, true ) ? $r['sellable'] : 'standalone',
			'consumes'           => self::json_map( $r['consumes'] ),
			'pricing_scheme_id'  => (string) $r['pricing_scheme_id'],
			'rules'              => self::json_list( $r['rules'] ),
			'external_refs'      => self::json_map( $r['external_refs'] ),
			'unit_noun_singular' => (string) $r['unit_noun_singular'],
			'unit_noun_plural'   => (string) $r['unit_noun_plural'],
			'parent_item_id'     => (string) $r['parent_item_id'],
			'match_priority'     => (int) $r['match_priority'],
			'countable'          => (bool) $r['countable'],
			'active'             => (bool) $r['active'],
			'sort_order'         => (int) $r['sort_order'],
		];
	}

	// ───────────────────────────────────────────────────────── resolver: match / classify

	/**
	 * Resolve free text to a single Item — longest-alias-wins, then match_priority.
	 *
	 * An item is disqualified if any of its negative_aliases is present. Each alias
	 * may declare a match_mode ('substring' default, 'word_boundary', 'exact').
	 *
	 * @return array|null the matched item, or null (empty catalog / no match).
	 */
	public static function match( $text, array $opts = [] ) {
		$hits = self::match_scored( $text, $opts );
		if ( ! $hits ) {
			return null;
		}
		$best = $hits[0]['id'];
		return self::get( $best );
	}

	/**
	 * Every distinct Item named anywhere in the text (one line can name several).
	 *
	 * @return array<int,array> items, best-scoring first.
	 */
	public static function match_all( $text, array $opts = [] ) {
		$out  = [];
		$seen = [];
		foreach ( self::match_scored( $text, $opts, true ) as $h ) {
			if ( isset( $seen[ $h['id'] ] ) ) {
				continue;
			}
			$seen[ $h['id'] ] = true;
			$item             = self::get( $h['id'] );
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Classify free text to a "kind" — the item id, which IS the count category in
	 * the item-keyed contract. Returns null when the catalog is empty or nothing
	 * matches, so callers fall back to their own neutral value.
	 *
	 * @return string|null
	 */
	public static function classify( $text ) {
		$item = self::match( $text );
		return $item ? $item['id'] : null;
	}

	/**
	 * The scored match list. Internal.
	 *
	 * @return array<int,array{id:string,len:int,priority:int}>
	 */
	private static function match_scored( $text, array $opts = [], $all = false ) {
		$norm = self::normalize( $text );
		if ( '' === $norm ) {
			return [];
		}
		$index = self::alias_index();
		if ( ! $index ) {
			return [];
		}

		// Disqualify items whose negative alias is present.
		$disqualified = [];
		foreach ( self::load_all() as $id => $item ) {
			if ( ! $item['active'] ) {
				continue;
			}
			foreach ( $item['negative_aliases'] as $neg ) {
				if ( self::alias_present( self::normalize( is_array( $neg ) ? ( $neg['value'] ?? '' ) : $neg ), $norm, 'substring' ) ) {
					$disqualified[ $id ] = true;
					break;
				}
			}
		}

		$hits = [];
		foreach ( $index as $entry ) {
			if ( isset( $disqualified[ $entry['id'] ] ) ) {
				continue;
			}
			if ( ! empty( $opts['type'] ) && $entry['type'] !== $opts['type'] ) {
				continue;
			}
			if ( ! empty( $opts['subtype'] ) && $entry['subtype'] !== $opts['subtype'] ) {
				continue;
			}
			if ( self::alias_present( $entry['alias'], $norm, $entry['mode'] ) ) {
				$hits[] = [ 'id' => $entry['id'], 'len' => $entry['len'], 'priority' => $entry['priority'] ];
			}
		}
		if ( ! $hits ) {
			return [];
		}
		// Longest alias wins; ties broken by higher match_priority.
		usort(
			$hits,
			static function ( $a, $b ) {
				if ( $a['len'] !== $b['len'] ) {
					return $b['len'] <=> $a['len'];
				}
				return $b['priority'] <=> $a['priority'];
			}
		);
		return $hits;
	}

	/**
	 * The flat alias index: one entry per (item, alias), sorted longest-first.
	 * Cached per request.
	 *
	 * @return array<int,array{alias:string,len:int,id:string,priority:int,mode:string,type:string,subtype:string}>
	 */
	private static function alias_index() {
		if ( null !== self::$alias_index ) {
			return self::$alias_index;
		}
		self::$alias_index = [];
		foreach ( self::load_all() as $item ) {
			if ( ! $item['active'] ) {
				continue;
			}
			// The display name is always an implicit alias.
			$aliases = $item['aliases'];
			if ( '' !== $item['display_name'] ) {
				$aliases[] = $item['display_name'];
			}
			foreach ( $aliases as $a ) {
				$value = is_array( $a ) ? ( $a['value'] ?? '' ) : $a;
				$mode  = is_array( $a ) ? ( $a['match_mode'] ?? 'substring' ) : 'substring';
				$norm  = self::normalize( $value );
				if ( '' === $norm ) {
					continue;
				}
				self::$alias_index[] = [
					'alias'    => $norm,
					'len'      => mb_strlen( $norm ),
					'id'       => $item['id'],
					'priority' => $item['match_priority'],
					'mode'     => in_array( $mode, [ 'substring', 'word_boundary', 'exact' ], true ) ? $mode : 'substring',
					'type'     => $item['type'],
					'subtype'  => $item['subtype'],
				];
			}
		}
		return self::$alias_index;
	}

	/** Is a normalized alias present in normalized text, per match mode? */
	private static function alias_present( $alias, $text, $mode ) {
		if ( '' === $alias ) {
			return false;
		}
		switch ( $mode ) {
			case 'exact':
				return $alias === $text;
			case 'word_boundary':
				return (bool) preg_match( '/(?<![a-z0-9])' . preg_quote( $alias, '/' ) . '(?![a-z0-9])/u', $text );
			case 'substring':
			default:
				return false !== mb_strpos( $text, $alias );
		}
	}

	private static function normalize( $text ) {
		$text = (string) $text;
		$text = mb_strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * alias => item id, longest alias first. This is the single export consumed by
	 * prompt builders and by JS (localized), so a tenant's vocabulary is defined
	 * exactly once. Empty catalog => [].
	 *
	 * @return array<string,string>
	 */
	public static function aliases() {
		$out = [];
		foreach ( self::alias_index() as $e ) {
			// First writer wins on collision, and the index is longest-first, so the
			// most specific alias keeps the mapping.
			if ( ! isset( $out[ $e['alias'] ] ) ) {
				$out[ $e['alias'] ] = $e['id'];
			}
		}
		return $out;
	}

	/** Alias of aliases(), matching the rewire-map's target API name. */
	public static function aliases_flat() {
		return self::aliases();
	}

	// ───────────────────────────────────────────────────────── the COUNTS CONTRACT

	/**
	 * The count vocabulary: id => meta for every countable Item. This is the single
	 * authority that replaces the fixed seven-bucket enum. Empty catalog => [].
	 *
	 * @return array<string,array>
	 */
	public static function count_categories() {
		$out = [];
		foreach ( self::all( [ 'countable' => true ] ) as $id => $item ) {
			$out[ $id ] = self::count_meta( $id );
		}
		return $out;
	}

	/** The countable item ids (the "kinds"). Empty catalog => []. */
	public static function kinds() {
		return array_keys( self::all( [ 'countable' => true ] ) );
	}

	/**
	 * The count meta for one item id: everything a prose builder needs so it never
	 * hardcodes "screen"/"door"/"unit" again.
	 *
	 * @return array
	 */
	public static function count_meta( $item_id ) {
		$item = self::get( $item_id );
		if ( ! $item ) {
			return [];
		}
		return [
			'type'               => $item['type'],
			'subtype'            => $item['subtype'],
			'display_name'       => $item['display_name'],
			'unit_noun_singular' => $item['unit_noun_singular'],
			'unit_noun_plural'   => $item['unit_noun_plural'],
			'parent_item_id'     => $item['parent_item_id'],
			'by_attribute'       => [],   // brand splits etc. land here, never in counts{}
		];
	}

	/**
	 * A fresh, empty counts envelope in the v2 shape.
	 *
	 * The shape is deliberate (see the counts contract in the doc):
	 *   - 'shape' is a discriminator so consumers branch instead of probing keys.
	 *   - 'counts' is SCALAR-ONLY at the top level: { item_id => int }. Brand and
	 *     other splits live in counts_meta[id]['by_attribute'], never inside counts.
	 *   - 'requested_item_ids' preserves absent != zero ("we didn't ask" is not
	 *     "we sold none").
	 *
	 * @param array $requested_item_ids ids that were asked for (may be empty).
	 * @return array
	 */
	public static function new_counts( array $requested_item_ids = [] ) {
		return [
			'shape'              => self::COUNTS_SHAPE,
			'counts'             => [],
			'counts_meta'        => [],
			'requested_item_ids' => array_values( array_map( [ __CLASS__, 'slug' ], $requested_item_ids ) ),
		];
	}

	/**
	 * Add to a count in an envelope, stamping the item's meta. Keeps counts scalar.
	 *
	 * @return array the mutated envelope.
	 */
	public static function add_count( array $envelope, $item_id, $n = 1 ) {
		$item_id = self::slug( $item_id );
		if ( '' === $item_id ) {
			return $envelope;
		}
		$current                          = isset( $envelope['counts'][ $item_id ] ) ? (int) $envelope['counts'][ $item_id ] : 0;
		$envelope['counts'][ $item_id ]   = $current + (int) $n;
		$envelope['counts_meta'][ $item_id ] = self::count_meta( $item_id );
		return $envelope;
	}

	/**
	 * Validate a counts envelope against the contract. Returns true or a WP_Error
	 * naming the first violation. The rule that matters most: counts must be scalar
	 * at the top level (the old enum went heterogeneous once and broke a consumer).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_counts( $envelope ) {
		if ( ! is_array( $envelope ) ) {
			return new WP_Error( 'zdz_counts_shape', __( 'Counts payload is not an array.', 'zorderz' ) );
		}
		if ( ( $envelope['shape'] ?? '' ) !== self::COUNTS_SHAPE ) {
			return new WP_Error( 'zdz_counts_shape', __( 'Counts payload is missing the shape discriminator.', 'zorderz' ) );
		}
		if ( ! isset( $envelope['counts'] ) || ! is_array( $envelope['counts'] ) ) {
			return new WP_Error( 'zdz_counts_shape', __( 'Counts payload has no counts map.', 'zorderz' ) );
		}
		foreach ( $envelope['counts'] as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				return new WP_Error(
					'zdz_counts_scalar',
					/* translators: %s: the offending count key. */
					sprintf( __( 'Count "%s" is not scalar. Splits belong in counts_meta.by_attribute.', 'zorderz' ), (string) $k )
				);
			}
		}
		return true;
	}

	/**
	 * A phrase like "3 screens" using the item's own unit noun — the one helper that
	 * replaces every hardcoded "screen(s)"/"door(s)" sprintf across the modules.
	 */
	public static function count_phrase( $item_id, $n ) {
		$meta = self::count_meta( $item_id );
		if ( ! $meta ) {
			return (string) (int) $n;
		}
		$n    = (int) $n;
		$noun = 1 === $n ? $meta['unit_noun_singular'] : $meta['unit_noun_plural'];
		if ( '' === $noun ) {
			$noun = $meta['display_name'];
		}
		return trim( $n . ' ' . $noun );
	}

	/**
	 * A tenant-defined map from legacy count-bucket words to item ids, so a module
	 * still speaking an old vocabulary resolves through the catalog. Empty by
	 * default — the platform ships no legacy words. Populate via the filter.
	 *
	 * @return array<string,string> legacy_key => item_id
	 */
	public static function legacy_count_map() {
		return (array) apply_filters( 'zdz_item_legacy_count_map', [] );
	}

	// ───────────────────────────────────────────────────────── pricing schemes

	/** @return array|null */
	public static function pricing_scheme( $scheme_id ) {
		$scheme_id = self::slug( $scheme_id );
		if ( '' === $scheme_id ) {
			return null;
		}
		global $wpdb;
		$table = self::schemes_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %s", $scheme_id ), ARRAY_A );
		return $row ? self::hydrate_scheme( $row ) : null;
	}

	/** @return array<string,array> id => scheme */
	public static function pricing_schemes( array $filter = [] ) {
		global $wpdb;
		$table = self::schemes_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY name ASC, id ASC", ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$s = self::hydrate_scheme( $r );
			if ( isset( $filter['method'] ) && $s['method'] !== $filter['method'] ) {
				continue;
			}
			if ( isset( $filter['scope'] ) && $s['scope'] !== $filter['scope'] ) {
				continue;
			}
			$out[ $s['id'] ] = $s;
		}
		return $out;
	}

	private static function hydrate_scheme( array $r ) {
		return [
			'id'          => (string) $r['id'],
			'name'        => (string) $r['name'],
			'method'      => in_array( $r['method'], self::PRICING_METHODS, true ) ? $r['method'] : 'flat',
			'params'      => self::json_map( $r['params'] ),
			'expression'  => (string) $r['expression'],
			'scope'       => in_array( $r['scope'], self::SCOPES, true ) || 'item' === $r['scope'] ? $r['scope'] : 'global',
			'cloned_from' => (string) $r['cloned_from'],
			'currency'    => (string) $r['currency'],
		];
	}

	/**
	 * Resolve a Pricing Scheme against a context to a money amount.
	 *
	 * Context keys understood by the built-in methods: qty / quantity, hours,
	 * area (or width_in + height_in), cost, msrp, markup, billed, and any name the
	 * scheme's formula references. quote_only returns a null amount deliberately —
	 * "no price" is a declared state, not a missing row.
	 *
	 * @return array{amount:?float,method:string,quote_only:bool,explain:string}
	 */
	public static function resolve_price( $scheme_id, array $ctx = [] ) {
		$scheme = is_array( $scheme_id ) ? $scheme_id : self::pricing_scheme( $scheme_id );
		if ( ! $scheme ) {
			return [ 'amount' => null, 'method' => '', 'quote_only' => false, 'explain' => __( 'No pricing scheme.', 'zorderz' ) ];
		}
		$p    = $scheme['params'];
		$qty  = (float) ( $ctx['qty'] ?? $ctx['quantity'] ?? 1 );
		$rate = (float) ( $p['rate'] ?? $p['amount'] ?? 0 );

		switch ( $scheme['method'] ) {
			case 'flat':
				$amt = (float) ( $p['amount'] ?? $p['rate'] ?? 0 );
				return self::price_result( $amt, $scheme, sprintf( 'flat %s', self::money( $amt ) ) );

			case 'per_unit':
				$amt = $rate * $qty;
				return self::price_result( $amt, $scheme, sprintf( '%s x %s', self::money( $rate ), $qty ) );

			case 'per_hour':
				$hours = (float) ( $ctx['hours'] ?? 0 );
				$amt   = $rate * $hours;
				return self::price_result( $amt, $scheme, sprintf( '%s/hr x %s', self::money( $rate ), $hours ) );

			case 'per_area':
				$area = isset( $ctx['area'] )
					? (float) $ctx['area']
					: (float) ( $ctx['width_in'] ?? 0 ) * (float) ( $ctx['height_in'] ?? 0 );
				$amt = $rate * $area;
				if ( isset( $p['min_charge'] ) ) {
					$amt = max( $amt, (float) $p['min_charge'] );
				}
				return self::price_result( $amt, $scheme, sprintf( '%s/%s x %s', self::money( $rate ), $p['area_basis'] ?? 'unit', $area ) );

			case 'per_visit':
				$amt = $rate; // a per-visit / minimum charge
				return self::price_result( $amt, $scheme, sprintf( 'per visit %s', self::money( $amt ) ) );

			case 'tiered':
				return self::resolve_tiered( $scheme, $ctx );

			case 'formula':
				$vars = array_merge(
					[ 'qty' => $qty, 'quantity' => $qty ],
					self::numeric_only( $p ),
					self::numeric_only( $ctx )
				);
				$amt = self::eval_formula( $scheme['expression'], $vars );
				return self::price_result( $amt, $scheme, self::sanitize_explain( $scheme['expression'] ) );

			case 'quote_only':
			default:
				return [ 'amount' => null, 'method' => 'quote_only', 'quote_only' => true, 'explain' => __( 'Quote only.', 'zorderz' ) ];
		}
	}

	private static function resolve_tiered( array $scheme, array $ctx ) {
		$p     = $scheme['params'];
		$tiers = isset( $p['tiers'] ) && is_array( $p['tiers'] ) ? $p['tiers'] : [];
		$axis  = (float) ( $ctx[ $p['axis'] ?? 'qty' ] ?? $ctx['qty'] ?? $ctx['quantity'] ?? 0 );
		$amt   = 0.0;
		foreach ( $tiers as $tier ) {
			$up_to = isset( $tier['up_to'] ) && null !== $tier['up_to'] ? (float) $tier['up_to'] : INF;
			if ( $axis <= $up_to ) {
				$amt = (float) ( $tier['amount'] ?? 0 );
				break;
			}
			$amt = (float) ( $tier['amount'] ?? $amt );
		}
		return self::price_result( $amt, $scheme, sprintf( 'tiered @ %s', $axis ) );
	}

	private static function price_result( $amount, array $scheme, $explain ) {
		$amount = round( (float) $amount, 2 );
		// A stated min/max band is informational; we clamp to a min if present.
		if ( isset( $scheme['params']['min'] ) && $amount < (float) $scheme['params']['min'] ) {
			$amount  = (float) $scheme['params']['min'];
			$explain .= ' (min applied)';
		}
		return [ 'amount' => $amount, 'method' => $scheme['method'], 'quote_only' => false, 'explain' => $explain ];
	}

	/**
	 * Clone a scheme under a new id/name, recording provenance so a family of rates
	 * can be revised together.
	 *
	 * @return string|WP_Error the new id.
	 */
	public static function clone_scheme( $scheme_id, $new_id = '', $new_name = '' ) {
		$src = self::pricing_scheme( $scheme_id );
		if ( ! $src ) {
			return new WP_Error( 'zdz_scheme_missing', __( 'Scheme not found.', 'zorderz' ) );
		}
		$new_id = self::slug( $new_id !== '' ? $new_id : ( $src['id'] . '-copy' ) );
		$copy   = $src;
		$copy['id']          = $new_id;
		$copy['name']        = '' !== $new_name ? $new_name : ( $src['name'] . ' (copy)' );
		$copy['cloned_from'] = $src['id'];
		$r                   = self::save_scheme( $copy );
		return is_wp_error( $r ) ? $r : $new_id;
	}

	// ───────────────────────────────────────────────────────── safe formula evaluator

	/**
	 * Evaluate an arithmetic expression over named variables. NO eval().
	 *
	 * Supports + - * / and parentheses over numbers and variable names. Unknown
	 * variables resolve to 0. Division by zero yields 0. Anything the tokenizer
	 * does not recognise yields 0 — the evaluator refuses rather than guessing.
	 *
	 * This is the CORE mechanism the whole Pricing Scheme model rests on; it is
	 * intentionally small and closed. It is the one thing not to generalise loosely.
	 */
	public static function eval_formula( $expr, array $vars = [] ) {
		$expr = (string) $expr;
		if ( '' === trim( $expr ) ) {
			return 0.0;
		}
		$tokens = self::tokenize_expr( $expr, $vars );
		if ( null === $tokens ) {
			return 0.0;
		}
		$rpn = self::to_rpn( $tokens );
		if ( null === $rpn ) {
			return 0.0;
		}
		return self::eval_rpn( $rpn );
	}

	/** @return array|null token list, or null on an illegal token. */
	private static function tokenize_expr( $expr, array $vars ) {
		$tokens = [];
		$len    = strlen( $expr );
		$i      = 0;
		while ( $i < $len ) {
			$c = $expr[ $i ];
			if ( ctype_space( $c ) ) {
				$i++;
				continue;
			}
			if ( false !== strpos( '+-*/()', $c ) ) {
				$tokens[] = [ 'op', $c ];
				$i++;
				continue;
			}
			if ( ctype_digit( $c ) || '.' === $c ) {
				$num = '';
				while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
					$num .= $expr[ $i ];
					$i++;
				}
				$tokens[] = [ 'num', (float) $num ];
				continue;
			}
			if ( ctype_alpha( $c ) || '_' === $c ) {
				$name = '';
				while ( $i < $len && ( ctype_alnum( $expr[ $i ] ) || '_' === $expr[ $i ] ) ) {
					$name .= $expr[ $i ];
					$i++;
				}
				$tokens[] = [ 'num', (float) ( $vars[ $name ] ?? 0 ) ];
				continue;
			}
			// Illegal character — refuse.
			return null;
		}
		return $tokens;
	}

	/** Shunting-yard to RPN. @return array|null */
	private static function to_rpn( array $tokens ) {
		$out   = [];
		$stack = [];
		$prec  = [ '+' => 1, '-' => 1, '*' => 2, '/' => 2 ];
		foreach ( $tokens as $t ) {
			if ( 'num' === $t[0] ) {
				$out[] = $t;
			} elseif ( '(' === $t[1] ) {
				$stack[] = $t;
			} elseif ( ')' === $t[1] ) {
				$found = false;
				while ( $stack ) {
					$top = array_pop( $stack );
					if ( '(' === $top[1] ) {
						$found = true;
						break;
					}
					$out[] = $top;
				}
				if ( ! $found ) {
					return null; // unbalanced
				}
			} else { // operator
				while ( $stack ) {
					$top = end( $stack );
					if ( 'op' === $top[0] && '(' !== $top[1] && $prec[ $top[1] ] >= $prec[ $t[1] ] ) {
						$out[] = array_pop( $stack );
					} else {
						break;
					}
				}
				$stack[] = $t;
			}
		}
		while ( $stack ) {
			$top = array_pop( $stack );
			if ( '(' === $top[1] ) {
				return null; // unbalanced
			}
			$out[] = $top;
		}
		return $out;
	}

	private static function eval_rpn( array $rpn ) {
		$stack = [];
		foreach ( $rpn as $t ) {
			if ( 'num' === $t[0] ) {
				$stack[] = (float) $t[1];
				continue;
			}
			if ( count( $stack ) < 2 ) {
				return 0.0;
			}
			$b = array_pop( $stack );
			$a = array_pop( $stack );
			switch ( $t[1] ) {
				case '+':
					$stack[] = $a + $b;
					break;
				case '-':
					$stack[] = $a - $b;
					break;
				case '*':
					$stack[] = $a * $b;
					break;
				case '/':
					$stack[] = 0.0 == $b ? 0.0 : $a / $b;
					break;
			}
		}
		return $stack ? (float) end( $stack ) : 0.0;
	}

	// ───────────────────────────────────────────────────────── write: items

	/**
	 * Create or replace an Item. Used by the admin UI and by discovery approval.
	 * Bumps the content version so consumer caches self-invalidate.
	 *
	 * @return true|WP_Error
	 */
	public static function save_item( array $item ) {
		$id = self::slug( $item['id'] ?? '' );
		if ( '' === $id ) {
			$id = self::slug( $item['display_name'] ?? '' );
		}
		if ( '' === $id ) {
			return new WP_Error( 'zdz_item_id', __( 'An item needs an id or a name.', 'zorderz' ) );
		}
		$type = ( $item['type'] ?? 'product' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'zdz_item_type', __( 'Type must be product or service.', 'zorderz' ) );
		}

		$subtype = self::slug( $item['subtype'] ?? '' );
		if ( '' !== $subtype ) {
			// Ensure the subtype term exists, honouring its declared scope/type.
			self::ensure_subtype( $subtype, $item['subtype_label'] ?? '', $item['subtype_scope'] ?? 'global', $type, (int) ( $item['match_priority'] ?? 50 ) );
		}

		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = [
			'id'                 => $id,
			'type'               => $type,
			'subtype'            => $subtype,
			'display_name'       => sanitize_text_field( (string) ( $item['display_name'] ?? '' ) ),
			'aliases'            => wp_json_encode( self::clean_alias_list( $item['aliases'] ?? [] ) ),
			'negative_aliases'   => wp_json_encode( self::clean_alias_list( $item['negative_aliases'] ?? [] ) ),
			'attributes'         => wp_json_encode( (object) ( is_array( $item['attributes'] ?? null ) ? $item['attributes'] : [] ) ),
			'sellable'           => in_array( $item['sellable'] ?? '', self::SELLABLE, true ) ? $item['sellable'] : 'standalone',
			'consumes'           => wp_json_encode( is_array( $item['consumes'] ?? null ) ? array_values( $item['consumes'] ) : [] ),
			'pricing_scheme_id'  => self::slug( $item['pricing_scheme_id'] ?? '' ),
			'rules'              => wp_json_encode( is_array( $item['rules'] ?? null ) ? array_values( $item['rules'] ) : [] ),
			'external_refs'      => wp_json_encode( (object) ( is_array( $item['external_refs'] ?? null ) ? $item['external_refs'] : [] ) ),
			'unit_noun_singular' => sanitize_text_field( (string) ( $item['unit_noun_singular'] ?? '' ) ),
			'unit_noun_plural'   => sanitize_text_field( (string) ( $item['unit_noun_plural'] ?? '' ) ),
			'parent_item_id'     => self::slug( $item['parent_item_id'] ?? '' ),
			'match_priority'     => (int) ( $item['match_priority'] ?? 50 ),
			'countable'          => ! empty( $item['countable'] ) ? 1 : 0,
			'active'             => array_key_exists( 'active', $item ) ? ( $item['active'] ? 1 : 0 ) : 1,
			'sort_order'         => (int) ( $item['sort_order'] ?? 0 ),
			'updated_at'         => $now,
		];

		$table  = self::items_table();
		$exists = (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id = %s", $id ) ) === $id;
		if ( $exists ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'zdz_item_db', $wpdb->last_error );
		}
		self::bump_version();
		return true;
	}

	/** @return bool */
	public static function delete_item( $item_id ) {
		$item_id = self::slug( $item_id );
		if ( '' === $item_id ) {
			return false;
		}
		global $wpdb;
		$ok = (bool) $wpdb->delete( self::items_table(), [ 'id' => $item_id ] );
		if ( $ok ) {
			self::bump_version();
		}
		return $ok;
	}

	// ───────────────────────────────────────────────────────── write: schemes

	/** @return true|WP_Error */
	public static function save_scheme( array $scheme ) {
		$id = self::slug( $scheme['id'] ?? '' );
		if ( '' === $id ) {
			$id = self::slug( $scheme['name'] ?? '' );
		}
		if ( '' === $id ) {
			return new WP_Error( 'zdz_scheme_id', __( 'A pricing scheme needs an id or a name.', 'zorderz' ) );
		}
		$method = $scheme['method'] ?? 'flat';
		if ( ! in_array( $method, self::PRICING_METHODS, true ) ) {
			return new WP_Error( 'zdz_scheme_method', __( 'Unknown pricing method.', 'zorderz' ) );
		}
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = [
			'id'          => $id,
			'name'        => sanitize_text_field( (string) ( $scheme['name'] ?? $id ) ),
			'method'      => $method,
			'params'      => wp_json_encode( (object) ( is_array( $scheme['params'] ?? null ) ? $scheme['params'] : [] ) ),
			'expression'  => (string) ( $scheme['expression'] ?? '' ),
			'scope'       => in_array( $scheme['scope'] ?? '', [ 'global', 'item' ], true ) ? $scheme['scope'] : 'global',
			'cloned_from' => self::slug( $scheme['cloned_from'] ?? '' ),
			'currency'    => sanitize_text_field( (string) ( $scheme['currency'] ?? '' ) ),
			'updated_at'  => $now,
		];
		$table  = self::schemes_table();
		$exists = (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id = %s", $id ) ) === $id;
		if ( $exists ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'zdz_scheme_db', $wpdb->last_error );
		}
		self::bump_version();
		return true;
	}

	/** @return bool */
	public static function delete_scheme( $scheme_id ) {
		$scheme_id = self::slug( $scheme_id );
		if ( '' === $scheme_id ) {
			return false;
		}
		global $wpdb;
		$ok = (bool) $wpdb->delete( self::schemes_table(), [ 'id' => $scheme_id ] );
		if ( $ok ) {
			self::bump_version();
		}
		return $ok;
	}

	// ───────────────────────────────────────────────────────── subtypes (WP taxonomy)

	/**
	 * The defined subtypes, from the taxonomy. Each carries its scope, type and
	 * match priority as term meta.
	 *
	 * @param bool $global_only when true, only 'global' subtypes (offered on new items).
	 * @return array<int,array{slug:string,label:string,scope:string,type:string,match_priority:int,count:int}>
	 */
	public static function subtypes( $global_only = false ) {
		if ( ! taxonomy_exists( self::SUBTYPE_TAX ) ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'   => self::SUBTYPE_TAX,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $t ) {
			$scope = (string) get_term_meta( $t->term_id, 'zdz_subtype_scope', true );
			$scope = in_array( $scope, self::SCOPES, true ) ? $scope : 'global';
			if ( $global_only && 'global' !== $scope ) {
				continue;
			}
			$out[] = [
				'slug'           => $t->slug,
				'label'          => $t->name,
				'scope'          => $scope,
				'type'           => (string) ( get_term_meta( $t->term_id, 'zdz_subtype_type', true ) ?: 'product' ),
				'match_priority' => (int) ( get_term_meta( $t->term_id, 'zdz_subtype_priority', true ) ?: 50 ),
				'count'          => (int) $t->count,
			];
		}
		return $out;
	}

	/** The resolved scope of a subtype slug, or 'global' if unknown. */
	public static function subtype_scope( $slug ) {
		$slug = self::slug( $slug );
		if ( '' === $slug || ! taxonomy_exists( self::SUBTYPE_TAX ) ) {
			return 'global';
		}
		$term = get_term_by( 'slug', $slug, self::SUBTYPE_TAX );
		if ( ! $term ) {
			return 'global';
		}
		$scope = (string) get_term_meta( $term->term_id, 'zdz_subtype_scope', true );
		return in_array( $scope, self::SCOPES, true ) ? $scope : 'global';
	}

	/**
	 * Create or update a subtype term. Returns the slug.
	 *
	 * @return string|WP_Error
	 */
	public static function ensure_subtype( $slug, $label = '', $scope = 'global', $type = 'product', $match_priority = 50 ) {
		if ( ! taxonomy_exists( self::SUBTYPE_TAX ) ) {
			self::register_subtype_taxonomy();
		}
		$slug  = self::slug( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'zdz_subtype_slug', __( 'A subtype needs a name.', 'zorderz' ) );
		}
		$label = '' !== $label ? $label : ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
		$term  = get_term_by( 'slug', $slug, self::SUBTYPE_TAX );
		if ( ! $term ) {
			$res = wp_insert_term( $label, self::SUBTYPE_TAX, [ 'slug' => $slug ] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$term_id = (int) $res['term_id'];
		} else {
			$term_id = (int) $term->term_id;
			wp_update_term( $term_id, self::SUBTYPE_TAX, [ 'name' => $label ] );
		}
		update_term_meta( $term_id, 'zdz_subtype_scope', in_array( $scope, self::SCOPES, true ) ? $scope : 'global' );
		update_term_meta( $term_id, 'zdz_subtype_type', in_array( $type, self::TYPES, true ) ? $type : 'product' );
		update_term_meta( $term_id, 'zdz_subtype_priority', (int) $match_priority );
		self::bump_version();
		return $slug;
	}

	/** @return bool */
	public static function delete_subtype( $slug ) {
		$slug = self::slug( $slug );
		if ( '' === $slug || ! taxonomy_exists( self::SUBTYPE_TAX ) ) {
			return false;
		}
		$term = get_term_by( 'slug', $slug, self::SUBTYPE_TAX );
		if ( ! $term ) {
			return false;
		}
		$ok = wp_delete_term( (int) $term->term_id, self::SUBTYPE_TAX );
		if ( true === $ok ) {
			self::bump_version();
			return true;
		}
		return false;
	}

	// ───────────────────────────────────────────────────────── discovery (hooks only)

	/**
	 * Ask connected systems to PROPOSE a catalog. Returns proposals only; nothing
	 * is written. Connectors (CRM, invoicing, a price list) attach to the filter
	 * and return items/schemes named verbatim from the tenant's own data with the
	 * reasoning shown. The platform ships no connectors, so out of the box this is
	 * empty — the hooks exist, tenant data never does.
	 *
	 * Guardrails baked into the mechanism: it never auto-applies (approval is a
	 * separate call), and it never editorialises about the prices it finds.
	 *
	 * @return array{enabled:bool,items:array,schemes:array,notes:array,sources:array}
	 */
	public static function discover( array $sources = [] ) {
		$enabled = (bool) apply_filters( 'zdz_item_discovery_enabled', true );
		if ( ! $enabled ) {
			return [ 'enabled' => false, 'items' => [], 'schemes' => [], 'notes' => [], 'sources' => [] ];
		}
		$proposal = apply_filters(
			'zdz_item_discovery_propose',
			[ 'items' => [], 'schemes' => [], 'notes' => [] ],
			$sources
		);
		$known = (array) apply_filters( 'zdz_item_discovery_sources', [] );
		return [
			'enabled' => true,
			'items'   => is_array( $proposal['items'] ?? null ) ? $proposal['items'] : [],
			'schemes' => is_array( $proposal['schemes'] ?? null ) ? $proposal['schemes'] : [],
			'notes'   => is_array( $proposal['notes'] ?? null ) ? $proposal['notes'] : [],
			'sources' => $known,
		];
	}

	/**
	 * Apply an owner-approved subset of a discovery proposal. Called only after
	 * item-by-item approval in the UI. This is the single "approved becomes real"
	 * path; discovery itself never writes.
	 *
	 * @return array{items:int,schemes:int,errors:array}
	 */
	public static function approve_proposal( array $items = [], array $schemes = [] ) {
		$done = [ 'items' => 0, 'schemes' => 0, 'errors' => [] ];
		foreach ( $schemes as $s ) {
			$r = self::save_scheme( (array) $s );
			if ( is_wp_error( $r ) ) {
				$done['errors'][] = $r->get_error_message();
			} else {
				$done['schemes']++;
			}
		}
		foreach ( $items as $it ) {
			$r = self::save_item( (array) $it );
			if ( is_wp_error( $r ) ) {
				$done['errors'][] = $r->get_error_message();
			} else {
				$done['items']++;
			}
		}
		return $done;
	}

	// ───────────────────────────────────────────────────────── consumer adapters: Jobs

	/**
	 * The shipped Jobs module binds four filters with neutral fallbacks. These
	 * adapters route them through the catalog. When the catalog is empty every one
	 * of them returns the incoming neutral value untouched, so Jobs keeps its own
	 * 'other'/'service' defaults and nothing is invented.
	 */
	public static function adapter_job_default_component( $fallback ) {
		if ( self::is_empty() ) {
			return $fallback;
		}
		$kinds = self::all( [ 'countable' => true ] );
		if ( ! $kinds ) {
			$kinds = self::all(); // any active item
		}
		$first = $kinds ? reset( $kinds ) : null;
		return $first ? ( '' !== $first['subtype'] ? $first['subtype'] : $first['id'] ) : $fallback;
	}

	public static function adapter_job_components( $default_map ) {
		if ( self::is_empty() ) {
			return $default_map;
		}
		$map = [];
		// One entry per subtype (the coarse "component"), else per item.
		foreach ( self::all() as $item ) {
			$key = '' !== $item['subtype'] ? $item['subtype'] : $item['id'];
			if ( ! isset( $map[ $key ] ) ) {
				$sub          = '' !== $item['subtype'] ? self::subtype_label( $item['subtype'] ) : $item['display_name'];
				$map[ $key ] = $sub !== '' ? $sub : $key;
			}
		}
		return $map ? $map : $default_map;
	}

	public static function adapter_job_classify_component( $pre, $text ) {
		if ( is_string( $pre ) && '' !== $pre ) {
			return $pre; // an earlier binding already decided
		}
		if ( self::is_empty() ) {
			return $pre; // null -> Jobs uses its own generic heuristic
		}
		$item = self::match( $text );
		if ( ! $item ) {
			return $pre;
		}
		return '' !== $item['subtype'] ? $item['subtype'] : $item['id'];
	}

	public static function adapter_job_detect_brand( $brand, $text ) {
		if ( self::is_empty() ) {
			return $brand;
		}
		$item = self::match( $text );
		if ( $item && ! empty( $item['attributes']['brand'] ) && is_string( $item['attributes']['brand'] ) ) {
			return $item['attributes']['brand'];
		}
		return $brand;
	}

	private static function subtype_label( $slug ) {
		foreach ( self::subtypes() as $s ) {
			if ( $s['slug'] === $slug ) {
				return $s['label'];
			}
		}
		return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	// ───────────────────────────────────────────────────────── canonical consumer filters

	public static function filter_classify( $pre, $text ) {
		if ( is_string( $pre ) && '' !== $pre ) {
			return $pre;
		}
		return self::classify( $text );
	}
	public static function filter_match( $pre, $text, $opts = [] ) {
		return null !== $pre ? $pre : self::match( $text, is_array( $opts ) ? $opts : [] );
	}
	public static function filter_aliases( $pre ) {
		return ! empty( $pre ) && is_array( $pre ) ? $pre : self::aliases();
	}
	public static function filter_kinds( $pre ) {
		return ! empty( $pre ) && is_array( $pre ) ? $pre : self::kinds();
	}
	public static function filter_count_categories( $pre ) {
		return ! empty( $pre ) && is_array( $pre ) ? $pre : self::count_categories();
	}
	public static function filter_get( $pre, $id ) {
		return null !== $pre ? $pre : self::get( $id );
	}
	public static function filter_pricing_resolve( $pre, $scheme_id, $ctx = [] ) {
		return null !== $pre ? $pre : self::resolve_price( $scheme_id, is_array( $ctx ) ? $ctx : [] );
	}
	public static function filter_version( $pre ) {
		return self::version();
	}

	// ───────────────────────────────────────────────────────── REST (publishes the vocabulary)

	public static function register_rest() {
		$read = static function () {
			return is_user_logged_in();
		};

		register_rest_route(
			ZDZ_REST_NS,
			'/item-engine/catalog',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $read,
				'callback'            => static function () {
					return rest_ensure_response(
						[
							'version'  => self::version(),
							'empty'    => self::is_empty(),
							'types'    => self::TYPES,
							'subtypes' => self::subtypes(),
							'items'    => array_values( self::all() ),
						]
					);
				},
			]
		);

		register_rest_route(
			ZDZ_REST_NS,
			'/item-engine/count-categories',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $read,
				'callback'            => static function () {
					return rest_ensure_response(
						[
							'shape'      => self::COUNTS_SHAPE,
							'version'    => self::version(),
							'categories' => self::count_categories(),
							'kinds'      => self::kinds(),
						]
					);
				},
			]
		);

		register_rest_route(
			ZDZ_REST_NS,
			'/item-engine/classify',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $read,
				'args'                => [ 'text' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ],
				'callback'            => static function ( $req ) {
					$text = (string) $req->get_param( 'text' );
					$item = self::match( $text );
					return rest_ensure_response(
						[
							'kind' => $item ? $item['id'] : null,
							'item' => $item,
						]
					);
				},
			]
		);
	}

	// ───────────────────────────────────────────────────────── OPTIONAL sample set

	/**
	 * A small, deliberately fictional demo catalog. It is NEVER auto-seeded — it is
	 * returned here for an explicit, confirmed admin action to apply. It names no
	 * real product, person, place or company: every entry is an obvious placeholder,
	 * chosen only to exercise both types, the subtype scopes, the counts contract,
	 * and every pricing method.
	 *
	 * @return array{subtypes:array,schemes:array,items:array}
	 */
	public static function sample_pack() {
		return [
			'subtypes' => [
				[ 'slug' => 'sample-unit', 'label' => __( 'Sample Unit', 'zorderz' ), 'scope' => 'global', 'type' => 'product', 'match_priority' => 60 ],
				[ 'slug' => 'sample-part', 'label' => __( 'Sample Part', 'zorderz' ), 'scope' => 'global', 'type' => 'product', 'match_priority' => 80 ],
				[ 'slug' => 'sample-visit', 'label' => __( 'Sample Visit', 'zorderz' ), 'scope' => 'global', 'type' => 'service', 'match_priority' => 40 ],
				[ 'slug' => 'sample-labor', 'label' => __( 'Sample Labor', 'zorderz' ), 'scope' => 'global', 'type' => 'service', 'match_priority' => 30 ],
			],
			'schemes'  => [
				[ 'id' => 'sample-flat', 'name' => __( 'Sample Flat Rate', 'zorderz' ), 'method' => 'flat', 'params' => [ 'amount' => 100 ], 'currency' => '' ],
				[ 'id' => 'sample-per-unit', 'name' => __( 'Sample Per-Unit', 'zorderz' ), 'method' => 'per_unit', 'params' => [ 'rate' => 25, 'min' => 25, 'max' => 40 ] ],
				[ 'id' => 'sample-per-hour', 'name' => __( 'Sample Hourly', 'zorderz' ), 'method' => 'per_hour', 'params' => [ 'rate' => 90 ] ],
				[ 'id' => 'sample-per-area', 'name' => __( 'Sample Per-Area', 'zorderz' ), 'method' => 'per_area', 'params' => [ 'rate' => 3, 'area_basis' => 'sq_ft', 'min_charge' => 50 ] ],
				[ 'id' => 'sample-per-visit', 'name' => __( 'Sample Per-Visit', 'zorderz' ), 'method' => 'per_visit', 'params' => [ 'rate' => 75, 'is_minimum' => true ] ],
				[ 'id' => 'sample-tiered', 'name' => __( 'Sample Tiered', 'zorderz' ), 'method' => 'tiered', 'params' => [ 'axis' => 'qty', 'tiers' => [ [ 'up_to' => 5, 'amount' => 30 ], [ 'up_to' => 20, 'amount' => 25 ], [ 'up_to' => null, 'amount' => 20 ] ] ] ],
				[ 'id' => 'sample-formula', 'name' => __( 'Sample Formula (cost x markup)', 'zorderz' ), 'method' => 'formula', 'expression' => 'cost * markup', 'params' => [ 'markup' => 2 ] ],
				[ 'id' => 'sample-quote', 'name' => __( 'Sample Quote Only', 'zorderz' ), 'method' => 'quote_only', 'params' => [] ],
			],
			'items'    => [
				[
					'id' => 'sample-standard-unit', 'type' => 'product', 'subtype' => 'sample-unit',
					'display_name' => __( 'Sample Standard Unit', 'zorderz' ),
					'aliases' => [ 'standard unit', 'std unit' ], 'sellable' => 'standalone',
					'unit_noun_singular' => 'unit', 'unit_noun_plural' => 'units',
					'countable' => true, 'match_priority' => 60, 'pricing_scheme_id' => 'sample-per-unit',
					'attributes' => [ 'brand' => '', 'bench_payable' => false ],
				],
				[
					'id' => 'sample-premium-unit', 'type' => 'product', 'subtype' => 'sample-unit',
					'display_name' => __( 'Sample Premium Unit', 'zorderz' ),
					'aliases' => [ 'premium unit' ], 'sellable' => 'standalone',
					'unit_noun_singular' => 'unit', 'unit_noun_plural' => 'units',
					'countable' => true, 'match_priority' => 65, 'pricing_scheme_id' => 'sample-formula',
					'attributes' => [ 'cost' => 40 ],
				],
				[
					'id' => 'sample-component-part', 'type' => 'product', 'subtype' => 'sample-part',
					'display_name' => __( 'Sample Component Part', 'zorderz' ),
					'aliases' => [ 'component part', 'sample part' ], 'sellable' => 'component',
					'unit_noun_singular' => 'part', 'unit_noun_plural' => 'parts',
					'countable' => false, 'match_priority' => 80, 'pricing_scheme_id' => 'sample-flat',
				],
				[
					'id' => 'sample-service-visit', 'type' => 'service', 'subtype' => 'sample-visit',
					'display_name' => __( 'Sample Service Visit', 'zorderz' ),
					'aliases' => [ 'service visit', 'service call' ], 'sellable' => 'standalone',
					'unit_noun_singular' => 'visit', 'unit_noun_plural' => 'visits',
					'countable' => true, 'match_priority' => 40, 'pricing_scheme_id' => 'sample-per-visit',
				],
				[
					'id' => 'sample-hourly-labor', 'type' => 'service', 'subtype' => 'sample-labor',
					'display_name' => __( 'Sample Hourly Labor', 'zorderz' ),
					'aliases' => [ 'hourly labor', 'labor' ], 'sellable' => 'standalone',
					'unit_noun_singular' => 'hour', 'unit_noun_plural' => 'hours',
					'countable' => false, 'match_priority' => 30, 'pricing_scheme_id' => 'sample-per-hour',
					'consumes' => [ [ 'item_id' => 'sample-component-part', 'qty_per_unit' => 1, 'uom' => 'each' ] ],
				],
			],
		];
	}

	/**
	 * Apply the sample set. Refuses unless $confirm === true, so it can never be
	 * triggered by activation or an accidental call.
	 *
	 * @return array{items:int,schemes:int,subtypes:int,errors:array}|WP_Error
	 */
	public static function apply_sample_pack( $confirm = false ) {
		if ( true !== $confirm ) {
			return new WP_Error( 'zdz_sample_unconfirmed', __( 'The sample set is not applied without explicit confirmation.', 'zorderz' ) );
		}
		$pack = self::sample_pack();
		$done = [ 'items' => 0, 'schemes' => 0, 'subtypes' => 0, 'errors' => [] ];
		foreach ( $pack['subtypes'] as $s ) {
			$r = self::ensure_subtype( $s['slug'], $s['label'], $s['scope'], $s['type'], $s['match_priority'] );
			if ( is_wp_error( $r ) ) {
				$done['errors'][] = $r->get_error_message();
			} else {
				$done['subtypes']++;
			}
		}
		$applied = self::approve_proposal( $pack['items'], $pack['schemes'] );
		$done['items']   = $applied['items'];
		$done['schemes'] = $applied['schemes'];
		$done['errors']  = array_merge( $done['errors'], $applied['errors'] );
		return $done;
	}

	// ───────────────────────────────────────────────────────── small helpers

	/** A stable slug id — lowercase, dashed, safe for a varchar PK. */
	public static function slug( $v ) {
		$v = (string) $v;
		if ( '' === trim( $v ) ) {
			return '';
		}
		$v = sanitize_title( $v );
		return substr( $v, 0, 80 );
	}

	private static function json_list( $raw ) {
		$d = json_decode( (string) $raw, true );
		return is_array( $d ) ? array_values( $d ) : [];
	}

	private static function json_map( $raw ) {
		$d = json_decode( (string) $raw, true );
		return is_array( $d ) ? $d : [];
	}

	/** Normalise an alias list into a clean array of strings or {value,match_mode}. */
	private static function clean_alias_list( $list ) {
		if ( is_string( $list ) ) {
			$list = preg_split( '/\r\n|\r|\n/', $list );
		}
		if ( ! is_array( $list ) ) {
			return [];
		}
		$out = [];
		foreach ( $list as $a ) {
			if ( is_array( $a ) ) {
				$val = trim( (string) ( $a['value'] ?? '' ) );
				if ( '' === $val ) {
					continue;
				}
				$entry = [ 'value' => sanitize_text_field( $val ) ];
				if ( ! empty( $a['match_mode'] ) && in_array( $a['match_mode'], [ 'substring', 'word_boundary', 'exact' ], true ) ) {
					$entry['match_mode'] = $a['match_mode'];
				}
				$out[] = $entry;
			} else {
				$val = trim( (string) $a );
				if ( '' !== $val ) {
					$out[] = sanitize_text_field( $val );
				}
			}
		}
		return $out;
	}

	/** Keep only numeric members of an array (for feeding the formula evaluator). */
	private static function numeric_only( array $in ) {
		$out = [];
		foreach ( $in as $k => $v ) {
			if ( is_numeric( $v ) ) {
				$out[ $k ] = (float) $v;
			}
		}
		return $out;
	}

	private static function money( $n ) {
		$sign = (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '' );
		return $sign . number_format( (float) $n, 2 );
	}

	private static function sanitize_explain( $expr ) {
		return 'formula: ' . preg_replace( '/[^a-z0-9_\.\+\-\*\/\(\)\s]/i', '', (string) $expr );
	}
}

ZDZ_Item_Engine::init();
