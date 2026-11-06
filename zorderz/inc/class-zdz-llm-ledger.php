<?php
/**
 * ZDZ_LLM_Ledger -- one row per Ai call, numbers and code words only.
 *
 * Table {prefix}zdz_llm_ledger: ts (UTC), caller, role, provider, model, in / cache_read / cache_write / out tokens,
 * searches, usd_milli (thousandths of a dollar), ms, outcome, fallback, picture_sent, coded_count, caught_count.
 * NEVER TEXT: no question, answer, file name, person, customer or id. Every string field is a short lower-case code word;
 * anything else is written as "other". Kept 13 months (a daily prune).
 *
 * month_to_date() is what the usage page and the 50% / 80% alerts read: the site's own rows ('app'), and every row
 * ('all'). Another tool that spends on the same provider account may write its rows here under a caller named on the
 * filter zdz_llm_external_callers (counted toward 'all' only), or report a figure on zdz_llm_external_month_usd.
 *
 * HOLDS, so two calls at once can't both spend the same headroom. Before a call is sent, reserve() books the most it
 * may cost (the call's own cap, or less when the month has less left) as a row in {prefix}zdz_llm_holds. Every cap check
 * counts live holds as spent. When the call's real cost is in the ledger, release() deletes the hold. A hold whose
 * process died expires on its own (the call's deadline plus a grace), so a crash can't lock money up for long.
 *
 * WHY INSERT, THEN CHECK AGAIN (no lock): each process writes its hold and only then adds up everything. Of two
 * processes, whichever adds up second always sees the other's hold, so at most one can be wrong, and it is wrong only
 * on the safe side; the one that sees itself over the line shrinks or drops its own hold. Nothing here needs GET_LOCK
 * or a lock of any kind. It DOES rely on every statement committing at once (WordPress's autocommit; the router is
 * never called inside an open transaction) and on one primary database (no read replica for these reads). The price:
 * near the line, two calls at once may both get less than they could have.
 *
 * "The most a call may cost" is the router's own worst-case estimate (fit(): a third of the bytes as tokens, the whole
 * prompt at the 1-hour cache-write rate). A prompt that tokenizes much worse than that can cost a little more than its
 * hold.
 *
 * Totals the caps read are always FRESH (no memo): spend that can't be read is refused (null), because spend the
 * router can't see is spend it can't cap.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Ledger {

	const DB_VERSION = '2';                         // 2: + the holds table
	const DB_OPTION  = 'zdz_llm_ledger_db';
	const KEEP_DAYS  = 396;                         // 13 months
	const PRUNE_HOOK = 'zdz_llm_ledger_prune';
	const CODE       = '/^[a-z0-9_.:\-]{1,48}$/';
	const STRINGS    = array( 'caller', 'role', 'provider', 'model', 'outcome' );
	const NUMBERS    = array( 'in_tokens', 'cache_read', 'cache_write', 'out_tokens', 'searches', 'usd_milli', 'ms', 'fallback', 'picture_sent', 'coded_count', 'caught_count' );
	const RECENT     = 30;                          // calls listed one by one on the usage page

	/** This process's view of the month: read from the database, then kept up to date by its own writes; read again
	 *  after MEMO_SECONDS (another process may have spent) and at a new month. */
	private static $memo = null;
	/** Spend this process made whose row could not be written: it still counts. */
	private static $unwritten = 0;
	const MEMO_SECONDS = 60;
	/** The clock (a suite swaps it). */
	public static $now = null;

	private static function now() {
		return self::$now ? (int) call_user_func( self::$now ) : time();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zdz_llm_ledger';
	}

	/** The holds (one row per call in flight: the most it may cost, until when). */
	public static function holds_table() {
		global $wpdb;
		return $wpdb->prefix . 'zdz_llm_holds';
	}

	public static function boot() {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
			add_action( 'init', array( __CLASS__, 'schedule' ) );
		}
	}

	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Callers whose spend is not this site's apps': counted toward 'all' only. Code words; ships empty.
	 *
	 * @return string[]
	 */
	public static function external_callers() {
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_llm_external_callers', array() ) : array();
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $c ) {
			$c = strtolower( (string) $c );
			if ( preg_match( self::CODE, $c ) ) {
				$out[ $c ] = true;
			}
		}
		return array_keys( $out );
	}

	/** Spend another tool reports for this month, in USD (never negative). */
	private static function external_month_usd() {
		$ext = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_llm_external_month_usd', 0.0 ) : 0.0;
		return is_numeric( $ext ) ? max( 0.0, (float) $ext ) : 0.0;
	}

	/** Make the tables once per schema version (cheap: one option read when they exist). */
	public static function maybe_install() {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return true;
		}
		global $wpdb;
		$t   = self::table();
		$cs  = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			caller varchar(48) NOT NULL DEFAULT '',
			role varchar(16) NOT NULL DEFAULT '',
			provider varchar(16) NOT NULL DEFAULT '',
			model varchar(32) NOT NULL DEFAULT '',
			in_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cache_read int(10) unsigned NOT NULL DEFAULT 0,
			cache_write int(10) unsigned NOT NULL DEFAULT 0,
			out_tokens int(10) unsigned NOT NULL DEFAULT 0,
			searches smallint(5) unsigned NOT NULL DEFAULT 0,
			usd_milli int(10) unsigned NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			outcome varchar(24) NOT NULL DEFAULT '',
			fallback tinyint(1) NOT NULL DEFAULT 0,
			picture_sent tinyint(1) NOT NULL DEFAULT 0,
			coded_count smallint(5) unsigned NOT NULL DEFAULT 0,
			caught_count smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY ts (ts),
			KEY caller_ts (caller, ts)
		) {$cs};";
		$h     = self::holds_table();
		$holds = "CREATE TABLE {$h} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			expires datetime NOT NULL,
			app tinyint(1) NOT NULL DEFAULT 1,
			usd_milli int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY expires (expires)
		) {$cs};";
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( array( $sql, $holds ) as $one ) {
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( $one );
			} else {
				$wpdb->query( $one ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own DDL, no input
			}
			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		return true;
	}

	/** A field as it may be stored: a code word or 'other'; a non-negative whole number. Nothing else is kept. */
	public static function clean( array $f ) {
		$row = array( 'ts' => gmdate( 'Y-m-d H:i:s', isset( $f['ts'] ) ? (int) $f['ts'] : self::now() ) );
		foreach ( self::STRINGS as $k ) {
			$v         = strtolower( (string) ( is_scalar( $f[ $k ] ?? '' ) ? ( $f[ $k ] ?? '' ) : '' ) );
			$row[ $k ] = preg_match( self::CODE, $v ) ? $v : 'other';
		}
		foreach ( self::NUMBERS as $k ) {
			$v         = $f[ $k ] ?? 0;
			$row[ $k ] = is_numeric( $v ) ? max( 0, (int) round( (float) $v ) ) : 0;
		}
		$row['fallback']     = $row['fallback'] ? 1 : 0;
		$row['picture_sent'] = $row['picture_sent'] ? 1 : 0;
		$row['role']         = substr( $row['role'], 0, 16 );
		$row['provider']     = substr( $row['provider'], 0, 16 );
		$row['model']        = substr( $row['model'], 0, 32 );
		$row['outcome']      = substr( $row['outcome'], 0, 24 );
		return $row;
	}

	/** The insert formats, in the row's own key order (a fixed list would store a string field as 0). */
	public static function formats( array $row ) {
		$out = array();
		foreach ( array_keys( $row ) as $k ) {
			$out[] = ( 'ts' === $k || in_array( $k, self::STRINGS, true ) ) ? '%s' : '%d';
		}
		return $out;
	}

	/** Write one row. true when it was stored. A row that could not be stored still counts toward the caps. */
	public static function write( array $f ) {
		global $wpdb;
		$row = self::clean( $f );
		$ok  = false !== $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( ! $ok ) {
			self::$unwritten += $row['usd_milli'];
			return false;
		}
		if ( is_array( self::$memo ) && 0 === strpos( $row['ts'], self::$memo['month'] ) ) {
			self::$memo['all'] += $row['usd_milli'];
			if ( ! in_array( $row['caller'], self::external_callers(), true ) ) {
				self::$memo['app'] += $row['usd_milli'];
			}
		}
		return $ok;
	}

	public static function month_start( $now = null ) {
		return gmdate( 'Y-m-01 00:00:00', null === $now ? self::now() : (int) $now );
	}

	/** The 'app' sum's WHERE tail: callers that are not external (prepared; empty list: every row). */
	private static function app_where( $start ) {
		global $wpdb;
		$ext = self::external_callers();
		if ( ! $ext ) {
			return $wpdb->prepare( 'ts >= %s', $start );
		}
		$ph = implode( ',', array_fill( 0, count( $ext ), '%s' ) );
		return $wpdb->prepare( "ts >= %s AND caller NOT IN ({$ph})", array_merge( array( $start ), $ext ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$ph} is one %s placeholder per external caller
	}

	/**
	 * This month's spend in USD: [ 'app' => float, 'all' => float ], or null when the ledger cannot be read.
	 * 'all' adds the filter zdz_llm_external_month_usd.
	 */
	public static function month_to_date() {
		$now = self::now();
		if ( null === self::$memo || $now - self::$memo['at'] > self::MEMO_SECONDS || gmdate( 'Y-m', $now ) !== self::$memo['month'] ) {
			global $wpdb;
			$t     = self::table();
			$start = self::month_start( $now );
			$all   = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(usd_milli),0) FROM {$t} WHERE ts >= %s", $start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null === $all || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			$app = $wpdb->get_var( "SELECT COALESCE(SUM(usd_milli),0) FROM {$t} WHERE " . self::app_where( $start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the WHERE tail is prepared; {$t} is self::table(): $wpdb->prefix plus a fixed name
			if ( null === $app || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			self::$memo = array( 'all' => (int) $all, 'app' => (int) $app, 'at' => $now, 'month' => gmdate( 'Y-m', $now ) );
		}
		return array(
			'app' => ( self::$memo['app'] + self::$unwritten ) / 1000.0,
			'all' => ( self::$memo['all'] + self::$unwritten ) / 1000.0 + self::external_month_usd(),
		);
	}

	/* ================================================================== holds */

	/**
	 * This month as the caps must see it, read fresh, in thousandths: [ 'app' => int, 'all' => int ], or null when any
	 * part can't be read. The ledger's rows + every live hold (not expired) + this process's unwritten spend; 'all' also
	 * adds the filter zdz_llm_external_month_usd.
	 */
	public static function totals() {
		global $wpdb;
		$now   = self::now();
		$t     = self::table();
		$h     = self::holds_table();
		$start = self::month_start( $now );
		$live  = gmdate( 'Y-m-d H:i:s', $now );
		// THE HOLDS FIRST, THEN THE LEDGER. Each read is its own statement and sees the database as it is then. A call
		// that finishes writes its ledger row and only then deletes its hold, so a hold gone by the time the holds are
		// read is already a ledger row by the time the ledger is read: a call finishing between the two reads is counted
		// twice (the safe side), never not at all. The other order could miss it in both.
		$reads = array(
			'hall' => $wpdb->prepare( "SELECT COALESCE(SUM(usd_milli),0) FROM {$h} WHERE expires > %s", $live ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'happ' => $wpdb->prepare( "SELECT COALESCE(SUM(usd_milli),0) FROM {$h} WHERE expires > %s AND app = 1", $live ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'all'  => $wpdb->prepare( "SELECT COALESCE(SUM(usd_milli),0) FROM {$t} WHERE ts >= %s", $start ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'app'  => "SELECT COALESCE(SUM(usd_milli),0) FROM {$t} WHERE " . self::app_where( $start ),
		);
		$v = array();
		foreach ( $reads as $k => $q ) {
			$x = $wpdb->get_var( $q ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
			if ( null === $x || false === $x || '' !== (string) $wpdb->last_error || ! is_numeric( $x ) ) {
				return null;
			}
			$v[ $k ] = (int) $x;
		}
		return array(
			'app' => $v['app'] + $v['happ'] + self::$unwritten,
			'all' => $v['all'] + $v['hall'] + self::$unwritten + (int) ceil( self::external_month_usd() * 1000 ),
		);
	}

	/** What is left under the lines, in thousandths. An app caller is held to both lines; anyone else to 'all' only. */
	public static function room( array $tot, array $lines, $app ) {
		$all = (int) $lines['all'] - $tot['all'];
		return $app ? min( (int) $lines['app'] - $tot['app'], $all ) : $all;
	}

	/**
	 * Book the most a call may cost before it is sent.
	 *
	 * @param float $want_usd the call's own cap
	 * @param array $lines    [ 'app' => thousandths, 'all' => thousandths ] (the business's monthly lines)
	 * @param bool  $app      true for the site's own callers (held to both lines)
	 * @param int   $life_s   seconds from now after which the hold no longer counts (the time to the call's deadline
	 *                        plus a grace; relative, so the router's clock and this one never have to agree)
	 * @return array|null [ 'id' => int, 'usd' => float granted, never above $want_usd rounded up to a thousandth ] (usd 0
	 *                    when nothing is left under a line), or null when the month can't be read or the hold can't be
	 *                    written: the caller refuses.
	 */
	public static function reserve( $want_usd, array $lines, $app, $life_s ) {
		global $wpdb;
		$want = (int) ceil( max( 0.0, (float) $want_usd ) * 1000 );
		$tot  = self::totals();
		if ( null === $tot ) {
			return null;
		}
		$grant = min( $want, self::room( $tot, $lines, $app ) );
		if ( $grant <= 0 ) {
			return array( 'id' => 0, 'usd' => 0.0 );
		}
		$now = self::now();
		$row = array(
			'created'   => gmdate( 'Y-m-d H:i:s', $now ),
			'expires'   => gmdate( 'Y-m-d H:i:s', $now + self::life( $life_s ) ),
			'app'       => $app ? 1 : 0,
			'usd_milli' => $grant,
		);
		if ( false === $wpdb->insert( self::holds_table(), $row, array( '%s', '%s', '%d', '%d' ) ) || (int) $wpdb->insert_id <= 0 ) {
			return null;
		}
		$id = (int) $wpdb->insert_id;
		// Add it all up again, ours included. Whichever of two processes adds up second sees the other's hold.
		$tot = self::totals();
		if ( null === $tot ) {
			self::release( $id );
			return null;
		}
		$over = -self::room( $tot, $lines, $app );
		if ( $over > 0 ) {
			$grant -= $over;
			if ( $grant <= 0 ) {
				self::release( $id );
				return array( 'id' => 0, 'usd' => 0.0 );
			}
			if ( false === $wpdb->update( self::holds_table(), array( 'usd_milli' => $grant ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ) ) {
				self::release( $id );
				return null;
			}
		}
		return array( 'id' => $id, 'usd' => $grant / 1000.0 );
	}

	/** How long a hold lives, in seconds: what the call asked, never under 30 s or over 15 minutes. */
	public static function life( $life_s ) {
		return max( 30, min( 900, (int) ceil( min( 900.0, (float) $life_s ) ) ) );
	}

	/** The call is over and its real cost is in the ledger: its hold stops counting. */
	public static function release( $id ) {
		global $wpdb;
		if ( (int) $id <= 0 ) {
			return false;
		}
		return false !== $wpdb->delete( self::holds_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/** Forget this request's memo (the suites, and a long run). */
	public static function forget() {
		self::$memo      = null;
		self::$unwritten = 0;
	}

	/** This month's rows, for the usage page (numbers and code words only). */
	public static function rows_since( $start ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE ts >= %s ORDER BY ts ASC LIMIT 200000", $start ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $r ) ? $r : array();
	}

	/** The usage page's figures for the rows given. */
	public static function summary( array $rows ) {
		$ext = self::external_callers();
		$s   = array( 'usd' => 0.0, 'app_usd' => 0.0, 'calls' => 0, 'pictures' => 0, 'fallbacks' => 0, 'in' => 0, 'cache_read' => 0, 'cache_write' => 0, 'out' => 0,
			'by' => array( 'caller' => array(), 'role' => array(), 'model' => array(), 'day' => array() ), 'outcomes' => array(), 'tokens' => array() );
		foreach ( $rows as $r ) {
			$usd = ( (int) $r['usd_milli'] ) / 1000.0;
			$s['usd'] += $usd;
			if ( ! in_array( $r['caller'], $ext, true ) ) {
				$s['app_usd'] += $usd;
			}
			$s['calls']++;
			$s['pictures']    += (int) $r['picture_sent'];
			$s['fallbacks']   += (int) $r['fallback'];
			$s['in']          += (int) $r['in_tokens'];
			$s['cache_read']  += (int) $r['cache_read'];
			$s['cache_write'] += (int) $r['cache_write'];
			$s['out']         += (int) $r['out_tokens'];
			$day = substr( (string) $r['ts'], 0, 10 );
			foreach ( array( 'caller' => $r['caller'], 'role' => $r['role'], 'model' => $r['model'], 'day' => $day ) as $dim => $k ) {
				if ( ! isset( $s['by'][ $dim ][ $k ] ) ) {
					$s['by'][ $dim ][ $k ] = array( 'calls' => 0, 'usd' => 0.0, 'ms' => 0 );
				}
				$s['by'][ $dim ][ $k ]['calls']++;
				$s['by'][ $dim ][ $k ]['usd'] += $usd;
				$s['by'][ $dim ][ $k ]['ms']  += (int) $r['ms'];
			}
			// tokens by app, so a cache that misses shows on the page
			$c = (string) $r['caller'];
			if ( ! isset( $s['tokens'][ $c ] ) ) {
				$s['tokens'][ $c ] = array( 'calls' => 0, 'usd' => 0.0, 'in' => 0, 'cache_read' => 0, 'cache_write' => 0, 'out' => 0 );
			}
			$s['tokens'][ $c ]['calls']++;
			$s['tokens'][ $c ]['usd']         += $usd;
			$s['tokens'][ $c ]['in']          += (int) $r['in_tokens'];
			$s['tokens'][ $c ]['cache_read']  += (int) $r['cache_read'];
			$s['tokens'][ $c ]['cache_write'] += (int) $r['cache_write'];
			$s['tokens'][ $c ]['out']         += (int) $r['out_tokens'];
			$o = (string) $r['outcome'];
			$s['outcomes'][ $o ] = ( $s['outcomes'][ $o ] ?? 0 ) + 1;
		}
		$prompt           = $s['in'] + $s['cache_read'] + $s['cache_write'];
		$s['cache_share'] = $prompt > 0 ? $s['cache_read'] / $prompt : 0.0;
		// the latest calls one by one (numbers and code words only), newest first, so where a call's tokens and seconds
		// go can be read per call, not only per app
		$s['recent'] = array();
		foreach ( array_reverse( array_slice( $rows, -self::RECENT ) ) as $r ) {
			$s['recent'][] = array(
				'ts' => (string) $r['ts'], 'caller' => (string) $r['caller'], 'role' => (string) $r['role'], 'model' => (string) $r['model'],
				'in' => (int) $r['in_tokens'], 'cache_read' => (int) $r['cache_read'], 'cache_write' => (int) $r['cache_write'],
				'out' => (int) $r['out_tokens'], 'ms' => (int) $r['ms'], 'usd' => ( (int) $r['usd_milli'] ) / 1000.0, 'outcome' => (string) $r['outcome'],
				'coded' => (int) ( $r['coded_count'] ?? 0 ),   // how many private details the call carried, coded
			);
		}
		return $s;
	}

	/** Delete rows older than 13 months, and holds that expired more than a day ago. */
	public static function prune() {
		global $wpdb;
		$t = self::table();
		$h = self::holds_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$h} WHERE expires < %s", gmdate( 'Y-m-d H:i:s', self::now() - 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ts < %s", gmdate( 'Y-m-d H:i:s', self::now() - self::KEEP_DAYS * 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
