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
	 * (Token-race fix — authoritative service): when the ZDZ_Token_Service
	 * mu-plugin is present, delegate the refresh to it. The service takes a REAL
	 * MySQL advisory lock (GET_LOCK) and guarantees AT MOST ONE network refresh
	 * per token rotation across every concurrent worker (theme + all plugin
	 * refreshers), which the previous per-client refreshers could not — FreshBooks
	 * refresh tokens are single-use, so two simultaneous refreshers revoke each
	 * other. The service also projects the new token to
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
			// The service is the ONE refresher: it serialises every refresh, adopts any reconnect and retries a
			// dead token with the copies it knows. A refresh here, outside its lock, would revoke the token every other
			// app holds, so a failure from the service is final.
			error_log( 'Zorderz Core billing: token refresh via ZDZ_Token_Service failed; not refreshing outside the service.' );
			return false;
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

			// The kernel's own copy only. Copying the pair onto every app's options would write one billing app's tokens
			// over another's (a refresh token never redeems under another app's client id); projecting a new pair to the
			// apps that share this billing app is the token service's job.
			update_option( 'zdz_core_fb_access_token', $body['access_token'] );
			update_option( 'zdz_core_fb_refresh_token', $body['refresh_token'] );

			return true;
		}

		return false;
	}

	public function api_request( string $method, string $endpoint, array $data = [] ) {
		// Bulletproof base/endpoint join. Exactly ONE slash between
		// the (slash-trimmed) base and the (slash-trimmed) endpoint, regardless
		// of whether either side carries one. This permanently closes the old
		// "https://api.freshbooks.comaccounting/..." concatenation bug (a pre-2.14
		// build joined as `$api_base . ltrim($endpoint,'/')` with a slashless
		// base, dropping the separator). Some sibling apps still ship
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
	 * Shared convenience used by the cross-app orchestrator (the assistant
	 * customer-document lookup, e.g. "estimate for Sam Rivera" — the invoice
	 * half of that lookup). Centralizing it here means every consumer reads
	 * invoices through ONE implementation rather than each plugin shipping its
	 * own pager. Returns raw FreshBooks invoice objects (with lines), already
	 * paginated and date-filtered.
	 *
	 * NOTE: This does NOT decide what is "open" or apply any redaction — it is a
	 * neutral data read. Status interpretation ("not paid yet") and any kiosk
	 * redaction are the caller's responsibility (Estimates bridge), so this method
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

		// FreshBooks sometimes IGNORES search[customerid] and returns other
		// customers' invoices (dozens of documents for a one-estimate client). NEVER
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

	/* ─────────────────────────── strict reads (budgeted callers) ───────────────────────────
	 *
	 * A caller that works to a time budget (the analytics app's stored-data ledger, the chat's invoice view) must tell
	 * "nothing there" from "the billing system did not answer": api_request() returns null for both. These reads throw
	 * on a failure instead, name the HTTP code in the message ("(HTTP 404)"), take a per-call timeout, and are GETs
	 * only: nothing here writes to the billing system.
	 */

	/** The account these reads use (the invoice view matches the business header to it). */
	public function account(): string {
		return (string) ( $this->account_id ?? '' );
	}

	/**
	 * A query string with the billing system's bracketed keys left as they are ("search[date_min]=…", "include[]=lines").
	 * Values are encoded; keys are the fixed names below. A list value repeats its key.
	 */
	public static function query_string( array $params ): string {
		$out = array();
		foreach ( $params as $k => $v ) {
			$k = (string) $k;
			if ( ! preg_match( '/^[a-z_]+(?:\[[a-z_]*\])?$/', $k ) ) {
				continue;
			}
			foreach ( is_array( $v ) ? $v : array( $v ) as $one ) {
				if ( null === $one || '' === (string) $one ) {
					continue;
				}
				$out[] = $k . '=' . rawurlencode( (string) $one );
			}
		}
		return implode( '&', $out );
	}

	/** One page of a list answer: { items, pages, total }, read from the billing system's result envelope. */
	public static function page_of( $data, string $list_key ): array {
		$result = is_array( $data ) ? ( $data['response']['result'] ?? array() ) : array();
		$items  = is_array( $result[ $list_key ] ?? null ) ? array_values( $result[ $list_key ] ) : array();
		return array(
			'items' => $items,
			'pages' => max( 1, (int) ( $result['pages'] ?? 1 ) ),
			'total' => (int) ( $result['total'] ?? count( $items ) ),
		);
	}

	/**
	 * One GET, strict. Returns the decoded body; throws RuntimeException on a transport failure, an HTTP error (after
	 * one token refresh on a 401) or a body that is not JSON.
	 */
	public function read_strict( string $path, array $query = array(), int $timeout = 30 ): array {
		if ( '' === $this->account() ) {
			throw new RuntimeException( 'The billing system is not connected.' );
		}
		$url = rtrim( $this->api_base, '/' ) . '/' . ltrim( $path, '/' );
		$qs  = self::query_string( $query );
		if ( '' !== $qs ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $qs;
		}
		$args = array(
			'method'  => 'GET',
			'headers' => $this->get_auth_headers(),
			'timeout' => max( 5, $timeout ),
		);
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Billing read failed: ' . esc_html( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $this->refresh_token() ) {
			$args['headers'] = $this->get_auth_headers();
			$response        = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'Billing read failed: ' . esc_html( $response->get_error_message() ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
		}
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( 'Billing read failed (HTTP ' . (int) $code . ')' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Billing read failed: the answer was not readable.' );
		}
		return $data;
	}

	/**
	 * One page of invoices, estimates or payments, for a reader that syncs page by page inside a time budget.
	 *
	 * @param string $resource 'invoices' | 'estimates' | 'payments'
	 * @param array  $search   any of date_min, date_max, updated_min (Y-m-d), vis_state (1 = deleted only, 2 = archived only;
	 *                         absent = the billing system's default list)
	 * @param int    $page     1-based
	 * @param int    $timeout  seconds for this request
	 * @return array { items, pages, total }
	 */
	public function list_page( string $resource, array $search, int $page = 1, int $timeout = 30 ): array {
		$paths = array(
			'invoices'  => 'invoices/invoices',
			'estimates' => 'estimates/estimates',
			'payments'  => 'payments/payments',
		);
		$resource = isset( $paths[ $resource ] ) ? $resource : 'invoices';
		$q        = array(
			'page'     => max( 1, $page ),
			'per_page' => 100, // the billing system's own cap
		);
		foreach ( array( 'date_min', 'date_max', 'updated_min' ) as $k ) {
			if ( ! empty( $search[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $search[ $k ] ) ) {
				$q[ 'search[' . $k . ']' ] = substr( (string) $search[ $k ], 0, 10 );
			}
		}
		if ( isset( $search['vis_state'] ) && in_array( (int) $search['vis_state'], array( 1, 2 ), true ) ) {
			$q['search[vis_state]'] = (int) $search['vis_state'];
		}
		if ( 'payments' !== $resource ) {
			$q['include[]'] = 'lines';
		}
		$data = $this->read_strict( '/accounting/account/' . rawurlencode( $this->account() ) . '/' . $paths[ $resource ], $q, $timeout );
		return self::page_of( $data, $resource );
	}

	/**
	 * Does this invoice still exist? One GET by id. 'active' | 'archived' | 'deleted' | 'missing' (the billing system says
	 * there is no such invoice), or '' when it could not say (timeout, sign-in, server error).
	 */
	public function invoice_vis( $invoice_id, int $timeout = 8 ): string {
		$id = preg_replace( '/\D+/', '', (string) $invoice_id );
		if ( '' === $id || '' === $this->account() ) {
			return '';
		}
		try {
			$data = $this->read_strict( '/accounting/account/' . rawurlencode( $this->account() ) . '/invoices/invoices/' . $id, array(), $timeout );
		} catch ( \Throwable $e ) {
			return false !== strpos( $e->getMessage(), '(HTTP 404)' ) ? 'missing' : '';
		}
		$err = $data['response']['errors'][0] ?? null;
		if ( is_array( $err ) ) {
			$msg = strtolower( (string) ( $err['message'] ?? '' ) );
			return ( 1012 === (int) ( $err['errno'] ?? 0 ) || false !== strpos( $msg, 'not found' ) ) ? 'missing' : '';
		}
		$inv = $data['response']['result']['invoice'] ?? null;
		if ( ! is_array( $inv ) ) {
			return '';
		}
		$vis = (int) ( $inv['vis_state'] ?? 0 );
		return 1 === $vis ? 'deleted' : ( 2 === $vis ? 'archived' : 'active' );
	}

	/**
	 * One invoice or estimate by its number, lines included. Exact only: the number as written and its zero-padded form
	 * ("1234" / "0001234"); a deleted document is never returned, and there is no "like" search, so a near match never
	 * opens someone else's document. With $all: every exact live match, one per billing id (an array, maybe empty), so a
	 * caller can refuse a number the billing system holds twice.
	 *
	 * @return array|null The document, or null when there is none (with $all: a list).
	 * @throws RuntimeException When the billing system can't be reached.
	 */
	public function get_document_by_number( string $kind, string $number, int $timeout = 8, bool $all = false ) {
		$kind   = 'estimate' === $kind ? 'estimate' : 'invoice';
		$number = trim( $number );
		if ( '' === $number || ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $number ) || '' === $this->account() ) {
			return $all ? array() : null;
		}
		$res      = 'estimate' === $kind ? 'estimates/estimates' : 'invoices/invoices';
		$field    = 'estimate' === $kind ? 'estimate_number' : 'invoice_number';
		$list     = 'estimate' === $kind ? 'estimates' : 'invoices';
		$id_key   = 'estimate' === $kind ? 'estimateid' : 'invoiceid';
		$variants = array( $number );
		if ( ctype_digit( $number ) && strlen( $number ) < 7 ) {
			$variants[] = str_pad( $number, 7, '0', STR_PAD_LEFT );
		}
		$want  = ltrim( $number, '0' );
		$found = array();
		foreach ( array_unique( $variants ) as $v ) {
			$data = $this->read_strict(
				'/accounting/account/' . rawurlencode( $this->account() ) . '/' . $res,
				array( 'per_page' => 5, 'search[' . $field . ']' => $v, 'include[]' => 'lines' ),
				$timeout
			);
			foreach ( self::page_of( $data, $list )['items'] as $doc ) {
				if ( ! is_array( $doc ) || 1 === (int) ( $doc['vis_state'] ?? 0 ) ) {
					continue;
				}
				if ( ltrim( (string) ( $doc[ $field ] ?? '' ), '0' ) === $want ) {
					if ( ! $all ) {
						return $doc;
					}
					$found[ (string) ( $doc[ $id_key ] ?? ( $doc['id'] ?? count( $found ) ) ) ] = $doc;
				}
			}
		}
		return $all ? array_values( $found ) : null;
	}

	/**
	 * The billing system's answer to the invoice share-link request (the link a customer opens without signing in).
	 * The caller asks only for an invoice that has been sent: the billing system may mark a draft sent to make its link.
	 *
	 * @throws RuntimeException When the billing system refuses or can't be reached ("(HTTP 422)" for a draft).
	 */
	public function get_invoice_share_link( $invoice_id, int $timeout = 8 ) {
		$invoice_id = (int) $invoice_id;
		if ( $invoice_id <= 0 || '' === $this->account() ) {
			return null;
		}
		return $this->read_strict(
			'/accounting/account/' . rawurlencode( $this->account() ) . '/invoices/invoices/' . $invoice_id . '/share_link',
			array( 'share_method' => 'share_link' ),
			$timeout
		);
	}

	/**
	 * The signed-in billing user and their businesses (an invoice view's company header).
	 *
	 * @throws RuntimeException When the billing system can't be reached.
	 */
	public function get_me( int $timeout = 8 ): array {
		return $this->read_strict( '/auth/api/v1/users/me', array(), $timeout );
	}
}