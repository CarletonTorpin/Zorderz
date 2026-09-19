<?php
/**
 * Zorderz Name Match — shared soundalike/homophone name matcher (pure PHP).
 *
 * The canonical, platform-wide implementation of name-recall. Every consumer that HEARS or
 * LOOKS UP a person/customer name (the estimate app, the assistant, Prep, Surveys, Receipt)
 * calls these statics instead of carrying its own copy, so a spelling learned once reaches
 * every app the same way.
 *
 * Two layers behind sounds_like(): Layer 1 = metaphone key (name_sound_key), which folds most
 * soundalikes onto one key for free; Layer 2 = the curated map (name-homophones.json beside
 * this file), which repairs the pairs the key splits and the short-vowel pairs the length-gated
 * Levenshtein misses. Suggestion-grade recall ONLY: name_score() raises a record to a
 * SUGGESTION; it never auto-fills and never merges two distinct-but-confusable records
 * (INV-12) — that stays with each app's strict email/phone gate.
 *
 * IDENTITY: this class is pure mechanism and carries no business data. The shipped
 * name-homophones.json is a neutral, general-English seed with NO tenant records; a business's
 * own confirmed spelling corrections layer in at runtime through the `zdz_name_homophones_map`
 * filter (an Identity Pack consumer populates it — empty by default, so a fresh install ships
 * with general-English recall only and never any customer's name).
 *
 * PARITY: the pure helpers below are the single source of truth for the logic. An app that
 * keeps an inline fallback (the estimate app does) must keep it byte-identical — fix the logic
 * HERE and let the fallback follow, or back-port; the two must not drift.
 *
 * Zero hard WordPress dependencies (the one WP reference, apply_filters in load_homophone_map,
 * is function_exists-guarded), so the class loads and its suite runs standalone under CLI.
 *
 * @package ZorderzTheme
 * @since   theme v1.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ZDZ_Name_Match' ) ) :

class ZDZ_Name_Match {

	const VERSION = '1.0.0';

	/**
	 * Load the curated homophone/soundalike name map (name-homophones.json beside this
	 * file), memoised. Extensible via the `zdz_name_homophones_map` filter when WP is present —
	 * this is the seam an Identity Pack uses to add a tenant's own confirmed spellings.
	 * Returns [] if the asset is missing (callers then fall back to the metaphone layer).
	 */
	public static function load_homophone_map() {
		static $map = null;
		if ( $map !== null ) return $map;
		$raw  = @file_get_contents( __DIR__ . '/name-homophones.json' );
		$data = ( $raw !== false && $raw !== '' ) ? json_decode( $raw, true ) : null;
		$map  = is_array( $data ) ? $data : array();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'zdz_name_homophones_map', $map );
			if ( is_array( $filtered ) ) $map = $filtered;
		}
		return $map;
	}

	/**
	 * The canonical "names sound alike" instruction for a parse/transcription LLM prompt.
	 * Apps that HEAR a name (dictation, transcribed calls) inject this so every parser
	 * chooses a spelling the same way. Apps that only LOOK UP a name use the matcher below.
	 */
	public static function parse_rule() {
		return "NAMES SOUND ALIKE - DO NOT LOCK A SPELLING FROM THE SOUND ALONE. Many names have several valid spellings that sound identical (Jon/John, Sean/Shawn, Smith/Smyth, Reed/Reid, Reece/Reese). When you are NOT given the letters spelled out, transcribe the name as heard but treat the spelling as PROVISIONAL: prefer the spelling that matches the person's own email local-part (the email is the field the customer typed themselves); and if the sound matches an EXISTING client/contact on the record, use that record's spelling. NEVER merge two people or businesses just because their names sound alike. When genuinely unsure, keep the heard spelling and add [AMBIGUOUS: sounds like X; could be spelled Y or Z].";
	}

	/** Public alias: every curated spelling that shares a sound-group with $name ([] if none). */
	public static function soundalike_variants( $name ) {
		return self::homophone_variants( $name );
	}

	/**
	 * Full name-match score for one candidate: the pure surname/first-name points PLUS the
	 * additional soundalike points, in one call. >= 5 means "surface as a suggestion".
	 * auto_fill stays each caller's own email/phone decision (INV-12).
	 */
	public static function name_score( $cand_fname, $cand_lname, $search_fname_norm, $search_lname_lower ) {
		return self::name_match_points( $cand_fname, $cand_lname, $search_fname_norm, $search_lname_lower )
		     + self::homophone_points( $cand_fname, $cand_lname, $search_fname_norm, $search_lname_lower );
	}

	public static function name_close( $a, $b ) {
		$a = strtolower( trim( (string) $a ) );
		$b = strtolower( trim( (string) $b ) );
		if ( $a === '' || $b === '' ) return false;
		$max = ( strlen( $a ) >= 6 || strlen( $b ) >= 6 ) ? 2 : 1;
		if ( abs( strlen( $a ) - strlen( $b ) ) > $max ) return false;
		return levenshtein( $a, $b ) <= $max;
	}

	public static function nickname_root( $name ) {
		static $map = array(
			'mike' => 'michael', 'mikey' => 'michael', 'mick' => 'michael',
			'bob' => 'robert', 'rob' => 'robert', 'bobby' => 'robert', 'robbie' => 'robert',
			'bill' => 'william', 'will' => 'william', 'billy' => 'william', 'liam' => 'william',
			'jim' => 'james', 'jimmy' => 'james', 'jamie' => 'james',
			'joe' => 'joseph', 'joey' => 'joseph',
			'tom' => 'thomas', 'tommy' => 'thomas',
			'dave' => 'david', 'davey' => 'david',
			'dan' => 'daniel', 'danny' => 'daniel',
			'dick' => 'richard', 'rick' => 'richard', 'ricky' => 'richard', 'rich' => 'richard',
			'steve' => 'steven', 'stevie' => 'steven',
			'chris' => 'christopher', 'ken' => 'kenneth', 'kenny' => 'kenneth',
			'ed' => 'edward', 'eddie' => 'edward', 'ted' => 'edward',
			'tony' => 'anthony', 'nick' => 'nicholas', 'matt' => 'matthew',
			'greg' => 'gregory', 'jeff' => 'jeffrey', 'ron' => 'ronald', 'don' => 'donald',
			'sam' => 'samuel', 'ben' => 'benjamin', 'andy' => 'andrew', 'drew' => 'andrew',
			'pat' => 'patrick', 'gabe' => 'gabriel', 'phil' => 'philip', 'fred' => 'frederick',
			'kathy' => 'katherine', 'kate' => 'katherine', 'katie' => 'katherine', 'cathy' => 'catherine',
			'liz' => 'elizabeth', 'beth' => 'elizabeth', 'betty' => 'elizabeth', 'sue' => 'susan',
			'peggy' => 'margaret', 'meg' => 'margaret', 'maggie' => 'margaret',
			'jen' => 'jennifer', 'jenny' => 'jennifer', 'becky' => 'rebecca',
			'deb' => 'deborah', 'debbie' => 'deborah', 'val' => 'valerie',
		);
		$n = strtolower( trim( (string) $name ) );
		return isset( $map[ $n ] ) ? $map[ $n ] : $n;
	}

	public static function first_names_equivalent( $a, $b ) {
		$a = strtolower( trim( (string) $a ) );
		$b = strtolower( trim( (string) $b ) );
		if ( $a === '' || $b === '' ) return false;
		if ( $a === $b ) return true;
		if ( strpos( $a, $b ) !== false || strpos( $b, $a ) !== false ) return true;
		if ( self::nickname_root( $a ) === self::nickname_root( $b ) ) return true;
		return self::name_close( $a, $b );
	}

	public static function name_sound_key( $name ) {
		$n = strtolower( trim( preg_replace( '/[^A-Za-z ]/', '', (string) $name ) ) );
		if ( $n === '' ) return '';
		$k = metaphone( $n );
		return is_string( $k ) ? $k : '';
	}

	public static function homophone_variants( $name ) {
		$n = strtolower( trim( (string) $name ) );
		if ( $n === '' ) return array();
		$map = self::load_homophone_map();
		$out = array();
		foreach ( array( 'surnames', 'given_names' ) as $bucket ) {
			if ( empty( $map[ $bucket ] ) || ! is_array( $map[ $bucket ] ) ) continue;
			foreach ( $map[ $bucket ] as $group ) {
				$spellings = ( isset( $group['spellings'] ) && is_array( $group['spellings'] ) ) ? $group['spellings'] : array();
				$lower = array();
				foreach ( $spellings as $s ) { $lower[] = strtolower( trim( (string) $s ) ); }
				if ( in_array( $n, $lower, true ) ) {
					foreach ( $lower as $s ) { if ( $s !== '' && $s !== $n ) $out[ $s ] = true; }
				}
			}
		}
		return array_keys( $out );
	}

	public static function sounds_like( $a, $b ) {
		$a = strtolower( trim( (string) $a ) );
		$b = strtolower( trim( (string) $b ) );
		if ( $a === '' || $b === '' ) return false;
		if ( $a === $b ) return true;
		if ( strpos( $a, $b ) !== false || strpos( $b, $a ) !== false ) return true;
		if ( self::name_close( $a, $b ) ) return true;
		if ( in_array( $b, self::homophone_variants( $a ), true ) ) return true;
		$ka = self::name_sound_key( $a );
		return $ka !== '' && $ka === self::name_sound_key( $b );
	}

	public static function homophone_points( $cand_fname, $cand_lname, $search_fname_norm, $search_lname_lower ) {
		$cf = str_replace( array( ' ', '&' ), '', strtolower( trim( (string) $cand_fname ) ) );
		$cl = strtolower( trim( (string) $cand_lname ) );
		$sf = (string) $search_fname_norm;
		$sl = (string) $search_lname_lower;
		$pts = 0;
		$surname_already = ( $sl !== '' && ( strpos( $cl, $sl ) !== false || self::name_close( $cl, $sl ) ) );
		if ( $sl !== '' && ! $surname_already && self::sounds_like( $cl, $sl ) ) {
			$pts += 4;
		}
		if ( $sf !== '' && $cf !== '' && ! self::first_names_equivalent( $cf, $sf ) && self::sounds_like( $cf, $sf ) ) {
			$pts += 10;
		}
		return $pts;
	}

	public static function name_match_points( $cand_fname, $cand_lname, $search_fname_norm, $search_lname_lower ) {
		$cf_norm = str_replace( array( ' ', '&' ), '', strtolower( trim( (string) $cand_fname ) ) );
		$cl      = strtolower( trim( (string) $cand_lname ) );
		$sf      = (string) $search_fname_norm;
		$sl      = (string) $search_lname_lower;
		$points  = 0;
		if ( $sl !== '' ) {
			if ( strpos( $cl, $sl ) !== false ) {
				$points += 5;
			} elseif ( self::name_close( $cl, $sl ) ) {
				$points += 4;
			}
		}
		if ( $sf !== '' && $cf_norm !== '' && self::first_names_equivalent( $cf_norm, $sf ) ) {
			$points += 10;
		}
		return $points;
	}

}

endif;
