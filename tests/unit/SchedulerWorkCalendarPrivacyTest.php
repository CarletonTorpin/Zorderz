<?php
/**
 * Scheduler — a WORK calendar may show what a colleague is busy with; a personal one only when.
 *
 *   - ZSCH_Settings::is_work_email() matches the ACCOUNT's domain exactly (lowercased, a
 *     trailing root dot dropped, the domain read from the LAST '@'), never by substring,
 *     prefix, suffix or subdomain.
 *   - It FAILS CLOSED on every uncertain input, and with nothing configured it discloses
 *     nothing at all (the Core default).
 *   - work_domains() refuses tokens that cannot be a domain, so a stray comma or 'com' can
 *     never widen disclosure.
 *   - The busy read gates the title per row through that predicate, and the two upstream
 *     protections (a private-marked event, a busy-only feed) are still in place.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-settings.php';

final class SchedulerWorkCalendarPrivacyTest extends TestCase {

	private const DIR = __DIR__ . '/../../zorderz-apps/apps/scheduler/includes/';

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'] );
	}

	private function domains( string $raw ): void {
		$GLOBALS['zdz_test_options']['zsch_work_domains'] = $raw;
	}

	public function test_nothing_configured_discloses_nothing(): void {
		$this->assertSame( array(), ZSCH_Settings::work_domains() );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'alex@example.com' ), 'the Core default must disclose no titles' );
	}

	public function test_exact_domain_matches(): void {
		$this->domains( 'example.com' );
		$this->assertTrue( ZSCH_Settings::is_work_email( 'alex@example.com' ) );
		$this->assertTrue( ZSCH_Settings::is_work_email( '  Jordan@EXAMPLE.COM ' ), 'case and surrounding space do not matter' );
		$this->assertTrue( ZSCH_Settings::is_work_email( 'sam@example.com.' ), 'a trailing root dot is the same domain' );
	}

	public function test_lookalike_domains_never_match(): void {
		$this->domains( 'example.com' );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'riley@notexample.com' ), 'substring' );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'riley@example.com.attacker.net' ), 'suffix attack' );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'riley@sub.example.com' ), 'a subdomain is a different mail domain' );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'riley@example.net' ), 'another TLD' );
	}

	public function test_domain_is_read_from_the_last_at_sign(): void {
		$this->domains( 'example.com' );
		$this->assertTrue( ZSCH_Settings::is_work_email( '"casey@example.net"@example.com' ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( '"casey@example.com"@example.net' ), 'only the real domain part counts' );
	}

	public function test_fails_closed_on_uncertain_input(): void {
		$this->domains( 'example.com' );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'no-at-sign.example.com' ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'blake@' ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( '' ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( null ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( array( 'quinn@example.com' ) ) );
		$this->assertFalse( ZSCH_Settings::is_work_email( 42 ) );
	}

	public function test_domain_list_refuses_tokens_that_are_not_domains(): void {
		$this->domains( ' Example.com, , com ; example.org. example.com ' );
		$this->assertSame( array( 'example.com', 'example.org' ), ZSCH_Settings::work_domains() );
		$this->assertFalse( ZSCH_Settings::is_work_email( 'morgan@com' ), "a bare 'com' is never matchable" );
		$this->assertTrue( ZSCH_Settings::is_work_email( 'morgan@example.org' ) );
	}

	public function test_busy_read_gates_the_title_per_row_and_fails_closed(): void {
		$src = (string) file_get_contents( self::DIR . 'class-zsch-sync.php' );
		$this->assertMatchesRegularExpression(
			"/\\\$title = '';\s*if \( class_exists\( 'ZSCH_Settings' \)\s*&& ZSCH_Settings::is_work_email\( \(string\) \( \\\$r\['email_label'\] \?\? '' \) \) \) \{/",
			$src,
			'the title starts empty and is only filled for a work account, with the settings class required'
		);
		$this->assertStringContainsString( "'title'       => \$title,", $src );
		$this->assertDoesNotMatchRegularExpression( "/'title'\s*=>\s*\(string\)\s*\\\$r\['title'\]/", $src, 'the raw title must never be emitted directly' );
	}

	public function test_upstream_protections_still_stand(): void {
		$g = (string) file_get_contents( self::DIR . 'class-zsch-google.php' );
		$m = (string) file_get_contents( self::DIR . 'class-zsch-graph-delegated.php' );
		$s = (string) file_get_contents( self::DIR . 'class-zsch-sync.php' );
		$this->assertStringContainsString( "'title'             => \$private ? '' :", $g, 'a private Google event keeps no title' );
		$this->assertStringContainsString( "'title'             => \$private ? '' :", $m, 'a private Outlook event keeps no title' );
		$this->assertStringContainsString( "\$busy_only  = ( 'busy_only' === (string) \$f->privacy );", $s, 'a busy-only feed stores no title' );
	}
}
