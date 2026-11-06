<?php
/**
 * ZDZ_LLM_Guard -- the zero-data-retention guard for every request the router sends to Anthropic.
 *
 * Zero data retention (ZDR) is an arrangement on the provider account, but it refuses nothing by itself: a request
 * that uses a feature outside ZDR is simply retained. So every request is checked here before it is sent, and anything
 * outside the ZDR-eligible set is REFUSED, never stripped and sent. A guard that strips would send a request that
 * differs from the one the caller tested.
 *
 *   Eligible: the Messages API (POST /v1/messages and nothing under it), prompt caching, images and PDF documents sent
 *   inline (base64 or text), adaptive thinking, effort, structured outputs (output_config.format, json_schema: only the
 *   JSON schema is cached, for up to 24 hours -- so a schema carries no customer data), basic web search
 *   (web_search_20250305) called directly.
 *   Never: message batches (/v1/messages/batches, long retention), the Files API (a "file" source or a file_id), code
 *   execution, the MCP connector (mcp_servers), containers, Agent Skills, web search with dynamic filtering (any other web
 *   search version, or allowed_callers other than ["direct"]), and any anthropic-beta header whatever it turns on.
 *
 * Two things a business decides are checked here too, from the router's configuration (never a literal):
 *   the model     must be one the router may use (its Model Registry slots); anything else is refused;
 *   the region    inference_geo must be exactly the configured region, and absent when none is configured.
 *
 * problems() names what is wrong in short words, never the content. check() returns null or a WP_Error.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Guard {

	const API_HOST = 'api.anthropic.com';
	const API_PATH = '/v1/messages';                 // exactly: never /v1/messages/batches, never /v1/files

	const TOP = array( 'model', 'max_tokens', 'system', 'messages', 'tools', 'tool_choice', 'thinking', 'output_config',
		'inference_geo', 'temperature', 'stop_sequences', 'metadata', 'stream' );
	const WEB_SEARCH_OK = 'web_search_20250305';     // the basic tool: no code execution behind it
	const BLOCK_TYPES   = array( 'text', 'image', 'document', 'tool_use', 'tool_result', 'thinking', 'redacted_thinking',
		'server_tool_use', 'web_search_tool_result' );
	const SOURCE_TYPES  = array( 'base64', 'text', 'url' );   // never "file" (the Files API)
	const INNER_TYPES   = array( 'text', 'image', 'document', 'search_result' );
	const SERVER_TOOLS  = array( 'web_search' );
	const OUTPUT_CONFIG = array( 'effort', 'format' );         // format: structured outputs, json_schema only
	const EFFORTS       = array( 'low', 'medium', 'high', 'max' );

	/**
	 * The models and the region a body may carry: the router's own configuration, unless a caller (a suite) passes them.
	 *
	 * @return array{models:string[],geo:string}
	 */
	public static function policy( array $given = null ) {
		if ( is_array( $given ) ) {
			return array(
				'models' => array_values( array_filter( array_map( 'strval', (array) ( $given['models'] ?? array() ) ), 'strlen' ) ),
				'geo'    => (string) ( $given['geo'] ?? '' ),
			);
		}
		$models = class_exists( 'ZDZ_Core_LLM' ) ? ZDZ_Core_LLM::approved_models() : array();
		$geo    = class_exists( 'ZDZ_Core_LLM' ) ? (string) ZDZ_Core_LLM::connection()['inference_geo'] : '';
		return array( 'models' => $models, 'geo' => $geo );
	}

	/** Header names, from a name => value map or a list of "Name: value" lines / [name, value] pairs. */
	private static function header_names( $headers ) {
		$names = array();
		if ( ! is_array( $headers ) ) {
			return $names;
		}
		foreach ( $headers as $k => $v ) {
			if ( is_string( $k ) ) {
				$names[] = $k;
			} elseif ( is_array( $v ) && $v ) {
				$names[] = (string) reset( $v );
			} else {
				$names[] = explode( ':', (string) $v, 2 )[0];
			}
		}
		return $names;
	}

	/** A JSON list (not an object). */
	private static function is_list( $v ) {
		return is_array( $v ) && ( ! $v || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
	}

	private static function blocks( $content ) {
		if ( ! is_array( $content ) ) {
			return array();
		}
		return array_values( array_filter( $content, 'is_array' ) );
	}

	private static function source_ok( array $b, array &$out ) {
		if ( array_key_exists( 'source', $b ) ) {
			$src = $b['source'];
			if ( ! is_array( $src ) || ! in_array( $src['type'] ?? null, self::SOURCE_TYPES, true ) ) {
				$out[] = 'source ' . ( is_array( $src ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $src['type'] ?? 'none' ) ) ) : 'odd' ); // file = the Files API
			}
			if ( is_array( $src ) && array_key_exists( 'file_id', $src ) ) {
				$out[] = 'file_id';
			}
		}
		if ( array_key_exists( 'file_id', $b ) ) {
			$out[] = 'file_id';
		}
	}

	/**
	 * -> sorted list of short reasons this request may not be sent (empty = fine).
	 *
	 * @param string     $url       where it would go
	 * @param array      $headers   the headers it would carry
	 * @param mixed      $body      the decoded body
	 * @param bool       $allow_web true only for the router's `web` role
	 * @param array|null $policy    [ 'models' => [...], 'geo' => '' ]; null reads the router's configuration
	 */
	public static function problems( $url, $headers, $body, $allow_web = false, array $policy = null ) {
		$pol = self::policy( $policy );
		$out = array();
		$u   = is_string( $url ) ? self::parse_url( $url ) : null;
		if ( ! $u || 'https' !== strtolower( (string) ( $u['scheme'] ?? '' ) ) || self::API_HOST !== strtolower( (string) ( $u['host'] ?? '' ) )
			|| self::API_PATH !== (string) ( $u['path'] ?? '' ) || isset( $u['query'] ) || isset( $u['port'] ) ) {
			$out[] = 'endpoint';                   // batches, files, anything but the Messages API
		}
		foreach ( self::header_names( $headers ) as $h ) {
			if ( 0 === strpos( strtolower( trim( (string) $h ) ), 'anthropic-beta' ) ) {
				$out[] = 'beta header';
			}
		}
		if ( ! is_array( $body ) || ( $body && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) ) {
			$out[] = 'body is not an object';
			sort( $out );
			return array_values( array_unique( $out ) );
		}
		foreach ( array_keys( $body ) as $k ) {
			if ( ! in_array( $k, self::TOP, true ) ) {
				$out[] = 'field ' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $k ) );   // mcp_servers, container, skills, betas ...
			}
		}
		if ( ! is_string( $body['model'] ?? null ) || '' === $body['model'] || ! in_array( $body['model'], $pol['models'], true ) ) {
			$out[] = 'model';                          // only a model the router's slots name
		}
		if ( '' === $pol['geo'] ) {
			if ( array_key_exists( 'inference_geo', $body ) ) {
				$out[] = 'inference_geo';              // a region nobody configured
			}
		} elseif ( $pol['geo'] !== ( $body['inference_geo'] ?? null ) ) {
			$out[] = 'inference_geo';                  // the configured region, exactly
		}
		if ( ! empty( $body['stream'] ) ) {
			$out[] = 'stream';                         // the router reads one answer; nothing streams
		}
		if ( array_key_exists( 'output_config', $body ) ) {
			$oc = $body['output_config'];
			if ( ! is_array( $oc ) || array_diff( array_keys( $oc ), self::OUTPUT_CONFIG ) ) {
				$out[] = 'output_config';
			} else {
				if ( isset( $oc['effort'] ) && ! in_array( $oc['effort'], self::EFFORTS, true ) ) {
					$out[] = 'effort';
				}
				if ( array_key_exists( 'format', $oc ) ) {
					$f = $oc['format'];
					if ( ! is_array( $f ) || 'json_schema' !== ( $f['type'] ?? null ) || ! isset( $f['schema'] ) || ! is_array( $f['schema'] )
						|| array_diff( array_keys( $f ), array( 'type', 'schema' ) ) ) {
						$out[] = 'output_config format';
					}
				}
			}
		}
		if ( array_key_exists( 'thinking', $body ) ) {
			$t = $body['thinking'];
			if ( ! is_array( $t ) || ! in_array( $t['type'] ?? null, array( 'adaptive', 'disabled' ), true ) ) {
				$out[] = 'thinking';
			}
		}
		$tools = $body['tools'] ?? null;
		if ( null !== $tools && ( ! is_array( $tools ) || ( $tools && array_keys( $tools ) !== range( 0, count( $tools ) - 1 ) ) ) ) {
			$out[] = 'tools is not a list';
			$tools = array();
		}
		foreach ( (array) $tools as $t ) {
			if ( ! is_array( $t ) ) {
				$out[] = 'tool is not an object';
				continue;
			}
			$typ = $t['type'] ?? null;
			if ( null === $typ || 'custom' === $typ ) {
				if ( empty( $t['name'] ) || ! array_key_exists( 'input_schema', $t ) ) {
					$out[] = 'custom tool shape';
				}
				continue;
			}
			if ( self::WEB_SEARCH_OK === $typ ) {
				if ( ! $allow_web ) {
					$out[] = 'web search is off for this call';
				} elseif ( ( $t['allowed_callers'] ?? null ) !== array( 'direct' ) ) {
					$out[] = 'web search callers';          // dynamic filtering runs code: not ZDR
				}
				continue;
			}
			$out[] = 'tool type ' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $typ ) ); // code execution, other web search, bash ...
		}
		$system = $body['system'] ?? null;
		if ( null !== $system && ! is_string( $system ) && ! self::is_list( $system ) ) {
			$out[] = 'system';
		}
		foreach ( self::blocks( $system ) as $b ) {
			if ( 'text' !== ( $b['type'] ?? null ) ) {
				$out[] = 'system block';
			}
		}
		if ( ! isset( $body['messages'] ) || ! is_array( $body['messages'] ) || ! $body['messages'] ) {
			$out[] = 'messages';
		}
		foreach ( (array) ( $body['messages'] ?? array() ) as $m ) {
			$content = is_array( $m ) ? ( $m['content'] ?? null ) : null;
			if ( ! is_string( $content ) && ! self::is_list( $content ) ) {
				$out[] = 'message content';   // an object, not a list of blocks
				continue;
			}
			foreach ( self::blocks( $content ) as $b ) {
				$typ = $b['type'] ?? null;
				if ( ! in_array( $typ, self::BLOCK_TYPES, true ) ) {
					$out[] = 'block ' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $typ ) );
					continue;
				}
				if ( 'server_tool_use' === $typ && ! in_array( $b['name'] ?? null, self::SERVER_TOOLS, true ) ) {
					$out[] = 'server tool';
				}
				if ( 'text' === $typ && ! empty( $b['citations'] ) && ! empty( $body['output_config']['format'] ) ) {
					$out[] = 'citations with a schema';
				}
				self::source_ok( $b, $out );
				if ( 'tool_result' === $typ ) {
					$inner = $b['content'] ?? null;
					if ( null !== $inner && ! is_string( $inner ) && ! self::is_list( $inner ) ) {
						$out[] = 'tool_result content';
					}
					foreach ( self::blocks( is_array( $inner ) ? $inner : null ) as $ib ) {
						if ( ! in_array( $ib['type'] ?? null, self::INNER_TYPES, true ) ) {
							$out[] = 'inner block';
						}
						self::source_ok( $ib, $out );
					}
				}
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/** wp_parse_url() when WordPress is loaded, parse_url() otherwise (the suites). false on a URL that does not parse. */
	private static function parse_url( $url ) {
		$u = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return is_array( $u ) ? $u : false;
	}

	/** null when the request may be sent; otherwise a WP_Error naming what is wrong (never the content). */
	public static function check( $url, $headers, $body, $allow_web = false, array $policy = null ) {
		$p = self::problems( $url, $headers, $body, $allow_web, $policy );
		if ( ! $p ) {
			return null;
		}
		return new WP_Error(
			'zdz_llm_guard',
			sprintf( __( 'Refused before sending: outside zero data retention (%s).', 'zorderz' ), implode( '; ', $p ) ),
			array( 'status' => 0, 'kind' => 'guard', 'billed' => false )
		);
	}
}
