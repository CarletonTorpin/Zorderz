<?php
/**
 * ZEST_Warranty_Note - a warranty line on the customer's paperwork for a price book family that has one.
 *
 * When an estimate carries a line of a price book family whose pack names a warranty page (`warranty_url` in the
 * family's match block, an https address), the notes (customer-facing; a billing system carries an estimate's notes
 * onto the invoice it becomes) get one line with the link: the family's own words (`warranty_line` beside it, "{url}"
 * where the link goes), else
 * "<family label> warranty: <link>". The product is decided by the price book's own test (ZDZ_Price_Book::
 * is_family_line: the line's name, or its sub-line where the family says so; notes never read). Added once: notes
 * that already hold the line or the link are left alone, so re-saves never stack it. A business turns it off with the
 * filter zest_warranty_note (default on).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Warranty_Note {

	public static function enabled(): bool {
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'zest_warranty_note', true ) : true;
	}

	/** The warranty line for one family, or '' when its pack names no warranty page. */
	public static function line_for( string $family ): string {
		if ( ! class_exists( 'ZDZ_Price_Book' ) ) {
			return '';
		}
		$f   = ZDZ_Price_Book::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZDZ_Price_Book::family() is a method, not the removed fam extension
		$url = is_array( $f ) ? trim( (string) ( $f['warranty_url'] ?? ( $f['match']['warranty_url'] ?? '' ) ) ) : '';
		if ( ! preg_match( '#^https://[A-Za-z0-9.\-]+/[A-Za-z0-9/_\-.%]*$#', $url ) ) {
			return '';
		}
		$words = is_array( $f ) ? trim( (string) ( $f['warranty_line'] ?? ( $f['match']['warranty_line'] ?? '' ) ) ) : '';
		if ( '' !== $words && false !== strpos( $words, '{url}' ) ) {
			return str_replace( '{url}', $url, $words );
		}
		/* translators: 1: the product family's name, 2: the warranty page */
		return sprintf( __( '%1$s warranty: %2$s', 'zorderz' ), (string) ( $f['label'] ?? $family ), $url );
	}

	/** The families the lines sell (engine items or billing-shape lines). */
	public static function families_in( array $lines ): array {
		if ( ! class_exists( 'ZEST_Price_Book_Lines' ) ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$li = array_key_exists( 'name', $l )
				? array( 'description' => (string) $l['name'], 'sub_description' => (string) ( $l['full_description'] ?? ( $l['description'] ?? '' ) ) )
				: $l;
			$f = ZEST_Price_Book_Lines::family_of( $li ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZEST_Price_Book_Lines::family_of() is a method of this app, not the removed fam extension
			if ( '' !== $f ) {
				$out[ $f ] = true;
			}
		}
		return array_keys( $out );
	}

	/** The notes with each family's warranty line, once. */
	public static function add( string $notes, array $lines ): string {
		if ( ! self::enabled() ) {
			return $notes;
		}
		foreach ( self::families_in( $lines ) as $f ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families_in() is a method of this class, not the removed fam extension
			$line = self::line_for( $f );
			if ( '' === $line ) {
				continue;
			}
			preg_match( '#https://\S+#', $line, $m );
			if ( false !== stripos( $notes, $line ) || ( isset( $m[0] ) && false !== stripos( $notes, $m[0] ) ) ) {
				continue;
			}
			$t     = rtrim( $notes );
			$notes = '' === $t ? $line : $t . "\n\n" . $line;
		}
		return $notes;
	}
}
