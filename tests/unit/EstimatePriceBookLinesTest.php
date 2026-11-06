<?php
/**
 * Estimates: lines priced from the business's price book.
 *
 *   - a line of a book family gets the book's price; a line the book can't price keeps its price with a flag; a line of
 *     no family, a discount and the location line are never touched; without a book nothing changes;
 *   - a line naming both choices of a split selector becomes two priced option lines; removing one takes the option
 *     wording off the other, and Approve refuses while any line still carries it;
 *   - an entitlement (a free service earned by an earlier purchase) is given once, only when the earlier purchase
 *     reaches the rule's minimum, reserved in the book so a second estimate can't take it too; a rule that does not say
 *     what earns it gives nothing; a free line the book does not hold for this estimate goes back to its price;
 *   - the warranty line comes from the family's pack, added once.
 *
 * Neutral data only: a made-up "Example" widget family with round numbers.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-est-pb-no-content' );
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
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-est-pb-no-theme'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return (object) array( 'role_names' => array() ); }
}

require_once __DIR__ . '/support/estimate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-item-engine.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-price-book.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-doc-conventions.php';

if ( ! class_exists( 'ZDZ_Test_Est_PB_DB' ) ) {
	/** The Item Engine's two tables and the price book's entitlement table, in memory. */
	final class ZDZ_Test_Est_PB_DB {
		public $prefix     = 'wp_';
		public $last_error = '';
		public $insert_id  = 0;
		public $t          = array( 'wp_zdz_items' => array(), 'wp_zdz_pricing_schemes' => array(), 'wp_zdz_price_book_entitlements' => array() );
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
		public function esc_like( $s ) { return $s; }
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
			if ( preg_match( "/FROM wp_zdz_price_book_entitlements WHERE rule_id = '([^']*)' AND client_id = '([^']*)' AND qualifying_doc = '([^']*)' AND live = 1/", $q, $m ) ) {
				foreach ( $this->t['wp_zdz_price_book_entitlements'] as $r ) {
					if ( $r['rule_id'] === $m[1] && $r['client_id'] === $m[2] && $r['qualifying_doc'] === $m[3] && 1 === $r['live'] ) {
						return $r;
					}
				}
				return null;
			}
			if ( preg_match( '/FROM wp_zdz_price_book_entitlements WHERE id = (\d+)/', $q, $m ) ) {
				return $this->t['wp_zdz_price_book_entitlements'][ (int) $m[1] ] ?? null;
			}
			return null;
		}
		public function get_col( $q ) { return array(); }
		public function insert( $table, $data ) {
			if ( 'wp_zdz_price_book_entitlements' === $table ) {
				$this->insert_id = count( $this->t[ $table ] ) + 1;
				$this->t[ $table ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data;
				return 1;
			}
			$this->t[ $table ][ (string) $data['id'] ] = $data;
			return 1;
		}
		public function update( $table, $data, $where ) {
			$id = (string) $where['id'];
			if ( ! isset( $this->t[ $table ][ $id ] ) ) {
				return 0;
			}
			if ( array_key_exists( 'live', $where ) && $this->t[ $table ][ $id ]['live'] !== $where['live'] ) {
				return 0;
			}
			$this->t[ $table ][ $id ] = array_merge( $this->t[ $table ][ $id ], $data );
			return 1;
		}
		public function delete( $table, $where ) {
			$had = isset( $this->t[ $table ][ (string) $where['id'] ] );
			unset( $this->t[ $table ][ (string) $where['id'] ] );
			return $had ? 1 : 0;
		}
	}
}

final class EstimatePriceBookLinesTest extends TestCase {

	/** A made-up widget family: plain and panel styles, a refit service, one free refit after a big enough purchase. */
	private static function pack( array $rule_over = array() ): array {
		$v = function ( $sku, array $attr, $w, $h, $label ) {
			return array( 'sku' => $sku, 'family' => 'example_widgets', 'kind' => 'refit' === ( $attr['work'] ?? '' ) ? 'refit' : 'unit', 'brand' => 'Example',
				'model' => $attr['style'] ?? 'refit', 'label' => $label, 'line_item' => 'Example ' . ucfirst( $attr['style'] ?? 'refit' ) . ' Widget',
				'dims' => array( 'max_width_in' => $w, 'max_height_in' => $h ), 'attributes' => $attr, 'uom' => 'each' );
		};
		$rule = $rule_over + array(
			'id' => 'example-free-refit', 'kind' => 'entitlement', 'applies_to_kind' => 'refit', 'text' => 'One refit at no charge after a purchase.',
			'price' => 0, 'window_days' => 365, 'max_uses' => 1, 'qualifying_min' => 200, 'qualifying_kinds' => array( 'unit' ), 'line_text' => 'At no charge',
		);
		$rule = array_filter( $rule, static function ( $x ) { return null !== $x; } );
		return array(
			'catalog' => array( 'price_book' => array(
				'families' => array( array(
					'key'     => 'example_widgets',
					'label'   => 'Example widgets',
					'brand'   => 'Example',
					'aliases' => array( 'example widget' ),
					'prompt'  => 'Write the style (plain or panel) and the opening as W x H.',
					'match'   => array(
						'names'         => array( 'example' ),
						'kinds'         => array( array( 'when' => array( 'work' => 'refit' ), 'kind' => 'refit' ), array( 'when' => array(), 'kind' => 'unit' ) ),
						'selectors'     => array(
							array( 'key' => 'work', 'options' => array( 'refit' => array( 'refit' ) ), 'default' => 'new' ),
							array( 'key' => 'style', 'options' => array( 'plain' => array( 'plain' ), 'panel' => array( 'panel' ) ), 'default' => 'plain', 'applies_when' => array( 'work' => 'new' ), 'choice' => true ),
						),
						'pair'          => array( 'words' => array( 'pair', 'dbl' ), 'doubled_when' => array( 'work' => 'new' ) ),
						'single'        => array( 'max_width' => 40, 'max_pair_width' => 80, 'applies_when' => array( 'work' => 'new' ) ),
						'size'          => array( 'default_width' => 36, 'default_height' => 80 ),
						'fit'           => array( 'order' => array( 'width', 'height' ) ),
						'notes'         => array(
							array( 'when' => array( 'choice' => true ), 'text' => 'Both styles named: two options.' ),
							array( 'when' => array( 'size_assumed' => true ), 'text' => 'No size given: priced as a standard opening.' ),
						),
						'reasons'       => array( 'no_fit:unit' => 'No widget covers {w}" x {h}" - price by hand.' ),
						'warranty_url'  => 'https://example.com/warranty/widgets',
						'warranty_line' => 'Widget warranty: {url}',
					),
				) ),
				'variants' => array(
					$v( 'EW-PL-36-80', array( 'work' => 'new', 'style' => 'plain' ), 36, 80, 'Plain widget 36 x 80' ),
					$v( 'EW-PN-36-80', array( 'work' => 'new', 'style' => 'panel' ), 36, 80, 'Panel widget 36 x 80' ),
					$v( 'EW-RF-36-80', array( 'work' => 'refit' ), 36, 80, 'Refit 36 x 80' ),
				),
			) ),
			'pricing' => array( 'price_book' => array(
				'version'  => 'example-1',
				'families' => array( array( 'key' => 'example_widgets', 'price_rules' => array( 'Plain widget' => '100 each' ), 'rules' => array( $rule ) ) ),
				'prices'   => array(
					array( 'sku' => 'EW-PL-36-80', 'price' => 100, 'double_price' => 190 ),
					array( 'sku' => 'EW-PN-36-80', 'price' => 150 ),
					array( 'sku' => 'EW-RF-36-80', 'price' => 40 ),
				),
			) ),
			'costs'   => array( 'price_book' => array(
				'families' => array( array( 'key' => 'example_widgets', 'source' => array( 'title' => 'Example list' ) ) ),
				'costs'    => array( array( 'sku' => 'EW-PL-36-80', 'cost' => 50 ), array( 'sku' => 'EW-PN-36-80', 'cost' => 80 ), array( 'sku' => 'EW-RF-36-80', 'cost' => 20 ) ),
			) ),
		);
	}

	protected function setUp(): void {
		$GLOBALS['wpdb']                  = new ZDZ_Test_Est_PB_DB();
		$GLOBALS['zdz_test_options']      = array( 'zdz_item_engine_schema' => 1, ZDZ_Price_Book::SCHEMA_OPT => ZDZ_Price_Book::SCHEMA_VERSION );
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_current_user'] = 91;
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		ZDZ_Privacy_View::flush();
		ZDZ_Business_Profile::flush();
		ZDZ_Doc_Conventions::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_current_user'] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
		ZDZ_Capabilities::flush();
		ZDZ_Privacy_View::flush();
		ZDZ_Doc_Conventions::flush();
	}

	private function load( array $rule_over = array() ): void {
		$msgs = ZDZ_Price_Book::pack_apply( self::pack( $rule_over ), 'example-business' );
		$this->assertMatchesRegularExpression( '/^Price book applied/', $msgs[0] );
		ZDZ_Item_Engine::flush();
		ZDZ_Price_Book::flush();
	}

	private static function li( string $desc, string $sub = '', float $price = 0.0, int $qty = 1, string $dims = '36x80' ): array {
		return array( 'description' => $desc, 'sub_description' => $sub, 'unit_price' => $price, 'quantity' => $qty, 'dimensions' => $dims );
	}

	public function test_without_a_book_nothing_changes(): void {
		$items = array( self::li( 'Example widget', 'Plain', 999 ) );
		$this->assertSame( array( 'items' => $items, 'flags' => array() ), ZEST_Price_Book_Lines::price( $items ) );
		$this->assertSame( '', ZEST_Price_Book_Lines::prompt_block() );
		$this->assertSame( '', ZEST_Warranty_Note::line_for( 'example_widgets' ) );
	}

	public function test_family_lines_take_the_book_price_and_others_are_left_alone(): void {
		$this->load();
		$res = ZEST_Price_Book_Lines::price( array(
			self::li( 'Example widget', 'Plain, white', 120 ),
			self::li( 'Window cleaning', '', 75 ),
			self::li( 'Discount', '', -10 ),
			self::li( 'Location', 'Exampleton - (AA)', 0 ),
		) );
		$this->assertSame( 100.0, $res['items'][0]['unit_price'] );
		$this->assertSame( 'EW-PL-36-80', $res['items'][0]['pb']['sku'] );
		$this->assertNotEmpty( $res['flags'], 'a changed price is flagged for the review' );
		$this->assertStringNotContainsString( 'price book', strtolower( $res['items'][0]['sub_description'] ), 'never on the customer\'s words' );
		$this->assertSame( 75.0, (float) $res['items'][1]['unit_price'] );
		$this->assertArrayNotHasKey( 'pb', $res['items'][1] );
		$this->assertSame( -10.0, (float) $res['items'][2]['unit_price'] );
		$this->assertArrayNotHasKey( 'pb', $res['items'][3] );
		$this->assertStringContainsString( 'Plain widget: 100 each', ZEST_Price_Book_Lines::prompt_block() );
		$this->assertStringNotContainsString( '50', ZEST_Price_Book_Lines::prompt_block(), 'prices only, never a cost' );
	}

	public function test_a_wide_opening_is_named_a_pair_and_a_pair_of_services_is_two(): void {
		$this->load();
		$res = ZEST_Price_Book_Lines::price( array(
			self::li( 'Example widget', 'Plain', 0, 1, '60x80' ),
			self::li( 'Example widget refit pair', '', 0, 1, '60x80' ),
			self::li( 'Dbl Example widget', 'Plain', 0, 1, '72x80' ),
		) );
		$this->assertSame( 'Pair Example widget', $res['items'][0]['description'], 'priced as a pair, so it says so' );
		$this->assertSame( 190.0, $res['items'][0]['unit_price'] );
		$this->assertSame( 1, (int) $res['items'][0]['quantity'] );
		$this->assertSame( 40.0, $res['items'][1]['unit_price'] );
		$this->assertSame( 2, (int) $res['items'][1]['quantity'], 'a pair priced one at a time is two' );
		$this->assertSame( 'Dbl Example widget', $res['items'][2]['description'], 'a pair that was asked for is left as written' );
		$this->assertSame( 1, (int) $res['items'][2]['quantity'] );
	}

	public function test_a_line_the_book_cannot_price_keeps_its_price_with_a_flag(): void {
		$this->load();
		$res = ZEST_Price_Book_Lines::price( array( self::li( 'Example widget', 'Plain', 333, 1, '60x120' ) ) );
		$this->assertSame( 333.0, (float) $res['items'][0]['unit_price'] );
		$this->assertFalse( $res['items'][0]['pb']['priced'] );
		$this->assertNotEmpty( $res['flags'] );
	}

	public function test_both_choices_become_two_priced_options_and_removing_one_cleans_the_other(): void {
		$this->load();
		$res = ZEST_Price_Book_Lines::price( array( self::li( 'Example widget', 'Plain or panel', 0 ) ) );
		$this->assertCount( 2, $res['items'] );
		$prices = array_map( static function ( $l ) { return (float) $l['unit_price']; }, $res['items'] );
		sort( $prices );
		$this->assertSame( array( 100.0, 150.0 ), $prices );
		$this->assertTrue( ZEST_Price_Book_Lines::has_options( $res['items'] ), 'Approve refuses while both are on' );
		$one = ZEST_Price_Book_Lines::clean_lone_option( array( $res['items'][0] ), 'sub_description' );
		$this->assertFalse( ZEST_Price_Book_Lines::has_options( $one ) );

		// the line editor: removing one option cleans its partner on the billing copy
		$lines = array(
			array( 'name' => 'Example Plain Widget', 'description' => $res['items'][0]['sub_description'], 'qty' => 1, 'rate' => 100, 'keep' => array() ),
			array( 'name' => 'Example Panel Widget', 'description' => $res['items'][1]['sub_description'], 'qty' => 1, 'rate' => 150, 'keep' => array() ),
		);
		$after = ZEST_Line_Edits::apply( $lines, array( array( 'type' => 'remove', 'line' => 1 ) ), array( 'text' => true, 'qty' => true, 'price' => true, 'add_remove' => true ) );
		$this->assertTrue( $after['ok'] );
		$this->assertFalse( ZEST_Price_Book_Lines::has_options( array( array( 'description' => $after['lines'][0]['description'] ) ) ) );
	}

	public function test_an_entitlement_is_given_once_and_only_for_a_big_enough_purchase(): void {
		$this->load();
		$big   = array( 'invoice_number' => '7001', 'create_date' => '2026-03-01', 'lines' => array( array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => 3, 'unit_cost' => array( 'amount' => 100 ) ) ) );
		$small = array( 'invoice_number' => '7002', 'create_date' => '2026-03-02', 'lines' => array( array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => 1, 'unit_cost' => array( 'amount' => 100 ) ) ) );
		$credit = array( 'invoice_number' => '7003', 'create_date' => '2026-03-03', 'lines' => array(
			array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => 3, 'unit_cost' => array( 'amount' => 100 ) ),
			array( 'name' => 'Credit', 'description' => 'Return', 'qty' => 1, 'unit_cost' => array( 'amount' => -150 ) ),
		) );
		$rule = ZDZ_Price_Book::rule( 'example_widgets', 'example-free-refit' );
		$this->assertSame( 200.0, (float) $rule['qualifying_min'], 'the book keeps what earns the rule' );
		$this->assertTrue( ZEST_Price_Book_Lines::invoice_qualifies( $big, 'example_widgets', $rule ) );
		$this->assertFalse( ZEST_Price_Book_Lines::invoice_qualifies( $small, 'example_widgets', $rule ) );
		$this->assertFalse( ZEST_Price_Book_Lines::invoice_qualifies( $credit, 'example_widgets', $rule ), 'net of credits' );

		$items  = array( self::li( 'Example widget', 'Refit', 40, 2 ) );
		$grants = ZEST_Price_Book_Lines::apply_entitlements( $items, 'c1', '', static function () use ( $small, $big ) { return array( $small, $big ); } );
		$this->assertCount( 1, $grants );
		$this->assertSame( '7001', $grants[0]['invoice'] );
		$this->assertCount( 2, $items, 'one free, the rest at the book price' );
		$this->assertSame( 0.0, (float) $items[0]['unit_price'] );
		$this->assertStringContainsString( 'At no charge', $items[0]['sub_description'] );
		$this->assertSame( 1, (int) $items[1]['quantity'] );
		ZEST_Price_Book_Lines::confirm( $grants, 'est:501' );

		// a second estimate for the same customer: that purchase's entitlement is used
		$again  = array( self::li( 'Example widget', 'Refit', 40 ) );
		$grants = ZEST_Price_Book_Lines::apply_entitlements( $again, 'c1', 'est:502', static function () use ( $big ) { return array( $big ); } );
		$this->assertSame( array(), $grants );
		$this->assertSame( 40.0, (float) $again[0]['unit_price'] );

		// a free line copied onto another estimate goes back to its price
		$copied = array( $items[0] );
		ZEST_Price_Book_Lines::apply_entitlements( $copied, 'c1', 'est:503', static function () { return array(); } );
		$this->assertSame( 40.0, (float) $copied[0]['unit_price'] );
		$this->assertStringNotContainsString( 'At no charge', $copied[0]['sub_description'] );

		// the estimate it was confirmed on keeps it
		$kept = array( $items[0] );
		$g    = ZEST_Price_Book_Lines::apply_entitlements( $kept, 'c1', 'est:501', static function () { return array(); } );
		$this->assertSame( 0.0, (float) $kept[0]['unit_price'] );
		$this->assertTrue( $g[0]['existing'] );
	}

	public function test_a_rule_that_does_not_say_what_earns_it_gives_nothing(): void {
		$this->load( array( 'qualifying_min' => null ) );
		$big   = array( 'invoice_number' => '7001', 'create_date' => '2026-03-01', 'lines' => array( array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => 9, 'unit_cost' => array( 'amount' => 100 ) ) ) );
		$items = array( self::li( 'Example widget', 'Refit', 40 ) );
		$this->assertSame( array(), ZEST_Price_Book_Lines::apply_entitlements( $items, 'c1', '', static function () use ( $big ) { return array( $big ); } ) );
		$this->assertSame( 40.0, (float) $items[0]['unit_price'] );
	}

	public function test_a_released_reservation_can_be_given_again(): void {
		$this->load();
		$big    = array( 'invoice_number' => '7001', 'create_date' => '2026-03-01', 'lines' => array( array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => 3, 'unit_cost' => array( 'amount' => 100 ) ) ) );
		$items  = array( self::li( 'Example widget', 'Refit', 40 ) );
		$grants = ZEST_Price_Book_Lines::apply_entitlements( $items, 'c1', '', static function () use ( $big ) { return array( $big ); } );
		$this->assertCount( 1, $grants );
		ZEST_Price_Book_Lines::release( $grants );   // the billing write failed
		$again = array( self::li( 'Example widget', 'Refit', 40 ) );
		$this->assertCount( 1, ZEST_Price_Book_Lines::apply_entitlements( $again, 'c1', '', static function () use ( $big ) { return array( $big ); } ) );
	}

	public function test_the_warranty_line_comes_from_the_pack_once(): void {
		$this->load();
		$this->assertSame( 'Widget warranty: https://example.com/warranty/widgets', ZEST_Warranty_Note::line_for( 'example_widgets' ) );
		$lines = array( self::li( 'Example widget', 'Plain' ) );
		$notes = ZEST_Warranty_Note::add( 'Thank you.', $lines );
		$this->assertStringContainsString( 'https://example.com/warranty/widgets', $notes );
		$this->assertSame( $notes, ZEST_Warranty_Note::add( $notes, $lines ), 'never stacked' );
		$this->assertSame( 'Thank you.', ZEST_Warranty_Note::add( 'Thank you.', array( self::li( 'Window cleaning' ) ) ) );
	}
}
