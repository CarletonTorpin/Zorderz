<?php
/**
 * ZANA_Style_Memory — how an administrator writes, learned from how they change the chat's drafts.
 *
 * What is kept, for each person (user meta; nobody else's chat ever reads it):
 *   - EVENTS (zana_style_events): the last 30 edits (a draft before and after the person changed it with Edit on its
 *     card: an email, a direct message or a channel post) and the short corrections they typed about a draft
 *     ("shorter", "never say X"). Plain text, at most 2,500 characters each.
 *   - RULES (zana_style_rules): at most 24 one-sentence rules, each for one audience (customer, staff, vendor,
 *     official, or all). A "learned" rule is rewritten by learn(); an "added" rule is the person's own, comes first and
 *     only they remove it. A rule is general by construction: one that holds an e-mail address, a link, a number of
 *     three or more digits or a customer's detail is refused.
 *
 * learn() asks the Ai router ONCE (role `extract`, a schema'd answer) for the whole updated list after new events.
 * The router codes customer details before anything leaves; a rule that comes back holding a code is refused. A failed
 * call, a refusal or an answer of the wrong shape changes nothing, and the pass is tried again later.
 *
 * The chat's instructions get the rules as "HOW <NAME> WRITES" (prompt_block()), right after "About you"; the rule
 * "audience and tone" (ZANA_Answer_Rules) says how they are applied.
 *
 * WHO: accounts with manage_options only; never the shared device, never an account in Privacy View. The setting
 * `zana_style_learning` ('0') turns it off for everyone (Zorderz → Settings). Core: on.
 *
 * REST, under ZDZ_REST_NS, signed in, with the chat, and only for a person enabled_for() says yes to:
 *   GET    {ns}/analytics/style             { rules: [ { id, audience, label, rule, source } ], edits, audiences }
 *   POST   {ns}/analytics/style/edit        { kind, before, after } → { recorded, learn, rules }
 *   POST   {ns}/analytics/style/draft       { card, session_id, body } → { body }   (a held direct message's new words)
 *   POST   {ns}/analytics/style/rule        { audience, rule } → { rule }
 *   DELETE {ns}/analytics/style/rule/<id>   { deleted }
 *   DELETE {ns}/analytics/style             { cleared }
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Style_Memory {

	const META_EVENTS  = 'zana_style_events';
	const META_RULES   = 'zana_style_rules';
	const META_LEARNED = 'zana_style_learned_at';
	const OPTION       = 'zana_style_learning';   // '0' turns learning off for everyone
	const HOOK         = 'zana_style_learn';
	const MAX_EVENTS   = 30;
	const MAX_TEXT     = 2500;
	const MAX_RULES    = 24;
	const MAX_LEARNED  = 20;
	const MAX_RULE     = 200;
	const MIN_RULE     = 8;
	const ROLE         = 'extract';   // the router role of the learning call; which model answers is the business's setting
	const DEADLINE_S   = 50;
	const AUDIENCES    = array( 'all', 'customer', 'staff', 'vendor', 'official' );
	const KINDS        = array( 'email', 'dm', 'post', 'text' );
	const CODE_OPEN    = '⟦';        // the brackets the Ai router writes a customer's detail in (ZDZ_Core_LLM::OPEN / CLOSE)
	const CODE_CLOSE   = '⟧';

	/** Tests hand in the router: callable( string $role, array $request, array $ctx ) => array|WP_Error. */
	public static $ask = null;

	/** Tests set the clock: callable() => int. */
	public static $now = null;

	/** Tests see a pass being scheduled: callable( int $uid ). */
	public static $scheduler = null;

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron_learn' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
	}

	private static function now(): int {
		return is_callable( self::$now ) ? (int) call_user_func( self::$now ) : time();
	}

	/* ─────────────────────────── who ─────────────────────────── */

	public static function on(): bool {
		return '0' !== (string) ( function_exists( 'get_option' ) ? get_option( self::OPTION, '1' ) : '1' );
	}

	/** An administrator, on their own account: never the shared device, never an account in Privacy View. */
	public static function enabled_for( int $uid ): bool {
		if ( $uid <= 0 || ! self::on() ) {
			return false;
		}
		if ( ! function_exists( 'user_can' ) || ! user_can( $uid, 'manage_options' ) ) {
			return false;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		return true;
	}

	public static function audience_label( string $a ): string {
		$labels = array(
			'all'      => __( 'All drafts', 'zorderz' ),
			'customer' => __( 'Customers and leads', 'zorderz' ),
			'staff'    => __( 'Staff', 'zorderz' ),
			'vendor'   => __( 'Vendors and partners', 'zorderz' ),
			'official' => __( 'Courts, lawyers, insurers and agencies', 'zorderz' ),
		);
		return $labels[ $a ] ?? $labels['all'];
	}

	/* ─────────────────────────── storage ─────────────────────────── */

	public static function events( int $uid ): array {
		$e = get_user_meta( $uid, self::META_EVENTS, true );
		return is_array( $e ) ? array_values( array_filter( $e, 'is_array' ) ) : array();
	}

	public static function rules( int $uid ): array {
		$r   = get_user_meta( $uid, self::META_RULES, true );
		$out = array();
		foreach ( is_array( $r ) ? $r : array() as $row ) {
			if ( is_array( $row ) && is_string( $row['rule'] ?? null ) && '' !== $row['rule'] && in_array( $row['audience'] ?? '', self::AUDIENCES, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private static function save_events( int $uid, array $events ): void {
		update_user_meta( $uid, self::META_EVENTS, array_slice( array_values( $events ), -self::MAX_EVENTS ) );
	}

	private static function save_rules( int $uid, array $rules ): void {
		update_user_meta( $uid, self::META_RULES, array_slice( array_values( $rules ), 0, self::MAX_RULES ) );
	}

	/** Plain text: tags out, entities decoded, spaces tidied, at most MAX_TEXT characters. */
	public static function plain( string $s ): string {
		$s = (string) preg_replace( '/<br\s*\/?>/i', "\n", $s );
		$s = (string) preg_replace( '/<\/(p|div|li)>/i', "\n", $s );
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
		$s = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $s );
		$s = (string) preg_replace( "/ *\n */", "\n", $s );
		$s = (string) preg_replace( "/\n{3,}/", "\n\n", trim( $s ) );
		return mb_substr( $s, 0, self::MAX_TEXT );
	}

	private static function squash( string $s ): string {
		return strtolower( (string) preg_replace( '/\s+/', ' ', trim( $s ) ) );
	}

	/* ─────────────────────────── recording ─────────────────────────── */

	/** A draft before and after the person changed it. 'recorded' | 'off' | 'same' | 'empty'. */
	public static function record_edit( int $uid, string $kind, string $before, string $after ): string {
		if ( ! self::enabled_for( $uid ) ) {
			return 'off';
		}
		$kind   = in_array( $kind, self::KINDS, true ) ? $kind : 'text';
		$before = self::plain( $before );
		$after  = self::plain( $after );
		if ( '' === $before || '' === $after ) {
			return 'empty';
		}
		if ( self::squash( $before ) === self::squash( $after ) ) {
			return 'same';
		}
		$events   = self::events( $uid );
		$events[] = array( 't' => self::now(), 'type' => 'edit', 'kind' => $kind, 'before' => $before, 'after' => $after );
		self::save_events( $uid, $events );
		return 'recorded';
	}

	/**
	 * A correction the person typed about a draft ("shorter", "drop the exclamation marks"). A bare go-ahead teaches
	 * nothing and a long message is a new request, so neither is kept. 'recorded' | 'off' | 'skipped'.
	 */
	public static function record_instruction( int $uid, string $text, string $kind = 'email' ): string {
		if ( ! self::enabled_for( $uid ) ) {
			return 'off';
		}
		$text = self::plain( $text );
		$len  = mb_strlen( $text );
		if ( $len < 4 || $len > 400 || preg_match( '/^\s*(ok(ay)?|yes|yep|sure|send( it)?|looks good|perfect|great|thanks?( you)?|go( ahead)?)[\s.!]*$/i', $text ) ) {
			return 'skipped';
		}
		$events   = self::events( $uid );
		$events[] = array( 't' => self::now(), 'type' => 'instruction', 'kind' => in_array( $kind, self::KINDS, true ) ? $kind : 'text', 'text' => $text );
		self::save_events( $uid, $events );
		self::schedule( $uid );
		return 'recorded';
	}

	/**
	 * The chat's turn: when this message corrects the draft the last answer showed (an email, a direct message or a
	 * channel post), it is kept as how this person writes. Never throws; a turn is never held up by it.
	 */
	public static function note_correction( int $uid, string $message, array $history ): string {
		try {
			if ( ! self::enabled_for( $uid ) || ! $history ) {
				return 'off';
			}
			if ( class_exists( 'ZANA_Email_Turn' ) && ZANA_Email_Turn::is_draft_followup( $message, $history ) ) {
				return self::record_instruction( $uid, $message, 'email' );
			}
			$kind = self::edits_message_draft( $message, $history );
			return '' !== $kind ? self::record_instruction( $uid, $message, $kind ) : 'skipped';
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Style_Memory: a correction could not be kept (' . get_class( $e ) . ').' );
			return 'skipped';
		}
	}

	/**
	 * Does this message change the direct message or channel post the newest answer drafted? 'dm', 'post' or ''.
	 * Only the answer just before it counts. A question about figures, or a hand-over to another app's action, is not
	 * an edit; an edit verb, or a word for the draft itself, is.
	 */
	public static function edits_message_draft( string $message, array $history ): string {
		$kind = '';
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( 'assistant' !== ( $history[ $i ]['role'] ?? '' ) ) {
				continue;
			}
			$prev = (string) ( $history[ $i ]['content'] ?? '' );
			if ( false !== strpos( $prev, '[ZANA_DM_DRAFT:' ) ) {
				$kind = 'dm';
			} elseif ( false !== strpos( $prev, '[ZANA_POST_DRAFT:' ) ) {
				$kind = 'post';
			}
			break;
		}
		if ( '' === $kind ) {
			return '';
		}
		$q = strtolower( trim( $message ) );
		if ( preg_match( '/\b(revenue|profit|margin|how\s+many|how\s+much|count|counts|number\s+of|totals?|average|avg|figures?|report|breakdown|per\s+month|this\s+month|last\s+month|this\s+year|last\s+year|ytd|quarter|commissions?|pricing|priced)\b/', $q ) || false !== strpos( $q, '$' ) ) {
			return '';
		}
		if ( preg_match( '/\b(schedule|book|appointment|reschedule|create\s+(?:an?\s+)?(?:estimate|invoice|lead)|send\s+(?:the\s+)?estimate|estimate\s+#?\d|invoice\s+#?\d|commission)\b/', $q ) ) {
			return '';
		}
		$edit = (bool) preg_match( '/\b(remove|delete|take\s+out|drop|leave\s+out|change|replace|swap|reword|rephrase|rewrite|revise|edit|adjust|tweak|fix|shorten|shorter|tighten|lengthen|longer|expand|start(?:\s+it)?\s+with|begin(?:\s+it)?\s+with|open\s+with|end(?:\s+it)?\s+with|add|append|include|also\s+(?:say|add|mention)|mention|sign\s+it|sign[\s-]?off|signature|make\s+it|instead|actually|capitalize|rename|no\s+(?:thanks?|signature|sign[\s-]?off)|never\s+say|too\s+(?:long|short|wordy|formal|casual|stiff|pushy))\b/', $q );
		$refs = (bool) preg_match( '/\b(message|msg|dm|note|draft|post|text|wording|greeting|tone|sign[\s-]?off|signature)\b/', $q );
		return ( $edit || $refs ) ? $kind : '';
	}

	/** The pass runs a moment later, once, however many events arrive meanwhile. */
	public static function schedule( int $uid ): void {
		if ( is_callable( self::$scheduler ) ) {
			call_user_func( self::$scheduler, $uid );
			return;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$args = array( $uid );
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK, $args );
		}
	}

	/* ─────────────────────────── learning ─────────────────────────── */

	public static function cron_learn( $uid ): void {
		try {
			self::learn( (int) $uid );
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Style_Memory: the scheduled pass failed (' . get_class( $e ) . ').' );
		}
	}

	/** What the learning call must answer with: the whole updated list of learned rules. */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'rules' ),
			'properties'           => array(
				'rules' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'audience', 'rule' ),
						'properties'           => array(
							'audience' => array( 'type' => 'string', 'enum' => self::AUDIENCES ),
							'rule'     => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/** The fixed words of the learning call. No person, business or example of either in them. */
	public static function instructions(): string {
		return 'You keep a short list of writing rules for one person. The rules come from how that person edits the drafts an '
			. 'assistant writes for them, and from the corrections they type. Each rule is ONE sentence in the imperative, about '
			. 'style: tone, length, greeting, sign-off, word choice, structure, punctuation, what to leave out. A rule is general: '
			. 'it never names a customer or any other person, a place, an address, a phone, an email, an amount, a document number '
			. 'or a date, and it never carries a code in double square brackets. Tag each rule with who it is for: customer '
			. '(customers and leads), staff (employees and teammates), vendor (suppliers and partners), official (courts, lawyers, '
			. 'insurers, agencies), or all. Work out each example\'s audience from its text. Keep the current rules that still hold, '
			. 'merge duplicates, drop any that the new examples contradict, and add a rule only for a clear, repeatable preference: '
			. 'a corrected fact, name or date is not a style rule. At most ' . self::MAX_LEARNED . ' rules. Do not repeat the person\'s '
			. 'own rules; never contradict them. Answer with the whole updated list of learned rules.';
	}

	/** The lists and the new examples, as the one message of the learning call. */
	public static function examples_text( array $learned, array $added, array $fresh ): string {
		$line = static function ( $r ) {
			return '- [' . $r['audience'] . '] ' . $r['rule'] . "\n";
		};
		$have = implode( '', array_map( $line, $learned ) );
		$own  = implode( '', array_map( $line, $added ) );
		$ex   = '';
		foreach ( array_values( array_slice( $fresh, -12 ) ) as $i => $e ) {
			$kind = in_array( $e['kind'] ?? '', self::KINDS, true ) ? $e['kind'] : 'text';
			if ( 'edit' === ( $e['type'] ?? '' ) ) {
				$ex .= "\nEXAMPLE " . ( $i + 1 ) . ' (' . $kind . " draft, edited)\nBEFORE:\n" . mb_substr( (string) ( $e['before'] ?? '' ), 0, 1200 ) . "\nAFTER:\n" . mb_substr( (string) ( $e['after'] ?? '' ), 0, 1200 ) . "\n";
			} else {
				$ex .= "\nEXAMPLE " . ( $i + 1 ) . ' (' . $kind . " draft, correction they typed)\n" . mb_substr( (string) ( $e['text'] ?? '' ), 0, 400 ) . "\n";
			}
		}
		return "CURRENT LEARNED RULES:\n" . ( '' !== $have ? $have : "(none yet)\n" )
			. "\nTHE PERSON'S OWN RULES (kept as they are):\n" . ( '' !== $own ? $own : "(none)\n" )
			. "\nNEW EXAMPLES:" . $ex;
	}

	/**
	 * Rewrite the learned rules from the events since the last pass. One router call. Returns a short outcome:
	 * 'off' | 'nothing new' | 'call failed' | 'unreadable answer' | 'learned N'. Anything but 'learned N' changed nothing.
	 */
	public static function learn( int $uid ): string {
		if ( ! self::enabled_for( $uid ) ) {
			return 'off';
		}
		$since = (int) get_user_meta( $uid, self::META_LEARNED, true );
		$fresh = array_values( array_filter( self::events( $uid ), static function ( $e ) use ( $since ) {
			return (int) ( $e['t'] ?? 0 ) > $since;
		} ) );
		if ( ! $fresh ) {
			return 'nothing new';
		}
		$rules   = self::rules( $uid );
		$added   = array_values( array_filter( $rules, static function ( $r ) { return 'added' === ( $r['source'] ?? '' ); } ) );
		$learned = array_values( array_filter( $rules, static function ( $r ) { return 'added' !== ( $r['source'] ?? '' ); } ) );

		$request = array(
			'system'      => self::instructions(),
			'messages'    => array( array( 'role' => 'user', 'content' => self::examples_text( $learned, $added, $fresh ) ) ),
			'json_schema' => self::schema(),
			'max_output'  => 1500,
		);
		$ctx     = array( 'caller' => class_exists( 'ZANA_LLM_Client' ) ? ZANA_LLM_Client::CALLER : 'sales-analytics', 'user_id' => $uid, 'deadline' => time() + self::DEADLINE_S );
		try {
			if ( is_callable( self::$ask ) ) {
				$res = call_user_func( self::$ask, self::ROLE, $request, $ctx );
			} elseif ( class_exists( 'ZDZ_Core_LLM' ) ) {
				$res = ZDZ_Core_LLM::ask( self::ROLE, $request, $ctx );
			} else {
				return 'call failed';
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Style_Memory: the learning call threw (' . get_class( $e ) . ').' );
			return 'call failed';
		}
		// what the call cost is on the person's day, whether it answered or not
		if ( class_exists( 'ZANA_Allowance' ) ) {
			$milli = is_wp_error( $res ) ? (int) ( ( (array) $res->get_error_data() )['usd_milli'] ?? 0 ) : (int) ceil( (float) ( is_array( $res ) ? ( $res['usd'] ?? 0 ) : 0 ) * 1000 );
			ZANA_Allowance::add_milli( $uid, $milli );
		}
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return 'call failed';
		}
		$json = $res['json'] ?? null;
		if ( ! is_array( $json ) || ! isset( $json['rules'] ) || ! is_array( $json['rules'] ) ) {
			return 'unreadable answer';
		}
		$new  = array();
		$seen = array();
		foreach ( $added as $r ) {
			$seen[ self::squash( $r['rule'] ) ] = true;
		}
		foreach ( $json['rules'] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$aud  = strtolower( trim( (string) ( $r['audience'] ?? 'all' ) ) );
			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $r['rule'] ?? '' ) ) ) );
			if ( ! in_array( $aud, self::AUDIENCES, true ) || '' !== self::rule_problem( $text ) || isset( $seen[ self::squash( $text ) ] ) ) {
				continue;
			}
			$seen[ self::squash( $text ) ] = true;
			$new[]                         = array( 'id' => self::new_id(), 'audience' => $aud, 'rule' => $text, 'source' => 'learned', 'at' => self::now() );
			if ( count( $new ) >= self::MAX_LEARNED ) {
				break;
			}
		}
		self::save_rules( $uid, array_merge( $added, array_slice( $new, 0, max( 0, self::MAX_RULES - count( $added ) ) ) ) );
		$last = 0;
		foreach ( $fresh as $e ) {
			$last = max( $last, (int) ( $e['t'] ?? 0 ) );
		}
		update_user_meta( $uid, self::META_LEARNED, $last );
		return 'learned ' . count( $new );
	}

	/** Why a rule is refused, or '' when it is fine. */
	public static function rule_problem( string $text ): string {
		$text = trim( $text );
		$len  = mb_strlen( $text );
		if ( $len < self::MIN_RULE ) {
			return __( 'too short', 'zorderz' );
		}
		if ( $len > self::MAX_RULE ) {
			/* translators: %d: the most characters a rule may have */
			return sprintf( __( 'too long (%d characters at most)', 'zorderz' ), self::MAX_RULE );
		}
		if ( preg_match( '/@|https?:|www\.|\d{3,}/i', $text ) ) {
			return __( 'a rule is about style: no e-mail addresses, links, phone or document numbers', 'zorderz' );
		}
		// a customer's detail reaches and leaves the model as a code in the router's own brackets: never part of a rule
		if ( false !== strpos( $text, self::CODE_OPEN ) || false !== strpos( $text, self::CODE_CLOSE ) ) {
			return __( 'a rule is about style: it names no customer', 'zorderz' );
		}
		return '';
	}

	/* ─────────────────────────── the person's own rules ─────────────────────────── */

	/** @return array|string the saved rule, or why it was not saved. */
	public static function add_rule( int $uid, string $audience, string $text ) {
		if ( ! self::enabled_for( $uid ) ) {
			return __( 'Style learning is for administrator accounts.', 'zorderz' );
		}
		$audience = in_array( $audience, self::AUDIENCES, true ) ? $audience : 'all';
		$text     = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		$problem  = self::rule_problem( $text );
		if ( '' !== $problem ) {
			/* translators: %s: why the rule was refused */
			return sprintf( __( 'Not saved: %s.', 'zorderz' ), $problem );
		}
		$rules = self::rules( $uid );
		foreach ( $rules as $r ) {
			if ( self::squash( $r['rule'] ) === self::squash( $text ) ) {
				return __( 'That rule is already on the list.', 'zorderz' );
			}
		}
		$own = count( array_filter( $rules, static function ( $r ) { return 'added' === ( $r['source'] ?? '' ); } ) );
		if ( $own >= self::MAX_RULES ) {
			return __( 'The list is full: delete a rule first.', 'zorderz' );
		}
		$rule = array( 'id' => self::new_id(), 'audience' => $audience, 'rule' => $text, 'source' => 'added', 'at' => self::now() );
		array_unshift( $rules, $rule );   // the person's own rule goes first; learned rules make room
		self::save_rules( $uid, $rules );
		return $rule;
	}

	public static function delete_rule( int $uid, string $id ): bool {
		$rules = self::rules( $uid );
		$keep  = array_values( array_filter( $rules, static function ( $r ) use ( $id ) {
			return (string) ( $r['id'] ?? '' ) !== $id;
		} ) );
		if ( count( $keep ) === count( $rules ) ) {
			return false;
		}
		self::save_rules( $uid, $keep );
		return true;
	}

	public static function clear( int $uid ): void {
		delete_user_meta( $uid, self::META_RULES );
		delete_user_meta( $uid, self::META_EVENTS );
		delete_user_meta( $uid, self::META_LEARNED );
	}

	/* ─────────────────────────── the instructions ─────────────────────────── */

	/** The block the chat's instructions get, after "About you". '' when there are no rules or the person is not learned from. */
	public static function prompt_block( int $uid ): string {
		if ( ! self::enabled_for( $uid ) ) {
			return '';
		}
		$rules = self::rules( $uid );
		if ( ! $rules ) {
			return '';
		}
		$by = array();
		foreach ( $rules as $r ) {
			$by[ $r['audience'] ][] = $r['rule'];
		}
		$name = class_exists( 'ZANA_User_Memory' ) ? ZANA_User_Memory::name_for( $uid ) : '';
		$out  = 'HOW ' . strtoupper( '' !== $name ? $name : 'THIS PERSON' ) . " WRITES (their own rules, and rules learned from their edits)\n"
			. 'When you draft anything this person will send, apply the rules for that draft\'s audience (AUDIENCE AND TONE) and the '
			. '"All drafts" rules. What they say in this chat overrides them. They shape style only: never let one change a fact, a '
			. 'figure or what DOCUMENTS THAT STATE FACTS requires. Do not mention these rules unless asked; if they ask what you '
			. "have learned about their writing, list them, and say they can change them under About you, How you write.\n";
		foreach ( self::AUDIENCES as $a ) {
			if ( empty( $by[ $a ] ) ) {
				continue;
			}
			$out .= self::audience_label( $a ) . ":\n";
			foreach ( $by[ $a ] as $t ) {
				$out .= '- ' . $t . "\n";
			}
		}
		return rtrim( $out );
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	/** Signed in, with the chat, and an administrator this is on for. */
	public static function can_use(): bool {
		return class_exists( 'ZANA_REST' ) && ZANA_REST::can_access() && self::enabled_for( (int) get_current_user_id() );
	}

	public static function register_routes(): void {
		$ns  = ZDZ_REST_NS;
		$can = array( __CLASS__, 'can_use' );
		register_rest_route( $ns, '/analytics/style', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $can ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_clear' ), 'permission_callback' => $can ),
		) );
		register_rest_route( $ns, '/analytics/style/edit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_edit' ), 'permission_callback' => $can ) );
		register_rest_route( $ns, '/analytics/style/draft', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_draft' ), 'permission_callback' => $can ) );
		register_rest_route( $ns, '/analytics/style/rule', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_add' ), 'permission_callback' => $can ) );
		register_rest_route( $ns, '/analytics/style/rule/(?P<id>[a-f0-9]{10})', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_delete' ), 'permission_callback' => $can ) );
	}

	/** The list as the page shows it. */
	public static function listing( int $uid ): array {
		$out = array();
		foreach ( self::rules( $uid ) as $r ) {
			$out[] = array(
				'id'       => (string) ( $r['id'] ?? '' ),
				'audience' => (string) $r['audience'],
				'label'    => self::audience_label( (string) $r['audience'] ),
				'rule'     => (string) $r['rule'],
				'source'   => 'added' === ( $r['source'] ?? '' ) ? 'added' : 'learned',
			);
		}
		$aud = array();
		foreach ( self::AUDIENCES as $a ) {
			$aud[] = array( 'id' => $a, 'label' => self::audience_label( $a ) );
		}
		return array( 'rules' => $out, 'edits' => count( self::events( $uid ) ), 'audiences' => $aud );
	}

	public static function rest_list() {
		return rest_ensure_response( self::listing( (int) get_current_user_id() ) );
	}

	/** A draft before and after its Edit. Learns in this request (the page does not wait for the answer). */
	public static function rest_edit( WP_REST_Request $req ) {
		$uid   = (int) get_current_user_id();
		$res   = self::record_edit( $uid, sanitize_key( (string) $req->get_param( 'kind' ) ), (string) $req->get_param( 'before' ), (string) $req->get_param( 'after' ) );
		$learn = '';
		if ( 'recorded' === $res ) {
			$learn = self::learn( $uid );
			if ( 0 !== strpos( $learn, 'learned' ) ) {
				self::schedule( $uid );   // tried again later
			}
		}
		return rest_ensure_response( array( 'recorded' => $res, 'learn' => $learn, 'rules' => count( self::rules( $uid ) ) ) );
	}

	/** New words for a direct message the chat holds for this person: what Send will send. */
	public static function rest_draft( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZANA_DM_Card' ) || ! method_exists( 'ZANA_DM_Card', 'revise' ) ) {
			return new WP_Error( 'zana_style_draft', __( 'That message cannot be changed here.', 'zorderz' ), array( 'status' => 404 ) );
		}
		$r = ZANA_DM_Card::revise( (int) get_current_user_id(), absint( $req->get_param( 'session_id' ) ), sanitize_key( (string) $req->get_param( 'card' ) ), (string) $req->get_param( 'body' ) );
		if ( empty( $r['success'] ) ) {
			return new WP_Error( 'zana_style_draft', (string) ( $r['message'] ?? __( 'That message cannot be changed here.', 'zorderz' ) ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'body' => (string) $r['body'] ) );
	}

	public static function rest_add( WP_REST_Request $req ) {
		$res = self::add_rule( (int) get_current_user_id(), sanitize_key( (string) $req->get_param( 'audience' ) ), (string) $req->get_param( 'rule' ) );
		if ( is_array( $res ) ) {
			return rest_ensure_response( array( 'rule' => array(
				'id'       => (string) $res['id'],
				'audience' => (string) $res['audience'],
				'label'    => self::audience_label( (string) $res['audience'] ),
				'rule'     => (string) $res['rule'],
				'source'   => 'added',
			) ) );
		}
		return new WP_Error( 'zana_style_rule', (string) $res, array( 'status' => 400 ) );
	}

	public static function rest_delete( WP_REST_Request $req ) {
		return rest_ensure_response( array( 'deleted' => self::delete_rule( (int) get_current_user_id(), sanitize_key( (string) $req->get_param( 'id' ) ) ) ) );
	}

	public static function rest_clear() {
		self::clear( (int) get_current_user_id() );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/* ─────────────────────────── the setting ─────────────────────────── */

	public static function register_setting(): void {
		register_setting( 'zdz_core_settings_group', self::OPTION, array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => array( __CLASS__, 'sanitize_on' ),
		) );
	}

	public static function sanitize_on( $v ): string {
		return empty( $v ) || '0' === (string) $v ? '0' : '1';
	}

	/** The line, on Zorderz → Settings. */
	public static function render_setting_row(): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Chat: learn how administrators write', 'zorderz' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>" value="0" />
				<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( self::on() ); ?> /> <?php esc_html_e( 'On', 'zorderz' ); ?></label>
				<p class="description"><?php esc_html_e( 'An administrator\'s edits to the chat\'s drafts, and the corrections they type, become short style rules for their own chat (About you, How you write). One small Ai call after an edit. Never the shared device or a Privacy View account.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/* ─────────────────────────── helpers ─────────────────────────── */

	private static function new_id(): string {
		try {
			return bin2hex( random_bytes( 5 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 10 );
		}
	}
}
