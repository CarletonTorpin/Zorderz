<?php
/**
 * ZANA_Routes — what the chat answers by rule, what it adds to the model's instructions, and what it does to every
 * answer before anyone reads it.
 *
 * before_model(): a narrow question one app answers is answered by that app, and the model is never asked:
 *   1. "show me the <page> page"           ZANA_Page_Nav (the business's page list)
 *   2. "how many hours did I work…"        ZANA_Hours_Route (hours.lookup)
 *   3. "plan 3 routes near … for …"        ZANA_Plan_Routes (opens the Route Planner)
 *   4. "who can … ?"                       ZANA_App_Reads (roles.who_can)
 *   5. "what sizes of <item> have we measured?"  ZANA_Measurements (the measurement notes the ledger keeps)
 *   6. a question about the person's mail   ZANA_Mail_Route (the inbox app); when the inbox cannot answer, the person
 *                                           is told so — a mail question is never answered by a model.
 *
 * extras(): the turn's additional instructions — "About you", the business's facts, the website pages, Charts, direct
 * messages, email drafting (with the outreach rest period and the person's own past emails as a style guide), the
 * person's "don't use my mail", the web rule.
 *
 * after_model(): chart blocks run and drawn, model-written buttons removed, library links made real, a draft naming an
 * admin-only zone taken out, an email-draft card only on a turn that asked for one, direct-message drafts held for
 * Send, access codes cut. finish() (after the outbound
 * gate): the server's chart buttons and the web note.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Routes {

	/**
	 * A rule answer for this turn, or null (the model answers). { answer, kind, plan }. plan is the mail decision, which
	 * extras() reads (an email to write, a declined mailbox).
	 */
	public static function before_model( int $uid, string $message, array $history, array $ctx = array() ): array {
		$shared = ! empty( $ctx['shared_device'] );
		if ( class_exists( 'ZANA_App_Reads' ) ) {
			ZANA_App_Reads::reset();
		}
		if ( class_exists( 'ZANA_Charts_Tool' ) ) {
			ZANA_Charts_Tool::reset();
		}
		$out = array( 'answer' => null, 'kind' => '', 'plan' => array() );
		if ( $shared || $uid <= 0 ) {
			return $out;
		}
		$fence = ZANA_Markers::data_start();
		if ( '' !== $fence && false !== strpos( $message, $fence ) ) {
			return $out;   // a widget block shared into the chat is the model's to read
		}

		$a = ZANA_Page_Nav::route( $message );
		if ( null !== $a ) {
			return array( 'answer' => $a, 'kind' => 'page', 'plan' => array() );
		}
		$a = ZANA_Hours_Route::route( $uid, $message, $history );
		if ( null !== $a ) {
			return array( 'answer' => $a, 'kind' => 'hours', 'plan' => array() );
		}
		$a = ZANA_Plan_Routes::route( $uid, $message );
		if ( null !== $a ) {
			return array( 'answer' => $a, 'kind' => 'plan_routes', 'plan' => array() );
		}
		$ar = ZANA_App_Reads::route( $uid, $message, $history );
		if ( is_array( $ar ) ) {
			return array( 'answer' => (string) $ar['markdown'], 'kind' => 'app_read', 'plan' => array() );
		}
		if ( class_exists( 'ZANA_Measurements' ) ) {
			$a = ZANA_Measurements::route( $uid, $message );
			if ( null !== $a ) {
				return array( 'answer' => $a, 'kind' => 'measurements', 'plan' => array() );
			}
		}

		$plan = ZANA_Mail_Route::plan( $uid, $message, $history, $ctx );
		if ( '' !== (string) ( $plan['mail'] ?? '' ) ) {
			$a = ZANA_Mail_Route::answer( $plan, $uid, $message );
			return array(
				'answer' => null !== $a ? $a : ZANA_Mail_Guard::refusal(),
				'kind'   => 'mail_' . $plan['mail'],
				'plan'   => $plan,
				'failed' => null === $a,
			);
		}
		$out['plan'] = $plan;
		return $out;
	}

	/** The turn's additional instructions (each a separate block; empty ones are left out). */
	public static function extras( int $uid, string $message, array $history, array $plan, array $flags = array() ): array {
		$out = array();
		$add = function ( $s ) use ( &$out ) {
			if ( is_string( $s ) && '' !== trim( $s ) ) {
				$out[] = trim( $s );
			}
		};
		$add( ZANA_User_Memory::prompt_block( $uid ) );
		$tier = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::tier_of( $uid ) : 'viewer';
		$add( ZANA_Company_Facts::block( $tier ) );
		$add( ZANA_Page_Nav::prompt_block() );
		if ( ! empty( $flags['charts_vocab'] ) ) {
			$add( ZANA_Charts_Tool::prompt_block( (array) $flags['charts_vocab'] ) );
		}
		if ( empty( $flags['privacy_view'] ) ) {
			$add( ZANA_DM_Card::prompt_block( $uid ) );
		}
		if ( ! empty( $plan['declined'] ) ) {
			$add( 'The person asked you NOT to use their mailbox for this question: answer from everything else, and do not offer to search their email.' );
		}
		if ( ! empty( $plan['email_task'] ) && empty( $flags['privacy_view'] ) ) {
			$add( ZANA_Compose::draft_rules() );
			$add( self::cooldown_block( $message, $history ) );
			$add( self::style_block( $uid, $message, $history ) );
		}
		if ( ! empty( $flags['web'] ) ) {
			$add( ZANA_Web_Note::prompt_rule() );
		}
		return $out;
	}

	/** The outreach rest period for the addresses the person typed in this conversation ('' when it is off). */
	public static function cooldown_block( string $message, array $history ): string {
		if ( ZANA_Email_Cooldown::days() <= 0 ) {
			return '';
		}
		$res = array();
		foreach ( ZANA_Email_Turn::typed_addresses( $history, $message ) as $addr ) {
			$res[ $addr ] = ZANA_Email_Cooldown::check( '', $addr );
		}
		return ZANA_Email_Cooldown::prompt_block( $res );
	}

	/** Up to three of the person's own sent emails like this one, for their voice only (fenced as data). */
	public static function style_block( int $uid, string $message, array $history ): string {
		if ( ! class_exists( 'ZIB_ZANA_Bridge' ) || ! method_exists( 'ZIB_ZANA_Bridge', 'style_examples' ) ) {
			return '';
		}
		$topic = ZANA_Email_Turn::style_topic( $message, $history );
		try {
			$r = ZIB_ZANA_Bridge::style_examples( $uid, $topic, 3 );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( empty( $r['ok'] ) || empty( $r['examples'] ) ) {
			return '';
		}
		$t = "HOW THIS PERSON WRITES EMAILS (their own sent emails" . ( empty( $r['related'] ) ? ', recent ones' : ', ones like this' ) . "): copy their length, greeting, tone and sign-off; never copy facts, names or addresses from them.\n";
		foreach ( (array) $r['examples'] as $i => $e ) {
			$t .= '--- example ' . ( $i + 1 ) . " ---\nSubject: " . (string) ( $e['subject'] ?? '' ) . "\n" . (string) ( $e['body'] ?? '' ) . "\n";
		}
		return ZANA_Markers::fence( $t );
	}

	/** Every answer, before it is saved or shown. */
	public static function after_model( string $text, int $uid, int $session_id, array $flags = array() ): string {
		$text = ZANA_Charts_Tool::strip_chip_markup( $text );
		$text = ZANA_Plan_Routes::strip_chip_markup( $text );
		$text = (string) preg_replace( '/<!--\s*ZANA_OPEN_PAGE:[\s\S]*?-->/', '', $text );   // only the page route writes a page directive
		if ( false !== stripos( $text, '[' . ZANA_Charts_Tool::MARKER . ']' ) ) {
			$text = ZANA_Charts_Tool::handle_markers( $text, $uid, (array) ( $flags['charts_vocab'] ?? array() ), ! empty( $flags['charts_vocab'] ) );
		}
		$vl = ZANA_Vault_Links::apply( $text, $uid );
		$text = (string) $vl['text'];
		$text = ZANA_Charts_Tool::guard_admin_only_zones( $text );
		$text = self::email_drafts_only_when_asked( $text, ! empty( $flags['email_task'] ) && empty( $flags['privacy_view'] ) );
		if ( ! empty( $flags['privacy_view'] ) ) {
			$text = (string) preg_replace( '/\[ZANA_DM_DRAFT:[^\]]*\]([\s\S]*?)\[\/ZANA_DM_DRAFT\]/', '$1', $text );   // no button that writes
		} else {
			$text = ZANA_DM_Card::hold( $text, $uid, $session_id );
		}
		return (string) ZANA_Sealed::cut( $text );
	}

	/**
	 * An email-draft card only on a turn that asked for an email (or a change to the last draft), never in Privacy View:
	 * otherwise the block's words stay as plain text and no card is drawn.
	 */
	public static function email_drafts_only_when_asked( string $text, bool $allowed ): string {
		if ( $allowed ) {
			return $text;
		}
		return (string) preg_replace( '/\[\/?ZDZ_EMAIL_DRAFT(?::[^\]\n]{0,80})?\]\n?/', '', $text );
	}

	/** After the outbound gate: the server's own chart buttons and the web note. */
	public static function finish( string $text, array $flags = array() ): string {
		return rtrim( $text ) . ZANA_Charts_Tool::chips_html() . ZANA_Web_Note::disclosure( ! empty( $flags['web'] ) );
	}
}
