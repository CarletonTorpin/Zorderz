<?php
/**
 * Drawings on photos and media share grants.
 *
 *   - ZDZ_Media_Marks: a drawing is refused, never trimmed, when it is not the kit's shape (pens,
 *     widths, arrows, notes of at most 500 characters, the per-layer and per-photo limits); each
 *     person's layer has its own revision, and a save from a stale revision is a 409; who sees a
 *     layer follows one rule (mine / team / people / project through the filter / admin's choice),
 *     and the shared device sees only what the team sees.
 *   - ZDZ_Media_Share and ZDZ_User_Media::viewer_can_view: who may share, open and revoke; a grant
 *     is live until revoked or expired; an app's grant only adds a yes for a signed-in viewer.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-user-media.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-media-share.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-media-marks.php';

/** A database that has nothing in it: every read finds no row. */
final class Zdz_Notify_Empty_Db {
	public $prefix = 'wp_';
	public $last_error = '';
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return null; }
	public function get_row( $q, $o = null ) { return null; }
	public function get_results( $q, $o = null ) { return array(); }
	public function query( $q ) { return 0; }
}

final class NotifyMediaMarksShareTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_roles'] = array(
			1 => array( 'zdz_sales' ), 2 => array( 'zdz_tech' ), 3 => array( 'zdz_admin' ),
			4 => array( 'zdz_general' ), 5 => array( 'subscriber' ), 6 => array( 'zdz_operator' ),
		);
		ZDZ_Media_Marks::forget();
	}

	protected function tearDown(): void {
		zdz_notify_test_reset();
		ZDZ_Media_Marks::forget();
		unset( $GLOBALS['wpdb'] );
	}

	private static function line( string $id, array $over = array() ): array {
		return array_merge( array( 'id' => $id, 'c' => 'r', 's' => 2, 'p' => array( 0, 0, 5000, 5000 ), 'k' => 'l' ), $over );
	}

	/* ── The shape ────────────────────────────────────────────────────────── */

	public function test_a_good_drawing_is_kept_as_drawn(): void {
		$out = ZDZ_Media_Marks::clean( array( self::line( 'abc123' ), self::line( 'def456', array( 'k' => 'a', 'n' => "  gap <2mm\r\nhere " ) ) ) );
		$this->assertSame( 'gap <2mm' . "\n" . 'here', $out[1]['n'], 'markup is text, never removed' );
		$this->assertSame( array( 0, 0, 5000, 5000 ), $out[0]['p'] );
	}

	/** @dataProvider bad_drawings */
	public function test_a_bad_drawing_is_refused_never_trimmed( $strokes ): void {
		$r = ZDZ_Media_Marks::clean( $strokes );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 400, ZDZ_Media_Marks::http_status( $r->get_error_code() ) );
	}

	public function bad_drawings(): array {
		$many = array();
		for ( $i = 0; $i < 101; $i++ ) { $many[] = self::line( sprintf( 'line%04d', $i ) ); }
		return array(
			'a free colour'            => array( array( self::line( 'abc123', array( 'c' => '#ff0000' ) ) ) ),
			'width 4'                  => array( array( self::line( 'abc123', array( 's' => 4 ) ) ) ),
			'an arrow of 3 points'     => array( array( self::line( 'abc123', array( 'k' => 'a', 'p' => array( 1, 2, 3, 4, 5, 6 ) ) ) ) ),
			'a note on a line'         => array( array( self::line( 'abc123', array( 'n' => 'hi' ) ) ) ),
			'a note of 501 characters' => array( array( self::line( 'abc123', array( 'k' => 'a', 'n' => str_repeat( 'x', 501 ) ) ) ) ),
			'a point off the photo'    => array( array( self::line( 'abc123', array( 'p' => array( 0, 0, 10001, 5 ) ) ) ) ),
			'an odd point count'       => array( array( self::line( 'abc123', array( 'p' => array( 0, 0, 1 ) ) ) ) ),
			'a fractional point'       => array( array( self::line( 'abc123', array( 'p' => array( 0, 0.5, 1, 1 ) ) ) ) ),
			'a short id'               => array( array( self::line( 'ab' ) ) ),
			'the same id twice'        => array( array( self::line( 'abc123' ), self::line( 'abc123' ) ) ),
			'101 lines'                => array( $many ),
			'not a list'               => array( array( 'x' => self::line( 'abc123' ) ) ),
			'a note that is not UTF-8' => array( array( self::line( 'abc123', array( 'k' => 'a', 'n' => "\xff\xfe" ) ) ) ),
		);
	}

	/* ── Layers and revisions ─────────────────────────────────────────────── */

	public function test_each_person_saves_their_own_layer(): void {
		$doc = ZDZ_Media_Marks::decode( null );
		list( $doc, $added ) = ZDZ_Media_Marks::with_layer( $doc, 1, ZDZ_Media_Marks::clean( array( self::line( 'abc123' ) ) ), '2026-01-15 10:00:00' );
		$this->assertSame( 1, $added );
		$this->assertSame( 'private', $doc['layers'][0]['share'], 'a first drawing starts private' );
		$this->assertSame( 1, ZDZ_Media_Marks::last_rev( $doc, 1 ) );
		list( $doc ) = ZDZ_Media_Marks::with_layer( $doc, 2, ZDZ_Media_Marks::clean( array( self::line( 'ghi789' ) ) ), '2026-01-15 10:01:00', 'project:79' );
		$this->assertSame( 'project', $doc['layers'][1]['share'], 'drawn in a Project: shared with its people' );
		$this->assertSame( 1, ZDZ_Media_Marks::last_rev( $doc, 1 ), "someone else's save never moves mine" );
		$this->assertFalse( ZDZ_Media_Marks::is_stale( $doc, 1, 1 ) );
		$this->assertTrue( ZDZ_Media_Marks::is_stale( $doc, 1, 0 ), 'the same drawing saved from two devices' );
		$this->assertSame( 409, ZDZ_Media_Marks::http_status( 'zdz_media_marks_stale' ) );
		$this->assertSame( 409, ZDZ_Media_Marks::http_status( 'zdz_media_marks_busy' ) );
		$this->assertSame( 403, ZDZ_Media_Marks::http_status( 'zdz_media_marks_denied' ) );
		list( $doc, , $removed ) = ZDZ_Media_Marks::with_layer( $doc, 1, array(), '2026-01-15 10:02:00' );
		$this->assertSame( 1, $removed );
		$this->assertSame( -1, ZDZ_Media_Marks::layer_index( $doc, 1 ), 'clearing every line removes the layer' );
		$this->assertSame( 2, ZDZ_Media_Marks::last_rev( $doc, 1 ), 'the clear is a revision: an old device stays stale' );
		$this->assertTrue( ZDZ_Media_Marks::is_stale( $doc, 1, 1 ) );
	}

	public function test_a_photo_holds_at_most_twenty_drawings(): void {
		$doc = ZDZ_Media_Marks::decode( null );
		for ( $u = 100; $u < 120; $u++ ) {
			list( $doc ) = ZDZ_Media_Marks::with_layer( $doc, $u, ZDZ_Media_Marks::clean( array( self::line( 'abc123' ) ) ), '2026-01-15 10:00:00' );
		}
		$r = ZDZ_Media_Marks::with_layer( $doc, 200, ZDZ_Media_Marks::clean( array( self::line( 'abc123' ) ) ), '2026-01-15 10:00:00' );
		$this->assertInstanceOf( 'WP_Error', $r );
	}

	public function test_sharing_choices(): void {
		$doc = ZDZ_Media_Marks::decode( null );
		list( $doc ) = ZDZ_Media_Marks::with_layer( $doc, 1, ZDZ_Media_Marks::clean( array( self::line( 'abc123' ) ) ), '2026-01-15 10:00:00' );
		$this->assertInstanceOf( 'WP_Error', ZDZ_Media_Marks::with_share( $doc, 1, 'people', array() ), 'choose at least one person' );
		$this->assertInstanceOf( 'WP_Error', ZDZ_Media_Marks::with_share( $doc, 1, 'project', array() ), 'not drawn in a Project' );
		$this->assertInstanceOf( 'WP_Error', ZDZ_Media_Marks::with_share( $doc, 1, 'everyone', array() ) );
		$this->assertInstanceOf( 'WP_Error', ZDZ_Media_Marks::with_share( $doc, 2, 'team', array() ), 'no layer of theirs' );
		$this->assertInstanceOf( 'WP_Error', ZDZ_Media_Marks::with_share( $doc, 1, 'people', range( 10, 41 ) ), 'at most 30 people' );
		$d = ZDZ_Media_Marks::with_share( $doc, 1, 'people', array( 2, 1, 2 ) );
		$this->assertSame( array( 2 ), $d['layers'][0]['people'], 'never themselves, never twice' );
	}

	public function test_who_sees_a_layer(): void {
		$row  = array( 'id' => 9, 'user_id' => 1, 'privacy' => 'team', 'media_type' => 'photo' );
		$base = array( 'uid' => 1, 'share' => 'private', 'people' => array(), 'context' => '' );
		$this->assertSame( 'mine', ZDZ_Media_Marks::audience( $base, 1, $row, false ) );
		$this->assertSame( '', ZDZ_Media_Marks::audience( $base, 2, $row, false ), 'private' );
		$this->assertSame( 'admin', ZDZ_Media_Marks::audience( $base, 3, $row, true ), "an administrator who chose to see everyone's" );
		$this->assertSame( 'team', ZDZ_Media_Marks::audience( array( 'share' => 'team' ) + $base, 4, $row, false ), 'the shared device sees team layers' );
		$this->assertSame( '', ZDZ_Media_Marks::audience( array( 'share' => 'people', 'people' => array( 4 ) ) + $base, 4, $row, false ), 'but nothing chosen for a person' );
		$this->assertSame( 'people', ZDZ_Media_Marks::audience( array( 'share' => 'people', 'people' => array( 2 ) ) + $base, 2, $row, false ) );
		$this->assertSame( '', ZDZ_Media_Marks::audience( array( 'share' => 'team' ) + $base, 5, $row, false ), 'never a bare signed-in account' );
		$proj = array( 'share' => 'project', 'context' => 'project:79' ) + $base;
		$this->assertSame( '', ZDZ_Media_Marks::audience( $proj, 6, $row, false ), 'nobody answers for the Project' );
		$GLOBALS['zdz_test_filters']['zdz_media_layer_audience'] = function ( $yes, $viewer, $ctx ) { return 6 === $viewer && 'project:79' === $ctx; };
		ZDZ_Media_Marks::forget();
		$this->assertSame( 'project', ZDZ_Media_Marks::audience( $proj, 6, $row, false ) );
	}

	public function test_lists_carry_counts_not_lines(): void {
		$doc = ZDZ_Media_Marks::decode( null );
		list( $doc ) = ZDZ_Media_Marks::with_layer( $doc, 1, ZDZ_Media_Marks::clean( array( self::line( 'abc123' ), self::line( 'def456', array( 'k' => 'a', 'n' => 'Check this' ) ) ) ), '2026-01-15 10:00:00' );
		$doc = ZDZ_Media_Marks::with_share( $doc, 1, 'team', array() );
		$row = array( 'id' => 9, 'user_id' => 1, 'privacy' => 'team', 'media_type' => 'photo' );
		$this->assertSame( array( 1, 1 ), ZDZ_Media_Marks::counts_for( wp_json_encode_for_test( $doc ), 2, $row ) );
		$this->assertSame( array( 0, 0 ), ZDZ_Media_Marks::counts_for( null, 2, $row ) );
	}

	public function test_an_older_document_reads_as_shared_layers(): void {
		$doc = ZDZ_Media_Marks::decode( array( 'rev' => 3, 'strokes' => array( self::line( 'abc123' ) + array( 'by' => 1 ), self::line( 'def456' ) + array( 'by' => 2 ) ) ) );
		$this->assertCount( 2, $doc['layers'] );
		$this->assertSame( 'team', $doc['layers'][0]['share'], 'it was shown to everyone, so it stays so' );
	}

	public function test_a_quarter_turn_turns_the_drawing(): void {
		$doc = array( 'v' => 2, 'layers' => array( array( 'uid' => 1, 'rev' => 1, 'strokes' => array( array( 'p' => array( 1000, 2000 ) ) ) ) ) );
		$t = ZDZ_Media_Marks::rotate( $doc, 90 );
		$this->assertSame( array( 8000, 1000 ), $t['layers'][0]['strokes'][0]['p'] );
		$this->assertSame( 2, $t['layers'][0]['rev'], 'a turned layer moves on a revision' );
		$this->assertSame( $doc, ZDZ_Media_Marks::rotate( $doc, 45 ) );
	}

	/* ── Who may draw, and grants ─────────────────────────────────────────── */

	public function test_who_may_draw(): void {
		$photo = array( 'id' => 9, 'user_id' => 1, 'privacy' => 'team', 'media_type' => 'photo' );
		$this->assertTrue( ZDZ_User_Media::viewer_may_mark( 2, $photo ) );
		$this->assertFalse( ZDZ_User_Media::viewer_may_mark( 4, $photo ), 'never the shared device' );
		$this->assertFalse( ZDZ_User_Media::viewer_may_mark( 5, $photo ), 'never a bare signed-in account' );
		$this->assertFalse( ZDZ_User_Media::viewer_may_mark( 2, array( 'media_type' => 'video' ) + $photo ), 'not a picture' );
		$this->assertTrue( ZDZ_User_Media::markable( array( 'media_type' => 'estimate_photo' ) ) );
	}

	public function test_private_media_access_and_an_apps_grant(): void {
		$GLOBALS['wpdb'] = new Zdz_Notify_Empty_Db();
		$GLOBALS['zdz_test_options']['zdz_media_shares_schema'] = 1;
		$private = array( 'id' => 9, 'user_id' => 1, 'privacy' => 'private', 'media_type' => 'photo' );
		$this->assertTrue( ZDZ_User_Media::viewer_can_view( 1, $private ), 'its owner' );
		$this->assertTrue( ZDZ_User_Media::viewer_can_view( 3, $private ), 'an administrator' );
		$this->assertFalse( ZDZ_User_Media::viewer_can_view( 2, $private ), 'no grant' );
		$this->assertFalse( ZDZ_User_Media::may_see_photo( 2, $private ) );
		$GLOBALS['zdz_test_filters']['zdz_media_viewer_can_view'] = function ( $yes, $viewer ) { return true; };
		$this->assertTrue( ZDZ_User_Media::viewer_can_view( 2, $private ), "an app's grant" );
		$this->assertFalse( ZDZ_User_Media::viewer_can_view( 0, $private ), 'never for a signed-out request' );
		$this->assertTrue( ZDZ_User_Media::may_see_photo( 2, array( 'privacy' => 'team' ) + $private ) );
	}

	public function test_share_permissions(): void {
		$item = array( 'id' => 9, 'user_id' => 1 );
		$this->assertTrue( ZDZ_Media_Share::may_share_item( 1, $item ), 'its owner' );
		$this->assertTrue( ZDZ_Media_Share::may_share_item( 3, $item ), 'an administrator' );
		$this->assertFalse( ZDZ_Media_Share::may_share_item( 2, $item ), 'someone else' );
		$this->assertFalse( ZDZ_Media_Share::may_share_item( 0, $item ) );
		$this->assertTrue( ZDZ_Media_Share::may_view_share( 2, 1, 2 ), 'the recipient' );
		$this->assertFalse( ZDZ_Media_Share::may_view_share( 6, 1, 2 ), 'a stranger with the marker' );
		$this->assertTrue( ZDZ_Media_Share::may_revoke( 1, 1 ) );
		$this->assertFalse( ZDZ_Media_Share::may_revoke( 2, 1 ), 'the recipient cannot revoke' );
		$this->assertTrue( ZDZ_Media_Share::is_kiosk( 4 ), 'the shared device neither shares nor receives' );
		$this->assertTrue( ZDZ_Media_Share::is_kiosk( 999 ), 'no account at all' );
		$this->assertFalse( ZDZ_Media_Share::is_kiosk( 2 ) );
	}

	public function test_a_grant_is_live_until_revoked_or_expired(): void {
		$now = '2026-01-15 10:00:00';
		$this->assertTrue( ZDZ_Media_Share::grant_live( array( 'revoked_at' => null, 'expires_at' => null ), $now ) );
		$this->assertFalse( ZDZ_Media_Share::grant_live( array( 'revoked_at' => '2026-01-14 09:00:00' ), $now ) );
		$this->assertFalse( ZDZ_Media_Share::grant_live( array( 'expires_at' => '2026-01-15 09:59:59' ), $now ) );
		$this->assertTrue( ZDZ_Media_Share::grant_live( array( 'expires_at' => '2026-01-16 00:00:00' ), $now ) );
		$this->assertSame( ZDZ_Media_Share::signature( array( 3, 1, 2 ) ), ZDZ_Media_Share::signature( array( '2', 3, 1 ) ), 'a set, in any order' );
	}
}

/** JSON as the database holds it (wp_json_encode without WordPress). */
function wp_json_encode_for_test( $v ) {
	return json_encode( $v );
}
