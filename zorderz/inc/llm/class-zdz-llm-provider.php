<?php
/**
 * ZDZ_LLM_Provider -- the seam every model provider sits behind.
 *
 * The router (ZDZ_Core_LLM) talks to a provider, never to an API, in one neutral shape. Nothing above the router
 * depends on one vendor: the same request runs through each adapter.
 *
 * NEUTRAL REQUEST (built by the router, already coded by ZDZ_Private):
 *   model        the model id the router chose (from a Model Registry slot, or an entry's own model)
 *   system       string (may be '')
 *   system_prefix, system_tier   strings: the cached blocks (only a provider that caches takes them apart)
 *   messages     [ [ 'role' => 'user'|'assistant', 'content' => [ part, ... ] ], ... ]
 *                part: [ 'type' => 'text',  'text' => string, 'cache' => '1h' (optional, the caller's data block) ]
 *                      [ 'type' => 'image', 'media_type' => 'image/jpeg'|'image/png'|'image/gif'|'image/webp', 'data' => base64 ]
 *                      [ 'type' => 'pdf',   'data' => base64, 'text' => the caller's extracted text (optional) ]
 *   json_schema  array|null   effort 'medium'|'high'   web bool   max_output int
 *   cache_latest bool  (a 5-minute mark on the latest block: roles that hold a conversation)
 *
 * NEUTRAL ANSWER:
 *   [ 'text' => string, 'json' => array|null (native structured output, still checked by the router),
 *     'citations' => [ [ 'url', 'title' ], ... ], 'usage' => [ in, cache_write_5m, cache_write_1h, cache_read, out, searches ],
 *     'model' => string, 'http' => int, 'stop' => string, 'usd' => float|null (null: the router prices it) ]
 *   or a WP_Error whose data is [ 'status' => http, 'kind' => overloaded|rate_limited|server|network|auth|bad_request|
 *     wrong_workspace|guard|refused|other, 'billed' => bool (the call probably ran), 'usage' => (when known) ].
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ZDZ_LLM_Provider {

	/** A short code word for the ledger: 'anthropic', 'openai', 'poe', 'fake'. */
	public function name();

	/**
	 * What this adapter can carry:
	 *   images bool · pdf 'native'|'text'|false · cache bool · json_schema 'native'|'ours' · effort bool · web bool ·
	 *   zdr bool (zero data retention is the provider's contract for every feature it sends)
	 */
	public function capabilities();

	/** One try. $deadline is unix seconds by which the answer must have arrived. */
	public function send( array $neutral, $deadline );

	/**
	 * USD per million tokens for $model: [ in, cache_write_5m, cache_write_1h, cache_read, out ] before any regional
	 * factor, or null when unknown. The router never sends a try it cannot price.
	 */
	public function prices( $model );
}

/** Shared helpers for the adapters (no state). */
final class ZDZ_LLM_Wire {

	/** Kind of failure from an HTTP status and an error type. */
	public static function classify( $status, $type = '' ) {
		$status = (int) $status;
		if ( 529 === $status || 'overloaded_error' === $type ) {
			return 'overloaded';
		}
		if ( 429 === $status || 'rate_limit_error' === $type ) {
			return 'rate_limited';
		}
		if ( 401 === $status || 403 === $status || 'authentication_error' === $type || 'permission_error' === $type ) {
			return 'auth';
		}
		if ( 400 === $status || 413 === $status || 422 === $status || 'invalid_request_error' === $type ) {
			return 'bad_request';
		}
		if ( $status >= 500 || 'api_error' === $type ) {
			return 'server';
		}
		return 0 === $status ? 'network' : 'other';
	}

	/** A failed try, said as one. The message is ours: a provider's own words never reach a page. */
	public static function error( $kind, $status = 0, $billed = false, array $usage = null, $retry_after = 0.0 ) {
		$says = array(
			'overloaded'      => __( 'The Ai is busy right now.', 'zorderz' ),
			'rate_limited'    => __( 'The Ai is busy right now.', 'zorderz' ),
			'server'          => __( 'The Ai had a problem answering.', 'zorderz' ),
			'network'         => __( 'The Ai could not be reached in time.', 'zorderz' ),
			'auth'            => __( 'The Ai key was refused. An admin checks the Ai key in the Zorderz settings.', 'zorderz' ),
			'bad_request'     => __( 'The Ai could not take this request.', 'zorderz' ),
			'wrong_workspace' => __( 'The Ai answered from the wrong account, so the answer was not used.', 'zorderz' ),
			'refused'         => __( 'The Ai declined to answer this.', 'zorderz' ),
			'other'           => __( 'The Ai could not answer.', 'zorderz' ),
		);
		$data = array( 'status' => (int) $status, 'kind' => $kind, 'billed' => (bool) $billed, 'retry_after' => (float) $retry_after );
		if ( null !== $usage ) {
			$data['usage'] = $usage;
		}
		return new WP_Error( 'zdz_llm_' . $kind, $says[ $kind ] ?? $says['other'], $data );
	}

	/** A transport error from wp_remote_post(): a time-out probably ran on the model (billed), a refused connection did not. */
	public static function transport_error( WP_Error $e ) {
		$m      = strtolower( $e->get_error_message() );
		$billed = false !== strpos( $m, 'timed out' ) || false !== strpos( $m, 'curl error 28' ) || false !== strpos( $m, 'operation timed' );
		return self::error( 'network', 0, $billed );
	}

	public static function zero_usage() {
		return array( 'in' => 0, 'cache_write_5m' => 0, 'cache_write_1h' => 0, 'cache_read' => 0, 'out' => 0, 'searches' => 0 );
	}

	/** Seconds left before $deadline, by the clock $now. */
	public static function seconds_left( $deadline, $now ) {
		return (float) $deadline - (float) $now;
	}
}
