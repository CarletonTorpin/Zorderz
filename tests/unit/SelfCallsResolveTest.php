<?php
/**
 * A class never calls a method of its own that it does not have.
 *
 * PHP only notices a missing method when the line runs, so a call into nothing can sit in a path the
 * unit tests replace with a double: the Jobs Projects sweep called Zjob_Project::estimate_created_by(),
 * which no file defined, and every run of the sweep (and every estimate-accepted notice) ended in a
 * fatal error on a site with estimates. This reads every class in the theme and the apps with PHP's own
 * tokenizer and checks each self:: and static:: call against the methods that class declares (a class
 * with a parent or a trait is left out: its methods may live elsewhere).
 *
 * Also: the Project an estimate mints carries the estimate's maker.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-db.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project.php';

/** Answers the one read estimate_created_by() makes. */
final class Zdz_Self_Calls_Db {
	public $prefix  = 'wp_';
	public $asked   = array();
	public $by_id   = array();

	public function prepare( $q, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback( '/%d/', function () use ( &$i, $args ) {
			return (string) (int) ( $args[ $i++ ] ?? 0 );
		}, $q );
	}

	public function get_var( $q ) {
		$this->asked[] = $q;
		if ( preg_match( '/SELECT created_by FROM wp_zest_estimates WHERE id = (\d+)/', $q, $m ) ) {
			return $this->by_id[ (int) $m[1] ] ?? null;
		}
		return null;
	}
}

final class SelfCallsResolveTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/** @return string[] every PHP file the theme and the apps ship. */
	private static function shipped_php(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( array( 'zorderz', 'zorderz-apps' ) as $top ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $top, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$p = str_replace( '\\', '/', (string) $f );
				if ( '.php' !== substr( $p, -4 ) || preg_match( '#/(tests|bundled|vendor|node_modules)/#', $p ) ) {
					continue;
				}
				$files[] = $p;
			}
		}
		sort( $files );
		return $files;
	}

	private static function next_sig( array $t, int $i ): int {
		$n = count( $t );
		for ( $j = $i + 1; $j < $n; $j++ ) {
			if ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return $n - 1;
	}

	private static function prev_sig( array $t, int $i ): int {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return 0;
	}

	/**
	 * The self:: and static:: calls in $code's classes that name no method of the class.
	 *
	 * @return string[] "line Class::method"
	 */
	private static function unresolved( string $code ): array {
		$t   = token_get_all( $code );
		$n   = count( $t );
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! is_array( $t[ $i ] ) || T_CLASS !== $t[ $i ][0] ) {
				continue;
			}
			$p = $t[ self::prev_sig( $t, $i ) ];
			if ( is_array( $p ) && in_array( $p[0], array( T_DOUBLE_COLON, T_NEW ), true ) ) {
				continue; // Foo::class, or an anonymous class
			}
			$nm = self::next_sig( $t, $i );
			if ( ! is_array( $t[ $nm ] ) || T_STRING !== $t[ $nm ][0] ) {
				continue;
			}
			$name    = $t[ $nm ][1];
			$extends = false;
			for ( $k = $nm + 1; $k < $n && '{' !== $t[ $k ]; $k++ ) {
				if ( is_array( $t[ $k ] ) && T_EXTENDS === $t[ $k ][0] ) {
					$extends = true;
				}
			}
			$depth = 0;
			$trait = false;
			$defs  = array();
			$calls = array();
			for ( $m = $k; $m < $n; $m++ ) {
				$x = $t[ $m ];
				if ( '{' === $x || ( is_array( $x ) && in_array( $x[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $x ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( ! is_array( $x ) ) {
					continue;
				}
				if ( T_USE === $x[0] && 1 === $depth ) {
					$trait = true;
				} elseif ( T_FUNCTION === $x[0] ) {
					$f = self::next_sig( $t, $m );
					if ( '&' === $t[ $f ] ) {
						$f = self::next_sig( $t, $f );
					}
					if ( is_array( $t[ $f ] ) && T_STRING === $t[ $f ][0] ) {
						$defs[ strtolower( $t[ $f ][1] ) ] = true;
					}
				} elseif ( ( T_STATIC === $x[0] || ( T_STRING === $x[0] && 'self' === strtolower( $x[1] ) ) ) ) {
					$dc = self::next_sig( $t, $m );
					if ( ! is_array( $t[ $dc ] ) || T_DOUBLE_COLON !== $t[ $dc ][0] ) {
						continue;
					}
					$mn = self::next_sig( $t, $dc );
					$pa = self::next_sig( $t, $mn );
					if ( is_array( $t[ $mn ] ) && T_STRING === $t[ $mn ][0] && '(' === $t[ $pa ] ) {
						$calls[] = array( $t[ $mn ][1], (int) $t[ $mn ][2] );
					}
				}
			}
			if ( ! $extends && ! $trait ) {
				foreach ( $calls as $c ) {
					if ( ! isset( $defs[ strtolower( $c[0] ) ] ) ) {
						$out[] = $c[1] . ' ' . $name . '::' . $c[0];
					}
				}
			}
			$i = $m;
		}
		return $out;
	}

	public function test_the_check_sees_a_call_into_nothing(): void {
		$code = "<?php\nclass Zdz_Probe {\n\tpublic static function a() { return self::b() + static::c(); }\n\tprivate static function b() { return \"{\$x}\"; }\n}\n";
		$this->assertSame( array( '3 Zdz_Probe::c' ), self::unresolved( $code ) );
	}

	public function test_every_self_call_names_a_method_of_its_class(): void {
		$root    = dirname( __DIR__, 2 ) . '/';
		$missing = array();
		foreach ( self::shipped_php() as $file ) {
			foreach ( self::unresolved( (string) file_get_contents( $file ) ) as $hit ) {
				$missing[] = str_replace( $root, '', $file ) . ':' . $hit;
			}
		}
		$this->assertSame( array(), $missing );
	}

	public function test_a_project_minted_from_an_estimate_carries_its_maker(): void {
		$db              = new Zdz_Self_Calls_Db();
		$db->by_id[41]   = '7';
		$GLOBALS['wpdb'] = $db;
		$m               = new ReflectionMethod( 'Zjob_Project', 'estimate_created_by' );
		$m->setAccessible( true );
		$this->assertSame( 7, $m->invoke( null, 41 ) );
		$this->assertSame( 0, $m->invoke( null, 42 ), 'a row that is gone: no originator' );
		$asked = count( $db->asked );
		$this->assertSame( 0, $m->invoke( null, 0 ) );
		$this->assertCount( $asked, $db->asked, 'no id, no read' );
	}
}
