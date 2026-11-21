<?php
/**
 * ZANA_Web_Vetting — when a fact found on the web may be called confirmed.
 *
 * THE RULE. A fact a web search turned up is a first finding, not a fact. It is confirmed only when several sites of
 * different owners say the same thing and the server has read the words on each page. Until then it is reported as
 * what it is: found once, disputed, or not checked.
 *
 * WHO DECIDES. A model may FIND things (claims, addresses, quotes). It never decides whether they are true. That is
 * decided here, by rule:
 *
 *   1. A piece of evidence counts only when its quote is on the page at its address, as the server read it (never as
 *      a model remembers it); or, for a claim with figures, when every figure of the claim is on that page close to
 *      one of the claim's words.
 *   2. Sources are counted by OWNER: the registrable domain, with one brand's sister sites folded together and any
 *      groups an administrator has declared. Two pages of one owner are one source.
 *   3. CONFIRMED needs at least min_sources() owners (never fewer than 2) that agree, at least one of which is not
 *      the subject of the claim: a company repeating its own figure on two of its own sites is one self-report.
 *   4. One checked source giving a different figure makes the claim DISPUTED, whatever else agrees. A disagreement is
 *      never averaged away.
 *
 * Nothing here calls a model, reads a page or writes to the database: the research run does those, and hands this
 * class what it found. So the rules can be tested without either.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Web_Vetting {

	const V_VERIFIED    = 'verified';
	const V_CONTESTED   = 'contested';
	const V_SINGLE      = 'single_source';
	const V_THIN        = 'thin';
	const V_UNCONFIRMED = 'unconfirmed';
	const V_NOT_CHECKED = 'not_checked';

	/** Pages read for one claim at most (each can take the page reader's whole time limit). */
	const MAX_PAGES_PER_CLAIM = 5;

	/**
	 * Endings under which a site's own name is the THIRD label from the right. Short on purpose: the question is "is
	 * this the same site", not a full list of public suffixes. A miss only ever folds two owners into one (stricter),
	 * never splits one owner in two.
	 */
	const MULTI_SUFFIXES = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'me.uk',
		'com.au', 'net.au', 'org.au', 'gov.au', 'edu.au',
		'co.nz', 'org.nz', 'govt.nz', 'co.jp', 'or.jp', 'ne.jp',
		'com.br', 'com.mx', 'co.za', 'co.in', 'gc.ca',
	);

	/** Words in a company's name that say nothing about who owns a site. */
	const GENERIC_WORDS = array(
		'company', 'companies', 'corporation', 'corporate', 'incorporated', 'limited', 'holdings', 'services', 'service',
		'solutions', 'group', 'international', 'industries', 'franchise', 'franchising', 'america', 'american', 'national',
		'global', 'systems', 'partners', 'brands',
	);

	/* ================================================================== settings */

	private static function setting( string $key, $default ) {
		return class_exists( 'ZANA_Research_Settings' ) ? ZANA_Research_Settings::get( $key ) : $default;
	}

	public static function enabled(): bool {
		return (bool) self::setting( 'vetting', true );
	}

	/** Owners that must agree for CONFIRMED. Never below 2: one source confirms nothing. */
	public static function min_sources(): int {
		return max( 2, min( 5, (int) self::setting( 'min_sources', 2 ) ) );
	}

	/** Most claims one run checks (each costs a paid search). */
	public static function max_claims(): int {
		return max( 1, min( 12, (int) self::setting( 'max_claims', 6 ) ) );
	}

	/* ================================================================== whose site */

	/** The host of an http(s) address; '' for anything else, a bare host or a number address (neither names a publisher). */
	public static function host_of( $url ): string {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$h = strtolower( rtrim( (string) parse_url( $url, PHP_URL_HOST ), '.' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- one component of an address already matched as http(s); wp_parse_url() adds nothing here and is not loaded in every context this class runs in
		if ( '' === $h || false === strpos( $h, '.' ) || preg_match( '/^[\d.]+$/', $h ) || false !== strpos( $h, ':' ) ) {
			return '';
		}
		return $h;
	}

	/** The site's own domain: https://www.news.example.co.uk/x → example.co.uk; a host with two labels is itself. */
	public static function registrable_domain( $url_or_host ): string {
		$h = false !== strpos( (string) $url_or_host, '://' ) ? self::host_of( $url_or_host ) : strtolower( rtrim( trim( (string) $url_or_host ), '.' ) );
		if ( '' === $h || ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $h ) || preg_match( '/^[\d.]+$/', $h ) ) {
			return '';
		}
		$labels = explode( '.', $h );
		$n      = count( $labels );
		if ( $n <= 2 ) {
			return $h;
		}
		$last2 = $labels[ $n - 2 ] . '.' . $labels[ $n - 1 ];
		$last3 = $labels[ $n - 3 ] . '.' . $last2;
		// a government site under a two-letter region (<agency>.<xx>.gov, <agency>.<xx>.us) is one agency's own
		if ( preg_match( '/^[a-z]{2}\.(gov|us)$/', $last2 ) ) {
			return $last3;
		}
		if ( in_array( $last3, self::MULTI_SUFFIXES, true ) && $n >= 4 ) {
			return $labels[ $n - 4 ] . '.' . $last3;
		}
		if ( in_array( $last2, self::MULTI_SUFFIXES, true ) ) {
			return $last3;
		}
		return $last2;
	}

	/** The name part of a site's domain, letters and digits only: example-co.com → exampleco. */
	public static function owner_label( $domain ): string {
		$d = self::registrable_domain( $domain );
		if ( '' === $d ) {
			return '';
		}
		return (string) preg_replace( '/[^a-z0-9]/', '', explode( '.', $d )[0] );
	}

	/**
	 * One owner: the same domain; or one name holds the other and the shorter is long enough not to be part of a
	 * common word (exampleco and examplecofranchise); or an administrator put them in one group.
	 */
	public static function same_owner( $a, $b ): bool {
		$da = self::registrable_domain( $a );
		$db = self::registrable_domain( $b );
		if ( '' === $da || '' === $db ) {
			return false;
		}
		if ( $da === $db ) {
			return true;
		}
		$la    = self::owner_label( $da );
		$lb    = self::owner_label( $db );
		$short = strlen( $la ) <= strlen( $lb ) ? $la : $lb;
		$long  = strlen( $la ) <= strlen( $lb ) ? $lb : $la;
		if ( strlen( $short ) >= 6 && false !== strpos( $long, $short ) ) {
			return true;
		}
		foreach ( self::owner_groups() as $group ) {
			if ( in_array( $da, $group, true ) && in_array( $db, $group, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Domains an administrator says have one owner (a publisher's network, a syndicate): one group a line, domains
	 * separated by commas. Core ships none.
	 *
	 * @return array<int,string[]>
	 */
	public static function owner_groups(): array {
		$raw = (string) self::setting( 'owner_groups', '' );
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$g = array();
			foreach ( explode( ',', $line ) as $d ) {
				$d = self::registrable_domain( trim( $d ) );
				if ( '' !== $d ) {
					$g[] = $d;
				}
			}
			if ( count( $g ) >= 2 ) {
				$out[] = array_values( array_unique( $g ) );
			}
		}
		return $out;
	}

	/** Is this site the claim's own subject (so what it says is a self-report)? */
	public static function is_subject_owned( $domain, $subject ): bool {
		$label = self::owner_label( $domain );
		if ( '' === $label ) {
			return false;
		}
		$s        = strtolower( (string) $subject );
		$squashed = (string) preg_replace( '/[^a-z0-9]/', '', $s );
		if ( strlen( $squashed ) >= 4 && false !== strpos( $label, $squashed ) ) {
			return true;
		}
		foreach ( preg_split( '/[^a-z0-9]+/', $s ) as $tok ) {
			if ( strlen( $tok ) >= 6 && ! in_array( $tok, self::GENERIC_WORDS, true ) && false !== strpos( $label, $tok ) ) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== words and figures */

	/**
	 * A text made plain for "is this quote on that page": lower case, curly quotes and dashes flattened, thousands
	 * separators dropped, every other run of punctuation or space one space. Decimal points are kept.
	 */
	public static function normalize( $s ): string {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = str_replace( array( "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}" ), array( "'", "'", '"', '"', '-', '-', ' ' ), $s );
		$s = (string) preg_replace( '/(?<=\d),(?=\d{3}\b)/', '', $s );
		$s = (string) preg_replace( '/[^\p{L}\p{N}%.$]+/u', ' ', $s );
		$s = (string) preg_replace( '/(?<!\d)\.|\.(?!\d)/', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * The figures a claim stands or falls on: money, percentages, and numbers with a thousands separator, a decimal or
	 * at least two digits. A bare year (1900 to 2099) is context, not the claim, and is left out.
	 *
	 * @return string[] e.g. '1000', '250', '12%', '1.5'
	 */
	public static function extract_figures( $text ): array {
		$out = array();
		if ( ! preg_match_all( '/(\$\s*)?(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?\s*(%|percent\b)?/i', (string) $text, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $g ) {
			$int   = str_replace( ',', '', $g[2] );
			$dec   = isset( $g[3] ) ? $g[3] : '';
			$pct   = ! empty( $g[4] );
			$money = '' !== trim( (string) $g[1] );
			$tok   = ltrim( $int, '0' );
			$tok   = '' === $tok ? '0' : $tok;
			if ( '' !== $dec && '' !== rtrim( substr( $dec, 1 ), '0' ) ) {
				$tok .= rtrim( $dec, '0' );
			}
			if ( ! $pct && ! $money && '' === $dec && preg_match( '/^(19|20)\d{2}$/', $int ) && false === strpos( $g[2], ',' ) ) {
				continue;   // a year
			}
			if ( ! $pct && ! $money && '' === $dec && strlen( $int ) < 2 ) {
				continue;   // "one of 2 sites" is not a figure a claim rests on
			}
			$out[] = $pct ? $tok . '%' : $tok;
		}
		return array_values( array_unique( $out ) );
	}

	/** Is a figure in a normalised text (a percentage with its sign or the word, and never as part of a longer number)? */
	public static function figure_on( string $tok, string $norm_text ): bool {
		$pct  = '%' === substr( $tok, -1 );
		$core = $pct ? substr( $tok, 0, -1 ) : $tok;
		$re   = '/(?<![\d.])' . preg_quote( $core, '/' ) . '(?:\.0+)?(?![\d])' . ( $pct ? '\s*(?:%|percent)' : '' ) . '/u';
		return (bool) preg_match( $re, $norm_text );
	}

	/** Is the quote on the page? Needs a real quote: 20 characters or more once made plain. */
	public static function quote_on_page( $quote, $page_text ): bool {
		$q = self::normalize( $quote );
		if ( strlen( $q ) < 20 ) {
			return false;
		}
		return false !== strpos( self::normalize( $page_text ), $q );
	}

	/**
	 * For a claim with figures whose quote was reworded: every figure of the claim is on the page inside one stretch
	 * of 500 characters that also holds one of the claim's words (four letters or more) and names the subject. Strict
	 * about the figures: a small bare number ("12") is on any page, so without a word-for-word quote only a figure
	 * that stands out (money, a percentage, a decimal, three digits or more) can stand in for one.
	 */
	public static function figures_on_page( array $figures, $claim_text, $page_text, $subject = '' ): bool {
		$figures = array_values( $figures );
		if ( ! $figures ) {
			return false;
		}
		$strong = false;
		foreach ( $figures as $f ) {
			$f = (string) $f;
			if ( '%' === substr( $f, -1 ) || false !== strpos( $f, '.' ) || strlen( $f ) >= 3 ) {
				$strong = true;
				break;
			}
		}
		if ( ! $strong ) {
			return false;
		}
		$page  = self::normalize( $page_text );
		$core  = rtrim( (string) $figures[0], '%' );
		$words = array();
		foreach ( preg_split( '/\s+/', self::normalize( $claim_text ) ) as $w ) {
			if ( strlen( $w ) >= 4 && ! preg_match( '/\d/', $w ) ) {
				$words[] = $w;
			}
		}
		$subj   = self::normalize( (string) $subject );
		$offset = 0;
		while ( '' !== $core && false !== ( $pos = strpos( $page, $core, $offset ) ) ) {
			$win = substr( $page, max( 0, $pos - 250 ), 500 );
			$all = true;
			foreach ( $figures as $f ) {
				if ( ! self::figure_on( (string) $f, $win ) ) {
					$all = false;
					break;
				}
			}
			if ( $all && '' !== $subj && false === strpos( $win, $subj ) ) {
				// the page must be talking about the same subject there
				$all = false;
				foreach ( explode( ' ', $subj ) as $tok ) {
					if ( strlen( $tok ) >= 5 && false !== strpos( $win, $tok ) ) {
						$all = true;
						break;
					}
				}
			}
			if ( $all ) {
				foreach ( $words as $w ) {
					if ( false !== strpos( $win, $w ) ) {
						return true;
					}
				}
			}
			$offset = $pos + 1;
		}
		return false;
	}

	/* ================================================================== reading what a model listed */

	/** The first JSON list or object in a text (a fenced block or bare), or null. */
	public static function json_from( $text ) {
		$t     = (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $text );
		$a     = strpos( $t, '[' );
		$o     = strpos( $t, '{' );
		$start = false === $a ? $o : ( false === $o ? $a : min( $a, $o ) );
		if ( false === $start ) {
			return null;
		}
		$end = strrpos( $t, '[' === $t[ $start ] ? ']' : '}' );
		if ( false === $end || $end <= $start ) {
			return null;
		}
		$j = json_decode( substr( $t, $start, $end - $start + 1 ), true );
		return is_array( $j ) ? $j : null;
	}

	/** An address a model wrote, with trailing punctuation cut; '' unless it is http(s) with a real host. */
	public static function clean_url( $u ): string {
		$u = rtrim( trim( is_scalar( $u ) ? (string) $u : '' ), '.,;:)]\'"' );
		return ( '' !== self::host_of( $u ) && strlen( $u ) <= 2000 ) ? $u : '';
	}

	/**
	 * The claims a model listed: [{ id, claim, subject, figures[], sources[{url, quote}] }]. Anything malformed is
	 * dropped; the figures are read from the claim's own words here, never taken from the model.
	 */
	public static function parse_claims( $text, $max = null ): array {
		$max = null === $max ? self::max_claims() : (int) $max;
		$j   = self::json_from( $text );
		if ( isset( $j['claims'] ) && is_array( $j['claims'] ) ) {
			$j = $j['claims'];
		}
		$out = array();
		if ( ! is_array( $j ) ) {
			return $out;
		}
		foreach ( $j as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$claim = trim( (string) preg_replace( '/\s+/', ' ', is_scalar( $c['claim'] ?? null ) ? (string) $c['claim'] : '' ) );
			if ( strlen( $claim ) < 8 ) {
				continue;
			}
			$claim = function_exists( 'mb_substr' ) ? mb_substr( $claim, 0, 400 ) : substr( $claim, 0, 400 );
			$src   = array();
			foreach ( is_array( $c['sources'] ?? null ) ? $c['sources'] : array() as $s ) {
				$url = self::clean_url( is_array( $s ) ? ( $s['url'] ?? '' ) : $s );
				if ( '' !== $url ) {
					$quote = is_array( $s ) && is_scalar( $s['quote'] ?? null ) ? trim( (string) $s['quote'] ) : '';
					$src[] = array( 'url' => $url, 'quote' => substr( $quote, 0, 600 ) );
				}
			}
			$out[] = array(
				'id'      => count( $out ) + 1,
				'claim'   => $claim,
				'subject' => substr( trim( is_scalar( $c['subject'] ?? null ) ? (string) $c['subject'] : '' ), 0, 80 ),
				'figures' => self::extract_figures( $claim ),
				'sources' => array_slice( $src, 0, 4 ),
			);
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/** True only for a well-formed EMPTY list ([] or {"claims":[]}): anything else is a listing that failed. */
	public static function is_empty_claim_list( $text ): bool {
		$t = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', (string) $text ) );
		$j = json_decode( $t, true );
		if ( array() === $j ) {
			return true;
		}
		return is_array( $j ) && array_key_exists( 'claims', $j ) && array() === $j['claims'] && 1 === count( $j );
	}

	/** What a second search found for a claim: [{ url, quote, value, stance supports|contradicts }], eight at most. */
	public static function parse_evidence( $text ): array {
		$j = self::json_from( $text );
		if ( isset( $j['sources'] ) && is_array( $j['sources'] ) ) {
			$j = $j['sources'];
		}
		$out = array();
		if ( ! is_array( $j ) ) {
			return $out;
		}
		foreach ( $j as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$url = self::clean_url( $e['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$stance = strtolower( is_scalar( $e['stance'] ?? null ) ? (string) $e['stance'] : 'supports' );
			$out[]  = array(
				'url'    => $url,
				'quote'  => substr( trim( is_scalar( $e['quote'] ?? null ) ? (string) $e['quote'] : '' ), 0, 600 ),
				'value'  => substr( trim( is_scalar( $e['value'] ?? null ) ? (string) $e['value'] : '' ), 0, 200 ),
				'stance' => 'contradicts' === $stance ? 'contradicts' : 'supports',
			);
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	/* ================================================================== deciding */

	/**
	 * What ONE piece of evidence shows, from the page the server read. The model's own "stance" is only a hint: a
	 * source supports the claim when its checked words carry the claim's figures, and contradicts it when its checked
	 * words carry a different figure.
	 *
	 * @param array       $claim     from parse_claims()
	 * @param array       $ev        { url, quote, value, stance }
	 * @param string|null $page_text the page as text; null when it could not be read
	 * @return array $ev + { status: supports|contradicts|not_on_page|unreadable, owner, judged? }
	 */
	public static function assess( array $claim, array $ev, $page_text ): array {
		$ev['owner'] = self::registrable_domain( $ev['url'] ?? '' );
		if ( null === $page_text || '' === trim( (string) $page_text ) ) {
			$ev['status'] = 'unreadable';
			return $ev;
		}
		$figs  = array_values( (array) ( $claim['figures'] ?? array() ) );
		$quote = (string) ( $ev['quote'] ?? '' );

		if ( self::quote_on_page( $quote, $page_text ) ) {
			if ( ! $figs ) {
				// no figures to check: the quote being on the page is the evidence, and the model's stance says which way
				// it points (model-judged; the block says so)
				$ev['status'] = 'contradicts' === ( $ev['stance'] ?? '' ) ? 'contradicts' : 'supports';
				$ev['judged'] = 'model';
				return $ev;
			}
			$nq      = self::normalize( $quote );
			$has_all = true;
			foreach ( $figs as $f ) {
				if ( ! self::figure_on( (string) $f, $nq ) ) {
					$has_all = false;
					break;
				}
			}
			if ( $has_all ) {
				$ev['status'] = 'supports';
				return $ev;
			}
			// the quote is real and carries figures, only not the claim's: a source giving a different number
			$qf = self::extract_figures( $quote . ' ' . (string) ( $ev['value'] ?? '' ) );
			if ( $qf && 'contradicts' === ( $ev['stance'] ?? '' ) ) {
				$ev['status'] = 'contradicts';
				return $ev;
			}
			$ev['status'] = 'not_on_page';
			return $ev;
		}

		// reworded or missing quote: only the claim's exact figures on the page count
		if ( $figs && self::figures_on_page( $figs, (string) ( $claim['claim'] ?? '' ), $page_text, (string) ( $claim['subject'] ?? '' ) ) ) {
			$ev['status'] = 'supports';
			$ev['judged'] = 'figures';
			return $ev;
		}
		$ev['status'] = 'not_on_page';
		return $ev;
	}

	/**
	 * The verdict for one claim, from its assessed evidence.
	 *
	 * @return array { verdict, supporting[owner=>url], contradicting[owner=>url], independent:int, self_only:bool, pages }
	 */
	public static function verdict( array $claim, array $assessed, $min = null ): array {
		$min     = null === $min ? self::min_sources() : max( 2, (int) $min );
		$subject = (string) ( $claim['subject'] ?? '' );
		$support = self::by_owner( $assessed, 'supports' );
		$contra  = self::by_owner( $assessed, 'contradicts' );

		$non_subject = 0;
		foreach ( array_keys( $support ) as $owner ) {
			if ( '' === $subject || ! self::is_subject_owned( $owner, $subject ) ) {
				$non_subject++;
			}
		}
		$n = count( $support );

		if ( $contra ) {
			$v = self::V_CONTESTED;
		} elseif ( $n >= $min && $non_subject >= 1 ) {
			$v = self::V_VERIFIED;
		} elseif ( $n >= 2 && $non_subject >= 1 ) {
			$v = self::V_THIN;     // independent support, from fewer owners than required
		} elseif ( $n >= 1 ) {
			$v = self::V_SINGLE;   // one owner, or only the subject's own sites
		} else {
			$v = self::V_UNCONFIRMED;
		}

		// what became of each source page, so "could not confirm" can say whether pages were read and silent or never read
		$pages = array( 'total' => 0, 'supports' => 0, 'contradicts' => 0, 'not_on_page' => 0, 'unreadable' => 0, 'unread' => 0 );
		foreach ( $assessed as $ev ) {
			$pages['total']++;
			$st = (string) ( $ev['status'] ?? 'unread' );
			$pages[ isset( $pages[ $st ] ) && 'total' !== $st ? $st : 'unread' ]++;
		}

		return array(
			'verdict'       => $v,
			'supporting'    => $support,
			'contradicting' => $contra,
			'independent'   => $n,
			'self_only'     => $n >= 1 && 0 === $non_subject,
			'pages'         => $pages,
		);
	}

	/** Evidence of one status, one address per owner (two pages of one site are one source). */
	private static function by_owner( array $assessed, string $status ): array {
		$out = array();
		foreach ( $assessed as $ev ) {
			if ( ! is_array( $ev ) || $status !== (string) ( $ev['status'] ?? '' ) ) {
				continue;
			}
			$dom = self::registrable_domain( $ev['url'] ?? '' );
			if ( '' === $dom ) {
				continue;
			}
			$key = $dom;
			foreach ( array_keys( $out ) as $known ) {
				if ( self::same_owner( $known, $dom ) ) {
					$key = $known;
					break;
				}
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = (string) $ev['url'];
			}
		}
		return $out;
	}

	/* ================================================================== saying it */

	/** Words that tell a reader a figure is not settled. */
	const HEDGES = '/\b(reported by|reports that|reportedly|according to|cited by|unverified|not (?:been )?independently|single[- ]source|one source|only source|contested|disputed|disagree|conflicting|differ(?:s|ent figure)?\b|vs\.?\s|versus|unconfirmed|could not (?:be )?confirm|not confirmed)/i';

	/**
	 * Claims that are not confirmed, yet whose leading figure the answer states on a line with no such word: a figure
	 * shown as settled when the check says it is not. A caveat counts where a reader sees it: on the line, in the
	 * sentence a list hangs from, in the paragraph and header row above a table, or in the section's heading. A
	 * citation number ("[15]") is not a figure.
	 *
	 * @return int[] claim ids
	 */
	public static function unhedged( $final_text, array $claims ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $final_text );
		$context = array();
		$heading = '';
		$leadin  = '';
		$thead   = '';
		$in_tab  = false;
		foreach ( $lines as $i => $line ) {
			$t = trim( $line );
			if ( preg_match( '/^#{1,6}\s/', $t ) ) {
				$heading       = $t;
				$leadin        = '';
				$in_tab        = false;
				$context[ $i ] = $t;
				continue;
			}
			$is_item = (bool) preg_match( '/^([-*+]\s|\d+[.)]\s)/', $t );
			$is_row  = 0 === strpos( $t, '|' );
			if ( $is_row && ! $in_tab ) {
				$thead  = $t;
				$in_tab = true;
			} elseif ( ! $is_row && '' !== $t ) {
				$in_tab = false;
			}
			if ( '' !== $t && ! $is_item && ! $is_row ) {
				$leadin = $t;
			}
			$context[ $i ] = $t . ' ' . ( $is_item || $is_row ? $leadin : '' ) . ' ' . ( $is_row ? $thead : '' ) . ' ' . $heading;
		}
		$bad = array();
		foreach ( $claims as $c ) {
			if ( self::V_VERIFIED === ( $c['verdict'] ?? '' ) || empty( $c['figures'] ) ) {
				continue;
			}
			$lead = (string) array_values( (array) $c['figures'] )[0];
			foreach ( $lines as $i => $line ) {
				$norm = self::normalize( (string) preg_replace( '/\[\d{1,3}\]/', ' ', (string) $line ) );
				if ( self::figure_on( $lead, $norm ) && ! preg_match( self::HEDGES, (string) ( $context[ $i ] ?? $line ) ) ) {
					$bad[] = (int) ( $c['id'] ?? 0 );
					break;
				}
			}
		}
		return $bad;
	}

	/** Why a claim could not be confirmed, from what became of its source pages. */
	public static function pages_words( array $p ): string {
		$total = (int) ( $p['total'] ?? 0 );
		if ( $total <= 0 ) {
			return __( 'Why: the search found no source pages for it.', 'zorderz' );
		}
		$bits = array();
		if ( ! empty( $p['not_on_page'] ) ) {
			/* translators: %d: a number of web pages */
			$bits[] = sprintf( __( '%d opened but did not contain it', 'zorderz' ), (int) $p['not_on_page'] );
		}
		if ( ! empty( $p['unreadable'] ) ) {
			/* translators: %d: a number of web pages */
			$bits[] = sprintf( __( '%d could not be opened by the server (blocked, not a web page, or no answer in time)', 'zorderz' ), (int) $p['unreadable'] );
		}
		if ( ! empty( $p['unread'] ) ) {
			/* translators: %d: a number of web pages */
			$bits[] = sprintf( __( '%d not read before the run ran out of time', 'zorderz' ), (int) $p['unread'] );
		}
		/* translators: 1: a number of web pages, 2: what became of them */
		return sprintf( _n( 'Why: %1$d source page: %2$s.', 'Why: %1$d source pages: %2$s.', $total, 'zorderz' ), $total, implode( '; ', $bits ) );
	}

	/** The words for a verdict, with its mark. */
	public static function label( string $v ): string {
		switch ( $v ) {
			case self::V_VERIFIED:
				return '✅ ' . __( 'Confirmed', 'zorderz' );
			case self::V_CONTESTED:
				return '⚠️ ' . __( 'Sources disagree', 'zorderz' );
			case self::V_THIN:
				return '🟡 ' . __( 'Partly confirmed', 'zorderz' );
			case self::V_SINGLE:
				return '🟡 ' . __( 'One source only', 'zorderz' );
			case self::V_UNCONFIRMED:
				return '❌ ' . __( 'Could not confirm', 'zorderz' );
			default:
				return '⏸ ' . __( 'Not checked', 'zorderz' );
		}
	}

	/** A short phrase for a verdict, for the activity log. */
	public static function verdict_words( string $v ): string {
		switch ( $v ) {
			case self::V_VERIFIED:
				return __( 'confirmed by independent sources', 'zorderz' );
			case self::V_CONTESTED:
				return __( 'sources disagree', 'zorderz' );
			case self::V_THIN:
				return __( 'partly confirmed', 'zorderz' );
			case self::V_SINGLE:
				return __( 'found in only one place', 'zorderz' );
			case self::V_UNCONFIRMED:
				return __( 'could not be confirmed', 'zorderz' );
			default:
				return __( 'not checked', 'zorderz' );
		}
	}

	/**
	 * An address a model supplied, made safe inside a markdown link: every character that could close the link or open
	 * another is percent-encoded, so one address stays one link.
	 */
	public static function md_url( $url ): string {
		return str_replace(
			array( ' ', "\t", "\n", "\r", '(', ')', '[', ']', '<', '>', '"', "'", '`', '\\' ),
			array( '%20', '%09', '%0A', '%0D', '%28', '%29', '%5B', '%5D', '%3C', '%3E', '%22', '%27', '%60', '%5C' ),
			(string) $url
		);
	}

	/** Words a model wrote, shown inside the server's block: no links, no tags, no emphasis, one line. */
	public static function md_text( $text ): string {
		$t = (string) preg_replace( '/\s+/u', ' ', (string) $text );
		return str_replace( array( '[', ']', '<', '>', '`', '*', '_' ), array( '(', ')', '‹', '›', "'", '', ' ' ), $t );
	}

	private static function site_list( array $by_owner ): string {
		$out = array();
		foreach ( $by_owner as $owner => $url ) {
			$out[] = '[' . self::md_text( $owner ) . '](' . self::md_url( $url ) . ')';
		}
		return implode( ', ', $out );
	}

	/**
	 * The "Outside facts check" block under a research answer, written by the server from the verdicts, never by a
	 * model.
	 *
	 * @param array  $claims   claims carrying 'verdict' and 'result' (from verdict())
	 * @param string $status   'done' | 'partial' | 'skipped' | 'none' | 'off'
	 * @param int[]  $unhedged ids from unhedged()
	 */
	public static function render_block( array $claims, string $status = 'done', array $unhedged = array() ): string {
		$h = "\n\n---\n**🔎 " . __( 'Outside facts check', 'zorderz' ) . '**: ' . sprintf(
			/* translators: %d: how many independent sites must agree */
			__( 'a web finding counts as confirmed only when at least %d independent sites (different owners, not only the company itself) say the same thing, and the server has found the words on each page.', 'zorderz' ),
			self::min_sources()
		) . "\n";

		if ( 'off' === $status ) {
			return $h . "\n_" . __( 'Outside-fact checking is switched off, so none of the web findings above were checked. Treat every outside figure as unverified.', 'zorderz' ) . '_';
		}
		if ( ! $claims ) {
			return $h . "\n_" . ( 'none' === $status
				? __( 'The research listed no outside claims to check. Treat any outside figure above as unverified.', 'zorderz' )
				: __( 'The outside facts in this answer could not be listed for checking, so none were checked. Treat every outside figure as unverified.', 'zorderz' ) ) . '_';
		}

		$counts = array();
		foreach ( $claims as $c ) {
			$v            = (string) ( $c['verdict'] ?? self::V_NOT_CHECKED );
			$counts[ $v ] = ( $counts[ $v ] ?? 0 ) + 1;
		}
		$parts = array();
		foreach ( array( self::V_VERIFIED, self::V_CONTESTED, self::V_THIN, self::V_SINGLE, self::V_UNCONFIRMED, self::V_NOT_CHECKED ) as $v ) {
			if ( ! empty( $counts[ $v ] ) ) {
				$words   = (string) preg_replace( '/^\S+\s/u', '', self::label( $v ) );
				$parts[] = $counts[ $v ] . ' ' . ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $words ) : strtolower( $words ) );
			}
		}
		/* translators: 1: how many claims were checked, 2: a list such as "2 confirmed · 1 sources disagree" */
		$h .= "\n" . sprintf( __( '%1$d checked: %2$s', 'zorderz' ), count( $claims ), implode( ' · ', $parts ) ) . "\n";

		foreach ( $claims as $c ) {
			$v   = (string) ( $c['verdict'] ?? self::V_NOT_CHECKED );
			$res = (array) ( $c['result'] ?? array() );
			$h  .= "\n- " . self::label( $v ) . ': ' . self::md_text( $c['claim'] ?? '' );
			$sup = (array) ( $res['supporting'] ?? array() );
			$con = (array) ( $res['contradicting'] ?? array() );
			if ( $sup ) {
				$h .= "\n  " . __( 'Says so:', 'zorderz' ) . ' ' . self::site_list( $sup );
			}
			if ( $con ) {
				$h .= "\n  " . __( 'Says otherwise:', 'zorderz' ) . ' ' . self::site_list( $con );
			}
			if ( ! empty( $res['self_only'] ) ) {
				$h .= "\n  " . __( 'Only the company\'s own sites say this.', 'zorderz' );
			}
			if ( self::V_UNCONFIRMED === $v && isset( $res['pages'] ) ) {
				$h .= "\n  " . self::pages_words( (array) $res['pages'] );
			}
			if ( in_array( (int) ( $c['id'] ?? 0 ), $unhedged, true ) ) {
				$h .= "\n  ⚠️ " . __( 'The answer above states this without saying it is unconfirmed.', 'zorderz' );
			}
		}
		if ( 'partial' === $status ) {
			$h .= "\n\n_" . __( 'The run ended before every outside fact was checked. Anything marked "Not checked" is unverified.', 'zorderz' ) . '_';
		}
		$h .= "\n\n_" . sprintf(
			/* translators: %d: the most claims one run checks */
			__( 'Only the most material outside claims are checked (up to %d a run). Any outside figure not listed here was not checked.', 'zorderz' ),
			self::max_claims()
		) . '_';
		return $h;
	}

	/**
	 * What the writing model is told about the check: how it may state each claim. '' when there were no web sources.
	 *
	 * @param array|null $state  the run's check state { claims, status } (null: no check ran)
	 * @param bool       $had_web the run used web sources
	 */
	public static function prompt_block( $state, bool $had_web ): string {
		$claims = is_array( $state ) ? (array) ( $state['claims'] ?? array() ) : array();
		if ( ! $claims ) {
			if ( $had_web && ! ( is_array( $state ) && 'none' === ( $state['status'] ?? '' ) ) ) {
				return "\n\nOUTSIDE-FACT CHECK: NOT DONE for this run. No outside figure was checked against independent sources. "
					. 'RULE: say where every outside figure came from and that it is unverified, in the sentence that states it '
					. '("reported by <site>, not independently verified"). Never present an outside figure as established fact.';
			}
			return '';
		}
		$lines = array();
		foreach ( $claims as $c ) {
			$v   = (string) ( $c['verdict'] ?? self::V_NOT_CHECKED );
			$res = (array) ( $c['result'] ?? array() );
			$l   = '- [' . strtoupper( $v ) . '] ' . (string) ( $c['claim'] ?? '' );
			if ( ! empty( $res['supporting'] ) ) {
				$l .= ' | says so: ' . implode( ', ', array_keys( (array) $res['supporting'] ) );
			}
			if ( ! empty( $res['contradicting'] ) ) {
				$o = array();
				foreach ( (array) ( $c['evidence'] ?? array() ) as $e ) {
					if ( 'contradicts' === ( $e['status'] ?? '' ) ) {
						$o[] = self::registrable_domain( $e['url'] ?? '' ) . ( '' !== (string) ( $e['value'] ?? '' ) ? ' (' . $e['value'] . ')' : '' );
					}
				}
				$l .= ' | says otherwise: ' . implode( ', ', array_unique( $o ) );
			}
			$lines[] = $l;
		}
		return "\n\nOUTSIDE-FACT CHECK (done by the server; it decides how you may state each claim):\n" . implode( "\n", $lines ) . "\n"
			. "RULES: only VERIFIED claims may be stated as fact (cite them). CONTESTED: give each source's figure and say they "
			. 'disagree; never pick one silently. SINGLE_SOURCE / THIN: say it was found in only one place (or only on the '
			. "company's own site) and is not independently confirmed. UNCONFIRMED / NOT_CHECKED: say it could not be confirmed, "
			. 'or leave it out. Never state an outside figure that is not in this list as a fact.';
	}
}
