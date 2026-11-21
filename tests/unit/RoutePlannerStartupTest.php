<?php
/**
 * Route Planner: the start-up read waits and asks again instead of ending on one refusal.
 *
 * The waiting rules are small pure functions inside the page script. This lifts them out by name and runs them
 * in node, so the rules themselves are pinned: which answers are waited out, how long, and when not at all.
 */

use PHPUnit\Framework\TestCase;

final class RoutePlannerStartupTest extends TestCase {

	private static function script(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/route-planner/assets/app.js' );
	}

	/** The source text of one top-level helper inside the page script, by name. */
	private static function fn( string $name ): string {
		$src = self::script();
		$at  = strpos( $src, 'function ' . $name . '(' );
		if ( false === $at ) {
			return '';
		}
		$open  = strpos( $src, '{', $at );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, $at, $i - $at + 1 );
				}
			}
		}
		return '';
	}

	private function run_js( string $body ) {
		if ( ! is_executable( '/usr/bin/node' ) && ! is_executable( '/usr/local/bin/node' ) ) {
			$this->markTestSkipped( 'node is not available' );
		}
		$helpers = '';
		foreach ( array( 'busy', 'tooLong', 'waitMs', 'retryAfter' ) as $name ) {
			$one = self::fn( $name );
			$this->assertNotSame( '', $one, $name . '() is in the page script' );
			$helpers .= $one . "\n";
		}
		$this->assertSame( 1, preg_match( '/var WAITS = \[[0-9, ]+\], WAIT_MAX = \d+, ANSWER_MS = \d+;/', self::script(), $m ) );
		$this->assertSame( 1, preg_match( '/var BUSY = \[[0-9, ]+\];/', self::script(), $b ) );
		$script = $m[0] . "\n" . $b[0] . "\n" . $helpers . 'console.log(JSON.stringify((function(){' . $body . '})()));';
		$out    = shell_exec( 'node -e ' . escapeshellarg( $script ) . ' 2>/dev/null' );
		$this->assertNotNull( $out, 'node ran the helpers' );
		return json_decode( (string) $out, true );
	}

	public function test_which_answers_are_waited_out(): void {
		$r = $this->run_js( 'var o={}; [429,502,503,504,520,521,522,523,524,400,401,403,404,500].forEach(function(s){ o[s]=busy({status:s}); }); o.net=busy({net:true}); o.none=busy(null); return o;' );
		foreach ( array( 429, 502, 503, 504, 520, 521, 522, 523, 524 ) as $s ) {
			$this->assertTrue( $r[ $s ], "HTTP $s is waited out" );
		}
		foreach ( array( 400, 401, 403, 404, 500 ) as $s ) {
			$this->assertFalse( $r[ $s ], "HTTP $s is an answer, not a busy site" );
		}
		$this->assertTrue( $r['net'], 'a request that never arrived is waited out' );
		$this->assertFalse( $r['none'] );
	}

	public function test_a_long_wait_asked_by_a_429_is_not_waited_at_all(): void {
		$r = $this->run_js( 'return { over: tooLong({status:429, wait:31}), at: tooLong({status:429, wait:30}), other: tooLong({status:503, wait:600}), none: tooLong(null) };' );
		$this->assertTrue( $r['over'], 'more than 30 s: the person is told at once, with how long' );
		$this->assertFalse( $r['at'], 'exactly 30 s is still waited' );
		$this->assertFalse( $r['other'], 'on other statuses a long Retry-After is a guess, so the usual waits stand' );
		$this->assertFalse( $r['none'] );
	}

	public function test_waits_grow_and_never_come_sooner_than_asked(): void {
		$r = $this->run_js( 'var o={plain:[],asked:[],guess:[]}; for (var i=0;i<3;i++){ o.plain.push(waitMs({status:429,wait:0},i)); o.asked.push(waitMs({status:429,wait:20},i)); o.guess.push(waitMs({status:503,wait:600},i)); } return o;' );
		$floors = array( 2000, 5000, 10000 );
		foreach ( $floors as $i => $floor ) {
			$this->assertGreaterThanOrEqual( $floor, $r['plain'][ $i ] );
			$this->assertLessThanOrEqual( $floor + 800, $r['plain'][ $i ], 'a little apart, never much later' );
			$this->assertGreaterThanOrEqual( 20000, $r['asked'][ $i ], 'never sooner than the reply asked' );
			$this->assertLessThanOrEqual( $floor + 800, $r['guess'][ $i ], 'a guess over the limit does not stretch the wait' );
		}
	}

	public function test_retry_after_reads_seconds_or_a_date_and_nothing_else(): void {
		$r = $this->run_js( 'var soon=new Date(Date.now()+45000).toUTCString(); return { n: retryAfter("120"), sp: retryAfter(" 7 "), d: retryAfter(soon), past: retryAfter(new Date(Date.now()-5000).toUTCString()), junk: retryAfter("soon"), empty: retryAfter(""), nul: retryAfter(null) };' );
		$this->assertSame( 120, $r['n'] );
		$this->assertSame( 7, $r['sp'] );
		$this->assertGreaterThanOrEqual( 43, $r['d'] );
		$this->assertLessThanOrEqual( 46, $r['d'] );
		$this->assertSame( 0, $r['past'] );
		$this->assertSame( 0, $r['junk'] );
		$this->assertSame( 0, $r['empty'] );
		$this->assertSame( 0, $r['nul'] );
	}

	public function test_only_the_start_up_read_is_patient_and_only_a_get_waits(): void {
		$src = self::script();
		$this->assertSame( 1, substr_count( $src, 'patient: true' ), 'one read is marked patient' );
		$this->assertStringContainsString( "api('boot', { patient: true", $src );
		$this->assertStringContainsString( "opts.patient && (!opts.method || opts.method === 'GET') && busy(e) && !tooLong(e) && n < WAITS.length", $src );
		$this->assertStringContainsString( 'id="zrp-again"', $src, 'a failed start has a Try again button' );
		$this->assertStringContainsString( "window.addEventListener('online'", $src, 'coming back online tries again' );
	}
}
