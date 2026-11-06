<?php
/**
 * ZANA_Ledger — the stored-data ledger: a copy of the billing system's invoices, estimates and payments and the CRM's
 * leads and lead notes, kept in step by a resumable background sync. Reports, home figures and quick answers read the
 * copy (local SQL, in milliseconds) instead of paging the providers inside a person's request.
 *
 * ONE SYNC PASS (hourly; every two minutes while a history is still loading; or "Sync now"), inside a time budget:
 *   1. Billing: invoices, estimates, payments. First what changed since the last pass (updated_min; for invoices and
 *      estimates the default list, then archived, then deleted, so a document deleted in the billing system stops
 *      counting here too), then the history, newest year first, a page at a time, resuming at the exact year, list and
 *      page where the last pass stopped. The history walk stops at the year the rules name (history_from_year), else
 *      after two years in a row with nothing in them. Payments: once a day the current year is read again whole (in
 *      January the year before too, and one older year in rotation), and a payment that read did not return is removed:
 *      a payment deleted or moved to another month leaves no trace.
 *   2. When the derivation rules change (ZANA_Ledger_Rules::signature(), or the catalog's version), every document is
 *      read once more and only its derived columns (lead source, brands, salesperson, town, postal code, mix, due and
 *      paid dates) are rewritten where they differ. The copy stays complete while it runs.
 *   3. CRM leads: the newest until one already held, then the history (oldest first, 100 full leads a page), then the
 *      OPEN leads every few hours (their status is what changes; an open lead that read did not return is marked
 *      "closed, outcome being re-read"), then a slow rolling re-read of everything.
 *   4. CRM lead notes: from the full lead objects when the CRM sends them; otherwise one lead at a time (newest first,
 *      then stale ones). A notes failure never fails the leads' sync.
 * Rows are written by id (ZANA_Ledger_Store), so a re-read never double-counts and a pass the host stops mid-page loses
 * that page only. While the CRM's history is loading, the billing steps leave it the last 14 seconds of every pass.
 *
 * NEVER WRITES TO A PROVIDER. Every call is a read (ZDZ_Core_FreshBooks::list_page, ZDZ_Core_Nutshell::find_leads_page,
 * ZDZ_Core_Nutshell::read_lead). On a staging or test copy (ZDZ_Staging_Guard active) nothing syncs at all: the copy
 * keeps what the database held when it was copied.
 *
 * Contract for other apps (all read-only):
 *   ZANA_Ledger::coverage()                      what the copy holds and how fresh each part is (no customer data)
 *   ZANA_Ledger::invoice_number_for_id( $id )    the invoice number of a billing invoice id ('' when not held)
 *   action `zana_ledger_fb_items` ( $kind, $items )  each page of invoices / estimates as read (the Charts feed)
 *   option `zana_ledger_state`                   the sync's cursors and last good sync times
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger {

	const STATE_OPTION = 'zana_ledger_state';
	const CRON_HOOK    = 'zana_ledger_sync';

	/** Seconds one pass may spend (WP-Cron runs inside a web request). */
	const BUDGET = 25;
	/** Per-request timeout: a page that cannot come back inside it waits for the next pass. */
	const PAGE_TIMEOUT = 20;
	/** The billing steps leave the CRM this many seconds while its history is loading. */
	const CRM_RESERVE = 14;
	/** How often OPEN leads are re-read. */
	const OPEN_REFRESH_SECONDS = 10800;
	/** One-lead-at-a-time notes: an open lead is read again after a day, any lead after 90 days. */
	const NOTES_REREAD_OPEN = 86400;
	const NOTES_REREAD_ALL  = 7776000;
	/** A note longer than this is kept cut (a pasted e-mail thread, say). */
	const NOTE_MAX_CHARS = 60000;
	/** Failed notes passes in a row before the two-minute chain stops (the hourly run carries on). */
	const NOTES_MAX_ERRORS = 5;
	/** The history walk never goes before this year. */
	const OLDEST_YEAR = 1990;
	/** Years in a row with nothing in them that end the history walk (when the rules name no first year). */
	const EMPTY_YEARS_END = 2;

	/** The billing kinds, in the order a pass reads them. */
	const FB_KINDS = array( 'invoices', 'estimates', 'payments' );

	/** Injected in tests: the clients (false = not connected), the store, "now", the staging decision, the catalog version. */
	public static $fb = null;
	public static $ns = null;
	public static $store = null;
	public static $now = null;
	public static $staging = null;
	public static $catalog_version = null;
	/** Injected in tests: callable( float $budget_end ) => bool, "is there time left"; else the clock. */
	public static $clock = null;
	/** Injected in tests: the enrichment context (rules, rep_codes, override). */
	public static $context = null;

	/* ─────────────────────────── boot, schedule ─────────────────────────── */

	public static function boot(): void {
		ZANA_Ledger_Store::maybe_install();
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_filter( 'zjob_order_job_invoice', array( __CLASS__, 'job_invoice_number' ), 10, 2 );
		add_filter( 'zdz_identity_pack_consumers', array( 'ZANA_Ledger_Rules', 'pack_consumer' ) );
	}

	public static function ensure_scheduled(): void {
		if ( self::staging() ) {
			return; // a copy never syncs: nothing is scheduled
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_scheduled(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** Cron: one pass, and another in two minutes while a history is loading and the pass went cleanly. */
	public static function cron(): array {
		$st = self::sync( self::BUDGET );
		if ( ! empty( $st['more'] ) && function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 120, self::CRON_HOOK );
		}
		return $st;
	}

	/** Is this a staging or test copy? Then the ledger reads nothing from any provider. */
	public static function staging(): bool {
		if ( null !== self::$staging ) {
			return (bool) self::$staging;
		}
		return class_exists( 'ZDZ_Staging_Guard' ) && ZDZ_Staging_Guard::is_active();
	}

	/* ─────────────────────────── state ─────────────────────────── */

	public static function state(): array {
		$s = function_exists( 'get_option' ) ? get_option( self::STATE_OPTION, array() ) : array();
		$s = is_array( $s ) ? $s : array();
		foreach ( self::FB_KINDS as $k ) {
			$s[ $k ] = array_merge( array(
				'year'          => null,   // history walk: the year being read
				'page'          => 1,      // ...its next page
				'vis'           => 0,      // ...which list: 0 the default, 2 archived
				'year_seen'     => 0,      // ...documents seen in that year so far
				'empty_years'   => 0,      // years in a row with nothing in them
				'inc_started'   => 0,      // what-changed cycle: when it began (unix)
				'inc_list'      => 0,      // ...which list it is on
				'inc_page'      => 1,      // ...its next page
				'done'          => false,  // the history is loaded
				'loaded_from'   => null,   // the oldest year fully read
				'updated_since' => null,   // Y-m-d: the what-changed cursor
				'rows'          => 0,
				'last_ok'       => null,   // UTC "Y-m-d H:i:s" of the last good pass
				'caught_up_at'  => null,   // UTC: when a pass last read every change there was
				'last_error'    => '',
				'relabel_v'     => '',     // the rules the stored derived columns match
				'relabel_target' => '',
				'relabel_year'  => null,
				'relabel_page'  => 1,
				'relabel_vis'   => 0,
				'relabel_read'  => 0,
				'relabel_changed' => 0,
				'relabel_done_at' => null,
				'relabel_error' => '',
				'backfill_rules' => '',
				'reread_on'     => '',     // payments: the day (UTC) the current year was last read again whole
				'reread_year'   => null,   // ...the year being read again
				'reread_page'   => 1,
				'reread_started' => '',
				'reread_queue'  => array(),
				'reread_next'   => null,   // ...the older year next in rotation
			), (array) ( $s[ $k ] ?? array() ) );
		}
		$s['leads'] = array_merge( array(
			'max_id'         => 0,
			'page'           => 1,
			'done'           => false,
			'open_refreshed' => 0,
			'roll_page'      => 1,
			'rows'           => 0,
			'last_ok'        => null,
			'last_error'     => '',
			'enabled'        => true,
			'notes_page'     => 1,
			'notes_done'     => false,
			'notes_via'      => '',     // 'find' (notes come with the full lead objects) | 'get' (one lead at a time)
			'notes_last'     => null,
			'notes_error'    => '',
			'notes_errors'   => 0,
		), (array) ( $s['leads'] ?? array() ) );
		return $s;
	}

	protected static function save_state( array $s ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $s, false );
		}
	}

	protected static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	protected static function utc( $t = null ): string {
		return gmdate( 'Y-m-d H:i:s', null === $t ? self::now() : (int) $t );
	}

	/** Is there at least $need seconds left before $deadline? */
	protected static function time_left( float $deadline, float $need = 2.0 ): bool {
		if ( is_callable( self::$clock ) ) {
			return (bool) call_user_func( self::$clock, $deadline - $need );
		}
		return microtime( true ) + $need < $deadline;
	}

	public static function store() {
		if ( null !== self::$store ) {
			return self::$store;
		}
		self::$store = new ZANA_Ledger_Store();
		return self::$store;
	}

	/* ─────────────────────────── the pass ─────────────────────────── */

	/**
	 * One pass within $budget seconds.
	 * @return array { ok, more (a history is still loading), notes: string[] }
	 */
	public static function sync( int $budget = self::BUDGET ): array {
		if ( self::staging() ) {
			return array( 'ok' => false, 'more' => false, 'notes' => array( 'this is a staging copy: the ledger does not sync here' ) );
		}
		$store = self::store();
		if ( ! $store->lock() ) {
			return array( 'ok' => false, 'more' => false, 'notes' => array( 'a sync is already running' ) );
		}
		$deadline = microtime( true ) + max( 5, $budget );
		$steps    = array();
		$failed   = false;
		$s        = self::state();
		$fb       = null;
		$ns       = null;
		try {
			$ns_early   = self::ns_client();
			$ns_loading = empty( $s['leads']['done'] ) || ( empty( $s['leads']['notes_done'] ) && $store->notes_ready() && (int) $s['leads']['notes_errors'] < self::NOTES_MAX_ERRORS );
			$fb_deadline = ( $ns_early && ! empty( $s['leads']['enabled'] ) && $ns_loading ) ? $deadline - self::CRM_RESERVE : $deadline;
			$fb = self::fb_client();
			if ( $fb ) {
				$ctx = self::enrich_context();
				foreach ( self::FB_KINDS as $kind ) {
					if ( ! self::time_left( $fb_deadline ) ) {
						break;
					}
					try {
						$n = 'payments' === $kind ? self::sync_payments( $fb, $s, $fb_deadline ) : self::sync_fb( $fb, $kind, $s, $fb_deadline, $ctx );
						$s[ $kind ]['last_ok']    = self::utc();
						$s[ $kind ]['last_error'] = '';
						$steps[] = $kind . ': ' . $n . ' rows';
					} catch ( \Throwable $e ) {
						$failed = true;
						$s[ $kind ]['last_error'] = substr( $e->getMessage(), 0, 300 );
						$steps[] = $kind . ' error';
						error_log( 'Zorderz Analytics ledger: the ' . $kind . ' sync failed: ' . $e->getMessage() );
					}
					self::save_state( $s );
				}
				if ( ! $failed && self::relabel_pending( $s ) ) {
					$rl_deadline = ( $ns_early && ! empty( $s['leads']['enabled'] ) ) ? $deadline - self::CRM_RESERVE : $deadline;
					try {
						$n = self::relabel_fb( $fb, $s, $rl_deadline, $ctx );
						$steps[] = 're-derive: ' . $n . ' changed';
					} catch ( \Throwable $e ) {
						$failed = true;
						$steps[] = 're-derive error';
						error_log( 'Zorderz Analytics ledger: re-deriving the stored labels failed: ' . $e->getMessage() );
					}
					self::save_state( $s );
				}
			} else {
				$steps[] = 'the billing system is not connected';
			}

			$ns = $ns_early;
			if ( $ns && ! empty( $s['leads']['enabled'] ) && self::time_left( $deadline, 12 ) ) {
				try {
					$n = self::sync_leads( $ns, $s, $deadline );
					$s['leads']['last_ok']    = self::utc();
					$s['leads']['last_error'] = '';
					$steps[] = 'leads: ' . $n . ' rows';
				} catch ( \Throwable $e ) {
					$failed = true;
					$s['leads']['last_error'] = substr( $e->getMessage(), 0, 300 );
					$steps[] = 'leads error';
					error_log( 'Zorderz Analytics ledger: the CRM leads sync failed: ' . $e->getMessage() );
				}
				self::save_state( $s );
			} elseif ( ! $ns ) {
				$steps[] = 'the CRM is not connected';
			}
		} finally {
			$store->unlock();
		}
		// The next pass in two minutes only while a history is loading AND this pass went cleanly: a disconnected or
		// failing provider waits for the hourly run, never a retry (and a token refresh) every two minutes, forever.
		$more = ! $failed && self::unfinished( $s, (bool) $fb, (bool) $ns );
		return array( 'ok' => true, 'more' => $more, 'notes' => $steps );
	}

	/** Is any part of the copy still loading (and so worth the two-minute chain)? */
	public static function unfinished( array $s, bool $fb, bool $ns ): bool {
		if ( $fb ) {
			foreach ( self::FB_KINDS as $k ) {
				if ( empty( $s[ $k ]['done'] ) ) {
					return true;
				}
			}
			if ( self::relabel_pending( $s ) ) {
				return true;
			}
		}
		if ( $ns && ! empty( $s['leads']['enabled'] ) ) {
			if ( empty( $s['leads']['done'] ) ) {
				return true;
			}
			if ( empty( $s['leads']['notes_done'] ) && self::store()->notes_ready() && (int) $s['leads']['notes_errors'] < self::NOTES_MAX_ERRORS ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── billing: invoices and estimates ─────────────────────────── */

	/** The rules' first year, or 0 for "until two empty years". */
	protected static function floor_year(): int {
		return (int) ZANA_Ledger_Rules::get()['history_from_year'];
	}

	/** Has the history walk read far enough after finishing $year with $seen documents in it? Updates the empty-year count. */
	public static function walk_done( array &$st, int $year, int $seen ): bool {
		$st['empty_years'] = $seen > 0 ? 0 : (int) $st['empty_years'] + 1;
		$floor             = self::floor_year();
		if ( $floor > 0 ) {
			return $year <= $floor;
		}
		return $year <= self::OLDEST_YEAR || (int) $st['empty_years'] >= self::EMPTY_YEARS_END;
	}

	protected static function sync_fb( $fb, string $kind, array &$s, float $deadline, array $ctx ): int {
		$st    = &$s[ $kind ];
		$count = 0;

		// 1. what changed since the last pass: the default list, then archived, then deleted
		if ( ! empty( $st['updated_since'] ) ) {
			$lists   = array( 0, 2, 1 );
			$reserve = empty( $st['done'] ) ? 8 : 0;
			if ( empty( $st['inc_started'] ) ) {
				$st['inc_started'] = self::now();
				$st['inc_list']    = 0;
				$st['inc_page']    = 1;
			}
			while ( (int) $st['inc_list'] < count( $lists ) ) {
				if ( ! self::time_left( $deadline, 2 + $reserve ) ) {
					break;
				}
				$vis    = $lists[ (int) $st['inc_list'] ];
				$search = array( 'updated_min' => $st['updated_since'] );
				if ( $vis ) {
					$search['vis_state'] = $vis;
				}
				$res    = $fb->list_page( $kind, $search, (int) $st['inc_page'], self::timeout( $deadline - $reserve ) );
				$count += self::upsert_fb( $kind, (array) $res['items'], $ctx );
				if ( (int) $st['inc_page'] >= max( 1, (int) $res['pages'] ) ) {
					$st['inc_list'] = (int) $st['inc_list'] + 1;
					$st['inc_page'] = 1;
				} else {
					$st['inc_page'] = (int) $st['inc_page'] + 1;
				}
				self::save_state( $s );
			}
			if ( (int) $st['inc_list'] >= count( $lists ) ) {
				// Done: next time from a day before this cycle began (the billing system filters by date; a little is read
				// twice, which is harmless).
				$st['updated_since'] = gmdate( 'Y-m-d', (int) $st['inc_started'] - DAY_IN_SECONDS );
				$st['caught_up_at']  = self::utc();
				$st['inc_started']   = 0;
				$st['inc_list']      = 0;
				$st['inc_page']      = 1;
			}
		}

		// 2. the history, newest year first, page by page: each year's default list, then its archived documents
		if ( empty( $st['done'] ) ) {
			if ( null === $st['year'] ) {
				$st['year']           = (int) gmdate( 'Y', self::now() );
				$st['page']           = 1;
				$st['vis']            = 0;
				$st['year_seen']      = 0;
				$st['empty_years']    = 0;
				$st['backfill_rules'] = self::rules_version();
				if ( empty( $st['updated_since'] ) ) {
					$st['updated_since'] = gmdate( 'Y-m-d', self::now() - DAY_IN_SECONDS );
				}
			}
			while ( empty( $st['done'] ) && self::time_left( $deadline ) ) {
				$y      = (int) $st['year'];
				$vis    = (int) $st['vis'];
				$search = array( 'date_min' => $y . '-01-01', 'date_max' => $y . '-12-31' );
				if ( $vis ) {
					$search['vis_state'] = $vis;
				}
				$res    = $fb->list_page( $kind, $search, (int) $st['page'], self::timeout( $deadline ) );
				$items  = (array) $res['items'];
				$count += self::upsert_fb( $kind, $items, $ctx );
				$st['year_seen'] = (int) $st['year_seen'] + count( $items );
				if ( (int) $st['page'] >= max( 1, (int) $res['pages'] ) ) {
					if ( 0 === $vis ) {
						$st['vis']  = 2;
						$st['page'] = 1;
						self::save_state( $s );
						continue;
					}
					$st['vis']         = 0;
					$st['loaded_from'] = $y;
					if ( self::walk_done( $st, $y, (int) $st['year_seen'] ) ) {
						$st['done'] = true;
						// a history read whole under today's rules has nothing to re-derive
						if ( self::rules_version() === (string) $st['backfill_rules'] && null === $st['relabel_year'] ) {
							$st['relabel_v'] = self::rules_version();
						}
					} else {
						$st['year']      = $y - 1;
						$st['page']      = 1;
						$st['year_seen'] = 0;
					}
				} else {
					$st['page'] = (int) $st['page'] + 1;
				}
				self::save_state( $s ); // a pass the host stops keeps every page it finished
			}
		}
		$st['rows'] = self::store()->count( $kind );
		return $count;
	}

	/** Write one page of invoices or estimates, then hand the same page, untouched, to `zana_ledger_fb_items`. */
	public static function upsert_fb( string $kind, array $items, array $ctx = array() ): int {
		$n     = 0;
		$store = self::store();
		foreach ( $items as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$row = 'estimates' === $kind ? self::estimate_row( $doc, $ctx ) : self::invoice_row( $doc, $ctx );
			if ( $row && $store->upsert( $kind, $row ) ) {
				$n++;
			}
		}
		if ( $items && function_exists( 'do_action' ) ) {
			try {
				do_action( 'zana_ledger_fb_items', $kind, $items );
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Analytics ledger: a zana_ledger_fb_items listener failed (' . get_class( $e ) . '); the ledger carries on.' );
			}
		}
		return $n;
	}

	/** What the enricher needs once per pass: the rules, the salesperson codes people hold, the back-assignments. */
	public static function enrich_context(): array {
		if ( is_array( self::$context ) ) {
			return self::$context + array( 'rules' => ZANA_Ledger_Rules::get() );
		}
		$ctx = array(
			'rules'     => ZANA_Ledger_Rules::get(),
			'rep_codes' => class_exists( 'ZANA_Ledger_Access' ) ? array_keys( ZANA_Ledger_Access::rep_map() ) : array(),
		);
		if ( class_exists( 'ZCC_Rep_Overrides' ) && method_exists( 'ZCC_Rep_Overrides', 'get_code' ) ) {
			$ctx['override'] = array( 'ZCC_Rep_Overrides', 'get_code' );
		}
		return $ctx;
	}

	public static function invoice_row( array $inv, array $ctx = array() ): ?array {
		$id = (int) ( $inv['invoiceid'] ?? $inv['id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$rules  = $ctx['rules'] ?? ZANA_Ledger_Rules::get();
		$amount = self::money( $inv['amount'] ?? 0 );
		$out    = self::money( $inv['outstanding'] ?? 0 );
		$enr    = ZANA_Ledger_Enrich::document( $inv, $rules, $ctx );
		return array(
			'invoice_id'     => $id,
			'invoice_number' => substr( (string) ( $inv['invoice_number'] ?? '' ), 0, 40 ),
			'customer_id'    => (int) ( $inv['customerid'] ?? 0 ),
			'customer_name'  => self::cut( ZANA_Ledger_Enrich::customer_name( $inv ), 255 ),
			'city'           => self::cut( '' !== $enr['city'] ? $enr['city'] : ZANA_Ledger_Enrich::label( (string) ( $inv['city'] ?? '' ), true, $rules ), 120 ),
			'zip'            => substr( '' !== $enr['zip'] ? $enr['zip'] : (string) ( $inv['code'] ?? '' ), 0, 12 ),
			'inv_date'       => self::date( $inv['create_date'] ?? '' ),
			'due_date'       => self::due_date( $inv ),
			'paid_date'      => self::paid_date( $inv, $amount, $out ),
			'amount'         => $amount,
			'paid'           => round( max( 0, $amount - $out ), 2 ),
			'outstanding'    => $out,
			'status'         => self::invoice_status( $inv ),
			'pay_status'     => substr( (string) ( $inv['payment_status'] ?? ( $inv['v3_status'] ?? '' ) ), 0, 30 ),
			'lead_source'    => self::cut( $enr['lead_source'], 80 ),
			'rep_code'       => substr( $enr['rep'], 0, 20 ),
			'rep_inferred'   => 0,
			'mix'            => (string) json_encode( ZANA_Ledger_Enrich::mix( $inv['lines'] ?? array(), $rules ) ),
			'brands'         => (string) json_encode( (object) ZANA_Ledger_Enrich::brands( $inv['lines'] ?? array(), $rules ) ),
			'vis_state'      => (int) ( $inv['vis_state'] ?? 0 ),
			'fb_updated'     => self::datetime( $inv['updated'] ?? '' ),
			'synced_at'      => self::utc(),
		);
	}

	public static function estimate_row( array $est, array $ctx = array() ): ?array {
		$id = (int) ( $est['estimateid'] ?? $est['id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$rules  = $ctx['rules'] ?? ZANA_Ledger_Rules::get();
		$enr    = ZANA_Ledger_Enrich::document( $est, $rules, array_diff_key( $ctx, array( 'override' => 1 ) ) );
		$status = strtolower( (string) ( $est['ui_status'] ?? ( $est['display_status'] ?? '' ) ) );
		return array(
			'estimate_id'     => $id,
			'estimate_number' => substr( (string) ( $est['estimate_number'] ?? '' ), 0, 40 ),
			'customer_id'     => (int) ( $est['customerid'] ?? 0 ),
			'customer_name'   => self::cut( ZANA_Ledger_Enrich::customer_name( $est ), 255 ),
			'city'            => self::cut( '' !== $enr['city'] ? $enr['city'] : ZANA_Ledger_Enrich::label( (string) ( $est['city'] ?? '' ), true, $rules ), 120 ),
			'est_date'        => self::date( $est['create_date'] ?? '' ),
			'amount'          => self::money( $est['amount'] ?? 0 ),
			'status_label'    => substr( $status, 0, 30 ),
			'accepted'        => ( ! empty( $est['accepted'] ) || false !== strpos( $status, 'accept' ) ) ? 1 : 0,
			'invoiced'        => ( ! empty( $est['invoiced'] ) || ! empty( $est['invoices'] ) || false !== strpos( $status, 'invoiced' ) ) ? 1 : 0,
			'lead_source'     => self::cut( $enr['lead_source'], 80 ),
			'rep_code'        => substr( $enr['rep'], 0, 20 ),
			'mix'             => (string) json_encode( ZANA_Ledger_Enrich::mix( $est['lines'] ?? array(), $rules ) ),
			'brands'          => (string) json_encode( (object) ZANA_Ledger_Enrich::brands( $est['lines'] ?? array(), $rules ) ),
			'vis_state'       => (int) ( $est['vis_state'] ?? 0 ),
			'fb_updated'      => self::datetime( $est['updated'] ?? '' ),
			'synced_at'       => self::utc(),
		);
	}

	/**
	 * The ledger's invoice status: 0 = DRAFT (never counted as a sale), otherwise the billing system's own status code,
	 * with its 0 ("disputed") stored as 90 so it cannot be mistaken for a draft. The text status (v3_status /
	 * display_status) decides "draft" when present; without it, 0 and 1 both read as draft.
	 */
	public static function invoice_status( array $inv ): int {
		foreach ( array( 'v3_status', 'display_status' ) as $k ) {
			if ( isset( $inv[ $k ] ) && is_string( $inv[ $k ] ) && '' !== $inv[ $k ] ) {
				$code = (int) ( $inv['status'] ?? 2 );
				if ( 'draft' === strtolower( trim( $inv[ $k ] ) ) || 1 === $code ) {
					return 0;
				}
				return 0 === $code ? 90 : $code;
			}
		}
		$code = (int) ( $inv['status'] ?? 2 );
		return ( 0 === $code || 1 === $code ) ? 0 : $code;
	}

	/** An invoice's due date: its own, else its issue date plus its terms (none given: due on receipt). */
	public static function due_date( array $inv ): ?string {
		$due = self::date( $inv['due_date'] ?? '' );
		if ( null !== $due ) {
			return $due;
		}
		$issued = self::date( $inv['create_date'] ?? '' );
		if ( null === $issued ) {
			return null;
		}
		$days = is_numeric( $inv['due_offset_days'] ?? null ) ? max( 0, min( 3650, (int) $inv['due_offset_days'] ) ) : 0;
		return gmdate( 'Y-m-d', strtotime( $issued . ' 12:00:00 UTC +' . $days . ' days' ) );
	}

	/**
	 * The day an invoice was paid in full: the billing system's date_paid, kept only when nothing is left to pay. A
	 * draft, a $0 invoice, one still owing and one settled with no date (a credit) have none: never guessed.
	 */
	public static function paid_date( array $inv, $amount = null, $out = null ): ?string {
		$amount = null === $amount ? self::money( $inv['amount'] ?? 0 ) : (float) $amount;
		$out    = null === $out ? self::money( $inv['outstanding'] ?? 0 ) : (float) $out;
		if ( $amount <= 0.004 || $out > 0.004 || 0 === self::invoice_status( $inv ) ) {
			return null;
		}
		return self::date( $inv['date_paid'] ?? '' );
	}

	/* ─────────────────────────── billing: payments ─────────────────────────── */

	public static function payment_row( array $p ): ?array {
		$id = (int) ( $p['paymentid'] ?? $p['id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}
		return array(
			'payment_id' => $id,
			'invoice_id' => (int) ( $p['invoiceid'] ?? 0 ),
			'pay_date'   => self::date( $p['date'] ?? '' ),
			'amount'     => self::money( $p['amount'] ?? 0 ),
			'pay_type'   => substr( ZANA_Ledger_Rules::label( $p['type'] ?? '' ), 0, 40 ),
			'vis_state'  => (int) ( $p['vis_state'] ?? 0 ),
			'fb_updated' => self::datetime( $p['updated'] ?? '' ),
			'synced_at'  => self::utc(),
		);
	}

	protected static function upsert_payments( array $items ): int {
		$n     = 0;
		$store = self::store();
		foreach ( $items as $p ) {
			$row = is_array( $p ) ? self::payment_row( $p ) : null;
			if ( $row && $store->upsert( 'payments', $row ) ) {
				$n++;
			}
		}
		return $n;
	}

	protected static function sync_payments( $fb, array &$s, float $deadline ): int {
		$st    = &$s['payments'];
		$count = 0;

		// 1. what changed since the last pass
		if ( ! empty( $st['updated_since'] ) ) {
			$reserve = empty( $st['done'] ) ? 8 : 0;
			if ( empty( $st['inc_started'] ) ) {
				$st['inc_started'] = self::now();
				$st['inc_page']    = 1;
			}
			$complete = false;
			while ( self::time_left( $deadline, 2 + $reserve ) ) {
				$res    = $fb->list_page( 'payments', array( 'updated_min' => $st['updated_since'] ), (int) $st['inc_page'], self::timeout( $deadline - $reserve ) );
				$count += self::upsert_payments( (array) $res['items'] );
				if ( (int) $st['inc_page'] >= max( 1, (int) $res['pages'] ) ) {
					$complete = true;
					break;
				}
				$st['inc_page'] = (int) $st['inc_page'] + 1;
				self::save_state( $s );
			}
			if ( $complete ) {
				$st['updated_since'] = gmdate( 'Y-m-d', (int) $st['inc_started'] - DAY_IN_SECONDS );
				$st['caught_up_at']  = self::utc();
				$st['inc_started']   = 0;
				$st['inc_page']      = 1;
			}
		}

		// 2. the history, newest year first
		if ( empty( $st['done'] ) ) {
			if ( null === $st['year'] ) {
				$st['year']        = (int) gmdate( 'Y', self::now() );
				$st['page']        = 1;
				$st['year_seen']   = 0;
				$st['empty_years'] = 0;
				if ( empty( $st['updated_since'] ) ) {
					$st['updated_since'] = gmdate( 'Y-m-d', self::now() - DAY_IN_SECONDS );
				}
			}
			while ( empty( $st['done'] ) && self::time_left( $deadline ) ) {
				$y      = (int) $st['year'];
				$res    = $fb->list_page( 'payments', array( 'date_min' => $y . '-01-01', 'date_max' => $y . '-12-31' ), (int) $st['page'], self::timeout( $deadline ) );
				$items  = (array) $res['items'];
				$count += self::upsert_payments( $items );
				$st['year_seen'] = (int) $st['year_seen'] + count( $items );
				if ( (int) $st['page'] >= max( 1, (int) $res['pages'] ) ) {
					$st['loaded_from'] = $y;
					if ( self::walk_done( $st, $y, (int) $st['year_seen'] ) ) {
						$st['done'] = true;
					} else {
						$st['year']      = $y - 1;
						$st['page']      = 1;
						$st['year_seen'] = 0;
					}
				} else {
					$st['page'] = (int) $st['page'] + 1;
				}
				self::save_state( $s );
			}
		}

		// 3. once a day (UTC), years read again whole: this year, in January last year too, and one older year in
		// rotation. A payment the read did not return goes (deleted, or moved to another year).
		if ( ! empty( $st['done'] ) ) {
			$today = gmdate( 'Y-m-d', self::now() );
			if ( null === $st['reread_year'] && $today !== (string) $st['reread_on'] ) {
				$this_year = (int) gmdate( 'Y', self::now() );
				$first     = max( self::OLDEST_YEAR, (int) $st['loaded_from'] );
				$queue     = array( $this_year );
				if ( '01' === gmdate( 'm', self::now() ) && $this_year - 1 >= $first ) {
					$queue[] = $this_year - 1;
				}
				$older = null !== $st['reread_next'] ? (int) $st['reread_next'] : $this_year - 1;
				if ( $older >= $this_year || $older < $first ) {
					$older = $this_year - 1;
				}
				if ( in_array( $older, $queue, true ) ) {
					$older--;
				}
				if ( $older >= $first ) {
					$queue[]           = $older;
					$st['reread_next'] = $older - 1 >= $first ? $older - 1 : $this_year - 1;
				}
				$st['reread_queue']   = $queue;
				$st['reread_year']    = array_shift( $st['reread_queue'] );
				$st['reread_page']    = 1;
				$st['reread_started'] = self::utc();
			}
			while ( null !== $st['reread_year'] && self::time_left( $deadline ) ) {
				$y      = (int) $st['reread_year'];
				$res    = $fb->list_page( 'payments', array( 'date_min' => $y . '-01-01', 'date_max' => $y . '-12-31' ), (int) $st['reread_page'], self::timeout( $deadline ) );
				$count += self::upsert_payments( (array) $res['items'] );
				if ( (int) $st['reread_page'] >= max( 1, (int) $res['pages'] ) ) {
					self::store()->sweep_payments( $y, (string) $st['reread_started'] );
					if ( $y === (int) gmdate( 'Y', self::now() ) ) {
						$st['caught_up_at'] = self::utc();
					}
					$next = (array) $st['reread_queue'];
					if ( $next ) {
						$st['reread_year']    = array_shift( $next );
						$st['reread_queue']   = $next;
						$st['reread_page']    = 1;
						$st['reread_started'] = self::utc();
					} else {
						$st['reread_year'] = null;
						$st['reread_on']   = $today;
					}
				} else {
					$st['reread_page'] = (int) $st['reread_page'] + 1;
				}
				self::save_state( $s );
			}
		}
		$st['rows'] = self::store()->count( 'payments' );
		return $count;
	}

	/* ─────────────────────────── re-deriving the stored labels ─────────────────────────── */

	/** The rules the derived columns are written under: the rules' signature and the catalog's version. */
	public static function rules_version(): string {
		$cat = null !== self::$catalog_version ? (string) self::$catalog_version
			: ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'version' ) ? (string) ZDZ_Item_Engine::version() : '0' );
		return ZANA_Ledger_Rules::signature() . '.' . $cat;
	}

	/** Is a re-derive due? Only once a kind's history is loaded, and only while its derived columns are older than the rules. */
	public static function relabel_pending( array $s ): bool {
		$v = self::rules_version();
		foreach ( array( 'invoices', 'estimates' ) as $k ) {
			if ( ! empty( $s[ $k ]['done'] ) && $v !== (string) ( $s[ $k ]['relabel_v'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read every invoice and estimate again (newest year first, the default list then archived) and rewrite only the
	 * derived columns where they differ. Resumes at its own cursor; the copy reads as complete throughout.
	 */
	protected static function relabel_fb( $fb, array &$s, float $deadline, array $ctx ): int {
		$changed = 0;
		$v       = self::rules_version();
		$store   = self::store();
		foreach ( array( 'invoices', 'estimates' ) as $kind ) {
			$st = &$s[ $kind ];
			if ( empty( $st['done'] ) || $v === (string) $st['relabel_v'] ) {
				unset( $st );
				continue;
			}
			if ( null !== $st['relabel_year'] && $v !== (string) $st['relabel_target'] ) {
				$st['relabel_year'] = null; // the rules changed mid-walk: start again, so no year is left on the old ones
			}
			if ( null === $st['relabel_year'] ) {
				$st['relabel_target']  = $v;
				$st['relabel_error']   = '';
				$st['relabel_year']    = (int) gmdate( 'Y', self::now() );
				$st['relabel_page']    = 1;
				$st['relabel_vis']     = 0;
				$st['relabel_read']    = 0;
				$st['relabel_changed'] = 0;
			}
			$floor = max( self::OLDEST_YEAR, (int) ( $st['loaded_from'] ?? self::OLDEST_YEAR ) );
			while ( self::time_left( $deadline, 8 ) ) {
				$y      = (int) $st['relabel_year'];
				$vis    = (int) $st['relabel_vis'];
				$search = array( 'date_min' => $y . '-01-01', 'date_max' => $y . '-12-31' );
				if ( $vis ) {
					$search['vis_state'] = $vis;
				}
				try {
					$res = $fb->list_page( $kind, $search, (int) $st['relabel_page'], self::timeout( $deadline ) );
				} catch ( \Throwable $e ) {
					$st['relabel_error'] = substr( $e->getMessage(), 0, 300 );
					if ( preg_match( '/time(?:d)?\s?out|cURL error 28/i', $e->getMessage() ) ) {
						break 2; // a slow page is out of time, not a failure: the chain carries on from this cursor
					}
					throw $e;
				}
				$st['relabel_error'] = '';
				$want = array();
				foreach ( (array) $res['items'] as $doc ) {
					$row = is_array( $doc ) ? ( 'estimates' === $kind ? self::estimate_row( $doc, $ctx ) : self::invoice_row( $doc, $ctx ) ) : null;
					if ( $row ) {
						$want[ (int) reset( $row ) ] = self::derived_of( $kind, $row );
					}
				}
				if ( $want ) {
					$have = $store->derived( $kind, array_keys( $want ) );
					foreach ( $want as $id => $fields ) {
						// a document the copy does not hold yet is the sync's to add, not this walk's
						if ( isset( $have[ $id ] ) && self::differs( $have[ $id ], $fields ) && $store->update_derived( $kind, $id, $fields ) ) {
							$changed++;
							$st['relabel_changed'] = (int) $st['relabel_changed'] + 1;
						}
					}
					$st['relabel_read'] = (int) $st['relabel_read'] + count( $want );
				}
				if ( (int) $st['relabel_page'] >= max( 1, (int) $res['pages'] ) ) {
					if ( 0 === $vis ) {
						$st['relabel_vis']  = 2;
						$st['relabel_page'] = 1;
					} elseif ( $y <= $floor ) {
						$st['relabel_v']       = $v;
						$st['relabel_done_at'] = self::utc();
						$st['relabel_year']    = null;
						$st['relabel_vis']     = 0;
						$st['relabel_page']    = 1;
						self::save_state( $s );
						break;
					} else {
						$st['relabel_year'] = $y - 1;
						$st['relabel_vis']  = 0;
						$st['relabel_page'] = 1;
					}
				} else {
					$st['relabel_page'] = (int) $st['relabel_page'] + 1;
				}
				self::save_state( $s );
			}
			$unfinished = $v !== (string) $st['relabel_v'];
			unset( $st );
			if ( $unfinished ) {
				break; // out of time: estimates wait until invoices are done
			}
		}
		return $changed;
	}

	/** The derived columns of a row. */
	public static function derived_of( string $kind, array $row ): array {
		$keys = array( 'lead_source', 'brands', 'rep_code', 'city', 'mix' );
		if ( 'invoices' === $kind ) {
			$keys = array_merge( $keys, array( 'zip', 'due_date', 'paid_date' ) );
		}
		return array_intersect_key( $row, array_flip( $keys ) );
	}

	/** Does the held row differ from the wanted derived columns? (JSON compared as decoded data; dates as dates.) */
	public static function differs( array $have, array $want ): bool {
		foreach ( $want as $k => $v ) {
			$h = $have[ $k ] ?? null;
			if ( in_array( $k, array( 'mix', 'brands' ), true ) ) {
				if ( json_decode( (string) $h, true ) != json_decode( (string) $v, true ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- key order does not matter
					return true;
				}
				continue;
			}
			if ( in_array( $k, array( 'due_date', 'paid_date' ), true ) ) {
				if ( self::date( $h ) !== $v ) {
					return true;
				}
				continue;
			}
			if ( (string) $h !== (string) $v ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── the CRM: leads ─────────────────────────── */

	protected static function sync_leads( $ns, array &$s, float $deadline ): int {
		$st    = &$s['leads'];
		$count = 0;
		$store = self::store();

		// 1. new leads: newest first until one already held
		if ( (int) $st['max_id'] > 0 ) {
			for ( $page = 1; $page <= 20 && self::time_left( $deadline ); $page++ ) {
				$rows   = self::ns_page( $ns, $deadline, array( 'filter' => 2 ), $page, 'DESC' );
				$count += self::upsert_leads( $rows, $s );
				$min    = PHP_INT_MAX;
				foreach ( $rows as $l ) {
					$min = min( $min, (int) ( $l['id'] ?? 0 ) );
				}
				if ( count( $rows ) < 100 || $min <= (int) $st['max_id'] ) {
					break;
				}
			}
		}

		// 2. the history, oldest first
		while ( empty( $st['done'] ) && self::time_left( $deadline ) ) {
			$rows   = self::ns_page( $ns, $deadline, array( 'filter' => 2 ), (int) $st['page'], 'ASC' );
			$count += self::upsert_leads( $rows, $s );
			if ( count( $rows ) < 100 ) {
				$st['done'] = true;
			} else {
				$st['page'] = (int) $st['page'] + 1;
			}
			self::save_state( $s );
		}

		// 3. open leads, every few hours: their status is what changes
		if ( ! empty( $st['done'] ) && self::now() - (int) $st['open_refreshed'] >= self::OPEN_REFRESH_SECONDS ) {
			$complete = false;
			$t0       = self::now();
			for ( $page = 1; $page <= 50; $page++ ) {
				if ( ! self::time_left( $deadline ) ) {
					break;
				}
				$rows   = self::ns_page( $ns, $deadline, array( 'status' => 0, 'filter' => 2 ), $page, 'ASC' );
				$count += self::upsert_leads( $rows, $s );
				if ( count( $rows ) < 100 ) {
					$complete = true;
					break;
				}
			}
			if ( $complete ) {
				$st['open_refreshed'] = self::now();
				$store->close_vanished_open_leads( self::utc( $t0 ) );
			}
		}

		$notes_backfill = ! empty( $st['done'] ) && empty( $st['notes_done'] ) && 'get' !== $st['notes_via']
			&& (int) $st['notes_errors'] < self::NOTES_MAX_ERRORS && $store->notes_ready();

		// 4. a slow rolling re-read of everything (edits, re-opened leads), a few pages a pass
		if ( ! empty( $st['done'] ) && ! $notes_backfill ) {
			for ( $i = 0; $i < 3 && self::time_left( $deadline ); $i++ ) {
				$rows   = self::ns_page( $ns, $deadline, array( 'filter' => 2 ), (int) $st['roll_page'], 'ASC' );
				$count += self::upsert_leads( $rows, $s );
				$st['roll_page'] = count( $rows ) < 100 ? 1 : (int) $st['roll_page'] + 1;
			}
		}

		// 5-6. the notes: a failure here never fails the leads' sync (their last good sync and the chain are not its to stop)
		if ( ! empty( $st['done'] ) && $store->notes_ready() ) {
			try {
				self::sync_notes( $ns, $s, $deadline, $count );
				$st['notes_error']  = '';
				$st['notes_errors'] = 0;
			} catch ( \Throwable $e ) {
				$st['notes_error']  = substr( $e->getMessage(), 0, 300 );
				$st['notes_errors'] = (int) $st['notes_errors'] + 1;
				error_log( 'Zorderz Analytics ledger: the lead notes step failed (the leads sync is unaffected): ' . $e->getMessage() );
			}
		}

		$st['max_id'] = $store->max_lead_id();
		$st['rows']   = $store->count( 'leads' );
		return $count;
	}

	/** One CRM page, its timeout fitted to what is left of the pass. */
	protected static function ns_page( $ns, float $deadline, array $query, int $page, string $direction ): array {
		if ( property_exists( $ns, 'request_timeout' ) ) {
			$ns->request_timeout = self::timeout( $deadline );
		}
		return (array) $ns->find_leads_page( $query, $page, 100, 'id', $direction );
	}

	public static function lead_row( $l ): ?array {
		if ( ! is_array( $l ) ) {
			return null;
		}
		$id = (int) ( $l['id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$first = function ( $list ) {
			if ( empty( $list ) || ! is_array( $list ) ) {
				return '';
			}
			$f = reset( $list );
			return is_array( $f ) && is_string( $f['name'] ?? null ) ? (string) $f['name'] : '';
		};
		$assignee = ( ! empty( $l['assignee'] ) && is_array( $l['assignee'] ) && is_string( $l['assignee']['name'] ?? null ) ) ? $l['assignee']['name'] : '';
		$name     = $l['name'] ?? ( $l['description'] ?? '' );
		if ( is_array( $name ) ) {
			$name = implode( ' ', array_filter( array_map( 'strval', array_filter( $name, 'is_scalar' ) ) ) );
		}
		$value = $l['value'] ?? 0;
		$value = is_array( $value ) ? (float) ( $value['amount'] ?? 0 ) : (float) $value;
		$acct  = ( ! empty( $l['primaryAccount'] ) && is_array( $l['primaryAccount'] ) ) ? (int) ( $l['primaryAccount']['id'] ?? 0 ) : 0;
		return array(
			'lead_id'     => $id,
			'lead_number' => substr( (string) ( $l['number'] ?? '' ), 0, 40 ),
			'name'        => self::cut( (string) $name, 255 ),
			'created_at'  => self::datetime( $l['createdTime'] ?? '' ),
			'closed_at'   => self::datetime( $l['closedTime'] ?? '' ),
			'status'      => (int) ( $l['status'] ?? 0 ),
			'source'      => self::cut( $first( $l['sources'] ?? null ), 120 ),
			'channel'     => self::cut( $first( $l['channels'] ?? null ), 120 ),
			'assignee'    => self::cut( (string) $assignee, 120 ),
			'value'       => round( $value, 2 ),
			'account_id'  => $acct,
			'synced_at'   => self::utc(),
		);
	}

	/** How many leads in the last upsert_leads() carried notes with text (the notes step reads it). */
	public static $notes_found = 0;

	/**
	 * Write a page of leads. A full lead object that carries notes with text has them kept too (never in one-lead-at-a-
	 * time mode, where findLeads' lists are not trusted; and never to delete a note: only a full read is).
	 */
	public static function upsert_leads( array $rows, array $s = array() ): int {
		$store             = self::store();
		$n                 = 0;
		self::$notes_found = 0;
		$notes_ok          = $store->notes_ready() && 'get' !== (string) ( $s['leads']['notes_via'] ?? '' );
		foreach ( $rows as $l ) {
			$r = self::lead_row( $l );
			if ( ! $r ) {
				continue;
			}
			if ( $store->upsert( 'leads', $r ) ) {
				$n++;
			}
			if ( $notes_ok && isset( $l['notes'] ) && is_array( $l['notes'] ) ) {
				$keep = self::note_rows( $l['notes'] );
				self::$notes_found += count( $keep );
				if ( $keep ) {
					$store->store_notes( (int) $r['lead_id'], $keep, false, 'find', self::utc() );
				}
			}
		}
		return $n;
	}

	/* ─────────────────────────── the CRM: notes ─────────────────────────── */

	protected static function sync_notes( $ns, array &$s, float $deadline, int &$count ): void {
		$st    = &$s['leads'];
		$store = self::store();
		// 5. every lead once more, oldest first, keeping the notes the full objects carry (a page is only started with 10 s left)
		if ( empty( $st['notes_done'] ) && 'get' !== $st['notes_via'] ) {
			while ( self::time_left( $deadline, 10 ) ) {
				$rows   = self::ns_page( $ns, $deadline, array( 'filter' => 2 ), (int) $st['notes_page'], 'ASC' );
				$count += self::upsert_leads( $rows, $s );
				if ( $rows && self::$notes_found > 0 ) {
					$st['notes_via'] = 'find';
				} elseif ( $rows && '' === $st['notes_via'] ) {
					// No note on the page and nothing known yet: the full lead read decides.
					$probe = self::probe_notes( $ns, $rows, $deadline );
					if ( 'yes' === $probe ) {
						$st['notes_via'] = 'get';
						break;
					}
					if ( 'no' !== $probe ) {
						throw new RuntimeException( 'could not check whether the lead list carries notes (page ' . (int) $st['notes_page'] . '); next pass' );
					}
					$st['notes_via'] = 'find';
				}
				foreach ( $rows as $l ) {
					if ( is_array( $l ) && (int) ( $l['id'] ?? 0 ) > 0 && ( ! array_key_exists( 'notes', $l ) || ( is_array( $l['notes'] ) && ! $l['notes'] ) ) ) {
						$store->store_notes( (int) $l['id'], array(), false, 'find', self::utc() );
					}
				}
				$st['notes_errors'] = 0;
				if ( count( $rows ) < 100 ) {
					$st['notes_done'] = true;
					$st['notes_last'] = self::utc();
					break;
				}
				$st['notes_page'] = (int) $st['notes_page'] + 1;
				self::save_state( $s );
			}
		}
		// 6. one lead at a time: in 'get' mode leads never read, then stale ones; in 'find' mode, once its walk is done,
		// only the few never read. A lead the CRM cannot read is stamped 'miss' so it never blocks the rest.
		$get_mode = 'get' === $st['notes_via'];
		if ( method_exists( $ns, 'read_lead' ) && ( $get_mode || ! empty( $st['notes_done'] ) ) ) {
			$failed = null;
			foreach ( $store->leads_needing_notes( 60, false, $get_mode, self::now(), self::NOTES_REREAD_OPEN, self::NOTES_REREAD_ALL ) as $lid ) {
				if ( ! self::time_left( $deadline, 4 ) ) {
					break;
				}
				if ( property_exists( $ns, 'request_timeout' ) ) {
					$ns->request_timeout = self::timeout( $deadline );
				}
				try {
					$lead = $ns->read_lead( (int) $lid );
				} catch ( \Throwable $e ) {
					$store->mark_miss( (int) $lid, self::utc() );
					if ( false !== stripos( $e->getMessage(), 'RPC error' ) ) {
						continue; // the CRM answered: this lead cannot be read (merged, removed)
					}
					$failed = $e; // the CRM is busy, down or slow: stop here; only this lead waits a day
					break;
				}
				if ( is_array( $lead ) ) {
					$store->store_notes( (int) $lid, self::note_rows( isset( $lead['notes'] ) && is_array( $lead['notes'] ) ? $lead['notes'] : array() ), true, 'get', self::utc() );
				} else {
					$store->mark_miss( (int) $lid, self::utc() );
				}
			}
			if ( $failed ) {
				throw new RuntimeException( 'reading a lead: ' . $failed->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in sync_leads(): kept in the sync state and the error log, never printed
			}
			if ( $get_mode && empty( $st['notes_done'] ) && ! $store->leads_needing_notes( 1, true, true, self::now(), self::NOTES_REREAD_OPEN, self::NOTES_REREAD_ALL ) ) {
				$st['notes_done'] = true;
				$st['notes_last'] = self::utc();
			}
		}
	}

	/** Does the full lead read return notes with text for any of up to three of these leads? 'yes' | 'no' | 'unknown'. */
	protected static function probe_notes( $ns, array $rows, float $deadline ): string {
		if ( ! method_exists( $ns, 'read_lead' ) ) {
			return 'no';
		}
		$asked = 0;
		$want  = min( 3, count( $rows ) );
		foreach ( $rows as $l ) {
			$id = is_array( $l ) ? (int) ( $l['id'] ?? 0 ) : 0;
			if ( $id <= 0 ) {
				continue;
			}
			if ( $asked >= $want ) {
				break;
			}
			if ( ! self::time_left( $deadline, 4 ) ) {
				return 'unknown';
			}
			try {
				$full = $ns->read_lead( $id );
			} catch ( \Throwable $e ) {
				return 'unknown';
			}
			if ( ! is_array( $full ) ) {
				return 'unknown';
			}
			$asked++;
			if ( self::note_rows( (array) ( $full['notes'] ?? array() ) ) ) {
				return 'yes';
			}
		}
		return $asked >= $want ? 'no' : 'unknown';
	}

	/** Notes with text as rows { note_key, created_at, author, body } (access codes cut; long notes kept cut). */
	public static function note_rows( array $notes ): array {
		$out = array();
		foreach ( $notes as $n ) {
			$r = self::note_row( $n );
			if ( $r && ! isset( $out[ $r['note_key'] ] ) ) {
				$out[ $r['note_key'] ] = $r;
			}
		}
		return array_values( $out );
	}

	/** One CRM note as a row, or null when it carries no text. */
	public static function note_row( $n ): ?array {
		if ( is_string( $n ) ) {
			$n = array( 'note' => $n );
		}
		if ( ! is_array( $n ) ) {
			return null;
		}
		$body = '';
		foreach ( array( 'note', 'body', 'description', 'text' ) as $k ) {
			if ( isset( $n[ $k ] ) && is_string( $n[ $k ] ) && '' !== trim( $n[ $k ] ) ) {
				$body = $n[ $k ];
				break;
			}
		}
		if ( '' === $body && isset( $n['noteHtml'] ) && is_string( $n['noteHtml'] ) ) {
			$body = html_entity_decode( strip_tags( (string) preg_replace( '/<br\s*\/?>|<\/p>|<\/div>|<\/li>/i', "\n", $n['noteHtml'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $body ) );
		if ( '' === $body ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'cut' ) ) {
			$body = (string) ZDZ_Sealed::cut( $body ); // no access code in the copy
		}
		$id      = isset( $n['id'] ) && is_scalar( $n['id'] ) ? (string) preg_replace( '/[^0-9A-Za-z_-]/', '', (string) $n['id'] ) : '';
		$created = self::datetime( $n['createdTime'] ?? ( $n['date'] ?? '' ) );
		$author  = '';
		foreach ( array( 'user', 'creator' ) as $k ) {
			if ( isset( $n[ $k ] ) && is_array( $n[ $k ] ) && is_string( $n[ $k ]['name'] ?? null ) ) {
				$author = $n[ $k ]['name'];
				break;
			}
		}
		return array(
			'note_key'   => '' !== $id ? substr( $id, 0, 40 ) : 'h' . substr( md5( (string) $created . '|' . $body ), 0, 32 ),
			'created_at' => $created,
			'author'     => self::cut( $author, 120 ),
			'body'       => self::cut( $body, self::NOTE_MAX_CHARS ),
		);
	}

	/* ─────────────────────────── clients ─────────────────────────── */

	/** The billing client, or null when the billing system is not connected. */
	protected static function fb_client() {
		if ( null !== self::$fb ) {
			return self::$fb ?: null;
		}
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return null;
		}
		try {
			$fb = new ZDZ_Core_FreshBooks();
			return ( method_exists( $fb, 'list_page' ) && $fb->is_configured() ) ? $fb : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** The CRM client, or null when the CRM is not connected. */
	protected static function ns_client() {
		if ( null !== self::$ns ) {
			return self::$ns ?: null;
		}
		if ( ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return null;
		}
		try {
			$ns = new ZDZ_Core_Nutshell();
			if ( ! method_exists( $ns, 'find_leads_page' ) || ! $ns->is_configured() ) {
				return null;
			}
			$ns->request_timeout = self::PAGE_TIMEOUT;
			return $ns;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	protected static function timeout( float $deadline ): int {
		if ( is_callable( self::$clock ) ) {
			return self::PAGE_TIMEOUT;
		}
		return (int) max( 5, min( self::PAGE_TIMEOUT, floor( $deadline - microtime( true ) ) ) );
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	protected static function cut( $v, int $n ): string {
		$v = (string) $v;
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $n, 'UTF-8' ) : substr( $v, 0, $n );
	}

	public static function money( $v ): float {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return round( (float) $v, 2 );
	}

	public static function date( $v ): ?string {
		$v = substr( (string) $v, 0, 10 );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	public static function datetime( $v ): ?string {
		if ( null === $v || '' === (string) $v ) {
			return null;
		}
		$t = is_numeric( $v ) ? (int) $v : strtotime( (string) $v );
		return $t ? gmdate( 'Y-m-d H:i:s', $t ) : null;
	}

	/* ─────────────────────────── what the copy holds (no customer data) ─────────────────────────── */

	/** What the copy holds, for the settings panel and every report's freshness line. */
	public static function coverage(): array {
		$s   = self::state();
		$cov = array();
		foreach ( self::FB_KINDS as $k ) {
			$cov[ $k ] = array(
				'rows'         => (int) $s[ $k ]['rows'],
				'complete'     => ! empty( $s[ $k ]['done'] ),
				'loaded_from'  => $s[ $k ]['loaded_from'],
				'loading'      => empty( $s[ $k ]['done'] ) ? $s[ $k ]['year'] : null,
				'last_ok'      => $s[ $k ]['last_ok'],
				'caught_up_at' => $s[ $k ]['caught_up_at'],
				'last_error'   => '' !== (string) $s[ $k ]['last_error'],
				'relabelling'  => in_array( $k, array( 'invoices', 'estimates' ), true ) && ! empty( $s[ $k ]['done'] ) && self::rules_version() !== (string) $s[ $k ]['relabel_v'],
			);
		}
		$cov['leads'] = array(
			'rows'       => (int) $s['leads']['rows'],
			'complete'   => ! empty( $s['leads']['done'] ),
			'last_ok'    => $s['leads']['last_ok'],
			'last_error' => '' !== (string) $s['leads']['last_error'],
		);
		$store = self::store();
		$sum   = $store->notes_summary();
		$cov['notes'] = array(
			'leads_read' => (int) $sum['leads_read'],
			'notes'      => (int) $sum['notes'],
			'complete'   => ! empty( $s['leads']['notes_done'] ),
			'via'        => (string) $s['leads']['notes_via'],
			'last_ok'    => '' !== (string) $sum['last_write'] ? $sum['last_write'] : $s['leads']['last_ok'],
			'ready'      => $store->notes_ready(),
			'last_error' => '' !== (string) $s['leads']['notes_error'],
		);
		$cov['staging'] = self::staging();
		return $cov;
	}

	/**
	 * The invoice number for a billing invoice id ('' when the copy has no live row for it). The jobs app asks it for the
	 * job's purchase-order number (filter `zjob_order_job_invoice`): a Project's invoice reference holds the billing id.
	 */
	public static function invoice_number_for_id( int $id ): string {
		return $id > 0 ? self::store()->invoice_number_for_id( $id ) : '';
	}

	/** `zjob_order_job_invoice`: a billing invoice id the copy knows becomes its invoice number; anything else is kept. */
	public static function job_invoice_number( $num, $project = array() ) {
		$num = trim( (string) $num );
		if ( '' === $num || ! ctype_digit( $num ) ) {
			return $num;
		}
		try {
			$known = self::invoice_number_for_id( (int) $num );
		} catch ( \Throwable $e ) {
			return $num;
		}
		return '' !== $known ? $known : $num;
	}
}
