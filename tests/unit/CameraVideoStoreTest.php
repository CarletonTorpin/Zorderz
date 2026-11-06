<?php
/**
 * Camera video parts (ZCAM_Video_Store): a clip's streamed parts are bounded, cleaned up and kept out of reach.
 *
 *   - Who: the shared device (by the platform's trait or its role) and a device in Demo Mode never stream clips;
 *     someone without the Camera app is refused; a person with it may.
 *   - Limits: at most MAX_OPEN unfinished clips and QUOTA_BYTES waiting per person; a retried part counts only its
 *     difference; a business may lower the limits, never raise them.
 *   - Clean-up: a clip none of whose parts was written for 24 hours is removed, a live one is kept, empty person
 *     folders go, the run stops at its budget.
 *   - Where: beside the install when that is outside the web root and writable, else an unguessable folder under
 *     uploads; every root gets deny-all rules and an empty index.php; a clip that began in the earlier fixed folder
 *     finishes there.
 *
 * Works in a temporary folder of its own. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir() { return array( 'basedir' => (string) ( $GLOBALS['zdz_test_uploads'] ?? '' ) ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0750, true ); }
}
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/camera/includes/class-zcam-video-store.php';

final class CameraVideoStoreTest extends TestCase {

	/** @var string */
	private $tmp = '';

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( 5 => 'staff', 6 => 'shared', 7 => 'none', 8 => 'admin' ) );
		$GLOBALS['zdz_test_user_meta'][5]['zdz_allowed_apps'] = array( 'zdz-camera' );   // the app grant, when the platform reads it
		$GLOBALS['zdz_test_user_meta'][7]['zdz_allowed_apps'] = array();
		$this->tmp = sys_get_temp_dir() . '/zcam-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp . '/site/public/wp', 0777, true );
		mkdir( $this->tmp . '/site/public/wp/wp-content/uploads', 0777, true );
		$GLOBALS['zdz_test_uploads'] = $this->tmp . '/site/public/wp/wp-content/uploads';
	}

	protected function tearDown(): void {
		$this->rm( $this->tmp );
		unset( $GLOBALS['zdz_test_uploads'] );
		zdz_test_msg_teardown();
	}

	private function rm( string $p ): void {
		if ( is_dir( $p ) && ! is_link( $p ) ) {
			foreach ( array_merge( (array) glob( $p . '/*' ), (array) glob( $p . '/.[!.]*' ) ) as $c ) {
				$this->rm( $c );
			}
			@rmdir( $p );
		} elseif ( file_exists( $p ) ) {
			@unlink( $p );
		}
	}

	private function part( string $dir, int $idx, int $bytes, int $mtime = 0 ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/' . $idx . '.part', str_repeat( 'x', $bytes ) );
		if ( $mtime ) {
			touch( $dir . '/' . $idx . '.part', $mtime );
			touch( $dir, $mtime );
		}
	}

	/* ── who ──────────────────────────────────────────────────────────────── */

	public function test_the_shared_device_and_a_person_without_the_app_stream_no_clips(): void {
		$this->assertSame( 'shared', ZCAM_Video_Store::may_record( 6 ), 'the shared-device role' );
		$GLOBALS['zdz_test_user_roles'][9] = array( 'zdz_tech' );
		$GLOBALS['zdz_test_user_caps'][9]  = array( 'zdz_access_app', 'zdz_is_shared_device' );
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$this->assertSame( 'shared', ZCAM_Video_Store::may_record( 9 ), 'the trait, on another role' );
		}
		$this->assertSame( 'grant', ZCAM_Video_Store::may_record( 7 ), 'no Camera app' );
		$this->assertSame( '', ZCAM_Video_Store::may_record( 5 ) );
		$this->assertSame( '', ZCAM_Video_Store::may_record( 8 ) );
		$this->assertSame( 'signed_out', ZCAM_Video_Store::may_record( 0 ) );
		$this->assertStringContainsString( 'shared device', ZCAM_Video_Store::refusal( 'shared' ) );
	}

	/* ── limits ───────────────────────────────────────────────────────────── */

	public function test_unfinished_clips_and_bytes_are_bounded(): void {
		$usage = array( 'bytes' => 100, 'open' => 8, 'captures' => array( 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8' ) );
		$this->assertSame( 'open', ZCAM_Video_Store::admit( $usage, 'new-clip', 10, 0, 8, 1000 ), 'a ninth clip waits' );
		$this->assertSame( '', ZCAM_Video_Store::admit( $usage, 'a3', 10, 0, 8, 1000 ), 'a known clip carries on' );
		$this->assertSame( 'bytes', ZCAM_Video_Store::admit( $usage, 'a3', 901, 0, 8, 1000 ), 'over the quota' );
		$this->assertSame( '', ZCAM_Video_Store::admit( $usage, 'a3', 901, 50, 8, 1000 ), 'a retried part counts only its difference' );
	}

	public function test_a_business_may_lower_the_limits_never_raise_them(): void {
		$this->assertSame( ZCAM_Video_Store::MAX_OPEN, ZCAM_Video_Store::max_open() );
		$this->assertSame( ZCAM_Video_Store::QUOTA_BYTES, ZCAM_Video_Store::quota_bytes() );
		$GLOBALS['zdz_test_filters']['zcam_video_max_open_captures'] = function () { return 500; };
		$GLOBALS['zdz_test_filters']['zcam_video_quota_bytes']       = function () { return PHP_INT_MAX; };
		$this->assertSame( ZCAM_Video_Store::MAX_OPEN, ZCAM_Video_Store::max_open() );
		$this->assertSame( ZCAM_Video_Store::QUOTA_BYTES, ZCAM_Video_Store::quota_bytes() );
		$GLOBALS['zdz_test_filters']['zcam_video_max_open_captures'] = function () { return 2; };
		$GLOBALS['zdz_test_filters']['zcam_video_quota_bytes']       = function () { return 1; };
		$this->assertSame( 2, ZCAM_Video_Store::max_open() );
		$this->assertSame( ZCAM_Video_Store::MIN_QUOTA, ZCAM_Video_Store::quota_bytes(), 'never below one part' );
	}

	public function test_usage_counts_each_persons_waiting_parts(): void {
		$root = $this->tmp . '/root';
		$this->part( $root . '/5/clipaaaa1', 0, 300 );
		$this->part( $root . '/5/clipaaaa1', 1, 200 );
		$this->part( $root . '/5/clipbbbb2', 0, 50 );
		$this->part( $root . '/8/clipcccc3', 0, 999 );
		$u = ZCAM_Video_Store::usage( array( $root . '/5', $this->tmp . '/nowhere/5' ) );
		$this->assertSame( 550, $u['bytes'] );
		$this->assertSame( 2, $u['open'] );
		$this->assertContains( 'clipaaaa1', $u['captures'] );
	}

	/* ── clean-up ─────────────────────────────────────────────────────────── */

	public function test_abandoned_clips_are_removed_live_ones_kept(): void {
		$root = $this->tmp . '/root';
		$now  = 1800000000;
		$this->part( $root . '/5/oldclip01', 0, 10, $now - 90000 );
		$this->part( $root . '/5/oldclip01', 1, 10, $now - 90000 );
		$this->part( $root . '/5/liveclip1', 0, 10, $now - 90000 );
		$this->part( $root . '/5/liveclip1', 1, 10, $now - 60 );          // still being written
		$this->part( $root . '/8/oldclip02', 0, 10, $now - 200000 );
		file_put_contents( $root . '/.htaccess', 'x' );
		$this->assertSame( 2, ZCAM_Video_Store::purge_root( $root, $now, ZCAM_Video_Store::TTL, 100 ) );
		$this->assertDirectoryMissing( $root . '/5/oldclip01' );
		$this->assertTrue( is_dir( $root . '/5/liveclip1' ), 'a clip still being written is kept' );
		$this->assertDirectoryMissing( $root . '/8', 'an empty person folder goes' );
		$this->assertFileExists( $root . '/.htaccess', 'the root and its rules stay' );
	}

	public function test_a_clean_up_run_stops_at_its_budget(): void {
		$root = $this->tmp . '/root';
		$now  = 1800000000;
		for ( $i = 0; $i < 5; $i++ ) {
			$this->part( $root . '/5/oldclip0' . $i, 0, 10, $now - 90000 );
		}
		$this->assertSame( 3, ZCAM_Video_Store::purge_root( $root, $now, ZCAM_Video_Store::TTL, 3 ) );
		$this->assertSame( 2, ZCAM_Video_Store::purge_root( $root, $now, ZCAM_Video_Store::TTL, 3 ) );
	}

	/* ── where ────────────────────────────────────────────────────────────── */

	public function test_parts_go_outside_the_web_root_when_the_host_allows(): void {
		$abs = $this->tmp . '/site/public/wp/';
		$doc = $this->tmp . '/site/public';
		// The install's parent folder is inside the web root here: uploads, under a name nobody can guess.
		$up = ZCAM_Video_Store::pick_root( $abs, $doc, (string) $GLOBALS['zdz_test_uploads'], 'abc123' );
		$this->assertSame( $GLOBALS['zdz_test_uploads'] . '/zcam-video-abc123', $up );
		$this->assertFileExists( $up . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $up . '/.htaccess' ) );
		$this->assertFileExists( $up . '/index.php' );

		// The web root is the install itself: beside it is outside, and writable here.
		$out = ZCAM_Video_Store::pick_root( $abs, $abs, (string) $GLOBALS['zdz_test_uploads'], 'abc123' );
		$this->assertSame( realpath( $this->tmp . '/site/public' ) . '/zorderz-private-abc123/video-tmp', $out );
		$this->assertFileExists( $out . '/.htaccess' );
		$this->assertFileExists( dirname( $out ) . '/.htaccess' );

		// Without a known web root, never guess: uploads.
		$this->assertSame( $up, ZCAM_Video_Store::pick_root( $abs, '', (string) $GLOBALS['zdz_test_uploads'], 'abc123' ) );
	}

	public function test_a_clip_that_began_in_the_earlier_folder_finishes_there(): void {
		$GLOBALS['zdz_test_filters']['zcam_video_tmp_dir'] = function () { return $this->tmp . '/chosen'; };
		$legacy = $GLOBALS['zdz_test_uploads'] . '/' . ZCAM_Video_Store::LEGACY_DIR;
		$this->part( $legacy . '/5/earlyclip', 0, 10 );
		$this->assertSame( $legacy . '/5/earlyclip', ZCAM_Video_Store::parts_dir( 5, 'earlyclip', true ) );
		$this->assertFileExists( $legacy . '/.htaccess', 'the earlier folder gets the rules too' );
		$new = ZCAM_Video_Store::parts_dir( 5, 'newclip01', true );
		$this->assertSame( $this->tmp . '/chosen/5/newclip01', $new );
		$this->assertTrue( is_dir( $new ) );
		$this->assertFileExists( $this->tmp . '/chosen/.htaccess', 'the root is guarded when it is first used' );
		$this->assertSame( '', ZCAM_Video_Store::parts_dir( 5, '../../', true ), 'a bad id has no folder' );
		$this->assertSame( array( $this->tmp . '/chosen/5', $legacy . '/5' ), ZCAM_Video_Store::user_dirs( 5 ) );
	}

	private function assertDirectoryMissing( string $d, string $m = '' ): void {
		$this->assertFalse( is_dir( $d ), $m ?: $d . ' should be gone' );
	}
}
