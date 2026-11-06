<?php
/**
 * ZJOB_Project_Types — what kind of work a project is.
 *
 * The list of kinds is the business's own (slug => label): a window cleaner, a landscaper and a
 * fabrication shop have nothing in common here, so Core ships none. It comes from the
 * `zjob_project_types` option (written by the Identity Pack's org file, or by an admin) and the
 * `zjob_project_types` filter. One kind may be named as the business's "service and repairs"
 * kind (the `zjob_repair_type` option): a line that says it is a repair is tagged with it.
 *
 * WHERE A LINE'S KINDS COME FROM — facts in the catalog, never a guess:
 *   each catalog item the line names (ZDZ_Item_Engine::match_all, the item's own names and
 *   aliases) carries its kind in the item attribute `project_type`. A line whose name names no
 *   item lets its description decide.
 *
 * Other apps read the list and the classifier; Team Roles ties a kind of work to a kind of
 * project, and Territories reads which items a line names.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZJOB_Project_Types' ) ) {

	class ZJOB_Project_Types {

		const OPTION        = 'zjob_project_types';
		const REPAIR_OPTION = 'zjob_repair_type';
		const MAX_TYPES     = 40;

		/** @var array|null per-request memo */
		private static $memo = null;

		public static function flush_memo(): void {
			self::$memo = null;
		}

		/**
		 * The kinds, in the business's order. slug => label. Empty until the business names some.
		 *
		 * @return array<string,string>
		 */
		public static function types(): array {
			if ( null !== self::$memo ) {
				return self::$memo;
			}
			$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
			$raw = apply_filters( 'zjob_project_types', is_array( $raw ) ? $raw : array() );
			$out = array();
			foreach ( is_array( $raw ) ? $raw : array() as $slug => $label ) {
				if ( is_array( $label ) ) { // the canonical pack shape: [ {slug, label}, … ]
					$slug  = (string) ( $label['slug'] ?? '' );
					$label = (string) ( $label['label'] ?? $slug );
				}
				$slug  = strtolower( (string) preg_replace( '/[^a-z0-9_]/i', '', (string) $slug ) );
				$label = trim( wp_strip_all_tags( (string) $label ) );
				if ( '' !== $slug && '' !== $label && count( $out ) < self::MAX_TYPES ) {
					$out[ $slug ] = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 60 ) : substr( $label, 0, 60 );
				}
			}
			return self::$memo = $out;
		}

		/** The kind that means "service and repairs", when the business named one. '' otherwise. */
		public static function repair_type(): string {
			$t = function_exists( 'get_option' ) ? (string) get_option( self::REPAIR_OPTION, '' ) : '';
			$t = (string) apply_filters( 'zjob_repair_type', $t );
			return isset( self::types()[ $t ] ) ? $t : '';
		}

		/** The kind a catalog item belongs to ('' when it names none, or one this business does not have). */
		public static function type_for_item( $item ): string {
			if ( is_string( $item ) && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'get' ) ) {
				$item = ZDZ_Item_Engine::get( $item );
			}
			if ( ! is_array( $item ) ) {
				return '';
			}
			$t = strtolower( trim( (string) ( $item['attributes']['project_type'] ?? '' ) ) );
			return isset( self::types()[ $t ] ) ? $t : '';
		}

		/** The catalog items a piece of text names (best first). Empty without the Item Engine. */
		public static function items_named( string $text ): array {
			if ( '' === trim( $text ) || ! class_exists( 'ZDZ_Item_Engine' ) || ! method_exists( 'ZDZ_Item_Engine', 'match_all' ) ) {
				return array();
			}
			try {
				return array_values( array_filter( (array) ZDZ_Item_Engine::match_all( $text ), 'is_array' ) );
			} catch ( \Throwable $e ) {
				return array();
			}
		}

		/**
		 * The kinds one line names: the kinds of the catalog items its name names (its description's,
		 * when the name names none), plus the repair kind when the line says it is a repair.
		 *
		 * @return string[] slugs, in the business's order
		 */
		public static function types_for_line( string $name, string $desc = '' ): array {
			$out   = array();
			$items = self::items_named( $name );
			if ( ! $items && '' !== trim( $desc ) ) {
				$items = self::items_named( $desc );
			}
			foreach ( $items as $it ) {
				$t = self::type_for_item( $it );
				if ( '' !== $t ) {
					$out[ $t ] = true;
				}
			}
			$repair = self::repair_type();
			// The name always counts. A description counts only when it starts with a repair word:
			// descriptions are often sales copy ("never needs a repair").
			$d = strtolower( ltrim( $desc ) );
			if ( '' !== $repair && ( self::is_repair_text( $name ) || ( '' !== $d && preg_match( '/^(repair|fix\b|fixing\b)/', $d ) && self::is_repair_text( $desc ) ) ) ) {
				$out[ $repair ] = true;
			}
			return self::ordered( array_keys( $out ) );
		}

		/** Does this text say the work is a repair? "no repair needed" and the like do not count. */
		public static function is_repair_text( string $text ): bool {
			$l = strtolower( $text );
			if ( ! preg_match( '/\brepair(s|ed|ing)?\b|\bfix\b|\bfixing\b|\btorn\b|\bbroken\b|\bbent\b|\bpatch(ed|ing)?\b/', $l ) ) {
				return false;
			}
			return ! preg_match( '/\b(no|not|without)\s+(a\s+)?(repairs?|fix)\b|\b(repairs?|fix)\s+(is\s+|are\s+)?(not|no longer)\s+(needed|required)\b/', $l );
		}

		/** Slugs in the business's order, unknown ones dropped. */
		public static function ordered( array $slugs ): array {
			$set = array_flip( $slugs );
			return array_values( array_filter( array_keys( self::types() ), static function ( $s ) use ( $set ) {
				return isset( $set[ $s ] );
			} ) );
		}

		/** ",a,b," <-> array. */
		public static function encode( array $slugs ): string {
			$slugs = self::ordered( $slugs );
			return $slugs ? ',' . implode( ',', $slugs ) . ',' : '';
		}

		public static function decode( string $stored ): array {
			return self::ordered( array_filter( explode( ',', $stored ) ) );
		}

		/** Labels for stored kinds, for a card. */
		public static function labels( string $stored ): array {
			$types = self::types();
			return array_map( static function ( $s ) use ( $types ) { return $types[ $s ]; }, self::decode( $stored ) );
		}
	}
}
