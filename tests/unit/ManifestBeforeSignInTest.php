<?php
/**
 * The web app manifest is served before the theme's access control.
 *
 * Browsers fetch a manifest without cookies unless the link asks for credentials, so
 * the request that fetches /zdz-manifest.json is a signed-out one even for a signed-in
 * person. If the access control's template_redirect runs first, the browser gets the
 * sign-in page instead of the app's name, colours and icons. These tests read the
 * theme's own hook registrations with the PHP tokenizer and check the order.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

class ManifestBeforeSignInTest extends TestCase {

	/**
	 * Every add_action( 'template_redirect', ... ) in functions.php as
	 * [ 'body' => callback source, 'priority' => int ].
	 *
	 * @return array<int, array{body:string, priority:int}>
	 */
	private function template_redirect_hooks(): array {
		$src    = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/functions.php' );
		$tokens = token_get_all( $src );
		$count  = count( $tokens );
		$hooks  = array();
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
			// Split the call's arguments at depth 1.
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
			if ( "'template_redirect'" !== trim( $args[0] ) ) {
				continue;
			}
			$hooks[] = array(
				'body'     => $args[1] ?? '',
				'priority' => isset( $args[2] ) ? (int) trim( $args[2] ) : 10,
			);
			$i = $k;
		}
		return $hooks;
	}

	private function hook_with( string $needle ): array {
		$found = array_values( array_filter( $this->template_redirect_hooks(), static function ( $h ) use ( $needle ) {
			return false !== strpos( $h['body'], $needle );
		} ) );
		$this->assertCount( 1, $found, 'one template_redirect hook mentions ' . $needle );
		return $found[0];
	}

	public function test_the_manifest_is_served_before_the_access_control(): void {
		$manifest = $this->hook_with( "get_query_var( 'zdz_manifest' )" );
		$gate     = $this->hook_with( 'is_user_logged_in()' );
		$this->assertLessThan( $gate['priority'], $manifest['priority'], 'the manifest runs before the sign-in gate' );
		$this->assertStringNotContainsString( 'is_user_logged_in', $manifest['body'], 'the manifest asks for no sign-in' );
	}

	public function test_the_manifest_runs_before_the_sign_in_redirects_too(): void {
		$manifest = $this->hook_with( "get_query_var( 'zdz_manifest' )" );
		// ZDZ_Login_Next continues a signed-in visitor from the home page at priority 9.
		$this->assertLessThan( 9, $manifest['priority'] );
		$this->assertStringContainsString( 'application/manifest+json', $manifest['body'] );
	}

	public function test_the_manifest_is_sent_with_a_200_status(): void {
		$body = $this->hook_with( "get_query_var( 'zdz_manifest' )" )['body'];
		// The main query may have marked the request 404; a browser rejects a manifest with an error status.
		$this->assertStringContainsString( 'status_header( 200 )', $body );
		$this->assertLessThan( strpos( $body, 'echo' ), strpos( $body, 'status_header( 200 )' ), 'the status is set before the body' );
	}
}
