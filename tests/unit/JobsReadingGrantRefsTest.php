<?php
/**
 * Projects: the reading grant, the default tiles, and many references per namespace.
 *
 *   - Read callers ask the read gate and write callers the write gate (`view_team_projects` counts only for
 *     reading; its permission checks run in their own process: zorderz-apps/apps/jobs/tests/test-reading-grant.php).
 *   - The Projects hub is granted by default like the Jobs tile; a denial is respected; never the shared device.
 *   - A Project holds many references in one namespace (two estimates, two invoices, every job); re-recording is a
 *     no-op; one record still belongs to one Project; the first recorded is the single-value answer.
 *   - The refs table is re-keyed by one ALTER that keeps every row, and only when it needs it.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $v ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $v; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
$zdz_wb_jobs = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
require_once $zdz_wb_jobs . 'flow/class-zdz-flow.php';
require_once $zdz_wb_jobs . 'class-zjob-db.php';
require_once $zdz_wb_jobs . 'class-zjob-scope.php';
require_once $zdz_wb_jobs . 'class-zjob-project.php';
require_once $zdz_wb_jobs . 'class-zjob-project-visibility.php';
require_once $zdz_wb_jobs . 'class-zjob-project-refs.php';
require_once $zdz_wb_jobs . 'class-zjob-tile-grant.php';
unset( $zdz_wb_jobs );

/** An in-memory refs table with the real keys: PRIMARY(item, system, entity, external_id) and UNIQUE(system, entity, external_id). */
final class ZDZ_Test_Wireb_Refs_DB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $rows       = array();
	public $seq        = 0;
	public $queries    = array();
	/** SHOW … answers for the migration test. */
	public $table_exists = true;
	public $pk           = array( 'work_item_id', 'system', 'entity', 'external_id' );
	public $columns      = array( 'work_item_id', 'system', 'entity', 'external_id', 'ref_seq' );

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'sql' => (string) $sql, 'args' => array_values( $args ) );
	}
	private function q( $q ) {
		return is_array( $q ) ? array( $q['sql'], $q['args'] ) : array( (string) $q, array() );
	}
	public function get_var( $q ) {
		list( $sql, $a ) = $this->q( $q );
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->table_exists ? $a[0] : null;
		}
		if ( false !== strpos( $sql, 'SELECT work_item_id FROM' ) ) {
			foreach ( $this->rows as $r ) {
				if ( $r['system'] === $a[0] && $r['entity'] === $a[1] && $r['external_id'] === (string) $a[2] ) {
					return $r['work_item_id'];
				}
			}
			return null;
		}
		return null;
	}
	public function get_col( $q, $x = 0 ) {
		list( $sql ) = $this->q( $q );
		return 0 === strpos( $sql, 'SHOW COLUMNS' ) ? $this->columns : array();
	}
	public function get_results( $q, $o = null ) {
		list( $sql, $a ) = $this->q( $q );
		if ( 0 === strpos( $sql, 'SHOW INDEX' ) ) {
			$out = array();
			foreach ( $this->pk as $i => $c ) {
				$out[] = array( 'Key_name' => 'PRIMARY', 'Seq_in_index' => $i + 1, 'Column_name' => $c );
			}
			return $out;
		}
		if ( false !== strpos( $sql, 'FROM wp_zdz_work_item_refs WHERE work_item_id = %s' ) ) {
			$out = array();
			foreach ( $this->rows as $r ) {
				if ( $r['work_item_id'] !== $a[0] || ( isset( $a[1] ) && $r['system'] !== $a[1] ) || ( isset( $a[2] ) && $r['entity'] !== $a[2] ) ) {
					continue;
				}
				$out[] = $r;
			}
			usort( $out, static function ( $x, $y ) {
				return strcmp( $x['system'] . '/' . $x['entity'], $y['system'] . '/' . $y['entity'] );
			} );
			return $out;
		}
		return array();
	}
	public function insert( $t, $data, $f = null ) {
		foreach ( $this->rows as $r ) {
			$pk = $r['work_item_id'] === $data['work_item_id'] && $r['system'] === $data['system'] && $r['entity'] === $data['entity'] && $r['external_id'] === $data['external_id'];
			$uq = $r['system'] === $data['system'] && $r['entity'] === $data['entity'] && $r['external_id'] === $data['external_id'];
			if ( $pk || $uq ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
		}
		$data['ref_seq'] = ++$this->seq;
		// Stored in a scrambled order, so the reader must sort by ref_seq itself.
		array_unshift( $this->rows, $data );
		return 1;
	}
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$n = 0;
		foreach ( $this->rows as $k => $r ) {
			if ( array_intersect_assoc( $where, $r ) === $where ) {
				$this->rows[ $k ] = array_merge( $r, $data );
				$n++;
			}
		}
		return $n;
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_charset_collate() { return ''; }
}

final class JobsReadingGrantRefsTest extends TestCase {

	const OWNER = 501, SALES = 502, TECH = 503, DEVICE = 504;
	const P1 = '01HZZZZZZZZZZZZZZZZZZZZZZ1';
	const P2 = '01HZZZZZZZZZZZZZZZZZZZZZZ2';

	private $saved_db;
	/** @var ZDZ_Test_Wireb_Refs_DB */
	private $db;

	protected function setUp(): void {
		$this->saved_db  = $GLOBALS['wpdb'] ?? null;
		$this->db        = new ZDZ_Test_Wireb_Refs_DB();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['zdz_test_user_roles'] = array(
			self::OWNER  => array( 'zdz_owner' ),
			self::SALES  => array( 'zdz_sales' ),
			self::TECH   => array( 'zdz_tech' ),
			self::DEVICE => array( 'zdz_general' ),
		);
		$GLOBALS['zdz_test_user_meta'] = array();
		$GLOBALS['zdz_test_user_caps'] = array();
		$GLOBALS['zdz_test_filters']['zdz_work_item_ref_namespaces'] = static function () {
			return array( 'estimate/document', 'invoice/document', 'job/handoff', 'appointment/event' );
		};
		if ( method_exists( 'ZJOB_Scope', 'flush_memo' ) ) {
			ZJOB_Scope::flush_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_db;
		unset( $GLOBALS['zdz_test_filters']['zdz_work_item_ref_namespaces'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_user_caps'] );
	}

	/* ── the reading grant (its permission checks run in apps/jobs/tests/test-reading-grant.php) ── */

	public function test_read_and_write_callers_ask_the_right_gate(): void {
		$dir   = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
		$reads = array( 'class-zjob-projects-ajax.php', 'class-zjob-accept-notice.php', 'class-zjob-install-date.php' );
		foreach ( $reads as $f ) {
			$src = (string) file_get_contents( $dir . $f );
			$this->assertStringContainsString( 'Zjob_Project_Visibility::may_view_project(', $src, $f . ' reads' );
		}
		foreach ( array( 'class-zjob-appointment-link.php', 'class-zjob-schedule-context.php' ) as $f ) {
			$src = (string) file_get_contents( $dir . $f );
			$this->assertStringNotContainsString( 'may_view_project', $src, $f . ' acts' );
		}
		$orders = (string) file_get_contents( $dir . 'class-zjob-project-orders.php' );
		$this->assertStringContainsString( 'Zjob_Project_Visibility::relationship( $uid, $project, false )', $orders );
		$this->assertStringContainsString( 'Zjob_Project_Visibility::actor_may_use_project( $uid, $project )', $orders );
	}

	/* ── the default tiles ─────────────────────────────────────────────────────────────── */

	public function test_projects_is_granted_like_the_jobs_tile(): void {
		$this->assertSame( array( 'jobs', 'projects' ), ZJOB_Tile_Grant::default_ids( false ) );
		$this->assertSame( array( 'jobs' ), ZJOB_Tile_Grant::default_ids( true ), 'never the Projects hub on the shared device' );
		$this->assertSame( array( 'leads', 'jobs', 'projects' ), ZJOB_Tile_Grant::merge( array( 'leads' ), '', array( 'jobs', 'projects' ) ) );
		$this->assertSame( array( 'jobs' ), ZJOB_Tile_Grant::merge( '', array( 'projects' ), array( 'jobs', 'projects' ) ), 'a denial is respected' );
		$this->assertNull( ZJOB_Tile_Grant::merge( array( 'jobs', 'projects' ), '', array( 'jobs', 'projects' ) ), 'nothing to write' );

		$GLOBALS['zdz_test_user_caps'] = array( self::SALES => array( 'zdz_access_app' ), self::DEVICE => array( 'zdz_access_app' ) );
		ZJOB_Tile_Grant::grant( self::SALES );
		ZJOB_Tile_Grant::grant( self::DEVICE );
		ZJOB_Tile_Grant::grant( self::TECH ); // no app access: nothing
		$this->assertSame( array( 'jobs', 'projects' ), $GLOBALS['zdz_test_user_meta'][ self::SALES ]['zdz_allowed_apps'] );
		$this->assertSame( array( 'jobs' ), $GLOBALS['zdz_test_user_meta'][ self::DEVICE ]['zdz_allowed_apps'] );
		$this->assertArrayNotHasKey( self::TECH, $GLOBALS['zdz_test_user_meta'] );

		$app = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/app.php' );
		$this->assertStringContainsString( 'ZJOB_Tile_Grant::grant( (int) $user_id );', $app );
	}

	/* ── many references per namespace ─────────────────────────────────────────────────── */

	public function test_a_project_holds_many_references_in_one_namespace(): void {
		$this->assertTrue( ZJOB_Project_Refs::put( self::P1, 'estimate', '11' ) );
		$this->assertTrue( ZJOB_Project_Refs::put( self::P1, 'estimate', '12' ), 'a second estimate is added, not re-pointed' );
		$this->assertTrue( ZJOB_Project_Refs::put( self::P1, 'invoice', '901' ) );
		$this->assertTrue( ZJOB_Project_Refs::put( self::P1, 'invoice', '902' ) );
		$this->assertTrue( ZJOB_Project_Refs::put( self::P1, 'estimate', '11' ), 're-recording is a no-op' );
		$this->assertCount( 4, $this->db->rows );

		$this->assertSame( array( '11', '12' ), ZJOB_Project_Refs::all( self::P1, 'estimate' ) );
		$this->assertSame( array( '901', '902' ), ZJOB_Project_Refs::all( self::P1, 'invoice' ) );
		$this->assertSame( '11', ZJOB_Project_Refs::get( self::P1, 'estimate' ), 'the first recorded is the single answer' );
		$this->assertSame( '901', ZJOB_Project_Refs::get( self::P1, 'invoice' ) );
		$grouped = ZJOB_Project_Refs::for_project( self::P1 );
		$this->assertSame( array( '11', '12' ), array_column( $grouped['estimate'], 'ref_id' ) );

		// One record still belongs to one Project.
		$this->assertFalse( ZJOB_Project_Refs::put( self::P2, 'estimate', '12' ) );
		$this->assertTrue( ZJOB_Project_Refs::put( self::P2, 'estimate', '13' ) );
		$this->assertSame( array( '11', '12' ), ZJOB_Project_Refs::all( self::P1, 'estimate' ) );
	}

	public function test_every_job_stays_attached(): void {
		$this->assertTrue( Zjob_Project::attach_job( self::P1, 31 ) );
		$this->assertTrue( Zjob_Project::attach_job( self::P1, 32 ) );
		$this->assertSame( array( '31', '32' ), Zdz_Flow_Refs::ids( self::P1, 'job', 'handoff' ), 'a second job never detaches the first' );
	}

	public function test_meta_refresh_touches_only_that_reference(): void {
		Zdz_Flow_Refs::put( self::P1, 'invoice', 'document', '901', array( 'sync_state' => 'probable' ) );
		Zdz_Flow_Refs::put( self::P1, 'invoice', 'document', '902', array( 'sync_state' => 'probable' ) );
		Zdz_Flow_Refs::put( self::P1, 'invoice', 'document', '902', array( 'sync_state' => 'confirmed' ) );
		$by = array_column( Zdz_Flow_Refs::for( self::P1, 'invoice', 'document' ), 'sync_state', 'external_id' );
		$this->assertSame( array( '901' => 'probable', '902' => 'confirmed' ), $by );
	}

	public function test_recorded_order_is_kept_without_a_sequence_too(): void {
		$rows = array(
			array( 'system' => 'invoice', 'entity' => 'document', 'external_id' => 'b', 'ref_seq' => 7 ),
			array( 'system' => 'estimate', 'entity' => 'document', 'external_id' => 'x', 'ref_seq' => 9 ),
			array( 'system' => 'invoice', 'entity' => 'document', 'external_id' => 'a', 'ref_seq' => 3 ),
		);
		$this->assertSame( array( 'x', 'a', 'b' ), array_column( Zdz_Flow_Refs::in_recorded_order( $rows ), 'external_id' ) );
		$old = array(
			array( 'system' => 'job', 'entity' => 'handoff', 'external_id' => '5' ),
			array( 'system' => 'estimate', 'entity' => 'document', 'external_id' => '1' ),
		);
		$this->assertSame( array( '1', '5' ), array_column( Zdz_Flow_Refs::in_recorded_order( $old ), 'external_id' ), 'a table not yet re-keyed still reads' );
	}

	/* ── the migration ─────────────────────────────────────────────────────────────────── */

	public function test_the_refs_table_is_rekeyed_in_one_alter_that_keeps_rows(): void {
		$t   = 'wp_zdz_work_item_refs';
		$sql = Zdz_Flow_DB::refs_upgrade_sql( $t, array( 'work_item_id', 'system', 'entity' ), false );
		$this->assertSame(
			'ALTER TABLE wp_zdz_work_item_refs ADD COLUMN ref_seq BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT AFTER external_id, ADD KEY idx_seq (ref_seq), DROP PRIMARY KEY, ADD PRIMARY KEY (work_item_id,`system`,entity,external_id)',
			$sql
		);
		$this->assertDoesNotMatchRegularExpression( '/\b(DROP TABLE|TRUNCATE|DELETE|DROP COLUMN)\b/i', $sql, 'no row is lost' );
		$this->assertSame( '', Zdz_Flow_DB::refs_upgrade_sql( $t, array( 'work_item_id', 'system', 'entity', 'external_id' ), true ), 'already current' );
		$this->assertStringNotContainsString( 'PRIMARY', Zdz_Flow_DB::refs_upgrade_sql( $t, array( 'work_item_id', 'system', 'entity', 'external_id' ), false ) );

		// An old table: the ALTER runs once.
		$this->db->pk      = array( 'work_item_id', 'system', 'entity' );
		$this->db->columns = array( 'work_item_id', 'system', 'entity', 'external_id' );
		$this->assertTrue( Zdz_Flow_DB::migrate_refs_keys() );
		$this->assertCount( 1, $this->db->queries );
		$this->assertSame( 0, strpos( $this->db->queries[0], 'ALTER TABLE wp_zdz_work_item_refs ' ) );

		// A current table, or none yet: nothing to run.
		$this->db->queries = array();
		$this->db->pk      = array( 'work_item_id', 'system', 'entity', 'external_id' );
		$this->db->columns = array( 'work_item_id', 'system', 'entity', 'external_id', 'ref_seq' );
		$this->assertTrue( Zdz_Flow_DB::migrate_refs_keys() );
		$this->db->table_exists = false;
		$this->assertTrue( Zdz_Flow_DB::migrate_refs_keys() );
		$this->assertSame( array(), $this->db->queries );
		$this->assertSame( '1.1.0', Zdz_Flow_DB::DB_VERSION, 'the version moves so existing installs run it' );
	}

	public function test_the_new_table_shape_and_the_appointment_link_stays_single(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/flow/class-zdz-flow-db.php' );
		$this->assertStringContainsString( 'PRIMARY KEY  (work_item_id,`system`,entity,external_id)', $src );
		$this->assertStringContainsString( 'UNIQUE KEY uq_ext (`system`,entity,external_id)', $src );
		$link = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-appointment-link.php' );
		$this->assertStringContainsString( 'self::forget( $project_id, $prev );', $link, 'moving the appointment drops the earlier link' );
	}
}
