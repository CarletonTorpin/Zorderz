<?php
/**
 * ZEST_Accept_Watch - a customer's acceptance noticed within minutes, not when someone happens to look.
 *
 * Every 5 minutes, in WP-Cron (never while a person waits):
 *
 *   candidates  this app's estimates with a billing id, not accepted, made in the last LOOKBACK_DAYS
 *   list read   one page of the billing system's recent estimates (no lines): every recent estimate's status in one
 *               call; only estimates whose id this app holds count, matched exactly
 *   single read what no list returned, oldest-read first, a few a pass (MAX_CALLS), each waiting at most CALL_S
 *   stamp       stamp(): one atomic conditional update records it once and fires the event once, whichever path
 *               noticed it (the watch, the twice-daily sync, a person)
 *
 * The status is read as a SIGNAL through the business's status map (ZEST_FreshBooks::status_signal, filter
 * zdz_billing_estimate_status); an unmapped status means nothing. The billing system's status does not say WHO
 * accepted (the customer, or staff marking it), so the event says who = 'unknown' unless a person recorded it here.
 *
 *   do_action( 'zest_estimate_accepted', $row, $source, $detail )
 *     $source: billing (the billing system's status said so) | manual (a person recorded it here)
 *     $detail: via (watch | sync | manual), who (staff | unknown), billing_state (accepted | manual), salesperson_uids,
 *     salesperson_initials (the people the estimate names, ZEST_Salesperson::for_row), duplicate (another row of the
 *     same billing estimate was accepted first). An acceptance of an estimate made before the watch's first day is
 *     backlog: the quiet zest_estimate_accepted_backlog fires instead (detail backlog and quiet true), so an old
 *     acceptance never wakes other apps. Telling the salesperson is the listeners' (the jobs app sends the notice).
 *
 * Off switch: option zest_accept_watch = 'off'. Nothing is logged but ids and counts.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Accept_Watch {

	const HOOK          = 'zest_accept_watch';
	const SCHEDULE      = 'zest_5min';
	const INTERVAL      = 300;
	const SINCE         = 'zest_accept_watch_since';
	const READS         = 'zest_accept_watch_reads';
	const SWITCH_OPT    = 'zest_accept_watch';
	const LOOKBACK_DAYS = 365;
	const HOT_DAYS      = 21;
	const MAX_CALLS     = 6;
	const CALL_S        = 8;
	const RECHECK_S     = 1800;
	const MAX_STAMPS    = 5;

	/** @var callable|null tests: ( method, path ) => decoded answer */
	public static $transport = null;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) && 'off' !== get_option( self::SWITCH_OPT, 'on' ) ) {
			wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE, self::HOOK );
		}
	}

	public static function register_schedule( $s ) {
		$s                   = is_array( $s ) ? $s : array();
		$s[ self::SCHEDULE ] = array( 'interval' => self::INTERVAL, 'display' => __( 'Every 5 minutes', 'zorderz' ) );
		return $s;
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** The watch's first day (Y-m-d H:i:s, UTC): acceptances of estimates made before it are backlog. */
	public static function since(): string {
		$s = (string) get_option( self::SINCE, '' );
		if ( '' === $s ) {
			$s = gmdate( 'Y-m-d H:i:s' );
			update_option( self::SINCE, $s, false );
		}
		return $s;
	}

	private static function call( string $path ) {
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, 'GET', $path );
		}
		$c = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::client() : null;
		if ( ! $c || ! method_exists( $c, 'api_request' ) ) {
			return null;
		}
		try {
			return $c->api_request( 'GET', $path );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function account(): string {
		return class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) ZDZ_Core_Settings::get_fb_account_id() ) : '';
	}

	/** One pass. Returns { read, stamped } counts. */
	public static function run(): array {
		$out = array( 'read' => 0, 'stamped' => 0 );
		if ( 'off' === get_option( self::SWITCH_OPT, 'on' ) || ! class_exists( 'ZEST_DB' ) || ( ! is_callable( self::$transport ) && ! ZEST_FreshBooks::is_configured() ) ) {
			return $out;
		}
		$acct = self::account();
		if ( '' === $acct && ! is_callable( self::$transport ) ) {
			return $out;
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . ZEST_DB::estimates_table() . " WHERE billing_doc_id <> '' AND status NOT IN ('accepted','converted','declined','void') AND doc_type = 'estimate' AND created_at >= %s ORDER BY id DESC LIMIT 1500", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			gmdate( 'Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * 86400 )
		), ARRAY_A );
		if ( ! $rows ) {
			return $out;
		}
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['billing_doc_id'] ][] = $r;
		}
		$since  = self::since();
		$seen   = array();
		$calls  = 0;
		$resp   = self::call( '/accounting/account/' . $acct . '/estimates/estimates?per_page=100&search[date_min]=' . gmdate( 'Y-m-d', time() - self::HOT_DAYS * 86400 ) );
		$calls++;
		foreach ( (array) ( is_array( $resp ) ? ( $resp['response']['result']['estimates'] ?? array() ) : array() ) as $est ) {
			$id = is_array( $est ) ? (string) ( $est['id'] ?? ( $est['estimateid'] ?? '' ) ) : '';
			if ( '' !== $id && isset( $by[ $id ] ) ) {
				$seen[ $id ] = $est;
			}
		}
		$reads = get_option( self::READS, array() );
		$reads = is_array( $reads ) ? $reads : array();
		$cold  = array_diff_key( $by, $seen );
		uksort( $cold, static function ( $a, $b ) use ( $reads ) { return (int) ( $reads[ $a ] ?? 0 ) <=> (int) ( $reads[ $b ] ?? 0 ); } );
		foreach ( array_keys( $cold ) as $id ) {
			if ( $calls >= self::MAX_CALLS ) {
				break;
			}
			if ( time() - (int) ( $reads[ $id ] ?? 0 ) < self::RECHECK_S ) {
				continue;
			}
			$one = self::call( '/accounting/account/' . $acct . '/estimates/estimates/' . rawurlencode( (string) $id ) );
			$calls++;
			$reads[ $id ] = time();
			$est = is_array( $one ) ? ( $one['response']['result']['estimate'] ?? null ) : null;
			if ( is_array( $est ) && (string) ( $est['id'] ?? ( $est['estimateid'] ?? '' ) ) === (string) $id ) {
				$seen[ (string) $id ] = $est;
			}
		}
		update_option( self::READS, array_slice( $reads, -2000, null, true ), false );
		$out['read'] = count( $seen );
		foreach ( $seen as $id => $est ) {
			if ( $out['stamped'] >= self::MAX_STAMPS ) {
				break;
			}
			if ( 'customer_accepted' !== ZEST_FreshBooks::status_signal( $est ) ) {
				continue;
			}
			foreach ( $by[ $id ] as $row ) {
				if ( self::stamp( $row, 'watch', 0, (string) ( $row['created_at'] ?? '' ) < $since ) ) {
					$out['stamped']++;
				}
			}
		}
		return $out;
	}

	/** What the event says about the people and the path. */
	public static function detail( array $row, string $via, int $by, bool $backlog, bool $duplicate ): array {
		$sp = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::for_row( $row ) : array( 'salesperson_uids' => array(), 'salesperson_initials' => array() );
		return array(
			'via'                  => $via,
			'who'                  => $by > 0 ? 'staff' : 'unknown',
			'billing_state'        => $by > 0 ? 'manual' : 'accepted',
			'salesperson_uids'     => $sp['salesperson_uids'],
			'salesperson_initials' => $sp['salesperson_initials'],
			'backlog'              => $backlog,
			'quiet'                => $backlog,
			'duplicate'            => $duplicate,
		);
	}

	/**
	 * Record one acceptance once and fire its event once (an atomic conditional update: two paths at once never both
	 * fire). $by: the person who recorded it here (0 for the billing system's status). true when this call recorded it.
	 */
	public static function stamp( array $row, string $via, int $by = 0, bool $backlog = false ): bool {
		global $wpdb;
		$t  = ZEST_DB::estimates_table();
		$ok = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET status = 'accepted', accepted_at = %s, accepted_by = %d, accepted_source = %s WHERE id = %d AND status <> 'accepted'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			current_time( 'mysql', true ), $by, substr( $via, 0, 20 ), (int) $row['id']
		) );
		if ( 1 !== (int) $ok ) {
			return false;
		}
		$dup    = '' !== (string) ( $row['billing_doc_id'] ?? '' ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE billing_doc_id = %s AND status = 'accepted' AND id <> %d", (string) $row['billing_doc_id'], (int) $row['id'] ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		$backlog = $backlog && 'watch' === $via;   // only the watch's own evidence makes one quiet; a person or the sync never
		$detail  = self::detail( $row, $via, $by, $backlog, $dup );
		$row['status']      = 'accepted';
		$row['accepted_by'] = $by;
		$source             = $by > 0 ? 'manual' : 'billing';
		try {
			do_action( $backlog ? 'zest_estimate_accepted_backlog' : 'zest_estimate_accepted', $row, $source, $detail );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: an acceptance listener failed for estimate id ' . (int) $row['id'] . '.' );
		}
		if ( $by > 0 && function_exists( 'zest_usage' ) ) {
			zest_usage( 'estimate_accepted', (int) $row['id'], array( 'uid' => $by ) );
		}
		return true;
	}
}
