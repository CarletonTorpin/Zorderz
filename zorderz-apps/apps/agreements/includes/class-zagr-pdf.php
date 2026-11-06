<?php
/**
 * ZAGR_PDF - a small, dependency-free PDF writer. [CORE]
 *
 * Three faces (Helvetica, Helvetica-Bold, and Helvetica-Oblique for typed signatures), paragraphs made of runs (bold and
 * plain words in one wrapped paragraph), simple tables, a footer with "Page N of M" on every page, and the document's
 * title in its info dictionary. PDF 1.4, the base-14 Helvetica faces in WinAnsi (no font embedding), deterministic for
 * the same input (no dates written by the writer itself).
 *
 * Coordinates: top-down (y is the distance from the top margin); points, 72 per inch; US Letter.
 *
 * A "paper" mode for the card layout (ZAGR_PDF_Paper), opted into by the constructor's third argument; without it the
 * classic layout's output is unchanged. Paper mode adds: one JPEG drawn as an image (DCTDecode, the bytes passed
 * through: the logo, one object shared by every page), rounded boxes, circles, lines, a five-colour stripe in a rounded
 * clip, text placed at a point (left or right aligned), and on output a running head on every page after the first
 * (the small logo, a title, a thin stripe) and a footer ("Page n of N") on every page, both marked /Artifact (page
 * furniture, not the document's words), at 10 points. Still the base-14 Helvetica faces, nothing embedded but the logo.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_PDF {

	const PAGE_W = 612.0;
	const PAGE_H = 792.0;
	const MARGIN = 54.0;
	const FOOT   = 30.0; // room kept for the footer

	private $pages  = array();
	private $buf    = '';
	private $y      = self::MARGIN;
	private $title  = '';
	private $footer = '';

	/** Paper mode: on, the logo { data, w, h } or null, the running head's words, and the page's limits. */
	private $paper   = false;
	private $logo    = null;
	private $running = '';
	private $top     = self::MARGIN;                        // where a new page's words start
	private $limit   = self::PAGE_H - self::MARGIN - self::FOOT;   // where a page's words must end

	const PAPER_TOP   = 70.0;    // below the running head and its stripe
	const PAPER_FIRST = 34.0;    // page one starts at its band
	const PAPER_LIMIT = 736.0;   // above the footer's rule

	/**
	 * $paper: array() for 1.0's layout, or { logo: { data, w, h } | null, running: string } for the paper
	 * form's (ZAGR_PDF_Paper).
	 */
	public function __construct( string $title = '', string $footer = '', ?array $paper = null ) {
		$this->title  = $title;
		$this->footer = $footer;
		if ( null !== $paper ) {
			$this->paper   = true;
			$this->logo    = isset( $paper['logo']['data'] ) && '' !== (string) $paper['logo']['data'] ? $paper['logo'] : null;
			$this->running = (string) ( $paper['running'] ?? '' );
			$this->top     = self::PAPER_TOP;
			$this->limit   = self::PAPER_LIMIT;
			$this->y       = self::PAPER_FIRST;
		}
	}

	public function content_width(): float {
		return self::PAGE_W - 2 * self::MARGIN;
	}

	public function cursor(): float {
		return $this->y;
	}

	public function space( float $dy ): void {
		$this->y += $dy;
	}

	public function room(): float {
		return $this->limit - $this->y;
	}

	/** the room a whole page gives (an unsplittable box taller than this is drawn across pages). */
	public function page_room(): float {
		return $this->limit - $this->top;
	}

	/** where a page's words must end (paper mode puts page one's foot just above it). */
	public function limit(): float {
		return $this->limit;
	}

	/** move the cursor (columns draw side by side from one top). */
	public function set_y( float $y ): void {
		$this->y = $y;
	}

	public function page_count(): int {
		return count( $this->pages ) + ( '' !== $this->buf ? 1 : 0 );
	}

	public function add_page(): void {
		$this->flush_page();
		$this->buf = '';
		$this->y   = $this->top;
	}

	public function ensure( float $need ): void {
		if ( $this->room() < $need ) {
			$this->add_page();
		}
	}

	/* ---------------------------------------------------------------- drawing */

	public function rect( float $x, float $top, float $w, float $h, array $rgb ): void {
		$y0         = self::PAGE_H - ( $top + $h );
		$this->buf .= sprintf( "%.3F %.3F %.3F rg %.3F %.3F %.3F %.3F re f\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, $y0, $w, $h );
	}

	/** An outlined box (the notice's border). */
	public function frame( float $x, float $top, float $w, float $h, float $lw = 1.2 ): void {
		$y0         = self::PAGE_H - ( $top + $h );
		$this->buf .= sprintf( "%.2F w 0 0 0 RG %.3F %.3F %.3F %.3F re S\n", $lw, $x, $y0, $w, $h );
	}

	/* ---------------------------------------------------------------- paper mode's drawing */

	private static function rgbf( array $rgb ): string {
		return sprintf( '%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255 );
	}

	/** The path of a rounded rectangle (top-down coordinates). */
	private static function round_path( float $x, float $top, float $w, float $h, float $r ): string {
		$r  = max( 0.0, min( $r, $w / 2, $h / 2 ) );
		$y0 = self::PAGE_H - ( $top + $h );
		$y1 = self::PAGE_H - $top;
		$k  = 0.5523 * $r;
		$x1 = $x + $w;
		return sprintf( "%.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h\n",
			$x + $r, $y0, $x1 - $r, $y0, $x1 - $r + $k, $y0, $x1, $y0 + $r - $k, $x1, $y0 + $r,
			$x1, $y1 - $r, $x1, $y1 - $r + $k, $x1 - $r + $k, $y1, $x1 - $r, $y1,
			$x + $r, $y1, $x + $r - $k, $y1, $x, $y1 - $r + $k, $x, $y1 - $r,
			$x, $y0 + $r, $x, $y0 + $r - $k, $x + $r - $k, $y0, $x + $r, $y0 );
	}

	/** A rounded box: filled ($fill), outlined ($stroke, $lw), or both. */
	public function round_box( float $x, float $top, float $w, float $h, float $r, ?array $fill, ?array $stroke = null, float $lw = 0.8 ): void {
		$path = self::round_path( $x, $top, $w, $h, $r );
		if ( null !== $fill ) {
			$this->buf .= 'q ' . self::rgbf( $fill ) . " rg\n" . $path . "f Q\n";
		}
		if ( null !== $stroke ) {
			$this->buf .= sprintf( 'q %.2F w ', $lw ) . self::rgbf( $stroke ) . " RG\n" . $path . "S Q\n";
		}
	}

	/** A filled circle; $cy is its centre, from the top. */
	public function circle( float $cx, float $cy, float $r, array $fill ): void {
		$y = self::PAGE_H - $cy;
		$k = 0.5523 * $r;
		$this->buf .= 'q ' . self::rgbf( $fill ) . sprintf( " rg\n%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c f Q\n",
			$cx + $r, $y, $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r, $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y,
			$cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r, $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y );
	}

	/** A horizontal line; $dash e.g. array( 3, 2 ) for a dashed one. */
	public function hline( float $x, float $top, float $w, array $rgb, float $lw = 0.8, array $dash = array() ): void {
		$y = self::PAGE_H - $top;
		$this->buf .= sprintf( 'q %.2F w ', $lw ) . self::rgbf( $rgb ) . ' RG ' . ( $dash ? '[' . implode( ' ', array_map( 'floatval', $dash ) ) . '] 0 d ' : '' )
			. sprintf( "%.2F %.2F m %.2F %.2F l S Q\n", $x, $y, $x + $w, $y );
	}

	/** Equal bands of colour in a rounded strip (the brand stripe). */
	public function stripe( float $x, float $top, float $w, float $h, array $colours ): void {
		$n = count( $colours );
		if ( ! $n ) {
			return;
		}
		$this->buf .= "q\n" . self::round_path( $x, $top, $w, $h, $h / 2 ) . "W n\n";
		$cw = $w / $n;
		foreach ( array_values( $colours ) as $i => $c ) {
			$this->buf .= self::rgbf( $c ) . sprintf( " rg %.3F %.3F %.3F %.3F re f\n", $x + $i * $cw, self::PAGE_H - ( $top + $h ), $cw + ( $i < $n - 1 ? 0.6 : 0 ), $h );
		}
		$this->buf .= "Q\n";
	}

	/** The logo, $w wide (its height follows), its top-left at ($x, $top). False without a logo. */
	public function logo( float $x, float $top, float $w ): bool {
		if ( null === $this->logo || (int) $this->logo['w'] <= 0 ) {
			return false;
		}
		$h          = $w * (int) $this->logo['h'] / (int) $this->logo['w'];
		$this->buf .= sprintf( "q %.3F 0 0 %.3F %.3F %.3F cm /Im1 Do Q\n", $w, $h, $x, self::PAGE_H - ( $top + $h ) );
		return true;
	}

	/** The logo's height at width $w (0 without a logo). */
	public function logo_h( float $w ): float {
		return null === $this->logo || (int) $this->logo['w'] <= 0 ? 0.0 : $w * (int) $this->logo['h'] / (int) $this->logo['w'];
	}

	/** One line of text at a point: $align 'left' (x is its start) or 'right' (x is its end). */
	public function text_at( float $x, float $baseline, string $text, string $face, float $size, array $rgb, string $align = 'left' ): void {
		if ( 'right' === $align ) {
			$x -= $this->text_width( $text, $size, $face );
		}
		$this->draw_text( $x, $baseline, $text, $face, $size, $rgb );
	}

	/** The width and JPEG size of a baseline JPEG's first frame (SOF0 to SOF2), read from its bytes; null if not one. */
	public static function jpeg_size( string $jpeg ): ?array {
		if ( "\xFF\xD8" !== substr( $jpeg, 0, 2 ) ) {
			return null;
		}
		$i = 2;
		$n = strlen( $jpeg );
		while ( $i + 9 < $n ) {
			if ( "\xFF" !== $jpeg[ $i ] ) {
				return null;
			}
			$m   = ord( $jpeg[ $i + 1 ] );
			$len = ( ord( $jpeg[ $i + 2 ] ) << 8 ) | ord( $jpeg[ $i + 3 ] );
			if ( $m >= 0xC0 && $m <= 0xC2 ) {
				$h = ( ord( $jpeg[ $i + 5 ] ) << 8 ) | ord( $jpeg[ $i + 6 ] );
				$w = ( ord( $jpeg[ $i + 7 ] ) << 8 ) | ord( $jpeg[ $i + 8 ] );
				$c = ord( $jpeg[ $i + 9 ] );
				return ( $w > 0 && $h > 0 && 3 === $c ) ? array( 'w' => $w, 'h' => $h ) : null;
			}
			$i += 2 + $len;
		}
		return null;
	}

	private function font( string $face ): string {
		return array( 'regular' => 'F1', 'bold' => 'F2', 'italic' => 'F3' )[ $face ] ?? 'F1';
	}

	/** the check boxes Helvetica has no glyph for, drawn as lines. */
	const BOXES = array( "\u{2610}" => 'box0', "\u{2612}" => 'box1' );

	private function draw_text( float $x, float $baseline_from_top, string $text, string $face, float $size, array $rgb ): void {
		if ( 'box0' === $face || 'box1' === $face ) {
			$side = $size * 0.70;
			$bx   = $x + $size * 0.05;
			$by   = self::PAGE_H - $baseline_from_top - $size * 0.02;
			$this->buf .= sprintf( "q %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $size * 0.06, $bx, $by, $side, $side );
			if ( 'box1' === $face ) {
				$this->buf .= sprintf( "%.2F %.2F m %.2F %.2F l S\n%.2F %.2F m %.2F %.2F l S\n", $bx, $by, $bx + $side, $by + $side, $bx, $by + $side, $bx + $side, $by );
			}
			$this->buf .= "Q\n";
			// the text layer says which box is checked, in invisible text (render mode 3) over the box.
			// the render mode is part of the graphics state and outlives ET, so it is set back to 0 (fill)
			// in the same text object; 1.0.x left it at 3, and every word drawn after the boxes on that page was
			// invisible (the subcontractor disclaimer and the start of the Mechanics Lien Warning), though still in
			// the text layer
			$this->buf .= sprintf( "BT /F1 %.2F Tf 3 Tr %.3F %.3F Td (%s) Tj 0 Tr ET\n", $size * 0.6, $bx, self::PAGE_H - $baseline_from_top, 'box1' === $face ? '[X]' : '[ ]' );
			return;
		}
		$this->buf .= sprintf( "BT /%s %.2F Tf %.3F %.3F %.3F rg %.3F %.3F Td (%s) Tj ET\n",
			$this->font( $face ), $size, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, self::PAGE_H - $baseline_from_top, $this->esc( $text ) );
	}

	/** One line, not wrapped. $o: face (regular|bold|italic), bold, size, rgb, x, lead, align (left|center). */
	public function line( string $text, array $o = array() ): void {
		$face = $o['face'] ?? ( ! empty( $o['bold'] ) ? 'bold' : 'regular' );
		$size = (float) ( $o['size'] ?? 10.5 );
		$rgb  = $o['rgb'] ?? array( 0, 0, 0 );
		$lead = (float) ( $o['lead'] ?? $size * 1.35 );
		$x    = (float) ( $o['x'] ?? self::MARGIN );
		$this->ensure( $lead );
		if ( ( $o['align'] ?? 'left' ) === 'center' ) {
			$x = ( self::PAGE_W - $this->text_width( $text, $size, $face ) ) / 2;
		}
		$this->draw_text( $x, $this->y + $size * 0.80, $text, $face, $size, $rgb );
		$this->y += $lead;
	}

	/** A wrapped paragraph in one face. */
	public function paragraph( string $text, array $o = array() ): void {
		$face = $o['face'] ?? ( ! empty( $o['bold'] ) ? 'bold' : 'regular' );
		$this->runs( array( array( $text, $face ) ), $o );
	}

	/**
	 * A wrapped paragraph of runs: [ [ text, face ], ... ]. A "\n" in a run breaks the line. $o: size, x, width, lead, rgb.
	 */
	public function runs( array $runs, array $o = array() ): void {
		$size  = (float) ( $o['size'] ?? 10.5 );
		$x0    = (float) ( $o['x'] ?? self::MARGIN );
		$width = (float) ( $o['width'] ?? ( self::PAGE_W - self::MARGIN - $x0 ) );
		$lead  = (float) ( $o['lead'] ?? $size * 1.35 );
		$rgb   = $o['rgb'] ?? array( 0, 0, 0 );
		foreach ( $this->layout( $runs, $size, $width ) as $line ) {
			$this->ensure( $lead );
			$x = $x0;
			foreach ( $line as $piece ) {
				$this->draw_text( $x, $this->y + $size * 0.80, $piece[0], $piece[1], $size, $rgb );
				$x += $this->text_width( $piece[0], $size, $piece[1] );
			}
			$this->y += $lead;
		}
	}

	/** Lines of [text, face] pieces fitting $width. */
	public function layout( array $runs, float $size, float $width ): array {
		$words = array(); // [ text, face, newline_before ]
		foreach ( $runs as $r ) {
			$parts = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $r[0] ) );
			foreach ( $parts as $pi => $part ) {
				if ( $pi > 0 ) {
					$words[] = array( '', $r[1], true );
				}
				$toks = preg_split( '/( +|\x{2610}|\x{2612})/u', $part, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
				foreach ( (array) $toks as $t ) {
					$words[] = array( $t, self::BOXES[ $t ] ?? $r[1], false );
				}
			}
		}
		$lines = array();
		$cur   = array();
		$w     = 0.0;
		foreach ( $words as $wd ) {
			if ( $wd[2] ) {
				$lines[] = $cur;
				$cur     = array();
				$w       = 0.0;
				continue;
			}
			$tw = $this->text_width( $wd[0], $size, $wd[1] );
			if ( '' === trim( $wd[0] ) ) {
				if ( $cur ) {
					$cur[] = array( ' ', $wd[1] );
					$w    += $this->text_width( ' ', $size, $wd[1] );
				}
				continue;
			}
			if ( $w + $tw > $width && $cur ) {
				while ( $cur && ' ' === end( $cur )[0] ) {
					array_pop( $cur );
				}
				$lines[] = $cur;
				$cur     = array();
				$w       = 0.0;
			}
			if ( $tw > $width ) {
				foreach ( $this->hard_break( $wd[0], $size, $wd[1], $width ) as $i => $chunk ) {
					if ( $i > 0 ) {
						$lines[] = $cur;
						$cur     = array();
						$w       = 0.0;
					}
					$cur[] = array( $chunk, $wd[1] );
					$w    += $this->text_width( $chunk, $size, $wd[1] );
				}
				continue;
			}
			$cur[] = array( $wd[0], $wd[1] );
			$w    += $tw;
		}
		if ( $cur ) {
			$lines[] = $cur;
		}
		// merge neighbouring pieces of the same face (fewer text objects)
		$out = array();
		foreach ( $lines as $ln ) {
			$m = array();
			foreach ( $ln as $p ) {
				if ( $m && end( $m )[1] === $p[1] && 0 !== strpos( $p[1], 'box' ) ) {
					$m[ count( $m ) - 1 ][0] .= $p[0];
				} else {
					$m[] = $p;
				}
			}
			$out[] = $m;
		}
		return $out;
	}

	/**
	 * A table: $rows = [ [ cell, ... ], ... ], each cell a list of runs. $widths: fractions of the content width.
	 * The first row is a header when $o['header'] is true. Rows never split across pages (a tall row starts a page).
	 */
	public function table( array $rows, array $widths, array $o = array() ): void {
		$size  = max( 10.0, (float) ( $o['size'] ?? 10.0 ) );   // 10 points or more
		$lead  = $size * 1.3;
		$pad   = 4.0;
		$total = $this->content_width();
		$cols  = count( $widths );
		foreach ( $rows as $ri => $row ) {
			$cells = array();
			$h     = 0.0;
			for ( $c = 0; $c < $cols; $c++ ) {
				$cw      = $total * $widths[ $c ] - 2 * $pad;
				$lines   = $this->layout( (array) ( $row[ $c ] ?? array() ), $size, max( 20.0, $cw ) );
				$cells[] = $lines;
				$h       = max( $h, count( $lines ) * $lead );
			}
			$h += 2 * $pad;
			$this->ensure( $h );
			$top = $this->y;
			if ( 0 === $ri && ! empty( $o['header'] ) ) {
				$this->rect( self::MARGIN, $top, $total, $h, array( 236, 239, 243 ) );
			}
			$x = self::MARGIN;
			for ( $c = 0; $c < $cols; $c++ ) {
				$ly = $top + $pad;
				foreach ( $cells[ $c ] as $line ) {
					$lx = $x + $pad;
					foreach ( $line as $piece ) {
						$this->draw_text( $lx, $ly + $size * 0.80, $piece[0], $piece[1], $size, array( 0, 0, 0 ) );
						$lx += $this->text_width( $piece[0], $size, $piece[1] );
					}
					$ly += $lead;
				}
				$x += $total * $widths[ $c ];
			}
			$this->rect( self::MARGIN, $top + $h, $total, 0.6, array( 200, 205, 212 ) );
			$this->y = $top + $h + 0.6;
		}
		$this->y += 6.0;
	}

	public function rule( array $rgb = array( 210, 214, 220 ), float $pad = 8.0 ): void {
		$this->space( $pad );
		$this->ensure( 2.0 );
		$this->rect( self::MARGIN, $this->y, $this->content_width(), 0.8, $rgb );
		$this->space( $pad );
	}

	/* ---------------------------------------------------------------- output */

	public function output(): string {
		$this->flush_page();
		if ( ! $this->pages ) {
			$this->pages[] = '';
		}
		$n   = count( $this->pages );
		$obj = array();
		$obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
		$next   = 6;
		$kids   = array();
		$xobj   = '';
		if ( $this->paper && null !== $this->logo ) {
			// the logo, once, for every page: the JPEG's own bytes (DCTDecode)
			$iid         = $next++;
			$obj[ $iid ] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $this->logo['w'] . ' /Height ' . (int) $this->logo['h']
				. ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( (string) $this->logo['data'] ) . " >>\nstream\n" . $this->logo['data'] . "\nendstream";
			$xobj        = ' /XObject << /Im1 ' . $iid . ' 0 R >>';
		}
		foreach ( $this->pages as $i => $content ) {
			if ( $this->paper ) {
				$content .= $this->furniture( $i + 1, $n );
			} else {
				// the classic footer: at 10 points, a long legal name cut with an ellipsis
				$foot = sprintf( 'Page %d of %d', $i + 1, $n );
				if ( '' !== $this->footer ) {
					$foot = $this->fit_line( $this->footer, 10, 'regular', $this->content_width() - $this->text_width( '   ' . $foot, 10 ) ) . '   ' . $foot;
				}
				$content .= sprintf( "BT /F1 10.00 Tf 0.400 0.400 0.400 rg %.3F %.3F Td (%s) Tj ET\n", self::MARGIN, self::MARGIN - 20, $this->esc( $foot ) );
			}
			$cid       = $next++;
			$obj[ $cid ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
			$pid       = $next++;
			$obj[ $pid ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' . $xobj . ' >> /Contents ' . $cid . ' 0 R >>';
			$kids[]    = $pid . ' 0 R';
		}
		$info         = $next++;
		$obj[ $info ] = '<< /Title (' . $this->esc( $this->title ) . ') /Producer (Zorderz agreements ' . ZAGR_VERSION . ') >>';
		$obj[1]       = '<< /Type /Catalog /Pages 2 0 R >>';
		$obj[2]       = '<< /Type /Pages /Kids [ ' . implode( ' ', $kids ) . ' ] /Count ' . $n . ' >>';
		$max          = $next - 1;
		$out          = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$off          = array();
		for ( $i = 1; $i <= $max; $i++ ) {
			$off[ $i ] = strlen( $out );
			$out      .= $i . " 0 obj\n" . $obj[ $i ] . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $off[ $i ] );
		}
		return $out . 'trailer' . "\n<< /Size " . ( $max + 1 ) . ' /Root 1 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}


	/**
	 * a paper-mode page's furniture, marked /Artifact: after page one, the small logo, the running head and
	 * a thin stripe; on every page, a rule and the footer (its words at the left, "Page n of N" at the right), 10 points.
	 */
	private function furniture( int $page, int $of ): string {
		$keep      = $this->buf;
		$this->buf = '';
		$grey      = array( 74, 83, 92 );
		$w         = $this->content_width();
		if ( $page > 1 ) {
			$this->logo( self::MARGIN, 26, 48 );
			if ( '' !== $this->running ) {
				$this->text_at( self::MARGIN + $w, 41, $this->running, 'regular', 10, $grey, 'right' );
			}
			$this->stripe( self::MARGIN, 50, $w, 3, ZAGR_PDF_Paper::stripes() );
		}
		$this->hline( self::MARGIN, self::PAGE_H - 46, $w, array( 211, 216, 222 ), 0.8 );
		$pg = sprintf( 'Page %d of %d', $page, $of );
		if ( '' !== $this->footer ) {
			// never as far as "Page n of N" (a long legal name is cut, with an ellipsis)
			$this->text_at( self::MARGIN, self::PAGE_H - 32, $this->fit_line( $this->footer, 10, 'regular', $w - $this->text_width( $pg, 10 ) - 14 ), 'regular', 10, $grey );
		}
		$this->text_at( self::MARGIN + $w, self::PAGE_H - 32, $pg, 'regular', 10, $grey, 'right' );
		$out       = "/Artifact BMC\n" . $this->buf . "EMC\n";
		$this->buf = $keep;
		return $out;
	}

	private function flush_page(): void {
		if ( '' === $this->buf ) {
			return;
		}
		$this->pages[] = $this->buf;
		$this->buf     = '';
	}

	/* ---------------------------------------------------------------- text (as ZRCPT_PDF) */

	private function esc( string $s ): string {
		return strtr( $this->to_winansi( $s ), array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '' ) );
	}

	private function to_winansi( string $s ): string {
		if ( '' === $s || ! preg_match( '/[\x80-\xFF]/', $s ) ) {
			return $s;
		}
		$conv = false;
		if ( function_exists( 'iconv' ) ) {
			$conv = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
		}
		if ( false === $conv && function_exists( 'mb_convert_encoding' ) ) {
			$conv = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		}
		if ( false === $conv ) {
			$conv = preg_replace( '/[^\x20-\x7E]/', '', $s );
		}
		return (string) $conv;
	}

	/**
	 * $s on one line no wider than $max, cut at the end with an ellipsis when it must be. The
	 * width table knows the ellipsis (1000 units in Helvetica and Helvetica-Bold).
	 */
	public function fit_line( string $s, float $size, string $face, float $max ): string {
		if ( $this->text_width( $s, $size, $face ) <= $max ) {
			return $s;
		}
		$len = mb_strlen( $s, 'UTF-8' );
		while ( $len > 0 ) {
			$len--;
			$cut = rtrim( mb_substr( $s, 0, $len, 'UTF-8' ), " \t-," ) . "\u{2026}";
			if ( $this->text_width( $cut, $size, $face ) <= $max ) {
				return $cut;
			}
		}
		return '';
	}

	public function text_width( string $s, float $size, string $face = 'regular' ): float {
		if ( 'box0' === $face || 'box1' === $face ) {
			return $size * 0.80;
		}
		$s     = $this->to_winansi( $s );
		$w     = self::widths( 'bold' === $face );
		$total = 0;
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$total += $w[ ord( $s[ $i ] ) ] ?? 556; // Oblique shares Helvetica's widths
		}
		return $total / 1000.0 * $size;
	}

	private function hard_break( string $word, float $size, string $face, float $max ): array {
		$out = array();
		$cur = '';
		$len = mb_strlen( $word, 'UTF-8' );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = mb_substr( $word, $i, 1, 'UTF-8' );
			if ( $this->text_width( $cur . $ch, $size, $face ) > $max && '' !== $cur ) {
				$out[] = $cur;
				$cur   = $ch;
			} else {
				$cur .= $ch;
			}
		}
		if ( '' !== $cur ) {
			$out[] = $cur;
		}
		return $out;
	}

	/** Standard AFM widths for Helvetica and Helvetica-Bold, codes 32-126 (ZRCPT_PDF's table). */
	private static function widths( bool $bold ): array {
		static $reg = null, $bd = null;
		if ( null === $reg ) {
			$reg = array(
				32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,
				42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
				52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,
				62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
				72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,
				82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,
				92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,
				102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
				111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
				120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584, 133=>1000,
			);
			$bd = array(
				32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,
				42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
				52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,
				62=>584,63=>611,64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,
				72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,80=>667,81=>778,
				82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,
				92=>278,93=>333,94=>584,95=>556,96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,
				102=>333,103=>611,104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,
				111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,
				120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584, 133=>1000,
			);
		}
		return $bold ? $bd : $reg;
	}
}
