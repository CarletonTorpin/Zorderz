<?php
/**
 * ZANA_Name_Variants — a name the question writes two ways.
 *
 * One message can name the same customer twice with two spellings (two letters swapped, a letter missing). Left to the
 * model, the two are answered as two jobs, or one of them as "not found". So the app reads the person's own message:
 * two capitalised words that are nearly the same word are most likely one name, and the model gets a one-line note
 * saying so. Which spelling is right is the records' call; nothing is searched, changed or stored here, and the words
 * in the note are the person's own, read back to them.
 *
 * WHAT COUNTS AS A PAIR. Both words start with a capital and have at least MIN_LEN letters (shorter names differ by a
 * letter too often: two first names), they are not the same word, and they are one letter apart, or the same letters
 * with two of them moved. The platform's own name matcher has the first word on what a close spelling is
 * (ZDZ_Name_Match::name_close) and the last word on what is NOT one name: a pair the business's sound-alike list
 * records as two different names (its "distinct but confusable" and "not sound-alike" groups) is never called one.
 * That list is the business's own data; Core ships none.
 *
 * WHAT IS NOT A NAME. No list of names lives here. A capitalised word is left out when it is one of the generic words
 * below (how sentences start, days, months, the words of documents and addresses), one of the business's own words
 * for the things it sells (ZDZ_Ask_Phrases::trade_words(), written from its catalog; filter
 * zana_name_variants_not_names for more), or, when the platform's word lists are loaded, an ordinary English word
 * that is nobody's name (ZDZ_Privacy_View::word_class). A word and its plain plural ("order", "orders") are one
 * word, not two spellings, unless those lists know one of them as a person's name (a surname with and without its s
 * is the commonest slip of all).
 *
 * Pure apart from those reads. The chat adds the note to a turn's instructions (never for the shared device, which
 * has no chat).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Name_Variants {

	const MIN_LEN   = 6;
	const MAX_PAIRS = 3;

	/** How many different capitalised words of one message are compared (a message is short; this bounds the work). */
	const MAX_WORDS = 60;

	/** Capitalised words that are never a name: no name, and no word of any one trade (not_names() adds the business's own). */
	const NOT_NAMES = array(
		'tomorrow', 'yesterday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
		'january', 'february', 'august', 'september', 'october', 'november', 'december',
		'please', 'thanks', 'because', 'before', 'between', 'should', 'another', 'others', 'second',
		'invoice', 'invoices', 'estimate', 'estimates', 'document', 'documents', 'record', 'records', 'report', 'reports',
		'customer', 'customers', 'project', 'projects', 'payment', 'payments', 'message', 'messages', 'number', 'numbers',
		'question', 'answer', 'search', 'charts', 'company', 'business', 'office', 'address', 'street', 'avenue',
	);

	/** Tests hand in the business's name map (the shape ZDZ_Name_Match keeps); null = the platform's own. */
	public static $map = null;

	/**
	 * Tests hand in the word check: callable( string $word ) => the array ZDZ_Privacy_View::word_class() answers, or
	 * false for "no word lists here". null = the platform's own.
	 */
	public static $word_class = null;

	/** The words that are never a name here: [ lower-case word => true ]. */
	public static function not_names(): array {
		$out = array_fill_keys( self::NOT_NAMES, true );
		if ( class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'trade_words' ) ) {
			foreach ( (array) ZDZ_Ask_Phrases::trade_words() as $phrase ) {
				foreach ( preg_split( '/[^\p{L}]+/u', mb_strtolower( (string) $phrase ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
					$out[ $w ]        = true;       // a catalog word, and how it is written for more than one
					$out[ $w . 's' ]  = true;
					$out[ $w . 'es' ] = true;
				}
			}
		}
		$more = function_exists( 'apply_filters' ) ? apply_filters( 'zana_name_variants_not_names', array() ) : array();
		foreach ( is_array( $more ) ? $more : array() as $w ) {
			if ( is_string( $w ) && '' !== trim( $w ) ) {
				$out[ mb_strtolower( trim( $w ) ) ] = true;
			}
		}
		return $out;
	}

	/** The letters of a word, one entry a letter (any alphabet). */
	private static function letters( string $w ): array {
		$c = preg_split( '//u', $w, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $c ) ? $c : str_split( $w );
	}

	/** How many single-letter edits lie between two words, counted in letters (an accented letter is one). */
	private static function distance( array $a, array $b ): int {
		$map = array();
		$enc = function ( array $letters ) use ( &$map ) {
			$s = '';
			foreach ( $letters as $l ) {
				if ( ! isset( $map[ $l ] ) ) {
					$map[ $l ] = chr( 1 + ( count( $map ) % 254 ) );
				}
				$s .= $map[ $l ];
			}
			return $s;
		};
		return levenshtein( $enc( $a ), $enc( $b ) );
	}

	/**
	 * Nearly the same word: one letter apart, or the same letters with two of them moved. Two changes that are not
	 * letters moved make two different words. When the platform's name matcher is there, it must call the two close too.
	 */
	public static function close( string $a, string $b ): bool {
		$la = self::letters( $a );
		$lb = self::letters( $b );
		if ( min( count( $la ), count( $lb ) ) < self::MIN_LEN || abs( count( $la ) - count( $lb ) ) > 1 || $a === $b ) {
			return false;
		}
		$d = self::distance( $la, $lb );
		if ( $d < 1 || $d > 2 ) {
			return false;
		}
		if ( 2 === $d ) {
			sort( $la );
			sort( $lb );
			if ( $la !== $lb ) {
				return false;
			}
		}
		if ( class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'name_close' ) ) {
			return (bool) ZDZ_Name_Match::name_close( $a, $b );
		}
		return true;
	}

	/** One word and its plain plural ("order", "orders"): one word written for one and for many. */
	private static function plural( string $a, string $b ): bool {
		return $a . 's' === $b || $b . 's' === $a || $a . 'es' === $b || $b . 'es' === $a;
	}

	/**
	 * What the platform's own word lists say of a capitalised word:
	 *   ordinary  a dictionary word or a title that is nobody's name;
	 *   name      the lists know it as a first name or a surname (an ordinary word that is also a name counts);
	 *   unknown   in no list: it may well be a name;
	 *   ''        no word lists here (the generic words above still apply).
	 */
	private static function kind( string $word ): string {
		if ( false === self::$word_class ) {
			return '';
		}
		try {
			if ( is_callable( self::$word_class ) ) {
				$r = call_user_func( self::$word_class, $word );
			} elseif ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'word_class' ) ) {
				$r = ZDZ_Privacy_View::word_class( $word );
			} else {
				return '';
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $r ) ) {
			return '';
		}
		$cls = (string) ( $r['cls'] ?? '' );
		if ( 'safe' === $cls ) {
			return 'ordinary';
		}
		if ( ! empty( $r['first'] ) || ! empty( $r['last'] ) || ! empty( $r['cf'] ) || ! empty( $r['cl'] ) ) {
			return 'name';
		}
		return in_array( $cls, array( 'ord', 'hon' ), true ) ? 'ordinary' : 'unknown';
	}

	/**
	 * Has the business recorded these two as two DIFFERENT names? Its sound-alike list keeps such pairs apart (the
	 * groups "distinct but confusable" and "not sound-alike"): one of the words in one name of a group and the other
	 * word in another name of the same group. With no list, or no such group: no.
	 */
	public static function known_distinct( string $a, string $b ): bool {
		$map = self::$map;
		if ( null === $map ) {
			$map = ( class_exists( 'ZDZ_Name_Match' ) && method_exists( 'ZDZ_Name_Match', 'load_homophone_map' ) ) ? ZDZ_Name_Match::load_homophone_map() : array();
		}
		if ( ! is_array( $map ) || $a === $b ) {
			return false;   // a word both names share is not what tells them apart
		}
		foreach ( array( 'distinct_but_confusable', 'not_soundalike_flags' ) as $bucket ) {
			foreach ( ( isset( $map[ $bucket ] ) && is_array( $map[ $bucket ] ) ) ? $map[ $bucket ] : array() as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				$in_a = array();
				$in_b = array();
				$n    = 0;
				foreach ( array( 'names', 'spellings' ) as $k ) {
					foreach ( ( isset( $group[ $k ] ) && is_array( $group[ $k ] ) ) ? $group[ $k ] : array() as $name ) {
						$words = is_scalar( $name ) ? preg_split( '/[^\p{L}\']+/u', mb_strtolower( (string) $name ), -1, PREG_SPLIT_NO_EMPTY ) : array();
						if ( in_array( $a, (array) $words, true ) ) {
							$in_a[] = $n;
						}
						if ( in_array( $b, (array) $words, true ) ) {
							$in_b[] = $n;
						}
						$n++;
					}
				}
				foreach ( $in_a as $i ) {
					foreach ( $in_b as $j ) {
						if ( $i !== $j ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * The pairs of words in a message that look like one name written two ways: [ [ first, second ], … ], each as the
	 * person wrote it, at most MAX_PAIRS. A possessive is read as its name ("Jordan's" is Jordan).
	 *
	 * @param string $message the person's own message
	 * @return array
	 */
	public static function find( $message ): array {
		$message = is_scalar( $message ) ? (string) $message : '';
		// A capitalised word: not the middle of a longer word, and not the part of a name after its apostrophe.
		if ( '' === $message || ! preg_match_all( '/(?<!\p{L})(?<!\p{L}[\'’])\p{Lu}[\p{L}\'’]{' . ( self::MIN_LEN - 1 ) . ',30}(?![\p{L}])/u', $message, $m ) ) {
			return array();
		}
		$not   = self::not_names();
		$words = array();
		foreach ( $m[0] as $w ) {
			$w = (string) preg_replace( '/^[\'’]+|[\'’]+$/u', '', $w );   // a quoted word
			$w = (string) preg_replace( '/[\'’]s$/u', '', $w );           // a possessive
			$k = mb_strtolower( $w );
			if ( mb_strlen( $k ) < self::MIN_LEN || isset( $not[ $k ] ) || isset( $words[ $k ] ) ) {
				continue;
			}
			$words[ $k ] = $w;
			if ( count( $words ) >= self::MAX_WORDS ) {
				break;
			}
		}
		$keys = array_map( 'strval', array_keys( $words ) );
		$out  = array();
		$n    = count( $keys );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$a = $keys[ $i ];
				$b = $keys[ $j ];
				if ( ! self::close( $a, $b ) ) {
					continue;
				}
				$ka = self::kind( $words[ $a ] );
				$kb = self::kind( $words[ $b ] );
				if ( 'ordinary' === $ka || 'ordinary' === $kb ) {
					continue;   // an ordinary word and its typo, or two ordinary words
				}
				if ( self::plural( $a, $b ) && 'name' !== $ka && 'name' !== $kb ) {
					continue;   // one word, for one and for many
				}
				if ( self::known_distinct( $a, $b ) ) {
					continue;   // the business's own list keeps these two apart
				}
				$out[] = array( $words[ $a ], $words[ $b ] );
				if ( count( $out ) >= self::MAX_PAIRS ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/** The app's one-line note for the model, or '' when the message has no such pair. */
	public static function note( $message ): string {
		$pairs = self::find( $message );
		if ( ! $pairs ) {
			return '';
		}
		$bits = array();
		foreach ( $pairs as $p ) {
			$bits[] = '"' . $p[0] . '" and "' . $p[1] . '"';
		}
		return "NAME SPELLINGS (a note from the app about the person's own message; not a record)\n"
			. 'The question writes ' . implode( '; ', $bits ) . ': nearly the same word, so most likely ONE name written two ways. '
			. 'Treat each pair as one person, and the jobs the question names with either spelling as the SAME job (described twice), '
			. 'unless the records clearly show two. Say in one line which spelling you used, and if neither matched, offer to look up '
			. 'the other spelling. Never report two jobs, or "not found", on a spelling alone.';
	}
}
