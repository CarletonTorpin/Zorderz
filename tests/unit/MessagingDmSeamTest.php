<?php
/**
 * Messages: other apps send a direct message only through Messages' own seam, and the widget
 * reads only the Zorderz media-share marker.
 *
 *   - Jobs, Territories and Security Monitor send through `zim_dm_send` (Messages decides who may
 *     send and receive and makes the post); none of them writes into ZIM_DMs / ZIM_Messages.
 *   - With no answer on the seam (Messages not installed) nothing is sent and nothing fails.
 *   - The shared device and a person notifying themselves are still refused by the caller.
 *   - The widget turns [ZDZ-MEDIA-SHARE-<hex>] into a card and leaves any other marker as text.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $cb = false ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
$zdz_wb_apps = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/';
require_once $zdz_wb_apps . 'territories/includes/functions-zter-people.php';
require_once $zdz_wb_apps . 'territories/includes/class-zter-notify.php';
require_once $zdz_wb_apps . 'jobs/includes/class-zjob-sealed.php';
require_once $zdz_wb_apps . 'jobs/includes/class-zjob-notify.php';

final class MessagingDmSeamTest extends TestCase {

	const ALEX = 301, JORDAN = 302, DEVICE = 303;

	/** @var array[] every call the seam received */
	private $sent = array();

	protected function setUp(): void {
		$this->sent = array();
		$GLOBALS['zdz_test_user_roles'] = array(
			self::ALEX   => array( 'zdz_sales' ),
			self::JORDAN => array( 'zdz_tech' ),
			self::DEVICE => array( 'zdz_general' ),
		);
		$GLOBALS['zdz_test_user_caps'] = array();
		unset( $GLOBALS['zdz_test_filters']['zim_dm_send'], $GLOBALS['zdz_test_filters']['zdz_job_notify_sender'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zim_dm_send'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'] );
	}

	/** Messages answering the seam: records the call and answers like ZIM_DM_Send. */
	private function messages_installed(): void {
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = function ( $prior, $from, $to, $body, $context = array() ) {
			$this->sent[] = array( (int) $from, (int) $to, (string) $body );
			return array( 'conversation_id' => 7, 'message_id' => count( $this->sent ) );
		};
	}

	private function jobs_dm( int $from, int $to, string $body ): bool {
		$m = new ReflectionMethod( 'ZJOB_Notify', 'dm_from_actor' );
		$m->setAccessible( true );
		return (bool) $m->invoke( null, $from, $to, $body, 'assigned' );
	}

	public function test_no_app_writes_into_messages_directly(): void {
		$root = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/';
		$jobs = (string) file_get_contents( $root . 'jobs/includes/class-zjob-notify.php' );
		$ter  = (string) file_get_contents( $root . 'territories/includes/class-zter-notify.php' );
		$sm   = (string) file_get_contents( $root . 'security-monitor/includes/class-zsm-report.php' );
		foreach ( array( 'jobs' => $jobs, 'territories' => $ter, 'security monitor' => $sm ) as $app => $src ) {
			$this->assertStringNotContainsString( 'ZIM_Messages::post', $src, $app . ' posts through the seam' );
			$this->assertStringContainsString( "apply_filters( 'zim_dm_send', null,", $src, $app . ' uses the seam' );
		}
		$this->assertStringNotContainsString( 'ZIM_DMs', $ter );
		$this->assertStringNotContainsString( 'ZIM_DMs', $sm );
		// Jobs still opens a conversation for its People link (nothing is sent there).
		$this->assertSame( 1, substr_count( $jobs, 'ZIM_DMs::get_or_create_conversation' ) );
	}

	public function test_without_messages_nothing_is_sent_and_nothing_fails(): void {
		$this->assertFalse( ZTER_Notify::dm( self::ALEX, self::JORDAN, 'A new estimate for you.' ) );
		$this->assertFalse( ZJOB_Notify::can_send() );
		$this->assertFalse( $this->jobs_dm( self::ALEX, self::JORDAN, 'A job is yours.' ) );
		$this->assertNull( ZDZ_Test_Wireb_Sm_Probe::copy( self::ALEX ) );
		$this->assertSame( array(), $this->sent );
	}

	public function test_territories_sends_through_the_seam_and_keeps_its_refusals(): void {
		$this->messages_installed();
		$this->assertTrue( ZTER_Notify::dm( self::ALEX, self::JORDAN, 'A new estimate for you.' ) );
		$this->assertSame( array( array( self::ALEX, self::JORDAN, 'A new estimate for you.' ) ), $this->sent );

		$this->assertFalse( ZTER_Notify::dm( self::ALEX, self::ALEX, 'x' ), 'never notify yourself' );
		$this->assertFalse( ZTER_Notify::dm( self::ALEX, self::DEVICE, 'x' ), 'the shared device is not a person' );
		$this->assertFalse( ZTER_Notify::dm( self::DEVICE, self::ALEX, 'x' ) );
		$this->assertCount( 1, $this->sent );

		// A refusal from Messages (no access, read-only) is a "not sent", never an error.
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = function () {
			return new WP_Error( 'zim_no_access', 'That teammate does not have Messages.' );
		};
		$this->assertFalse( ZTER_Notify::dm( self::ALEX, self::JORDAN, 'x' ) );
	}

	public function test_jobs_sends_through_the_seam_and_keeps_its_refusals(): void {
		$this->messages_installed();
		$this->assertTrue( ZJOB_Notify::can_send() );
		$this->assertTrue( $this->jobs_dm( self::ALEX, self::JORDAN, 'A job is yours.' ) );
		$this->assertSame( array( array( self::ALEX, self::JORDAN, 'A job is yours.' ) ), $this->sent );
		$this->assertFalse( $this->jobs_dm( self::JORDAN, self::JORDAN, 'x' ), 'never notify yourself' );
		$this->assertFalse( $this->jobs_dm( self::ALEX, self::DEVICE, 'x' ), 'the shared device is not a person' );
		$this->assertCount( 1, $this->sent );
	}

	public function test_security_monitor_keeps_its_digest_copy_through_the_seam(): void {
		$this->messages_installed();
		$r = ZDZ_Test_Wireb_Sm_Probe::copy( self::ALEX );
		$this->assertSame( 1, $r['message_id'] ?? 0 );
		$this->assertSame( array( array( self::ALEX, self::ALEX, "Digest\n\nAll clear." ) ), $this->sent, 'a note to self' );
	}

	public function test_the_widget_reads_only_the_zorderz_media_marker(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/messaging/assets/js/widget.js' );
		$this->assertStringContainsString( 'src.replace(/\[ZDZ-MEDIA-SHARE-([a-f0-9]{16,64})\]/gi', $js );
		$this->assertDoesNotMatchRegularExpression( '/\(\?:ZDZ-\|[A-Z]+\)MEDIA-SHARE/', $js, 'one marker name only' );
		$this->assertSame( 1, substr_count( $js, 'MEDIA-SHARE-([a-f0-9]' ) );
	}
}

/**
 * Reaches ZSM_Report::messages_copy() when the Security Monitor classes are loaded; otherwise
 * answers like the seam would (null when Messages is not there) using the same rule.
 */
final class ZDZ_Test_Wireb_Sm_Probe {
	public static function copy( int $uid ) {
		if ( ! class_exists( 'ZSM_Report' ) ) {
			$inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/security-monitor/includes/';
			foreach ( array( 'class-zsm-policy.php', 'class-zsm.php', 'class-zsm-feed-stream.php', 'class-zsm-feed.php', 'class-zsm-matcher.php', 'class-zsm-inventory.php', 'class-zsm-currency.php', 'class-zsm-findings.php', 'class-zsm-code.php', 'class-zsm-report.php' ) as $f ) {
				require_once $inc . $f;
			}
		}
		return ZSM_Report::messages_copy( $uid, 'Digest', 'All clear.' );
	}
}
