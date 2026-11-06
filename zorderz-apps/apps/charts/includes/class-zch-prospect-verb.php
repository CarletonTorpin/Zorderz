<?php
/**
 * The registry read verb `charts.prospect`: where to canvass, for ANOTHER APP.
 *
 * Another app could reach prospecting through the chat's `charts.run {prospect}`, but that is
 * refused when Charts' Assistant switch is off (a switch about the chat's models, not about apps
 * on this site), and its chat shape drops `bounds`, `street_homes` and the ingredient values a
 * route planner needs. This verb answers the same recipe with the page's row shape
 * (ZCH_Prospect::row()), behind Charts' own per-person door:
 *
 *   - Input:  recipe   the same recipe as charts.run {prospect} / POST …/charts/prospect (an array, or its JSON).
 *             app      optional: the asking app's id. A LABEL for the audit line and the hub's caller
 *                      (`app:<id>`), never a permission: who is asking comes from the registry. Only ids
 *                      on the known list (filter `zch_prospect_apps`) are kept; anything else is
 *                      `app:other`. Any caller may claim a known id, so the label is a hint for the
 *                      audit, not proof.
 *   - Gate:   ZCH_Access::allowed( person ) (the Charts page's own door, its two-factor hook included)
 *             and ZCH_Access::block( person, 'app:<id>' ) must carry run_v2 (the same per-person access
 *             the page uses, with the floors every non-screen caller gets). NOT the Assistant switch.
 *             Never the shared device. Charts' per-minute budget applies, shared with the page. The
 *             sealed block has money, by_person, compare and accounts turned OFF: ranking
 *             neighborhoods needs none of them.
 *   - Output: the ranked rows (name, town, ZIP, homes, walks, up to 8 street names with their home
 *             counts, customers as a COUNT, mix, reason, centre, bounds, miles, last canvassed, each
 *             ingredient's value and score), the ingredient labels, read-as and notes. The rows are
 *             the same for everyone allowed: they are places and company-wide counts. Never a
 *             customer, a name, an amount or an invoice.
 *   - Free text from the hub is scrubbed of anything shaped like money, a phone number or an e-mail
 *     address before it leaves (scrub()), so "never an amount" does not rest on the hub alone.
 *   - A failed read is an error, never an empty success. A hub that did not answer a moment ago is
 *     not asked again for 5 minutes, and the error says so.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Prospect_Verb {
	const VERB       = 'charts.prospect';
	const KNOWN_APPS = array( 'route-planner', 'doorhangers' );
	const BACKOFF_S  = 300;

	public static function get_capability_descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'charts',
			'app'           => ZCH_APP_ID,
			'tier'          => 'viewer',       // anyone signed in; Charts' own per-person access decides the rest.
			'needs_app'     => false,          // a planner's users need the ranking without opening Charts.
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Ranks the region\'s neighborhoods for canvassing by a recipe and returns the rows with their streets, bounds and ingredient scores, for people Charts lets run charts; counts and places only, never a customer, an amount or an invoice. For apps; the chat uses charts.run. Never the shared device.',
		);
	}

	/** The asking app's label: a known id, or 'other'. Never a permission. */
	public static function app_label( $raw ): string {
		$s     = is_scalar( $raw ) ? strtolower( preg_replace( '/[^a-z0-9-]/i', '', (string) $raw ) ) : '';
		$known = (array) apply_filters( 'zch_prospect_apps', self::KNOWN_APPS );
		return ( '' !== $s && in_array( $s, $known, true ) ) ? substr( $s, 0, 30 ) : 'other';
	}

	/** Free text from the hub, without anything shaped like money, a phone number or an e-mail address. */
	public static function scrub( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'scrub' ), $v );
		}
		if ( ! is_string( $v ) ) {
			return $v;
		}
		$v = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[removed]', $v );
		$v = preg_replace( '/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4}\b/', '[removed]', $v );
		$v = preg_replace( '/(?:[$\x{20AC}\x{00A3}]\s?\d[\d,]*(?:\.\d+)?\s?[kKmM]?|\b\d[\d,]*(?:\.\d+)?\s?(?:dollars|USD)\b)/u', '[amount removed]', $v );
		$v = preg_replace( '/\b(?:invoice|inv\.?|estimate)\s*#?\s*\d+/i', '[removed]', $v );
		return $v;
	}

	private static function no( string $why, string $msg, bool $denied = false ): array {
		$out = array( 'success' => false, 'verb' => self::VERB, 'message' => $msg );
		if ( $denied ) {
			$out['denied'] = true;
			$out['reason'] = $why;
		} else {
			$out['error'] = $why;
		}
		return $out;
	}

	/**
	 * The registry callback. $args: recipe, app, plus what ZDZ_Capabilities::invoke() stamps
	 * (user_id, requesting_user_id, tier, is_kiosk: the signed-in person, never the caller's).
	 */
	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || ZCH_Access::is_kiosk( $uid ) ) {
			return self::no( 'kiosk', __( 'Charts is not available on the shared device.', 'zorderz' ), true );
		}
		if ( $uid <= 0 ) {
			return self::no( 'signed_out', __( 'Sign in first.', 'zorderz' ), true );
		}
		if ( ! ZCH_Access::allowed( $uid ) ) {                 // the page's own door, its two-factor hook included.
			return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
		}
		$recipe = $args['recipe'] ?? array();
		if ( is_string( $recipe ) ) {
			$recipe = '' === trim( $recipe ) ? array() : json_decode( $recipe, true );
		}
		if ( ! is_array( $recipe ) ) {
			return self::no( 'bad_recipe', __( 'That recipe could not be read.', 'zorderz' ) );
		}
		$caller = 'app:' . self::app_label( $args['app'] ?? '' );
		$b      = ZCH_Access::block( $uid, $caller );
		if ( ! in_array( 'run_v2', $b['ops'], true ) ) {
			return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
		}
		if ( ! ZCH_Hub_Client::from_config() ) {
			return self::no( 'hub_unavailable', ZCH_Config::local_message() );
		}
		$last = (int) get_option( 'zch_last_fail', 0 );
		if ( $last && time() - $last < self::BACKOFF_S ) {
			return self::no( 'hub_unavailable', __( 'The Charts hub did not answer a moment ago; try again in a few minutes.', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( $uid ) ) {
			return self::no( 'rate_limited', __( 'Too many Charts requests in a minute. Try again shortly.', 'zorderz' ) );
		}
		$reply = ZCH_Prospect::run( $recipe, $uid, $caller, array( 'money' => false, 'by_person' => false, 'compare' => false, 'accounts' => false ) );
		if ( empty( $reply['ok'] ) ) {
			$code = (string) ( $reply['error']['code'] ?? 'hub_error' );
			if ( 'forbidden' === $code ) {
				return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
			}
			if ( 'hub_unavailable' === $code ) {
				return self::no( 'hub_unavailable', __( 'The Charts hub did not answer.', 'zorderz' ) );
			}
			/* translators: %s: an error code */
			return self::no( $code, sprintf( __( 'Charts did not answer (%s).', 'zorderz' ), $code ) );
		}
		$d      = (array) ( $reply['data'] ?? array() );
		$status = (string) ( $d['status'] ?? '' );
		if ( 'clarify' === $status || 'unsupported' === $status ) {
			return self::no( 'clarify', (string) ( $d['clarify_question'] ?? __( 'Charts needs a clearer recipe.', 'zorderz' ) ) );
		}
		if ( 'prospect' !== $status ) {
			// A hub that does not rank neighborhoods reads {prospect} as an empty chart spec: say so, never guess.
			return self::no( 'hub_too_old', __( 'Charts did not answer: the hub does not rank neighborhoods yet.', 'zorderz' ) );
		}
		if ( ! isset( $d['result']['rows'] ) || ! is_array( $d['result']['rows'] ) ) {
			return self::no( 'hub_error', __( 'Charts answered without a list of neighborhoods.', 'zorderz' ) );
		}
		delete_option( 'zch_last_fail' );
		return self::answer( $d, $recipe );
	}

	/** The verb's answer from the hub's prospect answer: the page's rows, every free-text field scrubbed. */
	public static function answer( array $d, array $recipe ): array {
		$p = ZCH_Prospect::for_page( $d );
		$r = $p['result'];
		foreach ( $r['rows'] as &$row ) {
			foreach ( array( 'name', 'town', 'region', 'reason', 'note' ) as $k ) {
				$row[ $k ] = self::scrub( $row[ $k ] );
			}
			$row['streets'] = self::scrub( $row['streets'] );
		}
		unset( $row );
		foreach ( $r['ingredients'] as &$m ) {
			$m['label'] = self::scrub( $m['label'] );
			$m['unit']  = self::scrub( $m['unit'] );
		}
		unset( $m );
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'kind'        => 'prospect',
			'title'       => self::scrub( $r['title'] ),
			'rows'        => $r['rows'],                 // row(): known keys only.
			'ingredients' => $r['ingredients'],          // label, unit, better, zip_wide per ingredient.
			'read_as'     => self::scrub( $r['read_as'] ),
			'notes'       => self::scrub( $r['notes'] ),
			'left_out'    => $r['left_out'],
			'candidates'  => $r['candidates'],
			'caption'     => self::scrub( $p['caption'] ),
			'recipe'      => ZCH_Prospect::clean_recipe( $d['spec']['prospect'] ?? $recipe ),
			'data_as_of'  => (string) ( $p['meta']['data_as_of'] ?? '' ),
		);
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'zdz_register_capabilities',
		function ( $caps ) {
			$caps = is_array( $caps ) ? $caps : array();
			$d    = ZCH_Prospect_Verb::get_capability_descriptor();
			$caps[ $d['verb'] ] = $d;
			return $caps;
		},
		20
	);
}
