<?php
/**
 * ZANA_Mail_Recall — "answer this from my MAILBOX, not the CRM": a pure detector.
 *
 * "What did I last tell Casey?" or a bare "Email" after a customer look-up is a recall of a communication, and it
 * belongs to the person's mailbox (the inbox app's search), not to the customer records.
 *
 * SAFETY BY CONSTRUCTION: the bare "email" follow-up fires only when the turn was already heading to a customer
 * look-up ($has_quick_search), so it can only redirect such a turn; an explicit recall that names the email medium
 * ("what was the last email I sent to Jordan") fires on its own, because the recipient is often a colleague no
 * customer look-up would ever find. A messaging-draft turn ($is_dm_turn) is never touched, and a turn that holds a
 * write instruction anywhere (ZANA_Mail_Compose) is never a recall.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Recall {

	/**
	 * Should this turn go to the mailbox?
	 *
	 * @param string $user_message     the person's words for this turn.
	 * @param bool   $has_quick_search did the turn already look like a customer look-up?
	 * @param bool   $is_dm_turn       was it already claimed as a messaging-draft turn?
	 * @return array { fire:bool, reason:string } reason ∈ {'', 'email-followup', 'recall'}
	 */
	public static function detect( string $user_message, bool $has_quick_search, bool $is_dm_turn ): array {
		$no = array( 'fire' => false, 'reason' => '' );
		if ( $is_dm_turn ) {
			return $no;
		}
		$raw = trim( $user_message );
		$mq  = strtolower( $raw );
		if ( '' === $mq ) {
			return $no;
		}

		// A message that LEADS with a draft / send verb is a WRITE, not a recall. The verb's POSITION tells "what did I
		// last WRITE to Casey" (a recall) from "write back to Casey" (a draft).
		if ( preg_match( '/^(?:can\s+you\s+|could\s+you\s+|please\s+|pls\s+|hey\s+|now\s+|go\s+)*(?:draft|compose|repl(?:y|ies)|respond|forward|write\s+(?:back|a|an|the)\b|send)\b/i', $mq ) ) {
			return $no;
		}
		// …wherever the instruction sits: pasted customer text supplies the recall frame ("what time", "have") and the
		// first person ("let me know", "we"), and "write … email" the verb and the medium.
		if ( class_exists( 'ZANA_Mail_Compose' ) && ZANA_Mail_Compose::is_compose( $raw ) ) {
			return $no;
		}

		// (a) The bare "email" FOLLOW-UP: the whole message is a mail qualifier ("email", "in email", "check my email"),
		//     meaningful only right after a customer look-up — the person is saying "no, my mail".
		if ( $has_quick_search && preg_match( '/^(?:in\s+|via\s+|on\s+|my\s+|the\s+|check\s+(?:my\s+)?|search\s+(?:my\s+)?)?e-?mails?\b[.!?]*$/i', $raw ) ) {
			return array( 'fire' => true, 'reason' => 'email-followup' );
		}

		// (b) A first-person RECALL: a recall verb + an interrogative / recency frame + a first-person subject. All three
		//     are required, so "tell Riley <content>" (imperative, no frame) and "what's Riley's phone" (no recall verb)
		//     both decline.
		$recall_verb  = (bool) preg_match( '/\b(told?|tell|say|said|saying|wrote|writ(?:e|ten|ing)|e-?mail(?:ed)?|sent|send|hear(?:d)?\s+back|repl(?:y|ied)|respond(?:ed)?)\b/i', $mq );
		$recall_frame = (bool) preg_match( '/\b(what|when|whether|did|have|had|last|latest|ever|recently)\b/i', $mq );
		$first_person = (bool) preg_match( '/\b(i|me|my|we|us|our)\b/i', $mq );

		// Two customer-record tugs that would otherwise satisfy the triple — a billing / record NOUN as the object ("did
		// we send Riley an invoice", "tell me what she paid") and a request aimed at the assistant ("tell me what Riley
		// ordered") — unless the ask names the email medium ("what did I EMAIL Riley about the invoice").
		$email_medium = (bool) preg_match( '/\b(e-?mail(?:ed|s)?|inbox|mailbox|messaged?|wrote|writ(?:e|ten|ing)|repl(?:y|ied)|hear(?:d)?\s+back|note)\b/i', $mq );
		$billing_noun = (bool) preg_match( '/\b(invoice|estimate|balance|owe[sd]?|owing|paid|payment|bill(?:ed|ing)?|charge[ds]?|receipt|quote[ds]?)\b/i', $mq );
		$bot_directed = (bool) preg_match( '/\b(tell|show|give|remind|let)\s+(?:me|us)\b/i', $mq );
		if ( ( $billing_noun || $bot_directed ) && ! $email_medium ) {
			return $no;
		}

		// A COUNT ("how many emails did I send…") is a period metric for the sent list, not a content recall.
		$is_count = (bool) preg_match( '/\b(?:how\s+many|number\s+of|count)\b/i', $mq );

		if ( $recall_verb && $recall_frame && $first_person && ( $has_quick_search || ( $email_medium && ! $is_count ) ) ) {
			return array( 'fire' => true, 'reason' => 'recall' );
		}
		return $no;
	}
}
