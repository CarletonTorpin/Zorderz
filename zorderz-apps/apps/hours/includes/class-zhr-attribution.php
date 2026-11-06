<?php
/**
 * ZHR_Attribution -- whose hours a reading is. Hours feed pay, so this is decided by exact rules, never by a guess.
 *
 * A reading (words a person typed or said, or a photo of a time sheet) is turned by the model into stretches of work,
 * each with the name written or said beside it ('' when none). The model only transcribes: it is never told who is
 * asking, so it cannot fill a name in. This class then decides, for each stretch, the one person it belongs to:
 *
 *   words with no name          -> the person the reading is for (their own account, or the person an admin opened)
 *   a name that is exactly one  -> that person: the reading's person, or ('other_person') someone else, which only
 *   person's                       someone who may add hours for that someone can add, for them
 *   a photo with no name        -> REVIEW ('no_name')
 *   a name that is nobody's     -> REVIEW ('no_match')
 *   a name that is two people's -> REVIEW ('ambiguous')
 *
 * "Exactly" means the whole name a person is known by (their display name, or first and last name), or their initials
 * when the sheet writes initials (capitals only: "JE", "J.E."). Never part of a name, never a sounds-like name, never
 * "the closest one", and never the owner or the person who sent the photo by default.
 *
 * A review item is attributed to nobody. It becomes hours only when a person chooses whose they are: someone in the
 * admin tier picks the person (from the candidates when the name is two people's), and a person outside it may only
 * say "these are mine" (and only when the name could be theirs). The choice is recorded on the entry.
 *
 * Pure: no database and no WordPress. People are rows ['id', 'name', 'first', 'last', 'initials'].
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Attribution {

	const REVIEW_NO_NAME   = 'no_name';
	const REVIEW_NO_MATCH  = 'no_match';
	const REVIEW_AMBIGUOUS = 'ambiguous';
	const REVIEW_OTHER     = 'other_person';

	/** Review kinds a person may resolve by choosing whose the hours are. 'other_person' is not one: it is someone's. */
	const CONFIRMABLE = array( self::REVIEW_NO_NAME, self::REVIEW_NO_MATCH, self::REVIEW_AMBIGUOUS );

	/** Lower case, curly apostrophes straightened, punctuation other than ' and - dropped, spaces collapsed. */
	public static function norm( $s ) {
		$s = str_replace( array( "\u{2019}", "\u{2018}" ), "'", (string) $s );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = (string) preg_replace( '/[^\p{L}\p{N}\s\'\-]+/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Written as initials: two to four capital letters, each optionally followed by a dot or a space ("JE", "J.E.", "J E"). */
	public static function looks_like_initials( $written ) {
		return (bool) preg_match( '/^\s*(?:\p{Lu}\.?\s*){2,4}$/u', (string) $written );
	}

	/** Letters only, lower case: "J.E." -> "je". */
	private static function compact( $s ) {
		$s = (string) preg_replace( '/[^\p{L}]+/u', '', (string) $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** The whole names a person is known by (normalised), and their initials (letters only, lower case; '' when fewer than two). */
	public static function keys( array $person ) {
		$names = array();
		foreach ( array( (string) ( $person['name'] ?? '' ), trim( (string) ( $person['first'] ?? '' ) . ' ' . (string) ( $person['last'] ?? '' ) ) ) as $n ) {
			$k = self::norm( $n );
			if ( '' !== $k && ! in_array( $k, $names, true ) ) {
				$names[] = $k;
			}
		}
		$ini = self::compact( (string) ( $person['initials'] ?? '' ) );
		return array( 'names' => $names, 'initials' => ( function_exists( 'mb_strlen' ) ? mb_strlen( $ini, 'UTF-8' ) : strlen( $ini ) ) >= 2 ? $ini : '' );
	}

	/**
	 * Who a written name is, by exact match only: ['status' => 'blank'|'match'|'none'|'ambiguous', 'id' => int (match),
	 * 'ids' => int[] (every person it matched)].
	 */
	public static function match( $written, array $people ) {
		$w = self::norm( $written );
		if ( '' === $w ) {
			return array( 'status' => 'blank', 'id' => 0, 'ids' => array() );
		}
		$as_initials = self::looks_like_initials( $written ) ? self::compact( $written ) : '';
		$hits        = array();
		foreach ( $people as $p ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$k = self::keys( $p );
			if ( in_array( $w, $k['names'], true ) || ( '' !== $as_initials && $as_initials === $k['initials'] ) ) {
				$hits[ $id ] = true;
			}
		}
		$ids = array_keys( $hits );
		if ( ! $ids ) {
			return array( 'status' => 'none', 'id' => 0, 'ids' => array() );
		}
		if ( 1 === count( $ids ) ) {
			return array( 'status' => 'match', 'id' => (int) $ids[0], 'ids' => $ids );
		}
		sort( $ids );
		return array( 'status' => 'ambiguous', 'id' => 0, 'ids' => $ids );
	}

	/**
	 * Attribute one proposed stretch. $kind: 'words' (typed or said) or 'photo'. $target: the person the reading is for.
	 * Returns ['person' => int (0 = nobody), 'review' => '' | a REVIEW_* kind, 'candidates' => int[]].
	 */
	public static function attribute( array $proposal, $kind, $target, array $people ) {
		$target = (int) $target;
		$m      = self::match( (string) ( $proposal['name'] ?? '' ), $people );
		switch ( $m['status'] ) {
			case 'blank':
				if ( 'words' === $kind && $target > 0 ) {
					return array( 'person' => $target, 'review' => '', 'candidates' => array() );
				}
				return array( 'person' => 0, 'review' => self::REVIEW_NO_NAME, 'candidates' => array() );
			case 'match':
				if ( $m['id'] === $target ) {
					return array( 'person' => $target, 'review' => '', 'candidates' => array() );
				}
				return array( 'person' => $m['id'], 'review' => self::REVIEW_OTHER, 'candidates' => array() );
			case 'ambiguous':
				return array( 'person' => 0, 'review' => self::REVIEW_AMBIGUOUS, 'candidates' => $m['ids'] );
		}
		return array( 'person' => 0, 'review' => self::REVIEW_NO_MATCH, 'candidates' => array() );
	}

	/**
	 * Whom $actor may choose for a review item: the admin tier, anyone in $people_ids (only the candidates when the name
	 * is two people's); anyone else, only themselves (and, for an ambiguous name, only when they are one of the two).
	 * An item that is not a review item, or is exactly someone's, has nothing to choose.
	 */
	public static function choices( array $stored, $actor, $actor_is_admin, array $people_ids ) {
		$review = (string) ( $stored['review'] ?? '' );
		if ( ! in_array( $review, self::CONFIRMABLE, true ) ) {
			return array();
		}
		$actor = (int) $actor;
		$pool  = array_values( array_unique( array_map( 'intval', $people_ids ) ) );
		if ( self::REVIEW_AMBIGUOUS === $review ) {
			$pool = array_values( array_intersect( $pool, array_map( 'intval', (array) ( $stored['candidates'] ?? array() ) ) ) );
		}
		if ( ! $actor_is_admin ) {
			return in_array( $actor, $pool, true ) ? array( $actor ) : array();
		}
		sort( $pool );
		return $pool;
	}

	/**
	 * May a stretch from a reading be added for $target? $stored: the server's own attribution of it (attribute()).
	 * $chosen: the person the adder chose for a review item (0 = none). The caller has already decided that $actor may
	 * add hours for $target at all. Returns ['ok' => bool, 'how' => 'exact'|'chosen'|'', 'why' => '' | a refusal code].
	 */
	public static function may_add( array $stored, $target, $chosen, $actor, $actor_is_admin, array $people_ids ) {
		$target = (int) $target;
		$person = (int) ( $stored['person'] ?? 0 );
		$review = (string) ( $stored['review'] ?? '' );
		if ( $target <= 0 ) {
			return array( 'ok' => false, 'how' => '', 'why' => 'no_person' );
		}
		if ( $person > 0 && in_array( $review, array( '', self::REVIEW_OTHER ), true ) ) {
			return $person === $target
				? array( 'ok' => true, 'how' => 'exact', 'why' => '' )
				: array( 'ok' => false, 'how' => '', 'why' => 'other_person' );
		}
		if ( in_array( $review, self::CONFIRMABLE, true ) && (int) $chosen === $target
			&& in_array( $target, self::choices( $stored, $actor, $actor_is_admin, $people_ids ), true ) ) {
			return array( 'ok' => true, 'how' => 'chosen', 'why' => '' );
		}
		return array( 'ok' => false, 'how' => '', 'why' => 'needs_person' );
	}

	/** A review item in plain words, for the person reading the proposals. $for_admin adds whose name it is when known. */
	public static function review_words( array $stored, $written, $for_admin, $other_name = '' ) {
		switch ( (string) ( $stored['review'] ?? '' ) ) {
			case self::REVIEW_NO_NAME:
				return __( 'No name is written beside these hours, so Hours will not guess whose they are. Choose whose they are to add them.', 'zorderz' );
			case self::REVIEW_NO_MATCH:
				/* translators: %s: a name as written on a time sheet */
				return sprintf( __( '"%s" is not exactly anyone\'s name here. Choose whose hours these are to add them.', 'zorderz' ), $written );
			case self::REVIEW_AMBIGUOUS:
				/* translators: %s: a name as written on a time sheet */
				return sprintf( __( '"%s" could be more than one person. Choose whose hours these are to add them.', 'zorderz' ), $written );
			case self::REVIEW_OTHER:
				return $for_admin && '' !== $other_name
					/* translators: %s: a person's name */
					? sprintf( __( 'These are %s\'s hours. They are added to their hours, not to the person open now.', 'zorderz' ), $other_name )
					: __( 'These hours have someone else\'s name on them, so they cannot be added here. The office can add them for that person.', 'zorderz' );
		}
		return '';
	}
}
