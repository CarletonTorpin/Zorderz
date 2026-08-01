<?php
/**
 * Zorderz Leads — Parallel Dispatch
 *
 * Bounded-concurrency HTTP dispatcher using curl_multi. Provides the
 * primitive behind:
 *   • Bulk Nutshell dedup pre-fetch (Improvement A)
 *   • Parallel AI classification (Improvement C)
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TRAP 1 — BOUNDED CONCURRENCY
 * ──────────────────────────────────────────────────────────────────────────
 * Concurrency is hard-capped by caller via the $max_concurrent argument.
 * The dispatcher NEVER fires more than N requests in flight. Callers set:
 *
 *   POE           cap=4  (halved on 429 — see TSLG_POE_PARALLEL)
 *   NUTSHELL      cap=8
 *   FRESHBOOKS    cap=4
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TRAP 3 — HEARTBEAT PROMISE
 * ──────────────────────────────────────────────────────────────────────────
 * The dispatcher accepts an optional `on_progress` callable invoked after
 * every completed request (not every N). Callers wrap this to update the
 * progress transient so the frontend never goes >30s without a heartbeat.
 *
 * Design notes:
 *   • No per-request dependency on WordPress HTTP API — we need raw
 *     curl_multi for true parallelism. wp_remote_post internally uses
 *     Requests, which is synchronous.
 *   • Each request passes through the same headers/body the caller would
 *     have used with wp_remote_post; auth is the caller's responsibility.
 *   • Rate-limit (HTTP 429) detection is surfaced as a separate flag so
 *     the caller can halve its cap for the remainder of the batch.
 *
 * @package Zorderz\Leads
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Parallel_Dispatch {

	/** Default timeouts — individual requests. */
	const DEFAULT_TIMEOUT_S         = 30;
	const DEFAULT_CONNECT_TIMEOUT_S = 10;

	/**
	 * Run a batch of HTTP requests with bounded concurrency.
	 *
	 * Each request in $requests is an associative array with the following
	 * keys (mirroring wp_remote_post's signature where possible):
	 *
	 *   id              string  — caller-chosen key; preserved in results.
	 *   url             string  — full URL.
	 *   method          string  — GET | POST | PUT (default POST).
	 *   headers         array   — header map.
	 *   body            string  — raw body, already encoded.
	 *   timeout         int     — per-request timeout seconds (optional).
	 *   connect_timeout int     — per-request connect timeout (optional).
	 *
	 * Returned array maps id → {
	 *     status     int       HTTP status (0 if network error)
	 *     body       string    Response body (empty on error)
	 *     headers    string    Raw response headers
	 *     error      string    Non-empty on network-level failure
	 *     elapsed_ms int       Wall-clock per request
	 * }
	 *
	 * @param array         $requests       List of requests (see above).
	 * @param int           $max_concurrent Hard concurrency cap.
	 * @param callable|null $on_progress    Optional fn(id, result, done, total).
	 * @return array Results map keyed by id.
	 */
	public static function run( array $requests, $max_concurrent = 4, $on_progress = null ) {
		if ( empty( $requests ) ) {
			return array();
		}
		$max_concurrent = max( 1, min( 16, (int) $max_concurrent ) );

		// ── Preconditions: curl_multi must be available ─────────────
		if ( ! function_exists( 'curl_multi_init' ) ) {
			// Graceful fallback — execute serially via wp_remote_request.
			return self::run_serial_fallback( $requests, $on_progress );
		}

		$total   = count( $requests );
		$results = array();
		$pending = array_values( $requests ); // fresh queue
		$done    = 0;

		$mh      = curl_multi_init();
		$handles = array();               // handle_id → { ch, req, started_at }
		$next    = 0;

		// Prime the queue up to the concurrency cap.
		while ( count( $handles ) < $max_concurrent && $next < $total ) {
			self::attach_handle( $mh, $handles, $pending[ $next ] );
			$next++;
		}

		// Main multi-exec loop.
		do {
			// Fire off any scheduled transfers.
			do {
				$mrc = curl_multi_exec( $mh, $active );
			} while ( $mrc === CURLM_CALL_MULTI_PERFORM );

			// Block until we have something to read, with a sensible cap.
			if ( $active ) {
				curl_multi_select( $mh, 1.0 );
			}

			// Collect finished transfers.
			while ( $info = curl_multi_info_read( $mh ) ) {
				$ch   = $info['handle'];
				$hid  = (int) $ch;
				$meta = $handles[ $hid ] ?? null;
				if ( ! $meta ) {
					curl_multi_remove_handle( $mh, $ch );
					curl_close( $ch );
					continue;
				}

				$raw     = curl_multi_getcontent( $ch );
				$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
				$err     = curl_error( $ch );
				$elapsed = (int) round( ( microtime( true ) - $meta['started_at'] ) * 1000 );

				// Pull headers out of the raw payload if header/body were combined.
				$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
				$headers  = $hdr_size > 0 ? substr( (string) $raw, 0, $hdr_size ) : '';
				$body     = $hdr_size > 0 ? substr( (string) $raw, $hdr_size )    : (string) $raw;

				$req_id = $meta['req']['id'] ?? spl_object_hash( (object) $meta['req'] );

				$result = array(
					'status'     => $status,
					'body'       => $body,
					'headers'    => $headers,
					'error'      => $err,
					'elapsed_ms' => $elapsed,
				);
				$results[ $req_id ] = $result;
				$done++;

				if ( is_callable( $on_progress ) ) {
					try {
						call_user_func( $on_progress, $req_id, $result, $done, $total );
					} catch ( \Throwable $e ) {
						// Progress-callback failures must never stop the batch.
						error_log( 'ZL parallel-dispatch progress callback error: ' . $e->getMessage() );
					}
				}

				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );
				unset( $handles[ $hid ] );

				// Schedule the next request if any are still queued.
				if ( $next < $total ) {
					self::attach_handle( $mh, $handles, $pending[ $next ] );
					$next++;
				}
			}

		} while ( $active || ! empty( $handles ) );

		curl_multi_close( $mh );
		return $results;
	}

	/**
	 * Attach one request to the multi-handle.
	 *
	 * Registers the handle in $handles by its integer cast (stable for the
	 * handle's lifetime) so we can match info_read's handle to metadata.
	 *
	 * @param resource|\CurlMultiHandle $mh      Multi handle.
	 * @param array                     &$handles Reference to in-flight map.
	 * @param array                     $req     Request descriptor.
	 * @return void
	 */
	private static function attach_handle( $mh, array &$handles, array $req ) {
		$ch = curl_init();
		$method = strtoupper( $req['method'] ?? 'POST' );
		$timeout    = (int) ( $req['timeout']         ?? self::DEFAULT_TIMEOUT_S );
		$connect_to = (int) ( $req['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_S );

		$options = array(
			CURLOPT_URL            => $req['url'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true, // include headers so we can split
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connect_to,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			// Reasonable defaults — callers can override via headers[] if needed.
			CURLOPT_USERAGENT      => 'TS-Sales-Leads/1.8.0 (curl_multi)',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);

		switch ( $method ) {
			case 'GET':
				$options[ CURLOPT_HTTPGET ] = true;
				break;
			case 'PUT':
				$options[ CURLOPT_CUSTOMREQUEST ] = 'PUT';
				if ( isset( $req['body'] ) ) {
					$options[ CURLOPT_POSTFIELDS ] = (string) $req['body'];
				}
				break;
			case 'POST':
			default:
				$options[ CURLOPT_POST ] = true;
				if ( isset( $req['body'] ) ) {
					$options[ CURLOPT_POSTFIELDS ] = (string) $req['body'];
				}
				break;
		}

		if ( ! empty( $req['headers'] ) && is_array( $req['headers'] ) ) {
			$hlines = array();
			foreach ( $req['headers'] as $hk => $hv ) {
				$hlines[] = $hk . ': ' . $hv;
			}
			$options[ CURLOPT_HTTPHEADER ] = $hlines;
		}

		curl_setopt_array( $ch, $options );
		curl_multi_add_handle( $mh, $ch );

		$handles[ (int) $ch ] = array(
			'ch'         => $ch,
			'req'        => $req,
			'started_at' => microtime( true ),
		);
	}

	/**
	 * Fallback for environments where curl_multi is unavailable. Executes
	 * requests serially via wp_remote_request. Used ONLY when curl_multi
	 * is missing — a warning should be surfaced to ops.
	 *
	 * @param array         $requests     Same shape as run().
	 * @param callable|null $on_progress  Same callable signature.
	 * @return array                      Results map keyed by id.
	 */
	private static function run_serial_fallback( array $requests, $on_progress = null ) {
		trigger_error(
			'ZL_Parallel_Dispatch: curl_multi_* not available — falling back to serial wp_remote_request. Install php-curl for proper parallelism.',
			E_USER_WARNING
		);

		$total = count( $requests );
		$done  = 0;
		$results = array();

		foreach ( $requests as $req ) {
			$start = microtime( true );
			$resp = wp_remote_request( $req['url'], array(
				'method'  => strtoupper( $req['method'] ?? 'POST' ),
				'headers' => $req['headers'] ?? array(),
				'body'    => $req['body'] ?? '',
				'timeout' => $req['timeout'] ?? self::DEFAULT_TIMEOUT_S,
			) );
			$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $resp ) ) {
				$result = array(
					'status'     => 0,
					'body'       => '',
					'headers'    => '',
					'error'      => $resp->get_error_message(),
					'elapsed_ms' => $elapsed,
				);
			} else {
				$result = array(
					'status'     => (int) wp_remote_retrieve_response_code( $resp ),
					'body'       => (string) wp_remote_retrieve_body( $resp ),
					'headers'    => '',
					'error'      => '',
					'elapsed_ms' => $elapsed,
				);
			}

			$id = $req['id'] ?? count( $results );
			$results[ $id ] = $result;
			$done++;

			if ( is_callable( $on_progress ) ) {
				try {
					call_user_func( $on_progress, $id, $result, $done, $total );
				} catch ( \Throwable $e ) {
					error_log( 'ZL serial fallback progress error: ' . $e->getMessage() );
				}
			}
		}
		return $results;
	}

	/**
	 * Convenience: detect whether any result in a batch indicates a 429.
	 * Callers use this to halve their concurrency cap for the remainder
	 * of the current generation run (not forever — see Trap 3 of prompt).
	 *
	 * @param array $results Results from run().
	 * @return bool
	 */
	public static function any_rate_limited( array $results ) {
		foreach ( $results as $r ) {
			if ( (int) ( $r['status'] ?? 0 ) === 429 ) {
				return true;
			}
		}
		return false;
	}
}
