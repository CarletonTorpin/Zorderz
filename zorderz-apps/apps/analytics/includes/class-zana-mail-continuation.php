<?php
/**
 * ZANA_Mail_Continuation — a "yes / widen it" reply to a MAILBOX answer. A pure detector.
 *
 * The chat answers a mail question — "how many pallets have we ordered?" → "📬 …none in your indexed mail — want me to
 * widen the search?" — and the person replies "yes". That reply carries no mail signal of its own, so without this it
 * would fall through to the billing records. The person asked to widen the MAILBOX search.
 *
 * detect() fires only when BOTH hold:
 *   (1) the immediately preceding ASSISTANT turn is a mailbox answer — the 📬 envelope every inbox answer starts with,
 *       together with an "indexed mail" / "your mail(box)" / "widen the search" phrase, so a stray envelope elsewhere
 *       cannot trip it; AND
 *   (2) THIS turn reads as a short affirmative or "widen" ("yes", "widen it", "look again") and introduces no new data
 *       scope (a month, a year, an amount, a #number or a billing word makes it a fresh question).
 * On fire it recovers the topic from the most recent REAL (non-affirmative) question, without its counting words, and
 * the chat searches the mailbox again with it — the model is not asked.
 *
 * SAFETY BY CONSTRUCTION: it fires only after a mailbox answer (itself the asker's own mail, never on the shared
 * device), and only on a turn no other mail or message route claimed. At worst it repeats a body-free search of the
 * asker's own mail — never a billing read, never someone else's mail. A long message, a new scope or a billing word
 * all decline, so a genuine new question is never trapped here.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Continuation {

	/** The mailbox render signature: the leading envelope every inbox answer (ZIB_ZANA_Bridge) starts with. */
	const MAIL_RENDER_SIGNATURE = '📬';

	/** A message longer than this is a fresh instruction, never a bare "yes / widen". */
	const MAX_CONTINUATION_LEN = 80;

	/**
	 * Should this turn WIDEN the previous mailbox search?
	 *
	 * @param string $user_message This turn's verbatim text.
	 * @param array  $history      Prior turns (NOT incl. this one), each { role, content }.
	 * @return array { fire:bool, query:string, reason:string }
	 */
	public static function detect( string $user_message, array $history ): array {
		$no = array( 'fire' => false, 'query' => '', 'reason' => '' );

		if ( empty( $history ) || ! is_array( $history ) ) {
			return $no;
		}

		// (1) The immediately-preceding ASSISTANT turn must be a mailbox render.
		$prev_bot = '';
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'assistant' === (string) ( $history[ $i ]['role'] ?? '' ) ) {
				$prev_bot = (string) ( $history[ $i ]['content'] ?? '' );
				break;
			}
		}
		if ( '' === $prev_bot || ! self::is_mailbox_render( $prev_bot ) ) {
			return $no;
		}

		// (2) THIS turn must read as a short affirmative / widen continuation …
		if ( ! self::is_affirmative_or_widen( $user_message ) ) {
			return $no;
		}
		// … and must NOT introduce a fresh data scope (then it's a new question, not a widen).
		if ( self::introduces_new_scope( $user_message ) ) {
			return $no;
		}

		// Recover the topic to widen from the most recent REAL question.
		$topic = self::recover_topic( $history );
		if ( '' === $topic ) {
			return $no;
		}

		return array(
			'fire'   => true,
			'query'  => $topic,
			'reason' => 'widen_mailbox_continuation',
		);
	}

	/**
	 * Does $text carry the mailbox render signature? The envelope AND an "indexed mail" /
	 * "your mail(box)" / "widen the search" phrase — both, so a stray 📬 can't trip it. Pure.
	 */
	public static function is_mailbox_render( string $text ): bool {
		if ( '' === $text || false === strpos( $text, self::MAIL_RENDER_SIGNATURE ) ) {
			return false;
		}
		$t = strtolower( $text );
		return ( false !== strpos( $t, 'indexed mail' ) )
			|| ( false !== strpos( $t, 'your mail' ) )       // covers "your mail" and "your mailbox"
			|| ( false !== strpos( $t, 'your mailbox' ) )
			|| ( false !== strpos( $t, 'widen the search' ) );
	}

	/** A short "yes" / "please do" / "widen it" / "look again" continuation. Pure. */
	public static function is_affirmative_or_widen( string $msg ): bool {
		$m = strtolower( trim( $msg ) );
		if ( '' === $m || strlen( $m ) > self::MAX_CONTINUATION_LEN ) {
			return false;
		}
		// Widen / look-again verbs anywhere in a short message.
		if ( preg_match( '/\b(widen|broaden|expand|wider|broader|look\s+again|search\s+again|try\s+again|check\s+again|look\s+harder|search\s+(?:more|wider|broader|deeper)|keep\s+looking|dig\s+deeper|cast\s+a\s+wider\s+net|more\s+broadly|anything\s+else|look\s+everywhere|all\s+(?:my\s+)?mail)\b/', $m ) ) {
			return true;
		}
		// Leading affirmative (exact, or word-boundary head so a clause may follow: "yes, widen it").
		$affirm = array(
			// yes + its common truncations/typos (a fumbled "yE"/"ya" is still a yes)
			'yes please', 'yes', 'yea', 'yeah', 'yep', 'yup', 'ya', 'ye', 'ys', 'y',
			'sure', 'sure thing', 'ok', 'okay', 'k', 'kk',
			'please do', 'please', 'go ahead', 'go for it', 'do it', 'do that', 'do so',
			'sounds good', 'proceed', 'absolutely', 'definitely', 'go on',
		);
		foreach ( $affirm as $a ) {
			if ( $m === $a || $m === $a . '.' || $m === $a . '!'
				|| 0 === strpos( $m, $a . ' ' ) || 0 === strpos( $m, $a . ',' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A new month/year/$amount/#number/zip/"vs" OR a CRM/billing word → a fresh question, so
	 * NOT a pure "widen the mail search". Pure.
	 */
	public static function introduces_new_scope( string $msg ): bool {
		return (bool) (
			preg_match( '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|january|february|march|april|june|july|august|september|october|november|december|q[1-4]|quarter|ytd|mtd|last\s+month|this\s+month|last\s+year|this\s+year|today|yesterday)\b/i', $msg )
			|| preg_match( '/\b(vs|versus|compared?\s+to|compare)\b/i', $msg )
			|| preg_match( '/[#$]\s?\d/', $msg )
			|| preg_match( '/\b\d{5}\b/', $msg )
			|| preg_match( '/\b(revenue|profit|margin|invoice|estimate|billing|accounting|crm|unpaid|overdue|receivable|commission|sold|sales)\b/i', $msg )
		);
	}

	/**
	 * Walk back through history to the most recent USER turn that is a REAL question (not itself a
	 * bare affirmative/widen), and strip the quantitative framing so what's left is the topic to
	 * search. Returns '' when no real prior question exists. Pure.
	 */
	public static function recover_topic( array $history ): string {
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'user' !== (string) ( $history[ $i ]['role'] ?? '' ) ) {
				continue;
			}
			$q = trim( (string) ( $history[ $i ]['content'] ?? '' ) );
			if ( '' === $q || self::is_affirmative_or_widen( $q ) ) {
				continue; // skip the "yes" turns — find the real question
			}
			return self::strip_framing( $q );
		}
		return '';
	}

	/**
	 * Strip interrogative / aggregate / unit framing → a bare topic phrase for a FULLTEXT search.
	 * Conservative: if stripping leaves too little, fall back to the de-punctuated original so the
	 * search always has usable terms. Pure.
	 */
	public static function strip_framing( string $q ): string {
		$orig = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[?.!,]+/', ' ', $q ) ) );

		$t = ' ' . strtolower( $q ) . ' ';
		// Interrogative / request framing.
		$t = preg_replace( '/\b(how\s+many|how\s+much|how\s+long|what(?:\'s|s)?|whats|which|when|where|who|do\s+we\s+have|did\s+we|have\s+we|do\s+i\s+have|did\s+i|have\s+i|are\s+there|is\s+there|can\s+you|could\s+you|would\s+you|please|pls|tell\s+me|show\s+me|find\s+me|find|search\s+for|search|look\s+up|look\s+for|count|number\s+of|total(?:\s+of)?|sum\s+of|amount\s+of)\b/', ' ', $t );
		// Aggregate / transaction verbs.
		$t = preg_replace( '/\b(order(?:ed|s|ing)?|bought|buy(?:ing)?|purchas(?:e|ed|es|ing)|re-?order(?:ed)?|restock(?:ed)?|received?|receiving|spent|spend|paid|pay|invoiced?|billed?|sold|sell|got|have|has|had|were|was|are|is|been)\b/', ' ', $t );
		// Units + very common function words (keep product/entity words).
		$t = preg_replace( '/\b(rolls?|feet|ft|foot|yards?|yds?|units?|pieces?|pcs?|boxes|box|cases?|pallets?|spools?|pounds?|lbs?|sq\.?\s?ft|square\s+feet|of|the|a|an|our|my|your|for|from|to|in|on|with|any|some|we|us|i|me)\b/', ' ', $t );
		$t = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[?.!,]+/', ' ', $t ) ) );

		// Fall back to the original (de-punctuated) if stripping over-ate the phrase.
		if ( strlen( $t ) < 3 ) {
			return $orig;
		}
		return $t;
	}
}
