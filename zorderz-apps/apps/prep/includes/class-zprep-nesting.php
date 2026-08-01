<?php
/**
 * ZPREP Nesting — v2.1.1 guillotine strip-row cut optimizer.
 *
 * WHY THIS REPLACES THE v2.1.0 Maximal-Rectangles PACKER
 * ------------------------------------------------------
 * v2.1.0 introduced true 2-D bin-packing (MaxRects + Best-Short-Side-Fit +
 * seeded Monte-Carlo), scored fewest-sheets ≫ fewest-cuts ≫ least-waste. It
 * packed *tightly* but produced layouts the shop could not cut cleanly by hand:
 * piece types interleaved within a sheet, sub-rows that didn't share a seam
 * with the row above (the "jump-out"), and pieces seated away from the left
 * edge. The operator could not run one straight blade across the whole body of
 * the sheet.
 *
 * This version makes the shop's cutting preferences STRUCTURAL rather than
 * merely scored, so a clean cut path is guaranteed, not hoped for:
 *
 *   • STRIP ROWS (guillotine-friendly, P2 + P3). A sheet is a vertical stack of
 *     full-usable-width *strip rows*. Each row holds pieces of ONE type, laid
 *     left→right across the width. Because every row spans the full usable
 *     width, every boundary between rows is a single straight cross-cut that
 *     runs edge-to-edge — the operator rips the sheet into rows, then cross-cuts
 *     each row into pieces. No notches, no interrupted seams, ever. Repeated
 *     small pieces (e.g. four 8×8 on a 36" roll) naturally form one full-width
 *     strip the operator slices with identical cross-cuts.
 *
 *   • SAME-TYPE GROUPING (P1). One type per row, and rows of the same type are
 *     placed contiguously; the packer prefers to fill a whole sheet with one
 *     type before opening another, so a sheet can be labeled "all 8×8" /
 *     "all 15×7". Types never interleave within a sheet.
 *
 *   • LEFT-HAND BIAS (P4). Every piece is placed at the smallest free x in its
 *     row, so leftover always lands on the far right. Enforced by construction.
 *
 *   • v2.1.0 INVARIANTS PRESERVED (P5). Same usable width (roll_w − right
 *     margin), same per-sheet max length, white/black isolation + half-dome
 *     isolation (the engine still groups before calling us — we pack one
 *     single-color / single-shape group at a time).
 *
 *   • MORE SEARCH (P6). Several deterministic groupings/orderings PLUS a larger
 *     seeded Monte-Carlo budget, all under RNG seed 1337 so a given job always
 *     yields the same layout. The packed result is chosen by a score that now
 *     puts same-type-grouping and straight-cut cleanliness ABOVE waste and
 *     comparable to cut count — but sheets still dominate, so we never open an
 *     extra roll just to look tidier.
 *
 * Rotation: a rectangular piece is oriented so its LONG side runs ACROSS the
 * sheet width (e.g. a 15×7 lies flat, 15" across, in 7"-tall rows), so the
 * operator makes long straight rips across the body of the sheet rather than
 * many short ones — the shop's stated priority (longest cuts contiguous), even
 * when standing the piece up would nest more across.
 *
 * Output: an array of "page" records, one per sheet, each with absolute
 * per-piece {x,y,w,h} in inches (x = across the roll width, y = along its
 * length), plus piece_count / used_len / deliverables. This is byte-for-byte
 * the same shape compute_nesting_pages() consumed from the v2.1.0 packer, so
 * the SVG/print/leftover code downstream is unchanged.
 *
 * Pure / dependency-free (no WP calls) so it can be unit-tested offline.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZPREP_Nesting {

	/**
	 * Search budget. P6: "move to ~10 Monte-Carlo instead of ~5 — it should do
	 * more." We keep a small time ceiling for the field device and a trial cap;
	 * RNG is seeded so results are reproducible regardless of how many trials
	 * the budget allows.
	 */
	const TIME_BUDGET_SEC   = 4.0;
	const MAX_RANDOM_TRIALS = 400;    // raised from 240 (P6: explore more)
	const RNG_SEED          = 1337;   // deterministic runs (same job → same layout)

	/**
	 * Scoring weights, in strict priority bands so a higher band can never be
	 * traded for any amount of a lower one:
	 *   1. Fewest sheets        — never add a roll pull for cosmetics (W_SHEET).
	 *   2. Straight-cut penalty — any layout here is guillotine-clean by
	 *      construction, so this term only separates row COUNT (each row is one
	 *      edge-to-edge cross-cut). Fewer, fuller rows = fewer cross-cuts (P2).
	 *   3. Same-type purity     — a sheet that mixes types costs per extra type
	 *      present on it; one-type sheets score best (P1).
	 *   4. Cut operations       — total straight cuts (rips + cross-cuts).
	 *   5. Waste                — final tie-breaker (least leftover area).
	 *
	 * The bands are spaced so the worst realistic value of a lower band can't
	 * climb into the one above it.
	 *
	 * v2.1.8 priority (the shop's decision): minimize SHEETS, then keep cuts
	 * guillotine-clean (no T-JUNCTIONS — a cut should never stop at a
	 * perpendicular one), then minimize WASTE (fill the width — shorter/more
	 * cross-cuts are acceptable), and finally, as a TIE-BREAKER among
	 * equal-waste layouts, prefer pieces laid long-side-ACROSS so cuts run the
	 * full length wherever it costs nothing. Cut count is the last tie-breaker.
	 */
	const W_SHEET    = 1000000000.0; // band 1 — fewest sheets (never trade a roll pull)
	const W_TJUNCT   = 100000.0;     // band 2 — clean guillotine cuts (no interrupted cuts)
	const W_WASTE    = 1.0;          // band 3 — least waste (fill the width; short cuts OK)
	const W_ORIENT   = 0.05;         // band 4 — prefer long-side-across when waste-neutral
	const W_CUT      = 0.001;        // band 5 — fewest straight cuts (final tie-breaker)

	private float $margin;       // right-side usable-width margin (in)
	private float $max_len;      // max sheet length (in) for this workspace
	private int   $roll_w;       // nominal roll width (in)
	private array $debug = [];   // last pack()'s search trace (see get_debug())

	/**
	 * @param int   $roll_w  Nominal roll width (e.g. 36 or 60).
	 * @param float $margin  Right-side margin removed from usable width.
	 * @param float $max_len Maximum usable length per sheet.
	 */
	public function __construct( int $roll_w, float $margin, float $max_len ) {
		$this->roll_w  = $roll_w;
		$this->margin  = $margin;
		$this->max_len = $max_len;
	}

	/**
	 * Pack a flat list of pieces into sheets.
	 *
	 * @param array $pieces Each: { w, h, label, kind, color, rotatable(bool) }.
	 *                      w/h are the piece's own dimensions in inches.
	 * @return array[] Page records (see emit_page()).
	 */
	public function pack( array $pieces ): array {
		$pieces = array_values( array_filter( $pieces, fn( $p ) => ( $p['w'] ?? 0 ) > 0 && ( $p['h'] ?? 0 ) > 0 ) );
		if ( empty( $pieces ) ) return [];

		$usable_w = $this->roll_w - $this->margin;

		// Drop pieces that cannot fit the roll in either orientation. (The
		// engine pre-filters oversize via needs_dimensions, but stay safe.)
		$pieces = array_values( array_filter( $pieces, function ( $p ) use ( $usable_w ) {
			$short = min( (float) $p['w'], (float) $p['h'] );
			$long  = max( (float) $p['w'], (float) $p['h'] );
			// Fits if its short side spans the width and its long side the length,
			// OR (un-rotated only) its width spans the width and height the length.
			$rot_ok = empty( $p['rotatable'] ) ? false : ( $short <= $usable_w + 1e-6 && $long <= $this->max_len + 1e-6 );
			$nat_ok = ( (float) $p['w'] <= $usable_w + 1e-6 && (float) $p['h'] <= $this->max_len + 1e-6 );
			return $rot_ok || $nat_ok;
		} ) );
		if ( empty( $pieces ) ) return [];

		// Bucket pieces by type (identical dims + label).
		$buckets = $this->bucketize( $pieces );

		// ── MONTE-CARLO SEARCH over the block-based packer ──
		// Each trial builds a full candidate layout with the clean-block discipline
		// (per-type sheets + partial-sheet merge), but VARIES the choices that
		// affect packing: the order types are placed/merged, each type's
		// orientation preference, and whether strip-fill is used. We score every
		// candidate on guillotine cut-quality (T-junctions) → cuts → waste →
		// sheets, and keep the best. Seeded (RNG_SEED) so a given job always
		// yields the same layout. The first few trials are deterministic
		// "sensible" orderings (area-desc, width-desc, height-desc); the rest are
		// seeded shuffles, bounded by MAX_RANDOM_TRIALS and TIME_BUDGET_SEC.
		mt_srand( self::RNG_SEED );

		$keys = array_keys( $buckets );
		$orderings = $this->seed_orderings( $buckets, $usable_w );

		$best = null; $best_score = INF;
		$best_meta = null;
		$runner_up = INF;          // 2nd-best distinct score (for "why this won")
		$sheet_hist = [];          // sheet-count → how many trials produced it
		$min_sheets_seen = PHP_INT_MAX;
		$start = microtime( true );
		$trial = 0;

		while ( true ) {
			if ( $trial < count( $orderings ) ) {
				$order = $orderings[ $trial ];
				$order_kind = 'seed#' . $trial;
			} else {
				if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET_SEC ) break;
				if ( ( $trial - count( $orderings ) ) >= self::MAX_RANDOM_TRIALS ) break;
				$order = $keys;
				shuffle_seeded( $order );
				$order_kind = 'random';
			}
			$trial++;

			// Vary strip-fill: most trials on (it usually helps); some off so a
			// cleaner no-fill layout can win on cut-quality when fill would add
			// T-junctions. Deterministic by trial index for reproducibility.
			$use_strip = ( $trial % 7 !== 0 );

			// Vary per-type ORIENTATION. This is the key degree of freedom for
			// tight packing: a type can lie long-side-across (longest cut, but may
			// waste width) or stand short-side-across (more pieces across, fills
			// the width). We try: all-default (null), all-long, all-short, then
			// seeded per-type random. The scorer keeps whichever gives the best
			// guillotine quality + least waste.
			$orient = [];
			$mode = $trial % 4;
			foreach ( $keys as $k ) {
				if ( $mode === 0 )      $orient[ $k ] = null;   // best_orientation default
				elseif ( $mode === 1 )  $orient[ $k ] = false;  // un-rotated (natural)
				elseif ( $mode === 2 )  $orient[ $k ] = true;   // rotated
				else                    $orient[ $k ] = ( mt_rand( 0, 1 ) === 1 ); // mixed
			}

			$layout = $this->build_layout( $buckets, $order, $usable_w, $use_strip, $orient );
			if ( empty( $layout ) ) continue;

			$n_sheets = count( $layout );
			$sheet_hist[ $n_sheets ] = ( $sheet_hist[ $n_sheets ] ?? 0 ) + 1;
			if ( $n_sheets < $min_sheets_seen ) $min_sheets_seen = $n_sheets;

			$score = $this->score_layout( $layout, $usable_w );
			if ( $score < $best_score ) {
				if ( $best_score < INF ) $runner_up = $best_score; // demote prior best
				$best_score = $score;
				$best = $layout;
				$best_meta = [
					'trial'      => $trial,
					'order_kind' => $order_kind,
					'order'      => implode( ' → ', $order ),
					'orient_mode'=> [ 0 => 'default(long-across)', 1 => 'natural', 2 => 'rotated', 3 => 'mixed' ][ $mode ],
					'strip_fill' => $use_strip,
				];
			} elseif ( $score < $runner_up && $score > $best_score ) {
				$runner_up = $score;
			}
		}

		if ( empty( $best ) ) { $this->debug = [ 'ok' => false, 'reason' => 'no feasible layout' ]; return []; }

		// Tidy packet: full/!partial sheets first (largest length first).
		usort( $best, fn( $a, $z ) => $z['used_len'] <=> $a['used_len'] );

		// ── DEBUG TRACE — the search's steps + the winning layout's score
		// breakdown + per-sheet metrics. Collected always (cheap); the engine
		// only forwards it to the UI when debug mode is on.
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		$breakdown  = $this->score_breakdown( $best, $usable_w );
		$per_sheet  = [];
		foreach ( $best as $bin ) {
			$rects = array_map( fn( $p ) => [ 'x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h'] ], $bin['placed'] );
			list( $tj, $cc ) = $this->guillotine_analyze( $rects, 0.0, 0.0, $usable_w, $bin['used_len'] );
			$area = 0.0; $types = [];
			foreach ( $bin['placed'] as $p ) { $area += $p['w'] * $p['h']; $types[ $p['label'] ] = true; }
			$sheet_area = $usable_w * $bin['used_len'];
			$per_sheet[] = [
				'len'        => round( $bin['used_len'], 2 ),
				'pieces'     => count( $bin['placed'] ),
				'types'      => array_keys( $types ),
				't_junctions'=> $tj,
				'cuts'       => $cc,
				'waste_pct'  => $sheet_area > 0 ? round( 100 * ( 1 - $area / $sheet_area ), 1 ) : 0,
			];
		}
		$this->debug = [
			'ok'            => true,
			'roll_w'        => $this->roll_w,
			'usable_w'      => round( $usable_w, 2 ),
			'max_len'       => $this->max_len,
			'piece_count'   => array_sum( array_map( fn( $b ) => (int) $b['qty'], $buckets ) ),
			'type_count'    => count( $buckets ),
			'trials_run'    => $trial,
			'elapsed_ms'    => $elapsed_ms,
			'seed'          => self::RNG_SEED,
			'winner'        => $best_meta,
			'score'         => round( $best_score, 3 ),
			'score_breakdown' => $breakdown, // sheets / t_junctions / waste / orient / cuts
			'sheets'        => count( $best ),
			'per_sheet'     => $per_sheet,
			'reasoning'     => $this->build_reasoning( $best, $per_sheet, $breakdown, $best_meta, $buckets, $usable_w, $trial, $runner_up, $best_score, $sheet_hist, $min_sheets_seen ),
		];

		$pages = [];
		foreach ( $best as $bin ) {
			$pages[] = $this->emit_page( $bin, $usable_w );
		}
		return $pages;
	}

	/**
	 * Produce human-readable "thinking" — the reasoning and trade-offs behind the
	 * chosen layout, derived from the ACTUAL decisions (not canned text). Returns
	 * an ordered list of { stage, note } lines the UI prints under "Reasoning".
	 */
	private function build_reasoning( array $bins, array $per_sheet, array $bd, ?array $winner, array $buckets, float $usable_w, int $trials, float $runner_up, float $best_score, array $sheet_hist, int $min_sheets ): array {
		$R = [];
		$cap = $this->max_len;

		// 1) The goal / priority the search optimized.
		$R[] = [ 'stage' => 'goal', 'note' =>
			'Optimized in priority order: (1) fewest sheets, (2) clean guillotine cuts ' .
			'(no cut stops at a perpendicular one — T-junctions), (3) least waste / fill ' .
			'the width, (4) prefer full-length cuts as a tie-breaker.' ];

		// 2) Search outcome — how hard it looked and what it found.
		$ties = $sheet_hist[ $min_sheets ] ?? 0;
		$R[] = [ 'stage' => 'search', 'note' =>
			'Ran ' . $trials . ' candidate layouts (seeded, reproducible). The best ' .
			'achievable sheet count seen was ' . $min_sheets . '; ' . $ties . ' of the ' .
			'trials reached it. Kept the cleanest-cutting, least-wasteful one of those.' ];

		// 3) Why the winner beat the runner-up (decode the band gap).
		if ( $runner_up < INF ) {
			$gap = $runner_up - $best_score;
			$why = '';
			if ( $gap >= self::W_SHEET * 0.5 )      $why = 'it used at least one fewer sheet';
			elseif ( $gap >= self::W_TJUNCT * 0.5 ) $why = 'it removed an interrupted (T-junction) cut';
			else                                    $why = 'it wasted less material with equally clean cuts';
			$R[] = [ 'stage' => 'winner', 'note' =>
				'Chosen trial #' . ( $winner['trial'] ?? 0 ) . ' (orientation: ' . ( $winner['orient_mode'] ?? '—' ) .
				', strip-fill: ' . ( ! empty( $winner['strip_fill'] ) ? 'on' : 'off' ) . '). It beat the ' .
				'next-best candidate because ' . $why . '.' ];
		} else {
			$R[] = [ 'stage' => 'winner', 'note' =>
				'Only one viable arrangement was found for this job (trial #' . ( $winner['trial'] ?? 0 ) . ').' ];
		}

		// 4) Cut-quality verdict.
		if ( (int) $bd['t_junctions'] === 0 ) {
			$R[] = [ 'stage' => 'cuts', 'note' =>
				'Every sheet decomposes into full edge-to-edge cuts — no cut has to stop at ' .
				'a perpendicular one. This is the cleanest cut quality the rule asks for.' ];
		} else {
			$R[] = [ 'stage' => 'cuts', 'note' =>
				$bd['t_junctions'] . ' interrupted (T-junction) cut(s) remain — the tightest ' .
				'arrangement found still needed them to avoid adding a sheet.' ];
		}

		// 5) Per-sheet WHY — derived from each sheet's composition.
		foreach ( $bins as $i => $bin ) {
			$ps = $per_sheet[ $i ];
			$ntypes = count( $ps['types'] );
			$fillpct = 100 - $ps['waste_pct'];
			$lenpct  = $cap > 0 ? round( 100 * $ps['len'] / $cap ) : 0;
			$note = 'Sheet ' . ( $i + 1 ) . ' (' . $ps['pieces'] . ' pcs, ' . round( $fillpct ) . '% full): ';

			if ( $ntypes === 1 ) {
				if ( $ps['waste_pct'] <= 12 ) {
					$note .= 'a clean single-type sheet, packed tight — the ideal case.';
				} elseif ( $lenpct >= 95 ) {
					$note .= 'a single type that fills the full ' . ( (float) $cap ) . '" length but only part of the width; ' .
					         'the leftover width strip was too small/!shaped to take another whole part without spilling to a new sheet.';
				} else {
					$note .= 'the trailing remainder of one type — what is left after its full sheets; it cannot combine ' .
					         'with another partial without exceeding the ' . ( (float) $cap ) . '" length cap.';
				}
			} else {
				$note .= $ntypes . ' types placed as separate clean blocks (' . implode( ' + ', $ps['types'] ) . '). ' .
				         'They were merged onto one sheet specifically to retire a whole sheet, each kept as its own ' .
				         'block so cuts stay straight.';
			}
			$R[] = [ 'stage' => 'sheet', 'note' => $note ];
		}

		// 6) Honest ceiling note — where the remaining waste comes from.
		$total_piece_area = 0.0;
		foreach ( $buckets as $b ) $total_piece_area += $b['area'] * $b['qty'];
		$floor = (int) ceil( $total_piece_area / ( $usable_w * $cap ) );
		if ( $min_sheets > $floor ) {
			$R[] = [ 'stage' => 'ceiling', 'note' =>
				'Theoretical floor for this job is ' . $floor . ' sheets (pure area). The clean-block, ' .
				'straight-cut rule costs ' . ( $min_sheets - $floor ) . ' extra sheet(s) vs. an interleaved ' .
				'nest — a deliberate trade of a little material for cut simplicity.' ];
		} else {
			$R[] = [ 'stage' => 'ceiling', 'note' =>
				'This matches the theoretical floor (' . $floor . ' sheets) — no material is being left on the table.' ];
		}

		return $R;
	}

	/** Last pack()'s search trace + winning-layout score breakdown (debug mode). */
	public function get_debug(): array { return $this->debug; }

	/** Decompose the score into its named bands (for the debug report). */
	private function score_breakdown( array $bins, float $usable_w ): array {
		$sheets = count( $bins ); $tj = 0; $cuts = 0; $waste = 0.0; $against = 0;
		foreach ( $bins as $bin ) {
			$rects = array_map( fn( $p ) => [ 'x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h'] ], $bin['placed'] );
			foreach ( $bin['placed'] as $p ) { if ( $p['w'] + 1e-6 < $p['h'] ) $against++; }
			list( $t, $c ) = $this->guillotine_analyze( $rects, 0.0, 0.0, $usable_w, $bin['used_len'] );
			$tj += $t; $cuts += $c;
			$area = 0.0; foreach ( $bin['placed'] as $p ) $area += $p['w'] * $p['h'];
			$waste += max( 0.0, $usable_w * $bin['used_len'] - $area );
		}
		return [
			'sheets'       => $sheets,
			't_junctions'  => $tj,
			'waste_sqin'   => round( $waste, 1 ),
			'not_long_across' => $against,
			'cuts'         => $cuts,
		];
	}

	/**
	 * Build ONE candidate layout with the clean-block discipline:
	 *   • PHASE A: pack each type (in $order) into its own clean sheets via
	 *     pack_type_bins(); collect full sheets, hold each type's trailing
	 *     partial sheet aside.
	 *   • PHASE B: merge the partial sheets as clean contiguous blocks, only when
	 *     a merge fits one sheet (saving a sheet); never interleaved.
	 * $use_strip toggles same-type leftover-strip fill for this candidate.
	 *
	 * @return array[] bins
	 */
	private function build_layout( array $buckets, array $order, float $usable_w, bool $use_strip, array $orient = [] ): array {
		$full_bins = [];
		$partial_bins = [];
		foreach ( $order as $key ) {
			if ( ! isset( $buckets[ $key ] ) ) continue;
			$fr = array_key_exists( $key, $orient ) ? $orient[ $key ] : null;
			$bins = $this->pack_type_bins( $buckets[ $key ], $usable_w, $use_strip, $fr );
			if ( empty( $bins ) ) continue;
			$last = array_pop( $bins );
			foreach ( $bins as $fb ) $full_bins[] = $fb;
			// Remember this type's orientation so the merge re-lays it the same way.
			$last['__force_rot'] = $fr;
			$partial_bins[] = $last;
		}
		$merged = $this->merge_partial_bins( $partial_bins, $usable_w, $use_strip );
		return array_merge( $full_bins, $merged );
	}

	/* ===================================================================
	 * BUCKETS — group identical pieces (same dims + label) for strip rows.
	 * =================================================================== */
	private function bucketize( array $pieces ): array {
		$buckets = [];
		foreach ( $pieces as $p ) {
			$k = $this->dim_key( $p ) . '|' . ( $p['label'] ?? '' );
			if ( ! isset( $buckets[ $k ] ) ) {
				$buckets[ $k ] = [
					'w'         => (float) $p['w'],
					'h'         => (float) $p['h'],
					'label'     => $p['label'] ?? '',
					'kind' => $p['kind'] ?? 'custom',
					'rotatable' => ! empty( $p['rotatable'] ),
					'qty'       => 0,
					'area'      => (float) $p['w'] * (float) $p['h'],
				];
			}
			$buckets[ $k ]['qty']++;
		}
		return $buckets;
	}

	private function dim_key( array $p ): string {
		$lo = min( (float) $p['w'], (float) $p['h'] );
		$hi = max( (float) $p['w'], (float) $p['h'] );
		return sprintf( '%07.2f|%07.2f', $hi, $lo );
	}

	/* ===================================================================
	 * ORDERING SEEDS — the order in which whole TYPES are introduced.
	 * Same-type grouping is structural (one type per row), so what varies
	 * between trials is which type fills a sheet first. These deterministic
	 * orders tend to pack the fewest sheets while keeping types contiguous.
	 * =================================================================== */
	private function seed_orderings( array $buckets, float $usable_w ): array {
		$keys = array_keys( $buckets );

		$sort = function ( callable $cmp ) use ( $keys, $buckets ) {
			$k = $keys; usort( $k, fn( $a, $b ) => $cmp( $buckets[ $a ], $buckets[ $b ] ) ); return $k;
		};

		// Row-length footprint of a whole bucket = how much sheet length its
		// pieces consume when laid in full-width strip rows (fewer = shorter).
		$footprint = function ( array $b ) use ( $usable_w ) {
			return $this->bucket_row_length( $b, $usable_w );
		};

		return [
			// Tallest single-piece row-height first (big pieces define the sheet).
			$sort( fn( $a, $b ) => $this->row_unit_height( $b, $usable_w ) <=> $this->row_unit_height( $a, $usable_w ) ),
			// Largest total footprint first (pack the bulky types, fill the rest).
			$sort( fn( $a, $b ) => $footprint( $b ) <=> $footprint( $a ) ),
			// Largest individual piece area first.
			$sort( fn( $a, $b ) => $b['area'] <=> $a['area'] ),
			// Most pieces first (long repetitive strips lead).
			$sort( fn( $a, $b ) => $b['qty'] <=> $a['qty'] ),
			// Smallest footprint first (consolidate little types early).
			$sort( fn( $a, $b ) => $footprint( $a ) <=> $footprint( $b ) ),
			// Stable label order (deterministic baseline).
			$sort( fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) ),
		];
	}

	/**
	 * The per-row height a bucket contributes: the piece dimension that runs
	 * ALONG the sheet length when the piece is oriented to maximize how many
	 * fit across the usable width.
	 */
	private function row_unit_height( array $b, float $usable_w ): float {
		list( , $row_h ) = $this->best_orientation( $b, $usable_w );
		return $row_h;
	}

	/** Total sheet-length a bucket needs as full-width strip rows. */
	private function bucket_row_length( array $b, float $usable_w ): float {
		// Quantity-aware so the footprint matches the orientation the packer will
		// actually use for this many pieces (v2.1.16).
		list( $across, $row_h ) = $this->best_orientation( $b, $usable_w, null, (int) ( $b['qty'] ?? 0 ) );
		if ( $across < 1 ) return INF;
		$rows = (int) ceil( $b['qty'] / $across );
		return $rows * $row_h;
	}

	/**
	 * Choose a piece type's orientation for full-width strip-row packing.
	 *
	 * v2.1.16 — MINIMUM-LENGTH orientation, with the shop's long-side-across cut
	 * preference kept as the tie-breaker.
	 *
	 * WHY THIS CHANGED. The old rule always stood the piece long-side-ACROSS
	 * (largest across-dimension first) for the longest contiguous cut. That is
	 * the right call when it is free — but it sometimes COSTS roll length. Real
	 * examples on a 36" roll (35.5" usable):
	 *   • 4× a 14×24 piece — long-across packs 1/row → 4 rows = 56"; laying it
	 *     14-across packs 2/row → 2 rows = 48" (saves 8").
	 *   • 6× 20×10      — long-across 1/row → 60"; 20-across-rotated 3/row → 40".
	 * The previous code only reached the cheaper layout if the Monte-Carlo search
	 * happened to roll the right per-type orientation — non-deterministic and the
	 * main reason the compute felt slow (it leaned on hundreds of random trials).
	 *
	 * Now we pick, among fitting orientations, the one that uses the LEAST total
	 * sheet length for this type's quantity (rows × row-height). Ties — including
	 * the very common single-row case where both orientations cost the same — go
	 * to the LONGER across-dimension (the shop's preferred full-length cut), then
	 * to the fuller row, then un-rotated for stability. So when long-side-across
	 * is waste-neutral it is still chosen; it is only overridden when standing the
	 * piece the other way genuinely saves material. This makes the FIRST
	 * (deterministic) trial already good; the Monte-Carlo then only has to explore
	 * type ORDER and merges, so it converges fast and reproducibly.
	 *
	 * $qty lets us compare true total length (rows depend on how many pieces).
	 * When $qty is null we fall back to comparing a single row (row-height), which
	 * preserves the old call sites that only wanted the per-row height.
	 *
	 * @return array{0:int,1:float,2:bool} [ pieces_across, row_height, rotated ]
	 */
	private function best_orientation( array $b, float $usable_w, ?bool $force_rot = null, ?int $qty = null ): array {
		$w = (float) $b['w']; $h = (float) $b['h'];

		// Candidate orientations: [ across_dim (piece width on the sheet),
		//                           along_dim (row height), rotated? ]
		$cands = [ [ $w, $h, false ] ];
		if ( ! empty( $b['rotatable'] ) && abs( $w - $h ) > 0.001 ) {
			$cands[] = [ $h, $w, true ];
		}

		// Keep only orientations that physically fit the sheet.
		$fit = [];
		foreach ( $cands as $c ) {
			list( $pw, $ph, $rot ) = $c;
			if ( $pw > $usable_w + 1e-6 ) continue;       // too wide for the roll
			if ( $ph > $this->max_len + 1e-6 ) continue;  // too long for a sheet
			$across = (int) floor( ( $usable_w + 1e-6 ) / $pw );
			if ( $across < 1 ) continue;
			// Total length to lay $qty pieces in full-width rows at this orientation.
			$n      = ( $qty !== null && $qty > 0 ) ? $qty : 1;
			$rows   = (int) ceil( $n / $across );
			$length = $rows * $ph;
			$fit[] = [ 'across' => $across, 'rowh' => $ph, 'rot' => $rot, 'pw' => $pw, 'length' => $length ];
		}
		if ( empty( $fit ) ) {
			// Nothing fits the given width. Do NOT force-place — return across=0 so
			// callers (esp. fit_block_in_strip / build_type_block) correctly treat
			// this as "no pieces placeable here" and reject, rather than laying a
			// piece off the edge of the sheet. (Bugfix v2.1.10: the old fail-safe
			// returned [1,$h,false] and produced out-of-bounds pieces in width strips.)
			return [ 0, $h, false ];
		}

		// If the search forces a specific rotation and it fits, honor it. This
		// lets the Monte-Carlo try standing a piece on its short side (more across,
		// less width waste) vs. long-side-across (longest contiguous cut) and
		// keep whichever scores better on guillotine quality + waste.
		if ( $force_rot !== null ) {
			foreach ( $fit as $f ) {
				if ( $f['rot'] === $force_rot ) return [ $f['across'], $f['rowh'], $f['rot'] ];
			}
		}

		// Least total length first; tie → longer across-cut (shop preference);
		// tie → fuller row; tie → un-rotated for stability.
		usort( $fit, function ( $a, $z ) {
			if ( abs( $a['length'] - $z['length'] ) > 1e-6 ) return $a['length'] <=> $z['length']; // less material
			if ( abs( $a['pw'] - $z['pw'] ) > 1e-9 )         return $z['pw'] <=> $a['pw'];          // longer across-cut
			if ( $a['across'] !== $z['across'] )             return $z['across'] <=> $a['across'];  // fuller row
			return ( $a['rot'] ? 1 : 0 ) <=> ( $z['rot'] ? 1 : 0 );                                 // prefer un-rotated
		} );
		$pick = $fit[0];

		return [ $pick['across'], $pick['rowh'], $pick['rot'] ];
	}

	/* ===================================================================
	 * ONE PACKING PASS — fill sheets with full-width single-type strip rows,
	 * introducing types in $order, one type fully placed before the next.
	 *
	 * v2.1.7 — LEFTOVER-STRIP FILL. When a type's primary orientation leaves a
	 * width strip on the right (e.g. a 23"-wide column on a 35.5" sheet leaves
	 * 12.5"), we pack MORE of the SAME piece into that strip, rotated 90° (short
	 * side across the strip, long side along the length). That right-hand strip
	 * gets ripped off and discarded anyway, so subdividing it adds pieces using
	 * material that was already going to be cut — it does NOT introduce a new
	 * cross-body cut and does not disturb the primary full-width rips. The strip
	 * is its own clean guillotine sub-region: one rip separates it from the
	 * primary block, then short cross-cuts divide it.
	 * =================================================================== */
	/**
	 * Pack ONE piece type into clean, single-type sheets.
	 *
	 * Each sheet is "Sheet 4 quality":
	 *   • PRIMARY zone: long-side-across columns, left-justified, stacked down the
	 *     length to the page cap (full-width straight rips between rows).
	 *   • STRIP zone: the right-hand leftover width filled with the SAME piece
	 *     rotated, flush left in its band, as one clean block.
	 *   • Any unused area consolidates into a single rectangle at the bottom-right.
	 *
	 * @return array[] bins (each a new_bin() with placed/rows/types/used_len).
	 */
	private function pack_type_bins( array $b, float $usable_w, bool $use_strip = true, ?bool $force_rot = null ): array {
		$remaining = (int) $b['qty'];
		$bins = [];
		while ( $remaining > 0 ) {
			$bin = $this->new_bin();
			$placed = $this->build_type_block( $b, $remaining, $usable_w, 0.0, $this->max_len, $use_strip, $force_rot );
			if ( $placed['count'] < 1 ) break; // safety: nothing fits
			$this->apply_block_to_bin( $bin, $placed );
			$bins[] = $bin;
			$remaining -= $placed['count'];
		}
		return $bins;
	}

	/**
	 * Build a single-type block within a length budget, starting at length y0.
	 * Returns placed pieces (absolute coords), rows, length used, and count.
	 * Pure geometry — used both for per-type sheets and for merge candidates.
	 *
	 * @return array{placed:array,rows:array,len:float,count:int}
	 */
	private function build_type_block( array $b, int $qty, float $usable_w, float $y0, float $len_budget, bool $use_strip = true, ?bool $force_rot = null ): array {
		$placed = []; $rows = []; $count = 0;
		if ( $qty < 1 ) return [ 'placed' => [], 'rows' => [], 'len' => 0.0, 'count' => 0 ];

		// Pass the actual quantity so the orientation choice minimizes TOTAL
		// length (rows × row-height), not just per-row height (v2.1.16).
		list( $across, $row_h, $rot ) = $this->best_orientation( $b, $usable_w, $force_rot, $qty );
		$pw = $rot ? (float) $b['h'] : (float) $b['w'];   // primary width (across)
		$ph = $rot ? (float) $b['w'] : (float) $b['h'];   // primary height (along length)
		if ( $across < 1 || $ph > $len_budget + 1e-6 ) {
			return [ 'placed' => [], 'rows' => [], 'len' => 0.0, 'count' => 0 ];
		}

		$primary_block_w = $across * $pw;
		$leftover_w      = $usable_w - $primary_block_w;
		$strip_cols      = 0;
		if ( $use_strip && $leftover_w > 1e-6 && abs( $pw - $ph ) > 1e-6 ) {
			$strip_cols = (int) floor( ( $leftover_w + 1e-6 ) / $ph );
		}

		// PRIMARY zone: as many full rows as fit the budget (and the qty).
		$rows_fit   = (int) floor( ( $len_budget + 1e-6 ) / $ph );
		$y = $y0; $used_len = 0.0;
		for ( $r = 0; $r < $rows_fit && $count < $qty; $r++ ) {
			$n = min( $across, $qty - $count );
			for ( $i = 0; $i < $n; $i++ ) {
				$placed[] = [ 'x' => $i * $pw, 'y' => $y, 'w' => $pw, 'h' => $ph,
				              'label' => $b['label'], 'kind' => $b['kind'], 'rot' => $rot ];
			}
			$rows[] = [ 'y' => $y, 'h' => $ph, 'label' => $b['label'], 'count' => $n, 'fill' => $n * $pw ];
			$y += $ph; $used_len += $ph; $count += $n;
		}

		// STRIP zone: same type rotated, flush left in the strip, within the SAME
		// length the primary zone used (so the strip never extends the sheet).
		if ( $strip_cols > 0 && $count < $qty && $used_len > 1e-6 ) {
			$srows = (int) floor( ( $used_len + 1e-6 ) / $pw ); // rotated piece is $pw long
			$placed_strip = 0;
			for ( $sr = 0; $sr < $srows && $count < $qty; $sr++ ) {
				$sy = $y0 + $sr * $pw;
				for ( $sc = 0; $sc < $strip_cols && $count < $qty; $sc++ ) {
					$sx = $primary_block_w + $sc * $ph;
					$placed[] = [ 'x' => $sx, 'y' => $sy, 'w' => $ph, 'h' => $pw,
					              'label' => $b['label'], 'kind' => $b['kind'], 'rot' => ! $rot ];
					$count++; $placed_strip++;
				}
			}
			if ( $placed_strip > 0 ) {
				$rows[] = [ 'y' => $y0, 'h' => $used_len, 'label' => $b['label'],
				            'count' => $placed_strip, 'fill' => 0, 'strip' => true ];
			}
		}

		return [ 'placed' => $placed, 'rows' => $rows, 'len' => $used_len, 'count' => $count ];
	}

	/** Append a built block's pieces/rows to a bin and extend its used_len. */
	private function apply_block_to_bin( array &$bin, array $block ): void {
		foreach ( $block['placed'] as $p ) $bin['placed'][] = $p;
		foreach ( $block['rows'] as $r )    $bin['rows'][]   = $r;
		foreach ( $block['placed'] as $p )  $bin['types'][ $p['label'] ] = true;
		$maxb = 0.0;
		foreach ( $bin['placed'] as $p ) $maxb = max( $maxb, $p['y'] + $p['h'] );
		$bin['used_len'] = $maxb;
	}

	/**
	 * Merge partial (last) single-type sheets into fewer sheets — but ONLY by
	 * stacking clean, contiguous, single-type BLOCKS along the length, and ONLY
	 * when a merge actually reduces the sheet count. Never interleaves types.
	 *
	 * Greedy: sort partials by length descending; for each, try to append other
	 * partials' blocks beneath it while they fit the page cap. The block already
	 * carries absolute y-offsets relative to its own sheet, so we re-lay each
	 * merged block at the running length offset to keep coords correct.
	 *
	 * @param array[] $partials bins (each a single-type partial sheet)
	 * @return array[] merged bins
	 */
	private function merge_partial_bins( array $partials, float $usable_w, bool $use_strip = true ): array {
		// Re-derive each partial as a (bucket, qty) so we can re-lay its block at
		// an arbitrary length offset during merge.
		$items = [];
		foreach ( $partials as $bin ) {
			$spec = $this->bin_to_typespec( $bin );
			$spec['force_rot'] = $bin['__force_rot'] ?? null;
			$items[] = $spec;
		}
		// Largest length first.
		usort( $items, fn( $a, $z ) => $z['len'] <=> $a['len'] );

		$used = array_fill( 0, count( $items ), false );
		$out  = [];

		for ( $i = 0; $i < count( $items ); $i++ ) {
			if ( $used[ $i ] ) continue;
			$used[ $i ] = true;

			$bin = $this->new_bin();
			$base = $this->build_type_block( $items[ $i ]['bucket'], $items[ $i ]['qty'], $usable_w, 0.0, $this->max_len, $use_strip, $items[ $i ]['force_rot'] );
			$this->apply_block_to_bin( $bin, $base );
			$cursor = $bin['used_len'];

			// Try to stack other partials' blocks beneath, clean and contiguous,
			// while they fit. Each appended block is its own type (never mixed
			// within a row), satisfying "clean blocks, never interleaved".
			for ( $j = $i + 1; $j < count( $items ); $j++ ) {
				if ( $used[ $j ] ) continue;
				$remaining_len = $this->max_len - $cursor;
				$blk = $this->build_type_block( $items[ $j ]['bucket'], $items[ $j ]['qty'], $usable_w, $cursor, $remaining_len, $use_strip, $items[ $j ]['force_rot'] );
				// Only accept if it places the WHOLE partial (so we truly remove a
				// sheet rather than split one type across more sheets).
				if ( $blk['count'] >= $items[ $j ]['qty'] && $blk['count'] > 0 ) {
					$this->apply_block_to_bin( $bin, $blk );
					$cursor = $bin['used_len'];
					$used[ $j ] = true;
				}
			}

			// (B) SIDE-BY-SIDE MERGE — when the base block is a tall column that
			// leaves an empty WIDTH strip on the right (e.g. five 23×12 leave a
			// 12.5" strip running the full sheet length), fill that strip with
			// another WHOLE partial as a clean block. One rip down the strip's
			// left edge separates it from the base, so cuts stay guillotine-clean.
			// Accept only a partial that fits ENTIRELY — so a sheet is truly
			// retired (your rule: merge only when it saves a whole sheet).
			$base_right = 0.0;
			foreach ( $bin['placed'] as $pp ) $base_right = max( $base_right, $pp['x'] + $pp['w'] );
			$strip_x = $base_right; $strip_w = $usable_w - $strip_x;
			if ( $strip_w > 1e-6 ) {
				for ( $j = $i + 1; $j < count( $items ); $j++ ) {
					if ( $used[ $j ] ) continue;
					$placed = $this->fit_block_in_strip( $items[ $j ]['bucket'], $items[ $j ]['qty'], $strip_w, $this->max_len );
					if ( $placed && $placed['count'] >= $items[ $j ]['qty'] ) {
						foreach ( $placed['placed'] as &$pc ) { $pc['x'] += $strip_x; }
						unset( $pc );
						$this->apply_block_to_bin( $bin, [ 'placed' => $placed['placed'], 'rows' => $placed['rows'] ] );
						$used[ $j ] = true;
						$base_right = 0.0;
						foreach ( $bin['placed'] as $pp ) $base_right = max( $base_right, $pp['x'] + $pp['w'] );
						$strip_x = $base_right; $strip_w = $usable_w - $strip_x;
						if ( $strip_w <= 1e-6 ) break;
					}
				}
			}

			$out[] = $bin;
		}

		return $out;
	}

	/**
	 * Lay a type's pieces as a clean block confined to a WIDTH strip (strip_w),
	 * spanning up to len_budget along the length. Tries both orientations and
	 * returns whichever places the most pieces (ties → shorter block).
	 * Coordinates are strip-local (x from 0); the caller shifts them to the
	 * strip's x-offset.
	 *
	 * @return array{placed:array,rows:array,len:float,count:int}|null
	 */
	private function fit_block_in_strip( array $b, int $qty, float $strip_w, float $len_budget ): ?array {
		$best = null;
		foreach ( [ false, true ] as $fr ) {
			if ( $fr && ( empty( $b['rotatable'] ) || abs( (float) $b['w'] - (float) $b['h'] ) < 1e-9 ) ) continue;
			$blk = $this->build_type_block( $b, $qty, $strip_w, 0.0, $len_budget, /*use_strip*/ false, $fr );
			if ( $blk['count'] < 1 ) continue;
			if ( $best === null
			     || $blk['count'] > $best['count']
			     || ( $blk['count'] === $best['count'] && $blk['len'] < $best['len'] ) ) {
				$best = $blk;
			}
		}
		return $best;
	}

	/**
	 * Reconstruct a (bucket, qty, len) spec from a single-type bin so its block
	 * can be re-laid at a different length offset during merging.
	 */
	private function bin_to_typespec( array $bin ): array {
		$label = ''; $kind = 'custom'; $qty = 0;
		$w = 0.0; $h = 0.0; $rotatable = true;
		// Infer the piece's own (unrotated) dims from the smallest primary piece.
		// All pieces in a single-type bin share dims (modulo rotation), so take
		// the first piece and normalize to (long,short) → (w,h) is irrelevant to
		// best_orientation, which re-derives from w/h.
		foreach ( $bin['placed'] as $p ) {
			$label = $p['label']; $kind = $p['kind'];
			$lo = min( $p['w'], $p['h'] ); $hi = max( $p['w'], $p['h'] );
			$w = $hi; $h = $lo; // canonical; best_orientation handles rotation
			$qty++;
		}
		$bucket = [ 'w' => $w, 'h' => $h, 'label' => $label, 'kind' => $kind,
		            'rotatable' => $rotatable, 'qty' => $qty, 'area' => $w * $h ];
		return [ 'bucket' => $bucket, 'qty' => $qty, 'len' => $bin['used_len'] ];
	}

	private function new_bin(): array {
		return [ 'placed' => [], 'rows' => [], 'types' => [], 'used_len' => 0.0 ];
	}

	/* ===================================================================
	 * SCORING — guillotine cut quality first (your rule: prefer cuts that run
	 * the ENTIRE length of the sheet in either direction; a cut that stops at a
	 * perpendicular cut — a T-junction — is bad), then waste, then sheet count.
	 *
	 * Priority bands (a higher band can never be traded for any amount of lower):
	 *   1. Fewest sheets        (W_SHEET)   — never open a roll pull for cosmetics
	 *   2. Fewest T-junctions   (W_TJUNCT)  — every interrupted cut is penalized
	 *   3. Fewest total cuts    (W_CUT)     — simpler is better
	 *   4. Least waste          (W_WASTE)   — tightest fill, the tie-breaker
	 * =================================================================== */
	private function score_layout( array $bins, float $usable_w ): float {
		$sheets    = count( $bins );
		$tjunct    = 0;
		$cuts      = 0;
		$waste     = 0.0;
		$against   = 0;   // pieces NOT laid long-side-across (shop dispreference)

		foreach ( $bins as $bin ) {
			$rects = [];
			foreach ( $bin['placed'] as $p ) {
				$rects[] = [ 'x' => $p['x'], 'y' => $p['y'], 'w' => $p['w'], 'h' => $p['h'] ];
				// "Across" is the x/width axis on the sheet. The shop prefers the
				// piece's LONG side to run across (longest contiguous cut). Count
				// pieces oriented the other way as a soft dispreference.
				if ( $p['w'] + 1e-6 < $p['h'] ) $against++;
			}
			$len = $bin['used_len'];
			list( $tj, $cc ) = $this->guillotine_analyze( $rects, 0.0, 0.0, $usable_w, $len );
			$tjunct += $tj;
			$cuts   += $cc;

			$area_used = 0.0;
			foreach ( $bin['placed'] as $p ) $area_used += $p['w'] * $p['h'];
			$waste += max( 0.0, $usable_w * $len - $area_used );
		}

		return $sheets  * self::W_SHEET
		     + $tjunct  * self::W_TJUNCT
		     + $waste   * self::W_WASTE
		     + $against * self::W_ORIENT
		     + $cuts    * self::W_CUT;
	}

	/**
	 * Recursive guillotine analysis of a set of axis-aligned rectangles within a
	 * region. A region is guillotine-separable if there is a FULL-SPAN cut
	 * (edge-to-edge, vertical or horizontal) that splits it into two non-empty
	 * sub-regions with NO rectangle straddling the cut line; we then recurse on
	 * each side. Each such cut runs the entire length/width of the (sub)region —
	 * exactly the "cut across the whole body" the shop wants.
	 *
	 * If no full-span cut exists but >1 rectangle remains, the remaining internal
	 * boundaries are T-JUNCTIONS (a cut would have to stop at a perpendicular
	 * one). We count the straddled boundaries as T-junctions — the penalty the
	 * scorer minimizes.
	 *
	 * @return array{0:int,1:int} [ t_junctions, cut_count ]
	 */
	private function guillotine_analyze( array $rects, float $rx, float $ry, float $rw, float $rh ): array {
		$n = count( $rects );
		if ( $n <= 1 ) return [ 0, 0 ];

		// Candidate vertical cut lines = right edges of rects strictly inside.
		$xs = [];
		foreach ( $rects as $r ) {
			$xe = $r['x'] + $r['w'];
			if ( $xe > $rx + 1e-6 && $xe < $rx + $rw - 1e-6 ) $xs[ (string) round( $xe, 4 ) ] = $xe;
		}
		foreach ( $xs as $cx ) {
			$straddle = false; $left = []; $right = [];
			foreach ( $rects as $r ) {
				if ( $r['x'] + $r['w'] <= $cx + 1e-6 )      $left[]  = $r;
				elseif ( $r['x'] >= $cx - 1e-6 )            $right[] = $r;
				else { $straddle = true; break; }
			}
			if ( ! $straddle && $left && $right ) {
				list( $tl, $cl ) = $this->guillotine_analyze( $left,  $rx, $ry, $cx - $rx, $rh );
				list( $tr, $cr ) = $this->guillotine_analyze( $right, $cx, $ry, $rx + $rw - $cx, $rh );
				return [ $tl + $tr, 1 + $cl + $cr ];
			}
		}

		// Candidate horizontal cut lines = bottom edges of rects strictly inside.
		$ys = [];
		foreach ( $rects as $r ) {
			$ye = $r['y'] + $r['h'];
			if ( $ye > $ry + 1e-6 && $ye < $ry + $rh - 1e-6 ) $ys[ (string) round( $ye, 4 ) ] = $ye;
		}
		foreach ( $ys as $cy ) {
			$straddle = false; $top = []; $bot = [];
			foreach ( $rects as $r ) {
				if ( $r['y'] + $r['h'] <= $cy + 1e-6 )      $top[] = $r;
				elseif ( $r['y'] >= $cy - 1e-6 )            $bot[] = $r;
				else { $straddle = true; break; }
			}
			if ( ! $straddle && $top && $bot ) {
				list( $tt, $ct ) = $this->guillotine_analyze( $top, $rx, $ry, $rw, $cy - $ry );
				list( $tb, $cb ) = $this->guillotine_analyze( $bot, $rx, $cy, $rw, $ry + $rh - $cy );
				return [ $tt + $tb, 1 + $ct + $cb ];
			}
		}

		// No full-span cut: the remaining pieces cannot be separated edge-to-edge.
		// Every pair that mutually blocks contributes a T-junction. Approximate
		// by (n-1) interrupted boundaries plus the cuts still needed.
		return [ $n - 1, $n - 1 ];
	}

	/* ===================================================================
	 * EMIT — convert a packed bin into the page record the app consumes.
	 * (Same shape as the v2.1.0 packer: pieces[], piece_count, used_len,
	 *  deliverables[].)
	 * =================================================================== */
	private function emit_page( array $bin, float $usable_w ): array {
		$pieces = [];
		$deliv  = [];
		foreach ( $bin['placed'] as $p ) {
			$pieces[] = [
				'x' => round( $p['x'], 3 ), 'y' => round( $p['y'], 3 ),
				'w' => round( $p['w'], 3 ), 'h' => round( $p['h'], 3 ),
				'label' => $p['label'], 'kind' => $p['kind'],
			];
			$key = $p['label'];
			if ( ! isset( $deliv[ $key ] ) ) $deliv[ $key ] = [ 'label' => $key, 'qty' => 0, 'kind' => $p['kind'] ];
			$deliv[ $key ]['qty']++;
		}
		return [
			'pieces'       => $pieces,
			'piece_count'  => count( $pieces ),
			'used_len'     => round( $bin['used_len'], 3 ),
			'deliverables' => array_values( $deliv ),
		];
	}
}

/* Seeded shuffle so trial sequences are reproducible across runs. */
if ( ! function_exists( 'shuffle_seeded' ) ) {
	function shuffle_seeded( array &$a ): void {
		for ( $i = count( $a ) - 1; $i > 0; $i-- ) {
			$j = mt_rand( 0, $i );
			$t = $a[ $i ]; $a[ $i ] = $a[ $j ]; $a[ $j ] = $t;
		}
	}
}
