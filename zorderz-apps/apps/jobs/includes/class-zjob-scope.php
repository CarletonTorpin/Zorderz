<?php
/**
 * Zorderz Jobs — the visibility Scope (a reusable, role-relative SQL predicate).
 *
 * The scope rule that was inlined inside ZJOB_Jobs::list_for() — "admins see all;
 * a crew lead sees their crew's (+ their own); everyone else sees only what they
 * created or are assigned" — extracted into a small value object so the Projects
 * container, the resolver, and any future reader express the SAME rule the SAME way
 * instead of re-deriving it per call site.
 *
 * THE HARD INVARIANT (do not "simplify" this away):
 *   sql_predicate() distinguishes THREE outcomes and they are NEVER interchangeable:
 *     - SEE-ALL   -> returns NULL          (add NO where clause; every row is visible)
 *     - SEE-NONE  -> returns the string '1=0' (a DENY: zero rows, deliberately)
 *     - SEE-SOME  -> returns a WHERE fragment ("( created_by IN (..) OR ... )")
 *   An empty scope (see-all) and a deny scope (see-none) are DIFFERENT VALUES. A
 *   caller that collapses NULL and '1=0' into the same "" turns a deny into an
 *   accidental see-all. Branch on is_all()/is_none() if you cannot handle a NULL.
 *
 * Fail-closed: no actor, the kiosk (a shared device is not a person), or a missing
 * hierarchy engine never widens to SEE-ALL. The worst case is SEE-NONE or self-only,
 * never everyone.
 *
 * The predicate inlines INTEGER user ids only (cast through intval), so the returned
 * fragment is safe to concatenate into a WHERE clause without a separate bound-args
 * array — there is no attacker-controlled string in it.
 *
 * ---------------------------------------------------------------------------------
 * THE PER-PERSON TIERS (for_user / can_see) — who may SEE which job rows
 * ---------------------------------------------------------------------------------
 * The static tier API answers "may this person see this job row?" for every reader
 * that works row by row (the install-date resolver, Prep, Receipts, the dossier):
 *
 *   none  the shared device (a shared device is not a person), or nobody signed in.
 *   all   an administrator. Everything.
 *   shop  the production role (Core: zdz_mfg; `zjob_scope_shop_roles`). Every job,
 *         because production has to see the whole floor to sequence it.
 *   crew  a crew lead: their own work plus every person in their crew.
 *   own   everyone else: work they are assigned, work they originated, and work
 *         that carries their salesperson initials (sp_code).
 *
 * The initials are their own dimension: a salesperson who SOLD a job keeps sight of
 * it after the work is handed to a specialist, because the customer still calls them.
 *
 * Seeing is not acting. These tiers decide VISIBILITY only; actor_can_manage /
 * actor_can_schedule / actor_can_close stay the sole authority on what a person may
 * DO. Widening what a query returns never widens what a button accepts.
 *
 * Fail-closed: a filter answer that is not a well-formed scope is ignored; a code
 * shorter than two letters never becomes a scope key; the shared device is checked
 * before anything else can grant it something.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Scope {

	/** Breadth of the scope. */
	const ALL    = 'all';    // sql_predicate() -> null   (see everything)
	const NONE   = 'none';   // sql_predicate() -> '1=0'  (see nothing — a deny)
	const SUBSET = 'subset'; // sql_predicate() -> fragment (see a specific set)

	/** The literal DENY predicate — see-none is a value, never an empty string. */
	const DENY_SQL = '1=0';

	/** Default column map (the wp_zdz_jobs shape). Overridable per call for reuse. */
	const DEFAULT_COLS = [
		'created_by' => 'created_by',
		'assignee'   => 'assigned_user_id',
	];

	private string $breadth;

	/** @var int[] ids whose CREATED rows are visible (SUBSET only). */
	private array $created_by_ids;

	/** @var int[] ids whose ASSIGNED rows are visible (SUBSET only). */
	private array $assignee_ids;

	private function __construct( string $breadth, array $created_by_ids = [], array $assignee_ids = [] ) {
		$this->breadth        = $breadth;
		$this->created_by_ids = self::clean_ids( $created_by_ids );
		$this->assignee_ids   = self::clean_ids( $assignee_ids );
	}

	/* =======================================================================
	 * CONSTRUCTORS
	 * ======================================================================= */

	/** An explicit SEE-ALL scope (predicate is null). */
	public static function all(): self {
		return new self( self::ALL );
	}

	/** An explicit SEE-NONE / deny scope (predicate is '1=0'). */
	public static function none(): self {
		return new self( self::NONE );
	}

	/**
	 * A bounded scope over the given id sets. If BOTH sets are empty this collapses
	 * to SEE-NONE (a subset of nothing is nothing — never silently see-all).
	 *
	 * @param int[] $created_by_ids
	 * @param int[] $assignee_ids
	 */
	public static function of( array $created_by_ids, array $assignee_ids ): self {
		$cb = self::clean_ids( $created_by_ids );
		$as = self::clean_ids( $assignee_ids );
		if ( empty( $cb ) && empty( $as ) ) {
			return self::none();
		}
		return new self( self::SUBSET, $cb, $as );
	}

	/**
	 * The role-relative scope for an actor — the reusable form of list_for()'s rule.
	 *
	 *   kiosk / no actor / no hierarchy-of-self  -> SEE-NONE  (fail-closed)
	 *   admin                                    -> SEE-ALL
	 *   crew lead                                -> self + crew (created OR assigned)
	 *   worker                                   -> self only  (created OR assigned)
	 *
	 * When ZDZ_Hierarchy is unavailable the scope is self-only (a person always sees
	 * their own rows) — never widened to everyone.
	 *
	 * @param int $actor_id WP user id.
	 */
	public static function for_actor( int $actor_id ): self {
		if ( $actor_id <= 0 ) {
			return self::none();
		}

		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			if ( ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
				return self::none(); // a shared device is not a person
			}
			if ( ZDZ_Hierarchy::is_admin( $actor_id ) ) {
				return self::all();
			}
			$ids = ZDZ_Hierarchy::overseeable_user_ids( $actor_id );
			$ids = self::clean_ids( $ids );
			if ( ! in_array( $actor_id, $ids, true ) ) {
				$ids[] = $actor_id;
			}
			// A row is visible if it was created by, OR assigned to, anyone in scope.
			return self::of( $ids, $ids );
		}

		// Hierarchy engine absent: fail-closed to self-only, never see-all.
		return self::of( [ $actor_id ], [ $actor_id ] );
	}

	/* =======================================================================
	 * INSPECTION
	 * ======================================================================= */

	public function breadth(): string {
		return $this->breadth;
	}

	public function is_all(): bool {
		return self::ALL === $this->breadth;
	}

	public function is_none(): bool {
		return self::NONE === $this->breadth;
	}

	/**
	 * The distinct set of user ids this scope references (empty for ALL and NONE).
	 * Handy for callers that want to pre-bound a secondary query.
	 *
	 * @return int[]
	 */
	public function visible_user_ids(): array {
		if ( self::SUBSET !== $this->breadth ) {
			return [];
		}
		return array_values( array_unique( array_merge( $this->created_by_ids, $this->assignee_ids ) ) );
	}

	/* =======================================================================
	 * THE PREDICATE
	 * ======================================================================= */

	/**
	 * Render the scope as a SQL WHERE fragment.
	 *
	 * @param array $cols Column map override, e.g.
	 *              [ 'created_by' => 'p.created_by', 'assignee' => 'p.assigned' ].
	 *              Values are trusted column expressions (never end-user input).
	 * @return string|null NULL = SEE-ALL (emit no clause); '1=0' = SEE-NONE (deny);
	 *              otherwise a parenthesised OR-fragment of IN() tests. NULL and
	 *              '1=0' are DISTINCT — a caller MUST NOT treat NULL as "no rows".
	 */
	public function sql_predicate( array $cols = [] ): ?string {
		if ( self::ALL === $this->breadth ) {
			return null;
		}
		if ( self::NONE === $this->breadth ) {
			return self::DENY_SQL;
		}

		$cols       = array_merge( self::DEFAULT_COLS, $cols );
		$created_by = (string) $cols['created_by'];
		$assignee   = (string) $cols['assignee'];

		$parts = [];
		if ( ! empty( $this->created_by_ids ) ) {
			$parts[] = $created_by . ' IN (' . implode( ',', $this->created_by_ids ) . ')';
		}
		if ( ! empty( $this->assignee_ids ) ) {
			$parts[] = $assignee . ' IN (' . implode( ',', $this->assignee_ids ) . ')';
		}

		// A SUBSET that somehow references no ids is a deny, not a see-all.
		if ( empty( $parts ) ) {
			return self::DENY_SQL;
		}

		return '( ' . implode( ' OR ', $parts ) . ' )';
	}

	/**
	 * Convenience: fold the scope into an existing SQL string as an extra AND term.
	 * SEE-ALL contributes nothing; SEE-NONE contributes the deny; a subset ANDs in.
	 * The base SQL must already be a complete SELECT WITHOUT a WHERE (or ending in a
	 * WHERE the caller manages) — this only returns the term to append; callers that
	 * need full control should use sql_predicate() directly.
	 *
	 * @return string '' for SEE-ALL, else a leading-' AND '-free predicate string.
	 */
	public function predicate_or_true( array $cols = [] ): string {
		$p = $this->sql_predicate( $cols );
		return null === $p ? '1=1' : $p;
	}

	/* =======================================================================
	 * THE PER-PERSON TIERS
	 * ======================================================================= */

	const TIER_NONE = 'none';
	const TIER_OWN  = 'own';
	const TIER_CREW = 'crew';
	const TIER_SHOP = 'shop';
	const TIER_ALL  = 'all';

	/** Ordered weakest to strongest, so tiers can be compared. */
	const TIERS = [ self::TIER_NONE, self::TIER_OWN, self::TIER_CREW, self::TIER_SHOP, self::TIER_ALL ];

	/** The Core production role that sees the whole floor (filter `zjob_scope_shop_roles`). */
	const SHOP_ROLES = [ 'zdz_mfg' ];

	/**
	 * The user-meta keys that may carry a person's salesperson initials. Every key is
	 * read and the union kept (filter `zjob_sp_code_meta_keys`), so a person set up
	 * through any one screen is still scoped by their code.
	 */
	const SP_CODE_META_KEYS = [ 'zdz_party_code', 'zl_salesperson_code', 'zdz_salesperson_initials', 'zdz_user_initials' ];

	/** @var array<int,array> per-request memo of resolved scopes. */
	private static array $memo = [];

	/**
	 * Resolve a person's job scope.
	 *
	 * @return array{tier:string,user_ids:int[],sp_codes:string[],sees_all:bool,reason:string}
	 */
	public static function for_user( int $user_id ): array {
		if ( isset( self::$memo[ $user_id ] ) ) {
			return self::$memo[ $user_id ];
		}
		$scope = self::resolve( $user_id );

		/**
		 * Adjust one person's job visibility after the built-in resolution. An answer
		 * that is not a well-formed scope (unknown tier, not an array) is ignored, so a
		 * broken filter fails closed to the built-in answer, never open. Nobody signed
		 * in and the shared device are never offered to the filter.
		 *
		 * @param array $scope   { tier, user_ids, sp_codes, sees_all, reason }
		 * @param int   $user_id
		 */
		if ( $user_id > 0 && 'shared device' !== $scope['reason'] ) {
			$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_scope', $scope, $user_id ) : $scope;
			if ( is_array( $filtered ) && isset( $filtered['tier'] ) && in_array( $filtered['tier'], self::TIERS, true ) ) {
				$tier  = (string) $filtered['tier'];
				$scope = [
					'tier'     => $tier,
					'user_ids' => self::clean_ids( (array) ( $filtered['user_ids'] ?? [] ) ),
					'sp_codes' => self::clean_codes( (array) ( $filtered['sp_codes'] ?? [] ) ),
					// "sees all" only ever rides the two tiers that mean it.
					'sees_all' => ! empty( $filtered['sees_all'] ) && in_array( $tier, [ self::TIER_ALL, self::TIER_SHOP ], true ),
					'reason'   => (string) ( $filtered['reason'] ?? 'filtered' ),
				];
			}
		}

		self::$memo[ $user_id ] = $scope;
		return $scope;
	}

	/** The built-in resolution (kiosk first, then admin, shop, crew lead, own work). */
	private static function resolve( int $user_id ): array {
		$none = [ 'tier' => self::TIER_NONE, 'user_ids' => [], 'sp_codes' => [], 'sees_all' => false, 'reason' => '' ];

		if ( $user_id <= 0 ) {
			return [ 'reason' => 'not signed in' ] + $none;
		}
		// A shared device is never a person; checked first so no later branch can grant it anything.
		if ( self::is_shared_device( $user_id ) ) {
			return [ 'reason' => 'shared device' ] + $none;
		}

		$codes = self::sp_codes_for( $user_id );

		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $user_id ) ) {
			return [ 'tier' => self::TIER_ALL, 'user_ids' => [], 'sp_codes' => $codes, 'sees_all' => true, 'reason' => 'admin' ];
		}

		if ( self::has_role( $user_id, self::shop_roles() ) ) {
			return [ 'tier' => self::TIER_SHOP, 'user_ids' => [], 'sp_codes' => $codes, 'sees_all' => true, 'reason' => 'shop floor' ];
		}

		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_crew_lead( $user_id ) ) {
			$ids = self::clean_ids( ZDZ_Hierarchy::overseeable_user_ids( $user_id ) );
			if ( ! in_array( $user_id, $ids, true ) ) {
				$ids[] = $user_id;
			}
			return [ 'tier' => self::TIER_CREW, 'user_ids' => $ids, 'sp_codes' => $codes, 'sees_all' => false, 'reason' => 'crew lead' ];
		}

		return [ 'tier' => self::TIER_OWN, 'user_ids' => [ $user_id ], 'sp_codes' => $codes, 'sees_all' => false, 'reason' => 'own work' ];
	}

	/**
	 * Can this person see this job row at all? The in-PHP twin of scope_predicate().
	 * Reads created_by, assigned_user_id and sp_code off the row — a caller that selects
	 * fewer columns gets "no" for everyone, never "yes".
	 */
	public static function can_see( int $user_id, array $row ): bool {
		$scope = self::for_user( $user_id );
		if ( self::TIER_NONE === $scope['tier'] ) {
			return false;
		}
		if ( ! empty( $scope['sees_all'] ) ) {
			return true;
		}
		$ids = (array) $scope['user_ids'];
		if ( in_array( (int) ( $row['created_by'] ?? 0 ), $ids, true ) ) {
			return true;
		}
		if ( in_array( (int) ( $row['assigned_user_id'] ?? 0 ), $ids, true ) ) {
			return true;
		}
		$code = strtoupper( trim( (string) ( $row['sp_code'] ?? '' ) ) );
		return '' !== $code && in_array( $code, (array) $scope['sp_codes'], true );
	}

	/**
	 * The scope as a prepared SQL predicate: [ sql, args ], or NULL when no predicate is
	 * needed (sees everything). A scope that sees nothing returns a predicate matching
	 * nothing — "no WHERE clause" and "WHERE false" are opposite outcomes and a caller
	 * must never collapse them.
	 *
	 * @return array{0:string,1:array}|null
	 */
	public static function scope_predicate( array $scope ): ?array {
		$tier = (string) ( $scope['tier'] ?? self::TIER_NONE );
		if ( ! in_array( $tier, self::TIERS, true ) || self::TIER_NONE === $tier ) {
			return [ '1 = 0', [] ];
		}
		if ( self::TIER_ALL === $tier || self::TIER_SHOP === $tier ) {
			return null;
		}
		$ids   = self::clean_ids( (array) ( $scope['user_ids'] ?? [] ) );
		$codes = self::clean_codes( (array) ( $scope['sp_codes'] ?? [] ) );
		if ( empty( $ids ) ) {
			return [ '1 = 0', [] ];
		}
		$ph        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$clauses   = [ "created_by IN ({$ph})", "assigned_user_id IN ({$ph})" ];
		$args      = array_merge( $ids, $ids );
		if ( ! empty( $codes ) ) {
			$cph       = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
			$clauses[] = "( sp_code <> '' AND sp_code IN ({$cph}) )";
			$args      = array_merge( $args, $codes );
		}
		return [ '( ' . implode( ' OR ', $clauses ) . ' )', $args ];
	}

	/**
	 * Every salesperson-initials code that belongs to this person, uppercased, 2-4
	 * letters (anything else is a display name or a typo and never a scope key: a one-
	 * letter code would match half the team).
	 *
	 * @return string[]
	 */
	public static function sp_codes_for( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return [];
		}
		$keys = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_sp_code_meta_keys', self::SP_CODE_META_KEYS ) : self::SP_CODE_META_KEYS;
		$raw  = [];
		foreach ( (array) $keys as $k ) {
			$raw[] = (string) get_user_meta( $user_id, (string) $k, true );
		}
		return self::clean_codes( $raw );
	}

	/**
	 * The code to stamp on a new job: the estimate's recorded salesperson first (the
	 * person who SOLD the work owns it for scope), else the creator's own code, else ''.
	 */
	public static function code_for_new_job( int $creator_id, int $estimate_id = 0 ): string {
		if ( $estimate_id > 0 ) {
			$sp = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zjob_estimate_sp_code', self::estimate_salesperson( $estimate_id ), $estimate_id ) : '';
			$sp = self::clean_codes( [ $sp ] );
			if ( $sp ) {
				return $sp[0];
			}
		}
		$mine = self::sp_codes_for( $creator_id );
		return $mine ? (string) $mine[0] : '';
	}

	/** A short label for the jobs list, so a person can tell what they are seeing. */
	public static function label( array $scope ): string {
		switch ( (string) ( $scope['tier'] ?? '' ) ) {
			case self::TIER_ALL:
				return __( 'All jobs', 'zorderz' );
			case self::TIER_SHOP:
				return __( 'All shop jobs', 'zorderz' );
			case self::TIER_CREW:
				return __( 'You and your crew', 'zorderz' );
			case self::TIER_OWN:
				return __( 'Your jobs', 'zorderz' );
			default:
				return '';
		}
	}

	/** The same, for a list of projects. */
	public static function project_label( array $scope ): string {
		switch ( (string) ( $scope['tier'] ?? '' ) ) {
			case self::TIER_ALL:
				return __( 'All projects', 'zorderz' );
			case self::TIER_SHOP:
				return __( 'All shop projects', 'zorderz' );
			case self::TIER_CREW:
				return __( 'You and your crew', 'zorderz' );
			case self::TIER_OWN:
				return __( 'Your projects', 'zorderz' );
			default:
				return '';
		}
	}

	/** Drop the per-request memo (tests; anything that changes a person's scope mid-request). */
	public static function flush_memo(): void {
		self::$memo = [];
	}

	/** The production roles that see every job (Core: zdz_mfg; a business may narrow it). */
	public static function shop_roles(): array {
		$roles = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_scope_shop_roles', self::SHOP_ROLES ) : self::SHOP_ROLES;
		$out   = [];
		foreach ( (array) $roles as $r ) {
			$r = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $r ) : strtolower( (string) $r );
			// Never the shared device, never an administrator-only slug through this door.
			if ( '' !== $r && 'zdz_general' !== $r ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function is_shared_device( int $user_id ): bool {
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $user_id );
	}

	private static function has_role( int $user_id, array $roles ): bool {
		if ( ! $roles || ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$u = get_userdata( $user_id );
		return $u && ! empty( $u->roles ) && (bool) array_intersect( (array) $u->roles, $roles );
	}

	/** The estimate's recorded salesperson (the estimate app's own column), or ''. */
	private static function estimate_salesperson( int $estimate_id ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( [ 'ZEST_DB', 'estimates_table' ] ) ) {
			return '';
		}
		$t = ZEST_DB::estimates_table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT salesperson FROM {$t} WHERE id = %d", $estimate_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
	}

	/** Uppercased 2-4 letter codes, unique. */
	private static function clean_codes( array $codes ): array {
		$out = [];
		foreach ( $codes as $c ) {
			$c = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
			if ( strlen( $c ) >= 2 && strlen( $c ) <= 4 ) {
				$out[ $c ] = true;
			}
		}
		return array_keys( $out );
	}

	/* =======================================================================
	 * INTERNAL
	 * ======================================================================= */

	/** Normalise a mixed id list to a clean, de-duplicated positive-int array. */
	private static function clean_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id; // key by value to de-dupe
			}
		}
		return array_values( $out );
	}
}
