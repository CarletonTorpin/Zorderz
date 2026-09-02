<?php
/**
 * Zsch_Intake — the platform Schedule-Job handoff (the receiving side).
 *
 * A launch CARRIES CONTEXT, NEVER AUTHORITY. Any surface adds one HTML attribute
 * `data-zdz-schedule data-ns="project" data-ref="42"`; a single document-level
 * delegated listener (assets/js/schedule-button.js) POSTs `{ns, ref_id}` to
 * POST zorderz/v1/scheduler/intake. This class:
 *
 *   1. fires apply_filters('zdz_compose_context', $bundle, {ns,ref_id,actor})
 *      — a contributor (Jobs' Zjob_Schedule_Context) answers with a prefill
 *      bundle (title/location/body/owner/duration/attendees, ≤4 facts) or a
 *      refusal naming an already-booked date. NO network, NO money in the bundle.
 *   2. SANITIZES EVERY field of the contributor's bundle (a contributor is
 *      another plugin — "the day one forgets is the day a note reaches Outlook
 *      unescaped").
 *   3. asks Zsch_Suggest for genuinely-free start times (local reads only).
 *   4. STASHES the bundle under a 32-char, single-user, single-use, TTL token,
 *      SERVER-SIDE. The origin `{ns, ref_id}` is held under that token and NEVER
 *      travels to the client, a query arg, the URL, referrer, or history (INV-1 /
 *      the PII-in-transit control). Only the opaque token handle goes to the
 *      browser.
 *
 * ON SAVE the create route resolves the token → origin and passes it to
 * ZSCH_Appointments::create(); the origin is read from the token, NEVER from the
 * request body. claim() is single-USE (deleted on read) and single-USER (bound to
 * the actor who minted it) — a token presented by another user is refused (IDOR).
 * Even so, the receiver RE-DERIVES its own gate: the born-linked write re-runs both
 * of Jobs' fail-closed gates (Zjob_Schedule_Context::on_created → attach()).
 *
 * The Core-default token TTL is 2h (raised from 15 min per S4-06's slow-save fix).
 * The authoritative value is Jobs' Zjob_Schedule_Context::token_ttl(); this reads
 * it when present and otherwise the same `zdz_compose_context_token_ttl` filter, so
 * the TTL is never a second hardcode of an already-generalized value.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @since 1.9.0 (handoff port; scheduler 1.7.1 → 1.8.0 in the Zorderz arc)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zsch_Intake {

	/** Token stash key prefix (a transient; the value never leaves the server). */
	const TRANSIENT_PREFIX = 'zsch_intake_';

	/** ZDZ_Share_Link namespace for the opaque token mint / rate control. */
	const TOKEN_NS = 'zsch-intake';

	/** Core-default TTL fallback (seconds) — 2h; Jobs is the authority when present. */
	const TOKEN_TTL_FALLBACK = 7200;

	/**
	 * The REST entry — POST zorderz/v1/scheduler/intake  { ns, ref_id }.
	 * can_write is enforced by the route's permission_callback; the actor is
	 * re-derived from the session here (never from the body — INV-Session).
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|array
	 */
	public static function rest_intake( $req ) {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid <= 0 ) {
			return array( 'ok' => false, 'error' => 'not_logged_in' );
		}
		$ns  = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $req->get_param( 'ns' ) ) : strtolower( trim( (string) $req->get_param( 'ns' ) ) );
		$ref = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $req->get_param( 'ref_id' ) ) : trim( (string) $req->get_param( 'ref_id' ) );
		if ( '' === $ns || '' === $ref ) {
			return array( 'ok' => false, 'error' => 'bad_args' );
		}
		return self::open( $ns, $ref, $uid );
	}

	/**
	 * Compose + suggest + stash. Returns the bundle the editor opens with (never
	 * the origin — that stays server-side under the token).
	 *
	 * @param string $ns     origin namespace (from the launch, re-gated by the contributor).
	 * @param string $ref    origin ref id.
	 * @param int    $actor  the acting user (server-derived).
	 * @return array
	 */
	public static function open( string $ns, string $ref, int $actor ): array {
		if ( '' === $ns || '' === $ref || $actor <= 0 ) {
			return array( 'ok' => false, 'error' => 'bad_args' );
		}

		// Fire the compose contract. The context's origin comes from the launch;
		// the CONTRIBUTOR re-gates the actor and decides what (if anything) to
		// return. INV-1: origin is trusted only here (hook payload), never a body.
		$context = array( 'ns' => $ns, 'ref_id' => $ref, 'actor' => $actor );
		$bundle  = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_compose_context', array(), $context ) : array();
		$bundle  = is_array( $bundle ) ? $bundle : array();

		// The trusted origin to stash: what the contributor echoed back, else the
		// launch pair. (A contributor that declined leaves the bundle empty; we
		// still stash the launch origin so a blank editor can be saved & linked.)
		$origin = ( isset( $bundle['origin'] ) && is_array( $bundle['origin'] ) )
			? array(
				'ns'     => (string) ( $bundle['origin']['ns'] ?? $ns ),
				'ref_id' => (string) ( $bundle['origin']['ref_id'] ?? $ref ),
			)
			: array( 'ns' => $ns, 'ref_id' => $ref );

		// A refusal (already booked) needs no token — the UI jumps to the existing
		// appointment. Never leak anything beyond the id + the date.
		if ( ! empty( $bundle['refused'] ) ) {
			return array(
				'ok'      => true,
				'refused' => (string) $bundle['refused'],
				'appt_id' => (int) ( $bundle['appt_id'] ?? 0 ),
				'when'    => isset( $bundle['when'] ) ? (string) $bundle['when'] : null,
			);
		}

		// SANITIZE every prefill field (a contributor is another plugin). The
		// whitelist STRUCTURALLY excludes any money key — none can pass.
		$prefill = self::sanitize_prefill( ( isset( $bundle['prefill'] ) && is_array( $bundle['prefill'] ) ) ? $bundle['prefill'] : array() );
		$facts   = self::sanitize_facts( ( isset( $bundle['facts'] ) && is_array( $bundle['facts'] ) ) ? $bundle['facts'] : array() );

		// Free-slot suggestion — LOCAL reads only.
		$suggest = class_exists( 'Zsch_Suggest' )
			? Zsch_Suggest::open_slots( $actor, array(
				'duration_min' => (int) ( $prefill['duration_min'] ?? 0 ),
				'owner_id'     => (int) ( $prefill['owner_id'] ?? 0 ),
			) )
			: array();

		// Stash the origin (+ actor) under an opaque, single-user token. The origin
		// NEVER reaches the client.
		$token = self::stash( array(
			'origin'  => $origin,
			'actor'   => $actor,
			'created' => time(),
		) );
		if ( '' === $token ) {
			return array( 'ok' => false, 'error' => 'token_unavailable' );
		}

		return array(
			'ok'      => true,
			'token'   => $token,
			'prefill' => $prefill,
			'suggest' => $suggest,
			'facts'   => $facts,
		);
	}

	/**
	 * Claim the origin for a token — single-USE (deleted on read) and single-USER
	 * (bound to the minting actor). Returns the origin `{ns, ref_id}` or an EMPTY
	 * array when the token is missing, expired, or presented by another user
	 * (IDOR). The create route calls this on save; the origin is never read from
	 * the request body.
	 *
	 * @param string $token
	 * @param int    $actor
	 * @return array{ns?:string,ref_id?:string}
	 */
	public static function claim( string $token, int $actor ): array {
		$token = self::normalize_token( $token );
		if ( '' === $token || $actor <= 0 || ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$payload = get_transient( self::TRANSIENT_PREFIX . $token );
		// Single-use: consume the token regardless of the outcome below.
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		if ( ! is_array( $payload ) ) {
			return array();
		}
		// Single-user: a token minted for one user is not claimable by another.
		if ( (int) ( $payload['actor'] ?? 0 ) !== $actor ) {
			return array();
		}
		$origin = ( isset( $payload['origin'] ) && is_array( $payload['origin'] ) ) ? $payload['origin'] : array();
		$ns     = (string) ( $origin['ns'] ?? '' );
		$ref    = (string) ( $origin['ref_id'] ?? '' );
		if ( '' === $ns || '' === $ref ) {
			return array();
		}
		return array( 'ns' => $ns, 'ref_id' => $ref );
	}

	/* ===================================================================
	 * INTERNAL
	 * =================================================================== */

	/** Mint a 32-char opaque token and stash the payload under it, single-user, TTL. */
	private static function stash( array $payload ): string {
		$token = '';
		if ( class_exists( 'ZDZ_Share_Link' ) && is_callable( array( 'ZDZ_Share_Link', 'mint_opaque' ) ) ) {
			$token = (string) ZDZ_Share_Link::mint_opaque( 16 ); // 16 bytes → 32 hex chars.
		} else {
			try {
				$token = bin2hex( random_bytes( 16 ) );
			} catch ( \Exception $e ) {
				return ''; // refuse rather than mint a weak token.
			}
		}
		if ( '' === $token || ! function_exists( 'set_transient' ) ) {
			return '';
		}
		set_transient( self::TRANSIENT_PREFIX . $token, $payload, self::token_ttl() );
		return $token;
	}

	/** The Core-default compose-context token TTL — Jobs is the authority when present. */
	private static function token_ttl(): int {
		if ( class_exists( 'Zjob_Schedule_Context' ) && is_callable( array( 'Zjob_Schedule_Context', 'token_ttl' ) ) ) {
			$ttl = (int) Zjob_Schedule_Context::token_ttl();
			if ( $ttl > 0 ) {
				return $ttl;
			}
		}
		$ttl = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_compose_context_token_ttl', self::TOKEN_TTL_FALLBACK ) : self::TOKEN_TTL_FALLBACK;
		return $ttl > 0 ? $ttl : self::TOKEN_TTL_FALLBACK;
	}

	/** Canonical token form: 32 lowercase hex chars. */
	private static function normalize_token( string $token ): string {
		$token = strtolower( trim( $token ) );
		$token = preg_replace( '/[^a-f0-9]/', '', $token );
		return (string) $token;
	}

	/**
	 * Whitelist-sanitize the prefill. ONLY these keys survive — a money/price/cost
	 * key is structurally impossible to pass. Every value is escaped for storage.
	 *
	 * @param array $p
	 * @return array
	 */
	private static function sanitize_prefill( array $p ): array {
		$s_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$s_area = function_exists( 'sanitize_textarea_field' ) ? 'sanitize_textarea_field' : 'trim';

		$out = array(
			'title'        => isset( $p['title'] ) ? call_user_func( $s_text, (string) $p['title'] ) : '',
			'location'     => isset( $p['location'] ) ? call_user_func( $s_text, (string) $p['location'] ) : '',
			'body'         => isset( $p['body'] ) ? call_user_func( $s_area, (string) $p['body'] ) : '',
			'owner_id'     => isset( $p['owner_id'] ) ? max( 0, (int) $p['owner_id'] ) : 0,
			'duration_min' => isset( $p['duration_min'] ) ? max( 0, (int) $p['duration_min'] ) : 0,
			'attendees'    => array(),
		);

		// Attendees: valid emails only (never a money value, never free text).
		if ( isset( $p['attendees'] ) && is_array( $p['attendees'] ) ) {
			$emails = array();
			foreach ( $p['attendees'] as $a ) {
				if ( function_exists( 'is_email' ) && is_email( $a ) ) {
					$emails[] = function_exists( 'sanitize_email' ) ? sanitize_email( $a ) : (string) $a;
				}
			}
			$out['attendees'] = array_values( array_unique( $emails ) );
		}

		// The 1.11.0 inferred-date seat: an optional concrete start/end wall-clock a
		// contributor may suggest (a human still confirms). Whitelisted + sanitized.
		if ( isset( $p['start_local'] ) ) {
			$out['start_local'] = call_user_func( $s_text, (string) $p['start_local'] );
		}
		if ( isset( $p['end_local'] ) ) {
			$out['end_local'] = call_user_func( $s_text, (string) $p['end_local'] );
		}

		return $out;
	}

	/** Sanitize the ≤4 context chips; label/value text only, no money. */
	private static function sanitize_facts( array $facts ): array {
		$s_text = function_exists( 'sanitize_text_field' ) ? 'sanitize_text_field' : 'trim';
		$out    = array();
		foreach ( $facts as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$out[] = array(
				'label' => call_user_func( $s_text, (string) ( $f['label'] ?? '' ) ),
				'value' => call_user_func( $s_text, (string) ( $f['value'] ?? '' ) ),
			);
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}
}
