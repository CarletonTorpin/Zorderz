<?php
/**
 * ZAGR_PDF_Render - the agreement, the change order and the signature page as PDFs. [CORE]
 *
 * Draws the template's allowed HTML (h2 h3 p strong ul li table tr th td br) with ZAGR_PDF. A statutory statement
 * written <p class="stat-bold"> is set in 12-point bold. The preview carries a "PREVIEW, NOT SIGNED" line and no
 * signature page; the signed copy ends with the signature page and the record of each step. A template with the card
 * layout ("paper") is drawn by ZAGR_PDF_Paper; a classic one here.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_PDF_Render {

	const BODY = 10.5;

	/** Plain words for each step of the record. */
	const STEP = array(
		'created' => 'Agreement prepared', 'superseded' => 'Replaced by a newer version', 'language' => 'Language of the sale recorded',
		'contractor_signed' => 'Signed for the contractor', 'viewed' => 'Page opened', 'consent_shown' => 'Consent shown',
		'pdf_opened' => 'PDF preview opened', 'consent_given' => 'Agreed to sign electronically', 'code_sent' => 'Code emailed',
		'code_ok' => 'Code accepted', 'code_failed' => 'Wrong code typed', 'signed' => 'Agreement signed by the buyer',
		'notice_acknowledged' => 'Notice of Cancellation signed and dated', 'pdf_sealed' => 'Signed PDF made',
		'copy_received' => 'Signed copy received', 'sign_failed' => 'Signing could not be saved',
		'signed_copy_opened' => 'Signed copy opened', 'cancelled' => 'Cancelled', 'window_closed' => 'Cancellation period ended',
		'change_created' => 'Change order prepared', 'change_contractor_signed' => 'Change order signed for the contractor',
		'change_signed' => 'Change order signed by the buyer', 'link_rotated' => 'Link replaced', 'email_failed' => 'An email could not be sent',
		'email_changed' => 'Buyer\'s email address changed', 'codes_locked' => 'Codes paused after too many wrong codes',
		'codes_limited' => 'Codes held back after many requests', 'codes_ceiling' => 'Codes paused after many wrong codes', 'codes_reset' => 'Codes turned back on', 'session_done' => 'Finished on the page',
		'age_answered' => 'Age question answered', 'copy_reissued' => 'Signed copy made again for the day it was received',
		'signed_on_paper' => 'Recorded as signed on paper', 'paper_undone' => 'Paper signing undone',
		'paper_late_confirmed' => 'Late paper signing confirmed', 'staff_answer' => 'Staff answer recorded',
	);

	/**
	 * a 64-character hash (the record's fingerprint, a SHA-256) as two fixed halves of 32 characters, on two
	 * lines, so it prints the same whatever the hash, in both layouts, at 10 points. A whole hash on one line at 10 points
	 * is up to 356 points wide, and the paper layout's column holds 353: one with no narrow letters ('f') pushed its last
	 * character onto the next line. 32 of the widest characters (178 points) and " (after step 9999)" (76) fit either column.
	 */
	public static function halves( string $hash ): string {
		return strlen( $hash ) > 32 ? substr( $hash, 0, 32 ) . "\n" . substr( $hash, 32 ) : $hash;
	}

	/** Draw a fragment of allowed HTML. */
	public static function html( ZAGR_PDF $pdf, string $html ): void {
		if ( '' === trim( $html ) ) {
			return;
		}
		$dom = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$root = null;
		foreach ( $dom->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				$root = $c;
				break;
			}
		}
		if ( $root ) {
			self::block( $pdf, $root );
		}
	}

	private static function block( ZAGR_PDF $pdf, DOMNode $node ): void {
		foreach ( $node->childNodes as $n ) {
			if ( XML_TEXT_NODE === $n->nodeType ) {
				$t = trim( preg_replace( '/\s+/', ' ', $n->textContent ) );
				if ( '' !== $t ) {
					$pdf->paragraph( $t, array( 'size' => self::BODY ) );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $n->nodeType ) {
				continue;
			}
			$tag = strtolower( $n->nodeName );
			$cls = $n instanceof DOMElement ? ' ' . $n->getAttribute( 'class' ) . ' ' : '  ';
			switch ( $tag ) {
				case 'h2':
					$pdf->space( 6 );
					$pdf->ensure( 40 );
					$pdf->paragraph( self::text( $n ), array( 'size' => 14, 'face' => 'bold' ) );
					$pdf->space( 2 );
					break;
				case 'h3':
					$pdf->space( 3 );
					$pdf->ensure( 30 );
					$pdf->paragraph( self::text( $n ), array( 'size' => 11.5, 'face' => 'bold' ) );
					break;
				case 'p':
					if ( false !== strpos( $cls, ' noc-copy ' ) ) {
						$pdf->add_page(); // each Notice of Cancellation copy on a page of its own, to print and detach
					}
					if ( false !== strpos( $cls, ' stat-bold ' ) ) {
						$pdf->paragraph( self::text( $n, true ), array( 'size' => 12, 'face' => 'bold' ) ); // the statute's 12-point bold
					} elseif ( false !== strpos( $cls, ' stat-12 ' ) ) {
						$pdf->runs( self::inline( $n, 'regular' ), array( 'size' => 12 ) ); // 12-point, not bold (a statutory notice set at that size)
					} elseif ( false !== strpos( $cls, ' todo ' ) ) {
						$pdf->ensure( 70 ); // a box is never split across pages
						$top = $pdf->cursor();
						$pdf->runs( array_merge( array( array( 'TO COME: ', 'bold' ) ), self::inline( $n, 'italic' ) ), array( 'size' => self::BODY, 'x' => ZAGR_PDF::MARGIN + 6, 'width' => $pdf->content_width() - 12 ) );
						$pdf->frame( ZAGR_PDF::MARGIN, $top - 3, $pdf->content_width(), $pdf->cursor() - $top + 4, 0.8 );
						$pdf->space( 3 );
					} else {
						$pdf->runs( self::inline( $n, 'regular' ), array( 'size' => self::BODY ) );
					}
					$pdf->space( 4 );
					break;
				case 'ul':
					foreach ( $n->childNodes as $li ) {
						if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
							$pdf->runs( array_merge( array( array( "\xE2\x80\xA2  ", 'regular' ) ), self::inline( $li, 'regular' ) ), array( 'size' => self::BODY, 'x' => ZAGR_PDF::MARGIN + 10 ) );
						}
					}
					$pdf->space( 4 );
					break;
				case 'table':
					self::table( $pdf, $n );
					break;
				case 'br':
					$pdf->space( self::BODY );
					break;
				case 'section':
				case 'div':
					self::block( $pdf, $n );
					break;
				default:
					$pdf->runs( self::inline( $n, 'regular' ), array( 'size' => self::BODY ) );
			}
		}
	}

	private static function text( DOMNode $n, bool $keep_breaks = false ): string {
		$runs = self::inline( $n, 'regular' );
		$s    = implode( '', array_map( function ( $r ) { return $r[0]; }, $runs ) );
		return $keep_breaks ? $s : str_replace( "\n", ' ', $s );
	}

	/** Inline runs: text, <strong> bold, <br> a line break. */
	private static function inline( DOMNode $node, string $face ): array {
		$out = array();
		foreach ( $node->childNodes as $c ) {
			if ( XML_TEXT_NODE === $c->nodeType ) {
				$t = preg_replace( '/\s+/', ' ', $c->textContent );
				if ( '' !== $t ) {
					$out[] = array( $t, $face );
				}
			} elseif ( XML_ELEMENT_NODE === $c->nodeType ) {
				$tag = strtolower( $c->nodeName );
				if ( 'br' === $tag ) {
					$out[] = array( "\n", $face );
				} else {
					$out = array_merge( $out, self::inline( $c, 'strong' === $tag ? 'bold' : $face ) );
				}
			}
		}
		// trim the paragraph's ends
		if ( $out ) {
			$out[0][0]                   = ltrim( $out[0][0], ' ' );
			$out[ count( $out ) - 1 ][0] = rtrim( $out[ count( $out ) - 1 ][0], ' ' );
		}
		return $out;
	}

	private static function table( ZAGR_PDF $pdf, DOMNode $t ): void {
		$rows   = array();
		$header = false;
		$cols   = 0;
		foreach ( $t->getElementsByTagName( 'tr' ) as $ri => $tr ) {
			$row = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
					continue;
				}
				$tag   = strtolower( $cell->nodeName );
				$row[] = self::inline( $cell, 'th' === $tag ? 'bold' : 'regular' );
				if ( 0 === $ri && 'th' === $tag ) {
					$header = true;
				}
			}
			$cols   = max( $cols, count( $row ) );
			$rows[] = $row;
		}
		if ( ! $cols ) {
			return;
		}
		$widths = 3 === $cols ? array( 0.62, 0.13, 0.25 ) : ( 4 === $cols ? array( 0.18, 0.42, 0.14, 0.26 ) : array_fill( 0, $cols, 1 / $cols ) );
		$pdf->table( $rows, $widths, array( 'header' => $header ) );
	}

	/**
	 * The agreement as a PDF. $opts: title, footer, preview (bool), sign (array|null: the signature page's facts),
	 * and $render from ZAGR_Document::agreement().
	 */
	public static function agreement( array $render, array $opts ): string {
		if ( 'paper' === ( $render['layout'] ?? '' ) ) {
			return ZAGR_PDF_Paper::agreement( $render, $opts );   // the card layout
		}
		$pdf = new ZAGR_PDF( (string) $opts['title'], (string) ( $opts['footer'] ?? '' ) );
		if ( ! empty( $opts['test'] ) ) {
			$pdf->paragraph( ZAGR_Strings::t( 'test_banner' ), array( 'size' => 11, 'face' => 'bold' ) );
			$pdf->space( 4 );
		}
		if ( ! empty( $opts['preview'] ) ) {
			$top = $pdf->cursor();
			$pdf->paragraph( ZAGR_Strings::t( 'pdf_preview_banner' ), array( 'size' => 11, 'face' => 'bold', 'x' => ZAGR_PDF::MARGIN + 8, 'width' => $pdf->content_width() - 16 ) );
			$pdf->frame( ZAGR_PDF::MARGIN, $top - 4, $pdf->content_width(), $pdf->cursor() - $top + 6 );
			$pdf->space( 10 );
		}
		$sign = $opts['sign'] ?? null;
		foreach ( $render['sections'] as $name => $html ) {
			if ( 'sign-notice' === $name ) {
				$pdf->ensure( 400 ); // the boxed notice and its signature line stay on one page
				$top = $pdf->cursor();
				self::html( $pdf, $html );
				self::sig_line( $pdf, $sign ? $sign['notice_name'] : '', $sign ? $sign['notice_date_long'] : '' );
				$pdf->frame( ZAGR_PDF::MARGIN - 6, $top - 4, $pdf->content_width() + 12, max( 10, $pdf->cursor() - $top + 4 ) );
				$pdf->space( 10 );
				continue;
			}
			self::html( $pdf, $html );
			if ( 'sign-agreement' === $name ) {
				self::sig_line( $pdf, $sign ? $sign['agreement_name'] : '', $sign ? $sign['signed_date_long'] : '' );
			}
			$pdf->space( 6 );
		}
		if ( $sign ) {
			self::signature_page( $pdf, $sign );
		}
		return $pdf->output();
	}

	/** A change order as a PDF. */
	public static function change( array $render, array $opts ): string {
		if ( 'paper' === ( $render['layout'] ?? '' ) ) {
			return ZAGR_PDF_Paper::change( $render, $opts );
		}
		$pdf = new ZAGR_PDF( (string) $opts['title'], (string) ( $opts['footer'] ?? '' ) );
		if ( ! empty( $opts['test'] ) ) {
			$pdf->paragraph( ZAGR_Strings::t( 'test_banner' ), array( 'size' => 11, 'face' => 'bold' ) );
			$pdf->space( 4 );
		}
		if ( ! empty( $opts['preview'] ) ) {
			$pdf->paragraph( 'PREVIEW, NOT SIGNED: the change order as it will read if you sign today.', array( 'size' => 11, 'face' => 'bold' ) );
			$pdf->space( 8 );
		}
		self::html( $pdf, $render['sections']['change-order'] );
		$sign = $opts['sign'] ?? null;
		self::sig_line( $pdf, $sign ? $sign['agreement_name'] : '', $sign ? $sign['signed_date_long'] : '' );
		if ( $sign ) {
			self::signature_page( $pdf, $sign );
		}
		return $pdf->output();
	}

	private static function sig_line( ZAGR_PDF $pdf, string $name, string $date ): void {
		$pdf->space( 4 );
		if ( '' === $name ) {
			$pdf->paragraph( 'Buyer: ________________________________    Date: ______________', array( 'size' => self::BODY ) );
			return;
		}
		$pdf->runs( array( array( 'Buyer: ', 'regular' ), array( $name, 'italic' ), array( '    Date: ' . $date, 'regular' ) ), array( 'size' => 12 ) );
	}

	/**
	 * The last page: both typed signatures, the business's, and the record. $s: agreement_name, signed_at_long,
	 * signed_date_long, notice_name, notice_date_long, contractor_name, contractor_date_long, render_hash,
	 * template_version, plan_version, cancel_by_long, email_masked, ip, device, events (rows of ZAGR_Events: the
	 * agreement's own steps and the signing session's), head ([ count, hash ]: the record's fingerprint when
	 * the copy was made), kind ('agreement' | 'change').
	 */
	public static function signature_page( ZAGR_PDF $pdf, array $s ): void {
		$pdf->add_page();
		$pdf->paragraph( ZAGR_Strings::t( 'pdf_sig_heading' ), array( 'size' => 15, 'face' => 'bold' ) );
		$pdf->space( 6 );
		$change = 'change' === ( $s['kind'] ?? 'agreement' );
		$pdf->paragraph( $change ? 'The change order, signed by the buyer' : ZAGR_Strings::t( 'pdf_sig_agreement' ), array( 'size' => 11, 'face' => 'bold' ) );
		$pdf->paragraph( $s['agreement_name'], array( 'size' => 18, 'face' => 'italic' ) );
		$pdf->paragraph( 'Signed ' . $s['signed_at_long'], array( 'size' => self::BODY ) );
		$pdf->space( 8 );
		if ( ! $change ) {
			$pdf->paragraph( ZAGR_Strings::t( 'pdf_sig_notice' ), array( 'size' => 11, 'face' => 'bold' ) );
			$pdf->paragraph( $s['notice_name'], array( 'size' => 18, 'face' => 'italic' ) );
			$pdf->paragraph( 'Date: ' . $s['notice_date_long'], array( 'size' => self::BODY ) );
			$pdf->space( 8 );
		}
		$pdf->paragraph( ZAGR_Strings::t( 'pdf_sig_contractor', array( 'company' => (string) ( $s['company'] ?? '' ) ) ), array( 'size' => 11, 'face' => 'bold' ) );
		$pdf->paragraph( $s['contractor_name'] . ', ' . $s['contractor_date_long'], array( 'size' => self::BODY ) );
		$pdf->space( 10 );
		$pdf->paragraph( ZAGR_Strings::t( 'pdf_record' ), array( 'size' => 12, 'face' => 'bold' ) );
		$rows = array(
			array( array( array( 'What', 'bold' ) ), array( array( 'Value', 'bold' ) ) ),
			array( array( array( 'Estimate', 'regular' ) ), array( array( '#' . $s['estimate_number'], 'regular' ) ) ),
			array( array( array( 'SHA-256 of the words on the page when signed', 'regular' ) ), array( array( self::halves( (string) $s['render_hash'] ), 'regular' ) ) ),
			array( array( array( 'SHA-256 of this filled-in copy\'s words', 'regular' ) ), array( array( self::halves( (string) ( $s['copy_hash'] ?? '' ) ), 'regular' ) ) ),
			array( array( array( 'Template version', 'regular' ) ), array( array( $s['template_version'], 'regular' ) ) ),
			array( array( array( 'Payment plan version', 'regular' ) ), array( array( $s['plan_version'], 'regular' ) ) ),
			array( array( array( 'Code emailed to', 'regular' ) ), array( array( $s['email_masked'], 'regular' ) ) ),
			array( array( array( 'Signed from (IP address)', 'regular' ) ), array( array( '' !== $s['ip'] ? $s['ip'] : 'Not known', 'regular' ) ) ),
			array( array( array( 'Device', 'regular' ) ), array( array( '' !== $s['device'] ? $s['device'] : 'Not known', 'regular' ) ) ),
		);
		if ( ! $change ) {
			if ( ! empty( $s['age_line'] ) ) {   // the buyer's answer, in the copy
				$rows[] = array( array( array( ZAGR_Strings::t( 'pdf_age_label' ), 'regular' ) ), array( array( (string) $s['age_line'], 'regular' ) ) );
			}
			if ( ! empty( $s['pack_label'] ) ) {   // the legal rules the period was worked out under
				$rows[] = array( array( array( ZAGR_Strings::t( 'pdf_pack_label' ), 'regular' ) ), array( array( (string) $s['pack_label'], 'regular' ) ) );
			}
			if ( ! empty( $s['window_line'] ) ) {
				$rows[] = array( array( array( ZAGR_Strings::t( 'pdf_window_label' ), 'regular' ) ), array( array( (string) $s['window_line'], 'regular' ) ) );
			}
			$rows[] = array( array( array( 'Cancellation period ends', 'regular' ) ), array( array( 'Midnight on ' . $s['cancel_by_long'], 'regular' ) ) );
		}
		if ( ! empty( $s['head'][1] ) ) {
			$rows[] = array( array( array( ZAGR_Strings::t( 'pdf_record_fp' ), 'regular' ) ), array( array( self::halves( (string) $s['head'][1] ) . ' (after step ' . (int) $s['head'][0] . ')', 'regular' ) ) );
		}
		// 10 points or more; each hash as two fixed halves (halves())
		$pdf->table( $rows, array( 0.27, 0.73 ), array( 'header' => true, 'size' => 10 ) );
		$pdf->paragraph( ZAGR_Strings::t( 'pdf_steps' ), array( 'size' => 11, 'face' => 'bold' ) );
		$ev = array( array( array( array( '#', 'bold' ) ), array( array( 'Time (UTC)', 'bold' ) ), array( array( 'Step', 'bold' ) ), array( array( 'Fingerprint', 'bold' ) ) ) );
		foreach ( (array) ( $s['events'] ?? array() ) as $e ) {
			$ev[] = array(
				array( array( (string) $e['seq'], 'regular' ) ),
				array( array( substr( (string) $e['at_utc'], 0, 19 ), 'regular' ) ),
				array( array( self::STEP[ $e['type'] ] ?? $e['type'], 'regular' ) ),
				array( array( substr( (string) $e['hash'], 0, 16 ), 'regular' ) ),
			);
		}
		$pdf->table( $ev, array( 0.07, 0.30, 0.41, 0.22 ), array( 'header' => true, 'size' => 10 ) );
		$pdf->paragraph( ZAGR_Strings::t( 'pdf_steps_note', array( 'company' => (string) ( $s['company'] ?? '' ) ) ), array( 'size' => 10 ) );
	}
}
