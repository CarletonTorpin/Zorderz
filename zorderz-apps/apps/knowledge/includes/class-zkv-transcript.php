<?php
/**
 * ZKV_Transcript — the transcript ingest pipeline (v1.5.0).
 *
 * All the "intelligence" of the private-transcript feature lives HERE, at
 * write time, once per document — never on the read/hot path. This class:
 *
 *   1. Materializes the LINE RENDITION (wp_zkv_transcript_lines): the
 *      normalized, non-overlapping, line-numbered form of the transcript.
 *      Chunks (2000 chars / 200 overlap) are not human-selectable units and
 *      duplicate text at their seams; the rendition is the stable coordinate
 *      system that excerpt selection, redaction, and the admin queue's
 *      ±1-line context all read from.
 *   2. Detects speaker labels and resolves them to WP users — EXACT
 *      name/alias match only, ambiguity refuses (never guesses), unmatched
 *      labels are ignored-and-logged. The output is integer rows in
 *      wp_zkv_doc_parties; no fuzzy matching ever runs at read time.
 *   3. Materializes excerpts for sharing: only the shared lines are stored
 *      (P3 — remove, don't cover). Redaction-mode builds verify the redacted
 *      text is byte-absent from the stored excerpt before it is saved.
 *
 * DETECTION IS OPT-IN (D4): only the uploader's explicit assertion (upload
 * modal choice, or the [transcript] email-subject tag) privatizes a document
 * at ingest. AI/structural detection may only SUGGEST — the doc stays a
 * normal, visible document until an admin confirms in the queue, so a
 * mis-detected policy PDF can never silently vanish from its own uploader.
 *
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Transcript {

	/** Max rendition lines stored per document (runaway-input backstop). */
	const MAX_LINES = 20000;

	/** Max characters kept per rendition line. */
	const MAX_LINE_CHARS = 4000;

	/** A label must speak on at least this many lines to count as a speaker. */
	const MIN_SPEAKER_LINES = 2;

	/** user_meta key holding extra names that resolve to a user ("CJ, Carl"). */
	const ALIAS_META = 'ts_transcript_aliases';

	/**
	 * Labels that look like "Name:" lines but are provenance/headers, never
	 * speakers. Compared lowercase. (The email-ingest provenance header is
	 * additionally sliced off entirely — this is belt and suspenders.)
	 */
	private static $label_blacklist = array(
		'forwarded by', 'received', 'subject', 'attachments', 'from', 'to',
		'cc', 'bcc', 'date', 'sent', 'note', 'warning', 'speaker', 'speakers',
		'transcript', 'summary', 'agenda', 'location', 'time', 'duration',
	);

	// ══════════════════════════════════════════════════════════════
	//  LINE RENDITION
	// ══════════════════════════════════════════════════════════════

	/**
	 * Build the normalized line rendition for a document.
	 *
	 * Sources the SAME extraction the chunk store uses (so rendition and
	 * chunks describe the same text), with two transcript-specific additions:
	 *   - WebVTT <v Speaker> voice tags are converted to "Speaker: " prefixes
	 *     BEFORE parsing (the stock parser strip_tags()es the name away).
	 *   - Email-sourced docs are sliced to the body BELOW the provenance
	 *     separator, so "Forwarded by: Ron <…>" header lines can neither
	 *     pollute speaker detection nor leak into excerpts.
	 *
	 * @param array $doc wp_zkv_documents row.
	 * @return array[] Each: array( 'speaker' => string, 'text' => string ).
	 */
	public static function build_lines( $doc ) {
		$text = self::extract_rendition_text( $doc );
		if ( '' === trim( (string) $text ) ) { return array(); }

		// Email-sourced docs: drop the provenance header (everything above the
		// box-drawing separator line the email ingest writes).
		if ( ! empty( $doc['source_type'] ) && 'email' === $doc['source_type'] ) {
			$text = self::email_body_slice( $text );
		}

		$raw_lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines     = array();
		foreach ( $raw_lines as $raw ) {
			$raw = trim( $raw );
			if ( '' === $raw ) { continue; }
			if ( strlen( $raw ) > self::MAX_LINE_CHARS ) {
				$raw = substr( $raw, 0, self::MAX_LINE_CHARS );
			}
			$speaker = '';
			$body    = $raw;
			if ( preg_match( '/^([A-Za-z][A-Za-z0-9 .\'\-]{0,60}?)\s*[:：]\s+(.+)$/u', $raw, $m ) ) {
				$label = trim( $m[1] );
				// A speaker label is 1–4 words; longer prefixes are sentences.
				if ( str_word_count( $label ) <= 4 ) {
					$speaker = $label;
					$body    = trim( $m[2] );
				}
			}
			$lines[] = array( 'speaker' => $speaker, 'text' => $body );
			if ( count( $lines ) >= self::MAX_LINES ) { break; }
		}
		return $lines;
	}

	/**
	 * Persist the rendition (replacing any prior one — re-index safe).
	 *
	 * @return int Number of lines stored.
	 */
	public static function store_lines( $doc_id, array $lines ) {
		global $wpdb;
		$table = $wpdb->prefix . 'zkv_transcript_lines';
		$wpdb->delete( $table, array( 'document_id' => (int) $doc_id ), array( '%d' ) );

		$now = current_time( 'mysql' );
		$n   = 0;
		foreach ( $lines as $i => $line ) {
			$wpdb->insert( $table, array(
				'document_id' => (int) $doc_id,
				'line_no'     => $i + 1,
				'speaker'     => substr( (string) $line['speaker'], 0, 190 ),
				'line_text'   => (string) $line['text'],
				'created_at'  => $now,
			), array( '%d', '%d', '%s', '%s', '%s' ) );
			$n++;
		}
		return $n;
	}

	/**
	 * Load the stored rendition. CALLERS MUST ENFORCE ACCESS FIRST — this is
	 * a raw data accessor used by party-gated AJAX and the excerpt builder.
	 *
	 * @return array[] Each: array( 'line_no', 'speaker', 'line_text' ).
	 */
	public static function lines( $doc_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT line_no, speaker, line_text
			 FROM {$wpdb->prefix}zkv_transcript_lines
			 WHERE document_id = %d ORDER BY line_no ASC",
			(int) $doc_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Extraction for the rendition. Mirrors ZKV_Indexer::extract_full_text()
	 * routing but adds VTT/SRT voice-tag preservation. Falls back to the
	 * indexer's public parsers so the two never disagree about format support.
	 */
	private static function extract_rendition_text( $doc ) {
		$path = ZKV_Indexer::resolve_file_path_public( $doc );
		if ( empty( $path ) || ! file_exists( $path ) ) { return ''; }

		$mime = (string) ( $doc['mime_type'] ?? '' );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$transcript_exts = array( 'srt', 'vtt', 'itt', 'sbv', 'ass', 'ssa', 'sub', 'lrc' );
		if ( in_array( $ext, $transcript_exts, true )
		     || in_array( $mime, array( 'text/vtt', 'application/x-subrip' ), true ) ) {
			$raw = (string) file_get_contents( $path );
			// Preserve WebVTT voice tags as speaker prefixes: <v Ron>hi</v> → Ron: hi
			$raw = preg_replace( '/<v(?:\.[^\s>]*)?\s+([^>]+)>/i', '$1: ', $raw );
			$raw = str_ireplace( '</v>', '', $raw );
			return ZKV_Indexer::parse_transcript( $raw, $ext ?: 'vtt' );
		}

		if ( 'json' === $ext || 'application/json' === $mime ) {
			$raw = (string) file_get_contents( $path );
			return ZKV_Indexer::parse_json_transcript( $raw );
		}

		if ( in_array( $mime, array( 'text/plain', 'text/markdown', 'text/csv' ), true ) ) {
			return (string) file_get_contents( $path );
		}

		// Other formats (PDF/DOCX): reuse the indexer's full extraction
		// (newline-preserving — quick_extract() collapses whitespace and
		// would destroy the line structure the rendition depends on).
		return (string) ZKV_Indexer::extract_full_text_public( $doc );
	}

	/** Everything below the email provenance separator (a run of ─ chars). */
	public static function email_body_slice( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^[\x{2500}-\x{257F}]{8,}\s*$/u', trim( $line ) ) ) {
				return implode( "\n", array_slice( $lines, $i + 1 ) );
			}
		}
		return $text; // No separator found — use as-is.
	}

	// ══════════════════════════════════════════════════════════════
	//  SPEAKER DETECTION + PARTY RESOLUTION (exact match, refuse ambiguity)
	// ══════════════════════════════════════════════════════════════

	/**
	 * Distinct speaker labels in a line set, each speaking ≥ MIN_SPEAKER_LINES
	 * lines, minus header-ish blacklisted labels.
	 *
	 * @param array[] $lines build_lines()/lines() output.
	 * @return array label => line count.
	 */
	public static function speakers_from_lines( array $lines ) {
		$counts = array();
		foreach ( $lines as $line ) {
			$label = trim( (string) ( $line['speaker'] ?? '' ) );
			if ( '' === $label ) { continue; }
			if ( in_array( strtolower( $label ), self::$label_blacklist, true ) ) { continue; }
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}
		return array_filter( $counts, function ( $c ) {
			return $c >= self::MIN_SPEAKER_LINES;
		} );
	}

	/**
	 * Build the resolvable identity map ONCE per request:
	 * normalized name → array of user ids that claim it.
	 *
	 * Names per user: display_name, first_name, user_login, nickname, plus the
	 * ts_transcript_aliases user_meta (comma-separated or JSON array). The
	 * shared kiosk role (zdz_general) is excluded — the kiosk is never a party
	 * (INV-Kiosk).
	 *
	 * @return array normalized-name => int[] user ids.
	 */
	public static function identity_map() {
		static $map = null;
		if ( null !== $map ) { return $map; }

		$map   = array();
		$users = get_users( array( 'fields' => 'all' ) );
		foreach ( $users as $user ) {
			$roles = (array) $user->roles;
			if ( in_array( 'zdz_general', $roles, true ) ) { continue; } // kiosk never a party
			$uid   = (int) $user->ID;
			$names = array(
				(string) $user->display_name,
				(string) get_user_meta( $uid, 'first_name', true ),
				(string) $user->user_login,
				(string) get_user_meta( $uid, 'nickname', true ),
			);
			$alias_raw = get_user_meta( $uid, self::ALIAS_META, true );
			if ( ! empty( $alias_raw ) ) {
				$aliases = is_array( $alias_raw ) ? $alias_raw : null;
				if ( null === $aliases ) {
					$decoded = json_decode( (string) $alias_raw, true );
					$aliases = is_array( $decoded ) ? $decoded : explode( ',', (string) $alias_raw );
				}
				foreach ( (array) $aliases as $a ) { $names[] = (string) $a; }
			}
			foreach ( $names as $name ) {
				$key = self::normalize_label( $name );
				if ( '' === $key ) { continue; }
				if ( ! isset( $map[ $key ] ) ) { $map[ $key ] = array(); }
				if ( ! in_array( $uid, $map[ $key ], true ) ) { $map[ $key ][] = $uid; }
			}
		}
		return $map;
	}

	/** Normalize a label/name for exact comparison. */
	public static function normalize_label( $label ) {
		$label = trim( (string) $label );
		$label = preg_replace( '/\s*\([^)]*\)\s*$/', '', $label ); // strip "(host)" role tags
		$label = preg_replace( '/\s+/', ' ', $label );
		return strtolower( trim( $label ) );
	}

	/**
	 * Resolve one speaker label to EXACTLY ONE WP user, or refuse.
	 *
	 * @return array( 'user_id' => int|null, 'method' => 'exact_name'|'alias'|null,
	 *                'reason' => 'ok'|'no_match'|'ambiguous' )
	 */
	public static function resolve_label( $label ) {
		$key = self::normalize_label( $label );
		if ( '' === $key ) {
			return array( 'user_id' => null, 'method' => null, 'reason' => 'no_match' );
		}
		$map = self::identity_map();
		if ( ! isset( $map[ $key ] ) || empty( $map[ $key ] ) ) {
			return array( 'user_id' => null, 'method' => null, 'reason' => 'no_match' );
		}
		if ( count( $map[ $key ] ) > 1 ) {
			// >1 hit: REFUSE rather than guess. Fail toward omitting a real
			// party (fixable in the admin queue), never toward the wrong grant.
			return array( 'user_id' => null, 'method' => null, 'reason' => 'ambiguous' );
		}
		$uid = (int) $map[ $key ][0];

		// Provenance: was it a core name or an alias? (UI/audit only.)
		$user   = get_userdata( $uid );
		$core   = array();
		if ( $user ) {
			$core[] = self::normalize_label( $user->display_name );
			$core[] = self::normalize_label( get_user_meta( $uid, 'first_name', true ) );
			$core[] = self::normalize_label( $user->user_login );
			$core[] = self::normalize_label( get_user_meta( $uid, 'nickname', true ) );
		}
		$method = in_array( $key, array_filter( $core ), true ) ? 'exact_name' : 'alias';
		return array( 'user_id' => $uid, 'method' => $method, 'reason' => 'ok' );
	}

	/**
	 * Full resolution pipeline for a PRIVATIZED transcript document:
	 * rendition → speakers → exact-match grants → status active|latent.
	 *
	 * Ordering note (no-exposure window): callers privatize the doc
	 * (visibility='transcript_private') BEFORE calling this, so the document
	 * is invisible to everyone while resolution runs; parties are added and
	 * only then does status flip to 'active'.
	 *
	 * @param int $doc_id
	 * @param int $actor_uid Who initiated (uploader / admin / 0 = system).
	 * @return array Summary for logging/UI.
	 */
	public static function resolve_document( $doc_id, $actor_uid = 0 ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		$doc    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		), ARRAY_A );
		if ( ! $doc || ! ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
			return array( 'ok' => false, 'error' => 'not a private transcript' );
		}

		$lines = self::build_lines( $doc );
		self::store_lines( $doc_id, $lines );

		$speakers  = self::speakers_from_lines( $lines );
		$matched   = 0;
		$unmatched = array();

		foreach ( $speakers as $label => $count ) {
			$res = self::resolve_label( $label );
			if ( ! empty( $res['user_id'] ) ) {
				$inserted = self::grant_party( $doc_id, (int) $res['user_id'], $label, $res['method'], $actor_uid );
				if ( $inserted ) { $matched++; }
			} else {
				$unmatched[] = $label;
				ZKV_ACL::log( 'transcript_party_unmatched', $doc_id, $actor_uid,
					'label="' . $label . '" reason=' . $res['reason'] . ' lines=' . (int) $count );
			}
		}

		$party_total = count( ZKV_ACL::party_user_ids( $doc_id ) );
		$status      = $party_total >= 1 ? 'active' : 'latent';
		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array( 'transcript_status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $doc_id ), array( '%s', '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'active' === $status ? 'transcript_activated' : 'transcript_latent',
			$doc_id, $actor_uid,
			'parties=' . $party_total . ( $unmatched ? ' unmatched=' . implode( '|', $unmatched ) : '' ) );

		self::flush_caches();

		return array(
			'ok'        => true,
			'status'    => $status,
			'parties'   => $party_total,
			'matched'   => $matched,
			'unmatched' => $unmatched,
			'lines'     => count( $lines ),
		);
	}

	// ══════════════════════════════════════════════════════════════
	//  v1.5.2 (KV2) — EXPLICIT-BY-DEFAULT: uploader confirms who may see it
	// ══════════════════════════════════════════════════════════════

	/**
	 * For an opt-in private transcript, DO NOT auto-grant its detected speakers.
	 * Build the line rendition, grant only the UPLOADER (so they can see and manage
	 * their own upload — they already hold the file, so this discloses nothing new),
	 * and set status='pending_confirm'. The named parties are granted only after the
	 * uploader confirms who may see it (confirm_parties). Nothing is shared until then.
	 *
	 * Replaces the auto-granting resolve_document() on the two indexer upload paths;
	 * the admin-queue confirm path still uses resolve_document() unchanged.
	 */
	public static function stage_pending_confirmation( $doc_id, $uploader_uid = 0 ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		$doc    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		), ARRAY_A );
		if ( ! $doc || ! ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
			return array( 'ok' => false, 'error' => 'not a private transcript' );
		}

		$lines = self::build_lines( $doc );
		self::store_lines( $doc_id, $lines );

		$uploader_uid = (int) $uploader_uid;
		if ( $uploader_uid > 0 ) {
			self::grant_party( $doc_id, $uploader_uid, '', 'uploader', $uploader_uid );
		}

		$detected = self::detected_parties( $doc_id, $lines );

		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array( 'transcript_status' => 'pending_confirm', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $doc_id ), array( '%s', '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'transcript_pending_confirm', $doc_id, $uploader_uid,
			'detected=' . count( $detected['matched'] )
			. ( ! empty( $detected['unmatched'] ) ? ' unmatched=' . implode( '|', $detected['unmatched'] ) : '' ) );

		self::flush_caches();

		return array(
			'ok'        => true,
			'status'    => 'pending_confirm',
			'detected'  => $detected['matched'],
			'unmatched' => $detected['unmatched'],
			'lines'     => count( $lines ),
		);
	}

	/**
	 * READ-ONLY speaker resolution — returns the named parties a transcript's speakers
	 * resolve to, WITHOUT granting anyone. Drives the uploader's "who may see it?"
	 * confirmation. Pass $lines to avoid rebuilding the rendition.
	 *
	 * @return array{matched: array<int,array{user_id:int,name:string,speaker_label:string}>, unmatched: string[]}
	 */
	public static function detected_parties( $doc_id, $lines = null ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		if ( null === $lines ) {
			$doc = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
			), ARRAY_A );
			if ( ! $doc ) { return array( 'matched' => array(), 'unmatched' => array() ); }
			$lines = self::build_lines( $doc );
		}
		$speakers  = self::speakers_from_lines( $lines );
		$matched   = array();
		$seen      = array();
		$unmatched = array();
		foreach ( $speakers as $label => $count ) {
			$res = self::resolve_label( $label );
			if ( ! empty( $res['user_id'] ) ) {
				$uid = (int) $res['user_id'];
				if ( isset( $seen[ $uid ] ) ) { continue; }
				$seen[ $uid ] = true;
				$u = get_userdata( $uid );
				$matched[] = array(
					'user_id'       => $uid,
					'name'          => $u ? $u->display_name : ( '#' . $uid ),
					'speaker_label' => (string) $label,
				);
			} else {
				$unmatched[] = (string) $label;
			}
		}
		return array( 'matched' => $matched, 'unmatched' => $unmatched );
	}

	/**
	 * Grant the parties the uploader CONFIRMED (a subset of the detected speakers) and
	 * flip the transcript to 'active'. Only ids that actually resolve as detected
	 * speakers are granted — the uploader can never inject an arbitrary user this way.
	 * The uploader stays a party. Confirming nobody is valid (shared with no one).
	 *
	 * @param int[] $confirmed_ids
	 */
	public static function confirm_parties( $doc_id, array $confirmed_ids, $actor_uid = 0 ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		$doc    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		), ARRAY_A );
		if ( ! $doc || ! ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
			return array( 'ok' => false, 'error' => 'not a private transcript' );
		}

		$detected = self::detected_parties( $doc_id );
		$valid    = array();
		foreach ( $detected['matched'] as $m ) { $valid[ (int) $m['user_id'] ] = $m['speaker_label']; }

		$granted = 0;
		foreach ( $confirmed_ids as $uid ) {
			$uid = (int) $uid;
			if ( isset( $valid[ $uid ] ) ) {
				if ( self::grant_party( $doc_id, $uid, $valid[ $uid ], 'confirmed', $actor_uid ) ) { $granted++; }
			}
		}

		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array( 'transcript_status' => 'active', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $doc_id ), array( '%s', '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'transcript_parties_confirmed', $doc_id, $actor_uid,
			'granted=' . $granted . ' of ' . count( $valid ) . ' detected' );

		self::flush_caches();

		return array(
			'ok'      => true,
			'status'  => 'active',
			'granted' => $granted,
			'parties' => count( ZKV_ACL::party_user_ids( $doc_id ) ),
		);
	}

	/**
	 * Insert one party grant (idempotent — uniq_doc_user absorbs re-runs).
	 * The ONLY writers of wp_zkv_doc_parties are this resolver and the
	 * admin bind/remove actions below. A party can share a VIEW (doc_shares)
	 * but can never promote anyone into the party ring.
	 */
	public static function grant_party( $doc_id, $user_id, $label, $method, $actor_uid = 0 ) {
		global $wpdb;
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}zkv_doc_parties
			   (document_id, user_id, speaker_label, match_method, created_at)
			 VALUES (%d, %d, %s, %s, %s)",
			(int) $doc_id, (int) $user_id,
			substr( (string) $label, 0, 190 ), substr( (string) $method, 0, 24 ),
			current_time( 'mysql' )
		) );
		if ( $ok ) {
			ZKV_ACL::log( 'transcript_party_added', $doc_id, $actor_uid,
				'user=' . (int) $user_id . ' label="' . $label . '" method=' . $method );
			ZKV_ACL::reset_cache();
		}
		return (bool) $ok;
	}

	// ══════════════════════════════════════════════════════════════
	//  PRIVATIZE / SUGGEST / ADMIN-QUEUE ACTIONS
	// ══════════════════════════════════════════════════════════════

	/**
	 * Mark a document as a private transcript (the OPT-IN write). Sets
	 * visibility='transcript_private' + transcript_status='detected' — from
	 * this instant the doc is invisible to every normal query, BEFORE any
	 * index/chunk content is exposed. Party resolution then runs and flips it
	 * to active/latent.
	 */
	public static function privatize( $doc_id, $actor_uid = 0 ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array(
				'visibility'        => ZKV_ACL::VIS_TRANSCRIPT,
				'transcript_status' => 'detected',
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => (int) $doc_id ), array( '%s', '%s', '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'transcript_privatized', $doc_id, $actor_uid );
		self::flush_caches();
	}

	/**
	 * AI/structural SUGGESTION (D4): never changes visibility. Flags the doc
	 * for the admin queue; it remains a normal, visible document.
	 * Called by the indexer after classification.
	 */
	public static function suggest( $doc_id, $signal, $actor_uid = 0 ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT visibility, transcript_status FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			(int) $doc_id
		), ARRAY_A );
		if ( ! $row ) { return false; }
		// Already private, already suggested, or admin already said no → skip.
		if ( ZKV_ACL::is_transcript_visibility( $row['visibility'] ) ) { return false; }
		if ( in_array( (string) $row['transcript_status'], array( 'suggested', 'not_transcript' ), true ) ) { return false; }

		$wpdb->update( $wpdb->prefix . 'zkv_documents',
			array( 'transcript_status' => 'suggested', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $doc_id ), array( '%s', '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'transcript_suggested', $doc_id, $actor_uid, 'signal=' . $signal );
		return true;
	}

	/**
	 * Structural belt-and-suspenders signal used alongside the AI's
	 * document_type: does the extracted text LOOK like a speaker-labeled
	 * conversation? (≥ 6 labeled lines across ≥ 2 distinct speakers.)
	 */
	public static function looks_like_transcript( $text ) {
		$labeled  = 0;
		$speakers = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( preg_match( '/^([A-Za-z][A-Za-z0-9 .\'\-]{0,60}?)\s*[:：]\s+\S/u', trim( $line ), $m ) ) {
				$label = self::normalize_label( $m[1] );
				if ( '' === $label || str_word_count( $label ) > 4 ) { continue; }
				if ( in_array( $label, self::$label_blacklist, true ) ) { continue; }
				$labeled++;
				$speakers[ $label ] = true;
			}
			if ( $labeled > 200 ) { break; } // enough signal
		}
		return ( $labeled >= 6 && count( $speakers ) >= 2 );
	}

	/**
	 * Admin CONFIRMS a suggestion: privatize + resolve. The doc's existing
	 * index/chunk rows become invisible the instant visibility flips (the ACL
	 * and every legacy filter key off visibility); no re-index needed.
	 */
	public static function confirm_transcript( $doc_id, $actor_uid ) {
		self::privatize( $doc_id, $actor_uid );
		return self::resolve_document( $doc_id, $actor_uid );
	}

	/**
	 * Admin says NOT a transcript. Falls back to a normal all_employees doc
	 * (admin can flip to admin_only in the usual way if wanted); parties,
	 * shares, and the rendition are removed. 'not_transcript' suppresses any
	 * future auto re-suggest for this doc.
	 */
	public static function mark_not_transcript( $doc_id, $actor_uid ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		$was_private = ZKV_ACL::is_transcript( $doc_id );
		$update = array( 'transcript_status' => 'not_transcript', 'updated_at' => current_time( 'mysql' ) );
		if ( $was_private ) { $update['visibility'] = 'all_employees'; }
		$wpdb->update( $wpdb->prefix . 'zkv_documents', $update, array( 'id' => $doc_id ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_doc_parties', array( 'document_id' => $doc_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_doc_shares', array( 'document_id' => $doc_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_transcript_lines', array( 'document_id' => $doc_id ), array( '%d' ) );
		ZKV_ACL::log( 'transcript_rejected', $doc_id, $actor_uid, $was_private ? 'was private → all_employees' : 'suggestion dismissed' );
		self::flush_caches();
		return true;
	}

	/**
	 * Admin LATE-JOIN BIND: after confirming out-of-band who a speaker slot
	 * really is, bind it to a WP user (match_method='manual'). Never a
	 * self-claim — widening access to private content is exactly the operation
	 * that must not be trusted to the requester. Binding grants the BOUND
	 * user; it never grants the admin anything.
	 */
	public static function bind_party( $doc_id, $label, $user_id, $actor_uid ) {
		global $wpdb;
		$doc_id  = (int) $doc_id;
		$user_id = (int) $user_id;
		if ( ! ZKV_ACL::is_transcript( $doc_id ) ) {
			return array( 'ok' => false, 'error' => 'not a private transcript' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'ok' => false, 'error' => 'no such user' );
		}
		if ( in_array( 'zdz_general', (array) $user->roles, true ) ) {
			return array( 'ok' => false, 'error' => 'the kiosk account can never be a party' );
		}
		$ok = self::grant_party( $doc_id, $user_id, $label, 'manual', $actor_uid );
		ZKV_ACL::log( 'transcript_party_bound_manual', $doc_id, $actor_uid,
			'user=' . $user_id . ' label="' . $label . '"' );

		// First bound party flips latent/detected → active.
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT transcript_status FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		) );
		if ( in_array( (string) $status, array( 'latent', 'detected' ), true )
		     && count( ZKV_ACL::party_user_ids( $doc_id ) ) >= 1 ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'transcript_status' => 'active', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $doc_id ), array( '%s', '%s' ), array( '%d' )
			);
			ZKV_ACL::log( 'transcript_activated', $doc_id, $actor_uid, 'via manual bind' );
		}
		self::flush_caches();
		return array( 'ok' => (bool) $ok );
	}

	/** Admin removes a party (mis-resolution fix). 0 parties left → latent. */
	public static function remove_party( $doc_id, $user_id, $actor_uid ) {
		global $wpdb;
		$doc_id = (int) $doc_id;
		$wpdb->delete( $wpdb->prefix . 'zkv_doc_parties',
			array( 'document_id' => $doc_id, 'user_id' => (int) $user_id ),
			array( '%d', '%d' )
		);
		ZKV_ACL::log( 'transcript_party_removed', $doc_id, $actor_uid, 'user=' . (int) $user_id );
		ZKV_ACL::reset_cache();
		if ( ZKV_ACL::is_transcript( $doc_id ) && 0 === count( ZKV_ACL::party_user_ids( $doc_id ) ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'transcript_status' => 'latent', 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $doc_id ), array( '%s', '%s' ), array( '%d' )
			);
			ZKV_ACL::log( 'transcript_latent', $doc_id, $actor_uid, 'last party removed' );
		}
		self::flush_caches();
		return true;
	}

	/**
	 * Unbound speaker labels for the admin queue: distinct rendition speakers
	 * minus labels already bound to a party.
	 *
	 * @return array label => line count.
	 */
	public static function unmatched_labels( $doc_id ) {
		$speakers = self::speakers_from_lines( self::lines( (int) $doc_id ) );
		$bound    = array();
		foreach ( ZKV_ACL::parties( (int) $doc_id ) as $p ) {
			$bound[] = self::normalize_label( $p['speaker_label'] );
		}
		$out = array();
		foreach ( $speakers as $label => $count ) {
			if ( ! in_array( self::normalize_label( $label ), $bound, true ) ) {
				$out[ $label ] = $count;
			}
		}
		return $out;
	}

	/**
	 * NARROW admin-queue context for one unmatched label (D6): the label's
	 * first occurrences with ±1 line, truncated — never the whole body. Each
	 * call is audit-logged as a disclosure.
	 *
	 * @return array[] Up to $max snippets of array( 'prev', 'line', 'next' ).
	 */
	public static function context_snippets( $doc_id, $label, $actor_uid, $max = 2 ) {
		$lines = self::lines( (int) $doc_id );
		$want  = self::normalize_label( $label );
		$out   = array();
		$trunc = function ( $row ) {
			if ( ! $row ) { return ''; }
			$t = ( '' !== $row['speaker'] ? $row['speaker'] . ': ' : '' ) . $row['line_text'];
			return mb_substr( $t, 0, 140, 'UTF-8' );
		};
		foreach ( $lines as $i => $row ) {
			if ( self::normalize_label( $row['speaker'] ) !== $want ) { continue; }
			$out[] = array(
				'prev' => $trunc( $lines[ $i - 1 ] ?? null ),
				'line' => $trunc( $row ),
				'next' => $trunc( $lines[ $i + 1 ] ?? null ),
			);
			if ( count( $out ) >= $max ) { break; }
		}
		ZKV_ACL::log( 'transcript_ctx_viewed', $doc_id, $actor_uid,
			'label="' . $label . '" snippets=' . count( $out ) );
		return $out;
	}

	// ══════════════════════════════════════════════════════════════
	//  EXCERPT MATERIALIZATION (P3 — remove, don't cover)
	// ══════════════════════════════════════════════════════════════

	/**
	 * Build the materialized excerpt text from line ranges.
	 *
	 * @param int    $doc_id
	 * @param string $mode   'select' (share ONLY these ranges) or
	 *                       'redact' (share everything EXCEPT these ranges).
	 * @param array  $ranges Array of [start_line, end_line] (1-based, inclusive).
	 * @return array|WP_Error array( 'text' => string, 'span_map' => string(json),
	 *                               'kept' => int, 'removed' => int )
	 */
	public static function materialize_excerpt( $doc_id, $mode, array $ranges ) {
		$lines = self::lines( (int) $doc_id );
		if ( empty( $lines ) ) {
			return new WP_Error( 'zkv_no_lines', 'No line rendition exists for this transcript.' );
		}
		$mode = ( 'redact' === $mode ) ? 'redact' : 'select';

		// Normalize ranges → a set of selected line numbers.
		$selected = array();
		foreach ( $ranges as $r ) {
			$s = max( 1, (int) ( $r[0] ?? 0 ) );
			$e = min( count( $lines ), (int) ( $r[1] ?? 0 ) );
			if ( $e < $s ) { continue; }
			for ( $n = $s; $n <= $e; $n++ ) { $selected[ $n ] = true; }
		}
		if ( empty( $selected ) ) {
			return new WP_Error( 'zkv_empty_ranges', 'No valid line ranges given.' );
		}

		$kept_rows    = array();
		$removed_rows = array();
		foreach ( $lines as $row ) {
			$n       = (int) $row['line_no'];
			$in_sel  = isset( $selected[ $n ] );
			$is_kept = ( 'select' === $mode ) ? $in_sel : ! $in_sel;
			if ( $is_kept ) { $kept_rows[ $n ] = $row; } else { $removed_rows[ $n ] = $row; }
		}
		if ( empty( $kept_rows ) ) {
			return new WP_Error( 'zkv_empty_excerpt', 'The excerpt would contain nothing.' );
		}

		// Assemble: kept lines in document order; each contiguous removed gap
		// becomes ONE "[redacted]" readability marker (the marker is a cue —
		// the underlying words are GONE from the stored text).
		$parts   = array();
		$prev_no = 0;
		foreach ( $kept_rows as $n => $row ) {
			if ( $prev_no && $n > $prev_no + 1 ) {
				$parts[] = '[redacted]';
			} elseif ( ! $prev_no && $n > 1 ) {
				$parts[] = '[redacted]';
			}
			$parts[] = ( '' !== $row['speaker'] ? $row['speaker'] . ': ' : '' ) . $row['line_text'];
			$prev_no = $n;
		}
		if ( $prev_no < count( $lines ) ) { $parts[] = '[redacted]'; }
		$text = implode( "\n", $parts );

		// P3 COMPLETENESS VERIFICATION: every removed line's text must be
		// byte-absent from the stored excerpt — unless that exact text also
		// appears on a kept line (then it was independently shared).
		$kept_texts = array();
		foreach ( $kept_rows as $row ) { $kept_texts[ $row['line_text'] ] = true; }
		foreach ( $removed_rows as $row ) {
			$t = (string) $row['line_text'];
			if ( '' === trim( $t ) || isset( $kept_texts[ $t ] ) ) { continue; }
			if ( false !== strpos( $text, $t ) ) {
				return new WP_Error( 'zkv_redaction_leak',
					'Excerpt build failed verification: removed text found in output. Nothing was saved.' );
			}
		}

		// span_map = which line ranges were KEPT (audit/QA provenance).
		$span_map = array();
		$run_s    = null;
		$run_e    = null;
		foreach ( array_keys( $kept_rows ) as $n ) {
			if ( null === $run_s ) { $run_s = $run_e = $n; continue; }
			if ( $n === $run_e + 1 ) { $run_e = $n; continue; }
			$span_map[] = array( $run_s, $run_e );
			$run_s = $run_e = $n;
		}
		if ( null !== $run_s ) { $span_map[] = array( $run_s, $run_e ); }

		return array(
			'text'     => $text,
			'span_map' => wp_json_encode( array( 'mode' => $mode, 'kept' => $span_map ) ),
			'kept'     => count( $kept_rows ),
			'removed'  => count( $removed_rows ),
		);
	}

	// ══════════════════════════════════════════════════════════════
	//  Cache hygiene
	// ══════════════════════════════════════════════════════════════

	/** Invalidate everything that could hold a stale view of transcript state. */
	public static function flush_caches() {
		ZKV_ACL::reset_cache();
		if ( class_exists( 'ZKV_TSA_Bridge' ) && method_exists( 'ZKV_TSA_Bridge', 'invalidate_cache' ) ) {
			ZKV_TSA_Bridge::invalidate_cache();
		}
	}
}
