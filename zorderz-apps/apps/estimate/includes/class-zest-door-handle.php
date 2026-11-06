<?php
/**
 * ZEST_Door_Handle - which side the home's existing door handle is on: for the team, never for the customer.
 *
 * An installer notes that an existing door has a right-hand or a left-hand handle; that tells the team how the door
 * opens, and for some products where their housing goes (on the side opposite the handle). The customer never needs it,
 * so it never reaches the customer's estimate: it rides on the line as $li['door'] (kept in this app's stored copy,
 * shown in the review as "Team only", and written in the CRM note under the line), and any handle or mount words that
 * reached the line's text are taken out of it.
 *
 *   line( $li, $cfg )   reads the model's fields (door_handle: left|right, handle_view: inside, housing_side: left|right),
 *                       or handle and mount words in the line's text (taken out), and keeps:
 *                       door { handle (as seen from the reference side), said, view, housing, from, conflict, text }
 *
 * What is the business's (option zest_door_handle, rules.yml `estimates.door_handle`):
 *   enabled         false by default: nothing is asked of the model and no line is read for it
 *   housing_words   the words that mark a product whose housing goes opposite the handle (the business's catalog);
 *                   none: no housing is ever worked out, only the handle side is kept
 *   reference_view  'outside' (default) or 'inside': the side left and right are said from unless the notes say otherwise
 *
 * Pure: no service is called. The words matched are English, as the rest of the parser's are.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Door_Handle {

	const OPT = 'zest_door_handle';

	const HANDLE_RE  = '/[\s,;(-]*\b(?:(?:the\s+)?(?:existing\s+|home\'?s\s+|old\s+)?(?:(?:swing(?:ing)?|slid(?:er|ing)|patio|front|back|entry)\s+)?door\'?s?\s+)?(?:(?:handle|knob|lever)\s+(?:is\s+)?(?:on\s+the\s+)?(left|right)(?:\s*-?\s*hand)?(?:\s+side)?|(left|right)\s*-?\s*hand(?:ed)?(?:\s+(?:door\s+)?(?:handle|knob|lever|door))?|(left|right)\s+(?:side\s+)?(?:handle|knob|lever))\b(?:\s*\((?:as\s+)?(?:seen\s+)?from\s+(?:the\s+)?(inside|outside)\))?[\s.,;)]*/i';
	const HOUSING_RE = '/[\s,;(-]*\b(?:(?:mount(?:ed|ing)?|housing)\s+(?:on\s+the\s+)?(left|right)(?:\s+side)?|(left|right)\s+(?:side\s+)?(?:mount(?:ed|ing)?|housing))\b[\s.,;)]*/i';

	/** The business's settings, checked; deny by default (disabled, no housing words). */
	public static function config(): array {
		$v   = function_exists( 'get_option' ) ? get_option( self::OPT, array() ) : array();
		$v   = is_array( $v ) ? $v : array();
		$out = array(
			'enabled'        => ! empty( $v['enabled'] ),
			'housing_words'  => array(),
			'reference_view' => 'inside' === ( $v['reference_view'] ?? '' ) ? 'inside' : 'outside',
		);
		foreach ( (array) ( $v['housing_words'] ?? array() ) as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9 \-]{1,39}$/', $w ) ) {
				$out['housing_words'][] = $w;
			}
		}
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'zest_door_handle_config', $out ) : $out;
	}

	private static function other( string $side ): string {
		return 'left' === $side ? 'right' : ( 'right' === $side ? 'left' : '' );
	}

	private static function side( $v ): string {
		$v = strtolower( trim( (string) $v ) );
		return in_array( $v, array( 'left', 'right' ), true ) ? $v : '';
	}

	/** Does the line name a product whose housing goes opposite the handle? */
	public static function has_housing( array $li, array $words ): bool {
		$t = strtolower( (string) ( $li['description'] ?? '' ) . ' ' . (string) ( $li['sub_description'] ?? '' ) );
		foreach ( $words as $w ) {
			if ( '' !== $w && preg_match( '/\b' . preg_quote( $w, '/' ) . '/i', $t ) ) {
				return true;
			}
		}
		return false;
	}

	/** One line: the handle side kept for the team, any handle or mount words taken off the customer's text. */
	public static function line( array $li, ?array $cfg = null ): array {
		$cfg = null === $cfg ? self::config() : $cfg;
		if ( empty( $cfg['enabled'] ) ) {
			unset( $li['door_handle'], $li['handle_view'], $li['housing_side'] );
			return $li;
		}
		$desc = (string) ( $li['description'] ?? '' );
		$meta = class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'is_metadata_line' ) && ZDZ_Doc_Conventions::is_metadata_line( $desc, $li['unit_price'] ?? 0 );
		if ( $meta || preg_match( '/\b(location|property location|discount|credit)\b/i', $desc ) ) {
			unset( $li['door_handle'], $li['handle_view'], $li['housing_side'] );
			return $li;
		}
		$ref    = 'inside' === ( $cfg['reference_view'] ?? '' ) ? 'inside' : 'outside';
		$handle = self::side( $li['door_handle'] ?? '' );
		$said   = strtolower( trim( (string) ( $li['handle_view'] ?? '' ) ) );
		$view   = in_array( $said, array( 'inside', 'outside' ), true ) ? $said : $ref;
		$house  = self::side( $li['housing_side'] ?? '' );
		foreach ( array( 'sub_description', 'notes', 'description' ) as $f ) {
			$t = (string) ( $li[ $f ] ?? '' );
			if ( '' === $t ) {
				continue;
			}
			if ( preg_match( self::HANDLE_RE, $t, $m ) ) {
				if ( '' === $handle ) {
					$handle = self::side( ( $m[1] ?? '' ) ?: ( ( $m[2] ?? '' ) ?: ( $m[3] ?? '' ) ) );
					if ( ! empty( $m[4] ) ) {
						$view = strtolower( $m[4] );
					}
				}
				$t = (string) preg_replace( self::HANDLE_RE, ' ', $t );
			}
			if ( preg_match( self::HOUSING_RE, $t, $m ) ) {
				if ( '' === $house ) {
					$house = self::side( ( $m[1] ?? '' ) ?: ( $m[2] ?? '' ) );
				}
				$t = (string) preg_replace( self::HOUSING_RE, ' ', $t );
			}
			$t        = trim( (string) preg_replace( '/\s{2,}/', ' ', $t ), " \t-,;" );
			$li[ $f ] = (string) preg_replace( '/\s+([,.;)])/', '$1', $t );
		}
		unset( $li['door_handle'], $li['handle_view'], $li['housing_side'] );
		if ( '' === $handle && '' === $house ) {
			return $li;   // nothing said: nothing new (a door field kept from an earlier pass stays)
		}
		// left and right as seen from the reference side
		$ref_handle = ( '' !== $handle && $view !== $ref ) ? self::other( $handle ) : $handle;
		$housed     = self::has_housing( $li, (array) ( $cfg['housing_words'] ?? array() ) );
		$derived    = ( $housed && '' !== $ref_handle ) ? self::other( $ref_handle ) : '';
		$housing    = '' !== $house ? $house : $derived;
		$conflict   = '' !== $house && '' !== $derived && $house !== $derived;
		$parts      = array();
		if ( '' !== $handle ) {
			$parts[] = $view !== $ref
				/* translators: 1: left|right as said, 2: inside|outside as said, 3: left|right from the reference side, 4: the reference side */
				? sprintf( __( 'Existing door handle: %1$s as seen from %2$s (%3$s from %4$s)', 'zorderz' ), $handle, $view, $ref_handle, $ref )
				/* translators: 1: left|right, 2: inside|outside */
				: sprintf( __( 'Existing door handle: %1$s (as seen from %2$s)', 'zorderz' ), $handle, $ref );
		}
		if ( $housed && '' !== $housing ) {
			$parts[] = sprintf( __( 'housing: %s', 'zorderz' ), $housing ) . ' ' . ( '' !== $house ? __( '(as the notes say)', 'zorderz' ) : __( '(opposite the handle)', 'zorderz' ) );
		}
		$text = implode( ' - ', $parts );
		if ( $conflict ) {
			/* translators: 1: the housing side the notes give, 2: the handle side, 3: the housing side it implies */
			$text .= '. ' . sprintf( __( 'CHECK: the notes put the housing on the %1$s, but a %2$s handle means a %3$s housing.', 'zorderz' ), $house, $ref_handle, $derived );
		}
		$li['door'] = array(
			'handle'   => $ref_handle,
			'said'     => $handle,
			'view'     => $view,
			'housing'  => $housed ? $housing : '',
			'from'     => '' !== $house ? 'notes' : ( '' !== $derived ? 'handle' : '' ),
			'conflict' => $conflict,
			'text'     => $text,
		);
		return $li;
	}

	/** Every line of a parse. */
	public static function apply( array $items, ?array $cfg = null ): array {
		$cfg = null === $cfg ? self::config() : $cfg;
		foreach ( $items as $k => $li ) {
			if ( is_array( $li ) ) {
				$items[ $k ] = self::line( $li, $cfg );
			}
		}
		return $items;
	}

	/** The model's instruction, only when the business uses it ('' otherwise). */
	public static function prompt_block( ?array $cfg = null ): string {
		$cfg = null === $cfg ? self::config() : $cfg;
		if ( empty( $cfg['enabled'] ) ) {
			return '';
		}
		$ref = 'inside' === ( $cfg['reference_view'] ?? '' ) ? 'INSIDE' : 'OUTSIDE';
		$alt = 'INSIDE' === $ref ? 'outside' : 'inside';
		return "## Existing door handle (team only)\n"
			. 'When the notes say which side the home\'s existing door handle is on ("right-hand handle", "handle on the left"), put it on that door\'s line as "door_handle": "left" or "right". Left and right are as seen from ' . $ref . ' the home unless the notes say the person was looking from ' . $alt . '; then also put "handle_view": "' . $alt . '". If the notes name the housing or mount side directly, put it in "housing_side": "left" or "right". NEVER write any of this in description, sub_description or notes: those reach the customer.';
	}

	/** The CRM note's line under an item ('' when none). */
	public static function team_line( array $li ): string {
		$t = is_array( $li['door'] ?? null ) ? trim( (string) ( $li['door']['text'] ?? '' ) ) : '';
		return '' === $t ? '' : 'Team: ' . str_replace( array( "\u{00D7}", '[', ']' ), array( 'x', '(', ')' ), $t );
	}
}
