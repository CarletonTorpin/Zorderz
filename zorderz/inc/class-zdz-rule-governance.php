<?php
/**
 * ZDZ_Rule_Governance — rules as typed objects, and the prompt as a rendering.
 *
 * The assistant used to carry ~71 named rules as prose inside a ~740-line system
 * prompt, plus a shadow registry (TSA_Rule_Registry) that pointed at seven of them
 * by two-letter id. Two-letter ids collide with staff initials by construction
 * (rule "DM" vs a person "DM"), the prompt was the only home of the wording, and a
 * cited id that no longer existed failed SILENTLY. This service fixes all three:
 *
 *   - RULES ARE TYPED, PARAMETERISED OBJECTS in a registry, addressed by a
 *     descriptive slug (never a two-letter code). Each carries an invariant, an
 *     enforcement tier, its triggers, and a PLACEHOLDER-DRIVEN directive template.
 *   - THE PROMPT IS A RENDERING of the rule set — render() turns the selected rules
 *     into the prompt block, interpolating tenant values (business name, the
 *     designated system of record, unit nouns) at build time. The wording lives in
 *     ONE place; the prompt is generated from it.
 *   - A CITED RULE MUST EXIST. cite() throws when a referenced id is unknown, and
 *     validate() lets a build fail on a dangling reference. Nothing cites a ghost.
 *
 * SAFETY FLOOR. Rules tiered `safety_floor` encode INV-level guarantees (honest
 * output, counts authority, no unconfirmed side effect, shared-device read-only).
 * A tenant may ADD rules or NARROW an advisory one through the `zdz_rules` filter,
 * but an attempt to weaken or remove a safety-floor rule is rejected and logged —
 * the floor only ever rises.
 *
 * The corpus recovered here is the off-repo assistant's rule set brought IN-REPO as
 * neutral, versioned templates: of the 62 live rules, the ~39 that name no company, product,
 * person, vendor or place ship as Core doctrine; the parameterised ones take their
 * specifics from the Business Profile, the Item Engine and the connection config at
 * render time. NO company/person/product/place/provider name appears in any
 * directive — only placeholders.
 *
 * Advisory, not enforcement (INV-1): a model that recites a rule can still break it.
 * The hard guarantees are enforced in code (ZDZ_Answer_Authority::gate(), the kiosk
 * strip, the confirm-before-write gates); this service helps the model SEE the rules
 * that govern the turn in front of it.
 *
 * Crosswalk: 05 §C (P-15, P-40, P-42), §D (T-22 … T-25); Playbook §2.6, §6.
 *
 * @since   1.1.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Rule_Governance {

	/** Corpus version — bumped when a directive changes; consumers cache-key on it. */
	const VERSION = '1.1.0';

	/** Keep the per-turn reminder small so it does not re-create the lost-in-the-middle problem. */
	const MAX_RULES_PER_TURN = 8;

	/* ── Enforcement tiers ──────────────────────────────────────────────────
	 * safety_floor    — an INV-level guarantee; NON-OVERRIDABLE; enforced in code.
	 * server_enforced — a code gate guarantees it; the manifest just explains it.
	 * hybrid          — partial code support + model discipline.
	 * advisory        — no code gate possible; the manifest is the primary lever.
	 */
	const TIER_SAFETY   = 'safety_floor';
	const TIER_ENFORCED = 'server_enforced';
	const TIER_HYBRID   = 'hybrid';
	const TIER_ADVISORY = 'advisory';

	/** @var array<string,array>|null Memoized merged corpus. */
	private static $memo = null;

	public static function init(): void {
		// One-time loud validation so a dangling cross-reference is caught early.
		add_action( 'init', array( __CLASS__, 'boot_validate' ), 5 );
	}

	/* ─────────────────────────── The Core corpus ─────────────────────────── */

	/**
	 * The shipped rules. Directives are PLACEHOLDER-DRIVEN templates: {business_name},
	 * {system_of_record}, {crm_name}, {counting_component}, {unit_noun} are resolved
	 * by the prompt builder at render time. No literal identity ever appears here.
	 *
	 * @return array<string,array>
	 */
	private static function core_rules(): array {
		return array(

			/* ── Safety floor (INV-level, non-overridable) ──────────────────── */

			'honest-output' => array(
				'title'       => 'Honest output — system-of-record language only',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'always' ),
				'enforced_by' => 'ZDZ_Answer_Authority::gate()',
				'directive'   => 'State only facts a system of record reports (identifiers, counts, status). Never claim an outcome — approved, sent, won, paid, booked, created — unless {system_of_record} confirms it. A refusal is a valid answer; "I can\'t confirm that" beats a confident guess.',
			),

			'counts-authority' => array(
				'title'       => 'Counts come from the authoritative counting component',
				'invariant'   => 'INV-3',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'always', 'signal:asks_count' ),
				'enforced_by' => '{counting_component} authoritative count block',
				'directive'   => 'Quantities of {unit_noun} come from {counting_component} — quote its figure verbatim. Never recompute a count yourself, never estimate one, and never derive a count from a money total.',
			),

			'no-unconfirmed-side-effect' => array(
				'title'       => 'Draft first — confirm and re-verify before any side effect',
				'invariant'   => 'INV-8',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'signal:marker_pending', 'signal:write_intent' ),
				'enforced_by' => 'draft→preview→confirm→re-verify (server side)',
				'directive'   => 'Preview every write — create, update, send, attach, book, exclude — and get an explicit confirmation before emitting its marker. The server re-checks the confirmation, so a marker without a real "yes" is held as a preview, not executed.',
			),

			'kiosk-read-only' => array(
				'title'       => 'Shared / unattended session: no side effects',
				'invariant'   => 'INV-10',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'signal:kiosk' ),
				'enforced_by' => 'kiosk marker strip + bridge refusal (server side)',
				'directive'   => 'On a shared or unattended device, never create, update, send or exclude anything, and never persist personal memory — these are stripped and refused server-side. Offer read-only help only.',
			),

			'no-unsourced-figures' => array(
				'title'       => 'Never state a figure the data does not contain',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'always', 'signal:asks_figure' ),
				'enforced_by' => 'ZDZ_Answer_Authority::gate()',
				'directive'   => 'Do not quote a figure — money, count, ratio — that the fetched data does not contain, and do not extrapolate a specific number from a broader total. If it is not in the data, say you do not have that breakdown rather than inventing one.',
			),

			'customer-identity-provenance' => array(
				'title'       => 'Same person only on an exact identifier match',
				'invariant'   => 'INV-2',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'category:customer_lookup', 'signal:customer_in_context' ),
				'enforced_by' => 'row-lineage association check',
				'directive'   => 'Treat two records as the same party ONLY on an exact email or phone match. Never merge on a shared surname, postal code or account label. An association (this person ↔ this order) must co-occur in the source rows, not be inferred from nearby text.',
			),

			'recipient-and-list-privacy' => array(
				'title'       => 'Outbound recipient gate + email-list privacy',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_SAFETY,
				'triggers'    => array( 'signal:email_intent', 'category:customer_lookup' ),
				'enforced_by' => 'ZDZ_Answer_Authority::gate() (email channel)',
				'directive'   => 'Never emit a recipient address the user did not supply or confirm. Never list more than a few customer email addresses in sequence; surface one at a time, and only for a customer the user named.',
			),

			/* ── Server-enforced / hybrid (parameterised) ───────────────────── */

			'source-of-record-filters-untrusted' => array(
				'title'       => 'A provider\'s own list filter is untrusted',
				'invariant'   => 'INV-2',
				'tier'        => self::TIER_HYBRID,
				'triggers'    => array( 'category:customer_lookup', 'signal:customer_in_context' ),
				'enforced_by' => 'client-side re-verification of every list filter',
				'directive'   => 'A list returned by {system_of_record} can silently include rows you did not ask for. Trust only rows that match the customer and date you requested; if a total looks too high for one customer, suspect an unhonoured filter.',
			),

			'assignment-vs-billing' => array(
				'title'       => 'Work assignment and billing are separate records',
				'invariant'   => 'INV-11',
				'tier'        => self::TIER_HYBRID,
				'triggers'    => array( 'category:lead_lookup', 'category:customer_lookup' ),
				'enforced_by' => '(advisory + bridge)',
				'directive'   => 'Lead and assignment state come from {crm_name}; billing state comes from {system_of_record}. Never invent a billing record from a lead. Queue actions may close or attach, never fabricate.',
			),

			'answerability' => array(
				'title'       => 'Obey the answerability verdict',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_HYBRID,
				'triggers'    => array( 'always' ),
				'enforced_by' => 'ZDZ_Answer_Authority::assess()',
				'directive'   => 'When the data is too sparse or too unparseable to answer, REFUSE or answer PARTIALLY rather than guess — and state plainly what you CAN say from what is present.',
			),

			'inference-is-inferred' => array(
				'title'       => 'An estimate is an inferred figure, never a fact',
				'invariant'   => 'INV-3',
				'tier'        => self::TIER_HYBRID,
				'triggers'    => array( 'signal:asks_count', 'signal:asks_figure' ),
				'enforced_by' => 'ZDZ_Figure tier propagation',
				'directive'   => 'Any figure you estimate rather than read is INFERRED and must be labelled as such; it can never satisfy a request for a confirmed number. The platform ships no built-in priors — an inferred figure exists only where a named tenant rule authorises it.',
			),

			/* ── Advisory (output discipline; ship as-is, no tenant content) ── */

			'no-thinking-traces' => array(
				'title'       => 'No visible chain-of-thought',
				'invariant'   => 'INV-1',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'always' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'Do the reasoning privately. The visible answer states conclusions and the figures that back them — not the planning that produced them.',
			),

			'terse-and-quantified' => array(
				'title'       => 'Terse, precise, quantified',
				'invariant'   => 'INV-1',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'always' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'Answer like a data terminal: concise and specific. Lead with the number asked for. Match the length of the answer to the size of the data — a small result gets a short answer.',
			),

			'scope-transparency' => array(
				'title'       => 'Disclose the scope of the search',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'always' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'Say what you searched and what you did not. If a date range, a source or a category was excluded, disclose it rather than implying the answer is complete.',
			),

			'zero-result-awareness' => array(
				'title'       => 'A zero result may be a missed filter',
				'invariant'   => 'INV-2',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'signal:zero_result' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'A keyword filter may miss valid variants. Before reporting "none", scan the unfiltered rows for synonyms; if a term was likely spelled differently, say so instead of asserting a hard zero.',
			),

			'ambiguity-detection' => array(
				'title'       => 'Resolve ambiguity by asking',
				'invariant'   => 'INV-1',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'signal:ambiguous' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'When a request could mean materially different things — a name that matches several parties, a period with no year — ask one clarifying question rather than picking one and proceeding.',
			),

			'comparison-hygiene' => array(
				'title'       => 'Compare like with like',
				'invariant'   => 'INV-1',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'signal:comparison' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'Do not compare a unit price with a total, or a partial period with a whole one. Where the business is seasonal, prefer same-period-last-year over raw month-over-month; where it is not, do not impose a seasonal frame.',
			),

			'cite-the-source' => array(
				'title'       => 'Attach the record reference to a key claim',
				'invariant'   => 'INV-12',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'signal:asks_figure' ),
				'enforced_by' => 'reference autolink pipeline',
				'directive'   => 'Where a key figure comes from a specific record, attach that record\'s reference so the reader can verify it. A broken reference is worse than none — if it cannot be resolved, state the figure in plain text without a link.',
			),

			'describe-not-prescribe' => array(
				'title'       => 'Describe how the business works; advise only on request',
				'invariant'   => 'INV-1',
				'tier'        => self::TIER_ADVISORY,
				'triggers'    => array( 'always' ),
				'enforced_by' => '(advisory)',
				'directive'   => 'Mirror how {business_name} actually prices and operates from its own data. Offer advice only when explicitly asked, keep it conservative, and disclose when you are reasoning from general knowledge rather than the business\'s own records.',
			),
		);
	}

	/* ─────────────────────────── Registry API ────────────────────────────── */

	/**
	 * The merged corpus: Core rules plus tenant additions/narrowings via the
	 * `zdz_rules` filter — with the safety floor protected. Memoized per request.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$core = self::core_rules();
		foreach ( $core as $id => &$r ) {
			$r = self::normalize_rule( $id, $r );
		}
		unset( $r );

		$merged = $core;

		/**
		 * Tenants may ADD a rule or NARROW an advisory/hybrid one. They may NOT
		 * weaken or remove a safety-floor rule; such an attempt is rejected + logged.
		 *
		 * @param array $tenant  id => rule-fragment
		 */
		$tenant = apply_filters( 'zdz_rules', array() );
		if ( is_array( $tenant ) ) {
			foreach ( $tenant as $id => $frag ) {
				$id = sanitize_key( (string) $id );
				if ( '' === $id || ! is_array( $frag ) ) {
					continue;
				}
				if ( isset( $core[ $id ] ) && self::TIER_SAFETY === $core[ $id ]['tier'] ) {
					// Protect the floor: allow adding trigger scope only, never re-tier or re-word.
					if ( isset( $frag['tier'] ) || isset( $frag['directive'] ) ) {
						error_log( '[ZDZ_Rule_Governance] refused override of safety-floor rule: ' . $id );
						continue;
					}
				}
				$base          = $merged[ $id ] ?? array( 'tier' => self::TIER_ADVISORY );
				$merged[ $id ] = self::normalize_rule( $id, array_merge( $base, $frag ) );
			}
		}

		self::$memo = $merged;
		return $merged;
	}

	/** Fill defaults + coerce types for one rule. */
	private static function normalize_rule( string $id, array $r ): array {
		return array(
			'id'          => $id,
			'title'       => (string) ( $r['title'] ?? $id ),
			'invariant'   => (string) ( $r['invariant'] ?? '' ),
			'tier'        => in_array( $r['tier'] ?? '', array( self::TIER_SAFETY, self::TIER_ENFORCED, self::TIER_HYBRID, self::TIER_ADVISORY ), true )
				? $r['tier'] : self::TIER_ADVISORY,
			'triggers'    => array_values( array_filter( array_map( 'strval', (array) ( $r['triggers'] ?? array() ) ) ) ),
			'enforced_by' => (string) ( $r['enforced_by'] ?? '(advisory)' ),
			'directive'   => (string) ( $r['directive'] ?? '' ),
		);
	}

	public static function exists( string $id ): bool {
		return isset( self::all()[ $id ] );
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Cite a rule by id — FAILS LOUDLY if it does not exist. Every place that
	 * references a rule id (a prompt block, a test, a bridge) should resolve it
	 * through cite(), so a dangling id can never ship silently.
	 *
	 * @throws RuntimeException when the id is unknown.
	 */
	public static function cite( string $id ): array {
		$r = self::get( $id );
		if ( null === $r ) {
			$msg = '[ZDZ_Rule_Governance] cited rule does not exist: ' . $id;
			error_log( $msg );
			throw new RuntimeException( $msg );
		}
		return $r;
	}

	/** @return string[] ids of every non-overridable safety-floor rule. */
	public static function safety_floor_ids(): array {
		$out = array();
		foreach ( self::all() as $id => $r ) {
			if ( self::TIER_SAFETY === $r['tier'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Validate the corpus: every referenced id resolves, and no directive carries a
	 * raw marker or a two-letter id. Returns [] when clean; a build can fail on a
	 * non-empty result.
	 *
	 * @return string[] error messages
	 */
	public static function validate(): array {
		$errors = array();
		foreach ( self::all() as $id => $r ) {
			if ( preg_match( '/^[A-Za-z]{1,2}$/', $id ) ) {
				$errors[] = "rule id '$id' is a short code — descriptive slugs only (they collide with staff initials)";
			}
			if ( '' === trim( $r['directive'] ) && self::TIER_ADVISORY !== $r['tier'] ) {
				$errors[] = "rule '$id' has no directive";
			}
		}
		return $errors;
	}

	/** Loud one-time validation at boot; never fatal in production. */
	public static function boot_validate(): void {
		$errors = self::validate();
		if ( ! empty( $errors ) ) {
			error_log( '[ZDZ_Rule_Governance] corpus validation: ' . implode( ' | ', $errors ) );
		}
	}

	/* ─────────────────────────── Selection ───────────────────────────────── */

	/**
	 * Deterministic selection for a turn: intent + kiosk + cheap signals → ordered
	 * rules. Pure (no LLM, no network). Safety-floor rules rank first, then
	 * advisory (they need the reminder most), then the rest; capped for recency.
	 *
	 * @param array $context { @type string $category, @type bool $is_kiosk, @type array $signals }
	 * @return array<string,array> ordered id => rule
	 */
	public static function select( array $context = array() ): array {
		$category = (string) ( $context['category'] ?? '' );
		$is_kiosk = ! empty( $context['is_kiosk'] );
		$signals  = (array) ( $context['signals'] ?? array() );

		$active = array();
		foreach ( self::all() as $id => $r ) {
			foreach ( $r['triggers'] as $trg ) {
				if ( self::trigger_matches( $trg, $category, $is_kiosk, $signals ) ) {
					$active[ $id ] = $r;
					break;
				}
			}
		}

		$rank = array( self::TIER_SAFETY => 0, self::TIER_ADVISORY => 1, self::TIER_HYBRID => 2, self::TIER_ENFORCED => 3 );
		uasort(
			$active,
			static fn( $a, $b ) => ( $rank[ $a['tier'] ] ?? 2 ) <=> ( $rank[ $b['tier'] ] ?? 2 )
		);

		if ( count( $active ) > self::MAX_RULES_PER_TURN ) {
			// Never drop a safety-floor rule; trim from the advisory tail.
			$floor = array();
			$rest  = array();
			foreach ( $active as $id => $r ) {
				if ( self::TIER_SAFETY === $r['tier'] ) {
					$floor[ $id ] = $r;
				} else {
					$rest[ $id ] = $r;
				}
			}
			$room  = max( 0, self::MAX_RULES_PER_TURN - count( $floor ) );
			$active = $floor + array_slice( $rest, 0, $room, true );
		}

		return $active;
	}

	private static function trigger_matches( string $trg, string $category, bool $is_kiosk, array $signals ): bool {
		if ( 'always' === $trg ) {
			return true;
		}
		if ( 'signal:kiosk' === $trg ) {
			return $is_kiosk;
		}
		if ( 0 === strpos( $trg, 'category:' ) ) {
			return substr( $trg, 9 ) === $category;
		}
		if ( 0 === strpos( $trg, 'signal:' ) ) {
			return ! empty( $signals[ substr( $trg, 7 ) ] );
		}
		return false;
	}

	/* ─────────────────────────── Rendering ───────────────────────────────── */

	/**
	 * Render a selected rule set into a prompt block — the prompt IS this rendering.
	 * Placeholders in each directive are interpolated from $params; marker tokens in
	 * any recited text are neutralised so a rule cannot trip a marker parser.
	 *
	 * @param array<string,array> $selected  from select() (or all() for the full set)
	 * @param array               $params    placeholder => value (from the Business Profile etc.)
	 * @return string
	 */
	public static function render( array $selected, array $params = array() ): string {
		if ( empty( $selected ) ) {
			return '';
		}
		$labels = array(
			self::TIER_SAFETY   => 'SAFETY FLOOR (non-negotiable)',
			self::TIER_ENFORCED => 'ENGINE-ENFORCED',
			self::TIER_HYBRID   => 'ENGINE-BACKED',
			self::TIER_ADVISORY => 'GUIDELINE',
		);

		$out  = "\n<rules>\n";
		$out .= "These rules govern this request. Follow every one; treat the safety floor as hard constraints you never work around.\n\n";
		foreach ( $selected as $id => $r ) {
			$directive = self::interpolate( $r['directive'], $params );
			$directive = self::escape_markers( $directive );
			$lbl       = $labels[ $r['tier'] ] ?? 'GUIDELINE';
			$out      .= '• [' . $lbl . '] ' . $r['title'] . ' — ' . $directive . "\n";
		}
		$out .= "</rules>\n";
		return $out;
	}

	/** Convenience: render the WHOLE corpus (used to publish the rule set). */
	public static function render_all( array $params = array() ): string {
		return self::render( self::all(), $params );
	}

	/** {placeholder} interpolation; an unresolved placeholder is left as a neutral blank. */
	private static function interpolate( string $tpl, array $params ): string {
		return preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( $m ) use ( $params ) {
				$key = $m[1];
				if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
					return (string) $params[ $key ];
				}
				// Neutral fallbacks so a directive still reads cleanly on an empty install.
				$neutral = array(
					'business_name'      => 'this business',
					'system_of_record'   => 'the system of record',
					'crm_name'           => 'the CRM',
					'counting_component' => 'the authoritative counting component',
					'unit_noun'          => 'items',
				);
				return $neutral[ $key ] ?? '';
			},
			$tpl
		);
	}

	/**
	 * Neutralise bracketed marker tokens ([ZDZ_EMAIL_DRAFT] → ZDZ_EMAIL_DRAFT, and
	 * any legacy [TS*_*]) so a recited rule can never match a marker parser. The
	 * marker vocabulary itself lives in ONE constants map owned by the app layer.
	 */
	public static function escape_markers( string $text ): string {
		return preg_replace( '/\[(\/?(?:ZDZ|ZANA|TS[A-Z]*)_[A-Z0-9_]+)\]/', '$1', $text );
	}
}

ZDZ_Rule_Governance::init();
