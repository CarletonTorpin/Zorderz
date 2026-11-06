<?php
/**
 * ZIM_Doc_Shares - an estimate or invoice named in a message (#1234), opened inside Messages the way the billing
 * system shows it, and shared for that one document only.
 *
 * A number written in a message ("#1234") becomes a chip. Opening it shows the document, read only, drawn by the
 * theme's paper (ZDZ.ui.paper). The sender may share THAT document with the other people in the conversation - never
 * the customer's other documents, never for good.
 *
 * Who may see document #N, decided on the server every time it is opened:
 *
 *   1. the viewer's OWN right, from the Estimates app (filter 'zdz_fb_document_for_viewer': the admin tier, or the
 *      estimate's own salesperson). This app never decides it.
 *   2. otherwise a live SHARE: a row in wp_zim_doc_grants naming this viewer and this number, not withdrawn, not past
 *      its 30 days, its message not deleted, the viewer still in that conversation - AND the sender may still see the
 *      document (read as the sender). A share from someone who cannot see the document gives nothing.
 *   3. otherwise: "This document belongs to someone else. Ask the sender to share it."
 *
 * A share names one number from one message, for the people in that conversation when it was given (never people
 * added later, never the shared device). It ends when the message is deleted, when the number is edited out of it,
 * when the sender withdraws it, when the person leaves the conversation, or after 30 days. Amounts show only when the
 * person looking may see prices too. Read only: nothing here writes to the billing system or to Estimates.
 *
 * Estimate or invoice: the two are separate number series in the billing system, so the same number in both is a
 * coincidence. "invoice #1234" (or "inv. #1234") means the invoice; any other #1234 means the estimate. A share holds
 * the kind its message named from the moment it is given.
 *
 * Without a document provider (nothing answers the filter) no share is made and the chip says documents cannot be
 * opened here yet.
 *
 * Caching: none here. Estimates keeps the billing system's answer for a document a few minutes and applies its rule
 * per request; who may see it is decided on every call.
 *
 * @package Zorderz
 */

defined( 'ABSPATH' ) || exit;


class ZIM_Doc_Shares {

	/** How long a share lasts at most. */
	const TTL = 30 * DAY_IN_SECONDS;

	/** At most this many numbers are shared from one message. */
	const MAX_REFS = 5;

	/** The words a person sees when the document is not theirs and nobody shared it. */
	const NOT_YOURS = 'This document belongs to someone else. Ask the sender to share it.';

	/** Test seam: a callable( $number, $args ) standing in for the filter. */
	public static $reader = null;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zim_doc_grants';
	}

	private static function now() {
		return current_time( 'mysql', true );
	}

	/** Is a document provider (the Estimates app's reader) present? */
	public static function provider_present() {
		return is_callable( self::$reader ) || ( function_exists( 'has_filter' ) && has_filter( 'zdz_fb_document_for_viewer' ) );
	}

	/**
	 * Document #N as $uid may see it. Always an array: [ ok, kind, doc, also ] or [ ok => false, why ].
	 */
	public static function read_as( $uid, $number, $kind = '', $price_user = 0 ) {
		// price_user: the person who will look at it. Amounts appear only if they may see prices too.
		$args = array( 'as_user' => (int) $uid, 'kind' => (string) $kind, 'timeout' => 8, 'price_user' => (int) ( $price_user ?: $uid ) );
		$r    = is_callable( self::$reader )
			? call_user_func( self::$reader, (string) $number, $args )
			: apply_filters( 'zdz_fb_document_for_viewer', null, (string) $number, $args );
		if ( ! is_array( $r ) ) {
			return array( 'ok' => false, 'why' => 'no_provider' );
		}
		if ( ! empty( $r['ok'] ) && ( empty( $r['doc'] ) || ! is_array( $r['doc'] ) ) ) {
			return array( 'ok' => false, 'why' => 'unavailable' );
		}
		return $r;
	}

	/** The numbers in a body that can be shared (#NNN–#NNNNNNNN), first five, in order. */
	public static function refs_in( $body ) {
		$refs = class_exists( 'ZIM_Preview_Cards' ) ? ZIM_Preview_Cards::extract_refs( (string) $body ) : array();
		return array_slice( array_map( 'strval', $refs ), 0, self::MAX_REFS );
	}

	/**
	 * A message as words, for reading what is said just before a "#N". A line break, a paragraph or a list item ends
	 * what is said ("\n"); other tags show no words of their own; entities and the no-break space become plain text;
	 * a markdown link keeps its words; and the marks * _ ` ~ go. So "**Invoice** #1234", "[invoice](…) #1234" and
	 * "invoice&nbsp;#1234" read as the words a person sees, and "invoice<br>#1234" does not join across the line.
	 * widget.js (kindBefore) takes the same steps on the message as drawn, so the chip and the server agree.
	 */
	public static function text_of( $s ) {
		return str_replace( self::MARK, '', self::marked_text( $s ) );
	}

	/** Where a markdown mark (* _ ` ~ [ ]) stood: not a letter and not "#", so "**Invoice**#1234" still has its #N. */
	const MARK = "\x01";

	/**
	 * text_of() with each mark left as MARK: deleting the marks would glue "Invoice**#1234" into "Invoice#1234", the
	 * #N could no longer be found, and the share would fall back to the estimate. Positions are found in this text;
	 * the words before a #N are read with the marks taken out ("in_voice" reads as the chip reads it).
	 */
	private static function marked_text( $s ) {
		$m = self::MARK;
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s );   // never a MARK of the body's own
		$s = str_replace( array( "\r\n", "\r" ), "\n", (string) $s );
		$s = preg_replace( '/<(https?:\/\/[^\s<>]+)>/i', '$1', (string) $s );   // <https://…> autolink: its address shows
		$s = preg_replace( '/<\/?(?:p|div|li|ul|ol|br|h[1-6]|blockquote|pre|table|tr|td|th|hr)\b[^>]*>/i', "\n", (string) $s );
		// Everything else that shows no words of its own stands as MARK, never a space: the chip deletes these, so a
		// space here would split "<i>inv</i>. #N" into "inv ." on the server only.
		$s = preg_replace( '/<\/?[a-z][^>]*>/i', $m, (string) $s );
		$s = preg_replace_callback( '/(`[^`\n]*`)|\\\\([!-\/:-@\[-`{-~])/', function ( $x ) {   // \. -> . (not inside `code`)
			return '' !== $x[1] ? $x[1] : $x[2];
		}, (string) $s );
		$s = preg_replace( '/!\[[^\]\n]*\]\((?:[^()\n]|\([^()\n]*\))*\)/', $m, (string) $s );      // an image: no words on screen
		$s = preg_replace( '/\]\((?:[^()\n]|\([^()\n]*\))*\)/', $m, (string) $s );                // [words](address "title") -> words
		$s = preg_replace( '/\]\[[^\]\n]*\]/', $m, (string) $s );                                  // [words][1] -> words
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = str_replace( "\xC2\xA0", ' ', (string) $s );
		return str_replace( array( '*', '_', '`', '~', '[', ']' ), $m, (string) $s );
	}

	/**
	 * Words right before a #N that make it an invoice: "invoice #N", "Invoice: #N", "inv. #N", "invoice no. #N",
	 * "INV - #N". Only spaces and tabs between -- never a line break -- and "inv." takes the dot, "invoice." does
	 * not, so "Sent the invoice. #1234 is the estimate" stays the estimate. \z, not $ (which would allow a final
	 * line break). widget.js INVOICE_WORDS is the same pattern.
	 */
	const INVOICE_WORDS_RE = '/(?<![a-z0-9])(?:invoice|inv\.?)(?:[ \t]*(?:no\.?|num(?:ber)?\.?))?[ \t]*[:\-\x{2013}]?[ \t]*\z/iu';

	/**
	 * Every kind this message names #N as, in order. Estimates and invoices are separate number series in the billing
	 * system, so the same number in both is a coincidence. "invoice #1234" means the invoice; "estimate #1234",
	 * "est #1234", "quote #1234" or no word means the estimate. A message naming both gives both.
	 */
	public static function kinds_for_ref( $body, $number ) {
		$text   = self::marked_text( $body );
		$number = ltrim( (string) $number, '0' );
		$out    = array();
		if ( '' === $number ) {
			return array( 'estimate' );
		}
		if ( ! preg_match_all( '/(?<![\w#])#0*' . preg_quote( $number, '/' ) . '(?!\d)/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			// The number is in the message but cannot be placed in its words: name no kind, so nothing is shared
			// and an unsettled share opens nothing (never a guess at the estimate).
			return array();
		}
		foreach ( $m[0] as $hit ) {
			// The whole text before this "#N" (a byte offset, cut on a character boundary), never a fixed window,
			// so a word cut in half ("…preinvoice") cannot look like "invoice".
			$before = str_replace( self::MARK, '', substr( $text, 0, $hit[1] ) );
			if ( ! preg_match( '//u', $before ) ) {
				$before = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $before, 'UTF-8', 'UTF-8' ) : $before;
			}
			$kind = preg_match( self::INVOICE_WORDS_RE, $before ) ? 'invoice' : 'estimate';
			if ( ! in_array( $kind, $out, true ) ) {
				$out[] = $kind;
			}
		}
		return $out;
	}

	/** The kind of the first "#N" in the message (see kinds_for_ref()); the estimate when it cannot be placed. */
	public static function kind_for_ref( $body, $number ) {
		$k = self::kinds_for_ref( $body, $number );
		return $k ? $k[0] : 'estimate';
	}

	/** Who a share from $author in $conversation_id goes to: the other members who can use it. */
	private static function grantees( $conversation_id, $author ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}zim_members WHERE conversation_id = %d",
			(int) $conversation_id
		) );
		$out = array();
		foreach ( (array) $ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 || $uid === (int) $author ) {
				continue;
			}
			if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( $uid ) ) {
				continue;   // the shared device never gets a document
			}
			$out[] = $uid;
		}
		return $out;
	}

	/**
	 * Share the given numbers from a just-posted message. Only numbers that are in the message
	 * itself are shared. Returns the number of people × documents granted.
	 */
	public static function grant_for_message( $message_id, $conversation_id, $author, $body, $numbers ) {
		global $wpdb;
		$in   = self::refs_in( $body );
		$want = array();
		foreach ( (array) $numbers as $n ) {
			$n = ltrim( preg_replace( '/\D/', '', (string) $n ), '0' );
			if ( '' !== $n && in_array( $n, array_map( function ( $x ) { return ltrim( $x, '0' ); }, $in ), true ) ) {
				$want[ $n ] = true;
			}
		}
		if ( ! $want || (int) $message_id <= 0 || (int) $author <= 0 ) {
			return 0;
		}
		// No shares while nothing can open them: they would quietly come alive when a provider arrives.
		if ( ! self::provider_present() ) {
			return 0;
		}
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( (int) $author ) ) {
			return 0;
		}
		$people = self::grantees( $conversation_id, $author );
		$now    = self::now();
		$until  = gmdate( 'Y-m-d H:i:s', time() + self::TTL );
		$made   = 0;
		$pairs = array();
		foreach ( array_keys( $want ) as $n ) {
			foreach ( self::kinds_for_ref( $body, $n ) as $k ) {   // one share per (number, kind) the message names
				$pairs[] = array( (string) $n, $k );
			}
		}
		foreach ( $pairs as $pair ) {
			list( $n, $kind ) = $pair;
			foreach ( $people as $uid ) {
				$dup = $wpdb->get_var( $wpdb->prepare(
					'SELECT id FROM ' . self::table() . " WHERE message_id = %d AND grantee_user_id = %d AND doc_number = %s AND doc_kind IN (%s, '') AND revoked_at IS NULL AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
					(int) $message_id, $uid, (string) $n, $kind, $now
				) );
				if ( $dup ) {
					continue;
				}
				$ok = $wpdb->insert( self::table(), array(
					'doc_number'      => (string) $n,
					'doc_kind'        => $kind,   // settled when it is shared, from the message's words
					'grantee_user_id' => $uid,
					'granted_by'      => (int) $author,
					'conversation_id' => (int) $conversation_id,
					'message_id'      => (int) $message_id,
					'created_at'      => $now,
					'expires_at'      => $until,
				), array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ) );
				if ( $ok ) {
					$made++;
				}
			}
		}
		if ( $made ) {
			self::audit( (int) $author, 'zim_doc_shared', sprintf( 'Shared %d document(s) from message %d', count( $pairs ), (int) $message_id ), array(
				'message_id'      => (int) $message_id,
				'conversation_id' => (int) $conversation_id,
				'documents'       => count( $pairs ),
				'people'          => count( $people ),
			) );
		}
		return $made;
	}

	/** A message's row, or null. */
	private static function message( $message_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, body, deleted_at FROM {$wpdb->prefix}zim_messages WHERE id = %d",
			(int) $message_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * "Share with this conversation" on a message sent without the share ticked (or before shares existed).
	 * Only its author; only a message that is not deleted. Returns the share status, or WP_Error.
	 */
	public static function share_message( $message_id, $actor, $number = '' ) {
		$m = self::message( $message_id );
		if ( ! $m || ! empty( $m['deleted_at'] ) ) {
			return new WP_Error( 'zim_not_found', __( 'Message not found.', 'zorderz' ) );
		}
		if ( (int) $m['author_user_id'] !== (int) $actor ) {
			return new WP_Error( 'zim_not_author', __( 'Only the person who sent it can share it.', 'zorderz' ) );
		}
		$refs = self::refs_in( $m['body'] );
		if ( ! $refs ) {
			return new WP_Error( 'zim_no_document', __( 'This message names no estimate or invoice.', 'zorderz' ) );
		}
		$number = ltrim( preg_replace( '/\D/', '', (string) $number ), '0' );
		if ( '' !== $number && ! in_array( $number, $refs, true ) ) {
			return new WP_Error( 'zim_no_document', sprintf( /* translators: %s: a document number */ __( 'This message does not name #%s.', 'zorderz' ), $number ) );
		}
		// One number given: only that one is shared (never the others the sender left out). "Share with this
		// conversation" shares every number in the message. Estimate or invoice comes from the message's own words;
		// the sender does not pick one, so there is no kind to take from the request.
		self::grant_for_message( (int) $m['id'], (int) $m['conversation_id'], (int) $actor, $m['body'], '' !== $number ? array( $number ) : $refs );
		return self::status_for_message( (int) $m['id'], (int) $actor );
	}

	/** Withdraw every live share from a message. Its author, or a site administrator. */
	public static function withdraw( $message_id, $actor ) {
		$m = self::message( $message_id );
		if ( ! $m ) {
			return new WP_Error( 'zim_not_found', __( 'Message not found.', 'zorderz' ) );
		}
		$is_admin = user_can( (int) $actor, 'manage_options' );
		if ( (int) $m['author_user_id'] !== (int) $actor && ! $is_admin ) {
			return new WP_Error( 'zim_not_author', __( 'Only the person who sent it can stop sharing it.', 'zorderz' ) );
		}
		$n = self::revoke_for_message( (int) $message_id, (int) $actor );
		if ( $n ) {
			self::audit( (int) $actor, 'zim_doc_share_withdrawn', sprintf( 'Stopped sharing the documents in message %d', (int) $message_id ), array(
				'message_id' => (int) $message_id,
				'rows'       => (int) $n,
			) );
		}
		return self::status_for_message( (int) $message_id, (int) $actor );
	}

	/** End every live share from a message (deleted, or withdrawn). Returns rows ended. */
	public static function revoke_for_message( $message_id, $actor = 0 ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET revoked_at = %s, revoked_by = %d WHERE message_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			self::now(), (int) $actor, (int) $message_id
		) );
		return (int) $n;
	}

	/** Someone taken out of a conversation loses its shares for good (re-adding them does not bring them back). */
	public static function revoke_for_grantee( $conversation_id, $user_id, $actor = 0 ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET revoked_at = %s, revoked_by = %d WHERE conversation_id = %d AND grantee_user_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			self::now(), (int) $actor, (int) $conversation_id, (int) $user_id
		) );
	}

	/** Daily: forget shares that ended more than 90 days ago (the table would otherwise only grow). */
	public static function purge_old() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE expires_at < %s OR ( revoked_at IS NOT NULL AND revoked_at < %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			$cut, $cut
		) );
	}

	/**
	 * After an edit: end the shares of numbers no longer in the message, and the shares of a kind the message no
	 * longer names -- "invoice #1234" edited to "#1234" ends the invoice's share; the sender's card then offers to
	 * share the estimate. Returns rows ended.
	 */
	public static function prune_for_message( $message_id, $body, $actor = 0 ) {
		global $wpdb;
		$keep = array();
		foreach ( self::refs_in( $body ) as $x ) {
			$x          = ltrim( (string) $x, '0' );
			$keep[ $x ] = self::kinds_for_ref( $body, $x );
		}
		$live = $wpdb->get_results( $wpdb->prepare(
			'SELECT DISTINCT doc_number, doc_kind FROM ' . self::table() . ' WHERE message_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			(int) $message_id
		), ARRAY_A );
		$n = 0;
		foreach ( (array) $live as $row ) {
			$num = ltrim( (string) $row['doc_number'], '0' );
			$k   = (string) $row['doc_kind'];
			if ( isset( $keep[ $num ] ) && ( '' === $k || in_array( $k, $keep[ $num ], true ) ) ) {
				continue;
			}
			$n += (int) $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s, revoked_by = %d WHERE message_id = %d AND doc_number = %s AND doc_kind = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
				self::now(), (int) $actor, (int) $message_id, (string) $row['doc_number'], $k
			) );
		}
		return $n;
	}

	/**
	 * The live shares naming this viewer, number and kind (or not yet settled), newest first, one per sender (at most
	 * five senders). A share of the invoice never answers for the estimate.
	 */
	private static function live_grants( $viewer, $number, $kind ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix plus fixed names; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT g.id, g.doc_kind, g.granted_by, g.conversation_id, g.message_id, g.expires_at
			   FROM ' . self::table() . " g
			   JOIN {$p}zim_messages m ON m.id = g.message_id AND m.deleted_at IS NULL
			   JOIN {$p}zim_members mem ON mem.conversation_id = g.conversation_id AND mem.user_id = g.grantee_user_id
			  WHERE g.grantee_user_id = %d AND g.doc_number = %s AND g.doc_kind IN (%s, '') AND g.revoked_at IS NULL AND g.expires_at > %s
			  ORDER BY g.id DESC LIMIT 200",
			(int) $viewer, (string) $number, (string) $kind, self::now()
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// The newest share from each sender, so five dead shares from one person cannot hide a good one.
		$out  = array();
		$seen = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$k = (int) $r['granted_by'] . '|' . (string) $r['doc_kind'];
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $r;
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/** The person's name as Messages shows it. */
	private static function name( $uid ) {
		$u = get_userdata( (int) $uid );
		return ( $u && isset( $u->display_name ) && '' !== (string) $u->display_name ) ? (string) $u->display_name : __( 'Someone', 'zorderz' );
	}

	private static function iso( $mysql ) {
		$t = $mysql ? strtotime( $mysql . ' UTC' ) : 0;
		return $t ? gmdate( 'c', $t ) : null;
	}

	/**
	 * What the panel and the chip get for #N, for this viewer. Never a document the viewer may
	 * not see. With $message_id and the viewer as its author, `share` says what is shared.
	 *
	 * One kind per answer. $kind is what the chip asks for (the words before the #N); without it, the message's words
	 * decide; with neither, the estimate. The viewer's own right is read for that kind. A share answers only for the
	 * kind it was made for -- a share settled as the invoice never opens for an estimate chip, and a share not yet
	 * settled opens only a kind its OWN message names (never the kind in the request).
	 */
	public static function preview_for( $viewer, $number, $message_id = 0, $kind = '' ) {
		$kind   = in_array( $kind, array( 'estimate', 'invoice' ), true ) ? $kind : '';
		$viewer = (int) $viewer;
		$number = ltrim( preg_replace( '/\D/', '', (string) $number ), '0' );
		$out    = array( 'number' => $number );
		if ( '' === $number || strlen( $number ) > 8 ) {
			return $out + array( 'state' => 'not_found', 'message' => __( 'That is not an estimate or invoice number.', 'zorderz' ) );
		}
		if ( $message_id ) {
			$share = self::status_for_message( (int) $message_id, $viewer );
			if ( $share ) {
				$out['share'] = $share;
			}
		}
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( $viewer ) ) {
			return $out + array( 'state' => 'denied', 'message' => __( 'Estimates and invoices are not opened on the shared device.', 'zorderz' ) );
		}
		if ( ! self::provider_present() ) {
			return $out + array( 'state' => 'unavailable', 'message' => self::no_provider_text() );
		}
		if ( '' === $kind ) {
			$m    = $message_id ? self::message( (int) $message_id ) : null;
			$kind = $m ? self::kind_for_ref( (string) $m['body'], $number ) : 'estimate';
		}
		$down = false;
		$own  = self::read_as( $viewer, $number, $kind );
		if ( ! empty( $own['ok'] ) ) {
			// No "also": the same number in the other series is a different document.
			return $out + array( 'state' => 'ok', 'via' => 'own', 'kind' => (string) $own['kind'], 'doc' => $own['doc'], 'also' => '' );
		}
		$why = (string) ( $own['why'] ?? '' );
		if ( 'no_provider' === $why ) {
			return $out + array( 'state' => 'unavailable', 'message' => self::no_provider_text() );
		}
		if ( 'unavailable' === $why ) {
			// The billing system is not answering: asking again as each sender would only make the person wait.
			return $out + array( 'state' => 'unavailable', 'message' => self::down_text() );
		}
		// Not found for the viewer, or not theirs: a share may still open it.
		foreach ( self::live_grants( $viewer, $number, $kind ) as $g ) {
			$gk = (string) $g['doc_kind'];
			if ( '' === $gk ) {
				// A share not yet settled: its own message decides, never the request.
				$gm    = self::message( (int) $g['message_id'] );
				$named = $gm ? self::kinds_for_ref( (string) $gm['body'], $number ) : array();
				if ( ! in_array( $kind, $named, true ) ) {
					continue;
				}
				if ( 1 === count( $named ) ) {
					self::pin_kind( (int) $g['message_id'], $number, $kind );
				}
			} elseif ( $gk !== $kind ) {
				continue;   // live_grants already filters; kept so a change there cannot open the other document
			}
			$r = self::read_as( (int) $g['granted_by'], $number, $kind, $viewer );
			if ( ! empty( $r['ok'] ) && (string) $r['kind'] === $kind ) {
				return $out + array(
					'state'     => 'ok',
					'via'       => 'shared',
					'kind'      => $kind,
					'doc'       => $r['doc'],
					'also'      => '',   // a share is for one document only
					'shared_by' => self::name( (int) $g['granted_by'] ),
					'until'     => self::iso( $g['expires_at'] ),
				);
			}
			if ( 'unavailable' === ( $r['why'] ?? '' ) ) {
				$down = true;
				break;
			}
		}
		if ( $down ) {
			return $out + array( 'state' => 'unavailable', 'message' => self::down_text() );
		}
		if ( 'not_found' === $why ) {
			$msg = 'invoice' === $kind
				/* translators: %s: a document number */
				? sprintf( __( 'No invoice #%s in the billing system.', 'zorderz' ), $number )
				/* translators: %s: a document number */
				: sprintf( __( 'No estimate #%s in the billing system.', 'zorderz' ), $number );
			return $out + array( 'state' => 'not_found', 'kind' => $kind, 'message' => $msg );
		}
		// Say why when it is not "someone else's" -- the person's own Estimates access is off (Safe Mode, the app
		// not given to them) -- so they and the sender know what to fix. `why` is the viewer's own state.
		if ( in_array( $why, array( 'no_access', 'safe_mode' ), true ) ) {
			return $out + array( 'state' => 'denied', 'why' => $why, 'message' => __( 'Estimates is not turned on for your account, so documents open here only when the sender shares them. Ask the sender, or an administrator.', 'zorderz' ) );
		}
		return $out + array( 'state' => 'denied', 'why' => '' !== $why ? $why : 'not_yours', 'message' => __( 'This document belongs to someone else. Ask the sender to share it.', 'zorderz' ) );
	}

	/** What a person sees while no app can open documents here. */
	private static function no_provider_text() {
		return __( 'Documents open here once the Estimates app can read them.', 'zorderz' );
	}

	/** What a person sees while the billing system does not answer. */
	private static function down_text() {
		return __( 'The billing system could not be read just now. Try again in a minute.', 'zorderz' );
	}

	/** A share whose message names one kind for the number is settled on it at its first open. */
	private static function pin_kind( $message_id, $number, $kind ) {
		global $wpdb;
		if ( ! in_array( $kind, array( 'estimate', 'invoice' ), true ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET doc_kind = %s WHERE message_id = %d AND doc_number = %s AND doc_kind = ''", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			$kind, (int) $message_id, (string) $number
		) );
	}

	/**
	 * For the message's author: what this message shares, with whom, until when.
	 * null for anyone else (others do not see who else a document was shared with).
	 */
	public static function status_for_message( $message_id, $viewer ) {
		global $wpdb;
		$m = self::message( $message_id );
		if ( ! $m || (int) $m['author_user_id'] !== (int) $viewer ) {
			return null;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT doc_number, doc_kind, grantee_user_id, expires_at FROM ' . self::table() . ' WHERE message_id = %d AND revoked_at IS NULL AND expires_at > %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			(int) $message_id, self::now()
		), ARRAY_A );
		$names = array();
		$nums  = array();
		$live  = array();
		$until = '';
		foreach ( (array) $rows as $r ) {
			$names[ (int) $r['grantee_user_id'] ] = self::name( (int) $r['grantee_user_id'] );
			$nums[ (string) $r['doc_number'] ]    = true;
			$live[ (string) $r['doc_number'] . '|' . (string) $r['doc_kind'] ] = true;
			if ( (string) $r['expires_at'] > $until ) {
				$until = (string) $r['expires_at'];
			}
		}
		$deleted = ! empty( $m['deleted_at'] );
		// Numbers in the message not shared now (added by an edit, or unticked) can be shared too.
		$pending = array();
		if ( ! $deleted ) {
			// Per number AND kind -- "estimate #1235" shared, then edited to add "invoice #1235", offers #1235 again
			// for the invoice.
			foreach ( self::refs_in( $m['body'] ) as $n ) {
				foreach ( self::kinds_for_ref( $m['body'], $n ) as $k ) {
					if ( ! isset( $live[ $n . '|' . $k ] ) && ! isset( $live[ $n . '|' ] ) ) {
						$pending[] = (string) $n;
						break;
					}
				}
			}
		}
		return array(
			'message_id' => (int) $m['id'],
			'active'     => (bool) $rows,
			'numbers'    => array_map( 'strval', array_keys( $nums ) ),
			'pending'    => $pending,
			'names'      => array_values( $names ),
			'until'      => $until ? self::iso( $until ) : null,
			'can_share'  => ! $deleted && (bool) self::refs_in( $m['body'] ) && (bool) self::grantees( (int) $m['conversation_id'], (int) $viewer ),
		);
	}

	/** Action-only audit (never a number's customer, never the body). */
	private static function audit( $uid, $slug, $detail, $meta ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( (int) $uid, $slug, $detail, 'zdz-internal-messaging', $meta );
		}
	}
}
