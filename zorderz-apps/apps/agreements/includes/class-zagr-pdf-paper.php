<?php
/**
 * ZAGR_PDF_Paper - the agreement, the change order and the signature page in the card layout. [CORE]
 *
 * For a template whose manifest says "layout": "paper", drawn with ZAGR_PDF in its paper mode, in the PDF's own
 * Helvetica (nothing embedded but the logo, one small JPEG shared by every page), in the business's brand colours (the
 * Business Profile's ramp):
 *   page one   the logo (or the business's name) and the agreement's title, the stripe, then the template's welcome
 *              (the cards, the numbered steps, the date the buyer signed), and its foot (where a Notice of Cancellation
 *              may be sent, and the help phone) at the foot of page one. Some laws put those on the FIRST PAGE: when
 *              page one is full, its spacing tightens (never its type size) until it all fits there.
 *   later      a running head (the small logo, the title, a thin stripe) and a footer with "Page n of N" (both marked
 *              /Artifact); h2 headings over a rule; cards; tables with a soft header row and a firm total row; a
 *              statutory notice in its box; a new page for the signatures, and one for each Notice of Cancellation copy
 *              (each starting at a dashed edge, to detach); the typed name and date on each signature line (class
 *              sig-slot), or blank lines in the preview.
 *   the copy   then the signature page: both signatures, the contractor's, the record of the signing and its steps.
 * Every word prints at 10 points or more; a statutory 12-point bold statement at 12-point bold.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_PDF_Paper {

	const INK     = array( 17, 20, 23 );
	const MUTE    = array( 74, 83, 92 );
	const LINE    = array( 211, 216, 222 );
	const GREY    = array( 72, 72, 72 );
	const WHITE   = array( 255, 255, 255 );
	const WARN_BG = array( 255, 244, 222 );
	const WARN_FG = array( 107, 63, 0 );
	/** The business's brand colours for the PDF, from the Business Profile's ramp (the platform's default ramp without one). */
	private static $ramp = null;

	/** One shade of the ramp as [ r, g, b ]. */
	public static function shade( string $k ): array {
		if ( null === self::$ramp ) {
			$def  = array( '50' => '#ebf5ff', '200' => '#adcfff', '300' => '#7ab3ff', '400' => '#4796f7', '500' => '#2c5f8a', '600' => '#1e3a5f', '700' => '#162d4a', '800' => '#0f1f35' );
			$have = class_exists( 'ZDZ_Business_Profile' ) ? (array) ZDZ_Business_Profile::get( 'brand.ramp', array() ) : array();
			self::$ramp = array();
			foreach ( $def as $key => $hex ) {
				$v = (string) ( $have[ $key ] ?? '' );
				$v = preg_match( '/^#[0-9A-Fa-f]{6}$/', $v ) ? $v : $hex;
				self::$ramp[ $key ] = array( hexdec( substr( $v, 1, 2 ) ), hexdec( substr( $v, 3, 2 ) ), hexdec( substr( $v, 5, 2 ) ) );
			}
		}
		return self::$ramp[ $k ] ?? array( 30, 58, 95 );
	}

	/** Headings, rules and the card titles. */
	public static function brand(): array {
		return self::shade( '600' );
	}

	/** A soft background. */
	public static function soft(): array {
		return self::shade( '50' );
	}

	/** The welcome's first line. */
	public static function hello(): array {
		return self::shade( '500' );
	}

	/** The five stripe colours, darkest first. */
	public static function stripes(): array {
		return array( self::shade( '700' ), self::shade( '600' ), self::shade( '500' ), self::shade( '400' ), self::shade( '300' ) );
	}

	/** The step circles: dark enough for white numbers. */
	public static function circles(): array {
		return array( self::shade( '800' ), self::shade( '700' ), self::shade( '600' ), self::shade( '500' ), self::shade( '500' ) );
	}

	const BODY  = 10.5;
	const SMALL = 10.0;   // the smallest type anywhere (10-point, the smallest some laws allow in a contract)
	const LEAD  = 1.42;

	/** Vertical spacing, 1.0 normally; page one tightens it when it must (never the type size). */
	private static $pad = 1.0;

	/* ---------------------------------------------------------------- the documents */

	/**
	 * The logo for the PDF: the Business Profile's wide logo for a light page, as JPEG bytes the PDF writer can embed
	 * (DCTDecode). A JPEG on this site is used as it is; a PNG on this site is flattened onto white with GD when the
	 * server has it. Anything else (a remote file, no GD) gives no logo, and the PDF prints the business's name instead.
	 * The filter zagr_pdf_logo_jpeg may supply the bytes. Null when there is none.
	 */
	public static function logo(): ?array {
		$d = (string) apply_filters( 'zagr_pdf_logo_jpeg', '' );
		if ( '' === $d ) {
			$d = self::logo_from_profile();
		}
		$s = '' !== $d ? ZAGR_PDF::jpeg_size( $d ) : null;
		return null === $s ? null : array( 'data' => $d, 'w' => $s['w'], 'h' => $s['h'] );
	}

	/** The profile's wide logo as JPEG bytes, or ''. Only a file under this site's uploads folder is read. */
	private static function logo_from_profile(): string {
		if ( ! class_exists( 'ZDZ_Business_Profile' ) || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$url = (string) ZDZ_Business_Profile::logo( 'wide', 'light' )['url'];
		$up  = wp_upload_dir();
		$base = rtrim( (string) ( $up['baseurl'] ?? '' ), '/' ) . '/';
		if ( '' === $url || 0 !== strpos( $url, $base ) ) {
			return '';
		}
		$rel  = substr( $url, strlen( $base ) );
		$path = realpath( rtrim( (string) $up['basedir'], '/' ) . '/' . $rel );
		$root = realpath( (string) $up['basedir'] );
		if ( false === $path || false === $root || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) || filesize( $path ) > 2097152 ) {
			return '';
		}
		$bytes = (string) file_get_contents( $path );
		if ( "\xFF\xD8" === substr( $bytes, 0, 2 ) ) {
			return $bytes;
		}
		if ( "\x89PNG" !== substr( $bytes, 0, 4 ) || ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return '';
		}
		$im = @imagecreatefromstring( $bytes );
		if ( ! $im ) {
			return '';
		}
		$w   = imagesx( $im );
		$h   = imagesy( $im );
		$out = imagecreatetruecolor( $w, $h );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopy( $out, $im, 0, 0, 0, 0, $w, $h );
		ob_start();
		imagejpeg( $out, null, 88 );
		$jpg = (string) ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $out );
		return $jpg;
	}

	private static function pdf( array $opts, string $running ): ZAGR_PDF {
		return new ZAGR_PDF( (string) ( $opts['title'] ?? '' ), (string) ( $opts['footer'] ?? '' ), array( 'logo' => self::logo(), 'running' => $running ) );
	}

	/** The agreement. $render from ZAGR_Document::agreement(); $opts: title, footer, preview, test, sign (as 1.0's). */
	public static function agreement( array $render, array $opts ): string {
		$pdf  = self::pdf( $opts, ZAGR_Strings::t( 'band_title' ) );
		$sign = $opts['sign'] ?? null;
		$secs = (array) $render['sections'];
		self::page_one( $pdf, self::parse( (string) ( $secs['header'] ?? '' ) ), $opts, ZAGR_Strings::t( 'band_title' ), ZAGR_Strings::t( 'pdf_preview_banner' ) );
		$signing = false;
		$group   = array_intersect_key( $secs, array_flip( array( 'sign-notice', 'sign-agreement' ) ) );
		foreach ( $secs as $name => $html ) {
			if ( 'header' === $name ) {
				continue;
			}
			if ( ! isset( $group[ $name ] ) ) {
				self::$pad = 1.0;
			} elseif ( ! $signing ) {
				$signing = true;
				self::new_page( $pdf );   // the signatures start a page of their own
				// a notice that must sit close to both signatures: the whole group on one page, its spacing
				// tightened (never its type) when it must be
				self::fit( $pdf, $group, $sign, $pdf->limit() - $pdf->cursor() );
			}
			$ctx = array( 'slot' => $name, 'sign' => $sign );
			foreach ( self::parse( (string) $html ) as $b ) {
				self::go( $pdf, $b, ZAGR_PDF::MARGIN, $pdf->content_width(), false, $ctx );
			}
			$pdf->space( 6 );
		}
		self::$pad = 1.0;
		if ( $sign ) {
			self::signature_page( $pdf, $sign );
		}
		return $pdf->output();
	}

	/** A change order. */
	public static function change( array $render, array $opts ): string {
		$pdf  = self::pdf( $opts, ZAGR_Strings::t( 'pdf_running_change' ) );
		$sign = $opts['sign'] ?? null;
		$html = (string) ( $render['sections']['change-order'] ?? '' );
		self::band( $pdf, ZAGR_Strings::t( 'pdf_change_title' ) );
		self::banners( $pdf, $opts, 'PREVIEW, NOT SIGNED: the change order as it will read if you sign today.' );
		$ctx = array( 'slot' => 'change-order', 'sign' => $sign );
		foreach ( self::parse( $html ) as $b ) {
			self::go( $pdf, $b, ZAGR_PDF::MARGIN, $pdf->content_width(), false, $ctx );
		}
		if ( false === strpos( $html, 'sig-slot' ) ) {
			self::go( $pdf, array( 't' => 'sig' ), ZAGR_PDF::MARGIN, $pdf->content_width(), false, $ctx );
		}
		if ( $sign ) {
			self::signature_page( $pdf, $sign );
		}
		return $pdf->output();
	}

	/** The spacing (1.0 down to 0.25) at which sections $secs take no more than $room; the smallest when none does. */
	private static function fit( ZAGR_PDF $pdf, array $secs, $sign, float $room ): void {
		$w = $pdf->content_width();
		foreach ( self::PADS as $f ) {
			self::$pad = $f;
			$need      = 0.0;
			foreach ( $secs as $name => $html ) {
				foreach ( self::parse( (string) $html ) as $b ) {
					$need += self::go( $pdf, $b, 0, $w, true, array( 'slot' => $name, 'sign' => $sign ) );
				}
				$need += 6;
			}
			if ( $need <= $room ) {
				return;
			}
		}
	}

	/** Start a new page unless this one has nothing on it yet. */
	private static function new_page( ZAGR_PDF $pdf ): void {
		if ( $pdf->cursor() > ZAGR_PDF::PAPER_TOP + 1 ) {
			$pdf->add_page();
		}
	}

	/* ---------------------------------------------------------------- page one */

	/** The logo, the title and its line, and the stripe. */
	private static function band( ZAGR_PDF $pdf, string $title ): void {
		$top = ZAGR_PDF::PAPER_FIRST;
		$w   = 116.0;
		$h   = $pdf->logo( ZAGR_PDF::MARGIN, $top, $w ) ? $pdf->logo_h( $w ) : 0.0;
		if ( $h <= 0 ) {
			$pdf->text_at( ZAGR_PDF::MARGIN, $top + 20, ZAGR_Strings::company(), 'bold', 16, self::brand() );
			$h = 26.0;
		}
		$right = ZAGR_PDF::MARGIN + $pdf->content_width();
		$pdf->text_at( $right, $top + $h - 15, $title, 'bold', 12, self::brand(), 'right' );
		$pdf->text_at( $right, $top + $h - 2, ZAGR_Strings::t( 'band_sub' ), 'regular', 10, self::GREY, 'right' );
		$pdf->stripe( ZAGR_PDF::MARGIN, $top + $h + 8, $pdf->content_width(), 6, self::stripes() );
		$pdf->set_y( $top + $h + 26 );
	}

	/** "TEST AGREEMENT…" and "PREVIEW, NOT SIGNED…", each in a soft box. */
	private static function banners( ZAGR_PDF $pdf, array $opts, string $preview ): void {
		if ( ! empty( $opts['test'] ) ) {
			self::banner( $pdf, ZAGR_Strings::t( 'test_banner' ), self::WARN_BG, self::WARN_FG );
		}
		if ( ! empty( $opts['preview'] ) ) {
			self::banner( $pdf, $preview, self::soft(), self::brand() );
		}
	}

	private static function banner( ZAGR_PDF $pdf, string $text, array $fill, array $ink ): void {
		$w    = $pdf->content_width();
		$lead = self::BODY * 1.3;
		$h    = count( $pdf->layout( array( array( $text, 'bold' ) ), self::BODY, $w - 20 ) ) * $lead + 10;
		$top  = $pdf->cursor();
		$pdf->round_box( ZAGR_PDF::MARGIN, $top, $w, $h, 6, $fill );
		$pdf->set_y( $top + 5 );
		$pdf->runs( array( array( $text, 'bold' ) ), array( 'size' => self::BODY, 'x' => ZAGR_PDF::MARGIN + 10, 'width' => $w - 20, 'lead' => $lead, 'rgb' => $ink ) );
		$pdf->set_y( $top + $h + 8 );
	}

	/** The spacing steps page one (and the signatures' page) may take, loosest first. */
	const PADS = array( 1.0, 0.85, 0.7, 0.55, 0.4, 0.25 );

	/**
	 * Page one: the band, the banners, the welcome, and its foot at the foot of the page. Everything the law puts on the
	 * first page (some laws put there the date the buyer signed; the statement, then the contractor's name,
	 * address and email; the help phone) is always on it, at its full size. When page one is full, what gives way, least
	 * first: the spacing, step by step; then the five steps' descriptions; then "What happens next" itself; and last
	 *, the welcome's other blocks that still don't fit move to the top of page two, in order, the
	 * required items staying on page one (logged with the estimate number only).
	 */
	private static function page_one( ZAGR_PDF $pdf, array $blocks, array $opts, string $title, string $preview ): void {
		self::band( $pdf, $title );
		self::banners( $pdf, $opts, $preview );
		$foot = null;
		$body = array();
		foreach ( $blocks as $b ) {
			if ( 'foot' === $b['t'] ) {
				$foot = $b;
			} else {
				$body[] = $b;
			}
		}
		$w     = $pdf->content_width();
		$avail = $pdf->limit() - $pdf->cursor();
		$ctx   = array( 'slot' => 'header', 'sign' => $opts['sign'] ?? null, 'first' => true );
		$need  = function ( array $set ) use ( $pdf, $foot, $w, $ctx ): float {
			$n = null === $foot ? 0.0 : self::go( $pdf, $foot, ZAGR_PDF::MARGIN, $w, true, $ctx ) + 10;
			foreach ( $set as $b ) {
				$n += self::go( $pdf, $b, ZAGR_PDF::MARGIN, $w, true, $ctx );
			}
			return $n;
		};
		$short = array_map( function ( $b ) {
			if ( 'steps' === $b['t'] ) {
				$b['items'] = array_map( function ( $it ) { return array( $it[0], array() ); }, $b['items'] );
			}
			return $b;
		}, $body );
		$none  = array_values( array_filter( $body, function ( $b ) { return 'steps' !== $b['t']; } ) );
		$fits  = false;
		foreach ( array( $body, $short, $none ) as $plan ) {
			foreach ( self::PADS as $f ) {
				self::$pad = $f;
				if ( $need( $plan ) <= $avail ) {
					$body = $plan;
					$fits = true;
					break 2;
				}
			}
		}
		$later = array();
		if ( ! $fits ) {
			// still too much: the required items stay, the rest of the welcome that doesn't fit goes to page two
			$body = $none;
			$room = $avail - $need( array_values( array_filter( $none, function ( $b ) { return ! empty( $b['req'] ); } ) ) );
			$keep = array();
			foreach ( $body as $b ) {
				if ( ! empty( $b['req'] ) ) {
					$keep[] = $b;
					continue;
				}
				$h = self::go( $pdf, $b, ZAGR_PDF::MARGIN, $w, true, $ctx );
				if ( ! $later && $h <= $room ) {
					$keep[] = $b;
					$room  -= $h;
				} else {
					$later[] = $b;
				}
			}
			$body = $keep;
			error_log( 'Zorderz agreements: page one of estimate #' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $opts['number'] ?? '' ) ) . ' is full; part of its welcome is on page two.' );
		}
		foreach ( $body as $b ) {
			self::go( $pdf, $b, ZAGR_PDF::MARGIN, $w, false, $ctx );
		}
		if ( null !== $foot ) {
			$fh = self::go( $pdf, $foot, ZAGR_PDF::MARGIN, $w, true, $ctx );
			$pdf->set_y( max( $pdf->cursor() + 10, $pdf->limit() - $fh ) );
			self::go( $pdf, $foot, ZAGR_PDF::MARGIN, $w, false, $ctx );
		}
		self::$pad = 1.0;
		$pdf->add_page();
		if ( $later ) {
			foreach ( $later as $b ) {
				self::go( $pdf, $b, ZAGR_PDF::MARGIN, $w, false, $ctx );
			}
			$pdf->space( 6 );
		}
	}

	/* ---------------------------------------------------------------- the template's HTML as blocks */

	/** The blocks of a fragment of the template's allowed HTML. */
	public static function parse( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$dom = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		foreach ( $dom->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				return self::blocks( $c, false );
			}
		}
		return array();
	}

	private static function cls( DOMNode $n ): array {
		return $n instanceof DOMElement ? array_values( array_filter( preg_split( '/\s+/', trim( $n->getAttribute( 'class' ) ) ) ) ) : array();
	}

	private static function para( array $runs, float $size, array $rgb, float $after, float $lead = self::LEAD ): array {
		return array( 't' => 'p', 'runs' => $runs, 'size' => $size, 'rgb' => $rgb, 'after' => $after, 'lead' => $lead );
	}

	private static function blocks( DOMNode $node, bool $in_card ): array {
		$out = array();
		foreach ( $node->childNodes as $n ) {
			if ( XML_TEXT_NODE === $n->nodeType ) {
				$t = trim( (string) preg_replace( '/\s+/', ' ', $n->textContent ) );
				if ( '' !== $t ) {
					$out[] = self::para( array( array( $t, 'regular' ) ), self::BODY, self::INK, 6 );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $n->nodeType ) {
				continue;
			}
			$tag = strtolower( $n->nodeName );
			$c   = self::cls( $n );
			$has = function ( string $k ) use ( $c ) {
				return in_array( $k, $c, true );
			};
			switch ( $tag ) {
				case 'h2':
					$out[] = array( 't' => 'h2', 'runs' => self::inline( $n, 'bold' ) );
					break;
				case 'h3':
					$out[] = array( 't' => 'h3', 'runs' => self::inline( $n, 'bold' ), 'card' => $in_card );
					break;
				case 'p':
					if ( $has( 'age-slot' ) ) {
						break;   // the page's question; nothing in the PDF
					}
					if ( $has( 'sig-slot' ) ) {
						$out[] = array( 't' => 'sig' );
					} elseif ( $has( 'noc-copy' ) ) {
						$out[] = array( 't' => 'chip', 'text' => trim( (string) preg_replace( '/\s+/', ' ', $n->textContent ) ) );
					} elseif ( $has( 'stat-bold' ) ) {
						$out[] = self::para( self::inline( $n, 'bold' ), 12, self::INK, 7, 1.27 );   // the statute's 12-point bold
					} elseif ( $has( 'stat-12' ) ) {
						$out[] = self::para( self::inline( $n, 'regular' ), 12, self::INK, 6, 1.32 );   // 12-point (a statutory notice set at that size)
					} elseif ( $has( 'todo' ) ) {
						$out[] = array( 't' => 'todo', 'runs' => array_merge( array( array( 'TO COME: ', 'bold' ) ), self::inline( $n, 'italic' ) ) );
					} elseif ( $has( 'money' ) ) {
						$out[] = self::para( self::inline( $n, 'bold' ), 16, self::INK, 4, 1.3 );
					} elseif ( $has( 'hello' ) ) {
						$out[] = self::para( self::inline( $n, 'bold' ), 12.5, self::hello(), 2, 1.3 );
					} elseif ( $has( 'small' ) ) {
						$out[] = self::para( self::inline( $n, 'regular' ), self::SMALL, self::MUTE, 6 );
					} elseif ( $has( 'first-page' ) ) {
						// the date the buyer signed: page one keeps it whatever else gives way
						$out[] = array( 'req' => true ) + self::para( self::inline( $n, 'regular' ), self::BODY, self::INK, 6 );
					} else {
						$out[] = self::para( self::inline( $n, 'regular' ), self::BODY, self::INK, 6 );
					}
					break;
				case 'ul':
				case 'ol':
					$items = array();
					$k     = 0;
					foreach ( $n->childNodes as $li ) {
						if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
							$k++;
							$items[] = array_merge( array( array( 'ol' === $tag ? $k . '.  ' : "\xE2\x80\xA2  ", 'regular' ) ), self::inline( $li, 'regular' ) );
						}
					}
					$out[] = array( 't' => 'list', 'items' => $items );
					break;
				case 'table':
					$out[] = self::table_block( $n );
					break;
				case 'div':
					if ( $has( 'cards' ) ) {
						$cards = array();
						foreach ( $n->childNodes as $d ) {
							if ( XML_ELEMENT_NODE === $d->nodeType ) {
								$cards[] = array( 't' => 'card', 'children' => self::blocks( $d, true ) );
							}
						}
						$out[] = array( 't' => 'row', 'cards' => $cards );
					} elseif ( $has( 'card' ) ) {
						$out[] = array( 't' => 'card', 'children' => self::blocks( $n, true ) );
					} elseif ( $has( 'steps' ) ) {
						$out[] = self::steps_block( $n );
					} elseif ( $has( 'notice' ) ) {
						$out[] = array( 't' => 'box', 'children' => self::blocks( $n, false ) );
					} elseif ( $has( 'first-foot' ) ) {
						// these first-page items "in a typeface no smaller than that generally used
						// in the body of the document": the body's size, in the quiet grey
						$kids = self::blocks( $n, false );
						foreach ( $kids as $i => $k ) {
							if ( 'p' === $k['t'] ) {
								$kids[ $i ]['size'] = self::BODY;
								$kids[ $i ]['rgb']  = self::MUTE;
							}
						}
						$out[] = array( 't' => 'foot', 'children' => $kids );
					} else {
						$out = array_merge( $out, self::blocks( $n, $in_card ) );
					}
					break;
				case 'br':
					break;
				default:
					$out[] = self::para( self::inline( $n, 'regular' ), self::BODY, self::INK, 6 );
			}
		}
		return $out;
	}

	/** Inline runs: text, <strong> bold, <br> a line break (as 1.0's renderer). */
	private static function inline( DOMNode $node, string $face ): array {
		$out = array();
		foreach ( $node->childNodes as $c ) {
			if ( XML_TEXT_NODE === $c->nodeType ) {
				$t = (string) preg_replace( '/\s+/', ' ', $c->textContent );
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
		if ( $out ) {
			$out[0][0]                   = ltrim( $out[0][0], ' ' );
			$out[ count( $out ) - 1 ][0] = rtrim( $out[ count( $out ) - 1 ][0], ' ' );
		}
		return $out;
	}

	/** "What happens next": its heading and its five steps. */
	private static function steps_block( DOMNode $n ): array {
		$title = array();
		$items = array();
		foreach ( $n->childNodes as $c ) {
			if ( XML_ELEMENT_NODE !== $c->nodeType ) {
				continue;
			}
			if ( 'h3' === strtolower( $c->nodeName ) ) {
				$title = self::inline( $c, 'bold' );
			} elseif ( 'ol' === strtolower( $c->nodeName ) ) {
				foreach ( $c->childNodes as $li ) {
					if ( XML_ELEMENT_NODE !== $li->nodeType ) {
						continue;
					}
					$head = array();
					$rest = array();
					foreach ( self::inline( $li, 'regular' ) as $r ) {
						if ( 'bold' === $r[1] && ! $rest ) {
							$head[] = $r;
						} elseif ( "\n" !== $r[0] || $rest ) {
							$rest[] = $r;
						}
					}
					$items[] = array( $head, $rest );
				}
			}
		}
		return array( 't' => 'steps', 'title' => $title, 'items' => $items );
	}

	/** A table as rows of cells (lists of runs), with its kind (the renderer's classes: lines, schedule, changes…). */
	private static function table_block( DOMNode $t ): array {
		$rows   = array();
		$header = false;
		foreach ( ( $t instanceof DOMElement ? $t->getElementsByTagName( 'tr' ) : array() ) as $ri => $tr ) {
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
			$rows[] = $row;
		}
		$kind = self::cls( $t )[0] ?? '';
		$n    = $rows ? max( array_map( 'count', $rows ) ) : 0;
		$spec = array(
			'lines'          => array( array( 0.62, 0.13, 0.25 ), array( 'left', 'center', 'right' ), true ),
			'schedule'       => array( array( 0.30, 0.45, 0.25 ), array( 'left', 'left', 'right' ), true ),
			'changes'        => array( array( 0.18, 0.42, 0.14, 0.26 ), array( 'left', 'left', 'center', 'right' ), false ),
			'changes-totals' => array( array( 0.34, 0.33, 0.33 ), array( 'left', 'left', 'left' ), false ),
		)[ $kind ] ?? array( $n ? array_fill( 0, $n, 1 / $n ) : array(), $n ? array_fill( 0, $n, 'left' ) : array(), false );
		if ( count( $spec[0] ) !== $n ) {
			$spec = array( $n ? array_fill( 0, $n, 1 / $n ) : array(), $n ? array_fill( 0, $n, 'left' ) : array(), false );
		}
		return array( 't' => 'table', 'rows' => $rows, 'header' => $header, 'widths' => $spec[0], 'aligns' => $spec[1], 'total' => $spec[2], 'size' => self::SMALL );
	}

	/* ---------------------------------------------------------------- drawing (or measuring: $dry) */

	/** The space a block takes below its last line (inside a card the padding takes its place). */
	private static function after_of( array $b ): float {
		return 'p' === $b['t'] ? $b['after'] * self::$pad : ( in_array( $b['t'], array( 'card', 'row', 'box', 'steps', 'table', 'list', 'todo' ), true ) ? 10 * self::$pad : 4 * self::$pad );
	}

	private static function sum( ZAGR_PDF $pdf, array $blocks, float $w, array $ctx ): float {
		$h = 0.0;
		foreach ( $blocks as $b ) {
			$h += self::go( $pdf, $b, 0, $w, true, $ctx );
		}
		return $h;
	}

	/**
	 * Draw block $b at the cursor, $x and $w wide; or, with $dry, only measure it. Returns its height (with the space
	 * after it). A box that fits on a page is never split: it starts a new page when this one has no room.
	 */
	private static function go( ZAGR_PDF $pdf, array $b, float $x, float $w, bool $dry, array $ctx ): float {
		$pad = self::$pad;
		switch ( $b['t'] ) {
			case 'p':
				$lead = $b['size'] * $b['lead'];
				$h    = count( $pdf->layout( $b['runs'], $b['size'], $w ) ) * $lead + $b['after'] * $pad;
				if ( ! $dry ) {
					$pdf->runs( $b['runs'], array( 'size' => $b['size'], 'x' => $x, 'width' => $w, 'lead' => $lead, 'rgb' => $b['rgb'] ) );
					$pdf->space( $b['after'] * $pad );
				}
				return $h;

			case 'h2':
				$big   = ! empty( $ctx['first'] );
				$size  = $big ? 22.0 : 15.0;
				$lead  = $size * 1.25;
				$lines = count( $pdf->layout( $b['runs'], $size, $w ) );
				$top   = $big ? 2.0 : 14.0 * $pad;
				$h     = $top + $lines * $lead + ( $big ? 6 : 12 ) * $pad;
				if ( $dry ) {
					return $h;
				}
				$pdf->ensure( $h + 60 );   // never alone at the foot of a page
				if ( $pdf->cursor() > ZAGR_PDF::PAPER_TOP + 1 ) {
					$pdf->space( $top );
				}
				$pdf->runs( $b['runs'], array( 'size' => $size, 'x' => $x, 'width' => $w, 'lead' => $lead, 'rgb' => self::brand() ) );
				if ( ! $big ) {
					$pdf->hline( $x, $pdf->cursor() + 2, $w, self::LINE, 0.8 );
				}
				$pdf->space( ( $big ? 6 : 12 ) * $pad );
				return $h;

			case 'h3':
				$size  = 11.5;
				$lead  = $size * 1.3;
				$card  = ! empty( $b['card'] );
				$top   = $card ? 0.0 : 9.0 * $pad;
				$h     = $top + count( $pdf->layout( $b['runs'], $size, $w ) ) * $lead + 3 * $pad;
				if ( $dry ) {
					return $h;
				}
				if ( ! $card ) {
					$pdf->ensure( $h + 40 );
					if ( $pdf->cursor() > ZAGR_PDF::PAPER_TOP + 1 ) {
						$pdf->space( $top );
					}
				}
				$pdf->runs( $b['runs'], array( 'size' => $size, 'x' => $x, 'width' => $w, 'lead' => $lead, 'rgb' => $card ? self::brand() : self::INK ) );
				$pdf->space( 3 * $pad );
				return $h;

			case 'list':
				$lead = self::BODY * self::LEAD;
				$h    = 0.0;
				foreach ( $b['items'] as $it ) {
					$h += count( $pdf->layout( $it, self::BODY, $w - 12 ) ) * $lead + 3 * $pad;
				}
				$h += 4 * $pad;
				if ( ! $dry ) {
					foreach ( $b['items'] as $it ) {
						$pdf->runs( $it, array( 'size' => self::BODY, 'x' => $x + 12, 'width' => $w - 12, 'lead' => $lead, 'rgb' => self::INK ) );
						$pdf->space( 3 * $pad );
					}
					$pdf->space( 4 * $pad );
				}
				return $h;

			case 'card':
				return self::card( $pdf, $b, $x, $w, $dry, $ctx, 0.0 );

			case 'row':
				$n   = max( 1, count( $b['cards'] ) );
				$gap = 12.0;
				$cw  = ( $w - $gap * ( $n - 1 ) ) / $n;
				$hh  = 0.0;
				foreach ( $b['cards'] as $c ) {
					$hh = max( $hh, self::card( $pdf, $c, 0, $cw, true, $ctx, 0.0 ) - 10 * $pad );
				}
				$h = $hh + 10 * $pad;
				if ( $dry ) {
					return $h;
				}
				if ( $hh <= $pdf->page_room() ) {
					$pdf->ensure( $hh );
				}
				$top = $pdf->cursor();
				foreach ( $b['cards'] as $i => $c ) {
					$pdf->set_y( $top );
					self::card( $pdf, $c, $x + $i * ( $cw + $gap ), $cw, false, $ctx, $hh );
				}
				$pdf->set_y( $top + $h );
				return $h;

			case 'box':   // the statute's notice: a firm border, never split
				$padx = 12.0;
				$pady = max( 8.0, 10.0 * $pad );
				$in   = self::sum( $pdf, $b['children'], $w - 2 * $padx, $ctx );
				$last = $b['children'] ? self::after_of( end( $b['children'] ) ) : 0.0;
				$bh   = $in - $last + 2 * $pady;
				$h    = $bh + 10 * $pad;
				if ( $dry ) {
					return $h;
				}
				if ( $bh <= $pdf->page_room() ) {
					$pdf->ensure( $bh );
				}
				$top = $pdf->cursor();
				$pdf->round_box( $x, $top, $w, $bh, 6, null, self::INK, 1.6 );
				$pdf->set_y( $top + $pady );
				foreach ( $b['children'] as $c ) {
					self::go( $pdf, $c, $x + $padx, $w - 2 * $padx, false, $ctx );
				}
				$pdf->set_y( $top + $h );
				return $h;

			case 'todo':
				$lead = self::BODY * self::LEAD;
				$bh   = count( $pdf->layout( $b['runs'], self::BODY, $w - 20 ) ) * $lead + 14;
				$h    = $bh + 10 * $pad;
				if ( $dry ) {
					return $h;
				}
				$pdf->ensure( $bh );
				$top = $pdf->cursor();
				$pdf->round_box( $x, $top, $w, $bh, 6, self::WARN_BG, self::WARN_FG, 1.0 );
				$pdf->set_y( $top + 7 );
				$pdf->runs( $b['runs'], array( 'size' => self::BODY, 'x' => $x + 10, 'width' => $w - 20, 'lead' => $lead, 'rgb' => self::WARN_FG ) );
				$pdf->set_y( $top + $h );
				return $h;

			case 'steps':
				return self::steps( $pdf, $b, $x, $w, $dry );

			case 'table':
				return self::table( $pdf, $b, $x, $w, $dry );

			case 'chip':   // a Notice of Cancellation copy: a page of its own, from a dashed edge
				if ( $dry ) {
					return 40.0;
				}
				self::new_page( $pdf );
				$top = $pdf->cursor();
				$pdf->hline( $x, $top, $w, self::MUTE, 0.8, array( 4, 3 ) );
				$tw = $pdf->text_width( $b['text'], self::SMALL, 'bold' );
				$pdf->round_box( $x, $top + 10, $tw + 22, 19, 9.5, self::soft() );
				$pdf->text_at( $x + 11, $top + 10 + 13.2, $b['text'], 'bold', self::SMALL, self::brand() );
				$pdf->set_y( $top + 40 );
				return 40.0;

			case 'sig':
				return self::sig( $pdf, $x, $w, $dry, $ctx );

			case 'foot':
				$h = 10.0 + self::sum( $pdf, $b['children'], $w, $ctx );
				if ( ! $dry ) {
					$pdf->hline( $x, $pdf->cursor(), $w, self::LINE, 0.8 );
					$pdf->space( 10 );
					foreach ( $b['children'] as $c ) {
						self::go( $pdf, $c, $x, $w, false, $ctx );
					}
				}
				return $h;
		}
		return 0.0;
	}

	/** A card: a rounded border round its blocks; $force_h draws it that tall (a row of cards shares one height). */
	private static function card( ZAGR_PDF $pdf, array $b, float $x, float $w, bool $dry, array $ctx, float $force_h ): float {
		$pad  = self::$pad;
		$padx = 12.0;
		$pady = 9.0 * $pad + 2;
		$in   = self::sum( $pdf, $b['children'], $w - 2 * $padx, $ctx );
		$last = $b['children'] ? self::after_of( end( $b['children'] ) ) : 0.0;
		$bh   = max( $force_h, $in - $last + 2 * $pady );
		$h    = $bh + 10 * $pad;
		if ( $dry ) {
			return $h;
		}
		if ( $bh <= $pdf->page_room() && $force_h <= 0 ) {
			$pdf->ensure( $bh );
		}
		$top = $pdf->cursor();
		$pdf->round_box( $x, $top, $w, $bh, 8, null, self::LINE, 0.9 );
		$pdf->set_y( $top + $pady );
		foreach ( $b['children'] as $c ) {
			self::go( $pdf, $c, $x + $padx, $w - 2 * $padx, false, $ctx );
		}
		$pdf->set_y( $top + $h );
		return $h;
	}

	/** The five steps: a coloured bar, a numbered circle, the step, and what happens, in five columns. */
	private static function steps( ZAGR_PDF $pdf, array $b, float $x, float $w, bool $dry ): float {
		$pad   = self::$pad;
		$n     = max( 1, count( $b['items'] ) );
		$gap   = 10.0;
		$cw    = ( $w - $gap * ( $n - 1 ) ) / $n;
		$tlead = 11.5 * 1.3;
		$th    = $b['title'] ? count( $pdf->layout( $b['title'], 11.5, $w ) ) * $tlead + 6 * $pad : 0.0;
		$ih    = 0.0;
		foreach ( $b['items'] as $it ) {
			$ih = max( $ih, 3 + 6 + 20 + 6 + count( $pdf->layout( $it[0], self::BODY, $cw ) ) * self::BODY * 1.3 + count( $pdf->layout( $it[1], self::SMALL, $cw ) ) * self::SMALL * 1.35 );
		}
		$h = $th + $ih + 12 * $pad;
		if ( $dry ) {
			return $h;
		}
		$pdf->ensure( $th + $ih );
		if ( $b['title'] ) {
			$pdf->runs( $b['title'], array( 'size' => 11.5, 'x' => $x, 'width' => $w, 'lead' => $tlead, 'rgb' => self::brand() ) );
			$pdf->space( 6 * $pad );
		}
		$top = $pdf->cursor();
		foreach ( array_values( $b['items'] ) as $i => $it ) {
			$cx = $x + $i * ( $cw + $gap );
			$k  = $i % 5;
			$pdf->rect( $cx, $top, $cw, 3, self::stripes()[ $k ] );
			$pdf->circle( $cx + 10, $top + 3 + 6 + 10, 10, self::circles()[ $k ] );
			$num = (string) ( $i + 1 );
			$pdf->text_at( $cx + 10 - $pdf->text_width( $num, 11, 'bold' ) / 2, $top + 3 + 6 + 10 + 3.9, $num, 'bold', 11, self::WHITE );
			$pdf->set_y( $top + 3 + 6 + 20 + 6 );
			$pdf->runs( $it[0], array( 'size' => self::BODY, 'x' => $cx, 'width' => $cw, 'lead' => self::BODY * 1.3, 'rgb' => self::INK ) );
			$pdf->runs( $it[1], array( 'size' => self::SMALL, 'x' => $cx, 'width' => $cw, 'lead' => self::SMALL * 1.35, 'rgb' => self::MUTE ) );
		}
		$pdf->set_y( $top + $ih + 12 * $pad );
		return $h;
	}

	/** A table: a soft header row in blue, rows over thin rules, a firm rule under the total. Rows never split. */
	private static function table( ZAGR_PDF $pdf, array $b, float $x, float $w, bool $dry ): float {
		$size  = (float) $b['size'];
		$lead  = $size * 1.32;
		$cpad  = 5.0;
		$total = 0.0;
		$rows  = array();
		foreach ( $b['rows'] as $ri => $row ) {
			$cells = array();
			$rh    = 0.0;
			foreach ( $b['widths'] as $c => $f ) {
				$lines   = $pdf->layout( (array) ( $row[ $c ] ?? array() ), $size, max( 20.0, $w * $f - 2 * $cpad ) );
				$cells[] = $lines;
				$rh      = max( $rh, count( $lines ) * $lead );
			}
			$rh    += 2 * $cpad;
			$rows[] = array( $cells, $rh );
			$total += $rh;
		}
		$h = $total + 10 * self::$pad;
		if ( $dry ) {
			return $h;
		}
		$last = count( $rows ) - 1;
		foreach ( $rows as $ri => $r ) {
			list( $cells, $rh ) = $r;
			$pdf->ensure( $rh );
			$top  = $pdf->cursor();
			$head = 0 === $ri && $b['header'];
			if ( $head ) {
				$pdf->rect( $x, $top, $w, $rh, self::soft() );
			}
			$cx = $x;
			foreach ( $b['widths'] as $c => $f ) {
				$cw = $w * $f;
				$ly = $top + $cpad;
				foreach ( $cells[ $c ] as $line ) {
					$lw = 0.0;
					foreach ( $line as $piece ) {
						$lw += $pdf->text_width( $piece[0], $size, $piece[1] );
					}
					$al = $b['aligns'][ $c ] ?? 'left';
					$lx = 'right' === $al ? $cx + $cw - $cpad - $lw : ( 'center' === $al ? $cx + ( $cw - $lw ) / 2 : $cx + $cpad );
					foreach ( $line as $piece ) {
						$pdf->text_at( $lx, $ly + $size * 0.80, $piece[0], $piece[1], $size, $head ? self::brand() : self::INK );
						$lx += $pdf->text_width( $piece[0], $size, $piece[1] );
					}
					$ly += $lead;
				}
				$cx += $cw;
			}
			if ( ! $head ) {
				$firm = $b['total'] && $ri === $last;
				$pdf->hline( $x, $top + $rh, $w, $firm ? self::INK : self::LINE, $firm ? 1.4 : 0.6 );
			}
			$pdf->set_y( $top + $rh );
		}
		$pdf->space( 10 * self::$pad );
		return $h;
	}

	/**
	 * A signature line where the template puts one (class sig-slot): the typed name (italic) and the date on the signed
	 * copy, blank lines in the preview. In sign-notice it is the buyer's acknowledgment of the notice; in sign-agreement
	 * and a change order, the buyer's signature.
	 */
	private static function sig( ZAGR_PDF $pdf, float $x, float $w, bool $dry, array $ctx ): float {
		$s      = is_array( $ctx['sign'] ?? null ) ? $ctx['sign'] : null;
		$notice = 'sign-notice' === ( $ctx['slot'] ?? '' );
		$name   = null === $s ? '' : (string) ( $notice ? ( $s['notice_name'] ?? '' ) : ( $s['agreement_name'] ?? '' ) );
		$date   = null === $s ? '' : (string) ( $notice ? ( $s['notice_date_long'] ?? '' ) : ( $s['signed_date_long'] ?? '' ) );
		$fit    = self::sig_fit( $pdf, $name, $w );
		if ( ! $fit['long'] ) {
			// the name and the date side by side on one line
			$h = 44.0;
			if ( $dry ) {
				return $h;
			}
			$pdf->ensure( $h );
			$top = $pdf->cursor() + 2;
			$lw  = $w * 0.62;
			$rx  = $x + $w * 0.68;
			$rw  = $w * 0.32;
			if ( '' !== $name ) {
				$pdf->text_at( $x + 2, $top + 18, $name, 'italic', self::SIG_SIZE, self::INK );
				$pdf->text_at( $rx + 2, $top + 18, $date, 'regular', self::BODY, self::INK );
			}
			$pdf->hline( $x, $top + 24, $lw, self::INK, 1.0 );
			$pdf->hline( $rx, $top + 24, $rw, self::INK, 1.0 );
			$pdf->text_at( $x, $top + 36, ZAGR_Strings::t( 'pdf_sig_caption' ), 'regular', self::SMALL, self::MUTE );
			$pdf->text_at( $rx, $top + 36, ZAGR_Strings::t( 'pdf_date_caption' ), 'regular', self::SMALL, self::MUTE );
			$pdf->set_y( $top - 2 + $h );
			return $h;
		}
		// a long name takes the whole width, smaller (never under 10 points) and, if it must,
		// on more than one line above its rule; the date moves to a line of its own below it
		$size = $fit['size'];
		$lead = $size * 1.25;
		$n    = count( $fit['lines'] );
		$rule = max( 24.0, 6 + ( $n - 1 ) * $lead + $size + 2 );
		$h    = $rule + 60;
		if ( $dry ) {
			return $h;
		}
		$pdf->ensure( $h );
		$top = $pdf->cursor() + 2;
		foreach ( $fit['lines'] as $i => $line ) {
			$pdf->text_at( $x + 2, $top + $rule - 6 - ( $n - 1 - $i ) * $lead, $line, 'italic', $size, self::INK );
		}
		$pdf->hline( $x, $top + $rule, $w, self::INK, 1.0 );
		$pdf->text_at( $x, $top + $rule + 12, ZAGR_Strings::t( 'pdf_sig_caption' ), 'regular', self::SMALL, self::MUTE );
		$dw = min( $w, max( $w * 0.32, $pdf->text_width( $date, self::BODY, 'regular' ) + 8 ) );
		$pdf->text_at( $x + 2, $top + $rule + 34, $date, 'regular', self::BODY, self::INK );
		$pdf->hline( $x, $top + $rule + 40, $dw, self::INK, 1.0 );
		$pdf->text_at( $x, $top + $rule + 52, ZAGR_Strings::t( 'pdf_date_caption' ), 'regular', self::SMALL, self::MUTE );
		$pdf->set_y( $top - 2 + $h );
		return $h;
	}

	/** The typed name's size on a signature line, and the smallest it may take (10 points). */
	const SIG_SIZE = 16.0;

	/**
	 * How a typed name sits on its signature line: { long, size, lines }. Short enough for the line beside the date:
	 * 16 points, one line. Longer: the whole width, shrunk half a point at a time to no less than 10 points, and only
	 * then wrapped (at 10 points).
	 */
	private static function sig_fit( ZAGR_PDF $pdf, string $name, float $w ): array {
		if ( '' === $name || $pdf->text_width( $name, self::SIG_SIZE, 'italic' ) <= $w * 0.62 - 4 ) {
			return array( 'long' => false, 'size' => self::SIG_SIZE, 'lines' => array( $name ) );
		}
		for ( $size = self::SIG_SIZE; $size >= self::SMALL; $size -= 0.5 ) {
			if ( $pdf->text_width( $name, $size, 'italic' ) <= $w - 4 ) {
				return array( 'long' => true, 'size' => $size, 'lines' => array( $name ) );
			}
		}
		$lines = array();
		foreach ( $pdf->layout( array( array( $name, 'italic' ) ), self::SMALL, $w - 4 ) as $ln ) {
			$lines[] = trim( implode( '', array_map( function ( $p ) { return (string) $p[0]; }, $ln ) ) );
		}
		return array( 'long' => true, 'size' => self::SMALL, 'lines' => $lines ?: array( $name ) );
	}

	/* ---------------------------------------------------------------- the signature page */

	/**
	 * The last page (as 1.0's ZAGR_PDF_Render::signature_page(), in this layout, every word at 10 points or more): both
	 * signatures, the business's, the record of the signing and its steps. $s as the classic layout's, with one_name (true when
	 * the record says one typed name was applied to both).
	 */
	public static function signature_page( ZAGR_PDF $pdf, array $s ): void {
		$pdf->add_page();
		$w      = $pdf->content_width();
		$x      = ZAGR_PDF::MARGIN;
		$ctx    = array( 'slot' => 'record', 'sign' => null );
		$change = 'change' === ( $s['kind'] ?? 'agreement' );
		$r      = function ( string $t, string $f = 'regular' ) {
			return array( array( $t, $f ) );
		};
		$named  = function ( string $title, string $name, string $line ) use ( $r ) {
			return array( 't' => 'card', 'children' => array(
				array( 't' => 'h3', 'runs' => $r( $title, 'bold' ), 'card' => true ),
				self::para( $r( $name, 'italic' ), 18, self::INK, 2, 1.3 ),
				self::para( $r( $line ), self::BODY, self::INK, 0 ),
			) );
		};
		$blocks = array( array( 't' => 'h2', 'runs' => $r( ZAGR_Strings::t( 'pdf_sig_heading' ), 'bold' ) ) );
		$blocks[] = $named( $change ? 'The change order, signed by the buyer' : ZAGR_Strings::t( 'pdf_sig_agreement' ), (string) $s['agreement_name'], 'Signed ' . $s['signed_at_long'] );
		if ( ! $change ) {
			$blocks[] = $named( ZAGR_Strings::t( 'pdf_sig_notice_ack' ), (string) $s['notice_name'], 'Date: ' . $s['notice_date_long'] );
			if ( ! empty( $s['one_name'] ) ) {
				$blocks[] = self::para( $r( ZAGR_Strings::t( 'pdf_one_name' ) ), self::BODY, self::INK, 8 );
			}
		}
		$blocks[] = array( 't' => 'card', 'children' => array(
			array( 't' => 'h3', 'runs' => $r( ZAGR_Strings::t( 'pdf_sig_contractor', array( 'company' => (string) ( $s['company'] ?? '' ) ) ), 'bold' ), 'card' => true ),
			self::para( $r( $s['contractor_name'] . ', ' . $s['contractor_date_long'] ), self::BODY, self::INK, 0 ),
		) );
		$rows = array(
			array( $r( 'What', 'bold' ), $r( 'Value', 'bold' ) ),
			array( $r( 'Estimate' ), $r( '#' . $s['estimate_number'] ) ),
			array( $r( 'SHA-256 of the words on the page when signed' ), $r( ZAGR_PDF_Render::halves( (string) $s['render_hash'] ) ) ),   // two fixed halves
			array( $r( 'SHA-256 of this filled-in copy\'s words' ), $r( ZAGR_PDF_Render::halves( (string) ( $s['copy_hash'] ?? '' ) ) ) ),
			array( $r( 'Template version' ), $r( (string) $s['template_version'] ) ),
			array( $r( 'Payment plan version' ), $r( (string) $s['plan_version'] ) ),
			array( $r( 'Code emailed to' ), $r( (string) $s['email_masked'] ) ),
			array( $r( 'Signed from (IP address)' ), $r( '' !== (string) $s['ip'] ? (string) $s['ip'] : 'Not known' ) ),
			array( $r( 'Device' ), $r( '' !== (string) $s['device'] ? (string) $s['device'] : 'Not known' ) ),
		);
		if ( ! $change ) {
			if ( ! empty( $s['age_line'] ) ) {
				$rows[] = array( $r( ZAGR_Strings::t( 'pdf_age_label' ) ), $r( (string) $s['age_line'] ) );
			}
			if ( ! empty( $s['pack_label'] ) ) {
				$rows[] = array( $r( ZAGR_Strings::t( 'pdf_pack_label' ) ), $r( (string) $s['pack_label'] ) );
			}
			if ( ! empty( $s['window_line'] ) ) {
				$rows[] = array( $r( ZAGR_Strings::t( 'pdf_window_label' ) ), $r( (string) $s['window_line'] ) );
			}
			$rows[] = array( $r( 'Cancellation period ends' ), $r( 'Midnight on ' . $s['cancel_by_long'] ) );
		}
		if ( ! empty( $s['head'][1] ) ) {
			$rows[] = array( $r( ZAGR_Strings::t( 'pdf_record_fp' ) ), $r( ZAGR_PDF_Render::halves( (string) $s['head'][1] ) . ' (after step ' . (int) $s['head'][0] . ')' ) );   // two fixed halves
		}
		$blocks[] = array( 't' => 'h3', 'runs' => $r( ZAGR_Strings::t( 'pdf_record' ), 'bold' ), 'card' => false );
		$blocks[] = array( 't' => 'table', 'rows' => $rows, 'header' => true, 'widths' => array( 0.28, 0.72 ), 'aligns' => array( 'left', 'left' ), 'total' => false, 'size' => self::SMALL );
		$ev       = array( array( $r( '#', 'bold' ), $r( 'Time (UTC)', 'bold' ), $r( 'Step', 'bold' ), $r( 'Fingerprint', 'bold' ) ) );
		foreach ( (array) ( $s['events'] ?? array() ) as $e ) {
			$ev[] = array( $r( (string) $e['seq'] ), $r( substr( (string) $e['at_utc'], 0, 19 ) ), $r( ZAGR_PDF_Render::STEP[ $e['type'] ] ?? (string) $e['type'] ), $r( substr( (string) $e['hash'], 0, 16 ) ) );
		}
		$blocks[] = array( 't' => 'h3', 'runs' => $r( ZAGR_Strings::t( 'pdf_steps' ), 'bold' ), 'card' => false );
		$blocks[] = array( 't' => 'table', 'rows' => $ev, 'header' => true, 'widths' => array( 0.07, 0.31, 0.38, 0.24 ), 'aligns' => array( 'left', 'left', 'left', 'left' ), 'total' => false, 'size' => self::SMALL );
		$blocks[] = self::para( $r( ZAGR_Strings::t( 'pdf_steps_note', array( 'company' => (string) ( $s['company'] ?? '' ) ) ) ), self::SMALL, self::MUTE, 0 );
		foreach ( $blocks as $b ) {
			self::go( $pdf, $b, $x, $w, false, $ctx );
		}
	}
}
