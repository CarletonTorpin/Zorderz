<?php
/**
 * ZANA_Identity — the assistant's part of an Identity Pack: how it sounds, and each person's daily Ai allowance. Each
 * is checked as the assistant reads it, shown in the pack's preview, written on apply and put back exactly on revert
 * (ZDZ_Identity_Pack::option_consumer()).
 *
 *   voice.yml
 *     assistant:
 *       name: Robin                      # what the assistant calls itself; none: "the assistant"
 *       lines:                           # short sentences about how to sound, never facts, prices or people
 *         - Plain words, short sentences.
 *   rules.yml
 *     ai_spend:
 *       daily_per_person_usd: 3          # 0 = no daily stop; at most ZANA_Allowance::MAX_USD
 *
 * Not from a pack: the postal-code lookup service (option zana_zip_place_url). It is where customers' postcodes are
 * sent, which a person on this site chooses (the pack's preview says so).
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Identity {

	const NAME_MAX  = 60;
	const LINE_MAX  = 400;
	const LINES_MAX = 40;

	public static function register( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( ! class_exists( 'ZDZ_Identity_Pack' ) || ! method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			return $consumers;
		}
		$label = __( 'Chat assistant', 'zorderz' );
		$consumers['assistant-voice']     = ZDZ_Identity_Pack::option_consumer( $label, 'voice', array( 'assistant' ), array( __CLASS__, 'plan_voice' ) );
		$consumers['assistant-allowance'] = ZDZ_Identity_Pack::option_consumer( $label, 'rules', array( 'ai_spend.daily_per_person_usd' ), array( __CLASS__, 'plan_allowance' ) );
		return $consumers;
	}

	/**
	 * voice.yml assistant { name, lines } → option zana_voice, as ZANA_Prompt_Builder::voice() reads it. A line that is
	 * not text, or longer than LINE_MAX, is refused by its place; more than LINES_MAX lines, or a name longer than
	 * NAME_MAX, refuses the whole voice: a voice is applied whole or not at all.
	 */
	public static function plan_voice( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		$path = 'assistant';
		$raw  = $data[ $path ];
		ZDZ_Identity_Pack::refuse_extra( $plan, $path, $raw, array( 'name', 'lines' ) );
		$raw   = is_array( $raw ) ? $raw : array();
		$name  = is_scalar( $raw['name'] ?? null ) ? trim( preg_replace( '/\s+/', ' ', (string) $raw['name'] ) ) : '';
		$lines = array();
		$bad   = false;
		if ( mb_strlen( $name ) > self::NAME_MAX || ( isset( $raw['name'] ) && ! is_scalar( $raw['name'] ) ) ) {
			$plan['refused'][] = array( 'path' => "$path.name", 'why' => sprintf( 'A name of at most %d characters.', self::NAME_MAX ) );
			$bad               = true;
		}
		$given = ZDZ_Identity_Pack::as_list( $raw['lines'] ?? array() );
		if ( count( $given ) > self::LINES_MAX ) {
			$plan['refused'][] = array( 'path' => "$path.lines", 'why' => sprintf( 'At most %d lines.', self::LINES_MAX ) );
			$bad               = true;
		}
		foreach ( $given as $i => $l ) {
			$t = is_string( $l ) ? trim( preg_replace( '/\s+/', ' ', $l ) ) : '';
			if ( '' === $t || mb_strlen( $t ) > self::LINE_MAX ) {
				$plan['refused'][] = array( 'path' => "$path.lines." . ( $i + 1 ), 'why' => sprintf( 'A sentence of text, at most %d characters.', self::LINE_MAX ) );
				$bad               = true;
				continue;
			}
			$lines[] = $t;
		}
		if ( $bad || ( '' === $name && ! $lines ) ) {
			return $plan;
		}
		ZDZ_Identity_Pack::plan_option( $plan, $path, ZANA_Prompt_Builder::VOICE_OPT, array( 'name' => $name, 'lines' => $lines ) );
		$plan['notes'][] = 'the voice lines say how the assistant sounds; they are sent with every chat, so they must hold no facts, prices or people.';
		return $plan;
	}

	/** rules.yml ai_spend.daily_per_person_usd → option zana_ai_allowance: dollars a person may spend a day, 0 to MAX_USD. */
	public static function plan_allowance( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		$path = 'ai_spend.daily_per_person_usd';
		$v    = $data[ $path ];
		if ( ! is_numeric( $v ) || (float) $v < 0 || (float) $v > ZANA_Allowance::MAX_USD ) {
			/* translators: %s: the most allowed */
			$plan['refused'][] = array( 'path' => $path, 'why' => sprintf( 'US dollars a person may spend a day, from 0 (no daily stop) to %s.', number_format( ZANA_Allowance::MAX_USD, 0 ) ) );
			return $plan;
		}
		ZDZ_Identity_Pack::plan_option( $plan, $path, ZANA_Allowance::OPTION, ZANA_Allowance::sanitize( $v ) );
		return $plan;
	}
}
