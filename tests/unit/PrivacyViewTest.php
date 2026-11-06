<?php
/**
 * ZDZ_Privacy_View: the per-account mode in which customer details and team names never reach the browser.
 *
 *   - Deny by default: only listed AJAX actions, REST routes, screens and front-end keys pass the gate; an unknown one
 *     is refused, and the messaging actions stay shut until a partner is chosen and the messaging app narrows them.
 *   - By field: a name, e-mail, phone, street or document field is a pseudonym whatever it holds; a secret is sealed;
 *     ids, states and links keep their shape.
 *   - The name rule: an unlisted capitalised word is a name (masked), the safe vocabulary is kept, and a business's own
 *     words come from its pack or the zdz_privacy_view_words filter.
 *   - Pseudonyms carry a check character, change with every reply, and only the administrator's look-up reads them.
 *   - The Identity Pack's knowledge.yml `privacy_view` block previews, applies and reverts.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-plugin-api.php';

/** A stand-in for the coder: knows two customer names, codes them as ⟦c:id⟧. */
final class ZDZ_Test_Pv_Coder {
	public $names = array( 1 => 'Alex Quinn', 2 => 'Example Hardware' );
	private function sub( $t ) {
		foreach ( $this->names as $id => $n ) {
			$t = preg_replace( '/(?<![\p{L}])' . preg_quote( $n, '/' ) . '(?![\p{L}])/iu', '⟦c:' . $id . '⟧', $t );
		}
		return $t;
	}
	public function encode( $t ) { return $this->sub( (string) $t ); }
	public function names_only( $t ) { return $this->sub( (string) $t ); }
	public function open( $c ) { return null; }
	public function ledger_name( $id ) { return $this->names[ (int) $id ] ?? null; }
	public function index() {
		$l = array();
		foreach ( $this->names as $id => $n ) {
			$l[ ZDZ_Privacy_View::norm( $n ) ] = $id;
		}
		return array( 'ledger' => $l, 'other' => array() );
	}
}

/** A coder that fails, as a broken install would. */
final class ZDZ_Test_Pv_Broken_Coder {
	public function encode( $t ) { throw new RuntimeException( 'broken' ); }
	public function names_only( $t ) { throw new RuntimeException( 'broken' ); }
	public function open( $c ) { return null; }
	public function index() { return array(); }
}

final class PrivacyViewTest extends TestCase {

	const TAG_CODE = '/^[BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{3}·[0-9A-Z]{4}[0-9A-Z_]$/u';

	/** A pattern for "starts with this text". */
	private static function starts( $text ) {
		return '/^' . preg_quote( $text, '/' ) . '/u';
	}

	private static function coder( $c ) {
		$r = new ReflectionProperty( 'ZDZ_Privacy_View', 'coder' );
		$r->setAccessible( true );
		$r->setValue( null, $c );
	}

	protected function setUp(): void {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			ZDZ_Business_Profile::flush();
		}
		ZDZ_Privacy_View::flush();
		self::coder( new ZDZ_Test_Pv_Coder() );
		ZDZ_Privacy_View::new_view( 'Bcdf' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'],
			$GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_user_roles'] );
		$_GET = array();
		$_POST = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );
		ZDZ_Privacy_View::flush();
	}

	/* ── who it applies to ───────────────────────────────────────────────── */

	public function test_only_the_flag_value_one_turns_it_on(): void {
		$GLOBALS['zdz_test_user_meta'] = array( 5 => array( 'zdz_privacy_view' => '1' ), 6 => array( 'zdz_privacy_view' => 'yes' ) );
		$this->assertTrue( ZDZ_Privacy_View::is_on( 5 ) );
		$this->assertFalse( ZDZ_Privacy_View::is_on( 6 ), 'only the stored value 1 counts' );
		$this->assertFalse( ZDZ_Privacy_View::is_on( 7 ), 'no flag: off' );
		$this->assertFalse( ZDZ_Privacy_View::is_on( 0 ), 'nobody signed in: off' );
		$GLOBALS['zdz_test_current_user'] = 5;
		$this->assertTrue( ZDZ_Privacy_View::is_on(), 'the signed-in user by default' );
	}

	public function test_app_list_is_narrowed_for_the_account_even_with_admin_rights(): void {
		$GLOBALS['zdz_test_user_meta'] = array( 9 => array( 'zdz_privacy_view' => '1' ) );
		$GLOBALS['zdz_test_user_caps']  = array( 9 => array( 'manage_options' ), 4 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_roles'] = array( 9 => array( 'administrator' ), 4 => array( 'administrator' ) );
		$this->assertTrue( ZDZ_Plugin_API::user_can_access_app( 9, 'sales-analytics' ) );
		$this->assertFalse( ZDZ_Plugin_API::user_can_access_app( 9, 'invoice' ), 'Privacy View wins over administrator rights' );
		$this->assertTrue( ZDZ_Plugin_API::user_can_access_app( 4, 'invoice' ), 'an administrator without it is unaffected' );
	}

	/* ── 1. deny by default ──────────────────────────────────────────────── */

	public function test_only_listed_ajax_actions_pass(): void {
		$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'zana_send_message' ) );
		$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'ZANA_SEND_MESSAGE' ), 'names compare in lower case' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zana_delete_session' ), 'not listed: refused' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zana_search_sessions' ), 'search over stored text is never listed' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( '' ) );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) { $list[] = 'example_read'; return $list; };
		$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'example_read' ), 'an app adds its own action once it masks its output' );
	}

	public function test_message_actions_stay_shut_without_a_partner_and_a_narrowing_app(): void {
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ), 'nobody chosen' );
		$GLOBALS['zdz_test_options']['zdz_privacy_view_dm_to'] = 4;
		$GLOBALS['zdz_test_user_caps']                          = array( 4 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_roles']                         = array( 4 => array( 'administrator' ) );
		$this->assertSame( 4, ZDZ_Privacy_View::dm_partner() );
		if ( class_exists( 'ZIM_Privacy_View' ) ) {
			// The messaging app's narrowing class is loaded in this process (its own tests load it, and a class cannot
			// be unloaded): the partner AND the narrowing open the actions; MessagingPrivacyViewTest pins the rest.
			$this->assertTrue( ZDZ_Privacy_View::dm_narrowed() );
			$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ) );
			return;
		}
		$this->assertFalse( ZDZ_Privacy_View::dm_narrowed(), 'no messaging app that narrows to one thread' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ), 'a partner alone is not enough' );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/', array( 'zim_page', 'zdz_embed', 'zim_compact', 'c' ) ) );
	}

	public function test_partner_must_be_a_real_administrator_and_not_in_privacy_view(): void {
		$GLOBALS['zdz_test_user_caps']  = array( 4 => array( 'manage_options' ), 5 => array(), 6 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_roles'] = array( 4 => array( 'administrator' ), 5 => array( 'zdz_sales' ), 6 => array( 'administrator' ) );
		$GLOBALS['zdz_test_user_meta'] = array( 6 => array( 'zdz_privacy_view' => '1' ) );
		$this->assertTrue( ZDZ_Privacy_View::dm_partner_ok( 4 ) );
		$this->assertFalse( ZDZ_Privacy_View::dm_partner_ok( 5 ), 'not an administrator' );
		$this->assertFalse( ZDZ_Privacy_View::dm_partner_ok( 6 ), 'another Privacy View account' );
		$this->assertFalse( ZDZ_Privacy_View::dm_partner_ok( 0 ) );
		$GLOBALS['zdz_test_options']['zdz_privacy_view_dm_to'] = 5;
		$this->assertSame( 0, ZDZ_Privacy_View::dm_partner(), 'checked every time it is read' );
	}

	public function test_only_listed_rest_reads_pass(): void {
		$this->assertTrue( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/consent', 'GET' ) );
		$this->assertTrue( ZDZ_Privacy_View::rest_allowed( 'zorderz/v1/notifications/unread-count/', 'HEAD' ) );
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/consent', 'POST' ), 'reads only' );
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/capabilities', 'GET' ), 'not listed' );
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/wp/v2/users', 'GET' ) );
	}

	public function test_only_listed_screens_with_their_own_keys_pass(): void {
		$this->assertTrue( ZDZ_Privacy_View::screen_allowed( 'profile.php' ) );
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'profile.php', '', array( 'zdz_view_as' ) ), 'an unexpected key refuses the screen' );
		$this->assertTrue( ZDZ_Privacy_View::screen_allowed( 'admin.php', 'zdz-llm-usage', array( 'page' ) ) );
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'admin.php', 'zdz-core-settings', array( 'page' ) ) );
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'users.php' ) );
	}

	public function test_front_end_is_the_home_address_with_listed_keys_only(): void {
		$this->assertTrue( ZDZ_Privacy_View::front_allowed( '/', array() ) );
		$this->assertTrue( ZDZ_Privacy_View::front_allowed( '/', array( 'cb' ) ) );
		$this->assertTrue( ZDZ_Privacy_View::front_allowed( '/', array( 'zdz_media', 't', 's' ) ), 'a photo (placeholder)' );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/', array( 'zdz_media', 'download' ) ) );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/', array( 'zdz_view_as' ) ), 'View As cannot be switched on' );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/jobs', array() ), 'any other page' );
		$this->assertTrue( ZDZ_Privacy_View::front_allowed( '/site/', array(), '/site' ), 'a site in a folder' );
	}

	public function test_verdict_refuses_odd_paths_overrides_and_writes(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/?cb=3';
		$_GET                      = array( 'cb' => '3' );
		$this->assertSame( '', ZDZ_Privacy_View::verdict() );
		$_SERVER['REQUEST_URI'] = '//jobs';
		$_GET                   = array();
		$this->assertMatchesRegularExpression( self::starts( 'path:' ), ZDZ_Privacy_View::verdict() );
		$_SERVER['REQUEST_URI'] = '/%2e%2e/wp-admin';
		$this->assertMatchesRegularExpression( self::starts( 'path:' ), ZDZ_Privacy_View::verdict() );
		$_SERVER['REQUEST_URI'] = '/';
		$_GET                   = array( '_method' => 'DELETE' );
		$this->assertSame( 'override', ZDZ_Privacy_View::verdict(), 'a method override is refused, never read' );
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( 'front:POST', ZDZ_Privacy_View::verdict() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array( 'x' => '1' );
		$this->assertSame( 'front:GET', ZDZ_Privacy_View::verdict(), 'nothing may arrive in a body' );
	}

	/* ── pseudonyms ─────────────────────────────────────────────────────── */

	public function test_codes_carry_a_check_character_that_catches_a_slip(): void {
		$c = ZDZ_Privacy_View::code( 'customer', 'alex quinn' );
		$this->assertTrue( ZDZ_Privacy_View::well_formed( $c ) );
		$abc = ZDZ_Privacy_View::ABC;
		for ( $i = 0; $i < 4; $i++ ) {
			$slip      = $c;
			$slip[ $i ] = $abc[ ( strpos( $abc, $c[ $i ] ) + 1 ) % 36 ];
			$this->assertFalse( ZDZ_Privacy_View::well_formed( $slip ), 'a one-character slip never reads as a code' );
		}
		if ( $c[0] !== $c[1] ) {
			$swap = $c[1] . $c[0] . substr( $c, 2 );
			$this->assertFalse( ZDZ_Privacy_View::well_formed( $swap ), 'nor a neighbour swap' );
		}
	}

	public function test_one_code_per_thing_within_a_reply_and_new_codes_in_the_next(): void {
		$a = ZDZ_Privacy_View::customer( 'Alex Quinn' );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $a );
		$this->assertSame( $a, ZDZ_Privacy_View::customer( 'alex  QUINN' ), 'one person, one pseudonym, in one reply' );
		$this->assertNotSame( $a, ZDZ_Privacy_View::customer( 'Jordan Example' ) );
		ZDZ_Privacy_View::new_view( 'Hjkl' );
		$this->assertMatchesRegularExpression( self::starts( 'Hjkl·' ), ZDZ_Privacy_View::customer( 'Alex Quinn' ) );
		$this->assertTrue( ZDZ_Privacy_View::is_pseudonym( $a ) );
		$this->assertFalse( ZDZ_Privacy_View::is_pseudonym( 'Alex Quinn' ) );
	}

	/* ── 2. by field ────────────────────────────────────────────────────── */

	public function test_fields_are_masked_by_their_key_whatever_they_hold(): void {
		$out = json_decode( ZDZ_Privacy_View::mask_json_text( zdz_test_pv_json( array(
			'customer'       => 'Somebody Nobody Listed',
			'clientEmail'    => 'alex@example.com',
			'phone'          => '555-010-0123',
			'site_address'   => '12 Example Rd',
			'invoice_number' => '4521',
			'gate_code'      => '#1234',
			'id'             => 42,
			'status'         => 'open',
			'url'            => 'https://example.com/projects',
			'color'          => '#1E3A5F',
			'timezone'       => 'America/Los_Angeles',
			'amount'         => 1250,
		) ) ), true );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $out['customer'], 'a name field: always a pseudonym, known or not' );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $out['clientEmail'], 'camelCase keys read as snake_case' );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $out['phone'] );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $out['site_address'] );
		$this->assertMatchesRegularExpression( self::TAG_CODE, $out['invoice_number'] );
		$this->assertSame( '[gate code sealed]', $out['gate_code'], 'a secret is never shown' );
		$this->assertSame( 42, $out['id'] );
		$this->assertSame( 'open', $out['status'] );
		$this->assertSame( 'https://example.com/projects', $out['url'] );
		$this->assertSame( '#1E3A5F', $out['color'] );
		$this->assertSame( 'America/Los_Angeles', $out['timezone'] );
		$this->assertSame( 1250, $out['amount'], 'an amount stays a number' );
	}

	public function test_an_unknown_key_gets_the_full_pass(): void {
		$out = json_decode( ZDZ_Privacy_View::mask_json_text( '{"anything_at_all":"Spoke with Alex Quinn and Riley Morgan"}' ), true );
		$this->assertStringNotContainsString( 'Alex', $out['anything_at_all'] );
		$this->assertStringNotContainsString( 'Riley', $out['anything_at_all'] );
		$this->assertStringContainsString( 'Spoke with', $out['anything_at_all'] );
	}

	public function test_not_json_is_refused_not_guessed(): void {
		$this->assertNull( ZDZ_Privacy_View::mask_json_text( '{"customer": "Alex' ) );
		$r = ZDZ_Privacy_View::mask_response( '{"customer": "Alex', 'application/json' );
		$this->assertStringNotContainsString( 'Alex', $r );
		$this->assertStringContainsString( '"success":false', $r );
		$this->assertStringNotContainsString( '%PDF', ZDZ_Privacy_View::mask_response( '%PDF-1.7 Alex Quinn', 'application/pdf' ), 'a download is refused' );
	}

	public function test_a_coder_that_fails_refuses_the_response(): void {
		self::coder( new ZDZ_Test_Pv_Broken_Coder() );
		$out = ZDZ_Privacy_View::filter_output( '<html><body>Alex Quinn</body></html>' );
		$this->assertStringNotContainsString( 'Alex', $out, 'never sent unmasked' );
		$this->assertStringContainsString( 'Privacy View', $out );
	}

	/**
	 * Run in its own process, where no other test file has loaded the coder (a runner that cannot do that skips it).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_the_coder_everything_is_refused(): void {
		if ( class_exists( 'ZDZ_Private' ) ) {
			$this->markTestSkipped( 'the coder is installed' );
		}
		self::coder( null );
		$out = ZDZ_Privacy_View::filter_output( '<html><body>Alex Quinn</body></html>' );
		$this->assertStringNotContainsString( 'Alex', $out );
		$this->assertStringContainsString( 'Privacy View', $out );
	}

	public function test_an_oversized_response_is_refused_before_masking(): void {
		$big = str_repeat( 'Alex Quinn ', (int) ( ZDZ_Privacy_View::MAX_BYTES / 10 ) + 10 );
		$this->assertStringNotContainsString( 'Alex', ZDZ_Privacy_View::mask_response( $big, 'text/plain' ) );
	}

	/* ── 3. prose and the name rule ─────────────────────────────────────── */

	public function test_known_customer_and_unknown_full_name_are_hidden_report_words_kept(): void {
		$this->assertSame( 'Met with [name] today about the March invoice.', ZDZ_Privacy_View::mask_prose( 'Met with Riley Morgan today about the March invoice.' ) );
		$t = ZDZ_Privacy_View::mask_prose( 'Sales grew in March for Alex Quinn.' );
		$this->assertMatchesRegularExpression( self::starts( 'Sales grew in March for Bcdf·' ), $t );
		$this->assertSame( 'Revenue by Month: Top Products', ZDZ_Privacy_View::mask_prose( 'Revenue by Month: Top Products' ) );
	}

	public function test_an_unlisted_capitalised_word_is_a_name(): void {
		$this->assertSame( 'Ask [name] about it.', ZDZ_Privacy_View::mask_prose( 'Ask Zorblax about it.' ), 'unknown: masked, never let through' );
		$this->assertSame( 'Mr. [name] called', ZDZ_Privacy_View::mask_prose( 'Mr. Quinn called' ) );
		$this->assertSame( 'Thanks, [name]', ZDZ_Privacy_View::mask_prose( 'Thanks, Morgan' ) );
		$this->assertSame( '[name] paid', ZDZ_Privacy_View::mask_prose( 'Robin Hill paid' ), 'two ordinary words that are a first name and a surname' );
	}

	public function test_word_lists_are_hashed_and_classify_words(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', ZDZ_Privacy_View::name_hash( 'robin' ) );
		$W = ZDZ_Privacy_View::words();
		$this->assertArrayHasKey( ZDZ_Privacy_View::name_hash( 'robin' ), $W['cfirst'], 'census first names are kept as hashes' );
		$this->assertArrayNotHasKey( 'robin', $W['cfirst'], 'never as words' );
		$this->assertArrayHasKey( 'invoice', $W['ord'] );
		$this->assertArrayHasKey( 'the', $W['stop'] );
		$this->assertSame( 'name', ZDZ_Privacy_View::word_class( 'Zorblax' )['cls'] );
		$this->assertSame( 'safe', ZDZ_Privacy_View::word_class( 'Invoices' )['cls'] );
		$this->assertSame( 'safe', ZDZ_Privacy_View::word_class( 'HOA' )['cls'], 'a short acronym in capitals' );
		$this->assertSame( 'hon', ZDZ_Privacy_View::word_class( 'Mrs' )['cls'] );
	}

	public function test_a_business_adds_its_own_words_through_the_pack_or_the_filter(): void {
		$this->assertSame( 'Our [name] line', ZDZ_Privacy_View::mask_prose( 'Our Zorblax line' ) );
		ZDZ_Privacy_View::flush();
		self::coder( new ZDZ_Test_Pv_Coder() );
		ZDZ_Privacy_View::new_view( 'Bcdf' );
		$GLOBALS['zdz_test_options']['zdz_privacy_view_vocabulary'] = array( 'words' => array( 'Zorblax' ), 'phrases' => array() );
		$this->assertSame( 'Our Zorblax line', ZDZ_Privacy_View::mask_prose( 'Our Zorblax line' ), 'a pack word is safe' );
		ZDZ_Privacy_View::flush();
		self::coder( new ZDZ_Test_Pv_Coder() );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_words'] = function ( $v ) { $v['phrases'][] = 'Quorn Vale'; return $v; };
		$this->assertSame( 'Delivered to Quorn Vale today', ZDZ_Privacy_View::mask_prose( 'Delivered to Quorn Vale today' ), 'a filter phrase is safe as a whole' );
		$this->assertSame( 'Ask [name] today', ZDZ_Privacy_View::mask_prose( 'Ask Quorn today' ), 'but not word by word' );
	}

	public function test_documents_streets_and_emails_in_prose(): void {
		$this->assertMatchesRegularExpression( self::starts( 'Invoice Bcdf·' ), ZDZ_Privacy_View::mask_prose( 'Invoice #4521 is due' ) );
		$this->assertStringNotContainsString( '4521', ZDZ_Privacy_View::mask_prose( 'Invoice #4521 is due' ) );
		$this->assertStringNotContainsString( '1450 Example St', ZDZ_Privacy_View::mask_prose( 'The job at 1450 Example St is done' ) );
		$this->assertStringNotContainsString( 'alex@example.com', ZDZ_Privacy_View::mask_prose( 'Mail alex@example.com' ) );
		$this->assertStringContainsString( '#1234', ZDZ_Privacy_View::mask_prose( 'License #1234' ), 'a licence number is not a document' );
	}

	public function test_team_members_are_coded_by_their_own_method(): void {
		$GLOBALS['zdz_test_users']     = array( (object) array( 'ID' => 7, 'display_name' => 'Jordan Example', 'user_login' => 'jexample' ) );
		$GLOBALS['zdz_test_user_meta'] = array( 7 => array( 'first_name' => 'Jordan', 'last_name' => 'Example', 'zdz_user_initials' => 'JE' ) );
		$staff = ZDZ_Privacy_View::staff( 7 );
		$t     = ZDZ_Privacy_View::mask_prose( 'Assigned to Jordan Example (JE), ask @jexample' );
		$this->assertStringNotContainsString( 'Jordan', $t );
		$this->assertStringNotContainsString( 'jexample', $t );
		$this->assertStringContainsString( $staff, $t );
		$out = json_decode( ZDZ_Privacy_View::mask_json_text( '{"salesperson":"JE","author_login":"jexample"}' ), true );
		$this->assertSame( $staff, $out['salesperson'] );
		$this->assertSame( $staff, $out['author_login'] );
	}

	public function test_the_business_own_name_is_not_a_customer(): void {
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_own_names'] = function ( $n ) { $n[] = 'Example Hardware'; return $n; };
		$this->assertSame( 'Thank you for choosing Example Hardware', ZDZ_Privacy_View::mask_prose( 'Thank you for choosing Example Hardware' ) );
	}

	public function test_an_address_at_the_business_own_domain_is_still_masked(): void {
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_own_names'] = function ( $n ) { $n[] = 'example.org'; return $n; };
		$t = ZDZ_Privacy_View::mask_prose( 'Ask jordan@example.org or see https://example.org/help' );
		$this->assertStringNotContainsString( 'jordan', $t, 'the local part can name a team member' );
		$this->assertStringContainsString( 'https://example.org/help', $t, 'the site itself is not hidden' );
	}

	public function test_a_made_up_record_mark_is_kept_only_when_a_business_names_it(): void {
		$this->assertStringNotContainsString( 'Zq Test', ZDZ_Privacy_View::mask_prose( 'Open Zq Test now' ) );
		ZDZ_Privacy_View::flush();
		self::coder( new ZDZ_Test_Pv_Coder() );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_kept_marks'] = function () { return array( 'Zq Test', 'Zqx' ); };
		$this->assertSame( 'Open Zq Test now', ZDZ_Privacy_View::mask_prose( 'Open Zq Test now' ) );
		$this->assertSame( 'Open ZQ TEST now', ZDZ_Privacy_View::mask_prose( 'Open ZQ TEST now' ), 'in capitals too' );
		$this->assertSame( 'Ask [name]', ZDZ_Privacy_View::mask_prose( 'Ask Zqx' ), 'a one-word mark is never honoured' );
	}

	/* ── the administrator's look-up and the chat's read-back ───────────── */

	public function test_lookup_reads_the_tag_and_finds_the_customer(): void {
		$p = ZDZ_Privacy_View::customer( 'Alex Quinn' );
		ZDZ_Privacy_View::new_view( 'Mnpq' );
		$hits = ZDZ_Privacy_View::lookup( $p );
		$this->assertContains( 'customer: alex quinn', $hits );
		$this->assertSame( array(), ZDZ_Privacy_View::lookup( substr( $p, 0, -1 ) . ( '_' === substr( $p, -1 ) ? 'A' : '_' ) ), 'a wrong check character finds nothing' );
		$this->assertSame( array(), ZDZ_Privacy_View::lookup( 'not a code' ) );
	}

	public function test_resolve_in_answers_only_from_text_the_chat_already_showed(): void {
		$p = ZDZ_Privacy_View::customer( 'Alex Quinn' );
		$r = ZDZ_Privacy_View::resolve_in( $p, 'Earlier answer: Alex Quinn has two open estimates.' );
		$this->assertSame( array( 'kind' => 'customer', 'value' => 'Alex Quinn' ), $r );
		$this->assertNull( ZDZ_Privacy_View::resolve_in( $p, 'An answer about somebody else.' ), 'never a guess' );
		$this->assertNull( ZDZ_Privacy_View::resolve_in( 'Bcdf·AAAAA', 'Alex Quinn' ) );
	}

	/* ── photos and uploads ─────────────────────────────────────────────── */

	public function test_upload_addresses_become_a_stand_in(): void {
		$html = '<img src="https://example.com/wp-content/uploads/2026/01/site-photo.jpg"> {"u":"https:\/\/example.com\/wp-content\/uploads\/a.jpg"}';
		$out  = ZDZ_Privacy_View::no_uploads( $html );
		$this->assertStringNotContainsString( 'site-photo.jpg', $out );
		$this->assertStringNotContainsString( 'a.jpg', $out );
		$this->assertStringContainsString( 'example.com/projects', ZDZ_Privacy_View::no_uploads( 'https://example.com/projects' ) );
	}

	public function test_photo_placeholder_holds_only_size_date_and_a_code(): void {
		$svg = ZDZ_Privacy_View::photo_placeholder( 99999, 10, '2026-03-04 10:00:00', 12 );
		$this->assertStringContainsString( 'width="4000"', $svg, 'clamped' );
		$this->assertStringContainsString( 'height="40"', $svg );
		$this->assertStringContainsString( '2026-03-04', $svg );
		$this->assertStringContainsString( 'Bcdf·', $svg );
	}

	/* ── the Identity Pack's part ───────────────────────────────────────── */

	public function test_pack_vocabulary_previews_applies_and_restores(): void {
		$files = array( 'knowledge' => array( 'privacy_view' => array(
			'safe_words'   => array( 'Quorn', 'two words', '<b>', 'Vale' ),
			'safe_phrases' => array( 'Quorn Vale', 'single' ),
		) ) );
		$v = ZDZ_Privacy_View::pack_vocabulary( $files );
		$this->assertSame( array( 'quorn', 'vale' ), $v['words'], 'one word each, lower case, nothing else' );
		$this->assertSame( array( 'quorn vale' ), $v['phrases'], 'a phrase has two words or more' );
		$this->assertNull( ZDZ_Privacy_View::pack_vocabulary( array( 'knowledge' => array( 'facts' => array() ) ) ), 'nothing said: nothing changed' );

		$this->assertNull( ZDZ_Privacy_View::pack_snapshot() );
		$this->assertCount( 2, ZDZ_Privacy_View::pack_preview( $files ) );
		ZDZ_Privacy_View::pack_apply( $files, 'example-business' );
		$this->assertSame( array( 'quorn', 'vale' ), $GLOBALS['zdz_test_options']['zdz_privacy_view_vocabulary']['words'] );
		$this->assertCount( 0, ZDZ_Privacy_View::pack_preview( $files ), 'applied: nothing left to change' );
		ZDZ_Privacy_View::pack_restore( null );
		$this->assertArrayNotHasKey( 'zdz_privacy_view_vocabulary', (array) ( $GLOBALS['zdz_test_options'] ?? array() ), 'revert to a blank install removes it' );
	}

	public function test_pack_consumer_is_complete(): void {
		$c = ZDZ_Privacy_View::pack_consumer( array() );
		$this->assertSame( array( 'knowledge' ), $c['privacy-view']['files'] );
		foreach ( array( 'preview', 'apply', 'snapshot', 'restore' ) as $k ) {
			$this->assertTrue( is_callable( $c['privacy-view'][ $k ] ), $k );
		}
	}
}

/** wp_json_encode when a test file before this one defined it, json_encode otherwise. */
function zdz_test_pv_json( $v ) {
	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
}
