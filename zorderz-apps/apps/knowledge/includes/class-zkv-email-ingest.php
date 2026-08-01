<?php
/**
 * ZKV_Email_Ingest — turns a forwarded email into Knowledge Vault documents.
 *
 * Fed by ZKV_Mailbox::poll() (v1.4.0). For each unread message in the
 * App@ mailbox:
 *
 *   1. TRUST GATE — the From address must match an active WP staff user
 *      (CT decision: any staff user may file knowledge). The shared kiosk
 *      account (`zdz_general`) is refused — the kiosk never authors company
 *      knowledge (INV-Kiosk). Non-staff mail is rejected silently (no reply
 *      — never generate backscatter to strangers).
 *   2. The email body becomes a vault text document through the SAME
 *      pipeline as the dashboard's "Paste Text" flow: a .txt file in the
 *      protected vault dir, a wp_zkv_documents row (source_type 'email',
 *      owner = the forwarder), and a scheduled ZKV_Indexer run — so it gets
 *      the same AI title, synopsis, key facts, chunks, and (per CT) an
 *      AI-chosen category from the EXISTING category list.
 *   3. Real file attachments on the allowlist become their own vault
 *      documents (same owner, same provenance), each AI-indexed.
 *   4. After indexing completes the indexer calls back into after_indexed():
 *      a deterministic 'Email Correspondence' tag + the original sender are
 *      appended to the index row (never left to the model), and the
 *      forwarder gets a confirmation email with the vault link. Failures
 *      send an error reply instead of silence.
 *
 * Content-hash dedupe (same as paste/upload) means a double-forwarded email
 * can never create a second document.
 *
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Email_Ingest {

	const NOTIFY_OPT      = 'zkv_mail_notify'; // doc_id → { to, name, attachments[] }
	const TAG_LABEL       = 'Email Correspondence';
	const MAX_BODY_CHARS  = 300000;
	const MAX_ATTACHMENTS = 10;

	/** Mirror of ZKV_Dashboard::allowed_mimes() (that helper is private). */
	private static function allowed_mimes() {
		return array(
			'pdf'      => 'application/pdf',
			'txt'      => 'text/plain',
			'md'       => 'text/markdown',
			'csv'      => 'text/csv',
			'doc'      => 'application/msword',
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'      => 'application/vnd.ms-excel',
			'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'heic'     => 'image/heic',
			'srt'      => 'application/x-subrip',
			'vtt'      => 'text/vtt',
			'itt'      => 'application/xml',
			'sbv'      => 'text/plain',
			'ass'      => 'text/plain',
			'ssa'      => 'text/plain',
			'sub'      => 'text/plain',
			'lrc'      => 'text/plain',
			'xml'      => 'application/xml',
			'json'     => 'application/json',
			'rtf'      => 'application/rtf',
		);
	}

	// ──────────────────────────────────────────────────────────────
	//  Entry point
	// ──────────────────────────────────────────────────────────────

	/**
	 * Ingest one Graph message.
	 *
	 * @param array $msg Graph message resource (id, subject, from, body, …).
	 * @return array{status:string,reason:string,doc_id:int,title:string}
	 *         status ∈ stored | duplicate | rejected | failed
	 */
	public static function ingest( $msg ) {
		global $wpdb;

		$mid = (string) ( $msg['id'] ?? '' );

		// ── 0. Internal Messaging DM-reply handoff (v1.4.1) ────────
		// The App@ mailbox is shared. Before we treat a message as a document,
		// ask the messaging plugin whether it is a reply to a DM alert email
		// (plus-addressed app+dm-<token>@… or carrying an in-body token). If so,
		// hand the raw Graph message to it — it verifies the token, confirms the
		// sender is the intended recipient, strips the quote, and posts the reply
		// back into the DM. The message becomes a DM, never a vault document. The
		// vault is the single inbox reader; this hand-off is the coordination.
		if ( class_exists( 'ZIM_Email_Reply' )
		     && ZIM_Email_Reply::message_is_dm_reply( $msg ) ) {
			$r = ZIM_Email_Reply::handle_graph_message( $msg );
			if ( ! empty( $r['ok'] ) ) {
				return self::result( 'messaging', 'posted into DM' );
			}
			// Claimed but unroutable — file under the messaging plugin's own
			// folder (drop, no backscatter). Carry the folder name back to poll().
			$out = self::result( 'messaging_failed', $r['reason'] ?? 'DM reply unroutable' );
			$out['folder'] = ZIM_Email_Reply::FOLDER_FAILED;
			return $out;
		}

		// ── 1. Sender gate ─────────────────────────────────────────
		$from_email = '';
		$from_name  = '';
		foreach ( array( 'from', 'sender' ) as $k ) {
			if ( ! empty( $msg[ $k ]['emailAddress']['address'] ) ) {
				$from_email = sanitize_email( $msg[ $k ]['emailAddress']['address'] );
				$from_name  = sanitize_text_field( $msg[ $k ]['emailAddress']['name'] ?? '' );
				break;
			}
		}
		if ( empty( $from_email ) ) {
			return self::result( 'rejected', 'no sender address' );
		}

		$user = get_user_by( 'email', $from_email );
		if ( ! $user ) {
			return self::result( 'rejected', 'sender is not a staff WP user: ' . $from_email );
		}
		if ( in_array( 'zdz_general', (array) $user->roles, true ) ) {
			// INV-Kiosk: the shared kiosk login never authors company knowledge.
			return self::result( 'rejected', 'kiosk account may not file knowledge' );
		}
		$forwarder_name = $user->display_name ?: ( $from_name ?: $from_email );

		// ── 2. Subject → title (+ [admin] / [transcript] escape hatches) ─
		$raw_subject = sanitize_text_field( $msg['subject'] ?? '' );
		$visibility  = 'all_employees';
		$subject     = $raw_subject;
		if ( preg_match( '/\[admin\]/i', $subject ) ) {
			$visibility = 'admin_only';
			$subject    = trim( preg_replace( '/\[admin\]/i', '', $subject ) );
		}
		// v1.5.0: [transcript] = the forwarder's explicit PRIVATE-TRANSCRIPT
		// assertion (the email counterpart of the upload modal's opt-in — the
		// only auto-privatize trigger, per D4). The doc is born
		// transcript_private, so it is invisible to everyone from the first
		// instant; party resolution then runs from BODY speaker labels only
		// (the provenance header is sliced off) — the forwarder is NEVER
		// auto-granted for forwarding. Emailed transcripts usually carry
		// "Speaker 1/2" labels, so latent-by-default is the expected outcome;
		// the admin queue binds the real people.
		$is_transcript_mail = false;
		if ( preg_match( '/\[transcript\]/i', $subject ) ) {
			$is_transcript_mail = true;
			$visibility         = 'transcript_private';
			$subject            = trim( preg_replace( '/\[transcript\]/i', '', $subject ) );
		}
		$title = self::clean_subject( $subject );

		$received_local = self::format_received( (string) ( $msg['receivedDateTime'] ?? '' ) );
		if ( '' === $title ) {
			$title = 'Email from ' . $forwarder_name . ' — ' . $received_local;
		}

		// ── 3. Body → plain text ───────────────────────────────────
		$body_text    = '';
		$body_content = (string) ( $msg['body']['content'] ?? '' );
		$body_type    = strtolower( (string) ( $msg['body']['contentType'] ?? 'text' ) );
		if ( '' !== trim( $body_content ) ) {
			$body_text = ( 'html' === $body_type ) ? self::html_to_text( $body_content ) : trim( $body_content );
		}
		if ( strlen( $body_text ) > self::MAX_BODY_CHARS ) {
			$body_text = substr( $body_text, 0, self::MAX_BODY_CHARS ) . "\n\n[...truncated...]";
		}

		// ── 4. Attachments (real files on the allowlist only) ─────
		$attachment_rows  = array(); // stored docs: [doc_id, filename, title]
		$attachment_notes = array(); // lines for the provenance header
		if ( ! empty( $msg['hasAttachments'] ) && class_exists( 'ZKV_Mailbox' ) ) {
			$atts = ZKV_Mailbox::list_attachments( $mid );
			$atts = array_slice( $atts, 0, self::MAX_ATTACHMENTS );
			foreach ( $atts as $att ) {
				$stored = self::store_attachment( $mid, $att, $user, $forwarder_name, $from_email, $title, $received_local, $visibility );
				$attachment_notes[] = $att['name'] . ' — ' . $stored['note'];
				if ( ! empty( $stored['doc_id'] ) ) {
					$attachment_rows[] = array(
						'doc_id'   => $stored['doc_id'],
						'filename' => $att['name'],
					);
				}
			}
		}

		if ( '' === trim( $body_text ) && empty( $attachment_rows ) ) {
			return self::result( 'rejected', 'empty email (no body text, no storable attachments)' );
		}
		if ( '' === trim( $body_text ) ) {
			$body_text = '(No body text — this email carried only the attached file' . ( count( $attachment_rows ) > 1 ? 's' : '' ) . '.)';
		}

		// ── 5. Compose the stored document text ───────────────────
		$header_lines   = array();
		$header_lines[] = 'FORWARDED EMAIL — filed to the Knowledge Vault';
		$header_lines[] = 'Forwarded by: ' . $forwarder_name . ' <' . $from_email . '>';
		$header_lines[] = 'Received: ' . $received_local;
		if ( '' !== $raw_subject ) {
			$header_lines[] = 'Subject: ' . $raw_subject;
		}
		if ( ! empty( $attachment_notes ) ) {
			$header_lines[] = 'Attachments: ' . implode( '; ', $attachment_notes );
		}
		$doc_text = implode( "\n", $header_lines ) . "\n\n────────────────────────\n\n" . $body_text;

		// ── 6. Content-hash dedupe (same rule as paste/upload) ────
		$file_hash = hash( 'sha256', $doc_text );
		$existing  = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title FROM {$wpdb->prefix}zkv_documents WHERE file_hash = %s LIMIT 1",
			$file_hash
		), ARRAY_A );
		if ( $existing ) {
			self::send_mail( $from_email, 'Knowledge Vault — already filed', array(
				'This email is already in the Knowledge Vault as "' . $existing['title'] . '", so nothing new was created.',
				'Nothing to do — it is already remembered.',
			) );
			return self::result( 'duplicate', 'content hash matches doc #' . $existing['id'], (int) $existing['id'], $existing['title'] );
		}

		// ── 7. Write the .txt into the protected vault dir ────────
		if ( function_exists( 'zkv_create_secure_vault_dir' ) ) {
			zkv_create_secure_vault_dir();
		}
		$safe_name = sanitize_file_name( substr( $title, 0, 60 ) );
		$filename  = date( 'Y-m-d' ) . '-email-' . ( $safe_name ?: 'message' ) . '.txt';
		$file_path = ZKV_VAULT_DIR . '/' . wp_unique_filename( ZKV_VAULT_DIR, $filename );
		if ( false === file_put_contents( $file_path, $doc_text ) ) {
			return self::result( 'failed', 'could not write email text to the vault directory' );
		}

		// ── 8. Insert the document row (owner = the forwarder) ────
		$provenance = 'Email correspondence forwarded to the Knowledge Vault by '
			. $forwarder_name . ' (' . $from_email . ') on ' . $received_local
			. ( '' !== $raw_subject ? '. Original subject: ' . $raw_subject : '' ) . '.';

		$inserted = $wpdb->insert( $wpdb->prefix . 'zkv_documents', array(
			'attachment_id' => 0,
			'uploaded_by'   => (int) $user->ID,
			'slug'          => '',
			'title'         => $title,
			'original_name' => basename( $file_path ),
			'mime_type'     => 'text/plain',
			'file_size'     => strlen( $doc_text ),
			'file_url'      => $file_path,
			'file_hash'     => $file_hash,
			'source_type'   => 'email',
			'description'   => 'Email correspondence — forwarded by ' . $forwarder_name,
			'user_context'  => $provenance,
			'category_id'   => null, // AI picks from the existing categories during indexing.
			'status'        => 'pending',
			'visibility'    => $visibility,
			'transcript_status' => $is_transcript_mail ? 'detected' : '',
			'version'       => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		) );

		if ( false === $inserted ) {
			@unlink( $file_path );
			return self::result( 'failed', 'could not create the document record' );
		}
		$doc_id = (int) $wpdb->insert_id;

		// Slug (uniqueness needs the id).
		if ( function_exists( 'zkv_generate_slug' ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'slug' => zkv_generate_slug( $title, $doc_id ) ),
				array( 'id' => $doc_id ), array( '%s' ), array( '%d' )
			);
		}

		// ── 9. Queue the forwarder's confirmation + schedule indexing ─
		self::queue_notify( $doc_id, $from_email, $forwarder_name, wp_list_pluck( $attachment_rows, 'filename' ) );
		wp_schedule_single_event( time(), 'zkv_process_pending_doc', array( $doc_id ) );

		// Access log — same table the dashboard writes.
		$wpdb->insert( $wpdb->prefix . 'zkv_access_log', array(
			'user_id'     => (int) $user->ID,
			'action'      => 'email_ingest',
			'document_id' => $doc_id,
			'context'     => 'mail-in',
			'created_at'  => current_time( 'mysql' ),
		) );

		return self::result( 'stored', '', $doc_id, $title );
	}

	// ──────────────────────────────────────────────────────────────
	//  Attachments
	// ──────────────────────────────────────────────────────────────

	/**
	 * Store one email attachment as its own vault document.
	 *
	 * @return array{doc_id:int,note:string}
	 */
	private static function store_attachment( $mid, $att, $user, $forwarder_name, $from_email, $email_title, $received_local, $visibility ) {
		global $wpdb;

		$name = sanitize_file_name( $att['name'] ?: 'attachment' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		// Allowlist check (extension decides; MIME filled from the map when blank).
		$mime_ok = false;
		$mime    = sanitize_text_field( $att['contentType'] );
		foreach ( self::allowed_mimes() as $exts => $allowed_mime ) {
			if ( in_array( $ext, explode( '|', $exts ), true ) ) {
				$mime_ok = true;
				if ( empty( $mime ) ) { $mime = $allowed_mime; }
				break;
			}
		}
		if ( ! $mime_ok ) {
			return array( 'doc_id' => 0, 'note' => 'not stored (file type .' . $ext . ' not allowed)' );
		}
		if ( $att['size'] > ZKV_MAX_UPLOAD_BYTES ) {
			return array( 'doc_id' => 0, 'note' => 'not stored (exceeds ' . size_format( ZKV_MAX_UPLOAD_BYTES ) . ')' );
		}

		$bytes = ZKV_Mailbox::attachment_bytes( $mid, $att['id'] );
		if ( '' === $bytes ) {
			return array( 'doc_id' => 0, 'note' => 'not stored (download failed)' );
		}
		if ( strlen( $bytes ) > ZKV_MAX_UPLOAD_BYTES ) {
			return array( 'doc_id' => 0, 'note' => 'not stored (exceeds ' . size_format( ZKV_MAX_UPLOAD_BYTES ) . ')' );
		}

		// Content-hash dedupe — a re-forwarded quote PDF stays a single doc.
		$file_hash = hash( 'sha256', $bytes );
		$existing  = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title FROM {$wpdb->prefix}zkv_documents WHERE file_hash = %s LIMIT 1",
			$file_hash
		), ARRAY_A );
		if ( $existing ) {
			return array( 'doc_id' => 0, 'note' => 'already in the vault as "' . $existing['title'] . '"' );
		}

		if ( function_exists( 'zkv_create_secure_vault_dir' ) ) {
			zkv_create_secure_vault_dir();
		}
		$file_path = ZKV_VAULT_DIR . '/' . wp_unique_filename( ZKV_VAULT_DIR, $name );
		if ( false === file_put_contents( $file_path, $bytes ) ) {
			return array( 'doc_id' => 0, 'note' => 'not stored (write failed)' );
		}

		$title = pathinfo( $name, PATHINFO_FILENAME );
		$title = ucwords( trim( preg_replace( '/\s+/', ' ', str_replace( array( '-', '_' ), ' ', $title ) ) ) );

		// v1.5.0: [transcript] mail — per-document application (D7): the
		// assertion covers the transcript-capable attachments too (the
		// transcript is at least as often the attachment as the body), but an
		// IMAGE attached to a transcript email is not a transcript — images
		// fall back to all_employees.
		$att_visibility = $visibility;
		$att_tstatus    = '';
		if ( 'transcript_private' === $visibility ) {
			if ( 0 === strpos( (string) $mime, 'image/' ) ) {
				$att_visibility = 'all_employees';
			} else {
				$att_tstatus = 'detected';
			}
		}

		$inserted = $wpdb->insert( $wpdb->prefix . 'zkv_documents', array(
			'attachment_id' => 0,
			'uploaded_by'   => (int) $user->ID,
			'slug'          => '',
			'title'         => $title ?: $name,
			'original_name' => $name,
			'mime_type'     => $mime,
			'file_size'     => strlen( $bytes ),
			'file_url'      => $file_path,
			'file_hash'     => $file_hash,
			'source_type'   => 'email',
			'description'   => 'Email attachment — forwarded by ' . $forwarder_name,
			'user_context'  => 'Attachment from the email "' . $email_title . '" forwarded to the Knowledge Vault by '
				. $forwarder_name . ' (' . $from_email . ') on ' . $received_local . '.',
			'category_id'   => null, // AI picks during indexing.
			'status'        => 'pending',
			'visibility'    => $att_visibility,
			'transcript_status' => $att_tstatus,
			'version'       => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		) );

		if ( false === $inserted ) {
			@unlink( $file_path );
			return array( 'doc_id' => 0, 'note' => 'not stored (record failed)' );
		}
		$doc_id = (int) $wpdb->insert_id;

		if ( function_exists( 'zkv_generate_slug' ) ) {
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'slug' => zkv_generate_slug( $title ?: $name, $doc_id ) ),
				array( 'id' => $doc_id ), array( '%s' ), array( '%d' )
			);
		}

		wp_schedule_single_event( time() + 5, 'zkv_process_pending_doc', array( $doc_id ) );

		return array( 'doc_id' => $doc_id, 'note' => 'stored in the vault' );
	}

	// ──────────────────────────────────────────────────────────────
	//  Indexer callbacks (deterministic tag + forwarder notification)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Called by ZKV_Indexer right after a document is successfully indexed.
	 * For email-sourced docs only: force the 'Email Correspondence' tag +
	 * sender onto the index row (never left to the model — deterministic),
	 * then send the queued confirmation to the forwarder.
	 *
	 * Idempotent: reindexing tags again harmlessly and never re-notifies
	 * (the notify queue entry is removed on first send).
	 *
	 * @param int   $document_id
	 * @param array $doc Document row as loaded at the START of indexing.
	 */
	public static function after_indexed( $document_id, $doc ) {
		if ( empty( $doc['source_type'] ) || 'email' !== $doc['source_type'] ) {
			return;
		}
		global $wpdb;

		// Deterministic tag + searchable sender on the CURRENT index row.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, tags, search_text FROM {$wpdb->prefix}zkv_index WHERE document_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1",
			(int) $document_id
		), ARRAY_A );

		if ( $row ) {
			$tags = (string) $row['tags'];
			if ( false === stripos( $tags, self::TAG_LABEL ) ) {
				$tags = '' === trim( $tags ) ? self::TAG_LABEL : $tags . ', ' . self::TAG_LABEL;
				$tags = substr( $tags, 0, 500 ); // column is varchar(500)
			}
			$search = (string) $row['search_text'];
			$extra  = self::TAG_LABEL . ' ' . (string) ( $doc['user_context'] ?? '' );
			if ( false === stripos( $search, self::TAG_LABEL ) ) {
				$search .= ' ' . $extra;
			}
			$wpdb->update( $wpdb->prefix . 'zkv_index',
				array( 'tags' => $tags, 'search_text' => $search ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ), array( '%d' )
			);
		}

		// Confirmation email (first successful index only).
		$notify = self::pop_notify( $document_id );
		if ( empty( $notify['to'] ) ) {
			return;
		}

		$final = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.title, d.slug, d.visibility, c.label AS category_label
			 FROM {$wpdb->prefix}zkv_documents d
			 LEFT JOIN {$wpdb->prefix}zkv_categories c ON d.category_id = c.id
			 WHERE d.id = %d",
			(int) $document_id
		), ARRAY_A );
		if ( ! $final ) {
			return;
		}

		$lines   = array();
		$lines[] = 'Filed in the Knowledge Vault: "' . $final['title'] . '"';
		$lines[] = 'Category: ' . ( $final['category_label'] ?: 'General' ) . ' (AI-chosen) · Tagged: ' . self::TAG_LABEL;
		if ( 'admin_only' === $final['visibility'] ) {
			$lines[] = 'Visibility: Admin only';
		}
		if ( ! empty( $notify['attachments'] ) ) {
			$lines[] = 'Attachments also filed: ' . implode( ', ', (array) $notify['attachments'] );
		}
		// v1.5.0: private transcripts get an honest, different confirmation.
		// (No content beyond the title the forwarder already possessed — they
		// forwarded the text — and no vault link they may not be able to open.)
		if ( 'transcript_private' === ( $final['visibility'] ?? '' ) ) {
			$lines[] = 'Visibility: PRIVATE TRANSCRIPT — only the people named as speakers can open it (matched to staff accounts; unmatched speakers wait for an admin to confirm).';
			$lines[] = 'It does NOT appear in general search or Brain Bot except for its named parties.';
		} else {
			if ( ! empty( $final['slug'] ) ) {
				$lines[] = 'View it: ' . home_url( '/vault/' . $final['slug'] );
			}
			$lines[] = '';
			$lines[] = 'It is now searchable in the Knowledge app and available to Brain Bot.';
		}

		self::send_mail( $notify['to'], 'Knowledge Vault — filed: ' . $final['title'], $lines );
	}

	/**
	 * Called by ZKV_Indexer when indexing fails. Email-sourced docs get an
	 * error reply instead of silence; the document stays in the vault in
	 * 'failed' state so an admin can retry from the Vault screen.
	 */
	public static function after_failed( $document_id, $doc, $error ) {
		if ( empty( $doc['source_type'] ) || 'email' !== $doc['source_type'] ) {
			return;
		}
		$notify = self::pop_notify( $document_id );
		if ( empty( $notify['to'] ) ) {
			return;
		}
		self::send_mail( $notify['to'], 'Knowledge Vault — filing needs a retry', array(
			'Your forwarded email "' . ( $doc['title'] ?? '' ) . '" was received and saved, but the AI indexing step failed:',
			$error,
			'',
			'An admin can re-run indexing from the Knowledge Vault screen — nothing was lost.',
		) );
	}

	// ──────────────────────────────────────────────────────────────
	//  Notify queue (option-backed, popped on first send)
	// ──────────────────────────────────────────────────────────────

	private static function queue_notify( $doc_id, $to, $name, $attachments ) {
		$q = get_option( self::NOTIFY_OPT, array() );
		if ( ! is_array( $q ) ) { $q = array(); }
		$q[ (string) $doc_id ] = array(
			'to'          => $to,
			'name'        => $name,
			'attachments' => array_values( (array) $attachments ),
		);
		// Bound the queue defensively.
		if ( count( $q ) > 200 ) {
			$q = array_slice( $q, -200, null, true );
		}
		update_option( self::NOTIFY_OPT, $q, false );
	}

	private static function pop_notify( $doc_id ) {
		$q = get_option( self::NOTIFY_OPT, array() );
		if ( ! is_array( $q ) || empty( $q[ (string) $doc_id ] ) ) {
			return array();
		}
		$entry = $q[ (string) $doc_id ];
		unset( $q[ (string) $doc_id ] );
		update_option( self::NOTIFY_OPT, $q, false );
		return is_array( $entry ) ? $entry : array();
	}

	// ──────────────────────────────────────────────────────────────
	//  Small helpers
	// ──────────────────────────────────────────────────────────────

	/** Strip any pile of Fwd:/FW:/Re:/AW: prefixes off a subject. */
	public static function clean_subject( $subject ) {
		$s = trim( (string) $subject );
		$guard = 0;
		while ( $guard < 10 && preg_match( '/^(fwd?|fw|re|aw)\s*:\s*/i', $s ) ) {
			$s = preg_replace( '/^(fwd?|fw|re|aw)\s*:\s*/i', '', $s );
			$guard++;
		}
		return trim( $s );
	}

	/**
	 * HTML email body → readable plain text.
	 * Kills style/script/head blocks, keeps line structure, decodes entities.
	 */
	public static function html_to_text( $html ) {
		$text = (string) $html;
		$text = preg_replace( '/<(style|script|head|title)\b[^>]*>.*?<\/\1>/is', ' ', $text );
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
		$text = preg_replace( '/<\/(p|div|tr|li|h[1-6]|blockquote|table)>/i', "\n", $text );
		$text = preg_replace( '/<li\b[^>]*>/i', '- ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), array( ' ', '' ), $text ); // nbsp, zero-width
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/** Graph ISO-8601 UTC → site-local human string. */
	private static function format_received( $iso ) {
		if ( '' === $iso ) {
			return current_time( 'M j, Y g:ia' );
		}
		$mysql_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $iso ) );
		$local     = get_date_from_gmt( $mysql_gmt, 'M j, Y g:ia' );
		return $local ?: $mysql_gmt . ' UTC';
	}

	/** Plain-text mail through wp_mail (WP Mail SMTP handles transport/identity). */
	private static function send_mail( $to, $subject, $lines ) {
		if ( ! is_email( $to ) ) { return; }
		wp_mail( $to, $subject, implode( "\n", (array) $lines ) );
	}

	private static function result( $status, $reason = '', $doc_id = 0, $title = '' ) {
		return array( 'status' => $status, 'reason' => $reason, 'doc_id' => $doc_id, 'title' => $title );
	}
}
