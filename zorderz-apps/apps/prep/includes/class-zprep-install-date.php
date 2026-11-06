<?php
/**
 * Zorderz Prep — install-date DISPLAY adapter (the Prep side of the install date).
 *
 * Prep prints an INSTALL date on the cut sheet, the Ready-to-Cut card, the lead picker and
 * the CRM completion note. Prep does NOT know how an install date is resolved: it consumes
 * the PUBLISHED cross-plugin resolver boundary exposed by the jobs app —
 * `ZJOB_Install_Date` — and nothing else. Two — and only two — resolver methods are called:
 *
 *   ZJOB_Install_Date::for_leads( array $lead_ids, int $viewer ) : array   // [lead_id => envelope]
 *   ZJOB_Install_Date::for_estimate_numbers( array $nums, int $viewer ) : array  // [num => envelope]
 *
 * THE PUBLISHED BOUNDARY: Prep touches NO jobs table directly. Every resolver call is guarded with
 * class_exists( 'ZJOB_Install_Date' ); when the resolver is absent Prep DEGRADES GRACEFULLY —
 * it bakes no install_fallback and the surfaces simply omit the install line (the
 * relabelled dates still show). Prep never queries `scheduled_appt_id` / `scheduled_start_utc`.
 *
 * BOTH ARMS. An estimate-born card often carries only its estimate reference, not a
 * lead reference (the CRM lead syncs on a later clock). Prep therefore asks BOTH arms and
 * prefers a KNOWN (scheduled) answer from EITHER key; a non-known answer never downgrades a
 * known one (merge()).
 *
 * FIRST-PAINT SERVER BAKE. annotate_jobs() resolves both arms ONCE for the whole
 * queue and writes `install_fallback` onto each card, so the chip paints on first render
 * against a stale cached widget — no second async round trip for the initial paint.
 *
 * DISPLAY-ONLY — THE HARD INVARIANT. An install date is DISPLAY-ONLY. It is NEVER used as a
 * gate, a permission, or a sort that changes which cards show or their order. annotate_jobs()
 * only ADDS a key; it never reorders, filters or drops a card. `status`/`state` are for
 * presentation (a chip style) alone.
 *
 * WORDING IS NOT RE-HARDCODED. The three-state paper wording (scheduled date / "unscheduled" /
 * "unknown" / "not applicable") is owned by the resolver and produced by
 * ZJOB_Install_Date::paper_line() — a value that never returns empty and prints the non-scheduled
 * states distinctly. Prep REUSES paper_line() and never re-hardcodes those status
 * strings.
 *
 * 0000-00-00 GUARD. The resolver guards the zeroed MySQL sentinel at the source (a real date
 * rides ONLY state==='known'; every other state has date===null). Prep adds defence in depth:
 * it exposes a raw date to the client NEVER — the client renders only the resolver's paper_line
 * string — and a zeroed/blank date can never surface as a real date.
 *
 * PRECEDENCE: THE JOBS ANSWER WINS, THE BILLING GUESS FILLS ONLY BLANKS. A card may also carry
 * a date read from its billing paperwork (`billing_install`, ZPREP_Billing_Chain). That guess
 * is shown only when the jobs app has no known date for the card; it never replaces one.
 * precedence() is the one ordering, and the widget's install-merge.js applies the same ranks
 * when a later answer for the same job arrives in the browser:
 *
 *     jobs: known date   >   billing guess   >   jobs: unscheduled   >   jobs: unknown / n.a.
 *
 * A guess is shown as a guess (state `estimated`, never `known`) and names the document it
 * came from.
 *
 * Ships EMPTY: names no company / person / product / place / provider.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Install_Date {

	/** The published resolver class — the ONLY jobs symbol Prep is permitted to call. */
	const RESOLVER = 'ZJOB_Install_Date';

	/**
	 * Is the published install-date resolver available? Every public method fails soft to a
	 * no-op / null when this is false, so Prep degrades gracefully on an older/absent jobs app.
	 */
	public static function available(): bool {
		return class_exists( self::RESOLVER );
	}

	/* ================================================================
	 * FIRST-PAINT SERVER BAKE — the whole queue, both arms, once.
	 * ================================================================ */

	/**
	 * Bake an `install_fallback` display payload onto every card in $jobs.
	 *
	 * Both arms are resolved ONCE for the whole batch (all lead ids, all estimate numbers),
	 * then each card is merged from its own two keys. The returned array is the SAME cards in
	 * the SAME order with one key added — install is never a gate or a sort (see class docblock).
	 *
	 * @param array $jobs   card rows; each may carry 'lead_id' (int) and 'estimate_number' (string).
	 * @param int   $viewer WP user id (the arms gate per row, fail-closed).
	 * @return array the cards, order and membership unchanged, each with 'install_fallback' when available.
	 */
	public static function annotate_jobs( array $jobs, int $viewer, bool $with_ts = false ): array {
		if ( ! self::available() || empty( $jobs ) ) {
			// Graceful degrade: no install line at all (not even a billing guess), and the
			// raw guess never leaves the server.
			foreach ( $jobs as &$job ) {
				if ( is_array( $job ) ) {
					unset( $job['billing_install'] );
				}
			}
			unset( $job );
			return $jobs;
		}

		// Collect the distinct keys for a single call per arm.
		$lead_ids = array();
		$est_nums = array();
		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$lid = (int) ( $job['lead_id'] ?? 0 );
			if ( $lid > 0 ) {
				$lead_ids[ (string) $lid ] = $lid;
			}
			$num = self::estimate_of( $job );
			if ( '' !== $num ) {
				$est_nums[ $num ] = $num;
			}
		}

		$lead_map = self::resolve_leads( array_values( $lead_ids ), $viewer );
		$est_map  = self::resolve_estimates( array_values( $est_nums ), $viewer );

		foreach ( $jobs as &$job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$raw   = ( isset( $job['billing_install'] ) && is_array( $job['billing_install'] ) ) ? $job['billing_install'] : null;
			$guess = $raw ? self::billing_payload( $raw ) : null;
			$env   = self::resolve_env( $job, $lead_map, $est_map );
			$jobs_payload            = self::fallback_for_envelope( $env );
			$job['install_fallback'] = self::precedence( $jobs_payload, $guess );
			if ( $with_ts ) {
				// Server-side only (the queue counts): the time of the date the card shows.
				$job['install_ts'] = self::shown_ts( $job['install_fallback'], $env, $raw );
			}
			unset( $job['billing_install'] ); // the raw guess (a UTC datetime) never leaves the server
		}
		unset( $job );

		return $jobs;
	}

	/* ================================================================
	 * PRECEDENCE — one order for the server bake and the browser merge.
	 * ================================================================ */

	/** The unix time of the date a card shows (0 when it shows no date). */
	private static function shown_ts( ?array $shown, array $env, ?array $raw ): int {
		if ( ! is_array( $shown ) ) {
			return 0;
		}
		if ( 'billing' === (string) ( $shown['source'] ?? '' ) ) {
			$t = strtotime( (string) ( $raw['start_utc'] ?? '' ) . ' UTC' );
			return $t ? (int) $t : 0;
		}
		if ( 'known' === (string) ( $shown['state'] ?? '' ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $env['date'] ?? '' ) ) ) {
			$t = strtotime( substr( (string) $env['date'], 0, 10 ) . ' 12:00:00 UTC' );
			return $t ? (int) $t : 0;
		}
		return 0;
	}

	/**
	 * Rank a display payload: 3 a date the jobs app knows, 2 a billing-paperwork guess,
	 * 1 jobs "unscheduled", 0 jobs unknown / not applicable, -1 nothing.
	 */
	public static function display_rank( ?array $p ): int {
		if ( ! is_array( $p ) || '' === (string) ( $p['line'] ?? '' ) ) {
			return -1;
		}
		if ( 'billing' === (string) ( $p['source'] ?? '' ) ) {
			return 2;
		}
		if ( 'known' === (string) ( $p['state'] ?? '' ) ) {
			return 3;
		}
		return 'unscheduled' === (string) ( $p['status'] ?? '' ) ? 1 : 0;
	}

	/**
	 * The payload a card shows: the jobs answer when it is a known date, else the billing guess
	 * when there is one, else the jobs answer (unscheduled / unknown), else null.
	 */
	public static function precedence( ?array $jobs, ?array $guess ): ?array {
		return self::display_rank( $guess ) > self::display_rank( $jobs ) ? $guess : $jobs;
	}

	/**
	 * A billing-paperwork guess as a display payload. The line names the day (and time, when one
	 * was written), the document it was read from, and that more dates exist when they do.
	 * Wording filter: zprep_install_guess_line( $line, $env ).
	 */
	public static function billing_payload( array $env ): ?array {
		$day = (string) ( $env['date'] ?? '' );
		$utc = (string) ( $env['start_utc'] ?? '' );
		if ( '' === $utc || '0000-00-00 00:00:00' === $utc ) {
			return null;
		}
		$ts = strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return null;
		}
		$fmt  = ! empty( $env['has_time'] ) ? 'D M j, g:i a' : 'D M j';
		$when = function_exists( 'wp_date' ) ? (string) wp_date( $fmt, $ts ) : gmdate( $fmt, $ts );
		if ( '' === $when && '' !== $day ) {
			$when = $day;
		}
		$doc  = (string) ( $env['doc'] ?? '' );
		$line = '' !== $doc
			/* translators: 1: day (and time), 2: billing document, e.g. "invoice #1001". */
			? sprintf( __( '%1$s · per %2$s', 'zorderz' ), $when, $doc )
			: $when;
		if ( ! empty( $env['spread'] ) ) {
			$line .= ' ' . __( '(+more dates)', 'zorderz' );
		}
		$line = trim( (string) apply_filters( 'zprep_install_guess_line', $line, $env ) );
		if ( '' === $line ) {
			return null;
		}
		return array(
			'line'   => $line,
			'status' => 'scheduled',
			'state'  => 'estimated',
			'source' => 'billing',
		);
	}

	/**
	 * Resolve one card's install_fallback from a single (lead_id, estimate_number) pair. Used by
	 * the lookup / billing paths that resolve one job at a time. Returns null when the resolver is
	 * absent (the caller omits the install line).
	 *
	 * @param int|null $lead_id
	 * @param string   $estimate_number
	 * @param int      $viewer
	 * @return array|null a display payload {line,status,state}, or null when unavailable.
	 */
	public static function for_reference( ?int $lead_id, string $estimate_number, int $viewer ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$lead_id = (int) $lead_id;
		$num     = trim( $estimate_number );
		$lead_map = self::resolve_leads( $lead_id > 0 ? array( $lead_id ) : array(), $viewer );
		$est_map  = self::resolve_estimates( '' !== $num ? array( $num ) : array(), $viewer );
		return self::resolve_card(
			array( 'lead_id' => $lead_id, 'estimate_number' => $num ),
			$lead_map,
			$est_map
		);
	}

	/* ================================================================
	 * INTERNAL — the two published arms, guarded and fail-soft.
	 * ================================================================ */

	/** Arm 1 — resolve a batch of lead ids. Never throws to the caller. */
	private static function resolve_leads( array $lead_ids, int $viewer ): array {
		if ( empty( $lead_ids ) || ! self::available() || ! is_callable( array( self::RESOLVER, 'for_leads' ) ) ) {
			return array();
		}
		try {
			$out = ZJOB_Install_Date::for_leads( $lead_ids, $viewer );
			return is_array( $out ) ? $out : array();
		} catch ( \Throwable $e ) {
			return array(); // bookkeeping must not break the cut queue.
		}
	}

	/** Arm 2 — resolve a batch of estimate numbers. Never throws to the caller. */
	private static function resolve_estimates( array $est_nums, int $viewer ): array {
		if ( empty( $est_nums ) || ! self::available() || ! is_callable( array( self::RESOLVER, 'for_estimate_numbers' ) ) ) {
			return array();
		}
		try {
			$out = ZJOB_Install_Date::for_estimate_numbers( $est_nums, $viewer );
			return is_array( $out ) ? $out : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Merge a card's two arms into one display payload. Prefer a KNOWN answer from either key;
	 * a non-known answer never downgrades a known one. When neither is known, keep the more
	 * informative status. When the card has no reference at all, an explicit unknown is shown
	 * (the three-state contract is honest: "unknown" = nothing matched).
	 */
	private static function resolve_card( array $job, array $lead_map, array $est_map ): array {
		return self::fallback_for_envelope( self::resolve_env( $job, $lead_map, $est_map ) );
	}

	/** The merged resolver envelope of one card (see resolve_card()). */
	private static function resolve_env( array $job, array $lead_map, array $est_map ): array {
		$lid = (int) ( $job['lead_id'] ?? 0 );
		$num = self::estimate_of( $job );

		$lead_env = ( $lid > 0 && isset( $lead_map[ (string) $lid ] ) && is_array( $lead_map[ (string) $lid ] ) )
			? $lead_map[ (string) $lid ]
			: null;
		$est_env = ( '' !== $num && isset( $est_map[ $num ] ) && is_array( $est_map[ $num ] ) )
			? $est_map[ $num ]
			: null;

		if ( null === $lead_env && null === $est_env ) {
			return self::unknown_envelope();
		}
		if ( null === $lead_env ) {
			return $est_env;
		}
		if ( null === $est_env ) {
			return $lead_env;
		}
		return self::merge( $lead_env, $est_env );
	}

	/**
	 * Cross-arm merge (the same rule as the client's mergeInstallMap, server side). A KNOWN answer wins; when
	 * both are known they are the same confirmed booking (the lead arm is kept, deterministic);
	 * when neither is known the more informative status is kept — a scheduled answer is NEVER
	 * downgraded to unknown.
	 */
	private static function merge( array $a, array $b ): array {
		if ( self::is_known( $a ) ) {
			return $a;
		}
		if ( self::is_known( $b ) ) {
			return $b;
		}
		return ( self::rank( $a ) >= self::rank( $b ) ) ? $a : $b;
	}

	/** Known-ness via the PUBLISHED helper (state === 'known'); never re-derived locally. */
	private static function is_known( array $env ): bool {
		if ( self::available() && is_callable( array( self::RESOLVER, 'is_scheduled' ) ) ) {
			return (bool) ZJOB_Install_Date::is_scheduled( $env );
		}
		return false;
	}

	/**
	 * Informativeness of a NON-known status for the merge tiebreak (a display-precedence
	 * mechanism, not wording): scheduled > unscheduled > not_applicable > unknown.
	 */
	private static function rank( array $env ): int {
		switch ( (string) ( $env['status'] ?? '' ) ) {
			case 'scheduled':
				return 3;
			case 'unscheduled':
				return 2;
			case 'not_applicable':
				return 1;
			default:
				return 0;
		}
	}

	/* ================================================================
	 * DISPLAY payload — {line,status,state}. The client renders `line` only.
	 * ================================================================ */

	/**
	 * Build the client display payload from a resolver envelope.
	 *
	 *   line   : the paper string (ZJOB_Install_Date::paper_line — never empty, distinct per state,
	 *            no fault token). This is the ONLY value the client renders.
	 *   status : scheduled|unscheduled|unknown|not_applicable — presentation (chip style) only.
	 *   state  : known|unknown|not_applicable — presentation only (is this a real date?).
	 *
	 * NO raw date leaves this method — the client cannot accidentally render 0000-00-00 or an
	 * unformatted datetime, because it is never handed one. A real date rides only state==='known',
	 * and even then the client prints the resolver's formatted paper_line, not the ISO field.
	 */
	public static function fallback_for_envelope( array $env ): array {
		$status = (string) ( $env['status'] ?? 'unknown' );
		$state  = (string) ( $env['state'] ?? 'unknown' );

		$line = '';
		if ( self::available() && is_callable( array( self::RESOLVER, 'paper_line' ) ) ) {
			$line = (string) ZJOB_Install_Date::paper_line( $env );
		}
		$line = trim( $line );
		if ( '' === $line ) {
			$line = '—'; // never blank on paper — a blank reads as "nobody filled this in".
		}

		return array(
			'line'   => $line,
			'status' => $status,
			'state'  => $state,
			'source' => 'jobs',
		);
	}

	/** A well-formed "unknown" envelope for a card that carries no resolvable reference. */
	private static function unknown_envelope(): array {
		return array( 'state' => 'unknown', 'status' => 'unknown', 'date' => null );
	}

	/**
	 * The estimate number on a card, accepting either wire shape: 'estimate_number' (the
	 * Ready-to-Cut card) or 'estimate_num' (the lead-picker row). One accessor so both surfaces
	 * feed the same batch annotator.
	 */
	private static function estimate_of( array $job ): string {
		$num = trim( (string) ( $job['estimate_number'] ?? '' ) );
		if ( '' === $num ) {
			$num = trim( (string) ( $job['estimate_num'] ?? '' ) );
		}
		return $num;
	}
}
