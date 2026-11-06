<?php
/**
 * ZANA_Markers — the one place the chat protocol tokens are defined.
 *
 * A protocol token is taught in the prompt, matched in PHP and matched again in the browser. Kept in ONE constants map,
 * the prompt builder reads it, the parser reads it, and it is published to other apps through the `zdz_chat_markers`
 * filter, so a rename is one edit and a drift can never leak a raw "[MARKER]" to a reader.
 *
 * The data fence is not this app's: it is the platform's one fence token, owned by the kernel
 * (ZDZ_Ask_Context::FENCE_START / FENCE_END), so a widget block shared from any app and the data this chat fetches are
 * fenced the same way. The bracketed form an earlier version of this app wrote is still recognised on read (strip()),
 * never written.
 *
 * Marker discipline: unknown markers are stripped by default in the host, and every marker this module can emit has a
 * handler here, so a marker can never reach a person as raw text. A marker narrated in backticks (a model explaining its
 * own protocol) is defused before any parsing, so an explanation never runs.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Markers {

	/** The assistant proposes an email draft (preview-first; never a send): [ZDZ_EMAIL_DRAFT] … [/ZDZ_EMAIL_DRAFT]. */
	const EMAIL_DRAFT       = '[ZDZ_EMAIL_DRAFT]';
	const EMAIL_DRAFT_CLOSE = '[/ZDZ_EMAIL_DRAFT]';

	/** The model asks for an app's verb: [ZDZ_ACTION]{"verb":"x.y","args":{…}}[/ZDZ_ACTION]. Never run as written. */
	const ACTION_OPEN  = '[ZDZ_ACTION]';
	const ACTION_CLOSE = '[/ZDZ_ACTION]';

	/** A held write in an answer, drawn as a Confirm card: [ZDZ_CONFIRM:<32 hex>]. Written by this app only. */
	const CONFIRM = '[ZDZ_CONFIRM:%s]';

	/** The bracketed fence an earlier version wrote: recognised on read only. */
	const OLD_FENCE = array( '⟦ZDZ-DATA-START⟧', '⟦ZDZ-DATA-END⟧' );

	/** The fence's opening line (the kernel's token; '' only without the theme, when this app does not run). */
	public static function data_start(): string {
		return class_exists( 'ZDZ_Ask_Context' ) ? ZDZ_Ask_Context::FENCE_START : '';
	}

	/** The fence's closing line. */
	public static function data_end(): string {
		return class_exists( 'ZDZ_Ask_Context' ) ? ZDZ_Ask_Context::FENCE_END : '';
	}

	/**
	 * The marker map: capability => { token, deprecated[] }. Cross-app verbs are owned by THOSE modules and arrive on
	 * the same `zdz_chat_markers` filter — this module never restates another module's token.
	 *
	 * @return array<string,array{token:string,deprecated:string[]}>
	 */
	public static function map(): array {
		return array(
			'analytics.email_draft' => array(
				'token'      => self::EMAIL_DRAFT,
				'deprecated' => array(),
			),
			'analytics.data_fence' => array(
				'token'      => self::data_start() . '…' . self::data_end(),
				'deprecated' => array( self::OLD_FENCE[0] . '…' . self::OLD_FENCE[1] ),
			),
			'analytics.action' => array(
				'token'      => self::ACTION_OPEN . '…' . self::ACTION_CLOSE,
				'deprecated' => array(),
			),
		);
	}

	/** Wrap a block of fetched data in the platform's fence so the model treats it as inert. */
	public static function fence( string $data ): string {
		return self::data_start() . "\n" . $data . "\n" . self::data_end();
	}

	/** Every fence token this app recognises on read: the kernel's, then the older bracketed ones. */
	public static function fence_tokens(): array {
		$out = array();
		foreach ( self::map()['analytics.data_fence'] as $k => $v ) {
			foreach ( (array) $v as $pair ) {
				foreach ( explode( '…', (string) $pair ) as $t ) {
					if ( '' !== $t ) {
						$out[ $t ] = $t;
					}
				}
			}
		}
		return array_values( $out );
	}

	/** A confirm card's token for a held action id. */
	public static function confirm( string $id ): string {
		return sprintf( self::CONFIRM, preg_replace( '/[^a-f0-9]/', '', strtolower( $id ) ) );
	}

	/**
	 * A marker narrated in backticks or a code span ("`[ZDZ_ACTION]`") becomes plain words, so the parser never reads an
	 * explanation as a request.
	 */
	public static function defuse_narrated( string $text ): string {
		return (string) preg_replace( '/`\s*\[(\/?)(ZDZ_[A-Z_]+)(?::[^\]`]*)?\]\s*`/', '$2$1_marker', $text );
	}

	/**
	 * Strip every known marker (current + deprecated) from a string destined for a human. The host strips unknown
	 * markers by default; this removes the ones this module owns so a token never reaches the reader as raw text. A
	 * confirm card's token is kept: the page draws it.
	 */
	public static function strip( string $text ): string {
		// A whole email draft ([ZDZ_EMAIL_DRAFT] or [ZDZ_EMAIL_DRAFT:Name] … [/ZDZ_EMAIL_DRAFT]) is kept: the page draws it as a draft card. An
		// unpaired opener or closer is removed below.
		$kept = array();
		$text = (string) preg_replace_callback( '/\[ZDZ_EMAIL_DRAFT(?::[^\]\n]{0,80})?\][\s\S]*?\[\/ZDZ_EMAIL_DRAFT\]/', function ( $m ) use ( &$kept ) {
			$kept[] = $m[0];
			return "\x01" . ( count( $kept ) - 1 ) . "\x02";
		}, $text );
		$text = str_replace( self::EMAIL_DRAFT_CLOSE, '', $text );
		$text = (string) preg_replace( '/\[ZDZ_EMAIL_DRAFT(?::[^\]\n]{0,80})?\]/', '', $text );
		$tokens = array();
		foreach ( self::map() as $cap => $row ) {
			if ( 'analytics.action' === $cap ) {
				continue;   // an action block is replaced by its outcome before the answer reaches anyone
			}
			foreach ( array_merge( array( $row['token'] ), $row['deprecated'] ) as $tok ) {
				foreach ( explode( '…', (string) $tok ) as $t ) {
					if ( '' !== $t ) {
						$tokens[] = $t;
					}
				}
			}
		}
		usort( $tokens, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );   // a bracketed token before the bare one inside it
		$text = str_replace( $tokens, '', $text );
		// A stray action block or tag the model left is never shown as a request.
		$text = (string) preg_replace( '/\[ZDZ_ACTION\].*?\[\/ZDZ_ACTION\]/s', '', $text );
		$text = str_replace( array( self::ACTION_OPEN, self::ACTION_CLOSE ), '', $text );
		$text = (string) preg_replace_callback( "/\x01(\d+)\x02/", function ( $m ) use ( $kept ) {
			return $kept[ (int) $m[1] ] ?? '';
		}, $text );
		return trim( $text );
	}
}
