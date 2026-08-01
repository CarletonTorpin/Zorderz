<?php
/**
 * KPI Metrics — REST Endpoint for Live Dashboard Data
 *
 * Queries FreshBooks (invoices, estimates) and Nutshell CRM (leads)
 * to provide real-time KPI metrics for the owner/admin dashboard.
 *
 * Plugins can contribute additional metrics via the `zdz_kpi_metrics` filter.
 * Results are cached in a WordPress transient (15 min TTL by default).
 *
 * Endpoint: GET /zorderz/v1/kpi-metrics
 * Optional: ?force_refresh=1 to bypass cache
 *
 * @since  2.9.0 — Initial REST endpoint with FreshBooks + Nutshell data.
 * @since  2.10.1 — Added surveys_month, google_reviews, website_reviews.
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_KPI_Metrics {

	private static $instance = null;

	/** Cache TTL in seconds (15 minutes). */
	private int $cache_ttl = 900;

	/** Transient key for the cached metrics blob. */
	private string $cache_key = 'zdz_kpi_metrics_v1';

	/**
	 * Option key holding the last KNOWN-GOOD revenue values (persisted only when
	 * a FreshBooks fetch actually returns numbers). Used to serve "as of <time>"
	 * figures instead of a ⚠ "Tap to retry" tile when a later fetch fails.
	 * v2.21.0.
	 */
	private string $last_good_key = 'zdz_kpi_metrics_last_good';

	/**
	 * Single-flight lock transient + TTL. Guards the (expensive) rebuild so two
	 * simultaneous cold dashboard loads don't both hammer FreshBooks and risk a
	 * rate-limit that would blank both revenue tiles. v2.21.0.
	 */
	private string $lock_key = 'zdz_kpi_metrics_rebuild_lock';
	private int $lock_ttl = 15;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  REST Route Registration                                           */
	/* ------------------------------------------------------------------ */

	public function register_routes(): void {
		register_rest_route( 'zorderz/v1', '/kpi-metrics', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_kpi_metrics' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'force_refresh' => [
					'default'           => '0',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	public function check_permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// Only admin-level roles should see KPI data
		$user = wp_get_current_user();
		$role = ! empty( $user->roles ) ? $user->roles[0] : '';
		return class_exists( 'ZDZ_User_Roles' )
			? ZDZ_User_Roles::is_admin_role( $role )
			: in_array( $role, [ 'administrator', 'zdz_owner', 'zdz_admin' ], true );
	}

	/* ------------------------------------------------------------------ */
	/*  Main Handler                                                      */
	/* ------------------------------------------------------------------ */

	public function handle_kpi_metrics( WP_REST_Request $request ): WP_REST_Response {
		$force = $request->get_param( 'force_refresh' ) === '1';

		if ( ! $force ) {
			$cached = get_transient( $this->cache_key );
			if ( false !== $cached ) {
				return rest_ensure_response( [
					'success' => true,
					'data'    => $this->filter_financial_for_user( $cached, get_current_user_id() ),
					'cached'  => true,
				] );
			}
		}

		// v2.21.0: single-flight. If another request is already rebuilding the
		// metrics, don't fire a second concurrent FreshBooks pass (which could
		// trip a rate limit and blank both revenue tiles for everyone). Briefly
		// wait for the in-flight rebuild's result, then fall through only if it
		// never lands.
		if ( ! $force && get_transient( $this->lock_key ) ) {
			$waited = $this->wait_for_inflight_rebuild();
			if ( false !== $waited ) {
				return rest_ensure_response( [
					'success' => true,
					'data'    => $this->filter_financial_for_user( $waited, get_current_user_id() ),
					'cached'  => true,
				] );
			}
		}

		// Claim the rebuild lock (auto-expires after lock_ttl as a dead-man's
		// switch in case this request dies mid-build).
		set_transient( $this->lock_key, 1, $this->lock_ttl );

		$metrics = $this->collect_metrics();

		// Cache the UNFILTERED metrics (the cache is shared across users); the
		// per-user financial filter is applied on the way out, below, so a
		// cached admin blob is never served wholesale to a revenue-denied user.
		set_transient( $this->cache_key, $metrics, $this->cache_ttl );
		delete_transient( $this->lock_key );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $this->filter_financial_for_user( $metrics, get_current_user_id() ),
			'cached'  => false,
		] );
	}

	/**
	 * Briefly poll for an in-flight rebuild's cached result (single-flight
	 * follower path). Returns the cached metrics array once it appears, or false
	 * if the lock clears / times out without a result (caller then rebuilds).
	 *
	 * @since 2.21.0
	 * @return array|false
	 */
	private function wait_for_inflight_rebuild() {
		// Up to ~3s in 300ms steps — long enough to ride out a normal rebuild,
		// short enough not to hang the dashboard if the leader died.
		for ( $i = 0; $i < 10; $i++ ) {
			usleep( 300000 ); // 300ms
			$cached = get_transient( $this->cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
			if ( ! get_transient( $this->lock_key ) ) {
				// Leader finished without leaving a cache, or died — stop waiting.
				break;
			}
		}
		return false;
	}

	/**
	 * Strip dollar-valued KPIs for users who lack view_company_revenue.
	 *
	 * Defence in depth for the shared-kiosk role (and any future revenue-
	 * denied role): hiding the tiles in app.js stops them rendering, but the
	 * REST endpoint must also refuse the figures server-side. We consult the
	 * theme-level permission (the single source of truth — ZDZ_Data_Permissions),
	 * and when revenue is denied we drop the revenue metrics entirely rather
	 * than returning a number. Non-financial counts (estimates, leads,
	 * reviews, job counts) are unaffected — "counts only when denied."
	 *
	 * @param array $metrics Collected metric_key => data map.
	 * @param int   $user_id Current user.
	 * @return array Filtered metrics.
	 */
	private function filter_financial_for_user( array $metrics, int $user_id ): array {
		// If the permission layer isn't present, fail closed on revenue.
		$can_revenue = class_exists( 'ZDZ_Data_Permissions' )
			? ZDZ_Data_Permissions::can( $user_id, 'view_company_revenue' )
			: false;

		if ( $can_revenue ) {
			return $metrics;
		}

		// Revenue denied: remove every dollar-valued metric. Counts stay.
		$financial_keys = [ 'ytd_revenue', 'mtd_revenue' ];
		foreach ( $financial_keys as $k ) {
			unset( $metrics[ $k ] );
		}

		return $metrics;
	}

	/* ------------------------------------------------------------------ */
	/*  Metric Collection                                                 */
	/* ------------------------------------------------------------------ */

	private function collect_metrics(): array {
		// Date boundaries
		$year_start  = gmdate( 'Y-01-01' );
		$month_start = gmdate( 'Y-m-01' );
		$today       = gmdate( 'Y-m-d' );

		// ── FreshBooks Metrics ──
		$fb_metrics = $this->collect_freshbooks_metrics( $year_start, $month_start, $today );

		// ── Nutshell CRM Metrics ──
		$ns_metrics = $this->collect_nutshell_metrics();

		// ── Admin-entered review counts (v2.10.1) ──
		$google_reviews  = class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_google_reviews_count() : 0;
		$website_reviews = class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_website_reviews_count() : 0;

		// ── Assemble base metrics ──
		$metrics = array_merge(
			$fb_metrics,
			$ns_metrics,
			[
				// Plugin-provided placeholders — plugins fill via `zdz_kpi_metrics` filter
				'surveys_month'   => [ 'value' => '—', 'raw' => null, 'source' => 'plugin' ],

				// Admin-entered review counts (manual input via Zorderz Core Settings)
				'google_reviews'  => [
					'value'  => $google_reviews > 0 ? (string) $google_reviews : '—',
					'raw'    => $google_reviews,
					'source' => 'settings',
				],
				'website_reviews' => [
					'value'  => $website_reviews > 0 ? (string) $website_reviews : '—',
					'raw'    => $website_reviews,
					'source' => 'settings',
				],

				// Legacy key — plugins may still contribute this
				'reviews_total' => [ 'value' => '—', 'raw' => null, 'source' => 'plugin' ],

				// Manufacturing placeholders — future inventory/job-queue plugin
				'jobs_today'    => [ 'value' => '—', 'raw' => null, 'source' => 'plugin' ],
				'jobs_week'     => [ 'value' => '—', 'raw' => null, 'source' => 'plugin' ],
				'supply_status' => [ 'value' => '—', 'raw' => null, 'source' => 'plugin' ],
			]
		);

		/**
		 * Allow plugins to contribute or override KPI metrics.
		 *
		 * Each metric is an array: [ 'value' => string, 'raw' => mixed, 'source' => string ]
		 * - `value` : formatted display string shown in KPI cards (e.g. "$142.5K")
		 * - `raw`   : numeric value for any frontend computation
		 * - `source`: identifier for debugging ('freshbooks', 'nutshell', 'plugin')
		 *
		 * Example plugin hook:
		 *   add_filter( 'zdz_kpi_metrics', function( $m ) {
		 *       $count = wp_count_posts( 'zdz_survey_batch' )->publish ?? 0;
		 *       $m['surveys_week'] = [ 'value' => (string) $count, 'raw' => $count, 'source' => 'surveys' ];
		 *       return $m;
		 *   } );
		 *
		 * @since 2.9.0
		 * @param array $metrics Associative array of metric_key => data.
		 */
		$metrics = apply_filters( 'zdz_kpi_metrics', $metrics );

		return $metrics;
	}

	/* ------------------------------------------------------------------ */
	/*  FreshBooks Data                                                   */
	/* ------------------------------------------------------------------ */

	private function collect_freshbooks_metrics( string $year_start, string $month_start, string $today ): array {
		$fb = new ZDZ_Core_FreshBooks();

		if ( ! $fb->is_configured() ) {
			return [
				'ytd_revenue'   => [ 'value' => '—', 'raw' => null, 'source' => 'freshbooks', 'error' => 'not_configured' ],
				'mtd_revenue'   => [ 'value' => '—', 'raw' => null, 'source' => 'freshbooks', 'error' => 'not_configured' ],
				'estimates_mtd' => [ 'value' => '—', 'raw' => null, 'source' => 'freshbooks', 'error' => 'not_configured' ],
				'_fb_status'    => 'not_configured',
			];
		}

		// ── YTD Revenue: Sum of paid + partially paid invoices since Jan 1 ──
		$ytd = $this->sum_paid_invoices( $fb, $year_start, $today );

		// ── MTD Revenue: Sum of paid + partially paid invoices since 1st of month ──
		$mtd = $this->sum_paid_invoices( $fb, $month_start, $today );

		// ── Estimates MTD: Count of estimates created this month ──
		$est_count = $this->count_estimates( $fb, $month_start, $today );

		// v2.20.0: Determine FreshBooks connection health from results.
		// If BOTH YTD and MTD return null, the token is likely expired.
		$fb_status = 'ok';
		if ( null === $ytd && null === $mtd ) {
			$fb_status = 'api_error';
			error_log( 'ZDZ_KPI_Metrics: FreshBooks API appears down or token expired. Both YTD and MTD returned null.' );
		} elseif ( null === $ytd || null === $mtd ) {
			$fb_status = 'partial_error';
		}

		// v2.21.0: stale-on-error. The HTTP layer (ZDZ_Core_FreshBooks::api_request)
		// already refreshes-and-retries once on a 401, so a null here means a
		// non-auth failure (timeout, 5xx, malformed body, or token mid-refresh).
		// Rather than blank the tile to ⚠ and make the owner tap "retry", fall
		// back to the LAST KNOWN-GOOD value (persisted below only on success) and
		// label it "as of <time>". A real 0.0 is NOT null, so a genuine $0 period
		// is never overwritten by stale data. We deliberately do NOT force an
		// extra refresh_token() call on double-null: it would duplicate the 401
		// path and, because FreshBooks refresh tokens are single-use, a spurious
		// refresh during a transient timeout risks churning the token.
		$last_good = get_option( $this->last_good_key, [] );
		if ( ! is_array( $last_good ) ) {
			$last_good = [];
		}

		// Persist this run's good values (only the ones we actually got).
		$now_ts            = time();
		$updated_last_good = $last_good;
		if ( null !== $ytd ) {
			$updated_last_good['ytd'] = [ 'raw' => $ytd, 'at' => $now_ts ];
		}
		if ( null !== $mtd ) {
			$updated_last_good['mtd'] = [ 'raw' => $mtd, 'at' => $now_ts ];
		}
		if ( $updated_last_good !== $last_good ) {
			update_option( $this->last_good_key, $updated_last_good, false );
		}

		return [
			'ytd_revenue'   => $this->revenue_metric_with_fallback( $ytd, $last_good, 'ytd' ),
			'mtd_revenue'   => $this->revenue_metric_with_fallback( $mtd, $last_good, 'mtd' ),
			'estimates_mtd' => [
				'value'  => (string) $est_count,
				'raw'    => $est_count,
				'source' => 'freshbooks',
			],
			'_fb_status'    => $fb_status,
		];
	}

	/**
	 * Build a single revenue metric, applying last-good-on-error fallback.
	 *
	 * - Live value present (incl. a genuine 0.0) → use it, source 'freshbooks'.
	 * - Live value null but a stored last-good exists → serve the last-good
	 *   amount with `stale => true` and a human `as_of` string instead of ⚠,
	 *   so a transient FreshBooks hiccup self-heals without a manual retry.
	 * - Live value null and no last-good → the original ⚠ / api_unavailable.
	 *
	 * @since 2.21.0
	 * @param float|null $live      The freshly-fetched value (null on failure).
	 * @param array      $last_good The stored last-good map (pre-update).
	 * @param string     $slot      'ytd' | 'mtd'.
	 * @return array Metric descriptor for the KPI payload.
	 */
	private function revenue_metric_with_fallback( ?float $live, array $last_good, string $slot ): array {
		if ( null !== $live ) {
			return [
				'value'  => $this->format_currency( $live ),
				'raw'    => $live,
				'source' => 'freshbooks',
				'error'  => null,
			];
		}

		// Live fetch failed — try last-good.
		if ( isset( $last_good[ $slot ]['raw'] ) && null !== $last_good[ $slot ]['raw'] ) {
			$at = isset( $last_good[ $slot ]['at'] ) ? (int) $last_good[ $slot ]['at'] : 0;
			return [
				'value'   => $this->format_currency( (float) $last_good[ $slot ]['raw'] ),
				'raw'     => (float) $last_good[ $slot ]['raw'],
				'source'  => 'freshbooks',
				'error'   => null,
				'stale'   => true,
				'as_of'   => $at ? $this->human_as_of( $at ) : null,
				'as_of_ts' => $at ?: null,
			];
		}

		// No fallback available — preserve the original error behaviour.
		return [
			'value'  => '⚠',
			'raw'    => null,
			'source' => 'freshbooks',
			'error'  => 'api_unavailable',
		];
	}

	/**
	 * Render a "last good" timestamp as a short relative label
	 * (e.g. "as of 4 min ago", "as of 2 hr ago", "as of Apr 30"), in site TZ.
	 *
	 * @since 2.21.0
	 */
	private function human_as_of( int $ts ): string {
		$delta = max( 0, time() - $ts );
		if ( $delta < 60 ) {
			return 'as of just now';
		}
		if ( $delta < 3600 ) {
			return 'as of ' . (int) round( $delta / 60 ) . ' min ago';
		}
		if ( $delta < 86400 ) {
			return 'as of ' . (int) round( $delta / 3600 ) . ' hr ago';
		}
		return 'as of ' . wp_date( 'M j', $ts );
	}

	/**
	 * Sum collected revenue from all invoices in a date range.
	 *
	 * Revenue = invoice total − outstanding balance. This formula naturally
	 * handles every invoice status:
	 *   - Paid:      outstanding = 0 → revenue = full amount
	 *   - Partial:   revenue = collected portion only
	 *   - Unpaid:    outstanding = amount → revenue = $0
	 *   - Overdue:   outstanding = amount → revenue = $0
	 *   - Draft:     outstanding = amount → revenue = $0
	 *
	 * We query ALL invoices in the date range (no payment_status filter)
	 * because FreshBooks ignores `search[payment_status]` on the list
	 * endpoint — verified in production testing 2026-04-30. The math
	 * is self-correcting: unpaid invoices contribute $0 to the sum.
	 *
	 * Aligns with Brain Bot RULE #4 / RULE B: only actual collections
	 * (paid or partially paid) count as revenue.
	 *
	 * @since  2.14.4.1 — Removed broken payment_status filter; single query.
	 * @since  2.14.4   — Added 'partial' status + paid-portion math.
	 * @since  2.9.0    — Initial implementation.
	 */
	/**
	 * Sum collected revenue from all invoices in a date range.
	 *
	 * Revenue = invoice total − outstanding balance.
	 *
	 * v2.20.0: Returns NULL when the FreshBooks API is unreachable or returns
	 * an error. The caller uses this to distinguish "genuinely $0 revenue"
	 * from "API failed, we don't know the number." Displaying $0 when the
	 * API is down is actively misleading.
	 *
	 * @since  2.9.0
	 * @since  2.20.0 — Returns null on API failure instead of 0.0.
	 * @return float|null  Revenue total, or null if the API call failed.
	 */
	private function sum_paid_invoices( ZDZ_Core_FreshBooks $fb, string $start, string $end ): ?float {
		$total = 0.0;
		$page  = 1;
		$pages = 1;
		$inv_count = 0;
		$api_ok = false; // v2.20.0: Track whether we got at least one valid response

		do {
			$response = $fb->get_invoices( [
				'search[date_min]' => $start,
				'search[date_max]' => $end,
				'per_page'         => 100,
				'page'             => $page,
			] );

			if ( $page === 1 ) {
				if ( empty( $response ) ) {
					error_log( "ZDZ_KPI_Metrics: FreshBooks returned NULL for invoices ({$start} to {$end}). Token may be expired." );
					return null; // API failure — don't display $0
				} elseif ( ! isset( $response['response']['result'] ) ) {
					error_log( "ZDZ_KPI_Metrics: FreshBooks response missing 'result' key for {$start} to {$end}. Possible auth error." );
					return null;
				} elseif ( empty( $response['response']['result']['invoices'] ) ) {
					// Zero invoices is a valid result (e.g., start of year with no sales yet)
					$api_ok = true;
					error_log( "ZDZ_KPI_Metrics: FreshBooks returned 0 invoices for {$start} to {$end}. This may be correct (new period)." );
				} else {
					$api_ok = true;
				}
			}

			$invoices = $response['response']['result']['invoices'] ?? [];
			if ( empty( $invoices ) ) {
				break;
			}

			foreach ( $invoices as $inv ) {
				$inv_amount    = (float) ( $inv['amount']['amount'] ?? '0' );
				$inv_remaining = (float) ( $inv['outstanding']['amount'] ?? '0' );
				$collected     = $inv_amount - $inv_remaining;

				if ( $collected > 0.0 ) {
					$total += $collected;
					$inv_count++;
				}
			}

			$pages = (int) ( $response['response']['result']['pages'] ?? 1 );
			$page++;

		} while ( $page <= $pages );

		if ( ! $api_ok ) {
			return null;
		}

		error_log( "ZDZ_KPI_Metrics: sum_paid_invoices({$start}, {$end}) = \${$total} from {$inv_count} paid invoices." );
		return round( $total, 2 );
	}

	/**
	 * Count estimates created within a date range.
	 * Uses per_page=1 to read just the `total` from pagination metadata.
	 */
	private function count_estimates( ZDZ_Core_FreshBooks $fb, string $start, string $end ): int {
		$response = $fb->get_estimates( [
			'search[date_min]' => $start,
			'search[date_max]' => $end,
			'per_page'         => 1,
		] );

		return (int) ( $response['response']['result']['total'] ?? 0 );
	}

	/* ------------------------------------------------------------------ */
	/*  Nutshell CRM Data                                                 */
	/* ------------------------------------------------------------------ */

	private function collect_nutshell_metrics(): array {
		$ns = new ZDZ_Core_Nutshell();

		if ( ! $ns->is_configured() ) {
			return [
				'new_leads'        => [ 'value' => '—', 'raw' => null, 'source' => 'nutshell', 'error' => 'not_configured' ],
				'leads_contacted'  => [ 'value' => '—', 'raw' => null, 'source' => 'nutshell', 'error' => 'not_configured' ],
				'leads_to_jobs'    => [ 'value' => '—', 'raw' => null, 'source' => 'nutshell', 'error' => 'not_configured' ],
			];
		}

		// ── Open leads (new / in-progress) ──
		$open_leads = $ns->find_leads( [
			'query' => [ 'status' => 0 ],   // 0 = Open
			'limit' => 200,
		] );
		$open_count = is_array( $open_leads ) ? count( $open_leads ) : 0;

		// ── Won leads (became jobs) ──
		$won_leads = $ns->find_leads( [
			'query' => [ 'status' => 1 ],   // 1 = Won
			'limit' => 200,
		] );
		$won_count = is_array( $won_leads ) ? count( $won_leads ) : 0;

		// ── "Contacted" ≈ open leads that have been moved past the first stage ──
		// Approximate: leads with a non-null `lastContactedDate` or a milestone > stage 0.
		// For now we'll count open leads that have at least one activity note
		// (the Lead Generator and Survey plugins create notes when leads are contacted).
		$contacted_count = 0;
		if ( is_array( $open_leads ) ) {
			foreach ( $open_leads as $lead ) {
				// Nutshell lead stubs include `lastContactedDate`
				if ( ! empty( $lead['lastContactedDate'] ) ) {
					$contacted_count++;
				}
			}
		}

		return [
			'new_leads'       => [ 'value' => (string) $open_count, 'raw' => $open_count, 'source' => 'nutshell' ],
			'leads_contacted' => [ 'value' => (string) $contacted_count, 'raw' => $contacted_count, 'source' => 'nutshell' ],
			'leads_to_jobs'   => [ 'value' => (string) $won_count, 'raw' => $won_count, 'source' => 'nutshell' ],
		];
	}

	/* ------------------------------------------------------------------ */
	/*  Formatting Helpers                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Format a dollar amount for compact display.
	 *   ≥ 1M  → "$1.24M"
	 *   ≥ 1K  → "$142.5K"
	 *   else  → "$850"
	 */
	private function format_currency( float $amount ): string {
		if ( $amount >= 1000000 ) {
			return '$' . number_format( $amount / 1000000, 2 ) . 'M';
		}
		if ( $amount >= 1000 ) {
			return '$' . number_format( $amount / 1000, 1 ) . 'K';
		}
		return '$' . number_format( $amount, 0 );
	}

	/* ------------------------------------------------------------------ */
	/*  Cache Invalidation                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Flush the KPI cache.  Call after major data changes
	 * (e.g. estimate created, invoice paid, lead status change).
	 *
	 * Usage: ZDZ_KPI_Metrics::flush_cache();
	 */
	public static function flush_cache(): void {
		delete_transient( 'zdz_kpi_metrics_v1' );
	}
}
