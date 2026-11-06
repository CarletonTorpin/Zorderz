<?php
/**
 * ZANA_Page_Nav — "show me the <page> page" opens one of the business's own website pages, from a list it keeps.
 *
 * The page list is the business's (option `zana_page_nav_map`, a JSON object or array the business or its pack
 * writes; filter of the same name): groups `primary_pages`, `tools` and `company_utility`, each a list of
 * { id, name, url, aliases[], category, description }. Core ships none, so on a fresh install this route never fires.
 * Only http(s) pages are kept.
 *
 *   resolve( $text )  the ONE page a spoken request names, or null: the request's words without the "show me / open /
 *                     take me to" lead-in and a trailing "page / site", matched against each page's name and aliases —
 *                     an exact alias first, then the LONGEST whole alias inside the words (so "price calculator" is the
 *                     more specific page). Ties go to primary pages. Conservative: it never guesses.
 *   suggest( $text )  real closest pages for an honest "did you mean …" (never an invented page).
 *   route()           the chat's rule: a navigation verb AND a page that resolves, AND no data / price word ("show me
 *                     last month's revenue for X" stays a data question) unless the page is a tool. An explicit page
 *                     request that does not resolve gets the honest miss.
 *
 * The answer carries the page as a directive the chat page turns into an in-app view — the URL is always the list's,
 * never a model's: `<!--ZANA_OPEN_PAGE:{"url","title","id"}-->` on its own line after one sentence.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Page_Nav {

	const OPT       = 'zana_page_nav_map';
	const DIRECTIVE = 'ZANA_OPEN_PAGE';
	const GROUPS    = array( 'primary_pages', 'tools', 'company_utility' );

	/** @var array|null flattened entries for this request */
	private static $entries = null;

	/** Tests hand in the map (array) and reset the cache. */
	public static function reset( $map = null ): void {
		self::$entries = null;
		self::$map     = $map;
	}

	/** @var array|null */
	private static $map = null;

	/** The business's map, cleaned. */
	public static function map(): array {
		if ( is_array( self::$map ) ) {
			return self::$map;
		}
		$m = function_exists( 'get_option' ) ? get_option( self::OPT, array() ) : array();
		if ( is_string( $m ) ) {
			$d = json_decode( $m, true );
			$m = is_array( $d ) ? $d : array();
		}
		$m = is_array( $m ) ? $m : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( self::OPT, $m );
			$m = is_array( $f ) ? $f : $m;
		}
		return $m;
	}

	/** Flattened entries: id, name, url, description, category, aliases[] (normalised), _group. */
	private static function entries(): array {
		if ( null !== self::$entries ) {
			return self::$entries;
		}
		self::$entries = array();
		$map           = self::map();
		foreach ( self::GROUPS as $group ) {
			foreach ( (array) ( $map[ $group ] ?? array() ) as $e ) {
				if ( ! is_array( $e ) || empty( $e['id'] ) || empty( $e['name'] ) || empty( $e['url'] ) ) {
					continue;
				}
				$url = trim( (string) $e['url'] );
				if ( ! preg_match( '#^https?://[^\s<>"\']+$#i', $url ) ) {
					continue;
				}
				$aliases = array( self::normalize_alias( (string) $e['name'] ) );
				foreach ( (array) ( $e['aliases'] ?? array() ) as $a ) {
					$na = self::normalize_alias( (string) $a );
					if ( '' !== $na ) {
						$aliases[] = $na;
					}
				}
				self::$entries[] = array(
					'id'          => mb_substr( (string) $e['id'], 0, 80 ),
					'name'        => mb_substr( trim( (string) $e['name'] ), 0, 80 ),
					'url'         => $url,
					'description' => mb_substr( (string) ( $e['description'] ?? '' ), 0, 300 ),
					'category'    => mb_substr( (string) ( $e['category'] ?? '' ), 0, 40 ),
					'aliases'     => array_values( array_unique( array_filter( $aliases ) ) ),
					'_group'      => $group,
				);
			}
		}
		return self::$entries;
	}

	private static function group_rank( string $group ): int {
		$i = array_search( $group, self::GROUPS, true );
		return false === $i ? 3 : (int) $i;
	}

	/** Lower case, anything not a letter or digit a space, spaces collapsed. */
	public static function normalize_alias( string $s ): string {
		$s = strtolower( $s );
		$s = (string) preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/** A spoken request without its navigation lead-in, a trailing "page / site" and filler (the business's name too). */
	public static function normalize_query( string $text ): string {
		$q = self::normalize_alias( $text );
		if ( '' === $q ) {
			return '';
		}
		// each optional piece takes its own trailing space, so a bare "a" never eats the first letter of the subject
		$lead = '/^(?:please\s+|hey\s+|ok\s+|okay\s+)?'
			. '(?:(?:can|could|would|will)\s+(?:you\s+)?)?'
			. '(?:(?:i\s+want\s+to|i\s+wanna|i\s+would\s+like\s+to|i\s+d\s+like\s+to|let\s+me|lets|let\s+s)\s+)?'
			. '(?:show|open|take|go|goto|pull|bring|navigate|nav|load|view|see|find|get|jump|visit|display)\s+'
			. '(?:me\s+|us\s+)?'
			. '(?:to\s+|up\s+|onto\s+)?'
			. '(?:the\s+|our\s+|your\s+|a\s+|an\s+|us\s+|that\s+)?/i';
		for ( $i = 0; $i < 2; $i++ ) {
			$next = preg_replace( $lead, '', $q );
			if ( null === $next || $next === $q ) {
				break;
			}
			$q = trim( $next );
		}
		$q = (string) preg_replace( '/\s+(page|pages|site|website|web\s*page|web\s*site)$/i', '', $q );
		$q = (string) preg_replace( '/\b(the|our|your|a|an|us|please)\b/i', ' ', $q );
		foreach ( self::business_names() as $n ) {
			$q = (string) preg_replace( '/\b' . preg_quote( $n, '/' ) . '\b/', ' ', $q );
		}
		return trim( (string) preg_replace( '/\s+/', ' ', $q ) );
	}

	/** The business's own names, normalised (a request may say "open <business> home"). */
	private static function business_names(): array {
		$out = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.trading_name', 'identity.legal_name', 'identity.short_name' ) as $p ) {
				$n = self::normalize_alias( (string) ZDZ_Business_Profile::get( $p, '' ) );
				if ( strlen( $n ) >= 3 ) {
					$out[] = $n;
				}
			}
		}
		return array_unique( $out );
	}

	/** The one page a request names, or null. */
	public static function resolve( string $text ): ?array {
		$q = self::normalize_query( $text );
		if ( strlen( $q ) < 2 ) {
			return null;
		}
		$best = null;
		$best_score = -1;
		$best_rank  = 99;
		$padded     = ' ' . $q . ' ';
		foreach ( self::entries() as $e ) {
			$rank = self::group_rank( $e['_group'] );
			foreach ( $e['aliases'] as $alias ) {
				if ( $q === $alias ) {
					$score = 100000;
				} elseif ( false !== strpos( $padded, ' ' . $alias . ' ' ) ) {
					$score = strlen( $alias );
				} else {
					continue;
				}
				if ( $score > $best_score || ( $score === $best_score && $rank < $best_rank ) ) {
					$best       = $e;
					$best_score = $score;
					$best_rank  = $rank;
				}
			}
		}
		return $best ? self::public_entry( $best ) : null;
	}

	/** Up to $n real pages closest to an unresolved request. Words every page shares (the catalog's trade words) do not count. */
	public static function suggest( string $text, int $n = 3 ): array {
		$q = self::normalize_query( $text );
		if ( '' === $q || ! self::entries() ) {
			return array();
		}
		$common = array();
		if ( class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'trade_words' ) ) {
			foreach ( (array) ZDZ_Ask_Phrases::trade_words() as $w ) {
				$common[] = self::normalize_alias( (string) $w );
			}
		}
		$all    = array_values( array_filter( explode( ' ', $q ), function ( $t ) { return strlen( $t ) >= 3; } ) );
		$tokens = array_values( array_filter( $all, function ( $t ) use ( $common ) { return ! in_array( $t, $common, true ); } ) );
		$tokens = $tokens ? $tokens : $all;
		$scored = array();
		foreach ( self::entries() as $idx => $e ) {
			$hay   = ' ' . implode( ' ', $e['aliases'] ) . ' ';
			$score = 0;
			foreach ( $tokens as $t ) {
				if ( false !== strpos( $hay, $t ) ) {
					$score++;
				}
			}
			if ( $score > 0 ) {
				$scored[] = array( 'score' => $score, 'rank' => self::group_rank( $e['_group'] ), 'idx' => $idx, 'e' => $e );
			}
		}
		usort( $scored, function ( $a, $b ) {
			return array( $b['score'], $a['rank'], $a['idx'] ) <=> array( $a['score'], $b['rank'], $b['idx'] );
		} );
		return array_map( function ( $s ) { return self::public_entry( $s['e'] ); }, array_slice( $scored, 0, max( 1, $n ) ) );
	}

	public static function get_by_id( string $id ): ?array {
		foreach ( self::entries() as $e ) {
			if ( $e['id'] === trim( $id ) ) {
				return self::public_entry( $e );
			}
		}
		return null;
	}

	public static function is_available(): bool {
		return (bool) self::entries();
	}

	private static function public_entry( array $e ): array {
		return array( 'id' => $e['id'], 'name' => $e['name'], 'url' => $e['url'], 'description' => $e['description'], 'category' => $e['category'] );
	}

	/**
	 * The route: the answer when this turn asks to open one of the business's pages, else null. Signal 1: a navigation
	 * verb. Signal 2: a page that resolves. A data / price word keeps it a data question (unless the page is a tool),
	 * and an edit instruction for a draft ("reword", "sign-off", "start it with") is never a page request.
	 */
	public static function route( string $message ): ?string {
		if ( ! self::is_available() ) {
			return null;
		}
		$nq = strtolower( trim( $message ) );
		if ( '' === $nq || strlen( $nq ) > 300 ) {
			return null;
		}
		$nav  = (bool) preg_match( '/\b(show|open|pull\s+up|bring\s+up|take\s+me\s+to|go\s+to|goto|navigate\s+to|nav\s+to|jump\s+to|load|view|visit|see|display)\b/i', $nq );
		$data = (bool) preg_match( '/\b(revenue|sales|sold|sell|selling|profit|margin|gross|net|booked|how\s+many|how\s+much|count|counts|number\s+of|numbers|total|totals|average|avg|figure|figures|report|breakdown|per\s+month|this\s+month|last\s+month|this\s+year|last\s+year|ytd|quarter|commission|price|priced|pricing|cost|charge|quote|invoice)\b/i', $nq ) || false !== strpos( $nq, '$' );
		$edit = (bool) preg_match( '/\b(reword|rephrase|rewrite|revise|sign[\s-]?off|signature|start(?:\s+it)?\s+with|begin(?:\s+it)?\s+with|end(?:\s+it)?\s+with|take\s+out|leave\s+out|remove\s+the|post\s+this\s+to|note\s+to\s+self)\b/i', $nq );
		if ( ! $nav || $edit ) {
			return null;
		}
		$e = self::resolve( $message );
		if ( is_array( $e ) && ( 'tool' === $e['category'] || ! $data ) ) {
			return self::render( $e );
		}
		if ( ! is_array( $e ) && ! $data && preg_match( '/\b(page|website|web\s*site|web\s*page)\b/i', $nq ) ) {
			return self::render( null, $message );
		}
		return null;
	}

	/** The answer: one sentence and the directive, or the honest miss with real suggestions. */
	public static function render( ?array $e, string $miss = '' ): string {
		if ( is_array( $e ) ) {
			$payload = json_encode( array( 'url' => $e['url'], 'title' => $e['name'], 'id' => $e['id'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
			/* translators: %s: the page's name */
			return sprintf( __( 'Opening the %s page for you.', 'zorderz' ), '**' . self::md( $e['name'] ) . '**' ) . "\n" . '<!--' . self::DIRECTIVE . ':' . ( is_string( $payload ) ? $payload : '{}' ) . '-->';
		}
		$s = '' !== $miss ? self::suggest( $miss, 3 ) : array();
		if ( $s ) {
			$names = array_map( function ( $x ) { return self::md( $x['name'] ); }, $s );
			/* translators: 1: page names, 2: the first page's name */
			return sprintf( __( 'I don\'t have that exact page in my list. Did you mean: %1$s? Say "show me the %2$s page" and I\'ll open it.', 'zorderz' ), implode( ', ', $names ), strtolower( $names[0] ) );
		}
		$some = array_slice( array_map( function ( $x ) { return self::md( $x['name'] ); }, self::entries() ), 0, 6 );
		return $some
			/* translators: %s: a few page names */
			? sprintf( __( 'I couldn\'t find that page on the site. I can open pages like %s. Which would you like?', 'zorderz' ), implode( ', ', $some ) )
			: __( 'I couldn\'t find that page on the site.', 'zorderz' );
	}

	/** A page name as markdown text (no markup can come out of the list). */
	private static function md( string $s ): string {
		return (string) preg_replace( '/([\\\\`*_\[\]<>#|~])/', '\\\\$1', $s );
	}

	/** A short id — name list for the instructions ('' with no list). */
	public static function prompt_block(): string {
		$lines = array();
		foreach ( array_slice( self::entries(), 0, 60 ) as $e ) {
			$lines[] = $e['id'] . ' — ' . $e['name'];
		}
		return $lines ? "WEBSITE PAGES the chat can open (say \"show me the <name> page\"; never write a page address yourself):\n" . implode( "\n", $lines ) : '';
	}
}
