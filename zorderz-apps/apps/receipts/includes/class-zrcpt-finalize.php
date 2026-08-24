<?php
/**
 * ZRCPT_Finalize — the idempotent, sentinel-based finalize pipeline for the
 * customer-facing receipt HTML, plus the receipt-scoped wp_kses allowlist.
 *
 * A receipt is an Ai-authored standalone HTML page stored in `_receipt_html` and
 * served RAW on a public token URL. This class owns three concerns that all touch
 * that stored HTML, kept together and (deliberately) free of hard WordPress deps
 * so each can be exercised by a no-WP harness:
 *
 *   1. FINALIZE (S5-01/S5-02). A single idempotent transform that STRIPS any
 *      prior sentinel-wrapped appended sections and RE-APPENDS fresh ones, so a
 *      re-generate / photo-remove / reorder converges to the same bytes. Two
 *      sections ship: a full-size print/email-durable photo gallery, and a
 *      proof-of-payment panel.
 *
 *   2. THE PAYMENT-CLAIM GATE (S5-02, honesty-critical). The panel reads
 *      "Payment in Full" ONLY when the system of record backs it — resolved
 *      through a conservative per-source matrix (every resolved source must be
 *      paid; a void reads unpaid) AND ratified by ZDZ_Answer_Authority::
 *      payment_claimed(). Fail-safe: with no authority, or any doubt, the panel
 *      renders a neutral "Your Invoice" with NO claim. A false "paid" is a lie.
 *
 *   3. THE wp_kses ALLOWLIST (S5-14). A curated allowlist applied at APPROVE time
 *      (see ZRCPT_Receipt::ajax_approve_receipt) so a compromised or prompt-
 *      injected bot output cannot store active content on a public URL even if a
 *      reviewer clicks through. A legitimate gallery/paid-panel receipt round-
 *      trips unchanged; <script> and every on* handler are stripped.
 *
 * GENERALIZATION: mechanism only. Sentinels, ordering, escaping, the QR-free
 * link+number panel, the kses allowlist, and the "every source must confirm"
 * matrix are [CORE]. Section wording and styling resolve through a neutral
 * document-conventions filter ([IDENTITY->document-conventions], Core default).
 * The provider paid/void status STRINGS are provider mapping
 * ([IDENTITY->connections+mappings]); the mechanism "a payment claim is server-
 * verified and every source must confirm" is Core. No company / person / product
 * / provider / amount is named or baked here.
 *
 * @since   3.11.0
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRCPT_Finalize {

	/* ── Idempotency sentinels (HTML comments; inert, preserved across kses) ── */
	const FS_GALLERY_START = '<!--ZRCPT:FS_GALLERY:START-->';
	const FS_GALLERY_END   = '<!--ZRCPT:FS_GALLERY:END-->';
	const PAID_INV_START   = '<!--ZRCPT:PAID_INV:START-->';
	const PAID_INV_END     = '<!--ZRCPT:PAID_INV:END-->';

	/**
	 * THE idempotent finalize. Strip every prior appended section, then re-append
	 * the paid panel and the full-size gallery (in that order, before </body> when
	 * present). Running it twice yields identical bytes.
	 *
	 * @param string $html The receipt HTML (fresh bot output, or stored HTML).
	 * @param array  $ctx  {
	 *   @type string[] $photo_urls       Ordered install-photo URLs for the gallery.
	 *   @type string   $invoice_url      Customer-facing invoice link (may carry a
	 *                                     #/link/ fragment — escaped with esc_attr).
	 *   @type string[] $invoice_numbers  Source invoice number(s) to show.
	 *   @type array    $payment_sources  Per-source SoR facts (see resolve_payment()).
	 * }
	 * @return string Finalized HTML.
	 */
	public static function finalize_html( string $html, array $ctx ): string {
		$html = self::strip_section( $html, self::FS_GALLERY_START, self::FS_GALLERY_END );
		$html = self::strip_section( $html, self::PAID_INV_START, self::PAID_INV_END );

		$paid    = self::build_paid_panel( $ctx );
		$gallery = self::build_fullsize_gallery( (array) ( $ctx['photo_urls'] ?? array() ), $ctx );

		$append = $paid . $gallery;
		if ( '' === $append ) {
			return $html;
		}
		return self::insert_before_body_close( $html, $append );
	}

	/**
	 * Refresh ONLY the full-size gallery (strip + re-append) with a new URL set,
	 * leaving the paid panel and everything else intact. Used by photo remove /
	 * reorder, which change the photo set but not the payment facts.
	 *
	 * @param string   $html       Stored receipt HTML.
	 * @param string[] $photo_urls The kept/reordered photo URLs, in order.
	 * @return string
	 */
	public static function refresh_fullsize_gallery( string $html, array $photo_urls ): string {
		$html    = self::strip_section( $html, self::FS_GALLERY_START, self::FS_GALLERY_END );
		$gallery = self::build_fullsize_gallery( $photo_urls, array() );
		if ( '' === $gallery ) {
			return $html;
		}
		return self::insert_before_body_close( $html, $gallery );
	}

	/* ───────────────────────────── Sections ──────────────────────────────── */

	/**
	 * S5-01 — the full-size gallery: one full-width photo per row, in the given
	 * order, every URL esc_url()-escaped. Empty in => ''. Wrapped in sentinels.
	 */
	public static function build_fullsize_gallery( array $photo_urls, array $ctx = array() ): string {
		$items = '';
		foreach ( $photo_urls as $u ) {
			$eu = self::esc_url( (string) $u );
			if ( '' === $eu ) {
				continue; // a URL esc_url refuses (non-http, javascript:) is dropped, never emitted
			}
			$items .= '<div class="zrcpt-fs-item" style="margin:0 0 12px;">'
				. '<img src="' . $eu . '" alt="" loading="lazy" style="width:100%;height:auto;display:block;margin:0 auto;" />'
				. '</div>';
		}
		if ( '' === $items ) {
			return '';
		}
		$label = self::section_text( 'fullsize_gallery_label', 'Photos (full size)', $ctx );
		$bg    = self::section_text( 'fullsize_gallery_bg', '#f4f4f4', $ctx );

		return self::FS_GALLERY_START
			. '<section class="zrcpt-fs-gallery" style="background:' . self::esc_attr( $bg ) . ';padding:20px;margin-top:24px;">'
			. '<h2 style="font-size:15px;margin:0 0 12px;text-align:center;">' . self::esc_html( $label ) . '</h2>'
			. $items
			. '</section>'
			. self::FS_GALLERY_END;
	}

	/**
	 * S5-02 — the proof-of-payment panel. The heading asserts "Payment in Full"
	 * ONLY when the payment-claim gate confirms it; otherwise a neutral heading
	 * with NO claim. Renders the invoice link as selectable plaintext plus an
	 * "open online" button (href escaped with esc_ATTR — NOT esc_url — so a
	 * #/link/ fragment survives) and the invoice number(s). No QR script (a
	 * bundled client script cannot survive the kses allowlist and would break the
	 * self-contained public page); the link + number are the durable proof.
	 */
	public static function build_paid_panel( array $ctx ): string {
		$link    = trim( (string) ( $ctx['invoice_url'] ?? '' ) );
		$numbers = array();
		foreach ( (array) ( $ctx['invoice_numbers'] ?? array() ) as $n ) {
			$n = trim( (string) $n );
			if ( '' !== $n ) {
				$numbers[] = $n;
			}
		}
		$numbers = array_values( array_unique( $numbers ) );

		// The panel exists to make payment proof reachable from paper. With no
		// link AND no number there is nothing to show — omit it (the receipt
		// still stands). An internal staff-only #/invoice/ URL is not customer-
		// facing, so it is not treated as a usable link.
		$has_link = ( '' !== $link && false === stripos( $link, '#/invoice/' ) );
		if ( ! $has_link && empty( $numbers ) ) {
			return '';
		}

		$claim_paid = self::claim_paid( $ctx );

		$heading = $claim_paid
			? self::section_text( 'paid_invoice_heading', 'Paid Invoice — Payment in Full', $ctx )
			: self::section_text( 'unpaid_invoice_heading', 'Your Invoice', $ctx );

		$body = '<h2 style="font-size:16px;margin:0 0 10px;">' . self::esc_html( $heading ) . '</h2>';

		if ( ! empty( $numbers ) ) {
			$label = ( count( $numbers ) > 1 )
				? self::section_text( 'invoice_numbers_label', 'Invoice numbers', $ctx )
				: self::section_text( 'invoice_number_label', 'Invoice number', $ctx );
			$body .= '<p style="margin:0 0 8px;">' . self::esc_html( $label ) . ': '
				. self::esc_html( implode( ', ', $numbers ) ) . '</p>';
		}

		if ( $has_link ) {
			// Selectable plaintext link + an "open online" button. The href uses
			// esc_attr (NOT esc_url) so the FreshBooks share fragment (#/link/…)
			// is preserved verbatim — an esc_url pass would strip it and the
			// button would 404 for the homeowner.
			$href = self::esc_attr( $link );
			$open = self::section_text( 'open_invoice_label', 'Open invoice online', $ctx );
			$body .= '<p style="margin:0 0 6px;word-break:break-all;font-size:12px;color:#555;">' . self::esc_html( $link ) . '</p>';
			$body .= '<p style="margin:0;"><a href="' . $href . '" target="_blank" rel="noopener nofollow" '
				. 'style="display:inline-block;padding:8px 14px;background:#1E4D6E;color:#fff;text-decoration:none;border-radius:4px;">'
				. self::esc_html( $open ) . '</a></p>';
		}

		return self::PAID_INV_START
			. '<section class="zrcpt-paid-panel" style="border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:24px;">'
			. $body
			. '</section>'
			. self::PAID_INV_END;
	}

	/* ─────────────────────── Payment-claim resolution ────────────────────── */

	/**
	 * The HONEST paid decision. Two gates in series, both fail-safe to "not paid":
	 *
	 *   (1) The conservative per-source matrix (resolve_payment): paid iff at least
	 *       one source resolved AND every resolved source is paid (a void reads
	 *       unpaid, never "zero outstanding => paid").
	 *   (2) ZDZ_Answer_Authority::payment_claimed() ratifies the CLAIM against the
	 *       system-of-record facts. Without the authority present, we NEVER assert
	 *       paid (INV-12 for money) — the panel falls back to "Your Invoice".
	 *
	 * @return bool True only when a "Payment in Full" statement is backed.
	 */
	public static function claim_paid( array $ctx ): bool {
		$sources = (array) ( $ctx['payment_sources'] ?? array() );
		if ( empty( $sources ) ) {
			return false; // no SoR facts => no claim
		}
		$pay = self::resolve_payment( $sources );

		// Build the amount facts (SoR-backed) for the authority's amount check.
		$amount_due  = 0.0;
		$amount_paid = 0.0;
		$have_amounts = false;
		foreach ( $sources as $s ) {
			if ( isset( $s['amount'] ) && is_numeric( $s['amount'] ) ) {
				$amount_due += (float) $s['amount'];
				$have_amounts = true;
			}
			if ( isset( $s['paid'] ) && is_numeric( $s['paid'] ) ) {
				$amount_paid += (float) $s['paid'];
			}
		}

		$claim_ctx = array(
			// Only offer the 'paid' outcome to the authority when our matrix
			// already concluded paid; otherwise the authority sees no paid claim.
			'sor_outcomes' => $pay['paid'] ? array( 'paid' ) : array(),
			'sor_backed'   => ( $pay['paid'] && $have_amounts ),
		);
		if ( $have_amounts ) {
			$claim_ctx['amount_paid'] = $amount_paid;
			$claim_ctx['amount_due']  = $amount_due;
		}

		if ( class_exists( 'ZDZ_Answer_Authority' ) && method_exists( 'ZDZ_Answer_Authority', 'payment_claimed' ) ) {
			return (bool) ZDZ_Answer_Authority::payment_claimed( $claim_ctx );
		}
		// Fail-safe: the receipt paid panel NEVER asserts payment without the gate.
		return false;
	}

	/**
	 * Normalize a set of billing sources to a single {resolved, paid} verdict.
	 *
	 * Rule (S5-02): paid iff >= 1 source resolved AND every resolved source is
	 * paid. An UNRESOLVED source (provider unreachable / no status) blocks the
	 * claim. A VOID ($0 outstanding on a non-zero amount) reads UNPAID — the old
	 * "zero outstanding => paid" heuristic is deliberately dropped.
	 *
	 * @param array $sources Each: ['status'=>string, 'amount'=>?float,
	 *                        'outstanding'=>?float, 'paid'=>?float, 'number'=>?string].
	 * @return array{resolved:bool,paid:bool,source_count:int}
	 */
	public static function resolve_payment( array $sources ): array {
		$paid_states = self::paid_status_set();
		$void_states = self::void_status_set();

		$any_resolved = false;
		$all_paid     = true;

		foreach ( $sources as $s ) {
			$status = strtolower( trim( (string) ( $s['status'] ?? '' ) ) );
			if ( '' === $status ) {
				$all_paid = false; // unresolved source can't confirm payment
				continue;
			}
			$any_resolved = true;

			$is_void = in_array( $status, $void_states, true );
			// Defensive void detection: $0 outstanding on a non-zero total that is
			// NOT explicitly a paid status also reads void/unpaid.
			if ( ! $is_void
				&& isset( $s['outstanding'], $s['amount'] )
				&& is_numeric( $s['outstanding'] ) && is_numeric( $s['amount'] )
				&& (float) $s['amount'] > 0.0
				&& 0.0 === (float) $s['outstanding']
				&& ! in_array( $status, $paid_states, true ) ) {
				$is_void = true;
			}

			$is_paid = in_array( $status, $paid_states, true ) && ! $is_void;
			if ( ! $is_paid ) {
				$all_paid = false;
			}
		}

		return array(
			'resolved'     => $any_resolved,
			'paid'         => ( $any_resolved && $all_paid ),
			'source_count' => count( $sources ),
		);
	}

	/**
	 * The provider statuses that mean "paid". [IDENTITY->connections/mappings]:
	 * the STRINGS are provider vocabulary, so they resolve through a filter with a
	 * conservative Core default; the "every source must confirm" MECHANISM is Core.
	 */
	public static function paid_status_set(): array {
		$default = array( 'paid', 'autopaid' );
		if ( function_exists( 'apply_filters' ) ) {
			$v = apply_filters( 'zrcpt_payment_paid_statuses', $default );
			if ( is_array( $v ) && ! empty( $v ) ) {
				return array_values( array_map( static function ( $s ) {
					return strtolower( trim( (string) $s ) );
				}, $v ) );
			}
		}
		return $default;
	}

	/** The provider statuses that mean "void" (explicitly unpaid). Mapping. */
	public static function void_status_set(): array {
		$default = array( 'void', 'voided' );
		if ( function_exists( 'apply_filters' ) ) {
			$v = apply_filters( 'zrcpt_payment_void_statuses', $default );
			if ( is_array( $v ) && ! empty( $v ) ) {
				return array_values( array_map( static function ( $s ) {
					return strtolower( trim( (string) $s ) );
				}, $v ) );
			}
		}
		return $default;
	}

	/* ─────────────────────────── wp_kses (S5-14) ─────────────────────────── */

	/**
	 * The curated allowlist for a model-authored receipt page: structural, text,
	 * table, list, gallery/panel and inline-SVG markup a receipt legitimately uses
	 * for print/email — with inline `style` allowed (receipts lay themselves out
	 * inline). NO <script>, <iframe>, <object>, <embed>, <form>, and NO on* handler
	 * (an unlisted attribute is dropped by kses). Filterable, but the strip in
	 * sanitize() is a non-negotiable floor regardless of the allowlist.
	 */
	public static function kses_allowed(): array {
		$common = array(
			'class' => true, 'id' => true, 'style' => true, 'title' => true,
			'dir'   => true, 'lang' => true, 'role' => true,
		);
		$tags = array(
			'html'   => array( 'lang' => true ),
			'head'   => array(),
			'body'   => $common,
			'meta'   => array( 'charset' => true, 'name' => true, 'content' => true ),
			'title'  => array(),
			'style'  => array( 'type' => true, 'media' => true ),
			'div'    => $common, 'section' => $common, 'article' => $common,
			'header' => $common, 'footer' => $common, 'main' => $common,
			'aside'  => $common, 'nav' => $common,
			'h1' => $common, 'h2' => $common, 'h3' => $common,
			'h4' => $common, 'h5' => $common, 'h6' => $common,
			'p' => $common, 'span' => $common, 'strong' => $common, 'b' => $common,
			'em' => $common, 'i' => $common, 'u' => $common, 'small' => $common,
			'sub' => $common, 'sup' => $common, 'mark' => $common,
			'br' => array(), 'hr' => $common, 'wbr' => array(),
			'a'   => $common + array( 'href' => true, 'target' => true, 'rel' => true ),
			'img' => $common + array(
				'src' => true, 'alt' => true, 'width' => true, 'height' => true,
				'loading' => true, 'srcset' => true, 'sizes' => true,
			),
			'ul' => $common, 'ol' => $common + array( 'start' => true, 'type' => true ),
			'li' => $common, 'dl' => $common, 'dt' => $common, 'dd' => $common,
			'table' => $common, 'thead' => $common, 'tbody' => $common, 'tfoot' => $common,
			'tr' => $common,
			'td' => $common + array( 'colspan' => true, 'rowspan' => true ),
			'th' => $common + array( 'colspan' => true, 'rowspan' => true, 'scope' => true ),
			'caption' => $common, 'colgroup' => $common, 'col' => $common + array( 'span' => true ),
			'figure' => $common, 'figcaption' => $common, 'blockquote' => $common,
			'pre' => $common, 'code' => $common,
			// Inline SVG (icons, marks) — a fixed geometric subset, no scripting.
			'svg'  => $common + array( 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'xmlns' => true ),
			'path' => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'g'    => $common, 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true ),
			'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true ),
			'line' => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true ),
			'polyline' => array( 'points' => true, 'fill' => true, 'stroke' => true ),
			'polygon'  => array( 'points' => true, 'fill' => true, 'stroke' => true ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zrcpt_receipt_kses_allowed', $tags );
			if ( is_array( $f ) && ! empty( $f ) ) {
				return $f;
			}
		}
		return $tags;
	}

	/**
	 * Sanitize model-authored receipt HTML for safe public serving. Prefers
	 * wp_kses (a real allowlist) AND always runs a conservative strip of active
	 * content, so even without wp_kses (or a kses gap) a <script>/on*=/javascript:
	 * payload can't reach the stored public page. Idempotent: a clean receipt
	 * round-trips unchanged. The ZRCPT sentinel comments are preserved across the
	 * kses pass (kses drops comments) so the finalize strip keeps working.
	 *
	 * @param string $html Stored (approved) receipt HTML.
	 * @return string
	 */
	public static function sanitize( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		// Protect what must survive VERBATIM across the passes: the idempotency
		// sentinels (kses drops comments) AND every <style> block (a receipt is a
		// full HTML document, and kses' CSS text handling would mangle selectors
		// like "a > b"). CSS can't execute script in a modern browser, so a
		// verbatim <style> is safe; the XSS vectors live in <script>/on*=/js: URLs,
		// stripped below.
		list( $html, $restore ) = self::protect_blocks( $html );

		if ( function_exists( 'wp_kses' ) ) {
			$out = wp_kses( $html, self::kses_allowed() );
			if ( is_string( $out ) && '' !== $out ) {
				$html = $out;
			}
		}
		$html = self::strip_active_content( $html );

		// Restore the protected blocks last (so the strip never scanned their CSS).
		return strtr( $html, $restore );
	}

	/* ────────────────────────────── Internals ────────────────────────────── */

	/** Remove every $start..$end block (idempotent; tolerant of repeats). */
	private static function strip_section( string $html, string $start, string $end ): string {
		$pattern = '/' . preg_quote( $start, '/' ) . '.*?' . preg_quote( $end, '/' ) . '/s';
		$out = preg_replace( $pattern, '', $html );
		return is_string( $out ) ? $out : $html;
	}

	/** Insert $append immediately before the first </body>, else at the end. */
	private static function insert_before_body_close( string $html, string $append ): string {
		$pos = stripos( $html, '</body>' );
		if ( false !== $pos ) {
			return substr( $html, 0, $pos ) . $append . substr( $html, $pos );
		}
		return $html . $append;
	}

	/**
	 * Replace the ZRCPT sentinel comments AND every <style> block with inert
	 * placeholders so they survive the kses / strip passes verbatim, and return
	 * the protected HTML plus a restore map (placeholder => original).
	 */
	private static function protect_blocks( string $html ): array {
		$restore = array();

		// Sentinels (fixed strings).
		$sent = array(
			'%%ZRCPT_FSG_S%%' => self::FS_GALLERY_START,
			'%%ZRCPT_FSG_E%%' => self::FS_GALLERY_END,
			'%%ZRCPT_PIV_S%%' => self::PAID_INV_START,
			'%%ZRCPT_PIV_E%%' => self::PAID_INV_END,
		);
		foreach ( $sent as $ph => $orig ) {
			$html = str_replace( $orig, $ph, $html );
			$restore[ $ph ] = $orig;
		}

		// <style>…</style> blocks (variable content) → indexed placeholders.
		$i = 0;
		$html = preg_replace_callback(
			'#<style\b[^>]*>.*?</style>#is',
			function ( $m ) use ( &$restore, &$i ) {
				$ph            = '%%ZRCPT_STYLE_' . ( $i++ ) . '%%';
				$restore[ $ph ] = $m[0];
				return $ph;
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			$html = '';
		}
		return array( $html, $restore );
	}

	/**
	 * Conservative active-content strip: <script> (paired + stray), and every on*
	 * event-handler attribute, and javascript: in href/src. Leaves ordinary
	 * content untouched, so a clean receipt is unchanged.
	 */
	private static function strip_active_content( string $html ): string {
		$rules = array(
			'#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is' => '',
			'#<\s*/?\s*script\b[^>]*>#is'                 => '',
			'#\son[a-z]+\s*=\s*"[^"]*"#i'                 => '',
			"#\son[a-z]+\s*=\s*'[^']*'#i"                 => '',
			'#\son[a-z]+\s*=\s*[^\s>]+#i'                 => '',
			'#(href|src)\s*=\s*"\s*javascript:[^"]*"#i'   => '$1="#"',
			"#(href|src)\s*=\s*'\s*javascript:[^']*'#i"   => '$1="#"',
		);
		foreach ( $rules as $pat => $rep ) {
			$out = preg_replace( $pat, $rep, $html );
			if ( is_string( $out ) ) {
				$html = $out;
			}
		}
		return $html;
	}

	/** Resolve a section label/style through a neutral filter; Core default given. */
	private static function section_text( string $key, string $default, array $ctx = array() ): string {
		if ( function_exists( 'apply_filters' ) ) {
			$v = apply_filters( 'zrcpt_receipt_section_text', $default, $key, $ctx );
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
		}
		return $default;
	}

	private static function esc_url( string $u ): string {
		$u = trim( $u );
		if ( '' === $u ) {
			return '';
		}
		if ( function_exists( 'esc_url' ) ) {
			return (string) esc_url( $u );
		}
		// No-WP fallback: http(s) only; encode the few chars that break an attr.
		if ( ! preg_match( '#^https?://#i', $u ) ) {
			return '';
		}
		return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), $u );
	}

	private static function esc_attr( string $s ): string {
		if ( function_exists( 'esc_attr' ) ) {
			return (string) esc_attr( $s );
		}
		return str_replace(
			array( '&', '"', "'", '<', '>' ),
			array( '&amp;', '&quot;', '&#039;', '&lt;', '&gt;' ),
			$s
		);
	}

	private static function esc_html( string $s ): string {
		if ( function_exists( 'esc_html' ) ) {
			return (string) esc_html( $s );
		}
		return str_replace(
			array( '&', '"', "'", '<', '>' ),
			array( '&amp;', '&quot;', '&#039;', '&lt;', '&gt;' ),
			$s
		);
	}
}
