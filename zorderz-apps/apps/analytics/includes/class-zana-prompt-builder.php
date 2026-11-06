<?php
/**
 * ZANA_Prompt_Builder — the single author of the chat's instructions.
 *
 * The instructions are GENERATED at runtime from the Core services, each section built on its own and testable:
 *
 *   fixed (the same for everyone, every turn)      identity → voice → formatting
 *   level (the same for everyone at one level)     catalog → roster
 *   this person, this turn                         permissions → conventions → prices → app actions → rules
 *
 * with the Ai router's cache marks between the three parts (ZANA_LLM_Client::SPLIT, TIER_SPLIT), so the fixed parts are
 * sent as cached blocks and only the last part is paid in full on every call.
 *
 * No business, person, product, place or provider name is written in this file; every concrete value is read from a
 * Core service at render time, so on an empty install the instructions are neutral and honest, and a name that is not a
 * real user can never appear in the roster (it renders from ZDZ_Party). The assistant has no name of its own unless the
 * business gives it one in its voice pack (voice.yml → option `zana_voice`, or the filter `zdz_assistant_voice`).
 *
 * Describe, never prescribe: the instructions state how the business's OWN data is structured and defer judgement to
 * its systems of record.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Prompt_Builder {

	/** Bumped when the assembly changes; folded into cache keys downstream. */
	const VERSION = '1.2.0';

	/** The voice option a voice pack writes: { name: string, lines: string[] }. */
	const VOICE_OPT = 'zana_voice';

	/**
	 * Build the full system text for a turn: fixed . SPLIT . level . TIER_SPLIT . the rest.
	 *
	 * @param int   $user_id
	 * @param array $context { @type string $category, @type bool $is_kiosk, @type bool $privacy_view, @type array $signals }
	 * @return string
	 */
	public static function build( int $user_id, array $context = array() ): string {
		$is_kiosk = ! empty( $context['is_kiosk'] )
			|| ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) );
		$context['is_kiosk'] = $is_kiosk;

		$fixed = self::join( array(
			self::identity_block(),
			self::voice_block(),
			self::formatting_block(),
		) );
		$level = self::join( array(
			self::catalog_block(),
			self::roster_block( $user_id ),
		) );
		$rest  = self::join( array(
			self::permissions_block( $user_id ),
			self::conventions_block(),
			self::price_book_block( $user_id ),
			class_exists( 'ZANA_Actions' ) && ! $is_kiosk ? ZANA_Actions::prompt_block( $user_id, ! empty( $context['privacy_view'] ) ) : '',
			self::rules_block( $user_id, $context ),
		) );

		$split = class_exists( 'ZANA_LLM_Client' ) ? array( ZANA_LLM_Client::SPLIT, ZANA_LLM_Client::TIER_SPLIT ) : array( "\n\n", "\n\n" );
		return $fixed . $split[0] . $level . $split[1] . $rest;
	}

	private static function join( array $sections ): string {
		$sections = array_values( array_filter( array_map( 'trim', $sections ), static fn( $s ) => '' !== $s ) );
		return implode( "\n\n", $sections );
	}

	/* ─────────────────────────── Sections ────────────────────────────────── */

	/** The assistant's name: the voice pack's, else "the assistant". */
	public static function assistant_name(): string {
		$v    = self::voice();
		$name = trim( (string) ( $v['name'] ?? '' ) );
		return '' !== $name ? $name : __( 'the assistant', 'zorderz' );
	}

	/**
	 * The voice pack: { name, lines[] }. Core ships none: a neutral assistant with no persona. Lines are short sentences
	 * about how to sound (never facts, prices or people); anything that is not a string is dropped.
	 */
	public static function voice(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::VOICE_OPT, array() ) : array();
		$v = is_array( $v ) ? $v : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zdz_assistant_voice', $v );
			$v = is_array( $f ) ? $f : $v;
		}
		$lines = array();
		foreach ( (array) ( $v['lines'] ?? array() ) as $l ) {
			if ( is_string( $l ) && '' !== trim( $l ) ) {
				$lines[] = mb_substr( trim( preg_replace( '/\s+/', ' ', $l ) ), 0, 400 );
			}
		}
		return array(
			'name'  => is_string( $v['name'] ?? null ) ? mb_substr( trim( $v['name'] ), 0, 60 ) : '',
			'lines' => array_slice( $lines, 0, 40 ),
		);
	}

	/**
	 * Identity: "You are the assistant for {name}, a {descriptor} in {area}." Rendered from the Business Profile;
	 * neutral when the profile is empty.
	 */
	public static function identity_block(): string {
		$name       = self::profile( 'identity.trading_name', '' );
		$descriptor = self::profile( 'identity.industry', '' );
		$area       = (string) apply_filters( 'zdz_service_area_label', '' );
		$self       = self::assistant_name();

		$who = sprintf(
			/* translators: %s: the assistant's name ("the assistant" unless the business named it) */
			__( 'You are %s for this business.', 'zorderz' ),
			$self
		);
		if ( '' !== $name ) {
			$who = sprintf(
				/* translators: 1: the assistant's name, 2: business name, 3: industry descriptor, 4: service area */
				__( 'You are %1$s for %2$s%3$s%4$s.', 'zorderz' ),
				$self,
				$name,
				'' !== $descriptor ? ', ' . $descriptor : '',
				'' !== $area ? ' in ' . $area : ''
			);
		}

		$sor = self::sor_label();
		return $who . ' '
			. sprintf(
				/* translators: %s: the system-of-record label */
				__( 'You report on the business\'s own records held in %s. All figures are historical business data — not financial advice or market analysis.', 'zorderz' ),
				$sor
			);
	}

	/** Voice: the business's own lines on how to sound. '' when it has none. */
	public static function voice_block(): string {
		$lines = self::voice()['lines'];
		if ( ! $lines ) {
			return '';
		}
		return "VOICE (how to sound; never a fact):\n- " . implode( "\n- ", $lines );
	}

	/** How an answer is written: the chat shows plain text and simple lists, not LaTeX. */
	public static function formatting_block(): string {
		return "FORMATTING:\n"
			. "- Write math as plain text a person would write: 12 × 4 = 48, $1,200 ÷ 30 = $40, a ≤ b. Never LaTeX, never \$…\$ or \\(…\\).\n"
			. "- Lead with the answer to exactly what was asked, then the detail that supports it; no more than the question needs.\n"
			. "- If a figure is not in the data you were given, say so plainly. Never invent one.";
	}

	/**
	 * Catalog: the count vocabulary and aliases from the Item Engine. Empty catalog ⇒ '' (the assistant has no product
	 * taxonomy to speak of, which is correct for a business that has not defined one).
	 */
	public static function catalog_block(): string {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ZDZ_Item_Engine::is_empty() ) {
			return '';
		}
		$cats = ZDZ_Item_Engine::count_categories();
		if ( empty( $cats ) ) {
			return '';
		}
		$lines = array();
		foreach ( $cats as $id => $meta ) {
			$label   = (string) ( $meta['display_name'] ?? $id );
			$unit    = (string) ( $meta['unit_noun_plural'] ?? $meta['unit_noun_singular'] ?? '' );
			$lines[] = '• ' . $label . ( '' !== $unit ? ' (counted in ' . $unit . ')' : '' );
		}
		return "PRODUCTS & SERVICES (from the catalog — the count vocabulary):\n" . implode( "\n", $lines );
	}

	/**
	 * Roster: active selectable people, rendered from ZDZ_Party. The short code is published under the key `initials`
	 * and matched case-insensitively. Generated from live users, so a name that is not a real user cannot appear.
	 */
	public static function roster_block( int $user_id ): string {
		if ( ! class_exists( 'ZDZ_Party' ) ) {
			return '';
		}
		$people = ZDZ_Party::selectable_people( array( 'include_self' => true ) );
		if ( empty( $people ) ) {
			return '';
		}
		$lines = array();
		foreach ( $people as $p ) {
			$name = (string) ( $p['name'] ?? '' );
			$ini  = strtoupper( (string) ( $p['initials'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$lines[] = '• ' . $name . ( '' !== $ini ? ' (' . $ini . ')' : '' );
		}
		if ( empty( $lines ) ) {
			return '';
		}
		return "TEAM ROSTER (people you may be asked about; match a short code case-insensitively):\n" . implode( "\n", $lines );
	}

	/** Permissions: what this reader may see, from the one permission authority. */
	public static function permissions_block( int $user_id ): string {
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'build_prompt_block' ) ) {
			return trim( (string) ZDZ_Data_Permissions::build_prompt_block( $user_id ) );
		}
		return '';
	}

	/** Conventions: per-person field/notation preferences. Neutral when none are set. */
	public static function conventions_block(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_field_preferences_prompt_block' ) ) {
			return trim( (string) ZDZ_Core_Settings::get_field_preferences_prompt_block( get_current_user_id() ) );
		}
		return '';
	}

	/**
	 * May this person see prices? The estimate app's blind-pricing switch decides: while it is off, everyone who uses
	 * the app sees prices, as on an estimate; while it is on, only someone the estimate app's field permissions let view
	 * pricing. With the switch on and the estimate app absent: the admin and operator tiers only (fail closed). Never the
	 * shared device.
	 */
	public static function may_see_prices( int $uid ): bool {
		if ( $uid <= 0 || ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) || ! (bool) get_option( 'zest_blind_pricing', false ) ) {
			return true;
		}
		if ( class_exists( 'ZEST_Dashboard' ) && is_callable( array( 'ZEST_Dashboard', 'get_resolved_permissions' ) ) ) {
			try {
				$p = (array) ZEST_Dashboard::get_resolved_permissions( $uid );
				return ! empty( $p['view_pricing'] ) || ! empty( $p['unit_price']['view'] );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		$tier = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::bridge_tier( $uid ) : '';
		return in_array( $tier, array( 'admin', 'operator' ), true );
	}

	/**
	 * The published price book as a block: each family's official customer prices, read live from ZDZ_Price_Book (never
	 * copied), or -- for someone who may not see prices -- one rule saying not to quote them. Prices only: a cost never
	 * leaves this method. '' when there is no book.
	 */
	public static function price_book_block( int $uid ): string {
		if ( ! class_exists( 'ZDZ_Price_Book' ) || ! is_callable( array( 'ZDZ_Price_Book', 'published' ) ) ) {
			return '';
		}
		try {
			$book = ZDZ_Price_Book::published();
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $book ) || empty( $book['families'] ) || ! is_array( $book['families'] ) ) {
			return '';
		}
		$clean = function ( $s, $max = 500 ) {
			return is_callable( array( 'ZDZ_Price_Book', 'clean_text' ) ) ? ZDZ_Price_Book::clean_text( (string) $s, $max ) : trim( (string) $s );
		};
		$labels = array();
		foreach ( $book['families'] as $key => $fam ) {
			$labels[] = $clean( is_array( $fam ) ? ( $fam['label'] ?? $key ) : $key, 120 );
		}
		$names = implode( ', ', array_filter( $labels ) );
		if ( ! self::may_see_prices( $uid ) ) {
			return "PRICES: NOT FOR THIS PERSON\nThis person's permissions do not include prices. Do NOT quote, estimate or hint at a price for {$names}"
				. ' (or any figure from a price book). If asked, say an office manager or sales lead can give the price.';
		}
		$version = $clean( (string) ( $book['version'] ?? '' ), 60 );
		$out     = 'OFFICIAL PRICE BOOK' . ( '' !== $version ? " (version {$version})" : '' ) . "\n"
			. 'These are the official CUSTOMER prices, read live from the published price book -- the same prices an estimate bills.'
			. ' For the products below they OVERRIDE every other price in these instructions. Quote them exactly. For a size, option or'
			. " product not listed, say a salesperson will quote it. Never mention cost, dealer price, markup or margin.\n";
		foreach ( $book['families'] as $key => $fam ) {
			if ( ! is_array( $fam ) || empty( $fam['price_rules'] ) ) {
				continue;
			}
			$out .= '── ' . $clean( $fam['label'] ?? $key, 120 ) . " ──\n";
			foreach ( (array) $fam['price_rules'] as $what => $price ) {
				$out .= '• ' . $clean( $what ) . ': ' . $clean( $price ) . "\n";
			}
		}
		return rtrim( $out );
	}

	/**
	 * Rules: the rendered rule set (the instructions ARE this rendering). Placeholders are resolved from the Core
	 * services; the safety floor is always present.
	 */
	public static function rules_block( int $user_id, array $context ): string {
		if ( ! class_exists( 'ZDZ_Rule_Governance' ) ) {
			return '';
		}
		$selected = ZDZ_Rule_Governance::select( $context );
		return ZDZ_Rule_Governance::render( $selected, self::rule_params() );
	}

	/* ─────────────────────────── Helpers ─────────────────────────────────── */

	/** The placeholder values the rule directives interpolate. */
	public static function rule_params(): array {
		$name = self::profile( 'identity.trading_name', '' );
		$unit = 'items';
		if ( class_exists( 'ZDZ_Item_Engine' ) && ! ZDZ_Item_Engine::is_empty() ) {
			$cats  = ZDZ_Item_Engine::count_categories();
			$first = is_array( $cats ) ? reset( $cats ) : array();
			$u     = (string) ( $first['unit_noun_plural'] ?? '' );
			if ( '' !== $u ) {
				$unit = $u;
			}
		}
		return array(
			'business_name'      => '' !== $name ? $name : 'this business',
			'system_of_record'   => self::sor_label(),
			'crm_name'           => self::crm_label(),
			'counting_component' => (string) apply_filters( 'zdz_counting_component_label', __( 'the authoritative counting component', 'zorderz' ) ),
			'unit_noun'          => $unit,
		);
	}

	/** The system-of-record label: from the connection config, never a vendor's name in code. */
	public static function sor_label(): string {
		$label = (string) apply_filters( 'zdz_system_of_record_label', '' );
		return '' !== $label ? $label : __( 'the billing system of record', 'zorderz' );
	}

	/** The CRM label: from the connection config; neutral default. */
	public static function crm_label(): string {
		$label = (string) apply_filters( 'zdz_crm_label', '' );
		return '' !== $label ? $label : __( 'the CRM', 'zorderz' );
	}

	/** Read a Business Profile path with a neutral fallback. */
	private static function profile( string $path, string $default = '' ): string {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$v = ZDZ_Business_Profile::get( $path, $default );
			return is_string( $v ) ? $v : $default;
		}
		return $default;
	}
}
