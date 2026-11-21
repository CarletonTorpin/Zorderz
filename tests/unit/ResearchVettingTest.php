<?php
/**
 * When a fact found on the web may be called confirmed (ZANA_Web_Vetting): sources are counted by owner, a company
 * repeating itself is one self-report, a checked disagreement always shows, a quote must be on the page the server
 * read, and the block under an answer is written by the server from the verdicts alone.
 *
 * Neutral data only: Example Co and sites under the reserved .example ending.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-research-settings.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-web-vetting.php';

final class ResearchVettingTest extends TestCase {

	const CLAIM = 'Example Co franchises average $480,000 in yearly revenue from 400 customers';

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	private function claim(): array {
		return ZANA_Web_Vetting::parse_claims( json_encode( array( 'claims' => array( array( 'claim' => self::CLAIM, 'subject' => 'Example Co' ) ) ) ) )[0];
	}

	private function ev( string $url, string $status ): array {
		return array( 'url' => $url, 'status' => $status );
	}

	/* ------------------------------------------------------------------ whose site */

	public function test_a_sites_own_domain(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertSame( 'tradeweekly.example', $V::registrable_domain( 'https://www.tradeweekly.example/a/b?c=1' ) );
		$this->assertSame( 'tradeweekly.example', $V::registrable_domain( 'news.tradeweekly.example' ) );
		$this->assertSame( 'example.co.uk', $V::registrable_domain( 'https://www.news.example.co.uk/x' ) );
		$this->assertSame( 'example.com.au', $V::registrable_domain( 'https://example.com.au/' ) );
		$this->assertSame( 'agency.zz.gov', $V::registrable_domain( 'https://search.agency.zz.gov/lookup' ), 'a government agency under a two-letter region is its own site' );
		$this->assertSame( 'two.example', $V::registrable_domain( 'two.example' ) );
	}

	public function test_what_names_no_publisher_has_no_domain(): void {
		$V = 'ZANA_Web_Vetting';
		foreach ( array( 'javascript:alert(1)', 'ftp://files.example/a', 'https://localhost/x', 'https://192.0.2.7/x', 'https://[2001:db8::1]/x', '', 'not a url', 'https://' ) as $bad ) {
			$this->assertSame( '', $V::registrable_domain( $bad ), $bad );
			$this->assertSame( '', $V::host_of( $bad ), $bad );
		}
		$this->assertSame( '', $V::registrable_domain( '192.0.2.7' ) );
		$this->assertSame( '', $V::registrable_domain( 'under_score.example' ) );
	}

	public function test_one_brands_sister_sites_are_one_owner(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertTrue( $V::same_owner( 'https://exampleco.example/a', 'https://www.exampleco.example/b' ) );
		$this->assertTrue( $V::same_owner( 'exampleco.example', 'examplecofranchise.example' ), 'one name holds the other' );
		$this->assertFalse( $V::same_owner( 'tradeweekly.example', 'citygazette.example' ) );
		$this->assertFalse( $V::same_owner( 'trade.example', 'tradeweekly.example' ), 'a short name is not a brand: it is part of many words' );
		$this->assertFalse( $V::same_owner( '', 'tradeweekly.example' ) );
	}

	public function test_an_administrator_may_group_sites_under_one_owner_and_core_groups_none(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertSame( array(), $V::owner_groups() );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'owner_groups' => "tradeweekly.example, citygazette.example\nalone.example\n" );
		$this->assertSame( array( array( 'tradeweekly.example', 'citygazette.example' ) ), $V::owner_groups(), 'a line with one name is not a group' );
		$this->assertTrue( $V::same_owner( 'tradeweekly.example', 'https://citygazette.example/x' ) );
		$this->assertFalse( $V::same_owner( 'tradeweekly.example', 'alone.example' ) );
	}

	public function test_a_site_named_after_the_subject_is_the_subjects_own(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertTrue( $V::is_subject_owned( 'exampleco.example', 'Example Co' ) );
		$this->assertTrue( $V::is_subject_owned( 'examplecofranchise.example', 'Example Co' ) );
		$this->assertFalse( $V::is_subject_owned( 'tradeweekly.example', 'Example Co' ) );
		$this->assertFalse( $V::is_subject_owned( 'globalservices.example', 'Example Co Global Services' ), 'words like "services" say nothing about who owns a site' );
		$this->assertTrue( $V::is_subject_owned( 'widgetmakers.example', 'The Widgetmakers Guild' ) );
		$this->assertFalse( $V::is_subject_owned( 'tradeweekly.example', '' ) );
	}

	/* ------------------------------------------------------------------ words and figures */

	public function test_the_figures_a_claim_rests_on(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertSame( array( '480000', '400' ), $V::extract_figures( self::CLAIM ) );
		$this->assertSame( array( '31%', '2.5', '1200' ), $V::extract_figures( 'Margins of 31% (2.5 points up) on $1,200.00 a job' ) );
		$this->assertSame( array( '12%' ), $V::extract_figures( 'about 12 percent of homes' ) );
		$this->assertSame( array(), $V::extract_figures( 'Founded in 2011 with 2 sites' ), 'a bare year and a single digit are context' );
		$this->assertSame( array( '2011' ), $V::extract_figures( 'It paid $2011 in fees' ), 'money is a figure whatever it looks like' );
		$this->assertSame( array( '1500' ), $V::extract_figures( '1,500 units and 1500 units' ) );
		$this->assertSame( array( '7' ), $V::extract_figures( 'a fee of $7' ) );
	}

	public function test_a_figure_is_found_whole_never_inside_a_longer_number(): void {
		$V = 'ZANA_Web_Vetting';
		$n = $V::normalize( 'Revenue was $480,000 last year; 14,800 visits; growth 31 percent; 3.5 crews.' );
		$this->assertTrue( $V::figure_on( '480000', $n ) );
		$this->assertFalse( $V::figure_on( '4800', $n ) );
		$this->assertFalse( $V::figure_on( '800', $n ) );
		$this->assertTrue( $V::figure_on( '31%', $n ) );
		$this->assertFalse( $V::figure_on( '31%', $V::normalize( 'It had 31 crews' ) ), 'a percentage needs its sign or the word' );
		$this->assertTrue( $V::figure_on( '3.5', $n ) );
		$this->assertFalse( $V::figure_on( '5', $n ) );
		$this->assertTrue( $V::figure_on( '1200', $V::normalize( 'a job costs $1,200.00' ) ) );
	}

	public function test_a_quote_must_be_on_the_page_and_be_a_real_quote(): void {
		$V    = 'ZANA_Web_Vetting';
		$page = 'About us. Example Co franchises reported average yearly revenue of $480,000 — from roughly 400 customers each — in the latest filing.';
		$this->assertTrue( $V::quote_on_page( 'average yearly revenue of $480,000 - from roughly 400 customers', $page ), 'dashes, case and separators do not matter' );
		$this->assertTrue( $V::quote_on_page( 'AVERAGE YEARLY REVENUE OF $480000', $page ) );
		$this->assertFalse( $V::quote_on_page( 'average yearly revenue of $520,000', $page ) );
		$this->assertFalse( $V::quote_on_page( 'About us.', $page ), 'too short to be a quote' );
		$this->assertFalse( $V::quote_on_page( '', $page ) );
	}

	public function test_figures_stand_in_for_a_reworded_quote_only_when_they_stand_out_and_sit_together(): void {
		$V    = 'ZANA_Web_Vetting';
		$page = str_repeat( 'Filler text about other things. ', 30 ) . 'In its filing Example Co says a franchise averages $480,000 in revenue and serves 400 customers a year. ' . str_repeat( 'More filler. ', 30 );
		$this->assertTrue( $V::figures_on_page( array( '480000', '400' ), self::CLAIM, $page, 'Example Co' ) );
		$this->assertFalse( $V::figures_on_page( array( '480000', '900' ), self::CLAIM, $page, 'Example Co' ), 'every figure of the claim must be there' );
		$this->assertFalse( $V::figures_on_page( array( '480000', '400' ), self::CLAIM, $page, 'Another Business' ), 'the page must be about the same subject there' );
		$this->assertFalse( $V::figures_on_page( array( '480000' ), 'Unrelated wording entirely', $page ), 'one of the claim\'s words must be near the figure' );
		$this->assertFalse( $V::figures_on_page( array( '12' ), 'It has 12 crews', 'On 12 March the 12 crews met.' ), 'a small bare number is on any page' );
		$this->assertFalse( $V::figures_on_page( array(), self::CLAIM, $page ) );
		$far = 'Example Co franchise revenue $480,000. ' . str_repeat( 'x ', 600 ) . ' 400 customers.';
		$this->assertFalse( $V::figures_on_page( array( '480000', '400' ), self::CLAIM, $far, 'Example Co' ), 'figures far apart are not one statement' );
	}

	/* ------------------------------------------------------------------ what a model listed */

	public function test_claims_are_read_strictly_and_their_figures_come_from_their_own_words(): void {
		$V   = 'ZANA_Web_Vetting';
		$raw = "Here:\n```json\n" . json_encode( array( 'claims' => array(
			array( 'claim' => self::CLAIM, 'subject' => 'Example Co', 'figures' => array( '999' ), 'sources' => array(
				array( 'url' => 'https://tradeweekly.example/report).', 'quote' => 'q' ),
				array( 'url' => 'javascript:alert(1)', 'quote' => 'x' ),
				'https://citygazette.example/news',
				array( 'url' => array( 'not', 'a', 'string' ) ),
			) ),
			array( 'claim' => 'short' ),
			'not a claim',
			array( 'claim' => array( 'nested' ) ),
		) ) ) . "\n```";
		$c = $V::parse_claims( $raw );
		$this->assertCount( 1, $c );
		$this->assertSame( 1, $c[0]['id'] );
		$this->assertSame( array( '480000', '400' ), $c[0]['figures'], 'never the figures the model handed over' );
		$this->assertSame( array( 'https://tradeweekly.example/report', 'https://citygazette.example/news' ), array_column( $c[0]['sources'], 'url' ) );
		$this->assertSame( 'Example Co', $c[0]['subject'] );
	}

	public function test_claims_are_capped(): void {
		$many = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$many[] = array( 'claim' => 'Claim number ' . $i . ' says $' . ( 100 + $i ) );
		}
		$this->assertCount( 6, ZANA_Web_Vetting::parse_claims( json_encode( $many ) ), 'Core checks six a run' );
		$this->assertCount( 3, ZANA_Web_Vetting::parse_claims( json_encode( $many ), 3 ) );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'max_claims' => 500 );
		$this->assertSame( 12, ZANA_Web_Vetting::max_claims(), 'a setting past the limit is read as the limit' );
	}

	public function test_an_empty_list_is_nothing_to_check_and_a_wrong_shape_is_a_failed_listing(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertTrue( $V::is_empty_claim_list( '[]' ) );
		$this->assertTrue( $V::is_empty_claim_list( "```json\n{\"claims\":[]}\n```" ) );
		$this->assertFalse( $V::is_empty_claim_list( '{"facts":["a"]}' ) );
		$this->assertFalse( $V::is_empty_claim_list( '{"claims":["a claim"]}' ) );
		$this->assertFalse( $V::is_empty_claim_list( 'no json here' ) );
		$this->assertFalse( $V::is_empty_claim_list( '{"claims":[],"note":"x"}' ) );
	}

	public function test_evidence_keeps_web_addresses_only_and_a_stance_it_knows(): void {
		$ev = ZANA_Web_Vetting::parse_evidence( json_encode( array(
			array( 'url' => 'https://tradeweekly.example/a', 'quote' => 'q', 'value' => '$480,000', 'stance' => 'CONTRADICTS' ),
			array( 'url' => 'file:///etc/passwd', 'quote' => 'q' ),
			array( 'url' => 'https://citygazette.example/b', 'stance' => 'maybe' ),
			'https://plain.example/c',
		) ) );
		$this->assertCount( 2, $ev );
		$this->assertSame( 'contradicts', $ev[0]['stance'] );
		$this->assertSame( 'supports', $ev[1]['stance'], 'anything else reads as supports: the page decides, not the label' );
		$this->assertSame( array(), ZANA_Web_Vetting::parse_evidence( 'I found nothing.' ) );
		$many = array_fill( 0, 20, array( 'url' => 'https://tradeweekly.example/a' ) );
		$this->assertCount( 8, ZANA_Web_Vetting::parse_evidence( json_encode( $many ) ) );
	}

	/* ------------------------------------------------------------------ one piece of evidence */

	public function test_evidence_is_judged_from_the_page_the_server_read(): void {
		$V     = 'ZANA_Web_Vetting';
		$claim = $this->claim();
		$page  = 'Example Co franchises average $480,000 in yearly revenue, each from about 400 customers, the company said.';
		$quote = 'Example Co franchises average $480,000 in yearly revenue, each from about 400 customers';

		$a = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => $quote, 'stance' => 'supports' ), $page );
		$this->assertSame( 'supports', $a['status'] );
		$this->assertSame( 'tradeweekly.example', $a['owner'] );

		$a = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => $quote, 'stance' => 'contradicts' ), $page );
		$this->assertSame( 'supports', $a['status'], 'the page carries the claim\'s figures, whatever the model called it' );

		$a = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => $quote ), null );
		$this->assertSame( 'unreadable', $a['status'] );
		$a = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => $quote ), "  \n " );
		$this->assertSame( 'unreadable', $a['status'] );

		$a = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => $quote ), 'A page about something else entirely, with no such figures on it at all.' );
		$this->assertSame( 'not_on_page', $a['status'], 'a quote the server cannot find is not evidence' );
	}

	public function test_a_real_quote_with_another_figure_is_a_disagreement_only_when_it_is_offered_as_one(): void {
		$V     = 'ZANA_Web_Vetting';
		$claim = $this->claim();
		$page  = 'The company itself states that franchises average $520,000 in yearly revenue from 410 customers.';
		$quote = 'franchises average $520,000 in yearly revenue from 410 customers';
		$a     = $V::assess( $claim, array( 'url' => 'https://exampleco.example/x', 'quote' => $quote, 'value' => '$520,000', 'stance' => 'contradicts' ), $page );
		$this->assertSame( 'contradicts', $a['status'] );
		$a     = $V::assess( $claim, array( 'url' => 'https://exampleco.example/x', 'quote' => $quote, 'stance' => 'supports' ), $page );
		$this->assertSame( 'not_on_page', $a['status'], 'offered as support, but the claim\'s figures are not in it' );
	}

	public function test_a_reworded_quote_counts_only_by_the_claims_exact_figures(): void {
		$V     = 'ZANA_Web_Vetting';
		$claim = $this->claim();
		$page  = 'Summary: at Example Co the average franchise revenue is $480,000 yearly, and each serves 400 customers.';
		$a     = $V::assess( $claim, array( 'url' => 'https://citygazette.example/a', 'quote' => 'They make about half a million a year.' ), $page );
		$this->assertSame( 'supports', $a['status'] );
		$this->assertSame( 'figures', $a['judged'] );
	}

	public function test_a_claim_without_figures_rests_on_the_quote_and_says_a_model_judged_it(): void {
		$V     = 'ZANA_Web_Vetting';
		$claim = $V::parse_claims( json_encode( array( array( 'claim' => 'Example Co requires a licence to operate in the region', 'subject' => 'Example Co' ) ) ) )[0];
		$page  = 'The regulator confirmed that Example Co requires a licence to operate in the region as of this year.';
		$a     = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => 'Example Co requires a licence to operate in the region', 'stance' => 'supports' ), $page );
		$this->assertSame( 'supports', $a['status'] );
		$this->assertSame( 'model', $a['judged'] );
		$a     = $V::assess( $claim, array( 'url' => 'https://tradeweekly.example/a', 'quote' => 'Example Co requires a licence to operate in the region', 'stance' => 'contradicts' ), $page );
		$this->assertSame( 'contradicts', $a['status'] );
	}

	/* ------------------------------------------------------------------ the verdict */

	public function test_one_source_is_never_confirmation(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array( $this->ev( 'https://tradeweekly.example/a', 'supports' ) ) );
		$this->assertSame( $V::V_SINGLE, $v['verdict'] );
		$this->assertSame( 1, $v['independent'] );
		$this->assertFalse( $v['self_only'] );
	}

	public function test_two_pages_of_one_site_are_one_source(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array(
			$this->ev( 'https://tradeweekly.example/a', 'supports' ),
			$this->ev( 'https://www.tradeweekly.example/b', 'supports' ),
			$this->ev( 'https://archive.tradeweekly.example/c', 'supports' ),
		) );
		$this->assertSame( $V::V_SINGLE, $v['verdict'] );
		$this->assertSame( array( 'tradeweekly.example' => 'https://tradeweekly.example/a' ), $v['supporting'] );
	}

	public function test_two_independent_owners_confirm(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array( $this->ev( 'https://tradeweekly.example/a', 'supports' ), $this->ev( 'https://citygazette.example/b', 'supports' ) ) );
		$this->assertSame( $V::V_VERIFIED, $v['verdict'] );
		$this->assertSame( 2, $v['independent'] );
	}

	public function test_a_company_repeating_itself_is_one_self_report(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array( $this->ev( 'https://exampleco.example/a', 'supports' ), $this->ev( 'https://examplecofranchise.example/b', 'supports' ) ) );
		$this->assertSame( $V::V_SINGLE, $v['verdict'] );
		$this->assertTrue( $v['self_only'] );
		$v = $V::verdict( $this->claim(), array( $this->ev( 'https://exampleco.example/a', 'supports' ), $this->ev( 'https://tradeweekly.example/b', 'supports' ) ) );
		$this->assertSame( $V::V_VERIFIED, $v['verdict'], 'the subject and one outsider are two owners, one of them independent' );
	}

	public function test_any_checked_disagreement_shows_whatever_else_agrees(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array(
			$this->ev( 'https://tradeweekly.example/a', 'supports' ),
			$this->ev( 'https://citygazette.example/b', 'supports' ),
			$this->ev( 'https://exampleco.example/c', 'contradicts' ),
		) );
		$this->assertSame( $V::V_CONTESTED, $v['verdict'] );
		$this->assertArrayHasKey( 'exampleco.example', $v['contradicting'] );
	}

	public function test_pages_that_were_silent_or_never_read_confirm_nothing(): void {
		$V = 'ZANA_Web_Vetting';
		$v = $V::verdict( $this->claim(), array(
			$this->ev( 'https://tradeweekly.example/a', 'not_on_page' ),
			$this->ev( 'https://citygazette.example/b', 'unreadable' ),
			array( 'url' => 'https://regionalpost.example/c' ),
		) );
		$this->assertSame( $V::V_UNCONFIRMED, $v['verdict'] );
		$this->assertSame( array( 'total' => 3, 'supports' => 0, 'contradicts' => 0, 'not_on_page' => 1, 'unreadable' => 1, 'unread' => 1 ), $v['pages'] );
		$this->assertSame( $V::V_UNCONFIRMED, $V::verdict( $this->claim(), array() )['verdict'] );
	}

	public function test_a_business_may_ask_for_more_sources_never_fewer_than_two(): void {
		$V  = 'ZANA_Web_Vetting';
		$ev = array( $this->ev( 'https://tradeweekly.example/a', 'supports' ), $this->ev( 'https://citygazette.example/b', 'supports' ) );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'min_sources' => 3 );
		$this->assertSame( 3, $V::min_sources() );
		$this->assertSame( $V::V_THIN, $V::verdict( $this->claim(), $ev )['verdict'], 'independent, but fewer than the business asks for' );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'min_sources' => 1 );
		$this->assertSame( 2, $V::min_sources() );
		$this->assertSame( $V::V_SINGLE, $V::verdict( $this->claim(), array( $ev[0] ), 1 )['verdict'], 'and never by the caller either' );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'min_sources' => 99 );
		$this->assertSame( 5, $V::min_sources() );
	}

	public function test_sites_an_administrator_grouped_count_once(): void {
		$V = 'ZANA_Web_Vetting';
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'owner_groups' => 'tradeweekly.example, citygazette.example' );
		$v = $V::verdict( $this->claim(), array( $this->ev( 'https://tradeweekly.example/a', 'supports' ), $this->ev( 'https://citygazette.example/b', 'supports' ) ) );
		$this->assertSame( $V::V_SINGLE, $v['verdict'] );
	}

	/* ------------------------------------------------------------------ the answer and the block */

	private function checked( string $verdict, array $ev ): array {
		$c            = $this->claim();
		$c['verdict'] = $verdict;
		$c['result']  = ZANA_Web_Vetting::verdict( $c, $ev );
		$c['evidence'] = $ev;
		return $c;
	}

	public function test_a_figure_stated_as_settled_is_flagged_and_a_caveat_counts_where_a_reader_sees_it(): void {
		$V = 'ZANA_Web_Vetting';
		$c = array( $this->checked( $V::V_SINGLE, array( $this->ev( 'https://tradeweekly.example/a', 'supports' ) ) ) );
		$this->assertSame( array( 1 ), $V::unhedged( 'Franchises average $480,000 a year.', $c ) );
		$this->assertSame( array(), $V::unhedged( 'Franchises reportedly average $480,000 a year.', $c ) );
		$this->assertSame( array(), $V::unhedged( 'According to one trade site, franchises average $480,000 a year.', $c ) );
		$this->assertSame( array(), $V::unhedged( "Revenue (unconfirmed, one source only):\n- Average: $480,000\n- Customers: 400", $c ), 'the sentence a list hangs from' );
		$this->assertSame( array(), $V::unhedged( "## Revenue (CONTESTED)\n\nThe average is $480,000.", $c ), 'the section heading' );
		$this->assertSame( array(), $V::unhedged( "Figures below are unverified.\n\n| Item | Value |\n|---|---|\n| Average | $480,000 |", $c ), 'the paragraph above a table' );
		$this->assertSame( array( 1 ), $V::unhedged( "## Revenue\n\n| Item | Value |\n|---|---|\n| Average | $480,000 |", $c ) );
		$this->assertSame( array(), $V::unhedged( 'Nothing about it here.', $c ) );
	}

	public function test_a_confirmed_claim_and_a_citation_number_are_never_flagged(): void {
		$V  = 'ZANA_Web_Vetting';
		$ok = array( $this->checked( $V::V_VERIFIED, array() ) );
		$this->assertSame( array(), $V::unhedged( 'Franchises average $480,000 a year.', $ok ) );
		$c  = $V::parse_claims( json_encode( array( array( 'claim' => 'Covers cost $15 to $25 each' ) ) ) );
		$c[0]['verdict'] = $V::V_SINGLE;
		$this->assertSame( array(), $V::unhedged( 'Internal sources [15] show steady demand.', $c ), 'a citation marker is not a figure' );
		$this->assertSame( array( 1 ), $V::unhedged( 'Covers cost $15 each.', $c ) );
	}

	public function test_the_block_is_the_servers_own_words_from_the_verdicts(): void {
		$V  = 'ZANA_Web_Vetting';
		$ok = $this->checked( $V::V_VERIFIED, array( $this->ev( 'https://tradeweekly.example/a', 'supports' ), $this->ev( 'https://citygazette.example/b', 'supports' ) ) );
		$no = $this->checked( $V::V_UNCONFIRMED, array( $this->ev( 'https://regionalpost.example/a', 'not_on_page' ), $this->ev( 'https://dailyreport.example/a', 'unreadable' ) ) );
		$no['id'] = 2;
		$b  = $V::render_block( array( $ok, $no ), 'done', array( 2 ) );
		$this->assertStringContainsString( 'Outside facts check', $b );
		$this->assertStringContainsString( 'at least 2 independent sites', $b );
		$this->assertStringContainsString( '2 checked: 1 confirmed · 1 could not confirm', $b );
		$this->assertStringContainsString( 'Says so: [tradeweekly.example](https://tradeweekly.example/a), [citygazette.example](https://citygazette.example/b)', $b );
		$this->assertStringContainsString( 'Why: 2 source pages: 1 opened but did not contain it; 1 could not be opened by the server', $b );
		$this->assertStringContainsString( 'states this without saying it is unconfirmed', $b );
		$this->assertStringContainsString( 'up to 6 a run', $b );
		$this->assertStringNotContainsString( 'ended before every outside fact', $b );
		$this->assertStringContainsString( 'ended before every outside fact', $V::render_block( array( $ok, $no ), 'partial' ) );
	}

	public function test_the_block_says_when_nothing_was_checked_and_why(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertStringContainsString( 'switched off', $V::render_block( array(), 'off' ) );
		$this->assertStringContainsString( 'listed no outside claims', $V::render_block( array(), 'none' ) );
		$this->assertStringContainsString( 'could not be listed for checking', $V::render_block( array(), 'skipped' ) );
		foreach ( array( 'off', 'none', 'skipped' ) as $st ) {
			$this->assertStringContainsString( 'unverified', $V::render_block( array(), $st ), $st );
		}
		$self = $this->checked( $V::V_SINGLE, array( $this->ev( 'https://exampleco.example/a', 'supports' ) ) );
		$this->assertStringContainsString( 'Only the company\'s own sites say this.', $V::render_block( array( $self ) ) );
		$none = $this->checked( $V::V_UNCONFIRMED, array() );
		$this->assertStringContainsString( 'the search found no source pages', $V::render_block( array( $none ) ) );
	}

	public function test_what_a_model_wrote_cannot_break_out_of_the_block(): void {
		$V = 'ZANA_Web_Vetting';
		$this->assertSame( 'https://a.example/x%29%5BOfficial%5D%28https://b.example', $V::md_url( 'https://a.example/x)[Official](https://b.example' ) );
		$this->assertSame( '(link)(x) ‹b›bold  under score \'code\'', $V::md_text( "[link](x) <b>**bold**\n_under_score `code`" ) );
		$c            = $this->checked( $V::V_VERIFIED, array( $this->ev( 'https://tradeweekly.example/a)[Click](https://evil.example', 'supports' ), $this->ev( 'https://citygazette.example/b', 'supports' ) ) );
		$c['claim']   = 'A claim with a [link](https://evil.example) and <script>alert(1)</script> in it';
		$b            = $V::render_block( array( $c ) );
		$this->assertStringNotContainsString( '](https://evil.example', $b );
		$this->assertStringNotContainsString( '<script>', $b );
	}

	public function test_the_writer_is_told_how_it_may_state_each_claim(): void {
		$V   = 'ZANA_Web_Vetting';
		$con = $this->checked( $V::V_CONTESTED, array( $this->ev( 'https://tradeweekly.example/a', 'supports' ), array( 'url' => 'https://exampleco.example/c', 'status' => 'contradicts', 'value' => '$520,000' ) ) );
		$p   = $V::prompt_block( array( 'claims' => array( $con ), 'status' => 'done' ), true );
		$this->assertStringContainsString( '[CONTESTED] ' . self::CLAIM, $p );
		$this->assertStringContainsString( 'says so: tradeweekly.example', $p );
		$this->assertStringContainsString( 'says otherwise: exampleco.example ($520,000)', $p );
		$this->assertStringContainsString( 'only VERIFIED claims may be stated as fact', $p );
		$this->assertStringContainsString( 'NOT DONE', $V::prompt_block( null, true ), 'web sources and no check: every outside figure is unverified' );
		$this->assertSame( '', $V::prompt_block( null, false ), 'no web sources, nothing to say' );
		$this->assertSame( '', $V::prompt_block( array( 'claims' => array(), 'status' => 'none' ), true ), 'the listing found nothing material from the web' );
	}

	public function test_the_check_is_on_in_core_and_a_business_may_switch_it_off(): void {
		$this->assertTrue( ZANA_Web_Vetting::enabled() );
		$GLOBALS['zdz_test_options'][ ZANA_Research_Settings::OPTION ] = array( 'vetting' => '0' );
		$this->assertFalse( ZANA_Web_Vetting::enabled() );
	}
}
