<?php
/**
 * ZRCPT FreshBooks — billing client for the Receipts module.
 *
 * GENERALIZATION (crosswalk 03 §B, B6/B7/B13): credentials resolve through the theme's ONE
 * shared source (ZDZ_Core_Settings), and the OAuth refresh delegates to the kernel
 * (ZDZ_Core_FreshBooks / ZDZ_Token_Service single-flight lock) — this module never POSTs the
 * token endpoint itself, so it can no longer revoke a sibling's single-use refresh token. The
 * old plaintext per-plugin prefix cascade is retained ONLY as a read-only legacy fallback so a
 * business's existing install keeps working until it re-connects through Core.
 *
 * Receipts search invoices AND estimates (a receipt attaches to an invoice); the search /
 * format plumbing below is neutral REST. An optional sibling billing client (e.g. the Prep
 * module) is used for search() when present, but is NOT required.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZRCPT_FreshBooks {

	/** @var object|null — optional sibling billing client (Prep) when present. */
	private $delegate = null;

	private string $last_error = '';

	/**
	 * Legacy plaintext option prefixes — READ-ONLY fallback for an in-place upgrade only. New
	 * installs resolve everything through ZDZ_Core_Settings; nothing is written here.
	 * @deprecated Superseded by Core credential resolution.
	 */
	const PREFIX_CASCADE = [ 'zdz_core_', 'tsec_', 'ts_surveys_', 'tsa_', 'tsl_', 'ts_core_' ];

	public function __construct() {
		// Optional: a sibling billing client (the Prep module) may answer plain-string search.
		if ( class_exists( 'ZPREP_FreshBooks' ) ) {
			$this->delegate = new ZPREP_FreshBooks();
		}
	}

	public function is_ready(): bool {
		if ( $this->delegate && method_exists( $this->delegate, 'is_ready' ) ) return $this->delegate->is_ready();
		return (bool) $this->get_credential( 'fb_access_token' )
			&& (bool) $this->get_credential( 'fb_account_id' );
	}

	public function get_active_prefix(): string {
		if ( $this->delegate && method_exists( $this->delegate, 'get_active_prefix' ) ) return $this->delegate->get_active_prefix();
		if ( class_exists( 'ZDZ_Core_Settings' ) && $this->get_credential( 'fb_access_token' ) !== '' ) {
			return 'zdz_core_';
		}
		foreach ( self::PREFIX_CASCADE as $p ) {
			if ( get_option( $p . 'fb_access_token' ) ) return $p;
		}
		return '';
	}

	public function get_last_error(): string { return $this->last_error; }

	/* ================================================================
	 * SEARCH — Multi-Strategy Invoice + Estimate Search
	 * ================================================================ */

	/**
	 * Search FreshBooks across invoices AND estimates, filtered to EM refs.
	 *
	 * v3.0.0: Self-sufficient implementation — works without the Prep module.
	 *
	 * Accepts a structured query array from the AI classifier:
	 *   $query['type']    — 'number' | 'name' | 'email' | 'phone' | 'address' | 'raw'
	 *   $query['value']   — the classified value
	 *   $query['raw']     — the original user input
	 *
	 * For backward compat, also accepts a plain string (delegates to the Prep module or
	 * treats as a raw query for legacy lookup bar).
	 *
	 * Return shape:
	 *   [
	 *     'status'  => 'ok' | 'not_found' | 'error' | 'not_configured',
	 *     'message' => string,
	 *     'matches' => [ { type, number, customer_id, customer_name, reference, invoice_url, ... } ]
	 *   ]
	 */
	public function search( $query, array $opts = [] ): array {
		$opts = array_merge( [
			'include_estimates' => true,
			'include_invoices'  => true,
			// The item tag/subtype the lookup is restricted to ('' = no restriction). The old
			// hardcoded "EM ref" filter is now the admin-chosen Item Engine tag. NO product
			// name is compiled in.
			'tag_filter'        => '',
		], $opts );

		// If the Prep module is active and query is a plain string, delegate.
		if ( $this->delegate && is_string( $query ) ) {
			return $this->delegate->search( $query );
		}

		if ( ! $this->is_ready() ) {
			return [
				'status'  => 'not_configured',
				'message' => 'Billing credentials are not configured. Connect a billing provider in Zorderz settings.',
				'matches' => [],
			];
		}

		// Normalize query
		if ( is_string( $query ) ) {
			$query = $this->classify_raw_query( $query );
		}

		$type  = $query['type'] ?? 'raw';
		$value = trim( $query['value'] ?? $query['raw'] ?? '' );

		if ( $value === '' ) {
			return [ 'status' => 'error', 'message' => 'Empty search query.', 'matches' => [] ];
		}

		$matches = [];

		try {
			switch ( $type ) {
				case 'number':
					$matches = $this->search_by_number( $value, $opts );
					break;
				case 'email':
					$matches = $this->search_by_email( $value, $opts );
					break;
				case 'phone':
					$matches = $this->search_by_phone( $value, $opts );
					break;
				case 'name':
					$matches = $this->search_by_name( $value, $opts );
					break;
				case 'address':
					$matches = $this->search_by_name( $value, $opts ); // Address searches via client name/org
					break;
				default:
					// Raw string — try number first, then name
					if ( preg_match( '/^\d{3,}$/', $value ) ) {
						$matches = $this->search_by_number( $value, $opts );
					}
					if ( empty( $matches ) ) {
						$matches = $this->search_by_name( $value, $opts );
					}
					break;
			}
		} catch ( \Exception $e ) {
			error_log( 'ZRCPT FreshBooks search error: ' . $e->getMessage() );
			return [ 'status' => 'error', 'message' => 'Search failed: ' . $e->getMessage(), 'matches' => [] ];
		}

		// Apply the item-tag filter (Item Engine). An empty tag means no restriction.
		if ( ! empty( $opts['tag_filter'] ) && ! empty( $matches ) ) {
			$matches = $this->filter_tag_matches( $matches, (string) $opts['tag_filter'] );
		}

		if ( empty( $matches ) ) {
			return [ 'status' => 'not_found', 'message' => 'No matching jobs found.', 'matches' => [] ];
		}

		return [ 'status' => 'ok', 'message' => count( $matches ) . ' match(es) found.', 'matches' => $matches ];
	}

	/* ── Search Strategies ──────────────────────────────────────── */

	private function search_by_number( string $number, array $opts ): array {
		$account_id = $this->get_credential( 'fb_account_id' );
		$matches = [];

		// Search invoices first (receipts primarily attach to invoices)
		if ( $opts['include_invoices'] ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/invoices/invoices",
				[ 'search[invoice_number]' => $number, 'include[]' => [ 'lines', 'direct_links' ], 'per_page' => 5 ]
			);
			foreach ( ( $resp['response']['result']['invoices'] ?? [] ) as $inv ) {
				$matches[] = $this->format_match( 'invoice', $inv );
			}
		}

		// Then estimates
		if ( $opts['include_estimates'] ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/estimates/estimates",
				[ 'search[estimate_number]' => $number, 'include[]' => [ 'lines', 'direct_links' ], 'per_page' => 5 ]
			);
			foreach ( ( $resp['response']['result']['estimates'] ?? [] ) as $est ) {
				$matches[] = $this->format_match( 'estimate', $est );
			}
		}

		return $matches;
	}

	private function search_by_email( string $email, array $opts ): array {
		$account_id = $this->get_credential( 'fb_account_id' );

		// Find client by email
		$resp = $this->api_get(
			"/accounting/account/{$account_id}/users/clients",
			[ 'search[email]' => $email, 'per_page' => 5 ]
		);
		$clients = $resp['response']['result']['clients'] ?? [];

		return $this->search_docs_for_clients( $clients, $opts );
	}

	private function search_by_phone( string $phone, array $opts ): array {
		$account_id = $this->get_credential( 'fb_account_id' );
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( strlen( $digits ) < 7 ) return [];

		// FreshBooks has no phone search API — paginated scan required
		$matched_clients = [];
		$page = 1;
		$max_pages = 5;

		while ( $page <= $max_pages ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/users/clients",
				[ 'per_page' => 100, 'page' => $page ]
			);
			$clients = $resp['response']['result']['clients'] ?? [];
			if ( empty( $clients ) ) break;

			foreach ( $clients as $c ) {
				$cp = preg_replace( '/[^0-9]/', '',
					( $c['mob_phone'] ?? '' ) . '|' .
					( $c['home_phone'] ?? '' ) . '|' .
					( $c['bus_phone'] ?? '' ) . '|' .
					( $c['p_street2'] ?? '' )
				);
				if ( $cp && ( strpos( $cp, $digits ) !== false || strpos( $digits, $cp ) !== false ) ) {
					$matched_clients[] = $c;
				}
			}

			if ( ! empty( $matched_clients ) || count( $clients ) < 100 ) break;
			$page++;
		}

		error_log( "ZRCPT FB: phone scan for {$digits} — scanned {$page} page(s), found " . count( $matched_clients ) . ' client(s)' );

		return $this->search_docs_for_clients( $matched_clients, $opts );
	}

	private function search_by_name( string $name, array $opts ): array {
		$account_id = $this->get_credential( 'fb_account_id' );

		// Split into first/last name
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		$fname = $parts[0] ?? '';
		$lname = $parts[1] ?? '';

		$all_clients = [];

		// Strategy 1: First + last name search
		if ( $fname && $lname ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/users/clients",
				[ 'search[fname_like]' => $fname, 'search[lname_like]' => $lname, 'per_page' => 10 ]
			);
			$clients = $resp['response']['result']['clients'] ?? [];
			if ( count( $clients ) <= 50 ) {
				foreach ( $clients as $c ) $all_clients[ $c['id'] ] = $c;
			}
		}

		// Strategy 2: Last name only
		if ( $lname && count( $all_clients ) < 5 ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/users/clients",
				[ 'search[lname_like]' => $lname, 'per_page' => 10 ]
			);
			$clients = $resp['response']['result']['clients'] ?? [];
			if ( count( $clients ) <= 50 ) {
				foreach ( $clients as $c ) $all_clients[ $c['id'] ] = $c;
			}
		}

		// Strategy 3: Organization search (handles "Smith" matching org name)
		if ( count( $all_clients ) < 5 ) {
			$search_term = $lname ?: $fname ?: $name;
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/users/clients",
				[ 'search[organization_like]' => $search_term, 'per_page' => 10 ]
			);
			$clients = $resp['response']['result']['clients'] ?? [];
			if ( count( $clients ) <= 50 ) {
				foreach ( $clients as $c ) $all_clients[ $c['id'] ] = $c;
			}
		}

		// Strategy 4: First name as last name (handles single-word input)
		if ( empty( $all_clients ) && $fname && ! $lname ) {
			$resp = $this->api_get(
				"/accounting/account/{$account_id}/users/clients",
				[ 'search[lname_like]' => $fname, 'per_page' => 10 ]
			);
			$clients = $resp['response']['result']['clients'] ?? [];
			if ( count( $clients ) <= 50 ) {
				foreach ( $clients as $c ) $all_clients[ $c['id'] ] = $c;
			}
		}

		return $this->search_docs_for_clients( array_values( $all_clients ), $opts );
	}

	/**
	 * Given a set of FreshBooks clients, find their recent invoices/estimates.
	 */
	private function search_docs_for_clients( array $clients, array $opts ): array {
		if ( empty( $clients ) ) return [];
		$account_id = $this->get_credential( 'fb_account_id' );
		$matches = [];

		// Limit to first 5 clients to avoid API hammering
		$clients = array_slice( $clients, 0, 5 );

		foreach ( $clients as $client ) {
			$client_id = $client['id'] ?? null;
			if ( ! $client_id ) continue;
			$want_cid = (int) $client_id;

			// Search invoices for this client
			if ( $opts['include_invoices'] ) {
				$resp = $this->api_get(
					"/accounting/account/{$account_id}/invoices/invoices",
					[ 'search[customerid]' => $client_id, 'include[]' => [ 'lines', 'direct_links' ], 'per_page' => 10 ]
				);
				foreach ( ( $resp['response']['result']['invoices'] ?? [] ) as $inv ) {
					// INV-2: FreshBooks sometimes ignores search[customerid]; never
					// trust it — a foreign invoice here would be shown/labeled under
					// THIS client's name (cross-customer PII leak). Verify each row.
					$row_cid = (int) ( $inv['customerid'] ?? $inv['client_id'] ?? $inv['ownerid'] ?? 0 );
					if ( $row_cid !== $want_cid ) continue;
					$m = $this->format_match( 'invoice', $inv );
					$m['customer_detail'] = $this->format_client_detail( $client );
					$matches[] = $m;
				}
			}

			// Search estimates for this client
			if ( $opts['include_estimates'] ) {
				$resp = $this->api_get(
					"/accounting/account/{$account_id}/estimates/estimates",
					[ 'search[customerid]' => $client_id, 'include[]' => [ 'lines', 'direct_links' ], 'per_page' => 10 ]
				);
				foreach ( ( $resp['response']['result']['estimates'] ?? [] ) as $est ) {
					$row_cid = (int) ( $est['customerid'] ?? $est['client_id'] ?? $est['ownerid'] ?? 0 );
					if ( $row_cid !== $want_cid ) continue;
					$m = $this->format_match( 'estimate', $est );
					$m['customer_detail'] = $this->format_client_detail( $client );
					$matches[] = $m;
				}
			}
		}

		// Sort: invoices first, then by number descending (most recent)
		usort( $matches, function ( $a, $b ) {
			if ( $a['type'] !== $b['type'] ) return $a['type'] === 'invoice' ? -1 : 1;
			return (int) $b['number'] - (int) $a['number'];
		} );

		// Cap at 10 results
		return array_slice( $matches, 0, 10 );
	}

	/* ── Item-tag filter (Item Engine) ─────────────────────────── */

	/**
	 * Filter matches to the tenant's configured item tag/subtype via the Item Engine.
	 *
	 * Generalized from the old hardcoded product-keyword/reference list: each match's
	 * searchable text (reference + notes + description + line text) is classified through the
	 * Item Engine (zrcpt_count_classify → ZDZ_Item_Engine::classify), and a match is kept when
	 * it classifies to $tag (or to a child of it). NO product keyword is compiled in. With an
	 * empty catalog the classifier returns nothing, so — exactly as before — if the filter
	 * removes everything we fall back to the first few unfiltered results, flagged, and let the
	 * UI decide. This preserves the "never strand the operator" behaviour without any taxonomy.
	 *
	 * @param array  $matches
	 * @param string $tag Item Engine item id/subtype to keep.
	 */
	private function filter_tag_matches( array $matches, string $tag ): array {
		if ( $tag === '' ) {
			return $matches;
		}
		$filtered = [];

		foreach ( $matches as $m ) {
			$searchable = trim(
				( $m['reference'] ?? '' ) . ' ' .
				( $m['notes'] ?? '' ) . ' ' .
				( $m['description'] ?? '' ) . ' ' .
				( $m['line_text'] ?? '' )
			);
			$kind = function_exists( 'zrcpt_count_classify' ) ? zrcpt_count_classify( $searchable ) : '';
			if ( $kind !== '' && ( $kind === $tag || strpos( $kind, $tag ) === 0 ) ) {
				$filtered[] = $m;
			}
		}

		// If the filter removed everything but we had matches, return a few unfiltered with a
		// flag — let the UI decide whether to show them (never strand the operator).
		if ( empty( $filtered ) && ! empty( $matches ) ) {
			$unfiltered = array_slice( $matches, 0, 3 );
			foreach ( $unfiltered as &$m ) {
				$m['tag_match'] = false;
			}
			return $unfiltered;
		}

		foreach ( $filtered as &$m ) {
			$m['tag_match'] = true;
		}

		return $filtered;
	}

	/* ── Format Helpers ────────────────────────────────────────── */

	private function format_match( string $type, array $doc ): array {
		$number_key = $type === 'invoice' ? 'invoice_number' : 'estimate_number';
		$id_key     = $type === 'invoice' ? 'invoiceid' : 'estimateid';

		// Collect line item text for item-tag filtering
		$line_text = '';
		foreach ( ( $doc['lines'] ?? [] ) as $line ) {
			$line_text .= ( $line['name'] ?? '' ) . ' ' . ( $line['description'] ?? '' ) . ' ';
		}

		// Build the CUSTOMER-FACING FreshBooks link.
		// The share link `https://my.freshbooks.com/#/link/{token}` opens for a
		// NON-logged-in viewer (the homeowner). The token lives in the invoice's
		// `direct_links` array (requires include[]=direct_links on the fetch).
		// The old `#/invoice/{id}` form is the account-internal view that renders
		// blank to anyone not logged into our FreshBooks — never the right thing
		// to hand a customer. Fall back to it only if no share token is present.
		$account_id  = $this->get_credential( 'fb_account_id' );
		$doc_id      = $doc[ $id_key ] ?? $doc['id'] ?? '';
		$fb_url      = '';

		$share_token = '';
		foreach ( ( $doc['direct_links'] ?? [] ) as $dl ) {
			if ( empty( $dl['token'] ) ) continue;
			// Prefer a link whose type matches this doc; else take the first token.
			if ( ( $dl['type'] ?? '' ) === $type ) { $share_token = (string) $dl['token']; break; }
			if ( $share_token === '' ) { $share_token = (string) $dl['token']; }
		}

		if ( $share_token !== '' ) {
			$fb_url = "https://my.freshbooks.com/#/link/{$share_token}";
		} elseif ( $account_id && $doc_id ) {
			$fb_url = ( $type === 'invoice' )
				? "https://my.freshbooks.com/#/invoice/{$doc_id}"
				: "https://my.freshbooks.com/#/estimate/{$doc_id}";
		}

		// Customer name from the document
		$cust_name = trim( ( $doc['fname'] ?? '' ) . ' ' . ( $doc['lname'] ?? '' ) );
		if ( empty( trim( $cust_name ) ) ) {
			$cust_name = $doc['current_organization'] ?? $doc['organization'] ?? '(unknown)';
		}

		return [
			'type'          => $type,
			'number'        => (string) ( $doc[ $number_key ] ?? '' ),
			'customer_id'   => (int) ( $doc['customerid'] ?? 0 ),
			'customer_name' => $cust_name,
			'reference'     => $doc['po_number'] ?? $doc['reference'] ?? '',
			'notes'         => $doc['notes'] ?? '',
			'description'   => $doc['description'] ?? '',
			'status'        => $doc['display_status'] ?? $doc['v3_status'] ?? '',
			'amount'        => $doc['amount']['amount'] ?? $doc['total']['amount'] ?? '0.00',
			'currency'      => $doc['amount']['code'] ?? 'USD',
			'invoice_url'   => $fb_url,
			'line_text'     => $line_text,
			'lines'         => $doc['lines'] ?? [],
		];
	}

	private function format_client_detail( array $client ): array {
		// Build address string
		$addr_parts = array_filter( [
			$client['p_street'] ?? '',
			$client['p_city'] ?? '',
			$client['p_province'] ?? '',
			$client['p_code'] ?? '',
		] );

		// Phone: check all fields (v6.1 convention)
		$phone = '';
		foreach ( [ 'mob_phone', 'home_phone', 'bus_phone' ] as $pf ) {
			if ( ! empty( $client[ $pf ] ) ) { $phone = $client[ $pf ]; break; }
		}
		if ( ! $phone && ! empty( $client['p_street2'] ) && preg_match( '/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $client['p_street2'] ) ) {
			$phone = $client['p_street2'];
		}

		return [
			'name'    => trim( ( $client['fname'] ?? '' ) . ' ' . ( $client['lname'] ?? '' ) ),
			'email'   => $client['email'] ?? '',
			'phone'   => $phone,
			'address' => implode( ', ', $addr_parts ),
			'id'      => (int) ( $client['id'] ?? 0 ),
		];
	}

	/**
	 * Classify a raw query string into a typed query.
	 * Heuristic classification — the AI classifier handles ambiguous cases.
	 */
	private function classify_raw_query( string $raw ): array {
		$raw = trim( $raw );

		// Email (most specific)
		if ( filter_var( $raw, FILTER_VALIDATE_EMAIL ) ) {
			return [ 'type' => 'email', 'value' => $raw, 'raw' => $raw ];
		}

		// Phone: 7+ total digits (check before number to avoid 760-518-3209 → "760")
		$all_digits = preg_replace( '/[^0-9]/', '', $raw );
		if ( strlen( $all_digits ) >= 7 && strlen( $all_digits ) <= 15 ) {
			return [ 'type' => 'phone', 'value' => $raw, 'raw' => $raw ];
		}

		// Pure digits: "14767", "#14767"
		if ( preg_match( '/^#?\d{3,}$/', preg_replace( '/[#\s]/', '', $raw ) ) ) {
			return [ 'type' => 'number', 'value' => preg_replace( '/[^0-9]/', '', $raw ), 'raw' => $raw ];
		}

		// Document keyword + number: "Invoice 14767", "Inv #14767", "EST 5541"
		if ( preg_match( '/^(?:invoice|inv|estimate|est|receipt|rec)[#\s._-]*(\d{3,})/i', $raw, $m ) ) {
			return [ 'type' => 'number', 'value' => $m[1], 'raw' => $raw ];
		}

		// Default: name/text search
		return [ 'type' => 'name', 'value' => $raw, 'raw' => $raw ];
	}

	/**
	 * Fetch client/customer detail by ID.
	 */
	public function get_client( $client_id ): ?array {
		if ( $this->delegate ) return $this->delegate->get_client( $client_id );

		$account_id = $this->get_credential( 'fb_account_id' );
		if ( ! $account_id || ! $client_id ) return null;

		$resp = $this->api_get(
			"/accounting/account/{$account_id}/users/clients/{$client_id}"
		);
		$client = $resp['response']['result']['client'] ?? null;
		return $client ? $this->format_client_detail( $client ) : null;
	}

	/* ================================================================
	 * CONNECTION DIAGNOSTICS
	 * ================================================================ */

	public function test_connection(): array {
		if ( $this->delegate ) return $this->delegate->test_connection();

		if ( ! $this->is_ready() ) {
			return [
				'overall' => [
					'label' => 'FreshBooks',
					'ok'    => false,
					'error' => 'No billing credentials found. Connect a billing provider in Zorderz settings.',
				],
			];
		}

		$results = [];
		$account_id = $this->get_credential( 'fb_account_id' );

		// Test 1: Profile
		$resp = $this->api_get( '/auth/api/v1/users/me' );
		$results['profile'] = [
			'label' => 'Profile / Account',
			'ok'    => $resp !== null,
			'error' => $this->last_error,
		];

		// Test 2: Invoices
		$resp = $this->api_get(
			"/accounting/account/{$account_id}/invoices/invoices",
			[ 'per_page' => 1 ]
		);
		$results['invoices'] = [
			'label' => 'Invoices (read)',
			'ok'    => $resp !== null,
			'error' => $this->last_error,
		];

		// Test 3: Clients
		$resp = $this->api_get(
			"/accounting/account/{$account_id}/users/clients",
			[ 'per_page' => 1 ]
		);
		$results['clients'] = [
			'label' => 'Clients (read)',
			'ok'    => $resp !== null,
			'error' => $this->last_error,
		];

		return $results;
	}

	/* ================================================================
	 * CREDENTIAL CASCADE
	 * ================================================================ */

	/**
	 * Resolve a billing credential from the theme's ONE shared source (ZDZ_Core_Settings),
	 * with a read-only legacy option fallback for in-place upgrades. NO plaintext copy is
	 * written back — the kernel owns credential storage now.
	 */
	private function get_credential( string $field ): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			$map = [
				'fb_access_token'  => 'get_fb_access_token',
				'fb_refresh_token' => 'get_fb_refresh_token',
				'fb_account_id'    => 'get_fb_account_id',
				'fb_client_id'     => 'get_fb_client_id',
			];
			if ( isset( $map[ $field ] ) && method_exists( 'ZDZ_Core_Settings', $map[ $field ] ) ) {
				$v = (string) call_user_func( [ 'ZDZ_Core_Settings', $map[ $field ] ] );
				if ( $v !== '' ) return $v;
			}
		}
		// Legacy read-only fallback (in-place upgrade only).
		foreach ( self::PREFIX_CASCADE as $p ) {
			$v = get_option( $p . $field );
			if ( ! empty( $v ) ) return (string) $v;
		}
		return '';
	}

	private function get_client_secret(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_client_secret' ) ) {
			$v = (string) ZDZ_Core_Settings::get_fb_client_secret();
			if ( $v !== '' ) return $v;
		}
		foreach ( self::PREFIX_CASCADE as $p ) {
			$raw = get_option( $p . 'fb_client_secret', '' );
			if ( empty( $raw ) ) continue;
			$dec = $this->decrypt( $raw );
			if ( ! empty( $dec ) ) return $dec;
			return $raw;
		}
		return '';
	}

	private function decrypt( string $value ): string {
		if ( empty( $value ) ) return '';
		$decoded = base64_decode( $value );
		if ( strpos( $decoded, '::' ) === false ) return $value;
		list( $iv, $cipher ) = explode( '::', $decoded, 2 );
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$dec = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return $dec !== false ? $dec : '';
	}

	public static function resolve_credential_source( string $field ): array {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			return [ 'present' => true, 'prefix' => 'zdz_core_', 'value_masked' => '•••', 'cascade' => [ 'zdz_core_' ] ];
		}
		foreach ( self::PREFIX_CASCADE as $p ) {
			$v = get_option( $p . $field );
			if ( ! empty( $v ) ) {
				return [ 'present' => true, 'prefix' => $p, 'value_masked' => '•••', 'cascade' => self::PREFIX_CASCADE ];
			}
		}
		return [ 'present' => false, 'prefix' => '', 'value_masked' => '', 'cascade' => self::PREFIX_CASCADE ];
	}

	/* ================================================================
	 * HTTP — GET / Token Refresh
	 * ================================================================ */

	private function api_get( string $path, array $params = [] ): ?array {
		$url = 'https://api.freshbooks.com' . $path;
		if ( ! empty( $params ) ) {
			// Build query with literal brackets (FreshBooks requirement).
			// An ARRAY value repeats the key — e.g. 'include[]' => ['lines',
			// 'direct_links'] becomes include[]=lines&include[]=direct_links,
			// which is how FreshBooks takes multiple includes.
			$parts = [];
			foreach ( $params as $key => $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						$parts[] = $key . '=' . rawurlencode( (string) $v );
					}
				} else {
					$parts[] = $key . '=' . rawurlencode( (string) $value );
				}
			}
			$url .= '?' . implode( '&', $parts );
		}
		return $this->api_request( 'GET', $url );
	}

	private function api_request( string $method, string $url, $data = null ): ?array {
		$this->last_error = '';
		$access_token = $this->get_credential( 'fb_access_token' );

		if ( empty( $access_token ) ) {
			$this->last_error = 'FreshBooks access token not found.';
			return null;
		}

		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Api-Version'   => 'alpha',
			],
		];
		if ( $data ) {
			$args['body'] = wp_json_encode( $data );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			$this->last_error = 'HTTP error: ' . $resp->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $resp );

		// Auto-refresh on 401
		if ( $code === 401 ) {
			$new_token = $this->refresh_token();
			if ( $new_token ) {
				$args['headers']['Authorization'] = 'Bearer ' . $new_token;
				$resp = wp_remote_request( $url, $args );
				if ( is_wp_error( $resp ) ) return null;
				$code = wp_remote_retrieve_response_code( $resp );
			} else {
				$this->last_error = 'Token expired and refresh failed.';
				return null;
			}
		}

		if ( $code >= 400 ) {
			$body = wp_remote_retrieve_body( $resp );
			$this->last_error = "HTTP {$code}: " . substr( $body, 0, 300 );
			error_log( "ZRCPT FB {$method} {$url} → {$code}: " . substr( $body, 0, 300 ) );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $resp ), true );
	}

	/**
	 * Refresh the billing access token through the KERNEL — never here (crosswalk 03-B13).
	 *
	 * FreshBooks refresh tokens are single-use; two independent refreshers revoke each other.
	 * The theme's ZDZ_Core_FreshBooks::refresh_token() delegates to ZDZ_Token_Service, which
	 * holds a platform-wide advisory lock (GET_LOCK) and guarantees AT MOST ONE network
	 * refresh per rotation, then republishes the rotated token to the shared store. This
	 * module reads the freshly-published token back through Core. It performs NO token POST of
	 * its own, so it can never clobber a sibling's token.
	 *
	 * @return string|null the new access token, or null when refresh is unavailable/failed.
	 */
	private function refresh_token(): ?string {
		if ( class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			$core = new ZDZ_Core_FreshBooks();
			if ( method_exists( $core, 'refresh_token' ) && $core->refresh_token() ) {
				$fresh = $this->get_credential( 'fb_access_token' );
				return $fresh !== '' ? $fresh : null;
			}
			return null;
		}
		// Last resort: the single-flight token service directly (still kernel-owned).
		if ( class_exists( 'ZDZ_Token_Service' ) && method_exists( 'ZDZ_Token_Service', 'refresh' ) ) {
			$svc_access = ZDZ_Token_Service::refresh( [
				'client_id'     => $this->get_credential( 'fb_client_id' ),
				'client_secret' => $this->get_client_secret(),
				'refresh_token' => $this->get_credential( 'fb_refresh_token' ),
			] );
			return ( is_string( $svc_access ) && $svc_access !== '' ) ? $svc_access : null;
		}
		$this->last_error = 'Billing token refresh is unavailable (no kernel token service).';
		return null;
	}
}
