<?php
/**
 * Shared FreshBooks OAuth 2.0 Client
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_FreshBooks {

	private string $client_id;
	private string $client_secret;
	private string $access_token;
	private string $refresh_token;
	private string $account_id;
	private string $api_base = 'https://api.freshbooks.com';

	public function __construct() {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			$this->client_id     = ZDZ_Core_Settings::get_fb_client_id();
			$this->client_secret = ZDZ_Core_Settings::get_fb_client_secret();
			$this->access_token  = ZDZ_Core_Settings::get_fb_access_token();
			$this->refresh_token = ZDZ_Core_Settings::get_fb_refresh_token();
			$this->account_id    = ZDZ_Core_Settings::get_fb_account_id();
		}
	}

	private function get_auth_headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
			'Api-Version'   => 'alpha',
		];
	}

	/**
	 * Refresh the FreshBooks access token.
	 *
	 * v2.26.0 (Token-race fix — authoritative service): when the ZDZ_Token_Service
	 * mu-plugin is present, delegate the refresh to it. The service takes a REAL
	 * MySQL advisory lock (GET_LOCK) and guarantees AT MOST ONE network refresh
	 * per token rotation across every concurrent worker (theme + all plugin
	 * refreshers), which the previous per-client refreshers could not — FreshBooks
	 * refresh tokens are single-use, so two simultaneous refreshers revoke each
	 * other (Interop contract §1.2). The service also projects the new token to
	 * every legacy option-key family, so the fan-out below is preserved centrally.
	 *
	 * When the service is absent (older platform / standalone), this falls back to
	 * the original self-contained refresh + fan-out, so behavior is unchanged on a
	 * host without the mu-plugin. Public signature is identical.
	 *
	 * @return bool True if a fresh access token is now in effect.
	 */
	public function refresh_token(): bool {
		// Preferred path: the authoritative, lock-protected service.
		if ( class_exists( 'ZDZ_Token_Service' ) ) {
			$new_access = ZDZ_Token_Service::refresh( [
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'refresh_token' => $this->refresh_token,
			] );
			if ( $new_access !== '' ) {
				// Adopt whatever the service published (it may have been rotated by
				// a concurrent worker; the service returns the live token either way).
				$this->access_token = $new_access;
				$svc_refresh        = ZDZ_Token_Service::get_refresh_token();
				if ( $svc_refresh !== '' ) {
					$this->refresh_token = $svc_refresh;
				}
				return true;
			}
			// Service returned '' (hard failure). Fall through to the legacy path as
			// a last resort so a transient service issue never bricks a refresh.
		}

		// Fallback path: original self-contained refresh (pre-2.26.0).
		$response = wp_remote_post( $this->api_base . '/auth/oauth/token', [
			'body' => wp_json_encode([
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'refresh_token' => $this->refresh_token,
			]),
			'headers' => [ 'Content-Type' => 'application/json' ],
		]);

		if ( is_wp_error( $response ) ) {
			error_log( 'FreshBooks Token Refresh Error: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['access_token'] ) ) {
			$this->access_token  = $body['access_token'];
			$this->refresh_token = $body['refresh_token'];

			// Save to theme's own prefix
			update_option( 'zdz_core_fb_access_token', $body['access_token'] );
			update_option( 'zdz_core_fb_refresh_token', $body['refresh_token'] );

			// v2.14.3.1: Sync to ALL plugin prefixes. FreshBooks refresh tokens
			// are single-use — if the theme refreshes and doesn't sync back,
			// every plugin's stored refresh token becomes revoked. This caused
			// "token expired and auto-refresh failed" errors even after re-auth.
			foreach ( [ 'tscc_', 'tsec_', 'tsl_', 'tsa_', 'zdz_surveys_' ] as $prefix ) {
				update_option( $prefix . 'fb_access_token',  $body['access_token'] );
				update_option( $prefix . 'fb_refresh_token', $body['refresh_token'] );
			}

			return true;
		}

		return false;
	}

	public function api_request( string $method, string $endpoint, array $data = [] ) {
		// v2.25.3: Bulletproof base/endpoint join. Exactly ONE slash between
		// the (slash-trimmed) base and the (slash-trimmed) endpoint, regardless
		// of whether either side carries one. This permanently closes the old
		// "https://api.freshbooks.comaccounting/..." concatenation bug (a pre-2.14
		// build joined as `$api_base . ltrim($endpoint,'/')` with a slashless
		// base, dropping the separator). Several plugins (TSEMC/TSER) still ship
		// private FreshBooks clients citing that bug — it is fixed here; they can
		// now safely delegate to this method. Absolute URLs are passed through.
		if ( preg_match( '#^https?://#i', $endpoint ) ) {
			$url = $endpoint;
		} else {
			$url = rtrim( $this->api_base, '/' ) . '/' . ltrim( $endpoint, '/' );
		}
		$args = [
			'method'  => strtoupper( $method ),
			'headers' => $this->get_auth_headers(),
			'timeout' => 30,
		];

		if ( ! empty( $data ) && in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'FreshBooks API Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 401 ) {
			if ( $this->refresh_token() ) {
				$args['headers'] = $this->get_auth_headers();
				$response = wp_remote_request( $url, $args );
				if ( is_wp_error( $response ) ) {
					error_log( 'FreshBooks API Error (post-refresh): ' . $response->get_error_message() );
					return null;
				}
				$code = wp_remote_retrieve_response_code( $response );
			} else {
				error_log( 'FreshBooks API: Token refresh failed. Original 401 on ' . $endpoint );
				return null;
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'FreshBooks API: HTTP ' . $code . ' on ' . $args['method'] . ' ' . $endpoint );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	public function get_invoices( array $params = [] ) {
		$query = http_build_query( $params );
		return $this->api_request( 'GET', "/accounting/account/{$this->account_id}/invoices/invoices?{$query}" );
	}

	public function get_clients( array $params = [] ) {
		$query = http_build_query( $params );
		return $this->api_request( 'GET', "/accounting/account/{$this->account_id}/users/clients?{$query}" );
	}

	public function create_estimate( array $estimate_data ) {
		return $this->api_request( 'POST', "/accounting/account/{$this->account_id}/estimates/estimates", [ 'estimate' => $estimate_data ] );
	}

	public function get_invoice_payments( string $invoice_id ) {
		return $this->api_request( 'GET', "/accounting/account/{$this->account_id}/invoices/invoices/{$invoice_id}/payments" );
	}

	/**
	 * Fetch estimates with optional search parameters.
	 *
	 * @since 2.9.0 (KPI metrics)
	 * @param array $params Query parameters (search filters, per_page, page).
	 * @return array|null API response or null on error.
	 */
	public function get_estimates( array $params = [] ) {
		$query = http_build_query( $params );
		return $this->api_request( 'GET', "/accounting/account/{$this->account_id}/estimates/estimates?{$query}" );
	}

	/**
	 * Check whether FreshBooks credentials are configured.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->access_token ) && ! empty( $this->account_id );
	}

	/**
	 * Fetch a single client's invoices within a recent date window.
	 *
	 * Shared convenience used by the cross-app orchestrator (the Brain Bot
	 * customer-document lookup, e.g. "estimate for Sam Rivera" — the invoice
	 * half of that lookup). Centralizing it here means every consumer reads
	 * invoices through ONE implementation rather than each plugin shipping its
	 * own pager. Returns raw FreshBooks invoice objects (with lines), already
	 * paginated and date-filtered.
	 *
	 * NOTE: This does NOT decide what is "open" or apply any redaction — it is a
	 * neutral data read. Status interpretation ("not paid yet") and any kiosk
	 * redaction are the caller's responsibility (TSEC bridge), so this method
	 * stays a pure source accessor with no policy baked in.
	 *
	 * @since 2.21.1 (cross-app orchestrator — Stage 0)
	 *
	 * @param int|string $client_id     FreshBooks client (customer) id.
	 * @param int        $lookback_days Days back from today (default 90).
	 * @param array      $extra         Optional extra search[...] params to merge
	 *                                  (e.g. [ 'search[vis_state]' => 0 ]).
	 * @return array Raw invoice objects; empty array on no-data or error.
	 */
	public function get_client_invoices( $client_id, int $lookback_days = 90, array $extra = [] ): array {
		if ( empty( $client_id ) ) {
			return [];
		}

		$since = gmdate( 'Y-m-d', strtotime( "-{$lookback_days} days" ) );

		$base = [
			'search[customerid]' => $client_id,
			'search[date_min]'   => $since,
			'include[]'          => 'lines',
			'per_page'           => 100,
		];
		// Caller-supplied filters win on key collision.
		$search = array_merge( $base, $extra );

		$all       = [];
		$page      = 1;
		$max_pages = 20; // safety cap; dynamic cap from API metadata below usually ends sooner

		// INV-2: FreshBooks sometimes IGNORES search[customerid] and returns other
		// customers' invoices (the "68 estimates for a 1-estimate client" leak). NEVER
		// trust the server filter — keep only rows whose customerid matches the one we
		// asked for. $want_cid is the effective filter (a caller may override it via
		// $extra); if no customerid filter is in effect we keep everything (unchanged).
		$want_cid = isset( $search['search[customerid]'] ) ? (int) $search['search[customerid]'] : 0;
		$dropped  = 0;

		while ( $page <= $max_pages ) {
			$search['page'] = $page;
			$resp = $this->get_invoices( $search );

			if ( empty( $resp ) || ! is_array( $resp ) ) {
				error_log( "ZDZ_Core_FreshBooks: get_client_invoices page={$page} — empty/invalid response" );
				break;
			}

			$invoices = $resp['response']['result']['invoices'] ?? [];

			if ( $page === 1 ) {
				$api_pages = (int) ( $resp['response']['result']['pages'] ?? 0 );
				$api_total = (int) ( $resp['response']['result']['total'] ?? 0 );
				error_log( "ZDZ_Core_FreshBooks: get_client_invoices client={$client_id} since={$since} — total={$api_total}, pages={$api_pages}" );
				if ( $api_pages > 0 && $api_pages < $max_pages ) {
					$max_pages = $api_pages;
				}
			}

			if ( empty( $invoices ) ) {
				break;
			}

			foreach ( $invoices as $inv ) {
				if ( $want_cid > 0 ) {
					$row_cid = (int) ( $inv['customerid'] ?? $inv['client_id'] ?? $inv['ownerid'] ?? 0 );
					if ( $row_cid !== $want_cid ) {
						$dropped++;
						continue; // foreign row — the server filter was not honored
					}
				}
				$all[] = $inv;
			}

			if ( count( $invoices ) < 100 ) {
				break;
			}
			$page++;
		}

		if ( $dropped > 0 ) {
			error_log( "ZDZ_Core_FreshBooks: get_client_invoices client={$client_id} — search[customerid] was NOT honored by FreshBooks; dropped {$dropped} foreign invoice(s), kept " . count( $all ) . '.' );
		}

		return $all;
	}
}