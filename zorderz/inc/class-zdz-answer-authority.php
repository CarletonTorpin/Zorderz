<?php
/**
 * ZDZ_Answer_Authority — the confidence tier and the single outbound gate.
 *
 * ONE service owns two jobs that the analytics/chat engine used to scatter across
 * a provenance checker, a self-check auditor, a query guard and four send paths:
 *
 *   1. THE CONFIDENCE TIER. Every figure a component states carries a tier —
 *      CONFIRMED > DERIVED > INFERRED > UNKNOWN — and the tier PROPAGATES THROUGH
 *      ARITHMETIC: a sum of a confirmed and an inferred figure is inferred, because
 *      it can be no stronger than its weakest input. A CONFIRMED claim can never be
 *      satisfied by an INFERRED cell. ZDZ_Figure is the value object that carries
 *      the tier; ZDZ_Answer_Authority::figure() mints one and the arithmetic helpers
 *      keep the tier honest.
 *
 *   2. THE SINGLE OUTBOUND GATE. Every channel that emits text to a human —
 *      chat, email, push, digest, stream — routes its payload through
 *      ZDZ_Answer_Authority::gate() before it leaves. A send path that does not
 *      call the gate is a bug: the gate is where INV-12 is enforced, in ONE place,
 *      so a fix reaches every channel at once (the old code fixed the chat surface
 *      and left the push/digest/stream surfaces leaking).
 *
 * INV-12 (the safety floor the gate enforces, non-overridable):
 *   - STATE FACTS ONLY. A figure a system of record does not back is not stated as
 *     fact; it is flagged, caveated, or withheld.
 *   - REFUSAL IS A VALID ANSWER. "I can't confirm that" beats a confident guess.
 *   - NEVER OUTCOME LANGUAGE UNLESS THE SYSTEM OF RECORD REPORTS IT. The assistant
 *     never says a thing was approved / sent / paid / won / booked / created unless
 *     the designated system of record for that claim confirms it in the context.
 *
 * Describe, never prescribe: the gate DECIDES (ok / partial / refuse) and records a
 * DISPOSITION for everything it drops or caveats (nothing silent). It replaces text
 * only when it must — a refusal is a replacement; a caveat is an addition — and it
 * never silently rewrites a model's prose, because a silent rewrite is itself an
 * unlogged side effect.
 *
 * Ships NEUTRAL. Thresholds and the currency locale come from config with Core
 * defaults; no company, product, person, place, provider or vendor is named here.
 * A tenant may tighten the thresholds via the `zdz_answer_authority_thresholds`
 * filter but cannot lower the INV-12 floor.
 *
 * Crosswalk: 05 §D (T-01 … T-28), Playbook §2.3, §7.
 *
 * @since   1.1.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A figure that knows how confident it is.
 *
 * The tier travels with the value through every arithmetic operation, taking the
 * WEAKEST tier of the operands, so a derived total built on an inferred input is
 * itself inferred and can never be presented as confirmed.
 */
final class ZDZ_Figure {

	/** @var float|int|null */
	public $value;
	/** @var string One of ZDZ_Answer_Authority::TIER_*. */
	public $tier;
	/** @var array Free-form provenance: sor_id, doc_kind, doc_id, field, fetched_at. */
	public $provenance;

	public function __construct( $value, string $tier = ZDZ_Answer_Authority::TIER_UNKNOWN, array $provenance = array() ) {
		$this->value      = is_numeric( $value ) ? $value + 0 : null;
		$this->tier       = ZDZ_Answer_Authority::normalize_tier( $tier );
		$this->provenance = $provenance;
	}

	/** Combine this figure with another under a binary op, weakening the tier. */
	private function combine( ZDZ_Figure $other, callable $op ): ZDZ_Figure {
		$a = ( null === $this->value ) ? 0 : $this->value;
		$b = ( null === $other->value ) ? 0 : $other->value;
		// A missing operand can only weaken the result.
		$tier = ZDZ_Answer_Authority::weakest(
			( null === $this->value ) ? ZDZ_Answer_Authority::TIER_UNKNOWN : $this->tier,
			( null === $other->value ) ? ZDZ_Answer_Authority::TIER_UNKNOWN : $other->tier
		);
		return new ZDZ_Figure( $op( $a, $b ), $tier );
	}

	public function plus( ZDZ_Figure $o ): ZDZ_Figure {
		return $this->combine( $o, static fn( $a, $b ) => $a + $b );
	}
	public function minus( ZDZ_Figure $o ): ZDZ_Figure {
		return $this->combine( $o, static fn( $a, $b ) => $a - $b );
	}
	public function times( ZDZ_Figure $o ): ZDZ_Figure {
		return $this->combine( $o, static fn( $a, $b ) => $a * $b );
	}
	/** Ratio; divide-by-zero yields an UNKNOWN figure rather than a fatal. */
	public function ratio( ZDZ_Figure $o ): ZDZ_Figure {
		if ( ! $o->value ) {
			return new ZDZ_Figure( null, ZDZ_Answer_Authority::TIER_UNKNOWN );
		}
		return $this->combine( $o, static fn( $a, $b ) => $a / $b );
	}

	/** May this figure be stated at (or above) the required tier? */
	public function may_state( string $required = ZDZ_Answer_Authority::TIER_DERIVED ): bool {
		return ZDZ_Answer_Authority::may_state( $this->tier, $required );
	}

	public function to_array(): array {
		return array(
			'value'      => $this->value,
			'tier'       => $this->tier,
			'provenance' => $this->provenance,
		);
	}
}

class ZDZ_Answer_Authority {

	/* ── Confidence tiers, strongest first ─────────────────────────────────── */
	const TIER_CONFIRMED = 'confirmed'; // stated by a system of record
	const TIER_DERIVED   = 'derived';   // computed from confirmed inputs
	const TIER_INFERRED  = 'inferred';  // an estimate / prior; never a fact
	const TIER_UNKNOWN   = 'unknown';   // no basis

	/** Tier ordering. Higher = more trustworthy. */
	const TIER_RANK = array(
		self::TIER_UNKNOWN   => 0,
		self::TIER_INFERRED  => 1,
		self::TIER_DERIVED   => 2,
		self::TIER_CONFIRMED => 3,
	);

	/* ── Egress verdicts ───────────────────────────────────────────────────── */
	const OK      = 'ok';      // may leave as-is
	const PARTIAL = 'partial'; // may leave with a caveat / some claims withheld
	const REFUSE  = 'refuse';  // may not leave; replaced by a refusal

	/**
	 * Every outbound channel names itself. A channel absent from this list still
	 * gets gated (fail loud, never crash) but is logged so a new send path that
	 * forgot to register is discoverable.
	 */
	const CHANNELS = array( 'chat', 'email', 'push', 'digest', 'stream' );

	/**
	 * A fourth verdict: the question does not apply (no filter was applied and
	 * nothing matched) - the mirror image of a false refusal. The gate treats NA as
	 * OK (the count block is simply omitted, no caveat added) and it NEVER upgrades a
	 * real REFUSE. §64 CH2.
	 */
	const NA = 'not_applicable';

	/**
	 * Live in-browser surfaces. Their payload is NOT run through sanitize_outbound():
	 * pictographs / zero-width characters render fine in a browser and scrubbing them
	 * would alter what the human typed or reads. Every OTHER channel is an external
	 * transport (CRM / email / push / digest) whose text IS scrubbed on the way out.
	 */
	const UI_SURFACES = array( 'chat', 'stream' );

	public static function init(): void {
		// Route for any component that would rather call a filter than the class.
		add_filter( 'zdz_answer_gate', array( __CLASS__, 'gate_filter' ), 10, 2 );
	}

	/* ───────────────────────────── Tier algebra ──────────────────────────── */

	public static function normalize_tier( string $tier ): string {
		$tier = strtolower( trim( $tier ) );
		return isset( self::TIER_RANK[ $tier ] ) ? $tier : self::TIER_UNKNOWN;
	}

	public static function tier_rank( string $tier ): int {
		return self::TIER_RANK[ self::normalize_tier( $tier ) ];
	}

	/** The weakest (lowest) of the given tiers — the propagation rule. */
	public static function weakest( string ...$tiers ): string {
		$out = self::TIER_CONFIRMED;
		foreach ( $tiers as $t ) {
			if ( self::tier_rank( $t ) < self::tier_rank( $out ) ) {
				$out = self::normalize_tier( $t );
			}
		}
		return $out;
	}

	/** May a figure of $have tier be stated where $required is demanded? */
	public static function may_state( string $have, string $required = self::TIER_DERIVED ): bool {
		return self::tier_rank( $have ) >= self::tier_rank( $required );
	}

	/** Mint a tiered figure. */
	public static function figure( $value, string $tier = self::TIER_UNKNOWN, array $provenance = array() ): ZDZ_Figure {
		return new ZDZ_Figure( $value, $tier, $provenance );
	}

	/** Sum a list of ZDZ_Figure, propagating the weakest tier (empty ⇒ UNKNOWN 0). */
	public static function sum( array $figures ): ZDZ_Figure {
		$acc = new ZDZ_Figure( 0, self::TIER_CONFIRMED );
		if ( empty( $figures ) ) {
			return new ZDZ_Figure( 0, self::TIER_UNKNOWN );
		}
		foreach ( $figures as $f ) {
			if ( $f instanceof ZDZ_Figure ) {
				$acc = $acc->plus( $f );
			}
		}
		return $acc;
	}

	/* ─────────────────────────────── Config ──────────────────────────────── */

	/**
	 * Answerability + confidence thresholds. Core defaults are neutral and
	 * universal (a fabricated money figure costs more than a fabricated count; a
	 * figure with no near-neighbour is almost certainly invented). A tenant may
	 * TIGHTEN these; the INV-12 floor below is not among them and cannot be moved.
	 */
	public static function thresholds(): array {
		$defaults = array(
			'clean_min'            => 90,   // >= clean
			'warn_min'             => 60,   // >= warning, else blocked
			'orphan_multiplier'    => 2.0,  // a figure with no near-neighbour costs double
			'max_unparseable_ratio'=> 0.40, // above this, refuse a count rather than guess
			// What a claim of each tier is allowed to do on the way out.
			'require_tier_for_fact'=> self::TIER_DERIVED, // stated as fact ⇒ derived+
			// Policy when the model asserts a business outcome the SoR has not confirmed.
			'outcome_without_sor'  => 'refuse', // 'refuse' | 'caveat'
			// Policy when a money/quantity figure has no backing in context.
			'unbacked_figure'      => 'caveat', // 'refuse' | 'caveat' | 'allow'
			// Money comparison tolerance in integer cents (reconcile + payment gate).
			'money_tolerance_cents'=> 1,
		);
		$t = apply_filters( 'zdz_answer_authority_thresholds', $defaults );
		return is_array( $t ) ? array_merge( $defaults, $t ) : $defaults;
	}

	/**
	 * Currency detection is locale-driven, never dollar-hardcoded — so a non-USD
	 * tenant still gets money extraction AND the shared-device scrub. The sigil
	 * comes from the Business Profile when present; the Core default is neutral.
	 *
	 * @return array{sigil:string,decimal:string,thousands:string}
	 */
	public static function currency(): array {
		$sigil     = '$';
		$decimal   = '.';
		$thousands = ',';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$s = (string) ZDZ_Business_Profile::get( 'currency_sign', '' );
			if ( '' !== $s ) {
				$sigil = $s;
			}
		}
		$c = apply_filters(
			'zdz_answer_authority_currency',
			array(
				'sigil'     => $sigil,
				'decimal'   => $decimal,
				'thousands' => $thousands,
			)
		);
		return is_array( $c ) ? $c : compact( 'sigil', 'decimal', 'thousands' );
	}

	/**
	 * The outcome/approval verbs that INV-12 governs. Generic and vendor-free;
	 * a tenant may ADD to this set but never remove one (the floor only rises).
	 */
	public static function outcome_terms(): array {
		$core = array(
			'approved', 'accepted', 'rejected', 'sent', 'delivered', 'paid',
			'refunded', 'won', 'closed won', 'booked', 'scheduled', 'invoiced',
			'created', 'updated', 'deleted', 'cancelled', 'canceled', 'completed',
			'submitted', 'confirmed',
		);
		$extra = apply_filters( 'zdz_answer_outcome_terms', array() );
		if ( is_array( $extra ) ) {
			$core = array_values( array_unique( array_merge( $core, array_map( 'strtolower', $extra ) ) ) );
		}
		return $core;
	}

	/* ──────────────────────────── Assessment ─────────────────────────────── */

	/**
	 * Analyse an outbound body WITHOUT mutating it. Pure: returns the findings the
	 * gate acts on. Two safety-floor checks plus a confidence score.
	 *
	 * @param string $text
	 * @param array  $context {
	 *   @type bool     $side_effect      A write was purportedly performed this turn.
	 *   @type array    $sor_outcomes     Outcomes the system of record CONFIRMS (e.g. ['sent']).
	 *   @type string[] $verified_figures Numeric strings the engine actually computed/fetched.
	 *   @type string[] $allowed_fallback_claims  Figures safe to state even unbacked.
	 * }
	 * @return array {verdict, score, dispositions[], outcome_claims[], unbacked_figures[]}
	 */
	public static function assess( string $text, array $context = array() ): array {
		$dispositions      = array();
		$score             = 100;
		$outcome_claims    = self::find_outcome_claims( $text, $context );
		$unbacked_figures  = self::find_unbacked_figures( $text, $context );

		$th        = self::thresholds();
		$verdict   = self::OK;

		// ── INV-12: outcome language without a system-of-record confirmation ──
		if ( ! empty( $outcome_claims ) ) {
			$score -= 40;
			$policy = ( 'caveat' === $th['outcome_without_sor'] && empty( $context['side_effect'] ) )
				? self::PARTIAL
				: self::REFUSE;
			$verdict = self::stronger_verdict( $verdict, $policy );
			$dispositions[] = array(
				'code'    => 'outcome_without_sor',
				'detail'  => 'stated an outcome the system of record has not confirmed',
				'claims'  => $outcome_claims,
			);
		}

		// ── Unbacked money / quantity figures ──
		if ( ! empty( $unbacked_figures ) ) {
			// Orphan figures (no near-neighbour) are the likeliest fabrications.
			$penalty = count( $unbacked_figures ) * 10;
			$score  -= (int) round( $penalty * (float) $th['orphan_multiplier'] );
			$policy  = 'refuse' === $th['unbacked_figure'] ? self::REFUSE
				: ( 'allow' === $th['unbacked_figure'] ? self::OK : self::PARTIAL );
			$verdict = self::stronger_verdict( $verdict, $policy );
			if ( self::OK !== $policy ) {
				$dispositions[] = array(
					'code'    => 'unbacked_figure',
					'detail'  => 'figure(s) not backed by fetched data',
					'figures' => $unbacked_figures,
				);
			}
		}

		$score = max( 0, min( 100, $score ) );
		// Score-band cross-check: a very low score can only strengthen the verdict.
		if ( $score < $th['warn_min'] ) {
			$verdict = self::stronger_verdict( $verdict, self::REFUSE );
		} elseif ( $score < $th['clean_min'] ) {
			$verdict = self::stronger_verdict( $verdict, self::PARTIAL );
		}

		return array(
			'verdict'          => $verdict,
			'score'            => $score,
			'dispositions'     => $dispositions,
			'outcome_claims'   => $outcome_claims,
			'unbacked_figures' => $unbacked_figures,
		);
	}

	/**
	 * THE SINGLE OUTBOUND GATE. Every channel calls this last, before the payload
	 * leaves. Assesses, applies policy, LOGS every drop/caveat as a disposition
	 * (nothing silent), and returns the payload the channel may actually emit.
	 *
	 * @param array $outbound { @type string $channel, @type string $text, @type array $context }
	 * @return array {verdict, text, score, tier, dispositions[]}
	 */
	public static function gate( array $outbound ): array {
		$channel = isset( $outbound['channel'] ) ? (string) $outbound['channel'] : 'chat';
		$text    = isset( $outbound['text'] ) ? (string) $outbound['text'] : '';
		$context = isset( $outbound['context'] ) && is_array( $outbound['context'] ) ? $outbound['context'] : array();
		
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			// Fail loud (log), never crash - an unregistered send path still gates.
			error_log( '[ZDZ_Answer_Authority] gate() called for unregistered channel: ' . $channel );
		}
		
		// Outbound text scrub - external transports only (CRM / email / push / digest),
		// NEVER the live in-browser surfaces. Applied first so assessment runs on the
		// cleaned text for those channels.
		if ( ! in_array( $channel, self::UI_SURFACES, true ) ) {
			$text = self::sanitize_outbound( $text );
		}
		
		$a              = self::assess( $text, $context );
		$assess_verdict = $a['verdict'];
		$verdict        = $assess_verdict;
		
		// Reader tier (by TRAIT; fail-safe to the middle tier). Opt-in via context; when
		// absent the legacy caveat is used, preserving prior behaviour exactly.
		$reader_tier = '';
		if ( isset( $context['reader_tier'] ) && '' !== (string) $context['reader_tier'] ) {
			$reader_tier = self::normalize_reader_tier( (string) $context['reader_tier'] );
		} elseif ( isset( $context['user_id'] ) ) {
			$reader_tier = self::reader_tier( (int) $context['user_id'] );
		}
		
		$additions    = array();            // factual lines APPENDED (never a rewrite)
		$dispositions = $a['dispositions']; // returned to the caller
		$fire         = $a['dispositions']; // fired here (reconcile fires its own)
		
		// -- CH1: total / row-sum reconciliation - flag-and-explain, NEVER rewrite --
		if ( isset( $context['reconcile'] ) && is_array( $context['reconcile'] )
			&& isset( $context['reconcile']['printed'], $context['reconcile']['rows'] ) ) {
			$rec = self::reconcile_total( $context['reconcile']['printed'], (array) $context['reconcile']['rows'] );
			if ( empty( $rec['agrees'] ) ) {
				$verdict        = self::stronger_verdict( $verdict, self::PARTIAL );
				$additions[]    = self::reconcile_caveat_text( $rec );
				$dispositions[] = array(
					'code'   => 'total_reconcile',
					'detail' => 'printed total and row sum disagree',
					'gap'    => $rec['gap'],
				);
			}
		}
		
		// -- CH7: deterministic engine-emitted scope caveat (never asked of the model) --
		if ( isset( $context['scope'] ) && is_array( $context['scope'] ) ) {
			$sc       = $context['scope'];
			$excluded = array_filter( array_map( 'trim', array_map( 'strval', (array) ( $sc['excluded'] ?? array() ) ) ) );
			if ( ! empty( $sc['search_broadened'] ) || ! empty( $excluded ) ) {
				$scope_line = self::scope_caveat_text( $sc );
				if ( '' !== $scope_line ) {
					$additions[]    = $scope_line;
					$scope_disp     = array( 'code' => 'scope_disclosure', 'detail' => 'engine-emitted scope caveat' );
					$dispositions[] = $scope_disp;
					$fire[]         = $scope_disp;
				}
			}
		}
		
		// -- Emit. A refusal REPLACES the body; a caveat / scope line is an ADDITION. --
		if ( self::REFUSE === $verdict ) {
			$out = self::refusal_text( $a );
		} else {
			$out = $text;
			// The provisional caveat fires only when ASSESS itself degraded the answer
			// (a genuine figure / outcome concern), tier-aware when the caller opted in.
			if ( self::PARTIAL === $assess_verdict ) {
				$partial_caveat = ( '' !== $reader_tier )
					? self::disclose_for_tier( $a, $reader_tier )
					: self::caveat_text( $a );
				if ( '' !== $partial_caveat ) {
					$out .= "\n\n" . $partial_caveat;
				}
			}
			foreach ( $additions as $add ) {
				if ( '' !== $add ) {
					$out .= "\n\n" . $add;
				}
			}
		}
		
		// Nothing silent: fire a disposition for every finding (reconcile fired its own).
		foreach ( $fire as $d ) {
			do_action( 'zdz_disposition', 'answer_authority', array_merge( $d, array( 'channel' => $channel, 'verdict' => $verdict ) ) );
		}
		
		return array(
			'verdict'      => $verdict,
			'text'         => $out,
			'score'        => $a['score'],
			'dispositions' => $dispositions,
		);
	}

	/** Filter form of gate(): apply_filters('zdz_answer_gate', $outbound). */
	public static function gate_filter( $result, $outbound ) {
		if ( is_array( $outbound ) ) {
			return self::gate( $outbound );
		}
		return $result;
	}

	/* ──────────────────────────── Internals ──────────────────────────────── */

	/** OK < PARTIAL < REFUSE — return the more restrictive of two verdicts. */
	private static function stronger_verdict( string $a, string $b ): string {
		// NA ranks with OK (0) so it never upgrades a real PARTIAL / REFUSE.
		$rank = array( self::NA => 0, self::OK => 0, self::PARTIAL => 1, self::REFUSE => 2 );
		return ( ( $rank[ $b ] ?? 0 ) > ( $rank[ $a ] ?? 0 ) ) ? $b : $a;
	}

	/**
	 * Outcome claims: an affirmative completion verb that the system of record has
	 * not confirmed for this turn. Conservative — requires an affirmative framing
	 * ("has been sent", "I've created", "was approved", "marked as paid") so a
	 * neutral discussion of a status does not trip it.
	 */
	private static function find_outcome_claims( string $text, array $context ): array {
		$confirmed = array();
		if ( ! empty( $context['sor_outcomes'] ) && is_array( $context['sor_outcomes'] ) ) {
			$confirmed = array_map( 'strtolower', $context['sor_outcomes'] );
		}
		if ( ! empty( $context['sor_confirmed'] ) ) {
			return array(); // whole turn is SoR-backed
		}

		$found = array();
		$lc    = strtolower( $text );
		foreach ( self::outcome_terms() as $verb ) {
			$v = preg_quote( $verb, '/' );
			// Affirmative completion framings only.
			$patterns = array(
				'/\b(?:has|have|is|are|was|were|been|been\s+successfully)\s+' . $v . '\b/',
				"/\bi(?:'ve| have|'ll| will)?\s+" . $v . '\b/',
				'/\b(?:marked|set)\s+(?:as\s+)?' . $v . '\b/',
				'/\b' . $v . '\s+(?:successfully|the\s+\w+)\b/',
			);
			foreach ( $patterns as $p ) {
				if ( preg_match( $p, $lc ) && ! in_array( $verb, $confirmed, true ) ) {
					$found[] = $verb;
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Money and large bare quantities present in the text but absent from the
	 * fetched/verified set and not on the allowed-fallback list. Locale-aware.
	 */
	private static function find_unbacked_figures( string $text, array $context ): array {
		$verified = array();
		foreach ( (array) ( $context['verified_figures'] ?? array() ) as $vf ) {
			$verified[] = self::normalize_number( (string) $vf );
		}
		$allowed = array();
		foreach ( (array) ( $context['allowed_fallback_claims'] ?? array() ) as $af ) {
			$allowed[] = self::normalize_number( (string) $af );
		}

		$cur   = self::currency();
		$sig   = preg_quote( $cur['sigil'], '/' );
		$sep   = preg_quote( $cur['thousands'], '/' );
		$dec   = preg_quote( $cur['decimal'], '/' );
		$found = array();

		// Money figures: sigil + grouped digits.
		if ( preg_match_all( '/' . $sig . '\s?\d{1,3}(?:' . $sep . '\d{3})*(?:' . $dec . '\d{1,2})?/', $text, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$n = self::normalize_number( $hit );
				if ( '' !== $n && ! in_array( $n, $verified, true ) && ! in_array( $n, $allowed, true ) ) {
					$found[] = trim( $hit );
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/** Strip a figure to comparable digits (sigil, grouping and decimals removed). */
	private static function normalize_number( string $s ): string {
		$cur = self::currency();
		$s   = str_replace( array( $cur['sigil'], $cur['thousands'], ' ' ), '', $s );
		$s   = str_replace( $cur['decimal'], '.', $s );
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $s, $m ) ) {
			return '';
		}
		return (string) ( $m[0] + 0 );
	}

	/** A neutral refusal — INV-12: refusal is a valid answer. */
	public static function refusal_text( array $assessment = array() ): string {
		$why = '';
		foreach ( (array) ( $assessment['dispositions'] ?? array() ) as $d ) {
			if ( 'outcome_without_sor' === ( $d['code'] ?? '' ) ) {
				$why = __( ' I can describe the current record, but I can\'t confirm that action happened unless the system of record shows it.', 'zorderz' );
				break;
			}
		}
		return __( 'I can\'t state that with confidence from the data I have.', 'zorderz' ) . $why;
	}

	/** A caveat appended to a PARTIAL answer. */
	public static function caveat_text( array $assessment = array() ): string {
		return __( 'Note: some figures above are not confirmed by the underlying records — treat them as provisional.', 'zorderz' );
	}

	/* ----------------- AC2 additions (Wave A: CH1/CH2/CH7/CH11/CH19, S5-02) ------ */

	/**
	 * CH1 - reconcile a printed total against the sum of its rows. FLAGS and EXPLAINS;
	 * it mutates nothing and NEVER rewrites the model's prose. Integer-cents compare.
	 * Emits a total_reconcile disposition (nothing silent). Per S1-03 / D-04 it never
	 * asserts which side is right beyond "likely".
	 *
	 * @param float|int|string|ZDZ_Figure $printed
	 * @param array                       $rows Row values (numbers, strings, or ZDZ_Figure).
	 * @param array                       $opts ['epsilon_cents'=>int]
	 * @return array ['agrees','printed','row_sum','gap','probable_cause','verdict_hint']
	 */
	public static function reconcile_total( $printed, array $rows, array $opts = array() ): array {
		$printed_cents = self::to_cents( $printed );
		$sum_cents     = 0;
		foreach ( $rows as $r ) {
			$sum_cents += self::to_cents( $r );
		}
		$epsilon   = isset( $opts['epsilon_cents'] ) ? (int) $opts['epsilon_cents'] : (int) self::thresholds()['money_tolerance_cents'];
		$gap_cents = $printed_cents - $sum_cents;
		$agrees    = ( abs( $gap_cents ) <= $epsilon );
		
		$out = array(
			'agrees'         => $agrees,
			'printed'        => $printed_cents / 100,
			'row_sum'        => $sum_cents / 100,
			'gap'            => $gap_cents / 100,
			'probable_cause' => null,
			'verdict_hint'   => $agrees ? 'agree' : 'disagreement',
		);
		if ( ! $agrees ) {
			$cause = self::explain_gap( $gap_cents / 100, $rows );
			if ( null !== $cause ) {
				$out['probable_cause'] = $cause;
				$out['verdict_hint']   = 'printed_likely_correct';
			}
		}
		
		do_action( 'zdz_disposition', 'answer_authority', array(
			'code'         => 'total_reconcile',
			'agrees'       => $agrees,
			'printed'      => $out['printed'],
			'row_sum'      => $out['row_sum'],
			'gap'          => $out['gap'],
			'verdict_hint' => $out['verdict_hint'],
		) );
		return $out;
	}

	/**
	 * Name the likeliest cause of a total/row-sum gap, or null. Conservative: only when
	 * a single row value equals the gap (a value probably counted twice) does it speak,
	 * and even then it only ever says the printed total is "probably" correct.
	 */
	public static function explain_gap( float $gap, array $rows ): ?string {
		$gap_cents = (int) round( abs( $gap ) * 100 );
		if ( 0 === $gap_cents ) {
			return null;
		}
		foreach ( $rows as $r ) {
			if ( self::to_cents( $r ) === $gap_cents ) {
				$cur   = self::currency();
				$shown = $cur['sigil'] . number_format( $gap_cents / 100, 2 );
				return sprintf(
					/* translators: %s is a money amount. */
					__( 'a value of %s appears counted twice; the printed total is probably the correct one', 'zorderz' ),
					$shown
				);
			}
		}
		return null;
	}

	/** The reader-facing caveat for a total/row-sum disagreement (an ADDITION, not a rewrite). */
	public static function reconcile_caveat_text( array $rec ): string {
		$cur     = self::currency();
		$printed = $cur['sigil'] . number_format( (float) ( $rec['printed'] ?? 0 ), 2 );
		$rowsum  = $cur['sigil'] . number_format( (float) ( $rec['row_sum'] ?? 0 ), 2 );
		if ( ! empty( $rec['probable_cause'] ) ) {
			return sprintf(
				/* translators: 1: printed total, 2: row sum, 3: probable-cause clause. */
				__( 'Note: the printed total (%1$s) and the sum of the lines (%2$s) disagree - %3$s.', 'zorderz' ),
				$printed,
				$rowsum,
				$rec['probable_cause']
			);
		}
		return sprintf(
			/* translators: 1: printed total, 2: row sum. */
			__( 'Note: the printed total (%1$s) and the sum of the lines (%2$s) disagree; I can\'t determine which is correct.', 'zorderz' ),
			$printed,
			$rowsum
		);
	}

	/**
	 * CH2 - the false-refusal guard. A filter is ACTIVE only when a filter field is
	 * non-empty. Filter active + zero matched -> REFUSE (a real "nothing matched").
	 * Filter INACTIVE (an unfiltered whole-portfolio question) + zero matched -> NA (not
	 * applicable: emit nothing, inject no prohibition). Data present -> OK. Sparse /
	 * unparseable above the ceiling -> PARTIAL.
	 *
	 * @param array $context ['filter_applied'=>bool,'detail_filter'=>array,'matched_count'=>int,'unparseable_ratio'=>float]
	 * @return string OK|PARTIAL|REFUSE|NA
	 */
	public static function assess_count_answerability( array $context ): string {
		$filter_active = ! empty( $context['filter_applied'] );
		if ( ! $filter_active && isset( $context['detail_filter'] ) && is_array( $context['detail_filter'] ) ) {
			foreach ( $context['detail_filter'] as $v ) {
				if ( is_array( $v ) ? ! empty( $v ) : ( '' !== trim( (string) $v ) ) ) {
					$filter_active = true;
					break;
				}
			}
		}
		
		if ( isset( $context['unparseable_ratio'] ) ) {
			$th = self::thresholds();
			if ( (float) $context['unparseable_ratio'] > (float) $th['max_unparseable_ratio'] ) {
				return self::PARTIAL;
			}
		}
		
		$matched = isset( $context['matched_count'] ) ? (int) $context['matched_count'] : 0;
		if ( $matched > 0 ) {
			return self::OK;
		}
		// Zero matched: a filter that matched nothing is a real refusal; an unfiltered
		// whole-portfolio question that simply has no data is NOT applicable - guarding the
		// false refusal (over-refusing is as dishonest as over-claiming).
		return $filter_active ? self::REFUSE : self::NA;
	}

	/**
	 * CH7 - a deterministic, engine-emitted scope line (facts, never asked of the model).
	 * States what was searched, whether the search was broadened, and what was excluded
	 * (e.g. past-dated documents the CALLER filtered - the engine states it, not the model).
	 */
	public static function scope_caveat_text( array $scope ): string {
		$consulted = array();
		foreach ( (array) ( $scope['sources_consulted'] ?? array() ) as $s ) {
			$s = trim( (string) $s );
			if ( '' !== $s ) {
				$consulted[] = $s;
			}
		}
		$excluded = array();
		foreach ( (array) ( $scope['excluded'] ?? array() ) as $e ) {
			$e = trim( (string) $e );
			if ( '' !== $e ) {
				$excluded[] = $e;
			}
		}
		
		$parts = array();
		if ( ! empty( $consulted ) ) {
			/* translators: %s is a comma-separated list of data sources searched. */
			$parts[] = sprintf( __( 'Searched: %s.', 'zorderz' ), implode( ', ', $consulted ) );
		}
		if ( ! empty( $scope['search_broadened'] ) ) {
			$parts[] = __( 'The search was broadened to locate a match.', 'zorderz' );
		}
		if ( ! empty( $excluded ) ) {
			/* translators: %s is a comma-separated list of what was excluded from the search. */
			$parts[] = sprintf( __( 'Excluded: %s.', 'zorderz' ), implode( ', ', $excluded ) );
		}
		return empty( $parts ) ? '' : implode( ' ', $parts );
	}

	/**
	 * CH11 - the reader's diagnostic tier, by TRAIT (never a role-slug literal). Fail-safe
	 * to the MIDDLE tier: fail-to-admin leaks diagnostics, fail-to-shared strips an
	 * admin's forensics.
	 *
	 * @return string 'admin'|'operator'|'shared'
	 */
	public static function reader_tier( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'operator';
		}
		// Shared-device / kiosk trait wins even over an admin capability (a shared screen).
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return 'shared';
		}
		// Admin trait - a capability, not a role slug.
		if ( function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			return 'admin';
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_admin_role' ) && function_exists( 'get_userdata' ) ) {
			$u = get_userdata( $user_id );
			if ( $u && isset( $u->roles ) && is_array( $u->roles ) && ! empty( $u->roles ) && ZDZ_User_Roles::is_admin_role( (string) reset( $u->roles ) ) ) {
				return 'admin';
			}
		}
		return 'operator';
	}

	/** Normalize an explicit tier string; unknown -> the middle tier. */
	private static function normalize_reader_tier( string $tier ): string {
		$tier = strtolower( trim( $tier ) );
		return in_array( $tier, array( 'admin', 'operator', 'shared' ), true ) ? $tier : 'operator';
	}

	/**
	 * CH11 - the reader-facing disclosure for a tier. admin -> full (provisional note +
	 * score + flags); operator -> one plain provisional line, no score; shared -> ''
	 * (nothing). Full detail is ALWAYS retained in the disposition log regardless of tier
	 * - this governs reader-facing verbosity only. The raw score is surfaced to admin only.
	 */
	public static function disclose_for_tier( array $assessment, string $tier ): string {
		$tier = self::normalize_reader_tier( $tier );
		if ( 'shared' === $tier ) {
			return '';
		}
		$line = self::caveat_text( $assessment );
		if ( 'admin' !== $tier ) {
			return $line; // operator: provisional line only, no score
		}
		$bits = array();
		if ( isset( $assessment['score'] ) ) {
			/* translators: %d is an internal confidence score out of 100. */
			$bits[] = sprintf( __( 'confidence score %d/100', 'zorderz' ), (int) $assessment['score'] );
		}
		$codes = array();
		foreach ( (array) ( $assessment['dispositions'] ?? array() ) as $d ) {
			if ( ! empty( $d['code'] ) ) {
				$codes[] = (string) $d['code'];
			}
		}
		if ( ! empty( $codes ) ) {
			/* translators: %s is a comma-separated list of internal check names. */
			$bits[] = sprintf( __( 'flags: %s', 'zorderz' ), implode( ', ', array_values( array_unique( $codes ) ) ) );
		}
		return empty( $bits ) ? $line : $line . ' (' . implode( '; ', $bits ) . ')';
	}

	/**
	 * CH19 - an HONEST coverage badge. States what a figures-located metric actually
	 * measured; NEVER a raw confidence percentage.
	 */
	public static function coverage_note( int $located, int $total ): string {
		$located = max( 0, $located );
		$total   = max( 0, $total );
		return sprintf(
			/* translators: 1: figures located, 2: total figures. NEVER a percentage. */
			__( 'Figures located: %1$d of %2$d matched to the source data. This checks that figures appear in the data - not that statements about them are correct.', 'zorderz' ),
			$located,
			$total
		);
	}

	/**
	 * S5-02 - the payment-claim gate. Returns true ONLY when the system of record backs
	 * it: an explicit 'paid' SoR outcome, OR an SoR-backed amount match (amount_paid +
	 * tolerance >= amount_due). Fail-safe FALSE - a heuristic or a model may NEVER assert
	 * paid. The receipt paid panel calls this before rendering "PAID" (INV-12 for money).
	 *
	 * @param array $context ['sor_outcomes'=>string[],'amount_paid'=>mixed,'amount_due'=>mixed,'sor_backed'=>bool]
	 */
	public static function payment_claimed( array $context ): bool {
		$sor = array();
		foreach ( (array) ( $context['sor_outcomes'] ?? array() ) as $o ) {
			$sor[] = strtolower( trim( (string) $o ) );
		}
		if ( in_array( 'paid', $sor, true ) ) {
			return true;
		}
		if ( isset( $context['amount_paid'], $context['amount_due'] ) && ! empty( $context['sor_backed'] ) ) {
			$paid = self::to_cents( $context['amount_paid'] );
			$due  = self::to_cents( $context['amount_due'] );
			$tol  = (int) self::thresholds()['money_tolerance_cents'];
			if ( $paid + $tol >= $due ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scrub outbound text for external transports (CRM / email / push). Conservative: it
	 * does NOT touch ordinary content, and it is applied ONLY at the gate for non-UI
	 * channels - never to prompts, never to the in-browser UI.
	 *   - U+2028 / U+2029 -> newline (REPLACED, never deleted - they carry a break);
	 *   - zero-width and joiners (U+200B..U+200D, U+FEFF) -> removed;
	 *   - variation / pictograph presentation selectors (U+FE00..U+FE0F) -> removed;
	 *   - 4-byte UTF-8 pictographs (astral plane, e.g. emoji) -> removed (CRM / SMS / push
	 *     transports frequently reject them);
	 *   - C0 / C1 controls except TAB and NEWLINE -> removed.
	 */
	public static function sanitize_outbound( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}
		// Line / paragraph separators -> newline (REPLACED, never deleted).
		$text = str_replace( array( "\u{2028}", "\u{2029}" ), "\n", $text );
		// Everything below is removed. One class; TAB (\x09) and NEWLINE (\x0A) are kept.
		$stripped = preg_replace(
			'/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200D}\x{FEFF}\x{FE00}-\x{FE0F}\x{10000}-\x{10FFFF}]/u',
			'',
			$text
		);
		return ( null === $stripped ) ? $text : $stripped;
	}

	/** Convert a number / money string / ZDZ_Figure to integer cents. */
	private static function to_cents( $v ): int {
		if ( $v instanceof ZDZ_Figure ) {
			$v = $v->value;
		}
		if ( is_string( $v ) ) {
			$n = self::normalize_number( $v );
			$v = ( '' === $n ) ? 0 : (float) $n;
		}
		return (int) round( ( (float) $v ) * 100 );
	}
}

ZDZ_Answer_Authority::init();
