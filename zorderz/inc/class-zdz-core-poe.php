<?php
/**
 * Shared Poe API Client
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

	private static array $FALLBACK_MODELS = [
		'Gemini-3.1-Pro'  => 'Claude-Opus-4.6',
		'Claude-Opus-4.6' => 'Gemini-3.1-Pro',
	];

	public function __construct( string $api_key = '', string $model = '' ) {
		$this->api_key       = $api_key ?: ( class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_poe_api_key() : '' );
		$this->default_model = $model ?: ( class_exists( 'ZDZ_Core_Settings' ) ? ZDZ_Core_Settings::get_ai_model() : 'Gemini-3.1-Pro' );
	}

	public function query( array $messages, float $temperature = 0.0, array $extra_params = [], string $model = '' ): string {
		$bot = $model ?: $this->default_model;
		$body = [
			'model'       => $bot,
			'messages'    => $messages,
			'temperature' => $temperature,
		];

		if ( stripos( $bot, 'Gemini' ) !== false ) {
			$body['thinking_budget'] = $extra_params['thinking_budget'] ?? 0;
			if ( ! empty( $extra_params['web_search'] ) ) {
				$body['web_search'] = true;
			}
		} elseif ( stripos( $bot, 'Claude' ) !== false ) {
			if ( isset( $extra_params['thinking_budget'] ) ) {
				$body['thinking_budget'] = $extra_params['thinking_budget'];
			}
			if ( isset( $extra_params['output_effort'] ) ) {
				$body['output_effort'] = $extra_params['output_effort'];
			}
		}

		$timeout = ( isset( $body['thinking_budget'] ) && $body['thinking_budget'] > 0 ) ? 180 : 90;

		$args = [
			'timeout' => $timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		];

		$response = wp_remote_post( $this->endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return 'Error: ' . $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( in_array( $code, [ 429, 502, 503, 504 ], true ) ) {
			sleep( 5 );
			$fallback = self::$FALLBACK_MODELS[ $bot ] ?? null;
			if ( $fallback ) {
				$body['model'] = $fallback;
				$args['body']  = wp_json_encode( $body );
				$response      = wp_remote_post( $this->endpoint, $args );
				if ( ! is_wp_error( $response ) ) {
					$code = wp_remote_retrieve_response_code( $response );
					$raw  = wp_remote_retrieve_body( $response );
				}
			}
		}

		$data    = json_decode( $raw, true );
		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( '' !== $content ) {
			return $content;
		}

		// No usable content: surface a diagnosable reason instead of a blank error,
		// so an unset/invalid key or an unavailable model is obvious to the operator.
		if ( '' === trim( $this->api_key ) ) {
			return 'Error: No Poe API key is set (Settings, Zorderz Core).';
		}
		$api_msg = '';
		if ( isset( $data['error'] ) ) {
			$api_msg = is_array( $data['error'] ) ? (string) ( $data['error']['message'] ?? '' ) : (string) $data['error'];
		}
		if ( 401 === $code || 403 === $code ) {
			return 'Error: Poe rejected the API key (HTTP ' . (int) $code . '). Check it under Settings, Zorderz Core.';
		}
		if ( $code >= 400 ) {
			return 'Error: Poe returned HTTP ' . (int) $code . '. ' . ( '' !== $api_msg ? $api_msg : 'Confirm the model "' . $bot . '" is available on your Poe plan.' );
		}
		if ( '' !== $api_msg ) {
			return 'Error: Poe: ' . $api_msg;
		}
		return 'Error: Empty response from model "' . $bot . '". Confirm the model name is available on your Poe plan.';
	}

	public function parse_llm_json( string $response ): ?array {
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $response, $m ) ) {
			return json_decode( $m[1], true );
		}
		if ( preg_match( '/\{.*\}/s', $response, $m ) ) {
			return json_decode( $m[0], true );
		}
		if ( preg_match( '/\[.*\]/s', $response, $m ) ) {
			return json_decode( $m[0], true );
		}
		return null;
	}
}