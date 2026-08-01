<?php
/**
 * ZL Lead Generator — Core business logic engine.
 *
 * ARCHITECTURE ROLE:
 * This is the central engine of the Zorderz Leads module. It orchestrates
 * the 8-step lead generation pipeline called by AJAX handlers in class-zl-dashboard.php.
 * It interacts with FreshBooks (for invoice data), Nutshell CRM (for enrichment and lead creation),
 * and Poe AI/Gemini (for filtering, validation, and summarization).
 *
 * BUSINESS CONTEXT:
 * Built for the business.
 * - Extracts past customers from FreshBooks paid invoices.
 * - Enriches them with Nutshell CRM data (custom fields, territory).
 * - Filters strictly by territory (each salesperson owns one or more territory codes).
 * - Uses a 3-layer AI product filtering system to find specific product buyers.
 * - Scores leads deterministically (max 100) based on recency, value, breadth, etc.
 * - Excludes commercial entities and enforces a cooldown period (default 90 days).
 *
 * KEY APIS:
 * - ZL_FreshBooks: Fetches paid invoices and client details.
 * - ZL_Nutshell: Searches contacts, extracts custom fields, creates leads/notes.
 * - ZL_Poe_Client: Calls Gemini-3.1-Pro (thinking_budget=32768, web_search=true for complex tasks; lightweight for refinement).
 *
 * @since 1.0.0
 * @since 1.2.0 Added city/zip, spend, demographic filters; comprehensive lead notes;
 *              product count inference; AI name classification; expanded Nutshell enrichment.
 * @since 1.2.1 Split AI expansion into a separate AJAX step to fix 502 errors.
 * @since 1.3.0 Added Nutshell sync, phone cascade (mob→home→bus→street2→Nutshell fallback),
 *              fixed entityType case sensitivity, consolidated notes, string note format.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Lead_Generator {

	/** 
	 * @var ZL_FreshBooks 
	 * Client for FreshBooks REST API (OAuth 2.0). Used for invoices and client details.
	 */
	private $fb;

	/** 
	 * @var ZL_Nutshell 
	 * Client for Nutshell CRM JSON-RPC 2.0 API. Used for contacts, leads, and notes.
	 */
	private $ns;

	/** 
	 * @var ZL_Poe_Client|null 
	 * Client for Poe AI API (OpenAI-compatible). Used for Gemini-3.1-Pro AI features.
	 */
	private $poe;

	/**
	 * @var array
	 * Plugin settings loaded from wp_options (leads_per_batch, lookback_days, etc.).
	 */
	private $settings;

	/**
	 * v1.8.0 — Per-chunk Nutshell lookup cache, keyed by lowercased email.
	 * Populated by {@see prime_nutshell_cache_from_emails()} in a bulk
	 * pre-pass BEFORE the normal enrichment loop runs. Each value is the
	 * shape returned by {@see ZL_Nutshell::bulk_search_contacts()}:
	 *
	 *   array(
	 *     'contact_id' => int|null,
	 *     'raw'        => array|null,   // the search result body
	 *     'error'      => string,       // set when status=0 / 5xx / body unparseable
	 *     'status'     => int,          // HTTP status from the search call
	 *   )
	 *
	 * Null key never appears — only successfully-deduped emails go in.
	 * Cleared between chunks via {@see clear_batch_caches()}.
	 *
	 * @since 1.8.0
	 * @var array<string,array>
	 */
	private $ns_email_cache = array();

	/**
	 * v1.8.0 — Per-chunk FreshBooks client cache, keyed by customer_id.
	 * Because the pre-pass needs the email to bulk Nutshell, and the main
	 * enrich_customer() call needs the full client, we fetch once and hand
	 * the cached value to enrich_customer() via get_fb_client_cached().
	 *
	 * @since 1.8.0
	 * @var array<string,array|null>
	 */
	private $fb_client_cache = array();

	/**
	 * v1.8.0 — 429-aware concurrency cap for subsequent AI calls in this batch.
	 * Starts at TSLG_POE_PARALLEL (default 4); on the first 429 response this
	 * is halved (min 1). Resets per batch via {@see clear_batch_caches()}.
	 *
	 * @since 1.8.0
	 * @var int
	 */
	private $poe_cap = 4;

	/**
	 * v1.8.0 — Optional progress callback. When set by ajax_enrich_chunk()
	 * via {@see set_progress_callback()}, deep helpers (bulk Nutshell,
	 * parallel AI) invoke this after each completed sub-request so the
	 * frontend's progress bar keeps ticking inside a single AJAX call.
	 *
	 * Signature: fn( string $stage_key, int $current, int $total ): void
	 *
	 * @since 1.8.0
	 * @var callable|null
	 */
	private $progress_cb = null;

	/**
	 * Get the current WP user's display initials for AI prompt context.
	 *
	 * v1.6.0 — Theme v2.3.0 recommends including user context in AI prompts
	 * so the AI can reference the operator by initials in summaries and notes.
	 *
	 * @since 1.6.0
	 * @return string User initials (e.g. "JS") or "System" if not logged in.
	 */
	public static function get_user_initials() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return 'System';
		}
		$first = trim( $user->first_name );
		$last  = trim( $user->last_name );
		if ( ! empty( $first ) && ! empty( $last ) ) {
			return strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );
		}
		// Fallback to display_name initials
		$parts = preg_split( '/\s+/', trim( $user->display_name ), 2 );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[1], 0, 1 ) );
		}
		return strtoupper( mb_substr( $user->display_name ?: $user->user_login, 0, 2 ) );
	}

	/**
	 * Constructor.
	 * Initializes the class and loads settings from the database.
	 */
	public function __construct() {
		$this->settings = $this->load_settings();
	}

	/**
	 * Load all plugin settings from wp_options.
	 * 
	 * Retrieves salespeople configurations, excluded companies, and batch settings.
	 * 
	 * @return array Associative array of plugin settings.
	 */
	private function load_settings() {
		// Roster resolves through ZDZ_Party (never a local roster constant/seed).
		$sp = function_exists( 'zl_salespeople' ) ? zl_salespeople() : array();

		$excluded_raw = strtolower( get_option( 'zl_excluded_companies', '' ) );
		$excluded     = array_filter( array_map( 'trim', explode( "\n", $excluded_raw ) ) );

		return array(
			'leads_per_batch'    => (int) get_option( 'zl_leads_per_batch', 50 ),
			'lookback_days'      => (int) get_option( 'zl_lookback_days', 730 ),
			'cooldown_days'      => (int) get_option( 'zl_cooldown_days', 90 ),
			'salespeople'        => $sp,
			'excluded_companies' => $excluded,
			'ai_model'           => get_option( 'zl_ai_model', 'Gemini-3.1-Pro' ),
		);
	}

	// ═══════════════════════════════════════════════════════════════════
	// v1.8.0 — BATCH CACHES + PROGRESS PLUMBING
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Install a progress callback for deep helpers (bulk Nutshell + parallel
	 * AI) to invoke during long-running sub-operations inside a single AJAX
	 * call. The callback signature is fn($stage_key, $current, $total).
	 *
	 * Typical wiring in ajax_enrich_chunk:
	 *
	 *     $gen->set_progress_callback( function ( $stage, $cur, $tot ) use ( $batch_id ) {
	 *         ZL_Progress::stage( $batch_id, "Processing {$stage}", $stage, $tot );
	 *         ZL_Progress::advance( $batch_id, 0 ); // heartbeat only
	 *     } );
	 *
	 * @since 1.8.0
	 * @param callable|null $cb
	 * @return void
	 */
	public function set_progress_callback( $cb ) {
		$this->progress_cb = is_callable( $cb ) ? $cb : null;
	}

	/**
	 * Return the Nutshell client. Used by ajax_enrich_chunk to issue the
	 * bulk pre-fetch without reaching inside this class.
	 *
	 * @since 1.8.0
	 * @return ZL_Nutshell|null
	 */
	public function get_ns() {
		return $this->ns;
	}

	/**
	 * Return the Poe client. Used for parallel classification batching.
	 *
	 * @since 1.8.0
	 * @return ZL_Poe_Client|null
	 */
	public function get_poe() {
		return $this->poe;
	}

	/**
	 * Return the current 429-aware Poe concurrency cap.
	 *
	 * @since 1.8.0
	 * @return int
	 */
	public function get_poe_cap() {
		return max( 1, (int) $this->poe_cap );
	}

	/**
	 * Halve the Poe concurrency cap for the remainder of the current batch.
	 * Called after the first 429 response. Floor is 1 (not 0 — we still
	 * need to make progress, just slower).
	 *
	 * Trap 3 from speed prompt — NOT permanent; cleared in clear_batch_caches().
	 *
	 * @since 1.8.0
	 * @return int The new cap.
	 */
	public function halve_poe_cap() {
		$this->poe_cap = max( 1, (int) floor( $this->poe_cap / 2 ) );
		return $this->poe_cap;
	}

	/**
	 * Clear all per-batch caches. Called by dashboard between chunks when
	 * we want a fresh snapshot, or at batch start.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public function clear_batch_caches() {
		$this->ns_email_cache  = array();
		$this->fb_client_cache = array();
		$this->poe_cap         = (int) apply_filters( 'zlg_poe_parallel', 4 );
	}

	/**
	 * FreshBooks client lookup with an in-memory per-request cache. Both
	 * the Nutshell pre-pass and the subsequent per-customer enrich_customer
	 * call need the FB client; without caching we'd pay double.
	 *
	 * @since 1.8.0
	 * @param string $customer_id
	 * @return array|null Same shape as $this->fb->get_client().
	 */
	public function get_fb_client_cached( $customer_id ) {
		$cid = (string) $customer_id;
		if ( array_key_exists( $cid, $this->fb_client_cache ) ) {
			return $this->fb_client_cache[ $cid ];
		}
		try {
			$this->fb_client_cache[ $cid ] = $this->fb ? $this->fb->get_client( $cid ) : null;
		} catch ( \Throwable $e ) {
			error_log( 'ZL v1.8.0: FB get_client failed for cid=' . $cid . ': ' . $e->getMessage() );
			$this->fb_client_cache[ $cid ] = null;
		}
		return $this->fb_client_cache[ $cid ];
	}

	/**
	 * Prime the per-chunk Nutshell cache by bulk-searching the provided
	 * emails in parallel. Implementation uses ZL_Nutshell::bulk_search_contacts
	 * which itself rides on ZL_Parallel_Dispatch (curl_multi).
	 *
	 * ──────────────────────────────────────────────────────────────────
	 * IMPROVEMENT A — BATCHED DEDUP PRE-FETCH
	 * ──────────────────────────────────────────────────────────────────
	 * Before v1.8.0, enrich_from_nutshell() issued one HTTP round-trip per
	 * customer sequentially. A 25-customer chunk could take 25×0.8s = 20s
	 * just on Nutshell email lookups. With this pre-pass, the same 25
	 * lookups run concurrently (cap=8) and complete in ~3–4s.
	 *
	 * @since 1.8.0
	 * @param string[] $emails
	 * @param int      $cap    Concurrency cap, default 8 (NS limit).
	 * @return int             Number of unique emails actually fetched.
	 */
	public function prime_nutshell_cache_from_emails( array $emails, $cap = 8 ) {
		if ( empty( $emails ) || ! $this->ns ) {
			return 0;
		}

		// Normalize + dedup emails locally — so we don't pay for already-cached.
		$to_fetch = array();
		foreach ( $emails as $e ) {
			$norm = strtolower( trim( (string) $e ) );
			if ( $norm === '' || strpos( $norm, '@' ) === false ) continue;
			if ( isset( $this->ns_email_cache[ $norm ] ) ) continue;
			$to_fetch[ $norm ] = true;
		}
		$to_fetch = array_keys( $to_fetch );
		if ( empty( $to_fetch ) ) {
			return 0;
		}

		$progress_cb = $this->progress_cb;
		$on_prog = is_callable( $progress_cb )
			? function ( $id, $res, $done, $total ) use ( $progress_cb ) {
				try {
					call_user_func( $progress_cb, 'nutshell_bulk', $done, $total );
				} catch ( \Throwable $e ) { /* noop */ }
			}
			: null;

		try {
			$results = $this->ns->bulk_search_contacts( $to_fetch, $cap, $on_prog );
		} catch ( \Throwable $e ) {
			error_log( 'ZL v1.8.0: bulk_search_contacts failed (fallback to serial): ' . $e->getMessage() );
			return 0;
		}

		foreach ( $results as $email_lower => $res ) {
			$this->ns_email_cache[ $email_lower ] = is_array( $res ) ? $res : array(
				'contact_id' => null,
				'raw'        => null,
			);
		}
		return count( $results );
	}

	/**
	 * Look up a pre-fetched Nutshell search result from the per-chunk cache.
	 *
	 * @since 1.8.0
	 * @param string $email
	 * @return array|null Cache entry or null if not primed.
	 */
	public function get_cached_nutshell_by_email( $email ) {
		$norm = strtolower( trim( (string) $email ) );
		return $this->ns_email_cache[ $norm ] ?? null;
	}

	/**
	 * Initialize API clients from settings / shared credentials.
	 *
	 * Attempts to load credentials for FreshBooks, Nutshell, and Poe AI.
	 * Includes fallback logic to share credentials with the TS Satisfaction Surveys plugin.
	 *
	 * @throws Exception If required credentials (FreshBooks or Nutshell) are missing.
	 */
	public function init_clients() {
		// ── FreshBooks ───────────────────────────────────────
		// Retrieve shared options, falling back to TS Surveys plugin if needed
		$fb_client_id     = ZL_Admin::get_shared_option( 'zl_fb_client_id', 'ts_surveys_fb_client_id' );
		$fb_client_secret = ZL_Admin::decrypt_shared( 'zl_fb_client_secret', 'ts_surveys_fb_client_secret' );
		$fb_account_id    = ZL_Admin::get_shared_option( 'zl_fb_account_id', 'ts_surveys_fb_account_id' );
		$fb_access_token  = ZL_Admin::get_shared_option( 'zl_fb_access_token', 'ts_surveys_fb_access_token' );

		if ( empty( $fb_access_token ) ) {
			throw new Exception( 'FreshBooks is not connected. Please connect FreshBooks in Settings.' );
		}

		$this->fb = new ZL_FreshBooks( $fb_client_id, $fb_client_secret, $fb_account_id );
		$this->fb->set_access_token( $fb_access_token );

		// ── Nutshell ────────────────────────────────────────
		$ns_email   = ZL_Admin::get_shared_option( 'zl_ns_email', 'ts_surveys_ns_email' );
		$ns_api_key = ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'ts_surveys_ns_api_key' );

		if ( empty( $ns_email ) || empty( $ns_api_key ) ) {
			throw new Exception( 'Nutshell CRM is not configured. Please add credentials in Settings.' );
		}

		$this->ns = new ZL_Nutshell( $ns_email, $ns_api_key );

		// ── Poe AI (optional) ───────────────────────────────
		// Try multiple approaches to find the Poe API key
		$poe_api_key = '';

		// 1. Try our own option (decrypted)
		$poe_api_key = ZL_Admin::decrypt_shared( 'zl_poe_api_key', 'ts_surveys_poe_api_key' );

		// 2. If empty, try common survey-plugin option names (encrypted)
		if ( empty( $poe_api_key ) ) {
			$survey_key_names = array(
				'ts_surveys_poe_api_key',
				'ts_surveys_poe_key',
				'ts_survey_poe_api_key',
				'tss_poe_api_key',
			);
			foreach ( $survey_key_names as $opt_name ) {
				$raw = get_option( $opt_name, '' );
				if ( ! empty( $raw ) ) {
					// Try decrypting first
					$decrypted = ZL_Admin::decrypt_shared( '__zl_skip__', $opt_name );
					if ( ! empty( $decrypted ) && strlen( $decrypted ) > 10 ) {
						$poe_api_key = $decrypted;
						break;
					}
					// If decryption returned garbage or empty, maybe it's stored plain
					if ( strlen( $raw ) > 20 && substr( $raw, 0, 3 ) !== 'U2F' ) {
						// Looks like a plain-text API key (not base64-ish encrypted)
						$poe_api_key = $raw;
						break;
					}
				}
			}
		}

		// 3. If still empty, try the raw option value (might be stored unencrypted)
		if ( empty( $poe_api_key ) ) {
			$raw_own    = get_option( 'zl_poe_api_key', '' );
			$raw_survey = get_option( 'ts_surveys_poe_api_key', '' );
			$raw_val    = ! empty( $raw_own ) ? $raw_own : $raw_survey;
			if ( ! empty( $raw_val ) && strlen( $raw_val ) > 10 ) {
				$poe_api_key = $raw_val;
			}
		}

		if ( ! empty( $poe_api_key ) ) {
			$this->poe = new ZL_Poe_Client( $poe_api_key, $this->settings['ai_model'] );
			error_log( 'ZL: Poe AI client initialized with model: ' . $this->settings['ai_model'] );
		} else {
			error_log( 'ZL: Poe API key not found — AI features disabled. Check Settings > Poe AI.' );
		}
	}

	/**
	 * Initialize ONLY the Nutshell client.
	 *
	 * Used for operations that don't need FreshBooks (e.g., sending test leads
	 * to Nutshell after generation). Avoids FreshBooks auth failures blocking
	 * Nutshell-only operations.
	 *
	 * @throws Exception If Nutshell credentials are missing.
	 */
	public function init_nutshell_only() {
		$ns_email   = ZL_Admin::get_shared_option( 'zl_ns_email', 'ts_surveys_ns_email' );
		$ns_api_key = ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'ts_surveys_ns_api_key' );

		if ( empty( $ns_email ) || empty( $ns_api_key ) ) {
			throw new Exception( 'Nutshell CRM is not configured. Please add credentials in Settings.' );
		}

		$this->ns = new ZL_Nutshell( $ns_email, $ns_api_key );
		error_log( 'ZL: Nutshell-only client initialized for ' . $ns_email );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 1 — Fetch invoices from FreshBooks, group by customer ID
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Fetch paid invoices from FreshBooks and group them by customer ID.
	 *
	 * @param int|null $lookback_days Override the configured lookback period.
	 * @return array Associative array keyed by customerid, values are invoice arrays.
	 */
	public function fetch_and_group_invoices( $lookback_days = null ) {
		if ( $lookback_days === null ) {
			$lookback_days = $this->settings['lookback_days'];
		}

		$invoices = $this->fb->get_paid_invoices( $lookback_days );
		$grouped  = array();

		foreach ( $invoices as $inv ) {
			$cid = isset( $inv['customerid'] ) ? (string) $inv['customerid'] : '';
			if ( empty( $cid ) ) {
				continue;
			}
			if ( ! isset( $grouped[ $cid ] ) ) {
				$grouped[ $cid ] = array();
			}
			$grouped[ $cid ][] = $inv;
		}

		return $grouped;
	}

	/**
	 * Fetch a SINGLE PAGE of paid invoices from FreshBooks.
	 *
	 * Added in v1.2.3 — Public wrapper for the private $fb client's paginated method.
	 * Called by ajax_fetch_invoices() to retrieve one page at a time, preventing
	 * web server proxy timeouts on long lookback periods.
	 *
	 * @param int $lookback_days Days to look back. Null = use configured default.
	 * @param int $page          Page number (1-based).
	 * @param int $per_page      Invoices per page (default 100).
	 * @return array { invoices, page, total_pages, total }
	 */
	public function fetch_invoices_page( $lookback_days = null, $page = 1, $per_page = 100, $skip_lines = false ) {
		if ( $lookback_days === null ) {
			$lookback_days = $this->settings['lookback_days'];
		}
		return $this->fb->get_paid_invoices_page( $lookback_days, $page, $per_page, $skip_lines );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 2 — Enrich a single customer with FB client + Nutshell data
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Enrich a customer with FreshBooks client details and Nutshell CRM data.
	 * 
	 * Applies auto-exclusion for commercial entities and excluded companies.
	 * Falls back to a hardcoded zip-to-territory map if Nutshell territory is missing.
	 *
	 * @param string $customerid  FreshBooks customer ID.
	 * @param array  $invoices    Array of FreshBooks invoice objects for this customer.
	 * @return array|null Enriched candidate data, or null if excluded / not found.
	 */
	public function enrich_customer( $customerid, $invoices, &$fail_reason = null ) {
		// ── FreshBooks client info ─────────────────────────
		// v1.8.0: route through the per-chunk in-memory cache so the
		// bulk-Nutshell pre-pass and this call share one FB round-trip.
		$client = $this->get_fb_client_cached( $customerid );
		if ( ! $client ) {
			$fail_reason = 'freshbooks_api';
			return null;
		}

		$fname = isset( $client['fname'] )        ? trim( $client['fname'] ) : '';
		$lname = isset( $client['lname'] )         ? trim( $client['lname'] ) : '';
		$email = isset( $client['email'] )         ? trim( $client['email'] ) : '';
		$org   = isset( $client['organization'] )  ? trim( $client['organization'] ) : '';

		// v1.3.0 — FreshBooks sometimes stores full name in fname with lname empty.
		// Split intelligently: "Laura Mansell" → fname="Laura", lname="Mansell"
		if ( empty( $lname ) && ! empty( $fname ) && strpos( $fname, ' ' ) !== false ) {
			$name_parts = explode( ' ', $fname );
			$fname      = array_shift( $name_parts ); // First word = first name
			$lname      = implode( ' ', $name_parts ); // Everything else = last name
		}

		// FreshBooks auto-fills organization with the person's name when no company is set.
		// Clear it so we don't display "Megan Biffar (Megan Biffar)" on the dashboard
		// or pass a false company name to Nutshell.
		$person_name = strtolower( trim( $fname . ' ' . $lname ) );
		if ( ! empty( $org ) && strtolower( $org ) === $person_name ) {
			$org = '';
		}

		$city  = '';
		$phone = '';

		// Phone: prefer mobile, fall back to home, business, then street2
		// Matches Surveys plugin cascade: mob_phone → home_phone → bus_phone → p_street2
		if ( ! empty( $client['mob_phone'] ) ) {
			$phone = trim( $client['mob_phone'] );
		} elseif ( ! empty( $client['home_phone'] ) ) {
			$phone = trim( $client['home_phone'] );
		} elseif ( ! empty( $client['bus_phone'] ) ) {
			$phone = trim( $client['bus_phone'] );
		} elseif ( ! empty( $client['p_street2'] ) ) {
			$phone = $this->extract_phone_from_street2( $client['p_street2'] );
		}

		// Location from billing address — build "City, ST ZIP" format
		$raw_city = ! empty( $client['p_city'] )     ? trim( $client['p_city'] )     : '';
		$state    = ! empty( $client['p_province'] )  ? trim( $client['p_province'] ) : '';
		$zip      = ! empty( $client['p_code'] )      ? trim( $client['p_code'] )     : '';

		$city = $raw_city;
		if ( ! empty( $state ) ) {
			$city .= ( ! empty( $city ) ? ', ' : '' ) . strtoupper( $state );
		}
		if ( ! empty( $zip ) ) {
			$city .= ( ! empty( $city ) ? ' ' : '' ) . $zip;
		}

		// ── Excluded companies check ──────────────────────
		$check_org  = strtolower( $org );
		$check_name = strtolower( $fname . ' ' . $lname );
		foreach ( $this->settings['excluded_companies'] as $excluded ) {
			if ( ! empty( $excluded ) && (
				strpos( $check_org, $excluded ) !== false ||
				strpos( $check_name, $excluded ) !== false
			) ) {
				$fail_reason = 'excluded_company';
				return null; // Skip excluded
			}
		}

		// ── Auto-exclude commercial / property management companies ──
		if ( $this->is_commercial_entity( $org, $fname, $lname ) ) {
			$fail_reason = 'commercial_entity';
			return null;
		}

		// ── Parse purchase history from invoices ──────────
		$purchase = $this->parse_purchase_history( $invoices );

		// ── Nutshell enrichment ───────────────────────────
		$nutshell = $this->enrich_from_nutshell( $email, $fname, $lname );

		// Phone fallback: if FreshBooks had no phone, use Nutshell contact's phone
		if ( empty( $phone ) && ! empty( $nutshell['phone'] ) ) {
			$phone = $nutshell['phone'];
		}

		// ── Territory fallback: service-area map ─────────────
		// If the CRM didn't return a territory (lead only exists in the billing provider),
		// resolve the customer's billing postal code through the admin-configured service
		// area (empty map = allow-all; see zl_territory_for_postal). No hardcoded geography.
		$territory = $nutshell['territory'];
		if ( empty( $territory ) && ! empty( $zip ) && function_exists( 'zl_territory_for_postal' ) ) {
			$territory = zl_territory_for_postal( $zip );
		}

		// JSON-encode purchase_history to reduce transient size.
		// PHP serialization of deeply nested arrays is very verbose;
		// a single JSON string serializes as a compact s:N:"..." entry.
		return array(
			'freshbooks_client_id' => $customerid,
			'first_name'           => $fname,
			'last_name'            => $lname,
			'email'                => $email,
			'phone'                => $phone,
			'city'                 => $city,
			'organization'         => $org,
			'territory'            => $territory,
			'nutshell_contact_id'  => $nutshell['contact_id'],
			'nutshell_interests'   => $nutshell['interests'],
			'nutshell_custom_fields' => wp_json_encode( $nutshell['custom_fields'] ),
			'purchase_history'     => wp_json_encode( $purchase['items'] ),
			'purchase_summary'     => $purchase['summary'],
			'total_value'          => $purchase['total_value'],
			'most_recent_date'     => $purchase['most_recent_date'],
			'product_categories'   => $purchase['categories'],
			'invoice_count'        => count( $invoices ),
		);
	}

	/**
	 * Auto-detect commercial / property management / business entities
	 * that are not individual homeowner customers.
	 * 
	 * @param string $org Organization name.
	 * @param string $fname First name.
	 * @param string $lname Last name.
	 * @return bool True if identified as a commercial entity.
	 */
	private function is_commercial_entity( $org, $fname, $lname ) {
		// Only check organization name for commercial keywords — NOT person name.
		// Checking fname/lname caused false positives (e.g., "Sarah Realty", "John Builders").
		$check = strtolower( trim( $org ) );
		if ( empty( $check ) ) {
			return false;
		}

		// Commercial / property management keywords
		$commercial_keywords = array(
			'property management', 'property mgmt', 'prop mgmt', 'prop management',
			'real estate', 'realty',
			'hoa', 'homeowners association', 'home owners association',
			'management company', 'mgmt company', 'management co', 'mgmt co',
			'management group', 'mgmt group',
			'management llc', 'mgmt llc',
			'management inc', 'mgmt inc',
			'property group', 'properties llc', 'properties inc',
			'apartment', 'apartments',
			'commercial', 'industrial',
			'construction co', 'construction llc', 'construction inc',
			'builders', 'building co',
			'general contractor',
		);

		foreach ( $commercial_keywords as $kw ) {
			if ( strpos( $check, $kw ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * FreshBooks line-item names/descriptions to EXCLUDE from purchase summaries.
	 * These are administrative / boilerplate items, not actual products.
	 */
	private static $junk_line_patterns = array(
		'location',
		'tax included',
		'tax and installation',
		'tax & installation',
		'tax and service',
		'tax & service',
		'tax is included',
		'appt. summary',
		'appt summary',
		'appointment summary',
		'service call completed',
		'service call',
		'discount',
		'adjustment',
		'credit',
		'deposit',
		'balance',
		'payment',
		'subtotal',
		'total',
		'misc',
		'miscellaneous',
		'note:',
		'notes:',
		'lockbox',
		'tenant:',
	);

	/**
	 * Check if a line-item name is junk / administrative (not a real product).
	 * 
	 * @param string $name The line item name.
	 * @return bool True if junk.
	 */
	private function is_junk_line_item( $name ) {
		$lower = strtolower( trim( $name ) );

		// Skip completely empty or very short items
		if ( strlen( $lower ) < 3 ) {
			return true;
		}

		// Check against junk patterns
		foreach ( self::$junk_line_patterns as $pattern ) {
			if ( strpos( $lower, $pattern ) !== false ) {
				return true;
			}
		}

		// Skip items that are ONLY a dollar amount
		if ( preg_match( '/^\$?[\d,]+\.?\d*$/', $lower ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse invoice line items into structured purchase history.
	 * Filters out administrative junk items and builds a clean summary.
	 * 
	 * @param array $invoices Array of FreshBooks invoices.
	 * @return array Parsed history including all items, clean items, summary, total value, etc.
	 */
	public function parse_purchase_history( $invoices ) {
		$items       = array();
		$all_items   = array(); // All items including junk (for filter matching)
		$total_value = 0.0;
		$most_recent = '2000-01-01';
		$categories  = array();

		foreach ( $invoices as $inv ) {
			$inv_date   = isset( $inv['create_date'] ) ? $inv['create_date'] : '';
			$inv_amount = $this->safe_amount( isset( $inv['amount'] ) ? $inv['amount'] : 0 );
			$total_value += $inv_amount;

			if ( $inv_date > $most_recent ) {
				$most_recent = $inv_date;
			}

			$lines = isset( $inv['lines'] ) ? $inv['lines'] : array();
			foreach ( $lines as $line ) {
				$desc = isset( $line['description'] ) ? $line['description'] : '';
				$name = isset( $line['name'] )        ? $line['name'] : $desc;
				$qty  = isset( $line['qty'] )          ? (int) $line['qty'] : 1;
				$amt  = $this->safe_amount( isset( $line['amount'] ) ? $line['amount'] : 0 );

				$item_data = array(
					'date'        => $inv_date,
					'name'        => $name,
					'description' => $desc,
					'qty'         => $qty,
					'amount'      => $amt,
				);

				// Keep ALL items for product filter matching (even junk)
				$all_items[] = $item_data;

				// Only add NON-junk items to clean list for summary display
				if ( ! $this->is_junk_line_item( $name ) ) {
					$items[] = $item_data;
				}

				// Categorize by keyword matching (check both name and description).
				// Vocabulary binds through the Item Engine filter; empty = no categories.
				$lower = strtolower( $name . ' ' . $desc );
				foreach ( zl_product_categories() as $cat => $keywords ) {
					foreach ( $keywords as $kw ) {
						if ( strpos( $lower, $kw ) !== false ) {
							$categories[ $cat ] = true;
							break 2;
						}
					}
				}
			}
		}

		// Build clean human-readable summary from non-junk items only
		$parts = array();
		foreach ( $items as $item ) {
			$label = trim( $item['name'] );
			// Clean up excessive detail — take first sentence/line only
			if ( strpos( $label, "\n" ) !== false ) {
				$label = trim( explode( "\n", $label )[0] );
			}
			if ( strlen( $label ) > 60 ) {
				$label = substr( $label, 0, 57 ) . '...';
			}
			if ( $item['qty'] > 1 ) {
				$label .= " (x{$item['qty']})";
			}
			$parts[] = $label;
		}
		$unique_parts = array_unique( $parts );
		$summary = implode( ', ', array_slice( $unique_parts, 0, 8 ) ); // Cap at 8 items
		if ( count( $unique_parts ) > 8 ) {
			$summary .= ' + ' . ( count( $unique_parts ) - 8 ) . ' more';
		}

		return array(
			'items'            => $all_items,   // ALL items for filter matching
			'clean_items'      => $items,       // Clean items for display
			'summary'          => $summary,
			'total_value'      => $total_value,
			'most_recent_date' => $most_recent,
			'categories'       => array_keys( $categories ),
		);
	}

	/**
	 * Safely extract a numeric amount from a FreshBooks money object or scalar.
	 * 
	 * @param mixed $val Amount value.
	 * @return float Safe float amount.
	 */
	private function safe_amount( $val ) {
		if ( is_array( $val ) && isset( $val['amount'] ) ) {
			return (float) $val['amount'];
		}
		return (float) $val;
	}

	/**
	 * Enrich lead data from Nutshell CRM (contact search → custom fields).
	 *
	 * @since 1.2.0 Expanded interest fields and added full custom_fields extraction.
	 * 
	 * @param string $email Email address.
	 * @param string $fname First name.
	 * @param string $lname Last name.
	 * @return array Enrichment result including contact_id, territory, interests, and custom_fields.
	 */
	private function enrich_from_nutshell( $email, $fname, $lname ) {
		$result = array(
			'contact_id'    => '',
			'phone'         => '',
			'territory'     => '',
			'interests'     => '',
			'custom_fields' => array(),
		);

		try {
			$contact_id = null;

			// 1. Search by email first (most reliable)
			//    v1.8.0: prefer the per-chunk cache seeded by
			//    prime_nutshell_cache_from_emails(). When the cache has a
			//    hit we skip the HTTP round-trip entirely. On a miss-hit
			//    (cache present, no contact) we also skip — a miss in the
			//    bulk pre-pass is authoritative for this chunk. Only fall
			//    through to single-shot search_by_email when no pre-pass
			//    was done at all (legacy call path).
			if ( ! empty( $email ) ) {
				$cached = $this->get_cached_nutshell_by_email( $email );
				if ( is_array( $cached ) ) {
					$contact_id = $cached['contact_id'] ?? null;
				} else {
					$search     = $this->ns->search_by_email( $email );
					$contact_id = $this->extract_contact_id_from_search( $search );
				}
			}

			// 2. Fall back to name search
			if ( $contact_id === null && ( ! empty( $fname ) || ! empty( $lname ) ) ) {
				$search_str = trim( $fname . ' ' . $lname );
				$search     = $this->ns->search_contacts( $search_str, 5 );
				$contact_id = $this->extract_contact_id_from_search( $search );
			}

			if ( $contact_id === null ) {
				return $result;
			}

			$result['contact_id'] = (string) $contact_id;

			// Get full contact details with custom fields
			$contact = $this->ns->get_contact( $contact_id );
			if ( empty( $contact ) ) {
				return $result;
			}

			$custom = $this->extract_custom_fields( $contact );

			// Store the FULL custom fields map in the return result
			$result['custom_fields'] = $custom;

			// Phone fallback from Nutshell contact
			$result['phone'] = $this->extract_phone_from_contact( $contact );

			// Territory
			if ( ! empty( $custom['Territory'] ) ) {
				$result['territory'] = trim( $custom['Territory'] );
			}

			// Interests from relevant Nutshell custom fields (expanded in v1.2.0)
			$interest_parts  = array();
			$interest_fields = array(
				'Summary of Recent Work We\'ve Done',
				'Number of Jobs',
				'Total Billed to Date',
				'QTY Windows',
				'QTY Doors',
				'QTY Double Doors',
				'QTY Single Doors',
				'Products Purchased',
				'Brands Owned',
				'Grand Zone',
				'Work We\'ve Done',
			);
			foreach ( $interest_fields as $field ) {
				if ( isset( $custom[ $field ] ) && $custom[ $field ] !== '' ) {
					$interest_parts[] = $field . ': ' . $custom[ $field ];
				}
			}
			$result['interests'] = implode( '; ', $interest_parts );

		} catch ( \Throwable $e ) {
			// Non-fatal — log and return partial data
			error_log( 'ZL Nutshell enrichment error: ' . $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Extract a usable contact ID from various Nutshell search response formats.
	 *
	 * searchByEmail returns:  { contacts: [{id: 123, ...}], leads: [...] }
	 * searchContacts returns: [{id: 123, entityType: "Contacts", ...}]
	 * 
	 * @param array $search Search response array.
	 * @return int|null Contact ID or null.
	 */
	private function extract_contact_id_from_search( $search ) {
		if ( empty( $search ) || ! is_array( $search ) ) {
			return null;
		}

		// Format: { contacts: [{id:…}] } (searchByEmail)
		if ( isset( $search['contacts'] ) && is_array( $search['contacts'] ) ) {
			foreach ( $search['contacts'] as $c ) {
				if ( isset( $c['id'] ) ) {
					return (int) $c['id'];
				}
			}
		}

		// Format: [{id:…, entityType:"Contacts"}] (searchContacts)
		if ( isset( $search[0] ) ) {
			foreach ( $search as $item ) {
				if ( isset( $item['id'] ) ) {
					$entity = isset( $item['entityType'] ) ? $item['entityType'] : '';
					if ( empty( $entity ) || $entity === 'Contacts' ) {
						return (int) $item['id'];
					}
				}
			}
		}

		// Single object: {id: 123}
		if ( isset( $search['id'] ) ) {
			return (int) $search['id'];
		}

		return null;
	}

	/**
	 * Extract custom field name→value pairs from a Nutshell contact object.
	 *
	 * Nutshell stores custom fields in various structures depending on API version.
	 * 
	 * @param array $contact Contact object from Nutshell.
	 * @return array Key-value pairs of custom fields.
	 */
	private function extract_custom_fields( $contact ) {
		$fields = array();

		// Primary: 'customFields' keyed by field-ID
		if ( isset( $contact['customFields'] ) && is_array( $contact['customFields'] ) ) {
			foreach ( $contact['customFields'] as $fd ) {
				if ( ! is_array( $fd ) ) {
					continue;
				}
				$name  = isset( $fd['name'] )  ? $fd['name']  : ( isset( $fd['label'] ) ? $fd['label'] : '' );
				$value = isset( $fd['value'] ) ? $fd['value'] : '';

				// Multi-select values are arrays
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				if ( ! empty( $name ) ) {
					$fields[ $name ] = (string) $value;
				}
			}
		}

		// Tags
		if ( isset( $contact['tags'] ) && is_array( $contact['tags'] ) ) {
			$fields['_tags'] = implode( ', ', $contact['tags'] );
		}

		return $fields;
	}

	/**
	 * Extract a phone number from the FreshBooks p_street2 (second address line).
	 *
	 * BUSINESS CONTEXT:
	 * Some FreshBooks invoices store the customer's phone number on the second
	 * address line (e.g., "619-572-2948") instead of in the dedicated phone fields.
	 * This helper detects if p_street2 looks like a phone number so we can capture
	 * it for the salesperson's call list.
	 * This is the last resort in the phone extraction cascade after all
	 * dedicated phone fields have been checked.
	 *
	 * Matches the Satisfaction Surveys plugin's _extract_phone_from_street2() implementation.
	 *
	 * @since 1.3.0
	 * @param string $street2 The FreshBooks p_street2 value.
	 * @return string The phone number if detected, empty string otherwise.
	 */
	private function extract_phone_from_street2( $street2 ) {
		if ( empty( $street2 ) || ! is_string( $street2 ) ) {
			return '';
		}

		$trimmed = trim( $street2 );
		if ( empty( $trimmed ) ) {
			return '';
		}

		// Strip everything except digits
		$digits_only = preg_replace( '/[^0-9]/', '', $trimmed );

		// A US phone number has 7 digits (local), 10 (area+local), or 11 (1+area+local)
		if ( strlen( $digits_only ) < 7 || strlen( $digits_only ) > 11 ) {
			return '';
		}

		// Make sure the string is primarily a phone number, not an address that
		// happens to contain some digits (e.g., "Apt 2B"). We check that at least
		// 60% of non-space characters are digits or common phone separators (-, (, ), ., +).
		$non_space   = preg_replace( '/\s/', '', $trimmed );
		$phone_chars = preg_replace( '/[^0-9\-\(\)\.\+]/', '', $non_space );
		if ( strlen( $non_space ) > 0 && ( strlen( $phone_chars ) / strlen( $non_space ) ) < 0.6 ) {
			return ''; // More like an address than a phone number
		}

		return $trimmed;
	}

	/**
	 * Normalize a phone number to a clean format for Nutshell.
	 *
	 * Handles various FreshBooks phone formats:
	 *   "(760)612-9190"  → "(760) 612-9190"
	 *   "760-612-9190"   → "(760) 612-9190"
	 *   "7606129190"     → "(760) 612-9190"
	 *   "17606129190"    → "(760) 612-9190"
	 *   "+1 7606129190"  → "(760) 612-9190"
	 *
	 * @since 1.3.0
	 * @param string $phone Raw phone number string.
	 * @return string Normalized phone, or original trimmed if not a recognizable US number.
	 */
	private function normalize_phone( $phone ) {
		if ( empty( $phone ) || ! is_string( $phone ) ) {
			return '';
		}
		$phone = trim( $phone );
		if ( empty( $phone ) ) {
			return '';
		}

		// Strip everything except digits
		$digits = preg_replace( '/[^0-9]/', '', $phone );

		// Handle 11-digit numbers starting with 1 (US country code)
		if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
			$digits = substr( $digits, 1 );
		}

		// If we have exactly 10 digits, format as (XXX) XXX-XXXX
		if ( strlen( $digits ) === 10 ) {
			return '(' . substr( $digits, 0, 3 ) . ') ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
		}

		// 7-digit local number — format as XXX-XXXX
		if ( strlen( $digits ) === 7 ) {
			return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 4 );
		}

		// Not a standard US number — return original trimmed value
		return $phone;
	}

	/**
	 * Extract the primary phone number from a Nutshell contact object.
	 *
	 * Nutshell stores phone numbers in an array of objects, each with a 'number'
	 * key and optionally a 'type' key (e.g., "Cell Phone", "Business Phone").
	 * This method returns the first non-empty phone number found.
	 *
	 * @since 1.3.0
	 * @param array $contact Nutshell contact object from getContact.
	 * @return string The first phone number found, or empty string.
	 */
	private function extract_phone_from_contact( $contact ) {
		if ( ! isset( $contact['phone'] ) || ! is_array( $contact['phone'] ) ) {
			return '';
		}
		foreach ( $contact['phone'] as $ph ) {
			$number = '';
			if ( is_array( $ph ) ) {
				$number = isset( $ph['number'] ) ? $ph['number'] : ( isset( $ph['value'] ) ? $ph['value'] : '' );
			} elseif ( is_string( $ph ) ) {
				$number = $ph;
			}
			if ( ! empty( trim( $number ) ) ) {
				return trim( $number );
			}
		}
		return '';
	}

	// ═══════════════════════════════════════════════════════════════════
	// Product filter — AI-powered matching
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * AI-expand a product filter into actual matching line item names.
	 *
	 * Extracts all unique line-item names from invoices, sends them to Gemini
	 * along with the user's filter query, and asks AI to identify which line
	 * items match. Returns an array of lowercase matching terms.
	 *
	 * @param array  $grouped_invoices  All invoices grouped by customer ID.
	 * @param string $filter_string     The user's product filter query.
	 * @return array Array with 'matched_names' (line-item names that match) and 'keywords' (fallback keywords).
	 */
	public function ai_expand_product_filter( $grouped_invoices, $filter_string ) {
		// Extract unique line item names from the full grouped invoices array.
		// NOTE: In v1.2.9+, the dashboard pre-extracts names during fetch and calls
		// ai_expand_product_filter_from_names() directly to avoid memory exhaustion.
		// This method is kept for backward compatibility.
		$all_names = array();
		foreach ( $grouped_invoices as $cid => $invoices ) {
			foreach ( $invoices as $inv ) {
				$lines = isset( $inv['lines'] ) ? $inv['lines'] : array();
				foreach ( $lines as $line ) {
					$name = isset( $line['name'] ) ? trim( $line['name'] ) : '';
					$desc = isset( $line['description'] ) ? trim( $line['description'] ) : '';
					if ( ! empty( $name ) ) {
						$all_names[ strtolower( $name ) ] = $name;
					}
					if ( ! empty( $desc ) && $desc !== $name ) {
						$all_names[ strtolower( $desc ) ] = $desc;
					}
				}
			}
		}

		return $this->ai_expand_product_filter_from_names( array_values( $all_names ), $filter_string );
	}

	/**
	 * AI-expand a product filter from pre-extracted unique line item names.
	 *
	 * v1.2.9: Split from ai_expand_product_filter() so that ajax_expand_filter()
	 * can pass pre-extracted names instead of the full grouped invoices array.
	 * Decompressing 9,000+ customers' invoice data (~4+ MB) in ajax_expand_filter()
	 * was exceeding PHP memory_limit on 15-year lookbacks, causing a fatal error
	 * that silently killed the AJAX handler with no HTTP response.
	 *
	 * @param array  $unique_names   Array of unique line-item name strings.
	 * @param string $filter_string  The user's product filter query.
	 * @return array Array with 'matched_names', 'keywords', 'ai_used', etc.
	 */
	public function ai_expand_product_filter_from_names( $unique_names, $filter_string ) {
		error_log( 'ZL: Found ' . count( $unique_names ) . ' unique line item names/descriptions for AI filter expansion' );

		// Parse the user's filter into individual terms (split on commas, slashes, semicolons)
		$raw_keywords = preg_split( '/[,\/;]+/', strtolower( $filter_string ) );
		$raw_keywords = array_filter( array_map( 'trim', $raw_keywords ) );

		// If no AI available, fall back to simple keyword matching with parsed terms
		if ( ! $this->poe || empty( $unique_names ) ) {
			$reason = ! $this->poe ? 'Poe AI client not initialized' : 'No line item names found in invoice data';
			error_log( 'ZL AI filter expansion skipped: ' . $reason );
			return array(
				'matched_names' => array(),
				'keywords'      => $raw_keywords,
				'ai_used'       => false,
				'error'         => $reason,
			);
		}

		// Send to Gemini: ask which line items relate to the filter
		$names_list = implode( "\n", array_map( function( $n, $i ) {
			return ( $i + 1 ) . '. ' . $n;
		}, array_slice( $unique_names, 0, 500 ), array_keys( array_slice( $unique_names, 0, 500 ) ) ) );

		// AI prompt for strict product matching. Assembled at runtime and business-NEUTRAL:
		// no company or product name is typed. Any trade shorthand is supplied by the tenant's
		// product-vocabulary binding (zl_product_aliases) and rendered below; with the default
		// empty vocabulary the shorthand section is simply omitted (no trade baked in).
		$biz       = (string) apply_filters( 'zl_business_descriptor', __( 'the business', 'zorderz' ) );
		$shorthand = '';
		$alias_map = function_exists( 'zl_product_aliases' ) ? zl_product_aliases() : array();
		if ( ! empty( $alias_map ) ) {
			$shorthand = "TRADE SHORTHAND (treat these as equivalent when matching):\n";
			foreach ( $alias_map as $trigger => $aliases ) {
				$shorthand .= '- "' . $trigger . '" also matches: ' . implode( ', ', (array) $aliases ) . "\n";
			}
			$shorthand .= "\n";
		}
		$prompt = "You are a STRICT product-matching assistant for {$biz}.\n\n"
			. "The user wants to find customers who purchased products matching this filter:\n\n"
			. "FILTER: \"{$filter_string}\"\n\n"
			. "Below is the complete list of line-item names from the billing provider's invoices. "
			. "Identify ONLY line items that SPECIFICALLY match the filter categories.\n\n"
			. $shorthand
			. "MATCHING RULES:\n"
			. "- Match items that relate to the specific product types in the filter, including any trade shorthand listed above\n"
			. "- A narrower filter category is NOT matched by an unrelated broader item\n"
			. "- A bare generic material/word alone is NOT a match — the product type must specifically match\n"
			. "- Generic terms only match if the filter is equally generic\n"
			. "- Each filter term (comma-separated) is a separate product category to match\n"
			. "- When in doubt about shorthand, include the item (the downstream validator will double-check)\n\n"
			. "LINE ITEMS:\n{$names_list}\n\n"
			. "Return ONLY the matching line-item numbers as a comma-separated list. "
			. "Example: 1,4,7,12,15\n"
			. "If NONE match, return: NONE\n"
			. "Also add a line starting with KEYWORDS: followed by comma-separated lowercase search "
			. "phrases that specifically describe the filter products. Include both multi-word phrases "
			. "AND any specific single-word trade terms. "
			. "Do NOT include overly generic single words. "
			. "Example format: <specific phrase>, <specific phrase>, <specific term>";

		error_log( 'ZL AI filter expansion: sending prompt (' . strlen( $prompt ) . ' chars, ' . count( $unique_names ) . ' items) to ' . $this->settings['ai_model'] );

		try {
			$response = $this->poe->query( $prompt, null, '', 0.0, array(
				'thinking_budget' => 32768,
				'web_search'      => true,
			) );
			$result   = trim( $response );

			$matched_names = array();
			$ai_keywords   = array();

			// Parse matched line-item numbers
			$lines = explode( "\n", $result );
			foreach ( $lines as $line ) {
				$line = trim( $line );

				// Parse KEYWORDS line
				if ( stripos( $line, 'KEYWORDS:' ) === 0 ) {
					$kw_str      = trim( substr( $line, 9 ) );
					$ai_keywords = array_filter( array_map( 'trim', preg_split( '/[,;]+/', strtolower( $kw_str ) ) ) );
					continue;
				}

				// Parse number list (e.g., "1,4,7,12,15" or "1, 4, 7")
				if ( preg_match( '/^[\d,\s]+$/', $line ) ) {
					$nums = array_filter( array_map( 'intval', preg_split( '/[,\s]+/', $line ) ) );
					foreach ( $nums as $num ) {
						$idx = $num - 1;
						if ( isset( $unique_names[ $idx ] ) ) {
							$matched_names[] = strtolower( $unique_names[ $idx ] );
						}
					}
				}

				// Handle "NONE"
				if ( strtoupper( $line ) === 'NONE' ) {
					break;
				}
			}

			error_log( 'ZL AI filter expansion: ' . count( $matched_names ) . ' matched names, ' . count( $ai_keywords ) . ' keywords' );

			// Combine AI keywords with user's parsed keywords
			$all_keywords = array_unique( array_merge( $raw_keywords, $ai_keywords ) );

			return array(
				'matched_names' => $matched_names,
				'keywords'      => $all_keywords,
				'ai_used'       => true,
				'ai_response'   => $result,
			);
		} catch ( \Throwable $e ) {
			error_log( 'ZL AI filter expansion error: ' . get_class( $e ) . ': ' . $e->getMessage() );
			// Fall back to parsed keywords
			return array(
				'matched_names' => array(),
				'keywords'      => $raw_keywords,
				'ai_used'       => false,
				'error'         => get_class( $e ) . ': ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Check if a candidate matches the expanded product filter.
	 *
	 * Uses both the AI-matched line-item names AND keyword fallback for maximum coverage.
	 *
	 * @param array $candidate      Enriched candidate data.
	 * @param array $expanded_filter The result from ai_expand_product_filter().
	 * @return bool True if any purchase item matches.
	 */
	public function matches_product_filter( $candidate, $expanded_filter ) {
		if ( empty( $expanded_filter ) ) {
			return true;
		}

		// Handle legacy string format (backwards compat)
		if ( is_string( $expanded_filter ) ) {
			$raw = preg_split( '/[,\/;]+/', strtolower( $expanded_filter ) );
			$expanded_filter = array(
				'matched_names' => array(),
				'keywords'      => array_filter( array_map( 'trim', $raw ) ),
			);
		}

		$matched_names = isset( $expanded_filter['matched_names'] ) ? $expanded_filter['matched_names'] : array();
		$keywords      = isset( $expanded_filter['keywords'] ) ? $expanded_filter['keywords'] : array();

		if ( empty( $matched_names ) && empty( $keywords ) ) {
			return true;
		}

		// Build searchable text from ALL purchase data + Nutshell fields
		$search_parts = array();
		$search_parts[] = $candidate['purchase_summary'] ?? '';
		$search_parts[] = $candidate['nutshell_interests'] ?? '';

		$line_names = array(); // Track individual line item names for exact matching
		$ph = $candidate['purchase_history'] ?? '';
		if ( ! empty( $ph ) ) {
			$ph_items = is_array( $ph ) ? $ph : json_decode( $ph, true );
			if ( ! is_array( $ph_items ) ) { $ph_items = array(); }
			foreach ( $ph_items as $item ) {
				$n = strtolower( $item['name'] ?? '' );
				$d = strtolower( $item['description'] ?? '' );
				$search_parts[] = $n;
				$search_parts[] = $d;
				if ( ! empty( $n ) ) { $line_names[] = $n; }
				if ( ! empty( $d ) ) { $line_names[] = $d; }
			}
		}

		$full_text = strtolower( implode( ' ', $search_parts ) );

		// Check 1: Exact line-item name match (from AI expansion)
		foreach ( $matched_names as $mn ) {
			// Check if any of the customer's line items match the AI-identified names
			foreach ( $line_names as $ln ) {
				if ( $ln === $mn || strpos( $ln, $mn ) !== false || strpos( $mn, $ln ) !== false ) {
					return true;
				}
			}
		}

		// Check 2: Expand keywords with product aliases (Item Engine binding).
		// A tenant maps its own shorthand → synonyms via the zl_product_aliases filter so a
		// filter term also matches the abbreviations that appear in invoice line-items. Ships
		// empty (no expansion), so no trade shorthand is baked in.
		$all_keywords = $keywords;
		$aliases_map  = zl_product_aliases();
		if ( ! empty( $aliases_map ) ) {
			foreach ( $keywords as $kw ) {
				foreach ( $aliases_map as $trigger => $aliases ) {
					if ( strpos( $kw, $trigger ) !== false || strpos( $trigger, $kw ) !== false ) {
						$all_keywords = array_merge( $all_keywords, (array) $aliases );
					}
				}
			}
			$all_keywords = array_unique( $all_keywords );
		}

		// Check 3: Keyword search — multi-word phrases + allow-listed single-word terms.
		// Generic single words (e.g. "screen", "door") are too broad and cause false
		// positives; a tenant declares its unambiguous single-word product terms via the
		// zl_single_word_product_terms filter (empty by default).
		$allowed_singles = zl_single_word_product_terms();
		foreach ( $all_keywords as $kw ) {
			if ( empty( $kw ) ) {
				continue;
			}
			$word_count      = count( array_filter( explode( ' ', $kw ) ) );
			$is_safe_single  = ( $word_count === 1 && in_array( strtolower( $kw ), $allowed_singles, true ) );
			if ( ( $word_count >= 2 || $is_safe_single ) && strpos( $full_text, $kw ) !== false ) {
				return true;
			}
		}

		return false;
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 3 — Cooldown check (de-duplication)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Check if a customer was already generated as a lead within the cooldown window.
	 * 
	 * @param string $freshbooks_client_id The FreshBooks customer ID.
	 * @return bool True if within cooldown period.
	 */
	public function is_within_cooldown( $freshbooks_client_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'zl_lead_history';
		$days    = $this->settings['cooldown_days'];
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE freshbooks_client_id = %s AND last_generated_at > %s LIMIT 1",
			$freshbooks_client_id,
			$cutoff
		) );

		return ! empty( $row );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 4 — Score a lead (deterministic, max 100)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Score a lead deterministically based on 6 weighted factors.
	 * 
	 * SCORING ALGORITHM (Max 100):
	 * - Recency (25): more recent purchases → higher score
	 * - Value (25): higher spend → higher score
	 * - Breadth (20): more diverse products → higher score
	 * - Repeat (15): more invoices → higher score
	 * - Nutshell (10): existing CRM contact → higher score
	 * - Season (5): seasonal bonus for screen-relevant months
	 *
	 * @param array $candidate Enriched candidate data from enrich_customer().
	 * @return float Score 0–100.
	 */
	public function score_lead( $candidate ) {
		$score = 0.0;

		// 1. Recency — more recent purchase = higher score  (max 25)
		$mr = $candidate['most_recent_date'];
		if ( ! empty( $mr ) && $mr !== '2000-01-01' ) {
			$days_ago = max( 0, ( time() - strtotime( $mr ) ) / 86400 );
			if ( $days_ago < 90 )       { $score += 25; }
			elseif ( $days_ago < 180 )  { $score += 20; }
			elseif ( $days_ago < 365 )  { $score += 15; }
			elseif ( $days_ago < 730 )  { $score += 10; }
			else                        { $score += 5;  }
		}

		// 2. Total purchase value  (max 25)
		$val = $candidate['total_value'];
		if ( $val >= 5000 )      { $score += 25; }
		elseif ( $val >= 2000 )  { $score += 20; }
		elseif ( $val >= 1000 )  { $score += 15; }
		elseif ( $val >= 500 )   { $score += 10; }
		else                     { $score += 5;  }

		// 3. Product breadth — more categories = broader buyer  (max 20)
		$cat_count = count( $candidate['product_categories'] );
		$score += min( 20, $cat_count * 5 );

		// 4. Repeat customer bonus — invoice count  (max 15)
		$inv = $candidate['invoice_count'];
		if ( $inv >= 5 )      { $score += 15; }
		elseif ( $inv >= 3 )  { $score += 10; }
		elseif ( $inv >= 2 )  { $score += 7;  }
		else                  { $score += 3;  }

		// 5. Nutshell enrichment bonus  (max 10)
		if ( ! empty( $candidate['nutshell_contact_id'] ) ) { $score += 5; }
		if ( ! empty( $candidate['nutshell_interests'] ) )  { $score += 5; }

		// 6. Seasonality — trade-neutral by default (max 5). Core makes NO assumption about
		// which months are "busy season"; a tenant whose demand is seasonal supplies a
		// month => points map (1-12 => int) via the zl_seasonality_boost filter. Empty map
		// (the default) adds nothing, so no trade is baked in.
		$season = (array) apply_filters( 'zl_seasonality_boost', array() );
		if ( ! empty( $season ) ) {
			$month = (int) gmdate( 'n' );
			if ( isset( $season[ $month ] ) ) {
				$score += min( 5, max( 0, (int) $season[ $month ] ) );
			}
		}

		return min( 100.0, $score );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 5 — Filter candidates by territory for a salesperson
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Filter candidates by service-area coverage for a salesperson.
	 *
	 * Coverage policy (replaces the old silent "Strict Territory Rule"):
	 *   - If NO coverage map is configured (empty = allow-all), coverage gating is OFF and
	 *     every candidate passes. Nothing is dropped, so nothing needs a disposition.
	 *   - If a map IS configured, a candidate whose territory does not match the
	 *     salesperson's codes — or whose territory is unresolved — is HELD (not returned),
	 *     and EVERY such hold is a LOGGED disposition (territory_out_of_area /
	 *     territory_unmatched) via zl_log_disposition(). No lead is ever silently skipped,
	 *     and the funnel counts balance (kept + held == input).
	 *
	 * @param array  $candidates       Array of enriched candidate data.
	 * @param string $salesperson_code  Salesperson code (e.g. "NW", "SE").
	 * @return array Candidates within coverage (all, when coverage is unconfigured).
	 */
	public function filter_by_territory( $candidates, $salesperson_code ) {
		$total = is_array( $candidates ) ? count( $candidates ) : 0;

		// Allow-all when the service area is not configured — never a silent drop.
		if ( ! function_exists( 'zl_coverage_configured' ) || ! zl_coverage_configured() ) {
			return $candidates;
		}

		$sp = null;
		foreach ( $this->settings['salespeople'] as $s ) {
			if ( strtoupper( $s['code'] ) === strtoupper( $salesperson_code ) ) {
				$sp = $s;
				break;
			}
		}

		// Fail LOUDLY on a missing salesperson rather than returning a subtly-wrong set.
		if ( ! $sp ) {
			throw new Exception( "Salesperson '{$salesperson_code}' not found in the roster." );
		}

		$territory_codes = array_filter( array_map( 'trim', explode( ',', strtoupper( $sp['territories'] ) ) ) );
		$filtered        = array();
		$held_unresolved = 0;
		$held_other_area = 0;

		foreach ( $candidates as $c ) {
			$lead_territory = strtoupper( trim( $c['territory'] ?? '' ) );

			if ( $lead_territory === '' ) {
				// Unresolved coverage — held for review, fail_direction: closed. Logged.
				$held_unresolved++;
				continue;
			}
			if ( in_array( $lead_territory, $territory_codes, true ) ) {
				$filtered[] = $c;
			} else {
				$held_other_area++;
			}
		}

		// One disposition row per reason — the drop is counted, never silent.
		if ( $held_unresolved > 0 && function_exists( 'zl_log_disposition' ) ) {
			zl_log_disposition( 'territory_unmatched', array(
				'salesperson' => $salesperson_code,
				'held'        => $held_unresolved,
				'of_input'    => $total,
				'policy'      => 'hold_for_review',
				'reason'      => 'postal code did not resolve to any territory',
			) );
		}
		if ( $held_other_area > 0 && function_exists( 'zl_log_disposition' ) ) {
			zl_log_disposition( 'territory_out_of_area', array(
				'salesperson' => $salesperson_code,
				'held'        => $held_other_area,
				'of_input'    => $total,
				'kept'        => count( $filtered ),
			) );
		}

		return $filtered;
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 5b — Additional candidate filters (v1.2.0)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Filter candidates by city names or zip codes.
	 *
	 * Parses a comma-separated filter string. If a term is 5 digits, it is
	 * treated as a zip code and checked against the city field (which contains
	 * "City, State ZIP"). Otherwise it is treated as a city name and matched
	 * with a case-insensitive substring search.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $candidates      Array of enriched candidate data.
	 * @param string $city_zip_filter  Comma-separated city names or zip codes.
	 * @return array Filtered candidates matching any of the city/zip terms.
	 */
	public function filter_by_city_zip( $candidates, $city_zip_filter ) {
		if ( empty( $city_zip_filter ) || ! is_string( $city_zip_filter ) ) {
			return $candidates;
		}

		$terms = array_filter( array_map( 'trim', explode( ',', $city_zip_filter ) ) );
		if ( empty( $terms ) ) {
			return $candidates;
		}

		$filtered = array();

		foreach ( $candidates as $candidate ) {
			$city_value = strtolower( trim( $candidate['city'] ?? '' ) );

			foreach ( $terms as $term ) {
				// If the term is exactly 5 digits, treat as zip code
				if ( preg_match( '/^\d{5}$/', $term ) ) {
					// Check if the zip code appears in the city field
					if ( strpos( $city_value, $term ) !== false ) {
						$filtered[] = $candidate;
						break;
					}
				} else {
					// Treat as city name — case-insensitive substring match
					if ( strpos( $city_value, strtolower( $term ) ) !== false ) {
						$filtered[] = $candidate;
						break;
					}
				}
			}
		}

		return $filtered;
	}

	/**
	 * Filter candidates by total invoice value (spend range).
	 *
	 * Checks each candidate's `total_value` against the provided min/max range.
	 * If spend_min is 0 or empty, no lower bound is applied.
	 * If spend_max is 0 or empty, no upper bound is applied.
	 *
	 * @since 1.2.0
	 *
	 * @param array     $candidates Array of enriched candidate data.
	 * @param float|int $spend_min  Minimum total spend (0 or empty = no minimum).
	 * @param float|int $spend_max  Maximum total spend (0 or empty = no maximum).
	 * @return array Filtered candidates within the spend range.
	 */
	public function filter_by_spend( $candidates, $spend_min, $spend_max ) {
		$min = (float) $spend_min;
		$max = (float) $spend_max;

		// If both are zero/empty, no filtering needed
		if ( empty( $min ) && empty( $max ) ) {
			return $candidates;
		}

		$filtered = array();

		foreach ( $candidates as $candidate ) {
			$total = (float) ( $candidate['total_value'] ?? 0 );

			// Check lower bound (only if min is set and non-zero)
			if ( ! empty( $min ) && $total < $min ) {
				continue;
			}

			// Check upper bound (only if max is set and non-zero)
			if ( ! empty( $max ) && $total > $max ) {
				continue;
			}

			$filtered[] = $candidate;
		}

		return $filtered;
	}

	/**
	 * Filter candidates by inferred-gender demographic (name classification).
	 *
	 * DISABLED BY DEFAULT — ethics/product review. Classifying people by a gender inferred
	 * from their first name is opt-in only: it runs solely when zl_gender_demographic_enabled()
	 * returns true AND an explicit non-'both' selection is made. When disabled, a non-'both'
	 * request is NOT silently applied — it is a logged disposition and all candidates pass.
	 *
	 * If enabled and the filter is empty or 'both', all candidates are returned unchanged.
	 * Otherwise, collects unique first names, calls ai_classify_names(), and filters. Names
	 * classified as 'unknown' are included (benefit of the doubt).
	 *
	 * @since 1.2.0
	 *
	 * @param array  $candidates        Array of enriched candidate data.
	 * @param string $demographic_filter 'male', 'female', 'both', or empty.
	 * @return array Filtered candidates matching the demographic.
	 */
	public function filter_by_demographic( $candidates, $demographic_filter ) {
		$filter = strtolower( trim( $demographic_filter ) );

		// No filtering if empty or 'both'
		if ( empty( $filter ) || 'both' === $filter ) {
			return $candidates;
		}

		// Ethics gate: the name→gender classifier is OFF unless a tenant explicitly enables
		// it. A non-'both' request while disabled is recorded, never silently honoured.
		if ( ! function_exists( 'zl_gender_demographic_enabled' ) || ! zl_gender_demographic_enabled() ) {
			if ( function_exists( 'zl_log_disposition' ) ) {
				zl_log_disposition( 'demographic_filter_disabled', array(
					'requested' => $filter,
					'reason'    => 'name-based gender classifier is off by default (ethics review)',
					'applied'   => false,
				) );
			}
			return $candidates;
		}

		// Collect all unique first names
		$names = array();
		foreach ( $candidates as $candidate ) {
			$first_name = trim( $candidate['first_name'] ?? '' );
			if ( ! empty( $first_name ) ) {
				$names[ strtolower( $first_name ) ] = $first_name;
			}
		}

		if ( empty( $names ) ) {
			return $candidates;
		}

		// Classify names via AI
		$classifications = $this->ai_classify_names( array_values( $names ) );

		// If AI returned no classifications, return all (no filtering)
		if ( empty( $classifications ) ) {
			return $candidates;
		}

		$filtered = array();
		foreach ( $candidates as $candidate ) {
			$first_name = strtolower( trim( $candidate['first_name'] ?? '' ) );
			$gender     = isset( $classifications[ $first_name ] ) ? $classifications[ $first_name ] : 'unknown';

			// Include if gender matches filter OR if gender is unknown (benefit of the doubt)
			if ( $gender === $filter || 'unknown' === $gender ) {
				$filtered[] = $candidate;
			}
		}

		return $filtered;
	}

	/**
	 * Classify an array of first names by gender using Gemini-3.1-Pro.
	 *
	 * Sends a batch prompt to the AI asking it to classify each name as
	 * M (male), F (female), or U (unknown/unisex). Returns an associative
	 * array mapping lowercase name to 'male', 'female', or 'unknown'.
	 *
	 * @since 1.2.0
	 *
	 * @param array $names_array Array of first name strings.
	 * @return array Associative array: lowercase_name => 'male'|'female'|'unknown'.
	 */
	public function ai_classify_names( $names_array ) {
		if ( empty( $names_array ) ) {
			return array();
		}

		// If no Poe client available, return empty array (no filtering)
		if ( ! $this->poe ) {
			return array();
		}

		$names_list = implode( ', ', $names_array );

		$prompt = "Classify each of the following first names as M (male), F (female), or U (unknown/unisex).\n\n"
			. "Names: {$names_list}\n\n"
			. "Return ONLY a comma-separated list of letters in the same order as the names.\n"
			. "Example input: John, Mary, Pat, Alex\n"
			. "Example output: M, F, U, U\n\n"
			. "Your response must contain ONLY the comma-separated letters, nothing else.";

		try {
			$response = $this->poe->query( $prompt, null, '', 0.0, array(
				'thinking_budget' => 32768,
				'web_search'      => true,
			) );
			$result = trim( $response );

			// Parse the comma-separated response
			$letters = array_map( 'trim', explode( ',', strtoupper( $result ) ) );

			$classifications = array();
			foreach ( $names_array as $i => $name ) {
				$letter = isset( $letters[ $i ] ) ? $letters[ $i ] : 'U';

				switch ( $letter ) {
					case 'M':
						$classifications[ strtolower( $name ) ] = 'male';
						break;
					case 'F':
						$classifications[ strtolower( $name ) ] = 'female';
						break;
					default:
						$classifications[ strtolower( $name ) ] = 'unknown';
						break;
				}
			}

			error_log( 'ZL: AI classified ' . count( $classifications ) . ' names by gender' );

			return $classifications;
		} catch ( \Throwable $e ) {
			error_log( 'ZL AI name classification error: ' . $e->getMessage() );
			return array();
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 5c — Product count inference (v1.2.0)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Infer product counts from cleaned line items.
	 *
	 * Buckets line items into the tenant's configured product categories
	 * (Item Engine binding via zl_product_categories) by keyword match. Returns
	 * an associative array with only non-zero counts (empty when no categories
	 * are configured — Core ships no trade-specific taxonomy).
	 *
	 * @since 1.2.0
	 *
	 * @param array $items Array of line item arrays with 'name' and 'qty' keys.
	 * @return array Associative array like ['Windows' => 10, 'Doors' => 3].
	 */
	public function infer_product_counts( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		// Product taxonomy is tenant/trade data (Item Engine binding): a map of
		// { category label => [keywords] } supplied via the zl_product_categories
		// filter. Core ships it EMPTY — with no categories configured we return no
		// counts rather than guessing or baking a trade in.
		$patterns = zl_product_categories();
		if ( empty( $patterns ) ) {
			return array();
		}
		$counts = array_fill_keys( array_keys( $patterns ), 0 );

		foreach ( $items as $item ) {
			$name  = strtolower( trim( $item['name'] ?? '' ) );
			$qty   = max( 1, (int) ( $item['qty'] ?? 1 ) );

			if ( empty( $name ) ) {
				continue;
			}

			// Skip junk items
			if ( $this->is_junk_line_item( $name ) ) {
				continue;
			}

			// Match against patterns — first match wins (most specific first)
			$matched = false;
			foreach ( $patterns as $category => $keywords ) {
				foreach ( $keywords as $kw ) {
					if ( strpos( $name, $kw ) !== false ) {
						$counts[ $category ] += $qty;
						$matched = true;
						break 2;
					}
				}
			}
		}

		// Return only non-zero counts
		return array_filter( $counts, function( $count ) {
			return $count > 0;
		} );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 6 — AI refine purchase descriptions
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Refine purchase descriptions using AI.
	 * 
	 * Nutshell lead descriptions must be <101 characters. This step asks Gemini
	 * to rewrite raw invoice summaries into concise 5-8 word descriptions.
	 *
	 * @param array $leads Array of leads with 'purchase_summary' keys.
	 * @return array Same leads with refined summaries.
	 */
	public function ai_refine_descriptions( $leads ) {
		if ( ! $this->poe || empty( $leads ) ) {
			return $leads;
		}

		// Process in chunks of 10 to stay within prompt limits
		$chunks  = array_chunk( $leads, 10 );

		// v1.8.0 / Improvement C — for any batch of ≥2 chunks, fan out to
		// Poe in parallel via ZL_Parallel_Dispatch using the current
		// 429-aware cap. Falls back to the original sequential loop if
		// query_parallel isn't available (legacy Poe clients).
		$can_parallel = ( count( $chunks ) >= 2 )
			&& method_exists( $this->poe, 'query_parallel' )
			&& class_exists( 'ZL_Parallel_Dispatch' );

		if ( $can_parallel ) {
			// Build one item per chunk, preserving index for reconciliation.
			$items = array();
			foreach ( $chunks as $chunk_idx => $chunk ) {
				$prompt_lines = array();
				foreach ( $chunk as $i => $lead ) {
					$prompt_lines[] = ( $i + 1 ) . '. ' . $lead['purchase_summary'];
				}
				$prompt = "You are a concise business assistant. Below are raw purchase descriptions from invoices for " . ( (string) apply_filters( 'zl_business_descriptor', __( 'the business', 'zorderz' ) ) ) . ".\n\n"
					. "Rewrite each one as a SHORT, clean summary (5-8 words max).\n"
					. "STRICT RULES:\n"
					. "- NO customer names\n"
					. "- NO addresses or locations\n"
					. "- NO cities or neighborhoods\n"
					. "- Focus ONLY on the product/service type and quantity\n"
					. "- Example: \"Standard install (x3)\"\n\n"
					. "Return ONLY numbered lines matching the input:\n\n"
					. implode( "\n", $prompt_lines );
				$items[] = array(
					'id'          => 'refine_' . $chunk_idx,
					'prompt'      => $prompt,
					'system'      => '',
					'temperature' => 0.0,
				);
			}

			// Heartbeat callback — forwards progress into the progress_cb
			// set by the dashboard (wired to ZL_Progress heartbeats).
			$pcb = $this->progress_cb;
			$on_prog = is_callable( $pcb )
				? function ( $id, $res, $done, $total ) use ( $pcb ) {
					try { call_user_func( $pcb, 'poe_refine', $done, $total ); }
					catch ( \Throwable $e ) { /* noop */ }
				}
				: null;

			$cap = $this->get_poe_cap();
			try {
				$results = $this->poe->query_parallel( $items, $cap, $on_prog );
			} catch ( \Throwable $e ) {
				error_log( 'ZL v1.8.0: parallel refine dispatch failed (falling back to serial): ' . $e->getMessage() );
				$results = array();
			}

			// If any chunk was rate-limited, halve our cap for remaining
			// AI work in this batch (Trap 3 — NOT forever; clear_batch_caches resets).
			$saw_429 = false;
			foreach ( $results as $r ) {
				if ( ! empty( $r['was_429'] ) ) { $saw_429 = true; break; }
			}
			if ( $saw_429 ) {
				$new_cap = $this->halve_poe_cap();
				error_log( "ZL v1.8.0: 429 detected during parallel refine — halved Poe cap to {$new_cap} for this batch." );
			}

			// Reconcile results back into the lead chunks.
			$refined = array();
			foreach ( $chunks as $chunk_idx => $chunk ) {
				$chunk_result = $results[ 'refine_' . $chunk_idx ] ?? null;
				$resp_text    = is_array( $chunk_result ) ? (string) ( $chunk_result['text'] ?? '' ) : '';

				if ( $resp_text !== '' ) {
					$lines = explode( "\n", trim( $resp_text ) );
					foreach ( $chunk as $i => &$lead_ref ) {
						foreach ( $lines as $line ) {
							$line = trim( $line );
							$num  = $i + 1;
							if ( preg_match( '/^' . $num . '[\.\)]\s*(.+)$/', $line, $m ) ) {
								$lead_ref['purchase_summary'] = trim( $m[1] );
								break;
							}
						}
					}
					unset( $lead_ref );
				}
				// On failure/empty response: leave original descriptions — non-fatal.
				$refined = array_merge( $refined, $chunk );
			}
			return $refined;
		}

		// ── SERIAL FALLBACK (original pre-v1.8.0 path) ──────────────
		$refined = array();

		foreach ( $chunks as $chunk ) {
			$prompt_lines = array();
			foreach ( $chunk as $i => $lead ) {
				$prompt_lines[] = ( $i + 1 ) . '. ' . $lead['purchase_summary'];
			}

			$prompt = "You are a concise business assistant. Below are raw purchase descriptions from invoices for " . ( (string) apply_filters( 'zl_business_descriptor', __( 'the business', 'zorderz' ) ) ) . ".\n\n"
				. "Rewrite each one as a SHORT, clean summary (5-8 words max).\n"
				. "STRICT RULES:\n"
				. "- NO customer names\n"
				. "- NO addresses or locations\n"
				. "- NO cities or neighborhoods\n"
				. "- Focus ONLY on the product/service type and quantity\n"
				. "- Example: \"Standard install (x3)\"\n\n"
				. "Return ONLY numbered lines matching the input:\n\n"
				. implode( "\n", $prompt_lines );

			try {
				$response = $this->poe->query( $prompt, null, '', 0.0 );
				$lines    = explode( "\n", trim( $response ) );

				foreach ( $chunk as $i => &$lead ) {
					foreach ( $lines as $line ) {
						$line = trim( $line );
						$num  = $i + 1;
						if ( preg_match( '/^' . $num . '[\.\)]\s*(.+)$/', $line, $m ) ) {
							$lead['purchase_summary'] = trim( $m[1] );
							break;
						}
					}
				}
				unset( $lead );
			} catch ( \Throwable $e ) {
				error_log( 'ZL AI refine error: ' . $e->getMessage() );
				// Non-fatal — keep original descriptions
			}

			$refined = array_merge( $refined, $chunk );
		}

		return $refined;
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 6b — AI strict validation (precision filter)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * AI strict validation — use Gemini 3.1 Pro to verify each lead's purchase
	 * history ACTUALLY matches the product filter. This is the precision layer
	 * after the broad keyword/AI expansion filtering.
	 *
	 * @param array  $leads          Leads from DB (with purchase_summary, purchase_history).
	 * @param string $product_filter The original product filter string.
	 * @return array Array with 'passed' (IDs), 'rejected' (array of {id, name, reason}), 'ai_used'.
	 */
	public function ai_strict_validate( $leads, $product_filter ) {
		if ( ! $this->poe || empty( $leads ) || empty( $product_filter ) ) {
			return array(
				'passed'   => array_column( $leads, 'id' ),
				'rejected' => array(),
				'ai_used'  => false,
			);
		}

		// Build per-lead info for AI
		$lead_info = array();
		foreach ( $leads as $i => $lead ) {
			$name    = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );
			$summary = $lead['purchase_summary'] ?? '';

			// Decode raw purchase history for detailed line items
			$history_items = array();
			$raw_history   = $lead['purchase_history'] ?? '';
			if ( ! empty( $raw_history ) ) {
				$decoded = json_decode( $raw_history, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $item ) {
						$item_name = $item['name'] ?? '';
						if ( ! empty( $item_name ) && ! $this->is_junk_line_item( $item_name ) ) {
							$history_items[] = $item_name;
						}
					}
				}
			}

			$lead_info[] = array(
				'id'         => $lead['id'],
				'number'     => $i + 1,
				'name'       => $name,
				'summary'    => $summary,
				'line_items' => array_unique( $history_items ),
			);
		}

		// Build prompt
		$lines = array();
		foreach ( $lead_info as $info ) {
			$items_str = ! empty( $info['line_items'] )
				? implode( '; ', array_slice( $info['line_items'], 0, 15 ) )
				: 'N/A';
			$lines[] = sprintf(
				"%d. %s\n   Summary: %s\n   Line items: %s",
				$info['number'],
				$info['name'],
				$info['summary'],
				$items_str
			);
		}

		$biz       = (string) apply_filters( 'zl_business_descriptor', __( 'the business', 'zorderz' ) );
		$shorthand = '';
		$alias_map = function_exists( 'zl_product_aliases' ) ? zl_product_aliases() : array();
		if ( ! empty( $alias_map ) ) {
			$shorthand = "TRADE SHORTHAND (treat these as equivalent):\n";
			foreach ( $alias_map as $trigger => $aliases ) {
				$shorthand .= '- "' . $trigger . '" also matches: ' . implode( ', ', (array) $aliases ) . "\n";
			}
			$shorthand .= "\n";
		}
		$prompt = "You are a STRICT product validation assistant for {$biz}.\n\n"
			. "PRODUCT FILTER: \"{$product_filter}\"\n\n"
			. "Below are sales leads with their purchase histories. For each lead, determine if they "
			. "ACTUALLY purchased products that specifically match the filter above.\n\n"
			. $shorthand
			. "VALIDATION RULES:\n"
			. "- A narrower filter category is NOT matched by an unrelated broader item\n"
			. "- A bare generic material/word alone is too vague — the purchase must specifically match the filter categories\n"
			. "- Match customers whose line items fall under the filter categories, including any trade shorthand listed above\n"
			. "- Generic work does NOT match unless the filter is equally generic\n"
			. "- Be STRICT on product category, but ACCEPT the trade shorthand listed above.\n\n"
			. "LEADS:\n" . implode( "\n\n", $lines ) . "\n\n"
			. "For EACH lead, respond with the lead number followed by PASS or REJECT and a brief reason.\n"
			. "Format:\n"
			. "1. PASS - matches the filter category\n"
			. "2. REJECT - purchase is outside the filter category";

		try {
			$response = $this->poe->query( $prompt, null, '', 0.0, array(
				'thinking_budget' => 32768,
				'web_search'      => true,
			) );
			$result = trim( $response );

			$passed   = array();
			$rejected = array();

			// Parse response
			foreach ( explode( "\n", $result ) as $line ) {
				$line = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}

				if ( preg_match( '/^(\d+)[\.\)]\s*(PASS|REJECT)\s*[-–—]?\s*(.*)$/i', $line, $m ) ) {
					$num     = (int) $m[1];
					$verdict = strtoupper( $m[2] );
					$reason  = trim( $m[3] );

					foreach ( $lead_info as $info ) {
						if ( $info['number'] === $num ) {
							if ( $verdict === 'PASS' ) {
								$passed[] = $info['id'];
							} else {
								$rejected[] = array(
									'id'     => $info['id'],
									'name'   => $info['name'],
									'reason' => $reason,
								);
							}
							break;
						}
					}
				}
			}

			// If AI didn't mention some leads, pass them by default
			$all_ids   = array_column( $lead_info, 'id' );
			$mentioned = array_merge( $passed, array_column( $rejected, 'id' ) );
			foreach ( $all_ids as $id ) {
				if ( ! in_array( $id, $mentioned, true ) ) {
					$passed[] = $id;
				}
			}

			error_log( 'ZL AI strict validation: ' . count( $passed ) . ' passed, ' . count( $rejected ) . ' rejected' );

			return array(
				'passed'      => $passed,
				'rejected'    => $rejected,
				'ai_used'     => true,
				'ai_response' => $result,
			);
		} catch ( \Throwable $e ) {
			error_log( 'ZL AI validation error: ' . $e->getMessage() );
			// On error, pass everyone
			return array(
				'passed'   => array_column( $lead_info, 'id' ),
				'rejected' => array(),
				'ai_used'  => false,
				'error'    => $e->getMessage(),
			);
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 7 — Create a Nutshell lead for a given lead
	// ═══════════════════════════════════════════════════════════════════
	//  PIPELINE / STAGESET RESOLUTION (v1.3.0)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * @var array|null Cached stagesets fetched from Nutshell this request.
	 * @since 1.3.0
	 */
	private $cached_stagesets = null;

	/**
	 * Detect the CRM sales pipeline for a lead from its purchase history.
	 *
	 * CRM pipeline routing is a tenant Mapping (crosswalk C1/C10), NOT code. The rules —
	 * which purchase keywords route to which pipeline name/id — are supplied by the tenant
	 * via the `zl_crm_pipeline_rules` filter; Core ships EMPTY, so no product or pipeline
	 * name is hardcoded. Each rule: [ 'pipeline' => string, 'keywords' => string[],
	 * 'all' => bool ] where `all` requires every keyword to match (else any). The
	 * fall-through pipeline is the tenant's `zl_crm_pipeline_default` (empty = let the
	 * resolver use the CRM's own default stageset). Survey pipelines belong to the Surveys
	 * app and are excluded downstream.
	 *
	 * @since 1.3.0
	 * @param string $purchase_history_json JSON-encoded purchase history items.
	 * @param string $purchase_summary      Textual purchase summary.
	 * @return string Pipeline name/id, or '' to defer to the CRM default.
	 */
	private function detect_pipeline( $purchase_history_json, $purchase_summary ) {
		$rules            = (array) apply_filters( 'zl_crm_pipeline_rules', array() );
		$default_pipeline = (string) apply_filters( 'zl_crm_pipeline_default', '' );

		if ( empty( $rules ) ) {
			return $default_pipeline;
		}

		$items = json_decode( $purchase_history_json, true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$text = strtolower( (string) $purchase_summary );
		foreach ( $items as $item ) {
			$text .= ' ' . strtolower( ( $item['name'] ?? '' ) . ' ' . ( $item['description'] ?? '' ) );
		}

		$scores = array();
		foreach ( $rules as $rule ) {
			$pipeline = (string) ( $rule['pipeline'] ?? '' );
			$keywords = array_filter( array_map( 'strtolower', (array) ( $rule['keywords'] ?? array() ) ) );
			if ( $pipeline === '' || empty( $keywords ) ) {
				continue;
			}
			$require_all = ! empty( $rule['all'] );
			$hits        = 0;
			$missed      = false;
			foreach ( $keywords as $kw ) {
				if ( strpos( $text, $kw ) !== false ) {
					$hits++;
				} else {
					$missed = true;
				}
			}
			$matched = $require_all ? ! $missed : ( $hits > 0 );
			if ( $matched ) {
				$scores[ $pipeline ] = ( $scores[ $pipeline ] ?? 0 ) + $hits;
			}
		}

		if ( empty( $scores ) ) {
			return $default_pipeline;
		}
		arsort( $scores );
		return (string) key( $scores );
	}

	/**
	 * Resolve the Nutshell stageset ID for a lead's purchase history.
	 *
	 * Uses detect_pipeline() to identify the target pipeline, then matches
	 * it against the Nutshell account's available stagesets via cascading:
	 *   1. Exact name match
	 *   2. Partial (contains) match
	 *   3. Default Pipeline fallback
	 *
	 * @since 1.3.0
	 * @param string $purchase_history_json JSON-encoded purchase history.
	 * @param string $purchase_summary      Textual purchase summary.
	 * @return int|null The stageset ID, or null if no match.
	 */
	private function resolve_stageset_id( $purchase_history_json, $purchase_summary ) {
		$target = $this->detect_pipeline( $purchase_history_json, $purchase_summary );

		// Fetch + cache stagesets once per request
		if ( null === $this->cached_stagesets ) {
			try {
				$raw = $this->ns->find_stagesets();
				$this->cached_stagesets = is_array( $raw ) ? $raw : array();
				$names = array_map( function( $ss ) {
					return ( $ss['name'] ?? '?' ) . ' (id=' . ( $ss['id'] ?? '?' ) . ')';
				}, $this->cached_stagesets );
				error_log( 'ZL: Nutshell stagesets found: ' . implode( ', ', $names ) );
			} catch ( \Throwable $e ) {
				error_log( 'ZL: Failed to fetch stagesets — ' . $e->getMessage() );
				$this->cached_stagesets = array();
			}
		}

		if ( empty( $this->cached_stagesets ) ) {
			return null;
		}

		$fallback_id = null;
		// The tenant's declared default pipeline name (Mapping), used as the fall-through.
		$default_name = (string) apply_filters( 'zl_crm_pipeline_default', '' );

		// Strategy 1: Exact name match (case-insensitive). Skipped when detection deferred
		// ($target === '') so an empty target can never spuriously match a stageset.
		foreach ( $this->cached_stagesets as $ss ) {
			$ss_name = $ss['name'] ?? '';
			$ss_id   = (int) ( $ss['id'] ?? 0 );

			if ( $target !== '' && strcasecmp( $ss_name, $target ) === 0 ) {
				error_log( "ZL: Pipeline match (exact): \"{$target}\" → stageset id={$ss_id}" );
				return $ss_id;
			}
			if ( $default_name !== '' && strcasecmp( $ss_name, $default_name ) === 0 ) {
				$fallback_id = $ss_id;
			}
		}

		// Strategy 2: Partial match (name contains target or vice versa). Only when a target
		// was detected — an empty needle must never match.
		if ( $target !== '' ) {
			foreach ( $this->cached_stagesets as $ss ) {
				$ss_name = strtolower( $ss['name'] ?? '' );
				$ss_id   = (int) ( $ss['id'] ?? 0 );
				$tgt_low = strtolower( $target );

				if ( $tgt_low !== '' && ( stripos( $ss_name, $tgt_low ) !== false || stripos( $tgt_low, $ss_name ) !== false ) ) {
					error_log( "ZL: Pipeline match (partial): \"{$ss_name}\" ↔ \"{$tgt_low}\" → stageset id={$ss_id}" );
					return $ss_id;
				}
			}
		}

		// Strategy 3: Keyword scoring (break pipeline name into words)
		$best_id    = null;
		$best_score = 0;
		$inv_lower  = strtolower( $purchase_history_json . ' ' . $purchase_summary );
		foreach ( $this->cached_stagesets as $ss ) {
			$ss_name = strtolower( $ss['name'] ?? '' );
			$ss_id   = (int) ( $ss['id'] ?? 0 );
			// Skip satisfaction survey pipelines for sales leads
			if ( strpos( $ss_name, 'satisfaction' ) !== false || strpos( $ss_name, 'survey' ) !== false ) {
				continue;
			}
			$words = preg_split( '/[\s\-\/]+/', $ss_name );
			$score = 0;
			foreach ( $words as $word ) {
				if ( strlen( $word ) <= 3 || in_array( $word, array( 'the', 'and', 'for', 'new', 'all', 'customer' ), true ) ) {
					continue;
				}
				if ( strpos( $inv_lower, $word ) !== false ) {
					$score++;
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_id    = $ss_id;
			}
		}
		if ( $best_score >= 2 ) {
			error_log( "ZL: Pipeline match (keyword score={$best_score}) → stageset id={$best_id}" );
			return $best_id;
		}

		error_log( "ZL: No pipeline match for \"{$target}\". Using fallback id=" . ( $fallback_id ?: 'null' ) );
		return $fallback_id;
	}

	/**
	 * Build a concise, contextual lead description with product counts.
	 *
	 * Instead of generic "Call - Name - Product Filter", builds descriptions like:
	 *   "Call - Jane Doe - 3 Windows, 1 Door"           (categories from Item Engine)
	 *   "Call John Smith → Suggest: <product filter>"
	 *
	 * @since 1.3.0
	 * @param string $full_name             Customer's full name.
	 * @param string $purchase_history_json  JSON-encoded purchase history items.
	 * @param string $product_filter         Product filter (fallback if no items parsed).
	 * @return string Description ≤100 characters.
	 */
	private function build_smart_description( $full_name, $purchase_history_json, $product_filter ) {
		$items = json_decode( $purchase_history_json, true );
		$summary_parts = array();

		// Bucket purchase-history items into the tenant's configured product
		// categories (Item Engine binding via zl_product_categories). Core ships
		// this EMPTY — with no categories configured we omit the product breakdown
		// rather than inventing a trade-specific taxonomy.
		$patterns = zl_product_categories();
		if ( is_array( $items ) && ! empty( $items ) && ! empty( $patterns ) ) {
			$counts = array_fill_keys( array_keys( $patterns ), 0 );

			foreach ( $items as $item ) {
				$name = strtolower( $item['name'] ?? '' );
				$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
				foreach ( $patterns as $category => $keywords ) {
					foreach ( (array) $keywords as $kw ) {
						if ( $kw !== '' && strpos( $name, strtolower( (string) $kw ) ) !== false ) {
							$counts[ $category ] += $qty;
							break 2;
						}
					}
				}
			}

			foreach ( $counts as $type => $qty ) {
				if ( $qty > 0 ) {
					$summary_parts[] = $qty . ' ' . ucfirst( (string) $type );
				}
			}
		}

		$bought_str = ! empty( $summary_parts ) ? implode( ', ', $summary_parts ) : '';

		// v1.7.0 — When a product filter is active, lead with the suggestion
		// so salespeople immediately see what to offer, not just what was bought.
		if ( ! empty( $product_filter ) ) {
			$suggest = ucwords( trim( $product_filter ) );
			$desc    = 'Call ' . $full_name . ' → Suggest: ' . $suggest;
			// Append purchase context if we still have room within the 100 char limit
			if ( ! empty( $bought_str ) && strlen( $desc . ' (Had: ' . $bought_str . ')' ) <= 100 ) {
				$desc .= ' (Had: ' . $bought_str . ')';
			}
		} else {
			$desc = 'Call - ' . $full_name;
			if ( ! empty( $bought_str ) ) {
				$desc .= ' - ' . $bought_str;
			}
		}

		// Final safety truncation to 100 chars (Nutshell limit)
		if ( strlen( $desc ) > 100 ) {
			$desc = substr( $desc, 0, 97 ) . '...';
		}

		return $desc;
	}

	/**
	 * Create a Nutshell lead and associate notes/contacts.
	 *
	 * Ensures the lead description is strictly <101 characters.
	 * If the contact doesn't exist in Nutshell, creates it.
	 *
	 * @param array  $lead_data       Lead data from the zl_leads row.
	 * @param string $salesperson_name Display name of the assigned salesperson.
	 * @param string $product_filter   Filter string to use in description.
	 * @return string Nutshell lead ID, or empty string on failure.
	 */
	public function create_nutshell_lead( $lead_data, $salesperson_name, $product_filter = '' ) {
		$full_name = trim( ( $lead_data['first_name'] ?? '' ) . ' ' . ( $lead_data['last_name'] ?? '' ) );

		$purchase_history_json = $lead_data['purchase_history'] ?? '[]';
		$purchase_summary      = $lead_data['purchase_summary'] ?? '';

		// v1.3.0 — Smart description with product counts instead of generic filter text
		$desc = $this->build_smart_description( $full_name, $purchase_history_json, $product_filter );

		// v1.3.0 — Resolve the correct sales pipeline (stageset) from purchase history
		$stageset_id = $this->resolve_stageset_id( $purchase_history_json, $purchase_summary );
		error_log( "ZL: Lead for \"{$full_name}\": desc=\"{$desc}\", stagesetId=" . ( $stageset_id ?: 'null' ) );

		// ── Determine contact ID — create new person in Nutshell if needed ──
		$contact_id = null;
		if ( ! empty( $lead_data['nutshell_contact_id'] ) ) {
			$contact_id = (int) $lead_data['nutshell_contact_id'];

			// v1.3.0 — Update existing Nutshell person with phone/email from FreshBooks.
			// Even if the person already exists, they may be missing phone/email data.
			$this->update_nutshell_contact_info( $contact_id, $lead_data );
		} else {
			// Person doesn't exist in Nutshell — create them with full details
			$contact_id = $this->create_nutshell_contact( $lead_data );
			if ( $contact_id ) {
				// Store the new contact ID back on the lead record
				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'zl_leads',
					array( 'nutshell_contact_id' => (string) $contact_id ),
					array( 'id' => $lead_data['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		$lead_params = array(
			'description' => $desc,
		);

		// v1.3.0 — Assign the correct sales pipeline
		if ( ! empty( $stageset_id ) ) {
			$lead_params['stagesetId'] = $stageset_id;
		}

		if ( $contact_id ) {
			$lead_params['contacts'] = array(
				array( 'id' => $contact_id ),
			);
		}

		try {
			$result = $this->ns->new_lead( $lead_params );

			if ( isset( $result['id'] ) ) {
				$lead_id = (int) $result['id'];

				// Add a single comprehensive note to the lead (matches Surveys plugin pattern:
				// pass note as plain string, use 'Leads' with capital L for entityType)
				$comprehensive_note = $this->build_comprehensive_lead_note( $lead_data, $salesperson_name );
				try {
					$this->ns->new_note(
						array( 'entityType' => 'Leads', 'id' => $lead_id ),
						$comprehensive_note
					);
					error_log( 'Added comprehensive note to lead #' . $lead_id );
				} catch ( \Throwable $e ) {
					error_log( 'Warning: Could not add note to lead #' . $lead_id . ': ' . $e->getMessage() );
				}

				// If the person has an existing Nutshell contact, add purchase history note to the person
				if ( $contact_id && ! empty( $lead_data['nutshell_contact_id'] ) ) {
					$person_note = $this->build_purchase_history_note( $lead_data );
					if ( ! empty( $person_note ) ) {
						try {
							$this->ns->new_note(
								array( 'entityType' => 'Contacts', 'id' => $contact_id ),
								$person_note
							);
							error_log( 'Added purchase history note to person #' . $contact_id );
						} catch ( \Throwable $e ) {
							error_log( 'Warning: Could not add note to person #' . $contact_id . ': ' . $e->getMessage() );
						}
					}
				}

				return (string) $lead_id;
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZL: Failed to create Nutshell lead: ' . $e->getMessage() );
		}

		return '';
	}

	/**
	 * Create a new Person (contact) in Nutshell with full details:
	 * name, phone, email, address, and purchase history.
	 *
	 * Called when a lead only exists in FreshBooks, not in Nutshell.
	 *
	 * @param array $lead_data Lead data from zl_leads row.
	 * @return int|null The new Nutshell contact ID, or null on failure.
	 */
	private function create_nutshell_contact( $lead_data ) {
		$fname = trim( $lead_data['first_name'] ?? '' );
		$lname = trim( $lead_data['last_name'] ?? '' );

		$contact = array(
			'name' => array(
				'givenName'  => $fname,
				'familyName' => $lname,
			),
		);

		// Phone — normalize to consistent format for Nutshell (v1.3.0)
		if ( ! empty( $lead_data['phone'] ) ) {
			$clean_phone = $this->normalize_phone( $lead_data['phone'] );
			if ( ! empty( $clean_phone ) ) {
				$contact['phone'] = array(
					array( 'number' => $clean_phone ),
				);
			}
		}

		// Email
		if ( ! empty( $lead_data['email'] ) ) {
			$contact['email'] = array(
				array( 'address' => trim( $lead_data['email'] ) ),
			);
		}

		// Address — parse from the "City, ST ZIP" formatted city field
		$address = $this->parse_city_to_address( $lead_data['city'] ?? '' );
		if ( ! empty( $address ) ) {
			$contact['address'] = array( $address );
		}

		// Description — business name comes from the profile (site name fallback), never hardcoded.
		$biz = (string) apply_filters( 'zl_business_name', get_bloginfo( 'name' ) );
		$contact['description'] = trim( ( $biz !== '' ? $biz . ' ' : '' ) . 'customer — created by the lead generator' );

		try {
			$result = $this->ns->new_contact( $contact );

			if ( isset( $result['id'] ) ) {
				$contact_id = (int) $result['id'];
				error_log( 'ZL: Created new Nutshell contact #' . $contact_id . ' for ' . $fname . ' ' . $lname );

				// Add purchase history as a note on the person (pass as plain string, matching Surveys plugin)
				$history_note = $this->build_purchase_history_note( $lead_data );
				if ( ! empty( $history_note ) ) {
					try {
						$this->ns->new_note(
							array( 'entityType' => 'Contacts', 'id' => $contact_id ),
							$history_note
						);
					} catch ( \Throwable $e ) {
						error_log( 'ZL: Failed to add purchase history note to contact #' . $contact_id . ': ' . $e->getMessage() );
					}
				}

				return $contact_id;
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZL: Failed to create Nutshell contact for ' . $fname . ' ' . $lname . ': ' . $e->getMessage() );
		}

		return null;
	}

	/**
	 * Update an existing Nutshell Person contact with phone/email from FreshBooks.
	 *
	 * When a contact already exists in Nutshell but is missing phone or email,
	 * this pushes the FreshBooks data onto the Nutshell Person record so that
	 * the contact info appears in the People listing within leads.
	 *
	 * @since 1.3.0
	 * @param int   $contact_id Nutshell contact ID.
	 * @param array $lead_data  Lead data from the zl_leads row.
	 */
	private function update_nutshell_contact_info( $contact_id, $lead_data ) {
		try {
			// Fetch the existing contact to check what's missing and get the rev number
			$existing = $this->ns->get_contact( $contact_id );
			if ( empty( $existing ) || ! isset( $existing['rev'] ) ) {
				error_log( "ZL: Cannot update contact #{$contact_id} — no rev found." );
				return;
			}

			$rev     = $existing['rev'];
			$updates = array();

			// Check if phone is missing on the existing contact
			$has_phone = false;
			if ( ! empty( $existing['phone'] ) && is_array( $existing['phone'] ) ) {
				foreach ( $existing['phone'] as $ph ) {
					$number = '';
					if ( is_array( $ph ) ) {
						$number = $ph['number'] ?? ( $ph['value'] ?? '' );
					} elseif ( is_string( $ph ) ) {
						$number = $ph;
					}
					if ( ! empty( trim( $number ) ) ) {
						$has_phone = true;
						break;
					}
				}
			}

			// If the existing contact has no phone but we have one from FreshBooks, add it
			if ( ! $has_phone && ! empty( $lead_data['phone'] ) ) {
				$clean_phone = $this->normalize_phone( $lead_data['phone'] );
				if ( ! empty( $clean_phone ) ) {
					$updates['phone'] = array(
						array( 'number' => $clean_phone ),
					);
					error_log( "ZL: Adding phone {$clean_phone} to existing contact #{$contact_id}" );
				}
			}

			// Check if email is missing on the existing contact
			$has_email = false;
			if ( ! empty( $existing['email'] ) && is_array( $existing['email'] ) ) {
				foreach ( $existing['email'] as $em ) {
					$addr = '';
					if ( is_array( $em ) ) {
						$addr = $em['address'] ?? ( $em['value'] ?? '' );
					} elseif ( is_string( $em ) ) {
						$addr = $em;
					}
					if ( ! empty( trim( $addr ) ) ) {
						$has_email = true;
						break;
					}
				}
			}

			// If the existing contact has no email but we have one from FreshBooks, add it
			if ( ! $has_email && ! empty( $lead_data['email'] ) ) {
				$updates['email'] = array(
					array( 'address' => trim( $lead_data['email'] ) ),
				);
				error_log( "ZL: Adding email to existing contact #{$contact_id}" );
			}

			// Only call editContact if there's something to update
			if ( ! empty( $updates ) ) {
				$this->ns->edit_contact( $contact_id, $rev, $updates );
				error_log( "ZL: Updated contact #{$contact_id} with " . implode( ', ', array_keys( $updates ) ) );
			}
		} catch ( \Throwable $e ) {
			// Non-fatal — log but don't block lead creation
			error_log( "ZL: Failed to update contact #{$contact_id}: " . $e->getMessage() );
		}
	}

	/**
	 * Parse the "City, ST ZIP" formatted city field into Nutshell address components.
	 *
	 * Examples:
	 *   "San Marcos, CA 92078" → { city: "San Marcos", state: "CA", postalCode: "92078" }
	 *   "El Cajon 92019"       → { city: "El Cajon", postalCode: "92019" }
	 *   "92129"                → { postalCode: "92129" }
	 *
	 * @param string $city_string The formatted city string.
	 * @return array Nutshell address array, or empty array if unparseable.
	 */
	private function parse_city_to_address( $city_string ) {
		$city_string = trim( $city_string );
		if ( empty( $city_string ) ) {
			return array();
		}

		$address = array();

		// Try to extract ZIP (5-digit number at the end)
		if ( preg_match( '/\b(\d{5})(?:-\d{4})?\s*$/', $city_string, $zip_match ) ) {
			$address['postalCode'] = $zip_match[1];
			$city_string = trim( substr( $city_string, 0, -strlen( $zip_match[0] ) ) );
		}

		// Try to extract state (1-2 uppercase letters at end, after a comma or space)
		if ( preg_match( '/[,\s]\s*([A-Z]{2})\s*$/', $city_string, $state_match ) ) {
			$address['state'] = $state_match[1];
			$city_string = trim( substr( $city_string, 0, -strlen( $state_match[0] ) ) );
			$city_string = rtrim( $city_string, ', ' );
		}

		// Whatever remains is the city name
		if ( ! empty( $city_string ) ) {
			$address['city'] = $city_string;
		}

		return $address;
	}

	/**
	 * Build a formatted purchase history note for a Nutshell contact.
	 *
	 * Lists all non-junk line items with dates, quantities, and amounts.
	 *
	 * @param array $lead_data Lead data from zl_leads row.
	 * @return string Formatted note text, or empty string if no history.
	 */
	private function build_purchase_history_note( $lead_data ) {
		// Decode purchase history JSON
		$raw_history = $lead_data['purchase_history'] ?? '';
		if ( empty( $raw_history ) ) {
			return '';
		}

		$items = is_array( $raw_history ) ? $raw_history : json_decode( $raw_history, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return '';
		}

		// Filter out junk items and build clean list
		$clean_items = array();
		foreach ( $items as $item ) {
			$name = $item['name'] ?? '';
			if ( empty( $name ) || $this->is_junk_line_item( $name ) ) {
				continue;
			}
			$clean_items[] = $item;
		}

		if ( empty( $clean_items ) ) {
			return '';
		}

		// Sort by date (newest first)
		usort( $clean_items, function( $a, $b ) {
			return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
		} );

		// Build the note
		$lines = array();
		$lines[] = "\xF0\x9F\x93\x8B Purchase History";
		$lines[] = "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80";

		foreach ( $clean_items as $item ) {
			$date = $item['date'] ?? '';
			$name = trim( $item['name'] ?? '' );
			$qty  = (int) ( $item['qty'] ?? 1 );
			$amt  = (float) ( $item['amount'] ?? 0 );

			// Clean up name — first line only, cap length
			if ( strpos( $name, "\n" ) !== false ) {
				$name = trim( explode( "\n", $name )[0] );
			}
			if ( strlen( $name ) > 60 ) {
				$name = substr( $name, 0, 57 ) . '...';
			}

			$line = "\xE2\x80\xA2 ";
			if ( ! empty( $date ) && $date !== '2000-01-01' ) {
				$line .= $date . ': ';
			}
			$line .= $name;
			if ( $qty > 1 ) {
				$line .= " (x{$qty})";
			}
			if ( $amt > 0 ) {
				$line .= ' — $' . number_format( $amt, 2 );
			}

			$lines[] = $line;
		}

		$lines[] = "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80";

		// Compute total and most recent date from the items themselves
		// (total_value and most_recent_date aren't stored in the DB)
		$total_value = 0.0;
		$most_recent = '';
		foreach ( $items as $item ) {
			$total_value += (float) ( $item['amount'] ?? 0 );
			$d = $item['date'] ?? '';
			if ( ! empty( $d ) && $d > $most_recent ) {
				$most_recent = $d;
			}
		}

		if ( $total_value > 0 ) {
			$lines[] = 'Total Spend: $' . number_format( $total_value, 2 );
		}

		if ( ! empty( $most_recent ) && $most_recent !== '2000-01-01' ) {
			$lines[] = 'Last Purchase: ' . $most_recent;
		}

		return implode( "\n", $lines );
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 7b — Comprehensive lead note builder (v1.2.0)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Build a comprehensive formatted text note for a Nutshell lead.
	 *
	 * Gives salespeople "x-ray vision" into the customer by combining contact
	 * information, Nutshell custom fields, FreshBooks purchase history, and
	 * inferred product counts into a single structured note.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $lead_data       Lead data from the zl_leads row.
	 * @param string $salesperson_name Display name of the assigned salesperson.
	 * @return string Formatted comprehensive note text.
	 */
	public function build_comprehensive_lead_note( $lead_data, $salesperson_name ) {
		$full_name = trim( ( $lead_data['first_name'] ?? '' ) . ' ' . ( $lead_data['last_name'] ?? '' ) );
		$email     = $lead_data['email'] ?? '';
		$phone     = $lead_data['phone'] ?? '';
		$city      = $lead_data['city'] ?? '';
		$org       = $lead_data['organization'] ?? '';
		$score     = $lead_data['score'] ?? '0';

		$note = '';

		// ── Header ──
		$note .= "\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";
		$note .= "\xF0\x9F\x93\x8B SALES LEAD BRIEF \xE2\x80\x94 {$full_name}\n";
		$note .= "\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\xE2\x95\x90\n";

		// ── Contact Information ──
		$note .= "\n\xF0\x9F\x93\x9E CONTACT INFORMATION\n";
		$note .= "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\n";
		$note .= 'Email: ' . ( ! empty( $email ) ? $email : 'N/A' ) . "\n";
		$note .= 'Phone: ' . ( ! empty( $phone ) ? $phone : 'N/A' ) . "\n";
		$note .= 'Location: ' . ( ! empty( $city ) ? $city : 'N/A' ) . "\n";
		$note .= 'Organization: ' . ( ! empty( $org ) ? $org : 'N/A' ) . "\n";
		$note .= 'Assigned to: ' . $salesperson_name . "\n";

		// ── Customer Profile (from Nutshell custom fields) ──
		$custom_fields = array();
		$raw_cf = $lead_data['nutshell_custom_fields'] ?? '';
		if ( ! empty( $raw_cf ) ) {
			$decoded = is_array( $raw_cf ) ? $raw_cf : json_decode( $raw_cf, true );
			if ( is_array( $decoded ) ) {
				$custom_fields = $decoded;
			}
		}

		if ( ! empty( $custom_fields ) ) {
			$note .= "\n\xF0\x9F\x8F\xA0 CUSTOMER PROFILE (from Nutshell)\n";
			$note .= "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\n";

			// Known fields in preferred display order
			$known_fields = array(
				'Summary of Recent Work We\'ve Done' => "\xE2\x98\x85 Recent Work",
				'Number of Jobs'                     => '# Jobs',
				'Total Billed to Date'               => 'Total Billed',
				'QTY Windows'                        => '# Windows',
				'QTY Doors'                          => '# Doors',
				'QTY Double Doors'                   => '# Double Doors',
				'QTY Single Doors'                   => '# Single Doors',
				'Products Purchased'                 => 'Products',
				'Brands Owned'                       => 'Brands',
				'Grand Zone'                         => 'Zone',
				'Work We\'ve Done'                   => 'Work Done',
			);

			$displayed_keys = array();
			foreach ( $known_fields as $field_name => $label ) {
				if ( isset( $custom_fields[ $field_name ] ) && $custom_fields[ $field_name ] !== '' ) {
					$note .= $label . ': ' . $custom_fields[ $field_name ] . "\n";
					$displayed_keys[ $field_name ] = true;
				}
			}

			// Also display any other non-empty custom fields not in the known list
			foreach ( $custom_fields as $field_name => $field_value ) {
				if ( isset( $displayed_keys[ $field_name ] ) ) {
					continue;
				}
				// Skip internal/meta fields
				if ( strpos( $field_name, '_' ) === 0 || 'Territory' === $field_name ) {
					continue;
				}
				if ( ! empty( $field_value ) ) {
					$note .= $field_name . ': ' . $field_value . "\n";
				}
			}
		}

		// ── Purchase History (from FreshBooks) ──
		$raw_history = $lead_data['purchase_history'] ?? '';
		$items       = array();
		if ( ! empty( $raw_history ) ) {
			$items = is_array( $raw_history ) ? $raw_history : json_decode( $raw_history, true );
			if ( ! is_array( $items ) ) {
				$items = array();
			}
		}

		// Filter out junk items
		$clean_items = array();
		foreach ( $items as $item ) {
			$name = $item['name'] ?? '';
			if ( ! empty( $name ) && ! $this->is_junk_line_item( $name ) ) {
				$clean_items[] = $item;
			}
		}

		// Sort by date (newest first)
		if ( ! empty( $clean_items ) ) {
			usort( $clean_items, function( $a, $b ) {
				return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
			} );
		}

		$note .= "\n\xF0\x9F\x92\xB0 PURCHASE HISTORY (from FreshBooks)\n";
		$note .= "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\n";

		if ( ! empty( $clean_items ) ) {
			foreach ( $clean_items as $item ) {
				$date = $item['date'] ?? '';
				$name = trim( $item['name'] ?? '' );
				$qty  = (int) ( $item['qty'] ?? 1 );
				$amt  = (float) ( $item['amount'] ?? 0 );

				// Clean up name — first line only, cap length
				if ( strpos( $name, "\n" ) !== false ) {
					$name = trim( explode( "\n", $name )[0] );
				}
				if ( strlen( $name ) > 60 ) {
					$name = substr( $name, 0, 57 ) . '...';
				}

				$line = "\xE2\x80\xA2 ";
				if ( ! empty( $date ) && $date !== '2000-01-01' ) {
					$line .= $date . ': ';
				}
				$line .= $name;
				if ( $qty > 1 ) {
					$line .= " (x{$qty})";
				}
				if ( $amt > 0 ) {
					$line .= " \xE2\x80\x94 \$" . number_format( $amt, 2 );
				}

				$note .= $line . "\n";
			}
		} else {
			$note .= "No purchase history available.\n";
		}

		// Compute total spend from items
		$total_spend = 0.0;
		foreach ( $items as $item ) {
			$total_spend += (float) ( $item['amount'] ?? 0 );
		}

		// Use lead_data total_value if available and items total is zero
		if ( $total_spend <= 0 && isset( $lead_data['total_value'] ) ) {
			$total_spend = (float) $lead_data['total_value'];
		}

		$note .= 'Total Customer Spend: $' . number_format( $total_spend, 2 ) . "\n";
		$note .= 'Lead Score: ' . $score . "/100\n";

		// ── Estimated Product Counts ──
		$product_counts = $this->infer_product_counts( $clean_items );

		if ( ! empty( $product_counts ) ) {
			$note .= "\n\xF0\x9F\x93\x8A ESTIMATED PRODUCT COUNTS\n";
			$note .= "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\n";
			foreach ( $product_counts as $product_type => $count ) {
				$note .= $product_type . ': ~' . $count . "\n";
			}
		}

		// ── Footer ──
		$note .= "\n\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\n";
		$user_initials = self::get_user_initials();
		$note .= "Generated by Zorderz Leads v" . ZL_VERSION . " ({$user_initials})\n";

		return $note;
	}

	// ═══════════════════════════════════════════════════════════════════
	// STEP 8 — Generate AI batch summary
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Generate an executive summary of the entire batch using AI.
	 * 
	 * @param int $batch_id The batch to summarize.
	 * @return string Summary text.
	 */
	public function ai_batch_summary( $batch_id ) {
		if ( ! $this->poe ) {
			return 'AI summary not available (Poe API key not configured).';
		}

		global $wpdb;
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zl_leads WHERE batch_id = %d ORDER BY score DESC",
			$batch_id
		), ARRAY_A );

		if ( empty( $leads ) ) {
			return 'No leads in this batch.';
		}

		$lines = array();
		foreach ( $leads as $l ) {
			$lines[] = sprintf(
				'- %s %s | %s | Score: %s | %s',
				$l['first_name'],
				$l['last_name'],
				$l['city'] ?: 'Unknown city',
				$l['score'],
				$l['purchase_summary']
			);
		}

		$user_initials = self::get_user_initials();
		$prompt = "You are a sales operations assistant. Summarize this batch of sales leads for " . ( (string) apply_filters( 'zl_business_descriptor', __( 'the business', 'zorderz' ) ) ) . ".\n\n"
			. "Generated by: {$user_initials}\n\n"
			. "Leads:\n" . implode( "\n", $lines ) . "\n\n"
			. "Write a 2-3 sentence executive summary covering: total lead count, geographic spread, top product opportunities, and average lead quality. Be concise.";

		try {
			return $this->poe->query( $prompt, null, '', 0.0, array(
				'thinking_budget' => 32768,
				'web_search'      => true,
			) );
		} catch ( \Throwable $e ) {
			error_log( 'ZL AI summary error: ' . $e->getMessage() );
			return 'AI summary generation failed. ' . count( $leads ) . ' leads generated successfully.';
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// DB helpers
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Insert a lead into zl_leads.
	 *
	 * @param int   $batch_id   Batch ID.
	 * @param array $lead_data  Enriched candidate data.
	 * @param float $score      Computed score.
	 * @return int Inserted row ID.
	 */
	public function save_lead_to_db( $batch_id, $lead_data, $score ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'zl_leads',
			array(
				'batch_id'               => $batch_id,
				'freshbooks_client_id'   => $lead_data['freshbooks_client_id'],
				'nutshell_contact_id'    => $lead_data['nutshell_contact_id'],
				'first_name'             => $lead_data['first_name'],
				'last_name'              => $lead_data['last_name'],
				'email'                  => $lead_data['email'],
				'phone'                  => $lead_data['phone'],
				'city'                   => $lead_data['city'],
				'organization'           => $lead_data['organization'],
				'territory'              => $lead_data['territory'],
				'purchase_summary'       => $lead_data['purchase_summary'],
				'purchase_history'       => is_string( $lead_data['purchase_history'] )
					? $lead_data['purchase_history']
					: wp_json_encode( $lead_data['purchase_history'] ),
				'nutshell_interests'     => $lead_data['nutshell_interests'],
				'nutshell_custom_fields' => isset( $lead_data['nutshell_custom_fields'] ) ? $lead_data['nutshell_custom_fields'] : '',
				'score'                  => $score,
				'status'                 => 'pending',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Update lead history for de-duplication tracking.
	 * 
	 * Records that a customer was generated as a lead to enforce the cooldown period.
	 * 
	 * @param string $freshbooks_client_id FreshBooks customer ID.
	 * @param string $email Customer email.
	 * @param int    $batch_id Batch ID.
	 */
	public function update_lead_history( $freshbooks_client_id, $email, $batch_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'zl_lead_history';

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE freshbooks_client_id = %s",
			$freshbooks_client_id
		) );

		$now = current_time( 'mysql', true );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'last_generated_at' => $now,
					'last_batch_id'     => $batch_id,
					'times_generated'   => $existing->times_generated + 1,
					'email'             => $email,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'freshbooks_client_id' => $freshbooks_client_id,
					'email'                => $email,
					'last_generated_at'    => $now,
					'last_batch_id'        => $batch_id,
					'times_generated'      => 1,
				),
				array( '%s', '%s', '%s', '%d', '%d' )
			);
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Nutshell Sync Features
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Sync a lead's status and salesperson notes from Nutshell.
	 * 
	 * @param array $lead_row The lead data from the database.
	 * @return array|false Array with status and notes, or false if sync failed.
	 */
	public function sync_lead_from_nutshell( $lead_row ) {
		if ( empty( $lead_row['nutshell_lead_id'] ) ) {
			return false;
		}

		$lead_id = (int) $lead_row['nutshell_lead_id'];
		$ns_lead = $this->ns->get_lead( $lead_id );

		// If Nutshell returns nothing, the lead was fully deleted/purged.
		// Return 'Deleted' status so the sync handler can clear the cooldown.
		if ( empty( $ns_lead ) ) {
			return array(
				'status' => 'Deleted',
				'notes'  => '',
			);
		}

		// Timeline fetch is non-fatal — sync should still work for status/deleted detection
		$timeline = array();
		try {
			$timeline = $this->ns->find_timeline_for_lead( $lead_id );
		} catch ( \Throwable $e ) {
			error_log( 'ZL: Timeline fetch failed for lead #' . $lead_id . ' (non-fatal): ' . $e->getMessage() );
		}

		$status = $this->get_nutshell_lead_status( $ns_lead );
		$notes  = $this->extract_salesperson_notes( $timeline );

		return array(
			'status' => $status,
			'notes'  => $notes,
		);
	}

	/**
	 * Extract salesperson notes from a Nutshell timeline.
	 * 
	 * @param array $timeline Timeline entries from Nutshell.
	 * @return string Extracted notes.
	 */
	public function extract_salesperson_notes( $timeline ) {
		if ( empty( $timeline ) || ! is_array( $timeline ) ) {
			return '';
		}

		$notes = array();
		foreach ( $timeline as $entry ) {
			$type = isset( $entry['entityType'] ) ? $entry['entityType'] : '';
			if ( $type === 'Activities' || $type === 'Notes' ) {
				$note_text = '';
				if ( isset( $entry['note'] ) ) {
					$note_text = $entry['note'];
				} elseif ( isset( $entry['body'] ) ) {
					$note_text = $entry['body'];
				}

				if ( ! empty( $note_text ) ) {
					$date = isset( $entry['createdTime'] ) ? date( 'Y-m-d', strtotime( $entry['createdTime'] ) ) : '';
					$notes[] = "[{$date}] {$note_text}";
				}
			}
		}

		return implode( "\n\n", $notes );
	}

	/**
	 * Extract the status from a Nutshell lead object.
	 * 
	 * @param array $ns_lead Nutshell lead object.
	 * @return string Lead status.
	 */
	public function get_nutshell_lead_status( $ns_lead ) {
		$status_name = 'Open';
		if ( isset( $ns_lead['status'] ) ) {
			if ( is_array( $ns_lead['status'] ) && isset( $ns_lead['status']['id'] ) ) {
				$sid = (int) $ns_lead['status']['id'];
				$status_map = array( 0 => 'Open', 1 => 'Won', 2 => 'Lost', 3 => 'Cancelled' );
				$status_name = isset( $status_map[ $sid ] ) ? $status_map[ $sid ] : 'Open';
			} elseif ( is_array( $ns_lead['status'] ) && isset( $ns_lead['status']['name'] ) ) {
				$status_name = $ns_lead['status']['name'];
			}
		}
		if ( ! empty( $ns_lead['deletedTime'] ) ) {
			$status_name = 'Deleted';
		}
		return $status_name;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Getters
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Get the current settings array.
	 * 
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Get the configured salespeople array.
	 * 
	 * @return array
	 */
	public function get_salespeople() {
		return $this->settings['salespeople'];
	}

	/**
	 * Check if the AI client is available.
	 * 
	 * @return bool
	 */
	public function has_ai() {
		return $this->poe !== null;
	}
}