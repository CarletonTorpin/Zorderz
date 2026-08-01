<?php
/**
 * ZEST_Catalog — the estimate module's ONLY view of "what this business sells".
 *
 * The old estimate creator hardcoded the catalog three-and-a-half ways: a VALID_PRODUCTS
 * table (names, aliases/brand tokens, min/max sizes), an OUT_OF_SCOPE pattern list, a
 * DEFAULT_PRICES book, a second "pricing guide" baked into the parse prompt, and a
 * get_default_pricing() fallback — and the three price books disagreed. All of that is
 * IDENTITY. It now lives in the Item Engine (catalog + pricing schemes) and ships EMPTY.
 *
 * This class is a thin, cache-light adapter over the Item Engine's canonical resolver
 * API — the static class when it is loaded, else the mirrored `zdz_item_*` filters, so
 * there is no hard class dependency. With an EMPTY catalog every method degrades to a
 * neutral value (null / '' / [] / passthrough), so a fresh install parses estimates with
 * no prices and no scope-rejection rather than a baked-in guess. No product name, alias,
 * brand token, size or price literal appears anywhere in this module.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Catalog {

	/** Confidence floor below which an AI-parsed item is flagged, not auto-accepted. */
	const CONFIDENCE_THRESHOLD = 60;

	/** Catalog content version (folds into cache keys so an edit self-invalidates). */
	public static function version(): int {
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'version' ) ) {
			return (int) ZDZ_Item_Engine::version();
		}
		return (int) apply_filters( 'zdz_item_engine_version', 0 );
	}

	/** True when the tenant has defined a catalog. Empty catalog => neutral behaviour. */
	public static function has_catalog(): bool {
		return ! empty( self::kinds() );
	}

	/** Classify free text to a catalog item id, or null. */
	public static function classify( string $text ): ?string {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'classify' ) ) {
			$id = ZDZ_Item_Engine::classify( $text );
			return $id ?: null;
		}
		$id = apply_filters( 'zdz_item_classify', null, $text );
		return $id ? (string) $id : null;
	}

	/** Longest-alias-wins match to a full item array, or null. */
	public static function match( string $text, array $opts = array() ): ?array {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'match' ) ) {
			$m = ZDZ_Item_Engine::match( $text, $opts );
			return is_array( $m ) ? $m : null;
		}
		$m = apply_filters( 'zdz_item_match', null, $text, $opts );
		return is_array( $m ) ? $m : null;
	}

	/** One item by id, or null. */
	public static function get( string $item_id ): ?array {
		if ( '' === $item_id ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'get' ) ) {
			$it = ZDZ_Item_Engine::get( $item_id );
			return is_array( $it ) ? $it : null;
		}
		$it = apply_filters( 'zdz_item_get', null, $item_id );
		return is_array( $it ) ? $it : null;
	}

	/** Countable item ids ("kinds"). Empty catalog => []. */
	public static function kinds(): array {
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'kinds' ) ) {
			return (array) ZDZ_Item_Engine::kinds();
		}
		return (array) apply_filters( 'zdz_item_kinds', array() );
	}

	/** Count categories (id => meta) — the count vocabulary. */
	public static function count_categories(): array {
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'count_categories' ) ) {
			return (array) ZDZ_Item_Engine::count_categories();
		}
		return (array) apply_filters( 'zdz_item_count_categories', array() );
	}

	/**
	 * Resolve a price for an item in a context. Returns the engine's shape
	 * { amount, method, quote_only, explain } or a neutral no-price result when the
	 * catalog/scheme is empty. NEVER invents a number.
	 *
	 * @param string $item_id
	 * @param array  $ctx { qty:int, hours:float, area:float, width_in:float, height_in:float }
	 * @return array{amount:?float,method:string,quote_only:bool,explain:string}
	 */
	public static function resolve_price( string $item_id, array $ctx = array() ): array {
		$neutral = array( 'amount' => null, 'method' => 'none', 'quote_only' => false, 'explain' => '' );
		$item    = self::get( $item_id );
		$scheme  = $item['pricing_scheme_id'] ?? ( $item['pricing_scheme'] ?? '' );

		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'resolve_price' ) ) {
			$r = ZDZ_Item_Engine::resolve_price( $item_id, $ctx );
			return is_array( $r ) ? array_merge( $neutral, $r ) : $neutral;
		}
		if ( '' !== (string) $scheme ) {
			$r = apply_filters( 'zdz_pricing_resolve', null, (string) $scheme, $ctx );
			if ( is_array( $r ) ) {
				return array_merge( $neutral, $r );
			}
		}
		return $neutral;
	}

	/**
	 * Best-effort price for a free-text line: classify → resolve. Returns null when
	 * the catalog cannot price it (a fresh install always returns null — the engine
	 * then leaves the line at $0 for a human, rather than guessing).
	 */
	public static function price_for_text( string $text, array $ctx = array() ): ?float {
		$id = self::classify( $text );
		if ( null === $id ) {
			return null;
		}
		$r = self::resolve_price( $id, $ctx );
		return isset( $r['amount'] ) && null !== $r['amount'] ? (float) $r['amount'] : null;
	}

	/**
	 * Size bounds for an item, from its measurement attributes. Returns null when the
	 * item declares none (no CORE min/max is baked — a size limit is tenant data).
	 *
	 * @return array{min_w:?float,max_w:?float,min_h:?float,max_h:?float}|null
	 */
	public static function size_bounds( string $item_id ): ?array {
		$item = self::get( $item_id );
		if ( ! $item ) {
			return null;
		}
		$attr = (array) ( $item['attributes'] ?? array() );
		$has  = false;
		$b    = array( 'min_w' => null, 'max_w' => null, 'min_h' => null, 'max_h' => null );
		foreach ( array_keys( $b ) as $k ) {
			if ( isset( $attr[ $k ] ) && '' !== $attr[ $k ] ) {
				$b[ $k ] = (float) $attr[ $k ];
				$has     = true;
			}
		}
		return $has ? $b : null;
	}

	/**
	 * Scope decision for a free-text line. Returns a rejection message when the tenant
	 * has declared this text out of scope (a negative-alias / out-of-scope catalog
	 * entry), or '' to accept. Empty catalog => '' (accept everything — no baked
	 * "we don't do glass/roofing/…" list, which was pure identity).
	 */
	public static function out_of_scope_reason( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		/**
		 * Filter: a tenant's Item Engine (or a rule) may declare an out-of-scope match.
		 * Ships with no listener, so a fresh install rejects nothing.
		 *
		 * @param string $reason '' = in scope; non-empty = customer-safe rejection text.
		 * @param string $text
		 */
		return (string) apply_filters( 'zdz_item_out_of_scope', '', $text );
	}

	/**
	 * A compact, catalog-derived summary for prompt assembly: the accepted item names,
	 * their aliases and any declared size bounds — generated from the tenant's own
	 * catalog, never a typed list. Empty catalog => '' (the prompt then simply omits a
	 * catalog section). Examples for the model come from here or obvious placeholders,
	 * never from a hardcoded roster of products.
	 */
	public static function prompt_block(): string {
		$kinds = self::kinds();
		if ( empty( $kinds ) ) {
			return '';
		}
		$lines = array();
		foreach ( $kinds as $id ) {
			$item = self::get( (string) $id );
			if ( ! $item ) {
				continue;
			}
			$name    = (string) ( $item['display_name'] ?? $item['name'] ?? $id );
			$aliases = array_map( 'strval', (array) ( $item['aliases'] ?? array() ) );
			$row     = '- ' . $name;
			if ( $aliases ) {
				$row .= ' (' . implode( ', ', array_slice( $aliases, 0, 8 ) ) . ')';
			}
			$b = self::size_bounds( (string) $id );
			if ( $b && ( null !== $b['max_w'] || null !== $b['max_h'] ) ) {
				$row .= sprintf(
					' [size %s-%s W x %s-%s H]',
					self::num( $b['min_w'] ), self::num( $b['max_w'] ),
					self::num( $b['min_h'] ), self::num( $b['max_h'] )
				);
			}
			$lines[] = $row;
		}
		return $lines ? "## Product catalog (accept these)\n" . implode( "\n", $lines ) : '';
	}

	private static function num( $v ): string {
		return null === $v ? '?' : rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
	}
}
