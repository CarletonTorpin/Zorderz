<?php
/**
 * Test doubles for the step runner suites: an in-memory $wpdb that understands the small set of statements the
 * runner and the apps built on it write (insert / update / delete by column values; UPDATE … SET … WHERE … and
 * DELETE FROM … WHERE … with AND, OR, parentheses, IN, IS NULL and comparisons; SELECT with COUNT / SUM / MAX,
 * ORDER BY and LIMIT). Neutral data only. Not a test file itself (no *Test.php suffix).
 *
 * @package Zorderz\Tests
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $uid ) { $GLOBALS['zdz_test_current_user'] = (int) $uid; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}

if ( ! class_exists( 'Zdz_Steps_Test_Db' ) ) {
	class Zdz_Steps_Test_Db {
		public $prefix     = 'wp_';
		public $last_error = '';
		public $insert_id  = 0;
		/** @var array<string,array<int,array<string,mixed>>> table => id => row */
		public $tables = array();
		public $ddl    = array();
		/** Every statement run through query(), in order. */
		public $log = array();
		/** Make the next query() fail (returns false) when it contains this text; cleared after one use. */
		public $fail_query = '';
		/** Make every insert fail. */
		public $fail_insert = false;
		/** callable( string $sql ): run once, just before a query() whose text contains $before_match is applied. */
		public $before       = null;
		public $before_match = '';
		private $next = array();

		public function get_charset_collate() { return ''; }

		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }

		public function prepare( $q, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}
				return "'" . str_replace( "'", "''", (string) $v ) . "'";
			}, $q );
		}

		public function rows( $suffix ) {
			return array_values( $this->tables[ $this->prefix . $suffix ] ?? array() );
		}

		public function row( $suffix, $id ) {
			return $this->tables[ $this->prefix . $suffix ][ (int) $id ] ?? null;
		}

		/** Change a stored row directly (a test arranging a situation). */
		public function poke( $suffix, $id, array $data ) {
			$t = $this->prefix . $suffix;
			if ( isset( $this->tables[ $t ][ (int) $id ] ) ) {
				$this->tables[ $t ][ (int) $id ] = array_merge( $this->tables[ $t ][ (int) $id ], $data );
			}
		}

		public function insert( $table, array $row, $formats = null ) {
			if ( $this->fail_insert ) {
				$this->last_error = 'insert failed';
				return false;
			}
			$id                            = ( $this->next[ $table ] ?? 1 );
			$this->next[ $table ]          = $id + 1;
			$row                           = array( 'id' => $id ) + $row;
			$this->tables[ $table ][ $id ] = $row;
			$this->insert_id               = $id;
			return 1;
		}

		private function matching( $table, array $where ) {
			$ids = array();
			foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
				foreach ( $where as $k => $v ) {
					if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) {
						continue 2;
					}
				}
				$ids[] = $id;
			}
			return $ids;
		}

		public function update( $table, array $data, array $where, $f1 = null, $f2 = null ) {
			$n = 0;
			foreach ( $this->matching( $table, $where ) as $id ) {
				$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
				$n++;
			}
			return $n;
		}

		public function delete( $table, array $where, $f = null ) {
			$n = 0;
			foreach ( $this->matching( $table, $where ) as $id ) {
				unset( $this->tables[ $table ][ $id ] );
				$n++;
			}
			return $n;
		}

		/* ---------------------------------------------------------------- a small SQL reader */

		private static function tokens( $sql ) {
			preg_match_all( "/'(?:[^']|'')*'|<=|>=|<>|!=|[=<>(),*+\-]|[A-Za-z_][A-Za-z0-9_.]*|\d+(?:\.\d+)?/", $sql, $m );
			return $m[0];
		}

		private static function value( $tok ) {
			if ( "'" === $tok[0] ) {
				return str_replace( "''", "'", substr( $tok, 1, -1 ) );
			}
			if ( 0 === strcasecmp( $tok, 'NULL' ) ) {
				return null;
			}
			return is_numeric( $tok ) ? $tok + 0 : $tok;
		}

		private static function cmp( $a, $op, $b ) {
			if ( null === $a || null === $b ) {
				return false;   // SQL: a comparison with NULL is never true
			}
			$num = is_numeric( $a ) && is_numeric( $b );
			$c   = $num ? ( $a + 0 <=> $b + 0 ) : strcmp( (string) $a, (string) $b );
			switch ( $op ) {
				case '=':
					return 0 === $c;
				case '!=':
				case '<>':
					return 0 !== $c;
				case '<':
					return $c < 0;
				case '>':
					return $c > 0;
				case '<=':
					return $c <= 0;
				default:
					return $c >= 0;
			}
		}

		/** expr := term ( OR term )* */
		private static function expr( array $t, &$i, array $row ) {
			$v = self::term( $t, $i, $row );
			while ( isset( $t[ $i ] ) && 0 === strcasecmp( $t[ $i ], 'OR' ) ) {
				$i++;
				$r = self::term( $t, $i, $row );
				$v = $v || $r;
			}
			return $v;
		}

		/** term := factor ( AND factor )* */
		private static function term( array $t, &$i, array $row ) {
			$v = self::factor( $t, $i, $row );
			while ( isset( $t[ $i ] ) && 0 === strcasecmp( $t[ $i ], 'AND' ) ) {
				$i++;
				$r = self::factor( $t, $i, $row );
				$v = $v && $r;
			}
			return $v;
		}

		private static function factor( array $t, &$i, array $row ) {
			if ( '(' === $t[ $i ] ) {
				$i++;
				$v = self::expr( $t, $i, $row );
				$i++;   // )
				return $v;
			}
			$col = $t[ $i++ ];
			$val = $row[ $col ] ?? null;
			$op  = strtoupper( $t[ $i++ ] );
			if ( 'IS' === $op ) {
				$not = 0 === strcasecmp( $t[ $i ], 'NOT' );
				if ( $not ) {
					$i++;
				}
				$i++;   // NULL
				return $not ? null !== $val : null === $val;
			}
			$not = false;
			if ( 'NOT' === $op ) {
				$not = true;
				$op  = strtoupper( $t[ $i++ ] );
			}
			if ( 'IN' === $op ) {
				$i++;   // (
				$in = false;
				while ( ')' !== $t[ $i ] ) {
					if ( ',' !== $t[ $i ] && null !== $val && (string) self::value( $t[ $i ] ) === (string) $val ) {
						$in = true;
					}
					$i++;
				}
				$i++;   // )
				return $not ? ! $in : $in;
			}
			if ( 'LIKE' === $op ) {
				// % any run, _ one character; a backslash before either makes it a plain character (esc_like)
				$raw = (string) self::value( $t[ $i++ ] );
				$pat = '';
				for ( $c = 0, $len = strlen( $raw ); $c < $len; $c++ ) {
					$ch = $raw[ $c ];
					if ( '\\' === $ch && $c + 1 < $len ) {
						$pat .= preg_quote( $raw[ ++$c ], '/' );
					} elseif ( '%' === $ch ) {
						$pat .= '.*';
					} elseif ( '_' === $ch ) {
						$pat .= '.';
					} else {
						$pat .= preg_quote( $ch, '/' );
					}
				}
				$hit = null !== $val && 1 === preg_match( '/^' . $pat . '$/s', (string) $val );
				return $not ? ! $hit : $hit;
			}
			return self::cmp( $val, $op, self::value( $t[ $i++ ] ) );
		}

		private function where_ids( $table, array $t, &$i ) {
			$ids = array();
			if ( ! isset( $t[ $i ] ) || 0 !== strcasecmp( $t[ $i ], 'WHERE' ) ) {
				return array_keys( $this->tables[ $table ] ?? array() );
			}
			$i++;
			$start = $i;
			$end   = $i;
			foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
				$j = $start;
				if ( self::expr( $t, $j, $row ) ) {
					$ids[] = $id;
				}
				$end = $j;
			}
			if ( $end === $start ) {   // no rows: still walk past the condition
				$j = $start;
				self::expr( $t, $j, array() );
				$end = $j;
			}
			$i = $end;
			return $ids;
		}

		public function query( $sql ) {
			$this->log[] = $sql;
			if ( '' !== $this->fail_query && false !== strpos( $sql, $this->fail_query ) ) {
				$this->fail_query = '';
				$this->last_error = 'query failed';
				return false;
			}
			if ( $this->before && ( '' === $this->before_match || false !== strpos( $sql, $this->before_match ) ) ) {
				$cb           = $this->before;
				$this->before = null;
				call_user_func( $cb, $sql );
			}
			if ( preg_match( '/^\s*CREATE TABLE/i', $sql ) ) {
				$this->ddl[] = $sql;
				return true;
			}
			$t = self::tokens( $sql );
			$i = 0;
			if ( 0 === strcasecmp( $t[0], 'UPDATE' ) ) {
				$table = $t[1];
				$i     = 3;   // past SET
				$sets  = array();
				while ( isset( $t[ $i ] ) && 0 !== strcasecmp( $t[ $i ], 'WHERE' ) ) {
					$col = $t[ $i ];
					$i  += 2;   // col =
					if ( isset( $t[ $i + 1 ] ) && in_array( $t[ $i + 1 ], array( '+', '-' ), true ) && $t[ $i ] === $col ) {
						$sets[] = array( $col, $t[ $i + 1 ], self::value( $t[ $i + 2 ] ) );
						$i     += 3;
					} else {
						$sets[] = array( $col, '=', self::value( $t[ $i ] ) );
						$i++;
					}
					if ( isset( $t[ $i ] ) && ',' === $t[ $i ] ) {
						$i++;
					}
				}
				$ids = $this->where_ids( $table, $t, $i );
				foreach ( $ids as $id ) {
					foreach ( $sets as $s ) {
						$cur = $this->tables[ $table ][ $id ][ $s[0] ] ?? 0;
						if ( '+' === $s[1] ) {
							$this->tables[ $table ][ $id ][ $s[0] ] = $cur + $s[2];
						} elseif ( '-' === $s[1] ) {
							$this->tables[ $table ][ $id ][ $s[0] ] = $cur - $s[2];
						} else {
							$this->tables[ $table ][ $id ][ $s[0] ] = $s[2];
						}
					}
				}
				return count( $ids );
			}
			if ( 0 === strcasecmp( $t[0], 'DELETE' ) ) {
				$table = $t[2];
				$i     = 3;
				$ids   = $this->where_ids( $table, $t, $i );
				foreach ( $ids as $id ) {
					unset( $this->tables[ $table ][ $id ] );
				}
				return count( $ids );
			}
			return 0;
		}

		/** SELECT <what> FROM t [WHERE …] [ORDER BY col [ASC|DESC]] [LIMIT n] → [ what tokens, rows ] */
		private function select( $sql ) {
			$sql = str_replace( '`', '', (string) $sql );
			if ( ! preg_match( '/^\s*SELECT\b.+\bFROM\s+\S+/is', $sql ) ) {
				return array( array( '*' ), array() );   // not a statement this double reads (SHOW TABLES …): nothing found
			}
			$t = self::tokens( $sql );
			$i = 1;
			$what = array();
			while ( isset( $t[ $i ] ) && 0 !== strcasecmp( $t[ $i ], 'FROM' ) ) {
				$what[] = $t[ $i++ ];
			}
			$table = $t[ ++$i ];
			$i++;
			$ids  = $this->where_ids( $table, $t, $i );
			$rows = array();
			foreach ( $ids as $id ) {
				$rows[] = $this->tables[ $table ][ $id ];
			}
			if ( isset( $t[ $i ] ) && 0 === strcasecmp( $t[ $i ], 'ORDER' ) ) {
				$col  = $t[ $i + 2 ];
				$desc = isset( $t[ $i + 3 ] ) && 0 === strcasecmp( $t[ $i + 3 ], 'DESC' );
				usort( $rows, function ( $a, $b ) use ( $col, $desc ) {
					$x = $a[ $col ] ?? null;
					$y = $b[ $col ] ?? null;
					$c = ( is_numeric( $x ) && is_numeric( $y ) ) ? ( $x + 0 <=> $y + 0 ) : strcmp( (string) $x, (string) $y );
					return $desc ? -$c : $c;
				} );
				$i += 3;
				if ( isset( $t[ $i ] ) && in_array( strtoupper( $t[ $i ] ), array( 'ASC', 'DESC' ), true ) ) {
					$i++;
				}
			}
			if ( isset( $t[ $i ] ) && 0 === strcasecmp( $t[ $i ], 'LIMIT' ) ) {
				$rows = array_slice( $rows, 0, (int) $t[ $i + 1 ] );
			}
			return array( $what, $rows );
		}

		private static function project( array $what, array $rows ) {
			$w = strtoupper( implode( '', $what ) );
			if ( '*' === $w ) {
				return $rows;
			}
			if ( preg_match( '/^(?:COALESCE\()?(COUNT|SUM|MAX|MIN)\((\*|[A-Z_0-9]+)\)(?:,0\))?$/', $w, $m ) ) {
				$col  = strtolower( $m[2] );
				$vals = '*' === $m[2] ? $rows : array_values( array_filter( array_map( function ( $r ) use ( $col ) { return $r[ $col ] ?? null; }, $rows ), function ( $v ) { return null !== $v; } ) );
				switch ( $m[1] ) {
					case 'COUNT':
						$v = count( $vals );
						break;
					case 'SUM':
						$v = $vals ? array_sum( $vals ) : ( false !== strpos( $w, 'COALESCE' ) ? 0 : null );
						break;
					case 'MAX':
						$v = $vals ? max( $vals ) : null;
						break;
					default:
						$v = $vals ? min( $vals ) : null;
				}
				return array( array( 'agg' => $v ) );
			}
			$cols = array_values( array_filter( $what, function ( $c ) { return ',' !== $c; } ) );
			$out  = array();
			foreach ( $rows as $r ) {
				$o = array();
				foreach ( $cols as $c ) {
					$o[ $c ] = $r[ $c ] ?? null;
				}
				$out[] = $o;
			}
			return $out;
		}

		public function get_results( $sql, $output = null ) {
			list( $what, $rows ) = $this->select( $sql );
			return self::project( $what, $rows );
		}

		public function get_row( $sql, $output = null ) {
			$rows = $this->get_results( $sql );
			return $rows ? $rows[0] : null;
		}

		public function get_var( $sql ) {
			$row = $this->get_row( $sql );
			if ( ! is_array( $row ) ) {
				return null;
			}
			$v = reset( $row );
			return null === $v ? null : (string) $v;
		}

		public function get_col( $sql ) {
			$out = array();
			foreach ( $this->get_results( $sql ) as $r ) {
				$out[] = reset( $r );
			}
			return $out;
		}
	}
}
