<?php
/**
 * Zorderz -> App key (ZDZ_Keys_Admin): the page, the notices and the Site Health item of the app's own key.
 *
 *   - the words of each notice, from what the key's state says (pure: no key needed): one per locked state, none
 *     when ready, none until the key is set up; every one says not to make a new key, and names key ids only;
 *   - the two lines the page gives for wp-config.php: the require is guarded, so a missing file locks the app's
 *     secrets and never takes the site down;
 *   - who may see the page and record a key: an administrator signed in as themselves, and never the shared device
 *     (whatever its role is called), Demo Mode, View As, or an account in Privacy View. Answered by the real role,
 *     tier, Demo Mode, View As and Privacy View classes, in a process of its own;
 *   - the page itself: key ids and never a key, the jobs and their counts, Run and Re-encrypt everything now (each
 *     press logged, and refused without the page's nonce), an unreadable value named by row and never by value;
 *   - Check a printed key: which key a typed code is and how much of it was read; never echoed, stored or logged;
 *   - what the class hooks, and that hooking it reads no key and writes nothing.
 *
 * Made-up keys only, drawn on every run. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/app-key-harness.php';
require_once zdzk_test_inc() . '/class-zdz-keys.php';
require_once zdzk_test_inc() . '/class-zdz-keys-migrate.php';
require_once zdzk_test_inc() . '/class-zdz-keys-admin.php';

final class AppKeyAdminTest extends TestCase {

	use ZDZK_Site_Harness;

	protected function setUp(): void {
		$this->zdzk_begin();
		ZDZ_Keys::reset();
	}

	protected function tearDown(): void {
		$this->zdzk_end();
		ZDZ_Keys::reset();
	}

	/** What ZDZ_Keys::info() answers for a site whose key is in place, changed as a test needs. */
	private static function info( array $over = array() ): array {
		return $over + array(
			'state'         => 'ok',
			'set_up'        => true,
			'kid'           => '0123abcd',
			'prev_kid'      => '',
			'prev_bad'      => false,
			'rotating'      => false,
			'unrecorded'    => false,
			'site_why'      => '',
			'secrets_load'  => true,
			'recorded_kid'  => '0123abcd',
			'recorded_site' => 'app.example.com',
			'recorded_at'   => '2026-01-15 09:30:00',
			'recorded_by'   => 1,
			'recorded_how'  => 'first',
			'site_const'    => 'app.example.com',
			'host'          => 'app.example.com',
			'wp_secrets'    => false,
		);
	}

	/* ── the words (no key needed) ────────────────────────────────────────── */

	public function test_a_ready_key_and_a_key_not_set_up_say_nothing(): void {
		$this->assertNull( ZDZ_Keys_Admin::banner( self::info() ), 'ready: no notice' );
		$this->assertNull( ZDZ_Keys_Admin::banner( self::info( array( 'state' => 'missing', 'set_up' => false, 'kid' => '', 'recorded_kid' => '' ) ) ), 'not set up yet: no notice' );
		$this->assertNull( ZDZ_Keys_Admin::banner( self::info( array( 'rotating' => true, 'prev_kid' => '89abcdef' ) ) ), 'a key change under way is not a fault' );
	}

	public function test_each_locked_state_has_its_own_words_and_none_asks_for_a_new_key(): void {
		$states = array(
			'missing'      => array( array( 'state' => 'missing', 'kid' => '' ), 'The app\'s key is missing.', 'require line' ),
			'bad_constant' => array( array( 'state' => 'bad_constant', 'kid' => '' ), 'ZDZ_APP_KEY is not a printed key.', '13 groups of four' ),
			'mismatch'     => array( array( 'state' => 'mismatch', 'kid' => '89abcdef' ), 'key id 89abcdef, expected 0123abcd', 'Put back the right ZDZ_APP_KEY line' ),
			'unrecorded'   => array( array( 'state' => 'mismatch', 'kid' => '89abcdef', 'unrecorded' => true, 'recorded_kid' => '' ), 'no record of which key its sealed values use (key id in zdz-secrets.php: 89abcdef)', 'press Record' ),
			'copied'       => array( array( 'state' => 'other_site', 'site_why' => 'record', 'recorded_site' => 'production.example.com', 'host' => 'staging.example.com' ), 'This site\'s database was copied from production.example.com.', 'this site is staging.example.com' ),
			'other file'   => array( array( 'state' => 'other_site', 'site_why' => 'file', 'site_const' => 'production.example.com', 'host' => 'staging.example.com' ), 'This site has the key for production.example.com.', 'This site is staging.example.com.' ),
		);
		foreach ( $states as $name => $case ) {
			$b = ZDZ_Keys_Admin::banner( self::info( $case[0] ) );
			$this->assertIsArray( $b, $name );
			$this->assertCount( 2, $b, $name );
			$this->assertStringContainsString( $case[1], $b[0], $name . ': what is wrong' );
			$this->assertStringContainsString( $case[2], $b[1], $name . ': what to do' );
			if ( 'other file' !== $name ) {
				$this->assertStringContainsString( 'Do not make a new key', $b[1], $name . ': never a new key' );
			}
			$this->assertStringNotContainsString( 'Regenerate', $b[0] . $b[1], $name );
			list( $class, $line ) = ZDZ_Keys_Admin::status_line( self::info( $case[0] ) );
			$this->assertSame( 'notice-error', $class, $name );
			$this->assertSame( $b[0] . ' ' . $b[1], $line, $name . ': the page says the same as the notice' );
		}
		$b = ZDZ_Keys_Admin::banner( self::info( array( 'state' => 'no_sodium', 'kid' => '' ) ) );
		$this->assertStringContainsString( 'no libsodium', $b[0] );
		$b = ZDZ_Keys_Admin::banner( self::info( array( 'state' => 'some-later-state' ) ) );
		$this->assertStringContainsString( 'can\'t be used right now', $b[0], 'a state this page does not know is still said to be a fault' );
	}

	public function test_the_page_says_the_state_in_one_line(): void {
		$this->assertSame( 'notice-success', ZDZ_Keys_Admin::status_line( self::info() )[0] );
		$this->assertStringContainsString( 'in place', ZDZ_Keys_Admin::status_line( self::info() )[1] );
		list( $class, $line ) = ZDZ_Keys_Admin::status_line( self::info( array( 'rotating' => true, 'prev_kid' => '89abcdef' ) ) );
		$this->assertSame( 'notice-warning', $class );
		$this->assertStringContainsString( 'A key change is under way', $line );
		$not_set_up           = array( 'state' => 'missing', 'set_up' => false, 'kid' => '', 'recorded_kid' => '' );
		list( $class, $line ) = ZDZ_Keys_Admin::status_line( self::info( $not_set_up + array( 'secrets_load' => false ) ) );
		$this->assertSame( 'notice-info', $class );
		$this->assertStringContainsString( 'Not set up yet', $line );
		$this->assertStringContainsString( 'nothing is affected', $line );
		list( $class, $line ) = ZDZ_Keys_Admin::status_line( self::info( $not_set_up ) );
		$this->assertSame( 'notice-warning', $class, 'wp-config.php asks for the file, and no key came from it' );
		$this->assertStringContainsString( 'no ZDZ_APP_KEY came from it', $line );
	}

	public function test_the_lines_for_wp_config_guard_the_require(): void {
		$lines = ZDZ_Keys_Admin::config_lines();
		$this->assertSame( "define( 'ZDZ_SECRETS_LOAD', true );", $lines[0] );
		$this->assertSame( "if ( is_readable( __DIR__ . '/zdz-secrets.php' ) ) { require_once __DIR__ . '/zdz-secrets.php'; }", $lines[1] );
		$this->assertCount( 2, $lines );
		// A bare require of a missing file is a fatal error for the whole site: the check must stay in front of it.
		$this->assertSame( 1, preg_match( '/^if \( is_readable\( (.+?) \) \) \{ require_once \1; \}$/D', $lines[1] ), 'the file that is checked is the file that is loaded' );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( 'ABSPATH', $line, 'beside wp-config.php, wherever WordPress itself lives' );
		}
	}

	public function test_a_typed_code_is_counted_never_kept(): void {
		$this->assertSame( array( 52, 0 ), ZDZ_Keys_Admin::code_reading( zdzk_printed( random_bytes( 32 ) ) ) );
		$this->assertSame( array( 8, 1 ), ZDZ_Keys_Admin::code_reading( "AAAA-BBBB\u{00A0}é" ), 'a pasted non-breaking space is spacing; an accented letter is one mark' );
		$this->assertSame( array( 4, 0 ), ZDZ_Keys_Admin::code_reading( '0o1i' ), '0 and 1 are read as O and I' );
		$this->assertSame( array( 0, 3 ), ZDZ_Keys_Admin::code_reading( '9!?' ) );
		$this->assertSame( array( 0, 0 ), ZDZ_Keys_Admin::code_reading( '' ) );
	}

	/* ── who may manage the key ───────────────────────────────────────────── */

	public function test_only_an_administrator_signed_in_as_themselves_manages_the_key(): void {
		$k = $this->key();
		$this->store( array( 'zdz_keys_ever' => 1 ) );
		$this->assert_held( $this->moment( 'who_may_manage', array( 'key' => $k ) ) );
		$this->assertSame( 1, $this->stored()['options']['zdz_keys_state']['by'], 'recorded by the administrator, and by nobody refused before' );
	}

	/* ── the page ─────────────────────────────────────────────────────────── */

	public function test_the_page_shows_key_ids_the_jobs_and_what_was_pressed_never_a_key_or_a_value(): void {
		$seen = $this->moment( 'page_with_and_without_jobs', array( 'key' => $this->key() ) );
		$this->assert_held( $seen );
		$this->assertCount( 2, $seen['logged'], 'two presses that ran, two audit rows' );
		foreach ( $seen['logged'] as $row ) {
			$this->assertSame( 'zdz-theme', $row[3] );
			$this->assertStringNotContainsString( 'EXAMPLE', $row[2], 'an audit row never holds a value' );
		}
	}

	public function test_check_a_printed_key_says_which_key_it_is_and_keeps_nothing(): void {
		$file     = $this->key();
		$recorded = $this->key();
		$nobodys  = $this->key();
		$this->store( array( 'zdz_keys_state' => self::record( $recorded ), 'zdz_keys_ever' => 1 ) );
		$before = (string) file_get_contents( $this->store_file() );
		$seen   = $this->moment( 'check_a_printed_key', array( 'key' => $file, 'prev' => $recorded, 'k1' => $recorded, 'k3' => $nobodys ) );
		$this->assert_held( $seen );
		$this->assertSame( array(), $seen['logged'] );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ), 'checking stores nothing' );
	}

	public function test_hooking_the_page_reads_no_key_and_writes_nothing(): void {
		$this->assert_held( $this->moment( 'boot' ) );
		$this->assertFalse( file_exists( $this->store_file() ) );
	}

	public function test_the_theme_loads_the_three_classes_and_boots_the_page(): void {
		$fn = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/functions.php' );
		foreach ( array( 'class-zdz-keys.php', 'class-zdz-keys-migrate.php', 'class-zdz-keys-admin.php' ) as $file ) {
			$this->assertSame( 1, substr_count( $fn, "'/inc/" . $file . "'" ), $file . ' is loaded once' );
			$this->assertFileExists( dirname( __DIR__, 2 ) . '/zorderz/inc/' . $file );
		}
		$this->assertSame( 1, substr_count( $fn, 'ZDZ_Keys_Admin::boot();' ) );
		$this->assertLessThan( strpos( $fn, 'ZDZ_Keys_Admin::boot();' ), strpos( $fn, "'/inc/class-zdz-keys-admin.php'" ), 'loaded before it is booted' );
	}

	public function test_nothing_else_in_the_theme_or_the_apps_calls_the_key_service_yet(): void {
		// The service ships with no caller: no stored value of any kind moves onto it until a later version says so.
		// (The version that gives it its first caller takes this test out, on purpose.)
		$root  = dirname( __DIR__, 2 );
		$own   = array( 'class-zdz-keys.php', 'class-zdz-keys-migrate.php', 'class-zdz-keys-admin.php' );
		$found = array();
		foreach ( array( $root . '/zorderz', $root . '/zorderz-apps' ) as $tree ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tree, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file;
				if ( '.php' !== substr( $path, -4 ) || in_array( basename( $path ), $own, true ) || false !== strpos( $path, '/bundled/' ) ) {
					continue;
				}
				$code = (string) file_get_contents( $path );
				if ( preg_match( '/\bZDZ_Keys(_Migrate)?::|[\'"]zdz_keys_migrations[\'"]|[\'"]ZDZ_APP_KEY/', $code ) ) {
					$found[] = substr( $path, strlen( $root ) + 1 );
				}
			}
		}
		$this->assertSame( array(), $found, 'a caller of the key service: ' . implode( ', ', $found ) );
	}
}
