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
 * soundalikes onto one key for free (Casey/Kacie); Layer 2 = the curated map, which repairs the
 * pairs the key splits (Quinn/Kwin) and the short-vowel pairs the length-gated Levenshtein
 * misses (Sean/Shawn). Suggestion-grade recall ONLY: name_score() raises a record to a
 * SUGGESTION; it never auto-fills and never merges two distinct-but-confusable records
 * (honest output) — that stays with each app's strict email/phone gate.
 *
 * THE CURATED MAP IS PRIVATE DATA. A business's soundalike groups are learned from its own
 * customers' surnames, and surnames identify people far more than first names do, so no name
 * list ships with the code. The map lives in the site option `zdz_name_homophones` (never
 * autoloaded, never a file a browser can fetch), and an Identity Pack or a plugin may extend it
 * at runtime through the `zdz_name_homophones_map` filter. Core ships the generic layers only:
 * the metaphone key, the typo tolerance and the nickname table. With nothing stored, recall
 * narrows to those layers; nothing breaks.
 *
 * An older build kept the list in `name-homophones.json` beside this file. migrate_legacy_file()
 * merges such a file into the option and deletes it (run on `init` only while the file is there).
 * To restore or replace the list by hand:
 *   wp option update zdz_name_homophones --format=json --autoload=no < list.json
 *
 * PARITY: the pure helpers below are the single source of truth for the logic. An app that
 * keeps an inline fallback (the estimate app does) must keep it byte-identical — fix the logic
 * HERE and let the fallback follow, or back-port; the two must not drift.
 *
 * Zero hard WordPress dependencies (every WP call — get_option, add_option, update_option,
 * get_transient, set_transient, apply_filters — is function_exists-guarded), so the class loads
 * and its suite runs standalone under CLI.
 *
 * @package ZorderzTheme
 * @since   theme v1.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'ZDZ_Name_Match' ) ) :

class ZDZ_Name_Match {

	const VERSION = '1.1.0';

	/** Where the curated map lives: a site option, never autoloaded, never in a file that ships. */
	const OPTION = 'zdz_name_homophones';

	/** The file an older build read; only ever read to move it into OPTION, then deleted. */
	const LEGACY_FILE = 'name-homophones.json';

	/** The buckets of the map. */
	const BUCKETS = array( 'surnames', 'given_names', 'distinct_but_confusable', 'not_soundalike_flags' );

	/** While a move could not finish, it is tried at most once an hour, not on every request. */
	const WAIT_KEY = 'zdz_name_homophones_move_wait';

	/**
	 * Load the curated homophone/soundalike name map from the site's private option, memoised.
	 * Extensible via the `zdz_name_homophones_map` filter when WP is present — the seam an
	 * Identity Pack uses to add a business's own confirmed spellings. Returns [] if nothing is
	 * stored (callers then fall back to the metaphone layer: recall narrows, nothing breaks).
	 */
	public static function load_homophone_map() {
		static $map = null;
		if ( $map !== null ) return $map;
		$map = array();
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( self::OPTION, null );
			if ( is_string( $stored ) && $stored !== '' ) {
				$stored = json_decode( $stored, true );
			}
			if ( is_array( $stored ) ) $map = $stored;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'zdz_name_homophones_map', $map );
			if ( is_array( $filtered ) ) $map = $filtered;
		}
		return $map;
	}

	/** Does this look like the curated map (a list of surname groups, each with spellings)? */
	public static function is_map( $data ) {
		if ( ! is_array( $data ) || empty( $data['surnames'] ) || ! is_array( $data['surnames'] ) ) return false;
		foreach ( $data['surnames'] as $g ) {
			if ( ! is_array( $g ) || empty( $g['spellings'] ) || ! is_array( $g['spellings'] ) ) return false;
		}
		return true;
	}

	/** The key that says two entries are the same group: their spellings/names, lower-cased and sorted. */
	private static function entry_key( $entry ) {
		$names = array();
		foreach ( array( 'spellings', 'names' ) as $k ) {
			if ( isset( $entry[ $k ] ) && is_array( $entry[ $k ] ) ) {
				foreach ( $entry[ $k ] as $n ) $names[] = strtolower( trim( (string) $n ) );
			}
		}
		sort( $names );
		return implode( '|', $names );
	}

	/** $base plus every group of $add it does not already hold (never removes anything). */
	public static function merge_maps( array $base, array $add ) {
		foreach ( $add as $k => $v ) {
			if ( ! in_array( $k, self::BUCKETS, true ) ) {
				if ( ! array_key_exists( $k, $base ) ) $base[ $k ] = $v;
				continue;
			}
			$have = array();
			foreach ( ( isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) ? $base[ $k ] : array() as $e ) $have[ self::entry_key( $e ) ] = true;
			foreach ( is_array( $v ) ? $v : array() as $e ) {
				if ( ! is_array( $e ) ) continue;
				$key = self::entry_key( $e );
				if ( $key !== '' && ! isset( $have[ $key ] ) ) { $base[ $k ][] = $e; $have[ $key ] = true; }
			}
		}
		return $base;
	}

	/** How many groups a map holds, over all buckets. */
	public static function group_count( $map ) {
		$n = 0;
		foreach ( self::BUCKETS as $b ) $n += ( is_array( $map ) && isset( $map[ $b ] ) && is_array( $map[ $b ] ) ) ? count( $map[ $b ] ) : 0;
		return $n;
	}

	/**
	 * Move an older build's name-homophones.json (beside this file, or in $dir) into the private
	 * option, then delete the file. The file's groups are MERGED into whatever the option already
	 * holds (a smaller list already stored never costs the file's groups), written with autoload
	 * off, and read back; the file is deleted only once the option holds every group of it. Logs
	 * counts, never a name.
	 *
	 * @param string|null $dir Folder holding the old file (default: this file's folder).
	 * @return string What happened: 'none' | 'imported' | 'merged' | 'kept' | 'invalid' | 'failed' | 'wait'
	 */
	public static function migrate_legacy_file( $dir = null ) {
		$file = rtrim( (string) ( $dir !== null ? $dir : __DIR__ ), '/' ) . '/' . self::LEGACY_FILE;
		if ( ! is_file( $file ) || ! function_exists( 'get_option' ) ) return 'none';
		if ( function_exists( 'get_transient' ) && get_transient( self::WAIT_KEY ) ) return 'wait';
		$raw  = @file_get_contents( $file );
		$data = ( $raw !== false && $raw !== '' ) ? json_decode( $raw, true ) : null;
		if ( ! self::is_map( $data ) ) {
			if ( ! is_file( $file ) ) return 'none';   // another request finished the move a moment ago
			self::wait_and_log( 'Zorderz Name Match: the old sound-alike file is not a valid list; left in place for a person to check.' );
			return 'invalid';
		}
		$existing = get_option( self::OPTION, null );
		if ( is_string( $existing ) && $existing !== '' ) $existing = json_decode( $existing, true );
		$base   = self::is_map( $existing ) ? $existing : array();
		$merged = self::merge_maps( $base, $data );
		if ( $base && self::group_count( $merged ) === self::group_count( $base ) ) {
			$result = 'kept';                                     // the option already holds every group
		} else {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $merged, '', 'no' );
			} else {
				update_option( self::OPTION, $merged, false );
			}
			$back = get_option( self::OPTION, null );
			if ( ! self::is_map( $back ) || self::group_count( self::merge_maps( $back, $data ) ) !== self::group_count( $back ) ) {
				self::wait_and_log( 'Zorderz Name Match: could not store the sound-alike list privately; the old file was kept.' );
				return 'failed';
			}
			$result = $base ? 'merged' : 'imported';
		}
		$gone = @unlink( $file ) || ! is_file( $file );
		if ( ! $gone ) {
			self::wait_and_log( 'Zorderz Name Match: the sound-alike list is private now, but the public file could NOT be deleted - delete '
				. basename( dirname( $file ) ) . '/' . self::LEGACY_FILE . ' by hand.' );
			return $result;
		}
		if ( function_exists( 'error_log' ) ) {
			$now = get_option( self::OPTION, null );
			error_log( sprintf( 'Zorderz Name Match: sound-alike list %s (%d groups); the public file was deleted.',
				'kept' === $result ? 'already private' : 'moved into a private setting', self::group_count( $now ) ) );
		}
		return $result;
	}

	private static function wait_and_log( $msg ) {
		if ( function_exists( 'set_transient' ) ) set_transient( self::WAIT_KEY, 1, 3600 );
		if ( function_exists( 'error_log' ) ) error_log( $msg );
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
	 * auto_fill stays each caller's own email/phone decision.
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
		$d = levenshtein( $a, $b ); // -1 when either arg > 255 bytes; treat as not-close
		return $d >= 0 && $d <= $max;
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
		// Anchored prefix only ("jo" ~ "joseph", "don" ~ "donald"). A plain substring test also
		// matched "don" ~ "brandon", "ed" ~ "fred" and "ann" ~ "joanne", i.e. different people;
		// nicknames that are not prefixes ("bob" ~ "robert") come from the nickname map below.
		if ( strpos( $a, $b ) === 0 || strpos( $b, $a ) === 0 ) return true;
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
