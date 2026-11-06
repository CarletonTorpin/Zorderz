<?php
/**
 * ZDZ_Price_Book: the priced catalog over the Item Engine, filled by an Identity Pack.
 *
 *   - Ships empty: an empty book answers null / [] / '' everywhere, and match_line() prices nothing.
 *   - Costs never leave the server for anyone below the admin rung (and never for Privacy View or an unknown role);
 *     the catalog the Item Engine publishes holds no cost at all.
 *   - match_line() reads a line's name, sub-line and size with the family's own vocabulary: family detection, vetoes,
 *     selectors with fallbacks, forced pairs, assumed sizes, two named choices, and the smallest covering variant.
 *   - The Item Engine's grid method prices a variant by SKU or by size.
 *   - The pack's catalog, pricing and costs files preview, apply and revert through the Identity Pack.
 *
 * Neutral data only: a made-up "Example" product family with round numbers.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return (object) array( 'role_names' => array() ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-item-engine.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-price-book.php';

/** Just enough of $wpdb for the Item Engine's two tables, in memory. */
final class ZDZ_Test_Pv_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $t          = array( 'wp_zdz_items' => array(), 'wp_zdz_pricing_schemes' => array() );
	public function prepare( $q, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$args ) {
			$v = array_shift( $args );
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}
	public function get_charset_collate() { return ''; }
	public function query( $q ) { return true; }
	public function suppress_errors( $s = true ) { return false; }
	public function get_var( $q ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) ) {
			return isset( $this->t[ $m[1] ] ) ? $m[1] : null;
		}
		if ( preg_match( "/^SELECT id FROM `(\w+)` WHERE id = '(.*)'$/", $q, $m ) ) {
			return isset( $this->t[ $m[1] ][ stripslashes( $m[2] ) ] ) ? stripslashes( $m[2] ) : null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM `(\w+)`$/', $q, $m ) ) {
			return count( $this->t[ $m[1] ] ?? array() );
		}
		return null;
	}
	public function get_results( $q, $o = null ) {
		return preg_match( '/^SELECT \* FROM `(\w+)`/', $q, $m ) ? array_values( $this->t[ $m[1] ] ?? array() ) : array();
	}
	public function get_row( $q, $o = null ) {
		if ( preg_match( "/^SELECT \* FROM `(\w+)` WHERE id = '(.*)'$/", $q, $m ) ) {
			return $this->t[ $m[1] ][ stripslashes( $m[2] ) ] ?? null;
		}
		return null;
	}
	public function get_col( $q ) { return array(); }
	public function insert( $table, $data ) {
		// A row with no id of its own gets the next number, as an auto-increment table gives it.
		$id                       = isset( $data['id'] ) ? (string) $data['id'] : (string) ( count( $this->t[ $table ] ?? array() ) + 1 );
		$this->t[ $table ][ $id ] = $data;
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( ! isset( $this->t[ $table ][ (string) $where['id'] ] ) ) {
			return 0;
		}
		$this->t[ $table ][ (string) $where['id'] ] = array_merge( $this->t[ $table ][ (string) $where['id'] ], $data );
		return 1;
	}
	public function delete( $table, $where ) {
		$had = isset( $this->t[ $table ][ (string) $where['id'] ] );
		unset( $this->t[ $table ][ (string) $where['id'] ] );
		return $had ? 1 : 0;
	}
}

final class PriceBookTest extends TestCase {

	private $dir = '';

	/** A made-up family: plain and panel styles, a refit service, a premium finish for one style, one part. */
	public static function pack_files() {
		$v = function ( $sku, array $attr, $w, $h, $label ) {
			return array( 'sku' => $sku, 'family' => 'example_doors', 'kind' => 'refit' === ( $attr['work'] ?? '' ) ? 'refit' : 'unit', 'brand' => 'Example',
				'model' => $attr['style'] ?? 'refit', 'label' => $label, 'line_item' => 'Example ' . ucfirst( $attr['style'] ?? 'refit' ) . ' Door',
				'dims' => array( 'max_width_in' => $w, 'max_height_in' => $h ), 'attributes' => $attr, 'uom' => 'each' );
		};
		$catalog = array( 'price_book' => array(
			'families' => array( array(
				'key'     => 'example_doors',
				'label'   => 'Example doors',
				'brand'   => 'Example',
				'aliases' => array( 'example door' ),
				'prompt'  => "Write the style (plain or panel) and the opening as W x H.",
				'match'   => array(
					'names'         => array( 'example' ),
					'generic_names' => array( 'single door' ),
					'other_brands'  => array( 'otherco' ),
					'skip'          => array( 'included at no charge' ),
					'flags'         => array( 'upcharge' => array( 'custom colour' ) ),
					'vetoes'        => array( array( 'field' => 'name', 'words' => array( 'repair', 'service' ), 'reason' => 'Not a door: priced by hand.' ) ),
					'not_in_book'   => array( 'motorised' ),
					'kinds'         => array( array( 'when' => array( 'work' => 'refit' ), 'kind' => 'refit' ), array( 'when' => array(), 'kind' => 'unit' ) ),
					'selectors'     => array(
						array( 'key' => 'work', 'options' => array( 'refit' => array( 'refit' ) ), 'default' => 'new' ),
						array( 'key' => 'style', 'options' => array( 'plain' => array( 'plain' ), 'panel' => array( 'panel' ) ), 'default' => 'plain', 'applies_when' => array( 'work' => 'new' ), 'choice' => true ),
						array( 'key' => 'finish', 'options' => array( 'premium' => array( 'premium' ) ), 'default' => 'standard', 'fallback' => array( 'premium' => 'standard' ) ),
					),
					'pair'          => array( 'words' => array( 'pair', 'dbl' ), 'doubled_when' => array( 'work' => 'new' ) ),
					'single'        => array( 'max_width' => 40, 'max_pair_width' => 80, 'applies_when' => array( 'work' => 'new' ) ),
					'size'          => array( 'default_width' => 36, 'default_height' => 80 ),
					'fit'           => array( 'order' => array( 'width', 'height' ) ),
					'notes'         => array(
						array( 'when' => array( 'named:style' => false, 'work' => 'new' ), 'text' => 'No style named: priced as plain.' ),
						array( 'when' => array( 'forced_pair' => true ), 'text' => 'A {w}" opening is priced as a pair of {leaf}".' ),
						array( 'when' => array( 'from:finish' => 'premium' ), 'text' => 'No premium finish here: priced as standard.' ),
						array( 'when' => array( 'choice' => true ), 'text' => 'Both styles named: two options.' ),
						array( 'when' => array( 'size_assumed' => true ), 'text' => 'No size given: priced as a standard opening.' ),
						array( 'when' => array( 'upcharge' => true ), 'text' => 'A custom colour is extra.' ),
						array( 'when' => array( 'other_brand' => true ), 'text' => 'The sub-line also names {other}; check.' ),
						array( 'when' => array( 'pair' => true, 'work' => 'refit', 'priced' => true ), 'text' => 'A pair of refits is two refits.' ),
					),
					'labels'        => array( 'finish' => array( 'standard' => 'the standard finish' ) ),
					'reasons'       => array( 'no_fit:unit' => 'No door covers {w}" x {h}" in {finish} - price by hand.' ),
				),
			) ),
			'variants' => array(
				$v( 'EX-PL-36-80', array( 'work' => 'new', 'style' => 'plain', 'finish' => 'standard' ), 36, 80, 'Plain door 36 x 80' ),
				$v( 'EX-PL-36-96', array( 'work' => 'new', 'style' => 'plain', 'finish' => 'standard' ), 36, 96, 'Plain door 36 x 96' ),
				$v( 'EX-PL-40-96', array( 'work' => 'new', 'style' => 'plain', 'finish' => 'standard' ), 40, 96, 'Plain door 40 x 96' ),
				$v( 'EX-PLP-36-80', array( 'work' => 'new', 'style' => 'plain', 'finish' => 'premium' ), 36, 80, 'Plain door, premium finish' ),
				$v( 'EX-PN-36-80', array( 'work' => 'new', 'style' => 'panel', 'finish' => 'standard' ), 36, 80, 'Panel door 36 x 80' ),
				$v( 'EX-RF-36-80', array( 'work' => 'refit', 'finish' => 'standard' ), 36, 80, 'Refit 36 x 80' ),
				array( 'sku' => 'EX-PART-1', 'family' => 'example_doors', 'kind' => 'part', 'label' => 'Hinge kit', 'uom' => 'each' ),
			),
		) );
		$pricing = array( 'price_book' => array(
			'version'  => 'example-1',
			'families' => array( array( 'key' => 'example_doors', 'price_rules' => array( 'Plain door' => '100 each' ),
				'rules' => array( array( 'id' => 'example-free-refit', 'kind' => 'entitlement', 'applies_to_kind' => 'refit', 'text' => 'One refit free within a year.', 'price' => 0, 'window_days' => 365, 'max_uses' => 1 ) ) ) ),
			'prices'   => array(
				array( 'sku' => 'EX-PL-36-80', 'price' => 100, 'double_price' => 190 ),
				array( 'sku' => 'EX-PL-36-96', 'price' => 120, 'double_price' => 230 ),
				array( 'sku' => 'EX-PL-40-96', 'price' => 140 ),
				array( 'sku' => 'EX-PLP-36-80', 'price' => 130 ),
				array( 'sku' => 'EX-PN-36-80', 'price' => 150, 'double_price' => 290 ),
				array( 'sku' => 'EX-RF-36-80', 'price' => 40 ),
			),
		) );
		$costs = array( 'price_book' => array(
			'families' => array( array( 'key' => 'example_doors', 'source' => array( 'title' => 'Example supplier list' ) ) ),
			'costs'    => array(
				array( 'sku' => 'EX-PL-36-80', 'cost' => 50 ), array( 'sku' => 'EX-PL-36-96', 'cost' => 60 ), array( 'sku' => 'EX-PL-40-96', 'cost' => 70 ),
				array( 'sku' => 'EX-PLP-36-80', 'cost' => 65 ), array( 'sku' => 'EX-PN-36-80', 'cost' => 80 ), array( 'sku' => 'EX-RF-36-80', 'cost' => 35 ),
				array( 'sku' => 'EX-PART-1', 'cost' => 7.5 ),
			),
		) );
		return array( 'catalog' => $catalog, 'pricing' => $pricing, 'costs' => $costs );
	}

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new ZDZ_Test_Pv_Wpdb();
		$GLOBALS['zdz_test_options'] = array( 'zdz_item_engine_schema' => 1 );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		ZDZ_Privacy_View::flush();
		ZDZ_Business_Profile::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_current_user'] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		ZDZ_Capabilities::flush();
		ZDZ_Privacy_View::flush();
		if ( '' !== $this->dir ) {
			foreach ( (array) glob( $this->dir . '/example-business/*' ) as $f ) {
				unlink( $f );
			}
			@rmdir( $this->dir . '/example-business' );
			@rmdir( $this->dir );
		}
	}

	private function load_book() {
		$msgs = ZDZ_Price_Book::pack_apply( self::pack_files(), 'example-business' );
		$this->assertMatchesRegularExpression( '/^Price book applied: 1 family, 7 items\./', $msgs[0] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
	}

	private static function line( $desc, $sub = '', $dims = '' ) {
		return ZDZ_Price_Book::match_line( 'example_doors', array( 'description' => $desc, 'sub_description' => $sub, 'dimensions' => $dims ) );
	}

	/* ── an empty book ─────────────────────────────────────────────────── */

	public function test_an_empty_book_answers_nothing(): void {
		$this->assertNull( ZDZ_Price_Book::published() );
		$this->assertSame( '', ZDZ_Price_Book::version() );
		$this->assertNull( ZDZ_Price_Book::item( 'EX-PL-36-80' ) );
		$this->assertNull( ZDZ_Price_Book::price( 'EX-PL-36-80' ) );
		$this->assertNull( ZDZ_Price_Book::cost( 'EX-PL-36-80' ) );
		$this->assertSame( array(), ZDZ_Price_Book::items( 'example_doors' ) );
		$this->assertNull( ZDZ_Price_Book::family( 'example_doors' ) );
		$this->assertSame( '', ZDZ_Price_Book::prompt_block( 'example_doors' ) );
		$this->assertFalse( ZDZ_Price_Book::is_family_line( 'example_doors', 'Example door', '' ) );
		$m = self::line( 'Example door', '', '36x80' );
		$this->assertFalse( $m['matched'] );
		$this->assertNull( $m['price'] );
	}

	/* ── lookups and costs ─────────────────────────────────────────────── */

	public function test_lookups_by_item_and_kind(): void {
		$this->load_book();
		$this->assertSame( 'example-1', ZDZ_Price_Book::version() );
		$this->assertSame( 100.0, ZDZ_Price_Book::price( 'EX-PL-36-80' ) );
		$this->assertNull( ZDZ_Price_Book::price( 'EX-PART-1' ), 'no customer price: quote by hand' );
		$this->assertSame( array( 'EX-PART-1' ), array_keys( ZDZ_Price_Book::items( 'example_doors', array( 'part' ) ) ) );
		$this->assertCount( 7, ZDZ_Price_Book::items( 'example_doors' ) );
		$this->assertSame( 'Example doors', ZDZ_Price_Book::family( 'example_doors' )['label'] );
		$this->assertSame( 365, ZDZ_Price_Book::rule( 'example_doors', 'example-free-refit' )['window_days'] );
		$this->assertSame( array( 'Plain door' => '100 each' ), ZDZ_Price_Book::price_rules( 'example_doors' ) );
	}

	public function test_costs_are_never_in_the_catalog_and_hidden_below_admin(): void {
		$this->load_book();
		foreach ( ZDZ_Price_Book::published()['items'] as $it ) {
			$this->assertArrayNotHasKey( 'cost', $it, 'the live book carries prices only' );
		}
		$stored = json_encode( $GLOBALS['wpdb']->t );
		$this->assertStringNotContainsString( '"cost"', $stored, 'no cost is written into the Item Engine (its catalog is readable by every signed-in user)' );
		$this->assertStringNotContainsString( 'Example supplier list', $stored );
		$this->assertSame( 50.0, ZDZ_Price_Book::cost( 'EX-PL-36-80' ), 'server-side cost of goods' );

		$GLOBALS['zdz_test_user_roles'] = array( 1 => array( 'administrator' ), 2 => array( 'zdz_sales' ), 3 => array( 'zdz_tech' ), 4 => array( 'some_plugin_role' ), 5 => array( 'zdz_owner' ) );
		$GLOBALS['zdz_test_user_meta']  = array( 5 => array( 'zdz_privacy_view' => '1' ) );
		$this->assertSame( 50.0, ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 1 ), 'an administrator sees the cost' );
		$this->assertNull( ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 2 ), 'staff rung: no cost' );
		$this->assertNull( ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 3 ), 'viewer rung: no cost' );
		$this->assertNull( ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 4 ), 'an unknown role: no cost' );
		$this->assertNull( ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 5 ), 'never in Privacy View, even for an owner' );
		$this->assertNull( ZDZ_Price_Book::cost_visible( 'EX-PL-36-80', 0 ) );
		$this->assertArrayNotHasKey( 'cost', ZDZ_Price_Book::item_for( 'EX-PL-36-80', 2 ) );
		$this->assertSame( 50.0, ZDZ_Price_Book::item_for( 'EX-PL-36-80', 1 )['cost'] );
		$this->assertNull( ZDZ_Price_Book::cost( 'EX-NOT-THERE' ) );
	}

	public function test_prompt_block_holds_prices_never_costs(): void {
		$this->load_book();
		$p = ZDZ_Price_Book::prompt_block( 'example_doors' );
		$this->assertStringContainsString( 'Plain door: 100 each', $p );
		$this->assertStringContainsString( 'One refit free within a year.', $p );
		$this->assertStringContainsString( 'Write the style (plain or panel)', $p );
		$this->assertStringNotContainsString( '50', $p );
		$this->assertStringNotContainsString( 'supplier', $p );
	}

	/* ── matching ──────────────────────────────────────────────────────── */

	public function test_which_lines_are_the_familys(): void {
		$this->load_book();
		$this->assertTrue( ZDZ_Price_Book::is_family_line( 'example_doors', 'Example Door', '' ) );
		$this->assertFalse( ZDZ_Price_Book::is_family_line( 'example_doors', 'Otherco Example Door', '' ), 'another brand in the name' );
		$this->assertTrue( ZDZ_Price_Book::is_family_line( 'example_doors', 'Single Door', 'White Example' ), 'a generic name with the brand in the sub-line' );
		$this->assertFalse( ZDZ_Price_Book::is_family_line( 'example_doors', 'Single Door', 'Otherco replaces an Example' ) );
		$this->assertFalse( ZDZ_Price_Book::is_family_line( 'example_doors', 'Window', 'Example' ) );
	}

	public function test_a_line_is_priced_by_style_and_size(): void {
		$this->load_book();
		$m = self::line( 'Example door', '', '36x80' );
		$this->assertTrue( $m['matched'] );
		$this->assertSame( 'EX-PL-36-80', $m['sku'] );
		$this->assertSame( 100.0, $m['price'] );
		$this->assertContains( 'No style named: priced as plain.', $m['assumed'] );
		$m = self::line( 'Example panel door', '', '36" x 80"' );
		$this->assertSame( 'EX-PN-36-80', $m['sku'] );
		$this->assertNotContains( 'No style named: priced as plain.', $m['assumed'] );
		$this->assertSame( 'EX-PL-36-96', self::line( 'Example door 36 x 90' )['sku'], 'the smallest covering height' );
		$this->assertSame( 'EX-PL-40-96', self::line( 'Example door', '', '38x90' )['sku'] );
	}

	public function test_wide_openings_become_pairs_or_are_refused(): void {
		$this->load_book();
		$m = self::line( 'Example door', '', '60x80' );
		$this->assertTrue( $m['double'] );
		$this->assertTrue( $m['forced_pair'] );
		$this->assertSame( 'EX-PL-36-80', $m['sku'] );
		$this->assertSame( 190.0, $m['price'], 'the pair price' );
		$this->assertContains( 'A 60" opening is priced as a pair of 30".', $m['assumed'] );
		$m = self::line( 'Example door', '', '90x80' );
		$this->assertNull( $m['price'] );
		$this->assertMatchesRegularExpression( '/90/', $m['reason'] );
		$p = self::line( 'Dbl Example door', '', '72x80' );
		$this->assertSame( 190.0, $p['price'] );
		$this->assertArrayNotHasKey( 'forced_pair', $p, 'a pair that was asked for is not forced' );
	}

	public function test_assumptions_fallbacks_and_vetoes(): void {
		$this->load_book();
		$m = self::line( 'Example door' );
		$this->assertSame( 'EX-PL-36-80', $m['sku'] );
		$this->assertContains( 'No size given: priced as a standard opening.', $m['assumed'] );
		$m = self::line( 'Example panel door premium', '', '36x80' );
		$this->assertSame( 'EX-PN-36-80', $m['sku'], 'no premium panel: falls back to standard' );
		$this->assertContains( 'No premium finish here: priced as standard.', $m['assumed'] );
		$this->assertSame( 'EX-PLP-36-80', self::line( 'Example plain door premium', '', '36x80' )['sku'] );
		$r = self::line( 'Example door repair' );
		$this->assertSame( 'other', $r['kind'] );
		$this->assertNull( $r['price'] );
		$this->assertSame( 'Not a door: priced by hand.', $r['reason'] );
		$n = self::line( 'Example motorised door', '', '36x80' );
		$this->assertTrue( $n['matched'] );
		$this->assertNull( $n['price'] );
		$f = self::line( 'Example refit', '', '36x80' );
		$this->assertSame( 'EX-RF-36-80', $f['sku'], 'the style does not apply to a refit' );
		$this->assertSame( 'refit', $f['kind'] );
		$this->assertSame( 40.0, $f['price'] );
		$this->assertFalse( self::line( 'Example refit', 'Included at no charge' )['matched'], 'a line the system wrote is not re-priced' );
		$this->assertContains( 'A custom colour is extra.', self::line( 'Example door', 'custom colour', '36x80' )['assumed'] );
		$this->assertContains( 'The sub-line also names Otherco; check.', self::line( 'Example door', 'replaces an otherco', '36x80' )['assumed'] );
	}

	public function test_reasons_and_notes_follow_the_familys_words(): void {
		$this->load_book();
		$m = self::line( 'Example door', '', '36x120' );
		$this->assertSame( 'No door covers 36" x 120" in the standard finish - price by hand.', $m['reason'], 'a reason per kind, a value in the family\'s words' );
		$this->assertContains( 'A pair of refits is two refits.', self::line( 'Example refit pair', '', '60x80' )['assumed'] );
		$this->assertNotContains( 'A pair of refits is two refits.', self::line( 'Example refit pair', '', '60x120' )['assumed'], 'a note that needs a price waits for one' );
	}

	public function test_notes_are_never_read(): void {
		$this->load_book();
		$m = ZDZ_Price_Book::match_line( 'example_doors', array( 'description' => 'Example door', 'dimensions' => '36x80', 'notes' => 'pair, motorised, repair' ) );
		$this->assertSame( 100.0, $m['price'] );
		$this->assertFalse( $m['double'] );
	}

	public function test_two_choices_named_split_into_options(): void {
		$this->load_book();
		$m = self::line( 'Example door, plain or panel', '', '36x80' );
		$this->assertTrue( $m['both_choices'] );
		$this->assertSame( 'style', $m['choice_key'] );
		$this->assertSame( array( 'EX-PL-36-80', 'EX-PN-36-80' ), array( $m['options'][0]['sku'], $m['options'][1]['sku'] ) );
		$this->assertSame( array( 100.0, 150.0 ), array( $m['options'][0]['price'], $m['options'][1]['price'] ) );
		$this->assertSame( 'Both styles named: two options.', $m['assumed'][0] );
		$this->assertSame( 'Example Plain Door', $m['options'][0]['line_item'] );
	}

	public function test_sizes_are_read_in_any_common_form(): void {
		$this->assertSame( array( 36.5, 80.0 ), ZDZ_Price_Book::parse_wh( '36-1/2" x 80"' ) );
		$this->assertSame( array( 36.0, 80.0 ), ZDZ_Price_Book::parse_wh( '80" H x 36" W' ), 'labels win over order' );
		$this->assertSame( array( 36.0, 80.0 ), ZDZ_Price_Book::parse_wh( "3' x 6'8\"" ) );
		$this->assertSame( array( 36.0, 96.0 ), ZDZ_Price_Book::parse_wh( '36 by 96' ) );
		$this->assertEquals( 80.0, ZDZ_Price_Book::parse_wh( '914mm x 2032mm' )[1] );
		$this->assertSame( array( null, null ), ZDZ_Price_Book::parse_wh( '0 x 80' ), 'a zero side is a missing size' );
		$this->assertSame( array( null, null ), ZDZ_Price_Book::parse_wh( 'no size here' ) );
	}

	public function test_the_item_engine_grid_prices_by_sku_or_size(): void {
		$this->load_book();
		$scheme = ZDZ_Item_Engine::pricing_scheme( 'price-book-example-doors' );
		$this->assertSame( 'grid', $scheme['method'] );
		$this->assertSame( 120.0, ZDZ_Item_Engine::resolve_price( $scheme, array( 'sku' => 'EX-PL-36-96' ) )['amount'] );
		$this->assertSame( 230.0, ZDZ_Item_Engine::resolve_price( $scheme, array( 'sku' => 'EX-PL-36-96', 'pair' => true ) )['amount'] );
		$this->assertSame( 100.0, ZDZ_Item_Engine::resolve_price( $scheme, array( 'width_in' => 30, 'height_in' => 70, 'attributes' => array( 'style' => 'plain', 'finish' => 'standard', 'work' => 'new' ) ) )['amount'] );
		$q = ZDZ_Item_Engine::resolve_price( $scheme, array( 'sku' => 'EX-PART-1' ) );
		$this->assertTrue( $q['quote_only'] );
		$this->assertNull( $q['amount'] );
	}

	/* ── validation ─────────────────────────────────────────────────────── */

	public function test_validation_refuses_bad_rows_and_flags_margins(): void {
		list( $errors, $warnings, $clean ) = ZDZ_Price_Book::validate_family( array(
			'family'   => array( 'key' => 'example_doors' ),
			'variants' => array(
				array( 'sku' => 'bad sku', 'kind' => 'unit' ),
				array( 'sku' => 'EX-A', 'kind' => 'unit', 'price' => -1 ),
				array( 'sku' => 'EX-B', 'kind' => 'unit', 'price' => 10, 'cost' => 20 ),
				array( 'sku' => 'EX-B', 'kind' => 'unit' ),
			),
		) );
		$this->assertNull( $clean );
		$this->assertCount( 3, $errors );
		$this->assertContains( 'EX-B: price is below cost.', $warnings );
		list( $errors, $warnings ) = ZDZ_Price_Book::validate_family( array( 'family' => array( 'key' => 'example_doors' ), 'variants' => array( array( 'sku' => 'EX-C', 'kind' => 'unit', 'price' => 100, 'cost' => 90 ) ) ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( array( 'EX-C: thin margin.' ), $warnings );
		list( $errors ) = ZDZ_Price_Book::validate_family( array( 'family' => array( 'key' => 'Bad Key' ), 'variants' => array() ) );
		$this->assertCount( 2, $errors );
	}

	public function test_a_rule_keeps_what_earns_it_and_the_words_its_line_carries(): void {
		$fam = array( 'key' => 'example_doors', 'rules' => array(
			array( 'id' => 'free-visit', 'kind' => 'entitlement', 'price' => 0, 'window_days' => 365, 'max_uses' => 1,
				'qualifying_min' => '250.5', 'qualifying_kinds' => array( 'unit', '', array( 'x' ), 'service' ), 'line_text' => '  At no charge  ', 'fallback_sku' => 'EX-A' ),
			array( 'id' => 'no-minimum', 'kind' => 'entitlement', 'qualifying_min' => 'lots', 'fallback_sku' => 'bad sku' ),
			array( 'id' => 'below-zero', 'kind' => 'entitlement', 'qualifying_min' => -5, 'line_text' => str_repeat( 'w', 200 ) ),
			array( 'id' => 'X', 'kind' => 'entitlement' ),
		) );
		list( $errors, , $clean ) = ZDZ_Price_Book::validate_family( array( 'family' => $fam, 'variants' => array( array( 'sku' => 'EX-A', 'kind' => 'unit', 'price' => 100 ) ) ) );
		$this->assertSame( array(), $errors );
		$rules = $clean['family']['rules'];
		$this->assertCount( 3, $rules, 'a rule with a bad id is dropped' );
		$this->assertSame( 250.5, $rules[0]['qualifying_min'] );
		$this->assertSame( array( 'unit', 'service' ), $rules[0]['qualifying_kinds'], 'kinds are words; empty and non-words are dropped' );
		$this->assertSame( 'At no charge', $rules[0]['line_text'] );
		$this->assertSame( 'EX-A', $rules[0]['fallback_sku'] );
		$this->assertNull( $rules[1]['qualifying_min'], 'no number: no minimum (and so nothing is earned)' );
		$this->assertSame( '', $rules[1]['fallback_sku'], 'only a SKU-shaped fallback is kept' );
		$this->assertSame( 0.0, $rules[2]['qualifying_min'], 'never below zero' );
		$this->assertLessThanOrEqual( 120, strlen( $rules[2]['line_text'] ) );
	}

	public function test_a_price_or_cost_for_an_unknown_sku_refuses_the_pack(): void {
		$files = self::pack_files();
		$files['pricing']['price_book']['prices'][] = array( 'sku' => 'EX-TYPO', 'price' => 5 );
		$book = ZDZ_Price_Book::pack_book( $files );
		$this->assertNotEmpty( $book['errors'] );
		$this->assertSame( 'price book NOT applied: ' . $book['errors'][0], ZDZ_Price_Book::pack_apply( $files, 'x' )[0] );
		$this->assertNull( ZDZ_Price_Book::published(), 'nothing written' );
		$this->assertNull( ZDZ_Price_Book::pack_book( array( 'knowledge' => array() ) ), 'no price_book block: not this consumer\'s' );
	}

	/* ── the Identity Pack: preview, apply, revert ─────────────────────── */

	public function test_pack_previews_applies_and_reverts_through_the_identity_pack(): void {
		$this->dir = sys_get_temp_dir() . '/zdz-pb-packs-' . getmypid();
		@mkdir( $this->dir . '/example-business', 0700, true );
		foreach ( self::pack_files() as $stem => $data ) {
			file_put_contents( $this->dir . '/example-business/' . $stem . '.json', json_encode( $data ) );
		}
		file_put_contents( $this->dir . '/example-business/pack.json', json_encode( array( 'label' => 'Example (test)' ) ) );
		$dir = $this->dir;
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_paths']     = function () use ( $dir ) { return array( $dir ); };
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] = function ( $c ) { return ZDZ_Price_Book::pack_consumer( ZDZ_Privacy_View::pack_consumer( $c ) ); };

		$packs = ZDZ_Identity_Pack::discover();
		$this->assertSame( array(), $packs['example-business']['unsupported'], 'catalog, pricing and costs are read now' );

		$p     = ZDZ_Identity_Pack::preview( 'example-business' );
		$paths = array_column( $p['changes'], 'path' );
		$this->assertContains( 'price_book.items', $paths );
		$this->assertSame( array(), $p['errors'] );
		$this->assertNull( ZDZ_Price_Book::published(), 'a preview writes nothing' );

		$r = ZDZ_Identity_Pack::apply( 'example-business', true );
		$this->assertTrue( $r['ok'] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		$this->assertSame( 100.0, ZDZ_Price_Book::price( 'EX-PL-36-80' ) );
		$this->assertSame( 50.0, ZDZ_Price_Book::cost( 'EX-PL-36-80' ) );

		$v = ZDZ_Identity_Pack::revert();
		$this->assertTrue( $v['ok'] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		$this->assertNull( ZDZ_Price_Book::published(), 'revert takes the book away again' );
		$this->assertArrayNotHasKey( ZDZ_Price_Book::COSTS_OPT, $GLOBALS['zdz_test_options'] );
		$this->assertArrayNotHasKey( ZDZ_Price_Book::VERSION_OPT, $GLOBALS['zdz_test_options'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->t['wp_zdz_items'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->t['wp_zdz_pricing_schemes'] );
	}

	public function test_revert_restores_a_book_that_was_there_before(): void {
		$this->load_book();
		$GLOBALS['zdz_test_options'][ ZDZ_Price_Book::COSTS_OPT ]['items']['EX-PL-36-80']['cost'] = 55;
		ZDZ_Price_Book::pack_snapshot();
		$files = self::pack_files();
		$files['pricing']['price_book']['prices'][0]['price'] = 105;
		ZDZ_Price_Book::pack_apply( $files, 'example-business' );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		$this->assertSame( 105.0, ZDZ_Price_Book::price( 'EX-PL-36-80' ) );
		$this->assertSame( 50.0, ZDZ_Price_Book::cost( 'EX-PL-36-80' ) );
		ZDZ_Price_Book::pack_restore( null );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		$this->assertSame( 100.0, ZDZ_Price_Book::price( 'EX-PL-36-80' ), 'the earlier price is back' );
		$this->assertSame( 55.0, ZDZ_Price_Book::cost( 'EX-PL-36-80' ), 'and the earlier cost' );
	}

	public function test_privacy_view_reads_the_books_product_words(): void {
		$this->load_book();
		ZDZ_Privacy_View::flush();
		$terms = ZDZ_Privacy_View::product_terms();
		$this->assertArrayHasKey( 'example', $terms['words'] );
		$this->assertArrayHasKey( 'panel', $terms['words'] );
	}
}
