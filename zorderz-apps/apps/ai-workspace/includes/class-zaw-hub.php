<?php
/**
 * ZAW_Hub -- the relay to the assistant engine beside the Charts hub, through the Charts app's one channel.
 *
 * There is no second channel: every call goes through ZCH_Hub_Client::from_config()->call(), sealed with the Charts
 * envelope, to the hub the business connected under Charts. The access block names the caller `app:ai-workspace`,
 * which the hub answers with assistant_turn / assistant_status / assistant_cancel and health, and nothing of its store.
 * The hub's `user` is a fixed word: the hub never learns who asked.
 *
 * The other direction, the hub to this site (the turn's single pull, the site tools), opens and seals with the same
 * envelope and the same keys: envelope() below.
 *
 * A staging copy never calls the hub, three times over: this class refuses while the platform's staging guard is on;
 * the Charts keys are used only on a host the business named as live (ZCH_Config::live_host); and the guard itself
 * holds every outbound write on a copy.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Hub {

	/** The relay's one id, the same in the hub's access rules (hub/zorderz_hub/access.py ASSISTANT_CALLER). */
	const CALLER = 'app:ai-workspace';
	/** The hub's `user` for every call: a fixed word, never a person. */
	const USER = 'ai-workspace';
	const OPS  = array( 'health', 'assistant_turn', 'assistant_status', 'assistant_cancel' );

	/** A stand-in client for the suites (anything with call( $op, $body, $user, $today )). */
	public static $client = null;
	/** A stand-in for the staging guard's answer, for the suites (a callable returning bool). */
	public static $staging = null;

	public static function block() {
		return array(
			'caller' => self::CALLER, 'ops' => self::OPS, 'scope' => array(), 'money' => false, 'by_person' => false,
			'line_items' => 'none', 'kiosk' => false, 'accounts' => false, 'invoices' => 'none', 'me' => null, 'compare' => false,
		);
	}

	/** True on a staging or test copy of the site (the platform's staging guard). */
	public static function staging() {
		if ( is_callable( self::$staging ) ) {
			return (bool) call_user_func( self::$staging );
		}
		return class_exists( 'ZDZ_Staging_Guard' ) && method_exists( 'ZDZ_Staging_Guard', 'is_active' ) && ZDZ_Staging_Guard::is_active();
	}

	/** True only on the live site: never a staging copy, and only on a host the business named as live under Charts. */
	public static function live_site() {
		if ( self::staging() ) {
			return false;
		}
		return class_exists( 'ZCH_Config' ) && method_exists( 'ZCH_Config', 'live_host' ) && ZCH_Config::live_host();
	}

	/** -> the hub's reply {ok, op, data|error}; a missing client, link or live site is an error, never an empty success. */
	public static function call( $op, array $body = array() ) {
		if ( self::staging() ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'staging' ) );
		}
		$client = self::$client;
		if ( ! $client ) {
			$client = ( class_exists( 'ZCH_Hub_Client' ) && method_exists( 'ZCH_Hub_Client', 'from_config' ) ) ? ZCH_Hub_Client::from_config() : null;
		}
		if ( ! $client ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'not_configured' ) );
		}
		$body['access'] = self::block();
		$r = $client->call( $op, $body, self::USER, gmdate( 'Y-m-d' ) );
		if ( ! is_array( $r ) || ! array_key_exists( 'ok', $r ) ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'hub_unavailable' ) );
		}
		if ( ! empty( $r['ok'] ) && is_array( $r['data'] ?? null ) && isset( $r['data']['money'] ) && class_exists( 'ZAW_Money' ) ) {
			ZAW_Money::note( $r['data']['money'] );          // every status keeps the engine's month fresh here
		}
		return $r;
	}

	/** What the hub machine is called in messages (the Charts setting; "the hub computer" by default). */
	public static function label() {
		return ( class_exists( 'ZCH_Config' ) && method_exists( 'ZCH_Config', 'hub_label' ) ) ? ZCH_Config::hub_label() : __( 'the hub computer', 'zorderz' );
	}

	/** The error a person reads, plainly. */
	public static function say( array $r ) {
		$code = (string) ( $r['error']['code'] ?? 'hub_unavailable' );
		$hub  = self::label();
		$map  = array(
			/* translators: %s: what the business calls its hub machine */
			'hub_unavailable' => sprintf( __( '%s did not answer (it may be asleep or offline). Nothing was sent to the Ai.', 'zorderz' ), ucfirst( $hub ) ),
			'not_configured'  => __( 'No Charts hub is connected to this site (or this is not the live site), so the assistant cannot be reached.', 'zorderz' ),
			'staging'         => __( 'This is a staging copy of the site, so the assistant is never called from here.', 'zorderz' ),
			'bad_request'     => __( 'The hub does not know this request: its assistant needs updating.', 'zorderz' ),
			'forbidden'       => __( 'The hub refused this request: its access rules do not know the Ai Workspace yet.', 'zorderz' ),
			/* translators: %s: what the business calls its hub machine */
			'assistant_off'   => sprintf( __( 'The assistant is turned off on %s.', 'zorderz' ), $hub ),
			/* translators: %s: what the business calls its hub machine */
			'assistant_busy'  => sprintf( __( '%s already has several turns waiting; try again in a minute.', 'zorderz' ), ucfirst( $hub ) ),
			'turn_not_found'  => __( 'The hub no longer has that turn.', 'zorderz' ),
			'bad_fetch'       => __( 'The hub refused the turn\'s address: the site address in its configuration must be this site.', 'zorderz' ),
			'ticket_too_big'  => __( 'The hub refused the turn as too large.', 'zorderz' ),
		);
		/* translators: %s: a short error code */
		return $map[ $code ] ?? sprintf( __( 'The hub could not take that (%s).', 'zorderz' ), preg_replace( '/[^a-z0-9_]/', '', $code ) );
	}

	/** The envelope for the hub's own calls to this site (the turn's pull, site tools): the same keys as every Charts call. */
	public static function envelope() {
		if ( ! self::live_site() || ! class_exists( 'ZCH_Envelope' ) || ! defined( 'ZCH_WEB_PARTY' ) || ! defined( 'ZCH_HUB_PARTY' ) ) {
			return null;
		}
		$secret = method_exists( 'ZCH_Config', 'site_secret' ) ? (string) ZCH_Config::site_secret() : '';
		$public = method_exists( 'ZCH_Config', 'hub_public' ) ? (string) ZCH_Config::hub_public() : '';
		if ( '' === $secret || '' === $public ) {
			return null;
		}
		try {
			$peers = array( ZCH_HUB_PARTY => array_values( array_filter( array_map( 'trim', explode( ',', $public ) ) ) ) );
			return new ZCH_Envelope( ZCH_WEB_PARTY, $secret, $peers );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
