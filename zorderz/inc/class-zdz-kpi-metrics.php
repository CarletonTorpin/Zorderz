<?php
/**
 * KPI Metrics — the home screen's tiles, each read from the app that owns its number.
 *
 * Endpoint: GET /zorderz/v1/kpi-metrics (optional ?force_refresh=1). Each role that has a tile set gets its own set
 * (set_for()); every other role is refused, and the shared device never. Company revenue is shown only to people with
 * view_company_revenue, and a last redaction pass (filter_financial_for_user()) takes out any money a revenue-denied
 * person could still be sent.
 *
 * No tile calls the billing system or the CRM inside a person's request. Each is read from its owner through the verb
 * registry (ZDZ_Capabilities::invoke, as the signed-in person):
 *
 *   set       tile              owner (verb)                                   age shown
 *   --------  ----------------  ---------------------------------------------  ----------------------------------
 *   company   ytd_revenue       ledger.home_figures (Analytics)                the ledger's invoice sync
 *             mtd_revenue       ledger.home_figures                            the ledger's invoice sync
 *             estimates_mtd     ledger.home_figures                            the ledger's estimate sync
 *             surveys_month     survey.counts (Surveys)                        when Surveys' table was read
 *             google_reviews    ZDZ_Core_Google_Business once connected, else the number typed in Zorderz → Settings
 *             website_reviews   ZDZ_Core_ReviewBridge::count() (+ survey.counts underneath)   when the website was asked
 *             new_leads         ledger.home_figures                            the ledger's lead sync
 *             leads_contacted   held: no number is sent                        —
 *             leads_won         ledger.home_figures                            the ledger's lead sync
 *   sales     my_estimates, my_invoices (ledger.home_figures, the person's own), my_leads (lead.counts),
 *             my_installed (jobs.installed_counts). Counts only: no money anywhere in this set.
 *   shop      shop_prep (prep.queue_counts), then up to three item tiles the business names (shop_items()), from the
 *             Commission app's items.sold_counts. Counts only.
 *   operator  op_to_call, op_talked, op_attention, op_reviews: all from Surveys' survey.counts (its `queue` too).
 *   tech      tech_hours (hours.lookup) first, then three of TECH_POOL in the person's own order of use: jobs.my_next_counts
 *             (Jobs), schedule.my_counts (Scheduler), media.my_counts (Media), messaging.unread_counts (Messages).
 *
 * Every tile carries its definition, its source, the DATA's time (never the response's), the interval after which it
 * is old (amber), and, where the person may open the chat, the question that asks it on the same definition and the
 * same month in the site's time zone. A verb that is missing, refused or failing makes THAT tile say so, with the
 * person's last good value for the same month and its age; the other tiles carry on. A failure is never "0".
 *
 * Quick load: every source is a local table or a cached count, so a rebuild is a few queries. Each person's answer is
 * cached CACHE_TTL seconds (the verbs are shaped by who asks, so the cache is per person); no cron (the registry
 * answers only for a signed-in person). flush_cache() starts a new generation.
 *
 * What is the business's: which of a set's tiles each role sees and in what order (chosen(), option zdz_kpi_tile_sets /
 * filter zdz_kpi_tile_sets, only from that set's own Core tiles), which item counts the shop's tiles show and what they
 * are called (shop_items(), option zdz_kpi_shop_items / filter zdz_kpi_shop_items), and how old a source may be before
 * it is amber (stale_after(), filter zdz_kpi_stale_after). An Identity Pack writes the first two (rules.yml
 * home_tiles.sets and home_tiles.shop_items, pack_consumer()). Which roles get which set, the tiles each set may hold,
 * the tile contract and the failure rules are Core.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_KPI_Metrics {

	/** The answer's shape (the page reads `tiles` from 2; 1 was a flat key => {value} map). */
	const SHAPE = 2;

	/** Each person's answer is kept this long (seconds). The sources are local tables, so short is cheap. */
	const CACHE_TTL = 120;

	/** An answer with a tile still being counted is kept this briefly, so the count shows once it is made. */
	const COUNTING_TTL = 15;

	/** The cache generation (flush_cache() moves it on). */
	const GEN_OPTION = 'zdz_kpi_metrics_gen';

	/** Each person's last good value per tile (user meta), for a tile whose owner could not answer just now. */
	const LAST_GOOD_META = 'zdz_kpi_last_good_v2';

	/** The business's shop item tiles (see shop_items()). */
	const SHOP_ITEMS_OPTION = 'zdz_kpi_shop_items';

	/** The business's choice of tiles per set (see chosen()). */
	const TILE_SETS_OPTION = 'zdz_kpi_tile_sets';

	/** How many item tiles the shop's set may carry (four tiles in all, as every set). */
	const SHOP_ITEM_SLOTS = 3;

	/** How many catalog items one count of a shop tile may add together. */
	const SHOP_COUNT_IDS = 8;

	/**
	 * How old each kind of source may be before its tile is amber (seconds). Core defaults, each tied to how often its
	 * owner refreshes; a business may change them on the zdz_kpi_stale_after filter (stale_after()).
	 */
	const STALE_DEFAULTS = array(
		'ledger' => 7200,          // the Analytics ledger's hourly copies
		'bridge' => 7200,          // the website's review count, asked hourly
		'google' => 30 * 86400,    // a typed Google total: time to look again
		'calls'  => 2 * 86400,     // call notes synced into Surveys from the CRM
		'items'  => 7200,          // the Commission app's item counts
		'prep'   => 3600,          // Prep's own read of its cut queue
	);

	/** The company's tiles, in the home screen's order. */
	const TILES = array( 'ytd_revenue', 'mtd_revenue', 'estimates_mtd', 'surveys_month', 'google_reviews', 'website_reviews', 'new_leads', 'leads_contacted', 'leads_won' );

	/**
	 * A tile set per role. `company` is TILES (owners and admins); `sales` the salesperson's four (counts only); `shop`
	 * Prep's queue and the business's item tiles; `operator` the call list and this month's surveys; `tech` My hours
	 * then three of TECH_POOL. Every other role has no set: the endpoint refuses it.
	 */
	const SETS = array(
		'company'  => self::TILES,
		'sales'    => array( 'my_estimates', 'my_invoices', 'my_leads', 'my_installed' ),
		'shop'     => array( 'shop_prep', 'shop_item_1', 'shop_item_2', 'shop_item_3' ),
		'operator' => array( 'op_to_call', 'op_talked', 'op_attention', 'op_reviews' ),
		'tech'     => array( 'tech_hours', 'tech_jobs', 'tech_schedule', 'tech_media', 'tech_messages' ),
	);

	/**
	 * The field tech's pool after My hours. Each tile asks its own app through a count verb that app publishes. `apps`
	 * are the app ids whose opens rank the tile: jobs are kept in Jobs, photos are taken in Camera and kept in Media.
	 * Listed in the role's starting order, which breaks ties.
	 */
	const TECH_POOL = array(
		'tech_jobs'     => array( 'verb' => 'jobs.my_next_counts', 'apps' => array( 'jobs' ) ),
		'tech_schedule' => array( 'verb' => 'schedule.my_counts', 'apps' => array( 'scheduler' ) ),
		'tech_media'    => array( 'verb' => 'media.my_counts', 'apps' => array( 'zdz-camera', 'zdz-media', 'zdz-media-all' ) ),
		'tech_messages' => array( 'verb' => 'messaging.unread_counts', 'apps' => array( 'internal-messaging' ) ),
	);

	/** How many of TECH_POOL the tech gets after My hours (four tiles in all, as every set). */
	const TECH_SLOTS = 3;

	/** Prep's own count of its cut queue. */
	const PREP_VERB = 'prep.queue_counts';

	/**
	 * Every verb a tile asks, and the app that answers it. A verb no app registers makes its tile say so ("does not
	 * answer this here yet"), never 0.
	 */
	const VERBS = array(
		'ledger.home_figures'     => 'Analytics',
		'survey.counts'           => 'Surveys',
		'lead.counts'             => 'Leads',
		'jobs.installed_counts'   => 'Jobs',
		'jobs.my_next_counts'     => 'Jobs',
		'prep.queue_counts'       => 'Prep',
		'items.sold_counts'       => 'Commission',
		'hours.lookup'            => 'Hours',
		'schedule.my_counts'      => 'Scheduler',
		'media.my_counts'         => 'Media',
		'messaging.unread_counts' => 'Messages',
	);

	/** Injected in tests: "now" as a unix time. */
	public static $now = null;

	/** The shared 15-minute blob of the first endpoint shape. Deleted by flush_cache(); never read. */
	const LEGACY_CACHE_KEY = 'zdz_kpi_metrics_v1';

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  REST                                                              */
	/* ------------------------------------------------------------------ */

	public function register_routes(): void {
		register_rest_route( ZDZ_REST_NS, '/kpi-metrics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_kpi_metrics' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'force_refresh' => array(
					'default'           => '0',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	public function check_permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// A role with a tile set; never the shared device, whatever its first role.
		return '' !== self::set_for( (int) get_current_user_id() );
	}

	/**
	 * Which tile set this person gets: 'company', 'sales', 'shop', 'operator', 'tech', or '' (none: refused). By the
	 * person's first role, as the page draws it; the shared device never. PURE apart from the user lookup.
	 */
	public static function set_for( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return '';
		}
		$user = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		$role = ( $user && ! empty( $user->roles ) ) ? (string) array_values( (array) $user->roles )[0] : '';
		if ( '' === $role || 'zdz_general' === $role ) {
			return '';
		}
		$admin = class_exists( 'ZDZ_User_Roles' )
			? ZDZ_User_Roles::is_admin_role( $role )
			: in_array( $role, array( 'administrator', 'zdz_owner', 'zdz_admin' ), true );
		if ( $admin ) {
			return 'company';
		}
		$by_role = array(
			'zdz_sales'    => 'sales',
			'zdz_operator' => 'operator',
			'zdz_tech'     => 'tech',
			'zdz_mfg'      => 'shop',
		);
		return $by_role[ $role ] ?? '';
	}

	public function handle_kpi_metrics( WP_REST_Request $request ): WP_REST_Response {
		$uid   = (int) get_current_user_id();
		$force = '1' === (string) $request->get_param( 'force_refresh' );
		$key   = self::cache_key( $uid );
		if ( ! $force ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) && self::SHAPE === (int) ( $hit['shape'] ?? 0 ) ) {
				$hit['tiles']  = $this->filter_financial_for_user( (array) ( $hit['tiles'] ?? array() ), $uid );
				$hit['cached'] = true;
				return rest_ensure_response( $hit );
			}
		}
		$out = self::build( $uid );
		set_transient( $key, $out, self::has_counting( $out ) ? self::COUNTING_TTL : self::CACHE_TTL );
		$out['tiles']  = $this->filter_financial_for_user( (array) ( $out['tiles'] ?? array() ), $uid );
		$out['cached'] = false;
		return rest_ensure_response( $out );
	}

	/**
	 * Per person, per generation, per month (site time) and per tile set: two people never share an answer, a flush
	 * starts fresh for everyone, an answer built at 23:59 on the last day is never served as the next month's, and a
	 * changed role never gets the old role's answer (an admin made a salesperson must not see the company's revenue
	 * for the cache's two minutes).
	 */
	public static function cache_key( int $uid ): string {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$ym = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz )->format( 'Ym' );
		return 'zdz_kpi2_' . $uid . '_' . (int) get_option( self::GEN_OPTION, 1 ) . '_' . $ym . '_' . ( self::set_for( $uid ) ?: 'none' );
	}

	/**
	 * Flush the KPI cache. Call after major data changes. Starts a new generation, so every person's cached answer is
	 * left behind at once (they are per person and cannot be listed), and deletes the first shape's shared blob.
	 */
	public static function flush_cache(): void {
		update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 1 ) + 1, false );
		delete_transient( self::LEGACY_CACHE_KEY );
	}

	/* ------------------------------------------------------------------ */
	/*  Money never reaches a revenue-denied person                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Take money out of the tiles for a person who lacks view_company_revenue: a second layer under the tiles' own
	 * rule (revenue_tile() already answers `not_shown`), so a tile added later that carries money is caught even if
	 * nobody remembered to gate it. Such a tile is replaced by a `not_shown` tile with no figure. Fails closed when the
	 * permission layer is missing.
	 *
	 * @param array $tiles   key => tile (or key => {value} in the first shape).
	 * @param int   $user_id The person.
	 * @return array
	 */
	private function filter_financial_for_user( array $tiles, int $user_id ): array {
		$can_revenue = class_exists( 'ZDZ_Data_Permissions' )
			? ZDZ_Data_Permissions::can( $user_id, 'view_company_revenue' )
			: false;
		if ( $can_revenue ) {
			return $tiles;
		}
		foreach ( array_keys( $tiles ) as $key ) {
			$t = $tiles[ $key ];
			if ( is_array( $t ) && 'not_shown' === ( $t['state'] ?? '' ) && null === ( $t['value'] ?? null ) ) {
				continue;
			}
			if ( self::is_financial_metric( (string) $key, $t ) ) {
				if ( is_array( $t ) && isset( $t['state'] ) ) {
					$tiles[ $key ] = self::tile( (string) ( $t['label'] ?? '' ), 'not_shown', array( 'message' => __( 'Company revenue is shown to people with the revenue right only.', 'zorderz' ) ) );
				} else {
					unset( $tiles[ $key ] );
				}
			}
		}
		return $tiles;
	}

	/**
	 * True if a KPI metric or tile is monetary and must be withheld from a revenue-denied person. PURE (no WordPress)
	 * so it is unit-testable and cannot be quietly weakened without a failing test. Any one signal is enough, erring
	 * toward withholding:
	 *   1. an explicit flag: $data['financial'] === true;
	 *   2. the displayed value (`value` or `display`) or a sheet row carries a currency sign ($, EUR, GBP, JPY), or
	 *      the tile carries a collected / owed / received amount;
	 *   3. the key names a money concept (revenue, receivable, outstanding, profit, ...).
	 *
	 * @param string $key  Metric key.
	 * @param mixed  $data Metric or tile descriptor.
	 * @return bool
	 */
	public static function is_financial_metric( string $key, $data ): bool {
		$sigil = '/[$\x{20AC}\x{00A3}\x{00A5}]/u';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['financial'] ) ) {
				return true;
			}
			foreach ( array( 'value', 'display', 'detail' ) as $f ) {
				if ( isset( $data[ $f ] ) && is_string( $data[ $f ] ) && preg_match( $sigil, $data[ $f ] ) ) {
					return true;
				}
			}
			foreach ( array( 'collected', 'owed', 'received' ) as $f ) {
				if ( isset( $data[ $f ] ) && null !== $data[ $f ] ) {
					return true;
				}
			}
			foreach ( (array) ( $data['parts'] ?? array() ) as $p ) {
				if ( is_array( $p ) && isset( $p['display'] ) && is_string( $p['display'] ) && preg_match( $sigil, $p['display'] ) ) {
					return true;
				}
			}
		}
		return (bool) preg_match(
			'/(revenue|receivable|_ar$|outstanding|\bmrr\b|\barr\b|turnover|takings|gross_profit|net_profit|sales_amount|income)/i',
			$key
		);
	}

	/* ------------------------------------------------------------------ */
	/*  The answer                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Every tile for this person. Public for the tests; the REST handler adds the cache around it.
	 * Shape: {success, shape, set, read_at, month, month_label, tz, tiles: {key => tile}} (and `day`, `order` for the
	 * tech's set) — see tile().
	 */
	public static function build( int $uid ): array {
		$now   = self::now();
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$month = $local->format( 'Y-m' );
		$label = $local->format( 'F Y' );
		$year  = $local->format( 'Y' );
		$end   = $local->format( 'Y-m-t' );   // the month's last day, for "1 January to 30 June"

		$set  = self::set_for( $uid );
		$head = array(
			'success'     => '' !== $set,
			'shape'       => self::SHAPE,
			'set'         => $set,
			'read_at'     => $now,
			'month'       => $month,
			'month_label' => $label,
			'tz'          => $tz->getName(),
		);
		if ( '' === $set ) {
			// The REST route refuses these people first; a direct call gets no tiles, never the company's.
			return $head + array( 'tiles' => array() );
		}
		$ctx = array( 'month' => $month, 'label' => $label, 'year' => $year, 'end' => $end, 'zone' => self::zone_words( $tz ) );

		// `order`: the cards to draw, in order: the tiles the business chose for this set (chosen()), and for the tech's
		// set My hours then the pool in the person's own order of use.
		$want = self::chosen( $set );
		if ( 'tech' === $set ) {
			$tiles = self::tech_tiles( self::tech_ranked( $uid ), $local, $want );
			return $head + array(
				'day'   => $local->format( 'Y-m-d' ),   // the page shows a kept copy only on its own day
				'order' => array_keys( $tiles ),
				'tiles' => self::with_last_good( $uid, $tiles, $month ),
			);
		}
		if ( 'operator' === $set ) {
			$tiles = self::pick( self::operator_tiles( $ctx ), $want );
		} elseif ( 'shop' === $set ) {
			// Prep's queue, then the item tiles the business configured, as far as the business chose them.
			$tiles = self::pick( self::shop_tiles( $ctx ), $want );
		} elseif ( 'sales' === $set ) {
			$tiles = self::pick( self::sales_tiles( $ctx ), $want );
		} else {
			$tiles = self::pick( self::company_tiles( $uid, $ctx, $want ), $want );
		}
		return $head + array( 'order' => array_keys( $tiles ), 'tiles' => self::with_last_good( $uid, $tiles, $month ) );
	}

	/** The company's tiles. A source none of the chosen tiles reads is not asked. */
	private static function company_tiles( int $uid, array $ctx, array $want ): array {
		$month       = $ctx['month'];
		$can_revenue = class_exists( 'ZDZ_Data_Permissions' ) && ZDZ_Data_Permissions::can( $uid, 'view_company_revenue' );
		$needs       = function ( array $keys ) use ( $want ) {
			return (bool) array_intersect( $keys, $want );
		};

		$ledger  = $needs( array( 'ytd_revenue', 'mtd_revenue', 'estimates_mtd', 'new_leads', 'leads_won' ) ) ? self::ask( 'ledger.home_figures', array( 'month' => $month ) ) : array();
		$surveys = $needs( array( 'surveys_month', 'website_reviews' ) ) ? self::ask( 'survey.counts', array( 'month' => $month ) ) : array();
		$bridge  = $needs( array( 'website_reviews' ) ) ? self::bridge_count() : null;

		$build = array(
			'ytd_revenue'     => function () use ( $ledger, $can_revenue, $ctx ) { return self::revenue_tile( $ledger, 'revenue_ytd', $can_revenue, $ctx ); },
			'mtd_revenue'     => function () use ( $ledger, $can_revenue, $ctx ) { return self::revenue_tile( $ledger, 'revenue_mtd', $can_revenue, $ctx ); },
			'estimates_mtd'   => function () use ( $ledger, $ctx ) { return self::estimates_tile( $ledger, $ctx ); },
			'surveys_month'   => function () use ( $surveys, $ctx ) { return self::surveys_tile( $surveys, $ctx ); },
			'google_reviews'  => function () { return self::google_tile(); },
			'website_reviews' => function () use ( $bridge, $surveys ) { return self::website_tile( $bridge, $surveys ); },
			'new_leads'       => function () use ( $ledger, $ctx ) { return self::new_leads_tile( $ledger, $ctx ); },
			'leads_contacted' => function () { return self::contacted_tile(); },
			'leads_won'       => function () use ( $ledger, $ctx ) { return self::won_tile( $ledger, $ctx ); },
		);
		$tiles = array();
		foreach ( $want as $k ) {
			if ( isset( $build[ $k ] ) ) {
				$tiles[ $k ] = $build[ $k ]();
			}
		}
		return $tiles;
	}

	/* ------------------------------------------------------------------ */
	/*  Which tiles a set shows: the business's choice                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The tiles a set shows, in order: Core's set (SETS) unless the business chose otherwise in the option
	 * zdz_kpi_tile_sets (an Identity Pack writes it: rules.yml home_tiles.sets), then the zdz_kpi_tile_sets filter. A
	 * choice may only leave tiles out or put them in another order: only the set's own Core tiles are kept, so a role
	 * never gains a tile from another role's set. For the tech's set the order of the pool is still the person's own
	 * use; the choice decides which of the pool may be drawn and whether My hours is. PURE apart from the option and
	 * the filter.
	 *
	 * @return string[] tile keys
	 */
	public static function chosen( string $set ): array {
		if ( ! isset( self::SETS[ $set ] ) ) {
			return array();
		}
		$raw = function_exists( 'get_option' ) ? get_option( self::TILE_SETS_OPTION, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zdz_kpi_tile_sets', $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		$clean = self::clean_tile_sets( $raw );
		return $clean[ $set ] ?? self::SETS[ $set ];
	}

	/**
	 * Validate a choice of tiles: set => [tile key, …]. A set that is not Core's is dropped; a list that is not a list
	 * is dropped (the set keeps Core's tiles); a key that is not one of that set's Core tiles, or is repeated, is dropped.
	 * An empty list is kept: that role sees no tiles. PURE.
	 *
	 * @return array<string,string[]>
	 */
	public static function clean_tile_sets( array $raw ): array {
		$out = array();
		foreach ( $raw as $set => $keys ) {
			$set = (string) $set;
			if ( ! isset( self::SETS[ $set ] ) || ! is_array( $keys ) ) {
				continue;
			}
			$list = array();
			foreach ( $keys as $k ) {
				if ( is_string( $k ) && in_array( $k, self::SETS[ $set ], true ) && ! in_array( $k, $list, true ) ) {
					$list[] = $k;
				}
			}
			$out[ $set ] = $list;
		}
		return $out;
	}

	/** The tiles in $want, in $want's order. PURE. */
	private static function pick( array $tiles, array $want ): array {
		$out = array();
		foreach ( $want as $k ) {
			if ( isset( $tiles[ $k ] ) ) {
				$out[ $k ] = $tiles[ $k ];
			}
		}
		return $out;
	}

	/**
	 * One tile. `state`: ok | stale (the owner answered from data older than its interval) | error (the owner could not
	 * answer just now) | unavailable (no owner answers it here) | not_shown (not for this person) | held (not counted
	 * here) | empty (nothing entered yet) | counting (the owner is making its first count). `as_of` is the DATA's unix
	 * time; `as_of_kind` says what it is (synced | read | asked | entered | counted); `stale_after` is the interval in
	 * seconds (null: never old).
	 */
	private static function tile( string $label, string $state, array $more = array() ): array {
		return array_merge( array(
			'label'       => $label,
			'state'       => $state,
			'value'       => null,
			'display'     => '—',
			'detail'      => '',
			'definition'  => '',
			'source'      => '',
			'as_of'       => null,
			'as_of_kind'  => '',
			'stale_after' => null,
			'message'     => '',
			'ask'         => '',
			'open'        => '',
			'open_label'  => '',        // the sheet's button for `open` ("Open Surveys")
			'parts'       => array(),   // rows for the tile's sheet ({label, display}), e.g. billed / received / owed
		), $more );
	}

	/** How old a kind of source may be before its tile is amber (seconds; at least a minute). */
	public static function stale_after( string $kind ): int {
		$d = self::STALE_DEFAULTS[ $kind ] ?? 7200;
		$v = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_kpi_stale_after', $d, $kind ) : $d;
		return is_numeric( $v ) && (int) $v >= 60 ? (int) $v : $d;
	}

	/** The site's time zone in words, for the questions the tiles hand the chat: "site time, Europe/Lisbon". */
	private static function zone_words( DateTimeZone $tz ): string {
		return 'site time, ' . $tz->getName();
	}

	/* ── revenue, estimates, leads: Analytics' ledger.home_figures ── */

	private static function revenue_tile( array $r, string $fig, bool $can_revenue, array $c ): array {
		$ytd   = 'revenue_ytd' === $fig;
		$label = $ytd ? __( 'Revenue this year', 'zorderz' ) : __( 'Revenue this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the billing system', 'zorderz' ),
			'ask'    => $ytd
				? 'How much did we invoice from 1 January to ' . self::day_words( $c['end'] ) . ' (' . $c['zone'] . ')? Count invoices dated in that window at their full amount, paid or not, archived ones included, drafts and deleted left out. Also say how many invoices, how much of those is still owed, and how much we received in payments dated from 1 January to ' . self::day_words( $c['end'] ) . ', on any invoice.'
				: 'How much did we invoice in ' . $c['label'] . ' (' . $c['zone'] . ')? Count invoices dated in that month at their full amount, paid or not, archived ones included, drafts and deleted left out. Also say how many invoices, how much of those is still owed, and how much we received in payments dated in ' . $c['label'] . ', on any invoice.',
		);
		if ( ! $can_revenue ) {
			// The theme's own rule, whatever the owner would answer.
			return self::tile( $label, 'not_shown', array( 'message' => __( 'Company revenue is shown to people with the revenue right only.', 'zorderz' ) ) );
		}
		$bad = self::verb_problem( $r, 'Analytics', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		if ( 'company' !== ( $r['shaped_for'] ?? '' ) ) {
			return self::tile( $label, 'not_shown', array( 'message' => (string) ( $r['definitions']['not_shown'] ?? __( 'Company revenue is not shown on this account.', 'zorderz' ) ) ) );
		}
		$def = (string) ( $r['definitions'][ $fig ] ?? '' );
		if ( $ytd && '' !== $def && 0 === strpos( $def, 'The same' ) ) {
			// A year sentence that starts "The same from 1 January …" reads badly alone: said in full, same rule.
			$def = 'Invoiced from 1 January to the end of this month: invoices dated in that window at their full amount, paid or not; drafts and deleted left out, archived included. Also how many invoices, and of those invoices how much has been collected (paid) and how much is still owed. Received: every payment dated from 1 January to the end of this month, on any invoice, including an earlier year\'s invoice paid this year; credits applied as payments are counted and also shown apart.';
		}
		$base['definition'] = $def;
		if ( isset( $r['errors']['invoices'] ) || ! isset( $r['company'][ $fig ]['value'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['invoices'] ?? __( 'The invoices could not be read just now.', 'zorderz' ) ) ) );
		}
		$v = (float) $r['company'][ $fig ]['value'];
		$n = (int) ( $r['company'][ $fig ]['invoices'] ?? 0 );
		// The big number is the total billed. Under it: what was RECEIVED (payments dated in the month, or from
		// 1 January, on any invoice) when the ledger sends `received`; else what has been collected on these invoices
		// when it sends collected / owed; else the invoice count. Nothing is invented for a figure not sent. The tile
		// is as old as the older of the two copies it reads.
		$fig_r  = (array) $r['company'][ $fig ];
		$has    = isset( $fig_r['collected'], $fig_r['owed'] ) && is_numeric( $fig_r['collected'] ) && is_numeric( $fig_r['owed'] );
		$rcv    = (array) ( $fig_r['received'] ?? array() );
		$has_r  = isset( $rcv['value'] ) && is_numeric( $rcv['value'] );
		$r_word = $ytd ? __( 'Received since 1 January', 'zorderz' ) : __( 'Received this month', 'zorderz' );
		$parts  = array( array( 'label' => __( 'Billed', 'zorderz' ), 'display' => self::money( $v ) ) );
		if ( $has_r ) {
			$parts[] = array( 'label' => $r_word, 'display' => self::money( (float) $rcv['value'] ) );
			$cr      = (array) ( $rcv['credits'] ?? array() );
			if ( (int) ( $cr['count'] ?? 0 ) > 0 ) {
				// Credits applied as payments are counted in received, and shown apart.
				$parts[] = array( 'label' => __( 'Of that, credits applied', 'zorderz' ), 'display' => self::money( (float) ( $cr['amount'] ?? 0 ) ) . ' (' . (int) $cr['count'] . ')' );
			}
		} elseif ( isset( $r['errors']['payments'] ) ) {
			$parts[] = array( 'label' => $r_word, 'display' => __( 'not read just now', 'zorderz' ) );
		}
		if ( $has ) {
			if ( ! $has_r ) {
				$parts[] = array( 'label' => __( 'Collected on these invoices', 'zorderz' ), 'display' => self::money( (float) $fig_r['collected'] ) );
			}
			$parts[] = array( 'label' => __( 'Still owed on these invoices', 'zorderz' ), 'display' => self::money( (float) $fig_r['owed'] ) );
		}
		$parts[] = array( 'label' => __( 'Invoices', 'zorderz' ), 'display' => (string) $n );
		if ( $has_r ) {
			$detail = self::format_currency( (float) $rcv['value'] ) . ' ' . __( 'received', 'zorderz' );
		} elseif ( $has ) {
			$detail = self::format_currency( (float) $fig_r['collected'] ) . ' ' . __( 'collected', 'zorderz' );
		} else {
			$detail = self::plural( $n, 'invoice' );
		}
		$state = self::source_state( $r, 'invoices' );
		$age   = self::source_age( $r, 'invoices' );
		if ( $has_r ) {
			if ( 'stale' === self::source_state( $r, 'payments' ) ) {
				$state = 'stale';
			}
			$pa = self::source_age( $r, 'payments' );
			if ( null === $pa['as_of'] || ( null !== $age['as_of'] && $pa['as_of'] < $age['as_of'] ) ) {
				$age = $pa; // the older of the two copies
			}
			if ( null === $pa['as_of'] ) {
				$state = 'stale';
			}
		}
		return self::tile( $label, $state, $base + $age + array(
			'financial' => true,
			'value'     => $v,
			'display'   => self::format_currency( $v ),
			'detail'    => $detail,
			'collected' => $has ? round( (float) $fig_r['collected'], 2 ) : null,
			'owed'      => $has ? round( (float) $fig_r['owed'], 2 ) : null,
			'received'  => $has_r ? round( (float) $rcv['value'], 2 ) : null,
			'parts'     => $parts,
		) );
	}

	/* ── The salesperson's tiles. Counts only: no money anywhere in this set. ── */

	/** The salesperson's four tiles, each from its owner. */
	private static function sales_tiles( array $c ): array {
		$ledger    = self::ask( 'ledger.home_figures', array( 'month' => $c['month'] ) );
		$leads     = self::ask( 'lead.counts', array( 'month' => $c['month'] ) );
		$installed = self::ask( 'jobs.installed_counts', array( 'month' => $c['month'] ) );
		return array(
			'my_estimates' => self::my_estimates_tile( $ledger, $c ),
			'my_invoices'  => self::my_invoices_tile( $ledger, $c ),
			'my_leads'     => self::my_leads_tile( $leads ),
			'my_installed' => self::my_installed_tile( $installed, $c ),
		);
	}

	/** The ledger's `mine`, or a tile saying why there is none (a salesperson-shaped answer only: never the company's). */
	private static function mine_problem( array $r, string $label, array $base ) {
		$bad = self::verb_problem( $r, 'Analytics', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		if ( 'mine' !== ( $r['shaped_for'] ?? '' ) ) {
			// A salesperson with the company revenue right gets the company's answer: this set shows only their own.
			return self::tile( $label, 'not_shown', array( 'message' => __( 'Your own figures are not in this answer.', 'zorderz' ) ) );
		}
		if ( ! empty( $r['mine']['no_rep_code'] ) ) {
			return self::tile( $label, 'not_shown', array( 'message' => (string) ( $r['definitions']['no_rep_code'] ?? __( 'Your account has no salesperson code.', 'zorderz' ) ) ) );
		}
		return null;
	}

	private static function my_estimates_tile( array $r, array $c ): array {
		$label = __( 'Your estimates this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the billing system', 'zorderz' ),
			'ask'    => 'How many of my estimates are dated in ' . $c['label'] . ' (' . $c['zone'] . '), drafts and deleted left out, and how many of them were accepted or turned into an invoice? Counts only.',
		);
		$bad = self::mine_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$m = (array) $r['mine'];
		$base['definition'] = trim( (string) ( $r['definitions']['estimates'] ?? '' ) . ' Shown as accepted / written: ' . (string) ( $r['definitions']['estimates_accepted'] ?? '' ) );
		if ( isset( $r['errors']['estimates'] ) || ! isset( $m['estimates'], $m['estimates_accepted'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['estimates'] ?? __( 'Your estimates could not be read just now.', 'zorderz' ) ) ) );
		}
		$n     = (int) $m['estimates'];
		$a     = (int) $m['estimates_accepted'];
		$parts = array(
			array( 'label' => __( 'Written', 'zorderz' ), 'display' => (string) $n ),
			array( 'label' => __( 'Accepted or invoiced', 'zorderz' ), 'display' => (string) $a ),
		);
		if ( isset( $m['estimates_drafts'] ) ) {
			$parts[] = array( 'label' => __( 'Drafts (not counted)', 'zorderz' ), 'display' => (string) (int) $m['estimates_drafts'] );
		}
		return self::tile( $label, self::source_state( $r, 'estimates' ), $base + self::source_age( $r, 'estimates' ) + array(
			'value'   => $n,
			'display' => $a . ' / ' . $n,
			'detail'  => __( 'accepted', 'zorderz' ),   // accepted of written; the sheet has both; fits a phone card
			'parts'   => $parts,
		) );
	}

	private static function my_invoices_tile( array $r, array $c ): array {
		$label = __( 'Your invoices this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the billing system', 'zorderz' ),
			'ask'    => 'How many of my invoices are dated in ' . $c['label'] . ' (' . $c['zone'] . '), drafts and deleted left out, and how many from 1 January? Counts only.',
		);
		$bad = self::mine_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$m = (array) $r['mine'];
		$base['definition'] = (string) ( $r['definitions']['invoices_mtd'] ?? '' );
		if ( isset( $r['errors']['invoices'] ) || ! isset( $m['invoices_mtd'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['invoices'] ?? __( 'Your invoices could not be read just now.', 'zorderz' ) ) ) );
		}
		$n = (int) $m['invoices_mtd'];
		return self::tile( $label, self::source_state( $r, 'invoices' ), $base + self::source_age( $r, 'invoices' ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => isset( $m['invoices_ytd'] ) ? (int) $m['invoices_ytd'] . ' ' . __( 'this year', 'zorderz' ) : '',
		) );
	}

	private static function my_leads_tile( array $r ): array {
		$label = __( 'Leads to contact', 'zorderz' );
		$base  = array(
			'source'     => __( 'Leads', 'zorderz' ),
			'open'       => 'leads',
			'open_label' => __( 'Open Leads', 'zorderz' ),
			'ask'        => 'How many of my outreach leads from the Leads app still need contacting, and how many were assigned to me today?',
		);
		$bad = self::verb_problem( $r, 'Leads', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$m = (array) ( $r['mine_now'] ?? array() );
		$base['definition'] = trim( (string) ( $r['definitions']['mine_now.to_contact'] ?? '' ) . ' These are the outreach leads the Leads app hands you, not the CRM\'s new inbound leads.' );
		if ( ! isset( $m['to_contact'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Your leads could not be read just now.', 'zorderz' ) ) );
		}
		$n = (int) $m['to_contact'];
		$t = (int) ( $m['new_today'] ?? 0 );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $t > 0 ? $t . ' ' . __( 'new today', 'zorderz' ) : ( $n > 0 ? __( 'none new today', 'zorderz' ) : __( 'all caught up', 'zorderz' ) ),
		) );
	}

	private static function my_installed_tile( array $r, array $c ): array {
		$label = __( 'Jobs installed this month', 'zorderz' );
		$base  = array(
			'source'     => __( 'Jobs', 'zorderz' ),
			'open'       => 'jobs',
			'open_label' => __( 'Open Jobs', 'zorderz' ),
			'ask'        => 'Which of the jobs I sold were installed in ' . $c['label'] . ' (' . $c['zone'] . ')? Count a job marked installed on a day in the month, or, with no mark, one whose work was all finished, the last of it in the month.',
		);
		$bad = self::verb_problem( $r, 'Jobs', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$m = (array) ( $r['mine'] ?? array() );
		$base['definition'] = (string) ( $r['definitions']['installed'] ?? '' );
		if ( ! isset( $m['installed'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Jobs could not be read just now.', 'zorderz' ) ) );
		}
		$n     = (int) $m['installed'];
		$parts = array();
		if ( isset( $m['marked'] ) ) {
			// Only an owner that keeps an "installed" mark sends it; with none, every count is by finished work.
			$parts[] = array( 'label' => __( 'Marked installed', 'zorderz' ), 'display' => (string) (int) $m['marked'] );
		}
		$parts[] = array( 'label' => isset( $m['marked'] ) ? __( 'All work finished (not marked)', 'zorderz' ) : __( 'All work finished', 'zorderz' ), 'display' => (string) (int) ( $m['by_jobs'] ?? 0 ) );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => 1 === $n ? __( 'job you sold', 'zorderz' ) : __( 'jobs you sold', 'zorderz' ),
			'parts'   => $parts,
		) );
	}

	/* ── The shop's tiles. Counts only. No "Ask in chat": the chat would open Analytics, which a shop role does not
	 *   normally have and which does not hold Prep's queue or the Commission app's item counts; the sheet says what
	 *   each counts and where from instead. ── */

	/**
	 * The business's item tiles for the shop's set, from the Commission app's items.sold_counts: at most
	 * SHOP_ITEM_SLOTS, each `{label, counts: [count, …] (1 or 2), words: [label per count], detail}`. A count is one
	 * of the keys that app's line classifier sends (the catalog's item ids), or a list of up to SHOP_COUNT_IDS of them
	 * added together ("new" = three kinds of new item). Read from the option zdz_kpi_shop_items (an Identity Pack writes
	 * it: rules.yml home_tiles.shop_items), then the zdz_kpi_shop_items filter. Core ships none: a shop's set is Prep's
	 * queue alone until the business names what it counts. PURE apart from the option and the filter.
	 *
	 * @return array[] cleaned entries, in order
	 */
	public static function shop_items(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::SHOP_ITEMS_OPTION, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zdz_kpi_shop_items', $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		return self::clean_shop_items( $raw );
	}

	/** Validate shop item entries: unknown shapes are dropped, never guessed. PURE. */
	public static function clean_shop_items( array $raw ): array {
		$out = array();
		foreach ( $raw as $e ) {
			if ( count( $out ) >= self::SHOP_ITEM_SLOTS || ! is_array( $e ) ) {
				continue;
			}
			$label  = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $e['label'] ?? '' ) ) ) );
			$id_ok  = function ( $k ) {
				return is_string( $k ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,79}$/', $k );
			};
			$counts = array();
			$broken = false;
			foreach ( array_values( (array) ( $e['counts'] ?? array() ) ) as $k ) {
				// One item id, or a list of them added together; a list with anything else in it is not guessed at.
				$ids = is_array( $k ) ? array_values( array_unique( $k ) ) : array( $k );
				if ( ! $ids || count( $ids ) > self::SHOP_COUNT_IDS || count( array_filter( $ids, $id_ok ) ) !== count( $ids ) ) {
					$broken = true;
					continue;
				}
				$counts[] = is_array( $k ) ? $ids : $k;
			}
			if ( '' === $label || $broken || ! $counts || count( $counts ) > 2 ) {
				continue;
			}
			$words = array();
			foreach ( $counts as $i => $k ) {
				$w         = trim( strip_tags( (string) ( ( (array) ( $e['words'] ?? array() ) )[ $i ] ?? '' ) ) );
				$words[]   = '' !== $w ? mb_substr( $w, 0, 40 ) : ( is_array( $k ) ? implode( '+', $k ) : $k );
			}
			$detail = trim( strip_tags( (string) ( $e['detail'] ?? '' ) ) );
			$out[]  = array(
				'label'  => mb_substr( $label, 0, 40 ),
				'counts' => $counts,
				'words'  => $words,
				'detail' => '' !== $detail ? mb_substr( $detail, 0, 24 ) : ( 2 === count( $counts ) ? strtolower( $words[0] ) . '/' . strtolower( $words[1] ) : '' ),
			);
		}
		return $out;
	}

	private static function shop_tiles( array $c ): array {
		$tiles = array( 'shop_prep' => self::prep_tile( self::ask( self::PREP_VERB, array() ) ) );
		$items = self::shop_items();
		if ( $items ) {
			$counts = self::ask( 'items.sold_counts', array() );
			foreach ( $items as $i => $spec ) {
				$tiles[ 'shop_item_' . ( $i + 1 ) ] = self::items_tile( $counts, $spec );
			}
		}
		return $tiles;
	}

	private static function prep_tile( array $r ): array {
		$label = __( 'Prep jobs', 'zorderz' );
		$base  = array(
			'source'     => __( 'Prep', 'zorderz' ),
			'open'       => 'prep',
			'open_label' => __( 'Open Prep', 'zorderz' ),
		);
		$bad = self::verb_problem( $r, 'Prep', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = isset( $r['counts'] ) && is_array( $r['counts'] ) ? $r['counts'] : null;
		if ( null === $k || ! isset( $k['to_cut'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Prep\'s queue could not be read just now.', 'zorderz' ) ) );
		}
		$n = (int) $k['to_cut'];
		$w = (int) ( $k['this_week'] ?? 0 );
		$p = (int) ( $k['possible'] ?? 0 );
		$d = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = trim( (string) ( $d['to_cut'] ?? '' ) . ' ' . (string) ( $d['this_week'] ?? '' ) );
		return self::tile( $label, ! empty( $r['stale'] ) ? 'stale' : 'ok', $base + array(
			'value'       => $n,
			'display'     => (string) $n,
			'detail'      => 0 === $n ? __( 'nothing to cut', 'zorderz' ) : $w . ' ' . __( 'this week', 'zorderz' ),
			'parts'       => array(
				array( 'label' => __( 'Ready to cut', 'zorderz' ), 'display' => (string) $n ),
				array( 'label' => __( 'Installing within 7 days', 'zorderz' ), 'display' => (string) $w ),
				array( 'label' => __( 'Possible, not yet approved (not counted)', 'zorderz' ), 'display' => (string) $p ),
			),
			'as_of'       => isset( $r['read_at'] ) ? (int) $r['read_at'] : null,
			'as_of_kind'  => 'read',
			'stale_after' => self::stale_after( 'prep' ),
		) );
	}

	/** The Commission app's answer, or a tile saying why there is none (still being counted: never 0). */
	private static function items_problem( array $r, string $label, array $base ) {
		$bad = self::verb_problem( $r, 'Commission', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		if ( ! isset( $r['counts'] ) || ! is_array( $r['counts'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['error'] ?? __( 'Commission could not answer just now.', 'zorderz' ) ) ) );
		}
		return null;
	}

	/** One of the business's item tiles: "23 / 9" for two counts (the sheet names each), one number for one. */
	private static function items_tile( array $r, array $spec ): array {
		$label = (string) $spec['label'];
		$base  = array( 'source' => __( 'Commission', 'zorderz' ) );
		$bad   = self::items_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k     = (array) $r['counts'];
		$defs  = (array) ( $r['definitions'] ?? array() );
		$nums  = array();
		$parts = array();
		$def   = array( (string) ( $defs['items'] ?? '' ) );
		foreach ( $spec['counts'] as $i => $key ) {
			$n = 0;
			foreach ( (array) $key as $id ) {
				$n += (int) ( $k[ (string) $id ] ?? 0 );   // a list of items is added together
			}
			$nums[]  = $n;
			$parts[] = array( 'label' => (string) $spec['words'][ $i ], 'display' => (string) $n );
			if ( is_string( $key ) && isset( $defs[ $key ] ) ) {
				$def[] = $spec['words'][ $i ] . ': ' . (string) $defs[ $key ];
			}
		}
		$parts[] = array( 'label' => __( 'Invoices counted', 'zorderz' ), 'display' => (string) (int) ( $r['invoices'] ?? 0 ) );
		$base['definition'] = trim( implode( ' ', array_filter( $def ) ) );
		return self::tile( $label, ! empty( $r['stale'] ) ? 'stale' : 'ok', $base + array(
			'value'       => array_sum( $nums ),
			'display'     => implode( ' / ', $nums ),
			'detail'      => (string) $spec['detail'],
			'parts'       => $parts,
			'as_of'       => isset( $r['counted_at'] ) ? (int) $r['counted_at'] : null,
			'as_of_kind'  => 'counted',
			'stale_after' => self::stale_after( 'items' ),
		) );
	}

	/* ── The operator's tiles, from Surveys' survey.counts (the month) and its `queue` (the call list now). Counts
	 *   only. No "Ask in chat": the operator's role does not normally open Analytics. ── */

	private static function operator_tiles( array $c ): array {
		$r = self::ask( 'survey.counts', array( 'month' => $c['month'] ) );
		return array(
			'op_to_call'   => self::op_to_call_tile( $r ),
			'op_talked'    => self::op_talked_tile( $r ),
			'op_attention' => self::op_attention_tile( $r ),
			'op_reviews'   => self::op_reviews_tile( $r ),
		);
	}

	/**
	 * The call list now, or a tile saying why there is none. The call list is its own read in Surveys: it comes even
	 * when the month's counts failed (success false), so it is looked for first and those tiles still answer.
	 */
	private static function queue_problem( array $r, string $label, array $base ) {
		if ( isset( $r['queue'] ) && is_array( $r['queue'] ) && isset( $r['queue']['open'] ) ) {
			return null;
		}
		$bad = self::verb_problem( $r, 'Surveys', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		if ( isset( $r['queue_error'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) $r['queue_error'] ) );
		}
		return self::tile( $label, 'unavailable', $base + array( 'message' => __( 'Surveys does not answer this here yet (it may need updating).', 'zorderz' ) ) );
	}

	/** The call-list tiles' age: when call notes last reached Surveys from the CRM; amber after the `calls` interval. */
	private static function queue_age( array $r ): array {
		$at = $r['queue']['synced_at'] ?? null;
		return array(
			'as_of'       => null !== $at ? (int) $at : ( isset( $r['read_at'] ) ? (int) $r['read_at'] : self::now() ),
			'as_of_kind'  => null !== $at ? 'synced' : 'read',
			'stale_after' => null !== $at ? self::stale_after( 'calls' ) : null,
		);
	}

	private static function surveys_base(): array {
		return array( 'source' => __( 'Surveys', 'zorderz' ), 'open' => 'surveys', 'open_label' => __( 'Open Surveys', 'zorderz' ) );
	}

	private static function op_to_call_tile( array $r ): array {
		$label = __( 'Customers to call', 'zorderz' );
		$base  = self::surveys_base();
		$bad   = self::queue_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$q     = (array) $r['queue'];
		$first = (int) ( $q['first_call'] ?? 0 );
		$again = (int) ( $q['call_again'] ?? 0 );
		$n     = $first + $again;
		$d     = (array) ( $r['queue_definitions'] ?? array() );
		$base['definition'] = trim( (string) ( $d['open'] ?? '' ) . ' Counted here: ' . (string) ( $d['first_call'] ?? '' ) . ' And: ' . (string) ( $d['call_again'] ?? '' ) . ' ' . (string) ( $d['synced_at'] ?? '' ) );
		return self::tile( $label, 'ok', $base + self::queue_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => 0 === $n ? __( 'all called', 'zorderz' ) : ( $again > 0 ? $again . ' ' . __( 'to try again', 'zorderz' ) : __( 'first calls', 'zorderz' ) ),
			'parts'   => array(
				array( 'label' => __( 'First call (no note yet)', 'zorderz' ), 'display' => (string) $first ),
				array( 'label' => __( 'Call again (voicemail)', 'zorderz' ), 'display' => (string) $again ),
			),
		) );
	}

	private static function op_talked_tile( array $r ): array {
		$label = __( 'Talked with this month', 'zorderz' );
		$base  = self::surveys_base();
		$bad   = self::verb_problem( $r, 'Surveys', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['put_on_call_list'], $k['reached'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'The survey counts could not be read just now.', 'zorderz' ) ) );
		}
		$list = (int) $k['put_on_call_list'];
		$n    = (int) $k['reached'];
		$df   = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = trim( (string) ( $df['put_on_call_list'] ?? '' ) . ' Shown as reached / on the list: ' . (string) ( $df['reached'] ?? '' ) . ' Call notes reach Surveys when it syncs with the CRM.' );
		// Aged by THIS month's customers still open (their oldest look), never by the live call list, which can hold
		// older months. None open: every outcome is final, so the read is the data's time.
		$at = $r['calls_synced_at'] ?? null;
		return self::tile( $label, 'ok', $base + array(
			'as_of'       => null !== $at ? (int) $at : ( isset( $r['read_at'] ) ? (int) $r['read_at'] : self::now() ),
			'as_of_kind'  => null !== $at ? 'synced' : 'read',
			'stale_after' => null !== $at ? self::stale_after( 'calls' ) : null,
		) + array(
			'value'   => $n,
			'display' => $n . ' / ' . $list,   // reached of those put on this month's call list
			'detail'  => __( 'reached', 'zorderz' ),
			'parts'   => array(
				array( 'label' => __( 'On this month\'s call list', 'zorderz' ), 'display' => (string) $list ),
				array( 'label' => __( 'Reached', 'zorderz' ), 'display' => (string) $n ),
				array( 'label' => __( 'Voicemail', 'zorderz' ), 'display' => (string) (int) ( $k['not_reached'] ?? 0 ) ),
				array( 'label' => __( 'Note to read', 'zorderz' ), 'display' => (string) (int) ( $k['needs_attention'] ?? 0 ) ),
				array( 'label' => __( 'No note yet', 'zorderz' ), 'display' => (string) (int) ( $k['waiting'] ?? 0 ) ),
			),
		) );
	}

	private static function op_attention_tile( array $r ): array {
		$label = __( 'Needs a look', 'zorderz' );
		$base  = self::surveys_base();
		$bad   = self::queue_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		// Call notes to read, plus the follow-ups still with a salesperson (their task open). A follow-up whose task is
		// done is settled and not counted. Unknown (the follow-ups not read) is never 0.
		$q     = (array) $r['queue'];
		$na    = (int) ( $q['needs_attention'] ?? 0 );
		$fu    = isset( $q['follow_up'] ) ? (int) $q['follow_up'] : null;
		$tasks = $q['tasks_open'] ?? null;
		$n     = $na + (int) $fu;
		$d     = (array) ( $r['queue_definitions'] ?? array() );
		$nr    = __( 'not read just now', 'zorderz' );
		$base['definition'] = trim( (string) ( $d['needs_attention'] ?? '' ) . ' And: ' . (string) ( $d['follow_up'] ?? '' ) );
		return self::tile( $label, 'ok', $base + self::queue_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => null === $fu ? __( 'tasks not read', 'zorderz' ) : $fu . ' ' . __( 'with sales', 'zorderz' ),
			'parts'   => array(
				array( 'label' => __( 'Call notes to read', 'zorderz' ), 'display' => (string) $na ),
				array( 'label' => __( 'Issue with the salesperson, task open', 'zorderz' ), 'display' => null === $fu ? $nr : (string) $fu ),
				array( 'label' => __( 'Issue settled, task done (not counted)', 'zorderz' ), 'display' => isset( $q['follow_up_settled'] ) ? (string) (int) $q['follow_up_settled'] : $nr ),
				array( 'label' => __( 'Surveys follow-up tasks open, anyone\'s', 'zorderz' ), 'display' => null === $tasks ? $nr : (string) (int) $tasks ),
			),
		) );
	}

	private static function op_reviews_tile( array $r ): array {
		$label = __( 'Review e-mails this month', 'zorderz' );
		$base  = self::surveys_base();
		$bad   = self::verb_problem( $r, 'Surveys', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['emails_sent'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'The survey counts could not be read just now.', 'zorderz' ) ) );
		}
		$n    = (int) $k['emails_sent'];
		$revs = (int) ( $k['reviews_left'] ?? 0 );
		$df   = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = trim( (string) ( $df['emails_sent'] ?? '' ) . ' Underneath: ' . (string) ( $df['reviews_left'] ?? '' ) );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $revs . ' ' . ( 1 === $revs ? __( 'review', 'zorderz' ) : __( 'reviews', 'zorderz' ) ),
			'parts'   => array(
				array( 'label' => __( 'Review e-mails sent', 'zorderz' ), 'display' => (string) $n ),
				array( 'label' => __( 'Reviews recorded this month', 'zorderz' ), 'display' => (string) $revs ),
				array( 'label' => __( 'Website reviews recorded, all time', 'zorderz' ), 'display' => (string) (int) ( $k['website_reviews_recorded'] ?? 0 ) ),
			),
		) );
	}

	/* ── The field tech's tiles. My hours (Hours' hours.lookup) first, then three of TECH_POOL in the tech's own order
	 *   of use. Each from its own app's count verb; counts only; no "Ask in chat". ── */

	/**
	 * The tiles after My hours, in the tech's order: the pool ranked by the apps they open most (ZDZ_For_You's "at
	 * work" list for the person, when that service is installed), keeping first the tiles whose app this person may
	 * use, then any whose app has no count yet (it says it may need updating), and only then any they may not use (not
	 * shown). Public for the tests.
	 *
	 * @return string[] TECH_SLOTS tile keys
	 */
	public static function tech_order( int $uid ): array {
		return array_slice( self::tech_ranked( $uid ), 0, self::TECH_SLOTS );
	}

	/**
	 * The whole pool in that order (tech_order() is its first TECH_SLOTS). tech_tiles() walks it, so a tile its app
	 * refuses only when asked (Messages on a screen facing a customer) gives its place to the next.
	 *
	 * @return string[] every TECH_POOL key
	 */
	public static function tech_ranked( int $uid ): array {
		$ranked = array();
		if ( class_exists( 'ZDZ_For_You' ) && method_exists( 'ZDZ_For_You', 'for_js' ) ) {
			try {
				$fy     = ZDZ_For_You::for_js( $uid, 'zdz_tech' );
				$ranked = is_array( $fy ) ? array_values( array_map( 'strval', (array) ( $fy['work'] ?? array() ) ) ) : array();
			} catch ( \Throwable $e ) {
				$ranked = array();
			}
		}
		$pos   = array_flip( $ranked );
		$keys  = array_keys( self::TECH_POOL );
		$score = array();
		foreach ( $keys as $i => $k ) {
			$best = PHP_INT_MAX;
			foreach ( self::TECH_POOL[ $k ]['apps'] as $app ) {
				if ( isset( $pos[ $app ] ) ) {
					$best = min( $best, (int) $pos[ $app ] );
				}
			}
			$why  = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::why_not( self::TECH_POOL[ $k ]['verb'], $uid ) : 'unknown';
			$tier = '' === $why ? 0 : ( 'unknown' === $why ? 1 : 2 );   // usable, not answering here yet, not theirs
			$score[ $k ] = array( $tier, $best, $i );
		}
		uksort( $score, function ( $a, $b ) use ( $score ) {
			return $score[ $a ] <=> $score[ $b ];
		} );
		return array_keys( $score );
	}

	/** The tech's week: the site's week (Settings → General, "Week starts on") holding today. PURE apart from the option. */
	public static function tech_week( DateTimeImmutable $local ): array {
		$sow  = (int) get_option( 'start_of_week', 1 );
		$sow  = ( $sow >= 0 && $sow <= 6 ) ? $sow : 1;
		$back = ( (int) $local->format( 'w' ) - $sow + 7 ) % 7;
		$from = $local->setTime( 12, 0 )->modify( '-' . $back . ' days' );
		return array( 'from' => $from->format( 'Y-m-d' ), 'to' => $from->modify( '+6 days' )->format( 'Y-m-d' ), 'today' => $local->format( 'Y-m-d' ) );
	}

	/**
	 * My hours, then the first TECH_SLOTS of $ranked that are shown. Hours is asked first: its week (its own week-start
	 * setting) is the week the other tiles count, so "this week" means one week on the whole strip; without Hours, the
	 * site's week (tech_week()). A tile its app refuses when asked (not shown) gives its place to the next; if fewer
	 * than TECH_SLOTS are shown, the refused ones fill in (hidden on the page, never 0).
	 */
	private static function tech_tiles( array $ranked, DateTimeImmutable $local, array $want = self::SETS['tech'] ): array {
		$hw   = self::ask( 'hours.lookup', array( 'period' => 'this_week' ) );
		$week = self::tech_week( $local );
		$per  = (array) ( $hw['period'] ?? array() );
		if ( ! empty( $hw['success'] ) && empty( $hw['denied'] ) && isset( $per['from'], $per['to'] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $per['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $per['to'] )
			&& (string) $per['from'] <= $week['today'] && $week['today'] <= (string) $per['to'] && (string) $per['to'] < self::add_days( (string) $per['from'], 14 ) ) {
			$week['from'] = (string) $per['from'];
			$week['to']   = (string) $per['to'];
		}
		// Four tiles in all: My hours (unless the business left it out) and the pool after it.
		$tiles   = in_array( 'tech_hours', $want, true ) ? array( 'tech_hours' => self::tech_hours_tile( $hw, $week ) ) : array();
		$refused = array();
		$ranked  = array_values( array_intersect( $ranked, $want ) );
		foreach ( $ranked as $k ) {
			if ( count( $tiles ) > self::TECH_SLOTS ) {
				break;
			}
			$t = self::tech_tile( $k, $week );
			if ( null === $t ) {
				continue;
			}
			if ( 'not_shown' === $t['state'] ) {
				$refused[ $k ] = $t;
				continue;
			}
			$tiles[ $k ] = $t;
		}
		foreach ( $refused as $k => $t ) {
			if ( count( $tiles ) > self::TECH_SLOTS ) {
				break;
			}
			$tiles[ $k ] = $t;
		}
		return $tiles;
	}

	/** One pool tile, asked of its app with the strip's week; null for a key not in the pool. */
	private static function tech_tile( string $k, array $week ) {
		switch ( $k ) {
			case 'tech_jobs':
				return self::tech_jobs_tile( self::ask( 'jobs.my_next_counts', $week ), $week );
			case 'tech_schedule':
				return self::tech_schedule_tile( self::ask( 'schedule.my_counts', array( 'day' => $week['today'] ) ), $week );
			case 'tech_media':
				return self::tech_media_tile( self::ask( 'media.my_counts', $week ), $week );
			case 'tech_messages':
				return self::tech_messages_tile( self::ask( 'messaging.unread_counts', array() ), $week );
		}
		return null;
	}

	/** A 'Y-m-d' moved by $n days. PURE. */
	private static function add_days( string $ymd, int $n ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
		return $d ? $d->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' ) : $ymd;
	}

	/** Minutes as a tile reads them: "31h 20m", "45m", "0h". PURE. */
	public static function hm( int $min ): string {
		$min = max( 0, $min );
		$h   = intdiv( $min, 60 );
		$m   = $min % 60;
		if ( 0 === $h ) {
			return 0 === $m ? '0h' : $m . 'm';
		}
		return $h . 'h' . ( $m ? ' ' . $m . 'm' : '' );
	}

	/** An answer's read time, or now: the owner writes as people act, so reading it is the data's time. */
	private static function read_age( array $r ): array {
		return array(
			'as_of'       => isset( $r['read_at'] ) ? (int) $r['read_at'] : self::now(),
			'as_of_kind'  => 'read',
			'stale_after' => null,
		);
	}

	private static function tech_hours_tile( array $w, array $week ): array {
		$label = __( 'My hours this week', 'zorderz' );
		$base  = array( 'source' => __( 'Hours', 'zorderz' ), 'open' => 'hours', 'open_label' => __( 'Open Hours', 'zorderz' ), 'period' => 'week:' . $week['from'] );
		$bad   = self::verb_problem( $w, 'Hours', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$d  = self::ask( 'hours.lookup', array( 'period' => 'today' ) );
		$tw = (array) ( $w['totals'] ?? array() );
		$td = ! empty( $d['success'] ) && empty( $d['denied'] ) ? (array) ( $d['totals'] ?? array() ) : null;
		if ( ! isset( $tw['worked'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Hours could not be read just now.', 'zorderz' ) ) );
		}
		$n   = (int) $tw['worked'];
		$per = (array) ( $w['period'] ?? array() );
		$nr  = null === $td || ! isset( $td['worked'] );
		$base['definition'] = 'The time on your own clock in Hours, ' . ( isset( $per['from'], $per['to'] ) ? 'this week as Hours counts it (' . self::day_words( (string) $per['from'] ) . ' to ' . self::day_words( (string) $per['to'] ) . ')' : 'this week' ) . ', with today underneath. A clock still running counts up to now.';
		return self::tile( $label, 'ok', $base + self::read_age( $w ) + array(
			'value'   => $n,
			'display' => self::hm( $n ),
			'detail'  => $nr ? __( 'today not read', 'zorderz' ) : self::hm( (int) $td['worked'] ) . ' ' . __( 'today', 'zorderz' ),
			'parts'   => array(
				array( 'label' => __( 'This week', 'zorderz' ), 'display' => self::hm( $n ) ),
				array( 'label' => __( 'Today', 'zorderz' ), 'display' => $nr ? __( 'not read just now', 'zorderz' ) : self::hm( (int) $td['worked'] ) ),
				array( 'label' => __( 'Days worked this week', 'zorderz' ), 'display' => (string) (int) ( $tw['days_worked'] ?? 0 ) ),
				array( 'label' => __( 'Meal-break questions to answer', 'zorderz' ), 'display' => (string) (int) ( $tw['meal_open'] ?? 0 ) ),
			),
		) );
	}

	private static function tech_jobs_tile( array $r, array $week ): array {
		$label = __( 'My installs this week', 'zorderz' );
		$base  = array( 'source' => __( 'Jobs', 'zorderz' ), 'open' => 'jobs', 'open_label' => __( 'Open Jobs', 'zorderz' ), 'period' => 'week:' . $week['from'] . ':' . $week['today'] );
		$bad   = self::verb_problem( $r, 'Jobs', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['this_week'], $k['today'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Your jobs could not be read just now.', 'zorderz' ) ) );
		}
		$n     = (int) $k['this_week'];
		$today = (int) $k['today'];
		$past  = (int) ( $k['passed'] ?? 0 );
		$next  = (string) ( $r['next_install_day'] ?? '' );
		$df    = (array) ( $r['definitions'] ?? array() );
		$none  = __( 'none booked', 'zorderz' );
		$base['definition'] = trim( (string) ( $df['this_week'] ?? '' ) . ' ' . (string) ( $df['today'] ?? '' ) );
		$detail = $today > 0 ? $today . ' ' . __( 'today', 'zorderz' )
			: ( $n > 0 ? __( 'none today', 'zorderz' ) : ( '' !== $next ? __( 'next', 'zorderz' ) . ' ' . self::short_day( $next ) : $none ) );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $detail,
			'parts'   => array(
				array( 'label' => __( 'Today', 'zorderz' ), 'display' => (string) $today ),
				array( 'label' => __( 'Today to', 'zorderz' ) . ' ' . self::short_day( (string) $week['to'] ), 'display' => (string) $n ),
				array( 'label' => __( 'Visit passed, not marked installed', 'zorderz' ), 'display' => (string) $past ),
				array( 'label' => __( 'Other next steps (order, cut, schedule)', 'zorderz' ), 'display' => (string) (int) ( $k['other'] ?? 0 ) ),
				array( 'label' => __( 'Next install after this week', 'zorderz' ), 'display' => '' !== $next ? self::short_day( $next ) : $none ),
			),
		) );
	}

	private static function tech_schedule_tile( array $r, array $week ): array {
		$label = __( 'Appointments today', 'zorderz' );
		$base  = array( 'source' => __( 'Scheduler', 'zorderz' ), 'open' => 'scheduler', 'open_label' => __( 'Open Scheduler', 'zorderz' ), 'period' => 'day:' . $week['today'] );
		$bad   = self::verb_problem( $r, 'Scheduler', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['today'], $k['ahead'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Your calendar could not be read just now.', 'zorderz' ) ) );
		}
		$n     = (int) $k['today'];
		$ahead = (int) $k['ahead'];
		$tom   = (int) ( $k['tomorrow'] ?? 0 );
		$at    = isset( $r['next_at'] ) && is_numeric( $r['next_at'] ) ? (int) $r['next_at'] : null;
		$df    = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = trim( (string) ( $df['today'] ?? '' ) . ' ' . (string) ( $df['ahead'] ?? '' ) );
		$detail = null !== $at ? __( 'next at', 'zorderz' ) . ' ' . self::clock( $at, (string) ( $r['tz'] ?? '' ) )
			: ( 0 === $n ? ( $tom > 0 ? $tom . ' ' . __( 'tomorrow', 'zorderz' ) : __( 'none today', 'zorderz' ) ) : ( 0 === $ahead ? __( 'all done', 'zorderz' ) : $ahead . ' ' . __( 'still on', 'zorderz' ) ) );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $detail,
			'parts'   => array(
				array( 'label' => __( 'Today', 'zorderz' ), 'display' => (string) $n ),
				array( 'label' => __( 'Not over yet', 'zorderz' ), 'display' => (string) $ahead ),
				array( 'label' => __( 'All day', 'zorderz' ), 'display' => (string) (int) ( $k['all_day'] ?? 0 ) ),
				array( 'label' => __( 'Tomorrow', 'zorderz' ), 'display' => (string) $tom ),
			),
		) );
	}

	private static function tech_media_tile( array $r, array $week ): array {
		$label = __( 'Photos this week', 'zorderz' );
		$base  = array( 'source' => __( 'Media', 'zorderz' ), 'open' => 'zdz-media-all', 'open_label' => __( 'Open Media', 'zorderz' ), 'period' => 'week:' . $week['from'] . ':' . $week['today'] );
		$bad   = self::verb_problem( $r, 'Media', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$w = (array) ( $r['week'] ?? array() );
		$d = (array) ( $r['today'] ?? array() );
		if ( ! isset( $w['photos'], $d['photos'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Your photos could not be counted just now.', 'zorderz' ) ) );
		}
		$n  = (int) $w['photos'];
		$df = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = (string) ( $df['photos'] ?? '' );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => (int) $d['photos'] . ' ' . __( 'today', 'zorderz' ),
			'parts'   => array(
				array( 'label' => __( 'Photos this week', 'zorderz' ), 'display' => (string) $n ),
				array( 'label' => __( 'Photos today', 'zorderz' ), 'display' => (string) (int) $d['photos'] ),
				array( 'label' => __( 'Videos this week', 'zorderz' ), 'display' => (string) (int) ( $w['videos'] ?? 0 ) ),
				array( 'label' => __( 'Sketches and documents this week', 'zorderz' ), 'display' => (string) (int) ( $w['other'] ?? 0 ) ),
			),
		) );
	}

	private static function tech_messages_tile( array $r, array $week ): array {
		$label = __( 'Unread messages', 'zorderz' );
		$base  = array( 'source' => __( 'Messages', 'zorderz' ), 'open' => 'internal-messaging', 'open_label' => __( 'Open Messages', 'zorderz' ), 'period' => 'day:' . $week['today'] );
		$bad   = self::verb_problem( $r, 'Messages', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['unread'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'Messages could not be read just now.', 'zorderz' ) ) );
		}
		$n  = (int) $k['unread'];
		$dm = (int) ( $k['direct'] ?? 0 );
		$df = (array) ( $r['definitions'] ?? array() );
		$base['definition'] = (string) ( $df['unread'] ?? '' );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => 0 === $n ? __( 'all read', 'zorderz' ) : ( $dm > 0 ? $dm . ' ' . __( 'direct', 'zorderz' ) : __( 'in channels', 'zorderz' ) ),
			'parts'   => array(
				array( 'label' => __( 'In direct conversations', 'zorderz' ), 'display' => (string) $dm ),
				array( 'label' => __( 'In channels', 'zorderz' ), 'display' => (string) (int) ( $k['channels'] ?? 0 ) ),
				array( 'label' => __( 'Conversations with unread', 'zorderz' ), 'display' => (string) (int) ( $k['conversations'] ?? 0 ) ),
			),
		) );
	}

	/** 'Y-m-d' → 'Mon 2 Nov'. PURE. */
	private static function short_day( string $ymd ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
		return $d ? $d->format( 'D j M' ) : $ymd;
	}

	/** A unix time as a clock in the given zone (else the site's): '10:30 AM'. */
	private static function clock( int $ts, string $tz ): string {
		try {
			$zone = new DateTimeZone( '' !== $tz ? $tz : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ) );
		} catch ( \Throwable $e ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->format( 'g:i A' );
	}

	/* ── The company's other tiles ── */

	private static function estimates_tile( array $r, array $c ): array {
		$label = __( 'Estimates this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the billing system', 'zorderz' ),
			'ask'    => 'How many estimates are dated in ' . $c['label'] . ' (' . $c['zone'] . '), drafts and deleted left out, and how many of those were accepted or turned into an invoice?',
		);
		$bad = self::verb_problem( $r, 'Analytics', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$mine = 'company' !== ( $r['shaped_for'] ?? '' );
		$figs = $mine ? (array) ( $r['mine'] ?? array() ) : (array) ( $r['company'] ?? array() );
		if ( $mine ) {
			$label       = __( 'Your estimates this month', 'zorderz' );
			$base['ask'] = 'How many of my estimates are dated in ' . $c['label'] . ' (' . $c['zone'] . '), drafts and deleted left out, and how many were accepted or invoiced?';
			if ( ! empty( $figs['no_rep_code'] ) ) {
				return self::tile( $label, 'not_shown', array( 'message' => (string) ( $r['definitions']['no_rep_code'] ?? '' ) ) );
			}
		}
		$base['definition'] = trim( (string) ( $r['definitions']['estimates'] ?? '' ) . ' ' . (string) ( $r['definitions']['estimates_accepted'] ?? '' ) );
		if ( isset( $r['errors']['estimates'] ) || ! isset( $figs['estimates'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['estimates'] ?? __( 'The estimates could not be read just now.', 'zorderz' ) ) ) );
		}
		$n = (int) $figs['estimates'];
		return self::tile( $label, self::source_state( $r, 'estimates' ), $base + self::source_age( $r, 'estimates' ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => isset( $figs['estimates_accepted'] ) ? (int) $figs['estimates_accepted'] . ' ' . __( 'accepted', 'zorderz' ) : '',
		) );
	}

	private static function new_leads_tile( array $r, array $c ): array {
		$label = __( 'New leads this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the CRM', 'zorderz' ),
			'ask'    => 'How many CRM leads were created in ' . $c['label'] . ' (' . $c['zone'] . ')? Count every kind, with no cap, and say how many are open now.',
		);
		$bad = self::lead_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$f = (array) $r['company'];
		$base['definition'] = (string) ( $r['definitions']['leads_new'] ?? '' );
		if ( isset( $r['errors']['leads'] ) || ! isset( $f['leads_new'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['leads'] ?? __( 'The leads could not be read just now.', 'zorderz' ) ) ) );
		}
		$n = (int) $f['leads_new'];
		return self::tile( $label, self::source_state( $r, 'leads' ), $base + self::source_age( $r, 'leads' ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => isset( $f['leads_open'] ) ? (int) $f['leads_open'] . ' ' . __( 'open now', 'zorderz' ) : '',
		) );
	}

	private static function won_tile( array $r, array $c ): array {
		$label = __( 'Leads won this month', 'zorderz' );
		$base  = array(
			'source' => __( 'Analytics\' hourly copy of the CRM', 'zorderz' ),
			'ask'    => 'How many CRM leads did we win in ' . $c['label'] . ' (' . $c['zone'] . '), by the day they were closed won, whenever they were created? And what was the win rate, won divided by won plus lost, among the leads closed that month?',
		);
		$bad = self::lead_problem( $r, $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$f = (array) $r['company'];
		$base['definition'] = trim( (string) ( $r['definitions']['leads_won'] ?? '' ) . ' ' . (string) ( $r['definitions']['win_rate'] ?? '' ) );
		if ( isset( $r['errors']['leads'] ) || ! isset( $f['leads_won'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['errors']['leads'] ?? __( 'The leads could not be read just now.', 'zorderz' ) ) ) );
		}
		$n    = (int) $f['leads_won'];
		$rate = $f['win_rate'] ?? null;
		return self::tile( $label, self::source_state( $r, 'leads' ), $base + self::source_age( $r, 'leads' ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => null === $rate ? __( 'none closed yet', 'zorderz' ) : __( 'win rate', 'zorderz' ) . ' ' . (int) round( 100 * (float) $rate ) . '%',
		) );
	}

	/** The ledger's lead figures are the company's only (its leads carry no salesperson code). */
	private static function lead_problem( array $r, string $label, array $base ) {
		$bad = self::verb_problem( $r, 'Analytics', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		if ( 'company' !== ( $r['shaped_for'] ?? '' ) || ! is_array( $r['company'] ?? null ) ) {
			return self::tile( $label, 'not_shown', array( 'message' => (string) ( $r['definitions']['not_shown'] ?? __( 'Lead figures are not shown on this account.', 'zorderz' ) ) ) );
		}
		return null;
	}

	/* ── surveys, reviews ── */

	private static function surveys_tile( array $r, array $c ): array {
		$label = __( 'Surveys this month', 'zorderz' );
		$base  = self::surveys_base() + array(
			'ask' => 'How many customers had their review e-mail go out in ' . $c['label'] . ' (' . $c['zone'] . '), counting each customer once, and of the customers put on that month\'s call list, how many did the follow-up call reach?',
		);
		$bad = self::verb_problem( $r, 'Surveys', $label, $base );
		if ( $bad ) {
			return $bad;
		}
		$k = (array) ( $r['counts'] ?? array() );
		if ( ! isset( $k['emails_sent'] ) ) {
			return self::tile( $label, 'error', $base + array( 'message' => __( 'The survey counts could not be read just now.', 'zorderz' ) ) );
		}
		$n    = (int) $k['emails_sent'];
		$list = (int) ( $k['put_on_call_list'] ?? 0 );
		$base['definition'] = trim( (string) ( $r['definitions']['emails_sent'] ?? '' ) . ' Underneath: of the customers put on this month\'s call list, how many the follow-up call reached.' );
		return self::tile( $label, 'ok', $base + self::read_age( $r ) + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $list > 0 ? (int) ( $k['reached'] ?? 0 ) . ' ' . __( 'of', 'zorderz' ) . ' ' . $list . ' ' . __( 'reached', 'zorderz' ) : __( 'none on the call list yet', 'zorderz' ),
		) );
	}

	private static function google_tile(): array {
		$label = __( 'Google reviews', 'zorderz' );
		// Google's own total and average once the site is connected to the Business Profile (ZDZ_Core_Google_Business).
		// A home screen asks Google itself only when nothing fresh is cached, briefly. Until connected, the typed number;
		// the typed number too while Google has not approved the business's project, and on a copy of the site (which
		// never asks Google), each saying so.
		$note  = '';
		$short = '';
		if ( class_exists( 'ZDZ_Core_Google_Business' ) && ZDZ_Core_Google_Business::is_ready() ) {
			$api = array(
				'definition'  => __( 'Every review on the business\'s Google Business Profile, with the average star rating, read from Google (asked at most once an hour).', 'zorderz' ),
				'source'      => __( 'Google Business Profile', 'zorderz' ),
				'as_of_kind'  => 'synced',
				'stale_after' => self::stale_after( 'ledger' ),
			);
			$sum = ZDZ_Core_Google_Business::summary( true );
			if ( is_array( $sum ) && isset( $sum['total'] ) ) {
				return self::tile( $label, 'ok', $api + array(
					'value'   => (int) $sum['total'],
					'display' => (string) (int) $sum['total'],
					'detail'  => null !== $sum['average'] ? '★ ' . number_format( (float) $sum['average'], 1 ) . ' ' . __( 'average', 'zorderz' ) : __( 'no rating yet', 'zorderz' ),
					'as_of'   => (int) $sum['asked_at'],
				) );
			}
			$why = method_exists( 'ZDZ_Core_Google_Business', 'fallback_reason' ) ? ZDZ_Core_Google_Business::fallback_reason() : '';
			if ( 'not_approved' === $why ) {
				$note  = __( 'Google has not approved this business\'s access to its reviews yet, so this is the number typed by hand.', 'zorderz' );
				$short = __( 'Google: not approved yet', 'zorderz' );
			} elseif ( 'staging' === $why ) {
				$note  = __( 'This copy of the site does not ask Google, so this is the number typed by hand.', 'zorderz' );
				$short = __( 'not asked on a copy of the site', 'zorderz' );
			} else {
				return self::tile( $label, 'error', $api + array( 'message' => __( 'Google could not be asked just now.', 'zorderz' ) ) );
			}
		}
		$n    = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_google_reviews_count' ) ? ZDZ_Core_Settings::get_google_reviews_count() : 0;
		$at   = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_google_reviews_entered_at' ) ? ZDZ_Core_Settings::get_google_reviews_entered_at() : 0;
		$base = array(
			'definition'  => trim( $note . ' ' . ( '' !== $note
				? __( 'The number of Google reviews, typed in by hand in Zorderz → Settings: as current as the day it was typed.', 'zorderz' )
				: __( 'The number of Google reviews, typed in by hand in Zorderz → Settings. Nothing on the site reads Google, so it is as current as the day it was typed.', 'zorderz' ) ) ),
			'source'      => __( 'typed in Zorderz → Settings', 'zorderz' ),
			'as_of_kind'  => 'entered',
			'stale_after' => self::stale_after( 'google' ),
		);
		if ( $n <= 0 && $at <= 0 ) {
			return self::tile( $label, 'empty', $base + array( 'message' => __( 'Not entered yet.', 'zorderz' ) . ( '' !== $short ? ' (' . $short . ')' : '' ) ) );
		}
		return self::tile( $label, ( $at > 0 && self::now() - $at <= self::stale_after( 'google' ) ) ? 'ok' : 'stale', $base + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => __( 'typed in', 'zorderz' ) . ( '' !== $short ? ' · ' . $short : '' ),
			'as_of'   => $at > 0 ? $at : null,   // null: typed before the date was kept
		) );
	}

	/** The business's website as the tiles name it: its marketing domain from the Business Profile, else "the website". */
	private static function website_words(): string {
		$d = class_exists( 'ZDZ_Business_Profile' ) ? trim( (string) ZDZ_Business_Profile::get( 'web.marketing_domain', '' ) ) : '';
		$d = preg_replace( '#^https?://#i', '', rtrim( $d, '/' ) );
		return '' !== $d ? $d : __( 'the website', 'zorderz' );
	}

	private static function website_tile( $bridge, array $surveys ): array {
		$label = __( 'Website reviews', 'zorderz' );
		$site  = self::website_words();
		$base  = self::surveys_base() + array(
			/* translators: %s: the business's website ("example.com" or "the website") */
			'definition'  => sprintf( __( 'Every review published on %s, asked of the website itself through the Review Bridge. Underneath: how many of them Surveys has recorded from customers it surveyed.', 'zorderz' ), $site ),
			'source'      => $site,
			'as_of_kind'  => 'asked',
			'stale_after' => self::stale_after( 'bridge' ),
		);
		$ours = ( ! empty( $surveys['success'] ) && empty( $surveys['denied'] ) && isset( $surveys['counts']['website_reviews_recorded'] ) )
			? (int) $surveys['counts']['website_reviews_recorded'] . ' ' . __( 'from surveyed customers', 'zorderz' ) : '';
		if ( 'not_configured' === $bridge ) {
			return self::tile( $label, 'unavailable', $base + array( 'message' => __( 'The Review Bridge to the website is not set up in Zorderz → Settings.', 'zorderz' ) ) );
		}
		if ( ! is_array( $bridge ) ) {
			/* translators: %s: the business's website */
			return self::tile( $label, 'error', $base + array( 'message' => sprintf( __( '%s could not be asked just now.', 'zorderz' ), $site ), 'detail' => $ours ) );
		}
		$n = (int) $bridge['published'];
		return self::tile( $label, ( self::now() - (int) $bridge['asked_at'] ) > self::stale_after( 'bridge' ) ? 'stale' : 'ok', $base + array(
			'value'   => $n,
			'display' => (string) $n,
			'detail'  => $ours,
			'as_of'   => (int) $bridge['asked_at'],
		) );
	}

	/**
	 * Contacted is held: no number, no question. A count of "this month's new leads someone has followed up with" is
	 * only right once the team's follow-ups are recorded where the app can see them (the contact log, ZDZ_Contacts,
	 * filled by the apps that make calls and send messages), so no figure is shown until then.
	 */
	private static function contacted_tile(): array {
		return self::tile( __( 'Contacted', 'zorderz' ), 'held', array(
			'display'    => __( 'Not counted yet', 'zorderz' ),
			'definition' => __( 'Of this month\'s new leads, how many someone has followed up with. It is not counted yet: a count is only right once every follow-up is recorded where the app can see it, so no number is shown until then.', 'zorderz' ),
			'message'    => __( 'Not counted yet.', 'zorderz' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  The Identity Pack's part                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Registers rules.yml's `home_tiles` with the Identity Pack (preview, apply, revert):
	 *
	 *   home_tiles:
	 *     sets:                       # per set, the tiles it shows, in order; only that set's own Core tiles
	 *       sales: [my_leads, my_installed]
	 *       tech: [tech_hours, tech_jobs, tech_schedule]
	 *     shop_items:                 # at most three item tiles for the shop's set
	 *       - label: Panels this month
	 *         counts:                     # the catalog's item ids; a list is added together
	 *           - [panel-new, panel-new-wide]
	 *           - panel-repair
	 *         words: [New, Repaired]
	 *
	 * Written into the options zdz_kpi_tile_sets and zdz_kpi_shop_items, checked as their readers check them.
	 */
	public static function pack_consumer( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( class_exists( 'ZDZ_Identity_Pack' ) && method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			$consumers['home-tiles'] = ZDZ_Identity_Pack::option_consumer( __( 'Home tiles', 'zorderz' ), 'rules', array( 'home_tiles.sets', 'home_tiles.shop_items' ), array( __CLASS__, 'plan_home_tiles' ) );
		}
		return $consumers;
	}

	/**
	 * The pack's home tiles, checked: a set that is not Core's, or a set naming a tile that is not its own, is refused
	 * whole (that set keeps what it has); a shop item the reader would drop is refused with its place in the list.
	 * Nothing is half-applied.
	 */
	public static function plan_home_tiles( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		if ( array_key_exists( 'home_tiles.sets', $data ) ) {
			$path = 'home_tiles.sets';
			$raw  = $data[ $path ];
			$ok   = array();
			if ( ! is_array( $raw ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'Write each set with its list of tiles.' );
			}
			foreach ( is_array( $raw ) ? $raw : array() as $set => $keys ) {
				$set = (string) $set;
				if ( ! isset( self::SETS[ $set ] ) ) {
					$plan['refused'][] = array( 'path' => "$path.$set", 'why' => sprintf( 'Not a tile set (sets are %s).', implode( ', ', array_keys( self::SETS ) ) ) );
					continue;
				}
				$list = ZDZ_Identity_Pack::as_list( $keys );
				$bad  = array_values( array_diff( array_map( 'strval', $list ), self::SETS[ $set ] ) );
				if ( $bad || count( $list ) !== count( array_unique( array_map( 'strval', $list ) ) ) ) {
					$plan['refused'][] = array(
						'path' => "$path.$set",
						'why'  => $bad
							? sprintf( 'Not one of this set\'s tiles: %s (its tiles are %s).', implode( ', ', $bad ), implode( ', ', self::SETS[ $set ] ) )
							: 'A tile is named twice.',
					);
					continue;
				}
				$ok[ $set ] = array_map( 'strval', $list );
			}
			if ( $ok ) {
				$now   = get_option( self::TILE_SETS_OPTION, array() );
				$value = array_merge( is_array( $now ) ? self::clean_tile_sets( $now ) : array(), $ok );
				ZDZ_Identity_Pack::plan_option( $plan, $path, self::TILE_SETS_OPTION, $value );
			}
		}
		if ( array_key_exists( 'home_tiles.shop_items', $data ) ) {
			$path = 'home_tiles.shop_items';
			$raw  = array_values( is_array( $data[ $path ] ) ? $data[ $path ] : array() );
			$bad  = false;
			if ( ! is_array( $data[ $path ] ) || count( $raw ) > self::SHOP_ITEM_SLOTS ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => sprintf( 'A list of at most %d item tiles.', self::SHOP_ITEM_SLOTS ) );
				$bad = true;
			}
			$catalog = class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'get' );
			foreach ( $bad ? array() : $raw as $i => $e ) {
				$one = self::clean_shop_items( array( $e ) );
				if ( 1 !== count( $one ) ) {
					$plan['refused'][] = array( 'path' => $path . '.' . ( $i + 1 ), 'why' => sprintf( 'An item tile needs a label and one or two counts, each a catalog item id (lower case, digits, - and _) or a list of up to %d of them added together.', self::SHOP_COUNT_IDS ) );
					$bad = true;
					continue;
				}
				$ids     = array();
				foreach ( $one[0]['counts'] as $cnt ) {
					$ids = array_merge( $ids, (array) $cnt );
				}
				$unknown = $catalog ? array_values( array_filter( $ids, function ( $id ) {
					return ! is_array( ZDZ_Item_Engine::get( $id ) );
				} ) ) : array();
				if ( $unknown ) {
					// A count key that names no item would read 0 for ever: refused, never shown as a quiet month.
					$plan['refused'][] = array( 'path' => $path . '.' . ( $i + 1 ), 'why' => sprintf( 'Not an item in this site\'s catalog: %s.', implode( ', ', $unknown ) ) );
					$bad = true;
				}
			}
			if ( ! $catalog && ! $bad ) {
				$plan['notes'][] = 'the catalog is not loaded here, so the item ids could not be checked against it.';
			}
			if ( ! $bad ) {
				ZDZ_Identity_Pack::plan_option( $plan, $path, self::SHOP_ITEMS_OPTION, self::clean_shop_items( $raw ) );
			}
		}
		return $plan;
	}

	/* ------------------------------------------------------------------ */
	/*  Asking the owners                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask a verb as the signed-in person. Never throws. A verb nobody registers comes back {_missing: true}.
	 */
	private static function ask( string $verb, array $args ): array {
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! ZDZ_Capabilities::get( $verb ) ) {
			return array( '_missing' => true, 'verb' => $verb );
		}
		try {
			$r = ZDZ_Capabilities::invoke( $verb, $args );
		} catch ( \Throwable $e ) {
			$r = null;
		}
		if ( ! is_array( $r ) ) {
			$r = array( 'success' => false, 'error' => 'No answer.' );
		}
		if ( ( empty( $r['success'] ) && empty( $r['counting'] ) ) || ! empty( $r['denied'] ) ) {
			error_log( 'ZDZ_KPI_Metrics: ' . $verb . ' did not answer (' . ( ! empty( $r['denied'] ) ? 'refused: ' . (string) ( $r['reason'] ?? '' ) : 'error' ) . ').' );
		}
		return $r;
	}

	/** Does this answer carry a tile its owner is still counting? */
	public static function has_counting( array $out ): bool {
		foreach ( (array) ( $out['tiles'] ?? array() ) as $t ) {
			if ( 'counting' === ( $t['state'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** A tile for an owner that is missing, refused, still counting or failed; null when the owner answered. */
	private static function verb_problem( array $r, string $app, string $label, array $base ) {
		if ( ! empty( $r['_missing'] ) ) {
			/* translators: %s: an app's name */
			return self::tile( $label, 'unavailable', $base + array( 'message' => sprintf( __( '%s does not answer this here yet (it may need updating).', 'zorderz' ), $app ) ) );
		}
		if ( ! empty( $r['denied'] ) ) {
			return self::tile( $label, 'not_shown', array( 'message' => (string) ( $r['message'] ?? __( 'Not available on your account.', 'zorderz' ) ) ) );
		}
		if ( empty( $r['success'] ) && ! empty( $r['counting'] ) ) {
			// The owner is making its first count: not a failure, and no number (never 0); the page asks again shortly.
			/* translators: %s: an app's name */
			return self::tile( $label, 'counting', $base + array( 'message' => (string) ( $r['error'] ?? sprintf( __( '%s is counting.', 'zorderz' ), $app ) ) ) );
		}
		if ( empty( $r['success'] ) ) {
			/* translators: %s: an app's name */
			return self::tile( $label, 'error', $base + array( 'message' => (string) ( $r['error'] ?? sprintf( __( '%s could not answer just now.', 'zorderz' ), $app ) ) ) );
		}
		return null;
	}

	/** The ledger source's freshness: ok, or stale when Analytics says so (old or incomplete). */
	private static function source_state( array $r, string $src ): string {
		return ! empty( $r['sources'][ $src ]['stale'] ) ? 'stale' : 'ok';
	}

	private static function source_age( array $r, string $src ): array {
		$at = $r['sources'][ $src ]['synced_at'] ?? null;
		return array(
			'as_of'       => null !== $at ? (int) $at : null,
			'as_of_kind'  => 'synced',
			'stale_after' => self::stale_after( 'ledger' ),
		);
	}

	/**
	 * The website total: {published, asked_at} | 'not_configured' | null (could not be asked).
	 * ZDZ_Core_ReviewBridge::count() keeps it an hour.
	 */
	private static function bridge_count() {
		if ( ! class_exists( 'ZDZ_Core_ReviewBridge' ) ) {
			return 'not_configured';
		}
		try {
			$rb = new ZDZ_Core_ReviewBridge();
			if ( ! $rb->is_configured() ) {
				return 'not_configured';
			}
			$c = method_exists( $rb, 'count' ) ? $rb->count() : null;
		} catch ( \Throwable $e ) {
			$c = null;
		}
		return ( is_array( $c ) && isset( $c['published'], $c['asked_at'] ) ) ? $c : null;
	}

	/* ------------------------------------------------------------------ */
	/*  Last good, per person                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Keep each good tile's value for this person and this month; give a failed tile the last one it had this month
	 * (never last month's under this month's name, never another tile's under its name, and a day's or a week's tile
	 * only the same day's or week's). Written only when something changed. A tile whose owner is missing (unavailable)
	 * gets none: that owner may never answer again, so an old figure would sit there indefinitely.
	 */
	private static function with_last_good( int $uid, array $tiles, string $month ): array {
		$kept = $uid > 0 ? get_user_meta( $uid, self::LAST_GOOD_META, true ) : array();
		$kept = is_array( $kept ) ? $kept : array();
		$next = $kept;
		foreach ( $tiles as $k => $t ) {
			if ( in_array( $t['state'], array( 'ok', 'stale' ), true ) && null !== $t['value'] ) {
				$next[ $k ] = array( 'month' => $month, 'label' => $t['label'], 'display' => $t['display'], 'detail' => $t['detail'], 'value' => $t['value'], 'as_of' => $t['as_of'], 'period' => (string) ( $t['period'] ?? '' ) );
			} elseif ( 'error' === $t['state'] && isset( $kept[ $k ]['month'] ) && $month === $kept[ $k ]['month']
				&& (string) ( $kept[ $k ]['label'] ?? '' ) === (string) $t['label']
				&& (string) ( $kept[ $k ]['period'] ?? '' ) === (string) ( $t['period'] ?? '' ) ) {
				$tiles[ $k ]['last_good'] = array( 'display' => (string) $kept[ $k ]['display'], 'detail' => (string) $kept[ $k ]['detail'], 'as_of' => $kept[ $k ]['as_of'] );
			}
		}
		if ( $uid > 0 && $next !== $kept ) {
			update_user_meta( $uid, self::LAST_GOOD_META, $next );
		}
		return $tiles;
	}

	/* ------------------------------------------------------------------ */
	/*  Words                                                             */
	/* ------------------------------------------------------------------ */

	/** The business's currency sign (Business Profile locale.currency_sign; Core default "$"). */
	private static function sign(): string {
		$s = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '$' ) : '$';
		return '' !== $s ? $s : '$';
	}

	/** An amount for a tile: ≥ 1M "$1.24M", ≥ 1K "$142.5K", else "$850". */
	private static function format_currency( float $amount ): string {
		$s = self::sign();
		$m = $amount < 0 ? '-' : '';
		$a = abs( $amount );
		if ( $a >= 1000000 ) {
			return $m . $s . number_format( $a / 1000000, 2 ) . 'M';
		}
		if ( $a >= 1000 ) {
			return $m . $s . number_format( $a / 1000, 1 ) . 'K';
		}
		return $m . $s . number_format( $a, 0 );
	}

	/** An exact amount for the sheet: "$12,345.67". */
	private static function money( float $amount ): string {
		return ( $amount < 0 ? '-' : '' ) . self::sign() . number_format( abs( $amount ), 2 );
	}

	private static function plural( int $n, string $word ): string {
		return $n . ' ' . $word . ( 1 === $n ? '' : 's' );
	}

	/** 'Y-m-d' → '31 October 2026'. */
	private static function day_words( string $ymd ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
		return $d ? $d->format( 'j F Y' ) : $ymd;
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'zdz_identity_pack_consumers', array( 'ZDZ_KPI_Metrics', 'pack_consumer' ) );
}
