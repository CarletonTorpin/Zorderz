<?php
/**
 * Route Planner moves the codes it kept in its own box into the vault itself (Zorderz -> Route Planner, Gate codes),
 * through ZDZ_Sealed's public seal(); it offers nothing on a scan filter that no part of the platform runs.
 *
 *   - admin_run() runs only the two known presses; anything else runs nothing;
 *   - result_words() says what a press did, what could not be kept and whether to press again;
 *   - the screen counts first and moves or seals only on an administrator's press with its own nonce;
 *   - no scan offer is left.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/route-planner/includes/class-zrp-codes.php';

final class RoutePlannerCodesMoveTest extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/route-planner/' . $rel );
	}

	public function test_an_unknown_press_runs_nothing(): void {
		$this->assertSame( array( 'rows' => 0, 'kept' => 0, 'lost' => 0, 'skipped' => 0, 'partial' => false ), ZRP_Codes::admin_run( 'everything', microtime( true ) + 1 ) );
	}

	public function test_the_words_for_a_press(): void {
		$this->assertSame( '3 codes moved into the vault.', ZRP_Codes::result_words( array( 'kept' => 3 ), 'move' ) );
		$this->assertSame(
			'1 code moved into the vault. 2 could not be opened with this site\'s keys and stay where they were. There are more: press again to carry on.',
			ZRP_Codes::result_words( array( 'kept' => 1, 'lost' => 2, 'partial' => true ), 'move' )
		);
		$this->assertSame(
			'2 codes from drive notes sealed. 1 was cut from its note but the vault did not keep it. 1 changed meanwhile and was left alone.',
			ZRP_Codes::result_words( array( 'kept' => 2, 'lost' => 1, 'skipped' => 1 ), 'notes' )
		);
	}

	public function test_the_move_seals_through_the_vaults_public_api(): void {
		$codes = self::src( 'includes/class-zrp-codes.php' );
		$this->assertStringContainsString( '$vid = ZDZ_Sealed::seal( self::vault_kind( $c[\'kind\'] ), $plain, self::vault_scope( $c[\'street_key\'], $c[\'zip\'] ) );', $codes );
		$this->assertStringNotContainsString( 'scan_sources', $codes, 'no offer on a scan nothing runs' );
		$this->assertStringNotContainsString( 'zdz_sealed_scan_sources', self::src( 'app.php' ) );
	}

	public function test_the_screen_counts_first_and_runs_only_on_a_press(): void {
		$app = self::src( 'app.php' );
		$this->assertStringContainsString( "if ( ! current_user_can( 'manage_options' ) || empty( \$_POST['zrp_codes'] ) ) {", $app );
		$this->assertStringContainsString( "check_admin_referer( 'zrp_codes' );", $app );
		$this->assertStringContainsString( "ZRP_Codes::count_unmoved()", $app, 'the codes in the own box are counted before any press' );
		$this->assertStringContainsString( "'count_notes' === \$what", $app, 'notes are looked through before they are sealed' );
		$this->assertStringContainsString( "|| ! ZRP_Codes::vault_on() ) {", $app, 'nothing moves without the vault' );
	}
}
