<?php
/**
 * Doorhangers and the Route Planner: the decisions that keep a door-hanger team safe and the data honest.
 *
 *   - No Soliciting: a noted house is left out of every route list; a site's own list can add houses, never take one
 *     off; a planned route never carries one.
 *   - Areas and addresses: a regional layer's own field names map onto the canonical shape; a bad source is refused;
 *     street and house keys are stable; a house inside a drawn loop is picked.
 *   - Pace: walking's limit is always below driving's; a cleared number box keeps the stored value.
 *   - Routes: the planner's walks keep their order and drop repeats; the two canvassing tags always count against a
 *     route; the Core vocabulary names no product.
 *   - Codes: without the vault (or with it turned off), a code is cut and never kept in plain text.
 *   - People: the shared device never holds the app; signed-out never does; the walker opens a code only on the
 *     route's day.
 *
 * Neutral data only. Every stub here reads the shared globals and is guarded, as the other unit files do.
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
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $cb = false ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/doorhangers/app.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/route-planner/app.php';

final class W9DoorRouteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'] );
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'flush' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	private static function house( $number, $street, $unit = '' ) {
		return array( 'number' => (string) $number, 'street' => $street, 'unit' => $unit, 'lat' => 1.0, 'lng' => 1.0 );
	}

	/* ── No Soliciting ─────────────────────────────────────────────────────────── */

	public function test_no_solicit_house_is_left_out_of_a_route_list(): void {
		$keys = array( ZDH_DB::match_key( '120', 'Oak Hollow Drive' ) => array( 'match_key' => 'x' ) );
		list( $kept, $out ) = ZDH_NoSolicit::without(
			array( self::house( 118, 'Oak Hollow Dr' ), self::house( 120, 'OAK HOLLOW DR.' ), self::house( 122, 'Oak Hollow Dr' ) ),
			$keys
		);
		$this->assertSame( 1, $out, 'the noted house is matched however its street is written' );
		$this->assertSame( array( '118', '122' ), array_column( $kept, 'number' ) );
	}

	public function test_no_solicit_unit_and_stored_rows_match_by_key(): void {
		$keys = array( ZDH_DB::match_key( '7', 'Maple Ave', 'B' ) => true );
		list( $kept, $out ) = ZDH_NoSolicit::without(
			array(
				array( 'house_number' => '7', 'street' => 'Maple Avenue', 'unit' => 'b' ),   // a stored address row
				array( 'number' => '7', 'street' => 'Maple Ave', 'unit' => 'A' ),            // the other unit stays
				array( 'match_key' => ZDH_DB::match_key( '7', 'Maple Ave', 'B' ) ),           // a row that carries its key
			),
			$keys
		);
		$this->assertSame( 2, $out );
		$this->assertCount( 1, $kept );
		$this->assertSame( 'A', $kept[0]['unit'] );
	}

	public function test_a_site_list_can_add_houses_but_never_remove_a_recorded_one(): void {
		$recorded = array( '10 oak st' => array( 'match_key' => '10 oak st', 'full_address' => '10 Oak St', 'created_at' => '2026-01-05' ) );
		$wider    = ZDH_NoSolicit::widen( $recorded, array( '12 OAK ST', array( 'match_key' => '14 oak st' ), '' ) );
		$this->assertArrayHasKey( '10 oak st', $wider, 'a recorded sign is never dropped' );
		$this->assertArrayHasKey( '12 oak st', $wider );
		$this->assertArrayHasKey( '14 oak st', $wider );
		$this->assertSame( '10 Oak St', $wider['10 oak st']['full_address'], 'an added entry never overwrites a recorded one' );
		$this->assertSame( $recorded, ZDH_NoSolicit::widen( $recorded, null ), 'a filter that answers nothing adds nothing' );
	}

	/* ── Areas and addresses ───────────────────────────────────────────────────── */

	public function test_regional_fields_map_onto_the_canonical_shape(): void {
		$GLOBALS['zdz_test_options'][ ZDH_Geo::SOURCE_OPTION ] = array(
			'base'   => 'https://gis.example.com/arcgis/rest/services/',
			'layers' => array( 'addresses' => 'Addr_Points', 'roads' => 'Road_Lines', 'zips' => 'Postal' ),
			'fields' => array( 'addresses' => array( 'number' => 'HOUSE_NO', 'name' => 'ST_NAME', 'suffix' => 'ST_TYPE', 'zip' => 'POSTCODE', 'community' => 'TOWN' ) ),
		);
		$this->assertTrue( ZDH_Geo::configured() );
		$this->assertSame( 'ST_NAME', ZDH_Geo::f( 'addresses', 'name' ) );
		$this->assertSame( 'unit', ZDH_Geo::f( 'addresses', 'unit' ), 'an unmapped field keeps its canonical name' );
		$json = ZDH_Geo::canon( 'addresses', array( 'features' => array(
			array( 'attributes' => array( 'HOUSE_NO' => 41, 'ST_NAME' => 'OAK HOLLOW', 'ST_TYPE' => 'DR', 'POSTCODE' => '00001', 'TOWN' => 'EXAMPLETON', 'SECRET' => 'x' ), 'geometry' => array( 'x' => -1.5, 'y' => 2.25 ) ),
		) ) );
		$a = ZDH_Geo::addresses_from_features( $json['features'] );
		$this->assertCount( 1, $a );
		$this->assertSame( '41', $a[0]['number'] );
		$this->assertSame( 'Oak Hollow Dr', $a[0]['street'] );
		$this->assertSame( 'Exampleton', $a[0]['city'] );
		$this->assertSame( '00001', $a[0]['zip'] );
		$this->assertSame( 2.25, $a[0]['lat'] );
		$this->assertArrayNotHasKey( 'SECRET', $json['features'][0]['attributes'], 'a field the map does not name is dropped' );
	}

	public function test_no_region_ships_and_a_bad_source_is_refused(): void {
		$this->assertFalse( ZDH_Geo::configured(), 'Core names no regional address layer' );
		$clean = ZDH_Geo::clean_source( array(
			'base'   => 'http://insecure.example.com/x/',
			'path'   => '../../etc',
			'layers' => array( 'addresses' => 'A B; DROP' ),
			'fields' => array( 'addresses' => array( 'name' => "x' OR 1=1" ) ),
			'bbox'   => array( 5, 5, 1, 1 ),
		) );
		$this->assertSame( '', $clean['base'], 'only https' );
		$this->assertSame( 'FeatureServer/0/query', $clean['path'] );
		$this->assertSame( '', $clean['layers']['addresses'] );
		$this->assertSame( 'name', $clean['fields']['addresses']['name'], 'a field name that is not an identifier is ignored' );
		$this->assertSame( array(), $clean['bbox'] );
		$this->assertSame( "'O''Brien'", ZDH_Geo::sql_str( "O'Brien" ) );
	}

	public function test_street_and_house_keys_are_stable(): void {
		$this->assertSame( ZDH_DB::street_key( 'North Oak Hollow Drive' ), ZDH_DB::street_key( 'n oak hollow dr.' ) );
		$this->assertSame( ZDH_DB::street_key( 'Mount Example Ave' ), ZDH_DB::street_key( 'Mt Example Avenue' ) );
		$this->assertSame( '41|b|oak hollow dr|00001', ZDH_Plan::house_key( '41', 'B', 'oak hollow dr', '00001-1234' ) );
		$this->assertSame( '00001', ZDH_Plan::key_zip( '41||oak hollow dr|00001' ) );
		$this->assertSame( '', ZDH_Plan::key_zip( '41||oak hollow dr' ) );
	}

	public function test_a_house_inside_a_drawn_loop_is_picked(): void {
		$loop = array( array( 0.0, 0.0 ), array( 0.0, 1.0 ), array( 1.0, 1.0 ), array( 1.0, 0.0 ) );
		$this->assertTrue( ZDH_Geo::point_in_poly( 0.5, 0.5, $loop ) );
		$this->assertFalse( ZDH_Geo::point_in_poly( 1.5, 0.5, $loop ) );
		$this->assertLessThan( 1.0, ZDH_Geo::dist_to_path_m( 0.0, 0.5, array( array( 0.0, 0.0 ), array( 0.0, 1.0 ) ) ) );
	}

	public function test_typed_address_strips_the_business_state_only(): void {
		$GLOBALS['zdz_test_filters']['zdh_state_words'] = function () { return array( 'ST', 'Example State' ); };
		list( $n, $st, $unit, $zip ) = ZDH_DB::parse_address_line( "4100 Example Ct\nExampleton, ST 00000 US", array( 'Exampleton' ) );
		$this->assertSame( array( '4100', 'Example Ct', '', '00000' ), array( $n, $st, $unit, $zip ) );
		$area = ZRP_Plan::read_area( '4100 Example Dr 00000' );
		$this->assertSame( 'address', $area['kind'] );
		$this->assertSame( 'Example Dr', $area['street'], 'a street type is never taken for a state' );
		$this->assertSame( '00000', $area['zip'] );
	}

	public function test_area_defaults_come_from_settings_never_a_built_in_place(): void {
		$a = zdh_area_defaults();
		$this->assertSame( '', $a['city'] );
		$this->assertSame( '', $a['county'] );
		$this->assertNull( zdh_parse_point( '' ) );
		$this->assertNull( zdh_parse_point( '95,10' ), 'off the globe' );
		$GLOBALS['zdz_test_options']['zdh_settings'] = array( 'default_city' => 'Exampleton', 'map_center' => '12.34567,-45.6' );
		$this->assertSame( 'Exampleton', zdh_area_defaults()['city'] );
		$this->assertSame( array( 12.3457, -45.6 ), zdh_map_center() );
	}

	/* ── Pace ──────────────────────────────────────────────────────────────────── */

	public function test_walking_limit_is_always_below_driving(): void {
		$l = zdh_pace_limits_from( array( 'pace_drive_mph' => 4, 'pace_walk_mph' => 9 ) );
		$this->assertSame( 4.0, $l['driveMph'] );
		$this->assertSame( 3.5, $l['walkMph'], 'walking sits half a mile an hour under driving' );
		$l = zdh_pace_limits_from( array( 'pace_drive_mph' => 500, 'pace_drive_s' => 1, 'pace_acc_m' => 999, 'pace_jump_m' => 1 ) );
		$this->assertSame( 30.0, $l['driveMph'] );
		$this->assertSame( 5, $l['driveS'] );
		$this->assertSame( 50, $l['accM'] );
		$this->assertSame( 100, $l['jumpM'] );
		$d = zdh_pace_limits_from( array() );
		$this->assertLessThan( $d['driveMph'], $d['walkMph'] );
	}

	public function test_a_cleared_number_box_keeps_the_stored_value(): void {
		$GLOBALS['zdz_test_options']['zdh_settings'] = array( 'pace_drive_mph' => 7, 'walk_rate' => 55 );
		$out = zdh_sanitize_settings( array( 'zdh_form' => '1', 'pace_drive_mph' => '', 'walk_rate' => 'abc', 'pace_walk_mph' => '8', 'map_center' => 'nowhere' ) );
		$this->assertSame( 7, $out['pace_drive_mph'] );
		$this->assertSame( 55, $out['walk_rate'] );
		$this->assertSame( 6.5, $out['pace_walk_mph'], 'walking clamped under driving' );
		$this->assertSame( '', $out['map_center'] );
		$this->assertSame( 0, $out['census_on'], 'an unticked box on the form is off' );
		$this->assertSame( 'Standard', $out['hanger_types'] );
	}

	public function test_planning_pace_follows_the_setting_and_its_rule(): void {
		$GLOBALS['zdz_test_options']['zdh_settings'] = array( 'walk_rate' => 60 );
		$this->assertSame( 1.0, ZDH_Plan::min_per_house() );
		$this->assertSame( 60.0, ZRP_Plan::per_hour() );
		$GLOBALS['zdz_test_filters']['zdh_plan_minutes_per_house'] = function () { return 2; };
		$this->assertSame( 2.0, ZDH_Plan::min_per_house() );
		$this->assertSame( 30.0, ZRP_Plan::per_hour() );
	}

	/* ── Routes and tags ───────────────────────────────────────────────────────── */

	public function test_planner_walks_keep_order_and_drop_repeats(): void {
		$skipped = 0;
		$w = ZDH_Plan::clean_walks( array( array( 'B|x', 'a|x', 'b|x' ), array( 'c|x', 7, '' ), array() ), $skipped );
		$this->assertSame( array( array( 'b|x', 'a|x' ), array( 'c|x' ) ), $w );
		$this->assertSame( 2, $skipped );
		$this->assertNull( ZDH_Plan::clean_walks( null ) );
		$this->assertFalse( ZDH_Plan::clean_walks( array( 'not-a-walk' ) ) );
	}

	public function test_canvassing_tags_always_count_against_a_route(): void {
		$GLOBALS['zdz_test_filters']['zrp_plan_negative_tags'] = function () { return array( 'busy_road' ); };
		$this->assertSame( array( 'no_solicit_signs', 'hoa_gated', 'busy_road' ), zrp_plan_negative_tags() );
		$GLOBALS['zdz_test_filters']['zrp_plan_negative_tags'] = function () { return array(); };
		$this->assertSame( array( 'no_solicit_signs', 'hoa_gated' ), zrp_plan_negative_tags(), 'a filter cannot take them off' );
	}

	public function test_core_vocabulary_names_no_product_and_a_pack_adds_its_own(): void {
		$v = ZRP_Vocab::vocabulary();
		$this->assertSame( array( 'hoa_gated', 'no_solicit_signs' ), array_keys( $v ) );
		foreach ( $v as $row ) {
			$this->assertSame( '', $row['product'] );
		}
		$GLOBALS['zdz_test_options']['zrp_vocabulary'] = array(
			'pools'            => array( 'Pools', 'example_product', 'Example product', array( 'pool', 'swimming pool' ), array( 'ask' ), array( 'pools_above', 'Not a slug!' ) ),
			'no_solicit_signs' => array( 'Changed', 'example_product', 'Example product', array( 'keep out' ) ),
		);
		$v = ZRP_Vocab::vocabulary();
		$this->assertSame( 'example_product', $v['pools']['product'] );
		$this->assertSame( array( 'pools_above', 'not_a_slug' ), $v['pools']['choices'] );
		$this->assertSame( '', $v['no_solicit_signs']['product'], 'a Core tag never gets a product line' );
		$this->assertContains( 'keep out', $v['no_solicit_signs']['phrases'] );
		$p = ZRP_Vocab::parse( 'there is a no soliciting sign on this street' );
		$this->assertContains( 'no_solicit_signs', $p['tags'] );
	}

	public function test_route_planner_settings_are_clamped(): void {
		$s = zrp_clean_settings( array( 'rehang_days' => 0, 'wx_rain_pct' => 300, 'wx_gust_mph' => -4, 'record' => '' ) );
		$this->assertSame( 1, $s['rehang_days'] );
		$this->assertSame( 100, $s['wx_rain_pct'] );
		$this->assertSame( 0, $s['wx_gust_mph'] );
		$this->assertSame( 0, $s['record'] );
		$this->assertSame( 0, $s['everyone'], 'the role grant starts off' );
	}

	public function test_the_role_grant_adds_only_when_on_and_never_to_the_shared_device(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_general' ), 8 => array( 'zdz_sales' ), 9 => array( 'subscriber' ) );
		$off = zrp_clean_settings( array() );
		$on  = zrp_clean_settings( array( 'everyone' => 1 ) );
		$this->assertSame( array( 'hours' ), zrp_role_grant( array( 'hours' ), 8, $off ), 'off: nothing is added' );
		$this->assertSame( array( 'hours', ZRP_APP_ID ), zrp_role_grant( array( 'hours' ), 8, $on ), 'on: sales hold it' );
		$this->assertSame( array(), zrp_role_grant( array(), 7, $on ), 'never the shared device' );
		$this->assertSame( array(), zrp_role_grant( array(), 9, $on ), 'never a role it is not offered to' );
		$this->assertSame( array(), zrp_role_grant( array(), 0, $on ), 'never signed out' );
		$this->assertSame( array( ZRP_APP_ID ), zrp_role_grant( array( ZRP_APP_ID ), 8, $on ), 'never twice' );
	}

	/* ── Codes: fail closed ────────────────────────────────────────────────────── */

	public function test_codes_are_cut_and_never_kept_without_the_vault(): void {
		$GLOBALS['zdz_test_filters']['zdh_vault_on'] = function () { return false; };
		$this->assertFalse( ZDH_Sealed::on() );
		$this->assertFalse( ZRP_Codes::vault_on() );
		foreach ( array( 'Gate code 4821#', 'lockbox 5521 by the side door', 'Alarm: 1 2 3 4', 'the gate code is four five six seven', 'code: #1984' ) as $t ) {
			$cut = ZDH_Sealed::seal( $t, 12 );
			$this->assertDoesNotMatchRegularExpression( '/\d{3}|four five/', preg_replace( '/route #\d+/', '', $cut ), $t );
			$this->assertStringContainsString( 'code removed]', $cut, 'the words say a code was cut, not sealed' );
			$this->assertSame( $cut, ZDH_Sealed::cut( $t ) );
		}
		$this->assertSame( 'Meet at 1450 Oak St', ZDH_Sealed::cut( 'Meet at 1450 Oak St' ) );
		$this->assertSame( 'Gate at 1450 Oak St', ZDH_Sealed::cut( 'Gate at 1450 Oak St' ), 'a house number is not a code' );
		$this->assertSame( 'zip code 00000', ZDH_Sealed::cut( 'zip code 00000' ) );
		$deep = ZDH_Sealed::deep( array( 'notes' => 'gate code 7777', 'n' => 3, 'list' => array( 'pin 9090' ) ) );
		$this->assertStringNotContainsString( '7777', $deep['notes'] );
		$this->assertStringNotContainsString( '9090', $deep['list'][0] );
		$f = ZDH_Sealed::fields( array( 'notes' => 'keypad 2468', 'name' => 'Route 1' ), 5 );
		$this->assertStringNotContainsString( '2468', $f['notes'] );
		$this->assertSame( array(), ZDH_Sealed::for_drop( array( 'id' => 5, 'notes' => '[gate code sealed]' ), 3 ), 'nothing to show without the vault' );
		$this->assertStringNotContainsString( '5521', ZRP_Codes::cut( 'lockbox 5521' ), 'the Route Planner cuts the same way' );
	}

	public function test_the_vault_is_never_assumed_present(): void {
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'ready' ) && ZDZ_Sealed::ready() ) {
			$this->assertTrue( ZDH_Sealed::on() );
			return;
		}
		$this->assertFalse( ZDH_Sealed::on(), 'no vault or a vault that is not ready: the fallback cuts' );
		$this->assertStringNotContainsString( '4821', ZDH_Sealed::seal( 'Gate code 4821', 9 ) );
	}

	/* ── People ────────────────────────────────────────────────────────────────── */

	public function test_signed_out_and_the_shared_device_never_hold_the_apps(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_general' ), 8 => array( 'zdz_sales' ) );
		$this->assertFalse( zdh_user_can_use( 0 ) );
		$this->assertFalse( zrp_user_can_use( -3 ) );
		$this->assertTrue( zdh_is_kiosk( 7 ) );
		$this->assertFalse( zdh_user_can_use( 7 ), 'the shared device never holds Doorhangers' );
		$this->assertFalse( zrp_user_can_use( 7 ), 'nor the Route Planner' );
		$this->assertFalse( zdh_user_is_admin( 7 ) );
		$this->assertFalse( zdh_user_is_admin( 8 ), 'sales is not the admin tier' );
		$this->assertFalse( zdh_is_kiosk( 8 ) );
		if ( ! class_exists( 'ZDZ_Plugin_API' ) ) {
			$GLOBALS['zdz_test_user_roles'][9] = array( 'subscriber' );
			$this->assertTrue( zdh_user_can_use( 8 ), 'a role the app is offered to holds it' );
			$this->assertFalse( zdh_user_can_use( 9 ), 'any other role gets nothing' );
		}
	}

	public function test_the_walker_opens_a_code_only_on_the_routes_day(): void {
		$route = array( 'assigned_uid' => 4, 'status' => 'planned', 'due_by' => '2026-01-05', 'drop_date' => '2026-01-01', 'started_at' => null );
		$this->assertTrue( ZDH_Sealed::walker_today( $route, 4, '2026-01-05' ) );
		$this->assertFalse( ZDH_Sealed::walker_today( $route, 4, '2026-01-01' ), 'the due day, not the planned day' );
		$this->assertFalse( ZDH_Sealed::walker_today( $route, 5, '2026-01-05' ), 'only the assigned walker' );
		$this->assertFalse( ZDH_Sealed::walker_today( $route, 0, '2026-01-05' ) );
		$started = array( 'assigned_uid' => 4, 'status' => 'in_progress', 'due_by' => '', 'drop_date' => '2026-01-01', 'started_at' => '2026-01-07 09:10:00' );
		$this->assertTrue( ZDH_Sealed::walker_today( $started, 4, '2026-01-07' ) );
		$this->assertFalse( ZDH_Sealed::walker_today( array( 'status' => 'done' ) + $route, 4, '2026-01-05' ), 'a finished route opens nothing' );
		$this->assertFalse( ZDH_Sealed::may_open( false, array( 'scope_type' => 'job', 'scope_id' => 'drop:1' ), 4 ), 'another scope is not this app\'s to answer' );
	}

	public function test_doorhangers_offers_no_scan_source_nothing_reads(): void {
		// Nothing in the platform runs a scan of older rows, so Doorhangers offers none: its rows are sealed on the
		// way in (insert, plan, edit, finish) and that is the whole path.
		$this->assertFalse( method_exists( 'ZDH_Sealed', 'scan_sources' ) );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/doorhangers/includes/class-zdh-sealed.php' );
		$this->assertStringNotContainsString( 'zdz_sealed_scan_sources', $src );
	}
}
