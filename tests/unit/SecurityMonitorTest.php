<?php
/**
 * Security Monitor: the decisions that keep it correct, polite to the feed provider and safe
 * with the feed key.
 *
 *   - version-range matching against feed entries ("*" = unbounded, inclusivity, the fix for
 *     THIS install, one finding per advisory, severity and the registration downgrade);
 *   - the feed reader (records across reads, resume per record, damaged input refused);
 *   - feed-size decisions: work sized for the host's request wall, the memory stop, and the
 *     verdict that decides whether a download may replace the cached copy;
 *   - the scheduling window: never asked before the job library has started, the 30-minute
 *     pacing, backoffs, the single retry, staleness;
 *   - alert de-duplication: nothing new = nothing sent; a failed delivery is retried;
 *   - no key: nothing is requested, the feed reads "not connected", the local checks still run;
 *   - the key never appears in a URL, a message, a result or a redirected request.
 *
 * All data here is neutral: example slugs, example.com hosts, made-up keys.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

$zsm_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/security-monitor/includes/';
require_once $zsm_inc . 'class-zsm-policy.php';
require_once $zsm_inc . 'class-zsm.php';
require_once $zsm_inc . 'class-zsm-feed-stream.php';
require_once $zsm_inc . 'class-zsm-feed.php';
require_once $zsm_inc . 'class-zsm-matcher.php';
require_once $zsm_inc . 'class-zsm-inventory.php';
require_once $zsm_inc . 'class-zsm-currency.php';
require_once $zsm_inc . 'class-zsm-findings.php';
require_once $zsm_inc . 'class-zsm-code.php';
require_once $zsm_inc . 'class-zsm-report.php';
unset( $zsm_inc );

final class SecurityMonitorTest extends TestCase {

	const KEY = 'example-feed-key-0123456789abcdef';

	private $tmp = array();

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'] );
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp = array();
	}

	private function file_with( string $content ): string {
		$f = tempnam( sys_get_temp_dir(), 'zsm' );
		file_put_contents( $f, $content );
		$this->tmp[] = $f;
		return $f;
	}

	/** Scan a whole file; optionally stop after every record and resume from the saved offset. */
	private static function scan_all( string $file, bool $resume_each ): array {
		$ids    = array();
		$off    = 0;
		$slices = 0;
		do {
			$fh = fopen( $file, 'rb' );
			$r  = ZSM_Feed_Stream::scan(
				$fh,
				$off,
				function ( $t ) use ( &$ids ) {
					$d     = json_decode( $t, true );
					$ids[] = is_array( $d ) ? ( $d['id'] ?? '?' ) : 'BAD';
				},
				$resume_each ? 0.0 : microtime( true ) + 3600
			);
			fclose( $fh );
			$off = $r['offset'];
			$slices++;
		} while ( ! $r['done'] && '' === $r['error'] && $slices < 100000 );
		return array( 'ids' => $ids, 'done' => $r['done'], 'error' => $r['error'], 'slices' => $slices );
	}

	/* ── Version ranges ──────────────────────────────────────────────────── */

	public function test_affected_iff_inside_the_range_with_its_inclusivity(): void {
		$this->assertTrue( ZSM_Matcher::in_range( '1.1.0', '1.0.0', 1, '1.2.3', 1 ) );
		$this->assertTrue( ZSM_Matcher::in_range( '1.0.0', '1.0.0', 1, '1.2.3', 1 ), 'lower bound inclusive' );
		$this->assertTrue( ZSM_Matcher::in_range( '1.2.3', '1.0.0', 1, '1.2.3', 1 ), 'upper bound inclusive' );
		$this->assertFalse( ZSM_Matcher::in_range( '1.2.4', '1.0.0', 1, '1.2.3', 1 ), 'the patched version is not affected' );
		$this->assertFalse( ZSM_Matcher::in_range( '0.9.9', '1.0.0', 1, '1.2.3', 1 ) );
		$this->assertFalse( ZSM_Matcher::in_range( '1.0.0', '1.0.0', 0, '2.0.0', 1 ), 'from exclusive' );
		$this->assertTrue( ZSM_Matcher::in_range( '1.0.1', '1.0.0', 0, '2.0.0', 1 ) );
		$this->assertFalse( ZSM_Matcher::in_range( '2.0.0', '1.0.0', 1, '2.0.0', 0 ), 'to exclusive: the classic "below the fix"' );
		$this->assertTrue( ZSM_Matcher::in_range( '1.9.9', '1.0.0', 1, '2.0.0', 0 ) );
		$this->assertTrue( ZSM_Matcher::in_range( '8.6.1', '0.0.0', 1, '9.0.0', 0 ), 'a wide range catches what equality would miss' );
	}

	public function test_star_and_empty_bounds_are_unbounded_and_no_version_never_matches(): void {
		$this->assertTrue( ZSM_Matcher::in_range( '9.3.1', '*', 1, '*', 1 ), '"*" to "*" (no fix yet) affects every version' );
		$this->assertTrue( ZSM_Matcher::in_range( '9.3.1', '*', 1, '9.4.0', 1 ) );
		$this->assertFalse( ZSM_Matcher::in_range( '9.4.1', '*', 1, '9.4.0', 1 ) );
		$this->assertTrue( ZSM_Matcher::in_range( '2.0.0', '1.5', 1, '*', 1 ) );
		$this->assertFalse( ZSM_Matcher::in_range( '1.4', '1.5', 1, '*', 1 ) );
		$this->assertTrue( ZSM_Matcher::in_range( '2.0', ' * ', 1, ' * ', 0 ), 'whitespace around "*"' );
		$this->assertTrue( ZSM_Matcher::in_range( '3.1.4', '', 1, '', 0 ), 'both bounds empty' );
		$this->assertFalse( ZSM_Matcher::in_range( '', '1.0', 1, '2.0', 1 ), 'no installed version: fail closed' );
	}

	public function test_best_fix_is_the_lowest_patched_version_above_the_installed_one(): void {
		$this->assertSame( '6.0.3', ZSM_Matcher::best_fix( '6.0.2', '3.7.40,6.0.3,6.1.1' ) );
		$this->assertSame( '1.38', ZSM_Matcher::best_fix( '1.37', '1.38' ) );
		$this->assertSame( '', ZSM_Matcher::best_fix( '2.0', '' ) );
		$this->assertSame( '', ZSM_Matcher::best_fix( '9.0', '1.0,2.0' ), 'never an older version' );
	}

	public function test_severity_downgrade_and_rank(): void {
		$this->assertSame( 'high', ZSM_Matcher::severity( 'High', null ) );
		$this->assertSame( 'critical', ZSM_Matcher::severity( '', 9.1 ) );
		$this->assertSame( 'high', ZSM_Matcher::severity( '', 7.0 ) );
		$this->assertSame( 'medium', ZSM_Matcher::severity( '', 4.0 ) );
		$this->assertSame( 'low', ZSM_Matcher::severity( '', 0.1 ) );
		$this->assertSame( 'info', ZSM_Matcher::severity( '', null ) );
		$this->assertSame( 'critical', ZSM_Matcher::severity( 'CRITICAL', 2.0 ), 'the rating wins over the score' );
		$this->assertSame( 'high', ZSM_Matcher::downgrade( 'critical' ) );
		$this->assertSame( 'info', ZSM_Matcher::downgrade( 'low' ) );
		$this->assertSame( 'info', ZSM_Matcher::downgrade( 'info' ) );
		$this->assertGreaterThan( ZSM_Matcher::rank( 'high' ), ZSM_Matcher::rank( 'critical' ) );
		$this->assertGreaterThan( ZSM_Matcher::rank( 'info' ), ZSM_Matcher::rank( 'low' ) );
	}

	public function test_match_component_one_finding_per_advisory_with_context(): void {
		$c    = array( 'slug' => 'Example-Plugin', 'name' => 'Example Plugin', 'version' => '1.2.0', 'comp_type' => 'plugin' );
		$rows = array(
			array( 'advisory_id' => 'adv-1', 'from_version' => '*', 'from_inclusive' => 1, 'to_version' => '1.3', 'to_inclusive' => 0, 'cvss_rating' => 'High', 'cvss_score' => '7.5', 'auth_required' => 1, 'informational' => 0, 'patched_version' => '1.3', 'cve' => 'CVE-2099-0001', 'title' => 'Example Plugin <= 1.2.9 - Authenticated (Subscriber+) Stored XSS', 'ref_url' => 'https://example.com/a' ),
			array( 'advisory_id' => 'adv-1', 'from_version' => '1.0', 'from_inclusive' => 1, 'to_version' => '1.2.5', 'to_inclusive' => 1, 'cvss_rating' => 'High', 'cvss_score' => '7.5', 'auth_required' => 1, 'informational' => 0, 'patched_version' => '1.3', 'cve' => 'CVE-2099-0001', 'title' => 'dup range', 'ref_url' => '' ),
			array( 'advisory_id' => 'adv-2', 'from_version' => '*', 'from_inclusive' => 1, 'to_version' => '*', 'to_inclusive' => 1, 'cvss_rating' => '', 'cvss_score' => null, 'auth_required' => 0, 'informational' => 1, 'patched_version' => '', 'cve' => '', 'title' => 'Closed', 'ref_url' => '' ),
			array( 'advisory_id' => 'adv-3', 'from_version' => '2.0', 'from_inclusive' => 1, 'to_version' => '*', 'to_inclusive' => 1, 'cvss_rating' => 'Critical', 'cvss_score' => '9.8', 'auth_required' => 0, 'informational' => 0, 'patched_version' => '', 'cve' => '', 'title' => 'not this version', 'ref_url' => '' ),
		);
		$closed = ZSM_Matcher::match_component( $c, $rows, false );
		$this->assertCount( 2, $closed, 'two ranges of one advisory make one finding; an out-of-range advisory none' );
		$this->assertSame( 'medium', $closed[0]['severity'], 'a login-only bug is downgraded while registration is closed' );
		$this->assertStringContainsString( 'downgraded', $closed[0]['context_note'] );
		$this->assertSame( '1.3', $closed[0]['fixed_version'] );
		$this->assertSame( 'example-plugin', $closed[0]['slug'], 'slugs are compared in lower case' );
		$this->assertSame( 'info', $closed[1]['severity'], 'an informational notice is info' );
		$open = ZSM_Matcher::match_component( $c, $rows, true );
		$this->assertSame( 'high', $open[0]['severity'], 'no downgrade while registration is open' );
		$this->assertSame( $closed[0]['finding_key'], $open[0]['finding_key'], 'the key does not depend on the severity' );
		$this->assertSame( array(), ZSM_Matcher::match_component( $c + array( 'first_party' => true ), $rows, false ), 'your own code is never matched against the feed' );
		$this->assertSame( array(), ZSM_Matcher::match_component( array( 'version' => '' ) + $c, $rows, false ) );
		$this->assertSame( 'wordpress', ZSM_Matcher::feed_slug( array( 'comp_type' => 'core', 'slug' => 'x' ), array() ) );
		$this->assertSame( 'feed-name', ZSM_Matcher::feed_slug( array( 'comp_type' => 'plugin', 'slug' => 'Local-Name' ), array( 'local-name' => 'feed-name' ) ) );
	}

	/* ── Feed records ────────────────────────────────────────────────────── */

	public function test_auth_required_reads_the_vector_then_the_title(): void {
		$this->assertFalse( ZSM_Feed::auth_required( 'X - Authenticated (Subscriber+) Stored XSS', 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H' ) );
		$this->assertTrue( ZSM_Feed::auth_required( 'X - Unauthenticated SQLi', 'CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N' ) );
		$this->assertTrue( ZSM_Feed::auth_required( '', 'CVSS:3.1/AV:N/AC:L/PR:H/UI:N/S:C/C:L/I:L/A:N' ) );
		$this->assertFalse( ZSM_Feed::auth_required( 'Example <= 9.4.0 - Unauthenticated SQL Injection', '' ), '"Unauthenticated" is not "authenticated"' );
		$this->assertTrue( ZSM_Feed::auth_required( 'X <= 1.0 - Authenticated (Contributor+) Stored XSS', '' ) );
		$this->assertFalse( ZSM_Feed::auth_required( 'X <= 1.0 - Cross-Site Request Forgery', '' ), 'no hint: no downgrade' );
	}

	public function test_rows_for_record_keeps_installed_software_and_core_only(): void {
		$rec  = array(
			'id'         => 'abc',
			'title'      => 'P <= 1.37 - Unauthenticated RCE',
			'cve'        => 'CVE-2099-1',
			'cvss'       => array( 'vector' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 'score' => 9.8, 'rating' => 'Critical' ),
			'references' => array( 'https://example.com/advisory' ),
			'software'   => array(
				array( 'type' => 'plugin', 'slug' => 'wanted-one', 'affected_versions' => array( '* - 1.37' => array( 'from_version' => '*', 'from_inclusive' => true, 'to_version' => '1.37', 'to_inclusive' => true ), '2.0 - 2.1' => array( 'from_version' => '2.0', 'from_inclusive' => true, 'to_version' => '2.1', 'to_inclusive' => false ) ), 'patched_versions' => array( '1.38' ) ),
				array( 'type' => 'plugin', 'slug' => 'not-installed', 'affected_versions' => array( '*' => array( 'from_version' => '*', 'to_version' => '*' ) ) ),
				array( 'type' => 'core', 'slug' => 'whatever', 'affected_versions' => array( '*' => array( 'from_version' => '*', 'from_inclusive' => true, 'to_version' => '6.4.2', 'to_inclusive' => true ) ) ),
				array( 'type' => 'plugin', 'slug' => 'wanted-one', 'patched_versions' => array() ),
			),
		);
		$rows = ZSM_Feed::rows_for_record( $rec, array( 'wanted-one' => true ) );
		$this->assertCount( 3, $rows, 'two ranges for the installed plugin and one for core; none for the rest' );
		$this->assertSame( 'wordpress', $rows[2]['slug'] );
		$this->assertSame( '*', $rows[0]['from_version'] );
		$this->assertSame( '1.38', $rows[0]['patched_version'] );
		$this->assertSame( 0, $rows[0]['auth_required'] );
		$long                                      = $rec;
		$long['software'][0]['patched_versions'] = array_map( function ( $i ) { return "6.$i.3"; }, range( 0, 120 ) );
		$lp                                        = ZSM_Feed::rows_for_record( $long, array( 'wanted-one' => true ) )[0]['patched_version'];
		$this->assertGreaterThan( 600, strlen( $lp ), 'a long patch list is kept whole' );
		$this->assertSame( array(), ZSM_Feed::rows_for_record( array( 'software' => $rec['software'] ), array( 'wanted-one' => true ) ), 'a record without an id is ignored' );
	}

	/* ── The feed reader ─────────────────────────────────────────────────── */

	public function test_stream_reads_records_across_reads_and_resumes_per_record(): void {
		$recs = array();
		for ( $n = 0; $n < 300; $n++ ) {
			$recs[ "id-$n" ] = array(
				'id'    => "id-$n",
				'title' => "q\"uote {brace} [bracket] back\\slash \\\" end-$n",
				'pad'   => str_repeat( 'x', 7000 + ( $n * 37 ) % 5000 ),
				'nest'  => array( 'a' => array( 'b' => array( 1, 2, array( 'c' => '}' ) ) ) ),
			);
		}
		$json = json_encode( $recs, JSON_UNESCAPED_SLASHES );
		$this->assertGreaterThan( 2 * ZSM_Feed_Stream::CHUNK, strlen( $json ) );
		$f   = $this->file_with( $json );
		$one = self::scan_all( $f, false );
		$this->assertTrue( $one['done'] && '' === $one['error'] );
		$this->assertSame( array_keys( $recs ), $one['ids'] );
		$each = self::scan_all( $f, true );
		$this->assertSame( array_keys( $recs ), $each['ids'], 'stopping after every record and resuming gives the same records' );
		$this->assertGreaterThanOrEqual( 300, $each['slices'] );
		$this->assertSame( array_keys( $recs ), self::scan_all( $this->file_with( json_encode( $recs, JSON_PRETTY_PRINT ) ), true )['ids'], 'pretty-printed' );
		$this->assertSame( array_keys( $recs ), self::scan_all( $this->file_with( json_encode( array_values( $recs ) ) ), false )['ids'], 'a list root' );
	}

	public function test_stream_refuses_damaged_input(): void {
		$json = json_encode( array( 'a' => array( 'id' => 'a', 'x' => str_repeat( 'y', 5000 ) ), 'b' => array( 'id' => 'b' ) ) );
		$cut  = self::scan_all( $this->file_with( substr( $json, 0, (int) ( strlen( $json ) * 0.6 ) ) ), false );
		$this->assertFalse( $cut['done'] );
		$this->assertStringContainsString( 'ended mid-document', $cut['error'] );
		$this->assertNotSame( '', self::scan_all( $this->file_with( '' ), false )['error'] );
		$huge = json_encode( array( 'a' => array( 'id' => 'a', 'x' => str_repeat( 'y', 3 * ZSM_Feed_Stream::CHUNK ) ) ) );
		$fh   = fopen( $this->file_with( $huge ), 'rb' );
		$big  = ZSM_Feed_Stream::scan( $fh, 0, function () {}, microtime( true ) + 60, ZSM_Feed_Stream::CHUNK );
		fclose( $fh );
		$this->assertStringContainsString( 'larger than', $big['error'], 'a record over the size cap is an error, not a memory blow-up' );
		$p = ZSM_Feed_Stream::peek( '{"software":[{"type":"plugin","slug":"Example-One"},{"type" : "theme", "slug" : "example-theme"}]}' );
		$this->assertTrue( isset( $p['slugs']['example-one'], $p['slugs']['example-theme'] ) );
		$this->assertFalse( $p['core'] );
		$this->assertTrue( ZSM_Feed_Stream::peek( '{"software":[{"type": "core","slug":"wordpress"}]}' )['core'] );
	}

	/* ── Feed size for the host ──────────────────────────────────────────── */

	public function test_work_is_sized_for_the_host_request_wall(): void {
		$this->assertSame( array( 'wall' => 60, 'slice' => 20.0, 'download_timeout' => 50 ), ZSM_Policy::budget( 60 ), 'a one-minute wall: 20 s steps, 50 s download' );
		$this->assertSame( array( 'wall' => 60, 'slice' => 20.0, 'download_timeout' => 50 ), ZSM_Policy::budget( 0 ), 'unknown: a minute is assumed' );
		$this->assertSame( array( 'wall' => 30, 'slice' => 10.0, 'download_timeout' => 20 ), ZSM_Policy::budget( 30 ), 'a tight host gets smaller steps' );
		$this->assertSame( array( 'wall' => 600, 'slice' => 20.0, 'download_timeout' => 50 ), ZSM_Policy::budget( 600 ), 'a generous host is capped' );
		$tiny = ZSM_Policy::budget( 5 );
		$this->assertSame( ZSM_Policy::MIN_SLICE, $tiny['slice'] );
		$this->assertSame( ZSM_Policy::MIN_DOWNLOAD, $tiny['download_timeout'] );
		foreach ( array( 20, 30, 45, 60, 90, 120 ) as $w ) {
			$b = ZSM_Policy::budget( $w );
			$this->assertLessThanOrEqual( $w / 2, $b['slice'], "a step stays well inside a {$w} s wall" );
			$this->assertLessThan( $w, $b['download_timeout'], "the download ends before a {$w} s wall" );
		}
	}

	public function test_memory_stop_keeps_a_slice_inside_the_limit(): void {
		$mb = 1048576;
		$this->assertSame( 128 * $mb + 40 * $mb, ZSM_Policy::memory_stop( 512 * $mb, 40 * $mb ), 'a roomy limit: base plus the headroom' );
		$this->assertSame( (int) floor( 256 * $mb * 0.85 ), ZSM_Policy::memory_stop( 256 * $mb, 150 * $mb ), 'a tight limit: 85% of it' );
		$this->assertSame( 50 * $mb + 128 * $mb, ZSM_Policy::memory_stop( -1, 50 * $mb ), 'no limit: base plus the headroom' );
		$this->assertSame( 50 * $mb + 16 * $mb, ZSM_Policy::memory_stop( 0, 50 * $mb, 1 ), 'the headroom never drops below 16 MB' );
	}

	public function test_only_a_complete_sane_download_replaces_the_cache(): void {
		$this->assertSame( '', ZSM_Policy::verdict( array( 'records' => 41000, 'matched' => 450, 'malformed' => 0, 'insert_failed' => 0 ) ) );
		$this->assertStringContainsString( 'only 900 records', ZSM_Policy::verdict( array( 'records' => 900 ) ), 'too small to be the real feed' );
		$this->assertStringContainsString( 'could not be written', ZSM_Policy::verdict( array( 'records' => 41000, 'insert_failed' => 1 ) ) );
		$this->assertStringContainsString( 'could not be decoded', ZSM_Policy::verdict( array( 'records' => 41000, 'matched' => 40, 'malformed' => 21 ) ) );
		$this->assertSame( '', ZSM_Policy::verdict( array( 'records' => 41000, 'matched' => 4, 'malformed' => 10 ) ), 'a few bad records are tolerated' );
		$this->assertSame( '', ZSM_Policy::verdict( array( 'records' => 20 ), 10 ), 'the minimum is configurable' );
	}

	/* ── Scheduling window ───────────────────────────────────────────────── */

	public function test_schedule_is_never_requested_before_the_job_library_starts(): void {
		$this->assertSame( 'wait', ZSM_Policy::schedule_action( false, true, false ), 'loaded but not started: ask again on a later request' );
		$this->assertSame( 'wait', ZSM_Policy::schedule_action( false, true, false, true ), 'even when forced' );
		$this->assertSame( 'action_scheduler', ZSM_Policy::schedule_action( false, true, true ) );
		$this->assertSame( 'wp_cron', ZSM_Policy::schedule_action( false, false, false ) );
		$this->assertSame( 'cached', ZSM_Policy::schedule_action( true, true, true ), 'confirmed recently: no query per page load' );
		$this->assertSame( 'action_scheduler', ZSM_Policy::schedule_action( true, true, true, true ), 'a settings save checks again' );
	}

	public function test_downloads_are_paced_and_backed_off(): void {
		$now  = 1800000000;
		$base = array( 'staging' => false, 'key' => true, 'url_ok' => true, 'now' => $now, 'backoff_until' => 0, 'last_attempt' => 0, 'last_error' => '' );
		$this->assertSame( 'go', ZSM_Policy::gate( $base )['status'] );
		$this->assertSame( 'waiting', ZSM_Policy::gate( array( 'last_attempt' => $now - 1799 ) + $base )['status'], 'one download per 30 minutes' );
		$this->assertSame( 'go', ZSM_Policy::gate( array( 'last_attempt' => $now - 1800 ) + $base )['status'] );
		$this->assertSame( 'waiting', ZSM_Policy::gate( array( 'backoff_until' => $now + 60 ) + $base )['status'] );
		$this->assertSame( 'staging', ZSM_Policy::gate( array( 'staging' => true ) + $base )['status'], 'a staging copy sharing the key never downloads' );
		$bad = ZSM_Policy::gate( array( 'url_ok' => false ) + $base );
		$this->assertSame( 'failed', $bad['status'] );
		$this->assertSame( ZSM_Policy::KIND_ATTENTION, $bad['kind'] );

		$this->assertSame( 3600, ZSM_Policy::rate_backoff( '' ), 'at least an hour after a rate limit' );
		$this->assertSame( 3600, ZSM_Policy::rate_backoff( '120' ) );
		$this->assertSame( 7200, ZSM_Policy::rate_backoff( '7200' ), 'a longer Retry-After is honoured' );
		$this->assertSame( 86400, ZSM_Policy::rate_backoff( '999999' ), 'never more than a day' );
		$this->assertSame( 10800, ZSM_Policy::rate_backoff( gmdate( 'D, d M Y H:i:s', $now + 10800 ) . ' GMT', $now ), 'an HTTP-date Retry-After' );

		$this->assertSame( max( $now + 3600, $now + 1800 ) + 60, ZSM_Policy::retry_at( $now + 3600, $now ), 'the retry runs a minute after the pause ends' );
		$this->assertSame( $now + 1800 + 60, ZSM_Policy::retry_at( 0, $now ), 'and never inside the 30-minute window' );

		$this->assertTrue( ZSM_Policy::codescan_due( 0, $now ) );
		$this->assertFalse( ZSM_Policy::codescan_due( $now - 3600, $now ) );
		$this->assertTrue( ZSM_Policy::codescan_due( $now - 72001, $now ) );
		$this->assertTrue( ZSM_Policy::job_expired( gmdate( 'c', $now - 86401 ), $now ) );
		$this->assertFalse( ZSM_Policy::job_expired( gmdate( 'c', $now - 600 ), $now ) );
	}

	public function test_http_failures_have_a_kind_and_only_attention_makes_the_copy_stale(): void {
		$this->assertSame( ZSM_Policy::KIND_ATTENTION, ZSM_Policy::http_failure( 401 )['kind'] );
		$this->assertSame( ZSM_Policy::AUTH_BACKOFF, ZSM_Policy::http_failure( 403 )['backoff'] );
		$this->assertSame( ZSM_Policy::KIND_RATE, ZSM_Policy::http_failure( 429 )['kind'] );
		$this->assertSame( ZSM_Policy::KIND_ATTENTION, ZSM_Policy::http_failure( 410 )['kind'] );
		$this->assertSame( ZSM_Policy::KIND_TRANSIENT, ZSM_Policy::http_failure( 503 )['kind'] );
		$this->assertSame( ZSM_Policy::KIND_ATTENTION, ZSM_Policy::http_failure( 418 )['kind'] );

		$now   = 1800000000;
		$fresh = gmdate( 'c', $now - 3600 );
		$this->assertFalse( ZSM_Policy::is_stale( $fresh, '', '', $now ) );
		$this->assertFalse( ZSM_Policy::is_stale( $fresh, 'rate limited', 'rate', $now ), 'a rate limit with a recent copy is not stale' );
		$this->assertFalse( ZSM_Policy::is_stale( $fresh, 'timeout', 'transient', $now ) );
		$this->assertTrue( ZSM_Policy::is_stale( $fresh, 'key refused', 'attention', $now ) );
		$this->assertTrue( ZSM_Policy::is_stale( $fresh, 'old error', '', $now ), 'an error with no kind needs a person' );
		$this->assertTrue( ZSM_Policy::is_stale( gmdate( 'c', $now - 172801 ), '', '', $now ), 'over 48 hours' );
		$this->assertTrue( ZSM_Policy::is_stale( '', '', '', $now ), 'never synced' );
	}

	/* ── Alert de-duplication ────────────────────────────────────────────── */

	public function test_digest_is_sent_only_when_something_changed(): void {
		$sig = ZSM_Policy::digest_signature( array( 'k1', 'k2' ), false );
		$this->assertSame( $sig, ZSM_Policy::digest_signature( array( 'k2', 'k1', 'k1' ), false ), 'the same set in another order is the same digest' );
		$this->assertNotSame( $sig, ZSM_Policy::digest_signature( array( 'k1', 'k2', 'k3' ), false ), 'a new finding is news' );
		$this->assertNotSame( $sig, ZSM_Policy::digest_signature( array( 'k1', 'k2' ), true ), 'the feed alarm going up is news' );

		$this->assertSame( array(), ZSM_Policy::digest_due( array( 3, 7 ), array( 3 => $sig, 7 => $sig ), $sig ), 'nothing new: nothing sent' );
		$this->assertSame( array( 7 ), ZSM_Policy::digest_due( array( 3, 7 ), array( 3 => $sig, 7 => 'older' ), $sig ), 'a failed delivery is tried again' );
		$this->assertSame( array( 9 ), ZSM_Policy::digest_due( array( 3, 9 ), array( 3 => $sig ), $sig ), 'a new recipient gets the current state' );
		$this->assertSame( array( 3, 7 ), ZSM_Policy::digest_due( array( 3, 7, 3, 0, -1 ), array( 3 => $sig, 7 => $sig ), $sig, true ), 'on demand: everyone, once each' );
	}

	/* ── No key ──────────────────────────────────────────────────────────── */

	public function test_without_a_key_nothing_is_requested_and_the_feed_reads_not_connected(): void {
		$this->assertSame( '', ZSM::feed_key(), 'no credential row: no key' );
		$this->assertFalse( ZSM::has_feed_key() );
		$gate = ZSM_Policy::gate( array( 'staging' => false, 'key' => false, 'url_ok' => true, 'now' => time(), 'backoff_until' => 0, 'last_attempt' => 0, 'last_error' => '' ) );
		$this->assertSame( 'needs_key', $gate['status'] );
		$this->assertStringContainsString( 'not connected', $gate['message'] );
		$this->assertStringContainsString( 'local checks still run', $gate['message'] );

		$now = time();
		$this->assertSame( 'not_connected', ZSM_Policy::feed_state( false, '', '', '', $now ) );
		$this->assertSame( 'not_connected', ZSM_Policy::feed_state( false, gmdate( 'c', $now - 999999 ), 'x', 'attention', $now ), 'an old copy does not turn an unconnected feed into an alarm' );
		$this->assertFalse( ZSM_Policy::alarm( 'not_connected' ), 'no "stale feed" alarm, no stale digest, for a feed nobody connected' );
		$this->assertSame( 'never', ZSM_Policy::feed_state( true, '', '', '', $now ) );
		$this->assertTrue( ZSM_Policy::alarm( 'never' ) );
		$this->assertSame( 'fresh', ZSM_Policy::feed_state( true, gmdate( 'c', $now - 60 ), '', '', $now ) );

		// The local checks need no key: updates, drift and the supply chain still report.
		$inv  = array(
			array( 'slug' => 'example-plugin', 'name' => 'Example Plugin', 'version' => '1.0', 'latest' => '1.1', 'update_available' => true, 'comp_type' => 'plugin', 'first_party' => false ),
			array( 'slug' => 'example-own', 'name' => 'Example Own', 'version' => '2.0', 'latest' => '', 'update_available' => false, 'comp_type' => 'plugin', 'first_party' => true, 'update_uri' => 'false' ),
		);
		$kind = array_column( ZSM_Currency::evaluate( $inv, array( 'example-own' => '2.1' ) ), 'kind' );
		$this->assertContains( 'outdated', $kind );
		$this->assertContains( 'drift', $kind );
	}

	/* ── The key never appears in output ─────────────────────────────────── */

	public function test_the_key_travels_only_in_the_header_to_the_feed_host(): void {
		$calls = array();
		$http  = function ( $url, $args ) use ( &$calls ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array() );
		};
		$url = ZSM_Feed::BASE_URL . 'production';
		$r   = ZSM_Feed::fetch( $url, self::KEY, '/tmp/zsm-tmp/feed-x.json', 50, $http );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 1, $calls );
		$this->assertSame( $url, $calls[0]['url'] );
		$this->assertStringNotContainsString( self::KEY, $calls[0]['url'], 'never in the URL' );
		$this->assertSame( 'Bearer ' . self::KEY, $calls[0]['args']['headers']['Authorization'] );
		$this->assertSame( 0, $calls[0]['args']['redirection'], 'the HTTP library never follows a redirect with the key' );
		$this->assertTrue( $calls[0]['args']['sslverify'] );
		$args = $calls[0]['args'];
		unset( $args['headers']['Authorization'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $args ), 'the key is in no other argument' );
		$this->assertStringNotContainsString( self::KEY, json_encode( $r ) );
	}

	public function test_a_redirect_is_followed_once_without_the_key_and_only_to_an_allowed_host(): void {
		$GLOBALS['zdz_test_filters']['zsm_feed_allowed_hosts'] = function ( $hosts ) {
			$hosts[] = 'downloads.example.com';
			return $hosts;
		};
		$calls = array();
		$http  = function ( $url, $args ) use ( &$calls ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			if ( 1 === count( $calls ) ) {
				return array( 'response' => array( 'code' => 302 ), 'headers' => array( 'Location' => 'https://downloads.example.com/feed.json' ) );
			}
			return array( 'response' => array( 'code' => 200 ), 'headers' => array() );
		};
		$r = ZSM_Feed::fetch( ZSM_Feed::BASE_URL . 'production', self::KEY, '/tmp/f.json', 50, $http );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 2, $r['hops'] );
		$this->assertArrayNotHasKey( 'Authorization', $calls[1]['args']['headers'], 'the second hop carries no key' );
		$this->assertStringNotContainsString( self::KEY, json_encode( $calls[1] ) );

		unset( $GLOBALS['zdz_test_filters']['zsm_feed_allowed_hosts'] );
		$calls = array();
		$http2 = function ( $url, $args ) use ( &$calls ) {
			$calls[] = $url;
			return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://elsewhere.example.net/?k=' . self::KEY ) );
		};
		$r2 = ZSM_Feed::fetch( ZSM_Feed::BASE_URL . 'production', self::KEY, '/tmp/f.json', 50, $http2 );
		$this->assertFalse( $r2['ok'] );
		$this->assertCount( 1, $calls, 'a redirect to a host that is not allowed is not followed: nothing else is contacted' );
		$this->assertStringContainsString( 'elsewhere.example.net', $r2['message'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $r2 ) );
	}

	public function test_errors_and_messages_never_carry_the_key(): void {
		$echoing = new class() {
			public function get_error_message() {
				return 'cURL error: header "Authorization: Bearer ' . SecurityMonitorTest::KEY . '" rejected by proxy';
			}
		};
		$r = ZSM_Feed::fetch( ZSM_Feed::BASE_URL . 'production', self::KEY, '/tmp/f.json', 50, function () use ( $echoing ) { return $echoing; } );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( ZSM_Policy::KIND_TRANSIENT, $r['kind'] );
		$this->assertStringNotContainsString( self::KEY, $r['message'], 'an HTTP error that echoes the header is scrubbed' );
		$this->assertStringContainsString( '[redacted]', $r['message'] );

		foreach ( array( 401, 403, 404, 429, 500 ) as $code ) {
			$res = ZSM_Feed::fetch( ZSM_Feed::BASE_URL . 'scanner', self::KEY, '/tmp/f.json', 50, function () use ( $code ) { return array( 'response' => array( 'code' => $code ), 'headers' => array( 'retry-after' => '60' ) ); } );
			$this->assertFalse( $res['ok'] );
			$this->assertStringNotContainsString( self::KEY, json_encode( $res ), "HTTP {$code}" );
		}

		$this->assertSame( 'token [redacted] here', ZSM_Policy::redact( 'token ' . self::KEY . ' here', self::KEY ) );
		$this->assertSame( 'Authorization: Bearer [redacted]', ZSM_Policy::redact( 'Authorization: Bearer some-other-token', '' ), 'any bearer value is scrubbed' );
	}

	public function test_only_the_feed_host_over_https_is_allowed(): void {
		$this->assertTrue( ZSM_Feed::url_allowed( ZSM_Feed::BASE_URL . 'production' ) );
		$this->assertFalse( ZSM_Feed::url_allowed( 'http://' . ZSM_Feed::ALLOWED_HOST . '/x' ), 'not over plain http' );
		$this->assertFalse( ZSM_Feed::url_allowed( 'https://example.com/feed' ) );
		$this->assertFalse( ZSM_Feed::url_allowed( 'https://user:pass@' . ZSM_Feed::ALLOWED_HOST . '/x' ) );
		$this->assertFalse( ZSM_Feed::url_allowed( 'https://' . ZSM_Feed::ALLOWED_HOST . '.example.com/x' ), 'a look-alike host' );
	}

	/* ── Your own components and the local checks ────────────────────────── */

	public function test_first_party_is_the_platform_plus_what_the_business_lists(): void {
		$conf = ZSM_Inventory::parse_first_party( array( ' Example-Own ', 'acme-*', 'shop-', '', '*', 'bad name!' ) );
		$this->assertSame( array( 'example-own', 'badname' ), $conf['exact'] );
		$this->assertSame( array( 'acme-', 'shop-' ), $conf['prefixes'] );
		$this->assertTrue( ZSM_Inventory::is_first_party( 'zorderz', $conf ) );
		$this->assertTrue( ZSM_Inventory::is_first_party( 'zorderz-apps', $conf ) );
		$this->assertTrue( ZSM_Inventory::is_first_party( 'example-own', $conf ) );
		$this->assertTrue( ZSM_Inventory::is_first_party( 'acme-tools', $conf ) );
		$this->assertFalse( ZSM_Inventory::is_first_party( 'example-plugin', $conf ) );
		$this->assertFalse( ZSM_Inventory::is_first_party( 'example-plugin', array( 'exact' => array(), 'prefixes' => array() ) ), 'nothing else by default' );
		$this->assertSame( 'example-plugin', ZSM_Inventory::plugin_slug( 'example-plugin/example-plugin.php' ) );
		$this->assertSame( 'single-file', ZSM_Inventory::plugin_slug( 'single-file.php' ) );
	}

	public function test_currency_drift_and_supply_chain(): void {
		$this->assertGreaterThan( 0, ZSM_Currency::compare( '1.0.0-hotfix1', '1.0.0' ) );
		$this->assertLessThan( 0, ZSM_Currency::compare( '2.0.0-beta', '2.0.0' ) );
		$this->assertSame( 0, ZSM_Currency::compare( '1.2', '1.2' ) );

		$inv = array(
			array( 'slug' => 'wordpress', 'name' => 'WordPress core', 'version' => '6.0', 'latest' => '6.1', 'update_available' => true, 'comp_type' => 'core', 'first_party' => false ),
			array( 'slug' => 'example-plugin', 'name' => 'Example Plugin', 'version' => '1.0', 'latest' => '1.1', 'update_available' => true, 'comp_type' => 'plugin', 'first_party' => false ),
			array( 'slug' => 'example-own', 'name' => 'Example Own', 'version' => '1.9', 'latest' => '3.0', 'update_available' => true, 'comp_type' => 'plugin', 'first_party' => true, 'update_uri' => '', 'wporg_known' => true ),
			array( 'slug' => 'example-named', 'name' => 'Example Named', 'version' => '2.0', 'latest' => '', 'comp_type' => 'plugin', 'first_party' => true, 'update_uri' => 'https://wordpress.org/plugins/x', 'wporg_known' => true ),
			array( 'slug' => 'example-sealed', 'name' => 'Example Sealed', 'version' => '2.0', 'latest' => '9.9', 'comp_type' => 'plugin', 'first_party' => true, 'update_uri' => 'false', 'wporg_known' => true ),
			array( 'slug' => 'example-ahead', 'name' => 'Example Ahead', 'version' => '5.0', 'latest' => '', 'comp_type' => 'theme', 'first_party' => true, 'update_uri' => 'https://updates.example.com/' ),
		);
		$by = array();
		foreach ( ZSM_Currency::evaluate( $inv, array( 'example-own' => '2.0', 'example-ahead' => '4.0' ) ) as $f ) {
			$by[ $f['kind'] . ':' . $f['slug'] ] = $f;
		}
		$this->assertSame( 'medium', $by['outdated:wordpress']['severity'] );
		$this->assertSame( 'low', $by['outdated:example-plugin']['severity'] );
		$this->assertArrayNotHasKey( 'outdated:example-own', $by, 'your own code is never "update it from WordPress.org"' );
		$this->assertSame( 'medium', $by['drift:example-own']['severity'], 'rolled back' );
		$this->assertSame( 'info', $by['drift:example-ahead']['severity'], 'ahead of the baseline' );
		$this->assertSame( 'high', $by['supply:example-own']['severity'], 'WordPress.org offers an update for your own code' );
		$this->assertSame( 'medium', $by['supply:example-named']['severity'], 'an Update URI on wordpress.org does not take it out of the channel' );
		$this->assertArrayNotHasKey( 'supply:example-sealed', $by, '"Update URI: false" takes it out' );
		$this->assertSame( 'info', $by['supply:first-party']['severity'] );
		$this->assertStringContainsString( 'example-named, example-own', $by['supply:first-party']['title'] );

		$a = ZSM_Currency::row( array( 'kind' => 'outdated', 'slug' => 's', 'installed_version' => '1', 'fixed_version' => '2' ) );
		$b = ZSM_Currency::row( array( 'kind' => 'outdated', 'slug' => 's', 'installed_version' => '1', 'fixed_version' => '2', 'title' => 'other words' ) );
		$this->assertSame( $a['finding_key'], $b['finding_key'], 'the de-duplication key ignores the wording' );
	}

	public function test_report_helpers_and_code_import(): void {
		$this->assertSame( 'Example Plugin <= 1.2 - Stored…', ZSM_Report::trim_words( 'Example Plugin <= 1.2 - Stored XSS via shortcode', 6 ), 'a "<=" in a title is not taken for a tag' );
		$rows = ZSM_Code::parse( json_encode( array( 'results' => array( array( 'check_id' => 'php.lang.eval', 'path' => 'wp-content/plugins/example-own/a.php', 'start' => array( 'line' => 7 ), 'extra' => array( 'message' => 'eval', 'severity' => 'ERROR' ) ) ) ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'example-own', $rows[0]['slug'] );
		$this->assertSame( 'high', $rows[0]['severity'] );
		$generic = ZSM_Code::parse( json_encode( array( 'files' => array( array( 'file' => 'themes/example-theme/x.php', 'line' => 3, 'message' => 'm', 'type' => 'WARNING', 'code' => 'R1' ) ) ) ) );
		$this->assertSame( 'theme', $generic[0]['comp_type'] );
		$this->assertSame( 'medium', $generic[0]['severity'] );
		$this->assertNull( ZSM_Code::parse( 'not json' ) );
		$this->assertSame( 'first-party', ZSM_Code::slug_from_path( 'somewhere/else.php' ) );
		$order = array( array( 'severity' => 'low', 'slug' => 'a' ), array( 'severity' => 'critical', 'slug' => 'z' ), array( 'severity' => 'critical', 'slug' => 'b' ) );
		usort( $order, array( 'ZSM_Findings', 'compare' ) );
		$this->assertSame( array( 'b', 'z', 'a' ), array_column( $order, 'slug' ) );
	}
}
