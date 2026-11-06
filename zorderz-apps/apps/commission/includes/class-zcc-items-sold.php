<?php
/**
 * ZCC_Items_Sold — the read verb `items.sold_counts`: this month's pieces sold, per catalog item, for the shop's home
 * tiles. The Commission app reads the billing system's invoices and classifies their lines (ZCC_Classifier, the Item
 * Engine's catalog), so it answers; the home screen never reads the billing system.
 *
 * WHAT IT COUNTS
 * --------------
 * Every invoice DATED in the month (the site's time zone; the invoice's issue date, checked here again), paid or not;
 * drafts and deleted ones left out. Each line the classifier matches to a catalog item adds its effective quantity to
 * that item's count, keyed by the item's id. Lines that are not products (location markers, fees, discounts, deposits:
 * the ledger kinds) and lines with no catalog match are not counted. Which items a tile shows, and what it calls them,
 * is the business's (the home screen's shop items, rules.yml home_tiles.shop_items).
 *
 * QUICK, AND HONEST ABOUT ITS AGE
 * -------------------------------
 * A month of invoices with their lines is a slow read, too slow for a home screen. So the counts are made in the
 * background (one WP-Cron event, asked for when the kept copy is missing or older than REFRESH_AFTER; nothing recurs)
 * and kept in OPTION with the time they were made; the verb answers from that copy with its age, stale after
 * STALE_SECONDS. No copy for this month yet: "still being counted" (counting), never zeros. A failed count keeps the
 * last good copy and records the failure.
 *
 * WHO: staff tier and up (the shop, the office, sales, admins); never the shared device. Counts only: no customer, no
 * invoice number, no money.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Items_Sold {

	const VERB          = 'items.sold_counts';
	const OPTION        = 'zcc_items_sold_v1';
	const CRON_HOOK     = 'zcc_items_sold_refresh';
	const STALE_SECONDS = 7200;   // older than this, the tile is amber
	const REFRESH_AFTER = 1800;   // older than this, a read asks for a new count in the background
	const LOCK          = 'zcc_items_sold_lock';

	/** Injected in tests: "now", and the month's invoices (callable( from, to ) => normalized invoices | null). */
	public static $now      = null;
	public static $invoices = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB,
				'source'        => 'commission-calculator',
				'app'           => defined( 'ZCC_APP_ID' ) ? ZCC_APP_ID : 'commission-calculator',
				'min_tier'      => 'staff',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'confirm'       => false,
				'needs_app'     => false,   // the shop's home screen, not the Commission app
				'summary'       => 'This month\'s pieces sold per catalog item (invoices dated in the month, the site\'s time zone, paid or not). Counts only, with when they were counted.',
			),
		);
		return $caps;
	}

	/** The verb. No arguments: this month. Identity is the registry's stamp only. */
	public static function from_capability( $args ): array {
		$args  = is_array( $args ) ? $args : array();
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) )
			|| ( $uid > 0 && class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		$win  = self::month_window();
		$snap = get_option( self::OPTION, null );
		$snap = is_array( $snap ) ? $snap : array();
		$have = isset( $snap['month'], $snap['counts'], $snap['built_at'] ) && $win['month'] === $snap['month'] && is_array( $snap['counts'] );
		$age  = $have ? max( 0, self::now() - (int) $snap['built_at'] ) : null;
		if ( ! $have || $age > self::REFRESH_AFTER ) {
			self::ask_for_count();
		}
		if ( ! $have ) {
			// A count that failed is an error (the tile says so); one not made yet is "still being counted".
			if ( '' !== (string) ( $snap['last_error'] ?? '' ) && $win['month'] === (string) ( $snap['error_month'] ?? '' ) ) {
				return array( 'success' => false, 'error' => __( 'This month\'s items could not be counted just now.', 'zorderz' ), 'verb' => self::VERB );
			}
			return array( 'success' => false, 'counting' => true, 'error' => __( 'This month\'s items are still being counted.', 'zorderz' ), 'verb' => self::VERB );
		}
		$counts = array();
		foreach ( (array) $snap['counts'] as $k => $n ) {
			$counts[ (string) $k ] = (int) $n;
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'month'       => $win['month'],
			'window'      => array( 'from' => $win['from'], 'to' => $win['to'], 'tz' => $win['tz'] ),
			'counts'      => $counts,
			'invoices'    => (int) ( $snap['invoices'] ?? 0 ),
			'counted_at'  => (int) $snap['built_at'],
			'age_s'       => $age,
			'stale'       => $age > self::STALE_SECONDS,
			'definitions' => self::definitions(),
			'source'      => 'commission-calculator',
		);
	}

	public static function definitions(): array {
		return array(
			'items' => __( 'Pieces on invoices dated this month (the site\'s time zone), paid or not; drafts and deleted invoices left out. Each line is read by the Commission app\'s line classifier and counted under the catalog item it matches.', 'zorderz' ),
		);
	}

	/** Ask WP-Cron for a count soon (once: an event already queued, or a count running, is not asked again). */
	public static function ask_for_count(): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ( function_exists( 'get_transient' ) && get_transient( self::LOCK ) ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	/** Clear the queued count (the app's deactivation). */
	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Count this month and keep it. A failed count keeps the last good copy (and says it failed); it never stores zeros
	 * for a month it could not read. Returns the stored copy, or null when nothing new was stored.
	 */
	public static function refresh(): ?array {
		if ( function_exists( 'get_transient' ) && get_transient( self::LOCK ) ) {
			return null;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK, 1, 300 );
		}
		try {
			$win      = self::month_window();
			$invoices = self::month_invoices( $win['from'], $win['to'] );
			$old      = get_option( self::OPTION, null );
			$old      = is_array( $old ) ? $old : array();
			if ( ! is_array( $invoices ) ) {
				$old['last_error']  = 'count failed';
				$old['error_month'] = $win['month'];
				$old['error_at']    = self::now();
				update_option( self::OPTION, $old, false );
				error_log( 'Commission: ' . self::VERB . ' could not count ' . $win['month'] . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				return null;
			}
			$snap = array(
				'month'    => $win['month'],
				'built_at' => self::now(),
				'counts'   => self::counts_from( $invoices, $win['from'], $win['to'] ),
				'invoices' => count( array_filter( $invoices, function ( $inv ) use ( $win ) {
					return self::in_window( $inv, $win['from'], $win['to'] );
				} ) ),
			);
			update_option( self::OPTION, $snap, false );
			return $snap;
		} finally {
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::LOCK );
			}
		}
	}

	/** The month's invoices, normalized, drafts left out; null when they could not be read. */
	private static function month_invoices( string $from, string $to ) {
		if ( is_callable( self::$invoices ) ) {
			return call_user_func( self::$invoices, $from, $to );
		}
		if ( ! class_exists( 'ZCC_FreshBooks' ) || ! ZCC_FreshBooks::is_connected() ) {
			return null;
		}
		try {
			$raw = ZCC_FreshBooks::fetch_raw( $from, $to );
		} catch ( \Throwable $e ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) || ( class_exists( 'ZCC_Invoice_View' ) && method_exists( 'ZCC_Invoice_View', 'is_draft' ) && ZCC_Invoice_View::is_draft( $r ) ) ) {
				continue;
			}
			$out[] = ZCC_FreshBooks::normalize_invoice( $r );
		}
		return $out;
	}

	/** Is this invoice dated inside the window? PURE. */
	private static function in_window( $inv, string $from, string $to ): bool {
		$day = is_array( $inv ) ? substr( (string) ( $inv['date_completed'] ?? '' ), 0, 10 ) : '';
		return '' !== $day && $day >= $from && $day <= $to;
	}

	/**
	 * The pieces per catalog item over invoices dated in the window. $classify defaults to the app's line classifier
	 * (lines => one result each, as ZCC_Classifier::classify_lines). PURE apart from the classifier.
	 *
	 * @return array<string,int> item id => pieces
	 */
	public static function counts_from( array $invoices, string $from, string $to, $classify = null ): array {
		$classify = is_callable( $classify ) ? $classify : array( 'ZCC_Classifier', 'classify_lines' );
		$out      = array();
		foreach ( $invoices as $inv ) {
			if ( ! self::in_window( $inv, $from, $to ) ) {
				continue;   // the date is checked again here: never a line from outside the month
			}
			$lines = array_values( (array) ( $inv['lines'] ?? array() ) );
			if ( ! $lines ) {
				continue;
			}
			foreach ( (array) call_user_func( $classify, $lines ) as $c ) {
				$id = is_array( $c ) ? (string) ( $c['item_id'] ?? '' ) : '';
				if ( '' === $id || '' !== (string) ( $c['ledger_kind'] ?? '' ) || ! empty( $c['is_location'] ) || 'positive' !== (string) ( $c['sign'] ?? 'positive' ) ) {
					continue;
				}
				$out[ $id ] = ( $out[ $id ] ?? 0 ) + max( 0, (int) ( $c['quantity'] ?? 0 ) );
			}
		}
		ksort( $out );
		return $out;
	}

	/** This month in the site's time zone: 'Y-m', its first and last days. */
	public static function month_window(): array {
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now   = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz );
		$start = new DateTimeImmutable( $now->format( 'Y-m' ) . '-01 00:00:00', $tz );
		return array(
			'month' => $now->format( 'Y-m' ),
			'from'  => $start->format( 'Y-m-d' ),
			'to'    => $start->modify( 'last day of this month' )->format( 'Y-m-d' ),
			'tz'    => $tz->getName(),
		);
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}
}
