<?php
/**
 * Zorderz Leads - Nutshell API Client
 *
 * ARCHITECTURE ROLE:
 * This class handles all JSON-RPC 2.0 communication with the Nutshell CRM.
 * It is primarily used in Step 6 (Create Nutshell) of the 8-step generation pipeline
 * to create contacts, leads, and notes for the generated sales leads.
 *
 * BUSINESS CONTEXT:
 * the business uses Nutshell CRM to manage its sales pipeline:
 * its salespeople. Leads generated from FreshBooks paid invoices are 
 * pushed here. Note: Nutshell enforces a strict <101 character limit on lead descriptions,
 * which is handled by the AI refinement step before calling these methods.
 *
 * API DETAILS:
 * Nutshell uses JSON-RPC 2.0. It requires an initial "discovery" request to find the
 * user-specific API endpoint before making authenticated calls using Basic Auth.
 *
 * @package Zorderz\Leads
 * @version 1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class ZL_Nutshell {

	/**
	 * @var string Nutshell login email (used for Basic Auth and discovery).
	 */
	private $email;

	/**
	 * @var string Nutshell API key.
	 */
	private $api_key;

	/**
	 * @var int JSON-RPC request ID counter.
	 */
	private $_id;

	/**
	 * @var string|null The discovered user-specific API endpoint URL.
	 */
	private $_api_url;

	/**
	 * @var array Log of discovery attempts for diagnostic purposes.
	 */
	private $_discovery_log;

	/**
	 * Constructor.
	 *
	 * v2.5.2 (interop §1.2/§1.4): credentials may be omitted. When they are, we
	 * source them from the theme's shared settings (ZDZ_Core_Settings) — the SAME
	 * single source the shared ZDZ_Core_Nutshell uses — so ZL no longer holds an
	 * independent copy of the Nutshell credential that could drift. Explicitly
	 * passed creds still win (back-compat for existing callers). The transport
	 * below is unchanged (its discovery + descriptive exceptions are retained);
	 * this only unifies WHERE the credential comes from. Defaulting the params also
	 * makes the documented no-arg construction safe.
	 *
	 * @param string $email   Nutshell login email. Empty → shared settings.
	 * @param string $api_key Nutshell API key.   Empty → shared settings.
	 */
	public function __construct( $email = '', $api_key = '' ) {
		// Prefer explicitly-passed creds; otherwise fall back to the shared source.
		if ( ( '' === $email || '' === $api_key ) && class_exists( 'ZDZ_Core_Settings' ) ) {
			if ( '' === $email && method_exists( 'ZDZ_Core_Settings', 'get_ns_email' ) ) {
				$email = (string) ZDZ_Core_Settings::get_ns_email();
			}
			if ( '' === $api_key && method_exists( 'ZDZ_Core_Settings', 'get_ns_api_key' ) ) {
				$api_key = (string) ZDZ_Core_Settings::get_ns_api_key();
			}
		}
		$this->email          = $email;
		$this->api_key        = $api_key;
		$this->_id            = 0;
		$this->_api_url       = null;
		$this->_discovery_log = array();
	}

	/**
	 * Discover the user-specific API endpoint.
	 * 
	 * Nutshell requires clients to call 'getApiForUsername' on the generic endpoint
	 * to receive a dedicated host (e.g., app01.nutshell.com) for subsequent API calls.
	 * Caches the result in $this->_api_url for the lifecycle of the object.
	 *
	 * @return void
	 */
	private function _discover() {
		// If we already discovered the endpoint, skip.
		if ( $this->_api_url ) {
			return;
		}
		$this->_discovery_log = array();

		// Try HTTPS first, fallback to HTTP if needed during discovery.
		$schemes = array( 'https', 'http' );
		foreach ( $schemes as $scheme ) {
			$discovery_url = "{$scheme}://api.nutshell.com/v1/json";
			$this->_discovery_log[] = "Trying discovery: {$discovery_url}";

			// Build JSON-RPC 2.0 payload for discovery
			$payload = array(
				'jsonrpc' => '2.0',
				'method'  => 'getApiForUsername',
				'params'  => array( 'username' => $this->email ),
				'id'      => 'discovery',
			);

			$response = wp_remote_post(
				$discovery_url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->_discovery_log[] = "Discovery error ({$scheme}): " . $response->get_error_message();
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$this->_discovery_log[] = "Discovery response: {$status_code}";

			if ( 200 === $status_code ) {
				$data   = json_decode( wp_remote_retrieve_body( $response ), true );
				$result = isset( $data['result'] ) ? $data['result'] : null;

				// Parse the dedicated API host from the result
				if ( is_array( $result ) && ! empty( $result['api'] ) ) {
					$host = $result['api'];
					if ( strpos( $host, 'http' ) === 0 ) {
						$this->_api_url = rtrim( $host, '/' ) . '/api/v1/json';
					} else {
						$this->_api_url = "https://{$host}/api/v1/json";
					}
					$this->_discovery_log[] = "Discovered endpoint: {$this->_api_url}";
					return;
				}
				$this->_discovery_log[] = 'Unexpected discovery response: ' . substr( wp_remote_retrieve_body( $response ), 0, 200 );
			}
		}

		// Fallback to the default app endpoint if discovery completely fails
		$this->_api_url = 'https://app.nutshell.com/api/v1/json';
		$this->_discovery_log[] = "Using fallback endpoint: {$this->_api_url}";
	}

	/**
	 * Execute a JSON-RPC 2.0 call.
	 * 
	 * Handles endpoint discovery, payload formatting, Basic Authentication,
	 * and error parsing.
	 *
	 * @param string $method The RPC method name (e.g., 'newLead').
	 * @param array  $params The RPC parameters.
	 * @return mixed The result data from the JSON-RPC response.
	 * @throws Exception If the HTTP request or RPC call fails.
	 */
	private function _rpc( $method, $params = array() ) {
		$this->_discover();
		$this->_id++;

		$payload = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => (string) $this->_id,
		);

		// Nutshell uses Basic Auth with the user's email and API key
		$auth_header = 'Basic ' . base64_encode( "{$this->email}:{$this->api_key}" );

		$response = wp_remote_post(
			$this->_api_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $auth_header,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( "Nutshell HTTP error on '{$method}': " . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_json   = wp_remote_retrieve_body( $response );

		if ( 401 === $status_code ) {
			throw new Exception( 'Nutshell authentication failed. Check your API Key and Login Email in settings.' );
		}

		if ( 200 !== $status_code ) {
			// Try to extract JSON-RPC error message from response body
			$rpc_msg = '';
			$rpc_data = json_decode( $body_json, true );
			if ( is_array( $rpc_data ) && isset( $rpc_data['error']['message'] ) ) {
				$rpc_msg = $rpc_data['error']['message'];
			}
			$log_str = implode( '; ', $this->_discovery_log );
			$msg = "Nutshell API error (HTTP {$status_code}) calling '{$method}'";
			if ( $rpc_msg ) {
				$msg .= ": {$rpc_msg}";
			}
			$msg .= " at {$this->_api_url}";
			error_log( $msg . "\nFull response: " . substr( $body_json, 0, 500 ) );
			throw new Exception( $msg );
		}

		$data = json_decode( $body_json, true );

		// Check for application-level JSON-RPC errors
		if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
			$err = $data['error'];
			$msg = is_array( $err ) && isset( $err['message'] ) ? $err['message'] : wp_json_encode( $err );
			throw new Exception( "Nutshell RPC error on '{$method}': {$msg}" );
		}

		return isset( $data['result'] ) ? $data['result'] : null;
	}

	/**
	 * Diagnostic: test discovery and a simple authenticated call.
	 * 
	 * Used by the WordPress admin settings page (class-zl-admin.php) to verify
	 * credentials when the user saves the Nutshell API settings.
	 *
	 * @return array Array of diagnostic log strings.
	 */
	public function test_connection() {
		$report = array();
		$this->_api_url = null; // Force re-discovery
		$this->_discover();

		$report = array_merge( $report, $this->_discovery_log );
		$report[] = "Final endpoint: {$this->_api_url}";

		try {
			$this->_id++;
			// 'getUser' is a safe, read-only method to test authentication
			$payload = array(
				'jsonrpc' => '2.0',
				'method'  => 'getUser',
				'params'  => array(),
				'id'      => (string) $this->_id,
			);

			$auth_header = 'Basic ' . base64_encode( "{$this->email}:{$this->api_key}" );

			$response = wp_remote_post(
				$this->_api_url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => $auth_header,
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				$report[] = 'getUser HTTP error: ' . $response->get_error_message();
				return $report;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$report[] = "getUser HTTP status: {$status_code}";

			if ( 200 === $status_code ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $data['result'] ) ) {
					$user = $data['result'];
					$name = isset( $user['name'] ) ? $user['name'] : '';
					$report[] = "Authenticated as: {$name}";
					$report[] = 'CONNECTION OK';
				} elseif ( ! empty( $data['error'] ) ) {
					$err = $data['error'];
					$msg = is_array( $err ) && isset( $err['message'] ) ? $err['message'] : wp_json_encode( $err );
					$report[] = "getUser RPC error: {$msg}";
				}
			} else {
				$report[] = 'getUser response: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
			}
		} catch ( \Throwable $exc ) {
			$report[] = 'getUser exception: ' . $exc->getMessage();
		}

		return $report;
	}

	// -- Convenience wrappers -----------------------------------------
	// These methods map directly to Nutshell JSON-RPC methods.
	// Used primarily in Step 6 (Create Nutshell) of the pipeline.

	// --- Contacts ---

	/**
	 * Search for a contact by exact email address.
	 */
	public function search_by_email( $email ) {
		return $this->_rpc( 'searchByEmail', array( 'emailAddressString' => $email ) );
	}

	/**
	 * Search contacts by a general string.
	 */
	public function search_contacts( $string, $limit = 10 ) {
		return $this->_rpc( 'searchContacts', array( 'string' => $string, 'limit' => $limit ) );
	}

	/**
	 * v1.8.0 — Bulk search by email. Submits N JSON-RPC requests in parallel
	 * via ZL_Parallel_Dispatch, then decodes and classifies each response
	 * into contact-id-or-null.
	 *
	 * This replaces the previous N-sequential-HTTP-roundtrip pattern that
	 * dominated enrichment latency for medium/large batches (Improvement A).
	 *
	 * ──────────────────────────────────────────────────────────────────────
	 * TRAP 2 — DEDUP RACE AWARENESS
	 * ──────────────────────────────────────────────────────────────────────
	 * This returns a SNAPSHOT of Nutshell state at bulk-fetch time. A lead
	 * created by another process (or this process later in the batch) will
	 * not appear until the next bulk_search. Callers that rely on the
	 * snapshot for dedup MUST re-check at push time — searching again
	 * right before creating a contact.
	 *
	 * @param string[] $emails  List of email addresses (case-insensitive;
	 *                          dedup happens internally).
	 * @param int      $cap     Max concurrent requests. Default 8.
	 * @param callable|null $on_progress  Progress callback fn(email_idx).
	 * @return array  Map  email_lower → { contact_id: int|null, raw: array|null }
	 *                Non-matched emails map to { contact_id: null, raw: null }.
	 *
	 * @since 1.8.0
	 */
	public function bulk_search_contacts( array $emails, $cap = 8, $on_progress = null ) {
		if ( empty( $emails ) ) {
			return array();
		}

		$this->_discover();
		if ( ! $this->_api_url ) {
			throw new Exception( 'Nutshell discovery failed — cannot bulk search.' );
		}

		// Deduplicate + lowercase BEFORE firing requests. A single batch of
		// 200 leads easily contains 40 duplicate emails (shared household,
		// same primary contact). Deduping here avoids 40 wasted requests.
		$unique = array();
		foreach ( $emails as $e ) {
			$e = strtolower( trim( (string) $e ) );
			if ( $e !== '' && strpos( $e, '@' ) !== false && ! isset( $unique[ $e ] ) ) {
				$unique[ $e ] = true;
			}
		}
		$unique_list = array_keys( $unique );
		if ( empty( $unique_list ) ) {
			return array();
		}

		// Build the request batch — one HTTP POST per email.
		$auth = 'Basic ' . base64_encode( "{$this->email}:{$this->api_key}" );
		$requests = array();
		foreach ( $unique_list as $i => $e ) {
			$this->_id++;
			$payload = array(
				'jsonrpc' => '2.0',
				'method'  => 'searchByEmail',
				'params'  => array( 'emailAddressString' => $e ),
				'id'      => 'bulk_' . $this->_id,
			);
			$requests[] = array(
				'id'      => 'em:' . $e,
				'url'     => $this->_api_url,
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $auth,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			);
		}

		if ( ! class_exists( 'ZL_Parallel_Dispatch' ) ) {
			// Defensive — class should be loaded alongside this one, but
			// fall back to sequential execution if somehow not.
			$out = array();
			foreach ( $unique_list as $e ) {
				$out[ $e ] = array(
					'contact_id' => $this->extract_contact_id_or_null(
						$this->_rpc( 'searchByEmail', array( 'emailAddressString' => $e ) )
					),
					'raw' => null,
				);
			}
			return $out;
		}

		$results = ZL_Parallel_Dispatch::run( $requests, max( 1, (int) $cap ), $on_progress );

		// Decode results.
		$out = array();
		foreach ( $unique_list as $e ) {
			$key  = 'em:' . $e;
			$r    = $results[ $key ] ?? null;
			$body = is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
			$dec  = $body === '' ? null : json_decode( $body, true );

			if ( is_array( $dec ) && isset( $dec['result'] ) ) {
				$cid = $this->extract_contact_id_or_null( $dec['result'] );
				$out[ $e ] = array(
					'contact_id' => $cid,
					'raw'        => $dec['result'],
				);
			} else {
				// Network error, 5xx, or malformed body — treat as "no match"
				// but surface the error for the caller's audit log.
				$out[ $e ] = array(
					'contact_id' => null,
					'raw'        => null,
					'error'      => is_array( $r ) ? ( $r['error'] ?? '' ) : '',
					'status'     => is_array( $r ) ? (int) ( $r['status'] ?? 0 ) : 0,
				);
			}
		}

		return $out;
	}

	/**
	 * Helper: extract a numeric contact ID from various searchByEmail
	 * response shapes. Mirrors extract_contact_id_from_search in the
	 * leads but returns int|null here for a cleaner caller API.
	 *
	 * @param mixed $search
	 * @return int|null
	 *
	 * @since 1.8.0
	 */
	public function extract_contact_id_or_null( $search ) {
		if ( ! is_array( $search ) ) {
			return null;
		}
		// searchByEmail: { contacts: [ { id: 123, ... }, ... ] }
		if ( isset( $search['contacts'] ) && is_array( $search['contacts'] ) && ! empty( $search['contacts'] ) ) {
			$first = $search['contacts'][0];
			if ( isset( $first['id'] ) ) {
				return (int) $first['id'];
			}
		}
		// searchContacts: flat array of stubs.
		if ( isset( $search[0]['id'] )
			&& isset( $search[0]['entityType'] )
			&& $search[0]['entityType'] === 'Contacts' ) {
			return (int) $search[0]['id'];
		}
		return null;
	}

	/**
	 * Search for contacts matching a phone number.
	 *
	 * Nutshell's searchContacts does full-text search which indexes phone numbers.
	 * Customers often use different emails for FreshBooks vs personal communication,
	 * but the phone number remains constant. This prevents duplicate contacts.
	 *
	 * @since 1.3.0
	 * @param string $phone Phone number to search for.
	 * @return mixed Search results (array of contact stubs).
	 */
	public function search_contacts_by_phone( $phone ) {
		return $this->search_contacts( $phone, 10 );
	}

	/**
	 * Get a specific contact by ID.
	 */
	public function get_contact( $contact_id ) {
		return $this->_rpc( 'getContact', array( 'contactId' => $contact_id ) );
	}

	/**
	 * Create a new contact.
	 */
	public function new_contact( $contact ) {
		return $this->_rpc( 'newContact', array( 'contact' => $contact ) );
	}

	/**
	 * Edit an existing contact. Requires the current revision number ($rev).
	 */
	public function edit_contact( $contact_id, $rev, $contact ) {
		return $this->_rpc( 'editContact', array(
			'contactId' => $contact_id,
			'rev'       => $rev,
			'contact'   => $contact,
		) );
	}

	// --- Leads ---

	/**
	 * Create a new lead.
	 * Note: The 'description' field inside $lead MUST be <101 characters.
	 */
	public function new_lead( $lead ) {
		return $this->_rpc( 'newLead', array( 'lead' => $lead ) );
	}

	/**
	 * Edit an existing lead. Requires the current revision number ($rev).
	 */
	public function edit_lead( $lead_id, $rev, $lead ) {
		return $this->_rpc( 'editLead', array(
			'leadId' => $lead_id,
			'rev'    => $rev,
			'lead'   => $lead,
		) );
	}

	/**
	 * Add a note to an entity (Lead, Contact, or Account).
	 */
	public function new_note( $entity, $note ) {
		return $this->_rpc( 'newNote', array( 'entity' => $entity, 'note' => $note ) );
	}

	/**
	 * List Nutshell users (id + name stubs). Used to resolve an assignee by name
	 * when no WP->Nutshell user-id mapping exists. Returns [] on failure.
	 */
	public function find_users( $limit = 200 ) {
		$res = $this->_rpc( 'findUsers', array( 'limit' => (int) $limit ) );
		return is_array( $res ) ? $res : array();
	}

	/**
	 * Search leads by a general string.
	 */
	public function search_leads( $string, $limit = 40 ) {
		return $this->_rpc( 'searchLeads', array( 'string' => $string, 'limit' => $limit ) );
	}

	/**
	 * Get a specific lead by ID.
	 */
	public function get_lead( $lead_id ) {
		return $this->_rpc( 'getLead', array( 'leadId' => $lead_id ) );
	}

	/**
	 * Retrieve all stagesets (pipelines) from Nutshell.
	 *
	 * Nutshell pipelines are called "stagesets" in the API. Each stageset
	 * has a numeric `id` and a string `name` (the tenant's own pipeline label).
	 * Used by create_nutshell_lead() to assign leads to the correct pipeline
	 * (routing rules are tenant Mappings — see ZL_Lead_Generator::detect_pipeline).
	 *
	 * @since 1.3.0
	 * @return array Array of stageset objects: [ { id: int, name: string, ... }, ... ]
	 */
	public function find_stagesets() {
		return $this->_rpc( 'findStagesets', array() );
	}

	/**
	 * Find leads using a structured query object.
	 */
	public function find_leads( $query = array() ) {
		return $this->_rpc( 'findLeads', array( 'query' => empty( $query ) ? new stdClass() : $query ) );
	}

	/**
	 * Fetch the timeline for a lead (activities + notes + emails).
	 *
	 * Uses `findTimeline` (not `getTimeline` which doesn't exist in the
	 * Nutshell JSON-RPC API). Matches the Surveys plugin pattern.
	 *
	 * @since 1.3.0
	 * @param int $lead_id The Nutshell lead ID.
	 * @return array Array of timeline entry objects.
	 */
	public function find_timeline_for_lead( $lead_id ) {
		$result = $this->_rpc( 'findTimeline', array(
			'query' => array(
				'leadId' => (int) $lead_id,
			),
		) );

		if ( ! $result || ! is_array( $result ) ) {
			return array();
		}

		return $result;
	}
}