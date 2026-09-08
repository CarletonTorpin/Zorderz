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
 *   - KIOSK DENIED: the shared device never reads mail (INV-10).
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

	/** owner_chat (P3) — how many messages a single Brain-Bot turn may surface, and
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

	/** owner_aggregate (P6c) — how many source messages a computed answer cites for grounding. */
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
	 * (D9). Guarded: with the identity plugin absent, only manage_options qualifies. Pure
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
	 * empty set, so the honest empty (INV-12) is preserved. Single bounded substring, esc_like'd; a
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

	private static function sent_to_recipient( int $actor, string $query, int $limit, string $cols, array $aliases = array() ): array {
		global $wpdb;
		list( $rsql, $rargs ) = self::recipient_match( $actor, $query, $aliases );
		if ( '' === $rsql ) {
			$rsql  = ' AND parties_text LIKE %s';
			$rargs = array( '%' . $wpdb->esc_like( trim( $query ) ) . '%' );
		}
		$args = array_merge( array( $actor, 'out' ), $rargs, array( $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . $cols . ' FROM ' . self::t_msg() . '
			 WHERE owner_user_id = %d AND direction = %s' . $rsql . '
			 ORDER BY received_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- recipient-scoped: the addressee lives in parties_text, never the body
			$args
		) );
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
	 * @return string  e.g. "Casey Lee <casey@acme.com>", with " +2 more" appended for a
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
	 * 'from' (the sender via fmt_addr) where the sent card carries 'to'.
	 *
	 * @param string $sender  sender name/addr; '' matches anyone (a pure "what did I receive today").
	 * @param string $since,$until  YYYY-MM-DD site-local dates; '' = unbounded.
	 * @return array { ok, results:[ { id, subject, received_at, from, composed, has_attachments } ], sender, since, until }
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
	 * @return array { ok:bool, message?:array }
	 */
	public static function owner_get_message( int $actor, int $message_id ): array {
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

		return array(
			'ok'      => true,
			'message' => array(
				'id'                => (int) $row->id,
				'subject'           => (string) $row->subject,
				'from'              => self::fmt_addr( (string) $row->from_name, (string) $row->from_addr ),
				'to'                => $to,
				'cc'                => $cc,
				'received_at'       => $row->received_at ? (string) $row->received_at : '',
				'class'             => (string) $row->class,
				'folder'            => (string) $row->folder,
				'has_attachments'   => (int) $row->has_attachments,
				'body_text'         => self::body_to_text( $body, (string) $row->body_format ),
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

	// ── Phase 2 — owner SEND (INV-SEND) ────────────────────────────────
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
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$html = nl2br( esc_html( $text ), false ); // <br>, not <br />
		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;">' . $html . '</div>';
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
		// ZIB_Ingest::allfolder_enabled() here once the all-folder sync (immutable ids) is ported.
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

	// ── cross-user resolution (P4b — Brain-Bot "search a colleague's mail") ──

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

	// ── owner_chat (P3 — Brain-Bot owner path) ──────────────────────

	/**
	 * The owner asks the assistant about their OWN indexed mail. Egress path #1.
	 *
	 * This is the ONLY method that hands mail *content* to the LLM, and it is
	 * fenced on every axis:
	 *   - SUBJECT FORCED == ACTOR. owner_user_id = %d = the real caller. There is
	 *     no parameter by which another user's mailbox could be named — a bot (or a
	 *     crafted marker) literally cannot express "search someone else's mail".
	 *   - KIOSK DENIED. The shared device never reaches this path (INV-10).
	 *   - BOUNDED. At most MAX_CHAT_HITS messages, each excerpt capped to
	 *     CHAT_EXCERPT_CH chars — a small, deliberate slice, never a mailbox dump.
	 *   - SAFE TEXT. Bodies are decrypted then reduced to plain text (no email HTML,
	 *     script, or style survives) before the model ever sees them.
	 *   - AUDITED. Allow or deny, the call is logged; the query is hashed, not kept.
	 *
	 * Returns STRUCTURED data. The spotlighting fence that wraps it for the model,
	 * and the neutralising of any fence delimiters an attacker mailed in, live in
	 * ZIB_TSA_Bridge — the one engine-facing seam — so this reader stays pure DB.
	 *
	 * @return array { ok:bool, reason?:string, results:array[] }
	 */
	public static function owner_chat( int $actor, string $query, int $limit = self::MAX_CHAT_HITS, string $mode = 'list', string $direction = 'any' ): array {
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
			// MENTIONS Alex while sent to someone else. Empty stays empty (INV-12) — we do NOT broaden
			// to the body-matching FULLTEXT / fuzzy / LIKE tiers below.
			$recipient_scoped = true;
			$rows = self::sent_to_recipient( $actor, $query, $limit, $cols );
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
		// returns nothing, so the honest empty (INV-12) is preserved.
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
		self::log( $actor, $actor, 'owner_chat', 'allow', $audit, substr( sha1( $query ), 0, 16 ), count( $out ) );
		$ret = array( 'ok' => true, 'results' => $out, 'mode' => $mode, 'direction' => $direction );
		if ( '' !== $sender['label'] ) {
			$ret['sender'] = $sender['label'];
		}
		if ( $fuzzy_used ) {
			$ret['fuzzy'] = true;
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
				// internal / staff person is KEPT — the owner asked to see partners + internal folks
				// in "who haven't I answered" and judge for themselves. Automated addresses still go.
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

	// ── owner_aggregate (P6c — COMPUTE a mailbox answer) ─────────────

	/**
	 * COMPUTE a mailbox answer over the P6 enrichment extracts — "how many units of a given product
	 * have I ordered", "how much have I spent with <vendor>", "how many orders from X".
	 *
	 * BODY-FREE and OWNER-FORCED: it aggregates ONLY the structured wp_zib_extracts derived at
	 * ingest (never a body), and every row is scoped `e.owner_user_id = <actor>` — cross-user is
	 * unexpressible, exactly like owner_chat / owner_followups. Returns the computed value AND the
	 * source messages, so the render can GROUND the number in specific mail (BEST-PRACTICES §4).
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
			// way — the live "pressing email" noise (a mapping service, WP Engine, Google, Ricoh, Thrive).
			|| preg_match( '/\bplugins?\s+(?:was|were)\s+updated\b/', $s )                               // WP Engine Smart Plugin Manager
			|| preg_match( '/\bleft (?:a|another) review\b/', $s )                                       // Google Business Profile review notice
			|| preg_match( '/\byour receipt\b/', $s )                                                    // receipts
			|| preg_match( '/\bupload report\b/', $s )                                                   // a mapping service's upload report
			|| preg_match( '/\bperformance report is in\b/', $s )                                        // Google Analytics report
			|| preg_match( '/\bshipment confirmation\b/', $s )                                           // shipping confirmation
			|| preg_match( '/^welcome to\b/', $s )                                                       // onboarding / early-access
			|| preg_match( '/\bworking for you\??\s*$/', $s )                                            // "how is the new X working for you?"
			// One-way LOGISTICS notices — a delivered/shipped/tracking FYI is not a reply you owe (live
			// 1100: "A shipment from order #4196 has been delivered" from a vendor's sales@ address
			// ranked #1 "pressing"). Kept TIGHT so a real complaint ("wrong item delivered", "screen
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
	 * v0.9.12 Convert a SITE-LOCAL calendar date (YYYY-MM-DD) to the UTC datetime for that day's
	 * START (00:00:00) or END (23:59:59) in the site's timezone. received_at is stored in UTC, but a
	 * user's "today" / "this week" means their LOCAL day — so a Pacific day boundary must be expressed
	 * as the matching UTC instant before it is compared. When the site timezone IS UTC this is a no-op,
	 * so the filter is unchanged until a real zone (e.g. America/Los_Angeles) is set. Fail-soft: on a
	 * malformed date or any error, returns the plain literal (old behaviour).
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
		// phantom people — "Morgan Reyes <f@x>" + "<f@x>" → "morgan reyes" and "f x com" —
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
	 * strips a trailing '>' from EVERY address, so "Morgan <morgan@x.com>" rendered as
	 * "Morgan <morgan@x.com". This builds the string by cases: both parts present →
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
	 * whitespace collapsed. "Morgan Reyes!" and "morgan  reyes" both → "morgan reyes", so
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

	// ── access-log reads (P4c — accountability: admin viewer + dot-plot) ────
	//
	// The audit trail is WRITTEN by log() below and, until now, never read back in code.
	// These readers surface it two ways — a manage_options admin table (access_log_recent)
	// and grouped day-counts for the ts-dot-plot sources (access_dotplot_counts). Both read
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
		if ( $uid <= 0 ) { return '—'; }
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : ( 'user #' . $uid );
	}

	/** UTC 'Y-m-d H:i:s' → site-local (Pacific) stamp for the admin table. */
	private static function local_stamp( string $sql_utc ): string {
		if ( '' === $sql_utc ) { return ''; }
		$ts = strtotime( $sql_utc . ' UTC' );
		return $ts ? wp_date( 'M j, Y g:ia', $ts ) : $sql_utc;
	}

	/**
	 * Recent audit rows for the manage_options admin viewer. Names resolved, event categorised,
	 * time in Pacific. `cross_only` narrows to cross-user (admin_search, actor≠subject) — the
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
	 * Grouped day-counts of one access CATEGORY, keyed by actor or subject, for the ts-dot-plot
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
