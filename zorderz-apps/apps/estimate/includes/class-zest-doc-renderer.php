<?php
/**
 * ZEST_Doc_Renderer — the shared, FreshBooks-parity document renderer (Phase 1).
 *
 * One renderer for estimates and (later) invoices. It takes a NORMALIZED document
 * array and emits a printable HTML page whose layout mirrors the FreshBooks
 * estimate/invoice a migrating business already knows: logo + payment-method badges
 * top-left, company block top-right, steel-blue section labels, a
 * Description/Rate/Qty/Line-Total table with sub-descriptions, a totals stack
 * (Estimate Total (USD) for estimates; Total + Amount Paid + Amount Due (USD) for
 * invoices), Notes and Terms.
 *
 * The company header is driven entirely by ZDZ_Business_Profile, so it is tenant
 * neutral. render_html() itself is pure (no WordPress calls beyond an esc fallback)
 * so it can be unit-rendered off-platform. from_estimate_row()/import_estimate()
 * are the WordPress-side glue over the zest_estimates table.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Doc_Renderer {

	/* ────────────────────────────── helpers ─────────────────────────────── */

	private static function e( $s ) {
		return function_exists( 'esc_html' )
			? esc_html( (string) $s )
			: htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	/** Money like FreshBooks: "$1,080.00", "−$175.00", or (dollar=false) "1,080.00". */
	private static function money( $v, $dollar = true ) {
		$v   = (float) $v;
		$neg = $v < 0;
		$s   = number_format( abs( $v ), 2 );
		if ( $dollar ) {
			$s = '$' . $s;
		}
		if ( $neg ) {
			$s = "\u{2212}" . $s; // U+2212 MINUS SIGN, matching the source docs
		}
		return $s;
	}

	private static function qty( $q ) {
		$q = (float) $q;
		return ( floor( $q ) === $q ) ? number_format( $q, 0 ) : rtrim( rtrim( number_format( $q, 2 ), '0' ), '.' );
	}

	/** Infer a line's kind when the stored line does not carry one. */
	private static function line_kind( array $li ) {
		if ( ! empty( $li['kind'] ) ) {
			return (string) $li['kind'];
		}
		$desc = strtolower( trim( (string) ( $li['description'] ?? '' ) ) );
		$id   = strtolower( (string) ( $li['id'] ?? '' ) );
		$price = (float) ( $li['unit_price'] ?? $li['rate'] ?? 0 );
		if ( 'location' === $id || strpos( $desc, 'location' ) === 0 ) {
			return 'context';
		}
		if ( strpos( $desc, 'tax and' ) === 0 || strpos( $desc, 'tax &' ) === 0 ) {
			return 'context';
		}
		if ( strpos( $desc, 'discount' ) === 0 || $price < 0 ) {
			return 'discount';
		}
		return 'item';
	}

	/** Normalize a raw items array: fill kind, rate, line_total. */
	private static function normalize_items( array $items ) {
		$out = array();
		foreach ( $items as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$rate = (float) ( $li['unit_price'] ?? $li['rate'] ?? 0 );
			$q    = (float) ( $li['quantity'] ?? $li['qty'] ?? 1 );
			$lt   = array_key_exists( 'line_total', $li ) ? (float) $li['line_total'] : ( $rate * $q );
			$sub  = (string) ( $li['sub_description'] ?? $li['sub'] ?? '' );
			if ( '' === $sub && ! empty( $li['attribution'] ) ) {
				$sub = (string) $li['attribution'];
			}
			$out[] = array(
				'kind'        => self::line_kind( $li ),
				'description' => (string) ( $li['description'] ?? '' ),
				'sub'         => $sub,
				'rate'        => $rate,
				'qty'         => $q,
				'line_total'  => $lt,
			);
		}
		return $out;
	}

	/** The single totals engine — subtotal, header discount, tax/shipping, total. */
	public static function compute_totals( array $items, $discount_type = 'none', $discount_value = 0, $tax = 0, $shipping = 0 ) {
		$subtotal = 0.0;
		foreach ( $items as $li ) {
			$subtotal += (float) $li['line_total'];
		}
		$discount_amount = 0.0;
		$discount_label  = '';
		if ( 'percent' === $discount_type && (float) $discount_value > 0 ) {
			$discount_amount = $subtotal * ( (float) $discount_value / 100 );
			$discount_label  = rtrim( rtrim( number_format( (float) $discount_value, 2 ), '0' ), '.' ) . '% Discount';
		} elseif ( 'amount' === $discount_type && (float) $discount_value != 0 ) {
			$discount_amount = (float) $discount_value;
			$discount_label  = 'Discount';
		}
		$total = $subtotal - $discount_amount + (float) $tax + (float) $shipping;
		return array(
			'subtotal'        => $subtotal,
			'discount_label'  => $discount_label,
			'discount_amount' => $discount_amount,
			'tax'             => (float) $tax,
			'shipping'        => (float) $shipping,
			'total'           => $total,
		);
	}

	/* ─────────────────────────── company header ─────────────────────────── */

	/** Company header from the Business Profile. Tenant-neutral; empty fields drop out. */
	public static function company_block() {
		$name = 'Company';
		$phone = '';
		$addr_lines = array();
		$tagline = '';
		$logo_html = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$name    = (string) ZDZ_Business_Profile::name();
			$phone   = (string) ZDZ_Business_Profile::get( 'contact.phone', '' );
			$tagline = (string) ZDZ_Business_Profile::get( 'identity.tagline', '' );
			$addr    = (array) ZDZ_Business_Profile::get( 'contact.address', array() );
			$street  = trim( (string) ( $addr['street'] ?? $addr['line1'] ?? '' ) );
			$city    = trim( (string) ( $addr['city'] ?? $addr['locality'] ?? '' ) );
			$state   = trim( (string) ( $addr['state'] ?? $addr['region'] ?? '' ) );
			$zip     = trim( (string) ( $addr['zip'] ?? $addr['postcode'] ?? $addr['postal_code'] ?? '' ) );
			if ( '' !== $street ) {
				$addr_lines[] = $street;
			}
			$cityline = trim( $city . ( '' !== $state ? ', ' . $state : '' ) . ( '' !== $zip ? '  ' . $zip : '' ), ' ,' );
			if ( '' !== $cityline ) {
				$addr_lines[] = $cityline;
			}
			if ( empty( $addr_lines ) ) {
				$al = (string) ZDZ_Business_Profile::address_line();
				if ( '' !== $al ) {
					$addr_lines[] = $al;
				}
			}
			if ( is_callable( array( 'ZDZ_Business_Profile', 'logo_html' ) ) ) {
				// White document background → 'light' background → dark-ink logo.
				$logo_html = (string) ZDZ_Business_Profile::logo_html( 'wide', 'light', 46 );
			}
		}
		$pay = apply_filters( 'zdz_doc_payment_methods', array( 'PO', 'Check', 'ACH', 'Card' ) );
		return array(
			'name'       => $name,
			'phone'      => $phone,
			'addr_lines' => $addr_lines,
			'tagline'    => $tagline,
			'logo_html'  => $logo_html,
			'payment'    => is_array( $pay ) ? $pay : array(),
		);
	}

	/* ─────────────────────────── the renderer ───────────────────────────── */

	/**
	 * Render a normalized document to a full, printable HTML page.
	 *
	 * $doc keys: kind ('estimate'|'invoice'), number, date, due_date, reference,
	 * customer{lines:[]}, items[], discount_type, discount_value, tax, shipping,
	 * amount_paid, notes, terms, company{} (optional; defaults to company_block()).
	 */
	public static function render_html( array $doc ) {
		$kind    = ( ( $doc['kind'] ?? 'estimate' ) === 'invoice' ) ? 'invoice' : 'estimate';
		$company = $doc['company'] ?? ( function_exists( 'add_filter' ) ? self::company_block() : array( 'name' => 'Company', 'payment' => array() ) );
		$items   = self::normalize_items( $doc['items'] ?? array() );
		$t       = self::compute_totals( $items, $doc['discount_type'] ?? 'none', $doc['discount_value'] ?? 0, $doc['tax'] ?? 0, $doc['shipping'] ?? 0 );

		$e = array( __CLASS__, 'e' );
		$m = array( __CLASS__, 'money' );

		// ── company header ──
		$logo = ! empty( $company['logo_html'] )
			? $company['logo_html']
			: '<span class="word">' . self::e( $company['name'] ?? 'Company' ) . '</span>';
		$tagline = ! empty( $company['tagline'] ) ? '<div class="tagline">' . self::e( $company['tagline'] ) . '</div>' : '';
		$pills = '';
		foreach ( (array) ( $company['payment'] ?? array() ) as $p ) {
			$pills .= '<span class="pill">' . self::e( $p ) . '</span> ';
		}
		$pay_row = $pills ? '<div class="pay"><b>Preferred Payment Methods:</b> ' . $pills . '</div>' : '';
		$co_addr = '';
		foreach ( (array) ( $company['addr_lines'] ?? array() ) as $l ) {
			$co_addr .= '<br>' . self::e( $l );
		}
		$co_phone = ! empty( $company['phone'] ) ? '<br>' . self::e( $company['phone'] ) : '';

		// ── meta columns ──
		$cust_lines = '';
		foreach ( (array) ( $doc['customer']['lines'] ?? array() ) as $l ) {
			if ( '' !== trim( (string) $l ) ) {
				$cust_lines .= self::e( $l ) . '<br>';
			}
		}
		$ref = (string) ( $doc['reference'] ?? '' );
		if ( 'estimate' === $kind ) {
			$col3 = self::lab( 'Estimate Number' ) . self::val( $doc['number'] ?? '' );
			if ( '' !== $ref ) {
				$col3 .= '<div class="gap"></div>' . self::lab( 'Reference' ) . self::val( $ref );
			}
			$meta = '<div class="col"><div class="lab">Prepared For</div><div class="val">' . $cust_lines . '</div></div>'
				. '<div class="col">' . self::lab( 'Estimate Date' ) . self::val( $doc['date'] ?? '' ) . '</div>'
				. '<div class="col">' . $col3 . '</div>';
		} else {
			$col2 = self::lab( 'Date of Issue' ) . self::val( $doc['date'] ?? '' )
				. '<div class="gap"></div>' . self::lab( 'Due Date' ) . self::val( $doc['due_date'] ?? '' );
			$col3 = self::lab( 'Invoice Number' ) . self::val( $doc['number'] ?? '' )
				. '<div class="gap"></div>' . self::lab( 'Reference' ) . self::val( '' !== $ref ? $ref : ' ' );
			$paid = (float) ( $doc['amount_paid'] ?? 0 );
			$due  = $t['total'] - $paid;
			$col4 = '<div class="lab">Amount Due (USD)</div><div class="bigdue">' . self::money( $due ) . '</div>';
			$meta = '<div class="col"><div class="lab">Billed To</div><div class="val">' . $cust_lines . '</div></div>'
				. '<div class="col">' . $col2 . '</div>'
				. '<div class="col">' . $col3 . '</div>'
				. '<div class="col amt">' . $col4 . '</div>';
		}

		// ── line rows ──
		$rows = '';
		foreach ( $items as $li ) {
			$sub = '' !== $li['sub'] ? '<div class="isub">' . self::e( $li['sub'] ) . '</div>' : '';
			$rows .= '<tr><td class="desc"><div class="iname">' . self::e( $li['description'] ) . '</div>' . $sub . '</td>'
				. '<td>' . self::money( $li['rate'] ) . '</td>'
				. '<td>' . self::qty( $li['qty'] ) . '</td>'
				. '<td>' . self::money( $li['line_total'] ) . '</td></tr>';
		}

		// ── totals ──
		$tr = '<div class="row"><span class="l">Subtotal</span><span class="r">' . self::money( $t['subtotal'], false ) . '</span></div>';
		if ( '' !== $t['discount_label'] ) {
			$tr .= '<div class="row"><span class="l">' . self::e( $t['discount_label'] ) . '</span><span class="r">' . self::money( -abs( $t['discount_amount'] ), false ) . '</span></div>';
		}
		if ( (float) $t['shipping'] != 0 ) {
			$tr .= '<div class="row"><span class="l">Shipping</span><span class="r">' . self::money( $t['shipping'], false ) . '</span></div>';
		}
		$tr .= '<div class="row"><span class="l">Tax</span><span class="r">' . self::money( $t['tax'], false ) . '</span></div>';
		if ( 'estimate' === $kind ) {
			$tr .= '<div class="row grand"><span class="l">Estimate Total (USD)</span><span class="r">' . self::money( $t['total'] ) . '</span></div>';
		} else {
			$paid = (float) ( $doc['amount_paid'] ?? 0 );
			$tr  .= '<div class="row grand"><span class="l">Total</span><span class="r">' . self::money( $t['total'], false ) . '</span></div>';
			$tr  .= '<div class="row"><span class="l">Amount Paid</span><span class="r">' . self::money( $paid, false ) . '</span></div>';
			$tr  .= '<div class="row grand"><span class="l">Amount Due (USD)</span><span class="r">' . self::money( $t['total'] - $paid ) . '</span></div>';
		}

		// ── notes / terms ──
		$tail = '';
		if ( ! empty( $doc['notes'] ) ) {
			$tail .= '<div class="section"><div class="lab">Notes</div><p>' . self::e( $doc['notes'] ) . '</p></div>';
		}
		if ( ! empty( $doc['terms'] ) ) {
			$tail .= '<div class="section"><div class="lab">Terms</div><p>' . self::e( $doc['terms'] ) . '</p></div>';
		}

		$title = ( 'estimate' === $kind ? 'Estimate' : 'Invoice' ) . ' ' . self::e( $doc['number'] ?? '' );
		$css   = self::css();

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><style>' . $css . '</style></head><body>'
			. '<button class="printbtn" onclick="window.print()">Print / Save as PDF</button>'
			. '<div class="page"><div class="top"><div><div class="logo-row">' . $logo . '</div>' . $tagline . $pay_row . '</div>'
			. '<div class="co"><div class="cn">' . self::e( $company['name'] ?? '' ) . '</div>' . $co_phone . $co_addr . '</div></div>'
			. '<div class="meta">' . $meta . '</div>'
			. '<table class="items"><thead><tr><th class="desc">Description</th><th>Rate</th><th>Qty</th><th>Line Total</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<div class="totals">' . $tr . '</div>' . $tail
			. '</div></body></html>';
	}

	private static function lab( $s ) { return '<div class="lab">' . self::e( $s ) . '</div>'; }
	private static function val( $s ) { return '<div class="val">' . self::e( $s ) . '</div>'; }

	private static function css() {
		return '
*{box-sizing:border-box}
body{font-family:Helvetica,Arial,sans-serif;color:#232a2f;font-size:10.5pt;line-height:1.45;margin:0;background:#f2f4f7}
.page{background:#fff;max-width:820px;margin:24px auto;padding:34px 40px 60px;box-shadow:0 1px 6px rgba(0,0,0,.12)}
.printbtn{position:fixed;top:16px;right:16px;background:#2f6db0;color:#fff;border:0;border-radius:7px;padding:9px 14px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.2)}
.top{display:flex;justify-content:space-between;align-items:flex-start}
.logo-row{display:flex;align-items:center;gap:9px}
.word{font-size:27px;font-weight:800;color:#12233b;letter-spacing:-.3px}
.tagline{font-size:8.5pt;color:#5b6675;margin-top:3px}
.pay{margin-top:9px;font-size:7.5pt;color:#333;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pay b{font-style:italic;font-weight:700}
.pill{border:1px solid #9aa6b2;border-radius:5px;padding:1px 7px;font-weight:700;font-size:7.5pt;color:#33414d}
.co{text-align:right;font-size:10pt;line-height:1.5}
.co .cn{font-weight:700}
.meta{display:flex;gap:20px;margin-top:30px}
.meta .col{flex:1}
.meta .col.amt{text-align:right}
.gap{height:10px}
.lab{color:#4f7d97;font-size:10pt;margin-bottom:2px}
.val{color:#232a2f;font-size:10pt}
.bigdue{font-size:27px;font-weight:700;color:#232a2f;margin-top:1px}
table.items{width:100%;border-collapse:collapse;margin-top:26px}
table.items thead th{color:#4f7d97;font-weight:400;font-size:10pt;text-align:right;padding:0 0 7px;border-bottom:2px solid #3f7286}
table.items thead th.desc{text-align:left}
table.items tbody td{padding:11px 0;border-bottom:1px solid #e4e6e9;vertical-align:top;text-align:right}
table.items tbody td.desc{text-align:left}
.iname{color:#232a2f}
.isub{color:#5a6470;font-size:9pt;margin-top:1px}
.totals{width:52%;margin-left:auto;margin-top:16px}
.totals .row{display:flex;justify-content:space-between;padding:4px 0}
.totals .row.grand{border-top:1px solid #cfd8dd;margin-top:4px;padding-top:9px}
.totals .row.grand .l{color:#4f7d97}
.section{margin-top:30px}
.section .lab{margin-bottom:3px}
.section p{margin:0;max-width:86%}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;max-width:none;padding:0}.printbtn{display:none}@page{size:Letter;margin:16mm}}
';
	}

	/* ─────────────────── WordPress glue over zest_estimates ──────────────── */

	/** Build a normalized $doc from a zest_estimates row (array). */
	public static function from_estimate_row( array $r ) {
		$items = json_decode( (string) ( $r['items_json'] ?? '[]' ), true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$lines = array();
		$push  = function ( $v ) use ( &$lines ) { $v = trim( (string) $v ); if ( '' !== $v ) { $lines[] = $v; } };
		$push( $r['customer_name'] ?? '' );
		$push( $r['customer_org'] ?? '' );
		$push( $r['customer_street'] ?? '' );
		$push( $r['customer_phone'] ?? '' );
		$city = trim( (string) ( $r['customer_city'] ?? '' ) );
		$st   = trim( (string) ( $r['customer_state'] ?? '' ) );
		$zip  = trim( (string) ( $r['customer_zip'] ?? '' ) );
		$cl   = trim( $city . ( '' !== $st ? ', ' . $st : '' ) . ( '' !== $zip ? '  ' . $zip : '' ), ' ,' );
		$push( $cl );

		$date = (string) ( $r['doc_date'] ?? '' );
		if ( '' === $date || '0000-00-00' === $date ) {
			$date = substr( (string) ( $r['created_at'] ?? '' ), 0, 10 );
		}
		$date_disp = $date ? date( 'm/d/Y', strtotime( $date ) ) : '';

		$terms = (string) ( $r['terms'] ?? '' );
		if ( '' === $terms ) {
			$terms = (string) apply_filters( 'zdz_doc_terms', '' );
		}

		return array(
			'kind'           => 'estimate',
			'number'         => (string) ( $r['doc_number'] ?? '' ) ?: (string) ( $r['billing_doc_num'] ?? '' ) ?: (string) ( $r['id'] ?? '' ),
			'date'           => $date_disp,
			'reference'      => (string) ( $r['reference'] ?? '' ),
			'customer'       => array( 'lines' => $lines ),
			'items'          => $items,
			'discount_type'  => (string) ( $r['discount_type'] ?? 'none' ),
			'discount_value' => (float) ( $r['discount_value'] ?? 0 ),
			'tax'            => (float) ( $r['tax_amount'] ?? 0 ),
			'shipping'       => (float) ( $r['shipping_amount'] ?? 0 ),
			'notes'          => (string) ( $r['notes'] ?? '' ),
			'terms'          => $terms,
		);
	}

	/** Render an estimate by id, or '' if not found. */
	public static function render_estimate( $id ) {
		global $wpdb;
		$table = ZEST_DB::estimates_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return '';
		}
		return self::render_html( self::from_estimate_row( $row ) );
	}

	/**
	 * Import a normalized document as an estimate row. Returns the new id (0 on failure).
	 * Generic on purpose: this is the seam the manual PDF-import pipeline (Phase 4) will
	 * feed. Preserves the supplied number/date/totals verbatim.
	 */
	public static function import_estimate( array $doc ) {
		global $wpdb;
		$cust  = (array) ( $doc['customer'] ?? array() );
		$items = (array) ( $doc['items'] ?? array() );
		$item_count = 0;
		foreach ( self::normalize_items( $items ) as $li ) {
			if ( 'item' === $li['kind'] ) {
				$item_count++;
			}
		}
		$date = (string) ( $doc['date'] ?? '' );
		$mysql_date = $date ? date( 'Y-m-d', strtotime( $date ) ) : null;

		$data = array(
			'customer_name'   => (string) ( $cust['name'] ?? '' ),
			'customer_org'    => (string) ( $cust['org'] ?? '' ),
			'customer_email'  => (string) ( $cust['email'] ?? '' ),
			'customer_phone'  => (string) ( $cust['phone'] ?? '' ),
			'customer_street' => (string) ( $cust['street'] ?? '' ),
			'customer_city'   => (string) ( $cust['city'] ?? '' ),
			'customer_state'  => (string) ( $cust['state'] ?? '' ),
			'customer_zip'    => (string) ( $cust['zip'] ?? '' ),
			'salesperson'     => (string) ( $doc['salesperson'] ?? '' ),
			'reference'       => (string) ( $doc['reference'] ?? '' ),
			'notes'           => (string) ( $doc['notes'] ?? '' ),
			'terms'           => (string) ( $doc['terms'] ?? '' ),
			'doc_number'      => (string) ( $doc['number'] ?? '' ),
			'doc_date'        => $mysql_date,
			'discount_type'   => in_array( ( $doc['discount_type'] ?? 'none' ), array( 'none', 'percent', 'amount' ), true ) ? $doc['discount_type'] : 'none',
			'discount_value'  => (float) ( $doc['discount_value'] ?? 0 ),
			'tax_amount'      => (float) ( $doc['tax'] ?? 0 ),
			'shipping_amount' => (float) ( $doc['shipping'] ?? 0 ),
			'items_json'      => wp_json_encode( array_values( $items ) ),
			'rejected_json'   => '[]',
			'input_text'      => (string) ( $doc['source_text'] ?? '' ),
			'item_count'      => $item_count,
			'status'          => 'imported',
			'created_by'      => get_current_user_id(),
			'created_at'      => $mysql_date ? ( $mysql_date . ' 12:00:00' ) : current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( $table = ZEST_DB::estimates_table(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ───────────────────────── invoices (Phase 2) ───────────────────────── */
	// Deliberately scoped to the DOCUMENT + payment tracking — not a CRM, not a
	// store. Customer details live on the document; products/prices come from the
	// Item Engine; "payments" are a manual record of money received elsewhere
	// (check/ACH/PO/etc.). No gateway, no inventory, no WooCommerce.

	/** Build a normalized invoice $doc from a zest_invoices row. */
	public static function from_invoice_row( array $r ) {
		$items = json_decode( (string) ( $r['items_json'] ?? '[]' ), true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$lines = array();
		$push  = function ( $v ) use ( &$lines ) { $v = trim( (string) $v ); if ( '' !== $v ) { $lines[] = $v; } };
		$push( $r['customer_name'] ?? '' );
		$push( $r['customer_org'] ?? '' );
		$push( $r['customer_street'] ?? '' );
		$push( $r['customer_phone'] ?? '' );
		$city = trim( (string) ( $r['customer_city'] ?? '' ) );
		$st   = trim( (string) ( $r['customer_state'] ?? '' ) );
		$zip  = trim( (string) ( $r['customer_zip'] ?? '' ) );
		$cl   = trim( $city . ( '' !== $st ? ', ' . $st : '' ) . ( '' !== $zip ? '  ' . $zip : '' ), ' ,' );
		$push( $cl );
		$fmt = function ( $d ) { $d = (string) $d; return ( $d && '0000-00-00' !== $d ) ? date( 'm/d/Y', strtotime( $d ) ) : ''; };
		$terms = (string) ( $r['terms'] ?? '' );
		if ( '' === $terms ) {
			$terms = (string) apply_filters( 'zdz_doc_terms', '' );
		}
		return array(
			'kind'           => 'invoice',
			'number'         => (string) ( $r['invoice_number'] ?? '' ) ?: (string) ( $r['id'] ?? '' ),
			'date'           => $fmt( $r['doc_date'] ?? ( $r['created_at'] ?? '' ) ),
			'due_date'       => $fmt( $r['due_date'] ?? '' ),
			'reference'      => (string) ( $r['reference'] ?? '' ),
			'customer'       => array( 'lines' => $lines ),
			'items'          => $items,
			'discount_type'  => (string) ( $r['discount_type'] ?? 'none' ),
			'discount_value' => (float) ( $r['discount_value'] ?? 0 ),
			'tax'            => (float) ( $r['tax_amount'] ?? 0 ),
			'shipping'       => (float) ( $r['shipping_amount'] ?? 0 ),
			'amount_paid'    => (float) ( $r['amount_paid'] ?? 0 ),
			'notes'          => (string) ( $r['notes'] ?? '' ),
			'terms'          => $terms,
		);
	}

	/** Render an invoice by id, or '' if not found. */
	public static function render_invoice( $id ) {
		global $wpdb;
		$t   = ZEST_DB::invoices_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return '';
		}
		return self::render_html( self::from_invoice_row( $row ) );
	}

	/** Next invoice number: max( configured cursor, db max + 1, 1001 ). Advances the cursor. */
	public static function next_invoice_number() {
		global $wpdb;
		$t      = ZEST_DB::invoices_table();
		$db_max = (int) $wpdb->get_var( "SELECT MAX(CAST(invoice_number AS UNSIGNED)) FROM {$t}" );
		$cursor = (int) get_option( 'zest_invoice_next', 0 );
		$n      = max( $cursor, $db_max + 1, 1001 );
		update_option( 'zest_invoice_next', $n + 1, false );
		return (string) $n;
	}

	/** Insert an invoice row from a normalized $doc (preserves number/date/totals). Returns id. */
	public static function import_invoice( array $doc ) {
		global $wpdb;
		$cust  = (array) ( $doc['customer'] ?? array() );
		$items = (array) ( $doc['items'] ?? array() );
		$tot   = self::compute_totals( self::normalize_items( $items ), $doc['discount_type'] ?? 'none', $doc['discount_value'] ?? 0, $doc['tax'] ?? 0, $doc['shipping'] ?? 0 );
		$total = (float) $tot['total'];
		$paid  = (float) ( $doc['amount_paid'] ?? 0 );
		$status = ( $paid >= $total && $total > 0 ) ? 'paid' : ( $paid > 0 ? 'partial' : ( ! empty( $doc['status'] ) ? (string) $doc['status'] : 'sent' ) );
		$date       = (string) ( $doc['date'] ?? '' );
		$due        = (string) ( $doc['due_date'] ?? '' );
		$mysql_date = $date ? date( 'Y-m-d', strtotime( $date ) ) : null;
		$mysql_due  = $due ? date( 'Y-m-d', strtotime( $due ) ) : null;
		$number     = (string) ( $doc['number'] ?? '' );

		$data = array(
			'invoice_number'     => $number,
			'source_estimate_id' => (int) ( $doc['source_estimate_id'] ?? 0 ),
			'customer_name'      => (string) ( $cust['name'] ?? '' ),
			'customer_org'       => (string) ( $cust['org'] ?? '' ),
			'customer_email'     => (string) ( $cust['email'] ?? '' ),
			'customer_phone'     => (string) ( $cust['phone'] ?? '' ),
			'customer_street'    => (string) ( $cust['street'] ?? '' ),
			'customer_city'      => (string) ( $cust['city'] ?? '' ),
			'customer_state'     => (string) ( $cust['state'] ?? '' ),
			'customer_zip'       => (string) ( $cust['zip'] ?? '' ),
			'salesperson'        => (string) ( $doc['salesperson'] ?? '' ),
			'reference'          => (string) ( $doc['reference'] ?? '' ),
			'items_json'         => wp_json_encode( array_values( $items ) ),
			'discount_type'      => in_array( ( $doc['discount_type'] ?? 'none' ), array( 'none', 'percent', 'amount' ), true ) ? $doc['discount_type'] : 'none',
			'discount_value'     => (float) ( $doc['discount_value'] ?? 0 ),
			'tax_amount'         => (float) ( $doc['tax'] ?? 0 ),
			'shipping_amount'    => (float) ( $doc['shipping'] ?? 0 ),
			'notes'              => (string) ( $doc['notes'] ?? '' ),
			'terms'              => (string) ( $doc['terms'] ?? '' ),
			'total_amount'       => $total,
			'amount_paid'        => $paid,
			'status'             => $status,
			'doc_date'           => $mysql_date,
			'due_date'           => $mysql_due,
			'created_by'         => get_current_user_id(),
			'created_at'         => $mysql_date ? ( $mysql_date . ' 12:00:00' ) : current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
			'paid_at'            => ( 'paid' === $status ) ? ( $mysql_date ? $mysql_date . ' 12:00:00' : current_time( 'mysql' ) ) : null,
		);
		if ( ! $wpdb->insert( ZEST_DB::invoices_table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		if ( $paid > 0 ) {
			$wpdb->insert( ZEST_DB::payments_table(), array(
				'invoice_id'  => $id,
				'method'      => (string) ( $doc['payment_method'] ?? 'other' ),
				'amount'      => $paid,
				'note'        => 'Imported opening balance',
				'received_at' => $mysql_date,
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			) );
		}
		if ( ctype_digit( $number ) && ( (int) $number + 1 ) > (int) get_option( 'zest_invoice_next', 0 ) ) {
			update_option( 'zest_invoice_next', (int) $number + 1, false );
		}
		return $id;
	}

	/** Convert an estimate into a trackable invoice. Returns the new invoice id (0 on failure). */
	public static function convert_estimate_to_invoice( $estimate_id, array $opts = array() ) {
		global $wpdb;
		$et = ZEST_DB::estimates_table();
		$e  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$et} WHERE id = %d", (int) $estimate_id ), ARRAY_A );
		if ( ! $e ) {
			return 0;
		}
		$doc      = self::from_estimate_row( $e );
		$today    = current_time( 'Y-m-d' );
		$due_days = isset( $opts['due_days'] ) ? (int) $opts['due_days'] : 30;
		$due      = date( 'Y-m-d', strtotime( $today . ' +' . $due_days . ' days' ) );
		$inv_doc  = array(
			'number'             => ! empty( $opts['number'] ) ? (string) $opts['number'] : self::next_invoice_number(),
			'source_estimate_id' => (int) $estimate_id,
			'date'               => $today,
			'due_date'           => $due,
			'reference'          => (string) ( $e['reference'] ?? '' ),
			'salesperson'        => (string) ( $e['salesperson'] ?? '' ),
			'customer'           => array(
				'name'   => $e['customer_name'] ?? '',
				'org'    => $e['customer_org'] ?? '',
				'email'  => $e['customer_email'] ?? '',
				'phone'  => $e['customer_phone'] ?? '',
				'street' => $e['customer_street'] ?? '',
				'city'   => $e['customer_city'] ?? '',
				'state'  => $e['customer_state'] ?? '',
				'zip'    => $e['customer_zip'] ?? '',
			),
			'items'              => $doc['items'],
			'discount_type'      => $doc['discount_type'],
			'discount_value'     => $doc['discount_value'],
			'tax'                => $doc['tax'],
			'shipping'           => $doc['shipping'],
			'notes'              => $doc['notes'],
			'terms'              => $doc['terms'],
			'amount_paid'        => 0,
			'status'             => 'sent',
		);
		$inv_id = self::import_invoice( $inv_doc );
		if ( ! $inv_id ) {
			return 0;
		}
		$wpdb->update( $et, array( 'converted_invoice_id' => $inv_id, 'status' => 'converted' ), array( 'id' => (int) $estimate_id ) );
		return $inv_id;
	}

	/** Record a payment against an invoice and recompute paid/status/due. Returns a summary. */
	public static function record_payment( $invoice_id, $amount, $method = 'other', $note = '', $received_at = '' ) {
		global $wpdb;
		$it  = ZEST_DB::invoices_table();
		$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$it} WHERE id = %d", (int) $invoice_id ), ARRAY_A );
		if ( ! $inv ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		$amount = round( (float) $amount, 2 );
		if ( 0.0 !== $amount ) {
			$wpdb->insert( ZEST_DB::payments_table(), array(
				'invoice_id'  => (int) $invoice_id,
				'method'      => in_array( $method, array( 'po', 'check', 'ach', 'card', 'cash', 'zelle', 'other' ), true ) ? $method : 'other',
				'amount'      => $amount,
				'note'        => (string) $note,
				'received_at' => $received_at ? date( 'Y-m-d', strtotime( $received_at ) ) : current_time( 'Y-m-d' ),
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			) );
		}
		$pt    = ZEST_DB::payments_table();
		$paid  = round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$pt} WHERE invoice_id = %d", (int) $invoice_id ) ), 2 );
		$total = (float) $inv['total_amount'];
		$status = ( $paid >= $total && $total > 0 ) ? 'paid' : ( $paid > 0 ? 'partial' : ( 'draft' === $inv['status'] ? 'draft' : 'sent' ) );
		$wpdb->update( $it, array(
			'amount_paid' => $paid,
			'status'      => $status,
			'paid_at'     => ( 'paid' === $status ) ? current_time( 'mysql' ) : null,
			'updated_at'  => current_time( 'mysql' ),
		), array( 'id' => (int) $invoice_id ) );
		return array( 'ok' => true, 'invoice_id' => (int) $invoice_id, 'amount_paid' => $paid, 'total' => $total, 'amount_due' => round( $total - $paid, 2 ), 'status' => $status );
	}

	/* ─────────────────── management console (Phase-2 UI) ────────────────── */

	/** Gather estimates + invoices and render the staff console page. */
	public static function render_console() {
		global $wpdb;
		$et   = ZEST_DB::estimates_table();
		$it   = ZEST_DB::invoices_table();
		$ests = $wpdb->get_results( "SELECT * FROM {$et} ORDER BY id DESC LIMIT 300", ARRAY_A ) ?: array();
		$invs = $wpdb->get_results( "SELECT * FROM {$it} ORDER BY id DESC LIMIT 300", ARRAY_A ) ?: array();
		return self::render_console_html( $ests, $invs );
	}

	private static function badge( $status ) {
		$s = strtolower( (string) $status );
		$label = ucfirst( $s );
		return '<span class="badge b-' . self::e( $s ) . '">' . self::e( $label ) . '</span>';
	}

	/** Pure-ish console renderer (WP context injected via $ctx for off-platform tests). */
	public static function render_console_html( array $ests, array $invs, array $ctx = array() ) {
		$company = $ctx['company'] ?? ( function_exists( 'add_filter' ) ? self::company_block() : array( 'name' => 'Company' ) );
		$rest    = $ctx['rest_base'] ?? ( function_exists( 'rest_url' ) ? rest_url( ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) . '/' ) : '/wp-json/zorderz/v1/' );
		$nonce   = $ctx['nonce'] ?? ( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '' );
		$home    = $ctx['home'] ?? ( function_exists( 'home_url' ) ? home_url( '/' ) : '/' );

		$fmt = function ( $d ) { $d = (string) $d; return ( $d && '0000-00-00' !== $d ) ? date( 'm/d/Y', strtotime( $d ) ) : ''; };
		$cust = function ( $r ) {
			$n = trim( (string) ( $r['customer_name'] ?? '' ) );
			$o = trim( (string) ( $r['customer_org'] ?? '' ) );
			return self::e( $o !== '' ? ( $n !== '' ? $n . ' · ' . $o : $o ) : $n );
		};

		// Estimates rows.
		$erows = '';
		foreach ( $ests as $e ) {
			$items = json_decode( (string) ( $e['items_json'] ?? '[]' ), true );
			$t = self::compute_totals( self::normalize_items( is_array( $items ) ? $items : array() ), $e['discount_type'] ?? 'none', $e['discount_value'] ?? 0, $e['tax_amount'] ?? 0, $e['shipping_amount'] ?? 0 );
			$num = (string) ( $e['doc_number'] ?? '' ) ?: (string) ( $e['billing_doc_num'] ?? '' ) ?: ( '#' . (int) $e['id'] );
			$date = $fmt( $e['doc_date'] ?? ( $e['created_at'] ?? '' ) );
			$conv = (int) ( $e['converted_invoice_id'] ?? 0 );
			$actions = '<a class="btn ghost" href="' . self::e( $home ) . '?zest_doc=' . (int) $e['id'] . '" target="_blank" rel="noopener">View</a>';
			if ( $conv > 0 ) {
				$actions .= ' <a class="lnk" href="' . self::e( $home ) . '?zest_inv=' . $conv . '" target="_blank" rel="noopener">→ Invoice</a>';
			} else {
				$actions .= ' <button class="btn" data-act="convert" data-id="' . (int) $e['id'] . '">Convert to invoice</button>';
			}
			$status = $conv > 0 ? 'converted' : ( (string) ( $e['status'] ?? 'created' ) );
			$erows .= '<tr><td class="mono">' . self::e( $num ) . '</td><td>' . self::e( $date ) . '</td><td>' . $cust( $e ) . '</td><td class="r">' . self::money( $t['total'] ) . '</td><td>' . self::badge( $status ) . '</td><td class="act">' . $actions . '</td></tr>';
		}
		if ( '' === $erows ) {
			$erows = '<tr><td colspan="6" class="empty">No estimates yet.</td></tr>';
		}

		// Invoices rows (+ inline payment form row).
		$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' );
		$irows = '';
		foreach ( $invs as $v ) {
			$total = (float) ( $v['total_amount'] ?? 0 );
			$paid  = (float) ( $v['amount_paid'] ?? 0 );
			$due   = round( $total - $paid, 2 );
			$num   = (string) ( $v['invoice_number'] ?? '' ) ?: ( '#' . (int) $v['id'] );
			$date  = $fmt( $v['doc_date'] ?? ( $v['created_at'] ?? '' ) );
			$status = (string) ( $v['status'] ?? 'draft' );
			if ( 'paid' !== $status && 'void' !== $status && ! empty( $v['due_date'] ) && $v['due_date'] < $today ) {
				$status = 'overdue';
			}
			$actions = '<a class="btn ghost" href="' . self::e( $home ) . '?zest_inv=' . (int) $v['id'] . '" target="_blank" rel="noopener">View</a>';
			if ( 'paid' !== (string) ( $v['status'] ?? '' ) && 'void' !== (string) ( $v['status'] ?? '' ) ) {
				$actions .= ' <button class="btn" data-act="pay" data-id="' . (int) $v['id'] . '">Record payment</button>';
			}
			$irows .= '<tr><td class="mono">' . self::e( $num ) . '</td><td>' . self::e( $date ) . '</td><td>' . $cust( $v ) . '</td><td class="r">' . self::money( $total ) . '</td><td class="r">' . self::money( $paid ) . '</td><td class="r due">' . self::money( $due ) . '</td><td>' . self::badge( $status ) . '</td><td class="act">' . $actions . '</td></tr>';
			$irows .= '<tr class="payrow" data-for="' . (int) $v['id'] . '" style="display:none"><td colspan="8"><div class="payform">'
				. '<span>Record a payment on ' . self::e( $num ) . ':</span> '
				. '<input class="amt" type="number" step="0.01" min="0" placeholder="Amount" value="' . number_format( max( 0, $due ), 2, '.', '' ) . '"> '
				. '<select class="method"><option value="check">Check</option><option value="ach">ACH</option><option value="po">PO</option><option value="card">Card</option><option value="cash">Cash</option><option value="zelle">Zelle</option><option value="other">Other</option></select> '
				. '<input class="note" type="text" placeholder="Note (optional)"> '
				. '<button class="btn primary" data-act="savepay" data-id="' . (int) $v['id'] . '">Save payment</button>'
				. '</div></td></tr>';
		}
		if ( '' === $irows ) {
			$irows = '<tr><td colspan="8" class="empty">No invoices yet.</td></tr>';
		}

		$logo = ! empty( $company['logo_html'] ) ? $company['logo_html'] : '<strong>' . self::e( $company['name'] ?? 'Zorderz' ) . '</strong>';
		$css  = self::console_css();
		$js   = self::console_js( $rest, $nonce );

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Estimates &amp; Invoices</title><style>' . $css . '</style></head><body>'
			. '<header class="bar"><div class="brand">' . $logo . '</div><div class="h1">Estimates &amp; Invoices</div></header>'
			. '<main>'
			. '<div id="msg" class="msg" hidden></div>'
			. '<section class="card"><h2>Estimates</h2><table><thead><tr><th>Number</th><th>Date</th><th>Customer</th><th class="r">Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $erows . '</tbody></table></section>'
			. '<section class="card"><h2>Invoices</h2><table><thead><tr><th>Number</th><th>Date</th><th>Customer</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Due</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $irows . '</tbody></table></section>'
			. '</main><script>' . $js . '</script></body></html>';
	}

	private static function console_css() {
		return '
*{box-sizing:border-box}
body{margin:0;font-family:Helvetica,Arial,sans-serif;color:#232a2f;background:#f2f4f7;font-size:14px}
.bar{display:flex;align-items:center;gap:16px;background:#fff;border-bottom:1px solid #e3e8ef;padding:12px 24px}
.bar .brand{font-size:16px;color:#12233b}
.bar .h1{font-size:15px;font-weight:700;color:#4f7d97}
main{max-width:1040px;margin:22px auto;padding:0 18px}
.card{background:#fff;border:1px solid #e6e9ee;border-radius:10px;padding:16px 18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.card h2{margin:2px 0 12px;font-size:15px;color:#12233b}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#7a8794;font-weight:700;padding:6px 10px;border-bottom:2px solid #eef1f5}
td{padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:middle}
td.r,th.r{text-align:right}
td.due{font-weight:700}
.mono{font-variant-numeric:tabular-nums;color:#12233b;font-weight:600}
.empty{color:#8a94a6;text-align:center;padding:18px}
.badge{display:inline-block;padding:2px 9px;border-radius:11px;font-size:11px;font-weight:700;text-transform:capitalize}
.b-draft{background:#eceff3;color:#5b6675}.b-created{background:#eceff3;color:#5b6675}
.b-sent{background:#e5effb;color:#2563a8}.b-imported{background:#e5effb;color:#2563a8}
.b-accepted{background:#e6f6ec;color:#1b7a44}.b-paid{background:#e6f6ec;color:#1b7a44}
.b-partial{background:#fdf0dd;color:#a9701a}
.b-converted{background:#e3f4f5;color:#1f7a86}
.b-overdue{background:#fbe6e6;color:#b3261e}.b-void{background:#eceff3;color:#8a94a6}
.btn{border:1px solid #c3ccd6;background:#fff;color:#26415a;border-radius:6px;padding:5px 10px;font-size:12.5px;font-weight:600;cursor:pointer}
.btn:hover{background:#f5f8fb}
.btn.ghost{color:#4f7d97}
.btn.primary{background:#2f6db0;border-color:#2f6db0;color:#fff}
.btn:disabled{opacity:.6;cursor:default}
.lnk{color:#1f7a86;font-size:12.5px;text-decoration:none;font-weight:600}
td.act{white-space:nowrap}
.payform{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f7f9fc;border:1px dashed #cfd8e2;border-radius:8px;padding:10px 12px}
.payform input,.payform select{padding:5px 8px;border:1px solid #c3ccd6;border-radius:6px;font-size:13px}
.payform .amt{width:120px}
.msg{margin:0 0 14px;padding:10px 14px;border-radius:8px;font-weight:600}
.msg.ok{background:#e6f6ec;color:#1b7a44}.msg.err{background:#fbe6e6;color:#b3261e}
';
	}

	private static function console_js( $rest, $nonce ) {
		$rest_js  = wp_json_encode( $rest );
		$nonce_js = wp_json_encode( $nonce );
		return 'const REST=' . $rest_js . ',NONCE=' . $nonce_js . ';'
			. 'function msg(t,ok){var m=document.getElementById("msg");m.textContent=t;m.className="msg "+(ok?"ok":"err");m.hidden=false;}'
			. 'async function api(p,b){try{const r=await fetch(REST+p,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":NONCE},body:JSON.stringify(b||{})});const j=await r.json().catch(function(){return null;});return {http:r.status,j:j};}catch(e){return {http:0,j:null};}}'
			. 'document.addEventListener("click",async function(e){'
			. 'var b=e.target.closest("[data-act]");if(!b)return;var act=b.dataset.act,id=b.dataset.id;'
			. 'if(act==="convert"){b.disabled=true;b.textContent="Converting…";var r=await api("estimate/"+id+"/convert",{due_days:30});if(r.j&&r.j.ok){msg("Converted to invoice.",true);location.reload();}else{b.disabled=false;b.textContent="Convert to invoice";msg("Convert failed (HTTP "+r.http+").",false);}}'
			. 'else if(act==="pay"){var row=document.querySelector(\'.payrow[data-for="\'+id+\'"]\');if(row)row.style.display=(row.style.display==="table-row"?"none":"table-row");}'
			. 'else if(act==="savepay"){var row=b.closest(".payrow");var amt=parseFloat(row.querySelector(".amt").value||"0");var method=row.querySelector(".method").value;var note=row.querySelector(".note").value;b.disabled=true;var r=await api("invoice/"+id+"/payment",{amount:amt,method:method,note:note});if(r.j&&r.j.ok){msg("Payment recorded. Status: "+r.j.status+", due "+r.j.amount_due+".",true);location.reload();}else{b.disabled=false;msg("Payment failed (HTTP "+r.http+").",false);}}'
			. '});';
	}
}
