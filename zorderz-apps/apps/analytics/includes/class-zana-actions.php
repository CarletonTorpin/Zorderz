<?php
/**
 * ZANA_Actions — what the chat may ask an app to do, and the Confirm card that does it.
 *
 * Every action the chat can take is a verb on the platform's registry (ZDZ_Capabilities, filter
 * `zdz_register_capabilities`), with its own side_effect, app and needs_app. The model never runs one. It may write an
 * action block in its answer:
 *
 *     [ZDZ_ACTION]{"verb":"appointment.create","args":{…}}[/ZDZ_ACTION]
 *
 * (or an app's own marker, published on `zdz_chat_markers` with the verb it stands for), and this class decides what
 * becomes of it, in this order:
 *
 *   1. a marker narrated in backticks is defused first (an explanation never runs);
 *   2. PROVENANCE: a block that appears word for word in the data the turn was given, or in what the person pasted, was
 *      echoed from a record, not decided: it is refused and logged (indirect injection);
 *   3. the registry decides who may: an unknown verb, the shared device, a tier below the verb's, an app the person
 *      cannot open — each is refused in words. A Privacy View account changes nothing, ever;
 *   4. a READ runs through ZDZ_Capabilities::invoke() (which stamps the signed-in person over anything the model wrote)
 *      and the app's own words are shown under its name; a failure is "I couldn't check <app> just now", never "none";
 *   5. a WRITE goes through ZDZ_Capabilities::invoke() too, which answers needs_confirm and runs nothing. Its arguments
 *      are stored here (per person AND chat, 10 minutes, at most five open), and the answer gets a Confirm card. At most
 *      ten write blocks per verb per person per hour are held; the rest are refused.
 *
 * NO TEXT THE PERSON TYPES EVER COMMITS A WRITE. Only a press of Confirm does (confirm()): it consumes the stored row
 * exactly once (an atomic claim in the database, so two presses or a retried request never write twice), asks the
 * registry again whether this person may (their tier or the app may have changed), runs the verb's own callback with the
 * STORED arguments, the person stamped over them and `confirmed` set, and records the press with who pressed it. Nothing
 * from the press is read but the row's id. A lost row means "ask again": it fails closed, never open.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Actions {

	/** How long a held action stays confirmable: long enough to read and think, short enough that tomorrow cannot commit tonight's. */
	const TTL = 600;

	const PREFIX     = 'zana_pact_';
	const IDX_PREFIX = 'zana_pactidx_';
	const CLAIM      = '_zana_pact_claim_';

	/** More open previews than this in one chat is confusion: the oldest is dropped. */
	const MAX_PENDING = 5;

	/** Write blocks held per verb, per person, per hour. */
	const RATE_CAP = 10;

	/** The longest arguments a block may carry (bytes of JSON). */
	const MAX_ARGS = 20000;

	/** Seams for the tests: callable( $verb, $uid ) => reason; callable( $verb ) => row|null; callable( $verb, $args ) => array. */
	public static $why_not = null;
	public static $get     = null;
	public static $invoke  = null;

	/* ───────────────────────────── the registry ───────────────────────────── */

	private static function why_not( string $verb, int $uid ): string {
		if ( is_callable( self::$why_not ) ) {
			return (string) call_user_func( self::$why_not, $verb, $uid );
		}
		return class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::why_not( $verb, $uid ) : 'unknown';
	}

	private static function get( string $verb ): ?array {
		if ( is_callable( self::$get ) ) {
			$r = call_user_func( self::$get, $verb );
			return is_array( $r ) ? $r : null;
		}
		return class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::get( $verb ) : null;
	}

	private static function invoke( string $verb, array $args ): array {
		if ( is_callable( self::$invoke ) ) {
			return (array) call_user_func( self::$invoke, $verb, $args );
		}
		return class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::invoke( $verb, $args ) : array( 'success' => false, 'denied' => true, 'reason' => 'unknown' );
	}

	/** The refusal for a registry reason, in words. */
	public static function refusal_words( string $why, string $verb ): string {
		switch ( $why ) {
			case 'kiosk':
				return __( 'That isn\'t available on the shared device, so I didn\'t do it.', 'zorderz' );
			case 'tier':
			case 'app':
				return __( 'That isn\'t available on your account, so I didn\'t do it.', 'zorderz' );
			case 'signed_out':
				return __( 'You need to be signed in for that.', 'zorderz' );
			case 'privacy_view':
				return __( 'This account can look, not change anything, so I didn\'t do it.', 'zorderz' );
			default:
				/* translators: %s: an action name */
				return sprintf( __( '"%s" isn\'t something the apps here can do, so I didn\'t do anything.', 'zorderz' ), self::defuse( $verb ) );
		}
	}

	/* ───────────────────────────── reading the answer ───────────────────────────── */

	/**
	 * Every action block in an answer: [ { block, verb, args (array|null when unreadable) } ], in order. The generic
	 * marker names its verb inside; an app's own marker (from `zdz_chat_markers`, a row with `verb` and `token` of the
	 * form "[OPEN]…[/OPEN]") stands for its verb and carries only the arguments.
	 */
	public static function blocks( string $text ): array {
		$out = array();
		if ( preg_match_all( '/\[ZDZ_ACTION\](.*?)\[\/ZDZ_ACTION\]/s', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$j    = strlen( $x[1] ) <= self::MAX_ARGS ? json_decode( trim( $x[1] ), true ) : null;
				$verb = is_array( $j ) && is_string( $j['verb'] ?? null ) ? strtolower( trim( $j['verb'] ) ) : '';
				$out[] = array(
					'block' => $x[0],
					'verb'  => $verb,
					'args'  => is_array( $j ) && '' !== $verb ? ( is_array( $j['args'] ?? null ) ? $j['args'] : array() ) : null,
				);
			}
		}
		foreach ( self::app_markers() as $verb => $tags ) {
			$re = '/' . preg_quote( $tags[0], '/' ) . '(.*?)' . preg_quote( $tags[1], '/' ) . '/s';
			if ( preg_match_all( $re, $text, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $x ) {
					$j     = strlen( $x[1] ) <= self::MAX_ARGS ? json_decode( trim( $x[1] ), true ) : null;
					$out[] = array( 'block' => $x[0], 'verb' => $verb, 'args' => is_array( $j ) ? $j : null );
				}
			}
		}
		return $out;
	}

	/** Apps' own markers: verb => [ open, close ]. Only well-formed "[NAME]…[/NAME]" tokens naming a dotted verb. */
	public static function app_markers(): array {
		$map = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_chat_markers', array() ) : array();
		$out = array();
		foreach ( (array) $map as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['verb'] ?? null ) || ! is_string( $row['token'] ?? null ) ) {
				continue;
			}
			$verb = strtolower( $row['verb'] );
			if ( ! preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $verb ) ) {
				continue;
			}
			if ( preg_match( '/^(\[([A-Z][A-Z0-9_]{2,40})\])…(\[\/\2\])$/u', $row['token'], $t ) && 'ZDZ_ACTION' !== $t[2] ) {
				$out[ $verb ] = array( $t[1], $t[3] );
			}
		}
		return $out;
	}

	/**
	 * The answer with each action block replaced by what became of it. $ctx: user_id, session_id, is_kiosk,
	 * privacy_view, sources (the texts the turn was given: the fenced data and the person's own message), msg_id.
	 */
	public static function process( string $answer, array $ctx ): string {
		$answer = ZANA_Markers::defuse_narrated( $answer );
		$blocks = self::blocks( $answer );
		if ( ! $blocks ) {
			return $answer;
		}
		$uid = (int) ( $ctx['user_id'] ?? 0 );
		$sid = (int) ( $ctx['session_id'] ?? 0 );
		foreach ( $blocks as $b ) {
			$answer = self::replace_once( $answer, $b['block'], self::outcome( $b, $uid, $sid, $ctx ) );
		}
		return $answer;
	}

	private static function replace_once( string $hay, string $needle, string $with ): string {
		$p = strpos( $hay, $needle );
		return false === $p ? $hay : substr( $hay, 0, $p ) . $with . substr( $hay, $p + strlen( $needle ) );
	}

	/** What one block becomes: a refusal, an app's answer, or a held write with its Confirm card. */
	private static function outcome( array $b, int $uid, int $sid, array $ctx ): string {
		// provenance: echoed from data, not decided
		foreach ( (array) ( $ctx['sources'] ?? array() ) as $src ) {
			if ( is_string( $src ) && '' !== $src && false !== stripos( $src, $b['block'] ) ) {
				error_log( 'ZANA_Actions: an action block appeared word for word in the data or the pasted text; refused.' );
				return "\u{26A0}\u{FE0F} " . __( 'I found that action inside pasted or record text, so I didn\'t run it. If you want it done, ask me directly.', 'zorderz' ) . "\n\n";
			}
		}
		if ( null === $b['args'] ) {
			return "\u{26A0}\u{FE0F} " . __( 'I couldn\'t read that request, so I haven\'t done anything. Please ask again.', 'zorderz' ) . "\n\n";
		}
		$verb = $b['verb'];
		$why  = self::why_not( $verb, $uid );
		if ( '' === $why && ( ! empty( $ctx['is_kiosk'] ) ) ) {
			$why = 'kiosk';
		}
		$row = self::get( $verb );
		if ( '' !== $why || ! $row ) {
			return self::refusal_words( '' !== $why ? $why : 'unknown', $verb ) . "\n\n";
		}
		$app = self::app_label( (string) ( $row['app'] ?? '' ) );
		if ( empty( $row['side_effect'] ) ) {
			$r = self::invoke( $verb, $b['args'] );
			return self::render_read( $r, $app ) . "\n\n";
		}
		if ( ! empty( $ctx['privacy_view'] ) ) {
			return self::refusal_words( 'privacy_view', $verb ) . "\n\n";
		}
		if ( ! self::rate_ok( $verb, $uid ) ) {
			/* translators: %d: how many held actions an hour */
			return "\u{26A0}\u{FE0F} " . sprintf( __( 'That is more than %d of these this hour, so I\'ve paused this one as a safety cap. It resets within the hour; ask again then, or do it in the app.', 'zorderz' ), self::RATE_CAP ) . "\n\n";
		}
		$r = self::invoke( $verb, $b['args'] );
		if ( empty( $r['needs_confirm'] ) ) {
			// a write the registry did not hold (refused on the way in): its words, never a commit
			return self::render_refused( $r ) . "\n\n";
		}
		$row_args = $b['args'];
		$preview  = self::describe( $verb, $row_args, $row );
		$id       = self::store( $uid, $sid, $verb, $row_args, $preview, ! empty( $ctx['is_kiosk'] ), (int) ( $ctx['msg_id'] ?? 0 ) );
		if ( '' === $id ) {
			return __( 'I couldn\'t hold that for you to confirm, so nothing was done. Please ask again.', 'zorderz' ) . "\n\n";
		}
		/* translators: %s: what the action would do */
		return sprintf( __( 'I can %s. Nothing has happened yet: press Confirm to do it.', 'zorderz' ), $preview ) . "\n" . ZANA_Markers::confirm( $id ) . "\n\n";
	}

	/** A read's answer, as the app wrote it, under the app's name. */
	public static function render_read( array $r, string $app ): string {
		if ( ! empty( $r['denied'] ) ) {
			return self::refusal_words( (string) ( $r['reason'] ?? 'tier' ), '' );
		}
		$msg = '';
		foreach ( array( 'message', 'answer', 'summary', 'text' ) as $k ) {
			if ( isset( $r[ $k ] ) && is_string( $r[ $k ] ) && '' !== trim( $r[ $k ] ) ) {
				$msg = $r[ $k ];
				break;
			}
		}
		if ( empty( $r['success'] ) || '' === trim( $msg ) ) {
			/* translators: %s: an app's name */
			return sprintf( __( 'I couldn\'t check %s just now.', 'zorderz' ), $app );
		}
		$msg = self::defuse( mb_substr( trim( $msg ), 0, 2000 ) );
		/* translators: %s: an app's name */
		return '**' . sprintf( __( 'From %s:', 'zorderz' ), $app ) . "**\n" . $msg;
	}

	private static function render_refused( array $r ): string {
		if ( ! empty( $r['denied'] ) ) {
			return self::refusal_words( (string) ( $r['reason'] ?? 'tier' ), (string) ( $r['verb'] ?? '' ) );
		}
		$m = (string) ( $r['message'] ?? ( $r['error'] ?? '' ) );
		return '' !== $m ? self::defuse( $m ) : __( 'That did not go through, and nothing was changed.', 'zorderz' );
	}

	/** The app's name for the reader: its registered label, else its id. */
	public static function app_label( string $app ): string {
		if ( '' === $app ) {
			return __( 'the platform', 'zorderz' );
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'get_instance' ) ) {
			$apps = ZDZ_Plugin_API::get_instance()->get_all_apps();
			if ( isset( $apps[ $app ] ) && is_object( $apps[ $app ] ) && method_exists( $apps[ $app ], 'get_config' ) ) {
				$cfg = (array) $apps[ $app ]->get_config();
				$l   = trim( (string) ( $cfg['nm'] ?? ( $cfg['name'] ?? '' ) ) );
				if ( '' !== $l ) {
					return $l;
				}
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $app ) );
	}

	/**
	 * What a held action IS, in words: the card's line, the record of the press and the note on a spent card. From the
	 * verb's own summary when it has one; a name the model wrote is defused, never echoed raw.
	 */
	public static function describe( string $verb, array $args, ?array $row = null ): string {
		$who = '';
		foreach ( array( 'customer', 'name', 'assignee', 'to', 'title' ) as $k ) {
			$v = $args[ $k ] ?? '';
			if ( is_array( $v ) ) {
				$v = trim( (string) ( $v['name'] ?? ( ( $v['first_name'] ?? '' ) . ' ' . ( $v['last_name'] ?? '' ) ) ) );
			}
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$who = mb_substr( trim( (string) $v ), 0, 80 );
				break;
			}
		}
		$what = str_replace( array( '.', '_' ), ' ', $verb );
		/* translators: 1: what the action does, 2: for whom */
		return self::defuse( '' !== $who ? sprintf( __( '%1$s (%2$s)', 'zorderz' ), $what, $who ) : $what );
	}

	/** Anything marker-shaped in model text, broken so echoing it cannot arm a verb or draw a card. */
	public static function defuse( string $text ): string {
		return (string) preg_replace( '/\[(\/?\s*Z[A-Z]*_[A-Z0-9_]+)(?::[^\]]*)?\s*\]/i', '($1)', $text );
	}

	/** One more held write for this verb and person this hour? (Counted when it is.) */
	private static function rate_ok( string $verb, int $uid ): bool {
		$key = 'zana_mcap_' . substr( md5( $verb ), 0, 10 ) . '_' . $uid . '_' . (int) floor( time() / 3600 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_CAP ) {
			return false;
		}
		set_transient( $key, $n + 1, 3660 );
		return true;
	}

	/* ───────────────────────────── the store ───────────────────────────── */

	public static function row_key( int $uid, int $sid, string $id ): string {
		return self::PREFIX . $uid . '_' . $sid . '_' . preg_replace( '/[^a-f0-9]/i', '', $id );
	}

	public static function index_key( int $uid, int $sid ): string {
		return self::IDX_PREFIX . $uid . '_' . $sid;
	}

	/**
	 * Canonical form of an argument set: keys sorted at every depth (maps only — a list keeps its order), strings
	 * trimmed, so the same payload with its keys in another order is the same action.
	 */
	public static function normalize_args( array $args ): array {
		$out = array();
		foreach ( $args as $k => $v ) {
			$out[ $k ] = is_array( $v ) ? self::normalize_args( $v ) : ( is_string( $v ) ? trim( $v ) : $v );
		}
		if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/** Hold a previewed write. Returns its id, or '' when nothing was stored (the shared device, no person, no chat). */
	public static function store( int $uid, int $sid, string $verb, array $args, string $preview = '', bool $is_kiosk = false, int $msg_id = 0 ): string {
		if ( $is_kiosk || $uid <= 0 || $sid <= 0 || '' === $verb ) {
			return '';
		}
		$now = time();
		$id  = bin2hex( random_bytes( 16 ) );
		$row = array(
			'id'          => $id,
			'verb'        => $verb,
			'args'        => self::normalize_args( $args ),
			'preview'     => $preview,
			'user_id'     => $uid,
			'session_id'  => $sid,
			'created_at'  => $now,
			'expires_at'  => $now + self::TTL,
			'consumed_at' => 0,
			'msg_id'      => $msg_id,
		);
		set_transient( self::row_key( $uid, $sid, $id ), $row, self::TTL );
		$idx   = self::read_index( $uid, $sid );
		$idx[] = $id;
		if ( count( $idx ) > self::MAX_PENDING ) {
			foreach ( array_splice( $idx, 0, count( $idx ) - self::MAX_PENDING ) as $old ) {
				delete_transient( self::row_key( $uid, $sid, $old ) );
			}
		}
		self::write_index( $uid, $sid, $idx );
		return $id;
	}

	/** One live row, or null. */
	public static function get_row( int $uid, int $sid, string $id ): ?array {
		return self::read_row( $uid, $sid, $id );
	}

	/** Take the row and burn it: returned exactly once; every later call for the same id gets null. */
	public static function consume( int $uid, int $sid, string $id ): ?array {
		$row = self::read_row( $uid, $sid, $id );
		if ( null === $row ) {
			return null;
		}
		if ( ! self::claim( $uid, $sid, $id ) ) {
			error_log( 'ZANA_Actions: a second request tried to consume a held action while the first held it; refused.' );
			return null;
		}
		// burn first, hand back second: a lost commit is recoverable, a double one is not
		delete_transient( self::row_key( $uid, $sid, $id ) );
		self::write_index( $uid, $sid, array_values( array_diff( self::read_index( $uid, $sid ), array( $id ) ) ) );
		$row['consumed_at'] = time();
		return $row;
	}

	/** Cancel: the row goes, nothing runs. */
	public static function cancel( int $uid, int $sid, string $id ): bool {
		if ( null === self::read_row( $uid, $sid, $id ) ) {
			return false;
		}
		delete_transient( self::row_key( $uid, $sid, $id ) );
		self::write_index( $uid, $sid, array_values( array_diff( self::read_index( $uid, $sid ), array( $id ) ) ) );
		return true;
	}

	/**
	 * A person pressed Confirm. Runs the held write from its STORED arguments, after asking the registry again.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function confirm( int $uid, int $sid, string $id ): array {
		$fail = function ( $m ) { return array( 'success' => false, 'message' => $m ); };
		if ( $uid <= 0 || (int) get_current_user_id() !== $uid ) {
			return $fail( __( 'You need to be signed in for that.', 'zorderz' ) );
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return $fail( self::refusal_words( 'privacy_view', '' ) );
		}
		$row = self::consume( $uid, $sid, $id );
		if ( null === $row ) {
			return $fail( __( 'That preview has expired or was already used. Ask again and I\'ll show it again.', 'zorderz' ) );
		}
		$verb = (string) $row['verb'];
		$why  = self::why_not( $verb, $uid );
		$c    = self::get( $verb );
		if ( '' !== $why || ! $c || empty( $c['side_effect'] ) || ! is_callable( $c['callback'] ?? null ) ) {
			self::record( $uid, $verb, (string) $row['preview'], false );
			return $fail( self::refusal_words( '' !== $why ? $why : 'unknown', $verb ) );
		}
		$args = (array) $row['args'];
		foreach ( array( 'user_id', 'requesting_user_id', 'is_kiosk', 'tier', 'confirmed' ) as $k ) {
			unset( $args[ $k ] );
		}
		$args['user_id']            = $uid;
		$args['requesting_user_id'] = $uid;
		$args['is_kiosk']           = false;   // the shared device never holds a row (why_not refused it above too)
		$args['tier']               = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::bridge_tier( $uid ) : '';
		$args['confirmed']          = true;    // the person pressed Confirm: that IS the confirmation
		try {
			$r = call_user_func( $c['callback'], $args );
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Actions: ' . $verb . ' failed on Confirm - ' . get_class( $e ) );
			$r = array( 'success' => false );
		}
		$ok = is_array( $r ) && ! empty( $r['success'] ) && empty( $r['denied'] );
		self::record( $uid, $verb, (string) $row['preview'], $ok );
		$msg = is_array( $r ) ? (string) ( $r['message'] ?? ( $r['error'] ?? ( $r['note'] ?? '' ) ) ) : '';
		$out = array(
			'success' => $ok,
			'message' => self::defuse( '' !== $msg ? $msg : ( $ok ? __( 'Done.', 'zorderz' ) : __( 'That did not go through.', 'zorderz' ) ) ),
		);
		// A write that opens something for the person (an email in their mail program or the Email app) says so; the page
		// opens it. Nothing else of the app's answer travels.
		if ( $ok && is_string( $r['href'] ?? null ) && 0 === stripos( $r['href'], 'mailto:' ) ) {
			$out['open_href'] = $r['href'];
		}
		if ( $ok && 'inbox' === ( $r['mode'] ?? '' ) && preg_match( '/^[a-f0-9]{32}$/', (string) ( $r['token'] ?? '' ) ) ) {
			$out['open_app'] = array( 'app' => preg_replace( '/[^a-z0-9\-]/', '', (string) ( $r['app'] ?? 'inbox' ) ), 'handoff' => (string) $r['token'] );
		}
		return $out;
	}

	/** Every press is recorded: who, which verb, what it said it would do, and whether it went through. */
	private static function record( int $uid, string $verb, string $preview, bool $ok ): void {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( $uid, 'chat_action_confirmed', mb_substr( $preview, 0, 200 ), ZANA_LLM_Client::CALLER, array( 'verb' => $verb, 'ok' => $ok ) );
		}
	}

	/** The verbs this person may use from the chat, for the prompt: [ verb => { summary, writes } ]. */
	public static function available( int $uid ): array {
		$out = array();
		if ( ! class_exists( 'ZDZ_Capabilities' ) ) {
			return $out;
		}
		foreach ( ZDZ_Capabilities::all() as $verb => $c ) {
			if ( '' === ZDZ_Capabilities::why_not( $verb, $uid ) ) {
				$out[ $verb ] = array( 'summary' => (string) $c['summary'], 'writes' => ! empty( $c['side_effect'] ) );
			}
		}
		return $out;
	}

	/** The prompt's section on actions ('' when the person may use none). */
	public static function prompt_block( int $uid, bool $no_writes = false ): string {
		$verbs = self::available( $uid );
		if ( ! $verbs ) {
			return '';
		}
		$lines = array();
		foreach ( $verbs as $verb => $v ) {
			if ( $no_writes && $v['writes'] ) {
				continue;
			}
			$lines[] = '- ' . $verb . ( $v['writes'] ? ' (changes something: the person confirms it)' : ' (looks something up)' )
				. ( '' !== $v['summary'] ? ': ' . mb_substr( $v['summary'], 0, 240 ) : '' );
		}
		if ( ! $lines ) {
			return '';
		}
		return "APP ACTIONS (what you may ask an app to do; only these):\n" . implode( "\n", $lines ) . "\n"
			. 'To ask for one, write exactly ' . ZANA_Markers::ACTION_OPEN . '{"verb":"<verb>","args":{…}}' . ZANA_Markers::ACTION_CLOSE
			. ' on its own line. You never do the action yourself: a lookup is shown to the person as the app answers it, and anything that changes something waits for the person to press Confirm. Never say it is done.';
	}

	/* ───────────────────────────── internals ───────────────────────────── */

	/**
	 * The atomic half of consume(): one winner per action id, ever. INSERT IGNORE against the options table's unique
	 * name is the portable atomic primitive WordPress has; exactly one caller gets one row. The row stays as the record
	 * that this id was spoken for (sweep_claims() clears old ones). Without a database handle the claim cannot be made
	 * atomically, so the commit does not happen: a refused press can be repeated, a double write cannot be undone.
	 */
	private static function claim( int $uid, int $sid, string $id ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$name = self::CLAIM . md5( $uid . ':' . $sid . ':' . $id );
		$n    = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
		if ( 1 === (int) $n ) {
			self::sweep_claims();
			return true;
		}
		return false;
	}

	private static function sweep_claims(): void {
		global $wpdb;
		if ( 1 !== mt_rand( 1, 50 ) || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s", '\_zana\_pact\_claim\_%', (string) ( time() - self::TTL * 6 ) ) );
	}

	private static function read_index( int $uid, int $sid ): array {
		$idx = get_transient( self::index_key( $uid, $sid ) );
		return is_array( $idx ) ? array_values( array_filter( $idx, 'is_string' ) ) : array();
	}

	private static function write_index( int $uid, int $sid, array $idx ): void {
		if ( ! $idx ) {
			delete_transient( self::index_key( $uid, $sid ) );
			return;
		}
		set_transient( self::index_key( $uid, $sid ), array_values( $idx ), self::TTL );
	}

	/** A row, or null when it is missing, malformed, expired, consumed, or not this person's and chat's. */
	private static function read_row( int $uid, int $sid, string $id ): ?array {
		if ( '' === $id || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}
		$row = get_transient( self::row_key( $uid, $sid, $id ) );
		if ( ! is_array( $row ) || ! isset( $row['verb'], $row['args'], $row['expires_at'] ) ) {
			return null;
		}
		// the row's own clock, not the transient's: a stale cache must never bring a commit back
		if ( (int) $row['expires_at'] <= time() || ! empty( $row['consumed_at'] ) ) {
			return null;
		}
		if ( (int) ( $row['user_id'] ?? 0 ) !== $uid || (int) ( $row['session_id'] ?? 0 ) !== $sid ) {
			return null;
		}
		return $row;
	}
}
