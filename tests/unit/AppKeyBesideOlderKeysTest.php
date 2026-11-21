<?php
/**
 * The app's own key beside the keys Zorderz already has. It ships with no caller, so nothing of theirs may change:
 *
 *   - gate codes (ZDZ_Sealed) keep their own key, made from the salts, and their own format; a change to the salts
 *     still locks them, and the app's key neither opens nor re-keys them; their own printed code in ZDZ_SEALED_KEY
 *     is still their way back, and a line there that is not a recovery code still opens nothing;
 *   - without real salts in wp-config.php the vault still seals nothing (and the app's key, made from no salt, works);
 *   - the salt watch (ZDZ_Salt_Watch) still names the salts that changed and still says what that breaks: its words
 *     are not softened while stored values depend on the salts;
 *   - stored credentials (ZDZ_Core_Crypto) are still kept under the salts, and still cannot be read once they change.
 *
 * Each moment is a process of its own (support/app-key-site.php): the salts change between two of them, and the gate
 * codes' key line is a constant. Made-up keys only, drawn on every run. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/app-key-harness.php';

final class AppKeyBesideOlderKeysTest extends TestCase {

	use ZDZK_Site_Harness;

	protected function setUp(): void {
		$this->zdzk_begin();
	}

	protected function tearDown(): void {
		$this->zdzk_end();
	}

	public function test_a_salt_change_still_locks_what_depends_on_the_salts_and_their_own_ways_back_still_work(): void {
		$k    = $this->key();
		$hand = $this->zdzk_dir . '/handover.txt';

		// before: everything works the way it did, with the app's key ready beside it
		$one = $this->moment( 'older_keys_seal', array( 'key' => $k, 'salts' => 'one', 'handover' => $hand ) );
		$this->assert_held( $one );
		$code = (string) file_get_contents( $hand );
		unlink( $hand );
		$this->assertSame( 1, preg_match( '/^[A-Z2-7]{4}( [A-Z2-7]{4}){12}$/D', $code ), 'the gate codes have a printed code of their own' );
		$this->zdzk_keys[] = $code;
		$this->assert_no_key_in( $this->zdzk_left, 'the first moment' );   // now that the gate codes' key is known too
		$id    = (string) $one['data']['code_id'];
		$check = (string) $one['data']['check'];
		$this->assertSame( 64, strlen( $check ) );

		// the salts change: locked exactly as before, and the app's key changes none of it
		$vault = $this->stored()['tables']['wp_zdz_sealed'];
		$this->assert_held( $this->moment( 'older_keys_after_salt_change', array( 'key' => $k, 'salts' => 'two', 'arg' => $id, 'k1' => $check ) ) );
		$this->assertSame( $vault, $this->stored()['tables']['wp_zdz_sealed'], 'not one sealed code was rewritten' );
		$this->assertStringNotContainsString( 'zdzk1:', (string) file_get_contents( $this->store_file() ), 'and nothing was put under the app\'s key' );

		// the gate codes' own printed code in ZDZ_SEALED_KEY: their own way back, as before
		$this->assert_held( $this->moment( 'older_keys_recovery_code', array( 'key' => $k, 'salts' => 'two', 'arg' => $id, 'sealed_key' => strtolower( str_replace( ' ', '-', $code ) ) ) ) );

		// a line there that is not a recovery code opens nothing
		$this->assert_held( $this->moment( 'older_keys_bad_recovery_code', array( 'key' => $k, 'salts' => 'two', 'arg' => $id, 'sealed_key' => 'NOT A RECOVERY CODE EXAMPLE' ) ) );
	}

	public function test_the_vault_still_needs_real_salts_and_the_app_key_needs_none(): void {
		$this->assert_held( $this->moment( 'older_keys_without_real_salts', array( 'key' => $this->key(), 'wpsalts' => 'placeholder' ) ) );
		$this->assertArrayNotHasKey( 'wp_zdz_sealed', $this->stored()['tables'], 'no gate code was kept' );
	}

	public function test_this_version_changes_no_file_of_the_older_key_services(): void {
		// The app's key is added beside them. A version that moves one of them onto it changes this list on purpose.
		$fn = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/functions.php' );
		foreach ( array( 'class-zdz-core-crypto.php', 'class-zdz-sealed.php', 'class-zdz-sealed-admin.php', 'class-zdz-salt-watch.php', 'class-zdz-core-credentials.php', 'class-zdz-staging-guard.php', 'class-zdz-cred-expiry.php' ) as $file ) {
			$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/' . $file );
			$this->assertSame( 0, preg_match( '/ZDZ_Keys|zdzk1|ZDZ_APP_KEY|zdz-secrets/', $code ), $file . ' does not know of the app\'s key' );
			$this->assertSame( 1, substr_count( $fn, "'/inc/" . $file . "'" ), $file . ' is still loaded' );
		}
	}
}
