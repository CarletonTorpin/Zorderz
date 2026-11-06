<?php
/**
 * ZAGR_Storage - where a signed PDF is kept. [CORE]
 *
 * Two copies, never a public one:
 *   1. The app's own copy, always: encrypted under the agreements key (ZAGR_Key::seal_file, its files subkey) in
 *      wp-content/zorderz-agreements-private/ (a folder of its own with deny-all rules; the bytes are ciphertext anyway),
 *      under a random name (filter zagr_private_dir). This is the copy the customer's page serves, so a locked private
 *      store never stops a customer opening their signed copy.
 *   2. A private document in the knowledge app's store, when that store is active, can encrypt, and its owner has
 *      already set up private documents (a customer's request never makes the store's key): the bytes are encrypted by
 *      the store itself (encrypt_string, then checked with verify_hash), the row is written private and admin-only,
 *      titled by estimate number and filed under the billing client id (the store has no customer field; the client
 *      id in its context is how it is found). Never a name in the title. It is filed as uploaded by the staff member
 *      who signed for the business, as agreement-<number>.pdf or change-order-<n>-<number>.pdf. The store is the
 *      knowledge app's private-document class (ZKV_Private, in apps/knowledge; filter zagr_private_store_class),
 *      behind class_exists and method_exists; when it isn't there or it refuses, only copy 1 exists.
 * The record says where the copies are: every pdf_sealed and copy_reissued step carries `vault_doc_id` and
 * `second_copy` ('private_documents' when copy 2 was kept, otherwise why not; see second_copy()).
 * The SHA-256 of the PDF is recorded on the agreement and in the record (pdf_sealed).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Storage {

	public static function dir(): string {
		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( ZAGR_DIR, 3 );
		return rtrim( (string) apply_filters( 'zagr_private_dir', $base . '/zorderz-agreements-private' ), '/' );
	}

	private static function ensure_dir(): bool {
		$d = self::dir();
		if ( ! is_dir( $d ) && ! @mkdir( $d, 0750, true ) ) { // phpcs:ignore
			return false;
		}
		if ( ! file_exists( $d . '/.htaccess' ) ) {
			@file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $d . '/index.php' ) ) {
			@file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return is_dir( $d ) && is_writable( $d );
	}

	/** Keep a PDF (encrypted). Returns the file name, or '' (nothing kept). $ad binds it to its agreement. */
	public static function put( string $bytes, string $ad ): string {
		$blob = ZAGR_Key::seal_file( $bytes, $ad );
		if ( null === $blob || ! self::ensure_dir() ) {
			return '';
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.zagr';
		$path = self::dir() . '/' . $name;
		$ok   = false !== @file_put_contents( $path, $blob, LOCK_EX );
		if ( ! $ok || ! hash_equals( hash( 'sha256', $bytes ), hash( 'sha256', (string) self::get( $name, $ad ) ) ) ) {
			@unlink( $path );
			return '';
		}
		return $name;
	}

	/** The PDF's bytes, or null (no such file, no key, or the file doesn't open). */
	public static function get( string $name, string $ad ): ?string {
		if ( ! preg_match( '/^[0-9a-f]{32}\.zagr$/', $name ) ) {
			return null;
		}
		$path = self::dir() . '/' . $name;
		if ( ! is_readable( $path ) ) {
			return null;
		}
		return ZAGR_Key::open_file( (string) file_get_contents( $path ), $ad );
	}

	/** The knowledge app's private-document class, '' when it isn't loaded or lacks what is needed. */
	public static function private_store(): string {
		$try = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zagr_private_store_class', 'ZKV_Private' ) : 'ZKV_Private';
		foreach ( array( $try ) as $c ) {
			if ( class_exists( $c ) && method_exists( $c, 'available' ) && method_exists( $c, 'encrypt_string' )
				&& method_exists( $c, 'finish_private' ) && method_exists( $c, 'key' ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Can the private store keep a document right now? Only with the key its owner already made (key( false )): making
	 * it here, inside a customer's request, would leave a key whose recovery code nobody has seen.
	 */
	public static function vault_ready(): bool {
		return '' === self::vault_state();
	}

	/**
	 * Why the private store would not keep a document right now, or '' when it would:
	 *   'no_store'       the knowledge app's private documents are not installed;
	 *   'cannot_encrypt' this site cannot keep private documents safely (no sodium, or no secret keys in wp-config);
	 *   'not_set_up'     nobody has set up private documents yet (the store's key is never made here);
	 *   'locked'         the store's key exists and does not open (the site's keys changed).
	 */
	public static function vault_state(): string {
		$c = self::private_store();
		if ( '' === $c ) {
			return 'no_store';
		}
		if ( ! $c::available() ) {
			return 'cannot_encrypt';
		}
		if ( null !== $c::key( false ) ) {
			return '';
		}
		$state = method_exists( $c, 'key_state' ) ? (string) $c::key_state() : 'none';
		return 'locked' === $state ? 'locked' : 'not_set_up';
	}

	/**
	 * What the record says about copy 2, given what to_vault() answered: 'private_documents' when it was kept, the
	 * vault_state() reason when the store would not take it, or 'refused' when the store was ready and it failed.
	 */
	public static function second_copy( int $vault_doc_id ): string {
		if ( $vault_doc_id > 0 ) {
			return 'private_documents';
		}
		$why = self::vault_state();
		return '' !== $why ? $why : 'refused';
	}

	/**
	 * File the PDF as a private document. Returns the document id, or 0 (the store is absent or refused; nothing of it
	 * is left behind then).
	 */
	public static function to_vault( string $bytes, string $title, int $client_id, string $estimate_number, int $uploaded_by = 0, string $file_name = '' ): int {
		if ( ! self::vault_ready() ) {
			return 0;
		}
		$c = self::private_store();
		global $wpdb;
		$docs = $wpdb->prefix . 'zkv_documents';
		try {
			$sha = hash( 'sha256', $bytes );
			$enc = (string) $c::encrypt_string( $bytes );
			if ( '' === $enc ) {
				return 0;
			}
			if ( method_exists( $c, 'verify_hash' ) && ! $c::verify_hash( $enc, $sha ) ) {
				@unlink( $enc );
				return 0;
			}
			$now = current_time( 'mysql' );
			$row = array(
				'attachment_id' => 0,
				'uploaded_by'   => $uploaded_by > 0 ? $uploaded_by : (int) get_current_user_id(),
				'slug'          => '',
				'title'         => $title,
				'original_name' => '' !== $file_name ? (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $file_name ) : 'agreement-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $estimate_number ) . '.pdf',
				'mime_type'     => 'application/pdf',
				'file_size'     => strlen( $bytes ),
				'file_url'      => $enc,
				'file_hash'     => '',
				'source_type'   => 'agreement',
				'description'   => 'Signed through the agreements app. PDF SHA-256 ' . $sha . '.',
				'user_context'  => 'Billing client ' . $client_id . ', estimate #' . $estimate_number . '. Filed by the agreements app.',
				'category_id'   => null,
				'status'        => 'pending',
				'visibility'    => 'admin_only',
				'is_private'    => 1,
				'version'       => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			);
			$row = (array) apply_filters( 'zagr_vault_document', $row, $client_id, $estimate_number );
			$row['is_private'] = 1; // whatever a filter says
			if ( false === $wpdb->insert( $docs, $row ) ) {
				@unlink( $enc );
				return 0;
			}
			$id  = (int) $wpdb->insert_id;
			$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$docs} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $docs is $wpdb->prefix and the knowledge vault's fixed documents table name; the id is a placeholder
			if ( ! $doc || true !== $c::finish_private( $doc ) ) {
				$wpdb->delete( $wpdb->prefix . 'zkv_index', array( 'document_id' => $id ) );
				$wpdb->delete( $docs, array( 'id' => $id ) );
				@unlink( $enc );
				return 0;
			}
			if ( method_exists( $c, 'log' ) ) {
				$c::log( 'made_private', $id, $uploaded_by > 0 ? $uploaded_by : (int) get_current_user_id(), 'agreements' );
			}
			return $id;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
