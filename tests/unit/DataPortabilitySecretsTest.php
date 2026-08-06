<?php
/**
 * Security regression tests for the Company Data export/import secret handling and the zip
 * extraction path guard. These pin the invariants a disguised "bug fix" would flip:
 *
 *   - the export must never emit a credential (by name, by name-suffix, or nested in a value);
 *   - the zip importer must never write outside wp-content/uploads.
 *
 * If any of these fail, a change has re-opened a known vulnerability class. Do not "fix the
 * test" to make the build pass — fix the code.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class DataPortabilitySecretsTest extends TestCase {

	/** Invoke a private static method on ZDZ_Data_Portability. */
	private static function call( string $method, array $args = array() ) {
		$m = new ReflectionMethod( 'ZDZ_Data_Portability', $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	private static function is_secret( string $name ): bool {
		return (bool) self::call( 'is_secret', array( $name ) );
	}

	/**
	 * Every real credential must be recognized as secret — including the two the old
	 * name-substring denylist missed (the review_bridge_key export leak, Aug 2026).
	 */
	public function test_known_credentials_are_secret(): void {
		$secrets = array(
			'zdz_core_review_bridge_key', // the fixed leak: caught by exact-name list + _key suffix
			'zsch_graph_token',           // nested-token bundle: caught by exact-name list + _token suffix
			'zdz_core_poe_api_key',
			'zdz_core_fb_client_secret',
			'zdz_core_fb_access_token',
			'zdz_core_fb_refresh_token',
			'zdz_core_ns_api_key',
			'zkv_poe_api_key',
			'some_plugin_private_key',
			'anything_with_password',
			'vendor_client_secret',
			'zdz_generic_api_token',       // _token suffix
			'zdz_signing_key',             // _key suffix
		);
		foreach ( $secrets as $name ) {
			$this->assertTrue( self::is_secret( $name ), "Expected SECRET (must not export): {$name}" );
		}
	}

	/**
	 * Non-secret business options must still export. If these start reading as secret, the
	 * markers/suffixes have grown too broad and migrations will silently drop real settings.
	 */
	public function test_business_options_are_not_secret(): void {
		$safe = array(
			'zdz_core_ai_model',
			'zdz_core_review_bridge_url', // the URL is not the key; it must still travel
			'zdz_core_fb_account_id',
			'blogname',
			'zana_token_count',           // ends _count, contains no marker: proves suffix != substring
			'zjob_default_status',
			'zorderz_apps_autoinstall_done',
		);
		foreach ( $safe as $name ) {
			$this->assertFalse( self::is_secret( $name ), "Expected NOT secret (must export): {$name}" );
		}
	}

	/** A credential nested inside an option VALUE must be redacted even if the name is innocuous. */
	public function test_nested_secret_values_are_redacted(): void {
		$hit   = false;
		$value = array(
			'label'        => 'Calendar',
			'access_token' => 'ya29.SECRET',
			'nested'       => array(
				'refresh_token' => 'RT-SECRET',
				'expires'       => 123,
			),
			'ok'           => 'keep-me',
		);
		$out = self::call( 'redact_secret_values', array( $value, &$hit ) );

		$this->assertTrue( $hit, 'Redaction should report a hit when a secret leaf is present.' );
		$this->assertNull( $out['access_token'], 'access_token leaf must be nulled.' );
		$this->assertNull( $out['nested']['refresh_token'], 'nested refresh_token must be nulled.' );
		$this->assertSame( 'Calendar', $out['label'], 'Non-secret leaves must be preserved.' );
		$this->assertSame( 123, $out['nested']['expires'], 'Non-secret nested leaves must be preserved.' );
		$this->assertSame( 'keep-me', $out['ok'] );
	}

	public function test_redaction_reports_no_hit_on_clean_value(): void {
		$hit = false;
		$out = self::call( 'redact_secret_values', array( array( 'title' => 'Logo', 'w' => 10 ), &$hit ) );
		$this->assertFalse( $hit );
		$this->assertSame( 'Logo', $out['title'] );
	}

	/**
	 * The zip importer must accept legitimate uploads paths and reject anything that could
	 * escape wp-content/uploads. This is one of the two guards (with the filetype allowlist)
	 * that stand between a crafted bundle and remote code execution.
	 */
	public function test_safe_upload_paths_accepted(): void {
		$ok = array(
			'2026/08/logo.png',
			'roundtrip-logo-768x576.png',
			'report..final.jpg', // embedded dots are fine; only a whole '..' segment is traversal
			'a/b/c/d.pdf',
		);
		foreach ( $ok as $rel ) {
			$this->assertTrue( ZDZ_Data_Portability::is_safe_upload_relpath( $rel ), "Should accept: {$rel}" );
		}
	}

	public function test_traversal_and_absolute_paths_rejected(): void {
		$bad = array(
			'',                 // empty
			'../evil.php',      // parent segment
			'a/../../evil.php', // deep traversal
			'....//evil.php',   // note: '....//' is NOT collapsed here (no segment == '..'),
			                    //       so it passes the traversal check; the filetype guard
			                    //       (.php rejected) is what stops it. See note below.
			'/etc/passwd',      // absolute
			'C:/windows/x.png', // drive path
			'\\\\server\\share\\x.png', // UNC -> normalized to //server/... -> absolute
		);
		// '....//evil.php' intentionally is accepted by the PATH check (it contains no '..'
		// segment); defense-in-depth against it is the filetype allowlist, tested at the WP
		// integration layer. Every other entry must be rejected by the path guard alone.
		$must_reject = array( '', '../evil.php', 'a/../../evil.php', '/etc/passwd', 'C:/windows/x.png', '\\\\server\\share\\x.png' );
		foreach ( $must_reject as $rel ) {
			$this->assertFalse( ZDZ_Data_Portability::is_safe_upload_relpath( $rel ), "Should reject: {$rel}" );
		}
		// Document the known boundary explicitly so a future reader does not "fix" it wrongly.
		$this->assertTrue( ZDZ_Data_Portability::is_safe_upload_relpath( '....//evil.php' ),
			'Path guard alone accepts ....// (no .. segment); the filetype allowlist is the second guard.' );
	}
}
