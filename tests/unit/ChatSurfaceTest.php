<?php
/**
 * The chat surface:
 *
 *   - ZANA_Surface::config(): a feature is on only when its server side answers; the shared device gets none and a
 *     Privacy View account none that writes, whatever a filter says; starter questions and their weights are cleaned;
 *   - chat-kit.js (run in Node, the file the page loads): links are https, mailto, tel or this site only; the markdown
 *     subset; the server's cards are matched exactly (Confirm, a drafted message or post, an email draft, Charts and
 *     app buttons, a page of this site, an invoice or estimate opener) and never inside a code block; the small chart's
 *     shapes; starter questions weighted; a Confirm card past its hold is expired; a turn carried into a fresh request
 *     earns more time; the docked thread's address.
 *
 * Neutral data only (Alex, Jordan, Example Co, example.com).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( $s, $start ) : substr( $s, $start, $len ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-surface.php';

final class ChatSurfaceTest extends TestCase {

	private static $kit = null;

	const ALL = array( 'memory', 'memoryDelete', 'dm', 'compose', 'notify', 'docs', 'docLink' );

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'] );
		ZANA_Surface::$probe = null;
	}

	/** Every service and admin-ajax action answers (or only those listed). */
	private function answering( array $only = array() ): void {
		ZANA_Surface::$probe = function ( $kind, $name, $method ) use ( $only ) {
			return ! $only || in_array( 'ajax' === $kind ? $name : $name . '::' . $method, $only, true );
		};
	}

	/* ── ZANA_Surface ────────────────────────────────────────────────────────────────────────────────── */

	public function test_a_feature_is_on_only_when_its_server_side_answers(): void {
		ZANA_Surface::$probe = function () { return false; };
		$this->assertSame( array_fill_keys( self::ALL, false ), ZANA_Surface::config( 7, false, false )['features'] );
		$this->answering( array( 'ZANA_REST_More::memory_list', 'zana_confirm_zim_dm' ) );
		$f = ZANA_Surface::config( 7, false, false )['features'];
		$this->assertTrue( $f['memory'] );
		$this->assertTrue( $f['dm'] );
		$this->assertFalse( $f['memoryDelete'] );
		$this->assertFalse( $f['docs'] );
		$this->answering();
		$this->assertSame( array_fill_keys( self::ALL, true ), ZANA_Surface::config( 7, false, false )['features'] );
	}

	public function test_privacy_view_reads_its_list_through_admin_ajax_only(): void {
		$this->answering( array( 'ZANA_REST_More::memory_list' ) );
		$this->assertFalse( ZANA_Surface::config( 7, false, true )['features']['memory'], 'its only road is admin-ajax' );
		$this->answering( array( 'zana_list_memories' ) );
		$this->assertTrue( ZANA_Surface::config( 7, false, true )['features']['memory'] );
		$this->assertFalse( ZANA_Surface::config( 7, false, false )['features']['memory'], 'everyone else reads it over REST' );
	}

	public function test_the_shared_device_and_privacy_view_never_get_a_feature_that_writes(): void {
		$this->answering();
		$GLOBALS['zdz_test_filters']['zana_chat_features'] = function ( $f ) { return array_fill_keys( array_keys( $f ), true ); };
		$this->assertSame( array_fill_keys( self::ALL, false ), ZANA_Surface::config( 7, true, false )['features'] );
		$pv = ZANA_Surface::config( 7, false, true )['features'];
		$this->assertTrue( $pv['memory'], 'its own sentences, read only' );
		$this->assertTrue( $pv['dm'], 'its one direct-message partner' );
		foreach ( array( 'memoryDelete', 'compose', 'notify', 'docs', 'docLink' ) as $k ) {
			$this->assertFalse( $pv[ $k ], $k );
		}
	}

	public function test_a_filter_may_turn_a_feature_off_never_on(): void {
		ZANA_Surface::$probe = function () { return false; };
		$GLOBALS['zdz_test_filters']['zana_chat_features'] = function ( $f ) { return array_fill_keys( array_keys( $f ), true ); };
		$this->assertSame( array_fill_keys( self::ALL, false ), ZANA_Surface::config( 7, false, false )['features'],
			'no button for a feature whose server side does not answer' );
		$this->answering();
		$GLOBALS['zdz_test_filters']['zana_chat_features'] = function ( $f ) { $f['dm'] = false; return $f; };
		$f = ZANA_Surface::config( 7, false, false )['features'];
		$this->assertFalse( $f['dm'] );
		$this->assertTrue( $f['compose'] );
	}

	public function test_starter_questions_and_weights_are_cleaned_and_core_lists_none(): void {
		ZANA_Surface::$probe = function () { return false; };
		$c = ZANA_Surface::config( 7, false, false );
		$this->assertSame( array(), $c['starters'] );
		$this->assertSame( 600, $c['confirm_ttl'] );
		$GLOBALS['zdz_test_filters']['zana_chat_starters'] = function () {
			return array( '  What   did we sell this week? ', '', 42, 'What did we sell this week?', str_repeat( 'x', 300 ) );
		};
		$GLOBALS['zdz_test_filters']['zana_chat_prompt_weights'] = function () {
			return array( 'What did we sell this week?' => 0.25, 'Big' => 5, 'Bad' => 'no', 3 => 0.5 );
		};
		$c = ZANA_Surface::config( 7, false, false );
		$this->assertSame( array( 'What did we sell this week?', str_repeat( 'x', 200 ) ), $c['starters'] );
		$this->assertSame( array( 'What did we sell this week?' => 0.25, 'Big' => 1.0 ), $c['weights'] );
		$this->assertSame( array(), ZANA_Surface::config( 7, true, false )['starters'], 'the shared device asks nothing' );
	}

	public function test_the_words_name_no_one(): void {
		foreach ( ZANA_Surface::words() as $k => $w ) {
			$this->assertIsString( $w, $k );
			$this->assertNotSame( '', trim( $w ), $k );
			$this->assertDoesNotMatchRegularExpression( '/\bAI\b/', $w, 'Ai, not AI: ' . $k );
		}
	}

	/* ── chat-kit.js, in Node ────────────────────────────────────────────────────────────────────────── */

	/** Run every case in one Node process; null when Node is not installed here. */
	private static function kit(): ?array {
		if ( null !== self::$kit ) {
			return self::$kit ?: null;
		}
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			self::$kit = array();
			return null;
		}
		$file = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/assets/js/chat-kit.js';
		$code = file_get_contents( __DIR__ . '/support/chat-kit-cases.js' );
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $file ) ); // sentinel:allow every argument is escapeshellarg()'d
		self::$kit = json_decode( (string) $out, true ) ?: array( 'error' => (string) $out );
		return self::$kit;
	}

	private function r( string $key ) {
		$k = self::kit();
		if ( null === $k ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$this->assertArrayNotHasKey( 'error', $k, (string) ( $k['error'] ?? '' ) );
		$this->assertArrayHasKey( $key, $k );
		return $k[ $key ];
	}

	public function test_links_are_https_mailto_tel_or_this_site_only(): void {
		$this->assertSame( array(
			'https://example.com/a' => 'https://example.com/a',
			'mailto:alex@example.com' => 'mailto:alex@example.com',
			'tel:555-0100' => 'tel:555-0100',
			'/vault/handbook' => '/vault/handbook',
			'javascript:alert(1)' => '',
			'JaVaScRiPt:alert(1)' => '',
			'data:text/html,x' => '',
			'http://example.com/' => '',
			'//example.org/x' => '',
			'/\\example.org' => '',
			'https://example.com/a b' => '',
			"https://example.com/\u{0007}" => '',
		), $this->r( 'safeHref' ) );
		$this->assertSame( array( '/x', 'https://example.com/y', '', '', '' ), $this->r( 'safeSiteUrl' ) );
		$this->assertSame( array( true, true, false, false ), $this->r( 'isVault' ) );
	}

	public function test_the_markdown_subset(): void {
		$b = $this->r( 'blocks' );
		$this->assertSame( array( 'p', 'ul', 'ol', 'table', 'code', 'quote', 'h', 'hr' ), array_column( $b, 't' ) );
		$this->assertCount( 2, $b[0]['lines'], 'a single line break is kept' );
		$this->assertSame( 'strong', $b[0]['lines'][0][0]['t'] );
		$this->assertSame( 3, $b[2]['start'] );
		$this->assertSame( array( '', 'right', 'center' ), $b[3]['align'] );
		$this->assertCount( 2, $b[3]['rows'] );
		$this->assertSame( "[ZDZ_CONFIRM:x]\n**not bold**", $b[4]['v'] );
		$i = $this->r( 'inline' );
		$this->assertSame( array( 'text' ), array_unique( array_column( $i['math'], 't' ) ), '2*3*4 stays math' );
		$this->assertSame( 'text', $i['snake'][0]['t'] );
		$this->assertSame( array( 'text', 'link', 'text' ), array_column( $i['links'], 't' ) );
		$this->assertSame( 'https://example.com/report', $i['links'][1]['href'] );
		$this->assertSame( array( array( 't' => 'text', 'v' => 'click' ) ), $i['badlink'], 'an unsafe link keeps its words as text' );
		$this->assertSame( 'https://example.com/a', $i['auto'][1]['href'], 'a bare address, without its full stop' );
		$this->assertSame( array( 'up', 'down' ), array_column( array_filter( $i['chg'], function ( $x ) { return 'chg' === $x['t']; } ), 'dir' ) );
		$this->assertSame( 'Hello Alex (https://example.com/x) Hi', $this->r( 'plain' ), 'a draft\'s marker is not copied' );
	}

	public function test_the_servers_cards_are_matched_exactly(): void {
		$x = $this->r( 'cards' );
		$kinds = array_column( $x['cards'], 'k' );
		$this->assertSame( array( 'page', 'dm', 'dm', 'post', 'email', 'chips', 'chips', 'confirm' ), $kinds );
		$this->assertSame( '/projects/', $x['cards'][0]['url'] );
		$this->assertSame( array( str_repeat( 'c', 32 ), 'alex', 'Alex Example', 'Hi Alex, the handbook is at /vault/handbook' ),
			array( $x['cards'][1]['id'], $x['cards'][1]['target'], $x['cards'][1]['name'], $x['cards'][1]['body'] ), 'a held message carries its id' );
		$this->assertSame( array( '', 'jordan' ), array( $x['cards'][2]['id'], $x['cards'][2]['target'] ), 'one not held has no id: never sendable' );
		$this->assertSame( 'general', $x['cards'][3]['channel'] );
		$this->assertSame( array( 'Jordan', 'jordan@example.com', 'Your visit', "Hello Jordan,\nSee you Tuesday." ),
			array( $x['cards'][4]['name'], $x['cards'][4]['to'], $x['cards'][4]['subject'], $x['cards'][4]['body'] ) );
		$chips = $x['cards'][5]['items'];
		$this->assertSame( array( 'chart', 'say' ), array_column( $chips, 'kind' ) );
		$this->assertSame( "\u{1F4CA} Chart 1: Sales & visits", $chips[0]['label'], 'entities are read back to their characters' );
		$this->assertSame( array( 'Jan', 'Feb', 'Mar' ), $chips[0]['mini']['p'] );
		$this->assertSame( 'Plan a canvassing route on Example St in 00000 for me', $chips[1]['say'] );
		$apps = $x['cards'][6]['items'];
		$this->assertSame( array( 'route-planner', 'jobs' ), array_column( $apps, 'app' ) );
		$this->assertSame( array( 'mode' => 'plan', 'area' => 'North', 'count' => 3 ), $apps[0]['options'], 'go needs an area and true; a bad count is 3' );
		$this->assertSame( array( 'project' => 12, 'source' => 'chat' ), $apps[1]['options'] );
		$this->assertSame( str_repeat( 'a', 32 ), $x['cards'][7]['id'] );
		$this->assertSame( array( 'invoice', '', 'estimate', 'invoice', 'invoice', '' ), $this->r( 'docColumns' ), 'columns headed Invoice or Estimate' );
		$this->assertSame( array( array( 'kind' => 'invoice', 'number' => '1001', 'text' => '#1001' ) ), $x['docs'] );
		$this->assertStringContainsString( '```' . "\n[ZDZ_CONFIRM:" . str_repeat( 'b', 32 ) . ']', $x['text'], 'a marker inside a code block stays as written' );
		$this->assertStringContainsString( '<b>kept as text</b>', $x['text'], 'any other markup stays visible as text' );
		$this->assertStringNotContainsString( 'evil.example', $x['text'], 'a page of another site is dropped' );
		$this->assertStringNotContainsString( 'hidden note', $x['text'], 'comments never show' );
	}

	public function test_chips_refuse_what_is_not_their_exact_shape(): void {
		$c = $this->r( 'chips' );
		$this->assertSame( array(), $c['bad'], 'a spec that is not base64url, a sentence without "for me", an unknown class' );
		$this->assertCount( 8, $c['many'], 'at most eight buttons' );
		$this->assertSame( 'zch:eyJhIjoxfQ%3D%3D', $this->r( 'chartsPrompt' ) );
		$this->assertSame( array( 'kept' => 'x', 'n' => 2, 'on' => true, 'source' => 'chat' ), $this->r( 'openOptions' ) );
	}

	public function test_the_small_charts_shapes(): void {
		$m = $this->r( 'mini' );
		$this->assertSame( 'bars', $m['bars']['kind'] );
		$this->assertCount( 2, $m['bars']['series'] );
		$this->assertSame( '$3k', $m['bars']['labels'][0]['text'], 'the axis top is the largest stacked total' );
		$this->assertSame( 'line', $m['line']['kind'], 'past 24 periods a line per series' );
		$this->assertSame( 'h', $m['h']['kind'] );
		$this->assertSame( array( 'North', 'South' ), array_column( $m['h']['rows'], 'label' ) );
		$this->assertNull( $m['one'], 'one period draws nothing' );
		$this->assertNull( $m['zero'], 'all zero draws nothing' );
		$this->assertSame( array( '999', '1.5k', '12k', '$2.5M' ), $m['fmt'] );
	}

	public function test_starter_questions_are_weighted(): void {
		$w = $this->r( 'weighted' );
		$this->assertCount( 3, $w['three'] );
		$this->assertNotContains( 'zero', $w['three'], 'a question weighted 0 comes only when nothing else is left' );
		$this->assertSame( 'zero', end( $w['all'] ) );
		$this->assertSame( 4000, $w['heavy'] + $w['light'] );
		$this->assertLessThan( 1200, $w['light'], 'a question weighted a quarter comes first about one time in five' );
		$this->assertGreaterThan( 2 * $w['light'], $w['heavy'] );
	}

	public function test_confirm_cards_turns_and_the_dock(): void {
		$t = $this->r( 'time' );
		$this->assertSame( array( false, false, true, false, false ), $t['expired'], 'the hold\'s life plus a minute; no time means not expired' );
		$this->assertSame( array( 1778000000000, 1778028800000, 0 ), $t['parsed'] );
		$this->assertSame( array( array( 'limit' => 180000, 'extendedFor' => 1 ), array( 'limit' => 300000, 'extendedFor' => 2 ), array( 'limit' => 300000, 'extendedFor' => 2 ) ), $t['poll'] );
		$this->assertSame( array( 'https://example.com/?zim_page=1&zdz_embed=dock&zim_compact=1&c=42', '' ), $t['dock'] );
		$mt = $this->r( 'mail' );
		$this->assertSame( 'mailto:alex%40example.com,jordan%40example.com?subject=Hi&body=Hello%20Alex%20(https%3A%2F%2Fexample.com)', $mt['two']['url'] );
		$this->assertTrue( $mt['two']['hasAddress'] );
		$this->assertSame( 'mailto:?subject=Hi', $mt['none']['url'], 'offered with no address too' );
		$this->assertFalse( $mt['none']['hasAddress'] );
		$this->assertSame( array( array( 'kind' => 'invoice', 'number' => '1001' ), array( 'kind' => 'estimate', 'number' => '2002' ), null, null ), $this->r( 'docRef' ) );
	}
}
