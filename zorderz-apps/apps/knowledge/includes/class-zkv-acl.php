<?php
/**
 * ZKV_ACL — the single authoritative access predicate for private transcripts.
 *
 * THE RULE (v1.5.0): a document row is admitted iff
 *   (it is NOT a transcript AND the caller passes the normal visibility rule)
 *   OR
 *   (it IS a transcript AND the caller's user id has a wp_zkv_doc_parties row
 *    — plus, in VIEW mode only, an active whole-document share).
 *
 * "Is a transcript" is tested ONLY as visibility = 'transcript_private'.
 * There is deliberately no second boolean column that could drift — the
 * fail-toward-hiding tripwire (every legacy query filters
 * visibility='all_employees') and this ACL key off the SAME column, so a
 * transcript that a forgotten code path touches is hidden, never exposed.
 *
 * TWO MODES, TWO NAMED METHODS (not a stringly-typed flag — a typo'd mode
 * would silently mis-scope; a typo'd method name is a fatal caught by php -l):
 *
 *   sql_where_chat( $uid, $alias )  — party-only. THE BRAIN-BOT BRIDGE USES
 *       THIS. Shares are never consulted: a share lends a *view*, not a chat
 *       seat, so sharing can never widen who the bot answers for.
 *   sql_where_view( $uid, $alias )  — party OR active, unexpired, unrevoked
 *       WHOLE-document share. REST search/context/preview/pricing/deep-search,
 *       the dashboard AJAX readers, and the /vault/{slug} serve gate use this.
 *       Excerpt shares are NOT admitted here — an excerpt is served solely
 *       from its own materialized copy (see the excerpt route), never by
 *       unlocking the full document.
 *
 * COMPOSE CONTRACT (the D1 fix): both methods return a PLACEHOLDER-FREE
 * fragment beginning with " AND ..." in which every user-derived value is a
 * hard-cast integer interpolated directly. The callers concatenate the
 * fragment into queries that are variously run through $wpdb->prepare() with
 * positional args (search/context/deep-search), or raw (pricing, bridge
 * pricing-density). A fragment containing % tokens or its own inner prepare()
 * would shift the outer positional bindings / double-prepare; this one cannot.
 *
 * PERF + PROVABILITY (the D9 shape): instead of a per-row correlated EXISTS,
 * the caller's admissible transcript doc-ids are pre-resolved ONCE per request
 * (a user is a party to only a handful of transcripts) and interpolated as a
 * PK IN (...) list. The allowed set is computed in PHP where the red-team
 * harness can log and assert it.
 *
 * ADMIN IS NOT PRIVILEGED HERE. Admin-ness relaxes only the non-transcript
 * half (preserving today's exact behavior for normal/admin_only docs). The
 * transcript half has NO admin branch — an admin who is not a party fails it.
 *
 * FAIL CLOSED: uid 0 / logged out / unknown → non-admin with an empty
 * transcript set → the fragment collapses to today's
 * " AND visibility='all_employees'", which excludes every transcript.
 *
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_ACL {

	/** The visibility value that marks a transcript. The ONLY transcript test. */
	const VIS_TRANSCRIPT = 'transcript_private';

	/** Per-request caches: uid → int[] of doc ids. */
	private static $party_cache = array();
	private static $whole_share_cache = array();

	// ──────────────────────────────────────────────────────────────
	//  The predicate
	// ──────────────────────────────────────────────────────────────

	/**
	 * Party-only predicate — the CHAT rule. Used by ZKV_TSA_Bridge on every
	 * query it runs, so no transcript byte can enter a model context except
	 * for a party.
	 *
	 * @param int    $uid   Requesting WP user id (server-derived, never model-supplied).
	 * @param string $alias Alias of wp_zkv_documents in the caller's query.
	 * @return string Placeholder-free SQL fragment beginning with " AND ".
	 */
	public static function sql_where_chat( $uid, $alias = 'd' ) {
		$ids = self::party_doc_ids( (int) $uid );
		return self::build_fragment( (int) $uid, $alias, $ids );
	}

	/**
	 * View predicate — party OR active whole-document share. Used by every
	 * human-facing read surface (REST, dashboard AJAX, file serve).
	 *
	 * @param int    $uid   Requesting WP user id.
	 * @param string $alias Alias of wp_zkv_documents in the caller's query.
	 * @return string Placeholder-free SQL fragment beginning with " AND ".
	 */
	public static function sql_where_view( $uid, $alias = 'd' ) {
		$uid = (int) $uid;
		$ids = array_values( array_unique( array_merge(
			self::party_doc_ids( $uid ),
			self::whole_share_doc_ids( $uid )
		) ) );
		return self::build_fragment( $uid, $alias, $ids );
	}

	/**
	 * Assemble the fragment from the pre-resolved admissible transcript ids.
	 *
	 * Non-admin, no ids : " AND {a}.visibility = 'all_employees'"   (== today)
	 * Non-admin, ids    : " AND ( {a}.visibility = 'all_employees' OR
	 *                       ( {a}.visibility = 'transcript_private' AND {a}.id IN (…) ) )"
	 * Admin, no ids     : " AND {a}.visibility <> 'transcript_private'"
	 * Admin, ids        : " AND ( {a}.visibility <> 'transcript_private' OR
	 *                       ( {a}.visibility = 'transcript_private' AND {a}.id IN (…) ) )"
	 *
	 * The transcript arm re-asserts visibility='transcript_private' so a stale
	 * ACL row on a doc that reverted to normal can never widen the normal rule.
	 */
	private static function build_fragment( $uid, $alias, array $ids ) {
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		if ( '' === $alias ) { $alias = 'd'; }
		$vis = self::VIS_TRANSCRIPT; // constant literal, no user input

		// Normal (non-transcript) half — today's exact behavior.
		if ( $uid > 0 && self::is_admin_user( $uid ) ) {
			$normal = "{$alias}.visibility <> '{$vis}'";
		} else {
			$normal = "{$alias}.visibility = 'all_employees'";
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ), function ( $v ) {
			return $v > 0;
		} ) );

		if ( empty( $ids ) || $uid <= 0 ) {
			return " AND {$normal}";
		}

		$in = implode( ',', $ids );
		return " AND ( {$normal} OR ( {$alias}.visibility = '{$vis}' AND {$alias}.id IN ({$in}) ) )";
	}

	// ──────────────────────────────────────────────────────────────
	//  Pre-resolved id sets (per-request cached)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Transcript doc-ids the user is a PARTY to. The chat-admissible set.
	 *
	 * @return int[]
	 */
	public static function party_doc_ids( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) { return array(); }
		if ( isset( self::$party_cache[ $uid ] ) ) { return self::$party_cache[ $uid ]; }

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.document_id
			 FROM {$wpdb->prefix}zkv_doc_parties p
			 WHERE p.user_id = %d",
			$uid
		) );
		self::$party_cache[ $uid ] = array_map( 'intval', (array) $ids );
		return self::$party_cache[ $uid ];
	}

	/**
	 * Transcript doc-ids the user holds an ACTIVE WHOLE-document share on.
	 * Active = not revoked, not expired — evaluated NOW, on this request, so
	 * revocation/expiry take effect on the recipient's next click.
	 *
	 * @return int[]
	 */
	public static function whole_share_doc_ids( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) { return array(); }
		if ( isset( self::$whole_share_cache[ $uid ] ) ) { return self::$whole_share_cache[ $uid ]; }

		global $wpdb;
		$now = esc_sql( current_time( 'mysql' ) );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT s.document_id
			 FROM {$wpdb->prefix}zkv_doc_shares s
			 WHERE s.shared_with = %d
			   AND s.scope = 'whole'
			   AND s.revoked_at IS NULL
			   AND ( s.expires_at IS NULL OR s.expires_at > '{$now}' )",
			$uid
		) );
		self::$whole_share_cache[ $uid ] = array_map( 'intval', (array) $ids );
		return self::$whole_share_cache[ $uid ];
	}

	/** Drop the per-request caches (call after any party/share mutation). */
	public static function reset_cache() {
		self::$party_cache       = array();
		self::$whole_share_cache = array();
	}

	// ──────────────────────────────────────────────────────────────
	//  Point checks
	// ──────────────────────────────────────────────────────────────

	/** Is this user a named party of this document? (The chat-mode point check.) */
	public static function is_party( $uid, $doc_id ) {
		return in_array( (int) $doc_id, self::party_doc_ids( (int) $uid ), true );
	}

	/**
	 * May this user open/read the WHOLE document? Party OR active whole share.
	 * (The serve-gate / download point check. Excerpt shares do NOT qualify.)
	 */
	public static function can_view_whole( $uid, $doc_id ) {
		$doc_id = (int) $doc_id;
		if ( self::is_party( $uid, $doc_id ) ) { return true; }
		return in_array( $doc_id, self::whole_share_doc_ids( (int) $uid ), true );
	}

	/** Is this visibility value the transcript marker? */
	public static function is_transcript_visibility( $visibility ) {
		return self::VIS_TRANSCRIPT === (string) $visibility;
	}

	/** Is this document a transcript (by id)? */
	public static function is_transcript( $doc_id ) {
		global $wpdb;
		$vis = $wpdb->get_var( $wpdb->prepare(
			"SELECT visibility FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			(int) $doc_id
		) );
		return self::is_transcript_visibility( $vis );
	}

	/** WP user ids of all parties of a document. @return int[] */
	public static function party_user_ids( $doc_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}zkv_doc_parties WHERE document_id = %d",
			(int) $doc_id
		) );
		return array_map( 'intval', (array) $ids );
	}

	/** Full party rows (user_id, speaker_label, match_method) for UI/audit. */
	public static function parties( $doc_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, speaker_label, match_method, created_at
			 FROM {$wpdb->prefix}zkv_doc_parties
			 WHERE document_id = %d ORDER BY id ASC",
			(int) $doc_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Load one ACTIVE excerpt share addressed to this user, by share id.
	 * The excerpt serve route's ONLY read. Returns the row (incl. the
	 * materialized excerpt_text) or null — never falls back to the document.
	 */
	public static function active_excerpt_share( $share_id, $uid ) {
		global $wpdb;
		$now = esc_sql( current_time( 'mysql' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.* FROM {$wpdb->prefix}zkv_doc_shares s
			 WHERE s.id = %d
			   AND s.shared_with = %d
			   AND s.scope = 'excerpt'
			   AND s.revoked_at IS NULL
			   AND ( s.expires_at IS NULL OR s.expires_at > '{$now}' )",
			(int) $share_id, (int) $uid
		), ARRAY_A );
		return $row ?: null;
	}

	/** All live shares on a document (for the party-facing "Shared with" list). */
	public static function live_shares( $doc_id ) {
		global $wpdb;
		$now = esc_sql( current_time( 'mysql' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.shared_by, s.shared_with, s.scope, s.excerpt_mode,
			        s.expires_at, s.created_at
			 FROM {$wpdb->prefix}zkv_doc_shares s
			 WHERE s.document_id = %d
			   AND s.revoked_at IS NULL
			   AND ( s.expires_at IS NULL OR s.expires_at > '{$now}' )
			 ORDER BY s.id DESC",
			(int) $doc_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Active excerpt shares held BY a user (recipient side, for their list). */
	public static function excerpt_shares_for( $uid ) {
		global $wpdb;
		$now = esc_sql( current_time( 'mysql' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.document_id, s.shared_by, s.expires_at, s.created_at
			 FROM {$wpdb->prefix}zkv_doc_shares s
			 WHERE s.shared_with = %d
			   AND s.scope = 'excerpt'
			   AND s.revoked_at IS NULL
			   AND ( s.expires_at IS NULL OR s.expires_at > '{$now}' )
			 ORDER BY s.id DESC",
			(int) $uid
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	// ──────────────────────────────────────────────────────────────
	//  Role helper (mirrors the platform's existing admin definition)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Same admin test the existing visibility_sql() implementations use:
	 * ZDZ_User_Roles::is_admin_role( first role ) when the theme is present,
	 * else manage_options. Applies ONLY to the non-transcript half of the
	 * predicate — never to the transcript arm.
	 */
	public static function is_admin_user( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) { return false; }
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$user  = get_userdata( $uid );
			$roles = (array) ( $user ? $user->roles : array() );
			$role  = ! empty( $roles ) ? $roles[0] : '';
			return ZDZ_User_Roles::is_admin_role( $role );
		}
		return user_can( $uid, 'manage_options' );
	}

	// ──────────────────────────────────────────────────────────────
	//  Audit log
	// ──────────────────────────────────────────────────────────────

	/**
	 * Write a transcript/share event to wp_zkv_access_log.
	 * action ≤ 30 chars (column limit); detail lands in search_query (≤500).
	 */
	public static function log( $action, $doc_id = 0, $actor_uid = 0, $detail = '' ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'zkv_access_log', array(
			'user_id'      => (int) ( $actor_uid ?: get_current_user_id() ),
			'action'       => substr( (string) $action, 0, 30 ),
			'document_id'  => (int) $doc_id ?: null,
			'search_query' => substr( (string) $detail, 0, 500 ),
			'context'      => 'transcript',
			'created_at'   => current_time( 'mysql' ),
		) );
	}
}
