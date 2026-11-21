<?php
/**
 * Words people see:
 *
 *   - "Ai", never "AI", in every PHP string and inline HTML (server log lines aside) and every quoted JavaScript
 *     string of the theme and the apps;
 *   - the bottom bar's labels (Jobs, Team, Schedule, Notices) come from the server's translations, with the English
 *     word only as a fallback;
 *   - the Receipts menu is "Receipts", translatable, and no file carries double-encoded (garbled) characters;
 *   - Charts in local mode (no hub set up) answers 200, and 503 only when a hub that is set up cannot be reached.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class OnScreenWordsTest extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** Theme and apps source files with this extension (no vendored code, no built copy, no minified files). */
	private static function files( string $ext ): array {
		$out = array();
		foreach ( array( 'zorderz', 'zorderz-apps' ) as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$p = str_replace( '\\', '/', $f->getPathname() );
				if ( substr( $p, -strlen( $ext ) ) === $ext && ! preg_match( '#/(vendor|node_modules|bundled)/|\.min\.js$#', $p ) ) {
					$out[] = $p;
				}
			}
		}
		sort( $out );
		return $out;
	}

	public function test_php_strings_say_ai(): void {
		$bad = array();
		foreach ( self::files( '.php' ) as $file ) {
			$toks = token_get_all( (string) file_get_contents( $file ) );
			$sig  = array_values( array_filter( $toks, function ( $t ) {
				return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			} ) );
			foreach ( $sig as $i => $t ) {
				if ( ! is_array( $t ) || ! in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) || ! preg_match( '/\bAI\b/', $t[1] ) ) {
					continue;
				}
				// a server log line may say what it likes: walk out through the calls this string sits in
				$depth = 0;
				$log   = false;
				for ( $j = $i - 1; $j >= 0 && $j > $i - 80; $j-- ) {
					$tx = is_array( $sig[ $j ] ) ? $sig[ $j ][1] : $sig[ $j ];
					if ( ')' === $tx ) {
						$depth++;
					} elseif ( '(' === $tx ) {
						if ( 0 === $depth ) {
							$name = $sig[ $j - 1 ] ?? '';
							if ( 'error_log' === ( is_array( $name ) ? $name[1] : $name ) ) {
								$log = true;
								break;
							}
							continue;
						}
						$depth--;
					} elseif ( ';' === $tx && 0 === $depth ) {
						break;
					}
				}
				if ( ! $log ) {
					$bad[] = substr( $file, strlen( self::root() ) + 1 ) . ':' . $t[2];
				}
			}
		}
		$this->assertSame( array(), $bad, 'Ai, not AI, in words people see' );
	}

	public function test_javascript_strings_say_ai(): void {
		$bad = array();
		foreach ( self::files( '.js' ) as $file ) {
			foreach ( preg_split( '/\R/', (string) file_get_contents( $file ) ) as $n => $line ) {
				$code = ltrim( $line );
				if ( '' === $code || 0 === strpos( $code, '//' ) || 0 === strpos( $code, '*' ) || 0 === strpos( $code, '/*' ) ) {
					continue;
				}
				if ( preg_match_all( '/\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`/', $line, $m ) ) {
					foreach ( $m[0] as $s ) {
						if ( preg_match( '/\bAI\b/', $s ) ) {
							$bad[] = substr( $file, strlen( self::root() ) + 1 ) . ':' . ( $n + 1 );
						}
					}
				}
			}
		}
		$this->assertSame( array(), $bad, 'Ai, not AI, in words people see' );
	}

	public function test_the_bottom_bar_labels_are_translated(): void {
		$r = self::root();
		$pairs = array(
			array( 'zorderz-apps/apps/jobs/app.php', "'label'      => __( 'Jobs', 'zorderz' )", 'zorderz-apps/apps/jobs/assets/js/nav.js', 'Jobs' ),
			array( 'zorderz-apps/apps/messaging/app.php', "'label'   => __( 'Team', 'zorderz' )", 'zorderz-apps/apps/messaging/assets/js/nav-inject.js', 'Team' ),
			array( 'zorderz-apps/apps/scheduler/app.php', "'label'   => __( 'Schedule', 'zorderz' )", 'zorderz-apps/apps/scheduler/assets/js/nav-inject.js', 'Schedule' ),
			array( 'zorderz/functions.php', "'notices' => __( 'Notices', 'zorderz' )", 'zorderz/assets/js/zdz-bell.js', 'Notices' ),
		);
		foreach ( $pairs as $p ) {
			$this->assertStringContainsString( $p[1], (string) file_get_contents( $r . '/' . $p[0] ), $p[0] . ' hands the script its word' );
			$js = (string) file_get_contents( $r . '/' . $p[2] );
			$this->assertStringNotContainsString( '>' . $p[3] . '</span>', $js, $p[2] . ': no fixed English label in the markup' );
			$this->assertStringNotContainsString( "'aria-label', '" . $p[3] . "'", $js, $p[2] . ': no fixed English name' );
			$this->assertStringContainsString( "|| '" . $p[3] . "'", $js, $p[2] . ': English only as the fallback' );
		}
	}

	public function test_the_receipts_menu_says_receipts(): void {
		$php = (string) file_get_contents( self::root() . '/zorderz-apps/apps/receipts/includes/class-zrcpt-receipt.php' );
		$this->assertStringContainsString( "'name'               => __( 'Receipts', 'zorderz' )", $php );
		$this->assertStringContainsString( "__( 'Receipt · %s', 'zorderz' )", $php, 'a new receipt is titled Receipt' );
		$this->assertStringContainsString( "zrcpt_document_convention( 'receipt_link_label', 'Receipt - Link' )", $php );
		$this->assertStringContainsString( '"[Receipt] Receipt sent\n"', $php );
		// outside comments, the trade's word is left only where an older invoice's link line is still recognised
		$code = '';
		foreach ( token_get_all( $php ) as $tok ) {
			if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= is_array( $tok ) ? $tok[1] : $tok;
			}
		}
		$this->assertSame( 1, substr_count( $code, 'Installation Receipt' ) );
		$this->assertStringContainsString( 'array_filter( [ $this->receipt_link_label(), \'Installation Receipt - Link\' ] )', $code );
	}

	public function test_no_file_carries_double_encoded_characters(): void {
		$bad = array();
		foreach ( array_merge( self::files( '.php' ), self::files( '.js' ) ) as $file ) {
			// UTF-8 read as Latin-1 and saved again: "â" followed by two C1 control characters
			if ( preg_match( '/\x{00E2}[\x{0080}-\x{00BF}][\x{0080}-\x{00BF}]/u', (string) file_get_contents( $file ) ) ) {
				$bad[] = substr( $file, strlen( self::root() ) + 1 );
			}
		}
		$this->assertSame( array(), $bad );
	}

	public function test_charts_answers_200_in_local_mode_and_503_for_an_unreachable_hub(): void {
		if ( ! class_exists( 'ZCH_REST' ) ) {
			require_once self::root() . '/zorderz-apps/apps/charts/includes/class-zch-rest.php';
		}
		$out = new ReflectionMethod( 'ZCH_REST', 'out' );
		$out->setAccessible( true );
		$local = $out->invoke( null, array( 'ok' => false, 'error' => array( 'code' => 'hub_unavailable', 'local' => true, 'message' => 'Local mode.' ) ) );
		$this->assertSame( 200, $local->get_status(), 'local mode is how the site runs, not an outage' );
		$this->assertTrue( $local->get_data()['error']['local'] );
		$down = $out->invoke( null, array( 'ok' => false, 'error' => array( 'code' => 'hub_unavailable', 'message' => 'The hub is asleep.' ) ) );
		$this->assertSame( 503, $down->get_status(), 'a hub that is set up and unreachable is an outage' );
		$other = $out->invoke( null, array( 'ok' => false, 'error' => array( 'code' => 'bad_question' ) ) );
		$this->assertSame( 200, $other->get_status() );
	}
}
