<?php
/**
 * ZDZ_LLM_Anthropic -- the native Messages API adapter.
 *
 *   POST https://api.anthropic.com/v1/messages with the key from the credentials layer
 *   (ZDZ_Core_Settings::get_credential( 'anthropic_api_key' ): never logged, never printed, never in an answer),
 *   anthropic-version 2023-06-01, and NO anthropic-beta header, ever.
 *   Every body: inference_geo when (and only when) a region is configured, adaptive thinking (its blocks are never
 *   returned as text), output_config.effort, output_config.format when a schema is given (structured outputs), images
 *   and PDF documents as inline base64, cache marks: 1 hour after the system text and on a caller's data block, 5 minutes
 *   on the latest block for a conversation, longest first, at most 4. Web search only as the basic tool, called directly.
 *   Every body passes ZDZ_LLM_Guard before it is sent; a refusal is never "fixed" here.
 *
 * THE WORKSPACE. A key belongs to one workspace on the provider account, and zero data retention is arranged per
 * account. The business names the workspace its key must come from (the credentials row anthropic_workspace_id, or
 * the filter zdz_anthropic_workspace_id). Every answer must carry that anthropic-workspace-id header or it is not used;
 * a missing header is refused too. With no workspace configured nothing is sent at all: an answer from an unknown
 * workspace could not be told from the right one, and refusing after the call would still have sent the data.
 *
 * Prices come from the router's configuration (ZDZ_Core_LLM::prices_for()), never from a table in this file.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Anthropic implements ZDZ_LLM_Provider {

	const URL       = 'https://api.anthropic.com/v1/messages';
	const VERSION   = '2023-06-01';
	const MAX_MARKS = 4;
	const WEB_USES  = 3;                                // searches one web call may make (priced by the router)

	private $key_fn;
	private $clock;
	private $ws_fn;

	/**
	 * @param callable|null $key_fn returns the key (default: the credentials row anthropic_api_key); read per call, kept nowhere
	 * @param callable|null $clock  returns unix seconds (tests swap it)
	 * @param callable|null $ws_fn  returns the workspace id (tests swap it; default workspace())
	 */
	public function __construct( $key_fn = null, $clock = null, $ws_fn = null ) {
		$this->key_fn = $key_fn;
		$this->clock  = $clock;
		$this->ws_fn  = $ws_fn;
	}

	public function name() {
		return 'anthropic';
	}

	public function capabilities() {
		return array( 'images' => true, 'pdf' => 'native', 'cache' => true, 'json_schema' => 'native', 'effort' => true, 'web' => true, 'zdr' => true );
	}

	public function prices( $model ) {
		return class_exists( 'ZDZ_Core_LLM' ) ? ZDZ_Core_LLM::prices_for( (string) $model ) : null;
	}

	private function now() {
		return $this->clock ? (float) call_user_func( $this->clock ) : microtime( true );
	}

	private function key() {
		if ( $this->key_fn ) {
			return (string) call_user_func( $this->key_fn );
		}
		return ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) )
			? (string) ZDZ_Core_Settings::get_credential( 'anthropic_api_key' )
			: '';
	}

	/** The workspace an answer must come from ('' when none is configured: then nothing is sent). */
	public static function workspace() {
		$ws = ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) )
			? (string) ZDZ_Core_Settings::get_credential( 'anthropic_workspace_id' )
			: '';
		$ws = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zdz_anthropic_workspace_id', $ws ) : $ws;
		return trim( $ws );
	}

	private function want_workspace() {
		return $this->ws_fn ? trim( (string) call_user_func( $this->ws_fn ) ) : self::workspace();
	}

	/** The headers of every request. No anthropic-beta, ever. */
	public static function headers( $key ) {
		return array(
			'x-api-key'         => $key,
			'anthropic-version' => self::VERSION,
			'content-type'      => 'application/json',
		);
	}

	/* ------------------------------------------------------------------ the body (pure; the suites test it without a network) */

	private static function part( array $p ) {
		switch ( $p['type'] ?? '' ) {
			case 'text':
				return array( 'type' => 'text', 'text' => (string) $p['text'] );
			case 'image':
				return array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => (string) $p['media_type'], 'data' => (string) $p['data'] ) );
			case 'pdf':
				return array( 'type' => 'document', 'source' => array( 'type' => 'base64', 'media_type' => 'application/pdf', 'data' => (string) $p['data'] ) );
		}
		return array( 'type' => 'unknown' ); // the guard refuses it
	}

	/**
	 * Neutral request -> Messages API body.
	 *
	 * @param array  $n   the neutral request
	 * @param string $geo the configured region ('' = none: the body carries no inference_geo); null reads the router's
	 */
	public static function body( array $n, $geo = null ) {
		$geo    = null === $geo ? ( class_exists( 'ZDZ_Core_LLM' ) ? (string) ZDZ_Core_LLM::connection()['inference_geo'] : '' ) : (string) $geo;
		$marks  = 0;
		$system = array();
		if ( '' !== (string) ( $n['system_prefix'] ?? '' ) ) {
			// The fixed part first, marked for an hour; what follows it is sent unmarked, so the part that changes per
			// person or per turn does not have to match for the cache to be read.
			$system[] = array( 'type' => 'text', 'text' => (string) $n['system_prefix'], 'cache_control' => array( 'type' => 'ephemeral', 'ttl' => '1h' ) );
			$marks++;
			if ( '' !== (string) ( $n['system_tier'] ?? '' ) ) {
				// The second block, the same for everyone at one permission level, also an hour.
				$system[] = array( 'type' => 'text', 'text' => (string) $n['system_tier'], 'cache_control' => array( 'type' => 'ephemeral', 'ttl' => '1h' ) );
				$marks++;
			}
			if ( '' !== (string) ( $n['system'] ?? '' ) ) {
				$system[] = array( 'type' => 'text', 'text' => (string) $n['system'] );
			}
		} elseif ( '' !== (string) ( $n['system'] ?? '' ) ) {
			$system[] = array( 'type' => 'text', 'text' => (string) $n['system'], 'cache_control' => array( 'type' => 'ephemeral', 'ttl' => '1h' ) );
			$marks++;
		}
		$messages = array();
		foreach ( (array) ( $n['messages'] ?? array() ) as $m ) {
			$content = array();
			foreach ( (array) ( $m['content'] ?? array() ) as $p ) {
				$b = self::part( (array) $p );
				// a caller's long-lived data block: 1 hour, while marks are left for the latest block
				if ( '1h' === ( $p['cache'] ?? null ) && $marks < self::MAX_MARKS - 1 ) {
					$b['cache_control'] = array( 'type' => 'ephemeral', 'ttl' => '1h' );
					$marks++;
				}
				$content[] = $b;
			}
			$messages[] = array( 'role' => 'assistant' === ( $m['role'] ?? '' ) ? 'assistant' : 'user', 'content' => $content );
		}
		if ( ! empty( $n['cache_latest'] ) && $messages && $marks < self::MAX_MARKS ) {
			$li = count( $messages ) - 1;
			$bi = count( $messages[ $li ]['content'] ) - 1;
			if ( $bi >= 0 && ! isset( $messages[ $li ]['content'][ $bi ]['cache_control'] ) ) {
				$messages[ $li ]['content'][ $bi ]['cache_control'] = array( 'type' => 'ephemeral' ); // 5 minutes, after every 1-hour mark
			}
		}
		$body = array(
			'model'         => (string) $n['model'],
			'max_tokens'    => max( 1, (int) ( $n['max_output'] ?? 4000 ) ),
			'messages'      => $messages,
			'thinking'      => array( 'type' => 'adaptive' ),
			'output_config' => array( 'effort' => in_array( $n['effort'] ?? '', ZDZ_LLM_Guard::EFFORTS, true ) ? $n['effort'] : 'medium' ),
		);
		if ( '' !== $geo ) {
			$body['inference_geo'] = $geo;
		}
		if ( $system ) {
			$body['system'] = $system;
		}
		if ( ! empty( $n['json_schema'] ) && is_array( $n['json_schema'] ) ) {
			$body['output_config']['format'] = array( 'type' => 'json_schema', 'schema' => $n['json_schema'] );
		}
		if ( ! empty( $n['web'] ) ) {
			$body['tools'] = array( array( 'type' => ZDZ_LLM_Guard::WEB_SEARCH_OK, 'name' => 'web_search', 'max_uses' => self::WEB_USES, 'allowed_callers' => array( 'direct' ) ) );
		}
		return $body;
	}

	/* ------------------------------------------------------------------ the answer */

	public static function usage( $u ) {
		$u   = is_array( $u ) ? $u : array();
		$w1h = (int) ( $u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0 );
		$all = (int) ( $u['cache_creation_input_tokens'] ?? 0 );
		return array(
			'in'             => (int) ( $u['input_tokens'] ?? 0 ),
			'cache_write_5m' => max( 0, $all - $w1h ),
			'cache_write_1h' => $w1h,
			'cache_read'     => (int) ( $u['cache_read_input_tokens'] ?? 0 ),
			'out'            => (int) ( $u['output_tokens'] ?? 0 ),
			'searches'       => (int) ( $u['server_tool_use']['web_search_requests'] ?? 0 ),
		);
	}

	/**
	 * The readable answer: text blocks joined as they come (a cited answer arrives split at each citation), text after a
	 * search or a tool call separated by a blank line. Thinking blocks are never text. The cited pages, each once.
	 */
	public static function answer( array $content ) {
		$parts = array();
		$run   = '';
		$cites = array();
		foreach ( $content as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( 'text' === ( $b['type'] ?? '' ) ) {
				$run .= (string) ( $b['text'] ?? '' );
				foreach ( (array) ( $b['citations'] ?? array() ) as $c ) {
					$url = is_array( $c ) ? (string) ( $c['url'] ?? '' ) : '';
					if ( '' !== $url && ! isset( $cites[ $url ] ) && preg_match( '#^https?://#i', $url ) ) {
						$cites[ $url ] = array( 'url' => $url, 'title' => (string) ( $c['title'] ?? $url ) );
					}
				}
			} elseif ( '' !== $run ) {
				$parts[] = trim( $run );
				$run     = '';
			}
		}
		if ( '' !== $run ) {
			$parts[] = trim( $run );
		}
		return array( implode( "\n\n", array_filter( $parts, 'strlen' ) ), array_values( $cites ) );
	}

	/* ------------------------------------------------------------------ one try */

	public function send( array $neutral, $deadline ) {
		$key = $this->key();
		if ( '' === $key ) {
			return new WP_Error( 'zdz_llm_no_key', __( 'The Ai is not set up yet: an admin enters the Anthropic key in the Zorderz credentials.', 'zorderz' ), array( 'status' => 0, 'kind' => 'auth', 'billed' => false ) );
		}
		$want_ws = $this->want_workspace();
		if ( '' === $want_ws ) {
			unset( $key );
			return new WP_Error( 'zdz_llm_no_workspace', __( 'The Ai is not set up yet: an admin enters the workspace ID the Anthropic key belongs to in the Zorderz credentials.', 'zorderz' ), array( 'status' => 0, 'kind' => 'auth', 'billed' => false ) );
		}
		$body    = self::body( $neutral );
		$headers = self::headers( $key );
		$refused = ZDZ_LLM_Guard::check( self::URL, $headers, $body, ! empty( $neutral['web'] ) );
		if ( $refused ) {
			return $refused;
		}
		$left = (int) floor( ZDZ_LLM_Wire::seconds_left( $deadline, $this->now() ) );
		if ( $left < 1 ) {
			return ZDZ_LLM_Wire::error( 'network', 0, false );
		}
		$r = wp_remote_post( self::URL, array(
			'timeout'     => $left,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
		) );
		unset( $key, $headers );
		if ( is_wp_error( $r ) ) {
			return ZDZ_LLM_Wire::transport_error( $r );
		}
		$status = (int) wp_remote_retrieve_response_code( $r );
		$data   = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $status ) {
			$type  = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
			$retry = (float) wp_remote_retrieve_header( $r, 'retry-after' );
			return ZDZ_LLM_Wire::error( ZDZ_LLM_Wire::classify( $status, $type ), $status, false, null, $retry );
		}
		if ( ! is_array( $data ) || 'message' !== ( $data['type'] ?? 'message' ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return ZDZ_LLM_Wire::error( 'network', $status, true ); // a 200 cut off mid-body: it ran
		}
		$usage = self::usage( $data['usage'] ?? null );
		$ws    = trim( (string) wp_remote_retrieve_header( $r, 'anthropic-workspace-id' ) );
		if ( '' === $ws || ! hash_equals( $want_ws, $ws ) ) {
			return ZDZ_LLM_Wire::error( 'wrong_workspace', $status, true, $usage );
		}
		$stop = (string) ( $data['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			return ZDZ_LLM_Wire::error( 'refused', $status, true, $usage );
		}
		list( $text, $cites ) = self::answer( $data['content'] );
		$json = null;
		if ( ! empty( $neutral['json_schema'] ) ) {
			$j    = json_decode( $text, true );
			$json = is_array( $j ) ? $j : null;
		}
		return array(
			'text'      => $text,
			'json'      => $json,
			'citations' => $cites,
			'usage'     => $usage,
			'model'     => (string) ( $data['model'] ?? $neutral['model'] ),
			'http'      => $status,
			'stop'      => $stop,
			'usd'       => null,
		);
	}
}
