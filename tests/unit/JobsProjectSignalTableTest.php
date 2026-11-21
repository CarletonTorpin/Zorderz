<?php
/**
 * The Projects ranking table on MySQL and MariaDB:
 *
 *   - No table definition in the theme or the apps names a column or an index with a bare
 *     reserved word. A reserved name has to be written in backticks, or the CREATE fails and
 *     dbDelta says nothing.
 *   - Every query on the ranking table writes the `signal` column in backticks.
 *   - The schema version is kept only once the table is really there, so a CREATE that failed
 *     is tried again on the next request.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}

class JobsProjectSignalTableTest extends TestCase {

	/** Words MySQL 8 or MariaDB 10.11 refuse as a bare column or index name. */
	private static function reserved(): array {
		$words = '
			accessible add all alter analyze and as asc asensitive before between bigint binary blob both
			by call cascade case change char character check collate column condition constraint continue
			convert create cross cube cume_dist current_date current_role current_time current_timestamp
			current_user cursor database databases day_hour day_microsecond day_minute day_second dec
			decimal declare default delayed delete delete_domain_id dense_rank desc describe deterministic
			distinct distinctrow div do_domain_ids double drop dual each else elseif empty enclosed escaped
			except exists exit explain false fetch first_value float float4 float8 for force foreign from
			fulltext function generated get grant group grouping groups having high_priority
			hour_microsecond hour_minute hour_second if ignore ignore_domain_ids in index infile inner
			inout insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into
			io_after_gtids io_before_gtids is iterate join json_table key keys kill lag last_value lateral
			lead leading leave left library like limit linear lines load localtime localtimestamp lock long
			longblob longtext loop low_priority manual master_bind master_demote_to_replica
			master_demote_to_slave master_ssl_verify_server_cert match maxvalue mediumblob mediumint
			mediumtext middleint minute_microsecond minute_second mod modifies natural no_write_to_binlog
			not nth_value ntile null numeric of offset on optimize optimizer_costs option optionally or
			order out outer outfile over page_checksum parallel parse_vcol_expr partition percent_rank
			portion precision primary procedure purge qualify range rank read read_write reads real
			recursive ref_system_id references regexp release rename repeat replace require resignal
			restrict return returning revoke right rlike row row_number rows schema schemas
			second_microsecond select sensitive separator set show signal smallint spatial specific sql
			sql_big_result sql_calc_found_rows sql_small_result sqlexception sqlstate sqlwarning ssl
			starting stats_auto_recalc stats_persistent stats_sample_pages stored straight_join system
			table terminated then tinyblob tinyint tinytext to trailing trigger true undo union unique
			unlock unsigned update usage use using utc_date utc_time utc_timestamp values varbinary varchar
			varcharacter varying virtual when where while window with write xor year_month zerofill
		';
		return array_flip( preg_split( '/\s+/', trim( $words ) ) );
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** @return string[] PHP files of the theme and the apps, without build copies. */
	private static function php_files(): array {
		$out = array();
		foreach ( array( 'zorderz', 'zorderz-apps' ) as $top ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::root() . '/' . $top, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$p = str_replace( '\\', '/', $f->getPathname() );
				if ( '.php' !== substr( $p, -4 ) || preg_match( '#/(bundled|vendor|node_modules)/#', $p ) ) {
					continue;
				}
				$out[] = $p;
			}
		}
		sort( $out );
		return $out;
	}

	public function test_no_table_definition_uses_a_bare_reserved_word(): void {
		$reserved   = self::reserved();
		$statements = 0;
		$bad        = array();
		foreach ( self::php_files() as $file ) {
			$src = (string) file_get_contents( $file );
			if ( false === stripos( $src, 'CREATE TABLE' ) ) {
				continue;
			}
			if ( ! preg_match_all( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[^\s(]+\s*\((.*?)\n\s*\)[^"\'\n]*["\']/si', $src, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $all as $m ) {
				++$statements;
				$line  = substr_count( substr( $src, 0, $m[0][1] ), "\n" ) + 1;
				$names = array();
				foreach ( preg_split( '/\R/', $m[1][0] ) as $raw ) {
					$t = rtrim( trim( $raw ), ',' );
					if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '--' ) ) {
						continue;
					}
					if ( preg_match( '/^(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+(`?)(\w+)\1\s*\((.*)\)/i', $t, $k ) ) {
						if ( '' === $k[1] ) {
							$names[] = $k[2];
						}
						$cols = $k[3];
					} elseif ( preg_match( '/^(?:PRIMARY\s+KEY|UNIQUE|CONSTRAINT|FOREIGN\s+KEY)[^(]*\((.*)\)/i', $t, $k ) ) {
						$cols = $k[1];
					} else {
						if ( preg_match( '/^(`?)(\w+)\1\s+\w+/', $t, $c ) && '' === $c[1] ) {
							$names[] = $c[2];
						}
						continue;
					}
					foreach ( explode( ',', $cols ) as $col ) {
						if ( preg_match( '/^(`?)(\w+)\1/', trim( $col ), $c ) && '' === $c[1] ) {
							$names[] = $c[2];
						}
					}
				}
				foreach ( $names as $n ) {
					if ( isset( $reserved[ strtolower( $n ) ] ) ) {
						$bad[] = substr( $file, strlen( self::root() ) + 1 ) . ':' . $line . ' ' . $n;
					}
				}
			}
		}
		$this->assertGreaterThan( 100, $statements, 'the scan should read the table definitions' );
		$this->assertSame( array(), $bad, "Bare reserved words in table definitions:\n" . implode( "\n", $bad ) );
	}

	public function test_signal_queries_write_the_column_in_backticks(): void {
		$files = array(
			self::root() . '/zorderz-apps/apps/jobs/includes/class-zjob-project-signal.php',
			self::root() . '/zorderz-apps/apps/jobs/includes/class-zjob-project.php',
		);
		$seen = 0;
		$bad  = array();
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
				if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
					continue;
				}
				$text = $tok[1];
				if ( ! preg_match( '/\b(SELECT|INSERT|UPDATE|DELETE|ORDER\s+BY|CREATE\s+TABLE|DUPLICATE\s+KEY|VALUES|NOT\s+NULL|PRIMARY\s+KEY)\b/i', $text ) || false === stripos( $text, 'signal' ) ) {
					continue;
				}
				++$seen;
				if ( preg_match( '/(?<![`\w])signal(?![`\w])/i', $text ) ) {
					$bad[] = basename( $file ) . ':' . $tok[2] . ' ' . trim( preg_replace( '/\s+/', ' ', $text ) );
				}
			}
		}
		$this->assertGreaterThanOrEqual( 5, $seen, 'the scan should find the ranking queries' );
		$this->assertSame( array(), $bad, "Unquoted signal column:\n" . implode( "\n", $bad ) );
	}

	public function test_the_table_writes_signal_in_backticks_and_keeps_its_version_only_once_made(): void {
		require_once self::root() . '/zorderz-apps/apps/jobs/includes/class-zjob-project-signal.php';
		$sql = Zjob_Project_Signal::schema_sql( 'wp_zjob_project_signal', '' );
		$this->assertStringContainsString( '`signal` INT', $sql );
		$this->assertStringContainsString( 'KEY `signal` (`signal`)', $sql );
		$this->assertSame( '1.0.1', Zjob_Project_Signal::DB_VERSION, 'a new number, so sites where the table was never made try again' );

		$was_wpdb = $GLOBALS['wpdb'] ?? null;
		$was_opts = $GLOBALS['zdz_test_options'] ?? null;
		try {
			$GLOBALS['wpdb'] = new class() {
				public $prefix = 'wp_';
				public $exists = false;
				public $asked  = array();
				public function esc_like( $s ) {
					return addcslashes( (string) $s, '_%\\' );
				}
				public function prepare( $q, ...$a ) {
					return str_replace( '%s', "'" . (string) $a[0] . "'", $q );
				}
				public function get_var( $q ) {
					$this->asked[] = $q;
					return $this->exists ? 'wp_zjob_project_signal' : null;
				}
			};
			$GLOBALS['zdz_test_options'] = array();

			$this->assertFalse( Zjob_Project_Signal::keep_version_if_made() );
			$this->assertArrayNotHasKey( Zjob_Project_Signal::DB_VERSION_OPTION, $GLOBALS['zdz_test_options'], 'a CREATE that did not happen keeps no version, so the next request tries again' );
			$this->assertSame( "SHOW TABLES LIKE 'wp\\_zjob\\_project\\_signal'", $GLOBALS['wpdb']->asked[0] );

			$GLOBALS['wpdb']->exists = true;
			$this->assertTrue( Zjob_Project_Signal::keep_version_if_made() );
			$this->assertSame( Zjob_Project_Signal::DB_VERSION, $GLOBALS['zdz_test_options'][ Zjob_Project_Signal::DB_VERSION_OPTION ] );

			$asked = count( $GLOBALS['wpdb']->asked );
			Zjob_Project_Signal::ensure_schema();
			$this->assertCount( $asked, $GLOBALS['wpdb']->asked, 'once the version is kept, nothing more is asked' );
		} finally {
			$GLOBALS['wpdb'] = $was_wpdb;
			if ( null === $was_opts ) {
				unset( $GLOBALS['zdz_test_options'] );
			} else {
				$GLOBALS['zdz_test_options'] = $was_opts;
			}
		}
	}
}
