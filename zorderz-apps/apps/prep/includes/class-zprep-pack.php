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
			'files'    => array( 'rules' ),
			'paths'    => array( 'rules' => array( 'prep' ) ),
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
		if ( ! is_array( $prep ) ) {
			return $out;
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
		);
		return $paths[ $opt ] ?? $opt;
	}

	public static function preview( array $files ) {
		$rows = array();
		foreach ( self::plan( $files ) as $opt => $val ) {
			$was = get_option( $opt, null );
			if ( $was !== $val ) {
				$rows[] = array(
					'file' => 'rules',
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
			if ( ! in_array( $opt, array( ZPREP_Upcoming::SHEETS_OPT, ZPREP_Upcoming::DAYS_OPT, ZPREP_Upcoming::AUDIENCE_OPT, ZPREP_Upcoming::HIDDEN_OPT ), true ) ) {
				continue;
			}
			null === $val ? delete_option( $opt ) : update_option( $opt, $val, false );
		}
		return array( __( 'Prep: prep sheets, working days and who sees Upcoming production put back.', 'zorderz' ) );
	}
}
