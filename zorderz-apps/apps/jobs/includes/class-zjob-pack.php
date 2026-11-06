<?php
/**
 * ZJOB_Pack — what the jobs app reads from an Identity Pack.
 *
 *   mappings.yml  order_forms.<key>      the business's supplier order forms (fields and paper maps). Blanks are not
 *                                        carried in a pack: they are uploaded in Settings → Zorderz Jobs, and a form
 *                                        prints once its blank is there and matches the map's pin.
 *   flows.yml     steps.order.kinds      the kinds of work that need materials ordered (My next opens the step)
 *                 steps.cut.kinds        the kinds of work prepared in the shop
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`). Every value is checked the way
 * the setting's own reader checks it; a value that fails is refused and listed. Revert puts each option back exactly
 * as it was, including "not set at all".
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Pack {

	public static function register( $consumers ) {
		$consumers         = is_array( $consumers ) ? $consumers : array();
		$consumers['jobs'] = array(
			'label'    => __( 'Jobs', 'zorderz' ),
			'files'    => array( 'mappings', 'flows' ),
			'paths'    => array( 'mappings' => array( 'order_forms' ), 'flows' => array( 'steps' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	/**
	 * What the pack would write: option => value, plus what was refused. PURE apart from the form check.
	 *
	 * @return array{write:array,refused:array}
	 */
	public static function plan( array $files ): array {
		$out   = array( 'write' => array(), 'refused' => array() );
		$forms = $files['mappings']['order_forms'] ?? null;
		if ( is_array( $forms ) ) {
			$keep = array();
			foreach ( $forms as $k => $f ) {
				$key = sanitize_key( (string) $k );
				if ( '' === $key || 'materials' === $key ) {
					$out['refused'][] = array( 'path' => 'mappings.order_forms.' . $k, 'why' => __( 'a form needs its own key, other than "materials"', 'zorderz' ) );
					continue;
				}
				if ( is_array( $f ) && isset( $f['paper'] ) && is_array( $f['paper'] ) ) {
					foreach ( array( 'pdf', 'xlsx' ) as $kind ) {
						if ( isset( $f['paper'][ $kind ] ) && is_array( $f['paper'][ $kind ] ) ) {
							$f['paper'][ $kind ]['where'] = 'site'; // a pack never points at Core's own files
						}
					}
				}
				if ( null === ZJOB_Project_Orders::clean_form( $f ) ) {
					$out['refused'][] = array( 'path' => 'mappings.order_forms.' . $key, 'why' => __( 'not a form: it needs at least one field with a key and a label', 'zorderz' ) );
					continue;
				}
				$keep[ $key ] = $f;
			}
			$out['write'][ ZJOB_Project_Orders::OPT_FORMS ] = $keep;
		}
		$steps = $files['flows']['steps'] ?? null;
		if ( is_array( $steps ) ) {
			$rules = array();
			foreach ( array( 'order', 'cut' ) as $s ) {
				if ( isset( $steps[ $s ]['kinds'] ) ) {
					$rules[ $s ] = array( 'kinds' => array_values( array_filter( array_map( static function ( $k ) {
						return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
					}, (array) $steps[ $s ]['kinds'] ) ) ) );
				}
			}
			if ( $rules ) {
				$out['write'][ ZJOB_My_Next::RULES_OPTION ] = $rules;
			}
		}
		return $out;
	}

	public static function preview( array $files ) {
		$plan = self::plan( $files );
		$rows = array();
		foreach ( $plan['write'] as $opt => $val ) {
			$was = get_option( $opt, null );
			if ( $was === $val ) {
				continue;
			}
			$file   = ZJOB_Project_Orders::OPT_FORMS === $opt ? 'mappings' : 'flows';
			$rows[] = array(
				'file' => $file,
				'path' => ZJOB_Project_Orders::OPT_FORMS === $opt ? 'order_forms' : 'steps',
				'from' => null === $was ? __( '(none)', 'zorderz' ) : self::words( $opt, $was ),
				'to'   => self::words( $opt, $val ),
			);
		}
		foreach ( $plan['refused'] as $r ) {
			$rows[] = array( 'file' => strtok( $r['path'], '.' ), 'path' => $r['path'], 'from' => '', 'to' => __( 'refused: ', 'zorderz' ) . $r['why'] );
		}
		return $rows;
	}

	private static function words( string $opt, $v ): string {
		if ( ZJOB_Project_Orders::OPT_FORMS === $opt ) {
			$bits = array();
			foreach ( (array) $v as $k => $f ) {
				$bits[] = $k . ( empty( $f['paper'] ) ? '' : ' (' . __( 'with paper', 'zorderz' ) . ')' );
			}
			return $bits ? implode( ', ', $bits ) : __( 'no forms', 'zorderz' );
		}
		$bits = array();
		foreach ( (array) $v as $s => $r ) {
			$bits[] = $s . ': ' . implode( ', ', (array) ( $r['kinds'] ?? array() ) );
		}
		return implode( '; ', $bits );
	}

	public static function snapshot( array $files = array(), $slug = '' ) {
		$keep = array();
		foreach ( array_keys( self::plan( $files )['write'] ) as $opt ) {
			$keep[ $opt ] = get_option( $opt, null );
		}
		return $keep;
	}

	public static function apply( array $files, $slug = '' ) {
		$plan = self::plan( $files );
		$msgs = array();
		foreach ( $plan['write'] as $opt => $val ) {
			update_option( $opt, $val, false );
			$msgs[] = ZJOB_Project_Orders::OPT_FORMS === $opt
				/* translators: %d: forms. */
				? sprintf( __( 'Jobs: %d supplier order form(s) applied. Upload each form\'s blank in Settings, Zorderz Jobs.', 'zorderz' ), count( (array) $val ) )
				: __( 'Jobs: the kinds of work that open an order or a shop prep step applied.', 'zorderz' );
		}
		foreach ( $plan['refused'] as $r ) {
			/* translators: 1: pack path, 2: reason. */
			$msgs[] = sprintf( __( 'Jobs: %1$s refused, %2$s.', 'zorderz' ), $r['path'], $r['why'] );
		}
		if ( class_exists( 'ZJOB_Project_Orders' ) ) {
			ZJOB_Project_Orders::flush_memo();
		}
		return $msgs;
	}

	public static function restore( $kept ) {
		if ( ! is_array( $kept ) ) {
			return array( __( 'nothing to put back.', 'zorderz' ) );
		}
		foreach ( $kept as $opt => $val ) {
			if ( ! in_array( $opt, array( ZJOB_Project_Orders::OPT_FORMS, ZJOB_My_Next::RULES_OPTION ), true ) ) {
				continue;
			}
			if ( null === $val ) {
				delete_option( $opt );
			} else {
				update_option( $opt, $val, false );
			}
		}
		return array( __( 'Jobs: order forms and step rules put back.', 'zorderz' ) );
	}
}
