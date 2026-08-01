<?php
/**
 * ZANA_Markers — the one place the chat protocol tokens are defined.
 *
 * Protocol/marker tokens were taught in the prompt, matched in PHP, and matched
 * again in JS — the same literal typed in three places, so a rename was three edits
 * and a drift was a silent leak of raw "[MARKER]" text to a user. Here they live in
 * ONE constants map: the prompt builder reads it, the parser reads it, and it is
 * published to JS via the `zdz_chat_markers` filter. A rename is one edit.
 *
 * All tokens carry the Z-rename; the pre-rename tokens are recorded as DEPRECATED
 * aliases so an upgraded install still parses an in-flight draft. No
 * company/person/product name appears in any token.
 *
 * Marker discipline (Playbook §7, crosswalk P-42): unknown markers are stripped by
 * default in the host, and a startup assertion should confirm every emittable marker
 * has a registered handler — so emitting a marker before its handler ships can never
 * leak raw text.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Markers {

	/** The assistant proposes an email draft (preview-first; never a send). */
	const EMAIL_DRAFT = '[ZDZ_EMAIL_DRAFT]';

	/** The data fence: everything between START and END is DATA, not instructions. */
	const DATA_START = '⟦ZDZ-DATA-START⟧';
	const DATA_END   = '⟦ZDZ-DATA-END⟧';

	/**
	 * The marker map: capability => { token, deprecated[] }. Cross-app verbs
	 * (estimate create/update/send, commission calc, survey exclude, scheduler book,
	 * lead assign, message post) are owned by THOSE modules and arrive on the same
	 * `zdz_chat_markers` filter — this module never restates another module's token.
	 *
	 * @return array<string,array{token:string,deprecated:string[]}>
	 */
	public static function map(): array {
		return array(
			'analytics.email_draft' => array(
				'token'      => self::EMAIL_DRAFT,
				'deprecated' => array( '[TSA_EMAIL_DRAFT]' ),
			),
			'analytics.data_fence' => array(
				'token'      => self::DATA_START . '…' . self::DATA_END,
				'deprecated' => array( '⟦TS-DATA-START⟧…⟦TS-DATA-END⟧' ),
			),
		);
	}

	/** Wrap a block of fetched data in the fence so the model treats it as inert. */
	public static function fence( string $data ): string {
		return self::DATA_START . "\n" . $data . "\n" . self::DATA_END;
	}

	/**
	 * Strip every known marker (current + deprecated) from a string destined for a
	 * human. The host strips unknown markers by default; this removes the ones this
	 * module owns so a preview token never reaches the reader as raw text.
	 */
	public static function strip( string $text ): string {
		$tokens = array( self::EMAIL_DRAFT, '[TSA_EMAIL_DRAFT]', self::DATA_START, self::DATA_END, '⟦TS-DATA-START⟧', '⟦TS-DATA-END⟧' );
		return trim( str_replace( $tokens, '', $text ) );
	}
}
