<?php
/**
 * What the app key's PHPUnit tests share: starting "one moment in a site's life" as a process of its own
 * (app-key-site.php), the options store that carries a site from one moment to the next, made-up keys, and the rule
 * every moment is held to: no key, in any spelling, in anything it printed, stored or logged.
 *
 * The classes under test are the theme's own; a run that wants another copy of them (to see the tests fail on a
 * changed one) names its folder in ZDZ_KEYS_TEST_INC, and another copy of the key maker in ZDZ_KEYS_TEST_BIN.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/app-key-format.php';

if ( ! function_exists( 'zdzk_test_inc' ) ) {
	/** The folder holding class-zdz-keys.php, class-zdz-keys-migrate.php and class-zdz-keys-admin.php. */
	function zdzk_test_inc() {
		$dir = (string) getenv( 'ZDZ_KEYS_TEST_INC' );
		return '' !== $dir ? rtrim( $dir, '/' ) : dirname( __DIR__, 3 ) . '/zorderz/inc';
	}
	/** The key maker an operator runs. */
	function zdzk_test_bin() {
		$file = (string) getenv( 'ZDZ_KEYS_TEST_BIN' );
		return '' !== $file ? $file : dirname( __DIR__, 3 ) . '/bin/make-app-keys.php';
	}
}

if ( ! trait_exists( 'ZDZK_Site_Harness' ) ) {

	trait ZDZK_Site_Harness {

		/** @var string this test's own folder: the options store, the error log, anything a case writes */
		private $zdzk_dir = '';

		/** @var string[] every made-up key this test drew: none may appear in anything a moment leaves behind */
		private $zdzk_keys = array();

		/** @var string everything the last moment left behind, for a test that learns of a key only afterwards */
		private $zdzk_left = '';

		private function zdzk_begin(): void {
			$this->zdzk_dir = sys_get_temp_dir() . '/zdzk-' . bin2hex( random_bytes( 6 ) );
			mkdir( $this->zdzk_dir, 0700 );
			$this->zdzk_keys = array();
		}

		private function zdzk_end(): void {
			if ( '' !== $this->zdzk_dir && is_dir( $this->zdzk_dir ) ) {
				self::zdzk_remove( $this->zdzk_dir );
			}
			$this->zdzk_dir  = '';
			$this->zdzk_keys = array();
		}

		private static function zdzk_remove( string $dir ): void {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::zdzk_remove( $path );
				} else {
					unlink( $path );
				}
			}
			rmdir( $dir );
		}

		/** A made-up printed key, new on every run and never printed. $digits: one whose key id is digits only. */
		private function key( bool $digits = false ): string {
			do {
				$k = zdzk_printed( random_bytes( 32 ) );
			} while ( $digits && ! preg_match( '/^\d{8}$/D', zdzk_kid( $k ) ) );
			$this->zdzk_keys[] = $k;
			return $k;
		}

		private function store_file( string $site = 'site' ): string {
			return $this->zdzk_dir . '/' . $site . '.json';
		}

		/** Give a site its database as an earlier moment left it: options, and what an app keeps in a table of its own. */
		private function store( array $options = array(), array $tables = array(), string $site = 'site' ): void {
			file_put_contents( $this->store_file( $site ), json_encode( array( 'options' => $options, 'tables' => $tables ) ) );
		}

		/** What the site's database holds now. */
		private function stored( string $site = 'site' ): array {
			$file = $this->store_file( $site );
			$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
			return array(
				'options' => (array) ( $data['options'] ?? array() ),
				'tables'  => (array) ( $data['tables'] ?? array() ),
			);
		}

		/** The record a site keeps of its key, as an earlier moment on $host would have written it. */
		private static function record( string $printed, string $host = 'app.example.com' ): array {
			return array(
				'kid'      => zdzk_kid( $printed ),
				'prev_kid' => '',
				'site'     => $host,
				'at'       => '2026-01-15 09:30:00',
				'by'       => 1,
				'how'      => 'first',
			);
		}

		/**
		 * Run a command with its own environment and no shell. [ exit code, what it printed, what it complained of ].
		 *
		 * @param string[] $command
		 * @param array    $env
		 */
		private static function zdzk_run( array $command, array $env, ?string $cwd = null ): array {
			$env  += array( 'PATH' => (string) getenv( 'PATH' ) );
			$pipes = array();
			$proc  = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env ); // sentinel:allow a list of arguments (no shell): PHP itself and this repository's own test files, with made-up keys
			if ( ! is_resource( $proc ) ) {
				return array( -1, '', 'the process could not be started' );
			}
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			return array( proc_close( $proc ), $out, $err );
		}

		/**
		 * One moment in a site's life: the case $case, with these wp-config.php lines and salts ($env: key, prev, site,
		 * load, wpconfig, home, salts, k1..k3, arg), on the database of $site. It must end cleanly, complain of nothing
		 * (a PHP warning is a failure), and leave no key behind: not in what it answered, stored or logged, and not in
		 * any page, notice or Site Health item it rendered. Returns what it saw.
		 *
		 * @param string[] $php extra arguments for PHP itself
		 */
		private function moment( string $case, array $env = array(), string $site = 'site', array $php = array() ): array {
			$elog  = $this->zdzk_dir . '/php-error.log';
			$shown = $this->zdzk_dir . '/shown.html';
			$vars  = array(
				'ZDZK_CASE'  => $case,
				'ZDZK_STORE' => $this->store_file( $site ),
				'ZDZK_ELOG'  => $elog,
				'ZDZK_SHOWN' => $shown,
				'ZDZK_INC'   => zdzk_test_inc(),
			);
			foreach ( $env as $name => $value ) {
				$vars[ 'ZDZK_' . strtoupper( (string) $name ) ] = (string) $value;
			}
			list( $rc, $out, $err ) = self::zdzk_run( array_merge( array( PHP_BINARY ), $php, array( __DIR__ . '/app-key-site.php' ) ), $vars );
			$this->assertSame( '', $err, "the case \"$case\" complained:\n" . $err );
			$this->assertSame( 0, $rc, "the case \"$case\" did not end cleanly:\n" . substr( $out, -2000 ) );
			$seen = json_decode( $out, true );
			$this->assertIsArray( $seen, "the case \"$case\" did not answer:\n" . substr( $out, 0, 2000 ) );
			$pages = is_file( $shown ) ? (string) file_get_contents( $shown ) : '';
			$left  = $out . $err . $pages
				. ( is_file( $this->store_file( $site ) ) ? (string) file_get_contents( $this->store_file( $site ) ) : '' )
				. ( is_file( $elog ) ? (string) file_get_contents( $elog ) : '' );
			$this->zdzk_left = $left;
			$this->assert_no_key_in( $left, "the case \"$case\"" );
			// and of every page, notice and Site Health item it rendered: nothing that stops to ask the browser (an admin
			// page that automation drives must not block), and no password box (a browser would offer to save the key)
			$this->assertStringNotContainsString( 'confirm(', $pages, "the case \"$case\" rendered a browser confirm()" );
			$this->assertStringNotContainsString( 'type="password"', $pages, "the case \"$case\" rendered a password box" );
			return $seen;
		}

		/** No key this test drew appears in $text: as printed, without its spaces, in lower case, raw, hex or base64. */
		private function assert_no_key_in( string $text, string $where ): void {
			foreach ( $this->zdzk_keys as $n => $printed ) {
				$raw      = zdzk_raw( $printed );
				$bare     = str_replace( ' ', '', $printed );
				$spelling = array(
					'as printed'           => $printed,
					'without its spaces'   => $bare,
					'in lower case'        => strtolower( $printed ),
					'bare, in lower case'  => strtolower( $bare ),
					'with dashes'          => str_replace( ' ', '-', $printed ),
					'as raw bytes'         => $raw,
					'in hex'               => bin2hex( $raw ),
					'in base64'            => rtrim( base64_encode( $raw ), '=' ),
					'in base64url'         => rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ),
				);
				foreach ( $spelling as $how => $needle ) {
					$this->assertFalse( strpos( $text, $needle ), "$where left key #" . ( $n + 1 ) . " behind, $how" );
				}
			}
		}

		/** Every check the case made held. */
		private function assert_held( array $seen ): void {
			$checks = (array) ( $seen['checks'] ?? array() );
			$this->assertNotEmpty( $checks, 'the case "' . ( $seen['case'] ?? '?' ) . '" checked nothing' );
			$failed = array();
			foreach ( $checks as $check ) {
				if ( true !== ( $check[0] ?? null ) ) {
					$failed[] = (string) ( $check[1] ?? '?' );
				}
			}
			$this->assertSame( array(), $failed, 'the case "' . ( $seen['case'] ?? '?' ) . "\" did not hold:\n - " . implode( "\n - ", $failed ) );
		}
	}
}
