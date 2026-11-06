<?php
/**
 * The invoice itself, as the customer got it.
 *
 * A tapped invoice number or line item opens the real-looking invoice, with the job's extras
 * (history, source, the questions to tap) in a strip below it.
 *
 * Where it comes from: the billing system (FreshBooks), read live by number through the
 * platform's shared client, ZDZ_Core_FreshBooks, which holds the connection and refreshes its
 * token. One read per opened card; nothing is stored on the site and nothing from it goes to
 * the Charts hub. The REST layer asks for it only after the card's three locks passed AND the
 * person may see the customer's name (owner, admins, or the job's own salesperson), so this
 * never widens who sees what. Without the money right every rate, line total and total is
 * left out.
 *
 * Nothing here is logged with a name or an address: failures log a reason code only.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Invoice_Doc {

	const TIMEOUT      = 8;          // seconds per billing call; the card falls back after.
	const BUSINESS_TTL = 43200;      // the company header (not personal) is kept 12 h.

	/** Injected in tests: callable($url) => decoded billing JSON, or throws. */
	public static $get = null;
	/** Injected in tests: the billing account id. */
	public static $account = null;
	/** Why the last fetch gave nothing (a code, for the card's note and the tests). */
	public static $why = '';
	/** Injected in tests: callable($invoice_id) => the billing system's share_link answer, or throws. */
	public static $share = null;

	/* ── Billing-system access ──────────────────────────────────────────── */

	private static function account_id() {
		if ( null !== self::$account ) {
			return (string) self::$account;
		}
		if ( ! class_exists( 'ZDZ_Core_Settings' ) || ! method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' ) ) {
			return '';
		}
		return (string) ZDZ_Core_Settings::get_fb_account_id();
	}

	/** A callable($url) that returns the billing system's decoded JSON, or null when it is not connected. */
	private static function getter() {
		if ( is_callable( self::$get ) ) {
			return self::$get;
		}
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return null;
		}
		try {
			$fb = new ZDZ_Core_FreshBooks();
			if ( method_exists( $fb, 'is_configured' ) && ! $fb->is_configured() ) {
				return null;
			}
			return function ( $url ) use ( $fb ) {
				$res = $fb->api_request( 'GET', $url );
				if ( null === $res ) {
					throw new RuntimeException( 'billing read failed' );
				}
				$errs = $res['response']['errors'] ?? null;
				if ( is_array( $errs ) && $errs ) {
					$code = (int) ( $errs[0]['errno'] ?? 0 );
					throw new RuntimeException( 8034 === $code ? 'HTTP 422' : 'billing error' );
				}
				return $res;
			};
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** "0001234" and "1234" are the same invoice (ZCH_Ledger::norm). */
	private static function same_no( $a, $b ) {
		$a = ltrim( (string) $a, '0' );
		$b = ltrim( (string) $b, '0' );
		return ( '' === $a ? '0' : $a ) === ( '' === $b ? '0' : $b );
	}

	/** The billing system's invoice (with lines) for a number, or null. */
	public static function fetch( $no ) {
		$all = self::matches( $no, false );
		return $all ? $all[0] : null;
	}

	/**
	 * Every live invoice with this number. `$all` reads every variant, one per invoice id, so a
	 * number the billing system holds twice is seen as twice (the copy refuses it rather than
	 * pick one). [] with self::$why.
	 */
	private static function matches( $no, $all ) {
		self::$why = '';
		$found     = array();
		$no        = (string) $no;
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $no ) ) {
			self::$why = 'bad_number';
			return array();
		}
		$acct = self::account_id();
		$get  = self::getter();
		if ( '' === $acct || ! $get ) {
			self::$why = 'no_billing';
			return array();
		}
		$variants = array( $no );
		if ( ctype_digit( $no ) && strlen( $no ) < 7 ) {
			$variants[] = str_pad( $no, 7, '0', STR_PAD_LEFT );
		}
		foreach ( array_unique( $variants ) as $v ) {
			$url = 'https://api.freshbooks.com/accounting/account/' . rawurlencode( $acct ) . '/invoices/invoices'
				. '?per_page=5&search[invoice_number]=' . rawurlencode( $v ) . '&include[]=lines';
			try {
				$res = call_user_func( $get, $url );
			} catch ( \Throwable $e ) {
				self::$why = 'billing_error';
				if ( function_exists( 'error_log' ) ) {
					error_log( 'zorderz charts invoice view: billing read failed' );
				}
				return array();
			}
			foreach ( (array) ( $res['response']['result']['invoices'] ?? array() ) as $inv ) {
				if ( ! is_array( $inv ) || 1 === (int) ( $inv['vis_state'] ?? 0 ) ) {     // deleted.
					continue;
				}
				if ( ! self::same_no( $inv['invoice_number'] ?? '', $no ) ) {
					continue;
				}
				if ( ! $all ) {
					return array( $inv );
				}
				$found[ (string) ( $inv['invoiceid'] ?? ( $inv['id'] ?? count( $found ) ) ) ] = $inv;
			}
		}
		if ( ! $found ) {
			self::$why = 'not_found';
		}
		return array_values( $found );
	}

	/* ── The company header ─────────────────────────────────────────────── */

	/** Name, phone and address lines for the top right: the billing system's business, else the Business Profile (kept 12 h). */
	public static function business() {
		$cached = function_exists( 'get_transient' ) ? get_transient( 'zch_invoice_business' ) : false;
		if ( is_array( $cached ) ) {
			return self::business_filtered( $cached );
		}
		$out  = null;
		$get  = self::getter();
		$acct = self::account_id();
		if ( $get && '' !== $acct ) {
			try {
				$me = call_user_func( $get, 'https://api.freshbooks.com/auth/api/v1/users/me' );
				foreach ( (array) ( $me['response']['business_memberships'] ?? array() ) as $m ) {
					$b = $m['business'] ?? null;
					if ( ! is_array( $b ) || (string) ( $b['account_id'] ?? '' ) !== $acct ) {
						continue;
					}
					$a     = (array) ( $b['address'] ?? array() );
					$phone = $b['phone_number']['phone_number'] ?? ( $b['phone_number'] ?? '' );
					$out   = array(
						'name'  => self::t( $b['name'] ?? '', 120 ),
						'lines' => array_values(
							array_filter(
								array(
									self::t( is_string( $phone ) ? $phone : '', 40 ),
									self::t( $a['street'] ?? '', 120 ),
									self::t( $a['street2'] ?? '', 120 ),
									self::t( trim( ( $a['city'] ?? '' ) . ( ( $a['city'] ?? '' ) !== '' && ( $a['province'] ?? '' ) !== '' ? ', ' : '' ) . self::state( $a['province'] ?? '' ) . '  ' . ( $a['postal_code'] ?? '' ) ), 120 ),
									self::t( $a['country'] ?? '', 60 ),
								),
								'strlen'
							)
						),
					);
					break;
				}
			} catch ( \Throwable $e ) {
				$out = null;
			}
		}
		if ( ! $out ) {
			$out = self::profile_header();
		} elseif ( function_exists( 'set_transient' ) ) {
			set_transient( 'zch_invoice_business', $out, self::BUSINESS_TTL );
		}
		return self::business_filtered( $out );
	}

	/** The header from the Business Profile when the billing system gives none. */
	private static function profile_header() {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			return array(
				'name'  => (string) ZDZ_Business_Profile::name(),
				'lines' => array_values(
					array_filter(
						array(
							(string) ZDZ_Business_Profile::get( 'contact.phone', '' ),
							(string) ZDZ_Business_Profile::address_line(),
						),
						'strlen'
					)
				),
			);
		}
		return array( 'name' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '', 'lines' => array() );
	}

	private static function business_filtered( $b ) {
		return function_exists( 'apply_filters' ) ? apply_filters( 'zch_invoice_business', $b ) : $b;
	}

	/** The logo at the top left: the business's wide logo for a light page (filter `zch_invoice_logo`). */
	public static function logo() {
		$url = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$l   = ZDZ_Business_Profile::logo( 'wide', 'light' );
			$url = is_array( $l ) ? (string) ( $l['url'] ?? '' ) : '';
		}
		$url = function_exists( 'apply_filters' ) ? apply_filters( 'zch_invoice_logo', $url ) : $url;
		return ( is_string( $url ) && ( preg_match( '#^https://#i', $url ) || '' === $url ) ) ? $url : '';
	}

	/* ── Shaping ────────────────────────────────────────────────────────── */

	private static function t( $s, $max ) {
		$s = is_scalar( $s ) ? (string) $s : '';
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = str_replace( "\r\n", "\n", (string) $s );
		$s = trim( (string) $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** A US state's full name, as the invoice prints it: the two letters. */
	private static function state( $p ) {
		static $map = array( 'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE', 'District of Columbia' => 'DC', 'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK', 'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC', 'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY' );
		$p = trim( (string) $p );
		foreach ( $map as $name => $code ) {
			if ( 0 === strcasecmp( $p, $name ) ) {
				return $code;
			}
		}
		return $p;
	}

	private static function money( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? null;
		}
		return is_numeric( $v ) ? round( (float) $v, 2 ) : null;
	}

	private static function date10( $s ) {
		$s = (string) $s;
		return preg_match( '/^\d{4}-\d{2}-\d{2}/', $s ) ? substr( $s, 0, 10 ) : '';
	}

	/**
	 * The billing system's invoice -> what the page draws. Plain text only (the browser writes
	 * text nodes, never HTML). `money` false -> no rate, line total or total.
	 */
	public static function shape( array $inv, $money ) {
		$org    = self::t( $inv['organization'] ?? '', 200 );
		$person = self::t( trim( ( $inv['fname'] ?? '' ) . ' ' . ( $inv['lname'] ?? '' ) ), 200 );
		// "City, ST  ZIP", as the billing system prints it (a comma, then two spaces before the ZIP).
		$city    = self::t( $inv['city'] ?? '', 120 );
		$st      = self::state( self::t( $inv['province'] ?? '', 60 ) );
		$place   = trim( $city . ( '' !== $city && '' !== $st ? ', ' : ' ' ) . $st );
		$code    = self::t( $inv['code'] ?? '', 20 );
		$billed  = array_values(
			array_filter(
				array(
					'' !== $org ? $org : $person,
					self::t( $inv['street'] ?? '', 200 ),
					self::t( $inv['street2'] ?? '', 200 ),
					trim( $place . ( '' !== $code ? '  ' . $code : '' ) ),
				),
				'strlen'
			)
		);
		$country = self::t( $inv['country'] ?? '', 60 );
		if ( '' !== $country && ! preg_match( '/^(united states|usa?|us)$/i', $country ) ) {
			$billed[] = $country;
		}

		$lines = array();
		$sub   = 0.0;
		foreach ( array_slice( (array) ( $inv['lines'] ?? array() ), 0, 300 ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$amt   = self::money( $ln['amount'] ?? null );
			$sub  += (float) $amt;
			$taxes = array();
			foreach ( array( 1, 2 ) as $i ) {
				$n = self::t( $ln[ 'taxName' . $i ] ?? '', 40 );
				if ( '' !== $n ) {
					$taxes[] = $n;
				}
			}
			$lines[] = array(
				'name'        => self::t( $ln['name'] ?? '', 1000 ),
				'description' => self::t( $ln['description'] ?? '', 5000 ),
				'rate'        => $money ? self::money( $ln['unit_cost'] ?? null ) : null,
				'qty'         => is_numeric( $ln['qty'] ?? null ) ? 0 + $ln['qty'] : null,
				'amount'      => $money ? $amt : null,
				'taxes'       => $taxes,
			);
		}
		$total    = self::money( $inv['amount'] ?? null );
		$discount = self::money( $inv['discount_total'] ?? null );
		$tax      = null !== $total ? round( $total - $sub + (float) $discount, 2 ) : null;
		if ( null !== $tax && abs( $tax ) < 0.005 ) {
			$tax = 0.0;
		}
		$issued = self::date10( $inv['create_date'] ?? '' );
		$due    = self::date10( $inv['due_date'] ?? '' );
		if ( '' === $due && '' !== $issued && is_numeric( $inv['due_offset_days'] ?? null ) ) {
			$due = gmdate( 'Y-m-d', strtotime( $issued . ' +' . (int) $inv['due_offset_days'] . ' days' ) );
		}
		$currency = self::t( is_array( $inv['amount'] ?? null ) ? ( $inv['amount']['code'] ?? 'USD' ) : ( $inv['currency_code'] ?? 'USD' ), 8 );
		return array(
			'number'    => self::t( $inv['invoice_number'] ?? '', 40 ),
			'issued'    => $issued,
			'due'       => $due,
			'reference' => self::t( $inv['po_number'] ?? '', 200 ),
			'status'    => self::t( $inv['v3_status'] ?? ( $inv['display_status'] ?? '' ), 40 ),
			'currency'  => '' !== $currency ? $currency : 'USD',
			'billed_to' => $billed,
			'lines'     => $lines,
			'totals'    => $money ? array(
				'subtotal' => round( $sub, 2 ),
				'discount' => $discount ? $discount : null,
				'tax'      => $tax,
				'total'    => $total,
				'paid'     => self::money( $inv['paid'] ?? null ),
				'due'      => self::money( $inv['outstanding'] ?? null ),
			) : null,
			'notes'     => self::t( $inv['notes'] ?? '', 5000 ),
			'terms'     => self::t( $inv['terms'] ?? '', 5000 ),
			'money'     => (bool) $money,
		);
	}

	/* ── The customer's link ────────────────────────────────────────────── */

	/**
	 * Someone who sees a customer's invoice may need its customer link, to paste into a message.
	 * The REST layer asks only for someone the invoice opens for WITH its name and address (the
	 * same rule as the card). Read live each time, stored nowhere, logged as a reason code only;
	 * the page only ever COPIES it (never opens it, never puts it in a link).
	 *
	 * Only a whole customer share link (https://…freshbooks.com/#/link/…) is accepted, never one
	 * built from a bare token, never a staff view. A draft has none: the billing system answers
	 * 422 until the invoice is sent, and Charts never marks one sent (no writes toward billing),
	 * so a draft is not asked.
	 */
	public static function share_link_from( $data ) {
		$cands = array();
		if ( is_array( $data ) ) {
			$cands[] = $data['response']['result']['share_link']['share_link'] ?? null;
			$cands[] = $data['response']['result']['share_link'] ?? null;
			$cands[] = $data['share_link']['share_link'] ?? null;
			$cands[] = $data['share_link'] ?? null;
		}
		foreach ( $cands as $c ) {
			if ( ! is_string( $c ) ) {
				continue;
			}
			$c = trim( $c );
			// The token is token characters only (no brackets, braces, slashes or spaces), so a
			// redacted link or anything with a path is refused rather than copied.
			if ( preg_match( '~^https://([a-z0-9-]+\.)*freshbooks\.com/#/link/[A-Za-z0-9._\~-]+$~iD', $c ) ) {
				return $c;
			}
		}
		return '';
	}

	/** Not sent yet, so no customer link (and asking could mark it sent). Fails closed: any status field saying draft, created or 1 counts. */
	public static function is_draft( array $inv ) {
		foreach ( array( $inv['v3_status'] ?? '', $inv['display_status'] ?? '', $inv['status'] ?? '' ) as $s ) {
			$s = strtolower( trim( is_scalar( $s ) ? (string) $s : '' ) );
			if ( 'draft' === $s || 'created' === $s || '1' === $s ) {
				return true;
			}
		}
		return false;
	}

	/** { ok, link } or { ok:false, why: bad_number|no_billing|not_found|billing_error|draft|no_link|ambiguous }. */
	public static function link_for( $no ) {
		$all = self::matches( $no, true );
		if ( ! $all ) {
			return array( 'ok' => false, 'why' => self::$why ? self::$why : 'not_found' );
		}
		if ( count( $all ) > 1 ) {   // never pick one of two customers' invoices.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'zorderz charts invoice link: ambiguous' );
			}
			return array( 'ok' => false, 'why' => 'ambiguous' );
		}
		$inv = $all[0];
		if ( self::is_draft( $inv ) ) {
			return array( 'ok' => false, 'why' => 'draft' );
		}
		$id = (int) ( $inv['invoiceid'] ?? ( $inv['id'] ?? 0 ) );
		if ( $id <= 0 ) {
			return array( 'ok' => false, 'why' => 'not_found' );
		}
		try {
			if ( is_callable( self::$share ) ) {
				$res = call_user_func( self::$share, $id );
			} else {
				$get = self::getter();
				if ( ! $get ) {
					return array( 'ok' => false, 'why' => 'no_billing' );
				}
				$res = call_user_func(
					$get,
					'https://api.freshbooks.com/accounting/account/' . rawurlencode( self::account_id() )
					. '/invoices/invoices/' . $id . '/share_link?share_method=share_link'
				);
			}
		} catch ( \Throwable $e ) {
			$why = false !== strpos( $e->getMessage(), 'HTTP 422' ) ? 'draft' : 'billing_error';   // 422: still a draft.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'zorderz charts invoice link: ' . $why );
			}
			return array( 'ok' => false, 'why' => $why );
		}
		$link = self::share_link_from( $res );
		if ( '' === $link ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'zorderz charts invoice link: no_link' );
			}
			return array( 'ok' => false, 'why' => 'no_link' );
		}
		return array( 'ok' => true, 'link' => $link );
	}

	/** What the card carries: the invoice, the company header and logo, or why there is none. */
	public static function for_card( $no, $money ) {
		$inv = self::fetch( $no );
		if ( ! $inv ) {
			return array( 'ok' => false, 'why' => self::$why ? self::$why : 'not_found', 'logo' => self::logo() );
		}
		$doc             = self::shape( $inv, $money );
		$doc['ok']       = true;
		$doc['draft']    = self::is_draft( $inv );   // a draft has no customer link yet.
		$doc['business'] = self::business();
		$doc['logo']     = self::logo();
		return $doc;
	}
}
