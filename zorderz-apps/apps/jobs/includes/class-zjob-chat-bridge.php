<?php
/**
 * Zorderz Jobs — orchestrator/chat bridge: the job-handoff verb.
 *
 * Lets an authorized person say, in chat: "hand the awning component on estimate
 * #1234 to Sam". The assistant emits the ZJOB_MARKER token; the chat engine parses it
 * server-side, injects the TRUE caller identity, and calls handoff_from_chat(). The
 * assistant never touches the DB or the CRM directly.
 *
 * MARKER: ZJOB_MARKER = '[ZDZ_JOB_HANDOFF]' (defined in app.php). This replaces the
 * legacy '[TS_HANDOFF]' token; the token lives in ONE constant, referenced here and
 * exposed to the engine via the `zdz_job_markers` filter — never typed as a literal.
 *
 * CONTRACT:
 *   - SIDE-EFFECTING -> preview-then-confirm (two turns). Turn 1 writes nothing.
 *   - kiosk-forbidden, resolved server-side (most-restrictive-wins).
 *   - requires the job-handoff data permission (server-authoritative).
 *   - NEVER writes to billing (the customer's estimate/invoice is untouched; the
 *     split lives only in the app + a CRM child lead).
 *
 * Reuses the same core the widget uses (ZJOB_Jobs::create + the ZJOB_CRM seam), so a
 * chat handoff and a tapped handoff are identical records.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Chat_Bridge {

	/** Source tag for the audit trail. */
	const SOURCE = 'jobs';

	/* =======================================================================
	 * AVAILABILITY
	 * ======================================================================= */

	/** Usable for the orchestrator when the jobs table exists. */
	public static function is_available(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
			return false;
		}
		$table = ZJOB_DB::table();
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}

	/* =======================================================================
	 * THE VERB — handoff_from_chat()  (WRITE, preview->confirm)
	 * ======================================================================= */

	/**
	 * Hand a job component to a specialist as a tracked sub-job (+ CRM child lead).
	 *
	 * Turn 1 (confirmed=false): returns a preview (requires_confirm=true), writes nothing.
	 * Turn 2 (confirmed=true) : app record + CRM child lead (fail-open on the CRM).
	 *
	 * @param array $payload
	 * @return array
	 */
	public static function handoff_from_chat( array $payload ): array {
		$ctx = self::resolve_context( $payload );
		$out = self::base( $ctx );

		// Never on the shared kiosk.
		if ( ! empty( $ctx['is_kiosk'] ) ) {
			return array_merge( $out, array(
				'error'   => 'forbidden_on_kiosk',
				'message' => 'Handing off jobs is not allowed on the shared device.',
			) );
		}

		// Must hold the job-handoff data permission.
		if ( ! class_exists( 'ZJOB_Jobs' )
			|| ! ZJOB_Jobs::user_can_hand_off( (int) $ctx['user_id'] ) ) {
			return array_merge( $out, array(
				'error'   => 'forbidden',
				'message' => 'You do not have permission to hand off jobs.',
			) );
		}

		// Resolve the specialist (assignee) -> a WP user id.
		$assignee_raw = trim( (string) ( $payload['assignee'] ?? '' ) );
		if ( $assignee_raw === '' ) {
			return self::clarify( $ctx, 'Who should I hand this off to?' );
		}
		$assignee_id = self::resolve_assignee_to_wp_user( $assignee_raw, (int) $ctx['user_id'] );
		if ( $assignee_id <= 0 ) {
			return self::clarify( $ctx, sprintf(
				'I could not find an app user for "%s". Give me the exact name, or set their code / initials on their profile.',
				$assignee_raw
			) );
		}
		$assignee_user = get_userdata( $assignee_id );
		$assignee_name = $assignee_user ? $assignee_user->display_name : $assignee_raw;

		$component = sanitize_key( (string) ( $payload['component'] ?? '' ) );
		if ( $component === '' ) {
			$component = function_exists( 'zjob_default_component' ) ? zjob_default_component() : 'other';
		}
		$comp_label     = function_exists( 'zjob_component_label' ) ? zjob_component_label( $component ) : $component;
		$customer_name  = sanitize_text_field( (string) ( $payload['customer_name'] ?? '' ) );
		$source_ref     = sanitize_text_field( (string) ( $payload['source_ref'] ?? '' ) );
		$parent_lead_id = (int) ( $payload['parent_lead_id'] ?? 0 );
		$contact_id     = (int) ( $payload['crm_contact_id'] ?? ( $payload['nutshell_contact_id'] ?? 0 ) );
		$brand          = sanitize_text_field( (string) ( $payload['brand'] ?? '' ) );
		$qty            = max( 0, (int) ( $payload['qty'] ?? 0 ) );
		$notes          = sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) );

		$label = $customer_name !== '' ? $customer_name : ( $source_ref !== '' ? $source_ref : 'this job' );

		// TURN 1 — preview only, write nothing.
		$confirmed = ! empty( $payload['confirmed'] );
		if ( ! $confirmed ) {
			return array_merge( $out, array(
				'success'          => true,
				'requires_confirm' => true,
				'action'           => 'handoff_component',
				'assignee'         => array( 'wp_user_id' => $assignee_id, 'name' => $assignee_name ),
				'component'        => $component,
				'customer_name'    => $customer_name,
				'source_ref'       => $source_ref,
				'message'          => sprintf(
					'Ready to hand the %s%s for %s to %s. This stays internal - it will NOT appear on the customer\'s billing document. Reply "yes" to confirm.',
					$comp_label,
					$qty > 0 ? ' (' . $qty . ')' : '',
					$label,
					$assignee_name
				),
			) );
		}

		// TURN 2 — create the app record, then mirror to the CRM (fail-open).
		$data = array(
			'component'        => $component,
			'customer_name'    => $customer_name,
			'source_ref'       => $source_ref,
			'parent_lead_id'   => $parent_lead_id,
			'crm_contact_id'   => $contact_id,
			'assigned_user_id' => $assignee_id,
			'brand'            => $brand,
			'qty'              => $qty,
			'notes'            => $notes,
		);
		$id = ZJOB_Jobs::create( $data, (int) $ctx['user_id'] );
		if ( $id <= 0 ) {
			return array_merge( $out, array(
				'error'   => 'create_failed',
				'message' => 'I could not record the handoff.',
			) );
		}

		$crm_ok        = false;
		$child_lead_id = 0;
		if ( class_exists( 'ZJOB_CRM' ) ) {
			$row = ZJOB_Jobs::get( $id );
			if ( $row ) {
				$crm = ZJOB_CRM::provider()->create_child_lead( $row );
				$crm_ok = ! empty( $crm['ok'] );
				$child_lead_id = (int) ( $crm['child_lead_id'] ?? 0 );
				if ( $child_lead_id > 0 ) {
					ZJOB_Jobs::attach_child_lead( $id, $child_lead_id );
				}
			}
		}

		return array_merge( $out, array(
			'success'       => true,
			'action'        => 'handoff_component',
			'job_id'        => $id,
			'assignee'      => array( 'wp_user_id' => $assignee_id, 'name' => $assignee_name ),
			'component'     => $component,
			'child_lead_id' => $child_lead_id,
			'message'       => sprintf(
				'Handed the %s for %s to %s.%s Not billed on the customer\'s document.',
				$comp_label,
				$label,
				$assignee_name,
				$crm_ok
					? ( $child_lead_id > 0 ? ' CRM lead #' . $child_lead_id . ' created and assigned.' : ' Mirrored to the CRM.' )
					: ' (Recorded in the app; CRM sync incomplete - assign in the CRM if needed.)'
			),
		) );
	}

	/* =======================================================================
	 * HELPERS (self-contained envelope, same SHAPE as the platform contract)
	 * ======================================================================= */

	/**
	 * Resolve engine-authoritative context. The engine injects user_id/is_kiosk; we
	 * re-derive kiosk from the role most-restrictive-wins so a forged hint can never
	 * lift the kiosk block.
	 */
	private static function resolve_context( array $payload ): array {
		$uid = (int) ( $payload['user_id'] ?? 0 );
		if ( $uid <= 0 && function_exists( 'get_current_user_id' ) ) {
			$uid = (int) get_current_user_id();
		}
		$is_kiosk = ! empty( $payload['is_kiosk'] );
		if ( class_exists( 'ZDZ_Hierarchy' ) && $uid > 0 && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			$is_kiosk = true;
		}
		return array( 'user_id' => $uid, 'is_kiosk' => $is_kiosk );
	}

	/** The base response envelope every return merges onto. */
	private static function base( array $ctx ): array {
		return array(
			'success' => false,
			'error'   => '',
			'source'  => self::SOURCE,
			'kiosk'   => ! empty( $ctx['is_kiosk'] ),
		);
	}

	/** A "needs clarification" return — never a silent no-op. */
	private static function clarify( array $ctx, string $message ): array {
		return array_merge( self::base( $ctx ), array(
			'success'       => true,
			'needs_clarify' => true,
			'message'       => $message,
		) );
	}

	/**
	 * Resolve a free-text specialist (a name, a code, or "me") to a WP user id.
	 *
	 * Party binding: the authoritative roster is ZDZ_Party::selectable_people(). A
	 * `zdz_resolve_party_code` filter lets the Party service (or a site) supply a
	 * hardened code resolver. Matching is EXACT (never fuzzy — the identity safety
	 * floor): exact code (case-sensitive), then exact initials, then exact display
	 * name, then a unique first-name match. Returns 0 when unsure (never guesses).
	 */
	private static function resolve_assignee_to_wp_user( string $assignee, int $actor_id ): int {
		$assignee = trim( $assignee );
		if ( $assignee === '' ) {
			return 0;
		}
		if ( in_array( strtolower( $assignee ), array( 'me', 'myself' ), true ) ) {
			return $actor_id;
		}
		// Party-service (or site) code resolver, when present.
		$pre = (int) apply_filters( 'zdz_resolve_party_code', 0, $assignee );
		if ( $pre > 0 ) {
			return $pre;
		}

		// The authoritative roster.
		$people = ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) )
			? ZDZ_Party::selectable_people( array( 'include_self' => true ) )
			: array();

		if ( empty( $people ) ) {
			// Fallback: exact/first-name match over app users.
			return self::resolve_over_users( $assignee );
		}

		// (a) exact salesperson code (case-sensitive), unique.
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_salesperson_code' ) ) {
			$codes = array();
			foreach ( $people as $p ) {
				$code = (string) ZDZ_Core_Settings::get_salesperson_code( (int) $p['id'] );
				if ( $code !== '' && $code === $assignee ) { // exact, case-sensitive
					$codes[] = (int) $p['id'];
				}
			}
			if ( count( $codes ) === 1 ) {
				return $codes[0];
			}
		}
		// (b) exact initials (case-insensitive), unique.
		$ini = array();
		foreach ( $people as $p ) {
			if ( ! empty( $p['initials'] ) && strcasecmp( (string) $p['initials'], $assignee ) === 0 ) {
				$ini[] = (int) $p['id'];
			}
		}
		if ( count( $ini ) === 1 ) {
			return $ini[0];
		}
		// (c) exact display name (case-insensitive), unique.
		$names = array();
		foreach ( $people as $p ) {
			if ( strcasecmp( trim( (string) $p['name'] ), $assignee ) === 0 ) {
				$names[] = (int) $p['id'];
			}
		}
		if ( count( $names ) === 1 ) {
			return $names[0];
		}
		// (d) unique first-name match.
		$first = array();
		foreach ( $people as $p ) {
			$fn = strtok( trim( (string) $p['name'] ), ' ' );
			if ( $fn !== false && strcasecmp( $fn, $assignee ) === 0 ) {
				$first[] = (int) $p['id'];
			}
		}
		if ( count( $first ) === 1 ) {
			return $first[0];
		}
		return 0; // ambiguous / not found -> caller asks
	}

	/** Fallback resolver over app users (exact display name, then unique first name). */
	private static function resolve_over_users( string $assignee ): int {
		$users = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 500 ) );
		$names = array();
		foreach ( $users as $u ) {
			if ( strcasecmp( trim( (string) $u->display_name ), $assignee ) === 0 ) {
				$names[] = (int) $u->ID;
			}
		}
		if ( count( $names ) === 1 ) {
			return $names[0];
		}
		$first = array();
		foreach ( $users as $u ) {
			$fn = strtok( trim( (string) $u->display_name ), ' ' );
			if ( $fn !== false && strcasecmp( $fn, $assignee ) === 0 ) {
				$first[] = (int) $u->ID;
			}
		}
		return count( $first ) === 1 ? $first[0] : 0;
	}

	/**
	 * Capability descriptor for the orchestrator registry. HIGH-trust,
	 * side-effecting, kiosk-forbidden.
	 */
	public static function get_capability_descriptor(): array {
		return array(
			'verb'        => 'jobs.handoff',
			'source'      => self::SOURCE,
			'marker'      => defined( 'ZJOB_MARKER' ) ? ZJOB_MARKER : '[ZDZ_JOB_HANDOFF]',
			'min_tier'    => 'staff',
			'kiosk'       => false,
			'side_effect' => true,
			'confirm'     => true,
			'summary'     => 'Hand a job component to a specialist as a tracked sub-job (app + CRM child lead; never billing).',
		);
	}
}
