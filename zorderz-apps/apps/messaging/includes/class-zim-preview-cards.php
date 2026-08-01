<?php
/**
 * FreshBooks-reference preview cards.
 *
 * Detects #NNNNN tokens in message bodies and, on demand, renders a small
 * preview card (number, kind, customer, amount, status, link). The actual
 * data comes from TSA via its REST endpoint `/zorderz/v1/freshbooks-preview/{id}`
 * — we never talk to FreshBooks directly (Trap 2 — no external API clients
 * in this plugin).
 *
 * Graceful degradation:
 *   • TSA loaded          → proxy through TSA_Analytics_Engine, cache 5 min.
 *   • TSA not loaded      → return a lightweight fallback card that just
 *                           links to the FreshBooks search URL. No error.
 *
 * Rendering is client-driven: the composer JS parses for #NNNNN, inserts a
 * placeholder span, and an IntersectionObserver calls `zim_preview_ref` as
 * the placeholder scrolls into view. This avoids a preview-call storm on
 * initial scrollback of a long conversation.
 *
 * @package TSIM
 */

defined( 'ABSPATH' ) || exit;

class ZIM_Preview_Cards {

	/**
	 * Detection regex.
	 *
	 * Matches `#` followed by 3–8 digits, bounded by non-digit/non-word chars
	 * so we don't match inside numbers (1#1234) or inside markdown anchors.
	 * Pattern adapted from the analytics app's auto_link_fb_documents(). We deliberately
	 * cap at 8 digits — longer sequences are certainly not FB doc numbers.
	 *
	 * Callers should use this with preg_match_all( ..., PREG_SET_ORDER ).
	 */
	const REF_REGEX = '/(?<![\w#])#(\d{3,8})(?!\d)/';

	/** Transient TTL for successful previews. */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Transient TTL for negative lookups — shorter so a mis-typed number recovers fast. */
	const NEG_CACHE_TTL = 60;

	/**
	 * Scan a body and return all distinct reference numbers.
	 *
	 * @return int[] Unique numbers, preserving first-seen order.
	 */
	public static function extract_refs( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return array();
		}
		if ( ! preg_match_all( self::REF_REGEX, $body, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$seen = array();
		foreach ( $m as $hit ) {
			$n = (int) $hit[1];
			if ( $n > 0 && ! isset( $seen[ $n ] ) ) {
				$seen[ $n ] = true;
			}
		}
		return array_keys( $seen );
	}

	/**
	 * Fetch (and cache) the preview card for a single reference number.
	 *
	 * @return array|WP_Error Card payload on success; WP_Error on unresolved.
	 */
	public static function get_card( $number ) {
		$number = absint( $number );
		if ( $number <= 0 ) {
			return new WP_Error( 'zim_preview_bad_id', 'Invalid reference number.' );
		}

		$key    = 'zim_fb_preview_' . $number;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			if ( isset( $cached['__miss'] ) ) {
				return new WP_Error( 'zim_preview_not_found', 'Reference not found.' );
			}
			return $cached;
		}

		// TSA not active? Return a minimal fallback card with no caching —
		// if TSA is enabled later in the same session, the next request will
		// upgrade to a real card.
		if ( ! class_exists( 'TSA_Analytics_Engine' ) ) {
			return self::fallback_card( $number );
		}

		$card = self::fetch_via_tsa( $number );

		if ( is_wp_error( $card ) ) {
			// Cache the miss briefly so a pasted-then-deleted typo doesn't
			// hammer TSA on every poll tick.
			if ( 'zim_preview_not_found' === $card->get_error_code() ) {
				set_transient( $key, array( '__miss' => true ), self::NEG_CACHE_TTL );
			}
			return $card;
		}

		set_transient( $key, $card, self::CACHE_TTL );
		return $card;
	}

	/**
	 * Call the analytics app's REST endpoint via internal dispatch — no external HTTP.
	 *
	 * TSA is expected to expose GET /zorderz/v1/freshbooks-preview/{id} returning
	 * { id, number, kind, customer_name, total, currency, status, url }.
	 * The coordinated patch doc (patches/tsa-v1.11.4-preview-endpoint.md)
	 * defines the contract; until that ships we return a WP_Error that
	 * callers should treat as transient.
	 */
	private static function fetch_via_tsa( $number ) {
		$req = new WP_REST_Request( 'GET', '/zorderz/v1/freshbooks-preview/' . $number );
		// Dispatch in-process — permission checks on the TSA side still run
		// against the current user.
		$resp = rest_do_request( $req );

		if ( $resp->is_error() ) {
			$err = $resp->as_error();
			// 404 → not found (do negative cache); other errors pass through.
			if ( $err && 404 === $resp->get_status() ) {
				return new WP_Error( 'zim_preview_not_found', 'Reference not found.' );
			}
			return new WP_Error(
				'zim_preview_tsa_error',
				$err ? $err->get_error_message() : 'TSA preview failed.'
			);
		}

		$data = $resp->get_data();
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return new WP_Error( 'zim_preview_bad_payload', 'TSA returned no data.' );
		}

		return self::normalize_card( $data );
	}

	/**
	 * Shape the TSA payload into the compact form our card renderer expects.
	 * Defensive against schema drift — unknown fields are dropped.
	 */
	private static function normalize_card( $data ) {
		$kind = isset( $data['kind'] ) ? (string) $data['kind'] : 'document';
		if ( ! in_array( $kind, array( 'invoice', 'estimate', 'document' ), true ) ) {
			$kind = 'document';
		}
		return array(
			'id'            => (int) $data['id'],
			'number'        => isset( $data['number'] ) ? (string) $data['number'] : (string) $data['id'],
			'kind'          => $kind,
			'customer_name' => isset( $data['customer_name'] ) ? (string) $data['customer_name'] : '',
			'total'         => isset( $data['total'] ) ? (float) $data['total'] : null,
			'currency'      => isset( $data['currency'] ) ? (string) $data['currency'] : 'USD',
			'status'        => isset( $data['status'] ) ? (string) $data['status'] : '',
			'url'           => isset( $data['url'] ) ? esc_url_raw( (string) $data['url'] ) : '',
			'source'        => 'tsa',
		);
	}

	/**
	 * Minimal card for when TSA isn't available. The link goes to the
	 * user's own FreshBooks search — no guarantee the number is real.
	 */
	private static function fallback_card( $number ) {
		return array(
			'id'            => $number,
			'number'        => (string) $number,
			'kind'          => 'document',
			'customer_name' => '',
			'total'         => null,
			'currency'      => '',
			'status'        => '',
			'url'           => 'https://my.freshbooks.com/#/search?q=' . rawurlencode( (string) $number ),
			'source'        => 'fallback',
		);
	}
}
