<?php
/**
 * ZANA_Mail_Reply_Route — the chat turn that answers ONE email from the person's own mailbox with a draft.
 *
 * "reply to #1" under a list of mail the chat just showed, or "write a response to the latest email from Alex".
 * ZANA_Mail_Reply decides, by plain code, that the turn asks for it and which email is meant; the inbox app
 * (ZIB_ZANA_Bridge::reply_source) hands that one email over; this class runs the turn:
 *
 *   route()   Tells the inbox app which chat this is (the numbers of a list belong to the chat that showed it),
 *             then decides. Not a reply turn: null, and the chat goes on as before. A reply that cannot start (no
 *             list to count from, a number the list lacks, a newer list, an email the person sent, two senders
 *             under one name, no visible text, an email that cannot be checked for hidden text): the chat says
 *             which, in its own words, and no model is asked. Otherwise the turn's plan, which carries the
 *             message's id, sender, subject and date and never its text.
 *   answer()  Asks the model once, through the chat's one road (ZANA_LLM_Client, so the platform's Ai router codes
 *             customer details, holds the budget and refuses anything outside zero data retention; with Ai not set
 *             up the turn says so and nothing is sent). The model gets the chat's instructions, the email fenced
 *             as data, and this turn's own words: no earlier turn of the chat, no general data, no chart, no web.
 *             What it writes is a draft: the answer is rebuilt by the server (one email-draft card addressed to the
 *             address the stored message came from, no other marker, link, image or button), and nothing is sent.
 *
 * WHO. Never the shared device, never an account in Privacy View, never a person without the Email app, and never
 * after "don't use my mail". The mailbox is the signed-in person's own: the inbox app's gatekeeper forces it, and a
 * number is only ever one of the numbers of the last list that person was shown in this chat.
 *
 * WHOSE RECORDS. A customer's records ride along only when a records connector answers `zana_reply_customer_records`
 * with exactly ONE customer whose record carries the address the email came from (ZANA_Mail_Reply::lookup_is_senders).
 * Core binds no connector: then none ride along, the model is told so, and it leaves [bracketed] notes instead.
 *
 * THE OUTBOUND GATE. The model's own words around the card pass ZDZ_Answer_Authority::gate() like every answer. The
 * draft is not the chat stating facts to the person; it is text for the person to check and send themselves. So it is
 * assessed by the same authority, and what it states that no record confirms is said in a line under the card.
 *
 * Logs carry reason codes and sizes only: never a name, an address, a subject or a word of the email.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Reply_Route {

	/** The records connector's seam: apply_filters( HOOK_RECORDS, null, int $uid, array{addr,name} $sender ) => list of customer records. */
	const HOOK_RECORDS = 'zana_reply_customer_records';

	/** Tests hand in the inbox app: callable( string $call, array $args ) => mixed. Calls: available, set_chat, reply_source, style_examples. */
	public static $inbox = null;

	/** Tests hand in the model: callable( array $messages, array $opts, int $uid ) => { ok, text, error }. */
	public static $model = null;

	/** Tests hand in "this person has the Email app": callable( int $uid ) => bool. */
	public static $has_app = null;

	/** The one email of the turn in hand, as the inbox app handed it over. For that turn only: never in a plan, a transcript or a log. */
	private static $source = array();

	/** Registers what the chat shows of a mail subject, so a list's numbers find their messages (see printed_subject()). */
	public static function boot(): void {
		add_filter( 'zib_chat_printed_subject', array( __CLASS__, 'printed_subject' ), 10, 2 );
	}

	/**
	 * A mail subject as the chat prints it: an access code in it is cut before the answer is saved (ZANA_Sealed), so
	 * the inbox app keys the numbered list by the same words the person sees beside each number.
	 */
	public static function printed_subject( $subject, $uid = 0 ) {
		return ( is_string( $subject ) && class_exists( 'ZANA_Sealed' ) ) ? (string) ZANA_Sealed::cut( $subject ) : $subject;
	}

	/* ───────────────────────────── the decision ───────────────────────────── */

	/**
	 * This turn, for ZANA_Routes::before_model(): null (not a reply turn), or { answer, kind: 'mail_reply', plan }.
	 * 'answer' is the chat's own line when the reply cannot start (the model is not asked); null when the plan holds
	 * the email to answer (is_turn()), and answer() writes the turn.
	 *
	 * @param int    $uid     the signed-in person.
	 * @param string $message this turn's words.
	 * @param array  $history earlier turns, oldest first: { role, content }.
	 * @param array  $ctx     session_id (int), privacy_view (bool), shared_device (bool).
	 */
	public static function route( int $uid, string $message, array $history = array(), array $ctx = array() ): ?array {
		self::$source = array();
		$chat         = (string) (int) ( $ctx['session_id'] ?? 0 );
		if ( $uid > 0 && empty( $ctx['shared_device'] ) ) {
			self::inbox( 'set_chat', array( $chat ) ); // a list this turn shows, or a number it reads, is this chat's
		}
		$plan = self::plan( $uid, $message, $history, $ctx );
		if ( null === $plan ) {
			return null;
		}
		$note = (string) ( $plan['mail_reply_note'] ?? '' );
		unset( $plan['mail_reply_note'] );
		return array(
			'answer' => '' !== $note ? $note : null,
			'kind'   => 'mail_reply',
			'plan'   => $plan,
		);
	}

	/**
	 * The plan of a reply turn, or null. { mail_reply_turn: true, email_task: true, mail_reply: { id, to, name,
	 * subject, received_at } } with the email in hand; { mail_reply_turn: true, mail_reply: {}, mail_reply_note } when
	 * the reply cannot start.
	 */
	public static function plan( int $uid, string $message, array $history = array(), array $ctx = array() ): ?array {
		$m = trim( $message );
		if ( '' === $m || ! empty( $ctx['shared_device'] ) || ! empty( $ctx['privacy_view'] ) || ! class_exists( 'ZANA_Mail_Reply' ) || ! self::may( $uid ) ) {
			return null;
		}
		if ( class_exists( 'ZANA_Mail_Relevance' ) && ZANA_Mail_Relevance::declined( $m ) ) {
			return null; // "don't use my mail"
		}
		$d = ZANA_Mail_Reply::detect( $m, $history );
		if ( empty( $d['fire'] ) ) {
			return null;
		}
		$ref = (array) $d['ref'];
		if ( ! empty( $ref['nolist'] ) ) {
			self::log( 'no list above the number' );
			return self::stub( array(), ZANA_Mail_Reply::cannot_start( 'no_list', $ref ) );
		}
		// A number the list on the screen does not show (or none: "which one?") is answered here, never looked up:
		// the list kept for numbering may hold more than was shown.
		if ( isset( $ref['n'] ) && ( (int) $ref['n'] < 1 || (int) $ref['n'] > (int) ( $ref['count'] ?? 0 ) ) ) {
			return self::stub( array(), ZANA_Mail_Reply::cannot_start( 'out_of_range', $ref, array( 'count' => (int) ( $ref['count'] ?? 0 ) ) ) );
		}
		$src = self::inbox( 'reply_source', array( $uid, $ref ) );
		$src = is_array( $src ) ? $src : array( 'ok' => false, 'reason' => 'not_permitted' );
		if ( empty( $src['ok'] ) ) {
			$why = (string) ( $src['reason'] ?? '' );
			if ( isset( $ref['from'] ) && in_array( $why, array( 'not_found', 'bad_ref' ), true ) ) {
				self::log( 'no mail from the person named: left to the normal path' );
				return null; // the chat writes them a new email, as on any other turn
			}
			self::log( 'cannot start (' . preg_replace( '/[^a-z_]/', '', $why ) . ', ' . preg_replace( '/[^a-z]/', '', (string) $d['why'] ) . ')' );
			return self::stub( array(), ZANA_Mail_Reply::cannot_start( $why, $ref, $src ) );
		}
		if ( isset( $ref['from'] ) && 'internal' === (string) ( $src['class'] ?? '' ) && ! ZANA_Mail_Reply::says_email( $m ) ) {
			self::log( 'a colleague named without the word email: left to the message path' );
			return null;
		}
		if ( (int) ( $src['id'] ?? 0 ) <= 0 ) {
			return self::stub( array(), ZANA_Mail_Reply::cannot_start( 'not_found', $ref ) );
		}
		self::$source = array( 'uid' => $uid ) + $src;
		self::log( 'answering one message (' . preg_replace( '/[^a-z]/', '', (string) $d['why'] ) . ', ' . strlen( (string) ( $src['text'] ?? '' ) ) . ' chars' . ( ! empty( $src['truncated'] ) ? ', cut' : '' ) . ')' );
		return self::stub( $src, '' );
	}

	/** A reply turn's plan: the message's id, sender, subject and date (never its text), or the line to say. */
	private static function stub( array $src, string $note ): array {
		$plan = array(
			'mail_reply_turn' => true,
			'mail_reply'      => array(),
		);
		if ( '' !== $note ) {
			$plan['mail_reply_note'] = $note;
			return $plan;
		}
		$plan['email_task'] = true;
		$plan['mail_reply'] = array(
			'id'          => (int) ( $src['id'] ?? 0 ),
			'to'          => (string) ( $src['from_addr'] ?? '' ),
			'name'        => (string) ( $src['from_name'] ?? '' ),
			'subject'     => (string) ( $src['subject'] ?? '' ),
			'received_at' => (string) ( $src['received_at'] ?? '' ),
		);
		return $plan;
	}

	/** Does this plan hold an email to answer (so answer() writes the turn)? */
	public static function is_turn( array $plan ): bool {
		return ! empty( $plan['mail_reply_turn'] ) && is_array( $plan['mail_reply'] ?? null ) && (int) ( $plan['mail_reply']['id'] ?? 0 ) > 0;
	}

	/* ───────────────────────────── the turn ───────────────────────────── */

	/**
	 * Write the reply turn. { ok, answer, error, tier, verdict, how, numbers, model_ms, cut_off }. ok false: the model
	 * could not be asked or did not answer, and 'error' is the sentence to say (nothing is made up in its place).
	 *
	 * @param int    $uid     the signed-in person.
	 * @param string $message this turn's own words.
	 * @param array  $context the turn's classification (ZANA_Chat::classify()).
	 * @param array  $plan    route()'s plan.
	 */
	public static function answer( int $uid, string $message, array $context, array $plan ): array {
		$out = array(
			'ok'       => true,
			'answer'   => '',
			'error'    => '',
			'tier'     => 'unknown',
			'verdict'  => 'ok',
			'how'      => 'route',
			'numbers'  => array(),
			'model_ms' => 0,
			'cut_off'  => false,
		);
		$src = self::source_for( $uid, $plan );
		self::$source = array(); // the email's text is held no longer than the turn that asked for it
		if ( empty( $src['ok'] ) ) {
			// The email could be opened when the turn was planned and cannot be now: said so, never a reply to nothing.
			$out['answer'] = ZANA_Mail_Reply::cannot_start( (string) ( $src['reason'] ?? 'not_found' ), array() );
			return $out;
		}
		if ( class_exists( 'ZANA_Sealed' ) ) {
			$src['text']    = (string) ZANA_Sealed::cut( (string) ( $src['text'] ?? '' ) );
			$src['subject'] = (string) ZANA_Sealed::cut( (string) ( $src['subject'] ?? '' ) );
		}
		$meta = array(
			'to'          => (string) ( $src['from_addr'] ?? '' ),   // the stored message's own sender address, nothing else
			'name'        => (string) ( $src['from_name'] ?? '' ),
			'subject'     => (string) ( $src['subject'] ?? '' ),
			'received_at' => (string) ( $src['received_at'] ?? '' ),
		);
		$rec             = self::records( $uid, $src );
		$meta['records'] = $rec['state'];

		$system = class_exists( 'ZANA_Prompt_Builder' ) ? ZANA_Prompt_Builder::build( $uid, $context ) : '';
		$extra  = self::extras( $uid );
		if ( $extra ) {
			$system .= "\n\n" . implode( "\n\n", $extra );
		}
		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'system', 'content' => self::data_block( $uid, $src, $rec ) ),
			array( 'role' => 'user', 'content' => $message ), // this turn's own words; no earlier turn rides along
		);

		$t0 = microtime( true );
		$r  = self::ask( $uid, $messages );
		$out['model_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$out['numbers']  = (array) ( $r['numbers'] ?? array() );
		$out['how']      = 'model';
		if ( empty( $r['ok'] ) ) {
			$out['ok']    = false;
			$out['error'] = (string) ( $r['error'] ?? '' );
			self::log( 'the model did not answer' );
			return $out;
		}
		$out['cut_off'] = ! empty( $r['cut_off'] );
		$done           = self::finish( (string) ( $r['text'] ?? '' ), $meta, $rec['figures'] );
		$out['answer']  = $done['answer'];
		$out['verdict'] = $done['verdict'];
		if ( $rec['figures'] && class_exists( 'ZDZ_Answer_Authority' ) ) {
			$out['tier'] = ZDZ_Answer_Authority::TIER_DERIVED;
		}
		return $out;
	}

	/**
	 * The turn's email: the one route() planned in this request, or (a turn finished in another request) the same
	 * message read again by the id its own plan carries, never by anything from the request.
	 */
	private static function source_for( int $uid, array $plan ): array {
		$id = (int) ( $plan['mail_reply']['id'] ?? 0 );
		if ( $uid <= 0 || $id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}
		$held = self::$source;
		if ( ! empty( $held['ok'] ) && (int) ( $held['uid'] ?? 0 ) === $uid && (int) ( $held['id'] ?? 0 ) === $id ) {
			return $held;
		}
		if ( ! self::may( $uid ) ) {
			return array( 'ok' => false, 'reason' => 'not_permitted' );
		}
		$src = self::inbox( 'reply_source', array( $uid, array( 'id' => $id ) ) );
		return is_array( $src ) ? $src : array( 'ok' => false, 'reason' => 'not_found' );
	}

	/**
	 * The chat's instructions for a reply turn, beside the standing ones: about the person, the business's own facts,
	 * how a draft is written, and the reply's rules. Nothing here is mail. Left out on purpose: the website's pages,
	 * Charts, direct messages, the web and the outreach rest period (that one is for writing to someone first; this
	 * answers someone who wrote in).
	 */
	public static function extras( int $uid ): array {
		$out = array();
		$add = function ( $s ) use ( &$out ) {
			if ( is_string( $s ) && '' !== trim( $s ) ) {
				$out[] = trim( $s );
			}
		};
		if ( class_exists( 'ZANA_User_Memory' ) ) {
			$add( ZANA_User_Memory::prompt_block( $uid ) );
		}
		if ( class_exists( 'ZANA_Company_Facts' ) ) {
			$add( ZANA_Company_Facts::block( class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::tier_of( $uid ) : 'viewer' ) );
		}
		if ( class_exists( 'ZANA_Compose' ) ) {
			$add( ZANA_Compose::draft_rules() );
		}
		$add( ZANA_Mail_Reply::rules() );
		return $out;
	}

	/**
	 * The turn's data, fenced: the one email (and its sender's own record when it may ride along), then the person's
	 * own sent emails to this same sender as a guide to their voice. Without a record, a line outside the fence says
	 * none is attached.
	 */
	public static function data_block( int $uid, array $src, array $rec ): string {
		$email = ZANA_Mail_Reply::email_for_model( $src );
		if ( '' !== (string) ( $rec['block'] ?? '' ) ) {
			$email .= "\n\n" . ZANA_Mail_Reply::no_fence( (string) $rec['block'] );
		}
		$out = array( self::fence( $email ) );
		if ( '' === (string) ( $rec['block'] ?? '' ) ) {
			$out[] = ZANA_Mail_Reply::NO_RECORDS;
		}
		$style = self::style_block( $uid, $src );
		if ( '' !== $style ) {
			$out[] = $style;
		}
		return implode( "\n\n", $out );
	}

	/** Up to three emails the person sent to this same sender, for their voice only (fenced as data). '' when there are none. */
	public static function style_block( int $uid, array $src ): string {
		$to = ZANA_Mail_Reply::plain_addr( (string) ( $src['from_addr'] ?? '' ) );
		if ( '' === $to ) {
			return ''; // with no plain address there is no "same sender": nothing written to anyone else rides along
		}
		try {
			$r = self::inbox( 'style_examples', array( $uid, (string) ( $src['subject'] ?? '' ), 3, $to ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $r ) || empty( $r['ok'] ) || empty( $r['examples'] ) || ! is_array( $r['examples'] ) ) {
			return '';
		}
		$t = "HOW THIS PERSON WRITES TO THIS SENDER (their own sent emails): copy their length, greeting, tone and sign-off; never copy facts, names or addresses from them.\n";
		foreach ( array_slice( $r['examples'], 0, 3 ) as $i => $e ) {
			$t .= '--- example ' . ( $i + 1 ) . " ---\nSubject: " . ZANA_Mail_Reply::no_fence( (string) ( $e['subject'] ?? '' ) ) . "\n" . ZANA_Mail_Reply::no_fence( (string) ( $e['body'] ?? '' ) ) . "\n";
		}
		return self::fence( $t );
	}

	/** Text inside the platform's data fence. */
	private static function fence( string $data ): string {
		if ( class_exists( 'ZANA_Markers' ) && '' !== ZANA_Markers::data_start() ) {
			return ZANA_Markers::fence( $data );
		}
		return "ZDZ-DATA-START\n" . $data . "\nZDZ-DATA-END";
	}

	/**
	 * The sender's own customer record, when it may ride along: { state, block, figures }. state '' (no connector
	 * answered, or the sender is a colleague or has no plain address: nothing is said about records), 'kept' (exactly
	 * one customer, and the email came from an address on their record) or 'not_matched' (the connector answered and
	 * the gate said no: the line under the card says no records were used).
	 */
	public static function records( int $uid, array $src ): array {
		$none = array( 'state' => '', 'block' => '', 'figures' => array() );
		$addr = ZANA_Mail_Reply::plain_addr( (string) ( $src['from_addr'] ?? '' ) );
		if ( '' === $addr || 'internal' === (string) ( $src['class'] ?? '' ) || ! function_exists( 'apply_filters' ) ) {
			return $none;
		}
		try {
			$found = apply_filters( self::HOOK_RECORDS, null, $uid, array( 'addr' => $addr, 'name' => (string) ( $src['from_name'] ?? '' ) ) );
		} catch ( \Throwable $e ) {
			self::log( 'the records connector failed (' . get_class( $e ) . ')' );
			return $none;
		}
		if ( ! is_array( $found ) ) {
			return $none; // no connector is bound: none attached, and nothing claimed about a match
		}
		$clients = array_values( array_filter( $found, 'is_array' ) );
		if ( ! ZANA_Mail_Reply::lookup_is_senders( $addr, $clients ) ) {
			self::log( 'the sender is not one customer on file: no records attached' );
			return array( 'state' => 'not_matched', 'block' => '', 'figures' => array() );
		}
		return array(
			'state'   => 'kept',
			'block'   => ZANA_Mail_Reply::records_block( $clients[0] ),
			'figures' => ZANA_Mail_Reply::record_figures( $clients[0] ),
		);
	}

	/** The model, through the chat's one road. { ok, text, error, numbers, cut_off }. */
	private static function ask( int $uid, array $messages ): array {
		$budget = class_exists( 'ZANA_Chat' ) ? ZANA_Chat::BUDGET_S : 150;
		if ( is_callable( self::$model ) ) {
			$r = call_user_func( self::$model, $messages, array( 'budget_s' => $budget ), $uid );
			return is_array( $r ) ? $r : array( 'ok' => false, 'error' => '' );
		}
		if ( ! class_exists( 'ZANA_LLM_Client' ) ) {
			return array( 'ok' => false, 'error' => __( 'The assistant isn\'t set up on this site yet (the Ai router is missing).', 'zorderz' ) );
		}
		$client = new ZANA_LLM_Client( $uid );
		$r      = $client->chat( $messages, array( 'budget_s' => $budget ) ); // never the web, never a second call
		return array(
			'ok'      => ! empty( $r['ok'] ),
			'text'    => (string) ( $r['text'] ?? '' ),
			'error'   => (string) ( $r['error'] ?? '' ),
			'numbers' => array( 'usd' => (float) ( $client->last_numbers['usd'] ?? 0 ), 'model' => (string) ( $client->last_numbers['model'] ?? '' ) ),
			'cut_off' => 'length' === $client->last_finish_reason(),
		);
	}

	/**
	 * The model's text made into the turn's answer: { answer, verdict }.
	 *
	 *   1. every marker but the one email draft goes, its payload with it;
	 *   2. the draft is rebuilt by the server (To: the stored sender, one card, no image, HTML, JSON or link beside it);
	 *   3. the model's own words around the card pass the outbound gate (a refusal replaces those words; the card
	 *      stays, since it claims nothing to the person);
	 *   4. the draft's own words are assessed too, and what no record confirms is said in a line under the card;
	 *   5. access codes are cut, and the first two steps run once more on the whole answer, so nothing a later step
	 *      added or joined leaves as anything but words.
	 */
	public static function finish( string $raw, array $meta, array $figures = array() ): array {
		$parts   = ZANA_Mail_Reply::pin_parts( ZANA_Mail_Reply::strip_markers( $raw ), $meta, true );
		$verdict = 'ok';
		if ( '' === $parts['card'] ) {
			// No draft: the model asked something, or said why it would not write one. Its words pass the gate whole.
			$g       = self::gate( $parts['before'], $figures );
			$answer  = $g['text'];
			$verdict = $g['verdict'];
		} else {
			$card = $parts['card'];
			if ( '' !== $parts['before'] || '' !== $parts['after'] ) {
				// The card's place is held by a word of the server's own while the words around it are checked.
				$hold  = 'zreplycard' . self::token();
				$g     = self::gate( trim( $parts['before'] . "\n\n" . $hold . "\n\n" . $parts['after'] ), $figures );
				$words = $g['text'];
				if ( 'ok' !== $g['verdict'] ) {
					$verdict = 'partial'; // the answer leaves with words withheld or a caution added
				}
				$answer = false !== strpos( $words, $hold )
					? trim( str_replace( $hold, $card, $words ) )
					: $card . "\n\n" . trim( $words );
			} else {
				$answer = $card;
			}
			$note    = (string) $parts['note'];
			$caution = self::draft_caution( (string) ( $parts['draft'] ?? '' ), $figures );
			if ( '' !== $caution ) {
				$verdict = 'partial';
				$note    = '' !== $note ? $note . "\n" . $caution : $caution;
			}
			if ( '' !== $note ) {
				$answer .= "\n\n" . $note;
			}
		}
		if ( class_exists( 'ZANA_Sealed' ) ) {
			$answer = (string) ZANA_Sealed::cut( $answer );
		}
		// The last look: the same rules again, on the answer as it will be saved and shown.
		$answer = ZANA_Mail_Reply::pin_draft( ZANA_Mail_Reply::strip_markers( $answer ), $meta, false );
		return array( 'answer' => $answer, 'verdict' => $verdict );
	}

	/** Words through the platform's outbound gate (channel 'chat'). { verdict, text }. */
	private static function gate( string $text, array $figures ): array {
		if ( '' === trim( $text ) || ! class_exists( 'ZDZ_Answer_Authority' ) ) {
			return array( 'verdict' => 'ok', 'text' => $text );
		}
		$g = ZDZ_Answer_Authority::gate( array(
			'channel' => 'chat',
			'text'    => $text,
			'context' => array(
				'verified_figures'        => $figures,
				'allowed_fallback_claims' => array(),
				'sor_outcomes'            => array(),
				'side_effect'             => false,
			),
		) );
		return array( 'verdict' => (string) ( $g['verdict'] ?? 'ok' ), 'text' => (string) ( $g['text'] ?? $text ) );
	}

	/**
	 * What the draft states that no record here confirms, as the line(s) for under the card ('' when nothing): the
	 * outbound authority's own findings (ZDZ_Answer_Authority::assess()), by the business's own thresholds. Each
	 * finding is logged as a disposition, like the gate's.
	 */
	private static function draft_caution( string $draft, array $figures ): string {
		if ( '' === trim( $draft ) || ! class_exists( 'ZDZ_Answer_Authority' ) ) {
			return '';
		}
		$a       = ZDZ_Answer_Authority::assess( $draft, array( 'verified_figures' => $figures, 'side_effect' => false ) );
		$claims  = array();
		$amounts = array();
		foreach ( (array) ( $a['dispositions'] ?? array() ) as $d ) {
			$code = (string) ( $d['code'] ?? '' );
			if ( 'outcome_without_sor' === $code ) {
				$claims = array_merge( $claims, (array) ( $d['claims'] ?? array() ) );
			} elseif ( 'unbacked_figure' === $code ) {
				$amounts = array_merge( $amounts, (array) ( $d['figures'] ?? array() ) );
			} else {
				continue;
			}
			if ( function_exists( 'do_action' ) ) {
				do_action( 'zdz_disposition', 'answer_authority', array( 'code' => $code, 'detail' => 'in a reply draft: said beside the draft', 'channel' => 'chat', 'verdict' => 'partial' ) );
			}
		}
		return ZANA_Mail_Reply::unconfirmed_note( $claims, $amounts );
	}

	/** A short word nobody can guess (it holds the card's place for one call). */
	private static function token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 16 );
		}
	}

	/* ───────────────────────────── the inbox app, and who may ───────────────────────────── */

	/**
	 * May one email of this person's be handed to a model at all? Never for nobody, the shared device (it is not a
	 * person and has no mailbox), an account in Privacy View (it reads coded data; an email's text never goes to a
	 * model for it), a site without the inbox app or with Email off, or a person without the Email app.
	 */
	public static function may( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		return self::available() && self::has_app( $uid );
	}

	/** Is the inbox app here, its mail feature on, and able to hand over one email? */
	public static function available(): bool {
		if ( is_callable( self::$inbox ) ) {
			return (bool) call_user_func( self::$inbox, 'available', array() );
		}
		return class_exists( 'ZIB_ZANA_Bridge' ) && method_exists( 'ZIB_ZANA_Bridge', 'reply_source' )
			&& method_exists( 'ZIB_ZANA_Bridge', 'is_available' ) && ZIB_ZANA_Bridge::is_available();
	}

	/** Does this person have the Email app (the platform's own answer for the inbox app)? */
	public static function has_app( int $uid ): bool {
		if ( is_callable( self::$has_app ) ) {
			return (bool) call_user_func( self::$has_app, $uid );
		}
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, defined( 'ZIB_APP_ID' ) ? (string) ZIB_APP_ID : 'inbox' );
		}
		return function_exists( 'zib_user_has_access' ) ? (bool) zib_user_has_access( $uid ) : false;
	}

	/** One call into the inbox app's bridge (or the test double). null when it has no such call. */
	private static function inbox( string $fn, array $args ) {
		if ( is_callable( self::$inbox ) ) {
			return call_user_func( self::$inbox, $fn, $args );
		}
		if ( ! class_exists( 'ZIB_ZANA_Bridge' ) || ! method_exists( 'ZIB_ZANA_Bridge', $fn ) ) {
			return null;
		}
		return call_user_func_array( array( 'ZIB_ZANA_Bridge', $fn ), $args );
	}

	/** One log line: what happened, by code. Never a name, an address, a subject or a word of mail. */
	private static function log( string $what ): void {
		error_log( 'ZANA_Mail_Reply_Route: ' . $what . '.' );
	}
}
