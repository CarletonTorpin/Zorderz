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
	 * WIDTHS/COLOURS are the fabrication mechanism (the business edits them), and
	 * no supplier price ships in code.
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
	 * The QUEUE TAG + SUBTYPE: which work is prep work is the business's own
	 * setting. A queue tag is a reference token that marks a job as prep work; a
	 * queue subtype binds the queue to an Item Engine subtype, so the product-line
	 * gate reads the item's own subtype. BOTH ship EMPTY.
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
	 * ROLL / MATERIAL model. Widths + colours are the fabrication mechanism (the
	 * business edits them); COSTS SHIP EMPTY. A width/colour that does not exist is
	 * the ABSENCE of that entry in the availability set.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * The roll/material set. Each entry: { width_in:int, color:string, cost_per_ft:float,
	 * available:bool }. Ships a default set (widths + generic colours) with ZERO costs; a
	 * business overrides it via Settings or the `zprep_rolls` filter.
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
		 * Filter the roll/material set. Costs are empty by default.
		 *
		 * @param array $out Roll entries.
		 */
		return (array) apply_filters( 'zprep_rolls', $out );
	}

	/**
	 * The default roll set: two widths in a dark colour + one in a light colour, so the
	 * tool works for a demo; NO brand names and NO costs. Availability is data: a
	 * width/colour that does not exist is simply absent.
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
	 * FABRICATION WORKSPACE profile. An operations concept — table
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
	 * INTERNAL ORDERS — what the shop cuts for itself. What an order may be
	 * for (and the short code each purpose puts in a reference code), the
	 * stock sheet the form starts from, the kinds of piece it offers and what
	 * the material costs are the business's: an administrator sets them on
	 * Prep's settings page, or an Identity Pack does (rules.yml
	 * prep.internal_orders, costs.yml prep.material_cost_per_sq_in). Core
	 * ships one general purpose, no sheet size and no cost.
	 * ────────────────────────────────────────────────────────────────── */

	const INTERNAL_OPT   = 'zprep_internal_orders';
	const COST_RATES_OPT = 'zprep_material_cost_rates';
	/** How the business numbers a building's sides, as one line the parser is told (Identity Pack rules.yml prep.side_convention). */
	const SIDES_OPT      = 'zprep_side_convention';
	const DEFAULT_SIDES  = 'Side 1 is the front-entrance side; sides are numbered 1→2→3→4 going counter-clockwise.';

	/** A side convention as stored: one line of at most 300 characters, or '' (Core's default applies). */
	public static function clean_side_convention( $raw ): string {
		$v = is_scalar( $raw ) ? trim( preg_replace( '/\s+/u', ' ', (string) $raw ) ) : '';
		$v = preg_replace( '/[\x00-\x1F\x7F]/u', '', $v );
		return mb_substr( (string) $v, 0, 300 );
	}

	/** The side convention the parser is told: the business's own, else Core's; code may change it (zprep_side_convention filter). */
	public static function side_convention(): string {
		$own   = function_exists( 'get_option' ) ? self::clean_side_convention( get_option( self::SIDES_OPT, '' ) ) : '';
		$sides = '' !== $own ? $own : self::DEFAULT_SIDES;
		$f     = function_exists( 'apply_filters' ) ? apply_filters( 'zprep_side_convention', $sides ) : $sides;
		$f     = self::clean_side_convention( $f );
		return '' !== $f ? $f : self::DEFAULT_SIDES;
	}
	const MAX_PURPOSES   = 20;

	/** The one purpose Core ships: key => { label, code }. */
	public static function default_internal_purposes(): array {
		return array(
			'general' => array( 'label' => __( 'Internal use', 'zorderz' ), 'code' => 'GEN' ),
		);
	}

	/**
	 * Purposes as data: key => { label, code }. A key is a slug; a code is 2 to 6 capital letters or digits (it is the
	 * middle of a reference code); a purpose missing either, or its label, is left out, and a code is used once.
	 * Accepts a list of { key, label, code } or a map key => { label, code }. PURE apart from sanitize_key.
	 */
	public static function clean_internal_purposes( $raw ): array {
		$out  = array();
		$used = array();
		foreach ( is_array( $raw ) ? $raw : array() as $k => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$key   = substr( sanitize_key( (string) ( $p['key'] ?? ( is_string( $k ) ? $k : '' ) ) ), 0, 40 );
			$label = trim( strip_tags( (string) ( $p['label'] ?? '' ) ) );
			$code  = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $p['code'] ?? '' ) ) );
			if ( '' === $key || '' === $label || strlen( $code ) < 2 || strlen( $code ) > 6 || isset( $out[ $key ] ) || isset( $used[ $code ] ) ) {
				continue;
			}
			$used[ $code ] = true;
			$out[ $key ]   = array( 'label' => substr( $label, 0, 60 ), 'code' => $code );
			if ( count( $out ) >= self::MAX_PURPOSES ) {
				break;
			}
		}
		return $out;
	}

	/** The stored internal-orders settings, cleaned: { purposes, default_sheet }. */
	public static function internal_orders(): array {
		$raw = get_option( self::INTERNAL_OPT, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'purposes'      => self::clean_internal_purposes( $raw['purposes'] ?? array() ),
			'default_sheet' => self::clean_sheet( $raw['default_sheet'] ?? null ),
		);
	}

	/** A stock sheet { w, l } by the engine's one rule, or null. */
	public static function clean_sheet( $sheet ): ?array {
		if ( ! class_exists( 'ZPREP_Engine' ) ) {
			require_once __DIR__ . '/class-zprep-engine.php';
		}
		return ZPREP_Engine::clean_stock_sheet( $sheet );
	}

	/** What an internal order may be for: the business's purposes, else the one Core ships. key => { label, code }. */
	public static function internal_purposes(): array {
		$mine = self::internal_orders()['purposes'];
		$mine = self::clean_internal_purposes( apply_filters( 'zprep_internal_purposes', $mine ) );
		return $mine ? $mine : self::default_internal_purposes();
	}

	/** The stock sheet the internal-order form starts from, or null (the boxes start empty). */
	public static function internal_default_sheet(): ?array {
		return self::clean_sheet( apply_filters( 'zprep_internal_default_sheet', self::internal_orders()['default_sheet'] ) );
	}

	/**
	 * The kinds of piece an internal order may hold: the catalog's kinds for this queue that are cut from stock exactly
	 * as typed, and Custom. slug => label. With an empty catalog: Custom alone.
	 */
	public static function internal_kinds(): array {
		$out = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			$filter  = array();
			$subtype = self::queue_subtype();
			if ( '' !== $subtype ) {
				$filter['subtype'] = $subtype;
			}
			foreach ( ZDZ_Item_Engine::all( $filter ) as $id => $item ) {
				$id = (string) $id;
				if ( 'custom' !== $id && preg_match( '/^[a-z0-9_\-]{1,80}$/', $id ) && self::is_cuttable_piece( $id, 'rectangle' ) ) {
					$out[ $id ] = (string) ( $item['display_name'] ?? self::kind_label( $id ) );
				}
			}
		}
		$out   = (array) apply_filters( 'zprep_internal_kinds', $out );
		$clean = array();
		foreach ( $out as $id => $label ) {
			$id = (string) $id;
			if ( 'custom' !== $id && preg_match( '/^[a-z0-9_\-]{1,80}$/', $id ) && '' !== trim( (string) $label ) ) {
				$clean[ $id ] = (string) $label;
			}
		}
		$clean['custom'] = __( 'Custom', 'zorderz' );
		return $clean;
	}

	/**
	 * Material cost rates as data: colour (or '*' for every colour) => cost per square inch, each over 0 and under
	 * 100. Accepts one number (every colour) or a map colour => number. PURE.
	 */
	public static function clean_cost_rates( $raw ): array {
		if ( is_numeric( $raw ) ) {
			$raw = array( '*' => $raw );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $color => $rate ) {
			$color = strtolower( trim( (string) $color ) );
			if ( '' === $color || ! preg_match( '/^(\*|[a-z0-9 _\-]{1,32})$/', $color ) || ! is_numeric( $rate ) ) {
				continue;
			}
			$rate = (float) $rate;
			if ( $rate > 0 && $rate < 100 ) {
				$out[ $color ] = round( $rate, 6 );
			}
		}
		return $out;
	}

	/**
	 * What the cut material costs per square inch in one colour, or 0.0 when the business has not said. Never a
	 * figure worked out here: an order made with no rate on file says so on the year-end page.
	 */
	public static function material_cost_rate( string $color ): float {
		$color = strtolower( trim( $color ) );
		$rates = self::clean_cost_rates( get_option( self::COST_RATES_OPT, array() ) );
		$rate  = $rates[ $color ] ?? ( $rates['*'] ?? 0.0 );
		$rate  = apply_filters( 'zprep_material_cost_rate', $rate, $color );
		$rate  = is_numeric( $rate ) ? (float) $rate : 0.0;
		return ( $rate > 0 && $rate < 100 ) ? $rate : 0.0;
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
