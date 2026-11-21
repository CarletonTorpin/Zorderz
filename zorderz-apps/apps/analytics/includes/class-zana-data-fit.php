<?php
/**
 * ZANA_Data_Fit — an answer that fits one Ai call's cost limit.
 *
 * The platform's Ai router prices every call before it sends anything: the whole prompt at the model's dearest input
 * rate, and room for the answer. A call whose worst case is over its role's per-call cap is refused, and the refusal
 * costs nothing. A chat turn that carries a large block of data (records, chart rows, a connector's data) can be
 * refused that way, and all the person would get is the router's sentence. So the chat's client
 * (ZANA_LLM_Client::ask()) asks such a call once more, with the data made to fit:
 *
 *   - fit_bytes(): the most prompt one call of a role fits under its cap, by the router's own reckoning (a token per
 *     3 bytes, the model's 1-hour cache-write rate, the region's price factor), keeping room for the answer: up to
 *     KEEP_OUT output tokens, thinking included, since an answer starved of output comes back empty or cut off;
 *   - cut(): the biggest fenced data block in the call is cut from its end, at a line break and never inside a
 *     character, so the whole call fits. A line inside the fence says how much was left out, and a note after it tells
 *     the model to say that the answer covers part of the data. The instructions are never cut: they are what makes
 *     an answer right, and the data is what made this one too big;
 *   - said(): the line under the answer that says part of the data was left out, so a partial answer always says so,
 *     whatever the model wrote.
 *
 * A refusal a cut cannot fix (no data block, too little data left, or a call that already fits, so the month's money
 * was the reason) is said at once, in words the person can act on (ZANA_LLM_Client::error_words()). A call that was cut
 * is never cut again.
 *
 * Pure apart from reading the router's role table and prices.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Data_Fit {

	/** Output tokens a cut call keeps room for (fewer when the call asks for fewer, never under MIN_OUT). */
	const KEEP_OUT = 8000;
	const MIN_OUT  = 1000;

	/** A cut that would leave less data than this (bytes) is not worth an answer. */
	const DATA_MIN = 8000;

	/** Bytes kept free: the customer-codes note, coded details that run longer than the words, each part's overhead. */
	const SLACK = 6000;

	/** Bytes the two notes take. */
	const NOTE_ROOM = 600;

	/**
	 * The most prompt (system text and messages, in bytes) one call of $role fits under its cap. 0 when it cannot be
	 * worked out (an unknown role, a model with no prices): then nothing is cut.
	 *
	 * @param string     $role    the router role the call was asked under
	 * @param int        $max_out the output the call asked for
	 * @param float|null $cap_usd a narrower cap for this call, when one applies
	 */
	public static function fit_bytes( string $role, int $max_out = self::KEEP_OUT, ?float $cap_usd = null ): int {
		if ( ! class_exists( 'ZDZ_Core_LLM' ) || ! method_exists( 'ZDZ_Core_LLM', 'role' ) || ! method_exists( 'ZDZ_Core_LLM', 'prices_for' ) ) {
			return 0;
		}
		$row = ZDZ_Core_LLM::role( $role );
		if ( ! is_array( $row ) ) {
			return 0;
		}
		$cap = (float) ( $row['cap_usd'] ?? 0 );
		if ( null !== $cap_usd && $cap_usd > 0 ) {
			$cap = min( $cap, $cap_usd );
		}
		$p = ZDZ_Core_LLM::prices_for( (string) ( $row['model'] ?? '' ) );
		if ( $cap <= 0 || ! is_array( $p ) || ! isset( $p[2], $p[4] ) || (float) $p[2] <= 0 ) {
			return 0;
		}
		$geo    = method_exists( 'ZDZ_Core_LLM', 'geo_factor' ) ? max( 1.0, (float) ZDZ_Core_LLM::geo_factor() ) : 1.0;
		$keep   = max( self::MIN_OUT, min( self::KEEP_OUT, $max_out ) ) * (float) $p[4] * $geo / 1e6;
		$tokens = ( $cap - $keep ) / ( (float) $p[2] * $geo / 1e6 );
		return max( 0, (int) floor( ( $tokens - 51 ) * 3 ) - self::SLACK );   // the router adds 51 tokens to its count
	}

	/** The size the router counts for a call: its system text, and each message with its overhead. */
	public static function size( string $system, array $msgs ): int {
		$n = strlen( $system );
		foreach ( $msgs as $m ) {
			$n += strlen( is_array( $m ) && is_string( $m['content'] ?? null ) ? $m['content'] : '' ) + 40;
		}
		return $n;
	}

	/**
	 * The biggest fenced data block in a text: array( start of its first data line, start of its closing token ), or
	 * null. A block opens on a line that holds the fence's opening token and closes at the next closing token.
	 */
	private static function biggest( string $text, string $open, string $close ): ?array {
		$best = null;
		$from = 0;
		while ( false !== ( $x = strpos( $text, $open, $from ) ) ) {
			$head = strpos( $text, "\n", $x );
			$end  = false === $head ? false : strpos( $text, $close, $head );
			if ( false === $head || false === $end ) {
				break;
			}
			if ( null === $best || ( $end - $head ) > ( $best[1] - $best[0] ) ) {
				$best = array( $head + 1, $end );
			}
			$from = $end + strlen( $close );
		}
		return $best;
	}

	/**
	 * The call with its biggest data block cut from its end so the whole fits $target bytes, the notes added:
	 * array( system, messages, characters left out ). Null when there is no data block, the call already fits, or what
	 * would be left is too little to answer from.
	 *
	 * @param string $system the call's system text
	 * @param array  $msgs   the call's messages ( role, content: string )
	 * @param int    $target fit_bytes() for the call
	 */
	public static function cut( string $system, array $msgs, int $target ): ?array {
		$open  = class_exists( 'ZANA_Markers' ) ? ZANA_Markers::data_start() : '';
		$close = class_exists( 'ZANA_Markers' ) ? ZANA_Markers::data_end() : '';
		$over  = self::size( $system, $msgs ) - $target;
		if ( '' === $open || '' === $close || $target <= 0 || $over <= 0 ) {
			return null;
		}
		// the biggest block of the call, wherever it is: the system text (key -1) or a message
		$texts = array( -1 => $system );
		foreach ( $msgs as $i => $m ) {
			if ( is_array( $m ) && is_string( $m['content'] ?? null ) ) {
				$texts[ $i ] = $m['content'];
			}
		}
		$where = null;
		$block = null;
		foreach ( $texts as $k => $t ) {
			$b = self::biggest( $t, $open, $close );
			if ( null !== $b && ( null === $block || ( $b[1] - $b[0] ) > ( $block[1] - $block[0] ) ) ) {
				$where = $k;
				$block = $b;
			}
		}
		if ( null === $block ) {
			return null;
		}
		$text  = $texts[ $where ];
		$inner = substr( $text, $block[0], $block[1] - $block[0] );
		$keep  = strlen( $inner ) - $over - self::NOTE_ROOM;
		if ( $keep < self::DATA_MIN ) {
			return null;
		}
		$nl   = strrpos( substr( $inner, 0, $keep ), "\n" );
		$keep = ( false !== $nl && $nl > self::DATA_MIN ) ? $nl : strlen( mb_strcut( $inner, 0, $keep, 'UTF-8' ) );   // never inside a character
		$gone = mb_strlen( trim( substr( $inner, $keep ), "\n" ), 'UTF-8' );   // the characters of data, not the line breaks around them
		$new  = substr( $text, 0, $block[0] ) . rtrim( substr( $inner, 0, $keep ) )
			. "\n[the rest of this data, about " . number_format( $gone ) . " characters, was left out here]\n"
			. $close
			. "\nNOTE FROM THE APP: the data above was cut from its end to fit one Ai call's cost limit (about " . number_format( $gone )
			. ' characters left out). Answer from what is there, say in one line that the answer covers only part of the data, '
			. 'and suggest a shorter period or a narrower question for the rest.'
			. substr( $text, $block[1] + strlen( $close ) );
		if ( -1 === $where ) {
			return array( $new, $msgs, $gone );
		}
		$msgs[ $where ]['content'] = $new;
		return array( $system, $msgs, $gone );
	}

	/** The answer with the line that says part of the data was left out ($chars: how much; 0 leaves it as it is). */
	public static function said( string $answer, int $chars ): string {
		if ( $chars <= 0 ) {
			return $answer;
		}
		return rtrim( $answer ) . "\n\n_" . sprintf(
			/* translators: %s: how many characters of data were left out */
			__( 'Part of the data (about %s characters) was left out of this answer to fit one Ai answer\'s cost limit. Ask about a shorter period or a narrower question for the rest.', 'zorderz' ),
			number_format( $chars )
		) . '_';
	}
}
