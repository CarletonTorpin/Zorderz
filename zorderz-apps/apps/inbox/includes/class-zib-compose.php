<?php
/**
 * ZIB_Compose — an email that carries Knowledge Vault documents.
 *
 * An email can carry Vault documents whichever Send was pressed — the Email app's composer or the chat's Confirm —
 * and the email the recipient gets is the same either way. A sensitive document (a scanned title, say) goes as an
 * outside link that stops working after 14 or 30 days.
 *
 * So there is ONE routine (ZIB_Gatekeeper::owner_send_compose) behind both Send buttons — the Email app's composer
 * (REST, via 'email') and the chat's Confirm card (the zib_compose_send filter, via 'chat') — and this class is
 * everything that routine needs that is not mail transport:
 *
 *   plan()        what each document becomes, decided on the server and the same for both buttons: a Private
 *                 document only ever as an outside link; an ordinary one attached unless it was asked for as a
 *                 link or the files would pass the size limit (then a link, and the plan says why);
 *   materialize() the bytes read and the links made (through the Vault's own filters - this app never reads the
 *                 Vault's tables or folder; the Vault applies its own rules for this person);
 *   body_html()   the owner's words, then the documents block - one function, so the body is byte-identical.
 *
 * The Vault side: the Knowledge Vault's file filters (zdz_vault_find_for_user, zdz_vault_file_for_user,
 * zdz_vault_file_bytes, zdz_vault_outside_link). Without them every Vault feature here answers "not available".
 *
 * INV-SEND, scoped: the chat may send ONE kind of email - a held document email that a person pressed Confirm on.
 * chat_press() checks the request really is the chat's Confirm press; any other caller of the chat filter is
 * refused and logged. Mail is still never sent by a marker, a cron or a one-click.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZIB_Compose {

	/** At most this many documents on one email. */
	const MAX_DOCS = 5;
	/** A handoff to the Email app lives this long (seconds). */
	const HANDOFF_TTL = 900;
	const HANDOFF_PREFIX = 'zib_ho_';
	/** The admin-ajax action of the chat's Confirm press: the only place the chat may send from. */
	const CHAT_CONFIRM_ACTION = 'wp_ajax_zana_confirm_pending_action';

	public static function boot(): void {
		// With the Email feature dark, the chat is not offered sending at all: no filters, no prompt section.
		if ( class_exists( 'ZIB_Settings' ) && ! ZIB_Settings::feature_enabled() ) { return; }
		add_filter( 'zib_compose_send', array( __CLASS__, 'f_send' ), 10, 3 );       // ( null, int $uid, array $req ) → result
		add_filter( 'zib_compose_plan', array( __CLASS__, 'f_plan' ), 10, 3 );       // ( null, int $uid, array $req ) → plan
		add_filter( 'zib_compose_handoff', array( __CLASS__, 'f_handoff' ), 10, 3 ); // ( null, int $uid, array $req ) → {token}
	}

	/** Is the Knowledge Vault answering? */
	public static function vault_ready(): bool {
		return function_exists( 'has_filter' ) && has_filter( 'zdz_vault_file_for_user' ) && has_filter( 'zdz_vault_outside_link' )
			&& has_filter( 'zdz_vault_file_bytes' );
	}

	/**
	 * The most bytes of attachments on one email; past it a document goes as a link. Filter: zib_attach_max_total.
	 * 10 MB: base64 makes it ~14 MB of mail, under what most receiving servers accept, and the uploads fit the
	 * request's time budget on hosts that end a request at 60 seconds.
	 */
	public static function max_total(): int {
		return max( 0, (int) apply_filters( 'zib_attach_max_total', 10 * 1048576 ) );
	}

	/**
	 * Seconds one compose send may take before it stops short of /send (many hosts end a request at 60). 30, so the
	 * last step (a 25 s Graph call, or an upload chunk capped to the time left) still ends inside the minute.
	 */
	public static function time_budget(): int {
		return max( 10, (int) apply_filters( 'zib_compose_time_budget', 30 ) );
	}

	/** When this request must stop short of /send: counted from the REQUEST's start, not this call's. */
	public static function deadline(): int {
		$start = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
		return min( $start, time() ) + self::time_budget();
	}

	/** [{id, how, days}] from a request: ids > 0, unique, at most MAX_DOCS; how attach|link; days 14|30. */
	public static function normalize_docs( $raw ): array {
		if ( is_string( $raw ) ) {
			$d   = json_decode( $raw, true );
			$raw = is_array( $d ) ? $d : array();
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $d ) {
			if ( ! is_array( $d ) ) { continue; }
			$id = (int) ( $d['id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) { continue; }
			$seen[ $id ] = true;
			$days  = (int) ( $d['days'] ?? 14 );
			$out[] = array(
				'id'   => $id,
				'how'  => ( 'link' === ( $d['how'] ?? '' ) ) ? 'link' : 'attach',
				'days' => in_array( $days, array( 14, 30 ), true ) ? $days : 14,
			);
			if ( count( $out ) >= self::MAX_DOCS ) { break; }
		}
		return $out;
	}

	/**
	 * What each document becomes, for this person. Reads no file and makes no link.
	 * Each: {id, title, name, mime, size, is_private, how (attach|link), days, why ('' | private | too-big)}.
	 *
	 * @return array|WP_Error  codes: vault-missing, doc-not-found, doc-no-share
	 */
	public static function plan( int $uid, array $docs ) {
		if ( ! $docs ) { return array(); }
		if ( ! self::vault_ready() ) { return new WP_Error( 'vault-missing', 'The Knowledge Vault is not available.' ); }
		$cap   = self::max_total();
		$total = 0;
		$out   = array();
		foreach ( $docs as $d ) {
			$f = apply_filters( 'zdz_vault_file_for_user', null, (int) $d['id'], $uid );
			if ( ! is_array( $f ) ) { return new WP_Error( 'doc-not-found', 'A document was not found in the Vault, or you cannot open it.' ); }
			$how = (string) $d['how'];
			$why = '';
			if ( empty( $f['can_attach'] ) ) {
				$how = 'link';
				$why = 'private';
			} elseif ( 'attach' === $how ) {
				$size = (int) ( $f['size'] ?? 0 );
				if ( $size <= 0 || $total + $size > $cap ) {
					$how = 'link';
					$why = 'too-big';
				} else {
					$total += $size;
				}
			}
			if ( 'link' === $how && empty( $f['can_link'] ) ) {
				return new WP_Error( 'doc-no-share', 'You can\'t share one of those documents outside the business.' );
			}
			$out[] = array(
				'id'         => (int) $f['id'],
				'title'      => (string) ( $f['title'] ?? '' ),
				'name'       => (string) ( $f['name'] ?? 'document' ),
				'mime'       => (string) ( $f['mime'] ?? 'application/octet-stream' ),
				'size'       => (int) ( $f['size'] ?? 0 ),
				'is_private' => ! empty( $f['is_private'] ),
				'how'        => $how,
				'days'       => (int) $d['days'],
				'why'        => $why,
			);
		}
		return $out;
	}

	/**
	 * What goes out: every attachment's bytes read FIRST (nothing is changed by a read), then one outside link per
	 * linked document. Returns {links:[{id,title,url,until}], attachments:[{name,mime,bytes}]} or WP_Error.
	 */
	public static function materialize( int $uid, array $plan, string $recipient_label ) {
		$atts = array();
		foreach ( $plan as $p ) {
			if ( 'attach' !== $p['how'] ) { continue; }
			$b = apply_filters( 'zdz_vault_file_bytes', null, (int) $p['id'], $uid, self::max_total() );
			if ( ! is_string( $b ) ) {
				return new WP_Error( 'doc-not-found', 'A document\'s file could not be read from the Vault.' );
			}
			$atts[] = array( 'name' => (string) $p['name'], 'mime' => (string) $p['mime'], 'bytes' => $b );
		}
		$links = array();
		foreach ( $plan as $p ) {
			if ( 'link' !== $p['how'] ) { continue; }
			$l = apply_filters( 'zdz_vault_outside_link', null, (int) $p['id'], $uid, array(
				'days'      => (int) $p['days'],
				'recipient' => mb_substr( $recipient_label, 0, 190 ),
				'source'    => 'email',
			) );
			if ( ! is_array( $l ) || empty( $l['url'] ) ) {
				self::revoke_links( $uid, $links ); // the ones already made for this email go too
				return new WP_Error( 'doc-no-share', 'An outside link could not be made for one of those documents.' );
			}
			$links[] = array(
				'id'         => (int) $p['id'],
				'link_id'    => (int) ( $l['id'] ?? 0 ),   // to stop it again if the email does not go
				'title'      => (string) $p['title'],
				'is_private' => ! empty( $p['is_private'] ),
				'mime'       => (string) $p['mime'],
				'size'       => (int) $p['size'],
				'url'        => (string) $l['url'],
				'until'      => self::until( (string) ( $l['expires_at'] ?? '' ) ),
			);
		}
		return array( 'links' => $links, 'attachments' => $atts );
	}

	/**
	 * Stop the links made for an email that did not go: nobody received them, so they must not stay live for 14 or
	 * 30 days. Through the Vault's own revoke (its rule: the maker may stop their link). Best effort.
	 */
	public static function revoke_links( int $uid, array $links ): int {
		$n = 0;
		if ( ! class_exists( 'ZKV_Ext_Links' ) || ! method_exists( 'ZKV_Ext_Links', 'revoke' ) ) { return 0; }
		foreach ( $links as $l ) {
			if ( ! empty( $l['link_id'] ) && true === ZKV_Ext_Links::revoke( (int) $l['link_id'], $uid ) ) { $n++; }
		}
		return $n;
	}

	/** A Private document's kind, for its neutral label: "PDF", "JPEG image", ... never its title or file name. */
	public static function kind( string $mime ): string {
		$m = strtolower( $mime );
		$map = array( 'application/pdf' => 'PDF', 'image/jpeg' => 'JPEG image', 'image/png' => 'PNG image', 'image/webp' => 'image', 'image/heic' => 'image', 'text/plain' => 'text file' );
		if ( isset( $map[ $m ] ) ) { return $map[ $m ]; }
		return 0 === strpos( $m, 'image/' ) ? 'image' : 'file';
	}

	/** "PDF, 0.9 MB" */
	public static function kind_and_size( string $mime, int $size ): string {
		$k = self::kind( $mime );
		if ( $size <= 0 ) { return $k; }
		return $k . ', ' . ( $size >= 1048576 ? number_format( $size / 1048576, 1 ) . ' MB' : max( 1, (int) round( $size / 1024 ) ) . ' KB' );
	}

	/** "October 29, 2026" in the site's time zone, from a UTC 'Y-m-d H:i:s'. */
	public static function until( string $utc ): string {
		$ts = '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
		if ( ! $ts ) { return ''; }
		return function_exists( 'wp_date' ) ? (string) wp_date( 'F j, Y', $ts ) : gmdate( 'F j, Y', $ts );
	}

	/**
	 * The owner's plain text as a minimal HTML body that keeps the spacing typed (escape, every newline a <br>, one
	 * plain <div>). The one copy of this rule: ZIB_Gatekeeper::text_to_html() calls it too.
	 */
	public static function text_html( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$html = nl2br( esc_html( $text ), false ); // <br>, not <br />
		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;">' . $html . '</div>';
	}

	/**
	 * The documents block under the words: each link, and the day it stops working. An ordinary document by its title;
	 * a Private one only as "Secure document (PDF, 0.9 MB)" — its title would otherwise sit in the owner's Sent Items,
	 * which this app indexes and the chat reads. The recipient sees the title on the link's own page.
	 */
	public static function links_html( array $links ): string {
		if ( ! $links ) { return ''; }
		$rows = '';
		foreach ( $links as $l ) {
			$label = ! empty( $l['is_private'] )
				? 'Secure document (' . self::kind_and_size( (string) ( $l['mime'] ?? '' ), (int) ( $l['size'] ?? 0 ) ) . ')'
				: (string) $l['title'];
			$rows .= '<li style="margin:0 0 6px 0;"><a href="' . esc_url( (string) $l['url'] ) . '">' . esc_html( $label ) . '</a>'
				. ( '' !== (string) $l['until'] ? '<br><span style="font-size:12px;">This link works until ' . esc_html( (string) $l['until'] ) . '.</span>' : '' )
				. '</li>';
		}
		$lead = 1 === count( $links ) ? 'The document:' : 'The documents:';
		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;margin-top:14px;">'
			. '<p style="margin:0 0 6px 0;">' . $lead . '</p><ul style="margin:0;padding-left:18px;">' . $rows . '</ul></div>';
	}

	/** The whole composed body: the words (if any), then the documents block. Same for both Send buttons. */
	public static function body_html( string $text, array $links ): string {
		return ( '' !== trim( $text ) ? self::text_html( $text ) : '' ) . self::links_html( $links );
	}

	/**
	 * True only inside the chat's Confirm press (CHAT_CONFIRM_ACTION) - the one place the chat may send from. A cron,
	 * a REST call, a marker or any other caller of the chat filter is not a person's press.
	 */
	public static function chat_press(): bool {
		// Not during shutdown: after wp_send_json() → wp_die() inside the press, WordPress never pops that hook, so
		// a shutdown callback of the same request would otherwise pass.
		return function_exists( 'doing_action' ) && doing_action( self::CHAT_CONFIRM_ACTION ) && ! doing_action( 'shutdown' );
	}

	// ── the filters the chat calls ──────────────────────────────────────────────

	/** The chat's Confirm: always via 'chat' (whatever the request says), so chat_press() is always checked. */
	public static function f_send( $prev, $uid, $req ) {
		if ( null !== $prev ) { return $prev; }
		return ZIB_Gatekeeper::owner_send_compose( (int) $uid, is_array( $req ) ? $req : array(), 'chat' );
	}

	/**
	 * What would go out, for the chat's preview: {ok, docs:[plan entries], mailbox:bool} or {ok:false, reason}.
	 * Titles are in it: the CALLER keeps a Private one out of anything an AI model reads.
	 */
	public static function f_plan( $prev, $uid, $req ) {
		if ( null !== $prev ) { return $prev; }
		$uid = (int) $uid;
		if ( ! ZIB_Gatekeeper::can_send( $uid ) ) { return array( 'ok' => false, 'reason' => 'not-connected' ); }
		$plan = self::plan( $uid, self::normalize_docs( is_array( $req ) ? ( $req['docs'] ?? array() ) : array() ) );
		if ( is_wp_error( $plan ) ) { return array( 'ok' => false, 'reason' => $plan->get_error_code() ); }
		return array( 'ok' => true, 'docs' => $plan );
	}

	/**
	 * "Open in Email": keep the email for this person for a few minutes and hand back a one-time token; the Email
	 * app asks for it (POST zorderz/v1 …/compose/handoff/<token>) and opens its composer filled in. Nothing is sent: the
	 * owner presses Send there. Only a NEW email is handed off.
	 */
	public static function f_handoff( $prev, $uid, $req ) {
		if ( null !== $prev ) { return $prev; }
		$uid = (int) $uid;
		if ( ! ZIB_Gatekeeper::can_send( $uid ) ) { return new WP_Error( 'not-connected', 'Your email is not connected.' ); }
		$req   = is_array( $req ) ? $req : array();
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::HANDOFF_PREFIX . hash( 'sha256', $token ), array(
			'uid' => $uid,
			'req' => array(
				'mode'    => 'new',
				'to'      => mb_substr( (string) ( $req['to'] ?? '' ), 0, 2000 ),
				'cc'      => mb_substr( (string) ( $req['cc'] ?? '' ), 0, 2000 ),
				'subject' => mb_substr( (string) ( $req['subject'] ?? '' ), 0, 500 ),
				'body'    => mb_substr( (string) ( $req['body'] ?? '' ), 0, 20000 ),
				'docs'    => self::normalize_docs( $req['docs'] ?? array() ),
			),
		), self::HANDOFF_TTL );
		return array( 'token' => $token );
	}

	/** The Email app takes a handoff once, and only the person it was made for. */
	public static function take_handoff( int $uid, string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) { return null; }
		$key = self::HANDOFF_PREFIX . hash( 'sha256', $token );
		$v   = get_transient( $key );
		if ( ! is_array( $v ) || (int) ( $v['uid'] ?? 0 ) !== $uid || $uid <= 0 ) { return null; }
		delete_transient( $key );
		return (array) $v['req'];
	}
}
