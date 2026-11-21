<?php
/**
 * ZEST_Nutshell — CRM helpers over the theme's shared ZDZ_Core_Nutshell client.
 *
 * The module holds no CRM credentials and pins no endpoint — the theme's Connections
 * layer owns the account, key and host discovery. This class adds estimate-shaped CRM
 * work: creating a lead/stub for an estimate and writing the billing document number
 * back to a custom field. Every external NAME (pipeline, milestone, custom field) is
 * resolved to an id through the Mappings layer via a filter, not matched by literal
 * string — so a CRM rename is a no-op instead of a silent break. The
 * platform never CREATES a pipeline/stage/field; an unresolved mapping is logged, never
 * guessed.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Nutshell {

	/** @var object|null tests: the CRM client to use (null asks the platform's) */
	public static $test_client = null;

	/** The shared CRM client, or null when not configured. */
	public static function client() {
		if ( is_object( self::$test_client ) ) {
			return self::$test_client;
		}
		if ( ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return null;
		}
		$c = new ZDZ_Core_Nutshell();
		return $c->is_configured() ? $c : null;
	}

	public static function is_configured(): bool {
		return null !== self::client();
	}

	/**
	 * Resolve an internal mapping id to the external CRM id. Reads the Mappings layer
	 * (crm.pipelines / crm.milestones / crm.custom_fields …) through a filter. Returns
	 * '' when unresolved, and logs it — a bind-by-name never happens here.
	 *
	 * @param string $kind One of pipelines|milestones|tags|outcomes|custom_fields.
	 * @param string $id   Internal mapping id.
	 */
	public static function external_id( string $kind, string $id ): string {
		$map = (array) apply_filters( 'zdz_crm_mappings', array() );
		$ext = $map[ $kind ][ $id ]['external_id'] ?? '';
		if ( '' === (string) $ext ) {
			error_log( sprintf( 'Zorderz Estimates: CRM mapping %s.%s is unresolved — skipping (resolve it in the connection wizard).', $kind, $id ) );
		}
		return (string) $ext;
	}

	/**
	 * Create a CRM lead for an estimate. Binds the pipeline by mapping id (never a
	 * literal pipeline name). Failure-tolerant: a CRM outage never blocks the billing
	 * estimate — it returns an error the caller logs as a disposition.
	 *
	 * @param array $lead { contact:array, pipeline_id:string, reference:string, note:string }
	 * @return array{ ok:bool, lead_id:string, error:string }
	 */
	public static function create_lead( array $lead ): array {
		$out    = array( 'ok' => false, 'lead_id' => '', 'error' => '' );
		$client = self::client();
		if ( ! $client ) {
			$out['error'] = 'CRM not configured.';
			return $out;
		}
		$pipeline_ext = '';
		if ( ! empty( $lead['pipeline_id'] ) ) {
			$pipeline_ext = self::external_id( 'pipelines', (string) $lead['pipeline_id'] );
		}
		$payload = array(
			'contact'   => (array) ( $lead['contact'] ?? array() ),
			'reference' => (string) ( $lead['reference'] ?? '' ),
			'note'      => (string) ( $lead['note'] ?? '' ),
		);
		if ( '' !== $pipeline_ext ) {
			$payload['stagesetId'] = $pipeline_ext;
		}
		try {
			$resp = $client->create_lead( $payload );
		} catch ( \Throwable $e ) {
			$out['error'] = 'CRM lead create failed: ' . $e->getMessage();
			return $out;
		}
		$lead_id = $resp['result']['id'] ?? ( $resp['lead_id'] ?? '' );
		if ( ! $lead_id ) {
			$out['error'] = 'CRM lead create returned no id.';
			return $out;
		}
		$out['ok']      = true;
		$out['lead_id'] = (string) $lead_id;
		return $out;
	}

	/**
	 * The CRM lead that is positively this estimate's, by the rule "Add measurements" uses
	 * (ZEST_Added_Measurements::lead_matches()): its id. 0 when there is no CRM, no lead or number on the estimate, or
	 * the lead does not name this estimate (nothing is ever written to a lead that is not shown to be this
	 * estimate's). -1 when the lead could not be read just now, so nothing is known either way.
	 */
	public static function estimate_lead_id( array $row ): int {
		$lead_id = (int) preg_replace( '/\D/', '', (string) ( $row['crm_lead_id'] ?? '' ) );
		$number  = ltrim( (string) ( $row['billing_doc_num'] ?? '' ), '#' );
		if ( $lead_id <= 0 || '' === $number || ! class_exists( 'ZEST_Added_Measurements' ) ) {
			return 0;
		}
		$client = self::client();
		if ( ! $client || ! method_exists( $client, 'read_lead' ) ) {
			return 0;
		}
		try {
			$lead = $client->read_lead( $lead_id );
		} catch ( \Throwable $e ) {
			return -1;
		}
		if ( ! is_array( $lead ) || ! $lead ) {
			return -1;
		}
		$match = ZEST_Added_Measurements::lead_matches( $lead, $number, (string) ( $row['crm_contact_id'] ?? '' ), ZEST_Added_Measurements::estimate_fields() );
		return empty( $match['ok'] ) ? 0 : $lead_id;
	}

	/**
	 * One note on a lead. Gate codes are cut from the words and the platform's outbound cleaner runs over them
	 * first. False when the CRM did not take it (the caller keeps the words and tries again).
	 */
	public static function add_lead_note( int $lead_id, string $text ): bool {
		$client = self::client();
		if ( ! $client || $lead_id <= 0 || '' === trim( $text ) || ! method_exists( $client, 'add_note' ) ) {
			return false;
		}
		$text = class_exists( 'ZEST_Sealed' ) ? ZEST_Sealed::cut( $text ) : $text;
		$text = ( class_exists( 'ZDZ_Answer_Authority' ) && method_exists( 'ZDZ_Answer_Authority', 'sanitize_outbound' ) ) ? (string) ZDZ_Answer_Authority::sanitize_outbound( $text ) : $text;
		try {
			$res = $client->add_note( array( 'entity' => array( 'entityType' => 'Leads', 'id' => $lead_id ), 'note' => $text ) );
		} catch ( \Throwable $e ) {
			return false;
		}
		return null !== $res && false !== $res;
	}

	/**
	 * Write the billing document number to the mapped custom field on a lead. OUTBOUND
	 * only — the platform reads its own record, never a CRM note body, back (a note body is
	 * never parsed as a protocol).
	 */
	public static function set_billing_doc_number( string $lead_id, string $number ): bool {
		$client = self::client();
		if ( ! $client || '' === $lead_id ) {
			return false;
		}
		$field_ext = self::external_id( 'custom_fields', 'billing_document_number' );
		if ( '' === $field_ext ) {
			return false; // unresolved mapping — logged in external_id()
		}
		try {
			if ( method_exists( $client, 'add_note' ) ) {
				// Record via the client's own write path; the concrete field write is
				// provider-specific and delegated to the core client's rpc.
				return (bool) $client->rpc_call( 'editLead', array(
					'leadId'  => $lead_id,
					'lead'    => array( 'customFields' => array( $field_ext => $number ) ),
				) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: set_billing_doc_number failed (non-fatal): ' . $e->getMessage() );
		}
		return false;
	}
}
