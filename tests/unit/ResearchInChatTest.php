<?php
/**
 * Deep research in the conversation:
 *
 *   - research.js, in Node: the plan card's Start buttons, the progress card's Stop, the trail and "Keep going" under
 *     a finished answer, the "Go deeper" button; a sentence the server did not write is never sent, every word is
 *     written as text, and nothing is drawn for a run that is not the viewer's;
 *   - chat-kit.js reads the two tokens exactly, never inside code, and leaves them out of copied text;
 *   - the chat asks research in its three places, in the right order;
 *   - the app loads it as one bounded block, its script only where the chat is.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class ResearchInChatTest extends TestCase {

	/** @var array|null the Node cases' results (an empty array: Node is not installed here) */
	private static $js = null;

	private static function dir(): string {
		return dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/';
	}

	/** Run every case in one Node process; null when Node is not installed here. */
	private static function js(): ?array {
		if ( null !== self::$js ) {
			return self::$js ?: null;
		}
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			self::$js = array();
			return null;
		}
		$code = file_get_contents( __DIR__ . '/support/research-js-cases.js' );
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( self::dir() . 'assets/js/research.js' ) . ' ' . escapeshellarg( self::dir() . 'assets/js/chat-kit.js' ) ); // sentinel:allow every argument is escapeshellarg()'d
		self::$js = json_decode( (string) $out, true ) ?: array( 'error' => (string) $out );
		return self::$js;
	}

	private function r( string $key ) {
		$k = self::js();
		if ( null === $k ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$this->assertArrayNotHasKey( 'error', $k, (string) ( $k['error'] ?? '' ) );
		$this->assertArrayHasKey( $key, $k );
		return $k[ $key ];
	}

	/* ------------------------------------------------------------------ research.js: plain helpers */

	public function test_times_amounts_and_labels(): void {
		$this->assertSame( array( '0:00', '0:05', '1:00', '2:05', '60:00', '0:00', '0:00' ), $this->r( 'mmss' ) );
		$this->assertSame( array( '$1.00', '$0.40', '$2.50', '$0.00', '$0.00', '$0.00' ), $this->r( 'usd' ) );
		$this->assertSame( array( 'Step 2 of 24', 'x and {zz}', '<b><i></b>' ), $this->r( 'fill' ), 'a value is put in as it is: the page writes it as text' );
		$this->assertSame( array( '…', '✓', '✕', '–', '⏸', '?', '•', '•', '•' ), $this->r( 'marks' ) );
	}

	public function test_an_event_is_shaped_as_text_and_cut_to_size(): void {
		$e = $this->r( 'event' );
		$this->assertSame( '1:05', $e['time'] );
		$this->assertSame( 'ok', $e['state'] );
		$this->assertSame( array( '0:17', '2 sources', 'tradeweekly.example, citygazette.example' ), $e['extras'] );
		$this->assertSame( 'an excerpt', $e['excerpt'] );
		$bad = $this->r( 'eventBad' );
		$this->assertSame( 'info', $bad['state'], 'a state it does not know is shown as information' );
		$this->assertSame( 'obj', $bad['label'] );
		$this->assertSame( 500, mb_strlen( $bad['detail'] ) );
		$this->assertSame( 320, mb_strlen( $bad['excerpt'] ) );
		$this->assertCount( 3, $bad['items'] );
		$this->assertSame( 240, mb_strlen( $bad['items'][2] ) );
		$this->assertSame( '', $this->r( 'eventNone' )['label'] );
	}

	public function test_the_trail_in_one_line_and_how_often_the_page_asks(): void {
		$this->assertSame( '2 web searches · 1 data answer · 3 facts checked · 1 step restarted · 4:12', $this->r( 'summary' ) );
		$this->assertSame( '', $this->r( 'summaryEmpty' ) );
		$this->assertSame( array( 2500, 6000, 15000, 0, 0 ), $this->r( 'poll' ), 'a plan waiting, a hidden tab: slower; an ended run: never' );
	}

	public function test_only_the_servers_own_sentences_are_ever_sent(): void {
		$this->assertSame( array( 'Deep research on my question above #0123abcd', '', '', '', '', '', '' ), $this->r( 'deeper' ) );
		$this->assertSame( array( 'Keep going on deep research #12', '', '', '', '' ), $this->r( 'more' ) );
	}

	/* ------------------------------------------------------------------ chat-kit.js: the tokens */

	public function test_the_tokens_are_read_exactly_and_never_inside_code(): void {
		$cards = $this->r( 'cards' );
		$this->assertSame( array( array( 'k' => 'research', 'id' => 12 ), array( 'k' => 'research_offer', 'tag' => '0123abcd' ) ), $cards );
		$text = $this->r( 'cardText' );
		$this->assertStringContainsString( '[ZANA_RESEARCH:abc]', $text, 'anything else is left as words' );
		$this->assertStringContainsString( '[ZANA_RESEARCH_OFFER:0123ABCD]', $text );
		$this->assertStringContainsString( '[ZANA_RESEARCH:99]', $text, 'inside a code block it is code' );
		$this->assertSame( 'The answer.', $this->r( 'plain' ), 'a copied answer carries no token' );
	}

	/* ------------------------------------------------------------------ research.js: the cards */

	public function test_the_two_cards_are_offered_to_the_chat(): void {
		$this->assertSame( array( 'research', 'research_offer' ), $this->r( 'registered' ) );
	}

	public function test_go_deeper_sends_its_own_sentence_and_only_for_someone_who_could_start(): void {
		$this->assertSame( array( '🔬 Go deeper (deep research: up to 5 min · $1.00)' ), $this->r( 'offerLabel' ) );
		$this->assertSame( array( 'Deep research on my question above #0123abcd' ), $this->r( 'offerSent' ) );
		$this->assertTrue( $this->r( 'offerBadTag' ) );
		$this->assertTrue( $this->r( 'offerCannot' ) );
	}

	public function test_a_plan_waits_for_start_and_start_is_a_request_to_the_server(): void {
		$this->assertSame( array( 'Start, with the web', 'Start, your own data only', 'Cancel' ), $this->r( 'planButtons' ) );
		$plan = $this->r( 'planText' );
		$this->assertStringContainsString( 'Find <b>out</b>.', $plan, 'the plan\'s words are text, never markup' );
		$this->assertStringContainsString( 'Nothing more is spent until you press Start.', $plan );
		$this->assertStringContainsString( 'up to 5 min and $1.00', $plan );
		$this->assertSame( array( 'zana-research-tool is-web', 'zana-research-tool is-data', 'zana-research-tool is-data' ), $this->r( 'planTools' ), 'a tool it does not know is drawn as data' );
		$this->assertSame( array(
			array( '/research/12', 'GET', null ),
			array( '/research/12/start', 'POST', array( 'web' => false ) ),
		), $this->r( 'asked' ) );
		$this->assertSame( array( 'What it has done (2)', 'Stop' ), $this->r( 'workingButtons' ) );
		$working = $this->r( 'workingText' );
		$this->assertStringContainsString( 'Searching the web', $working );
		$this->assertStringContainsString( 'Step 1 of 24 · 2 sources · $0.30 of $1.00 · your own data only', $working );
		$this->assertStringContainsString( '1:05 · 3:55 left', $working );
	}

	public function test_the_answer_takes_the_cards_place_when_the_run_ends(): void {
		$this->assertSame( array( "The answer.\n\n[ZANA_RESEARCH:12]" ), $this->r( 'filled' ) );
	}

	public function test_under_a_finished_answer_the_trail_and_keep_going(): void {
		$this->assertSame( 0, $this->r( 'endedFilled' ), 'an answer already in place is not drawn again' );
		$this->assertSame( array( 'How this was researched (1 web search · 1 data answer · 4:12)', '➕ Keep going (up to 5 min · $1.00)' ), $this->r( 'endedButtons' ) );
		$trail = $this->r( 'trailText' );
		$this->assertStringContainsString( 'Searched the web', $trail );
		$this->assertStringNotContainsString( 'never shown', $trail, 'the kept trail shows no excerpt' );
		$this->assertSame( array( 'Keep going on deep research #12' ), $this->r( 'moreSent' ) );
		$this->assertSame( 1, $this->r( 'forgedButtons' ), 'a sentence the server did not write gets no button: only the trail' );
		$this->assertSame( 1, $this->r( 'cannotButtons' ), 'nor for someone who could not start a run' );
	}

	public function test_nothing_is_drawn_for_a_run_that_is_not_the_viewers(): void {
		$this->assertTrue( $this->r( 'goneHidden' ) );
		$this->assertTrue( $this->r( 'badId' ) );
	}

	public function test_the_script_writes_no_markup_from_what_it_is_told(): void {
		$src = (string) file_get_contents( self::dir() . 'assets/js/research.js' );
		$this->assertStringNotContainsString( 'innerHTML', $src );
		$this->assertStringNotContainsString( 'insertAdjacentHTML', $src );
		$this->assertStringNotContainsString( 'outerHTML', $src );
		$this->assertStringNotContainsString( 'eval(', $src );   // sentinel:allow a string the test looks for in the page script, not a call
		$this->assertDoesNotMatchRegularExpression( '/claude|anthropic|openai|gemini|\bgpt\b/i', $src, 'no provider is named' );
	}

	/* ------------------------------------------------------------------ the chat and the app */

	public function test_the_chat_asks_research_in_its_three_places(): void {
		$chat = (string) file_get_contents( self::dir() . 'includes/class-zana-chat.php' );
		$turn = strpos( $chat, 'ZANA_Research::turn( $user_id, $session_id, $message, $history )' );
		$this->assertGreaterThan( 0, $turn );
		$this->assertGreaterThan( strpos( $chat, "self::store_message( \$session_id, \$user_id, 'user', \$message" ), $turn, 'after the person\'s message is kept' );
		$this->assertLessThan( strpos( $chat, 'ZANA_Routes::before_model(' ), $turn, 'and before any rule answers the turn' );
		$this->assertLessThan( $turn, strpos( $chat, 'is_shared_device( $user_id )' ), 'the shared device is refused before research is ever asked' );
		$this->assertGreaterThan( strpos( $chat, 'ZANA_Routes::finish(' ), strpos( $chat, 'ZANA_Research::after_answer(' ), 'the last word on an answer, after the outbound gate' );
		$this->assertStringContainsString( 'ZANA_Research::banner(', $chat, 'a rule answer says a refused run too' );
		$this->assertSame( 1, substr_count( $chat, '// ── Deep research (BEGIN)' ) );
	}

	public function test_the_app_loads_it_as_one_block_and_its_script_only_where_the_chat_is(): void {
		$app = (string) file_get_contents( self::dir() . 'app.php' );
		$a   = strpos( $app, 'Deep research (BEGIN)' );
		$b   = strpos( $app, 'Deep research (END)' );
		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( $a, $b );
		$block = substr( $app, $a, $b - $a );
		foreach ( array( 'web-vetting', 'research-settings', 'research-store', 'research-activity', 'research-gate', 'research-tools', 'research', 'research-rest' ) as $part ) {
			$this->assertStringContainsString( "'" . $part . "'", $block );
			$this->assertFileExists( self::dir() . 'includes/class-zana-' . $part . '.php' );
		}
		$this->assertStringContainsString( "wp_script_is( 'zana-chat', 'enqueued' )", $block, 'its script and styles only on a page with the chat' );
		$this->assertStringContainsString( 'ZANA_Research_Settings::page_config(', $block );
		$this->assertSame( 1, substr_count( $app, 'Deep research (BEGIN)' ) );
		$this->assertStringNotContainsString( 'ZANA_Research', substr( $app, 0, $a ) . substr( $app, $b + 30 ), 'nothing of it outside its block' );
	}

	public function test_no_new_admin_ajax_action_and_no_public_route(): void {
		foreach ( glob( self::dir() . 'includes/class-zana-research*.php' ) as $f ) {
			$src = (string) file_get_contents( $f );
			$this->assertStringNotContainsString( 'wp_ajax_', $src, basename( $f ) . ': the page asks through the chat\'s REST routes' );
			$this->assertStringNotContainsString( '__return_true', $src, basename( $f ) );
		}
		$rest = (string) file_get_contents( self::dir() . 'includes/class-zana-research-rest.php' );
		$this->assertSame( 3, substr_count( $rest, "'permission_callback' => array( __CLASS__, 'can_access' )" ) );
		$this->assertSame( 3, substr_count( $rest, 'register_rest_route(' ) );
	}
}
