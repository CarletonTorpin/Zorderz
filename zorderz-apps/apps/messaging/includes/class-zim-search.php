<?php
/**
 * ZIM_Search
 *
 * Per-conversation full-text search via MySQL MATCH…AGAINST, with a LIKE
 * fallback for hosts where FULLTEXT isn't available (engines or builds that
 * reject the migration's ALTER TABLE).
 *
 * MEP SCOPE:
 *   - Per-conversation only. Cross-conversation search is explicitly in the
 *     "Not in this release" list — do not add it here without a v1.1 re-plan.
 *   - Soft-deleted messages are NEVER returned (Things NOT to do rule).
 *
 * BOOLEAN-MODE HYGIENE:
 *   MATCH…AGAINST (… IN BOOLEAN MODE) honours operators (+ - > < ( ) ~ * ").
 *   We strip all of them from the user query to avoid surprise
 *   syntax errors and "I searched for -important and got nothing" confusion.
 *   Tokens of length < 3 are dropped (InnoDB default ft_min_word_len is 3);
 *   the last surviving token gets a trailing * for prefix matches.
 *
 * INDEX USE:
 *   Acceptance #15: EXPLAIN on a scroll query must show index use, not a
 *   full table scan. That query is covered by idx_conv_created. Search
 *   adds FULLTEXT on body. Both declared at table creation (Trap 8).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Search {

	/** Cached FT-availability probe (per-request). */
	private static $ft_available = null;

	/**
	 * Search messages in a single conversation.
	 *
	 * @param int    $conversation_id
	 * @param string $query
	 * @param int    $user_id   caller — membership re-checked as defence-in-depth
	 * @param int    $limit
	 * @return array of hydrated message rows (shape matches ZIM_Messages::fetch_since)
	 */
	public static function search( $conversation_id, $query, $user_id, $limit = 50 ) {
		$conversation_id = (int) $conversation_id;
		$user_id         = (int) $user_id;
		$limit           = max( 1, min( 100, (int) $limit ) );

		if ( ! ZIM_Membership::is_member( $user_id, $conversation_id ) ) {
			return array();
		}

		$query = trim( (string) $query );
		if ( '' === $query || mb_strlen( $query ) < 2 ) {
			return array();
		}

		$ids = self::has_fulltext_index()
			? self::search_fulltext( $conversation_id, $query, $limit )
			: self::search_like( $conversation_id, $query, $limit );

		if ( empty( $ids ) ) {
			return array();
		}
		return self::hydrate_by_ids( $ids );
	}

	/**
	 * Does wp_zim_messages have a FULLTEXT index on body?
	 * Cached per-request. Checked against information_schema.
	 */
	public static function has_fulltext_index() {
		if ( null !== self::$ft_available ) {
			return self::$ft_available;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.STATISTICS
			  WHERE TABLE_SCHEMA = DATABASE()
			    AND TABLE_NAME   = %s
			    AND INDEX_NAME   = 'idx_body_ft'",
			$wpdb->prefix . 'zim_messages'
		) );
		self::$ft_available = $count > 0;
		return self::$ft_available;
	}

	/**
	 * FULLTEXT path — returns message ids (most-recent first).
	 */
	private static function search_fulltext( $conversation_id, $query, $limit ) {
		global $wpdb;
		$boolean = self::to_boolean_mode( $query );
		if ( '' === $boolean ) {
			// Query too short for FT minimum-word — fall through to LIKE.
			return self::search_like( $conversation_id, $query, $limit );
		}

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d
			    AND deleted_at IS NULL
			    AND MATCH(body) AGAINST (%s IN BOOLEAN MODE)
			  ORDER BY created_at DESC
			  LIMIT %d",
			$conversation_id,
			$boolean,
			$limit
		) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * LIKE fallback. Slow on big tables but always works.
	 */
	private static function search_like( $conversation_id, $query, $limit ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d
			    AND deleted_at IS NULL
			    AND body LIKE %s
			  ORDER BY created_at DESC
			  LIMIT %d",
			$conversation_id,
			$like,
			$limit
		) ) );
	}

	/**
	 * Rewrite a free-text query as a BOOLEAN-mode expression.
	 * Strip all operators; require each remaining ≥3-char token; prefix-wildcard the last.
	 * Returns '' if nothing usable remains.
	 */
	private static function to_boolean_mode( $query ) {
		$clean  = preg_replace( '/[+\-><\(\)~*"@]+/u', ' ', $query );
		$tokens = preg_split( '/\s+/u', (string) $clean, -1, PREG_SPLIT_NO_EMPTY );
		$kept   = array();
		foreach ( $tokens as $t ) {
			if ( mb_strlen( $t ) >= 3 ) {
				$kept[] = $t;
			}
		}
		if ( empty( $kept ) ) {
			return '';
		}
		$last_ix = count( $kept ) - 1;
		foreach ( $kept as $i => &$t ) {
			$t = '+' . $t . ( $i === $last_ix ? '*' : '' );
		}
		return implode( ' ', $kept );
	}

	/**
	 * Hydrate messages by id, preserving the order we were given.
	 * Shape matches ZIM_Messages::fetch_since output so the frontend can
	 * render search hits with the same renderer.
	 */
	private static function hydrate_by_ids( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, body,
			        created_at, edited_at, deleted_at
			   FROM {$wpdb->prefix}zim_messages
			  WHERE id IN ({$placeholders})",
			...$ids
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		// Reorder to match the ids array (FIND_IN_SET would work but this is portable).
		$by_id = array();
		foreach ( $rows as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
		}
		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		// Batch-load authors, attachments, mentions.
		$author_ids = array_unique( array_map( 'intval', wp_list_pluck( $ordered, 'author_user_id' ) ) );
		$authors = array();
		foreach ( $author_ids as $uid ) {
			$u = get_userdata( $uid );
			$authors[ $uid ] = array(
				'id'    => $uid,
				'name'  => $u ? $u->display_name : 'Unknown',
				'login' => $u ? $u->user_login   : '',
			);
		}
		$message_ids = array_map( 'intval', wp_list_pluck( $ordered, 'id' ) );
		$attachments_by_message = ZIM_Attachments::for_messages( $message_ids );
		$mentions_by_message    = ZIM_Mentions::for_messages( $message_ids );

		$out = array();
		foreach ( $ordered as $r ) {
			$mid = (int) $r['id'];
			$created_ts = strtotime( $r['created_at'] . ' UTC' );
			$edited_ts  = $r['edited_at'] ? strtotime( $r['edited_at'] . ' UTC' ) : 0;
			$out[] = array(
				'id'              => $mid,
				'conversation_id' => (int) $r['conversation_id'],
				'author'          => $authors[ (int) $r['author_user_id'] ] ?? array(
					'id' => (int) $r['author_user_id'], 'name' => 'Unknown', 'login' => '',
				),
				'body'            => (string) $r['body'],
				'body_raw'        => (string) $r['body'],
				'created_at'      => $created_ts ? gmdate( 'c', $created_ts ) : null,
				'edited_at'       => $edited_ts  ? gmdate( 'c', $edited_ts )  : null,
				'deleted'         => false, // excluded at query time
				'attachments'     => $attachments_by_message[ $mid ] ?? array(),
				'mentions'        => $mentions_by_message[ $mid ] ?? array(),
			);
		}
		return $out;
	}
}
