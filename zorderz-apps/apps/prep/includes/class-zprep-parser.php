<?php
/**
 * Zorderz Prep — measurement parser.
 *
 * Turns a free-form CRM measurement note into structured cut records. The AI call goes
 * through the shared ZDZ_Core_Poe client, and the system prompt is ASSEMBLED AT RUNTIME
 * from the Item Engine vocabulary (the tenant's own piece "kinds"/aliases, scoped to the
 * configured queue subtype), the configured roll colours, and neutral measurement
 * conventions. No product taxonomy is hardcoded; an EMPTY catalog degrades to "emit a
 * short descriptive token" and the engine humanises it.
 *
 * The output-framing sentinel is a neutral constant (replaces the legacy YABADABA token).
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Parser {

	/** Neutral output sentinel (was the YABADABA marker). One constant, referenced twice. */
	const SENTINEL = 'ZDZ-BEGIN-JSON';

	/** @var object|null Shared AI client (ZDZ_Core_Poe) or null when unavailable. */
	private $ai;

	public function __construct() {
		$this->ai = ZPREP_Settings::ai();
	}

	public function is_ready(): bool {
		return null !== $this->ai;
	}

	/**
	 * Parse a raw measurement block into structured records.
	 *
	 * @param string $raw_note The (already assembled) parser input.
	 * @return array|null Parsed structure or null on failure.
	 */
	public function parse_measurements( string $raw_note ): ?array {
		if ( ! $this->is_ready() ) {
			return null;
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $this->system_prompt() ),
			array(
				'role'    => 'user',
				'content' => 'Parse this CRM note into the JSON schema. Strip all pricing, use inches for dimensions, and start your reply with ' . self::SENTINEL . " then a newline then the JSON.\n\n---\n\n" . $raw_note,
			),
		);

		$text = $this->ai->query( $messages, 0.2, array( 'output_effort' => 'max', 'thinking_budget' => 8192 ) );
		if ( ! is_string( $text ) || '' === $text || strpos( $text, 'Error:' ) === 0 ) {
			return null;
		}

		// Strip everything up to and including the sentinel, if present.
		$pos = stripos( $text, self::SENTINEL );
		if ( false !== $pos ) {
			$text = substr( $text, $pos + strlen( self::SENTINEL ) );
		}

		$json = ( method_exists( $this->ai, 'parse_llm_json' ) ) ? $this->ai->parse_llm_json( $text ) : $this->extract_json( $text );
		if ( ! is_array( $json ) ) {
			return null;
		}

		if ( isset( $json['measurements'] ) && is_array( $json['measurements'] ) ) {
			foreach ( $json['measurements'] as &$m ) {
				// Hard-strip any pricing that leaked into source_line.
				if ( isset( $m['source_line'] ) ) {
					$m['source_line'] = preg_replace( '/[@=]\s*\$[0-9]+(?:[.,][0-9]+)?/', '', (string) $m['source_line'] );
					$m['source_line'] = trim( preg_replace( '/\s{2,}/', ' ', (string) $m['source_line'] ) );
				}
				// Accept the legacy field name on input, then normalise to `kind`.
				if ( ! isset( $m['kind'] ) && isset( $m['vent_type'] ) ) {
					$m['kind'] = $m['vent_type'];
				}
				$m['shape'] = $this->validate_shape( $m['shape'] ?? 'rectangle' );
				$m['kind']  = $this->resolve_kind( $m );
				unset( $m['vent_type'] );
			}
			unset( $m );
		}

		return $json;
	}

	/**
	 * Bind classification to the Item Engine. When the catalog matches the line to a
	 * cuttable item, use that item id as the kind (so downstream labels/defaults come from
	 * the catalog). Otherwise keep the model's own token (neutral fallback). Never invents
	 * a taxonomy.
	 */
	private function resolve_kind( array $m ): string {
		$token = trim( (string) ( $m['kind'] ?? '' ) );
		$text  = trim( ( (string) ( $m['source_line'] ?? '' ) ) . ' ' . ( (string) ( $m['notes'] ?? '' ) ) . ' ' . $token );

		$opts = array();
		$sub  = ZPREP_Settings::queue_subtype();
		if ( '' !== $sub ) {
			$opts['subtype'] = $sub;
		}
		$item = ZPREP_Settings::item_match( $text, $opts );
		if ( is_array( $item ) && ! empty( $item['id'] ) ) {
			return (string) $item['id'];
		}
		return '' !== $token ? sanitize_key( str_replace( ' ', '_', $token ) ) : 'custom';
	}

	private function validate_shape( $shape ): string {
		$shape = strtolower( trim( (string) $shape ) );
		return in_array( $shape, array( 'rectangle', 'circle', 'half_circle' ), true ) ? $shape : 'rectangle';
	}

	/**
	 * The runtime-assembled system prompt. No company/person/product name is typed in;
	 * the piece vocabulary is generated from the Item Engine, colours from the roll config.
	 */
	public function system_prompt(): string {
		$business = ZPREP_Settings::letterhead();
		$colors   = ZPREP_Settings::roll_colors();
		$color_a  = $colors[0] ?? 'black';
		$color_b  = $colors[1] ?? ( $color_a === 'black' ? 'white' : 'black' );
		$color_list = implode( '" | "', array_map( 'strval', $colors ) );

		$vocab_block = $this->kind_vocabulary_block();

		$s  = "You extract fabrication measurements from a CRM measurement note for {$business}.\n\n";
		$s .= 'Output strict JSON only. No prose. Start your reply with the exact token ' . self::SENTINEL . ", then a newline, then the JSON.\n\n";
		$s .= "The note is usually a structured estimate block with detailed line items in the form:\n";
		$s .= "  N. <Piece type name> — <free-form description, may mention side 1/2/3/4> [<W>\" x <H>\"] × <qty>\n\n";
		$s .= "ADJUSTMENT NOTES (read every note; the field notes are the source of truth):\n";
		$s .= "The input may contain a BASE MEASUREMENTS block and separate ADJUSTMENT NOTES. Start from the base line items, then APPLY each adjustment to produce the FINAL measurements:\n";
		$s .= "  • \"one more <type>\" / \"add 1 <type>\"        → INCREASE that item's qty.\n";
		$s .= "  • \"N total <type>\", \"make it N\"              → SET that item's qty to N.\n";
		$s .= "  • \"drop the <type>\", \"remove the <type>\"      → REMOVE / zero that item.\n";
		$s .= "  • \"make these {$color_b}\"                       → change that item's color.\n";
		$s .= "  • a brand-new item described only in a note      → ADD it as its own entry.\n";
		$s .= "Record what you changed in that entry's \"notes\" field. Never silently ignore an adjustment that changes a quantity, color, or item.\n\n";

		$s .= "OUTPUT JSON schema:\n";
		$s .= "{\n";
		$s .= "  \"customer\": { \"name\": \"\", \"address\": \"\", \"phone\": \"\", \"email\": \"\", \"estimate_id\": \"\", \"salesperson\": \"\", \"location_city\": \"\" },\n";
		$s .= "  \"measurements\": [\n";
		$s .= "    {\n";
		$s .= "      \"kind\": \"<piece kind — see PIECE KINDS below>\",\n";
		$s .= "      \"qty\": 0,\n";
		$s .= "      \"width_in\": 0.0,\n";
		$s .= "      \"height_in\": 0.0,\n";
		$s .= "      \"shape\": \"rectangle\" | \"circle\" | \"half_circle\",\n";
		$s .= "      \"color\": \"{$color_list}\",\n";
		$s .= "      \"side\": \"1\" | \"2\" | \"3\" | \"4\" | \"\",\n";
		$s .= "      \"source_line\": \"verbatim snippet with all pricing stripped\",\n";
		$s .= "      \"confidence\": 0,\n";
		$s .= "      \"customer_install\": false,\n";
		$s .= "      \"notes\": \"\"\n";
		$s .= "    }\n";
		$s .= "  ],\n";
		$s .= "  \"unparsed_lines\": [\"any line that looked like a measurement but could not be confidently parsed\"]\n";
		$s .= "}\n\n";

		$s .= "CRITICAL PRICING RULE:\n";
		$s .= "Do NOT include any price, dollar amount, unit cost, or subtotal anywhere in your output. When copying source_line, strip every \"@ \$NN.NN\" and \"= \$NN.NN\" fragment. Pricing is unreliable and must never surface.\n\n";

		$s .= "PIECE KINDS:\n";
		$s .= "*** Read the note text; do not guess from dimensions. *** The piece type name is written in the line, before the em-dash (—). Extract it literally.\n";
		$s .= $vocab_block . "\n";
		$s .= "If a line names no recognised type, emit a short lowercase descriptive token for \"kind\" (e.g. the type word the note uses). Do NOT invent a size.\n\n";

		$s .= "PRESERVING FIELD NAMES:\n";
		$s .= "The field notes often use on-site nicknames for a piece. Preserve those verbatim in the \"notes\" field. \"kind\" is the standard category; \"notes\" keeps the human-readable field name.\n\n";

		$s .= "SHAPE:\n";
		$s .= "Use \"half_circle\" ONLY when the line explicitly says the piece is a half-round / half-dome / cut-in-half piece. Use \"circle\" for a round piece that is installed as-is (a pre-made round unit). Everything else is \"rectangle\".\n\n";

		$s .= "SIDE DETECTION (a neutral site-orientation convention):\n";
		$s .= "Side 1 is the front-entrance side; sides are numbered 1→2→3→4 going counter-clockwise. Extract the numeric side when clearly indicated; leave \"\" if ambiguous.\n\n";

		$s .= "COLOR RULES — read every line; do not default too eagerly:\n";
		$s .= "The color is usually on the same line, but a section heading's color applies to the lines beneath it unless a line overrides it. Recognise the configured colors: " . implode( ', ', array_map( 'strval', $colors ) ) . ". Only when NO color cue appears on the line AND none is inherited → default to \"{$color_a}\". A job may mix colors; emit each entry with its own color.\n\n";

		$s .= "CUSTOMER-INSTALL DETECTION:\n";
		$s .= "Phrases like \"for customer to install\", \"cut for customer\", \"customer's own install\" → customer_install: true.\n\n";

		$s .= "CONFIDENCE: 90+ type+dims+color all clear; 70-89 one ambiguous field; 50-69 inferred type or dims; <50 do not emit, put the original line in unparsed_lines.\n\n";
		$s .= "NEVER FABRICATE DIMENSIONS. If a piece gives no size, emit width_in=null and height_in=null (the engine supplies a standard cut size where one is configured). Emit each side as a SEPARATE entry when the same piece appears on different sides.\n";

		return $s;
	}

	/**
	 * Build the PIECE KINDS vocabulary block from the Item Engine, scoped to the queue
	 * subtype. Empty catalog => a neutral instruction (no hardcoded list).
	 */
	private function kind_vocabulary_block(): string {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) && ! has_filter( 'zdz_item_aliases' ) ) {
			return "The catalog is not configured, so classify by the type word in the line and emit it as a short lowercase token (e.g. \"type_a\", \"type_b\").";
		}

		$subtype = ZPREP_Settings::queue_subtype();
		$items   = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			$filter = array( 'countable' => true );
			if ( '' !== $subtype ) {
				$filter['subtype'] = $subtype;
			}
			$items = ZDZ_Item_Engine::all( $filter );
		}

		if ( empty( $items ) ) {
			return "The catalog defines no pieces for this queue yet, so classify by the type word in the line and emit it as a short lowercase token.";
		}

		$lines = array();
		foreach ( $items as $id => $item ) {
			$aliases = array();
			foreach ( (array) ( $item['aliases'] ?? array() ) as $a ) {
				$aliases[] = is_array( $a ) ? (string) ( $a['value'] ?? '' ) : (string) $a;
			}
			$aliases = array_values( array_filter( array_slice( $aliases, 0, 8 ) ) );
			$name    = (string) ( $item['display_name'] ?? $id );
			$lines[] = '- "' . $id . '" (' . $name . ')' . ( $aliases ? ' — matches: ' . implode( ', ', $aliases ) : '' );
		}
		return "Use one of these \"kind\" ids when the line names it (match by the words after \"matches\"):\n" . implode( "\n", $lines );
	}

	/** Fallback JSON extractor if the shared client lacks parse_llm_json(). */
	private function extract_json( string $text ): ?array {
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $m ) ) {
			$d = json_decode( trim( $m[1] ), true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		$first = strpos( $text, '{' );
		$last  = strrpos( $text, '}' );
		if ( false !== $first && false !== $last && $last > $first ) {
			$d = json_decode( substr( $text, $first, $last - $first + 1 ), true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return null;
	}
}
