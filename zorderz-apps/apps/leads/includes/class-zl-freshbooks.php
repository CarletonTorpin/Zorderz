<?php
/**
 * FILE: class-zl-freshbooks.php
 * MODULE: Zorderz Leads
 * 
 * ARCHITECTURE ROLE:
 * This class is the FreshBooks REST API client. It handles OAuth 2.0 authentication,
 * account discovery, and fetching the core data needed for the lead generation pipeline.
 * Specifically, it powers Step 2 (Fetch Invoices) and Step 3 (Enrich Chunks) of the 
 * 8-step AJAX generation flow.
 * 
 * BUSINESS CONTEXT:
 * the business uses FreshBooks for invoicing. We mine historical paid invoices to find
 * past customers who may need new work. 
 * - In Step 2, we fetch all paid invoices within a lookback period (extended in v1.2.0).
 * - In Step 3, we fetch the specific Client details (address) to determine the zip code
 *   for strict territory assignment (by territory code).
 * 
 * ITERATION NOTES:
 * - FreshBooks API requires an `account_id` for accounting endpoints. This is auto-discovered
 *   during the OAuth flow and saved in WP options.
 * - Pagination is handled automatically in `get_paid_invoices` (100 per page).
 * - If FreshBooks changes their OAuth callback requirements, update `get_auth_url` and `exchange_code`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class ZL_FreshBooks {

	private $client_id;
	private $client_secret;
	private $account_id;
	private $access_token;

	const AUTH_URL  = 'https://auth.freshbooks.com/service/auth/oauth/authorize';
	const TOKEN_URL = 'https://api.freshbooks.com/auth/oauth/token';
	const API_BASE  = 'https://api.freshbooks.com';

	/**
	 * Constructor.
	 * 
	 * PURPOSE: Initializes the client with credentials.
	 * CALLERS: `ZL_Admin` (for setup), `ZL_Lead_Generator` (for pipeline execution).
	 *
	 * @param string $client_id     FreshBooks Client ID.
	 * @param string $client_secret FreshBooks Client Secret.
	 * @param string $account_id    Optional. FreshBooks Account ID (discovered after auth).
	 */
	public function __construct( $client_id, $client_secret, $account_id = '' ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->account_id    = $account_id;
		$this->access_token  = null;
	}

	/**
	 * Get the OAuth2 authorization URL.
	 * 
	 * PURPOSE: Generates the URL to redirect the admin user to FreshBooks for login.
	 * SIDE EFFECTS: None.
	 *
	 * @return string Authorization URL.
	 */
	public function get_auth_url() {
		// v1.5.3 — Fixed: must match ZL_Admin::handle_oauth_callback() which listens on zl-callback.
		// Previously used 'ts-surveys-callback' which routed to the Surveys plugin's handler instead,
		// causing tokens to be stored in ts_surveys_fb_* options rather than zl_fb_* options.
		// NOTE: The redirect URI must also be registered in the FreshBooks Developer Portal.
		$redirect_uri = admin_url( 'admin.php?page=zl-callback' );
		$params       = array(
			'client_id'     => $this->client_id,
			'response_type' => 'code',
			'redirect_uri'  => $redirect_uri,
		);

		return add_query_arg( $params, self::AUTH_URL );
	}

	/**
	 * Exchange an authorization code for an access token.
	 * 
	 * PURPOSE: Completes the OAuth 2.0 flow.
	 * CALLERS: `class-zl-admin.php` during the callback handler.
	 * ERROR HANDLING: Throws Exception on network failure or non-200 response.
	 *
	 * @param string $code The authorization code.
	 * @return array The token response data.
	 * @throws Exception If the request fails.
	 */
	public function exchange_code( $code ) {
		// v1.5.3 — Must match get_auth_url() and handle_oauth_callback()
		$redirect_uri = admin_url( 'admin.php?page=zl-callback' );
		$body         = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri'  => $redirect_uri,
		);

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'FreshBooks auth request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_json   = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			throw new Exception( "FreshBooks auth failed ({$status_code}): " . substr( $body_json, 0, 300 ) );
		}

		$data = json_decode( $body_json, true );
		if ( ! isset( $data['access_token'] ) ) {
			throw new Exception( 'Invalid token response from FreshBooks.' );
		}

		$this->access_token = $data['access_token'];
		return $data;
	}

	/**
	 * Auto-discover the Account ID from the authenticated user.
	 * 
	 * PURPOSE: FreshBooks API endpoints require an Account ID, which is distinct from the Client ID.
	 * This hits the `/users/me` endpoint to find the business account associated with the token.
	 * CALLERS: `class-zl-admin.php` immediately after `exchange_code`.
	 *
	 * @return string The Account ID.
	 * @throws Exception If the account ID cannot be determined.
	 */
	public function discover_account_id() {
		if ( ! empty( $this->account_id ) ) {
			return $this->account_id;
		}

		$response = wp_remote_get(
			self::API_BASE . '/auth/api/v1/users/me',
			array(
				'headers' => $this->_headers(),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'FreshBooks auto-detect request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			throw new Exception( "Could not auto-detect your FreshBooks Account ID. API returned status {$status_code}." );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$resp = isset( $data['response'] ) ? $data['response'] : array();

		// FreshBooks data structure for users can vary based on account type (business vs individual)
		$memberships = isset( $resp['business_memberships'] ) ? $resp['business_memberships'] : array();

		if ( empty( $memberships ) ) {
			// Fallback for older/different account structures
			$roles = isset( $resp['roles'] ) ? $resp['roles'] : array();
			if ( ! empty( $roles ) ) {
				$this->account_id = isset( $roles[0]['accountid'] ) ? (string) $roles[0]['accountid'] : '';
			}
			if ( empty( $this->account_id ) ) {
				throw new Exception( 'Could not find a business account linked to your FreshBooks login. Check your FreshBooks account.' );
			}
		} else {
			// Standard business membership extraction
			$biz              = isset( $memberships[0]['business'] ) ? $memberships[0]['business'] : array();
			$this->account_id = isset( $biz['account_id'] ) ? (string) $biz['account_id'] : ( isset( $biz['id'] ) ? (string) $biz['id'] : '' );
		}

		if ( empty( $this->account_id ) ) {
			throw new Exception( 'Could not determine your FreshBooks Account ID.' );
		}

		return $this->account_id;
	}

	/**
	 * Fetch paid invoices from the last X days.
	 * 
	 * PURPOSE: Core data extraction for Step 2 of the pipeline.
	 * BUSINESS LOGIC: Only fetches invoices with status=4 (Paid). 
	 * In v1.2.0, lookback was extended (e.g., "Since 2000").
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of verified paid invoices.
	 * @throws Exception If the API request fails.
	 */
	public function get_paid_invoices( $days = 30 ) {
		$date_min = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$invoices = array();
		$page     = 1;

		// Pagination loop: fetches 100 invoices per request until all pages are consumed.
		while ( true ) {
			$url = self::API_BASE . "/accounting/account/{$this->account_id}/invoices/invoices";
			$url = add_query_arg(
				array(
					'search[status]'   => 4,
					'search[date_min]' => $date_min,
					'include[]'        => 'lines',
					'page'             => $page,
					'per_page'         => 100,
				),
				$url
			);

			$response = wp_remote_get(
				$url,
				array(
					'headers' => $this->_headers(),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'FreshBooks invoice fetch request failed: ' . $response->get_error_message() );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body_json   = wp_remote_retrieve_body( $response );

			if ( 200 !== $status_code ) {
				throw new Exception( "FreshBooks invoice fetch failed ({$status_code}): " . substr( $body_json, 0, 300 ) );
			}

			$data   = json_decode( $body_json, true );
			$result = isset( $data['response']['result'] ) ? $data['response']['result'] : array();

			if ( ! empty( $result['invoices'] ) ) {
				$invoices = array_merge( $invoices, $result['invoices'] );
			}

			$total_pages = isset( $result['pages'] ) ? (int) $result['pages'] : 1;
			if ( $page >= $total_pages ) {
				break;
			}
			$page++;
		}

		// Belt-and-suspenders: verify each invoice is truly paid.
		// Sometimes status=4 invoices might have a tiny outstanding balance due to rounding/adjustments.
		$verified = array();
		foreach ( $invoices as $inv ) {
			$outstanding = $this->safe_amount( isset( $inv['outstanding'] ) ? $inv['outstanding'] : array() );
			if ( $outstanding > 0.01 ) {
				continue; // Still has a balance — not fully paid.
			}
			$verified[] = $inv;
		}

		return $verified;
	}

	/**
	 * Fetch a SINGLE PAGE of paid invoices from FreshBooks.
	 *
	 * Added in v1.2.3 — Used by the paginated AJAX fetch to retrieve one page
	 * at a time, preventing web server proxy timeouts on long lookback periods.
	 * Each call makes exactly ONE FreshBooks API request (~2-3s).
	 *
	 * @param int $days     Number of days to look back.
	 * @param int $page     Page number (1-based).
	 * @param int $per_page Invoices per page (default 100).
	 * @return array {
	 *     @type array $invoices    Verified paid invoices for this page.
	 *     @type int   $page        Current page number.
	 *     @type int   $total_pages Total number of pages.
	 *     @type int   $total       Total number of invoices across all pages.
	 * }
	 * @throws Exception If the API request fails.
	 */
	public function get_paid_invoices_page( $days = 30, $page = 1, $per_page = 100, $skip_lines = false ) {
		$date_min      = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$fallback_used = false;
		$diag_info     = array(); // v1.5.3 — Diagnostic breadcrumbs for the dashboard

		// v1.5.3 — If a previous page already determined include[]=lines fails,
		// skip it entirely on this page (saves a wasted API call per page).
		if ( $skip_lines ) {
			$result        = $this->_fetch_invoice_page_raw( $date_min, $page, $per_page, false, $diag_info );
			$fallback_used = true;
			$result['_fallback_used'] = $fallback_used;
			$result['_diag_info']     = $diag_info;
			return $result;
		}

		// First attempt: include lines for product filtering.
		$include_lines = true;
		$result        = $this->_fetch_invoice_page_raw( $date_min, $page, $per_page, $include_lines, $diag_info );

		// ── v1.5.3 — Smart fallback on page 1 zero-result ────────────────────
		// If page 1 returns 0 total invoices WITH include[]=lines, the FreshBooks
		// API may be rejecting the request due to OAuth scope restrictions, API
		// changes, or URL encoding issues. We retry WITHOUT include[]=lines.
		// If THAT succeeds, the pipeline continues (without line-item product
		// filtering) — some leads are better than zero leads.
		if ( 1 === (int) $page && 0 === (int) $result['total'] ) {
			error_log( 'ZL FreshBooks v1.5.3: Page 1 returned 0 invoices WITH include[]=lines — retrying WITHOUT it...' );
			$diag_info['fallback_reason'] = 'Zero invoices with include[]=lines, retrying without';

			$fallback_result = $this->_fetch_invoice_page_raw( $date_min, $page, $per_page, false, $diag_info );

			if ( $fallback_result['total'] > 0 ) {
				error_log( 'ZL FreshBooks v1.5.3: ✓ Fallback succeeded! Found ' . $fallback_result['total'] . ' invoices WITHOUT include[]=lines.' );
				error_log( 'ZL FreshBooks v1.5.3: ⚠ Line items will be missing — product filtering will be limited.' );
				$diag_info['fallback_success'] = true;
				$diag_info['fallback_total']   = $fallback_result['total'];
				$result        = $fallback_result;
				$fallback_used = true;
			} else {
				error_log( 'ZL FreshBooks v1.5.3: ✗ Fallback also returned 0 invoices. Issue is NOT include[]=lines.' );
				$diag_info['fallback_success'] = false;

				// v1.5.3 — Last-resort: try with per_page=1 (matches diagnostic test exactly)
				error_log( 'ZL FreshBooks v1.5.3: Trying per_page=1 (diagnostic-mirror test)...' );
				$mirror_result = $this->_fetch_invoice_page_raw( $date_min, 1, 1, false, $diag_info );
				if ( $mirror_result['total'] > 0 ) {
					error_log( 'ZL FreshBooks v1.5.3: ✓ per_page=1 found ' . $mirror_result['total'] . ' invoices! Issue is per_page parameter.' );
					// Re-fetch with per_page=100 but no include[]=lines (shouldn't be needed but let's try)
					$diag_info['mirror_success'] = true;
					$diag_info['mirror_total']   = $mirror_result['total'];
				} else {
					error_log( 'ZL FreshBooks v1.5.3: ✗ per_page=1 also returned 0. Deep credential/account issue.' );
					$diag_info['mirror_success'] = false;
				}
			}
		}

		// v1.5.3 — Verbose diagnostic logging on page 1 when 0 invoices
		if ( 0 === (int) $result['total'] && 1 === (int) $page ) {
			error_log( '═══════════════════════════════════════════════════' );
			error_log( 'ZL FreshBooks v1.5.3 DIAGNOSTIC — 0 INVOICES ON PAGE 1' );
			error_log( '  account_id:   ' . ( ! empty( $this->account_id ) ? $this->account_id : '(EMPTY)' ) );
			error_log( '  date_min:     ' . $date_min . ' (lookback: ' . $days . ' days)' );
			error_log( '  per_page:     ' . $per_page );
			error_log( '  token:        ' . ( ! empty( $this->access_token ) ? 'present (' . strlen( $this->access_token ) . ' chars)' : 'MISSING' ) );
			error_log( '  client_id:    ' . ( ! empty( $this->client_id ) ? substr( $this->client_id, 0, 12 ) . '...' : '(EMPTY)' ) );
			error_log( '  client_secret:' . ( ! empty( $this->client_secret ) ? 'set (' . strlen( $this->client_secret ) . ' chars)' : '(EMPTY/FALSE)' ) );
			if ( ! empty( $diag_info ) ) {
				error_log( '  diag_info:    ' . wp_json_encode( $diag_info ) );
			}
			error_log( '═══════════════════════════════════════════════════' );
		}

		$result['_fallback_used'] = $fallback_used;
		$result['_diag_info']     = $diag_info;
		return $result;
	}

	/**
	 * Internal: Execute a single FreshBooks invoice page fetch with retry logic.
	 *
	 * Extracted in v1.5.3 from get_paid_invoices_page() to support the smart
	 * fallback mechanism (retry without include[]=lines when 0 results).
	 *
	 * @since 1.5.3
	 * @param string $date_min      Minimum date (Y-m-d).
	 * @param int    $page          Page number.
	 * @param int    $per_page      Results per page.
	 * @param bool   $include_lines Whether to add include[]=lines.
	 * @param array  &$diag_info    Diagnostic breadcrumbs (passed by reference).
	 * @return array { invoices, page, total_pages, total }
	 * @throws Exception On unrecoverable API failure.
	 */
	private function _fetch_invoice_page_raw( $date_min, $page, $per_page, $include_lines, &$diag_info ) {
		$query_args = array(
			'search[status]'   => 4,
			'search[date_min]' => $date_min,
			'page'             => $page,
			'per_page'         => $per_page,
		);
		if ( $include_lines ) {
			$query_args['include[]'] = 'lines';
		}

		$url = self::API_BASE . "/accounting/account/{$this->account_id}/invoices/invoices";
		$url = add_query_arg( $query_args, $url );

		// v1.5.3 — Log the exact URL on page 1 for debugging
		$tag = $include_lines ? 'WITH-lines' : 'NO-lines';
		if ( 1 === (int) $page ) {
			error_log( "ZL FreshBooks [{$tag}]: URL = " . $url );
		}

		// v1.3.0 — Retry with exponential backoff for transient server errors
		$max_retries    = 3;
		$delay          = 3; // Initial retry delay in seconds
		$last_exception = null;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => $this->_headers(),
					'timeout' => 45, // Increased from 30s for large result sets
				)
			);

			// Connection-level error (DNS failure, cURL timeout) — retry
			if ( is_wp_error( $response ) ) {
				$last_exception = new Exception( 'FreshBooks invoice fetch request failed: ' . $response->get_error_message() );
				if ( $attempt < $max_retries ) {
					error_log( "ZL FreshBooks [{$tag}]: Connection error on page {$page} attempt {$attempt}, retrying in {$delay}s..." );
					sleep( $delay );
					$delay *= 2;
					continue;
				}
				throw $last_exception;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body_json   = wp_remote_retrieve_body( $response );

			// v1.5.3 — Log response status and body snippet on page 1
			if ( 1 === (int) $page && 1 === $attempt ) {
				error_log( "ZL FreshBooks [{$tag}]: HTTP {$status_code}, body[0:300] = " . substr( $body_json, 0, 300 ) );
				$diag_info["http_{$tag}"]      = (int) $status_code;
				$diag_info["body_snip_{$tag}"]  = substr( $body_json, 0, 300 );
			}

			// Server errors (500, 502, 503, 504) — retry with backoff
			if ( in_array( (int) $status_code, array( 500, 502, 503, 504 ), true ) && $attempt < $max_retries ) {
				error_log( "ZL FreshBooks [{$tag}]: HTTP {$status_code} on page {$page} attempt {$attempt}/{$max_retries}, retrying in {$delay}s..." );
				$last_exception = new Exception( "FreshBooks invoice fetch failed ({$status_code}): " . substr( $body_json, 0, 300 ) );
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			// v1.5.1 — Auto-refresh on 401 Unauthorized (expired token)
			if ( 401 === (int) $status_code ) {
				error_log( "ZL FreshBooks [{$tag}]: 401 Unauthorized on page {$page} — attempting token refresh..." );
				if ( $this->refresh_access_token() ) {
					error_log( "ZL FreshBooks [{$tag}]: Token refreshed, retrying page {$page}..." );
					// Don't count as a retry — just re-enter the loop with fresh token
					continue;
				}
				throw new Exception(
					'FreshBooks token expired and refresh failed. Please reconnect FreshBooks in Settings → Leads.'
				);
			}

			// Non-200, non-retryable error
			if ( 200 !== (int) $status_code ) {
				throw new Exception( "FreshBooks invoice fetch failed ({$status_code}): " . substr( $body_json, 0, 300 ) );
			}

			// Success — break out of retry loop
			break;
		}

		// Exhausted all retries
		if ( ! isset( $status_code ) || 200 !== (int) $status_code ) {
			throw $last_exception ?: new Exception( 'FreshBooks API failed after ' . $max_retries . ' retries on page ' . $page );
		}

		$data   = json_decode( $body_json, true );
		$result = isset( $data['response']['result'] ) ? $data['response']['result'] : array();

		$page_invoices = ! empty( $result['invoices'] ) ? $result['invoices'] : array();
		$total_pages   = isset( $result['pages'] ) ? (int) $result['pages'] : 1;
		$total         = isset( $result['total'] )  ? (int) $result['total']  : count( $page_invoices );

		// Verify each invoice is truly paid (belt-and-suspenders for rounding issues)
		$verified = array();
		foreach ( $page_invoices as $inv ) {
			$outstanding = $this->safe_amount( isset( $inv['outstanding'] ) ? $inv['outstanding'] : array() );
			if ( $outstanding > 0.01 ) {
				continue;
			}
			$verified[] = $inv;
		}

		return array(
			'invoices'    => $verified,
			'page'        => $page,
			'total_pages' => $total_pages,
			'total'       => $total,
		);
	}

	/**
	 * Fetch a single client's details.
	 * 
	 * PURPOSE: Used in Step 3 (Enrichment) to get the client's address/zip code.
	 * BUSINESS LOGIC: The zip code is critical for strict territory assignment (by territory code).
	 *
	 * @param int|string $customerid The FreshBooks Customer ID.
	 * @return array|null The client data, or null if not found.
	 */
	public function get_client( $customerid ) {
		$url = self::API_BASE . "/accounting/account/{$this->account_id}/users/clients/{$customerid}";

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->_headers(),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['response']['result']['client'] ) ? $data['response']['result']['client'] : null;
	}

	/**
	 * Helper to generate API headers.
	 * 
	 * PURPOSE: Injects the OAuth Bearer token into requests.
	 *
	 * @return array Headers array.
	 */
	private function _headers() {
		return array(
			'Authorization' => "Bearer {$this->access_token}",
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Helper to safely extract an amount from a FreshBooks money object.
	 * 
	 * PURPOSE: FreshBooks API returns monetary values either as a float or an object 
	 * containing 'amount' and 'code' (currency). This normalizes it.
	 *
	 * @param mixed $amount_obj The amount object or value.
	 * @return float The numeric amount.
	 */
	private function safe_amount( $amount_obj ) {
		if ( is_array( $amount_obj ) && isset( $amount_obj['amount'] ) ) {
			return (float) $amount_obj['amount'];
		}
		return (float) $amount_obj;
	}

	/**
	 * Set the access token manually (e.g., from DB).
	 * 
	 * PURPOSE: Allows the lead generator engine to instantiate the client using 
	 * credentials stored in the WordPress options table, bypassing the OAuth flow.
	 *
	 * @param string $token The access token.
	 */
	public function set_access_token( $token ) {
		$this->access_token = $token;
	}

	/**
	 * Refresh the OAuth access token using the stored refresh token.
	 *
	 * FreshBooks OAuth tokens expire after ~12 hours. This method uses the
	 * refresh_token (stored during initial authorization) to obtain a new
	 * access_token without requiring the user to re-authorize.
	 *
	 * Updates both the in-memory access_token and the persisted WP options
	 * so subsequent requests (and future page loads) use the new token.
	 *
	 * @since 1.5.1
	 * @return bool True if refresh succeeded and new token is active.
	 */
	public function refresh_access_token() {
		// Try ZL's own refresh token first, fall back to Surveys plugin
		$refresh_token = get_option( 'zl_fb_refresh_token', '' );
		if ( empty( $refresh_token ) ) {
			$refresh_token = get_option( 'ts_surveys_fb_refresh_token', '' );
		}

		if ( empty( $refresh_token ) ) {
			error_log( 'ZL FreshBooks: No refresh token available — re-authorization required.' );
			return false;
		}

		error_log( 'ZL FreshBooks: Attempting token refresh...' );

		$response = wp_remote_post( self::TOKEN_URL, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'ZL FreshBooks: Token refresh request failed: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $status_code || ! isset( $body['access_token'] ) ) {
			error_log( 'ZL FreshBooks: Token refresh failed (HTTP ' . $status_code . '): ' . substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
			return false;
		}

		// Update in-memory token
		$this->access_token = $body['access_token'];

		// Persist new tokens to WP options for future page loads
		update_option( 'zl_fb_access_token', $body['access_token'] );
		if ( isset( $body['refresh_token'] ) ) {
			update_option( 'zl_fb_refresh_token', $body['refresh_token'] );
		}

		error_log( 'ZL FreshBooks: Token refreshed successfully.' );
		return true;
	}
}