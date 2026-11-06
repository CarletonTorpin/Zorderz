<?php
/**
 * ZRP_Vocab -- from what the driver said to suggested tags and a scope.
 *
 * Deterministic: phrases from a closed vocabulary, matched as whole words. Nothing here is a model, and nothing here
 * decides: the tags it finds are SUGGESTIONS that a person confirms -- by saying "keep that" right after the
 * note, or on the Review list once the car has stopped. The note is always kept as said (privacy-scrubbed).
 *
 *   vocabulary()      slug => {label, product, product_label, phrases}: the built-in list (zdz-route-planner-names.php,
 *                     filter `zrp_default_vocabulary`) plus what an administrator added in settings
 *   parse( $said )    {tags, negated, scope, side}: scope is house | side | block | street | segment (the default);
 *                     side is left | right | '' (unsaid: the driver's right, the curb they are looking at)
 *   scrub( $said, $scope )  the note as kept: no e-mail, phone, licence-plate or long number shapes (a house number only
 *                     when the speaker said "this house") -- notes describe homes, never people
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Vocab {

	/**
	 * a bare mention ("pools") means at least one home where it was said: 'here'.
	 * "this block" / "the whole street" are said for that block or street; "the whole neighborhood", "same house model",
	 * "every house", "they all" are 'neighborhood': said for the street it was said on, and the seed that a nearby
	 * street saying the same corroborates (ZRP_Verb). 'segment' is the oldest default, kept for its notes.
	 */
	const SCOPES   = array( 'here', 'house', 'side', 'block', 'street', 'neighborhood', 'segment' );
	const FLAGS    = array( 'hard_to_see', 'ask', 'code' );
	const MAX_SAID = 500;
	const NEGATORS = '(?:no|not|none|without|never|zero|isn\'?t|aren\'?t|doesn\'?t|don\'?t|hasn\'?t|haven\'?t|nothing)';

	/** A slug: lower case, a-z 0-9 and _, 2-40 long; '' when it isn't one. */
	public static function slug( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/[^a-z0-9]+/', '_', $s );
		$s = trim( $s, '_' );
		return ( strlen( $s ) >= 2 && strlen( $s ) <= 40 ) ? $s : '';
	}

	private static function clean_phrase( $p ) {
		$p = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $p ) ) );
		$p = preg_replace( '/[^a-z0-9\' ]/', '', $p );
		return ( '' !== $p && strlen( $p ) <= 60 ) ? $p : '';
	}

	/**
	 * The administrator's additions, one per line: "slug | label | product slug | product label | phrase, phrase".
	 * A slug that exists already gets the extra phrases (and a new label/product if given). Bad lines are skipped.
	 */
	public static function parse_extra( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$p    = array_map( 'trim', explode( '|', $line ) );
			$slug = self::slug( $p[0] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$phr = array();
			foreach ( explode( ',', (string) ( $p[4] ?? '' ) ) as $ph ) {
				$ph = self::clean_phrase( $ph );
				if ( '' !== $ph ) {
					$phr[] = $ph;
				}
			}
			$out[ $slug ] = array(
				'label'         => mb_substr( sanitize_text_field( (string) ( $p[1] ?? '' ) ), 0, 60 ),
				'product'       => self::slug( $p[2] ?? '' ),
				'product_label' => mb_substr( sanitize_text_field( (string) ( $p[3] ?? '' ) ), 0, 60 ),
				'phrases'       => array_slice( array_values( array_unique( $phr ) ), 0, 30 ),
			);
			if ( count( $out ) >= 60 ) {
				break;
			}
		}
		return $out;
	}

	/** slug => {label, product, product_label, phrases}. */
	public static function vocabulary() {
		$raw = (array) apply_filters( 'zrp_default_vocabulary', function_exists( 'zrp_builtin_vocabulary' ) ? zrp_builtin_vocabulary() : array() );
		$v   = array();
		foreach ( $raw as $slug => $row ) {
			$slug = self::slug( $slug );
			if ( '' === $slug || ! is_array( $row ) ) {
				continue;
			}
			$phr = array();
			foreach ( (array) ( $row[3] ?? array() ) as $ph ) {
				$ph = self::clean_phrase( $ph );
				if ( '' !== $ph ) {
					$phr[] = $ph;
				}
			}
			$flags   = array_values( array_intersect( array_map( 'strval', (array) ( $row[4] ?? array() ) ), self::FLAGS ) );
			$choices = array_values( array_filter( array_map( array( __CLASS__, 'slug' ), (array) ( $row[5] ?? array() ) ) ) );
			$v[ $slug ] = array( 'label' => (string) ( $row[0] ?? $slug ), 'product' => self::slug( $row[1] ?? '' ), 'product_label' => (string) ( $row[2] ?? '' ), 'phrases' => array_values( array_unique( $phr ) ), 'flags' => $flags, 'choices' => array_slice( $choices, 0, 4 ) );
		}
		$extra = self::parse_extra( function_exists( 'zrp_get_settings' ) ? ( zrp_get_settings()['vocab_extra'] ?? '' ) : '' );
		foreach ( $extra as $slug => $e ) {
			if ( isset( $v[ $slug ] ) ) {
				$v[ $slug ]['phrases'] = array_values( array_unique( array_merge( $v[ $slug ]['phrases'], $e['phrases'] ) ) );
				if ( '' !== $e['label'] ) { $v[ $slug ]['label'] = $e['label']; }
				if ( '' !== $e['product'] ) { $v[ $slug ]['product'] = $e['product']; $v[ $slug ]['product_label'] = $e['product_label']; }
			} elseif ( $e['phrases'] ) {
				$v[ $slug ] = array( 'label' => '' !== $e['label'] ? $e['label'] : $slug, 'product' => $e['product'], 'product_label' => $e['product_label'], 'phrases' => $e['phrases'], 'flags' => array(), 'choices' => array() );
			}
		}
		return $v;
	}

	/** Words as matched: lower case, apostrophes kept, clause marks (,. ;) kept as their own tokens, the rest a space. */
	public static function words( $s ) {
		$s = strtolower( (string) $s );
		$s = str_replace( array( "\xE2\x80\x99", '`' ), "'", $s );
		$s = preg_replace( '/([,.;!?])/', ' $1 ', $s );
		$s = preg_replace( '/[^a-z0-9\',.;!? ]+/', ' ', $s );
		$s = str_replace( array( '!', '?' ), '.', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	private static function phrase_re( $ph ) {
		$parts = array_map( function ( $w ) { return preg_quote( $w, '/' ) . '(?:e?s)?'; }, explode( ' ', $ph ) );
		return '/(?<![a-z0-9\'])' . implode( ' ', $parts ) . '(?![a-z0-9\'])/';
	}

	/** Where the clause holding position $at starts: after the last,.; or "but / and / has / have / with / plus". */
	private static function clause_start( $t, $at ) {
		$before = substr( $t, 0, $at );
		$best   = 0;
		if ( preg_match_all( '/(?:^| )([,.;]|but|and|has|have|had|with|plus|also|except) /', $before, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $i => $hit ) {
				$word = $m[1][ $i ][0];
				if ( in_array( $word, array( 'has', 'have', 'had' ), true ) ) {
					// "doesn't have a pool": a "have" right after a negator belongs to it, not a new clause
					$prev = trim( substr( $before, 0, $hit[1] ) );
					if ( preg_match( '/(?:^| )' . self::NEGATORS . '$/', $prev ) ) {
						continue;
					}
				}
				$best = $hit[1] + strlen( $hit[0] );
			}
		}
		return $best;
	}

	/** {tags: [slug...], negated: [slug...], scope, side}. */
	public static function parse( $said, $vocab = null ) {
		$vocab = is_array( $vocab ) ? $vocab : self::vocabulary();
		$t     = self::words( $said );
		$out   = array( 'tags' => array(), 'negated' => array(), 'scope' => 'here', 'side' => '' );
		if ( '' === $t ) {
			return $out;
		}
		// every phrase hit; the longest wins where two overlap ("no soliciting sign" is one hit, "gated" in "gated community" none)
		$hits = array();
		foreach ( $vocab as $slug => $row ) {
			foreach ( $row['phrases'] as $ph ) {
				if ( preg_match_all( self::phrase_re( $ph ), $t, $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $hit ) {
						$hits[] = array( 'slug' => $slug, 'at' => $hit[1], 'len' => strlen( $hit[0] ), 'phrase' => $ph );
					}
				}
			}
		}
		usort( $hits, function ( $a, $b ) { return $b['len'] - $a['len'] ?: $a['at'] - $b['at']; } );
		$kept = array();
		foreach ( $hits as $h ) {
			foreach ( $kept as $r ) {
				if ( $h['at'] < $r['at'] + $r['len'] && $h['at'] + $h['len'] > $r['at'] ) { continue 2; }
			}
			$kept[] = $h;
		}
		usort( $kept, function ( $a, $b ) { return $a['at'] - $b['at']; } );
		// a negator counts only inside the hit's own clause, and never across an earlier tag
		// ("No HOA, pools ...", "nothing but pools", "without exception has solar panels")
		$tags = array();
		$neg  = array();
		$prev_end = 0;
		foreach ( $kept as $h ) {
			$from   = max( $prev_end, self::clause_start( $t, $h['at'] ) );
			$before = trim( substr( $t, $from, $h['at'] - $from ) );
			$prev_end = $h['at'] + $h['len'];
			if ( '' !== $before && preg_match( '/(?:^| )' . self::NEGATORS . '(?: [a-z0-9\']+){0,3}$/', $before ) ) {
				$neg[ $h['slug'] ] = true;
				continue;
			}
			$tags[ $h['slug'] ] = true;
		}
		foreach ( array_keys( $neg ) as $s ) {
			if ( isset( $tags[ $s ] ) ) { unset( $neg[ $s ] ); } // said both ways: the positive mention stands, the person confirms
		}
		$out['tags']    = array_keys( $tags );
		$out['negated'] = array_keys( $neg );
		// the scope the speaker said; the narrowest wins when two are said
		$w = ' ' . preg_replace( '/ [,.;]/', '', $t ) . ' ';
		if ( preg_match( '/ (?:this|that) (?:house|home)(?: on the (?:left|right))? /', $w ) || preg_match( '/ (?:the )?house on the (?:left|right) /', $w ) ) {
			$out['scope'] = 'house';
		} elseif ( preg_match( '/ (?:this|that|my|our|the (?:left|right)) side /', $w ) || preg_match( '/ (?:left|right) side /', $w ) || preg_match( '/ on (?:the|my) (?:left|right) /', $w ) ) {
			$out['scope'] = 'side';
		} elseif ( preg_match( '/ (?:this|the|whole|entire) (?:whole |entire )?block /', $w ) ) {
			$out['scope'] = 'block';
		} elseif ( preg_match( '/ (?:this|the|whole|entire) (?:whole |entire )?(?:street|road|avenue|court|lane|cul de sac) /', $w ) ) { // not "way" or "drive": "all the way down", "the drive"
			$out['scope'] = 'street';
		} elseif ( preg_match( '/ (?:this|the|whole|entire) (?:whole |entire )?(?:neighbou?rhood|tract|development|subdivision|community|area) /', $w )
			|| preg_match( '/ same (?:house |home )?(?:model|floor ?plan|builder|design|style) /', $w )
			|| preg_match( '/ (?:every|each) (?:single )?(?:house|home|one) /', $w )
			|| preg_match( '/ all (?:the |these |those |of the |of these )?(?:houses|homes) /', $w )
			|| preg_match( '/ (?:they|these|those) all /', $w ) || preg_match( '/ all of them /', $w ) ) {
			$out['scope'] = 'neighborhood'; // said for this street; a nearby street saying the same corroborates it
		}
		if ( preg_match( '/ (?:on the|to the|the|my) left | left side /', $w ) ) {
			$out['side'] = 'left';
		} elseif ( preg_match( '/ (?:on the|to the|the|my) right | right side /', $w ) ) {
			$out['side'] = 'right';
		}
		return $out;
	}

	/**
	 * "Scratch that" / "delete that" is read in context: how much it takes back depends on what was said. Live dictation
	 * has no model at hand while the car moves, so the rule is the narrow one: a correction
	 * INSIDE a sentence ("the whole street has pools, no wait, just this side") takes back only the words before it in
	 * that sentence; a correction said on its own takes back the last note (the page's "scratch that" command). Either
	 * way the words are kept as 'retracted' for an Undo. A recorded drive is read by the transcription service's model
	 * with the whole drive in view, and it decides how much goes.
	 * [kept, removed]: removed '' when there was no correction; kept '' when nothing came after it.
	 */
	const CORRECTION = '/\b(?:scratch that|delete that|cancel that|strike that|never ?mind|no wait|wait no|i mean|correction|actually no|ignore that|erase that|disregard that)\b[\s,.;:!-]*/i';

	public static function correct( $said ) {
		$parts = preg_split( self::CORRECTION, (string) $said );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return array( trim( (string) $said ), '' );
		}
		$kept    = trim( (string) array_pop( $parts ), " \t,.;" );
		$removed = trim( preg_replace( '/\s+/', ' ', implode( ' ', $parts ) ), " \t,.;" );
		return array( preg_match( '/[a-z0-9]/i', $kept ) ? $kept : '', $removed );
	}

	/**
	 * The note as kept. Out, always: e-mail addresses; any run of 7 or more digits (a phone, spaced
	 * or not); licence plates (1ABC234, ABC1234, ABC 123, 7 XYZ 123, and whatever follows "plate"/"license"); names after
	 * Mr / Mrs / Ms / Miss / Dr; "the Garcias"; "Lopez's house", "the Smith family". Numbers: none, except in a "this
	 * house" note, where only $house (the map's number for the snapped house) may stay. A sentence-leading capital is
	 * not a name.
	 */
	public static function scrub( $said, $scope = 'here', $house = '' ) {
		$s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $said ) ) );
		$s = mb_substr( $s, 0, self::MAX_SAID );
		$s = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[removed]', $s );
		$s = preg_replace( '/\b(plate|licen[cs]e(?: plate)?)(?: number)?[\s:#]+(?:[A-Z0-9]{1,8}[\s-]?){1,3}/i', '$1 [removed]', $s );
		$s = preg_replace( '/\b(?:\d[\s.()-]{0,2}){6,}\d\b/', '[removed]', $s );                 // 7+ digits: a phone
		$s = preg_replace( '/\b\d\s?[A-Z]{3}\s?\d{3}\b/', '[removed]', $s );                    // 1ABC234, 7 XYZ 123
		$s = preg_replace( '/\b[A-Z]{2,3}[\s-]?\d{3,4}\b/', '[removed]', $s );                     // ABC1234, ABC 123
		$s = preg_replace( '/\b(?:Mr|Mrs|Ms|Miss|Mx|Dr)\.?\s+[A-Z][a-zA-Z\'-]+/', '[removed]', $s );
		$s = preg_replace( '/\b(the|The)\s+[A-Z][a-z]{2,}s\b(?!\s+(?:St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Way|Ct|Court|Ln|Lane)\b)/', '$1 [removed]', $s );
		$s = preg_replace( '/(?<=[a-z,;] )[A-Z][a-z]{2,}(?:\'s|s\'|s)?(?=\s+(?:family|house|home|place|kids|car|truck|dog|residence|yard)\b)/', '[removed]', $s );
		$s = preg_replace( '/^[A-Z][a-z]{2,}(?:\'s|s\')(?=\s+(?:family|house|home|place|kids|car|truck|dog|residence|yard)\b)/', '[removed]', $s );
		$keep = ( 'house' === $scope ) ? preg_replace( '/[^0-9]/', '', (string) $house ) : '';
		$s = preg_replace_callback( '/\b\d+\b/', function ( $m ) use ( $keep ) { return ( '' !== $keep && $m[0] === $keep ) ? $m[0] : '[number]'; }, $s );
		// a number said in words is a number too -- three or more number words in a row (a code
		// the hearing missed, "four five six seven"; a phone said aloud) become [number]
		$nw = '(?:zero|oh|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand|pound|star|\[number\])';
		$s = preg_replace( '/(?<![a-z])' . $nw . '(?:[\s,.-]+' . $nw . '){2,}(?![a-z])/i', '[number]', $s );
		return trim( $s );
	}

}
