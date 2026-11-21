<?php
/**
 * Deep research is off until it is set up, and every gate fails closed:
 *
 *   - Core ships it off, with modest limits; a stored value outside its limits reads as the nearest one inside;
 *   - no Ai budget, no model or no workspace: no run, and the page says which is missing;
 *   - only an administrator-level account that holds "Deep research queries" may start one; the shared device, a
 *     Privacy View account and Demo Mode are refused whatever else is true;
 *   - the site's Ai calls for research in a day, a person's runs and cost in a day, and one run at a time;
 *   - the chat offers "Go deeper" only to someone who could start a run right now, and the buttons' sentences are
 *     read back exactly;
 *   - an Identity Pack sets the limits, never the switch.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}

require_once __DIR__ . '/research-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';

final class ResearchGateTest extends TestCase {

	const F = 'Zdz_Research_Fixture';

	protected function setUp(): void {
		Zdz_Research_Fixture::up();
	}

	protected function tearDown(): void {
		Zdz_Research_Fixture::down();
	}

	private function settings( array $s ): void {
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = $s + array( 'enabled' => true );
	}

	/** A run of this person's in the table, as if it had happened. */
	private function past_run( int $uid, array $row = array() ): int {
		return ZANA_Research_Store::create( $row + array( 'user_id' => $uid, 'phase' => 'done', 'approved' => 1 ) );
	}

	/* ------------------------------------------------------------------ settings */

	public function test_core_ships_it_off_with_modest_limits(): void {
		unset( $GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] );
		$s = ZANA_Research_Settings::all();
		$this->assertFalse( $s['enabled'] );
		$this->assertFalse( ZANA_Research_Settings::enabled() );
		$this->assertSame( 5, $s['round_minutes'] );
		$this->assertSame( 1.0, $s['round_usd'] );
		$this->assertSame( 20, $s['run_minutes'] );
		$this->assertSame( 5.0, $s['run_usd'] );
		$this->assertSame( 24, $s['max_steps'] );
		$this->assertSame( 60, $s['day_calls'] );
		$this->assertSame( 3, $s['person_runs_day'] );
		$this->assertSame( 5.0, $s['person_usd_day'] );
		$this->assertTrue( $s['vetting'] );
		$this->assertSame( 2, $s['min_sources'] );
		$this->assertSame( 6, $s['max_claims'] );
		$this->assertSame( '', $s['owner_groups'] );
	}

	public function test_a_stored_value_outside_its_limits_reads_as_the_nearest_inside(): void {
		$s = ZANA_Research_Settings::clean( array(
			'round_minutes' => 9000, 'round_usd' => 0, 'run_minutes' => 1, 'run_usd' => 1e9, 'max_steps' => -4, 'day_calls' => 0,
			'person_runs_day' => 'many', 'person_usd_day' => '2.345', 'min_sources' => 1, 'max_claims' => 40, 'unknown' => 'x', 'enabled' => 'yes',
		) );
		$this->assertSame( 5, $s['run_minutes'] );
		$this->assertSame( 5, $s['round_minutes'], 'a round is never longer than the whole run' );
		$this->assertSame( 0.25, $s['round_usd'] );
		$this->assertSame( 50.0, $s['run_usd'] );
		$this->assertSame( 3, $s['max_steps'] );
		$this->assertSame( 20, $s['day_calls'], 'there is no "unlimited": zero reads as the lowest limit, the calls one run needs' );
		$this->assertSame( 3, $s['person_runs_day'], 'not a number: Core\'s value' );
		$this->assertSame( 2.35, $s['person_usd_day'] );
		$this->assertSame( 2, $s['min_sources'] );
		$this->assertSame( 12, $s['max_claims'] );
		$this->assertArrayNotHasKey( 'unknown', $s );
		$this->assertTrue( $s['enabled'] );
		$this->assertSame( 1.0, ZANA_Research_Settings::clean( array( 'round_usd' => 8, 'run_usd' => 1 ) )['round_usd'], 'a round never costs more than the whole run' );
	}

	public function test_the_switch_is_on_only_for_a_plain_yes(): void {
		foreach ( array( true, 1, '1', 'on', 'yes', 'true' ) as $v ) {
			$this->assertTrue( ZANA_Research_Settings::clean( array( 'enabled' => $v ) )['enabled'] );
		}
		foreach ( array( false, 0, '0', '', 'off', 'no', null, array( 1 ), 2, 'enabled' ) as $v ) {
			$this->assertFalse( ZANA_Research_Settings::clean( array( 'enabled' => $v ) )['enabled'] );
		}
		$this->assertFalse( ZANA_Research_Settings::sanitize( array( 'round_minutes' => 4 ) )['enabled'], 'a form that does not say so leaves it off' );
		$this->assertSame( ZANA_Research_Settings::DEFAULTS, ZANA_Research_Settings::sanitize( 'not an array' ) );
	}

	public function test_owner_groups_are_kept_as_host_names_only(): void {
		$g = ZANA_Research_Settings::clean_groups( "TradeWeekly.example, citygazette.example , <b>x</b>, https://bad.example/x\nalone.example\n\na.example,a.example\n one.example ,two.example" );
		$this->assertSame( "tradeweekly.example, citygazette.example\none.example, two.example", $g );
	}

	public function test_the_form_keeps_the_site_lists_in_the_page_readers_own_option(): void {
		ZANA_Research_Settings::sanitize( array( 'deny_hosts' => "Blocked.example\nhttps://not-a-host/x  other.example", 'allow_hosts' => '' ) );
		$lists = ZDZ_Page_Fetch::hosts();
		$this->assertSame( array( 'blocked.example', 'other.example' ), $lists['deny'] );
		$this->assertSame( array(), $lists['allow'] );
		$this->assertArrayNotHasKey( 'deny_hosts', ZANA_Research_Settings::sanitize( array( 'deny_hosts' => 'a.example' ) ) );
	}

	public function test_what_is_missing_is_said_and_nothing_is_missing_when_the_site_is_set_up(): void {
		$this->assertSame( array(), ZANA_Research_Settings::missing() );
		unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		$m = ZANA_Research_Settings::missing();
		$this->assertCount( 1, $m );
		$this->assertStringContainsString( 'budget', $m[0] );
		unset( $GLOBALS['zdz_test_options']['zdz_model_slot_llm_primary'] );
		unset( $GLOBALS['zdz_test_filters']['zdz_anthropic_workspace_id'] );
		$m = implode( ' | ', ZANA_Research_Settings::missing() );
		$this->assertStringContainsString( 'budget', $m );
		$this->assertStringContainsString( 'model', $m );
		$this->assertStringContainsString( 'workspace', $m );
	}

	/* ------------------------------------------------------------------ the feature */

	public function test_off_means_no_run_for_anyone(): void {
		$this->settings( array( 'enabled' => false ) );
		$this->assertStringContainsString( 'switched off', ZANA_Research_Gate::refusal( Zdz_Research_Fixture::OWNER ) );
		$this->assertStringContainsString( 'switched off', ZANA_Research_Gate::site_refusal() );
		$this->assertTrue( ZANA_Research_Gate::could_ever( Zdz_Research_Fixture::OWNER ), 'who it could run for is a separate question' );
		$this->assertFalse( ZANA_Research_Settings::page_config( Zdz_Research_Fixture::OWNER )['canStart'] );
	}

	public function test_an_administrator_on_a_site_that_is_set_up_may_start_one(): void {
		$this->assertSame( '', ZANA_Research_Gate::refusal( Zdz_Research_Fixture::OWNER ) );
		$this->assertTrue( ZANA_Research_Settings::page_config( Zdz_Research_Fixture::OWNER )['canStart'] );
	}

	/** @dataProvider not_set_up */
	public function test_a_site_that_is_not_set_up_runs_nothing( string $what, string $word ): void {
		if ( 'budget' === $what ) {
			unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		} elseif ( 'model' === $what ) {
			unset( $GLOBALS['zdz_test_options']['zdz_model_slot_llm_primary'] );
		} else {
			unset( $GLOBALS['zdz_test_filters']['zdz_anthropic_workspace_id'] );
		}
		$no = ZANA_Research_Gate::refusal( Zdz_Research_Fixture::OWNER );
		$this->assertStringContainsString( 'not set up', $no );
		$this->assertStringContainsString( $word, $no );
		$this->assertSame( 0, ZANA_Research::turn( Zdz_Research_Fixture::OWNER, 5, 'deep research on our sales trend please', array() )['run'] ?? 0 );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows( 'zana_research' ), 'no run is made' );
		$this->assertSame( array(), Zdz_Research_Fixture::$router->fake->requests, 'and nothing is sent to the Ai' );
	}

	public function not_set_up(): array {
		return array(
			'no Ai budget'  => array( 'budget', 'budget' ),
			'no Ai model'   => array( 'model', 'model' ),
			'no workspace'  => array( 'workspace', 'workspace' ),
		);
	}

	/* ------------------------------------------------------------------ the person */

	public function test_nobody_the_shared_device_and_staff_are_refused(): void {
		$F = self::F;
		$this->assertStringContainsString( 'signed-in', ZANA_Research_Gate::refusal( 0 ) );
		$this->assertStringContainsString( 'shared device', ZANA_Research_Gate::refusal( $F::KIOSK ) );
		$this->assertStringContainsString( 'isn\'t available on this account', ZANA_Research_Gate::refusal( $F::SALES ) );
		$this->assertStringContainsString( 'isn\'t available on this account', ZANA_Research_Gate::refusal( 999 ), 'an id that is no user at all' );
		foreach ( array( 0, $F::KIOSK, $F::SALES, 999 ) as $uid ) {
			$this->assertFalse( ZANA_Research_Gate::could_ever( $uid ) );
			$this->assertFalse( ZANA_Research_Settings::page_config( $uid )['canStart'] );
		}
	}

	public function test_the_shared_device_is_refused_even_as_an_administrator(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_roles'][ $F::KIOSK ] = array( 'administrator', 'zdz_general' );
		$F::flush();
		$this->assertStringContainsString( 'shared device', ZANA_Research_Gate::refusal( $F::KIOSK ) );
	}

	public function test_a_staff_account_is_refused_even_when_given_the_permission(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_meta'][ $F::SALES ]['zdz_data_permissions'] = array( 'access_deep_research' => 'allow' );
		$F::flush();
		$this->assertTrue( ZANA_Research_Gate::can( $F::SALES, 'access_deep_research' ) );
		$this->assertStringContainsString( 'isn\'t available on this account', ZANA_Research_Gate::refusal( $F::SALES ), 'the permission narrows who may; it never widens it past administrators' );
	}

	public function test_an_administrator_without_the_permission_is_refused(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_meta'][ $F::OWNER ]['zdz_data_permissions'] = array( 'access_deep_research' => 'deny' );
		$F::flush();
		$this->assertStringContainsString( 'Data Permissions', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::could_ever( $F::OWNER ) );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::ADMIN ), 'another administrator is not affected' );
	}

	public function test_a_privacy_view_account_is_refused(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_meta'][ $F::OWNER ][ ZDZ_Privacy_View::META ] = '1';
		$this->assertTrue( ZANA_Research_Gate::privacy_view( $F::OWNER ) );
		$this->assertStringContainsString( 'Privacy View', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::could_ever( $F::OWNER ) );
		$this->assertFalse( ZANA_Research_Settings::page_config( $F::OWNER )['canStart'] );
	}

	public function test_demo_mode_is_refused(): void {
		ZANA_Research_Gate::$demo = function () {
			return true;
		};
		$this->assertStringContainsString( 'Demo Mode', ZANA_Research_Gate::refusal( Zdz_Research_Fixture::OWNER ) );
	}

	public function test_the_web_needs_its_own_permission(): void {
		$F = self::F;
		$this->assertTrue( ZANA_Research_Gate::web_allowed( $F::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::web_allowed( 0 ) );
		$this->assertFalse( ZANA_Research_Gate::web_allowed( $F::KIOSK ) );
		$GLOBALS['zdz_test_user_meta'][ $F::OWNER ]['zdz_data_permissions'] = array( 'access_web_research' => 'deny' );
		$F::flush();
		$this->assertFalse( ZANA_Research_Gate::web_allowed( $F::OWNER ) );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ), 'a run may still start: on the business\'s own data only' );
	}

	public function test_without_the_platforms_permissions_nobody_may(): void {
		ZANA_Research_Gate::$permission = null;   // and the class is not loaded here
		$this->assertFalse( class_exists( 'ZDZ_Data_Permissions', false ) );
		$this->assertStringContainsString( 'Data Permissions', ZANA_Research_Gate::refusal( Zdz_Research_Fixture::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::web_allowed( Zdz_Research_Fixture::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::could_ever( Zdz_Research_Fixture::OWNER ) );
	}

	/* ------------------------------------------------------------------ in which order a refusal is said */

	public function test_an_account_below_administrator_level_hears_only_that_it_is_not_available_to_it(): void {
		$F = self::F;
		$this->settings( array( 'enabled' => false ) );
		$this->assertStringContainsString( 'isn\'t available on this account', ZANA_Research_Gate::refusal( $F::SALES ), 'not how the site is set up' );
		unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		$this->assertStringContainsString( 'isn\'t available on this account', ZANA_Research_Gate::refusal( $F::SALES ) );
	}

	public function test_an_administrator_hears_the_site_first_then_the_permission_then_the_limits(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_meta'][ $F::OWNER ]['zdz_data_permissions'] = array( 'access_deep_research' => 'deny' );
		$this->settings( array( 'enabled' => false ) );
		$this->assertStringContainsString( 'switched off', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->settings( array( 'enabled' => true ) );
		$this->assertStringContainsString( 'Data Permissions', ZANA_Research_Gate::refusal( $F::OWNER ) );
		unset( $GLOBALS['zdz_test_user_meta'][ $F::OWNER ]['zdz_data_permissions'] );
		ZANA_Research_Store::create( array( 'user_id' => $F::OWNER, 'phase' => 'researching', 'approved' => 1 ) );
		$this->assertStringContainsString( 'in progress', ZANA_Research_Gate::refusal( $F::OWNER ) );
	}

	/* ------------------------------------------------------------------ never mentioned */

	public function test_some_turns_never_hear_of_deep_research(): void {
		$F = self::F;
		$this->assertFalse( ZANA_Research_Gate::silent( $F::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::silent( $F::SALES ), 'an account below administrator level is told it is not available' );
		$this->assertTrue( ZANA_Research_Gate::silent( 0 ) );
		$this->assertTrue( ZANA_Research_Gate::silent( $F::KIOSK ) );
		$GLOBALS['zdz_test_user_meta'][ $F::ADMIN ][ ZDZ_Privacy_View::META ] = '1';
		$this->assertTrue( ZANA_Research_Gate::silent( $F::ADMIN ) );
		ZANA_Research_Gate::$demo = function () {
			return true;
		};
		$this->assertTrue( ZANA_Research_Gate::silent( $F::OWNER ) );
	}

	public function test_a_reply_written_in_a_messages_thread_never_starts_or_continues_a_run(): void {
		$F    = self::F;
		$seen = array();
		ZANA_Research_Gate::$messages_turn = function ( $uid ) use ( &$seen ) {
			$seen[] = $uid;
			return true;
		};
		$this->assertTrue( ZANA_Research_Gate::in_messages_reply( $F::OWNER ) );
		$this->assertTrue( ZANA_Research_Gate::silent( $F::OWNER ) );
		$past = ZANA_Research_Store::create( array( 'user_id' => $F::OWNER, 'phase' => 'done', 'approved' => 1, 'wrapup_at' => gmdate( 'Y-m-d H:i:s', $F::$now ), 'ended_on' => 'time', 'ended_in' => 'researching' ) );
		foreach ( array( 'Do deep research on our warranty claims please', ZANA_Research_Gate::SAY_MORE . $past, ZANA_Research_Gate::SAY_DEEPER . ' #0123abcd' ) as $msg ) {
			$this->assertNull( ZANA_Research::turn( $F::OWNER, 5, $msg, array( array( 'role' => 'user', 'content' => 'Why did repair sales fall in spring?' ) ) ), $msg );
		}
		$this->assertCount( 1, $GLOBALS['wpdb']->rows( 'zana_research' ), 'no run was started or continued' );
		$this->assertSame( 0, ZANA_Research_Store::get( $past )['continued'] );
		$q = 'Why did our repair sales fall in the spring months?';
		$this->assertSame( 'An answer.', ZANA_Research::after_answer( 'An answer. [ZANA_RESEARCH:9]', $F::OWNER, $q, array( 'note' => array( 'banner' => 'x' ) ) ), 'no button, no note, and no token' );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, $q ) );
		$this->assertNotEmpty( $seen );
	}

	public function test_outside_a_messages_reply_the_chat_is_not_one(): void {
		ZANA_Research_Gate::$messages_turn = null;   // asked of the assistant's Messages replies, when that part is installed
		$this->assertFalse( ZANA_Research_Gate::in_messages_reply( Zdz_Research_Fixture::OWNER ) );
		$this->assertFalse( ZANA_Research_Gate::silent( Zdz_Research_Fixture::OWNER ) );
	}

	public function test_privacy_view_and_demo_mode_get_no_note_when_they_name_it(): void {
		$F = self::F;
		$GLOBALS['zdz_test_user_meta'][ $F::OWNER ][ ZDZ_Privacy_View::META ] = '1';
		$this->assertNull( ZANA_Research::turn( $F::OWNER, 5, 'Do deep research on our warranty claims please', array() ) );
		$this->assertSame( 'An answer.', ZANA_Research::after_answer( 'An answer.', $F::OWNER, 'Do deep research on our warranty claims please', array() ) );
		unset( $GLOBALS['zdz_test_user_meta'][ $F::OWNER ][ ZDZ_Privacy_View::META ] );
		ZANA_Research_Gate::$demo = function () {
			return true;
		};
		$this->assertNull( ZANA_Research::turn( $F::OWNER, 5, 'Do deep research on our warranty claims please', array() ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows( 'zana_research' ) );
	}

	/* ------------------------------------------------------------------ the limits */

	public function test_a_run_is_not_started_when_the_days_calls_could_not_finish_one(): void {
		$F = self::F;
		$this->past_run( $F::ADMIN, array( 'calls' => 40 ) );
		$this->assertSame( 20, ZANA_Research_Gate::day_calls_left() );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ), 'exactly enough left' );
		$this->past_run( $F::ADMIN, array( 'calls' => 1 ) );
		$no = ZANA_Research_Gate::refusal( $F::OWNER );
		$this->assertStringContainsString( '41 of 60 Ai calls used', $no, 'the numbers are said' );
		$this->assertStringContainsString( 'starts again at', $no );
		$this->assertStringContainsString( 'Ai calls a day', $no, 'and the setting, as the settings page names it' );
	}

	public function test_yesterdays_calls_do_not_count_today(): void {
		$F = self::F;
		$this->past_run( $F::ADMIN, array( 'calls' => 500, 'started_at' => gmdate( 'Y-m-d H:i:s', $F::$now - 86400 ) ) );
		$this->assertSame( 60, ZANA_Research_Gate::day_calls_left() );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ) );
	}

	public function test_a_person_may_start_only_so_many_runs_a_day(): void {
		$F = self::F;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ) );
			$this->past_run( $F::OWNER );
		}
		$this->assertStringContainsString( 'already started 3 deep research runs today', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::ADMIN ), 'the limit is per person' );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER, true ), 'another round of a run already started is not another run' );
	}

	public function test_a_plan_that_was_never_started_is_not_a_run_against_the_days_count(): void {
		$F = self::F;
		for ( $i = 0; $i < 5; $i++ ) {
			$this->past_run( $F::OWNER, array( 'approved' => 0, 'phase' => 'stopped' ) );
		}
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ) );
	}

	public function test_a_persons_research_cost_has_a_daily_limit_rounds_included(): void {
		$F = self::F;
		$this->past_run( $F::OWNER, array( 'cost_milli' => 4999 ) );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->past_run( $F::OWNER, array( 'cost_milli' => 1, 'approved' => 0 ) );
		$this->assertStringContainsString( 'limit of $5.00', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->assertStringContainsString( 'limit of $5.00', ZANA_Research_Gate::refusal( $F::OWNER, true ), 'a "Keep going" round is refused too' );
	}

	public function test_one_run_at_a_time_a_person(): void {
		$F  = self::F;
		$id = $this->past_run( $F::OWNER, array( 'phase' => 'researching' ) );
		$this->assertStringContainsString( 'deep research #' . $id . ' in progress', ZANA_Research_Gate::refusal( $F::OWNER ) );
		$this->assertSame( '', ZANA_Research_Gate::refusal( $F::ADMIN ) );
	}

	/* ------------------------------------------------------------------ what was asked */

	/** @dataProvider by_name */
	public function test_asking_for_it_by_name( string $message, bool $asked ): void {
		$this->assertSame( $asked, ZANA_Research_Gate::asked_by_name( $message ), $message );
	}

	public function by_name(): array {
		$fence = ZANA_Markers::fence( 'A shared block that says deep research in it.' );
		return array(
			array( 'Do deep research on our warranty claims', true ),
			array( 'do a Deep-Research pass on pricing', true ),
			array( 'A deep dive into last quarter please', true ),
			array( 'deepresearch the returns', true ),
			array( 'Research our returns in depth', false ),
			array( 'How deep is the research pool?', false ),
			array( 'What is this about? ' . $fence, false ),
			array( '', false ),
		);
	}

	public function test_the_button_is_offered_only_where_a_run_could_start(): void {
		$F = self::F;
		$q = 'Why did our repair sales fall in the spring months?';
		$this->assertTrue( ZANA_Research_Gate::may_offer( $F::OWNER, $q ) );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::SALES, $q ), 'not someone it could never run for' );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, 'Why did sales fall?' ), 'too short a question' );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, 'Do deep research on why our repair sales fell' ), 'it was asked for by name' );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, $q, array( 'privacy_view' => true ) ) );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, $q, array( 'email_task' => true ) ) );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, 'Write an email to our supplier about the late delivery of parts' ), 'an email to write' );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, ZANA_Research_Gate::SAY_DEEPER . ' #0123abcd' ) );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, ZANA_Research_Gate::SAY_MORE . '4' ) );
		$this->settings( array( 'enabled' => false ) );
		$this->assertFalse( ZANA_Research_Gate::may_offer( $F::OWNER, $q ), 'never while the feature is off' );
	}

	public function test_a_questions_tag_ignores_spacing_case_and_tags(): void {
		$a = ZANA_Research_Gate::tag( 'Why did sales fall in spring?' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $a );
		$this->assertSame( $a, ZANA_Research_Gate::tag( "  why did   SALES fall\nin spring?  " ) );
		$this->assertSame( $a, ZANA_Research_Gate::tag( '<b>Why did sales</b> fall in spring?' ) );
		$this->assertNotSame( $a, ZANA_Research_Gate::tag( 'Why did sales fall in summer?' ) );
	}

	public function test_a_go_deeper_press_finds_its_own_question(): void {
		$G    = 'ZANA_Research_Gate';
		$old  = 'Why did our repair sales fall in the spring months?';
		$new  = 'And how many estimates did we send last month?';
		$hist = array(
			array( 'role' => 'user', 'content' => $old ),
			array( 'role' => 'assistant', 'content' => 'An answer.' ),
			array( 'role' => 'user', 'content' => $new ),
			array( 'role' => 'assistant', 'content' => 'Another answer.' ),
			array( 'role' => 'user', 'content' => $G::SAY_DEEPER . ' #' . $G::tag( $new ) ),
			array( 'role' => 'user', 'content' => $G::SAY_MORE . '3' ),
		);
		$this->assertSame( $old, $G::deeper_question( $G::SAY_DEEPER . ' #' . $G::tag( $old ), $hist ), 'the question the button sat under, not the newest' );
		$this->assertSame( $new, $G::deeper_question( $G::SAY_DEEPER, $hist ), 'without a tag: the newest question, never a press' );
		$this->assertSame( $new, $G::deeper_question( $G::SAY_DEEPER . ' #ffffffff', $hist ), 'an unknown tag: the newest question' );
		$this->assertSame( '', $G::deeper_question( $G::SAY_DEEPER, array() ) );
		$this->assertNull( $G::deeper_question( 'Deep research on my question above, and add prices', $hist ), 'only the exact sentence is the press' );
		$this->assertNull( $G::deeper_question( $G::SAY_DEEPER . ' #XYZ', $hist ) );
		$this->assertSame( '', $G::deeper_question( $G::SAY_DEEPER, array( array( 'role' => 'assistant', 'content' => 'x' ), 'junk', array( 'role' => 'user', 'content' => array( 'parts' ) ) ) ) );
	}

	public function test_a_keep_going_press_is_the_exact_sentence(): void {
		$G = 'ZANA_Research_Gate';
		$this->assertSame( 12, $G::more_id( 'Keep going on deep research #12' ) );
		$this->assertSame( 12, $G::more_id( '  Keep going on deep research #12 ' ) );
		$this->assertSame( 0, $G::more_id( 'Keep going on deep research #12 and add prices' ) );
		$this->assertSame( 0, $G::more_id( 'keep going on deep research #12' ) );
		$this->assertSame( 0, $G::more_id( 'Keep going on deep research #' ) );
		$this->assertSame( 0, $G::more_id( 'Keep going on deep research #12345678901' ) );
		$this->assertSame( 0, $G::more_id( 'Keep going on deep research #1 OR 1=1' ) );
	}

	/* ------------------------------------------------------------------ the page's words, and a pack */

	public function test_the_pages_words_name_no_one_and_carry_one_rounds_size(): void {
		$this->settings( array( 'round_minutes' => 7, 'round_usd' => 1.5 ) );
		$c = ZANA_Research_Settings::page_config( Zdz_Research_Fixture::OWNER );
		$this->assertSame( 7, $c['roundMin'] );
		$this->assertSame( 1.5, $c['roundUsd'] );
		$this->assertNotEmpty( $c['i18n']['goDeeper'] );
		foreach ( $c['i18n'] as $k => $v ) {
			$this->assertIsString( $v, $k );
			$this->assertDoesNotMatchRegularExpression( '/claude|anthropic|openai|gemini|gpt|opus/i', $v, $k . ': no provider or model is named' );
		}
	}

	public function test_a_pack_sets_the_limits_and_never_the_switch(): void {
		$this->settings( array( 'enabled' => false ) );
		$plan = ZANA_Research_Settings::plan_pack( array( 'research' => array(
			'enabled'       => true,
			'round_minutes' => 8,
			'round_usd'     => 2,
			'day_calls'     => 100000,
			'max_claims'    => 'six',
			'vetting'       => 'yes',
			'owner_groups'  => array( 'tradeweekly.example, citygazette.example', 'alone.example' ),
		) ) );
		$refused = array_column( $plan['refused'], 'path' );
		$this->assertContains( 'research.enabled', $refused, 'the switch is not a pack\'s to write' );
		$this->assertContains( 'research.day_calls', $refused );
		$this->assertContains( 'research.max_claims', $refused );
		$this->assertContains( 'research.vetting', $refused, 'true or false, not a word' );
		$this->assertContains( 'research.owner_groups.2', $refused );
		$written = array();
		foreach ( $plan['writes'] as $w ) {
			$this->assertSame( ZANA_Research_Settings::OPTION, $w['option'] );
			$written[ $w['key'] ] = $w['value'];
		}
		$this->assertSame( array( 'round_minutes' => 8, 'round_usd' => 2.0 ), $written, 'a list with a bad group is not written at all' );
		$this->assertNotEmpty( $plan['notes'] );
		$ok = ZANA_Research_Settings::plan_pack( array( 'research' => array( 'vetting' => false, 'owner_groups' => array( 'tradeweekly.example, citygazette.example' ) ) ) );
		$this->assertSame( array(), $ok['refused'] );
		$this->assertCount( 2, $ok['writes'] );
	}

	public function test_the_pack_consumer_reads_rules_research_only(): void {
		$c = ZANA_Research_Settings::register_pack( array() );
		$this->assertArrayHasKey( 'assistant-research', $c );
		$this->assertSame( array( 'rules' ), $c['assistant-research']['files'] );
		$this->assertSame( array( 'rules' => array( 'research' ) ), $c['assistant-research']['paths'] );
	}
}
