<?php
/**
 * ZKV_Private — Private documents.
 *
 * A knowledge vault must not become the one place an intruder goes for a business's most
 * sensitive papers, so a person can mark a document Private: a switch per document, off by
 * default, with the same audience choices as any other document, and a recovery code an
 * administrator prints.
 *
 * A Private document:
 *   - is never sent to any AI service (no title suggestion, no index, no page images, never another upload's
 *     "documents in this category" context, never chat);
 *   - keeps its file ENCRYPTED on disk (libsodium secretstream, XChaCha20-Poly1305, 64 KiB chunks) and no plain
 *     copy anywhere: WordPress's media copy and its thumbnails are removed, and no extracted text is stored;
 *   - is found by its title and the Context the person typed (both stay readable on purpose — the form says so).
 *
 * The key. One random 32-byte data key, kept in the option zkv_private_key WRAPPED (secretbox) by a key derived
 * from the site's own secret keys in wp-config.php (or ZKV_PRIVATE_KEK, 64 hex, if defined there). A database copy
 * alone therefore cannot open a Private file. If the site's keys are ever reset, the wrap no longer opens: the Vault
 * says "locked" and an administrator types the recovery code (the data key in base32), which is checked against a
 * stored fingerprint and wrapped again with the new site keys. The code is the data key itself — whoever holds it
 * holds every Private file; it is shown only to WordPress administrators and every showing is logged.
 *
 * Fails closed everywhere: no sodium, no key, a failed check -> the document is not made Private (it stays as it
 * was) and nothing is decrypted.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Private {

	const OPT_KEY    = 'zkv_private_key';        // {v, w (base64 nonce|box), fp, created}
	const OPT_SAVED  = 'zkv_private_code_saved'; // when an admin said the code is written down
	const MAGIC      = 'ZKVPF1';                  // file header, then the 24-byte secretstream header
	const CHUNK      = 65536;
	const EXT        = 'zkvp';
	const TEXT_CAP   = 200000;                    // the viewer's text view, as ZKV_Viewer::TEXT_CAP

	/** @var string|null|false the unwrapped data key this request (false = tried, locked) */
	private static $dek = null;

	// ──────────────────────────────────────────────────────────────
	//  Is it private?
	// ──────────────────────────────────────────────────────────────

	/** @param array|int $doc a document row or its id */
	public static function is_private( $doc ) {
		if ( is_array( $doc ) ) {
			return ! empty( $doc['is_private'] );
		}
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT is_private FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $doc
		) );
	}

	/** SQL fragment: never a Private document (the AI-facing queries). */
	public static function sql_not_private( $alias = 'd' ) {
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		return ' AND ' . ( '' !== $alias ? $alias : 'd' ) . '.is_private = 0';
	}

	/**
	 * Is a file on disk one of ours (encrypted)? Our name (.zkvp, never an upload extension) AND our header - an
	 * uploaded file that merely starts with the same six bytes is not mistaken for an encrypted one.
	 */
	public static function is_encrypted_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) { return false; }
		if ( '.' . self::EXT !== substr( $path, -( strlen( self::EXT ) + 1 ) ) ) { return false; }
		$h = @fopen( $path, 'rb' );
		if ( ! $h ) { return false; }
		$m = fread( $h, strlen( self::MAGIC ) );
		fclose( $h );
		return self::MAGIC === $m;
	}

	/**
	 * Can this site keep documents Private? libsodium, and the site's secret keys defined in wp-config.php (or
	 * ZKV_PRIVATE_KEK): without them WordPress keeps its salts in the database, and a database copy alone would then
	 * open the files.
	 */
	public static function available() {
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return false;
		}
		if ( defined( 'ZKV_PRIVATE_KEK' ) && is_string( ZKV_PRIVATE_KEK ) && 1 === preg_match( '/^[0-9a-f]{64}$/i', ZKV_PRIVATE_KEK ) ) {
			return true;
		}
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $c ) {
			if ( ! defined( $c ) || strlen( (string) constant( $c ) ) < 32 || 'put your unique phrase here' === constant( $c ) ) { return false; }
		}
		return true;
	}

	/** Why available() is false, in words for the screen. */
	public static function unavailable_reason() {
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'This server cannot encrypt (PHP sodium is missing).';
		}
		return 'This site\'s secret keys are not set in wp-config.php (AUTH_KEY, AUTH_SALT, SECURE_AUTH_KEY, SECURE_AUTH_SALT), so Private documents cannot be kept safely.';
	}

	/**
	 * The stored key record. From the options API, and straight from the table when the API says there is none (its
	 * cache can be behind another request that has just made the key).
	 */
	public static function record() {
		$rec = get_option( self::OPT_KEY, null );
		if ( is_array( $rec ) && ! empty( $rec['w'] ) ) { return $rec; }
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_KEY ) );
		if ( null === $raw ) { return null; }
		$rec = maybe_unserialize( $raw );
		return ( is_array( $rec ) && ! empty( $rec['w'] ) ) ? $rec : null;
	}

	// ──────────────────────────────────────────────────────────────
	//  The key
	// ──────────────────────────────────────────────────────────────

	/** The key-encryption key: the site's own secrets (wp-config), never stored. */
	private static function kek() {
		if ( defined( 'ZKV_PRIVATE_KEK' ) && is_string( ZKV_PRIVATE_KEK ) && 1 === preg_match( '/^[0-9a-f]{64}$/i', ZKV_PRIVATE_KEK ) ) {
			return hex2bin( ZKV_PRIVATE_KEK );
		}
		return hash( 'sha256', 'zkv-private-kek-v1|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	private static function fingerprint( $dek ) {
		return substr( hash( 'sha256', 'zkv-private-fp-v1|' . $dek ), 0, 16 );
	}

	private static function wrap( $dek ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return array(
			'v'       => 1,
			'w'       => base64_encode( $nonce . sodium_crypto_secretbox( $dek, $nonce, self::kek() ) ),
			'fp'      => self::fingerprint( $dek ),
			'created' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** 'none' (never made), 'ready', or 'locked' (the site's keys changed; the recovery code opens it). */
	public static function key_state() {
		if ( ! self::available() ) { return 'unavailable'; }
		if ( null === self::record() ) { return 'none'; }
		return null !== self::key( false ) ? 'ready' : 'locked';
	}

	/**
	 * The data key, or null. $create = true makes one the first time (the first document made Private).
	 * Never logged, never returned to a browser except as the recovery code (recovery_code()).
	 */
	public static function key( $create = false ) {
		if ( is_string( self::$dek ) ) { return self::$dek; }
		if ( false === self::$dek && ! $create ) { return null; }
		if ( ! self::available() ) { self::$dek = false; return null; }
		$rec = self::record();
		if ( null !== $rec ) {
			$raw = base64_decode( (string) $rec['w'], true );
			if ( false !== $raw && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				$dek = sodium_crypto_secretbox_open(
					substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
					substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
					self::kek()
				);
				if ( is_string( $dek ) && 32 === strlen( $dek ) && hash_equals( (string) ( $rec['fp'] ?? '' ), self::fingerprint( $dek ) ) ) {
					self::$dek = $dek;
					return $dek;
				}
			}
			self::$dek = false; // a key exists and will not open: LOCKED. Never replaced by a new one.
			return null;
		}
		if ( ! $create ) { return null; }
		// The first key. Two first-time requests at once must not each keep a different key: WordPress's add_option
		// is an INSERT ... ON DUPLICATE KEY UPDATE and would let the second replace the first, losing
		// every file the first had already encrypted. So: a database lock, INSERT IGNORE, and then whatever is stored.
		global $wpdb;
		$locked = '1' === (string) $wpdb->get_var( "SELECT GET_LOCK('zkv_private_key', 10)" );
		$dek    = random_bytes( 32 );
		$ins    = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::OPT_KEY, maybe_serialize( self::wrap( $dek ) )
		) );
		if ( $locked ) { $wpdb->get_var( "SELECT RELEASE_LOCK('zkv_private_key')" ); }
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( self::OPT_KEY, 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
		if ( 1 === (int) $ins ) { delete_option( self::OPT_SAVED ); }
		// Use what the table holds - ours, or the one another request stored a moment before.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_KEY ) );
		$rec = null === $raw ? null : maybe_unserialize( $raw );
		if ( ! is_array( $rec ) || empty( $rec['w'] ) ) { self::$dek = null; return null; }
		if ( hash_equals( (string) $rec['fp'], self::fingerprint( $dek ) ) ) { self::$dek = $dek; return $dek; }
		self::$dek = null;
		return self::key( false );
	}

	/** Base32 (RFC 4648, no padding) of the data key, in groups of four. Admins only (the caller checks). */
	public static function recovery_code() {
		$dek = self::key( false );
		if ( null === $dek ) { return ''; }
		return trim( chunk_split( self::b32( $dek ), 4, ' ' ) );
	}

	/**
	 * Unlock with the recovery code after the site's keys changed: the code must match the stored fingerprint; then
	 * the key is wrapped again with today's site keys. Returns true, or an error message.
	 */
	public static function unlock( $code ) {
		if ( ! self::available() ) { return self::unavailable_reason(); }
		$rec = self::record();
		if ( ! is_array( $rec ) || empty( $rec['fp'] ) ) { return 'There is no Private key on this site yet.'; }
		$dek = self::b32_decode( (string) $code );
		if ( 32 !== strlen( (string) $dek ) || ! hash_equals( (string) $rec['fp'], self::fingerprint( $dek ) ) ) {
			return 'That recovery code does not match this site\'s Private documents.';
		}
		update_option( self::OPT_KEY, self::wrap( $dek ), 'no' );
		self::$dek = $dek;
		return true;
	}

	public static function code_saved() {
		return '' !== (string) get_option( self::OPT_SAVED, '' );
	}

	public static function mark_code_saved( $uid ) {
		update_option( self::OPT_SAVED, gmdate( 'Y-m-d H:i:s' ) . '|' . (int) $uid, 'no' );
	}

	private static function b32( $bin ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach ( str_split( $bin ) as $c ) { $bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT ); }
		$out = '';
		foreach ( str_split( $bits, 5 ) as $grp ) { $out .= $alpha[ bindec( str_pad( $grp, 5, '0', STR_PAD_RIGHT ) ) ]; }
		return $out;
	}

	private static function b32_decode( $s ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		// Look-alikes first (a printed 0 read as O, 1 as I, 8 as B), then everything that is not the alphabet goes
		//.
		$s = strtr( strtoupper( (string) $s ), array( '0' => 'O', '1' => 'I', '8' => 'B' ) );
		$s = preg_replace( '/[^A-Z2-7]/', '', $s );
		$bits = '';
		foreach ( str_split( $s ) as $c ) {
			$i = strpos( $alpha, $c );
			if ( false === $i ) { return ''; }
			$bits .= str_pad( decbin( $i ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) { $out .= chr( bindec( $byte ) ); }
		}
		return $out;
	}

	// ──────────────────────────────────────────────────────────────
	//  Files
	// ──────────────────────────────────────────────────────────────

	/** A fresh random file name in the Vault folder for an encrypted file. */
	public static function new_path() {
		return rtrim( ZKV_VAULT_DIR, '/' ) . '/' . bin2hex( random_bytes( 16 ) ) . '.' . self::EXT;
	}

	/**
	 * Encrypt $plain_path into a new file; returns the new path or '' (nothing written then). The plain file is left
	 * where it is - the caller removes it only after the row points at the new one.
	 */
	public static function encrypt_file( $plain_path ) {
		$key = self::key( true );
		if ( null === $key || ! is_readable( $plain_path ) ) { return ''; }
		$in = @fopen( $plain_path, 'rb' );
		if ( ! $in ) { return ''; }
		return self::encrypt_stream( function () use ( $in ) {
			return feof( $in ) ? '' : (string) fread( $in, self::CHUNK );
		}, $key, function () use ( $in ) { fclose( $in ); } );
	}

	/** Encrypt a string (pasted text) into a new file; returns the path or ''. */
	public static function encrypt_string( $plain ) {
		$key = self::key( true );
		if ( null === $key ) { return ''; }
		$parts = str_split( (string) $plain, self::CHUNK );
		if ( array() === $parts || array( '' ) === $parts ) { $parts = array( '' ); }
		$i = 0;
		return self::encrypt_stream( function () use ( &$parts, &$i ) {
			return $i < count( $parts ) ? $parts[ $i++ ] : '';
		}, $key, null );
	}

	private static function encrypt_stream( callable $next, $key, $close ) {
		$path = self::new_path();
		$out  = @fopen( $path, 'xb' );
		if ( ! $out ) { if ( $close ) { $close(); } return ''; }
		$ok = true;
		try {
			list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
			$ok = strlen( self::MAGIC . $header ) === fwrite( $out, self::MAGIC . $header );
			$cur = $next();
			while ( $ok ) {
				$nxt  = $next();
				$last = ( '' === $nxt );
				$c    = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $cur, '',
					$last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
				$ok = strlen( $c ) === fwrite( $out, $c ); // a short write (a full disk) is a failure
				if ( $last ) { break; }
				$cur = $nxt;
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		$ok = fflush( $out ) && $ok;
		$ok = fclose( $out ) && $ok;
		if ( $close ) { $close(); }
		if ( ! $ok ) { @unlink( $path ); return ''; }
		return $path;
	}

	/** Does the encrypted file open to bytes with this SHA-256? */
	public static function verify_hash( $enc, $sha256 ) {
		$ctx = hash_init( 'sha256' );
		$n = self::decrypt_to( $enc, function ( $p ) use ( $ctx ) { hash_update( $ctx, $p ); } );
		return $n >= 0 && '' !== (string) $sha256 && hash_equals( (string) $sha256, hash_final( $ctx ) );
	}

	/** Does the encrypted file open to exactly the bytes of $plain_path? (Checked before any plain copy is removed.) */
	public static function verify( $enc, $plain_path ) {
		$ctx = hash_init( 'sha256' );
		$n = self::decrypt_to( $enc, function ( $p ) use ( $ctx ) { hash_update( $ctx, $p ); } );
		return $n >= 0 && is_readable( $plain_path ) && hash_equals( (string) hash_file( 'sha256', $plain_path ), hash_final( $ctx ) );
	}

	/**
	 * Decrypt a Private file chunk by chunk to $emit (a callable taking a string). Returns the number of plain bytes,
	 * or -1 when the key is locked or the file is damaged / not ours (anything already emitted stops there).
	 */
	public static function decrypt_to( $path, callable $emit ) {
		$key = self::key( false );
		if ( null === $key ) { return -1; }
		$in = @fopen( $path, 'rb' );
		if ( ! $in ) { return -1; }
		$total = -1;
		try {
			$head = fread( $in, strlen( self::MAGIC ) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
			if ( strlen( (string) $head ) === strlen( self::MAGIC ) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
				&& 0 === strpos( $head, self::MAGIC ) ) {
				$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $head, strlen( self::MAGIC ) ), $key );
				$n = 0; $final = false;
				while ( ! feof( $in ) ) {
					$c = fread( $in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES );
					if ( '' === $c || false === $c ) { break; }
					$r = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $c );
					if ( false === $r ) { $n = -1; break; }
					list( $plain, $tag ) = $r;
					$emit( $plain );
					$n += strlen( $plain );
					if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag ) { $final = true; break; }
				}
				$total = ( $n >= 0 && $final ) ? $n : -1; // a file cut short never reads as whole
			}
		} catch ( \Throwable $e ) {
			$total = -1;
		}
		fclose( $in );
		return $total;
	}

	/** The whole plain text of a Private file up to $cap bytes, or null (locked, damaged, or over the cap). */
	public static function decrypt_to_string( $path, $cap = 52428800 ) {
		$buf = '';
		$over = false;
		$n = self::decrypt_to( $path, function ( $p ) use ( &$buf, &$over, $cap ) {
			if ( $over ) { return; }
			if ( strlen( $buf ) + strlen( $p ) > $cap ) { $over = true; return; }
			$buf .= $p;
		} );
		return ( $n < 0 || $over ) ? null : $buf;
	}

	/** Stream a Private file to the browser (headers already sent by the caller, without Content-Length). */
	public static function stream( $path ) {
		while ( ob_get_level() > 0 ) { @ob_end_clean(); }
		return self::decrypt_to( $path, function ( $p ) { echo $p; flush(); } ); // phpcs:ignore -- file bytes
	}

	// ──────────────────────────────────────────────────────────────
	//  Making a document Private, and back
	// ──────────────────────────────────────────────────────────────

	/**
	 * Make a document Private. In this order, so that no AI run can start on it once this begins and no plain copy is
	 * left when it ends:
	 *   1. the flag first, in one UPDATE that refuses while an indexing run holds the document (an indexing run
	 *      claims it the same way - ZKV_Indexer::process_document), and a note of the plain copies to remove;
	 *   2. encrypt, and check the encrypted copy opens to exactly the original bytes;
	 *   3. point the row at it; then remove the plain file, the media copy and its sizes, the chunks, the AI index,
	 *      transcript lines and the chat's caches. A request that dies after 1 leaves the note: the hourly sweep
	 *      (sweep()) finishes the job or puts the flag back.
	 * Returns true or an error message; on an error nothing has changed.
	 */
	public static function make_private( $doc_id, $uid ) {
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $doc_id ), ARRAY_A );
		if ( ! $doc ) { return 'Document not found.'; }
		if ( ! empty( $doc['is_private'] ) ) {
			return self::finish_private( $doc ); // already: make sure no plain copy is left
		}
		if ( class_exists( 'ZKV_ACL' ) && ZKV_ACL::is_transcript_visibility( $doc['visibility'] ) ) {
			return 'A private transcript cannot also be made Private yet.';
		}
		if ( ! self::available() ) { return self::unavailable_reason() . ' Nothing was changed.'; }
		if ( null === self::key( true ) ) { return 'The Private key is locked. An administrator must enter the recovery code first.'; }
		$plain = class_exists( 'ZKV_Viewer' ) ? ZKV_Viewer::locate( $doc ) : ZKV_Indexer::resolve_file_path_public( $doc );
		if ( '' === (string) $plain || ! is_readable( $plain ) ) { return 'The document\'s file was not found, so it could not be encrypted.'; }
		$want = (string) hash_file( 'sha256', $plain ); // the bytes we promise to keep, read BEFORE anything changes
		// 1. The flag AND status 'encrypting', in one UPDATE that refuses while an indexing run holds the document
		//    (processing, touched in the last 5 minutes). While 'encrypting', nothing else touches the document: an
		//    indexing run's claim fails (is_private), finish_private leaves it to this request,
		//    re-index refuses. A note for the sweep in case this request dies.
		self::pending_add( (int) $doc_id, (string) $plain, (int) $doc['attachment_id'] );
		$stale = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - 300 ); // updated_at is site time, as current_time('mysql')
		$got = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}zkv_documents SET is_private = 1, is_pricing_authority = 0, status = 'encrypting', updated_at = %s
			 WHERE id = %d AND is_private = 0 AND status <> 'encrypting' AND ( status <> 'processing' OR updated_at < %s )",
			current_time( 'mysql' ), (int) $doc_id, $stale
		) );
		if ( 1 !== (int) $got ) {
			self::pending_drop( (int) $doc_id );
			return 'The AI is reading this document right now. Try again in a minute.';
		}
		$prev_status = (string) $doc['status'];
		$stored      = (string) $doc['file_url']; // what the row names (a path, an old URL - not always what locate() found)
		// Undo only while the row is still ours and still names what it named before (never an encrypted file); the
		// note goes only when the undo really happened - otherwise the sweep finishes the job.
		$undo = function () use ( $wpdb, $doc_id, $stored, $prev_status ) {
			$n = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}zkv_documents SET is_private = 0, status = %s WHERE id = %d AND status = 'encrypting' AND file_url = %s",
				'encrypting' === $prev_status ? 'pending' : $prev_status, (int) $doc_id, $stored
			) );
			if ( 1 === (int) $n ) { self::pending_drop( (int) $doc_id ); }
		};
		// 2. Encrypt, and check the encrypted copy opens to the bytes hashed above.
		$enc = self::encrypt_file( $plain );
		if ( '' === $enc ) { $undo(); return 'The file could not be encrypted. Nothing was changed.'; }
		if ( ! self::verify_hash( $enc, $want ) ) {
			@unlink( $enc ); $undo();
			return 'The encrypted copy did not check out. Nothing was changed.';
		}
		// 3. The row points at the encrypted file; then the plain copies go.
		$ok = $wpdb->update( $wpdb->prefix . 'zkv_documents', array(
			'file_url'      => $enc,
			'attachment_id' => 0,
			'file_hash'     => '',
			'updated_at'    => current_time( 'mysql' ),
		), array( 'id' => (int) $doc_id ) );
		if ( false === $ok ) { @unlink( $enc ); $undo(); return 'That could not be saved. Nothing was changed.'; }
		self::remove_plain( (int) $doc_id, (string) $plain, (int) $doc['attachment_id'], $enc );
		$doc['is_private'] = 1; $doc['file_url'] = $enc; $doc['attachment_id'] = 0;
		$r = self::finish_private( $doc, true );
		if ( true === $r ) { self::pending_drop( (int) $doc_id ); } // otherwise the sweep finishes it
		// Links made by someone who may not share a Private document stop for good: they must not come
		// back if the document is ever made ordinary again.
		if ( class_exists( 'ZKV_Ext_Links' ) ) { ZKV_Ext_Links::revoke_unallowed( (int) $doc_id ); }
		self::log( 'made_private', (int) $doc_id, (int) $uid );
		return $r;
	}

	/** The plain file (unless another document names it) and the media copy with every size WordPress made. */
	private static function remove_plain( $doc_id, $plain, $att_id, $keep ) {
		if ( $att_id > 0 ) { wp_delete_attachment( (int) $att_id, true ); }
		if ( '' !== (string) $plain && is_file( $plain ) && $plain !== $keep ) { self::delete_plain( $plain, (int) $doc_id ); }
	}

	/**
	 * Every plain trace of a Private document goes, and its index row is rebuilt from the person's own words only.
	 * Also the one way a Private document is "indexed" (ZKV_Indexer::process_document routes here). Returns true or
	 * an error message (then the document is marked failed and nothing plain has been removed).
	 */
	public static function finish_private( array $doc, $owner = false ) {
		global $wpdb;
		$id = (int) $doc['id'];
		// A "make Private" is under way in another request: that request owns the document until it is done or the
		// sweep takes over (10 minutes). An indexing run arriving now changes nothing.
		if ( ! $owner ) {
			$st = $wpdb->get_row( $wpdb->prepare( "SELECT status, updated_at FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $id ), ARRAY_A );
			if ( $st && 'encrypting' === $st['status'] && strtotime( (string) $st['updated_at'] . ' UTC' ) > strtotime( current_time( 'mysql' ) . ' UTC' ) - 600 ) {
				return true;
			}
		}
		// A file that is still plain (a mail-in or upload that arrived Private): encrypt it, check it, then remove it.
		$cur = (string) ( $doc['file_url'] ?? '' );
		if ( '' !== $cur && is_file( $cur ) && ! self::is_encrypted_file( $cur ) ) {
			$enc = self::encrypt_file( $cur );
			if ( '' === $enc || ! self::verify( $enc, $cur ) ) {
				if ( '' !== $enc ) { @unlink( $enc ); }
				$wpdb->update( $wpdb->prefix . 'zkv_documents', array( 'status' => 'failed', 'processing_error' => 'Could not encrypt this Private document.', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
				return 'Could not encrypt this Private document.';
			}
			if ( false === $wpdb->update( $wpdb->prefix . 'zkv_documents', array( 'file_url' => $enc, 'attachment_id' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ) ) {
				@unlink( $enc );
				return 'That could not be saved.';
			}
			self::remove_plain( $id, $cur, (int) ( $doc['attachment_id'] ?? 0 ), $enc );
		}
		$wpdb->delete( $wpdb->prefix . 'zkv_chunks', array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_index', array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_transcript_lines', array( 'document_id' => $id ), array( '%d' ) );
		$cat = '';
		if ( ! empty( $doc['category_id'] ) ) {
			$cat = (string) $wpdb->get_var( $wpdb->prepare( "SELECT label FROM {$wpdb->prefix}zkv_categories WHERE id = %d", (int) $doc['category_id'] ) );
		}
		$search = trim( implode( ' ', array_filter( array( $doc['title'] ?? '', $doc['description'] ?? '', $doc['user_context'] ?? '', $cat ) ) ) );
		$wpdb->insert( $wpdb->prefix . 'zkv_index', array(
			'document_id'   => $id,
			'version'       => (int) ( $doc['version'] ?? 1 ),
			'is_current'    => 1,
			'summary_json'  => '{}',
			'synopsis'      => '',
			'key_entities'  => '{}',
			'key_facts'     => '[]',
			'document_type' => 'private',
			'tags'          => '',
			'search_text'   => '' !== $search ? $search : 'private',
			'tokens_used'   => 0,
			'model_used'    => 'none (private)',
			'created_at'    => current_time( 'mysql' ),
		) );
		// The file_hash goes too: a duplicate check must not answer "already here as <title>" about a Private document.
		$fin = $wpdb->update( $wpdb->prefix . 'zkv_documents', array(
			'status' => 'indexed', 'processing_error' => null, 'is_pricing_authority' => 0, 'file_hash' => '',
			'indexed_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $id ) );
		self::forget_caches( $id );
		return false === $fin ? 'The document\'s status could not be saved.' : true;
	}

	/**
	 * Back to an ordinary document: decrypt to a new plain file (every byte written and the count checked), point the
	 * row at it, and only then remove the encrypted one; index it the normal way - which sends it to the AI service
	 * (the screen asked first). Returns true or a message; on an error nothing has changed.
	 */
	public static function make_ordinary( $doc_id, $uid ) {
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $doc_id ), ARRAY_A );
		if ( ! $doc ) { return 'Document not found.'; }
		if ( empty( $doc['is_private'] ) ) { return true; }
		$enc = (string) $doc['file_url'];
		if ( ! self::is_encrypted_file( $enc ) ) { return 'The encrypted file was not found.'; }
		$plain = rtrim( ZKV_VAULT_DIR, '/' ) . '/' . ( class_exists( 'ZKV_File_Names' ) ? ZKV_File_Names::random_name( (string) $doc['original_name'] ) : bin2hex( random_bytes( 16 ) ) );
		$out = @fopen( $plain, 'xb' );
		if ( ! $out ) { return 'Could not write the file.'; }
		$written = 0; $short = false;
		$n = self::decrypt_to( $enc, function ( $p ) use ( $out, &$written, &$short ) {
			$w = fwrite( $out, $p );
			if ( strlen( $p ) !== $w ) { $short = true; }
			$written += (int) $w;
		} );
		$closed = fflush( $out ) && fclose( $out );
		if ( $n < 0 || $short || ! $closed || $written !== $n || filesize( $plain ) !== $n ) {
			@unlink( $plain );
			return $n < 0 ? 'The Private key is locked or the file is damaged. Nothing was changed.' : 'The file could not be written in full (is the disk full?). Nothing was changed.';
		}
		$ok = $wpdb->update( $wpdb->prefix . 'zkv_documents', array(
			'is_private' => 0, 'file_url' => $plain, 'file_hash' => hash_file( 'sha256', $plain ), 'status' => 'pending', 'updated_at' => current_time( 'mysql' ),
		), array( 'id' => (int) $doc_id ) );
		if ( false === $ok ) { @unlink( $plain ); return 'That could not be saved. Nothing was changed.'; }
		@unlink( $enc );
		$wpdb->delete( $wpdb->prefix . 'zkv_index', array( 'document_id' => (int) $doc_id ), array( '%d' ) );
		self::log( 'made_ordinary', (int) $doc_id, (int) $uid );
		wp_schedule_single_event( time(), 'zkv_process_pending_doc', array( (int) $doc_id ) );
		self::forget_caches( (int) $doc_id );
		return true;
	}

	// ──────────────────────────────────────────────────────────────
	//  What a dying request leaves behind - noted, and swept hourly
	// ──────────────────────────────────────────────────────────────

	// One option row per entry.
	const PEND_PREFIX = 'zkv_pend_';  // zkv_pend_<doc id> => {plain, att, at}: plain copies to remove after "make Private"
	const TEMP_PREFIX = 'zkv_tmpf_';  // zkv_tmpf_<md5 of path> => {path, at}: the upload form's AI-step files

	private static function pending_add( $doc_id, $plain, $att ) {
		update_option( self::PEND_PREFIX . (int) $doc_id, array( 'plain' => (string) $plain, 'att' => (int) $att, 'at' => time() ), 'no' );
	}

	private static function pending_drop( $doc_id ) {
		delete_option( self::PEND_PREFIX . (int) $doc_id );
	}

	/** A file the upload form's AI step wrote (preanalyze); claimed by an upload, or swept after an hour. */
	public static function temp_add( $path ) {
		update_option( self::TEMP_PREFIX . md5( (string) $path ), array( 'path' => (string) $path, 'at' => time() ), 'no' );
	}

	public static function temp_claim( $path ) {
		delete_option( self::TEMP_PREFIX . md5( (string) $path ) );
	}

	/** Remove a temp file now (the form was cleared or closed) - only if no document names it. */
	public static function temp_discard( $path ) {
		global $wpdb;
		$path = (string) $path;
		self::temp_claim( $path );
		$named = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE file_url = %s", $path ) );
		$real  = realpath( $path );
		if ( 0 === $named && $real && 0 === strpos( $real, rtrim( (string) realpath( ZKV_VAULT_DIR ), '/' ) . '/' ) ) {
			@unlink( $real );
		}
	}

	/** Every row of one kind of note: [ option name => value ]. Read from the table (they are never autoloaded). */
	private static function notes( $prefix ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 2000",
			$wpdb->esc_like( $prefix ) . '%'
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) { $v = maybe_unserialize( $r['option_value'] ); if ( is_array( $v ) ) { $out[ $r['option_name'] ] = $v; } }
		return $out;
	}

	/**
	 * Hourly (zkv_private_sweep): finish a "make Private" a request did not (its plain copies), or put the flag back
	 * when the file was never encrypted; and remove the upload form's abandoned temp files (an hour old, named by no
	 * document). Returns what it did, for the log and the tests.
	 */
	public static function sweep( $now = null ) {
		global $wpdb;
		$now  = null === $now ? time() : (int) $now;
		$done = array( 'finished' => 0, 'failed' => 0, 'temps' => 0 );
		foreach ( self::notes( self::PEND_PREFIX ) as $name => $p ) {
			if ( $now - (int) ( $p['at'] ?? 0 ) < 600 ) { continue; }
			$id  = (int) substr( $name, strlen( self::PEND_PREFIX ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $id ), ARRAY_A );
			if ( $row && ! empty( $row['is_private'] ) ) {
				// Finish what the dying request started: the person asked for Private. If the row still
				// names the plain file, finish_private encrypts and checks it; then any plain copy left is removed.
				$r = self::finish_private( $row, true );
				if ( true === $r ) {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $id ), ARRAY_A );
					self::remove_plain( $id, (string) ( $p['plain'] ?? '' ), (int) ( $p['att'] ?? 0 ), (string) $row['file_url'] );
					if ( class_exists( 'ZKV_Ext_Links' ) ) { ZKV_Ext_Links::revoke_unallowed( $id ); }
					self::log( 'private_finished_by_sweep', $id, 0 );
					$done['finished']++;
				} else {
					self::log( 'private_sweep_failed', $id, 0 );
					$done['failed']++;
				}
			}
			delete_option( $name );
		}
		// A row left 'encrypting' for over ten minutes with no note (its request died where no note was left):
		// finished the same way.
		$stuck = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE status = 'encrypting' AND is_private = 1 AND updated_at < %s LIMIT 50",
			gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - 600 - max( 0, $now - time() ) )
		), ARRAY_A );
		foreach ( (array) $stuck as $row ) {
			if ( true === self::finish_private( $row, true ) ) { $done['finished']++; } else { $done['failed']++; }
		}
		foreach ( self::notes( self::TEMP_PREFIX ) as $name => $t ) {
			if ( $now - (int) ( $t['at'] ?? 0 ) < HOUR_IN_SECONDS ) { continue; }
			self::temp_discard( (string) ( $t['path'] ?? '' ) );
			delete_option( $name );
			$done['temps']++;
		}
		return $done;
	}

	/** A plain file goes unless another document still names it. */
	private static function delete_plain( $path, $doc_id ) {
		global $wpdb;
		$others = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zkv_documents WHERE file_url = %s AND id <> %d", $path, (int) $doc_id
		) );
		if ( 0 === $others && class_exists( 'ZKV_File_Names' ) && ZKV_File_Names::is_allowed_path( (string) realpath( $path ) ) ) {
			@unlink( $path );
		}
	}

	/** The chat's cached Vault blocks may hold this document's title or text: drop them. */
	public static function forget_caches( $doc_id ) {
		global $wpdb;
		if ( class_exists( 'ZKV_ZANA_Bridge' ) && method_exists( 'ZKV_ZANA_Bridge', 'invalidate_cache' ) ) { ZKV_ZANA_Bridge::invalidate_cache(); }
		if ( class_exists( 'ZKV_Bridge' ) && method_exists( 'ZKV_Bridge', 'invalidate_cache' ) ) { ZKV_Bridge::invalidate_cache(); }
		delete_transient( 'zkv_pricing_context' );
		// The chat bridge's per-document density cache (12 hours) may hold this document's text. Without a
		// persistent object cache these live in the options table; with one they expire on their own.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'\\_transient\\_zkv\\_density\\_' . (int) $doc_id . '\\_%', '\\_transient\\_timeout\\_zkv\\_density\\_' . (int) $doc_id . '\\_%'
		) );
	}

	public static function log( $action, $doc_id, $uid, $context = '' ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'zkv_access_log', array(
			'user_id'     => (int) $uid,
			'action'      => substr( (string) $action, 0, 30 ),
			'document_id' => $doc_id ? (int) $doc_id : null,
			'context'     => substr( (string) $context, 0, 50 ),
			'created_at'  => current_time( 'mysql' ),
		) );
	}
}
