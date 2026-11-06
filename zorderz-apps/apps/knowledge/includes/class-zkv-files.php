<?php
/**
 * ZKV_Files — the one way another app asks the Vault for a document's file.
 *
 * An app such as the Inbox attaches a Vault file to a reply, or puts an outside link in it. It never reads the Vault's tables or
 * its folder: it asks through these filters, and the Vault answers with its own rules (the person must be able to
 * open the document; transcripts never leave; a Private document goes only as an outside link).
 *
 *   apply_filters( 'zdz_vault_find_for_user',  null, string $words, int $uid, int $limit )  → list of matches
 *   apply_filters( 'zdz_vault_file_for_user',  null, int $doc_id, int $uid )               → array | WP_Error
 *   apply_filters( 'zdz_vault_file_bytes',     null, int $doc_id, int $uid, int $max )     → string | WP_Error
 *   apply_filters( 'zdz_vault_outside_link',   null, int $doc_id, int $uid, array $args )  → array | WP_Error
 *
 * Finding by words runs here, in the database (title and the person's Context), so a Private document's title is
 * matched on the site and never handed to an AI service to choose from.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Files {

	public static function boot() {
		add_filter( 'zdz_vault_find_for_user', array( __CLASS__, 'f_find' ), 10, 4 );
		add_filter( 'zdz_vault_file_for_user', array( __CLASS__, 'f_file' ), 10, 3 );
		add_filter( 'zdz_vault_file_bytes', array( __CLASS__, 'f_bytes' ), 10, 4 );
		add_filter( 'zdz_vault_outside_link', array( __CLASS__, 'f_link' ), 10, 4 );
	}

	public static function f_find( $prev, $words, $uid, $limit = 5 ) { return null !== $prev ? $prev : self::find( (string) $words, (int) $uid, (int) $limit ); }
	public static function f_file( $prev, $doc_id, $uid ) { return null !== $prev ? $prev : self::for_user( (int) $doc_id, (int) $uid ); }
	public static function f_bytes( $prev, $doc_id, $uid, $max = 3145728 ) { return null !== $prev ? $prev : self::bytes( (int) $doc_id, (int) $uid, (int) $max ); }
	public static function f_link( $prev, $doc_id, $uid, $args = array() ) {
		return null !== $prev ? $prev : ZKV_Ext_Links::create( (int) $doc_id, (int) $uid, is_array( $args ) ? $args : array() );
	}

	/** The person's view of one document (never a transcript), or WP_Error('zkv_not_found'). */
	public static function for_user( $doc_id, $uid ) {
		$doc = class_exists( 'ZKV_Ext_Links' ) ? self::visible( $doc_id, $uid ) : null;
		if ( ! $doc ) { return new WP_Error( 'zkv_not_found', 'That document was not found in the Vault.' ); }
		return array(
			'id'            => (int) $doc['id'],
			'title'         => (string) $doc['title'],
			'name'          => (string) ( $doc['original_name'] ?: 'document' ),
			'mime'          => (string) ( $doc['mime_type'] ?: 'application/octet-stream' ),
			'size'          => (int) $doc['file_size'],
			'is_private'    => ! empty( $doc['is_private'] ),
			'can_attach'    => empty( $doc['is_private'] ),   // a Private document goes as an outside link only
			'can_link'      => null !== ZKV_Ext_Links::shareable_doc( (int) $doc['id'], (int) $uid ),
			'url'           => function_exists( 'zkv_secure_url' ) ? zkv_secure_url( (int) $doc['id'], (string) $doc['slug'] ) : '',
		);
	}

	/**
	 * The file's bytes for an attachment, or WP_Error. Never for a Private document (a sensitive file
	 * travels as a link that expires). $max caps the size (the caller's mail limit).
	 */
	public static function bytes( $doc_id, $uid, $max = 3145728 ) {
		$doc = self::visible( $doc_id, $uid );
		if ( ! $doc ) { return new WP_Error( 'zkv_not_found', 'That document was not found in the Vault.' ); }
		if ( ! empty( $doc['is_private'] ) ) { return new WP_Error( 'zkv_private', 'A Private document is shared as a link that expires, not attached.' ); }
		$path = class_exists( 'ZKV_Viewer' ) ? ZKV_Viewer::locate( $doc ) : '';
		$real = $path ? realpath( $path ) : false;
		if ( ! $real || ! ZKV_File_Names::is_allowed_path( $real ) ) { return new WP_Error( 'zkv_no_file', 'The document\'s file was not found.' ); }
		$size = (int) filesize( $real );
		if ( $max > 0 && $size > $max ) { return new WP_Error( 'zkv_too_big', 'The file is too big to attach.' ); }
		$b = file_get_contents( $real );
		if ( false === $b ) { return new WP_Error( 'zkv_no_file', 'The document\'s file could not be read.' ); }
		if ( class_exists( 'ZKV_Private' ) ) { ZKV_Private::log( 'attached', (int) $doc['id'], (int) $uid ); }
		return $b;
	}

	/**
	 * Documents this person may open whose title or Context match $words (no AI involved), best first. Each:
	 * {id, title, name, mime, size, is_private}. Transcripts are never offered.
	 */
	public static function find( $words, $uid, $limit = 5 ) {
		global $wpdb;
		$uid   = (int) $uid;
		$limit = max( 1, min( 10, (int) $limit ) );
		$words = trim( preg_replace( '/\s+/', ' ', (string) $words ) );
		if ( '' === $words || $uid <= 0 || ! function_exists( 'zkv_user_has_access' ) || ! zkv_user_has_access( $uid ) ) { return array(); }
		$vis = class_exists( 'ZKV_ACL' ) ? ZKV_ACL::sql_where_view( $uid, 'd' ) : " AND d.visibility = 'all_employees'";
		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$terms = array_values( array_filter( array_unique( preg_split( '/[^\p{L}\p{N}-]+/u', $lower( $words ) ) ), function ( $t ) {
			return strlen( $t ) >= 2 && ! in_array( $t, array( 'the', 'a', 'an', 'of', 'for', 'to', 'and', 'my', 'our', 'his', 'her', 'their', 'doc', 'document', 'file', 'copy', 'pdf' ), true );
		} ) );
		if ( ! $terms ) { return array(); }
		$terms = array_slice( $terms, 0, 6 );
		$like = array(); $score = array(); $args = array(); $sargs = array();
		foreach ( $terms as $t ) {
			$like[]  = '( LOWER(d.title) LIKE %s OR LOWER(d.user_context) LIKE %s OR LOWER(d.original_name) LIKE %s )';
			$score[] = '( LOWER(d.title) LIKE %s )';
			$e = '%' . $wpdb->esc_like( $t ) . '%';
			array_push( $args, $e, $e, $e );
			$sargs[] = $e;
		}
		// The best title matches first IN the query.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $vis is ZKV_ACL's fragment (fixed literals and int ids); $like and $score are fixed %s clauses; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.title, d.original_name, d.mime_type, d.file_size, d.is_private, d.user_context
			 FROM {$wpdb->prefix}zkv_documents d
			 WHERE d.status = 'indexed' AND d.visibility <> 'transcript_private' {$vis} AND ( " . implode( ' OR ', $like ) . ' )
			 ORDER BY ( ' . implode( ' + ', $score ) . ' ) DESC, d.updated_at DESC LIMIT 60',
			...array_merge( $args, $sargs )
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$scored = array();
		foreach ( (array) $rows as $r ) {
			$title = $lower( (string) $r['title'] );
			$hay   = $title . ' ' . $lower( (string) $r['user_context'] . ' ' . (string) $r['original_name'] );
			$s = 0;
			foreach ( $terms as $t ) {
				if ( false !== strpos( $title, $t ) ) { $s += 3; } elseif ( false !== strpos( $hay, $t ) ) { $s += 1; }
			}
			$scored[] = array( $s, $r );
		}
		usort( $scored, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		$out = array();
		foreach ( array_slice( $scored, 0, $limit ) as $x ) {
			$r = $x[1];
			$out[] = array(
				'id' => (int) $r['id'], 'title' => (string) $r['title'], 'name' => (string) $r['original_name'],
				'mime' => (string) $r['mime_type'], 'size' => (int) $r['file_size'], 'is_private' => ! empty( $r['is_private'] ),
				'score' => (int) $x[0],
			);
		}
		return $out;
	}

	/** A document this person may open in the Vault (the app gate plus the view rule), never a transcript. */
	private static function visible( $doc_id, $uid ) {
		global $wpdb;
		$uid = (int) $uid;
		if ( $uid <= 0 || ! function_exists( 'zkv_user_has_access' ) || ! zkv_user_has_access( $uid ) ) { return null; }
		$vis = class_exists( 'ZKV_ACL' ) ? ZKV_ACL::sql_where_view( $uid, 'd' ) : " AND d.visibility = 'all_employees'";
		// Phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis is the Vault's own fixed fragment.
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT d.* FROM {$wpdb->prefix}zkv_documents d WHERE d.id = %d {$vis}", (int) $doc_id ), ARRAY_A );
		if ( ! $doc || 'transcript_private' === (string) $doc['visibility'] ) { return null; }
		return $doc;
	}
}
