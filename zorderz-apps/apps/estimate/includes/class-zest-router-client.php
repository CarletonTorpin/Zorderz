<?php
/**
 * ZEST_Router_Client - Estimates' Ai calls through the platform's one Ai router (ZDZ_Core_LLM), with a switch back to
 * the chat gateway's road (ZEST_Poe_Client).
 *
 * It speaks the same provider interface as the gateway client (ZEST_AI_Provider: complete() and parse_json()), so the
 * engine's callers are unchanged. What the router adds is its own: the business's models (Model Registry slots, never a
 * name here), its budgets and caps, and its privacy rule (customer details coded before anything leaves the site, gate
 * codes cut; text comes back coded and is decoded here for the person asking, through ZDZ_Private::for( 'llm' )).
 *
 *   the road   option zest_ai_route: 'router' (the default) or 'gateway'. The router is used only when its class is
 *              there and a primary model is set (decided per call); otherwise, and when an admin chose 'gateway', the
 *              gateway client is used as before. A router failure is said as one, never retried on the gateway.
 *   roles      the engine's roles map to the router's: parse and fallback -> extract, classify -> classify, a picture
 *              (vision, transcribe) -> read_document.
 *   pictures   only this site's own uploads, read from disk (never a fetch of an outside address); a picture that can't
 *              be read is an error, never a silent text-only call.
 *   time       one call waits at most 52 seconds (deadline), inside a browser request's limit.
 *
 * The caller row 'estimate-creator' is in ZDZ_Core_LLM::CALLERS (it may send pictures on read_document).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Router_Client implements ZEST_AI_Provider {

	const ROUTE_OPT = 'zest_ai_route';
	const CALLER    = 'estimate-creator';
	const WAIT_S    = 52;

	/** @var ZEST_Poe_Client the gateway road */
	private $gateway;

	public function __construct( $gateway = null ) {
		$this->gateway = $gateway instanceof ZEST_AI_Provider ? $gateway : new ZEST_Poe_Client();
	}

	/** The provider the engine uses. It decides per call, so a theme loaded after this app is still seen. */
	public static function provider(): ZEST_AI_Provider {
		return new self();
	}

	/** Is the router the road now? The option allows it, its class is there, and a primary model is set. */
	public function use_router(): bool {
		$route = function_exists( 'get_option' ) ? (string) get_option( self::ROUTE_OPT, 'router' ) : 'router';
		if ( 'gateway' === $route || ! class_exists( 'ZDZ_Core_LLM' ) || ! method_exists( 'ZDZ_Core_LLM', 'ask' ) || ! class_exists( 'ZDZ_Private' ) ) {
			return false;
		}
		$m = method_exists( 'ZDZ_Core_LLM', 'models' ) ? (array) ZDZ_Core_LLM::models() : array();
		return '' !== (string) ( $m['primary'] ?? '' );
	}

	public function is_configured(): bool {
		return $this->use_router() || $this->gateway->is_configured();
	}

	public static function role( string $engine_role, bool $pictures ): string {
		if ( $pictures ) {
			return 'read_document';
		}
		return 'classify' === $engine_role ? 'classify' : 'extract';
	}

	/** A picture from this site's uploads as { media_type, data } (base64), or null. */
	public static function picture( string $url ): ?array {
		if ( ! function_exists( 'attachment_url_to_postid' ) || ! function_exists( 'get_attached_file' ) ) {
			return null;
		}
		$id   = (int) attachment_url_to_postid( $url );
		$path = $id > 0 ? (string) get_attached_file( $id ) : '';
		if ( '' === $path || ! is_readable( $path ) || filesize( $path ) > 8 * 1024 * 1024 ) {
			return null;
		}
		$mt = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $path ) : '';
		if ( ! in_array( $mt, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local upload, read once
		return false === $raw ? null : array( 'media_type' => $mt, 'data' => base64_encode( $raw ) );
	}

	/** The engine's messages as the router's request: { system, messages }; pictures on the last user message. */
	public static function request( array $messages, array $images ): ?array {
		$system = '';
		$out    = array();
		foreach ( $messages as $m ) {
			$role    = (string) ( $m['role'] ?? 'user' );
			$content = $m['content'] ?? '';
			if ( 'system' === $role ) {
				$system .= ( '' !== $system ? "\n\n" : '' ) . ( is_string( $content ) ? $content : '' );
				continue;
			}
			$out[] = array( 'role' => 'assistant' === $role ? 'assistant' : 'user', 'content' => is_string( $content ) ? $content : '' );
		}
		if ( $images ) {
			$parts = array();
			foreach ( $images as $url ) {
				$p = self::picture( (string) $url );
				if ( null === $p ) {
					return null;
				}
				$parts[] = array( 'type' => 'image' ) + $p;
			}
			for ( $i = count( $out ) - 1; $i >= 0; $i-- ) {
				if ( 'user' === $out[ $i ]['role'] ) {
					$out[ $i ]['content'] = array_merge( array( array( 'type' => 'text', 'text' => (string) $out[ $i ]['content'] ) ), $parts );
					break;
				}
			}
		}
		return array( 'system' => $system, 'messages' => $out );
	}

	public function complete( array $messages, array $opts = array() ): array {
		if ( ! $this->use_router() ) {
			return $this->gateway->complete( $messages, $opts );
		}
		$out    = array( 'ok' => false, 'text' => '', 'error' => '', 'raw' => array() );
		$images = ! empty( $opts['images'] ) && is_array( $opts['images'] ) ? array_values( $opts['images'] ) : array();
		$req    = self::request( $messages, $images );
		if ( null === $req ) {
			$out['error'] = __( 'A photo could not be read for the Ai. Try the photo again, or ask an admin to switch the Ai road.', 'zorderz' );
			return $out;
		}
		try {
			$ans = ZDZ_Core_LLM::ask( self::role( (string) ( $opts['role'] ?? 'parse' ), (bool) $images ), $req, array(
				'caller'   => self::CALLER,
				'user_id'  => (int) get_current_user_id(),
				'deadline' => time() + self::WAIT_S,
			) );
		} catch ( \Throwable $e ) {
			$out['error'] = __( 'The Ai isn\'t reachable just now.', 'zorderz' );
			return $out;
		}
		if ( is_wp_error( $ans ) ) {
			$out['error'] = (string) $ans->get_error_message();
			return $out;
		}
		if ( ! is_array( $ans ) || ! is_string( $ans['text'] ?? null ) ) {
			$out['error'] = __( 'The Ai sent no answer.', 'zorderz' );
			return $out;
		}
		$show        = ! ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( (int) get_current_user_id() ) );
		$out['ok']   = true;
		$out['text'] = (string) ZDZ_Private::for( 'llm' )->decode( (string) $ans['text'], $show );
		$out['raw']  = array( 'model' => (string) ( $ans['model'] ?? '' ), 'usd' => (float) ( $ans['usd'] ?? 0 ) );
		return $out;
	}

	public function parse_json( string $response ): ?array {
		if ( class_exists( 'ZDZ_Core_LLM' ) && method_exists( 'ZDZ_Core_LLM', 'parse_json' ) ) {
			$j = ZDZ_Core_LLM::parse_json( $response );
			return is_array( $j ) ? $j : null;
		}
		$j = json_decode( $response, true );
		return is_array( $j ) ? $j : null;
	}
}
