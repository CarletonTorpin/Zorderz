<?php
/**
 * Estimates for an account in Privacy View (ZEST_Privacy_View, with the platform's ZDZ_Privacy_View).
 *
 *   - closed until an administrator opens it; opened, the account has four reads and nothing else of this app: the
 *     platform is told about exactly those, with the only fields each may carry, and no REST route;
 *   - every request this app registers starts "zest", so the app's own guard covers each of them;
 *   - the app's part is hooked as its files load, before any app boots;
 *   - every write is refused for the account in the handler itself, whatever any list says;
 *   - a reply is built again from a list of fields: the customer, the number, the street and every team member are
 *     pseudonyms; no e-mail, phone, town, postal code or price; nothing a person typed or dictated;
 *   - a sentence about a follow-up is written again around pseudonyms from closed lists, never passed on;
 *   - the platform's own masking leaves such a reply as it is;
 *   - "not there" and "not yours" are one answer.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimatePrivacyViewTest extends TestCase {

	const ALEX  = 61; // sales, AA
	const CASEY = 65; // owner, CC
	const PV    = 70; // the account in Privacy View (an owner's role, so it sees every estimate, as pseudonyms)
	const LOOK  = 71; // another account in Privacy View, with a salesperson's role

	const CODE = '/\A[BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{3}·[0-9A-Z]{4}[0-9A-Z_]\z/u';

	/** @var ZDZ_Test_EstFu_DB */
	private $db;

	protected function setUp(): void {
		$this->db = zdz_test_estfu_people( array(
			self::ALEX => array( 'zdz_sales', 'AA' ), self::CASEY => array( 'zdz_owner', 'CC' ), self::PV => array( 'zdz_owner', 'PV' ), self::LOOK => array( 'zdz_sales', 'LK' ),
		) );
		$GLOBALS['zdz_test_user_meta'][ self::PV ]['zdz_privacy_view']   = '1';
		$GLOBALS['zdz_test_user_meta'][ self::LOOK ]['zdz_privacy_view'] = '1';
		$GLOBALS['zdz_test_current_user']                                = self::PV;
		$coder = new ReflectionProperty( 'ZDZ_Privacy_View', 'coder' );
		$coder->setAccessible( true );
		$coder->setValue( null, new ZDZ_Test_EstFu_Coder() );
		ZDZ_Privacy_View::new_view( 'Bcdf' );
	}

	protected function tearDown(): void {
		zdz_test_estfu_teardown();
	}

	private function open(): void {
		$GLOBALS['zdz_test_options'][ ZEST_Privacy_View::MODE_OPTION ] = 'look';
	}

	/** The platform's own filters, answered by the app as they are on a site. */
	private function hooked(): void {
		foreach ( array( 'allowed_ajax', 'ajax_keys', 'allowed_apps', 'allowed_rest' ) as $f ) {
			$GLOBALS['zdz_test_filters'][ 'zdz_privacy_view_' . $f ] = array( 'ZEST_Privacy_View', $f );
		}
	}

	private static function app_file( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/' . $rel );
	}

	/** Every admin-ajax action the app's PHP registers, read from its files. */
	private static function registered_actions(): array {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate';
		$php = self::app_file( 'app.php' );
		foreach ( glob( $dir . '/includes/*.php' ) as $f ) {
			$php .= "\n" . file_get_contents( $f );
		}
		preg_match_all( "/'wp_ajax_(?:nopriv_)?([a-z0-9_]+)'/", $php, $literal );
		preg_match_all( "/^\s*'(zest_[a-z0-9_]+)'\s*=>\s*'ajax_[a-z_]+',/m", $php, $table );
		$all = array_values( array_unique( array_merge( $literal[1], $table[1] ) ) );
		sort( $all );
		return $all;
	}

	/* ── closed, then four reads ─────────────────────────────────────────── */

	public function test_estimates_is_closed_to_the_account_until_an_administrator_opens_it(): void {
		$this->hooked();
		$this->assertTrue( ZEST_Privacy_View::on() );
		$this->assertFalse( ZEST_Privacy_View::on( self::ALEX ) );
		$this->assertFalse( ZEST_Privacy_View::open() );
		foreach ( array_keys( ZEST_Privacy_View::READS ) as $a ) {
			$this->assertFalse( ZEST_Privacy_View::may( $a ), $a );
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $a ), 'the platform keeps it shut too: ' . $a );
		}
		$this->assertSame( array( 'x' ), ZEST_Privacy_View::allowed_ajax( array( 'x' ) ) );
		$this->assertSame( array(), ZEST_Privacy_View::ajax_keys( array() ) );
		$this->assertSame( array( 'projects' ), ZEST_Privacy_View::allowed_apps( array( 'projects' ) ) );
		$this->assertFalse( ZEST_Privacy_View::may_look( self::PV ) );
		$GLOBALS['zdz_test_options'][ ZEST_Privacy_View::MODE_OPTION ] = 'on';
		$this->assertFalse( ZEST_Privacy_View::open(), 'only the exact value opens it' );
		$this->assertFalse( ZEST_Privacy_View::may_look( self::PV ) );
		$this->assertFalse( ZEST_Privacy_View::may_look( 0 ) );
	}

	public function test_opened_the_account_has_the_four_reads_and_nothing_else(): void {
		$this->open();
		$this->hooked();
		$this->assertSame( array( 'zest_list_open', 'zest_history', 'zest_fu_history', 'zest_pv_estimate' ), array_keys( ZEST_Privacy_View::READS ) );
		$all = self::registered_actions();
		$this->assertGreaterThan( 40, count( $all ), 'every request the app registers is read from its files' );
		$this->assertContains( 'zest_create', $all );
		$this->assertContains( 'zest_fu_send', $all );
		$this->assertContains( 'zest_bv_save', $all );
		$this->assertContains( 'zest_test_estimate_link', $all );
		$open = array();
		foreach ( $all as $a ) {
			$this->assertTrue( ZEST_Privacy_View::ours( $a ), 'the guard knows it as one of the app\'s: ' . $a );
			if ( ZEST_Privacy_View::may( $a ) ) {
				$open[] = $a;
			}
			$this->assertSame( ZEST_Privacy_View::may( $a ), ZDZ_Privacy_View::ajax_allowed( $a ), 'the platform and the app agree on ' . $a );
		}
		$this->assertSame( array( 'zest_fu_history', 'zest_history', 'zest_list_open', 'zest_pv_estimate' ), $open, 'for the account, only the four go through' );
		foreach ( array( 'ZEST_FU_SEND', 'zest_fu_send ', "zest_fu_send\0", 'zest_history_all', 'zest_pv_estimate2', 'zest', 'zest_', '' ) as $a ) {
			$this->assertFalse( ZEST_Privacy_View::may( $a ), var_export( $a, true ) );
		}
		$this->assertFalse( ZEST_Privacy_View::ours( 'zana_send_message' ), 'another app\'s request is not this guard\'s to refuse' );
	}

	public function test_the_platform_is_told_the_fields_the_app_and_no_rest_route(): void {
		$this->open();
		$keys = ZEST_Privacy_View::ajax_keys( array( 'zana_send_message' => array( 'message' ) ) );
		$this->assertSame( array( 'message' ), $keys['zana_send_message'], 'what was there stays' );
		$this->assertSame( array(), $keys['zest_list_open'] );
		$this->assertSame( array(), $keys['zest_history'] );
		$this->assertSame( array( 'id' ), $keys['zest_fu_history'] );
		$this->assertSame( array( 'id' ), $keys['zest_pv_estimate'], 'by this app\'s row id only: never a number or a name' );
		$this->assertSame( array( 'projects', 'estimate-creator' ), ZEST_Privacy_View::allowed_apps( array( 'projects' ) ) );
		$this->assertSame( array( 'estimate-creator' ), ZEST_Privacy_View::allowed_apps( array( 'estimate-creator' ) ), 'once' );
		$routes = array( '/zorderz/v1/consent', '/zorderz/v1/estimate/import', 'zorderz/v1/invoice/7/payment', '/zorderz/v1/estimate/7/convert', '/zorderz/v1/notifications/unread-count' );
		$this->assertSame( array( '/zorderz/v1/consent', '/zorderz/v1/notifications/unread-count' ), ZEST_Privacy_View::allowed_rest( $routes ), 'no REST route of this app, whatever was added' );
	}

	public function test_the_apps_part_is_hooked_as_its_files_load(): void {
		$app = self::app_file( 'app.php' );
		$at  = strpos( $app, 'ZEST_Privacy_View::boot();' );
		$this->assertNotSame( false, $at );
		$this->assertLessThan( strpos( $app, "add_action( 'plugins_loaded'" ), $at, 'before any app boots: the platform\'s gate runs first' );
		$this->assertLessThan( $at, strpos( $app, "require_once ZEST_DIR . 'includes/class-zest-privacy-view.php';" ) );
		$this->assertSame( "\n", $app[ $at - 1 ], 'at the top level of the file, not inside a callback' );
		$pv = self::app_file( 'includes/class-zest-privacy-view.php' );
		foreach ( array( 'zdz_privacy_view_allowed_ajax', 'zdz_privacy_view_ajax_keys', 'zdz_privacy_view_allowed_apps', 'zdz_privacy_view_allowed_rest' ) as $hook ) {
			$this->assertStringContainsString( "add_filter( '" . $hook . "'", $pv );
		}
		$this->assertStringContainsString( "add_action( 'admin_init', array( __CLASS__, 'guard' ), 0 );", $pv, 'the guard runs before any handler' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_zest_pv_estimate'", $pv );
	}

	/* ── every write refused, in the handler itself ──────────────────────── */

	public function test_the_follow_up_handlers_refuse_the_account_directly(): void {
		$this->open();
		$est = $this->db->estimate();
		$row = (array) ZEST_Access::row( $est );
		foreach ( array(
			ZEST_Followup_Ajax::send( $row, self::PV, array( 'to' => self::ALEX, 'expect' => '0' ) ),
			ZEST_Followup_Ajax::answer( $row, self::PV, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'expect' => '0' ) ),
			ZEST_Followup_Ajax::reopen( $row, self::PV, '0' ),
			ZEST_Followup_Ajax::undo( $row, self::PV, '0' ),
			ZEST_Followup_Ajax::line_retry( 1, self::PV ),
		) as $r ) {
			$this->assertFalse( $r['ok'] );
			$this->assertSame( 'zdz_privacy_view', $r['code'] );
			$this->assertSame( 403, $r['status'] );
		}
		$this->assertNull( ZEST_Followup::latest( $est ), 'nothing was recorded' );
		$this->assertSame( array( 'code' => 'zdz_privacy_view', 'message' => 'Not available in Privacy View.' ), ZEST_Privacy_View::refusal(), 'the refusal the platform itself gives' );
	}

	public function test_the_other_handlers_carry_the_refusal_too(): void {
		$this->assertMatchesRegularExpression( '/function no_kiosk\(.*?ZEST_Privacy_View::on\( \$uid \).*?wp_send_json_error\( ZEST_Privacy_View::refusal\(\), 403 \)/s', self::app_file( 'includes/class-zest-access.php' ),
			'what the shared device may not do, the account may not do' );
		$dash = self::app_file( 'includes/class-zest-dashboard.php' );
		$this->assertMatchesRegularExpression( '/private static function guard\( bool \$look = false \).*?ZEST_Privacy_View::on\(\) && ! \( \$look && ZEST_Privacy_View::may_look\( \(int\) get_current_user_id\(\) \) \).*?wp_send_json_error\( ZEST_Privacy_View::refusal\(\), 403 \)/s', $dash );
		$this->assertSame( 2, substr_count( $dash, 'self::guard( true )' ), 'only the two lists are reads the account may make there' );
		$this->assertMatchesRegularExpression( '/function ajax_list_open\(\): void \{\s+\$uid = self::guard\( true \);\s+wp_send_json_success\( ZEST_Lists::reply\( \$uid, \'open\' \) \);/', $dash );
		$this->assertMatchesRegularExpression( '/function ajax_history\(\): void \{\s+\$uid = self::guard\( true \);\s+wp_send_json_success\( ZEST_Lists::reply\( \$uid, \'history\' \) \);/', $dash );
		$this->assertMatchesRegularExpression( '/function save_profile_fields.*?current_user_can\( \'edit_user\'.*?ZEST_Privacy_View::on\(\) \) \{\s+return;.*?update_user_meta/s', self::app_file( 'includes/class-zest-admin.php' ),
			'its short code decides whose an estimate is: it cannot change it' );
		$this->assertMatchesRegularExpression( '/function ajax_test_estimate_link.*?manage_options.*?ZEST_Privacy_View::no_writes\(\).*?probe_customer_link/s', self::app_file( 'includes/class-zest-settings.php' ) );
		$fu = self::app_file( 'includes/class-zest-followup-ajax.php' );
		$this->assertMatchesRegularExpression( '/if \( \$write \|\| ! ZEST_Privacy_View::open\(\) \) \{\s+wp_send_json_error\( ZEST_Privacy_View::refusal\(\), 403 \);/', $fu );
		$this->assertMatchesRegularExpression( '/\} else \{\s+\/\/[^\n]*\n\s+\/\/[^\n]*\n\s+ZEST_Followup::ensure_table\(\);/', $fu, 'nothing is written on view: not even the table being made' );
		$app = self::app_file( 'app.php' );
		$this->assertSame( 0, substr_count( $app, "'permission_callback' => function" ), 'every REST route of the app asks the same question' );
		$this->assertGreaterThan( 5, substr_count( $app, "'permission_callback' => \$admin" ) + substr_count( $app, "'permission_callback' => 'zest_rest_admin_only'" ) );
		$this->assertStringContainsString( "return current_user_can( 'manage_options' ) && ! ZEST_Privacy_View::on();", $app );
		$this->assertSame( 3, substr_count( $app, "\tzest_no_privacy_view_page();\n" ), 'the three printable pages' );
	}

	/* ── a follow-up, written again ──────────────────────────────────────── */

	public function test_a_follow_up_is_written_again_around_pseudonyms(): void {
		$alex  = ZEST_Privacy_View::staff( self::ALEX );
		$casey = ZEST_Privacy_View::staff( self::CASEY );
		$this->assertMatchesRegularExpression( self::CODE, $alex );
		$this->assertNotSame( $alex, $casey );
		$v = ZEST_Privacy_View::followup( array(
			'id' => 4, 'token' => '4:done:1:0:0', 'state' => 'done', 'to' => $alex, 'by' => $casey, 'asked' => '2026-01-05 10:00:00', 'answer' => 'reached',
			'outcome' => 'Whatever the handler said', 'outcome_code' => 'deciding', 'tries' => 1, 'who' => $alex, 'when' => '2026-01-06 11:30:00', 'declined' => false,
			'mine' => true, 'by_me' => true, 'reopened' => false, 'reopened_by' => '', 'words' => 'Jordan Blake rang Morgan Example on 555-0100',
			'doc_state' => 'skipped', 'doc_why' => 'Morgan Example asked us not to', 'doc_why_code' => 'mode_test', 'test' => false, 'something_new' => 'a field added later',
		) );
		$this->assertSame( $alex . ' reached the customer: Still deciding', $v['words'], 'from the state, the answer and the outcome code: nothing of the words sent' );
		$this->assertSame( 'Still deciding', $v['outcome'] );
		$this->assertSame( '', $v['token'], 'nothing it could act with' );
		$this->assertFalse( $v['mine'] );
		$this->assertFalse( $v['by_me'] );
		$this->assertSame( ZEST_Followup::why_words( 'mode_test' ), $v['doc_why'], 'from the code only' );
		$this->assertFalse( array_key_exists( 'something_new', $v ), 'a field added to the handler later is not sent until it is added here' );
		$this->assertStringNotContainsString( 'Morgan', json_encode( $v ) );
		$this->assertStringNotContainsString( 'Jordan', json_encode( $v ) );

		$v = ZEST_Privacy_View::followup( array( 'id' => 5, 'state' => 'open', 'to' => 'AA', 'by' => 'Casey Robin', 'who' => array( 'x' ), 'outcome_code' => 'made_up', 'answer' => 'sold', 'doc_state' => 'on fire', 'doc_why_code' => 'Not A Code' ) );
		$this->assertSame( '[name]', $v['to'], 'a code or a name that is not a pseudonym is never passed on' );
		$this->assertSame( '[name]', $v['by'] );
		$this->assertSame( '[name]', $v['who'] );
		$this->assertSame( '[name] asked [name] to follow up', $v['words'] );
		$this->assertSame( '', $v['outcome_code'] );
		$this->assertSame( '', $v['answer'] );
		$this->assertSame( '', $v['doc_state'] );
		$this->assertSame( '', $v['doc_why'] );
		$this->assertSame( '', ZEST_Privacy_View::followup( array( 'state' => 'exploded' ) )['state'] );
		$this->assertNull( ZEST_Privacy_View::followup( null ) );
		$this->assertNull( ZEST_Privacy_View::followup( 'text' ) );

		$v = ZEST_Privacy_View::followup( array( 'state' => 'done', 'answer' => 'reached', 'outcome_code' => 'not_needed', 'who' => $alex, 'declined' => false, 'reopened' => true, 'reopened_by' => $casey ) );
		$this->assertSame( '; reopened by ' . $casey, substr( $v['words'], -strlen( '; reopened by ' . $casey ) ) );
	}

	public function test_the_history_hides_everything_typed(): void {
		$this->open();
		$est = $this->db->estimate();
		$row = (array) ZEST_Access::row( $est );
		$GLOBALS['zdz_test_current_user'] = self::CASEY;   // the team records a follow-up
		$GLOBALS['zdz_test_options']['zest_followup_line_mode'] = 'off';
		ZEST_Followup_Ajax::send( $row, self::CASEY, array( 'to' => self::ALEX, 'note' => 'call Morgan Example on 555-0100', 'expect' => '0' ) );
		ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'answer' => 'tried', 'channel' => 'text', 'note' => 'texted Morgan', 'expect' => ZEST_Followup::token( ZEST_Followup::latest( $est ) ) ) );
		ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'note' => 'Morgan will ring back', 'expect' => ZEST_Followup::token( ZEST_Followup::latest( $est ) ) ) );
		ZEST_Followup_Ajax::undo( $row, self::ALEX, ZEST_Followup::token( ZEST_Followup::latest( $est ) ) );
		ZEST_Followup::mark( (int) ZEST_Followup::latest( $est )['id'], array( 'doc_state' => 'written', 'doc_line' => 'Follow-up 6/1/2026 (AA): Customer is still deciding.' ) );

		$GLOBALS['zdz_test_current_user'] = self::PV;
		$h = ZEST_Followup_Ajax::history( $est, self::PV );
		$this->assertTrue( $h['ok'] );
		$this->assertTrue( $h['privacy_view'] );
		$this->assertMatchesRegularExpression( self::CODE, $h['number'], 'the number is a pseudonym' );
		$r = $h['rows'][0];
		$this->assertSame( ZEST_Privacy_View::HIDDEN, $r['ask_note'] );
		$this->assertSame( ZEST_Privacy_View::HIDDEN, $r['tried'][0]['note'] );
		$this->assertSame( 'Text', $r['tried'][0]['channel'] );
		$this->assertMatchesRegularExpression( self::CODE, $r['tried'][0]['who'] );
		$this->assertSame( 'Follow-up 6/1/2026: Customer is still deciding.', $r['doc_line'], 'the app\'s own fixed line, without the person\'s code' );
		$this->assertSame( ZEST_Privacy_View::HIDDEN, $r['undone'][0]['note'] );
		$this->assertSame( 'an answer', $r['undone'][0]['words'] );
		$this->assertMatchesRegularExpression( self::CODE, $r['undone'][0]['by'] );
		$all = json_encode( $h );
		foreach ( array( 'Morgan', '555-0100', 'ring back', '1001', '(AA)', '"AA"', '"CC"', 'Person 6' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $all, $leak );
		}

		// "not there" and "not yours" are one answer, for an account that could otherwise test which ids exist
		$this->assertSame( array( false, 404 ), array( ZEST_Followup_Ajax::history( 999, self::PV )['ok'], ZEST_Followup_Ajax::history( 999, self::PV )['status'] ) );
		$mine = ZEST_Followup_Ajax::history( $est, self::LOOK );
		$this->assertSame( array( false, 404, ZEST_Followup_Ajax::history( 999, self::PV )['message'] ), array( $mine['ok'], $mine['status'], $mine['message'] ) );
	}

	public function test_the_platforms_masking_leaves_every_fixed_sentence_as_it_is(): void {
		$alex  = ZEST_Privacy_View::staff( self::ALEX );
		$casey = ZEST_Privacy_View::staff( self::CASEY );
		$whys  = array( 'no_line', 'bad_line', 'not_connected', 'local', 'mode_off', 'mode_test', 'busy', 'taken_back', 'unread', 'other_doc', 'customer_unread', 'customer_old', 'customer',
			'archived', 'accepted', 'not_open', 'no_lines', 'history', 'refused', 'not_last', 'still_there', 'undo_unfinished', 'error', 'differs:3', 'differs:0' );
		$views = array();
		foreach ( array_keys( ZEST_Followup::OUTCOMES ) as $i => $code ) {
			foreach ( array( 'reached', 'handled' ) as $answer ) {
				$views[] = array( 'id' => 1, 'state' => 'done', 'answer' => $answer, 'outcome_code' => $code, 'to' => $alex, 'by' => $casey, 'who' => $alex, 'tries' => 2,
					'declined' => ZEST_Followup::is_declined( $code ), 'reopened' => 0 === $i % 2, 'reopened_by' => $casey, 'doc_state' => 'skipped', 'doc_why_code' => $whys[ $i ] );
			}
		}
		foreach ( $whys as $why ) {
			$views[] = array( 'id' => 2, 'state' => 'tried', 'answer' => 'tried', 'to' => $alex, 'by' => '', 'who' => '', 'tries' => 3, 'doc_state' => 'failed', 'doc_why_code' => $why );
		}
		$views[] = array( 'id' => 3, 'state' => 'open', 'to' => '', 'by' => '', 'who' => '' );
		foreach ( $views as $view ) {
			$v = ZEST_Privacy_View::followup( $view );
			$this->assertNotSame( '', $v['words'] );
			$this->assertSame( $v, ZDZ_Privacy_View::mask_value( $v ), $v['words'] . ' / ' . $v['doc_why'] );
		}
		$tried = array();
		foreach ( ZEST_Followup::channels() as $label ) {
			$tried[] = array( 'when' => '2026-01-05 10:00:00', 'who' => $alex, 'channel' => $label, 'note' => 'typed' );
		}
		$h = ZEST_Privacy_View::fu_history( array( 'number' => '1001', 'rows' => array( $views[0] + array(
			'ask_note' => 'typed', 'note' => 'typed', 'channel' => 'Call', 'tried' => $tried, 'doc_line' => 'Follow-up 6/1/2026 (AA): Customer chose another provider.', 'crm' => 'posted',
			'undone' => array( array( 'kind' => 'ask', 'by' => $casey, 'when' => '2026-01-05 10:00:00', 'note' => 'typed' ), array( 'kind' => 'try', 'by' => $alex ), array( 'kind' => 'answer', 'by' => $alex ) ),
		) ) ) );
		$this->assertCount( 5, $h['rows'][0]['tried'] );
		$this->assertSame( $h, ZDZ_Privacy_View::mask_value( $h ) );
		$this->assertStringNotContainsString( 'typed', json_encode( $h ) );
	}

	public function test_a_line_that_is_not_the_apps_own_is_never_shown(): void {
		$this->assertSame( '', ZEST_Privacy_View::doc_line( 'Follow-up 6/1/2026 (AA): Morgan said to call back' ) );
		$this->assertSame( '', ZEST_Privacy_View::doc_line( array( 'x' ) ) );
		$this->assertSame( '', ZEST_Privacy_View::hidden( '  ' ) );
		$this->assertSame( ZEST_Privacy_View::HIDDEN, ZEST_Privacy_View::hidden( array( 'typed' ) ) );
		$this->assertSame( '', ZEST_Privacy_View::status( 'Morgan' ) );
		$this->assertSame( 'sent', ZEST_Privacy_View::status( 'sent' ) );
		$this->assertSame( '', ZEST_Privacy_View::when( '2026-01-05 Morgan' ) );
		$this->assertSame( '2026-01-05 10:00:00', ZEST_Privacy_View::when( '2026-01-05 10:00:00' ) );
		$this->assertSame( '', ZEST_Privacy_View::customer( '  ' ) );
		$this->assertSame( '', ZEST_Privacy_View::number( '#' ) );
		$this->assertSame( '', ZEST_Privacy_View::staff( 0 ) );
	}

	/* ── one estimate, and the lists ─────────────────────────────────────── */

	private function full_estimate(): int {
		return $this->db->estimate( array(
			'customer_name' => 'Morgan Example', 'customer_org' => 'Example Co', 'customer_email' => 'morgan@example.com', 'customer_phone' => '555-0100',
			'customer_street' => '12 Sample St', 'customer_city' => 'Sampletown', 'customer_state' => 'ca', 'customer_zip' => '00000', 'crm_lead_id' => '900', 'crm_contact_id' => '44',
			'salesperson' => 'AA & ZZ', 'created_by' => self::CASEY, 'approved_by' => self::CASEY, 'approved_at' => '2026-01-05 12:00:00', 'sent_at' => '2026-01-05 13:00:00',
			'items_json' => json_encode( array(
				array( 'description' => 'Widget for Morgan', 'sub_description' => 'gate code 4321', 'unit_price' => 125.5, 'quantity' => 3 ),
				array( 'description' => '', 'sub_description' => '', 'unit_price' => 0, 'quantity' => 1 ),
			) ),
			'rejected_json' => json_encode( array( array( 'description' => 'left out' ) ) ),
			'notes' => "Call Morgan first.\nFollow-up 6/1/2026 (AA): Customer will call back.\nSubmitted by: AA",
		) );
	}

	public function test_one_estimate_to_look_at_carries_no_name_no_contact_no_price_and_no_words(): void {
		$this->open();
		$est = $this->full_estimate();
		$r   = ZEST_Privacy_View::look( $est, self::PV );
		$this->assertTrue( $r['privacy_view'] );
		$e = $r['estimate'];
		$this->assertMatchesRegularExpression( self::CODE, $e['customer'] );
		$this->assertMatchesRegularExpression( self::CODE, $e['number'] );
		$this->assertMatchesRegularExpression( self::CODE, $e['street'] );
		$this->assertMatchesRegularExpression( self::CODE, $e['approved_by'] );
		$this->assertSame( ZEST_Privacy_View::staff( self::ALEX ) . ' & [name]', $e['salesperson'], 'a code that names nobody on the team is "[name]", never the code' );
		$this->assertSame( 'CA', $e['state'] );
		$this->assertTrue( $e['has_email'] );
		$this->assertTrue( $e['has_phone'] );
		$this->assertTrue( $e['in_billing'] );
		$this->assertTrue( $e['in_crm'] );
		$this->assertSame( array( array( 'words' => true, 'qty' => 3.0 ), array( 'words' => false, 'qty' => 1.0 ) ), $e['lines'], 'a line is its quantity, and whether it has any words' );
		$this->assertSame( 1, $e['left_out'] );
		$this->assertSame( array( 'Follow-up 6/1/2026: Customer will call back.' ), $e['followup_lines'], 'of the notes, only the app\'s own fixed lines' );
		$this->assertSame( 2, $e['notes_hidden'] );
		$all = json_encode( $r );
		foreach ( array( 'Morgan', 'Example Co', 'example.com', '555-0100', 'Sample', '00000', '4321', '125.5', '376.5', '1001', '"900"', '"44"', 'Submitted', '(AA)', 'unit_price', 'total', 'customer_email', 'city', 'zip' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $all, $leak );
		}
		$this->assertSame( $r, ZDZ_Privacy_View::mask_value( $r ), 'the platform\'s own masking leaves it as it is' );
	}

	public function test_an_estimate_that_is_not_there_or_not_the_accounts_is_one_answer(): void {
		$this->open();
		$est = $this->full_estimate();
		$this->assertNull( ZEST_Privacy_View::look( 999, self::PV ) );
		$this->assertNull( ZEST_Privacy_View::look( $est, self::LOOK ), 'a salesperson\'s role sees its own estimates only, in Privacy View too' );
		$own = $this->db->estimate( array( 'created_by' => self::LOOK, 'billing_doc_num' => '1002' ) );
		$this->assertSame( $own, ZEST_Privacy_View::look( $own, self::LOOK )['estimate']['id'] );
	}

	public function test_the_lists_for_everyone_else_carry_each_estimates_follow_up(): void {
		$GLOBALS['zdz_test_current_user'] = self::CASEY;
		$draft = $this->db->estimate( array( 'status' => 'created', 'created_by' => self::ALEX, 'created_at' => '2026-01-01 10:00:00', 'billing_doc_num' => '1001' ) );
		$sent  = $this->db->estimate( array( 'status' => 'sent', 'created_by' => self::CASEY, 'salesperson' => 'CC', 'created_at' => '2026-01-02 10:00:00', 'billing_doc_num' => '1002' ) );
		$asked = $this->db->estimate( array( 'status' => 'sent', 'created_by' => self::CASEY, 'salesperson' => 'AA', 'created_at' => '2026-01-03 10:00:00', 'billing_doc_num' => '1003' ) );
		$won   = $this->db->estimate( array( 'status' => 'accepted', 'accepted_at' => '2026-01-04 09:00:00', 'created_by' => self::CASEY, 'salesperson' => 'AA', 'created_at' => '2026-01-04 10:00:00', 'billing_doc_num' => '1004' ) );
		$other = $this->db->estimate( array( 'status' => 'sent', 'created_by' => self::CASEY, 'salesperson' => 'CC', 'created_at' => '2026-01-05 10:00:00', 'billing_doc_num' => '1005' ) );
		ZEST_Followup::ask( array( 'estimate_id' => $asked, 'sent_by' => self::CASEY, 'sent_to' => self::ALEX ) );
		ZEST_Followup::ask( array( 'estimate_id' => $won, 'sent_by' => self::CASEY, 'sent_to' => self::ALEX ) );
		ZEST_Followup::ask( array( 'estimate_id' => $other, 'sent_by' => self::CASEY, 'sent_to' => self::ALEX ) );   // sent to Alex, who is not otherwise on it

		$open = ZEST_Lists::reply( self::CASEY, 'open' );
		$this->assertSame( array( $other, $asked, $draft ), array_column( $open['rows'], 'id' ), 'not yet sent, or sent and waiting on a follow-up; never an accepted one' );
		$this->assertTrue( $open['followups']['can_send'] );
		$row = $open['rows'][1];
		$this->assertSame( array( 'id', 'customer', 'number', 'status', 'created', 'items', 'followup', 'followup_history', 'can_follow', 'declined' ), array_keys( $row ) );
		$this->assertSame( 'Morgan Example', $row['customer'] );
		$this->assertSame( 'open', $row['followup']['state'] );
		$this->assertTrue( $row['followup_history'] );
		$this->assertTrue( $row['can_follow'] );
		$this->assertNull( $open['rows'][2]['followup'] );
		$this->assertTrue( $open['rows'][2]['can_follow'], 'anyone records a follow-up on an open estimate of their own' );

		$mine = ZEST_Lists::reply( self::ALEX, 'open' );
		$this->assertSame( array( $other, $asked, $draft ), array_column( $mine['rows'], 'id' ), 'the ask itself puts the estimate on the list of the person asked' );
		$this->assertTrue( $mine['rows'][0]['followup']['mine'] );
		$this->assertFalse( $mine['followups']['can_send'] );
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZEST_Access_Requests::REVOKED ] = array( 'row:' . $other => 1 );
		$this->assertSame( array( $asked, $draft ), array_column( ZEST_Lists::reply( self::ALEX, 'open' )['rows'], 'id' ), 'unless an administrator removed their access to it' );

		$past = ZEST_Lists::reply( self::CASEY, 'history' );
		$this->assertSame( array( $other, $won, $asked, $sent, $draft ), array_column( $past['rows'], 'id' ) );
		$this->assertFalse( $past['rows'][1]['can_follow'], 'an accepted estimate is not followed up' );
		$this->assertSame( 'open', $past['rows'][1]['followup']['state'], 'but what was recorded still shows' );
	}

	public function test_the_lists_for_the_account_are_built_again_from_a_list_of_fields(): void {
		$this->open();
		$est = $this->full_estimate();
		$GLOBALS['zdz_test_current_user'] = self::CASEY;
		ZEST_Followup_Ajax::send( (array) ZEST_Access::row( $est ), self::CASEY, array( 'to' => self::ALEX, 'note' => 'ring Morgan', 'expect' => '0' ) );
		$GLOBALS['zdz_test_current_user'] = self::PV;
		ZDZ_Privacy_View::new_view( 'Bcdf' );

		foreach ( array( 'open', 'history' ) as $which ) {
			$l = ZEST_Lists::reply( self::PV, $which );
			$this->assertTrue( $l['privacy_view'] );
			$this->assertSame( array( 'can_send' => false, 'read_only' => true, 'outcomes' => array(), 'channels' => array(), 'max_note' => 0 ), $l['followups'] );
			$this->assertCount( 1, $l['rows'] );
			$r = $l['rows'][0];
			$this->assertSame( array( 'id', 'customer', 'number', 'status', 'created', 'items', 'followup', 'followup_history', 'can_follow', 'declined', 'test' ), array_keys( $r ) );
			$this->assertMatchesRegularExpression( self::CODE, $r['customer'] );
			$this->assertMatchesRegularExpression( self::CODE, $r['number'] );
			$this->assertFalse( $r['can_follow'] );
			$this->assertSame( '', $r['followup']['token'] );
			$this->assertSame( ZEST_Privacy_View::staff( self::CASEY ) . ' asked ' . ZEST_Privacy_View::staff( self::ALEX ) . ' to follow up', $r['followup']['words'] );
			$all = json_encode( $l );
			foreach ( array( 'Morgan', '1001', '"AA"', '"CC"', 'ring', 'example.com', '555-0100' ) as $leak ) {
				$this->assertStringNotContainsString( $leak, $all, $which . ': ' . $leak );
			}
			$this->assertSame( $l, ZDZ_Privacy_View::mask_value( $l ), 'the platform\'s own masking leaves it as it is' );
		}
		$this->assertSame( array(), ZEST_Lists::reply( self::LOOK, 'open' )['rows'], 'a salesperson\'s role in Privacy View sees its own estimates only' );
		$this->assertSame( array(), $this->db->get_results( "SELECT id FROM wp_zest_followups WHERE sent_by = " . self::PV ), 'looking wrote nothing' );
	}

	public function test_a_test_customer_is_marked_by_the_apps_own_rule(): void {
		$this->open();
		$est = $this->db->estimate( array( 'customer_name' => 'Test Customer One', 'created_by' => self::CASEY ) );
		$this->assertTrue( ZEST_Privacy_View::look( $est, self::PV )['estimate']['test'] );
		$GLOBALS['zdz_test_options']['zest_test_fixture'] = 'Sample';
		$this->assertFalse( ZEST_Privacy_View::look( $est, self::PV )['estimate']['test'], 'the mark an administrator set, not a second notion of a test customer' );
	}

	/* ── the screen ──────────────────────────────────────────────────────── */

	public function test_the_look_only_widget_holds_no_control_that_writes(): void {
		$pv = self::app_file( 'includes/class-zest-privacy-view.php' );
		$at = strpos( $pv, 'public static function render_widget' );
		$this->assertNotSame( false, $at );
		$html = substr( $pv, $at );
		foreach ( array( 'zest-input', 'zest-file', 'zest-parse', 'data-tab="new"', 'data-tab="docs"', 'data-tab="tools"', 'data-tab="leads"', 'dashboard.js', 'restNonce\'   => wp_create_nonce' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent );
		}
		$this->assertStringContainsString( "'privacyView' => true", $html );
		$this->assertStringContainsString( 'id="zest-open-list"', $html );
		$this->assertStringContainsString( 'id="zest-history-list"', $html );
		$app = self::app_file( 'includes/class-zest-app.php' );
		$this->assertMatchesRegularExpression( '/ZEST_Privacy_View::on\( \$user_id \) && ! ZEST_Privacy_View::open\(\) \) \{\s+return null;/', $app, 'closed: no tile body at all' );
		$this->assertMatchesRegularExpression( '/function body_html.*?ZEST_Privacy_View::on\( \$user_id \) \) \{\s+return ZEST_Privacy_View::open\(\) \? ZEST_Privacy_View::render_widget\( \$user_id \) : \'\';/s', $app );
	}

	public function test_the_admin_tier_follows_the_platforms_data_permission(): void {
		$sp = self::app_file( 'includes/class-zest-salesperson.php' );
		$this->assertStringContainsString( "ZDZ_Data_Permissions::can( \$uid, 'view_others_data' )", $sp );
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate';
		foreach ( array_merge( array( $dir . '/app.php' ), glob( $dir . '/includes/*.php' ) ) as $f ) {
			$this->assertStringNotContainsString( 'can_view_others_data', (string) file_get_contents( $f ), basename( $f ) . ' asks the platform by a method it has' );
		}
		$this->assertTrue( method_exists( 'ZEST_Salesperson', 'is_admin_tier' ) );
		$this->assertTrue( ZEST_Salesperson::is_admin_tier( self::CASEY ) );
		$this->assertFalse( ZEST_Salesperson::is_admin_tier( self::ALEX ) );
	}
}
