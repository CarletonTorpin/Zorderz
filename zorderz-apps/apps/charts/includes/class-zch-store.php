<?php
/**
 * What the website keeps: saved chart specs, pin state, optional dated aggregate snapshots,
 * and the audit log. Never line-level rows, search results, names or contact details.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Store {

	/** Facts about a ZIP a chart may show. The census facts are Core; the place facts exist when the hub's region pack has them. */
	const ZIPFACT_KEYS = array(
		'population', 'households', 'homes', 'owner_pct', 'single_family_pct', 'home_value',
		'year_built', 'pre1980_pct', 'built_1980s_90s_pct', 'built_2000_pct', 'income', 'age', 'land_sqmi',
		'density', 'age65_pct',
		'fire_vh_pct', 'fire_h_pct', 'fire_pct', 'coast_mi', 'burned_pct', 'fire_count', 'last_fire_year',
		'vector_q8', 'tax_rate', 'lot_acres',
	);

	const GRAIN_DETAILS = array( 'week', 'day', 'month_of_year', 'quarter_of_year', 'week_of_year', 'day_of_week', 'season' );

	/** Injected in tests: replaces the stored settings option. */
	public static $settings = null;

	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	public static function activate() {
		self::maybe_upgrade();
		if ( ! wp_next_scheduled( ZCH_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'zch_5min', ZCH_CRON_HOOK );
		}
	}

	/** Single-flight migration: concurrent requests must not each run dbDelta; take a lock and re-read the version inside it. */
	public static function maybe_upgrade() {
		if ( get_option( 'zch_db_version' ) === ZCH_DB_VERSION ) {
			return;
		}
		global $wpdb;
		$lock = 'zch_migrate';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return;
		}
		try {
			wp_cache_delete( 'zch_db_version', 'options' );
			$wpdb->flush();
			$cur = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'zch_db_version' ) );
			if ( ZCH_DB_VERSION !== $cur ) {
				self::install();
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c     = $wpdb->get_charset_collate();
		$saved = self::table( ZCH_TABLE_SAVED );
		$snap  = self::table( ZCH_TABLE_SNAPSHOT );
		$audit = self::table( ZCH_TABLE_AUDIT );
		dbDelta(
			"CREATE TABLE $saved (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(190) NOT NULL DEFAULT '',
			spec_json LONGTEXT NOT NULL,
			chart_type VARCHAR(20) NOT NULL DEFAULT 'auto',
			pinned TINYINT(1) NOT NULL DEFAULT 0,
			shared_with TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_pinned (owner_user_id, pinned)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE $snap (
			saved_id BIGINT UNSIGNED NOT NULL,
			result_json LONGTEXT NOT NULL,
			as_of DATE NULL,
			refreshed_at DATETIME NOT NULL,
			PRIMARY KEY  (saved_id)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE $audit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			op VARCHAR(20) NOT NULL,
			question VARCHAR(500) NULL,
			caller VARCHAR(40) NULL,
			ok TINYINT(1) NOT NULL DEFAULT 0,
			code VARCHAR(30) NULL,
			rows_out INT NULL,
			ms INT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created_at)
		) $c;"
		);
		// The hourly invoice feed's queue and estimate links.
		if ( class_exists( 'ZCH_Feed' ) ) {
			dbDelta( ZCH_Feed::install_sql( $c ) );
			$feed = ZCH_Feed::table();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feed ) ) !== $feed ) {
				return;                          // not stamped: the next load tries again.
			}
		}
		update_option( 'zch_db_version', ZCH_DB_VERSION, false );
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	public static function settings() {
		$d = array(
			'snapshots'         => false,               // keep dated aggregates for an offline hub: the business decides.
			'alert_email'       => '',                  // empty = the site admin email.
			'business_hours'    => array( 7, 18 ),      // alert only between these hours.
			'business_days'     => array( 1, 2, 3, 4, 5, 6 ),
			'charts_per_minute' => 30,                  // per person: asks, drills, job lists, cards and pivots.
			'bot_totals'        => false,               // let the assistant receive Charts totals.
			'counts_product'    => '',                  // the hub product measured counts are for ('' = off).
			'counts_match'      => '',                  // a word an estimate's items carry for that product.
			'map_shapes'        => '',                  // an https URL to the ZIP-area outlines (GeoJSON).
		);
		$s = is_array( self::$settings ) ? self::$settings : get_option( ZCH_OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge( $d, $s );
	}

	/* ── Saved charts ───────────────────────────────────────────────────── */

	private static function shape( $r ) {
		$r['spec'] = json_decode( $r['spec_json'], true );
		unset( $r['spec_json'] );
		$r['id']            = (int) $r['id'];
		$r['owner_user_id'] = (int) $r['owner_user_id'];
		$r['pinned']        = (bool) $r['pinned'];
		$r['shared_with']   = array_values( array_map( 'intval', (array) json_decode( (string) ( $r['shared_with'] ?? '' ), true ) ) );
		return $r;
	}

	/** Site admin = the web admin who can see and share every saved chart. */
	public static function is_site_admin( $uid ) {
		return user_can( (int) $uid, 'manage_options' );
	}

	/** Own charts, charts shared with this user, and (site admin) everyone's. */
	public static function saved_visible_to( $uid ) {
		global $wpdb;
		$t    = self::table( ZCH_TABLE_SAVED );
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name, no input.
		$rows = $rows ? $rows : array();
		$out  = array();
		foreach ( $rows as $r ) {
			$r    = self::shape( $r );
			$mine = (int) $uid === $r['owner_user_id'];
			if ( ! $mine && ! in_array( (int) $uid, $r['shared_with'], true ) && ! self::is_site_admin( $uid ) ) {
				continue;
			}
			$r['mine']       = $mine;
			$r['can_manage'] = $mine || self::is_site_admin( $uid );
			if ( ! $mine ) {
				$o               = get_userdata( $r['owner_user_id'] );
				$r['owner_name'] = $o ? $o->display_name : '';
				$r['pinned']     = false;           // pins are the maker's own.
			}
			if ( ! $r['can_manage'] ) {
				unset( $r['shared_with'] );
			}
			// A chart about a named account keeps its title (which may name the account) from
			// people who are not allowed to see account names.
			if ( self::uses_accounts( $r['spec'] ) && ! ZCH_Access::block( $uid )['accounts'] ) {
				$r['title'] = __( 'Saved chart (named account hidden)', 'zorderz' );
			}
			$out[] = $r;
		}
		return $out;
	}

	/** Does a spec chart one customer (an invoice card's pivot)? */
	public static function uses_customers( $spec ) {
		$spec = (array) $spec;
		$f    = isset( $spec['filters'] ) ? (array) $spec['filters'] : array();
		return ! empty( $f['customers'] );
	}

	/** Does a saved spec chart a named account (filter or split)? */
	public static function uses_accounts( $spec ) {
		$spec = (array) $spec;
		$f    = isset( $spec['filters'] ) ? (array) $spec['filters'] : array();
		return ! empty( $f['accounts'] ) || ( 'account' === ( $spec['split'] ?? '' ) );
	}

	public static function get_visible( $id, $uid ) {
		foreach ( self::saved_visible_to( $uid ) as $r ) {
			if ( (int) $id === $r['id'] ) {
				return $r;
			}
		}
		return null;
	}

	public static function can_manage( $id, $uid ) {
		global $wpdb;
		$t     = self::table( ZCH_TABLE_SAVED );
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $t WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		return $owner > 0 && ( (int) $uid === $owner || self::is_site_admin( $uid ) );
	}

	public static function set_shared( $id, array $user_ids ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table( ZCH_TABLE_SAVED ),
			array(
				'shared_with' => wp_json_encode( array_values( array_unique( array_map( 'intval', $user_ids ) ) ) ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/** A spec is settings, not data: strip anything that is not a spec field. */
	public static function clean_spec( $spec ) {
		// A saved prospecting recipe is {prospect: recipe, title}; nothing else rides along.
		if ( is_array( $spec ) && isset( $spec['prospect'] ) && is_array( $spec['prospect'] ) && class_exists( 'ZCH_Prospect' ) ) {
			return array(
				'prospect' => ZCH_Prospect::clean_recipe( $spec['prospect'] ),
				'title'    => mb_substr( sanitize_text_field( (string) ( $spec['title'] ?? '' ) ), 0, 190 ),
			);
		}
		$keep = array(
			'intent', 'title', 'measure', 'split', 'time_grain', 'date_from', 'date_to',
			'filters', 'include_unpaid', 'chart_type', 'top_n',
			'orientation', 'labels', 'venn_by', 'venn_sets',
			'facet',
		);
		$out  = array();
		foreach ( (array) $spec as $k => $v ) {
			// A pack's switch fields (a reclassification rule) are named *_mode.
			if ( in_array( $k, $keep, true ) || preg_match( '/^[a-z_]{1,30}_mode$/', (string) $k ) ) {
				$out[ $k ] = $v;
			}
		}
		$w = self::clean_weather( $spec['weather'] ?? null );
		if ( $w ) {
			$out['weather'] = $w;               // a chip edit or a saved chart keeps its weather.
		}
		$gd = $spec['grain_detail'] ?? null;     // a by-week / by-day / date-part chart keeps its grain.
		if ( is_string( $gd ) && in_array( $gd, self::GRAIN_DETAILS, true ) ) {
			$out['grain_detail'] = $gd;
		}
		$z = self::clean_zipfacts( $spec['zipfacts'] ?? null );
		if ( $z ) {
			$out['zipfacts'] = $z;              // and its ZIP facts (rate, shading, dots, profile).
		}
		$cw = self::clean_compare( $spec['compare_windows'] ?? null );
		if ( $cw ) {
			$out['compare_windows'] = $cw;      // "2024 vs 2025" stays a comparison through chip edits.
		}
		return $out;
	}

	/**
	 * The periods a chart compares, as the hub sent them: 2-12 windows of a label and two dates,
	 * drawn side by side ("period") or one line each over the calendar ("group"), and the dates
	 * the hub put on the chips (a changed date chip makes it one range again; the hub checks that,
	 * and everything else, again).
	 */
	public static function clean_compare( $cw ) {
		if ( ! is_array( $cw ) || ! in_array( $cw['as'] ?? null, array( 'period', 'group' ), true ) ) {
			return null;
		}
		$ws = $cw['windows'] ?? null;
		if ( ! is_array( $ws ) || count( $ws ) < 2 || count( $ws ) > 12 ) {   // the hub's COMPARE_MAX.
			return null;
		}
		$iso = '/^\d{4}-\d{2}-\d{2}$/';
		$out = array();
		foreach ( array_values( $ws ) as $w ) {
			if ( ! is_array( $w ) ) {
				return null;
			}
			$a     = (string) ( $w['from'] ?? '' );
			$b     = (string) ( $w['to'] ?? '' );
			$label = trim( sanitize_text_field( (string) ( $w['label'] ?? '' ) ) );
			if ( ! preg_match( $iso, $a ) || ! preg_match( $iso, $b ) || $a > $b || '' === $label || mb_strlen( $label ) > 60 ) {
				return null;
			}
			$out[] = array( 'label' => $label, 'from' => $a, 'to' => $b );
		}
		$dates = array();
		foreach ( array( 'date_from', 'date_to' ) as $k ) {
			$v           = $cw[ $k ] ?? null;
			$dates[ $k ] = ( is_string( $v ) && preg_match( $iso, $v ) ) ? $v : null;
		}
		return array( 'as' => $cw['as'], 'windows' => $out, 'date_from' => $dates['date_from'], 'date_to' => $dates['date_to'] );
	}

	/** The fact keys a chart may name: the Core list plus any a site adds (filter `zch_zipfact_keys`). */
	public static function zipfact_keys() {
		$keys = self::ZIPFACT_KEYS;
		$f    = function_exists( 'apply_filters' ) ? apply_filters( 'zch_zipfact_keys', $keys ) : $keys;
		$out  = array();
		foreach ( is_array( $f ) ? $f : $keys as $k ) {
			if ( is_string( $k ) && preg_match( '/^[a-z][a-z0-9_]{0,29}$/', $k ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** What each ZIP is like on a chart, from closed lists only (the hub checks it again). */
	public static function clean_zipfacts( $z ) {
		if ( ! is_array( $z ) ) {
			return null;
		}
		$views = array( 'rate', 'shade', 'dots', 'profile' );
		$pers  = array( 'homes', 'households', 'people' );
		$view  = isset( $z['view'] ) && in_array( $z['view'], $views, true ) ? $z['view'] : null;
		if ( ! $view ) {
			return null;
		}
		$out = array( 'view' => $view );
		if ( isset( $z['fact'] ) && in_array( $z['fact'], self::zipfact_keys(), true ) ) {
			$out['fact'] = $z['fact'];
		}
		if ( isset( $z['per'] ) && in_array( $z['per'], $pers, true ) ) {
			$out['per'] = $z['per'];
		}
		if ( ( 'rate' === $view && ! isset( $out['per'] ) ) || ( in_array( $view, array( 'shade', 'dots' ), true ) && ! isset( $out['fact'] ) ) ) {
			return null;
		}
		return $out;
	}

	/** The weather beside a chart, from closed lists only (the hub checks it again). */
	public static function clean_weather( $w ) {
		if ( ! is_array( $w ) ) {
			return null;
		}
		$vars  = array( 'tmax', 'tmin', 'tavg', 'prcp', 'rain_days', 'hot_days' );
		$views = array( 'overlay', 'scatter', 'bands', 'only' );
		$var   = isset( $w['var'] ) && in_array( $w['var'], $vars, true ) ? $w['var'] : null;
		if ( ! $var ) {
			return null;
		}
		$out = array(
			'var'  => $var,
			'view' => isset( $w['view'] ) && in_array( $w['view'], $views, true ) ? $w['view'] : 'overlay',
			'lag'  => null,
		);
		foreach ( array( 'color_by', 'labels', 'by_group' ) as $k ) {   // bars colored by it, written on each bar, one line per group.
			if ( ! empty( $w[ $k ] ) && ( true === $w[ $k ] || 1 === $w[ $k ] || '1' === $w[ $k ] || 'true' === $w[ $k ] ) ) {
				$out[ $k ] = true;
			}
		}
		$lag = $w['lag'] ?? null;
		if ( is_array( $lag ) && isset( $lag['n'], $lag['unit'] ) && is_int( $lag['n'] ) && $lag['n'] >= 1 && $lag['n'] <= 12
			&& in_array( $lag['unit'], array( 'day', 'week', 'month' ), true ) ) {
			$out['lag'] = array( 'n' => $lag['n'], 'unit' => $lag['unit'] );
		}
		return $out;
	}

	public static function save_graph( $user_id, $title, $spec, $chart_type, $id = 0 ) {
		global $wpdb;
		$t    = self::table( ZCH_TABLE_SAVED );
		$now  = current_time( 'mysql', true );
		$spec = self::clean_spec( $spec );
		// A saved chart never holds one customer's key (pivots are not saved; a chart started
		// from one is saved for everyone the filter would hide from).
		if ( isset( $spec['filters'] ) && is_array( $spec['filters'] ) ) {
			unset( $spec['filters']['customers'] );
		}
		$type = sanitize_key( $chart_type );
		$row  = array(
			'title'      => mb_substr( sanitize_text_field( $title ), 0, 190 ),
			'spec_json'  => wp_json_encode( $spec ),
			'chart_type' => '' !== $type ? $type : 'auto',
			'updated_at' => $now,
		);
		if ( $id && self::can_manage( $id, $user_id ) ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return $id;
		}
		$row += array( 'owner_user_id' => $user_id, 'pinned' => 0, 'sort_order' => 0, 'created_at' => $now );
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function set_pinned( $id, $user_id, $pinned ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table( ZCH_TABLE_SAVED ),
			array( 'pinned' => $pinned ? 1 : 0, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id, 'owner_user_id' => $user_id )
		);
	}

	public static function delete_graph( $id, $user_id ) {
		global $wpdb;
		if ( ! self::can_manage( $id, $user_id ) ) {
			return false;
		}
		$ok = $wpdb->delete( self::table( ZCH_TABLE_SAVED ), array( 'id' => $id ) );
		if ( $ok ) {
			$wpdb->delete( self::table( ZCH_TABLE_SNAPSHOT ), array( 'saved_id' => $id ) );
		}
		return (bool) $ok;
	}

	/* ── Offline snapshots ──────────────────────────────────────────────── */

	/** The aggregate part of a result a snapshot may keep: rows, totals and labels, never anything else. */
	public static function snapshot_of( $result ) {
		$result = is_array( $result ) ? $result : array();
		return array(
			'columns'         => $result['columns'] ?? array(),
			'rows'            => $result['rows'] ?? array(),
			'totals'          => $result['totals'] ?? array(),
			'groups'          => $result['groups'] ?? array(),
			'periods'         => $result['periods'] ?? array(),
			'colors'          => $result['colors'] ?? array(),
			'chart_type'      => $result['chart_type'] ?? 'table',
			'units_estimated' => ! empty( $result['units_estimated'] ),
			'notes'           => $result['notes'] ?? array(),
		);
	}

	public static function put_snapshot( $saved_id, $result, $as_of ) {
		if ( ! self::settings()['snapshots'] ) {
			return;
		}
		global $wpdb;
		$wpdb->replace(
			self::table( ZCH_TABLE_SNAPSHOT ),
			array(
				'saved_id'     => $saved_id,
				'result_json'  => wp_json_encode( self::snapshot_of( $result ) ),
				'as_of'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $as_of ) ? $as_of : null,
				'refreshed_at' => current_time( 'mysql', true ),
			)
		);
	}

	public static function get_snapshot( $saved_id ) {
		if ( ! self::settings()['snapshots'] ) {
			return null;
		}
		global $wpdb;
		$t = self::table( ZCH_TABLE_SNAPSHOT );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE saved_id=%d", $saved_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		if ( ! $r ) {
			return null;
		}
		return array( 'result' => json_decode( $r['result_json'], true ), 'as_of' => $r['as_of'] );
	}

	/* ── Audit and limits ───────────────────────────────────────────────── */

	public static function audit( $op, $ok, $code = null, $rows = null, $ms = null, $question = null, $caller = 'ui' ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$wpdb->insert(
			self::table( ZCH_TABLE_AUDIT ),
			array(
				'user_id'    => get_current_user_id(),
				'op'         => substr( (string) $op, 0, 20 ),
				'question'   => null !== $question ? mb_substr( (string) $question, 0, 500 ) : null,
				'caller'     => substr( (string) $caller, 0, 40 ),
				'ok'         => $ok ? 1 : 0,
				'code'       => null !== $code ? substr( (string) $code, 0, 30 ) : null,
				'rows_out'   => $rows,
				'ms'         => $ms,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/** The transient key that counts one person's calls in one minute. */
	public static function rate_key( $user_id, $now = null ) {
		return 'zch_rl_' . (int) $user_id . '_' . gmdate( 'YmdHi', null !== $now ? (int) $now : time() );
	}

	public static function rate_ok( $user_id ) {
		$key = self::rate_key( $user_id );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) self::settings()['charts_per_minute'] ) {
			return false;
		}
		set_transient( $key, $n + 1, 90 );
		return true;
	}
}
