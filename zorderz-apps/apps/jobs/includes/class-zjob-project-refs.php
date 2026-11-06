<?php
/**
 * ZJOB_Project_Refs — what a Project points at, by namespace: the published lookup other apps use.
 *
 * A Project holds references to the records it is made of (its estimate, invoice, appointment, CRM lead, receipt,
 * survey, conversation, a logged repair), each keyed by (namespace, external id). This is a thin, stable face over the
 * Flow ref map (Zdz_Flow_Refs) so another app never needs to know the Flow schema:
 *
 *   put( $project_id, $ns, $ref_id, $args )  record that the Project owns $ref_id in $ns. True when stored or
 *                                            already there; false when refused (unknown namespace, an empty or '0'
 *                                            id, another Project already owning it). Never throws.
 *   get( $project_id, $ns )                  the Project's first id in $ns (the one recorded first), or ''.
 *   all( $project_id, $ns )                  every id the Project holds in $ns, in the order recorded.
 *   project_for( $ns, $ref_id )              which Project owns $ref_id in $ns, or ''. A bare reference lookup with
 *                                            no notion of a viewer: every caller gates what it then shows.
 *   for_project( $project_id )               every reference the Project holds, grouped by namespace; each row
 *                                            carries ref_id (the external id).
 *
 * Namespaces are the registered Flow systems (Zjob_Project::NAMESPACES — estimate, invoice, appointment, cut, receipt,
 * survey, conversation, lead, job, repair — plus any a connections pack adds through `zdz_work_item_ref_namespaces`).
 * An estimate reference holds the estimate app's own id, never a bare document number: estimate #12 and invoice #12
 * are different records. A Project may hold many ids in one namespace (two estimates; a deposit and a balance
 * invoice); putting another adds it beside the ones it has. One record still belongs to one Project only.
 *
 * Project ids are the Flow work-item ids (strings).
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Project_Refs {

	/** How sure are we that this reference belongs to this Project? (kept on the ref's sync_state) */
	const CONFIDENCE = array( 'confirmed', 'probable', 'suggested' );

	/** The registered entity for a namespace, or '' when the namespace is unknown. */
	public static function entity( string $ns ): string {
		$ns = sanitize_key( $ns );
		if ( '' === $ns ) {
			return '';
		}
		if ( class_exists( 'Zjob_Appointment_Link' ) && is_callable( array( 'Zjob_Appointment_Link', 'entity_for_system' ) ) ) {
			$e = (string) Zjob_Appointment_Link::entity_for_system( $ns );
			if ( '' !== $e ) {
				return $e;
			}
		}
		return class_exists( 'Zjob_Project' ) ? (string) Zjob_Project::entity_for( $ns ) : '';
	}

	public static function put( string $project_id, string $ns, string $ref_id, array $args = array() ): bool {
		$ref_id = trim( $ref_id );
		// '0' is not an identity, and neither is ''.
		if ( '' === $project_id || '' === $ref_id || '0' === $ref_id || ! class_exists( 'Zdz_Flow_Refs' ) ) {
			return false;
		}
		$entity = self::entity( $ns );
		if ( '' === $entity ) {
			error_log( 'Zorderz Jobs: refused an unknown project reference namespace.' );
			return false;
		}
		$confidence = (string) ( $args['confidence'] ?? 'confirmed' );
		$confidence = in_array( $confidence, self::CONFIDENCE, true ) ? $confidence : 'confirmed';
		try {
			return (bool) Zdz_Flow_Refs::put( $project_id, sanitize_key( $ns ), $entity, $ref_id, array( 'sync_state' => $confidence ) );
		} catch ( \Throwable $e ) {
			// Another Project owns it, or the id is not a work item: refused, never thrown at the caller.
			return false;
		}
	}

	public static function get( string $project_id, string $ns ): string {
		$entity = self::entity( $ns );
		if ( '' === $project_id || '' === $entity || ! class_exists( 'Zdz_Flow_Refs' ) ) {
			return '';
		}
		foreach ( Zdz_Flow_Refs::for( $project_id, sanitize_key( $ns ), $entity ) as $r ) {
			$id = (string) ( $r['external_id'] ?? '' );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/** @return string[] every id the Project holds in $ns, in the order recorded. */
	public static function all( string $project_id, string $ns ): array {
		$entity = self::entity( $ns );
		if ( '' === $project_id || '' === $entity || ! class_exists( 'Zdz_Flow_Refs' ) ) {
			return array();
		}
		$out = array();
		foreach ( Zdz_Flow_Refs::for( $project_id, sanitize_key( $ns ), $entity ) as $r ) {
			$id = (string) ( $r['external_id'] ?? '' );
			if ( '' !== $id && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function project_for( string $ns, string $ref_id ): string {
		$ref_id = trim( $ref_id );
		$entity = self::entity( $ns );
		if ( '' === $ref_id || '' === $entity || ! class_exists( 'Zdz_Flow_Refs' ) ) {
			return '';
		}
		$pid = Zdz_Flow_Refs::get( sanitize_key( $ns ), $entity, $ref_id );
		if ( null === $pid ) {
			return '';
		}
		// A ref owned by some other kind of work item is not a Project.
		if ( class_exists( 'Zjob_Project' ) && null === Zjob_Project::get_raw( (string) $pid ) ) {
			return '';
		}
		return (string) $pid;
	}

	/** @return array<string, array<int, array>> ns => rows (each with ref_id). */
	public static function for_project( string $project_id ): array {
		if ( '' === $project_id || ! class_exists( 'Zdz_Flow_Refs' ) ) {
			return array();
		}
		$out = array();
		foreach ( Zdz_Flow_Refs::for( $project_id ) as $r ) {
			$ns = (string) ( $r['system'] ?? '' );
			if ( '' === $ns ) {
				continue;
			}
			$r['ns']      = $ns;
			$r['ref_id']  = (string) ( $r['external_id'] ?? '' );
			$out[ $ns ][] = $r;
		}
		return $out;
	}
}
