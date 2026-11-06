<?php
/**
 * ZJOB_Privacy_View — Projects for a Privacy View account (ZDZ_Privacy_View).
 *
 * The platform opens the Projects app to such an account for two reads only — zdz_jobs_projects_list and
 * zdz_jobs_project_detail — and masks every reply on the way out. This is the app's own part, on the server:
 *
 *   read only   every can_* and may_* flag is false at any depth, so no button that writes is drawn; opening a Project
 *               writes nothing for it (an open order's PO is shown as it would be, written for nobody).
 *   own words   free text can carry this Project's own customer word for word (lower case, or a business named with
 *               ordinary words, which no name rule can tell from prose). The server knows the exact strings, so the
 *               customer's name and business (whole phrases, any case), the name's words of three letters or more on
 *               their own (capitalised, so "brown frame" stays a colour), and each street are replaced on the server
 *               with the platform's pseudonyms.
 *   documents   every run of 3-8 digits under a document key (DOC_KEYS: a number, an invoice, an estimate, a PO) is a
 *               document pseudonym.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Privacy_View {

	/** Keys whose value is or holds an invoice / estimate number (a PO is a prefix and the job's invoice number). */
	const DOC_KEYS = array( 'number', 'job_invoice', 'job_po', 'po', 'po_was', 'invoice', 'estimate', 'estimate_num', 'invoice_num', 'source_ref' );

	/** Words never taken on their own from a customer's name (they join names, they are not one). */
	const NOT_A_NAME = array( 'and', 'the', 'of', 'mr', 'mrs', 'ms', 'dr', 'miss', 'llc', 'inc', 'corp', 'co', 'ltd', 'lp', 'family', 'trust', 'jr', 'sr' );

	/** Is the signed-in account a Privacy View one? */
	public static function on(): bool {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on();
	}

	/** Every can_* / may_* flag false at any depth; every document number a pseudonym. Pure but for the pseudonym. */
	public static function shape( array $out ): array {
		$sib = in_array( (string) ( $out['kind'] ?? '' ), array( 'estimate', 'invoice' ), true ) ? (string) $out['kind'] : '';
		foreach ( $out as $k => $v ) {
			if ( is_string( $k ) && ( 0 === strpos( $k, 'can_' ) || 0 === strpos( $k, 'may_' ) ) ) {
				$out[ $k ] = is_array( $v ) ? array_map( static function () {
					return false;
				}, $v ) : false;
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = self::shape( $v );
			} elseif ( is_string( $k ) && in_array( $k, self::DOC_KEYS, true ) && ( is_string( $v ) || is_int( $v ) ) ) {
				$out[ $k ] = self::docs_in( (string) $v, '' !== $sib ? $sib : ( false !== strpos( $k, 'estimate' ) ? 'estimate' : 'invoice' ) );
			}
		}
		return $out;
	}

	/** Each run of 3-8 digits as a document pseudonym. */
	public static function docs_in( string $v, string $kind ): string {
		if ( ! class_exists( 'ZDZ_Privacy_View' ) || ! method_exists( 'ZDZ_Privacy_View', 'doc' ) ) {
			return (string) preg_replace( '/\d{3,}/', '[number]', $v );
		}
		return (string) preg_replace_callback( '/(?<!\d)\d{3,8}(?!\d)/', static function ( $m ) use ( $kind ) {
			return (string) ZDZ_Privacy_View::doc( $kind, ltrim( $m[0], '0' ) );
		}, $v );
	}

	/**
	 * This Project's own customer words and streets as [pattern, pseudonym] pairs, longest first.
	 *
	 * @param string[] $names   the customer's name first, then the business
	 * @param string[] $streets
	 */
	public static function own_terms( array $names, array $streets ): array {
		if ( ! class_exists( 'ZDZ_Privacy_View' ) ) {
			return array();
		}
		$t     = array();
		$names = array_values( array_filter( array_map( static function ( $n ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $n ) );
		}, $names ), 'strlen' ) );
		foreach ( $names as $n ) {
			if ( mb_strlen( $n ) < 3 ) {
				continue;
			}
			$t[ 'i|' . mb_strtolower( $n ) ] = array( '/(?<![\p{L}\p{N}])' . preg_quote( $n, '/' ) . '(?![\p{L}\p{N}])/iu', (string) ZDZ_Privacy_View::customer( $n ), mb_strlen( $n ) );
		}
		$person = $names[0] ?? '';
		if ( '' !== $person ) {
			$code = (string) ZDZ_Privacy_View::customer( $person );
			foreach ( preg_split( '/[^\p{L}\'’\-]+/u', $person, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( mb_strlen( $w ) < 3 || in_array( mb_strtolower( $w ), self::NOT_A_NAME, true ) ) {
					continue;
				}
				$cap = mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_strtolower( mb_substr( $w, 1 ) );
				foreach ( array_unique( array( $cap, mb_strtoupper( $w ) ) ) as $form ) {
					$t[ 'c|' . $form ] = array( '/(?<![\p{L}\p{N}])' . preg_quote( $form, '/' ) . '(?![\p{L}\p{N}])/u', $code, mb_strlen( $form ) );
				}
			}
		}
		foreach ( $streets as $a ) {
			$line = trim( (string) preg_replace( '/,.*$/s', '', (string) $a ) );
			if ( mb_strlen( $line ) < 5 ) {
				continue;
			}
			$code = (string) ZDZ_Privacy_View::street( $line );
			$t[ 'i|' . mb_strtolower( $line ) ] = array( '/(?<![\p{L}\p{N}])' . preg_quote( $line, '/' ) . '(?![\p{L}\p{N}])/iu', $code, mb_strlen( $line ) );
			if ( preg_match( '/^(\d{1,6}[a-z]?)\s+(\S{2,})/iu', $line, $m ) ) {
				$p = $m[1] . ' ' . $m[2];
				$t[ 'i|' . mb_strtolower( $p ) ] = array( '/(?<![\p{L}\p{N}])' . preg_quote( $m[1], '/' ) . '\s+' . preg_quote( $m[2], '/' ) . '(?![\p{L}\p{N}])/iu', $code, mb_strlen( $p ) );
			}
		}
		$t = array_values( $t );
		usort( $t, static function ( $a, $b ) {
			return $b[2] - $a[2];
		} );
		return $t;
	}

	/** Every string in $v with the terms replaced (keys untouched; a link is left whole). */
	public static function scrub( $v, array $terms ) {
		if ( ! $terms ) {
			return $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::scrub( $x, $terms );
			}
			return $v;
		}
		if ( ! is_string( $v ) || '' === $v || preg_match( '#^(?:https?:)?//\S+$|^/\?\S+$#i', $v ) ) {
			return $v;
		}
		foreach ( $terms as $t ) {
			$v = (string) preg_replace( $t[0], $t[1], $v );
		}
		return $v;
	}

	/** The list: each row's own customer, business and street replaced in its own strings. */
	public static function rows( array $reply ): array {
		foreach ( (array) ( $reply['rows'] ?? array() ) as $i => $r ) {
			if ( is_array( $r ) ) {
				$reply['rows'][ $i ] = self::scrub( $r, self::own_terms( array( (string) ( $r['customer'] ?? '' ), (string) ( $r['business'] ?? '' ) ), array( (string) ( $r['address'] ?? '' ) ) ) );
			}
		}
		return self::shape( $reply );
	}

	/** The detail: the Project's own words replaced everywhere in the reply, then shape(). */
	public static function detail( array $out ): array {
		$p   = (array) ( $out['project'] ?? array() );
		$out = self::scrub( $out, self::own_terms( array( (string) ( $p['customer_name'] ?? '' ), (string) ( $p['customer_business'] ?? '' ) ), array( (string) ( $p['customer_address'] ?? '' ) ) ) );
		return self::shape( $out );
	}
}
