<?php
/**
 * What an Identity Pack writes for the assistant, the ask bar, the usual hours, the name matcher and Agreements:
 *
 *   - catalog.yml vocabulary.things → the ask bar's trade words: plain words only, a word that could act as a pattern
 *     refused by name;
 *   - org.yml work_hours.default → the usual hours, weekday names read as days, refused whole when unreadable;
 *   - knowledge.yml names.soundalikes → the name matcher's groups, ADDED to what the site holds, the preview showing
 *     counts, never a name;
 *   - voice.yml assistant and rules.yml ai_spend.daily_per_person_usd → the assistant's voice and daily allowance,
 *     inside the assistant's own limits;
 *   - Agreements: the legal pack (only one this site has, or none), the cancellations mailbox, the liability
 *     insurer, the commencement words, subcontractors (never assumed), the test customer's name and the lead-time
 *     groups (refused whole when one group is wrong);
 *   - the postal-code lookup service is not read from a pack: it names where customers' postcodes are sent.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
require_once __DIR__ . '/support/privacy-stubs.php';
require_once __DIR__ . '/support/agreements-stubs.php';
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
$zdz_kwa_root = dirname( __DIR__, 2 );
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-business-profile.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-identity-pack.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-capabilities.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-for-you.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-name-match.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/agreements/includes/class-zagr-identity.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/analytics/includes/class-zana-allowance.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/analytics/includes/class-zana-prompt-builder.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/analytics/includes/class-zana-identity.php';

final class KWirePackConsumersTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['zdz_test_options'] = array();
		unset( $GLOBALS['zdz_test_filters'] );
	}

	private static function paths( array $rows ): array {
		return array_column( $rows, 'path' );
	}

	/* ── the platform's own ──────────────────────────────────────────────── */

	public function test_trade_words_are_plain_words_only(): void {
		$p = ZDZ_Identity_Pack::plan_trade_words( array( 'vocabulary.things' => array( 'Panel', 'roof  tile', '(a|b)+', 'panel', 'x' ) ) );
		$this->assertSame( array( 'vocabulary.things' ), self::paths( $p['refused'] ), 'a word that could act as a pattern is refused' );
		$this->assertSame( array( 'panel', 'roof tile', 'x' ), $p['writes'][0]['value'] );
		$this->assertSame( 'zdz_ask_trade_words', $p['writes'][0]['option'] );
		$this->assertArrayHasKey( 'trade-words', ZDZ_Identity_Pack::settings_consumers() );
	}

	public function test_usual_hours_read_weekday_names_and_refuse_what_they_cannot_read(): void {
		$p = ZDZ_Identity_Pack::plan_work_hours( array( 'work_hours.default' => array( 'days' => array( 'monday', 'Tuesday', 3, 4, 'friday' ), 'start' => '8:00', 'end' => '16:30' ) ) );
		$this->assertSame( array(), $p['refused'] );
		$this->assertSame( array( 'days' => array( 1, 2, 3, 4, 5 ), 'start' => '08:00', 'end' => '16:30' ), $p['writes'][0]['value'] );
		$this->assertSame( ZDZ_For_You::OPT_HOURS, $p['writes'][0]['option'] );
		foreach ( array(
			array( 'days' => array( 'someday' ), 'start' => '08:00', 'end' => '16:00' ),
			array( 'days' => array( 1 ), 'start' => '08:00', 'end' => '08:00' ),
			array( 'days' => array(), 'start' => '08:00', 'end' => '16:00' ),
			'weekdays',
		) as $bad ) {
			$p = ZDZ_Identity_Pack::plan_work_hours( array( 'work_hours.default' => $bad ) );
			$this->assertSame( array(), $p['writes'], json_encode( $bad ) );
			$this->assertContains( 'work_hours.default', self::paths( $p['refused'] ) );
		}
	}

	public function test_soundalike_groups_are_added_and_never_shown(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Name_Match::OPTION ] = array( 'surnames' => array( array( 'spellings' => array( 'Casey', 'Kacie' ) ) ) );
		$p = ZDZ_Identity_Pack::plan_soundalikes( array( 'names.soundalikes' => array(
			'surnames'    => array( array( 'spellings' => array( 'Quinn', 'Kwin' ) ), array( 'spellings' => array( 'Kacie', 'Casey' ) ), array( 'spellings' => array( 'Solo' ) ) ),
			'given_names' => array( array( 'names' => array( 'Sean', 'Shawn' ) ) ),
			'favourites'  => array(),
		) ) );
		$this->assertSame( array( 'names.soundalikes.surnames.3', 'names.soundalikes.favourites' ), self::paths( $p['refused'] ) );
		$new = $p['writes'][0]['value'];
		$this->assertCount( 2, $new['surnames'], 'added, a group already held not twice, none removed' );
		$this->assertCount( 1, $new['given_names'] );
		$this->assertSame( array( 'path' => 'names.soundalikes', 'from' => '1 groups', 'to' => '3 groups' ), $p['changes'][0] );
		$this->assertStringNotContainsString( 'Quinn', json_encode( $p['changes'] ), 'the preview shows counts, never a name' );
	}

	public function test_a_postcode_lookup_service_is_never_set_by_a_pack(): void {
		$this->assertArrayHasKey( 'places.zip_lookup_url', ZDZ_Identity_Pack::NOT_FROM_A_PACK['connections'] );
	}

	/* ── the assistant ───────────────────────────────────────────────────── */

	public function test_the_assistants_voice_and_allowance_inside_its_limits(): void {
		$p = ZANA_Identity::plan_voice( array( 'assistant' => array( 'name' => 'Robin', 'lines' => array( 'Plain words.', '  Short   sentences. ' ) ) ) );
		$this->assertSame( array( 'name' => 'Robin', 'lines' => array( 'Plain words.', 'Short sentences.' ) ), $p['writes'][0]['value'] );
		$this->assertSame( ZANA_Prompt_Builder::VOICE_OPT, $p['writes'][0]['option'] );
		$p = ZANA_Identity::plan_voice( array( 'assistant' => array( 'name' => 'Robin', 'lines' => array( 'Fine.', array( 'nested' ), str_repeat( 'a', 401 ) ), 'mood' => 'x' ) ) );
		$this->assertSame( array( 'assistant.mood', 'assistant.lines.2', 'assistant.lines.3' ), self::paths( $p['refused'] ) );
		$this->assertSame( array(), $p['writes'], 'a voice is applied whole or not at all' );

		$p = ZANA_Identity::plan_allowance( array( 'ai_spend.daily_per_person_usd' => '3' ) );
		$this->assertSame( 3.0, $p['writes'][0]['value'] );
		foreach ( array( -1, 51, 'lots' ) as $bad ) {
			$p = ZANA_Identity::plan_allowance( array( 'ai_spend.daily_per_person_usd' => $bad ) );
			$this->assertSame( array(), $p['writes'], (string) $bad );
		}
		$c = ZANA_Identity::register( array() );
		$this->assertSame( array( 'voice' => array( 'assistant' ) ), $c['assistant-voice']['paths'] );
		$this->assertSame( array( 'rules' => array( 'ai_spend.daily_per_person_usd' ) ), $c['assistant-allowance']['paths'] );
	}

	/* ── Agreements ──────────────────────────────────────────────────────── */

	public function test_the_legal_pack_must_be_one_this_site_has(): void {
		$ids = ZAGR_Pack::ids();
		$this->assertNotEmpty( $ids );
		$p = ZAGR_Identity::plan_profile( array( 'legal.agreement_pack' => strtoupper( $ids[0] ) ) );
		$this->assertSame( array(), $p['refused'] );
		$this->assertSame( ZDZ_Business_Profile::OPTION, $p['writes'][0]['option'] );
		$this->assertSame( 'legal', $p['writes'][0]['key'] );
		$this->assertSame( $ids[0], $p['writes'][0]['value']['agreement_pack'] );
		$this->assertSame( 'none', ZAGR_Identity::plan_profile( array( 'legal.agreement_pack' => 'none' ) )['writes'][0]['value']['agreement_pack'] );
		$p = ZAGR_Identity::plan_profile( array( 'legal.agreement_pack' => 'xx-made-up' ) );
		$this->assertSame( array( 'legal.agreement_pack' ), self::paths( $p['refused'] ) );
		$this->assertSame( array(), $p['writes'] );
	}

	public function test_agreement_facts_from_a_pack_are_checked(): void {
		$GLOBALS['zdz_test_options'][ ZAGR_Settings::OPT_FACTS ] = array( 'legal_name' => 'Example Co' );
		$p = ZAGR_Identity::plan_profile( array(
			'contact.function_mailboxes.cancellations' => 'cancel@example.com',
			'insurance.general_liability'              => array( 'insurer' => 'Example Mutual', 'phone' => '555-0100' ),
		) );
		$this->assertSame( array(), $p['refused'] );
		$keys = array_column( $p['writes'], 'value', 'key' );
		$this->assertSame( array( 'cancel_email' => 'cancel@example.com', 'cgl_insurer' => 'Example Mutual', 'cgl_phone' => '555-0100' ), $keys );
		foreach ( $p['writes'] as $w ) {
			$this->assertSame( ZAGR_Settings::OPT_FACTS, $w['option'], 'one fact at a time: the others are kept' );
		}
		$p = ZAGR_Identity::plan_profile( array( 'contact.function_mailboxes.cancellations' => 'not an address', 'insurance.general_liability' => array( 'phone' => '555-0100' ) ) );
		$this->assertSame( array( 'contact.function_mailboxes.cancellations', 'insurance.general_liability' ), self::paths( $p['refused'] ) );

		$p = ZAGR_Identity::plan_rules( array( 'agreements.subcontractors_used' => false, 'testing.fixture_customer_name' => 'Sample Customer' ) );
		$this->assertSame( 'no', $p['writes'][0]['value'] );
		$this->assertSame( 'Sample Customer', $p['writes'][1]['value'] );
		$p = ZAGR_Identity::plan_rules( array( 'agreements.subcontractors_used' => 'maybe' ) );
		$this->assertSame( array( 'agreements.subcontractors_used' ), self::paths( $p['refused'] ), 'never assumed' );

		$p = ZAGR_Identity::plan_wording( array( 'agreements.substantial_commencement' => 'When we begin work at your home.' ) );
		$this->assertSame( 'substantial_commencement', $p['writes'][0]['key'] );
	}

	public function test_lead_time_groups_are_applied_whole_or_not_at_all(): void {
		$good = array(
			'standard'      => array( 'label' => 'Standard', 'min' => 3, 'max' => 10, 'install' => 1 ),
			'made_to_order' => array( 'label' => 'Made to order', 'min' => 20, 'max' => 40, 'install' => 1, 'per_unit' => true ),
		);
		$p = ZAGR_Identity::plan_lead( array( 'lead_times' => $good ) );
		$this->assertSame( array(), $p['refused'] );
		$this->assertSame( ZAGR_Settings::OPT_LEAD, $p['writes'][0]['option'] );
		$this->assertSame( array( 'standard', 'made_to_order' ), array_column( $p['writes'][0]['value'], 'id' ) );
		$this->assertTrue( $p['writes'][0]['value'][1]['per_unit'] );

		$bad = $good + array( 'backwards' => array( 'min' => 9, 'max' => 2, 'install' => 1 ) );
		$p   = ZAGR_Identity::plan_lead( array( 'lead_times' => $bad ) );
		$this->assertSame( array( 'lead_times.backwards' ), self::paths( $p['refused'] ) );
		$this->assertSame( array(), $p['writes'], 'half a list is never applied' );

		$c = ZAGR_Identity::register( array() );
		$this->assertSame( array( 'agreements-profile', 'agreements-wording', 'agreements-rules', 'agreements-lead' ), array_keys( $c ) );
	}

	/* ── one round trip through preview, apply and revert ───────────────── */

	public function test_preview_apply_and_revert_round_trip(): void {
		$root = sys_get_temp_dir() . '/zdz-kwa-pack-' . getmypid() . '-' . mt_rand();
		@mkdir( $root . '/example-co', 0700, true );
		file_put_contents( $root . '/example-co/rules.yml', "home_tiles:\n  sets:\n    sales: [my_leads, my_installed]\ntesting:\n  fixture_customer_name: Sample Customer\nai_spend:\n  daily_per_person_usd: 2\n" );
		file_put_contents( $root . '/example-co/flows.yml', "lead_times:\n  standard: { label: Standard, min: 3, max: 10, install: 1 }\n" );
		file_put_contents( $root . '/example-co/catalog.yml', "vocabulary:\n  things: [panel, roof tile]\n" );
		$GLOBALS['zdz_test_options'][ ZAGR_Settings::OPT_TEST ] = 'Earlier Name';
		$before = $GLOBALS['zdz_test_options'];
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_paths']     = function () use ( $root ) { return array( $root ); };
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] = function ( $c ) { return ZANA_Identity::register( ZAGR_Identity::register( ZDZ_KPI_Metrics::pack_consumer( $c ) ) ); };
		try {
			$p = ZDZ_Identity_Pack::preview( 'example-co' );
			$this->assertSame( array(), $p['refused'] );
			$paths = array_column( $p['changes'], 'path' );
			foreach ( array( 'home_tiles.sets', 'testing.fixture_customer_name', 'ai_spend.daily_per_person_usd', 'lead_times', 'vocabulary.things' ) as $want ) {
				$this->assertContains( $want, $paths );
			}
			$this->assertSame( $before, $GLOBALS['zdz_test_options'], 'a preview writes nothing' );

			$this->assertTrue( ZDZ_Identity_Pack::apply( 'example-co', true )['ok'] );
			$this->assertSame( array( 'my_leads', 'my_installed' ), ZDZ_KPI_Metrics::chosen( 'sales' ) );
			$this->assertSame( 'Sample Customer', ZAGR_Settings::test_fixture() );
			$this->assertSame( 2.0, ZANA_Allowance::allowance_usd() );
			$this->assertArrayHasKey( 'standard', ZAGR_Settings::lead() );
			$this->assertSame( array( 'panel', 'roof tile' ), get_option( 'zdz_ask_trade_words' ) );

			$this->assertTrue( ZDZ_Identity_Pack::revert()['ok'] );
			foreach ( array( ZDZ_KPI_Metrics::TILE_SETS_OPTION, ZAGR_Settings::OPT_TEST, ZANA_Allowance::OPTION, ZAGR_Settings::OPT_LEAD, 'zdz_ask_trade_words' ) as $k ) {
				$this->assertSame( $before[ $k ] ?? 'ABSENT', $GLOBALS['zdz_test_options'][ $k ] ?? 'ABSENT', "$k is exactly as it was" );
			}
		} finally {
			unset( $GLOBALS['zdz_test_filters']['zdz_identity_pack_paths'], $GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] );
			foreach ( (array) glob( $root . '/example-co/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $root . '/example-co' );
			@rmdir( $root );
		}
	}
}
