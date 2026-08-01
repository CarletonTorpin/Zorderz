<?php
/**
 * ZEST_Poe_Client — the estimate module's AI provider, wrapping the theme's shared
 * ZDZ_Core_Poe connection (one registered gateway, not a private key).
 *
 * The old client hardcoded model names at every call site (a parse model, a classify
 * model, a fallback pair) and shipped model-forcing migrations. This client hardcodes
 * NONE: every call asks for a job ROLE ('parse' | 'classify' | 'fallback' | 'transcribe')
 * and resolves it through zest_ai_model() (the `zdz_ai_model_role` filter) with a safe
 * empty default, so an unset role falls through to the theme client's own default model.
 * The central model registry is owned by the analytics module; this binds to it by filter.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Poe_Client implements ZEST_AI_Provider {

	private $core     = null;
	private $injected = null;

	/**
	 * @param ZDZ_Core_Poe|null $core Optional pre-built core client (tests). Otherwise the
	 *   theme connection is resolved LAZILY on first use — this object is constructed at
	 *   plugins_loaded, before the theme's functions.php defines ZDZ_Core_Poe.
	 */
	public function __construct( $core = null ) {
		$this->injected = $core;
	}

	/** Resolve the theme's shared Poe connection on demand (never in the constructor). */
	private function core() {
		if ( null !== $this->core ) {
			return $this->core;
		}
		if ( $this->injected instanceof ZDZ_Core_Poe ) {
			$this->core = $this->injected;
		} elseif ( class_exists( 'ZDZ_Core_Poe' ) ) {
			$this->core = new ZDZ_Core_Poe();
		} else {
			$this->core = false; // resolved-but-absent
		}
		return $this->core;
	}

	public function is_configured(): bool {
		if ( ! $this->core() ) {
			return false;
		}
		$key = class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_poe_api_key() : '';
		return '' !== (string) $key;
	}

	/**
	 * Complete a request. Resolves the model from opts['role'] via the platform filter;
	 * an empty result defers to the theme client default. Supports vision by attaching
	 * image urls as message parts. Every failure returns a typed error — never a
	 * silently-empty success.
	 */
	public function complete( array $messages, array $opts = array() ): array {
		$out = array( 'ok' => false, 'text' => '', 'error' => '', 'raw' => array() );
		$core = $this->core();
		if ( ! $core ) {
			$out['error'] = 'AI gateway not available (theme Poe connection missing).';
			return $out;
		}

		$role  = (string) ( $opts['role'] ?? 'parse' );
		$model = function_exists( 'zest_ai_model' ) ? zest_ai_model( $role ) : '';

		// Attach images (vision) to the last user message as content parts.
		if ( ! empty( $opts['images'] ) && is_array( $opts['images'] ) ) {
			$messages = self::attach_images( $messages, $opts['images'] );
		}

		$temperature = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.0;
		$extra       = (array) ( $opts['extra'] ?? array() );

		try {
			$text = $core->query( $messages, $temperature, $extra, $model );
		} catch ( \Throwable $e ) {
			$out['error'] = 'AI request failed: ' . $e->getMessage();
			return $out;
		}

		if ( is_string( $text ) && stripos( $text, 'Error' ) === 0 ) {
			$out['error'] = $text;
			return $out;
		}
		$out['ok']   = true;
		$out['text'] = (string) $text;
		return $out;
	}

	/** Parse a JSON object/array out of a model response (delegates to the core client). */
	public function parse_json( string $response ): ?array {
		$core = $this->core();
		if ( $core && method_exists( $core, 'parse_llm_json' ) ) {
			return $core->parse_llm_json( $response );
		}
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $response, $m ) ) {
			return json_decode( $m[1], true );
		}
		if ( preg_match( '/\{.*\}/s', $response, $m ) ) {
			return json_decode( $m[0], true );
		}
		return null;
	}

	/** Multimodal: turn the last user string into text + image content parts. */
	private static function attach_images( array $messages, array $images ): array {
		$parts = array();
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( ( $messages[ $i ]['role'] ?? '' ) === 'user' ) {
				$content = $messages[ $i ]['content'] ?? '';
				if ( is_string( $content ) ) {
					$parts[] = array( 'type' => 'text', 'text' => $content );
				}
				foreach ( $images as $url ) {
					$url = (string) $url;
					if ( '' !== $url ) {
						$parts[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) );
					}
				}
				$messages[ $i ]['content'] = $parts;
				break;
			}
		}
		return $messages;
	}
}
