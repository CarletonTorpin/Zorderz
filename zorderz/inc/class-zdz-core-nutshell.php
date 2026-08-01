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
	 * Check whether Nutshell credentials are configured.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->email ) && ! empty( $this->api_key );
	}
}