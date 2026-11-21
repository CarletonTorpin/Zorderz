<?php
/**
 * The step runner: long work as a chain of short steps. One worker a job, a start nobody can forge, a step that
 * hands itself on is not a failure, three failures end a job, Stop closes what is not running and is read by what
 * is, a job that has ended never moves back, and the watchdog starts a quiet job again a bounded number of times.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/step-runner-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-step-runner.php';

final class StepRunnerTest extends TestCase {

	/** @var Zdz_Steps_Test_Db */
	private $db;
	private $now = 1700000000;
	/** @var array<int,array{0:int,1:string}> every start request "sent", oldest first */
	private $kicks = array();
	private $armed = 0;
	/** @var array<int,string> what the handlers were told */
	private $told = array();
	/** @var callable|null */
	private $step;
	private $steps_run = 0;

	protected function setUp(): void {
		$this->db                    = new Zdz_Steps_Test_Db();
		$GLOBALS['wpdb']             = $this->db;
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
		$this->now                   = 1700000000;
		$this->kicks                 = array();
		$this->armed                 = 0;
		$this->told                  = array();
		$this->steps_run             = 0;
		$this->step                  = function () {
			return 'again';
		};
		ZDZ_Step_Runner::reset();
		ZDZ_Step_Runner::$now      = function () {
			return $this->now;
		};
		ZDZ_Step_Runner::$origin   = null;
		ZDZ_Step_Runner::$sapi     = null;
		ZDZ_Step_Runner::$loopback = function ( $id, $token ) {
			$this->kicks[] = array( (int) $id, (string) $token );
		};
		ZDZ_Step_Runner::$armer    = function () {
			$this->armed++;
		};
		ZDZ_Step_Runner::register( 'survey', array(
			'step'      => function ( array $job ) {
				$this->steps_run++;
				return call_user_func( $this->step, $job );
			},
			'stopped'   => function ( array $job, $why ) {
				$this->told[] = 'stopped:' . $why;
			},
			'failed'    => function ( array $job, $error ) {
				$this->told[] = 'failed:' . $error;
			},
			'abandoned' => function ( array $job, $why ) {
				$this->told[] = 'abandoned:' . $why;
			},
			'retrying'  => function ( array $job, $e, $n ) {
				$this->told[] = 'retrying:' . $n;
			},
		) );
	}

	protected function tearDown(): void {
		ZDZ_Step_Runner::reset();
		ZDZ_Step_Runner::$now      = null;
		ZDZ_Step_Runner::$origin   = null;
		ZDZ_Step_Runner::$sapi     = null;
		ZDZ_Step_Runner::$loopback = null;
		ZDZ_Step_Runner::$armer    = null;
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_current_user'] );
	}

	private function job( int $id ): array {
		return (array) ZDZ_Step_Runner::get( $id );
	}

	private function start( array $args = array() ): int {
		return ZDZ_Step_Runner::start( 'survey', 7, $args );
	}

	/* ------------------------------------------------------------------ kinds and starting */

	public function test_a_kind_needs_a_step_handler_and_keeps_its_first_registration(): void {
		$this->assertFalse( ZDZ_Step_Runner::register( 'empty', array() ) );
		$this->assertFalse( ZDZ_Step_Runner::register( 'empty', array( 'step' => 'no_such_function_here' ) ) );
		$this->assertFalse( ZDZ_Step_Runner::register( '', array( 'step' => 'strlen' ) ) );
		$this->assertTrue( ZDZ_Step_Runner::register( 'other', array( 'step' => function () {
			return 'done';
		} ) ) );
		$this->assertFalse( ZDZ_Step_Runner::register( 'other', array( 'step' => function () {
			return 'again';
		} ) ), 'a second registration must not replace the first' );
		$id = ZDZ_Step_Runner::start( 'other', 7, array( 'kick' => false ) );
		$this->assertSame( 'done', ZDZ_Step_Runner::run( $id ) );
	}

	public function test_a_job_starts_queued_with_its_stage_state_and_deadline(): void {
		$id  = $this->start( array( 'stage' => 'Plan', 'state' => array( 'q' => 'x' ), 'deadline' => $this->now + 600 ) );
		$job = $this->job( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 'plan', $job['stage'] );
		$this->assertSame( array( 'q' => 'x' ), $job['state'] );
		$this->assertSame( 7, $job['owner'] );
		$this->assertSame( $this->now + 600, ZDZ_Step_Runner::ts( $job['deadline_at'] ) );
		$this->assertCount( 1, $this->kicks, 'its first step is started' );
		$this->assertSame( 1, $this->armed, 'the sweep is armed while a job is live' );
		$this->assertCount( 1, $this->db->ddl, 'the table is made on first use' );
		$this->start();
		$this->assertCount( 1, $this->db->ddl, '... and only once' );
	}

	public function test_no_job_starts_for_an_unknown_kind_for_nobody_or_without_a_table(): void {
		$this->assertSame( 0, ZDZ_Step_Runner::start( 'nothing', 7 ) );
		$this->assertSame( 0, ZDZ_Step_Runner::start( 'survey', 0 ) );
		$this->db->fail_insert = true;
		$this->assertSame( 0, $this->start() );
		$this->assertSame( array(), $this->kicks );
	}

	public function test_no_job_starts_when_the_table_could_not_be_made(): void {
		$this->db->fail_query = 'CREATE TABLE';
		$this->assertSame( 0, $this->start() );
		$this->assertArrayNotHasKey( ZDZ_Step_Runner::DB_OPTION, $GLOBALS['zdz_test_options'] );
	}

	public function test_a_job_can_be_made_without_starting_its_first_step(): void {
		$id = $this->start( array( 'kick' => false ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( array(), $this->kicks );
	}

	/* ------------------------------------------------------------------ the start token */

	public function test_a_start_token_works_once_and_only_its_hash_is_kept(): void {
		$id                = $this->start();
		list( , $token )   = $this->kicks[0];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertSame( hash( 'sha256', $token ), $this->job( $id )['kick_token'] );
		$this->assertNotSame( $token, $this->job( $id )['kick_token'] );
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id, str_repeat( 'a', 32 ) ), 'a guessed token' );
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id + 1, $token ), 'another job' );
		$this->assertTrue( ZDZ_Step_Runner::consume_kick( $id, $token ) );
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id, $token ), 'a token is used up by its first use' );
		$this->assertSame( '', $this->job( $id )['kick_token'] );
	}

	public function test_a_malformed_token_is_refused_before_the_table_is_asked(): void {
		$id = $this->start();
		$n  = count( $this->db->log );
		foreach ( array( '', 'short', str_repeat( 'g', 32 ), str_repeat( 'a', 31 ), str_repeat( 'a', 33 ), "' OR 1=1 --" ) as $bad ) {
			$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id, $bad ) );
		}
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( 0, str_repeat( 'a', 32 ) ) );
		$this->assertCount( $n, $this->db->log );
	}

	public function test_an_empty_stored_token_matches_nothing(): void {
		$id = $this->start();
		ZDZ_Step_Runner::consume_kick( $id, $this->kicks[0][1] );
		// the hash of an empty token is not '', and a token must be 32 hex characters anyway
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id, '' ) );
	}

	public function test_one_of_two_requests_holding_the_same_token_starts_the_step(): void {
		$id                     = $this->start();
		$token                  = $this->kicks[0][1];
		$second                 = null;
		$this->db->before_match = "SET kick_token = ''";
		$this->db->before       = function () use ( $id, $token, &$second ) {
			$second = ZDZ_Step_Runner::consume_kick( $id, $token );   // the same request, delivered twice, arriving together
		};
		$first = ZDZ_Step_Runner::consume_kick( $id, $token );
		$this->assertTrue( $second );
		$this->assertFalse( $first );
	}

	public function test_no_token_is_looked_up_before_the_table_exists(): void {
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( 1, str_repeat( 'a', 32 ) ) );
		$this->assertSame( array(), $this->db->log );
	}

	public function test_a_new_start_replaces_the_old_token(): void {
		$id = $this->start();
		ZDZ_Step_Runner::kick( $id );
		$this->assertFalse( ZDZ_Step_Runner::consume_kick( $id, $this->kicks[0][1] ) );
		$this->assertTrue( ZDZ_Step_Runner::consume_kick( $id, $this->kicks[1][1] ) );
	}

	/* ------------------------------------------------------------------ one step */

	public function test_a_step_that_says_again_is_followed_by_the_next_one(): void {
		$id = $this->start();
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 1, $job['runs'] );
		$this->assertCount( 2, $this->kicks );
		$this->assertNull( $job['lease_until'], 'the job is given back after the step' );
	}

	public function test_a_step_that_says_done_ends_the_job_and_starts_nothing(): void {
		$this->step = function () {
			return 'done';
		};
		$id         = $this->start();
		$this->assertSame( 'done', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'done', $this->job( $id )['status'] );
		$this->assertCount( 1, $this->kicks );
		$this->assertSame( 'ended', ZDZ_Step_Runner::run( $id ), 'an ended job runs no step' );
		$this->assertSame( 1, $this->steps_run );
	}

	public function test_a_step_that_says_wait_parks_the_job_until_its_person_answers(): void {
		$this->step = function () {
			return 'wait';
		};
		$id         = $this->start();
		$this->assertSame( 'waiting', ZDZ_Step_Runner::run( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'waiting', $job['status'] );
		$this->assertSame( $this->now + ZDZ_Step_Runner::WAIT_S, ZDZ_Step_Runner::ts( $job['wait_until'] ) );
		$this->assertCount( 1, $this->kicks );
		$this->assertSame( 'waiting', ZDZ_Step_Runner::run( $id ), 'a waiting job runs no step' );
		$this->assertSame( 1, $this->steps_run );
	}

	public function test_a_step_may_say_how_long_to_wait(): void {
		$this->step = function () {
			return array( 'next' => 'wait', 'wait_s' => 600 );
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( $this->now + 600, ZDZ_Step_Runner::ts( $this->job( $id )['wait_until'] ) );
	}

	public function test_a_step_runs_as_the_jobs_owner(): void {
		$GLOBALS['zdz_test_current_user'] = 0;
		$seen                             = null;
		$this->step                       = function () use ( &$seen ) {
			$seen = get_current_user_id();
			return 'done';
		};
		ZDZ_Step_Runner::run( $this->start() );
		$this->assertSame( 7, $seen );
	}

	public function test_a_step_keeps_its_place_and_never_its_status(): void {
		$id         = $this->start();
		$this->step = function ( array $job ) {
			ZDZ_Step_Runner::set( (int) $job['id'], array( 'stage' => 'Read', 'step' => 3, 'progress' => 'P2', 'state' => array( 'n' => 2 ), 'deadline' => $this->now + 90, 'status' => 'done' ) );
			return 'again';
		};
		ZDZ_Step_Runner::run( $id );
		$job = $this->job( $id );
		$this->assertSame( 'read', $job['stage'] );
		$this->assertSame( 3, $job['step'] );
		$this->assertSame( 'p2', $job['progress'] );
		$this->assertSame( array( 'n' => 2 ), $job['state'] );
		$this->assertSame( $this->now + 90, ZDZ_Step_Runner::ts( $job['deadline_at'] ) );
		$this->assertSame( 'queued', $job['status'] );
	}

	public function test_a_missing_job_and_an_unloaded_kind_run_nothing(): void {
		$this->assertSame( 'missing', ZDZ_Step_Runner::run( 99 ) );
		$id = $this->start();
		ZDZ_Step_Runner::reset();
		$this->assertSame( 'unknown_kind', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'queued', $this->job( $id )['status'] );
		$this->assertSame( 0, $this->job( $id )['runs'] );
	}

	/* ------------------------------------------------------------------ one worker a job */

	public function test_a_job_another_worker_holds_is_left_alone(): void {
		$id = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id, array( 'lease_until' => gmdate( 'Y-m-d H:i:s', $this->now + 60 ), 'lease_token' => 'other' ) );
		$this->assertSame( 'busy', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 0, $this->steps_run );
		$this->assertSame( 'other', $this->job( $id )['lease_token'], 'the other worker keeps its hold' );
		$this->assertCount( 1, $this->kicks );
	}

	public function test_a_hold_that_ran_out_can_be_taken(): void {
		$id = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id, array( 'lease_until' => gmdate( 'Y-m-d H:i:s', $this->now - 1 ), 'lease_token' => 'gone' ) );
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 1, $this->steps_run );
	}

	public function test_two_requests_for_one_job_run_one_step(): void {
		$id         = $this->start();
		$inner      = null;
		$this->step = function () use ( $id, &$inner ) {
			$inner = ZDZ_Step_Runner::run( $id );   // a second request arriving while this step runs
			return 'again';
		};
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'busy', $inner );
		$this->assertSame( 1, $this->steps_run );
		$this->assertSame( 1, $this->job( $id )['runs'] );
	}

	public function test_a_hold_that_could_not_be_written_is_not_a_hold(): void {
		$id                   = $this->start();
		$this->db->fail_query = 'SET lease_until';
		$this->assertSame( 'busy', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 0, $this->steps_run );
	}

	public function test_the_hold_is_longer_on_the_command_line_than_in_a_web_request(): void {
		ZDZ_Step_Runner::$sapi = 'cli';
		$this->assertSame( ZDZ_Step_Runner::LEASE_CLI_S, ZDZ_Step_Runner::lease_seconds() );
		ZDZ_Step_Runner::$sapi = 'fpm-fcgi';
		$this->assertSame( ZDZ_Step_Runner::LEASE_WEB_S, ZDZ_Step_Runner::lease_seconds() );
		$id         = $this->start();
		$held       = 0;
		$this->step = function ( array $job ) use ( &$held ) {
			$held = ZDZ_Step_Runner::ts( ZDZ_Step_Runner::get( (int) $job['id'] )['lease_until'] );
			return 'done';
		};
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( $this->now + ZDZ_Step_Runner::LEASE_WEB_S, $held );
	}

	public function test_the_job_is_given_back_when_a_step_throws(): void {
		$this->step = function () {
			throw new RuntimeException( 'x' );
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->assertNull( $this->job( $id )['lease_until'] );
		$this->assertSame( '', $this->job( $id )['lease_token'] );
	}

	/* ------------------------------------------------------------------ handing on, and failing */

	public function test_a_step_that_hands_itself_on_is_not_a_failure(): void {
		$this->step = function () {
			throw new ZDZ_Step_Yield( 'short' );
		};
		$id         = $this->start();
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 0, $job['fails'] );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertCount( 2, $this->kicks );
		$this->assertSame( array(), $this->told );
	}

	public function test_a_short_request_hands_the_step_on_a_bounded_number_of_times(): void {
		$id                      = $this->start();
		ZDZ_Step_Runner::$sapi   = 'fpm-fcgi';
		ZDZ_Step_Runner::$origin = function () {
			return $this->now - 60;   // the request is 60 seconds old when the step starts
		};
		$ran        = 0;
		$this->step = function ( array $job ) use ( &$ran ) {
			ZDZ_Step_Runner::yield_if_short( $job, 40 );
			$ran++;
			return 'again';
		};
		for ( $i = 0; $i < ZDZ_Step_Runner::MAX_YIELDS; $i++ ) {
			$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
			$this->assertSame( 0, $ran, 'handed on, not run' );
		}
		$this->assertSame( ZDZ_Step_Runner::MAX_YIELDS, $this->job( $id )['yields'] );
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 1, $ran, 'a step that never fits runs anyway' );
		$this->assertSame( 0, $this->job( $id )['fails'] );
	}

	public function test_the_handing_on_count_starts_again_when_the_job_moves(): void {
		$id                      = $this->start();
		ZDZ_Step_Runner::$sapi   = 'fpm-fcgi';
		ZDZ_Step_Runner::$origin = function () {
			return $this->now - 60;
		};
		$this->step = function ( array $job ) {
			ZDZ_Step_Runner::yield_if_short( $job, 40 );
			return 'again';
		};
		ZDZ_Step_Runner::run( $id );
		ZDZ_Step_Runner::run( $id );
		ZDZ_Step_Runner::set( $id, array( 'step' => 1 ) );
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 1, $this->job( $id )['yields'], 'a new place starts its own count' );
	}

	public function test_a_fresh_request_does_not_hand_on_and_nothing_does_outside_a_step(): void {
		$id   = $this->start();
		$seen = array();
		$this->step = function ( array $job ) use ( &$seen ) {
			ZDZ_Step_Runner::yield_if_short( $job, 40 );
			$seen = array( ZDZ_Step_Runner::elapsed(), ZDZ_Step_Runner::window_left() );
			return 'done';
		};
		$this->assertSame( 'done', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( array( 0, ZDZ_Step_Runner::WINDOW_S ), $seen );
		ZDZ_Step_Runner::yield_if_short( $this->job( $id ), 100000 );   // outside a step: never throws
		$this->assertSame( 0, ZDZ_Step_Runner::elapsed() );
		$this->assertSame( 3600, ZDZ_Step_Runner::window_left() );
	}

	public function test_the_window_is_counted_from_when_the_request_began(): void {
		ZDZ_Step_Runner::$sapi   = 'fpm-fcgi';
		ZDZ_Step_Runner::$origin = function () {
			return $this->now - 20;
		};
		$left       = null;
		$this->step = function () use ( &$left ) {
			$this->now += 5;
			$left = ZDZ_Step_Runner::window_left();
			return 'done';
		};
		ZDZ_Step_Runner::run( $this->start() );
		$this->assertSame( ZDZ_Step_Runner::WINDOW_S - 25, $left );
	}

	public function test_a_site_may_set_the_window_within_limits(): void {
		$this->assertSame( ZDZ_Step_Runner::WINDOW_S, ZDZ_Step_Runner::window() );
		foreach ( array( array( 45, 45 ), array( 5, 30 ), array( 9000, 300 ), array( 'soon', ZDZ_Step_Runner::WINDOW_S ) ) as $case ) {
			$GLOBALS['zdz_test_filters']['zdz_step_window_seconds'] = function () use ( $case ) {
				return $case[0];
			};
			$this->assertSame( $case[1], ZDZ_Step_Runner::window() );
		}
	}

	public function test_a_failed_step_is_tried_again_and_the_app_is_told(): void {
		$this->step = function () {
			throw new RuntimeException( 'the line dropped' );
		};
		$id         = $this->start();
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 1, $this->job( $id )['fails'] );
		$this->assertSame( 'queued', $this->job( $id )['status'] );
		$this->assertSame( array( 'retrying:1' ), $this->told );
		$this->assertCount( 2, $this->kicks );
	}

	public function test_three_failures_in_a_row_end_the_job(): void {
		$this->step = function () {
			throw new RuntimeException( 'the line dropped' );
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 'failed', ZDZ_Step_Runner::run( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'the line dropped', $job['error'] );
		$this->assertSame( array( 'retrying:1', 'retrying:2', 'failed:the line dropped' ), $this->told );
		$this->assertCount( 3, $this->kicks, 'no step is started for a failed job' );
		$this->assertSame( 'ended', ZDZ_Step_Runner::run( $id ) );
	}

	public function test_a_step_that_works_starts_the_failure_count_again(): void {
		$fail       = true;
		$this->step = function () use ( &$fail ) {
			if ( $fail ) {
				throw new RuntimeException( 'x' );
			}
			return 'again';
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 2, $this->job( $id )['fails'] );
		$fail = false;
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 0, $this->job( $id )['fails'] );
		$fail = true;
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ), 'the next failure is the first again' );
	}

	public function test_a_fatal_step_ends_the_job_at_once(): void {
		$this->step = function () {
			throw new ZDZ_Step_Fatal( 'nothing to work with' );
		};
		$id         = $this->start();
		$this->assertSame( 'failed', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'failed', $this->job( $id )['status'] );
		$this->assertSame( array( 'failed:nothing to work with' ), $this->told );
		$this->assertCount( 1, $this->kicks );
	}

	public function test_a_php_error_in_a_step_is_a_failure_not_a_crash(): void {
		$this->step = function () {
			throw new TypeError( 'bad value' );
		};
		$id         = $this->start();
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 1, $this->job( $id )['fails'] );
	}

	public function test_a_handler_that_throws_while_being_told_changes_nothing(): void {
		ZDZ_Step_Runner::register( 'loud', array(
			'step'     => function () {
				throw new RuntimeException( 'x' );
			},
			'retrying' => function () {
				throw new RuntimeException( 'y' );
			},
			'failed'   => function () {
				throw new RuntimeException( 'z' );
			},
		) );
		$id = ZDZ_Step_Runner::start( 'loud', 7 );
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'failed', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'failed', $this->job( $id )['status'] );
	}

	/* ------------------------------------------------------------------ Stop */

	public function test_stop_closes_a_waiting_job_at_once(): void {
		$this->step = function () {
			return 'wait';
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( 'closed', ZDZ_Step_Runner::request_stop( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertSame( array( 'stopped:person' ), $this->told );
	}

	public function test_stop_closes_a_job_no_worker_holds_at_once(): void {
		$id = $this->start();
		$this->assertSame( 'closed', ZDZ_Step_Runner::request_stop( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		// the start that was already on its way finds an ended job
		$this->assertTrue( ZDZ_Step_Runner::consume_kick( $id, $this->kicks[0][1] ) );
		$this->assertSame( 'ended', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 0, $this->steps_run );
	}

	public function test_stop_during_a_step_is_read_when_the_step_ends(): void {
		$id         = $this->start();
		$asked      = null;
		$this->step = function () use ( $id, &$asked ) {
			$asked = ZDZ_Step_Runner::request_stop( $id );   // pressed while this step is running
			return 'again';
		};
		$this->assertSame( 'stopped', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'asked', $asked );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertSame( array( 'stopped:person' ), $this->told );
		$this->assertCount( 1, $this->kicks, 'no further step is started' );
	}

	public function test_a_long_step_can_ask_whether_stop_was_pressed(): void {
		$id         = $this->start();
		$seen       = array();
		$this->step = function () use ( $id, &$seen ) {
			$seen[] = ZDZ_Step_Runner::stop_asked( $id );
			ZDZ_Step_Runner::request_stop( $id );
			$seen[] = ZDZ_Step_Runner::stop_asked( $id );
			return 'again';
		};
		ZDZ_Step_Runner::run( $id );
		$this->assertSame( array( false, true ), $seen );
		$this->assertTrue( ZDZ_Step_Runner::stop_asked( $id ), 'an ended job' );
		$this->assertTrue( ZDZ_Step_Runner::stop_asked( 999 ), 'no such job' );
	}

	public function test_stop_before_a_step_starts_is_read_by_that_step(): void {
		$id = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id, array( 'stop_asked' => 1 ) );
		$this->assertSame( 'stopped', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 0, $this->steps_run );
		$this->assertSame( array( 'stopped:person' ), $this->told );
	}

	public function test_stop_closes_a_held_job_that_has_gone_quiet(): void {
		$id = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id, array( 'status' => 'running', 'lease_until' => gmdate( 'Y-m-d H:i:s', $this->now + 100 ), 'lease_token' => 'w' ) );
		$this->assertSame( 'asked', ZDZ_Step_Runner::request_stop( $id ), 'a worker that was heard from just now is left to end its step' );
		$this->assertSame( 'running', $this->job( $id )['status'] );
		$this->now += ZDZ_Step_Runner::QUIET_S + 1;
		$this->assertSame( 'closed', ZDZ_Step_Runner::request_stop( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
	}

	public function test_a_job_that_finished_stays_finished_when_stop_arrives_late(): void {
		$id         = $this->start();
		$this->step = function () use ( $id ) {
			ZDZ_Step_Runner::request_stop( $id );
			return 'done';
		};
		$this->assertSame( 'done', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'done', $this->job( $id )['status'] );
		$this->assertSame( 'ended', ZDZ_Step_Runner::request_stop( $id ) );
		$this->assertSame( array(), $this->told );
	}

	public function test_a_step_that_comes_back_after_its_job_ended_changes_nothing(): void {
		$id         = $this->start();
		$this->step = function () use ( $id ) {
			$this->now += ZDZ_Step_Runner::QUIET_S + 1;       // a long, silent step ...
			ZDZ_Step_Runner::request_stop( $id );              // ... closed under it by Stop
			ZDZ_Step_Runner::set( $id, array( 'stage' => 'late' ) );
			return 'again';
		};
		$this->assertSame( 'again', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertCount( 1, $this->kicks, 'nothing is started for an ended job' );
		$this->assertSame( array( 'stopped:person' ), $this->told );
	}

	public function test_stop_on_an_ended_or_missing_job_says_so(): void {
		$this->assertSame( 'missing', ZDZ_Step_Runner::request_stop( 55 ) );
		$id = $this->start();
		ZDZ_Step_Runner::request_stop( $id );
		$this->assertSame( 'ended', ZDZ_Step_Runner::request_stop( $id ) );
		$this->assertSame( array( 'stopped:person' ), $this->told, 'told once' );
	}

	/* ------------------------------------------------------------------ the person answers */

	public function test_an_answer_sets_a_waiting_job_going_again(): void {
		$this->step = function () {
			return 'wait';
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->armed = 0;
		$this->assertTrue( ZDZ_Step_Runner::resume( $id, array( 'stage' => 'work', 'state' => array( 'ok' => 1 ) ) ) );
		$job = $this->job( $id );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 'work', $job['stage'] );
		$this->assertSame( array( 'ok' => 1 ), $job['state'] );
		$this->assertNull( $job['wait_until'] );
		$this->assertCount( 2, $this->kicks );
		$this->assertSame( 1, $this->armed );
	}

	public function test_only_one_of_two_answers_counts(): void {
		$this->step = function () {
			return 'wait';
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->assertTrue( ZDZ_Step_Runner::resume( $id ) );
		$this->assertFalse( ZDZ_Step_Runner::resume( $id, array( 'stage' => 'second' ) ) );
		$this->assertSame( '', $this->job( $id )['stage'] );
		$this->assertCount( 2, $this->kicks );
	}

	public function test_an_answer_to_a_job_that_is_not_waiting_does_nothing(): void {
		$id = $this->start();
		$this->assertFalse( ZDZ_Step_Runner::resume( $id ) );
		ZDZ_Step_Runner::request_stop( $id );
		$this->assertFalse( ZDZ_Step_Runner::resume( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertFalse( ZDZ_Step_Runner::resume( 404 ) );
	}

	/* ------------------------------------------------------------------ the watchdog */

	public function test_a_quiet_job_nobody_holds_is_started_again(): void {
		$id = $this->start();
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ), 'heard from just now' );
		$this->now += ZDZ_Step_Runner::QUIET_S;
		$this->assertTrue( ZDZ_Step_Runner::poke( $id ) );
		$this->assertCount( 2, $this->kicks );
		$this->assertSame( 1, $this->job( $id )['revivals'] );
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ), 'at most one start a minute' );
		$this->now += ZDZ_Step_Runner::KICK_EVERY_S;
		$this->assertTrue( ZDZ_Step_Runner::poke( $id ) );
		$this->assertSame( 2, $this->job( $id )['revivals'] );
	}

	public function test_a_job_a_worker_holds_is_not_started_again(): void {
		$id = $this->start();
		$this->now += 200;
		$this->db->poke( 'zdz_step_jobs', $id, array( 'status' => 'running', 'lease_until' => gmdate( 'Y-m-d H:i:s', $this->now + 30 ) ) );
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$this->assertCount( 1, $this->kicks );
		$this->now += 31;   // the request died and its hold ran out
		$this->assertTrue( ZDZ_Step_Runner::poke( $id ) );
	}

	public function test_starts_that_get_nowhere_are_counted_and_end_the_job(): void {
		$id = $this->start();
		for ( $i = 1; $i <= ZDZ_Step_Runner::MAX_REVIVALS; $i++ ) {
			$this->now += 200;
			$this->assertTrue( ZDZ_Step_Runner::poke( $id ), 'start ' . $i );
		}
		$this->now += 200;
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'abandoned: revivals', $job['error'] );
		$this->assertSame( array( 'abandoned:revivals' ), $this->told );
		$this->assertCount( 1 + ZDZ_Step_Runner::MAX_REVIVALS, $this->kicks );
	}

	public function test_progress_starts_the_count_of_empty_starts_again(): void {
		$id = $this->start();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->now += 200;
			ZDZ_Step_Runner::poke( $id );
		}
		$this->assertSame( 3, $this->job( $id )['revivals'] );
		ZDZ_Step_Runner::set( $id, array( 'progress' => 'page2' ) );   // work was done inside the step
		$this->now += 200;
		ZDZ_Step_Runner::poke( $id );
		$this->assertSame( 1, $this->job( $id )['revivals'] );
	}

	public function test_a_job_far_past_its_deadline_is_abandoned(): void {
		$id = $this->start( array( 'deadline' => $this->now + 100 ) );
		$this->now += 100 + ZDZ_Step_Runner::BACKSTOP_S;
		ZDZ_Step_Runner::touch( $id );
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$this->assertSame( 'queued', $this->job( $id )['status'], 'exactly at the limit is still inside it' );
		$this->now += 1;
		ZDZ_Step_Runner::poke( $id );
		$this->assertSame( 'failed', $this->job( $id )['status'] );
		$this->assertSame( array( 'abandoned:deadline' ), $this->told );
	}

	public function test_a_step_is_not_run_for_a_job_far_past_its_deadline_or_past_its_run_limit(): void {
		$id = $this->start( array( 'deadline' => $this->now + 100 ) );
		$this->now += 101 + ZDZ_Step_Runner::BACKSTOP_S;
		$this->assertSame( 'abandoned', ZDZ_Step_Runner::run( $id ) );
		$this->assertSame( 0, $this->steps_run );

		$id2 = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id2, array( 'runs' => ZDZ_Step_Runner::MAX_RUNS ) );
		$this->assertSame( 'abandoned', ZDZ_Step_Runner::run( $id2 ) );
		$this->assertSame( 0, $this->steps_run );
		$this->assertSame( array( 'abandoned:deadline', 'abandoned:runs' ), $this->told );
	}

	public function test_a_wait_nobody_answered_is_closed_when_it_runs_out(): void {
		$this->step = function () {
			return array( 'next' => 'wait', 'wait_s' => 300 );
		};
		$id         = $this->start();
		ZDZ_Step_Runner::run( $id );
		$this->now += 300;
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$this->assertSame( 'waiting', $this->job( $id )['status'] );
		$this->now += 1;
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertSame( array( 'stopped:expired' ), $this->told );
		$this->assertCount( 1, $this->kicks, 'a waiting job is never started by the watchdog' );
	}

	public function test_the_watchdog_leaves_ended_and_missing_jobs_alone(): void {
		$this->assertFalse( ZDZ_Step_Runner::poke( 12 ) );
		$id = $this->start();
		ZDZ_Step_Runner::request_stop( $id );
		$this->now += 5000;
		$this->assertFalse( ZDZ_Step_Runner::poke( $id ) );
		$this->assertSame( 'stopped', $this->job( $id )['status'] );
		$this->assertCount( 1, $this->kicks );
	}

	public function test_the_sweep_tends_every_live_job_and_comes_back_while_one_is_left(): void {
		$a = $this->start();
		$b = $this->start( array( 'deadline' => $this->now + 10 ) );
		$c = $this->start();
		ZDZ_Step_Runner::request_stop( $c );
		$this->armed = 0;
		$this->kicks = array();
		$this->now  += 200;
		ZDZ_Step_Runner::sweep();
		$this->assertSame( array( $a, $b ), array_column( $this->kicks, 0 ) );
		$this->assertSame( 1, $this->armed );

		$this->now  += 20 + ZDZ_Step_Runner::BACKSTOP_S;
		ZDZ_Step_Runner::request_stop( $a );
		$this->armed = 0;
		ZDZ_Step_Runner::sweep();   // b is past its backstop, a and c have ended
		$this->assertSame( 'failed', $this->job( $b )['status'] );
		$this->assertSame( 0, $this->armed, 'nothing live: the sweep does not come back' );
	}

	public function test_the_sweep_does_nothing_before_the_table_exists(): void {
		ZDZ_Step_Runner::sweep();
		ZDZ_Step_Runner::prune();
		$this->assertSame( array(), $this->db->log );
		$this->assertSame( 0, $this->armed );
	}

	public function test_old_ended_jobs_are_removed_and_live_ones_kept(): void {
		$old  = $this->start();
		$live = $this->start();
		$new  = $this->start();
		ZDZ_Step_Runner::request_stop( $old );
		$this->now += ZDZ_Step_Runner::KEEP_DAYS * 86400 + 5;
		ZDZ_Step_Runner::request_stop( $new );
		ZDZ_Step_Runner::prune();
		$this->assertNull( ZDZ_Step_Runner::get( $old ) );
		$this->assertNotNull( ZDZ_Step_Runner::get( $live ), 'a live job is never removed, however old' );
		$this->assertNotNull( ZDZ_Step_Runner::get( $new ) );
	}

	/* ------------------------------------------------------------------ small parts */

	public function test_stored_times_read_back_as_utc_seconds(): void {
		$this->assertSame( 0, ZDZ_Step_Runner::ts( null ) );
		$this->assertSame( 0, ZDZ_Step_Runner::ts( '' ) );
		$this->assertSame( 0, ZDZ_Step_Runner::ts( '0000-00-00 00:00:00' ) );
		$this->assertSame( 0, ZDZ_Step_Runner::ts( 'soon' ) );
		$this->assertSame( 1700000000, ZDZ_Step_Runner::ts( '2023-11-14 22:13:20' ) );
	}

	public function test_a_job_with_unreadable_state_reads_as_empty_state(): void {
		$id = $this->start();
		$this->db->poke( 'zdz_step_jobs', $id, array( 'state' => '{not json' ) );
		$this->assertSame( array(), $this->job( $id )['state'] );
		$this->assertNull( ZDZ_Step_Runner::get( 0 ) );
		$this->assertNull( ZDZ_Step_Runner::get( -4 ) );
	}
}
