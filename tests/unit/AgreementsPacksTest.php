<?php
/**
 * The agreements app's legal packs and the decisions made from them, with neutral data only:
 *
 *   - business days under each pack's own definition, across holiday edges (a holiday on a Saturday or a Sunday, the
 *     turn of the year, Thanksgiving week, a holiday one pack names and the other doesn't);
 *   - the windows: the national three days, and a state's longer windows for a buyer 65 or older and for disaster
 *     repairs, the latest deadline winning when packs are layered;
 *   - which packs apply: the chosen pack, and the state layer the JOB's address decides;
 *   - every rule carries a citation, the date it took effect and the date it was last checked, or the pack is refused;
 *   - the checklist that holds an agreement back from "ready to sign";
 *   - template fingerprinting and its approval.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/agreements-stubs.php';

final class AgreementsPacksTest extends TestCase {

	/** @var string a scratch folder for packs and templates of the test's own */
	private $tmp = '';

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_filters']    = array(
			'zagr_timezone' => static function () { return 'America/Chicago'; },
		);
		ZAGR_Pack::forget();
		ZAGR_Template::forget();
		ZAGR_Calendar::forget();
		ZAGR_Business_Days::forget();
		ZAGR_Strings::forget();
	}

	protected function tearDown(): void {
		if ( '' !== $this->tmp && is_dir( $this->tmp ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $this->tmp );
		}
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_user_roles'] );
		ZAGR_Pack::forget();
		ZAGR_Template::forget();
		ZAGR_Calendar::forget();
		ZAGR_Strings::forget();
	}

	private function scratch(): string {
		$this->tmp = sys_get_temp_dir() . '/zagr-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0700, true );
		return $this->tmp;
	}

	private function bd( string $pack ): array {
		return ZAGR_Pack::load( $pack )['rules']['business_days'];
	}

	/* ── business days ─────────────────────────────────────────────────────── */

	public function test_holiday_rules_fall_on_the_right_days(): void {
		$fed = $this->bd( 'us-federal' );
		$h   = ZAGR_Business_Days::holidays( $fed, 2026 );
		foreach ( array( '2026-01-01', '2026-01-19', '2026-02-16', '2026-05-25', '2026-06-19', '2026-07-04', '2026-09-07', '2026-10-12', '2026-11-11', '2026-11-26', '2026-12-25' ) as $d ) {
			$this->assertContains( $d, $h, $d . ' is a federal holiday in 2026' );
		}
		$this->assertContains( '2026-07-03', $h, 'Independence Day on a Saturday takes the Friday before too' );
		$this->assertNotContains( '2020-06-19', ZAGR_Business_Days::holidays( $fed, 2020 ), 'Juneteenth counts only from 2021' );
		$this->assertContains( '2021-01-18', ZAGR_Business_Days::holidays( $fed, 2021 ), 'third Monday of January 2021' );
	}

	public function test_sunday_never_counts_and_saturday_does(): void {
		$fed = $this->bd( 'us-federal' );
		$this->assertFalse( ZAGR_Business_Days::is_business_day( $fed, '2026-11-08' ), 'a Sunday' );
		$this->assertTrue( ZAGR_Business_Days::is_business_day( $fed, '2026-11-07' ), 'a Saturday counts' );
		$this->assertFalse( ZAGR_Business_Days::is_business_day( $fed, '2026-02-30' ), 'not a date' );
	}

	public function test_thanksgiving_week(): void {
		// signed Wednesday 25 November 2026: Thursday is Thanksgiving, Sunday never counts
		$this->assertSame( '2026-11-30', ZAGR_Business_Days::add( $this->bd( 'us-federal' ), '2026-11-25', 3 ) );
		$this->assertSame( '2026-11-30', ZAGR_Business_Days::add( $this->bd( 'us-ca' ), '2026-11-25', 3 ) );
	}

	public function test_holiday_on_a_saturday_takes_the_friday_too(): void {
		// 4 July 2026 is a Saturday: Friday 3 July and Saturday 4 July both drop out
		$this->assertSame( '2026-07-08', ZAGR_Business_Days::add( $this->bd( 'us-federal' ), '2026-07-02', 3 ) );
	}

	public function test_the_turn_of_the_year(): void {
		$fed = $this->bd( 'us-federal' );
		// 25 December 2027 and 1 January 2028 are Saturdays: Friday 24 and Friday 31 December 2027 drop out too
		$this->assertSame( '2027-12-29', ZAGR_Business_Days::add( $fed, '2027-12-23', 3 ) );
		$this->assertSame( '2028-01-04', ZAGR_Business_Days::add( $fed, '2027-12-29', 3 ), 'the next year\'s New Year observed on 31 December' );
	}

	public function test_a_holiday_one_pack_names_and_the_other_does_not(): void {
		// Juneteenth (Friday 19 June 2026) is a federal holiday; the state's list doesn't name it
		$this->assertSame( '2026-06-22', ZAGR_Business_Days::add( $this->bd( 'us-federal' ), '2026-06-17', 3 ) );
		$this->assertSame( '2026-06-20', ZAGR_Business_Days::add( $this->bd( 'us-ca' ), '2026-06-17', 3 ) );
		// layered, the later deadline wins
		$this->assertSame( '2026-06-22', ZAGR_Pack::deadline( array( 'us-federal', 'us-ca' ), '2026-06-17', array( 'senior' => 'no', 'disaster' => 'no' ) ) );
	}

	public function test_out_of_range_counts_are_refused(): void {
		$fed = $this->bd( 'us-federal' );
		$this->assertSame( '', ZAGR_Business_Days::add( $fed, '2026-11-02', 0 ) );
		$this->assertSame( '', ZAGR_Business_Days::add( $fed, '2026-11-02', 31 ) );
		$this->assertSame( '', ZAGR_Business_Days::add( $fed, 'not-a-date', 3 ) );
	}

	/* ── windows ───────────────────────────────────────────────────────────── */

	public function test_national_pack_gives_three_business_days_and_asks_nothing(): void {
		$s = array( 'us-federal' );
		$this->assertSame( array(), ZAGR_Pack::questions( $s ) );
		$w = ZAGR_Pack::window( $s, array() );
		$this->assertSame( 3, (int) $w['days'] );
		$this->assertSame( '2026-11-05', ZAGR_Pack::deadline( $s, '2026-11-02', array() ) );
	}

	public function test_state_windows_for_seniors_and_disaster_repairs(): void {
		$s = array( 'us-federal', 'us-ca' );
		$this->assertNull( ZAGR_Pack::window( $s, array() ), 'never a window before the required answers: the senior question is never assumed' );
		$this->assertNull( ZAGR_Pack::window( $s, array( 'senior' => 'no' ) ), 'the staff disaster answer is required too' );
		$this->assertSame( 3, (int) ZAGR_Pack::window( $s, array( 'senior' => 'no', 'disaster' => 'no' ) )['days'] );
		$w5 = ZAGR_Pack::window( $s, array( 'senior' => 'yes', 'disaster' => 'no' ) );
		$this->assertSame( 5, (int) $w5['days'] );
		$this->assertSame( 'fifth', $w5['ordinal'] );
		$this->assertSame( 7, (int) ZAGR_Pack::window( $s, array( 'senior' => 'no', 'disaster' => 'yes' ) )['days'] );
		$this->assertSame( 7, (int) ZAGR_Pack::window( $s, array( 'senior' => 'yes', 'disaster' => 'yes' ) )['days'], 'the longest applies' );
		$this->assertSame( '2026-11-07', ZAGR_Pack::deadline( $s, '2026-11-02', array( 'senior' => 'yes', 'disaster' => 'no' ) ) );
		$this->assertSame( '2026-11-16', ZAGR_Pack::deadline( $s, '2026-11-09', array( 'senior' => 'yes', 'disaster' => 'no' ) ), 'Veterans\' Day drops out' );
		$this->assertSame( '2026-11-10', ZAGR_Pack::deadline( $s, '2026-11-02', array( 'senior' => 'no', 'disaster' => 'yes' ) ) );
		$this->assertSame( '', ZAGR_Pack::deadline( $s, '2026-11-02', array( 'senior' => 'maybe', 'disaster' => 'no' ) ) );
	}

	public function test_deposit_limit_and_refund_time(): void {
		$this->assertNull( ZAGR_Pack::deposit_cap( array( 'us-federal' ), 2000000, 0 ) );
		$s = array( 'us-federal', 'us-ca' );
		$this->assertSame( 100000, ZAGR_Pack::deposit_cap( $s, 2000000, 0 ), 'the fixed limit' );
		$this->assertSame( 45000, ZAGR_Pack::deposit_cap( $s, 500000, 50000 ), '10 percent, measured before tax' );
		$this->assertTrue( ZAGR_Pack::whole_dollars( $s ) );
		$this->assertSame( 'days', ZAGR_Pack::refund( $s )['unit'] );
		$this->assertSame( 'business days', ZAGR_Pack::refund( array( 'us-federal' ) )['unit'] );
	}

	public function test_a_state_notice_replaces_the_national_one(): void {
		$fed = ZAGR_Pack::notice_sources( array( 'us-federal' ) );
		$this->assertStringContainsString( 'You, the buyer, may cancel this transaction at any time prior to midnight of the third business day', $fed['statement'] );
		$both = ZAGR_Pack::notice_sources( array( 'us-federal', 'us-ca' ) );
		$this->assertStringContainsString( '{{window_title}} Right to Cancel', $both['statement'] );
		$this->assertStringNotContainsString( 'may cancel this transaction at any time prior to midnight', $both['statement'] );
		$this->assertArrayHasKey( 'first_page', $both );
		$this->assertArrayNotHasKey( 'first_page', $fed );
	}

	/* ── which packs apply ─────────────────────────────────────────────────── */

	public function test_the_jobs_state_is_read_from_its_address(): void {
		$this->assertSame( 'IL', ZAGR_Pack::region_of( array( 'address_lines' => array( '1 Example Street', 'Springfield, IL 62701' ) ) ) );
		$this->assertSame( 'CA', ZAGR_Pack::region_of( array( 'address_lines' => array( '1 Example Street', 'Example City, CA 90001-1234' ) ) ) );
		$this->assertSame( 'CA', ZAGR_Pack::region_of( array( 'region' => 'ca', 'address_lines' => array() ) ) );
		$this->assertSame( '', ZAGR_Pack::region_of( array( 'address_lines' => array( '1 Example Street', 'Example City' ) ) ) );
		$this->assertSame( '', ZAGR_Pack::region_of( array( 'address_lines' => array( 'Example City, ZZ 12345' ) ) ), 'not a state' );
	}

	public function test_the_job_address_decides_the_state_layer(): void {
		$this->assertSame( array( 'us-federal', 'us-ca' ), ZAGR_Pack::stack_for( 'CA', 'us-federal' ) );
		$this->assertSame( array( 'us-federal' ), ZAGR_Pack::stack_for( 'TX', 'us-federal' ) );
		$this->assertSame( array( 'us-federal' ), ZAGR_Pack::stack_for( 'NV', 'us-ca' ), 'a state pack chosen by the office does not follow a job elsewhere' );
		$this->assertSame( array( 'us-federal', 'us-ca' ), ZAGR_Pack::stack_for( '', 'us-ca' ), 'an unread state keeps the chosen chain (and the checklist holds it back)' );
		$this->assertSame( array(), ZAGR_Pack::stack_for( 'CA', '' ), 'no pack, no agreement' );
	}

	public function test_the_default_pack_is_the_national_one(): void {
		$this->assertSame( 'us-federal', ZAGR_Pack::selected() );
		$GLOBALS['zdz_test_filters']['zagr_agreement_pack'] = static function () { return 'none'; };
		$this->assertSame( '', ZAGR_Pack::selected() );
	}

	/* ── every rule cited ──────────────────────────────────────────────────── */

	public function test_shipped_packs_cite_every_rule(): void {
		foreach ( array( 'us-federal', 'us-ca' ) as $id ) {
			$p = ZAGR_Pack::load( $id );
			$this->assertSame( $id, $p['id'] );
			foreach ( array_merge( (array) $p['rules']['windows'], (array) $p['rules']['flags'], (array) $p['checklist'] ) as $row ) {
				$this->assertNotEmpty( $row['citation'] );
				$this->assertTrue( ZAGR_Business_Days::valid( (string) $row['effective_from'] ) );
				$this->assertTrue( ZAGR_Business_Days::valid( (string) $row['last_checked'] ) );
			}
		}
	}

	public function test_a_rule_without_its_citation_is_refused(): void {
		$dir = $this->scratch() . '/packs';
		mkdir( $dir . '/xx-test/notices', 0700, true );
		file_put_contents( $dir . '/xx-test/pack.json', json_encode( array( 'id' => 'xx-test', 'label' => 'Test', 'region' => '', 'layers_on' => '' ) ) );
		file_put_contents( $dir . '/xx-test/checklist.json', json_encode( array( 'items' => array() ) ) );
		$bd = array( 'excluded_weekdays' => array( 7 ), 'holidays' => array(), 'citation' => 'Example Act 1', 'effective_from' => '2020-01-01', 'last_checked' => '2026-01-01' );
		file_put_contents( $dir . '/xx-test/rules.json', json_encode( array( 'business_days' => $bd, 'windows' => array( array( 'id' => 's', 'days' => 3, 'word' => 'three', 'ordinal' => 'third', 'when' => array() ) ) ) ) );
		$GLOBALS['zdz_test_filters']['zagr_pack_dirs'] = static function ( $d ) use ( $dir ) { return array_merge( (array) $d, array( $dir ) ); };
		try {
			ZAGR_Pack::load( 'xx-test' );
			$this->fail( 'a window with no citation must not load' );
		} catch ( ZAGR_Pack_Error $e ) {
			$this->assertStringContainsString( 'citation', $e->getMessage() );
		}
		$this->assertNull( ZAGR_Pack::packs( array( 'xx-test' ) ), 'a stack holding a broken pack applies nothing' );
	}

	/* ── the checklist ─────────────────────────────────────────────────────── */

	private function rows( array $stack, string $mode, array $facts, array $uses = array(), array $declares = array(), ?array $doc = null, bool $lead = false ): array {
		return ZAGR_Checklist::rows( ZAGR_Pack::packs( $stack ), $mode, array(
			'facts' => $facts, 'template_uses' => $uses, 'template_declares' => $declares, 'lead_groups_ok' => $lead, 'doc' => $doc,
		) );
	}

	public function test_missing_facts_block_ready_to_sign(): void {
		$s    = array( 'us-federal', 'us-ca' );
		$uses = array( 'pack_statement_html', 'pack_cancellation_form_html', 'pack_first_page_html', 'signed_date' );
		$miss = ZAGR_Checklist::missing( $this->rows( $s, 'test', array(), $uses ) );
		$this->assertContains( 'the contractor\'s license number', $miss );
		$this->assertContains( 'the email address for cancellations', $miss );
		$this->assertContains( 'the phone number that helps the buyer with the Notice of Cancellation', $miss );
		$this->assertNotContains( 'the answer to whether a subcontractor will be used', $miss, 'needed only before customers sign' );
		$facts = array( 'license' => 'A-12345', 'cancel_email' => 'cancel@example.com', 'help_phone' => '555-0100', 'legal_name' => 'Example Co', 'address_lines' => array( '1 Example Street' ) );
		$this->assertSame( array(), ZAGR_Checklist::missing( $this->rows( $s, 'test', $facts, $uses ) ) );
		$on = ZAGR_Checklist::missing( $this->rows( $s, 'on', $facts, $uses ) );
		$this->assertContains( 'the answer to whether a subcontractor will be used', $on, 'never assumed: an unanswered box blocks' );
		$this->assertContains( 'your template\'s Mechanics Lien Warning (mechanics_lien_warning)', $on );
		$this->assertContains( 'lead times for the approximate start and completion dates', $on );
	}

	public function test_bad_formats_do_not_pass(): void {
		$this->assertFalse( ZAGR_Checklist::fact_ok( 'cancel_email', 'not an address' ) );
		$this->assertFalse( ZAGR_Checklist::fact_ok( 'help_phone', '12' ) );
		$this->assertFalse( ZAGR_Checklist::fact_ok( 'subcontractors', 'maybe' ) );
		$this->assertTrue( ZAGR_Checklist::fact_ok( 'subcontractors', 'no' ) );
		$this->assertTrue( ZAGR_Checklist::fact_ok( 'address_lines', array( '1 Example Street' ) ) );
		$this->assertFalse( ZAGR_Checklist::fact_ok( 'address_lines', array( '  ' ) ) );
	}

	public function test_per_agreement_items_hold_back_only_that_agreement(): void {
		$s      = array( 'us-federal', 'us-ca' );
		$global = $this->rows( $s, 'test', array() );
		$job    = array_values( array_filter( $global, static function ( $r ) { return 'fed_job_state' === $r['id']; } ) )[0];
		$this->assertTrue( $job['ok'] && $job['per_agreement'], 'on the settings screen, asked per agreement' );
		$doc  = array( 'region' => '', 'answers' => array( 'disaster' => '' ), 'families' => array() );
		$miss = ZAGR_Checklist::missing( $this->rows( $s, 'test', array(), array(), array(), $doc ) );
		$this->assertContains( 'the state of the job\'s address (which state\'s rules apply)', $miss );
		$this->assertContains( 'the staff answer to whether the work repairs damage from a declared disaster', $miss );
		$doc  = array( 'region' => 'CA', 'answers' => array( 'disaster' => 'no' ), 'families' => array() );
		$miss = ZAGR_Checklist::missing( $this->rows( $s, 'test', array(), array(), array(), $doc ) );
		$this->assertNotContains( 'the staff answer to whether the work repairs damage from a declared disaster', $miss );
	}

	public function test_an_unknown_item_kind_never_passes(): void {
		$rows = ZAGR_Checklist::rows( array( array( 'checklist' => array( array( 'id' => 'odd', 'label' => 'odd', 'kind' => 'magic', 'needed_in' => array( 'test' ) ) ) ) ), 'test', array() );
		$this->assertSame( array( 'odd' ), ZAGR_Checklist::missing( $rows ) );
	}

	/* ── templates ─────────────────────────────────────────────────────────── */

	private function copy_starter( string $as ): string {
		$dir = $this->scratch() . '/templates/' . $as;
		mkdir( $dir, 0700, true );
		foreach ( glob( ZAGR_DIR . 'templates/starter-1/*' ) as $f ) {
			copy( $f, $dir . '/' . basename( $f ) );
		}
		$m              = json_decode( file_get_contents( $dir . '/manifest.json' ), true );
		$m['version']   = $as;
		$m['starter']   = false;
		$m['statutory'] = array( 'mechanics_lien_warning' );
		file_put_contents( $dir . '/manifest.json', json_encode( $m ) );
		$root = dirname( $dir );
		$GLOBALS['zdz_test_filters']['zagr_templates_dirs'] = static function () use ( $root ) { return array( $root ); };
		return $dir;
	}

	public function test_the_starter_template_loads_and_is_marked_as_a_starter(): void {
		$t = ZAGR_Template::load( 'starter-1' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $t['files_hash'] );
		$this->assertTrue( $t['starter'] );
		$this->assertTrue( $t['has_todo'], 'a starter never reaches customers' );
		foreach ( array( 'pack_statement_html', 'pack_cancellation_form_html', 'pack_first_page_html', 'signed_date' ) as $ph ) {
			$this->assertContains( $ph, $t['uses'] );
		}
	}

	public function test_a_fingerprint_follows_every_byte(): void {
		$dir = $this->copy_starter( 'example-1' );
		$a   = ZAGR_Template::load( 'example-1' );
		$this->assertNotSame( ZAGR_Template::load( 'starter-1' )['files_hash'], $a['files_hash'] );
		$this->assertSame( array( 'mechanics_lien_warning' ), $a['statutory'] );
		file_put_contents( $dir . '/schedule.html', file_get_contents( $dir . '/schedule.html' ) . ' ' );
		ZAGR_Template::forget();
		$this->assertNotSame( $a['files_hash'], ZAGR_Template::load( 'example-1' )['files_hash'] );
	}

	public function test_a_template_with_a_script_or_a_misplaced_notice_is_refused(): void {
		$dir = $this->copy_starter( 'example-2' );
		file_put_contents( $dir . '/notices.html', '<h2>Notices</h2><script>alert(1)</script>' );
		try {
			ZAGR_Template::load( 'example-2' );
			$this->fail( 'a script must not load' );
		} catch ( ZAGR_Template_Error $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}
		ZAGR_Template::forget();
		file_put_contents( $dir . '/notices.html', '<h2>Notices</h2>{{pack_statement_html}}' );
		try {
			ZAGR_Template::load( 'example-2' );
			$this->fail( 'a notice drawn before the buyer\'s answer must not load' );
		} catch ( ZAGR_Template_Error $e ) {
			$this->assertStringContainsString( 'age_sections', $e->getMessage() );
		}
	}

	public function test_an_approval_names_the_files_and_the_approver(): void {
		$dir = $this->copy_starter( 'example-3' );
		$GLOBALS['zdz_test_user_caps'][7]  = array( 'manage_options' );
		$GLOBALS['zdz_test_user_roles'][7] = array( 'administrator' );
		$GLOBALS['zdz_test_options'][ ZAGR_Settings::OPT_TEMPLATE ] = array( 'version' => 'starter-1' );
		$starter = ZAGR_Template::load( 'starter-1' );
		$r = ZAGR_Settings::approve_template( 'starter-1', $starter['files_hash'], 7, true, 'Robin Example' );
		$this->assertFalse( $r['ok'], 'the starter is never approvable' );
		$GLOBALS['zdz_test_options'][ ZAGR_Settings::OPT_TEMPLATE ] = array( 'version' => 'example-3' );
		$t = ZAGR_Template::load( 'example-3' );
		$this->assertFalse( ZAGR_Settings::approve_template( 'example-3', $t['files_hash'], 7, true, '' )['ok'], 'an approver must be named' );
		$this->assertFalse( ZAGR_Settings::approve_template( 'example-3', $t['files_hash'], 7, false, 'Robin Example' )['ok'], 'and confirmed' );
		$this->assertFalse( ZAGR_Settings::approve_template( 'example-3', str_repeat( 'a', 64 ), 7, true, 'Robin Example' )['ok'], 'only the files shown' );
		$this->assertFalse( ZAGR_Settings::approve_template( 'example-3', $t['files_hash'], 8, true, 'Robin Example' )['ok'], 'only an administrator' );
		$this->assertTrue( ZAGR_Settings::approve_template( 'example-3', $t['files_hash'], 7, true, 'Robin Example' )['ok'] );
		$this->assertTrue( ZAGR_Settings::template_approved() );
		$this->assertSame( 'Robin Example', ZAGR_Settings::approval( 'example-3' )['approver'] );
		file_put_contents( $dir . '/project.html', file_get_contents( $dir . '/project.html' ) . "\n" );
		ZAGR_Template::forget();
		$this->assertFalse( ZAGR_Settings::template_approved(), 'files edited after approval are no longer approved' );
	}

	/* ── one agreement's dates ─────────────────────────────────────────────── */

	public function test_an_agreement_reads_its_own_frozen_packs_and_answers(): void {
		$doc = array( 'packs' => array( 'us-federal', 'us-ca' ), 'answers' => array( 'disaster' => 'no' ) );
		$this->assertTrue( ZAGR_Document::asks_age( $doc ) );
		$this->assertSame( '', ZAGR_Document::norm_age( $doc, '' ) );
		$this->assertSame( 0, ZAGR_Document::window_days( $doc, '' ), 'no window until the buyer answers' );
		$this->assertSame( 5, ZAGR_Document::window_days( $doc, 'yes' ) );
		$this->assertSame( '2026-11-07', ZAGR_Document::deadline( $doc, '2026-11-02', 'yes' ) );
		$fed = array( 'packs' => array( 'us-federal' ), 'answers' => array() );
		$this->assertFalse( ZAGR_Document::asks_age( $fed ) );
		$this->assertSame( 'na', ZAGR_Document::norm_age( $fed, '' ) );
		$this->assertSame( '2026-11-05', ZAGR_Document::deadline( $fed, '2026-11-02', 'na' ) );
	}

	public function test_the_period_ends_at_local_midnight(): void {
		$end = ZAGR_Calendar::end_ts( '2026-11-05' );
		$this->assertSame( '2026-11-05 23:59:59', ( new DateTimeImmutable( '@' . $end ) )->setTimezone( new DateTimeZone( 'America/Chicago' ) )->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( ZAGR_Calendar::window_open( '2026-11-05', $end ) );
		$this->assertFalse( ZAGR_Calendar::window_open( '2026-11-05', $end + 1 ) );
	}

	/* ── the request and the words drawn ───────────────────────────────────── */

	private function request( string $city_line, int $deposit, int $total = 500000, array $more = array() ): array {
		$doc   = array( 'number' => '1001', 'date' => '2026-11-02', 'lines' => array( array( 'name' => 'Example work', 'description' => 'As described', 'qty' => 1, 'amount_cents' => $total ) ), 'contract_cents' => $total, 'tax_cents' => 0, 'discount_cents' => 0 );
		$sched = array( 'deposit_cents' => $deposit, 'balance_cents' => $total - $deposit, 'total_cents' => $total, 'plan_version' => 'p1' );
		$sp    = array( 'name' => 'Jordan Example', 'registration' => '' );
		return $more + array(
			'estimate_id' => 9, 'client_id' => 3, 'is_test' => true, 'language_ok' => true,
			'buyer'       => array( 'name' => 'Casey Example', 'email' => 'casey@example.com', 'address_lines' => array( '1 Example Street', $city_line ) ),
			'document'    => $doc, 'schedule' => $sched, 'salesperson' => $sp,
			'doc_hash'    => ZAGR_Document::doc_hash( $doc, $sched, $sp ),
		);
	}

	public function test_a_request_takes_the_packs_of_the_jobs_state(): void {
		$ok = ZAGR_Document::check( $this->request( 'Example City, CA 90001', 40000 ) );
		$this->assertTrue( $ok['ok'], (string) $ok['message'] );
		$this->assertSame( array( 'us-federal', 'us-ca' ), $ok['req']['packs'] );
		$this->assertSame( 'CA', $ok['req']['region'] );
		$this->assertSame( array( 'disaster' => '' ), $ok['req']['answers'], 'a staff answer not sent stays unanswered' );
		$over = ZAGR_Document::check( $this->request( 'Example City, CA 90001', 60000 ) );
		$this->assertFalse( $over['ok'], 'more than 10 percent in that state' );
		$fed = ZAGR_Document::check( $this->request( 'Springfield, IL 62701', 60000 ) );
		$this->assertTrue( $fed['ok'], 'the national pack sets no down payment limit' );
		$this->assertSame( array( 'us-federal' ), $fed['req']['packs'] );
		$yes = ZAGR_Document::check( $this->request( 'Example City, CA 90001', 40000, 500000, array( 'disaster_repair' => true ) ) );
		$this->assertSame( 'yes', $yes['req']['answers']['disaster'] );
		$alias = $this->request( 'Springfield, IL 62701', 100 );
		unset( $alias['language_ok'] );
		$alias['english'] = true;
		$this->assertTrue( ZAGR_Document::check( $alias )['ok'], 'the older key name is read the same way' );
		$this->assertTrue( ZAGR_Document::check( $alias )['req']['language_ok'], 'its answer is kept, not dropped' );
		$alias['english'] = false;
		$this->assertFalse( ZAGR_Document::check( $alias )['req']['language_ok'] );
		$bad              = $this->request( 'Springfield, IL 62701', 100 );
		$bad['doc_hash']  = str_repeat( '0', 64 );
		$this->assertSame( 'hash_mismatch', ZAGR_Document::check( $bad )['code'] );
	}

	private function frozen( array $req ): array {
		$r = ZAGR_Document::check( $req )['req'];
		return array(
			'document' => $r['document'], 'schedule' => $r['schedule'], 'salesperson' => $r['salesperson'], 'families' => array(),
			'buyer' => array( 'name' => $r['buyer']['name'], 'address_lines' => $r['buyer']['address_lines'] ),
			'region' => $r['region'], 'packs' => $r['packs'], 'answers' => $r['answers'],
			'facts' => array( 'company_name' => 'Example Co', 'address_lines' => array( '2 Example Road', 'Example City' ), 'license' => 'A-1', 'cancel_email' => 'cancel@example.com',
				'help_phone' => '555-0100', 'substantial_commencement' => '', 'cgl_insurer' => '', 'cgl_phone' => '', 'lead' => array(), 'subcontractors' => 'no', 'template_version' => 'starter-1', 'test' => true ),
		);
	}

	public function test_the_starter_draws_the_packs_notices(): void {
		$ca  = $this->frozen( $this->request( 'Example City, CA 90001', 40000, 500000, array( 'disaster_repair' => false ) ) );
		$out = ZAGR_Document::agreement( $ca, array( 'sign_date' => '2026-11-02', 'age' => 'yes', 'contractor_uid' => 0, 'contractor_signed_at' => '', 'url' => 'https://app.example.com/agreement/x/' ) );
		$this->assertStringContainsString( 'Five-Day Right to Cancel', $out['html'] );
		$this->assertStringContainsString( 'within five business days from the above date', $out['html'] );
		$this->assertStringContainsString( 'THE DOWNPAYMENT MAY NOT EXCEED', $out['html'] );
		$this->assertStringContainsString( 'cancel@example.com', $out['html'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $out['hash'] );
		$this->assertSame( 2, substr_count( $out['html'], '<h3>Notice of Cancellation</h3>' ), 'the form in duplicate' );
		$before = ZAGR_Document::before_age( $ca, array( 'sign_date' => '2026-11-02', 'url' => '' ) );
		$this->assertStringNotContainsString( 'Right to Cancel', implode( '', $before['sections'] ), 'no notice before the buyer answers' );
		$fed = $this->frozen( $this->request( 'Springfield, IL 62701', 100 ) );
		$out = ZAGR_Document::agreement( $fed, array( 'sign_date' => '2026-11-02', 'age' => 'na', 'contractor_uid' => 0, 'contractor_signed_at' => '', 'url' => '' ) );
		$this->assertStringContainsString( 'You, the buyer, may cancel this transaction at any time prior to midnight of the third business day', $out['html'] );
		$this->assertStringNotContainsString( 'DOWNPAYMENT', $out['html'] );
		try {
			ZAGR_Document::agreement( $ca, array( 'sign_date' => '2026-11-02', 'age' => '', 'contractor_uid' => 0, 'contractor_signed_at' => '', 'url' => '' ) );
			$this->fail( 'never drawn without the buyer\'s answer' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'answer', $e->getMessage() );
		}
	}
}
