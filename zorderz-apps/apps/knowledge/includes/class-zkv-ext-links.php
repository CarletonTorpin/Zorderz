<?php
/**
 * ZKV_Ext_Links — "Share outside": a link to one Vault document for someone outside the company.
 *
 * A scanned document sent to a customer or a partner should not be an attachment that lives forever in their
 * mailbox: the recipient gets a link that opens the file for a set number of days (14 or 30 by default, filter
 * zkv_ext_link_days), after which the link no longer works.
 *
 *   /vault/l/<token>         a small page: who shared it, until when, View and Download (no sign-in)
 *   /vault/l/<token>/file    the file (?dl=1 downloads it); a Private document is decrypted on the way out
 *
 * The token is 32 random bytes (base64url, 43 characters); only its SHA-256 is stored, so a database copy cannot
 * open a link. Every request re-checks the row: not stopped, not past expires_at, the document still there. A miss,
 * an expired link and a stopped one all get the same page and the same 404, so a guess learns nothing. Responses carry
 * no-store / no-cache headers (a host's page cache must never keep a copy past a Stop), noindex, no referrer.
 *
 * Who may make one: anyone who may open the document in the Vault; for a Private document only its uploader or an
 * administrator. Transcripts never. Who may stop one: whoever made it, the uploader, or an administrator.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Ext_Links {

	const DAYS    = array( 14, 30 );
	const DEFAULT = 14;
	const RATE_NS = 'zkv-ext';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zkv_ext_links';
	}

	/** dbDelta schema (run by zkv_activate). */
	public static function schema( $charset ) {
		$t = self::table();
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			document_id bigint(20) unsigned NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			recipient varchar(190) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'vault',
			expires_at datetime NOT NULL,
			revoked_at datetime DEFAULT NULL,
			revoked_by bigint(20) unsigned DEFAULT NULL,
			visits int unsigned NOT NULL DEFAULT 0,
			downloads int unsigned NOT NULL DEFAULT 0,
			last_open_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_token (token_hash),
			KEY idx_document (document_id),
			KEY idx_expires (expires_at)
		) {$charset};";
	}

	public static function days( $d ) {
		$d = (int) $d;
		$allowed = array_map( 'intval', (array) apply_filters( 'zkv_ext_link_days', self::DAYS ) );
		return in_array( $d, $allowed, true ) ? $d : self::DEFAULT;
	}

	private static function hash( $token ) {
		return hash( 'sha256', 'zkv-ext-v1|' . (string) $token );
	}

	public static function url( $token ) {
		return home_url( '/vault/l/' . $token );
	}

	// ──────────────────────────────────────────────────────────────
	//  Who may
	// ──────────────────────────────────────────────────────────────

	/** The document row if $uid may share it outside, else null (one answer for missing and not allowed). */
	public static function shareable_doc( $doc_id, $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		if ( $uid <= 0 || ! function_exists( 'zkv_user_has_access' ) || ! zkv_user_has_access( $uid ) ) { return null; }
		$u = get_userdata( $uid );
		if ( ! $u || in_array( 'zdz_general', (array) $u->roles, true ) ) { return null; } // never the shared kiosk login
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return null; // nor any shared device
		}
		$vis = class_exists( 'ZKV_ACL' ) ? ZKV_ACL::sql_where_view( $uid, 'd' ) : " AND d.visibility = 'all_employees'";
		// Phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis is the Vault's own fixed fragment.
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT d.* FROM {$wpdb->prefix}zkv_documents d WHERE d.id = %d {$vis}", (int) $doc_id ), ARRAY_A );
		if ( ! $doc ) { return null; }
		if ( 'transcript_private' === (string) $doc['visibility'] ) { return null; }
		if ( ! empty( $doc['is_private'] ) && (int) $doc['uploaded_by'] !== $uid && ! self::is_admin( $uid ) ) { return null; }
		return $doc;
	}

	public static function is_admin( $uid ) {
		return class_exists( 'ZKV_ACL' ) ? ZKV_ACL::is_admin_user( (int) $uid ) : user_can( (int) $uid, 'manage_options' );
	}

	// ──────────────────────────────────────────────────────────────
	//  Make, list, stop
	// ──────────────────────────────────────────────────────────────

	/**
	 * Make a link. $args: days (14|30), recipient (a label: a name or an e-mail, shown only to staff), source.
	 * Returns array{id, url, expires_at, days} or WP_Error. The token is in the url only - never stored.
	 */
	public static function create( $doc_id, $uid, array $args = array() ) {
		global $wpdb;
		$doc = self::shareable_doc( $doc_id, $uid );
		if ( ! $doc ) { return new WP_Error( 'zkv_not_shareable', 'You can\'t share that document outside.' ); }
		$days      = self::days( $args['days'] ?? self::DEFAULT );
		$recipient = substr( sanitize_text_field( (string) ( $args['recipient'] ?? '' ) ), 0, 190 );
		$source    = preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $args['source'] ?? 'vault' ) ) ) ?: 'vault';
		$token     = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		$ok = $wpdb->insert( self::table(), array(
			'token_hash'  => self::hash( $token ),
			'document_id' => (int) $doc['id'],
			'created_by'  => (int) $uid,
			'recipient'   => $recipient,
			'source'      => substr( $source, 0, 20 ),
			'expires_at'  => $expires,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( ! $ok ) { return new WP_Error( 'zkv_link_failed', 'The link could not be saved.' ); }
		$id = (int) $wpdb->insert_id;
		if ( class_exists( 'ZKV_Private' ) ) { ZKV_Private::log( 'ext_link_made', (int) $doc['id'], (int) $uid, 'link=' . $id . ' days=' . $days ); }
		return array( 'id' => $id, 'url' => self::url( $token ), 'expires_at' => $expires, 'days' => $days, 'document_id' => (int) $doc['id'] );
	}

	/** Every link on a document, newest first, for staff who may see it (never the token); can_stop for $viewer. */
	public static function for_document( $doc_id, $viewer = 0 ) {
		global $wpdb;
		$uploader = (int) $wpdb->get_var( $wpdb->prepare( "SELECT uploaded_by FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $doc_id ) );
		$viewer   = (int) $viewer;
		$is_admin = $viewer > 0 && self::is_admin( $viewer );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, created_by, recipient, source, expires_at, revoked_at, visits, downloads, last_open_at, created_at FROM ' . self::table() . ' WHERE document_id = %d ORDER BY id DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
			(int) $doc_id
		), ARRAY_A );
		$now = gmdate( 'Y-m-d H:i:s' );
		$out = array();
		$may = array();
		foreach ( (array) $rows as $r ) {
			$u = get_userdata( (int) $r['created_by'] );
			$out[] = array(
				'id'           => (int) $r['id'],
				'by'           => $u ? $u->display_name : '',
				'by_id'        => (int) $r['created_by'],
				'recipient'    => (string) $r['recipient'],
				'source'       => (string) $r['source'],
				'expires_at'   => (string) $r['expires_at'],
				'state'        => ! empty( $r['revoked_at'] ) ? 'stopped' : ( $r['expires_at'] <= $now ? 'expired'
					: ( self::maker_still_may( (int) $doc_id, (int) $r['created_by'], $may ) ? 'live' : 'stopped' ) ), // the rule changed (made Private ...)
				'visits'       => (int) $r['visits'],
				'downloads'    => (int) $r['downloads'],
				'last_open_at' => (string) $r['last_open_at'],
				'created_at'   => (string) $r['created_at'],
				'can_stop'     => $viewer > 0 && ( (int) $r['created_by'] === $viewer || $uploader === $viewer || $is_admin ),
			);
		}
		return $out;
	}

	private static function maker_still_may( $doc_id, $maker, array &$cache ) {
		if ( ! isset( $cache[ $maker ] ) ) { $cache[ $maker ] = null !== self::shareable_doc( $doc_id, $maker ); }
		return $cache[ $maker ];
	}

	/** Stop a link: its maker, the document's uploader, or an administrator. Returns true or an error message. */
	public static function revoke( $link_id, $uid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT l.id, l.created_by, l.document_id, l.revoked_at, d.uploaded_by FROM ' . self::table() . " l LEFT JOIN {$wpdb->prefix}zkv_documents d ON d.id = l.document_id WHERE l.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
			(int) $link_id
		), ARRAY_A );
		if ( ! $row ) { return 'That link was not found.'; }
		$uid = (int) $uid;
		if ( (int) $row['created_by'] !== $uid && (int) $row['uploaded_by'] !== $uid && ! self::is_admin( $uid ) ) {
			return 'Only the person who made the link, the uploader or an admin can stop it.';
		}
		if ( empty( $row['revoked_at'] ) ) {
			$wpdb->update( self::table(), array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_by' => $uid ), array( 'id' => (int) $link_id ) );
			if ( class_exists( 'ZKV_Private' ) ) { ZKV_Private::log( 'ext_link_stopped', (int) $row['document_id'], $uid, 'link=' . (int) $link_id ); }
		}
		return true;
	}

	/**
	 * Stop, for good, every live link on a document whose maker may no longer share it (made Private and they are not
	 * its uploader or an admin). Without this, live() only refuses them while the rule holds, so they would work again
	 * if the document were made ordinary again. Returns how many were stopped.
	 */
	public static function revoke_unallowed( $doc_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_by FROM ' . self::table() . ' WHERE document_id = %d AND revoked_at IS NULL', (int) $doc_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
		$n = 0; $may = array();
		foreach ( (array) $rows as $r ) {
			if ( ! self::maker_still_may( (int) $doc_id, (int) $r['created_by'], $may ) ) {
				$wpdb->update( self::table(), array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_by' => 0 ), array( 'id' => (int) $r['id'] ) );
				$n++;
			}
		}
		return $n;
	}

	/** A deleted document takes its links with it. */
	public static function delete_for_document( $doc_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'document_id' => (int) $doc_id ), array( '%d' ) );
	}

	/** The live link row for a token, joined to its document, or null. */
	public static function live( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT l.*, d.title, d.original_name, d.mime_type, d.file_size, d.file_url, d.attachment_id, d.is_private, d.visibility, d.status FROM '
			. self::table() . " l JOIN {$wpdb->prefix}zkv_documents d ON d.id = l.document_id WHERE l.token_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
			self::hash( $token )
		), ARRAY_A );
		if ( ! $row ) { return null; }
		if ( ! empty( $row['revoked_at'] ) ) { return null; }
		if ( strtotime( $row['expires_at'] . ' UTC' ) <= time() ) { return null; }
		if ( 'transcript_private' === (string) $row['visibility'] ) { return null; }
		// The rules that allowed the link must still hold for the person who made it: the document
		// made Private since (and they are not its uploader or an admin), made admin-only, or they lost the Vault or
		// left - the link stops.
		if ( null === self::shareable_doc( (int) $row['document_id'], (int) $row['created_by'] ) ) {
			// ...and stays stopped: it does not come back if the rule later allows it again.
			$wpdb->update( self::table(), array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_by' => 0 ), array( 'id' => (int) $row['id'] ) );
			return null;
		}
		return $row;
	}

	// ──────────────────────────────────────────────────────────────
	//  The public route
	// ──────────────────────────────────────────────────────────────

	public static function rewrite() {
		add_rewrite_rule( '^vault/l/([A-Za-z0-9_-]{43})(/file)?/?$', 'index.php?zkv_ext=$matches[1]&zkv_ext_file=$matches[2]', 'top' );
		add_rewrite_tag( '%zkv_ext%', '([A-Za-z0-9_-]{43})' );
		add_rewrite_tag( '%zkv_ext_file%', '(/file)?' );
	}

	private static function no_cache() {
		if ( headers_sent() ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Frame-Options: DENY', true );
	}

	/** template_redirect at priority 1: before the theme's sign-in gate and before /vault/{slug}. */
	public static function maybe_serve() {
		$token = (string) get_query_var( 'zkv_ext', '' );
		if ( '' === $token ) { return; }
		// Only at /vault/l/... (a ?zkv_ext= on any other address is ignored, so a cache rule for the path covers it).
		$req = (string) parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the path is only compared with a fixed prefix; never stored or printed.
		if ( 0 !== strpos( '/' . ltrim( $req, '/' ), '/vault/l/' ) ) { return; }
		$want_file = '' !== (string) get_query_var( 'zkv_ext_file', '' );
		self::no_cache();
		if ( class_exists( 'ZDZ_Share_Link' ) && ! ZDZ_Share_Link::rate_ok( self::RATE_NS, 60 ) ) {
			self::gone();
		}
		$row = self::live( $token );
		if ( ! $row ) { self::gone(); }
		global $wpdb;
		$path = class_exists( 'ZKV_Viewer' ) ? ZKV_Viewer::locate( $row ) : '';
		$real = $path ? realpath( $path ) : false;
		if ( ! $real || ! class_exists( 'ZKV_File_Names' ) || ! ZKV_File_Names::is_allowed_path( $real ) ) { self::gone(); }

		if ( ! $want_file ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET visits = visits + 1, last_open_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s' ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
			self::page( $row, $token );
		}

		$dl   = isset( $_GET['dl'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- a public link
		$mime = (string) ( $row['mime_type'] ?: 'application/octet-stream' );
		// Only kinds a browser shows safely are shown inline; everything else downloads.
		$inline_ok = in_array( $mime, array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain' ), true );
		$name = sanitize_file_name( (string) ( $row['original_name'] ?: 'document' ) );
		// A Private document opens only with its key - checked before a single header of the file is sent.
		if ( ! empty( $row['is_private'] ) && ( ! class_exists( 'ZKV_Private' ) || ! ZKV_Private::is_encrypted_file( $real ) || null === ZKV_Private::key( false ) ) ) {
			self::gone();
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET downloads = downloads + 1, last_open_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s' ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values go through placeholders.
		status_header( 200 );
		header( 'Content-Type: ' . ( 'text/plain' === $mime ? 'text/plain; charset=utf-8' : $mime ) );
		header( 'Content-Disposition: ' . ( ( $dl || ! $inline_ok ) ? 'attachment' : 'inline' ) . '; filename="' . $name . '"' );
		if ( ! empty( $row['is_private'] ) ) {
			ZKV_Private::stream( $real );
			exit;
		}
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real );
		exit;
	}

	/** One answer for every miss: a wrong token, an expired link, a stopped one, a deleted document. */
	private static function gone() {
		if ( ! headers_sent() ) { header_remove( 'Content-Disposition' ); header_remove( 'Content-Length' ); }
		self::no_cache();
		status_header( 404 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$site = self::site_name();
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Link not available', 'zorderz' ) . '</title>' . self::style() . '</head><body><main class="x">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::style() returns a fixed stylesheet literal; every other part is escaped.
			. '<h1>' . esc_html__( 'This link is no longer available', 'zorderz' ) . '</h1><p>' . esc_html__( 'It may have expired or been stopped. Please ask the person who sent it for a new one.', 'zorderz' ) . '</p>'
			. '<p class="m">' . esc_html( $site ) . '</p></main></body></html>';
		exit;
	}

	private static function page( array $row, $token ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$by    = get_userdata( (int) $row['created_by'] );
		$who   = $by ? $by->display_name : '';
		$site  = self::site_name();
		$ts    = strtotime( $row['expires_at'] . ' UTC' );
		$until = function_exists( 'wp_date' ) ? wp_date( 'F j, Y', $ts ) : gmdate( 'F j, Y', $ts );
		$file  = self::url( $token ) . '/file';
		$size  = function_exists( 'size_format' ) ? size_format( (int) $row['file_size'] ) : '';
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $row['title'] ) . '</title>' . self::style() . '</head><body><main class="x">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::style() returns a fixed stylesheet literal; every other part is escaped.
			. '<p class="m">' . esc_html( '' !== $who
				/* translators: 1: the person who shared, 2: the business name */
				? sprintf( __( '%1$s at %2$s shared a document with you', 'zorderz' ), $who, $site )
				/* translators: %s: the business name */
				: sprintf( __( '%s shared a document with you', 'zorderz' ), $site ) ) . '</p>'
			. '<h1>' . esc_html( $row['title'] ) . '</h1>'
			. '<p class="m">' . esc_html( (string) $row['original_name'] ) . ( $size ? ' · ' . esc_html( $size ) : '' ) . '</p>'
			. '<p class="b"><a class="p" href="' . esc_url( $file ) . '">' . esc_html__( 'View', 'zorderz' ) . '</a> <a href="' . esc_url( $file . '?dl=1' ) . '">' . esc_html__( 'Download', 'zorderz' ) . '</a></p>'
			/* translators: %s: the date the link stops working */
			. '<p class="m">' . esc_html( sprintf( __( 'This link works until %s. Please don\'t forward it.', 'zorderz' ), $until ) ) . '</p>'
			. '</main></body></html>';
		exit;
	}

	/** The business's name (Business Profile), else the site's title. */
	private static function site_name() {
		$n = class_exists( 'ZDZ_Business_Profile' ) ? trim( (string) ZDZ_Business_Profile::name() ) : '';
		return '' !== $n ? $n : (string) get_bloginfo( 'name' );
	}

	/**
	 * The page stands alone (no theme), so it uses the browser's own system colors: light or dark follows the
	 * recipient's device, with no color of ours to keep in step.
	 */
	private static function style() {
		return '<style>:root{color-scheme:light dark}body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:Canvas;color:CanvasText}'
			. '.x{max-width:560px;margin:0 auto;padding:40px 16px}h1{font-size:22px;line-height:1.3;margin:6px 0 4px}'
			. '.m{color:GrayText;font-size:14px;margin:6px 0}.b{margin:22px 0}.b a{display:inline-block;padding:10px 18px;margin:0 8px 8px 0;'
			. 'border-radius:8px;border:1px solid GrayText;color:CanvasText;text-decoration:none;font-weight:600}.b a.p{background:LinkText;border-color:LinkText;color:Canvas}</style>';
	}
}
