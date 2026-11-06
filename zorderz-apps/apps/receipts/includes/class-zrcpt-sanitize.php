<?php
/**
 * ZRCPT_Sanitize — makes model-written receipt HTML safe to store and serve.
 *
 * The Ai writes the receipt page, and the app serves it on its own origin (and shows
 * it to staff in a same-origin preview). So anything that can run script is removed,
 * and the result is rebuilt so that a browser reads it exactly the way this class did.
 *
 *   1. prepare: one pass with an HTML tokenizer that follows the browser's rules. The
 *      doctype, <title>, <style> blocks and stylesheet links to an allowed font host
 *      (none by default; `zrcpt_receipt_font_hosts`) are taken out and rebuilt from
 *      known-safe parts. Comments and raw-text elements (script, iframe, textarea,
 *      noscript, xmp, noembed, noframes, plaintext) are removed with their content, so
 *      script source never shows up as page text.
 *   2. wp_kses() with the receipt allowlist, when WordPress is loaded.
 *   3. strip_active(), the floor: the same tokenizer again. Every tag is written back in
 *      one canonical form (double-quoted, escaped values; only the first copy of an
 *      attribute, which is the one browsers use). Event handlers, srcdoc, script-capable
 *      URLs and unsafe inline CSS are removed, and dangerous elements are dropped. It
 *      runs after kses, and alone where kses is absent (standalone tests).
 *   4. The rebuilt head parts go at the very start of the document. They are never put
 *      back at positions the model chose (a model could place them inside an attribute
 *      value).
 *
 * The result ends in plain-text state, so a caller can safely append its own blocks to
 * the end. Idempotent: clean( clean( x ) ) === clean( x ).
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZRCPT_Sanitize {

	/** Raw-text elements (browsers do not read their content as markup): removed with their content. */
	const RAW = array(
		'script' => 1, 'style' => 1, 'title' => 1, 'textarea' => 1, 'xmp' => 1, 'iframe' => 1,
		'noembed' => 1, 'noframes' => 1, 'noscript' => 1, 'plaintext' => 1,
	);

	/** Elements whose tags are removed; any content between them stays and is sanitized. */
	const DROP = array(
		'object' => 1, 'embed' => 1, 'applet' => 1, 'frame' => 1, 'frameset' => 1, 'form' => 1,
		'input' => 1, 'button' => 1, 'select' => 1, 'option' => 1, 'optgroup' => 1, 'keygen' => 1,
		'isindex' => 1, 'base' => 1, 'link' => 1, 'param' => 1, 'portal' => 1, 'template' => 1,
		'slot' => 1, 'math' => 1, 'animate' => 1, 'animatemotion' => 1, 'animatetransform' => 1,
		'animatecolor' => 1, 'set' => 1, 'discard' => 1, 'foreignobject' => 1, 'use' => 1,
		'handler' => 1, 'listener' => 1, 'dialog' => 1,
	);

	/** Attributes that are always removed (on* handlers are removed separately). */
	const DROP_ATTRS = array( 'srcdoc' => 1, 'formaction' => 1, 'ping' => 1, 'http-equiv' => 1 );

	/** Attributes that hold a URL. */
	const URL_ATTRS = array(
		'href' => 1, 'src' => 1, 'action' => 1, 'poster' => 1, 'background' => 1, 'xlink:href' => 1,
		'data' => 1, 'cite' => 1, 'longdesc' => 1, 'manifest' => 1, 'codebase' => 1, 'archive' => 1,
		'classid' => 1, 'usemap' => 1, 'lowsrc' => 1, 'dynsrc' => 1, 'profile' => 1, 'icon' => 1,
	);

	const TAG_NAME  = '/^[a-zA-Z][a-zA-Z0-9-]*(?::[a-zA-Z][a-zA-Z0-9-]*)?$/';
	const ATTR_NAME = '/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/';
	const WS        = " \t\n\r\f";

	/** Clean model-written receipt HTML. */
	public static function clean( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		// Browser input preprocessing: newlines normalized, NULs and a leading BOM dropped.
		$html = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $html );
		if ( 0 === strncmp( $html, "\xEF\xBB\xBF", 3 ) ) {
			$html = substr( $html, 3 );
		}

		$head = array( 'doctype' => '', 'title' => null, 'blocks' => array() );
		$body = self::walk( $html, true, $head );

		if ( function_exists( 'wp_kses' ) ) {
			$props = array( __CLASS__, 'style_properties' );
			$parts = array( __CLASS__, 'allow_css_part' );
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'safe_style_css', $props );
				add_filter( 'safecss_filter_attr_allow_css', $parts, 10, 2 );
			}
			$protocols   = function_exists( 'wp_allowed_protocols' ) ? wp_allowed_protocols() : array( 'http', 'https', 'mailto', 'tel' );
			$protocols[] = 'data'; // raster data: images only; the floor turns every other data: URL into "#"
			$out = wp_kses( $body, self::allowed_html(), $protocols );
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'safe_style_css', $props );
				remove_filter( 'safecss_filter_attr_allow_css', $parts, 10 );
			}
			if ( is_string( $out ) && ( '' !== trim( $out ) || '' === trim( strip_tags( $body ) ) ) ) {
				$body = $out;
			} elseif ( function_exists( 'error_log' ) ) {
				// kses emptied a page that has text: treat that as a kses failure and let the
				// floor (safe on its own) do the work rather than blank the receipt.
				error_log( 'ZRCPT_Sanitize: wp_kses returned nothing for a page with text; used the floor alone.' );
			}
		}

		$body = ltrim( self::strip_active( $body ) );

		$top = array();
		if ( '' !== $head['doctype'] ) {
			$top[] = $head['doctype'];
		}
		if ( null !== $head['title'] ) {
			$top[] = '<title>' . $head['title'] . '</title>';
		}
		foreach ( $head['blocks'] as $block ) {
			$top[] = $block;
		}
		return empty( $top ) ? $body : implode( "\n", $top ) . "\n" . $body;
	}

	/** The floor on its own: canonical tags, nothing that can run. */
	public static function strip_active( string $html ): string {
		$unused = array();
		return self::walk( str_replace( "\0", '', $html ), false, $unused );
	}

	/** Receipt allowlist for wp_kses. Explicit context: WordPress adds no global attributes. */
	public static function allowed_html(): array {
		$common = array(
			'class' => true, 'id' => true, 'style' => true, 'title' => true, 'dir' => true, 'lang' => true,
			'role' => true, 'aria-label' => true, 'aria-hidden' => true, 'data-*' => true,
		);
		$tags = array(
			'html' => array( 'lang' => true, 'dir' => true ), 'head' => array(), 'body' => $common,
			'meta' => array( 'charset' => true, 'name' => true, 'content' => true ),
			'div' => $common, 'section' => $common, 'article' => $common, 'header' => $common, 'footer' => $common,
			'main' => $common, 'aside' => $common, 'nav' => $common,
			'h1' => $common, 'h2' => $common, 'h3' => $common, 'h4' => $common, 'h5' => $common, 'h6' => $common,
			'p' => $common, 'span' => $common, 'strong' => $common, 'b' => $common, 'em' => $common, 'i' => $common,
			'u' => $common, 'small' => $common, 'sub' => $common, 'sup' => $common, 'mark' => $common,
			'br' => array(), 'hr' => $common, 'wbr' => array(),
			'a' => $common + array( 'href' => true, 'target' => true, 'rel' => true ),
			'img' => $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'srcset' => true, 'sizes' => true, 'decoding' => true ),
			'picture' => $common, 'source' => array( 'srcset' => true, 'type' => true, 'media' => true, 'sizes' => true ),
			'ul' => $common, 'ol' => $common + array( 'start' => true, 'type' => true ), 'li' => $common,
			'dl' => $common, 'dt' => $common, 'dd' => $common,
			'table' => $common, 'thead' => $common, 'tbody' => $common, 'tfoot' => $common, 'tr' => $common,
			'td' => $common + array( 'colspan' => true, 'rowspan' => true ),
			'th' => $common + array( 'colspan' => true, 'rowspan' => true, 'scope' => true ),
			'caption' => $common, 'colgroup' => $common, 'col' => $common + array( 'span' => true ),
			'figure' => $common, 'figcaption' => $common, 'blockquote' => $common, 'pre' => $common, 'code' => $common,
			'details' => $common, 'summary' => $common,
			'svg' => $common + array( 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'xmlns' => true, 'preserveaspectratio' => true ),
			'path' => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'fill-rule' => true, 'clip-rule' => true ),
			'g' => $common, 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ),
			'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true ),
			'line' => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ),
			'polyline' => array( 'points' => true, 'fill' => true, 'stroke' => true ),
			'polygon' => array( 'points' => true, 'fill' => true, 'stroke' => true ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'zrcpt_receipt_kses_allowed', $tags );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$tags = $filtered;
			}
		}
		return $tags;
	}

	/** safe_style_css additions for the kses pass (layout and print properties receipts use). */
	public static function style_properties( $props ) {
		$more = array(
			'display', 'gap', 'row-gap', 'column-gap', 'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
			'flex-basis', 'align-items', 'align-content', 'align-self', 'justify-content', 'justify-items', 'justify-self',
			'order', 'grid-template-columns', 'grid-template-rows', 'grid-column', 'grid-row', 'grid-gap', 'grid-area',
			'place-items', 'object-fit', 'object-position', 'box-shadow', 'text-shadow', 'opacity', 'white-space',
			'word-break', 'overflow-wrap', 'word-wrap', 'hyphens', 'text-overflow', 'overflow', 'overflow-x', 'overflow-y',
			'box-sizing', 'page-break-inside', 'page-break-before', 'page-break-after', 'break-inside', 'break-before',
			'break-after', 'print-color-adjust', '-webkit-print-color-adjust', 'aspect-ratio', 'cursor', 'outline',
			'vertical-align', 'list-style-type', 'letter-spacing', 'text-transform', 'line-height', 'min-height', 'max-height',
			'transform', 'transition', 'filter', 'backdrop-filter', 'inset', 'z-index',
		);
		return array_values( array_unique( array_merge( (array) $props, $more ) ) );
	}

	/**
	 * safecss_filter_attr_allow_css: WordPress drops any inline declaration that
	 * contains "(" (rgba(), translate(), …), "\" or "&". Keep such a part when
	 * it passes this class's own CSS check.
	 */
	public static function allow_css_part( $allow, $part = '' ) {
		return $allow || self::style_ok( (string) $part );
	}

	/** Tokenize $s like a browser. $prepare: keep markup verbatim and collect head parts; else write canonical tags. */
	private static function walk( string $s, bool $prepare, array &$head ): string {
		$out  = '';
		$n    = strlen( $s );
		$i    = 0;
		$seen = false; // any content yet (the doctype is kept only when it comes first)
		while ( $i < $n ) {
			$lt   = strpos( $s, '<', $i );
			$stop = ( false === $lt ) ? $n : $lt;
			if ( $stop > $i ) {
				$text = substr( $s, $i, $stop - $i );
				$out .= $text;
				if ( '' !== trim( $text, self::WS ) ) {
					$seen = true;
				}
			}
			if ( false === $lt ) {
				break;
			}
			$i = $lt;
			$c = $s[ $i + 1 ] ?? '';

			if ( '!' === $c ) {
				if ( '--' === substr( $s, $i + 2, 2 ) ) { // comment: dropped
					$i = self::comment_end( $s, $i + 4 );
					continue;
				}
				// Doctype, CDATA or another markup declaration: ends at the first ">".
				if ( $prepare && ! $seen && 0 === strncasecmp( substr( $s, $i, 9 ), '<!doctype', 9 ) ) {
					$head['doctype'] = '<!DOCTYPE html>';
				}
				$seen = true;
				$i    = self::to_gt( $s, $i );
				continue;
			}
			if ( '?' === $c ) { // bogus comment
				$i = self::to_gt( $s, $i );
				continue;
			}
			if ( '/' === $c ) {
				$d = $s[ $i + 2 ] ?? '';
				if ( '' === $d ) {
					break; // "</" at the end of input
				}
				if ( '>' === $d ) { // "</>" is ignored by browsers
					$i += 3;
					continue;
				}
				if ( ! self::is_alpha( $d ) ) { // bogus comment
					$i = self::to_gt( $s, $i );
					continue;
				}
				$tok = self::read_tag( $s, $i + 2 );
				if ( null === $tok ) {
					break; // input ends inside the tag: browsers drop it
				}
				$ln = strtolower( $tok['name'] );
				if ( $prepare ) {
					if ( ! isset( self::RAW[ $ln ] ) && 'link' !== $ln ) {
						$out .= substr( $s, $i, $tok['end'] - $i );
					}
				} elseif ( ! isset( self::RAW[ $ln ] ) && ! isset( self::DROP[ $ln ] ) && preg_match( self::TAG_NAME, $tok['name'] ) ) {
					$out .= '</' . $tok['name'] . '>';
				}
				$i = $tok['end'];
				continue;
			}
			if ( ! self::is_alpha( $c ) ) { // a "<" that starts nothing is text
				$out .= '&lt;';
				$seen = true;
				$i++;
				continue;
			}

			$tok = self::read_tag( $s, $i + 1 );
			if ( null === $tok ) {
				break;
			}
			$seen = true;
			$ln   = strtolower( $tok['name'] );
			if ( isset( self::RAW[ $ln ] ) ) {
				list( $content, $end ) = self::raw_end( $s, $tok['end'], $ln );
				if ( $prepare ) {
					self::collect( $head, $ln, $tok, $content );
				}
				$i = $end;
				continue;
			}
			if ( $prepare ) {
				if ( 'link' === $ln ) {
					self::collect( $head, $ln, $tok, '' );
				} else {
					$out .= substr( $s, $i, $tok['end'] - $i );
				}
			} else {
				$out .= self::emit_tag( $tok );
			}
			$i = $tok['end'];
		}
		return $out;
	}

	/**
	 * Read one tag; $p is the first character of its name. Follows the HTML
	 * tokenizer's tag, attribute-name and attribute-value states. Returns
	 * [name, attrs => [[name, value|null], ...], self, end], or null when the
	 * input ends inside the tag (browsers drop such a tag).
	 */
	private static function read_tag( string $s, int $p ): ?array {
		$n     = strlen( $s );
		$len   = strcspn( $s, self::WS . '/>', $p );
		$name  = substr( $s, $p, $len );
		$p    += $len;
		$attrs = array();
		$self  = false;
		while ( true ) {
			$p += strspn( $s, self::WS, $p );
			if ( $p >= $n ) {
				return null;
			}
			$c = $s[ $p ];
			if ( '>' === $c ) {
				return array( 'name' => $name, 'attrs' => $attrs, 'self' => $self, 'end' => $p + 1 );
			}
			if ( '/' === $c ) {
				$p++;
				$self = ( $p < $n && '>' === $s[ $p ] );
				continue;
			}
			$self  = false;
			$start = $p;
			$p++; // the first character always belongs to the name (even "=")
			$p    += strcspn( $s, self::WS . '/>=', $p );
			$aname = substr( $s, $start, $p - $start );
			$p    += strspn( $s, self::WS, $p );
			if ( $p >= $n ) {
				return null;
			}
			if ( '=' !== $s[ $p ] ) {
				$attrs[] = array( $aname, null );
				continue;
			}
			$p++;
			$p += strspn( $s, self::WS, $p );
			if ( $p >= $n ) {
				return null;
			}
			$q = $s[ $p ];
			if ( '"' === $q || "'" === $q ) {
				$close = strpos( $s, $q, $p + 1 );
				if ( false === $close ) {
					return null;
				}
				$attrs[] = array( $aname, substr( $s, $p + 1, $close - $p - 1 ) );
				$p       = $close + 1;
				continue;
			}
			if ( '>' === $q ) {
				$attrs[] = array( $aname, '' );
				continue;
			}
			$vlen    = strcspn( $s, self::WS . '>', $p );
			$attrs[] = array( $aname, substr( $s, $p, $vlen ) );
			$p      += $vlen;
		}
	}

	/** Content of a raw-text element and the position after its end tag (or the end of input). */
	private static function raw_end( string $s, int $p, string $name ): array {
		$n = strlen( $s );
		if ( 'plaintext' !== $name ) {
			$from = $p;
			while ( false !== ( $q = stripos( $s, '</' . $name, $from ) ) ) {
				$after = $q + 2 + strlen( $name );
				if ( $after < $n && false !== strpos( self::WS . '/>', $s[ $after ] ) ) {
					$tok = self::read_tag( $s, $q + 2 );
					return array( substr( $s, $p, $q - $p ), null === $tok ? $n : $tok['end'] );
				}
				$from = $q + 2;
			}
		}
		return array( (string) substr( $s, $p ), $n );
	}

	/** Position after a comment that starts at $p (just past "<!--"). */
	private static function comment_end( string $s, int $p ): int {
		$n = strlen( $s );
		if ( $p < $n && '>' === $s[ $p ] ) {
			return $p + 1; // "<!-->"
		}
		if ( '->' === substr( $s, $p, 2 ) ) {
			return $p + 2; // "<!--->"
		}
		$a = strpos( $s, '-->', $p );
		$b = strpos( $s, '--!>', $p );
		if ( false === $a && false === $b ) {
			return $n;
		}
		if ( false === $b || ( false !== $a && $a < $b ) ) {
			return $a + 3;
		}
		return $b + 4;
	}

	private static function to_gt( string $s, int $p ): int {
		$gt = strpos( $s, '>', $p );
		return false === $gt ? strlen( $s ) : $gt + 1;
	}

	/** Keep the first <title>, every <style> and stylesheet links to an allowed font host, rebuilt from safe parts. */
	private static function collect( array &$head, string $ln, array $tok, string $content ): void {
		if ( 'title' === $ln ) {
			if ( null === $head['title'] ) {
				$head['title'] = self::esc( html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			return;
		}
		if ( 'style' === $ln ) {
			$media = self::attr( $tok, 'media' );
			$m     = ( null !== $media && preg_match( '/^[a-zA-Z0-9 ,():.\-]{1,200}$/', $media ) ) ? ' media="' . self::esc( $media ) . '"' : '';
			// $content cannot contain "</style" followed by a space, "/" or ">" (raw_end stops there).
			$head['blocks'][] = '<style' . $m . '>' . $content . '</style>';
			return;
		}
		if ( 'link' === $ln ) {
			$rel  = strtolower( trim( (string) self::attr( $tok, 'rel' ) ) );
			$href = trim( (string) self::attr( $tok, 'href' ) );
			if ( 'stylesheet' === $rel && self::font_link_ok( $href ) ) {
				$head['blocks'][] = '<link rel="stylesheet" href="' . self::esc( $href ) . '">';
			}
		}
	}

	/**
	 * Hosts a receipt may load a font stylesheet from. None by default: a third-party font
	 * host learns every customer who opens the receipt. A business may allow one (each entry
	 * a bare host name, e.g. 'fonts.example.com'); the receipt CSP allows the same hosts.
	 *
	 * @return string[]
	 */
	public static function font_hosts(): array {
		$hosts = function_exists( 'apply_filters' ) ? apply_filters( 'zrcpt_receipt_font_hosts', array() ) : array();
		$out   = array();
		foreach ( (array) $hosts as $h ) {
			$h = strtolower( trim( (string) $h ) );
			if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h ) ) {
				$out[] = $h;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** An https stylesheet URL on an allowed font host, with no characters that could break out. */
	private static function font_link_ok( string $href ): bool {
		foreach ( self::font_hosts() as $h ) {
			if ( preg_match( '#^https://' . preg_quote( $h, '#' ) . '/[A-Za-z0-9/?&=:;,.+%@_~-]*$#', $href ) ) {
				return true;
			}
		}
		return false;
	}

	/** First value of an attribute (decoded), '' for a valueless one, null when absent. */
	private static function attr( array $tok, string $name ): ?string {
		foreach ( $tok['attrs'] as $a ) {
			if ( strtolower( $a[0] ) === $name ) {
				return null === $a[1] ? '' : html_entity_decode( $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return null;
	}

	/** One tag in canonical form, or '' when the element is dropped. */
	private static function emit_tag( array $tok ): string {
		$name = $tok['name'];
		$ln   = strtolower( $name );
		if ( ! preg_match( self::TAG_NAME, $name ) || isset( self::DROP[ $ln ] ) ) {
			return '';
		}
		if ( 'meta' === $ln && null !== self::attr( $tok, 'http-equiv' ) ) {
			return ''; // refresh / set-cookie / CSP overrides
		}
		$out  = '<' . $name;
		$seen = array();
		foreach ( $tok['attrs'] as $a ) {
			list( $an, $av ) = $a;
			$la = strtolower( $an );
			if ( isset( $seen[ $la ] ) ) {
				continue; // browsers keep only the first copy
			}
			$seen[ $la ] = true;
			if ( ! preg_match( self::ATTR_NAME, $an ) || 0 === strncmp( $la, 'on', 2 ) || isset( self::DROP_ATTRS[ $la ] ) ) {
				continue;
			}
			if ( null === $av ) {
				$out .= ' ' . $an;
				continue;
			}
			$v = html_entity_decode( $av, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( isset( self::URL_ATTRS[ $la ] ) && ! self::url_ok( $v ) ) {
				$v = '#';
			}
			if ( 'style' === $la && ! self::style_ok( $v ) ) {
				continue;
			}
			$out .= ' ' . $an . '="' . self::esc( $v ) . '"';
		}
		return $out . ( $tok['self'] ? ' /' : '' ) . '>';
	}

	/** Relative URLs and http(s)/mailto/tel/sms are fine; data: only for raster images. */
	public static function url_ok( string $v ): bool {
		$u = strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $v ) );
		if ( ! preg_match( '/^([a-z][a-z0-9+.\-]*):/', $u, $m ) ) {
			return true;
		}
		if ( in_array( $m[1], array( 'http', 'https', 'mailto', 'tel', 'sms' ), true ) ) {
			return true;
		}
		return 'data' === $m[1] && (bool) preg_match( '#^data:image/(png|jpe?g|gif|webp|avif|bmp)[;,]#', $u );
	}

	/** Inline CSS with nothing script-like in it (entities and CSS escapes decoded first). */
	public static function style_ok( string $css ): bool {
		$css = html_entity_decode( $css, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$css = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', $css );
		$css = (string) preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/su',
			static function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					$cp = hexdec( $m[1] );
					return self::utf8_chr( (int) $cp );
				}
				return $m[2];
			},
			$css
		);
		$css = strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $css ) );
		if ( preg_match( '/expression|javascript:|vbscript:|livescript:|behavior:|-moz-binding|@import|<\/?style/', $css ) ) {
			return false;
		}
		if ( preg_match_all( '/url\(["\']?([^"\')]*)/', $css, $urls ) ) {
			foreach ( $urls[1] as $url ) {
				if ( ! self::url_ok( $url ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** ASCII letter test without the ctype extension. */
	private static function is_alpha( string $c ): bool {
		return 1 === strlen( $c ) && ( ( $c >= 'a' && $c <= 'z' ) || ( $c >= 'A' && $c <= 'Z' ) );
	}

	/** UTF-8 for a code point, without mbstring ('' for invalid ones). */
	private static function utf8_chr( int $cp ): string {
		if ( $cp <= 0 || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
			return '';
		}
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	private static function esc( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
}
