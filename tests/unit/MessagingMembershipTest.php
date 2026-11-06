<?php
/**
 * Messaging: membership and channels.
 *
 *   - ZIM_Membership answers the literal question (a member row), for nobody and for no conversation: false.
 *   - Core seeds one channel (#announcements: everyone reads, administrators post). A business's channels come from
 *     its Identity Pack (org.yml messaging.channels) or a filter, cleaned: Core role slugs only, and a channel whose
 *     roles are all unknown is dropped, never opened to everyone ("everyone" must be said). The pack cannot change
 *     Core's channel; preview, apply and revert work on the stored list.
 *   - A shared-media card renders only for someone in its conversation (or who may see everyone's media).
 *   - messaging.unread_counts: counts only, never for the shared device or someone without Messages.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';

final class MessagingMembershipTest extends TestCase {

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( 51 => 'staff', 52 => 'staff', 53 => 'shared', 54 => 'none' ) );
		// 51 is a member of conversation 70; nobody else is.
		$GLOBALS['wpdb']->on_var = function ( $sql ) {
			return false !== strpos( $sql, 'zim_members' ) && false !== strpos( $sql, 'conversation_id = 70 AND user_id = 51' ) ? '1' : null;
		};
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	public function test_membership_is_the_literal_answer(): void {
		$this->assertTrue( ZIM_Membership::is_member( 51, 70 ) );
		$this->assertFalse( ZIM_Membership::is_member( 52, 70 ) );
		$this->assertFalse( ZIM_Membership::is_member( 51, 71 ) );
		$this->assertFalse( ZIM_Membership::is_member( 0, 70 ) );
		$this->assertFalse( ZIM_Membership::is_member( 51, 0 ) );
	}

	public function test_core_seeds_one_channel_everyone_reads(): void {
		$spec = ZIM_Channels::default_seed_spec();
		$this->assertCount( 1, $spec );
		$this->assertSame( 'announcements', $spec[0]['slug'] );
		$this->assertSame( 1, $spec[0]['is_announcements'], 'administrators post' );
		$this->assertSame( array(), $spec[0]['roles'], 'everyone with Messages reads (the shared device too, read-only)' );
	}

	public function test_a_business_channel_is_cleaned_and_never_widened(): void {
		$GLOBALS['zdz_test_options']['zim_default_channels'] = array(
			array( 'slug' => '#Crew Room', 'name' => '#crew', 'roles' => array( 'zdz_tech', 'zdz_admin', 'not_a_role' ) ),
			array( 'slug' => 'ghost', 'roles' => array( 'not_a_role' ) ),
			array( 'slug' => 'open', 'roles' => array() ),
			array( 'slug' => 'all-hands', 'everyone' => true, 'description' => "Line one\nline two" ),
			array( 'slug' => 'announcements', 'roles' => array( 'zdz_sales' ) ),
		);
		$spec = ZIM_Channels::default_seed_spec();
		$by   = array_column( $spec, null, 'slug' );
		$this->assertSame( array( 'announcements', 'crew-room', 'all-hands' ), array_column( $spec, 'slug' ) );
		$this->assertSame( array( 'zdz_tech', 'zdz_admin' ), $by['crew-room']['roles'], 'unknown roles dropped' );
		$this->assertSame( array(), $by['announcements']['roles'], 'Core\'s channel stays as it is' );
		$this->assertSame( array(), $by['all-hands']['roles'], 'everyone, because it said so' );
		$this->assertSame( 'Line one line two', $by['all-hands']['description'] );
		$this->assertArrayNotHasKey( 'ghost', $by, 'all roles unknown: refused, never everyone' );
		$this->assertArrayNotHasKey( 'open', $by, 'an empty role list is not "everyone"' );
	}

	public function test_a_filter_cannot_widen_a_channel_either(): void {
		$GLOBALS['zdz_test_filters']['zim_default_channels'] = function ( $s ) {
			$s[] = array( 'slug' => 'team', 'roles' => array( 'made_up' ) );
			return $s;
		};
		$this->assertSame( array( 'announcements' ), array_column( ZIM_Channels::default_seed_spec(), 'slug' ) );
	}

	public function test_the_identity_pack_lists_channels_with_preview_apply_and_revert(): void {
		$files = array( 'org' => array( 'messaging' => array( 'channels' => array(
			array( 'slug' => 'crew', 'name' => '#crew', 'roles' => array( 'zdz_tech' ) ),
			array( 'slug' => 'announcements', 'everyone' => true ),
		) ) ) );
		$this->assertSame( array( 'crew' ), array_column( ZIM_Channels::pack_channels( $files ), 'slug' ), 'Core\'s channel is not the pack\'s' );
		$this->assertNull( ZIM_Channels::pack_channels( array( 'org' => array( 'roles' => array() ) ) ), 'nothing said: nothing changed' );
		$rows = ZIM_Channels::pack_preview( $files );
		$this->assertSame( 'messaging.channels', $rows[0]['path'] );
		$this->assertSame( '#crew', $rows[0]['to'] );
		$kept = ZIM_Channels::pack_snapshot();
		$this->assertNull( $kept );
		ZIM_Channels::pack_apply( $files, 'example' );
		$this->assertSame( array( 'crew' ), array_column( $GLOBALS['zdz_test_options']['zim_default_channels'], 'slug' ) );
		ZIM_Channels::pack_restore( $kept );
		$this->assertArrayNotHasKey( 'zim_default_channels', $GLOBALS['zdz_test_options'] ?? array() );
		$c = ZIM_Channels::pack_consumer( array() );
		$this->assertSame( array( 'org' ), $c['messaging-channels']['files'] );
	}

	public function test_a_media_card_renders_only_inside_its_conversation(): void {
		$this->assertTrue( ZIM_Media_Cards::viewer_in_conversation( 51, 70 ) );
		$this->assertFalse( ZIM_Media_Cards::viewer_in_conversation( 52, 70 ), 'a pasted marker shows nothing' );
		$this->assertSame( '[ZDZ-MEDIA-SHARE-0a1b2c3d4e5f6a7b]', ZIM_Media_Cards::marker( '0A1B2C3D4E5F6A7B' ) );
		$this->assertSame( '', ZIM_Media_Cards::marker( '<>' ) );
	}

	public function test_unread_counts_are_counts_and_never_for_the_shared_device(): void {
		$this->assertTrue( ZIM_Unread_Counts::from_capability( array( 'user_id' => 53 ) )['denied'] );
		$this->assertSame( 'kiosk', ZIM_Unread_Counts::from_capability( array( 'user_id' => 51, 'is_kiosk' => true ) )['reason'] );
		$this->assertSame( 'app', ZIM_Unread_Counts::from_capability( array( 'user_id' => 54 ) )['reason'] );
		$this->assertSame( 'signed_out', ZIM_Unread_Counts::from_capability( array() )['reason'] );
		$caps = ZIM_Unread_Counts::register_capabilities( array() );
		$n    = ZDZ_Capabilities::normalize( ZIM_Unread_Counts::VERB, $caps[ ZIM_Unread_Counts::VERB ] );
		$this->assertFalse( $n['side_effect'] );
		$this->assertFalse( $n['kiosk'] );
		$this->assertSame( 'internal-messaging', $n['app'] );
	}
}
