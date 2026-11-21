<?php
/**
 * Messaging: `zim_message_posted` is said once a new message is stored (its id, its conversation, its author), so
 * another app can act on it.
 *
 *   - It is said in ZIM_Messages::post() only (a new message, never an edit or a delete), after the row is stored and
 *     before the call returns.
 *   - A listener's own trouble is caught: the send stands.
 *   - The checks for an author in Privacy View, and for a read-only account, still come first: a refused message says
 *     nothing.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/messaging/includes/class-zim-mentions.php';

final class MessagingMessagePostedTest extends TestCase {

	const ALEX   = 31;   // an administrator
	const JORDAN = 32;   // a staff member
	const SHARED = 33;   // the shared device

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( self::ALEX => 'admin', self::JORDAN => 'staff', self::SHARED => 'shared' ) );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['wpdb']->on_row          = function ( $sql ) {
			return false !== strpos( $sql, 'zim_conversations WHERE id = 55' ) ? (object) array( 'kind' => 'dm', 'is_announcements' => 0 ) : null;
		};
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/messaging/includes/class-zim-messages.php' );
	}

	/** The body of one method of the class, from its signature to the next method's docblock. */
	private function method_body( string $name ): string {
		$src   = $this->source();
		$start = strpos( $src, 'public static function ' . $name . '(' );
		$this->assertTrue( false !== $start, $name . '() exists' );
		$next = strpos( $src, "\n\t/**", (int) $start );
		return substr( $src, (int) $start, false === $next ? null : $next - (int) $start );
	}

	public function test_it_is_said_once_and_only_for_a_new_message(): void {
		$said = "do_action( 'zim_message_posted', \$message_id, \$conversation_id, \$author_user_id );";
		$this->assertSame( 1, substr_count( $this->source(), "do_action( 'zim_message_posted'" ), 'said in one place' );
		$this->assertStringContainsString( $said, $this->method_body( 'post' ), 'with the message, its conversation and its author' );
		$this->assertStringNotContainsString( 'zim_message_posted', $this->method_body( 'edit' ) );
		$this->assertStringNotContainsString( 'zim_message_posted', $this->method_body( 'soft_delete' ) );
	}

	public function test_it_is_said_after_the_message_is_stored_and_before_the_call_returns(): void {
		$post   = $this->method_body( 'post' );
		$stored = strpos( $post, '$message_id = (int) $wpdb->insert_id;' );
		$said   = strpos( $post, "do_action( 'zim_message_posted'" );
		$return = strpos( $post, "'message_id'         => \$message_id," );
		$this->assertTrue( false !== $stored );
		$this->assertTrue( false !== $said );
		$this->assertTrue( false !== $return );
		$this->assertLessThan( $said, $stored, 'the row exists first' );
		$this->assertLessThan( $return, $said, 'and the caller hears of it afterwards' );
		// Everything else a send does (read marker, mentions, notifications) is already done when a listener runs.
		foreach ( array( 'ZIM_Channels::mark_read(', 'ZIM_Mentions::reconcile(', 'ZIM_Notifications::maybe_queue_first_unread(' ) as $step ) {
			$this->assertLessThan( $said, strpos( $post, $step ), $step );
		}
	}

	public function test_a_listeners_trouble_is_caught(): void {
		$post = $this->method_body( 'post' );
		$said = (int) strpos( $post, "do_action( 'zim_message_posted'" );
		$near = substr( $post, max( 0, $said - 120 ), 320 );
		$this->assertStringContainsString( 'try {', substr( $near, 0, 120 ), 'said inside a try' );
		$this->assertStringContainsString( '} catch ( \Throwable $e ) {', $near, 'anything thrown is caught' );
	}

	public function test_the_checks_that_refuse_a_message_come_first(): void {
		$post = $this->method_body( 'post' );
		$said = strpos( $post, "do_action( 'zim_message_posted'" );
		foreach ( array(
			'ZIM_Privacy_View::on( $author_user_id )',
			'ZIM_Privacy_View::conversation_ok( $conversation_id, $author_user_id )',
			'zim_user_can_write( $author_user_id )',
			"'zim_empty_message'",
			"'zim_insert_failed'",
		) as $check ) {
			$at = strpos( $post, $check );
			$this->assertTrue( false !== $at, $check );
			$this->assertLessThan( $said, $at, $check . ' is decided before anyone is told' );
		}
	}

	public function test_a_send_still_stores_the_message_and_returns_its_id(): void {
		$r = ZIM_Messages::post( 55, self::ALEX, 'The gate code changed; ask the office for it.' );
		$this->assertIsArray( $r );
		$this->assertSame( 1, $r['message_id'] );
		$this->assertSame( array(), $r['mentioned_user_ids'] );
		$row = $GLOBALS['wpdb']->inserted[0];
		$this->assertSame( 'wp_zim_messages', $row[0] );
		$this->assertSame( 55, $row[1]['conversation_id'] );
		$this->assertSame( self::ALEX, $row[1]['author_user_id'] );
	}

	public function test_a_refused_message_stores_nothing(): void {
		$r = ZIM_Messages::post( 55, self::SHARED, 'hello' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'zim_read_only', $r->get_error_code() );
		$r = ZIM_Messages::post( 55, self::ALEX, '   ' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'zim_empty_message', $r->get_error_code() );
		$r = ZIM_Messages::post( 56, self::ALEX, 'hello' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'zim_no_conversation', $r->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted );
	}
}
