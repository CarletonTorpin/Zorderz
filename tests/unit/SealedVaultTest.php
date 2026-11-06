<?php
/**
 * The access-code vault (ZDZ_Sealed) and its key page (ZDZ_Sealed_Admin):
 *
 *   - the reader finds and cuts gate, lockbox and alarm codes and leaves sizes, money and times as words;
 *   - a code sealed under a scope opens for an administrator only (never the shared device), and every opening is
 *     written down first;
 *   - a key that is not the one the codes were sealed with LOCKS the vault (nothing sealed, nothing opened), and the
 *     check is never re-stored by itself; only an administrator typing ACCEPT records it again;
 *   - the printed recovery code opens today's codes, read with the look-alikes a person copying it by hand makes;
 *   - Core ships no region words, model words or postcode shape; a business adds them by option or filter.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed-admin.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-salt-watch.php';

final class SealedVaultTest extends TestCase {

	const ADMIN  = 3;
	const KIOSK  = 4;
	const SALES  = 5;

	protected function setUp(): void {
		$GLOBALS['wpdb']                = new ZDZ_Test_Vault_DB();
		$GLOBALS['zdz_test_user_roles'] = array( self::ADMIN => array( 'administrator' ), self::KIOSK => array( 'zdz_general' ), self::SALES => array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_current_user'] = self::ADMIN;
		ZDZ_Sealed::reset_state();
		if ( class_exists( 'ZDZ_Capabilities' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZDZ_Sealed::reset_state();
	}

	private function box_under( $plain, $key ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	/* ── the reader ───────────────────────────────────────────────────────── */

	public function test_reader_cuts_codes_and_leaves_sizes_money_and_times(): void {
		$cut = ZDZ_Sealed::cut( 'Gate code is 4821, lockbox # 5521, alarm: #5871. Screen door #3080, $1,450 paid, meet at the gate 0930.' );
		foreach ( array( '4821', '5521', '5871' ) as $code ) {
			$this->assertStringNotContainsString( $code, $cut );
		}
		$this->assertStringContainsString( '[gate code sealed]', $cut );
		$this->assertStringContainsString( '[lockbox code sealed]', $cut );
		$this->assertStringContainsString( '[alarm code sealed]', $cut );
		foreach ( array( '#3080', '$1,450', '0930' ) as $kept ) {
			$this->assertStringContainsString( $kept, $cut, "'$kept' is not a code" );
		}
		$this->assertSame( 'zip code 12345, building code 2022', ZDZ_Sealed::cut( 'zip code 12345, building code 2022' ) );
		$this->assertFalse( ZDZ_Sealed::might_have_code( 'no numbers here at all' ) );
		$this->assertTrue( ZDZ_Sealed::might_have_code( 'code is four eight two one' ) );
		$this->assertSame( '4821', ZDZ_Sealed::normalize( 'four eight two one' ) );
		$this->assertSame( array(), ZDZ_Sealed::find( 'gate 2 hung, door 3 of 5' ) );
	}

	public function test_region_and_model_words_ship_empty_and_a_business_adds_them(): void {
		// Without the region's lists a number that might be a postcode, a model or a code book's is cut (over-cutting
		// hides a number, never shows a code).
		$this->assertSame( '[gate code sealed] - [gate code sealed]', ZDZ_Sealed::cut( '12345 - gate code 1066' ) );
		$this->assertStringNotContainsString( '1750', ZDZ_Sealed::cut( 'Lockbox on the left, Examplemodel 1750 is 1750' ) );
		$this->assertStringNotContainsString( '3109', ZDZ_Sealed::cut( 'xbc code 3109' ) );

		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::REGION_WORDS_OPT ] = array( 'state', 'xbc' );
		$GLOBALS['zdz_test_filters']['zdz_sealed_model_words']      = function ( $w ) { $w[] = 'examplemodel'; return $w; };
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::POSTCODE_OPT ]     = array( 'digits' => '^1\d{4}$', 'region' => 'ST' );
		ZDZ_Sealed::flush_lists();
		$this->assertSame( 'xbc code 3109', ZDZ_Sealed::cut( 'xbc code 3109' ), "a region's code book is not an access code" );
		$this->assertSame( '12345 - [gate code sealed]', ZDZ_Sealed::cut( '12345 - gate code 1066' ), "the region's postcode is a postcode" );
		$this->assertSame( 'Lockbox on the left, Examplemodel 1750 is 1750', ZDZ_Sealed::cut( 'Lockbox on the left, Examplemodel 1750 is 1750' ), 'a model number is not a code' );
		$this->assertStringContainsString( '[keypad code sealed]', ZDZ_Sealed::cut( 'Keypad by the Examplemodel 1750, 4821 gets you in' ) );

		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::POSTCODE_OPT ] = array( 'digits' => 'bad/pattern(' );
		ZDZ_Sealed::flush_lists();
		$this->assertStringNotContainsString( '1066', ZDZ_Sealed::cut( '12345 - gate code 1066' ), 'a broken pattern is refused, never thrown' );
	}

	/* ── the vault ────────────────────────────────────────────────────────── */

	public function test_seal_and_open_for_an_administrator_only_and_every_opening_written_first(): void {
		$this->assertTrue( ZDZ_Sealed::can_keep() );
		$r = ZDZ_Sealed::cut_ids( 'Gate code is 4821', array( 'job', 12 ) );
		$this->assertSame( 1, $r['kept'] );
		$id = $r['ids'][0];
		$row = $GLOBALS['wpdb']->rows( 'zdz_sealed' )[0];
		$this->assertStringNotContainsString( '4821', $row['sealed'], 'never kept in plain text' );
		$this->assertSame( '4821', ZDZ_Sealed::open( $id, self::ADMIN ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows( 'zdz_sealed_views' ), 'the opening was written down' );

		$this->assertNull( ZDZ_Sealed::open( $id, self::KIOSK ) );
		$this->assertSame( 'kiosk', ZDZ_Sealed::why_not( $id, self::KIOSK ) );
		$this->assertSame( 'not_allowed', ZDZ_Sealed::why_not( $id, self::SALES ) );
		$GLOBALS['zdz_test_filters']['zdz_sealed_may_open'] = function () { return true; };
		$this->assertSame( 'kiosk', ZDZ_Sealed::why_not( $id, self::KIOSK ), 'no app can open a code to the shared device' );
		$this->assertSame( '', ZDZ_Sealed::why_not( $id, self::SALES ), 'an app may allow its own person' );
		$this->assertSame( 'signed_out', ZDZ_Sealed::why_not( $id, 0 ) );
		$this->assertNull( ZDZ_Sealed::scope( array( 'invoice', 3 ) ), 'only the known scopes' );

		$GLOBALS['wpdb']->fail_insert = true;
		$this->assertNull( ZDZ_Sealed::open( $id, self::ADMIN ), 'an opening that cannot be written down shows nothing' );
	}

	public function test_a_changed_key_locks_and_only_accept_records_the_check_again(): void {
		$id = ZDZ_Sealed::seal( 'gate', '4821', array( 'job', 1 ) );
		$this->assertGreaterThan( 0, $id );
		$good = $GLOBALS['zdz_test_options'][ ZDZ_Sealed::CHECK_OPT ];

		// the check was recorded under another key: the site's key changed
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::CHECK_OPT ] = hash_hmac( 'sha256', ZDZ_Sealed::CHECK_LABEL, random_bytes( 32 ) );
		ZDZ_Sealed::reset_state();
		$this->assertTrue( ZDZ_Sealed::locked() );
		$this->assertFalse( ZDZ_Sealed::can_keep() );
		$this->assertTrue( ZDZ_Sealed::ready(), 'codes are still found and cut while locked' );
		$this->assertSame( 0, ZDZ_Sealed::seal( 'gate', '1234', array( 'job', 1 ) ), 'nothing is kept while locked' );
		$this->assertNull( ZDZ_Sealed::open( $id, self::ADMIN ) );
		$this->assertSame( 'locked', ZDZ_Sealed::why_not( $id, self::ADMIN ) );
		$this->assertNotSame( $good, $GLOBALS['zdz_test_options'][ ZDZ_Sealed::CHECK_OPT ], 'the check is never re-stored by itself' );
		$this->assertSame( '', ZDZ_Sealed::recovery_code(), 'no recovery code for a key that is not the codes\' key' );

		$this->assertFalse( ZDZ_Sealed::accept_current_key( self::ADMIN, 'accept' ), 'ACCEPT, typed exactly' );
		$this->assertFalse( ZDZ_Sealed::accept_current_key( self::KIOSK, 'ACCEPT' ), 'never the shared device' );
		$this->assertTrue( ZDZ_Sealed::accept_current_key( self::ADMIN, 'ACCEPT' ) );
		$this->assertTrue( ZDZ_Sealed::can_keep() );
		$this->assertSame( $good, $GLOBALS['zdz_test_options'][ ZDZ_Sealed::CHECK_OPT ], "today's key, unchanged" );
	}

	public function test_first_use_locks_when_the_newest_code_is_not_this_keys(): void {
		$GLOBALS['wpdb']->tables['wp_zdz_sealed'] = array( 1 => array(
			'id' => 1, 'kind' => 'gate', 'sealed' => $this->box_under( '9999', random_bytes( 32 ) ),
			'scope_type' => 'job', 'scope_id' => '1', 'created_by' => 0, 'created_at' => '2026-01-01 00:00:00',
		) );
		ZDZ_Sealed::reset_state();
		$this->assertTrue( ZDZ_Sealed::locked() );
		$this->assertArrayNotHasKey( ZDZ_Sealed::CHECK_OPT, $GLOBALS['zdz_test_options'] ?? array(), 'nothing is recorded for a key that is not theirs' );
	}

	public function test_recovery_code_is_read_with_look_alikes_and_never_stored(): void {
		ZDZ_Sealed::seal( 'gate', '4821', array( 'job', 1 ) );
		$code = ZDZ_Sealed::recovery_code();
		$this->assertSame( 13, count( explode( ' ', $code ) ), 'thirteen groups of four' );
		$this->assertSame( 52, strlen( str_replace( ' ', '', $code ) ) );
		$this->assertTrue( ZDZ_Sealed::code_matches( $code ) );
		$copied = strtolower( strtr( str_replace( ' ', '-', $code ), array( 'O' => '0', 'I' => '1', 'B' => '8' ) ) );
		$this->assertTrue( ZDZ_Sealed::code_matches( $copied ), 'copied by hand: lower case, dashes, 0/1/8 for O/I/B' );
		$bad    = $code;
		$bad[0] = 'A' === $bad[0] ? 'C' : 'A';
		$this->assertFalse( ZDZ_Sealed::code_matches( $bad ) );
		$this->assertFalse( ZDZ_Sealed::code_matches( 'AAAA' ) );
		foreach ( $GLOBALS['zdz_test_options'] as $v ) {
			$this->assertStringNotContainsString( str_replace( ' ', '', $code ), is_scalar( $v ) ? (string) $v : json_encode( $v ), 'the key is never kept' );
		}
		$this->assertSame( array( 8, 1 ), ZDZ_Sealed_Admin::code_reading( "AAAA-BBBB\u{00A0}é" ) );
	}

	public function test_salt_watch_says_still_open_only_when_codes_can_be_opened(): void {
		ZDZ_Sealed::seal( 'gate', '4821', array( 'job', 1 ) );
		$this->assertStringContainsString( 'Gate codes still open', implode( ' ', ZDZ_Salt_Watch::breaks( array( 'secure_auth' ) ) ) );
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::CHECK_OPT ] = hash_hmac( 'sha256', ZDZ_Sealed::CHECK_LABEL, random_bytes( 32 ) );
		ZDZ_Sealed::reset_state();
		$lines = implode( ' ', ZDZ_Salt_Watch::breaks( array( 'secure_auth' ) ) );
		$this->assertStringContainsString( 'Gate codes are locked', $lines );
		$this->assertStringContainsString( 'ZDZ_SEALED_KEY', $lines );
	}
}
