<?php
/**
 * TS Contact Bridge — shared "contact lookup" capability provider.
 *
 * Part of the cross-app orchestrator (Orchestrator Interop Contract §2 — the
 * CAPABILITY layer). Exposes a single read-only verb the operator bot can call
 * to answer "give me the contact info for <Name>". Contact identity is SHARED
 * platform data (not owned by any one app), so the bridge lives in the theme
 * alongside the ZDZ_Core_* data clients rather than in a plugin.
 *
 * CALLED BY: TSA Analytics Engine (class-tsa-analytics-engine.php), the
 *            [ZDZ_CONTACT] marker handler. Mirrors TSEC_TSA_Bridge::lookup_for_tsa().
 *
 * DATA SOURCES (Seam 1, shared clients only — no private API client):
 *   - ZDZ_Core_Nutshell::find_contacts()  — primary identity + phone/email
 *   - ZDZ_Core_FreshBooks::get_clients()   — corroboration: billing phone/email/address,
 *                                           and the salesperson INITIALS that drive scope
 *
 * GOVERNING RULES (preserved from the Interop Contract):
 *   - The bridge enforces permission/scope BEFORE returning any field.
 *     A denial is a SUCCESSFUL result carrying a message and NO contact data.
 *   - The host passes tier + is_kiosk + requesting_user_id; the bridge re-checks
 *     them server-side and never trusts the model.
 *   - is_available() + verb_for_tsa($payload) + structured return are
 *     registry-shaped, so zdz_register_capabilities (L4) onboarding is a one-liner.
 *
 * DISCLOSURE (what fields are returned), by tier:
 *   - kiosk (zdz_general / shared device): NAME + CITY ONLY. No phone/email/address.
 *   - every other logged-in tier: full contact info (subject to SCOPE below).
 *
 * SCOPE (whose contact a user may pull) — relationship-gated, shared-job aware:
 *   A requester may see a customer's full contact when ANY of:
 *     (a) the requester is admin/owner (sees anyone), OR
 *     (b) the customer is on the requester's OWN lead/job, OR
 *     (c) the requester SHARES the job — determined by the FreshBooks salesperson
 *         INITIALS on the customer's invoices/estimates. Initials like "(GT)" +
 *         "(DT)" (or composite "(GT/DT)", "(GT, DT)", "(GT & DT)") mean both reps
 *         are attached and BOTH may see that customer's data.
 *   The requester's own initials come from their commission profile
 *   (tscc_salesperson_code, with a ZDZ_Core_Settings fallback). When no
 *   relationship exists and the requester isn't admin, the bridge returns
 *   denied:true with a polite message and no data.
 *
 * @package ZorderzTheme
 * @version 1.0.0
 * @since   theme v2.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Contact_Bridge {

	/**
	 * Whether the contact-lookup verb can run at all.
	 * True when at least one shared data client is configured.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		$ns_ok = class_exists( 'ZDZ_Core_Nutshell' )
			&& ( ! method_exists( 'ZDZ_Core_Nutshell', 'is_configured' )
				|| ( new \ZDZ_Core_Nutshell() )->is_configured() );
		$fb_ok = class_exists( 'ZDZ_Core_FreshBooks' )
			&& ( ! method_exists( 'ZDZ_Core_FreshBooks', 'is_configured' )
				|| ( new \ZDZ_Core_FreshBooks() )->is_configured() );
		return $ns_ok || $fb_ok;
	}

	/**
	 * Look up a customer's contact info on the operator bot's behalf.
	 *
	 * @param array $payload {
	 *     @type string $query              REQUIRED. The customer name as spoken.
	 *     @type string $tier               Caller's resolved tier (for disclosure).
	 *     @type bool   $is_kiosk           Whether this is the shared-kiosk session.
	 *     @type int    $requesting_user_id The user on whose behalf the bot acts.
	 * }
	 * @return array {
	 *     @type bool   $success   Always present. True even for a denial/clarify.
	 *     @type bool   $denied    True when scope/permission forbids disclosure.
	 *     @type bool   $needs_clarify True when the name was ambiguous/empty.
	 *     @type string $message   Human-readable message (the answer on denial/clarify).
	 *     @type string $error     Hard-failure text (config/parse), else ''.
	 *     @type array  $contact   { name, company, phone, email, address, city, zip,
	 *                               nutshell_url, freshbooks_client_id, sources{} }
	 *                             — populated fields only; redacted per tier.
	 *     @type string $source    Always 'zdz_contact_bridge'.
	 * }
	 */
	public static function lookup_for_tsa( array $payload ): array {
		$result = array(
			'success'       => true,
			'denied'        => false,
			'needs_clarify' => false,
			'message'       => '',
			'error'         => '',
			'contact'       => array(),
			'source'        => 'zdz_contact_bridge',
		);

		$query          = trim( (string) ( $payload['query'] ?? '' ) );
		$requesting_uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		$is_kiosk       = self::resolve_is_kiosk( $payload, $requesting_uid );

		if ( $query === '' ) {
			$result['needs_clarify'] = true;
			$result['message']       = 'Which customer should I look up?';
			return $result;
		}

		if ( ! self::is_available() ) {
			$result['error'] = 'Contact lookup is unavailable — neither the CRM nor FreshBooks is connected.';
			return $result;
		}

		// ── Resolve the customer (confidence-gated; never guess between two) ──
		// On kiosk we only ever return name+city, so skip the (expensive, 5-year)
		// salesperson-code scan that scope decisions need — it's never used there.
		$record = self::resolve_contact( $query, ! $is_kiosk );
		if ( empty( $record ) ) {
			$result['needs_clarify'] = true;
			$result['message']       = 'I couldn\'t find a customer matching "' . $query . '". Could you give me their full name?';
			return $result;
		}

		// ── v2.28.11: CLOSEST-MATCH GUARD (no silent wrong-person) ──
		// resolve_freshbooks_client() accepts a fuzzy/typo last-name match and a
		// last-name-only fallback, so an asked first name that matched NOBODY can
		// still resolve to a different person who shares the surname (live: "who is
		// Sam Rivera" resolved to Chris Rivera). Detect when the resolved name does
		// not contain every token the user asked for, and flag it so the card can
		// say "closest match" + offer chat — instead of presenting the wrong
		// contact as exact. We only FLAG (never suppress): a closest match is still
		// useful, it just must be labeled. Mirrors the chat path's confirm step.
		$asked_tokens = array_values( array_filter( preg_split( '/\s+/', strtolower( trim( $query ) ) ), function ( $t ) {
			return $t !== '' && strlen( $t ) > 1; // ignore initials/punctuation noise
		} ) );
		$resolved_lc  = strtolower( (string) ( $record['name'] ?? '' ) );
		$is_closest   = false;
		if ( ! empty( $asked_tokens ) && $resolved_lc !== '' ) {
			foreach ( $asked_tokens as $tok ) {
				// token present verbatim? then it matched. Otherwise, allow a near
				// (<=1 edit) match against any word of the resolved name before
				// declaring a mismatch, so genuine typos ('Rivera'->'Rivera') don't
				// trip the flag but a wholly different given name ('Steve') does.
				if ( strpos( $resolved_lc, $tok ) !== false ) { continue; }
				$near = false;
				foreach ( preg_split( '/\s+/', $resolved_lc ) as $rw ) {
					if ( $rw !== '' && levenshtein( $rw, $tok ) <= 1 ) { $near = true; break; }
				}
				if ( ! $near ) { $is_closest = true; break; }
			}
		}
		if ( $is_closest ) {
			$result['closest_match'] = true;
			$result['asked']         = $query;
		}

		// ── DISCLOSURE: kiosk gets name + city ONLY, regardless of scope ──
		if ( $is_kiosk ) {
			$result['contact'] = array(
				'name' => $record['name'],
				'city' => $record['city'],
			);
			$result['message'] = 'On this shared device I can only show the customer name and city. Sign in on your own device for full contact details.';
			return $result;
		}

		// ── SCOPE: may this requester see this customer's full contact? ──
		$scope = self::check_scope( $record, $requesting_uid );
		if ( ! $scope['allowed'] ) {
			$result['denied']  = true;
			$result['message'] = $scope['message'];
			// On denial we still confirm the customer EXISTS by name+city (no PII),
			// so the user knows the lookup worked but isn't theirs to see.
			$result['contact'] = array(
				'name' => $record['name'],
				'city' => $record['city'],
			);
			return $result;
		}

		// ── Full disclosure (populated fields only; never fabricate) ──
		$result['contact'] = array_filter( array(
			'name'                 => $record['name'],
			'company'              => $record['company'],
			'phone'                => $record['phone'],
			'email'                => $record['email'],
			'address'              => $record['address'],
			'city'                 => $record['city'],
			'zip'                  => $record['zip'],
			'nutshell_url'         => $record['nutshell_url'],
			'freshbooks_client_id' => $record['freshbooks_client_id'],
			'sources'              => $record['sources'],
		), function ( $v ) {
			return $v !== '' && $v !== null && $v !== array();
		} );

		if ( $record['phone'] === '' && $record['email'] === '' ) {
			$result['message'] = 'Found ' . $record['name'] . ', but no phone or email is on file.';
		}

		return $result;
	}

	/**
	 * Resolve + MERGE a contact from Nutshell (primary) and FreshBooks
	 * (corroboration). Returns a normalized record, or [] when not confidently
	 * found. Prefers non-empty fields; records the source of phone/email/address.
	 *
	 * @param string $query
	 * @return array
	 */
	private static function resolve_contact( string $query, bool $need_codes = true ): array {
		$rec = array(
			'name'                 => '',
			'company'              => '',
			'phone'                => '',
			'email'                => '',
			'address'              => '',
			'city'                 => '',
			'zip'                  => '',
			'nutshell_url'         => '',
			'freshbooks_client_id' => '',
			'sp_codes'             => array(),   // initials attached to this customer
			'sources'              => array(),
		);
		$found = false;

		// ── FreshBooks first: it carries the salesperson initials we need for
		//    scope, plus reliable billing phone/email/address. ──
		$fb_client = self::resolve_freshbooks_client( $query );
		if ( ! empty( $fb_client ) ) {
			$found = true;
			$fname = trim( (string) ( $fb_client['fname'] ?? '' ) );
			$lname = trim( (string) ( $fb_client['lname'] ?? '' ) );
			$org   = trim( (string) ( $fb_client['organization'] ?? '' ) );
			$rec['name']    = trim( $fname . ' ' . $lname );
			if ( $rec['name'] === '' ) { $rec['name'] = $org; }
			$rec['company'] = $org;

			// FreshBooks field names are quirky — use the SAME proven extraction as
			// the analytics app's customer lookup (TSA_FreshBooks::extract_client_*): the phone key
			// is `mob_phone` (NOT `mobile_phone`), and email may live in a contacts
			// sub-array / username / pref_email rather than a flat `email`.
			$fb_phone = trim( (string) ( $fb_client['mob_phone'] ?? $fb_client['home_phone'] ?? $fb_client['bus_phone'] ?? $fb_client['mobile_phone'] ?? '' ) );
			$fb_email = self::extract_fb_email( $fb_client );
			if ( $fb_phone !== '' ) { $rec['phone'] = $fb_phone; $rec['sources']['phone'] = 'freshbooks'; }
			if ( $fb_email !== '' ) { $rec['email'] = $fb_email; $rec['sources']['email'] = 'freshbooks'; }

			// Address: primary p_* fields (with p_street2), falling back to shipping s_*.
			$street = trim( (string) ( $fb_client['p_street'] ?? '' ) . ' ' . (string) ( $fb_client['p_street2'] ?? '' ) );
			$city   = trim( (string) ( $fb_client['p_city'] ?? '' ) );
			$prov   = trim( (string) ( $fb_client['p_province'] ?? '' ) );
			$code   = trim( (string) ( $fb_client['p_code'] ?? '' ) );
			if ( $city === '' && ! empty( $fb_client['s_city'] ) ) {
				$street = trim( (string) ( $fb_client['s_street'] ?? '' ) . ' ' . (string) ( $fb_client['s_street2'] ?? '' ) );
				$city   = trim( (string) ( $fb_client['s_city'] ?? '' ) );
				$prov   = trim( (string) ( $fb_client['s_province'] ?? '' ) );
				$code   = trim( (string) ( $fb_client['s_code'] ?? '' ) );
			}
			// FreshBooks sometimes appends the phone onto the street string; if we
			// still have no phone, salvage a phone-looking tail from the street.
			if ( $fb_phone === '' && $street !== '' && preg_match( '/(\+?\d[\d\-\.\(\) ]{7,}\d)\s*$/', $street, $pm ) ) {
				$rec['phone'] = trim( $pm[1] ); $rec['sources']['phone'] = 'freshbooks';
				$street = trim( preg_replace( '/\s*\+?\d[\d\-\.\(\) ]{7,}\d\s*$/', '', $street ) );
			}
			$rec['city'] = $city;
			$rec['zip']  = $code;
			$addr_parts  = array_filter( array( $street, $city, trim( $prov . ' ' . $code ) ) );
			if ( ! empty( $addr_parts ) ) {
				$rec['address'] = implode( ', ', $addr_parts );
				$rec['sources']['address'] = 'freshbooks';
			}
			if ( ! empty( $fb_client['id'] ) ) { $rec['freshbooks_client_id'] = (string) $fb_client['id']; }

			// Salesperson initials attached to this customer (drives scope).
			// Skipped on kiosk ($need_codes false) — scope is never evaluated there.
			if ( $need_codes ) {
				$rec['sp_codes'] = self::collect_sp_codes_for_client( $fb_client );
			}
		}

		// ── Nutshell corroboration: fill gaps; prefer to keep FreshBooks values. ──
		$ns_contact = self::resolve_nutshell_contact( $query );
		if ( ! empty( $ns_contact ) ) {
			$found = true;
			if ( $rec['name'] === '' && ! empty( $ns_contact['name'] ) ) {
				$rec['name'] = trim( (string) $ns_contact['name'] );
			}
			if ( $rec['phone'] === '' && ! empty( $ns_contact['phone'] ) ) {
				$rec['phone'] = trim( (string) $ns_contact['phone'] );
				$rec['sources']['phone'] = 'nutshell';
			}
			if ( $rec['email'] === '' && ! empty( $ns_contact['email'] ) ) {
				$rec['email'] = trim( (string) $ns_contact['email'] );
				$rec['sources']['email'] = 'nutshell';
			}
			if ( $rec['city'] === '' && ! empty( $ns_contact['city'] ) ) {
				$rec['city'] = trim( (string) $ns_contact['city'] );
			}
			if ( ! empty( $ns_contact['url'] ) ) {
				$rec['nutshell_url'] = (string) $ns_contact['url'];
			}
		}

		return $found ? $rec : array();
	}

	/**
	 * Broad FreshBooks client search via the shared core client, mirroring the analytics app's
	 * proven search_clients(): search[user_like] (fuzzy, all name fields) +
	 * include[]=contacts (full objects with phone/email), with an archived-clients
	 * (vis_state=2) retry when the active pool is empty. Returns the clients array.
	 *
	 * @param \ZDZ_Core_FreshBooks $fb
	 * @param string              $name
	 * @param int                 $limit
	 * @return array
	 */
	private static function fb_search_clients( $fb, string $name, int $limit = 10 ): array {
		$name = trim( $name );
		if ( $name === '' ) {
			return array();
		}
		$resp = $fb->get_clients( array(
			'search[user_like]' => $name,
			'include[]'         => 'contacts',
			'per_page'          => $limit,
		) );
		$clients = $resp['response']['result']['clients'] ?? ( $resp['response']['result']['client'] ?? array() );

		// Retry archived (vis_state=2) — a separate pool FreshBooks excludes by default.
		if ( ( ! is_array( $clients ) || empty( $clients ) ) ) {
			$resp = $fb->get_clients( array(
				'search[user_like]' => $name,
				'search[vis_state]' => 2,
				'include[]'         => 'contacts',
				'per_page'          => $limit,
			) );
			$clients = $resp['response']['result']['clients'] ?? ( $resp['response']['result']['client'] ?? array() );
		}
		return is_array( $clients ) ? array_values( $clients ) : array();
	}

	/**
	 * FreshBooks client resolution, confidence-gated. Mirrors TSEC's proven
	 * resolve_customer(): single candidate → accept; several → accept only when
	 * exactly one shares the spoken last name; otherwise ambiguous → [].
	 *
	 * @param string $query
	 * @return array Raw FreshBooks client row, or [].
	 */
	private static function resolve_freshbooks_client( string $query ): array {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return array();
		}
		$fb = new \ZDZ_Core_FreshBooks();
		if ( method_exists( $fb, 'is_configured' ) && ! $fb->is_configured() ) {
			return array();
		}

		$parts = preg_split( '/\s+/', trim( $query ) );
		$fname = '';
		$lname = '';
		if ( count( $parts ) === 1 ) {
			$lname = $parts[0];
		} else {
			$fname = $parts[0];
			$lname = end( $parts );
		}

		// Use the SAME proven query the TSA chat path uses (search_clients):
		// search[user_like] is a broad fuzzy search across all name fields AND
		// returns FULL client objects (phone/email/contacts populated) —
		// search[lname] returns leaner rows AND only exact-ish last names, which is
		// why the inline card was showing "no phone or email on file" and missing
		// typo'd surnames. include[]=contacts pulls the contacts sub-array email.
		$clients = self::fb_search_clients( $fb, trim( $query ), 10 );

		// Fallbacks: try just the last name, then just the first name (mirrors the
		// chat path's progressive narrowing) so a slightly-off full string still hits.
		if ( empty( $clients ) && $lname !== '' && strcasecmp( $lname, trim( $query ) ) !== 0 ) {
			$clients = self::fb_search_clients( $fb, $lname, 10 );
		}
		if ( empty( $clients ) && $fname !== '' ) {
			$clients = self::fb_search_clients( $fb, $fname, 10 );
		}

		if ( empty( $clients ) ) {
			return array();
		}
		$clients = array_values( $clients );

		if ( count( $clients ) === 1 ) {
			return $clients[0];
		}

		// Narrow by first name when we have one.
		if ( $fname !== '' ) {
			$wantf = strtolower( $fname );
			$byf = array_values( array_filter( $clients, function ( $c ) use ( $wantf ) {
				return strtolower( trim( (string) ( $c['fname'] ?? '' ) ) ) === $wantf;
			} ) );
			if ( count( $byf ) === 1 ) {
				return $byf[0];
			}
		}

		// Exactly one shares the last name → accept; else ambiguous.
		$wantl = strtolower( $lname );
		$byl = array_values( array_filter( $clients, function ( $c ) use ( $wantl ) {
			return strtolower( trim( (string) ( $c['lname'] ?? '' ) ) ) === $wantl;
		} ) );
		if ( count( $byl ) === 1 ) {
			return $byl[0];
		}

		// Typo tolerance: if the search already fuzzy-matched and there's exactly
		// ONE candidate whose last name is a near-match to the spoken one (prefix
		// or short edit distance), accept it — this resolves "Rivera"→"Rivera"
		// like the chat path does, without guessing between two different people.
		if ( $wantl !== '' ) {
			$near = array_values( array_filter( $clients, function ( $c ) use ( $wantl ) {
				$ln = strtolower( trim( (string) ( $c['lname'] ?? '' ) ) );
				if ( $ln === '' ) { return false; }
				if ( strpos( $ln, $wantl ) === 0 || strpos( $wantl, $ln ) === 0 ) { return true; }
				return levenshtein( $ln, $wantl ) <= 2;
			} ) );
			if ( count( $near ) === 1 ) {
				return $near[0];
			}
		}

		error_log( 'ZDZ_Contact_Bridge: ' . count( $clients ) . ' FreshBooks candidates for "' . $query . '"; ambiguous, deferring to clarify.' );
		return array();
	}

	/**
	 * Nutshell contact resolution via the shared client's findContacts RPC.
	 * Returns a small normalized stub or [].
	 *
	 * @param string $query
	 * @return array
	 */
	private static function resolve_nutshell_contact( string $query ): array {
		if ( ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return array();
		}
		$ns = new \ZDZ_Core_Nutshell();
		if ( method_exists( $ns, 'is_configured' ) && ! $ns->is_configured() ) {
			return array();
		}

		// Use Nutshell's searchContacts (the platform-proven RPC — full-text search
		// that also indexes phone numbers), via the shared client's generic rpc_call.
		// Shape: a flat array of stubs [ {id, name, entityType:"Contacts", ...}, ... ]
		// (older callers see it under a 'result' key — tolerate both).
		$resp = null;
		if ( method_exists( $ns, 'rpc_call' ) ) {
			$resp = $ns->rpc_call( 'searchContacts', array( 'string' => $query, 'limit' => 10 ) );
		} elseif ( method_exists( $ns, 'find_contacts' ) ) {
			$resp = $ns->find_contacts( array( 'string' => $query, 'limit' => 10 ) );
		}

		$rows = array();
		if ( is_array( $resp ) ) {
			if ( isset( $resp['result'] ) && is_array( $resp['result'] ) ) {
				$rows = $resp['result'];
			} elseif ( isset( $resp[0] ) ) {
				$rows = $resp; // already a flat array of stubs
			}
		}
		if ( empty( $rows ) ) {
			return array();
		}
		$rows = array_values( $rows );

		// Accept only a confident single match (or unique last-name match) — never
		// guess between two people.
		$pick = null;
		if ( count( $rows ) === 1 ) {
			$pick = $rows[0];
		} else {
			$parts = preg_split( '/\s+/', trim( $query ) );
			$lname = strtolower( (string) end( $parts ) );
			$matches = array_values( array_filter( $rows, function ( $r ) use ( $lname ) {
				$nm = strtolower( (string) ( $r['name'] ?? '' ) );
				return $lname !== '' && substr( $nm, -strlen( $lname ) ) === $lname;
			} ) );
			if ( count( $matches ) === 1 ) {
				$pick = $matches[0];
			}
		}
		if ( ! $pick ) {
			return array();
		}

		// searchContacts stubs are shallow (id + name + entityType). Some Nutshell
		// configs return contact detail inline; pull phone/email/city when present,
		// tolerating both scalar and Nutshell's array-of-{value} shapes.
		$contact = $pick['contact'] ?? $pick;
		$phone = '';
		if ( ! empty( $contact['phone'] ) ) {
			$phone = is_array( $contact['phone'] )
				? (string) ( $contact['phone'][0]['value'] ?? reset( $contact['phone'] ) )
				: (string) $contact['phone'];
		}
		$email = '';
		if ( ! empty( $contact['email'] ) ) {
			$email = is_array( $contact['email'] )
				? (string) ( $contact['email'][0]['value'] ?? reset( $contact['email'] ) )
				: (string) $contact['email'];
		}

		return array(
			'name'  => (string) ( $pick['name'] ?? $contact['name'] ?? '' ),
			'phone' => $phone,
			'email' => $email,
			'city'  => (string) ( $contact['city'] ?? '' ),
			'url'   => (string) ( $pick['htmlUrl'] ?? $pick['url'] ?? '' ),
		);
	}

	/**
	 * Pull the salesperson INITIALS attached to a FreshBooks customer by reading
	 * the codes off their recent invoices/estimates. Reuses the same parenthesized
	 * "(GT)" / "(GT/DT)" convention TSCC parses for commission splits.
	 *
	 * @param array $fb_client
	 * @return array Upper-cased initials, e.g. ['GT','DT'].
	 */
	private static function collect_sp_codes_for_client( array $fb_client ): array {
		$codes = array();
		$client_id = (int) ( $fb_client['id'] ?? 0 );
		if ( $client_id <= 0 || ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return $codes;
		}
		$fb = new \ZDZ_Core_FreshBooks();
		if ( method_exists( $fb, 'is_configured' ) && ! $fb->is_configured() ) {
			return $codes;
		}

		// Recent invoices carry the line-item text with "(SP)" codes.
		$rows = array();
		if ( method_exists( $fb, 'get_client_invoices' ) ) {
			$rows = $fb->get_client_invoices( $client_id, 1825 ); // ~5y of history
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		foreach ( $rows as $inv ) {
			$haystack = '';
			// Top-level notes/po + each line description.
			$haystack .= ' ' . ( $inv['notes'] ?? '' ) . ' ' . ( $inv['po_number'] ?? '' );
			$lines = $inv['lines'] ?? array();
			if ( is_array( $lines ) ) {
				foreach ( $lines as $ln ) {
					$haystack .= ' ' . ( $ln['name'] ?? '' ) . ' ' . ( $ln['description'] ?? '' );
				}
			}
			foreach ( self::extract_sp_codes( $haystack ) as $c ) {
				$codes[ $c ] = true;
			}
		}

		return array_keys( $codes );
	}

	/**
	 * Robustly pull an email from a FreshBooks client object. Mirrors
	 * TSA_FreshBooks::extract_client_email(): top-level email → contacts sub-array
	 * → username-if-email → pref_email. Kept local so the theme has no hard
	 * dependency on the TSA plugin.
	 *
	 * @param array $client
	 * @return string
	 */
	private static function extract_fb_email( $client ): string {
		if ( empty( $client ) || ! is_array( $client ) ) {
			return '';
		}
		if ( ! empty( $client['email'] ) ) {
			return trim( (string) $client['email'] );
		}
		if ( ! empty( $client['contacts'] ) && is_array( $client['contacts'] ) ) {
			foreach ( $client['contacts'] as $contact ) {
				if ( ! empty( $contact['email'] ) ) {
					return trim( (string) $contact['email'] );
				}
			}
		}
		if ( ! empty( $client['username'] ) && filter_var( $client['username'], FILTER_VALIDATE_EMAIL ) ) {
			return trim( (string) $client['username'] );
		}
		if ( ! empty( $client['pref_email'] ) && is_string( $client['pref_email'] )
			&& filter_var( $client['pref_email'], FILTER_VALIDATE_EMAIL ) ) {
			return trim( (string) $client['pref_email'] );
		}
		return '';
	}

	/**
	 * Parse parenthesized salesperson codes from free text. A self-contained copy
	 * of TSCC_FreshBooks::extract_sp_codes() (kept local so the theme has no hard
	 * dependency on the plugin), with the same 2–4-letter + denylist guard.
	 *
	 * @param string $text
	 * @return array Upper-cased codes.
	 */
	private static function extract_sp_codes( string $text ): array {
		$codes = array();
		if ( $text === '' ) {
			return $codes;
		}
		if ( preg_match_all( '/\(\s*([A-Za-z][A-Za-z0-9 ,\/&+\.\-]*?)\s*\)/', $text, $groups ) ) {
			$deny = array( 'TAX', 'INCL', 'EA', 'QTY', 'PER', 'NA', 'TBD', 'NEW', 'OLD', 'SEE', 'PO', 'CC' );
			foreach ( $groups[1] as $inner ) {
				// Split composite groups: "CT/FR", "CT, FR", "CT & FR", "CT and FR", "CT+FR".
				$tokens = preg_split( '/\s*(?:\/|,|&|\+|\band\b)\s*/i', trim( $inner ) );
				foreach ( $tokens as $tok ) {
					$tok = strtoupper( trim( $tok ) );
					if ( $tok === '' ) { continue; }
					if ( ! preg_match( '/^[A-Z]{2,4}$/', $tok ) ) { continue; }
					if ( in_array( $tok, $deny, true ) ) { continue; }
					$codes[ $tok ] = true;
				}
			}
		}
		return array_keys( $codes );
	}

	/**
	 * SCOPE decision: may $requesting_uid see $record's full contact?
	 * admin/owner → yes; else allowed only if the requester's own salesperson
	 * code is among the codes attached to the customer (own job or shared job).
	 *
	 * @param array $record
	 * @param int   $requesting_uid
	 * @return array { @type bool $allowed; @type string $message }
	 */
	private static function check_scope( array $record, int $requesting_uid ): array {
		// Admin / owner see everyone.
		if ( self::user_is_admin_like( $requesting_uid ) ) {
			return array( 'allowed' => true, 'message' => '' );
		}

		$mine = strtoupper( trim( self::get_user_sp_code( $requesting_uid ) ) );
		$attached = array_map( 'strtoupper', (array) ( $record['sp_codes'] ?? array() ) );

		if ( $mine !== '' && in_array( $mine, $attached, true ) ) {
			return array( 'allowed' => true, 'message' => '' );
		}

		// No relationship → deny disclosure, but stay friendly + actionable.
		$who = $record['name'] !== '' ? $record['name'] : 'that customer';
		$msg = 'That customer (' . $who . ') isn\'t on one of your jobs, so I can\'t share their contact details. '
			. 'If you should have access, ask an admin to add your initials to the job.';
		return array( 'allowed' => false, 'message' => $msg );
	}

	/**
	 * The requester's salesperson initials, from their commission profile meta
	 * (set in TSCC), with a ZDZ_Core_Settings fallback.
	 *
	 * @param int $uid
	 * @return string
	 */
	private static function get_user_sp_code( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		$code = (string) get_user_meta( $uid, 'tscc_salesperson_code', true );
		if ( $code === '' && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_salesperson_code' ) ) {
			$code = (string) \ZDZ_Core_Settings::get_salesperson_code( $uid );
		}
		return trim( $code );
	}

	/**
	 * admin/owner-like check. Uses the platform permission layer when present,
	 * falling back to the WP capability.
	 *
	 * @param int $uid
	 * @return bool
	 */
	private static function user_is_admin_like( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'get_tier' ) ) {
			$tier = strtolower( (string) \ZDZ_Data_Permissions::get_tier( $uid ) );
			if ( in_array( $tier, array( 'admin', 'owner', 'operator' ), true ) ) {
				return true;
			}
		}
		$u = get_userdata( $uid );
		if ( $u && ( in_array( 'administrator', (array) $u->roles, true )
			|| in_array( 'zdz_owner', (array) $u->roles, true )
			|| in_array( 'zdz_admin', (array) $u->roles, true ) ) ) {
			return true;
		}
		return user_can( $uid, 'manage_options' );
	}

	/**
	 * Resolve kiosk most-restrictive-wins: explicit flag OR tier OR runtime role.
	 *
	 * @param array $payload
	 * @param int   $uid
	 * @return bool
	 */
	private static function resolve_is_kiosk( array $payload, int $uid ): bool {
		if ( ! empty( $payload['is_kiosk'] ) ) {
			return true;
		}
		$tier = strtolower( trim( (string) ( $payload['tier'] ?? '' ) ) );
		if ( in_array( $tier, array( 'kiosk', 'zdz_general', 'general' ), true ) ) {
			return true;
		}
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		if ( $u && in_array( 'zdz_general', (array) $u->roles, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * L4-ready capability descriptor (NOT registered yet — zdz_register_capabilities
	 * does not exist until Stage 1). Shaped so onboarding is a one-line add_filter.
	 *
	 * @return array
	 */
	public static function get_capability_descriptor(): array {
		return array(
			'verb'        => 'contact.lookup',
			'provider'    => 'theme-contacts',
			'tier'        => 'viewer',   // minimum to reach the verb; scope refined in-callback
			'callback'    => array( 'ZDZ_Contact_Bridge', 'lookup_for_tsa' ),
			'kiosk'       => true,        // reachable on kiosk, but DISCLOSURE is name+city only
			'side_effect' => false,
		);
	}

	/**
	 * Descriptive format spec (what the bot should emit). Companion to is_available().
	 *
	 * @return array
	 */
	public static function get_format_spec(): array {
		return array(
			'marker'  => '[ZDZ_CONTACT]{ "query": "<customer name>" }[/ZDZ_CONTACT]',
			'payload' => array(
				'query' => 'string — the customer name as the user said it',
			),
		);
	}
}
