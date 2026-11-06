<?php
/**
 * Media's media.my_counts verb: a read, never on the shared device, counts only; what counts as a photo, a video
 * or other; a malformed or too-long week is refused rather than answered with zeros.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/media/inc/class-zml-my-counts.php';

final class MediaMyCountsTest extends TestCase {

	public function test_descriptor_is_a_read_not_on_the_shared_device(): void {
		$d = ZML_My_Counts::descriptor();
		$this->assertSame( 'media.my_counts', $d['verb'] );
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'zdz-media', $d['app'] );
	}

	public function test_split_by_kind(): void {
		$this->assertSame(
			array( 'photos' => 6, 'videos' => 2, 'other' => 1 ),
			ZML_My_Counts::split( array( 'photo' => 3, 'image' => 1, 'estimate_photo' => 2, 'video' => 1, 'job_video' => 1, 'sketch' => 1 ) )
		);
	}

	public function test_refusals(): void {
		$this->assertSame( 'signed_out', ZML_My_Counts::from_capability( array() )['reason'] );
		$this->assertSame( 'kiosk', ZML_My_Counts::from_capability( array( 'user_id' => 5, 'is_kiosk' => true ) )['reason'] );
		$r = ZML_My_Counts::from_capability( array( 'user_id' => 5 ) );
		$this->assertFalse( $r['success'], 'without the theme counter it is an error, never zeros' );
		$this->assertArrayNotHasKey( 'photos', $r );
	}

	public function test_day_validation(): void {
		$this->assertSame( '2027-02-28', ZML_My_Counts::day( '2027-02-28' ) );
		$this->assertNull( ZML_My_Counts::day( '2027-02-30' ) );
		$this->assertNull( ZML_My_Counts::day( 'yesterday' ) );
	}
}
