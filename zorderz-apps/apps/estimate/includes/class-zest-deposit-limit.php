<?php
/**
 * ZEST_Deposit_Limit - how much an estimate may ask before the work: the limit the deposit reader measures against.
 *
 * The limit is law where law sets one, and it comes from the customer-agreements app's legal packs: each pack's
 * rules.json may carry a `deposit_cap` ({ percent, fixed_cents, base, whole_dollars }). The packs that apply to a job
 * are the business's chosen pack and the state pack for the job's state (ZAGR_Pack::stack_for). A pack with no
 * deposit_cap sets no limit: the federal default sets none, so a business under it gets no deposit findings at all.
 *
 *   job state known      the packs for that state
 *   job state unknown    every state pack that layers on the chosen pack, the strictest of them (unknown never
 *                        means "no limit": the reader then errs toward asking a person)
 *   no agreements app,   no legal limit; only the business's own one (below)
 *   or the "none" pack
 *
 * A business may narrow the limit, never widen it: the option `zest_deposit_house_limit` ({ percent, fixed_cents },
 * from rules.yml `estimates.deposit.house_limit`) is folded in with min(). Both parts are optional; the stricter of
 * every part wins. A limit with neither a percent nor a fixed amount is no limit (active() false).
 *
 * The wording the app writes when it rewrites an over-limit ask ("Deposit: $X, due when you approve this estimate.
 * Balance due upon completion.") is document-conventions data (estimate.deposit_sentence, estimate.balance_sentence)
 * with these neutral English defaults; the reader recognises the app's own sentence from the same template.
 *
 * The reader's word lists that depend on what a business sells (the goods that "ship" or "arrive" before an install,
 * the product words beside which "50/50" is a product's split, the product attributes measured in percent) come from
 * zest_deposit_product_words / zest_deposit_rate_words (options written by rules.yml
 * `estimates.deposit.product_words` / `rate_words`, and filters of the same names). Core ships generic words only.
 *
 * Money is integer cents, US dollars (the deposit reader reads "$" figures).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Deposit_Limit {

	const HOUSE_OPT   = 'zest_deposit_house_limit';
	const PRODUCT_OPT = 'zest_deposit_product_words';
	const RATE_OPT    = 'zest_deposit_rate_words';

	const SENTENCE = 'Deposit: {amount}, due when you approve this estimate.';
	const BALANCE  = 'Balance due upon completion.';

	/** The limit in force for this request (set by the caller for the document it reads), or null: not yet worked out. */
	private static $now = null;
	private static $words = array();

	/* ------------------------------------------------------------------ the limit */

	/** No limit. */
	public static function none(): array {
		return array( 'rate_bp' => null, 'cap_cents' => null, 'whole' => false, 'source' => 'none' );
	}

	/**
	 * Fold deposit_cap rules (each { percent?, fixed_cents?, whole_dollars? }) and the business's own limit into one:
	 * the smallest percent and the smallest fixed amount of all of them. Pure.
	 */
	public static function fold( array $rules, ?array $house = null ): array {
		$out = self::none();
		$add = static function ( $c, string $src ) use ( &$out ) {
			if ( ! is_array( $c ) ) {
				return;
			}
			$hit = false;
			if ( isset( $c['percent'] ) && is_numeric( $c['percent'] ) && (float) $c['percent'] >= 0 && (float) $c['percent'] <= 100 ) {
				$bp             = (int) round( (float) $c['percent'] * 100 );
				$out['rate_bp'] = null === $out['rate_bp'] ? $bp : min( $out['rate_bp'], $bp );
				$hit            = true;
			}
			if ( isset( $c['fixed_cents'] ) && is_numeric( $c['fixed_cents'] ) && (int) $c['fixed_cents'] >= 0 ) {
				$fc               = (int) $c['fixed_cents'];
				$out['cap_cents'] = null === $out['cap_cents'] ? $fc : min( $out['cap_cents'], $fc );
				$hit              = true;
			}
			if ( ! empty( $c['whole_dollars'] ) ) {
				$out['whole'] = true;
			}
			if ( $hit ) {
				$out['source'] = 'none' === $out['source'] ? $src : ( $out['source'] === $src ? $src : 'both' );
			}
		};
		foreach ( $rules as $r ) {
			$add( $r, 'law' );
		}
		$add( $house, 'house' );
		return $out;
	}

	/** The business's own limit (rules.yml), or null. */
	public static function house(): ?array {
		$h = function_exists( 'get_option' ) ? get_option( self::HOUSE_OPT, null ) : null;
		if ( function_exists( 'apply_filters' ) ) {
			$h = apply_filters( 'zest_deposit_house_limit', $h );
		}
		return is_array( $h ) ? $h : null;
	}

	/**
	 * The deposit_cap rules of the legal packs for a job in $region ('' = not known): see the header. [] without the
	 * agreements app. Never throws.
	 */
	public static function law_rules( string $region ): array {
		if ( ! class_exists( 'ZAGR_Pack' ) || ! method_exists( 'ZAGR_Pack', 'stack_for' ) || ! method_exists( 'ZAGR_Pack', 'packs' ) ) {
			return array();
		}
		try {
			$stacks = array();
			$region = strtoupper( trim( $region ) );
			if ( '' !== $region ) {
				$stacks[] = ZAGR_Pack::stack_for( $region );
			} else {
				// the state is not known: the chosen chain, and every state pack layered on its root could be the job's
				$chain    = (array) ZAGR_Pack::stack_for( '' );
				$stacks[] = $chain;
				$root     = (string) ( $chain[0] ?? '' );
				if ( '' !== $root && method_exists( 'ZAGR_Pack', 'ids' ) && method_exists( 'ZAGR_Pack', 'load' ) ) {
					foreach ( (array) ZAGR_Pack::ids() as $id ) {
						try {
							$p = ZAGR_Pack::load( (string) $id );
						} catch ( \Throwable $e ) {
							continue;
						}
						if ( (string) ( $p['meta']['layers_on'] ?? '' ) === $root && '' !== (string) ( $p['meta']['region'] ?? '' ) ) {
							$stacks[] = array( $root, (string) $id );
						}
					}
				}
			}
			$rules = array();
			foreach ( $stacks as $stack ) {
				foreach ( (array) ZAGR_Pack::packs( (array) $stack ) as $p ) {
					if ( is_array( $p['rules']['deposit_cap'] ?? null ) ) {
						$rules[] = $p['rules']['deposit_cap'];
					}
				}
			}
			return $rules;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** The limit for a job in $region: law (when the agreements app is there) and the business's own. */
	public static function for_region( string $region ): array {
		return self::fold( self::law_rules( $region ), self::house() );
	}

	/** Use this limit for what this request reads next (a caller sets it per document; tests set it directly). */
	public static function set( ?array $limit ): void {
		self::$now = null === $limit ? null : $limit + self::none();
	}

	/** The limit in force: the one set, else the one for a job whose state is not known. */
	public static function get(): array {
		if ( null === self::$now ) {
			self::$now = self::for_region( '' );
		}
		return self::$now;
	}

	public static function reset(): void {
		self::$now   = null;
		self::$words = array();
	}

	/** Is there any limit to read against? */
	public static function active(): bool {
		$l = self::get();
		return null !== $l['rate_bp'] || null !== $l['cap_cents'];
	}

	/** The most that may be asked on this pre-tax price, or null when nothing limits it. */
	public static function limit_cents( int $pretax ): ?int {
		$l   = self::get();
		$lim = null;
		if ( null !== $l['rate_bp'] && $pretax > 0 ) {
			$lim = intdiv( $pretax * $l['rate_bp'], 10000 );
		}
		if ( null !== $l['cap_cents'] ) {
			$lim = null === $lim ? $l['cap_cents'] : min( $lim, $l['cap_cents'] );
		}
		return $lim;
	}

	/** The amount the app's own sentence states: the limit on this price, in whole dollars where the law says so. */
	public static function cap_cents( int $pretax ): int {
		$l   = self::get();
		$lim = self::limit_cents( max( 0, $pretax ) );
		if ( null === $lim ) {
			return 0;
		}
		if ( null !== $l['rate_bp'] && $pretax <= 0 ) {
			return 0;
		}
		return $l['whole'] ? intdiv( $lim, 100 ) * 100 : $lim;
	}

	public static function money( int $cents ): string {
		return '$' . number_format( $cents / 100, ( $cents % 100 ) ? 2 : 0 );
	}

	private static function pct_text( int $bp ): string {
		return rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' ) . '%';
	}

	/**
	 * [ over?, why ] for one amount: $pct > 0 is a percent of the price, else $cents a dollar figure. A percent is over
	 * when it is over the percent limit, or when that share of this price is over the fixed limit; a figure when it is
	 * over the lesser of the two on this price (exactly, so a share right at the limit is within it). With the price
	 * unknown (0), only what is over at any price counts.
	 */
	public static function over( float $pct, int $cents, int $pretax ): array {
		$l = self::get();
		if ( $pct > 0 ) {
			$p = rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) . '%';
			if ( null !== $l['rate_bp'] && $pct > $l['rate_bp'] / 100 + 0.0001 ) {
				return array( true, $p . ' is over the ' . self::pct_text( $l['rate_bp'] ) . ' limit' );
			}
			if ( null !== $l['cap_cents'] && $pretax > 0 ) {
				$share = (int) round( $pretax * $pct / 100 );
				if ( $share > $l['cap_cents'] ) {
					return array( true, $p . ' of this price is ' . self::money( $share ) . ', over the ' . self::money( $l['cap_cents'] ) . ' limit' );
				}
			}
			return array( false, '' );
		}
		$limit = self::limit_cents( $pretax );
		if ( null === $limit || ( $pretax <= 0 && null === $l['cap_cents'] ) ) {
			return array( false, '' );
		}
		if ( $cents > $limit ) {
			return array( true, self::money( $cents ) . ' is over the ' . self::money( $limit ) . ' limit' . ( $pretax > 0 ? ' for this price' : '' ) );
		}
		return array( false, '' );
	}

	/** The limit in words: "$1,000 or 10% of the price, whichever is less", "10% of the price", "$500". */
	public static function describe(): string {
		$l = self::get();
		if ( null !== $l['rate_bp'] && null !== $l['cap_cents'] ) {
			return self::money( $l['cap_cents'] ) . ' or ' . self::pct_text( $l['rate_bp'] ) . ' of the price, whichever is less';
		}
		if ( null !== $l['rate_bp'] ) {
			return self::pct_text( $l['rate_bp'] ) . ' of the price';
		}
		return null !== $l['cap_cents'] ? self::money( $l['cap_cents'] ) : '';
	}

	/* ------------------------------------------------------------------ the app's own sentence */

	private static function convention( string $key, string $default ): string {
		$v = $default;
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'get' ) ) {
			try {
				$got = ZDZ_Doc_Conventions::get( 'estimate.' . $key, null );
				if ( is_string( $got ) && '' !== trim( $got ) ) {
					$v = trim( $got );
				}
			} catch ( \Throwable $e ) {
				$v = $default;
			}
		}
		return $v;
	}

	/** "Deposit: {amount}, ..." with {amount} kept; a template without {amount} falls back to the default. */
	public static function template(): string {
		$t = self::convention( 'deposit_sentence', self::SENTENCE );
		return false === strpos( $t, '{amount}' ) ? self::SENTENCE : $t;
	}

	public static function balance(): string {
		return self::convention( 'balance_sentence', self::BALANCE );
	}

	public static function short_sentence( int $pretax ): string {
		return str_replace( '{amount}', self::money( self::cap_cents( $pretax ) ), self::template() );
	}

	public static function lawful_sentence( int $pretax ): string {
		return self::short_sentence( $pretax ) . ' ' . self::balance();
	}

	/** The app's own sentence as a pattern: group 1 is its figure. */
	public static function own_re(): string {
		$parts = explode( '{amount}', self::template(), 2 );
		$fig   = '\$((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{2})?)';
		return '/' . preg_quote( $parts[0], '/' ) . $fig . preg_quote( $parts[1] ?? '', '/' ) . '(?:[ \t]+' . preg_quote( self::balance(), '/' ) . ')?/';
	}

	/* ------------------------------------------------------------------ the business's words */

	/** A configured word list: lower-case words and short phrases (letters, spaces, hyphens), at most 60. */
	public static function words( string $which ): array {
		if ( isset( self::$words[ $which ] ) ) {
			return self::$words[ $which ];
		}
		$opt = 'rate' === $which ? self::RATE_OPT : self::PRODUCT_OPT;
		$raw = function_exists( 'get_option' ) ? get_option( $opt, array() ) : array();
		if ( function_exists( 'apply_filters' ) ) {
			$raw = apply_filters( $opt, $raw );
		}
		$out = array();
		foreach ( (array) $raw as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[a-z][a-z \-]{1,39}$/', $w ) ) {
				$out[ $w ] = true;
			}
		}
		return self::$words[ $which ] = array_slice( array_keys( $out ), 0, 60 );
	}

	/**
	 * A regex alternation of $core fragments and the configured words (each with an optional plural), never empty and
	 * never with an empty branch.
	 */
	public static function alt( array $core, string $which = 'product' ): string {
		$alts = array_values( array_filter( array_map( 'strval', $core ), 'strlen' ) );
		foreach ( self::words( $which ) as $w ) {
			$alts[] = str_replace( array( ' ', '\\-' ), array( '\s+', '\s*-?\s*' ), preg_quote( $w, '/' ) ) . '(?:e?s)?';
		}
		return $alts ? implode( '|', $alts ) : '(?!)';
	}
}
