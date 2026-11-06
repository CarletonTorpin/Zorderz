<?php
/**
 * ZANA_App_Reads — the chat asks another app through that app's registry READ verb, and shows the app's own words.
 *
 * Data crosses apps only through read verbs on the registry (ZDZ_Capabilities): the owning app answers; the chat never
 * reads another app's tables. This is the chat's one reader for them:
 *
 *   - an ALLOW-LIST of adapters, one per verb (ADAPTERS). A verb that is not on it is never run, and neither is a write;
 *   - each adapter names the verb, a pure detector (the words only, no model), a payload of plain values (never
 *     user_id, requesting_user_id, tier or is_kiosk: the registry stamps the signed-in person), the app's name for the
 *     source line, and how to read the app's answer (did the app recognise the question?);
 *   - it asks only through ZDZ_Capabilities::invoke(), only when the signed-in person is the turn's person, with no
 *     direct-bridge fallback;
 *   - it renders the app's `message` as written, framed with a bold header and a source line ("_From Team Roles._").
 *     A failure, an empty answer or a missing verb is "I couldn't check <app> just now", never "no one";
 *   - the model never sees a verb's answer: on later turns the chat's history keeps only the title and a note
 *     (for_models()), so an app's notes about people never reach a model provider.
 *
 * Today: roles.who_can (Team Roles: "who can use a second-story ladder?", "can Sam do roof access?"). Never the shared
 * device, never with a widget block shared into the chat. Every answer is cut of access codes (ZANA_Sealed).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_App_Reads {

	/**
	 * The allow-list. verb => adapter. `kind` is the turn records' word for the verb. `detect` / `follow` / `read` /
	 * `header` / `owns` are this class's own static methods. Adding the next app's read is a row here, a detector and its
	 * tests.
	 */
	const ADAPTERS = array(
		'roles.who_can' => array(
			'app'    => 'Team Roles',
			'kind'   => 'roles',
			'detect' => 'roles_detect',
			'follow' => 'roles_follow_up',
			'read'   => 'roles_read',
			'header' => 'roles_header',
			'owns'   => 'roles_owns',
		),
	);

	/**
	 * Verbs the chat asks through this reader for its own use (never detected or routed here, never rendered here): the
	 * same registry-only ask() — the allow-list, the session-user check, no identity in the payload, no write.
	 * territories.zones: which zones only the admin tier may see (the charts route's guard); its answer is never shown or
	 * sent to a model.
	 */
	const MARKER_VERBS = array(
		'territories.zones' => array( 'app' => 'Territories' ),
	);

	/** Is this verb on either allow-list? */
	public static function allowed( $verb ) {
		return isset( self::ADAPTERS[ $verb ] ) || isset( self::MARKER_VERBS[ $verb ] );
	}

	/** The keys a payload may never carry: the registry names the asker. */
	const IDENTITY_KEYS = array( 'user_id', 'requesting_user_id', 'tier', 'is_kiosk' );

	/** Injected in tests: why_not (callable $verb, $uid => '' | reason), and the verb's answer (callable $verb, $payload, $uid). */
	public static $why_not = null;
	public static $invoke = null;

	/** Answers already asked this request (route stage, then render): uid|verb|payload => answer. Never stored anywhere else. */
	private static $memo = array();

	/** Forget this request's answers. The chat calls it at the start of every turn (one background request can run several
	 * turns). */
	public static function reset() {
		self::$memo = array();
	}

	/**
	 * The chat history as a model may see it: an app's answer shown in an earlier turn is replaced by its title and a
	 * note, so the app's words (names, notes) never reach a model provider on a later turn. The app's question back
	 * ("Which one: …?") lists only the app's own labels and is kept (a short reply is read against it). The chat applies
	 * this to the history it sends, once a turn.
	 */
	public static function for_models( array $history ) {
		foreach ( $history as $i => $h ) {
			if ( is_array( $h ) && 'assistant' === strtolower( (string) ( $h['role'] ?? '' ) ) && isset( $h['content'] ) ) {
				$history[ $i ]['content'] = self::model_text( (string) $h['content'] );
			}
		}
		return $history;
	}

	/**
	 * One stored assistant message as a model may see it: an app's answer (its source line on a line of its own,
	 * anywhere) becomes its title and a note; anything else is unchanged.
	 */
	public static function model_text( $content ) {
		$c = (string) $content;
		foreach ( self::ADAPTERS as $verb => $ad ) {
			if ( false !== strpos( $c, self::mark( $verb ) ) && preg_match( '/(?:^|\n)' . preg_quote( self::mark( $verb ), '/' ) . '[ \t]*(?:\r?\n|$)/', $c ) ) {
				$head = preg_match( '/^\*\*[^\n]*\*\*/', $c, $hm ) ? $hm[0] . "\n\n" : '';
				return $head . '(' . $ad['app'] . '\'s answer was shown to the person here. It is not shared with the assistant.)';
			}
		}
		// An hours answer is kept out of what a model sees on later turns the same way: it keeps its title (the period)
		// and its source line, which its own follow-up ("and last week?") reads.
		foreach ( self::REDACTED as $mark => $o ) {
			if ( false !== strpos( $c, $mark ) && preg_match( '/(?:^|\n)' . preg_quote( $mark, '/' ) . '[ \t]*(?:\r?\n|$)/', $c ) ) {
				$head = isset( $o['head'] ) ? $o['head'] . "\n\n" : ( preg_match( '/^\*\*[^\n]*\*\*/', $c, $hm ) ? $hm[0] . "\n\n" : '' );
				return $head . '(' . $o['app'] . '\' answer was shown to the person here. It is not shared with the assistant.' . ( $o['tail'] ?? '' ) . ')'
					. ( ! empty( $o['keep_mark'] ) ? "\n\n" . $mark : '' );
			}
		}
		return $c;
	}

	/** Answers this reader does not render that are kept out of later turns' history too: source line => how. */
	const REDACTED = array(
		'_From Hours._' => array( 'app' => 'Hours', 'keep_mark' => true ),
	);

	/** The source line under a real answer: "_From Team Roles._". A follow-up, the fast lane and the ask bar read it. */
	public static function mark( $verb ) {
		return '_From ' . ( self::ADAPTERS[ $verb ]['app'] ?? 'the app' ) . '._';
	}

	/** The line under the owner's own question back ("Which one: …?"): a short reply is sent to the same verb. */
	public static function ask_mark( $verb ) {
		return '_' . ( self::ADAPTERS[ $verb ]['app'] ?? 'The app' ) . ' asks: reply with one of them._';
	}

	/** Is this rendered text a real answer from an app (its source line), not a refusal, a question or a failure? */
	public static function is_answer( $text ) {
		foreach ( array_keys( self::ADAPTERS ) as $verb ) {
			if ( preg_match( '/(?:^|\n)' . preg_quote( self::mark( $verb ), '/' ) . '\s*$/', (string) $text ) ) {
				return true;
			}
		}
		return false;
	}

	/* ── The route ─────────────────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * The route: the app's rendered answer for this turn, or null when no app on the allow-list recognises the question
	 * (the chat goes on as usual). The verb is asked HERE, so the route is taken only when the app recognises the
	 * question (an adapter's `read`). Never the shared device, never with a widget block shared in, never a long message.
	 */
	public static function route( int $uid, string $msg, array $history = array(), bool $shared_device = false ): ?array {
		if ( $shared_device || $uid <= 0 || '' === trim( $msg ) || strlen( $msg ) > 400 || false !== strpos( $msg, '[CONTINUATION]' ) ) {
			return null;
		}
		$fence = class_exists( 'ZANA_Markers' ) ? ZANA_Markers::data_start() : '';
		if ( '' !== $fence && false !== strpos( $msg, $fence ) ) {
			return null;
		}
		foreach ( self::ADAPTERS as $verb => $ad ) {
			$payload = call_user_func( array( __CLASS__, $ad['detect'] ), $msg );
			if ( null === $payload && $history ) {
				$payload = call_user_func( array( __CLASS__, $ad['follow'] ), $msg, $history );
			}
			if ( ! is_array( $payload ) ) {
				continue;
			}
			// the detector's own hints ("_" keys) are never sent to the app (clean_payload drops them)
			$hints   = array_filter( $payload, function ( $k ) { return is_string( $k ) && '_' === substr( $k, 0, 1 ); }, ARRAY_FILTER_USE_KEY );
			$payload = self::clean_payload( $payload );
			$why     = self::why_not( $verb, $uid );
			if ( 'tier' === $why || 'app' === $why ) {
				// The app answers this, and this person may not ask it: say so, so the model doesn't guess -- but only when the
				// words are plainly the app's ("who can reset the router" is not; the app cannot be asked whether it knows them).
				if ( empty( $ad['owns'] ) || ! call_user_func( array( __CLASS__, $ad['owns'] ), $payload ) ) {
					return null;
				}
				return self::answer( array( 'verb' => $verb, 'payload' => array(), 'mode' => 'refused' ), $uid );
			}
			if ( '' !== $why ) {
				return null; // unknown (the app is not here), signed out, the shared device: the chat goes on as usual
			}
			if ( 'planner' === call_user_func( array( __CLASS__, $ad['read'] ), $verb, $payload, $uid, $hints ) ) {
				return null;
			}
			return self::answer( array( 'verb' => $verb, 'payload' => $payload, 'mode' => 'ask' ), $uid );
		}
		return null;
	}

	/** Plain values only, never an identity key, each string at most 120 characters. */
	public static function clean_payload( array $payload ) {
		$out = array();
		foreach ( $payload as $k => $v ) {
			if ( ! is_string( $k ) || in_array( $k, self::IDENTITY_KEYS, true ) || ! preg_match( '/^[a-z][a-z_]{0,30}$/', $k ) ) {
				continue;
			}
			if ( is_string( $v ) ) {
				$v = trim( (string) preg_replace( '/\s+/', ' ', $v ) );
				$v = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 120 ) : substr( $v, 0, 120 );
				if ( '' !== $v ) {
					$out[ $k ] = $v;
				}
			} elseif ( is_int( $v ) || is_bool( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/* ── The registry ───────────────────────────────────────────────────────────────────────────────────────────── */

	/** '' when this person may ask the verb; else the registry's reason (unknown | signed_out | kiosk | tier | app | write). */
	public static function why_not( $verb, $uid ) {
		if ( ! self::allowed( $verb ) ) {
			return 'unknown'; // never a verb off the allow-list
		}
		if ( is_callable( self::$why_not ) ) {
			return (string) call_user_func( self::$why_not, $verb, (int) $uid );
		}
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'get' ) || ! method_exists( 'ZDZ_Capabilities', 'why_not' ) ) {
			return 'unknown'; // no registry, no route (the theme carries it)
		}
		$row = ZDZ_Capabilities::get( (string) $verb );
		if ( ! is_array( $row ) ) {
			return 'unknown';
		}
		if ( ! empty( $row['side_effect'] ) ) {
			return 'write'; // a read verb that turned into a write is never run here
		}
		return (string) ZDZ_Capabilities::why_not( (string) $verb, (int) $uid );
	}

	/**
	 * The app's answer, through ZDZ_Capabilities::invoke() only, for the session user only. Memoised for this request
	 * (the route asks; the answer reads the same reply). Never throws: a failure is an error envelope.
	 */
	public static function ask( $verb, array $payload, $uid ) {
		$payload = self::clean_payload( $payload );
		$key     = (int) $uid . '|' . $verb . '|' . md5( (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ) );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$r = array( 'success' => false, 'error' => 'not_asked' );
		try {
			if ( ! self::allowed( $verb ) ) {
				$r = array( 'success' => false, 'error' => 'not_allowed' );
			} elseif ( is_callable( self::$invoke ) ) {
				$r = (array) call_user_func( self::$invoke, $verb, $payload, (int) $uid );
			} elseif ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() !== (int) $uid || (int) $uid <= 0 ) {
				// The registry names the session user; asking for anyone else would answer the wrong person.
				$r = array( 'success' => false, 'error' => 'not_session_user' );
			} elseif ( '' !== ( $why = self::why_not( $verb, $uid ) ) ) {
				// a verb that is gone, or became a write, is a failure ("couldn't check"), never a refusal or "no one"
				$r = in_array( $why, array( 'unknown', 'write' ), true )
					? array( 'success' => false, 'error' => 'verb_' . $why )
					: array( 'success' => false, 'denied' => true, 'reason' => $why );
			} else {
				$r = (array) ZDZ_Capabilities::invoke( (string) $verb, $payload );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_App_Reads: ' . $verb . ' failed (' . get_class( $e ) . ').' );
			$r = array( 'success' => false, 'error' => 'threw' );
		}
		if ( class_exists( 'ZANA_Sealed' ) ) {
			$r = (array) ZANA_Sealed::deep( $r );   // whichever app answered, no access code reaches the chat
		}
		return self::$memo[ $key ] = $r;
	}

	/** What the answer is: answered | clarify | denied | failed. */
	public static function outcome( array $r ) {
		$msg = trim( (string) ( $r['message'] ?? '' ) );
		if ( ! empty( $r['success'] ) && empty( $r['denied'] ) && empty( $r['needs_clarify'] ) && '' !== $msg ) {
			return 'answered';
		}
		if ( ! empty( $r['needs_clarify'] ) && '' !== $msg ) {
			return 'clarify';
		}
		if ( ! empty( $r['denied'] ) || ( empty( $r['success'] ) && empty( $r['error'] ) && '' !== $msg ) ) {
			return 'denied'; // the registry's refusal, or the app's own "no" (a retired ability says so)
		}
		return 'failed';
	}

	/* ── The answer ─────────────────────────────────────────────────────────────────────────────────────────────── */

	/**
	 * Render the turn's app read for $uid. $for 'chat' (markdown for the chat's renderer) or 'bar' (a renderer that
	 * escapes every character itself). Returns array( markdown, outcome ).
	 */
	public static function answer( array $ar, int $uid, string $for = 'chat' ): array {
		$verb = (string) ( $ar['verb'] ?? '' );
		$ad   = self::ADAPTERS[ $verb ] ?? null;
		$app  = $ad ? self::app_label( $verb ) : __( 'that app', 'zorderz' );
		$warn = "\u{26A0}\u{FE0F} ";
		if ( ! $ad ) {
			/* translators: %s: an app's name */
			return array( 'markdown' => $warn . sprintf( __( 'I couldn\'t check %s just now.', 'zorderz' ), $app ), 'outcome' => 'failed' );
		}
		/* translators: %s: an app's name */
		$refused = sprintf( __( '%s answers that, and it isn\'t available on your account.', 'zorderz' ), $app );
		if ( 'refused' === ( $ar['mode'] ?? '' ) ) {
			return array( 'markdown' => $refused, 'outcome' => 'refused' );
		}
		$payload = (array) ( $ar['payload'] ?? array() );
		$r       = self::ask( $verb, $payload, $uid );
		$out     = self::outcome( $r );
		$msg     = trim( (string) ( $r['message'] ?? '' ) );
		$text    = 'bar' === $for ? self::plain( $msg ) : self::md_text( $msg );
		if ( 'answered' === $out ) {
			$head = (string) call_user_func( array( __CLASS__, $ad['header'] ), $r, $payload );
			$head = 'bar' === $for ? self::plain( $head ) : self::md_text( $head );
			return array( 'markdown' => "**{$head}**\n\n{$text}\n\n" . self::mark( $verb ), 'outcome' => 'answered' );
		}
		if ( 'clarify' === $out ) {
			// the app's question back; with options ("Which one: …?") a short reply goes to the same verb
			return array( 'markdown' => $text . ( empty( $r['options'] ) ? '' : "\n\n" . self::ask_mark( $verb ) ), 'outcome' => 'clarify' );
		}
		if ( 'denied' === $out ) {
			$reg = (string) ( $r['reason'] ?? '' );
			if ( '' === $msg || in_array( $reg, array( 'tier', 'app' ), true ) ) {
				return array( 'markdown' => $refused, 'outcome' => 'refused' );
			}
			return array( 'markdown' => $text, 'outcome' => 'denied' );
		}
		/* translators: %s: an app's name */
		return array( 'markdown' => $warn . sprintf( __( 'I couldn\'t check %1$s just now. Please ask again in a minute, or open %1$s.', 'zorderz' ), $app ), 'outcome' => 'failed' );
	}

	/** The app's name as the person sees it (translated). */
	public static function app_label( string $verb ): string {
		switch ( $verb ) {
			case 'roles.who_can':
				return __( 'Team Roles', 'zorderz' );
			case 'territories.zones':
				return __( 'Territories', 'zorderz' );
		}
		return __( 'the app', 'zorderz' );
	}

	/** The app's words as markdown TEXT: no tag, link, emphasis or marker can come out of them. */
	public static function md_text( $text ) {
		$t = htmlspecialchars( (string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		return (string) preg_replace( '/([\\\\`*_{}\[\]()#+\-.!|~>:=])/', '\\\\$1', $t );
	}

	/** For the ask bar (it escapes every character itself): control characters out, no bracketed marker. */
	public static function plain( $text ) {
		$t = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
		return (string) preg_replace( '/\[(\/?\s*(?:ZDZ|ZANA|ZIB|ZIM)[A-Z]*_[A-Z0-9_]+)\s*\]/i', '($1)', $t );
	}

	/* ── roles.who_can (the Team Roles app) ──────────────────────────────────────────────────────────────────────── */

	/** Words that end the question without saying anything about the ability. */
	const ROLES_TAIL = '/\s+(?:on|in) (?:the|our) (?:team|crew|company)$|\s+(?:here|around here|right now|currently)$/';

	/**
	 * Not this route: a time or day (the scheduler's and availability's), a pronoun asker ("who can I call"), a customer,
	 * a job or a place, money, contact and help ("who can help with a refund"). Team Roles is about kinds of work, site
	 * conditions and tasks, so its own words never need these.
	 */
	const ROLES_NOT = '/\b(?:i|me|we|you|us|my|your|today|tonight|tomorrow|yesterday|now|asap|soon|when|time|times|morning|afternoon|evening|week|weekend|month|next|monday|tuesday|wednesday|thursday|friday|saturday|sunday|mondays|tuesdays|wednesdays|thursdays|fridays|saturdays|sundays|am|pm|noon|schedule|scheduled|available|availability|free|open|appointment|appointments|come|came|coming|out|over|by|call|phone|text|email|e-mail|contact|reach|talk|speak|ask|help|refund|refunds|pay|paid|money|cost|price|quote|approve|sign|answer|customer|customers|client|house|home|address|street|job|jobs|project|sold|most|best|fastest|kiosk|chat|app|it|this|that|them|those|these|something|anything|everything)\b|\d|\'s\b/';

	/** "someone", "anyone" … : the whole team, not a person's name. */
	const ROLES_NOBODY = '/^(?:i|you|we|they|he|she|it|me|us|them|someone|somebody|anyone|anybody|everyone|everybody|no one|nobody|the|this|that|these|those|my|our|your|a|an|there|who|what|which|team|crew|kiosk|chat|bot|app|u|hours|charts?|projects?|jobs|estimates?|scheduler|schedule|calendar|roles|inbox|stock|leads|doorhangers|camera|receipts?|territories|routes?|analytics|chat|freshbooks|nutshell|google|siri|alexa|it\'s|messages|media|sketch|surveys?|commission|vault|contacts|settings|notifications|home|techs?|installers?|crews|guys|people|folks|staff|salespeople|sales|admins?|leads?|everyone|and|or|both|all)$/';

	/** A work verb a person-form question may use without "do" ("can Sam install the fence?"). */
	const ROLES_WORK = 'install|repair|cut|prep|sell|measure|estimate|climb|drive|fix|service';

	/**
	 * "who can do weekend installs?", "who's able to use a second-story ladder", "who knows how to measure",
	 * "is anyone trained on example widgets", "can anyone drive the trailer" -> array( query ).
	 * "can Sam use a second-story ladder?", "is Riley able to do roof access" -> array( query, person ).
	 * Pure: the words only. Team Roles decides whether it knows the ability, and matches the name; the assistant never does.
	 */
	public static function roles_detect( $message ) {
		$m = strtolower( str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", (string) $message ) );
		$m = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[?!.,;:]+/', ' ', $m ) ) );
		$m = trim( (string) preg_replace( '/^(?:so|ok|okay|hey|and|also|quick question)\s+/', '', $m ) );
		if ( '' === $m || strlen( $m ) > 160 ) {
			return null;
		}
		$m    = trim( (string) preg_replace( self::ROLES_TAIL, '', $m ) );
		$x    = null;
		$name = '';
		$does = false;
		$help = '(?:do |use |handle |work on |work |go on |go in |go into |run |operate )?';
		$who  = '(?:who|who all|which (?:people|techs|installers|of us|ones))';
		if ( preg_match( '/^' . $who . '(?:\'s| is| are)? (?:on (?:the|our) (?:team|crew) )?(?:can|could|is able to|are able to) ' . $help . '(.+)$/', $m, $g )
			|| preg_match( '/^' . $who . '(?:\'s| is| are) (?:able|allowed|trained|qualified|certified|cleared|ok|okay) (?:to|for|on|in|with) ' . $help . '(.+)$/', $m, $g )
			|| preg_match( '/^' . $who . ' knows? how to ' . $help . '(.+)$/', $m, $g )
			|| preg_match( '/^(?:is|are) (?:anyone|anybody|someone|somebody|there anyone|there someone) (?:on (?:the|our) (?:team|crew) )?(?:able to|allowed to|trained (?:on|in|to|for)|qualified (?:to|for|on)|certified (?:to|for|on)|cleared (?:to|for)) ' . $help . '(.+)$/', $m, $g )
			|| preg_match( '/^(?:does|do) (?:anyone|anybody|someone|somebody) know how to ' . $help . '(.+)$/', $m, $g )
			|| preg_match( '/^(?:can|could) (?:anyone|anybody|someone|somebody) (?:on (?:the|our) (?:team|crew) )?' . $help . '(.+)$/', $m, $g ) ) {
			$x = $g[1];
		} elseif ( preg_match( '/^' . $who . ' (sells|installs|repairs|services|fixes) (.+)$/', $m, $g ) ) {
			// Sell, Install and Repair per item: "who installs example widgets?", "who sells fences?" -- the role word stays
			// in the question, so Team Roles answers that one cell
			$x = $g[1] . ' ' . $g[2];
		} elseif ( preg_match( '/^' . $who . ' (?:does|handles|works on|do|handle|work on) (.+)$/', $m, $g ) ) {
			// "who does example widgets?" names the item alone -- Team Roles answers one line per role
			$x = $g[1];
		} elseif ( preg_match( '/^(?:can|could) ([a-z][a-z\'.-]*(?: [a-z][a-z\'.-]*){0,2}?) (?:do|use|handle|work on|go on|go in|go into|run|operate) (.+)$/', $m, $g )
			|| preg_match( '/^(?:is|does) ([a-z][a-z\'.-]*(?: [a-z][a-z\'.-]*){0,2}?) (?:able to|allowed to|trained (?:on|in|to|for)|qualified (?:to|for|on)|certified (?:to|for|on)|cleared (?:to|for)|knows? how to) ' . $help . '(.+)$/', $m, $g ) ) {
			$name = $g[1];
			$x    = $g[2];
		} elseif ( preg_match( '/^(?:can|could) ([a-z][a-z\'.-]*(?: [a-z][a-z\'.-]*){0,2}?) ((?:' . self::ROLES_WORK . ')\b.*)$/', $m, $g )
			|| preg_match( '/^(?:does|do) ([a-z][a-z\'.-]*(?: [a-z][a-z\'.-]*){0,2}?) ((?:sell|install|repair|service|fix)\b.*)$/', $m, $g ) ) {
			// "does Alex sell example widgets?" -- one person, one cell
			$name = $g[1];
			$x    = $g[2];
			$does = 0 === strpos( $m, 'do' ); // "does Example Supply sell …" may be a shop, not a teammate
		}
		if ( null === $x ) {
			return null;
		}
		if ( '' !== $name ) {
			foreach ( explode( ' ', $name ) as $w ) {
				if ( preg_match( self::ROLES_NOBODY, $w ) || preg_match( '/^(?:' . self::ROLES_WORK . '|do|use|be|get|go|see|make)$/', $w ) ) {
					return null; // "can you …", "can we …", "can the crew …": not a person's name
				}
			}
		}
		$x = trim( (string) preg_replace( '/^(?:a|an|the|some|any)\s+/', '', trim( $x ) ) );
		$n = count( explode( ' ', $x ) );
		// "2nd story" is a word Team Roles knows; any other digit is a count, a time or an address
		if ( '' === $x || $n > 8 || preg_match( self::ROLES_NOT, (string) preg_replace( '/\b[12](?:st|nd)\b/', 'ord', $x ) ) ) {
			return null;
		}
		$out = array( 'query' => $x );
		if ( '' !== $name ) {
			$out['person'] = $name;
			if ( $does ) {
				$out['_named_only'] = true; // a name Team Roles does not know goes on as usual, never "no one by that name"
			}
		}
		return $out;
	}

	/**
	 * Right after Team Roles asked "Which one: …?", a short reply ("the second-story one", "Second-story ladder") goes
	 * to the same verb; a person named in the question before it is asked about again.
	 */
	public static function roles_follow_up( $message, array $history ) {
		$last = '';
		$prev = '';
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			$role = strtolower( (string) ( $history[ $i ]['role'] ?? '' ) );
			if ( '' === $last && 'assistant' === $role ) {
				$last = (string) ( $history[ $i ]['content'] ?? '' );
				continue;
			}
			if ( '' !== $last && 'user' === $role ) {
				$prev = (string) ( $history[ $i ]['content'] ?? '' );
				break;
			}
		}
		if ( false === strpos( $last, self::ask_mark( 'roles.who_can' ) ) ) {
			return null;
		}
		$m = strtolower( str_replace( array( "\u{2019}", "\u{2018}" ), "'", (string) $message ) );
		$m = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[?!.,;:]+/', ' ', $m ) ) );
		$m = trim( (string) preg_replace( '/^(?:the|i mean|i meant|um|oh|ok|okay)\s+/', '', $m ) );
		$m = trim( (string) preg_replace( '/\s+(?:one|please)$/', '', $m ) );
		if ( '' === $m || count( explode( ' ', $m ) ) > 6 || preg_match( self::ROLES_NOT, (string) preg_replace( '/\b[12](?:st|nd)\b/', 'ord', $m ) ) ) { // "2nd" 
			return null;
		}
		// the question back, as plain words, so roles_read() can check the reply named one of its options
		$tie  = '';
		foreach ( preg_split( '/\r\n|\r|\n/', $last ) as $line ) {
			if ( preg_match( '/^which one/i', ltrim( $line ) ) ) {
				$tie = html_entity_decode( (string) preg_replace( '/\\\\(.)/', '$1', $line ), ENT_QUOTES, 'UTF-8' );
				break;
			}
		}
		if ( '' === $tie ) {
			return null;
		}
		// the reply with the question it answers ("ladder" + "second story"): Team Roles reads them together
		$out  = array( 'query' => $m, '_within' => $tie );
		$orig = self::roles_detect( $prev );
		if ( is_array( $orig ) ) {
			$out['query'] = trim( (string) ( $orig['query'] ?? '' ) . ' ' . $m );
			if ( ! empty( $orig['person'] ) ) {
				$out['person'] = $orig['person'];
			}
		}
		return $out;
	}

	/**
	 * Did Team Roles recognise the question? 'route' or 'planner'. Its "Which ability? Team Roles has: …" (no ability,
	 * no options) means no: the chat goes on as usual ('planner'). A tie ("Which one: …?", with options) is shown. A
	 * refusal or a failure is said (never the model, which would guess). For one person, the ability is checked first WITHOUT the
	 * name, so "No one on the team by that name" is told apart from "no such ability" by the answer's shape, not its words.
	 */
	public static function roles_read( $verb, array $payload, $uid, array $hints = array() ) {
		$probe = isset( $payload['person'] ) ? array( 'query' => (string) ( $payload['query'] ?? '' ) ) : $payload;
		$r     = self::ask( $verb, $probe, $uid );
		$out   = self::outcome( $r );
		if ( 'clarify' === $out && empty( $r['options'] ) && empty( $r['ability'] ) ) {
			return 'planner';
		}
		if ( 'answered' !== $out && 'clarify' !== $out ) {
			// Team Roles could not be asked or said no: say so only when the words are plainly its kind; otherwise the chat goes
			// on as usual (a failure must not capture "who can drive to Springfield")
			return self::roles_owns( $payload ) ? 'route' : 'planner';
		}
		// Team Roles matches on any one shared word ("unlock the shop" -> Open and close the shop,
		// "drive to Springfield" -> Drive the truck or trailer, "repair the truck" -> every repair and Drive). The chat takes the
		// route only when ONE ability -- the answer, or one of the options a tie offers -- accounts for EVERY content word of
		// the question: a word of its own name or key, or a word Team Roles itself, asked about that word alone, ties to that
		// same ability ("covers" is Widget covers, "fix" is a repair, "upstairs" is second-story: its words, never copied here).
		$query = (string) ( $payload['query'] ?? '' );
		// An ITEM's answer names a role too ("install", "sells", "fixing"): a word of that kind
		// is Team Roles' to vouch for, asked with the item's own name ("install Example widgets" -> that item, role
		// install). A plain ability's answer never gets this ("repair the truck" is still no one ability's).
		$item_of = array();
		$role_of = ''; // the role Team Roles answered; a role word must be THAT role ("sells and installs": two)
		if ( 'answered' === $out ) {
			$cands = array( (string) ( $r['ability']['key'] ?? '' ) => (string) ( $r['ability']['label'] ?? '' ) );
			$item_of[ (string) ( $r['ability']['key'] ?? '' ) ] = 'item' === (string) ( $r['ability']['group'] ?? '' );
			$role_of = 'item' === (string) ( $r['ability']['group'] ?? '' ) ? (string) ( $r['ability']['role'] ?? '' ) : '';
		} else {
			$cands = array();
			foreach ( (array) ( $r['options'] ?? array() ) as $k ) {
				$cands[ (string) $k ] = '';
				$item_of[ (string) $k ] = null; // a tie's option: Team Roles says, when asked
			}
		}
		$fits = false;
		foreach ( $cands as $key => $label ) {
			if ( '' === (string) $key ) {
				// an answer with a name and no key: its own words only (nothing to ask Team Roles about)
				if ( '' !== trim( $label ) && self::covers( $query, $label ) ) {
					$fits = true;
					break;
				}
				continue;
			}
			$is_item = $item_of[ (string) $key ] ?? null;
			$name    = '' !== trim( $label ) ? $label : str_replace( '_', ' ', (string) $key );
			$vouch   = function ( $word ) use ( $verb, $uid, $key, $is_item, $name, $role_of ) {
				$w = self::ask( $verb, array( 'query' => $word ), $uid );
				$k = 'answered' === self::outcome( $w ) ? array( (string) ( $w['ability']['key'] ?? '' ) ) : array_map( 'strval', (array) ( $w['options'] ?? array() ) );
				if ( in_array( $key, $k, true ) ) {
					return true;
				}
				if ( false === $is_item ) {
					return false;
				}
				// the word with the item's name: Team Roles answers that item WITH a role -> a role word
				$c = self::ask( $verb, array( 'query' => $word . ' ' . $name ), $uid );
				$rr = (string) ( $c['ability']['role'] ?? '' );
				return 'answered' === self::outcome( $c ) && (string) ( $c['ability']['key'] ?? '' ) === (string) $key
					&& 'item' === (string) ( $c['ability']['group'] ?? '' ) && '' !== $rr && ( '' === $role_of || $rr === $role_of );
			};
			if ( self::covers( $query, $label . ' ' . str_replace( '_', ' ', $key ), $vouch ) ) {
				$fits = true;
				break;
			}
		}
		if ( ! $fits ) {
			return 'planner';
		}
		// A reply to Team Roles' "Which one: …?" routes only to one of the names it offered, whole.
		if ( isset( $hints['_within'] ) ) {
			$label = trim( (string) ( $r['ability']['label'] ?? '' ) );
			if ( 'answered' !== $out || ! self::offered( $label, (string) $hints['_within'] ) ) {
				return 'planner';
			}
		}
		if ( isset( $payload['person'] ) && 'answered' === $out ) {
			$pa = self::ask( $verb, $payload, $uid ); // the person's own answer, memoised for the render step
			if ( ! empty( $hints['_named_only'] ) && 'answered' !== self::outcome( $pa ) ) {
				return 'planner'; // "does Example Supply sell example widgets?" -- not someone on the team
			}
		}
		return 'route';
	}

	/** Words a who-can question carries that say nothing about the ability (Team Roles drops the same kind). */
	const ROLES_FILLER = array( 'a', 'an', 'the', 'and', 'or', 'of', 'to', 'on', 'onto', 'in', 'into', 'at', 'up', 'for', 'with', 'do', 'doing', 'use', 'using',
		'work', 'handle', 'go', 'get', 'run', 'operate', 'climb', 'climbing', 'our', 'some', 'any', 'kind', 'kinds', 'type', 'types', 'please', 'be', 'here',
		'there', 'me', 'tell', 'list', 'show', 'who', 'can', 'could', 'is', 'are', 'able', 'allowed', 'trained', 'qualified', 'certified', 'know', 'knows', 'how' );

	/** Is $label one of the names in Team Roles' "Which one: A, B?" -- whole, as written (not "Install" inside "new install"). */
	public static function offered( $label, $tie ) {
		$label = trim( (string) $label );
		return '' !== $label && (bool) preg_match( '/(?::|,) ' . preg_quote( $label, '/' ) . '(?:,|\?)/', (string) $tie );
	}

	/**
	 * The forms a word may take (conservative, so "Sam" is not "same" and "trail" is not "trailer"): the word,
	 * its singular ("doors", "stories", "spaces"), and the base of an -ing / -ation / -ized form ("installing",
	 * "installation", "estimating" -> estimate, "cutting" -> cut, "motorized" -> motor). Anything else is Team Roles' to vouch for.
	 */
	public static function forms( $w ) {
		$w = strtolower( (string) $w );
		$f = array( $w => true );
		if ( strlen( $w ) > 4 && 'ies' === substr( $w, -3 ) ) {
			$f[ substr( $w, 0, -3 ) . 'y' ] = true;
		} elseif ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
			$f[ substr( $w, 0, -1 ) ] = true;
		}
		foreach ( array( 'ations' => 1, 'ation' => 1, 'ings' => 1, 'ing' => 1, 'ized' => 0, 'ised' => 0 ) as $suf => $e ) {
			$l = strlen( $suf );
			if ( strlen( $w ) - $l >= 3 && substr( $w, -$l ) === $suf ) {
				$b = substr( $w, 0, -$l );
				$f[ $b ] = true;
				if ( $e ) {
					$f[ $b . 'e' ] = true;
					$n = strlen( $b );
					if ( $n > 2 && $b[ $n - 1 ] === $b[ $n - 2 ] ) {
						$f[ substr( $b, 0, -1 ) ] = true; // "cutting" -> cut
					}
				}
				break;
			}
		}
		return array_keys( $f );
	}

	/** The words of a text: lower case, "re-hang" as one word, split on anything else. */
	public static function words_of( $s ) {
		$s = strtolower( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		$s = (string) preg_replace( '/\bre-(?=[a-z])/', 're', $s );
		return preg_split( '/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Every content word of the question is one of $own's words, in any of its forms(). A word left over may be vouched
	 * for by $vouch (the word's owning ability, asked about that word alone); more than three left over is never one ability's question.
	 */
	public static function covers( $query, $own, $vouch = null ) {
		$have = array();
		foreach ( self::words_of( $own ) as $h ) {
			foreach ( self::forms( $h ) as $x ) {
				$have[ $x ] = true;
			}
		}
		$left = array();
		foreach ( self::words_of( $query ) as $w ) {
			if ( in_array( $w, self::ROLES_FILLER, true ) ) {
				continue;
			}
			$hit = false;
			foreach ( self::forms( $w ) as $x ) {
				if ( isset( $have[ $x ] ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				$left[ $w ] = true;
			}
		}
		if ( ! $left ) {
			return true;
		}
		if ( ! is_callable( $vouch ) || count( $left ) > 3 ) {
			return false;
		}
		foreach ( array_keys( $left ) as $w ) {
			if ( ! call_user_func( $vouch, (string) $w ) ) {
				return false;
			}
		}
		return true;
	}

	/** Tests hand in the abilities: [ { key, label, group, roles: [ { role, label } ] } ]. */
	public static $abilities = null;

	/**
	 * For an asker the registry refuses (the verb is staff tier), or when Team Roles cannot be asked, it cannot say
	 * whether it knows the words, so "not available on your account" / "couldn't check" is said only when ONE ability
	 * of the team's own list accounts for every word ("who can repair the truck" or "access the shop" is no one
	 * ability's). The list is Team Roles' own (filter zdz_abilities): each ability's name and key words, and for an
	 * item its role words (sell / seller, install / installer, repair / fix). It decides only whether to say that
	 * sentence, never answers anything. With no Team Roles app the list is empty and nothing is claimed.
	 */
	public static function roles_vocab(): array {
		$rows = is_array( self::$abilities ) ? self::$abilities : ( function_exists( 'apply_filters' ) ? apply_filters( 'zdz_abilities', null, array() ) : null );
		$out  = array();
		$syn  = array( 'sell' => 'sell seller sells', 'install' => 'install installer installs', 'repair' => 'repair fix fixes repairs service' );
		foreach ( (array) $rows as $a ) {
			if ( ! is_array( $a ) || empty( $a['key'] ) ) {
				continue;
			}
			$words = trim( (string) ( $a['label'] ?? '' ) . ' ' . str_replace( '_', ' ', (string) $a['key'] ) );
			foreach ( (array) ( $a['roles'] ?? array() ) as $r ) {
				$role   = strtolower( (string) ( is_array( $r ) ? ( $r['role'] ?? '' ) : $r ) );
				$words .= ' ' . ( $syn[ $role ] ?? $role ) . ' ' . strtolower( (string) ( is_array( $r ) ? ( $r['label'] ?? '' ) : '' ) );
			}
			$out[] = trim( (string) preg_replace( '/\s+/', ' ', strtolower( $words ) ) );
		}
		return array_values( array_filter( array_unique( $out ) ) );
	}

	public static function roles_owns( array $payload ) {
		foreach ( self::roles_vocab() as $words ) {
			if ( self::covers( (string) ( $payload['query'] ?? '' ), $words ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "Who can · Second-story ladder" / "One person · Second-story ladder". An item:
	 * "Who can install · Example widgets", "One person · Example widgets (install)", and for the item alone
	 * (one line per role) "Who can · Example widgets".
	 */
	public static function roles_header( array $r, array $payload ) {
		$label = trim( (string) ( $r['ability']['label'] ?? '' ) );
		$role  = 'item' === (string) ( $r['ability']['group'] ?? '' ) ? strtolower( trim( (string) ( $r['ability']['role'] ?? '' ) ) ) : '';
		$role  = preg_match( '/^[a-z]{2,12}$/', $role ) ? $role : '';
		if ( '' === $label ) {
			return 'Team Roles';
		}
		if ( isset( $payload['person'] ) ) {
			return 'One person · ' . $label . ( '' !== $role ? ' (' . $role . ')' : '' );
		}
		return 'Who can' . ( '' !== $role ? ' ' . $role : '' ) . ' · ' . $label;
	}
}
