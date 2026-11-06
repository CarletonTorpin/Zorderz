<?php
/**
 * ZDZ_LLM_OpenAI_Compatible -- POST <base>/v1/chat/completions for any provider entry.
 *
 * The road for every other provider: a hosted OpenAI-style API, a local model server, a business's own gateway.
 * Anthropic is never reached this way (its compatibility layer drops PDFs and response_format, has no caching, and is
 * not covered by zero data retention).
 *
 * An ENTRY (option zdz_llm_openai_entries; a connections pack sets it, there is no editing screen) is:
 *   base       https://… (no path after it; /v1/chat/completions is added). A base on a private address (a local
 *              model server) is called directly; any other through wp_safe_remote_request() (ZDZ_Outbound).
 *   model      the provider's model name
 *   key_name   the name of its key in the credentials layer (one credential authority), never the key itself
 *   vision     bool: may carry images
 *   reasoning  '' or the name of the provider's reasoning setting (e.g. 'reasoning_effort'), which gets the effort
 *   search     false: there is no web search on this road, so `web` never runs here
 *   terms      the provider's retention terms, as text an admin has checked; terms_checked_by / terms_checked_at
 *   prices     [ in, out ] USD per million tokens
 * An entry without checked terms cannot carry a role that sends business data, and an entry without prices is never
 * called (its cost could not be capped). The router enforces both.
 *
 * The 1:1 rules: images as image_url data URLs; a PDF as the caller's extracted text (no text, no send); JSON is
 * checked by the router only (no response_format: providers differ); no cache marks (a stable prefix first); effort
 * only through a declared reasoning setting.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_OpenAI_Compatible implements ZDZ_LLM_Provider {

	private $id;
	private $entry;
	private $key_fn;
	private $clock;

	public function __construct( $id, array $entry, $key_fn = null, $clock = null ) {
		$this->id     = (string) $id;
		$this->entry  = $entry + array( 'base' => '', 'model' => '', 'key_name' => '', 'vision' => false, 'reasoning' => '', 'search' => false, 'terms' => '', 'terms_checked_by' => 0, 'terms_checked_at' => 0, 'prices' => null );
		$this->key_fn = $key_fn;
		$this->clock  = $clock;
	}

	public function name() {
		return 'openai';
	}

	public function entry_id() {
		return $this->id;
	}

	public function entry() {
		return $this->entry;
	}

	/** Terms an admin has checked: text, who, when. */
	public function terms_checked() {
		return '' !== trim( (string) $this->entry['terms'] ) && (int) $this->entry['terms_checked_by'] > 0 && (int) $this->entry['terms_checked_at'] > 0;
	}

	public function capabilities() {
		return array( 'images' => (bool) $this->entry['vision'], 'pdf' => 'text', 'cache' => false, 'json_schema' => 'ours', 'effort' => '' !== (string) $this->entry['reasoning'], 'web' => false, 'zdr' => false );
	}

	public function prices( $model ) {
		$p = $this->entry['prices'];
		if ( is_array( $p ) && 2 === count( $p ) && is_numeric( reset( $p ) ) && is_numeric( end( $p ) ) ) {
			$p  = array_values( $p );
			$in = max( 0.0, (float) $p[0] );
			return array( $in, $in, $in, $in, max( 0.0, (float) $p[1] ) );
		}
		return null;
	}

	private function now() {
		return $this->clock ? (float) call_user_func( $this->clock ) : microtime( true );
	}

	public function url() {
		return rtrim( (string) $this->entry['base'], '/' ) . '/v1/chat/completions';
	}

	private function key() {
		if ( $this->key_fn ) {
			return (string) call_user_func( $this->key_fn, (string) $this->entry['key_name'] );
		}
		$name = (string) $this->entry['key_name'];
		return ( '' !== $name && class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) ) ? (string) ZDZ_Core_Settings::get_credential( $name ) : '';
	}

	/** Neutral request -> chat/completions body, or a WP_Error when it cannot be carried 1:1. */
	public function body( array $n ) {
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
					if ( empty( $this->entry['vision'] ) ) {
						return new WP_Error( 'zdz_llm_no_vision', __( 'This provider entry cannot read pictures.', 'zorderz' ), array( 'status' => 0, 'kind' => 'bad_request', 'billed' => false ) );
					}
					$parts[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $p['media_type'] . ';base64,' . $p['data'] ) );
					$only    = false;
				} elseif ( 'pdf' === $t ) {
					if ( '' === trim( (string) ( $p['text'] ?? '' ) ) ) {
						return new WP_Error( 'zdz_llm_pdf_needs_text', __( 'This provider entry reads a PDF only as its extracted text, and none was given.', 'zorderz' ), array( 'status' => 0, 'kind' => 'bad_request', 'billed' => false ) );
					}
					$parts[] = array( 'type' => 'text', 'text' => "[The document's text]\n" . (string) $p['text'] );
				}
			}
			$role = 'assistant' === ( $m['role'] ?? '' ) ? 'assistant' : 'user';
			if ( $only ) {
				$msgs[] = array( 'role' => $role, 'content' => implode( "\n\n", array_column( $parts, 'text' ) ) );
			} else {
				$msgs[] = array( 'role' => $role, 'content' => $parts );
			}
		}
		$body = array(
			'model'      => (string) $this->entry['model'],
			'messages'   => $msgs,
			'max_tokens' => max( 1, (int) ( $n['max_output'] ?? 4000 ) ),
			'stream'     => false,
		);
		$knob = preg_replace( '/[^a-z_]/', '', (string) $this->entry['reasoning'] );
		if ( '' !== $knob ) {
			$body[ $knob ] = in_array( $n['effort'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $n['effort'] : 'medium';
		}
		return $body;
	}

	public function send( array $neutral, $deadline ) {
		if ( ! empty( $neutral['web'] ) ) {
			return new WP_Error( 'zdz_llm_no_web', __( 'This provider entry has no web search.', 'zorderz' ), array( 'status' => 0, 'kind' => 'bad_request', 'billed' => false ) );
		}
		$u = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $this->entry['base'] ) : parse_url( (string) $this->entry['base'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $u ) || 'https' !== strtolower( (string) ( $u['scheme'] ?? '' ) ) || '' === (string) ( $u['host'] ?? '' ) ) {
			return new WP_Error( 'zdz_llm_entry', __( 'This provider entry has no https address.', 'zorderz' ), array( 'status' => 0, 'kind' => 'bad_request', 'billed' => false ) );
		}
		$body = $this->body( $neutral );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$key = $this->key();
		if ( '' === $key ) {
			return new WP_Error( 'zdz_llm_no_key', __( 'This provider entry has no key in the Zorderz credentials.', 'zorderz' ), array( 'status' => 0, 'kind' => 'auth', 'billed' => false ) );
		}
		$left = (int) floor( ZDZ_LLM_Wire::seconds_left( $deadline, $this->now() ) );
		if ( $left < 1 ) {
			return ZDZ_LLM_Wire::error( 'network', 0, false );
		}
		// A local model server on the business's own network is allowed by design; a public host goes through the safe
		// call (ZDZ_Outbound). Never a redirect: the key would follow it.
		$args = array(
			'timeout'     => $left,
			'redirection' => 0,
			'headers'     => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
		);
		$r    = class_exists( 'ZDZ_Outbound' ) ? ZDZ_Outbound::send( 'POST', $this->url(), $args, true, 0 ) : wp_remote_post( $this->url(), $args );
		unset( $args );
		unset( $key );
		if ( is_wp_error( $r ) ) {
			return ZDZ_LLM_Wire::transport_error( $r );
		}
		$status = (int) wp_remote_retrieve_response_code( $r );
		$data   = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $status ) {
			$type = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
			return ZDZ_LLM_Wire::error( ZDZ_LLM_Wire::classify( $status, $type ), $status, false );
		}
		$msg = is_array( $data ) ? ( $data['choices'][0]['message'] ?? null ) : null;
		if ( ! is_array( $msg ) ) {
			return ZDZ_LLM_Wire::error( 'network', $status, true );
		}
		$text = $msg['content'] ?? '';
		if ( is_array( $text ) ) { // some providers answer in parts
			$t = '';
			foreach ( $text as $p ) {
				if ( is_array( $p ) && 'text' === ( $p['type'] ?? '' ) ) {
					$t .= (string) ( $p['text'] ?? '' );
				}
			}
			$text = $t;
		}
		$u      = (array) ( $data['usage'] ?? array() );
		$cached = (int) ( $u['prompt_tokens_details']['cached_tokens'] ?? 0 );
		$usage  = ZDZ_LLM_Wire::zero_usage();
		$usage['in']         = max( 0, (int) ( $u['prompt_tokens'] ?? 0 ) - $cached );
		$usage['cache_read'] = $cached;
		$usage['out']        = (int) ( $u['completion_tokens'] ?? 0 );
		$json = null;
		if ( ! empty( $neutral['json_schema'] ) ) {
			$j    = json_decode( trim( (string) $text ), true );
			$json = is_array( $j ) ? $j : null;
		}
		return array(
			'text'      => trim( (string) $text ),
			'json'      => $json,
			'citations' => array(),
			'usage'     => $usage,
			'model'     => (string) $this->entry['model'],
			'http'      => $status,
			'stop'      => (string) ( $data['choices'][0]['finish_reason'] ?? '' ),
			'usd'       => null,
		);
	}
}
