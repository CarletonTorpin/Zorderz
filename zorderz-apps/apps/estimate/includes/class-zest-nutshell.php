<?php
/**
 * ZEST_Nutshell — CRM helpers over the theme's shared ZDZ_Core_Nutshell client.
 *
 * The module holds no CRM credentials and pins no endpoint — the theme's Connections
 * layer owns the account, key and host discovery. This class adds estimate-shaped CRM
 * work: creating a lead/stub for an estimate and writing the billing document number
 * back to a custom field. Every external NAME (pipeline, milestone, custom field) is
 * resolved to an id through the Mappings layer via a filter, not matched by literal
 * string — so a CRM rename is a no-op instead of a silent break (crosswalk C1–C7). The
 * platform never CREATES a pipeline/stage/field; an unresolved mapping is logged, never
 * guessed.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Nutshell {

	/** The shared CRM client, or null when not configured. */
	public static function client() {
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
	 * Write the billing document number to the mapped custom field on a lead. OUTBOUND
	 * only — the platform reads its own record, never a CRM note body, back (crosswalk
	 * C9 forbids parsing note bodies as a protocol).
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
