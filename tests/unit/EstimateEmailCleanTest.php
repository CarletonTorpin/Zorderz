<?php
/**
 * Estimates: an e-mail address never keeps its sentence's punctuation.
 *
 * A dictated or pasted address arrives as "... my email is alex@example.com." The cleaner drops opening brackets and
 * quotes from the front, closing punctuation, brackets and quotes from the end and a mailto: prefix, then checks what
 * is left; it never removes a character that is legal at the start of an address, refuses a placeholder, and refuses a
 * very long paste without scanning it. The create path cleans the address once, so the billing system, the CRM lead
 * and the app's own row get the same one.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateEmailCleanTest extends TestCase {

	private static function c( $v ): string {
		return ZEST_FreshBooks::clean_email( $v );
	}

	public function test_punctuation_around_the_address_is_dropped(): void {
		foreach ( array(
			'alex@example.com.', 'alex@example.com,', 'alex@example.com;', 'alex@example.com:', 'alex@example.com!?', '<alex@example.com>', '(alex@example.com).',
			'"alex@example.com"', 'mailto:alex@example.com', "mailto:'alex@example.com'", "\u{201C}alex@example.com\u{201D}.", "\u{FEFF}alex@example.com", '  alex@example.com  ', 'MAILTO:<alex@example.com>,',
		) as $typed ) {
			$this->assertSame( 'alex@example.com', self::c( $typed ), var_export( $typed, true ) );
		}
	}

	public function test_what_must_not_change(): void {
		$this->assertSame( 'alex.morgan@example.com', self::c( 'Alex.Morgan@Example.com' ), 'a good address, in the lower case this app keeps' );
		$this->assertSame( 'a.l.e.x@example.com', self::c( 'a.l.e.x@example.com' ), 'a dot inside the local part is kept' );
		$this->assertSame( '!alex@example.com', self::c( '!alex@example.com' ), 'a leading "!" is legal there: it is never removed' );
		$this->assertSame( '{alex}@example.com', self::c( '{alex}@example.com' ) );
	}

	public function test_what_is_still_refused(): void {
		foreach ( array( '', '.', 'unknown', 'unknown.', 'N/A', 'none', 'no email', 'alex.example.com.', 'alex@morgan@example.com', 'alex@example..com', 'alex morgan@example.com', 'alex@', '@example.com' ) as $typed ) {
			$this->assertSame( '', self::c( $typed ), var_export( $typed, true ) );
		}
		$this->assertSame( '', self::c( null ) );
		$this->assertSame( '', self::c( array( 'alex@example.com' ) ) );
		$this->assertSame( '', self::c( "alex@example.com\xC3\x28" ), 'text that is not valid is refused, never passed on' );
	}

	public function test_a_very_long_paste_is_refused_without_being_scanned(): void {
		$t0 = microtime( true );
		$this->assertSame( '', self::c( str_repeat( '.', 100000 ) . 'alex@example.com' ) );
		$this->assertSame( '', self::c( 'alex@example.com' . str_repeat( '.', 400 ) ), 'longer than an address can be' );
		$this->assertSame( 'alex@example.com', self::c( str_repeat( ' ', 400 ) . 'alex@example.com.' ), 'spaces around it do not count' );
		$this->assertLessThan( 0.5, microtime( true ) - $t0 );
		$this->assertSame( '', self::c( str_repeat( 'a', 320 ) . '@example.com' ) );
	}

	public function test_the_create_path_cleans_the_address_once_before_anything_is_sent(): void {
		$dash  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-dashboard.php' );
		$from  = strpos( $dash, 'public static function create_core' );
		$body  = substr( $dash, $from, strpos( $dash, 'public static function ajax_update' ) - $from );
		$clean = strpos( $body, "\$raw['customer']['email'] = \$email;" );
		$this->assertNotSame( false, $clean );
		$this->assertNotSame( false, strpos( $body, "\$raw['customer_email'] = \$email;" ) );
		$this->assertLessThan( strpos( $body, 'ZEST_FreshBooks::create_estimate( $raw' ), $clean, 'before the billing system' );
		$this->assertLessThan( strpos( $body, 'self::store_row( $raw' ), $clean, 'before the app\'s own row' );
		$this->assertLessThan( strpos( $body, 'ZEST_Nutshell::create_lead(' ), $clean, 'before the CRM lead' );
		$this->assertSame( 1, substr_count( $body, 'ZEST_FreshBooks::clean_email(' ), 'once' );
		$this->assertMatchesRegularExpression( '/\$email = ZEST_FreshBooks::clean_email\([^\n]+\);\n\t\tif \( array_key_exists\( \'customer_email\', \$raw \) \) \{\n\t\t\t\$raw\[\'customer_email\'\] = \$email;\n\t\t\}\n\t\tif \( isset\( \$raw\[\'customer\'\] \)[^\n]+\{\n\t\t\t\$raw\[\'customer\'\]\[\'email\'\] = \$email;\n\t\t\}\n\t\t\$is_operator/', $body,
			'written back right where it is cleaned, before anything else reads it' );
	}
}
