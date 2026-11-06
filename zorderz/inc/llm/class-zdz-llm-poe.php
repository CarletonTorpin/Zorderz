<?php
/**
 * ZDZ_LLM_Poe -- a thin adapter over the platform's Poe gateway (ZDZ_Core_Poe::request()).
 *
 * So a role can be pointed at Poe by an admin's choice (never automatically: when the native provider is down the
 * router says so plainly rather than hopping). Nothing about the gateway is rewritten here: the messages go to
 * request() as OpenAI-shaped messages and its answer comes back as it is. Poe has no zero-retention contract, so the
 * admin view says plainly that a role pointed here sends its (coded) data to Poe. Poe reports no tokens: its calls are
 * ledgered at $0 with their outcome and time.
 *
 * The gateway is asked for exactly the model the router chose (`_no_failover`: its own fallback would answer from a
 * model nobody picked for this role) and to stop by the call's deadline (`_budget_s`, never under 15 seconds).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Poe implements ZDZ_LLM_Provider {

	/** The gateway never waits less than this; the router does not try Poe with less time left. */
	const MIN_BUDGET_S = 15;

	private $client;
	private $clock;

	/** @param object|null $client a gateway with request() (tests pass one; default a new ZDZ_Core_Poe with the site's Poe key) */
	public function __construct( $client = null, $clock = null ) {
		$this->client = $client;
		$this->clock  = $clock;
	}

	public function name() {
		return 'poe';
	}

	public function capabilities() {
		return array( 'images' => true, 'pdf' => 'text', 'cache' => false, 'json_schema' => 'ours', 'effort' => false, 'web' => false, 'zdr' => false );
	}

	public function prices( $model ) {
		return null;
	}

	private function now() {
		return $this->clock ? (float) call_user_func( $this->clock ) : microtime( true );
	}

	/** Neutral request -> the OpenAI-shaped messages the gateway takes. */
	public static function messages( array $n ) {
		$msgs = array();
		$sys  = (string) ( $n['system'] ?? '' );
		if ( ! empty( $n['json_schema'] ) ) {
			$sys .= ( '' === $sys ? '' : "\n\n" ) . 'Answer with one JSON value only, no other words, matching this JSON schema: ' . wp_json_encode( $n['json_schema'] );
		}
		if ( '' !== $sys ) {
			$msgs[] = array( 'role' => 'system', 'content' => $sys );
		}
		foreach ( (array) ( $n['messages'] ?? array() ) as $m ) {
			$parts = array();
			$only  = true;
			foreach ( (array) ( $m['content'] ?? array() ) as $p ) {
				$t = $p['type'] ?? '';
				if ( 'text' === $t ) {
					$parts[] = array( 'type' => 'text', 'text' => (string) $p['text'] );
				} elseif ( 'image' === $t ) {
					$parts[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $p['media_type'] . ';base64,' . $p['data'] ) );
					$only    = false;
				} elseif ( 'pdf' === $t && '' !== trim( (string) ( $p['text'] ?? '' ) ) ) {
					$parts[] = array( 'type' => 'text', 'text' => "[The document's text]\n" . (string) $p['text'] );
				}
			}
			$role   = 'assistant' === ( $m['role'] ?? '' ) ? 'assistant' : 'user';
			$msgs[] = array( 'role' => $role, 'content' => $only ? implode( "\n\n", array_column( $parts, 'text' ) ) : $parts );
		}
		return $msgs;
	}

	public function send( array $neutral, $deadline ) {
		$client = $this->client;
		if ( ! $client ) {
			if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
				return ZDZ_LLM_Wire::error( 'other' );
			}
			$client = new ZDZ_Core_Poe();
		}
		if ( ! method_exists( $client, 'request' ) ) {
			return ZDZ_LLM_Wire::error( 'other' );
		}
		$model  = (string) ( $neutral['model'] ?? '' );
		$budget = (int) max( self::MIN_BUDGET_S, floor( ZDZ_LLM_Wire::seconds_left( $deadline, $this->now() ) ) );
		$r      = $client->request( self::messages( $neutral ), 0.0, array( '_budget_s' => $budget, '_no_failover' => true ), $model );
		if ( is_wp_error( $r ) ) {
			// The gateway's own words never reach a page; its HTTP status gives the kind.
			$d  = $r->get_error_data();
			$st = is_array( $d ) ? (int) ( $d['status'] ?? 0 ) : ( is_numeric( $d ) ? (int) $d : 0 );
			return new WP_Error( 'zdz_llm_poe', __( 'Poe could not answer.', 'zorderz' ), array( 'status' => $st, 'kind' => ZDZ_LLM_Wire::classify( $st ), 'billed' => false ) );
		}
		$text = is_array( $r ) ? (string) ( $r['text'] ?? '' ) : (string) $r;
		$json = null;
		if ( ! empty( $neutral['json_schema'] ) && method_exists( $client, 'parse_llm_json' ) ) {
			$json = $client->parse_llm_json( $text );
		}
		return array(
			'text'      => $text,
			'json'      => $json,
			'citations' => array(),
			'usage'     => ZDZ_LLM_Wire::zero_usage(),
			'model'     => is_array( $r ) && isset( $r['model'] ) ? (string) $r['model'] : $model,
			'http'      => is_array( $r ) ? (int) ( $r['status'] ?? 200 ) : 200,
			'stop'      => '',
			'usd'       => 0.0,
		);
	}
}
