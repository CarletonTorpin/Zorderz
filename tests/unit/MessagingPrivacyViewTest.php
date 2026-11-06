<?php
/**
 * Messaging: an account in Privacy View (ZIM_Privacy_View, with the theme's ZDZ_Privacy_View).
 *
 *   - Nothing is open until an administrator chooses who it may message; then only the five Messages actions of its
 *     one thread and the chat's confirmation, never search, upload, edit, delete, channels or push.
 *   - Its one conversation: the direct conversation with that administrator; any other is refused, for the page and
 *     for every writer (ZIM_Messages::post), whatever the caller.
 *   - The list holds that thread alone; it posts text only, with its label once; zim_dm_send reaches only the partner.
 *   - The theme's own gate opens the direct-message actions only with this class present and a partner chosen.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';

final class MessagingPrivacyViewTest extends TestCase {

	const PV    = 21;   // the account in Privacy View
	const ADMIN = 22;   // the administrator chosen for it
	const OTHER = 23;   // another administrator
	const STAFF = 24;

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( self::PV => 'staff', self::ADMIN => 'admin', self::OTHER => 'admin', self::STAFF => 'staff' ) );
		$GLOBALS['zdz_test_user_meta'][ self::PV ]['zdz_privacy_view'] = '1';
		$GLOBALS['zdz_test_current_user']                              = self::PV;
		// The direct conversation between the account and its partner is #77.
		$GLOBALS['wpdb']->on_var = function ( $sql ) {
			return false !== strpos( $sql, "kind = 'dm' AND user_a = " . self::PV . ' AND user_b = ' . self::ADMIN ) ? '77' : null;
		};
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	private function choose_partner( int $uid ): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = $uid;
	}

	public function test_nothing_is_open_until_an_administrator_is_chosen(): void {
		$this->assertTrue( ZIM_Privacy_View::on() );
		$this->assertSame( 0, ZIM_Privacy_View::partner() );
		foreach ( ZIM_Privacy_View::ACTIONS as $a ) {
			$this->assertFalse( ZIM_Privacy_View::action_ok( $a ), $a );
		}
		$this->assertFalse( ZIM_Privacy_View::conversation_ok( 77 ) );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ), 'the theme keeps them shut too' );
	}

	public function test_only_the_threads_own_actions_once_chosen(): void {
		$this->choose_partner( self::ADMIN );
		$this->assertSame( self::ADMIN, ZIM_Privacy_View::partner() );
		foreach ( array( 'zim_sidebar', 'zim_fetch_before', 'zim_poll', 'zim_mark_read', 'zim_post', 'zana_confirm_zim_dm' ) as $a ) {
			$this->assertTrue( ZIM_Privacy_View::action_ok( $a ), $a );
		}
		foreach ( array( 'zim_search', 'zim_upload', 'zim_edit', 'zim_delete', 'zim_bulk_delete', 'zim_channel_create', 'zim_member_add',
			'zim_preview_ref', 'zim_share_ref', 'zim_push_subscribe', 'zim_dm_open', 'zim_user_search', 'zim_media_share', '' ) as $a ) {
			$this->assertFalse( ZIM_Privacy_View::action_ok( $a ), $a );
		}
		$this->assertTrue( ZDZ_Privacy_View::dm_narrowed(), 'the theme sees the narrowing class' );
		$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ) );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_upload' ) );
	}

	public function test_the_partner_must_still_be_an_administrator(): void {
		$this->choose_partner( self::STAFF );
		$this->assertSame( 0, ZIM_Privacy_View::partner(), 'not an administrator: nobody' );
		$this->assertFalse( ZIM_Privacy_View::action_ok( 'zim_post' ) );
	}

	public function test_its_one_conversation_only(): void {
		$this->choose_partner( self::ADMIN );
		$this->assertSame( 77, ZIM_Privacy_View::conversation_id() );
		$this->assertTrue( ZIM_Privacy_View::conversation_ok( 77 ) );
		$this->assertFalse( ZIM_Privacy_View::conversation_ok( 78 ) );
		$this->choose_partner( self::OTHER );
		$this->assertFalse( ZIM_Privacy_View::conversation_ok( 77 ), 'a new partner: the old thread closes' );
	}

	public function test_the_list_is_that_thread_alone(): void {
		$this->choose_partner( self::ADMIN );
		$out = ZIM_Privacy_View::sidebar( array(
			'channels' => array( array( 'id' => 1, 'name' => '#announcements' ) ),
			'dms'      => array( array( 'id' => 77, 'other_name' => 'Morgan' ), array( 'id' => 80, 'other_name' => 'Quinn' ) ),
		) );
		$this->assertSame( array(), $out['channels'] );
		$this->assertSame( array( 77 ), array_column( $out['dms'], 'id' ) );
	}

	public function test_every_writer_is_held_to_the_thread(): void {
		$this->choose_partner( self::ADMIN );
		$r = ZIM_Messages::post( 78, self::PV, 'hello' );
		$this->assertSame( 'zim_privacy_view', $r->get_error_code(), 'another conversation, whatever the caller' );
		$r = ZIM_Messages::post( 77, self::PV, 'see the file', array( 5 ) );
		$this->assertSame( 'zim_text_only', $r->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted, 'nothing written' );
	}

	public function test_its_text_is_plain_with_one_label(): void {
		$pre = ZIM_Privacy_View::prefix();
		$this->assertSame( $pre . 'Hello', ZIM_Privacy_View::body( 'Hello' ) );
		$this->assertSame( $pre . 'Hello', ZIM_Privacy_View::body( $pre . 'Hello' ), 'never twice' );
		$b = ZIM_Privacy_View::body( '&lt;img src=x onerror=alert(1)&gt; <b>bold</b> [the plan](https://example.com/p) ![pic](https://example.com/i.png)' );
		$this->assertStringNotContainsString( '<img', $b, 'decoded first, then stripped: no live tag' );
		$this->assertStringNotContainsString( 'example.com', $b, 'no hidden link address, no picture' );
		$this->assertStringContainsString( 'the plan', $b );
		$this->assertStringContainsString( "a\nb", ZIM_Privacy_View::body( '<p>a</p><p>b</p>' ), 'lines kept' );
		$GLOBALS['zdz_test_filters']['zim_privacy_view_prefix'] = function () { return ''; };
		$this->assertSame( $pre, ZIM_Privacy_View::prefix(), 'the label may change, never disappear' );
	}

	public function test_send_from_the_chat_reaches_only_the_partner(): void {
		$this->choose_partner( self::ADMIN );
		$r = ZIM_DM_Send::send( null, self::PV, self::OTHER, 'hello' );
		$this->assertSame( 'zim_privacy_view', $r->get_error_code() );
		$this->choose_partner( 0 );
		$this->assertSame( 'zim_privacy_view', ZIM_DM_Send::send( null, self::PV, self::ADMIN, 'hello' )->get_error_code(), 'nobody chosen' );
	}

	public function test_dm_send_refuses_the_shared_device_and_says_whose_access_is_missing(): void {
		zdz_test_msg_people( array( 30 => 'shared', 31 => 'none' ) );
		$this->assertSame( 'zim_read_only', ZIM_DM_Send::send( null, 30, self::STAFF, 'x' )->get_error_code() );
		$r = ZIM_DM_Send::send( null, self::STAFF, 31, 'x' );
		$this->assertSame( 'zim_no_access', $r->get_error_code() );
		$this->assertStringContainsString( 'teammate', $r->get_error_message() );
		$this->assertSame( 'zim_no_access', ZIM_DM_Send::send( null, 31, self::STAFF, 'x' )->get_error_code() );
		$this->assertSame( 'zim_empty_message', ZIM_DM_Send::send( null, self::STAFF, self::ADMIN, '  ' )->get_error_code() );
		$this->assertSame( 'kept', ZIM_DM_Send::send( 'kept', self::STAFF, self::ADMIN, 'x' ), 'an earlier answer passes through' );
	}

	public function test_chat_source_is_kept_for_the_sender_alone(): void {
		$this->assertNull( ZIM_DM_Send::clean_source( array( 'app' => 'other', 'session_id' => 3 ) ) );
		$this->assertNull( ZIM_DM_Send::clean_source( array( 'app' => 'chat', 'session_id' => 0 ) ) );
		ZIM_DM_Send::remember_source( self::STAFF, 501, array( 'session_id' => 9, 'message_id' => 4 ) );
		$rows = array(
			array( 'id' => 501, 'author_user_id' => self::STAFF, 'deleted_at' => null ),
			array( 'id' => 502, 'author_user_id' => self::ADMIN, 'deleted_at' => null ),
		);
		$this->assertSame( array( 501 => array( 'session_id' => 9, 'message_id' => 4 ) ), ZIM_DM_Send::sources_for_viewer( self::STAFF, $rows ) );
		$this->assertSame( array(), ZIM_DM_Send::sources_for_viewer( self::ADMIN, $rows ), 'never shown to anyone else' );
	}

	public function test_unread_counts_for_privacy_view_are_its_thread_only(): void {
		$this->choose_partner( self::ADMIN );
		$this->assertSame( array( 'unread' => 3, 'direct' => 1, 'channels' => 2, 'conversations' => 2 ),
			ZIM_Unread_Counts::tally( array( array( 'unread' => 2 ), array( 'unread' => 0 ) ), array( array( 'unread' => 1 ) ) ) );
	}
}
