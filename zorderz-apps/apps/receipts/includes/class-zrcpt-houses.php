<?php
/**
 * ZRCPT_Houses — "Which house?": is this photo of THIS customer's house?
 *
 * Two neighbours can have work done on the same day; a phone's location cannot tell two houses fifteen metres apart.
 * A photo goes on a customer's receipt only when the system is SURE it belongs to that house, or when a PERSON has
 * said so. Everything else is "Which house?", and the server refuses to make the receipt until somebody answers.
 *
 * HOW SURE IS DECIDED, strongest signal first
 *   1. The job open on the camera (the photo's job_id). A job of this receipt's Project (or, with the Project unknown,
 *      at the same street address) is SURE. A job anywhere else never appears here, whatever its location says.
 *   2. A person's filing: every "this photo is of house X" is kept with who and when (table zrcpt_photo_house,
 *      append-only, newest wins).
 *   3. Geometry against EVERY house with work that day. A house is "in reach" of a photo when
 *      distance <= photo accuracy + house-point uncertainty + MARGIN_M. Only this house in reach: sure. This house and
 *      another: Which house? Another only: not this house (a person may still file it here). None in reach: off site;
 *      "Include anyway" is allowed only when no other same-day house is within LOOSE_R_M, and it is logged.
 *   4. No location at all: allowed (nothing can disprove it) UNLESS another same-day house is within LOOSE_R_M of this
 *      one; then a person decides.
 *
 * WHERE THE OTHER HOUSES COME FROM: the jobs named on that day's photos (each at its geocoded address, or better, the
 * centre of its own tight-fix photos), the receipts already made for that day (the centre of their photos), and the
 * filter `zrcpt_same_day_houses` (a scheduler or jobs app may publish its day's installs there). A source that cannot
 * answer makes the generate check refuse ("could not check" is never "nobody there").
 *
 * PRIVACY. Coordinates never reach the browser: the picker gets each photo's status, a short reason and a distance,
 * and each house's letter and street line. The engine below is pure; the adapters read the media store, the jobs app's
 * public faces and the receipts.
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRCPT_Houses {

	/** Slack on top of photo accuracy + house uncertainty, metres. */
	const MARGIN_M = 10.0;
	/** A photo with no stated accuracy, conservatively (a phone outdoors). */
	const UNKNOWN_ACC_M = 25.0;
	/** Another same-day house this close is a competitor. */
	const LOOSE_R_M = 300.0;
	/** How far around a photo other houses are looked for at all. */
	const SEARCH_R_M = 400.0;
	/** A fix this tight can stand for the house itself ("observed"). */
	const OBSERVED_MAX_ACC_M = 20.0;

	/** Uncertainty of a house point, by how it was found. */
	const UNC_M = array(
		'rooftop'      => 8.0,
		'observed'     => 10.0,
		'parcel'       => 15.0,
		'interpolated' => 50.0,
		'approximate'  => 75.0,
		'unknown'      => 75.0,
	);

	const JOB_THIS       = 'job_this';
	const JOB_OTHER      = 'job_other';
	const JOB_UNVERIFIED = 'job_unverified';
	const FILED_THIS     = 'filed_this';
	const FILED_OTHER    = 'filed_other';
	const NEAR_THIS      = 'near_this';
	const NEAR_OTHER     = 'near_other';
	const WHICH          = 'which_house';
	const OFF_SITE       = 'off_site';
	const UNLOCATED      = 'unlocated';
	const NO_ANCHOR      = 'no_anchor';

	/** Statuses that may go on this receipt without anything further. */
	const ALLOWED = array( 'job_this', 'filed_this', 'near_this', 'unlocated', 'no_anchor' );
	/** Statuses a person must resolve. */
	const NEEDS_PERSON = array( 'which_house', 'near_other', 'job_unverified' );

	const TABLE      = 'zrcpt_photo_house';
	const DB_VERSION = '1';
	const DB_OPTION  = 'zrcpt_photo_house_db';

	/** Set by the generate check: every source must answer, or it throws (the check refuses). */
	public static $strict = false;

	private static $memo = array();

	public static function flush_memo(): void {
		self::$memo = array();
	}

	public static function init(): void {
		add_action( 'wp_ajax_zrcpt_file_photos', array( __CLASS__, 'ajax_file_photos' ) );
		// No _nopriv variant.
	}

	/* =====================================================================
	 * PURE CORE
	 * ===================================================================== */

	public static function unc_for( string $precision ): float {
		$p = strtolower( trim( $precision ) );
		if ( 'exact' === $p || 'point' === $p ) {
			$p = 'rooftop';
		}
		return (float) ( self::UNC_M[ $p ] ?? self::UNC_M['unknown'] );
	}

	/** A house, normalised: every source goes through here, so the geometry sees one shape. */
	public static function house( array $a ): array {
		$lat = isset( $a['lat'] ) && is_numeric( $a['lat'] ) ? (float) $a['lat'] : null;
		$lng = isset( $a['lng'] ) && is_numeric( $a['lng'] ) ? (float) $a['lng'] : null;
		if ( null !== $lat && null !== $lng && ( abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) ) {
			$lat = null;
			$lng = null;
		}
		$prec = (string) ( $a['precision'] ?? 'unknown' );
		$keys = array();
		foreach ( (array) ( $a['keys'] ?? array() ) as $k ) {
			if ( '' !== (string) $k ) {
				$keys[ (string) $k ] = true;
			}
		}
		$key = (string) ( $a['key'] ?? '' );
		if ( '' === $key && $keys ) {
			$key = (string) array_key_first( $keys );
		}
		if ( '' !== $key ) {
			$keys[ $key ] = true;
		}
		return array(
			'key'         => $key,
			'keys'        => array_keys( $keys ),
			'label'       => (string) ( $a['label'] ?? '' ),
			'address'     => (string) ( $a['address'] ?? '' ),
			'lat'         => $lat,
			'lng'         => $lng,
			'precision'   => $prec,
			'unc_m'       => isset( $a['unc_m'] ) && is_numeric( $a['unc_m'] ) ? max( 1.0, (float) $a['unc_m'] ) : self::unc_for( $prec ),
			'source'      => (string) ( $a['source'] ?? '' ),
			'job_id'      => (int) ( $a['job_id'] ?? 0 ),
			'project_ids' => array_values( array_filter( array_map( 'strval', (array) ( $a['project_ids'] ?? array() ) ), 'strlen' ) ),
			'is_target'   => ! empty( $a['is_target'] ),
		);
	}

	public static function has_point( ?array $h ): bool {
		return is_array( $h ) && null !== $h['lat'] && null !== $h['lng'];
	}

	/** Great-circle metres. */
	public static function dist_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r  = 6371000.0;
		$p1 = deg2rad( $lat1 );
		$p2 = deg2rad( $lat2 );
		$dp = deg2rad( $lat2 - $lat1 );
		$dl = deg2rad( $lng2 - $lng1 );
		$a  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	public static function photo_acc( array $p ): float {
		$a = $p['gps_accuracy'] ?? null;
		return ( null !== $a && '' !== $a && is_numeric( $a ) && (float) $a >= 0 ) ? max( 3.0, (float) $a ) : self::UNKNOWN_ACC_M;
	}

	public static function photo_has_gps( array $p ): bool {
		return isset( $p['gps_lat'], $p['gps_lng'] ) && '' !== $p['gps_lat'] && '' !== $p['gps_lng'];
	}

	/** Lower-case, letters and digits, common words shortened, so "12 Oak Street" and "12 oak st." match. PURE. */
	public static function norm( string $x ): string {
		$x = strtolower( (string) preg_replace( '/#\s*/', ' unit ', $x ) );
		$x = ' ' . trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $x ) ) . ' ';
		$x = strtr( $x, array(
			' street ' => ' st ', ' avenue ' => ' ave ', ' av ' => ' ave ', ' drive ' => ' dr ', ' road ' => ' rd ', ' lane ' => ' ln ',
			' court ' => ' ct ', ' place ' => ' pl ', ' boulevard ' => ' blvd ', ' circle ' => ' cir ', ' terrace ' => ' ter ',
			' trail ' => ' trl ', ' parkway ' => ' pkwy ', ' highway ' => ' hwy ', ' glen ' => ' gln ', ' crossing ' => ' xing ',
			' point ' => ' pt ', ' cove ' => ' cv ', ' square ' => ' sq ', ' apartment ' => ' apt ', ' suite ' => ' ste ',
			' north ' => ' n ', ' south ' => ' s ', ' east ' => ' e ', ' west ' => ' w ',
		) );
		return trim( (string) preg_replace( '/\s+/', ' ', $x ) );
	}

	/**
	 * The parts of an address that say which house: number, street, unit, town, postcode. null when the first part has
	 * no house number. PURE.
	 *
	 * @return array{num:string,street:string,unit:string,city:string,zip:string}|null
	 */
	public static function street_key( string $address ): ?array {
		$raw = trim( $address );
		if ( '' === $raw ) {
			return null;
		}
		$parts = array_values( array_filter( array_map( 'trim', (array) preg_split( '/[,\n]/', $raw ) ), 'strlen' ) );
		$zip   = '';
		$last  = (string) end( $parts );
		if ( count( $parts ) > 1 && preg_match( '/(?:^|\s)([A-Z0-9]{2,5}(?:[ -][A-Z0-9]{2,4})?)\s*$/i', $last, $zm ) && preg_match( '/\d/', $zm[1] ) ) {
			$zip = strtolower( str_replace( array( ' ', '-' ), '', $zm[1] ) );
		}
		$norm = self::norm( (string) ( $parts[0] ?? '' ) );
		if ( ! preg_match( '/^(\d+[a-z]?)\s+(.+)$/', $norm, $m ) ) {
			return null;
		}
		$street = $m[2];
		$unit   = '';
		if ( preg_match( '/^(.*?)\s+(?:apt|ste|unit|no)\s*(\S+)$/', $street, $um ) ) {
			$street = $um[1];
			$unit   = $um[2];
		}
		$rest = array_slice( $parts, 1 );
		if ( '' === $unit && $rest && preg_match( '/^(?:apt|ste|unit)\s*(\S+)$/', self::norm( (string) $rest[0] ), $um2 ) ) {
			$unit = $um2[1];
			array_shift( $rest );
		}
		$city = '';
		if ( $rest ) {
			$city = self::norm( (string) $rest[0] );
			$city = trim( (string) preg_replace( '/\s+(?:[a-z]{2,3}\s+)?[a-z0-9]*\d[a-z0-9 ]*$/', '', $city ) ); // a region and postcode riding on it
			if ( preg_match( '/\d/', $city ) || preg_match( '/^[a-z]{2,3}$/', $city ) ) {
				$city = '';
			}
		}
		return array( 'num' => $m[1], 'street' => trim( (string) $street ), 'unit' => (string) $unit, 'city' => $city, 'zip' => $zip );
	}

	/**
	 * Two addresses for the same house? Number, the whole street and the unit must agree (a duplex's other unit is the
	 * neighbour case), and the postcode when both carry one, else the town when both name one. Never a distance test.
	 * PURE.
	 */
	public static function same_address( string $a, string $b ): bool {
		$pa = self::street_key( $a );
		$pb = self::street_key( $b );
		if ( null === $pa || null === $pb ) {
			return false;
		}
		if ( $pa['num'] !== $pb['num'] || $pa['street'] !== $pb['street'] || $pa['unit'] !== $pb['unit'] ) {
			return false;
		}
		if ( '' !== $pa['zip'] && '' !== $pb['zip'] ) {
			return $pa['zip'] === $pb['zip'];
		}
		return ! ( '' !== $pa['city'] && '' !== $pb['city'] && $pa['city'] !== $pb['city'] );
	}

	/** The street's name without direction or suffix words ("n oak trl" -> "oak"). PURE. */
	public static function street_core( string $street ): string {
		$skip = (array) apply_filters( 'zrcpt_street_words', array( 'n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw', 'st', 'ave', 'blvd', 'ct', 'dr', 'ln', 'rd', 'pl', 'ter', 'cir', 'pkwy', 'hwy', 'way', 'trl', 'row', 'sq', 'loop', 'pt', 'cv', 'gln', 'xing' ) );
		$w    = array_values( array_filter( explode( ' ', $street ), static function ( $t ) use ( $skip ) {
			return '' !== $t && ! in_array( $t, $skip, true );
		} ) );
		return $w ? $w[0] : $street;
	}

	/**
	 * How two addresses relate, for a camera job's photos: 'same', 'conflict' (definitely different: another number, a
	 * different stated unit or postcode, another street), or 'unknown' (a person decides). PURE.
	 */
	public static function address_relation( string $a, string $b ): string {
		$pa = self::street_key( $a );
		$pb = self::street_key( $b );
		if ( null === $pa || null === $pb ) {
			return 'unknown';
		}
		if ( self::same_address( $a, $b ) ) {
			return 'same';
		}
		if ( $pa['num'] !== $pb['num'] ) {
			return 'conflict';
		}
		if ( '' !== $pa['unit'] && '' !== $pb['unit'] && $pa['unit'] !== $pb['unit'] ) {
			return 'conflict';
		}
		if ( '' !== $pa['zip'] && '' !== $pb['zip'] && $pa['zip'] !== $pb['zip'] ) {
			return 'conflict';
		}
		if ( self::street_core( $pa['street'] ) !== self::street_core( $pb['street'] ) ) {
			return 'conflict';
		}
		return 'unknown';
	}

	/** Does a filing name this house? PURE. */
	public static function decision_matches( array $decision, array $target ): bool {
		$k = (string) ( $decision['house_key'] ?? '' );
		if ( 'unbound' === $k || 'none' === $k || 'self:house' === $k ) {
			return false; // never anyone's house
		}
		if ( '' !== $k && in_array( $k, (array) $target['keys'], true ) ) {
			return true;
		}
		$addr = (string) ( $decision['house_address'] ?? '' );
		return '' !== $addr && '' !== (string) $target['address'] && self::same_address( $addr, (string) $target['address'] );
	}

	/**
	 * Place one photo against this receipt's house. PURE.
	 *
	 * @param array      $p        { media_id, job_id, gps_lat, gps_lng, gps_accuracy }
	 * @param array|null $target   house() or null (a list with no house of its own)
	 * @param array      $others   house()[]: every OTHER house with work that day
	 * @param array      $verdicts job_id => 'this' | 'other' | 'unverified' | 'unknown'
	 * @param array|null $decision the newest filing for this photo
	 * @return array{status:string,reach:array,competitors:array,acc_m:float,dist_m:?int,why:string}
	 */
	public static function place( array $p, ?array $target, array $others, array $verdicts = array(), ?array $decision = null ): array {
		$acc = self::photo_acc( $p );
		$out = array( 'status' => '', 'reach' => array(), 'competitors' => array(), 'acc_m' => round( $acc, 1 ), 'dist_m' => null, 'why' => '' );

		$jid = (int) ( $p['job_id'] ?? 0 );
		if ( $jid > 0 ) {
			$v = (string) ( $verdicts[ $jid ] ?? 'unknown' );
			if ( 'this' === $v && null !== $target ) {
				return array( 'status' => self::JOB_THIS, 'why' => __( 'Taken from this job in the camera.', 'zorderz' ) ) + $out;
			}
			if ( 'other' === $v ) {
				return array( 'status' => self::JOB_OTHER, 'why' => __( 'Taken from a different job in the camera.', 'zorderz' ) ) + $out;
			}
			if ( 'unverified' === $v && null === $decision ) {
				return array( 'status' => self::JOB_UNVERIFIED, 'why' => __( 'Taken from a job that cannot be tied to this invoice.', 'zorderz' ) ) + $out;
			}
			// 'unknown' (the job no longer exists): treated as untagged.
		}

		if ( null !== $decision ) {
			return null !== $target && self::decision_matches( $decision, $target )
				? array( 'status' => self::FILED_THIS, 'why' => __( 'Filed to this house by a person.', 'zorderz' ) ) + $out
				: array( 'status' => self::FILED_OTHER, 'why' => __( 'Filed to another house by a person.', 'zorderz' ) ) + $out;
		}

		$others = array_values( array_filter( $others, array( __CLASS__, 'has_point' ) ) );

		if ( ! self::photo_has_gps( $p ) ) {
			$comp = array();
			if ( null !== $target && self::has_point( $target ) ) {
				foreach ( $others as $h ) {
					if ( self::dist_m( $h['lat'], $h['lng'], $target['lat'], $target['lng'] ) <= self::LOOSE_R_M ) {
						$comp[] = $h['key'];
					}
				}
			}
			$out['competitors'] = $comp;
			$out['status']      = $comp ? self::WHICH : self::UNLOCATED;
			$out['why']         = $comp ? __( 'No location on this photo, and another house near this one had work that day.', 'zorderz' ) : __( 'No location on this photo.', 'zorderz' );
			return $out;
		}

		$plat   = (float) $p['gps_lat'];
		$plng   = (float) $p['gps_lng'];
		$in     = array();
		$loose  = array();
		$houses = $others;
		if ( null !== $target && self::has_point( $target ) ) {
			array_unshift( $houses, $target );
		}
		foreach ( $houses as $h ) {
			$d                         = self::dist_m( $plat, $plng, $h['lat'], $h['lng'] );
			$r                         = $acc + (float) $h['unc_m'] + self::MARGIN_M;
			$out['reach'][ $h['key'] ] = array( 'd' => (int) round( $d ), 'r' => (int) round( $r ) );
			if ( $h['is_target'] ) {
				$out['dist_m'] = (int) round( $d );
			}
			if ( $d <= $r ) {
				$in[] = $h;
			} elseif ( ! $h['is_target'] && $d <= self::LOOSE_R_M ) {
				$loose[] = $h['key'];
			}
		}
		$this_in  = false;
		$other_in = array();
		foreach ( $in as $h ) {
			if ( $h['is_target'] ) {
				$this_in = true;
			} else {
				$other_in[] = $h['key'];
			}
		}
		$out['competitors'] = $other_in ? $other_in : $loose;

		if ( null === $target || ! self::has_point( $target ) ) {
			if ( count( $other_in ) >= 2 || ( null !== $target && ( $other_in || $loose ) ) ) {
				$out['status'] = self::WHICH;
				$out['why']    = __( 'Houses with work that day are too close to tell apart.', 'zorderz' );
			} elseif ( 1 === count( $other_in ) ) {
				$out['status'] = self::NEAR_OTHER;
				$out['why']    = __( 'Taken at another house with work that day.', 'zorderz' );
			} else {
				$out['status'] = self::NO_ANCHOR;
				$out['why']    = __( 'This address has no map point, and no other house is near.', 'zorderz' );
			}
			return $out;
		}
		if ( $this_in && ! $other_in ) {
			$out['status'] = self::NEAR_THIS;
			$out['why']    = __( 'Only this house is within the photo\'s location circle.', 'zorderz' );
		} elseif ( $this_in ) {
			$out['status'] = self::WHICH;
			$out['why']    = __( 'This house and a neighbour with work that day are both within the photo\'s location circle.', 'zorderz' );
		} elseif ( $other_in ) {
			$out['status'] = self::NEAR_OTHER;
			$out['why']    = __( 'Taken at another house with work that day.', 'zorderz' );
		} elseif ( $loose ) {
			$out['status'] = self::WHICH;
			$out['why']    = __( 'Not at this address, and another house with work that day is nearby.', 'zorderz' );
		} elseif ( null !== $out['dist_m'] && $out['dist_m'] <= self::LOOSE_R_M ) {
			// Nothing else had work anywhere near: the wide on-site rule still holds (a big lot, a rough geocode).
			$out['status'] = self::NEAR_THIS;
			$out['why']    = __( 'Near this address, and no other house with work that day is nearby.', 'zorderz' );
		} else {
			$out['status'] = self::OFF_SITE;
			$out['why']    = __( 'Not at this address, and no other house with work that day is nearby.', 'zorderz' );
		}
		return $out;
	}

	/**
	 * One status for a set from its photos' placements: which_house (any needs a person), off_site, filed_other (the
	 * whole set went elsewhere), this_house (every photo sure or filed here), or unlocated. PURE.
	 */
	public static function set_status( array $placements ): string {
		$c = array();
		foreach ( $placements as $pl ) {
			$s       = (string) ( $pl['status'] ?? '' );
			$c[ $s ] = ( $c[ $s ] ?? 0 ) + 1;
		}
		$n = array_sum( $c );
		if ( 0 === $n ) {
			return 'unlocated';
		}
		foreach ( self::NEEDS_PERSON as $s ) {
			if ( ! empty( $c[ $s ] ) ) {
				return 'which_house';
			}
		}
		if ( ! empty( $c[ self::OFF_SITE ] ) ) {
			return 'off_site';
		}
		$away = ( $c[ self::FILED_OTHER ] ?? 0 ) + ( $c[ self::JOB_OTHER ] ?? 0 );
		if ( $away === $n ) {
			return 'filed_other';
		}
		$sure = ( $c[ self::JOB_THIS ] ?? 0 ) + ( $c[ self::NEAR_THIS ] ?? 0 ) + ( $c[ self::FILED_THIS ] ?? 0 );
		return ( $sure > 0 && $sure + $away === $n ) ? 'this_house' : 'unlocated';
	}

	/** The server's per-photo verdict at generate: '' = allowed, else the refusal. Unknown fails closed. PURE. */
	public static function gate_reason( array $placement, bool $vouched ): string {
		$s = (string) ( $placement['status'] ?? '' );
		if ( in_array( $s, self::ALLOWED, true ) ) {
			return '';
		}
		if ( self::OFF_SITE === $s ) {
			return $vouched ? '' : 'off_site';
		}
		if ( self::JOB_OTHER === $s ) {
			return 'other_job';
		}
		if ( self::FILED_OTHER === $s ) {
			return 'filed_other';
		}
		if ( self::NEAR_OTHER === $s ) {
			return 'other_house';
		}
		return 'which_house';
	}

	/**
	 * Merge same-house duplicates from different sources, only on identity (a shared key or the same street address),
	 * never on distance. The better point wins. Anything that IS this house folds into it (its point only: a key from
	 * an address match never makes a job's photos "sure"). PURE.
	 *
	 * @return array{others:array,target:?array}
	 */
	public static function merge( array $houses, ?array $target ): array {
		$out = array();
		foreach ( $houses as $h ) {
			if ( null !== $target && ( array_intersect( $h['keys'], $target['keys'] )
				|| ( '' !== $h['address'] && '' !== $target['address'] && self::same_address( $h['address'], $target['address'] ) ) ) ) {
				if ( self::has_point( $h ) && ( ! self::has_point( $target ) || $h['unc_m'] < $target['unc_m'] ) ) {
					$target['lat']       = $h['lat'];
					$target['lng']       = $h['lng'];
					$target['unc_m']     = $h['unc_m'];
					$target['precision'] = $h['precision'];
				}
				continue;
			}
			$merged = false;
			foreach ( $out as &$o ) {
				if ( array_intersect( $h['keys'], $o['keys'] ) || ( '' !== $h['address'] && '' !== $o['address'] && self::same_address( $h['address'], $o['address'] ) ) ) {
					if ( self::has_point( $h ) && ( ! self::has_point( $o ) || $h['unc_m'] < $o['unc_m'] ) ) {
						$o['lat']       = $h['lat'];
						$o['lng']       = $h['lng'];
						$o['unc_m']     = $h['unc_m'];
						$o['precision'] = $h['precision'];
					}
					$o['keys']    = array_values( array_unique( array_merge( $o['keys'], $h['keys'] ) ) );
					$o['label']   = '' === $o['label'] ? $h['label'] : $o['label'];
					$o['address'] = '' === $o['address'] ? $h['address'] : $o['address'];
					$o['job_id']  = $o['job_id'] ? $o['job_id'] : $h['job_id'];
					$merged       = true;
					break;
				}
			}
			unset( $o );
			if ( ! $merged ) {
				$out[] = $h;
			}
		}
		return array( 'others' => $out, 'target' => $target );
	}

	/** The centre of tight-fix photos as an "observed" house point, or null. PURE. */
	public static function observed_point( array $photos, float $max_acc = self::OBSERVED_MAX_ACC_M, int $min = 2, bool $allow_unknown = false ): ?array {
		$pts = array();
		foreach ( $photos as $p ) {
			if ( ! self::photo_has_gps( $p ) ) {
				continue;
			}
			$a = $p['gps_accuracy'] ?? null;
			if ( ( null === $a || '' === $a || ! is_numeric( $a ) ) && $allow_unknown ) {
				$a = self::UNKNOWN_ACC_M;
			}
			if ( null === $a || '' === $a || ! is_numeric( $a ) || (float) $a > $max_acc ) {
				continue;
			}
			$pts[] = array( (float) $p['gps_lat'], (float) $p['gps_lng'], max( 3.0, (float) $a ) );
		}
		if ( count( $pts ) < $min ) {
			return null;
		}
		$la = 0.0;
		$ln = 0.0;
		$ac = 0.0;
		foreach ( $pts as $q ) {
			$la += $q[0];
			$ln += $q[1];
			$ac += $q[2];
		}
		$n      = count( $pts );
		$la    /= $n;
		$ln    /= $n;
		$ac    /= $n;
		$spread = 0.0;
		foreach ( $pts as $q ) {
			$spread = max( $spread, self::dist_m( $la, $ln, $q[0], $q[1] ) );
		}
		// A walk-around spreads photos over the lot; half the spread plus the mean fix is an honest radius.
		return array( 'lat' => round( $la, 7 ), 'lng' => round( $ln, 7 ), 'precision' => 'observed', 'unc_m' => round( max( self::UNC_M['observed'], $ac, $spread / 2 ), 1 ), 'n' => $n );
	}

	/** Street line only: the first part. Never a name. PURE. */
	public static function street_line( string $address ): string {
		return trim( (string) preg_split( '/[,\n]/', trim( $address ) )[0] );
	}

	/** A house as the picker needs it: letter, label, street line, how it was found. No coordinates, no names. PURE. */
	public static function house_public( array $h, string $letter = '' ): array {
		return array(
			'key'       => $h['key'],
			'label'     => '' !== $h['label'] ? $h['label'] : __( 'Another house', 'zorderz' ),
			'address'   => self::street_line( $h['address'] ),
			'unc_m'     => (int) round( $h['unc_m'] ),
			'precision' => $h['precision'],
			'is_target' => $h['is_target'],
			'letter'    => $letter,
		);
	}

	/* =====================================================================
	 * RECORDS: a person's filings (append-only, newest wins)
	 * ===================================================================== */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function ensure_table(): void {
		if ( (string) get_option( self::DB_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			media_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			house_key VARCHAR(64) NOT NULL DEFAULT '',
			house_address VARCHAR(255) NOT NULL DEFAULT '',
			house_label VARCHAR(191) NOT NULL DEFAULT '',
			status_before VARCHAR(24) NOT NULL DEFAULT '',
			context VARCHAR(24) NOT NULL DEFAULT '',
			decided_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			decided_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY media_id (media_id, id)
		) " . $wpdb->get_charset_collate() . ';' );
		if ( empty( $wpdb->last_error ) ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/** Keep a filing for each photo. The caller has checked the person may file them. */
	public static function record( array $media_ids, array $house, int $actor_id, string $context, array $status_before = array() ): int {
		global $wpdb;
		self::ensure_table();
		$key = substr( (string) preg_replace( '/[^a-z0-9:_-]/i', '', (string) ( $house['key'] ?? '' ) ), 0, 64 );
		if ( '' === $key || $actor_id <= 0 ) {
			return 0;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$n   = 0;
		foreach ( array_unique( array_map( 'intval', $media_ids ) ) as $mid ) {
			if ( $mid > 0 && $wpdb->insert( self::table(), array(
				'media_id'      => $mid,
				'house_key'     => $key,
				'house_address' => substr( (string) ( $house['address'] ?? '' ), 0, 255 ),
				'house_label'   => substr( (string) ( $house['label'] ?? '' ), 0, 191 ),
				'status_before' => substr( (string) ( $status_before[ $mid ] ?? '' ), 0, 24 ),
				'context'       => substr( sanitize_key( $context ), 0, 24 ),
				'decided_by'    => $actor_id,
				'decided_at'    => $now,
			) ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Newest filing per photo: media_id => row. */
	public static function decisions_for( array $media_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $media_ids ) ) ) );
		if ( ! $ids || (string) get_option( self::DB_OPTION, '' ) !== self::DB_VERSION ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			// Only integers are inlined.
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE media_id IN (' . implode( ',', $chunk ) . ') ORDER BY id DESC', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! isset( $out[ (int) $r['media_id'] ] ) ) {
					$out[ (int) $r['media_id'] ] = $r;
				}
			}
		}
		return $out;
	}

	/* =====================================================================
	 * ADAPTERS: the media store, the jobs app's public faces, receipts
	 * ===================================================================== */

	private static function media_table(): string {
		global $wpdb;
		return $wpdb->prefix . ( class_exists( 'ZDZ_User_Media' ) ? ZDZ_User_Media::TABLE : 'zdz_user_media' );
	}

	/** A media-store row in the shape place() reads. PURE. */
	public static function photo_from_row( array $r ): array {
		$meta = json_decode( (string) ( $r['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		$cap  = trim( (string) ( $r['captured_at'] ?? '' ) );
		if ( '' === $cap || 0 === strpos( $cap, '0000-00-00' ) ) {
			$cap = (string) ( $r['created_at'] ?? '' );
		}
		$ts = '' !== $cap ? strtotime( $cap ) : false;
		return array(
			'media_id'      => (int) ( $r['id'] ?? 0 ),
			'attachment_id' => (int) ( $r['wp_attachment_id'] ?? 0 ),
			'user_id'       => (int) ( $r['user_id'] ?? 0 ),
			'gps_lat'       => ( isset( $r['gps_lat'] ) && '' !== $r['gps_lat'] ) ? (float) $r['gps_lat'] : null,
			'gps_lng'       => ( isset( $r['gps_lng'] ) && '' !== $r['gps_lng'] ) ? (float) $r['gps_lng'] : null,
			'gps_accuracy'  => isset( $meta['gps_accuracy'] ) && is_numeric( $meta['gps_accuracy'] ) ? (float) $meta['gps_accuracy'] : null,
			'source_app'    => (string) ( $r['source_app'] ?? '' ),
			'source_ref'    => (string) ( $r['source_ref'] ?? '' ),
			'job_id'        => (int) ( $meta['job_id'] ?? 0 ),
			'day'           => $ts ? gmdate( 'Y-m-d', $ts ) : '',
		);
	}

	/** Media-store rows for attachment ids (read only). null when the read failed: the caller fails closed. */
	public static function photos_for_attachments( array $att_ids ): ?array {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $att_ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			// Only integers are inlined.
			$rows = $wpdb->get_results( 'SELECT id, user_id, wp_attachment_id, source_app, source_ref, captured_at, created_at, gps_lat, gps_lng, meta_json FROM ' . self::media_table() . ' WHERE wp_attachment_id IN (' . implode( ',', $chunk ) . ') ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				return null;
			}
			foreach ( $rows as $r ) {
				if ( ! isset( $out[ (int) $r['wp_attachment_id'] ] ) ) {
					$out[ (int) $r['wp_attachment_id'] ] = self::photo_from_row( $r );
				}
			}
		}
		return $out;
	}

	/** Photos taken on one day near a point, or carrying a job (read only). Throws when the read failed. */
	public static function photos_on_day( string $day, ?float $lat, ?float $lng ): array {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return array();
		}
		$mk = 'pod:' . $day . ':' . ( null === $lat ? '-' : round( $lat, 3 ) . ',' . round( $lng, 3 ) );
		if ( isset( self::$memo[ $mk ] ) ) {
			return self::$memo[ $mk ];
		}
		$t    = self::media_table();
		$from = $day . ' 00:00:00';
		$to   = $day . ' 23:59:59';
		if ( null !== $lat && null !== $lng ) {
			$dlat = self::SEARCH_R_M / 111320.0;
			$dlng = self::SEARCH_R_M / max( 1.0, 111320.0 * cos( deg2rad( $lat ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::media_table(): $wpdb->prefix plus a fixed table name
			$sql  = $wpdb->prepare( "SELECT id, user_id, wp_attachment_id, source_app, source_ref, captured_at, created_at, gps_lat, gps_lng, meta_json FROM {$t} WHERE media_type = 'photo' AND captured_at BETWEEN %s AND %s AND ( ( gps_lat BETWEEN %f AND %f AND gps_lng BETWEEN %f AND %f ) OR meta_json LIKE %s ) ORDER BY id DESC LIMIT 600",
				$from, $to, $lat - $dlat, $lat + $dlat, $lng - $dlng, $lng + $dlng, '%"job_id":%' );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::media_table(): $wpdb->prefix plus a fixed table name
			$sql = $wpdb->prepare( "SELECT id, user_id, wp_attachment_id, source_app, source_ref, captured_at, created_at, gps_lat, gps_lng, meta_json FROM {$t} WHERE media_type = 'photo' AND captured_at BETWEEN %s AND %s AND meta_json LIKE %s ORDER BY id DESC LIMIT 600", $from, $to, '%"job_id":%' );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'same-day photo read failed' );
		}
		return self::$memo[ $mk ] = array_map( array( __CLASS__, 'photo_from_row' ), $rows );
	}

	/** A job through the jobs app's public read, memoised; with the Project it belongs to. */
	public static function job_row( int $job_id ): ?array {
		if ( $job_id <= 0 || ! class_exists( 'ZJOB_Handoff' ) ) {
			return null;
		}
		$mk = 'job:' . $job_id;
		if ( ! array_key_exists( $mk, self::$memo ) ) {
			try {
				$row = ZJOB_Handoff::get( $job_id );
				if ( $row && class_exists( 'Zdz_Flow_Refs' ) ) {
					$pid = (string) ( Zdz_Flow_Refs::get( 'job', 'handoff', (string) $job_id ) ?? '' );
					if ( '' === $pid && (int) ( $row['estimate_id'] ?? 0 ) > 0 ) {
						$pid = (string) ( Zdz_Flow_Refs::get( 'estimate', 'document', (string) (int) $row['estimate_id'] ) ?? '' );
					}
					$row['project_id'] = $pid;
				}
				self::$memo[ $mk ] = $row ? $row : null;
			} catch ( \Throwable $e ) {
				self::$memo[ $mk ] = null;
			}
		}
		return self::$memo[ $mk ];
	}

	/** A geocoded point for an address, from the platform's cache only (never the network while a person waits). */
	private static function geocode( string $address ): ?array {
		if ( '' === trim( $address ) || ! class_exists( 'ZDZ_Geocoder' ) ) {
			return null;
		}
		try {
			$g = ZDZ_Geocoder::lookup( $address );
		} catch ( \Throwable $e ) {
			$g = null;
		}
		return is_array( $g ) && isset( $g['lat'], $g['lng'] ) ? $g : null;
	}

	/** A job as a house: its address's point, or better, the centre of its own tight-fix camera photos. */
	public static function job_house( int $job_id, array $day_photos = array() ): ?array {
		$row = self::job_row( $job_id );
		if ( ! $row ) {
			return null;
		}
		$addr = (string) ( $row['customer_address'] ?? '' );
		$geo  = self::geocode( $addr );
		$pid  = (string) ( $row['project_id'] ?? '' );
		$h    = array(
			'key'         => 'job:' . $job_id,
			'keys'        => array_filter( array( 'job:' . $job_id, '' !== $pid ? 'prj:' . $pid : '' ) ),
			'label'       => self::street_line( $addr ) ?: sprintf( __( 'Job #%d', 'zorderz' ), $job_id ),
			'address'     => $addr,
			'lat'         => $geo['lat'] ?? null,
			'lng'         => $geo['lng'] ?? null,
			'precision'   => (string) ( $geo['precision'] ?? 'unknown' ),
			'source'      => 'job',
			'job_id'      => $job_id,
			'project_ids' => '' !== $pid ? array( $pid ) : array(),
		);
		$obs = self::observed_point( array_values( array_filter( $day_photos, static function ( $p ) use ( $job_id ) {
			return (int) ( $p['job_id'] ?? 0 ) === $job_id;
		} ) ) );
		if ( $obs ) {
			$h['lat']       = $obs['lat'];
			$h['lng']       = $obs['lng'];
			$h['precision'] = 'observed';
			$h['unc_m']     = $obs['unc_m'];
		}
		return self::house( $h );
	}

	/**
	 * Does this job belong to the receipt's house? 'this' (same Project, or with the Project unknown the same street
	 * address), 'other' (another Project, or a definitely different address), 'unverified', or 'unknown' (no such job).
	 */
	public static function job_verdict( int $job_id, array $target ): string {
		$row = self::job_row( $job_id );
		if ( ! $row ) {
			return 'unknown';
		}
		if ( in_array( 'job:' . $job_id, $target['keys'], true ) ) {
			return 'this';
		}
		$pid = (string) ( $row['project_id'] ?? '' );
		if ( '' !== $pid && $target['project_ids'] ) {
			return in_array( $pid, $target['project_ids'], true ) ? 'this' : 'other';
		}
		$jaddr = (string) ( $row['customer_address'] ?? '' );
		if ( '' !== $jaddr && '' !== $target['address'] ) {
			$rel = self::address_relation( $jaddr, $target['address'] );
			if ( 'same' === $rel ) {
				return 'this';
			}
			if ( 'conflict' === $rel ) {
				return 'other'; // only a definite conflict locks a photo out
			}
		}
		return 'unverified';
	}

	/**
	 * This receipt's house, from what the server knows: the address read from the billing system, the invoice numbers,
	 * the Projects those invoices belong to (through the jobs app's refs) and their jobs.
	 *
	 * @param array $a { address, label, invoice_numbers[], receipt_id }
	 */
	public static function target( array $a ): array {
		$keys = array();
		$pids = array();
		foreach ( (array) ( $a['invoice_numbers'] ?? array() ) as $n ) {
			$d = preg_replace( '/[^0-9A-Za-z]/', '', (string) $n );
			if ( '' !== $d ) {
				$keys[] = 'inv:' . $d;
				if ( class_exists( 'ZJOB_Project_Refs' ) ) {
					try {
						$p = (string) ZJOB_Project_Refs::project_for( 'invoice', $d );
						if ( '' !== $p ) {
							$pids[] = $p;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}
		$pids = array_values( array_unique( $pids ) );
		foreach ( $pids as $p ) {
			$keys[] = 'prj:' . $p;
			if ( class_exists( 'Zjob_Project' ) ) {
				try {
					foreach ( (array) Zjob_Project::jobs_for( $p ) as $j ) {
						if ( ! empty( $j['id'] ) ) {
							$keys[] = 'job:' . (int) $j['id'];
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}
		if ( ! empty( $a['receipt_id'] ) ) {
			$keys[] = 'receipt:' . (int) $a['receipt_id'];
		}
		$keys[] = 'self:house';
		$addr   = trim( (string) ( $a['address'] ?? '' ) );
		$geo    = isset( $a['lat'], $a['lng'] ) && is_numeric( $a['lat'] ) ? array( 'lat' => $a['lat'], 'lng' => $a['lng'], 'precision' => (string) ( $a['precision'] ?? 'unknown' ) ) : self::geocode( $addr );
		if ( '' !== $addr ) {
			$keys[] = 'addr:' . substr( md5( self::norm( $addr ) ), 0, 20 );
		}
		$primary = '';
		foreach ( $keys as $k ) {
			if ( 0 === strpos( $k, 'inv:' ) ) {
				$primary = $k;
				break;
			}
		}
		return self::house( array(
			// 'unbound': no invoice and no address - a key no filing ever matches.
			'key'         => '' !== $primary ? $primary : ( current( array_diff( $keys, array( 'self:house' ) ) ) ?: 'unbound' ),
			'keys'        => $keys,
			'label'       => (string) ( $a['label'] ?? '' ) ?: ( self::street_line( $addr ) ?: __( 'This house', 'zorderz' ) ),
			'address'     => $addr,
			'lat'         => $geo['lat'] ?? null,
			'lng'         => $geo['lng'] ?? null,
			'precision'   => (string) ( $geo['precision'] ?? 'unknown' ),
			'source'      => 'target',
			'project_ids' => $pids,
			'is_target'   => true,
		) );
	}

	/** Receipts made for an install day, each placed at the centre of its own photos. */
	private static function receipt_houses_on( string $day ): array {
		if ( ! function_exists( 'get_posts' ) || ! class_exists( 'ZRCPT_Receipt' ) ) {
			return array();
		}
		$ts = strtotime( $day . ' 12:00:00' );
		if ( ! $ts ) {
			return array();
		}
		$d    = (int) gmdate( 'j', $ts );
		$text = gmdate( 'F ', $ts ) . $d . gmdate( 'S', gmmktime( 0, 0, 0, 1, $d ) ) . gmdate( ', Y', $ts );
		$ids  = array_map( 'intval', (array) get_posts( array(
			'post_type'      => ZRCPT_Receipt::POST_TYPE,
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_install_date', 'value' => $text, 'compare' => '=' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) ) );
		$att_of = array();
		$all    = array();
		foreach ( $ids as $pid ) {
			$att_of[ $pid ] = array_map( 'intval', (array) get_post_meta( $pid, '_source_media_ids', true ) );
			$all            = array_merge( $all, $att_of[ $pid ] );
		}
		$by_att = $all ? self::photos_for_attachments( $all ) : array();
		if ( null === $by_att ) {
			throw new \RuntimeException( 'receipt photo read failed' );
		}
		$out = array();
		foreach ( $ids as $pid ) {
			$ph = array();
			foreach ( $att_of[ $pid ] as $att ) {
				if ( isset( $by_att[ $att ] ) ) {
					$ph[] = $by_att[ $att ];
				}
			}
			$obs = self::observed_point( $ph, 1000.0, 1, true );
			if ( ! $obs ) {
				continue;
			}
			$keys = array( 'receipt:' . $pid );
			foreach ( (array) get_post_meta( $pid, '_fb_doc_numbers', false ) as $n ) {
				$dn = preg_replace( '/[^0-9A-Za-z]/', '', (string) $n );
				if ( '' !== $dn ) {
					$keys[] = 'inv:' . $dn;
				}
			}
			$addr  = (string) get_post_meta( $pid, '_address_short', true );
			$out[] = self::house( array(
				'key'       => 'receipt:' . $pid,
				'keys'      => array_values( array_unique( $keys ) ),
				'label'     => self::street_line( $addr ) ?: sprintf( __( 'Receipt %d', 'zorderz' ), $pid ),
				'address'   => $addr,
				'lat'       => $obs['lat'],
				'lng'       => $obs['lng'],
				'precision' => 'observed',
				// Placed by a person who may have had this very problem: never tighter than a street-level point.
				'unc_m'     => max( 25.0, (float) $obs['unc_m'] ),
				'source'    => 'receipt',
			) );
		}
		return $out;
	}

	/** Every other house with work that day near the points, and the target refined by them. */
	public static function day_houses( string $day, ?float $lat, ?float $lng, ?array $target, int $exclude_receipt = 0 ): array {
		$photos = self::photos_on_day( $day, $lat, $lng );
		$cands  = array();
		$jobs   = array();
		foreach ( $photos as $p ) {
			if ( $p['job_id'] > 0 ) {
				$jobs[ $p['job_id'] ] = true;
			}
		}
		foreach ( array_keys( $jobs ) as $jid ) {
			$h = self::job_house( (int) $jid, $photos );
			if ( $h ) {
				$cands[] = $h;
			}
		}
		foreach ( self::receipt_houses_on( $day ) as $h ) {
			$cands[] = $h;
		}
		$extra = apply_filters( 'zrcpt_same_day_houses', array(), array( 'date' => $day, 'lat' => $lat, 'lng' => $lng, 'radius_m' => self::SEARCH_R_M ) );
		foreach ( (array) $extra as $e ) {
			if ( is_array( $e ) && ! empty( $e['key'] ) ) {
				$e['source'] = 'filter:' . (string) ( $e['source'] ?? 'other' );
				$cands[]     = self::house( $e );
			}
		}
		if ( $exclude_receipt > 0 ) {
			$cands = array_values( array_filter( $cands, static function ( $h ) use ( $exclude_receipt ) {
				return ! in_array( 'receipt:' . $exclude_receipt, $h['keys'], true );
			} ) );
		}
		$m = self::merge( $cands, $target );
		return array( 'others' => array_values( array_filter( $m['others'], array( __CLASS__, 'has_point' ) ) ), 'target' => $m['target'] );
	}

	/**
	 * Place photos (any days) against a target; the same-day lookups run once per day.
	 *
	 * @return array{placements:array,houses:array,target:?array}
	 */
	public static function place_all( array $photos, ?array $target, int $exclude_receipt = 0 ): array {
		$by_day = array();
		$mids   = array();
		foreach ( $photos as $p ) {
			$by_day[ (string) ( $p['day'] ?? '' ) ][] = $p;
			if ( ! empty( $p['media_id'] ) ) {
				$mids[] = (int) $p['media_id'];
			}
		}
		$decisions  = self::decisions_for( $mids );
		$placements = array();
		$houses     = array();
		$t_out      = $target;
		foreach ( $by_day as $day => $list ) {
			$lat = null;
			$lng = null;
			if ( null !== $target && self::has_point( $target ) ) {
				$lat = $target['lat'];
				$lng = $target['lng'];
			} else {
				$c = self::observed_point( $list, 100000.0, 1, true );
				if ( $c ) {
					$lat = $c['lat'];
					$lng = $c['lng'];
				}
			}
			$dh    = '' !== $day ? self::day_houses( (string) $day, $lat, $lng, $target, $exclude_receipt ) : array( 'others' => array(), 'target' => $target );
			$t_day = $dh['target'];
			if ( null !== $t_day && null !== $t_out && self::has_point( $t_day ) && ( ! self::has_point( $t_out ) || $t_day['unc_m'] < $t_out['unc_m'] ) ) {
				$t_out = $t_day;
			}
			$tp     = ( null !== $t_day && self::has_point( $t_day ) ) ? $t_day : null;
			$others = array_values( array_filter( $dh['others'], static function ( $h ) use ( $tp, $list ) {
				if ( null !== $tp && self::dist_m( $tp['lat'], $tp['lng'], $h['lat'], $h['lng'] ) <= self::SEARCH_R_M + $h['unc_m'] ) {
					return true;
				}
				foreach ( $list as $p ) {
					if ( self::photo_has_gps( $p ) && self::dist_m( (float) $p['gps_lat'], (float) $p['gps_lng'], $h['lat'], $h['lng'] ) <= self::LOOSE_R_M + $h['unc_m'] + self::photo_acc( $p ) ) {
						return true;
					}
				}
				return false;
			} ) );
			$houses[ $day ] = $others;
			$verdicts       = array();
			foreach ( $list as $p ) {
				$j = (int) ( $p['job_id'] ?? 0 );
				if ( $j > 0 && ! isset( $verdicts[ $j ] ) ) {
					$verdicts[ $j ] = null !== $t_day ? self::job_verdict( $j, $t_day ) : 'unverified';
				}
			}
			foreach ( $list as $p ) {
				$mid       = (int) ( $p['media_id'] ?? 0 );
				$pl        = self::place( $p, $t_day, $others, $verdicts, $decisions[ $mid ] ?? null );
				$pl['day'] = $day;
				if ( isset( $decisions[ $mid ] ) ) {
					$pl['filed'] = array( 'house_label' => (string) $decisions[ $mid ]['house_label'], 'by' => (int) $decisions[ $mid ]['decided_by'], 'at' => (string) $decisions[ $mid ]['decided_at'] );
				}
				$placements[ $mid ] = $pl;
			}
		}
		return array( 'placements' => $placements, 'houses' => $houses, 'target' => $t_out );
	}

	/* =====================================================================
	 * THE PICKER and THE GATE
	 * ===================================================================== */

	/** The houses a set's "Which house?" shows: this one first, lettered A, B… PURE. */
	public static function houses_for_set( array $placements, array $houses_by_day, ?array $target ): array {
		$want = array();
		foreach ( $placements as $pl ) {
			foreach ( (array) ( $pl['competitors'] ?? array() ) as $k ) {
				$want[ $k ] = true;
			}
			foreach ( (array) ( $pl['reach'] ?? array() ) as $k => $r ) {
				if ( $r['d'] <= max( $r['r'], self::LOOSE_R_M ) ) {
					$want[ $k ] = true;
				}
			}
		}
		$list = null !== $target ? array( $target ) : array();
		$seen = null !== $target ? array( $target['key'] => true ) : array();
		foreach ( $placements as $pl ) {
			foreach ( (array) ( $houses_by_day[ $pl['day'] ?? '' ] ?? array() ) as $h ) {
				if ( ! isset( $seen[ $h['key'] ] ) && isset( $want[ $h['key'] ] ) ) {
					$seen[ $h['key'] ] = true;
					$list[]            = $h;
				}
			}
		}
		$out = array();
		foreach ( $list as $i => $h ) {
			$out[] = self::house_public( $h, chr( 65 + min( 25, $i ) ) );
		}
		return $out;
	}

	/**
	 * The picker's sets with every photo placed: each photo gets a status, a reason, its accuracy and its distance from
	 * this house (never a coordinate); each set a status and the houses to choose from. Photos of another job are
	 * taken out. A read failure leaves the sets as they were.
	 */
	public static function annotate( array $sessions, array $target, int $exclude_receipt = 0 ): array {
		$att = array();
		foreach ( $sessions as $s ) {
			foreach ( (array) ( $s['photos'] ?? array() ) as $p ) {
				if ( ! empty( $p['attachment_id'] ) ) {
					$att[] = (int) $p['attachment_id'];
				}
			}
		}
		try {
			$by_att = self::photos_for_attachments( $att );
			if ( null === $by_att ) {
				return $sessions;
			}
			$pa = self::place_all( array_values( $by_att ), $target, $exclude_receipt );
		} catch ( \Throwable $e ) {
			error_log( 'ZRCPT which house: the picker could not place photos - ' . $e->getMessage() );
			return $sessions;
		}
		$out = array();
		foreach ( $sessions as $s ) {
			$keep = array();
			$pls  = array();
			foreach ( (array) ( $s['photos'] ?? array() ) as $p ) {
				$row = $by_att[ (int) ( $p['attachment_id'] ?? 0 ) ] ?? null;
				$pl  = $row ? ( $pa['placements'][ (int) $row['media_id'] ] ?? null ) : null;
				if ( $pl && self::JOB_OTHER === $pl['status'] ) {
					continue; // another job's photo never appears here
				}
				if ( $pl ) {
					$p['place'] = array( 'status' => $pl['status'], 'why' => $pl['why'], 'acc_m' => $pl['acc_m'], 'dist_m' => $pl['dist_m'], 'filed' => isset( $pl['filed'] ) );
					$p['place_media_id'] = (int) $row['media_id'];
					$pls[]               = $pl;
				}
				$keep[] = $p;
			}
			if ( ! $keep ) {
				continue;
			}
			$s['photos']       = $keep;
			$s['photo_count']  = count( $keep );
			$s['house_status'] = self::set_status( $pls );
			$s['needs_count']  = count( array_filter( $pls, static function ( $pl ) {
				return in_array( $pl['status'], self::NEEDS_PERSON, true );
			} ) );
			$s['houses']       = self::houses_for_set( $pls, $pa['houses'], $pa['target'] ?? $target );
			$out[]             = $s;
		}
		return $out;
	}

	/**
	 * Place EVERY attachment the generator was handed and refuse the ones the system is not sure of. A photo this
	 * person uploaded for THIS customer in the receipt builder (and not from a job) is their own statement and passes;
	 * an attachment with no media record passes only when this person uploaded it. A source that cannot answer refuses
	 * everything.
	 *
	 * @return array{ok:bool,refused:array,counts:array,message:string,log:array}
	 */
	public static function gate( array $att_ids, array $target, array $vouched_media, int $exclude_receipt, int $actor_id, string $customer_id ): array {
		$att_ids = array_values( array_filter( array_unique( array_map( 'intval', $att_ids ) ) ) );
		$fail    = array( 'ok' => false, 'refused' => array( 'unchecked' => $att_ids ), 'counts' => array( 'unchecked' => count( $att_ids ) ), 'log' => array() );
		$by_att  = self::photos_for_attachments( $att_ids );
		if ( null === $by_att ) {
			return $fail + array( 'message' => __( 'The photo check could not run, so this receipt was not made. Try again in a minute.', 'zorderz' ) );
		}
		$own      = array();
		$to_place = array();
		foreach ( $by_att as $att => $p ) {
			if ( in_array( $p['source_app'], array( 'zorderz', 'receipts' ), true ) && $actor_id > 0 && $p['user_id'] === $actor_id && empty( $p['job_id'] )
				&& '' !== $customer_id && 'customer:' . $customer_id === $p['source_ref'] ) {
				$own[ $att ] = true;
				continue;
			}
			$to_place[] = $p;
		}
		$was = self::$strict;
		self::$strict = true;
		try {
			$pa = self::place_all( $to_place, $target, $exclude_receipt );
		} catch ( \Throwable $e ) {
			error_log( 'ZRCPT which house check: ' . $e->getMessage() . ' - refusing.' );
			return $fail + array( 'message' => __( 'The photo check could not ask everything it needs just now, so this receipt was not made. Try again in a minute.', 'zorderz' ) );
		} finally {
			self::$strict = $was;
		}
		$vouch   = array_flip( array_map( 'intval', $vouched_media ) );
		$refused = array();
		$counts  = array();
		$log     = array();
		foreach ( $att_ids as $att ) {
			$p = $by_att[ $att ] ?? null;
			if ( isset( $own[ $att ] ) ) {
				$log[ $att ] = 'uploaded';
				continue;
			}
			if ( ! $p ) {
				$post = function_exists( 'get_post' ) ? get_post( $att ) : null;
				if ( $post && 'attachment' === (string) ( $post->post_type ?? '' ) && $actor_id > 0 && (int) ( $post->post_author ?? 0 ) === $actor_id ) {
					$log[ $att ] = 'uploaded';
				} else {
					$refused['unchecked'][] = $att;
					$counts['unchecked']    = ( $counts['unchecked'] ?? 0 ) + 1;
					$log[ $att ]            = 'unchecked';
				}
				continue;
			}
			$pl          = $pa['placements'][ $p['media_id'] ] ?? array( 'status' => self::WHICH );
			$why         = self::gate_reason( $pl, isset( $vouch[ $p['media_id'] ] ) );
			$log[ $att ] = (string) $pl['status'];
			if ( '' !== $why ) {
				$refused[ $why ][] = $att;
				$counts[ $why ]    = ( $counts[ $why ] ?? 0 ) + 1;
			}
		}
		return array( 'ok' => ! $refused, 'refused' => $refused, 'counts' => $counts, 'log' => $log, 'message' => $refused ? self::refusal_message( $counts ) : '' );
	}

	/** The refusal in words. PURE. */
	public static function refusal_message( array $counts ): string {
		$n     = static function ( string $k ) use ( $counts ): int {
			return (int) ( $counts[ $k ] ?? 0 );
		};
		$parts = array();
		$words = array(
			'which_house' => __( '%d photo(s) still need "Which house?" answered', 'zorderz' ),
			'other_house' => __( '%d photo(s) were taken at another house with work that day', 'zorderz' ),
			'filed_other' => __( '%d photo(s) were filed to another house', 'zorderz' ),
			'other_job'   => __( '%d photo(s) were taken from a different job in the camera', 'zorderz' ),
			'off_site'    => __( '%d photo(s) are away from this address and were not confirmed with "Include anyway"', 'zorderz' ),
			'unchecked'   => __( '%d photo(s) could not be checked against the house (no photo record): pick them from your photo sets or upload them here', 'zorderz' ),
		);
		foreach ( $words as $k => $w ) {
			if ( $n( $k ) ) {
				$parts[] = sprintf( $w, $n( $k ) );
			}
		}
		return __( 'This receipt was not made: ', 'zorderz' ) . implode( '; ', $parts ) . '. ' . __( 'Open the photo sets, answer "Which house?" for each, and try again.', 'zorderz' );
	}

	/** One tap files a set to a house: the person's own photos (an administrator: any), every choice kept. */
	public static function ajax_file_photos(): void {
		check_ajax_referer( ZRCPT_Receipt::NONCE_ACTION, 'nonce' );
		$uid = (int) get_current_user_id();
		$kiosk = ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) )
			|| ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) );
		if ( $uid <= 0 || $kiosk || ! ZRCPT_Receipt::user_can_access( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorderz' ) ), 403 );
		}
		$ids = json_decode( sanitize_text_field( wp_unslash( (string) ( $_POST['media_ids'] ?? '[]' ) ) ), true );
		$ids = array_values( array_filter( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) ) );
		$key = sanitize_text_field( wp_unslash( (string) ( $_POST['house_key'] ?? '' ) ) );
		if ( ! $ids || '' === $key || count( $ids ) > 500 ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to file.', 'zorderz' ) ) );
		}
		$admin   = current_user_can( 'manage_options' ) || ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $uid ) );
		$allowed = array();
		foreach ( $ids as $mid ) {
			$row = class_exists( 'ZDZ_User_Media' ) ? ZDZ_User_Media::get_by_id( $mid ) : null;
			if ( $row && ( (int) ( $row['user_id'] ?? 0 ) === $uid || $admin ) ) {
				$allowed[] = $mid;
			}
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Those photos are not yours to file.', 'zorderz' ) ), 403 );
		}
		$house  = array(
			'key'     => $key,
			'address' => sanitize_text_field( wp_unslash( (string) ( $_POST['house_address'] ?? '' ) ) ),
			'label'   => 'none' === $key ? __( 'Neither house', 'zorderz' ) : sanitize_text_field( wp_unslash( (string) ( $_POST['house_label'] ?? '' ) ) ),
		);
		$before = array();
		foreach ( self::decisions_for( $allowed ) as $m => $d ) {
			$before[ (int) $m ] = 'filed:' . substr( (string) $d['house_key'], 0, 17 ); // read here, never from the request
		}
		$n = self::record( $allowed, $house, $uid, 'builder', $before );
		if ( $n < count( $allowed ) ) {
			wp_send_json_error( array( 'message' => __( 'That choice could not be saved. Try again.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'filed' => $n, 'skipped' => count( $ids ) - count( $allowed ) ) );
	}
}
