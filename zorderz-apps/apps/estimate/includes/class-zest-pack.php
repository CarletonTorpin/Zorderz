<?php
/**
 * ZEST_Pack -- Estimates' part of an Identity Pack. Each value is checked here and written into the option the app
 * reads; a value that cannot be read is refused with the reason and changes nothing; a key Estimates does not know is
 * reported as unused. Revert puts every option back as it was.
 *
 *   rules.yml
 *     estimates:
 *       deposit:
 *         house_limit: { percent: 10, fixed_cents: 50000 }   # the business's own, stricter limit (never wider than law)
 *         product_words: [ "widget" ]                         # what the business sells: goods that ship or arrive
 *         rate_words: [ "opacity" ]                           # its products' attributes measured in percent
 *       payment_schedule:
 *         groups: [ { id: "widgets", label: "Widgets", names: [ "widget" ], price_book_family: "" } ]
 *         deposit: { at_limit: true }                         # or { percent, fixed_cents, whole_dollars }
 *       payment_notes_mode: test                              # off | test | on
 *       test_fixture: "Test Customer"                         # the mark a test customer's name starts with
 *       door_handle: { enabled: true, housing_words: [ "widget" ], reference_view: outside }   # team-only handle side
 *       reassign: { from_codes: [ "AB" ] }                    # codes a stub carries while nobody has been chosen
 *   catalog.yml
 *     estimate:
 *       line_options: { map_version: 1, built, colors, color_context, slots, families }   # the press-and-hold menus
 *   territories.yml
 *     postal_cities: { "00000": [ "Neighbourhood name", "Postal city" ] }
 *   document-conventions.yml
 *     estimate:
 *       deposit_sentence / balance_sentence / option_tail / edited_by_line   (strings)
 *       schedule: { heading, deposit_line, window_line, final_line, total_line, tax_included_suffix, promise_line,
 *                   link_lead, phone_lead, tax_included_names: [] }
 *       sale_form: { blank_url, sha256, fields: { field: fact } }
 *
 * The document-convention keys are written into the document conventions' own option, only these keys under
 * `estimate` (every other key there is kept as it was).
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Pack {

	const DOC_KEYS = array( 'deposit_sentence', 'balance_sentence', 'option_tail', 'edited_by_line', 'schedule' );
	const RULE_KEYS = array( 'deposit', 'payment_schedule', 'payment_notes_mode', 'test_fixture', 'door_handle', 'reassign' );
	const DOC_OPT   = 'zdz_document_conventions';

	public static function init(): void {
		add_filter( 'zdz_identity_pack_consumers', array( __CLASS__, 'register' ) );
	}

	public static function register( $consumers ) {
		$consumers                     = is_array( $consumers ) ? $consumers : array();
		$consumers['estimate-creator'] = array(
			'label'    => __( 'Estimates', 'zorderz' ),
			'files'    => array( 'rules', 'territories', 'document-conventions', 'catalog' ),
			'paths'    => array( 'rules' => array( 'estimates' ), 'territories' => array( 'postal_cities' ), 'document-conventions' => array( 'estimate' ), 'catalog' => array( 'estimate' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	private static function words( $v, int $max = 60 ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$out = array();
		foreach ( $v as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( ! preg_match( '/^[a-z][a-z \-]{1,39}$/', $w ) ) {
				return null;
			}
			$out[] = $w;
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	private static function limit( $v ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$out = array();
		if ( isset( $v['percent'] ) ) {
			if ( ! is_numeric( $v['percent'] ) || (float) $v['percent'] < 0 || (float) $v['percent'] > 100 ) {
				return null;
			}
			$out['percent'] = (float) $v['percent'];
		}
		if ( isset( $v['fixed_cents'] ) ) {
			if ( ! is_numeric( $v['fixed_cents'] ) || (int) $v['fixed_cents'] < 0 ) {
				return null;
			}
			$out['fixed_cents'] = (int) $v['fixed_cents'];
		}
		if ( ! empty( $v['whole_dollars'] ) ) {
			$out['whole_dollars'] = true;
		}
		if ( ! empty( $v['at_limit'] ) ) {
			$out['at_limit'] = true;
		}
		return $out ? $out : null;
	}

	/**
	 * What the pack's files say for Estimates, checked (pure): [ 'writes' => option => value, 'doc' => estimate.* =>
	 * value, 'refused' => [ [path, why] ], 'unconsumed' => [ path ] ], or null when the pack has none of the blocks.
	 */
	public static function plan( array $files ): ?array {
		$rules = is_array( $files['rules']['estimates'] ?? null ) ? $files['rules']['estimates'] : null;
		$terr  = is_array( $files['territories']['postal_cities'] ?? null ) ? $files['territories']['postal_cities'] : null;
		$doc   = is_array( $files['document-conventions']['estimate'] ?? null ) ? $files['document-conventions']['estimate'] : null;
		$cat   = is_array( $files['catalog']['estimate'] ?? null ) ? $files['catalog']['estimate'] : null;
		if ( null === $rules && null === $terr && null === $doc && null === $cat ) {
			return null;
		}
		$p   = array( 'writes' => array(), 'doc' => array(), 'refused' => array(), 'unconsumed' => array() );
		$bad = static function ( string $path, string $why ) use ( &$p ) {
			$p['refused'][] = array( 'path' => $path, 'why' => $why );
		};
		foreach ( (array) $rules as $k => $v ) {
			if ( ! in_array( $k, self::RULE_KEYS, true ) ) {
				$p['unconsumed'][] = 'estimates.' . $k;
				continue;
			}
			if ( 'deposit' === $k ) {
				foreach ( (array) $v as $dk => $dv ) {
					if ( 'house_limit' === $dk ) {
						$l = self::limit( $dv );
						if ( null === $l ) {
							$bad( 'estimates.deposit.house_limit', __( 'a percent from 0 to 100 and/or whole cents', 'zorderz' ) );
						} else {
							$p['writes']['zest_deposit_house_limit'] = $l;
						}
					} elseif ( 'product_words' === $dk || 'rate_words' === $dk ) {
						$w = self::words( $dv );
						if ( null === $w ) {
							$bad( 'estimates.deposit.' . $dk, __( 'a list of words (letters, spaces, hyphens)', 'zorderz' ) );
						} else {
							$p['writes'][ 'product_words' === $dk ? 'zest_deposit_product_words' : 'zest_deposit_rate_words' ] = $w;
						}
					} else {
						$p['unconsumed'][] = 'estimates.deposit.' . $dk;
					}
				}
			} elseif ( 'payment_schedule' === $k ) {
				$groups = array();
				$ok     = is_array( $v );
				foreach ( (array) ( $v['groups'] ?? array() ) as $g ) {
					$id = is_array( $g ) ? strtolower( trim( (string) ( $g['id'] ?? '' ) ) ) : '';
					$nm = is_array( $g ) ? self::words( (array) ( $g['names'] ?? array() ) ) : null;
					if ( ! preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}$/', $id ) || null === $nm ) {
						$ok = false;
						break;
					}
					$groups[] = array( 'id' => $id, 'label' => (string) ( $g['label'] ?? $id ), 'names' => $nm, 'price_book_family' => (string) ( $g['price_book_family'] ?? '' ) );
				}
				$dep = isset( $v['deposit'] ) ? self::limit( $v['deposit'] ) : array();
				if ( ! $ok || null === $dep ) {
					$bad( 'estimates.payment_schedule', __( 'groups need an id and a list of words; the deposit needs at_limit, a percent or whole cents', 'zorderz' ) );
				} else {
					$p['writes']['zest_payment_schedule'] = array( 'groups' => $groups, 'deposit' => $dep );
				}
			} elseif ( 'payment_notes_mode' === $k ) {
				if ( in_array( $v, array( 'off', 'test', 'on' ), true ) ) {
					$p['writes']['zest_payment_notes_mode'] = $v;
				} else {
					$bad( 'estimates.payment_notes_mode', __( 'off, test or on', 'zorderz' ) );
				}
			} elseif ( 'door_handle' === $k ) {
				$w = is_array( $v ) ? self::words( (array) ( $v['housing_words'] ?? array() ) ) : null;
				$r = is_array( $v ) ? (string) ( $v['reference_view'] ?? 'outside' ) : '';
				if ( null === $w || ! in_array( $r, array( 'outside', 'inside' ), true ) ) {
					$bad( 'estimates.door_handle', __( 'enabled, a list of housing words and a reference_view of outside or inside', 'zorderz' ) );
				} else {
					$p['writes']['zest_door_handle'] = array( 'enabled' => ! empty( $v['enabled'] ), 'housing_words' => $w, 'reference_view' => $r );
				}
			} elseif ( 'reassign' === $k ) {
				$codes = array();
				$ok    = is_array( $v ) && is_array( $v['from_codes'] ?? array() );
				foreach ( $ok ? (array) ( $v['from_codes'] ?? array() ) : array() as $c ) {
					$c = strtoupper( trim( (string) $c ) );
					if ( ! preg_match( '/^[A-Z]{2,3}$/', $c ) ) {
						$ok = false;
						break;
					}
					$codes[] = $c;
				}
				if ( ! $ok ) {
					$bad( 'estimates.reassign', __( 'from_codes: a list of two- or three-letter codes', 'zorderz' ) );
				} else {
					$p['writes']['zest_reassign_from_codes'] = array_values( array_unique( $codes ) );
				}
			} elseif ( 'test_fixture' === $k ) {
				$s = trim( (string) $v );
				if ( '' !== $s && strlen( $s ) <= 60 && ! preg_match( '/[<>]/', $s ) ) {
					$p['writes']['zest_test_fixture'] = $s;
				} else {
					$bad( 'estimates.test_fixture', __( 'a short name mark', 'zorderz' ) );
				}
			}
		}
		if ( null !== $terr ) {
			$map = array();
			foreach ( $terr as $zip => $pair ) {
				$z = (string) preg_replace( '/\D/', '', (string) $zip );
				if ( 5 !== strlen( $z ) || ! is_array( $pair ) || 2 !== count( $pair ) ) {
					$bad( 'postal_cities.' . $zip, __( 'a five-digit postcode and [ stored name, postal city ]', 'zorderz' ) );
					continue;
				}
				$map[ $z ] = array( (string) array_values( $pair )[0], (string) array_values( $pair )[1] );
			}
			$p['writes']['zest_postal_cities'] = $map;
		}
		foreach ( (array) $cat as $k => $v ) {
			if ( 'line_options' === $k ) {
				if ( class_exists( 'ZEST_Line_Options' ) && ZEST_Line_Options::validate( $v ) ) {
					$p['writes'][ ZEST_Line_Options::PACK_OPTION ] = $v;
				} else {
					$bad( 'catalog.estimate.line_options', __( 'a map_version 1 option map: families, slots, colors, every word short plain text', 'zorderz' ) );
				}
			} else {
				$p['unconsumed'][] = 'catalog.estimate.' . $k;
			}
		}
		foreach ( (array) $doc as $k => $v ) {
			if ( 'sale_form' === $k ) {
				$ok = is_array( $v ) && preg_match( '#^https://#', (string) ( $v['blank_url'] ?? '' ) ) && preg_match( '/^[0-9a-f]{64}$/', (string) ( $v['sha256'] ?? '' ) ) && is_array( $v['fields'] ?? null );
				if ( $ok ) {
					$p['writes']['zest_sale_form'] = array( 'blank_url' => (string) $v['blank_url'], 'sha256' => (string) $v['sha256'], 'fields' => array_map( 'strval', (array) $v['fields'] ) );
				} else {
					$bad( 'estimate.sale_form', __( 'an https blank_url, its sha256 and a fields map', 'zorderz' ) );
				}
			} elseif ( in_array( $k, self::DOC_KEYS, true ) ) {
				$ok = 'schedule' === $k ? is_array( $v ) : ( is_string( $v ) && strlen( $v ) <= 300 && false === strpos( $v, "\n" ) );
				if ( $ok ) {
					$p['doc'][ $k ] = $v;
				} else {
					$bad( 'estimate.' . $k, __( 'one line of text', 'zorderz' ) );
				}
			} else {
				$p['unconsumed'][] = 'estimate.' . $k;
			}
		}
		return $p;
	}

	private static function file_of( string $opt ): string {
		if ( 'zest_line_options_pack' === $opt ) {
			return 'catalog';
		}
		return 'zest_postal_cities' === $opt ? 'territories' : ( 'zest_sale_form' === $opt ? 'document-conventions' : 'rules' );
	}

	private static function file_of_path( string $path ): string {
		if ( 0 === strpos( $path, 'catalog.' ) ) {
			return 'catalog';
		}
		if ( 0 === strpos( $path, 'postal_cities' ) ) {
			return 'territories';
		}
		return 0 === strpos( $path, 'estimate.' ) ? 'document-conventions' : 'rules';
	}

	public static function preview( array $files ) {
		$p = self::plan( $files );
		if ( null === $p ) {
			return array();
		}
		$rows = array();
		foreach ( $p['writes'] as $opt => $v ) {
			$cur = get_option( $opt, null );
			if ( $cur !== $v ) {
				$rows[] = array( 'file' => self::file_of( $opt ), 'path' => $opt, 'from' => wp_json_encode( $cur ), 'to' => wp_json_encode( $v ) );
			}
		}
		$stored = get_option( self::DOC_OPT, array() );
		foreach ( $p['doc'] as $k => $v ) {
			$cur = is_array( $stored ) ? ( $stored['estimate'][ $k ] ?? null ) : null;
			if ( $cur !== $v ) {
				$rows[] = array( 'file' => 'document-conventions', 'path' => 'estimate.' . $k, 'from' => wp_json_encode( $cur ), 'to' => wp_json_encode( $v ) );
			}
		}
		foreach ( $p['refused'] as $r ) {
			$rows[] = array( 'file' => self::file_of_path( $r['path'] ), 'path' => $r['path'], 'from' => '', 'to' => '', 'kind' => 'refused', 'why' => $r['why'] );
		}
		foreach ( $p['unconsumed'] as $u ) {
			$rows[] = array( 'file' => self::file_of_path( $u ), 'path' => $u, 'from' => '', 'to' => '', 'kind' => 'unconsumed' );
		}
		return $rows;
	}

	public static function apply( array $files, $slug = '' ) {
		$p = self::plan( $files );
		if ( null === $p ) {
			return array( __( 'Estimates: the pack has no estimate settings; left as they were.', 'zorderz' ) );
		}
		foreach ( $p['writes'] as $opt => $v ) {
			update_option( $opt, $v, false );
		}
		if ( $p['doc'] ) {
			$stored = get_option( self::DOC_OPT, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$stored['estimate'] = array_merge( is_array( $stored['estimate'] ?? null ) ? $stored['estimate'] : array(), $p['doc'] );
			update_option( self::DOC_OPT, $stored );
			if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
				ZDZ_Doc_Conventions::flush();
			}
		}
		if ( class_exists( 'ZEST_Line_Options' ) ) {
			ZEST_Line_Options::flush();
		}
		$n    = count( $p['writes'] ) + count( $p['doc'] );
		$msgs = $n ? array( sprintf( _n( 'Estimates: %d setting applied.', 'Estimates: %d settings applied.', $n, 'zorderz' ), $n ) ) : array();
		foreach ( $p['refused'] as $r ) {
			/* translators: 1: a pack path, 2: why */
			$msgs[] = sprintf( __( 'Estimates: %1$s left as it was: %2$s', 'zorderz' ), $r['path'], $r['why'] );
		}
		return $msgs;
	}

	const OPTIONS = array( 'zest_deposit_house_limit', 'zest_deposit_product_words', 'zest_deposit_rate_words', 'zest_payment_schedule', 'zest_payment_notes_mode', 'zest_test_fixture', 'zest_postal_cities', 'zest_sale_form', 'zest_door_handle', 'zest_reassign_from_codes', 'zest_line_options_pack', self::DOC_OPT );

	/** What to keep before a pack writes: each option as it was, or that there was none. */
	public static function snapshot( ...$unused ) {
		$out = array();
		foreach ( self::OPTIONS as $o ) {
			$v         = get_option( $o, null );
			$out[ $o ] = array( 'had' => null !== $v, 'value' => $v );
		}
		return $out;
	}

	public static function restore( $kept ) {
		foreach ( is_array( $kept ) ? $kept : array() as $o => $k ) {
			if ( ! in_array( $o, self::OPTIONS, true ) || ! is_array( $k ) ) {
				continue;
			}
			if ( ! empty( $k['had'] ) ) {
				update_option( $o, $k['value'], false );
			} else {
				delete_option( $o );
			}
		}
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			ZDZ_Doc_Conventions::flush();
		}
		return array();
	}
}
