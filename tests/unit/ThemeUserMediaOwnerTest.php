<?php
/**
 * Tags, the owner move, who uploaded a photo, and rotating one.
 *
 *   - apply_tags(): adding and removing are case-insensitive and idempotent (no change, no write); every change is
 *     recorded (who, when, what, why); a missing row is null.
 *   - actor_may_reassign(): the administrator rung only, never the shared device, never a signed-out id.
 *   - reassign_owner(): refused to anyone but an administrator, to the shared device or a non-staff account as the
 *     new owner; keeps who uploaded the photo across moves; a photo moved meanwhile is refused, never overwritten.
 *   - provenance(): user ids only, never a name or the move's reason.
 *   - rotate_photo(): refuses another person's photo (unless the viewer may see others'), a bad angle, a signed-out
 *     viewer, and anything that is not an image attachment, before any file is touched.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-user-media.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'error_log' ) ) {
	function error_log( $m ) { return true; }
}

/**
 * An in-memory media table: just the statements ZDZ_User_Media's tag, owner and provenance code runs. prepare()
 * returns the SQL and its arguments together, so each read and write can be answered from the rows.
 */
final class Zdz_Test_Thm_Media_Db {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array();
	public $writes = 0;
	/** A callable run just before the next conditional UPDATE: someone else's change landing in between. */
	public $meanwhile = null;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( $sql, $args );
	}

	public function get_var( $q ) {
		list( $sql, $a ) = $q;
		if ( preg_match( '/SELECT meta_json FROM/', $sql ) ) {
			$id = (int) $a[0];
			return isset( $this->rows[ $id ] ) ? (string) $this->rows[ $id ]['meta_json'] : null;
		}
		return null;
	}

	public function get_row( $q, $o = null ) {
		list( $sql, $a ) = is_array( $q ) ? $q : array( $q, array() );
		$id = (int) ( $a[0] ?? 0 );
		return $this->rows[ $id ] ?? null;
	}

	public function get_results( $q, $o = null ) { return array(); }

	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		$this->writes++;
		return 1;
	}

	public function query( $q ) {
		list( $sql, $a ) = $q;
		if ( null !== $this->meanwhile ) {
			$f = $this->meanwhile;
			$this->meanwhile = null;
			$f( $this );
		}
		if ( preg_match( '/^UPDATE \S+ SET user_id = %d, meta_json = %s, updated_at = %s WHERE id = %d AND user_id = %d AND (BINARY meta_json = %s|meta_json IS NULL)/', $sql, $m ) ) {
			list( $new, $meta, $when, $id, $old ) = $a;
			$r = $this->rows[ (int) $id ] ?? null;
			if ( ! $r || (int) $r['user_id'] !== (int) $old ) {
				return 0;
			}
			if ( 'meta_json IS NULL' === $m[1] ? null !== $r['meta_json'] : (string) $r['meta_json'] !== (string) $a[5] ) {
				return 0;
			}
			$this->rows[ (int) $id ] = array_merge( $r, array( 'user_id' => (int) $new, 'meta_json' => $meta, 'updated_at' => $when ) );
			$this->writes++;
			return 1;
		}
		return 0;
	}
}

final class ThemeUserMediaOwnerTest extends TestCase {

	private $db;

	protected function setUp(): void {
		// 1 sales, 2 tech, 3 admin role, 4 the shared device, 5 a subscriber, 6 a site administrator, 7 a shared
		// device that also holds manage_options.
		$GLOBALS['zdz_test_user_roles'] = array(
			1 => array( 'zdz_sales' ), 2 => array( 'zdz_tech' ), 3 => array( 'zdz_admin' ),
			4 => array( 'zdz_general' ), 5 => array( 'subscriber' ), 6 => array( 'administrator' ), 7 => array( 'zdz_general' ),
		);
		$GLOBALS['zdz_test_user_caps'] = array( 6 => array( 'manage_options' ), 7 => array( 'manage_options' ) );
		$this->db = new Zdz_Test_Thm_Media_Db();
		$this->db->rows[10] = array( 'id' => 10, 'user_id' => 1, 'privacy' => 'private', 'media_type' => 'photo', 'meta_json' => '{"exif":{"Make":"Example"}}', 'updated_at' => '2026-01-15 10:00:00', 'share_token' => str_repeat( 'a', 32 ), 'wp_attachment_id' => 0 );
		$this->db->rows[11] = array( 'id' => 11, 'user_id' => 2, 'privacy' => 'team', 'media_type' => 'photo', 'meta_json' => null, 'updated_at' => '2026-01-15 10:00:00', 'share_token' => str_repeat( 'b', 32 ), 'wp_attachment_id' => 0 );
		$GLOBALS['wpdb'] = $this->db;
		if ( class_exists( 'ZDZ_Capabilities' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	protected function tearDown(): void {
		zdz_notify_test_reset();
		unset( $GLOBALS['wpdb'] );
	}

	private function meta( int $id ): array {
		return (array) json_decode( (string) $this->db->rows[ $id ]['meta_json'], true );
	}

	/* ── Tags ─────────────────────────────────────────────────────────────── */

	public function test_tags_add_once_and_keep_the_exif_block(): void {
		$r = ZDZ_User_Media::apply_tags( 10, array( 'Before', 'Front door' ), array(), 1 );
		$this->assertTrue( $r['changed'] );
		$this->assertSame( array( 'Before', 'Front door' ), $r['tags'] );
		$m = $this->meta( 10 );
		$this->assertSame( array( 'Make' => 'Example' ), $m['exif'], 'the stored EXIF block survives a tag edit' );
		$this->assertSame( 1, $m['last_tagged_by'] );
		$this->assertCount( 1, $m['tag_history'] );
	}

	public function test_tags_are_idempotent_and_case_insensitive(): void {
		ZDZ_User_Media::apply_tags( 10, array( 'Before' ), array(), 1 );
		$writes = $this->db->writes;
		$again  = ZDZ_User_Media::apply_tags( 10, array( 'before', 'BEFORE' ), array(), 1 );
		$this->assertFalse( $again['changed'], 'adding a tag already there in another case changes nothing' );
		$none   = ZDZ_User_Media::apply_tags( 10, array(), array( 'after' ), 1 );
		$this->assertFalse( $none['changed'], 'removing a tag that is not there changes nothing' );
		$this->assertSame( $writes, $this->db->writes, 'no change, no write' );
		$gone = ZDZ_User_Media::apply_tags( 10, array(), array( 'BEFORE' ), 3, 'wrong job' );
		$this->assertTrue( $gone['changed'] );
		$this->assertSame( array(), $gone['tags'] );
		$last = end( $this->meta( 10 )['tag_history'] );
		$this->assertSame( array( 'Before' ), $last['rm'] );
		$this->assertSame( 'wrong job', $last['why'] );
		$this->assertSame( 3, $last['by'] );
	}

	public function test_tags_on_a_missing_row_or_an_empty_tag(): void {
		$this->assertNull( ZDZ_User_Media::apply_tags( 99, array( 'x' ), array(), 1 ) );
		$this->assertNull( ZDZ_User_Media::apply_tags( 0, array( 'x' ), array(), 1 ) );
		$r = ZDZ_User_Media::apply_tags( 10, array( '   ', str_repeat( 'x', 61 ) ), array(), 1 );
		$this->assertFalse( $r['changed'], 'blank and over-long tags are dropped' );
	}

	/* ── Who may move a photo ─────────────────────────────────────────────── */

	public function test_only_the_administrator_rung_may_move_a_photo(): void {
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 0 ) );
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 1 ), 'sales' );
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 2 ), 'tech' );
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 5 ), 'subscriber' );
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 4 ), 'the shared device' );
		$this->assertFalse( ZDZ_User_Media::actor_may_reassign( 7 ), 'the shared device, even holding manage_options' );
		$this->assertTrue( ZDZ_User_Media::actor_may_reassign( 6 ), 'a site administrator' );
		$this->assertTrue( ZDZ_User_Media::actor_may_reassign( 3 ), 'the admin role' );
	}

	public function test_a_move_is_refused_to_a_non_administrator(): void {
		$r = ZDZ_User_Media::reassign_owner( 10, 2, 1 );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'zdz_media_not_admin', $r->get_error_code() );
		$this->assertSame( 1, $this->db->rows[10]['user_id'], 'the photo stays where it was' );
	}

	public function test_a_photo_never_goes_to_the_shared_device_or_an_outsider(): void {
		$this->assertSame( 'zdz_media_kiosk', ZDZ_User_Media::reassign_owner( 10, 4, 6 )->get_error_code() );
		$this->assertSame( 'zdz_media_not_staff', ZDZ_User_Media::reassign_owner( 10, 5, 6 )->get_error_code() );
		$this->assertSame( 'zdz_media_no_user', ZDZ_User_Media::reassign_owner( 10, 42, 6 )->get_error_code() );
		$this->assertSame( 'zdz_media_missing', ZDZ_User_Media::reassign_owner( 99, 2, 6 )->get_error_code() );
		$this->assertSame( 1, $this->db->rows[10]['user_id'] );
	}

	public function test_a_move_keeps_who_uploaded_it(): void {
		$r = ZDZ_User_Media::reassign_owner( 10, 2, 6, 'taken on the wrong phone' );
		$this->assertTrue( $r['moved'] );
		$this->assertSame( 2, $this->db->rows[10]['user_id'] );
		$this->assertSame( 1, $r['uploaded_by'] );
		$r2 = ZDZ_User_Media::reassign_owner( 10, 3, 6 );
		$this->assertSame( 1, $r2['uploaded_by'], 'the uploader is written once and never rewritten' );
		$m = $this->meta( 10 );
		$this->assertCount( 2, $m['owner_history'] );
		$this->assertSame( array( 'Make' => 'Example' ), $m['exif'] );
		$same = ZDZ_User_Media::reassign_owner( 10, 3, 6 );
		$this->assertFalse( $same['moved'], 'moving to the current owner changes nothing' );
	}

	public function test_a_row_with_no_details_yet_can_be_moved(): void {
		$r = ZDZ_User_Media::reassign_owner( 11, 1, 3 );
		$this->assertTrue( $r['moved'] );
		$this->assertSame( 2, $this->meta( 11 )['uploaded_by'] );
	}

	public function test_a_photo_moved_meanwhile_is_refused(): void {
		$this->db->meanwhile = function ( $db ) {
			$db->rows[10]['user_id'] = 3;   // another administrator's move landed first
		};
		$r = ZDZ_User_Media::reassign_owner( 10, 2, 6 );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'zdz_media_moved_meanwhile', $r->get_error_code() );
		$this->assertSame( 3, $this->db->rows[10]['user_id'], 'the other move stands' );
	}

	public function test_a_tag_saved_meanwhile_is_kept_by_the_move(): void {
		$this->db->meanwhile = function ( $db ) {
			$m = json_decode( (string) $db->rows[10]['meta_json'], true );
			$m['tags'] = array( 'After' );
			$db->rows[10]['meta_json'] = json_encode( $m );
		};
		$r = ZDZ_User_Media::reassign_owner( 10, 2, 6 );
		$this->assertTrue( $r['moved'] );
		$this->assertSame( array( 'After' ), $this->meta( 10 )['tags'], 'a tag edit in between is never overwritten' );
	}

	/* ── Provenance ───────────────────────────────────────────────────────── */

	public function test_provenance_is_ids_only(): void {
		$this->assertSame( array( 'uploaded_by' => 2, 'moved_by' => 0, 'moved_at' => '' ), ZDZ_User_Media::provenance( $this->db->rows[11] ) );
		ZDZ_User_Media::reassign_owner( 10, 2, 6, 'Robin asked for it' );
		$p = ZDZ_User_Media::provenance( $this->db->rows[10] );
		$this->assertSame( array( 'uploaded_by', 'moved_by', 'moved_at' ), array_keys( $p ) );
		$this->assertSame( 1, $p['uploaded_by'] );
		$this->assertSame( 6, $p['moved_by'] );
		$this->assertStringNotContainsString( 'Robin', json_encode( $p ), 'never the reason or a name' );
		$this->assertSame( 2, ZDZ_User_Media::provenance( array( 'user_id' => 2, 'meta_json' => 'not json' ) )['uploaded_by'] );
	}

	/* ── Rotate ───────────────────────────────────────────────────────────── */

	public function test_rotate_refuses_before_touching_any_file(): void {
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 2, 90 ), "another person's photo" );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 4, 90 ), 'the shared device' );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 0, 90 ), 'signed out' );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 1, 45 ), 'not a quarter turn' );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 1, 360 ), 'no turn at all' );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 99, 1, 90 ), 'no such photo' );
		$this->assertFalse( ZDZ_User_Media::rotate_photo( 10, 1, 90 ), 'the owner, but no image attachment' );
		$this->assertSame( 1, $this->db->rows[10]['user_id'] );
		$this->assertArrayNotHasKey( 'last_rotated_by', $this->meta( 10 ), 'nothing recorded for a refused turn' );
	}

	public function test_gated_addresses_change_when_the_row_does(): void {
		$a = ZDZ_User_Media::secure_url( $this->db->rows[10], 'full' );
		$b = ZDZ_User_Media::secure_url( array( 'updated_at' => '2026-01-15 10:05:00' ) + $this->db->rows[10], 'full' );
		$this->assertNotSame( $a, $b, 'an in-place edit gives the photo a new address, so no stale copy is shown' );
		$this->assertStringContainsString( 'zdz_media=10', $a );
	}
}
