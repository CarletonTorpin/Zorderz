<?php
/**
 * Kernel services that decide who may do what, and how people and apps are drawn:
 *
 *   - ZDZ_Capabilities: an unlabelled verb is a write (never run unconfirmed); a business can
 *     move a role on the verb ladder but never onto the admin rung unless the role is admin-level;
 *     the shared device is never placed; a role nobody placed gets no rung (deny by default).
 *   - ZDZ_Person_Color: chosen tones are kept, the shared device is plain gray, hand-out order is
 *     stable, a bad palette filter falls back to the shipped palette.
 *   - ZDZ_App_Groups: a business may move apps and rename groups, never change the colours.
 *   - ZDZ_Consent: nothing is "agreed" while the card is off; recorders carry on as before.
 *   - ZDZ_Core_Crypto: each at-rest format round-trips, and plaintext is never read as ciphertext.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-person-color.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-app-groups.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-crypto.php';

final class KernelVerbsPeopleTest extends TestCase {

	protected function setUp(): void {
		// capabilities another file's test granted are not this file's people
		unset( $GLOBALS['zdz_test_user_caps'] );
		ZDZ_Capabilities::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'] );
		ZDZ_Capabilities::flush();
		ZDZ_Person_Color::flush();
	}

	/* ── Capabilities ─────────────────────────────────────────────────────── */

	public function test_unlabelled_verb_is_treated_as_a_write(): void {
		$row = ZDZ_Capabilities::normalize( 'widget.make', array( 'callable' => 'strlen' ), array() );
		$this->assertTrue( $row['side_effect'], 'a verb nobody labelled must not be run unconfirmed' );
		$this->assertTrue( $row['outside'] );
		$this->assertSame( 'admin', $row['tier'], 'an unknown tier word is the highest rung' );
	}

	public function test_annotation_or_row_can_mark_a_read(): void {
		$ann = ZDZ_Capabilities::annotations();
		$row = ZDZ_Capabilities::normalize( 'lead.lookup', array( 'callable' => 'strlen', 'tier' => 'sales' ), $ann );
		$this->assertFalse( $row['side_effect'] );
		$this->assertSame( 'staff', $row['tier'] );
		$row2 = ZDZ_Capabilities::normalize( 'thing.read', array( 'callback' => 'strlen', 'side_effect' => false ), array() );
		$this->assertFalse( $row2['side_effect'] );
	}

	public function test_rejects_bad_verbs_and_uncallables(): void {
		$this->assertNull( ZDZ_Capabilities::normalize( 'NoDot', array( 'callable' => 'strlen' ) ) );
		$this->assertNull( ZDZ_Capabilities::normalize( 'a.b', array( 'callable' => 'no_such_function_xyz' ) ) );
		$this->assertNull( ZDZ_Capabilities::normalize( 'a.b', 'not-an-array' ) );
	}

	public function test_business_cannot_put_a_non_admin_role_on_the_admin_rung(): void {
		$GLOBALS['zdz_test_options']['zdz_role_tiers'] = array( 'zdz_sales' => 'admin', 'zdz_tech' => 'staff', 'zdz_general' => 'admin', 'zdz_operator' => 'nonsense' );
		$t = ZDZ_Capabilities::role_tiers();
		$this->assertSame( 'staff', $t['zdz_sales'], 'admin rung refused to a non-admin role' );
		$this->assertSame( 'staff', $t['zdz_tech'], 'a business may move a role within the floor' );
		$this->assertArrayNotHasKey( 'zdz_general', $t, 'the shared-device role is never placed' );
		$this->assertSame( 'staff', $t['zdz_operator'], 'an invalid value leaves the default' );
	}

	public function test_unknown_role_gets_no_rung(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'some_other_plugin_role' ), 8 => array( 'zdz_tech' ), 9 => array( 'zdz_tech', 'zdz_sales' ) );
		$this->assertSame( '', ZDZ_Capabilities::tier_of( 7 ) );
		$this->assertSame( 'viewer', ZDZ_Capabilities::tier_of( 8 ) );
		$this->assertSame( 'staff', ZDZ_Capabilities::tier_of( 9 ), 'two roles: the higher rung' );
		$this->assertSame( '', ZDZ_Capabilities::tier_of( 0 ) );
	}

	public function test_invoke_never_runs_a_write_and_overwrites_identity(): void {
		$GLOBALS['zdz_test_user_roles']   = array( 5 => array( 'zdz_owner' ) );
		$GLOBALS['zdz_test_current_user'] = 5;
		$seen                             = new ArrayObject();
		$GLOBALS['zdz_test_filters']['zdz_register_capabilities'] = function ( $caps ) use ( $seen ) {
			$caps['demo.write'] = array( 'callable' => function ( $a ) use ( $seen ) { $seen['ran'] = true; return array( 'success' => true ); }, 'tier' => 'viewer' );
			$caps['demo.read']  = array( 'callable' => function ( $a ) { return array( 'success' => true, 'who' => $a['user_id'] ); }, 'tier' => 'viewer', 'side_effect' => false );
			return $caps;
		};
		ZDZ_Capabilities::flush();
		$w = ZDZ_Capabilities::invoke( 'demo.write' );
		$this->assertTrue( $w['needs_confirm'] );
		$this->assertArrayNotHasKey( 'ran', $seen->getArrayCopy() );
		$r = ZDZ_Capabilities::invoke( 'demo.read', array( 'user_id' => 999 ) );
		$this->assertSame( 5, $r['who'], 'the caller can never name who is asking' );
	}

	/* ── Person colour ────────────────────────────────────────────────────── */

	public function test_person_tones_are_stable_and_chosen_tones_kept(): void {
		$pal = ZDZ_Person_Color::palette();
		$out = ZDZ_Person_Color::assign( array(
			3 => array( 'initials' => 'CC', 'chosen' => '', 'roles' => array( 'zdz_sales' ) ),
			1 => array( 'initials' => 'AA', 'chosen' => strtolower( $pal[0] ), 'roles' => array( 'zdz_owner' ) ),
			2 => array( 'initials' => 'BB', 'chosen' => '', 'roles' => array( 'zdz_general' ) ),
		) );
		$this->assertSame( $pal[0], $out[1]['color'] );
		$this->assertTrue( $out[1]['chosen'] );
		$this->assertSame( 'hexagon', $out[1]['shape'] );
		$this->assertSame( 'kiosk', $out[2]['shape'] );
		$this->assertSame( ZDZ_Person_Color::KIOSK['color'], $out[2]['color'] );
		$this->assertSame( $pal[1], $out[3]['color'], 'the next free tone, not the chosen one' );
		$this->assertSame( 'triangle', $out[3]['shape'] );
	}

	public function test_bad_palette_filter_falls_back(): void {
		$GLOBALS['zdz_test_filters']['zdz_person_tones'] = function () { return array( 'red' => 'nope' ); };
		ZDZ_Person_Color::flush();
		$this->assertSame( array_keys( ZDZ_Person_Color::TONES ), ZDZ_Person_Color::palette() );
	}

	/* ── App groups ───────────────────────────────────────────────────────── */

	public function test_business_moves_apps_and_renames_groups_but_not_colours(): void {
		$GLOBALS['zdz_test_options']['zdz_app_groups'] = ZDZ_App_Groups::sanitize( array(
			'apps'   => array( 'invoice' => 'sales', 'game' => 'not-a-group' ),
			'labels' => array( 'office' => '<b>Back Office</b>', 'nope' => 'x' ),
		) );
		$this->assertSame( 'sales', ZDZ_App_Groups::group_for( 'invoice' ) );
		$this->assertSame( 'tools', ZDZ_App_Groups::group_for( 'game' ), 'an unknown group leaves the default' );
		$g = ZDZ_App_Groups::groups();
		$this->assertSame( 'Back Office', $g['office']['label'] );
		$this->assertSame( ZDZ_App_Groups::GROUPS['office']['color'], $g['office']['color'] );
		$cfg = ZDZ_App_Groups::apply( array( 'cc' => '#123456', 'cat' => 'Finance' ), 'invoice' );
		$this->assertSame( '#123456', $cfg['cc_plugin'] );
		$this->assertSame( ZDZ_App_Groups::GROUPS['sales']['color'], $cfg['cc'] );
		$this->assertStringNotContainsString( ZDZ_App_Groups::NEEDS_YOU, implode( ',', array_column( ZDZ_App_Groups::GROUPS, 'color' ) ), 'the reserved colour is never a group colour' );
	}

	public function test_new_app_is_grouped_by_its_declared_category(): void {
		$this->assertSame( 'office', ZDZ_App_Groups::group_for( 'some-new-app', 'Finance' ) );
		$this->assertSame( 'tools', ZDZ_App_Groups::group_for( 'some-new-app', '' ) );
	}

	/* ── Consent ──────────────────────────────────────────────────────────── */

	public function test_consent_off_means_nobody_has_agreed_and_records_carry_on(): void {
		$GLOBALS['zdz_test_options']['zdz_consent_card_on'] = '0';
		$this->assertFalse( ZDZ_Consent::has( 'usage_records', 4 ) );
		$this->assertTrue( ZDZ_Consent::may_record( 'usage_records', 4 ) );
		$this->assertFalse( ZDZ_Consent::may_record( 'usage_records', 0 ), 'a signed-out request is never recorded' );
	}

	public function test_consent_on_requires_the_current_version(): void {
		$GLOBALS['zdz_test_options']['zdz_consent_card_on'] = '1';
		$GLOBALS['zdz_test_user_meta'] = array(
			4 => array( 'zdz_consent' => array( 'version' => ZDZ_Consent::VERSION, 'accepted_at' => '2026-01-01T00:00:00Z' ) ),
			6 => array( 'zdz_consent' => array( 'version' => ZDZ_Consent::VERSION + 5, 'accepted_at' => '2026-01-01T00:00:00Z' ) ),
		);
		$this->assertTrue( ZDZ_Consent::has( 'usage_records', 4 ) );
		$this->assertFalse( ZDZ_Consent::has( 'usage_records', 6 ), 'agreeing to another version is not agreeing to this one' );
		$this->assertFalse( ZDZ_Consent::may_record( 'usage_records', 3 ), 'card on, no answer: not recorded' );
		$this->assertFalse( ZDZ_Consent::has( 'no_such_scope', 4 ) );
	}

	public function test_default_notice_names_the_business_not_a_placeholder(): void {
		$w = ZDZ_Consent::wording();
		$this->assertStringNotContainsString( '{business}', $w );
		$this->assertCount( 3, preg_split( "/\n\n/", $w ) );
	}

	/* ── Crypto ───────────────────────────────────────────────────────────── */

	public function test_each_format_round_trips_exactly(): void {
		$key = 'sk-test-' . str_repeat( 'a1B2', 8 );
		foreach ( array( ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::PAIR ) as $fmt ) {
			$enc = ZDZ_Core_Crypto::encode( $key, $fmt );
			$this->assertNotSame( $key, $enc );
			$this->assertSame( array( $fmt, $key ), ZDZ_Core_Crypto::decode( $enc ) );
		}
		$this->assertSame( array( ZDZ_Core_Crypto::PLAIN, $key ), ZDZ_Core_Crypto::decode( $key ) );
		$this->assertSame( array( 'empty', '' ), ZDZ_Core_Crypto::decode( '' ) );
		$this->assertSame( array( 'vault', null ), ZDZ_Core_Crypto::decode( 'v1s:abc' ) );
	}

	public function test_long_foreign_ciphertext_is_not_a_key(): void {
		$this->assertSame( 'undecodable', ZDZ_Core_Crypto::decode( str_repeat( 'QUJD', 30 ) )[0] );
		$this->assertSame( '', ZDZ_Core_Crypto::plain( str_repeat( 'QUJD', 30 ) ) );
		$this->assertSame( 8, strlen( ZDZ_Core_Crypto::fingerprint( 'x' ) ) );
	}
}
