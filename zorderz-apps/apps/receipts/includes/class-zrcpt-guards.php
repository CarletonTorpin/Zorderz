<?php
/**
 * ZRCPT_Guards — the pure decisions that keep one customer's receipt on that customer's
 * paperwork and photos.
 *
 *   - partition_invoices(): a receipt is for ONE billing customer. The first kept invoice
 *     fixes that customer; a later invoice of a different (or unconfirmable) customer is
 *     dropped, failing closed. When the receipt is bound to an item tag, invoices that do
 *     not carry the tag are dropped too, but that topic filter fails open: if it would drop
 *     every invoice, all are kept, so a receipt is never emitted empty.
 *   - link_write_allowed(): the last check before the receipt's public link is written
 *     onto a billing document. Blanking a link line is always allowed; writing one needs
 *     the receipt's customer and the document's customer to be known and equal.
 *   - reuse_allowed(): which receipt page a generate updates. A page found by one of this
 *     job's invoice numbers is the same job and is reused (its link stays the same). A page
 *     found only by address or slug that is bound to OTHER invoices is a different job at
 *     the same place: a new page is made, so the second job never takes over the link the
 *     first job's customer already has.
 *
 * No WordPress calls: every input is passed in, so each rule is a unit test.
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZRCPT_Guards {

	/**
	 * May the receipt link be written onto a document of customer $doc_customer_id?
	 *
	 * @param string $receipt_url         The link being written ('' = blank the line).
	 * @param string $expected_customer_id The receipt's billing customer id ('' = unresolved).
	 * @param string $doc_customer_id      The document's billing customer id ('' = unknown).
	 */
	public static function link_write_allowed( string $receipt_url, string $expected_customer_id, string $doc_customer_id ): bool {
		if ( '' === $receipt_url ) {
			return true; // removing a link never exposes anything
		}
		$expected_customer_id = trim( $expected_customer_id );
		$doc_customer_id      = trim( $doc_customer_id );
		return '' !== $expected_customer_id && '' !== $doc_customer_id && $doc_customer_id === $expected_customer_id;
	}

	/**
	 * Partition a receipt's source invoices.
	 *
	 * @param array    $docs        number => billing document (array with 'customerid'), in the
	 *                              order the reviewer chose; a number with no document is skipped.
	 * @param bool     $tag_only    true when the receipt is bound to an item tag.
	 * @param callable $matches_tag fn( array $doc ): bool — does the document carry the tag.
	 * @return array{kept:string[],anchor:string,dropped_tag:string[],dropped_customer:string[],fail_open:bool}
	 */
	public static function partition_invoices( array $docs, bool $tag_only, callable $matches_tag ): array {
		$kept    = array();
		$anchor  = '';
		$have    = false;
		$drop_t  = array();
		$drop_c  = array();
		foreach ( $docs as $num => $doc ) {
			$num = (string) $num;
			if ( ! is_array( $doc ) || empty( $doc ) ) {
				continue;
			}
			if ( $tag_only && ! $matches_tag( $doc ) ) {
				$drop_t[] = $num;
				continue;
			}
			$cid = trim( (string) ( $doc['customerid'] ?? '' ) );
			if ( $have && $cid !== $anchor ) {
				$drop_c[] = $num;
				continue;
			}
			if ( ! $have ) {
				$have   = true;
				$anchor = $cid;
			}
			$kept[] = $num;
		}
		$fail_open = false;
		if ( empty( $kept ) && ! empty( $drop_t ) ) {
			// The topic filter alone dropped everything: keep the set rather than emit an
			// empty receipt. No identity drop can have happened (that needs a kept anchor),
			// and link_write_allowed() still guards every write.
			$fail_open = true;
			$kept      = array();
			$anchor    = '';
			$have      = false;
			foreach ( $docs as $num => $doc ) {
				if ( ! is_array( $doc ) || empty( $doc ) ) {
					continue;
				}
				$cid = trim( (string) ( $doc['customerid'] ?? '' ) );
				if ( $have && $cid !== $anchor ) {
					$drop_c[] = (string) $num;
					continue;
				}
				if ( ! $have ) {
					$have   = true;
					$anchor = $cid;
				}
				$kept[] = (string) $num;
			}
			$drop_t = array();
		}
		return array(
			'kept'             => $kept,
			'anchor'           => $anchor,
			'dropped_tag'      => $drop_t,
			'dropped_customer' => $drop_c,
			'fail_open'        => $fail_open,
		);
	}

	/**
	 * May a generate for $want invoice numbers update an existing receipt page?
	 *
	 * @param bool  $matched_by_invoice the page was found by one of $want's invoice numbers.
	 * @param array $bound              invoice numbers the page is bound to (may be empty).
	 * @param array $want               invoice numbers this generate is for.
	 */
	public static function reuse_allowed( bool $matched_by_invoice, array $bound, array $want ): bool {
		if ( $matched_by_invoice ) {
			return true;
		}
		$bound = self::digits( $bound );
		if ( empty( $bound ) ) {
			return true; // an unbound page (saved before pages were bound to invoices)
		}
		return ! empty( array_intersect( $bound, self::digits( $want ) ) );
	}

	/** Digit-only invoice numbers, empties dropped, unique, re-indexed. */
	private static function digits( array $nums ): array {
		$out = array();
		foreach ( $nums as $n ) {
			$d = preg_replace( '/[^0-9]/', '', (string) $n );
			if ( '' !== $d ) {
				$out[ $d ] = $d;
			}
		}
		return array_values( $out );
	}
}
