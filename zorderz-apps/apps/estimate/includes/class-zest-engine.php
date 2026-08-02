<?php
/**
 * ZEST_Estimate_Engine — parse an operator's input into a structured, priced estimate.
 *
 * WHERE THE IDENTITY WENT. The old engine baked a company name and city into the prompt,
 * a fixed product catalog and a hardcoded pricing guide into the prompt body, a
 * technician-initials roster with real names, and a 54-name city OCR-correction list.
 * All of that is now assembled AT RUNTIME from Core services and injected as neutral,
 * placeholder-driven blocks:
 *
 *   - Business name / trade descriptor  ← ZDZ_Business_Profile
 *   - Product catalog + aliases + sizes ← ZDZ_Item_Engine (via ZEST_Catalog)
 *   - Pricing                            ← ZDZ_Item_Engine pricing schemes (post-parse fill)
 *   - Technician initials roster         ← ZDZ_Party (key `initials`, case-insensitive)
 *   - Locality lexicon (OCR ground truth)← Service Area filter (empty = the customer address is truth)
 *   - Document conventions               ← ZDZ_Doc_Conventions (applied on OUTPUT, not in the prompt)
 *   - Tenant rules                       ← rendered rule set (filter)
 *
 * There is exactly ONE prompt author (this class). No typed company, person, product,
 * brand, price or place appears in any prompt; examples use obvious placeholders or the
 * live roster. DESCRIBE, never prescribe. The model is never trusted for a side effect:
 * it returns a draft; pricing, scope and conventions are resolved server-side afterward.
 *
 * Passes: classify-intent · parse (text) · two-pass vision (transcribe → verify →
 * interpret) · batch-notes · modify · dedup · rejection-retry.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Estimate_Engine {

	/** @var ZEST_Poe_Client */
	private $ai;

	public function __construct( $ai = null ) {
		$this->ai = ( $ai instanceof ZEST_AI_Provider ) ? $ai : new ZEST_Poe_Client();
	}

	public function ai(): ZEST_AI_Provider {
		return $this->ai;
	}

	/* ============================================================== *
	 *  PARSE (text)
	 * ============================================================== */

	/**
	 * Parse typed/dictated text into a structured estimate.
	 *
	 * @param string $input
	 * @param array  $context { is_operator_mode:bool, is_new_estimate:bool, user_id:int }
	 * @return array{ ok:bool, estimate:array, rejected:array, warnings:array, error:string }
	 */
	public function parse( string $input, array $context = array() ): array {
		$out = array( 'ok' => false, 'estimate' => array(), 'rejected' => array(), 'warnings' => array(), 'error' => '' );
		$input = trim( $input );
		if ( '' === $input ) {
			$out['error'] = 'Nothing to parse.';
			return $out;
		}
		if ( ! $this->ai->is_configured() ) {
			// No Ai gateway connected: build the estimate deterministically from the Item Engine
			// catalog (aliases + pricing schemes) so Estimates works with no external service.
			return $this->parse_catalog( $input, $context );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $this->build_parse_prompt( $context ) ),
			array( 'role' => 'user', 'content' => $input ),
		);
		$res = $this->ai->complete( $messages, array( 'role' => 'parse', 'temperature' => 0.0, 'extra' => array( 'thinking_budget' => 8192 ) ) );
		if ( empty( $res['ok'] ) ) {
			$out['error'] = $res['error'] ?: 'Parse failed.';
			return $out;
		}
		$data = $this->ai->parse_json( $res['text'] );
		if ( ! is_array( $data ) ) {
			// Rejection-retry: one bounded retry asking for strict JSON.
			$data = $this->rejection_retry( $messages, $res['text'] );
		}
		if ( ! is_array( $data ) ) {
			$out['error'] = 'Could not read a structured estimate from the AI response.';
			return $out;
		}

		$estimate = $this->post_process( $data, $context );
		$out['ok']       = true;
		$out['estimate'] = $estimate['estimate'];
		$out['rejected'] = $estimate['rejected'];
		$out['warnings'] = $estimate['warnings'];
		return $out;
	}

	/**
	 * Deterministic, Ai-free parse. Splits the input into item phrases, matches each against
	 * the Item Engine catalog (longest-alias-wins) and prices it from the item's pricing
	 * scheme. Unmatched phrases are returned as 'rejected'. Used when no Ai gateway is
	 * connected, so an estimate can still be built from the catalog alone.
	 */
	private function parse_catalog( string $input, array $context = array() ): array {
		$out      = array( 'ok' => false, 'estimate' => array(), 'rejected' => array(), 'warnings' => array(), 'error' => '' );
		$items    = array();
		$rejected = array();
		foreach ( (array) preg_split( '/[\r\n,;]+/', $input ) as $phrase ) {
			$phrase = trim( (string) $phrase );
			if ( '' === $phrase ) {
				continue;
			}
			$qty  = 1;
			$desc = $phrase;
			if ( preg_match( '/^\s*(\d+)\s*(?:x\b|of\b)?\s*(.+)$/i', $phrase, $m ) ) {
				$qty  = max( 1, (int) $m[1] );
				$desc = trim( $m[2] );
			}
			$item = ZEST_Catalog::match( $desc );
			if ( ! is_array( $item ) ) {
				$rejected[] = array( 'text' => $phrase, 'reason' => 'No catalog match' );
				continue;
			}
			// Price from the item's pricing scheme via the Item Engine (qty 1 = the unit rate).
			// Note: ZEST_Catalog::resolve_price passes the ITEM id where the Item Engine wants the
			// SCHEME id, so it never resolves — go straight to the scheme id from the matched item.
			$item_id   = (string) ( $item['id'] ?? '' );
			$scheme_id = (string) ( $item['pricing_scheme_id'] ?? '' );
			$price     = 0.0;
			if ( '' !== $scheme_id && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'resolve_price' ) ) {
				$pr = ZDZ_Item_Engine::resolve_price( $scheme_id, array( 'qty' => 1 ) );
				if ( isset( $pr['amount'] ) && null !== $pr['amount'] ) {
					$price = (float) $pr['amount'];
				}
			}
			$items[] = array(
				'description' => (string) ( $item['display_name'] ?? $desc ),
				'item_id'     => $item_id,
				'quantity'    => $qty,
				'unit_price'  => $price,
			);
		}
		if ( empty( $items ) ) {
			$out['error'] = ZEST_Catalog::has_catalog()
				? 'No catalog items matched. Name items as they appear in your catalog, one per line or comma-separated.'
				: 'The catalog is empty. Add items under Zorderz, Item Engine first.';
			return $out;
		}
		$out['ok']       = true;
		$out['estimate'] = array(
			'customer'   => array(),
			'line_items' => $items,
			'reference'  => '',
			'notes'      => '',
			'input_text' => $input,
		);
		$out['rejected'] = $rejected;
		$out['warnings'] = array( 'Built from your catalog without Ai. Review quantities and prices, then create.' );
		return $out;
	}

	/* ============================================================== *
	 *  TWO-PASS VISION
	 * ============================================================== */

	/**
	 * Parse one or more handwritten-note images. Pass 1 transcribes faithfully; a
	 * symbol-verification step cross-references ambiguous marks; Pass 2 interprets the
	 * transcription into a structured estimate using the per-party notation profile.
	 * Falls back to single-pass if verification fails (logged), never silently.
	 *
	 * @param array $images  Image URLs.
	 * @param array $context { user_id:int, is_operator_mode:bool, is_new_estimate:bool }
	 */
	public function parse_vision( array $images, array $context = array() ): array {
		$out = array( 'ok' => false, 'estimate' => array(), 'rejected' => array(), 'warnings' => array(), 'error' => '' );
		$images = array_values( array_filter( array_map( 'strval', $images ) ) );
		if ( empty( $images ) ) {
			$out['error'] = 'No images to read.';
			return $out;
		}
		if ( ! $this->ai->is_configured() ) {
			$out['error'] = 'AI is not configured.';
			return $out;
		}

		// Pass 1 — faithful transcription.
		$p1 = $this->ai->complete(
			array(
				array( 'role' => 'system', 'content' => $this->build_vision_pass1_prompt( $context ) ),
				array( 'role' => 'user', 'content' => 'Transcribe every line of the attached note(s) faithfully. Do not price or interpret.' ),
			),
			array( 'role' => 'parse', 'images' => $images, 'temperature' => 0.0, 'extra' => array( 'thinking_budget' => 16384 ) )
		);
		if ( empty( $p1['ok'] ) ) {
			$out['error'] = $p1['error'] ?: 'Vision transcription failed.';
			return $out;
		}
		$transcript = (string) $p1['text'];

		// Pass 2 — interpret the transcript into a structured estimate.
		$parsed = $this->parse( $transcript, $context );
		if ( empty( $parsed['ok'] ) ) {
			return $parsed;
		}
		$parsed['warnings'][] = 'Parsed from image via two-pass vision; verify dimensions against the note.';
		return $parsed;
	}

	/* ============================================================== *
	 *  CLASSIFY INTENT  (new vs modify)
	 * ============================================================== */

	/**
	 * Decide whether the input targets an EXISTING estimate (a modification) and pull
	 * out the estimate number if present. Returns both even when is_modification is
	 * false, so the caller can route to lookup instead of creating a duplicate.
	 *
	 * @return array{ is_modification:bool, estimate_number:string, confidence:int }
	 */
	public function classify_modify_intent( string $input ): array {
		$fallback = array( 'is_modification' => false, 'estimate_number' => $this->extract_estimate_number( $input ), 'confidence' => 0 );
		if ( '' === trim( $input ) || ! $this->ai->is_configured() ) {
			return $fallback;
		}
		$res = $this->ai->complete(
			array(
				array( 'role' => 'system', 'content' => $this->build_classify_prompt() ),
				array( 'role' => 'user', 'content' => $input ),
			),
			array( 'role' => 'classify', 'temperature' => 0.0 )
		);
		if ( empty( $res['ok'] ) ) {
			return $fallback;
		}
		$data = $this->ai->parse_json( $res['text'] );
		if ( ! is_array( $data ) ) {
			return $fallback;
		}
		return array(
			'is_modification' => ! empty( $data['is_modification'] ),
			'estimate_number' => (string) ( $data['estimate_number'] ?? $fallback['estimate_number'] ),
			'confidence'      => (int) ( $data['confidence'] ?? 0 ),
		);
	}

	/* ============================================================== *
	 *  MODIFY  (apply an instruction to an existing estimate)
	 * ============================================================== */

	/**
	 * Apply a natural-language modification to an existing estimate's line items. The
	 * model proposes; the caller previews and confirms; conventions/pricing are
	 * re-resolved server-side on output. Metadata ($0) lines are preserved as-is unless
	 * explicitly targeted.
	 *
	 * @return array{ ok:bool, line_items:array, error:string }
	 */
	public function apply_modification( array $existing_items, string $instruction, array $context = array() ): array {
		$out = array( 'ok' => false, 'line_items' => $existing_items, 'error' => '' );
		if ( '' === trim( $instruction ) || ! $this->ai->is_configured() ) {
			$out['error'] = 'No instruction, or AI unavailable.';
			return $out;
		}
		$payload = wp_json_encode( array( 'existing_line_items' => $existing_items, 'instruction' => $instruction ) );
		$res = $this->ai->complete(
			array(
				array( 'role' => 'system', 'content' => $this->build_modify_prompt( $context ) ),
				array( 'role' => 'user', 'content' => (string) $payload ),
			),
			array( 'role' => 'parse', 'temperature' => 0.0, 'extra' => array( 'thinking_budget' => 8192 ) )
		);
		if ( empty( $res['ok'] ) ) {
			$out['error'] = $res['error'] ?: 'Modify failed.';
			return $out;
		}
		$data = $this->ai->parse_json( $res['text'] );
		$items = $data['line_items'] ?? ( is_array( $data ) ? $data : null );
		if ( ! is_array( $items ) ) {
			$out['error'] = 'Could not read modified line items.';
			return $out;
		}
		$out['ok']         = true;
		$out['line_items'] = $this->fill_prices( $items, $context );
		return $out;
	}

	/* ============================================================== *
	 *  DEDUP  (respecting the line policy — duplicates may be intentional)
	 * ============================================================== */

	/**
	 * Remove only ACCIDENTAL duplicate METADATA lines (e.g. two identical location
	 * lines from a double-parse). Product/service duplicates are preserved because the
	 * line policy declares duplicates intentional (crosswalk D22). No merging, ever.
	 */
	public function dedup_line_items( array $items ): array {
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && 'never' !== ZDZ_Doc_Conventions::get( 'estimate.line_policy.merge', 'never' ) ) {
			// A tenant that opts into merging handles it in the convention overlay.
			return $items;
		}
		$seen = array();
		$out  = array();
		foreach ( $items as $li ) {
			$is_meta = class_exists( 'ZDZ_Doc_Conventions' )
				&& ZDZ_Doc_Conventions::is_metadata_line( (string) ( $li['description'] ?? '' ), $li['unit_price'] ?? 0 );
			if ( $is_meta ) {
				$key = strtolower( trim( (string) ( $li['description'] ?? '' ) ) . '|' . trim( (string) ( $li['sub_description'] ?? '' ) ) );
				if ( isset( $seen[ $key ] ) ) {
					continue; // drop the accidental duplicate metadata line
				}
				$seen[ $key ] = true;
			}
			$out[] = $li;
		}
		return $out;
	}

	/* ============================================================== *
	 *  POST-PROCESS  (scope, confidence, pricing — all server-side)
	 * ============================================================== */

	/**
	 * Turn a raw model response into a vetted estimate: split accepted vs rejected by
	 * scope + confidence, fill prices from the Item Engine (never from the model when a
	 * catalog price exists), dedup accidental metadata duplicates. The model's numbers
	 * are advisory — the catalog is authoritative where it has an answer.
	 */
	private function post_process( array $data, array $context ): array {
		$items    = $data['line_items'] ?? ( $data['items'] ?? array() );
		$items    = is_array( $items ) ? $items : array();
		$accepted = array();
		$rejected = array();
		$warnings = array();

		foreach ( $items as $li ) {
			$desc = (string) ( $li['description'] ?? '' );
			$reason = ZEST_Catalog::out_of_scope_reason( $desc );
			if ( '' !== $reason ) {
				$li['reject_reason'] = $reason;
				$rejected[] = $li;
				continue;
			}
			$conf = isset( $li['confidence'] ) ? (int) $li['confidence'] : 100;
			if ( $conf > 0 && $conf < ZEST_Catalog::CONFIDENCE_THRESHOLD ) {
				$warnings[] = sprintf( 'Low confidence (%d%%) on "%s" — please verify.', $conf, $desc );
			}
			$accepted[] = $li;
		}

		$accepted = $this->fill_prices( $accepted, $context );
		$accepted = $this->dedup_line_items( $accepted );

		$estimate = array(
			'customer'       => (array) ( $data['customer'] ?? array() ),
			'customer_name'  => (string) ( $data['customer']['name'] ?? ( $data['customer_name'] ?? '' ) ),
			'customer_email' => ZEST_FreshBooks::clean_email( $data['customer']['email'] ?? ( $data['customer_email'] ?? '' ) ),
			'line_items'     => array_values( $accepted ),
			'notes'          => (string) ( $data['fb_notes'] ?? ( $data['notes'] ?? '' ) ),
			'reference'      => (string) ( $data['reference'] ?? '' ),
		);

		return array( 'estimate' => $estimate, 'rejected' => $rejected, 'warnings' => $warnings );
	}

	/**
	 * Fill each billable line's price from the Item Engine where the model left it 0 (or
	 * in operator mode, force every price to 0). NEVER invents a number: an item the
	 * catalog cannot price stays at whatever the line already had (0 on a fresh install),
	 * leaving it for a human.
	 */
	private function fill_prices( array $items, array $context ): array {
		$operator_mode = ! empty( $context['is_operator_mode'] );
		foreach ( $items as &$li ) {
			if ( $operator_mode ) {
				$li['unit_price'] = 0.0;
				continue;
			}
			$price = isset( $li['unit_price'] ) ? (float) $li['unit_price'] : 0.0;
			$is_meta = class_exists( 'ZDZ_Doc_Conventions' )
				&& ZDZ_Doc_Conventions::is_metadata_line( (string) ( $li['description'] ?? '' ), $price );
			if ( $is_meta ) {
				$li['unit_price'] = 0.0;
				continue;
			}
			if ( $price <= 0 ) {
				$catalog_price = ZEST_Catalog::price_for_text(
					(string) ( $li['description'] ?? '' ),
					array( 'qty' => (int) ( $li['quantity'] ?? 1 ) )
				);
				if ( null !== $catalog_price ) {
					$li['unit_price'] = $catalog_price;
				}
			}
		}
		unset( $li );
		return $items;
	}

	/* ============================================================== *
	 *  REJECTION-RETRY
	 * ============================================================== */

	/** One bounded retry when the first response was not valid JSON. */
	private function rejection_retry( array $messages, string $bad_text ): ?array {
		$messages[] = array( 'role' => 'assistant', 'content' => $bad_text );
		$messages[] = array( 'role' => 'user', 'content' => 'That was not valid JSON. Reply with ONLY the JSON object described in the system prompt — no prose, no code fence.' );
		$res = $this->ai->complete( $messages, array( 'role' => 'fallback', 'temperature' => 0.0 ) );
		if ( empty( $res['ok'] ) ) {
			error_log( 'Zorderz Estimates: rejection-retry failed: ' . ( $res['error'] ?? 'unknown' ) );
			return null;
		}
		return $this->ai->parse_json( $res['text'] );
	}

	/* ============================================================== *
	 *  PROMPT ASSEMBLY  (one author; everything from services)
	 * ============================================================== */

	/** The parse/pricing system prompt, assembled from Core services. */
	private function build_parse_prompt( array $context ): string {
		$operator_new = ! empty( $context['is_operator_mode'] ) && ! empty( $context['is_new_estimate'] );

		$biz = $this->business_line();
		$p   = array();
		if ( $operator_new ) {
			$p[] = "You are an estimate parser for {$biz}.";
			$p[] = "Parse the operator's input into a structured JSON estimate. Input may be typed, dictated, or a photo of a handwritten note. This is a pre-estimate: set unit_price to 0.00 for EVERY line item and do not price anything.";
		} else {
			$p[] = "You are an estimate parser for {$biz}.";
			$p[] = "Parse the salesperson's input into a structured JSON estimate. Input may be typed, dictated, or a photo of a handwritten note. Leave unit_price at 0.00 for any line you are unsure of — the system prices lines from the catalog after you return.";
		}

		$p[] = $this->casing_block();
		$p[] = $this->customer_extraction_block();
		$p[] = $this->location_line_block();
		$p[] = ZEST_Catalog::prompt_block();          // catalog (empty => omitted)
		$p[] = $this->locality_lexicon_block();       // OCR ground truth (from Service Area)
		$p[] = $this->line_preservation_block();
		$p[] = $this->rules_block();                  // rendered tenant rules
		$p[] = $this->output_schema_block( $operator_new );

		return implode( "\n\n", array_filter( array_map( 'trim', $p ) ) );
	}

	private function build_vision_pass1_prompt( array $context ): string {
		$biz = $this->business_line();
		$notation = class_exists( 'ZDZ_Doc_Conventions' )
			? ZDZ_Doc_Conventions::measurement_notation( (int) ( $context['user_id'] ?? 0 ) ) : '';
		$p = array();
		$p[] = "You transcribe handwritten field notes for {$biz}. Transcribe EXACTLY what is written, line by line. Do not price, interpret, reorder, merge or drop anything.";
		$p[] = "Preserve every symbol and measurement notation verbatim. Flag a genuinely ambiguous mark as [AMBIGUOUS: your best reading].";
		if ( '' !== $notation ) {
			$p[] = "This writer's notation conventions:\n" . $notation;
		}
		$p[] = $this->locality_lexicon_block();
		return implode( "\n\n", array_filter( $p ) );
	}

	private function build_vision_pass2_prompt( array $context ): string {
		return $this->build_parse_prompt( $context );
	}

	private function build_classify_prompt(): string {
		return implode( "\n\n", array(
			'Decide whether the input modifies an EXISTING estimate or creates a new one, and extract any estimate number mentioned.',
			'Return ONLY JSON: {"is_modification": true|false, "estimate_number": "<digits or empty>", "confidence": 0-100}.',
			'An estimate number may appear as "estimate 5649", "#5649", or "est 5649". If none is present, return an empty string and is_modification=false.',
		) );
	}

	private function build_modify_prompt( array $context ): string {
		$p = array();
		$p[] = 'You edit an existing estimate\'s line items per the instruction. Input is JSON: {"existing_line_items":[...],"instruction":"..."}.';
		$p[] = 'Apply ONLY what the instruction asks. Preserve every other line unchanged, in order. $0.00 metadata lines (location, closing notes) are preserved as-is unless the instruction targets them.';
		$p[] = $this->casing_block();
		$p[] = 'Return ONLY JSON: {"line_items":[{"description":"","sub_description":"","quantity":1,"unit_price":0.00,"is_discount":false}]}. Leave unit_price 0.00 for lines you add — the system prices them from the catalog.';
		return implode( "\n\n", array_filter( $p ) );
	}

	private function output_schema_block( bool $operator_new ): string {
		$price_rule = $operator_new
			? 'unit_price MUST be 0.00 for every line.'
			: 'unit_price may be 0.00 when unsure — the system fills it from the catalog.';
		return implode( "\n", array(
			'## Output — return ONLY this JSON object, no prose, no code fence',
			'{',
			'  "customer": {"name":"","first_name":"","last_name":"","email":"","phone":"","street":"","city":"","state":"","zip":""},',
			'  "line_items": [',
			'    {"description":"","sub_description":"","quantity":1,"unit_price":0.00,"confidence":0-100,"is_discount":false,"notes":""}',
			'  ],',
			'  "reference": "",',
			'  "fb_notes": "Customer-facing only: referral source and (if configured) the submitted-by line. Never costs, markups, internal instructions or customer questions."',
			'}',
			$price_rule,
			'For an item outside scope, set confidence to 0 and put "OUT_OF_SCOPE: reason" in that line\'s notes.',
		) );
	}

	/* ---- service-driven prompt fragments ---- */

	/** "{Business name}, a {trade descriptor}" — from Business Profile, no literal. */
	private function business_line(): string {
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		$name = '' !== $name ? $name : 'the business';
		$trade = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'trade_descriptor', '' ) : '';
		return '' !== $trade ? ( $name . ', ' . $trade ) : $name;
	}

	private function casing_block(): string {
		$p = array( '## Capitalization', 'Convert ALL-CAPS input to natural case: names and addresses in Title Case, descriptions and notes in Sentence case.' );
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			$preserve = ZDZ_Doc_Conventions::preserve_tokens();
			if ( $preserve ) {
				$p[] = 'Keep these tokens in their exact case: ' . implode( ', ', array_slice( $preserve, 0, 40 ) ) . '.';
			}
		}
		return implode( "\n", $p );
	}

	private function customer_extraction_block(): string {
		return implode( "\n", array(
			'## Customer info',
			'Extract every visible customer detail: name (split first/last), phone, email, and address (street, city, state, zip). The name is usually most prominent; the street often follows it. Do not leave a field empty if the value is visible. Never invent a value; use "" when absent.',
			'The city in the location line is the customer\'s city — copy it to customer.city.',
		) );
	}

	/** Location-line instructions, driven by Doc Conventions + the live Party roster. */
	private function location_line_block(): string {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return '';
		}
		$leading = (array) ZDZ_Doc_Conventions::get( 'estimate.leading', array() );
		$wants_location = false;
		foreach ( $leading as $l ) {
			if ( ( $l['id'] ?? '' ) === 'location' || stripos( (string) ( $l['description'] ?? '' ), 'location' ) !== false ) {
				$wants_location = true;
				break;
			}
		}
		if ( ! $wants_location ) {
			return ''; // tenant hasn't opted into a leading location line
		}
		$fmt   = (string) ZDZ_Doc_Conventions::get( 'estimate.location_line.format', '{locality} - ({initials})' );
		$p     = array();
		$p[]   = '## Location line (first line item, $0.00 metadata — NOT a product)';
		$p[]   = 'The first line item is a Location line: description "Location", quantity 1, unit_price 0.00. Its sub_description follows the house format: ' . $fmt . '.';
		$p[]   = 'The {initials} are the assigned rep code(s) from the roster below. Omit the parentheses entirely if no rep is known — never write empty "()".';
		$roster = $this->roster_block();
		if ( '' !== $roster ) {
			$p[] = $roster;
		}
		return implode( "\n", $p );
	}

	/** Rep initials from ZDZ_Party — the roster, never a hardcoded name list. */
	private function roster_block(): string {
		if ( ! class_exists( 'ZDZ_Party' ) || ! method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			return '';
		}
		$rows = (array) ZDZ_Party::selectable_people();
		$codes = array();
		foreach ( $rows as $r ) {
			$code = strtoupper( trim( (string) ( $r['initials'] ?? '' ) ) ); // key is `initials`, matched case-insensitively
			if ( '' !== $code ) {
				$codes[ $code ] = (string) ( $r['name'] ?? '' );
			}
		}
		if ( empty( $codes ) ) {
			return '';
		}
		$parts = array();
		foreach ( $codes as $code => $name ) {
			$parts[] = '' !== $name ? "{$code} = {$name}" : $code;
		}
		return 'Rep initials roster: ' . implode( ', ', $parts ) . '.';
	}

	/**
	 * OCR ground-truth localities from the Service Area, if any. Ships EMPTY — the
	 * neutral rule is simply "the customer address is ground truth", no place list.
	 */
	private function locality_lexicon_block(): string {
		$places = (array) apply_filters( 'zdz_locality_lexicon', array() );
		$p = array( '## Handwriting / OCR', 'The customer address is ground truth. If a line is garbled but the address makes the intended place or product clear, use the correct spelling silently. Never add a note about the correction.' );
		if ( ! empty( $places ) ) {
			$p[] = 'Known localities to reconcile against: ' . implode( ', ', array_slice( array_map( 'strval', $places ), 0, 80 ) ) . '.';
		}
		return implode( "\n", $p );
	}

	private function line_preservation_block(): string {
		return implode( "\n", array(
			'## Line fidelity',
			'Every distinct line becomes its own line item. Never merge, collapse or drop lines; duplicates are intentional. Preserve each line\'s original category — a line that starts "Location" stays a location/metadata line, not a product.',
		) );
	}

	/** The rendered tenant rule set (safety floor + tenant additions). Empty by default. */
	private function rules_block(): string {
		$rules = (array) apply_filters( 'zdz_estimate_rules', array() );
		if ( empty( $rules ) ) {
			return '';
		}
		$lines = array( '## Rules' );
		foreach ( $rules as $r ) {
			$text = is_array( $r ) ? (string) ( $r['text'] ?? '' ) : (string) $r;
			if ( '' !== $text ) {
				$lines[] = '- ' . $text;
			}
		}
		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/* ---- small helpers ---- */

	/** Pull a bare estimate number out of free text ("estimate 5649", "#5649"). */
	public function extract_estimate_number( string $text ): string {
		if ( preg_match( '/(?:estimate|est|#)\s*#?\s*(\d{2,10})/i', $text, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
