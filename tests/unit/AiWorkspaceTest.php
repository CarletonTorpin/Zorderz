<?php
/**
 * The Ai Workspace's own rules, without WordPress: who may use it (the shared device and Privacy View never), the
 * money booked BEFORE a turn is sent and its one ledger row after, the person's consent for their usage record, a
 * staging copy that never calls the hub, the relay's one caller id (the same as the hub's), the tool endpoint's bare
 * refusals, and the coding of a tool's answer. Neutral data only; throwaway keys made here.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ZCH_WEB_PARTY' ) ) {
	define( 'ZCH_WEB_PARTY', 'zorderz-site' );
}
if ( ! defined( 'ZCH_HUB_PARTY' ) ) {
	define( 'ZCH_HUB_PARTY', 'charts-hub' );
}
if ( ! defined( 'ZDZ_REST_NS' ) ) {
	define( 'ZDZ_REST_NS', 'zorderz/v1' );
}
if ( ! defined( 'ZAW_DB_VERSION' ) ) {
	define( 'ZAW_DB_VERSION', '1' );
}

require_once __DIR__ . '/llm-test-doubles.php';
require_once __DIR__ . '/support/privacy-stubs.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $uid ) { $GLOBALS['zdz_test_current_user'] = (int) $uid; }
}

$zdz_test_aw_root = dirname( __DIR__, 2 );
// the kernel classes the rules read (the customer-detail coder is a stand-in here: Zdz_Test_Coder)
foreach ( array( 'class-zdz-capabilities.php', 'class-zdz-consent.php', 'class-zdz-privacy-view.php' ) as $zdz_test_aw_f ) {
	require_once $zdz_test_aw_root . '/zorderz/inc/' . $zdz_test_aw_f;
}
foreach ( array( 'envelope', 'config', 'hub-client' ) as $zdz_test_aw_f ) {
	require_once $zdz_test_aw_root . '/zorderz-apps/apps/charts/includes/class-zch-' . $zdz_test_aw_f . '.php';
}
foreach ( array( 'db', 'settings', 'grant', 'hub', 'money', 'tools', 'turns' ) as $zdz_test_aw_f ) {
	require_once $zdz_test_aw_root . '/zorderz-apps/apps/ai-workspace/includes/class-zaw-' . $zdz_test_aw_f . '.php';
}

/** A hub client that records what it was asked and answers from a script. */
final class ZDZ_Test_Aw_Client {
	public $calls = array();
	public $reply = array( 'ok' => true, 'data' => array() );
	public function call( $op, array $body, $user, $today ) {
		$this->calls[] = array( 'op' => $op, 'body' => $body, 'user' => $user );
		return $this->reply;
	}
}

/** Just enough $wpdb for the turns table: rows by turn id and conversation key; every UPDATE "changes one row". */
final class ZDZ_Test_Aw_DB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $turns      = array();
	public $convs      = array();
	public $queries    = array();
	public function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $q );
	}
	public function get_row( $q, $out = null ) {
		if ( preg_match( "/zaw_turns WHERE turn_id = '([a-f0-9]{32})'/", $q, $m ) ) {
			return $this->turns[ $m[1] ] ?? null;
		}
		if ( preg_match( "/zaw_convs WHERE conv_key = '([a-f0-9]{16})'/", $q, $m ) ) {
			return $this->convs[ $m[1] ] ?? null;
		}
		return null;
	}
	public function query( $q ) {
		$this->queries[] = $q;
		return 1;
	}
}

final class AiWorkspaceTest extends TestCase {

	const OWNER   = 41;   // an owner-level account
	const OWNER2  = 42;
	const OFFICE  = 43;   // an office admin (zdz_admin): not owner-level
	const SHARED  = 44;   // the shared device
	const PRIVATE = 45;   // an owner-level account in Privacy View

	private $t = 1793000000;
	private $prev_wpdb = null;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_user_meta']  = array();
		$GLOBALS['zdz_test_user_roles'] = array(
			self::OWNER   => array( 'administrator' ),
			self::OWNER2  => array( 'zdz_owner', 'administrator' ),
			self::OFFICE  => array( 'zdz_admin' ),
			self::SHARED  => array( 'zdz_general' ),
			self::PRIVATE => array( 'administrator' ),
		);
		$GLOBALS['zdz_test_user_caps']  = array(
			self::OWNER   => array( 'manage_options' ),
			self::OWNER2  => array( 'manage_options' ),
			self::OFFICE  => array( 'manage_options' ),
			self::PRIVATE => array( 'manage_options' ),
		);
		$GLOBALS['zdz_test_user_meta'][ self::PRIVATE ][ ZDZ_Privacy_View::META ] = '1';
		$GLOBALS['zdz_test_current_user'] = 0;
		unset( $GLOBALS['zdz_test_filters']['zaw_max_grants'], $GLOBALS['zdz_test_filters']['zdz_llm_external_callers'], $GLOBALS['zdz_test_filters']['zdz_consent_scopes'] );
		$this->prev_wpdb = $GLOBALS['wpdb'] ?? null;   // another suite's $wpdb is put back afterwards
		$GLOBALS['wpdb'] = new Zdz_Test_Wpdb();
		$self            = $this;
		ZDZ_LLM_Ledger::$now = function () use ( $self ) {
			return $self->t;
		};
		ZAW_Money::$now = ZDZ_LLM_Ledger::$now;
		ZDZ_LLM_Ledger::forget();
		ZAW_Hub::$client  = null;
		ZAW_Hub::$staging = function () {
			return false;
		};
		ZCH_Config::$stored = array();
	}

	protected function tearDown(): void {
		ZDZ_LLM_Ledger::$now = null;
		ZAW_Money::$now      = null;
		ZDZ_LLM_Ledger::forget();
		ZAW_Hub::$client  = null;
		ZAW_Hub::$staging = null;
		ZCH_Config::$stored = null;
		$GLOBALS['wpdb'] = $this->prev_wpdb;
		unset( $GLOBALS['zdz_test_filters']['zaw_max_grants'], $GLOBALS['zdz_test_filters']['zdz_llm_external_callers'], $GLOBALS['zdz_test_filters']['zdz_consent_scopes'] );
		$GLOBALS['zdz_test_options']   = array();
		$GLOBALS['zdz_test_user_meta'] = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		unset( $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'] );
	}

	private function budget( $app, $all = null ) {
		$GLOBALS['zdz_test_options'][ ZDZ_Core_LLM::BUDGET_OPTION ] = array( 'app' => $app, 'all' => null === $all ? $app : $all );
	}

	private function near( $expected, $actual, $delta, $msg = '' ) {
		$this->assertTrue( abs( $expected - $actual ) <= $delta, $msg . " expected $expected got $actual" );
	}

	private function held() {
		$sum = 0;
		foreach ( $GLOBALS['wpdb']->rows( 'zdz_llm_holds' ) as $r ) {
			$sum += (int) $r['usd_milli'];
		}
		return $sum;
	}

	/* ── who ──────────────────────────────────────────────────────────── */

	public function test_only_a_holder_uses_it_and_never_the_shared_device_or_privacy_view(): void {
		$this->assertFalse( ZAW_Grant::may_use( 0 ), 'signed out' );
		$this->assertFalse( ZAW_Grant::may_use( self::OWNER ), 'not granted' );
		$this->assertSame( '', ZAW_Grant::grant_self( self::OWNER ) );
		$this->assertTrue( ZAW_Grant::may_use( self::OWNER ) );
		// a grant written by hand for the shared device or a Privacy View account still never works
		$GLOBALS['zdz_test_options'][ ZAW_Grant::OPTION ][ self::SHARED ]  = array( 'at' => 1 );
		$GLOBALS['zdz_test_options'][ ZAW_Grant::OPTION ][ self::PRIVATE ] = array( 'at' => 1 );
		$this->assertFalse( ZAW_Grant::may_use( self::SHARED ) );
		$this->assertSame( 'shared_device', ZAW_Grant::refused( self::SHARED ) );
		$this->assertFalse( ZAW_Grant::may_use( self::PRIVATE ) );
		$this->assertSame( 'privacy_view', ZAW_Grant::refused( self::PRIVATE ) );
		$this->assertSame( 'signed_out', ZAW_Grant::refused( 0 ) );
	}

	public function test_an_owner_level_account_grants_itself_up_to_the_setting(): void {
		$this->assertNotSame( '', ZAW_Grant::grant_self( self::OFFICE ), 'an office admin is not owner-level' );
		$GLOBALS['zdz_test_user_roles'][99] = array( 'zdz_owner' );
		$this->assertTrue( ZAW_Grant::owner_level( 99 ) );
		$this->assertFalse( ZAW_Grant::owner_level( self::OFFICE ) );
		$this->assertNotSame( '', ZAW_Grant::grant_self( self::SHARED ) );
		$this->assertNotSame( '', ZAW_Grant::grant_self( self::PRIVATE ) );
		$this->assertSame( '', ZAW_Grant::grant_self( self::OWNER ) );
		$this->assertNotSame( '', ZAW_Grant::grant_self( self::OWNER2 ), 'Core default: one holder at a time' );
		ZAW_Settings::save( array( 'max_grants' => 2 ) );
		$this->assertSame( '', ZAW_Grant::grant_self( self::OWNER2 ) );
		$GLOBALS['zdz_test_filters']['zaw_max_grants'] = function () {
			return 9;
		};
		$this->assertSame( 2, ZAW_Grant::max(), 'a filter may narrow the setting, never widen it' );
		$GLOBALS['zdz_test_filters']['zaw_max_grants'] = function () {
			return 1;
		};
		$this->assertSame( 1, ZAW_Grant::max() );
		ZAW_Grant::revoke_self( self::OWNER );
		$this->assertFalse( ZAW_Grant::may_use( self::OWNER ) );
	}

	public function test_settings_have_neutral_defaults_and_bounds(): void {
		$d = ZAW_Settings::all();
		$this->assertSame( array( 'turn_usd' => 1.0, 'max_grants' => 1, 'effort' => 'medium', 'web' => false ), $d, 'web search off by default' );
		$c = ZAW_Settings::clean( array( 'turn_usd' => 999, 'max_grants' => -3, 'effort' => 'reckless', 'web' => 1 ) );
		$this->assertSame( ZAW_Settings::ceiling(), $c['turn_usd'], 'never above the router\'s per-call ceiling' );
		$this->assertSame( 1, $c['max_grants'] );
		$this->assertSame( 'medium', $c['effort'] );
		$this->assertTrue( $c['web'] );
		$this->assertSame( ZAW_Settings::MIN_TURN_USD, ZAW_Settings::clean( array( 'turn_usd' => 0 ) )['turn_usd'] );
	}

	/* ── money: booked before a turn is sent ─────────────────────────── */

	public function test_no_budget_means_nothing_is_booked_or_sent(): void {
		$h = ZAW_Money::hold( 1.0 );
		$this->assertSame( 'no_budget', $h['why'] );
		$this->assertSame( 0.0, $h['usd'] );
		$this->assertSame( 0, $this->held() );
	}

	public function test_a_hold_is_booked_before_the_turn_and_holds_to_what_is_left(): void {
		$this->budget( 10.0, 3.0 );
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 1600 ) );
		$a = ZAW_Money::hold( 1.0 );
		$this->assertGreaterThan( 0, $a['id'] );
		$this->assertSame( 1.0, $a['usd'] );
		$this->assertSame( 1000, $this->held(), 'the most the answer may cost is held before anything is sent' );
		$holds = $GLOBALS['wpdb']->rows( 'zdz_llm_holds' );
		$this->assertSame( 0, (int) $holds[0]['app'], 'the engine spends on the account, not inside the apps\' line' );
		$b = ZAW_Money::hold( 1.0 );
		$this->near( 0.4, $b['usd'], 0.0001, 'only what is left of the account\'s line' );
		$c = ZAW_Money::hold( 1.0 );
		$this->assertSame( 'none_left', $c['why'] );
		$this->assertSame( 1400, $this->held(), 'a refused hold is not left behind' );
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertNull( ZAW_Money::hold( 1.0 ), 'spend that can\'t be read: not sent' );
	}

	public function test_the_turns_one_row_at_the_pull_then_settled_to_the_engines_figures(): void {
		$this->budget( 50.0 );
		$GLOBALS['zdz_test_filters']['zdz_llm_external_callers'] = array( 'ZAW_Money', 'external_caller' );
		$hold = ZAW_Money::hold( 1.0 );
		$turn = array( 'created' => gmdate( 'Y-m-d H:i:s', $this->t - 30 ), 'coded_count' => 3, 'caught_count' => 1 );
		$lid  = ZAW_Money::book( $turn, array( 'usd' => 1.0 ), 'in_progress', 'example-model-large', false, false );
		$this->assertGreaterThan( 0, $lid );
		ZAW_Money::release( $hold['id'] );
		$this->assertSame( 0, $this->held() );
		$rows = $GLOBALS['wpdb']->rows( 'zdz_llm_ledger' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'app:ai-workspace', $rows[0]['caller'] );
		$this->assertSame( 1000, $rows[0]['usd_milli'], 'booked at the hold: the most it could cost' );
		$this->assertSame( 'in_progress', $rows[0]['outcome'] );
		$this->assertSame( 3, $rows[0]['coded_count'] );
		ZDZ_LLM_Ledger::forget();
		$m = ZDZ_LLM_Ledger::month_to_date();
		$this->near( 0.0, $m['app'], 0.0001, 'not the apps\' line' );
		$this->near( 1.0, $m['all'], 0.0001, 'the account\'s line' );
		$this->assertTrue( ZAW_Money::settle( $lid, $turn, array( 'usd' => 0.2345, 'in' => 900, 'out' => 120, 'cache_write_5m' => 10, 'cache_write_1h' => 5 ), 'done', 'example-model-large', false, false ) );
		$rows = $GLOBALS['wpdb']->rows( 'zdz_llm_ledger' );
		$this->assertSame( 235, $rows[0]['usd_milli'], 'rounded up to the thousandth' );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
		$this->assertSame( 15, $rows[0]['cache_write'] );
		$this->assertSame( 'estimated', ZAW_Money::outcome( 'lost', true ) );
		$this->assertSame( 'error_runner', ZAW_Money::outcome( 'failed', false ) );
	}

	public function test_the_engines_own_spend_reaches_the_router_and_counts_its_most_when_stale(): void {
		$month = gmdate( 'Y-m', $this->t );
		$this->assertFalse( ZAW_Money::note( array( 'error' => 'unreadable' ) ) );
		$this->assertTrue( ZAW_Money::note( array( 'month' => $month, 'engine_usd' => 3.0, 'other_usd' => 1.25, 'cap_usd' => null ) ) );
		$this->near( 1.25, ZAW_Money::filter( 0.5 ) - 0.5, 0.0001 );
		$this->t += 7200;                                         // two hours without word from the hub
		$this->near( 1.25, ZAW_Money::external_usd(), 0.0001, 'no engine line: its last word stands' );
		ZAW_Money::note( array( 'month' => $month, 'engine_usd' => 3.0, 'other_usd' => 1.25, 'cap_usd' => 20.0 ) );
		$this->t += 7200;
		ZDZ_LLM_Ledger::write( array( 'caller' => 'app:ai-workspace', 'usd_milli' => 4000, 'ts' => $this->t ) );
		$this->near( 16.0, ZAW_Money::external_usd(), 0.0001, 'stale: the most the engine could have spent unseen' );
	}

	/* ── consent ─────────────────────────────────────────────────────── */

	public function test_the_persons_usage_is_kept_only_under_their_consent(): void {
		$GLOBALS['zdz_test_filters']['zdz_consent_scopes'] = function ( $s ) {
			$s['assistant_usage'] = 'What each answer cost you.';
			return $s;
		};
		$this->assertTrue( ZAW_Turns::keeps_usage( self::OWNER ), 'card off: kept as before' );
		$GLOBALS['zdz_test_options'][ ZDZ_Consent::OPT_ON ] = '1';
		$this->assertFalse( ZAW_Turns::keeps_usage( self::OWNER ), 'card on, not agreed: not kept' );
		$GLOBALS['zdz_test_user_meta'][ self::OWNER ][ ZDZ_Consent::META ] = array( 'version' => ZDZ_Consent::version(), 'accepted_at' => '2026-01-01T00:00:00Z' );
		$this->assertTrue( ZAW_Turns::keeps_usage( self::OWNER ) );
		$this->assertFalse( ZAW_Turns::keeps_usage( 0 ) );
	}

	/* ── the link ────────────────────────────────────────────────────── */

	public function test_the_relay_has_one_caller_id_and_it_is_the_hubs(): void {
		$this->assertSame( 'app:ai-workspace', ZAW_Hub::CALLER );
		$this->assertSame( ZAW_Hub::CALLER, ZAW_Money::CALLER, 'one id for the relay and its ledger rows' );
		$hub = (string) file_get_contents( dirname( __DIR__, 2 ) . '/hub/zorderz_hub/access.py' );
		$this->assertStringContainsString( 'ASSISTANT_CALLER = "' . ZAW_Hub::CALLER . '"', $hub );
		$b = ZAW_Hub::block();
		$this->assertFalse( $b['kiosk'] );
		$this->assertSame( array(), $b['scope'] );
		$this->assertSame( array( 'health', 'assistant_turn', 'assistant_status', 'assistant_cancel' ), $b['ops'] );
		$this->assertFalse( $b['money'] );
	}

	public function test_a_staging_copy_never_calls_the_hub(): void {
		$client          = new ZDZ_Test_Aw_Client();
		ZAW_Hub::$client = $client;
		ZAW_Hub::$staging = function () {
			return true;
		};
		$r = ZAW_Hub::call( 'assistant_turn', array( 'turn_id' => str_repeat( 'a', 32 ) ) );
		$this->assertSame( 'staging', $r['error']['code'] );
		$this->assertSame( array(), $client->calls, 'nothing reached the client' );
		$this->assertNull( ZAW_Hub::envelope() );
		$this->assertFalse( ZAW_Hub::live_site() );
		$this->assertFalse( ZAW_Turns::ready() );
		$why = ZAW_Turns::preflight( self::OWNER, 'hello' );
		$this->assertTrue( is_wp_error( $why ) );
		$this->assertSame( 'not_ready', $why->get_error_code() );
	}

	public function test_a_call_carries_the_block_and_never_a_person(): void {
		$client          = new ZDZ_Test_Aw_Client();
		$client->reply   = array( 'ok' => true, 'data' => array( 'runner' => 'up', 'money' => array( 'month' => gmdate( 'Y-m', $this->t ), 'other_usd' => 0.5, 'engine_usd' => 2.0 ) ) );
		ZAW_Hub::$client = $client;
		$GLOBALS['zdz_test_current_user'] = self::OWNER;
		$r = ZAW_Hub::call( 'assistant_status', array() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'ai-workspace', $client->calls[0]['user'], 'a fixed word, never who asked' );
		$this->assertSame( 'app:ai-workspace', $client->calls[0]['body']['access']['caller'] );
		$this->assertStringNotContainsString( (string) self::OWNER, json_encode( $client->calls[0] ) );
		$this->near( 0.5, ZAW_Money::external_usd(), 0.0001, 'every status keeps the engine\'s month fresh' );
		ZAW_Hub::$client = null;
		$this->assertSame( 'not_configured', ZAW_Hub::call( 'health' )['error']['code'], 'no Charts hub here: an error, never an empty success' );
		$this->assertNotSame( '', ZAW_Hub::say( array( 'error' => array( 'code' => 'assistant_off' ) ) ) );
	}

	/* ── the tool endpoint ───────────────────────────────────────────── */

	private function pair() {
		list( $web_sk, $web_pk ) = ZCH_Envelope::keygen();
		list( $hub_sk, $hub_pk ) = ZCH_Envelope::keygen();
		$seen   = array();
		$replay = function ( $n ) use ( &$seen ) {
			if ( isset( $seen[ $n ] ) ) {
				return false;
			}
			$seen[ $n ] = 1;
			return true;
		};
		$site = new ZCH_Envelope( ZCH_WEB_PARTY, $web_sk, array( ZCH_HUB_PARTY => array( $hub_pk ) ), $replay );
		$hub  = new ZCH_Envelope( ZCH_HUB_PARTY, $hub_sk, array( ZCH_WEB_PARTY => array( $web_pk ) ), $replay );
		return array( $site, $hub );
	}

	public function test_the_tool_endpoint_answers_only_a_live_turn_with_its_token(): void {
		list( $site, $hub ) = $this->pair();
		$db              = new ZDZ_Test_Aw_DB();
		$GLOBALS['wpdb'] = $db;
		$tid             = str_repeat( 'b', 32 );
		$tok             = str_repeat( 'c', 64 );
		$db->turns[ $tid ] = array( 'id' => 7, 'turn_id' => $tid, 'user_id' => self::OWNER, 'conv_key' => str_repeat( 'd', 16 ), 'state' => 'running',
			'tool_hash' => hash( 'sha256', $tok ), 'site_tools' => '["app_roles_who_can"]', 'created' => gmdate( 'Y-m-d H:i:s' ) );
		$ask = function ( $body ) use ( $hub, $site ) {
			return ZAW_Tools::answer( $site, $hub->seal( ZCH_WEB_PARTY, 'request', $body, 'rid-1' ) );
		};
		$this->assertTrue( is_wp_error( ZAW_Tools::answer( null, array() ) ), 'no envelope here: a bare 404' );
		$this->assertTrue( is_wp_error( ZAW_Tools::answer( $site, array( 'v' => 1 ) ) ) );
		list( $stranger ) = $this->pair();
		$this->assertTrue( is_wp_error( ZAW_Tools::answer( $site, $stranger->seal( ZCH_HUB_PARTY, 'request', array(), 'r' ) ) ), 'not from the hub' );
		$this->assertTrue( is_wp_error( $ask( array( 'turn_id' => $tid, 'token' => str_repeat( 'e', 64 ), 'tool' => 'app_roles_who_can' ) ) ), 'wrong token' );
		$db->turns[ $tid ]['state'] = 'done';
		$this->assertTrue( is_wp_error( $ask( array( 'turn_id' => $tid, 'token' => $tok, 'tool' => 'app_roles_who_can' ) ) ), 'a finished turn' );
		$db->turns[ $tid ]['state'] = 'running';
		$out   = $ask( array( 'turn_id' => $tid, 'token' => $tok, 'tool' => 'app_secret_tool' ) );
		$inner = $hub->open( $out, ZCH_WEB_PARTY, 'response', 'rid-1' );
		$this->assertFalse( $inner['body']['ok'], 'a tool the turn was not given' );
		$out   = $ask( array( 'turn_id' => $tid, 'token' => $tok, 'tool' => 'app_roles_who_can' ) );
		$inner = $hub->open( $out, ZCH_WEB_PARTY, 'response', 'rid-1' );
		$this->assertFalse( $inner['body']['ok'], 'the person no longer holds the workspace: the app is not asked' );
		$this->assertSame( array(), $db->queries, 'nothing ran' );
	}

	public function test_a_tools_answer_is_coded_and_never_names_the_asker(): void {
		$coder = new Zdz_Test_Coder();
		$text  = ZAW_Tools::coded_text( array(
			'success'            => true,
			'user_id'            => self::OWNER,
			'requesting_user_id' => self::OWNER,
			'tier'               => 'admin',
			'people'             => array( 'Riley Example' => 'can', 'Jordan Sample' => 'learning' ),
			'note'               => 'call 555-0142 or riley@example.com',
			'count'              => 2,
			'open'               => true,
		), $coder );
		$this->assertStringNotContainsString( 'Riley Example', $text, 'a key is coded too' );
		$this->assertStringNotContainsString( '555-0142', $text );
		$this->assertStringNotContainsString( 'riley@example.com', $text );
		$this->assertStringNotContainsString( 'user_id', $text );
		$this->assertStringNotContainsString( '"tier"', $text );
		$j = json_decode( $text, true );
		$this->assertSame( 2, $j['count'], 'a number nothing changes stays a number' );
		$this->assertTrue( $j['open'] );
		$long = ZAW_Tools::coded_text( array( 'x' => str_repeat( 'y', ZAW_Tools::MAX_CHARS + 50 ) ), $coder );
		$this->assertStringContainsString( '[cut at', $long );
		$this->assertSame( array( 'a' => array( 'b' => 'B!' ) ), ZAW_Tools::walk( array( 'a' => array( 'b' => 'B' ) ), function ( $s ) { return $s . '!'; }, 0 ) );
	}

	public function test_site_tools_are_reads_the_person_may_use(): void {
		$GLOBALS['zdz_test_filters']['zaw_site_tools'] = function ( $defs ) {
			$defs['not a tool name'] = array( 'verb' => 'roles.who_can', 'input_schema' => array() );
			return $defs;
		};
		$GLOBALS['zdz_test_filters']['zdz_register_capabilities'] = function ( $caps ) {
			$caps['roles.who_can'] = array( 'tier' => 'viewer', 'side_effect' => false, 'needs_app' => false, 'callback' => function () { return array( 'success' => true ); } );
			$caps['jobs.upcoming_work'] = array( 'tier' => 'viewer', 'needs_app' => false, 'callback' => function () { return array( 'success' => true ); } ); // no side_effect said: a write
			return $caps;
		};
		if ( method_exists( 'ZDZ_Capabilities', 'flush' ) ) {
			ZDZ_Capabilities::flush();
		}
		$names = array_map( function ( $d ) { return $d['name']; }, ZAW_Tools::for_user( self::OWNER ) );
		$this->assertSame( array( 'app_roles_who_can' ), $names, 'an unlabelled verb is a write: never a tool' );
		$this->assertSame( array(), ZAW_Tools::for_user( self::SHARED ), 'the shared device gets no tools' );
		unset( $GLOBALS['zdz_test_filters']['zaw_site_tools'], $GLOBALS['zdz_test_filters']['zdz_register_capabilities'] );
		if ( method_exists( 'ZDZ_Capabilities', 'flush' ) ) {
			ZDZ_Capabilities::flush();
		}
	}
}
