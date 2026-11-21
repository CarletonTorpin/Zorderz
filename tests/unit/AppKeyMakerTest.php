<?php
/**
 * bin/make-app-keys.php: the script an operator runs on their own computer to make a site's key and its
 * zdz-secrets.php, and the file it writes. Each test runs the real script, as an operator would.
 *
 *   - one run makes one key file for one site: the folder is the operator's only (0700), the file too (0600), and
 *     only the key id is printed, never the key;
 *   - the key id it prints is the one the app shows for that key; the address is kept as the app compares it;
 *   - a key file is never written over; an address that is not one, a missing argument or an unknown option makes
 *     nothing at all;
 *   - the script uses nothing but PHP itself: no other file, no network;
 *   - the file it writes defines nothing unless wp-config.php asked for it, prints nothing when asked for on its
 *     own, and can be loaded twice without a warning;
 *   - end to end: a site whose wp-config.php has the two lines the page gives, and the made file beside it, is
 *     ready and shows the same key id; the same file on another site locks instead of reading; and with the file
 *     gone the site still answers (the require is guarded) and says the key was not read.
 *
 * Made-up keys only, made by the script itself on every run. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/app-key-harness.php';
require_once zdzk_test_inc() . '/class-zdz-keys.php';
require_once zdzk_test_inc() . '/class-zdz-keys-migrate.php';
require_once zdzk_test_inc() . '/class-zdz-keys-admin.php';

final class AppKeyMakerTest extends TestCase {

	use ZDZK_Site_Harness;

	protected function setUp(): void {
		$this->zdzk_begin();
	}

	protected function tearDown(): void {
		$this->zdzk_end();
	}

	/** Run the script with these arguments. [ exit code, what it printed, what it complained of ]. */
	private function make( array $args ): array {
		return self::zdzk_run( array_merge( array( PHP_BINARY, zdzk_test_bin() ), $args ), array(), $this->zdzk_dir );
	}

	/** Make a key for $site into $folder (under this test's own folder); the file's path. The key joins the keys no output may hold. */
	private function made( string $site, string $folder ): string {
		list( $rc, $out, $err ) = $this->make( array( '--site=' . $site, $folder ) );
		$this->assertSame( array( 0, '' ), array( $rc, $err ), $out . $err );
		$file = $this->zdzk_dir . '/' . $folder . '/zdz-secrets.php';
		$this->assertFileExists( $file );
		$this->zdzk_keys[] = self::key_in( $file );
		$this->assert_no_key_in( $out, 'the script' );
		return $file;
	}

	private static function key_in( string $file ): string {
		return preg_match( "/define\( 'ZDZ_APP_KEY', '([A-Z2-7 ]+)' \);/", (string) file_get_contents( $file ), $m ) ? $m[1] : '';
	}

	private static function mode( string $path ): string {
		clearstatcache();
		return substr( sprintf( '%o', fileperms( $path ) ), -3 );
	}

	/** A site's folder: wp-config.php with the two lines the page gives, and (unless it "went missing") the key file. */
	private function site_root( ?string $key_file ): string {
		$root = $this->zdzk_dir . '/site-' . bin2hex( random_bytes( 3 ) );
		mkdir( $root );
		file_put_contents( $root . '/wp-config.php', "<?php\n" . implode( "\n", ZDZ_Keys_Admin::config_lines() ) . "\n" );
		if ( null !== $key_file ) {
			copy( $key_file, $root . '/zdz-secrets.php' );
		}
		return $root;
	}

	/* ── making a key ─────────────────────────────────────────────────────── */

	public function test_it_makes_one_key_file_closed_to_everyone_else_and_prints_only_the_key_id(): void {
		$old = umask( 0022 );   // an everyday computer, where new files are open to read
		list( $rc, $out, $err ) = $this->make( array( '--site=app.example.com', 'keys-live' ) );
		umask( $old );
		$this->assertSame( array( 0, '' ), array( $rc, $err ), $out . $err );
		$file = $this->zdzk_dir . '/keys-live/zdz-secrets.php';
		$this->assertFileExists( $file );
		if ( '/' === DIRECTORY_SEPARATOR ) {
			$this->assertSame( '700', self::mode( dirname( $file ) ), 'the folder is the operator\'s only' );
			$this->assertSame( '600', self::mode( $file ), 'and so is the file' );
		}
		$this->assertSame( array( '.', '..', 'zdz-secrets.php' ), scandir( dirname( $file ) ), 'one file, nothing else left behind' );

		$key = self::key_in( $file );
		$this->assertSame( 1, preg_match( '/^[A-Z2-7]{4}( [A-Z2-7]{4}){12}$/D', $key ), 'a printed key: 13 groups of four' );
		$this->assertSame( 32, strlen( (string) ZDZ_Keys::parse( $key ) ), 'that the app reads as 32 bytes' );
		$this->zdzk_keys[] = $key;
		$this->assert_no_key_in( $out, 'the script' );

		$kid = ZDZ_Keys::code_kid( $key );
		$this->assertSame( zdzk_kid( $key ), $kid );
		$this->assertStringContainsString( 'Key id:  ' . $kid, $out, 'it prints the key id the app shows for this key' );
		$this->assertStringContainsString( 'Key id: ' . $kid, (string) file_get_contents( $file ), 'and writes it in the file\'s own note' );
		$this->assertStringContainsString( "define( 'ZDZ_APP_KEY_SITE', 'app.example.com' );", (string) file_get_contents( $file ) );
		$this->assertStringContainsString( realpath( $file ), $out, 'it says where the file is' );
		$this->assertStringContainsString( 'password manager', $out );
	}

	public function test_the_address_is_kept_the_way_the_app_compares_it(): void {
		$file = $this->made( 'HTTPS://WWW.Staging.Example.com:8443/wp/', 'keys-staging' );
		$this->assertStringContainsString( "define( 'ZDZ_APP_KEY_SITE', 'staging.example.com' );", (string) file_get_contents( $file ) );
		$this->assertSame( 'staging.example.com', ZDZ_Keys::norm_host( 'HTTPS://WWW.Staging.Example.com:8443/wp/' ), 'the same reading as the app\'s' );
		foreach ( array( 'app.example.com:443', 'http://App.Example.com/?x=1#top', '  www.app.example.com  ', 'localhost' ) as $n => $typed ) {
			$file = $this->made( $typed, 'spelling-' . $n );
			$this->assertStringContainsString( "define( 'ZDZ_APP_KEY_SITE', '" . ZDZ_Keys::norm_host( $typed ) . "' );", (string) file_get_contents( $file ), 'as the app reads ' . json_encode( $typed ) );
		}
	}

	public function test_two_runs_make_two_different_keys(): void {
		$a = self::key_in( $this->made( 'app.example.com', 'one' ) );
		$b = self::key_in( $this->made( 'app.example.com', 'two' ) );
		$this->assertNotSame( $a, $b );
		$this->assertNotSame( zdzk_kid( $a ), zdzk_kid( $b ) );
	}

	public function test_it_never_writes_over_a_key_file(): void {
		$file   = $this->made( 'app.example.com', 'keys' );
		$before = (string) file_get_contents( $file );
		list( $rc, $out, $err ) = $this->make( array( '--site=app.example.com', 'keys' ) );
		$this->assertSame( 1, $rc );
		$this->assertSame( '', $out, 'nothing is announced as made' );
		$this->assertStringContainsString( 'never written over', $err );
		$this->assertSame( $before, (string) file_get_contents( $file ), 'the first key is still there, byte for byte' );
		// the same through another spelling of the folder, and for a link standing where the file would go
		list( $rc ) = $this->make( array( '--site=app.example.com', './keys/' ) );
		$this->assertSame( 1, $rc );
		$this->assertSame( $before, (string) file_get_contents( $file ) );
		mkdir( $this->zdzk_dir . '/linked' );
		if ( @symlink( $file, $this->zdzk_dir . '/linked/zdz-secrets.php' ) ) {
			list( $rc ) = $this->make( array( '--site=app.example.com', 'linked' ) );
			$this->assertSame( 1, $rc, 'a link is not written through' );
			$this->assertSame( $before, (string) file_get_contents( $file ) );
		}
	}

	public function test_it_writes_into_a_folder_that_is_already_there_and_leaves_it_as_it_was(): void {
		mkdir( $this->zdzk_dir . '/mine', 0750 );
		file_put_contents( $this->zdzk_dir . '/mine/notes.txt', 'kept' );
		$this->made( 'app.example.com', 'mine' );
		$this->assertSame( 'kept', (string) file_get_contents( $this->zdzk_dir . '/mine/notes.txt' ) );
		if ( '/' === DIRECTORY_SEPARATOR ) {
			$this->assertSame( '750', self::mode( $this->zdzk_dir . '/mine' ), 'a folder it did not make keeps its own permissions' );
			$this->assertSame( '600', self::mode( $this->zdzk_dir . '/mine/zdz-secrets.php' ) );
		}
	}

	public function test_what_is_not_a_site_address_makes_nothing(): void {
		foreach ( array( 'not a host', 'exa_mple.com', 'https://', '', 'user:secret@app.example.com', '-app.example.com', 'app..example.com', str_repeat( 'a', 64 ) . '.example.com', "app.example.com' ); //" ) as $n => $bad ) {
			list( $rc, $out, $err ) = $this->make( array( '--site=' . $bad, 'bad-' . $n ) );
			$this->assertSame( 1, $rc, 'refused: ' . json_encode( $bad ) );
			$this->assertSame( '', $out );
			$this->assertStringContainsString( 'Nothing was made', $err );
			$this->assertFalse( file_exists( $this->zdzk_dir . '/bad-' . $n ), 'no folder is left behind for ' . json_encode( $bad ) );
		}
	}

	public function test_it_needs_a_site_one_folder_and_nothing_it_does_not_know(): void {
		$tries = array(
			'no site'              => array( 'keys' ),
			'no folder'            => array( '--site=app.example.com' ),
			'nothing at all'       => array(),
			'two folders'          => array( '--site=app.example.com', 'keys', 'more' ),
			'an unknown option'    => array( '--site=app.example.com', '--force', 'keys' ),
			'a site with no value' => array( 'keys', '--site' ),
			'a folder not there'   => array( '--site=app.example.com', 'no/such/parent' ),
		);
		foreach ( $tries as $name => $args ) {
			list( $rc, $out, $err ) = $this->make( $args );
			$this->assertSame( 1, $rc, $name );
			$this->assertSame( '', $out, $name );
			$this->assertStringContainsString( 'Nothing was made', $err, $name );
		}
		$this->assertSame( array( '.', '..' ), scandir( $this->zdzk_dir ), 'not one of them left anything behind' );
	}

	public function test_asking_for_help_makes_nothing(): void {
		list( $rc, $out, $err ) = $this->make( array( '--help', '--site=app.example.com', 'keys' ) );
		$this->assertSame( array( 0, '' ), array( $rc, $err ) );
		$this->assertStringContainsString( 'Usage: php make-app-keys.php --site=', $out );
		$this->assertFalse( file_exists( $this->zdzk_dir . '/keys' ) );
	}

	public function test_the_script_uses_nothing_but_php_itself(): void {
		// Read as PHP reads it: what the script does, not what its notes or the text of the file it writes say.
		$code    = (string) file_get_contents( zdzk_test_bin() );
		$tokens  = token_get_all( $code );
		$loads   = array();
		$calls   = array();
		$globals = array();
		foreach ( $tokens as $i => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( in_array( $t[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_EVAL ), true ) ) {
				$loads[] = $t[1];
			}
			if ( T_VARIABLE === $t[0] && 0 === strpos( $t[1], '$_' ) ) {
				$globals[] = $t[1];
			}
			if ( T_STRING === $t[0] ) {
				$next = $tokens[ $i + 1 ] ?? null;
				$next = ( is_array( $next ) && T_WHITESPACE === $next[0] ) ? ( $tokens[ $i + 2 ] ?? null ) : $next;
				if ( '(' === $next ) {
					$calls[ strtolower( $t[1] ) ] = true;
				}
			}
		}
		$this->assertSame( array(), $loads, 'it loads no other file' );
		$this->assertSame( array(), $globals, 'it reads nothing of a web request or of the environment' );
		foreach ( array_keys( $calls ) as $fn ) {
			$this->assertSame( 0, preg_match( '/^(curl_|socket_|stream_socket_|fsockopen|pfsockopen|mail$|getenv$|putenv$|exec$|shell_exec$|system$|passthru$|proc_open$|popen$|dns_|gethostby|ftp_|ssh2_|wp_)/', $fn ), 'it must not call ' . $fn . '()' );
		}
		foreach ( array( 'random_bytes', 'hash_hmac', 'fopen', 'chmod', 'umask' ) as $fn ) {
			$this->assertArrayHasKey( $fn, $calls, 'it is expected to call ' . $fn . '()' );
		}
		$this->assertStringContainsString( "'cli' !== PHP_SAPI", $code, 'and it runs from the command line only' );
		$this->assertStringContainsString( "fopen( \$zdz_file, 'xb' )", $code, 'the file is made new, or not at all' );
	}

	public function test_the_script_and_the_page_give_the_same_two_lines_for_wp_config(): void {
		$file = $this->made( 'app.example.com', 'keys' );
		list( , $out ) = $this->make( array( '--site=app.example.com', 'again' ) );
		$this->zdzk_keys[] = self::key_in( $this->zdzk_dir . '/again/zdz-secrets.php' );
		foreach ( ZDZ_Keys_Admin::config_lines() as $line ) {
			$this->assertStringContainsString( $line, $out, 'printed by the script' );
			$this->assertStringContainsString( $line, (string) file_get_contents( $file ), 'and written in the file\'s own note' );
		}
	}

	/* ── the file it writes ───────────────────────────────────────────────── */

	public function test_the_key_file_defines_nothing_unless_wp_config_asked_for_it(): void {
		$file = $this->made( 'app.example.com', 'keys' );

		list( $rc, $out, $err ) = self::zdzk_run( array( PHP_BINARY, '-l', $file ), array() );
		$this->assertSame( 0, $rc, 'it is valid PHP: ' . $out . $err );

		// asked for on its own: no output of any kind
		list( $rc, $out, $err ) = self::zdzk_run( array( PHP_BINARY, $file ), array() );
		$this->assertSame( array( 0, '', '' ), array( $rc, $out, $err ) );

		// loaded by something that did not ask as wp-config.php does: nothing defined, and the loader carries on
		$probe = $this->zdzk_dir . '/probe.php';
		file_put_contents( $probe, '<?php require ' . var_export( $file, true ) . '; echo defined( "ZDZ_APP_KEY" ) || defined( "ZDZ_APP_KEY_SITE" ) ? "defined" : "nothing defined", "; went on";' );
		list( $rc, $out, $err ) = self::zdzk_run( array( PHP_BINARY, $probe ), array() );
		$this->assertSame( array( 0, 'nothing defined; went on', '' ), array( $rc, $out, $err ) );

		// asked for as wp-config.php does: the key and its site, and loading it again is no warning
		file_put_contents( $probe, '<?php error_reporting( E_ALL ); define( "ZDZ_SECRETS_LOAD", true ); require ' . var_export( $file, true ) . '; require ' . var_export( $file, true ) . '; echo strlen( ZDZ_APP_KEY ), " ", ZDZ_APP_KEY_SITE, " ", defined( "ZDZ_APP_KEY_PREVIOUS" ) ? "previous" : "no previous";' );
		list( $rc, $out, $err ) = self::zdzk_run( array( PHP_BINARY, $probe ), array() );
		$this->assertSame( array( 0, '64 app.example.com no previous', '' ), array( $rc, $out, $err ) );

		// a line of the same name written earlier in wp-config.php wins, quietly: no "already defined" before any page
		file_put_contents( $probe, '<?php error_reporting( E_ALL ); define( "ZDZ_SECRETS_LOAD", true ); define( "ZDZ_APP_KEY_SITE", "earlier.example.com" ); require ' . var_export( $file, true ) . '; echo ZDZ_APP_KEY_SITE;' );
		list( $rc, $out, $err ) = self::zdzk_run( array( PHP_BINARY, $probe ), array() );
		$this->assertSame( array( 0, 'earlier.example.com', '' ), array( $rc, $out, $err ) );
	}

	public function test_over_the_web_the_key_file_and_the_script_answer_not_found_and_nothing_else(): void {
		$file = $this->made( 'app.example.com', 'webroot' );
		$root = dirname( $file );
		copy( zdzk_test_bin(), $root . '/make-app-keys.php' );

		$probe = @stream_socket_server( 'tcp://127.0.0.1:0' );
		if ( false === $probe ) {
			$this->markTestSkipped( 'no local port to serve from here' );
		}
		$port = (int) substr( (string) strrchr( (string) stream_socket_get_name( $probe, false ), ':' ), 1 );
		fclose( $probe );
		$log    = $this->zdzk_dir . '/server.log';
		$pipes  = array();
		$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root ), array( 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes, $root, array( 'PATH' => (string) getenv( 'PATH' ) ) ); // sentinel:allow a list of arguments (no shell): PHP's own web server on this computer only, for this test
		if ( ! is_resource( $server ) ) {
			$this->markTestSkipped( 'PHP\'s own web server could not be started here' );
		}
		try {
			$get = function ( string $path ) use ( $port ) {
				for ( $try = 0; $try < 60; $try++ ) {
					$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.5 );
					if ( false !== $conn ) {
						fwrite( $conn, "GET $path HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n" );
						$answer = (string) stream_get_contents( $conn );
						fclose( $conn );
						return $answer;
					}
					usleep( 50000 );
				}
				return null;
			};
			foreach ( array( '/zdz-secrets.php', '/make-app-keys.php' ) as $path ) {
				$answer = $get( $path );
				if ( null === $answer ) {
					$this->markTestSkipped( 'PHP\'s own web server did not answer here' );
				}
				$parts = explode( "\r\n\r\n", $answer, 2 );
				$this->assertSame( 1, preg_match( '#^HTTP/\d\.\d 404\b#', $parts[0] ), $path . ' answers "not found": ' . strtok( $parts[0], "\r\n" ) );
				$this->assertSame( '', $parts[1] ?? '', $path . ' sends nothing with it' );
				$this->assert_no_key_in( $answer, 'the answer to ' . $path );
			}
		} finally {
			proc_terminate( $server );
			proc_close( $server );
		}
		$this->assert_no_key_in( is_file( $log ) ? (string) file_get_contents( $log ) : '', 'the web server\'s log' );
		$this->assertSame( array( '.', '..', 'make-app-keys.php', 'zdz-secrets.php' ), scandir( $root ), 'and a request for the script made nothing' );
	}

	/* ── end to end: the made file on a site ──────────────────────────────── */

	public function test_a_site_set_up_as_the_page_says_is_ready_and_shows_the_same_key_id(): void {
		$root = $this->site_root( $this->made( 'app.example.com', 'keys' ) );
		$seen = $this->moment( 'first_use', array( 'wpconfig' => $root . '/wp-config.php' ) );
		$this->assert_held( $seen );
		$this->assertSame( zdzk_kid( self::key_in( $root . '/zdz-secrets.php' ) ), $this->stored()['options']['zdz_keys_state']['kid'], 'the key id the script printed is the one the app records' );
	}

	public function test_the_made_file_on_another_site_locks_instead_of_reading(): void {
		$root = $this->site_root( $this->made( 'staging.example.com', 'keys' ) );
		$this->assert_held( $this->moment( 'site_line_other_host', array( 'wpconfig' => $root . '/wp-config.php' ) ) );
		$this->assertFalse( file_exists( $this->store_file() ), 'and records nothing there' );
	}

	public function test_a_missing_key_file_never_takes_the_site_down(): void {
		// wp-config.php still has its two lines; the file is gone (a restore, a copy that left it out).
		$root = $this->site_root( null );
		$this->assertFalse( file_exists( $root . '/zdz-secrets.php' ) );
		$seen = $this->moment( 'file_loaded_but_no_key', array( 'wpconfig' => $root . '/wp-config.php' ) );
		$this->assert_held( $seen );   // the process ended cleanly, with no error and no warning: the site answers
		// and a site that had its key set up says the key is missing, on the same request, instead of failing
		$k = $this->key();
		$this->store( array( 'zdz_keys_state' => self::record( $k ), 'zdz_keys_ever' => 1, 'zdz_example_row' => zdzk_seal_under( $k, ZDZ_Keys::PURPOSES['credentials'], 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ) ), array(), 'was-set-up' );
		$this->assert_held( $this->moment( 'missing_after_set_up', array( 'wpconfig' => $root . '/wp-config.php' ), 'was-set-up' ) );
	}
}
