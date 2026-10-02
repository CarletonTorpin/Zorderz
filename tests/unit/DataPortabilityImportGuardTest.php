<?php
/**
 * The Company Data importer's trust boundary, and the shipped sample seed.
 *
 *   - an import may only write Zorderz tables, never WordPress core tables;
 *   - install state (schema markers, debug switches, the Web Push keypair) never travels;
 *   - the Web Push private key is a secret;
 *   - the sample loader never installs a published password hash;
 *   - the shipped seed itself carries no password hash, no private key, no debug switch.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class DataPortabilityImportGuardTest extends TestCase {

	private static function is_secret( string $name ): bool {
		$m = new ReflectionMethod( 'ZDZ_Data_Portability', 'is_secret' );
		$m->setAccessible( true );
		return (bool) $m->invoke( null, $name );
	}

	public function test_core_tables_are_not_importable(): void {
		foreach ( array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'comments', 'links' ) as $core ) {
			$this->assertFalse( ZDZ_Data_Portability::is_importable_table( $core ), $core );
		}
	}

	public function test_zorderz_tables_are_importable_except_skipped_ones(): void {
		$this->assertTrue( ZDZ_Data_Portability::is_importable_table( 'zdz_items' ) );
		$this->assertTrue( ZDZ_Data_Portability::is_importable_table( 'zest_estimates' ) );
		$this->assertFalse( ZDZ_Data_Portability::is_importable_table( 'zim_push_subscriptions' ) );
		$this->assertFalse( ZDZ_Data_Portability::is_importable_table( 'zsch_calendar_accounts' ) );
		$this->assertFalse( ZDZ_Data_Portability::is_importable_table( 'zdz_items`; DROP TABLE x' ) );
		$this->assertFalse( ZDZ_Data_Portability::is_importable_table( '' ) );
	}

	public function test_install_state_is_not_portable(): void {
		foreach ( array( 'zest_db_version', 'zdz_apps_version', 'zdz_item_engine_version', 'zdz_migrated_2_20_3', 'zsv_operator_cols_migrated', 'zdz_debug_capture', 'zim_vapid_public_b64', 'zdz_rename_migration_report' ) as $name ) {
			$this->assertTrue( ZDZ_Data_Portability::is_non_portable_option( $name ), $name );
		}
		foreach ( array( 'zdz_business_profile', 'zdz_role_labels', 'zest_settings' ) as $name ) {
			$this->assertFalse( ZDZ_Data_Portability::is_non_portable_option( $name ), $name );
		}
	}

	public function test_web_push_private_key_is_secret(): void {
		$this->assertTrue( self::is_secret( 'zim_vapid_private_pem' ) );
		$this->assertTrue( self::is_secret( 'zdz_some_signing_pem' ) );
	}

	public function test_sample_bundle_is_prepared_without_passwords_or_install_state(): void {
		$prepared = ZDZ_Data_Portability::prepare_sample_bundle( array(
			'users'   => array( array( 'ID' => 2, 'user_login' => 'demo', 'user_pass' => '$wp$2y$12$abcdef', 'user_activation_key' => 'k' ) ),
			'options' => array( 'zdz_debug_capture' => '1', 'zim_vapid_private_pem' => 'PEM', 'zest_db_version' => '1.0', 'zdz_business_profile' => array( 'x' => 1 ) ),
		) );
		$this->assertSame( '', $prepared['users'][0]['user_pass'] );
		$this->assertSame( '', $prepared['users'][0]['user_activation_key'] );
		$this->assertSame( array( 'zdz_business_profile' ), array_keys( $prepared['options'] ) );
	}

	/** The seed that ships in every theme zip. */
	public function test_shipped_sample_seed_carries_no_credentials(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable' );
		}
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( dirname( __DIR__, 2 ) . '/zorderz/sample-data/testco.zip' ) );
		$bundle = json_decode( (string) $zip->getFromName( 'zorderz-data.json' ), true );
		$zip->close();
		$this->assertIsArray( $bundle );
		foreach ( (array) $bundle['users'] as $u ) {
			$this->assertEmpty( $u['user_pass'] ?? '', 'seed user ' . ( $u['user_login'] ?? '?' ) . ' carries a password hash' );
		}
		foreach ( array_keys( (array) $bundle['options'] ) as $name ) {
			$this->assertFalse( self::is_secret( (string) $name ), 'seed option is a secret: ' . $name );
			$this->assertFalse( ZDZ_Data_Portability::is_non_portable_option( (string) $name ), 'seed option is install state: ' . $name );
		}
	}
}
