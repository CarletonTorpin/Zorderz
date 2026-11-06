<?php
/**
 * The staging guard's decisions: when it is on (fail-safe: a copy of the live site never
 * reaches customers), which outbound requests may leave a copy, and which jobs may run.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-staging-guard.php';

final class StagingGuardTest extends TestCase {

	private static function env( array $over = array() ): array {
		return $over + array( 'host' => 'app.example.com', 'site_host' => 'app.example.com', 'db' => 'wp_live', 'env' => '', 'type' => 'production' );
	}

	private static function pin_of( array $env ): array {
		return array( 'fp' => ZDZ_Staging_Guard::fingerprint( $env ), 'host' => $env['host'], 'at' => 1, 'by' => 1 );
	}

	private static function none(): array {
		return array( 'force' => '', 'hosts' => array(), 'envs' => array() );
	}

	public function test_the_pinned_live_site_is_inert(): void {
		$live = self::env();
		$this->assertSame( array( 'active' => false, 'reason' => 'live' ), ZDZ_Staging_Guard::decide( $live, self::pin_of( $live ), self::none() ) );
	}

	public function test_a_copy_of_the_live_site_is_guarded_whatever_differs(): void {
		$pin = self::pin_of( self::env() );
		foreach ( array(
			'another host'                    => array( 'host' => 'staging.example.com', 'site_host' => 'staging.example.com', 'db' => 'wp_stage' ),
			'mid-copy: same host, other db'   => array( 'db' => 'wp_stage' ),
			'mid-copy: other host environment' => array( 'env' => 'examplestage' ),
		) as $why => $over ) {
			$d = ZDZ_Staging_Guard::decide( self::env( $over ), $pin, self::none() );
			$this->assertTrue( $d['active'], $why );
			$this->assertSame( 'copy', $d['reason'], $why );
		}
	}

	public function test_listed_host_or_environment_and_any_non_production_type_is_guarded(): void {
		$cfg = array( 'force' => '', 'hosts' => array( 'staging.example.com' ), 'envs' => array( 'examplestage' ) );
		$this->assertSame( 'listed', ZDZ_Staging_Guard::decide( self::env( array( 'host' => 'staging.example.com' ) ), null, $cfg )['reason'] );
		$this->assertSame( 'listed', ZDZ_Staging_Guard::decide( self::env( array( 'env' => 'examplestage' ) ), null, $cfg )['reason'] );
		foreach ( array( 'staging', 'development', 'local' ) as $type ) {
			$d = ZDZ_Staging_Guard::decide( self::env( array( 'type' => $type ) ), null, self::none() );
			$this->assertTrue( $d['active'], $type );
			$this->assertSame( 'environment_type', $d['reason'] );
		}
	}

	public function test_forcing_is_explicit_and_off_is_the_only_way_off_on_a_copy(): void {
		$pin  = self::pin_of( self::env() );
		$copy = self::env( array( 'host' => 'staging.example.com' ) );
		$this->assertSame( array( 'active' => false, 'reason' => 'forced_off' ), ZDZ_Staging_Guard::decide( $copy, $pin, array( 'force' => 'off' ) + self::none() ) );
		$this->assertSame( array( 'active' => true, 'reason' => 'forced_on' ), ZDZ_Staging_Guard::decide( self::env(), $pin, array( 'force' => 'on' ) + self::none() ) );
	}

	public function test_an_unpinned_production_site_is_pinned_by_the_first_administrator(): void {
		$this->assertSame( array( 'active' => false, 'reason' => 'unpinned' ), ZDZ_Staging_Guard::decide( self::env(), null, self::none() ) );
	}

	public function test_items_are_lower_case_unique_and_never_empty(): void {
		$this->assertSame( array( 'a.example.com', 'b.example.com' ), ZDZ_Staging_Guard::items( ' A.example.com, ,b.example.com,a.example.com' ) );
		$this->assertSame( array(), ZDZ_Staging_Guard::items( '' ) );
	}

	public function test_outbound_traffic_from_a_copy(): void {
		$self = 'staging.example.com';
		$d    = function ( $url, $method = 'GET', $self_ok = true ) use ( $self ) {
			return ZDZ_Staging_Guard::decide_http( $url, $method, $self, $self_ok );
		};
		// Provider sign-in and write hosts: never, whatever the method.
		$this->assertSame( 'block', $d( 'https://auth.freshbooks.com/oauth/authorize' ) );
		$this->assertSame( 'block', $d( 'https://api.freshbooks.com/auth/oauth/token', 'POST' ), 'the billing token refresh' );
		$this->assertSame( 'block', $d( 'https://login.microsoftonline.com/tenant/oauth2/v2.0/token', 'POST' ) );
		$this->assertSame( 'block', $d( 'https://app.nutshell.com/api/v1/json', 'GET' ) );
		$this->assertSame( 'block', $d( 'https://api.stripe.com/v1/charges' ) );
		$this->assertSame( 'block', $d( 'https://wns2.notify.windows.com/w/?token=x', 'POST' ) );
		// Reads still work; writes elsewhere do not; the Ai gateways may POST.
		$this->assertSame( 'allow', $d( 'https://api.freshbooks.com/accounting/account/x/invoices/invoices' ) );
		$this->assertSame( 'block', $d( 'https://api.freshbooks.com/accounting/account/x/invoices/invoices', 'POST' ) );
		$this->assertSame( 'block', $d( 'https://graph.microsoft.com/v1.0/me/sendMail', 'POST' ) );
		$this->assertSame( 'block', $d( 'https://hooks.example.net/notify', 'PUT' ) );
		$this->assertSame( 'allow', $d( 'https://api.poe.com/v1/chat/completions', 'POST' ) );
		$this->assertSame( 'allow', $d( 'https://api.anthropic.com/v1/messages', 'POST' ) );
		// Loopbacks: the copy's own host may write; the live host (mid-copy) may not.
		$this->assertSame( 'allow', $d( 'https://staging.example.com/wp-cron.php', 'POST' ) );
		$this->assertSame( 'block', $d( 'https://staging.example.com/wp-cron.php', 'POST', false ) );
		$this->assertSame( 'block', $d( 'https://staging.example.com/wp-admin/admin-ajax.php?action=as_async_request_queue_runner', 'POST' ) );
	}

	public function test_only_jobs_declared_safe_run_on_a_copy(): void {
		$now   = 1000.0;
		$crons = array(
			900  => array( 'zdz_send_reminders' => array( 'k' => array() ), 'zdz_safe_job' => array( 'k' => array() ) ),
			950  => array( 'zdz_sync_crm' => array( 'k' => array() ) ),
			2000 => array( 'zdz_safe_job' => array( 'k2' => array() ) ),
		);
		$this->assertSame( array(), ZDZ_Staging_Guard::filter_jobs( $crons, array(), $now ), 'Core allows no job by default' );
		$this->assertSame( array( 900 => array( 'zdz_safe_job' => array( 'k' => array() ) ) ), ZDZ_Staging_Guard::filter_jobs( $crons, array( 'zdz_safe_job' ), $now ) );
	}

	public function test_the_fingerprint_is_stable_and_names_no_secret(): void {
		$a = ZDZ_Staging_Guard::fingerprint( self::env() );
		$this->assertSame( $a, ZDZ_Staging_Guard::fingerprint( self::env() ) );
		$this->assertSame( 16, strlen( $a ) );
		$this->assertNotSame( $a, ZDZ_Staging_Guard::fingerprint( self::env( array( 'db' => 'wp_other' ) ) ) );
	}
}
