<?php
/**
 * Commission: party → attribution → pay, locked months, cost visibility, Ai proposals.
 *
 *   - Commission and piece-rate pay go only to the person a sale or job is attributed to;
 *     nobody, an unknown code, or sources that disagree is a review item, never a default.
 *   - Rate, tier and split math from a neutral plan.
 *   - A locked month never changes; an invoice locked in another month is paid once.
 *   - Costs and margins: the administrator tier only; Privacy View and the shared device never.
 *   - An Ai answer is a proposal: asking writes nothing to the ledger, the classifier or the
 *     confirmed mappings; only a person's confirmation does.
 *   - Places, location lines and $0 lines never carry a cost; the invoice fetch reads every page.
 *   - The compensation pack skips people the site does not have.
 *
 * Neutral data only: made-up people (Alex, Jordan, Sam, Casey), made-up items, made-up rates.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $v ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $v; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return (object) array( 'role_names' => array() ); }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
}

// The app's Item Engine helpers (app.php is not loaded here): a made-up catalog.
if ( ! function_exists( 'zcc_item_get' ) ) {
	function zcc_item_get( string $item_id ) { return $GLOBALS['zcc_test_catalog'][ $item_id ] ?? null; }
}
if ( ! function_exists( 'zcc_item_match' ) ) {
	function zcc_item_match( string $text ) {
		foreach ( (array) ( $GLOBALS['zcc_test_catalog'] ?? array() ) as $id => $it ) {
			foreach ( (array) ( $it['aliases'] ?? array() ) as $a ) {
				if ( false !== stripos( $text, $a ) ) {
					return $it;
				}
			}
		}
		return null;
	}
}
if ( ! function_exists( 'zcc_item_classify' ) ) {
	function zcc_item_classify( string $text ): string {
		$m = zcc_item_match( $text );
		return is_array( $m ) ? (string) $m['id'] : '';
	}
}
if ( ! function_exists( 'zcc_item_engine_version' ) ) {
	function zcc_item_engine_version(): int { return (int) ( $GLOBALS['zcc_test_catalog_version'] ?? 1 ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-item-engine.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-price-book.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-compensation.php';
foreach ( array( 'split', 'places', 'line-map', 'cost-book', 'classifier', 'freshbooks', 'ledger', 'audit', 'rep-overrides', 'visibility',
	'attribution', 'review', 'material-cost', 'cost-recheck', 'calc-engine', 'month-lock', 'installer-pay', 'invoice-view', 'ai' ) as $zcc_part ) {
	require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/commission/includes/class-zcc-' . $zcc_part . '.php';
}

final class ZCC_Test_Fb_Client {
	public $pages = array();
	public $asked = array();
	public function get_invoices( array $params ) {
		$this->asked[] = $params;
		$p = (int) ( $params['page'] ?? 1 );
		return array( 'response' => array( 'result' => array( 'invoices' => $this->pages[ $p - 1 ] ?? array(), 'pages' => count( $this->pages ) ) ) );
	}
}

final class CommissionPaySafetyTest extends TestCase {

	const ALEX = 101, JORDAN = 102, SAM = 103, CASEY = 104, ADMIN = 201, SALES = 202, SHARED = 204;

	protected function setUp(): void {
		// The Ai proposal's "shown" text goes through the customer-detail coder when another file loaded it; on this
		// site no customer table exists.
		$GLOBALS['wpdb']               = new ZDZ_Test_Empty_DB();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_options']   = array();
		$GLOBALS['zdz_test_filters']   = array();
		$GLOBALS['zdz_test_users']     = array();
		$GLOBALS['zdz_test_user_meta'] = array();
		$GLOBALS['zdz_test_user_roles'] = array(
			self::ADMIN  => array( 'administrator' ),
			self::SALES  => array( 'zdz_sales' ),
			self::SHARED => array( 'zdz_general' ),
		);
		$GLOBALS['zdz_test_user_caps'] = array( self::ADMIN => array( 'manage_options' ) );
		$GLOBALS['zcc_test_catalog']   = array(
			'widget-a' => array( 'id' => 'widget-a', 'display_name' => 'Widget A', 'subtype' => 'widget', 'aliases' => array( 'widget a' ), 'attributes' => array() ),
			'widget-b' => array( 'id' => 'widget-b', 'display_name' => 'Widget B', 'subtype' => 'widget', 'aliases' => array( 'widget b' ), 'attributes' => array() ),
		);
		// Costs live in the private cost book.
		$GLOBALS['zdz_test_options']['zcc_cost_book'] = array(
			'widget-a' => array( 'cost_type' => 'per_unit', 'cost' => 100 ),
			'widget-b' => array( 'cost_type' => 'per_unit', 'cost' => 20 ),
		);
		$this->plan( self::ALEX, array( 'zdz_comp_structure' => 'flat_rate', 'zdz_comp_rate_percent' => 10, 'zdz_party_code' => 'AL' ) );
		$this->plan( self::JORDAN, array( 'zdz_comp_structure' => 'tiered', 'zdz_comp_tiers' => array( array( 'ceiling' => 2000, 'rate' => 5 ), array( 'ceiling' => null, 'rate' => 7 ) ), 'zdz_party_code' => 'JO' ) );
		$this->plan( self::SAM, array( 'zdz_comp_structure' => 'piece_rate', 'zdz_comp_is_piece_worker' => '1', 'zdz_party_code' => 'SA' ) );
		$GLOBALS['zdz_test_filters']['zdz_compensation_all_plans'] = function ( $plans ) {
			$out = array();
			foreach ( array_keys( $GLOBALS['zdz_test_user_meta'] ) as $uid ) {
				$p = ZDZ_Compensation::get_plan( (int) $uid );
				if ( $p['configured'] ) {
					$out[] = $p;
				}
			}
			return $out;
		};
		ZDZ_Compensation::flush_code_cache();
		ZCC_Classifier::flush();
		ZCC_Places::flush();
		ZCC_FreshBooks::flush_memo();
		ZCC_Ledger::memory( true );
		ZCC_Attribution::$override_lookup = static function () { return ''; };
		ZCC_Month_Lock::$now_month = '2026-03';
		ZCC_Month_Lock::$compute   = null;
		ZCC_Material_Cost::$force  = null;
		ZCC_Calc_Engine::$no_ledger = false;
		ZCC_Ai::$ask     = null;
		ZCC_Ai::$catalog = array_values( $GLOBALS['zcc_test_catalog'] );
		ZCC_Invoice_View::$read = null;
		if ( class_exists( 'ZDZ_Privacy_View' ) ) {
			ZDZ_Privacy_View::flush();
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'flush' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	/** Set (or with null, clear) the piece-rate mode in the compensation settings. */
	private function piece_mode( ?string $mode ): void {
		$s = (array) ( $GLOBALS['zdz_test_options']['zdz_compensation_settings'] ?? array() );
		if ( null === $mode ) {
			unset( $s['piece_rate_eligibility']['mode'] );
		} else {
			$s['piece_rate_eligibility']['mode'] = $mode;
		}
		$GLOBALS['zdz_test_options']['zdz_compensation_settings'] = $s;
	}

	protected function tearDown(): void {
		ZCC_Ledger::memory( false );
		ZCC_FreshBooks::$client = null;
		ZCC_Attribution::$override_lookup = null;
		ZCC_Month_Lock::$now_month = null;
		ZCC_Ai::$ask     = null;
		ZCC_Ai::$catalog = null;
		ZDZ_Compensation::flush_code_cache();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_user_meta'],
			$GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zcc_test_catalog'], $GLOBALS['zdz_test_current_user'],
			$GLOBALS['zdz_test_transients'], $GLOBALS['wpdb'] );
	}

	private function plan( int $uid, array $meta ): void {
		$GLOBALS['zdz_test_user_meta'][ $uid ] = $meta;
	}

	private function inv( int $id, array $codes, array $lines, string $paid = '2026-01-15' ): array {
		$norm = array();
		foreach ( $lines as $l ) {
			$norm[] = array( 'description' => $l[0], 'item_name' => $l[0], 'desc_sub' => '', 'qty' => $l[2] ?? 1, 'amount' => $l[1], 'unit_cost' => $l[1] );
		}
		return array(
			'invoice_id' => $id, 'invoice_number' => (string) ( 9000 + $id ), 'customer_name' => 'Example Customer',
			'date_completed' => '2026-01-05', 'date_paid' => $paid, 'v3_status' => 'paid', 'reference' => '',
			'lines' => $norm, 'salesperson_codes' => $codes, 'discount_amount' => 0,
		);
	}

	/* ── party → attribution → pay ─────────────────────────────────────── */

	public function test_commission_counts_only_the_attributed_persons_invoices(): void {
		$invs = array(
			$this->inv( 1, array( 'AL' ), array( array( 'Widget A install', 500 ) ) ),
			$this->inv( 2, array( 'JO' ), array( array( 'Widget A install', 900 ) ) ),
			$this->inv( 3, array(), array( array( 'Widget A install', 700 ) ) ),
		);
		$split = ZCC_Attribution::partition( $invs, self::ALEX );
		$this->assertSame( array( 1 ), array_column( $split['matched'], 'invoice_id' ), 'only the invoice coded to this person' );
		$this->assertCount( 1, $split['review'], 'the invoice with nobody is a review item' );
		$this->assertSame( 'no_attribution', $split['review'][0]['reason'] );
		$this->assertArrayNotHasKey( 'customer_name', $split['review'][0], 'a review item keeps no customer details' );

		$calc = ZCC_Calc_Engine::calculate( $split['matched'], self::ALEX, '2026-01' );
		$this->assertSame( 40.0, $calc['summary']['total_commission'], '10% of (500 - 100 cost)' );
		// An administrator is never handed the unattributed invoice either.
		$this->assertSame( array(), ZCC_Attribution::partition( $invs, self::ADMIN )['matched'] );
	}

	public function test_unknown_code_or_disagreeing_sources_pay_nobody_until_settled(): void {
		$unknown = ZCC_Attribution::resolve( $this->inv( 4, array( 'ZZ' ), array() ), 'sale' );
		$this->assertSame( 'none', $unknown['status'] );
		$this->assertSame( 'unknown_code', $unknown['reason'] );

		$GLOBALS['zdz_test_filters']['zcc_attribution_people'] = function ( $p, $inv, $role ) {
			return array( array( 'user_id' => CommissionPaySafetyTest::JORDAN, 'source' => 'estimate' ) );
		};
		$inv = $this->inv( 5, array( 'AL' ), array( array( 'Widget A install', 500 ) ) );
		$res = ZCC_Attribution::resolve( $inv, 'sale' );
		$this->assertSame( 'ambiguous', $res['status'], 'the code and the recorded salesperson disagree' );
		$this->assertFalse( ZCC_Attribution::pays( $res, self::ALEX ) );
		$this->assertFalse( ZCC_Attribution::pays( $res, self::JORDAN ) );

		ZCC_Attribution::$override_lookup = static function ( $id ) { return 5 === $id ? 'JO' : ''; };
		$settled = ZCC_Attribution::resolve( $inv, 'sale' );
		$this->assertSame( 'one', $settled['status'], 'a person\'s override settles it' );
		$this->assertTrue( ZCC_Attribution::pays( $settled, self::JORDAN ) );
		$this->assertFalse( ZCC_Attribution::pays( $settled, self::ALEX ) );

		$GLOBALS['zdz_test_filters']['zcc_attribution_people'] = function () {
			return array( array( 'user_id' => CommissionPaySafetyTest::ALEX ), array( 'user_id' => CommissionPaySafetyTest::JORDAN ) );
		};
		ZCC_Attribution::$override_lookup = static function () { return ''; };
		$two = ZCC_Attribution::resolve( $this->inv( 6, array(), array() ), 'sale' );
		$this->assertSame( 'ambiguous', $two['status'], 'two recorded people are never guessed between' );
	}

	public function test_recorded_salesperson_alone_attributes_the_sale(): void {
		$GLOBALS['zdz_test_filters']['zcc_attribution_people'] = function ( $p, $inv, $role ) {
			return 'sale' === $role ? array( array( 'user_id' => CommissionPaySafetyTest::ALEX, 'source' => 'territories' ) ) : array();
		};
		$split = ZCC_Attribution::partition( array( $this->inv( 7, array(), array( array( 'Widget A install', 500 ) ) ) ), self::ALEX );
		$this->assertCount( 1, $split['matched'] );
		$this->assertSame( 'territories', $split['matched'][0]['attribution']['source'] );
	}

	/* ── rate, tier and split math from a neutral plan ───────────────────── */

	public function test_tiered_math_is_marginal_across_the_month(): void {
		$invs = array(
			$this->inv( 11, array( 'JO' ), array( array( 'Widget A install', 1600 ) ) ), // net 1500
			$this->inv( 12, array( 'JO' ), array( array( 'Widget A install', 1600 ) ) ), // net 1500 → crosses 2000
		);
		$calc = ZCC_Calc_Engine::calculate( ZCC_Attribution::partition( $invs, self::JORDAN )['matched'], self::JORDAN, '2026-01' );
		// 2000 × 5% + 1000 × 7% = 100 + 70
		$this->assertSame( 170.0, $calc['summary']['total_commission'] );
	}

	public function test_shared_job_split_by_weight_and_unconfigured_plan_pays_zero(): void {
		$this->plan( self::ALEX, array( 'zdz_comp_structure' => 'flat_rate', 'zdz_comp_rate_percent' => 10, 'zdz_party_code' => 'AL', 'zdz_comp_split_policy' => 'weight', 'zdz_comp_split_weight' => 7 ) );
		$this->plan( self::JORDAN, array( 'zdz_comp_structure' => 'flat_rate', 'zdz_comp_rate_percent' => 10, 'zdz_party_code' => 'JO', 'zdz_comp_split_policy' => 'weight', 'zdz_comp_split_weight' => 3 ) );
		ZDZ_Compensation::flush_code_cache();
		$inv = $this->inv( 21, array( 'AL', 'JO' ), array( array( 'Widget A install', 1100 ) ) ); // net 1000, pool 100
		$a   = ZCC_Calc_Engine::calculate( ZCC_Attribution::partition( array( $inv ), self::ALEX )['matched'], self::ALEX, '2026-01' );
		$j   = ZCC_Calc_Engine::calculate( ZCC_Attribution::partition( array( $inv ), self::JORDAN )['matched'], self::JORDAN, '2026-01' );
		$this->assertSame( 70.0, $a['summary']['total_commission'] );
		$this->assertSame( 30.0, $j['summary']['total_commission'] );

		$GLOBALS['zdz_test_user_meta'][ self::CASEY ] = array( 'zdz_party_code' => 'CA' ); // no structure: no plan
		$none = ZCC_Calc_Engine::calculate( array( $this->inv( 22, array(), array( array( 'Widget A install', 500 ) ) ) ), self::CASEY, '2026-01' );
		$this->assertSame( 0.0, $none['summary']['total_commission'], 'no plan never pays a default rate' );
		$this->assertSame( 'unconfigured', $none['summary']['commission_rate'] );
	}

	/* ── piece-rate pay from recorded installers ─────────────────────────── */

	public function test_piece_rate_pay_counts_only_jobs_attributed_to_the_installer(): void {
		$GLOBALS['zdz_test_options']['zdz_compensation_piece_rates'] = array( 'widget-a' => array( 'rate' => 15, 'unit' => 'per_item' ), 'widget-b' => array( 'rate' => 5, 'unit' => 'per_item' ) );
		$this->plan( self::CASEY, array( 'zdz_comp_structure' => 'piece_rate', 'zdz_comp_is_piece_worker' => '1', 'zdz_party_code' => 'CA' ) );
		ZDZ_Compensation::flush_code_cache();
		$GLOBALS['zdz_test_filters']['zcc_attribution_people'] = function ( $p, $inv, $role ) {
			if ( 'install' !== $role ) {
				return array();
			}
			$map = array( 31 => CommissionPaySafetyTest::SAM, 32 => CommissionPaySafetyTest::CASEY );
			return isset( $map[ $inv['invoice_id'] ] ) ? array( array( 'user_id' => $map[ $inv['invoice_id'] ], 'source' => 'jobs' ) ) : array();
		};
		$invs = array(
			$this->inv( 31, array(), array( array( 'Widget A', 300, 2 ), array( 'Widget B', 40, 3 ) ) ),
			$this->inv( 32, array(), array( array( 'Widget A', 300, 5 ) ) ),
			$this->inv( 33, array(), array( array( 'Widget A', 300, 4 ) ) ), // nobody recorded; two piece workers
		);
		$sam = ZCC_Installer_Pay::tally_for_party( self::SAM, $invs );
		$this->assertSame( array( 'widget-a' => 2, 'widget-b' => 3 ), $sam['counts'], 'only Sam\'s own job' );
		$this->assertSame( 45.0, ZCC_Installer_Pay::compute_pay( $sam['counts'] )['total_pay'] );
		$this->assertSame( 'no_installer', $sam['review'][0]['reason'], 'the unrecorded job is a review item' );

		$this->piece_mode( 'company_wide' );
		$this->assertSame( 'several_piece_workers', ZCC_Installer_Pay::tally_for_party( self::SAM, $invs )['review'][0]['reason'], 'company-wide with two piece workers: still a review item' );
		$this->piece_mode( null );

		// Team Roles: Sam is not marked able to install widget-b — those units are held.
		$GLOBALS['zdz_test_filters']['zdz_person_can_do'] = function ( $prior, $uid, $item, $role ) {
			return array( 'can' => 'widget-b' !== $item );
		};
		$held = ZCC_Installer_Pay::tally_for_party( self::SAM, $invs );
		$this->assertSame( array( 'widget-a' => 2 ), $held['counts'] );
		$this->assertSame( array( 'widget-b' => 3 ), $held['withheld'] );
	}

	public function test_company_wide_mode_pays_only_a_single_piece_worker(): void {
		$GLOBALS['zdz_test_options']['zdz_compensation_piece_rates'] = array( 'widget-a' => array( 'rate' => 15, 'unit' => 'per_item' ) );
		$inv  = $this->inv( 41, array(), array( array( 'Widget A', 300, 2 ) ) );
		$this->piece_mode( 'company_wide' );
		$only = ZCC_Attribution::resolve( $inv, 'install' );
		$this->assertSame( 'one', $only['status'] );
		$this->assertSame( array( self::SAM ), $only['people'] );

		$this->plan( self::CASEY, array( 'zdz_comp_structure' => 'piece_rate', 'zdz_comp_is_piece_worker' => '1' ) );
		$this->assertSame( 'ambiguous', ZCC_Attribution::resolve( $inv, 'install' )['status'], 'two piece workers: never both paid' );
	}

	public function test_core_piece_rate_mode_pays_only_recorded_jobs(): void {
		$this->assertSame( 'per_job', ZDZ_Compensation::piece_rate_eligibility()['mode'], 'Core default' );
		$this->piece_mode( 'company_wide' );
		$this->assertSame( 'company_wide', ZDZ_Compensation::piece_rate_eligibility()['mode'], 'still a setting a business may choose' );
		$this->piece_mode( 'everyone' );
		$this->assertSame( 'per_job', ZDZ_Compensation::piece_rate_eligibility()['mode'], 'an unknown mode reads as the Core default' );
		$this->piece_mode( null );

		// One piece worker (Sam), a job with nobody recorded: not Sam's under the Core mode.
		$GLOBALS['zdz_test_options']['zdz_compensation_piece_rates'] = array( 'widget-a' => array( 'rate' => 15, 'unit' => 'per_item' ) );
		$inv = $this->inv( 42, array(), array( array( 'Widget A', 300, 2 ) ) );
		$res = ZCC_Attribution::resolve( $inv, 'install' );
		$this->assertSame( 'none', $res['status'] );
		$this->assertSame( 'no_installer', $res['reason'] );
		$tally = ZCC_Installer_Pay::tally_for_party( self::SAM, array( $inv ) );
		$this->assertSame( array(), $tally['counts'], 'nobody is paid for a job nobody is recorded on' );
		$this->assertCount( 1, $tally['review'] );
	}

	public function test_team_roles_check_applies_only_once_someone_is_marked(): void {
		$GLOBALS['zdz_test_options']['zdz_compensation_piece_rates'] = array( 'widget-a' => array( 'rate' => 15, 'unit' => 'per_item' ) );
		$GLOBALS['zdz_test_filters']['zcc_attribution_people'] = function ( $p, $inv, $role ) {
			return 'install' === $role ? array( array( 'user_id' => CommissionPaySafetyTest::SAM, 'source' => 'jobs' ) ) : array();
		};
		// Team Roles answers Can't for everything (nobody marked) ...
		$GLOBALS['zdz_test_filters']['zdz_person_can_do'] = function () {
			return array( 'can' => false, 'level' => 'cannot', 'why' => 'unmarked' );
		};
		$invs = array( $this->inv( 43, array(), array( array( 'Widget A', 300, 2 ) ) ) );

		// ... and says it holds no marks at all: the check is skipped and the answer says so.
		$GLOBALS['zdz_test_filters']['zdz_team_roles_in_use'] = function () { return false; };
		$this->assertSame( 'unmarked', ZCC_Installer_Pay::team_roles_check() );
		$off = ZCC_Installer_Pay::tally_for_party( self::SAM, $invs );
		$this->assertSame( array( 'widget-a' => 2 ), $off['counts'] );
		$this->assertSame( array(), $off['withheld'] );
		$this->assertSame( 'unmarked', $off['team_roles'] );
		$this->assertStringContainsString( 'Team Roles', (string) ( $off['team_roles_note'] ?? '' ) );

		// Once anyone is marked, the check applies: units of an item Sam is not marked for are held.
		$GLOBALS['zdz_test_filters']['zdz_team_roles_in_use'] = function () { return true; };
		$on = ZCC_Installer_Pay::tally_for_party( self::SAM, $invs );
		$this->assertSame( array(), $on['counts'] );
		$this->assertSame( array( 'widget-a' => 2 ), $on['withheld'] );
		$this->assertSame( 'on', $on['team_roles'] );
		$this->assertArrayNotHasKey( 'team_roles_note', $on );

		// Without Team Roles saying either way, any answer to the per-item question still counts.
		unset( $GLOBALS['zdz_test_filters']['zdz_team_roles_in_use'] );
		$this->assertSame( 'off', ZCC_Installer_Pay::team_roles_check() );
		$this->assertSame( array( 'widget-a' => 2 ), ZCC_Installer_Pay::tally_for_party( self::SAM, $invs )['withheld'] );
	}

	public function test_the_pay_screen_shows_the_team_roles_note(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/commission/assets/js/widget.js' );
		$this->assertStringContainsString( 'data.team_roles_note', $js );
	}

	/* ── locked months ───────────────────────────────────────────────────── */

	public function test_lock_month_freezes_exactly_that_figure(): void {
		$invs = array( $this->inv( 51, array( 'AL' ), array( array( 'Widget A install', 500 ) ) ), $this->inv( 52, array( 'AL' ), array( array( 'Widget A install', 300 ) ) ) );
		ZCC_Month_Lock::$compute = function ( $target, $s, $e, $month ) use ( &$invs ) {
			return ZCC_Calc_Engine::calculate( ZCC_Attribution::partition( $invs, $target )['matched'], $target, $month );
		};
		$this->assertFalse( ZCC_Month_Lock::answer( self::SALES, self::ALEX, '2026-01' )['ok'], 'only the payer locks' );
		$this->assertFalse( ZCC_Month_Lock::answer( self::ADMIN, self::ALEX, '2026-03' )['ok'], 'a month not over cannot be locked' );

		$r = ZCC_Month_Lock::answer( self::ADMIN, self::ALEX, '2026-01' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 60.0, $r['commission'] ); // 10% of 400 + 10% of 200
		$this->assertSame( 2, $r['invoices'] );

		// Later edits to the invoices cannot change the locked month.
		$invs[0]['lines'][0]['amount'] = 5000;
		$again = ZCC_Calc_Engine::calculate( $invs, self::ALEX, '2026-01' );
		$this->assertSame( 60.0, $again['summary']['total_commission'] );
		$this->assertSame( 2, $again['ledger_stats']['locked'] );
		foreach ( ZCC_Ledger::get_period( self::ALEX, '2026-01' ) as $row ) {
			$this->assertSame( 'finalized', $row['status'] );
		}
		$second = ZCC_Month_Lock::answer( self::ADMIN, self::ALEX, '2026-01' );
		$this->assertSame( 0, $second['locked_now'], 'a second press changes nothing' );
		$this->assertSame( 60.0, $second['commission'] );
	}

	public function test_invoice_locked_in_another_month_is_paid_once(): void {
		$inv = $this->inv( 61, array( 'AL' ), array( array( 'Widget A install', 500 ) ) );
		ZCC_Calc_Engine::calculate( array( $inv ), self::ALEX, '2026-01' );
		ZCC_Ledger::finalize_invoices( self::ALEX, array( 61 ), self::ADMIN );
		$feb = ZCC_Calc_Engine::calculate( array( $inv ), self::ALEX, '2026-02' );
		$this->assertSame( 0.0, $feb['summary']['total_commission'] );
		$this->assertSame( 1, $feb['ledger_stats']['locked_elsewhere'] );
	}

	public function test_locked_row_cost_recheck_lists_but_never_changes(): void {
		$inv = $this->inv( 71, array( 'AL' ), array( array( 'Widget A install', 500 ) ) );
		ZCC_Calc_Engine::calculate( array( $inv ), self::ALEX, '2026-01' );
		ZCC_Ledger::finalize_invoices( self::ALEX, array( 71 ), self::ADMIN );
		$GLOBALS['zdz_test_options']['zcc_cost_book']['widget-a']['cost'] = 150;   // the cost changes later
		$calc = ZCC_Calc_Engine::calculate( array( $inv ), self::ALEX, '2026-01' );
		$this->assertSame( 40.0, $calc['summary']['total_commission'], 'the locked figure stands' );
		$flags = array_values( array_filter( $calc['flags'], static function ( $f ) { return 'Cost recheck' === ( $f['description'] ?? '' ); } ) );
		$this->assertCount( 1, $flags );
		$this->assertSame( 150.0, $flags[0]['cost_recheck']['new'] );
	}

	/* ── cost visibility ─────────────────────────────────────────────────── */

	public function test_costs_are_for_the_administrator_tier_only(): void {
		$this->assertTrue( ZCC_Visibility::may_see_costs( self::ADMIN ) );
		$this->assertFalse( ZCC_Visibility::may_see_costs( self::SALES ), 'staff never see a cost' );
		$this->assertFalse( ZCC_Visibility::may_see_costs( self::SHARED ), 'the shared device never' );
		$this->assertFalse( ZCC_Visibility::may_see_costs( 999 ), 'an unknown person never' );
		$GLOBALS['zdz_test_user_meta'][ self::ADMIN ]['zdz_privacy_view'] = '1';
		$this->assertFalse( ZCC_Visibility::may_see_costs( self::ADMIN ), 'an account in Privacy View never' );
		$this->assertFalse( ZCC_Visibility::is_pay_admin( self::ADMIN ) );

		$calc  = ZCC_Calc_Engine::calculate( array( $this->inv( 81, array( 'AL' ), array( array( 'Widget A install', 0.0 ), array( 'Widget A install', 500 ) ) ) ), self::ALEX, '2026-01' );
		$shown = ZCC_Visibility::shape_calc( $calc, false );
		$json  = json_encode( $shown );
		foreach ( array( 'total_cogs', 'net_commissionable', '"cogs"', 'cogs_source', 'material' ) as $k ) {
			$this->assertStringNotContainsString( $k, $json, $k . ' must not reach a salesperson' );
		}
		$this->assertArrayNotHasKey( 'flags', $shown, 'flags can quote a cost' );
		$this->assertSame( 40.0, $shown['summary']['total_commission'], 'the commission itself stays' );
	}

	/* ── Ai proposals ────────────────────────────────────────────────────── */

	public function test_ai_proposal_never_writes_and_a_person_confirms(): void {
		$line = array( 'description' => 'Gizmo for the back gate', 'item_name' => 'Gizmo for the back gate', 'desc_sub' => '', 'qty' => 1, 'amount' => 80 );
		$inv  = array( 'invoice_id' => 91, 'invoice_number' => '9091', 'lines' => array( $line ), 'salesperson_codes' => array( 'AL' ), 'v3_status' => 'paid', 'date_completed' => '2026-01-02', 'date_paid' => '2026-01-03' );
		$this->assertSame( 'unknown', ZCC_Classifier::classify_line( $line )['category'] );
		$lines = ZCC_Ai::unclear_lines( array( $inv ) );
		$this->assertCount( 1, $lines );

		$seen = array();
		ZCC_Ai::$ask = function ( $role, $request, $ctx ) use ( &$seen ) {
			$seen = array( $role, $request, $ctx );
			return array( 'json' => array( 'lines' => array( array( 'i' => 0, 'item_id' => 'widget-b', 'non_product' => false, 'confidence' => 0.7, 'note' => 'looks like widget b' ) ) ) );
		};
		$this->assertFalse( ZCC_Ai::propose( $lines, self::SALES )['ok'], 'staff cannot ask' );
		$this->assertSame( array(), $seen, 'a refused ask never reaches the router' );

		$before_ledger = ZCC_Ledger::get_period( self::ALEX, '2026-01' );
		$res = ZCC_Ai::propose( $lines, self::ADMIN );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'classify', $seen[0] );
		$this->assertSame( 'commission-calculator', $seen[2]['caller'] );
		$this->assertTrue( $seen[1]['system_company'] );
		$this->assertArrayNotHasKey( 'model', $seen[1], 'no model is named by the app' );
		$this->assertStringNotContainsString( '100', $seen[1]['system'], 'no cost goes to the Ai' );

		$this->assertSame( $before_ledger, ZCC_Ledger::get_period( self::ALEX, '2026-01' ), 'asking writes no ledger row' );
		$this->assertArrayNotHasKey( 'zcc_line_map', $GLOBALS['zdz_test_options'], 'asking writes no mapping' );
		ZCC_Classifier::flush();
		$this->assertSame( 'unknown', ZCC_Classifier::classify_line( $line )['category'], 'a proposal never classifies a line' );
		$key = array_key_first( $lines );
		$this->assertSame( 'proposed', ZCC_Ai::all()[ $key ]['status'] );

		$this->assertFalse( ZCC_Ai::settle( $key, 'confirm', '', self::SALES )['ok'], 'staff cannot confirm' );
		$this->assertTrue( ZCC_Ai::settle( $key, 'confirm', '', self::ADMIN )['ok'] );
		$now = ZCC_Classifier::classify_line( $line );
		$this->assertSame( 'widget-b', $now['item_id'] );
		$this->assertSame( 'confirmed_mapping', $now['classified_by'] );
	}

	/* ── places, location lines, $0 lines ────────────────────────────────── */

	public function test_places_and_location_lines_never_carry_a_cost(): void {
		$GLOBALS['zdz_test_options']['zcc_places'] = array( 'Widget', 'North Example' );
		ZCC_Places::flush();
		$this->assertStringNotContainsString( 'Widget', ZCC_Places::neutralize( 'Widget - (AL) Repair' ) );
		$this->assertStringContainsString( '(AL)', ZCC_Places::neutralize( 'Widget - (AL) Repair' ), 'the code stays' );
		$this->assertStringNotContainsString( 'North Example', ZCC_Places::neutralize( 'North Example service call' ) );
		$this->assertSame( 'service call', strtolower( trim( ZCC_Places::neutralize( 'Widget service call' ) ) ), 'a one-word place with no product context goes' );
		$this->assertStringContainsString( 'Widget', ZCC_Places::neutralize( 'Widget 36 x 80' ), 'with a size the word stays' );
		$this->assertStringNotContainsString( 'Widget', ZCC_Places::neutralize( 'Widget - (AL) 36 x 80' ), 'just before a code it is a place' );

		$raw = array( 'id' => 5, 'invoice_number' => '0005', 'v3_status' => 'paid', 'lines' => array(
			array( 'name' => 'Location', 'description' => 'Widget - (AL)', 'qty' => 1, 'amount' => array( 'amount' => '0.00' ) ),
			array( 'name' => 'Loc:', 'description' => 'priced by mistake', 'qty' => 1, 'amount' => array( 'amount' => '50.00' ) ),
		) );
		$n = ZCC_FreshBooks::normalize_invoice( $raw );
		$this->assertCount( 1, $n['lines'], 'a $0 location line is the job\'s location, not a line' );
		$this->assertTrue( $n['lines'][0]['is_location'] );
		$this->assertSame( array( 'AL' ), $n['salesperson_codes'], 'the code on the location line still attributes' );
		$this->assertArrayNotHasKey( 'fb_url', $n, 'no link to the billing system is built' );
		$calc = ZCC_Calc_Engine::calculate( array( $n ), self::ALEX, '2026-01' );
		$this->assertSame( 0.0, $calc['summary']['total_cogs'] );

		$zero = ZCC_Calc_Engine::calculate( array( $this->inv( 95, array( 'AL' ), array( array( 'Widget A install', 0.0 ) ) ) ), self::ALEX, '2026-01' );
		$this->assertSame( 0.0, $zero['summary']['total_cogs'], 'a $0 line gets no catalog cost' );
	}

	public function test_invoice_fetch_reads_every_page_and_skips_deleted(): void {
		$c = new ZCC_Test_Fb_Client();
		$c->pages = array(
			array( array( 'id' => 1, 'invoice_number' => '1', 'v3_status' => 'paid', 'create_date' => '2026-01-02', 'date_paid' => '2026-01-10', 'lines' => array() ),
				array( 'id' => 2, 'invoice_number' => '2', 'v3_status' => 'paid', 'vis_state' => 1, 'create_date' => '2026-01-02', 'date_paid' => '2026-01-10', 'lines' => array() ) ),
			array( array( 'id' => 3, 'invoice_number' => '3', 'v3_status' => 'paid', 'create_date' => '2025-12-20', 'date_paid' => '2026-01-04', 'lines' => array() ),
				array( 'id' => 4, 'invoice_number' => '4', 'v3_status' => 'draft', 'create_date' => '2026-01-03', 'lines' => array() ),
				array( 'id' => 5, 'invoice_number' => '5', 'v3_status' => 'paid', 'create_date' => '2025-12-01', 'date_paid' => '2025-12-30', 'lines' => array() ) ),
		);
		ZCC_FreshBooks::$client = $c;
		$got = ZCC_FreshBooks::get_invoices( '2026-01-01', '2026-01-31' );
		$this->assertSame( array( 1, 3 ), array_column( $got, 'invoice_id' ), 'paid in the window; deleted, drafts and earlier payments out' );
		$this->assertCount( 2, $c->asked, 'both pages read' );
		$this->assertSame( '', ZCC_FreshBooks::last_error() );
	}

	public function test_share_link_only_a_whole_billing_address(): void {
		$this->assertSame( 'https://my.freshbooks.com/#/link/abc', ZCC_FreshBooks::share_link_from( array( 'response' => array( 'result' => array( 'share_link' => array( 'share_link' => 'https://my.freshbooks.com/#/link/abc' ) ) ) ) ) );
		$this->assertSame( '', ZCC_FreshBooks::share_link_from( array( 'share_link' => 'https://example.com/#/link/abc' ) ) );
		$this->assertSame( '', ZCC_FreshBooks::share_link_from( array( 'share_link' => 'https://my.freshbooks.com/"><script>' ) ) );
	}

	public function test_invoice_view_opens_only_where_commission_lists_it(): void {
		$GLOBALS['zdz_test_user_meta'][ self::SALES ] = array( 'zdz_comp_structure' => 'flat_rate', 'zdz_comp_rate_percent' => 5 );
		ZCC_Ledger::upsert_entry( self::SALES, '2026-01', array( 'invoice_id' => 77, 'invoice_number' => '0077', 'lines' => array() ) );
		ZCC_Invoice_View::$read = static function () {
			return array( 'invoice_number' => '0077', 'fname' => 'Example', 'lname' => 'Customer', 'street' => '1 Example St', 'amount' => array( 'amount' => '100' ), 'lines' => array(), 'v3_status' => 'paid' );
		};
		$own = ZCC_Invoice_View::audience( self::SALES, '77', 'commission' );
		$this->assertFalse( $own['allowed'], 'without the commission permission service the figure gate is manage_options' );
		$adm = ZCC_Invoice_View::answer( self::ADMIN, '77', 'commission', self::SALES );
		$this->assertTrue( $adm['ok'] );
		$this->assertArrayNotHasKey( 'fb_url', $adm['doc'] );
		$this->assertFalse( ZCC_Invoice_View::answer( self::SHARED, '77', 'commission' )['ok'], 'never the shared device' );
		$this->assertFalse( ZCC_Invoice_View::answer( self::SALES, '77', 'review' )['ok'], 'review items: the payer only' );
		$hidden = ZCC_Invoice_View::shape( array( 'fname' => 'Example', 'street' => '1 Example St', 'notes' => 'gate 1234', 'amount' => 100, 'lines' => array( array( 'name' => 'x', 'amount' => 10, 'qty' => 1 ) ) ), false, true, false );
		$this->assertNull( $hidden['totals'] );
		$this->assertSame( '', $hidden['notes'], 'notes come only with the address' );
		$this->assertNull( $hidden['lines'][0]['amount'] );
	}

	/* ── material cost rules ─────────────────────────────────────────────── */

	public function test_material_cost_rules_validate_and_apply_only_from_the_start_date(): void {
		$this->assertFalse( ZCC_Material_Cost::save( array( 'start_date' => '2026-02-30' ), self::ADMIN, '2026-01-10' )['ok'], 'not a real date' );
		$this->assertFalse( ZCC_Material_Cost::save( array( 'start_date' => '2026-01-01' ), self::ADMIN, '2026-01-10' )['ok'], 'a past day needs its own confirmation' );
		$this->assertFalse( ZCC_Material_Cost::save( array( 'areas' => array( 'Example Area' => 150 ) ), self::ADMIN, '2026-01-10' )['ok'], 'a rate is a percent under 100' );
		$ok = ZCC_Material_Cost::save( array( 'start_date' => '2026-01-01', 'confirm_past' => 1, 'areas' => array( 'Example Area' => 10 ), 'tax_areas' => array( 'other' => 'Example Area' ) ), self::ADMIN, '2026-01-10' );
		$this->assertTrue( $ok['ok'] );

		$after  = ZCC_Calc_Engine::calculate( array( $this->inv( 101, array( 'AL' ), array( array( 'Widget A install', 500 ) ), '2026-01-15' ) ), self::ALEX, '2026-01' );
		$this->assertSame( 110.0, $after['summary']['total_cogs'], 'cost 100 + 10% purchase tax' );
		$before = ZCC_Calc_Engine::calculate( array( $this->inv( 102, array( 'AL' ), array( array( 'Widget A install', 500 ) ), '2025-12-20' ) ), self::ALEX, '2025-12' );
		$this->assertSame( 100.0, $before['summary']['total_cogs'], 'paid before the start date: unchanged' );

		$rep = ZCC_Material_Cost::report_row( self::ALEX, '2026-01', array( $this->inv( 103, array( 'AL' ), array( array( 'Widget A install', 500 ) ), '2026-01-15' ) ) );
		$this->assertSame( 100.0, $rep['before']['cogs'] );
		$this->assertSame( 110.0, $rep['after']['cogs'] );
		$this->assertSame( array(), ZCC_Ledger::rows_for_invoices( self::ALEX, array( 103 ) ), 'the what-if writes nothing' );
		$this->assertFalse( ZCC_Calc_Engine::$no_ledger, 'the dry-run switch is put back' );
	}

	/* ── the compensation pack ───────────────────────────────────────────── */

	public function test_compensation_pack_skips_people_the_site_does_not_have(): void {
		$roster = array( array( 'id' => self::ALEX, 'initials' => 'AL' ), array( 'id' => self::JORDAN, 'initials' => 'JO' ), array( 'id' => self::CASEY, 'initials' => 'JO' ) );
		$this->assertSame( self::ALEX, ZDZ_Compensation::pack_party( 'al', $roster ) );
		$this->assertSame( 0, ZDZ_Compensation::pack_party( 'JO', $roster ), 'two people with the same initials: no match' );
		$this->assertSame( 0, ZDZ_Compensation::pack_party( 'QQ', $roster ) );
		$r = ZDZ_Compensation::pack_read( array(
			'plans' => array(
				array( 'party' => array( 'initials' => 'AL' ), 'structure' => 'flat_rate', 'rate_percent' => 4 ),
				array( 'party' => array( 'initials' => 'QQ' ), 'structure' => 'flat_rate', 'rate_percent' => 4 ),
				array( 'party' => array( 'initials' => 'AL' ), 'structure' => 'tiered' ),
			),
			'piece_rates' => array( array( 'item' => 'widget-a', 'rate' => 3 ) ),
		), $roster );
		$this->assertSame( array( self::ALEX ), array_keys( $r['plans'] ) );
		$this->assertCount( 2, $r['skipped'], 'the unknown person and the duplicate plan are said, never given to someone else' );
		$this->assertSame( array( 'widget-a' ), array_keys( $r['piece_rates'] ) );
		$this->assertSame( array(), ZDZ_Compensation::pack_read( array() )['plans'], 'Core ships an empty plan' );
	}
}
