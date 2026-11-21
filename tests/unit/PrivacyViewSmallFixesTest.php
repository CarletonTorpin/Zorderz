<?php
/**
 * ZDZ_Privacy_View: what an account in Privacy View is sent, in five places where a detail could still show.
 *
 *   A. a link's %-encoded piece (a query value, a path segment, a key, the fragment) is read decoded, masked, and
 *      written back encoded, so the link still works; the whole link is then read as before, so nothing is unhidden;
 *   B. a billing or CRM system's id for a customer, written in a sentence, is a customer code ("client ID: 20031";
 *      "customer #30045" is not an invoice); a count, a size or a year beside the word is left alone;
 *   C. a team member's initials in a sentence: in a list, after a role word, or on their own (unless also a word);
 *   D. a one-word display name ("admin") in a name field is that person (their first name in their own thread);
 *   E. the messaging app's edit, delete, select and swipe-delete controls are not drawn on the account's pages;
 * and the words those rules lean on are the business's own where another business's would differ (role labels, the
 * names of its systems, its product words, its region codes).
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-view-gate-doubles.php';

final class PrivacyViewSmallFixesTest extends TestCase {

	const CODE = '/(?:Cust|Staff|Inv|Est|email|phone|street)·[0-9A-Z]{4}[0-9A-Z_]/u';

	/** Two team members with initials, and the administrator whose display name is one word. */
	const JB = 3;
	const MC = 4;

	protected function setUp(): void {
		ZDZ_Test_Kpv_Request::reset();
		ZDZ_Test_Kpv_Request::people();
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_labels']     = function () { return true; };   // labelled codes ("Cust·…"), so a test can say what kind each is
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_known_docs'] = function () { return array( '30045' => 'invoice' ); };
		$GLOBALS['zdz_test_users'] = array(
			(object) array( 'ID' => self::JB, 'display_name' => 'Jordan Blake', 'user_login' => 'jblake', 'roles' => array( 'zdz_sales' ) ),
			(object) array( 'ID' => self::MC, 'display_name' => 'Morgan Casey', 'user_login' => 'mcasey', 'roles' => array( 'zdz_tech' ) ),
			(object) array( 'ID' => ZDZ_Test_Kpv_Request::ADMIN, 'display_name' => 'admin', 'user_login' => 'siteadmin', 'roles' => array( 'administrator' ) ),
			(object) array( 'ID' => ZDZ_Test_Kpv_Request::ACCOUNT, 'display_name' => 'Privacy Account', 'user_login' => 'privacy-account', 'roles' => array( 'administrator' ) ),
		);
		$GLOBALS['zdz_test_user_roles'][ self::MC ]                 = array( 'zdz_tech' );
		$GLOBALS['zdz_test_user_names']                             = array( ZDZ_Test_Kpv_Request::ADMIN => 'admin', self::JB => 'Jordan Blake', self::MC => 'Morgan Casey', ZDZ_Test_Kpv_Request::ACCOUNT => 'Privacy Account' );
		$GLOBALS['zdz_test_user_meta'][ self::JB ]                  = array( 'first_name' => 'Jordan', 'last_name' => 'Blake', 'zdz_user_initials' => 'JB' );
		$GLOBALS['zdz_test_user_meta'][ self::MC ]                  = array( 'first_name' => 'Morgan', 'last_name' => 'Casey', 'zdz_user_initials' => 'MC' );
		$GLOBALS['zdz_test_user_meta'][ ZDZ_Test_Kpv_Request::ADMIN ] = array( 'first_name' => 'Robin', 'last_name' => 'Devin' );
		ZDZ_Privacy_View::flush();
		ZDZ_Test_Kpv_Request::set( 'coder', new ZDZ_Test_Kpv_Coder() );
		ZDZ_Privacy_View::new_view();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_user_names'] );
		ZDZ_Test_Kpv_Request::reset();
	}

	/** Every detail a test plants, that must not be readable in what comes out. */
	private static function leaks( $out ) {
		$found = array();
		foreach ( array( 'Alex Riley', 'Riley', 'alex@example.com', 'alex@', '555-010-0142', '5550100142', '555 010 0142', '30045', 'Jordan Blake', 'Blake', 'Morgan Casey', 'Example Hardware' ) as $d ) {
			if ( false !== stripos( (string) $out, $d ) ) {
				$found[] = $d;
			}
		}
		return $found;
	}

	private static function prose( $t ) {
		return (string) ZDZ_Privacy_View::mask_prose( $t );
	}

	/* ── A. a %-encoded piece of a link ───────────────────────────────────────── */

	public function test_an_encoded_query_value_is_masked_and_the_link_still_works(): void {
		$cases = array(
			'an e-mail'       => array( 'https://app.example.com/x?to=alex%40example.com&v=2', 'alex@example.com' ),
			'a phone'         => array( '/x?p=555%2D010%2D0142&id=abc123', '555-010-0142' ),
			'a phone, + form' => array( '/x?p=555+010+0142', '0142' ),
			'a customer'      => array( 'https://app.example.com/x?c=Alex%20Riley#top', 'Riley' ),
			'an invoice'      => array( '/x?q=invoice%20%2330045', '30045' ),
		);
		foreach ( $cases as $what => $c ) {
			$out = (string) ZDZ_Privacy_View::mask_value( $c[0], 'url' );
			$dec = rawurldecode( $out );
			$this->assertFalse( stripos( $dec, $c[1] ), $what . ': masked inside the link (' . $out . ')' );
			$this->assertSame( array(), self::leaks( $dec ), $what );
			$this->assertMatchesRegularExpression( self::CODE, $dec, $what . ': a code stands in its place' );
			$this->assertDoesNotMatchRegularExpression( '/\s/', $out, $what . ': the link is still one link (encoded)' );
			$this->assertSame( strtok( $c[0], '?' ), strtok( $out, '?' ), $what . ': its path kept' );
		}
		$out = (string) ZDZ_Privacy_View::mask_value( 'https://app.example.com/x?c=Alex%20Riley#top', 'url' );
		$this->assertSame( '#top', substr( $out, -4 ), 'the part after # is kept' );
		$out = (string) ZDZ_Privacy_View::mask_value( '/x?to=alex%40example.com&doc=30045', 'href' );
		$this->assertStringNotContainsString( '30045', $out, 'a plain value beside an encoded one is read as before (a known invoice number in a link)' );
		$this->assertFalse( stripos( rawurldecode( $out ), 'alex@' ) );
		foreach ( array( 'https://app.example.com/?zana_session=k3j4h5g6f7d8s9a0q1w2', '/x?q=hello%20world&page=2', '/wp-admin/admin.php?page=zdz-llm-usage', '/x?s=a%2Fb' ) as $plain ) {
			$this->assertSame( $plain, ZDZ_Privacy_View::mask_value( $plain, 'url' ), 'nothing to hide: the link is left byte for byte' );
		}
	}

	public function test_every_other_piece_of_such_a_link_is_still_read(): void {
		foreach ( array(
			'in the path'          => '/c/alex%40example.com/x?a=b%20c',
			'a phone in the path'  => '/c/555%20010%200142/x?a=b%20c',
			'a name in the path'   => '/Alex%20Riley/x?a=b%20c',
			'in the fragment'      => '/x?q=a%20b#e=alex%40example.com',
			'as a key'             => '/x?alex%40example.com=1&a=b%20c',
			'a pair with no value' => '/x?q=a%20b&Jordan%20Blake',
			'a file name'          => '/p/Alex%20Riley.jpg?v=1%202',
			'all of them'          => '/x?to=alex%40example.com&q=Alex%20Riley#t=alex%40example.com',
		) as $what => $u ) {
			$out = (string) ZDZ_Privacy_View::mask_value( $u, 'url' );
			$this->assertSame( array(), self::leaks( rawurldecode( str_replace( '+', ' ', $out ) ) ), 'beside an encoded value, ' . $what . ': nothing shows (' . $out . ')' );
		}
		$h = (string) ZDZ_Privacy_View::mask_html( '<p><a href="/x?to=alex%40example.com&amp;q=Alex%20Riley#t=alex%40example.com">m</a><img src="/p/Alex%20Riley.jpg?v=1%202"></p>', false );
		$this->assertSame( array(), self::leaks( rawurldecode( html_entity_decode( $h ) ) ), '... and in a page\'s href and src' );
		$h = (string) ZDZ_Privacy_View::mask_html( '<p><a href="/?to=alex%40example.com&amp;x=1">mail</a></p>', false );
		$this->assertFalse( stripos( rawurldecode( html_entity_decode( $h ) ), 'alex@' ) );
		$this->assertStringContainsString( '&amp;x=1', $h, 'the rest of the link is as it was' );
	}

	/* ── B. a system's id for a customer, in a sentence ───────────────────────── */

	public function test_a_customer_id_in_a_sentence_is_a_customer_code(): void {
		$t = self::prose( 'Billing client 1000042 has two estimates. Client ID: 20031. See customer #30045 and client no. 4005.' );
		$this->assertDoesNotMatchRegularExpression( '/1000042|20031|30045|4005/', $t );
		$this->assertSame( 4, preg_match_all( '/Cust·/u', $t ), 'four ways of writing it: each a customer code, the words kept (' . $t . ')' );
		$this->assertStringNotContainsString( 'Inv·', $t, '"customer #30045" is not read as an invoice' );
		$this->assertStringContainsString( 'Client ID: Cust·', $t );
		foreach ( array( 'We added 120 customers in 2026.', 'Customer since 2019, client 2024 too.', 'One customer 3 of 5 left.', 'The client 450 sq ft office.', 'One customer: 120 units.',
			'The client 10000 sq ft lot.', 'A customer 12000 dollars over.', 'The client 1200 m2 site.', 'A customer 40000 euros over.',
			'The client 2500 kg load.' ) as $plain ) {
			$this->assertSame( $plain, self::prose( $plain ), 'a count, a year or a size beside the word stays' );
		}
		$this->assertStringContainsString( 'Inv·', self::prose( 'Invoice #30045 was paid.' ), 'an invoice number is still an invoice' );
		$this->assertStringNotContainsString( 'Cust·', self::prose( 'Client ID 2024 and customer #2024, both years.' ), 'a year is never a client id (with "ID" or "#" too)' );
		$t = self::prose( 'See customer#30045 and client_id 20031 today.' );
		$this->assertMatchesRegularExpression( '/customer Cust·\S+ and client_id Cust·/u', $t, '"customer#30045" keeps a space where the # was; "client_id 20031" is one too' );
	}

	public function test_a_systems_name_before_the_word_makes_a_short_number_an_id_and_the_business_names_its_own(): void {
		$this->assertStringContainsString( 'Cust·', self::prose( 'The billing client 4417 paid.' ), 'Core: "billing" before the word' );
		$this->assertStringContainsString( 'Cust·', self::prose( 'See CRM customer 882 first.' ) );
		$this->assertSame( 'The client 4417 paid late.', self::prose( 'The client 4417 paid late.' ), 'without a system\'s name, a short bare number is not an id' );
		$this->assertSame( 'In ledgerly client 4417 is new.', self::prose( 'In ledgerly client 4417 is new.' ), 'a system Core does not know is not one' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_system_words'] = function ( $words ) {
			return array( 'Ledgerly', 'two words', 7 );   // Core's own left out: they stay all the same
		};
		$this->assertContains( 'ledgerly', ZDZ_Privacy_View::system_words() );
		$this->assertContains( 'billing', ZDZ_Privacy_View::system_words(), 'Core\'s own cannot be taken away' );
		$this->assertNotContains( 'two words', ZDZ_Privacy_View::system_words() );
		$this->assertMatchesRegularExpression( '/^In ledgerly client Cust·\S+ is new\.$/u', self::prose( 'In ledgerly client 4417 is new.' ), 'a business on another system names it' );
		$this->assertMatchesRegularExpression( '/ client Cust·\S+ is new\.$/u', self::prose( 'In Ledgerly client 4417 is new.' ), '... in any case' );
		$this->assertStringContainsString( 'Cust·', self::prose( 'The billing client 4417 paid.' ), '... and Core\'s own still count' );
	}

	public function test_a_count_of_the_business_own_products_is_not_an_id(): void {
		$this->assertStringContainsString( 'Cust·', self::prose( 'One customer: 12000 widgets were late.' ), 'an unknown word after the number does not make it a count' );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::VOCAB_OPT ] = array( 'words' => array( 'widget' ), 'phrases' => array() );
		ZDZ_Privacy_View::flush();
		ZDZ_Test_Kpv_Request::set( 'coder', new ZDZ_Test_Kpv_Coder() );
		$this->assertSame( 'One customer: 12000 widgets were late.', self::prose( 'One customer: 12000 widgets were late.' ), 'the business\'s own product word (its Identity Pack\'s vocabulary), singular or plural' );
		$this->assertStringContainsString( 'Cust·', self::prose( 'Client ID: 12000 widgets were late.' ), 'a number named as an id is one, whatever follows' );
	}

	/* ── C. a team member's initials in a sentence ────────────────────────────── */

	public function test_initials_in_a_sentence_are_coded_in_a_list_after_a_role_word_or_alone(): void {
		$j = ZDZ_Privacy_View::staff( self::JB );
		$m = ZDZ_Privacy_View::staff( self::MC );
		$this->assertSame( "Crew: $j and $m.", self::prose( 'Crew: JB and MC.' ), 'a list after a role word' );
		$this->assertSame( "$j/$m will install Monday.", self::prose( 'JB/MC will install Monday.' ), 'two codes joined by /' );
		$this->assertSame( "Ask tech $m about it.", self::prose( 'Ask tech MC about it.' ), 'after a role word' );
		$this->assertSame( "$j will call the homeowner.", self::prose( 'JB will call the homeowner.' ), 'on its own, a code that is not a word' );
		$this->assertSame( "($j) sold it.", self::prose( '(JB) sold it.' ), 'in brackets, as before' );
		$this->assertSame( 'JB', self::prose( 'JB' ), 'a value that IS the code (an avatar\'s letters) is left to its field' );
		foreach ( array( 'Part JB-12 is in.', 'See /x/JB/MC/ there.', 'Model ZJB and JBQ.', 'Code ' . $j . ' stays.', 'jb and mc in lower case.' ) as $plain ) {
			$this->assertSame( $plain, self::prose( $plain ), 'not inside a word, a part number, an address or a code' );
		}
		$this->assertSame( 'Open https://app.example.com/x?u=JB&v=MC now.', self::prose( 'Open https://app.example.com/x?u=JB&v=MC now.' ), 'not inside an address written in a sentence' );
	}

	public function test_initials_that_are_also_a_word_stay_a_word_on_their_own(): void {
		$GLOBALS['zdz_test_user_meta'][ ZDZ_Test_Kpv_Request::ADMIN ]['zdz_user_initials'] = 'IT';
		ZDZ_Test_Kpv_Request::set( 'staff', null );
		$a = ZDZ_Privacy_View::staff( ZDZ_Test_Kpv_Request::ADMIN );
		$j = ZDZ_Privacy_View::staff( self::JB );
		$this->assertSame( 'Call IT when IT is back.', self::prose( 'Call IT when IT is back.' ), 'on its own: stays the word' );
		$this->assertSame( "Installer: $a.", self::prose( 'Installer: IT.' ), '... coded after a role word and a colon' );
		$this->assertSame( "$j and $a will go.", self::prose( 'JB and IT will go.' ), '... or in a list' );
		$this->assertSame( 'The crew IT desk is closed.', self::prose( 'The crew IT desk is closed.' ), '... but not after a role word without ":" or "-"' );
	}

	public function test_a_region_code_from_the_business_vocabulary_stays_a_word_too(): void {
		$GLOBALS['zdz_test_user_meta'][ self::MC ]['zdz_user_initials'] = 'ZQ';
		ZDZ_Test_Kpv_Request::set( 'staff', null );
		$z = ZDZ_Privacy_View::staff( self::MC );
		$this->assertSame( "Shipping to $z today.", self::prose( 'Shipping to ZQ today.' ), 'Core knows no region\'s codes: initials alone are a person' );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::VOCAB_OPT ] = array( 'words' => array( 'zq' ), 'phrases' => array() );
		ZDZ_Privacy_View::flush();
		ZDZ_Test_Kpv_Request::set( 'coder', new ZDZ_Test_Kpv_Coder() );
		$z = ZDZ_Privacy_View::staff( self::MC );
		$this->assertSame( 'Shipping to ZQ today.', self::prose( 'Shipping to ZQ today.' ), 'a short word of the business\'s own (its region code) stays a word on its own' );
		$this->assertSame( "Crew: $z.", self::prose( 'Crew: ZQ.' ), '... and is still the person after a role word and a colon' );
	}

	public function test_the_business_role_labels_are_role_words(): void {
		$this->assertContains( 'crew', ZDZ_Privacy_View::role_words() );
		$this->assertContains( 'assigned to', ZDZ_Privacy_View::role_words() );
		$j = ZDZ_Privacy_View::staff( self::JB );
		$this->assertSame( "The stylist $j is in.", self::prose( 'The stylist JB is in.' ), 'initials that are no word are coded on their own, whatever stands before them' );
		$GLOBALS['zdz_test_user_meta'][ self::JB ]['zdz_user_initials'] = 'IT';
		ZDZ_Test_Kpv_Request::set( 'staff', null );
		$i = ZDZ_Privacy_View::staff( self::JB );
		$this->assertSame( 'Stylist: IT.', self::prose( 'Stylist: IT.' ), 'a role Core does not know: a word-code after it stays a word' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_role_words'] = function ( $words ) {
			return array( 'Stylist', 'lead therapist', 'x', 'bad;word' );   // Core's own left out: they stay all the same
		};
		$this->assertContains( 'stylist', ZDZ_Privacy_View::role_words() );
		$this->assertContains( 'lead therapist', ZDZ_Privacy_View::role_words() );
		$this->assertContains( 'crew', ZDZ_Privacy_View::role_words(), 'Core\'s own cannot be taken away' );
		$this->assertNotContains( 'x', ZDZ_Privacy_View::role_words() );
		$this->assertNotContains( 'bad;word', ZDZ_Privacy_View::role_words() );
		$this->assertSame( "Stylist: $i.", self::prose( 'Stylist: IT.' ), 'a business that names its roles: coded after its role word' );
		$this->assertSame( "Lead therapist - $i.", self::prose( 'Lead therapist - IT.' ) );
	}

	public function test_a_very_long_run_of_codes_never_empties_the_text(): void {
		$long = 'Crew today: ' . str_repeat( 'JB, ', 10000 ) . 'MC.';
		$ip   = new ReflectionMethod( 'ZDZ_Privacy_View', 'initials_prose' );
		$ip->setAccessible( true );
		$out = (string) $ip->invoke( null, $long, ZDZ_Privacy_View::staff_names()['initials'] );
		$this->assertGreaterThan( 10000, strlen( $out ) );
		$this->assertDoesNotMatchRegularExpression( '/(?<![\p{L}\p{N}·])(?:JB|MC)(?![\p{L}\p{N}])/u', $out, 'every code coded' );
	}

	/* ── D. a one-word display name is that person in a name field ────────────── */

	public function test_a_one_word_display_name_is_that_person_in_a_name_field(): void {
		$j  = json_decode( (string) ZDZ_Privacy_View::mask_json_text( '{"other_name":"admin","author":{"name":"Admin"},"title":"the admin page","text":"ask an admin"}' ), true );
		$s7 = ZDZ_Privacy_View::staff( ZDZ_Test_Kpv_Request::ADMIN );
		$this->assertSame( $s7, $j['other_name'], 'in a name field: their team code' );
		$this->assertSame( $s7, $j['author']['name'] );
		$this->assertSame( 'the admin page', $j['title'], 'in prose the word stays a word' );
		$this->assertSame( 'ask an admin', $j['text'] );
	}

	public function test_in_their_own_thread_the_partner_is_shown_by_first_name(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = ZDZ_Test_Kpv_Request::ADMIN;
		ZDZ_Test_Kpv_Request::build( 'ajax', array( 'action' => 'zim_sidebar' ) );
		ZDZ_Privacy_View::new_view();
		$r = json_decode( (string) ZDZ_Privacy_View::mask_response( '{"success":true,"data":{"items":[{"other_name":"admin","text":"hi"}]}}', 'application/json' ), true );
		$this->assertSame( 'Robin', $r['data']['items'][0]['other_name'] ?? null, 'in the thread with them: their first name, not "admin"' );
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), array( 'action' => 'zana_list_sessions' ) );
		ZDZ_Privacy_View::new_view();
		$r = json_decode( (string) ZDZ_Privacy_View::mask_response( '{"success":true,"data":{"items":[{"author_name":"admin"}]}}', 'application/json' ), true );
		$this->assertMatchesRegularExpression( self::CODE, (string) ( $r['data']['items'][0]['author_name'] ?? '' ), 'anywhere else: their code' );
	}

	/* ── E. controls the account may not use are not drawn ────────────────────── */

	public function test_the_accounts_pages_hide_controls_it_may_not_use(): void {
		$page = (string) ZDZ_Privacy_View::mask_html( '<!doctype html><html><head></head><body><div class="zim-w-msg-actions"><button>edit</button></div></body></html>' );
		$this->assertStringContainsString( '<style id="zdz-pv-banner-css">.zim-w-msg-actions,.zim-w-select-btn,.zim-w-swipe-zone{display:none!important}</style>', $page, 'the account\'s page carries the rule, beside the banner' );
		$this->assertSame( 1, substr_count( $page, 'zdz-pv-banner-css' ), 'once' );
		$this->assertStringNotContainsString( 'zdz-pv-banner-css', (string) ZDZ_Privacy_View::mask_html( '<p>x</p>', false ), 'and only where the banner goes' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_hidden_controls'] = function ( $selectors ) {
			return array( '.example-edit-btn', '#example-delete', '.example-row  .example-more', 'body', '.a{}body{display:none', '.x, .y', 9, '</style><script>' );
		};
		$this->assertSame( array( '.zim-w-msg-actions', '.zim-w-select-btn', '.zim-w-swipe-zone', '.example-edit-btn', '#example-delete', '.example-row .example-more' ), ZDZ_Privacy_View::hidden_controls(),
			'an app adds plain class and id selectors of its own; nothing else is taken, and Core\'s own stay' );
		$page = (string) ZDZ_Privacy_View::mask_html( '<!doctype html><html><head></head><body><p>x</p></body></html>' );
		$this->assertStringContainsString( '.zim-w-swipe-zone,.example-edit-btn,#example-delete,.example-row .example-more{display:none!important}</style>', $page );
		$this->assertStringNotContainsString( '<script>', $page );
	}

	/* ── a reply an app coded itself comes out as it went in ──────────────────── */

	public function test_a_reply_an_app_coded_at_the_source_is_not_coded_twice(): void {
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), array( 'action' => 'zana_list_sessions' ) );
		ZDZ_Privacy_View::new_view();
		$c      = ZDZ_Privacy_View::customer( 'Alex Riley' );
		$e      = ZDZ_Privacy_View::doc( 'estimate', '30045' );
		$s3     = ZDZ_Privacy_View::staff( self::JB );
		$s4     = ZDZ_Privacy_View::staff( self::MC );
		$st     = ZDZ_Privacy_View::street( '12 Example Rd' );
		$hidden = '[not shown in Privacy View]';
		$shaped = array( 'success' => true, 'data' => array(
			'rows'      => array(
				array( 'id' => 41, 'ref_id' => '998877', 'number' => $e, 'customer_name' => $c, 'salesperson' => "$s3 & $s4", 'item_count' => 2, 'status' => 2, 'total' => 889.5, 'is_test' => true,
					'created_at' => '2026-09-12 10:00:00', 'followup' => array( 'id' => 5, 'token' => '', 'state' => 'open', 'to' => $s3, 'by' => $s4, 'asked' => '2026-11-10 18:00:00', 'outcome' => 'Still deciding',
						'tries' => 0, 'mine' => false, 'words' => "$s4 asked $s3 to follow up", 'why' => 'the estimate was accepted or invoiced' ) ),
				array( 'id' => 42, 'ref_id' => '', 'number' => '', 'customer_name' => $c, 'salesperson' => '[name]', 'followup' => null ),
			),
			'read_only' => true,
			'one'       => array( 'id' => 41, 'number' => $e, 'customer' => $c, 'salesperson' => $s3, 'street' => $st, 'has_email' => true, 'has_phone' => false, 'status' => 'created', 'approved_by' => $s4,
				'lines' => array( array( 'words' => true, 'qty' => 1.0, 'unit_price' => 0.0, 'amount' => 0.0 ), array( 'words' => false, 'qty' => 6.0, 'unit_price' => 41.5, 'amount' => 249.0 ) ),
				'notes_hidden' => 4, 'total' => 889.5 ),
			'history'   => array( array( 'ask_note' => $hidden, 'note' => $hidden, 'channel' => 'Left a voicemail', 'outcome' => 'Something else (say in the note)',
				'tried' => array( array( 'when' => '2026-11-10 19:00:00', 'who' => $s3, 'channel' => 'Call', 'note' => $hidden ) ) ) ),
		) );
		$out = ZDZ_Privacy_View::mask_response( json_encode( $shaped, JSON_UNESCAPED_UNICODE ), 'application/json' );
		$got = json_decode( (string) $out, true );
		$this->assertIsArray( $got );
		$diff = array();
		$walk = function ( $a, $b, $path ) use ( &$walk, &$diff ) {
			foreach ( (array) $a as $k => $v ) {
				if ( is_array( $v ) ) {
					$walk( $v, is_array( $b[ $k ] ?? null ) ? $b[ $k ] : array(), "$path/$k" );
				} elseif ( ! array_key_exists( $k, (array) $b ) || ( ( is_int( $v ) || is_float( $v ) ) ? ( ! is_int( $b[ $k ] ) && ! is_float( $b[ $k ] ) ) || (float) $b[ $k ] !== (float) $v : $b[ $k ] !== $v ) ) {
					$diff[] = "$path/$k";   // (1.0 may come back as 1)
				}
			}
		};
		$walk( $shaped, $got, '' );
		$this->assertSame( array(), $diff, 'codes, fixed words, "[name]", numbers, dates and ids come out exactly as they went in' );
		$this->assertSame( array(), self::leaks( $out ) );
	}
}
