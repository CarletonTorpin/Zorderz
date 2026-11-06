<?php
/**
 * ZEST_Doc_Reader - one billing estimate or invoice, read only, for another app (the messaging app's document chips).
 *
 * It answers one question: "may THIS person see document #N, and if so, what does it say?", with exactly the rule
 * this app uses for its own estimates (ZEST_Access::may_open: the admin tier, the estimate's creator or salesperson, an
 * administrator's grant). The caller decides whose eyes to use (the viewer's own, or the sender's of a share it has
 * verified); this never widens the rule and never writes anything.
 *
 *   $r = apply_filters( 'zdz_fb_document_for_viewer', null, '1042', array(
 *       'as_user'    => $user_id,    // whose rights to use (required)
 *       'kind'       => '',          // '' = estimate first, then invoice; or 'estimate' | 'invoice'
 *       'price_user' => $viewer_id,  // whose price right decides the amounts (default: as_user)
 *   ) );
 *   // -> [ 'ok' => true,  'kind' => 'estimate', 'doc' => [ ...the paper... ], 'also' => '' ]
 *   // -> [ 'ok' => false, 'why' => 'not_yours' | 'not_found' | 'unavailable' | 'no_access' | 'shared_device' | 'bad_number' ]
 *
 * An estimate is found by its number in this app's own rows, then read from the billing system by its id (the answer
 * must be that very document). An invoice is found by its number in the billing system and goes to the admin tier, or
 * to a salesperson whose own it is (ZEST_Billing_View::owns: made from an estimate that is theirs, or its location
 * line names exactly them); nobody else's, and nothing when that rule is not here. Amounts
 * appear only when both the rights-holder and the person who will look may see prices. The business header and logo
 * come from the charts app's invoice card when it is there (ZCH_Invoice_Doc::business / ::logo), else the Business
 * Profile. Gate codes are cut from every word. The billing answer is kept 5 minutes, a miss 60 seconds; who may see it
 * is decided on every call.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Doc_Reader {

	public static function init(): void {
		add_filter( 'zdz_fb_document_for_viewer', array( __CLASS__, 'for_viewer' ), 10, 3 );
	}

	public static function for_viewer( $result = null, $number = '', $args = array() ) {
		if ( is_array( $result ) ) {
			return $result;   // another provider answered first
		}
		$args   = is_array( $args ) ? $args : array();
		$number = ltrim( (string) preg_replace( '/\D/', '', (string) $number ), '0' );
		if ( '' === $number || strlen( $number ) > 12 ) {
			return array( 'ok' => false, 'why' => 'bad_number' );
		}
		$uid = (int) ( $args['as_user'] ?? 0 );
		if ( $uid <= 0 || ! get_userdata( $uid ) ) {
			return array( 'ok' => false, 'why' => 'no_access' );
		}
		if ( ZEST_Access::is_kiosk( $uid ) ) {
			return array( 'ok' => false, 'why' => 'shared_device' );
		}
		$price = (int) ( $args['price_user'] ?? $uid );
		$price = $price > 0 && get_userdata( $price ) ? $price : $uid;
		$kind  = in_array( $args['kind'] ?? '', array( 'estimate', 'invoice' ), true ) ? (string) $args['kind'] : '';
		try {
			$out = self::run( $number, $kind, $uid, $price );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'why' => 'unavailable' );
		}
		return class_exists( 'ZEST_Sealed' ) ? ZEST_Sealed::deep( $out ) : $out;
	}

	private static function run( string $number, string $kind, int $uid, int $price ): array {
		$kinds  = '' !== $kind ? array( $kind ) : array( 'estimate', 'invoice' );
		$found  = false;
		foreach ( $kinds as $k ) {
			if ( 'estimate' === $k ) {
				$row = ZEST_Access::row_by_number( $number );
				if ( ! $row || '' === (string) $row['billing_doc_id'] ) {
					continue;
				}
				$found = true;
				if ( ! ZEST_Access::may_open( $row, $uid ) ) {
					continue;
				}
				$doc = self::doc( 'estimate', (string) $row['billing_doc_id'] );
			} else {
				$admin = ZEST_Access::is_admin_tier( $uid );
				if ( ! $admin && ! class_exists( 'ZEST_Billing_View' ) ) {
					continue;   // no ownership rule for invoices here: the admin tier's only
				}
				$doc = self::doc_by_number( 'invoice', $number );
				if ( false === $doc && $found ) {
					continue;   // the estimate of that number was found: its answer stands
				}
				if ( is_array( $doc ) ) {
					$found = true;
					if ( ! $admin && ! ZEST_Billing_View::owns( $doc, 'invoice', $uid ) ) {
						continue;
					}
				}
			}
			if ( false === $doc ) {
				return array( 'ok' => false, 'why' => 'unavailable' );
			}
			if ( ! is_array( $doc ) ) {
				continue;
			}
			return array( 'ok' => true, 'kind' => $k, 'doc' => self::shape( $doc, $k, $uid, $price ), 'also' => '' );
		}
		return array( 'ok' => false, 'why' => $found ? 'not_yours' : 'not_found' );
	}

	/** The document by id, kept 5 minutes; null when there is none, false when the billing system can't be read. */
	private static function doc( string $kind, string $id ) {
		$key = 'zest_docr_' . $kind . '_' . $id;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return isset( $hit['doc'] ) && is_array( $hit['doc'] ) ? $hit['doc'] : null;
		}
		if ( ! ZEST_Billing_Doc::available() ) {
			return false;
		}
		$doc = ZEST_Billing_Doc::read( $kind, $id );
		if ( ! is_array( $doc ) || 1 === (int) ( $doc['vis_state'] ?? 0 ) ) {
			set_transient( $key, array( 'miss' => 1 ), 60 );
			return null;
		}
		set_transient( $key, array( 'doc' => $doc ), 300 );
		return $doc;
	}

	/** A document by its number (the billing system's own search), kept as above. */
	private static function doc_by_number( string $kind, string $number ) {
		$key = 'zest_docr_n_' . $kind . '_' . $number;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return isset( $hit['doc'] ) && is_array( $hit['doc'] ) ? $hit['doc'] : null;
		}
		$d = ZEST_Billing_Doc::find_by_number( $kind, $number );
		if ( false === $d ) {
			return false;
		}
		set_transient( $key, is_array( $d ) ? array( 'doc' => $d ) : array( 'miss' => 1 ), is_array( $d ) ? 300 : 60 );
		return is_array( $d ) ? $d : null;
	}

	private static function t( $s, int $max ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$s = wp_check_invalid_utf8( $s, true );
		}
		$s = trim( str_replace( "\r\n", "\n", (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function money( $v ): ?float {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? null;
		}
		return is_numeric( $v ) ? round( (float) $v, 2 ) : null;
	}

	private static function sees_prices( int $uid ): bool {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		$p = class_exists( 'ZEST_Dashboard' ) ? ZEST_Dashboard::get_resolved_permissions( $uid ) : array();
		return ! empty( $p['view_pricing'] );
	}

	/** The paper: plain text only (the page writes text nodes, never HTML); amounts only when both may see prices. */
	public static function shape( array $doc, string $kind, int $uid, int $price ): array {
		$money  = self::sees_prices( $uid ) && ( $price === $uid || self::sees_prices( $price ) );
		$org    = self::t( $doc['organization'] ?? '', 200 );
		$person = self::t( trim( (string) ( $doc['fname'] ?? '' ) . ' ' . (string) ( $doc['lname'] ?? '' ) ), 200 );
		$place  = trim( self::t( $doc['city'] ?? '', 120 ) . ', ' . self::t( $doc['province'] ?? '', 60 ), ', ' );
		$code   = self::t( $doc['code'] ?? '', 20 );
		$billed = array_values( array_filter( array( '' !== $org ? $org : $person, self::t( $doc['street'] ?? '', 200 ), self::t( $doc['street2'] ?? '', 200 ), trim( $place . ( '' !== $code ? ' ' . $code : '' ) ) ), 'strlen' ) );
		$lines  = array();
		$sub    = 0.0;
		foreach ( array_slice( (array) ( $doc['lines'] ?? array() ), 0, 300 ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$rate = self::money( $ln['unit_cost'] ?? null );
			$qty  = is_numeric( $ln['qty'] ?? null ) ? 0 + $ln['qty'] : null;
			$amt  = self::money( $ln['amount'] ?? null );
			if ( null === $amt && null !== $rate && null !== $qty ) {
				$amt = round( $rate * $qty, 2 );
			}
			$sub    += (float) $amt;
			$lines[] = array(
				'name'        => self::t( $ln['name'] ?? '', 1000 ),
				'description' => self::t( $ln['description'] ?? '', 5000 ),
				'rate'        => $money ? $rate : null,
				'qty'         => $qty,
				'amount'      => $money ? $amt : null,
			);
		}
		$total    = self::money( $doc['amount'] ?? null );
		$discount = self::money( $doc['discount_total'] ?? null );
		$tax      = null !== $total ? round( $total - $sub + (float) $discount, 2 ) : null;
		$totals   = $money ? array( 'subtotal' => round( $sub, 2 ), 'discount' => $discount ?: null, 'tax' => ( null !== $tax && abs( $tax ) < 0.005 ) ? 0.0 : $tax, 'total' => $total ) : null;
		if ( $money && 'invoice' === $kind ) {
			$totals['paid'] = self::money( $doc['paid'] ?? null );
			$totals['due']  = self::money( $doc['outstanding'] ?? null );
		}
		return array(
			'kind'      => $kind,
			'number'    => self::t( $doc[ $kind . '_number' ] ?? '', 40 ),
			'issued'    => preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $doc['create_date'] ?? '' ) ) ? substr( (string) $doc['create_date'], 0, 10 ) : '',
			'reference' => self::t( $doc['po_number'] ?? '', 200 ),
			'customer'  => '' !== $org ? $org : $person,
			'billed_to' => $billed,
			'lines'     => $lines,
			'totals'    => $totals,
			'notes'     => self::t( $doc['notes'] ?? '', 5000 ),
			'terms'     => self::t( $doc['terms'] ?? '', 5000 ),
			'money'     => $money,
			'business'  => self::business(),
			'logo'      => self::logo(),
		);
	}

	/** The business header the charts app's invoice card prints, else the Business Profile's name. */
	public static function business(): array {
		if ( class_exists( 'ZCH_Invoice_Doc' ) && method_exists( 'ZCH_Invoice_Doc', 'business' ) ) {
			try {
				$b = ZCH_Invoice_Doc::business();
				if ( is_array( $b ) && isset( $b['name'] ) ) {
					return array( 'name' => (string) $b['name'], 'lines' => array_values( array_map( 'strval', (array) ( $b['lines'] ?? array() ) ) ) );
				}
			} catch ( \Throwable $e ) {
				// fall through to the profile
			}
		}
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		return array( 'name' => '' !== $name ? $name : ( function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '' ), 'lines' => array() );
	}

	/** The invoice logo from the same place the charts app takes it. https only. */
	public static function logo(): string {
		$url = '';
		if ( class_exists( 'ZCH_Invoice_Doc' ) && method_exists( 'ZCH_Invoice_Doc', 'logo' ) ) {
			try {
				$url = (string) ZCH_Invoice_Doc::logo();
			} catch ( \Throwable $e ) {
				$url = '';
			}
		} elseif ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'logo' ) ) {
			$l   = ZDZ_Business_Profile::logo( 'wide', 'light' );
			$url = is_array( $l ) ? (string) ( $l['url'] ?? '' ) : '';
		}
		return preg_match( '#^https://#i', $url ) ? $url : '';
	}
}
