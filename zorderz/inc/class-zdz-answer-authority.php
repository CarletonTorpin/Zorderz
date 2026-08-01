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
			// Fail loud (log), never crash — an unregistered send path still gates.
			error_log( '[ZDZ_Answer_Authority] gate() called for unregistered channel: ' . $channel );
		}

		$a       = self::assess( $text, $context );
		$verdict = $a['verdict'];
		$out     = $text;

		if ( self::REFUSE === $verdict ) {
			$out = self::refusal_text( $a );
		} elseif ( self::PARTIAL === $verdict ) {
			$out = $text . "\n\n" . self::caveat_text( $a );
		}

		// Nothing silent: fire a disposition for every finding so a funnel balances.
		foreach ( $a['dispositions'] as $d ) {
			do_action( 'zdz_disposition', 'answer_authority', array_merge( $d, array( 'channel' => $channel, 'verdict' => $verdict ) ) );
		}

		return array(
			'verdict'      => $verdict,
			'text'         => $out,
			'score'        => $a['score'],
			'dispositions' => $a['dispositions'],
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
		$rank = array( self::OK => 0, self::PARTIAL => 1, self::REFUSE => 2 );
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
}

ZDZ_Answer_Authority::init();
