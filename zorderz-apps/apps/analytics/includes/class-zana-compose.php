<?php
/**
 * ZANA_Compose — an email the chat drafted opens in the Email app, filled in. Nothing is ever sent from the chat.
 *
 * The drafting rules (draft_rules(), for a turn that asks for an email) tell the model to write the draft as
 *   [ZDZ_EMAIL_DRAFT]
 *   To: someone@example.com
 *   Subject: …
 *
 *   the body
 *   [/ZDZ_EMAIL_DRAFT]
 * and the chat page draws it as a card. "Open in Email" posts the draft to `POST {ns}/analytics/compose`, which hands
 * it to the Email app's compose hand-off (filter `zib_compose_handoff`: the draft kept for this person for a few
 * minutes, a one-time token back; the Email app takes it with POST …/compose/handoff/<token> and opens its composer).
 * Without the Email app the answer is a mailto: link for the person's own mail program. The shared device and an
 * account in Privacy View never hand anything off.
 *
 * It also answers the platform's `zdz_compose_handoff` filter (the referral panel's "Press to email") through the same
 * Email app hand-off, so every "open an email" in the chat ends in the same composer.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Compose {

	const OPEN  = '[ZDZ_EMAIL_DRAFT]';
	const CLOSE = '[/ZDZ_EMAIL_DRAFT]';

	public static function boot(): void {
		add_filter( 'zdz_compose_handoff', array( __CLASS__, 'f_compose' ), 10, 3 );
	}

	/** The drafting rules for a turn that asks for an email to be written. */
	public static function draft_rules(): string {
		return "EMAIL DRAFTS: write the email as\n" . self::OPEN . "\nTo: <address, or leave blank>\nSubject: <subject>\n---\n<the email>\n" . self::CLOSE . "\n"
			. 'One draft per answer. Use only an address the person gave or the data shows; never invent one. Sign with the person\'s own first name, never a placeholder like "[Your Name]". '
			. 'Nothing is sent from the chat: the person opens the draft in their email and sends it themselves. Never say an email was sent.';
	}

	/** The draft in an answer: { to, subject, body } or array(). Only addresses that are real addresses are kept. */
	public static function draft_of( string $answer ): array {
		if ( ! preg_match( '/\[ZDZ_EMAIL_DRAFT(?::[^\]\n]{0,80})?\]([\s\S]*?)\[\/ZDZ_EMAIL_DRAFT\]/', $answer, $m ) ) {
			return array();
		}
		return self::parse( (string) $m[1] );
	}

	/** "To: … / Subject: … / a --- line (or a blank line) / body" → { to, subject, body }. */
	public static function parse( string $block ): array {
		$block = str_replace( "\r\n", "\n", trim( $block ) );
		$to    = '';
		$subj  = '';
		$cc    = '';
		$lines = explode( "\n", $block );
		while ( $lines && preg_match( '/^\s*(to|cc|subject)\s*:\s*(.*)$/i', $lines[0], $h ) ) {
			$v = trim( $h[2] );
			if ( 'subject' === strtolower( $h[1] ) ) {
				$subj = $v;
			} elseif ( 'cc' === strtolower( $h[1] ) ) {
				$cc = self::addresses( $v );
			} else {
				$to = self::addresses( $v );
			}
			array_shift( $lines );
		}
		while ( $lines && '' === trim( $lines[0] ) ) {
			array_shift( $lines );
		}
		if ( $lines && preg_match( '/^\s*---\s*$/', $lines[0] ) ) {
			array_shift( $lines );
		}
		return array(
			'to'      => $to,
			'cc'      => $cc,
			'subject' => mb_substr( trim( (string) preg_replace( '/[\x00-\x1F]+/', ' ', $subj ) ), 0, 300 ),
			'body'    => mb_substr( trim( implode( "\n", $lines ) ), 0, 20000 ),
		);
	}

	/** The real addresses in a To / Cc line, comma separated. */
	public static function addresses( string $line ): string {
		preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $line, $m );
		$ok = array_values( array_unique( array_filter( array_map( 'strtolower', $m[0] ), function ( $a ) {
			return false !== filter_var( $a, FILTER_VALIDATE_EMAIL );
		} ) ) );
		return implode( ', ', array_slice( $ok, 0, 10 ) );
	}

	/** May this person hand an email off? */
	public static function refusal( int $uid ): string {
		if ( $uid <= 0 ) {
			return __( 'Sign in first.', 'zorderz' );
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return __( 'Emails are not opened from the shared device.', 'zorderz' );
		}
		if ( class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid ) ) {
			return __( 'Emails are not opened from the chat in Privacy View.', 'zorderz' );
		}
		return '';
	}

	/**
	 * Hand a draft to the Email app: { mode: 'inbox', token, app: 'inbox', href } or { mode: 'mailto', href }, or
	 * { error } when this person may not.
	 */
	public static function open_in_email( int $uid, array $draft ): array {
		$no = self::refusal( $uid );
		if ( '' !== $no ) {
			return array( 'error' => $no );
		}
		$d    = array(
			'to'      => self::addresses( (string) ( $draft['to'] ?? '' ) ),
			'cc'      => self::addresses( (string) ( $draft['cc'] ?? '' ) ),
			'subject' => mb_substr( trim( (string) preg_replace( '/[\x00-\x1F]+/', ' ', (string) ( $draft['subject'] ?? '' ) ) ), 0, 300 ),
			'body'    => mb_substr( (string) ( $draft['body'] ?? '' ), 0, 20000 ),
			'docs'    => array(),
		);
		$href = 'mailto:' . rawurlencode( $d['to'] ) . '?subject=' . rawurlencode( $d['subject'] ) . '&body=' . rawurlencode( $d['body'] ) . ( '' !== $d['cc'] ? '&cc=' . rawurlencode( $d['cc'] ) : '' );
		$h    = self::inbox_token( $uid, $d );
		if ( is_array( $h ) && ! empty( $h['token'] ) && preg_match( '/^[a-f0-9]{32}$/', (string) $h['token'] ) ) {
			return array( 'mode' => 'inbox', 'token' => (string) $h['token'], 'app' => 'inbox', 'href' => $href );
		}
		return array( 'mode' => 'mailto', 'href' => $href );
	}

	/** The Email app's own hand-off (its filter), or null without it. */
	private static function inbox_token( int $uid, array $d ) {
		if ( ! function_exists( 'has_filter' ) || ! has_filter( 'zib_compose_handoff' ) ) {
			return null;
		}
		$h = apply_filters( 'zib_compose_handoff', null, $uid, $d );
		return is_wp_error( $h ) ? null : $h;
	}

	/** The platform's hand-off filter, answered through the Email app's. A non-null earlier answer passes through. */
	public static function f_compose( $prev, $uid = 0, $req = array() ) {
		if ( null !== $prev ) {
			return $prev;
		}
		if ( '' !== self::refusal( (int) $uid ) ) {
			return null;
		}
		$h = self::inbox_token( (int) $uid, is_array( $req ) ? $req : array() );
		return ( is_array( $h ) && ! empty( $h['token'] ) ) ? array( 'token' => (string) $h['token'], 'app' => 'inbox' ) : null;
	}
}
