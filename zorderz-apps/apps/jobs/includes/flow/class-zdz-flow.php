<?php
/**
 * Zdz_Flow — the app-local, Flow-shaped work-item WRITER and reader.
 *
 * The jobs app owns this substrate for now; it is shaped exactly like the future Core Flow
 * service (workflow-web.md §4) so promotion is a move, not a redesign. It is the SOLE writer of
 * a work item's `state` (INV-A): state is a materialized view of an append-only transition log,
 * written only by create() (the genesis) and transition() (every step thereafter).
 *
 * Load-bearing guarantees (all preserved from the Flow design):
 *   - INV-A  no state without a transition — enforced in code here (state is written only inside
 *            create() and transition(), each paired atomically with its log row).
 *   - INV-B  no exit without a disposition — disposition() writes the ledger row (idempotent).
 *   - human_code is DISPLAY-ONLY, never parsed, never a key. Keys are (system, entity, external_id).
 *   - idempotent transitions — optimistic concurrency (WHERE version=? AND state=?), a request
 *            idempotency key with a UNIQUE index, and keyed timers (a timer fires once).
 *   - events carry `visibility` (internal | staff | customer) on the envelope, and the outbox row
 *            is written IN the transition's transaction (no event without a committed transition).
 *
 * The substrate ships EMPTY: it registers no flow definition and no ref namespace. Apps supply
 * flow definitions (via the `zdz_flow_definitions` filter or the wp_zdz_flow_definitions table),
 * guards (via `zdz_flow_guards`), the human_code template (via `zdz_flow_human_code_template`), and
 * a derivation rule for derived-status work types (via `zdz_flow_derive_state`). With no definition
 * registered, transition() runs in a permissive, definition-less mode that still enforces INV-A,
 * optimistic concurrency, idempotency, the append-only log and the outbox — it simply does not
 * validate against a state machine there is none of.
 *
 * Promotion path: move this file (and its siblings) to `zorderz/inc/` unchanged.
 *
 * @package Zorderz\Jobs\Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-zdz-ulid.php';
require_once __DIR__ . '/class-zdz-flow-db.php';
require_once __DIR__ . '/class-zdz-flow-refs.php';

if ( ! class_exists( 'Zdz_Flow' ) ) {

	class Zdz_Flow {

		/** Actor kinds recorded on a transition. */
		const ACTOR_KINDS = array( 'user', 'system', 'external', 'api' );
		/** Event-envelope visibility tiers. A customer renderer may consume only `customer`. */
		const VISIBILITIES = array( 'internal', 'staff', 'customer' );

		/* ===================================================================
		 * CREATE — mint a work item + its genesis transition (∅ -> initial).
		 * =================================================================== */

		/**
		 * Create a work item. Idempotent on (tenant, work_type, natural_key): a second minter for
		 * the same natural_key is a no-op that returns the EXISTING id, not an error.
		 *
		 * $args:
		 *   work_type        (string, required)  the definition's work_type.
		 *   natural_key      (string, required)  the dedupe/origin key (e.g. "estimate:1234").
		 *   state            (string)            initial state when no definition is registered
		 *                                        (default 'new'); ignored when a definition exists
		 *                                        (its initial:true state wins).
		 *   flow_version     (int)               pinned flow version when definition-less.
		 *   human_code       (string)            explicit display code; else rendered from template.
		 *   human_code_ctx   (array)             tokens for the human_code template.
		 *   parent_id/root_id(string)            hierarchy.
		 *   actor            (array)             {kind,id,on_behalf_of} for the genesis transition.
		 *   evidence         (array)             genesis evidence.
		 *   reason           (string)            genesis reason.
		 *   refs             (array)             initial refs [ [system,entity,external_id,meta], … ].
		 *   idempotency_key  (string)            genesis idempotency key (else derived).
		 *
		 * @return string the work_item_id (ULID), or '' on invalid input / write failure.
		 *
		 * @throws Zdz_Flow_Ref_Conflict if an initial ref collides with another item (the newly
		 *         minted item is discarded first, so the caller gets a clean conflict to retreat on).
		 */
		public static function create( array $args ): string {
			global $wpdb;

			$work_type = sanitize_key( (string) ( $args['work_type'] ?? '' ) );
			$natural   = trim( (string) ( $args['natural_key'] ?? '' ) );
			if ( '' === $work_type || '' === $natural ) {
				return '';
			}
			$natural = substr( $natural, 0, 191 );
			$tenant  = Zdz_Flow_DB::tenant_id();
			$wi      = Zdz_Flow_DB::work_items();

			// Idempotent create: return the existing id if one already holds this natural_key.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wi} WHERE tenant_id = %d AND work_type = %s AND natural_key = %s",
					$tenant,
					$work_type,
					$natural
				)
			);
			if ( null !== $existing ) {
				return (string) $existing;
			}

			$def          = self::definition( $work_type );
			$initial      = $def ? self::initial_state( $def ) : sanitize_key( (string) ( $args['state'] ?? 'new' ) );
			$initial      = ( '' !== $initial ) ? $initial : 'new';
			$flow_version = $def ? (int) ( $def['version'] ?? 0 ) : (int) ( $args['flow_version'] ?? 0 );

			$id        = Zdz_Ulid::generate();
			$parent_id = ( isset( $args['parent_id'] ) && Zdz_Ulid::is_valid( $args['parent_id'] ) ) ? (string) $args['parent_id'] : null;
			$root_id   = self::resolve_root( $id, $parent_id, $args['root_id'] ?? null );
			$human     = self::resolve_human_code( $work_type, $args );
			$actor     = self::normalize_actor( $args['actor'] ?? array() );
			$now       = current_time( 'mysql', true );

			$idem = (string) ( $args['idempotency_key'] ?? '' );
			$idem = ( '' !== $idem )
				? hash( 'sha256', $idem )
				: hash( 'sha256', $tenant . '|' . $id . '|create|' . $natural );

			self::begin();

			$ok = $wpdb->insert(
				$wi,
				array(
					'id'              => $id,
					'tenant_id'       => $tenant,
					'work_type'       => $work_type,
					'flow_version'    => $flow_version,
					'state'           => $initial, // ← state born WITH its genesis transition (INV-A)
					'state_since'     => $now,
					'version'         => 0,
					'assurance_level' => isset( $args['assurance_level'] ) ? (string) $args['assurance_level'] : null,
					'human_code'      => $human,
					'natural_key'     => $natural,
					'parent_id'       => $parent_id,
					'root_id'         => $root_id,
					'created_at'      => $now,
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $ok ) {
				// Lost a create race on the natural_key UNIQUE — adopt the winner.
				self::rollback();
				$winner = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wi} WHERE tenant_id = %d AND work_type = %s AND natural_key = %s",
						$tenant,
						$work_type,
						$natural
					)
				);
				return ( null !== $winner ) ? (string) $winner : '';
			}

			// Genesis transition (∅ -> initial), sequence 1, in the same transaction.
			$tr_id = self::insert_transition(
				array(
					'tenant_id'        => $tenant,
					'work_item_id'     => $id,
					'sequence'         => 1,
					'transition_id'    => 'create',
					'from_state'       => null,
					'to_state'         => $initial,
					'actor_kind'       => $actor['kind'],
					'actor_id'         => $actor['id'],
					'on_behalf_of'     => $actor['on_behalf_of'],
					'reason'           => isset( $args['reason'] ) ? (string) $args['reason'] : null,
					'guards_evaluated' => wp_json_encode( array() ),
					'evidence'         => isset( $args['evidence'] ) ? wp_json_encode( $args['evidence'] ) : null,
					'assurance_level'  => isset( $args['assurance_level'] ) ? (string) $args['assurance_level'] : null,
					'idempotency_key'  => $idem,
					'occurred_at'      => $now,
					'recorded_at'      => $now,
				)
			);
			if ( '' === $tr_id ) {
				self::rollback();
				return '';
			}

			// Genesis event to the outbox, in the same transaction.
			self::insert_outbox(
				array(
					'tenant_id'       => $tenant,
					'transition_id'   => $tr_id,
					'event_type'      => $work_type . '.created.v1',
					'visibility'      => 'internal',
					'envelope'        => wp_json_encode(
						self::build_envelope(
							array(
								'id'           => $id,
								'tenant_id'    => $tenant,
								'work_type'    => $work_type,
								'human_code'   => $human,
								'flow_version' => $flow_version,
							),
							array(
								'id'        => 'create',
								'from'      => null,
								'to'        => $initial,
								'sequence'  => 1,
								'actor'     => $actor,
								'tr_row_id' => $tr_id,
							),
							$work_type . '.created.v1',
							'internal',
							array(),
							array()
						)
					),
					'next_attempt_at' => $now,
				)
			);

			self::commit();

			// Initial refs AFTER commit — a ref conflict here means another item owns an external
			// record we tried to claim; discard our empty item and surface the conflict so the
			// caller can adopt the winner (the mint-lock retreat).
			if ( ! empty( $args['refs'] ) && is_array( $args['refs'] ) ) {
				foreach ( $args['refs'] as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$sys  = (string) ( $r['system'] ?? $r[0] ?? '' );
					$ent  = (string) ( $r['entity'] ?? $r[1] ?? '' );
					$ext  = (string) ( $r['external_id'] ?? $r[2] ?? '' );
					$meta = (array) ( $r['meta'] ?? $r[3] ?? array() );
					if ( '' === $sys || '' === $ent || '' === $ext ) {
						continue;
					}
					try {
						Zdz_Flow_Refs::put( $id, $sys, $ent, $ext, $meta );
					} catch ( Zdz_Flow_Ref_Conflict $e ) {
						self::discard_empty( $id );
						throw $e;
					}
				}
			}

			return $id;
		}

		/* ===================================================================
		 * TRANSITION — THE SOLE ONGOING STATE WRITER (INV-A).
		 * =================================================================== */

		/**
		 * Apply a transition. This is the only method that writes `state` after birth.
		 *
		 * $opts:
		 *   actor            (array)  {kind,id,on_behalf_of}.
		 *   idempotency_key  (string) request idempotency key; a replay returns the original result.
		 *   expected_state   (string) optimistic-concurrency guard (default: current state).
		 *   expected_version (int)    optimistic-concurrency guard (default: current version).
		 *   to / to_state    (string) target state when running definition-less (no state machine).
		 *   reason           (string), evidence (array), payload (array for the event data).
		 *   assurance_level  (string) recorded on the item + transition (never laundered).
		 *   guards           (array)  extra guard ids to evaluate when definition-less.
		 *   occurred_at      (string) when it happened (default now); recorded_at is always now.
		 *
		 * @return array|WP_Error  {ok,from,to,sequence,version,assurance_level} on success (or a
		 *                         replay), a WP_Error on not-found / unknown / illegal-from /
		 *                         guard-denied / 409-conflict.
		 */
		public static function transition( string $work_item_id, string $transition_id, array $opts = array() ) {
			global $wpdb;

			$item = self::get( $work_item_id );
			if ( null === $item ) {
				return new WP_Error( 'zdz_flow_not_found', 'Work item not found.', array( 'status' => 404 ) );
			}
			$transition_id = sanitize_key( $transition_id );
			$tenant        = (int) $item['tenant_id'];
			$actor         = self::normalize_actor( $opts['actor'] ?? array() );

			$idem_raw  = (string) ( $opts['idempotency_key'] ?? '' );
			$idem_hash = ( '' !== $idem_raw )
				? hash( 'sha256', $idem_raw )
				: hash( 'sha256', Zdz_Ulid::generate() ); // absent key => a distinct (non-idempotent) call

			// Request idempotency: a replay of a recorded key returns the original result, no new row.
			$prev = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT from_state, to_state, sequence, assurance_level FROM ' . Zdz_Flow_DB::transitions() .
					' WHERE tenant_id = %d AND work_item_id = %s AND idempotency_key = %s',
					$tenant,
					$work_item_id,
					$idem_hash
				),
				ARRAY_A
			);
			if ( is_array( $prev ) ) {
				return array(
					'ok'              => true,
					'replayed'        => true,
					'from'            => $prev['from_state'],
					'to'              => $prev['to_state'],
					'sequence'        => (int) $prev['sequence'],
					'version'         => (int) $item['version'],
					'assurance_level' => $prev['assurance_level'],
				);
			}

			// Resolve the transition — against the pinned definition, or in permissive mode.
			$def    = self::definition( $item['work_type'], (int) $item['flow_version'] );
			$guards = array();
			if ( $def ) {
				$t = self::find_transition( $def, $transition_id );
				if ( null === $t ) {
					return new WP_Error(
						'zdz_flow_unknown_transition',
						'Unknown transition for this work type.',
						array( 'status' => 422, 'transition' => $transition_id )
					);
				}
				$from_set = self::to_array( $t['from'] ?? array() );
				if ( ! empty( $from_set ) && ! in_array( $item['state'], $from_set, true ) ) {
					return new WP_Error(
						'zdz_flow_illegal_from',
						'Transition is not legal from the current state.',
						array( 'status' => 409, 'current_state' => $item['state'], 'from' => $from_set )
					);
				}
				$to     = sanitize_key( (string) ( $t['to'] ?? '' ) );
				$guards = isset( $t['guards'] ) && is_array( $t['guards'] ) ? $t['guards'] : array();
			} else {
				$to = sanitize_key( (string) ( $opts['to'] ?? $opts['to_state'] ?? '' ) );
				if ( '' === $to ) {
					return new WP_Error(
						'zdz_flow_no_target',
						'No flow definition and no explicit target state supplied.',
						array( 'status' => 422 )
					);
				}
				$guards = isset( $opts['guards'] ) && is_array( $opts['guards'] ) ? $opts['guards'] : array();
			}
			if ( '' === $to ) {
				return new WP_Error( 'zdz_flow_no_target', 'Transition resolves to an empty target state.', array( 'status' => 422 ) );
			}

			// Guards (pure predicates). A denial is fail-closed; an unreachable guard is a denial
			// unless it declares on_unavailable: allow (§4.3 — a visible config line, not an accident).
			list( $evaluated, $denied ) = self::eval_guards( $guards, $item, $opts );
			if ( null !== $denied ) {
				return new WP_Error(
					'zdz_flow_guard_denied',
					'A guard denied the transition.',
					array(
						'status'    => 409,
						'code'      => $denied['code'] ?? 'denied',
						'guard'     => $denied['id'] ?? '',
						'evaluated' => $evaluated,
					)
				);
			}

			$expected_state   = isset( $opts['expected_state'] ) ? (string) $opts['expected_state'] : (string) $item['state'];
			$expected_version = isset( $opts['expected_version'] ) ? (int) $opts['expected_version'] : (int) $item['version'];
			$assurance        = isset( $opts['assurance_level'] ) ? (string) $opts['assurance_level'] : (string) ( $item['assurance_level'] ?? '' );
			$now              = current_time( 'mysql', true );
			$occurred         = isset( $opts['occurred_at'] ) ? (string) $opts['occurred_at'] : $now;
			$wi               = Zdz_Flow_DB::work_items();

			self::begin();

			// Optimistic UPDATE — the materialized-state write. Zero affected rows => 409 conflict.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wi}
					 SET state = %s, version = version + 1, state_since = %s, assurance_level = %s
					 WHERE id = %s AND version = %d AND state = %s",
					$to,
					$now,
					$assurance,
					$work_item_id,
					$expected_version,
					$expected_state
				)
			);
			if ( false === $affected ) {
				self::rollback();
				return new WP_Error( 'zdz_flow_db_error', 'State update failed.', array( 'status' => 500 ) );
			}
			if ( 0 === (int) $affected ) {
				self::rollback();
				$cur = self::get( $work_item_id );
				return new WP_Error(
					'zdz_flow_conflict',
					'State changed underneath this request.',
					array(
						'status'          => 409,
						'current_state'   => $cur['state'] ?? null,
						'current_version' => isset( $cur['version'] ) ? (int) $cur['version'] : null,
					)
				);
			}

			$new_version = $expected_version + 1;

			// Append the transition row at MAX(sequence)+1 (the UNIQUE(work_item_id,sequence) guards
			// the ordering; the optimistic UPDATE already serialized concurrent writers by version).
			$seq   = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(MAX(sequence),0)+1 FROM ' . Zdz_Flow_DB::transitions() . ' WHERE work_item_id = %s',
					$work_item_id
				)
			);
			$tr_id = self::insert_transition(
				array(
					'tenant_id'        => $tenant,
					'work_item_id'     => $work_item_id,
					'sequence'         => $seq,
					'transition_id'    => $transition_id,
					'from_state'       => $expected_state,
					'to_state'         => $to,
					'actor_kind'       => $actor['kind'],
					'actor_id'         => $actor['id'],
					'on_behalf_of'     => $actor['on_behalf_of'],
					'reason'           => isset( $opts['reason'] ) ? (string) $opts['reason'] : null,
					'guards_evaluated' => wp_json_encode( $evaluated ),
					'evidence'         => isset( $opts['evidence'] ) ? wp_json_encode( $opts['evidence'] ) : null,
					'assurance_level'  => ( '' !== $assurance ) ? $assurance : null,
					'idempotency_key'  => $idem_hash,
					'occurred_at'      => $occurred,
					'recorded_at'      => $now,
				)
			);
			if ( '' === $tr_id ) {
				self::rollback();
				return new WP_Error( 'zdz_flow_log_failed', 'Could not append the transition log row.', array( 'status' => 500 ) );
			}

			// Timers declared by the transition (definition-driven) or supplied in $opts.
			self::apply_timers( $work_item_id, ( $def ? ( $t ?? array() ) : array() ), $opts, $now );

			// Outbox events — visibility-tagged, in the transition's transaction.
			$external_refs = self::refs_map( $work_item_id );
			$emits         = self::resolve_emits( $def ? ( $t ?? array() ) : array(), $item['work_type'], $transition_id );
			foreach ( $emits as $emit ) {
				$vis    = self::normalize_visibility( $emit['visibility'] ?? 'internal' );
				$etype  = (string) ( $emit['type'] ?? ( $item['work_type'] . '.' . $transition_id . '.v1' ) );
				$data   = isset( $opts['payload'] ) && is_array( $opts['payload'] ) ? $opts['payload'] : array();
				self::insert_outbox(
					array(
						'tenant_id'       => $tenant,
						'transition_id'   => $tr_id,
						'event_type'      => $etype,
						'visibility'      => $vis,
						'envelope'        => wp_json_encode(
							self::build_envelope(
								$item,
								array(
									'id'        => $transition_id,
									'from'      => $expected_state,
									'to'        => $to,
									'sequence'  => $seq,
									'actor'     => $actor,
									'tr_row_id' => $tr_id,
								),
								$etype,
								$vis,
								$data,
								$external_refs
							)
						),
						'next_attempt_at' => $now,
					)
				);
			}

			self::commit();

			return array(
				'ok'              => true,
				'from'            => $expected_state,
				'to'              => $to,
				'sequence'        => $seq,
				'version'         => $new_version,
				'assurance_level' => ( '' !== $assurance ) ? $assurance : null,
			);
		}

		/* ===================================================================
		 * READ + DERIVE
		 * =================================================================== */

		/** The work item row (state is the cached materialized view). */
		public static function get( string $work_item_id ): ?array {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . Zdz_Flow_DB::work_items() . ' WHERE id = %s', $work_item_id ),
				ARRAY_A
			);
			return is_array( $row ) ? $row : null;
		}

		/** The full transition log for an item, oldest first (the source of truth). */
		public static function history( string $work_item_id ): array {
			global $wpdb;
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . Zdz_Flow_DB::transitions() . ' WHERE work_item_id = %s ORDER BY sequence ASC',
					$work_item_id
				),
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * COMPUTE (never write) the state the work type's derivation rule yields from current
		 * inputs. Callers apply the result via a `recompute` transition, so the materialized `state`
		 * is still only ever written by transition() — INV-A holds even for derived-status types.
		 * The default is a no-op (returns the current state); the `project` five-branch derivation
		 * (B2) hooks `zdz_flow_derive_state`.
		 */
		public static function derive_state( string $work_item_id ): string {
			$item = self::get( $work_item_id );
			if ( null === $item ) {
				return '';
			}
			$def      = self::definition( $item['work_type'], (int) $item['flow_version'] );
			$computed = apply_filters( 'zdz_flow_derive_state', $item['state'], $item, $def );
			return (string) $computed;
		}

		/**
		 * Convenience: derive the state and, if it differs, apply it through a `recompute` system
		 * transition (preserving INV-A). Returns the transition result, or null if nothing changed.
		 */
		public static function recompute( string $work_item_id, array $opts = array() ) {
			$item = self::get( $work_item_id );
			if ( null === $item ) {
				return new WP_Error( 'zdz_flow_not_found', 'Work item not found.', array( 'status' => 404 ) );
			}
			$computed = self::derive_state( $work_item_id );
			if ( '' === $computed || $computed === $item['state'] ) {
				return null;
			}
			$opts['to']    = $computed;
			$opts['actor'] = $opts['actor'] ?? array( 'kind' => 'system', 'id' => null );
			if ( empty( $opts['idempotency_key'] ) ) {
				$opts['idempotency_key'] = 'recompute:' . $work_item_id . ':' . $item['version'] . ':' . $computed;
			}
			return self::transition( $work_item_id, 'recompute', $opts );
		}

		/* ===================================================================
		 * DISPOSITION (INV-B) — no exit without a logged reason.
		 * =================================================================== */

		/**
		 * Record one disposition row (a drop / skip / dedupe / timeout / filter). Idempotent on
		 * (run_id, subject_kind, subject_key, stage). Also fired on the `zdz_flow_disposition`
		 * action so existing app code (which already emits it) shares one ledger.
		 *
		 * $args: funnel, run_id, subject_kind, subject_key, stage, code, retryable?, retry_after?,
		 *        detail?, decided_by.
		 */
		public static function disposition( array $args ): void {
			global $wpdb;
			$now         = current_time( 'mysql', true );
			$retry_after = isset( $args['retry_after'] ) ? (string) $args['retry_after'] : null;
			$detail      = isset( $args['detail'] ) ? wp_json_encode( $args['detail'] ) : null;

			// Nullable columns render as SQL NULL literals — a %s placeholder would coerce PHP null to
			// '', which is invalid for the nullable DATETIME (retry_after) under strict SQL mode.
			$sql = 'INSERT IGNORE INTO ' . Zdz_Flow_DB::dispositions() . '
				 (tenant_id, funnel, run_id, subject_kind, subject_key, stage, code, retryable, retry_after, detail, decided_by, created_at)
				 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, '
				. ( null === $retry_after ? 'NULL' : '%s' ) . ', '
				. ( null === $detail ? 'NULL' : '%s' ) . ', %s, %s)';

			$params = array(
				isset( $args['tenant_id'] ) ? (int) $args['tenant_id'] : Zdz_Flow_DB::tenant_id(),
				(string) ( $args['funnel'] ?? '' ),
				(string) ( $args['run_id'] ?? '' ),
				(string) ( $args['subject_kind'] ?? '' ),
				substr( (string) ( $args['subject_key'] ?? '' ), 0, 191 ),
				(string) ( $args['stage'] ?? '' ),
				(string) ( $args['code'] ?? '' ),
				! empty( $args['retryable'] ) ? 1 : 0,
			);
			if ( null !== $retry_after ) {
				$params[] = $retry_after;
			}
			if ( null !== $detail ) {
				$params[] = $detail;
			}
			$params[] = (string) ( $args['decided_by'] ?? '' );
			$params[] = $now;

			$wpdb->query( $wpdb->prepare( $sql, $params ) );
			do_action( 'zdz_flow_disposition', $args );
		}

		/**
		 * Funnel conservation check (INV-B): a run may be marked complete only when
		 * inputs == advanced + dispositions. Returns true when the accounting balances.
		 */
		public static function funnel_conserved( string $run_id, int $inputs, int $advanced ): bool {
			global $wpdb;
			$dispositions = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Zdz_Flow_DB::dispositions() . ' WHERE run_id = %s',
					$run_id
				)
			);
			return ( $inputs === $advanced + $dispositions );
		}

		/* ===================================================================
		 * EMIT / OUTBOX
		 * =================================================================== */

		/**
		 * Emit an event to the outbox against an item's latest transition. Public for effect
		 * authors; transition() writes its own events inline in the transaction.
		 */
		public static function emit( string $work_item_id, string $event_type, string $visibility, array $data ): void {
			$item = self::get( $work_item_id );
			if ( null === $item ) {
				return;
			}
			$last = self::latest_transition( $work_item_id );
			if ( null === $last ) {
				return; // an item always has a genesis transition; nothing to anchor to otherwise.
			}
			$vis = self::normalize_visibility( $visibility );
			$now = current_time( 'mysql', true );
			self::insert_outbox(
				array(
					'tenant_id'       => (int) $item['tenant_id'],
					'transition_id'   => (string) $last['id'],
					'event_type'      => $event_type,
					'visibility'      => $vis,
					'envelope'        => wp_json_encode(
						self::build_envelope(
							$item,
							array(
								'id'        => (string) $last['transition_id'],
								'from'      => $last['from_state'],
								'to'        => $last['to_state'],
								'sequence'  => (int) $last['sequence'],
								'actor'     => self::normalize_actor(
									array(
										'kind'         => $last['actor_kind'],
										'id'           => $last['actor_id'],
										'on_behalf_of' => $last['on_behalf_of'],
									)
								),
								'tr_row_id' => (string) $last['id'],
							),
							$event_type,
							$vis,
							$data,
							self::refs_map( $work_item_id )
						)
					),
					'next_attempt_at' => $now,
				)
			);
		}

		/**
		 * Render a display-only human code from a tenant template. NEVER parsed, NEVER a key.
		 * Tokens: {YYYY} {YY} {MM} {DD} are built-in; every other {token} comes from $ctx (which
		 * wins over the built-ins). Unknown tokens render as empty. Capped to 64 chars.
		 */
		public static function human_code( string $template, array $ctx ): string {
			$now     = current_time( 'timestamp', true );
			$builtin = array(
				'YYYY' => gmdate( 'Y', $now ),
				'YY'   => gmdate( 'y', $now ),
				'MM'   => gmdate( 'm', $now ),
				'DD'   => gmdate( 'd', $now ),
			);
			$vars = $builtin;
			foreach ( $ctx as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$vars[ (string) $k ] = (string) $v;
				}
			}
			$out = preg_replace_callback(
				'/\{([A-Za-z0-9_]+)\}/',
				static function ( $m ) use ( $vars ) {
					return array_key_exists( $m[1], $vars ) ? $vars[ $m[1] ] : '';
				},
				$template
			);
			return substr( (string) $out, 0, 64 );
		}

		/* ===================================================================
		 * TIMER HELPERS — keyed to fire once (§4.10 layer 4).
		 * =================================================================== */

		/** Start (or reschedule) a keyed timer. fire_key = sha256(item|timer|scheduled_for). */
		public static function start_timer( string $work_item_id, string $timer_id, string $fires_at ): void {
			global $wpdb;
			$timer_id = sanitize_key( $timer_id );
			$fire_key = hash( 'sha256', $work_item_id . '|' . $timer_id . '|' . $fires_at );
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . Zdz_Flow_DB::timers() . '
					 (work_item_id, timer_id, fires_at, extended_count, extended_total_s, fired_at, fire_key)
					 VALUES (%s, %s, %s, 0, 0, NULL, %s)
					 ON DUPLICATE KEY UPDATE fires_at = VALUES(fires_at), fire_key = VALUES(fire_key), fired_at = NULL',
					$work_item_id,
					$timer_id,
					$fires_at,
					$fire_key
				)
			);
		}

		/** Cancel a timer (removes the row). */
		public static function cancel_timer( string $work_item_id, string $timer_id ): void {
			global $wpdb;
			$wpdb->delete(
				Zdz_Flow_DB::timers(),
				array( 'work_item_id' => $work_item_id, 'timer_id' => sanitize_key( $timer_id ) ),
				array( '%s', '%s' )
			);
		}

		/**
		 * Extend a timer's deadline (accumulating). The idempotency of an extend must be enforced by
		 * the caller's request key (an accumulating effect must carry one) — this method mutates.
		 */
		public static function extend_timer( string $work_item_id, string $timer_id, int $seconds ): void {
			global $wpdb;
			$timer_id = sanitize_key( $timer_id );
			$seconds  = max( 0, $seconds );
			$new_key  = hash( 'sha256', $work_item_id . '|' . $timer_id . '|extend|' . microtime( true ) );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Zdz_Flow_DB::timers() . "
					 SET fires_at = DATE_ADD(fires_at, INTERVAL %d SECOND),
					     extended_count = extended_count + 1,
					     extended_total_s = extended_total_s + %d,
					     fire_key = %s
					 WHERE work_item_id = %s AND timer_id = %s",
					$seconds,
					$seconds,
					$new_key,
					$work_item_id,
					$timer_id
				)
			);
		}

		/** Timers due to fire (unfired, past due). */
		public static function due_timers( int $limit = 50 ): array {
			global $wpdb;
			$now  = current_time( 'mysql', true );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . Zdz_Flow_DB::timers() . '
					 WHERE fired_at IS NULL AND fires_at <= %s
					 ORDER BY fires_at ASC LIMIT %d',
					$now,
					max( 1, $limit )
				),
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Atomically claim a timer's firing. Returns true only for the caller that won the claim,
		 * so a cron that runs twice fires the timer once.
		 */
		public static function fire_timer( string $work_item_id, string $timer_id ): bool {
			global $wpdb;
			$now      = current_time( 'mysql', true );
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Zdz_Flow_DB::timers() . '
					 SET fired_at = %s
					 WHERE work_item_id = %s AND timer_id = %s AND fired_at IS NULL',
					$now,
					$work_item_id,
					sanitize_key( $timer_id )
				)
			);
			return ( 1 === (int) $affected );
		}

		/* ===================================================================
		 * DISCARD (mint-lock retreat helper)
		 * =================================================================== */

		/**
		 * Discard a freshly-minted, never-advanced work item (version 0, only its genesis
		 * transition). Used by the mint-lock retreat: a lost-race writer discards its empty item and
		 * adopts the winner. Refuses (returns false) to delete an item that has advanced — a real
		 * work item is never silently destroyed.
		 */
		public static function discard_empty( string $work_item_id ): bool {
			global $wpdb;
			$item = self::get( $work_item_id );
			if ( null === $item ) {
				return false;
			}
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Zdz_Flow_DB::transitions() . ' WHERE work_item_id = %s',
					$work_item_id
				)
			);
			if ( (int) $item['version'] > 0 || $count > 1 ) {
				return false; // it advanced — not empty, do not destroy.
			}

			self::begin();
			// Remove the genesis event(s) so no consumer sees a `created` for a discarded item.
			$tr_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . Zdz_Flow_DB::transitions() . ' WHERE work_item_id = %s',
					$work_item_id
				)
			);
			if ( ! empty( $tr_ids ) ) {
				$in = implode( ',', array_fill( 0, count( $tr_ids ), '%s' ) );
				$wpdb->query(
					$wpdb->prepare( 'DELETE FROM ' . Zdz_Flow_DB::outbox() . " WHERE transition_id IN ($in)", $tr_ids )
				);
			}
			$wpdb->delete( Zdz_Flow_DB::transitions(), array( 'work_item_id' => $work_item_id ), array( '%s' ) );
			$wpdb->delete( Zdz_Flow_DB::refs(), array( 'work_item_id' => $work_item_id ), array( '%s' ) );
			$wpdb->delete( Zdz_Flow_DB::timers(), array( 'work_item_id' => $work_item_id ), array( '%s' ) );
			$wpdb->delete( Zdz_Flow_DB::work_items(), array( 'id' => $work_item_id ), array( '%s' ) );
			self::commit();
			return true;
		}

		/* ===================================================================
		 * INTERNALS
		 * =================================================================== */

		private static function begin(): void {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
		}
		private static function commit(): void {
			global $wpdb;
			$wpdb->query( 'COMMIT' );
		}
		private static function rollback(): void {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
		}

		/** Insert one transition row; returns its ULID, or '' on failure. */
		private static function insert_transition( array $t ): string {
			global $wpdb;
			$id = $t['id'] ?? Zdz_Ulid::generate();
			$ok = $wpdb->insert(
				Zdz_Flow_DB::transitions(),
				array(
					'id'               => $id,
					'tenant_id'        => (int) $t['tenant_id'],
					'work_item_id'     => (string) $t['work_item_id'],
					'sequence'         => (int) $t['sequence'],
					'transition_id'    => (string) $t['transition_id'],
					'from_state'       => $t['from_state'],
					'to_state'         => (string) $t['to_state'],
					'actor_kind'       => (string) $t['actor_kind'],
					'actor_id'         => $t['actor_id'],
					'on_behalf_of'     => $t['on_behalf_of'],
					'reason'           => $t['reason'],
					'guards_evaluated' => $t['guards_evaluated'],
					'evidence'         => $t['evidence'],
					'assurance_level'  => $t['assurance_level'],
					'idempotency_key'  => (string) $t['idempotency_key'],
					'occurred_at'      => (string) $t['occurred_at'],
					'recorded_at'      => (string) $t['recorded_at'],
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return $ok ? (string) $id : '';
		}

		/** Insert one outbox row; returns its ULID, or '' on failure. */
		private static function insert_outbox( array $o ): string {
			global $wpdb;
			$id = Zdz_Ulid::generate();
			$ok = $wpdb->insert(
				Zdz_Flow_DB::outbox(),
				array(
					'id'              => $id,
					'tenant_id'       => (int) $o['tenant_id'],
					'transition_id'   => (string) $o['transition_id'],
					'event_type'      => (string) $o['event_type'],
					'visibility'      => (string) $o['visibility'],
					'envelope'        => $o['envelope'],
					'status'          => 'pending',
					'attempts'        => 0,
					'next_attempt_at' => (string) $o['next_attempt_at'],
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			return $ok ? (string) $id : '';
		}

		/** Build the §4.5 event envelope. $meta carries the transition summary + actor. */
		private static function build_envelope( array $item, array $meta, string $event_type, string $visibility, array $data, array $external_refs ): array {
			$tenant        = (int) ( $item['tenant_id'] ?? Zdz_Flow_DB::tenant_id() );
			$work_type     = (string) ( $item['work_type'] ?? '' );
			$work_item_id  = (string) ( $item['id'] ?? '' );
			$subject       = 'flow://' . $tenant . '/' . $work_type . '/' . $work_item_id;
			$actor         = self::normalize_actor( $meta['actor'] ?? array() );
			$actor_digest  = $actor['kind'] . ':' . (string) ( $actor['id'] ?? '' );
			$payload_dig   = substr( hash( 'sha256', (string) wp_json_encode( $data ) ), 0, 32 );
			$transition_id = (string) ( $meta['id'] ?? '' );
			$idem          = hash( 'sha256', $subject . '|' . $transition_id . '|' . $actor_digest . '|' . $payload_dig );
			$now_iso       = gmdate( 'c' );

			return array(
				'specversion'    => '1.0',
				'id'             => Zdz_Ulid::generate(),
				'type'           => $event_type,
				'tenant'         => $tenant,
				'subject'        => $subject,
				'time'           => $now_iso,
				'recorded_at'    => $now_iso,
				'sequence'       => (int) ( $meta['sequence'] ?? 0 ),
				'idempotency_key' => $idem,
				'actor'          => $actor,
				'transition'     => array(
					'id'           => $transition_id,
					'from'         => $meta['from'] ?? null,
					'to'           => $meta['to'] ?? null,
					'flow_version' => (int) ( $item['flow_version'] ?? 0 ),
				),
				'data'           => array(
					'work_item' => array(
						'id'         => $work_item_id,
						'human_code' => (string) ( $item['human_code'] ?? '' ),
						'work_type'  => $work_type,
					),
				) + $data,
				'external_refs'  => $external_refs,
				'visibility'     => self::normalize_visibility( $visibility ),
			);
		}

		/** The item's external refs as a { "system.entity": external_id } map (for the envelope). */
		private static function refs_map( string $work_item_id ): array {
			$out = array();
			foreach ( Zdz_Flow_Refs::for( $work_item_id ) as $r ) {
				$out[ $r['system'] . '.' . $r['entity'] ] = $r['external_id'];
			}
			return $out;
		}

		/** The latest (highest-sequence) transition row for an item, or null. */
		private static function latest_transition( string $work_item_id ): ?array {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Zdz_Flow_DB::transitions() . ' WHERE work_item_id = %s ORDER BY sequence DESC LIMIT 1',
					$work_item_id
				),
				ARRAY_A
			);
			return is_array( $row ) ? $row : null;
		}

		/** Normalize an actor spec to {kind,id,on_behalf_of} with a validated kind. */
		private static function normalize_actor( $actor ): array {
			$actor = is_array( $actor ) ? $actor : array();
			$kind  = (string) ( $actor['kind'] ?? 'system' );
			if ( ! in_array( $kind, self::ACTOR_KINDS, true ) ) {
				$kind = 'system';
			}
			$id  = $actor['id'] ?? null;
			$obo = $actor['on_behalf_of'] ?? null;
			return array(
				'kind'         => $kind,
				'id'           => ( null === $id || '' === $id ) ? null : (string) $id,
				'on_behalf_of' => ( null === $obo || '' === $obo ) ? null : (string) $obo,
			);
		}

		/** Clamp a visibility to the enum (default internal — the most-restrictive tier). */
		private static function normalize_visibility( $vis ): string {
			$vis = (string) $vis;
			return in_array( $vis, self::VISIBILITIES, true ) ? $vis : 'internal';
		}

		/** root_id: an explicit one wins; else the parent's root; else the item is its own root. */
		private static function resolve_root( string $id, ?string $parent_id, $explicit_root ): string {
			if ( is_string( $explicit_root ) && Zdz_Ulid::is_valid( $explicit_root ) ) {
				return $explicit_root;
			}
			if ( null !== $parent_id ) {
				$parent = self::get( $parent_id );
				if ( $parent && ! empty( $parent['root_id'] ) ) {
					return (string) $parent['root_id'];
				}
				return $parent_id;
			}
			return $id;
		}

		/** Resolve the display code: an explicit value wins; else render the tenant template. */
		private static function resolve_human_code( string $work_type, array $args ): string {
			if ( isset( $args['human_code'] ) && '' !== (string) $args['human_code'] ) {
				return substr( (string) $args['human_code'], 0, 64 );
			}
			$template = (string) apply_filters( 'zdz_flow_human_code_template', '{prefix}-{YYYY}-{NNNN}', $work_type, $args );
			$ctx      = isset( $args['human_code_ctx'] ) && is_array( $args['human_code_ctx'] ) ? $args['human_code_ctx'] : array();
			return self::human_code( $template, $ctx );
		}

		/* ── definitions + guards ─────────────────────────────────────────── */

		/**
		 * Resolve a flow definition for (work_type[, version]). Prefers the in-memory registry
		 * (the `zdz_flow_definitions` filter — apps register specs without seeding the DB), then the
		 * wp_zdz_flow_definitions table. Returns null when none is registered (permissive mode).
		 */
		public static function definition( string $work_type, ?int $version = null ): ?array {
			$registry = apply_filters( 'zdz_flow_definitions', array() );
			if ( is_array( $registry ) && isset( $registry[ $work_type ] ) && is_array( $registry[ $work_type ] ) ) {
				$spec = $registry[ $work_type ];
				if ( null === $version || (int) ( $spec['version'] ?? 0 ) === (int) $version ) {
					return self::normalize_definition( $spec, $work_type );
				}
			}

			global $wpdb;
			$table  = Zdz_Flow_DB::definitions();
			$tenant = Zdz_Flow_DB::tenant_id();
			if ( null !== $version ) {
				$json = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT spec FROM {$table} WHERE tenant_id = %d AND work_type = %s AND version = %d",
						$tenant,
						$work_type,
						$version
					)
				);
			} else {
				$json = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT spec FROM {$table} WHERE tenant_id = %d AND work_type = %s ORDER BY version DESC LIMIT 1",
						$tenant,
						$work_type
					)
				);
			}
			if ( null === $json ) {
				return null;
			}
			$spec = json_decode( (string) $json, true );
			return is_array( $spec ) ? self::normalize_definition( $spec, $work_type ) : null;
		}

		/** Ensure a spec has work_type/version/states/transitions keys. */
		private static function normalize_definition( array $spec, string $work_type ): array {
			$spec['work_type']   = (string) ( $spec['work_type'] ?? $work_type );
			$spec['version']     = (int) ( $spec['version'] ?? 0 );
			$spec['states']      = isset( $spec['states'] ) && is_array( $spec['states'] ) ? $spec['states'] : array();
			$spec['transitions'] = isset( $spec['transitions'] ) && is_array( $spec['transitions'] ) ? $spec['transitions'] : array();
			return $spec;
		}

		/** The definition's initial state (initial:true), else the first state id, else ''. */
		private static function initial_state( array $def ): string {
			$first = '';
			foreach ( (array) ( $def['states'] ?? array() ) as $s ) {
				$sid = is_array( $s ) ? (string) ( $s['id'] ?? '' ) : (string) $s;
				if ( '' === $first && '' !== $sid ) {
					$first = $sid;
				}
				if ( is_array( $s ) && ! empty( $s['initial'] ) && '' !== $sid ) {
					return $sid;
				}
			}
			return $first;
		}

		/** Find a transition spec by id within a definition. */
		private static function find_transition( array $def, string $transition_id ): ?array {
			foreach ( (array) ( $def['transitions'] ?? array() ) as $t ) {
				if ( is_array( $t ) && (string) ( $t['id'] ?? '' ) === $transition_id ) {
					return $t;
				}
			}
			return null;
		}

		/**
		 * Evaluate a transition's declared guards. Returns [ evaluated[], denied|null ]. A guard is
		 * resolved from the `zdz_flow_guards` registry (id => callable). An unresolvable guard is a
		 * denial (fail-closed) unless it declares on_unavailable: allow.
		 */
		private static function eval_guards( array $guards, array $item, array $opts ): array {
			$registry  = apply_filters( 'zdz_flow_guards', array() );
			$registry  = is_array( $registry ) ? $registry : array();
			$evaluated = array();
			$denied    = null;

			foreach ( $guards as $g ) {
				$gid          = is_array( $g ) ? (string) ( $g['id'] ?? '' ) : (string) $g;
				$on_unavail   = is_array( $g ) ? (string) ( $g['on_unavailable'] ?? 'deny' ) : 'deny';
				if ( '' === $gid ) {
					continue;
				}
				if ( ! isset( $registry[ $gid ] ) || ! is_callable( $registry[ $gid ] ) ) {
					$res = ( 'allow' === $on_unavail )
						? array( 'allow' => true, 'code' => 'unavailable_allowed' )
						: array( 'allow' => false, 'code' => 'unavailable' );
				} else {
					$r = call_user_func( $registry[ $gid ], $item, $opts );
					if ( is_bool( $r ) ) {
						$res = array( 'allow' => $r, 'code' => $r ? 'ok' : 'denied' );
					} elseif ( is_array( $r ) ) {
						$res = array(
							'allow'    => ! empty( $r['allow'] ),
							'code'     => (string) ( $r['code'] ?? ( empty( $r['allow'] ) ? 'denied' : 'ok' ) ),
							'message'  => (string) ( $r['message'] ?? '' ),
							'evidence' => $r['evidence'] ?? null,
						);
					} else {
						$res = array( 'allow' => false, 'code' => 'invalid_guard_result' );
					}
				}
				$res['id']   = $gid;
				$evaluated[] = $res;
				if ( empty( $res['allow'] ) && null === $denied ) {
					$denied = $res;
				}
			}
			return array( $evaluated, $denied );
		}

		/** Start/stop timers a transition declares (definition-driven) or supplies via $opts. */
		private static function apply_timers( string $work_item_id, array $t, array $opts, string $now ): void {
			$starts = array();
			$stops  = array();

			// Definition form: transition.timers = { start:[...], stop:[...] } or a flat start list.
			if ( isset( $t['timers'] ) && is_array( $t['timers'] ) ) {
				if ( isset( $t['timers']['start'] ) ) {
					$starts = array_merge( $starts, (array) $t['timers']['start'] );
				}
				if ( isset( $t['timers']['stop'] ) ) {
					$stops = array_merge( $stops, (array) $t['timers']['stop'] );
				}
				if ( ! isset( $t['timers']['start'] ) && ! isset( $t['timers']['stop'] ) ) {
					$starts = array_merge( $starts, $t['timers'] );
				}
			}
			// Opts form: explicit start/stop instructions from the caller.
			if ( isset( $opts['timers']['start'] ) && is_array( $opts['timers']['start'] ) ) {
				$starts = array_merge( $starts, $opts['timers']['start'] );
			}
			if ( isset( $opts['timers']['stop'] ) && is_array( $opts['timers']['stop'] ) ) {
				$stops = array_merge( $stops, $opts['timers']['stop'] );
			}

			foreach ( $starts as $s ) {
				if ( is_array( $s ) ) {
					$tid   = (string) ( $s['id'] ?? $s['timer'] ?? '' );
					$fires = (string) ( $s['fires_at'] ?? '' );
				} else {
					$tid   = (string) $s;
					$fires = '';
				}
				if ( '' === $tid || '' === $fires ) {
					continue; // a timer with no scheduled_for is a no-op (the definition must supply it).
				}
				self::start_timer( $work_item_id, $tid, $fires );
			}
			foreach ( $stops as $s ) {
				$tid = is_array( $s ) ? (string) ( $s['id'] ?? $s['timer'] ?? '' ) : (string) $s;
				if ( '' !== $tid ) {
					self::cancel_timer( $work_item_id, $tid );
				}
			}
			unset( $now );
		}

		/**
		 * The events a transition emits. A definition may declare `emit` (a string, a list, or a
		 * list of {type,visibility}); with none declared, a single default event is emitted.
		 */
		private static function resolve_emits( array $t, string $work_type, string $transition_id ): array {
			$default = array( array( 'type' => $work_type . '.' . $transition_id . '.v1', 'visibility' => 'internal' ) );
			if ( empty( $t['emit'] ) ) {
				return $default;
			}
			$emit = $t['emit'];
			$out  = array();
			foreach ( (array) $emit as $e ) {
				if ( is_string( $e ) ) {
					$out[] = array( 'type' => $e, 'visibility' => 'internal' );
				} elseif ( is_array( $e ) ) {
					$out[] = array(
						'type'       => (string) ( $e['type'] ?? ( $work_type . '.' . $transition_id . '.v1' ) ),
						'visibility' => (string) ( $e['visibility'] ?? 'internal' ),
					);
				}
			}
			return ! empty( $out ) ? $out : $default;
		}

		/** Coerce a value that may be a scalar or a list into a list of strings. */
		private static function to_array( $v ): array {
			if ( is_array( $v ) ) {
				return array_values( array_map( 'strval', $v ) );
			}
			return ( '' === (string) $v ) ? array() : array( (string) $v );
		}
	}
}
