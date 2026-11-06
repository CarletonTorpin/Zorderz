<?php
/**
 * Messaging: replying to a message e-mail (ZIM_Email_Reply) - how a reply finds its conversation, and who may post.
 *
 *   - The routing token is signed (HMAC-SHA256 under the site's own secret) and checked in constant time: a token
 *     edited by one character, made under another secret, or of another shape is refused.
 *   - The token is found in a plus-tagged recipient address (its case kept) or in the in-body marker.
 *   - Only the person the e-mail went to may reply into the conversation: a forward is never posted as them.
 *   - The reply posted is the person's new words: quoted history, signatures and the marker are taken out.
 *   - The reply address is built from the configured mailbox; nothing is minted without one.
 *
 * Neutral data only (example.com addresses).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';

final class MessagingEmailReplyTest extends TestCase {

	protected function setUp(): void {
		zdz_test_msg_reset();
		$GLOBALS['zdz_test_options']['zim_email_reply_secret'] = str_repeat( 'a1', 32 );
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	private static function graph( array $to, string $body, string $from = 'sam@example.com', string $type = 'text' ): array {
		return array(
			'toRecipients' => array_map( function ( $a ) { return array( 'emailAddress' => array( 'address' => $a ) ); }, $to ),
			'from'         => array( 'emailAddress' => array( 'address' => $from ) ),
			'body'         => array( 'contentType' => $type, 'content' => $body ),
		);
	}

	public function test_a_token_routes_to_exactly_what_it_was_minted_for(): void {
		$t = ZIM_Email_Reply::mint( 70, 42, 900 );
		$this->assertSame( array( 'conversation_id' => 70, 'recipient_user_id' => 42, 'message_id' => 900 ), ZIM_Email_Reply::verify( $t ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $t, 'safe in an address' );
	}

	public function test_an_edited_or_foreign_token_is_refused(): void {
		$t = ZIM_Email_Reply::mint( 70, 42, 900 );
		list( $p, $s ) = explode( '.', $t, 2 );
		$forged = rtrim( strtr( base64_encode( 'v1:70:43:900' ), '+/', '-_' ), '=' ) . '.' . $s;
		$this->assertFalse( ZIM_Email_Reply::verify( $forged ), 'another recipient under the same signature' );
		$this->assertFalse( ZIM_Email_Reply::verify( $p . '.' . substr( $s, 0, -1 ) . ( 'A' === substr( $s, -1 ) ? 'B' : 'A' ) ), 'one character changed' );
		$this->assertFalse( ZIM_Email_Reply::verify( $p ), 'no signature' );
		$this->assertFalse( ZIM_Email_Reply::verify( '' ) );
		$GLOBALS['zdz_test_options']['zim_email_reply_secret'] = str_repeat( 'b2', 32 );
		$this->assertFalse( ZIM_Email_Reply::verify( $t ), 'another site\'s secret' );
		$v2 = rtrim( strtr( base64_encode( 'v2:70:42:900' ), '+/', '-_' ), '=' );
		$sig = rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'v2:70:42:900', str_repeat( 'b2', 32 ), true ) ), '+/', '-_' ), '=' );
		$this->assertFalse( ZIM_Email_Reply::verify( $v2 . '.' . $sig ), 'a well-signed token of another shape' );
	}

	public function test_the_token_is_found_in_the_address_or_the_body(): void {
		$t = ZIM_Email_Reply::mint( 70, 42, 900 );
		$m = self::graph( array( 'app+DM-' . $t . '@example.com' ), 'Thanks' );
		$this->assertSame( $t, ZIM_Email_Reply::extract_token_from_recipients( $m ), 'tag matched in any case, token case kept' );
		$this->assertTrue( ZIM_Email_Reply::message_is_dm_reply( $m ) );
		$b = self::graph( array( 'app@example.com' ), '<p>Thanks</p><span>' . ZIM_Email_Reply::body_marker( $t ) . '</span>', 'sam@example.com', 'html' );
		$this->assertSame( $t, ZIM_Email_Reply::extract_token_from_body( $b['body']['content'] ) );
		$this->assertTrue( ZIM_Email_Reply::message_is_dm_reply( $b ) );
		$this->assertFalse( ZIM_Email_Reply::message_is_dm_reply( self::graph( array( 'app@example.com' ), 'An invoice is attached.' ) ), 'ordinary mail is left for the vault' );
		$this->assertSame( '', ZIM_Email_Reply::extract_token_from_recipients( self::graph( array( 'app+dm-<script>@example.com' ), '' ) ) );
	}

	public function test_only_the_person_it_went_to_may_reply(): void {
		$this->assertTrue( ZIM_Email_Reply::sender_is_recipient( 'Sam@Example.com', 'sam@example.com' ) );
		$this->assertFalse( ZIM_Email_Reply::sender_is_recipient( 'quinn@example.com', 'sam@example.com' ), 'a forward' );
		$this->assertFalse( ZIM_Email_Reply::sender_is_recipient( '', 'sam@example.com' ) );
		$this->assertFalse( ZIM_Email_Reply::sender_is_recipient( 'sam@example.com', '' ) );
		$r = ZIM_Email_Reply::handle_graph_message( self::graph( array( 'app@example.com' ), 'no token here' ) );
		$this->assertFalse( $r['ok'] );
		$r = ZIM_Email_Reply::handle_graph_message( self::graph( array( 'app+dm-AAAA.BBBB@example.com' ), 'forged' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'token failed verification', $r['reason'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted, 'nothing posted' );
	}

	public function test_the_reply_is_the_persons_new_words(): void {
		$t    = ZIM_Email_Reply::mint( 70, 42, 900 );
		$text = "Sounds good, see you at 9.\n\nOn Tue, Morgan wrote:\n> Can you come by?\n" . ZIM_Email_Reply::body_marker( $t );
		$this->assertSame( 'Sounds good, see you at 9.', ZIM_Email_Reply::extract_reply_text( $text, 'text' ) );
		$this->assertSame( 'Yes.', ZIM_Email_Reply::extract_reply_text( "Yes.\n--\nSam\n555-0100", 'text' ), 'signature taken off' );
		$html = '<div>On my way</div><blockquote>Earlier message</blockquote>';
		$this->assertSame( 'On my way', ZIM_Email_Reply::extract_reply_text( $html, 'html' ) );
	}

	public function test_the_reply_address_needs_a_configured_mailbox(): void {
		$this->assertSame( '', ZIM_Email_Reply::reply_address_for_token( 'abc.def' ), 'nothing to mint from' );
		$this->assertFalse( ZIM_Email_Reply::outbound_ready() );
		$GLOBALS['zdz_test_options']['zim_email_reply_config'] = array( 'mailbox_base' => 'app', 'mail_domain' => 'example.com' );
		$this->assertSame( 'app+dm-abc.def@example.com', ZIM_Email_Reply::reply_address_for_token( 'abc.def' ) );
		$this->assertTrue( ZIM_Email_Reply::outbound_ready() );
	}
}
