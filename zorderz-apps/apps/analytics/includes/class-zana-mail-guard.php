<?php
/**
 * ZANA_Mail_Guard — a mailbox turn is answered by the inbox, never by the model. A pure decision.
 *
 * A mail turn (a recall, a thread-state question, a colleague's mailbox) is answered ONLY by the inbox app's own
 * rendered answer (ZANA_Mail_Route → ZIB_ZANA_Bridge). If such a turn ever reached the model and came back as prose
 * with no inbox answer in it — a model answering "did I email Alex?" from nothing, "no emails to Alex on file" — that
 * prose is a made-up claim about the person's mailbox. This class decides exactly that case so the chat refuses the
 * model's text and says plainly that it could not check, instead of shipping it.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Guard {

	/** The inbox app's answer marks: an expandable mail card, or the envelope its answers start with. */
	const CARD = '[ZIB_MAIL]';
	const SIGN = "\u{1F4EC}";

	/**
	 * Did a MAIL turn end WITHOUT the inbox producing the answer? true = refuse the text; false = safe (not a mail
	 * turn, or the inbox's answer is there).
	 *
	 * @param array  $plan     the turn's route plan (ZANA_Mail_Route::plan()); a non-empty 'mail' kind is a mail turn.
	 * @param bool   $answered did the inbox answer this turn?
	 * @param string $response the text about to be shown.
	 */
	public static function model_authored_mail_answer( array $plan, bool $answered, string $response ): bool {
		if ( '' === (string) ( $plan['mail'] ?? '' ) ) {
			return false;
		}
		if ( $answered ) {
			return false;
		}
		// Belt: an inbox card or envelope answer is in the text even though the flag was not set → not model prose.
		return false === strpos( $response, self::CARD ) && 0 !== strpos( ltrim( $response ), self::SIGN );
	}

	/** What the person is told instead. */
	public static function refusal(): string {
		return __( 'I couldn\'t check your email just now, so I won\'t guess what is in it. Please ask again in a minute, or open the Email app.', 'zorderz' );
	}
}
