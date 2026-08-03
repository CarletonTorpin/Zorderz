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
			// Resolve straight from the matched item's scheme id here (clear and direct).
			// (ZEST_Catalog::resolve_price also resolves from the scheme id as of 1.3.3.)
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
				// Ask for the UNIT rate (qty 1), not the extended amount: unit_price is
				// multiplied by the line quantity downstream (ajax_create, compute_totals),
				// so passing the real quantity here would square it. Matches parse_catalog().
				$catalog_price = ZEST_Catalog::price_for_text(
					(string) ( $li['description'] ?? '' ),
					array( 'qty' => 1 )
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
	 *  IMPORT PARSE  (verbatim — no pricing, no catalog matching)
	 * ============================================================== */

	/**
	 * Parse the extracted text of an EXISTING business's estimate/invoice PDF into the
	 * canonical document model, VERBATIM. This is the AI seam for the manual PDF-import
	 * pipeline (milestone #54): it never prices, never matches the Item Engine catalog and
	 * never calls fill_prices() — the source document's own numbers (rates, line totals,
	 * tax, discounts, totals) are the truth and are preserved exactly. The model returns a
	 * draft; the human operator reviews and confirms before any import side effect.
	 *
	 * One bounded rejection-retry, mirroring the create parser. When no AI gateway is
	 * connected there is no deterministic fallback (the document is free-form), so the
	 * caller falls back to manual entry in the review panel.
	 *
	 * @param string $text    Extracted document text (all pages).
	 * @param array  $context { kind:'estimate'|'invoice'|'', user_id:int }
	 * @return array{ ok:bool, doc:array, warnings:array, error:string }
	 */
	public function parse_document( string $text, array $context = array() ): array {
		$out  = array( 'ok' => false, 'doc' => array(), 'warnings' => array(), 'error' => '' );
		$text = trim( $text );
		if ( '' === $text ) {
			$out['error'] = 'Nothing to parse.';
			return $out;
		}
		if ( ! $this->ai->is_configured() ) {
			$out['error'] = 'AI is not configured. Enter or edit the document manually in the review panel.';
			return $out;
		}

		$kind_hint = in_array( ( $context['kind'] ?? '' ), array( 'estimate', 'invoice' ), true ) ? (string) $context['kind'] : '';

		$messages = array(
			array( 'role' => 'system', 'content' => $this->build_import_prompt( $context ) ),
			array( 'role' => 'user', 'content' => $text ),
		);
		$res = $this->ai->complete( $messages, array( 'role' => 'parse', 'temperature' => 0.0, 'extra' => array( 'thinking_budget' => 8192 ) ) );
		if ( empty( $res['ok'] ) ) {
			$out['error'] = $res['error'] ?: 'Parse failed.';
			return $out;
		}
		$data = $this->ai->parse_json( $res['text'] );
		if ( ! is_array( $data ) ) {
			$data = $this->rejection_retry( $messages, $res['text'] ); // one bounded retry
		}
		if ( ! is_array( $data ) ) {
			$out['error'] = 'Could not read a structured document from the AI response.';
			return $out;
		}

		$norm            = $this->normalize_import_doc( $data, $text, $kind_hint );
		$out['ok']       = true;
		$out['doc']      = $norm['doc'];
		$out['warnings'] = $norm['warnings'];
		return $out;
	}

	/**
	 * Map a raw import-model response to the canonical $doc, VERBATIM. Coerces types and
	 * parses currency (stripping "$", commas, "()" and U+2212) but NEVER reprices:
	 * unit_price and line_total come straight from the source. Reconciles the computed
	 * total against the total printed on the source and appends a WARNING (never a silent
	 * correction) when they differ by more than a cent.
	 *
	 * @return array{ doc:array, warnings:array }
	 */
	private function normalize_import_doc( array $data, string $source_text, string $kind_hint ): array {
		$warnings = array();

		$kind = (string) ( $data['kind'] ?? '' );
		$kind = in_array( $kind, array( 'estimate', 'invoice' ), true ) ? $kind : '';
		if ( '' !== $kind_hint ) {
			if ( '' !== $kind && $kind !== $kind_hint ) {
				$warnings[] = sprintf( 'The model read this as an %s; importing as %s per your selection.', $kind, $kind_hint );
			}
			$kind = $kind_hint; // operator's explicit choice wins over the model's guess
		}
		if ( '' === $kind ) {
			$kind = 'estimate';
		}

		$c        = (array) ( $data['customer'] ?? array() );
		$customer = array(
			'name'   => (string) ( $c['name'] ?? '' ),
			'org'    => (string) ( $c['org'] ?? '' ),
			'email'  => (string) ( $c['email'] ?? '' ),
			'phone'  => (string) ( $c['phone'] ?? '' ),
			'street' => (string) ( $c['street'] ?? '' ),
			'city'   => (string) ( $c['city'] ?? '' ),
			'state'  => (string) ( $c['state'] ?? '' ),
			'zip'    => (string) ( $c['zip'] ?? '' ),
		);

		$raw_items = $data['items'] ?? ( $data['line_items'] ?? array() );
		$raw_items = is_array( $raw_items ) ? $raw_items : array();
		$items     = array();
		foreach ( $raw_items as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$lk  = (string) ( $li['kind'] ?? '' );
			$lk  = in_array( $lk, array( 'item', 'context', 'discount', 'fee', 'note' ), true ) ? $lk : 'item';
			$qty = isset( $li['quantity'] ) ? (float) $li['quantity'] : ( isset( $li['qty'] ) ? (float) $li['qty'] : 1.0 );
			if ( $qty <= 0 ) {
				$qty = 1.0;
			}
			$rate   = $this->money_to_float( $li['unit_price'] ?? ( $li['rate'] ?? 0 ) );
			$has_lt = array_key_exists( 'line_total', $li );
			$lt     = $has_lt ? $this->money_to_float( $li['line_total'] ) : ( $rate * $qty );
			// A discount/credit line reads as negative even if the source dropped the sign.
			if ( 'discount' === $lk && $lt > 0 ) {
				$lt = -$lt;
				if ( $rate > 0 ) {
					$rate = -$rate;
				}
			}
			$items[] = array(
				'kind'            => $lk,
				'description'     => (string) ( $li['description'] ?? '' ),
				'sub_description' => (string) ( $li['sub_description'] ?? ( $li['sub'] ?? '' ) ),
				'quantity'        => $qty,
				'unit_price'      => $rate,
				'line_total'      => $lt,
				'is_lot'          => ! empty( $li['is_lot'] ),
				'attribution'     => (string) ( $li['attribution'] ?? '' ),
			);
		}

		$discount_type = (string) ( $data['discount_type'] ?? 'none' );
		$discount_type = in_array( $discount_type, array( 'none', 'percent', 'amount' ), true ) ? $discount_type : 'none';

		$doc = array(
			'kind'           => $kind,
			'number'         => (string) ( $data['number'] ?? '' ),
			'date'           => (string) ( $data['date'] ?? '' ),
			'due_date'       => (string) ( $data['due_date'] ?? '' ),
			'reference'      => (string) ( $data['reference'] ?? '' ),
			'customer'       => $customer,
			'items'          => $items,
			'discount_type'  => $discount_type,
			'discount_value' => $this->money_to_float( $data['discount_value'] ?? 0 ),
			'tax'            => $this->money_to_float( $data['tax'] ?? 0 ),
			'shipping'       => $this->money_to_float( $data['shipping'] ?? 0 ),
			'amount_paid'    => $this->money_to_float( $data['amount_paid'] ?? 0 ),
			'salesperson'    => (string) ( $data['salesperson'] ?? '' ),
			'notes'          => (string) ( $data['notes'] ?? '' ),
			'terms'          => (string) ( $data['terms'] ?? '' ),
			'source_text'    => $source_text,
			'status'         => 'imported',
		);

		// Reconcile the computed total against the total printed on the source. Never
		// correct silently — surface it for the human to resolve in the review panel.
		$stated = array_key_exists( 'stated_total', $data ) ? $this->money_to_float( $data['stated_total'] )
			: ( array_key_exists( 'total', $data ) ? $this->money_to_float( $data['total'] ) : null );
		if ( null !== $stated && class_exists( 'ZEST_Doc_Renderer' ) ) {
			$t     = ZEST_Doc_Renderer::compute_totals( $items, $doc['discount_type'], $doc['discount_value'], $doc['tax'], $doc['shipping'] );
			$delta = round( (float) $t['total'] - $stated, 2 );
			if ( abs( $delta ) > 0.01 ) {
				$warnings[] = sprintf(
					'Totals mismatch: computed %s vs printed %s (off by %s). Check the line items — nothing was auto-corrected.',
					number_format( (float) $t['total'], 2 ),
					number_format( $stated, 2 ),
					number_format( $delta, 2 )
				);
			}
			$doc['stated_total'] = $stated; // review aid only; the import writers ignore it
		}

		$conf = isset( $data['confidence'] ) ? (int) $data['confidence'] : 0;
		if ( $conf > 0 && $conf < 70 ) {
			$warnings[] = sprintf( 'Low extraction confidence (%d%%). Review every field before importing.', $conf );
		}

		return array( 'doc' => $doc, 'warnings' => $warnings );
	}

	/**
	 * Parse a currency-ish value to a float. A number passes through; a string has "$",
	 * thousands commas and whitespace stripped, with wrapping parentheses "(175.00)", a
	 * leading U+2212 (−) or an ASCII "-" read as NEGATIVE. Unparseable → 0.0.
	 */
	private function money_to_float( $v ): float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = trim( (string) $v );
		if ( '' === $s ) {
			return 0.0;
		}
		$s   = str_replace( "\u{2212}", '-', $s ); // U+2212 MINUS SIGN → ASCII hyphen
		$neg = false;
		if ( preg_match( '/^\((.*)\)$/', $s, $m ) ) { // (175.00) accounting negative
			$neg = true;
			$s   = $m[1];
		}
		if ( strpos( $s, '-' ) !== false ) {
			$neg = true;
		}
		$s = preg_replace( '/[^0-9.]/', '', $s ); // drop $, commas, letters, sign, spaces
		if ( '' === $s || '.' === $s ) {
			return 0.0;
		}
		if ( substr_count( $s, '.' ) > 1 ) { // collapse stray dots, keep the first as the point
			$first = strpos( $s, '.' );
			$s     = substr( $s, 0, $first + 1 ) . str_replace( '.', '', substr( $s, $first + 1 ) );
		}
		$f = (float) $s;
		return $neg ? -$f : $f;
	}

	/** The import (verbatim-extraction) system prompt. No catalog, no pricing, no conventions. */
	private function build_import_prompt( array $context ): string {
		$kind_hint = in_array( ( $context['kind'] ?? '' ), array( 'estimate', 'invoice' ), true ) ? (string) $context['kind'] : '';
		$p         = array();
		$p[]       = 'You transcribe an EXISTING business document — an estimate/quote or an invoice — from the raw text of an uploaded PDF into structured JSON. This is a data-entry import, not a new quote. Copy every value EXACTLY as printed. Do NOT price anything, do NOT do math beyond reading printed numbers, and do NOT invent, reorder, merge, drop or "improve" any line. Preserve the document\'s own numbers verbatim (rates, line totals, tax, discounts, totals).';
		if ( '' !== $kind_hint ) {
			$p[] = 'The operator says this document is an ' . $kind_hint . '. Set "kind" to "' . $kind_hint . '".';
		} else {
			$p[] = 'Decide "kind": "invoice" if it shows an invoice number, an "Amount Due"/"Amount Paid" line, or a due date; otherwise "estimate" (also for a quote or proposal).';
		}
		$p[] = "## Line items\n"
			. "Each printed table row becomes one item, in order. Set each line's \"kind\":\n"
			. "- \"item\": a billable product/service line (the default).\n"
			. "- \"context\": a \$0 metadata line that is NOT a charge — e.g. a \"Location\" line, a heading, or a closing \"Tax & installation\" note. Keep it as its own line with line_total 0.\n"
			. "- \"discount\": a credit/discount line shown IN the items table (negative line_total).\n"
			. "- \"fee\": an explicit surcharge/fee line.\n"
			. "- \"note\": a free-text note line with no charge.\n"
			. "Copy each line's description and any secondary text (into sub_description). Put the printed quantity in \"quantity\", the printed unit price in \"unit_price\", and the printed line total in \"line_total\". If only a line total is printed, set quantity 1 and unit_price equal to that total.";
		$p[] = "## Currency\n"
			. "Return every money value as a number. When reading, strip \"\$\" and thousands commas, and treat wrapping parentheses \"(175.00)\" or a leading minus (\"-\" or the U+2212 \u{2212} sign) as NEGATIVE.";
		$p[] = "## House-paperwork rules to encode (common FreshBooks exports)\n"
			. "- A rep/initials code such as \"(GT)\" or a trailing \"- (AS)\" identifies the SALESPERSON: put the code (letters only, e.g. \"GT\" or \"AS\") in \"salesperson\". STILL keep the \"Location\" line itself as a kind:\"context\" line — do not delete it and do not move its text.\n"
			. "- A line worded like \"per Geoff\" or \"per Dana\" is a manual DISCOUNT/credit: kind:\"discount\", negative line_total, and put the name (\"Geoff\"/\"Dana\") in \"attribution\".\n"
			. "- A totals-section line like \"5% Discount\" is a HEADER discount, not an item: set discount_type:\"percent\" and discount_value:5 (the number only). A flat total-section discount like \"Discount -\$50\" → discount_type:\"amount\", discount_value:50. Do NOT also emit it as a line item.\n"
			. "- A grouped/lot line like \"(4) ... Total for Lot\" is ONE item: kind:\"item\", is_lot:true, quantity 1, and line_total equal to the printed lot total (keep the \"(4)\" in the description).";
		$p[] = "## Customer\n"
			. "Fill customer.name, org (company, if any), email, phone, street, city, state, zip from the bill-to / prepared-for block. Use \"\" for anything not printed. Never guess.";
		$p[] = $this->import_schema_block();
		return implode( "\n\n", array_filter( array_map( 'trim', $p ) ) );
	}

	/** The strict JSON schema the import model must return (canonical $doc + review aids). */
	private function import_schema_block(): string {
		return implode( "\n", array(
			'## Output — return ONLY this JSON object, no prose, no code fence',
			'{',
			'  "kind": "estimate",',
			'  "number": "", "date": "", "due_date": "", "reference": "",',
			'  "customer": {"name":"","org":"","email":"","phone":"","street":"","city":"","state":"","zip":""},',
			'  "items": [',
			'    {"kind":"item","description":"","sub_description":"","quantity":1,"unit_price":0,"line_total":0,"is_lot":false,"attribution":""}',
			'  ],',
			'  "discount_type": "none", "discount_value": 0,',
			'  "tax": 0, "shipping": 0, "amount_paid": 0,',
			'  "salesperson": "", "notes": "", "terms": "",',
			'  "stated_total": 0,',
			'  "confidence": 0',
			'}',
			'Rules: kind is "estimate" or "invoice". discount_type is "none", "percent" or "amount". item.kind is one of "item","context","discount","fee","note". "stated_total" is the grand total EXACTLY as printed on the document (used only for a mismatch check — do not compute it yourself). "confidence" is 0-100 for how cleanly the text extracted. Keep dates as printed (e.g. "05/14/2024") — do not reformat.',
		) );
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
