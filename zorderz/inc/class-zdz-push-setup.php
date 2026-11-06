<?php
/**
 * ZDZ_Push_Setup — what Settings -> Notifications needs to turn phone notifications on or off on
 * this device, from one switch.
 *
 * The theme owns no push system. Each app that sends push (the messaging app for notices and
 * messages, the chat for its answers, any other) owns its own key, worker, endpoints and table,
 * and describes itself on the capability registry with a read verb named "<app>.push_setup": the
 * owner answers, the asker never reads its tables. This class asks every such verb for the
 * signed-in person and hands the browser the list. The browser then registers each channel's
 * worker, subscribes with each channel's key and posts to each OWNER's own endpoint (the writes
 * never pass through the theme).
 *
 *   GET /zorderz/v1/push/setup  ->  { channels: [ { verb, channel, label, parts[], vapid_public_key, sw_url,
 *                                                   sw_scope, subscribe{action,nonce}, unsubscribe{…}, test{…},
 *                                                   subscribed_hashes[], current_hashes[], quiet_hours? } ] }
 *
 *   - Signed in only; never the shared device or Demo Mode: 403.
 *   - An owner that is not active, or refuses this person, is simply absent.
 *   - Every field is checked before it reaches the page: actions are plain names, nonces plain
 *     tokens, the worker is on this site, its scope a path, hashes 64 hex characters. Anything
 *     else drops the channel.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Push_Setup {

	const SUFFIX = '.push_setup';

	/** Channels in the order a person reads them: notices and messages, then chat answers, then anything new. */
	const ORDER = array( 'messages' => 0, 'chat' => 1 );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( ZDZ_REST_NS, '/push/setup', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_setup' ),
			'permission_callback' => function () { return is_user_logged_in(); },
		) );
	}

	/** May this person turn on push here? Never the shared device, never while Demo Mode runs. */
	public static function allowed( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'is_kiosk_user' ) && ZDZ_Alert_Router::is_kiosk_user( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		return true;
	}

	public static function rest_setup() {
		$uid = (int) get_current_user_id();
		if ( ! self::allowed( $uid ) ) {
			return new WP_Error( 'zdz_push_not_here', __( 'Notifications are off on the shared device.', 'zorderz' ), array( 'status' => 403 ) );
		}
		$res = rest_ensure_response( array( 'channels' => self::channels() ) );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/** Every "<app>.push_setup" verb, asked for the signed-in person through the registry; the ones that answered. */
	public static function channels(): array {
		if ( ! class_exists( 'ZDZ_Capabilities' ) ) {
			return array();
		}
		$out = array();
		foreach ( ZDZ_Capabilities::all() as $verb => $c ) {
			if ( substr( (string) $verb, -strlen( self::SUFFIX ) ) !== self::SUFFIX ) {
				continue;
			}
			$a = ZDZ_Capabilities::invoke( (string) $verb, array() );
			if ( empty( $a['success'] ) || ! empty( $a['denied'] ) ) {
				continue; // not for this person, or the owner could not answer: the channel is absent
			}
			$ch = self::clean( (string) $verb, $a );
			if ( $ch ) {
				$out[] = $ch;
			}
		}
		usort( $out, function ( $x, $y ) {
			$a = self::ORDER[ $x['channel'] ] ?? 5;
			$b = self::ORDER[ $y['channel'] ] ?? 5;
			return $a === $b ? strcmp( $x['channel'], $y['channel'] ) : $a - $b;
		} );
		return $out;
	}

	/** One owner's answer, checked field by field. Null when anything the browser needs is missing or not safe. */
	public static function clean( string $verb, array $a ): ?array {
		$name = function ( $v ) { return is_string( $v ) && preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $v ) ? $v : ''; };
		$tok  = function ( $v ) { return is_string( $v ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $v ) ? $v : ''; };
		$act  = function ( $v ) use ( $name, $tok ) {
			if ( ! is_array( $v ) ) {
				return null;
			}
			$x = $name( $v['action'] ?? '' );
			$n = $tok( $v['nonce'] ?? '' );
			return ( '' !== $x && '' !== $n ) ? array( 'action' => $x, 'nonce' => $n ) : null;
		};
		$channel = $name( $a['channel'] ?? '' );
		if ( '' === $channel ) {
			$channel = $name( substr( $verb, 0, (int) strpos( $verb, '.' ) ) );
		}
		$key = (string) ( $a['vapid_public_key'] ?? '' );
		if ( '' === $channel || ! preg_match( '/^[A-Za-z0-9_-]{80,100}$/', $key ) ) {
			return null; // a P-256 public key is 65 bytes: 87 characters of base64url
		}
		// The worker must be on this site; its scope a path on it.
		$sw    = (string) ( $a['sw_url'] ?? '' );
		$home  = wp_parse_url( home_url( '/' ) );
		$swp   = wp_parse_url( $sw );
		$scope = (string) ( $a['sw_scope'] ?? '' );
		if ( ! $swp || empty( $swp['host'] ) || empty( $home['host'] ) || strtolower( $swp['host'] ) !== strtolower( $home['host'] )
			|| ! in_array( strtolower( (string) ( $swp['scheme'] ?? '' ) ), array( 'https', 'http' ), true )
			|| ! preg_match( '#^/[A-Za-z0-9_\-./]*/?$#', $scope ) || false !== strpos( $scope, '..' ) ) {
			return null;
		}
		$sub   = $act( $a['subscribe'] ?? null );
		$unsub = $act( $a['unsubscribe'] ?? null );
		if ( ! $sub || ! $unsub ) {
			return null;
		}
		$hashes = array();
		foreach ( (array) ( $a['subscribed_hashes'] ?? array() ) as $h ) {
			if ( is_string( $h ) && preg_match( '/^[0-9a-f]{64}$/', $h ) ) {
				$hashes[] = $h;
			}
		}
		$current = array();
		foreach ( (array) ( $a['current_hashes'] ?? array() ) as $h ) {
			if ( is_string( $h ) && in_array( $h, $hashes, true ) ) {
				$current[] = $h; // only ones the list above holds
			}
		}
		$parts = array();
		foreach ( (array) ( $a['parts'] ?? array() ) as $p ) {
			$p = trim( wp_strip_all_tags( (string) $p ) );
			if ( '' !== $p && strlen( $p ) <= 40 ) {
				$parts[] = $p;
			}
		}
		$label = trim( wp_strip_all_tags( (string) ( $a['label'] ?? '' ) ) );
		$ch = array(
			'verb'              => $verb,
			'channel'           => $channel,
			'label'             => '' !== $label ? substr( $label, 0, 60 ) : ucfirst( $channel ),
			'parts'             => $parts ? array_slice( $parts, 0, 4 ) : array( '' !== $label ? $label : ucfirst( $channel ) ),
			'vapid_public_key'  => $key,
			'sw_url'            => esc_url_raw( $sw ),
			'sw_scope'          => $scope,
			'subscribe'         => $sub,
			'unsubscribe'       => $unsub,
			'test'              => $act( $a['test'] ?? null ),
			'subscribed_hashes' => array_slice( array_values( array_unique( $hashes ) ), 0, 50 ),
			'current_hashes'    => array_slice( array_values( array_unique( $current ) ), 0, 50 ),
		);
		if ( isset( $a['quiet_hours'] ) && is_array( $a['quiet_hours'] ) ) {
			$q  = $a['quiet_hours'];
			$hm = function ( $v ) { return is_string( $v ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : ''; };
			$qa = $act( $q );
			if ( '' !== $hm( $q['start'] ?? '' ) && '' !== $hm( $q['end'] ?? '' ) && $qa ) {
				$ch['quiet_hours'] = array( 'start' => $q['start'], 'end' => $q['end'] ) + $qa;
			}
		}
		return $ch;
	}
}
