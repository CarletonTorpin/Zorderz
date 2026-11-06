<?php
/**
 * ZANA_Plain_Math — turns LaTeX math in a model answer into plain text the chat can show.
 *
 * The chat renders text, not LaTeX, so an answer that writes its arithmetic the way models are trained to --
 *     ($12" + 12" = 24"$, height $20" \le 30"$)
 *     $$\frac{\$1,200.00}{36,000 \text{ sq in}} \approx \$0.03333 \text{ per sq in}$$
 * would reach the reader as raw dollar signs and backslash commands. This rewrites it as the plain line a person
 * would write:
 *     (12" + 12" = 24", height 20" ≤ 30")
 *     $1,200.00 ÷ 36,000 sq in ≈ $0.03333 per sq in
 *
 * MONEY IS NOT MATH. "$" is also the currency sign, so inline $…$ is converted only when it is closed the way LaTeX
 * closes it (no space just inside either "$", the closing "$" not followed by a digit) AND the inside reads as math (a
 * backslash command, or an operator such as = + × ≤ ^ between terms). "between $500 and $700", "$1,200 for the roll
 * and $28.00 a sheet", "$5-$10" are left alone. Code fences, inline code, <pre> blocks and app markers ([ZDZ_…],
 * [ZEST_…] with their payloads) are never touched; a regex failure leaves the answer as it was.
 *
 * The prompt also asks for plain-text math; this is the deterministic backstop for when the model writes LaTeX anyway.
 * Pure; no WordPress.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Plain_Math {

	/** Commands that become a symbol. Longest names first where one is a prefix of another. */
	const SYMBOLS = array(
		'\\leqslant' => '≤', '\\geqslant' => '≥', '\\times' => '×', '\\cdot' => '·', '\\div' => '÷', '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥',
		'\\ge' => '≥', '\\neq' => '≠', '\\ne' => '≠', '\\approx' => '≈', '\\sim' => '~', '\\pm' => '±',
		'\\mp' => '∓', '\\rightarrow' => '→', '\\Rightarrow' => '⇒', '\\to' => '→', '\\leftarrow' => '←',
		'\\infty' => '∞', '\\degree' => '°', '\\circ' => '°', '\\pi' => 'π', '\\Delta' => 'Δ', '\\delta' => 'δ',
		'\\%' => '%', '\\&' => '&', '\\#' => '#', '\\_' => '_', '\\{' => '(', '\\}' => ')',
		'\\ldots' => '…', '\\dots' => '…', '\\cdots' => '…',
		'\\lt' => '<', '\\gt' => '>', '\\quad' => ' ', '\\qquad' => ' ',
	);

	/** Superscript digits for ^2, ^{3}, … */
	const SUPERS = array( '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶',
		'7' => '⁷', '8' => '⁸', '9' => '⁹', '-' => '⁻' );

	/** A cheap test: could this answer hold LaTeX at all? */
	public static function might_have_math( string $text ): bool {
		return false !== strpos( $text, '$' ) || false !== strpos( $text, '\\(' ) || false !== strpos( $text, '\\[' );
	}

	/**
	 * The answer with every LaTeX math span written as plain text. Returns the input unchanged when there
	 * is nothing to convert, so it is safe on every answer.
	 */
	public static function apply( string $text ): string {
		if ( '' === $text || ( ! self::might_have_math( $text ) && false === strpos( $text, '\\' ) ) ) {
			return $text;
		}
		$out = '';
		foreach ( self::segments( $text ) as $seg ) {
			$out .= $seg[1] ? $seg[0] : self::convert_text( $seg[0] );
		}
		return '' === trim( $out ) && '' !== trim( $text ) ? $text : $out;
	}

	/**
	 * Splits the answer into [text, protected] pieces. Protected pieces are kept byte for byte: fenced code
	 * (``` / ~~~), <pre> blocks, inline `code`, and every app marker with its payload, attributes included
	 * ([ZDZ_EMAIL_DRAFT:…], [ZDZ_ACTION]…[/ZDZ_ACTION]). An opener with no closer protects the rest of the answer.
	 * A linear walk (strpos per opener), so an answer with thousands of unclosed tags cannot go quadratic.
	 */
	private static function segments( string $t ): array {
		$segs = array();
		$at   = 0;
		$len  = strlen( $t );
		while ( $at < $len ) {
			if ( ! preg_match( '/```|~~~|<pre\b|`|\[(Z[A-Z0-9]*_[A-Z0-9_]+)(?::[^\]\n]*)?\]/i', $t, $m, PREG_OFFSET_CAPTURE, $at ) ) {
				break;
			}
			$open  = (int) $m[0][1];
			$token = (string) $m[0][0];
			$body  = $open + strlen( $token );
			if ( '```' === $token || '~~~' === $token ) {
				$close = strpos( $t, $token, $body );
				$end   = false === $close ? $len : $close + 3;
			} elseif ( 0 === stripos( $token, '<pre' ) ) {
				$close = stripos( $t, '</pre>', $body );
				$end   = false === $close ? $len : $close + 6;
			} elseif ( '`' === $token ) {
				$close = strpos( $t, '`', $body );
				$nl    = strpos( $t, "\n", $body );
				if ( false === $close || ( false !== $nl && $nl < $close ) ) {
					// A lone backtick is just a character: keep it in the text and look further on.
					$segs[] = array( substr( $t, $at, $body - $at ), false );
					$at     = $body;
					continue;
				}
				$end = $close + 1;
			} else {
				$close = strpos( $t, '[/' . $m[1][0] . ']', $body );
				$end   = false === $close ? $len : $close + strlen( '[/' . $m[1][0] . ']' );
			}
			if ( $open > $at ) {
				$segs[] = array( substr( $t, $at, $open - $at ), false );
			}
			$segs[] = array( substr( $t, $open, $end - $open ), true );
			$at     = $end;
		}
		if ( $at < $len ) {
			$segs[] = array( substr( $t, $at ), false );
		}
		// Merge neighbouring text pieces (a lone backtick split one).
		$merged = array();
		foreach ( $segs as $sg ) {
			$k = count( $merged ) - 1;
			if ( $k >= 0 && ! $sg[1] && ! $merged[ $k ][1] ) {
				$merged[ $k ][0] .= $sg[0];
			} else {
				$merged[] = $sg;
			}
		}
		return $merged;
	}

	/** Converts the math in one stretch of unprotected answer text. */
	private static function convert_text( string $t ): string {
		if ( false !== strpos( $t, '$' ) || false !== strpos( $t, '\\(' ) || false !== strpos( $t, '\\[' ) ) {
			// Display math $$…$$: math inside, and a block over several lines must carry a LaTeX command
			// (so "affluence $$ = mid … tier $$$" is not paired up).
			$t = self::rc( '/(?<!\$)\$\$(?!\$)(.+?)(?<!\$)\$\$(?!\$)/s', function ( $m ) {
				$in  = (string) $m[1];
				$cmd = (bool) preg_match( '/\\\\[a-zA-Z]{2,}/', $in );
				if ( ( false !== strpos( $in, "\n" ) && ! $cmd ) || ( ! $cmd && ! self::looks_like_math( $in ) ) ) {
					return $m[0];
				}
				return self::latex_to_plain( $in );
			}, $t );
			// \[…\] and \(…\): only when math is inside (a markdown-escaped "\[1\]" is a bracket, not math).
			foreach ( array( '/\\\\\[(.+?)\\\\\]/s', '/\\\\\((.+?)\\\\\)/s' ) as $re ) {
				$t = self::rc( $re, function ( $m ) {
					return self::looks_like_math( (string) $m[1] ) ? self::latex_to_plain( (string) $m[1] ) : $m[0];
				}, $t );
			}
			$t = self::inline_dollars( $t );
		}
		// A bare command in prose ("28 \times 60" with no dollar signs) → its symbol.
		$t = self::rc( '/\\\\(times|div|cdot|approx|leqslant|geqslant|leq|geq|le|ge|neq|pm)(?![a-zA-Z])/', function ( $m ) {
			$map = array( 'times' => '×', 'div' => '÷', 'cdot' => '·', 'approx' => '≈', 'leqslant' => '≤', 'geqslant' => '≥',
				'leq' => '≤', 'geq' => '≥', 'le' => '≤', 'ge' => '≥', 'neq' => '≠', 'pm' => '±' );
			return $map[ $m[1] ];
		}, $t );
		return $t;
	}

	/**
	 * Inline $…$, walked as pairs of unescaped single dollar signs. A pair that is not math is skipped ONE
	 * sign at a time, so "$1,000 for 42 sheets = ($\frac{1000}{42}$)" keeps its money and still converts
	 * the fraction (a regex match would have consumed the second sign).
	 */
	private static function inline_dollars( string $t ): string {
		if ( ! preg_match_all( '/(?<![\\\\$])\$(?!\$)/', $t, $mm, PREG_OFFSET_CAPTURE ) ) {
			return $t;
		}
		$pos  = array_map( function ( $x ) { return (int) $x[1]; }, $mm[0] );
		$n    = count( $pos );
		$reps = array();
		for ( $i = 0; $i + 1 < $n; ) {
			$o = $pos[ $i ];
			$c = $pos[ $i + 1 ];
			if ( self::is_math_pair( $t, $o, $c ) ) {
				$reps[] = array( $o, $c, self::latex_to_plain( substr( $t, $o + 1, $c - $o - 1 ) ) );
				$i += 2;
			} else {
				$i += 1;
			}
		}
		for ( $k = count( $reps ) - 1; $k >= 0; $k-- ) {
			$t = substr( $t, 0, $reps[ $k ][0] ) . $reps[ $k ][2] . substr( $t, $reps[ $k ][1] + 1 );
		}
		return $t;
	}

	/** Is the text between the dollar signs at $o and $c an inline LaTeX span? */
	private static function is_math_pair( string $t, int $o, int $c ): bool {
		$inner = (string) substr( $t, $o + 1, $c - $o - 1 );
		if ( '' === $inner || strlen( $inner ) > 200 || false !== strpos( $inner, "\n" ) ) {
			return false;
		}
		$before = $o > 0 ? $t[ $o - 1 ] : ' ';
		$after  = $c + 1 < strlen( $t ) ? $t[ $c + 1 ] : ' ';
		// Opened and closed the LaTeX way: no space just inside, not glued to a word before, not a digit after.
		if ( preg_match( '/[\w]/', $before ) || ctype_space( $inner[0] ) || ctype_space( substr( $inner, -1 ) ) ) {
			return false;
		}
		if ( preg_match( '/[\d\/]/', $after ) ) {
			return false; // "$1,200/$1,500", "$5-$10"
		}
		// Unit labels: "($)", "($/hr)", "Price ($) | Qty ($)" — a sign that sits against a bracket or slash.
		if ( preg_match( '/^[)\/\]|]/', $inner ) || preg_match( '/[(\[|]$/', $inner ) ) {
			return false;
		}
		$cmd = (bool) preg_match( '/\\\\[a-zA-Z]{2,}|\\\\[%$#&,;]/', $inner );
		// Money-shaped without a LaTeX command: "$1,200 ÷ 36,000 = 0.0333 (", "$28.00 × 4 = (", "$X×10%)+".
		$grp = (bool) preg_match( '/[{}]|[_^]\{/', $inner );
		if ( ! $cmd && ! $grp && ( preg_match( '/^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?(?![0-9])/', $inner )
			|| preg_match( '/^[0-9]+\.[0-9]{2}(?![0-9])/', $inner )
			|| preg_match( '/^[A-Z](?![a-z])/', $inner ) ) ) {
			return false;
		}
		return $cmd || self::looks_like_math( $inner );
	}

	/** Does the inside of a $…$ read as math rather than as two money amounts with words between? */
	public static function looks_like_math( string $inner ): bool {
		$inner = trim( $inner );
		if ( '' === $inner ) {
			return false;
		}
		if ( preg_match( '/\\\\[a-zA-Z]+|\\\\[%$#&]|[\^_]\{|[{}]/', $inner ) ) {
			return true; // a LaTeX command, an escaped symbol, a sub/superscript group or braces
		}
		// A lone measurement: $14"$, $5.5"$, $90°$. Money never ends in an inch, foot or degree mark.
		if ( preg_match( '/^[0-9][0-9,.]*\s*(?:"|\'\'|\'|°)$/u', $inner ) ) {
			return true;
		}
		// A lone lowercase variable or a subscripted one: $x$, $x_1$, $a^2$.
		if ( preg_match( '/^[a-z](?:[_^][a-zA-Z0-9]+)?$/', $inner ) ) {
			return true;
		}
		// An operator between two terms: 14" + 14" = 28", 3 - 2, 1/2, 2*3, a ≤ b, x^2.
		return (bool) preg_match( '/[\w"\')\]]\s*(?:=|\+|×|≤|≥|<|>|\*|\^|÷|\/|\s-\s)\s*[\w"(\-$.]/u', $inner );
	}

	/** One LaTeX math body as plain text. */
	public static function latex_to_plain( string $tex ): string {
		$s = $tex;
		// Environments and alignment: \begin{aligned} … \end{aligned}, & column marks, \\ line breaks.
		if ( preg_match( '/\\\\begin\{|\\\\\\\\/', $s ) ) {
			$s = self::re( '/\\\\(?:begin|end)\{[a-zA-Z*]+\}/', '', $s );
			$s = self::re( '/\\\\\\\\/', "\n", $s );
			$s = str_replace( '&', '', $s ); // alignment marks only inside an environment or a multi-line block
		}
		// \boxed{x}, \overline{x}, \underline{x}, \hat{x} → x; the \frac12 and \sqrt2 shorthands.
		$s = self::re( '/\\\\(?:boxed|overline|underline|hat|bar|widehat|tilde)\s*(?=\{)/', '\\text', $s );
		$s = self::re( '/\\\\[dt]?frac\s*([0-9])\s*([0-9])/', '$1 ÷ $2', $s );
		$s = self::re( '/\\\\sqrt\s*([0-9]+)/', '√$1', $s );
		$s = strtr( $s, array( '\\lceil' => '⌈', '\\rceil' => '⌉', '\\lfloor' => '⌊', '\\rfloor' => '⌋' ) );
		// Escaped dollar first, so a "$" inside never reads as a delimiter again.
		$s = str_replace( '\\$', "\x01", $s );
		// Sizing that carries no meaning in plain text.
		$s = self::re( '/\\\\(?:left|right|big|Big|bigg|Bigg)(?=[\s(\[|.)\]\\\\])\.?/', '', $s );
		$s = self::re( '/\\\\displaystyle|\\\\limits|\\\\nolimits/', '', $s );
		// Commands with arguments, innermost first (the brace pattern is recursive).
		$arg = '(\{(?:[^{}]++|(?-1))*\})';
		for ( $guard = 0; $guard < 8; $guard++ ) {
			$before = $s;
			// \frac{a}{b} (and \dfrac, \tfrac) → a ÷ b, parenthesised when a side is itself a sum or product.
			$s = self::rc( '/\\\\[dt]?frac\s*' . $arg . '\s*' . $arg . '/', function ( $m ) {
				$a = self::latex_to_plain( substr( $m[1], 1, -1 ) );
				$b = self::latex_to_plain( substr( $m[2], 1, -1 ) );
				return self::paren( $a ) . ' ÷ ' . self::paren( $b );
			}, $s );
			// \sqrt{x} → √x or √(x).
			$s = self::rc( '/\\\\sqrt\s*' . $arg . '/', function ( $m ) {
				$x = self::latex_to_plain( substr( $m[1], 1, -1 ) );
				return '√' . ( preg_match( '/^[\w.,]+$/u', $x ) ? $x : '(' . $x . ')' );
			}, $s );
			// Bold → markdown bold; text / roman / italic → the words.
			$s = self::rc( '/\\\\(?:mathbf|textbf|boldsymbol|bm)\s*' . $arg . '/', function ( $m ) {
				$x = trim( self::latex_to_plain( substr( $m[1], 1, -1 ) ) );
				return '' === $x ? '' : '**' . $x . '**';
			}, $s );
			$s = self::rc( '/\\\\(?:text|textrm|textit|textsf|texttt|mathrm|mathit|mathsf|mathtt|operatorname|mbox|hbox|emph)\s*' . $arg . '/', function ( $m ) {
				return self::latex_to_plain( substr( $m[1], 1, -1 ) );
			}, $s );
			// Powers: ^2 / ^{2} → ², other ^{x} → ^x. Subscripts: _{x} / _x → (x)… kept readable, never "_".
			$s = self::rc( '/\^\s*(?:\{([^{}]*)\}|([0-9]))/', function ( $m ) {
				$x = '' !== (string) ( $m[1] ?? '' ) ? (string) $m[1] : (string) ( $m[2] ?? '' );
				$x = trim( self::latex_to_plain( $x ) );
				if ( preg_match( '/^-?[0-9]+$/', $x ) ) {
					return strtr( $x, self::SUPERS );
				}
				return '^' . ( preg_match( '/^[\w.]+$/u', $x ) ? $x : '(' . $x . ')' );
			}, $s );
			$s = self::rc( '/_\s*(?:\{([^{}]*)\}|([a-zA-Z0-9]))/', function ( $m ) {
				$x = '' !== (string) ( $m[1] ?? '' ) ? (string) $m[1] : (string) ( $m[2] ?? '' );
				return '(' . trim( self::latex_to_plain( $x ) ) . ')';
			}, $s );
			if ( $before === $s ) {
				break;
			}
		}
		// Symbols (longest command names first, so \leq is not read as \le + "q").
		$sym = self::SYMBOLS;
		uksort( $sym, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		foreach ( $sym as $cmd => $plain ) {
			$s = self::re( '/' . preg_quote( $cmd, '/' ) . '(?![a-zA-Z])/', $plain, $s );
		}
		// Spacing commands: \, \; \: \! and a backslash-space.
		$s = self::re( '/\\\\[,;:!> ]/', ' ', $s );
		// Any other command keeps its name as a word (\max → max); stray braces go.
		$s = self::re( '/\\\\([a-zA-Z]+)/', '$1', $s );
		$s = str_replace( array( '{', '}' ), '', $s );
		$s = str_replace( "\x01", '$', $s );
		// Tidy the spacing the source used for layout.
		$s = self::re( '/[ \t]+/', ' ', $s );
		$s = self::re( '/ *\n */', "\n", $s );
		$s = self::re( '/([(⌈⌊]) /u', '$1', $s );
		$s = self::re( '/ ([⌉⌋])/u', '$1', $s );
		$s = self::re( '/\( /', '(', $s );
		$s = self::re( '/ \)/', ')', $s );
		return trim( $s );
	}

	/** preg_replace that never blanks the text: on a regex failure it returns the input unchanged. */
	private static function re( $pattern, $replacement, string $subject ): string {
		$r = preg_replace( $pattern, $replacement, $subject );
		return is_string( $r ) ? $r : $subject;
	}

	/** preg_replace_callback that never blanks the text: on a regex failure it returns the input unchanged. */
	private static function rc( $pattern, callable $cb, string $subject ): string {
		$r = preg_replace_callback( $pattern, $cb, $subject );
		return is_string( $r ) ? $r : $subject;
	}

	/** Wrap a fraction side in parentheses when it is a sum, difference or product of terms. */
	private static function paren( string $x ): string {
		$x = trim( $x );
		// Any operator inside a side groups it: "28+2", "$1,000-$200", "40×25". A leading minus does not.
		return preg_match( '/[+×·÷*\/]|(?<=[^\s(])\s*-/u', $x ) ? '(' . $x . ')' : $x;
	}
}
