<?php
/**
 * The chat's rule routes and channels, server side, with neutral fixtures only (Alex, Jordan, Sam, Riley, Robin,
 * Example Co, example.com, 555 numbers):
 *
 *   - mailbox questions: "do not search the mailbox", a write anywhere is never a read, a pasted customer message is
 *     not the person's request, purchases / admin topics / sent-to / received routes, recall, thread state, a
 *     colleague's mailbox only for a reader and a team name, "yes, widen it", Privacy View refused, the inbox's answer
 *     used as rendered, a mail question never answered by the model;
 *   - "About you": the sentence rules, explicit requests, own-only save / delete / forget, the shared device and
 *     Privacy View refused;
 *   - the "Chat answers" channel: refused for the shared device, the channel's shape passes the platform's check, the
 *     test-push rate;
 *   - library links: [VAULT-n] becomes a link, a document the reader cannot see is never named, a code block is kept,
 *     no reader means no change, the recipient warning sits outside the card;
 *   - access codes cut (a door size is not a code; only this app's verbs are wrapped);
 *   - the direct-message card: held, sent once through Messages' seam, never for another chat, never in Privacy View;
 *   - read adapters (roles.who_can), the hours / route-plan / page routes, email turns, the rest period, compose,
 *     Charts' spec check and markers, label cleaning, answer times, the business's facts, starters.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
require_once __DIR__ . '/llm-test-doubles.php';

foreach ( array( 'ZANA_APP_ID' => 'sales-analytics', 'ZANA_NONCE' => 'zana_nonce', 'ZANA_URL' => 'https://app.example.com/wp-content/plugins/zorderz-apps/apps/analytics/' ) as $zcht_k => $zcht_v ) {
	if ( ! defined( $zcht_k ) ) {
		define( $zcht_k, $zcht_v );
	}
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $a = 0, $b = 0 ) { return mt_rand( (int) $a, (int) $b ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) $u; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = '' ) { return 'abc123nonce'; }
}

$zcht_dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-context.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-push-setup.php';
foreach ( array( 'markers', 'allowance', 'llm-client', 'pseudonym-route', 'db', 'mail-compose', 'mail-relevance', 'mail-recall', 'mail-followup',
	'mail-crossuser', 'mail-continuation', 'mail-guard', 'email-turn', 'email-cooldown', 'mail-route', 'compose', 'user-memory',
	'turn-timings', 'push', 'charts-tool', 'hours-route', 'plan-routes', 'page-nav', 'vault-links', 'label-clean', 'sealed',
	'app-reads', 'dm-card', 'company-facts', 'starters', 'web-note', 'routes' ) as $zcht_f ) {
	require_once $zcht_dir . 'class-zana-' . $zcht_f . '.php';
}

if ( ! class_exists( 'ZDZ_Test_Cht_DB' ) ) {
	/** An in-memory $wpdb for this file: the memory table, inserts, deletes and the claim on the options table. */
	class ZDZ_Test_Cht_DB {
		public $prefix   = 'wp_';
		public $options  = 'wp_options';
		public $usermeta = '';
		public $rows     = array();
		public $claims   = array();
		public $insert_id = 0;
		public function prepare( $sql, ...$args ) {
			$i = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, $sql );
		}
		public function esc_like( $s ) { return $s; }
		public function get_charset_collate() { return ''; }
		public function insert( $table, $row, $fmt = null ) {
			$this->insert_id = count( $this->rows[ $table ] ?? array() ) + 1;
			$this->rows[ $table ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $row;
			return 1;
		}
		public function delete( $table, $where, $fmt = null ) {
			$n = 0;
			foreach ( (array) ( $this->rows[ $table ] ?? array() ) as $id => $r ) {
				if ( array_intersect_assoc( array_map( 'strval', $where ), array_map( 'strval', $r ) ) === array_map( 'strval', $where ) ) {
					unset( $this->rows[ $table ][ $id ] );
					$n++;
				}
			}
			return $n;
		}
		public function update( $table, $set, $where ) { return 1; }
		public function get_results( $sql, $out = null ) {
			if ( preg_match( "/FROM wp_zana_memory WHERE user_id = (\d+) AND category = '([a-z_]+)'/", $sql, $m ) ) {
				return array_values( array_filter( $this->rows['wp_zana_memory'] ?? array(), function ( $r ) use ( $m ) {
					return (int) $r['user_id'] === (int) $m[1] && $r['category'] === $m[2];
				} ) );
			}
			return array();
		}
		public function get_var( $sql ) { return null; }
		public function get_col( $sql ) { return array(); }
		public function query( $sql ) {
			if ( preg_match( "/^INSERT IGNORE INTO wp_options \(option_name, option_value, autoload\) VALUES \('([^']+)'/", $sql, $m ) ) {
				if ( isset( $this->claims[ $m[1] ] ) ) {
					return 0;
				}
				$this->claims[ $m[1] ] = true;
				return 1;
			}
			return 0;
		}
	}
}

class ChatRoutesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                = new ZDZ_Test_Cht_DB();
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_filters']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_user_meta']  = array();
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_sales' ), 8 => array( 'zdz_sales' ), 9 => array( 'zdz_general' ), 1 => array( 'administrator' ) );
		$GLOBALS['zdz_test_current_user'] = 7;
		ZANA_Mail_Route::$inbox  = null;
		ZANA_Mail_Route::$staff  = null;
		ZANA_Mail_Route::$reader = null;
		ZANA_Mail_Route::$today  = '2026-05-15';
		ZANA_User_Memory::$model = null;
		ZANA_Vault_Links::$resolver = null;
		ZANA_Vault_Links::$access   = null;
		ZANA_Vault_Links::$recipient = null;
		ZANA_Vault_Links::$base     = 'https://app.example.com';
		ZANA_Vault_Links::$hosts    = array( 'app.example.com' );
		ZANA_Sealed::$finder  = null;
		ZANA_Sealed::$labeler = null;
		ZANA_DM_Card::$recipient = null;
		ZANA_DM_Card::$claim     = null;
		ZANA_App_Reads::$why_not   = null;
		ZANA_App_Reads::$invoke    = null;
		ZANA_App_Reads::$abilities = null;
		ZANA_App_Reads::reset();
		ZANA_Hours_Route::$can    = null;
		ZANA_Hours_Route::$invoke = null;
		ZANA_Plan_Routes::$can   = null;
		ZANA_Plan_Routes::$today = '2026-05-15';
		ZANA_Page_Nav::reset( null );
		ZANA_Charts_Tool::reset();
		ZANA_Charts_Tool::$vocab_for   = null;
		ZANA_Charts_Tool::$invoke      = null;
		ZANA_Charts_Tool::$admin_zones = null;
		ZANA_Email_Turn::$company_names = array();
		ZANA_Email_Cooldown::$now = null;
		ZANA_Company_Facts::$rows = null;
		ZANA_Push::$now = null;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_current_user'] );
		ZANA_Mail_Route::$inbox = null;
		ZANA_Mail_Route::$staff = null;
		ZANA_Mail_Route::$reader = null;
		ZANA_Page_Nav::reset( null );
		ZANA_Charts_Tool::$vocab_for = null;
		ZANA_Charts_Tool::$invoke = null;
		ZANA_Email_Turn::$company_names = null;
		ZANA_Company_Facts::$rows = null;
		ZANA_Vault_Links::$hosts = null;
		ZANA_Vault_Links::$base = null;
	}

	/* ─────────────────────────── mailbox: which turns are mail questions ─────────────────────────── */

	public function test_do_not_search_the_mailbox_is_a_decline_and_never_a_mail_route() {
		$this->assertTrue( ZANA_Mail_Relevance::declined( 'Do not search the mailbox, just tell me what you know.' ) );
		$this->assertTrue( ZANA_Mail_Relevance::declined( "don't check my email for this" ) );
		$this->assertTrue( ZANA_Mail_Relevance::declined( 'No email please.' ) );
		$this->assertFalse( ZANA_Mail_Relevance::detect( 'Do not search the mailbox for the supplier order' )['fire'] );
		$plan = ZANA_Mail_Route::plan( 7, 'Do not search my mailbox. Who are our biggest customers?' );
		$this->assertTrue( ! empty( $plan['declined'] ) && empty( $plan['mail'] ) );
	}

	public function test_a_narrower_mail_ask_after_a_decline_is_not_a_decline() {
		$this->assertFalse( ZANA_Mail_Relevance::declined( "don't forget to check my email" ) );
		$this->assertFalse( ZANA_Mail_Relevance::declined( "I don't use my inbox much, but did Example Co email me?" ) );
		$this->assertFalse( ZANA_Mail_Relevance::declined( "don't use the CRM, check my mail" ) );
	}

	public function test_a_write_anywhere_in_the_turn_is_never_a_mailbox_read() {
		$paste = "Hi Alex,\nDid you get my email? Please let me know what time we can expect you.\nThanks, Robin\nWrite an email to this individual asking if Tuesday works.";
		$this->assertTrue( ZANA_Mail_Compose::is_compose( $paste ) );
		$this->assertFalse( ZANA_Mail_Relevance::detect( $paste )['fire'] );
		$this->assertFalse( ZANA_Mail_Recall::detect( $paste, true, false )['fire'] );
		$this->assertFalse( ZANA_Mail_Followup::detect( $paste )['fire'] );
		$this->assertFalse( ZANA_Mail_CrossUser::detect( $paste )['fire'] );
		$this->assertTrue( ! empty( ZANA_Mail_Route::plan( 7, $paste )['email_task'] ) );
	}

	public function test_a_mailbox_question_outranks_a_write_in_the_same_turn() {
		$this->assertFalse( ZANA_Mail_Compose::is_compose( 'Did Jordan reply to my email? Then draft a follow-up.' ) );
		$this->assertTrue( ZANA_Mail_Compose::is_compose( 'Reply to her saying Tuesday works.' ) );
		$this->assertSame( array( 'robin@example.com' ), ZANA_Mail_Compose::addresses_in( 'write to robin@example.com.Thanks' ) );
	}

	public function test_purchases_admin_topics_and_received_mail_route_to_the_mailbox() {
		$this->assertSame( 'procurement', ZANA_Mail_Relevance::detect( 'How many pallets have I ordered this year?' )['reason'] );
		$this->assertFalse( ZANA_Mail_Relevance::detect( 'How many pallets did we sell this year?' )['fire'], 'a sales question stays on the records' );
		$this->assertSame( 'admin_insurance', ZANA_Mail_Relevance::detect( "what's the status on our insurance renewal?" )['reason'] );
		$this->assertSame( 'mail_lookup', ZANA_Mail_Relevance::detect( 'how many emails do I have from Example Co' )['reason'] );
		$this->assertSame( 'explicit_inbox', ZANA_Mail_Relevance::detect( 'check my inbox for the permit' )['reason'] );
		$this->assertFalse( ZANA_Mail_Relevance::detect( "what's Alex's phone number" )['fire'] );
	}

	public function test_a_business_supply_word_only_adds_a_route() {
		$this->assertFalse( ZANA_Mail_Relevance::detect( 'How many widgets have I ordered?' )['fire'] );
		$GLOBALS['zdz_test_options'][ ZANA_Mail_Relevance::SUPPLY_OPT ] = array( 'widget', 'bad|word(' );
		$this->assertSame( 'procurement', ZANA_Mail_Relevance::detect( 'How many widgets have I ordered?' )['reason'] );
		$this->assertSame( array( 'widgets?' ), ZANA_Mail_Relevance::extra_words( ZANA_Mail_Relevance::SUPPLY_OPT ), 'a word that is not a word is dropped' );
	}

	public function test_sent_mail_to_a_person_is_a_server_built_search() {
		$this->assertSame( 'sent_to', ZANA_Mail_Relevance::detect( "have I sent emails to Jordan about Sam's commission?" )['reason'] );
		$this->assertSame( array( 'query' => 'jordan', 'topic' => "Sam's commission", 'match' => 'recipient' ), ZANA_Mail_Relevance::sent_plan( "have I sent emails to Jordan about Sam's commission?" ) );
		$this->assertSame( array( 'query' => 'jordan', 'topic' => '', 'match' => 'any' ), ZANA_Mail_Relevance::sent_plan( 'search my sent emails for jordan' ) );
		$this->assertSame( array(), ZANA_Mail_Relevance::sent_plan( 'search my sent emails for this week' ), 'a period alone is the sent list' );
	}

	public function test_thread_state_who_holds_the_ball() {
		$this->assertSame( 'needs_reply', ZANA_Mail_Followup::detect( 'who is waiting on me?' )['kind'] );
		$this->assertSame( 'awaiting_reply', ZANA_Mail_Followup::detect( 'who owes me a reply?' )['kind'] );
		$this->assertSame( 'sent_log', ZANA_Mail_Followup::detect( 'what did I send this week?' )['kind'] );
		$this->assertFalse( ZANA_Mail_Followup::detect( "who hasn't paid their invoice?" )['fire'] );
		$this->assertSame( array( '2026-05-09', '2026-05-15' ), ZANA_Mail_Followup::range_for_period( 'this_week', '2026-05-15' ) );
	}

	public function test_recall_needs_the_medium_or_a_customer_lookup() {
		$this->assertSame( 'recall', ZANA_Mail_Recall::detect( 'what was the last email I sent to Jordan?', false, false )['reason'] );
		$this->assertSame( 'email-followup', ZANA_Mail_Recall::detect( 'email', true, false )['reason'] );
		$this->assertFalse( ZANA_Mail_Recall::detect( 'email', false, false )['fire'] );
		$this->assertFalse( ZANA_Mail_Recall::detect( 'tell me what Riley paid', true, false )['fire'] );
	}

	public function test_a_colleague_mailbox_only_for_a_reader_and_a_team_name() {
		$this->assertSame( 'jordan', strtolower( ZANA_Mail_CrossUser::detect( 'did Jordan get any Example Co emails this week?' )['person'] ) );
		$this->assertFalse( ZANA_Mail_CrossUser::detect( 'did I get any emails from the supplier?' )['fire'] );
		$this->assertFalse( ZANA_Mail_CrossUser::detect( 'did the customer get my email?' )['fire'] );
		$this->assertFalse( ZANA_Mail_CrossUser::detect( 'have I sent emails to Jordan?' )['fire'], 'my own sent mail, never Jordan\'s mailbox' );
		ZANA_Mail_Route::$staff  = function ( $n ) { return 'jordan' === strtolower( $n ); };
		ZANA_Mail_Route::$reader = function ( $u ) { return 7 === $u; };
		$this->assertSame( 'crossuser', ZANA_Mail_Route::plan( 7, 'did Jordan get any Example Co emails this week?' )['mail'] );
		$this->assertNotSame( 'crossuser', ZANA_Mail_Route::plan( 8, 'did Jordan get any Example Co emails this week?' )['mail'] ?? '', 'not a reader' );
		$this->assertNotSame( 'crossuser', ZANA_Mail_Route::plan( 7, 'did Casey get any Example Co emails this week?' )['mail'] ?? '', 'not a team name' );
	}

	public function test_yes_widen_after_a_mailbox_answer_searches_again() {
		$hist = array(
			array( 'role' => 'user', 'content' => 'how many pallets have we ordered from Example Co?' ),
			array( 'role' => 'assistant', 'content' => "\u{1F4EC} I don't see any in your indexed mail — want me to widen the search?" ),
		);
		$wc = ZANA_Mail_Continuation::detect( 'yes', $hist );
		$this->assertTrue( $wc['fire'] );
		$this->assertStringContainsString( 'example co', $wc['query'] );
		$this->assertFalse( ZANA_Mail_Continuation::detect( 'yes, and what was revenue in March?', $hist )['fire'] );
		$this->assertSame( 'widen', ZANA_Mail_Route::plan( 7, 'yes', $hist )['mail'] );
	}

	public function test_privacy_view_never_reads_mail_from_the_chat() {
		$plan = ZANA_Mail_Route::plan( 7, 'check my inbox for the permit', array(), array( 'privacy_view' => true ) );
		$this->assertSame( 'refused', $plan['mail'] );
		$this->assertStringContainsString( 'Privacy View', (string) ZANA_Mail_Route::answer( $plan, 7, 'x' ) );
	}

	public function test_the_inbox_answer_is_used_as_rendered_and_a_failure_is_never_a_model_answer() {
		$calls = array();
		ZANA_Mail_Route::$inbox = function ( $fn, $args ) use ( &$calls ) {
			$calls[] = array( $fn, $args );
			return array( 'ok' => true, 'render' => "\u{1F4EC} 2 messages from Example Co" );
		};
		$route = ZANA_Routes::before_model( 7, 'how many emails do I have from Example Co', array() );
		$this->assertSame( 'mail_search', $route['kind'] );
		$this->assertStringContainsString( '2 messages', $route['answer'] );
		$this->assertSame( 'handle_marker', $calls[0][0] );
		$this->assertSame( 7, $calls[0][1][1], 'the turn\'s signed-in person, never anyone from the words' );
		ZANA_Mail_Route::$inbox = function () { return null; };
		$route = ZANA_Routes::before_model( 7, 'how many emails do I have from Example Co', array() );
		$this->assertSame( ZANA_Mail_Guard::refusal(), $route['answer'] );
		$this->assertTrue( ZANA_Mail_Guard::model_authored_mail_answer( array( 'mail' => 'search' ), false, 'No emails to Alex on file.' ) );
		$this->assertFalse( ZANA_Mail_Guard::model_authored_mail_answer( array(), false, 'Anything.' ) );
	}

	public function test_the_shared_device_gets_no_route_at_all() {
		ZANA_Mail_Route::$inbox = function () { return array( 'render' => 'mail' ); };
		$r = ZANA_Routes::before_model( 9, 'check my inbox', array(), array( 'shared_device' => true ) );
		$this->assertNull( $r['answer'] );
	}

	/* ─────────────────────────── email turns, rest period, compose ─────────────────────────── */

	public function test_email_turns_and_their_follow_ups() {
		$this->assertTrue( ZANA_Email_Turn::is_email_task( 'write the email to riley@example.com' ) );
		$this->assertFalse( ZANA_Email_Turn::is_email_task( 'did Riley email me?' ) );
		$hist = array( array( 'role' => 'user', 'content' => 'draft a reply to Riley' ), array( 'role' => 'assistant', 'content' => "[ZDZ_EMAIL_DRAFT]\nTo: riley@example.com\nSubject: Tuesday\n\nHi Riley\n[/ZDZ_EMAIL_DRAFT]" ) );
		$this->assertTrue( ZANA_Email_Turn::is_draft_followup( 'try again, shorter', $hist ) );
		$this->assertFalse( ZANA_Email_Turn::is_draft_followup( 'thanks', $hist ) );
		$this->assertTrue( ZANA_Email_Turn::is_style_reference( 'check my past sent emails to figure out how I write these' ) );
		ZANA_Email_Turn::$company_names = array( 'Sum Example Co' );
		$this->assertStringNotContainsString( 'Sum Example', ZANA_Email_Turn::without_company_name( 'Thanks, Sam / Sum Example Co' ) );
	}

	public function test_the_rest_period_decides_from_the_latest_contact() {
		ZANA_Email_Cooldown::$now = strtotime( '2026-05-15 12:00:00' );
		$this->assertTrue( ZANA_Email_Cooldown::decide( array( array( 'date' => '2026-05-01 10:00:00', 'type' => 'outreach' ) ), 0 )['can_email'], 'off by default' );
		$d = ZANA_Email_Cooldown::decide( array( array( 'date' => '2026-05-01 10:00:00', 'type' => 'outreach' ) ), 30 );
		$this->assertFalse( $d['can_email'] );
		$this->assertSame( 16, $d['days_remaining'] );
		$this->assertTrue( ZANA_Email_Cooldown::decide( array( array( 'date' => '2026-05-10 10:00:00', 'type' => 'inbound' ), array( 'date' => '2026-05-01 10:00:00', 'type' => 'outreach' ) ), 30 )['can_email'] );
		$this->assertSame( 'work', ZANA_Email_Cooldown::classify( 'Scheduled the installation for Tuesday' ) );
		$this->assertSame( 'outreach', ZANA_Email_Cooldown::classify( 'anything unread' ), 'an unread note counts as outreach' );
	}

	public function test_a_draft_opens_in_the_email_app_or_a_mail_link_and_never_from_the_shared_device() {
		$d = ZANA_Compose::draft_of( "Here it is:\n[ZDZ_EMAIL_DRAFT]\nTo: Riley <riley@example.com>, not-an-address\nSubject: Tuesday\n---\nHi Riley,\nTuesday works.\n[/ZDZ_EMAIL_DRAFT]" );
		$this->assertSame( 'riley@example.com', $d['to'] );
		$this->assertSame( "Hi Riley,\nTuesday works.", $d['body'] );
		$h = ZANA_Compose::open_in_email( 7, $d );
		$this->assertSame( 'mailto', $h['mode'] );
		$GLOBALS['zdz_test_filters']['zib_compose_handoff'] = function ( $prev, $uid, $req ) { return array( 'token' => str_repeat( 'a', 32 ) ); };
		$this->assertSame( 'inbox', ZANA_Compose::open_in_email( 7, $d )['mode'] );
		$this->assertArrayHasKey( 'error', ZANA_Compose::open_in_email( 9, $d ) );
		$this->assertStringContainsString( '[ZDZ_EMAIL_DRAFT]', ZANA_Markers::strip( "x\n[ZDZ_EMAIL_DRAFT]\nTo:\n\nbody\n[/ZDZ_EMAIL_DRAFT]" ), 'a whole draft is kept for its card' );
		$this->assertSame( 'body', ZANA_Markers::strip( 'body[/ZDZ_EMAIL_DRAFT]' ), 'an unpaired closer is removed' );
		$this->assertSame( 'Hi', ZANA_Compose::draft_of( "[ZDZ_EMAIL_DRAFT:Riley]\nTo:\nSubject: x\n\nHi\n[/ZDZ_EMAIL_DRAFT]" )['body'], 'a blank line also ends the headers' );
		$this->assertSame( 'x body', ZANA_Markers::strip( 'x [ZDZ_EMAIL_DRAFT:Riley]body' ), 'an unpaired named opener is removed' );
		$this->assertStringContainsString( "\n---\n", ZANA_Compose::draft_rules() );
		$this->assertSame( "To: a@example.com\nHi", ZANA_Routes::email_drafts_only_when_asked( "[ZDZ_EMAIL_DRAFT]\nTo: a@example.com\nHi[/ZDZ_EMAIL_DRAFT]", false ), 'no card on a turn that did not ask for an email' );
	}

	/* ─────────────────────────── "About you" ─────────────────────────── */

	public function test_the_sentence_rules() {
		$this->assertSame( '', ZANA_User_Memory::sentence_problem( 'Alex prefers short answers with the total first.', 'Alex' ) );
		$this->assertNotSame( '', ZANA_User_Memory::sentence_problem( 'Jordan prefers short answers.', 'Alex' ), 'about the person by name' );
		$this->assertNotSame( '', ZANA_User_Memory::sentence_problem( 'Alex has a doctor appointment on Fridays.', 'Alex' ), 'nothing sensitive' );
		$this->assertNotSame( '', ZANA_User_Memory::sentence_problem( 'Alex quotes $500 for the standard job.', 'Alex' ), 'no company detail' );
		$this->assertNotSame( '', ZANA_User_Memory::sentence_problem( 'Alex likes totals. Alex likes charts.', 'Alex' ), 'one sentence' );
		$this->assertNotSame( '', ZANA_User_Memory::sentence_problem( 'Alex prefers short answers with the total first.', 'Alex', array( array( 'fact_value' => 'Alex prefers short answers with the total first please.' ) ) ), 'already known' );
		$this->assertSame( 'remember', ZANA_User_Memory::explicit_intent( 'Please remember that I like the total first.' ) );
		$this->assertSame( '', ZANA_User_Memory::explicit_intent( "I don't remember the price." ) );
		$this->assertSame( 'forget', ZANA_User_Memory::explicit_intent( 'forget that I like charts' ) );
		$this->assertSame( '', ZANA_User_Memory::explicit_intent( 'forget about the Example Co estimate' ) );
	}

	public function test_memory_is_saved_deleted_and_forgotten_for_its_own_person_only() {
		$GLOBALS['zdz_test_user_meta'][7]['first_name'] = 'Alex';
		$GLOBALS['zdz_test_user_meta'][8]['first_name'] = 'Jordan';
		ZANA_User_Memory::$model = function ( $prompt, $uid ) { return 'Alex prefers short answers with the total first.'; };
		$this->assertMatchesRegularExpression( '/^saved #\d+/', ZANA_User_Memory::handle_turn( 7, 'Please remember that I like short answers with the total first.' ) );
		$mine = ZANA_User_Memory::sentences( 7 );
		$this->assertCount( 1, $mine );
		$this->assertSame( array(), ZANA_User_Memory::sentences( 8 ) );
		$this->assertFalse( ZANA_User_Memory::delete( 8, (int) $mine[0]['id'] ), 'another person cannot delete it' );
		$this->assertCount( 1, ZANA_User_Memory::sentences( 7 ) );
		$this->assertSame( 0, ZANA_User_Memory::picked_id( '99', $mine ), 'a forget reply must name one of the person\'s own ids' );
		ZANA_User_Memory::$model = function () use ( $mine ) { return (string) $mine[0]['id']; };
		$this->assertMatchesRegularExpression( '/^forgot #\d+/', ZANA_User_Memory::handle_turn( 7, 'forget that I like short answers' ) );
		$this->assertSame( array(), ZANA_User_Memory::sentences( 7 ) );
	}

	public function test_no_memory_on_the_shared_device_or_in_privacy_view() {
		$this->assertTrue( ZANA_User_Memory::refused( 9 ) );
		$this->assertSame( '', ZANA_User_Memory::prompt_block( 9 ) );
		$GLOBALS['zdz_test_user_meta'][7][ ZDZ_Privacy_View::META ] = '1';
		$this->assertTrue( ZANA_User_Memory::refused( 7 ) );
		ZANA_User_Memory::$model = function () { return 'Alex prefers short answers with the total first.'; };
		$this->assertSame( 'skipped', ZANA_User_Memory::handle_turn( 7, 'remember that I like short answers' ) );
		$this->assertSame( array( '2026-05-11', '2026-05-12' ), ZANA_User_Memory::real_days( array( '2026-05-11', '2026-05-12', '2026-05-30' ), array( '2026-05-11' => array( 'a' ), '2026-05-12' => array( 'b' ) ) ) );
	}

	/* ─────────────────────────── the "Chat answers" channel ─────────────────────────── */

	public function test_push_setup_is_refused_for_the_shared_device_and_privacy_view() {
		$this->assertFalse( ZANA_Push::from_capability( array( 'user_id' => 9 ) )['success'] );
		$this->assertFalse( ZANA_Push::from_capability( array( 'user_id' => 7, 'is_kiosk' => true ) )['success'] );
		$GLOBALS['zdz_test_user_meta'][7][ ZDZ_Privacy_View::META ] = '1';
		$this->assertFalse( ZANA_Push::from_capability( array( 'user_id' => 7 ) )['success'] );
	}

	public function test_the_channel_passes_the_platform_check() {
		$GLOBALS['zdz_test_options'][ ZANA_Push::OPT_PUBLIC ] = str_repeat( 'A', 87 );
		$a = ZANA_Push::from_capability( array( 'user_id' => 7 ) );
		$this->assertTrue( $a['success'] );
		$ch = ZDZ_Push_Setup::clean( ZANA_Push::VERB_SETUP, $a );
		$this->assertIsArray( $ch );
		$this->assertSame( 'chat', $ch['channel'] );
		$this->assertSame( 'zana_push_subscribe', $ch['subscribe']['action'] );
		$caps = ZANA_Push::register_capabilities( array() );
		$this->assertFalse( $caps[ ZANA_Push::VERB_SETUP ]['descriptor']['side_effect'] );
		$this->assertFalse( $caps[ ZANA_Push::VERB_SETUP ]['descriptor']['kiosk'] );
	}

	public function test_the_test_push_rate_and_endpoints() {
		$now = 1800000000;
		$this->assertCount( 2, ZANA_Push::recent_stamps( array( $now - 100, $now - 200, $now - 4000 ), $now ) );
		$this->assertSame( '', ZANA_Push::clean_endpoint( 'http://push.example.com/x' ), 'https only' );
		$this->assertSame( 'https://push.example.com/a%2Bb', ZANA_Push::clean_endpoint( 'https://push.example.com/a%2Bb' ), 'percent-encoded octets kept' );
	}

	/* ─────────────────────────── library links ─────────────────────────── */

	private function vault( array $docs ) {
		ZANA_Vault_Links::$resolver = function ( $kind, $key, $uid ) use ( $docs ) {
			foreach ( $docs as $d ) {
				if ( ( 'id' === $kind && (string) $d['id'] === $key ) || ( 'slug' === $kind && $d['slug'] === $key ) ) {
					return $d;
				}
			}
			return 'slug_like' === $kind ? array() : null;
		};
	}

	public function test_vault_citations_become_links_and_unseen_documents_are_never_named() {
		$this->vault( array( array( 'id' => 12, 'slug' => 'example-guide', 'title' => 'Example Guide', 'visibility' => 'staff' ) ) );
		$r = ZANA_Vault_Links::apply( 'See [VAULT-12] and [VAULT-99].', 7 );
		$this->assertStringContainsString( '[Example Guide](https://app.example.com/vault/example-guide)', $r['text'] );
		$this->assertStringContainsString( '(that library document was not found)', $r['text'] );
		$this->assertSame( 1, $r['linked'] );
		$this->assertSame( 1, $r['removed'] );
		$bare = ZANA_Vault_Links::apply( 'Open app.example.com/vault/example-guide now', 7 );
		$this->assertStringContainsString( '](https://app.example.com/vault/example-guide)', $bare['text'] );
		$this->assertSame( "```\n[VAULT-12]\n```", ZANA_Vault_Links::apply( "```\n[VAULT-12]\n```", 7 )['text'], 'code is kept as written' );
		$this->assertSame( 'See [VAULT-12].', ZANA_Vault_Links::apply( 'See [VAULT-12].', 0 )['text'], 'no reader, no change' );
		$this->assertSame( ' AND d.is_private = 0', ZANA_Vault_Links::not_private_sql() );
	}

	public function test_a_draft_card_gets_a_warning_line_outside_it_when_the_recipient_cannot_open_a_link() {
		$this->vault( array( array( 'id' => 12, 'slug' => 'example-guide', 'title' => 'Example Guide', 'visibility' => 'admin_only' ) ) );
		ZANA_Vault_Links::$recipient = function ( $who ) { return array( 'uid' => 8, 'name' => 'Jordan', 'kind' => 'dm' ); };
		ZANA_Vault_Links::$access    = function ( $who, $doc ) { return '(it is admin-only in the library)'; };
		$r = ZANA_Vault_Links::apply( '[ZANA_DM_DRAFT:@jordan]Read [VAULT-12][/ZANA_DM_DRAFT]', 7 );
		$this->assertStringContainsString( "[/ZANA_DM_DRAFT]\n\n", $r['text'] );
		$this->assertStringContainsString( "Jordan won't be able to open", substr( $r['text'], strpos( $r['text'], '[/ZANA_DM_DRAFT]' ) ) );
	}

	/* ─────────────────────────── access codes ─────────────────────────── */

	public function test_codes_are_cut_door_sizes_are_not_and_only_this_apps_verbs_are_wrapped() {
		ZANA_Sealed::$finder = function ( $t ) {
			$out = array();
			foreach ( array( '4821' => 'gate', '#3080' => 'door' ) as $w => $k ) {
				$p = strpos( $t, $w );
				if ( false !== $p ) {
					$out[] = array( $p, strlen( $w ), $k );
				}
			}
			return $out;
		};
		ZANA_Sealed::$labeler = function ( $k ) { return '[' . $k . ' code sealed]'; };
		$this->assertSame( 'gate [gate code sealed], storm door #3080', ZANA_Sealed::cut( 'gate 4821, storm door #3080' ) );
		$this->assertSame( array( 'a' => array( 'b' => 'code [gate code sealed]' ) ), ZANA_Sealed::deep( array( 'a' => array( 'b' => 'code 4821' ) ) ) );
		$caps = ZANA_Sealed::guard_verbs( array(
			'x.mine'  => array( 'callable' => array( 'ZANA_Starters', 'defaults' ) ),
			'y.other' => array( 'callable' => array( 'ZDZ_Capabilities', 'all' ) ),
		) );
		$this->assertInstanceOf( 'Closure', $caps['x.mine']['callable'] );
		$this->assertSame( array( 'ZDZ_Capabilities', 'all' ), $caps['y.other']['callable'] );
	}

	/* ─────────────────────────── the direct-message card ─────────────────────────── */

	public function test_a_message_draft_is_held_and_sent_once_through_messages_own_seam() {
		ZANA_DM_Card::$recipient = function ( $who ) { return '@jordan' === $who ? array( 'uid' => 8, 'name' => 'Jordan Example', 'login' => 'jordan' ) : null; };
		$sent = array();
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = function ( $prev, $from, $to, $body, $ctx ) use ( &$sent ) {
			$sent[] = array( $from, $to, $body, $ctx['source']['session_id'] );
			return array( 'conversation_id' => 44, 'message_id' => 5 );
		};
		$held = ZANA_DM_Card::hold( 'Here: [ZANA_DM_DRAFT:@jordan]Can you cover Tuesday?[/ZANA_DM_DRAFT]', 7, 3 );
		$this->assertMatchesRegularExpression( '/\[ZANA_DM_DRAFT:([a-f0-9]{32})\|@jordan\|Jordan Example\]Can you cover Tuesday\?\[\/ZANA_DM_DRAFT\]/', $held );
		preg_match( '/ZANA_DM_DRAFT:([a-f0-9]{32})/', $held, $m );
		$this->assertFalse( ZANA_DM_Card::confirm( 7, 4, $m[1] )['success'], 'another chat' );
		$this->assertFalse( ZANA_DM_Card::confirm( 8, 3, $m[1] )['success'], 'another person' );
		$ok = ZANA_DM_Card::confirm( 7, 3, $m[1] );
		$this->assertTrue( $ok['success'] );
		$this->assertSame( 44, $ok['conversation_id'] );
		$this->assertSame( array( array( 7, 8, 'Can you cover Tuesday?', 3 ) ), $sent );
		$this->assertFalse( ZANA_DM_Card::confirm( 7, 3, $m[1] )['success'], 'never twice' );
		$this->assertCount( 1, $sent );
	}

	public function test_a_draft_to_nobody_or_from_privacy_view_is_never_a_card() {
		ZANA_DM_Card::$recipient = function () { return null; };
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = function () { return null; };
		$this->assertStringNotContainsString( '[ZANA_DM_DRAFT:', ZANA_DM_Card::hold( '[ZANA_DM_DRAFT:@nobody]Hi[/ZANA_DM_DRAFT]', 7, 3 ) );
		$out = ZANA_Routes::after_model( '[ZANA_DM_DRAFT:@jordan]Hi[/ZANA_DM_DRAFT]', 7, 3, array( 'privacy_view' => true ) );
		$this->assertSame( 'Hi', $out );
	}

	/* ─────────────────────────── read adapters ─────────────────────────── */

	public function test_roles_who_can_detection_is_by_words_only() {
		$this->assertSame( array( 'query' => 'second-story ladder' ), ZANA_App_Reads::roles_detect( 'who can use a second-story ladder?' ) );
		$this->assertSame( array( 'query' => 'roof access', 'person' => 'riley' ), ZANA_App_Reads::roles_detect( 'is Riley able to do roof access' ) );
		$this->assertNull( ZANA_App_Reads::roles_detect( 'who can come tomorrow at 9?' ) );
		$this->assertNull( ZANA_App_Reads::roles_detect( 'can you send me the report' ) );
	}

	public function test_roles_route_shows_the_apps_words_and_refuses_only_its_own_kind() {
		ZANA_App_Reads::$why_not = function () { return ''; };
		ZANA_App_Reads::$invoke  = function ( $verb, $payload ) {
			return array( 'success' => true, 'message' => 'Sam and Riley.', 'ability' => array( 'key' => 'ladder_2', 'label' => 'Second-story ladder' ) );
		};
		$r = ZANA_App_Reads::route( 7, 'who can use a second-story ladder?' );
		$this->assertSame( 'answered', $r['outcome'] );
		$this->assertStringContainsString( '_From Team Roles._', $r['markdown'] );
		$this->assertStringContainsString( 'Sam and Riley\\.', $r['markdown'], 'the app\'s words as text' );
		$this->assertStringContainsString( "(Team Roles's answer was shown", ZANA_App_Reads::model_text( $r['markdown'] ), 'never shown to a model later' );
		ZANA_App_Reads::$why_not   = function () { return 'tier'; };
		ZANA_App_Reads::$abilities = array();
		$this->assertNull( ZANA_App_Reads::route( 7, 'who can use a second-story ladder?' ), 'no list: nothing is claimed' );
		ZANA_App_Reads::$abilities = array( array( 'key' => 'ladder_2', 'label' => 'Second-story ladder' ) );
		$this->assertSame( 'refused', ZANA_App_Reads::route( 7, 'who can use a second-story ladder?' )['outcome'] );
		$this->assertNull( ZANA_App_Reads::route( 9, 'who can use a second-story ladder?', array(), true ) );
		$this->assertSame( array( 'query' => 'x' ), ZANA_App_Reads::clean_payload( array( 'query' => 'x', 'user_id' => 99, 'tier' => 'admin' ) ) );
	}

	/* ─────────────────────────── hours, route plans, pages ─────────────────────────── */

	public function test_hours_route() {
		$this->assertSame( array( 'period' => 'last_week' ), ZANA_Hours_Route::detect( 'how many hours did I work last week?' ) );
		$this->assertNull( ZANA_Hours_Route::detect( 'what are your hours of operation?' ) );
		$this->assertNull( ZANA_Hours_Route::detect( 'log my hours for today' ) );
		$this->assertNull( ZANA_Hours_Route::detect( 'my hours this week vs last week' ) );
		ZANA_Hours_Route::$can    = true;
		ZANA_Hours_Route::$invoke = function ( $uid, $p ) { return array( 'success' => true, 'message' => '32.5 hours', 'period' => array( 'label' => 'Last week' ) ); };
		$a = ZANA_Hours_Route::route( 7, 'my hours last week' );
		$this->assertStringContainsString( '32.5 hours', $a );
		$this->assertSame( array( 'period' => 'today' ), ZANA_Hours_Route::follow_up( 'and today?', array( array( 'role' => 'assistant', 'content' => $a ) ) ) );
		$this->assertNull( ZANA_Hours_Route::route( 9, 'my hours last week', array(), true ) );
	}

	public function test_route_plan_requests_open_the_route_planner_with_settings_only() {
		$p = ZANA_Plan_Routes::detect( 'plan 3 door-hanger routes for saturday near 99999' );
		$this->assertSame( 3, $p['count'] );
		$this->assertSame( '99999', $p['area'] );
		$this->assertSame( '2026-05-16', $p['date'] );
		$this->assertNull( ZANA_Plan_Routes::detect( 'email Alex that we plan 3 routes' ) );
		$this->assertNull( ZANA_Plan_Routes::detect( 'plan a route on Example Dr' ) );
		ZANA_Plan_Routes::$can = true;
		$a = ZANA_Plan_Routes::answer( $p, 7 );
		$this->assertMatchesRegularExpression( ZANA_Plan_Routes::CHIP_RE, substr( $a, strpos( $a, '<div' ) ) );
		$this->assertSame( array( 'mode' => 'plan', 'area' => '99999', 'date' => '2026-05-16', 'count' => 3, 'go' => true ), ZANA_Plan_Routes::launch_options( $p ) );
		$this->assertSame( 'x', ZANA_Plan_Routes::strip_chip_markup( 'x<div class="zana-app-chips"><button data-rp="AAAA">y</button></div>' ) );
	}

	public function test_pages_open_only_from_the_business_list() {
		$this->assertNull( ZANA_Page_Nav::route( 'show me the services page' ), 'no list: no route' );
		ZANA_Page_Nav::reset( array( 'primary_pages' => array(
			array( 'id' => 'services', 'name' => 'Services', 'url' => 'https://www.example.com/services/', 'aliases' => array( 'what we do' ) ),
			array( 'id' => 'bad', 'name' => 'Bad', 'url' => 'javascript:alert(1)' ),
		), 'tools' => array( array( 'id' => 'calc', 'name' => 'Price Calculator', 'url' => 'https://www.example.com/calc/', 'category' => 'tool' ) ) ) );
		$a = ZANA_Page_Nav::route( 'show me the services page' );
		$this->assertStringContainsString( '<!--ZANA_OPEN_PAGE:{"url":"https://www.example.com/services/"', $a );
		$this->assertNull( ZANA_Page_Nav::route( 'show me services revenue last month' ), 'a data question' );
		$this->assertNotNull( ZANA_Page_Nav::route( 'open the price calculator' ), 'a tool may name a price word' );
		$this->assertNull( ZANA_Page_Nav::get_by_id( 'bad' ), 'only http(s) pages' );
		$this->assertStringContainsString( 'Did you mean', ZANA_Page_Nav::route( 'show me the servics page please' ) . ZANA_Page_Nav::render( null, 'services overview' ) );
		$this->assertStringNotContainsString( 'ZANA_OPEN_PAGE', ZANA_Routes::after_model( 'x <!--ZANA_OPEN_PAGE:{"url":"https://evil.example"}-->', 7, 1 ), 'only the page route writes the directive' );
	}

	/* ─────────────────────────── Charts ─────────────────────────── */

	private function vocab(): array {
		return array( 'measures' => array( 'jobs', 'revenue' ), 'splits' => array( array( 'key' => 'area' ) ), 'areas' => array( 'Northside', 'Southside' ), 'money' => true, 'grains' => array( 'none', 'month', 'year' ) );
	}

	public function test_chart_specs_are_checked_against_the_persons_lists() {
		list( $s, $p ) = ZANA_Charts_Tool::validate( array( 'measure' => 'jobs', 'periods' => array( '2025', 'last_12m' ), 'filters' => array( 'areas' => array( 'Northside' ) ), 'note' => 'x' ), $this->vocab() );
		$this->assertSame( array(), $p );
		$this->assertArrayNotHasKey( 'note', $s );
		list( $s, $p ) = ZANA_Charts_Tool::validate( array( 'measure' => 'profit', 'periods' => array( 'next year' ), 'filters' => array( 'areas' => array( 'Elsewhere' ), 'customers' => array( 'Riley' ) ) ), $this->vocab() );
		$this->assertNull( $s );
		$this->assertCount( 3, $p );
		$v = $this->vocab();
		$v['money'] = false;
		list( $s, $p ) = ZANA_Charts_Tool::validate( array( 'measure' => 'jobs', 'filters' => array( 'job_min' => 500 ) ), $v );
		$this->assertNull( $s, 'dollars never for a person without money' );
	}

	public function test_charts_run_through_the_registry_and_draw_the_servers_buttons() {
		$asked = array();
		ZANA_Charts_Tool::$invoke = function ( $spec, $uid ) use ( &$asked ) {
			$asked[] = $uid;
			return array( 'success' => true, 'title' => 'Jobs by month', 'measure' => 'jobs', 'unit' => 'jobs', 'columns' => array( 'period', 'value' ),
				'rows' => array( array( '2026-01', 12 ), array( '2026-02', 15 ) ), 'spec_public' => array( 'measure' => 'jobs' ) );
		};
		$text = '[ZANA_CHARTS_RUN]{"measure":"jobs","periods":["this_year"],"grain":"month"}[/ZANA_CHARTS_RUN]';
		$this->assertTrue( ZANA_Charts_Tool::only_requests( $text ) );
		ZANA_Charts_Tool::run_specs( ZANA_Charts_Tool::requests_in( $text ), 7, $this->vocab() );
		$this->assertSame( array( 7 ), $asked );
		$this->assertStringContainsString( '2026-02 | 15 jobs', ZANA_Charts_Tool::block() );
		$this->assertContains( '15', ZANA_Charts_Tool::verified_figures() );
		$chips = ZANA_Charts_Tool::chips_html();
		$this->assertStringContainsString( 'data-zch="', $chips );
		$this->assertStringContainsString( 'data-mini="', $chips );
		$this->assertSame( 'answer', ZANA_Charts_Tool::strip_chip_markup( 'answer<button class="zana-charts-open" data-zch="AAA">x</button>' ) );
		$out = ZANA_Charts_Tool::handle_markers( 'See: [ZANA_CHARTS_RUN]{"measure":"profit"}[/ZANA_CHARTS_RUN]', 7, $this->vocab(), true );
		$this->assertStringContainsString( 'Charts did not run that chart', $out );
		$this->assertStringContainsString( 'Charts is not available here', ZANA_Charts_Tool::handle_markers( '[ZANA_CHARTS_RUN]{"measure":"jobs"}[/ZANA_CHARTS_RUN]', 7, array(), false ) );
	}

	public function test_a_draft_naming_an_admin_only_zone_is_taken_out() {
		ZANA_Charts_Tool::$admin_zones = function () { return array( 'Inner Zone' ); };
		$out = ZANA_Charts_Tool::guard_admin_only_zones( "[ZDZ_EMAIL_DRAFT]\nTo: riley@example.com\n\nWe serve the Inner Zone.\n[/ZDZ_EMAIL_DRAFT]" );
		$this->assertStringNotContainsString( 'Inner Zone', $out );
		$this->assertSame( 'We serve the Inner Zone.', ZANA_Charts_Tool::guard_admin_only_zones( 'We serve the Inner Zone.' ), 'not a draft: unchanged' );
	}

	/* ─────────────────────────── labels, timings, facts, starters ─────────────────────────── */

	public function test_labels_are_names_only() {
		$this->assertSame( 'Friend', ZANA_Label_Clean::source( 'Friend. Gate code # 1234' ) );
		$this->assertSame( 'Neighbor house', ZANA_Label_Clean::source( 'Neighbor house at 12 Example Ave' ) );
		$this->assertSame( 'Springfield', ZANA_Label_Clean::city( 'Location - Springfield (XY) - (AB) 6/4 9 am install' ) );
		$this->assertSame( '', ZANA_Label_Clean::city( '1250 Example Road, Springfield' ) );
		$this->assertSame( '', ZANA_Label_Clean::source( '#5678' ) );
	}

	public function test_answer_times_keep_labels_and_times_only() {
		$row = ZANA_Turn_Timings::row( array( 'session_id' => 3, 'kind' => 'Hours!', 'status' => 'odd', 'how' => 'route', 'model' => 'm<x>' ), 100.0, 101.5 );
		$this->assertSame( 'hours', $row['kind'] );
		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 1500, $row['total_ms'] );
		$this->assertSame( 'mx', $row['model'] );
		$rows = array(
			array( 'id' => 1, 'session_id' => 3, 'asked_at' => '2026-05-15 10:00:00', 'kind' => 'hours', 'status' => 'ok', 'how' => 'route', 'total_ms' => 1000, 'model_ms' => 0, 'model' => '', 'usd' => 0, 'cut_off' => 0 ),
			array( 'id' => 2, 'session_id' => 3, 'asked_at' => '2026-05-15 10:02:00', 'kind' => 'hours', 'status' => 'ok', 'how' => 'route', 'total_ms' => 3000, 'model_ms' => 0, 'model' => '=cmd', 'usd' => 0, 'cut_off' => 0 ),
		);
		$s = ZANA_Turn_Timings::summary( $rows );
		$this->assertSame( 2.0, $s[0]['median_s'] );
		$this->assertSame( 1, $s[0]['reasked'] );
		$this->assertStringContainsString( "'=cmd", ZANA_Turn_Timings::csv( $rows ), 'never a formula in a spreadsheet' );
		$this->assertNull( ZANA_Turn_Timings::quantile( array(), 0.5 ) );
	}

	public function test_facts_above_a_level_are_never_in_its_block() {
		ZANA_Company_Facts::$rows = array(
			array( 'id' => 'hours', 'category' => 'business', 'label' => 'Opening hours', 'value' => 'Weekdays 9 to 5', 'tier_limit' => 'all', 'sort_order' => 10 ),
			array( 'id' => 'margin', 'category' => 'conventions', 'label' => 'Target', 'value' => 'Internal only', 'tier_limit' => 'admin', 'sort_order' => 20 ),
		);
		$this->assertStringNotContainsString( 'Internal only', ZANA_Company_Facts::block( 'staff' ) );
		$this->assertStringContainsString( 'Weekdays 9 to 5', ZANA_Company_Facts::block( 'staff' ) );
		$this->assertStringContainsString( 'Internal only', ZANA_Company_Facts::block( 'admin' ) );
		$this->assertCount( 1, ZANA_Company_Facts::filter_by_tier( ZANA_Company_Facts::$rows, 'unknown-level' ), 'an unknown level is the lowest' );
		$f = ZANA_Company_Facts::sanitize( array( 'id' => 'A B!', 'category' => 'x', 'tier_limit' => 'owner', 'label' => '<b>L</b>', 'value' => 'v' ) );
		$this->assertSame( array( 'ab', 'business', 'all', 'L' ), array( $f['id'], $f['category'], $f['tier_limit'], $f['label'] ) );
	}

	public function test_starters_by_role_and_none_for_the_shared_device() {
		$this->assertSame( 'sales', ZANA_Starters::bucket_for_roles( array( 'zdz_sales' ) ) );
		$this->assertSame( 'field', ZANA_Starters::bucket_for_roles( array( 'made_up_role' ) ), 'an unknown role gets the most limited list' );
		$this->assertNotEmpty( ZANA_Starters::for_user( 7 ) );
		$this->assertSame( array(), ZANA_Starters::for_user( 9 ) );
		$GLOBALS['zdz_test_options']['zana_role_prompts_sales'] = "One?\n\nTwo?\nOne?";
		$this->assertSame( array( 'One?', 'Two?' ), ZANA_Starters::for_user( 7 ) );
	}

	public function test_the_web_note() {
		$this->assertSame( '', ZANA_Web_Note::disclosure( false ) );
		$this->assertStringContainsString( 'first-pass finding', ZANA_Web_Note::disclosure( true ) );
		$this->assertStringContainsString( 'never add an outside figure', ZANA_Web_Note::prompt_rule() );
	}
}
