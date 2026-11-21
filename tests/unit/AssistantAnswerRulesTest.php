<?php
/**
 * The chat's three answer rules and the blank fact entries that go with them:
 *
 *   - "documents that state facts", "infer, quantify, qualify, cite" and "audience and tone" are typed rules in the
 *     platform's registry, written once into the fixed part of the instructions (the same for everyone, cached), never
 *     taking a place in the short per-turn reminder, and never lowering a platform floor;
 *   - a business may narrow one, or add its own beside them; emptying one brings Core's wording back;
 *   - the wording names no business, person, place or number, and no bracketed action token can come out of it;
 *   - the blank entries for legal entities and signers are labels only (operator level and above); a label left as it
 *     came is not a fact and is dropped at save; an entry with nothing typed is refused.
 *
 * Neutral data only (Example Co, Alex, Jordan).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';

foreach ( array( 'ZANA_APP_ID' => 'sales-analytics', 'ZANA_NONCE' => 'zana_nonce' ) as $zdz_asst_k => $zdz_asst_v ) {
	if ( ! defined( $zdz_asst_k ) ) {
		define( $zdz_asst_k, $zdz_asst_v );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}

$zdz_asst_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/';
foreach ( array( 'markers', 'allowance', 'llm-client', 'prompt-builder', 'company-facts', 'answer-rules', 'fact-starters' ) as $zdz_asst_f ) {
	require_once $zdz_asst_inc . 'class-zana-' . $zdz_asst_f . '.php';
}

final class AssistantAnswerRulesTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $_GET['zana_fact_starter'] );
		self::forget_rules();
		$GLOBALS['wpdb']          = new ZDZ_Test_Vault_DB();   // no catalog, no profile: an empty site
		ZANA_Company_Facts::$rows = array();
		self::forget_site();
		// the rules are on the registry, as the app puts them there when it loads
		$GLOBALS['zdz_test_filters']['zdz_rules'] = array( 'ZANA_Answer_Rules', 'register' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $_GET['zana_fact_starter'], $GLOBALS['wpdb'] );
		ZANA_Company_Facts::$rows = null;
		self::forget_rules();
		self::forget_site();
	}

	/** Another file may have read a profile or a catalog; this file's site has neither. */
	private static function forget_site(): void {
		foreach ( array( 'ZDZ_Business_Profile', 'ZDZ_Item_Engine' ) as $c ) {
			if ( class_exists( $c ) && method_exists( $c, 'flush' ) ) {
				$c::flush();
			}
		}
	}

	private static function forget_rules(): void {
		$memo = new ReflectionProperty( 'ZDZ_Rule_Governance', 'memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}

	/* ── the rules ───────────────────────────────────────────────────────────────────── */

	public function test_the_three_rules_are_typed_rules_in_the_registry(): void {
		foreach ( ZANA_Answer_Rules::IDS as $id ) {
			$r = ZDZ_Rule_Governance::cite( $id );   // throws when a cited rule does not exist
			$this->assertSame( ZDZ_Rule_Governance::TIER_ADVISORY, $r['tier'], $id );
			$this->assertNotSame( '', trim( $r['directive'] ), $id );
			$this->assertNotSame( $id, $r['title'], $id . ' has a title of its own' );
		}
		$this->assertSame( array(), ZDZ_Rule_Governance::validate() );
		$this->assertSame( array( 'documents-that-state-facts', 'infer-quantify-qualify-cite', 'audience-and-tone' ), ZANA_Answer_Rules::IDS );
	}

	public function test_they_never_take_a_place_in_the_short_reminder_or_touch_a_floor(): void {
		$floor = ZDZ_Rule_Governance::safety_floor_ids();
		foreach ( array( array(), array( 'category' => 'customer_lookup', 'signals' => array( 'asks_count' => true, 'asks_figure' => true, 'email_intent' => true, 'write_intent' => true ) ), array( 'is_kiosk' => true ) ) as $ctx ) {
			$picked = array_keys( ZDZ_Rule_Governance::select( $ctx ) );
			$this->assertSame( array(), array_intersect( ZANA_Answer_Rules::IDS, $picked ) );
			$this->assertLessThanOrEqual( ZDZ_Rule_Governance::MAX_RULES_PER_TURN, count( $picked ) );
		}
		$this->assertSame( array(), array_intersect( ZANA_Answer_Rules::IDS, $floor ), 'none of them is a floor, and none replaces one' );
		$this->assertContains( 'honest-output', $floor );
		self::forget_rules();
		unset( $GLOBALS['zdz_test_filters']['zdz_rules'] );
		$this->assertSame( $floor, ZDZ_Rule_Governance::safety_floor_ids(), 'the floor is the same with and without them' );
	}

	public function test_the_block_is_in_the_fixed_part_of_the_instructions_for_everyone(): void {
		$block = ZANA_Answer_Rules::block();
		$this->assertSame( 0, strpos( $block, 'ANSWER RULES' ) );
		foreach ( array( 'DOCUMENTS THAT STATE FACTS', 'INFER, QUANTIFY, QUALIFY, CITE', 'AUDIENCE AND TONE' ) as $head ) {
			$this->assertSame( 1, preg_match( '/^' . preg_quote( $head, '/' ) . '$/m', $block ), $head . ' heads its rule' );
		}
		$built = ZANA_Prompt_Builder::build( 0, array() );
		$fixed = substr( $built, 0, (int) strpos( $built, ZANA_LLM_Client::SPLIT ) );
		$this->assertStringContainsString( $block, $fixed, 'before the first cache mark: the same for everyone, every turn' );
		$this->assertStringNotContainsString( 'ANSWER RULES', substr( $built, strlen( $fixed ) ) );
		$this->assertGreaterThan( strpos( $fixed, 'FORMATTING:' ), strpos( $fixed, 'ANSWER RULES' ), 'after how an answer is formatted' );
		foreach ( array( array( 'is_kiosk' => true ), array( 'privacy_view' => true ) ) as $ctx ) {
			$this->assertStringContainsString( $block, ZANA_Prompt_Builder::build( 0, $ctx ) );
		}
	}

	public function test_documents_that_state_facts_says_what_makes_a_paper_safe(): void {
		$d = ZANA_Answer_Rules::rule( 'documents-that-state-facts' )['directive'];
		foreach ( array(
			'someone who will rely on it',                     // when the rule applies
			'An ordinary email to a customer about their own job is not this',
			'becomes a blank to fill',                         // nothing guessed
			'never a confident guess',
			'Never make a paper look older than it is',
			'never put a past date on the line where it is signed',
			'starts on the date it is signed',                 // a new procedure is not written as an old one
			'Name only the people the evidence shows',
			'never put another person\'s name in their place',
			'A signer attests only to what they know themselves',
			'takes no position in any legal proceeding',
			'by document number, not by name',
			'Check the paper against the business\'s own standing',
			'say so first, plainly, before any wording',
			'Never help write around a fact',
			'End with what must be checked before anyone signs',
			'you are not a lawyer',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $d, $needle );
		}
		$this->assertSame( 8, preg_match_all( '/^\d\. /m', $d ), 'eight numbered points' );
	}

	public function test_inference_is_quantified_qualified_and_cited(): void {
		$d = ZANA_Answer_Rules::rule( 'infer-quantify-qualify-cite' )['directive'];
		foreach ( array( 'A disagreement is a finding', 'how many records each rests on', 'ON RECORD', 'STATED', 'INFERRED', 'OUTSIDE SOURCE', 'Never present an inference as a record', 'A figure with no source is not given', 'what would confirm or overturn it' ) as $needle ) {
			$this->assertStringContainsString( $needle, $d, $needle );
		}
	}

	public function test_every_audience_has_its_tone_and_the_assumed_one_is_said(): void {
		$d = ZANA_Answer_Rules::rule( 'audience-and-tone' )['directive'];
		foreach ( array( 'CUSTOMER or LEAD', 'STAFF', 'VENDOR or PARTNER', 'OFFICIAL BODY', 'THE PERSON ASKING' ) as $who ) {
			$this->assertSame( 1, preg_match( '/^- ' . preg_quote( $who, '/' ) . ' \(/m', $d ), $who );
		}
		$this->assertStringContainsString( 'say in one short line above the draft which you assumed', $d );
		$this->assertStringContainsString( 'a correction is private, factual and about the work, never the person', $d );
		$this->assertStringContainsString( 'no persuasion, no warmth markers, no exclamation marks', $d );
		$this->assertStringContainsString( 'When a HOW ... WRITES block lists rules for that audience, apply them on top of this.', $d );
		// the two places that send the reader to the documents rule name it as its heading is written
		$this->assertSame( 2, substr_count( $d, 'under DOCUMENTS THAT STATE FACTS' ) );
	}

	public function test_the_wording_carries_no_placeholder_token_or_particular(): void {
		$all = '';
		foreach ( ZANA_Answer_Rules::core() as $id => $r ) {
			$all .= $r['title'] . "\n" . $r['directive'] . "\n";
			$this->assertSame( array(), $r['triggers'], $id );
		}
		$this->assertSame( 0, preg_match( '/[{}$]|\[\/?Z[A-Z]*_/', $all ), 'no placeholder, no variable, no bracketed action token' );
		$this->assertSame( 0, preg_match( '/@|https?:|www\.|\d{3,}|#\d/', $all ), 'no address, link, phone or document number' );
		$this->assertSame( 0, preg_match( '/\b(?:Inc|LLC|Ltd|Corp)\b/', $all ), 'no business is named' );
	}

	public function test_a_business_may_narrow_a_rule_or_add_its_own_and_an_emptied_rule_comes_back(): void {
		$GLOBALS['zdz_test_filters']['zdz_rules'] = function ( $rules ) {
			$rules = ZANA_Answer_Rules::register( $rules );
			$rules['audience-and-tone']['directive'] .= ' For {business_name}, never use slang with [ZDZ_ACTION] in it.';
			$rules['infer-quantify-qualify-cite']['directive'] = '   ';
			$rules['example-local-law'] = array( 'title' => 'Local law', 'directive' => 'In this place, a notice is dated the day it is posted.', 'triggers' => array( 'always' ) );
			return $rules;
		};
		self::forget_rules();
		$GLOBALS['zdz_test_options']['zdz_business_profile'] = array();
		$block = ZANA_Answer_Rules::block();
		$this->assertStringContainsString( 'For this business, never use slang with ZDZ_ACTION in it.', $block, 'filled and defused as the registry does it' );
		$this->assertStringNotContainsString( '[ZDZ_ACTION]', $block );
		$this->assertStringContainsString( 'A disagreement is a finding', $block, 'emptied: Core\'s wording is written' );
		$this->assertStringNotContainsString( 'Local law', $block, 'its own rule is the registry\'s to select, not part of this block' );
		$this->assertTrue( ZDZ_Rule_Governance::exists( 'example-local-law' ) );
	}

	public function test_without_the_registry_filter_the_block_is_still_whole(): void {
		unset( $GLOBALS['zdz_test_filters']['zdz_rules'] );
		self::forget_rules();
		$this->assertFalse( ZDZ_Rule_Governance::exists( 'audience-and-tone' ) );
		$this->assertStringContainsString( 'AUDIENCE AND TONE', ZANA_Answer_Rules::block() );
		$this->assertSame( array( 'kept' => 1 ) + ZANA_Answer_Rules::core(), ZANA_Answer_Rules::register( array( 'kept' => 1 ) ), 'added to what is there' );
		$mine = array( 'audience-and-tone' => array( 'directive' => 'Mine.' ) );
		$this->assertSame( 'Mine.', ZANA_Answer_Rules::register( $mine )['audience-and-tone']['directive'], 'never over an entry already there' );
	}

	/* ── the blank entries ───────────────────────────────────────────────────────────────────── */

	public function test_the_blank_entries_are_labels_only_for_the_operator_level_and_above(): void {
		$all = ZANA_Fact_Starters::all();
		$this->assertSame( array( 'business-entities', 'business-signatories' ), array_keys( $all ) );
		foreach ( $all as $id => $s ) {
			$this->assertSame( $id, $s['id'] );
			$this->assertSame( 'operator+', $s['tier_limit'], $id );
			$this->assertContains( $s['category'], ZANA_Company_Facts::CATEGORIES, $id );
			$this->assertGreaterThanOrEqual( 4, count( $s['fields'] ), $id );
			$tpl = ZANA_Fact_Starters::template( $id );
			$this->assertLessThan( 2000, strlen( $tpl ), $id );
			foreach ( explode( "\n", $tpl ) as $line ) {
				$this->assertSame( 1, preg_match( '/: $/', $line ), 'a label and nothing after it: ' . $line );
			}
			$this->assertSame( 0, preg_match( '/@|https?:|\d{3,}|\b(?:Inc|LLC|Ltd|Corp)\b/', $s['label'] . "\n" . $tpl ), $id . ' holds no value of any business' );
			// straight from the form with nothing typed: there is nothing to save
			$this->assertSame( '', ZANA_Fact_Starters::filled( $id, $tpl ) );
			$this->assertNotSame( '', ZANA_Company_Facts::validate( ZANA_Company_Facts::sanitize( array( 'id' => $id, 'label' => $s['label'], 'value' => ZANA_Fact_Starters::filled( $id, $tpl ) ) ) ) );
		}
		$this->assertSame( 'business', $all['business-entities']['category'] );
		$this->assertSame( 'people', $all['business-signatories']['category'] );
		$this->assertStringContainsString( 'looked up live before it is stated', implode( "\n", $all['business-entities']['fields'] ), 'a status is never stated from a saved fact' );
		$this->assertStringContainsString( 'Who reads a document before it leaves the business', implode( "\n", $all['business-signatories']['fields'] ) );
		$this->assertSame( '', ZANA_Fact_Starters::template( 'no-such-entry' ) );
		$this->assertNull( ZANA_Fact_Starters::get( 'no-such-entry' ) );
	}

	public function test_only_the_labels_left_as_they_came_are_dropped_at_save(): void {
		$fields = ZANA_Fact_Starters::get( 'business-entities' )['fields'];
		$typed  = $fields[0] . ": Example Co Holdings\r\n"
			. $fields[1] . ":\n"                                  // left as it came
			. '  ' . strtoupper( $fields[2] ) . " :  \n"          // left as it came, however it was spaced or cased
			. "A line of the administrator's own:\n"              // not one of the entry's labels: kept
			. $fields[3] . ': Example Co';
		$this->assertSame(
			$fields[0] . ": Example Co Holdings\nA line of the administrator's own:\n" . $fields[3] . ': Example Co',
			ZANA_Fact_Starters::filled( 'business-entities', $typed )
		);
		$other = "Opening hours:\nWeekdays, eight to five";
		$this->assertSame( $other, ZANA_Fact_Starters::filled( 'opening_hours', $other ), 'any other fact is saved as typed' );
		$this->assertSame( $other, ZANA_Fact_Starters::filled( 'business-signatories', $other ), 'and so is a line that is not one of the entry\'s labels' );
	}

	public function test_the_chosen_entry_fills_the_form_and_a_saved_one_is_not_offered_again(): void {
		$this->assertNull( ZANA_Fact_Starters::chosen() );
		$_GET['zana_fact_starter'] = 'business-signatories';
		$this->assertSame( 'business-signatories', ZANA_Fact_Starters::chosen()['id'] );
		$_GET['zana_fact_starter'] = 'Business-Signatories"><script>';
		$this->assertNull( ZANA_Fact_Starters::chosen(), 'only a known id, read as a key' );
		$page = 'https://app.example.com/wp-admin/admin.php?page=zana-settings';
		$html = ZANA_Fact_Starters::links_html( $page );
		$this->assertStringContainsString( 'page=zana-settings&amp;zana_fact_starter=business-entities"', $html );
		$this->assertStringContainsString( 'zana_fact_starter=business-signatories"', $html );
		$this->assertSame( 2, substr_count( $html, '<a ' ) );
		ZANA_Company_Facts::$rows = array( array( 'id' => 'business-entities', 'category' => 'business', 'label' => 'x', 'value' => 'y', 'tier_limit' => 'operator+', 'sort_order' => 10 ) );
		$html = ZANA_Fact_Starters::links_html( $page );
		$this->assertStringNotContainsString( 'zana_fact_starter=business-entities', $html, 'already one of the business\'s facts' );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		ZANA_Company_Facts::$rows[] = array( 'id' => 'business-signatories', 'category' => 'people', 'label' => 'x', 'value' => 'y', 'tier_limit' => 'operator+', 'sort_order' => 20 );
		$this->assertSame( '', ZANA_Fact_Starters::links_html( $page ) );
	}

	public function test_a_business_adds_its_own_blank_entries_and_cannot_replace_cores(): void {
		$GLOBALS['zdz_test_filters']['zana_fact_starters'] = function () {
			return array(
				'business-entities' => array( 'label' => 'Replaced', 'fields' => array( 'x' ) ),
				'Depot Rules!'      => array( 'category' => 'conventions', 'tier_limit' => 'all', 'label' => ' Depot <b>rules</b>: ', 'fields' => array( 'Who opens the depot:', '', 7, 'Who opens the depot' ) ),
				'no-fields'         => array( 'label' => 'Nothing to fill', 'fields' => array() ),
			);
		};
		$all = ZANA_Fact_Starters::all();
		$this->assertSame( array( 'business-entities', 'business-signatories', 'depotrules' ), array_keys( $all ) );
		$this->assertNotSame( 'Replaced', $all['business-entities']['label'] );
		$this->assertSame( array( 'id' => 'depotrules', 'category' => 'conventions', 'tier_limit' => 'all', 'label' => 'Depot rules', 'fields' => array( 'Who opens the depot' ) ), $all['depotrules'] );
		$this->assertSame( 'Who opens the depot: ', ZANA_Fact_Starters::template( 'depotrules' ) );
	}

	public function test_a_saved_entry_reaches_only_the_levels_it_is_for(): void {
		$value                    = ZANA_Fact_Starters::filled( 'business-signatories', ZANA_Fact_Starters::get( 'business-signatories' )['fields'][0] . ': Alex Example, owner' );
		ZANA_Company_Facts::$rows = array( ZANA_Company_Facts::sanitize( array( 'id' => 'business-signatories', 'category' => 'people', 'label' => 'Who signs for the business', 'value' => $value, 'tier_limit' => 'operator+' ) ) );
		$this->assertSame( '', ZANA_Company_Facts::block( 'staff' ), 'never named below the operator level' );
		$this->assertSame( '', ZANA_Company_Facts::block( 'viewer' ) );
		$this->assertStringContainsString( 'Alex Example, owner', ZANA_Company_Facts::block( 'operator' ) );
		$this->assertStringContainsString( 'Alex Example, owner', ZANA_Company_Facts::block( 'admin' ) );
	}

	public function test_the_settings_screen_drops_blank_labels_before_it_saves(): void {
		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-settings.php' );
		$save = substr( $src, (int) strpos( $src, 'public static function post_fact_save' ), 2600 );
		$this->assertLessThan( strpos( $save, 'ZANA_Fact_Starters::filled(' ), strpos( $save, "check_admin_referer( 'zana_fact_save' )" ), 'after the nonce' );
		$this->assertLessThan( strpos( $save, 'ZANA_Company_Facts::save( $fact )' ), strpos( $save, 'ZANA_Fact_Starters::filled(' ), 'before the save' );
		$this->assertLessThan( strpos( $save, "check_admin_referer( 'zana_fact_save' )" ), strpos( $save, "current_user_can( 'manage_options' )" ), 'administrators only, checked first' );
	}
}
