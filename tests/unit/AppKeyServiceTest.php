<?php
/**
 * The app's own key service (ZDZ_Keys): one master key kept apart from the WordPress salts, a subkey per purpose,
 * sealed values that name their key, and a service that fails closed and never heals itself.
 *
 * The key comes from constants in wp-config.php, so each state of a site is looked at in a process of its own (see
 * support/app-key-site.php): the tests below name a moment, hand it its key lines, its salts and its database, and
 * read what it saw. What they pin:
 *
 *   - nothing set up: nothing is read, written, shown or sealed (the service is there, and does nothing);
 *   - first use records the key id, and only the key id; the next request writes nothing;
 *   - the stored format, checked against its description by an independent reader and writer; a value moved to
 *     another row or purpose, cut, changed or relabelled never opens, and never reads as an empty string;
 *   - a missing key, a line that is not a key, the wrong key, another site's key, a copied database: locked, with
 *     nothing written, and still locked on the next request; the right key back opens everything again;
 *   - a lost record is never re-made by the software: an administrator types the printed key, and a key the stored
 *     values contradict is never recorded by anyone;
 *   - a previous key reads old values while a key change is under way, and is not enough once its line is gone;
 *   - a change to the WordPress salts leaves everything sealed under the app's key readable;
 *   - no key, in any spelling, in anything a moment prints, stores or logs (checked after every moment).
 *
 * Made-up keys only, drawn on every run. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/app-key-harness.php';
require_once zdzk_test_inc() . '/class-zdz-keys.php';
require_once zdzk_test_inc() . '/class-zdz-keys-migrate.php';
require_once zdzk_test_inc() . '/class-zdz-keys-admin.php';

final class AppKeyServiceTest extends TestCase {

	use ZDZK_Site_Harness;

	protected function setUp(): void {
		$this->zdzk_begin();
		ZDZ_Keys::reset();
	}

	protected function tearDown(): void {
		$this->zdzk_end();
		unset( $GLOBALS['zdz_test_filters']['zdz_export_non_portable_option_patterns'] );
		ZDZ_Keys::reset();
	}

	private static function cred(): int {
		return ZDZ_Keys::PURPOSES['credentials'];
	}

	/* ── what needs no key ────────────────────────────────────────────────── */

	public function test_a_printed_key_is_read_the_way_a_person_copies_one(): void {
		$raw     = random_bytes( 32 );
		$printed = zdzk_printed( $raw );
		$this->assertSame( 1, preg_match( '/^[A-Z2-7]{4}( [A-Z2-7]{4}){12}$/D', $printed ), '13 groups of four' );
		$this->assertSame( $raw, ZDZ_Keys::parse( $printed ) );
		$this->assertSame( $raw, ZDZ_Keys::parse( strtolower( $printed ) ), 'case is ignored' );
		$this->assertSame( $raw, ZDZ_Keys::parse( str_replace( ' ', '-', $printed ) ), 'dashes are ignored' );
		$this->assertSame( $raw, ZDZ_Keys::parse( "  " . str_replace( ' ', "\n", $printed ) . "\t" ), 'so is any spacing' );
		$this->assertSame( $raw, ZDZ_Keys::parse( strtr( $printed, array( 'O' => '0', 'I' => '1', 'B' => '8' ) ) ), '0, 1 and 8 are read as O, I and B' );
		$bare = str_replace( ' ', '', $printed );
		foreach ( array( substr( $bare, 0, 51 ), $bare . 'A', '', 'not a key', substr( $bare, 0, 51 ) . '9', substr( $bare, 0, 51 ) . '_', null, 12, array( $printed ), true ) as $bad ) {
			$this->assertNull( ZDZ_Keys::parse( $bad ), 'not a printed key: ' . json_encode( is_string( $bad ) ? strlen( $bad ) : $bad ) );
		}
	}

	public function test_a_key_id_is_eight_hex_that_say_which_key_and_nothing_about_it(): void {
		$printed = zdzk_printed( random_bytes( 32 ) );
		$kid     = ZDZ_Keys::code_kid( $printed );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{8}$/D', $kid ) );
		$this->assertSame( zdzk_kid( $printed ), $kid, 'the documented rule: first 8 hex of HMAC-SHA256( "zdzk1 key id", key )' );
		$this->assertSame( $kid, ZDZ_Keys::code_kid( strtolower( str_replace( ' ', '', $printed ) ) ), 'however the key is typed' );
		$this->assertNotSame( $kid, ZDZ_Keys::code_kid( zdzk_printed( random_bytes( 32 ) ) ) );
		$this->assertSame( '', ZDZ_Keys::code_kid( 'ABCD EFGH' ) );
		$this->assertSame( '', ZDZ_Keys::code_kid( array( $printed ) ), 'only text is read as a key' );
		$this->assertSame( '', ZDZ_Keys::code_kid( null ) );
	}

	public function test_hosts_are_compared_bare(): void {
		foreach ( array( 'app.example.com', 'APP.Example.com', 'https://app.example.com', 'http://www.app.example.com/', 'https://WWW.App.Example.com:8443/wp/?x=1#top', '  app.example.com  ', 'app.example.com:443' ) as $spelling ) {
			$this->assertSame( 'app.example.com', ZDZ_Keys::norm_host( $spelling ), $spelling );
		}
		$this->assertSame( 'staging.example.com', ZDZ_Keys::norm_host( 'https://staging.example.com' ) );
		$this->assertNotSame( ZDZ_Keys::norm_host( 'example.com' ), ZDZ_Keys::norm_host( 'app.example.com' ), 'a subdomain is another site' );
		$this->assertSame( '', ZDZ_Keys::norm_host( '' ) );
	}

	public function test_the_purposes_keep_their_ids_for_ever(): void {
		// An id is part of every value sealed for its purpose: renumbering one would lock what is already stored.
		$this->assertSame(
			array(
				'credentials'      => 1,
				'inbox-tokens'     => 2,
				'inbox-content'    => 3,
				'scheduler-tokens' => 4,
				'vault-kek'        => 5,
				'agreements-wrap'  => 6,
				'sealed-codes'     => 7,
				'rp-codes'         => 8,
				'rp-audio'         => 9,
				'hours-private'    => 10,
				'sign'             => 11,
				'links'            => 12,
				'privacy-view'     => 13,
				'private'          => 14,
			),
			ZDZ_Keys::purposes()
		);
		$this->assertSame( 'zdzk1', ZDZ_Keys::FORMAT );
		$this->assertSame( 8, strlen( ZDZ_Keys::CTX ), 'the KDF context is exactly 8 bytes' );
		$this->assertSame( 'zdzkeys1', ZDZ_Keys::CTX );
	}

	public function test_only_a_whole_value_in_this_format_is_a_sealed_value(): void {
		$v = 'zdzk1:0123abcd:' . str_repeat( 'Aa0_-', 12 );
		$this->assertTrue( ZDZ_Keys::is_zdzk1( $v ) );
		$this->assertSame( '0123abcd', ZDZ_Keys::header_key_id( $v ) );
		foreach ( array( $v . "\n", ' ' . $v, 'zdzk1:0123ABCD:AAAA', 'zdzk1:0123abc:AAAA', 'zdzk1:0123abcd:', 'zdzk1:0123abcd:AA=A', 'zdzk2:0123abcd:AAAA', 'v1:AAAA', '', null, 7, array( $v ) ) as $not ) {
			$this->assertFalse( ZDZ_Keys::is_zdzk1( $not ), json_encode( $not ) );
			$this->assertSame( '', ZDZ_Keys::header_key_id( $not ) );
		}
	}

	public function test_with_no_key_line_this_very_process_is_refused_everything(): void {
		if ( defined( 'ZDZ_APP_KEY' ) ) {
			$this->markTestSkipped( 'this process has a key line of its own' );
		}
		$before = $GLOBALS['zdz_test_options'] ?? null;
		$this->assertSame( 'missing', ZDZ_Keys::state() );
		$this->assertFalse( ZDZ_Keys::ready() );
		$this->assertFalse( ZDZ_Keys::rotating() );
		$this->assertSame( '', ZDZ_Keys::key_id() );
		$this->assertNull( ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0001', 'row' ), 'never the plaintext back' );
		$this->assertNull( ZDZ_Keys::mac( 'sign', 'message' ) );
		$this->assertFalse( ZDZ_Keys::verify_mac( 'sign', 'message', str_repeat( 'a', 64 ) ) );
		$this->assertNull( ZDZ_Keys::open( 'credentials', 'zdzk1:0123abcd:' . str_repeat( 'A', 60 ), 'row' ), 'null, never an empty string' );
		$this->assertSame( '', ZDZ_Keys::seal( 'credentials', '', 'row' ), 'nothing in, nothing out' );
		$this->assertSame( '', ZDZ_Keys::open( 'credentials', '', 'row' ) );
		$this->assertNull( ZDZ_Keys::recordable() );
		$this->assertSame( array( 'error' => 'no_such_job' ), ZDZ_Keys_Migrate::run( 'anything' ) );
		$this->assertSame( array(), ZDZ_Keys_Migrate::run_all() );
		$this->assertFalse( ZDZ_Keys_Migrate::all_done() );
		$this->assertSame( $before, $GLOBALS['zdz_test_options'] ?? null, 'and nothing was written' );
	}

	public function test_the_keys_own_bookkeeping_never_travels_in_a_data_export(): void {
		$patterns = ZDZ_Keys::export_non_portable( array( '/^example_/' ) );
		$this->assertContains( '/^example_/', $patterns, 'what others declared is kept' );
		$this->assertSame( array( '/^zdz_keys_(state|ever)$/', '/^zdz_keys_migrate_/' ), ZDZ_Keys::export_non_portable( 'not a list' ) );
		$GLOBALS['zdz_test_filters']['zdz_export_non_portable_option_patterns'] = array( 'ZDZ_Keys', 'export_non_portable' );
		foreach ( array( ZDZ_Keys::STATE_OPT, ZDZ_Keys::EVER_OPT, ZDZ_Keys_Migrate::OPT . 'credentials', ZDZ_Keys_Migrate::OPT . 'inbox-tokens' ) as $name ) {
			$this->assertTrue( ZDZ_Data_Portability::is_non_portable_option( $name ), $name . ' belongs to one install' );
		}
		foreach ( array( 'zdz_keys_statement', 'zdz_keys', 'zdz_business_profile' ) as $name ) {
			$this->assertFalse( ZDZ_Data_Portability::is_non_portable_option( $name ), $name . ' is not the key\'s' );
		}
	}

	/* ── nothing set up ───────────────────────────────────────────────────── */

	public function test_until_the_key_is_set_up_the_service_does_nothing_at_all(): void {
		$seen = $this->moment( 'dark' );
		$this->assert_held( $seen );
		$this->assertSame( 0, $seen['writes'] );
		$this->assertFileDoesNotExistYet( $this->store_file() );
	}

	public function test_a_wp_config_line_without_a_key_file_is_still_not_set_up(): void {
		$this->assert_held( $this->moment( 'file_loaded_but_no_key', array( 'load' => '1' ) ) );
	}

	/* ── first use, and the format ────────────────────────────────────────── */

	public function test_first_use_records_the_key_id_and_nothing_else(): void {
		$k = $this->key();
		$this->assert_held( $this->moment( 'first_use', array( 'key' => $k ) ) );
		$state = $this->stored()['options'];
		$this->assertSame( array( 'zdz_keys_state', 'zdz_keys_ever' ), array_keys( $state ), 'two options, and no more' );
		$this->assertSame( zdzk_kid( $k ), $state['zdz_keys_state']['kid'] );
	}

	public function test_the_site_line_may_be_written_any_way_a_person_writes_an_address(): void {
		$this->assert_held( $this->moment( 'site_line_any_spelling', array( 'key' => $this->key(), 'site' => 'HTTPS://WWW.App.Example.com:443/wp/' ) ) );
	}

	public function test_the_stored_format_is_the_documented_one_and_a_damaged_value_never_opens(): void {
		$k    = $this->key();
		$seen = $this->moment( 'format', array( 'key' => $k ) );
		$this->assert_held( $seen );
		// and from this side: what the service sealed, read here by the description alone
		$this->assertSame( 'provider-key-EXAMPLE-0001', zdzk_open_under( $k, self::cred(), 'credentials', $seen['data']['sealed'], 'zdz_example_row' ) );
		$this->assertFalse( zdzk_open_under( $this->key(), self::cred(), 'credentials', $seen['data']['sealed'], 'zdz_example_row' ), 'and by no other key' );
	}

	/* ── the key is gone, or is not a key ─────────────────────────────────── */

	public function test_a_missing_key_locks_everything_and_changes_nothing(): void {
		$k = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $k ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $k, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		) );
		$before = (string) file_get_contents( $this->store_file() );
		$this->assert_held( $this->moment( 'missing_after_set_up' ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ), 'the database is the same, byte for byte' );
		// the line back: everything reads again
		$this->assert_held( $this->moment( 'right_key_back', array( 'key' => $k ) ) );
	}

	public function test_a_key_line_that_is_not_a_printed_key_is_never_shown(): void {
		$this->assert_held( $this->moment( 'not_a_printed_key', array( 'key' => 'NOT A PRINTED KEY EXAMPLE' ) ) );
	}

	/* ── the wrong key ────────────────────────────────────────────────────── */

	public function test_a_changed_key_locks_and_never_heals_itself(): void {
		$right = $this->key();
		$wrong = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $right ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $right, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		) );
		$before = (string) file_get_contents( $this->store_file() );
		$this->assert_held( $this->moment( 'changed_key', array( 'key' => $wrong, 'k1' => $right ) ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ), 'nothing was written' );
		// the next request, and the one after: still locked, still nothing written
		$this->assert_held( $this->moment( 'changed_key', array( 'key' => $wrong, 'k1' => $right ) ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ), 'the record was not re-made under the new key' );
		// the right line back: the value opens, nothing was damaged
		$this->assert_held( $this->moment( 'right_key_back', array( 'key' => $right ) ) );
	}

	public function test_while_locked_the_paper_key_is_recognised_as_the_recorded_one(): void {
		$right = $this->key();
		$this->store( array( 'zdz_keys_state' => self::record( $right ), 'zdz_keys_ever' => 1 ) );
		$this->assert_held( $this->moment( 'check_recorded_key_when_locked', array( 'key' => $this->key(), 'k1' => $right ) ) );
	}

	/* ── a lost record ────────────────────────────────────────────────────── */

	public function test_a_lost_record_is_recorded_again_only_by_an_administrator_typing_the_key(): void {
		$k = $this->key();
		$this->store( array( 'zdz_example_row' => zdzk_seal_under( $k, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ) ) );
		$this->assert_held( $this->moment( 'lost_record_same_key', array( 'key' => $k, 'k2' => $this->key() ) ) );
		$this->assertSame( zdzk_kid( $k ), $this->stored()['options']['zdz_keys_state']['kid'] );
	}

	public function test_a_key_the_stored_values_contradict_is_never_recorded(): void {
		$k     = $this->key();
		$other = $this->key();
		$this->store( array( 'zdz_example_row' => zdzk_seal_under( $other, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ) ) );
		$before = (string) file_get_contents( $this->store_file() );
		$this->assert_held( $this->moment( 'lost_record_other_key', array( 'key' => $k, 'k2' => $other ) ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ) );
	}

	public function test_a_foreign_value_inside_a_settings_array_is_seen_too(): void {
		$k     = $this->key();
		$other = $this->key();
		$this->store( array( 'zdz_example_settings' => array( 'provider' => array( 'key' => zdzk_seal_under( $other, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'x' ) ), 'mode' => 'live' ) ) );
		$this->assert_held( $this->moment( 'foreign_id_inside_serialized_option', array( 'key' => $k, 'k2' => $other ) ) );
		$this->assertArrayNotHasKey( 'zdz_keys_state', $this->stored()['options'] );
	}

	public function test_a_record_lost_with_nothing_left_to_find_still_waits_for_an_administrator(): void {
		// The software records a key id by itself only where no key id was ever recorded: a scan cannot see every
		// place a value may sit, so "nothing found" is not proof.
		$this->store( array( 'zdz_keys_ever' => 1 ) );
		$this->assert_held( $this->moment( 'lost_record_nothing_found', array( 'key' => $this->key() ) ) );
	}

	public function test_a_record_lost_in_the_middle_of_a_key_change_records_the_previous_key(): void {
		$new = $this->key();
		$old = $this->key();
		$this->store( array( 'zdz_example_row' => zdzk_seal_under( $old, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ) ) );
		$this->assert_held( $this->moment( 'lost_record_mid_change', array( 'key' => $new, 'prev' => $old ) ) );
	}

	public function test_what_the_scan_cannot_look_through_it_does_not_guess_about(): void {
		$this->assert_held( $this->moment( 'job_too_large_to_scan', array( 'key' => $this->key() ) ) );
		$this->assertArrayNotHasKey( 'zdz_keys_state', $this->stored()['options'] );
		$this->assert_held( $this->moment( 'large_job_with_a_key_id_reader', array( 'key' => $this->key() ), 'other' ) );
	}

	public function test_the_scan_reads_every_option_row_however_many_there_are(): void {
		$k     = $this->key();
		$other = $this->key();
		$rows  = array();
		for ( $n = 1; $n <= 250; $n++ ) {
			$rows[ 'zdz_example_row_' . $n ] = zdzk_seal_under( $k, self::cred(), 'credentials', 'value-EXAMPLE-' . $n, 'zdz_example_row_' . $n );
		}
		$rows['zdz_example_row_last'] = zdzk_seal_under( $other, self::cred(), 'credentials', 'value-EXAMPLE-last', 'zdz_example_row_last' );
		$this->store( $rows );
		$this->assert_held( $this->moment( 'many_option_rows', array( 'key' => $k, 'k2' => $other ) ) );
	}

	/* ── another site's key, a copy, a move ───────────────────────────────── */

	public function test_another_sites_key_file_locks_app_secrets(): void {
		$this->assert_held( $this->moment( 'site_line_other_host', array( 'key' => $this->key(), 'site' => 'staging.example.com' ) ) );
		$this->assertFileDoesNotExistYet( $this->store_file() );
	}

	public function test_a_copied_database_stays_locked_whatever_key_file_the_copy_has(): void {
		$live = $this->key();
		$base = array(
			'zdz_keys_state'  => self::record( $live, 'production.example.com' ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $live, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		);
		// a hand-written copy of the live site's file that names no site
		$this->store( $base, array(), 'a' );
		$before = (string) file_get_contents( $this->store_file( 'a' ) );
		$this->assert_held( $this->moment( 'copied_database', array( 'key' => $live ), 'a' ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file( 'a' ) ) );
		// the copy's own file, with a key of its own: locked too, and nothing offers to start it afresh
		$this->store( $base, array(), 'b' );
		$this->assert_held( $this->moment( 'copied_database', array( 'key' => $this->key(), 'site' => 'app.example.com' ), 'b' ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file( 'b' ) ) );
		// the live site's file, brought along by the copy
		$this->store( $base, array(), 'c' );
		$this->assert_held( $this->moment( 'copied_database_with_the_other_sites_file', array( 'key' => $live, 'site' => 'production.example.com' ), 'c' ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file( 'c' ) ) );
	}

	public function test_a_site_that_moved_to_a_new_address_is_recorded_there_by_an_administrator(): void {
		$k = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $k, 'old.example.com' ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $k, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		) );
		$this->assert_held( $this->moment( 'moved_site', array( 'key' => $k, 'site' => 'app.example.com' ) ) );
		$this->assertSame( 'app.example.com', $this->stored()['options']['zdz_keys_state']['site'] );
		$this->assertSame( zdzk_kid( $k ), $this->stored()['options']['zdz_keys_state']['prev_kid'], 'the record keeps the key id it replaced' );
	}

	public function test_a_move_is_not_recorded_over_a_value_under_another_key(): void {
		$k = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $k, 'old.example.com' ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $this->key(), self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
		) );
		$this->assert_held( $this->moment( 'moved_site_with_a_foreign_value', array( 'key' => $k, 'site' => 'app.example.com' ) ) );
		$this->assertSame( 'old.example.com', $this->stored()['options']['zdz_keys_state']['site'] );
	}

	/* ── a previous key ───────────────────────────────────────────────────── */

	public function test_a_previous_key_line_that_is_not_a_key_opens_nothing_and_is_never_shown(): void {
		$this->assert_held( $this->moment( 'previous_key_malformed', array( 'key' => $this->key(), 'prev' => 'NOT-A-PRINTED-KEY-EXAMPLE' ) ) );
	}

	public function test_a_previous_key_equal_to_the_current_one_is_ignored(): void {
		$k = $this->key();
		$this->assert_held( $this->moment( 'previous_key_same_as_current', array( 'key' => $k, 'prev' => strtolower( $k ) ) ) );
	}

	public function test_the_previous_key_reads_old_values_and_only_while_its_line_is_there(): void {
		$new = $this->key();
		$old = $this->key();
		$this->store( array(
			'zdz_keys_state'  => self::record( $old ),
			'zdz_keys_ever'   => 1,
			'zdz_example_row' => zdzk_seal_under( $old, self::cred(), 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' ),
			'zdz_example_mac' => zdzk_mac_under( $old, ZDZ_Keys::PURPOSES['sign'], 'sign', 'message-EXAMPLE' ),
		) );
		$before = (string) file_get_contents( $this->store_file() );
		$this->assert_held( $this->moment( 'previous_key_reads_old_values', array( 'key' => $new, 'prev' => $old ) ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ), 'reading with either key writes nothing' );
		// the previous-key line taken out too soon: the recorded key is not there any more
		$this->assert_held( $this->moment( 'previous_key_gone_too_soon', array( 'key' => $new ) ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store_file() ) );
	}

	/* ── the salts change ─────────────────────────────────────────────────── */

	public function test_a_change_to_the_wordpress_salts_leaves_what_the_app_key_sealed_readable(): void {
		$k    = $this->key();
		$one  = $this->moment( 'salts_seal', array( 'key' => $k, 'salts' => 'one' ) );
		$this->assert_held( $one );
		$kept = (string) file_get_contents( $this->store_file() );
		$this->assertStringNotContainsString( 'provider-key-EXAMPLE', $kept, 'nothing is stored in the clear' );
		$this->assertStringNotContainsString( 'zdzk-unit-salt', $kept, 'and no salt' );
		$two = $this->moment( 'salts_changed', array( 'key' => $k, 'salts' => 'two' ) );
		$this->assert_held( $two );
		$this->assertNotSame( $one['data']['fp_auth'], $two['data']['fp_auth'], 'the two moments really had different salts' );
		$this->assertSame( $kept, (string) file_get_contents( $this->store_file() ), 'and the change wrote nothing' );
	}

	/* ── no libsodium ─────────────────────────────────────────────────────── */

	public function test_without_libsodium_the_service_is_locked_and_says_so(): void {
		$off = array( '-d', 'disable_functions=sodium_crypto_kdf_derive_from_key,sodium_memzero' );
		$this->assert_held( $this->moment( 'no_sodium', array( 'key' => $this->key() ), 'site', $off ) );
	}

	/** A file that a moment had no reason to make is not there. */
	private function assertFileDoesNotExistYet( string $file ): void {
		$this->assertFalse( file_exists( $file ), basename( $file ) . ' was written' );
	}
}
