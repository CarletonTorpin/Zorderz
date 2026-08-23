<?php
/**
 * Shared Poe API Client — the ONE choke every AI call on the platform leaves through.
 *
 * The gateway owns the endpoint and reads the API key from Core settings; the model
 * handle is resolved and remapped through ZDZ_Model_Registry (no model literal lives
 * here). A request is built once, disciplined identically for every caller:
 *   - `stream:false` (Poe otherwise answers SSE and json_decode() returns null);
 *   - a per-FAMILY reasoning knob (a level-taking family wants thinking_level, a
 *     budget-taking family wants thinking_budget, an effort-taking family wants
 *     output_effort) resolved from the filterable, EMPTY-by-default
 *     `zdz_reasoning_families` map — a legacy thinking_budget is TRANSLATED to the
 *     family's knob, never dropped, and when the caller asks for nothing, nothing is
 *     sent (the baseline forced thinking_budget:0 on every fast-tier bot, silently
 *     disabling thinking / 400-ing a level-taking bot);
 *   - the resolved handle run through ZDZ_Model_Registry::remap() before the body is
 *     built (folds an explicitly-passed retired handle to the live one);
 *   - on a retryable status, ONE immediate retry (no worker-holding sleep) with the
 *     registry-resolved fallback handle (the fallback map ships EMPTY → a fresh
 *     install surfaces the error rather than looping).
 *
 * Two return surfaces:
 *   - query(): string — the assistant text, or '' on any failure (as before, so
 *     callers never break); failures are logged.
 *   - request(): array|WP_Error — the rich surface; a WP_Error carries the HTTP
 *     status in its error data (['status','model','body']). query() wraps request().
 *
 * NO vendor, product or model name is hardcoded in this file: the base default is
 * read from ZDZ_Core_Settings / the registry, and the reasoning-family and fallback
 * maps ship EMPTY, populated only by a tenant/connections filter. Ships neutral.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Poe {

	private string $api_key;
	private string $default_model;
	private string $endpoint = 'https://api.poe.com/v1/chat/completions';

	public function __construct( string $api_key = '', string $model = '' ) {
		$this->api_key = $api_key;
		if ( '' === $this->api_key && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_poe_api_key' ) ) {
			$this->api_key = (string) ZDZ_Core_Settings::get_poe_api_key();
		}
		// Default model default is '' (no vendor literal); the registry resolves the
		// neutral base handle from config when nothing is passed.
		$this->default_model = $model;
		if ( '' === $this->default_model && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_ai_model' ) ) {
			$this->default_model = (string) ZDZ_Core_Settings::get_ai_model();
		}
	}

	/**
	 * The rich request surface. Resolve → remap → build → post (→ retry once), and
	 * return either a success array or a WP_Error whose error_data carries the HTTP
	 * status so a caller can diagnose the failure.
	 *
	 * @param array  $messages     Chat messages.
	 * @param float  $temperature  Sampling temperature (kept always-sent, as before).
	 * @param array  $extra_params Optional: thinking_budget|thinking_level|output_effort|web_search.
	 * @param string $model        Optional explicit model handle (else the default).
	 * @return array|WP_Error  ['text','status','model','data'] on success; WP_Error on failure.
	 */
	public function request( array $messages, float $temperature = 0.0, array $extra_params = array(), string $model = '' ) {
		// ── Resolve then remap (closes the S7-08 gap; folds a passed retired handle
		//    to the live one). An empty default resolves through the registry base. ──
		$bot = ( '' !== $model ) ? $model : $this->default_model;
		if ( class_exists( 'ZDZ_Model_Registry' ) ) {
			$bot = ( '' === $bot ) ? ZDZ_Model_Registry::base_model() : ZDZ_Model_Registry::remap( $bot );
		}
		if ( '' === $bot ) {
			return new WP_Error(
				'zdz_no_model',
				__( 'No AI model is configured. Set one under Settings, Zorderz Core.', 'zorderz' ),
				array( 'status' => 0, 'model' => '' )
			);
		}
		if ( '' === trim( $this->api_key ) ) {
			return new WP_Error(
				'zdz_no_key',
				__( 'No Poe API key is set (Settings, Zorderz Core).', 'zorderz' ),
				array( 'status' => 0, 'model' => $bot )
			);
		}

		$body = $this->build_body( $messages, $temperature, $extra_params, $bot );
		list( $code, $raw, $transport ) = $this->post( $body );

		// ── Retryable status: retry ONCE, immediately (no sleep), with the
		//    registry-resolved fallback handle. Empty fallback map → no retry model
		//    → surface the error rather than loop. ──
		if ( null === $transport && in_array( $code, array( 429, 502, 503, 504 ), true ) ) {
			$fb = class_exists( 'ZDZ_Model_Registry' ) ? ZDZ_Model_Registry::fallback_for( $bot ) : '';
			if ( '' !== $fb && class_exists( 'ZDZ_Model_Registry' ) ) {
				$fb = ZDZ_Model_Registry::remap( $fb );
			}
			if ( '' !== $fb && $fb !== $bot ) {
				// Rebuild for the fallback's family (build_body re-runs the knob guard).
				$retry_body = $this->build_body( $messages, $temperature, $extra_params, $fb );
				list( $code2, $raw2, $transport2 ) = $this->post( $retry_body );
				if ( null === $transport2 ) {
					$bot  = $fb;
					$code = $code2;
					$raw  = $raw2;
				}
			}
		}

		if ( null !== $transport ) {
			// Transport failure (DNS / timeout / connection) — no HTTP status.
			return new WP_Error(
				'zdz_transport',
				$transport->get_error_message(),
				array( 'status' => 0, 'model' => $bot )
			);
		}

		$data    = json_decode( $raw, true );
		$content = is_array( $data ) ? (string) ( $data['choices'][0]['message']['content'] ?? '' ) : '';
		if ( '' !== $content ) {
			return array(
				'text'   => $content,
				'status' => $code,
				'model'  => $bot,
				'data'   => is_array( $data ) ? $data : array(),
			);
		}

		// ── No usable content: a diagnosable WP_Error carrying the HTTP status. Keep
		//    the same diagnosable messages the baseline surfaced. ──
		$api_msg = '';
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$api_msg = is_array( $data['error'] ) ? (string) ( $data['error']['message'] ?? '' ) : (string) $data['error'];
		}
		$err_data = array( 'status' => $code, 'model' => $bot, 'body' => substr( (string) $raw, 0, 500 ) );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'zdz_auth',
				sprintf( __( 'Poe rejected the API key (HTTP %d). Check it under Settings, Zorderz Core.', 'zorderz' ), (int) $code ),
				$err_data
			);
		}
		if ( $code >= 400 ) {
			$msg = ( '' !== $api_msg )
				? sprintf( __( 'Poe returned HTTP %1$d. %2$s', 'zorderz' ), (int) $code, $api_msg )
				: sprintf( __( 'Poe returned HTTP %1$d. Confirm the model "%2$s" is available on your Poe plan.', 'zorderz' ), (int) $code, $bot );
			return new WP_Error( 'zdz_http', $msg, $err_data );
		}
		if ( '' !== $api_msg ) {
			return new WP_Error( 'zdz_api', $api_msg, $err_data );
		}
		return new WP_Error(
			'zdz_empty',
			sprintf( __( 'Empty response from model "%s". Confirm the model name is available on your Poe plan.', 'zorderz' ), $bot ),
			$err_data
		);
	}

	/**
	 * The stable string surface. Returns the assistant text, or '' on ANY failure
	 * (unchanged contract, so existing callers never break). Failures are logged.
	 */
	public function query( array $messages, float $temperature = 0.0, array $extra_params = array(), string $model = '' ): string {
		$result = $this->request( $messages, $temperature, $extra_params, $model );
		if ( is_wp_error( $result ) ) {
			$d = $result->get_error_data();
			error_log( sprintf(
				'[ZDZ_Core_Poe] query failed: %s (model=%s, status=%s)',
				$result->get_error_message(),
				is_array( $d ) ? (string) ( $d['model'] ?? '' ) : '',
				is_array( $d ) ? (string) ( $d['status'] ?? '' ) : ''
			) );
			return '';
		}
		return (string) ( $result['text'] ?? '' );
	}

	/**
	 * Build the disciplined request body for a resolved bot. PUBLIC so any batch or
	 * parallel caller builds an identical body. Always sets stream:false; carries
	 * temperature (as the baseline always did); merges the family reasoning knob and,
	 * separately, web_search when the caller set it.
	 */
	public function build_body( array $messages, float $temperature, array $extra_params, string $bot ): array {
		$body = array(
			'model'       => $bot,
			'messages'    => $messages,
			'temperature' => $temperature,
			'stream'      => false,
		);
		$body = array_merge( $body, self::enforce_reasoning_family( $bot, $extra_params ) );
		if ( ! empty( $extra_params['web_search'] ) ) {
			$body['web_search'] = true;
		}
		return $body;
	}

	/**
	 * The family knob-guard. PURE — returns ONLY the reasoning-knob subset to merge.
	 *
	 * A "family" is a rule the platform learns from the `zdz_reasoning_families`
	 * filter. Core ships NO rule and names no vendor; a connections/model-roster pack
	 * supplies the ordered rules (which lower-cased substrings identify a family and
	 * which reasoning parameter that family accepts). Each rule:
	 *
	 *   array(
	 *     'match'       => string|string[],  // ALL lower-cased substrings must be in the id
	 *     'knob'        => string,           // the parameter name this family accepts
	 *     'from_budget' => mixed,            // value to emit when the caller passes a
	 *                                        //   legacy thinking_budget>0 and the knob is
	 *                                        //   NOT itself a budget (e.g. a level string);
	 *                                        //   'passthrough' emits the caller's int
	 *     'from_level'  => array,            // array('none'=>..,'low'=>..,'high'=>..) mapping
	 *                                        //   a caller thinking_level to this knob's value
	 *   )
	 *
	 * Resolution, in order:
	 *   (1) the caller already speaks this family's native knob → pass it through;
	 *   (2) a legacy thinking_budget>0 → TRANSLATE it (never drop);
	 *   (3) a thinking_level → translate via the family's level map;
	 *   (4) otherwise send NOTHING.
	 * When no rule matches (the pack is absent, or the id is unknown) → send NOTHING:
	 * the safe default that never 400s and never silently under-thinks.
	 *
	 * @return array the reasoning-knob subset (web_search is merged by build_body()).
	 */
	public static function enforce_reasoning_family( string $model, array $extra ): array {
		$rules = apply_filters( 'zdz_reasoning_families', array() );
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return array();
		}
		$id = strtolower( $model );

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['knob'] ) ) {
				continue;
			}
			$needles = (array) ( $rule['match'] ?? array() );
			if ( empty( $needles ) ) {
				continue;
			}
			$matched = true;
			foreach ( $needles as $needle ) {
				$needle = strtolower( (string) $needle );
				if ( '' === $needle || false === strpos( $id, $needle ) ) {
					$matched = false;
					break;
				}
			}
			if ( ! $matched ) {
				continue;
			}

			$knob = (string) $rule['knob'];

			// (1) Caller already speaks this family's native knob → passthrough.
			if ( array_key_exists( $knob, $extra ) && '' !== (string) $extra[ $knob ] ) {
				return array( $knob => $extra[ $knob ] );
			}
			// (2) Legacy thinking_budget → translate, never drop.
			if ( isset( $extra['thinking_budget'] ) && (int) $extra['thinking_budget'] > 0 ) {
				if ( 'thinking_budget' === $knob || ( isset( $rule['from_budget'] ) && 'passthrough' === $rule['from_budget'] ) ) {
					return array( $knob => (int) $extra['thinking_budget'] );
				}
				if ( array_key_exists( 'from_budget', $rule ) && null !== $rule['from_budget'] ) {
					return array( $knob => $rule['from_budget'] );
				}
			}
			// (3) Legacy thinking_level → translate via the family's level map.
			if ( isset( $extra['thinking_level'] ) && '' !== (string) $extra['thinking_level'] ) {
				$lvl = strtolower( (string) $extra['thinking_level'] );
				$map = ( isset( $rule['from_level'] ) && is_array( $rule['from_level'] ) ) ? $rule['from_level'] : array();
				if ( array_key_exists( $lvl, $map ) ) {
					return array( $knob => $map[ $lvl ] );
				}
				if ( 'thinking_level' === $knob ) {
					return array( $knob => $lvl ); // native level knob, no remap needed
				}
			}
			// (4) Caller asked for nothing → send nothing.
			return array();
		}
		return array();
	}

	/**
	 * One POST. Returns list( int $code, string $raw, WP_Error|null $transport_error ).
	 * A transport error (WP_Error from wp_remote_post) yields code 0 + a null body.
	 */
	private function post( array $body ): array {
		$args = array(
			'timeout' => self::timeout_for( $body ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);
		$response = wp_remote_post( $this->endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return array( 0, '', $response );
		}
		return array(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			null,
		);
	}

	/** Longer timeout when a reasoning knob signals deep thinking, else the base. */
	private static function timeout_for( array $body ): int {
		$deep = false;
		if ( isset( $body['thinking_budget'] ) && (int) $body['thinking_budget'] > 0 ) {
			$deep = true;
		} elseif ( isset( $body['thinking_level'] ) && 'high' === strtolower( (string) $body['thinking_level'] ) ) {
			$deep = true;
		} elseif ( isset( $body['output_effort'] ) && in_array( strtolower( (string) $body['output_effort'] ), array( 'high', 'max' ), true ) ) {
			$deep = true;
		}
		return $deep ? 180 : 90;
	}

	/**
	 * Extract a JSON object/array from a model reply. Each branch guards with
	 * is_array() so a decoded SCALAR (e.g. "5") is never returned as success.
	 */
	public function parse_llm_json( string $response ): ?array {
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $response, $m ) ) {
			$d = json_decode( $m[1], true );
			return is_array( $d ) ? $d : null;
		}
		if ( preg_match( '/\{.*\}/s', $response, $m ) ) {
			$d = json_decode( $m[0], true );
			return is_array( $d ) ? $d : null;
		}
		if ( preg_match( '/\[.*\]/s', $response, $m ) ) {
			$d = json_decode( $m[0], true );
			return is_array( $d ) ? $d : null;
		}
		return null;
	}
}
