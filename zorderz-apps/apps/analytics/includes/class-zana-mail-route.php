<?php
/**
 * ZANA_Mail_Route — the chat's mailbox questions, answered by the inbox app and never by the model.
 *
 * plan() decides, by rule, whether this turn is a question about the person's own mail (or, for a mailbox reader, a
 * colleague's shared mail); answer() asks the inbox app and returns its rendered answer. The model is never in the
 * loop past that decision: what the inbox renders goes straight to the person, so one person's mail never reaches the
 * model's reasoning about anything else, and a message someone mailed in can never be read by a model as an
 * instruction. Every field the inbox renders is already neutralised by it (no marker, link or card can come out of a
 * mail).
 *
 * The decision, in order (the first that applies wins):
 *   0. not for the shared device, nor an account in Privacy View (mail is read in the Email app), nor when the person
 *      said not to use the mail ("do not search the mailbox": ZANA_Mail_Relevance::declined);
 *   1. a request to WRITE an email, or an edit of the draft the chat just wrote, is never a mail read
 *      (ZANA_Email_Turn); the chat drafts it instead;
 *   2. a colleague's mailbox (ZANA_Mail_CrossUser), only for a mailbox reader and a name that is one of the team;
 *   3. a recall of what the person said or sent (ZANA_Mail_Recall), searched as sent mail when it names a recipient;
 *   4. the state of their threads (ZANA_Mail_Followup: who owes a reply, what was sent in a period);
 *   5. any other mailbox question (ZANA_Mail_Relevance), searched by the person / topic the turn names;
 *   6. "yes / widen it" right after a mailbox answer (ZANA_Mail_Continuation).
 *
 * The inbox's API (zorderz-apps/apps/inbox): ZIB_ZANA_Bridge::is_available(), handle_marker() (search, "what did I
 * tell", "what did they send"), followups(), search(), search_subject() when the inbox has it, else
 * ZIB_Gatekeeper::resolve_reader_subject() + admin_search() (the consented, logged colleague read). Identity is always
 * the turn's signed-in person, never anything from the words.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Route {

	/** The envelope every mailbox answer starts with (ZANA_Mail_Continuation reads it on the next turn). */
	const SIGN = "\u{1F4EC}";

	/** Tests hand in the inbox: callable( string $call, array $args ) => array{ok, render, …}|null. */
	public static $inbox = null;

	/** Tests hand in "is this a team member's name": callable( string $name ) => bool. */
	public static $staff = null;

	/** Tests hand in "is this person a mailbox reader": callable( int $uid ) => bool. */
	public static $reader = null;

	/** Tests set today (Y-m-d). */
	public static $today = null;

	/** Is the inbox app here and its mail feature on? */
	public static function available(): bool {
		if ( is_callable( self::$inbox ) ) {
			return true;
		}
		return class_exists( 'ZIB_ZANA_Bridge' ) && method_exists( 'ZIB_ZANA_Bridge', 'is_available' ) && ZIB_ZANA_Bridge::is_available();
	}

	/**
	 * The route for this turn. array() when it is not a mailbox question; otherwise { mail: kind, …the kind's fields,
	 * reason }. kind ∈ crossuser | recall | followup | search | widen | refused. 'email_task' => true marks a turn that
	 * asks for an email to be written (no route; the chat drafts it).
	 *
	 * @param int    $uid      the signed-in person
	 * @param string $message  this turn's words
	 * @param array  $history  earlier turns, oldest first: { role, content }
	 * @param array  $ctx      customer_lookup (bool: the turn reads like a customer look-up), privacy_view (bool),
	 *                         shared_device (bool)
	 */
	public static function plan( int $uid, string $message, array $history = array(), array $ctx = array() ): array {
		$msg = trim( $message );
		if ( $uid <= 0 || '' === $msg || ! empty( $ctx['shared_device'] ) ) {
			return array();
		}
		if ( ZANA_Mail_Relevance::declined( $msg ) ) {
			return array( 'declined' => true );
		}
		// An email to WRITE (or an edit of the one just drafted) is the chat's to draft, never a read.
		if ( ZANA_Email_Turn::is_draft_followup( $msg, $history ) ) {
			return array( 'email_task' => true, 'followup' => true );
		}
		if ( ZANA_Email_Turn::is_email_task( $msg ) || ZANA_Email_Turn::is_style_reference( $msg ) ) {
			return array( 'email_task' => true, 'followup' => false );
		}

		$plan = array();
		$xu   = ZANA_Mail_CrossUser::detect( $msg );
		if ( ! empty( $xu['fire'] ) && self::is_reader( $uid ) && self::is_staff_name( (string) $xu['person'] ) ) {
			$plan = array( 'mail' => 'crossuser', 'person' => (string) $xu['person'], 'query' => (string) $xu['query'], 'reason' => (string) $xu['reason'] );
		}
		if ( ! $plan ) {
			$rc = ZANA_Mail_Recall::detect( $msg, ! empty( $ctx['customer_lookup'] ), false );
			if ( ! empty( $rc['fire'] ) ) {
				$sp   = ZANA_Mail_Relevance::sent_plan( $msg );
				$plan = array( 'mail' => 'recall', 'reason' => (string) $rc['reason'] ) + ( $sp ? $sp : array( 'query' => ZANA_Mail_Relevance::extract_query( $msg ) ) );
			}
		}
		if ( ! $plan ) {
			$fu = ZANA_Mail_Followup::detect( $msg );
			if ( ! empty( $fu['fire'] ) ) {
				// a sent-mail SEARCH ("have I emailed Jordan about…") is the search below, not the period list
				if ( 'sent_log' !== $fu['kind'] || array() === ZANA_Mail_Relevance::sent_plan( $msg ) ) {
					$range = 'sent_log' === $fu['kind'] ? ZANA_Mail_Followup::range_for_period( (string) $fu['period'], self::today() ) : array( '', '' );
					$plan  = array( 'mail' => 'followup', 'kind' => (string) $fu['kind'], 'since' => $range[0], 'until' => $range[1], 'reason' => (string) $fu['reason'] );
				}
			}
		}
		if ( ! $plan ) {
			$mr = ZANA_Mail_Relevance::detect( $msg );
			if ( ! empty( $mr['fire'] ) ) {
				$sp   = ZANA_Mail_Relevance::sent_plan( $msg );
				$plan = array( 'mail' => 'search', 'reason' => (string) $mr['reason'] ) + ( $sp ? $sp : array( 'query' => ZANA_Mail_Relevance::extract_query( $msg ) ) );
			}
		}
		if ( ! $plan && $history ) {
			$wc = ZANA_Mail_Continuation::detect( $msg, $history );
			if ( ! empty( $wc['fire'] ) ) {
				$plan = array( 'mail' => 'widen', 'query' => (string) $wc['query'], 'reason' => (string) $wc['reason'] );
			}
		}
		if ( $plan && ! empty( $ctx['privacy_view'] ) ) {
			return array( 'mail' => 'refused', 'reason' => 'privacy_view' );
		}
		return $plan;
	}

	/**
	 * The inbox's answer for a plan, or null when the inbox could not answer (the chat then says it could not check:
	 * never the model). Always text the inbox rendered, or one of this class's own plain sentences.
	 */
	public static function answer( array $plan, int $uid, string $message ): ?string {
		$kind = (string) ( $plan['mail'] ?? '' );
		if ( '' === $kind || $uid <= 0 ) {
			return null;
		}
		if ( 'refused' === $kind ) {
			return __( 'Email isn\'t read from the chat in Privacy View. Open the Email app instead.', 'zorderz' );
		}
		if ( ! self::available() ) {
			return null;
		}
		try {
			switch ( $kind ) {
				case 'crossuser':
					$r = self::colleague( $uid, (string) $plan['person'], (string) ( $plan['query'] ?? '' ), $message );
					break;
				case 'followup':
					$opts = array_filter( array( 'since' => (string) ( $plan['since'] ?? '' ), 'until' => (string) ( $plan['until'] ?? '' ) ) );
					$r    = self::call( 'followups', array( (string) $plan['kind'], $opts, $uid ) );
					break;
				case 'widen':
					$r = self::call( 'search', array( (string) $plan['query'], $uid, 'list', 'any' ) );
					break;
				default: // recall, search
					$payload = array( 'query' => (string) ( $plan['query'] ?? '' ) );
					if ( '' !== (string) ( $plan['topic'] ?? '' ) ) {
						$payload['topic'] = (string) $plan['topic'];
					}
					if ( 'any' === ( $plan['match'] ?? '' ) ) {
						$payload['match'] = 'any';
					}
					$r = self::call( 'handle_marker', array( $payload, $uid, $message ) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Mail_Route: the inbox could not answer (' . get_class( $e ) . ').' );
			return null;
		}
		$text = is_array( $r ) ? trim( (string) ( $r['render'] ?? '' ) ) : '';
		return '' === $text ? null : $text;
	}

	/** A colleague's shared mailbox: the inbox's own route when it has one, else its gatekeeper and a summary list. */
	private static function colleague( int $uid, string $person, string $query, string $message ): ?array {
		if ( is_callable( self::$inbox ) ) {
			return self::call( 'search_subject', array( $person, $uid, $query ) );
		}
		if ( method_exists( 'ZIB_ZANA_Bridge', 'search_subject' ) ) {
			$dir = method_exists( 'ZIB_ZANA_Bridge', 'detect_direction' ) ? (string) ZIB_ZANA_Bridge::detect_direction( $message ) : 'any';
			return (array) ZIB_ZANA_Bridge::search_subject( $person, $uid, $query, 'list', $dir );
		}
		if ( ! class_exists( 'ZIB_Gatekeeper' ) || ! method_exists( 'ZIB_Gatekeeper', 'resolve_reader_subject' ) || ! method_exists( 'ZIB_Gatekeeper', 'admin_search' ) ) {
			return null;
		}
		$n   = function ( $s ) {
			return method_exists( 'ZIB_ZANA_Bridge', 'neutralize' ) ? (string) ZIB_ZANA_Bridge::neutralize( (string) $s ) : self::plain( (string) $s );
		};
		$res = (array) ZIB_Gatekeeper::resolve_reader_subject( $uid, $person );
		$who = $n( $person );
		switch ( (string) ( $res['status'] ?? 'none' ) ) {
			case 'ok':
				$label = $n( (string) $res['label'] );
				$found = (array) ZIB_Gatekeeper::admin_search( $uid, (int) $res['uid'], $query, 10, 0 );
				if ( empty( $found['ok'] ) ) {
					return array( 'render' => self::SIGN . ' ' . sprintf( __( 'I can\'t read %s\'s mailbox: sharing isn\'t on.', 'zorderz' ), '**' . $label . '**' ) );
				}
				return array( 'render' => self::colleague_list( (array) ( $found['results'] ?? array() ), $label, $n( $query ), $n ) );
			case 'ambiguous':
				return array( 'render' => self::SIGN . ' ' . sprintf( __( 'More than one person matches "%1$s": %2$s. Who do you mean?', 'zorderz' ), $who, implode( ', ', array_map( $n, (array) ( $res['candidates'] ?? array() ) ) ) ) );
			case 'not_shared':
				return array( 'render' => self::SIGN . ' ' . sprintf( __( '%s hasn\'t turned on mailbox sharing, so I can\'t search their mail. They can turn on "Allow admin search" on their mailbox connection.', 'zorderz' ), '**' . $who . '**' ) );
			case 'forbidden':
				return array( 'render' => self::SIGN . ' ' . __( 'I can only search mailboxes that have been shared with you for review.', 'zorderz' ) );
		}
		return array( 'render' => self::SIGN . ' ' . sprintf( __( 'I don\'t see anyone named "%s" with a connected mailbox that\'s shared with you.', 'zorderz' ), $who ) );
	}

	/** A colleague's mail as a summary list (no bodies): their name in the lead and the footer, the read is logged. */
	public static function colleague_list( array $rows, string $who, string $q, callable $n ): string {
		$foot = '_' . sprintf( __( 'You\'re reading %s\'s mailbox because they turned on sharing. This view is logged.', 'zorderz' ), $who ) . '_';
		if ( ! $rows ) {
			return self::SIGN . ' ' . sprintf( __( 'I don\'t see anything in %s\'s indexed mail', 'zorderz' ), '**' . $who . '**' )
				. ( '' !== $q ? ' ' . sprintf( __( 'matching "%s"', 'zorderz' ), $q ) : '' ) . ".\n\n" . $foot;
		}
		$out = array( self::SIGN . ' **' . sprintf( _n( '%d message', '%d messages', count( $rows ), 'zorderz' ), count( $rows ) ) . '** ' . sprintf( __( 'in %s\'s mail', 'zorderz' ), '**' . $who . '**' )
			. ( '' !== $q ? ' ' . sprintf( __( 'matching "%s"', 'zorderz' ), $q ) : '' ) . ', ' . __( 'newest first:', 'zorderz' ), '' );
		$i = 0;
		foreach ( array_slice( $rows, 0, 10 ) as $r ) {
			$i++;
			$subj  = $n( (string) ( $r['subject'] ?? '' ) );
			$from  = $n( (string) ( $r['from'] ?? '' ) );
			$date  = method_exists( 'ZIB_ZANA_Bridge', 'human_date' ) ? (string) ZIB_ZANA_Bridge::human_date( (string) ( $r['received_at'] ?? '' ) ) : '';
			$exc   = $n( (string) ( $r['snippet'] ?? ( $r['excerpt'] ?? '' ) ) );
			$out[] = '**' . $i . '. ' . ( '' !== $subj ? $subj : __( '(no subject)', 'zorderz' ) ) . '**';
			$meta  = array_filter( array( $date, 'sent' === ( $r['direction'] ?? '' ) ? sprintf( __( '%s sent', 'zorderz' ), $who ) : $from ) );
			if ( $meta ) {
				$out[] = '_' . implode( ' · ', $meta ) . '_';
			}
			if ( '' !== $exc ) {
				$out[] = $exc;
			}
			$out[] = '';
		}
		$out[] = $foot;
		return rtrim( implode( "\n", $out ) );
	}

	/** One call into the inbox's bridge (or the test double). */
	private static function call( string $fn, array $args ): ?array {
		if ( is_callable( self::$inbox ) ) {
			$r = call_user_func( self::$inbox, $fn, $args );
			return is_array( $r ) ? $r : null;
		}
		if ( ! class_exists( 'ZIB_ZANA_Bridge' ) || ! method_exists( 'ZIB_ZANA_Bridge', $fn ) ) {
			return null;
		}
		$r = call_user_func_array( array( 'ZIB_ZANA_Bridge', $fn ), $args );
		return is_array( $r ) ? $r : null;
	}

	/** Is this person a mailbox reader (the inbox's own rule)? */
	public static function is_reader( int $uid ): bool {
		if ( is_callable( self::$reader ) ) {
			return (bool) call_user_func( self::$reader, $uid );
		}
		return class_exists( 'ZIB_Gatekeeper' ) && method_exists( 'ZIB_Gatekeeper', 'is_reader' ) && ZIB_Gatekeeper::is_reader( $uid );
	}

	/** Is $name one of the team's people (a first name, a last name or a full name of a signed-up person)? */
	public static function is_staff_name( string $name ): bool {
		if ( is_callable( self::$staff ) ) {
			return (bool) call_user_func( self::$staff, $name );
		}
		$needle = strtolower( trim( (string) preg_replace( "/['\x{2019}]s\\b/u", '', $name ) ) );
		if ( '' === $needle || ! function_exists( 'get_users' ) ) {
			return false;
		}
		foreach ( (array) get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 500 ) ) as $u ) {
			$first = strtolower( trim( (string) get_user_meta( (int) $u->ID, 'first_name', true ) ) );
			$last  = strtolower( trim( (string) get_user_meta( (int) $u->ID, 'last_name', true ) ) );
			$disp  = strtolower( trim( (string) $u->display_name ) );
			if ( in_array( $needle, array_filter( array( $first, $last, trim( $first . ' ' . $last ), $disp ) ), true )
				|| in_array( $needle, preg_split( '/\s+/', $disp ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** The site's date, Y-m-d. */
	public static function today(): string {
		if ( is_string( self::$today ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', self::$today ) ) {
			return self::$today;
		}
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** Plain text for a line of an answer: no marker, link or block can come out of it. */
	public static function plain( string $s ): string {
		$s = str_replace( array( '[', ']', '`', '⟦', '⟧' ), array( '(', ')', "'", '(', ')' ), $s );
		$s = (string) preg_replace( '#\bhttps?(?=://)#i', 'hxxp', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[\x00-\x1F]+/', ' ', $s ) ) );
	}
}
