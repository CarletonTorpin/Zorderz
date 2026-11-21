<?php
/**
 * ZPREP_Pack — what Prep reads from an Identity Pack.
 *
 *   rules.yml  prep.sheets         the prep sheets: which kinds of work each is for, the words that pick it, how many
 *                                  working days before the install it must be ready, how many the shop prepares a day,
 *                                  the steps printed on it (ZPREP_Upcoming)
 *              prep.working_days   the weekdays the shop works (1 = Monday)
 *              prep.upcoming_audience      'prep' (everyone who can open Prep) or 'admin'
 *              prep.upcoming_hidden_roles  role slugs never shown Upcoming production
 *              prep.side_convention        how the business numbers a building's sides, one line the cut-list reader is told
 *                                  ("Side 1 faces the street; the rest go clockwise"); empty keeps Core's
 *              prep.internal_orders        what the shop may cut for itself: purposes [{ key, label, code }] (the code is
 *                                  the middle of an order's reference code) and default_sheet { w, l } in inches (the
 *                                  stock sheet the form starts from)
 *   costs.yml  prep.material_cost_per_sq_in  what the cut material costs per square inch: one number, or a number per
 *                                  roll colour (the year-end page's material cost)
 *
 * Registered on the Identity Pack's consumer registry. Each value is checked the way its reader checks it; revert puts
 * each option back exactly as it was, including "not set at all".
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Pack {

	public static function register( $consumers ) {
		$consumers         = is_array( $consumers ) ? $consumers : array();
		$consumers['prep'] = array(
			'label'    => __( 'Prep', 'zorderz' ),
			'files'    => array( 'rules', 'costs' ),
			'paths'    => array( 'rules' => array( 'prep' ), 'costs' => array( 'prep' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	/** option => value the pack would write. PURE apart from the sheet check. */
	public static function plan( array $files ): array {
		$prep = $files['rules']['prep'] ?? null;
		$out  = array();
		$cost = is_array( $files['costs']['prep'] ?? null ) ? ( $files['costs']['prep']['material_cost_per_sq_in'] ?? null ) : null;
		if ( null !== $cost ) {
			$out[ ZPREP_Settings::COST_RATES_OPT ] = ZPREP_Settings::clean_cost_rates( $cost );
		}
		if ( ! is_array( $prep ) ) {
			return $out;
		}
		if ( isset( $prep['internal_orders'] ) && is_array( $prep['internal_orders'] ) ) {
			$out[ ZPREP_Settings::INTERNAL_OPT ] = array(
				'purposes'      => ZPREP_Settings::clean_internal_purposes( $prep['internal_orders']['purposes'] ?? array() ),
				'default_sheet' => ZPREP_Settings::clean_sheet( $prep['internal_orders']['default_sheet'] ?? null ),
			);
		}
		if ( isset( $prep['sheets'] ) ) {
			$sheets = array();
			foreach ( (array) $prep['sheets'] as $s ) {
				$c = ZPREP_Upcoming::clean_sheet( $s );
				if ( $c ) {
					$sheets[] = $c;
				}
			}
			$out[ ZPREP_Upcoming::SHEETS_OPT ] = $sheets;
		}
		if ( isset( $prep['working_days'] ) ) {
			$out[ ZPREP_Upcoming::DAYS_OPT ] = array_values( array_unique( array_filter( array_map( 'intval', (array) $prep['working_days'] ), static function ( $d ) {
				return $d >= 1 && $d <= 7;
			} ) ) );
		}
		if ( isset( $prep['upcoming_audience'] ) ) {
			$out[ ZPREP_Upcoming::AUDIENCE_OPT ] = ZPREP_Upcoming::audience( (string) $prep['upcoming_audience'] );
		}
		if ( isset( $prep['side_convention'] ) ) {
			$out[ ZPREP_Settings::SIDES_OPT ] = ZPREP_Settings::clean_side_convention( $prep['side_convention'] );
		}
		if ( isset( $prep['upcoming_hidden_roles'] ) ) {
			$out[ ZPREP_Upcoming::HIDDEN_OPT ] = ZPREP_Upcoming::hidden_roles( (array) $prep['upcoming_hidden_roles'] );
		}
		return $out;
	}

	/** The pack path an option comes from. */
	private static function path_of( string $opt ): string {
		$paths = array(
			ZPREP_Upcoming::SHEETS_OPT   => 'prep.sheets',
			ZPREP_Upcoming::DAYS_OPT     => 'prep.working_days',
			ZPREP_Upcoming::AUDIENCE_OPT => 'prep.upcoming_audience',
			ZPREP_Upcoming::HIDDEN_OPT   => 'prep.upcoming_hidden_roles',
			ZPREP_Settings::INTERNAL_OPT => 'prep.internal_orders',
			ZPREP_Settings::COST_RATES_OPT => 'prep.material_cost_per_sq_in',
			ZPREP_Settings::SIDES_OPT      => 'prep.side_convention',
		);
		return $paths[ $opt ] ?? $opt;
	}

	/** The pack file an option comes from. */
	private static function file_of( string $opt ): string {
		return ZPREP_Settings::COST_RATES_OPT === $opt ? 'costs' : 'rules';
	}

	/** Every option this consumer may write, and so may put back. */
	private static function options(): array {
		return array( ZPREP_Upcoming::SHEETS_OPT, ZPREP_Upcoming::DAYS_OPT, ZPREP_Upcoming::AUDIENCE_OPT, ZPREP_Upcoming::HIDDEN_OPT, ZPREP_Settings::INTERNAL_OPT, ZPREP_Settings::COST_RATES_OPT, ZPREP_Settings::SIDES_OPT );
	}

	public static function preview( array $files ) {
		$rows = array();
		foreach ( self::plan( $files ) as $opt => $val ) {
			$was = get_option( $opt, null );
			if ( $was !== $val ) {
				$rows[] = array(
					'file' => self::file_of( $opt ),
					'path' => self::path_of( $opt ),
					'from' => null === $was ? __( '(none)', 'zorderz' ) : wp_json_encode( $was ),
					'to'   => wp_json_encode( $val ),
				);
			}
		}
		return $rows;
	}

	public static function snapshot( array $files = array(), $slug = '' ) {
		$keep = array();
		foreach ( array_keys( self::plan( $files ) ) as $opt ) {
			$keep[ $opt ] = get_option( $opt, null );
		}
		return $keep;
	}

	public static function apply( array $files, $slug = '' ) {
		$msgs = array();
		foreach ( self::plan( $files ) as $opt => $val ) {
			update_option( $opt, $val, false );
			$msgs[] = ZPREP_Upcoming::SHEETS_OPT === $opt
				/* translators: %d: sheets. */
				? sprintf( __( 'Prep: %d prep sheet(s) applied.', 'zorderz' ), count( $val ) )
				/* translators: %s: a pack path. */
				: sprintf( __( 'Prep: %s applied.', 'zorderz' ), self::path_of( $opt ) );
		}
		return $msgs;
	}

	public static function restore( $kept ) {
		foreach ( is_array( $kept ) ? $kept : array() as $opt => $val ) {
			if ( ! in_array( $opt, self::options(), true ) ) {
				continue;
			}
			null === $val ? delete_option( $opt ) : update_option( $opt, $val, false );
		}
		return array( __( 'Prep: prep sheets, working days, who sees Upcoming production, internal orders, the material cost and the side numbering put back.', 'zorderz' ) );
	}
}
