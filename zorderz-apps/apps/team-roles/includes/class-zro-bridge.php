<?php
/**
 * ZRO_Bridge — what the rest of the platform may ask Team Roles through the capability registry.
 *
 *   roles.who_can   "who can install <item>?" / "can Robin work at height?" — a READ. Tier viewer
 *                   (techs ask too), never the shared device, no side effect. Registered on
 *                   zdz_register_capabilities; ZDZ_Capabilities decides who may use it and names
 *                   the asker from the session (requesting_user_id), never from the payload.
 *                   Payload: `ability` (a key) or `query` (words); optional `person` (a name) or
 *                   `person_id` for one person's answer. The list names those marked Can, then
 *                   those Learning. Notes are shown only for rows the asker may see in the app
 *                   (self, crew, or the admin tier). The message is built from stored answers
 *                   only: no model writes it.
 *
 *   What each asker gets:
 *     the admin tier      every person's level, with every note and "since" date
 *     a crew lead         every person's level; notes and dates for self and crew
 *     staff and techs     every person's level; notes and dates for their own row only
 *     the shared device   nothing (the registry refuses it, and so does this bridge)
 *     not on the team     nothing
 *   Levels are the whole team's to know; notes are the row's own viewers'.
 *
 *   Items: "who can install <item>?" names the item AND the role and lists those people; "who
 *   does <item>?" names only the item and answers per role ("sell: …; install: …; repair: …"). A
 *   kind of work that moved into items is still answered when asked by its key, from the items;
 *   it is no longer matched by words (the item is), so a question never gets two answers.
 *
 *   Words: an item is found by its catalog name and aliases; a plain ability by its name. The
 *   business's own synonyms come from the `zro_word_synonyms` filter (word => the word to read it
 *   as), and words that name no item on their own (a trade's generic nouns, like "unit") from
 *   `zro_item_filler_words`. Core ships only the role verbs and number words.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_Bridge {

	const VERB   = 'roles.who_can';
	const SOURCE = 'zro_team_roles';

	/** Words that say nothing about which ability. */
	const STOP = array( 'who', 'whos', 'can', 'cant', 'could', 'do', 'does', 'doing', 'the', 'a', 'an', 'is', 'are', 'and', 'or', 'of', 'to', 'on', 'in', 'for', 'with',
		'our', 'my', 'we', 'us', 'anyone', 'someone', 'somebody', 'anybody', 'people', 'person', 'team', 'crew', 'able', 'allowed', 'trained', 'qualified', 'knows', 'know', 'how',
		'which', 'what', 'job', 'jobs', 'work', 'use', 'go', 'up', 'be', 'it', 'that', 'this', 'there', 'here', 'me', 'tell', 'list', 'show', 'please' );

	/** Core's own synonyms: the role verbs' forms and number words. */
	const SAME = array( '2nd' => 'second', '1st' => 'first', 'single' => 'first', 'storey' => 'story',
		'installs' => 'install', 'installing' => 'install', 'installation' => 'install', 'installations' => 'install', 'installer' => 'install', 'installers' => 'install', 'installed' => 'install',
		'repairs' => 'repair', 'repairing' => 'repair', 'fix' => 'repair', 'fixing' => 'repair', 'service' => 'repair', 'servicing' => 'repair',
		'selling' => 'sell', 'sales' => 'sell', 'sold' => 'sell', 'sells' => 'sell',
		'estimates' => 'estimate', 'measuring' => 'measure', 'measurements' => 'measure', 'driving' => 'drive' );

	/** The words that name a role, not an item. */
	const ROLE_WORDS = array( 'sell' => 'sell', 'install' => 'install', 'repair' => 'repair' );

	private static $same = null;

	public static function init() {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function descriptor() {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'team-roles',
			'app'           => ZRO_APP_ID,
			'tier'          => 'viewer', // techs ask "who can do X?" too; notes stay per row
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'who_can_for_chat' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'outside'       => false,
			'summary'       => 'Who on the team can sell, install or repair an item, or do a kind of work, a site condition or a task, from Team Roles; or whether one person can.',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	/** Core's synonyms plus the business's own (never overriding a role verb). */
	private static function same() {
		if ( null === self::$same ) {
			$extra = apply_filters( 'zro_word_synonyms', array() );
			$out   = self::SAME;
			foreach ( is_array( $extra ) ? $extra : array() as $w => $to ) {
				$w  = strtolower( trim( (string) $w ) );
				$to = strtolower( trim( (string) $to ) );
				if ( '' !== $w && '' !== $to && ! isset( self::ROLE_WORDS[ $w ] ) && ! isset( $out[ $w ] ) ) {
					$out[ $w ] = $to;
				}
			}
			self::$same = $out;
		}
		return self::$same;
	}

	public static function flush() {
		self::$same = null;
	}

	private static function filler() {
		$f = apply_filters( 'zro_item_filler_words', array() );
		return is_array( $f ) ? array_values( array_filter( array_map( function ( $w ) { return strtolower( trim( (string) $w ) ); }, $f ) ) ) : array();
	}

	public static function words( $s ) {
		$same = self::same();
		$s    = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s );
		$s    = str_replace( array( '&', "'" ), array( ' and ', '' ), $s );
		$out  = array();
		foreach ( preg_split( '/[^a-z0-9-]+/', $s, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$w = $same[ $w ] ?? $w;
			if ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) && ! isset( $same[ $w ] ) ) {
				$w = substr( $w, 0, -1 );
			}
			$w = $same[ $w ] ?? $w;
			if ( '' !== $w && ! in_array( $w, self::STOP, true ) ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	/** An item's own words: its name, its id, and its catalog aliases. */
	private static function item_words( array $i ) {
		$text = $i['label'] . ' ' . str_replace( array( '_', '-' ), ' ', $i['key'] ) . ' ' . implode( ' ', (array) ( $i['aliases'] ?? array() ) );
		return array_values( array_diff( self::words( $text ), self::filler() ) );
	}

	/**
	 * The ability a question names: an exact key, an exact label, else the best overlap of words.
	 * Returns ['ability' => row], ['item' => row, 'role' => ''|role], ['options' => rows] when two
	 * or more fit equally, or [] for none.
	 */
	public static function match( $text ) {
		$text = trim( (string) $text );
		$all  = ZRO_Abilities::all();
		if ( '' === $text ) {
			return array();
		}
		$cell = ZRO_Abilities::parse_cell( $text );
		if ( $cell && empty( $cell[0]['retired'] ) ) {
			return array( 'item' => $cell[0], 'role' => $cell[1] );
		}
		if ( isset( $all[ $text ] ) ) {
			return 'item' === $all[ $text ]['group'] ? array( 'item' => $all[ $text ], 'role' => '' ) : array( 'ability' => $all[ $text ] );
		}
		foreach ( $all as $a ) {
			if ( 0 === strcasecmp( $a['label'], $text ) ) {
				return 'item' === $a['group'] ? array( 'item' => $a, 'role' => '' ) : array( 'ability' => $a );
			}
		}
		$q = self::words( $text );
		if ( ! $q ) {
			return array();
		}
		$plain = array_filter( $all, function ( $a ) { return 'item' !== $a['group']; } );
		if ( ! ZRO_Migrate::items_live() ) {
			// Before the move the marks are on the older kinds and the items are empty: answer from
			// the kinds that hold them. Items are reached by their exact key until then.
			return self::plain_match( $q, $plain )['result'];
		}
		// A role word ("install") says which of an item's answers; it is not itself evidence for an
		// item or a plain ability, so neither side scores it. The better score wins; a tie is a
		// question, never a guess.
		$role = '';
		foreach ( $q as $w ) {
			if ( isset( self::ROLE_WORDS[ $w ] ) && '' === $role ) {
				$role = self::ROLE_WORDS[ $w ];
			}
		}
		$qp    = array_values( array_diff( $q, array_keys( self::ROLE_WORDS ) ) );
		$qi    = array_values( array_diff( $qp, self::filler() ) );
		$ibest = 0;
		$ihits = array();
		foreach ( ZRO_Abilities::items() as $i ) {
			$n = count( array_intersect( $qi, self::item_words( $i ) ) );
			if ( 0 === $n || $n < $ibest ) {
				continue;
			}
			if ( $n > $ibest ) {
				$ibest = $n;
				$ihits = array();
			}
			$ihits[] = $i;
		}
		// The plain abilities, never a kind of work that moved into items (its item answers it).
		$live = array_filter( $plain, function ( $a ) { return ! ZRO_Abilities::merged_into( $a ); } );
		if ( 0 === $ibest ) {
			// No item named: match the plain abilities, role words and all; then, if nothing fits, the moved kinds too.
			$p = self::plain_match( $q, $live );
			return $p['best'] > 0 ? $p['result'] : self::plain_match( $q, $plain )['result'];
		}
		$p = $qp ? self::plain_match( $qp, $live ) : array( 'best' => 0, 'result' => array() );
		if ( $ibest > $p['best'] ) {
			return 1 === count( $ihits ) ? array( 'item' => $ihits[0], 'role' => $role ) : array( 'options' => $ihits );
		}
		if ( $p['best'] > $ibest ) {
			return $p['result'];
		}
		$opts = $ihits; // a tie: ask which
		foreach ( isset( $p['result']['options'] ) ? $p['result']['options'] : ( isset( $p['result']['ability'] ) ? array( $p['result']['ability'] ) : array() ) as $a ) {
			$opts[] = $a;
		}
		return array( 'options' => $opts );
	}

	/**
	 * The word match over a list of plain abilities. The most question words matched wins. A tie is
	 * a question, not a guess, unless exactly one tied ability's name and the question are the same
	 * words. Returns array( 'best' => score, 'result' => array( 'ability' => row ) | array( 'options' => rows ) | array() ).
	 */
	private static function plain_match( array $q, array $list ) {
		$best = 0;
		$hits = array();
		foreach ( $list as $a ) {
			$aw = self::words( $a['label'] . ' ' . str_replace( '_', ' ', $a['key'] ) );
			$n  = count( array_intersect( $q, $aw ) );
			if ( 0 === $n || $n < $best ) {
				continue;
			}
			if ( $n > $best ) {
				$best = $n;
				$hits = array();
			}
			$hits[] = array( 'a' => $a, 'full' => count( $aw ) === $n && count( $q ) === $n );
		}
		if ( 1 === count( $hits ) ) {
			return array( 'best' => $best, 'result' => array( 'ability' => $hits[0]['a'] ) );
		}
		$full = array_values( array_filter( $hits, function ( $h ) { return $h['full']; } ) );
		if ( 1 === count( $full ) ) {
			return array( 'best' => $best, 'result' => array( 'ability' => $full[0]['a'] ) );
		}
		$rows = array_map( function ( $h ) { return $h['a']; }, $hits );
		return array( 'best' => $best, 'result' => $rows ? array( 'options' => $rows ) : array() );
	}

	/** A structured `ability` is exact: its key or its label, retired ones included, never word-matched. */
	public static function exact( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$a = ZRO_Abilities::get( $text );
		if ( $a ) {
			return $a;
		}
		foreach ( ZRO_Abilities::all( true ) as $row ) {
			if ( 0 === strcasecmp( $row['label'], $text ) ) {
				return $row;
			}
		}
		return null;
	}

	/** The team member a name means (exact name, initials, or a first name that is unique), or a message. */
	private static function person( $name, $id ) {
		$id = is_scalar( $id ) ? (int) $id : 0;
		if ( $id > 0 ) {
			return zro_is_team_member( $id ) ? $id : __( 'No one on the team has that id.', 'zorderz' );
		}
		$name = strtolower( trim( is_scalar( $name ) ? (string) $name : '' ) );
		if ( '' === $name ) {
			return __( 'No one on the team by that name.', 'zorderz' );
		}
		$hits = array();
		foreach ( zro_team() as $p ) {
			$pn = strtolower( $p['name'] );
			if ( $pn === $name || strtolower( $p['initials'] ) === $name || 0 === strpos( $pn, $name . ' ' ) ) {
				$hits[] = (int) $p['id'];
			}
		}
		$hits = array_values( array_unique( $hits ) );
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		return 0 === count( $hits ) ? __( 'No one on the team by that name.', 'zorderz' ) : __( 'More than one person on the team matches that name.', 'zorderz' );
	}

	private static function list_names( array $names ) {
		if ( count( $names ) <= 1 ) {
			return implode( '', $names );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' and ' . $last;
	}

	/** What Team Roles has, for a "which one?" reply: items and the plain abilities, never a kind that moved. */
	private static function labels() {
		$out = array();
		foreach ( ZRO_Abilities::all() as $a ) {
			if ( ! ZRO_Abilities::merged_into( $a ) ) {
				$out[] = $a['label'];
			}
		}
		return $out;
	}

	public static function who_can_for_chat( array $payload ) {
		$asker = (int) ( $payload['requesting_user_id'] ?? 0 );
		if ( $asker <= 0 || ! empty( $payload['is_kiosk'] ) || zro_is_kiosk( $asker ) || ! zro_user_can_use( $asker ) ) {
			return array( 'success' => false, 'denied' => true, 'message' => __( 'Team Roles is not available on this account.', 'zorderz' ) );
		}
		$named = trim( is_scalar( $payload['ability'] ?? '' ) ? (string) ( $payload['ability'] ?? '' ) : '' );
		if ( '' !== $named ) {
			$cell = ZRO_Abilities::parse_cell( $named );
			$a    = $cell ? $cell[0] : self::exact( $named );
			if ( ! $a ) {
				/* translators: 1: what was asked, 2: list of abilities */
				return array( 'success' => false, 'needs_clarify' => true, 'message' => sprintf( __( 'Team Roles has no ability "%1$s". It has: %2$s.', 'zorderz' ), $named, implode( '; ', self::labels() ) ), 'source' => self::SOURCE );
			}
			if ( ! empty( $a['retired'] ) ) {
				/* translators: %s: ability */
				return array( 'success' => false, 'denied' => false, 'message' => sprintf( __( '"%s" is retired in Team Roles, so no one is given it.', 'zorderz' ), $a['label'] ), 'ability' => array( 'key' => $a['key'], 'label' => $a['label'] ), 'source' => self::SOURCE );
			}
			$m = 'item' === $a['group'] ? array( 'item' => $a, 'role' => $cell ? $cell[1] : '' ) : array( 'ability' => $a );
		} else {
			$m = self::match( is_scalar( $payload['query'] ?? '' ) ? (string) ( $payload['query'] ?? '' ) : '' );
		}
		if ( isset( $m['item'] ) ) {
			return self::item_answer( $asker, $m['item'], (string) $m['role'], $payload );
		}
		if ( empty( $m ) ) {
			return array( 'success' => false, 'needs_clarify' => true, 'message' => __( 'Which ability? Team Roles has:', 'zorderz' ) . ' ' . implode( '; ', self::labels() ) . '.', 'source' => self::SOURCE );
		}
		if ( isset( $m['options'] ) ) {
			return array( 'success' => false, 'needs_clarify' => true, 'message' => __( 'Which one:', 'zorderz' ) . ' ' . implode( ', ', array_map( function ( $o ) { return $o['label']; }, $m['options'] ) ) . '?',
				'options' => array_map( function ( $o ) { return $o['key']; }, $m['options'] ), 'source' => self::SOURCE );
		}
		$a = $m['ability'];

		// One person's answer.
		if ( ! empty( $payload['person'] ) || ! empty( $payload['person_id'] ) ) {
			$uid = self::person( $payload['person'] ?? '', $payload['person_id'] ?? 0 );
			if ( ! is_int( $uid ) ) {
				return array( 'success' => false, 'needs_clarify' => true, 'message' => $uid, 'source' => self::SOURCE );
			}
			$ans = ZRO_Roles::person_can( $uid, $a['key'] );
			if ( ! zro_can_view( $asker, $uid ) ) {
				// The level is the team's to know; the note and date belong to the row's own viewers.
				$u          = get_userdata( $uid );
				$words      = array( 'can' => 'can do', 'learning' => 'is learning', 'cannot' => 'is not marked as able to do' );
				$ans['why'] = sprintf( '%s %s %s.', $u ? $u->display_name : 'They', $words[ $ans['level'] ], $a['label'] );
			}
			return array( 'success' => true, 'denied' => false, 'message' => $ans['why'], 'ability' => array( 'key' => $a['key'], 'label' => $a['label'] ), 'answer' => $ans, 'source' => self::SOURCE );
		}

		// Who can.
		$rows     = ZRO_Roles::who_can( $a['key'], true );
		$can      = array();
		$learning = array();
		foreach ( $rows as $r ) {
			$label = $r['name'];
			if ( '' !== $r['note'] && zro_can_view( $asker, $r['user_id'] ) ) {
				$label .= ' (' . $r['note'] . ')';
			}
			if ( 'can' === $r['level'] ) {
				$can[] = $label;
			} else {
				$learning[] = $label;
			}
		}
		if ( ! $can && ! $learning ) {
			$msg = sprintf( 'No one is marked as able to do %s yet in Team Roles.', $a['label'] );
		} else {
			$msg = $can ? sprintf( '%s can do %s', self::list_names( $can ), $a['label'] ) : sprintf( 'No one is marked Can for %s yet', $a['label'] );
			if ( $learning ) {
				$msg .= sprintf( '; %s %s learning', self::list_names( $learning ), 1 === count( $learning ) ? 'is' : 'are' );
			}
			$msg .= '.';
		}
		$pick = function ( $level ) use ( $rows ) {
			return array_values( array_map( function ( $r ) { return array( 'id' => $r['user_id'], 'name' => $r['name'] ); }, array_filter( $rows, function ( $r ) use ( $level ) { return $level === $r['level']; } ) ) );
		};
		return array(
			'success'  => true,
			'denied'   => false,
			'message'  => $msg,
			'ability'  => array( 'key' => $a['key'], 'label' => $a['label'], 'group' => $a['group'] ),
			'can'      => $pick( 'can' ),
			'learning' => $pick( 'learning' ),
			'source'   => self::SOURCE,
		);
	}

	/**
	 * An item. With a role: who can (or one person's answer), like any ability. Without: one line
	 * per role, so "who does <item>?" is never answered with the sellers when the asker meant the
	 * installers.
	 */
	private static function item_answer( $asker, array $i, $role, array $payload ) {
		$roles  = ZRO_Abilities::roles_of( $i );
		$labels = ZRO_Abilities::role_labels();
		if ( '' !== $role && ! in_array( $role, $roles, true ) ) {
			return array( 'success' => false, 'needs_clarify' => true,
				/* translators: 1: item, 2: role, 3: roles it has */
				'message' => sprintf( __( '%1$s has no %2$s in Team Roles. It has: %3$s.', 'zorderz' ), $i['label'], strtolower( $labels[ $role ] ?? $role ), implode( ', ', array_map( function ( $r ) use ( $labels ) { return $labels[ $r ]; }, $roles ) ) ),
				'source' => self::SOURCE );
		}
		$ask  = '' !== $role ? array( $role ) : $roles;
		$item = array( 'key' => $i['key'], 'label' => $i['label'], 'group' => 'item', 'role' => $role );
		if ( ! empty( $payload['person'] ) || ! empty( $payload['person_id'] ) ) {
			$uid = self::person( $payload['person'] ?? '', $payload['person_id'] ?? 0 );
			if ( ! is_int( $uid ) ) {
				return array( 'success' => false, 'needs_clarify' => true, 'message' => $uid, 'source' => self::SOURCE );
			}
			$u     = get_userdata( $uid );
			$parts = array();
			$out   = array();
			foreach ( $ask as $r ) {
				$ans = ZRO_Roles::person_can( $uid, ZRO_Abilities::cell_key( $i['key'], $r ) );
				if ( ! zro_can_view( $asker, $uid ) ) {
					$words      = array( 'can' => 'can', 'learning' => 'is learning to', 'cannot' => 'is not marked to' );
					$ans['why'] = sprintf( '%s %s %s %s.', $u ? $u->display_name : 'They', $words[ $ans['level'] ], $r, $i['label'] );
				}
				$out[ $r ] = $ans;
				$parts[]   = $ans['why'];
			}
			// `answer` keeps the three-key shape (the best of the roles asked); each role's is in by_role.
			$best = null;
			foreach ( $out as $x ) {
				if ( null === $best || ZRO_Roles::RANK[ $x['level'] ] > ZRO_Roles::RANK[ $best['level'] ] ) {
					$best = $x;
				}
			}
			$answer = 1 === count( $out ) ? reset( $out ) : array( 'can' => $best['can'], 'level' => $best['level'], 'why' => implode( ' ', $parts ) );
			return array( 'success' => true, 'denied' => false, 'message' => implode( ' ', $parts ), 'ability' => $item, 'answer' => $answer, 'by_role' => $out, 'source' => self::SOURCE );
		}
		$lines = array();
		$can   = array();
		$learn = array();
		foreach ( $ask as $r ) {
			$rows = ZRO_Roles::who_can( ZRO_Abilities::cell_key( $i['key'], $r ), true );
			$c    = array();
			$l    = array();
			foreach ( $rows as $x ) {
				$label = $x['name'];
				if ( '' !== $x['note'] && zro_can_view( $asker, $x['user_id'] ) ) {
					$label .= ' (' . $x['note'] . ')';
				}
				if ( 'can' === $x['level'] ) {
					$c[]         = $label;
					$can[ $r ][] = array( 'id' => $x['user_id'], 'name' => $x['name'] );
				} else {
					$l[]           = $label;
					$learn[ $r ][] = array( 'id' => $x['user_id'], 'name' => $x['name'] );
				}
			}
			$line = $c ? sprintf( '%s can %s %s', self::list_names( $c ), $r, $i['label'] ) : sprintf( 'No one is marked to %s %s yet', $r, $i['label'] );
			if ( $l ) {
				$line .= sprintf( '; %s %s learning', self::list_names( $l ), 1 === count( $l ) ? 'is' : 'are' );
			}
			$lines[] = $line . '.';
		}
		// `can` and `learning` stay lists of people (able in ANY role asked); each role's lists are in by_role.
		$union = function ( $per ) {
			$seen = array();
			foreach ( $per as $list ) {
				foreach ( $list as $x ) {
					$seen[ $x['id'] ] = $x;
				}
			}
			return array_values( $seen );
		};
		$by = array();
		foreach ( $ask as $r ) {
			$by[ $r ] = array( 'can' => $can[ $r ] ?? array(), 'learning' => $learn[ $r ] ?? array() );
		}
		$able = array_map( function ( $x ) { return $x['id']; }, $union( $can ) );
		return array(
			'success'  => true,
			'denied'   => false,
			'message'  => implode( ' ', $lines ),
			'ability'  => $item,
			'can'      => $union( $can ),
			'learning' => array_values( array_filter( $union( $learn ), function ( $x ) use ( $able ) { return ! in_array( $x['id'], $able, true ); } ) ),
			'by_role'  => (object) $by,
			'source'   => self::SOURCE,
		);
	}
}
