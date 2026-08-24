<?php
/**
 * Shared Nutshell CRM JSON-RPC 2.0 Client
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Nutshell {

	private string $email;
	private string $api_key;
	private string $endpoint = 'https://app.nutshell.com/api/v1/json';

	public function __construct() {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			$this->email   = ZDZ_Core_Settings::get_ns_email();
			$this->api_key = ZDZ_Core_Settings::get_ns_api_key();
		}
	}

	public function rpc_call( string $method, array $params = [] ) {
		$payload = [
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => uniqid( 'ns_', true ),
		];

		$response = wp_remote_post( $this->endpoint, [
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $this->email . ':' . $this->api_key ),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		]);

		if ( is_wp_error( $response ) ) {
			error_log( 'Nutshell RPC Error: ' . $response->get_error_message() );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['error'] ) ) {
			error_log( 'Nutshell API Error: ' . wp_json_encode( $body['error'] ) );
			return null;
		}

		return $body['result'] ?? null;
	}

	public function find_contacts( array $query ) {
		return $this->rpc_call( 'findContacts', $query );
	}

	public function create_lead( array $lead_data ) {
		return $this->rpc_call( 'newLead', $lead_data );
	}

	public function create_contact( array $contact_data ) {
		return $this->rpc_call( 'newContact', $contact_data );
	}

	public function add_note( array $note_data ) {
		return $this->rpc_call( 'newNote', $note_data );
	}

	public function get_activities( array $query ) {
		return $this->rpc_call( 'findActivities', $query );
	}

	/**
	 * Find leads with optional query filters.
	 *
	 * @since 2.9.0 (KPI metrics)
	 * @param array $query Query parameters (status, limit, orderBy, etc.)
	 * @return array|null Array of lead stubs or null on error.
	 */
	public function find_leads( array $query = [] ) {
		return $this->rpc_call( 'findLeads', $query );
	}

	/**
	 * Get a single lead by ID.
	 *
	 * @since 2.9.0
	 * @param int $lead_id Nutshell lead ID.
	 * @param string $rev Lead revision ('REV_NEWEST' typically).
	 * @return array|null Lead data or null on error.
	 */
	public function get_lead( int $lead_id, string $rev = '0' ) {
		return $this->rpc_call( 'getLead', [ 'leadId' => $lead_id, 'rev' => $rev ] );
	}

	/**
	 * Close a lead by setting its OUTCOME (never its status) and verify by re-reading.
	 *
	 * Nutshell refuses a bare `editLead {status}` with an HTTP 400 ("Lead status can
	 * only be set to 0 (for reopening)") on a lead in ANY state — it rejects the *shape*
	 * of the write, not a state fact. The correct close is to set an `outcomeId`; the
	 * outcome's TYPE then drives the resulting status. Because a write can silently
	 * fail, the ONLY basis for "closed" here is a re-read: status != 0 (0 = OPEN).
	 *
	 * Shared by surveys and jobs. When $note is given it is written BEFORE the verifying
	 * re-read, as a best-effort attempt record; a caller that must gate its note on the
	 * verified result should pass '' and add the note itself once `closed` is true.
	 *
	 * @param int    $crm_lead_id Nutshell lead id.
	 * @param int    $outcome_id  The outcome to set (see resolve_won_outcome()).
	 * @param string $note        Optional CRM note body written on the close attempt.
	 * @return array{closed:bool,verified:bool,status:int}
	 */
	public function close_lead_by_outcome( int $crm_lead_id, int $outcome_id, string $note = '' ): array {
		$out = [ 'closed' => false, 'verified' => false, 'status' => 0 ];
		if ( $crm_lead_id < 1 || $outcome_id < 1 ) {
			return $out;
		}

		// The CRM wants the current rev on an edit.
		$rev = $this->lead_rev( $crm_lead_id );
		$this->rpc_call( 'editLead', [
			'leadId' => $crm_lead_id,
			'rev'    => $rev,
			'lead'   => [ 'outcomeId' => $outcome_id ], // outcomeId ONLY — never status.
		] );

		if ( '' !== $note ) {
			$this->add_note( [
				'entity' => [ 'entityType' => 'leads', 'id' => $crm_lead_id ],
				'note'   => [ 'body' => $note ],
			] );
		}

		// VERIFY: re-read the lead. status != 0 (0 = OPEN) is the only basis for closed.
		$status          = $this->lead_status_int( $crm_lead_id );
		$out['verified'] = ( null !== $status );
		$out['status']   = (int) $status;
		$out['closed']   = ( null !== $status && 0 !== (int) $status );
		return $out;
	}

	/**
	 * Resolve the CRM outcome that means "closed as a sale / satisfied", or null (refuse).
	 *
	 * Deliberately conservative — NO "any outcome" fallback (unlike a lost/cancelled
	 * close, where any non-won outcome is acceptable): filing a satisfied customer under
	 * an arbitrary outcome could mark them Lost, and a lead left open is recoverable
	 * while a wrong disposition is not. Resolution order:
	 *   1. a pinned name via the `zsv_won_outcome_name` / `zdz_won_outcome_name` filter
	 *      (Identity mapping — Core ships neither);
	 *   2. an outcome whose TYPE is 'won';
	 *   3. an outcome whose label reads like a sale (won|sold|complete|satisfied) AND
	 *      NOT like a loss (lost|cancel|no sale|dead) — the negative guard rejects a
	 *      label such as "Complete - lost to competitor";
	 *   4. nothing matched → REFUSE: log the account's actual outcomes and return null so
	 *      the caller leaves the lead OPEN.
	 *
	 * The resolution AND its negative are transient-cached per account so a misconfigured
	 * account does not re-list outcomes for every lead inside a budgeted sweep.
	 *
	 * @return array{id:int,name:string}|null
	 */
	public function resolve_won_outcome(): ?array {
		$prefer = (string) apply_filters( 'zdz_won_outcome_name', '' );
		$prefer = (string) apply_filters( 'zsv_won_outcome_name', $prefer );
		$prefer = strtolower( trim( $prefer ) );

		$cache_key = 'zdz_ns_won_outcome_' . md5( ( $this->email ?? '' ) . '|' . $prefer );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			// A cached negative is stored as ['none'=>true]; a hit as ['id'=>..,'name'=>..].
			return empty( $cached['none'] ) ? [ 'id' => (int) $cached['id'], 'name' => (string) $cached['name'] ] : null;
		}

		$list = $this->rpc_call( 'findLead_Outcomes', [] );
		if ( ! is_array( $list ) || empty( $list ) ) {
			$list = $this->rpc_call( 'findLead_Outcomes', [ 'limit' => 250 ] );
		}

		$pinned  = null;
		$typed   = null;
		$labeled = null;
		$seen    = [];

		if ( is_array( $list ) ) {
			foreach ( $list as $o ) {
				if ( ! is_array( $o ) ) {
					continue;
				}
				$id = (int) ( $o['id'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$label    = (string) ( $o['description'] ?? ( $o['name'] ?? '' ) );
				$type_raw = $o['type'] ?? '';
				if ( is_array( $type_raw ) ) {
					$type_raw = $type_raw['name'] ?? ( $type_raw['id'] ?? '' );
				}
				$type   = strtolower( trim( (string) $type_raw ) );
				$low    = strtolower( $label );
				$seen[] = [ 'id' => $id, 'label' => $label, 'type' => $type ];

				// 1. pinned name (exact, then contains).
				if ( null === $pinned && '' !== $prefer && ( $low === $prefer || false !== strpos( $low, $prefer ) ) ) {
					$pinned = [ 'id' => $id, 'name' => $label ];
				}
				// 2. type: won.
				if ( null === $typed && 'won' === $type ) {
					$typed = [ 'id' => $id, 'name' => $label ];
				}
				// 3. label reads like a sale AND NOT like a loss (the negative guard).
				if ( null === $labeled
					&& preg_match( '/\b(won|sold|complete|satisfied)\b/i', $label )
					&& ! preg_match( '/\b(lost|cancel|no\s*sale|dead)\b/i', $label ) ) {
					$labeled = [ 'id' => $id, 'name' => $label ];
				}
			}
		}

		$resolved = $pinned ?? $typed ?? $labeled;

		if ( null === $resolved ) {
			// REFUSE — leave the lead open. Log the account's actual outcomes so an
			// operator can pin one (outcome labels are CRM config, not customer PII).
			error_log( 'ZDZ_Core_Nutshell::resolve_won_outcome refused — no won-type outcome. Account outcomes: ' . wp_json_encode( $seen ) );
			set_transient( $cache_key, [ 'none' => true ], 10 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $resolved, 10 * MINUTE_IN_SECONDS );
		return $resolved;
	}

	/** Read a lead's numeric status; 0 = OPEN, non-zero = a closed disposition; null if unreadable. */
	private function lead_status_int( int $lead_id ): ?int {
		$lead = $this->get_lead( $lead_id, 'REV_NEWEST' );
		if ( ! is_array( $lead ) || ! isset( $lead['status'] ) ) {
			return null;
		}
		if ( is_numeric( $lead['status'] ) ) {
			return (int) $lead['status'];
		}
		if ( is_array( $lead['status'] ) && isset( $lead['status']['id'] ) ) {
			return (int) $lead['status']['id'];
		}
		return null;
	}

	/** Pull a fresh lead rev string (the CRM wants the current rev on edit). */
	private function lead_rev( int $lead_id ): string {
		$lead = $this->get_lead( $lead_id, 'REV_NEWEST' );
		if ( is_array( $lead ) && isset( $lead['rev'] ) ) {
			return (string) $lead['rev'];
		}
		return 'REV_NEWEST';
	}

	/**
	 * Check whether Nutshell credentials are configured.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->email ) && ! empty( $this->api_key );
	}
}