<?php
/**
 * Zorderz Prep — settings + identity resolution.
 *
 * Everything that would differ between businesses lives here as configuration, read from
 * a Core service or a tenant option/filter, never hardcoded:
 *
 *   - the QUEUE TAG + queue SUBTYPE (what makes a job "prep work" — was a hardcoded
 *     reference token bound to a single product line),
 *   - the ROLL / MATERIAL model (widths, colours, availability) with supplier costs that
 *     SHIP EMPTY,
 *   - the fabrication WORKSPACE profile (table sizes, margins, sheet-length caps — an
 *     operations concept, not a material property),
 *   - the CRM cut STAGE name + the AI model,
 *   - the cut-sheet LETTERHEAD (Business Profile),
 *   - Core-service accessors for CRM / AI / billing (Connections layer),
 *   - the Item-Engine seams for the piece vocabulary, "is this a cut piece?", and default
 *     sizes — with NEUTRAL fallbacks so an empty catalog never crashes.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Settings {

	/* ─────────────────────────────────────────────────────────────────
	 * Stored settings + defaults. Costs default to 0.00 (EMPTY): the roll
	 * WIDTHS/COLOURS are the fabrication mechanism (tenant-editable), but no
	 * supplier price ships in code.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return array The default settings blob. Roll costs are 0 (ship empty). */
	public static function defaults(): array {
		return array(
			'grungy_end_margin_in' => 0.5,
			'min_saveable_in'      => 12.0,
			'black_tiebreaker'     => 'fewest_sheets',
			'ai_model'             => '',   // '' => fall back to ZDZ_Core_Settings::get_ai_model().
			'queue_tag'            => '',   // '' => accept every job (no product-line gate).
			'queue_subtype'        => '',   // '' => no Item-Engine subtype gate.
			'cut_stage_name'       => '',   // '' => auto-queue disabled; lookup still works.
			'rolls'                => array(),   // '' / [] => use the neutral default roll set below.
		);
	}

	/** @return array Merged stored settings over defaults. */
	public static function all(): array {
		$opts = get_option( 'zprep_settings', array() );
		return array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
	}

	/** @return mixed One setting value. */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Core-service accessors (Connections layer). Fail soft (return null)
	 * rather than fabricate a client from copied credentials.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return object|null CRM client, or null when unavailable/unconfigured. */
	public static function crm() {
		if ( ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return null;
		}
		$ns = new ZDZ_Core_Nutshell();
		return $ns->is_configured() ? $ns : null;
	}

	/** @return object|null AI client, or null when unavailable. */
	public static function ai() {
		if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
			return null;
		}
		return new ZDZ_Core_Poe( '', self::ai_model() );
	}

	/** @return object|null Billing provider client, or null when unavailable/unconfigured. */
	public static function billing() {
		if ( ! class_exists( 'ZDZ_Core_Freshbooks' ) ) {
			return null;
		}
		$fb = new ZDZ_Core_Freshbooks();
		return $fb->is_configured() ? $fb : null;
	}

	/** @return string The Poe model to use for measurement parsing. */
	public static function ai_model(): string {
		$m = trim( (string) self::get( 'ai_model', '' ) );
		if ( '' === $m && class_exists( 'ZDZ_Core_Settings' ) ) {
			$m = (string) ZDZ_Core_Settings::get_ai_model();
		}
		return $m;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * The QUEUE TAG + SUBTYPE — the generalized replacement for the baked-in
	 * single hardcoded product line. A queue tag is a reference token that marks a
	 * job as prep work; a queue subtype binds the queue to an Item-Engine
	 * subtype so the product-line gate reads the item's own subtype (RC-03),
	 * not a substring in a routing code. BOTH ship EMPTY.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return string The reference-code token that marks a prep job. '' = accept all. */
	public static function queue_tag(): string {
		return strtoupper( trim( (string) apply_filters( 'zprep_queue_tag', self::get( 'queue_tag', '' ) ) ) );
	}

	/** @return string The Item-Engine subtype the queue is bound to. '' = no subtype gate. */
	public static function queue_subtype(): string {
		return trim( (string) apply_filters( 'zprep_queue_subtype', self::get( 'queue_subtype', '' ) ) );
	}

	/**
	 * Does a reference/description qualify for the prep queue?
	 *
	 * Resolution order (nothing hardcoded to a trade):
	 *   1. Item Engine — if a queue subtype is configured, ask the catalog whether the
	 *      text names an item of that subtype (the product-line gate reads the subtype).
	 *   2. Queue tag  — if a tag is configured, require it as a reference token.
	 *   3. Neither configured — accept (empty catalog / fresh install => allow-all, with
	 *      a logged disposition so "we accepted everything" is never silent).
	 *
	 * @param string $reference The record's reference/PO token.
	 * @param string $text      Free text (description / notes) to classify.
	 * @return bool
	 */
	public static function job_in_queue( string $reference, string $text = '' ): bool {
		$subtype = self::queue_subtype();
		if ( '' !== $subtype && ( class_exists( 'ZDZ_Item_Engine' ) || has_filter( 'zdz_item_match' ) ) ) {
			$hay  = trim( $reference . ' ' . $text );
			$item = self::item_match( $hay, array( 'subtype' => $subtype ) );
			if ( is_array( $item ) ) {
				return true;
			}
			// Subtype configured but nothing matched: fall through to the tag gate.
		}

		$tag = self::queue_tag();
		if ( '' !== $tag ) {
			$letters = strtoupper( preg_replace( '/[^A-Za-z]/', '', $reference . $text ) );
			return '' !== $letters && strpos( $letters, preg_replace( '/[^A-Z]/', '', $tag ) ) !== false;
		}

		// Nothing configured — allow-all, but record the disposition (nothing silent).
		self::disposition( 'queue_allow_all', array( 'reason' => 'no queue tag or subtype configured' ) );
		return true;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * ROLL / MATERIAL model. Widths + colours are the fabrication mechanism
	 * (tenant-editable); COSTS SHIP EMPTY. "60-inch white doesn't exist" is
	 * expressed as the ABSENCE of that entry in the availability set, not a
	 * hardcoded isset() guard.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * The roll/material set. Each entry: { width_in:int, color:string, cost_per_ft:float,
	 * available:bool }. Ships a NEUTRAL default set (widths + generic colours) with ZERO
	 * costs; a tenant overrides via Settings or the `zprep_rolls` filter.
	 *
	 * @return array<int,array{width_in:int,color:string,cost_per_ft:float,available:bool}>
	 */
	public static function rolls(): array {
		$stored = self::get( 'rolls', array() );
		$rolls  = ( is_array( $stored ) && ! empty( $stored ) ) ? $stored : self::default_rolls();

		$out = array();
		foreach ( (array) $rolls as $r ) {
			$w = (int) ( $r['width_in'] ?? 0 );
			$c = strtolower( trim( (string) ( $r['color'] ?? '' ) ) );
			if ( $w <= 0 || '' === $c ) {
				continue;
			}
			$out[] = array(
				'width_in'    => $w,
				'color'       => $c,
				'cost_per_ft' => max( 0.0, (float) ( $r['cost_per_ft'] ?? 0.0 ) ), // EMPTY by default.
				'available'   => ! isset( $r['available'] ) || (bool) $r['available'],
			);
		}
		/**
		 * Filter the roll/material set. NEUTRAL default; costs empty.
		 *
		 * @param array $out Roll entries.
		 */
		return (array) apply_filters( 'zprep_rolls', $out );
	}

	/**
	 * The neutral default roll set: two widths in a dark colour + one in a light colour.
	 * This is the fabrication MECHANISM (so the tool works for a demo), carrying NO brand
	 * names and NO costs. Note there is deliberately no light 60" entry — availability is
	 * data, so "that width/colour doesn't exist" is simply its absence here.
	 *
	 * @return array
	 */
	private static function default_rolls(): array {
		return array(
			array( 'width_in' => 36, 'color' => 'black', 'cost_per_ft' => 0.0, 'available' => true ),
			array( 'width_in' => 60, 'color' => 'black', 'cost_per_ft' => 0.0, 'available' => true ),
			array( 'width_in' => 36, 'color' => 'white', 'cost_per_ft' => 0.0, 'available' => true ),
		);
	}

	/** @return int[] Available roll widths for a colour, widest first-agnostic (as declared). */
	public static function roll_widths_for_color( string $color ): array {
		$color = strtolower( trim( $color ) );
		$out   = array();
		foreach ( self::rolls() as $r ) {
			if ( $r['available'] && $r['color'] === $color ) {
				$out[] = (int) $r['width_in'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return float Per-linear-foot cost for a (width,color) roll. 0.0 when unset (empty). */
	public static function roll_cost_per_ft( int $width_in, string $color ): float {
		$color = strtolower( trim( $color ) );
		foreach ( self::rolls() as $r ) {
			if ( (int) $r['width_in'] === $width_in && $r['color'] === $color ) {
				return (float) $r['cost_per_ft'];
			}
		}
		return 0.0;
	}

	/** @return string[] The distinct roll colours available (for the UI + parser vocab). */
	public static function roll_colors(): array {
		$out = array();
		foreach ( self::rolls() as $r ) {
			if ( $r['available'] ) {
				$out[ $r['color'] ] = true;
			}
		}
		$colors = array_keys( $out );
		return $colors ?: array( 'black' );
	}

	/** @return string The default roll colour (first declared available colour). */
	public static function default_color(): string {
		$colors = self::roll_colors();
		return $colors[0] ?? 'black';
	}

	/* ─────────────────────────────────────────────────────────────────
	 * FABRICATION WORKSPACE profile (MU-07). An operations concept — table
	 * sizes, margins, sheet-length caps — deliberately NOT a material property.
	 * Core mechanism defaults, tenant-overridable.
	 * ────────────────────────────────────────────────────────────────── */

	public static function grungy_margin_in(): float {
		return max( 0.0, (float) self::get( 'grungy_end_margin_in', 0.5 ) );
	}

	public static function min_saveable_in(): float {
		return max( 0.0, (float) self::get( 'min_saveable_in', 12.0 ) );
	}

	public static function black_tiebreaker(): string {
		$v = (string) self::get( 'black_tiebreaker', 'fewest_sheets' );
		return in_array( $v, array( 'fewest_sheets', 'shortest_length' ), true ) ? $v : 'fewest_sheets';
	}

	/**
	 * Max printable sheet length (inches) for a given roll width — "what a human can
	 * carry", not a material limit. Overridable via `zprep_max_page_length`.
	 *
	 * @param int $roll_w Roll width in inches.
	 * @return float
	 */
	public static function max_page_length_in( int $roll_w ): float {
		$default = ( $roll_w >= 60 ) ? 48.0 : 60.0;
		return (float) apply_filters( 'zprep_max_page_length', $default, $roll_w );
	}

	/** @return float The per-batch planning max sheet length. */
	public static function max_sheet_length_in(): float {
		return (float) apply_filters( 'zprep_max_sheet_length', 60.0 );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * CRM cut STAGE + letterhead.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * The CRM pipeline stage that means "approved, ready to cut". Ships EMPTY — each CRM
	 * names its stages differently. When empty, the auto-queue is disabled (the lookup
	 * box still works). Overridable via `zprep_cut_stage_name`.
	 *
	 * @return string
	 */
	public static function cut_stage_name(): string {
		return trim( (string) apply_filters( 'zprep_cut_stage_name', self::get( 'cut_stage_name', '' ) ) );
	}

	/** @return string The cut-sheet letterhead, from Business Profile (site name fallback). */
	public static function letterhead(): string {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$n = trim( (string) ZDZ_Business_Profile::name() );
			if ( '' !== $n ) {
				return $n;
			}
		}
		return (string) get_bloginfo( 'name' );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * ITEM-ENGINE seams. The piece vocabulary, "is this a cut piece?" and
	 * default sizes come from the catalog; an EMPTY catalog degrades to a
	 * neutral geometry rule. Nothing here hardcodes a piece list.
	 * ────────────────────────────────────────────────────────────────── */

	/** Item Engine match with the mirrored-filter fallback. @return array|null item. */
	public static function item_match( string $text, array $opts = array() ) {
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			return ZDZ_Item_Engine::match( $text, $opts );
		}
		$pre = apply_filters( 'zdz_item_match', null, $text, $opts );
		return is_array( $pre ) ? $pre : null;
	}

	/** Item Engine get with the mirrored-filter fallback. @return array|null item. */
	public static function item_get( string $item_id ) {
		if ( '' === $item_id ) {
			return null;
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			return ZDZ_Item_Engine::get( $item_id );
		}
		$pre = apply_filters( 'zdz_item_get', null, $item_id );
		return is_array( $pre ) ? $pre : null;
	}

	/**
	 * Human label for a piece "kind". The kind is either an Item Engine item id (from
	 * classification) or the AI's own free token when the catalog is empty. Prefer the
	 * catalog's display name; otherwise humanise the token — never a hardcoded map.
	 *
	 * @param string $kind
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		$kind = trim( $kind );
		if ( '' === $kind ) {
			return __( 'Piece', 'zorderz' );
		}
		$item = self::item_get( $kind );
		if ( is_array( $item ) && ! empty( $item['display_name'] ) ) {
			return (string) $item['display_name'];
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $kind ) );
	}

	/**
	 * Default fabrication dimensions for a kind that arrived with no size (e.g. a piece
	 * the installer trims on-site). Read from the item's attributes when the catalog has
	 * it; NEUTRAL fallback = null (the engine then flags "needs dimensions").
	 *
	 * @param string $kind
	 * @return array{w:float,h:float}|null
	 */
	public static function default_dimensions_for( string $kind ) {
		$item = self::item_get( $kind );
		if ( is_array( $item ) ) {
			$attr = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
			$w    = isset( $attr['default_w_in'] ) ? (float) $attr['default_w_in'] : 0.0;
			$h    = isset( $attr['default_h_in'] ) ? (float) $attr['default_h_in'] : 0.0;
			if ( $w > 0 && $h > 0 ) {
				return array( 'w' => $w, 'h' => $h );
			}
		}
		/**
		 * Filter default dimensions for a sizeless kind. NEUTRAL default: none.
		 *
		 * @param array|null $dims { w, h } or null.
		 * @param string     $kind
		 */
		$dims = apply_filters( 'zprep_default_dimensions', null, $kind );
		return ( is_array( $dims ) && ! empty( $dims['w'] ) && ! empty( $dims['h'] ) )
			? array( 'w' => (float) $dims['w'], 'h' => (float) $dims['h'] )
			: null;
	}

	/**
	 * Is a piece something the shop CUTS from roll stock, or a pre-made deliverable that
	 * ships with the order but is installed as-is? The answer comes from the Item Engine
	 * (`attributes.is_fabricated`), never a hardcoded list of piece names.
	 *
	 * NEUTRAL geometry fallback (empty catalog): a round piece is treated as a pre-made
	 * deliverable (it isn't nested from a rectangular strip); everything else is cuttable
	 * once it has real dimensions.
	 *
	 * @param string $kind
	 * @param string $shape 'rectangle' | 'circle' | 'half_circle'
	 * @return bool True = cut from roll stock; false = pre-made deliverable (off cut sheets).
	 */
	public static function is_cuttable_piece( string $kind, string $shape ): bool {
		$item = self::item_get( $kind );
		if ( is_array( $item ) ) {
			$attr = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
			if ( array_key_exists( 'is_fabricated', $attr ) ) {
				return (bool) $attr['is_fabricated'];
			}
		}
		// Neutral fallback: round pieces are pre-made deliverables; the rest are cut.
		$cuttable = ( 'circle' !== $shape );
		return (bool) apply_filters( 'zprep_is_cuttable_piece', $cuttable, $kind, $shape );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Dispositions — NOTHING is silent. Every drop/skip/promote/allow-all
	 * routes here so the future Core Flow service can consume the ledger.
	 * ────────────────────────────────────────────────────────────────── */

	public static function disposition( string $code, array $context = array() ): void {
		$context = array_merge( array( 'code' => $code, 'app' => 'prep' ), $context );
		do_action( 'zdz_flow_disposition', $code, $context, get_current_user_id() );
	}
}
