<?php
/**
 * Scheduler — the read-only Health panel's verdicts, and the observable zone database.
 *
 *   - storage_verdict() can never say "ok" on the narrow 767-byte limit or an unknown row
 *     format; the limit is per indexed column.
 *   - cron/version verdicts: an unscheduled tick is bad, code and schema agreeing is ok.
 *   - The zone database is fingerprinted from real transitions (stable, order-independent),
 *     the DST self-test passes on a zone with seasonal time and answers n/a on one without,
 *     and the declared zones come from configuration — Core declares only UTC.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
}

$zsch_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/';
require_once $zsch_inc . 'class-zsch-settings.php';
require_once $zsch_inc . 'class-zsch-tzdata.php';
require_once $zsch_inc . 'class-zsch-health.php';

final class SchedulerHealthTzdataTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	/* ── Storage engine ───────────────────────────────────────────────────── */

	public function test_storage_verdict_is_never_ok_on_a_narrow_or_unknown_format(): void {
		foreach ( array( 'Compact', 'Redundant', '', 'Mystery' ) as $tbl ) {
			foreach ( array( 'Dynamic', 'Compact', '' ) as $srv ) {
				$limit = ZSCH_Health::index_limit_for( $tbl );
				$v     = ZSCH_Health::storage_verdict( $tbl, $srv, $limit );
				$this->assertNotSame( 'ok', $v['level'], "table=$tbl server=$srv" );
			}
		}
		$this->assertSame( 'ok', ZSCH_Health::storage_verdict( 'Dynamic', 'Dynamic', 3072 )['level'] );
	}

	public function test_the_767_limit_is_per_column(): void {
		$this->assertSame( 767, ZSCH_Health::index_limit_for( 'Compact' ) );
		$this->assertSame( 3072, ZSCH_Health::index_limit_for( 'Dynamic' ) );
		$this->assertSame( 767, ZSCH_Health::index_limit_for( 'unknown' ), 'unknown is assumed narrow' );
		$this->assertStringContainsString( 'whole index may still total 3072', ZSCH_Health::storage_verdict( 'Compact', 'Dynamic', 767 )['text'] );
	}

	/* ── Cron and version ─────────────────────────────────────────────────── */

	public function test_cron_verdicts(): void {
		$this->assertSame( 'bad', ZSCH_Health::cron_verdict( false, 1000 )['level'] );
		$this->assertSame( 'ok', ZSCH_Health::cron_verdict( 1200, 1000 )['level'] );
		$this->assertNotSame( 'ok', ZSCH_Health::cron_verdict( 1000 - ZSCH_Health::CRON_GRACE - 60, 1000 )['level'], 'overdue past the grace window' );
	}

	public function test_version_verdicts(): void {
		$this->assertSame( 'bad', ZSCH_Health::version_verdict( '1.10.0', '' )['level'] );
		$this->assertSame( 'ok', ZSCH_Health::version_verdict( '1.10.0', '1.10.0' )['level'] );
		$this->assertNotSame( 'ok', ZSCH_Health::version_verdict( '1.10.0', '1.9.0' )['level'] );
	}

	public function test_the_panel_expects_the_new_columns_and_keys(): void {
		$this->assertContains( 'override_key', ZSCH_Health::EXPECTED['zsch_appointments'] );
		$this->assertContains( 'pulled_body_hash', ZSCH_Health::EXPECTED['zsch_graph_map'] );
		foreach ( ZSCH_Health::EXPECTED_INDEXES['zsch_appointments'] as $idx ) {
			$this->assertDoesNotMatchRegularExpression( '/^idx_t/', $idx, 'index names use this app\'s own prefix' );
		}
		$migration = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/db/migrate-1.10.0.php' );
		foreach ( ZSCH_Health::EXPECTED_INDEXES['zsch_appointments'] as $idx ) {
			$this->assertStringContainsString( 'KEY ' . $idx . ' ', $migration, $idx . ' is declared by the migration the panel checks' );
		}
	}

	/* ── Zone database ────────────────────────────────────────────────────── */

	public function test_fingerprint_is_stable_and_order_independent(): void {
		$a = ZSCH_Tzdata::fingerprint( array( 'UTC', 'America/New_York' ) );
		$b = ZSCH_Tzdata::fingerprint( array( 'America/New_York', 'UTC', 'UTC' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, ZSCH_Tzdata::fingerprint( array( 'UTC', 'Europe/London' ) ) );
	}

	public function test_dst_selftest(): void {
		$checks = ZSCH_Tzdata::selftest( 'America/New_York' );
		$this->assertCount( 3, $checks );
		$this->assertSame( 'pass', ZSCH_Tzdata::selftest_status( $checks ) );
		$this->assertSame( 'n/a', ZSCH_Tzdata::selftest_status( ZSCH_Tzdata::selftest( 'UTC' ) ), 'no seasonal time: an honest n/a' );
		$this->assertSame( 'fail', ZSCH_Tzdata::selftest( 'Not/AZone' )[0]['status'] );
	}

	public function test_wall_clock_resolution_is_the_same_in_every_zone(): void {
		// The repeated hour → its FIRST occurrence; the skipped hour → the pre-gap offset.
		$this->assertSame( '2027-10-31 00:30:00', ZSCH_Tzdata::local_to_utc( '2027-10-31 01:30:00', 'Europe/London' ), 'first of the two 01:30s (summer time)' );
		$this->assertSame( '2027-03-28 01:30:00', ZSCH_Tzdata::local_to_utc( '2027-03-28 01:30:00', 'Europe/London' ), 'skipped hour read with the winter offset' );
		$this->assertSame( '2026-11-01 05:30:00', ZSCH_Tzdata::local_to_utc( '2026-11-01 01:30:00', 'America/New_York' ) );
		$this->assertSame( '2026-03-08 07:30:00', ZSCH_Tzdata::local_to_utc( '2026-03-08 02:30:00', 'America/New_York' ) );
		$this->assertSame( '2027-07-01 08:00:00', ZSCH_Tzdata::local_to_utc( '2027-07-01 09:00:00', 'Europe/London' ), 'an ordinary time is untouched' );
		$this->assertSame( '', ZSCH_Tzdata::local_to_utc( 'not a time', 'Europe/London' ) );
		$this->assertSame( '', ZSCH_Tzdata::local_to_utc( '2027-07-01 09:00:00', 'Not/AZone' ) );
		foreach ( array( 'Europe/London', 'Europe/Berlin', 'Australia/Sydney', 'Pacific/Auckland', 'America/Chicago' ) as $zone ) {
			$this->assertSame( 'pass', ZSCH_Tzdata::selftest_status( ZSCH_Tzdata::selftest( $zone ) ), $zone );
		}
	}

	public function test_core_declares_only_utc_and_the_business_zone_comes_from_configuration(): void {
		$this->assertSame( array( 'UTC' ), ZSCH_Tzdata::DEFAULT_ZONES );
		$this->assertSame( array( 'UTC' ), ZSCH_Tzdata::zones(), 'nothing configured: UTC only' );

		$GLOBALS['zdz_test_options']['zsch_graph_config'] = array( 'default_tz' => 'Europe/London' );
		$this->assertSame( 'Europe/London', ZSCH_Tzdata::primary_zone() );
		$this->assertContains( 'Europe/London', ZSCH_Tzdata::zones() );
		$this->assertSame( 'pass', ZSCH_Tzdata::selftest_status( ZSCH_Tzdata::selftest() ), 'the self-test runs on the configured zone' );

		$GLOBALS['zdz_test_options']['zsch_graph_config'] = array( 'default_tz' => 'Not/AZone' );
		$this->assertSame( 'UTC', ZSCH_Tzdata::primary_zone(), 'an unreadable zone falls back to UTC' );
	}
}
