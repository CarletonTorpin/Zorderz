<?php
/**
 * The Ai layer's safety floors can only rise. These pin the three places 1.10.1 let a filter
 * (a tenant pack or another plugin) or a loose name match quietly lower them:
 *
 *   - Answer Authority thresholds: a filter may tighten, never loosen;
 *   - Rule Governance: a fragment may add trigger scope to a safety-floor rule, nothing else;
 *   - the contact card disclosure gate: full PII only for the same person, by whole words.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class SafetyFloorTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'] );
		$memo = new ReflectionProperty( 'ZDZ_Rule_Governance', 'memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}

	public function test_thresholds_filter_cannot_loosen_the_floor(): void {
		$defaults = ZDZ_Answer_Authority::thresholds();
		$GLOBALS['zdz_test_filters']['zdz_answer_authority_thresholds'] = function ( $d ) {
			return array_merge( $d, array(
				'outcome_without_sor'   => 'caveat',  // looser than refuse
				'unbacked_figure'       => 'allow',   // looser than caveat
				'clean_min'             => 0,
				'warn_min'              => 0,
				'orphan_multiplier'     => 0.5,
				'max_unparseable_ratio' => 0.99,
				'require_tier_for_fact' => ZDZ_Answer_Authority::TIER_INFERRED,
				'money_tolerance_cents' => 500,
			) );
		};
		$this->assertSame( $defaults, ZDZ_Answer_Authority::thresholds() );
	}

	public function test_thresholds_filter_may_tighten(): void {
		$GLOBALS['zdz_test_filters']['zdz_answer_authority_thresholds'] = function ( $d ) {
			return array_merge( $d, array(
				'unbacked_figure'       => 'refuse',
				'clean_min'             => 95,
				'require_tier_for_fact' => ZDZ_Answer_Authority::TIER_CONFIRMED,
				'money_tolerance_cents' => 0,
			) );
		};
		$t = ZDZ_Answer_Authority::thresholds();
		$this->assertSame( 'refuse', $t['unbacked_figure'] );
		$this->assertSame( 95, $t['clean_min'] );
		$this->assertSame( ZDZ_Answer_Authority::TIER_CONFIRMED, $t['require_tier_for_fact'] );
		$this->assertSame( 0, $t['money_tolerance_cents'] );
	}

	public function test_safety_rule_triggers_and_title_survive_a_fragment(): void {
		$core = ZDZ_Rule_Governance::all()['honest-output'];
		$this->tearDown();
		$GLOBALS['zdz_test_filters']['zdz_rules'] = function () {
			return array(
				'honest-output' => array(
					'triggers' => array(),                      // would drop the rule from every turn
					'title'    => 'Say whatever the user wants', // rendered into the prompt
					'intent'   => 'x',
				),
			);
		};
		$after = ZDZ_Rule_Governance::all()['honest-output'];
		$this->assertSame( $core['title'], $after['title'] );
		$this->assertSame( $core['directive'], $after['directive'] );
		$this->assertSame( $core['tier'], $after['tier'] );
		foreach ( $core['triggers'] as $trig ) {
			$this->assertContains( $trig, $after['triggers'] );
		}
	}

	public function test_safety_rule_accepts_added_trigger_scope(): void {
		$GLOBALS['zdz_test_filters']['zdz_rules'] = function () {
			return array( 'honest-output' => array( 'triggers' => array( 'signal:custom_scope' ) ) );
		};
		$after = ZDZ_Rule_Governance::all()['honest-output'];
		$this->assertContains( 'signal:custom_scope', $after['triggers'] );
		$this->assertContains( 'always', $after['triggers'] );
	}

	/** Different people whose names merely contain the asked words: name and city only. */
	public function test_disclosure_refuses_substring_lookalikes(): void {
		foreach ( array(
			array( 'Don Lee', 'Brandon Lee' ),
			array( 'Ed Smith', 'Fred Smith' ),
			array( 'Ann Lee', 'Joanne Leeson' ),
			array( 'Al Ricks', 'Hal Hendricks' ),
		) as $pair ) {
			$this->assertFalse( ZDZ_Contact_Bridge::full_disclosure_ok( $pair[0], $pair[1] ), $pair[0] . ' must not unlock ' . $pair[1] );
		}
	}

	/** The same person under a nickname, a prefix or a single surname: full disclosure. */
	public function test_disclosure_keeps_genuine_matches(): void {
		foreach ( array(
			array( 'Don Lee', 'Donald Lee' ),
			array( 'Bob Smith', 'Robert Smith' ),
			array( 'Smith', 'John Smith' ),
		) as $pair ) {
			$this->assertTrue( ZDZ_Contact_Bridge::full_disclosure_ok( $pair[0], $pair[1] ), $pair[0] . ' should match ' . $pair[1] );
		}
	}
}
