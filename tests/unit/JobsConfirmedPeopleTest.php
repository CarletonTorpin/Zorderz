<?php
/**
 * Projects: the people confirmed on a Project may read it, and every read-only path asks the read gate.
 *
 *   - tie_from(): the confirmed salesperson is 'seller', anyone confirmed on another step 'other', everyone else and
 *     any unknown step nothing; both answer shapes (role => id, role => { user_id }) are read;
 *   - confirmed_tie() reads an enriched Project's list without asking the table, and gives nothing when there is no
 *     list to read (deny by default);
 *   - the read-only paths (My next's steps and its recent work, Upcoming's street and preparer, the Projects detail and
 *     list) ask may_view_project(); the paths that act (attach an appointment, place or print an order, schedule) still
 *     ask the write gate. The relationship ladder itself runs with the real permission engine in its own process:
 *     zorderz-apps/apps/jobs/tests/test-reading-grant.php.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-visibility.php';

final class JobsConfirmedPeopleTest extends TestCase {

	private const ALEX   = 501; // confirmed salesperson
	private const JORDAN = 502; // confirmed installer
	private const SAM    = 503; // on nothing

	private static function jobs( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/' . $file );
	}

	public function test_the_tie_a_confirmed_step_gives(): void {
		$list = array( 'seller' => self::ALEX, 'installer' => self::JORDAN );
		$this->assertSame( 'seller', Zjob_Project_Visibility::tie_from( self::ALEX, $list ) );
		$this->assertSame( 'other', Zjob_Project_Visibility::tie_from( self::JORDAN, $list ) );
		$this->assertSame( '', Zjob_Project_Visibility::tie_from( self::SAM, $list ), 'nobody else gains anything' );
		$this->assertSame( '', Zjob_Project_Visibility::tie_from( 0, $list ), 'nobody signed in' );
	}

	public function test_both_answer_shapes_and_unknown_steps(): void {
		$rows = array(
			'orderer'  => array( 'user_id' => self::JORDAN, 'by' => self::ALEX, 'at' => '2026-01-02 03:04:05', 'decision_id' => 0 ),
			'preparer' => array( 'user_id' => self::ALEX ),
		);
		$this->assertSame( 'other', Zjob_Project_Visibility::tie_from( self::JORDAN, $rows ) );
		$this->assertSame( 'other', Zjob_Project_Visibility::tie_from( self::ALEX, $rows ) );
		$this->assertSame( '', Zjob_Project_Visibility::tie_from( self::SAM, array( 'watcher' => self::SAM ) ), 'a step that is not one of the five is no tie' );
		$this->assertSame( 'seller', Zjob_Project_Visibility::tie_from( self::ALEX, array( 'installer2' => self::ALEX, 'seller' => array( 'user_id' => self::ALEX ) ) ), 'the seller step outranks the rest' );
		$this->assertSame( '', Zjob_Project_Visibility::tie_from( self::ALEX, array( 'seller' => 'not a number' ) ) );
	}

	public function test_an_enriched_project_is_read_without_the_table_and_nothing_means_nothing(): void {
		$p = array( 'id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'confirmed' => array( 'installer' => self::JORDAN ) );
		$this->assertSame( 'other', Zjob_Project_Visibility::confirmed_tie( self::JORDAN, $p ) );
		$this->assertSame( '', Zjob_Project_Visibility::confirmed_tie( self::JORDAN, array( 'confirmed' => array() ) ), 'an empty list is no tie' );
		$this->assertSame( '', Zjob_Project_Visibility::confirmed_tie( self::JORDAN, array( 'id' => '' ) ), 'no Project id: nothing to read, no tie' );
	}

	public function test_read_only_paths_ask_the_read_gate(): void {
		$next = self::jobs( 'class-zjob-my-next.php' );
		$this->assertStringNotContainsString( 'actor_may_use_project', $next, 'My next only reads Projects' );
		$this->assertSame( 2, substr_count( $next, 'Zjob_Project_Visibility::may_view_project(' ) );
		$this->assertStringContainsString( "'confirmed'    => (array) ( \$data['people'][ \$pid ] ?? array() )", $next, 'the people already read are handed to the gate' );

		$up = self::jobs( 'class-zjob-upcoming.php' );
		$this->assertStringNotContainsString( 'actor_may_use_project', $up );
		$this->assertStringContainsString( 'Zjob_Project_Visibility::may_view_project( $uid, $project )', $up );

		$ajax = self::jobs( 'class-zjob-projects-ajax.php' );
		$this->assertStringContainsString( 'may_view_project( $uid, $p )', $ajax );
		$this->assertStringContainsString( "'confirmed' => true", $ajax, 'the list carries the Projects the person is confirmed on' );
	}

	public function test_acting_paths_keep_the_write_gate(): void {
		$this->assertStringContainsString( 'actor_may_use_project( $viewer, $project )', self::jobs( 'class-zjob-appointment-link.php' ) );
		$this->assertStringContainsString( 'actor_may_use_project( $viewer, $project )', self::jobs( 'class-zjob-schedule-context.php' ) );
		$this->assertStringContainsString( 'actor_may_use_project( $uid, $project )', self::jobs( 'class-zjob-project-orders.php' ) );
		$this->assertStringContainsString( 'actor_may_use_project( $uid, $project )', self::jobs( 'class-zjob-order-paper.php' ) );
		$vis = self::jobs( 'class-zjob-project-visibility.php' );
		$this->assertStringContainsString( "\$tie = \$for_reading ? self::confirmed_tie( \$viewer, \$project ) : '';", $vis, 'a write gate never counts a confirmed step' );
	}
}
