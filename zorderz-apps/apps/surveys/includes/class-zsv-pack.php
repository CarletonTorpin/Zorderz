<?php
/**
 * ZSV_Pack — Surveys' part of an Identity Pack: how this business's survey operator writes call
 * notes (voice.yml), and how far back a Google review is matched to a customer (rules.yml). Each
 * value is checked as the app reads it, shown in the pack's preview, written on apply and put
 * back exactly on revert (ZDZ_Identity_Pack::option_consumer()).
 *
 *   voice.yml
 *     surveys:
 *       status_vocabulary:            # the phrases a call note is classified by
 *         attention: [ follow up, call back, will call ]       # a person reads the note first
 *         send_instruction: [ send link, ok to send ]          # the operator's "send the review request"
 *         # also: issue, voicemail, voicemail_full, no_review, no_primary_account, satisfied
 *       note_reading:                 # how a note's sentences and clauses are read
 *         subjects: [ she, he, they, customer, client ]
 *         tails: [ if anything comes up, if needed ]
 *         open_work: [ install, schedul, refund ]
 *         # also: titles, web_endings, conditions, send_verbs, waits, waits_not, lead_ins,
 *         #       soft_words, auxiliaries, call_backs, bare_asides, replies
 *   rules.yml
 *     surveys:
 *       google_reviews:
 *         lookback_days: 60           # a review older than this is never matched (1 to 365)
 *         candidate_days: 120         # the customer's follow-up was opened at most this long before it (1 to 730)
 *
 * A list the pack sets REPLACES the built-in list of that name; a list it leaves out is left as it
 * is. The lists that hold a note back for a person (HOLD_LISTS) may be changed but never emptied:
 * a pack may make the reader more careful, not switch a check off. A value that cannot be read is
 * refused with the reason and changes nothing.
 *
 * Not from a pack: automatic recording of Google reviews. An administrator turns it on in the
 * Surveys widget, after looking at what the check would have recorded.
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Pack {

	const VOCABULARY_KEYS = array( 'issue', 'voicemail', 'voicemail_full', 'no_review', 'no_primary_account', 'satisfied', 'attention', 'send_instruction' );

	const READING_KEYS = array( 'titles', 'web_endings', 'conditions', 'send_verbs', 'waits', 'waits_not', 'lead_ins', 'soft_words', 'subjects', 'auxiliaries', 'call_backs', 'bare_asides', 'replies', 'tails', 'open_work' );

	/** Lists that hold a note back for a person: a pack may change their phrases, never empty them. */
	const HOLD_LISTS = array( 'issue', 'voicemail', 'voicemail_full', 'no_review', 'attention', 'conditions', 'send_verbs', 'waits', 'open_work' );

	const REVIEW_DAYS = array(
		'lookback_days'  => array( 'zsv_greviews_lookback_days', 1, 365 ),
		'candidate_days' => array( 'zsv_greviews_candidate_days', 1, 730 ),
	);

	public static function register( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( ! class_exists( 'ZDZ_Identity_Pack' ) || ! method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			return $consumers;
		}
		$label = __( 'Surveys', 'zorderz' );
		$consumers['surveys-call-notes'] = ZDZ_Identity_Pack::option_consumer( $label, 'voice', array( 'surveys.status_vocabulary', 'surveys.note_reading' ), array( __CLASS__, 'plan_words' ) );
		$consumers['surveys-reviews']    = ZDZ_Identity_Pack::option_consumer( $label, 'rules', array( 'surveys.google_reviews' ), array( __CLASS__, 'plan_reviews' ) );
		return $consumers;
	}

	/**
	 * One list from a pack, checked: a list (or comma-separated text) of phrases, each a piece of
	 * text of at most ZSV_Settings::WORD_LENGTH_MAX characters, at most ZSV_Settings::WORDS_MAX of
	 * them. Null, with the reason in $why, when it is not that.
	 *
	 * @return string[]|null
	 */
	public static function words( $value, &$why = '' ): ?array {
		$why = '';
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			$why = 'A list of phrases.';
			return null;
		}
		$list = ZDZ_Identity_Pack::as_list( $value );
		if ( count( $list ) > ZSV_Settings::WORDS_MAX ) {
			$why = sprintf( 'At most %d phrases.', ZSV_Settings::WORDS_MAX );
			return null;
		}
		foreach ( $list as $i => $w ) {
			if ( ! is_string( $w ) || '' === trim( $w ) || strlen( trim( $w ) ) > ZSV_Settings::WORD_LENGTH_MAX ) {
				$why = sprintf( 'Entry %d is not a phrase of at most %d characters.', $i + 1, ZSV_Settings::WORD_LENGTH_MAX );
				return null;
			}
		}
		return ZSV_Settings::clean_words( $list );
	}

	/**
	 * voice.yml surveys.status_vocabulary and surveys.note_reading → the options ZSV_Settings reads
	 * its lists from, one list at a time.
	 */
	public static function plan_words( array $data ): array {
		$plan  = ZDZ_Identity_Pack::plan();
		$parts = array(
			'surveys.status_vocabulary' => array( ZSV_Settings::VOCABULARY_OPTION, self::VOCABULARY_KEYS ),
			'surveys.note_reading'      => array( ZSV_Settings::READING_OPTION, self::READING_KEYS ),
		);
		foreach ( $parts as $path => $part ) {
			if ( ! array_key_exists( $path, $data ) ) {
				continue;
			}
			list( $option, $known ) = $part;
			$raw = $data[ $path ];
			if ( ! is_array( $raw ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'A map of list names to lists of phrases.' );
				continue;
			}
			ZDZ_Identity_Pack::refuse_extra( $plan, $path, $raw, $known );
			foreach ( $known as $key ) {
				if ( ! array_key_exists( $key, $raw ) ) {
					continue;
				}
				$list = self::words( $raw[ $key ], $why );
				if ( null === $list ) {
					$plan['refused'][] = array( 'path' => "$path.$key", 'why' => $why );
					continue;
				}
				if ( ! $list && in_array( $key, self::HOLD_LISTS, true ) ) {
					$plan['refused'][] = array( 'path' => "$path.$key", 'why' => 'This list holds a note back for a person: a pack may change its phrases, not empty it.' );
					continue;
				}
				ZDZ_Identity_Pack::plan_key( $plan, "$path.$key", $option, $key, $list );
			}
		}
		if ( $plan['writes'] ) {
			$plan['notes'][] = 'these lists decide which call notes earn a review e-mail without a person reading them first; a list set here replaces the built-in list of that name.';
		}
		return $plan;
	}

	/** rules.yml surveys.google_reviews { lookback_days, candidate_days } → the two windows ZSV_Settings reads. */
	public static function plan_reviews( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		$path = 'surveys.google_reviews';
		$raw  = $data[ $path ] ?? null;
		if ( ! is_array( $raw ) ) {
			$plan['refused'][] = array( 'path' => $path, 'why' => 'lookback_days and candidate_days, each a number of days.' );
			return $plan;
		}
		if ( array_key_exists( 'automatic', $raw ) ) {
			$plan['refused'][] = array( 'path' => "$path.automatic", 'why' => 'Automatic recording is turned on by an administrator in the Surveys widget, never by a pack.' );
			unset( $raw['automatic'] );
		}
		ZDZ_Identity_Pack::refuse_extra( $plan, $path, $raw, array_keys( self::REVIEW_DAYS ) );
		foreach ( self::REVIEW_DAYS as $key => $spec ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			list( $option, $min, $max ) = $spec;
			$v = $raw[ $key ];
			if ( ! is_numeric( $v ) || (int) $v != $v || (int) $v < $min || (int) $v > $max ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- "60" and 60 are both sixty days; 60.5 is refused.
				$plan['refused'][] = array( 'path' => "$path.$key", 'why' => sprintf( 'Whole days, %d to %d.', $min, $max ) );
				continue;
			}
			$now = get_option( $option, null );
			if ( null === $now || ! is_numeric( $now ) || (int) $now !== (int) $v ) { // a stored "60" and a pack's 60 are the same setting
				ZDZ_Identity_Pack::plan_option( $plan, "$path.$key", $option, (int) $v );
			}
		}
		return $plan;
	}
}
