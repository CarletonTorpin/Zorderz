<?php
/**
 * ZL ↔ Orchestrator Bridge — Zorderz Leads as a cross-app capability provider.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS (and why it conforms to the contract, not invents its own thing)
 * ─────────────────────────────────────────────────────────────────────────────
 * This is ZL's seat at the cross-app orchestrator table, written to
 * `ORCHESTRATOR-INTEROP-CONTRACT-v1.md` (2026-06-03). The operator bot ("Brain
 * Bot", the TSA analytics app) plans a request, and when a request resolves to
 * "show me <person>'s leads / pipeline status", it invokes a ZL **read verb**
 * here, server-side. We return a structured array; the TSA engine strips its
 * marker and renders a card/list in its widget.
 *
 * It is the L1 ("Reachable — read") brick for ZL and is shaped as a future
 * `zdz_register_capabilities` callback (CONTRACT §2.3 / §6) so it slots into the
 * Stage-1 registry **unchanged**: structured return, tier-aware, self-contained.
 *
 * Reference implementation we mirror: Estimate Creator's `TSEC_TSA_Bridge`
 * (CONTRACT §2.1) — confidence-gated entity resolution, structured return,
 * tier/kiosk redaction enforced *in the bridge* (never trusting the model), and
 * no fabrication on empty.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE TWO SHARED SEAMS, AS THEY APPLY TO ZL (CONTRACT §1 + §2)
 * ─────────────────────────────────────────────────────────────────────────────
 *  • DATA SEAM. The lead/pipeline data the bot wants is, for ZL, *already
 *    materialized in our own `wp_zl_leads` table* (name, city, territory,
 *    purchase summary, Nutshell ids, nutshell_status, contact_status, score).
 *    That is the fastest, richest, most truthful source for "what's the status
 *    of Steve's lead" — it is the rep's own generated pipeline, not a guess. So
 *    the primary lookup reads our table. When we need a *live* CRM read we don't
 *    already hold (a fresh Nutshell pipeline stage / timeline), we go through the
 *    theme's shared **`ZDZ_Core_Nutshell`** client — never a new private pager —
 *    which is the L3 "shared-data citizen" rule applied to the *new* code path.
 *    (We deliberately do NOT rip out ZL's existing `ZL_Nutshell` generator
 *    client; the contract's §1.4 migration discipline is "behind existing
 *    signatures, never a big-bang rewrite." That migration is a separate to-do
 *    tracked in the ZL conformance doc; it does not block this read bridge.)
 *
 *  • CAPABILITY SEAM. We expose static verbs the orchestrator calls. Today that
 *    is via the bridge pattern (CONTRACT §2.1); when the capability registry
 *    lands (Stage 1) these same methods become registered callbacks (see the
 *    `zdz_register_capabilities` block at the bottom of `ts-sales-leads.php`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY NON-NEGOTIABLES (CONTRACT §3) — ENFORCED HERE, IN CODE
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. TIER AT THE BOUNDARY. The orchestrator passes the caller's tier/kiosk; we
 *     enforce redaction here, before returning. We never rely on the model to
 *     redact. We resolve the tier ourselves from the WordPress user via the
 *     theme's `TS_Data_Permissions` so a spoofed payload can't widen access.
 *  2. KIOSK = MOST-RESTRICTIVE-WINS. Leads carry contact info — the exact
 *     poaching risk the shared shop device guards against. So:
 *       - read verbs run in a **bounded kiosk variant**: name + city + pipeline
 *         stage + work-type only; NO phone, email, full address, or dollar
 *         figures. (Per the ZL conformance doc, "when in doubt forbid on
 *         kiosk"; we implement the bounded form because identify-the-job is
 *         useful and the contact shield is what actually matters.)
 *       - side-effecting verbs (create_lead) are **forbidden on kiosk**, full
 *         stop, and additionally declared side_effect=true so the orchestrator
 *         requires preview-and-confirm off-kiosk.
 *  3. PROVENANCE. We surface dollar figures only off-kiosk, and only the
 *     `purchase_summary`/`purchase_history` *already stored* against the lead
 *     (which itself came from FreshBooks at generation time). We never compute a
 *     fresh aggregate here, so there is no new un-provenanced number; any figure
 *     the bot then *states* still flows through TSA's provenance backbone.
 *  4. NO FABRICATION ON EMPTY. A zero-result lookup says so (with the sanctioned
 *     auto-widen note); it never invents a lead. Below the name-confidence floor
 *     we return needs_clarify instead of guessing the wrong person.
 *  5. URL INTEGRITY. We carry the real Nutshell lead id through so a deep link
 *     is built from the id, never reconstructed from a display string.
 *
 * Every public method returns a structured array and NEVER throws to the caller
 * and NEVER returns null (CONTRACT §2.1.1).
 *
 * @package Zorderz\Leads
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_TSA_Bridge {

	/** Source tag for the audit trail (CONTRACT §2.1.1: every return carries `source`). */
	const SOURCE = 'leads';

	/**
	 * Name-match confidence floor. Below this we ask rather than guess
	 * (resolve-then-confirm, mirrors TSEC's "go clarify rather than show the
	 * wrong client"). 0.0–1.0.
	 */
	const CONFIDENCE_FLOOR = 0.45;

	/* ═══════════════════════════════════════════════════════════════════════
	 * AVAILABILITY
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Is this app installed + minimally usable for the orchestrator?
	 *
	 * "Usable" for a read bridge = the leads table exists. (Nutshell/FreshBooks
	 * creds are NOT required for the local-table lookup; they only gate the
	 * optional live-CRM enrichment, which degrades gracefully.)
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'zl_leads';
		// SHOW TABLES LIKE is cheap and avoids a hard dependency on a migration flag.
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERB 1 — lookup_for_tsa()  (READ, the headline verb)
	 * "what are <person>'s leads / what's their pipeline status"
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Resolve a person and return their lead(s) + pipeline status.
	 *
	 * Payload (the orchestrator passes these; we treat them as untrusted input):
	 *   - customer | query : string   the person reference ("Jane Doe", "Doe")
	 *   - tier             : string   caller's tier hint (we re-resolve authoritatively)
	 *   - is_kiosk         : bool      caller's kiosk hint (we re-resolve authoritatively)
	 *   - user_id          : int       the acting WP user (for authoritative tier resolve)
	 *   - enrich           : bool      optional; if true, attempt a live Nutshell
	 *                                  stage/timeline read via the shared core client
	 *
	 * Return (structured, CONTRACT §2.1.1 + the ZL conformance doc's shape):
	 *   {
	 *     success        : bool,
	 *     error          : string,        // '' on success
	 *     source         : 'leads',
	 *     kiosk          : bool,          // the tier we actually enforced
	 *     needs_clarify  : bool,          // true when below the confidence floor
	 *     message        : string,        // human sentence for the bot to relay
	 *     resolved       : { first_name, last_name, city, confidence } | null,
	 *     leads          : [ <lead view>, ... ],   // redacted per tier
	 *     pipeline_stage : string,        // best-known current stage, '' if unknown
	 *     candidates     : [ {label, lead_id}... ] // only populated on no-confident-match
	 *   }
	 *
	 * @param array $payload
	 * @return array
	 */
	public static function lookup_for_tsa( array $payload ): array {
		try {
			$ctx   = self::resolve_context( $payload );
			$query = self::clean_query( $payload['customer'] ?? $payload['query'] ?? '' );

			if ( $query === '' ) {
				return self::clarify( $ctx, 'Who would you like me to look up? Give me a name.' );
			}

			// 1) Find candidate lead rows by name (local table — the rep's pipeline).
			$matches = self::match_leads_by_name( $query );

			// 2) No match at all → honest empty (NOT a fabricated record). The
			//    sanctioned widen for ZL is the cooldown/age, not a date window,
			//    but the table already holds the full retained history, so a true
			//    empty is a true empty. Distinguish "no such person" from "no
			//    leads for a known person" (the conformance doc's resolution-vs-
			//    empty distinction, mirrored from the orchestrator design §5/§12).
			if ( empty( $matches ) ) {
				return array_merge( self::base( $ctx ), array(
					'success'  => true,
					'message'  => sprintf(
						"I couldn't find a lead matching \"%s\" in the lead pipeline. Want me to check the spelling, or search a different name?",
						$query
					),
					'leads'    => array(),
					'resolved' => null,
				) );
			}

			// 3) Confidence-gate: collapse to the single most likely (resolve-then-
			//    confirm). Below the floor we surface candidates and ask, never guess.
			$best = $matches[0];
			if ( (float) $best['confidence'] < self::CONFIDENCE_FLOOR ) {
				$cands = array();
				foreach ( array_slice( $matches, 0, 4 ) as $m ) {
					$cands[] = array(
						'label'   => trim( $m['first_name'] . ' ' . $m['last_name'] )
							. ( $m['city'] ? ' (' . $m['city'] . ')' : '' ),
						'lead_id' => (int) $m['id'],
					);
				}
				$out = self::clarify(
					$ctx,
					sprintf( "I found a few possible matches for \"%s\" — which one did you mean?", $query )
				);
				$out['candidates'] = $cands;
				return $out;
			}

			// 4) Gather every lead row for that resolved person (a customer can have
			//    more than one lead across batches), redact per tier, and shape.
			$person_rows = self::rows_for_same_person( $matches, $best );
			$lead_views  = array();
			foreach ( $person_rows as $row ) {
				$lead_views[] = self::shape_lead_view( $row, $ctx );
			}

			$pipeline_stage = self::derive_pipeline_stage( $person_rows, $ctx );

			// 5) Optional live-CRM enrichment via the SHARED core client (L3 for the
			//    new path). Best-effort; never blocks or throws; off on kiosk.
			if ( ! $ctx['is_kiosk'] && ! empty( $payload['enrich'] ) ) {
				$live = self::enrich_stage_via_core( $best );
				if ( $live !== '' ) {
					$pipeline_stage = $live;
				}
			}

			$resolved = array(
				'first_name' => $best['first_name'],
				'last_name'  => $best['last_name'],
				'city'       => $best['city'],
				'confidence' => round( (float) $best['confidence'], 2 ),
			);

			$name = trim( $best['first_name'] . ' ' . $best['last_name'] );
			$msg  = $ctx['is_kiosk']
				? sprintf( '%s — %d lead%s on file. (Contact details are hidden on the shared device.)',
					$name ?: 'This customer', count( $lead_views ), count( $lead_views ) === 1 ? '' : 's' )
				: sprintf( '%s — %d lead%s on file%s.',
					$name ?: 'This customer', count( $lead_views ), count( $lead_views ) === 1 ? '' : 's',
					$pipeline_stage ? ', current stage: ' . $pipeline_stage : '' );

			return array_merge( self::base( $ctx ), array(
				'success'        => true,
				'message'        => $msg,
				'resolved'       => $resolved,
				'leads'          => $lead_views,
				'pipeline_stage' => $pipeline_stage,
			) );

		} catch ( \Throwable $e ) {
			error_log( 'ZL_TSA_Bridge::lookup_for_tsa error: ' . $e->getMessage() );
			return self::hard_error( 'Lead lookup failed.' );
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERB 2 — find_leads_for_tsa()  (READ, by filter not by person)
	 * "leads from <source>", "leads not yet contacted in 92065", "new this week"
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Find leads by filter (territory/zip, contact status, age, salesperson).
	 *
	 * Payload:
	 *   - zip|city|territory : string   optional location filter
	 *   - contact_status     : string   'pending'|'contacted'|'skipped' (optional)
	 *   - within_days        : int      created within N days (optional)
	 *   - assigned_to        : string   salesperson code (optional)
	 *   - limit              : int      default 25, hard-capped 100
	 *   - tier / is_kiosk / user_id : as above
	 *
	 * Returns the same envelope as lookup_for_tsa but `leads[]` is the filtered
	 * set and `resolved`/`pipeline_stage` are null/empty. On kiosk this returns
	 * an *aggregate-leaning* view: counts + non-contact facts only (the bounded
	 * form), so "is there an open lead in 92065" works without exposing a name's
	 * contact path.
	 *
	 * @param array $payload
	 * @return array
	 */
	public static function find_leads_for_tsa( array $payload ): array {
		try {
			global $wpdb;
			$ctx = self::resolve_context( $payload );

			$where  = array( '1=1' );
			$args   = array();
			$t      = $wpdb->prefix . 'zl_leads';

			$zip = self::clean_query( $payload['zip'] ?? '' );
			// City is the stored field; zip is matched against city only if it looks
			// like a city. A literal zip filter matches the territory zips → city.
			$city = self::clean_query( $payload['city'] ?? '' );
			if ( $city !== '' ) {
				$where[] = 'l.city LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $city ) . '%';
			}

			$territory = self::clean_query( $payload['territory'] ?? '' );
			if ( $territory !== '' ) {
				$where[] = 'l.territory = %s';
				$args[]  = $territory;
			}

			$cs = self::clean_query( $payload['contact_status'] ?? '' );
			if ( in_array( $cs, array( 'pending', 'contacted', 'skipped' ), true ) ) {
				$where[] = 'l.contact_status = %s';
				$args[]  = $cs;
			}

			$within = isset( $payload['within_days'] ) ? max( 0, (int) $payload['within_days'] ) : 0;
			if ( $within > 0 ) {
				$where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
				$args[]  = $within;
			}

			$assigned = self::clean_query( $payload['assigned_to'] ?? '' );
			if ( $assigned !== '' ) {
				// assigned_to lives on the batch, so join.
				$where[] = 'b.assigned_to = %s';
				$args[]  = strtoupper( $assigned );
			}

			$limit = isset( $payload['limit'] ) ? (int) $payload['limit'] : 25;
			$limit = max( 1, min( 100, $limit ) );

			$sql = "SELECT l.*, b.assigned_to AS batch_assigned_to
			        FROM {$t} l
			        LEFT JOIN {$wpdb->prefix}zl_batches b ON b.id = l.batch_id
			        WHERE " . implode( ' AND ', $where ) . "
			        ORDER BY l.created_at DESC
			        LIMIT %d";
			$args[] = $limit;

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();

			if ( empty( $rows ) ) {
				return array_merge( self::base( $ctx ), array(
					'success' => true,
					'message' => 'No leads matched those filters.',
					'leads'   => array(),
					'count'   => 0,
				) );
			}

			$views = array();
			foreach ( $rows as $row ) {
				$views[] = self::shape_lead_view( $row, $ctx );
			}

			$msg = sprintf( '%d lead%s matched.', count( $views ), count( $views ) === 1 ? '' : 's' );
			if ( $ctx['is_kiosk'] ) {
				$msg .= ' (Contact details hidden on the shared device.)';
			}

			return array_merge( self::base( $ctx ), array(
				'success' => true,
				'message' => $msg,
				'leads'   => $views,
				'count'   => count( $views ),
			) );

		} catch ( \Throwable $e ) {
			error_log( 'ZL_TSA_Bridge::find_leads_for_tsa error: ' . $e->getMessage() );
			return self::hard_error( 'Lead search failed.' );
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERB 3 — create_lead_from_tsa()  (SIDE-EFFECT, OPTIONAL, gated)
	 * Declared but intentionally a guarded stub: side-effects must run through
	 * preview-and-confirm and are kiosk-forbidden (CONTRACT §2.4 + §3.2). We ship
	 * it refusing rather than half-implemented so the registry can declare the
	 * verb's shape now; flesh out the write when the confirm UX is wired.
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Create a lead from chat. SIDE-EFFECTING → requires preview-and-confirm,
	 * kiosk-forbidden. Currently returns a structured refusal (not implemented)
	 * so the contract's "declare side_effect honestly" holds and no unconfirmed
	 * write can occur. When implemented, route the Nutshell write through the
	 * shared `ZDZ_Core_Nutshell::create_lead()` and keep the local-save-first /
	 * durable-enqueue pattern from `ZL_Nutshell_Writeback`.
	 *
	 * @param array $payload
	 * @return array
	 */
	public static function create_lead_from_tsa( array $payload ): array {
		$ctx = self::resolve_context( $payload );

		if ( $ctx['is_kiosk'] ) {
			return array_merge( self::base( $ctx ), array(
				'success' => false,
				'error'   => 'forbidden_on_kiosk',
				'message' => 'Creating a lead is not allowed on the shared device.',
			) );
		}

		// Honest "not yet implemented" — never a silent no-op, never an unconfirmed write.
		return array_merge( self::base( $ctx ), array(
			'success'        => false,
			'error'          => 'not_implemented',
			'requires_confirm' => true,
			'message'        => 'Lead creation from chat isn’t enabled yet — add it from the Leads app for now.',
		) );
	}

	/**
	 * Assign one or more Nutshell leads to a user (set the lead's owner/assignee).
	 * SIDE-EFFECTING -> preview-and-confirm, kiosk-forbidden, admin-only.
	 *
	 * Payload:
	 *   - assignee  : string  name / salesperson code / "me" of the target owner (required)
	 *   - lead_ids  : int[]   Nutshell lead ids to (re)assign — resolved server-side by the
	 *                         engine from the fetched lead/survey context, NEVER from the model
	 *   - confirmed : bool    true only on the user's explicit second-turn "yes"
	 *   - user_id / tier / is_kiosk : engine-authoritative context (overrides any model hint)
	 *
	 * Turn 1 (confirmed=false): returns a preview (requires_confirm=true), writes nothing.
	 * Turn 2 (confirmed=true) : per lead get_lead()->rev->edit_lead(assignee) in Nutshell.
	 *                          Fail-open per lead (one bad lead never aborts the batch).
	 */
	public static function assign_lead_owner_for_tsa( array $payload ): array {
		$ctx = self::resolve_context( $payload );
		$out = self::base( $ctx );

		// INV-10: never on the shared kiosk.
		if ( ! empty( $ctx['is_kiosk'] ) ) {
			return array_merge( $out, array(
				'error'   => 'forbidden_on_kiosk',
				'message' => 'Assigning leads is not allowed on the shared device.',
			) );
		}

		// Admin/operator only (same authority as the Leads app's assign action).
		if ( ! class_exists( 'ZL_Lead_Assignment' )
			|| ! ZL_Lead_Assignment::is_lead_admin( (int) $ctx['user_id'] ) ) {
			return array_merge( $out, array(
				'error'   => 'forbidden',
				'message' => 'You do not have permission to reassign leads.',
			) );
		}

		// Target leads (Nutshell ids), resolved upstream by the engine — not the model.
		$lead_ids = array();
		if ( ! empty( $payload['lead_ids'] ) && is_array( $payload['lead_ids'] ) ) {
			$lead_ids = array_values( array_unique( array_filter( array_map( 'intval', $payload['lead_ids'] ) ) ) );
		}
		if ( empty( $lead_ids ) ) {
			return self::clarify( $ctx, 'I could not tell which leads to assign. Tell me which leads (e.g. "the 10 survey leads from today") and who to assign them to.' );
		}

		// Resolve the assignee -> a Nutshell user id (+ the WP user id, for reference).
		$assignee_raw = trim( (string) ( $payload['assignee'] ?? '' ) );
		if ( $assignee_raw === '' ) {
			return self::clarify( $ctx, 'Who should I assign these leads to?' );
		}
		$resolved = self::resolve_assignee_to_nutshell_user( $assignee_raw, (int) $ctx['user_id'] );
		if ( empty( $resolved['ns_user_id'] ) ) {
			$msg = ! empty( $resolved['ambiguous'] )
				? sprintf( 'More than one person matches "%s". Which one?', $assignee_raw )
				: sprintf( 'I could not find a Nutshell user for "%s". Map their Nutshell user id in the Leads app, or give me the exact name.', $assignee_raw );
			return self::clarify( $ctx, $msg );
		}
		$ns_user_id   = (int) $resolved['ns_user_id'];
		$ns_user_name = (string) ( $resolved['ns_user_name'] ?? $assignee_raw );

		// TURN 1 — preview only, write nothing.
		$confirmed = ! empty( $payload['confirmed'] );
		if ( ! $confirmed ) {
			return array_merge( $out, array(
				'success'          => true,
				'requires_confirm' => true,
				'action'           => 'assign_lead_owner',
				'assignee'         => array( 'ns_user_id' => $ns_user_id, 'name' => $ns_user_name ),
				'lead_ids'         => $lead_ids,
				'count'            => count( $lead_ids ),
				'message'          => sprintf(
					'Ready to assign %d lead%s to %s. Reply "yes" to confirm.',
					count( $lead_ids ), count( $lead_ids ) === 1 ? '' : 's', $ns_user_name
				),
			) );
		}

		// TURN 2 — perform the Nutshell owner write, per lead, fail-open.
		if ( ! class_exists( 'ZL_Nutshell' ) ) {
			return array_merge( $out, array( 'error' => 'nutshell_unavailable', 'message' => 'The Nutshell connection is not available right now.' ) );
		}
		$ns           = new ZL_Nutshell();
		$assignee_ref = array( 'entityType' => 'Users', 'id' => $ns_user_id );

		$assigned = array();
		$failed   = array();
		foreach ( $lead_ids as $lid ) {
			try {
				$lead = $ns->get_lead( $lid );
				$rev  = ( is_array( $lead ) && isset( $lead['rev'] ) ) ? $lead['rev'] : '';
				if ( '' === $rev ) { $failed[] = $lid; continue; }
				$res = $ns->edit_lead( $lid, $rev, array( 'assignee' => $assignee_ref ) );
				if ( false === $res || null === $res ) { $failed[] = $lid; } else { $assigned[] = $lid; }
			} catch ( \Throwable $e ) {
				$failed[] = $lid;
			}
		}

		$ok = ! empty( $assigned );
		return array_merge( $out, array(
			'success'  => $ok,
			'action'   => 'assign_lead_owner',
			'assignee' => array( 'ns_user_id' => $ns_user_id, 'name' => $ns_user_name ),
			'assigned' => $assigned,
			'failed'   => $failed,
			'count'    => count( $assigned ),
			'message'  => $ok
				? sprintf(
					'Assigned %d lead%s to %s%s.',
					count( $assigned ), count( $assigned ) === 1 ? '' : 's', $ns_user_name,
					empty( $failed ) ? '' : sprintf( ' (%d could not be updated)', count( $failed ) )
				)
				: 'None of the leads could be assigned — the Nutshell update was rejected.',
		) );
	}

	/**
	 * Resolve a free-text assignee ("a teammate", a salesperson code, or "me") to a
	 * Nutshell user id. Order: self -> WP-user-by-code/name -> mapped Nutshell id;
	 * final fallback -> match the name against the live Nutshell user roster.
	 * Never guesses: returns ns_user_id=0 (ambiguous flag set) when unsure.
	 *
	 * @return array { ns_user_id:int, ns_user_name:string, wp_user_id:int, ambiguous:bool }
	 */
	private static function resolve_assignee_to_nutshell_user( string $assignee, int $actor_id ): array {
		$none = array( 'ns_user_id' => 0, 'ns_user_name' => '', 'wp_user_id' => 0, 'ambiguous' => false );
		$a    = trim( $assignee );
		if ( $a === '' ) { return $none; }

		// 1. Resolve to a WP user id where we can.
		$wp_uid = 0;
		if ( preg_match( '/^(me|myself|i)$/i', $a ) ) {
			$wp_uid = $actor_id;
		} elseif ( class_exists( 'ZL_Lead_Assignment' ) && preg_match( '/^[A-Za-z]{1,4}\d?$/', $a ) ) {
			$wp_uid = ZL_Lead_Assignment::resolve_code_to_user( strtoupper( $a ) );
		}
		if ( $wp_uid === 0 ) {
			$cands = get_users( array(
				'search'         => '*' . $a . '*',
				'search_columns' => array( 'display_name', 'user_nicename', 'user_login' ),
				'number'         => 5,
				'fields'         => array( 'ID', 'display_name' ),
			) );
			if ( count( $cands ) === 1 ) {
				$wp_uid = (int) $cands[0]->ID;
			} elseif ( count( $cands ) > 1 ) {
				$exact = array_values( array_filter( $cands, function ( $u ) use ( $a ) {
					return strcasecmp( strtok( (string) $u->display_name, ' ' ), $a ) === 0;
				} ) );
				if ( count( $exact ) === 1 ) { $wp_uid = (int) $exact[0]->ID; } elseif ( count( $exact ) > 1 ) { return array_merge( $none, array( 'ambiguous' => true ) ); }
			}
		}

		// 2. WP user -> mapped Nutshell user id.
		if ( $wp_uid > 0 ) {
			$ns_id = 0;
			if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_nutshell_user_id' ) ) {
				$ns_id = (int) ZDZ_Core_Settings::get_nutshell_user_id( $wp_uid );
			}
			if ( $ns_id <= 0 ) {
				$ns_id = (int) get_user_meta( $wp_uid, 'zl_nutshell_user_id', true );
			}
			if ( $ns_id > 0 ) {
				$u = get_userdata( $wp_uid );
				return array( 'ns_user_id' => $ns_id, 'ns_user_name' => $u ? $u->display_name : $a, 'wp_user_id' => $wp_uid, 'ambiguous' => false );
			}
		}

		// 3. Fallback: match the name against the live Nutshell user roster.
		if ( class_exists( 'ZL_Nutshell' ) ) {
			$ns    = new ZL_Nutshell();
			$users = method_exists( $ns, 'find_users' ) ? $ns->find_users( 200 ) : array();
			$hits  = array();
			foreach ( (array) $users as $u ) {
				$name = is_array( $u ) ? (string) ( $u['name'] ?? '' ) : '';
				$id   = is_array( $u ) ? (int) ( $u['id'] ?? 0 ) : 0;
				if ( $id <= 0 || $name === '' ) { continue; }
				if ( strcasecmp( $name, $a ) === 0 || strcasecmp( strtok( $name, ' ' ), $a ) === 0 || stripos( $name, $a ) !== false ) {
					$hits[ $id ] = $name;
				}
			}
			if ( count( $hits ) === 1 ) {
				$id = array_key_first( $hits );
				return array( 'ns_user_id' => (int) $id, 'ns_user_name' => $hits[ $id ], 'wp_user_id' => $wp_uid, 'ambiguous' => false );
			}
			if ( count( $hits ) > 1 ) {
				$exact = array_filter( $hits, function ( $n ) use ( $a ) { return strcasecmp( strtok( (string) $n, ' ' ), $a ) === 0; } );
				if ( count( $exact ) === 1 ) {
					$id = array_key_first( $exact );
					return array( 'ns_user_id' => (int) $id, 'ns_user_name' => $exact[ $id ], 'wp_user_id' => $wp_uid, 'ambiguous' => false );
				}
				return array_merge( $none, array( 'ambiguous' => true, 'wp_user_id' => $wp_uid ) );
			}
		}

		return array_merge( $none, array( 'wp_user_id' => $wp_uid ) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * INTERNALS — context / tier resolution
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Authoritatively resolve the acting user's tier/kiosk state. We accept the
	 * orchestrator's hints but DO NOT trust them for redaction — we re-derive
	 * from WordPress so a forged payload can't widen access (CONTRACT §3.1).
	 *
	 * Kiosk is defined by the theme as the `ts_general` role (the all-deny shared
	 * shop account). We read it through `TS_Data_Permissions` when present, and
	 * fall back to a direct role check + the payload hint if the theme isn't
	 * loaded (e.g. unit context) — defaulting to the *most restrictive* reading.
	 *
	 * @param array $payload
	 * @return array { user_id:int, is_kiosk:bool, can_view_contact:bool, can_view_pricing:bool }
	 */
	private static function resolve_context( array $payload ): array {
		$user_id = isset( $payload['user_id'] ) ? (int) $payload['user_id'] : get_current_user_id();

		$is_kiosk_hint = ! empty( $payload['is_kiosk'] )
			|| ( isset( $payload['tier'] ) && strtolower( (string) $payload['tier'] ) === 'kiosk' );

		$is_kiosk = $is_kiosk_hint; // start from the hint…
		$user     = $user_id ? get_userdata( $user_id ) : null;

		// …then let the authoritative WordPress role decide (can only TIGHTEN here:
		// if the user *is* ts_general we force kiosk on regardless of the hint).
		if ( $user && in_array( 'zdz_general', (array) $user->roles, true ) ) {
			$is_kiosk = true;
		}

		// Contact/pricing visibility off-kiosk is governed by ZL's own feature
		// permissions (view_contact_info / view_pricing). On kiosk both are denied.
		$can_view_contact = false;
		$can_view_pricing = false;
		if ( ! $is_kiosk && class_exists( 'ZL_Permissions' ) ) {
			$can_view_contact = ZL_Permissions::user_can( 'view_contact_info', $user_id );
			$can_view_pricing = ZL_Permissions::user_can( 'view_pricing', $user_id );
		}

		return array(
			'user_id'          => $user_id,
			'is_kiosk'         => (bool) $is_kiosk,
			'can_view_contact' => (bool) $can_view_contact,
			'can_view_pricing' => (bool) $can_view_pricing,
		);
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * INTERNALS — name resolution (confidence-gated, mirrors TSEC)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Match leads by a free-text name against the local table, returning rows
	 * sorted by a 0–1 confidence score (exact full-name > exact last > prefix >
	 * token), most recent first as the tie-breaker. This is the "fuzzy/wildcard
	 * match + confidence gating" the orchestrator design §12 calls for, done over
	 * the data we own.
	 *
	 * @param string $query
	 * @return array<int,array> rows with an added 'confidence' float
	 */
	private static function match_leads_by_name( string $query ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'zl_leads';

		$q      = trim( $query );
		$tokens = preg_split( '/\s+/', $q );
		$like   = '%' . $wpdb->esc_like( $q ) . '%';

		// Broad candidate pull: any token hits first or last name. Bounded.
		$clauses = array();
		$args    = array();
		foreach ( $tokens as $tok ) {
			$tok = trim( $tok );
			if ( $tok === '' ) {
				continue;
			}
			$tl        = '%' . $wpdb->esc_like( $tok ) . '%';
			$clauses[] = '(l.first_name LIKE %s OR l.last_name LIKE %s)';
			$args[]    = $tl;
			$args[]    = $tl;
		}
		if ( empty( $clauses ) ) {
			return array();
		}

		$sql  = "SELECT l.*, b.assigned_to AS batch_assigned_to
		         FROM {$t} l
		         LEFT JOIN {$wpdb->prefix}zl_batches b ON b.id = l.batch_id
		         WHERE " . implode( ' OR ', $clauses ) . "
		         ORDER BY l.created_at DESC
		         LIMIT 50";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$ql = strtolower( $q );
		foreach ( $rows as &$r ) {
			$full = strtolower( trim( $r['first_name'] . ' ' . $r['last_name'] ) );
			$last = strtolower( trim( $r['last_name'] ) );
			$first= strtolower( trim( $r['first_name'] ) );

			$score = 0.30; // a token matched at all
			if ( $full === $ql ) {
				$score = 1.00;                       // exact full name
			} elseif ( $last === $ql || $first === $ql ) {
				$score = 0.80;                       // exact single-name match
			} elseif ( $last !== '' && strpos( $ql, $last ) !== false ) {
				$score = 0.65;                       // query contains the last name
			} elseif ( $first !== '' && strpos( $ql, $first ) !== false ) {
				$score = 0.55;                       // query contains the first name
			} elseif ( strpos( $full, $ql ) !== false ) {
				$score = 0.50;                       // substring of the full name
			}
			$r['confidence'] = $score;
		}
		unset( $r );

		// Sort by confidence desc, then most recent (created_at already DESC from SQL).
		usort( $rows, function ( $a, $b ) {
			if ( $a['confidence'] === $b['confidence'] ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
			return ( $a['confidence'] < $b['confidence'] ) ? 1 : -1;
		} );

		return $rows;
	}

	/**
	 * Collect every row belonging to the same resolved person (so multiple leads
	 * across batches are returned together). We key on FreshBooks client id when
	 * present, else exact normalized full name + city.
	 *
	 * @param array $all  all scored matches
	 * @param array $best the chosen top match
	 * @return array<int,array>
	 */
	private static function rows_for_same_person( array $all, array $best ): array {
		$out = array();
		$fb  = (string) ( $best['freshbooks_client_id'] ?? '' );
		$key = strtolower( trim( $best['first_name'] . '|' . $best['last_name'] . '|' . $best['city'] ) );

		foreach ( $all as $r ) {
			$same = false;
			if ( $fb !== '' && (string) ( $r['freshbooks_client_id'] ?? '' ) === $fb ) {
				$same = true;
			} elseif ( strtolower( trim( $r['first_name'] . '|' . $r['last_name'] . '|' . $r['city'] ) ) === $key ) {
				$same = true;
			}
			if ( $same ) {
				$out[] = $r;
			}
		}
		return $out ?: array( $best );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * INTERNALS — view shaping + redaction (tier enforced HERE)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Shape one DB lead row into the wire view, applying tier/kiosk redaction
	 * deterministically (CONTRACT §3.2 / §4: "data carries the redaction; the
	 * renderer just honors it"). On kiosk we hard-strip contact + money. Off
	 * kiosk we additionally honor ZL's own view_contact_info / view_pricing.
	 *
	 * @param array $row
	 * @param array $ctx resolve_context() output
	 * @return array
	 */
	private static function shape_lead_view( array $row, array $ctx ): array {
		$show_contact = ! $ctx['is_kiosk'] && $ctx['can_view_contact'];
		$show_money   = ! $ctx['is_kiosk'] && $ctx['can_view_pricing'];

		// Sales role grants both by default (see ZL_Permissions defaults); a
		// restricted user or kiosk falls through to hidden.
		$view = array(
			'lead_id'         => (int) $row['id'],
			'first_name'      => (string) $row['first_name'],
			'last_name'       => (string) $row['last_name'],
			'city'            => (string) $row['city'],
			'territory'       => (string) $row['territory'],
			'contact_status'  => (string) $row['contact_status'],
			'nutshell_status' => (string) ( $row['nutshell_status'] ?? '' ),
			// Real id for a correct deep link — never reconstructed (CONTRACT §3.5).
			'nutshell_lead_id'=> (string) ( $row['nutshell_lead_id'] ?? '' ),
			'kiosk'           => $ctx['is_kiosk'],
		);

		// Work/interest summary identifies the job. On kiosk we keep it but scrub
		// any address-bearing line and any dollar figure (belt-and-suspenders with
		// the money flag), matching the TSEC kiosk "scrub address-like lines" rule.
		$summary = (string) ( $row['purchase_summary'] ?? '' );
		if ( $summary !== '' ) {
			if ( ! $show_money ) {
				$summary = self::scrub_money( $summary );
			}
			if ( $ctx['is_kiosk'] ) {
				$summary = self::scrub_address_lines( $summary );
			}
			$view['work_summary'] = $summary;
		}

		if ( $show_contact ) {
			$view['email'] = (string) ( $row['email'] ?? '' );
			$view['phone'] = (string) ( $row['phone'] ?? '' );
		}
		// else: contact fields omitted entirely (renderer can't show what isn't there).

		if ( $show_money ) {
			// Score is an internal ranking number, not a dollar figure, but it's
			// quote-intel-adjacent; expose only off-kiosk to people who see pricing.
			$view['score'] = (float) $row['score'];
		}

		return $view;
	}

	/**
	 * Best-known pipeline stage for the resolved person from the rows we hold.
	 * Prefers a non-empty `nutshell_status`; falls back to a contact-status
	 * paraphrase so kiosk still gets a coarse stage without a CRM call.
	 *
	 * @param array $rows
	 * @param array $ctx
	 * @return string
	 */
	private static function derive_pipeline_stage( array $rows, array $ctx ): string {
		foreach ( $rows as $r ) {
			if ( ! empty( $r['nutshell_status'] ) ) {
				return (string) $r['nutshell_status'];
			}
		}
		// Fallback from local contact_status.
		$cs = strtolower( (string) ( $rows[0]['contact_status'] ?? '' ) );
		switch ( $cs ) {
			case 'contacted': return 'Contacted';
			case 'skipped':   return 'Skipped';
			case 'pending':   return 'Not yet contacted';
			default:          return '';
		}
	}

	/**
	 * Optional live pipeline-stage read via the SHARED core Nutshell client
	 * (L3 for the new path). Best-effort, never throws. Returns '' on any miss
	 * so the caller keeps the local stage.
	 *
	 * @param array $best the resolved top lead row (carries nutshell_lead_id)
	 * @return string
	 */
	private static function enrich_stage_via_core( array $best ): string {
		try {
			$ns_id = (int) ( $best['nutshell_lead_id'] ?? 0 );
			if ( $ns_id <= 0 || ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
				return '';
			}
			$core = new ZDZ_Core_Nutshell();
			if ( ! $core->is_configured() ) {
				return '';
			}
			$lead = $core->get_lead( $ns_id, 'REV_NEWEST' );
			if ( is_array( $lead ) ) {
				// Nutshell shapes vary; probe the common stage fields.
				$stage = $lead['stagesetStageId']['name']
					?? $lead['status']
					?? $lead['stage']['name']
					?? '';
				if ( is_string( $stage ) && $stage !== '' ) {
					return $stage;
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZL_TSA_Bridge::enrich_stage_via_core error: ' . $e->getMessage() );
		}
		return '';
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * INTERNALS — small helpers
	 * ═══════════════════════════════════════════════════════════════════════ */

	/** Strip $-amounts from a string (reuses ZL_Permissions::scrub_pricing when present). */
	private static function scrub_money( string $text ): string {
		if ( class_exists( 'ZL_Permissions' ) ) {
			return ZL_Permissions::scrub_pricing( $text );
		}
		return preg_replace( '/\$[\d,]+(?:\.\d{1,2})?/', '[hidden]', $text );
	}

	/**
	 * Remove address-bearing lines (the "Location: 23944 Nectar Way" pattern the
	 * contract calls out) while keeping the rest of the work description. We drop
	 * any clause that looks like a street address or an explicit Location: tag.
	 */
	private static function scrub_address_lines( string $text ): string {
		$parts = preg_split( '/[\n;]+/', $text );
		$kept  = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p === '' ) {
				continue;
			}
			// Location: ... or a street-number + street-word pattern → drop.
			if ( preg_match( '/^location\s*:/i', $p ) ) {
				continue;
			}
			if ( preg_match( '/\b\d{2,6}\s+\w+(\s+\w+){0,3}\s+(st|street|ave|avenue|rd|road|way|dr|drive|ln|lane|ct|court|blvd|pl|place|cir|circle)\b/i', $p ) ) {
				continue;
			}
			$kept[] = $p;
		}
		return implode( '; ', $kept );
	}

	/** Trim + collapse a free-text query; strip control chars. */
	private static function clean_query( $raw ): string {
		$s = is_string( $raw ) ? $raw : '';
		$s = trim( wp_strip_all_tags( $s ) );
		return preg_replace( '/\s+/', ' ', $s );
	}

	/** The common envelope fields for every return (CONTRACT §2.1.1). */
	private static function base( array $ctx ): array {
		return array(
			'success'       => false,
			'error'         => '',
			'source'        => self::SOURCE,
			'kiosk'         => (bool) $ctx['is_kiosk'],
			'needs_clarify' => false,
			'message'       => '',
			'resolved'      => null,
			'leads'         => array(),
			'pipeline_stage'=> '',
			'candidates'    => array(),
		);
	}

	/** A below-the-floor / must-ask return. */
	private static function clarify( array $ctx, string $message ): array {
		return array_merge( self::base( $ctx ), array(
			'success'       => true,   // the call worked; we just need more info
			'needs_clarify' => true,
			'message'       => $message,
		) );
	}

	/** A self-contained hard-error return (still structured; never throws out). */
	private static function hard_error( string $message ): array {
		return array(
			'success'       => false,
			'error'         => $message,
			'source'        => self::SOURCE,
			'kiosk'         => true,   // fail closed
			'needs_clarify' => false,
			'message'       => $message,
			'resolved'      => null,
			'leads'         => array(),
			'pipeline_stage'=> '',
			'candidates'    => array(),
		);
	}
}
