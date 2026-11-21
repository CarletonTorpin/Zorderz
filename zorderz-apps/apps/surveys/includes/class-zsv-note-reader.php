<?php
/**
 * Zorderz Surveys — reading a call note's send instruction in context.
 *
 * The survey operator closes a happy call with an instruction to send the review request
 * ("Very happy. She will call back if anything comes up. Send the link."). The same note often
 * carries words that normally mean "a person should read this first": a call still owed, a
 * follow-up, somebody else to reach. Whether the instruction stands is read sentence by sentence
 * and clause by clause:
 *
 *   SENTENCES. A stop typed with no space after it still ends a sentence ("again.Send the
 *   link"), so a note typed quickly reads like the same note typed carefully. Abbreviations,
 *   decimals, titles and web addresses are left whole.
 *
 *   CLAUSES. A sentence splits at a comma, a semicolon, a colon or a spaced dash, because notes
 *   are comma-joined as often as they use full stops.
 *
 *   THE INSTRUCTION. It counts only when it is not negated ("don't send the link"), sits in a
 *   clause with no attention word of its own, and that clause is not conditional ("send the link
 *   if that is ok").
 *
 *   EVERY ATTENTION WORD, ANYWHERE IN THE NOTE, must then sit in a SOFT ASIDE for the instruction
 *   to stand: a clause that only says the customer may call the business later ("will call
 *   again", "she'll call back if anything comes up", "he will get back to us"). The whole clause
 *   has to have that shape, with nothing after it but a short allowed tail. Anything else is a
 *   HARD caveat and holds the e-mail for a person wherever it sits: the business's own follow-up,
 *   try again, reschedule, come back, a call the business or a named person owes, a call back
 *   "for", "about", "to" or "when" something.
 *
 *   OPEN WORK. When an aside is excused, the note must not name unfinished work or money
 *   anywhere ("she will call back, to schedule the next visit").
 *
 * The reading is the platform's. Every word list comes from ZSV_Settings (status_vocabulary(),
 * note_reading(), negation_words()), so a business keeps its own operator's wording as data. A
 * phrasing this does not recognise lands on the safe side: a person reads the note.
 *
 * Everything here is pure: text in, a decision out.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Note_Reader {

	/** How far back (bytes) a negator is looked for, and within how many words. */
	const NEGATOR_WINDOW = 40;
	const NEGATOR_WORDS  = 4;

	/** How far after a send verb (characters, same sentence) a waiting word still belongs to it. */
	const WAIT_REACH = 40;

	/** Straight quotes for curly ones, a plain space for a hard one. */
	private static function straighten( string $text ): string {
		return str_replace(
			array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0" ),
			array( "'", "'", '"', '"', ' ' ),
			$text
		);
	}

	/** The whole note as the phrase lists are matched against it: lower case, straight quotes, single spaces. */
	public static function lower( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', strtolower( self::straighten( $text ) ) ) );
	}

	/** A list of words as one regex alternation (quoted), or '' when the list is empty. */
	private static function alt( array $words ): string {
		$q = array();
		foreach ( $words as $w ) {
			$w = (string) $w;
			if ( '' !== $w ) {
				$q[] = str_replace( ' ', '\s+', preg_quote( $w, '/' ) );
			}
		}
		return $q ? '(?:' . implode( '|', $q ) . ')' : '';
	}

	/**
	 * Put a space after a sentence stop that was typed with none ("again.Send", "Great!Send"), so
	 * the sentence split sees two sentences. Only after a word of two or more letters, so "p.m.",
	 * "a.m." and "e.g." stay whole; never after a title ("Mr.", "St.") and never before a
	 * web-address ending (".com", ".co.uk"). Numbers are untouched ("5.5").
	 */
	public static function unglue_sentences( string $text ): string {
		$r      = ZSV_Settings::note_reading();
		$behind = '';
		foreach ( $r['titles'] as $t ) {
			if ( preg_match( '/^[a-z]{1,12}$/', (string) $t ) ) {
				$behind .= '(?<!\b' . $t . ')';
			}
		}
		$ends = array();
		foreach ( $r['web_endings'] as $e ) {
			if ( preg_match( '/^[a-z0-9]{1,24}$/', (string) $e ) ) {
				$ends[] = $e;
			}
		}
		$ahead = $ends ? '(?!(?:' . implode( '|', $ends ) . ')\b)' : '';
		$out   = preg_replace( '/(?<=[A-Za-z]{2})' . $behind . '([.!?]+)(?=[A-Za-z])' . $ahead . '/i', '$1 ', $text );
		return null === $out ? $text : $out;
	}

	/** The note ready to be split: straight quotes, single spaces, a leading call date dropped, sentences un-glued. */
	public static function clean( string $text ): string {
		$clean = trim( (string) preg_replace( '/\s+/', ' ', self::straighten( $text ) ) );
		$clean = (string) preg_replace( '/^\d{1,2}\/\d{1,2}(\/\d{2,4})?\s*/', '', $clean );
		return self::unglue_sentences( $clean );
	}

	/** @return string[] The note's sentences (case kept). */
	public static function sentences( string $text ): array {
		$clean = self::clean( $text );
		$parts = preg_split( '/(?<=[.!?])\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY );
		return $parts ? $parts : array( $clean );
	}

	/**
	 * One sentence's clauses, split at a comma, a semicolon, a colon or a spaced dash ("Loves it
	 * - will call again - send the link"). A hyphen inside a word is kept. Empty pieces dropped.
	 *
	 * @return string[]
	 */
	public static function split_clauses( string $sentence ): array {
		$parts = preg_split( '/\s*[,;:]\s*|\s+(?:-{1,2}|\x{2013}|\x{2014})\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( (string) $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out ? $out : array( trim( $sentence ) );
	}

	/** Is any of $phrases in $lower? */
	public static function contains_any( string $lower, array $phrases ): bool {
		foreach ( $phrases as $p ) {
			$p = (string) $p;
			if ( '' !== $p && false !== strpos( $lower, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the match at $pos preceded by a negator within the last few words ("don't send the
	 * link", "do not ever send the link")? The look back stops at a sentence break, so a negation
	 * in one sentence never reaches into the next ("Not home. Send the link anyway.").
	 */
	public static function has_negator_before( string $lower, int $pos ): bool {
		if ( $pos <= 0 ) {
			return false;
		}
		$window = substr( $lower, max( 0, $pos - self::NEGATOR_WINDOW ), min( self::NEGATOR_WINDOW, $pos ) );
		$cut    = 0;
		foreach ( array( '.', '!', '?', ';', "\n" ) as $sep ) {
			$p = strrpos( $window, $sep );
			if ( false !== $p && $p + 1 > $cut ) {
				$cut = $p + 1;
			}
		}
		if ( $cut > 0 ) {
			$window = substr( $window, $cut );
		}
		$tokens = preg_split( '/[^a-z\']+/', $window, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) ) {
			return false;
		}
		$negators = ZSV_Settings::negation_words();
		foreach ( array_slice( $tokens, -self::NEGATOR_WORDS ) as $tok ) {
			if ( in_array( $tok, $negators, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where the first of $phrases stands NOT negated in $lower, or false.
	 *
	 * @return int|false
	 */
	public static function find_unnegated( string $lower, array $phrases ) {
		foreach ( $phrases as $phrase ) {
			$phrase = (string) $phrase;
			if ( '' === $phrase ) {
				continue; // an empty phrase would match everywhere, for ever
			}
			$offset = 0;
			while ( false !== ( $pos = strpos( $lower, $phrase, $offset ) ) ) {
				if ( ! self::has_negator_before( $lower, $pos ) ) {
					return $pos;
				}
				$offset = $pos + strlen( $phrase );
			}
		}
		return false;
	}

	/** Does this clause carry one of the conditional words ("if", "unless") as a word? */
	public static function is_conditional( string $clause_lower ): bool {
		$alt = self::alt( ZSV_Settings::note_reading()['conditions'] );
		return '' !== $alt && 1 === preg_match( '/\b' . $alt . '\b/', $clause_lower );
	}

	/**
	 * Is this clause a SOFT aside: the customer saying they may call the business later? The whole
	 * clause must be one of the three shapes (see ZSV_Settings::note_reading()), with nothing
	 * after it but one allowed tail:
	 *
	 *   a subject (she, he, they, customer, client) + an auxiliary (will, 'll, may, might, can)
	 *     + a call back (call back, call again, call us);
	 *   with no subject, only a bare aside ("will call again", "will call us");
	 *   a subject + a reply ("'ll get back to us", "will get back to us").
	 *
	 * Nothing else is soft: "will call back later", "will call when it comes in", a named person
	 * who will call back, "wants a callback", "call her back", "I'll call back", a call back "for
	 * the rest", "if it acts up again", "to schedule" all mean a call still owed or work not done,
	 * and "the owner to call back" means the account holder was not the one reached.
	 */
	public static function is_soft_callback_clause( string $clause ): bool {
		$r = ZSV_Settings::note_reading();
		$c = strtolower( trim( self::straighten( $clause ), " \t.!?\"'" ) );
		$lead = self::alt( $r['lead_ins'] );
		if ( '' !== $lead ) {
			$c = (string) preg_replace( '/^' . $lead . '\s+/', '', $c );
		}
		$c = (string) preg_replace( '/\s+/', ' ', $c );
		if ( '' === $c ) {
			return false;
		}
		$subj = self::alt( $r['subjects'] );
		$back = self::alt( $r['call_backs'] );
		$bare = self::alt( $r['bare_asides'] );
		$tail = self::alt( $r['tails'] );
		$tail = '' !== $tail ? '(?: ' . $tail . ')?' : '';
		// A piece that starts with an apostrophe ('ll) is written onto the word before it; any other follows a space.
		$joined = static function ( array $pieces ): string {
			$q = array();
			foreach ( $pieces as $p ) {
				$p = (string) $p;
				if ( '' !== $p ) {
					$q[] = ( "'" === $p[0] ? '' : ' ' ) . str_replace( ' ', '\s+', preg_quote( $p, '/' ) );
				}
			}
			return $q ? '(?:' . implode( '|', $q ) . ')' : '';
		};
		$aux   = $joined( $r['auxiliaries'] );
		$reply = $joined( $r['replies'] );
		$forms = array();
		if ( '' !== $subj && '' !== $aux && '' !== $back ) {
			$forms[] = '/^' . $subj . $aux . ' ' . $back . $tail . '$/';
		}
		if ( '' !== $bare ) {
			$forms[] = '/^' . $bare . $tail . '$/';
		}
		if ( '' !== $subj && '' !== $reply ) {
			$forms[] = '/^' . $subj . $reply . $tail . '$/';
		}
		foreach ( $forms as $re ) {
			if ( 1 === preg_match( $re, $c ) ) {
				return true;
			}
		}
		return false;
	}

	/** Does the note name unfinished work or money anywhere (a word that begins with an open_work entry)? */
	public static function mentions_open_work( string $lower ): bool {
		$alt = self::alt( ZSV_Settings::note_reading()['open_work'] );
		return '' !== $alt && 1 === preg_match( '/\b' . $alt . '/', $lower );
	}

	/**
	 * Does a sentence tell the send to WAIT on something: "send the link after the rest of the
	 * work is done", "send it once the part is fitted"? A send that waits is a hold, not a green light.
	 * "Send the link when you get a chance" does not wait on the work, and is not one.
	 */
	public static function send_waits( string $text ): bool {
		$r     = ZSV_Settings::note_reading();
		$send  = self::alt( $r['send_verbs'] );
		$waits = self::alt( $r['waits'] );
		if ( '' === $send || '' === $waits ) {
			return false;
		}
		$not = self::alt( $r['waits_not'] );
		$re  = '/\b' . $send . '\b[^.!?]{0,' . self::WAIT_REACH . '}\b' . $waits . '\s+' . ( '' !== $not ? '(?!' . $not . '\b)' : '' ) . '/';
		foreach ( self::sentences( $text ) as $sentence ) {
			if ( 1 === preg_match( $re, strtolower( $sentence ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The note's soft asides, as they are written in it (lower case, no end punctuation). Such a
	 * clause says only that the customer may call later, so it is no evidence of an open issue.
	 *
	 * @return string[]
	 */
	public static function soft_asides( string $text ): array {
		$out = array();
		foreach ( self::sentences( $text ) as $sentence ) {
			foreach ( self::split_clauses( strtolower( $sentence ) ) as $clause ) {
				if ( self::is_soft_callback_clause( $clause ) ) {
					$core = trim( $clause, " \t.!?\"'" );
					if ( '' !== $core ) {
						$out[] = $core;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Does the note carry an explicit send instruction that STANDS?
	 *
	 * @param string   $text      The note as written.
	 * @param string[] $send      The send-instruction phrases (lower case).
	 * @param string[] $attention The attention phrases (lower case).
	 * @return array{stands:bool,why:string} why: '' (it stands) | 'none' (no clean, unconditional,
	 *   un-negated instruction) | 'hard_caveat' (an attention word outside a soft aside) |
	 *   'open_work' (an aside was excused, but the note names unfinished work or money).
	 */
	public static function send_instruction( string $text, array $send, array $attention ): array {
		$sentences = self::sentences( $text );
		$found     = false;
		foreach ( $sentences as $sentence ) {
			$ls = strtolower( $sentence );
			if ( false === self::find_unnegated( $ls, $send ) ) {
				continue;
			}
			foreach ( self::split_clauses( $ls ) as $clause ) {
				if ( false !== self::find_unnegated( $clause, $send )
					&& ! self::contains_any( $clause, $attention )
					&& ! self::is_conditional( $clause ) ) {
					$found = true;
					break 2;
				}
			}
		}
		if ( ! $found ) {
			return array( 'stands' => false, 'why' => 'none' );
		}

		$soft    = ZSV_Settings::note_reading()['soft_words'];
		$excused = false;
		foreach ( $sentences as $sentence ) {
			foreach ( self::split_clauses( strtolower( $sentence ) ) as $clause ) {
				$hits = array();
				foreach ( $attention as $phrase ) {
					$phrase = (string) $phrase;
					if ( '' !== $phrase && false !== strpos( $clause, $phrase ) ) {
						$hits[] = $phrase;
					}
				}
				if ( ! $hits ) {
					continue;
				}
				// Soft only when every word found may be soft AND the clause has the shape.
				if ( array_diff( $hits, $soft ) || ! self::is_soft_callback_clause( $clause ) ) {
					return array( 'stands' => false, 'why' => 'hard_caveat' );
				}
				$excused = true;
			}
		}
		if ( $excused && self::mentions_open_work( self::lower( $text ) ) ) {
			return array( 'stands' => false, 'why' => 'open_work' );
		}
		return array( 'stands' => true, 'why' => '' );
	}
}
