<?php
/**
 * ZANA_Prompt_Builder — the single author of the chat system prompt.
 *
 * The assistant's ~740-line system prompt used to be an inline heredoc, the single
 * largest company artifact in the tree: it named the company, pasted a salesperson
 * roster (three of whom did not exist), embedded real customer PII as parse
 * examples, hardcoded per-rep revenue splits, and taught a dual-brand-by-ZIP rule as
 * a literal. This class replaces all of it with a GENERATED document assembled at
 * RUNTIME from the Core services, each section independently built and testable:
 *
 *   identity → facts → catalog → roster → permissions → conventions → rules
 *
 * Exactly ONE author. No typed company/person/product/place/provider name appears in
 * this file; every concrete value is read from a Core service at render time, so on
 * an empty install the prompt is neutral and honest, and a nonexistent roster name is
 * structurally impossible (the roster renders from ZDZ_Party — real users only).
 *
 * Describe, never prescribe: the prompt states how the business's OWN data is
 * structured and defers judgement to the systems of record; it does not carry one
 * tenant's accounting conventions as universal truth.
 *
 * Crosswalk: 05 §C (P-01…P-42, esp. P-02, P-03, P-06, P-07, P-16); Playbook §6.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Prompt_Builder {

	/** Bumped when the assembly changes; folded into cache keys downstream. */
	const VERSION = '1.1.0';

	/**
	 * Build the full system prompt for a turn.
	 *
	 * @param int   $user_id
	 * @param array $context { @type string $category, @type bool $is_kiosk, @type array $signals }
	 * @return string
	 */
	public static function build( int $user_id, array $context = array() ): string {
		$is_kiosk = ! empty( $context['is_kiosk'] )
			|| ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) );
		$context['is_kiosk'] = $is_kiosk;

		$sections = array(
			self::identity_block(),
			self::catalog_block(),
			self::roster_block( $user_id ),
			self::permissions_block( $user_id ),
			self::conventions_block(),
			self::rules_block( $user_id, $context ),
		);

		$sections = array_values( array_filter( array_map( 'trim', $sections ), static fn( $s ) => '' !== $s ) );
		return implode( "\n\n", $sections );
	}

	/* ─────────────────────────── Sections ────────────────────────────────── */

	/**
	 * Identity: "You are the analytics assistant for {name}, a {descriptor} in
	 * {area}." Rendered from the Business Profile; neutral when the profile is empty.
	 */
	public static function identity_block(): string {
		$name       = self::profile( 'legal_name', self::profile( 'name', '' ) );
		$descriptor = self::profile( 'industry_descriptor', '' );
		$area       = self::profile( 'service_area', '' );

		$who = __( 'You are the analytics assistant for this business.', 'zorderz' );
		if ( '' !== $name ) {
			$who = sprintf(
				/* translators: 1: business name, 2: industry descriptor, 3: service area */
				__( 'You are the analytics assistant for %1$s%2$s%3$s.', 'zorderz' ),
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

	/**
	 * Catalog: the count vocabulary and aliases from the Item Engine. Empty catalog
	 * ⇒ '' (the assistant simply has no product taxonomy to speak of, which is
	 * correct for a business that has not defined one).
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
			$label = (string) ( $meta['display_name'] ?? $id );
			$unit  = (string) ( $meta['unit_noun_plural'] ?? $meta['unit_noun_singular'] ?? '' );
			$lines[] = '• ' . $label . ( '' !== $unit ? ' (counted in ' . $unit . ')' : '' );
		}
		return "PRODUCTS & SERVICES (from the catalog — the count vocabulary):\n" . implode( "\n", $lines );
	}

	/**
	 * Roster: active selectable people, rendered from ZDZ_Party. The short code is
	 * published under the key `initials` (NOT `code`) and matched case-insensitively.
	 * Generated from live users, so a name that is not a real user cannot appear.
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
			// PARTY-ROSTER-CONTRACT-v1: the key is 'initials', not 'code'.
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
			$block = (string) ZDZ_Data_Permissions::build_prompt_block( $user_id );
			return trim( $block );
		}
		return '';
	}

	/**
	 * Conventions: per-user field/notation preferences (already per-user config).
	 * Neutral when none are set. Reads the theme's own preferences block if present.
	 */
	public static function conventions_block(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_field_preferences_prompt_block' ) ) {
			$uid = get_current_user_id();
			$block = (string) ZDZ_Core_Settings::get_field_preferences_prompt_block( $uid );
			return trim( $block );
		}
		return '';
	}

	/**
	 * Rules: the rendered rule set (the prompt IS this rendering). Placeholders are
	 * resolved from the Core services; the safety floor is always present.
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
		$name = self::profile( 'legal_name', self::profile( 'name', '' ) );
		$unit = 'items';
		if ( class_exists( 'ZDZ_Item_Engine' ) && ! ZDZ_Item_Engine::is_empty() ) {
			$cats = ZDZ_Item_Engine::count_categories();
			$first = is_array( $cats ) ? reset( $cats ) : array();
			$u = (string) ( $first['unit_noun_plural'] ?? '' );
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

	/**
	 * The system-of-record label. Comes from the connection config, NEVER a hardcoded
	 * vendor name; neutral default is "the billing system of record".
	 */
	public static function sor_label(): string {
		$label = (string) apply_filters( 'zdz_system_of_record_label', '' );
		return '' !== $label ? $label : __( 'the billing system of record', 'zorderz' );
	}

	/** The CRM label — from connection config; neutral default. */
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
