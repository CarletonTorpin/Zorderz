<?php
/**
 * Zorderz Prep Engine — deterministic PHP cut-optimization with 2-D nesting.
 *
 * The geometry is business-agnostic. Everything that would differ between businesses is
 * READ from configuration / the Item Engine, never hardcoded:
 *   - roll widths, colours, availability and per-foot costs  -> ZPREP_Settings::rolls()
 *   - fabrication workspace (table sizes, margins, sheet caps) -> ZPREP_Settings
 *   - a piece's human label + default size                   -> Item Engine (ZPREP_Settings)
 *   - whether a piece is CUT from stock or a pre-made deliverable -> Item Engine
 *     (`attributes.is_fabricated`), with a neutral geometry fallback.
 *
 * A "kind" is an Item Engine item id (from classification) or the parser's own free token
 * when the catalog is empty; the engine never interprets it, only labels it.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Engine {

	const SHAPE_RECTANGLE   = 'rectangle';
	const SHAPE_CIRCLE      = 'circle';
	const SHAPE_HALF_CIRCLE = 'half_circle';

	private float $grungy_margin;
	private float $min_saveable;
	private string $black_tiebreaker;

	private array $leftover_reservations = array();
	private array $plan_options          = array();
	private array $pack_debug            = array();

	public function __construct() {
		$this->grungy_margin    = ZPREP_Settings::grungy_margin_in();
		$this->min_saveable     = ZPREP_Settings::min_saveable_in();
		$this->black_tiebreaker = ZPREP_Settings::black_tiebreaker();
	}

	/* ================================================================
	 * PUBLIC — MAIN ENTRY POINT
	 * ================================================================ */
	public function compute_plan( array $measurements, array $options = array() ): array {
		$this->plan_options = array_merge(
			array(
				'use_leftovers' => false,
				'source_job'    => '',
				'workspace'     => 'flat',
				'force_roll'    => 0,
				'debug'         => false,
			),
			$options
		);
		$this->leftover_reservations = array();
		$this->pack_debug            = array();

		$normalized = array_map( array( $this, 'normalize_measurement' ), $measurements );

		if ( ! empty( $this->plan_options['use_leftovers'] ) && class_exists( 'ZPREP_Leftovers' ) ) {
			$normalized = $this->reserve_leftovers( $normalized );
		}

		$batches = $this->aggregate_batches( $normalized );

		$warnings     = array();
		$total_pieces = 0;

		foreach ( $batches as &$batch ) {
			$batch['plan'] = $this->plan_batch( $batch, $warnings );
			$total_pieces += (int) ( $batch['total_qty'] ?? 0 );
		}
		unset( $batch );

		// The 2-D packer is the single source of truth for the physical layout; sheet
		// count and material usage derive from the packed pages.
		$pages = $this->compute_nesting_pages( $batches );

		$total_sheets   = count( $pages );
		$linear_in_used = array(); // "{width}_{color}" => inches
		foreach ( $pages as $pg ) {
			$key = $pg['roll_width_in'] . '_' . $pg['color'];
			if ( ! isset( $linear_in_used[ $key ] ) ) {
				$linear_in_used[ $key ] = 0.0;
			}
			$linear_in_used[ $key ] += (float) $pg['sheet_length'];
		}

		$deliverables          = $this->build_deliverables( $batches );
		$leftover_deliverables = $this->build_leftover_deliverables();
		$material_cost         = $this->cost_summary( $linear_in_used );
		$leftover_savings      = $this->summarize_leftover_savings();

		if ( ! empty( $leftover_savings['summary_line'] ) ) {
			array_unshift( $warnings, $leftover_savings['summary_line'] );
		}

		$out = array(
			'summary'       => array(
				'total_pieces' => $total_pieces,
				'batch_count'  => count( $batches ),
				'total_sheets' => $total_sheets,
			),
			'batches'       => array_values( $batches ),
			'pages'         => $pages,
			'deliverables'  => $deliverables,
			'material_cost' => $material_cost,
			'warnings'      => $warnings,
		);

		if ( ! empty( $this->plan_options['debug'] ) ) {
			$out['debug'] = array(
				'version'    => ZPREP_VERSION,
				'workspace'  => $this->plan_options['workspace'],
				'force_roll' => (int) $this->plan_options['force_roll'],
				'groups'     => $this->pack_debug,
			);
		}

		if ( ! empty( $leftover_deliverables ) ) {
			$out['leftover_deliverables'] = $leftover_deliverables;
		}
		if ( ! empty( $this->leftover_reservations ) ) {
			$out['leftover_reservations'] = array_values( $this->leftover_reservations );
		}
		if ( ! empty( $leftover_savings['used_count'] ) ) {
			$out['leftover_savings'] = $leftover_savings;
		}

		return $out;
	}

	/* ================================================================
	 * NESTING PAGES (true 2-D bin packing)
	 * ================================================================ */
	public function compute_nesting_pages( array $batches ): array {
		if ( ! class_exists( 'ZPREP_Nesting' ) ) {
			require_once __DIR__ . '/class-zprep-nesting.php';
		}

		$groups = array();

		foreach ( $batches as $batch ) {
			$plan = $batch['plan'] ?? array();
			if ( empty( $plan['sheets'] ) ) {
				continue; // needs_dimensions / oversize / not-cut -> skip (warned elsewhere).
			}

			$color     = $batch['color'];
			$is_square = ( ( $batch['shape'] ?? '' ) === self::SHAPE_HALF_CIRCLE );

			list( $piece_w, $piece_h, $label ) = $this->piece_geometry_for_batch( $batch, $plan );
			if ( $piece_w <= 0 || $piece_h <= 0 ) {
				continue;
			}

			$roll_w   = $this->roll_for_group( $color, $piece_w, $piece_h );
			if ( $roll_w <= 0 ) {
				continue; // no available roll for this colour.
			}
			$roll_key = $roll_w . '_' . $color . ( $is_square ? '_sq' : '' );

			if ( ! isset( $groups[ $roll_key ] ) ) {
				$groups[ $roll_key ] = array(
					'roll_width_in' => $roll_w,
					'color'         => $color,
					'is_square'     => $is_square,
					'pieces'        => array(),
				);
			}

			$qty   = (int) ( $batch['total_qty'] ?? 0 );
			$count = $is_square ? (int) ceil( $qty / 2 ) : $qty;

			for ( $i = 0; $i < $count; $i++ ) {
				$groups[ $roll_key ]['pieces'][] = array(
					'w'         => $piece_w,
					'h'         => $piece_h,
					'label'     => $label,
					'kind'      => $batch['kind'] ?? 'custom',
					'color'     => $color,
					'rotatable' => ! $is_square,
				);
			}
		}

		$pages = array();
		foreach ( $groups as $group ) {
			$roll_w  = $group['roll_width_in'];
			$color   = $group['color'];
			$gm      = $this->grungy_margin;
			$max_len = ZPREP_Settings::max_page_length_in( (int) $roll_w );

			$packer = new ZPREP_Nesting( $roll_w, $gm, $max_len );
			$packed = $packer->pack( $group['pieces'] );

			$gd          = $packer->get_debug();
			$gd['group'] = $roll_w . '" ' . $color;
			$this->pack_debug[] = $gd;

			foreach ( $packed as $pp ) {
				$deliverables = array();
				foreach ( $pp['deliverables'] as $d ) {
					$deliverables[] = array(
						'label' => $this->kind_label_from_piece( $d, $group ),
						'qty'   => (int) $d['qty'],
						'dims'  => $this->dims_from_piece_label( $d['label'] ),
						'color' => $color,
					);
				}
				$pages[] = $this->emit_nesting_page( $roll_w, $color, $gm, (float) $pp['used_len'], $pp['pieces'], $deliverables );
			}
		}

		return array_values( $pages );
	}

	/**
	 * A single piece's cut geometry + display label. Half-circle pieces are cut as a
	 * square (each square yields two half-circles); the square side comes from the piece
	 * width, the item's default size, or a filterable default — never a fixed constant.
	 *
	 * @return array [ piece_w, piece_h, label ]
	 */
	private function piece_geometry_for_batch( array $batch, array $plan ): array {
		if ( ( $batch['shape'] ?? '' ) === self::SHAPE_HALF_CIRCLE ) {
			$sq = $this->half_square_dim( $batch );
			if ( ! empty( $plan['sheets'][0]['swath_width_in'] ) ) {
				$sq = (float) $plan['sheets'][0]['swath_width_in'];
			}
			return array( $sq, $sq, $this->fmt_in( $sq ) . '×' . $this->fmt_in( $sq ) );
		}
		$w     = (float) ( $batch['width_in'] ?? 0 );
		$h     = (float) ( $batch['height_in'] ?? 0 );
		$label = $this->fmt_in( $w ) . '×' . $this->fmt_in( $h );
		return array( $w, $h, $label );
	}

	/** The square side used to cut half-circle pieces (piece width / item default / filter). */
	private function half_square_dim( array $batch ): float {
		$w = (float) ( $batch['width_in'] ?? 0 );
		if ( $w > 0 ) {
			return $w;
		}
		$dims = ZPREP_Settings::default_dimensions_for( (string) ( $batch['kind'] ?? '' ) );
		if ( is_array( $dims ) && $dims['w'] > 0 ) {
			return (float) $dims['w'];
		}
		return (float) apply_filters( 'zprep_half_square_default_in', 24.0, $batch );
	}

	/** Choose the single roll width for a packing group: narrowest available that fits. */
	private function roll_for_group( string $color, float $piece_w, float $piece_h ): int {
		$candidates = $this->eligible_rolls( $color );
		if ( empty( $candidates ) ) {
			return 0;
		}
		$short = min( $piece_w, $piece_h );
		foreach ( $candidates as $roll_w ) {
			if ( $short <= ( $roll_w - $this->grungy_margin ) + 1e-6 ) {
				return (int) $roll_w;
			}
		}
		return (int) ( max( $candidates ) ?: 0 );
	}

	/** Map a packed-deliverable record back to a human kind label (from the Item Engine). */
	private function kind_label_from_piece( array $deliverable, array $group ): string {
		if ( ! empty( $group['is_square'] ) ) {
			return $this->kind_label( (string) ( $deliverable['kind'] ?? '' ), __( 'Half-round piece', 'zorderz' ) );
		}
		$kind = (string) ( $deliverable['kind'] ?? '' );
		return $this->kind_label( $kind, __( 'Piece', 'zorderz' ) );
	}

	/** Turn a "W×H" label into the bracketed dims string the UI shows. */
	private function dims_from_piece_label( string $label ): string {
		$parts = preg_split( '/\s*[x×]\s*/u', $label );
		if ( count( $parts ) === 2 ) {
			return $parts[0] . '" × ' . $parts[1] . '"';
		}
		return '';
	}

	/** Build one nesting page record from accumulated pieces. */
	private function emit_nesting_page( int $roll_w, string $color, float $gm, float $raw_length, array $pieces, array $deliverables ): array {
		$sheet_length = ceil( $raw_length * 2 ) / 2; // round up to nearest 0.5"
		$linear_ft    = $sheet_length / 12.0;
		$cost_per_ft  = ZPREP_Settings::roll_cost_per_ft( $roll_w, $color );
		$cost         = round( $linear_ft * $cost_per_ft, 2 );

		$usable_w        = $roll_w - $gm;
		$max_piece_right = 0;
		foreach ( $pieces as $p ) {
			$max_piece_right = max( $max_piece_right, $p['x'] + $p['w'] );
		}
		$leftover_strip    = round( max( 0, $usable_w - $max_piece_right ), 2 );
		$leftover_saveable = ( $leftover_strip >= $this->min_saveable && $sheet_length >= $this->min_saveable );

		return array(
			'roll_key'          => $roll_w . '_' . $color,
			'roll_width_in'     => $roll_w,
			'color'             => $color,
			'sheet_length'      => round( $sheet_length, 2 ),
			'sheet_length_in'   => round( $sheet_length, 2 ),
			'linear_feet'       => round( $linear_ft, 2 ),
			'cost'              => $cost,
			'pieces'            => $pieces,
			'piece_count'       => count( $pieces ),
			'deliverables'      => $deliverables,
			'leftover_strip'    => $leftover_strip,
			'leftover_in'       => $leftover_strip,
			'leftover_saveable' => $leftover_saveable,
			'grungy_margin'     => $gm,
		);
	}

	/* ================================================================
	 * RESERVE LEFTOVERS
	 * ================================================================ */
	private function reserve_leftovers( array $normalized ): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$out     = array();
		foreach ( $normalized as $m ) {
			if ( ( $m['shape'] ?? 'rectangle' ) === self::SHAPE_HALF_CIRCLE ) {
				$out[] = $m;
				continue;
			}
			$w = $m['width_in'] ?? null;
			$h = $m['height_in'] ?? null;
			if ( null === $w || null === $h || $m['qty'] <= 0 ) {
				$out[] = $m;
				continue;
			}
			$piece_short = min( (float) $w, (float) $h );
			$piece_long  = max( (float) $w, (float) $h );
			$remaining   = (int) $m['qty'];

			while ( $remaining > 0 ) {
				$cands = ZPREP_Leftovers::find_candidates( $m['color'], $piece_short, $piece_long );
				if ( empty( $cands ) ) {
					break;
				}
				$won = false;
				foreach ( $cands as $cand ) {
					if ( ZPREP_Leftovers::reserve( (int) $cand['id'], $user_id ) ) {
						$this->leftover_reservations[ (int) $cand['id'] ] = array(
							'leftover_id' => (int) $cand['id'],
							'material'    => $cand['material'],
							'width_in'    => (float) $cand['width_in'],
							'length_in'   => (float) $cand['length_in'],
							'source_job'  => (string) ( $cand['source_job'] ?? '' ),
							'covers'      => array(
								'kind'    => $m['kind'],
								'piece_w' => (float) $w,
								'piece_h' => (float) $h,
								'side'    => $m['side'] ?? '',
							),
						);
						--$remaining;
						$won = true;
						break;
					}
				}
				if ( ! $won ) {
					break;
				}
			}
			if ( $remaining < $m['qty'] ) {
				$m['qty'] = $remaining;
			}
			if ( $m['qty'] > 0 ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	private function build_leftover_deliverables(): array {
		if ( empty( $this->leftover_reservations ) ) {
			return array();
		}
		$out = array();
		foreach ( $this->leftover_reservations as $r ) {
			$label = $this->kind_label( (string) ( $r['covers']['kind'] ?? '' ), __( 'Piece', 'zorderz' ) );
			$key   = $label . '|leftover';
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'label' => $label, 'qty' => 0, 'from_leftover' => true, 'pieces' => array() );
			}
			++$out[ $key ]['qty'];
			$out[ $key ]['pieces'][] = array(
				'leftover_id' => $r['leftover_id'],
				'source_job'  => $r['source_job'],
				'width_in'    => $r['width_in'],
				'length_in'   => $r['length_in'],
				'piece_w'     => $r['covers']['piece_w'],
				'piece_h'     => $r['covers']['piece_h'],
			);
		}
		return array_values( $out );
	}

	private function summarize_leftover_savings(): array {
		if ( empty( $this->leftover_reservations ) ) {
			return array( 'used_count' => 0, 'by_material' => array(), 'summary_line' => '' );
		}
		$used_count  = count( $this->leftover_reservations );
		$by_material = array();
		foreach ( $this->leftover_reservations as $r ) {
			$mat        = $r['material'] ?? 'black';
			$feet_saved = ( max( $r['covers']['piece_w'], $r['covers']['piece_h'] ) ) / 12.0;
			if ( ! isset( $by_material[ $mat ] ) ) {
				$by_material[ $mat ] = array( 'count' => 0, 'feet_saved' => 0.0 );
			}
			++$by_material[ $mat ]['count'];
			$by_material[ $mat ]['feet_saved'] += $feet_saved;
		}
		$parts = array();
		foreach ( $by_material as $mat => $info ) {
			$parts[] = sprintf( '%.1f ft of %s', $info['feet_saved'], $mat );
		}
		return array(
			'used_count'   => $used_count,
			'by_material'  => $by_material,
			'summary_line' => sprintf(
				/* translators: 1: count, 2: plural s, 3: material breakdown. */
				__( 'Used %1$d leftover piece%2$s (saved %3$s).', 'zorderz' ),
				$used_count,
				1 === $used_count ? '' : 's',
				implode( ' + ', $parts )
			),
		);
	}

	/* ================================================================
	 * NORMALIZE
	 * ================================================================ */
	private function normalize_measurement( array $m ): array {
		$kind  = (string) ( $m['kind'] ?? $m['vent_type'] ?? 'custom' ); // accept legacy key on input.
		$qty   = max( 0, (int) ( $m['qty'] ?? 0 ) );
		$w     = ( isset( $m['width_in'] ) && '' !== $m['width_in'] && null !== $m['width_in'] ) ? (float) $m['width_in'] : null;
		$h     = ( isset( $m['height_in'] ) && '' !== $m['height_in'] && null !== $m['height_in'] ) ? (float) $m['height_in'] : null;
		$shape = $m['shape'] ?? self::SHAPE_RECTANGLE;
		$valid_colors = ZPREP_Settings::roll_colors();
		$color = strtolower( (string) ( $m['color'] ?? '' ) );
		if ( ! in_array( $color, $valid_colors, true ) ) {
			$color = ZPREP_Settings::default_color();
		}
		$side = (string) ( $m['side'] ?? '' );
		$ci   = ! empty( $m['customer_install'] );

		if ( self::SHAPE_CIRCLE === $shape && $w && ! $h ) {
			$h = $w;
		}
		if ( self::SHAPE_CIRCLE === $shape && ! $w && $h ) {
			$w = $h;
		}

		// A sizeless kind may carry Item-Engine default dimensions (installer trims
		// on-site); NEUTRAL fallback leaves it null so plan_batch flags needs-dimensions.
		if ( self::SHAPE_HALF_CIRCLE !== $shape && self::SHAPE_CIRCLE !== $shape && null === $w && null === $h ) {
			$dims = ZPREP_Settings::default_dimensions_for( $kind );
			if ( is_array( $dims ) ) {
				$w = (float) $dims['w'];
				$h = (float) $dims['h'];
			}
		}

		return array(
			'kind'             => $kind,
			'qty'              => $qty,
			'width_in'         => $w,
			'height_in'        => $h,
			'shape'            => $shape,
			'color'            => $color,
			'side'             => $side,
			'source_line'      => (string) ( $m['source_line'] ?? '' ),
			'customer_install' => $ci,
			'notes'            => (string) ( $m['notes'] ?? '' ),
		);
	}

	/* ================================================================
	 * AGGREGATE
	 * ================================================================ */
	private function aggregate_batches( array $measurements ): array {
		$out = array();
		foreach ( $measurements as $m ) {
			if ( $m['qty'] <= 0 ) {
				continue;
			}
			$key = implode(
				'|',
				array(
					$m['kind'],
					$m['shape'],
					$m['color'],
					null !== $m['width_in'] ? round( $m['width_in'], 2 ) : 'null',
					null !== $m['height_in'] ? round( $m['height_in'], 2 ) : 'null',
					$m['customer_install'] ? 'ci' : 'std',
				)
			);
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'key'              => $key,
					'kind'             => $m['kind'],
					'shape'            => $m['shape'],
					'color'            => $m['color'],
					'width_in'         => $m['width_in'],
					'height_in'        => $m['height_in'],
					'customer_install' => $m['customer_install'],
					'total_qty'        => 0,
					'sides'            => array(),
					'source_lines'     => array(),
					'notes'            => array(),
				);
			}
			$out[ $key ]['total_qty'] += $m['qty'];
			$side_label                 = '' !== $m['side'] ? 'Side ' . $m['side'] : __( '(unspecified side)', 'zorderz' );
			$out[ $key ]['sides'][ $side_label ] = ( $out[ $key ]['sides'][ $side_label ] ?? 0 ) + $m['qty'];
			if ( '' !== $m['source_line'] ) {
				$out[ $key ]['source_lines'][] = $m['source_line'];
			}
			if ( '' !== $m['notes'] ) {
				$out[ $key ]['notes'][] = $m['notes'];
			}
		}
		return array_values( $out );
	}

	/* ================================================================
	 * PLAN A SINGLE BATCH
	 * ================================================================ */
	private function plan_batch( array $batch, array &$warnings ): array {
		$shape = $batch['shape'];
		$kind  = (string) ( $batch['kind'] ?? '' );

		// PRE-MADE deliverables are installed as-is, not cut from stock. Whether a piece
		// is fabricated comes from the Item Engine (`attributes.is_fabricated`); the
		// neutral fallback treats a round piece as pre-made. Such a batch is surfaced as
		// a deliverable but excluded from packing.
		if ( ! ZPREP_Settings::is_cuttable_piece( $kind, $shape ) ) {
			ZPREP_Settings::disposition( 'piece_not_cut', array( 'kind' => $kind, 'shape' => $shape, 'qty' => (int) $batch['total_qty'] ) );
			return array( 'needs_dimensions' => false, 'not_cut' => true, 'sheets' => array() );
		}

		if ( ( null === $batch['width_in'] || null === $batch['height_in'] ) && self::SHAPE_HALF_CIRCLE !== $shape ) {
			$warnings[] = sprintf(
				/* translators: 1: piece label, 2: quantity. */
				__( '"%1$s" batch needs dimensions before it can be planned (qty %2$d).', 'zorderz' ),
				$this->kind_label( $kind, __( 'Piece', 'zorderz' ) ),
				$batch['total_qty']
			);
			ZPREP_Settings::disposition( 'needs_dimensions', array( 'kind' => $kind, 'qty' => (int) $batch['total_qty'] ) );
			return array( 'needs_dimensions' => true, 'sheets' => array() );
		}
		if ( self::SHAPE_HALF_CIRCLE === $shape ) {
			return $this->plan_half_domes( $batch );
		}
		return $this->plan_rectangle_batch( $batch );
	}

	private function plan_rectangle_batch( array $batch ): array {
		$w               = (float) $batch['width_in'];
		$h               = (float) $batch['height_in'];
		$qty             = (int) $batch['total_qty'];
		$color           = $batch['color'];
		$candidate_rolls = $this->eligible_rolls( $color );
		$max_len         = ZPREP_Settings::max_sheet_length_in();

		if ( empty( $candidate_rolls ) ) {
			return array( 'needs_dimensions' => false, 'needs_oversize' => true, 'sheets' => array(), 'message' => __( 'No roll material is configured for this colour.', 'zorderz' ) );
		}

		$best = null;
		foreach ( $candidate_rolls as $roll_w ) {
			foreach ( array( array( $w, $h, 'short-across' ), array( $h, $w, 'long-across' ) ) as $orient ) {
				list( $across, $along, $orient_label ) = $orient;
				$usable_w                               = $roll_w - $this->grungy_margin;
				if ( $across > $usable_w || $usable_w <= 0 ) {
					continue;
				}
				$per_sheet_row = (int) floor( $usable_w / $across );
				if ( $per_sheet_row <= 0 ) {
					continue;
				}

				$max_rows_per_sheet = max( 1, (int) floor( $max_len / $along ) );
				$rows_needed        = (int) ceil( $qty / $per_sheet_row );
				$num_sheets         = (int) ceil( $rows_needed / $max_rows_per_sheet );
				$sheet_length       = min( $rows_needed, $max_rows_per_sheet ) * $along;
				$pieces_fit         = $rows_needed * $per_sheet_row;
				$extras             = $pieces_fit - $qty;
				$linear_ft          = ( $rows_needed * $along ) / 12.0;

				$score = array(
					'roll_w'             => $roll_w,
					'orient'             => $orient_label,
					'per_row'            => $per_sheet_row,
					'rows'               => $rows_needed,
					'max_rows_per_sheet' => $max_rows_per_sheet,
					'num_sheets'         => $num_sheets,
					'sheet_length'       => $sheet_length,
					'extras'             => $extras,
					'linear_ft'          => $linear_ft,
					'across'             => $across,
					'along'              => $along,
				);
				if ( null === $best || $this->beats( $score, $best, $color ) ) {
					$best = $score;
				}
			}
		}

		if ( null === $best ) {
			return array( 'needs_dimensions' => false, 'needs_oversize' => true, 'sheets' => array(), 'message' => __( 'Piece is too large for every available roll.', 'zorderz' ) );
		}

		$sheets    = array();
		$remaining = $qty;
		$max_rows  = $best['max_rows_per_sheet'];
		$per_row   = $best['per_row'];
		$along     = $best['along'];

		while ( $remaining > 0 ) {
			$rows_this   = min( $max_rows, (int) ceil( $remaining / $per_row ) );
			$pieces_this = min( $remaining, $rows_this * $per_row );
			$len_this    = $rows_this * $along;

			$sub_batch              = $batch;
			$sub_batch['total_qty'] = $pieces_this;

			$sheets[]   = $this->build_sheet_entry( (int) $best['roll_w'], $best['across'], $len_this, $color, $per_row, $rows_this, $along, $sub_batch );
			$remaining -= $pieces_this;
		}

		return array( 'needs_dimensions' => false, 'sheets' => $sheets );
	}

	private function beats( array $a, array $b, string $color ): bool {
		if ( $a['per_row'] !== $b['per_row'] ) {
			return $a['per_row'] > $b['per_row'];
		}
		if ( $a['rows'] !== $b['rows'] ) {
			return $a['rows'] < $b['rows'];
		}
		if ( $a['extras'] !== $b['extras'] ) {
			return $a['extras'] < $b['extras'];
		}
		// Colour-specific tiebreaker: minimize linear feet, else prefer the smaller roll.
		if ( 'shortest_length' === $this->black_tiebreaker ) {
			return $a['linear_ft'] < $b['linear_ft'];
		}
		return $a['roll_w'] < $b['roll_w'];
	}

	private function plan_half_domes( array $batch ): array {
		$qty             = (int) $batch['total_qty'];
		$color           = $batch['color'];
		$squares_needed  = (int) ceil( $qty / 2 );
		$square_dim      = $this->half_square_dim( $batch );

		$candidates = $this->eligible_rolls( $color );
		if ( empty( $candidates ) ) {
			return array( 'needs_dimensions' => false, 'needs_oversize' => true, 'sheets' => array(), 'message' => __( 'No roll material is configured for this colour.', 'zorderz' ) );
		}
		// Narrowest roll that fits the square, else the widest available.
		$roll_w = 0;
		foreach ( $candidates as $cw ) {
			if ( $square_dim <= ( $cw - $this->grungy_margin ) + 1e-6 ) {
				$roll_w = (int) $cw;
				break;
			}
		}
		if ( $roll_w <= 0 ) {
			$roll_w = (int) max( $candidates );
		}

		$usable_w = $roll_w - $this->grungy_margin;
		$per_row  = max( 1, (int) floor( $usable_w / $square_dim ) );
		$max_len  = ZPREP_Settings::max_sheet_length_in();
		$max_rows = max( 1, (int) floor( $max_len / $square_dim ) );

		$sheets    = array();
		$remaining = $squares_needed;
		while ( $remaining > 0 ) {
			$rows_this    = min( $max_rows, (int) ceil( $remaining / $per_row ) );
			$squares_this = min( $remaining, $rows_this * $per_row );
			$length_this  = $rows_this * $square_dim;

			$sheets[]   = array(
				'label'              => sprintf( 'Sheet — %s"×%s" — %s', $this->fmt_in( $square_dim ), $this->fmt_in( $length_this ), ucfirst( $color ) ),
				'roll_width_in'      => $roll_w,
				'color'              => $color,
				'swath_width_in'     => $square_dim,
				'sheet_length_in'    => $length_this,
				'perpendicular_cuts' => $rows_this - 1,
				'cut_interval_in'    => $square_dim,
				'pieces_per_row'     => $per_row,
				'rows'               => $rows_this,
				'pieces_produced'    => $squares_this,
				'kind_label'         => $this->kind_label( (string) ( $batch['kind'] ?? '' ), __( 'Half-round piece', 'zorderz' ) ),
				'instructions'       => sprintf(
					/* translators: 1: count, 2: dim, 3: dim, 4: colour. */
					__( 'Cut %1$d square(s) of %2$s"×%3$s" in %4$s. Each becomes 2 half-round pieces.', 'zorderz' ),
					$squares_this,
					$this->fmt_in( $square_dim ),
					$this->fmt_in( $square_dim ),
					$color
				),
				'leftover_in'        => 0.0,
				'leftover_saveable'  => false,
				'customer_install'   => ! empty( $batch['customer_install'] ),
				'sides_breakdown'    => $batch['sides'] ?? array(),
				'source_lines'       => $batch['source_lines'] ?? array(),
				'notes'              => $batch['notes'] ?? array(),
				'extras'             => 0,
			);
			$remaining -= $squares_this;
		}

		return array( 'needs_dimensions' => false, 'sheets' => $sheets );
	}

	private function build_sheet_entry( int $roll_w_used, float $swath_w, float $sheet_len, string $color, int $per_row, int $rows, float $cut_interval, array $batch ): array {
		$pieces_produced = $per_row * $rows;
		$extras          = $pieces_produced - (int) $batch['total_qty'];

		$usable_w              = $roll_w_used - $this->grungy_margin;
		$leftover_strip_across = max( 0.0, $usable_w - $per_row * $swath_w );
		$leftover_saveable     = ( $leftover_strip_across >= $this->min_saveable && $sheet_len >= $this->min_saveable );

		$label      = $this->kind_label( (string) $batch['kind'], __( 'Piece', 'zorderz' ) );
		$piece_desc = sprintf( '%s"×%s" %s', $this->fmt_in( $swath_w ), $this->fmt_in( $cut_interval ), $label );

		$instruction = sprintf(
			/* translators: 1: swath, 2: sheet length, 3: colour, 4: cuts, 5: rows, 6: pieces, 7: piece desc, 8: extras note. */
			__( 'Cut one sheet of %1$s"×%2$s" in %3$s. %4$d perpendicular cut(s), %5$d row(s). Yields %6$d piece(s) of %7$s.%8$s', 'zorderz' ),
			$this->fmt_in( $swath_w ),
			$this->fmt_in( $sheet_len ),
			$color,
			max( 0, $per_row - 1 ),
			$rows,
			$pieces_produced,
			$piece_desc,
			$extras > 0 ? sprintf( ' (%d extra)', $extras ) : ''
		);

		return array(
			'label'                 => sprintf( 'Sheet — %s"×%s" — %s', $this->fmt_in( $swath_w ), $this->fmt_in( $sheet_len ), ucfirst( $color ) ),
			'roll_width_in'         => $roll_w_used,
			'color'                 => $color,
			'swath_width_in'        => $swath_w,
			'sheet_length_in'       => $sheet_len,
			'perpendicular_cuts'    => max( 0, $per_row - 1 ),
			'length_cuts'           => max( 0, $rows - 1 ),
			'cut_interval_in'       => $cut_interval,
			'cross_cut_interval_in' => $swath_w,
			'pieces_per_row'        => $per_row,
			'rows'                  => $rows,
			'pieces_produced'       => $pieces_produced,
			'kind_label'            => $label,
			'instructions'          => $instruction,
			'leftover_in'           => $leftover_strip_across,
			'leftover_saveable'     => $leftover_saveable,
			'customer_install'      => ! empty( $batch['customer_install'] ),
			'sides_breakdown'       => $batch['sides'] ?? array(),
			'source_lines'          => $batch['source_lines'] ?? array(),
			'notes'                 => $batch['notes'] ?? array(),
			'extras'                => $extras,
		);
	}

	/* ================================================================
	 * DELIVERABLES
	 * ================================================================ */
	private function build_deliverables( array $batches ): array {
		$out = array();
		foreach ( $batches as $b ) {
			$not_cut = ! empty( $b['plan']['not_cut'] );
			$label   = $this->kind_label( (string) ( $b['kind'] ?? '' ), __( 'Piece', 'zorderz' ) );
			$key     = $label . ( $b['customer_install'] ? ' [ci]' : '' ) . ( $not_cut ? ' [premade]' : '' );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'label'            => $label,
					'customer_install' => (bool) $b['customer_install'],
					'not_cut'          => $not_cut,
					'not_cut_reason'   => $not_cut ? __( 'Pre-made unit — included with the order, not cut from roll stock.', 'zorderz' ) : '',
					'qty'              => 0,
					'dimensions'       => array(),
					'color_mix'        => array(),
				);
			}
			$out[ $key ]['qty'] += (int) $b['total_qty'];
			if ( null !== $b['width_in'] && null !== $b['height_in'] ) {
				$dim                                  = sprintf( '%s" × %s"', $this->fmt_in( $b['width_in'] ), $this->fmt_in( $b['height_in'] ) );
				$out[ $key ]['dimensions'][ $dim ]    = ( $out[ $key ]['dimensions'][ $dim ] ?? 0 ) + $b['total_qty'];
			}
			$out[ $key ]['color_mix'][ $b['color'] ] = ( $out[ $key ]['color_mix'][ $b['color'] ] ?? 0 ) + $b['total_qty'];
		}
		return array_values( $out );
	}

	/* ================================================================
	 * COST — derived from the (width,color) roll costs (empty by default).
	 * ================================================================ */
	private function cost_summary( array $linear_in_used ): array {
		$rolls = array();
		$total = 0.0;
		foreach ( $linear_in_used as $key => $inches ) {
			if ( $inches <= 0 ) {
				continue;
			}
			$parts = explode( '_', $key, 2 );
			$width = (int) ( $parts[0] ?? 0 );
			$color = (string) ( $parts[1] ?? '' );
			$ft    = $inches / 12.0;
			$cost  = round( $ft * ZPREP_Settings::roll_cost_per_ft( $width, $color ), 2 );
			$rolls[] = array(
				'label' => sprintf( '%d" %s', $width, ucfirst( $color ) ),
				'feet'  => round( $ft, 2 ),
				'cost'  => $cost,
			);
			$total += $cost;
		}
		return array( 'rolls' => $rolls, 'total' => round( $total, 2 ) );
	}

	/* ================================================================
	 * ROLL SELECTION
	 * ================================================================ */

	/**
	 * The eligible roll widths for a colour, honouring the manual override and workspace.
	 * All widths/availability come from ZPREP_Settings::rolls() — nothing hardcoded.
	 *
	 * @return int[] Widths in preference order (empty if the colour has no roll).
	 */
	private function eligible_rolls( string $color ): array {
		$widths = ZPREP_Settings::roll_widths_for_color( $color );
		if ( empty( $widths ) ) {
			return array();
		}
		sort( $widths ); // ascending

		$force = (int) ( $this->plan_options['force_roll'] ?? 0 );
		if ( $force > 0 ) {
			if ( in_array( $force, $widths, true ) ) {
				return array( $force );
			}
			// Forced width unavailable for this colour (e.g. "force widest" for a colour
			// that only has the narrow roll) -> degrade to what exists.
			return $widths;
		}

		$ws = $this->plan_options['workspace'] ?? 'flat';
		if ( 'roller' === $ws ) {
			rsort( $widths ); // prefer the widest on the roller table.
			return $widths;
		}
		// Flat table: prefer the narrowest (most manageable) sheet.
		return array( $widths[0] );
	}

	/* ================================================================
	 * UTILITY
	 * ================================================================ */
	public function fmt_in( float $in ): string {
		if ( abs( $in - round( $in ) ) < 0.001 ) {
			return (string) (int) round( $in );
		}
		return rtrim( rtrim( number_format( $in, 2, '.', '' ), '0' ), '.' );
	}

	/** Human label for a kind, from the Item Engine, with a neutral fallback. */
	private function kind_label( string $kind, string $fallback = '' ): string {
		$kind = trim( $kind );
		if ( '' === $kind || 'custom' === $kind ) {
			return '' !== $fallback ? $fallback : ZPREP_Settings::kind_label( $kind );
		}
		return ZPREP_Settings::kind_label( $kind );
	}
}
