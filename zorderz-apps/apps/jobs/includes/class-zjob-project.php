<?php
/**
 * Zjob_Project — the Projects container, implemented as the first work_type on the Flow substrate.
 *
 * A Project IS a `work_item` (work_type = 'project') created through Zdz_Flow::create(). It is NOT a
 * new table — it is a thin facade over the committed Flow writer (class-zdz-flow.php). Everything a
 * Project references (its estimate, invoice, appointments, cuts, receipts, surveys, conversations,
 * CRM lead, and its own child jobs) is recorded in the namespaced ref map (Zdz_Flow_Refs), keyed by
 * (system, entity, external_id) — NEVER a bare document number (estimate #5982 is not invoice #5982).
 *
 * INVARIANTS this class is built around (do not "simplify" any away):
 *   - INV-A — derived status is a MATERIALIZED VIEW. The five-branch derivation is computed by the
 *     `zdz_flow_derive_state` callback and applied ONLY through Zdz_Flow::transition() (via
 *     refresh_status()). There is NO status setter anywhere in this class. Project status is
 *     work_items.state, and Zdz_Flow::transition() is its sole writer.
 *   - human_code (PRJ-YYYY-NNNN) is DISPLAY-ONLY, minted from a tenant template, never parsed, never
 *     a key. The sayable sequence comes from an atomic per-year counter option; real uniqueness is
 *     the work item's natural_key + the ref UNIQUE(system,entity,external_id). human_code is NOT a
 *     UNIQUE column, so a display collision is harmless (and no code='' poison is possible).
 *   - keys are (system, entity, external_id). extract_doc_ident() refuses a bare number.
 *   - a mint race resolves in the DB: ensure() adopts the winner a Zdz_Flow_Ref_Conflict names.
 *   - bookkeeping does not break the work: the audit-seam subscriber swallows its own exceptions
 *     ("placing a Job in a container is bookkeeping, and bookkeeping does not get to break the work").
 *
 * Ships EMPTY: it names no company/person/product/place/provider. It registers the GENERIC ref
 * namespaces ([CORE]); the provider-named ones (a billing provider's estimate/invoice, a CRM's lead)
 * are [IDENTITY] the connections pack adds through the SAME `zdz_work_item_ref_namespaces` filter. The
 * `project` flow definition is a [CORE] default (tenants may narrow the derivation). Nothing seeds on
 * activation.
 *
 * Promotion path: when Flow is promoted to a Core service, this facade moves beside it unchanged.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/flow/class-zdz-flow.php';

if ( ! class_exists( 'Zjob_Project' ) ) {

	class Zjob_Project {

		/** The work_type this container lives on. */
		const WORK_TYPE = 'project';

		/** The pinned flow definition version (bumped only on a definition change). */
		const FLOW_VERSION = 1;

		/** The five DERIVED project states (a materialized view of child-job statuses — no setter). */
		const STATES = array( 'open', 'active', 'waiting', 'completed', 'cancelled' );

		/**
		 * The Core-default human_code prefix. `PRJ` is a generic project label, not a company/person/
		 * product/place/provider name; the full shape is [IDENTITY→document-conventions] and a tenant
		 * may override the template via the `zdz_flow_human_code_template` filter.
		 */
		const CODE_PREFIX_DEFAULT = 'PRJ';

		/**
		 * The GENERIC ref namespaces this container registers, as system => entity. The `entity`
		 * disambiguates document numbers across systems so estimate #5982 and invoice #5982 never
		 * collide. `job/handoff` is the container's own internal child-linkage namespace. Provider-
		 * named namespaces (a billing provider's estimate/invoice, a CRM's lead) are NOT here — the
		 * connections pack adds them through the same filter ([IDENTITY]).
		 */
		const NAMESPACES = array(
			'estimate'     => 'document',
			'invoice'      => 'document',
			'appointment'  => 'event',
			'cut'          => 'sheet',
			'receipt'      => 'document',
			'survey'       => 'response',
			'conversation' => 'thread',
			'lead'         => 'record',
			'job'          => 'handoff',
		);

		/**
		 * The ONE literal refusal the read path returns for a missing project OR a viewer with no
		 * relationship — a single string so two different responses cannot enumerate ids
		 * (the existence-oracle stays closed).
		 */
		const REFUSAL = 'not_available';

		/* ===================================================================
		 * INIT — register the filters this class owns. Nothing seeds; no DB write.
		 * =================================================================== */

		/**
		 * Hook the ref-namespace enum, the `project` flow definition, and the derived-status callback.
		 * Also subscribe the (out-of-model) audit seams so a new/changed Job joins and refreshes its
		 * Project once the jobs model fires them. Idempotent: safe to call on init and after_setup_theme.
		 */
		public static function init(): void {
			if ( ! function_exists( 'add_filter' ) ) {
				return;
			}
			add_filter( 'zdz_work_item_ref_namespaces', array( __CLASS__, 'register_namespaces' ) );
			add_filter( 'zdz_flow_definitions', array( __CLASS__, 'register_definition' ) );
			add_filter( 'zdz_flow_derive_state', array( __CLASS__, 'derive_state_cb' ), 10, 3 );

			// The Jobs model never learns Projects exist; it fires a seam and this subscriber reacts.
			// (These actions are declared by the jobs model / orchestrator — an S3-07 seam. Subscribing
			// before they fire is a harmless no-op, and keeps the wiring in one place.)
			if ( function_exists( 'add_action' ) ) {
				add_action( 'zjob_handoff_created', array( __CLASS__, 'on_job_changed' ), 10, 1 );
				add_action( 'zjob_job_audited', array( __CLASS__, 'on_job_changed' ), 10, 1 );
			}
		}

		/**
		 * Register the generic ref namespaces ([CORE]). Merges, never replaces, so the connections
		 * pack can add its provider-named ([IDENTITY]) namespaces through the same filter.
		 *
		 * @param mixed $list the accumulating namespace list.
		 * @return array
		 */
		public static function register_namespaces( $list ): array {
			$out = is_array( $list ) ? $list : array();
			foreach ( self::NAMESPACES as $system => $entity ) {
				$out[] = $system . '/' . $entity;
			}
			return $out;
		}

		/**
		 * Register the [CORE]-default `project` flow definition.
		 *
		 * The five states are the derived-status set; the five `to_*` transitions each carry a fixed
		 * target and NO `from` (legal from any state), because refresh_status() applies the derivation
		 * by choosing the transition whose target matches the computed status. This is how a
		 * materialized-view status still flows through the sole state writer (INV-A): the committed
		 * substrate reads a transition's target from the definition when one is registered, so a
		 * derived-status work type declares one transition per target rather than a single dynamic one.
		 *
		 * @param mixed $registry work_type => spec.
		 * @return array
		 */
		public static function register_definition( $registry ): array {
			$registry = is_array( $registry ) ? $registry : array();
			if ( isset( $registry[ self::WORK_TYPE ] ) ) {
				return $registry; // already declared (a tenant override wins).
			}
			$emit = array( array( 'type' => 'project.status.v1', 'visibility' => 'internal' ) );
			$registry[ self::WORK_TYPE ] = array(
				'work_type'   => self::WORK_TYPE,
				'version'     => self::FLOW_VERSION,
				'states'      => array(
					array( 'id' => 'open', 'initial' => true ),
					array( 'id' => 'active' ),
					array( 'id' => 'waiting' ),
					array( 'id' => 'completed' ),
					array( 'id' => 'cancelled' ),
				),
				'transitions' => array(
					array( 'id' => 'to_open', 'to' => 'open', 'emit' => $emit ),
					array( 'id' => 'to_active', 'to' => 'active', 'emit' => $emit ),
					array( 'id' => 'to_waiting', 'to' => 'waiting', 'emit' => $emit ),
					array( 'id' => 'to_completed', 'to' => 'completed', 'emit' => $emit ),
					array( 'id' => 'to_cancelled', 'to' => 'cancelled', 'emit' => $emit ),
				),
			);
			return $registry;
		}

		/* ===================================================================
		 * DERIVED STATUS — the five-branch materialized view (INV-A).
		 * =================================================================== */

		/**
		 * The `zdz_flow_derive_state` callback. COMPUTES (never writes) the status a project's child
		 * jobs yield. Non-project work types pass through untouched.
		 *
		 * @param string $state current cached state (returned unchanged for non-projects).
		 * @param array  $item  the work_item row.
		 * @param mixed  $def   the resolved definition (unused; the derivation is over children).
		 * @return string
		 */
		public static function derive_state_cb( $state, $item, $def ): string {
			unset( $def );
			if ( ! is_array( $item ) || ( $item['work_type'] ?? '' ) !== self::WORK_TYPE ) {
				return (string) $state;
			}
			return self::status_from_counts( self::counts_for( (string) $item['id'] ) );
		}

		/**
		 * The five-branch derivation, as a pure function of the per-status child-job counts:
		 *   (empty)         -> open        a project with no jobs yet is open, not completed.
		 *   all cancelled   -> cancelled
		 *   all resolved    -> completed   every job is done|cancelled (and not all cancelled).
		 *   any pending     -> waiting     a job sits in pending_close.
		 *   any active      -> active      a job is in_progress.
		 *   else            -> open        (e.g. an untouched or mixed open/done set).
		 *
		 * @param array<string,int> $counts status => count (job statuses).
		 * @return string one of self::STATES.
		 */
		public static function status_from_counts( array $counts ): string {
			$open      = (int) ( $counts['open'] ?? 0 );
			$active    = (int) ( $counts['in_progress'] ?? 0 );
			$pending   = (int) ( $counts['pending_close'] ?? 0 );
			$done      = (int) ( $counts['done'] ?? 0 );
			$cancelled = (int) ( $counts['cancelled'] ?? 0 );
			$total     = $open + $active + $pending + $done + $cancelled;

			if ( $total === 0 ) {
				return 'open';
			}
			if ( $cancelled === $total ) {
				return 'cancelled';
			}
			if ( ( $done + $cancelled ) === $total ) {
				return 'completed';
			}
			if ( $pending > 0 ) {
				return 'waiting';
			}
			if ( $active > 0 ) {
				return 'active';
			}
			return 'open';
		}

		/**
		 * Apply the derived status through the sole state writer (INV-A). Computes the target via the
		 * `zdz_flow_derive_state` callback, then — only if it differs from the cached state — applies
		 * it with the matching `to_<state>` transition. There is no setter: status can change ONLY
		 * here, and only via Zdz_Flow::transition().
		 *
		 * @param string $project_id
		 * @return array|WP_Error|null the transition result, or null when nothing changed / not a project.
		 */
		public static function refresh_status( string $project_id ) {
			$item = self::get_raw( $project_id );
			if ( null === $item || ( $item['work_type'] ?? '' ) !== self::WORK_TYPE ) {
				return null;
			}
			$target = Zdz_Flow::derive_state( $project_id );
			if ( '' === $target || $target === (string) $item['state'] ) {
				return null; // convergent no-op.
			}
			if ( ! in_array( $target, self::STATES, true ) ) {
				return null; // never move to an undeclared state.
			}
			return Zdz_Flow::transition(
				$project_id,
				'to_' . $target,
				array(
					'actor'           => array( 'kind' => 'system', 'id' => null ),
					'reason'          => 'derived status recompute',
					// Deterministic per (item, version, target) so a double-fire is an idempotent replay.
					'idempotency_key' => 'projstatus:' . $project_id . ':' . (int) $item['version'] . ':' . $target,
				)
			);
		}

		/* ===================================================================
		 * CREATE / ENSURE
		 * =================================================================== */

		/**
		 * Find-or-create the Project for an origin document. Idempotent: the natural_key
		 * ("{system}:{id}") dedupes at the Flow layer, and a mint race is resolved in the DB —
		 * a Zdz_Flow_Ref_Conflict names the winner and we adopt it.
		 *
		 * $args:
		 *   origin_system (string, required)  e.g. 'estimate' or 'lead' (a registered namespace).
		 *   origin_id     (string|int, req.)  the origin document id.
		 *   created_by    (int)               the user who originated it (recorded as the genesis actor,
		 *                                      giving the quote author the same REL_RELATED tie a Job gives).
		 *   customer      (array)             optional display block passed through to evidence.
		 *
		 * @return string the project work_item id (ULID), or '' on invalid input / write failure.
		 */
		public static function ensure( array $args ): string {
			$system = sanitize_key( (string) ( $args['origin_system'] ?? $args['system'] ?? '' ) );
			$id_raw = (string) ( $args['origin_id'] ?? $args['external_id'] ?? '' );
			$id_raw = trim( $id_raw );
			if ( '' === $system || '' === $id_raw ) {
				return '';
			}
			$entity = self::entity_for( $system );

			// Fast idempotent path: an existing origin ref already names the project.
			if ( '' !== $entity && class_exists( 'Zdz_Flow_Refs' ) ) {
				$existing = Zdz_Flow_Refs::get( $system, $entity, $id_raw );
				if ( null !== $existing ) {
					return (string) $existing;
				}
			}

			$refs = array();
			if ( '' !== $entity ) {
				$refs[] = array( 'system' => $system, 'entity' => $entity, 'external_id' => $id_raw );
			}

			$created_by = (int) ( $args['created_by'] ?? 0 );
			$actor      = ( $created_by > 0 )
				? array( 'kind' => 'user', 'id' => (string) $created_by )
				: array( 'kind' => 'system', 'id' => null );

			$create_args = array(
				'work_type'      => self::WORK_TYPE,
				'natural_key'    => $system . ':' . $id_raw,
				'human_code_ctx' => array(
					'prefix' => self::code_prefix(),
					'NNNN'   => self::next_seq(),
				),
				'actor'          => $actor,
				'refs'           => $refs,
				'reason'         => 'project ensured for ' . $system,
			);
			if ( isset( $args['customer'] ) && is_array( $args['customer'] ) ) {
				$create_args['evidence'] = array( 'customer' => $args['customer'] );
			}

			try {
				$pid = Zdz_Flow::create( $create_args );
			} catch ( Zdz_Flow_Ref_Conflict $e ) {
				// A concurrent minter already owns the origin ref — adopt the DB-resolved winner.
				return $e->winner();
			}
			return (string) $pid;
		}

		/**
		 * Reverse lookup: the project id that owns an origin document, or '' if none.
		 *
		 * @param string $system
		 * @param string $external_id
		 * @return string
		 */
		public static function find_by_origin( string $system, string $external_id ): string {
			$system = sanitize_key( $system );
			$entity = self::entity_for( $system );
			if ( '' === $entity || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return '';
			}
			$id = Zdz_Flow_Refs::get( $system, $entity, (string) $external_id );
			return null === $id ? '' : (string) $id;
		}

		/* ===================================================================
		 * READ
		 * =================================================================== */

		/** The raw work_item row (state is the cached materialized view), or null. */
		public static function get_raw( string $project_id ): ?array {
			if ( ! class_exists( 'Zdz_Flow' ) ) {
				return null;
			}
			$row = Zdz_Flow::get( $project_id );
			if ( null === $row || ( $row['work_type'] ?? '' ) !== self::WORK_TYPE ) {
				return null;
			}
			return $row;
		}

		/**
		 * The enriched project (work_item row + derived facets the visibility engine and resolver
		 * read): created_by (the genesis actor), sp_code (empty by default; estimate-minted projects
		 * carry none — created_by is the tie), the participant id set, the escaped customer display
		 * block, and the per-status child counts. Returns null for a non-existent / non-project id.
		 */
		public static function get( string $project_id ): ?array {
			$item = self::get_raw( $project_id );
			if ( null === $item ) {
				return null;
			}
			$item['created_by']   = self::created_by( $project_id );
			$item['sp_code']      = (string) apply_filters( 'zdz_project_sp_code', '', $item );
			$item['participants'] = self::participant_ids( $project_id );
			$item['customer']     = self::customer_block( $project_id );
			$item['counts']       = self::counts_for( $project_id );
			return $item;
		}

		/**
		 * The user who originated the project = the genesis transition's actor id (when a user).
		 * Read from the append-only log, so it is authoritative and needs no extra column.
		 *
		 * @param string $project_id
		 * @return int
		 */
		public static function created_by( string $project_id ): int {
			if ( ! class_exists( 'Zdz_Flow' ) ) {
				return 0;
			}
			foreach ( Zdz_Flow::history( $project_id ) as $t ) {
				if ( 1 === (int) ( $t['sequence'] ?? 0 ) && 'user' === ( $t['actor_kind'] ?? '' ) ) {
					return (int) ( $t['actor_id'] ?? 0 );
				}
			}
			return 0;
		}

		/**
		 * Everyone "on" the project: the originator + every child job's creator and assignee. Used by
		 * the visibility engine to resolve oversight and relatedness.
		 *
		 * @param string $project_id
		 * @return int[]
		 */
		public static function participant_ids( string $project_id ): array {
			global $wpdb;
			$ids = array();
			$cb  = self::created_by( $project_id );
			if ( $cb > 0 ) {
				$ids[] = $cb;
			}
			$cw = self::child_where( $project_id );
			if ( null !== $cw && isset( $wpdb ) && class_exists( 'ZJOB_DB' ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DISTINCT created_by, assigned_user_id FROM ' . ZJOB_DB::table() . ' WHERE ' . $cw[0],
						$cw[1]
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					if ( (int) $r['created_by'] > 0 ) {
						$ids[] = (int) $r['created_by'];
					}
					if ( (int) $r['assigned_user_id'] > 0 ) {
						$ids[] = (int) $r['assigned_user_id'];
					}
				}
			}
			return array_values( array_unique( $ids ) );
		}

		/**
		 * The child job rows of a project (linked by the stored `job` refs and/or the `estimate` ref).
		 *
		 * @param string $project_id
		 * @return array<int,array>
		 */
		public static function jobs_for( string $project_id ): array {
			global $wpdb;
			$cw = self::child_where( $project_id );
			if ( null === $cw || ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
				return array();
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . ZJOB_DB::table() . ' WHERE ' . $cw[0] . ' ORDER BY id ASC',
					$cw[1]
				),
				ARRAY_A
			);
			return $rows ?: array();
		}

		/**
		 * Per-status counts of a project's child jobs — ONE GROUP BY, never N+1.
		 *
		 * @param string $project_id
		 * @return array<string,int> keyed by job status (open|in_progress|pending_close|done|cancelled).
		 */
		public static function counts_for( string $project_id ): array {
			global $wpdb;
			$counts = array(
				'open'          => 0,
				'in_progress'   => 0,
				'pending_close' => 0,
				'done'          => 0,
				'cancelled'     => 0,
			);
			$cw = self::child_where( $project_id );
			if ( null === $cw || ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
				return $counts;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT status, COUNT(*) AS c FROM ' . ZJOB_DB::table() . ' WHERE ' . $cw[0] . ' GROUP BY status',
					$cw[1]
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$s = (string) $r['status'];
				if ( isset( $counts[ $s ] ) ) {
					$counts[ $s ] = (int) $r['c'];
				}
			}
			return $counts;
		}

		/**
		 * List the projects a viewer may see, SCOPED IN SQL via ZJOB_Scope.
		 *
		 * The hard invariant (ZJOB_Scope): SEE-ALL (null predicate) and SEE-NONE ('1=0') are DISTINCT
		 * and never interchangeable. We branch on is_none()/is_all() explicitly so a deny is never
		 * collapsed into an accidental see-all. A project is visible when the viewer can see one of its
		 * child jobs (the same role-relative rule the jobs list uses) OR the viewer originated it.
		 *
		 * @param int   $viewer WP user id.
		 * @param array $args   { status?: one of self::STATES, limit?: int }.
		 * @return array<int,array> work_item rows.
		 */
		public static function list_for( int $viewer, array $args = array() ): array {
			global $wpdb;
			if ( $viewer <= 0 || ! class_exists( 'ZJOB_Scope' ) || ! class_exists( 'Zdz_Flow_DB' ) || ! isset( $wpdb ) ) {
				return array();
			}
			$scope = ZJOB_Scope::for_actor( $viewer );

			// SEE-NONE is a deny — zero rows, deliberately. NEVER fall through to see-all.
			if ( $scope->is_none() ) {
				return array();
			}

			$wi     = Zdz_Flow_DB::work_items();
			$tenant = Zdz_Flow_DB::tenant_id();
			$where  = array( 'wi.work_type = %s', 'wi.tenant_id = %d' );
			$params = array( self::WORK_TYPE, $tenant );

			$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
			if ( '' !== $status && in_array( $status, self::STATES, true ) ) {
				$where[]  = 'wi.state = %s';
				$params[] = $status;
			}

			if ( $scope->is_all() ) {
				// SEE-ALL: add no ownership clause (the predicate would be NULL — we emit nothing).
				$noop = true;
				unset( $noop );
			} else {
				// SEE-SOME: apply the scope predicate IN SQL over the child jobs, plus a creator arm.
				$uids = $scope->visible_user_ids();
				if ( empty( $uids ) ) {
					return array(); // a subset that references no ids is a deny, not a see-all.
				}
				$pred = $scope->sql_predicate(
					array( 'created_by' => 'j.created_by', 'assignee' => 'j.assigned_user_id' )
				);
				if ( null === $pred ) {
					return array(); // impossible in a subset, but fail-closed rather than widen.
				}
				$refs     = Zdz_Flow_DB::refs();
				$tr       = Zdz_Flow_DB::transitions();
				$jobs     = ZJOB_DB::table();
				$uid_ph   = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
				$job_link = '( ( r.system = \'job\' AND r.entity = \'handoff\' AND j.id = CAST(r.external_id AS UNSIGNED) )'
					. ' OR ( r.system = \'estimate\' AND r.entity = \'document\' AND j.estimate_id = CAST(r.external_id AS UNSIGNED) ) )';

				$by_jobs = "wi.id IN ( SELECT r.work_item_id FROM {$refs} r JOIN {$jobs} j ON {$job_link} WHERE {$pred} )";
				// The scope predicate inlines integer ids only (ZJOB_Scope guarantees), so it carries
				// no bound params — safe to concatenate. The creator arm binds the same id set.
				$by_creator = "wi.id IN ( SELECT t.work_item_id FROM {$tr} t WHERE t.sequence = 1 AND t.actor_kind = 'user' AND t.actor_id IN ({$uid_ph}) )";
				$where[]    = "( {$by_jobs} OR {$by_creator} )";
				$params     = array_merge( $params, $uids );
			}

			$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 200;
			$sql   = "SELECT wi.* FROM {$wi} wi WHERE " . implode( ' AND ', $where ) . ' ORDER BY wi.created_at DESC LIMIT ' . $limit;
			$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			return $rows ?: array();
		}

		/* ===================================================================
		 * ATTACH — a Job joins its Project (idempotent; via the ref map).
		 * =================================================================== */

		/**
		 * Attach a job to a project by recording the `job/handoff` ref. Idempotent: Zdz_Flow_Refs::put
		 * returns true on first record and on re-record. A job already owned by a DIFFERENT project is
		 * NOT stolen — the conflict is swallowed (bookkeeping does not break the work).
		 *
		 * @param string $project_id
		 * @param int    $job_id
		 * @return bool true when the job is attached to this project.
		 */
		public static function attach_job( string $project_id, int $job_id ): bool {
			if ( ! class_exists( 'Zdz_Ulid' ) || ! Zdz_Ulid::is_valid( $project_id ) || $job_id <= 0 ) {
				return false;
			}
			try {
				return Zdz_Flow_Refs::put( $project_id, 'job', 'handoff', (string) $job_id );
			} catch ( Zdz_Flow_Ref_Conflict $e ) {
				return false; // already in another container — do not steal.
			} catch ( \Throwable $e ) {
				return false; // swallow — attaching is bookkeeping.
			}
		}

		/**
		 * The audit-seam subscriber: a new/changed Job ensures its Project (from the job's estimate),
		 * joins it, and refreshes the derived status. Swallows ALL of its own exceptions — placing a
		 * Job in a container must never break the Job. The jobs model never learns Projects exist.
		 *
		 * @param int|array $job_ref a job id, or a context array carrying job_id.
		 */
		public static function on_job_changed( $job_ref ): void {
			try {
				$job_id = is_array( $job_ref ) ? (int) ( $job_ref['job_id'] ?? 0 ) : (int) $job_ref;
				if ( $job_id <= 0 || ! class_exists( 'ZJOB_Jobs' ) ) {
					return;
				}
				$job = ZJOB_Jobs::get( $job_id );
				if ( ! is_array( $job ) ) {
					return;
				}
				$estimate_id = (int) ( $job['estimate_id'] ?? 0 );
				if ( $estimate_id <= 0 ) {
					return; // B2 attaches estimate-linked jobs; other origins land in later units.
				}
				$pid = self::ensure(
					array(
						'origin_system' => 'estimate',
						'origin_id'     => $estimate_id,
						'created_by'    => (int) ( $job['created_by'] ?? 0 ),
					)
				);
				if ( '' === $pid ) {
					return;
				}
				self::attach_job( $pid, $job_id );
				self::refresh_status( $pid );
			} catch ( \Throwable $e ) {
				// bookkeeping does not get to break the work.
				return;
			}
		}

		/* ===================================================================
		 * DOCUMENT IDENTITY — (doc_type, id), never a bare number.
		 * =================================================================== */

		/**
		 * Resolve a raw document pointer to (doc_type, id) — and its (system, entity, external_id)
		 * ref key. REFUSES a bare number (returns []): the estimate #5982 vs invoice #5982 collision
		 * is prevented by requiring the type, never keying on the number alone.
		 *
		 * Accepts: ['doc_type'=>..,'id'=>..], ['system'=>..,'external_id'=>..], or "estimate:5982" /
		 * "estimate#5982". A lone "5982" is refused.
		 *
		 * @param mixed $raw
		 * @return array{} | array{doc_type:string,id:string,system:string,entity:string,external_id:string}
		 */
		public static function extract_doc_ident( $raw ): array {
			$type = '';
			$id   = '';
			if ( is_array( $raw ) ) {
				$type = (string) ( $raw['doc_type'] ?? $raw['system'] ?? '' );
				$id   = (string) ( $raw['id'] ?? $raw['external_id'] ?? '' );
			} elseif ( is_string( $raw ) ) {
				if ( preg_match( '/^\s*([A-Za-z][A-Za-z0-9_]*)\s*[:#]\s*(.+?)\s*$/', $raw, $m ) ) {
					$type = $m[1];
					$id   = $m[2];
				} else {
					return array(); // a bare number / unparseable string — refuse (never key on a number).
				}
			} else {
				return array();
			}

			$type = sanitize_key( $type );
			$id   = trim( $id );
			if ( '' === $type || '' === $id ) {
				return array();
			}
			$entity = self::entity_for( $type );
			return array(
				'doc_type'    => $type,
				'id'          => $id,
				'system'      => $type,
				'entity'      => $entity,
				'external_id' => $id,
			);
		}

		/* ===================================================================
		 * INTERNALS
		 * =================================================================== */

		/** The registered entity for a namespace system (e.g. estimate => document), or ''. */
		public static function entity_for( string $system ): string {
			$system = sanitize_key( $system );
			return self::NAMESPACES[ $system ] ?? '';
		}

		/**
		 * The WHERE fragment + params selecting a project's child jobs (by stored `job` refs and/or by
		 * the `estimate` ref's estimate_id). Returns null when the project links to no jobs at all.
		 *
		 * @param string $project_id
		 * @return array{0:string,1:array}|null
		 */
		private static function child_where( string $project_id ): ?array {
			if ( ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return null;
			}
			$job_ids = array();
			$est_ids = array();
			foreach ( Zdz_Flow_Refs::for( $project_id ) as $r ) {
				$sys = (string) ( $r['system'] ?? '' );
				$ent = (string) ( $r['entity'] ?? '' );
				$ext = (int) ( $r['external_id'] ?? 0 );
				if ( $ext <= 0 ) {
					continue;
				}
				if ( 'job' === $sys && 'handoff' === $ent ) {
					$job_ids[] = $ext;
				} elseif ( 'estimate' === $sys && 'document' === $ent ) {
					$est_ids[] = $ext;
				}
			}
			$job_ids = array_values( array_unique( $job_ids ) );
			$est_ids = array_values( array_unique( $est_ids ) );

			$parts = array();
			$args  = array();
			if ( ! empty( $job_ids ) ) {
				$parts[] = 'id IN (' . implode( ',', array_fill( 0, count( $job_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $job_ids );
			}
			if ( ! empty( $est_ids ) ) {
				$parts[] = 'estimate_id IN (' . implode( ',', array_fill( 0, count( $est_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $est_ids );
			}
			if ( empty( $parts ) ) {
				return null;
			}
			return array( '( ' . implode( ' OR ', $parts ) . ' )', $args );
		}

		/**
		 * The escaped customer display block for a project (from its first child job). business /
		 * address / name are escaped HERE (esc_html) because they are CRM/geo-PII values that a
		 * downstream renderer may drop into innerHTML — the field that round-trips `<img onerror=…>`
		 * is a real XSS vector. Returns an empty block when nothing is linked yet.
		 *
		 * @param string $project_id
		 * @return array{name:string,business:string,address:string}
		 */
		public static function customer_block( string $project_id ): array {
			$empty = array( 'name' => '', 'business' => '', 'address' => '' );
			$jobs  = self::jobs_for( $project_id );
			if ( empty( $jobs ) ) {
				return $empty;
			}
			$j = $jobs[0];
			$e = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';
			return array(
				'name'     => (string) call_user_func( $e, (string) ( $j['customer_name'] ?? '' ) ),
				'business' => (string) call_user_func( $e, (string) ( $j['customer_business'] ?? '' ) ),
				'address'  => (string) call_user_func( $e, (string) ( $j['customer_address'] ?? '' ) ),
			);
		}

		/** The Core-default human_code prefix (tenant-overridable via the human_code template filter). */
		private static function code_prefix(): string {
			return (string) apply_filters( 'zdz_project_code_prefix', self::CODE_PREFIX_DEFAULT );
		}

		/**
		 * The next per-year project sequence, as a zero-padded string, from an atomic tenant counter
		 * option. human_code is DISPLAY-ONLY and NOT a unique column, so this never inserts an empty
		 * placeholder into a unique column (the `code=''` poison) — a display collision is harmless.
		 *
		 * @return string e.g. "0007".
		 */
		private static function next_seq(): string {
			global $wpdb;
			$year = gmdate( 'Y' );
			$name = 'zdz_project_code_seq_' . $year;

			if ( ! function_exists( 'get_option' ) || ! isset( $wpdb ) ) {
				return '0001'; // no options store (harness) — a harmless display value.
			}
			if ( null === get_option( $name, null ) ) {
				if ( function_exists( 'add_option' ) ) {
					add_option( $name, '0', '', 'no' );
				}
			}
			// Atomic bump on the options row (option_value is a string column; +1 coerces numerically).
			$wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $name )
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $name, 'options' );
			}
			$seq = (int) get_option( $name, 1 );
			if ( $seq < 1 ) {
				$seq = 1;
			}
			return str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
		}
	}
}
