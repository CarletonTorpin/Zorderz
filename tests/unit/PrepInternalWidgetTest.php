<?php
/**
 * What Prep's screen shows for the shop's own jobs, from the shipped widget.js run in Node on a stand-in page:
 *
 *   - a page cut from a stock sheet is named by the sheet, drawn with no edge margin, and is "long", not "cut to";
 *   - an internal order's card says it is one, by which code, what for and who for, with everything typed escaped;
 *     a manager's card says who has it and can be assigned; anyone else's says only when it is theirs;
 *   - the shop's own pinned jobs all show at the top of the list; the "Show more" fold is for the jobs after them.
 *
 * The requests the screen posts are held to the server's handlers by AjaxActionContractTest.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class PrepInternalWidgetTest extends TestCase {

	/** @var array|null what the cases printed, read once. */
	private static $out = null;

	private function cases(): array {
		if ( null === self::$out ) {
			$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
			if ( '' === $node ) {
				$this->markTestSkipped( 'node is not available' );
			}
			$root      = dirname( __DIR__, 2 );
			$json      = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/support/prep-widget-cases.js' ) . ' ' . escapeshellarg( $root . '/zorderz-apps/apps/prep/assets/js/widget.js' ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d
			self::$out = json_decode( $json, true );
			$this->assertIsArray( self::$out, 'the widget cases did not run: ' . substr( $json, 0, 400 ) );
		}
		return self::$out;
	}

	public function test_a_sheet_page_is_named_and_drawn_as_a_sheet(): void {
		$o = $this->cases();
		$this->assertSame( array( '48" GREY', '24" x 32" SHEET · GREY' ), $o['labels'] );
		$this->assertStringContainsString( 'margin', $o['svg']['roll'], 'a roll keeps its edge margin' );
		$this->assertStringContainsString( 'Cut 40"', $o['svg']['roll'] );
		$this->assertStringNotContainsString( 'margin', $o['svg']['sheet'], 'a stock sheet has none to keep off' );
		$this->assertStringContainsString( '½" margin', $o['svg']['roll'], 'the default edge margin, as a fraction' );
		$this->assertStringContainsString( '1" margin', $o['margins']['one'], 'a margin set to 1" is printed as 1"' );
		$this->assertStringNotContainsString( 'margin', $o['margins']['zero'], 'a margin set to 0 stays 0: no band, no label' );
		$this->assertSame( '¼" margin', $o['margins']['quarter'] );
		$this->assertSame( 0.5, $o['margins']['unset'], 'a page with no margin of its own and no setting: ½"' );
		$this->assertStringContainsString( '32" long', $o['svg']['sheet'] );
		$this->assertStringNotContainsString( 'Cut 32', $o['svg']['sheet'], 'a sheet is not pulled to a length' );
	}

	public function test_a_shop_job_is_named_by_its_own_reference(): void {
		$o = $this->cases();
		$this->assertSame( array( 'Internal order INT-GEN-26-001', 'Internal order', 'Remake #7', '' ), $o['refs'] );
		$this->assertSame( array( true, true, false, false ), $o['shop'], 'a remake and an internal order are the shop\'s own; a customer job is not' );
		$this->assertSame( array( '1.5', '2.26', '0', '' ), $o['hours'] );
	}

	public function test_an_internal_orders_card_says_what_it_is_and_escapes_what_was_typed(): void {
		$o    = $this->cases();
		$card = $o['card']['plain'];
		$this->assertSame( 'button', $card['tag'], 'someone who is not a manager gets the card alone: nothing to assign with' );
		$this->assertSame( 'remake:4', $card['attrs']['data-job'] );
		$this->assertStringContainsString( 'zprep-w-atc-internal', $card['cls'] );
		$this->assertStringContainsString( 'Jig &lt;covers&gt;', $card['html'] );
		$this->assertStringNotContainsString( '<covers>', $card['html'] );
		$this->assertStringContainsString( 'Internal · INT-GEN-26-004 · Internal use · for the bench crew', $card['html'] );
		$this->assertStringContainsString( '>Internal order<', $card['html'], 'told from a customer\'s job at a glance' );
		$this->assertStringContainsString( '6 pieces', $card['html'] );
		$this->assertStringNotContainsString( 'Assigned', $card['html'] );
		$this->assertStringNotContainsString( 'Unassigned', $card['html'] );

		$this->assertStringContainsString( 'Assigned to you', $o['card']['mine']['html'], 'a named one is theirs: they are sent no one else\'s' );
		$this->assertStringNotContainsString( 'Jordan', $o['card']['mine']['html'] );
		$this->assertStringContainsString( '✓ Cut', $o['card']['cut']['html'] );
		$this->assertStringContainsString( '1 piece<', $o['card']['cut']['html'] );

		$free = $o['managed']['free'];
		$this->assertSame( array( 'div', 'zprep-w-atc-wrap' ), array( $free['tag'], $free['cls'] ) );
		$this->assertSame( array( 'button', 'button' ), array_column( $free['kids'], 'tag' ) );
		$this->assertSame( 'Assign', $free['kids'][1]['text'] );
		$this->assertStringContainsString( 'Unassigned', $free['kids'][0]['html'] );
		$given = $o['managed']['given'];
		$this->assertSame( 'Reassign', $given['kids'][1]['text'] );
		$this->assertStringContainsString( '→ Jordan', $given['kids'][0]['html'], 'a manager sees who has it' );

		$this->assertSame( 'a &quot;b&quot; &lt;c&gt; &amp; &#39;d&#39;', $o['attr'], 'a typed value inside an attribute' );
		$this->assertSame(
			array( '&lt;b&gt;Fix&lt;/b&gt; · for a &amp; b · 24" × 32" sheets · 1 piece', 'Shop fixtures · 60" roll · 6 pieces', 'roll · 0 pieces' ),
			$o['line'],
			'the line under an order in the manager\'s window'
		);
	}

	public function test_the_shops_own_jobs_sit_above_the_fold(): void {
		$o = $this->cases();
		$this->assertSame( 3, $o['visible'], '(the list shows three jobs before "Show more")' );
		$mixed = $o['list']['mixed'];
		$this->assertSame( array( 'remake:9', 'remake:8', 'lead:1', 'lead:2', 'lead:3' ), $mixed['shown'], 'both pinned jobs, then the first three of the rest' );
		$this->assertSame( 'Show 3 more', $mixed['more'] );
		$this->assertSame( array( 'remake:9', 'remake:8', 'lead:1', 'lead:2', 'lead:3', 'lead:4', 'lead:5', 'remake:2' ), $mixed['all'], 'a manager\'s remake stays after the customer jobs' );
		$this->assertSame( 'none', $mixed['moreAfter'] );

		$many = $o['list']['manyPinned'];
		$this->assertCount( 6, $many['shown'], 'pinned jobs are never folded away, however many' );
		$this->assertSame( '', $many['more'] );

		$this->assertSame( array( array(), 'No approved jobs right now.' ), array( $o['list']['none']['shown'], $o['list']['none']['empty'] ) );
	}
}
