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
 *   - on a failure another model might not have (ZDZ_Model_Registry::fails_over():
 *     400 / 404 / 408 / 413 / 422, 429, 5xx, 529, and a time-out when the call has a
 *     budget and time is left; never 401 / 403, a key problem), ONE immediate retry (no
 *     worker-holding sleep) with the registry-resolved fallback handle (the fallback map
 *     ships EMPTY → a fresh install surfaces the error rather than looping). A handle
 *     that answered 404 is remembered for an hour and not asked again as a fallback;
 *   - web search only for a model that can search (ZDZ_Model_Registry::web_capable()).
 *     A call for one that cannot first asks the registry's `web` slot for findings
 *     (only a short, text-only question travels to the web: ZDZ_Core_LLM::web_safe())
 *     and hands them over as context, with web search off; with no findings, the model
 *     is told it could not look anything up, so it says so instead of guessing.
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
	 * Two caller-side knobs are read here and never sent: `_budget_s` (int, seconds) caps
	 * the HTTP wait so a caller with a deadline is never held past it (never under 15 s),
	 * and `_no_failover` (bool) answers from exactly the model asked for or not at all,
	 * skipping the fallback retry (the Ai router sends a role to Poe only by an admin's
	 * choice, and a fallback model is a choice nobody made).
	 *
	 * @param array  $messages     Chat messages.
	 * @param float  $temperature  Sampling temperature (kept always-sent, as before).
	 * @param array  $extra_params Optional: thinking_budget|thinking_level|output_effort|web_search|_budget_s|_no_failover.
	 * @param string $model        Optional explicit model handle (else the default).
	 * @return array|WP_Error  ['text','status','model','data'] on success; WP_Error on failure.
	 */
	public function request( array $messages, float $temperature = 0.0, array $extra_params = array(), string $model = '' ) {
		// ── Resolve then remap (a passed retired handle folds to the live one, so a
		//    caller can never pin a retired model). An empty default resolves through
		//    the registry base. ──
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

		$budget_s = isset( $extra_params['_budget_s'] ) && is_numeric( $extra_params['_budget_s'] ) ? max( 15, (int) $extra_params['_budget_s'] ) : 0;
		$started  = microtime( true );
		$asked    = array( $messages, $extra_params );   // the caller's own, before any web findings are added
		list( $messages, $extra_params ) = $this->web_for( $bot, $messages, $extra_params, $budget_s );
		$body     = $this->build_body( $messages, $temperature, $extra_params, $bot );
		list( $code, $raw, $transport ) = $this->post( $body, $budget_s );
		if ( null === $transport && 404 === $code && class_exists( 'ZDZ_Model_Registry' ) ) {
			ZDZ_Model_Registry::mark_gone( $bot, 'poe' );   // a real 404: not asked again as a fallback for an hour
		}

		// ── A failure another model might not have: retry ONCE, immediately (no
		//    sleep), with the registry-resolved fallback handle. Empty fallback map →
		//    no retry model → surface the error rather than loop. A time-out is
		//    retried only when the caller gave a budget and enough of it is left. A
		//    caller that asked for no failover gets the first answer as it is. ──
		$left = $budget_s > 0 ? $budget_s - ( microtime( true ) - $started ) : 0;
		if ( empty( $extra_params['_no_failover'] ) && class_exists( 'ZDZ_Model_Registry' )
			&& ( null === $transport ? ( $code >= 400 && ZDZ_Model_Registry::fails_over( $code ) ) : $left >= 15 ) ) {
			$fb = ZDZ_Model_Registry::remap( ZDZ_Model_Registry::fallback_for( $bot ) );
			if ( '' !== $fb && $fb !== $bot && ! ZDZ_Model_Registry::is_gone( $fb, 'poe' ) ) {
				// Rebuilt for the fallback: its own web rule (findings already fetched are reused, never asked twice)
				// and its family's knob (build_body re-runs the guard).
				$reuse = $messages !== $asked[0] && ! ZDZ_Model_Registry::web_capable( $fb );
				list( $fb_messages, $fb_extra ) = $reuse ? array( $messages, $extra_params ) : $this->web_for( $fb, $asked[0], $asked[1], $budget_s );
				$retry_body = $this->build_body( $fb_messages, $temperature, $fb_extra, $fb );
				list( $code2, $raw2, $transport2 ) = $this->post( $retry_body, $budget_s > 0 ? (int) max( 15, floor( $left ) ) : 0 );
				if ( null === $transport2 && 404 === $code2 ) {
					ZDZ_Model_Registry::mark_gone( $fb, 'poe' );
				}
				if ( null === $transport2 ) {
					$bot       = $fb;
					$code      = $code2;
					$raw       = $raw2;
					$transport = null;
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

	/** Said to a model without web search when the call wanted the web and no findings came. */
	public static function no_web_note(): string {
		return 'Note: live web search is not available for this answer. If the question needs current information from the web, say plainly that you could not look it up, and do not invent it.';
	}

	/**
	 * The messages and parameters for $bot when the caller asked for web search. A model that can search keeps
	 * web_search. One that cannot never gets it: the registry's `web` slot is asked for findings on the question
	 * (only when the messages are a short, text-only question, ZDZ_Core_LLM::web_safe(); never business data), and they
	 * go first as a system message; with none, the no-web note does.
	 *
	 * @return array{0:array,1:array} [ messages, extra_params ]
	 */
	private function web_for( string $bot, array $messages, array $extra_params, int $budget_s ): array {
		if ( empty( $extra_params['web_search'] ) || ! class_exists( 'ZDZ_Model_Registry' ) || ZDZ_Model_Registry::web_capable( $bot ) ) {
			return array( $messages, $extra_params );
		}
		unset( $extra_params['web_search'] );
		$found = '';
		$web   = ZDZ_Model_Registry::web_model( $bot, 'poe' );
		if ( '' !== $web && class_exists( 'ZDZ_Core_LLM' ) && ZDZ_Core_LLM::web_safe( $messages ) ) {
			$sys = 'You are the web-research step for another model. Search the web for what the question needs and return '
				. 'concise factual findings with concrete figures and dates, each followed by the exact URL it came from. Say '
				. 'when sources disagree or the web is inconclusive. Never invent a figure or a URL.';
			$q   = '';
			foreach ( $messages as $m ) {
				if ( 'user' === ( $m['role'] ?? '' ) && is_string( $m['content'] ?? null ) ) {
					$q = $m['content'];
				}
			}
			$r = $this->request(
				array( array( 'role' => 'system', 'content' => $sys ), array( 'role' => 'user', 'content' => $q ) ),
				0.2,
				array( 'web_search' => true, '_no_failover' => true, '_budget_s' => $budget_s > 0 ? max( 15, (int) floor( $budget_s / 2 ) ) : 60 ),
				$web
			);
			$found = is_wp_error( $r ) ? '' : trim( (string) ( $r['text'] ?? '' ) );
		}
		$note = '' !== $found
			? "WEB FINDINGS (from a separate web search; first-pass, unverified: cite them as such):\n" . $found
			: self::no_web_note();
		array_unshift( $messages, array( 'role' => 'system', 'content' => $note ) );
		return array( $messages, $extra_params );
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
	 * $budget_s > 0 caps the wait at the caller's own time budget. Protected so a suite can script the answers.
	 */
	protected function post( array $body, int $budget_s = 0 ): array {
		$timeout = self::timeout_for( $body );
		if ( $budget_s > 0 ) {
			$timeout = min( $timeout, $budget_s );
		}
		$args = array(
			'timeout' => $timeout,
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
