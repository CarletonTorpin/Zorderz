<?php
/**
 * Order forms only on a job that needs one.
 *
 *   - ZJOB_Project_Orders::sold_needs_order(): does what was sold call for a supplier's order? Shop stock does; so
 *     does every Project while the business has named no kind of work as ordered; so does a Project whose kinds are
 *     not known (no job yet, or jobs that name no kind); so does one with a kind the business orders. Only kinds
 *     that are known, with none of them ordered, answer no.
 *   - ZJOB_My_Next: a Project's kinds are read one way (kinds_of()) and the order kinds are asked with one test
 *     (kinds_need_order()), so My next's "order" step and the opened Project agree.
 *   - view() carries the answer as a real true or false, and it is not a permission: the same forms and the same
 *     "may start" go out either way, and a read-only copy keeps the answer the server worked out.
 *   - The Projects script: the row that starts an order is drawn at once only where the job needs one; anywhere else
 *     the panel is one line that opens it. Only an exact false hides anything.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

$zjob_test_orders_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
require_once $zjob_test_orders_inc . 'class-zjob-project-orders.php';
require_once $zjob_test_orders_inc . 'class-zjob-my-next.php';
require_once $zjob_test_orders_inc . 'class-zjob-privacy-view.php';
unset( $zjob_test_orders_inc );

final class JobsOrderFormsWhereNeededTest extends TestCase {

	/** The kinds this made-up business orders materials for. */
	const ORDERED = array( 'alpha', 'beta' );

	const PID = '01HJOBSORDERS0000000000001';

	private $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new ZDZ_Test_Empty_DB();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
		ZJOB_Project_Orders::flush_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
		ZJOB_Project_Orders::flush_memo();
	}

	private function project(): array {
		return array( 'id' => self::PID, 'human_code' => 'PRJ-0001', 'state' => 'open' );
	}

	/** Job rows of the given kinds. */
	private function jobs( array $kinds ): array {
		$out = array();
		foreach ( $kinds as $i => $k ) {
			$out[] = array( 'id' => $i + 1, 'component' => $k, 'status' => 'open' );
		}
		return $out;
	}

	private function needs( array $kinds, ?array $ordered = null ): bool {
		return ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( $kinds ), null === $ordered ? self::ORDERED : $ordered );
	}

	/* ── The rule ─────────────────────────────────────────────────────────── */

	public function test_shop_stock_always_shows_its_forms(): void {
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( ZJOB_Project_Orders::stock_project(), array(), self::ORDERED ), 'the desk is for ordering' );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( array( 'is_stock' => 1 ), $this->jobs( array( 'gamma' ) ), self::ORDERED ), 'whatever kinds its rows carry' );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( array( 'id' => ZJOB_Project_Orders::STOCK_PROJECT ), $this->jobs( array( 'gamma' ) ), self::ORDERED ) );
	}

	public function test_with_no_kind_named_as_ordered_nothing_is_hidden(): void {
		// Core names none: every Project shows its forms, as it did before there was a rule.
		$this->assertSame( array(), ZJOB_My_Next::step_rules()['order'] );
		foreach ( array( array(), array( 'gamma' ), array( 'service' ), array( 'other' ), array( 'gamma', 'delta' ) ) as $kinds ) {
			$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( $kinds ) ), implode( ',', $kinds ) );
			$this->assertTrue( $this->needs( $kinds, array() ) );
		}
	}

	public function test_kinds_that_are_not_known_never_hide_the_forms(): void {
		$this->assertTrue( $this->needs( array() ), 'no job yet: its kinds were never worked out' );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), array( array( 'id' => 4 ) ), self::ORDERED ), 'a row that carries no kind at all' );
		$this->assertTrue( $this->needs( array( '' ) ), 'an empty kind' );
		$this->assertTrue( $this->needs( array( '', '  ' ) ), 'blanks with nothing in them' );
		$this->assertTrue( $this->needs( array( ZJOB_Project_Orders::KIND_CATCH_ALL ) ), 'worked out and nothing found: the catch-all names no kind' );
		$this->assertTrue( $this->needs( array( 'other', 'other' ) ) );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), array( 'not a row', null ), self::ORDERED ), 'rows that are not rows' );
	}

	public function test_known_kinds_with_none_of_them_ordered_get_the_line(): void {
		$this->assertFalse( $this->needs( array( 'gamma' ) ) );
		$this->assertFalse( $this->needs( array( 'gamma', 'delta' ) ) );
		$this->assertFalse( $this->needs( array( 'service' ) ), 'Core\'s own service kind is a known kind' );
		$this->assertFalse( $this->needs( array( 'other', 'gamma' ) ), 'one named kind is enough to know' );
		$this->assertFalse( $this->needs( array( 'alphas' ) ), 'only a whole kind counts' );
		$this->assertFalse( $this->needs( array( 'alpha-beta' ) ) );
	}

	public function test_an_ordered_kind_shows_the_forms(): void {
		$this->assertTrue( $this->needs( array( 'alpha' ) ) );
		$this->assertTrue( $this->needs( array( 'beta' ) ) );
		$this->assertTrue( $this->needs( array( 'gamma', 'alpha' ) ), 'beside a kind that is not ordered' );
		$this->assertTrue( $this->needs( array( ' Alpha ' ) ), 'spaces and capitals around a stored kind do not hide it' );
		$this->assertTrue( $this->needs( array( 'other' ), array( 'other' ) ), 'a business may order for the catch-all too' );
	}

	public function test_the_ordered_kinds_are_the_step_rules_and_nothing_else(): void {
		$GLOBALS['zdz_test_options'][ ZJOB_My_Next::RULES_OPTION ] = array( 'order' => array( 'kinds' => array( 'Alpha', 'beta!' ) ), 'cut' => array( 'kinds' => array( 'gamma' ) ) );
		$this->assertSame( self::ORDERED, ZJOB_My_Next::step_rules()['order'] );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( array( 'beta' ) ) ) );
		$this->assertFalse( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( array( 'gamma' ) ) ), 'a kind prepared in the shop is not thereby ordered' );
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), array() ), 'still never hidden on a guess' );
		// A filter names the kinds the same way the option does.
		unset( $GLOBALS['zdz_test_options'] );
		$GLOBALS['zdz_test_filters'][ ZJOB_My_Next::RULES_OPTION ] = function ( $raw ) {
			return array( 'order' => array( 'kinds' => array( 'delta' ) ) );
		};
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( array( 'delta' ) ) ) );
		$this->assertFalse( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( array( 'alpha' ) ) ) );
		// The panel keeps no list of its own.
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-orders.php' );
		$this->assertStringContainsString( "ZJOB_My_Next::step_rules()['order']", $src );
		$this->assertStringContainsString( 'ZJOB_My_Next::kinds_need_order(', $src );
	}

	/* ── My next asks the same question ───────────────────────────────────── */

	public function test_a_projects_kinds_are_read_one_way(): void {
		$this->assertSame( array(), ZJOB_My_Next::kinds_of( array() ) );
		$this->assertSame(
			array( 'alpha', 'gamma', 'other' ),
			ZJOB_My_Next::kinds_of( array(
				array( 'component' => 'alpha' ),
				array( 'component' => ' GAMMA' ),
				array( 'component' => 'alpha' ),
				array( 'component' => '' ),
				array( 'id' => 9 ),
				array( 'component' => 'other' ),
				array( 'component' => array( 'x' ) ),
				'junk',
			) )
		);
		$this->assertTrue( ZJOB_My_Next::kinds_need_order( array( 'gamma', 'beta' ), self::ORDERED ) );
		$this->assertFalse( ZJOB_My_Next::kinds_need_order( array( 'gamma' ), self::ORDERED ) );
		$this->assertFalse( ZJOB_My_Next::kinds_need_order( array(), self::ORDERED ) );
		$this->assertFalse( ZJOB_My_Next::kinds_need_order( array( 'alpha' ), array() ) );
	}

	public function test_my_next_raises_its_order_step_for_the_same_kinds(): void {
		$owners = array( 'cut' => array( 30 ), 'order' => array( 40 ), 'office' => array( 50 ) );
		$rules  = array( 'order' => self::ORDERED, 'cut' => array() );
		foreach ( array( 'alpha' => true, 'beta' => true, 'gamma' => false, 'delta' => false, 'service' => false ) as $kind => $want ) {
			$jobs  = $this->jobs( array( $kind ) );
			$steps = ZJOB_My_Next::steps( array(
				'id'           => self::PID,
				'kinds'        => ZJOB_My_Next::kinds_of( $jobs ),
				'installed'    => false,
				'seller_ids'   => array( 10 ),
				'seller_codes' => array(),
				'orders'       => array( 'n' => 0, 'ordered' => 0 ),
				'cut'          => 'complete',
				'visit'        => null,
				'visit_past'   => false,
				'money'        => null,
				'confirmed'    => array(),
			), $owners, $rules );
			$this->assertSame( $want, in_array( 'order', array_column( $steps, 'step' ), true ), $kind . ': the order step' );
			$this->assertSame( $want, ZJOB_Project_Orders::sold_needs_order( $this->project(), $jobs, self::ORDERED ), $kind . ': the opened Project gives the same answer' );
		}
		// Where the kinds are not known the step is not raised (a step needs a fact) and the forms still show.
		$this->assertTrue( ZJOB_Project_Orders::sold_needs_order( $this->project(), $this->jobs( array( 'other' ) ), self::ORDERED ) );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-my-next.php' );
		$this->assertStringContainsString( "self::kinds_need_order( \$kinds, (array) ( \$rules['order'] ?? array() ) )", $src, 'the step asks the shared test' );
		$this->assertStringContainsString( "'kinds'        => self::kinds_of( \$jobs ),", $src, 'and reads the kinds the shared way' );
	}

	/* ── The view ─────────────────────────────────────────────────────────── */

	public function test_the_view_carries_a_real_true_or_false_and_it_is_not_a_permission(): void {
		$GLOBALS['zdz_test_options'][ ZJOB_My_Next::RULES_OPTION ] = array( 'order' => array( 'kinds' => self::ORDERED ) );
		$ordered = ZJOB_Project_Orders::view( $this->project(), $this->jobs( array( 'alpha' ) ), 61 );
		$other   = ZJOB_Project_Orders::view( $this->project(), $this->jobs( array( 'gamma' ) ), 61 );
		$unknown = ZJOB_Project_Orders::view( $this->project(), array(), 61 );
		$this->assertTrue( $ordered['sold_needs'] );
		$this->assertFalse( $other['sold_needs'] );
		$this->assertTrue( $unknown['sold_needs'] );
		$this->assertStringContainsString( '"sold_needs":false', (string) wp_json_encode( $other ), 'on the wire it is a real false: the screen hides on nothing else' );
		$this->assertSame( $ordered['forms'], $other['forms'], 'every form is still sent: the line opens the same ones' );
		$this->assertNotEmpty( $other['forms'] );
		$this->assertSame( $ordered['can_start'], $other['can_start'], 'who may start an order is the same on both' );
		$this->assertTrue( ZJOB_Project_Orders::view( ZJOB_Project_Orders::stock_project(), array(), 61 )['sold_needs'], 'the materials desk' );
	}

	public function test_a_read_only_copy_keeps_the_answer_the_server_worked_out(): void {
		// Every can_* and may_* is forced false on that copy. The answer is neither, so it passes as it is.
		$shaped = ZJOB_Privacy_View::shape( array( 'orders' => array( 'sold_needs' => true, 'can_start' => true ) ) );
		$this->assertTrue( $shaped['orders']['sold_needs'] );
		$this->assertFalse( $shaped['orders']['can_start'] );
		$shaped = ZJOB_Privacy_View::shape( array( 'orders' => array( 'sold_needs' => false, 'can_start' => true ) ) );
		$this->assertFalse( $shaped['orders']['sold_needs'] );
	}

	public function test_no_handler_asks_whether_the_job_needs_an_order(): void {
		$src   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-orders.php' );
		$calls = substr_count( $src, 'sold_needs_order(' );
		$this->assertSame( 3, $calls, 'named in the header, defined once, and called once (by view())' );
		$this->assertStringContainsString( "'sold_needs' => self::sold_needs_order( \$project, \$jobs ),", $src );
		$act = substr( $src, (int) strpos( $src, 'public static function act(' ) );
		$act = substr( $act, 0, (int) strpos( $act, 'public static function estimate_lines(' ) );
		$this->assertStringNotContainsString( 'sold_needs', $act, 'starting, saving, confirming and placing an order never read it' );
	}

	/* ── The screen ───────────────────────────────────────────────────────── */

	private static function script(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/assets/js/projects.js' );
	}

	/** The text of one helper inside the page script, by name. */
	private static function fn( string $name ): string {
		$src = self::script();
		$at  = strpos( $src, 'function ' . $name . '(' );
		if ( false === $at ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) strpos( $src, '{', $at ); $i < $len; $i++ ) {
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

	/** Runs $body against the page script's own order-panel helpers and returns what it returned. */
	private function run_js( string $body ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$helpers = '';
		foreach ( array( 'esc', 'word', 'needsOrder', 'canStart', 'formsTucked', 'ordersQuiet', 'moreLine', 'stepsHtml', 'shown', 'paperBtns', 'ordersHtml' ) as $name ) {
			$one = self::fn( $name );
			$this->assertNotSame( '', $one, $name . '() is in the page script' );
			$helpers .= $one . "\n";
		}
		$code = "var window = {}, edits = {}, CFG = {}, state = { startOpen: '' };\nfunction cfg() { return CFG; }\n" . $helpers
			. "function view(o) { var v = { orders: [], forms: [{ key: 'materials', label: 'Materials list' }, { key: 'form-b', label: 'Form B' }], kinds: { 'new': 'New job', addon: 'Add-on' }, can_start: true, gate: '', po: 'PO-1042', stock: false }; Object.keys(o || {}).forEach(function (k) { v[k] = o[k]; }); return v; }\n"
			. "function order(o) { var d = { id: 7, form: 'materials', form_label: 'Materials list', fields: [], part_fields: [], status: 'open', rev: 2, body: { units: [], parts: [], notes: '' }, po: 'PO-1042', steps: {}, can_confirm: {}, can_edit: true, can_order: false, order_why: '' }; Object.keys(o || {}).forEach(function (k) { d[k] = o[k]; }); return d; }\n"
			. "function seen(html) { return { quiet: /class=\"zprj-sec zprj-orders is-quiet\"/.test(html), starts: (html.match(/class=\"zprj-start\"/g) || []).length, forms: (html.match(/<option value=\"(materials|form-b)\"/g) || []).length, lines: (html.match(/data-act=\"more\"/g) || []).length, heading: (/<h4>([^<]*)<\\/h4>/.exec(html) || [])[1] || '', po: /This job&#39;s PO|This job's PO/.test(html), cards: (html.match(/class=\"zprj-order\"/g) || []).length, presses: (html.match(/<button|<select|<input|<a /g) || []).length, closed: /aria-expanded=\"false\"/.test(html), label: (/data-act=\"more\"[^>]*>([^<]*)</.exec(html) || [])[1] || '' }; }\n"   // sentinel:allow a JavaScript RegExp .exec() inside the page-script source the test runs, not a PHP call
			. 'console.log(JSON.stringify((function(){' . $body . '})()));';
		$out = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' 2>&1' );
		$res = json_decode( (string) $out, true );
		$this->assertNotNull( $res, 'node ran the helpers: ' . substr( (string) $out, 0, 400 ) );
		return $res;
	}

	public function test_a_job_with_nothing_to_order_shows_one_line_and_a_press_opens_what_was_there(): void {
		$r = $this->run_js( "var v = view({ sold_needs: false }); var a = seen(ordersHtml(v, false, 'P1')); state.startOpen = 'P1'; var b = seen(ordersHtml(v, false, 'P1')); return { a: a, b: b };" );
		$this->assertTrue( $r['a']['quiet'], 'the panel is the line only' );
		$this->assertSame( 0, $r['a']['starts'], 'no row that starts an order' );
		$this->assertSame( 0, $r['a']['forms'] );
		$this->assertSame( 1, $r['a']['lines'] );
		$this->assertSame( 'Order something for this job', $r['a']['label'] );
		$this->assertSame( '', $r['a']['heading'], 'no heading' );
		$this->assertFalse( $r['a']['po'], 'no PO line' );
		$this->assertSame( 1, $r['a']['presses'], 'one thing to press in the panel' );
		$this->assertTrue( $r['a']['closed'], 'and it says it is closed' );
		// Pressed on this Project: everything that was there, and no line.
		$this->assertFalse( $r['b']['quiet'] );
		$this->assertSame( 1, $r['b']['starts'] );
		$this->assertSame( 2, $r['b']['forms'], 'the same forms' );
		$this->assertSame( 0, $r['b']['lines'] );
		$this->assertSame( 'Material orders', $r['b']['heading'] );
		$this->assertTrue( $r['b']['po'] );
	}

	public function test_the_line_is_for_the_project_it_was_pressed_on(): void {
		$r = $this->run_js( "var v = view({ sold_needs: false }); state.startOpen = 'P1'; return { same: seen(ordersHtml(v, false, 'P1')), other: seen(ordersHtml(v, false, 'P2')), num: seen(ordersHtml(v, false, 1)) };" );
		$this->assertSame( 1, $r['same']['starts'] );
		$this->assertSame( 0, $r['other']['starts'], 'another Project starts with the line again' );
		$this->assertSame( 1, $r['other']['lines'] );
		$this->assertSame( 1, $r['num']['lines'] );
		$src = self::script();
		$this->assertStringContainsString( "if (state.startOpen !== String(p.id)) { state.startOpen = ''; }", $src, 'opening another Project forgets the press' );
		$this->assertStringContainsString( "state.startOpen = String(sec.getAttribute('data-project') || '');", $src, 'the press is kept for that Project' );
		$more = substr( $src, (int) strpos( $src, "if (act === 'more') {" ), 700 );
		$this->assertStringNotContainsString( 'post(', substr( $more, 0, (int) strpos( $more, 'return;' ) ), 'a press asks the server nothing' );
	}

	public function test_a_job_that_needs_an_order_shows_its_forms_as_before(): void {
		$r = $this->run_js( "return seen(ordersHtml(view({ sold_needs: true }), false, 'P1'));" );
		$this->assertSame( 1, $r['starts'] );
		$this->assertSame( 2, $r['forms'] );
		$this->assertSame( 0, $r['lines'] );
		$this->assertSame( 'Material orders', $r['heading'] );
		$this->assertTrue( $r['po'] );
		$this->assertFalse( $r['quiet'] );
	}

	public function test_only_an_exact_false_hides_anything(): void {
		$r = $this->run_js( "var out = {}; out.missing = seen(ordersHtml(view({}), false, 'P1')); [['zero', 0], ['text0', '0'], ['empty', ''], ['nul', null], ['textfalse', 'false']].forEach(function (c) { out[c[0]] = seen(ordersHtml(view({ sold_needs: c[1] }), false, 'P1')); }); return out;" );
		foreach ( array( 'missing', 'zero', 'text0', 'empty', 'nul', 'textfalse' ) as $k ) {
			$this->assertSame( 1, $r[ $k ]['starts'], $k . ': the forms show' );
			$this->assertSame( 0, $r[ $k ]['lines'], $k );
		}
	}

	public function test_an_order_already_on_the_job_still_shows_with_the_forms_behind_the_line(): void {
		$r = $this->run_js( "return seen(ordersHtml(view({ sold_needs: false, orders: [order({})] }), false, 'P1'));" );
		$this->assertFalse( $r['quiet'] );
		$this->assertSame( 'Material orders', $r['heading'] );
		$this->assertTrue( $r['po'] );
		$this->assertSame( 1, $r['cards'], 'the order is a fact about the job: it stays' );
		$this->assertSame( 0, $r['starts'] );
		$this->assertSame( 1, $r['lines'], 'the line where the start row was' );
	}

	public function test_people_and_places_the_line_is_not_for(): void {
		$r = $this->run_js( "return { cannot: seen(ordersHtml(view({ sold_needs: false, can_start: false }), false, 'P1')), readonly: seen(ordersHtml(view({ sold_needs: false }), true, 'P1')), noforms: seen(ordersHtml(view({ sold_needs: false, forms: [] }), false, 'P1')), stock: seen(ordersHtml(view({ sold_needs: false, stock: true, po: '' }), false, 'stock')), none: ordersHtml(null, false, 'P1') };" );
		foreach ( array( 'cannot', 'readonly', 'noforms' ) as $k ) {
			$this->assertSame( 0, $r[ $k ]['lines'], $k . ': no line' );
			$this->assertSame( 0, $r[ $k ]['starts'], $k . ': and no start row, as before' );
			$this->assertSame( 'Material orders', $r[ $k ]['heading'], $k . ': what they saw before' );
			$this->assertTrue( $r[ $k ]['po'], $k );
			$this->assertFalse( $r[ $k ]['quiet'], $k );
		}
		$this->assertSame( 1, $r['stock']['starts'], 'shop stock always shows its start row, whatever the answer says' );
		$this->assertSame( 0, $r['stock']['lines'] );
		$this->assertSame( 'Shop stock orders', $r['stock']['heading'] );
		$this->assertSame( '', $r['none'], 'no order panel at all: nothing is drawn' );
	}

	public function test_the_lines_words_come_from_the_server_and_are_escaped(): void {
		$r = $this->run_js( "CFG = { words: { orderMore: 'Order <b>more</b>' } }; var html = ordersHtml(view({ sold_needs: false }), false, 'P1'); return { html: html };" );
		$this->assertStringContainsString( 'Order &lt;b&gt;more&lt;/b&gt;', $r['html'] );
		$this->assertStringNotContainsString( '<b>', $r['html'] );
		$app = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-projects-app.php' );
		$this->assertSame( 1, preg_match( "/'orderMore'\s+=> __\( 'Order something for this job', 'zorderz' \),/", $app ) );
	}

	public function test_the_line_is_44px_to_press_at_the_themes_smallest_role_in_secondary_ink(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/assets/css/projects.css' );
		$this->assertSame( 1, preg_match( '/\.zprj-ord-morebtn\s*\{([^}]*)\}/', $css, $m ) );
		$this->assertStringContainsString( 'min-height: 44px', $m[1] );
		$this->assertStringContainsString( 'font-size: var(--zdz-type-meta, 14px)', $m[1], 'a role, and 14px or more without one' );
		$this->assertStringContainsString( 'color: var(--sys-text-sec)', $m[1] );
		$this->assertStringContainsString( '.zprj-ord-morebtn:focus-visible', $css );
	}
}
