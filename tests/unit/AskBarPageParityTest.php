<?php
/**
 * The ask bar's rules run twice: on the page (the theme's app.js reads zdzData.askPhrases) and on the server
 * (ZDZ_Ask_Actions::match, ZDZ_Ask_Phrases::match_commission / match_stock / match_launch, ZDZ_For_You::mode_at). One
 * corpus, both sides, the same answer: the work people say opens the same app with the same name and number, a named
 * person's commission is read the same way, a stock question names the same item, a launch opens the same app, and work
 * hours decide "at work" the same way, across midnight too.
 *
 * The page's functions are lifted out of app.js itself (tests/unit/support/app-js-ask.js) and run in Node with the
 * rules the server hands the page. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-phrases.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-actions.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-for-you.php';

final class AskBarPageParityTest extends TestCase {

	const APPS = array( 'estimate-creator', 'scheduler', 'leads', 'surveys', 'jobs', 'prep', 'receipts', 'stock', 'internal-messaging',
		'commission-calculator', 'zdz-sketch-pad', 'zdz-media', 'knowledge-vault', 'game' );

	private static $page = null;

	private static function corpus(): array {
		return array(
			'actions'    => array(
				'send the estimate to Jordan', 'Um, send the estimate to Jordan please', 'resend estimate #5123', 'approve estimate 2041',
				'share the estimate in the team chat', 'on my way to the Example job', 'tell Alex the Example job moved',
				'assign leads to Alex', 'forward this lead to Sam', 'send the surveys from last week', 'hey, send the receipt to Casey',
				'Ok so mark the Example project installed.', 'print the cut sheets', 'create a channel for the crew',
				'close out the Example job', 'how many estimates did I send', 'did Jordan start the job', 'estimates sent this month',
				'send the estimate to Jordan?', 'what is on the schedule today',
			),
			'commission' => array(
				"Alex's May commission", "Jordan's commission by product", "how is Jordan's commission calculated",
				'how much did Jordan earn last month', 'my commission this month', 'commission by salesperson',
				'average commission this year', 'how much did the Example job make', 'luanch commission', 'Alex and Jordan commission',
				"what's Sam's commission rate", 'commission for Riley in Q2', 'did Casey make anything in March',
			),
			'stock'      => array(
				'how much tape do we have', 'do we have any hinges in stock?', 'how many leads do we have', "what's available tomorrow",
				'how many are left on the Example job right now please tell me', 'are there any brackets available', 'got any rollers left',
			),
			'launch'     => array(
				'new estimate for Jordan Example', 'estimate for Jordan', 'schedule tomorrow', 'open commission by salesperson', 'open leads',
				'open the schedule for next week', 'show me the gallery', 'opne leads', 'play a game', 'check stock for hinges',
				'open messages', 'sketch', 'calendar this week', 'start a new quote for Morgan', 'the schedule for friday',
				'Schedule Tomorrow', 'open the commission report in messages', 'lauch the game', 'show leads from the expo',
			),
		);
	}

	private static function hours_cases(): array {
		$week  = array( 'days' => array( 1, 2, 3, 4, 5 ), 'start' => '09:00', 'end' => '17:00' );
		$night = array( 'days' => array( 5 ), 'start' => '22:00', 'end' => '06:00' );
		$flat  = array( 'days' => array( 1 ), 'start' => '08:00', 'end' => '08:00' );
		return array(
			array( 'hours' => $week, 'dow' => 1, 'min' => 540 ), array( 'hours' => $week, 'dow' => 1, 'min' => 1020 ),
			array( 'hours' => $week, 'dow' => 1, 'min' => 539 ), array( 'hours' => $week, 'dow' => 6, 'min' => 600 ),
			array( 'hours' => $night, 'dow' => 5, 'min' => 1380 ), array( 'hours' => $night, 'dow' => 6, 'min' => 120 ),
			array( 'hours' => $night, 'dow' => 6, 'min' => 400 ), array( 'hours' => $night, 'dow' => 5, 'min' => 600 ),
			array( 'hours' => $flat, 'dow' => 1, 'min' => 480 ),
		);
	}

	/** Run the page's interpreters once over the corpus; null when Node is not installed here. */
	private function page(): array {
		if ( null !== self::$page ) {
			return self::$page;
		}
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$phrases          = ZDZ_Ask_Phrases::for_js( 0 );
		$phrases['stock'] = ZDZ_Ask_Phrases::stock();   // as the page of someone who may ask Stock gets it
		$corpus           = self::corpus();
		$corpus['mode']   = self::hours_cases();
		$tmp              = tempnam( sys_get_temp_dir(), 'zdzask' );
		file_put_contents( $tmp, json_encode( array( 'phrases' => $phrases, 'apps' => self::APPS, 'corpus' => $corpus ) ) );
		$code = file_get_contents( __DIR__ . '/support/app-js-ask.js' );
		$app  = dirname( __DIR__, 2 ) . '/zorderz/assets/js/app.js';
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $app ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d
		unlink( $tmp );
		$res = json_decode( (string) $out, true );
		$this->assertIsArray( $res, 'the page side did not run: ' . substr( (string) $out, 0, 600 ) );
		self::$page = $res;
		return $res;
	}

	public function test_the_work_people_say_opens_the_same_app_on_both_sides(): void {
		$page = $this->page()['actions'];
		$hits = 0;
		foreach ( self::corpus()['actions'] as $q ) {
			$s = ZDZ_Ask_Actions::match( $q );
			$server = $s ? array( 'id' => $s['id'], 'app' => $s['app'], 'name' => $s['name'], 'number' => $s['number'] ) : null;
			$this->assertSame( $server, $page[ $q ], $q );
			$hits += $s ? 1 : 0;
		}
		$this->assertGreaterThan( 8, $hits, 'the corpus reaches the rows' );
	}

	public function test_a_named_persons_commission_is_read_the_same_way(): void {
		$page = $this->page()['commission'];
		foreach ( self::corpus()['commission'] as $q ) {
			$s = ZDZ_Ask_Phrases::match_commission( $q );
			$server = $s ? array( 'subject' => $s['subject'], 'period' => $s['period'], 'focus' => $s['focus'] ) : null;
			$this->assertSame( $server, $page[ $q ], $q );
		}
	}

	public function test_a_stock_question_names_the_same_item(): void {
		$page = $this->page()['stock'];
		foreach ( self::corpus()['stock'] as $q ) {
			$this->assertSame( ZDZ_Ask_Phrases::match_stock( $q ), $page[ $q ], $q );
		}
	}

	public function test_a_launch_opens_the_same_app_with_the_same_words(): void {
		$page = $this->page()['launch'];
		foreach ( self::corpus()['launch'] as $q ) {
			$s = ZDZ_Ask_Phrases::match_launch( $q, self::APPS );
			$p = $page[ $q ];
			if ( null === $s ) {
				$this->assertNull( $p, $q );
				continue;
			}
			$this->assertIsArray( $p, $q );
			$this->assertSame( $s['id'], $p['id'], $q );
			if ( '' !== $s['value'] ) {
				$this->assertSame( $s['value'], substr( $p['name'], -strlen( $s['value'] ) ), $q . ' (the words it opens with)' );
			}
		}
	}

	public function test_work_hours_decide_at_work_the_same_way(): void {
		$page = $this->page()['mode'];
		foreach ( self::hours_cases() as $i => $c ) {
			$this->assertSame( ZDZ_For_You::mode_at( $c['hours'], $c['dow'], $c['min'] ), $page[ $i ], json_encode( $c ) );
		}
	}
}
