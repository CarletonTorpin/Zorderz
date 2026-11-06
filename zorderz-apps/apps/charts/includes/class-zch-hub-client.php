<?php
/**
 * The only code that talks to the Charts hub.
 *
 * Server-authoritative: the browser calls WordPress; this class seals the request with
 * ZCH_Envelope, posts it to the hub, opens the answer and checks that it answers this
 * request (its rid). The keys come from ZCH_Config and never leave PHP. Any failure is a
 * plain error array, and the caller shows "Charts hub unavailable". There is no plaintext
 * path.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Hub_Client {
	private $url;
	private $env;
	private $headers;
	public $last_ms = 0;

	public function __construct( $url, $site_secret, $hub_public_csv, array $headers = array(), $replay = null ) {
		$this->url     = $url;
		$this->headers = $headers;
		$peers         = array( ZCH_HUB_PARTY => array_values( array_filter( array_map( 'trim', explode( ',', (string) $hub_public_csv ) ) ) ) );
		$this->env     = new ZCH_Envelope( ZCH_WEB_PARTY, $site_secret, $peers, $replay );
	}

	/** True only on the live site (ZCH_Config::live_host). */
	public static function live_host() {
		return ZCH_Config::live_host();
	}

	/** Built from the configuration; null when Charts has no hub here (local mode) or this is not the live site. */
	public static function from_config() {
		if ( 'connected' !== ZCH_Config::state() ) {
			return null;
		}
		try {
			return new self( ZCH_Config::hub_url(), ZCH_Config::site_secret(), ZCH_Config::hub_public(), ZCH_Config::headers() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Call one hub op. Returns the hub's answer {ok, op, data|error}, or
	 * {ok:false, error:{code:'hub_unavailable'}}.
	 */
	public function call( $op, array $body, $user, $today ) {
		$body    = array_merge( $body, array( 'op' => $op, 'user' => $user, 'today' => $today ) );
		$started = microtime( true );
		$rid     = ZCH_Envelope::b64e( random_bytes( 12 ) );
		try {
			$sealed = $this->env->seal( ZCH_HUB_PARTY, 'request', $body, $rid );
		} catch ( \Throwable $e ) {
			return self::unavailable();
		}
		$raw           = $this->post( zch_json( $sealed ), 'ask' === $op ? 60 : 20 );
		$this->last_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( null === $raw ) {
			return self::unavailable();
		}
		$outer = json_decode( $raw, true );
		if ( ! is_array( $outer ) ) {
			return self::unavailable();
		}
		try {
			$inner = $this->env->open( $outer, ZCH_HUB_PARTY, 'response', $rid );
		} catch ( \Throwable $e ) {
			return self::unavailable();
		}
		$reply = $inner['body'] ?? null;
		if ( ! is_array( $reply ) || ! array_key_exists( 'ok', $reply ) ) {
			return self::unavailable();
		}
		return $reply;
	}

	/** The answer every caller gets when the hub cannot be reached, or there is none here. */
	public static function unavailable() {
		if ( 'connected' !== ZCH_Config::state() ) {
			return array(
				'ok'    => false,
				'error' => array(
					'code'    => 'hub_unavailable',
					'local'   => true,
					'message' => ZCH_Config::local_message(),
				),
			);
		}
		return array(
			'ok'    => false,
			'error' => array(
				'code'    => 'hub_unavailable',
				/* translators: %s: what the business calls the hub machine, e.g. "the hub computer" */
				'message' => sprintf( __( 'Charts hub unavailable: %s may be asleep or offline.', 'zorderz' ), ZCH_Config::hub_label() ),
			),
		);
	}

	private function post( $json, $timeout ) {
		$headers = array_merge( $this->headers, array( 'Content-Type' => 'application/json' ) );
		if ( class_exists( 'ZDZ_Outbound' ) || function_exists( 'wp_remote_post' ) ) {
			$args = array(
				'headers'     => $headers,
				'body'        => $json,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'blocking'    => true,
			);
			// The hub listens on loopback or a private overlay address by design, so a private host is called
			// directly; a public one (a tunnel's address) goes through the safe call. Never a redirect.
			$res = class_exists( 'ZDZ_Outbound' )
				? ZDZ_Outbound::send( 'POST', $this->url, $args, true, 0 )
				: wp_remote_post( $this->url, $args + array( 'reject_unsafe_urls' => false ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return null;
			}
			return wp_remote_retrieve_body( $res );
		}
		// Outside WordPress (the cross-language test): the same request with PHP streams.
		$h = '';
		foreach ( $headers as $k => $v ) {
			$h .= "$k: $v\r\n";
		}
		$ctx  = stream_context_create(
			array(
				'http' => array(
					'method'          => 'POST',
					'header'          => $h,
					'content'         => $json,
					'timeout'         => $timeout,
					'ignore_errors'   => true,
					'follow_location' => 0,
				),
			)
		);
		$raw  = @file_get_contents( $this->url, false, $ctx ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$code = 0;
		if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
			$code = (int) $m[1];
		}
		return ( false === $raw || 200 !== $code ) ? null : $raw;
	}
}

if ( ! function_exists( 'zch_json' ) ) {
	/** JSON for a sealed body: wp_json_encode inside WordPress, json_encode outside it. */
	function zch_json( $data ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
	}
}
