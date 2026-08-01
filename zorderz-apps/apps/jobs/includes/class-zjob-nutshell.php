<?php
/**
 * Zorderz Jobs — Nutshell CRM adapter (the default ZJOB_CRM_Provider backend).
 *
 * Creates a SEPARATE CHILD LEAD for the specialist, owned by them, linked back to
 * the parent customer lead, optionally moved into a pipeline stage (bound by the
 * `zdz_job_child_stage_name` filter — NO pipeline name is hardcoded), with notes on
 * both leads. Every step is independently failure-tolerant: any single call may fail
 * without losing the app-side record (the app owns assignment; the CRM is the
 * mirror). This class writes ONLY to the CRM; it NEVER touches the customer's
 * billing document (the internal work split must not appear on the invoice).
 *
 * All wire calls go through the theme's shared ZDZ_Core_Nutshell client (one
 * credential authority — no private key copies here). This is a concrete adapter;
 * the provider-agnostic seam is ZJOB_CRM / ZJOB_CRM_Provider.
 *
 * FUTURE (Flow service): the customer-facing/internal split of these notes is a
 * `visibility` concern on the Flow event envelope. Until Flow lands, parent-lead
 * notes are kept deliberately neutral and internal-labelled.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Nutshell {

	/**
	 * A child lead is INTERNAL WORK, never a sale, so it is closed via a NOT-WON
	 * outcome. The tag makes it filterable out of sales reports; the outcome name is
	 * the (cancelled-type) reason attached IF the account defines it. Both are neutral
	 * defaults and filterable. CRM lead status ints: 0 open, 1 won, 2 lost, 3 cancelled.
	 */
	const INTERNAL_TAG          = 'Internal Job';
	const INTERNAL_OUTCOME_NAME = 'Internal-complete';
	const NS_STATUS_CANCELLED   = 3;

	/** Is the shared CRM client available and configured? */
	public static function available(): bool {
		return class_exists( 'ZDZ_Core_Nutshell' ) && ( new ZDZ_Core_Nutshell() )->is_configured();
	}

	/**
	 * The internal-work tag (filterable). Empty disables tagging.
	 */
	private static function internal_tag(): string {
		return (string) apply_filters( 'zdz_job_internal_tag', self::INTERNAL_TAG );
	}

	/**
	 * The pipeline/stage a component's child lead should enter.
	 *
	 * NO pipeline name is hardcoded (those are tenant/business data). The mapping is
	 * supplied entirely by the `zdz_job_child_stage_name` filter — a site (or the
	 * future Item Engine / Flow service) maps a component to a pipeline or stage name.
	 * The neutral default is '' (empty), which means "do not move the stage": the
	 * child lead is still created, assigned and noted.
	 */
	private static function pipeline_for_component( string $component ): string {
		/** Filterable: (string $pipeline_or_stage_name = '', string $component). */
		return (string) apply_filters( 'zdz_job_child_stage_name', '', $component );
	}

	/**
	 * Create the specialist's child lead for a job.
	 *
	 * @param array $job A wp_zdz_jobs row (assoc).
	 * @return array{ok:bool,child_lead_id:int,steps:array<string,bool>,error:string}
	 */
	public static function create_child_lead( array $job ): array {
		$result = [ 'ok' => false, 'child_lead_id' => 0, 'steps' => [], 'error' => '' ];

		if ( ! self::available() ) {
			$result['error'] = 'crm_unconfigured';
			return $result;
		}
		$ns = new ZDZ_Core_Nutshell();

		$assignee_id = (int) ( $job['assigned_user_id'] ?? 0 );
		$contact_id  = (int) ( $job['crm_contact_id'] ?? 0 );
		$parent_lead = (int) ( $job['parent_lead_id'] ?? 0 );
		$component   = (string) ( $job['component'] ?? '' );
		$brand       = trim( (string) ( $job['brand'] ?? '' ) );
		$qty         = (int) ( $job['qty'] ?? 0 );
		$notes       = trim( (string) ( $job['notes'] ?? '' ) );
		$customer    = trim( (string) ( $job['customer_name'] ?? '' ) );
		$comp_label  = function_exists( 'zjob_component_label' ) ? zjob_component_label( $component ) : ucfirst( $component );

		if ( $contact_id <= 0 && $parent_lead > 0 ) {
			$contact_id = self::contact_id_from_lead( $ns, $parent_lead );
		}

		// 1) A CONCISE lead description. newLead HARD-REJECTS a description over 100
		//    characters, so keep it short; ALL detail goes in the note at step 4.
		$desc = $comp_label;
		if ( $customer !== '' ) { $desc .= ' - ' . $customer; }
		if ( $qty > 1 )         { $desc .= ' x' . $qty; }
		$desc .= ' [handoff]';
		$description = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 100 ) : substr( $desc, 0, 100 );

		// 2) newLead — a separate lead for the specialist's component.
		$lead_payload = [ 'lead' => [ 'description' => $description ] ];
		if ( $contact_id > 0 ) {
			$lead_payload['lead']['contacts'] = [ [ 'id' => $contact_id, 'entityType' => 'Contacts' ] ];
		}
		$created       = $ns->create_lead( $lead_payload );
		$child_lead_id = self::extract_lead_id( $created );
		$result['steps']['newLead'] = ( $child_lead_id > 0 );
		if ( $child_lead_id <= 0 ) {
			$result['error'] = 'newlead_failed';
			error_log( sprintf(
				'Zorderz Jobs: newLead failed (desc_len=%d). Response: %s',
				strlen( $description ),
				is_scalar( $created ) ? (string) $created : wp_json_encode( $created )
			) );
			return $result;
		}
		$result['child_lead_id'] = $child_lead_id;

		$assignee_ns_id = self::resolve_crm_user_id( $ns, $assignee_id );

		// 3a) Owner -> the specialist (best-effort; the note documents it regardless).
		if ( $assignee_ns_id > 0 ) {
			$rev   = self::lead_rev( $ns, $child_lead_id );
			$owned = $ns->rpc_call( 'editLead', [
				'leadId' => $child_lead_id,
				'rev'    => $rev,
				'lead'   => [ 'assignee' => [ 'entityType' => 'Users', 'id' => $assignee_ns_id ] ],
			] );
			$result['steps']['assign'] = ( null !== $owned );
		}

		// 3b) Stage -> the target milestone, IF a mapping is supplied by the filter.
		//     No pipeline is hardcoded; an empty mapping simply skips the stage move.
		$stage_name = self::pipeline_for_component( $component );
		if ( '' !== $stage_name ) {
			$milestone_id = self::milestone_id_by_name( $ns, $stage_name );
			if ( $milestone_id > 0 ) {
				$rev    = self::lead_rev( $ns, $child_lead_id );
				$staged = $ns->rpc_call( 'editLead', [
					'leadId' => $child_lead_id,
					'rev'    => $rev,
					'lead'   => [ 'milestoneId' => $milestone_id ],
				] );
				$result['steps']['stage'] = ( null !== $staged );
			}
		}

		// 4) newNote — full provenance + all detail on the child lead.
		$note_lines = [ sprintf( '%s handoff assigned to %s.', $comp_label, self::user_label( $assignee_id ) ) ];
		if ( $customer !== '' )  { $note_lines[] = 'Customer: ' . $customer; }
		if ( $qty > 0 )          { $note_lines[] = 'Qty: ' . $qty; }
		if ( $brand !== '' )     { $note_lines[] = 'Brand: ' . $brand; }
		if ( $parent_lead > 0 )  { $note_lines[] = 'Split from parent lead #' . $parent_lead . '.'; }
		if ( $notes !== '' )     { $note_lines[] = 'Notes: ' . $notes; }
		$note_lines[] = 'Internal work split — not reflected on the customer\'s billing document.';
		$noted = $ns->add_note( [
			'entity' => [ 'entityType' => 'Leads', 'id' => $child_lead_id ],
			'note'   => '* ' . implode( "\n", $note_lines ),
		] );
		$result['steps']['newNote'] = ( null !== $noted );

		// 5) newNote on the PARENT lead too, so the originator's lead shows the split.
		if ( $parent_lead > 0 ) {
			$ns->add_note( [
				'entity' => [ 'entityType' => 'Leads', 'id' => $parent_lead ],
				'note'   => sprintf(
					'-> %s component handed off to %s as separate lead #%d (internal; not billed separately).',
					$comp_label,
					self::user_label( $assignee_id ),
					$child_lead_id
				),
			] );
			$result['steps']['parentNote'] = true;
		}

		$result['ok'] = true;
		return $result;
	}

	/**
	 * Record the worker's completion on the CRM: a note carrying the finish-photo
	 * permalinks on the specialist's CHILD lead, and a shorter heads-up on the PARENT
	 * lead. Does NOT move any stage and NEVER marks a lead Won. Best-effort.
	 *
	 * @return array{ok:bool,error:string,steps:array<string,bool>}
	 */
	public static function post_completion_note( array $job, array $photo_links, bool $verified, int $worker_id ): array {
		$result = [ 'ok' => false, 'error' => '', 'steps' => [] ];

		$child_lead  = (int) ( $job['crm_child_lead_id'] ?? 0 );
		$parent_lead = (int) ( $job['parent_lead_id'] ?? 0 );
		if ( $child_lead <= 0 && $parent_lead <= 0 ) {
			$result['error'] = 'no_lead';
			return $result;
		}
		if ( ! self::available() ) {
			$result['error'] = 'crm_unconfigured';
			return $result;
		}
		$ns = new ZDZ_Core_Nutshell();

		$component  = (string) ( $job['component'] ?? '' );
		$comp_label = function_exists( 'zjob_component_label' ) ? zjob_component_label( $component ) : ucfirst( $component );
		$customer   = trim( (string) ( $job['customer_name'] ?? '' ) );
		$worker     = self::user_label( $worker_id );
		$n          = count( $photo_links );

		$links = [];
		foreach ( $photo_links as $i => $p ) {
			$url = (string) ( $p['url'] ?? '' );
			if ( $url !== '' ) {
				$links[] = sprintf( 'Photo %d: %s', $i + 1, $url );
			}
		}
		$loc = $verified ? 'location verified on-site' : 'location NOT verified';

		if ( $child_lead > 0 ) {
			$lines = [ sprintf( '%s work marked complete by %s.', $comp_label, $worker ) ];
			if ( $customer !== '' ) {
				$lines[] = 'Customer: ' . $customer;
			}
			$lines[] = sprintf( '%d finish photo%s attached (%s).', $n, ( 1 === $n ? '' : 's' ), $loc );
			$lines   = array_merge( $lines, $links );
			$lines[] = 'Awaiting originator close-out (internal; not billed separately).';
			$noted   = $ns->add_note( [
				'entity' => [ 'entityType' => 'Leads', 'id' => $child_lead ],
				'note'   => '* ' . implode( "\n", $lines ),
			] );
			$result['steps']['childNote'] = ( null !== $noted );
		}

		if ( $parent_lead > 0 ) {
			$noted = $ns->add_note( [
				'entity' => [ 'entityType' => 'Leads', 'id' => $parent_lead ],
				'note'   => sprintf(
					'-> %s component completed by %s (%d photo%s, %s). Ready to close out.',
					$comp_label,
					$worker,
					$n,
					( 1 === $n ? '' : 's' ),
					$verified ? 'location verified' : 'location unverified'
				),
			] );
			$result['steps']['parentNote'] = ( null !== $noted );
		}

		$result['ok'] = ! empty( array_filter( $result['steps'] ) );
		if ( ! $result['ok'] && '' === $result['error'] ) {
			$result['error'] = 'note_failed';
		}
		return $result;
	}

	/**
	 * Note the tech's ETA signal on the CUSTOMER's lead (falls back to the child
	 * lead). Best-effort; never blocks the app-side signal.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function post_eta_note( array $job, string $eta, int $worker_id ): array {
		$result = [ 'ok' => false, 'error' => '' ];
		$lead   = (int) ( $job['parent_lead_id'] ?? 0 );
		if ( $lead <= 0 ) {
			$lead = (int) ( $job['crm_child_lead_id'] ?? 0 );
		}
		if ( $lead <= 0 ) {
			$result['error'] = 'no_lead';
			return $result;
		}
		if ( ! self::available() ) {
			$result['error'] = 'crm_unconfigured';
			return $result;
		}
		$ns         = new ZDZ_Core_Nutshell();
		$worker     = self::user_label( $worker_id );
		$customer   = trim( (string) ( $job['customer_name'] ?? '' ) );
		$component  = (string) ( $job['component'] ?? '' );
		$comp_label = function_exists( 'zjob_component_label' ) ? zjob_component_label( $component ) : ucfirst( $component );
		$who        = '' !== $customer ? $customer : ( $comp_label . ' job' );

		$msg = ( 'on_my_way' === $eta )
			? sprintf( '-> %s is on the way to %s.', $worker, $who )
			: sprintf( '-> %s is running late for %s - may need to reschedule.', $worker, $who );

		$noted = $ns->add_note( [
			'entity' => [ 'entityType' => 'Leads', 'id' => $lead ],
			'note'   => $msg,
		] );
		$result['ok'] = ( null !== $noted );
		if ( ! $result['ok'] ) {
			$result['error'] = 'note_failed';
		}
		return $result;
	}

	/**
	 * Close the specialist's CHILD lead when the job is signed off. Closes it as a
	 * NOT-WON outcome (a child lead is internal work, not a sale — marking it Won
	 * would inflate win-rate/forecast/quota). Layered best-effort: set an outcome,
	 * tag it internal, note both leads. If the account defines no outcome the lead is
	 * left OPEN (never Won) and only tagged+noted (needs_outcome=true).
	 *
	 * @return array{ok:bool,error:string,steps:array<string,bool>}
	 */
	public static function close_child_lead( array $job, int $actor_id, bool $auto = false ): array {
		$result = [ 'ok' => false, 'error' => '', 'steps' => [], 'status_before' => null, 'status_after' => null, 'outcome' => '', 'needs_outcome' => false ];

		$child_lead  = (int) ( $job['crm_child_lead_id'] ?? 0 );
		$parent_lead = (int) ( $job['parent_lead_id'] ?? 0 );
		if ( $child_lead <= 0 ) {
			$result['error'] = 'no_child_lead';
			return $result;
		}
		if ( ! self::available() ) {
			$result['error'] = 'crm_unconfigured';
			return $result;
		}
		$ns = new ZDZ_Core_Nutshell();

		$component  = (string) ( $job['component'] ?? '' );
		$comp_label = function_exists( 'zjob_component_label' ) ? zjob_component_label( $component ) : ucfirst( $component );
		$customer   = trim( (string) ( $job['customer_name'] ?? '' ) );
		$signoff    = $auto
			? 'auto-closed (close-out time limit reached)'
			: 'signed off by ' . self::user_label( $actor_id );

		// getLead returns `status` as a bare int: 0 = OPEN, non-zero = already closed.
		// We do NOT re-close an already-closed lead and never override a human outcome.
		$status_before = self::lead_status_int( $ns, $child_lead );
		$result['status_before'] = $status_before;
		$closed = ( null !== $status_before && 0 !== $status_before );

		// 1) Close as NOT-WON via an OUTCOME. A bare closed status is rejected, so a
		//    lost/cancelled close must carry an outcomeId. We set ONLY the outcomeId
		//    (never a status, never Won) and VERIFY by re-reading the status.
		if ( ! $closed ) {
			$outcome = self::resolve_outcome( $ns );
			if ( ! empty( $outcome['id'] ) ) {
				$result['outcome'] = (string) ( $outcome['name'] ?? '' );
				$rev = self::lead_rev( $ns, $child_lead );
				$ns->rpc_call( 'editLead', [ 'leadId' => $child_lead, 'rev' => $rev, 'lead' => [ 'outcomeId' => (int) $outcome['id'] ] ] );
				$after  = self::lead_status_int( $ns, $child_lead );
				$closed = ( null !== $after && 0 !== $after );
			} else {
				$result['needs_outcome'] = true;
			}
		}
		$result['steps']['close'] = $closed;
		$result['status_after']   = self::lead_status_int( $ns, $child_lead );

		// 2) Tag internal (best-effort; filterable/excludable from sales reports).
		$tag = self::internal_tag();
		if ( '' !== $tag ) {
			$rev    = self::lead_rev( $ns, $child_lead );
			$tagged = $ns->rpc_call( 'editLead', [ 'leadId' => $child_lead, 'rev' => $rev, 'lead' => [ 'tags' => [ $tag ] ] ] );
			$result['steps']['tag'] = ( null !== $tagged );
		}

		// 3) Close note on the child lead.
		$state = $closed
			? 'closed (not a sale; excluded from revenue/win reporting)'
			: 'left open pending a not-won outcome (never Won)';
		$lines = [ sprintf( '%s internal work %s.', $comp_label, $signoff ) ];
		if ( '' !== $customer ) {
			$lines[] = 'Customer: ' . $customer;
		}
		$lines[] = 'Internal job complete - ' . $state . '.';
		$noted = $ns->add_note( [
			'entity' => [ 'entityType' => 'Leads', 'id' => $child_lead ],
			'note'   => '* ' . implode( "\n", $lines ),
		] );
		$result['steps']['childNote'] = ( null !== $noted );

		// 4) Heads-up on the parent (originator's) lead.
		if ( $parent_lead > 0 ) {
			$noted2 = $ns->add_note( [
				'entity' => [ 'entityType' => 'Leads', 'id' => $parent_lead ],
				'note'   => sprintf( '-> %s internal job %s.', $comp_label, $signoff ),
			] );
			$result['steps']['parentNote'] = ( null !== $noted2 );
		}

		$result['ok'] = $closed || ! empty( $result['steps']['tag'] );
		if ( ! $result['ok'] && '' === $result['error'] ) {
			$result['error'] = $result['needs_outcome'] ? 'needs_outcome' : 'close_failed';
		}
		return $result;
	}

	/**
	 * Resolve a usable CRM lead outcome (best-effort). Prefers the configured
	 * not-won outcome by name (filterable, neutral default); else a Cancelled-type
	 * reason; else the first defined outcome. Any non-won outcome closes the lead as
	 * NOT Won, which is all we require. Returns [] if none defined. Cached per-request.
	 *
	 * @return array{id?:int,name?:string}
	 */
	private static function resolve_outcome( ZDZ_Core_Nutshell $ns ): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$prefer = strtolower( (string) apply_filters( 'zdz_job_internal_outcome_name', self::INTERNAL_OUTCOME_NAME ) );
		$list   = $ns->rpc_call( 'findLead_Outcomes', [] );
		if ( ! is_array( $list ) || empty( $list ) ) {
			$list = $ns->rpc_call( 'findLead_Outcomes', [ 'limit' => 250 ] );
		}
		$named  = [];
		$cancel = [];
		$first  = [];
		if ( is_array( $list ) ) {
			foreach ( $list as $o ) {
				if ( ! is_array( $o ) ) {
					continue;
				}
				$id = (int) ( $o['id'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$desc = (string) ( $o['description'] ?? ( $o['name'] ?? '' ) );
				$pick = [ 'id' => $id, 'name' => $desc ];
				if ( empty( $first ) ) {
					$first = $pick;
				}
				if ( empty( $cancel ) && false !== stripos( $desc, 'cancel' ) ) {
					$cancel = $pick;
				}
				if ( '' !== $prefer && strtolower( $desc ) === $prefer ) {
					$named = $pick;
					break;
				}
			}
		}
		$out   = ! empty( $named ) ? $named : ( ! empty( $cancel ) ? $cancel : $first );
		$cache = $out;
		return $out;
	}

	/** Read a lead's numeric status (0 = OPEN; non-zero = a closed disposition). */
	private static function lead_status_int( ZDZ_Core_Nutshell $ns, int $lead_id ): ?int {
		$lead = $ns->get_lead( $lead_id, 'REV_NEWEST' );
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

	/* =======================================================================
	 * CRM LOOKUPS
	 * ======================================================================= */

	/** Pull a fresh lead rev string (the CRM wants the current rev on edit). */
	private static function lead_rev( ZDZ_Core_Nutshell $ns, int $lead_id ): string {
		$lead = $ns->get_lead( $lead_id, 'REV_NEWEST' );
		if ( is_array( $lead ) && isset( $lead['rev'] ) ) {
			return (string) $lead['rev'];
		}
		return 'REV_NEWEST';
	}

	/** Extract the numeric contact id from a getLead result (first contact). */
	private static function contact_id_from_lead( ZDZ_Core_Nutshell $ns, int $lead_id ): int {
		$lead = $ns->get_lead( $lead_id, 'REV_NEWEST' );
		if ( is_array( $lead ) && ! empty( $lead['contacts'][0]['id'] ) ) {
			return (int) $lead['contacts'][0]['id'];
		}
		return 0;
	}

	/** Extract a lead id from a newLead/getLead result shape. */
	private static function extract_lead_id( $result ): int {
		if ( is_array( $result ) ) {
			if ( ! empty( $result['id'] ) ) {
				return (int) $result['id'];
			}
			if ( ! empty( $result['lead']['id'] ) ) {
				return (int) $result['lead']['id'];
			}
		}
		return 0;
	}

	/**
	 * Resolve a WP user id -> CRM user id. Order: ZDZ_Core_Settings helper (if
	 * present) -> per-user meta `zdz_crm_user_id` -> findUsers by name/email
	 * (best-effort). Returns 0 if unresolved (the child lead is still created + noted).
	 */
	private static function resolve_crm_user_id( ZDZ_Core_Nutshell $ns, int $wp_user_id ): int {
		if ( $wp_user_id <= 0 ) {
			return 0;
		}
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_nutshell_user_id' ) ) {
			$id = (int) ZDZ_Core_Settings::get_nutshell_user_id( $wp_user_id );
			if ( $id > 0 ) {
				return $id;
			}
		}
		$meta = (int) get_user_meta( $wp_user_id, 'zdz_crm_user_id', true );
		if ( $meta > 0 ) {
			return $meta;
		}
		static $cache = [];
		if ( isset( $cache[ $wp_user_id ] ) ) {
			return $cache[ $wp_user_id ];
		}
		$user  = get_userdata( $wp_user_id );
		$found = 0;
		if ( $user ) {
			$users = $ns->rpc_call( 'findUsers', [ 'query' => [], 'limit' => 200 ] );
			if ( is_array( $users ) ) {
				foreach ( $users as $u ) {
					$name  = strtolower( (string) ( $u['name'] ?? ( $u['name']['displayName'] ?? '' ) ) );
					$email = strtolower( (string) ( $u['emails'][0] ?? '' ) );
					if ( ( $email !== '' && $email === strtolower( $user->user_email ) )
						|| ( $name !== '' && $name === strtolower( $user->display_name ) ) ) {
						$found = (int) ( $u['id'] ?? 0 );
						break;
					}
				}
			}
		}
		$cache[ $wp_user_id ] = $found;
		return $found;
	}

	/**
	 * Resolve a CRM milestone (stage) id for a target name. Accepts EITHER a stage or
	 * a pipeline name: exact milestone-name match, else the entry (lowest-position)
	 * milestone of a stageset with that name. Returns 0 if nothing matches. Cached.
	 */
	private static function milestone_id_by_name( ZDZ_Core_Nutshell $ns, string $name ): int {
		if ( $name === '' ) {
			return 0;
		}
		static $cache = [];
		$key = strtolower( $name );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$milestones = $ns->rpc_call( 'findMilestones', [ 'limit' => 250 ] );
		$milestones = is_array( $milestones ) ? $milestones : [];

		$id = 0;
		foreach ( $milestones as $ms ) {
			if ( is_array( $ms ) && isset( $ms['name'] ) && strcasecmp( (string) $ms['name'], $name ) === 0 ) {
				$id = (int) ( $ms['id'] ?? 0 );
				if ( $id > 0 ) {
					break;
				}
			}
		}

		if ( $id <= 0 ) {
			$stageset_id = 0;
			$sets = $ns->rpc_call( 'findStagesets', [] );
			if ( is_array( $sets ) ) {
				foreach ( $sets as $s ) {
					if ( is_array( $s ) && isset( $s['name'] ) && strcasecmp( (string) $s['name'], $name ) === 0 ) {
						$stageset_id = (int) ( $s['id'] ?? 0 );
						break;
					}
				}
			}
			if ( $stageset_id > 0 ) {
				$best_pos = PHP_INT_MAX;
				foreach ( $milestones as $ms ) {
					if ( ! is_array( $ms ) || (int) ( $ms['stagesetId'] ?? 0 ) !== $stageset_id ) {
						continue;
					}
					$pos = (int) ( $ms['position'] ?? 0 );
					if ( $pos < $best_pos ) {
						$best_pos = $pos;
						$id       = (int) ( $ms['id'] ?? 0 );
					}
				}
			}
		}

		$cache[ $key ] = $id;
		return $id;
	}

	/** A friendly label for a WP user (email-name -> display name -> #id). */
	private static function user_label( int $wp_user_id ): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_user_email_name' ) ) {
			$name = (string) ZDZ_Core_Settings::get_user_email_name( $wp_user_id );
			if ( $name !== '' ) {
				return $name;
			}
		}
		$user = get_userdata( $wp_user_id );
		return $user ? $user->display_name : ( '#' . $wp_user_id );
	}

	/* =======================================================================
	 * CONTACT READ (best-effort address/phone for a job card)
	 * ======================================================================= */

	/**
	 * Resolve a CRM contact's address / phone / company by id. Best-effort: any
	 * failure or unexpected shape returns blanks. Never throws.
	 *
	 * @return array{address:string,phone:string,business:string}
	 */
	public static function resolve_contact_info( int $contact_id ): array {
		$out = [ 'address' => '', 'phone' => '', 'business' => '' ];
		if ( $contact_id <= 0 || ! self::available() ) {
			return $out;
		}
		$ns = new ZDZ_Core_Nutshell();
		$c  = $ns->rpc_call( 'getContact', [ 'contactId' => $contact_id ] );
		if ( ! is_array( $c ) ) {
			return $out;
		}
		if ( isset( $c['contact'] ) && is_array( $c['contact'] ) ) {
			$c = $c['contact'];
		}
		$out['phone']    = self::first_phone( $c );
		$out['address']  = self::first_address( $c );
		$out['business'] = self::company_name( $c );
		return $out;
	}

	/** Pull the first usable phone string out of a CRM contact (many shapes). */
	private static function first_phone( array $c ): string {
		foreach ( [ 'phone', 'phones' ] as $k ) {
			if ( empty( $c[ $k ] ) ) {
				continue;
			}
			$list = $c[ $k ];
			if ( is_string( $list ) && trim( $list ) !== '' ) {
				return trim( $list );
			}
			$entries = ( is_array( $list ) && isset( $list[0] ) ) ? $list : [ $list ];
			foreach ( $entries as $e ) {
				if ( is_string( $e ) && trim( $e ) !== '' ) {
					return trim( $e );
				}
				if ( is_array( $e ) ) {
					foreach ( [ 'value', 'number', 'e164', 'name' ] as $vk ) {
						if ( ! empty( $e[ $vk ] ) && is_string( $e[ $vk ] ) ) {
							return trim( $e[ $vk ] );
						}
					}
					if ( ! empty( $e['value'] ) && is_array( $e['value'] ) ) {
						foreach ( [ 'number', 'e164', 'raw', 'countryCode' ] as $vk ) {
							if ( ! empty( $e['value'][ $vk ] ) && is_scalar( $e['value'][ $vk ] ) ) {
								return trim( (string) $e['value'][ $vk ] );
							}
						}
					}
				}
			}
		}
		return '';
	}

	/** Pull the first usable postal address out of a CRM contact. */
	private static function first_address( array $c ): string {
		foreach ( [ 'address', 'addresses' ] as $k ) {
			if ( empty( $c[ $k ] ) ) {
				continue;
			}
			$list = $c[ $k ];
			if ( is_string( $list ) && trim( $list ) !== '' ) {
				return trim( $list );
			}
			$entries = ( is_array( $list ) && isset( $list[0] ) ) ? $list : [ $list ];
			foreach ( $entries as $e ) {
				if ( is_string( $e ) && trim( $e ) !== '' ) {
					return trim( $e );
				}
				if ( is_array( $e ) ) {
					$street = '';
					foreach ( [ 'address_1', 'address1', 'street', 'name' ] as $sk ) {
						if ( ! empty( $e[ $sk ] ) && is_string( $e[ $sk ] ) ) {
							$street = (string) $e[ $sk ];
							break;
						}
					}
					$tail = [];
					foreach ( [ 'city', 'state', 'postalCode', 'postal_code', 'zip' ] as $sk ) {
						if ( ! empty( $e[ $sk ] ) && is_scalar( $e[ $sk ] ) ) {
							$tail[] = (string) $e[ $sk ];
						}
					}
					$full = trim( implode( ', ', array_filter( [ trim( $street ), implode( ' ', $tail ) ] ) ) );
					if ( $full !== '' ) {
						return $full;
					}
				}
			}
		}
		return '';
	}

	/** Best-effort company / account name linked to a contact. */
	private static function company_name( array $c ): string {
		foreach ( [ 'accountName', 'account', 'companyName', 'company' ] as $k ) {
			if ( empty( $c[ $k ] ) ) {
				continue;
			}
			if ( is_string( $c[ $k ] ) ) {
				return trim( $c[ $k ] );
			}
			if ( is_array( $c[ $k ] ) ) {
				if ( ! empty( $c[ $k ]['name'] ) && is_string( $c[ $k ]['name'] ) ) {
					return trim( $c[ $k ]['name'] );
				}
				if ( isset( $c[ $k ][0]['name'] ) && is_string( $c[ $k ][0]['name'] ) ) {
					return trim( $c[ $k ][0]['name'] );
				}
			}
		}
		return '';
	}
}
