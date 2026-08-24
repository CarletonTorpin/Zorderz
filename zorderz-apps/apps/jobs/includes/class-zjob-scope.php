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
				return self::none(); // a shared device is not a person (INV-10)
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
