<?php
/**
 * Moving stored values onto the app's key (ZDZ_Keys_Migrate), and a deliberate key change.
 *
 * A component registers a job (the filter zdz_keys_migrations); the runner moves its values in batches. What these
 * tests pin, each in processes of their own (support/app-key-site.php), with the database carried from one press to
 * the next:
 *
 *   - a job that is not well formed is dropped; a second run at the same time is refused; nothing runs without a
 *     ready key;
 *   - values kept the way stored credentials are kept today (under the WordPress salts) move in batches: a run that
 *     stops part-way resumes where it stopped, a run after the end changes nothing, a value kept under other salts is
 *     listed by row and left exactly as it was, and nothing is ever stored in the clear on the way;
 *   - every write is read back: a store that keeps half a value, or the wrong bytes, is found out and the old bytes
 *     are put back; a value that cannot be put back holds the job open and is said loudly;
 *   - a list that grows between presses is not called finished; hundreds of unreadable values do not keep a job open;
 *   - a key change: both keys read, new values go under the new key, the new key is recorded only once nothing is left
 *     under the old one (a job's rows and the options table alike), and then the old key's line can go;
 *   - a key id made of digits only is still a key id (PHP turns such an array key into a number).
 *
 * Made-up keys only, drawn on every run. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/app-key-harness.php';
require_once zdzk_test_inc() . '/class-zdz-keys.php';
require_once zdzk_test_inc() . '/class-zdz-keys-migrate.php';

final class AppKeyMigrateTest extends TestCase {

	use ZDZK_Site_Harness;

	protected function setUp(): void {
		$this->zdzk_begin();
		ZDZ_Keys::reset();
	}

	protected function tearDown(): void {
		$this->zdzk_end();
		unset( $GLOBALS['zdz_test_filters']['zdz_keys_migrations'] );
		ZDZ_Keys::reset();
	}

	/** A well-formed job over nothing. */
	private static function job( array $over = array() ): array {
		$none = function () {
			return array();
		};
		return $over + array( 'purpose' => 'credentials', 'items' => $none, 'stored' => $none, 'legacy' => $none, 'write' => $none );
	}

	/* ── what needs no key ────────────────────────────────────────────────── */

	public function test_only_a_well_formed_job_is_registered(): void {
		$GLOBALS['zdz_test_filters']['zdz_keys_migrations'] = function ( $jobs ) {
			$jobs['credentials']   = self::job( array( 'label' => 'Stored provider keys' ) );
			$jobs['inbox_tokens-2'] = self::job( array( 'purpose' => 'inbox-tokens', 'kids' => function () {
				return array();
			} ) );
			$jobs['Capitals']      = self::job();
			$jobs['has space']     = self::job();
			$jobs['unknown']       = self::job( array( 'purpose' => 'payroll' ) );
			$jobs['numeric']       = self::job( array( 'purpose' => 1 ) );
			$jobs['no-legacy']     = array_diff_key( self::job(), array( 'legacy' => 1 ) );
			$jobs['bad-items']     = self::job( array( 'items' => 'zdzk_no_such_function' ) );
			$jobs['bad-kids']      = self::job( array( 'kids' => array( 'not', 'callable' ) ) );
			return $jobs;
		};
		$jobs = ZDZ_Keys_Migrate::jobs();
		$this->assertSame( array( 'credentials', 'inbox_tokens-2' ), array_keys( $jobs ) );
		$this->assertSame( 'Stored provider keys', $jobs['credentials']['label'] );
		$this->assertSame( 'inbox_tokens-2', $jobs['inbox_tokens-2']['label'], 'a job without a label is named by its id' );
	}

	public function test_core_registers_no_job_of_its_own(): void {
		$this->assertSame( array(), ZDZ_Keys_Migrate::jobs(), 'nothing is moved until a component registers what it keeps' );
		$fresh = ZDZ_Keys_Migrate::status( 'never-ran' );
		$this->assertSame( -1, $fresh['cursor'] );
		$this->assertSame( array( 0, 0, 0 ), array( $fresh['done'], $fresh['skipped'], $fresh['unreadable_n'] ) );
		$this->assertFalse( $fresh['finished'] );
		$this->assertSame( '', $fresh['at'] );
	}

	public function test_the_key_ids_a_job_holds_are_read_by_its_reader_or_item_by_item(): void {
		$rows = array( 'a' => 'zdzk1:0123abcd:AAAA', 'b' => 'zdzk1:12345678:AAAA', 'c' => 'fixed-iv-bytes', 'd' => '', 'e' => null, 'f' => 'zdzk1:0123abcd:BBBB' );
		$job  = self::job( array(
			'items'  => function () use ( $rows ) {
				return array_keys( $rows );
			},
			'stored' => function ( $item ) use ( $rows ) {
				return $rows[ $item ];
			},
		) );
		$kids = ZDZ_Keys_Migrate::kids_in( $job, 10 );
		sort( $kids );
		$this->assertSame( array( '0123abcd', '12345678' ), $kids, 'each key id once, as text: one made of digits only is not turned into a number' );
		$this->assertNull( ZDZ_Keys_Migrate::kids_in( $job, 5 ), 'more items than may be looked through, and no reader: it cannot tell' );
		$with = $job + array( 'kids' => function () {
			return array( '89abcdef', '89abcdef', 12345678 );
		} );
		$this->assertSame( array( '89abcdef', '12345678' ), ZDZ_Keys_Migrate::kids_in( $with, 5 ), 'with a reader, the reader answers, whatever the size' );
	}

	public function test_nothing_runs_without_a_ready_key(): void {
		if ( defined( 'ZDZ_APP_KEY' ) ) {
			$this->markTestSkipped( 'this process has a key line of its own' );
		}
		$wrote = false;
		$GLOBALS['zdz_test_filters']['zdz_keys_migrations'] = function ( $jobs ) use ( &$wrote ) {
			$jobs['example'] = self::job( array(
				'items'  => function () {
					return array( 'row1' );
				},
				'stored' => function () {
					return 'legacy-bytes';
				},
				'legacy' => function () {
					return 'value-EXAMPLE-0001';
				},
				'write'  => function () use ( &$wrote ) {
					$wrote = true;
					return true;
				},
			) );
			return $jobs;
		};
		$before = $GLOBALS['zdz_test_options'] ?? null;
		$this->assertSame( array( 'error' => 'missing' ), ZDZ_Keys_Migrate::run( 'example', 200, 1 ) );
		$this->assertSame( array( 'example' => array( 'error' => 'missing' ) ), ZDZ_Keys_Migrate::run_all( 200, 1 ) );
		$this->assertFalse( ZDZ_Keys_Migrate::all_done() );
		$this->assertSame( 0, ZDZ_Keys_Migrate::pending( ZDZ_Keys_Migrate::jobs()['example'], array( 'row1' ) ), 'a value whose write already failed this pass is not counted again' );
		$this->assertFalse( $wrote, 'nothing was written' );
		$this->assertSame( $before, $GLOBALS['zdz_test_options'] ?? null, 'and no count was kept' );
	}

	/* ── the registry and the lock ────────────────────────────────────────── */

	public function test_a_malformed_job_is_dropped_and_a_second_run_at_once_is_refused(): void {
		$this->assert_held( $this->moment( 'jobs_registry', array( 'key' => $this->key() ) ) );
	}

	/* ── moving values kept under the salts ───────────────────────────────── */

	public function test_values_kept_under_the_salts_move_in_batches_resume_and_are_never_wiped(): void {
		$k = $this->key();

		// the first press stops after three of seven
		$one = $this->moment( 'move_start', array( 'key' => $k ) );
		$this->assert_held( $one );
		$before = $one['data']['before'];
		$this->assertSame( array( 2, 3, 0, 0, false ), array( $one['data']['run']['cursor'], $one['data']['run']['done'], $one['data']['run']['skipped'], $one['data']['run']['unreadable_n'], $one['data']['run']['finished'] ), 'interrupted after three' );
		$this->assertSame( array( $before['row4'], $before['row7'] ), array( $one['data']['rows']['row4'], $one['data']['rows']['row7'] ), 'what it has not reached is as it was' );
		$this->assertFalse( $one['data']['all_done'] );
		$this->assert_nothing_in_the_clear();

		// the second resumes where the first stopped
		$two = $this->moment( 'move_press', array( 'key' => $k ) );
		$this->assertSame( array( 5, 6, 0, false ), array( $two['data']['run']['cursor'], $two['data']['run']['done'], $two['data']['run']['skipped'], $two['data']['run']['finished'] ), 'resumed: six done' );
		for ( $n = 1; $n <= 6; $n++ ) {
			$this->assertSame( 'provider-key-EXAMPLE-000' . $n, $two['data']['opens'][ 'row' . $n ], 'row' . $n . ' opens under the app\'s key' );
			$this->assertSame( 'provider-key-EXAMPLE-000' . $n, zdzk_open_under( $k, ZDZ_Keys::PURPOSES['credentials'], 'credentials', $two['data']['rows'][ 'row' . $n ], 'row' . $n ), 'and is bound to its own row' );
		}
		$this->assert_nothing_in_the_clear();

		// the third reaches the value kept under other salts: listed by row, left exactly as it was, and the job ends
		$three = $this->moment( 'move_press', array( 'key' => $k ) );
		$this->assertSame( array( 6, 1, array( 'row7' ), array(), true ), array( $three['data']['run']['done'], $three['data']['run']['unreadable_n'], $three['data']['run']['unreadable'], $three['data']['run']['damaged'], $three['data']['run']['finished'] ) );
		$this->assertSame( $before['row7'], $three['data']['rows']['row7'], 'never wiped, never rewritten' );
		$this->assertTrue( $three['data']['all_done'], 'a value that depends on the salts does not hold the move open' );
		$this->assertSame( 1, $three['data']['run']['by'], 'the count names who pressed' );

		// pressed again after the end: it counts again and changes nothing
		$kept = $this->stored()['tables'];
		$four = $this->moment( 'move_press', array( 'key' => $k, 'arg' => '200' ) );
		$this->assertSame( array( 0, 6, 1, true ), array( $four['data']['run']['done'], $four['data']['run']['skipped'], $four['data']['run']['unreadable_n'], $four['data']['run']['finished'] ), 'run again: nothing done, six skipped' );
		$this->assertSame( $kept, $this->stored()['tables'], 'not one stored byte changed' );
		$this->assert_nothing_in_the_clear();
	}

	private function assert_nothing_in_the_clear(): void {
		$kept = (string) file_get_contents( $this->store_file() );
		$this->assertStringNotContainsString( 'provider-key-EXAMPLE', $kept, 'no value is stored in the clear at any point' );
		$this->assertStringNotContainsString( 'zdzk-unit-salt', $kept, 'and no salt' );
	}

	public function test_every_write_is_read_back_and_a_bad_one_is_undone(): void {
		$this->assert_held( $this->moment( 'move_read_back', array( 'key' => $this->key() ) ) );
		$rows = $this->stored()['tables']['odd'];
		$this->assertSame( array( 'legacy:value-EXAMPLE-0001', 'legacy:value-EXAMPLE-0002' ), array( $rows['h1'], $rows['h2'] ) );
	}

	public function test_a_value_that_cannot_be_put_back_holds_the_job_open(): void {
		$this->assert_held( $this->moment( 'value_that_cannot_be_put_back', array( 'key' => $this->key() ) ) );
	}

	public function test_a_list_that_grows_between_presses_is_not_called_finished(): void {
		$this->assert_held( $this->moment( 'list_grows_between_presses', array( 'key' => $this->key() ) ) );
	}

	public function test_a_value_met_twice_because_the_list_shifted_is_counted_once(): void {
		$this->assert_held( $this->moment( 'list_shifts_under_the_cursor', array( 'key' => $this->key() ) ) );
	}

	public function test_the_lock_is_given_back_even_when_a_run_fails_part_way(): void {
		$this->assert_held( $this->moment( 'lock_is_given_back', array( 'key' => $this->key() ) ) );
	}

	public function test_a_job_that_asks_about_the_key_during_the_first_scan_does_not_loop(): void {
		$this->assert_held( $this->moment( 'job_callback_asks_during_first_scan', array( 'key' => $this->key() ) ) );
	}

	public function test_hundreds_of_unreadable_values_do_not_keep_a_job_open(): void {
		$this->assert_held( $this->moment( 'many_unreadable_values', array( 'key' => $this->key() ) ) );
	}

	/* ── a deliberate key change ──────────────────────────────────────────── */

	public function test_a_key_change_is_recorded_only_when_nothing_is_left_under_the_old_key(): void {
		$new = $this->key();
		$old = $this->key();
		$this->store( array( 'zdz_keys_state' => self::record( $old ), 'zdz_keys_ever' => 1 ) );
		$this->assert_held( $this->moment( 'key_change_start', array( 'key' => $new, 'prev' => $old ) ) );
		$state = $this->stored()['options']['zdz_keys_state'];
		$this->assertSame( array( zdzk_kid( $new ), zdzk_kid( $old ), 'rotated' ), array( $state['kid'], $state['prev_kid'], $state['how'] ) );
		foreach ( $this->stored()['tables']['change'] as $item => $value ) {
			$this->assertSame( zdzk_kid( $new ), substr( $value, 6, 8 ), $item . ' is under the new key' );
			$this->assertFalse( zdzk_open_under( $old, ZDZ_Keys::PURPOSES['credentials'], 'credentials', $value, $item ), 'and the old key no longer opens it' );
		}
		// the next request, the old key's line taken out
		$this->assert_held( $this->moment( 'key_change_done', array( 'key' => $new ) ) );
	}

	public function test_a_value_in_the_options_table_holds_a_key_change_open_too(): void {
		$new = $this->key();
		$old = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $old ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $old, ZDZ_Keys::PURPOSES['credentials'], 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		) );
		$this->assert_held( $this->moment( 'key_change_held_open_by_an_option', array( 'key' => $new, 'prev' => $old ) ) );
		$this->assertSame( zdzk_kid( $old ), $this->stored()['options']['zdz_keys_state']['kid'], 'the old key is still the recorded one' );
	}

	public function test_a_key_id_made_of_digits_only_is_still_a_key_id(): void {
		$new = $this->key( true );
		$old = $this->key( true );
		$this->assert_held( $this->moment( 'digits_only_key_id', array( 'key' => $new, 'prev' => $old ) ) );
	}
}
