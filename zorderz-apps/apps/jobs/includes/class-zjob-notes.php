<?php
/**
 * Zorderz Jobs — the Job Dossier: threaded notes (wp_zdz_job_notes).
 *
 * Notes are where the bluntest customer PII lives — "the dog", "the hoarding", "what
 * the customer said on the phone". A leak here is the exact disclosure the whole
 * Dossier subsystem is built to prevent. The design therefore hard-wires one rule:
 *
 *   NOTES ARE INTERNAL BY DEFAULT, AND THERE IS EXACTLY ONE TEAM-ONLY CROSS-PLUGIN
 *   DOOR — for_harvest() — WHICH RETURNS ONLY team-VISIBLE ROWS, IN SQL.
 *
 * THE HARD INVARIANT (do not add a flag, ever):
 *   There is NO $include_internal parameter anywhere in this class, and there never
 *   will be — "a parameter that can leak eventually does". The safe choice is *which
 *   method you call*, not an argument you pass:
 *     - for_harvest()          -> team-visible rows ONLY (WHERE visibility='team').
 *                                 This is the ONLY method another plugin / a customer
 *                                 surface may call. Internal bodies are unreachable
 *                                 through it because the SQL never selects them.
 *     - for_jobs()             -> the jobs app's OWN Record-panel reader. Returns full
 *       / top_level_for_jobs()    threads INCLUDING internal bodies, for a staff viewer
 *                                 who has ALREADY been gated per-component (the caller
 *                                 must pass only ids the viewer may open — see the B5
 *                                 Record panel's openable_job_ids()). These are NOT
 *                                 cross-plugin doors.
 *     - counts_for()           -> integer counts only (a count discloses nothing), so
 *                                 it may span every note regardless of tier.
 *
 * The visibility door here and the actor_can_manage() door in ZJOB_Jobs must never
 * diverge: this class trusts its caller to have gated openability; it does not widen.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Notes {

	/** Visibility tiers. internal is the default and the most restricted. */
	const VIS_INTERNAL = 'internal'; // team-internal; the bluntest PII; never harvested
	const VIS_TEAM     = 'team';     // team-shareable; the ONLY tier for_harvest() emits

	/* =======================================================================
	 * TABLE
	 * ======================================================================= */

	/** wp_zdz_job_notes — the DDL + boot live in ZJOB_Events (single dossier migrator). */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_job_notes';
	}

	/** Make sure the dossier tables exist (delegates to the single owner). */
	private static function ensure_schema(): void {
		if ( class_exists( 'ZJOB_Events' ) && method_exists( 'ZJOB_Events', 'ensure_schema' ) ) {
			ZJOB_Events::ensure_schema();
		}
	}

	/* =======================================================================
	 * WRITE
	 * ======================================================================= */

	/**
	 * Add a note to a job. Internal by default. A reply passes the thread root's id as
	 * $opts['parent_id']; a mismatched parent (not a top-level note on the same job) is
	 * demoted to a new top-level note rather than silently mis-threaded.
	 *
	 * @param int    $job_id
	 * @param int    $author_id
	 * @param string $body
	 * @param array  $opts { visibility:'internal'|'team'='internal', parent_id:int=0 }
	 * @return int The new note id, or 0 on failure/empty body.
	 */
	public static function add( int $job_id, int $author_id, string $body, array $opts = [] ): int {
		if ( $job_id <= 0 ) {
			return 0;
		}
		self::ensure_schema();
		global $wpdb;

		// Plain-text notes: strip tags/scripts on the way in (defence in depth — the
		// render side escapes too), keep line breaks. Never store markup.
		$body = sanitize_textarea_field( (string) wp_unslash( $body ) );
		if ( '' === trim( $body ) ) {
			return 0;
		}

		$visibility = self::clamp_visibility( (string) ( $opts['visibility'] ?? self::VIS_INTERNAL ) );

		$parent_id = max( 0, (int) ( $opts['parent_id'] ?? 0 ) );
		if ( $parent_id > 0 && ! self::valid_thread_root( $parent_id, $job_id ) ) {
			$parent_id = 0; // fall back to a new top-level note, never a broken thread
		}

		$now = current_time( 'mysql' );

		$ok = $wpdb->insert(
			self::table(),
			[
				'job_id'     => $job_id,
				'parent_id'  => $parent_id,
				'author_id'  => max( 0, (int) $author_id ),
				'visibility' => $visibility,
				'body'       => $body,
				'edited_by'  => 0,
				'created_at' => $now,
				'updated_at' => $now,
				'deleted_at' => null,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
		if ( ! $ok ) {
			return 0;
		}
		$note_id = (int) $wpdb->insert_id;

		// Best-effort narrative crumb (the body never enters the event; only the fact).
		if ( class_exists( 'ZJOB_Events' ) ) {
			ZJOB_Events::record( $job_id, 'note_added', [
				'actor_id'   => (int) $author_id,
				'visibility' => ZJOB_Events::VIS_STAFF,
				'context'    => [ 'note_id' => $note_id, 'tier' => $visibility ],
			] );
		}

		return $note_id;
	}

	/**
	 * Soft-delete a note (history is preserved; the row stops appearing in every
	 * reader). Append-friendly: we never hard-delete a dossier note.
	 */
	public static function remove( int $note_id, int $actor_id = 0 ): bool {
		if ( $note_id <= 0 ) {
			return false;
		}
		self::ensure_schema();
		global $wpdb;
		$now = current_time( 'mysql' );
		return (bool) $wpdb->update(
			self::table(),
			[ 'deleted_at' => $now, 'updated_at' => $now, 'edited_by' => max( 0, (int) $actor_id ) ],
			[ 'id' => $note_id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);
	}

	/* =======================================================================
	 * READ — APP-INTERNAL (returns internal bodies; caller MUST pre-gate openability)
	 * ======================================================================= */

	/**
	 * Full threads for a set of jobs — INCLUDING internal bodies. This is the jobs
	 * app's own Record-panel reader, NOT a cross-plugin door. The caller must pass
	 * only ids the viewer is allowed to open (per-component actor_can_manage), because
	 * this method deliberately does not re-gate and does not filter by tier.
	 *
	 * (No $include_internal flag exists — this method's identity IS the choice.)
	 *
	 * @param int[] $job_ids
	 * @param array $opts { limit:int=500, order:'ASC'|'DESC'='ASC' }
	 * @return array<int,array>
	 */
	public static function for_jobs( array $job_ids, array $opts = [] ): array {
		return self::query_rows( $job_ids, null, false, $opts );
	}

	/**
	 * As for_jobs(), but only the top-level notes (thread roots, parent_id=0). Same
	 * app-internal posture — includes internal bodies; never gains an include flag.
	 *
	 * @param int[] $job_ids
	 * @return array<int,array>
	 */
	public static function top_level_for_jobs( array $job_ids, array $opts = [] ): array {
		return self::query_rows( $job_ids, null, true, $opts );
	}

	/* =======================================================================
	 * READ — THE ONE CROSS-PLUGIN DOOR (team-visible rows ONLY, in SQL)
	 * ======================================================================= */

	/**
	 * The single team-only cross-plugin door. Returns ONLY notes whose visibility is
	 * 'team'; an internal note is unreachable through this method because the SQL never
	 * selects it. Any other plugin, or any customer-facing surface, that wants notes
	 * MUST call this and only this.
	 *
	 * @param int[] $job_ids
	 * @param array $opts { limit:int=500, order:'ASC'|'DESC'='ASC' }
	 * @return array<int,array>
	 */
	public static function for_harvest( array $job_ids, array $opts = [] ): array {
		return self::query_rows( $job_ids, self::VIS_TEAM, false, $opts );
	}

	/* =======================================================================
	 * COUNTS (a count discloses nothing — spans every tier)
	 * ======================================================================= */

	/**
	 * Per-job note counts across all tiers (non-deleted). Safe to show anyone: the
	 * Record panel uses this for an honest "N notes" even where the strip shows fewer.
	 *
	 * @param int[] $job_ids
	 * @return array<int,int> job_id => count
	 */
	public static function counts_for( array $job_ids ): array {
		$job_ids = self::clean_ids( $job_ids );
		$out     = array_fill_keys( $job_ids, 0 );
		if ( empty( $job_ids ) ) {
			return $out;
		}
		self::ensure_schema();
		global $wpdb;

		$table = self::table();
		$ph    = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		$sql   = "SELECT job_id, COUNT(*) AS c FROM {$table}
			WHERE job_id IN ({$ph}) AND deleted_at IS NULL GROUP BY job_id";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $job_ids ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['job_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/* =======================================================================
	 * INTERNAL
	 * ======================================================================= */

	/**
	 * The shared reader. $visibility_exact is the ONLY tier control:
	 *   - null   -> every tier (the app-internal readers)
	 *   - 'team' -> team rows only (for_harvest)
	 * It is a fixed argument chosen by the calling method, never exposed to callers —
	 * there is no path for an external caller to ask query_rows() for internal rows.
	 */
	private static function query_rows( array $job_ids, ?string $visibility_exact, bool $top_only, array $opts ): array {
		$job_ids = self::clean_ids( $job_ids );
		if ( empty( $job_ids ) ) {
			return [];
		}
		self::ensure_schema();
		global $wpdb;

		$where  = [];
		$params = [];

		$ph      = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		$where[] = "job_id IN ({$ph})";
		$params  = array_merge( $params, $job_ids );

		$where[] = 'deleted_at IS NULL';

		if ( null !== $visibility_exact ) {
			$where[]  = 'visibility = %s';
			$params[] = $visibility_exact;
		}
		if ( $top_only ) {
			$where[] = 'parent_id = 0';
		}

		$order = ( strtoupper( (string) ( $opts['order'] ?? 'ASC' ) ) === 'DESC' ) ? 'DESC' : 'ASC';
		$limit = (int) ( $opts['limit'] ?? 500 );
		$limit = max( 1, min( 2000, $limit ) );

		$table = self::table();
		// Group each thread together: root key = (parent_id==0 ? id : parent_id), then
		// roots before their replies, then chronological within.
		$root_key = 'CASE WHEN parent_id = 0 THEN id ELSE parent_id END';
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY {$root_key} {$order}, parent_id ASC, created_at ASC, id ASC LIMIT {$limit}";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return $rows ?: [];
	}

	/** A parent is valid only if it is a live, top-level note on the same job. */
	private static function valid_thread_root( int $parent_id, int $job_id ): bool {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE id = %d AND job_id = %d AND parent_id = 0 AND deleted_at IS NULL LIMIT 1",
			$parent_id, $job_id
		) );
		return ! empty( $found );
	}

	/** Clamp a visibility to a known tier; unknown -> the internal default (safest). */
	private static function clamp_visibility( string $v ): string {
		$v = sanitize_key( $v );
		return in_array( $v, [ self::VIS_INTERNAL, self::VIS_TEAM ], true ) ? $v : self::VIS_INTERNAL;
	}

	/** Normalise a mixed id list to clean positive ints. */
	private static function clean_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}
}
