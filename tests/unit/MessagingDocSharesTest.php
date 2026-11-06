<?php
/**
 * Messaging: an estimate or invoice named in a message (ZIM_Doc_Shares) - who may see its card.
 *
 *   - The viewer's own right (read through the Estimates app's reader) opens it; nothing else does, except a live
 *     share from the message's sender WHILE the sender may still see the document.
 *   - A share answers for its own kind only: an invoice share never opens the estimate with the same number.
 *   - The shared device never opens a document; without a reader nothing opens and nothing is shared.
 *   - "invoice #N" is the invoice, any other #N the estimate, read from the message's own words.
 *   - Shares go only to the other people in the conversation (never the author, never the shared device), only for
 *     numbers that are in the message; the share status is for the author alone.
 *   - Preview cards never carry a link to the billing system and never match an HTML entity.
 *
 * Neutral data only: numbers like #1234, people Alex (sender), Sam (recipient), Quinn (stranger).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';

final class MessagingDocSharesTest extends TestCase {

	const ALEX  = 41;   // sender, the estimate's own salesperson
	const SAM   = 42;   // in the conversation
	const QUINN = 43;   // a stranger to the document
	const DEV   = 44;   // the shared device, in the conversation

	/** @var array who may read what: uid => [ "kind|number" => true ] */
	private $rights = array();

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( self::ALEX => 'staff', self::SAM => 'staff', self::QUINN => 'staff', self::DEV => 'shared' ) );
		$this->rights = array( self::ALEX => array( 'estimate|1234' => true, 'invoice|1234' => true ) );
		$rights = &$this->rights;
		ZIM_Doc_Shares::$reader = function ( $number, $args ) use ( &$rights ) {
			$kind = '' !== $args['kind'] ? $args['kind'] : 'estimate';
			if ( empty( $rights[ (int) $args['as_user'] ][ $kind . '|' . $number ] ) ) {
				return array( 'ok' => false, 'why' => 'not_yours' );
			}
			// Amounts only when the person looking may see prices: here, the reader reports whose rule it used.
			return array( 'ok' => true, 'kind' => $kind, 'doc' => array( 'number' => $number, 'kind' => $kind, 'price_user' => (int) $args['price_user'] ) );
		};
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	/** A live share row for Sam from Alex in message 900, conversation 70. */
	private function shares( array $rows ): void {
		$GLOBALS['wpdb']->on_results = function ( $sql ) use ( $rows ) {
			if ( false === strpos( $sql, 'zim_doc_grants g' ) ) {
				return array();
			}
			return array_values( array_filter( $rows, function ( $r ) use ( $sql ) {
				return false !== strpos( $sql, 'g.grantee_user_id = ' . (int) $r['grantee'] ) && false !== strpos( $sql, "'" . $r['doc_kind'] . "'" );
			} ) );
		};
	}

	private static function grant( string $kind, int $by = self::ALEX ): array {
		return array( 'id' => 1, 'doc_kind' => $kind, 'granted_by' => $by, 'conversation_id' => 70, 'message_id' => 900, 'expires_at' => '2099-01-01 00:00:00', 'grantee' => self::SAM );
	}

	public function test_the_viewers_own_right_opens_it(): void {
		$r = ZIM_Doc_Shares::preview_for( self::ALEX, '1234', 0, 'estimate' );
		$this->assertSame( 'ok', $r['state'] );
		$this->assertSame( 'own', $r['via'] );
		$this->assertSame( '', $r['also'], 'never a link to the other series' );
	}

	public function test_nobody_else_without_a_share(): void {
		$r = ZIM_Doc_Shares::preview_for( self::QUINN, '1234', 0, 'estimate' );
		$this->assertSame( 'denied', $r['state'] );
		$this->assertSame( 'not_yours', $r['why'] );
		$this->assertArrayNotHasKey( 'doc', $r, 'no document, not a word of it' );
	}

	public function test_a_live_share_opens_it_read_as_the_sender_with_the_viewers_price_right(): void {
		$this->shares( array( self::grant( 'estimate' ) ) );
		$r = ZIM_Doc_Shares::preview_for( self::SAM, '1234', 0, 'estimate' );
		$this->assertSame( 'ok', $r['state'] );
		$this->assertSame( 'shared', $r['via'] );
		$this->assertSame( self::SAM, $r['doc']['price_user'], 'amounts follow the person looking' );
		$this->assertNotSame( '', $r['until'] );
	}

	public function test_a_share_from_someone_who_lost_the_document_gives_nothing(): void {
		$this->shares( array( self::grant( 'estimate' ) ) );
		$this->rights[ self::ALEX ] = array();
		$r = ZIM_Doc_Shares::preview_for( self::SAM, '1234', 0, 'estimate' );
		$this->assertSame( 'denied', $r['state'] );
	}

	public function test_a_share_answers_for_its_own_kind_only(): void {
		$this->shares( array( self::grant( 'invoice' ) ) );
		$this->assertSame( 'ok', ZIM_Doc_Shares::preview_for( self::SAM, '1234', 0, 'invoice' )['state'] );
		$this->assertSame( 'denied', ZIM_Doc_Shares::preview_for( self::SAM, '1234', 0, 'estimate' )['state'], 'the estimate is another document' );
	}

	public function test_never_on_the_shared_device_and_nothing_without_a_reader(): void {
		$this->assertSame( 'denied', ZIM_Doc_Shares::preview_for( self::DEV, '1234' )['state'] );
		ZIM_Doc_Shares::$reader = null;
		$this->assertFalse( ZIM_Doc_Shares::provider_present() );
		$this->assertSame( 'unavailable', ZIM_Doc_Shares::preview_for( self::ALEX, '1234' )['state'] );
		$this->assertSame( 0, ZIM_Doc_Shares::grant_for_message( 900, 70, self::ALEX, 'see #1234', array( '1234' ) ), 'no share that could come alive later' );
		$this->assertSame( 'not_found', ZIM_Doc_Shares::preview_for( self::ALEX, 'abc' )['state'] );
	}

	public function test_invoice_or_estimate_from_the_messages_own_words(): void {
		$cases = array(
			'invoice #1234'                              => array( 'invoice' ),
			'Invoice: #1234'                             => array( 'invoice' ),
			'inv. #1234'                                 => array( 'invoice' ),
			'**Invoice** #1234'                          => array( 'invoice' ),
			'invoice&nbsp;#1234'                         => array( 'invoice' ),
			'#1234'                                      => array( 'estimate' ),
			'estimate #1234'                             => array( 'estimate' ),
			'Sent the invoice. #1234 is the estimate'    => array( 'estimate' ),
			"invoice<br>#1234"                           => array( 'estimate' ),
			'preinvoice #1234'                           => array( 'estimate' ),
			'estimate #1234 and invoice #1234'           => array( 'estimate', 'invoice' ),
		);
		foreach ( $cases as $body => $want ) {
			$this->assertSame( $want, ZIM_Doc_Shares::kinds_for_ref( $body, '1234' ), $body );
		}
		$this->assertSame( array(), ZIM_Doc_Shares::kinds_for_ref( 'number 1234', '1234' ), 'cannot be placed: no kind, nothing shared' );
	}

	public function test_shares_go_to_the_other_people_for_numbers_in_the_message(): void {
		$GLOBALS['wpdb']->on_col = function ( $sql ) {
			return false !== strpos( $sql, 'zim_members' ) ? array( (string) self::ALEX, (string) self::SAM, (string) self::DEV ) : array();
		};
		$made = ZIM_Doc_Shares::grant_for_message( 900, 70, self::ALEX, 'Please look at invoice #1234', array( '1234', '9999' ) );
		$this->assertSame( 1, $made, 'Sam only: never the author, never the shared device, never a number not in the message' );
		$row = $GLOBALS['wpdb']->inserted[0][1];
		$this->assertSame( self::SAM, $row['grantee_user_id'] );
		$this->assertSame( 'invoice', $row['doc_kind'] );
		$this->assertSame( '1234', $row['doc_number'] );
		$this->assertSame( 0, ZIM_Doc_Shares::grant_for_message( 901, 70, self::DEV, '#1234', array( '1234' ) ), 'the shared device shares nothing' );
	}

	public function test_the_share_status_is_for_the_author_alone(): void {
		$GLOBALS['wpdb']->on_row = function ( $sql ) {
			return array( 'id' => 900, 'conversation_id' => 70, 'author_user_id' => self::ALEX, 'body' => 'invoice #1234', 'deleted_at' => null );
		};
		$this->assertNull( ZIM_Doc_Shares::status_for_message( 900, self::SAM ) );
		$s = ZIM_Doc_Shares::status_for_message( 900, self::ALEX );
		$this->assertSame( 900, $s['message_id'] );
		$this->assertSame( array( '1234' ), $s['pending'], 'not shared yet: offered' );
		$this->assertSame( 'zim_not_author', ZIM_Doc_Shares::share_message( 900, self::SAM )->get_error_code(), 'only its author shares it' );
		$this->assertSame( 'zim_not_author', ZIM_Doc_Shares::withdraw( 900, self::SAM )->get_error_code(), 'or withdraws it' );
	}

	public function test_preview_cards_never_link_out_and_never_read_an_entity(): void {
		$this->assertSame( array( 1234 ), ZIM_Preview_Cards::extract_refs( 'see #1234 and &#105; and #12' ) );
		$this->assertSame( array(), ZIM_Preview_Cards::extract_refs( 'a&#1234;b' ) );
		$card = ZIM_Preview_Cards::get_card( 1234 );   // no preview proxy: the plain card
		$this->assertSame( '', $card['url'] );
		$this->assertSame( 'fallback', $card['source'] );
	}
}
