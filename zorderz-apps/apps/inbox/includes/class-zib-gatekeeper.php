<?php
/**
 * ZIB_Gatekeeper — the ONE class permitted to read Ballast content.
 *
 * The Ballast doctrine's single door. Nothing else in the platform issues a
 * SELECT against wp_zib_messages / _participants; every read comes through here
 * and is default-deny + audited. P2 implements the first whitelisted egress
 * path, `owner_search`: an owner searching their OWN mail. (owner_chat / P3,
 * admin_search + owner_share / P4 add the other three paths later.)
 *
 * Guarantees enforced here:
 *   - OWNER-ONLY: owner_user_id = <hard-cast int>; for owner_search the subject
 *     is FORCED equal to the actor, so cross-user is unexpressible.
 *   - KIOSK DENIED: the shared device never reads mail.
 *   - FAIL-CLOSED: uid 0 / feature off / not owned → deny, logged.
 *   - AUDITED: every call (allow or deny) appends to wp_zib_access_log; search
 *     terms are hashed, never stored.
 *   - SAFE BODIES: the reader returns email body as STRIPPED TEXT — raw email
 *     HTML (attacker-controlled) is never handed to the browser to render.
 *
 * @since 0.3.0 (P2 — owner search)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Gatekeeper {

	const MAX_LIMIT = 50;

	/** owner_chat — how many messages a single assistant turn may surface, and
	 *  how many chars of each body the bot is allowed to see. Bounds how much of the
	 *  owner's OWN mail enters the model context per turn (small, deliberate egress). */
	const MAX_CHAT_HITS   = 8;
	const CHAT_EXCERPT_CH = 260;   // v0.9.5: a minimized slice (was 600 — surfaced marketing footers)

	/** owner_followups (P5) — how many THREADS a follow-up digest may surface (a digest
	 *  legitimately wants more than a single lookup's 8, but is still bounded + owner-only). */
	const MAX_FOLLOWUP = 25;

	/** needs_reply (P5.1 "pressing") — a reply you owe from longer ago than this is almost
	 *  certainly dead; drop it from the "what's pressing" window so the digest stays live and
	 *  the age term of the pressure score can't be dominated by one ancient thread. ~6 weeks. */
	const NEEDS_MAX_AGE_DAYS = 45;

	/** owner_aggregate — how many source messages a computed answer cites for grounding. */
	const MAX_AGG_SOURCES = 12;

	private static function t_msg(): string { global $wpdb; return $wpdb->prefix . 'zib_messages'; }
	private static function t_ext(): string { global $wpdb; return $wpdb->prefix . 'zib_extracts'; }
	private static function t_party(): string { global $wpdb; return $wpdb->prefix . 'zib_participants'; }
	private static function t_log(): string { global $wpdb; return $wpdb->prefix . 'zib_access_log'; }

	// ── the gate ────────────────────────────────────────────────────

	/** Owner-path gate: logged-in, feature live, NOT the kiosk. */
	private static function gate_owner( int $actor ): bool {
		if ( $actor <= 0 || ! ZIB_Settings::feature_enabled() ) {
			return false;
		}
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $actor ) ) {
			return false; // kiosk is a device, not a person
		}
		return true;
	}

	/**
	 * Is this actor allowed to read OTHER people's indexed mail AT ALL? (Necessary, not
	 * sufficient — the per-mailbox gate is still checked by gate_admin_read.) True when the
	 * actor can manage_options, OR holds one of the identity plugin's configured reader roles
	 * Guarded: with the identity plugin absent, only manage_options qualifies. Pure
	 * capability check — names no mailbox.
	 */
	private static function actor_is_reader( int $actor ): bool {
		if ( $actor <= 0 ) {
			return false;
		}
		if ( user_can( $actor, 'manage_options' ) ) {
			return true;
		}
		if ( class_exists( 'ZMI_Store' ) ) {
			$allowed = array_map( 'strtolower', (array) ZMI_Store::service_mailbox_reader_roles() );
			$u       = get_userdata( $actor );
			if ( $u && ! empty( $u->roles ) ) {
				foreach ( (array) $u->roles as $role ) {
					if ( in_array( strtolower( (string) $role ), $allowed, true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * PUBLIC capability probe: may this user read OTHER people's mail at all (a reader, non-kiosk,
	 * feature live)? Necessary-not-sufficient — a specific mailbox still needs its gate on. The
	 * engine calls this to decide whether to OFFER the cross-user chat path at all (so a non-reader's
	 * turn is routed exactly as before). Names no mailbox; reads nothing.
	 */
	public static function is_reader( int $actor ): bool {
		if ( $actor <= 0 || ! ZIB_Settings::feature_enabled() ) {
			return false;
		}
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $actor ) ) {
			return false;
		}
		return self::actor_is_reader( $actor );
	}

	/**
	 * Admin-read gate: may $actor read $subject's indexed mail RIGHT NOW? Default-deny, the
	 * admin-side mirror of gate_owner, fenced on every axis:
	 *   - FEATURE LIVE + actor is a real (non-kiosk) READER (actor_is_reader).
	 *   - PER-MAILBOX GATE ON: the subject's account row carries admin_search_enabled = 1. The
	 *     admin owns this flag (admin_set_read); with it 0 the mailbox is closed to admins even
	 *     while indexing continues — so enabling INDEXING never implies READ.
	 *   - SUBJECT real + account not disabled.
	 * Cross-user reads are expressible ONLY through this one guarded door, and every call that
	 * reaches it is logged by the caller.
	 */
	private static function gate_admin_read( int $actor, int $subject ): bool {
		if ( $subject <= 0 || ! ZIB_Settings::feature_enabled() ) {
			return false;
		}
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $actor ) ) {
			return false; // kiosk is a device, not a person
		}
		if ( ! self::actor_is_reader( $actor ) ) {
			return false;
		}
		global $wpdb;
		$on = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT admin_search_enabled FROM ' . $wpdb->prefix . 'zib_accounts WHERE owner_user_id = %d AND status <> %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$subject, 'disabled'
		) );
		return 1 === $on;
	}

	// ── owner_search ────────────────────────────────────────────────

	/**
	 * Search the actor's OWN indexed mail. Summaries only (no body).
	 *
	 * @return array { ok:bool, results:array[], total_shown:int }
	 */
	public static function owner_search( int $actor, string $query, int $limit = 30, int $offset = 0 ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_search', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'results' => array() );
		}
		global $wpdb;
		$limit  = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset = max( 0, $offset );
		$cols   = 'id, ms_conversation_id, folder, direction, class, from_addr, from_name, received_at, subject, snippet, has_attachments';
		$query  = trim( $query );

		if ( '' === $query ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE owner_user_id = %d ORDER BY received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$actor, $limit, $offset
			) );
		} else {
			$bool = self::build_boolean_query( $query );
			if ( '' === $bool ) {
				$rows = array();
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare(
					'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
					 FROM ' . self::t_msg() . '
					 WHERE owner_user_id = %d AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
					 ORDER BY score DESC, received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$bool, $actor, $bool, $limit, $offset
				) );
				// Defensive fallback if the FULLTEXT index is somehow absent.
				if ( '' !== (string) $wpdb->last_error ) {
					$rows = self::like_search( $actor, $query, $limit, $offset, $cols );
				}
			}
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'              => (int) $r->id,
				'conversation'    => (string) $r->ms_conversation_id,
				'folder'          => (string) $r->folder,
				'direction'       => (string) $r->direction,
				'class'           => (string) $r->class,
				'from'            => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'subject'         => (string) $r->subject,
				'snippet'         => (string) $r->snippet,
				'received_at'     => $r->received_at ? (string) $r->received_at : '',
				'has_attachments' => (int) $r->has_attachments,
			);
		}
		self::log( $actor, $actor, 'owner_search', 'allow', '', substr( sha1( $query ), 0, 16 ), count( $out ) );
		return array( 'ok' => true, 'results' => $out );
	}

	/** LIKE fallback (only if FULLTEXT is unavailable). */
	private static function like_search( int $actor, string $query, int $limit, int $offset, string $cols ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d AND (subject LIKE %s OR snippet LIKE %s OR parties_text LIKE %s)
			 ORDER BY received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$actor, $like, $like, $like, $limit, $offset
		) );
	}

	/**
	 * v0.9.6 SUBSTRING RECALL NET. Fired ONLY when FULLTEXT (exact + fuzzy) returned nothing
	 * (from owner_chat): a last owner-scoped, direction-filtered, capped, recency-first LIKE pass
	 * over the SAME columns as the FULLTEXT search, INCLUDING gist + body_clean_text — so a term
	 * MySQL FULLTEXT cannot tokenise (below its minimum token size, or glued to punctuation / buried
	 * inside a longer token) is still found when it is genuinely present. A true absence returns an
	 * empty set, so the honest empty is preserved. Single bounded substring, esc_like'd; a
	 * term under 2 chars is not worth a full scan. Owner scope is FORCED (owner_user_id = $actor).
	 */
	private static function like_recall( int $actor, string $query, string $dir_sql, array $dir_args, int $limit, string $cols ): array {
		global $wpdb;
		$term = trim( $query );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term );
		if ( $len < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$args = array_merge(
			array( $actor ),
			$dir_args,
			array( $like, $like, $like, $like, $like, $limit )
		);
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d' . $dir_sql . '
			   AND ( subject LIKE %s OR snippet LIKE %s OR parties_text LIKE %s OR gist LIKE %s OR body_clean_text LIKE %s )
			 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- substring recall net; owner-scoped, capped, recency-first
			$args
		) );
	}

	/**
	 * v0.9.7 SEARCH-COVERAGE snapshot for the owner's mailbox — the honest "as-of" behind an
	 * empty search: how many messages are indexed, the date span they cover, and how many bodies
	 * are NOT yet searchable — matching the ENRICHER's own backlog (a body still to be cleaned:
	 * enrich_ver behind, body present, no clean text yet), NOT every text-empty row (a notification
	 * or image-only email is enriched with empty text and is DONE, never "still indexing"). Lets
	 * the render tell an empty apart: "the word isn't in your mail" vs "N messages aren't indexed
	 * yet". Owner-scoped + gated; one cheap owner-keyed aggregate. Read-only.
	 */
	public static function mail_coverage( int $actor ): array {
		if ( ! self::gate_owner( $actor ) ) {
			return array( 'ok' => false );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) AS total, MIN(received_at) AS oldest, MAX(received_at) AS newest,
			        SUM( CASE WHEN ( body_clean_text IS NULL OR body_clean_text = %s )
			                       AND COALESCE( enrich_ver, 0 ) < %d
			                       AND body_enc IS NOT NULL AND body_enc <> %s
			                  THEN 1 ELSE 0 END ) AS pending
			 FROM ' . self::t_msg() . ' WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- owner-scoped coverage aggregate
			'', ( class_exists( 'ZIB_Enrich' ) ? (int) ZIB_Enrich::ENRICH_VER : 2 ), '', $actor
		) );
		if ( ! is_object( $row ) ) {
			return array( 'ok' => false );
		}
		return array(
			'ok'      => true,
			'total'   => (int) $row->total,
			'oldest'  => (string) $row->oldest,
			'newest'  => (string) $row->newest,
			'pending' => (int) $row->pending,
		);
	}

	/**
	 * v0.9.9 RECIPIENT-SCOPED SENT SEARCH. "the last email I sent to <person>" names the RECIPIENT,
	 * who lives in parties_text (from+to+cc), NOT the body. Match the person in parties_text
	 * SPECIFICALLY, so a message that merely MENTIONS them in its body while actually sent to someone
	 * else can never be returned as "sent to <person>". Owner-scoped, direction='out', capped,
	 * recency-first. Fixes the "sent to Alex returned a message sent to Jordan" report.
	 */
	/**
	 * v0.9.15 Normalise a recipient NAME the model may have emitted with a scoping prefix — "to:Casey",
	 * "to Casey", "for: Casey", "sent to Casey" — down to the bare contact ("Casey"), so recipient_match
	 * and the render lead never carry "to:" into the match or the wording. A real name that merely starts
	 * with those letters ("Tom", "Tony", "Format") is untouched (the prefix needs a colon or a space).
	 * Pure.
	 */
	public static function normalize_recipient( string $q ): string {
		$q = trim( (string) $q );
		$q = preg_replace( '/^\s*(?:sent\s+to|addressed\s+to|recipient|to|for)\s*:\s*/i', '', $q );
		$q = preg_replace( '/^\s*(?:sent\s+to|addressed\s+to|to|for)\s+/i', '', $q );
		return trim( (string) $q, " \t\n\r\0\x0B:?.,\"'" );
	}

	/**
	 * v0.9.14 CONTACT RESOLUTION for a recipient search. A person's name must catch ALL of their
	 * addresses — "Casey" should match sales@example.com / "the company name", not just the
	 * literal string "Casey". Builds a parties_text OR-match over: (1) the name itself; (2) MAILBOX-
	 * DERIVED addresses — every distinct address ever seen under a party-name containing the name (so a
	 * contact whose address once appeared as "Casey V" is caught even when a later mail shows only the
	 * company); (3) CRM-resolved $aliases the engine passes (first-party addresses / company names). Stays
	 * parties_text-only (never the body). Owner-scoped. Returns array( sql_fragment, args ) ('' if none).
	 */
	private static function recipient_match( int $actor, string $name, array $aliases = array() ): array {
		global $wpdb;
		$name  = self::normalize_recipient( $name );
		$named = ( '' !== $name && ! self::is_generic_recipient( $name ) );
		$terms = array();
		if ( $named ) {
			$terms[ strtolower( $name ) ] = $name;
			$rows = $wpdb->get_col( $wpdb->prepare(
				'SELECT DISTINCT addr FROM ' . self::t_party() . '
				 WHERE owner_user_id = %d AND addr <> %s AND name LIKE %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- contact resolution: parties only, never the body
				$actor, '', '%' . $wpdb->esc_like( $name ) . '%', 25
			) );
			foreach ( (array) $rows as $a ) {
				$a = strtolower( trim( (string) $a ) );
				if ( '' !== $a ) { $terms[ $a ] = $a; }
			}
		}
		foreach ( (array) $aliases as $a ) {
			$a = trim( (string) $a );
			if ( '' !== $a ) { $terms[ strtolower( $a ) ] = $a; }
		}
		if ( empty( $terms ) ) {
			return array( '', array() );
		}
		$ors = array(); $args = array();
		foreach ( $terms as $t ) {
			$ors[]  = 'parties_text LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $t ) . '%';
		}
		return array( ' AND (' . implode( ' OR ', $ors ) . ')', $args );
	}

	private static function sent_to_recipient( int $actor, string $query, int $limit, string $cols, array $aliases = array(), array $topic_terms = array() ): array {
		global $wpdb;
		list( $rsql, $rargs ) = self::recipient_match( $actor, $query, $aliases );
		if ( '' === $rsql ) {
			$rsql  = ' AND parties_text LIKE %s';
			$rargs = array( '%' . $wpdb->esc_like( trim( $query ) ) . '%' );
		}
		// "…to Jordan ABOUT Sam's invoice" — every topic word must appear in the message
		// (subject, preview, gist or text). The addressee still comes from parties_text only.
		$tsql  = '';
		$targs = array();
		$tbool = self::topic_boolean( $topic_terms );
		if ( '' !== $tbool ) {
			$tsql  = ' AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)';
			$targs = array( $tbool );
		}
		$args = array_merge( array( $actor, 'out' ), $rargs, $targs, array( $limit ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns from the caller's fixed list, table from self::t_msg(); the recipient and topic fragments hold placeholders only
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d AND direction = %s' . $rsql . $tsql . '
			 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- recipient-scoped: the addressee lives in parties_text, never the body
			$args
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $tsql && '' !== (string) $wpdb->last_error ) {
			// FULLTEXT unavailable → the same AND-of-terms as substrings over the same text columns.
			$lsql  = '';
			$largs = array();
			foreach ( $topic_terms as $t ) {
				$like   = '%' . $wpdb->esc_like( $t ) . '%';
				$lsql  .= ' AND (subject LIKE %s OR snippet LIKE %s OR gist LIKE %s OR body_clean_text LIKE %s)';
				$largs  = array_merge( $largs, array( $like, $like, $like, $like ) );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns from the caller's fixed list, table from self::t_msg(); the recipient and topic-substring fragments hold placeholders only
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
				 WHERE owner_user_id = %d AND direction = %s' . $rsql . $lsql . '
				 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- recipient-scoped + topic substrings
				array_merge( array( $actor, 'out' ), $rargs, $largs, array( $limit ) )
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		}
		return $rows;
	}

	/**
	 * Has the owner exchanged mail with someone of this NAME (or address)? Owner-scoped read of the
	 * participants table (parties only, never a body) — used to split a folded "person + subject" key.
	 * Kiosk / non-owners get false (gate_owner), so nothing is learned about anyone's mail.
	 */
	public static function recipient_resolves( int $actor, string $name ): bool {
		$name = self::normalize_recipient( $name );
		if ( strlen( $name ) < 3 || self::is_generic_recipient( $name ) || ! self::gate_owner( $actor ) ) {
			return false;
		}
		global $wpdb;
		// At the START of a word of the name, or of the address — "home" must not match "Homeowner Ln".
		$e = $wpdb->esc_like( $name );
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::t_party() . ' WHERE owner_user_id = %d AND ( name LIKE %s OR name LIKE %s OR addr LIKE %s ) LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- parties only
			$actor, $e . '%', '% ' . $e . '%', $e . '%'
		) );
	}

	/**
	 * The words of a sent-mail TOPIC ("Sam's invoices" -> ['sam','invoice']): lowercase,
	 * letters/digits only, a possessive or plural "s" dropped from words of 5+ letters (quotes -> quote,
	 * invoices -> invoice; each term is then a prefix, so "sam*" still matches "Sam's"), filler
	 * words and words under 3 letters skipped, at most 4 terms. Pure.
	 *
	 * @return string[]
	 */
	public static function topic_terms( string $topic ): array {
		$stop  = array( 'the', 'and', 'for', 'with', 'about', 'his', 'her', 'their', 'our', 'your', 'my', 'any', 'some', 'that', 'this', 'from', 'into', 'over', 'what', 'when', 'was', 'were', 'are', 'has', 'have', 'had' );
		$topic = (string) preg_replace( "/['\u{2019}]s\\b/iu", '', $topic ); // "Morgan's" -> "Morgan", not "morgans"
		$topic = strtolower( str_replace( array( "'", "\u{2019}" ), '', $topic ) );
		$out   = array();
		foreach ( preg_split( '/[^a-z0-9]+/', $topic ) as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			if ( strlen( $w ) >= 5 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 );
			}
			$out[ $w ] = $w;
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return array_values( $out );
	}

	/** '+sam* +invoice*' — every term required, as a prefix. '' for no terms. Pure. */
	public static function topic_boolean( array $terms ): string {
		$parts = array();
		foreach ( $terms as $t ) {
			$t = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $t ) );
			if ( strlen( $t ) >= 3 ) {
				$parts[] = '+' . $t . '*';
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * v0.9.11 RECIPIENT LABEL for a message the owner SENT. A sent message's addressees live
	 * in the participants table (role='to'); its "from" field is the owner themselves, so the
	 * render path (which keyed on "from") showed a sent row as "you -> {from}" == "you ->
	 * yourself". This returns the real addressee(s): the first formatted via fmt_addr, plus a
	 * " +N more" suffix when the message went to several people. Owner- AND message-scoped.
	 * The addressee comes from the parties table, NEVER the body (same doctrine as
	 * sent_to_recipient) so recipient identity can never be steered by mailed-in content.
	 *
	 * @return string  e.g. "Casey Example <casey@example.com>", with " +2 more" appended for a
	 *                 multi-recipient send; '' when no addressee was stored.
	 */
	private static function recipient_label( int $actor, int $message_id ): string {
		if ( $actor <= 0 || $message_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$tos = $wpdb->get_results( $wpdb->prepare(
			'SELECT addr, name FROM ' . self::t_party() . '
			 WHERE message_id = %d AND owner_user_id = %d AND role = %s
			 ORDER BY id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- addressee is parties-only, never the body
			$message_id, $actor, 'to'
		) );
		$labels = array();
		foreach ( (array) $tos as $t ) {
			$who = self::fmt_addr( (string) $t->name, (string) $t->addr );
			if ( '' !== $who ) {
				$labels[] = $who;
			}
		}
		if ( empty( $labels ) ) {
			return '';
		}
		$extra = count( $labels ) - 1;
		return $extra > 0 ? $labels[0] . ' +' . $extra . ' more' : $labels[0];
	}

	/**
	 * Browse the actor's OWN indexed mail by folder, most-recent first, paginated. Summaries only.
	 * $folder: a 32-hex folder_hash (a real Graph folder), a coarse bucket ('inbox'|'sent'|'other'),
	 * or '' for all mail. Owner-forced, kiosk-denied, capped.
	 *
	 * @return array { ok, results:[ {id, folder, folder_name, direction, from, subject, snippet, received_at, has_attachments, unread} ], folder }
	 */
	public static function owner_browse( int $actor, string $folder = '', int $limit = 30, int $offset = 0 ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_browse', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'results' => array() );
		}
		global $wpdb;
		$limit  = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset = max( 0, $offset );
		$folder = trim( $folder );
		$cols   = 'id, folder, folder_name, direction, from_addr, from_name, received_at, subject, snippet, has_attachments, is_read';

		$where = 'owner_user_id = %d';
		$args  = array( $actor );
		if ( preg_match( '/^[a-f0-9]{32}$/i', $folder ) ) {
			$where .= ' AND folder_hash = %s';           // a real Graph folder, keyed by md5(ms_folder_id)
			$args[] = strtolower( $folder );
		} elseif ( in_array( strtolower( $folder ), array( 'inbox', 'sent', 'other' ), true ) ) {
			$where .= ' AND folder = %s';                // a coarse bucket
			$args[] = strtolower( $folder );
		}
		// else '' / unrecognised → all indexed mail (no folder predicate)

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE ' . $where . '
			 ORDER BY received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- summaries only, never the body
			array_merge( $args, array( $limit, $offset ) )
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'              => (int) $r->id,
				'folder'          => (string) $r->folder,
				'folder_name'     => (string) ( $r->folder_name ?? '' ),
				'direction'       => (string) $r->direction,
				'from'            => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'subject'         => (string) $r->subject,
				'snippet'         => (string) $r->snippet,
				'received_at'     => $r->received_at ? (string) $r->received_at : '',
				'has_attachments' => (int) $r->has_attachments,
				'unread'          => ( 1 === (int) $r->is_read ) ? 0 : 1, // Mail-list unread bolding
			);
		}
		self::log( $actor, $actor, 'owner_browse', 'allow', 'browse:' . ( '' !== $folder ? substr( $folder, 0, 12 ) : '*' ), '', count( $out ) );
		return array( 'ok' => true, 'results' => $out, 'folder' => $folder );
	}

	/**
	 * Strip a leading "from:" / "sender:" / "by:" prefix down to the bare contact (e.g. "from:Alex"
	 * → "Alex") so sender_match and the render lead never carry the prefix into the match. A real name
	 * that merely starts with those letters is untouched — the prefix needs a colon or a following
	 * space plus a whole word. Pure.
	 */
	public static function normalize_sender( string $q ): string {
		$q = trim( (string) $q );
		$q = preg_replace( '/^\s*(?:from|sender|by)\s*:\s*/i', '', $q );
		$q = preg_replace( '/^\s*(?:from|by)\s+/i', '', $q );
		return trim( (string) $q, " \t\n\r\0\x0B:?.,\"'" );
	}

	/**
	 * SENDER match — the received twin of recipient_match. Same name→address resolution (with the
	 * known-user preference and mailbox-derived addresses), but the predicate is scoped to the message's
	 * OWN from columns — (from_name LIKE OR from_addr LIKE) per term — not parties_text. On INBOUND mail
	 * the person we mean is the SENDER; matching parties_text would also catch mail where they were merely
	 * cc'd. Owner-scoped. Returns array( sql_fragment, args ) ('' if the name is generic / unresolvable).
	 * Deliberately duplicates recipient_match's resolution rather than refactoring it, so the tested SENT
	 * path stays unchanged.
	 */
	private static function sender_match( int $actor, string $name, array $aliases = array() ): array {
		global $wpdb;
		$name  = self::normalize_sender( $name );
		$named = ( '' !== $name && ! self::is_generic_recipient( $name ) );
		$terms = array();
		if ( $named ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT addr, MAX(is_internal) AS internal FROM ' . self::t_party() . '
				 WHERE owner_user_id = %d AND addr <> %s AND name LIKE %s
				 GROUP BY addr LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sender resolution: parties only, never the body
				$actor, '', '%' . $wpdb->esc_like( $name ) . '%', 25
			) );
			$internal = array();
			$external = array();
			foreach ( (array) $rows as $r ) {
				$a = strtolower( trim( (string) $r->addr ) );
				if ( '' === $a ) { continue; }
				if ( 1 === (int) $r->internal ) { $internal[ $a ] = $a; } else { $external[ $a ] = $a; }
			}
			if ( ! empty( $internal ) ) {
				$terms = $internal;                       // known-user preference (same as recipient_match)
			} else {
				$terms[ strtolower( $name ) ] = $name;    // broad name + every external address it used
				$terms = array_merge( $terms, $external );
			}
		}
		foreach ( (array) $aliases as $a ) {
			$a = trim( (string) $a );
			if ( '' !== $a ) { $terms[ strtolower( $a ) ] = $a; }
		}
		if ( empty( $terms ) ) {
			return array( '', array() );
		}
		$ors  = array();
		$args = array();
		foreach ( $terms as $t ) {
			$like   = '%' . $wpdb->esc_like( $t ) . '%';
			$ors[]  = '(from_name LIKE %s OR from_addr LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		return array( ' AND (' . implode( ' OR ', $ors ) . ')', $args );
	}

	/**
	 * "WHAT DID <person> SEND ME [today/this week]" — the RECEIVED twin of owner_told. A windowed,
	 * SENDER-scoped INBOUND read that returns the sender's composed text (quoted-reply/forward chains
	 * stripped) for the same [ZIB_MAIL] expandable card — so a customer who emailed a photo can be pulled
	 * up in chat, attachments and all. direction='in'; the sender is matched on the message's OWN from_*
	 * columns (sender_match), never the body; received_at is filtered to a site-local day window. Owner-
	 * forced, kiosk-denied, capped, recency-first, deduped exactly like owner_told. The card item carries
	 * 'from' (the sender via fmt_addr) where the sent card carries 'to'; from_name and from_addr are the stored
	 * columns as they are, for a caller that must know the address without reading it out of a display string.
	 *
	 * @param string $sender  sender name/addr; '' matches anyone (a pure "what did I receive today").
	 * @param string $since,$until  YYYY-MM-DD site-local dates; '' = unbounded.
	 * @return array { ok, results:[ { id, subject, received_at, from, from_name, from_addr, composed, has_attachments } ], sender, since, until }
	 */
	public static function owner_received( int $actor, string $sender, string $since = '', string $until = '', int $limit = self::MAX_CHAT_HITS, array $aliases = array() ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_received', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'results' => array() );
		}
		global $wpdb;
		$limit  = max( 1, min( self::MAX_CHAT_HITS, $limit ) );
		$sender = trim( $sender );
		$since  = self::sane_date( $since );
		$until  = self::sane_date( $until );

		$where = 'owner_user_id = %d AND direction = %s';
		$args  = array( $actor, 'in' );
		list( $ssql, $sargs ) = self::sender_match( $actor, $sender, $aliases );
		if ( '' !== $ssql ) { $where .= $ssql; $args = array_merge( $args, $sargs ); }
		if ( '' !== $since ) { $where .= ' AND received_at >= %s'; $args[] = self::local_date_to_utc( $since, false ); }
		if ( '' !== $until ) { $where .= ' AND received_at <= %s'; $args[] = self::local_date_to_utc( $until, true ); }

		// Over-fetch + dedup, identical to owner_told: all-folder sync can surface one inbound email from
		// several folders (Inbox + a filed copy), and a re-send can arrive twice. Collapse by RFC
		// Message-ID, falling back to a content signature (subject + composed body + send minute).
		$fetch = min( 60, max( $limit, $limit * 4 ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, received_at, subject, from_addr, from_name, ms_internet_message_id, has_attachments, body_enc, body_format
			 FROM ' . self::t_msg() . ' WHERE ' . $where . '
			 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sender lives in from_*, never the body
			array_merge( $args, array( $fetch ) )
		) );

		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $r ) {
			$text     = self::body_to_text( ZIB_Crypto::decrypt( (string) $r->body_enc ), (string) $r->body_format );
			$composed = self::strip_quoted( $text );

			$mid  = strtolower( trim( (string) $r->ms_internet_message_id ) );
			$mkey = ( '' !== $mid ) ? 'm:' . $mid : '';
			$csig = 'c:' . strtolower( trim( (string) $r->subject ) ) . '|'
				. substr( (string) $r->received_at, 0, 16 ) . '|' . $composed;
			if ( ( '' !== $mkey && isset( $seen[ $mkey ] ) ) || isset( $seen[ $csig ] ) ) {
				continue;
			}
			if ( '' !== $mkey ) { $seen[ $mkey ] = true; }
			$seen[ $csig ] = true;

			$out[] = array(
				'id'              => (int) $r->id,
				'subject'         => (string) $r->subject,
				'received_at'     => $r->received_at ? (string) $r->received_at : '',
				'from'            => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'from_name'       => (string) $r->from_name,
				'from_addr'       => (string) $r->from_addr,
				'composed'        => $composed,
				'has_attachments' => (int) ( $r->has_attachments ?? 0 ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		self::log( $actor, $actor, 'owner_received', 'allow', 'recv:' . ( '' !== $since ? $since : '*' ) . '..' . ( '' !== $until ? $until : '*' ), substr( sha1( $sender ), 0, 16 ), count( $out ) );
		return array( 'ok' => true, 'results' => $out, 'sender' => $sender, 'since' => $since, 'until' => $until );
	}

	/**
	 * v0.9.13 "WHAT DID I TELL <person> [today/this week]" — a windowed, recipient-scoped SENT read
	 * that returns WHAT THE OWNER WROTE (composed text, quoted reply/forward chains stripped) for the
	 * [ZIB_MAIL] expandable card. direction='out'; the addressee is matched in parties_text ONLY, never
	 * the body (same doctrine as sent_to_recipient); received_at is filtered to a SITE-LOCAL day window
	 * (converted to UTC via local_date_to_utc). Owner-forced, kiosk-denied, capped, recency-first.
	 *
	 * @param string $recipient  addressee name/addr; '' matches anyone (a pure "what did I send today").
	 * @param string $since,$until  YYYY-MM-DD site-local dates; '' = unbounded.
	 * @return array { ok, results:[ { id, subject, received_at, to, composed } ], recipient, since, until }
	 */
	public static function owner_told( int $actor, string $recipient, string $since = '', string $until = '', int $limit = self::MAX_CHAT_HITS, array $aliases = array() ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_told', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'results' => array() );
		}
		global $wpdb;
		$limit     = max( 1, min( self::MAX_CHAT_HITS, $limit ) );
		$recipient = trim( $recipient );
		$since     = self::sane_date( $since );
		$until     = self::sane_date( $until );

		$where = 'owner_user_id = %d AND direction = %s';
		$args  = array( $actor, 'out' );
		list( $rsql, $rargs ) = self::recipient_match( $actor, $recipient, $aliases );
		if ( '' !== $rsql ) { $where .= $rsql; $args = array_merge( $args, $rargs ); }
		if ( '' !== $since ) { $where .= ' AND received_at >= %s'; $args[] = self::local_date_to_utc( $since, false ); }
		if ( '' !== $until ) { $where .= ' AND received_at <= %s'; $args[] = self::local_date_to_utc( $until, true ); }

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, direction, class, received_at, subject, body_enc, body_format
			 FROM ' . self::t_msg() . ' WHERE ' . $where . '
			 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- addressee is parties-only, never the body
			array_merge( $args, array( $limit ) )
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$text     = self::body_to_text( ZIB_Crypto::decrypt( (string) $r->body_enc ), (string) $r->body_format );
			$composed = self::strip_quoted( $text );
			$out[] = array(
				'id'          => (int) $r->id,
				'subject'     => (string) $r->subject,
				'received_at' => $r->received_at ? (string) $r->received_at : '',
				'to'          => self::recipient_label( $actor, (int) $r->id ),
				'composed'    => $composed,
			);
		}
		self::log( $actor, $actor, 'owner_told', 'allow', 'told:' . ( '' !== $since ? $since : '*' ) . '..' . ( '' !== $until ? $until : '*' ), substr( sha1( $recipient ), 0, 16 ), count( $out ) );
		return array( 'ok' => true, 'results' => $out, 'recipient' => $recipient, 'since' => $since, 'until' => $until );
	}

	/**
	 * v0.9.13 Return only what the OWNER newly WROTE — cut the body at the first quoted-reply or
	 * forwarded-message marker so the card cites the owner's words, not the whole thread. Preserves
	 * line breaks (unlike tidy_for_excerpt, which flattens for a one-line snippet). Pure; a fresh
	 * compose (no markers) is kept whole. Capped so a runaway body can't bloat a card.
	 */
	public static function strip_quoted( string $text ): string {
		$cut = strlen( $text );
		$markers = array(
			'/\bOn\b.{0,160}?\bwrote:/s',                    // Gmail / Apple: "On <date>, X wrote:"
			'/^\s*-{2,}\s*Original Message\s*-{2,}/im',       // Outlook
			'/^\s*-{2,}\s*Forwarded message\s*-{2,}/im',      // Gmail forward
			'/\bBegin forwarded message:/i',                   // Apple forward
			'/^\s*From:[^\n]+\n\s*(?:Sent|Date):[^\n]+/im',  // Outlook quoted header block
			'/^\s*>{1,}\s?/m',                                // quoted lines
			'/^\s*_{5,}\s*$/m',                               // Outlook divider rule
		);
		foreach ( $markers as $re ) {
			if ( preg_match( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos = (int) $m[0][1];
				if ( $pos >= 0 && $pos < $cut ) { $cut = $pos; }
			}
		}
		$head = trim( (string) substr( $text, 0, $cut ) );
		if ( mb_strlen( $head ) > 4000 ) {
			$head = rtrim( mb_substr( $head, 0, 4000 ) ) . '…';
		}
		return $head;
	}

	// ── owner_get_message (full read, decrypted body) ───────────────

	/**
	 * Open one of the actor's own messages. Returns a SAFE text body.
	 *
	 * With $visible_only, an HTML email's body_text is only what its reader sees (visible_html()). That is
	 * the text the chat's drafting model reads when the person asks it to answer this one email, and an
	 * instruction hidden from the person's mail program must never reach a model. body_unreadable is then
	 * true when the email was too big or too tangled to tell hidden text from visible text (body_text is
	 * empty; the caller says so and never guesses). 'direction' says whether the message was received ('in')
	 * or sent ('out'); from_name and from_addr are the stored sender columns as they are, so a caller that
	 * addresses a reply never reads an address out of a display string its writer chose.
	 *
	 * @return array { ok:bool, message?:array }
	 */
	public static function owner_get_message( int $actor, int $message_id, bool $visible_only = false ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_search', 'deny', 'open not permitted', '', 0 );
			return array( 'ok' => false );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::t_msg() . ' WHERE id = %d AND owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$message_id, $actor
		) );
		if ( ! $row ) {
			self::log( $actor, $actor, 'owner_search', 'deny', 'open:not-owned', '', 0 );
			return array( 'ok' => false );
		}

		$to = array();
		$cc = array();
		$parties = $wpdb->get_results( $wpdb->prepare(
			'SELECT role, addr, name FROM ' . self::t_party() . ' WHERE message_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$message_id
		) );
		foreach ( (array) $parties as $pt ) {
			$who = self::fmt_addr( (string) $pt->name, (string) $pt->addr );
			if ( 'cc' === $pt->role ) { $cc[] = $who; } elseif ( 'to' === $pt->role ) { $to[] = $who; }
		}

		$body = ZIB_Crypto::decrypt( (string) $row->body_enc );
		// An HTML email also yields a sanitised, images-off body_html for the reading pane (rendered in a
		// sandboxed iframe). A plain-text email has no HTML — body_text carries it.
		$is_html = ( 'html' === strtolower( (string) $row->body_format ) );
		$san     = $is_html ? self::sanitize_email_html( $body ) : array( 'html' => '', 'has_remote_images' => false, 'has_inline_images' => false );
		self::log( $actor, $actor, 'owner_search', 'allow', 'open:' . (int) $message_id, '', 1 );

		// The text: all of it for the reading pane's caller; only what a reader sees when a model will read it.
		$unreadable = false;
		if ( $visible_only && $is_html ) {
			$body_text  = self::body_to_text( self::visible_html( $body ), 'html' );
			$unreadable = (bool) self::$vis_gave_up;
			if ( $unreadable ) {
				$body_text = ''; // never part of an email whose hidden text could not be told apart
			}
		} else {
			$body_text = self::body_to_text( $body, (string) $row->body_format );
		}

		return array(
			'ok'      => true,
			'message' => array(
				'id'                => (int) $row->id,
				'subject'           => (string) $row->subject,
				'from'              => self::fmt_addr( (string) $row->from_name, (string) $row->from_addr ),
				'from_name'         => (string) ( $row->from_name ?? '' ),
				'from_addr'         => (string) ( $row->from_addr ?? '' ),
				'to'                => $to,
				'cc'                => $cc,
				'received_at'       => $row->received_at ? (string) $row->received_at : '',
				'direction'         => (string) ( $row->direction ?? '' ),
				'class'             => (string) $row->class,
				'folder'            => (string) $row->folder,
				'has_attachments'   => (int) $row->has_attachments,
				'body_text'         => $body_text,
				'body_unreadable'   => $unreadable,
				'body_html'         => (string) $san['html'],
				'has_remote_images' => (bool) $san['has_remote_images'],
				'has_inline_images' => (bool) ( $san['has_inline_images'] ?? false ),
			),
		);
	}

	/**
	 * Sanitise an email's HTML for the reading pane: drop dangerous elements/attributes, neutralise URL
	 * schemes, force images OFF (stash <img src> into data-zib-src + blank src, tracking whether any remote
	 * or cid: inline image was found), then run wp_kses with an email-safe allow-list. Rendered in a
	 * sandboxed iframe (the real boundary); has_remote_images lets the UI show "Load images" only when
	 * there is something to load.
	 *
	 * @return array { html:string, has_remote_images:bool, has_inline_images:bool }
	 */
	public static function sanitize_email_html( string $html ): array {
		$has_remote = false;
		$has_inline = false; // cid: (embedded) images are tracked separately from remote ones.

		// 1. Drop dangerous element blocks WITH their content.
		$html = (string) preg_replace( '#<(script|style|head|title|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html );
		// Any orphan opening/closing of those, plus framing/form/plugin elements.
		$html = (string) preg_replace( '#</?(?:script|style|head|title|template|noscript|iframe|frame|frameset|object|embed|applet|form|input|button|select|option|optgroup|textarea|label|fieldset|legend|link|meta|base)\b[^>]*>#is', '', $html );
		// 2. Strip HTML comments (can hide conditional / injected payloads).
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );
		// 3. Strip event-handler attributes (on…=), quoted or bare.
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html );
		$html = (string) preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $html );
		$html = (string) preg_replace( '#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html );
		// 4. Neutralise dangerous URL schemes anywhere in an attribute value.
		$html = (string) preg_replace( '#(href|src|xlink:href|action|formaction)\s*=\s*(["\'])\s*(?:javascript|vbscript|data|file|about)\s*:[^"\']*\2#i', '$1="#"', $html );
		// 5. IMAGES OFF: stash every <img src> into data-zib-src and blank src. A cid: reference is an
		//    EMBEDDED (inline) part of this very message — not a remote fetch — so it flags has_inline, not
		//    has_remote: the reader can resolve and show it without the tracking risk of a remote pull.
		$html = (string) preg_replace_callback( '#<img\b([^>]*)>#is', function ( $m ) use ( &$has_remote, &$has_inline ) {
			$attrs = $m[1];
			if ( preg_match( '#\ssrc\s*=\s*(["\'])(.*?)\1#is', $attrs, $sm ) ) {
				$url = trim( (string) $sm[2] );
				if ( '' !== $url ) {
					if ( 0 === stripos( $url, 'cid:' ) ) { $has_inline = true; } else { $has_remote = true; }
				}
				$attrs = str_replace( $sm[0], ' data-zib-src="' . self::attr_esc( $url ) . '"', $attrs );
			}
			return '<img' . $attrs . '>';
		}, $html );
		// 6. Neutralise url(...) / expression() / behavior in inline styles (background images, IE vectors).
		$html = (string) preg_replace_callback( '#\sstyle\s*=\s*(["\'])(.*?)\1#is', function ( $m ) use ( &$has_remote ) {
			$css = $m[2];
			if ( preg_match( '#url\s*\(#i', $css ) ) { $has_remote = true; }
			$css = (string) preg_replace( '#url\s*\([^)]*\)#i', 'none', $css );
			$css = (string) preg_replace( '#(expression|behaviou?r|-moz-binding)\s*\([^)]*\)#i', '', $css );
			return ' style="' . $css . '"';
		}, $html );
		// 7. Legacy background="url" attributes.
		$html = (string) preg_replace( '#\sbackground\s*=\s*(["\']).*?\1#is', '', $html );

		// 8. Production belt: a proper tokenising allow-list. (In unit tests wp_kses is absent, so the
		//    pre-pass above must already be safe — that is what the tests assert.)
		if ( function_exists( 'wp_kses' ) ) {
			$html = wp_kses( $html, self::email_allowed_html(), array( 'http', 'https', 'mailto', 'tel' ) );
		}

		return array( 'html' => (string) $html, 'has_remote_images' => (bool) $has_remote, 'has_inline_images' => (bool) $has_inline );
	}

	/** esc_attr when WP is present, a safe fallback otherwise (so sanitize_email_html is unit-testable). */
	private static function attr_esc( string $s ): string {
		return function_exists( 'esc_attr' ) ? esc_attr( $s ) : htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/** Email-safe wp_kses allow-list: formatting + tables + neutralised <img> (data-zib-src), no
	 *  script/style/form/framing. style/class/align kept so the mail still reads like the original. */
	private static function email_allowed_html(): array {
		$common = array( 'style' => true, 'class' => true, 'align' => true, 'dir' => true, 'title' => true );
		$cell   = array_merge( $common, array( 'colspan' => true, 'rowspan' => true, 'valign' => true, 'width' => true, 'height' => true, 'bgcolor' => true, 'nowrap' => true ) );
		return array(
			'p' => $common, 'div' => $common, 'span' => $common, 'br' => array(), 'hr' => $common,
			'a' => array_merge( $common, array( 'href' => true, 'target' => true, 'rel' => true, 'name' => true ) ),
			'b' => $common, 'strong' => $common, 'i' => $common, 'em' => $common, 'u' => $common,
			's' => $common, 'strike' => $common, 'sub' => $common, 'sup' => $common, 'small' => $common,
			'big' => $common, 'mark' => $common, 'blockquote' => $common, 'pre' => $common, 'code' => $common,
			'ul' => $common, 'ol' => array_merge( $common, array( 'start' => true, 'type' => true ) ), 'li' => $common,
			'dl' => $common, 'dt' => $common, 'dd' => $common,
			'h1' => $common, 'h2' => $common, 'h3' => $common, 'h4' => $common, 'h5' => $common, 'h6' => $common,
			'table' => array_merge( $common, array( 'width' => true, 'height' => true, 'cellpadding' => true, 'cellspacing' => true, 'border' => true, 'bgcolor' => true ) ),
			'thead' => $common, 'tbody' => $common, 'tfoot' => $common, 'caption' => $common,
			'tr' => array_merge( $common, array( 'valign' => true, 'bgcolor' => true ) ), 'td' => $cell, 'th' => $cell, 'colgroup' => $common, 'col' => array_merge( $common, array( 'span' => true, 'width' => true ) ),
			'img' => array( 'data-zib-src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true, 'align' => true ),
			'font' => array_merge( $common, array( 'color' => true, 'face' => true, 'size' => true ) ),
			'center' => $common, 'figure' => $common, 'figcaption' => $common,
		);
	}

	/**
	 * The owner's Mail folder nav — their tracked, non-empty folders (folder_hash + name + count).
	 * Owner-gated; degrades to an empty list until the all-folder sync populates the folders table.
	 *
	 * @return array { ok:bool, folders:array<{hash,name,well_known,count}> }
	 */
	public static function owner_folders( int $actor ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_folders', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'folders' => array() );
		}
		$rows = ZIB_Ingest::owner_folder_list( $actor );
		$out  = array();
		foreach ( (array) $rows as $f ) {
			$name  = trim( (string) ( $f['display_name'] ?? '' ) );
			$out[] = array(
				'hash'       => (string) ( $f['folder_hash'] ?? '' ),
				'name'       => ( '' !== $name ) ? $name : 'Folder',
				'well_known' => (string) ( $f['well_known'] ?? '' ),
				'count'      => (int) ( $f['indexed_count'] ?? 0 ),
			);
		}
		self::log( $actor, $actor, 'owner_folders', 'allow', '', '', count( $out ) );
		return array( 'ok' => true, 'folders' => $out );
	}

	// ── Attachments (read) — list / bytes / inline cid: images (owner-gated live Graph read) ──

	/** Max attachment bytes served inline. Phone photos fit; a bigger file is NAMED, not shown. */
	const MAX_ATTACH_BYTES = 12582912; // 12 MiB (decoded)

	/** v0.20.0: cap how many embedded (cid:) images one message can resolve, to bound the Graph fan-out. */
	const MAX_INLINE_COUNT = 20;

	/** Browser-native raster types we will render. image/svg+xml is DELIBERATELY absent (script vector). */
	private static function attach_image_types(): array {
		return array( 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp' );
	}

	// ── v0.20.0: HEIC/HEIF — Apple's photo format. Browsers (Chrome/Firefox) can't decode it, so it is
	// NOT a directly-renderable type; instead, when the host has an Imagick built with libheif, we
	// transcode it to JPEG server-side and serve THAT. On a host without the decoder it degrades to a
	// named, non-rendered attachment (never a broken box). All checks are local (no network).

	/** HEIC/HEIF media types (parameters already stripped by the caller). */
	private static function heic_types(): array {
		return array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' );
	}

	/** True when a (media-type-only) content type is HEIC/HEIF. */
	public static function is_heic_type( string $ctype ): bool {
		return in_array( strtolower( trim( $ctype ) ), self::heic_types(), true );
	}

	/** True when this host can transcode HEIC/HEIF → JPEG (Imagick compiled with libheif). Cached. */
	public static function can_transcode_heic(): bool {
		static $cap = null;
		if ( null !== $cap ) { return $cap; }
		$cap = false;
		if ( class_exists( 'Imagick' ) ) {
			try {
				$fmts = @\Imagick::queryFormats( 'HE*' ); // HEIC, HEIF, HEIC-…
				$cap  = is_array( $fmts ) && ! empty( $fmts );
			} catch ( \Throwable $e ) { $cap = false; }
		}
		return $cap;
	}

	/**
	 * Transcode a HEIC/HEIF byte string to JPEG. Returns raw JPEG bytes, or false if the host can't or
	 * the decode fails. Metadata is stripped (privacy + size); resource limits guard a decompression bomb.
	 *
	 * @param string $raw Decoded (binary) HEIC bytes.
	 * @return string|false
	 */
	public static function heic_to_jpeg( string $raw ) {
		if ( ! self::can_transcode_heic() || '' === $raw ) { return false; }
		try {
			$im = new \Imagick();
			// Cap decode work so a crafted file can't exhaust memory/CPU.
			if ( defined( '\Imagick::RESOURCETYPE_MEMORY' ) ) { $im->setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 ); }
			if ( defined( '\Imagick::RESOURCETYPE_MAP' ) )    { $im->setResourceLimit( \Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 ); }
			$im->readImageBlob( $raw );
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 82 );
			if ( method_exists( $im, 'stripImage' ) ) { $im->stripImage(); }
			$out = (string) $im->getImageBlob();
			$im->clear();
			if ( method_exists( $im, 'destroy' ) ) { $im->destroy(); }
			return ( '' !== $out ) ? $out : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * PURE classifier for ONE Graph attachment object (list item OR full object). No I/O — unit-tested.
	 * Decides the safe display name, whether it is a real file attachment, whether it is a renderable
	 * image, and (when not) why. On a LIST projection the @odata.type discriminator may be dropped; an
	 * unknown type is treated as a file CANDIDATE here and authoritatively re-checked by the bytes GET
	 * (whose full object always carries @odata.type + contentBytes), so a non-file can never be served.
	 *
	 * @param array $att A Graph attachment array.
	 * @return array { att_id, name, content_type, size, is_file, is_inline, is_image, renderable, reason }
	 */
	public static function classify_attachment( array $att ): array {
		$odata     = strtolower( (string) ( $att['@odata.type'] ?? '' ) );
		$is_inline = ! empty( $att['isInline'] );
		if ( '' !== $odata ) {
			$is_file = ( false !== strpos( $odata, 'fileattachment' ) );
		} else {
			// List projection can omit the type discriminator; treat as a file candidate — the bytes
			// GET re-classifies the full object (which always carries @odata.type) before serving.
			$is_file = true;
		}
		$ctype = strtolower( trim( (string) ( $att['contentType'] ?? '' ) ) );
		// contentType may carry parameters ("image/jpeg; name=x"); keep the media type only.
		$semi = strpos( $ctype, ';' );
		if ( false !== $semi ) { $ctype = trim( substr( $ctype, 0, $semi ) ); }
		$size     = max( 0, (int) ( $att['size'] ?? 0 ) );
		$name     = self::safe_filename( (string) ( $att['name'] ?? '' ) );
		$is_image = in_array( $ctype, self::attach_image_types(), true );
		$is_heic  = self::is_heic_type( $ctype ); // browser can't render it directly — transcoded when served

		$renderable = true;
		$reason     = '';
		if ( ! $is_file ) {
			$renderable = false; $reason = 'not-a-file';
		} elseif ( $is_inline ) {
			$renderable = false; $reason = 'inline';            // HTML/ad layer — never rendered
		} elseif ( ! $is_image ) {
			$renderable = false; $reason = 'not-image';
		} elseif ( $size > self::MAX_ATTACH_BYTES ) {
			$renderable = false; $reason = 'too-large';
		}

		return array(
			'att_id'       => (string) ( $att['id'] ?? '' ),
			'name'         => $name,
			'content_type' => $ctype,
			'size'         => $size,
			'is_file'      => $is_file,
			'is_inline'    => $is_inline,
			'is_image'     => $is_image,
			'is_heic'      => $is_heic,
			'renderable'   => $renderable,
			'reason'       => $reason,
		);
	}

	/** Neutralise a filename for display: drop path segments + control chars, cap length. Never HTML. */
	public static function safe_filename( string $name ): string {
		$name = str_replace( array( "\\", '/' ), array( ' ', ' ' ), $name ); // no path segments
		$name = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $name );    // control chars
		$name = preg_replace( '/\s{2,}/', ' ', (string) $name );
		$name = trim( (string) $name );
		if ( '' === $name ) { $name = 'attachment'; }
		if ( mb_strlen( $name ) > 120 ) { $name = mb_substr( $name, 0, 117 ) . '…'; }
		return $name;
	}


	/** Map a Graph WP_Error to a short, non-leaky reason for the client. */
	private static function graph_reason( $err ): string {
		$code = is_wp_error( $err ) ? (string) $err->get_error_code() : '';
		if ( 'zib_graph_transient' === $code ) { return 'busy'; }
		if ( 'zib_graph_401' === $code )       { return 'auth'; }
		if ( 'zib_graph_404' === $code )       { return 'gone'; }
		return 'error';
	}

	/**
	 * List the RENDERABLE (image) + NAMED (other real file) attachments of one of the actor's own
	 * messages, via a live Graph read. Owner-gated exactly like owner_get_message. Inline images and
	 * non-file parts are omitted entirely. Never returns bytes — only what to offer.
	 *
	 * @return array { ok:bool, images:array[], others:array[], reason?:string }
	 */
	public static function owner_list_attachments( int $actor, int $message_id ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'list not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'list:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$list = ZIB_Graph::list_attachments( (int) $ref->account_id, (string) $ref->ms_message_id, ZIB_Ingest::allfolder_enabled() );
		if ( is_wp_error( $list ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'list:graph:' . $list->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => 'graph', 'detail' => self::graph_reason( $list ) );
		}
		$images = array();
		$others = array();
		$heic_ok = self::can_transcode_heic();
		foreach ( (array) $list as $att ) {
			$c = self::classify_attachment( (array) $att );
			// v0.20.0: a non-inline HEIC/HEIF photo is renderable when the host can transcode it to JPEG.
			$heic_renderable = ( $c['is_heic'] && $c['is_file'] && ! $c['is_inline'] && $heic_ok && $c['size'] <= self::MAX_ATTACH_BYTES );
			if ( $c['renderable'] || $heic_renderable ) {
				$images[] = array(
					'att_id'       => $c['att_id'],
					'name'         => $c['name'],
					// HEIC is served transcoded, so the browser receives image/jpeg.
					'content_type' => $heic_renderable ? 'image/jpeg' : $c['content_type'],
					'size'         => $c['size'],
				);
			} elseif ( $c['is_file'] && ! $c['is_inline'] ) {
				// A real, non-inline attachment we won't render (non-image, image too large, or HEIC on a
				// host without a decoder): name it, with a clear reason.
				$reason = ( $c['is_heic'] && ! $heic_ok ) ? 'heic-no-decoder' : $c['reason'];
				$others[] = array(
					'name'         => $c['name'],
					'content_type' => $c['content_type'],
					'size'         => $c['size'],
					'reason'       => $reason,
				);
			}
			// inline images / non-file parts: silently omitted here (handled by owner_list_inline_images).
		}
		self::log( $actor, $actor, 'owner_attach', 'allow', 'list:' . (int) $message_id, '', count( $images ) );
		return array( 'ok' => true, 'images' => $images, 'others' => $others );
	}

	/**
	 * Fetch ONE image attachment's bytes (base64) for inline rendering. Re-classifies the FULL object
	 * server-side and refuses anything that is not a non-inline, allow-listed, in-cap image — plus a
	 * belt check that contentBytes is actually present (an itemAttachment carries none). The att_id is
	 * addressed only within the owner-scoped message, so it cannot reach another mailbox.
	 *
	 * @return array { ok:bool, name?, content_type?, data_b64?, reason? }
	 */
	public static function owner_get_attachment( int $actor, int $message_id, string $att_id ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'get not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$att_id = trim( $att_id );
		if ( '' === $att_id ) {
			return array( 'ok' => false, 'reason' => 'bad-id' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'get:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$att = ZIB_Graph::get_attachment( (int) $ref->account_id, (string) $ref->ms_message_id, $att_id, ZIB_Ingest::allfolder_enabled() );
		if ( is_wp_error( $att ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'get:graph:' . $att->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => 'graph', 'detail' => self::graph_reason( $att ) );
		}
		$c = self::classify_attachment( (array) $att );
		// v0.20.0: a non-inline HEIC/HEIF file passes the gate only when this host can transcode it.
		$heic_ok = ( $c['is_heic'] && $c['is_file'] && ! $c['is_inline'] && self::can_transcode_heic() && $c['size'] <= self::MAX_ATTACH_BYTES );
		if ( ! $c['renderable'] && ! $heic_ok ) {
			$reason = ( $c['is_heic'] && ! self::can_transcode_heic() ) ? 'heic-no-decoder' : ( '' !== $c['reason'] ? $c['reason'] : 'blocked' );
			self::log( $actor, $actor, 'owner_attach', 'deny', 'get:blocked:' . $reason, '', 0 );
			return array( 'ok' => false, 'reason' => $reason );
		}
		$bytes = (string) ( $att['contentBytes'] ?? '' );
		if ( '' === $bytes ) {
			return array( 'ok' => false, 'reason' => 'no-bytes' );
		}
		// Belt: cap the ENCODED length too (base64 ≈ 4/3 of decoded), so a mislabelled size can't slip a
		// huge payload through the byte gate.
		if ( strlen( $bytes ) > (int) ( self::MAX_ATTACH_BYTES * 1.4 ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'get:blocked:too-large', '', 0 );
			return array( 'ok' => false, 'reason' => 'too-large' );
		}
		$content_type = $c['content_type'];
		if ( $heic_ok ) {
			// Decode → transcode HEIC to JPEG → re-encode. On any failure, refuse (never a broken image).
			$raw  = base64_decode( $bytes, true );
			$jpeg = ( false !== $raw && '' !== $raw ) ? self::heic_to_jpeg( $raw ) : false;
			if ( false === $jpeg || strlen( $jpeg ) > self::MAX_ATTACH_BYTES ) {
				self::log( $actor, $actor, 'owner_attach', 'deny', 'get:blocked:heic-decode', '', 0 );
				return array( 'ok' => false, 'reason' => 'heic-decode-failed' );
			}
			$bytes        = base64_encode( $jpeg );
			$content_type = 'image/jpeg';
		}
		self::log( $actor, $actor, 'owner_attach', 'allow', 'get:' . (int) $message_id, '', 1 );
		return array(
			'ok'           => true,
			'name'         => $c['name'],
			'content_type' => $content_type,
			'data_b64'     => $bytes, // Graph returns contentBytes already base64-encoded (JPEG re-encoded for HEIC)
		);
	}

	/**
	 * v0.20.0 — resolve a message's EMBEDDED (inline, cid:) images to bytes for the reading pane.
	 *
	 * iPhone/Outlook/etc. embed a photo as an inline attachment referenced by the HTML as
	 * <img src="cid:CONTENT-ID">. A cid: URL can't resolve inside the sandboxed reader iframe, so those
	 * images render as a broken box. This returns each inline image's bytes keyed by its Content-ID, so
	 * the reader can swap cid:… for a data: URI. Unlike remote images (a tracking pull), inline parts are
	 * already inside the message — no network fetch on display — so showing them carries no tracking risk.
	 *
	 * Owner-gated exactly like owner_get_message / owner_list_attachments. Each part is re-classified from
	 * its FULL Graph object before any bytes are returned; a part is resolved when it is a raster image (or
	 * HEIC transcoded to JPEG on a capable host) that carries a Content-ID — the marker that the HTML can
	 * reference it as cid:… — each under the size cap, up to MAX_INLINE_COUNT. Keyed on Content-ID rather
	 * than the isInline flag, which some clients (iPhone Mail) leave unset on a cid-referenced photo.
	 *
	 * @return array { ok:bool, images:array<{cid,content_type,data_b64,name}>, heic_capable:bool, reason?:string }
	 */
	public static function owner_list_inline_images( int $actor, int $message_id ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'inline not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'inline:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$immutable = ZIB_Ingest::allfolder_enabled();
		$list      = ZIB_Graph::list_attachments( (int) $ref->account_id, (string) $ref->ms_message_id, $immutable );
		if ( is_wp_error( $list ) ) {
			self::log( $actor, $actor, 'owner_attach', 'deny', 'inline:graph:' . $list->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => 'graph', 'detail' => self::graph_reason( $list ) );
		}
		$heic_ok = self::can_transcode_heic();
		$images  = array();
		$total   = 0;
		// Resolve image parts that carry a Content-ID — the marker of a cid:-referenceable part — INLINE
		// parts first (the usual embed), then any remaining image parts, because some clients (e.g. iPhone
		// Mail) reference a photo by cid: WITHOUT setting isInline. contentId lives only on the FULL object,
		// so candidates are fetched in full and gated on a non-empty Content-ID. Bounded by count + a running
		// size total; the client only calls this when the body actually carries a cid: image.
		foreach ( array( true, false ) as $want_inline ) {
			foreach ( (array) $list as $att ) {
				if ( count( $images ) >= self::MAX_INLINE_COUNT ) { break 2; }
				$c = self::classify_attachment( (array) $att );
				if ( (bool) $c['is_inline'] !== $want_inline ) { continue; } // handle this pass's disposition only
				if ( ! ( $c['is_image'] || ( $c['is_heic'] && $heic_ok ) ) ) { continue; }
				if ( $c['size'] > self::MAX_ATTACH_BYTES ) { continue; }

				$full = ZIB_Graph::get_attachment( (int) $ref->account_id, (string) $ref->ms_message_id, $c['att_id'], $immutable );
				if ( is_wp_error( $full ) ) { continue; }
				$fc = self::classify_attachment( (array) $full ); // authoritative re-check of the FULL object
				if ( ! $fc['is_file'] ) { continue; }
				$is_heic = $fc['is_heic'];
				if ( ! ( $fc['is_image'] || ( $is_heic && $heic_ok ) ) ) { continue; }

				// Only a part with a Content-ID can be referenced as cid: in the HTML; that is what we resolve.
				$cid = trim( trim( (string) ( $full['contentId'] ?? '' ) ), '<>' );
				if ( '' === $cid ) { continue; }

				$bytes = (string) ( $full['contentBytes'] ?? '' );
				if ( '' === $bytes || strlen( $bytes ) > (int) ( self::MAX_ATTACH_BYTES * 1.4 ) ) { continue; }
				$ctype = $fc['content_type'];
				if ( $is_heic ) {
					$raw  = base64_decode( $bytes, true );
					$jpeg = ( false !== $raw && '' !== $raw ) ? self::heic_to_jpeg( $raw ) : false;
					if ( false === $jpeg || strlen( $jpeg ) > self::MAX_ATTACH_BYTES ) { continue; }
					$bytes = base64_encode( $jpeg );
					$ctype = 'image/jpeg';
				}
				$total += strlen( $bytes );
				if ( $total > (int) ( self::MAX_ATTACH_BYTES * 1.6 ) ) { break 2; } // running cap across all imgs

				$images[] = array(
					'cid'          => $cid,
					'content_type' => $ctype,
					'data_b64'     => $bytes,
					'name'         => $fc['name'],
				);
			}
		}
		self::log( $actor, $actor, 'owner_attach', 'allow', 'inline:' . (int) $message_id, '', count( $images ) );
		return array( 'ok' => true, 'images' => $images, 'heic_capable' => $heic_ok );
	}

	// ── Owner SEND (only the owner sends, after seeing the full text) ───
	//
	// The owner-composed WRITE path. Each method re-gates the owner (gate_owner), resolves the target
	// SERVER-SIDE (owner_msg_ref for reply/forward — the ms_message_id is never taken from the client),
	// validates recipients, calls the Graph send, and AUDITS via log('owner_send'). Fired ONLY by the
	// owner's explicit Send of a composed message; no chat/marker/one-click path may reach here (INV-SEND).

	/** Owner-scoped resolve of a message's Graph coordinates. Null if not found / not owned. */
	private static function owner_msg_ref( int $actor, int $message_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT account_id, ms_message_id, has_attachments FROM ' . self::t_msg() . ' WHERE id = %d AND owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$message_id, $actor
		) );
	}

	/** The owner's own mailbox account id — for a NEW message, which has no source message row. */
	private static function owner_account_id( int $actor ): int {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $wpdb->prefix . 'zib_accounts WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$actor
		) );
		return $id ? (int) $id : 0;
	}

	/** Parse a comma/semicolon/newline-separated recipient string into Graph recipient objects; invalid
	 *  addresses are dropped and reported. Accepts "Name <addr>" and bare "addr". Pure; unit-tested. */
	public static function parse_recipients( string $raw ): array {
		$parts   = preg_split( '/[,;\n]+/', (string) $raw );
		$rcpt    = array();
		$invalid = array();
		$seen    = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p ) { continue; }
			$addr = ( preg_match( '/<([^>]+)>/', $p, $m ) ) ? trim( $m[1] ) : $p;
			$addr = strtolower( trim( $addr ) );
			$ok   = function_exists( 'is_email' ) ? (bool) is_email( $addr ) : (bool) filter_var( $addr, FILTER_VALIDATE_EMAIL );
			if ( ! $ok ) { $invalid[] = $p; continue; }
			if ( isset( $seen[ $addr ] ) ) { continue; }
			$seen[ $addr ] = true;
			$rcpt[]        = array( 'emailAddress' => array( 'address' => $addr ) );
		}
		return array( 'recipients' => $rcpt, 'invalid' => $invalid, 'count' => count( $rcpt ) );
	}

	/** Map a send WP_Error to a short, client-safe reason (403 → reconnect: Mail.Send not granted). */
	private static function send_reason( $err ): string {
		$code = is_wp_error( $err ) ? (string) $err->get_error_code() : '';
		if ( 'zib_graph_403' === $code )       { return 'reconnect'; }
		if ( 'zib_graph_401' === $code )       { return 'auth'; }
		if ( 'zib_graph_transient' === $code ) { return 'busy'; }
		return 'error';
	}

	/**
	 * Turn the owner's composed plain text into a minimal, safe HTML body that PRESERVES the spacing they
	 * typed. A blank line between paragraphs has to survive into the delivered mail; sending as contentType
	 * 'Text' does not carry it (mail clients collapse blank lines). So: escape first (no injection), turn
	 * every newline into <br>, wrap in one plain <div>. No links auto-made, no remote resources, no scripts.
	 */
	private static function text_to_html( string $text ): string {
		// one copy of this rule (ZIB_Compose::text_html), so an email with documents and one without
		// shape the owner's words identically. The output is byte-for-byte what this method returned before.
		return ZIB_Compose::text_html( $text );
	}

	/**
	 * may this person send from their own mailbox right now? The REST rule (has the app,
	 * not the kiosk, feature on) plus a connected mailbox. For the chat's preview and handoff, which run outside REST.
	 */
	public static function can_send( int $actor ): bool {
		if ( ! self::gate_owner( $actor ) ) { return false; }
		if ( function_exists( 'zib_user_has_access' ) && ! zib_user_has_access( $actor ) ) { return false; }
		// The grant must include Mail.Send - the same rule as /connection's can_send (without it the chat
		// offered, then Confirm minted links before Graph's 403, and Open in Email lost the email).
		global $wpdb;
		$scopes = $wpdb->get_var( $wpdb->prepare(
			'SELECT scopes FROM ' . $wpdb->prefix . 'zib_accounts WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$actor
		) );
		return null !== $scopes && false !== stripos( (string) $scopes, 'Mail.Send' );
	}

	/**
	 * the ONE send of an email carrying Knowledge Vault documents, behind both Send buttons:
	 * the Email app's composer ($via 'email', REST) and the chat's Confirm card ($via 'chat', the zib_compose_send
	 * filter). Same steps, same body, same Graph calls either way, so the recipient cannot tell which was pressed.
	 *
	 * $req: mode (new | reply | replyall | forward), message_id (reply / forward: one of the owner's OWN indexed
	 * messages, resolved here), to, cc (new), subject (new), body (plain text), docs [{id, how, days}].
	 *
	 * Order: gate → (chat: the Confirm press itself) → the target → recipients → the plan (what each document
	 * becomes, ZIB_Compose::plan) → bytes read, links made (ZIB_Compose::materialize) → draft, body, attachments,
	 * send (ZIB_Graph::send_with_attachments) → audit. Owner-forced, audited. INV-SEND: an explicit Send, or the
	 * owner's Confirm press on a held chat action.
	 *
	 * @return array { ok:bool, reason?:string, attached?:int, linked?:int }
	 */
	public static function owner_send_compose( int $actor, array $req, string $via = 'email' ): array {
		$via  = ( 'chat' === $via ) ? 'chat' : 'email';
		$mode = (string) ( $req['mode'] ?? 'new' );
		$mode = in_array( $mode, array( 'new', 'reply', 'replyall', 'forward' ), true ) ? $mode : 'new';
		if ( ! self::gate_owner( $actor ) || ( function_exists( 'zib_user_has_access' ) && ! zib_user_has_access( $actor ) ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':' . $via . ' not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		if ( 'chat' === $via && ! ZIB_Compose::chat_press() ) {
			// INV-SEND: the chat sends only on a person's Confirm press. Anything else asking is refused - and noted.
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':chat:not-a-confirm-press', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$docs = ZIB_Compose::normalize_docs( $req['docs'] ?? array() );
		// One shape for both buttons: the subject as the REST route cleans it, the words trimmed.
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) ( $req['body'] ?? '' ) ) );
		$req['subject'] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) ( $req['subject'] ?? '' ) ) : trim( (string) ( $req['subject'] ?? '' ) );
		if ( 'chat' === $via && ( 'new' !== $mode || ! $docs ) ) {
			// The chat's exception is exactly one thing: a NEW email carrying Vault documents.
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':chat:not-a-document-email', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$deadline = ZIB_Compose::deadline();

		$ms_id   = '';
		$message = array();
		$label   = '';
		$count   = 1;
		if ( 'new' === $mode ) {
			$acct = self::owner_account_id( $actor );
			if ( $acct <= 0 ) { return array( 'ok' => false, 'reason' => 'not-connected' ); }
			$to = self::parse_recipients( (string) ( $req['to'] ?? '' ) );
			if ( $to['count'] < 1 ) { return array( 'ok' => false, 'reason' => 'no-recipients' ); }
			$cc      = self::parse_recipients( (string) ( $req['cc'] ?? '' ) );
			$subject = trim( (string) ( $req['subject'] ?? '' ) );
			if ( '' === trim( $body ) && '' === $subject && ! $docs ) { return array( 'ok' => false, 'reason' => 'empty' ); }
			$message = array( 'subject' => $subject, 'toRecipients' => $to['recipients'] );
			if ( $cc['count'] > 0 ) { $message['ccRecipients'] = $cc['recipients']; }
			$label = implode( ', ', array_map( function ( $r ) { return $r['emailAddress']['address']; }, $to['recipients'] ) );
			$count = $to['count'];
		} else {
			$ref = self::owner_msg_ref( $actor, (int) ( $req['message_id'] ?? 0 ) );
			if ( ! $ref ) {
				self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':not-owned', '', 0 );
				return array( 'ok' => false, 'reason' => 'not-found' );
			}
			$acct  = (int) $ref->account_id;
			$ms_id = (string) $ref->ms_message_id;
			if ( 'forward' === $mode ) {
				$to = self::parse_recipients( (string) ( $req['to'] ?? '' ) );
				if ( $to['count'] < 1 ) { return array( 'ok' => false, 'reason' => 'no-recipients' ); }
				$message = array( 'toRecipients' => $to['recipients'] );
				$label   = implode( ', ', array_map( function ( $r ) { return $r['emailAddress']['address']; }, $to['recipients'] ) );
				$count   = $to['count'];
			} else {
				if ( '' === trim( $body ) && ! $docs ) { return array( 'ok' => false, 'reason' => 'empty' ); }
				$label = 'a reply by email';
			}
		}

		$plan = ZIB_Compose::plan( $actor, $docs );
		if ( is_wp_error( $plan ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':' . $via . ':' . $plan->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => $plan->get_error_code() );
		}
		$out = ZIB_Compose::materialize( $actor, $plan, $label );
		if ( is_wp_error( $out ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':' . $via . ':' . $out->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => $out->get_error_code() );
		}
		$html = ZIB_Compose::body_html( $body, $out['links'] );
		$res  = ZIB_Graph::send_with_attachments( $acct, $mode, $ms_id, $message, $html, $out['attachments'], ZIB_Ingest::allfolder_enabled(), $deadline );
		$ids  = implode( ',', array_map( function ( $p ) { return $p['id'] . ( 'link' === $p['how'] ? 'L' : 'A' ); }, $plan ) );
		if ( is_wp_error( $res ) ) {
			$data   = method_exists( $res, 'get_error_data' ) ? $res->get_error_data() : null;
			$code   = (string) $res->get_error_code();
			$unsure = is_array( $data ) && 'send' === ( $data['stage'] ?? '' ) && in_array( $code, array( 'zib_net', 'zib_graph_transient' ), true );
			// Before /send, or a clear refusal of it: nothing went, so the links made for it stop now.
			// A timeout or a 5xx ON /send may follow a delivery: the links stay, and the owner is told to look in Sent.
			$stopped = $unsure ? 0 : ZIB_Compose::revoke_links( $actor, $out['links'] );
			self::log( $actor, $actor, 'owner_send', 'deny', 'compose:' . $mode . ':' . $via . ':graph:' . $code . ':docs=' . $ids
				. ( $unsure ? ':unsure' : ':links-stopped=' . $stopped ), '', 0 );
			if ( $unsure ) { return array( 'ok' => false, 'reason' => 'unsure' ); }
			if ( 'zib_too_slow' === $code ) { return array( 'ok' => false, 'reason' => 'too-slow' ); }
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		self::log( $actor, $actor, 'owner_send', 'allow', 'compose:' . $mode . ':' . $via . ':docs=' . $ids
			. ( 'new' === $mode ? ':' . substr( sha1( (string) ( $message['subject'] ?? '' ) ), 0, 12 ) : ':' . (int) ( $req['message_id'] ?? 0 ) ), '', $count );
		return array( 'ok' => true, 'attached' => count( $out['attachments'] ), 'linked' => count( $out['links'] ) );
	}

	/**
	 * Owner replies (or reply-alls) to one of their OWN indexed messages. $comment is the composed reply
	 * body; Graph quotes the original beneath it. Owner-forced, audited. INV-SEND: explicit Send only.
	 *
	 * @return array { ok:bool, reason?:string }
	 */
	public static function owner_send_reply( int $actor, int $message_id, string $comment, bool $all = false ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'reply not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$comment = trim( $comment );
		if ( '' === $comment ) { return array( 'ok' => false, 'reason' => 'empty' ); }
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'reply:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		// immutable=false: without all-folder sync, ingest stores the default ms_message_id. Wire
		// ZIB_Ingest::allfolder_enabled() here once the all-folder sync (immutable ids) is enabled.
		$res = ZIB_Graph::send_reply_html( (int) $ref->account_id, (string) $ref->ms_message_id, self::text_to_html( $comment ), $all, false );
		if ( is_wp_error( $res ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'reply:graph:' . $res->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		self::log( $actor, $actor, 'owner_send', 'allow', ( $all ? 'replyall:' : 'reply:' ) . (int) $message_id, '', 1 );
		return array( 'ok' => true );
	}

	/**
	 * Owner forwards one of their OWN indexed messages to new recipients, with an optional note.
	 * Owner-forced, audited. INV-SEND: explicit Send only.
	 *
	 * @return array { ok:bool, reason?:string }
	 */
	public static function owner_send_forward( int $actor, int $message_id, string $comment, string $to_raw ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'forward not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'forward:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$to = self::parse_recipients( $to_raw );
		if ( $to['count'] < 1 ) { return array( 'ok' => false, 'reason' => 'no-recipients' ); }
		$res = ZIB_Graph::send_forward_html( (int) $ref->account_id, (string) $ref->ms_message_id, self::text_to_html( trim( $comment ) ), $to['recipients'], false );
		if ( is_wp_error( $res ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'forward:graph:' . $res->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		self::log( $actor, $actor, 'owner_send', 'allow', 'forward:' . (int) $message_id, '', $to['count'] );
		return array( 'ok' => true );
	}

	/**
	 * Owner sends a BRAND-NEW message from their own mailbox. Owner-forced, audited. INV-SEND: explicit
	 * Send only. Body is spacing-preserving HTML (text_to_html) so the owner's line breaks survive.
	 *
	 * @return array { ok:bool, reason?:string }
	 */
	public static function owner_send_new( int $actor, string $to_raw, string $cc_raw, string $subject, string $body ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'new not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$acct = self::owner_account_id( $actor );
		if ( $acct <= 0 ) { return array( 'ok' => false, 'reason' => 'not-connected' ); }
		$to = self::parse_recipients( $to_raw );
		if ( $to['count'] < 1 ) { return array( 'ok' => false, 'reason' => 'no-recipients' ); }
		$cc      = self::parse_recipients( $cc_raw );
		$subject = trim( $subject );
		$body    = (string) $body;
		if ( '' === trim( $body ) && '' === $subject ) { return array( 'ok' => false, 'reason' => 'empty' ); }
		$message = array(
			'subject'      => $subject,
			'body'         => array( 'contentType' => 'HTML', 'content' => self::text_to_html( $body ) ),
			'toRecipients' => $to['recipients'],
		);
		if ( $cc['count'] > 0 ) { $message['ccRecipients'] = $cc['recipients']; }
		$res = ZIB_Graph::send_new( $acct, $message );
		if ( is_wp_error( $res ) ) {
			self::log( $actor, $actor, 'owner_send', 'deny', 'new:graph:' . $res->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		self::log( $actor, $actor, 'owner_send', 'allow', 'new:' . substr( sha1( $subject ), 0, 12 ), '', $to['count'] );
		return array( 'ok' => true );
	}

	// ── owner triage — mark-read / move (INV-WRITE: identity forced to the current user) ──

	/**
	 * Owner sets read / unread on one of their OWN indexed messages. Owner-forced, audited. The one
	 * mark-read the UI fires automatically is on OPEN (the owner's own act of opening the message).
	 *
	 * @return array { ok:bool, reason?:string }
	 */
	public static function owner_mark_read( int $actor, int $message_id, bool $is_read ): array {
		global $wpdb;
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'markread not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'markread:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$res = ZIB_Graph::set_read( (int) $ref->account_id, (string) $ref->ms_message_id, $is_read, ZIB_Ingest::allfolder_enabled() );
		if ( is_wp_error( $res ) ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'markread:graph:' . $res->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		// Graph is authoritative, but the index re-read lags ingest. Reflect the flip on the owner's OWN
		// row immediately so a /browse refresh (e.g. after "Mark unread" → Back) agrees.
		$wpdb->update( self::t_msg(), array( 'is_read' => $is_read ? 1 : 0 ), array( 'id' => (int) $message_id, 'owner_user_id' => $actor ), array( '%d' ), array( '%d', '%d' ) );
		self::log( $actor, $actor, 'owner_triage', 'allow', ( $is_read ? 'markread:' : 'markunread:' ) . (int) $message_id, '', 0 );
		return array( 'ok' => true );
	}

	/**
	 * Owner moves one of their OWN indexed messages to another folder — Delete (→ deleteditems),
	 * Archive, Spam (→ junkemail), or a folder they picked. Owner-forced, audited. Reversible: every
	 * destination is a real folder, never a purge.
	 *
	 * @param string $destination 'deleteditems' | 'archive' | 'junkemail' | 'inbox' | a 32-hex owned folder_hash.
	 * @return array { ok:bool, reason?:string }
	 */
	public static function owner_move( int $actor, int $message_id, string $destination ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'move not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'denied' );
		}
		$ref = self::owner_msg_ref( $actor, $message_id );
		if ( ! $ref ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'move:not-owned', '', 0 );
			return array( 'ok' => false, 'reason' => 'not-found' );
		}
		$dest = self::triage_destination( (int) $ref->account_id, $destination );
		if ( '' === $dest ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'move:bad-dest', '', 0 );
			return array( 'ok' => false, 'reason' => 'bad-dest' );
		}
		$res = ZIB_Graph::move_message( (int) $ref->account_id, (string) $ref->ms_message_id, $dest, ZIB_Ingest::allfolder_enabled() );
		if ( is_wp_error( $res ) ) {
			self::log( $actor, $actor, 'owner_triage', 'deny', 'move:graph:' . $res->get_error_code(), '', 0 );
			return array( 'ok' => false, 'reason' => self::send_reason( $res ) );
		}
		self::log( $actor, $actor, 'owner_triage', 'allow', 'move:' . strtolower( trim( $destination ) ) . ':' . (int) $message_id, '', 0 );
		return array( 'ok' => true );
	}

	/**
	 * Resolve a move destination to a Graph folder id. Well-known names pass through; a 32-hex
	 * folder_hash is resolved to its stored ms_folder_id, owner-scoped by account. Anything else → ''.
	 */
	private static function triage_destination( int $account_id, string $dest ): string {
		$k = strtolower( trim( $dest ) );
		$wellknown = array( 'deleteditems' => 'deleteditems', 'archive' => 'archive', 'junkemail' => 'junkemail', 'inbox' => 'inbox' );
		if ( isset( $wellknown[ $k ] ) ) {
			return $wellknown[ $k ];
		}
		if ( preg_match( '/^[a-f0-9]{32}$/', $k ) ) {
			global $wpdb;
			$fid = $wpdb->get_var( $wpdb->prepare(
				'SELECT ms_folder_id FROM ' . $wpdb->prefix . 'zib_folders WHERE account_id = %d AND folder_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$account_id, $k
			) );
			if ( $fid ) {
				return (string) $fid;
			}
		}
		return '';
	}

	// ── admin_search / admin_get_message (P4 — admin-provisioned read) ──

	/**
	 * An authorised admin searches a STAFF member's indexed mail. The admin-side mirror of
	 * owner_search — same summaries-only shape, same alias-aware FULLTEXT (build_boolean_query),
	 * same LIKE fallback — but the owner is the SUBJECT, admitted only through gate_admin_read
	 * (feature on + reader actor + the subject's per-mailbox admin gate ON). Every call is logged
	 * as path 'admin_search' with actor != subject. Direction is normalised to sent/received.
	 *
	 * @return array { ok:bool, results:array[], subject:int }
	 */
	public static function admin_search( int $actor, int $subject, string $query, int $limit = 30, int $offset = 0 ): array {
		if ( ! self::gate_admin_read( $actor, $subject ) ) {
			self::log( $actor, $subject, 'admin_search', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'results' => array(), 'subject' => $subject );
		}
		global $wpdb;
		$limit  = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset = max( 0, $offset );
		$cols   = 'id, ms_conversation_id, folder, direction, class, from_addr, from_name, received_at, subject, snippet, has_attachments';
		$query  = trim( $query );

		if ( '' === $query ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE owner_user_id = %d ORDER BY received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$subject, $limit, $offset
			) );
		} else {
			$bool = self::build_boolean_query( $query );
			if ( '' === $bool ) {
				$rows = array();
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare(
					'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
					 FROM ' . self::t_msg() . '
					 WHERE owner_user_id = %d AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
					 ORDER BY score DESC, received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$bool, $subject, $bool, $limit, $offset
				) );
				if ( '' !== (string) $wpdb->last_error ) {
					$rows = self::like_search_subject( $subject, $query, $limit, $offset, $cols );
				}
			}
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'              => (int) $r->id,
				'conversation'    => (string) $r->ms_conversation_id,
				'folder'          => (string) $r->folder,
				'direction'       => ( 'out' === $r->direction ? 'sent' : 'received' ),
				'class'           => (string) $r->class,
				'from'            => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'subject'         => (string) $r->subject,
				'snippet'         => (string) $r->snippet,
				'received_at'     => $r->received_at ? (string) $r->received_at : '',
				'has_attachments' => (int) $r->has_attachments,
			);
		}
		self::log( $actor, $subject, 'admin_search', 'allow', '', substr( sha1( $query ), 0, 16 ), count( $out ) );
		return array( 'ok' => true, 'results' => $out, 'subject' => $subject );
	}

	/** LIKE fallback for admin_search (subject-scoped), used only if FULLTEXT is unavailable. */
	private static function like_search_subject( int $subject, string $query, int $limit, int $offset, string $cols ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d AND (subject LIKE %s OR snippet LIKE %s OR parties_text LIKE %s)
			 ORDER BY received_at DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$subject, $like, $like, $like, $limit, $offset
		) );
	}

	/**
	 * An authorised admin opens ONE message from a staff mailbox. Loads the row, reads its REAL
	 * owner, and gates on THAT owner via gate_admin_read (so the per-mailbox gate is enforced on
	 * the open exactly as on the search — a leaked/guessed message id can't cross the gate).
	 * Returns a SAFE text body, same shape as owner_get_message. Logged as path 'admin_search'.
	 *
	 * @return array { ok:bool, message?:array }
	 */
	public static function admin_get_message( int $actor, int $message_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::t_msg() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$message_id
		) );
		if ( ! $row ) {
			self::log( $actor, 0, 'admin_search', 'deny', 'open:not-found', '', 0 );
			return array( 'ok' => false );
		}
		$subject = (int) $row->owner_user_id;
		if ( ! self::gate_admin_read( $actor, $subject ) ) {
			self::log( $actor, $subject, 'admin_search', 'deny', 'open not permitted', '', 0 );
			return array( 'ok' => false );
		}

		$to = array();
		$cc = array();
		$parties = $wpdb->get_results( $wpdb->prepare(
			'SELECT role, addr, name FROM ' . self::t_party() . ' WHERE message_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$message_id
		) );
		foreach ( (array) $parties as $pt ) {
			$who = self::fmt_addr( (string) $pt->name, (string) $pt->addr );
			if ( 'cc' === $pt->role ) { $cc[] = $who; } elseif ( 'to' === $pt->role ) { $to[] = $who; }
		}

		$body = ZIB_Crypto::decrypt( (string) $row->body_enc );
		self::log( $actor, $subject, 'admin_search', 'allow', 'open:' . (int) $message_id, '', 1 );

		return array(
			'ok'      => true,
			'message' => array(
				'id'              => (int) $row->id,
				'owner_user_id'   => $subject,
				'subject'         => (string) $row->subject,
				'from'            => self::fmt_addr( (string) $row->from_name, (string) $row->from_addr ),
				'to'              => $to,
				'cc'              => $cc,
				'received_at'     => $row->received_at ? (string) $row->received_at : '',
				'class'           => (string) $row->class,
				'folder'          => (string) $row->folder,
				'direction'       => ( 'out' === $row->direction ? 'sent' : 'received' ),
				'has_attachments' => (int) $row->has_attachments,
				'body_text'       => self::body_to_text( $body, (string) $row->body_format ),
			),
		);
	}

	/**
	 * Flip the per-mailbox admin-read gate for a subject mailbox. THE control the
	 * "admin controls the gate" model turns on: manage_options ONLY (stricter than reading,
	 * which reader-roles may also do). Writes admin_search_enabled on the subject's account row
	 * and logs 'gate:on'/'gate:off'. No mail is read here.
	 *
	 * @return array { ok:bool }
	 */
	public static function admin_set_read( int $actor, int $subject, bool $on ): array {
		if ( $actor <= 0 || ! user_can( $actor, 'manage_options' ) || $subject <= 0 ) {
			self::log( $actor, $subject, 'admin_search', 'deny', 'gate:not permitted', '', 0 );
			return array( 'ok' => false );
		}
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . $wpdb->prefix . 'zib_accounts SET admin_search_enabled = %d, updated_at = %s WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$on ? 1 : 0, gmdate( 'Y-m-d H:i:s' ), $subject
		) );
		self::log( $actor, $subject, 'admin_search', 'allow', $on ? 'gate:on' : 'gate:off', '', (int) $n );
		return array( 'ok' => ( false !== $n ) );
	}

	// ── cross-user resolution (the assistant: "search a colleague's mail") ──

	/**
	 * Resolve a NAME the reader typed in chat ("Jordan", "Jordan's") to a SINGLE consenting
	 * colleague's user id — the server-side bridge between a natural-language ask and the
	 * gated admin_search path. Fenced exactly like gate_admin_read, and never trusts a
	 * caller-supplied id: the name is matched server-side against the owners of CONNECTED
	 * mailboxes, and only a mailbox whose per-mailbox admin gate is ON (admin_search_enabled=1)
	 * is resolvable. The caller's own mailbox is never reachable through this path (that is the
	 * owner_chat path). Reads no mail.
	 *
	 * @return array{uid:int,status:string,label:string,candidates:array<int,string>}
	 *   status ∈ 'ok'        — uid is a single shared colleague matching the name
	 *          | 'forbidden' — actor is a kiosk or not a reader at all
	 *          | 'none'      — no connected colleague matches that name
	 *          | 'not_shared'— a colleague matches, but their mailbox gate is off
	 *          | 'ambiguous' — more than one SHARED colleague matches (candidates listed)
	 */
	public static function resolve_reader_subject( int $actor, string $name ): array {
		$none = array( 'uid' => 0, 'status' => 'none', 'label' => '', 'candidates' => array() );
		$name = trim( $name );
		if ( $actor <= 0 || '' === $name || ! ZIB_Settings::feature_enabled() ) {
			return $none;
		}
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $actor ) ) {
			return array( 'uid' => 0, 'status' => 'forbidden', 'label' => '', 'candidates' => array() ); // kiosk is a device, not a reader
		}
		if ( ! self::actor_is_reader( $actor ) ) {
			return array( 'uid' => 0, 'status' => 'forbidden', 'label' => '', 'candidates' => array() );
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT owner_user_id, admin_search_enabled FROM ' . $wpdb->prefix . 'zib_accounts WHERE status <> %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'disabled'
		) );
		// Drop a possessive ("Jordan's" → "Jordan") before the house name-normalizer (which turns
		// an apostrophe into a space, leaving a stray "s"), so "Jordan's" still resolves to Jordan.
		$needle       = self::norm_name( preg_replace( "/['\x{2019}]s\\b/u", '', $name ) );
		$shared       = array(); // uid => display label (name match + gate ON)
		$unshared_hit = false;   // a name match whose gate is OFF (→ 'not_shared', an honest, useful answer)
		foreach ( $rows as $r ) {
			$uid = (int) $r->owner_user_id;
			if ( $uid <= 0 || $uid === $actor ) {
				continue; // the caller's own mailbox is the owner_chat path, never this one
			}
			$label = self::user_label( $uid );
			if ( '' === $label || ! self::name_matches( $needle, $label, $uid ) ) {
				continue;
			}
			if ( 1 === (int) $r->admin_search_enabled ) {
				$shared[ $uid ] = $label;
			} else {
				$unshared_hit = true;
			}
		}
		if ( 1 === count( $shared ) ) {
			$uid = (int) array_key_first( $shared );
			return array( 'uid' => $uid, 'status' => 'ok', 'label' => $shared[ $uid ], 'candidates' => array() );
		}
		if ( count( $shared ) > 1 ) {
			return array( 'uid' => 0, 'status' => 'ambiguous', 'label' => '', 'candidates' => array_values( $shared ) );
		}
		if ( $unshared_hit ) {
			return array( 'uid' => 0, 'status' => 'not_shared', 'label' => $name, 'candidates' => array() );
		}
		return $none;
	}

	/** Display label for a user id (display_name), or '' if unknown. */
	private static function user_label( int $uid ): string {
		$u = get_userdata( $uid );
		return $u ? trim( (string) $u->display_name ) : '';
	}

	/**
	 * Does a normalized needle match this user? True on the full display name, any single name
	 * token (so "Jordan" matches "Jordan Jones"), the WP first/last-name meta, or "first last".
	 */
	private static function name_matches( string $needle, string $label, int $uid ): bool {
		$ln = self::norm_name( $label );
		if ( '' === $needle || '' === $ln ) {
			return false;
		}
		if ( $needle === $ln ) {
			return true;
		}
		$ltoks = preg_split( '/\s+/', $ln );
		if ( is_array( $ltoks ) && in_array( $needle, $ltoks, true ) ) {
			return true;
		}
		$first = self::norm_name( (string) get_user_meta( $uid, 'first_name', true ) );
		$last  = self::norm_name( (string) get_user_meta( $uid, 'last_name', true ) );
		if ( '' !== $first && $needle === $first ) {
			return true;
		}
		if ( '' !== $last && $needle === $last ) {
			return true;
		}
		if ( '' !== $first && '' !== $last && $needle === ( $first . ' ' . $last ) ) {
			return true;
		}
		return false;
	}

	// ── owner_chat (the assistant's own-mail path) ──────────────────

	/**
	 * The caller's OWN sent emails that read most like the one they are about to write — voice
	 * examples for a chat draft, so a drafted email sounds like its sender. Owner path only (gate_owner: the caller's own mailbox, never the kiosk, never
	 * anyone else's — owner_user_id = actor is in the WHERE). Sent ('out') mail to people outside the
	 * company ('external'), of a normal email's length, ranked by relevance to $topic (OR-mode FULLTEXT
	 * over the same columns as owner_chat); topped up with the most recent such emails when fewer match.
	 * Read-only; logged with a hash of the topic, never the topic.
	 *
	 * With $to (a reply to one email), only mail the caller sent to that one address: what rides along with an
	 * email from outside is only what its sender has already had from the caller. '' (no usable address) returns
	 * no rows, never mail sent to anyone else.
	 *
	 * @return array { ok:bool, related:bool, rows:array[] } rows: { id, subject, body, received_at }
	 */
	public static function sent_style_examples( int $actor, string $topic, int $limit = 3, ?string $to = null ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'style_examples', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'related' => false, 'rows' => array() );
		}
		if ( null !== $to && '' === trim( $to ) ) {
			self::log( $actor, $actor, 'style_examples', 'allow', 'no recipient', '', 0 );
			return array( 'ok' => true, 'related' => false, 'rows' => array() );
		}
		global $wpdb;
		$limit = max( 1, min( 5, $limit ) );
		$topic = trim( substr( $topic, 0, 300 ) );
		$cols  = 'id, subject, body_clean_text, received_at';
		$where = "owner_user_id = %d AND direction = 'out' AND class = 'external'
			AND body_clean_text IS NOT NULL AND CHAR_LENGTH(body_clean_text) BETWEEN 60 AND 1500";
		$wargs = array( $actor );
		if ( null !== $to ) {
			$where  .= ' AND id IN ( SELECT message_id FROM ' . self::t_party() . " WHERE owner_user_id = %d AND role = 'to' AND LOWER(addr) = %s )";
			$wargs[] = $actor;
			$wargs[] = strtolower( trim( $to ) );
		}
		$keep  = function ( $rows, array $seen, int $want ) {
			$out = array();
			foreach ( (array) $rows as $r ) {
				$id   = (int) ( $r->id ?? 0 );
				$body = trim( (string) ( $r->body_clean_text ?? '' ) );
				// A forward or a pasted thread is not the person's own voice.
				if ( $id <= 0 || isset( $seen[ $id ] ) || '' === $body
					|| preg_match( '/^(?:-{2,}\s*)?(?:forwarded message|original message|begin forwarded)/im', $body )
					|| preg_match( '/^\s*(?:fwd?|fw)\s*:/i', (string) ( $r->subject ?? '' ) ) ) {
					continue;
				}
				$out[ $id ] = array(
					'id'          => $id,
					'subject'     => (string) ( $r->subject ?? '' ),
					'body'        => $body,
					'received_at' => (string) ( $r->received_at ?? '' ),
				);
				if ( count( $out ) >= $want ) {
					break;
				}
			}
			return $out;
		};

		$rows    = array();
		$related = false;
		$bool    = '' !== $topic ? self::build_boolean_query( $topic, false ) : '';
		if ( '' !== $bool ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns, table and WHERE are fixed strings set above; every value is a placeholder
			$hit = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
				 FROM ' . self::t_msg() . ' WHERE ' . $where . ' AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
				 ORDER BY score DESC, received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $bool ), $wargs, array( $bool, 4 * $limit ) )
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			if ( '' === (string) $wpdb->last_error ) {
				$rows    = $keep( $hit, array(), $limit );
				$related = ! empty( $rows );
			}
		}
		if ( count( $rows ) < $limit ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns, table and WHERE are fixed strings set above; every value is a placeholder
			$recent = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE ' . $where . ' AND CHAR_LENGTH(body_clean_text) <= 900
				 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $wargs, array( 6 * $limit ) )
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			$rows = $rows + $keep( $recent, $rows, $limit - count( $rows ) );
		}
		$rows = array_values( $rows );
		self::log( $actor, $actor, 'style_examples', 'allow', $related ? 'related' : 'recent', '' !== $topic ? substr( sha1( $topic ), 0, 16 ) : '', count( $rows ) );
		return array( 'ok' => true, 'related' => $related, 'rows' => $rows );
	}

	/**
	 * The owner asks the assistant about their OWN indexed mail. Egress path #1.
	 *
	 * This is the ONLY method that hands mail *content* to the LLM, and it is
	 * fenced on every axis:
	 *   - SUBJECT FORCED == ACTOR. owner_user_id = %d = the real caller. There is
	 *     no parameter by which another user's mailbox could be named — a bot (or a
	 *     crafted marker) literally cannot express "search someone else's mail".
	 *   - KIOSK DENIED. The shared device never reaches this path.
	 *   - BOUNDED. At most MAX_CHAT_HITS messages, each excerpt capped to
	 *     CHAT_EXCERPT_CH chars — a small, deliberate slice, never a mailbox dump.
	 *   - SAFE TEXT. Bodies are decrypted then reduced to plain text (no email HTML,
	 *     script, or style survives) before the model ever sees them.
	 *   - AUDITED. Allow or deny, the call is logged; the query is hashed, not kept.
	 *
	 * Returns STRUCTURED data. The spotlighting fence that wraps it for the model,
	 * and the neutralising of any fence delimiters an attacker mailed in, live in
	 * ZIB_ZANA_Bridge — the one engine-facing seam — so this reader stays pure DB.
	 *
	 * @return array { ok:bool, reason?:string, results:array[] }
	 */
	public static function owner_chat( int $actor, string $query, int $limit = self::MAX_CHAT_HITS, string $mode = 'list', string $direction = 'any', string $topic = '', string $match = 'recipient' ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_chat', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'not_permitted', 'results' => array() );
		}
		global $wpdb;
		$limit = max( 1, min( self::MAX_CHAT_HITS, $limit ) );
		$query = trim( $query );
		$mode  = ( 'latest' === $mode ) ? 'latest' : 'list';
		$cols  = 'id, folder, direction, class, from_addr, from_name, received_at, subject, snippet, has_attachments, body_enc, body_format';

		// A generic RECIPIENT / ROLE noun ("a customer", "someone", "sent to") is NOT a
		// person to resolve: it would match every sender carrying the word (Home Depot
		// "customer care", Twilio "customer experience"…) or every body that says it.
		// Blank it so the ask becomes a pure recency (+direction) query, not a bogus
		// "which did you mean?" (the Session-1086 lesson).
		if ( self::is_generic_recipient( $query ) ) {
			$query = '';
		}

		// Direction predicate. Stored 'out' = sent, 'in' = received; 'any' = BOTH, the
		// safe default (a search that hides the customer's reply is worse than one that
		// shows an extra row). Identity is anchored to the caller regardless: owner == actor.
		$dir_val  = ( 'sent' === $direction ) ? 'out' : ( ( 'received' === $direction ) ? 'in' : '' );
		$dir_sql  = ( '' !== $dir_val ) ? ' AND direction = %s' : '';
		$dir_args = ( '' !== $dir_val ) ? array( $dir_val ) : array();

		// ── SINGULAR RECALL by a NAMED sender ("what was the LAST thing from X?") ──
		// A filter-and-sort, not a relevance rank: resolve the person to THEIR OWN
		// sender address(es), newest first. Two distinct people → hand back candidates,
		// never pick (Session-1083). ONLY for received/any: on SENT mail the named person
		// is the RECIPIENT, not from_addr, so we fall through to FULLTEXT (which also
		// matches parties_text) rather than resolve the wrong column.
		$sender = array( 'addresses' => array(), 'label' => '', 'ambiguous' => false, 'candidates' => array() );
		if ( 'latest' === $mode && '' !== $query && 'sent' !== $direction ) {
			$sender = self::resolve_sender( $actor, $query );
			if ( ! empty( $sender['ambiguous'] ) ) {
				self::log( $actor, $actor, 'owner_chat', 'allow', 'latest:ambiguous', substr( sha1( $query ), 0, 16 ), 0 );
				return array( 'ok' => true, 'results' => array(), 'mode' => 'latest', 'ambiguous' => true, 'candidates' => $sender['candidates'] );
			}
		}

		$recipient_scoped = false;
		$topic_terms      = ( 'sent' === $direction && '' !== $query ) ? self::topic_terms( $topic ) : array();
		$topic_state      = ''; // '' (no topic) | 'hit' (rows match the topic) | 'missed' (none do; rows are all mail to that person)
		$content_search   = false; // match=any and nothing was addressed to the term -> searched the text of sent mail instead
		if ( ! empty( $sender['addresses'] ) ) {
			// Sender-filtered recency: the newest message from that person is the answer.
			$addrs = array_values( $sender['addresses'] );
			$ph    = implode( ',', array_fill( 0, count( $addrs ), '%s' ) );
			$args  = array_merge( array( $actor ), $dir_args, $addrs, array( $limit ) );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
				 WHERE owner_user_id = %d' . $dir_sql . ' AND LOWER(from_addr) IN (' . $ph . ')
				 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$args
			) );
		} elseif ( 'sent' === $direction && '' !== $query ) {
			// SENT to a NAMED person: the person is the RECIPIENT (in parties_text), not a body term.
			// Match the addressee specifically so "sent to Alex" cannot return a message that only
			// MENTIONS Alex while sent to someone else. Empty stays empty — we do NOT broaden
			// to the body-matching FULLTEXT / fuzzy / LIKE tiers below.
			$recipient_scoped = true;
			if ( ! empty( $topic_terms ) ) {
				// "…to Jordan about Sam's invoice" — the person AND the topic. When no email to
				// them mentions it, say so and show what WAS sent to them (the render labels it honestly).
				$rows        = self::sent_to_recipient( $actor, $query, $limit, $cols, array(), $topic_terms );
				$topic_state = empty( $rows ) ? 'missed' : 'hit';
				if ( empty( $rows ) ) {
					$rows = self::sent_to_recipient( $actor, $query, $limit, $cols );
				}
			} else {
				$rows = self::sent_to_recipient( $actor, $query, $limit, $cols );
			}
			if ( empty( $rows ) && 'any' === $match ) {
				// "search my sent emails for <X>" — X may be a topic, not a person. Nothing was
				// addressed to it, so search the TEXT of sent mail (direction still 'out'); the fuzzy and
				// substring tiers below then run as for any content search. Labelled in the render.
				$recipient_scoped = false;
				$content_search   = true;
				$bool             = self::build_boolean_query( $query );
				if ( '' !== $bool ) {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns are this method's fixed list, table from self::t_msg(); every value is a placeholder
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
						 FROM ' . self::t_msg() . '
						 WHERE owner_user_id = %d AND direction = %s AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
						 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sent-mail text search
						$bool, $actor, 'out', $bool, $limit
					) );
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
					if ( '' !== (string) $wpdb->last_error ) {
						$rows = array();
					}
				}
			}
		} elseif ( '' === $query ) {
			// A contentless ask ("what's in my inbox?" / "the last email I sent") →
			// most-recent in the requested direction, still capped.
			$args = array_merge( array( $actor ), $dir_args, array( $limit ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE owner_user_id = %d' . $dir_sql . ' ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$args
			) );
		} else {
			$bool = self::build_boolean_query( $query );
			if ( '' === $bool ) {
				$rows = array();
			} else {
				$args = array_merge( array( $bool, $actor ), $dir_args, array( $bool, $limit ) );
				$rows = $wpdb->get_results( $wpdb->prepare(
					'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
					 FROM ' . self::t_msg() . '
					 WHERE owner_user_id = %d' . $dir_sql . ' AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
					 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- owner_chat is recency-first: "last/latest from X" must surface the newest, not the highest-scored.
					$args
				) );
				if ( '' !== (string) $wpdb->last_error ) {
					$rows = self::like_search( $actor, $query, $limit, 0, $cols );
				}
			}
		}

		// FUZZY FALLBACK — the exact (AND) search found nothing, so retry once in
		// OR-mode (any term), best-match-first. Catches a misspelled or partial name:
		// "Riley Andersen" when the record is "Riley Anderson" still matches on the
		// correct token. Same owner-scope, direction filter, and cap as the exact
		// search — only the strictness relaxes. Skipped when the query was blanked.
		$fuzzy_used = false;
		if ( empty( $rows ) && '' !== $query && ! $recipient_scoped ) {
			$fuzzy = self::build_boolean_query( $query, false );
			if ( '' !== $fuzzy ) {
				$args   = array_merge( array( $fuzzy, $actor ), $dir_args, array( $fuzzy, $limit ) );
				$frows  = $wpdb->get_results( $wpdb->prepare(
					'SELECT ' . $cols . ', MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE) AS score
					 FROM ' . self::t_msg() . '
					 WHERE owner_user_id = %d' . $dir_sql . ' AND MATCH(subject, snippet, parties_text, gist, body_clean_text) AGAINST (%s IN BOOLEAN MODE)
					 ORDER BY score DESC, received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fuzzy fallback: best partial match first, then recency
					$args
				) );
				if ( ! empty( $frows ) ) {
					$rows       = $frows;
					$fuzzy_used = true;
				}
			}
		}

		// ── SUBSTRING RECALL NET (v0.9.6) ─────────────────────────────────────
		// Exact + fuzzy FULLTEXT both found nothing. FULLTEXT is word-token based: it
		// ignores a term below MySQL's minimum token size and cannot match a term glued
		// to punctuation or buried inside a longer token. One last owner-scoped,
		// direction-filtered, capped, recency-first SUBSTRING pass over the SAME columns
		// — INCLUDING gist + body_clean_text — recovers those. A genuine absence still
		// returns nothing, so the honest empty is preserved.
		if ( empty( $rows ) && '' !== $query && ! $recipient_scoped ) {
			$lrows = self::like_recall( $actor, $query, $dir_sql, $dir_args, $limit, $cols );
			if ( ! empty( $lrows ) ) {
				$rows       = $lrows;
				$fuzzy_used = true; // a substring hit is a NON-exact match -> render as broadened
			}
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$body = ZIB_Crypto::decrypt( (string) $r->body_enc );
			$text = self::body_to_text( $body, (string) $r->body_format );
			$out[] = array(
				'id'          => (int) $r->id,
				'from'        => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'subject'     => (string) $r->subject,
				'received_at' => $r->received_at ? (string) $r->received_at : '',
				'class'       => (string) $r->class,
				// Normalise the stored 'out'/'in' to the render's vocabulary. The render
				// and render_latest key on 'sent'; the store writes 'out' — without this
				// the "you → " / "you sent" phrasing never fired (a latent 0.4.x bug).
				'direction'   => ( 'out' === $r->direction ? 'sent' : 'received' ),
				// v0.9.11: the addressee of a SENT message, for the render's "you -> {to}" line. A
				// sent message's "from" is the owner; the recipient lives in the parties table.
				'to'          => ( 'out' === $r->direction ? self::recipient_label( $actor, (int) $r->id ) : '' ),
				'excerpt'     => self::excerpt( self::tidy_for_excerpt( $text ), self::CHAT_EXCERPT_CH ),
			);
		}
		$audit = ( '' !== $sender['label'] ) ? 'latest:sender' : $mode;
		if ( '' !== $dir_val ) { $audit .= ':' . $direction; }
		if ( '' !== $topic_state ) { $audit .= ':topic-' . $topic_state; }
		if ( $content_search ) { $audit .= ':text'; }
		self::log( $actor, $actor, 'owner_chat', 'allow', $audit, substr( sha1( $query ), 0, 16 ), count( $out ) );
		$ret = array( 'ok' => true, 'results' => $out, 'mode' => $mode, 'direction' => $direction );
		if ( '' !== $sender['label'] ) {
			$ret['sender'] = $sender['label'];
		}
		if ( $fuzzy_used ) {
			$ret['fuzzy'] = true;
		}
		if ( $content_search ) {
			$topic_state = ''; // the rows came from the text search, not from mail sent to that person
		}
		if ( '' !== $topic_state ) {
			$ret['topic']       = implode( ' ', $topic_terms );
			$ret['topic_state'] = $topic_state;
		}
		if ( $content_search && ! empty( $out ) ) {
			$ret['content_search'] = true;
		}
		return $ret;
	}

	/**
	 * P5 FOLLOW-UPS — thread-state over the owner's OWN indexed mail. Same doctrine as
	 * owner_chat (owner == actor forced; kiosk denied; fail-closed; audited; bounded;
	 * safe text). READ-ONLY and SURFACING only: it never sends or drafts (INV-SEND — the
	 * system composes; a human clicks Send on a full preview, never a chat command).
	 *
	 *   'awaiting_reply' — threads whose LAST message is outbound (you're waiting on them).
	 *   'needs_reply'    — threads whose LAST message is inbound (they're waiting on you);
	 *                      non-human senders (no-reply/notifications) + internal-class
	 *                      threads excluded by default.
	 *   'sent_log'       — outbound messages in a date range (default none = all), newest
	 *                      first + a real total count. Answers "what did I send this <period>".
	 *
	 * @return array { ok:bool, kind:string, count:int, threads:array[] }
	 */
	public static function owner_followups( int $actor, string $kind, array $opts = array() ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_followups', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'reason' => 'not_permitted', 'kind' => $kind, 'count' => 0, 'threads' => array() );
		}
		global $wpdb;
		$kind  = in_array( $kind, array( 'awaiting_reply', 'needs_reply', 'sent_log' ), true ) ? $kind : 'awaiting_reply';
		$limit = max( 1, min( self::MAX_FOLLOWUP, (int) ( $opts['limit'] ?? self::MAX_FOLLOWUP ) ) );
		// BODY-FREE by design: the follow-up digest carries subject / party / date / class
		// only — never message bodies. That is what lets the model recap it safely (the
		// injection payload lives in the body, which never leaves this reader). So we do
		// not even SELECT body_enc here.
		$cols  = 'id, ms_conversation_id, direction, class, from_addr, from_name, received_at, subject, snippet';

		if ( 'sent_log' === $kind ) {
			$since = self::sane_date( (string) ( $opts['since'] ?? '' ) );
			$until = self::sane_date( (string) ( $opts['until'] ?? '' ) );
			$where = 'owner_user_id = %d AND direction = %s';
			$args  = array( $actor, 'out' );
			if ( '' !== $since ) {
				$where .= ' AND received_at >= %s';
				$args[] = self::local_date_to_utc( $since, false );
			}
			if ( '' !== $until ) {
				$where .= ' AND received_at <= %s';
				$args[] = self::local_date_to_utc( $until, true );
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_msg() . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . $cols . ' FROM ' . self::t_msg() . ' WHERE ' . $where . ' ORDER BY received_at DESC LIMIT %d', array_merge( $args, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$threads = self::followup_rows( $actor, (array) $rows, 'out' );
			self::log( $actor, $actor, 'owner_followups', 'allow', 'sent_log', '', count( $threads ) );
			return array( 'ok' => true, 'kind' => $kind, 'count' => $count, 'threads' => $threads );
		}

		// awaiting_reply → owner sent last ('out'); needs_reply → owner received last ('in').
		// "Latest per thread" must be a SINGLE, deterministic message, else a same-second
		// send+receive in one thread could be its 'out' latest AND its 'in' latest and the
		// thread would show in BOTH digests (contradictory ball). NOT EXISTS picks the one
		// row with no later message by (received_at, id) — a total order — then filters by
		// that unique latest's direction. No GROUP BY (ONLY_FULL_GROUP_BY-safe); PHP dedup
		// below is now belt-and-suspenders.
		$dir      = ( 'needs_reply' === $kind ) ? 'in' : 'out';
		$is_needs = ( 'needs_reply' === $kind );
		$now      = ( isset( $opts['now'] ) && (int) $opts['now'] > 0 ) ? (int) $opts['now'] : time();

		// needs_reply is RANKED by "pressure" (how overdue + how many unanswered nudges + whether
		// they're an established contact), so it scans a wider LIVE pool and sorts in PHP; awaiting
		// stays recency-first with the tight scan. (Awaiting = who owes YOU; not a pressing to-do.)
		$scan = $is_needs ? ( self::MAX_FOLLOWUP * 2 ) : min( self::MAX_FOLLOWUP * 2, $limit + 20 );

		// Recency window (needs_reply only): a reply you owe from > NEEDS_MAX_AGE_DAYS ago is almost
		// surely dead — pruning it keeps "what's pressing" live and bounds the score's age term.
		$recency_sql = '';
		$q_args      = array( $actor, $dir );
		if ( $is_needs ) {
			$recency_sql = ' AND m.received_at >= %s';
			$q_args[]    = gmdate( 'Y-m-d H:i:s', $now - ( self::NEEDS_MAX_AGE_DAYS * 86400 ) );
		}
		$q_args[] = $actor; // NOT EXISTS subquery owner
		$q_args[] = $scan;  // LIMIT

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT m.id, m.ms_conversation_id, m.direction, m.class, m.from_addr, m.from_name, m.received_at, m.subject, m.snippet
			 FROM ' . self::t_msg() . ' m
			 WHERE m.owner_user_id = %d AND m.ms_conversation_id <> \'\' AND m.direction = %s' . $recency_sql . '
			   AND NOT EXISTS (
			        SELECT 1 FROM ' . self::t_msg() . ' m2
			         WHERE m2.owner_user_id = %d
			           AND m2.ms_conversation_id = m.ms_conversation_id
			           AND ( m2.received_at > m.received_at
			                 OR ( m2.received_at = m.received_at AND m2.id > m.id ) ) )
			 ORDER BY m.received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$q_args
		) );

		$keep = array();
		$seen = array();
		foreach ( (array) $rows as $r ) {
			$conv = (string) $r->ms_conversation_id;
			if ( '' === $conv || isset( $seen[ $conv ] ) ) {
				continue; // one row per thread (guards an exact-timestamp tie)
			}
			if ( $is_needs ) {
				// Live-window guard in PHP too (a loose driver may not honor the SQL cutoff).
				$lt = strtotime( (string) $r->received_at . ' UTC' );
				if ( $lt && intdiv( max( 0, $now - $lt ), 86400 ) > self::NEEDS_MAX_AGE_DAYS ) {
					continue;
				}
				// "Every real human": drop only AUTOMATED senders (no class arg passed), so a real
				// internal / staff person is KEPT — "who haven't I answered" shows partners and internal
				// people so the mailbox owner judges for themselves. Automated addresses still go.
				if ( empty( $opts['include_nonhuman'] ) && self::is_nonhuman_sender( (string) $r->from_addr ) ) {
					continue;
				}
			}
			if ( empty( $opts['include_noise'] ) && self::is_noise_thread( (string) $r->subject ) ) {
				continue; // one-way FYIs (forwarded lead-notifications, unsubscribe, auto-replies) aren't a reply owed either way
			}
			$seen[ $conv ] = true;
			$keep[]        = $r;
			if ( ! $is_needs && count( $keep ) >= $limit ) {
				break; // awaiting: recency-first, trim here. needs: score + sort FIRST, then trim below.
			}
		}

		$signals = array();
		if ( $is_needs && ! empty( $keep ) ) {
			// Rank by pressure: most-overdue / most-nudged / known-contact first. Body-free — every
			// signal is derived from message DIRECTION, TIMESTAMPS and prior-recipient history, never
			// a message body (the Ballast still never hands a body to the model).
			$signals = self::needs_signals( $actor, $keep, $now );
			usort( $keep, function ( $a, $b ) use ( $signals ) {
				$ca = (string) $a->ms_conversation_id;
				$cb = (string) $b->ms_conversation_id;
				$pa = (int) ( $signals[ $ca ]['pressure'] ?? 0 );
				$pb = (int) ( $signals[ $cb ]['pressure'] ?? 0 );
				if ( $pa !== $pb ) { return $pb <=> $pa; }
				$da = (int) ( $signals[ $ca ]['days_waiting'] ?? 0 );
				$db = (int) ( $signals[ $cb ]['days_waiting'] ?? 0 );
				if ( $da !== $db ) { return $db <=> $da; }
				$ta = (int) ( strtotime( (string) $a->received_at . ' UTC' ) ?: 0 );
				$tb = (int) ( strtotime( (string) $b->received_at . ' UTC' ) ?: 0 );
				if ( $ta !== $tb ) { return $tb <=> $ta; }
				return ( (int) $b->id ) <=> ( (int) $a->id );
			} );
			$keep = array_slice( $keep, 0, $limit );
		}

		$threads = self::followup_rows( $actor, $keep, $dir );
		if ( $is_needs ) {
			foreach ( $threads as $idx => $t ) {
				$c = (string) ( $t['conversation'] ?? '' );
				if ( isset( $signals[ $c ] ) ) {
					$threads[ $idx ]['days_waiting'] = (int) $signals[ $c ]['days_waiting'];
					$threads[ $idx ]['nudges']       = (int) $signals[ $c ]['nudges'];
					$threads[ $idx ]['known']        = (bool) $signals[ $c ]['known'];
					$threads[ $idx ]['pressure']     = (int) $signals[ $c ]['pressure'];
				}
			}
		}
		self::log( $actor, $actor, 'owner_followups', 'allow', $kind, '', count( $threads ) );
		return array( 'ok' => true, 'kind' => $kind, 'count' => count( $threads ), 'threads' => $threads );
	}

	// ── owner_aggregate (COMPUTE a mailbox answer) ─────────────────────

	/**
	 * COMPUTE a mailbox answer over the P6 enrichment extracts — "how many units of a given product
	 * have I ordered", "how much have I spent with <vendor>", "how many orders from X".
	 *
	 * BODY-FREE and OWNER-FORCED: it aggregates ONLY the structured wp_zib_extracts derived at
	 * ingest (never a body), and every row is scoped `e.owner_user_id = <actor>` — cross-user is
	 * unexpressible, exactly like owner_chat / owner_followups. Returns the computed value AND the
	 * source messages, so the render can GROUND the number in specific mail.
	 *
	 * @param array $spec { metric: sum_qty|sum_amount|count, kind?: product|money|order_ref,
	 *                      label?, unit?, from?, since?, until? } — a REQUEST only; identity is
	 *                      NEVER taken from here (the engine passes the real caller as $actor).
	 * @return array { ok, permitted, metric, kind, label, unit, value, count, since, until, sources[] }
	 */
	public static function owner_aggregate( int $actor, array $spec ): array {
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_aggregate', 'deny', 'not permitted', '', 0 );
			return array( 'ok' => false, 'permitted' => false, 'metric' => '', 'kind' => '', 'label' => '', 'unit' => '', 'value' => 0.0, 'count' => 0, 'since' => '', 'until' => '', 'sources' => array() );
		}
		global $wpdb;
		$ext = self::t_ext();
		$msg = self::t_msg();

		$metric = (string) ( $spec['metric'] ?? '' );
		$metric = in_array( $metric, array( 'sum_qty', 'sum_amount', 'count' ), true ) ? $metric : 'sum_qty';
		$kind   = (string) ( $spec['kind'] ?? '' );
		if ( '' === $kind ) {
			$kind = ( 'sum_amount' === $metric ) ? 'money' : 'product';
		}
		$kind = in_array( $kind, array( 'product', 'money', 'order_ref' ), true ) ? $kind : 'product';

		$label = strtolower( trim( (string) ( $spec['label'] ?? '' ) ) );
		$label = trim( (string) preg_replace( '/[^a-z0-9\- ]/', '', $label ) );
		$unit  = class_exists( 'ZIB_Enrich' ) ? ZIB_Enrich::canon_unit( (string) ( $spec['unit'] ?? '' ) ) : strtolower( trim( (string) ( $spec['unit'] ?? '' ) ) );
		$fromq = strtolower( trim( (string) ( $spec['from'] ?? '' ) ) );
		$since = self::sane_date( (string) ( $spec['since'] ?? '' ) );
		$until = self::sane_date( (string) ( $spec['until'] ?? '' ) );

		// Always join messages (for date/sender scoping + grounding). Owner forced on BOTH sides.
		$join  = ' INNER JOIN ' . $msg . ' m ON m.id = e.message_id AND m.owner_user_id = e.owner_user_id ';
		$where = 'e.owner_user_id = %d AND e.kind = %s';
		$args  = array( $actor, $kind );
		if ( '' !== $label ) {
			// "product name" should match the stored "product-name": spaces → wildcard.
			$where .= ' AND e.label LIKE %s';
			$args[] = '%' . str_replace( ' ', '%', $wpdb->esc_like( $label ) ) . '%';
		}
		if ( '' !== $unit ) {
			$where .= ' AND e.unit = %s';
			$args[] = $unit;
		}
		if ( '' !== $since ) { $where .= ' AND e.received_at >= %s'; $args[] = self::local_date_to_utc( $since, false ); }
		if ( '' !== $until ) { $where .= ' AND e.received_at <= %s'; $args[] = self::local_date_to_utc( $until, true ); }
		if ( '' !== $fromq ) {
			$like   = '%' . $wpdb->esc_like( $fromq ) . '%';
			$where .= ' AND ( LOWER(m.from_addr) LIKE %s OR LOWER(m.from_name) LIKE %s )';
			$args[] = $like;
			$args[] = $like;
		}

		// ── metric value ── (sum_qty is computed from the DE-DUPED orders below, not a raw SUM)
		if ( 'sum_amount' === $metric ) {
			$value = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(e.amount),0) FROM ' . $ext . ' e' . $join . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( 'count' === $metric ) {
			$value = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $ext . ' e' . $join . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$value = null; // filled from $qty_sum
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT e.message_id) FROM ' . $ext . ' e' . $join . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// ── DE-DUPED ORDERS: one row per order # (else per message). The redundant confirmation /
		//    shipment / delivery emails for the SAME order collapse to a single counted line, so the
		//    total is honest and the render can break it down. This is both the breakdown AND the
		//    sum_qty source. ──
		$ord_rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT COALESCE(NULLIF(e.ref, \'\'), CONCAT(\'m:\', e.message_id)) AS okey,
			        MAX(e.qty) AS qty, MAX(e.unit) AS unit, MAX(e.ref) AS ref,
			        MAX(e.raw_span) AS variant, MAX(e.label) AS label,
			        COUNT(DISTINCT e.message_id) AS emails,
			        MIN(m.received_at) AS first_seen, MAX(m.received_at) AS last_seen
			 FROM ' . $ext . ' e' . $join . ' WHERE ' . $where . '
			 GROUP BY okey ORDER BY first_seen ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array_merge( $args, array( self::MAX_AGG_SOURCES ) )
		) );
		$orders  = array();
		$qty_sum = 0.0;
		foreach ( $ord_rows as $r ) {
			$q        = isset( $r->qty ) ? (float) $r->qty : 0.0;
			$qty_sum += $q;
			$orders[] = array(
				'ref'         => (string) $r->ref,
				'qty'         => $q,
				'unit'        => (string) $r->unit,
				'variant'     => (string) $r->variant,
				'label'       => (string) $r->label,
				'emails'      => (int) $r->emails,
				'received_at' => $r->first_seen ? (string) $r->first_seen : '',
			);
		}
		if ( null === $value ) { $value = $qty_sum; }

		// matched-set date span (the emails that actually carried a counted extract)
		$matched_lo = ''; $matched_hi = '';
		$span = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(m.received_at) AS lo, MAX(m.received_at) AS hi FROM ' . $ext . ' e' . $join . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $span ) { $matched_lo = (string) ( $span->lo ?? '' ); $matched_hi = (string) ( $span->hi ?? '' ); }

		// "discussing" = messages that MENTION the topic (not only those with a counted extract), so
		// the answer can say "I found N emails about X" alongside the computed total.
		$discussing = $count;
		if ( '' !== $label ) {
			$dlike      = '%' . str_replace( ' ', '%', $wpdb->esc_like( $label ) ) . '%';
			$discussing = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $msg . ' WHERE owner_user_id = %d AND (
				   LOWER(subject) LIKE %s OR LOWER(snippet) LIKE %s OR LOWER(gist) LIKE %s OR LOWER(body_clean_text) LIKE %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$actor, $dlike, $dlike, $dlike, $dlike
			) );
		}

		// coverage: how far back the indexed mail goes (owner-wide) — the "how far back it looked".
		$cov         = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(received_at) AS lo, MAX(received_at) AS hi FROM ' . $msg . ' WHERE owner_user_id = %d', $actor ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$coverage_lo = $cov ? (string) ( $cov->lo ?? '' ) : '';
		$coverage_hi = $cov ? (string) ( $cov->hi ?? '' ) : '';

		// flat source list (grounding / back-compat)
		$src = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT e.message_id, e.qty, e.unit, e.amount, e.label, e.ref, m.subject, m.from_addr, m.from_name, m.received_at, m.direction
			 FROM ' . $ext . ' e' . $join . ' WHERE ' . $where . '
			 ORDER BY m.received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array_merge( $args, array( self::MAX_AGG_SOURCES ) )
		) );
		$sources = array();
		foreach ( $src as $r ) {
			$sources[] = array(
				'message_id'  => (int) $r->message_id,
				'subject'     => (string) $r->subject,
				'from'        => self::fmt_addr( (string) $r->from_name, (string) $r->from_addr ),
				'received_at' => $r->received_at ? (string) $r->received_at : '',
				'direction'   => ( 'out' === $r->direction ? 'sent' : 'received' ),
				'qty'         => isset( $r->qty ) ? (float) $r->qty : null,
				'unit'        => (string) $r->unit,
				'amount'      => isset( $r->amount ) ? (float) $r->amount : null,
				'ref'         => (string) $r->ref,
			);
		}

		self::log( $actor, $actor, 'owner_aggregate', 'allow', $metric . ':' . $kind, substr( sha1( $label . '|' . $unit . '|' . $fromq ), 0, 16 ), $count );
		return array(
			'ok'           => true,
			'permitted'    => true,
			'metric'       => $metric,
			'kind'         => $kind,
			'label'        => $label,
			'unit'         => $unit,
			'value'        => $value,
			'count'        => $count,
			'orders'       => $orders,
			'orders_count' => count( $orders ),
			'discussing'   => $discussing,
			'matched_lo'   => $matched_lo,
			'matched_hi'   => $matched_hi,
			'coverage_lo'  => $coverage_lo,
			'coverage_hi'  => $coverage_hi,
			'since'        => $since,
			'until'        => $until,
			'sources'      => $sources,
		);
	}

	/** Map raw follow-up rows → BODY-FREE thread structs; resolve the OTHER party
	 *  (recipient for outbound, sender for inbound). No body is read or returned — the
	 *  digest is subject/party/date/class only. Owner-scoped participants only. */
	private static function followup_rows( int $actor, array $rows, string $dir ): array {
		if ( empty( $rows ) ) {
			return array();
		}
		$recips = array();
		if ( 'out' === $dir ) {
			$ids = array();
			foreach ( $rows as $r ) {
				$id = (int) $r->id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				global $wpdb;
				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$pr = $wpdb->get_results( $wpdb->prepare(
					'SELECT message_id, name, addr FROM ' . self::t_party() . ' WHERE owner_user_id = %d AND role = %s AND message_id IN (' . $ph . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					array_merge( array( $actor, 'to' ), $ids )
				) );
				foreach ( (array) $pr as $p ) {
					$mid = (int) $p->message_id;
					if ( ! isset( $recips[ $mid ] ) ) {
						$recips[ $mid ] = self::fmt_addr( (string) $p->name, (string) $p->addr );
					}
				}
			}
		}
		$out = array();
		foreach ( $rows as $r ) {
			$other = ( 'out' === $dir )
				? (string) ( $recips[ (int) $r->id ] ?? '' )
				: self::fmt_addr( (string) $r->from_name, (string) $r->from_addr );
			$out[] = array(
				'id'             => (int) $r->id, // the thread's newest message: the one "reply to #2" answers
				'conversation'   => (string) $r->ms_conversation_id,
				'last_at'        => $r->received_at ? (string) $r->received_at : '',
				'last_direction' => ( 'out' === $r->direction ? 'sent' : 'received' ),
				'other_party'    => $other,
				'subject'        => (string) $r->subject,
				'class'          => (string) $r->class,
			);
		}
		return $out;
	}

	/**
	 * Body-free PRESSURE signals for needs_reply candidates. For each thread it derives, using
	 * only message DIRECTION, TIMESTAMPS and prior-recipient history (never a body):
	 *   - days_waiting : age of the latest inbound message (how overdue your reply is).
	 *   - nudges       : inbound messages since the owner's LAST outbound in that thread — or ALL
	 *                    inbound if the owner never replied. 2+ means they've written again unanswered.
	 *   - known        : the owner has SENT to this address before (an established two-way contact,
	 *                    distinct from a first-time cold sender). Mail-only — no CRM lookup.
	 *   - pressure     : the transparent score (see pressure_score()).
	 * Two bounded, owner-scoped queries over the candidate threads only. Keyed by conversation id.
	 *
	 * @return array conv => { days_waiting:int, nudges:int, known:bool, pressure:int }
	 */
	private static function needs_signals( int $actor, array $rows, int $now ): array {
		global $wpdb;
		$conv_addr = array();
		$conv_last = array();
		foreach ( $rows as $r ) {
			$c = (string) $r->ms_conversation_id;
			if ( '' === $c ) {
				continue;
			}
			$conv_addr[ $c ] = strtolower( trim( (string) $r->from_addr ) );
			$conv_last[ $c ] = (string) $r->received_at; // the latest inbound = the message you owe a reply to
		}
		$convs = array_keys( $conv_addr );
		if ( empty( $convs ) ) {
			return array();
		}
		$addrs = array_values( array_unique( array_filter( array_values( $conv_addr ) ) ) );

		// (1) NUDGES — direction + time of every message in the candidate threads (BODY-FREE), so we
		//     can count how many times they've written since the owner's last reply.
		$by_conv = array();
		$ph      = implode( ',', array_fill( 0, count( $convs ), '%s' ) );
		$mrows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT ms_conversation_id AS conv, direction, received_at, id
			 FROM ' . self::t_msg() . ' WHERE owner_user_id = %d AND ms_conversation_id IN (' . $ph . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array_merge( array( $actor ), $convs )
		) );
		foreach ( (array) $mrows as $m ) {
			$by_conv[ (string) $m->conv ][] = array(
				'dir' => (string) $m->direction,
				'ts'  => (int) ( strtotime( (string) $m->received_at . ' UTC' ) ?: 0 ),
				'id'  => (int) $m->id,
			);
		}

		// (2) KNOWN — addresses the owner has SENT to before (participants role='to'). Mail-only; an
		//     established contact you've corresponded with, not a first-time cold sender.
		$known = array();
		if ( $addrs ) {
			$ph2   = implode( ',', array_fill( 0, count( $addrs ), '%s' ) );
			$krows = $wpdb->get_results( $wpdb->prepare(
				'SELECT DISTINCT LOWER(addr) AS addr FROM ' . self::t_party() . '
				 WHERE owner_user_id = %d AND role = %s AND LOWER(addr) IN (' . $ph2 . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $actor, 'to' ), $addrs )
			) );
			foreach ( (array) $krows as $k ) {
				$known[ (string) $k->addr ] = true;
			}
		}

		$out = array();
		foreach ( $convs as $c ) {
			$lt   = (int) ( strtotime( ( (string) ( $conv_last[ $c ] ?? '' ) ) . ' UTC' ) ?: 0 );
			$days = $lt ? max( 0, intdiv( $now - $lt, 86400 ) ) : 0;

			$nud = 1;
			if ( ! empty( $by_conv[ $c ] ) ) {
				$list = $by_conv[ $c ];
				usort( $list, function ( $x, $y ) { return $x['ts'] !== $y['ts'] ? ( $x['ts'] <=> $y['ts'] ) : ( $x['id'] <=> $y['id'] ); } );
				$last_out = 0;
				foreach ( $list as $mm ) {
					if ( 'out' === $mm['dir'] ) { $last_out = $mm['ts']; }
				}
				$cnt = 0;
				foreach ( $list as $mm ) {
					if ( 'in' === $mm['dir'] && $mm['ts'] > $last_out ) { $cnt++; }
				}
				$nud = max( 1, $cnt );
			}

			$kn = isset( $conv_addr[ $c ] ) && '' !== $conv_addr[ $c ] && isset( $known[ $conv_addr[ $c ] ] );
			$out[ $c ] = array(
				'days_waiting' => $days,
				'nudges'       => $nud,
				'known'        => $kn,
				'pressure'     => self::pressure_score( $days, $nud, $kn ),
			);
		}
		return $out;
	}

	/**
	 * The transparent "pressing" score for a needs_reply thread — deliberately simple and
	 * explainable (every point traces to a stated fact on the row), never a black box:
	 *   • 25 points per EXTRA unanswered message from them  — the strongest "you're behind" signal;
	 *   • + days_waiting, capped at 30                       — age, bounded so one old thread can't
	 *                                                           drown out an actively-nudging one;
	 *   • + 10 if they're an established contact (known)     — you've emailed this address before.
	 * Higher = more pressing. Pure; unit-tested directly.
	 */
	public static function pressure_score( int $days_waiting, int $nudges, bool $known ): int {
		$days_waiting = max( 0, $days_waiting );
		$nudges       = max( 1, $nudges );
		return ( 25 * ( $nudges - 1 ) )
			+ min( $days_waiting, 30 )
			+ ( $known ? 10 : 0 );
	}

	/**
	 * Is a sender an automated / non-human address (or an internal-class thread)? Keeps
	 * newsletters, no-reply and employee↔employee threads out of "needs reply". Pure.
	 */
	public static function is_nonhuman_sender( string $addr, string $class = '' ): bool {
		if ( 'internal' === $class ) {
			return true;
		}
		$a = strtolower( trim( $addr ) );
		if ( '' === $a ) {
			return false;
		}
		$at     = strpos( $a, '@' );
		$local  = ( false !== $at ) ? substr( $a, 0, (int) $at ) : $a;
		$domain = ( false !== $at ) ? substr( $a, (int) $at + 1 ) : '';

		// (1) Automated LOCAL-PART token, bounded by a component edge (start/end or a
		// . _ + - separator), so "noreply@", "businessprofile-noreply@", "analytics-noreply@",
		// "bounces+tag@", "no-reply.support@" all match, but a real person at "noreplyman@",
		// "jbounces@" or "bob.donotreplywood@" is NOT wrongly hidden (the token must be a whole
		// component, never a substring of a longer word). Live gap this closes: Google Business
		// Profile / Analytics send from "*-noreply@google.com" — the OLD start-anchored test
		// missed them and they buried the real customer threads.
		if ( preg_match( '/(?:^|[._+-])(?:no-?reply|do-?not-?reply|donotreply|notifications?|notify|mailer-daemon|mailer|postmaster|automated|bounces?|newsletter|updates?|alerts?)(?:[._+-]|$)/', $local ) ) {
			return true;
		}
		// (2) Automated SUBDOMAIN label — services blast from mail./email./billing./
		// notifications./mailer./bounce./send. subdomains ("support@mail.mapping-service.example",
		// "invoice+statements@billing.mapping-service.example"); real people use a ROOT domain
		// (gmail.com, cox.net, a company's own domain). Root-domain vendors we DO converse
		// with (sales@supplier.example, orders@vendor.example) are untouched.
		$dlabel = ( '' !== $domain ) ? substr( $domain, 0, (int) strpos( $domain . '.', '.' ) ) : '';
		if ( in_array( $dlabel, array( 'mail', 'email', 'em', 'mailer', 'notifications', 'notification', 'notify', 'bounce', 'bounces', 'billing', 'send', 'sendgrid', 'mailgun', 'news', 'newsletter', 'noreply', 'reply', 'updates', 'marketing' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Is this thread's LAST message a one-way FYI / automated notice rather than a real
	 * conversation where a reply is owed either way? Forwarded lead-notifications ("Fwd: New
	 * form submission!"), unsubscribe confirmations, out-of-office / auto-replies and
	 * delivery-status notices are not "someone's waiting on a reply" — left in, they bury the
	 * real customer threads (the exact "data dump, not an answer" complaint). Subject-only (we
	 * hold no body here); pure. Overridable with opts['include_noise'].
	 */
	public static function is_noise_thread( string $subject ): bool {
		$s = strtolower( trim( $subject ) );
		if ( '' === $s ) {
			return false;
		}
		return (bool) (
			   preg_match( '/^(?:fwd?|fw):.*\bform submission\b/', $s )                                  // forwarded web/CRM lead-notification
			|| preg_match( '/^unsubscribe\b/', $s )                                                      // list-unsubscribe confirmation
			|| preg_match( '/^(?:re:\s*)?(?:out of office|automatic reply|auto-?reply)\b/', $s )         // vacation / auto-responder
			|| preg_match( '/\b(?:delivery status notification|undeliverable|mail delivery (?:failed|subsystem))\b/', $s )
			// SaaS notifications / receipts / reports / reviews that name no reply owed either
			// way: software and service notices that otherwise rank as "pressing".
			|| preg_match( '/\bplugins?\s+(?:was|were)\s+updated\b/', $s )                               // a host's plugin-update notice
			|| preg_match( '/\bleft (?:a|another) review\b/', $s )                                       // Google Business Profile review notice
			|| preg_match( '/\byour receipt\b/', $s )                                                    // receipts
			|| preg_match( '/\bupload report\b/', $s )                                                   // a mapping service's upload report
			|| preg_match( '/\bperformance report is in\b/', $s )                                        // Google Analytics report
			|| preg_match( '/\bshipment confirmation\b/', $s )                                           // shipping confirmation
			|| preg_match( '/^welcome to\b/', $s )                                                       // onboarding / early-access
			|| preg_match( '/\bworking for you\??\s*$/', $s )                                            // "how is the new X working for you?"
			// One-way LOGISTICS notices — a delivered/shipped/tracking FYI is not a reply you owe (a
			// supplier's "A shipment from order #1001 has been delivered" would otherwise rank as
			// "pressing"). Kept TIGHT so a real complaint ("wrong item delivered", "part
			// arrived damaged") — which carries a substantive subject — is NOT swallowed: "delivered"
			// only counts when anchored to a shipment/order/package noun, as a carrier notice reads.
			|| preg_match( '/\bshipment\b[^.!?]*\bdeliver(?:ed|y)\b/', $s )                              // "a shipment … has been delivered"
			|| preg_match( '/\b(?:out for delivery|(?:order|package|it)\s+has\s+shipped|order\s+shipped)\b/', $s )
			|| preg_match( '/\btracking\s+(?:number|info(?:rmation)?|update|details)\b/', $s )           // carrier tracking notice
		);
	}

	/** Validate a YYYY-MM-DD date; return it or '' (never trust a caller-supplied range). */
	public static function sane_date( string $d ): string {
		$d = trim( $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}

	/**
	 * Convert a SITE-LOCAL calendar date (YYYY-MM-DD) to the UTC datetime for that day's
	 * START (00:00:00) or END (23:59:59) in the site's timezone. received_at is stored in UTC, but a
	 * user's "today" / "this week" means their LOCAL day — so a local day boundary must be expressed
	 * as the matching UTC instant before it is compared. When the site timezone IS UTC this is a no-op,
	 * so the filter is unchanged until a real zone (e.g. Europe/Berlin) is set. Fail-soft: on a
	 * malformed date or any error, returns the plain literal.
	 */
	private static function local_date_to_utc( string $ymd, bool $end ): string {
		$suffix = $end ? ' 23:59:59' : ' 00:00:00';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return $ymd . $suffix;
		}
		try {
			$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$local = new DateTimeImmutable( $ymd . $suffix, $tz );
			return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return $ymd . $suffix;
		}
	}

	/**
	 * Is this query nothing but a generic RECIPIENT / ROLE noun — "a customer",
	 * "someone", "a company", "sent to" — rather than a real person's name? Such a
	 * query must not be resolved as a sender (it matches every address containing the
	 * word) nor FULLTEXT'd (it matches every body that says it). Pure; used to blank the
	 * keyword so the ask falls back to a recency/direction search.
	 */
	public static function is_generic_recipient( string $q ): bool {
		$q = strtolower( trim( $q ) );
		if ( '' === $q ) {
			return false;
		}
		$q = preg_replace( '/^(?:a|an|the|my|our|some|any|another)\s+/', '', $q );
		$q = trim( (string) $q, " \t\n\r.?!," );
		static $generic = array(
			'customer', 'customers', 'client', 'clients', 'someone', 'somebody', 'anyone',
			'anybody', 'everyone', 'everybody', 'person', 'people', 'human', 'humans',
			'company', 'companies', 'contact', 'contacts', 'recipient', 'recipients',
			'lead', 'leads', 'prospect', 'prospects', 'sent to', 'a human',
		);
		return in_array( $q, $generic, true );
	}

	/**
	 * Common nickname ↔ formal-name variants for a single name token (case-insensitive),
	 * always including the token itself. Lets "Bob" also find "Robert" and vice-versa.
	 * Deliberately SMALL and high-confidence — a wrong expansion only widens a fallback
	 * search, but a garbage one wastes the budget, so only well-known pairs are listed.
	 */
	public static function name_variants( string $token ): array {
		$t = strtolower( trim( $token ) );
		if ( '' === $t ) {
			return array();
		}
		static $map = null;
		if ( null === $map ) {
			$pairs = array(
				array( 'robert', 'rob', 'bob', 'bobby' ),
				array( 'william', 'will', 'bill', 'billy' ),
				array( 'richard', 'rick', 'dick', 'rich' ),
				array( 'james', 'jim', 'jimmy', 'jamie' ),
				array( 'john', 'jack', 'johnny' ),
				array( 'michael', 'mike', 'mikey' ),
				array( 'david', 'dave' ),
				array( 'daniel', 'dan', 'danny' ),
				array( 'joseph', 'joe', 'joey' ),
				array( 'thomas', 'tom', 'tommy' ),
				array( 'charles', 'charlie', 'chuck' ),
				array( 'christopher', 'chris' ),
				array( 'anthony', 'tony' ),
				array( 'matthew', 'matt' ),
				array( 'edward', 'ed', 'eddie', 'ted' ),
				array( 'elizabeth', 'liz', 'beth', 'betty', 'eliza' ),
				array( 'katherine', 'catherine', 'kate', 'katie', 'kathy' ),
				array( 'margaret', 'maggie', 'meg', 'peggy' ),
				array( 'jennifer', 'jen', 'jenny' ),
				array( 'patricia', 'pat', 'patty', 'trish' ),
				array( 'nicholas', 'nick' ),
				array( 'samuel', 'sam', 'sammy' ),
				array( 'benjamin', 'ben', 'benji' ),
				array( 'alexander', 'alex' ),
				array( 'andrew', 'andy', 'drew' ),
				array( 'stephen', 'steven', 'steve' ),
				array( 'ronald', 'ron', 'ronnie' ),
				array( 'kenneth', 'ken', 'kenny' ),
			);
			$map = array();
			foreach ( $pairs as $group ) {
				foreach ( $group as $n ) {
					$map[ $n ] = $group;
				}
			}
		}
		return isset( $map[ $t ] ) ? $map[ $t ] : array( $t );
	}

	/**
	 * Resolve a person NAME (from the query) to THEIR OWN sender address(es),
	 * reading only the actor's own indexed mail. Returns:
	 *   { addresses:string[], label:string, ambiguous:bool, candidates:array[] }
	 *
	 *   - One distinct person (possibly several addresses) → addresses + label.
	 *   - Two+ distinct people who share the name → ambiguous + candidates (ask).
	 *   - No sender matches (it's a topic, not a person) → empty (caller falls back
	 *     to the FULLTEXT search).
	 *
	 * Never substitutes a different person who merely shares part of the name — the
	 * exact-phrase pass wins; token matching is only a fallback when the phrase is
	 * absent. Addresses are lower-cased for a case-insensitive IN() match upstream.
	 */
	public static function resolve_sender( int $actor, string $name ): array {
		$empty = array( 'addresses' => array(), 'label' => '', 'ambiguous' => false, 'candidates' => array() );
		if ( ! self::gate_owner( $actor ) ) {
			return $empty;
		}
		$name = trim( $name );
		if ( mb_strlen( $name ) < 2 || self::is_generic_recipient( $name ) ) {
			return $empty; // a real name, never a role noun ("customer", "someone")
		}

		// Prefer the WHOLE phrase ("Devin Alvarez" names one person); only fall back to
		// individual ≥3-char tokens when the exact phrase matches no sender at all.
		// Identity/alias seed: if the name or address maps to a known account, scan the
		// owner's mail for ANY of that person's addresses too — so "Jordan" (or "jordan@")
		// also finds mail sent from "install@". Guarded; unchanged when the resolver is absent.
		$seed = array( $name );
		if ( class_exists( 'ZDZ_Mailbox_Identity' ) ) {
			$cands = ( false !== strpos( $name, '@' ) ) ? array( $name ) : array();
			foreach ( preg_split( '/[^A-Za-z0-9_@.\-]+/', strtolower( $name ) ) as $w ) {
				$w = trim( (string) $w, '.@_-' );
				if ( strlen( $w ) < 3 ) {
					continue;
				}
				if ( false !== strpos( $w, '@' ) ) {
					$cands[] = $w;
				} else {
					foreach ( (array) ZDZ_Mailbox_Identity::internal_domains() as $d ) {
						$cands[] = $w . '@' . $d;
					}
				}
			}
			$seen = array();
			foreach ( $cands as $c ) {
				$uid = (int) ZDZ_Mailbox_Identity::user_for_address( $c );
				if ( $uid > 0 && ! isset( $seen[ $uid ] ) ) {
					$seen[ $uid ] = true;
					$seed = array_merge( $seed, (array) ZDZ_Mailbox_Identity::addresses_for_user( $uid ) );
				}
			}
			$seed = array_values( array_unique( $seed ) );
		}
		$rows = self::sender_groups( $actor, $seed );
		if ( empty( $rows ) ) {
			$toks = array();
			foreach ( preg_split( '/[^A-Za-z0-9@._-]+/', $name ) as $t ) {
				$t = trim( (string) $t );
				if ( mb_strlen( $t ) >= 3 ) {
					foreach ( self::name_variants( $t ) as $v ) {
						$toks[] = $v; // include common nickname/formal variants ("Bob" → also "Robert")
					}
				}
			}
			$toks = array_values( array_unique( $toks ) );
			if ( $toks ) {
				$rows = self::sender_groups( $actor, $toks );
			}
		}
		if ( empty( $rows ) ) {
			return $empty;
		}

		// Group by ADDRESS — the stable identity key. (Grouping by display name splits
		// a single address that appears both WITH and WITHOUT a from_name into two
		// duplicate people — "Morgan Blake <f@x>" + "<f@x>" → "morgan blake" and "f x com" —
		// which made "latest from Morgan" a permanent, un-resolvable "which did you
		// mean?". One address = one contact, whatever names it carried.)
		$by_addr = array();
		foreach ( $rows as $r ) {
			$addr = strtolower( trim( (string) $r->from_addr ) );
			if ( '' === $addr ) {
				continue;
			}
			$disp = trim( (string) $r->from_name );
			if ( ! isset( $by_addr[ $addr ] ) ) {
				$by_addr[ $addr ] = array( 'addr' => $addr, 'label' => '', 'count' => 0 );
			}
			if ( '' !== $disp && '' === $by_addr[ $addr ]['label'] ) {
				$by_addr[ $addr ]['label'] = $disp; // first non-empty display name for this address
			}
			$by_addr[ $addr ]['count'] += (int) $r->cnt;
		}
		if ( empty( $by_addr ) ) {
			return $empty;
		}

		// One person or several? Compare by normalized display name, ignoring addresses
		// that never carried one. One distinct name (or one address, or no names) → one
		// person, possibly many addresses. Two+ distinct names → genuinely ambiguous.
		$named = array();
		foreach ( $by_addr as $a ) {
			$nn = self::norm_name( (string) $a['label'] );
			if ( '' !== $nn ) {
				$named[ $nn ] = true;
			}
		}
		uasort( $by_addr, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

		if ( count( $by_addr ) === 1 || count( $named ) <= 1 ) {
			$addrs = array();
			$label = '';
			foreach ( $by_addr as $a ) {
				$addrs[] = $a['addr'];
				if ( '' === $label && '' !== $a['label'] ) {
					$label = $a['label'];
				}
			}
			if ( '' === $label ) {
				$label = $addrs[0];
			}
			return array( 'addresses' => $addrs, 'label' => $label, 'ambiguous' => false, 'candidates' => array() );
		}

		// Several distinct people → ask. One row per ADDRESS (never the same one twice).
		$cands = array();
		foreach ( array_slice( array_values( $by_addr ), 0, 5 ) as $a ) {
			$cands[] = array( 'label' => ( '' !== $a['label'] ? $a['label'] : $a['addr'] ), 'addr' => $a['addr'], 'count' => $a['count'] );
		}
		return array( 'addresses' => array(), 'label' => '', 'ambiguous' => true, 'candidates' => $cands );
	}

	/** Grouped sender scan for resolve_sender: each value LIKE-matched against name OR addr. */
	private static function sender_groups( int $actor, array $like_values ): array {
		global $wpdb;
		$clauses = array();
		$args    = array( $actor );
		foreach ( $like_values as $v ) {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				continue;
			}
			$like      = '%' . $wpdb->esc_like( $v ) . '%';
			$clauses[] = '(from_name LIKE %s OR from_addr LIKE %s)';
			$args[]    = $like;
			$args[]    = $like;
		}
		if ( empty( $clauses ) ) {
			return array();
		}
		$args[] = 40; // bounded candidate scan
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT from_addr, from_name, COUNT(*) AS cnt, MAX(received_at) AS last_at
			 FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d AND ( ' . implode( ' OR ', $clauses ) . ' )
			 GROUP BY from_addr, from_name
			 ORDER BY cnt DESC, last_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$args
		) );
	}

	// ── pure helpers (unit-tested) ──────────────────────────────────

	/**
	 * Format a "Name <addr>" line without ever eating the closing bracket.
	 *
	 * The obvious `trim( "$name <$addr>", ' <>' )` shortcut is wrong: its trim mask
	 * strips a trailing '>' from EVERY address, so "Morgan <morgan@example.com>" rendered as
	 * "Morgan <morgan@example.com". This builds the string by cases: both parts present →
	 * "Name <addr>"; only one → that one; neither → ''.
	 */
	public static function fmt_addr( string $name, string $addr ): string {
		$name = trim( $name );
		$addr = trim( $addr );
		if ( '' !== $name && '' !== $addr ) {
			return $name . ' <' . $addr . '>';
		}
		return '' !== $name ? $name : $addr;
	}

	/**
	 * Normalize a display name for grouping senders: lowercase, punctuation → space,
	 * whitespace collapsed. "Morgan Blake!" and "morgan  blake" both → "morgan blake", so
	 * one person's two spellings/addresses group together instead of reading as two.
	 */
	public static function norm_name( string $s ): string {
		$s = strtolower( trim( $s ) );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) );
	}

	/**
	 * Cap text to $max chars on a word boundary, single-spaced, with an ellipsis
	 * when truncated. Keeps each excerpt small and predictable for the model.
	 */
	public static function excerpt( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( $max <= 0 || strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > (int) ( $max * 0.6 ) ) {
			$cut = substr( $cut, 0, $sp );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * Build a BOOLEAN-MODE FULLTEXT query: each ≥2-char term becomes a required
	 * prefix (+term*). Strips operators so a user's punctuation can't inject
	 * boolean syntax. Returns '' when nothing usable remains.
	 */
	public static function build_boolean_query( string $q, bool $require = true ): string {
		$pre = $require ? '+' : ''; // require=false → OR-mode (any term), for the fuzzy fallback

		// Identity/alias awareness: if the query names a person or one of their addresses,
		// expand it (via ZDZ_Mailbox_Identity) to ALL that account's identifiers — every alias
		// local-part + display-name word, internal domain excluded — as a REQUIRED OR-GROUP.
		// So "Jordan" or "jordan@" also matches "install@", and the consumed tokens are NOT
		// AND-required literally (which is why "jordan install@example.com" used to find
		// nothing). Guarded: with the resolver absent this is empty and behaviour is unchanged.
		$idn = self::identity_expand( $q );

		$topics = array();
		foreach ( preg_split( '/[^A-Za-z0-9_@.]+/', strtolower( $q ) ) as $term ) {
			$term = trim( $term, '.@_' );
			if ( strlen( $term ) < 2 || isset( $idn['consumed'][ $term ] ) ) {
				continue;
			}
			$topics[] = $pre . $term . '*';
		}

		$parts = array();
		if ( ! empty( $idn['terms'] ) ) {
			$grp = array();
			foreach ( $idn['terms'] as $t ) {
				$grp[] = $t . '*'; // OR within the person group
			}
			// require → the person is a required group (any one identifier); fuzzy → fold in flat.
			$parts[] = $require ? ( '+(' . implode( ' ', $grp ) . ')' ) : implode( ' ', $grp );
		}
		foreach ( $topics as $t ) {
			$parts[] = $t;
		}
		return implode( ' ', $parts );
	}

	/**
	 * Identity/alias expansion for the FULLTEXT query. Resolves any address or bare
	 * staff-address word in the query to a WP account via ZDZ_Mailbox_Identity, and returns
	 * that account's search identifiers (alias local-parts + display-name words, internal
	 * domain excluded) plus the query tokens they consumed. Empty (no-op) when the resolver
	 * is not installed, so the base search is unchanged.
	 *
	 * @return array{terms:string[],consumed:array<string,bool>}
	 */
	private static function identity_expand( string $q ): array {
		$out = array( 'terms' => array(), 'consumed' => array() );
		if ( ! class_exists( 'ZDZ_Mailbox_Identity' ) ) {
			return $out;
		}
		$uids    = array();
		$domains = (array) ZDZ_Mailbox_Identity::internal_domains();

		// (1) explicit address tokens in the query.
		if ( preg_match_all( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $q, $m ) ) {
			foreach ( $m[0] as $addr ) {
				$uid = (int) ZDZ_Mailbox_Identity::user_for_address( $addr );
				if ( $uid > 0 ) {
					$uids[ $uid ] = true;
					$out['consumed'][ trim( strtolower( $addr ), '.@_' ) ] = true;
				}
			}
		}

		// (2) bare words that ARE a staff address local-part (word@internal-domain).
		foreach ( preg_split( '/[^A-Za-z0-9_@.]+/', strtolower( $q ) ) as $term ) {
			$term = trim( $term, '.@_' );
			if ( strlen( $term ) < 3 || false !== strpos( $term, '@' ) || isset( $out['consumed'][ $term ] ) ) {
				continue;
			}
			foreach ( $domains as $d ) {
				$uid = (int) ZDZ_Mailbox_Identity::user_for_address( $term . '@' . $d );
				if ( $uid > 0 ) {
					$uids[ $uid ]             = true;
					$out['consumed'][ $term ] = true;
					break;
				}
			}
		}

		if ( empty( $uids ) ) {
			return $out;
		}

		// internal-domain words to exclude from identifiers (e.g. 'example', 'com').
		$dwords = array();
		foreach ( $domains as $d ) {
			foreach ( explode( '.', strtolower( (string) $d ) ) as $w ) {
				if ( '' !== $w ) {
					$dwords[ $w ] = true;
				}
			}
		}

		// (3) gather each resolved account's identifiers.
		$terms = array();
		foreach ( array_keys( $uids ) as $uid ) {
			foreach ( (array) ZDZ_Mailbox_Identity::addresses_for_user( $uid ) as $addr ) {
				$local = strtolower( (string) strstr( (string) $addr, '@', true ) );
				if ( strlen( $local ) >= 2 && ! isset( $dwords[ $local ] ) ) {
					$terms[ $local ] = true;
				}
			}
			$u = get_userdata( (int) $uid );
			if ( $u ) {
				foreach ( preg_split( '/[^a-z0-9]+/', strtolower( (string) $u->display_name ) ) as $w ) {
					if ( strlen( $w ) >= 3 && ! isset( $dwords[ $w ] ) ) {
						$terms[ $w ] = true;
					}
				}
			}
		}
		$out['terms'] = array_keys( $terms );
		return $out;
	}

	/**
	 * Reduce an email body to safe, readable plain text. HTML is de-scripted,
	 * de-styled, tag-stripped, entity-decoded, whitespace-collapsed — the result
	 * is displayed with textContent, so no email markup ever reaches the DOM.
	 */
	/**
	 * v0.9.5 (data-minimisation + non-editorialising excerpts): tidy a decrypted body into a
	 * MINIMAL excerpt source. Strips zero-width / invisible characters (hidden-text hygiene) and
	 * cuts a bulk-mail marketing/legal FOOTER once real content has been seen — so a marketing
	 * email's address/copyright/unsubscribe boilerplate does not fill the answer, while a short
	 * message that merely mentions one of these words is never gutted. PURE.
	 */
	public static function tidy_for_excerpt( string $text ): string {
		// v0.9.8 null-safety: a /u pattern returns null on invalid UTF-8 (CP1252/Latin-1 mail parts
		// are common); fall back to the pre-step value so a stray byte never blanks the whole excerpt.
		$stripped = preg_replace( '/[\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text );
		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}
		if ( preg_match( '/(unsubscribe|view (?:this |the )?(?:email )?in (?:your )?browser|you (?:are )?receiv(?:e|ing) this (?:email|message|because)|manage (?:your )?(?:email )?preferences|update your preferences|why (?:did|am) I (?:get|receiving) this|sent to you because|\x{00A9}\s?20\d\d|copyright\s?\x{00A9}?\s?20\d\d)/iu', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$cut = (int) $m[0][1];
			if ( $cut >= 140 ) {
				$text = substr( $text, 0, $cut );
			}
		}
		$collapsed = preg_replace( '/\s{2,}/u', ' ', $text );
		return trim( is_string( $collapsed ) ? $collapsed : $text );
	}

	/**
	 * An HTML email with what its reader never sees taken out, its text left as it was. For the one email the
	 * chat's drafting model reads (ZIB_ZANA_Bridge::reply_source): hidden text is how an instruction the mailbox's
	 * owner cannot see would be slipped to a model. Not the reading pane's sanitiser (sanitize_email_html), which
	 * draws for a person. Pure. What it reads is below; past its limits, or without the DOM extension, it gives up
	 * and says so ($vis_gave_up: the result is '' and the chat asks for the text instead), never guesses.
	 *
	 * Read the way a mail program on a desktop screen draws the email (1024 px wide, light mode):
	 *   - comments go as the reading pane takes them out ("<!--" to the next "-->"), so an Outlook-only block
	 *     (<!--[if mso]>…<![endif]-->) goes and the copy every other program shows (<!--[if !mso]><!-->…) stays;
	 *     <style> is read as CSS wherever it is (an unclosed one to the end), a comment inside it included;
	 *   - the email's CSS: its rules in order, nested rules, @media read for that screen (@media all / screen, and a
	 *     width that includes it, apply; print, dark mode and a phone's width do not); inside @supports, @layer,
	 *     @container, @scope and @document only what hides is taken, as for a selector it reads only roughly;
	 *     selectors with tag, #id, .class, [attr] with every operator, the four combinators, the structural
	 *     pseudo-classes, :not() / :is() / :where(), CSS escapes; a pseudo-class a mail at rest never has (:hover,
	 *     :focus …) matches nothing; one it does not read (:has(), :lang() …) is read as matching, for what hides
	 *     only. The cascade: !important, the style attribute, specificity, order;
	 *   - gone with everything inside: display:none, the hidden attribute, mso-hide:all, content-visibility:hidden,
	 *     an opacity (with every parent's, and filter:opacity()) under 0.2, zoom or a scale under 0.1, a transform
	 *     or offset (left, top, right, bottom, a margin, text-indent) of 1,000px out or 500px back, clipped to
	 *     nothing, a box of 2px or less with overflow hidden, an SVG of no size or a part of one never drawn
	 *     (defs, desc …), the body of a closed <details>, a closed <dialog>, and what is never drawn (head,
	 *     title, style, script, template, noscript, comments);
	 *   - text hidden where it sits, until a child says otherwise: visibility:hidden, a font size under 3px (a
	 *     template's font-size:0 wrapper around columns that size their own text is not hidden), and text whose
	 *     colour is too close to what is under it (contrast under 1.25: white on white, #000 on #000, #fefefe,
	 *     transparent; a link is blue unless styled; a picture under it is not known, so its text is kept).
	 * Limits: 128 KB of CSS, 1,500 rules, 5,000 elements, 300,000 selector steps, 200 levels deep (deeper is not
	 * drawn and goes).
	 */
	public static $vis_gave_up = false;

	const VIS_MAX_CSS      = 131072;
	const VIS_MAX_RULES    = 1500;
	const VIS_MAX_ELEMENTS = 5000;
	const VIS_MAX_STEPS    = 300000;

	/** visible_html(): the properties that can show or hide text (after shorthands are read into these). */
	const VIS_PROPS = '/^(?:display|visibility|font-size|opacity|filter|color|-webkit-text-fill-color|fill|fill-opacity|transform|scale|translate|clip-path|clip|mso-hide|content-visibility|zoom|height|max-height|width|max-width|overflow|overflow-x|overflow-y|left|top|right|bottom|margin-left|margin-top|margin-right|text-indent|background-color|background-image|position|float|--[a-z0-9_-]+)$/';

	/** visible_html(): elements laid out inline unless something makes them a box (width and height do nothing to them). */
	const VIS_INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite', 'code', 'data', 'dfn', 'em', 'font', 'i', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var' );

	/** visible_html(): the CSS colour names, name:rrggbb. */
	const VIS_NAMED =
		'aliceblue:f0f8ff antiquewhite:faebd7 aqua:00ffff aquamarine:7fffd4 azure:f0ffff beige:f5f5dc'
		. ' bisque:ffe4c4 black:000000 blanchedalmond:ffebcd blue:0000ff blueviolet:8a2be2 brown:a52a2a'
		. ' burlywood:deb887 cadetblue:5f9ea0 chartreuse:7fff00 chocolate:d2691e coral:ff7f50 cornflowerblue:6495ed'
		. ' cornsilk:fff8dc crimson:dc143c cyan:00ffff darkblue:00008b darkcyan:008b8b darkgoldenrod:b8860b'
		. ' darkgray:a9a9a9 darkgreen:006400 darkgrey:a9a9a9 darkkhaki:bdb76b darkmagenta:8b008b'
		. ' darkolivegreen:556b2f darkorange:ff8c00 darkorchid:9932cc darkred:8b0000 darksalmon:e9967a'
		. ' darkseagreen:8fbc8f darkslateblue:483d8b darkslategray:2f4f4f darkslategrey:2f4f4f darkturquoise:00ced1'
		. ' darkviolet:9400d3 deeppink:ff1493 deepskyblue:00bfff dimgray:696969 dimgrey:696969 dodgerblue:1e90ff'
		. ' firebrick:b22222 floralwhite:fffaf0 forestgreen:228b22 fuchsia:ff00ff gainsboro:dcdcdc ghostwhite:f8f8ff'
		. ' gold:ffd700 goldenrod:daa520 gray:808080 green:008000 greenyellow:adff2f grey:808080 honeydew:f0fff0'
		. ' hotpink:ff69b4 indianred:cd5c5c indigo:4b0082 ivory:fffff0 khaki:f0e68c lavender:e6e6fa'
		. ' lavenderblush:fff0f5 lawngreen:7cfc00 lemonchiffon:fffacd lightblue:add8e6 lightcoral:f08080'
		. ' lightcyan:e0ffff lightgoldenrodyellow:fafad2 lightgray:d3d3d3 lightgreen:90ee90 lightgrey:d3d3d3'
		. ' lightpink:ffb6c1 lightsalmon:ffa07a lightseagreen:20b2aa lightskyblue:87cefa lightslategray:778899'
		. ' lightslategrey:778899 lightsteelblue:b0c4de lightyellow:ffffe0 lime:00ff00 limegreen:32cd32 linen:faf0e6'
		. ' magenta:ff00ff maroon:800000 mediumaquamarine:66cdaa mediumblue:0000cd mediumorchid:ba55d3'
		. ' mediumpurple:9370db mediumseagreen:3cb371 mediumslateblue:7b68ee mediumspringgreen:00fa9a'
		. ' mediumturquoise:48d1cc mediumvioletred:c71585 midnightblue:191970 mintcream:f5fffa mistyrose:ffe4e1'
		. ' moccasin:ffe4b5 navajowhite:ffdead navy:000080 oldlace:fdf5e6 olive:808000 olivedrab:6b8e23'
		. ' orange:ffa500 orangered:ff4500 orchid:da70d6 palegoldenrod:eee8aa palegreen:98fb98 paleturquoise:afeeee'
		. ' palevioletred:db7093 papayawhip:ffefd5 peachpuff:ffdab9 peru:cd853f pink:ffc0cb plum:dda0dd'
		. ' powderblue:b0e0e6 purple:800080 rebeccapurple:663399 red:ff0000 rosybrown:bc8f8f royalblue:4169e1'
		. ' saddlebrown:8b4513 salmon:fa8072 sandybrown:f4a460 seagreen:2e8b57 seashell:fff5ee sienna:a0522d'
		. ' silver:c0c0c0 skyblue:87ceeb slateblue:6a5acd slategray:708090 slategrey:708090 snow:fffafa'
		. ' springgreen:00ff7f steelblue:4682b4 tan:d2b48c teal:008080 thistle:d8bfd8 tomato:ff6347 turquoise:40e0d0'
		. ' violet:ee82ee wheat:f5deb3 white:ffffff whitesmoke:f5f5f5 yellow:ffff00 yellowgreen:9acd32';

	public static function visible_html( string $html ): string {
		self::$vis_gave_up = false;
		list( $html, $css ) = self::vis_split( $html );
		$html  = (string) preg_replace( '#<(head|title|template|noscript|xml)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$rules = self::vis_css_rules( $css );
		if ( self::$vis_gave_up ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			// Without the DOM extension the email's rules cannot be applied to its elements, so hidden text cannot
			// be told from visible text: it gives up, as for an email past its limits.
			self::$vis_gave_up = true;
			return '';
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = '' !== trim( $html ) && $doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return '';
		}
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$top  = $doc->documentElement;
		if ( ! $top ) {
			return '';
		}
		$ctx = self::vis_context( $rules );
		$st  = self::vis_start();
		$ctx['keep'][] = $top;
		$st  = self::vis_state( $top, $st, $ctx );
		if ( null !== $st && $body && ! $body->isSameNode( $top ) ) {
			$ctx['keep'][] = $body;
			$st = self::vis_state( $body, $st, $ctx );
		}
		if ( null === $st ) {
			return '';
		}
		self::vis_walk( $body ? $body : $top, $st, $ctx, 0 );
		if ( self::$vis_gave_up ) {
			return '';
		}
		// Decided on the email as it came, then cut: a sibling or :empty rule reads the tree before anything goes.
		foreach ( $ctx['blank'] as $t ) {
			$t->nodeValue = '';
		}
		foreach ( $ctx['drop'] as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}
		$out = '';
		foreach ( ( $body ? $body->childNodes : $doc->childNodes ) as $node ) {
			$out .= $doc->saveHTML( $node );
		}
		return $out;
	}

	/**
	 * visible_html(): the email without its comments, <style> and <script> (a mail program never draws them), and the
	 * CSS of its <style> blocks. Read in one pass, as a browser reads it: "<!--" outside a <style> starts a comment
	 * that runs to the next "-->" (or the end); inside a <style> it is only CSS; a <style> left open runs to the end.
	 */
	private static function vis_split( string $html ): array {
		$out = '';
		$css = '';
		$at  = 0;
		$len = strlen( $html );
		while ( $at < $len && preg_match( '/<!--|<(style|script)(?=[\s>\/])/i', $html, $m, PREG_OFFSET_CAPTURE, $at ) ) {
			$i    = (int) $m[0][1];
			$out .= substr( $html, $at, $i - $at ) . ' ';
			if ( '<!--' === $m[0][0] ) {
				$e  = strpos( $html, '-->', $i + 4 );
				$at = false === $e ? $len : $e + 3;
				continue;
			}
			$tag = strtolower( (string) $m[1][0] );
			$gt  = strpos( $html, '>', $i );
			if ( false === $gt ) {
				return array( $out, $css );
			}
			$end  = preg_match( '#</' . $tag . '\b[^>]*>#i', $html, $cm, PREG_OFFSET_CAPTURE, $gt + 1 ) ? (int) $cm[0][1] : -1;
			$text = $end < 0 ? substr( $html, $gt + 1 ) : substr( $html, $gt + 1, $end - $gt - 1 );
			if ( 'style' === $tag ) {
				$css .= "\n" . $text;
				if ( strlen( $css ) > self::VIS_MAX_CSS ) {
					self::$vis_gave_up = true;
					return array( $out, '' );
				}
			}
			$at = $end < 0 ? $len : $end + strlen( (string) $cm[0][0] );
		}
		return array( $out . substr( $html, $at ), $css );
	}

	/** visible_html(): the state at the top of the page: seen, 16px, black on white, fully opaque, not in an SVG. */
	private static function vis_start(): array {
		return array( 'vis' => true, 'px' => 16.0, 'fg' => array( 0, 0, 0, 1.0 ), 'tf' => null, 'fill' => array( 0, 0, 0, 1.0 ), 'bg' => array( array( 255, 255, 255 ) ), 'op' => 1.0, 'svg' => false, 'vars' => array() );
	}

	/** visible_html(): the rules indexed by what their last step needs (an id, a class, a tag, or anything). */
	private static function vis_context( array $rules ): array {
		$idx = array( 'id' => array(), 'cls' => array(), 'tag' => array(), 'any' => array() );
		foreach ( $rules as $ri => $r ) {
			$c = $r['sel'][ count( $r['sel'] ) - 1 ];
			if ( '' !== $c['id'] ) {
				$idx['id'][ $c['id'] ][] = $ri;
			} elseif ( $c['cls'] ) {
				$idx['cls'][ $c['cls'][0] ][] = $ri;
			} elseif ( '' !== $c['tag'] ) {
				$idx['tag'][ $c['tag'] ][] = $ri;
			} else {
				$idx['any'][] = $ri;
			}
		}
		return array( 'rules' => $rules, 'idx' => $idx, 'memo' => array(), 'cls' => array(), 'pos' => array(), 'keep' => array(), 'steps' => 0, 'els' => 0, 'blank' => array(), 'drop' => array() );
	}

	/** visible_html(): a node's children decided (hidden ones to go, text hidden where it sits to be blanked). */
	private static function vis_walk( DOMNode $node, array $st, array &$ctx, int $depth ): void {
		if ( $depth > 200 ) {
			foreach ( $node->childNodes as $child ) {
				$ctx['drop'][] = $child; // deeper than any layout a mail program draws: not read, so not kept
			}
			return;
		}
		$closed = ( $node instanceof DOMElement && 'details' === strtolower( $node->nodeName ) && ! $node->hasAttribute( 'open' ) );
		$seen   = $st['vis'] && $st['px'] >= 3.0 && self::vis_seen( $st );
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				if ( $closed || ! $seen ) {
					$ctx['blank'][] = $child;
				}
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				$ctx['drop'][] = $child; // a comment or the like: never drawn
				continue;
			}
			$ctx['keep'][] = $child;
			if ( $closed && 'summary' !== strtolower( $child->nodeName ) ) {
				$ctx['drop'][] = $child; // a closed <details> shows its summary only
				continue;
			}
			if ( ++$ctx['els'] > self::VIS_MAX_ELEMENTS || $ctx['steps'] > self::VIS_MAX_STEPS ) {
				self::$vis_gave_up = true;
				return;
			}
			$cs = self::vis_state( $child, $st, $ctx );
			if ( null === $cs ) {
				$ctx['drop'][] = $child; // hidden with everything inside it
				continue;
			}
			self::vis_walk( $child, $cs, $ctx, $depth + 1 );
			if ( self::$vis_gave_up ) {
				return;
			}
		}
	}

	/**
	 * visible_html(): the state under $el (vis: visibility; px: font size; fg / tf / fill: the text's colour; bg: the
	 * colours under it, null for a picture; op: opacity with its parents'; svg: inside an SVG), or null when $el is
	 * hidden with everything inside it.
	 */
	private static function vis_state( DOMElement $el, array $st, array &$ctx ) {
		$tag = strtolower( $el->nodeName );
		if ( $el->hasAttribute( 'hidden' ) || in_array( $tag, array( 'datalist', 'rp' ), true ) || ( 'dialog' === $tag && ! $el->hasAttribute( 'open' ) ) ) {
			return null;
		}
		if ( 'svg' === $tag ) {
			if ( preg_match( '/^\s*0(?:\.0*)?(?:px)?\s*$/', (string) $el->getAttribute( 'width' ) ) || preg_match( '/^\s*0(?:\.0*)?(?:px)?\s*$/', (string) $el->getAttribute( 'height' ) ) ) {
				return null;
			}
			$st['svg'] = true;
		} elseif ( $st['svg'] && in_array( $tag, array( 'defs', 'desc', 'metadata', 'symbol', 'clippath', 'mask', 'pattern', 'marker', 'lineargradient', 'radialgradient', 'filter' ), true ) ) {
			return null; // parts of an SVG that are never drawn as they stand
		}
		$d = self::vis_cascade( $el, $ctx );
		if ( $st['svg'] ) {
			foreach ( array( 'display', 'visibility', 'opacity', 'fill', 'fill-opacity', 'font-size', 'transform' ) as $a ) {
				if ( ! isset( $d[ $a ] ) && $el->hasAttribute( $a ) ) {
					$d[ $a ] = strtolower( trim( (string) $el->getAttribute( $a ) ) );
				}
			}
		}
		if ( ! isset( $d['color'] ) && ( 'font' === $tag || 'body' === $tag ) && $el->hasAttribute( 'font' === $tag ? 'color' : 'text' ) ) {
			$d['color'] = strtolower( trim( (string) $el->getAttribute( 'font' === $tag ? 'color' : 'text' ) ) );
		}
		if ( ! isset( $d['color'] ) && 'a' === $tag && $el->hasAttribute( 'href' ) ) {
			$d['color'] = 'rgb(0,0,238)'; // a link is blue unless something styles it (the browser's own default)
		}
		if ( ! isset( $d['background-color'] ) && $el->hasAttribute( 'bgcolor' ) ) {
			$d['background-color'] = strtolower( trim( (string) $el->getAttribute( 'bgcolor' ) ) );
		}
		if ( ! isset( $d['background-image'] ) && '' !== trim( (string) $el->getAttribute( 'background' ) ) ) {
			$d['background-image'] = 'url(x)';
		}
		// Custom properties are inherited, and var() is read in every value that reaches the element.
		foreach ( $d as $p => $v ) {
			if ( 0 === strpos( $p, '--' ) ) {
				$st['vars'][ $p ] = $v;
				unset( $d[ $p ] );
			}
		}
		foreach ( $d as $p => $v ) {
			if ( false !== strpos( $v, 'var(' ) ) {
				$d[ $p ] = self::vis_var( $v, $st['vars'], 0 ); // a fallback counts with no variable set
			}
		}
		$pos  = (string) ( $d['position'] ?? '' );
		$boxy = (bool) preg_match( '/^(?:absolute|fixed)$/', $pos ) || (bool) preg_match( '/^(?:left|right)$/', (string) ( $d['float'] ?? '' ) );
		$box  = array(
			'inline'     => ! $boxy && ( isset( $d['display'] ) ? (bool) preg_match( '/^(?:inline|contents)$/', $d['display'] ) : in_array( $tag, self::VIS_INLINE, true ) ),
			'positioned' => (bool) preg_match( '/^(?:relative|absolute|fixed|sticky)$/', $pos ),
			'abs'        => (bool) preg_match( '/^(?:absolute|fixed)$/', $pos ),
		);
		if ( self::vis_gone( $d, $box ) ) {
			return null;
		}
		$op = 1.0;
		if ( isset( $d['opacity'] ) && null !== ( $o = self::vis_ratio( $d['opacity'] ) ) ) {
			$op *= max( 0.0, min( 1.0, $o ) );
		}
		if ( isset( $d['filter'] ) && preg_match_all( '/\bopacity\(\s*([^)]*)\)|\balpha\(\s*opacity\s*=\s*([\d.]+)/', $d['filter'], $fm, PREG_SET_ORDER ) ) {
			foreach ( $fm as $f ) {
				$o   = '' !== (string) ( $f[2] ?? '' ) ? (float) $f[2] / 100 : self::vis_ratio( (string) $f[1] );
				$op *= null === $o ? 1.0 : max( 0.0, min( 1.0, $o ) );
			}
		}
		$st['op'] *= $op;
		if ( $st['op'] < 0.2 ) {
			return null;
		}
		if ( isset( $d['visibility'] ) ) {
			if ( preg_match( '/^(?:hidden|collapse)\b/', $d['visibility'] ) ) {
				$st['vis'] = false;
			} elseif ( preg_match( '/^(?:visible|initial)\b/', $d['visibility'] ) ) {
				$st['vis'] = true;
			}
		}
		if ( isset( $d['font-size'] ) ) {
			$st['px'] = self::vis_font_px( $d['font-size'], $st['px'] );
		}
		// What is under the text: a colour, the stops of a gradient, or a picture (not known).
		$img = (string) ( $d['background-image'] ?? '' );
		$bgc = isset( $d['background-color'] ) ? self::vis_color( $d['background-color'], null ) : null;
		if ( '' !== $img && ! preg_match( '/^(?:none|initial|unset|inherit|revert)$/', $img ) ) {
			$stops = preg_match( '/gradient\(/', $img ) && ! preg_match( '/\burl\(|image-set\(|element\(|paint\(/', $img ) ? self::vis_colors_in( $img ) : array();
			if ( $stops ) {
				$under = ( is_array( $bgc ) && $bgc[3] >= 0.999 ) ? array( array( $bgc[0], $bgc[1], $bgc[2] ) ) : $st['bg'];
				$st['bg'] = null === $under ? null : self::vis_over( $stops, $under );
			} else {
				$st['bg'] = null;
			}
		} elseif ( is_array( $bgc ) && $bgc[3] > 0.0 ) {
			$st['bg'] = $bgc[3] >= 0.999 ? array( array( $bgc[0], $bgc[1], $bgc[2] ) ) : ( null === $st['bg'] ? null : self::vis_over( array( $bgc ), $st['bg'] ) );
		}
		if ( isset( $d['color'] ) ) {
			$st['fg'] = self::vis_color( $d['color'], $st['fg'] );
		}
		if ( isset( $d['-webkit-text-fill-color'] ) ) {
			$st['tf'] = preg_match( '/^(?:currentcolor|inherit|unset|initial)$/', $d['-webkit-text-fill-color'] ) ? null : self::vis_color( $d['-webkit-text-fill-color'], null );
			if ( null === $st['tf'] && ! preg_match( '/^(?:currentcolor|inherit|unset|initial)$/', $d['-webkit-text-fill-color'] ) ) {
				$st['tf'] = 'unknown';
			}
		}
		if ( $st['svg'] ) {
			if ( isset( $d['fill'] ) ) {
				$st['fill'] = preg_match( '/^currentcolor$/', $d['fill'] ) ? $st['fg'] : ( 'none' === $d['fill'] ? array( 0, 0, 0, 0.0 ) : self::vis_color( $d['fill'], $st['fill'] ) );
			}
			if ( isset( $d['fill-opacity'] ) && is_array( $st['fill'] ) && null !== ( $fo = self::vis_ratio( $d['fill-opacity'] ) ) ) {
				$st['fill'][3] *= max( 0.0, min( 1.0, $fo ) );
			}
		}
		return $st;
	}

	/** visible_html(): can text in this state be told from what is under it? Not known (a picture, a colour not read): yes. */
	private static function vis_seen( array $st ): bool {
		$fg = $st['svg'] ? $st['fill'] : ( null !== $st['tf'] ? $st['tf'] : $st['fg'] );
		if ( ! is_array( $fg ) || null === $st['bg'] ) {
			return true;
		}
		if ( $fg[3] <= 0.05 ) {
			return false;
		}
		foreach ( $st['bg'] as $b ) {
			$c = array( $fg[0] * $fg[3] + $b[0] * ( 1 - $fg[3] ), $fg[1] * $fg[3] + $b[1] * ( 1 - $fg[3] ), $fg[2] * $fg[3] + $b[2] * ( 1 - $fg[3] ) );
			$x = self::vis_lum( $c );
			$y = self::vis_lum( $b );
			if ( ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ) >= 1.25 ) {
				return true;
			}
		}
		return false;
	}

	/** visible_html(): relative luminance of an sRGB colour. */
	private static function vis_lum( array $c ): float {
		$f = function ( $x ) {
			$x = max( 0.0, min( 255.0, (float) $x ) ) / 255;
			return $x <= 0.03928 ? $x / 12.92 : pow( ( $x + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $f( $c[0] ) + 0.7152 * $f( $c[1] ) + 0.0722 * $f( $c[2] );
	}

	/** visible_html(): each colour of $top laid over each of $under (opaque results). */
	private static function vis_over( array $top, array $under ): array {
		$out = array();
		foreach ( $top as $t ) {
			$a = isset( $t[3] ) ? (float) $t[3] : 1.0;
			foreach ( $under as $u ) {
				$out[] = array( $t[0] * $a + $u[0] * ( 1 - $a ), $t[1] * $a + $u[1] * ( 1 - $a ), $t[2] * $a + $u[2] * ( 1 - $a ) );
				if ( $a >= 0.999 ) {
					break;
				}
			}
		}
		return array_slice( $out, 0, 24 );
	}

	/**
	 * visible_html(): do these declarations hide the element with everything inside it? $box says how it is laid out
	 * (inline: width, height and text-indent do nothing to it; positioned: left / top move it; abs: clip applies);
	 * left out, whatever could hide is taken to.
	 */
	private static function vis_gone( array $d, array $box = array( 'inline' => false, 'positioned' => true, 'abs' => true ) ): bool {
		$get = function ( string $p ) use ( $d ): string {
			return isset( $d[ $p ] ) ? (string) $d[ $p ] : '';
		};
		if ( preg_match( '/^none\b/', $get( 'display' ) ) || preg_match( '/^all\b/', $get( 'mso-hide' ) ) || preg_match( '/^hidden\b/', $get( 'content-visibility' ) ) ) {
			return true;
		}
		if ( '' !== $get( 'zoom' ) && null !== ( $z = self::vis_ratio( $get( 'zoom' ) ) ) && $z < 0.1 ) {
			return true;
		}
		if ( self::vis_transform_hides( $get( 'transform' ) ) ) {
			return true;
		}
		if ( '' !== $get( 'scale' ) ) {
			$sv = preg_split( '/\s+/', trim( $get( 'scale' ) ) );
			foreach ( array_slice( $sv, 0, 2 ) as $v ) {
				$r = self::vis_ratio( $v );
				if ( null !== $r && abs( $r ) < 0.1 ) {
					return true;
				}
			}
		}
		if ( '' !== $get( 'translate' ) ) {
			foreach ( array_slice( preg_split( '/\s+/', trim( $get( 'translate' ) ) ), 0, 2 ) as $v ) {
				$px = self::vis_px( $v, 16.0, null );
				if ( null !== $px && abs( $px ) >= 1000.0 ) {
					return true;
				}
			}
		}
		if ( preg_match( '/\binset\(\s*(?:5\d|[6-9]\d|100)(?:\.\d+)?%|\bcircle\(\s*0(?:px|%)?\s*[ )a]|\bpolygon\(\s*(?:0[a-z%]*\s+0[a-z%]*\s*,?\s*)+\)/', $get( 'clip-path' ) ) ) {
			return true;
		}
		if ( preg_match( '/\bblur\(\s*([^)]*)\)/', $get( 'filter' ), $bm ) && null !== ( $bl = self::vis_px( $bm[1], 16.0, null ) ) && $bl >= 4.0 ) {
			return true; // blurred past reading
		}
		if ( ! empty( $box['abs'] ) && preg_match( '/^rect\(([^)]*)\)/', $get( 'clip' ), $cm ) ) {
			$e = array_map( function ( $x ) {
				return 'auto' === $x ? null : self::vis_px( $x, 16.0, null );
			}, self::vis_args( $cm[1] ) );
			if ( 4 === count( $e ) && ( ( null !== $e[1] && null !== $e[3] && $e[1] - $e[3] <= 2.0 ) || ( null !== $e[0] && null !== $e[2] && $e[2] - $e[0] <= 2.0 ) ) ) {
				return true; // clip: rect( top right bottom left ) of 2px or less across or down
			}
		}
		$moves = array( 'margin-left', 'margin-right' );
		if ( ! empty( $box['positioned'] ) ) {
			array_push( $moves, 'left', 'top', 'right', 'bottom' );
		}
		if ( empty( $box['inline'] ) ) {
			array_push( $moves, 'margin-top', 'text-indent' );
		}
		foreach ( $moves as $p ) {
			$px = '' !== $get( $p ) ? self::vis_px( $get( $p ), 16.0, null ) : null;
			if ( null !== $px && ( $px <= -500.0 || $px >= 1000.0 ) ) {
				return true;
			}
		}
		$clipped = (bool) preg_match( '/\b(?:hidden|clip)\b/', $get( 'overflow' ) . ' ' . $get( 'overflow-x' ) . ' ' . $get( 'overflow-y' ) );
		if ( $clipped && empty( $box['inline'] ) && preg_match( '/^(\d+(?:\.\d+)?)%$/', trim( $get( 'text-indent' ) ), $tm ) && (float) $tm[1] >= 100.0 ) {
			return true; // text-indent:100% with overflow hidden: the words start past the box's right edge
		}
		if ( $clipped && empty( $box['inline'] ) ) {
			foreach ( array( 'height', 'max-height', 'width', 'max-width' ) as $p ) {
				$px = '' !== $get( $p ) ? self::vis_px( $get( $p ), 16.0, null ) : null;
				if ( null !== $px && $px <= 2.0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/** visible_html(): does a transform scale the element to (almost) nothing, turn it edge-on, or move it off the page? */
	private static function vis_transform_hides( string $t ): bool {
		if ( '' === $t || 'none' === $t ) {
			return false;
		}
		foreach ( self::vis_funcs( $t ) as $f ) {
			$args = self::vis_args( $f[1] );
			switch ( $f[0] ) {
				case 'scale':
				case 'scalex':
				case 'scaley':
				case 'scale3d':
					foreach ( array_slice( $args, 0, 'scale3d' === $f[0] ? 2 : 2 ) as $a ) {
						$r = self::vis_ratio( $a );
						if ( null !== $r && abs( $r ) < 0.1 ) {
							return true;
						}
					}
					break;
				case 'translate':
				case 'translatex':
				case 'translatey':
				case 'translate3d':
					foreach ( array_slice( $args, 0, 2 ) as $a ) {
						$px = self::vis_px( $a, 16.0, null );
						if ( null !== $px && abs( $px ) >= 1000.0 ) {
							return true;
						}
					}
					break;
				case 'matrix':
				case 'matrix3d':
					$n = array_map( 'floatval', $args );
					if ( 'matrix' === $f[0] && 6 === count( $n ) && ( abs( $n[0] * $n[3] - $n[1] * $n[2] ) < 0.01 || abs( $n[4] ) >= 1000 || abs( $n[5] ) >= 1000 ) ) {
						return true;
					}
					if ( 'matrix3d' === $f[0] && 16 === count( $n ) && ( abs( $n[0] * $n[5] - $n[1] * $n[4] ) < 0.01 || abs( $n[12] ) >= 1000 || abs( $n[13] ) >= 1000 ) ) {
						return true;
					}
					break;
				case 'skew':
				case 'skewx':
				case 'skewy':
				case 'rotatex':
				case 'rotatey':
					foreach ( array_slice( $args, 0, 2 ) as $a ) {
						if ( preg_match( '/^(-?[\d.]+)(deg|turn|rad|grad)?$/', (string) $a, $am ) ) {
							$deg = (float) $am[1] * ( 'turn' === ( $am[2] ?? '' ) ? 360 : ( 'rad' === ( $am[2] ?? '' ) ? 57.2958 : ( 'grad' === ( $am[2] ?? '' ) ? 0.9 : 1 ) ) );
							$deg = fmod( abs( $deg ), 180.0 );
							if ( abs( $deg - 90.0 ) < 3.0 ) {
								return true; // edge-on, or skewed to nothing
							}
						}
					}
					break;
			}
		}
		return false;
	}

	/** visible_html(): the functions in a value, as [ name (lower case), what is between its parentheses ]. */
	private static function vis_funcs( string $v ): array {
		$out = array();
		$len = strlen( $v );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( ! preg_match( '/\G([a-z][a-z0-9-]*)\(/i', $v, $m, 0, $i ) ) {
				continue;
			}
			$j     = $i + strlen( $m[0] );
			$depth = 1;
			$k     = $j;
			for ( ; $k < $len && $depth > 0; $k++ ) {
				if ( '(' === $v[ $k ] ) {
					$depth++;
				} elseif ( ')' === $v[ $k ] ) {
					$depth--;
				}
			}
			$out[] = array( strtolower( $m[1] ), substr( $v, $j, max( 0, $k - $j - 1 ) ) );
			$i     = $k - 1;
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	/** visible_html(): a function's arguments, split at top-level commas (or spaces when there is no comma). */
	private static function vis_args( string $a ): array {
		$parts = self::vis_split_top( $a, ',' );
		if ( 1 === count( $parts ) ) {
			$parts = preg_split( '/\s+(?![^(]*\))/', trim( $a ) );
		}
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/** visible_html(): $s split at $sep where it is not inside parentheses or quotes. */
	private static function vis_split_top( string $s, string $sep ): array {
		$out   = array();
		$cur   = '';
		$depth = 0;
		$q     = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '' !== $q ) {
				$cur .= $c;
				if ( '\\' === $c && $i + 1 < $len ) {
					$cur .= $s[ ++$i ];
				} elseif ( $c === $q ) {
					$q = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$q = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ( ')' === $c || ']' === $c ) && $depth > 0 ) {
				$depth--;
			} elseif ( $c === $sep && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $c;
		}
		$out[] = $cur;
		return $out;
	}

	/** visible_html(): "0.5" or "50%" as 0.5; null when it is not a number. */
	private static function vis_ratio( string $v ): ?float {
		if ( ! preg_match( '/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)\s*(%?)\s*$/i', $v, $m ) ) {
			return null;
		}
		return '%' === $m[2] ? (float) $m[1] / 100 : (float) $m[1];
	}

	/** visible_html(): a font size in px, from its parent's ($parent px). */
	private static function vis_font_px( string $v, float $parent ): float {
		$k = array( 'xx-small' => 9, 'x-small' => 10, 'small' => 13, 'medium' => 16, 'large' => 18, 'x-large' => 24, 'xx-large' => 32, 'xxx-large' => 48 );
		$v = trim( $v );
		if ( isset( $k[ $v ] ) ) {
			return (float) $k[ $v ];
		}
		if ( 'smaller' === $v ) {
			return $parent / 1.2;
		}
		if ( 'larger' === $v ) {
			return $parent * 1.2;
		}
		if ( preg_match( '/^(?:inherit|unset|revert)$/', $v ) ) {
			return $parent;
		}
		if ( 'initial' === $v ) {
			return 16.0;
		}
		$px = self::vis_px( $v, $parent, $parent );
		return null === $px ? $parent : max( 0.0, $px );
	}

	/**
	 * visible_html(): a length in px, or null when it cannot be read. em is $em px, % is of $pct px (null: % not read);
	 * a plain number is px (as quirks mode, where most mail is drawn, reads it). calc(), min(), max() and clamp() are
	 * worked out.
	 */
	private static function vis_px( string $v, float $em, ?float $pct ): ?float {
		$v = trim( strtolower( $v ) );
		if ( '' === $v ) {
			return null;
		}
		$pos = 0;
		$r   = self::vis_expr( $v, $pos, $em, $pct, 0 );
		return ( null !== $r && $pos >= strlen( $v ) ) ? $r : null;
	}

	/** visible_html(): one calc() sum from $v at $pos (terms and factors), in px; null when it is not read. */
	private static function vis_expr( string $v, int &$pos, float $em, ?float $pct, int $depth ): ?float {
		if ( $depth > 8 ) {
			return null;
		}
		$sum = self::vis_term( $v, $pos, $em, $pct, $depth );
		while ( null !== $sum && preg_match( '/\G\s+([+-])\s+/', $v, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			$t    = self::vis_term( $v, $pos, $em, $pct, $depth );
			if ( null === $t ) {
				return null;
			}
			$sum = '+' === $m[1] ? $sum + $t : $sum - $t;
		}
		return $sum;
	}

	private static function vis_term( string $v, int &$pos, float $em, ?float $pct, int $depth ): ?float {
		$x = self::vis_factor( $v, $pos, $em, $pct, $depth );
		while ( null !== $x && preg_match( '/\G\s*([*\/])\s*/', $v, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			$y    = self::vis_factor( $v, $pos, $em, $pct, $depth );
			if ( null === $y || ( '/' === $m[1] && 0.0 === $y ) ) {
				return null;
			}
			$x = '*' === $m[1] ? $x * $y : $x / $y;
		}
		return $x;
	}

	private static function vis_factor( string $v, int &$pos, float $em, ?float $pct, int $depth ): ?float {
		if ( preg_match( '/\G\s*(calc|min|max|clamp)?\(\s*/', $v, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			$fn   = (string) ( $m[1] ?? '' );
			$vals = array();
			do {
				$x = self::vis_expr( $v, $pos, $em, $pct, $depth + 1 );
				if ( null === $x ) {
					return null;
				}
				$vals[] = $x;
			} while ( preg_match( '/\G\s*,\s*/', $v, $c, 0, $pos ) && ( $pos += strlen( $c[0] ) ) );
			if ( ! preg_match( '/\G\s*\)/', $v, $c, 0, $pos ) ) {
				return null;
			}
			$pos += strlen( $c[0] );
			if ( 'min' === $fn ) {
				return min( $vals );
			}
			if ( 'max' === $fn ) {
				return max( $vals );
			}
			if ( 'clamp' === $fn ) {
				return 3 === count( $vals ) ? max( $vals[0], min( $vals[1], $vals[2] ) ) : null;
			}
			return 1 === count( $vals ) ? $vals[0] : null;
		}
		if ( ! preg_match( '/\G\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(px|pt|pc|in|cm|mm|q|em|rem|ex|ch|%|vw|vh|vmin|vmax)?/', $v, $m, 0, $pos ) ) {
			return null;
		}
		$pos += strlen( $m[0] );
		$n    = (float) $m[1];
		switch ( $m[2] ?? '' ) {
			case 'pt':
				return $n * 4 / 3;
			case 'pc':
				return $n * 16;
			case 'in':
				return $n * 96;
			case 'cm':
				return $n * 37.8;
			case 'mm':
				return $n * 3.78;
			case 'q':
				return $n * 0.945;
			case 'em':
				return $n * $em;
			case 'rem':
				return $n * 16;
			case 'ex':
			case 'ch':
				return $n * $em / 2;
			case '%':
				return null === $pct ? null : $n * $pct / 100;
			case 'vw':
				return $n * 10.24;
			case 'vh':
				return $n * 7.68;
			case 'vmin':
				return $n * 7.68;
			case 'vmax':
				return $n * 10.24;
		}
		return $n;
	}

	/** visible_html(): a CSS colour as [ r, g, b, alpha ]; $cur for currentcolor / inherit; null when it is not read. */
	private static function vis_color( string $v, $cur ) {
		$v = strtolower( trim( $v ) );
		if ( '' === $v || preg_match( '/^(?:inherit|unset|currentcolor|revert|revert-layer)$/', $v ) ) {
			return $cur;
		}
		if ( 'initial' === $v ) {
			return array( 0, 0, 0, 1.0 );
		}
		if ( 'transparent' === $v ) {
			return array( 0, 0, 0, 0.0 );
		}
		if ( preg_match( '/^#([0-9a-f]+)$/', $v, $m ) ) {
			$h = $m[1];
			if ( 3 === strlen( $h ) || 4 === strlen( $h ) ) {
				$h = preg_replace( '/(.)/', '$1$1', $h );
			}
			if ( 6 !== strlen( $h ) && 8 !== strlen( $h ) ) {
				return null;
			}
			return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), 8 === strlen( $h ) ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0 );
		}
		if ( preg_match( '/^(rgba?|hsla?)\(\s*(.*?)\s*\)$/s', $v, $m ) ) {
			$p = preg_split( '/\s*[,\/]\s*|\s+/', $m[2] );
			if ( count( $p ) < 3 ) {
				return null;
			}
			$a = isset( $p[3] ) ? self::vis_ratio( $p[3] ) : 1.0;
			if ( null === $a ) {
				return null;
			}
			if ( 'r' === $m[1][0] ) {
				$c = array();
				for ( $k = 0; $k < 3; $k++ ) {
					$x = self::vis_ratio( $p[ $k ] );
					if ( null === $x ) {
						return null;
					}
					$c[] = false !== strpos( $p[ $k ], '%' ) ? $x * 255 : $x;
				}
				return array( $c[0], $c[1], $c[2], max( 0.0, min( 1.0, $a ) ) );
			}
			$h = (float) $p[0];
			$s = self::vis_ratio( $p[1] );
			$l = self::vis_ratio( $p[2] );
			if ( null === $s || null === $l ) {
				return null;
			}
			$s = false !== strpos( $p[1], '%' ) ? $s : $s / 100;
			$l = false !== strpos( $p[2], '%' ) ? $l : $l / 100;
			$f = function ( $n ) use ( $h, $s, $l ) {
				$k = fmod( $n + $h / 30, 12 );
				return 255 * ( $l - $s * min( $l, 1 - $l ) * max( -1, min( $k - 3, 9 - $k, 1 ) ) );
			};
			return array( $f( 0 ), $f( 8 ), $f( 4 ), max( 0.0, min( 1.0, $a ) ) );
		}
		if ( preg_match( '/(?:^| )' . preg_quote( $v, '/' ) . ':([0-9a-f]{6})\b/', self::VIS_NAMED, $m ) ) {
			return array( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ), 1.0 );
		}
		return null;
	}

	/** visible_html(): every colour named in a value (a gradient's stops), each [ r, g, b, alpha ]. */
	private static function vis_colors_in( string $v ): array {
		$out = array();
		if ( preg_match_all( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\([^)]*\)|\b[a-z]+\b/', strtolower( $v ), $m ) ) {
			foreach ( $m[0] as $t ) {
				$c = self::vis_color( $t, null );
				if ( is_array( $c ) ) {
					$out[] = $c;
				}
				if ( count( $out ) >= 12 ) {
					break;
				}
			}
		}
		return $out;
	}

	/** visible_html(): the declarations that reach $el, by the cascade: !important, the style attribute, specificity, order. */
	private static function vis_cascade( DOMElement $el, array &$ctx ): array {
		$idx  = $ctx['idx'];
		$cand = $idx['any'];
		$id   = strtolower( (string) $el->getAttribute( 'id' ) );
		if ( '' !== $id && isset( $idx['id'][ $id ] ) ) {
			$cand = array_merge( $cand, $idx['id'][ $id ] );
		}
		foreach ( array_keys( self::vis_classes( $el, $ctx ) ) as $c ) {
			if ( isset( $idx['cls'][ $c ] ) ) {
				$cand = array_merge( $cand, $idx['cls'][ $c ] );
			}
		}
		$tag = strtolower( $el->nodeName );
		if ( isset( $idx['tag'][ $tag ] ) ) {
			$cand = array_merge( $cand, $idx['tag'][ $tag ] );
		}
		$win = array();
		foreach ( array_unique( $cand ) as $ri ) {
			$r = $ctx['rules'][ $ri ];
			if ( ! self::vis_matches( $el, $r['sel'], count( $r['sel'] ) - 1, $ctx, (int) $ri ) ) {
				continue;
			}
			foreach ( $r['decl'] as $p => $v ) {
				$key = array( $v[1] ? 1 : 0, 0, $r['spec'][0], $r['spec'][1], $r['spec'][2], $r['order'] );
				if ( ! isset( $win[ $p ] ) || $key > $win[ $p ][1] ) {
					$win[ $p ] = array( $v[0], $key );
				}
			}
		}
		foreach ( self::vis_decls( (string) $el->getAttribute( 'style' ) ) as $p => $v ) {
			$key = array( $v[1] ? 1 : 0, 1, 0, 0, 0, 0 );
			if ( ! isset( $win[ $p ] ) || $key > $win[ $p ][1] ) {
				$win[ $p ] = array( $v[0], $key );
			}
		}
		$d = array();
		foreach ( $win as $p => $w ) {
			$d[ $p ] = $w[0];
		}
		return $d;
	}

	/** visible_html(): "prop: value; …" as prop => [ value (lower case, CSS escapes decoded), important ], shorthands read. Pure. */
	private static function vis_decls( string $style ): array {
		$out = array();
		if ( '' === trim( $style ) ) {
			return $out;
		}
		foreach ( self::vis_split_top( $style, ';' ) as $decl ) {
			$c = strpos( $decl, ':' );
			if ( false === $c ) {
				continue;
			}
			$prop = strtolower( trim( self::vis_unescape( substr( $decl, 0, $c ) ) ) );
			if ( ! preg_match( '/^(?:-?[a-z][a-z0-9-]*|--[a-z0-9_-]+)$/', $prop ) ) {
				continue;
			}
			$val = strtolower( trim( self::vis_unescape( substr( $decl, $c + 1 ) ) ) );
			$imp = (bool) preg_match( '/!\s*important\s*$/', $val );
			$val = trim( (string) preg_replace( '/!\s*important\s*$/', '', $val ) );
			foreach ( self::vis_expand( $prop, $val ) as $p => $v ) {
				if ( ! isset( $out[ $p ] ) || ! $out[ $p ][1] || $imp ) {
					$out[ $p ] = array( $v, $imp );
				}
			}
		}
		return $out;
	}

	/** visible_html(): a shorthand read into the properties it sets (background, font, inset, margin, overflow). */
	private static function vis_expand( string $p, string $v ): array {
		switch ( $p ) {
			case 'background':
				$col = 'transparent';
				$img = 'none';
				foreach ( self::vis_args( str_replace( ',', ' , ', $v ) ) as $t ) {
					if ( preg_match( '/^(?:url|image-set|element|paint|cross-fade|[a-z-]*gradient)\(/', $t ) ) {
						$img = 'none' === $img ? $t : $img . ' ' . $t;
					} elseif ( is_array( self::vis_color( $t, null ) ) ) {
						$col = $t;
					}
				}
				return array( 'background-color' => $col, 'background-image' => $img );
			case 'font':
				// [style variant weight stretch] size[/line-height] family: the size is the first length or size word
				foreach ( preg_split( '/\s+/', trim( (string) preg_replace( '/\s*\/\s*/', '/', $v ) ) ) as $t ) {
					$t = explode( '/', $t )[0];
					if ( preg_match( '/^(?:[\d.]+(?:[a-z%]+)?|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger|calc\(.*|min\(.*|max\(.*|clamp\(.*)$/', $t ) && ! preg_match( '/^\d{3}$/', $t ) ) {
						return array( 'font-size' => $t );
					}
				}
				return array();
			case 'inset':
			case 'margin':
				$t = self::vis_args( $v );
				$n = count( $t );
				if ( $n < 1 || $n > 4 ) {
					return array();
				}
				$top    = $t[0];
				$right  = $t[ $n > 1 ? 1 : 0 ];
				$bottom = $t[ $n > 2 ? 2 : 0 ];
				$left   = $t[ 4 === $n ? 3 : ( $n > 1 ? 1 : 0 ) ];
				return 'inset' === $p
					? array( 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left )
					: array( 'margin-top' => $top, 'margin-right' => $right, 'margin-left' => $left );
			case 'overflow':
				$t = preg_split( '/\s+/', trim( $v ) );
				return array( 'overflow-x' => $t[0], 'overflow-y' => $t[1] ?? $t[0] );
		}
		return array( $p => $v );
	}

	/** visible_html(): CSS escapes ("n\6f ne", "u\rl") read as the characters they stand for. Pure. */
	private static function vis_unescape( string $s ): string {
		if ( false === strpos( $s, '\\' ) ) {
			return $s;
		}
		return (string) preg_replace_callback( '/\\\\(?:([0-9a-f]{1,6})\s?|(.))/is', function ( $m ) {
			if ( '' !== (string) ( $m[1] ?? '' ) ) {
				$cp = hexdec( $m[1] );
				return ( $cp > 0 && $cp < 0x110000 && function_exists( 'mb_chr' ) ) ? (string) mb_chr( (int) $cp, 'UTF-8' ) : '';
			}
			return (string) ( $m[2] ?? '' );
		}, $s );
	}

	/** visible_html(): var(--name, fallback) in a value read from the custom properties in force (five deep at most). */
	private static function vis_var( string $v, array $vars, int $depth ): string {
		if ( $depth > 5 ) {
			return $v;
		}
		$out = preg_replace_callback( '/var\(\s*(--[a-z0-9_-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)/', function ( $m ) use ( $vars, $depth ) {
			if ( isset( $vars[ $m[1] ] ) ) {
				return self::vis_var( (string) $vars[ $m[1] ], $vars, $depth + 1 );
			}
			return isset( $m[2] ) ? self::vis_var( trim( (string) $m[2] ), $vars, $depth + 1 ) : $m[0];
		}, $v );
		return is_string( $out ) ? $out : $v;
	}

	/**
	 * visible_html(): the email's own CSS read into rules, each { sel: compounds, spec, order, decl } with only the
	 * properties that can show or hide text. One pass over the text (no rescans), nested blocks to six deep.
	 */
	private static function vis_css_rules( string $css ): array {
		if ( '' === trim( $css ) ) {
			return array();
		}
		$css   = (string) preg_replace( '#/\*.*?(?:\*/|$)#s', ' ', $css );
		$css   = str_replace( array( '<!--', '-->' ), ' ', $css );
		$rules = array();
		$order = 0;
		$i     = 0;
		while ( $i < strlen( $css ) && ! self::$vis_gave_up ) {
			self::vis_css_block( $css, $i, array(), false, $rules, $order, 0 );
		}
		return $rules;
	}

	/**
	 * visible_html(): one block of CSS from $i to the "}" that closes it (or the end). $outer: the selectors of the
	 * style rule it belongs to (its own declarations, and CSS nesting); $rough: only what hides is taken from it.
	 */
	private static function vis_css_block( string $css, int &$i, array $outer, bool $rough, array &$rules, int &$order, int $depth ): void {
		$len  = strlen( $css );
		$own  = $order++;
		$text = '';
		$decl = '';
		while ( $i < $len ) {
			$ch = $css[ $i ];
			if ( '"' === $ch || "'" === $ch ) {
				$k = $i + 1;
				while ( $k < $len && $css[ $k ] !== $ch ) {
					$k += ( '\\' === $css[ $k ] ) ? 2 : 1;
				}
				$text .= substr( $css, $i, $k + 1 - $i );
				$i     = $k + 1;
				continue;
			}
			if ( '\\' === $ch ) {
				$text .= substr( $css, $i, 2 );
				$i    += 2;
				continue;
			}
			if ( '(' === $ch ) {
				$k     = $i;
				$depth2 = 0;
				do {
					if ( '(' === $css[ $k ] ) {
						$depth2++;
					} elseif ( ')' === $css[ $k ] ) {
						$depth2--;
					}
					$k++;
				} while ( $k < $len && $depth2 > 0 && '{' !== $css[ $k ] && '}' !== $css[ $k ] );
				$text .= substr( $css, $i, $k - $i );
				$i     = $k;
				continue;
			}
			if ( ';' === $ch ) {
				$decl .= $text . ';';
				$text  = '';
				$i++;
				continue;
			}
			if ( '}' === $ch ) {
				$i++;
				break;
			}
			if ( '{' === $ch ) {
				$i++;
				$pre  = trim( $text );
				$text = '';
				if ( $depth >= 6 ) {
					self::vis_css_skip( $css, $i );
					continue;
				}
				if ( '' !== $pre && '@' === $pre[0] ) {
					$how = self::vis_at( $pre );
					if ( 'skip' === $how ) {
						self::vis_css_skip( $css, $i );
					} else {
						self::vis_css_block( $css, $i, $outer, $rough || 'rough' === $how, $rules, $order, $depth + 1 );
					}
					continue;
				}
				$sels = self::vis_nest( $pre, $outer );
				if ( $sels ) {
					self::vis_css_block( $css, $i, $sels, $rough, $rules, $order, $depth + 1 );
				} else {
					self::vis_css_skip( $css, $i );
				}
				continue;
			}
			$text .= $ch;
			$i++;
		}
		if ( $outer ) {
			self::vis_add_rule( $outer, $decl . $text, $rough, $own, $rules );
		}
	}

	/** visible_html(): past a block whose rules are not read, its braces matched. */
	private static function vis_css_skip( string $css, int &$i ): void {
		$len   = strlen( $css );
		$depth = 1;
		while ( $i < $len && $depth > 0 ) {
			$ch = $css[ $i ];
			if ( '"' === $ch || "'" === $ch ) {
				$k = $i + 1;
				while ( $k < $len && $css[ $k ] !== $ch ) {
					$k += ( '\\' === $css[ $k ] ) ? 2 : 1;
				}
				$i = $k + 1;
				continue;
			}
			if ( '\\' === $ch ) {
				$i += 2;
				continue;
			}
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
			}
			$i++;
		}
	}

	/** visible_html(): an at-rule's block: 'read' (it applies as written), 'rough' (only what hides), or 'skip'. */
	private static function vis_at( string $pre ): string {
		if ( ! preg_match( '/^@([a-z-]+)\s*(.*)$/is', $pre, $m ) ) {
			return 'skip';
		}
		$name = strtolower( $m[1] );
		if ( 'media' === $name ) {
			return self::vis_media( (string) $m[2] ) ? 'read' : 'skip';
		}
		return in_array( $name, array( 'supports', 'layer', 'container', 'scope', 'document', '-moz-document' ), true ) ? 'rough' : 'skip';
	}

	/** visible_html(): does a media query list apply to a desktop screen (1024 x 768, light, a mouse)? */
	private static function vis_media( string $q ): bool {
		foreach ( self::vis_split_top( strtolower( $q ), ',' ) as $one ) {
			$one = trim( $one );
			$neg = false;
			if ( preg_match( '/^not\s+/', $one ) ) {
				$neg = true;
				$one = trim( substr( $one, 3 ) );
			}
			$one = (string) preg_replace( '/^only\s+/', '', $one );
			$ok  = true;
			if ( preg_match( '/^([a-z-]+)/', $one, $tm ) && 'and' !== $tm[1] ) {
				$ok = in_array( $tm[1], array( 'all', 'screen' ), true );
			}
			if ( $ok && preg_match_all( '/\(([^()]*)\)/', $one, $fm ) ) {
				foreach ( $fm[1] as $f ) {
					if ( ! self::vis_media_feature( trim( $f ) ) ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok !== $neg ) {
				return true;
			}
		}
		return false;
	}

	private static function vis_media_feature( string $f ): bool {
		$dim = array( 'width' => 1024.0, 'height' => 768.0 );
		if ( preg_match( '/^(min|max)-(?:device-)?(width|height)\s*:\s*(.+)$/', $f, $m ) ) {
			$px = self::vis_px( $m[3], 16.0, null );
			return null === $px || ( 'min' === $m[1] ? $dim[ $m[2] ] >= $px : $dim[ $m[2] ] <= $px );
		}
		if ( preg_match( '/^(?:device-)?(width|height)\s*:\s*(.+)$/', $f, $m ) ) {
			$px = self::vis_px( $m[2], 16.0, null );
			return null === $px || abs( $dim[ $m[1] ] - $px ) < 1;
		}
		if ( preg_match( '/^(?:(.+?)\s*(<=|>=|<|>|=)\s*)?(?:device-)?(width|height)(?:\s*(<=|>=|<|>|=)\s*(.+))?$/', $f, $m ) && ( '' !== ( $m[1] ?? '' ) || '' !== ( $m[4] ?? '' ) ) ) {
			$v = $dim[ $m[3] ];
			if ( '' !== ( $m[1] ?? '' ) && null !== ( $a = self::vis_px( $m[1], 16.0, null ) ) && ! self::vis_cmp( $a, $m[2], $v ) ) {
				return false;
			}
			if ( '' !== ( $m[4] ?? '' ) && null !== ( $b = self::vis_px( (string) $m[5], 16.0, null ) ) && ! self::vis_cmp( $v, $m[4], $b ) ) {
				return false;
			}
			return true;
		}
		if ( preg_match( '/^prefers-color-scheme\s*:\s*([a-z]+)/', $f, $m ) ) {
			return 'dark' !== $m[1];
		}
		if ( preg_match( '/^orientation\s*:\s*([a-z]+)/', $f, $m ) ) {
			return 'portrait' !== $m[1];
		}
		if ( preg_match( '/^(?:any-)?hover\s*:\s*none|^(?:any-)?pointer\s*:\s*(?:none|coarse)|^prefers-reduced-transparency|^forced-colors\s*:\s*active|^inverted-colors\s*:\s*inverted/', $f ) ) {
			return false;
		}
		return true; // anything else a desktop screen has
	}

	private static function vis_cmp( float $a, string $op, float $b ): bool {
		switch ( $op ) {
			case '<':
				return $a < $b;
			case '<=':
				return $a <= $b;
			case '>':
				return $a > $b;
			case '>=':
				return $a >= $b;
		}
		return abs( $a - $b ) < 1;
	}

	/** visible_html(): a rule's selector list, each joined to the rule it is nested in ("&", or a descendant). */
	private static function vis_nest( string $pre, array $outer ): array {
		$out = array();
		foreach ( self::vis_split_top( $pre, ',' ) as $sel ) {
			$sel = trim( $sel );
			if ( '' === $sel ) {
				continue;
			}
			if ( ! $outer ) {
				$out[] = $sel;
				continue;
			}
			foreach ( $outer as $o ) {
				$out[] = false !== strpos( $sel, '&' ) ? str_replace( '&', $o, $sel ) : $o . ' ' . $sel;
				if ( count( $out ) >= 50 ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/** visible_html(): one style rule's declarations added for each of its selectors that is read. */
	private static function vis_add_rule( array $sels, string $decls, bool $rough, int $order, array &$rules ): void {
		$d = array_filter( self::vis_decls( $decls ), function ( $k ) {
			return (bool) preg_match( self::VIS_PROPS, $k );
		}, ARRAY_FILTER_USE_KEY );
		if ( ! $d ) {
			return;
		}
		foreach ( $sels as $sel ) {
			$p = self::vis_selector( $sel );
			if ( null === $p ) {
				continue;
			}
			$use = ( $rough || $p['approx'] ) ? array_filter( $d, function ( $v, $k ) {
				return self::vis_hides( $k, $v[0] );
			}, ARRAY_FILTER_USE_BOTH ) : $d;
			if ( ! $use ) {
				continue;
			}
			if ( count( $rules ) >= self::VIS_MAX_RULES ) {
				self::$vis_gave_up = true;
				return;
			}
			$rules[] = array( 'sel' => $p['chain'], 'spec' => $p['spec'], 'order' => $order, 'decl' => $use );
		}
	}

	/** visible_html(): would this one declaration hide text by itself (for a rule read only roughly)? */
	private static function vis_hides( string $p, string $v ): bool {
		if ( 'visibility' === $p ) {
			return (bool) preg_match( '/^(?:hidden|collapse)\b/', $v );
		}
		if ( 'font-size' === $p ) {
			return self::vis_font_px( $v, 16.0 ) < 3.0;
		}
		if ( 'opacity' === $p || 'fill-opacity' === $p ) {
			$r = self::vis_ratio( $v );
			return null !== $r && $r < 0.2;
		}
		if ( 'color' === $p || '-webkit-text-fill-color' === $p || 'fill' === $p ) {
			$c = self::vis_color( $v, null );
			return is_array( $c ) && ( $c[3] < 0.2 || self::vis_lum( $c ) > 0.8 );
		}
		if ( in_array( $p, array( 'overflow', 'overflow-x', 'overflow-y' ), true ) ) {
			return (bool) preg_match( '/\b(?:hidden|clip)\b/', $v ); // a box of nothing with overflow hidden takes two
		}
		if ( in_array( $p, array( 'height', 'max-height', 'width', 'max-width' ), true ) ) {
			$px = self::vis_px( $v, 16.0, null );
			return null !== $px && $px <= 2.0;
		}
		if ( 'filter' === $p ) {
			$op = 1.0;
			foreach ( self::vis_funcs( $v ) as $f ) {
				$r   = 'opacity' === $f[0] ? self::vis_ratio( $f[1] ) : null;
				$op *= null === $r ? 1.0 : max( 0.0, min( 1.0, $r ) );
			}
			return $op < 0.2 || (bool) preg_match( '/alpha\(\s*opacity\s*=\s*1?\d(?:\.\d+)?\s*\)/', $v ) || self::vis_gone( array( 'filter' => $v ) );
		}
		if ( in_array( $p, array( 'background-color', 'background-image' ), true ) ) {
			return false;
		}
		return self::vis_gone( array( $p => $v ) );
	}

	/**
	 * visible_html(): a selector read into compounds, left to right, each with the combinator before it, its
	 * specificity, and whether it is read only roughly ('approx': a pseudo-class it does not read). Null when it is
	 * not a selector it reads, or one that matches nothing in a mail at rest.
	 */
	private static function vis_selector( string $sel ) {
		$s     = trim( $sel );
		$n     = strlen( $s );
		$i     = 0;
		$chain = array();
		$spec  = array( 0, 0, 0 );
		$apx   = false;
		$comb  = ' ';
		while ( $i < $n ) {
			$c = self::vis_compound_parse( $s, $i, $spec, $apx );
			if ( null === $c ) {
				return null;
			}
			$c['comb'] = $comb;
			$chain[]   = $c;
			if ( count( $chain ) > 8 ) {
				return null;
			}
			$ws = false;
			while ( $i < $n && ctype_space( $s[ $i ] ) ) {
				$i++;
				$ws = true;
			}
			if ( $i >= $n ) {
				break;
			}
			if ( '>' === $s[ $i ] || '+' === $s[ $i ] || '~' === $s[ $i ] ) {
				$comb = $s[ $i ];
				$i++;
				while ( $i < $n && ctype_space( $s[ $i ] ) ) {
					$i++;
				}
			} elseif ( $ws ) {
				$comb = ' ';
			} else {
				return null;
			}
		}
		return $chain ? array( 'chain' => $chain, 'spec' => $spec, 'approx' => $apx ) : null;
	}

	/** visible_html(): one compound selector ("div.a#b[x]:first-child") from $s at $i, or null. */
	private static function vis_compound_parse( string $s, int &$i, array &$spec, bool &$apx ) {
		$n   = strlen( $s );
		$c   = array( 'tag' => '', 'id' => '', 'ids' => array(), 'cls' => array(), 'attr' => array(), 'ps' => array() );
		$any = false;
		if ( $i < $n && '*' === $s[ $i ] ) {
			$i++;
			$any = true;
		} elseif ( $i < $n && self::vis_ident_start( $s, $i ) ) {
			$c['tag'] = strtolower( self::vis_ident( $s, $i ) );
			$spec[2]++;
			$any = true;
		}
		while ( $i < $n ) {
			$ch = $s[ $i ];
			if ( '#' === $ch ) {
				$i++;
				$v = strtolower( self::vis_ident( $s, $i ) );
				if ( '' === $v ) {
					return null;
				}
				$c['ids'][] = $v;
				$c['id']    = $v;
				$spec[0]++;
			} elseif ( '.' === $ch ) {
				$i++;
				$v = strtolower( self::vis_ident( $s, $i ) );
				if ( '' === $v ) {
					return null;
				}
				$c['cls'][] = $v;
				$spec[1]++;
			} elseif ( '[' === $ch ) {
				$a = self::vis_attr_parse( $s, $i );
				if ( null === $a ) {
					return null;
				}
				$c['attr'][] = $a;
				$spec[1]++;
			} elseif ( ':' === $ch ) {
				$p = self::vis_pseudo_parse( $s, $i, $spec, $apx );
				if ( null === $p ) {
					return null;
				}
				if ( false !== $p ) {
					$c['ps'][] = $p;
				}
			} else {
				break;
			}
			$any = true;
		}
		return $any ? $c : null;
	}

	private static function vis_ident_start( string $s, int $i ): bool {
		$c = $s[ $i ];
		return ctype_alpha( $c ) || '_' === $c || '\\' === $c || ord( $c ) >= 0x80 || ( '-' === $c && $i + 1 < strlen( $s ) && ( ctype_alpha( $s[ $i + 1 ] ) || '-' === $s[ $i + 1 ] || '_' === $s[ $i + 1 ] || '\\' === $s[ $i + 1 ] ) );
	}

	/** visible_html(): a CSS name from $s at $i, its escapes read ("md\:hidden" is the class "md:hidden"). */
	private static function vis_ident( string $s, int &$i ): string {
		$n   = strlen( $s );
		$out = '';
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( '\\' === $c && $i + 1 < $n ) {
				if ( preg_match( '/\G\\\\([0-9a-f]{1,6})\s?/i', $s, $m, 0, $i ) ) {
					$cp   = hexdec( $m[1] );
					$out .= ( $cp > 0 && $cp < 0x110000 && function_exists( 'mb_chr' ) ) ? (string) mb_chr( (int) $cp, 'UTF-8' ) : '';
					$i   += strlen( $m[0] );
				} else {
					$out .= $s[ $i + 1 ];
					$i   += 2;
				}
				continue;
			}
			if ( ctype_alnum( $c ) || '-' === $c || '_' === $c || ord( $c ) >= 0x80 ) {
				$out .= $c;
				$i++;
				continue;
			}
			break;
		}
		return $out;
	}

	/** visible_html(): "[name]", "[name op value i]" from $s at $i as [ name, op, value ], or null. */
	private static function vis_attr_parse( string $s, int &$i ) {
		$n = strlen( $s );
		$i++;
		while ( $i < $n && ctype_space( $s[ $i ] ) ) {
			$i++;
		}
		$name = strtolower( self::vis_ident( $s, $i ) );
		while ( $i < $n && ctype_space( $s[ $i ] ) ) {
			$i++;
		}
		if ( '' === $name || $i >= $n ) {
			return null;
		}
		if ( ']' === $s[ $i ] ) {
			$i++;
			return array( $name, '', null );
		}
		if ( '=' === $s[ $i ] ) {
			$op = '=';
			$i++;
		} elseif ( $i + 1 < $n && '=' === $s[ $i + 1 ] && false !== strpos( '~|^$*', $s[ $i ] ) ) {
			$op = $s[ $i ] . '=';
			$i += 2;
		} else {
			return null;
		}
		while ( $i < $n && ctype_space( $s[ $i ] ) ) {
			$i++;
		}
		if ( $i < $n && ( '"' === $s[ $i ] || "'" === $s[ $i ] ) ) {
			$q = $s[ $i ];
			$k = $i + 1;
			$v = '';
			while ( $k < $n && $s[ $k ] !== $q ) {
				if ( '\\' === $s[ $k ] && $k + 1 < $n ) {
					$v .= $s[ $k + 1 ];
					$k += 2;
					continue;
				}
				$v .= $s[ $k ];
				$k++;
			}
			$i = $k + 1;
		} else {
			$v = self::vis_ident( $s, $i );
		}
		while ( $i < $n && ctype_space( $s[ $i ] ) ) {
			$i++;
		}
		if ( $i < $n && ( 'i' === strtolower( $s[ $i ] ) || 's' === strtolower( $s[ $i ] ) ) ) {
			$i++;
			while ( $i < $n && ctype_space( $s[ $i ] ) ) {
				$i++;
			}
		}
		if ( $i >= $n || ']' !== $s[ $i ] ) {
			return null;
		}
		$i++;
		return array( $name, $op, strtolower( $v ) );
	}

	/**
	 * visible_html(): a pseudo-class from $s at $i: a check [ name, argument ], false (nothing to check), or null (not
	 * a selector it reads, or one that matches nothing in a mail at rest).
	 */
	private static function vis_pseudo_parse( string $s, int &$i, array &$spec, bool &$apx ) {
		$n = strlen( $s );
		$i++;
		$two = false;
		if ( $i < $n && ':' === $s[ $i ] ) {
			$i++;
			$two = true;
		}
		$name = strtolower( self::vis_ident( $s, $i ) );
		if ( '' === $name ) {
			return null;
		}
		$arg = null;
		if ( $i < $n && '(' === $s[ $i ] ) {
			$depth = 0;
			$k     = $i;
			for ( ; $k < $n; $k++ ) {
				if ( '(' === $s[ $k ] ) {
					$depth++;
				} elseif ( ')' === $s[ $k ] && 0 === --$depth ) {
					break;
				}
			}
			if ( $k >= $n ) {
				return null;
			}
			$arg = substr( $s, $i + 1, $k - $i - 1 );
			$i   = $k + 1;
		}
		if ( in_array( $name, array( 'first-line', 'first-letter' ), true ) ) {
			$spec[2]++;
			$apx = true;
			return false; // the element's own first line or letter: read as the element, for what hides
		}
		if ( $two || in_array( $name, array( 'before', 'after' ), true ) ) {
			return null; // ::before, ::marker …: drawn text not in the email's own words
		}
		switch ( $name ) {
			case 'first-child':
			case 'last-child':
			case 'only-child':
			case 'first-of-type':
			case 'last-of-type':
			case 'only-of-type':
			case 'empty':
			case 'root':
				$spec[1]++;
				return array( $name, null );
			case 'link':
			case 'any-link':
				$spec[1]++;
				return array( 'link', null );
			case 'nth-child':
			case 'nth-last-child':
			case 'nth-of-type':
			case 'nth-last-of-type':
				$spec[1]++;
				$ab = self::vis_nth( (string) $arg );
				if ( null === $ab ) {
					if ( preg_match( '/\bof\b/i', (string) $arg ) ) {
						$apx = true;
						return false;
					}
					return null;
				}
				return array( $name, $ab );
			case 'not':
			case 'is':
			case 'where':
			case 'matches':
			case '-webkit-any':
			case '-moz-any':
				if ( 'where' !== $name ) {
					$spec[1]++;
				}
				$list = array();
				foreach ( self::vis_split_top( (string) $arg, ',' ) as $part ) {
					$part = trim( $part );
					$j    = 0;
					$sp   = array( 0, 0, 0 );
					$ap   = false;
					$cc   = '' === $part ? null : self::vis_compound_parse( $part, $j, $sp, $ap );
					if ( null === $cc || $j < strlen( $part ) || $ap ) {
						$apx = true; // a combinator or something not read inside: roughly, for what hides
						return false;
					}
					$list[] = $cc;
				}
				return array( 'not' === $name ? 'not' : 'is', $list );
			case 'defined':
			case 'read-only':
				return false; // every element of a mail is
			case 'has':
			case 'lang':
			case 'dir':
			case 'scope':
			case 'host':
			case 'host-context':
			case 'nth-col':
			case 'nth-last-col':
			case 'state':
			case 'local-link':
			case 'blank':
				$spec[1]++;
				$apx = true;
				return false;
		}
		return null; // :hover, :focus, :checked … never so in a mail at rest; an unknown one: browsers drop the rule
	}

	/** visible_html(): "2n+1", "odd", "even", "-n+3", "5" as [ a, b ], or null. */
	private static function vis_nth( string $a ) {
		$a = strtolower( str_replace( ' ', '', trim( $a ) ) );
		if ( 'odd' === $a ) {
			return array( 2, 1 );
		}
		if ( 'even' === $a ) {
			return array( 2, 0 );
		}
		if ( preg_match( '/^[+-]?\d+$/', $a ) ) {
			return array( 0, (int) $a );
		}
		if ( preg_match( '/^([+-]?\d*)n([+-]\d+)?$/', $a, $m ) ) {
			$k = '' === $m[1] || '+' === $m[1] ? 1 : ( '-' === $m[1] ? -1 : (int) $m[1] );
			return array( $k, (int) ( $m[2] ?? 0 ) );
		}
		return null;
	}

	/** visible_html(): does $el, with what is around it, match $chain up to compound $i? Memoized per element. */
	private static function vis_matches( DOMElement $el, array $chain, int $i, array &$ctx, int $ri ): bool {
		if ( ! self::vis_compound( $el, $chain[ $i ], $ctx ) ) {
			return false;
		}
		if ( 0 === $i ) {
			return true;
		}
		$k = $ri . '.' . $i . '.' . spl_object_id( $el );
		if ( isset( $ctx['memo'][ $k ] ) ) {
			return $ctx['memo'][ $k ];
		}
		$ctx['keep'][] = $el;
		if ( ++$ctx['steps'] > self::VIS_MAX_STEPS ) {
			self::$vis_gave_up = true;
			return $ctx['memo'][ $k ] = false;
		}
		switch ( $chain[ $i ]['comb'] ) {
			case '>':
				$p  = $el->parentNode;
				$ok = $p instanceof DOMElement && self::vis_matches( $p, $chain, $i - 1, $ctx, $ri );
				break;
			case '+':
				$p  = self::vis_prev( $el );
				$ok = null !== $p && self::vis_matches( $p, $chain, $i - 1, $ctx, $ri );
				break;
			case '~':
				$ok = self::vis_any( $el, $chain, $i - 1, $ctx, $ri, false );
				break;
			default:
				$ok = self::vis_any( $el, $chain, $i - 1, $ctx, $ri, true );
		}
		return $ctx['memo'][ $k ] = $ok;
	}

	/** visible_html(): does an ancestor ($up) or an earlier sibling of $el match $chain up to $i? Memoized per element. */
	private static function vis_any( DOMElement $el, array $chain, int $i, array &$ctx, int $ri, bool $up ): bool {
		$p = $up ? $el->parentNode : self::vis_prev( $el );
		if ( ! $p instanceof DOMElement ) {
			return false;
		}
		$k = ( $up ? 'u' : 's' ) . $ri . '.' . $i . '.' . spl_object_id( $p );
		if ( isset( $ctx['memo'][ $k ] ) ) {
			return $ctx['memo'][ $k ];
		}
		$ctx['keep'][] = $p;
		if ( ++$ctx['steps'] > self::VIS_MAX_STEPS ) {
			self::$vis_gave_up = true;
			return $ctx['memo'][ $k ] = false;
		}
		return $ctx['memo'][ $k ] = ( self::vis_matches( $p, $chain, $i, $ctx, $ri ) || self::vis_any( $p, $chain, $i, $ctx, $ri, $up ) );
	}

	/** visible_html(): the element before $el among its parent's elements, or null. */
	private static function vis_prev( DOMElement $el ) {
		for ( $p = $el->previousSibling; null !== $p; $p = $p->previousSibling ) {
			if ( $p instanceof DOMElement ) {
				return $p;
			}
		}
		return null;
	}

	/** visible_html(): does $el itself match one compound (tag, ids, classes, attributes, pseudo-classes)? */
	private static function vis_compound( DOMElement $el, array $c, array &$ctx ): bool {
		if ( '' !== $c['tag'] && strtolower( $el->nodeName ) !== $c['tag'] ) {
			return false;
		}
		foreach ( $c['ids'] as $id ) {
			if ( strtolower( (string) $el->getAttribute( 'id' ) ) !== $id ) {
				return false;
			}
		}
		if ( $c['cls'] ) {
			$have = self::vis_classes( $el, $ctx );
			foreach ( $c['cls'] as $w ) {
				if ( ! isset( $have[ $w ] ) ) {
					return false;
				}
			}
		}
		foreach ( $c['attr'] as $a ) {
			if ( ! $el->hasAttribute( $a[0] ) ) {
				return false;
			}
			if ( '' === $a[1] ) {
				continue;
			}
			$have = strtolower( (string) $el->getAttribute( $a[0] ) );
			$want = (string) $a[2];
			switch ( $a[1] ) {
				case '=':
					$ok = $have === $want;
					break;
				case '~=':
					$ok = '' !== $want && in_array( $want, preg_split( '/\s+/', trim( $have ) ), true );
					break;
				case '|=':
					$ok = $have === $want || 0 === strpos( $have, $want . '-' );
					break;
				case '^=':
					$ok = '' !== $want && 0 === strpos( $have, $want );
					break;
				case '$=':
					$ok = '' !== $want && substr( $have, -strlen( $want ) ) === $want;
					break;
				default:
					$ok = '' !== $want && false !== strpos( $have, $want );
			}
			if ( ! $ok ) {
				return false;
			}
		}
		foreach ( $c['ps'] as $p ) {
			if ( ! self::vis_pseudo_ok( $el, $p, $ctx ) ) {
				return false;
			}
		}
		return true;
	}

	/** visible_html(): $el's classes, lower case, as a set (kept for the call). */
	private static function vis_classes( DOMElement $el, array &$ctx ): array {
		$k = spl_object_id( $el );
		if ( ! isset( $ctx['cls'][ $k ] ) ) {
			$ctx['keep'][]    = $el;
			$raw              = trim( strtolower( (string) $el->getAttribute( 'class' ) ) );
			$ctx['cls'][ $k ] = '' === $raw ? array() : array_flip( preg_split( '/\s+/', $raw ) );
		}
		return $ctx['cls'][ $k ];
	}

	private static function vis_pseudo_ok( DOMElement $el, array $p, array &$ctx ): bool {
		switch ( $p[0] ) {
			case 'is':
				foreach ( $p[1] as $c ) {
					if ( self::vis_compound( $el, $c, $ctx ) ) {
						return true;
					}
				}
				return false;
			case 'not':
				foreach ( $p[1] as $c ) {
					if ( self::vis_compound( $el, $c, $ctx ) ) {
						return false;
					}
				}
				return true;
			case 'root':
				return null !== $el->ownerDocument && null !== $el->ownerDocument->documentElement && $el->isSameNode( $el->ownerDocument->documentElement );
			case 'empty':
				foreach ( $el->childNodes as $c ) {
					if ( $c instanceof DOMElement || ( $c instanceof DOMText && '' !== $c->nodeValue ) ) {
						return false;
					}
				}
				return true;
			case 'link':
				return in_array( strtolower( $el->nodeName ), array( 'a', 'area' ), true ) && $el->hasAttribute( 'href' );
		}
		$q = self::vis_pos( $el, $ctx ); // [ index, count, index of its type, count of its type ]
		switch ( $p[0] ) {
			case 'first-child':
				return 1 === $q[0];
			case 'last-child':
				return $q[0] === $q[1];
			case 'only-child':
				return 1 === $q[1];
			case 'first-of-type':
				return 1 === $q[2];
			case 'last-of-type':
				return $q[2] === $q[3];
			case 'only-of-type':
				return 1 === $q[3];
			case 'nth-child':
				return self::vis_nth_ok( $q[0], $p[1] );
			case 'nth-last-child':
				return self::vis_nth_ok( $q[1] - $q[0] + 1, $p[1] );
			case 'nth-of-type':
				return self::vis_nth_ok( $q[2], $p[1] );
			case 'nth-last-of-type':
				return self::vis_nth_ok( $q[3] - $q[2] + 1, $p[1] );
		}
		return true;
	}

	private static function vis_nth_ok( int $pos, array $ab ): bool {
		list( $a, $b ) = $ab;
		if ( 0 === $a ) {
			return $pos === $b;
		}
		$k = ( $pos - $b ) / $a;
		return $k >= 0 && abs( $k - round( $k ) ) < 1e-9;
	}

	/** visible_html(): where $el is among its parent's elements: [ index, count, index of its type, count of its type ]. */
	private static function vis_pos( DOMElement $el, array &$ctx ): array {
		$k = spl_object_id( $el );
		if ( isset( $ctx['pos'][ $k ] ) ) {
			return $ctx['pos'][ $k ];
		}
		$par  = $el->parentNode;
		$sibs = array();
		if ( $par ) {
			foreach ( $par->childNodes as $c ) {
				if ( $c instanceof DOMElement ) {
					$sibs[] = $c;
				}
			}
		} else {
			$sibs[] = $el;
		}
		$types = array();
		foreach ( $sibs as $c ) {
			$t           = strtolower( $c->nodeName );
			$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
		}
		$seen = array();
		foreach ( $sibs as $ix => $c ) {
			$t                                       = strtolower( $c->nodeName );
			$seen[ $t ]                              = ( $seen[ $t ] ?? 0 ) + 1;
			$ctx['keep'][]                           = $c;
			$ctx['pos'][ spl_object_id( $c ) ]       = array( $ix + 1, count( $sibs ), $seen[ $t ], $types[ $t ] );
			if ( ++$ctx['steps'] > self::VIS_MAX_STEPS ) {
				self::$vis_gave_up = true;
				break;
			}
		}
		return $ctx['pos'][ $k ] ?? array( 1, 1, 1, 1 );
	}

	public static function body_to_text( string $body, string $format ): string {
		if ( 'html' === strtolower( $format ) ) {
			$body = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', ' ', $body );
			$body = preg_replace( '#<br\s*/?>#i', "\n", (string) $body );
			$body = preg_replace( '#</(p|div|tr|li|h[1-6])>#i', "\n", (string) $body );
			$body = strip_tags( (string) $body );
			$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$body = preg_replace( "/[ \t]+/", ' ', (string) $body );
		$body = preg_replace( "/\n{3,}/", "\n\n", (string) $body );
		return trim( (string) $body );
	}

	// ── access-log reads (accountability: admin viewer + dot-plot) ─────────
	//
	// The audit trail is WRITTEN by log() below and, until now, never read back in code.
	// These readers surface it two ways — a manage_options admin table (access_log_recent)
	// and grouped day-counts for the Dot Plot sources (access_dotplot_counts). Both read
	// ONLY the log (metadata: who/whose/when/how-many + a hashed query), never mail content.

	/**
	 * Categorise one audit row into a human event label from (path, decision, reason).
	 * The cross-user (admin_search) rows are the accountability-relevant ones; owner_* rows
	 * are the person's own mailbox. Pure — used by the viewer AND the dot-plot category map.
	 */
	public static function category_label( string $path, string $decision, string $reason ): string {
		if ( 'admin_search' === $path ) {
			if ( 'deny' === $decision ) {
				return ( 0 === strpos( $reason, 'gate:' ) ) ? 'Sharing change denied' : 'Access denied';
			}
			if ( 0 === strpos( $reason, 'open:' ) ) { return 'Viewed a message'; }
			if ( 'gate:on' === $reason ) { return 'Opened sharing'; }
			if ( 'gate:off' === $reason ) { return 'Closed sharing'; }
			if ( '' === $reason ) { return 'Searched mailbox'; }
			return 'Reviewed mailbox';
		}
		$own = array(
			'owner_chat'      => 'Asked the assistant',
			'owner_search'    => 'Searched own mail',
			'owner_told'      => 'Recalled what they sent',
			'owner_followups' => 'Checked follow-ups',
			'owner_aggregate' => 'Computed from own mail',
		);
		$lbl = $own[ $path ] ?? 'Own mailbox';
		return ( 'deny' === $decision ) ? ( $lbl . ' (denied)' ) : $lbl;
	}

	/** A user's display label for the log/plot, or a stable placeholder. */
	private static function user_label_public( int $uid ): string {
		if ( $uid <= 0 ) { return '–'; }
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : ( 'user #' . $uid );
	}

	/** UTC 'Y-m-d H:i:s' → site-local stamp for the admin table. */
	private static function local_stamp( string $sql_utc ): string {
		if ( '' === $sql_utc ) { return ''; }
		$ts = strtotime( $sql_utc . ' UTC' );
		if ( ! $ts ) {
			return $sql_utc;
		}
		$df = trim( (string) get_option( 'date_format', '' ) );
		$tf = trim( (string) get_option( 'time_format', '' ) );
		return wp_date( ( '' !== $df ? $df : 'M j, Y' ) . ' ' . ( '' !== $tf ? $tf : 'g:ia' ), $ts );
	}

	/**
	 * Recent audit rows for the manage_options admin viewer. Names resolved, event categorised,
	 * time in the site's zone. `cross_only` narrows to cross-user (admin_search, actor≠subject) — the
	 * sensitive events. Content-free by construction (the log never holds mail).
	 *
	 * @return array { ok:bool, rows:array }
	 */
	public static function access_log_recent( int $actor, array $args = array() ): array {
		if ( $actor <= 0 || ! function_exists( 'user_can' ) || ! user_can( $actor, 'manage_options' ) ) {
			return array( 'ok' => false, 'rows' => array() );
		}
		global $wpdb;
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$where = '1 = 1';
		if ( ! empty( $args['cross_only'] ) ) {
			$where = "path = 'admin_search' AND actor_user_id <> subject_owner_user_id";
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ts, actor_user_id, subject_owner_user_id, path, decision, reason, result_count
			 FROM ' . self::t_log() . " WHERE $where ORDER BY ts DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$limit
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$is_cross = ( 'admin_search' === $r['path'] && (int) $r['actor_user_id'] !== (int) $r['subject_owner_user_id'] );
			$out[]    = array(
				'ts'       => self::local_stamp( (string) $r['ts'] ),
				'actor'    => self::user_label_public( (int) $r['actor_user_id'] ),
				'subject'  => self::user_label_public( (int) $r['subject_owner_user_id'] ),
				'category' => self::category_label( (string) $r['path'], (string) $r['decision'], (string) $r['reason'] ),
				'decision' => (string) $r['decision'],
				'count'    => (int) $r['result_count'],
				'cross'    => $is_cross,
			);
		}
		return array( 'ok' => true, 'rows' => $out );
	}

	/** The fixed WHERE predicate for a dot-plot access category (whitelisted — no caller input in SQL). */
	private static function access_category_sql( string $category ): string {
		switch ( $category ) {
			case 'viewed_other':   return "path = 'admin_search' AND decision = 'allow' AND reason LIKE 'open:%%'";
			case 'searched_other': return "path = 'admin_search' AND decision = 'allow' AND reason = ''";
			case 'denied_other':   return "path = 'admin_search' AND decision = 'deny' AND reason NOT LIKE 'gate:%%'";
			case 'gate_changed':   return "path = 'admin_search' AND decision = 'allow' AND reason LIKE 'gate:%%'";
			case 'read_own':       return "decision = 'allow' AND path IN ('owner_chat','owner_search','owner_told','owner_followups','owner_aggregate')";
		}
		return '';
	}

	/**
	 * Grouped day-counts of one access CATEGORY, keyed by actor or subject, for the Dot Plot
	 * source callback. Returns raw {rk, d, n} rows (the callback maps them to the grid shape). The
	 * WHERE is built from a whitelisted category (no injection) + int-cast ids; row-scoping to the
	 * viewer's tier is the grid's job (it passes only permitted row_keys). Metadata only.
	 *
	 * @param string $keycol 'actor_user_id' | 'subject_owner_user_id'
	 * @return array<int,array{rk:string,d:string,n:int}>
	 */
	public static function access_dotplot_counts( string $keycol, string $category, array $user_ids, string $from_utc, string $to_utc, int $offset_seconds ): array {
		global $wpdb;
		if ( ! in_array( $keycol, array( 'actor_user_id', 'subject_owner_user_id' ), true ) || empty( $user_ids ) ) {
			return array();
		}
		$pred = self::access_category_sql( $category );
		if ( '' === $pred ) {
			return array();
		}
		$ids    = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$daycol = ( 0 !== $offset_seconds ) ? 'DATE(ts + INTERVAL %d SECOND)' : 'DATE(ts)';
		$params = array();
		if ( 0 !== $offset_seconds ) { $params[] = $offset_seconds; }
		$params[] = $from_utc;
		$params[] = $to_utc;
		$params   = array_merge( $params, $ids );
		$sql = "SELECT `$keycol` AS rk, $daycol AS d, COUNT(*) AS n
			FROM " . self::t_log() . "
			WHERE ts >= %s AND ts < %s AND `$keycol` IN ($in) AND ($pred)
			GROUP BY rk, d"; // phpcs:ignore WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	// ── audit ───────────────────────────────────────────────────────

	/**
	 * - "Look in my email" from Stock: the ACTOR'S OWN indexed mail, received in the last
	 * $days (1-60), from a sender on Stock's supplier list, handed to Stock one message at a time through
	 * ZIB_Supplier_Feed::emit() -- which passes the order number and lines only (never the body or the subject).
	 * Owner-forced, kiosk-denied, capped, audited (path owner_supplier_scan). The same message seen in two folders is
	 * sent once (the RFC Message-ID); Stock de-duplicates again on its side.
	 *
	 * @return array { ok, scanned, created, already, skipped, reason? }
	 */
	public static function owner_supplier_scan( int $actor, array $senders, int $days = 30 ): array {
		$out = array( 'ok' => false, 'scanned' => 0, 'created' => 0, 'already' => 0, 'skipped' => 0, 'why' => array() );
		if ( ! self::gate_owner( $actor ) ) {
			self::log( $actor, $actor, 'owner_supplier_scan', 'deny', 'not permitted', '', 0 );
			$out['reason'] = 'not-permitted';
			return $out;
		}
		if ( ! $senders ) {
			$out['reason'] = 'no-suppliers';
			return $out;
		}
		global $wpdb;
		// Say so when there is nothing to look in, rather than "nothing new".
		$modes = (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT index_mode FROM ' . $wpdb->prefix . 'zib_accounts WHERE owner_user_id = %d AND status <> %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$actor, 'disabled'
		) );
		if ( ! $modes ) {
			$out['reason'] = 'no-mailbox';
			return $out;
		}
		if ( ! array_intersect( $modes, array( 'external', 'all' ) ) ) {
			$out['reason'] = 'mode-excludes-external'; // supplier mail is external; this mailbox does not keep it
			return $out;
		}
		$days  = max( 1, min( 60, $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$conds = array();
		$args  = array( $actor, 'in', $since );
		foreach ( $senders as $sn ) {
			if ( '@' === $sn[0] ) {
				$conds[] = 'm.from_addr LIKE %s OR m.from_addr LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $sn );
				$args[]  = '%.' . $wpdb->esc_like( substr( $sn, 1 ) );
			} else {
				$conds[] = 'm.from_addr = %s';
				$args[]  = $sn;
			}
		}
		$args[] = ZIB_Supplier_Feed::SCAN_MAX;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names are fixed; each sender condition holds placeholders only
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT m.id, m.account_id, MD5(m.ms_message_id) AS ms_message_hash, m.ms_internet_message_id, m.folder, m.folder_name, m.class, m.from_addr, m.received_at, m.subject, m.body_enc, m.body_format, f.well_known
			 FROM ' . self::t_msg() . ' m LEFT JOIN ' . $wpdb->prefix . 'zib_folders f ON f.account_id = m.account_id AND f.folder_hash = m.folder_hash AND m.folder_hash <> \'\'
			 WHERE m.owner_user_id = %d AND m.direction = %s AND m.received_at >= %s AND (' . implode( ' OR ', $conds ) . ')
			 ORDER BY m.received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value is a placeholder
			$args
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$seen = array();
		foreach ( (array) $rows as $r ) {
			if ( ! ZIB_Supplier_Feed::sender_matches( (string) $r->from_addr, $senders ) ) {
				continue; // the LIKE is a pre-filter; the exact rule decides
			}
			if ( 'internal' === (string) $r->class || ZIB_Supplier_Feed::skip_folder( (string) $r->folder, (string) $r->folder_name, (string) ( $r->well_known ?? '' ) ) ) {
				continue; // never Junk / Deleted Items (a spoofed "supplier" lands there)
			}
			$mid = strtolower( trim( (string) $r->ms_internet_message_id ) );
			if ( '' !== $mid ) {
				if ( isset( $seen[ $mid ] ) ) {
					continue;
				}
				$seen[ $mid ] = true;
			}
			$out['scanned']++;
			$clean = class_exists( 'ZIB_Enrich' )
				? ZIB_Enrich::clean_body( ZIB_Crypto::decrypt( (string) $r->body_enc ), (string) $r->body_format )
				: '';
			$res = ZIB_Supplier_Feed::emit(
				$actor,
				ZIB_Supplier_Feed::message_key( (int) $r->account_id, (string) $r->ms_message_hash, (string) $r->ms_internet_message_id ),
				array( 'from_addr' => (string) $r->from_addr, 'received_at' => (string) $r->received_at, 'subject' => (string) $r->subject ),
				$clean,
				'scan'
			);
			$what = is_array( $res ) ? (string) ( $res['result'] ?? '' ) : '';
			if ( 'created' === $what ) {
				$out['created']++;
			} elseif ( 'duplicate' === $what ) {
				$out['already']++;
			} else {
				$out['skipped']++;
				// Why, per reason, so the toast does not give one reason for every skip.
				$w = is_array( $res ) ? preg_replace( '/[^a-z\-]/', '', strtolower( (string) ( $res['why'] ?? '' ) ) ) : '';
				$w = '' !== $w ? substr( $w, 0, 40 ) : 'other';
				$out['why'][ $w ] = ( $out['why'][ $w ] ?? 0 ) + 1;
			}
		}
		$out['ok'] = true;
		self::log( $actor, $actor, 'owner_supplier_scan', 'allow', 'days=' . $days . ';created=' . $out['created'], '', $out['scanned'] );
		return $out;
	}

	/**
	 * Door 1 (ingest) is audited too: one row per supplier message handed to Stock, with
	 * Stock's answer. The owner is both actor and subject (the ingest runs for the message's own owner).
	 */
	public static function audit_supplier_feed( int $owner, string $result ): void {
		$result = preg_replace( '/[^a-z\-]/', '', strtolower( $result ) );
		self::log( $owner, $owner, 'supplier_feed_ingest', 'allow', 'result=' . ( '' !== $result ? $result : 'none' ), '', 1 );
	}

	private static function log( int $actor, int $subject, string $path, string $decision, string $reason, string $query_hash, int $count ): void {
		global $wpdb;
		$wpdb->insert( self::t_log(), array(
			'actor_user_id'         => $actor,
			'subject_owner_user_id' => $subject,
			'path'                  => $path,
			'decision'              => $decision,
			'reason'                => substr( $reason, 0, 255 ),
			'query_hash'            => $query_hash,
			'result_count'          => $count,
			'ts'                    => gmdate( 'Y-m-d H:i:s' ),
		) );
	}
}
