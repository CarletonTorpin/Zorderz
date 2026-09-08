<?php
/**
 * Zjob_Geo_Backfill — re-stamp GPS onto estimate media whose address is now cached.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * When an estimate is finalized, the Estimates app forward-geocodes the customer
 * address (ZDZ_Geocoder::resolve_address) and stamps the resulting GPS onto each
 * uploaded photo + the measurement transcript, so the Project can surface them
 * "at this address". But when that finalize-time geocode MISSED — the provider was
 * slow or unconfigured — the media rows saved with NULL GPS, and GPS on
 * zdz_user_media is write-once (ZDZ_User_Media::update()'s whitelist excludes it),
 * so those rows never surface even after the address later resolves. This is the
 * background pass that fills them.
 *
 * WHAT IT WRITES, AND WHY THAT IS LEGITIMATE
 * ------------------------------------------
 * It fills gps_lat/gps_lng on rows where they are currently NULL, and ONLY on rows
 * whose source_app is 'estimate-creator'. It never overwrites a recorded fix, and
 * it never touches a camera photo: a camera photo's GPS is a forensic fact and a
 * NULL there must STAY unknown; an estimate photo's "location" was DEFINED as the
 * customer's address, so stamping the address GPS onto it completes exactly what
 * finalize would have written had the geocoder answered.
 *
 * CACHE-KEY ALIGNMENT IS LOAD-BEARING
 * -----------------------------------
 * The Project reads photos against the address the Estimates app geocoded. So this
 * pass resolves the SAME structured address (via the shared ZDZ_Geocoder, which
 * owns the cache-key contract), which returns the point to stamp AND seeds the
 * exact cache entry the render path later reads.
 *
 * BUDGET
 * ------
 * resolve_address may hit a networked provider (Identity-configured; Core ships it
 * OFF), so the pass runs inside Zdz_Sweep (wall-clock budget + per-service breaker
 * + lock). Cached addresses are read cache-only first and cost no budget, so a run
 * drains the cheap majority freely and only spends budget on genuinely-new ones. An
 * unfinished run resumes next tick from the same query.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zjob_Geo_Backfill {

	/** The only source_app this pass will ever write to. Camera rows are off-limits. */
	const SOURCE_APP = 'estimate-creator';

	/** Set once the pass has drained; the cron then unschedules itself. */
	const OPT_DONE = 'zjob_geo_restamp_done';

	/** Circuit-breaker / sweep service key. */
	const SERVICE = 'zjob_geo_restamp';

	/** Hourly cron hook. */
	const SWEEP_HOOK = 'zjob_geo_restamp_sweep';

	/** Distinct estimates processed per run (a CEILING; the wall-clock budget stops sooner). */
	const BATCH = 60;

	/** Estimates whose geocode just failed are parked here so the sweep does not re-attempt (and
	 *  re-trip the breaker on) the same un-geocodable address every tick. { est_num: last_fail_epoch }. */
	const OPT_COOLDOWN  = 'zjob_geo_restamp_cooldown';
	const COOLDOWN_SECS = 21600; // 6h
	const COOLDOWN_MAX  = 300;

	public static function init(): void {
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/** Schedule the hourly sweep until the pass reports done. */
	public static function maybe_schedule(): void {
		if ( self::is_done() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP_HOOK );
		}
	}

	/** Cron entry: run one budgeted pass, then unschedule once nothing remains. */
	public static function sweep(): void {
		self::run();
		if ( 0 === self::pending() ) {
			self::mark_done();
		}
	}

	/* ===================================================================== COUNTS */

	private static function media_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_user_media';
	}

	/** Distinct numbered estimates that still have at least one NULL-GPS estimate media row. */
	public static function pending(): int {
		global $wpdb;
		$media = self::media_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix; SOURCE_APP is a class constant literal.
		$n = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(source_ref, '-', 2), '-', -1))
			   FROM `{$media}`
			  WHERE source_app = %s AND gps_lat IS NULL AND source_ref LIKE %s",
			self::SOURCE_APP,
			'est-%'
		) );
		return (int) $n;
	}

	/* ===================================================================== THE PASS */

	/** @var array<string,int> per-run accounting, threaded through the Zdz_Sweep work closure. */
	private static $report = array();

	/**
	 * Re-stamp up to $max distinct estimates' NULL-GPS media, budgeted by Zdz_Sweep.
	 *
	 * @return array{estimates:int,resolved:int,failed:int,skipped:int,stamped:int,remaining:int}
	 */
	public static function run( int $max = self::BATCH ): array {
		self::$report = array( 'estimates' => 0, 'resolved' => 0, 'failed' => 0, 'skipped' => 0, 'stamped' => 0, 'remaining' => 0 );

		if ( ! class_exists( 'ZEST_DB' ) || ! class_exists( 'ZDZ_Geocoder' )
			|| ! method_exists( 'ZEST_DB', 'estimates_table' ) || ! method_exists( 'ZDZ_Geocoder', 'resolve_address' ) ) {
			return self::$report;
		}

		$max = max( 1, min( 500, $max ) );

		if ( class_exists( 'Zdz_Sweep' ) && method_exists( 'Zdz_Sweep', 'run' ) ) {
			Zdz_Sweep::run(
				self::SERVICE,
				static function ( $limit ) use ( $max ) {
					return self::pending_numbers( (int) min( $max, (int) $limit ) );
				},
				static function ( $num ) {
					self::process_one( (string) $num );
				},
				array( 'service' => self::SERVICE, 'batch_limit' => min( $max, 40 ) )
			);
		} else {
			// No sweep infra: run unbudgeted (cache hits are free; a missing networked
			// provider means resolve_address returns null without a network call anyway).
			foreach ( self::pending_numbers( $max ) as $num ) {
				self::process_one( (string) $num );
			}
		}

		self::$report['remaining'] = self::pending();
		return self::$report;
	}

	/**
	 * Resolve one estimate's address and stamp its NULL-GPS media. A throw defers the
	 * row to the next tick (Zdz_Sweep) and, on a geocode miss, parks it in cooldown so
	 * the breaker is not re-tripped on the same un-geocodable address every hour.
	 */
	private static function process_one( string $num ): void {
		self::$report['estimates']++;
		$address = self::address_for_number( $num );
		if ( empty( $address ) ) {
			self::$report['skipped']++;
			return;
		}

		// Cache first — costs no budget and no network.
		$point = ZDZ_Geocoder::resolve_address( $address, array( 'cache_only' => true ) );
		if ( ! is_array( $point ) ) {
			try {
				$point = ZDZ_Geocoder::resolve_address( $address );
			} catch ( \Throwable $e ) {
				$point = null;
			}
			if ( ! is_array( $point ) || ! isset( $point['lat'], $point['lng'] ) ) {
				self::$report['failed']++;
				self::cooldown_note( $num );
				return;
			}
		}
		if ( ! isset( $point['lat'], $point['lng'] ) ) {
			self::$report['failed']++;
			return;
		}

		self::cooldown_clear( $num );
		self::$report['resolved']++;
		$n = self::stamp( $num, (float) $point['lat'], (float) $point['lng'] );
		self::$report['stamped'] += $n;
	}

	/* ===================================================================== PIECES */

	/** Up to $limit distinct estimate numbers with NULL-GPS media, skipping cooled ones. */
	private static function pending_numbers( int $limit ): array {
		global $wpdb;
		$media = self::media_table();
		$limit = max( 1, min( 500, $limit ) );
		$cool  = self::cooldown_get();
		$fetch = (int) min( 500, $limit + count( $cool ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix; SOURCE_APP literal; $fetch clamped.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(source_ref, '-', 2), '-', -1) AS est_num
			   FROM `{$media}`
			  WHERE source_app = %s AND gps_lat IS NULL AND source_ref LIKE %s
			  ORDER BY est_num ASC
			  LIMIT %d",
			self::SOURCE_APP,
			'est-%',
			$fetch
		) );
		$nums = array();
		foreach ( (array) $rows as $s ) {
			$s = (string) $s;
			if ( '' === $s || isset( $cool[ $s ] ) ) {
				continue;
			}
			$nums[] = $s;
			if ( count( $nums ) >= $limit ) {
				break;
			}
		}
		return $nums;
	}

	/**
	 * The STRUCTURED address for an estimate number, read from the SAME estimate row
	 * the finalize path geocoded, so ZDZ_Geocoder::address_cache_key() computes the
	 * byte-identical key the render path reads. Returns array (canonical fields) or ''.
	 *
	 * @return array<string,string>|string
	 */
	private static function address_for_number( string $num ) {
		global $wpdb;
		if ( '' === $num ) {
			return '';
		}
		$t = ZEST_DB::estimates_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from estimates_table(); value bound.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT customer_street, customer_city, customer_state, customer_zip
			   FROM `{$t}` WHERE billing_doc_num = %s ORDER BY id DESC LIMIT 1",
			$num
		), ARRAY_A );
		if ( ! $row ) {
			return '';
		}
		$addr = array(
			'street' => (string) ( $row['customer_street'] ?? '' ),
			'city'   => (string) ( $row['customer_city'] ?? '' ),
			'state'  => (string) ( $row['customer_state'] ?? '' ),
			'zip'    => (string) ( $row['customer_zip'] ?? '' ),
		);
		return ( '' === trim( implode( '', $addr ) ) ) ? '' : $addr;
	}

	/**
	 * Fill GPS on this estimate's NULL-GPS estimate-creator rows. Triply fenced: this
	 * source_app only, this estimate only (by the SAME number extraction pending() uses,
	 * so stamp writes exactly the rows pending counted), and ONLY where gps_lat is still
	 * NULL — so a recorded fix is never overwritten and a camera row is never touched.
	 */
	private static function stamp( string $num, float $lat, float $lng ): int {
		global $wpdb;
		$media = self::media_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix; every value bound below.
		$sql = $wpdb->prepare(
			"UPDATE `{$media}`
			    SET gps_lat = %f, gps_lng = %f
			  WHERE source_app = %s
			    AND gps_lat IS NULL
			    AND source_ref LIKE %s
			    AND SUBSTRING_INDEX(SUBSTRING_INDEX(source_ref, '-', 2), '-', -1) = %s",
			$lat, $lng, self::SOURCE_APP, 'est-%', $num
		);
		$n = $wpdb->query( $sql );
		return ( false === $n ) ? 0 : (int) $n;
	}

	private static function mark_done(): void {
		update_option( self::OPT_DONE, 1, false );
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
	}

	public static function is_done(): bool {
		return (bool) get_option( self::OPT_DONE, 0 );
	}

	/* ===================================================================== COOLDOWN */

	/** Read the cooldown map, pruned of entries older than COOLDOWN_SECS. */
	private static function cooldown_get(): array {
		$map = get_option( self::OPT_COOLDOWN, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$cut = time() - self::COOLDOWN_SECS;
		$out = array();
		foreach ( $map as $num => $ts ) {
			if ( (int) $ts >= $cut ) {
				$out[ (string) $num ] = (int) $ts;
			}
		}
		return $out;
	}

	/** Park an estimate that just failed to geocode. */
	private static function cooldown_note( string $num ): void {
		$map         = self::cooldown_get();
		$map[ $num ] = time();
		if ( count( $map ) > self::COOLDOWN_MAX ) {
			asort( $map );
			$map = array_slice( $map, -self::COOLDOWN_MAX, null, true );
		}
		update_option( self::OPT_COOLDOWN, $map, false );
	}

	/** A good geocode clears any parking for that estimate. */
	private static function cooldown_clear( string $num ): void {
		$map = self::cooldown_get();
		if ( isset( $map[ $num ] ) ) {
			unset( $map[ $num ] );
			update_option( self::OPT_COOLDOWN, $map, false );
		}
	}

	/* ===================================================================== WORKLIST */

	/**
	 * A human sentence for one pending estimate's geocode state. PURE — no DB, no
	 * globals — so it is unit-tested directly. The one row an operator must act on is
	 * 'unresolvable': the provider matched nothing and gave up, so the address itself
	 * has to be corrected at the source. Every other state clears itself on a later run.
	 *
	 * @param string $status 'ok' | 'retry' | 'unresolvable' | 'no-row' | '' (not attempted).
	 */
	public static function describe_status( string $status, int $attempts, bool $parked, int $ceiling = 4 ): string {
		switch ( $status ) {
			case 'unresolvable':
				return sprintf(
					"Couldn't match this address (gave up after %d tries). Correct the address at the source, then re-run.",
					max( 1, $attempts )
				);
			case 'retry':
				$s = sprintf( 'Not matched yet — will retry (attempt %d of %d).', max( 1, $attempts ), max( 1, $ceiling ) );
				return $parked ? $s . ' Parked ~6h after a recent miss.' : $s;
			case 'ok':
				return 'Resolved — will stamp on the next run.';
			case 'no-row':
				return 'No estimate row to read an address from (deleted, or a numberless stub).';
			default:
				return 'Not attempted yet — resolves on the next run.';
		}
	}

	/**
	 * The operator worklist — every estimate still waiting on a location, with its
	 * composed address and whether it is parked. READ-ONLY: no network, no writes.
	 * (The shared geocoder caches by transient rather than a queryable attempts table,
	 * so per-address census status/attempts are not surfaced here — parked vs not is.)
	 *
	 * @return array<int,array{num:string,address:string,parked:bool,note:string}>
	 */
	public static function pending_report( int $limit = 200 ): array {
		global $wpdb;
		$media = self::media_table();
		$limit = max( 1, min( 1000, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix; SOURCE_APP literal; $limit clamped.
		$nums = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(source_ref, '-', 2), '-', -1) AS est_num
			   FROM `{$media}`
			  WHERE source_app = %s AND gps_lat IS NULL AND source_ref LIKE %s
			  ORDER BY est_num ASC
			  LIMIT %d",
			self::SOURCE_APP,
			'est-%',
			$limit
		) );

		$cool = self::cooldown_get();
		$out  = array();
		foreach ( (array) $nums as $num ) {
			$num = (string) $num;
			if ( '' === $num ) {
				continue;
			}
			$parked  = isset( $cool[ $num ] );
			$address = self::address_for_number( $num );
			if ( empty( $address ) ) {
				$out[] = array( 'num' => $num, 'address' => '', 'parked' => $parked, 'note' => self::describe_status( 'no-row', 0, $parked ) );
				continue;
			}
			$line = trim( implode( ', ', array_filter( array(
				trim( (string) ( $address['street'] ?? '' ) ),
				trim( (string) ( $address['city'] ?? '' ) ),
				trim( trim( (string) ( $address['state'] ?? '' ) ) . ' ' . trim( (string) ( $address['zip'] ?? '' ) ) ),
			) ) ) );
			$status = $parked ? 'retry' : '';
			$out[]  = array( 'num' => $num, 'address' => $line, 'parked' => $parked, 'note' => self::describe_status( $status, 1, $parked ) );
		}
		return $out;
	}
}
