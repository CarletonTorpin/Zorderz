<?php
/**
 * The Charts app's own rules, without WordPress: the sealed envelope (with throwaway keys made
 * here), who may see what, the hub connection's states, and the cleaners that stand between a
 * browser or the chat and the hub.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ZCH_WEB_PARTY' ) ) {
	define( 'ZCH_WEB_PARTY', 'zorderz-site' );
}
if ( ! defined( 'ZCH_HUB_PARTY' ) ) {
	define( 'ZCH_HUB_PARTY', 'charts-hub' );
}
if ( ! defined( 'ZDZ_REST_NS' ) ) {
	define( 'ZDZ_REST_NS', 'zorderz/v1' );
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}

$zdz_test_ch_dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/charts/includes/';
foreach ( array( 'envelope', 'config', 'access', 'hub-client', 'store', 'counts', 'prospect', 'chat-bridge', 'survey-verb', 'local', 'territories' ) as $zdz_test_ch_f ) {
	require_once $zdz_test_ch_dir . 'class-zch-' . $zdz_test_ch_f . '.php';
}

/** A replay check that remembers nonces in memory (the site uses transients). */
final class ZDZ_Test_Ch_Replay {
	public $seen = array();
	public function __invoke( $nonce ) {
		if ( isset( $this->seen[ $nonce ] ) ) {
			return false;
		}
		$this->seen[ $nonce ] = true;
		return true;
	}
}

final class ChartsAppTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_roles']  = array();
		$GLOBALS['zdz_test_user_meta']   = array();
		$GLOBALS['zdz_test_transients']  = array();
		$GLOBALS['zdz_test_home']        = 'https://example.com';
		unset( $GLOBALS['zdz_test_filters']['zch_role_defaults'] );
		ZCH_Access::flush();
		ZCH_Config::$stored = null;
		self::forget_staging_decision();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zch_role_defaults'], $GLOBALS['zdz_test_home'] );
		ZCH_Config::$stored = null;
		ZCH_Access::flush();
		self::forget_staging_decision();
		unset( $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'] );
	}

	/** The staging guard keeps one decision per request; when another file loaded it, decide again for this host. */
	private static function forget_staging_decision(): void {
		if ( class_exists( 'ZDZ_Staging_Guard' ) && property_exists( 'ZDZ_Staging_Guard', 'status' ) ) {
			$p = new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}

	private function pair(): array {
		list( $web_sk, $web_pk ) = ZCH_Envelope::keygen();
		list( $hub_sk, $hub_pk ) = ZCH_Envelope::keygen();
		$web = new ZCH_Envelope( ZCH_WEB_PARTY, $web_sk, array( ZCH_HUB_PARTY => array( $hub_pk ) ), new ZDZ_Test_Ch_Replay() );
		$hub = new ZCH_Envelope( ZCH_HUB_PARTY, $hub_sk, array( ZCH_WEB_PARTY => array( $web_pk ) ), new ZDZ_Test_Ch_Replay() );
		return array( $web, $hub );
	}

	/* ── the envelope ─────────────────────────────────────────────── */

	public function test_envelope_round_trip_and_shape(): void {
		list( $web, $hub ) = $this->pair();
		$out = $web->seal( ZCH_HUB_PARTY, 'request', array( 'op' => 'health' ), 'rid-1' );
		$this->assertSame( array( 'ct', 'kid_from', 'kid_to', 'nonce', 'v' ), $this->sorted_keys( $out ) );
		$this->assertSame( 1, $out['v'] );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $out['ct'], 'base64url without padding' );
		$inner = $hub->open( $out, ZCH_WEB_PARTY, 'request' );
		$this->assertSame( array( 'op' => 'health' ), $inner['body'] );
		$back = $web->open( $hub->seal( ZCH_WEB_PARTY, 'response', array( 'ok' => true ), $inner['rid'] ), ZCH_HUB_PARTY, 'response', 'rid-1' );
		$this->assertSame( array( 'ok' => true ), $back['body'] );
	}

	public function test_envelope_refuses_replay_tamper_and_strangers(): void {
		list( $web, $hub ) = $this->pair();
		$out = $web->seal( ZCH_HUB_PARTY, 'request', array( 'n' => 1 ) );
		$hub->open( $out, ZCH_WEB_PARTY, 'request' );
		$this->assertSame( 'replay', $this->open_error( $hub, $out, 'request' ) );

		$out2       = $web->seal( ZCH_HUB_PARTY, 'request', array( 'n' => 2 ) );
		$ct         = ZCH_Envelope::b64d( $out2['ct'] );
		$ct[0]      = chr( ord( $ct[0] ) ^ 1 );
		$out2['ct'] = ZCH_Envelope::b64e( $ct );
		$this->assertSame( 'cannot open', $this->open_error( $hub, $out2, 'request' ) );

		$this->assertSame( 'wrong message type', $this->open_error( $hub, $web->seal( ZCH_HUB_PARTY, 'request', array() ), 'response' ) );

		list( $stranger_sk ) = ZCH_Envelope::keygen();
		$stranger           = new ZCH_Envelope( ZCH_WEB_PARTY, $stranger_sk, array( ZCH_HUB_PARTY => array( ZCH_Envelope::b64e( sodium_crypto_box_publickey_from_secretkey( ZCH_Envelope::b64d( $stranger_sk ) ) ) ) ) );
		$this->assertSame( 'wrong version or not addressed to this key', $this->open_error( $hub, $stranger->seal( ZCH_HUB_PARTY, 'request', array() ), 'request' ) );

		$old = $web->seal( ZCH_HUB_PARTY, 'request', array(), null, time() - 600 );
		$this->assertSame( 'stale or future timestamp', $this->open_error( $hub, $old, 'request' ) );
	}

	public function test_envelope_rejects_bad_keys_and_parties(): void {
		$this->expectException( ZCH_Envelope_Error::class );
		new ZCH_Envelope( ZCH_WEB_PARTY, 'too-short', array() );
	}

	public function test_envelope_key_id_and_transient_replay(): void {
		list( $web ) = $this->pair();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', ZCH_Envelope::key_id( 'x' ) );
		$this->assertTrue( $web->transient_replay_check( 'nonce-a' ) );
		$this->assertFalse( $web->transient_replay_check( 'nonce-a' ), 'A nonce works once in the window.' );
		foreach ( array_keys( $GLOBALS['zdz_test_transients'] ) as $k ) {
			$this->assertMatchesRegularExpression( '/^zch_n_[0-9a-f]{32}$/', $k );
		}
	}

	/* ── who may see what ─────────────────────────────────────────── */

	private function plain_defaults(): void {
		// The theme switches resolved by hand, so the test does not depend on the platform's permissions class.
		$GLOBALS['zdz_test_filters']['zch_role_defaults'] = function ( $d ) {
			foreach ( $d as $role => $keys ) {
				foreach ( $keys as $k => $v ) {
					if ( is_string( $v ) && 0 === strpos( $v, 'theme:' ) ) {
						$d[ $role ][ $k ] = 'zdz_sales' === $role && 'charts_money' === $k ? 'allow' : ( 'zdz_sales' === $role ? 'deny' : 'allow' );
					}
				}
			}
			$d['zdz_general'] = $d['zdz_owner'];      // a filter can never lift the shared device's floor.
			return $d;
		};
	}

	public function test_access_least_privilege(): void {
		$this->plain_defaults();
		$GLOBALS['zdz_test_user_roles'] = array( 1 => array( 'zdz_owner' ), 2 => array( 'zdz_sales' ), 3 => array( 'subscriber' ), 4 => array( 'zdz_general' ), 5 => array( 'zdz_owner', 'zdz_sales' ) );
		$GLOBALS['zdz_test_user_meta']  = array( 2 => array( 'zdz_user_initials' => 'am' ), 5 => array( 'zdz_user_initials' => 'JB' ) );

		$this->assertSame( 'allow', ZCH_Access::resolve( 1 )['charts_company'] );
		$this->assertSame( array( 'deny' ), array_values( array_unique( ZCH_Access::resolve( 3 ) ) ), 'An unknown role gets nothing.' );
		$this->assertSame( array( 'deny' ), array_values( array_unique( ZCH_Access::resolve( 4 ) ) ), 'The shared device gets nothing, whatever a filter says.' );
		$this->assertSame( 'zdz_sales', ZCH_Access::governing_role( get_userdata( 5 ) ), 'Several roles: the most restrictive governs.' );

		$owner = ZCH_Access::block( 1 );
		$this->assertNull( $owner['scope'] );
		$this->assertTrue( $owner['money'] );
		$this->assertContains( 'ask', $owner['ops'] );

		$sales = ZCH_Access::block( 2 );
		$this->assertSame( array( 'AM' ), $sales['scope'], 'A salesperson sees their own jobs only.' );
		$this->assertFalse( $sales['by_person'] );
		$this->assertSame( 'own', $sales['invoices'] );
		$this->assertSame( 'AM', $sales['me'] );
		$this->assertNotContains( 'data_status', $sales['ops'] );

		$bot = ZCH_Access::block( 1, 'bot' );
		$this->assertSame( array(), array_values( array_diff( $bot['ops'], array( 'health', 'vocab', 'run_spec', 'run_v2' ) ) ) );
		$this->assertSame( 'none', $bot['line_items'] );
		$this->assertFalse( $bot['accounts'] );

		$this->assertSame( array( 'health' ), ZCH_Access::block( 3 )['ops'] );
		$this->assertSame( array( 'health' ), ZCH_Access::block( 1, 'stranger' )['ops'], 'An unknown caller gets health only.' );
	}

	public function test_access_overrides_and_line_item_denial(): void {
		$this->plain_defaults();
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_admin' ) );
		$GLOBALS['zdz_test_user_meta']  = array( 7 => array( 'zch_permissions' => array( 'charts_line_items' => 'deny', 'charts_money' => 'bogus' ) ) );
		$r                              = ZCH_Access::resolve( 7 );
		$this->assertSame( 'deny', $r['charts_line_items'] );
		$this->assertSame( 'allow', $r['charts_money'], 'An unreadable override falls back to the role default.' );
		$this->assertSame( 'none', ZCH_Access::invoice_breadth( 7 ), 'No line items, no invoice cards.' );
		$this->assertSame( 'deny', $r['charts_admin'], 'An admin does not get the data status by default.' );
	}

	/* ── the hub connection ───────────────────────────────────────── */

	public function test_config_states_and_live_host_lock(): void {
		if ( defined( 'ZCH_HUB_URL' ) || defined( 'ZCH_LIVE_HOSTS' ) ) {
			$this->markTestSkipped( 'Charts constants are defined in this process.' );
		}
		ZCH_Config::$stored = array();
		$this->assertSame( 'not_configured', ZCH_Config::state() );
		$this->assertNull( ZCH_Hub_Client::from_config(), 'No hub: local mode.' );
		$this->assertStringContainsString( 'local', strtolower( ZCH_Config::local_message() ) );

		list( $sk ) = ZCH_Envelope::keygen();
		list( , $pk ) = ZCH_Envelope::keygen();
		ZCH_Config::$stored = array( 'url' => 'https://hub.example.com/v1/rpc', 'site_secret' => $sk, 'hub_public' => $pk, 'live_hosts' => 'www.example.com' );
		$this->assertSame( 'not_live', ZCH_Config::state(), 'Deny by default: this host is not the named live site.' );

		ZCH_Config::$stored['live_hosts'] = 'Example.com, www.example.com';
		$staging = class_exists( 'ZDZ_Staging_Guard' ) && method_exists( 'ZDZ_Staging_Guard', 'is_active' ) && ZDZ_Staging_Guard::is_active();
		$this->assertSame( $staging ? 'not_live' : 'connected', ZCH_Config::state() );
		$this->assertSame( array( 'example.com', 'www.example.com' ), ZCH_Config::live_hosts() );
		$this->assertSame( 'the hub computer', ZCH_Config::hub_label() );
	}

	/* ── what the browser and the chat may send ───────────────────── */

	public function test_store_cleaners(): void {
		$spec = ZCH_Store::clean_spec(
			array(
				'measure'   => 'revenue',
				'split'     => 'product',
				'sneaky'    => 'x',
				'promo_mode' => 'strict',
				'zipfacts'  => array( 'view' => 'rate', 'per' => 'homes' ),
				'weather'   => 'rain',
			)
		);
		$this->assertArrayNotHasKey( 'sneaky', $spec );
		$this->assertSame( 'strict', $spec['promo_mode'], 'A pack switch (*_mode) rides along.' );
		$this->assertSame( array( 'view' => 'rate', 'per' => 'homes' ), $spec['zipfacts'] );
		$this->assertNull( ZCH_Store::clean_zipfacts( array( 'view' => 'shade' ) ), 'A shading needs a fact.' );
		$this->assertNull( ZCH_Store::clean_zipfacts( array( 'view' => 'rate', 'per' => 'cats' ) ) );
		$this->assertSame( 'shade', ZCH_Store::clean_zipfacts( array( 'view' => 'shade', 'fact' => 'vector_q8' ) )['view'] );
		$this->assertNull( ZCH_Store::clean_compare( array( 'as' => 'period', 'windows' => array( array( 'label' => 'A', 'from' => '2025-01-01', 'to' => '2024-01-01' ), array( 'label' => 'B', 'from' => '2025-01-01', 'to' => '2025-02-01' ) ) ) ), 'A window that ends before it starts is refused.' );
		$this->assertSame( 'zch_rl_9_202501020304', ZCH_Store::rate_key( 9, gmmktime( 3, 4, 5, 1, 2, 2025 ) ) );
	}

	public function test_counts_helpers(): void {
		$this->assertSame( '15558', ZCH_Counts::norm_no( '0015558' ) );
		$this->assertSame( '', ZCH_Counts::norm_no( '12 34' ) );
		$this->assertSame( 'Google Ads', ZCH_Counts::clean_label( '  Google   Ads ' ) );
		$this->assertSame( 'Other', ZCH_Counts::clean_label( 'call 555-0101' ) );
		$this->assertSame( 'Other', ZCH_Counts::clean_label( 'alex@example.com' ) );
		$map    = array_fill_keys( range( 1000, 1199 ), 'Google Ads' );
		$chunks = ZCH_Counts::chunk_bytes( $map, 1000 );
		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertSame( count( $map ), array_sum( array_map( 'count', $chunks ) ) );
		foreach ( $chunks as $c ) {
			$this->assertLessThanOrEqual( 1000, strlen( zch_json( $c ) ) );
		}
	}

	public function test_chat_specs_never_carry_names(): void {
		$v2 = ZCH_Chat_Bridge::clean_v2(
			array(
				'measure' => 'revenue',
				'series'  => array( array( 'filters' => array( 'accounts' => array( 'k1' ) ) ) ),
				'filters' => array( 'products' => array( 'Shade sails' ), 'Accounts' => array( 'k1' ), 'nested' => array( 'customer' => 'x' ) ),
				'bogus'   => 1,
			)
		);
		$this->assertArrayNotHasKey( 'series', $v2 );
		$this->assertArrayNotHasKey( 'bogus', $v2 );
		$this->assertSame( array( 'products' => array( 'Shade sails' ), 'nested' => array() ), $v2['filters'] );
		$this->assertTrue( ZCH_Chat_Bridge::asks_money( array( 'measure' => 'jobs', 'agg' => 'per_job' ) ) );
		$this->assertFalse( ZCH_Chat_Bridge::asks_money( array( 'measure' => 'jobs' ) ) );
		$this->assertTrue( ZCH_Chat_Bridge::asks_person( array( 'filters' => array( 'exclude_salespeople' => array( 'AM' ) ) ) ) );
	}

	public function test_prospect_recipes_are_bounded(): void {
		$r = ZCH_Prospect::clean_recipe( array( 'product' => 'Shade Sails!', 'ingredients' => array( 'inland' => 1, 'DROP TABLE' => 1, 'safety_area' => 0.5 ) ) );
		$this->assertSame( 'shade_sails', $r['product'] );
		$this->assertArrayNotHasKey( 'DROP TABLE', $r['ingredients'] ?? array() );
		$this->assertTrue( ZCH_Prospect::is_ingredient( 'safety_area' ) );
		$this->assertFalse( ZCH_Prospect::is_ingredient( 'Bad-Key' ) );
		$b = ZCH_Prospect::narrowed( array( 'money' => true, 'by_person' => true ), array( 'money' => false, 'by_person' => true ) );
		$this->assertSame( array( 'money' => false, 'by_person' => true ), $b, 'Only ever narrowed.' );
	}

	public function test_survey_recordings_come_only_from_this_site(): void {
		$tok = str_repeat( 'ab', 32 );
		$ok  = 'https://example.com/wp-json/zorderz/v1/route-planner/audio-fetch/12?k=' . $tok;
		$this->assertTrue( ZCH_Survey_Verb::own_url( $ok ) );
		$this->assertFalse( ZCH_Survey_Verb::own_url( str_replace( 'example.com', 'example.net', $ok ) ) );
		$this->assertFalse( ZCH_Survey_Verb::own_url( str_replace( 'https://', 'https://user:pw@', $ok ) ) );
		$this->assertFalse( ZCH_Survey_Verb::own_url( str_replace( '/12?', '/../12?', $ok ) ) );
		$this->assertFalse( ZCH_Survey_Verb::own_url( $ok . '&x=1' ) );
		$this->assertFalse( ZCH_Survey_Verb::own_url( str_replace( 'https://', 'http://', $ok ) ), 'Plain http only when the site itself is http.' );
	}

	public function test_canvassing_lookups_stay_in_their_shape(): void {
		$this->assertSame( array( 'name' => 'MAIN', 'zip' => '00011' ), ZCH_Local::clean_points_query( array( 'name' => 'MAIN', 'zip' => '00011', 'evil' => 1 ) ) );
		$this->assertNull( ZCH_Local::clean_points_query( array( 'name' => "MAIN'; --" ) ), 'Outside the layer\'s letters: ask the public site.' );
		$this->assertNull( ZCH_Local::clean_points_query( array( 'envelope' => array( 0, 0, 1, 1 ) ) ), 'Never a whole county in one answer.' );
		$this->assertNull( ZCH_Local::clean_points_query( array( 'num' => 5 ) ), 'A number alone is no question.' );
		$f = ZCH_Local::clean_feature( array( 'attributes' => array( 'num' => '100', 'name' => 'MAIN', 'owner' => 'Alex Example', 'zip' => '000110000' ), 'geometry' => array( 'x' => -100.1, 'y' => 40.1 ) ) );
		$this->assertArrayNotHasKey( 'owner', $f['attributes'] );
		$this->assertSame( 100, $f['attributes']['num'] );
		$this->assertSame( '00011', $f['attributes']['zip'] );
	}

	public function test_territory_questions(): void {
		$this->assertSame( 'zip:00011', ZCH_Territories::clean_who( 'ZIP:00011-1234' ) );
		$this->assertSame( 'person:jordan', ZCH_Territories::clean_who( 'person:Jordan' ) );
		$this->assertSame( '', ZCH_Territories::clean_who( 'person:<script>' ) );
		$o = ZCH_Territories::overlaps(
			array(
				array( 'initials' => 'AM', 'zips' => array( '00012', '00011' ) ),
				array( 'initials' => 'JB', 'zips' => array( '00011' ) ),
				array( 'initials' => '', 'zips' => array( '00012' ) ),
			)
		);
		$this->assertSame( array( array( 'zip' => '00011', 'initials' => array( 'AM', 'JB' ) ), array( 'zip' => '00012', 'initials' => array( 'AM', '?' ) ) ), $o );
	}

	private function sorted_keys( array $a ): array {
		$k = array_keys( $a );
		sort( $k );
		return $k;
	}

	private function open_error( $env, $outer, $typ ): string {
		try {
			$env->open( $outer, ZCH_WEB_PARTY, $typ );
		} catch ( ZCH_Envelope_Error $e ) {
			return $e->getMessage();
		}
		return 'opened';
	}

	public function test_a_key_id_made_only_of_digits_still_addresses_the_box(): void {
		// One key id in a few hundred is all digits; PHP stores such an array key as an integer.
		$hub_sk = null;
		$hub_pk = null;
		for ( $i = 0; $i < 20000 && null === $hub_sk; $i++ ) {
			list( $sk, $pk ) = ZCH_Envelope::keygen();
			if ( ctype_digit( ZCH_Envelope::key_id( ZCH_Envelope::b64d( $pk ) ) ) ) {
				$hub_sk = $sk;
				$hub_pk = $pk;
			}
		}
		$this->assertNotNull( $hub_sk, 'found a key whose id is all digits' );
		list( $web_sk, $web_pk ) = ZCH_Envelope::keygen();
		$web = new ZCH_Envelope( ZCH_WEB_PARTY, $web_sk, array( ZCH_HUB_PARTY => array( $hub_pk ) ), new ZDZ_Test_Ch_Replay() );
		$hub = new ZCH_Envelope( ZCH_HUB_PARTY, $hub_sk, array( ZCH_WEB_PARTY => array( $web_pk ) ), new ZDZ_Test_Ch_Replay() );
		$box = $web->seal( ZCH_HUB_PARTY, 'request', array( 'q' => 1 ) );
		$this->assertIsString( $box['kid_to'], 'the key id travels as a string' );
		$this->assertSame( array( 'q' => 1 ), $hub->open( json_decode( json_encode( $box ), true ), ZCH_WEB_PARTY, 'request' )['body'] );
	}
}
