<?php
/**
 * ZANA_Invoice_Doc — an invoice or estimate opened in the chat, as the customer got it, read live by number from the
 * billing connector (ZDZ_Core_FreshBooks::get_document_by_number: exact only, never a deleted document). Nothing is
 * stored on the site.
 *
 *   1. who may open it (may_open): the company view opens every document; a salesperson only a document the ledger
 *      names under one of their own codes, checked again on the document the billing system returns (the same id, and
 *      its location line still carrying their code). The Charts app's own setting for the person narrows it when Charts
 *      is installed. Never the shared device, Demo Mode or a Privacy View account. A document that is not theirs and
 *      one that does not exist get the same answer.
 *   2. what the page draws (shape): plain text only; without the money right every rate, line total and total is left
 *      out. Access codes are cut from every text field.
 *   3. the customer's link (link): for an invoice that is not a draft (asking for a draft's link could mark it sent),
 *      exactly one live invoice under the number, and only for someone who also sees its amounts. Read live, stored
 *      nowhere, logged as a reason code only.
 *
 * Contract: ZANA_Invoice_Doc::open( $uid, $kind, $number ), ZANA_Invoice_Doc::link( $uid, $number ) (REST routes in
 * ZANA_Ledger_Admin).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Invoice_Doc {

	/** Seconds per billing call. */
	const TIMEOUT = 8;
	/** The company header (not personal) is kept 12 hours. */
	const BUSINESS_TTL = 43200;
	/** Openings per person per window. */
	const RATE_MAX    = 40;
	const RATE_WINDOW = 600;

	/** Injected in tests: callable( kind, number, all ) => document(s); may throw. callable( id ) => share answer. */
	public static $fetcher = null;
	public static $sharer  = null;
	/** Injected in tests: kind => number => [ { id, rep } ]; callable( uid ) => the Charts breadth or null. */
	public static $ledger  = null;
	public static $breadth = null;

	/** Why the last read gave nothing: not_found | billing_error | no_billing. */
	public static $why = '';

	public static function refused(): string {
		return __( 'That one can\'t be opened here.', 'zorderz' );
	}

	/** May this person open this document? { ok, money, scope: all|own, id?, codes? }. */
	public static function may_open( array $ctx, string $kind, string $number ): array {
		$no = array( 'ok' => false, 'money' => false, 'scope' => '' );
		$ctx = ZANA_Ledger_Access::normalize( $ctx );
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $number ) ) {
			return $no;
		}
		$breadth = self::charts_breadth( $ctx['user_id'] );
		if ( 'none' === $breadth ) {
			return $no;
		}
		$money = ZANA_Ledger_Access::may( $ctx, 'company' );
		if ( ZANA_Ledger_Access::may( $ctx, 'doc_all' ) && 'own' !== $breadth ) {
			return array( 'ok' => true, 'money' => $money, 'scope' => 'all' );
		}
		if ( ! ZANA_Ledger_Access::may( $ctx, 'doc_own' ) ) {
			return $no;
		}
		$row = self::ledger_rep( $kind, $number );
		if ( ! $row || ! ZANA_Ledger_Access::rep_matches( $row['rep'], $ctx['codes'] ) ) {
			return $no; // fail closed: no row, no code, someone else's
		}
		return array( 'ok' => true, 'money' => $money, 'scope' => 'own', 'id' => $row['id'], 'codes' => $ctx['codes'] );
	}

	/** The Charts app's invoice-card breadth for a person ('all' | 'own' | 'none'), or null when Charts isn't installed. */
	public static function charts_breadth( int $uid ): ?string {
		if ( is_callable( self::$breadth ) ) {
			return call_user_func( self::$breadth, $uid );
		}
		if ( ! class_exists( 'ZCH_Access' ) || ! method_exists( 'ZCH_Access', 'invoice_breadth' ) ) {
			return null;
		}
		try {
			$b = (string) ZCH_Access::invoice_breadth( $uid );
		} catch ( \Throwable $e ) {
			return 'none';
		}
		return in_array( $b, array( 'all', 'own' ), true ) ? $b : 'none';
	}

	/** The ledger's live row for a number: { id, rep }, or null (none, or two live documents under one number). */
	public static function ledger_rep( string $kind, string $number ): ?array {
		$n = ltrim( trim( $number ), '0' );
		if ( '' === $n || ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $n ) ) {
			return null;
		}
		if ( is_array( self::$ledger ) ) {
			$rows = (array) ( self::$ledger[ $kind ][ $n ] ?? array() );
		} else {
			global $wpdb;
			$est  = 'estimate' === $kind;
			$t    = ZANA_Ledger_Store::table( $est ? 'estimates' : 'invoices' );
			$col  = $est ? 'estimate_number' : 'invoice_number';
			$idc  = $est ? 'estimate_id' : 'invoice_id';
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$idc} AS id, rep_code AS rep FROM {$t} WHERE TRIM(LEADING '0' FROM {$col}) = %s AND vis_state <> 1 LIMIT 3", $n ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed names.
		}
		if ( 1 !== count( $rows ) ) {
			return null;
		}
		return array( 'id' => (int) $rows[0]['id'], 'rep' => (string) $rows[0]['rep'] );
	}

	/**
	 * The second lock for "own", on the document the billing system returned: the very document the ledger approved (the
	 * same id), and its own location line still carrying one of the person's codes.
	 */
	public static function still_theirs( array $d, string $kind, array $may ): bool {
		$fid = (int) ( 'estimate' === $kind ? ( $d['estimateid'] ?? $d['id'] ?? 0 ) : ( $d['invoiceid'] ?? $d['id'] ?? 0 ) );
		if ( $fid <= 0 || $fid !== (int) ( $may['id'] ?? 0 ) ) {
			return false;
		}
		$loc = ZANA_Ledger_Enrich::location( (array) ( $d['lines'] ?? array() ), ZANA_Ledger_Rules::get() );
		return ZANA_Ledger_Access::rep_matches( $loc['rep'], (array) ( $may['codes'] ?? array() ) );
	}

	/** At most RATE_MAX openings per person per RATE_WINDOW seconds. */
	public static function rate_ok( int $uid ): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return true;
		}
		$k = 'zana_invdoc_rate_' . $uid;
		$n = (int) get_transient( $k );
		if ( $n >= self::RATE_MAX ) {
			return false;
		}
		set_transient( $k, $n + 1, self::RATE_WINDOW );
		return true;
	}

	protected static function client() {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return null;
		}
		try {
			$fb = new ZDZ_Core_FreshBooks();
			return ( method_exists( $fb, 'get_document_by_number' ) && $fb->is_configured() ) ? $fb : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** The document(s) for a number, or null / [] with self::$why set. */
	protected static function read( string $kind, string $number, bool $all ) {
		self::$why = '';
		try {
			if ( is_callable( self::$fetcher ) ) {
				$doc = call_user_func( self::$fetcher, $kind, $number, $all );
			} else {
				$fb = self::client();
				if ( ! $fb ) {
					self::$why = 'no_billing';
					return $all ? array() : null;
				}
				$doc = $fb->get_document_by_number( $kind, $number, self::TIMEOUT, $all );
			}
		} catch ( \Throwable $e ) {
			self::$why = 'billing_error';
			error_log( 'Zorderz Analytics invoice view: the billing read failed (' . $kind . ').' );
			return $all ? array() : null;
		}
		if ( $all ) {
			$doc = is_array( $doc ) ? array_values( $doc ) : array();
			self::$why = $doc ? '' : 'not_found';
			return $doc;
		}
		if ( ! is_array( $doc ) ) {
			self::$why = 'not_found';
			return null;
		}
		return $doc;
	}

	/** Name and address lines for the header: the Business Profile's, else the site's name. */
	public static function business(): array {
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		if ( '' === $name && function_exists( 'get_bloginfo' ) ) {
			$name = (string) get_bloginfo( 'name' );
		}
		$lines = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$phone = (string) ZDZ_Business_Profile::get( 'contact.phone', '' );
			$addr  = method_exists( 'ZDZ_Business_Profile', 'address_line' ) ? (string) ZDZ_Business_Profile::address_line() : '';
			$lines = array_values( array_filter( array( self::t( $phone, 40 ), self::t( $addr, 200 ) ), 'strlen' ) );
		}
		$out = array( 'name' => self::t( $name, 120 ), 'lines' => $lines );
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'zana_invoice_business', $out ) : $out;
	}

	/** The logo at the top left (https only): the Business Profile's wide logo for a light background. */
	public static function logo(): string {
		$url = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'logo' ) ) {
			$l   = ZDZ_Business_Profile::logo( 'wide', 'light' );
			$url = is_array( $l ) ? (string) ( $l['url'] ?? '' ) : (string) $l;
		}
		$url = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zana_invoice_logo', $url ) : $url;
		return preg_match( '#^https://#i', $url ) ? $url : '';
	}

	/* ─────────────────────────── shaping (pure) ─────────────────────────── */

	protected static function t( $s, int $max ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = trim( str_replace( "\r\n", "\n", $s ) );
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'cut' ) && '' !== $s ) {
			$s = (string) ZDZ_Sealed::cut( $s );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	protected static function money( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? null;
		}
		return is_numeric( $v ) ? round( (float) $v, 2 ) : null;
	}

	protected static function date10( $s ): string {
		$s = (string) $s;
		return preg_match( '/^\d{4}-\d{2}-\d{2}/', $s ) ? substr( $s, 0, 10 ) : '';
	}

	/** A billing document -> what the page draws (plain text). $money false: no rate, line total or total. */
	public static function shape( array $d, string $kind, bool $money ): array {
		$est    = 'estimate' === $kind;
		$org    = self::t( $d['organization'] ?? '', 200 );
		$person = self::t( trim( ( $d['fname'] ?? '' ) . ' ' . ( $d['lname'] ?? '' ) ), 200 );
		$place  = trim( self::t( $d['city'] ?? '', 120 ) . ' ' . self::t( $d['province'] ?? '', 60 ) );
		$code   = self::t( $d['code'] ?? '', 20 );
		$billed = array_values( array_filter( array(
			'' !== $org ? $org : $person,
			self::t( $d['street'] ?? '', 200 ),
			self::t( $d['street2'] ?? '', 200 ),
			trim( $place . ( '' !== $code ? '  ' . $code : '' ) ),
			self::t( $d['country'] ?? '', 60 ),
		), 'strlen' ) );
		$lines = array();
		$sub   = 0.0;
		foreach ( array_slice( (array) ( $d['lines'] ?? array() ), 0, 300 ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$amt     = self::money( $ln['amount'] ?? null );
			$sub    += (float) $amt;
			$lines[] = array(
				'name'        => self::t( $ln['name'] ?? '', 1000 ),
				'description' => self::t( $ln['description'] ?? '', 5000 ),
				'rate'        => $money ? self::money( $ln['unit_cost'] ?? null ) : null,
				'qty'         => is_numeric( $ln['qty'] ?? null ) ? 0 + $ln['qty'] : null,
				'amount'      => $money ? $amt : null,
			);
		}
		$total    = self::money( $d['amount'] ?? null );
		$discount = self::money( $d['discount_total'] ?? null );
		$tax      = null !== $total ? round( $total - $sub + (float) $discount, 2 ) : null;
		if ( null !== $tax && abs( $tax ) < 0.005 ) {
			$tax = 0.0;
		}
		$issued = self::date10( $d['create_date'] ?? '' );
		$due    = $est ? '' : self::date10( $d['due_date'] ?? '' );
		if ( ! $est && '' === $due && '' !== $issued && is_numeric( $d['due_offset_days'] ?? null ) ) {
			$due = gmdate( 'Y-m-d', strtotime( $issued . ' 12:00:00 UTC +' . (int) $d['due_offset_days'] . ' days' ) );
		}
		$cur = is_array( $d['amount'] ?? null ) ? ( $d['amount']['code'] ?? '' ) : ( $d['currency_code'] ?? '' );
		return array(
			'kind'      => $est ? 'estimate' : 'invoice',
			'number'    => self::t( $d[ $est ? 'estimate_number' : 'invoice_number' ] ?? '', 40 ),
			'issued'    => $issued,
			'due'       => $due,
			'reference' => self::t( $d['po_number'] ?? '', 200 ),
			'status'    => self::t( $d['v3_status'] ?? ( $d['ui_status'] ?? ( $d['display_status'] ?? '' ) ), 40 ),
			'currency'  => self::t( $cur, 8 ),
			'billed_to' => $billed,
			'lines'     => $lines,
			'totals'    => $money ? array(
				'subtotal' => round( $sub, 2 ),
				'discount' => $discount ? $discount : null,
				'tax'      => $tax,
				'total'    => $total,
				'paid'     => $est ? null : self::money( $d['paid'] ?? null ),
				'due'      => $est ? null : self::money( $d['outstanding'] ?? null ),
			) : null,
			'notes'     => self::t( $d['notes'] ?? '', 5000 ),
			'terms'     => self::t( $d['terms'] ?? '', 5000 ),
			'money'     => $money,
		);
	}

	/** Not sent yet: no customer link. Fail closed: any status saying so, or no status at all. */
	public static function is_draft( array $d ): bool {
		$seen = false;
		foreach ( array( $d['v3_status'] ?? '', $d['display_status'] ?? '', $d['status'] ?? '' ) as $s ) {
			$s = strtolower( trim( is_scalar( $s ) ? (string) $s : '' ) );
			if ( '' !== $s ) {
				$seen = true;
			}
			if ( 'draft' === $s || 'created' === $s || '1' === $s ) {
				return true;
			}
		}
		return ! $seen;
	}

	/** A whole customer share link from the billing system's answer, or ''. Never built from a bare token. */
	public static function share_link_from( $data ): string {
		$cands = array();
		if ( is_array( $data ) ) {
			$cands[] = $data['response']['result']['share_link']['share_link'] ?? null;
			$cands[] = $data['response']['result']['share_link'] ?? null;
			$cands[] = $data['share_link']['share_link'] ?? null;
			$cands[] = $data['share_link'] ?? null;
		}
		foreach ( $cands as $c ) {
			if ( is_string( $c ) && preg_match( '~^https://([a-z0-9-]+\.)*freshbooks\.com/#/link/[A-Za-z0-9._\~-]+$~iD', trim( $c ) ) ) {
				return trim( $c );
			}
		}
		return '';
	}

	/* ─────────────────────────── the entry points ─────────────────────────── */

	/** Open a document: { ok: true, doc } or { ok: false, code, message }. */
	public static function open( int $uid, string $kind, string $number ): array {
		$kind   = 'estimate' === $kind ? 'estimate' : 'invoice';
		$number = trim( $number );
		$may    = self::may_open( ZANA_Ledger_Access::context( $uid ), $kind, $number );
		if ( ! $may['ok'] ) {
			return array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
		}
		if ( ! self::rate_ok( $uid ) ) {
			return array( 'ok' => false, 'code' => 'rate', 'message' => __( 'That\'s a lot of documents in a few minutes; try again shortly.', 'zorderz' ) );
		}
		$d = self::read( $kind, $number, false );
		if ( ! $d ) {
			if ( 'not_found' === self::$why ) {
				return 'all' === $may['scope']
					? array( 'ok' => false, 'code' => 'not_found', 'message' => __( 'The billing system has no document with that number.', 'zorderz' ) )
					: array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
			}
			return array( 'ok' => false, 'code' => '' !== self::$why ? self::$why : 'billing_error', 'message' => __( 'The billing system didn\'t answer just now.', 'zorderz' ) );
		}
		if ( 'own' === $may['scope'] && ! self::still_theirs( $d, $kind, $may ) ) {
			return array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
		}
		$doc              = self::shape( $d, $kind, $may['money'] );
		$doc['business']  = self::business();
		$doc['logo']      = self::logo();
		$doc['draft']     = self::is_draft( $d );
		$doc['can_share'] = 'invoice' === $kind && ! $doc['draft'] && $may['money'];
		return array( 'ok' => true, 'doc' => $doc );
	}

	/** The customer's link for one invoice, for the page to copy: { ok: true, link } or { ok: false, code, message }. */
	public static function link( int $uid, string $number ): array {
		$number = trim( $number );
		$may    = self::may_open( ZANA_Ledger_Access::context( $uid ), 'invoice', $number );
		if ( ! $may['ok'] || ! $may['money'] ) {
			return array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
		}
		if ( ! self::rate_ok( $uid ) ) {
			return array( 'ok' => false, 'code' => 'rate', 'message' => __( 'That\'s a lot of documents in a few minutes; try again shortly.', 'zorderz' ) );
		}
		$no  = function ( $why ) {
			error_log( 'Zorderz Analytics invoice link: ' . $why );
			$words = array(
				'draft'      => __( 'This invoice is still a draft, so it has no customer link yet.', 'zorderz' ),
				'ambiguous'  => __( 'The billing system has more than one invoice with this number, so no link was copied.', 'zorderz' ),
				'not_found'  => __( 'The billing system has no invoice with that number.', 'zorderz' ),
				'no_billing' => __( 'The billing system isn\'t connected on this site.', 'zorderz' ),
			);
			return array( 'ok' => false, 'code' => $why, 'message' => $words[ $why ] ?? __( 'The billing system didn\'t give a customer link for this invoice just now. Try again in a minute.', 'zorderz' ) );
		};
		$all = self::read( 'invoice', $number, true );
		if ( ! $all ) {
			if ( 'not_found' === self::$why ) {
				return 'all' === $may['scope'] ? $no( 'not_found' ) : array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
			}
			return $no( '' !== self::$why ? self::$why : 'billing_error' );
		}
		if ( count( $all ) > 1 ) {
			return $no( 'ambiguous' );
		}
		$d = $all[0];
		if ( 'own' === $may['scope'] && ! self::still_theirs( $d, 'invoice', $may ) ) {
			return array( 'ok' => false, 'code' => 'refused', 'message' => self::refused() );
		}
		if ( self::is_draft( $d ) ) {
			return $no( 'draft' );
		}
		$id = (int) ( $d['invoiceid'] ?? ( $d['id'] ?? 0 ) );
		try {
			if ( is_callable( self::$sharer ) ) {
				$res = call_user_func( self::$sharer, $id );
			} else {
				$fb  = self::client();
				$res = $fb ? $fb->get_invoice_share_link( $id, self::TIMEOUT ) : null;
			}
		} catch ( \Throwable $e ) {
			return $no( false !== strpos( $e->getMessage(), 'HTTP 422' ) ? 'draft' : 'billing_error' );
		}
		$link = self::share_link_from( $res );
		return '' === $link ? $no( 'no_link' ) : array( 'ok' => true, 'link' => $link );
	}
}
