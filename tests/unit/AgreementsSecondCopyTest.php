<?php
/**
 * Agreements: the second encrypted copy of a signed PDF goes to the knowledge app's private documents, and the
 * record says where the copies are.
 *
 *   - The binding finds the knowledge app's real private-document class (ZKV_Private) when it is loaded.
 *   - The store is used only when it can encrypt and its owner already set it up; otherwise the record says why
 *     (no store, cannot encrypt, not set up, locked) and only the app's own copy exists.
 *   - A kept copy is a private, admin-only document whatever a filter says, and a failed one leaves nothing behind.
 *   - Every pdf_sealed and copy_reissued step carries `second_copy`.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/agreements-stubs.php';
require_once ZAGR_DIR . 'includes/class-zagr-storage.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/knowledge/includes/class-zkv-private.php';

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}

/** A private-document store whose answers the test sets. */
final class ZDZ_Test_Wireb_Vault {
	public static $available = true;
	public static $key       = 'k';
	public static $state     = 'ready';
	public static $finish    = true;
	public static $finished  = array();
	public static $files     = array();

	public static function reset(): void {
		self::$available = true;
		self::$key       = 'k';
		self::$state     = 'ready';
		self::$finish    = true;
		self::$finished  = array();
		self::$files     = array();
	}
	public static function available() { return self::$available; }
	public static function key( $create = false ) { return self::$key; }
	public static function key_state() { return self::$state; }
	public static function encrypt_string( $plain ) {
		$p = tempnam( sys_get_temp_dir(), 'zdzwb' );
		file_put_contents( $p, 'sealed:' . $plain );
		self::$files[] = $p;
		return $p;
	}
	public static function verify_hash( $enc, $sha ) { return hash_equals( (string) $sha, hash( 'sha256', substr( (string) file_get_contents( $enc ), 7 ) ) ); }
	public static function finish_private( array $doc, $owner = false ) {
		self::$finished[] = $doc;
		return self::$finish;
	}
}

final class AgreementsSecondCopyTest extends TestCase {

	/** @var ZDZ_Test_Agr_DB */
	private $db;

	protected function setUp(): void {
		$this->db                    = new ZDZ_Test_Agr_DB();
		$GLOBALS['wpdb']             = $this->db;
		$GLOBALS['zdz_test_filters'] = array();
		$this->db->query( 'CREATE TABLE wp_zkv_documents ( id INTEGER PRIMARY KEY AUTOINCREMENT, attachment_id INTEGER, uploaded_by INTEGER, slug TEXT, title TEXT, original_name TEXT, mime_type TEXT, file_size INTEGER, file_url TEXT, file_hash TEXT, source_type TEXT, description TEXT, user_context TEXT, category_id INTEGER, status TEXT, visibility TEXT, is_private INTEGER, version INTEGER, created_at TEXT, updated_at TEXT )' );
		$this->db->query( 'CREATE TABLE wp_zkv_index ( id INTEGER PRIMARY KEY AUTOINCREMENT, document_id INTEGER )' );
		ZDZ_Test_Wireb_Vault::reset();
	}

	protected function tearDown(): void {
		foreach ( ZDZ_Test_Wireb_Vault::$files as $f ) {
			@unlink( $f );
		}
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_filters'] );
	}

	private function use_double(): void {
		$GLOBALS['zdz_test_filters']['zagr_private_store_class'] = static function () { return 'ZDZ_Test_Wireb_Vault'; };
	}

	public function test_the_knowledge_apps_private_documents_are_the_store(): void {
		$this->assertSame( 'ZKV_Private', ZAGR_Storage::private_store(), 'found by its own name, behind class_exists and method_exists' );
		foreach ( array( 'available', 'key', 'key_state', 'encrypt_string', 'verify_hash', 'finish_private', 'log' ) as $m ) {
			$this->assertTrue( method_exists( 'ZKV_Private', $m ), 'ZKV_Private::' . $m );
		}
		$GLOBALS['zdz_test_filters']['zagr_private_store_class'] = static function () { return 'No_Such_Store_Class'; };
		$this->assertSame( '', ZAGR_Storage::private_store() );
		$this->assertSame( 'no_store', ZAGR_Storage::vault_state() );
		$this->assertSame( 'no_store', ZAGR_Storage::second_copy( 0 ) );
	}

	public function test_the_record_says_why_there_is_no_second_copy(): void {
		$this->use_double();
		$this->assertSame( '', ZAGR_Storage::vault_state() );
		$this->assertTrue( ZAGR_Storage::vault_ready() );
		$this->assertSame( 'private_documents', ZAGR_Storage::second_copy( 12 ) );
		$this->assertSame( 'refused', ZAGR_Storage::second_copy( 0 ), 'ready, but the store did not take it' );

		ZDZ_Test_Wireb_Vault::$key   = null;
		ZDZ_Test_Wireb_Vault::$state = 'none';
		$this->assertSame( 'not_set_up', ZAGR_Storage::second_copy( 0 ), 'the key is never made inside a customer\'s request' );
		ZDZ_Test_Wireb_Vault::$state = 'locked';
		$this->assertSame( 'locked', ZAGR_Storage::second_copy( 0 ) );
		ZDZ_Test_Wireb_Vault::$available = false;
		$this->assertSame( 'cannot_encrypt', ZAGR_Storage::second_copy( 0 ) );
		$this->assertFalse( ZAGR_Storage::vault_ready() );
	}

	public function test_a_kept_copy_is_private_and_admin_only_whatever_a_filter_says(): void {
		$this->use_double();
		$GLOBALS['zdz_test_filters']['zagr_vault_document'] = static function ( $row ) {
			$row['is_private'] = 0;
			return $row;
		};
		$id = ZAGR_Storage::to_vault( '%PDF-1.4 example', 'Signed agreement, estimate #1042', 5, '1042', 7, 'agreement-1042.pdf' );
		$this->assertGreaterThan( 0, $id );
		$row = $this->db->get_row( 'SELECT * FROM wp_zkv_documents WHERE id = ' . (int) $id );
		$this->assertSame( 1, (int) $row['is_private'] );
		$this->assertSame( 'admin_only', $row['visibility'] );
		$this->assertSame( 'Signed agreement, estimate #1042', $row['title'] );
		$this->assertSame( 'agreement-1042.pdf', $row['original_name'] );
		$this->assertStringContainsString( hash( 'sha256', '%PDF-1.4 example' ), (string) $row['description'] );
		$this->assertCount( 1, ZDZ_Test_Wireb_Vault::$finished );
	}

	public function test_a_refused_copy_leaves_nothing_behind(): void {
		$this->use_double();
		ZDZ_Test_Wireb_Vault::$finish = 'Could not encrypt this Private document.';
		$this->assertSame( 0, ZAGR_Storage::to_vault( '%PDF-1.4 example', 'Signed agreement, estimate #1043', 5, '1043' ) );
		$this->assertSame( '0', (string) $this->db->get_var( 'SELECT COUNT(*) FROM wp_zkv_documents' ) );
		$this->assertFileDoesNotExistCompat( ZDZ_Test_Wireb_Vault::$files[0] );

		ZDZ_Test_Wireb_Vault::reset();
		ZDZ_Test_Wireb_Vault::$key   = null;
		ZDZ_Test_Wireb_Vault::$state = 'none';
		$this->assertSame( 0, ZAGR_Storage::to_vault( '%PDF-1.4 example', 'Signed agreement, estimate #1044', 5, '1044' ) );
		$this->assertSame( array(), ZDZ_Test_Wireb_Vault::$files, 'not set up: nothing is encrypted at all' );
	}

	public function test_every_signed_pdf_step_says_where_the_copies_are(): void {
		$src = (string) file_get_contents( ZAGR_DIR . 'includes/class-zagr-flow.php' );
		$this->assertSame( 3, substr_count( $src, 'ZAGR_Storage::to_vault(' ) );
		$this->assertSame( 3, substr_count( $src, "'second_copy' => ZAGR_Storage::second_copy( (int) \$vault )" ) );
		$this->assertSame( 3, substr_count( $src, "'own_copy' => true, 'vault_doc_id' => \$vault, 'second_copy'" ) );
	}

	private function assertFileDoesNotExistCompat( string $path ): void {
		$this->assertFalse( file_exists( $path ), 'the encrypted file is removed' );
	}
}
