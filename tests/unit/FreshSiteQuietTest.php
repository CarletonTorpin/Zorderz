<?php
/**
 * A new site stays quiet and whole: what installing on a managed host and opening every screen showed.
 *
 *   - Leads: the deferred owner backfill clears its marker when there is nothing to seed, so it does
 *     not run again (and write to the log) on every request; a failed read keeps it for a retry.
 *   - Media: a site with no table gets it at today's schema, never the first-release shape that the
 *     EXIF step has already passed over; the table made from scratch has every column the upgrade adds,
 *     and admin requests bring a table left at an older schema up to date.
 *   - Integration Health names say what they check, with no build-stage labels.
 *   - Home: a tile's "as of" is written the business's way, like the figure beside it.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-lead-assignment.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-user-media.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/db/migrate-2.17.1.php';

/** Answers the few statements these paths send; records every one. */
final class Zdz_Fresh_Site_Db {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();
	public $tables     = array();
	public $results    = array();
	public $fail_reads = false;

	public function get_charset_collate() { return ''; }

	public function get_var( $q ) {
		$this->queries[] = $q;
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $q, $m ) ) {
			return in_array( $m[1], $this->tables, true ) ? $m[1] : null;
		}
		return null;
	}

	public function get_col( $q, $i = 0 ) {
		$this->queries[] = $q;
		return array();
	}

	public function get_results( $q, $output = null ) {
		$this->queries[] = $q;
		if ( $this->fail_reads ) {
			$this->last_error = "Table 'wp_zl_batches' doesn't exist";
			return null;
		}
		return $this->results;
	}

	public function query( $q ) {
		$this->queries[] = $q;
		if ( preg_match( '/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $q, $m ) ) {
			$this->tables[] = $m[1];
		}
		return true;
	}

	public function prepare( $q, ...$args ) { return $q; }
}

final class FreshSiteQuietTest extends TestCase {

	private $db;

	protected function setUp(): void {
		$this->db        = new Zdz_Fresh_Site_Db();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['zdz_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'] );
	}

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel );
	}

	/* ── Leads ────────────────────────────────────────────────────────────── */

	public function test_nothing_to_seed_clears_the_deferred_marker(): void {
		$GLOBALS['zdz_test_options']['zl_assignment_backfill_pending'] = 1;
		$this->assertSame( 0, ZL_Lead_Assignment::backfill_from_batches() );
		$this->assertArrayNotHasKey( 'zl_assignment_backfill_pending', $GLOBALS['zdz_test_options'], 'cleared, so the next request skips it' );

		$reads = count( $this->db->queries );
		ZL_Lead_Assignment::maybe_run_deferred_backfill();
		$this->assertCount( $reads, $this->db->queries, 'no marker, no query' );
	}

	public function test_a_failed_read_keeps_the_marker_for_a_retry(): void {
		$GLOBALS['zdz_test_options']['zl_assignment_backfill_pending'] = 1;
		$this->db->fail_reads = true;
		$this->assertSame( 0, ZL_Lead_Assignment::backfill_from_batches() );
		$this->assertArrayHasKey( 'zl_assignment_backfill_pending', $GLOBALS['zdz_test_options'] );
	}

	/* ── Media table ──────────────────────────────────────────────────────── */

	public function test_a_site_with_no_table_never_gets_the_first_release_shape(): void {
		zdz_theme_migrate_2_17_1();
		$old = array_filter( $this->db->queries, static function ( $q ) {
			return false !== strpos( $q, 'CREATE TABLE' ) && false === strpos( $q, 'gps_lat' );
		} );
		$this->assertCount( 0, $old, 'no table without the EXIF columns' );
		$this->assertNotSame( 2, $GLOBALS['zdz_test_options']['zdz_media_schema'] ?? null, 'never stamped at the first-release schema' );
	}

	public function test_the_new_table_has_every_column_the_upgrade_adds(): void {
		$src = self::src( 'zorderz/inc/class-zdz-user-media.php' );
		$this->assertSame( 1, preg_match( '/CREATE TABLE IF NOT EXISTS `\{\$table\}` \((.*?)\) \{\$charset\}/s', $src, $m ) );
		preg_match_all( '/ADD COLUMN `([a-z_]+)`/', $src, $added );
		$this->assertNotEmpty( $added[1] );
		foreach ( array_unique( $added[1] ) as $col ) {
			$this->assertStringContainsString( '`' . $col . '`', $m[1], $col . ' is in the table made from scratch' );
		}
		preg_match_all( '/ADD KEY `([a-z_]+)`/', $src, $keys );
		foreach ( array_unique( $keys[1] ) as $key ) {
			$this->assertStringContainsString( 'KEY `' . $key . '`', $m[1], $key . ' is in the table made from scratch' );
		}
	}

	public function test_admin_requests_bring_an_older_table_up_to_date(): void {
		$this->assertTrue( method_exists( 'ZDZ_User_Media', 'install' ) );
		$this->assertStringContainsString( "add_action( 'admin_init', array( 'ZDZ_User_Media', 'install' ) );", self::src( 'zorderz/inc/class-zdz-user-media.php' ) );
	}

	/* ── Integration Health ───────────────────────────────────────────────── */

	public function test_health_check_names_carry_no_build_stage_labels(): void {
		$src = self::src( 'zorderz/inc/class-zdz-integration-tests.php' );
		$this->assertDoesNotMatchRegularExpression( '/\(Group \d+[A-Z]?\)|Group \d+[A-Z]? not yet deployed/', $src );
		$this->assertStringContainsString( "'name'   => 'Permission System',", $src );
	}

	/* ── Home: "as of" ────────────────────────────────────────────────────── */

	public function test_a_tiles_as_of_is_written_the_business_way(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$js  = file_get_contents( __DIR__ . '/support/app-js-asof.js' );
		$app = dirname( __DIR__, 2 ) . '/zorderz/assets/js/app.js';
		$out = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $js ) . ' ' . escapeshellarg( $app ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d
		$res = json_decode( (string) $out, true );
		$this->assertIsArray( $res, 'the page side did not run: ' . substr( (string) $out, 0, 400 ) );
		$this->assertSame( 'as of 22:05', $res['gb'] );
		$this->assertMatchesRegularExpression( '/^as of 10:05\s?PM$/u', $res['us'] );
		$this->assertStringContainsString( '05', $res['odd'], 'a locale the browser refuses falls back, never throws' );
	}
}
