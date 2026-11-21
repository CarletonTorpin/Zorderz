<?php
/**
 * When the apps bundle is updated in place, each app's activation work re-runs once,
 * late on init: WordPress has its rewrite object (an app adds a rewrite rule there),
 * translations may load (post type labels), and every app's own init registrations
 * are in place, so the one flush at the end writes complete rules. Before init the
 * rule call failed and every flush did nothing.
 *
 * Read from the bundle's main file with the PHP tokenizer.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

class AppsUpdateTimingTest extends TestCase {

	/** Every add_action( ... ) in the bundle's main file as [ hook, body, priority ]. */
	private function actions(): array {
		$tokens = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/zorderz-apps.php' ) );
		$count  = count( $tokens );
		$out    = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 'add_action' !== $tokens[ $i ][1] ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}
			$depth = 0;
			$args  = array( '' );
			for ( $k = $j; $k < $count; $k++ ) {
				$t    = $tokens[ $k ];
				$text = is_array( $t ) ? $t[1] : $t;
				if ( in_array( $text, array( '(', '[', '{' ), true ) || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					$depth++;
					if ( 1 === $depth ) {
						continue;
					}
				} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $text && 1 === $depth ) {
					$args[] = '';
					continue;
				}
				$args[ count( $args ) - 1 ] .= $text;
			}
			$out[] = array(
				'hook'     => trim( trim( $args[0] ), "'\"" ),
				'body'     => $args[1] ?? '',
				'priority' => isset( $args[2] ) ? (int) trim( $args[2] ) : 10,
			);
			$i = $k;
		}
		return $out;
	}

	private function update_gate(): array {
		$found = array_values( array_filter( $this->actions(), static function ( $a ) {
			return false !== strpos( $a['body'], "get_option( 'zdz_apps_version' )" );
		} ) );
		$this->assertCount( 1, $found, 'one in-place update gate' );
		return $found[0];
	}

	public function test_the_update_work_runs_late_on_init(): void {
		$gate = $this->update_gate();
		$this->assertSame( 'init', $gate['hook'], 'not before init: no rewrite object, no translations yet' );
		$this->assertGreaterThan( 10, $gate['priority'], 'after the apps register their post types and rules on init' );
	}

	public function test_the_update_work_flushes_rules_once_after_every_app(): void {
		$body = $this->update_gate()['body'];
		$this->assertSame( 1, substr_count( $body, 'flush_rewrite_rules(' ) );
		$this->assertGreaterThan( strpos( $body, 'foreach ( zdz_apps_manifest()' ), strpos( $body, 'flush_rewrite_rules(' ), 'the flush comes after every app' );
		$this->assertGreaterThan( strpos( $body, 'foreach ( zdz_apps_manifest()' ), strpos( $body, "update_option( 'zdz_apps_version'" ), 'the version is recorded after the work' );
	}
}
